import { Injectable, effect, inject, signal, untracked } from '@angular/core';
import { TokenService } from './token.service';

const STORAGE_PREFIX = 'pp.sales.draft.v1:';

interface DraftEnvelope {
  counters: string[];
  activeCounter: string;
  bills: Record<string, unknown>;
}

/**
 * Open sales bills survive leaving New Sales, a refresh, and closing the browser
 * (localStorage, keyed by client/branch/user). Cleared on logout or Discard.
 */
@Injectable({ providedIn: 'root' })
export class SalesDraftService {
  private readonly tokens = inject(TokenService);

  readonly counters = signal<string[]>([]);
  readonly activeCounter = signal('COUNTER 1');
  readonly bills = signal<Record<string, any>>({});

  constructor() {
    this.hydrate();
    effect(() => {
      const counters = this.counters();
      const activeCounter = this.activeCounter();
      const bills = this.bills();
      untracked(() => this.persist(counters, activeCounter, bills));
    });
  }

  clear(): void {
    this.removeStored();
    this.counters.set([]);
    this.activeCounter.set('COUNTER 1');
    this.bills.set({});
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
      const parsed = JSON.parse(raw) as DraftEnvelope;
      if (!parsed || !Array.isArray(parsed.counters) || parsed.counters.length === 0) return;
      if (!parsed.bills || typeof parsed.bills !== 'object') return;
      const active = parsed.counters.includes(parsed.activeCounter)
        ? parsed.activeCounter
        : parsed.counters[0];
      this.counters.set(parsed.counters);
      this.activeCounter.set(active);
      this.bills.set(parsed.bills);
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

  private persist(counters: string[], activeCounter: string, bills: Record<string, unknown>): void {
    const key = this.storageKey();
    if (!key || counters.length === 0) return;
    try {
      const payload: DraftEnvelope = { counters, activeCounter, bills };
      localStorage.setItem(key, JSON.stringify(payload));
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
}
