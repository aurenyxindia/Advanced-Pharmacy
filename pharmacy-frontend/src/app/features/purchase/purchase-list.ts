import { Component, OnInit, computed, effect, inject, signal, untracked } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { PurchaseService } from '../../core/services/purchase.service';
import { UserRightsService } from '../../core/services/user-rights.service';

@Component({
  selector: 'app-purchase-list',
  imports: [CommonModule, FormsModule, RouterLink],
  templateUrl: './purchase-list.html',
  styleUrl: './purchase-list.scss',
})
export class PurchaseList implements OnInit {
  private readonly purchaseSvc = inject(PurchaseService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly rights = inject(UserRightsService);

  canViewPurchase = () => this.rights.canView('PURCHASE');
  canEditPurchase = () => this.rights.canEdit('PURCHASE');
  canDeletePurchase = () => this.rights.canDelete('PURCHASE');
  canImportBill = () => this.rights.canEdit('IMPORT_BILL');
  /** Opens Inward Stock (Paperless shared bills). */
  canInwardStock = () => this.rights.canEdit('PURCHASE');
  canDispense = () => this.rights.canView('DISPENSING');

  onInwardStock(): void {
    void this.router.navigateByUrl('/app/purchase/inward-stock');
  }

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  readonly rows = signal<Record<string, any>[]>([]);
  readonly loading = signal(true);
  readonly error = signal<string | null>(null);
  readonly search = signal('');
  /** YYYY-MM or '' for all months (default = current local month). */
  readonly selectedMonth = signal(this.localMonth());

  readonly viewOpen = signal(false);
  readonly viewLoading = signal(false);
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  readonly viewRow = signal<Record<string, any> | null>(null);
  readonly deletingId = signal<number | null>(null);

  /** Retail parity: nearest-rupee; sub-₹1 round-off residue displays as 0. */
  roundRupee(value: unknown): number {
    const n = Math.round((Number(value) || 0) * 100) / 100;
    if (n > 0 && n < 1) return 0;
    if (n < 0 && n > -1) return 0;
    return Math.round(n);
  }

  readonly kpis = computed(() => {
    const list = this.rows();
    let net = 0;
    let discount = 0;
    let tax = 0;
    let paid = 0;
    let balance = 0;
    for (const r of list) {
      net += this.roundRupee(r['net_amount']);
      discount += Number(r['discount_amount']) || 0;
      tax += Number(r['gst_amount']) || 0;
      paid += this.roundRupee(r['paid']);
      balance += this.roundRupee(r['balance']);
    }
    return {
      count: list.length,
      net: Math.round(net),
      discount: Math.round(discount * 100) / 100,
      tax: Math.round(tax * 100) / 100,
      paid: Math.round(paid),
      balance: Math.round(balance),
    };
  });

  constructor() {
    // Refresh list when a purchase is saved/updated elsewhere (entry / import).
    effect(() => {
      const v = this.purchaseSvc.logVersion();
      if (v === 0) return;
      untracked(() => this.reload());
    });
  }

  ngOnInit(): void {
    const qMonth = String(this.route.snapshot.queryParamMap.get('month') || '').trim();
    if (/^\d{4}-\d{2}$/.test(qMonth)) {
      this.selectedMonth.set(qMonth);
    } else if (qMonth === 'all') {
      this.selectedMonth.set('');
    }
    const qSearch = String(this.route.snapshot.queryParamMap.get('q') || '').trim();
    if (qSearch) this.search.set(qSearch);
    const qView = Number(this.route.snapshot.queryParamMap.get('view') || 0);
    this.reload();
    if (qView > 0) {
      this.openView(qView);
      void this.router.navigate([], {
        relativeTo: this.route,
        queryParams: { view: null },
        queryParamsHandling: 'merge',
        replaceUrl: true,
      });
    }
  }

  /** Local calendar month YYYY-MM (not UTC). */
  private localMonth(d = new Date()): string {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    return `${y}-${m}`;
  }

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  poNo(r: Record<string, any> | null): string {
    if (!r) return '';
    const stored = (r['po_no'] ?? '').toString().trim();
    if (stored) return stored;
    return String(r['id'] ?? '');
  }

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  itemsOf(p: Record<string, any>): Record<string, any>[] {
    const items = p['items'];
    return Array.isArray(items) ? items : [];
  }

  totalQty(it: Record<string, unknown>): number {
    return (Number(it['quantity']) || 0) + (Number(it['free_quantity']) || 0);
  }

  applySearch(): void {
    this.router.navigate([], {
      relativeTo: this.route,
      queryParams: { q: this.search().trim() || null },
      queryParamsHandling: 'merge',
      replaceUrl: true,
    });
    this.reload();
  }

  reload(): void {
    this.loading.set(true);
    this.error.set(null);
    this.purchaseSvc.list({
      search: this.search().trim() || undefined,
      month: this.selectedMonth() || undefined,
      per_page: 200,
    }).subscribe({
      next: (res) => {
        this.rows.set(res.data ?? []);
        this.loading.set(false);
      },
      error: () => {
        this.loading.set(false);
        this.error.set('Could not load purchases.');
      },
    });
  }

  onMonthChange(value: string): void {
    this.selectedMonth.set(value || '');
    this.router.navigate([], {
      relativeTo: this.route,
      queryParams: { month: value || 'all', q: this.search().trim() || null },
      queryParamsHandling: 'merge',
      replaceUrl: true,
    });
    this.reload();
  }

  clearMonth(): void {
    this.onMonthChange('');
  }

  thisMonth(): void {
    this.onMonthChange(this.localMonth());
  }

  openView(id: number): void {
    this.viewOpen.set(true);
    this.viewLoading.set(true);
    this.viewRow.set(null);
    this.purchaseSvc.get(id).subscribe({
      next: (row) => {
        this.viewRow.set(row);
        this.viewLoading.set(false);
      },
      error: (err: HttpErrorResponse) => {
        this.viewLoading.set(false);
        this.viewOpen.set(false);
        this.error.set(err.error?.message ?? 'Could not load purchase.');
      },
    });
  }

  closeView(): void {
    this.viewOpen.set(false);
    this.viewRow.set(null);
  }

  /** Leave this bill and open a blank purchase. View right is enough; Edit is only for saved bills. */
  newPurchase(): void {
    if (!this.canViewPurchase()) {
      this.error.set('You do not have permission to add a purchase.');
      return;
    }
    this.closeView();
    void this.router.navigate(['/app/purchase/new']);
  }

  edit(id: number): void {
    if (!this.canEditPurchase()) {
      this.error.set('You do not have permission to edit purchases.');
      return;
    }
    this.router.navigate(['/app/purchase', id, 'edit']);
  }

  barcodes(id: number): void {
    this.purchaseSvc.barcodeBlob(id).subscribe({
      next: (blob) => window.open(URL.createObjectURL(blob), '_blank'),
      error: () => this.error.set('Could not generate A4 barcodes.'),
    });
  }

  /** TSC TE 210 / TE 244 — 40×15 mm thermal roll labels. */
  labelBarcodes(id: number): void {
    this.purchaseSvc.labelBarcodeBlob(id).subscribe({
      next: (blob) => window.open(URL.createObjectURL(blob), '_blank'),
      error: () => this.error.set('Could not generate label barcodes for TSC TE 210.'),
    });
  }

  remove(id: number): void {
    if (!this.canDeletePurchase()) {
      this.error.set('You do not have permission to delete purchases.');
      return;
    }
    if (!confirm('Delete this purchase? Stock will be reversed if items were not sold.')) return;
    this.deletingId.set(id);
    this.purchaseSvc.remove(id).subscribe({
      next: () => {
        this.deletingId.set(null);
        if (this.viewRow()?.['id'] === id) this.closeView();
        this.reload();
      },
      error: (err: HttpErrorResponse) => {
        this.deletingId.set(null);
        this.error.set(err.error?.message ?? 'Could not delete purchase.');
      },
    });
  }

  downloadExcel(): void {
    const list = this.rows();
    const header = ['PO No', 'Invoice No', 'Invoice Date', 'Distributor', 'Cash/Credit', 'Net Total', 'Discount', 'Tax', 'Paid', 'Balance'];
    const lines = [header.join(',')];
    for (const r of list) {
      const date = r['invoice_date'] ? String(r['invoice_date']).slice(0, 10) : '';
      const cells = [
        this.poNo(r),
        r['invoice_no'] ?? '',
        date,
        r['supplier_name'] ?? '',
        r['cash_credit'] ?? '',
        String(this.roundRupee(r['net_amount'])),
        Number(r['discount_amount'] || 0).toFixed(2),
        Number(r['gst_amount'] || 0).toFixed(2),
        String(this.roundRupee(r['paid'])),
        String(this.roundRupee(r['balance'])),
      ].map((c) => `"${String(c).replace(/"/g, '""')}"`);
      lines.push(cells.join(','));
    }
    const blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `purchase-report-${this.selectedMonth() || 'all'}.csv`;
    a.click();
    URL.revokeObjectURL(url);
  }
}
