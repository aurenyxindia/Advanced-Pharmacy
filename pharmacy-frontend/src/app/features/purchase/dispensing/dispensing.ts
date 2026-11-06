import { Component, HostListener, OnInit, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpErrorResponse } from '@angular/common/http';
import { RouterLink } from '@angular/router';
import { BatchOption, SaleProduct, SalesService } from '../../../core/services/sales.service';
import {
  DispensingDetail,
  DispensingService,
  DispensingSummary,
  Hospital,
} from '../../../core/services/dispensing.service';
import { UserRightsService } from '../../../core/services/user-rights.service';

type Tab = 'new' | 'history';

interface DispLine {
  binding_id: number;
  product_name: string;
  mfg: string;
  pack: string;
  upp: number;
  batches: BatchOption[];
  batch_id: number;
  qty: number;
  disc: number;
}

@Component({
  selector: 'app-dispensing',
  imports: [CommonModule, FormsModule, RouterLink],
  templateUrl: './dispensing.html',
  styleUrl: './dispensing.scss',
})
export class Dispensing implements OnInit {
  private readonly svc = inject(DispensingService);
  private readonly salesSvc = inject(SalesService);
  private readonly rights = inject(UserRightsService);

  readonly canEdit = () => this.rights.canEdit('DISPENSING');
  readonly canViewReports = () => this.rights.canView('ALL_REPORTS');
  readonly departments = ['OT', 'ICU', 'Ward', 'Emergency', 'Casualty', 'Labour Room', 'OPD'];

  readonly tab = signal<Tab>('new');
  readonly toast = signal<string | null>(null);
  readonly now = signal(new Date());

  // ---- Hospitals ----
  readonly hospitals = signal<Hospital[]>([]);
  readonly hospitalsLoading = signal(true);
  readonly hospitalId = signal<number | null>(null);

  // ---- Header fields ----
  patientName = '';
  department = '';
  remarks = '';

  // ---- Stock search (distributor-style combo) ----
  readonly searchTerm = signal('');
  readonly stock = signal<SaleProduct[]>([]);
  readonly stockLoading = signal(false);
  readonly highlight = signal(0);
  readonly showStockDropdown = signal(false);
  private searchTimer?: ReturnType<typeof setTimeout>;
  private searchReq = 0;
  readonly addingId = signal<number | null>(null);
  /** Cached sellable batches by binding_id for the stock panel (always shown under each product). */
  readonly stockBatches = signal<Record<number, BatchOption[]>>({});
  readonly batchesLoading = signal<Record<number, boolean>>({});
  private batchPrefetchGen = 0;

  readonly stockComboMeta = computed(() => {
    const n = this.stock().length;
    const q = this.searchTerm().trim();
    if (this.stockLoading()) return 'Searching…';
    if (q) return `${n} product(s) · search results`;
    return `${n} product(s) · stock list`;
  });

  // ---- Lines ----
  readonly lines = signal<DispLine[]>([]);
  readonly saving = signal(false);
  readonly error = signal<string | null>(null);

  readonly totals = computed(() => {
    const ls = this.lines();
    return {
      items: ls.length,
      qty: ls.reduce((s, l) => s + (Number(l.qty) || 0), 0),
      amount: Math.round(ls.reduce((s, l) => s + this.lineAmount(l), 0) * 100) / 100,
    };
  });

  // ---- History ----
  readonly history = signal<DispensingSummary[]>([]);
  readonly historyLoading = signal(false);
  readonly historyError = signal<string | null>(null);
  histFrom = this.monthStart();
  histTo = this.isoToday();
  histHospital: number | null = null;
  histSearch = '';
  private histTimer?: ReturnType<typeof setTimeout>;
  readonly historyTotals = computed(() => {
    const rows = this.history();
    return {
      count: rows.length,
      qty: rows.reduce((s, r) => s + (Number(r.total_qty) || 0), 0),
      amount: Math.round(rows.reduce((s, r) => s + (Number(r.amount) || 0), 0) * 100) / 100,
    };
  });

  readonly pdfBusy = signal(false);

  readonly detailOpen = signal(false);
  readonly detailLoading = signal(false);
  readonly detail = signal<DispensingDetail | null>(null);

  ngOnInit(): void {
    this.loadHospitals();
  }

  setTab(t: Tab): void {
    this.tab.set(t);
    if (t === 'history') this.loadHistory();
  }

  // =================== Hospitals ===================

  loadHospitals(): void {
    this.hospitalsLoading.set(true);
    this.svc.hospitals().subscribe({
      next: (list) => {
        this.hospitals.set(list);
        this.hospitalsLoading.set(false);
        if (!this.hospitalId() || !list.some((h) => h.id === this.hospitalId())) {
          this.hospitalId.set(this.defaultHospitalId());
        }
      },
      error: () => {
        this.hospitals.set([]);
        this.hospitalsLoading.set(false);
      },
    });
  }

  private defaultHospitalId(): number | null {
    const list = this.hospitals();
    return (list.find((h) => Number(h.is_default) === 1) ?? (list.length === 1 ? list[0] : null))?.id ?? null;
  }

  isDefault(h: Hospital): boolean {
    return Number(h.is_default) === 1;
  }

  // =================== Stock search ===================

  @HostListener('document:mousedown', ['$event'])
  onDocumentMouseDown(ev: MouseEvent): void {
    const t = ev.target as HTMLElement | null;
    if (!t?.closest('.stock-combo')) this.showStockDropdown.set(false);
  }

  openStockDropdown(): void {
    if (!this.canEdit()) return;
    if (!this.showStockDropdown()) {
      this.showStockDropdown.set(true);
      this.runSearch();
    }
  }

  toggleStockDropdown(ev?: Event): void {
    if (!this.canEdit()) return;
    ev?.preventDefault();
    ev?.stopPropagation();
    if (this.showStockDropdown()) {
      this.showStockDropdown.set(false);
      return;
    }
    this.showStockDropdown.set(true);
    setTimeout(() => document.getElementById('ds-search')?.focus(), 0);
    this.runSearch();
  }

  onSearch(value: string): void {
    this.searchTerm.set(value);
    this.showStockDropdown.set(true);
    clearTimeout(this.searchTimer);
    this.searchTimer = setTimeout(() => this.runSearch(), 200);
  }

  pickStockProduct(p: SaleProduct, ev?: Event): void {
    ev?.preventDefault();
    ev?.stopPropagation();
    this.addProduct(p);
    this.showStockDropdown.set(false);
    this.searchTerm.set('');
  }

  pickStockBatch(p: SaleProduct, b: BatchOption, ev?: Event): void {
    ev?.preventDefault();
    ev?.stopPropagation();
    this.addBatch(p, b);
    this.showStockDropdown.set(false);
    this.searchTerm.set('');
  }

  stockSecondary(p: SaleProduct): string {
    const bits = [
      p.pack_size || '',
      p.manufacturer_short || p.manufacturer || '',
      p.available_qty != null ? `Avl ${p.available_qty}` : '',
    ].filter(Boolean);
    return bits.length ? `(${bits.join(' · ')})` : '';
  }

  private runSearch(): void {
    const req = ++this.searchReq;
    this.stockLoading.set(true);
    this.salesSvc.searchProducts(this.searchTerm().trim()).subscribe({
      next: (list) => {
        if (req !== this.searchReq) return;
        const term = this.searchTerm().trim();
        let products = (list ?? []).filter((p) => (Number(p.available_qty) || 0) > 0 && !p.is_expired);
        // Cap the default browse list so batch prefetch stays light; full matches when searching.
        if (!term && products.length > 60) {
          products = products.slice(0, 60);
        }
        this.stock.set(products);
        this.highlight.set(0);
        this.stockBatches.set({});
        this.batchesLoading.set({});
        this.stockLoading.set(false);
        // Always load & show batches for every product in the list / search results.
        this.prefetchBatchesForStock(products);
      },
      error: () => {
        if (req !== this.searchReq) return;
        this.stock.set([]);
        this.stockBatches.set({});
        this.batchesLoading.set({});
        this.stockLoading.set(false);
      },
    });
  }

  /** Load sellable batches for all visible shop-stock products (default list + search). */
  private prefetchBatchesForStock(products: SaleProduct[]): void {
    const gen = ++this.batchPrefetchGen;
    for (const p of products) {
      this.ensureStockBatches(p.binding_id, undefined, gen);
    }
  }

  onSearchKeydown(ev: KeyboardEvent): void {
    const list = this.stock();
    if (ev.key === 'Escape') {
      ev.preventDefault();
      this.showStockDropdown.set(false);
      return;
    }
    if (!list.length) return;
    if (ev.key === 'ArrowDown') {
      ev.preventDefault();
      this.showStockDropdown.set(true);
      this.highlight.update((i) => Math.min(list.length - 1, i + 1));
      this.scrollComboHighlight();
    } else if (ev.key === 'ArrowUp') {
      ev.preventDefault();
      this.highlight.update((i) => Math.max(0, i - 1));
      this.scrollComboHighlight();
    } else if (ev.key === 'Enter') {
      ev.preventDefault();
      const p = list[this.highlight()];
      if (p) this.pickStockProduct(p);
    }
  }

  private scrollComboHighlight(): void {
    setTimeout(() => document.querySelector('.stock-combo .combo-item.active-item')?.scrollIntoView({ block: 'nearest' }), 0);
  }

  isBatchesLoading(bindingId: number): boolean {
    return !!this.batchesLoading()[bindingId];
  }

  batchesFor(bindingId: number): BatchOption[] {
    return this.stockBatches()[bindingId] ?? [];
  }

  batchesReady(bindingId: number): boolean {
    return Object.prototype.hasOwnProperty.call(this.stockBatches(), bindingId);
  }

  private ensureStockBatches(
    bindingId: number,
    onReady?: (batches: BatchOption[]) => void,
    prefetchGen?: number,
  ): void {
    if (Object.prototype.hasOwnProperty.call(this.stockBatches(), bindingId)) {
      onReady?.(this.stockBatches()[bindingId] ?? []);
      return;
    }
    if (this.batchesLoading()[bindingId] && !onReady) return;
    this.batchesLoading.update((m) => ({ ...m, [bindingId]: true }));
    this.salesSvc.batches(bindingId).subscribe({
      next: (batches) => {
        if (prefetchGen != null && prefetchGen !== this.batchPrefetchGen) return;
        const sellable = this.sortBatchesFefo(
          (batches ?? []).filter((b) => !b.is_expired && (Number(b.quantity) || 0) > 0),
        );
        this.stockBatches.update((m) => ({ ...m, [bindingId]: sellable }));
        this.batchesLoading.update((m) => ({ ...m, [bindingId]: false }));
        onReady?.(sellable);
      },
      error: () => {
        if (prefetchGen != null && prefetchGen !== this.batchPrefetchGen) return;
        this.batchesLoading.update((m) => ({ ...m, [bindingId]: false }));
        this.stockBatches.update((m) => ({ ...m, [bindingId]: [] }));
        onReady?.([]);
      },
    });
  }

  /** FEFO — earliest expiry first (null last), then batch id. */
  private sortBatchesFefo(batches: BatchOption[]): BatchOption[] {
    return [...batches].sort((a, b) => {
      const ae = a.expiry_date ? Date.parse(a.expiry_date) : Number.POSITIVE_INFINITY;
      const be = b.expiry_date ? Date.parse(b.expiry_date) : Number.POSITIVE_INFINITY;
      if (ae !== be) return ae - be;
      return (a.batch_id || 0) - (b.batch_id || 0);
    });
  }

  lineTrack(l: DispLine, i: number): string {
    return `${l.binding_id}:${l.batch_id}:${i}`;
  }

  /** Highlight batches whose number matches the current search term. */
  batchMatchesSearch(b: BatchOption): boolean {
    const q = this.searchTerm().trim().toLowerCase();
    if (!q) return false;
    return (b.batch_no || '').toLowerCase().includes(q);
  }

  formatBatchLabel(b: BatchOption): string {
    const exp = b.expiry_date
      ? (() => {
          const d = new Date(b.expiry_date);
          if (Number.isNaN(d.getTime())) return '';
          return ` · ${String(d.getMonth() + 1).padStart(2, '0')}/${String(d.getFullYear()).slice(-2)}`;
        })()
      : '';
    return `${b.batch_no}${exp} · Avl ${b.quantity}`;
  }


  // =================== Lines ===================

  /** Add / bump the FEFO (earliest-expiry) batch line for a product. */
  addProduct(p: SaleProduct): void {
    if (!this.canEdit()) return;
    this.error.set(null);
    this.addingId.set(p.binding_id);
    this.ensureStockBatches(p.binding_id, (sellable) => {
      this.addingId.set(null);
      if (!sellable.length) {
        this.error.set(`No dispensable stock for ${p.product_name} in this branch (expired or zero).`);
        return;
      }
      this.pushOrBumpLine(p, sellable[0], sellable);
    });
  }

  /** Add a specific batch from the expanded shop-stock rows. */
  addBatch(p: SaleProduct, batch: BatchOption, ev?: Event): void {
    ev?.stopPropagation();
    if (!this.canEdit()) return;
    this.error.set(null);
    const sellable = this.batchesFor(p.binding_id);
    const all = sellable.length ? sellable : [batch];
    this.pushOrBumpLine(p, batch, all);
  }

  private pushOrBumpLine(p: SaleProduct, batch: BatchOption, sellable: BatchOption[]): void {
    const existing = this.lines().findIndex(
      (l) => l.binding_id === p.binding_id && l.batch_id === batch.batch_id,
    );
    if (existing >= 0) {
      const l = this.lines()[existing];
      this.setQty(existing, (Number(l.qty) || 0) + 1);
      return;
    }
    this.lines.update((ls) => [
      ...ls,
      {
        binding_id: p.binding_id,
        product_name: p.product_name,
        mfg: p.manufacturer_short || p.manufacturer || '',
        pack: p.pack_size || '',
        upp: Math.max(1, Number(batch.units_per_pack ?? p.units_per_pack) || 1),
        batches: sellable,
        batch_id: batch.batch_id,
        qty: 1,
        disc: 0,
      },
    ]);
  }

  batchOf(l: DispLine): BatchOption | undefined {
    return l.batches.find((b) => b.batch_id === l.batch_id);
  }

  available(l: DispLine): number {
    return Number(this.batchOf(l)?.quantity) || 0;
  }

  unitMrp(l: DispLine): number {
    return Number(this.batchOf(l)?.unit_mrp) || 0;
  }

  lineAmount(l: DispLine): number {
    const amt = this.unitMrp(l) * (Number(l.qty) || 0) * (1 - (Number(l.disc) || 0) / 100);
    return Math.round(amt * 100) / 100;
  }

  overStock(l: DispLine): boolean {
    return (Number(l.qty) || 0) > this.available(l);
  }

  setBatch(i: number, batchId: number | string): void {
    const id = Number(batchId);
    this.lines.update((ls) => {
      const cur = ls[i];
      if (!cur || cur.batch_id === id) return ls;
      const other = ls.findIndex((l, idx) => idx !== i && l.binding_id === cur.binding_id && l.batch_id === id);
      if (other >= 0) {
        // Merge into the existing line for that batch.
        return ls
          .map((l, idx) => (idx === other ? { ...l, qty: (Number(l.qty) || 0) + (Number(cur.qty) || 0) } : l))
          .filter((_, idx) => idx !== i);
      }
      return ls.map((l, idx) => (idx === i ? { ...l, batch_id: id } : l));
    });
  }

  setQty(i: number, value: number | string): void {
    const qty = Math.max(0, Math.trunc(Number(value) || 0));
    this.lines.update((ls) => ls.map((l, idx) => (idx === i ? { ...l, qty } : l)));
  }

  setDisc(i: number, value: number | string): void {
    const disc = Math.min(100, Math.max(0, Number(value) || 0));
    this.lines.update((ls) => ls.map((l, idx) => (idx === i ? { ...l, disc } : l)));
  }

  removeLine(i: number): void {
    this.lines.update((ls) => ls.filter((_, idx) => idx !== i));
  }

  clearForm(): void {
    this.lines.set([]);
    this.patientName = '';
    this.department = '';
    this.remarks = '';
    this.error.set(null);
    this.hospitalId.set(this.defaultHospitalId());
  }

  save(): void {
    if (!this.canEdit()) return;
    const hid = this.hospitalId();
    if (!hid) {
      this.error.set('Please select a hospital.');
      return;
    }
    const ls = this.lines();
    if (!ls.length) {
      this.error.set('Add at least one product from shop stock.');
      return;
    }
    const zero = ls.find((l) => !(Number(l.qty) > 0));
    if (zero) {
      this.error.set(`Enter qty for ${zero.product_name}.`);
      return;
    }
    const over = ls.find((l) => this.overStock(l));
    if (over) {
      this.error.set(`${over.product_name}: only ${this.available(over)} in stock for batch ${this.batchOf(over)?.batch_no ?? ''}.`);
      return;
    }
    this.saving.set(true);
    this.error.set(null);
    this.svc
      .create({
        hospital_id: hid,
        patient_name: this.patientName.trim(),
        department: this.department.trim(),
        remarks: this.remarks.trim(),
        lines: ls.map((l) => ({ batch_id: l.batch_id, qty: Number(l.qty) || 0, discount_pct: Number(l.disc) || 0 })),
      })
      .subscribe({
        next: (res) => {
          this.saving.set(false);
          const d = res.data;
          this.showToast(`Dispensing saved: ${d?.dispensing_no ?? ''} · ${d?.items ?? 0} item(s) · ₹${(d?.amount ?? 0).toFixed(2)}`);
          this.clearForm();
          this.now.set(new Date());
          this.stock.set([]);
          this.stockBatches.set({});
          this.batchesLoading.set({});
        },
        error: (err: HttpErrorResponse) => {
          this.saving.set(false);
          const errors = err.error?.errors as Record<string, string[]> | undefined;
          this.error.set((errors ? Object.values(errors).flat()[0] : null) || err.error?.message || 'Could not save dispensing.');
        },
      });
  }

  // =================== History ===================

  loadHistory(): void {
    this.historyLoading.set(true);
    this.historyError.set(null);
    this.svc
      .list({ from: this.histFrom, to: this.histTo, hospital_id: this.histHospital, search: this.histSearch.trim() })
      .subscribe({
        next: (rows) => {
          this.history.set(rows);
          this.historyLoading.set(false);
        },
        error: (err: HttpErrorResponse) => {
          this.historyError.set(err.error?.message ?? 'Could not load dispensing history.');
          this.historyLoading.set(false);
        },
      });
  }

  onHistorySearch(): void {
    clearTimeout(this.histTimer);
    this.histTimer = setTimeout(() => this.loadHistory(), 300);
  }

  /** Opens the Hospital Dispensing Report PDF (current History filters) in a new tab. */
  openReportPdf(): void {
    if (this.pdfBusy()) return;
    // Open the tab synchronously so the popup blocker allows it, then load the PDF into it.
    const win = window.open('', '_blank');
    if (win) {
      win.document.title = 'Hospital Dispensing Report';
      win.document.body.innerHTML =
        '<p style="font-family:Segoe UI,Arial,sans-serif;color:#475569;padding:24px">Preparing Hospital Dispensing Report…</p>';
    }
    this.pdfBusy.set(true);
    this.svc
      .reportPdf({ from: this.histFrom, to: this.histTo, hospital_id: this.histHospital, search: this.histSearch.trim() })
      .subscribe({
        next: (blob) => {
          this.pdfBusy.set(false);
          const url = URL.createObjectURL(new Blob([blob], { type: 'application/pdf' }));
          if (win && !win.closed) {
            win.location.href = url;
          } else {
            window.open(url, '_blank');
          }
          setTimeout(() => URL.revokeObjectURL(url), 60_000);
        },
        error: () => {
          this.pdfBusy.set(false);
          win?.close();
          const msg = 'Could not open the Hospital Dispensing Report.';
          if (this.tab() === 'history') this.historyError.set(msg);
          else this.error.set(msg);
        },
      });
  }

  openDetail(r: DispensingSummary): void {
    this.detailOpen.set(true);
    this.detailLoading.set(true);
    this.detail.set(null);
    this.svc.get(r.dispensing_no).subscribe({
      next: (d) => {
        this.detail.set(d);
        this.detailLoading.set(false);
      },
      error: (err: HttpErrorResponse) => {
        this.historyError.set(err.error?.message ?? 'Could not load dispensing.');
        this.detailLoading.set(false);
        this.detailOpen.set(false);
      },
    });
  }

  closeDetail(): void {
    this.detailOpen.set(false);
    this.detail.set(null);
  }

  private showToast(msg: string): void {
    this.toast.set(msg);
    setTimeout(() => this.toast.set(null), 5000);
  }

  private isoToday(): string {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  }

  private monthStart(): string {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`;
  }
}
