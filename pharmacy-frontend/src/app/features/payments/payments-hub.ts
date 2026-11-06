import { Component, OnInit, inject, signal, computed } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { MetaService } from '../../core/services/meta.service';
import { PaymentsHubService } from '../../core/services/payments-hub.service';

type Tab = 'distributor' | 'customer' | 'wcustomer' | 'vouchers';

@Component({
  selector: 'app-payments-hub',
  imports: [CommonModule, FormsModule],
  templateUrl: './payments-hub.html',
  styleUrl: './payments-hub.scss',
})
export class PaymentsHubPage implements OnInit {
  private readonly api = inject(PaymentsHubService);
  private readonly meta = inject(MetaService);

  readonly tab = signal<Tab>('distributor');
  readonly loading = signal(false);
  readonly saving = signal(false);
  readonly error = signal<string | null>(null);
  readonly toast = signal<string | null>(null);

  readonly businessType = this.meta.businessType;
  readonly showRetail = computed(() => {
    const t = this.businessType();
    return t === 'retail' || t === 'both';
  });
  readonly showWholesale = computed(() => {
    const t = this.businessType();
    return t === 'wholesale' || t === 'both';
  });

  // Distributor
  readonly distList = signal<any[]>([]);
  distFilter = '';
  distDetail: any = null;
  distAmount: number | null = null;
  distMode = 'Cash';
  distTxn = '';
  showDistHistory = false;
  readonly distHistory = signal<any[]>([]);

  // Customer
  readonly custList = signal<any[]>([]);
  custDetail: any = null;
  custAmount: number | null = null;
  custMode = 'Cash';
  custTxn = '';
  custSubTab: 'payment' | 'history' = 'payment';
  readonly custHistory = signal<any[]>([]);
  readonly custClearedLog = signal<any[]>([]);
  custHistoryFilter = '';

  // Wholesale customer
  readonly wcustList = signal<any[]>([]);
  wcustDetail: any = null;
  wcustAmount: number | null = null;
  wcustMode = 'Cash';
  wcustTxn = '';
  wcustSubTab: 'payment' | 'history' = 'payment';
  readonly wcustHistory = signal<any[]>([]);
  readonly wcustClearedLog = signal<any[]>([]);
  wcustHistoryFilter = '';

  // Vouchers
  readonly vouchers = signal<any[]>([]);
  voucherSearch = '';
  showVoucherForm = false;
  vForm = {
    voucher_type: 'receipt',
    voucher_date: new Date().toISOString().slice(0, 10),
    party_type: 'customer',
    party_name: '',
    amount: 0,
    mode: 'cash',
    ref_no: '',
    narration: '',
  };

  readonly modesDist = ['Cash', 'UPI', 'Cheque', 'Bank / Card'];
  readonly modesCust = ['Cash', 'Cheque', 'Bank / Card'];

  /** Nearest-rupee display; sub-₹1 leftovers (e.g. 0.50) show as 0, not ₹1. */
  roundRupee(value: unknown): number {
    const n = Math.round((Number(value) || 0) * 100) / 100;
    if (n > 0 && n < 1) return 0;
    if (n < 0 && n > -1) return 0;
    return Math.round(n);
  }

  ngOnInit(): void {
    this.meta.loadProfile();
    this.setTab('distributor');
  }

  setTab(t: Tab): void {
    this.tab.set(t);
    this.error.set(null);
    this.toast.set(null);
    this.distDetail = null;
    this.custDetail = null;
    this.wcustDetail = null;
    this.showDistHistory = false;
    this.custSubTab = 'payment';
    this.wcustSubTab = 'payment';
    if (t === 'distributor') this.loadDist();
    else if (t === 'customer') this.loadCust();
    else if (t === 'wcustomer') this.loadWcust();
    else this.loadVouchers();
  }

  filteredDist(): any[] {
    const q = this.distFilter.trim().toLowerCase();
    const list = this.distList();
    if (!q) return list;
    return list.filter((p) => String(p.distributor_name || '').toLowerCase().includes(q));
  }

  loadDist(): void {
    this.loading.set(true);
    this.api.distributorDues().subscribe({
      next: (r) => {
        this.distList.set(r);
        this.loading.set(false);
      },
      error: (e) => {
        this.loading.set(false);
        this.error.set(e?.error?.message || 'Failed to load distributor dues.');
      },
    });
  }

  openDist(p: any): void {
    this.distDetail = JSON.parse(JSON.stringify(p));
    this.distAmount = null;
    this.distMode = 'Cash';
    this.distTxn = '';
    this.applyDistFifo();
  }

  applyDistFifo(): void {
    if (!this.distDetail?.details) return;
    let left = Number(this.distAmount) || 0;
    const max = this.distMax();
    if (left > max) {
      left = max;
      this.distAmount = max;
    }
    for (const d of this.distDetail.details) {
      const bal = Number(d.balance) || 0;
      const pay = left > 0 ? Math.min(left, bal) : 0;
      d.payment_amount = Math.round(pay * 100) / 100;
      d.balance_amount = Math.round((bal - pay) * 100) / 100;
      d.selected = d.payment_amount > 0;
      d.payment_mode = this.distMode;
      d.transaction_no = this.distTxn;
      left = Math.round((left - pay) * 100) / 100;
    }
  }

  /** Tick an invoice: Pay Now becomes the remaining amount, and stays editable. */
  onDistInvoiceCheck(d: any, checked: boolean): void {
    const bal = Number(d.balance) || 0;
    d.selected = checked;
    d.payment_amount = checked ? Math.round(bal * 100) / 100 : 0;
    d.balance_amount = Math.round((bal - d.payment_amount) * 100) / 100;
    d.payment_mode = this.distMode;
    d.transaction_no = this.distTxn;
    this.syncDistAmountFromLines();
  }

  distAllChecked(): boolean {
    const rows = this.distDetail?.details || [];
    return rows.length > 0 && rows.every((d: any) => !!d.selected);
  }

  onDistCheckAll(checked: boolean): void {
    for (const d of this.distDetail?.details || []) {
      this.onDistInvoiceCheck(d, checked);
    }
  }

  onDistLinePay(d: any): void {
    let pay = Number(d.payment_amount) || 0;
    const bal = Number(d.balance) || 0;
    if (pay < 0) pay = 0;
    if (pay > bal) pay = bal;
    d.payment_amount = Math.round(pay * 100) / 100;
    d.balance_amount = Math.round((bal - pay) * 100) / 100;
    d.selected = d.payment_amount > 0;
    d.payment_mode = this.distMode;
    d.transaction_no = this.distTxn;
    this.syncDistAmountFromLines();
  }

  private syncDistAmountFromLines(): void {
    const sum = (this.distDetail?.details || []).reduce(
      (s: number, d: any) => s + (Number(d.payment_amount) || 0),
      0,
    );
    this.distAmount = Math.round(sum * 100) / 100;
  }

  distMax(): number {
    if (!this.distDetail?.details) return 0;
    return Math.round(
      this.distDetail.details.reduce((s: number, d: any) => s + (Number(d.balance) || 0), 0) * 100,
    ) / 100;
  }

  saveDist(): void {
    this.error.set(null);
    this.saving.set(true);
    // sync mode onto lines
    for (const d of this.distDetail.details || []) {
      if (Number(d.payment_amount) > 0) {
        d.payment_mode = this.distMode;
        d.transaction_no = this.distTxn;
      }
    }
    this.api
      .settleDistributor({
        distributor_name: this.distDetail.distributor_name,
        mode: this.distMode,
        transaction_no: this.distTxn,
        details: this.distDetail.details,
      })
      .subscribe({
        next: () => {
          this.saving.set(false);
          this.toast.set('Distributor payment saved.');
          this.distDetail = null;
          this.loadDist();
        },
        error: (e) => {
          this.saving.set(false);
          this.toast.set(null);
          this.error.set(this.apiError(e, 'Save failed.'));
        },
      });
  }

  openDistHistory(): void {
    this.showDistHistory = true;
    this.api.history('distributor').subscribe({ next: (r) => this.distHistory.set(r) });
  }

  // ---- Retail customer ----
  loadCust(): void {
    this.loading.set(true);
    this.api.customerDues().subscribe({
      next: (r) => {
        this.custList.set(r);
        this.loading.set(false);
      },
      error: (e) => {
        this.loading.set(false);
        this.error.set(e?.error?.message || 'Failed to load customer dues.');
      },
    });
  }

  showCustPaymentTab(): void {
    this.custSubTab = 'payment';
    this.custDetail = null;
    this.loadCust();
  }

  showCustHistoryTab(): void {
    this.custSubTab = 'history';
    this.custDetail = null;
    this.loadCustClearedLog();
  }

  loadCustClearedLog(): void {
    this.loading.set(true);
    this.api.history('customer').subscribe({
      next: (r) => {
        this.custClearedLog.set(r);
        this.loading.set(false);
      },
      error: () => {
        this.custClearedLog.set([]);
        this.loading.set(false);
      },
    });
  }

  filteredCustCleared(): any[] {
    const q = this.custHistoryFilter.trim().toLowerCase();
    const list = this.custClearedLog();
    if (!q) return list;
    return list.filter(
      (h) =>
        String(h.patient_name || h.party_name || '').toLowerCase().includes(q) ||
        String(h.bill_no || '').toLowerCase().includes(q) ||
        String(h.contact_no || '').toLowerCase().includes(q),
    );
  }

  openCust(p: any): void {
    this.custDetail = JSON.parse(JSON.stringify(p));
    this.custAmount = null;
    this.custMode = 'Cash';
    this.custTxn = '';
    this.api.history('customer', p.patient_name, p.contact_no).subscribe({
      next: (r) => this.custHistory.set(r),
    });
  }

  custMax(): number {
    if (!this.custDetail?.details) return 0;
    return Math.round(
      this.custDetail.details.reduce((s: number, d: any) => s + (Number(d.balance) || 0), 0) * 100,
    ) / 100;
  }

  saveCust(): void {
    this.error.set(null);
    let amt = Number(this.custAmount) || 0;
    const max = this.custMax();
    if (amt <= 0) {
      this.error.set('Enter payment amount.');
      return;
    }
    if (amt > max) amt = max;
    this.saving.set(true);
    this.api
      .settleCustomer({
        patient_name: this.custDetail.patient_name,
        contact_no: this.custDetail.contact_no,
        amount: amt,
        mode: this.custMode,
        transaction_no: this.custTxn,
      })
      .subscribe({
        next: (res) => {
          this.saving.set(false);
          this.toast.set('Customer payment saved.');
          this.custDetail = null;
          this.loadCust();
          this.printFromSettle(res);
        },
        error: (e) => {
          this.saving.set(false);
          this.error.set(e?.error?.message || 'Save failed.');
        },
      });
  }

  // ---- Wholesale customer ----
  loadWcust(): void {
    this.loading.set(true);
    this.api.wholesaleCustomerDues().subscribe({
      next: (r) => {
        this.wcustList.set(r);
        this.loading.set(false);
      },
      error: (e) => {
        this.loading.set(false);
        this.error.set(e?.error?.message || 'Failed to load wholesale dues.');
      },
    });
  }

  showWcustPaymentTab(): void {
    this.wcustSubTab = 'payment';
    this.wcustDetail = null;
    this.loadWcust();
  }

  showWcustHistoryTab(): void {
    this.wcustSubTab = 'history';
    this.wcustDetail = null;
    this.loadWcustClearedLog();
  }

  loadWcustClearedLog(): void {
    this.loading.set(true);
    this.api.history('wcustomer').subscribe({
      next: (r) => {
        this.wcustClearedLog.set(r);
        this.loading.set(false);
      },
      error: () => {
        this.wcustClearedLog.set([]);
        this.loading.set(false);
      },
    });
  }

  filteredWcustCleared(): any[] {
    const q = this.wcustHistoryFilter.trim().toLowerCase();
    const list = this.wcustClearedLog();
    if (!q) return list;
    return list.filter(
      (h) =>
        String(h.patient_name || h.party_name || '').toLowerCase().includes(q) ||
        String(h.bill_no || '').toLowerCase().includes(q) ||
        String(h.contact_no || '').toLowerCase().includes(q),
    );
  }

  openWcust(p: any): void {
    this.wcustDetail = JSON.parse(JSON.stringify(p));
    this.wcustAmount = null;
    this.wcustMode = 'Cash';
    this.wcustTxn = '';
    this.applyWcustFifo();
    this.api.history('wcustomer', p.patient_name, p.contact_no).subscribe({
      next: (r) => this.wcustHistory.set(r),
    });
  }

  applyWcustFifo(): void {
    if (!this.wcustDetail?.details) return;
    let left = Number(this.wcustAmount) || 0;
    const max = this.wcustMax();
    if (left > max) {
      left = max;
      this.wcustAmount = max;
    }
    for (const d of this.wcustDetail.details) {
      const bal = Number(d.balance) || 0;
      const pay = left > 0 ? Math.min(left, bal) : 0;
      d.payment_amount = Math.round(pay * 100) / 100;
      d.balance_amount = Math.round((bal - pay) * 100) / 100;
      d.payment_mode = this.wcustMode;
      d.transaction_no = this.wcustTxn;
      left = Math.round((left - pay) * 100) / 100;
    }
  }

  onWcustLinePay(d: any): void {
    let pay = Number(d.payment_amount) || 0;
    const bal = Number(d.balance) || 0;
    if (pay < 0) pay = 0;
    if (pay > bal) pay = bal;
    d.payment_amount = Math.round(pay * 100) / 100;
    d.balance_amount = Math.round((bal - pay) * 100) / 100;
    d.payment_mode = this.wcustMode;
    d.transaction_no = this.wcustTxn;
  }

  wcustMax(): number {
    if (!this.wcustDetail?.details) return 0;
    return Math.round(
      this.wcustDetail.details.reduce((s: number, d: any) => s + (Number(d.balance) || 0), 0) * 100,
    ) / 100;
  }

  saveWcust(): void {
    this.error.set(null);
    this.saving.set(true);
    for (const d of this.wcustDetail.details || []) {
      if (Number(d.payment_amount) > 0) {
        d.payment_mode = this.wcustMode;
        d.transaction_no = this.wcustTxn;
      }
    }
    this.api
      .settleWholesaleCustomer({
        patient_name: this.wcustDetail.patient_name,
        contact_no: this.wcustDetail.contact_no,
        mode: this.wcustMode,
        transaction_no: this.wcustTxn,
        details: this.wcustDetail.details,
      })
      .subscribe({
        next: (res) => {
          this.saving.set(false);
          this.toast.set('Wholesale payment saved.');
          this.wcustDetail = null;
          this.loadWcust();
          this.printFromSettle(res);
        },
        error: (e) => {
          this.saving.set(false);
          this.error.set(e?.error?.message || 'Save failed.');
        },
      });
  }

  // ---- Vouchers ----
  loadVouchers(): void {
    this.loading.set(true);
    this.api.vouchers(this.voucherSearch).subscribe({
      next: (r) => {
        this.vouchers.set(r);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  saveVoucher(): void {
    this.error.set(null);
    if (!this.vForm.party_name || !this.vForm.amount) {
      this.error.set('Party name and amount are required.');
      return;
    }
    this.saving.set(true);
    this.api.saveVoucher({ ...this.vForm }).subscribe({
      next: () => {
        this.saving.set(false);
        this.toast.set('Voucher saved.');
        this.showVoucherForm = false;
        this.vForm = {
          voucher_type: 'receipt',
          voucher_date: new Date().toISOString().slice(0, 10),
          party_type: 'customer',
          party_name: '',
          amount: 0,
          mode: 'cash',
          ref_no: '',
          narration: '',
        };
        this.loadVouchers();
      },
      error: (e) => {
        this.saving.set(false);
        this.error.set(e?.error?.message || 'Save failed.');
      },
    });
  }

  deleteVoucher(id: number): void {
    if (!confirm('Delete this voucher?')) return;
    this.api.deleteVoucher(id).subscribe({ next: () => this.loadVouchers() });
  }

  printPayment(id: number): void {
    this.api.printAllocation(id);
  }

  /** Auto-open receipt(s) after settle when API returns allocation ids. */
  private printFromSettle(res: any): void {
    const ids: number[] = Array.isArray(res?.allocation_ids)
      ? res.allocation_ids
      : (Array.isArray(res?.applied) ? res.applied.map((a: any) => a.allocation_id).filter(Boolean) : []);
    for (const id of ids) {
      if (id > 0) this.api.printAllocation(id);
    }
  }

  private apiError(e: any, fallback: string): string {
    const msg = e?.error?.message;
    if (typeof msg === 'string' && msg.trim()) return msg;
    const errors = e?.error?.errors;
    if (errors && typeof errors === 'object') {
      const first = Object.values(errors).flat().find((x) => typeof x === 'string' && x.trim());
      if (typeof first === 'string') return first;
    }
    return fallback;
  }
}
