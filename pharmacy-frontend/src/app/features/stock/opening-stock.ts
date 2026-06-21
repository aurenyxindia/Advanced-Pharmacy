import { Component, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpErrorResponse } from '@angular/common/http';
import { OpeningEntry, StockService } from '../../core/services/stock.service';
import { ProductSearchResult, PurchaseService } from '../../core/services/purchase.service';

@Component({
  selector: 'app-opening-stock',
  imports: [CommonModule, FormsModule],
  templateUrl: './opening-stock.html',
  styleUrl: './opening-stock.scss',
})
export class OpeningStock {
  private readonly stockSvc = inject(StockService);
  private readonly purchaseSvc = inject(PurchaseService);

  readonly form = signal({
    master_product_id: 0, product_name: '', batch_no: '', expiry_date: '', shelf_location: '',
    quantity: 0, mrp: 0, purchase_rate: 0, opening_date: new Date().toISOString().slice(0, 10),
  });
  readonly searchResults = signal<ProductSearchResult[]>([]);
  readonly showResults = signal(false);
  private timer?: ReturnType<typeof setTimeout>;

  readonly rows = signal<OpeningEntry[]>([]);
  readonly saving = signal(false);
  readonly error = signal<string | null>(null);
  readonly toast = signal<string | null>(null);

  constructor() { this.load(); }

  load(): void { this.stockSvc.openingList().subscribe((d) => this.rows.set(d)); }

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  patch(field: string, value: any): void { this.form.update((f) => ({ ...f, [field]: value })); }

  onProductInput(value: string): void {
    this.patch('product_name', value);
    this.patch('master_product_id', 0);
    clearTimeout(this.timer);
    this.showResults.set(true);
    if (value.trim().length < 2) { this.searchResults.set([]); return; }
    this.timer = setTimeout(() => {
      this.purchaseSvc.searchProducts(value).subscribe((r) => this.searchResults.set(r));
    }, 220);
  }
  selectProduct(p: ProductSearchResult): void {
    this.form.update((f) => ({ ...f, master_product_id: p.id, product_name: p.name, shelf_location: p.shelf_location || '' }));
    this.searchResults.set([]);
    this.showResults.set(false);
  }

  save(): void {
    const f = this.form();
    if (!f.master_product_id) { this.error.set('Select a product from the list.'); return; }
    if (!f.batch_no.trim() || f.quantity <= 0) { this.error.set('Batch and quantity are required.'); return; }
    this.saving.set(true);
    this.error.set(null);
    this.stockSvc.openingStore(f).subscribe({
      next: () => {
        this.saving.set(false);
        this.showToast('Opening stock added.');
        this.form.set({ master_product_id: 0, product_name: '', batch_no: '', expiry_date: '', shelf_location: '',
          quantity: 0, mrp: 0, purchase_rate: 0, opening_date: f.opening_date });
        this.load();
      },
      error: (err: HttpErrorResponse) => { this.saving.set(false); this.error.set(err.error?.message ?? 'Could not save.'); },
    });
  }

  writeOff(): void {
    if (!confirm('Write off all expired stock? This removes expired batch quantities and records them in the stock ledger.')) return;
    this.stockSvc.writeOffExpired().subscribe((r) => {
      this.showToast(`Wrote off ${r.data.batches} batch(es), ${r.data.quantity} units.`);
    });
  }

  private showToast(m: string): void { this.toast.set(m); setTimeout(() => this.toast.set(null), 3000); }
}
