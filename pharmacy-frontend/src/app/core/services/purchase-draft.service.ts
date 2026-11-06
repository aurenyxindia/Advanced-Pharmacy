import { Injectable, inject } from '@angular/core';
import { TokenService } from './token.service';

const STORAGE_PREFIX = 'pp.purchase.draft.v1:';

/** Snapshot of the New Purchase screen so masters can round-trip safely. */
export interface PurchaseDraft {
  invoiceNo: string;
  invoiceDate: string;
  supplierId: number | null;
  supplierName: string;
  billDiscountPct?: number;
  cashCredit?: 'Cash' | 'UPI' | 'Cheque' | 'Credit';
  dueDate?: string;
  cdMode?: 'Percentage' | 'Amount';
  cdPct?: number;
  cdAmt?: number;
  netBillAmt?: number;
  transportCharge?: number;
  transportGstPct?: number;
  debitNoteNo?: string;
  debitDeductionAmt?: number;
  entry: Record<string, unknown>;
  lines: Record<string, unknown>[];
  productQuery: string;
  packSelect: string;
}

/** Created master ids handed back after Product / Supplier create. */
export interface PurchaseHandshake {
  createdProductId?: number;
  createdSupplierId?: number;
}

/** Import Bill / Upload Purchase — round-trip to Distributor Master. */
export type ImportResumeSource = 'scan' | 'upload' | 'hub';

export interface ImportResumeSnapshot {
  source: ImportResumeSource;
  returnUrl: string;
  payload: Record<string, unknown>;
}

/**
 * New Purchase survives leaving the page, a refresh, and closing the browser
 * (localStorage, keyed by client/branch/user). Cleared on Save, Discard, or logout.
 */
@Injectable({ providedIn: 'root' })
export class PurchaseDraftService {
  private readonly tokens = inject(TokenService);
  private draft: PurchaseDraft | null = null;
  private handshake: PurchaseHandshake | null = null;
  private importResume: ImportResumeSnapshot | null = null;
  /** Set when navigating to a master; consumed when purchase form restores. */
  private expectResume = false;

  constructor() {
    try {
      sessionStorage.removeItem('aps.purchase.draft.v1');
      sessionStorage.removeItem('aps.purchase.handshake.v1');
      localStorage.removeItem('aps.purchase.draft.v1');
      localStorage.removeItem('aps.purchase.handshake.v1');
    } catch {
      /* ignore */
    }
    this.hydrate();
  }

  saveDraft(draft: PurchaseDraft): void {
    this.draft = draft;
    this.persist();
  }

  loadDraft(): PurchaseDraft | null {
    return this.draft;
  }

  clearDraft(): void {
    this.draft = null;
    this.removeStored();
  }

  markExpectResume(): void {
    this.expectResume = true;
  }

  /** True only when returning from a master round-trip in this SPA session. */
  consumeExpectResume(): boolean {
    const v = this.expectResume;
    this.expectResume = false;
    return v;
  }

  setHandshake(payload: PurchaseHandshake): void {
    this.handshake = payload;
  }

  saveImportResume(snapshot: ImportResumeSnapshot): void {
    this.importResume = snapshot;
    this.markExpectResume();
  }

  /** Restore Import Bill screen after Distributor Master create (read-once). */
  consumeImportResume(source: ImportResumeSource): ImportResumeSnapshot | null {
    if (!this.expectResume) {
      return null;
    }
    this.expectResume = false;
    const snap = this.importResume;
    this.importResume = null;
    if (!snap || snap.source !== source) {
      return null;
    }
    return snap;
  }

  /** Read-once handshake so it is not re-applied on a later visit. */
  consumeHandshake(): PurchaseHandshake | null {
    const h = this.handshake;
    this.handshake = null;
    return h;
  }

  clearAll(): void {
    this.clearDraft();
    this.handshake = null;
    this.importResume = null;
    this.expectResume = false;
  }

  private storageKey(): string | null {
    const user = this.tokens.user;
    if (!user?.id) return null;
    return `${STORAGE_PREFIX}${user.client_id ?? 0}:${user.branch_id ?? 0}:${user.id}`;
  }

  private hydrate(): void {
    const key = this.storageKey();
    if (!key) return;
    try {
      const raw = this.readStored(key);
      if (!raw) return;
      const parsed = JSON.parse(raw) as PurchaseDraft;
      if (!parsed || typeof parsed !== 'object' || !Array.isArray(parsed.lines)) return;
      this.draft = parsed;
    } catch {
      /* ignore a damaged draft and start empty */
    }
  }

  /** Prefer localStorage; migrate leftover sessionStorage drafts once. */
  private readStored(key: string): string | null {
    try {
      const local = localStorage.getItem(key);
      if (local) return local;
      const session = sessionStorage.getItem(key);
      if (!session) return null;
      try {
        localStorage.setItem(key, session);
        sessionStorage.removeItem(key);
      } catch {
        /* keep reading from session if local is blocked */
      }
      return session;
    } catch {
      return null;
    }
  }

  private persist(): void {
    const key = this.storageKey();
    if (!key || !this.draft) return;
    try {
      localStorage.setItem(key, JSON.stringify(this.draft));
      try {
        sessionStorage.removeItem(key);
      } catch {
        /* ignore */
      }
    } catch {
      /* quota or private mode — in-memory draft still works for this visit */
    }
  }

  private removeStored(): void {
    this.removePrefixFrom(localStorage);
    this.removePrefixFrom(sessionStorage);
  }

  private removePrefixFrom(store: Storage): void {
    try {
      const keys: string[] = [];
      for (let i = 0; i < store.length; i++) {
        const k = store.key(i);
        if (k && k.startsWith(STORAGE_PREFIX)) keys.push(k);
      }
      for (const k of keys) store.removeItem(k);
    } catch {
      /* ignore */
    }
  }

  /** Only allow in-app return paths (no open redirects). */
  safeReturnUrl(url: string | null | undefined, fallback = '/app/purchase/new'): string {
    const u = (url ?? '').trim();
    if (u.startsWith('/app/') && !u.startsWith('//') && !u.includes('://')) {
      return u.split('#')[0];
    }
    return fallback;
  }
}
