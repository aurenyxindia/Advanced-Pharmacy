import { Component, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { BranchPerformance, HoService, PortfolioItem } from '../../core/services/ho.service';
import { MetaService } from '../../core/services/meta.service';
import { SalesService, SaleProduct } from '../../core/services/sales.service';

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
  private readonly salesSvc = inject(SalesService);

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
  readonly searchResults = signal<SaleProduct[]>([]);
  readonly searchTerm = signal('');
  private searchTimer?: ReturnType<typeof setTimeout>;
  readonly transferError = signal<string | null>(null);
  readonly transferToast = signal<string | null>(null);
  readonly saving = signal(false);

  constructor() {
    this.meta.loadBranches().subscribe();
    this.loadPerformance();
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

  // ---- transfer product search (searches source branch is approximate: uses billing search of current user branch) ----
  onSearch(value: string): void {
    this.searchTerm.set(value);
    clearTimeout(this.searchTimer);
    if (value.trim().length < 2) { this.searchResults.set([]); return; }
    this.searchTimer = setTimeout(() => {
      this.salesSvc.searchProducts(value).subscribe((r) => this.searchResults.set(r));
    }, 250);
  }
  addLine(p: SaleProduct): void {
    if (this.transferLines().some((l) => l.binding_id === p.binding_id)) return;
    this.transferLines.update((ls) => [...ls, {
      binding_id: p.binding_id, product_name: p.product_name, available_qty: p.available_qty, quantity: 1,
    }]);
    this.searchTerm.set('');
    this.searchResults.set([]);
  }
  updateQty(i: number, qty: number): void {
    this.transferLines.update((ls) => ls.map((l, idx) => idx === i ? { ...l, quantity: qty } : l));
  }
  removeLine(i: number): void {
    this.transferLines.update((ls) => ls.filter((_, idx) => idx !== i));
  }

  submitTransfer(): void {
    this.transferError.set(null);
    if (!this.fromBranch() || !this.toBranch()) { this.transferError.set('Select both branches.'); return; }
    if (this.fromBranch() === this.toBranch()) { this.transferError.set('Branches must differ.'); return; }
    if (this.transferLines().length === 0) { this.transferError.set('Add at least one product.'); return; }

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
