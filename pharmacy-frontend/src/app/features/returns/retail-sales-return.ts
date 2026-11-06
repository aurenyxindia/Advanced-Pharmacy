import { Component, HostListener, OnInit, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ReturnTabsComponent } from './return-tabs';
import { RetailSalesReturnApi } from '../../core/services/returns-hub.service';
import { BatchOption, SaleProduct, SalesService } from '../../core/services/sales.service';
import { UserRightsService } from '../../core/services/user-rights.service';

interface ManualLine {
  product_binding_id: number;
  batch_id: number;
  product_name: string;
  batch_no: string;
  pack_size: string;
  expiry_date: string | null;
  units_per_pack: number;
  stock_qty: number;
  mrp: number;
  unit_rate: number;
  return_qty: number;
  disc_per: number;
  discount_amt: number;
  gst_rate: number;
}

@Component({
  selector: 'app-retail-sales-return',
  imports: [CommonModule, FormsModule, ReturnTabsComponent],
  templateUrl: './retail-sales-return.html',
  styleUrl: './retail-sales-return.scss',
})
export class RetailSalesReturnPage implements OnInit {
  private readonly api = inject(RetailSalesReturnApi);
  private readonly salesSvc = inject(SalesService);
  private readonly rights = inject(UserRightsService);

  readonly canEdit = () => this.rights.canEdit('RETURN_NOTE');

  readonly mode = signal<'list' | 'create' | 'manual' | 'history' | 'history-view'>('list');
  readonly loading = signal(false);
  readonly saving = signal(false);
  readonly error = signal<string | null>(null);
  readonly toast = signal<string | null>(null);

  readonly sales = signal<any[]>([]);
  readonly history = signal<any[]>([]);
  search = '';
  historySearch = '';

  selectedBill: any = null;
  selectedHistory: any = null;

  // ---- External / manual return ----
  manualBillDate = this.isoToday();
  manualCustomer = '';
  manualContact = '';
  manualDoctor = '';
  readonly manualLines = signal<ManualLine[]>([]);
  readonly showStockDropdown = signal(false);
  readonly stockTerm = signal('');
  readonly stockHits = signal<SaleProduct[]>([]);
  readonly stockLoading = signal(false);
  readonly stockHighlight = signal(0);
  readonly stockBatches = signal<Record<number, BatchOption[]>>({});
  readonly batchesLoading = signal<Record<number, boolean>>({});
  private stockTimer?: ReturnType<typeof setTimeout>;
  private stockReq = 0;
  private batchGen = 0;

  readonly manualTotal = computed(() => {
    const sum = this.manualLines().reduce((s, l) => s + this.manualLineAmt(l), 0);
    return Math.round(sum * 100) / 100;
  });

  ngOnInit(): void {
    this.loadSales();
  }

  @HostListener('document:mousedown', ['$event'])
  onDocMouseDown(ev: MouseEvent): void {
    const t = ev.target as HTMLElement | null;
    if (!t?.closest('.rsr-stock-combo')) this.showStockDropdown.set(false);
  }

  loadSales(): void {
    this.loading.set(true);
    this.api.sales(this.search, 30).subscribe({
      next: (r) => {
        this.sales.set(r);
        this.loading.set(false);
      },
      error: (e) => {
        this.loading.set(false);
        this.error.set(e?.error?.message || 'Failed to load bills.');
      },
    });
  }

  onSearch(v: string): void {
    this.search = v;
    this.loadSales();
  }

  openReturn(row: any): void {
    this.loading.set(true);
    this.error.set(null);
    this.api.saleDetails(row.id).subscribe({
      next: (d) => {
        d.products = (d.products || []).map((p: any) => ({
          ...p,
          this_return_qty: 0,
          this_return_amt: 0,
        }));
        this.selectedBill = d;
        this.mode.set('create');
        this.loading.set(false);
      },
      error: (e) => {
        this.loading.set(false);
        this.error.set(e?.error?.message || 'Failed to load bill.');
      },
    });
  }

  openManual(): void {
    if (!this.canEdit()) return;
    this.error.set(null);
    this.toast.set(null);
    this.manualBillDate = this.isoToday();
    this.manualCustomer = '';
    this.manualContact = '';
    this.manualDoctor = '';
    this.manualLines.set([]);
    this.stockTerm.set('');
    this.stockHits.set([]);
    this.stockBatches.set({});
    this.showStockDropdown.set(false);
    this.mode.set('manual');
  }

  lineAmt(p: any): number {
    const qty = Number(p.this_return_qty) || 0;
    const unit = Number(p.unit_rate) || Number(p.rate) || 0;
    const disc = Number(p.disc_per || p.discount_pct) || 0;
    return Math.round(((qty * unit * (100 - disc)) / 100) * 100) / 100;
  }

  qtyUnit(p: any): string {
    return Number(p.units_per_pack) > 1 ? 'tabs' : 'units';
  }

  onQtyChange(p: any): void {
    const max = Number(p.max_return_qty) || 0;
    let q = Number(p.this_return_qty) || 0;
    if (q < 0) q = 0;
    if (q > max) {
      this.error.set(`Return qty cannot exceed ${max} ${this.qtyUnit(p)} for ${p.p_name}.`);
      q = 0;
    }
    p.this_return_qty = q;
    p.this_return_amt = this.lineAmt(p);
  }

  linesTotal(): number {
    if (!this.selectedBill?.products) return 0;
    const sum = this.selectedBill.products.reduce((s: number, p: any) => s + this.lineAmt(p), 0);
    return Math.round(sum * 100) / 100;
  }

  returnTotal(): number {
    if (!this.selectedBill) return 0;
    const remaining = Math.max(0, Number(this.selectedBill.net_amount || 0) - Number(this.selectedBill.return_total || 0));
    return Math.min(Math.round(this.linesTotal()), Math.round(remaining * 100) / 100);
  }

  historyRoundOff(): number {
    const h = this.selectedHistory;
    if (!h?.products?.length) return 0;
    const sum = h.products.reduce((s: number, p: any) => s + Number(p.return_amt || 0), 0);
    return Math.round((Number(h.return_total || 0) - sum) * 100) / 100;
  }

  roundOff(): number {
    return Math.round((this.returnTotal() - this.linesTotal()) * 100) / 100;
  }

  save(): void {
    this.error.set(null);
    const products = (this.selectedBill.products || [])
      .filter((p: any) => Number(p.this_return_qty) > 0)
      .map((p: any) => ({
        id: p.id,
        this_return_qty: Number(p.this_return_qty),
        this_return_amt: this.lineAmt(p),
        return_qty: Number(p.returned_qty || 0) + Number(p.this_return_qty),
        return_amt: Number(p.returned_amt || 0) + this.lineAmt(p),
      }));
    if (!products.length) {
      this.error.set('Enter return quantity for at least one product.');
      return;
    }
    this.saving.set(true);
    this.api
      .save({
        id: this.selectedBill.id,
        return_total: this.returnTotal(),
        balance: this.selectedBill.balance,
        products,
      })
      .subscribe({
        next: (r) => {
          this.saving.set(false);
          this.toast.set(`Return ${r.return_no} saved.`);
          if (r?.id) this.printHistory(r.id);
          this.mode.set('list');
          this.selectedBill = null;
          this.loadSales();
        },
        error: (e) => {
          this.saving.set(false);
          this.error.set(e?.error?.message || 'Save failed.');
        },
      });
  }

  // =================== External / manual return ===================

  openStockDropdown(): void {
    if (!this.canEdit()) return;
    this.showStockDropdown.set(true);
    this.runStockSearch();
  }

  toggleStockDropdown(ev?: Event): void {
    ev?.preventDefault();
    ev?.stopPropagation();
    if (this.showStockDropdown()) {
      this.showStockDropdown.set(false);
      return;
    }
    this.openStockDropdown();
    setTimeout(() => document.getElementById('rsr-stock-search')?.focus(), 0);
  }

  onStockSearch(v: string): void {
    this.stockTerm.set(v);
    this.showStockDropdown.set(true);
    clearTimeout(this.stockTimer);
    this.stockTimer = setTimeout(() => this.runStockSearch(), 200);
  }

  onStockKeydown(ev: KeyboardEvent): void {
    const list = this.stockHits();
    if (ev.key === 'Escape') {
      ev.preventDefault();
      this.showStockDropdown.set(false);
      return;
    }
    if (!list.length) return;
    if (ev.key === 'ArrowDown') {
      ev.preventDefault();
      this.stockHighlight.update((i) => Math.min(list.length - 1, i + 1));
    } else if (ev.key === 'ArrowUp') {
      ev.preventDefault();
      this.stockHighlight.update((i) => Math.max(0, i - 1));
    } else if (ev.key === 'Enter') {
      ev.preventDefault();
      const p = list[this.stockHighlight()];
      if (p) this.expandProductBatches(p);
    }
  }

  private runStockSearch(): void {
    const req = ++this.stockReq;
    this.stockLoading.set(true);
    this.salesSvc.searchProducts(this.stockTerm().trim()).subscribe({
      next: (list) => {
        if (req !== this.stockReq) return;
        const products = (list ?? []).filter((p) => (Number(p.available_qty) || 0) > 0 && !p.is_expired);
        this.stockHits.set(products.slice(0, this.stockTerm().trim() ? 80 : 40));
        this.stockHighlight.set(0);
        this.stockBatches.set({});
        this.batchesLoading.set({});
        this.stockLoading.set(false);
        this.prefetchBatches(this.stockHits());
      },
      error: () => {
        if (req !== this.stockReq) return;
        this.stockHits.set([]);
        this.stockLoading.set(false);
      },
    });
  }

  private prefetchBatches(products: SaleProduct[]): void {
    const gen = ++this.batchGen;
    for (const p of products) this.ensureBatches(p.binding_id, undefined, gen);
  }

  batchesFor(bindingId: number): BatchOption[] {
    return this.stockBatches()[bindingId] ?? [];
  }

  isBatchesLoading(bindingId: number): boolean {
    return !!this.batchesLoading()[bindingId];
  }

  batchesReady(bindingId: number): boolean {
    return Object.prototype.hasOwnProperty.call(this.stockBatches(), bindingId);
  }

  private ensureBatches(bindingId: number, onReady?: (b: BatchOption[]) => void, gen?: number): void {
    if (Object.prototype.hasOwnProperty.call(this.stockBatches(), bindingId)) {
      onReady?.(this.stockBatches()[bindingId] ?? []);
      return;
    }
    if (this.batchesLoading()[bindingId] && !onReady) return;
    this.batchesLoading.update((m) => ({ ...m, [bindingId]: true }));
    this.salesSvc.batches(bindingId).subscribe({
      next: (batches) => {
        if (gen != null && gen !== this.batchGen) return;
        const sellable = (batches ?? []).filter((b) => !b.is_expired && (Number(b.quantity) || 0) > 0);
        this.stockBatches.update((m) => ({ ...m, [bindingId]: sellable }));
        this.batchesLoading.update((m) => ({ ...m, [bindingId]: false }));
        onReady?.(sellable);
      },
      error: () => {
        if (gen != null && gen !== this.batchGen) return;
        this.stockBatches.update((m) => ({ ...m, [bindingId]: [] }));
        this.batchesLoading.update((m) => ({ ...m, [bindingId]: false }));
        onReady?.([]);
      },
    });
  }

  expandProductBatches(p: SaleProduct): void {
    this.ensureBatches(p.binding_id);
  }

  stockSecondary(p: SaleProduct): string {
    const bits = [p.pack_size || '', p.manufacturer_short || p.manufacturer || '', `Avl ${p.available_qty}`].filter(Boolean);
    return bits.length ? `(${bits.join(' · ')})` : '';
  }

  /** Strip / pack MRP for batch display (not loose unit MRP). */
  stripMrpOf(b: BatchOption): number {
    const upp = Math.max(1, Number(b.units_per_pack) || 1);
    const pack = Number(b.pack_mrp) || 0;
    if (pack > 0) return Math.round(pack * 100) / 100;
    const unit = Number(b.unit_mrp) || Number(b.mrp) || 0;
    return Math.round(unit * upp * 100) / 100;
  }

  addManualBatch(p: SaleProduct, b: BatchOption, ev?: Event): void {
    ev?.preventDefault();
    ev?.stopPropagation();
    this.error.set(null);
    const existing = this.manualLines().findIndex(
      (l) => l.product_binding_id === p.binding_id && l.batch_id === b.batch_id,
    );
    if (existing >= 0) {
      this.manualLines.update((ls) =>
        ls.map((l, i) => (i === existing ? { ...l, return_qty: (Number(l.return_qty) || 0) + 1 } : l)).map((l, i) =>
          i === existing ? this.syncDiscFromPct({ ...l }) : l,
        ),
      );
      this.showStockDropdown.set(false);
      this.stockTerm.set('');
      return;
    }
    const upp = Math.max(1, Number(b.units_per_pack ?? p.units_per_pack) || 1);
    const unitMrp = Number(b.unit_mrp) || 0;
    // Strip / pack MRP (purchase strip) — not per-tab unit MRP.
    const stripMrp =
      Number(b.pack_mrp) ||
      (unitMrp > 0 ? Math.round(unitMrp * upp * 100) / 100 : 0) ||
      Number(b.mrp) ||
      Number(p.mrp) ||
      0;
    const saleRate = unitMrp > 0 ? unitMrp : upp > 1 ? Math.round((stripMrp / upp) * 10000) / 10000 : stripMrp;
    const line: ManualLine = {
      product_binding_id: p.binding_id,
      batch_id: b.batch_id,
      product_name: p.product_name,
      batch_no: b.batch_no,
      pack_size: p.pack_size || '',
      expiry_date: b.expiry_date,
      units_per_pack: upp,
      stock_qty: Number(b.quantity) || 0,
      mrp: stripMrp,
      unit_rate: saleRate,
      return_qty: 1,
      disc_per: 0,
      discount_amt: 0,
      gst_rate: Number((p as any).gst_rate) || 0,
    };
    this.manualLines.update((ls) => [...ls, line]);
    this.showStockDropdown.set(false);
    this.stockTerm.set('');
  }

  manualLineAmt(l: ManualLine): number {
    const qty = Number(l.return_qty) || 0;
    const rate = Number(l.unit_rate) || 0;
    const discAmt = Number(l.discount_amt) || 0;
    return Math.round(Math.max(0, qty * rate - discAmt) * 100) / 100;
  }

  private syncDiscFromPct(l: ManualLine): ManualLine {
    const gross = Math.round((Number(l.return_qty) || 0) * (Number(l.unit_rate) || 0) * 100) / 100;
    const pct = Math.min(100, Math.max(0, Number(l.disc_per) || 0));
    const amt = Math.round(((gross * pct) / 100) * 100) / 100;
    return { ...l, disc_per: pct, discount_amt: amt };
  }

  private syncDiscFromAmt(l: ManualLine): ManualLine {
    const gross = Math.round((Number(l.return_qty) || 0) * (Number(l.unit_rate) || 0) * 100) / 100;
    let amt = Math.max(0, Number(l.discount_amt) || 0);
    if (amt > gross) amt = gross;
    const pct = gross > 0 ? Math.round((amt / gross) * 10000) / 100 : 0;
    return { ...l, discount_amt: amt, disc_per: Math.min(100, pct) };
  }

  patchManualLine(i: number, patch: Partial<ManualLine>, sync: 'pct' | 'amt' | 'qty' | 'rate' = 'qty'): void {
    this.manualLines.update((ls) =>
      ls.map((l, idx) => {
        if (idx !== i) return l;
        let next = { ...l, ...patch };
        if (sync === 'pct' || sync === 'qty' || sync === 'rate') next = this.syncDiscFromPct(next);
        else if (sync === 'amt') next = this.syncDiscFromAmt(next);
        return next;
      }),
    );
  }

  removeManualLine(i: number): void {
    this.manualLines.update((ls) => ls.filter((_, idx) => idx !== i));
  }

  saveManual(): void {
    this.error.set(null);
    if (!this.canEdit()) return;
    if (!this.manualCustomer.trim()) {
      this.error.set('Enter customer name.');
      return;
    }
    if (!this.manualBillDate) {
      this.error.set('Enter sale billing date.');
      return;
    }
    const lines = this.manualLines();
    if (!lines.length) {
      this.error.set('Add at least one product from shop stock.');
      return;
    }
    for (const l of lines) {
      if (!(Number(l.return_qty) > 0)) {
        this.error.set(`Enter return qty for ${l.product_name}.`);
        return;
      }
      if (!(Number(l.unit_rate) > 0)) {
        this.error.set(`Enter sale rate / MRP for ${l.product_name}.`);
        return;
      }
    }
    this.saving.set(true);
    this.api
      .saveManual({
        customer_name: this.manualCustomer.trim(),
        bill_date: this.manualBillDate,
        contact_no: this.manualContact.trim(),
        doctor_name: this.manualDoctor.trim(),
        products: lines.map((l) => ({
          product_binding_id: l.product_binding_id,
          batch_id: l.batch_id,
          batch_no: l.batch_no,
          product_name: l.product_name,
          pack_size: l.pack_size,
          expiry_date: l.expiry_date,
          units_per_pack: l.units_per_pack,
          mrp: l.mrp,
          unit_rate: l.unit_rate,
          this_return_qty: Number(l.return_qty),
          disc_per: Number(l.disc_per) || 0,
          discount_amt: Number(l.discount_amt) || 0,
          gst_rate: Number(l.gst_rate) || 0,
        })),
      })
      .subscribe({
        next: (r) => {
          this.saving.set(false);
          this.toast.set(`External return ${r.return_no} saved.`);
          if (r?.id) this.printHistory(r.id);
          this.mode.set('list');
          this.manualLines.set([]);
          this.loadSales();
        },
        error: (e) => {
          this.saving.set(false);
          const errors = e?.error?.errors as Record<string, string[]> | undefined;
          this.error.set((errors ? Object.values(errors).flat()[0] : null) || e?.error?.message || 'Save failed.');
        },
      });
  }

  openHistory(): void {
    this.mode.set('history');
    this.loadHistory();
  }

  loadHistory(): void {
    this.loading.set(true);
    this.api.history(this.historySearch).subscribe({
      next: (r) => {
        this.history.set(r);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  viewHistory(row: any): void {
    this.api.historyDetails(row.id).subscribe({
      next: (d) => {
        this.selectedHistory = d;
        this.mode.set('history-view');
      },
      error: (e) => this.error.set(e?.error?.message || 'Failed to load return.'),
    });
  }

  printHistory(id: number): void {
    this.api.historyPdfBlob(id).subscribe({
      next: (blob) => window.open(URL.createObjectURL(blob), '_blank'),
      error: () => this.error.set('Failed to open print PDF.'),
    });
  }

  isManualReturn(row: any): boolean {
    return String(row?.return_type || '').toLowerCase() === 'manual' || String(row?.sale_invoice_no || '') === 'EXTERNAL';
  }

  backToList(): void {
    this.mode.set('list');
    this.selectedBill = null;
    this.selectedHistory = null;
    this.showStockDropdown.set(false);
    this.loadSales();
  }

  private isoToday(): string {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  }
}
