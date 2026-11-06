import { Component, computed, HostListener, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { BranchPerformance, BranchStockItem, HoService, PortfolioItem } from '../../core/services/ho.service';
import { MetaService } from '../../core/services/meta.service';

interface TransferLine {
  binding_id: number;
  product_name: string;
  available_qty: number;
  quantity: number;
}

@Component({
  selector: 'app-ho-center',
  imports: [CommonModule, FormsModule, RouterLink],
  templateUrl: './ho-center.html',
  styleUrl: './ho-center.scss',
})
export class HoCenter {
  private readonly ho = inject(HoService);
  private readonly meta = inject(MetaService);

  readonly tab = signal<'performance' | 'portfolio' | 'transfer'>('performance');

  readonly performance = signal<BranchPerformance[]>([]);
  readonly totals = signal<Record<string, number>>({});
  readonly portfolio = signal<PortfolioItem[]>([]);
  readonly branches = this.meta.branches;
  readonly transfers = signal<Record<string, unknown>[]>([]);
  readonly loading = signal(false);

  // transfer form
  readonly fromBranch = signal<number | null>(null);
  readonly toBranch = signal<number | null>(null);
  readonly transferLines = signal<TransferLine[]>([]);
  readonly fromStock = signal<BranchStockItem[]>([]);
  readonly stockLoading = signal(false);
  readonly productQuery = signal('');
  readonly showProductDropdown = signal(false);
  readonly transferError = signal<string | null>(null);
  readonly transferToast = signal<string | null>(null);
  readonly saving = signal(false);

  readonly logOpen = signal(false);
  readonly logLoading = signal(false);
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  readonly logRow = signal<Record<string, any> | null>(null);

  /** Coerce unknown table cell values for template bindings. */
  asNum(v: unknown): number {
    return Number(v) || 0;
  }

  readonly filteredFromStock = computed(() => {
    const q = this.productQuery().trim().toLowerCase();
    const all = this.fromStock();
    if (!q) return all;
    return all.filter((p) =>
      p.product_name.toLowerCase().includes(q) ||
      (p.manufacturer_short ?? '').toLowerCase().includes(q) ||
      (p.pack_size ?? '').toLowerCase().includes(q),
    );
  });

  constructor() {
    this.meta.loadBranches().subscribe();
    this.loadPerformance();
  }

  @HostListener('document:mousedown', ['$event'])
  onDocumentMouseDown(ev: MouseEvent): void {
    const t = ev.target as HTMLElement | null;
    if (!t?.closest('.product-combo')) this.showProductDropdown.set(false);
  }

  switchTab(t: 'performance' | 'portfolio' | 'transfer'): void {
    this.tab.set(t);
    if (t === 'performance') this.loadPerformance();
    if (t === 'portfolio') this.loadPortfolio();
    if (t === 'transfer') this.loadTransfers();
  }

  loadPerformance(): void {
    this.loading.set(true);
    this.ho.branchPerformance().subscribe({
      next: (d) => { this.performance.set(d.branches); this.totals.set(d.totals); this.loading.set(false); },
      error: () => this.loading.set(false),
    });
  }
  loadPortfolio(): void {
    this.loading.set(true);
    this.ho.portfolio().subscribe({
      next: (d) => { this.portfolio.set(d); this.loading.set(false); },
      error: () => this.loading.set(false),
    });
  }
  loadTransfers(): void {
    this.ho.transfers().subscribe((d) => this.transfers.set(d));
  }

  onFromBranchChange(id: number | null): void {
    this.fromBranch.set(id);
    this.transferLines.set([]);
    this.productQuery.set('');
    this.showProductDropdown.set(false);
    this.loadFromStock();
  }

  private loadFromStock(): void {
    const branchId = this.fromBranch();
    if (!branchId) {
      this.fromStock.set([]);
      return;
    }
    this.stockLoading.set(true);
    this.ho.branchStock(branchId).subscribe({
      next: (rows) => {
        this.fromStock.set(rows);
        this.stockLoading.set(false);
      },
      error: () => {
        this.fromStock.set([]);
        this.stockLoading.set(false);
      },
    });
  }

  openProductDropdown(ev?: Event): void {
    ev?.preventDefault();
    ev?.stopPropagation();
    if (!this.fromBranch()) {
      this.transferError.set('Select the From Branch first.');
      return;
    }
    this.transferError.set(null);
    this.showProductDropdown.update((o) => !o);
    if (!this.fromStock().length && !this.stockLoading()) this.loadFromStock();
  }

  onProductQuery(q: string): void {
    this.productQuery.set(q);
    this.showProductDropdown.set(true);
  }

  addLine(p: BranchStockItem): void {
    if (this.transferLines().some((l) => l.binding_id === p.binding_id)) {
      this.showProductDropdown.set(false);
      this.productQuery.set('');
      return;
    }
    this.transferLines.update((ls) => [...ls, {
      binding_id: p.binding_id,
      product_name: p.product_name,
      available_qty: Number(p.available_qty) || 0,
      quantity: 1,
    }]);
    this.productQuery.set('');
    this.showProductDropdown.set(false);
  }

  updateQty(i: number, qty: number): void {
    this.transferLines.update((ls) => ls.map((l, idx) => {
      if (idx !== i) return l;
      const capped = Math.min(Math.max(0, qty), l.available_qty);
      return { ...l, quantity: capped };
    }));
  }

  removeLine(i: number): void {
    this.transferLines.update((ls) => ls.filter((_, idx) => idx !== i));
  }

  branchLabel(id: unknown): string {
    const n = Number(id);
    const b = this.branches().find((x) => x.id === n);
    return b ? `${b.code} - ${b.name}` : String(id ?? '-');
  }

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  logItems(row: Record<string, any> | null): Record<string, any>[] {
    const items = row?.['items'];
    return Array.isArray(items) ? items : [];
  }

  openTransferLog(id: number): void {
    this.logOpen.set(true);
    this.logLoading.set(true);
    this.logRow.set(null);
    this.ho.transferLog(id).subscribe({
      next: (row) => {
        this.logRow.set(row);
        this.logLoading.set(false);
      },
      error: (err: HttpErrorResponse) => {
        this.logLoading.set(false);
        this.logOpen.set(false);
        this.transferError.set(err.error?.message ?? 'Could not load transfer log.');
      },
    });
  }

  closeTransferLog(): void {
    this.logOpen.set(false);
    this.logRow.set(null);
  }

  submitTransfer(): void {
    this.transferError.set(null);
    if (!this.fromBranch() || !this.toBranch()) { this.transferError.set('Select both branches.'); return; }
    if (this.fromBranch() === this.toBranch()) { this.transferError.set('Branches must differ.'); return; }
    if (this.transferLines().length === 0) { this.transferError.set('Add at least one product.'); return; }
    for (const l of this.transferLines()) {
      if (l.quantity <= 0) { this.transferError.set(`Enter a valid qty for ${l.product_name}.`); return; }
      if (l.quantity > l.available_qty) {
        this.transferError.set(`${l.product_name}: qty exceeds available stock (${l.available_qty}).`);
        return;
      }
    }

    this.saving.set(true);
    this.ho.transfer({
      from_branch_id: this.fromBranch(),
      to_branch_id: this.toBranch(),
      items: this.transferLines().map((l) => ({ product_binding_id: l.binding_id, quantity: l.quantity })),
    }).subscribe({
      next: () => {
        this.saving.set(false);
        this.transferLines.set([]);
        this.transferToast.set('Stock transferred successfully.');
        setTimeout(() => this.transferToast.set(null), 3000);
        this.loadTransfers();
        this.loadFromStock();
      },
      error: (err: HttpErrorResponse) => {
        this.saving.set(false);
        const errors = err.error?.errors;
        const first = errors ? (Object.values(errors)[0] as string[])?.[0] : null;
        this.transferError.set(first ?? err.error?.message ?? 'Transfer failed.');
      },
    });
  }
}
