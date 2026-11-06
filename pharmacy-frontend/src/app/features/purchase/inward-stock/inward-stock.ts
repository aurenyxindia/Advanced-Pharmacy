import { Component, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import {
  InwardStockDetail,
  InwardStockRow,
  InwardStockService,
} from '../../../core/services/inward-stock.service';

@Component({
  selector: 'app-inward-stock',
  imports: [CommonModule, FormsModule, RouterLink],
  templateUrl: './inward-stock.html',
  styleUrl: './inward-stock.scss',
})
export class InwardStock implements OnInit {
  private readonly svc = inject(InwardStockService);

  readonly statusFilter = signal<'pending' | 'approved' | 'rejected' | 'all'>('pending');
  readonly rows = signal<InwardStockRow[]>([]);
  readonly loading = signal(true);
  readonly error = signal<string | null>(null);
  readonly toast = signal<string | null>(null);
  readonly actingId = signal<number | null>(null);

  readonly detailOpen = signal(false);
  readonly detailLoading = signal(false);
  readonly detail = signal<InwardStockDetail | null>(null);

  ngOnInit(): void {
    this.reload();
  }

  reload(): void {
    this.loading.set(true);
    this.error.set(null);
    this.svc.list(this.statusFilter()).subscribe({
      next: (rows) => {
        this.rows.set(rows);
        this.loading.set(false);
      },
      error: (err) => {
        this.error.set(err?.error?.message || 'Could not load inward stock.');
        this.loading.set(false);
      },
    });
  }

  setFilter(status: 'pending' | 'approved' | 'rejected' | 'all'): void {
    this.statusFilter.set(status);
    this.reload();
  }

  openDetail(row: InwardStockRow): void {
    this.detailOpen.set(true);
    this.detailLoading.set(true);
    this.detail.set(null);
    this.svc.show(row.id).subscribe({
      next: (d) => {
        this.detail.set(d);
        this.detailLoading.set(false);
      },
      error: (err) => {
        this.error.set(err?.error?.message || 'Could not load bill details.');
        this.detailLoading.set(false);
        this.detailOpen.set(false);
      },
    });
  }

  closeDetail(): void {
    this.detailOpen.set(false);
    this.detail.set(null);
  }

  paidOf(r: InwardStockRow): number {
    if (r.paid != null) return Number(r.paid) || 0;
    const credit = String(r.cash_credit || '').toLowerCase().includes('credit');
    return credit ? 0 : Number(r.net_amt) || 0;
  }

  balanceOf(r: InwardStockRow): number {
    if (r.balance != null) return Number(r.balance) || 0;
    const credit = String(r.cash_credit || '').toLowerCase().includes('credit');
    return credit ? Number(r.net_amt) || 0 : 0;
  }

  approve(row: InwardStockRow, ev?: Event): void {
    ev?.stopPropagation();
    if (
      !confirm(
        `Approve ${row.source_order_no}?\n\nCreates a Purchase Report entry:\nPO No = ${row.source_order_no}\nDistributor = ${row.source_medical_name || '—'}\nStock will be added (inward).`
      )
    ) {
      return;
    }
    this.actingId.set(row.id);
    this.error.set(null);
    this.svc.approve(row.id).subscribe({
      next: (res) => {
        this.actingId.set(null);
        const warnings = (res['warnings'] as string[] | undefined) ?? [];
        const msg = String(res['message'] || 'Approved.');
        this.toast.set(warnings.length ? `${msg} (${warnings.length} warning(s))` : msg);
        setTimeout(() => this.toast.set(null), 4000);
        if (this.detail()?.id === row.id) this.closeDetail();
        this.reload();
      },
      error: (err) => {
        this.actingId.set(null);
        this.error.set(err?.error?.message || 'Approve failed.');
      },
    });
  }

  reject(row: InwardStockRow, ev?: Event): void {
    ev?.stopPropagation();
    if (!confirm(`Reject shared bill ${row.source_order_no}? Stock will not change.`)) {
      return;
    }
    this.actingId.set(row.id);
    this.error.set(null);
    this.svc.reject(row.id).subscribe({
      next: (res) => {
        this.actingId.set(null);
        this.toast.set(String(res['message'] || 'Rejected.'));
        setTimeout(() => this.toast.set(null), 3000);
        if (this.detail()?.id === row.id) this.closeDetail();
        this.reload();
      },
      error: (err) => {
        this.actingId.set(null);
        this.error.set(err?.error?.message || 'Reject failed.');
      },
    });
  }

  statusBadge(status: string): string {
    switch (status) {
      case 'pending':
        return 'badge-pending';
      case 'approved':
        return 'badge-approved';
      case 'rejected':
        return 'badge-rejected';
      default:
        return 'badge-muted';
    }
  }

  itemName(item: Record<string, unknown>): string {
    return String(item['product_name'] ?? item['p_name'] ?? '');
  }

  itemMfr(item: Record<string, unknown>): string {
    return String(item['manufacturer'] ?? item['m_name'] ?? '');
  }

  itemHsn(item: Record<string, unknown>): string {
    return String(item['hsn_code'] ?? item['hsn'] ?? '');
  }

  itemBatch(item: Record<string, unknown>): string {
    return String(item['batch_no'] ?? '');
  }

  itemExpiry(item: Record<string, unknown>): string {
    const raw = String(item['expiry_raw'] ?? item['exp_date'] ?? item['expiry_date'] ?? '').trim();
    if (!raw) return '—';
    // Paperless often sends YYYY-MM
    if (/^\d{4}-\d{2}/.test(raw)) {
      const [y, m] = raw.split('-');
      return `${m}/${y.slice(-2)}`;
    }
    return raw;
  }

  itemPack(item: Record<string, unknown>): string {
    return String(item['pack_size'] ?? item['pack'] ?? '');
  }

  itemQty(item: Record<string, unknown>): number {
    const sQty = Number(item['quantity'] ?? item['SQty'] ?? item['sqty'] ?? 0);
    if (sQty > 0) return sQty;
    return Number(item['qty'] ?? 0);
  }

  itemFree(item: Record<string, unknown>): number {
    return Number(
      item['free_quantity'] ?? item['freeQty'] ?? item['free_qty'] ?? item['FQty'] ?? item['free'] ?? 0
    );
  }

  itemTotalQty(item: Record<string, unknown>): number {
    const t = Number(item['total_qty'] ?? 0);
    if (t > 0) return t;
    return this.itemQty(item) + this.itemFree(item);
  }

  itemRate(item: Record<string, unknown>): number {
    const r = Number(item['purchase_rate'] ?? item['sale_rate'] ?? item['rate'] ?? 0);
    if (r > 0) return r;
    const qty = this.itemQty(item);
    const taxable = Number(item['taxable'] ?? 0);
    return qty > 0 ? taxable / qty : 0;
  }

  itemPtr(item: Record<string, unknown>): number {
    const p = Number(item['ptr'] ?? 0);
    return p > 0 ? p : this.itemRate(item);
  }

  itemDiscPct(item: Record<string, unknown>): number {
    return Number(item['discount_pct'] ?? item['disc_per'] ?? item['disc'] ?? 0);
  }

  itemGst(item: Record<string, unknown>): number {
    return Number(item['gst_rate'] ?? item['gst'] ?? 0);
  }

  itemMrp(item: Record<string, unknown>): number {
    return Number(item['mrp'] ?? 0);
  }

  itemTaxable(item: Record<string, unknown>): number {
    return Number(item['taxable'] ?? 0);
  }

  itemTax(item: Record<string, unknown>): number {
    const t = Number(item['tax'] ?? 0);
    if (t > 0) return t;
    const taxable = this.itemTaxable(item);
    const gst = this.itemGst(item);
    return Math.round(taxable * gst) / 100;
  }

  itemNet(item: Record<string, unknown>): number {
    return Number(item['line_total'] ?? item['net'] ?? 0);
  }

  itemShelf(item: Record<string, unknown>): string {
    return String(item['shelf_location'] ?? item['shelf'] ?? '');
  }

  itemCategory(item: Record<string, unknown>): string {
    return String(item['product_category'] ?? item['p_cat'] ?? '');
  }
}
