import { Injectable } from '@angular/core';
import { OpeningLine } from '../../features/stock/opening-stock-excel.util';

/** Snapshot of Opening Stock entry so Product Master can round-trip safely. */
export interface OpeningStockDraft {
  draft: Record<string, unknown>;
  lines: OpeningLine[];
  /** When adding from an unmatched Excel line. */
  addProductForLineKey: string | null;
  activeTab: 'opening' | 'adjustment' | 'mitrigger';
}

@Injectable({ providedIn: 'root' })
export class OpeningStockDraftService {
  private draft: OpeningStockDraft | null = null;
  private expectResume = false;

  saveDraft(payload: OpeningStockDraft): void {
    this.draft = payload;
  }

  loadDraft(): OpeningStockDraft | null {
    return this.draft;
  }

  clearDraft(): void {
    this.draft = null;
  }

  markExpectResume(): void {
    this.expectResume = true;
  }

  consumeExpectResume(): boolean {
    const v = this.expectResume;
    this.expectResume = false;
    return v;
  }

  clearAll(): void {
    this.draft = null;
    this.expectResume = false;
  }
}
