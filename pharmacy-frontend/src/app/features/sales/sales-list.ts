import { Component, computed, effect, inject, signal, untracked } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { SalesService } from '../../core/services/sales.service';
import { PosModalService } from '../../core/services/pos-modal.service';
import { UserRightsService } from '../../core/services/user-rights.service';
import { CrudService } from '../../core/services/crud.service';
import { Router } from '@angular/router';

@Component({
  selector: 'app-sales-list',
  imports: [CommonModule, FormsModule],
  templateUrl: './sales-list.html',
  styleUrl: './sales-list.scss',
})
export class SalesList {
  private readonly salesSvc = inject(SalesService);
  private readonly pos = inject(PosModalService);
  private readonly rights = inject(UserRightsService);
  private readonly crud = inject(CrudService);
  private readonly router = inject(Router);

  /** View = sales log + NEW SALES / billing; Edit = full saved-bill edit; Delete = remove bill. */
  canViewSales = () => this.rights.canView('SALES');
  canEditSales = () => this.rights.canEdit('SALES');
  canDeleteSales = () => this.rights.canDelete('SALES');
  readonly canEditSavedBill = () => this.rights.hasFullAccess();
  canEditPaymentModeOnly = () => this.canEditSales() && !this.canEditSavedBill();
  readonly deletingId = signal<number | null>(null);

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  readonly rows = signal<Record<string, any>[]>([]);
  readonly loading = signal(true);
  readonly billMode = signal<'retail' | 'wholesale' | 'ipd' | ''>('');
  readonly search = signal('');
  readonly viewing = signal<Record<string, unknown> | null>(null);
  readonly viewLoading = signal(false);
  readonly whatsappSending = signal<number | null>(null);
  readonly toast = signal<string | null>(null);

  /** Payment-mode-only edit (same options as sales form). */
  readonly paymentModes = ['Cash', 'Card', 'UPI', 'Credit', 'Cash+UPI'] as const;
  readonly editing = signal<Record<string, unknown> | null>(null);
  readonly editLoading = signal(false);
  readonly editSaving = signal(false);
  readonly editPaymentMode = signal('Cash');
  readonly editAccountId = signal<number | null>(null);
  readonly editCashAmount = signal<number | null>(null);
  readonly editSplitUpi = computed(() => {
    const net = Math.round((Number(this.editing()?.['net_amount']) || 0) * 100) / 100;
    return Math.max(0, Math.round((net - (Number(this.editCashAmount()) || 0)) * 100) / 100);
  });
  readonly accounts = signal<{ id: number; name: string; account_no?: string; bank_name?: string }[]>([]);
  readonly editError = signal<string | null>(null);

  /** Preview paid/balance for the selected payment mode. */
  readonly editPreview = computed(() => {
    const sale = this.editing();
    if (!sale) {
      return { paid: 0, balance: 0, net: 0 };
    }
    const net = Math.round((Number(sale['net_amount']) || 0) * 100) / 100;
    const mode = this.editPaymentMode().toLowerCase();
    const oldMode = String(sale['payment_mode'] ?? 'cash').toLowerCase();
    if (mode === 'credit') {
      if (oldMode === 'credit') {
        let paid = Math.round((Number(sale['paid']) || 0) * 100) / 100;
        if (paid < 0) paid = 0;
        if (paid > net) paid = net;
        const balance = Math.round((net - paid) * 100) / 100;
        return { paid: balance < 0.01 ? net : paid, balance: balance < 0.01 ? 0 : balance, net };
      }
      return { paid: 0, balance: net, net };
    }
    return { paid: net, balance: 0, net };
  });

  /** KPIs derived from the loaded billing history only. */
  readonly kpis = computed(() => {
    const list = this.rows();
    let net = 0;
    let paid = 0;
    let balance = 0;
    for (const r of list) {
      net += Number(r['net_amount']) || 0;
      paid += Number(r['paid']) || 0;
      balance += Number(r['balance']) || 0;
    }
    return {
      bills: list.length,
      net: Math.round(net * 100) / 100,
      paid: Math.round(paid * 100) / 100,
      balance: Math.round(balance * 100) / 100,
    };
  });

  constructor() {
    // Initial load + auto-refresh when a sale is saved (POS modal stays open over this page).
    effect(() => {
      this.salesSvc.logVersion();
      untracked(() => this.reload());
    });
    this.crud.list<{ id: number; name: string; account_no?: string; bank_name?: string }>('accounts', { perPage: 200 })
      .subscribe({
        next: (res) => this.accounts.set(res.data ?? []),
        error: () => this.accounts.set([]),
      });
  }

  reload(): void {
    this.loading.set(true);
    const mode = this.billMode();
    this.salesSvc.list({
      search: this.search().trim() || undefined,
      bill_mode: mode || undefined,
      per_page: 100,
    }).subscribe({
      next: (res) => {
        this.rows.set(res.data ?? []);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  onTypeChange(value: string): void {
    this.billMode.set((value as 'retail' | 'wholesale' | 'ipd' | '') || '');
    this.reload();
  }

  invoice(id: number): void {
    this.salesSvc.invoiceBlob(id).subscribe((blob) => window.open(URL.createObjectURL(blob), '_blank'));
  }

  invoiceDual(id: number): void {
    this.salesSvc.invoiceDualBlob(id).subscribe((blob) => window.open(URL.createObjectURL(blob), '_blank'));
  }

  invoiceDotMatrix(id: number): void {
    // Open the tab synchronously so the popup blocker allows it, then load the bill page into it.
    const win = window.open('', '_blank');
    win?.document.write('<p style="font-family:Segoe UI,Arial,sans-serif;color:#475569;padding:24px">Preparing dot matrix print…</p>');
    this.salesSvc.invoiceDotMatrixBlob(id).subscribe({
      next: (blob) => {
        const url = URL.createObjectURL(new Blob([blob], { type: 'text/html' }));
        if (win && !win.closed) win.location.href = url;
        else window.open(url, '_blank');
        setTimeout(() => URL.revokeObjectURL(url), 60_000);
      },
      error: () => {
        win?.close();
        this.toast.set('Could not open the dot matrix print.');
      },
    });
  }

  sendWhatsApp(id: number, contact?: string): void {
    const phone = (contact || '').replace(/\D/g, '');
    if (phone.length < 10) {
      this.toast.set('Customer mobile number is required to send WhatsApp.');
      return;
    }
    this.whatsappSending.set(id);
    this.toast.set(null);
    this.salesSvc.sendWhatsApp(id).subscribe({
      next: (res) => {
        this.whatsappSending.set(null);
        this.toast.set(res.message || 'WhatsApp bill sent.');
      },
      error: (err) => {
        this.whatsappSending.set(null);
        const msg = err?.error?.message || err?.error?.errors?.contact_no?.[0] || 'Failed to send WhatsApp bill.';
        this.toast.set(msg);
      },
    });
  }

  view(id: number): void {
    this.viewLoading.set(true);
    this.viewing.set(null);
    this.salesSvc.get(id).subscribe({
      next: (sale) => {
        this.viewing.set(sale);
        this.viewLoading.set(false);
      },
      error: () => this.viewLoading.set(false),
    });
  }

  closeView(): void {
    this.viewing.set(null);
  }

  remove(id: number): void {
    if (!this.canDeleteSales()) {
      this.toast.set('You do not have permission to delete sales.');
      return;
    }
    if (!confirm('Delete this sale bill? Stock will be restored to inventory.')) {
      return;
    }
    this.deletingId.set(id);
    this.toast.set(null);
    this.salesSvc.remove(id).subscribe({
      next: () => {
        this.deletingId.set(null);
        if (Number(this.viewing()?.['id']) === id) {
          this.closeView();
        }
        this.toast.set('Sale deleted and stock restored.');
        this.reload();
      },
      error: (err) => {
        this.deletingId.set(null);
        const msg =
          err?.error?.message ||
          err?.error?.errors?.sale?.[0] ||
          'Could not delete sale.';
        this.toast.set(msg);
      },
    });
  }

  editBill(id: number): void {
    if (!this.canEditSavedBill()) {
      this.toast.set('Only admins can edit the full bill. Use the payment mode edit instead.');
      return;
    }
    void this.router.navigate(['/app/sales', id, 'edit']);
  }

  openEdit(id: number): void {
    if (!this.canEditSales()) {
      this.toast.set('You do not have permission to edit payment mode.');
      return;
    }
    this.editLoading.set(true);
    this.editing.set(null);
    this.editError.set(null);
    this.salesSvc.get(id).subscribe({
      next: (sale) => {
        this.editing.set(sale);
        const label = this.payLabel({ payment_mode: sale['payment_mode'] ?? 'cash' });
        this.editPaymentMode.set(this.paymentModes.includes(label as typeof this.paymentModes[number]) ? label : 'Cash');
        this.editAccountId.set(sale['account_id'] != null ? Number(sale['account_id']) : null);
        this.editCashAmount.set(label === 'Cash+UPI' ? Number(sale['cash_amount']) || null : null);
        this.editLoading.set(false);
      },
      error: () => {
        this.editLoading.set(false);
        this.toast.set('Could not load sale for edit.');
      },
    });
  }

  closeEdit(): void {
    this.editing.set(null);
    this.editError.set(null);
    this.editSaving.set(false);
  }

  onEditPaymentModeChange(mode: string): void {
    this.editPaymentMode.set(mode);
    if (mode !== 'Card') {
      this.editAccountId.set(null);
    }
    if (mode !== 'Cash+UPI') {
      this.editCashAmount.set(null);
    }
    this.editError.set(null);
  }

  savePaymentMode(): void {
    const sale = this.editing();
    if (!sale) {
      return;
    }
    const mode = this.editPaymentMode();
    if (mode === 'Card' && !this.editAccountId()) {
      this.editError.set('Select a bank / card account for Card payments.');
      return;
    }
    const net = Math.round((Number(sale['net_amount']) || 0) * 100) / 100;
    const cash = Math.round((Number(this.editCashAmount()) || 0) * 100) / 100;
    if (mode === 'Cash+UPI' && (cash <= 0 || cash >= net)) {
      this.editError.set(`Cash+UPI: enter the Cash amount — more than 0 and less than Net ₹${net.toFixed(2)}.`);
      return;
    }
    this.editSaving.set(true);
    this.editError.set(null);
    this.salesSvc.updatePaymentMode(
      Number(sale['id']),
      mode.toLowerCase(),
      mode === 'Card' ? this.editAccountId() : null,
      mode === 'Cash+UPI' ? cash : null,
    ).subscribe({
      next: (res) => {
        this.editSaving.set(false);
        this.closeEdit();
        this.toast.set(
          `Payment mode updated to ${this.payLabel({ payment_mode: res.payment_mode })}. Paid ${res.paid.toFixed(2)} · Balance ${res.balance.toFixed(2)}.`,
        );
        this.reload();
      },
      error: (err) => {
        this.editSaving.set(false);
        this.editError.set(
          err?.error?.errors?.cash_amount?.[0]
          || err?.error?.message
          || err?.error?.errors?.payment_mode?.[0]
          || err?.error?.errors?.account_id?.[0]
          || 'Failed to update payment mode.',
        );
      },
    });
  }

  newSale(): void {
    if (!this.canViewSales()) {
      this.toast.set('You do not have permission to create sales.');
      return;
    }
    this.pos.show();
  }

  payLabel(r: Record<string, unknown>): string {
    const m = String(r['payment_mode'] ?? r['cash_credit'] ?? '');
    if (m.toLowerCase() === 'cash+upi') return 'Cash+UPI';
    if (m.toLowerCase() === 'upi') return 'UPI';
    return m ? m.charAt(0).toUpperCase() + m.slice(1).toLowerCase() : '-';
  }

  accountLabel(id: number | null | undefined): string {
    if (id == null) {
      return '-';
    }
    const a = this.accounts().find((x) => x.id === id);
    if (!a) {
      return String(id);
    }
    const bits = [a.name, a.bank_name, a.account_no].filter(Boolean);
    return bits.join(' · ') || a.name;
  }

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  itemsOf(sale: Record<string, any>): any[] {
    return Array.isArray(sale['items']) ? sale['items'] : [];
  }

  itemRate(it: Record<string, unknown>): number {
    const v = it['display_rate'];
    if (v != null && v !== '') return Number(v) || 0;
    return Number(it['rate']) || 0;
  }

  itemMrp(it: Record<string, unknown>): number {
    const v = it['display_mrp'];
    if (v != null && v !== '') return Number(v) || 0;
    return Number(it['mrp']) || 0;
  }

  itemQty(it: Record<string, unknown>): number {
    const v = it['display_qty'];
    if (v != null && v !== '') return Number(v) || 0;
    return Number(it['quantity']) || 0;
  }

  itemDiscPct(it: Record<string, unknown>): number {
    const v = it['display_disc_pct'];
    if (v != null && v !== '') return Number(v) || 0;
    return Number(it['discount_pct']) || 0;
  }

  itemNet(it: Record<string, unknown>): number {
    const v = it['display_net'];
    if (v != null && v !== '') return Number(v) || 0;
    return Number(it['line_total']) || 0;
  }
}
