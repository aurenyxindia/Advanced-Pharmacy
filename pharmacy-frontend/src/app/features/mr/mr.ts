import { ChangeDetectorRef, Component, OnInit, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpErrorResponse } from '@angular/common/http';
import { Router } from '@angular/router';
import { AuthService } from '../../core/services/auth.service';
import { CrudService } from '../../core/services/crud.service';
import { MetaService } from '../../core/services/meta.service';
import {
  MrCustomer,
  MrDailyReport,
  MrMonitor,
  MrOrder,
  MrOrderItem,
  MrOsFollowup,
  MrOutstandingRow,
  MrRateHistory,
  MrService,
  MrStats,
  MrStockFollowup,
  MrStockRow,
  MrVisit,
} from '../../core/services/mr.service';
import { SalesService } from '../../core/services/sales.service';
import { UserRightsService } from '../../core/services/user-rights.service';
import {
  addressError,
  emailError,
  formatGstin,
  formatPhone,
  gstinError,
  phoneError,
} from '../../core/utils/input-format';
import { MrCustomerPicker } from './mr-customer-picker';
import { MrStockPicker } from './mr-stock-picker';

type Errors = Record<string, string | null>;

/** Dropdown-backed / case-sensitive keys that must not be uppercased on save. */
const KEEP_CASE = new Set(['email', 'status', 'outcome', 'order_type', 'delivery_type', 'location_type', 'stock_status']);

/** All MR free-text is stored in capitals (Retail parity). */
function upperText<T extends object>(o: T): T {
  const out = { ...o } as Record<string, unknown>;
  for (const [k, v] of Object.entries(out)) {
    if (typeof v === 'string' && !KEEP_CASE.has(k)) out[k] = v.trim().toUpperCase();
  }
  return out as T;
}

/** Person / place names: letters with . ' - spaces (e.g. "Dr. A. D'Souza"). */
const PERSON_RE = /^[A-Za-z][A-Za-z .'-]*$/;
/** Place / specialty text: letters with common separators, no digits. */
const PLACE_RE = /^[A-Za-z][A-Za-z .,&'()\/-]*$/;
/** Business names / areas: must contain a letter; digits allowed (e.g. "24x7 Medicals", "Sector 21"). */
const ORG_RE = /^[A-Za-z0-9][A-Za-z0-9 .,&'()\/-]*$/;
const DL_RE = /^[A-Za-z0-9][A-Za-z0-9 \/,.-]*$/;

function textError(
  value: string | null | undefined,
  label: string,
  kind: 'person' | 'place' | 'org',
  required = false,
  max = 160,
): string | null {
  const v = (value ?? '').trim();
  if (!v) return required ? `${label} is required.` : null;
  if (v.length < 2) return `${label} must be at least 2 characters.`;
  if (v.length > max) return `${label} must be at most ${max} characters.`;
  if (kind === 'person' && !PERSON_RE.test(v)) return `${label} should contain letters only.`;
  if (kind === 'place' && !PLACE_RE.test(v)) return `${label} should contain letters only (no digits).`;
  if (kind === 'org' && (!ORG_RE.test(v) || !/[A-Za-z]/.test(v))) return `${label} contains invalid characters.`;
  return null;
}

function maxLenError(value: string | null | undefined, label: string, max: number): string | null {
  return (value ?? '').trim().length > max ? `${label} must be at most ${max} characters.` : null;
}

function numberError(
  value: unknown,
  label: string,
  opts: { required?: boolean; integer?: boolean; min?: number; max?: number } = {},
): string | null {
  if (value === null || value === undefined || value === '') return opts.required ? `${label} is required.` : null;
  const n = Number(value);
  if (!Number.isFinite(n)) return `${label} must be a number.`;
  if (opts.integer && !Number.isInteger(n)) return `${label} must be a whole number.`;
  const min = opts.min ?? 0;
  if (n < min) return min === 0 ? `${label} cannot be negative.` : `${label} must be at least ${min}.`;
  if (opts.max != null && n > opts.max) return `${label} cannot exceed ${opts.max}.`;
  return null;
}

function firstError(errors: Errors): string | null {
  return Object.values(errors).find((e) => !!e) ?? null;
}

type Tab = 'dashboard' | 'visits' | 'orders' | 'stock' | 'monitor' | 'reports' | 'outstanding' | 'customers';

interface OrderForm {
  order_date: string;
  customer_id: number | null;
  customer_name: string;
  customer_contact: string;
  customer_address: string;
  customer_dl_no: string;
  order_type: string;
  expected_delivery_date: string;
  delivery_type: string;
  remarks: string;
}

interface CustomerForm {
  name: string;
  phone: string;
  email: string;
  address: string;
  state: string;
  city: string;
  area: string;
  gstin: string;
  credit_days: number;
  dl_no: string;
}

/** Payload read by Sales → New (wholesale) to pre-fill an MR order (one-shot). */
export interface MrDoSalePayload {
  order_id: number;
  order_no: string;
  customer_id: number | null;
  customer_name: string;
  customer_contact: string;
  customer_address: string;
  lines: {
    binding_id: number;
    batch_id: number;
    qty: number;
    rate: number;
    product_name: string;
    gst?: number;
    hsn?: string | null;
    pack_size?: string | null;
    m_name?: string | null;
  }[];
}

@Component({
  selector: 'app-mr',
  imports: [CommonModule, FormsModule, MrStockPicker, MrCustomerPicker],
  templateUrl: './mr.html',
  styleUrl: './mr.scss',
})
export class MrPage implements OnInit {
  private readonly svc = inject(MrService);
  private readonly salesSvc = inject(SalesService);
  private readonly crud = inject(CrudService);
  private readonly meta = inject(MetaService);
  private readonly auth = inject(AuthService);
  private readonly rights = inject(UserRightsService);
  private readonly router = inject(Router);
  private readonly cdr = inject(ChangeDetectorRef);

  readonly canEdit = () => this.rights.canEdit('MR');
  readonly canDelete = () => this.rights.canDelete('MR');
  readonly canSell = () => this.rights.canView('SALES');

  readonly outcomeOptions = ['Promoted', 'Sample Given', 'Order Expected', 'Not Interested', 'Follow-Up'];
  readonly orderTypeOptions = ['Wholesaler', 'Retailer', 'Distributor'];
  readonly deliveryTypeOptions = [
    { value: 'Regular', label: 'Regular Delivery' },
    { value: 'Urgent', label: 'Urgent Delivery' },
    { value: 'Other', label: 'Other' },
  ];
  readonly locationTypeOptions = ['Pharmacy', 'Distributor', 'Wholesaler'];
  readonly stockStatusOptions = ['Available', 'Low', 'Out Of Stock', 'Near Expiry'];
  readonly osStatusOptions = ['Open', 'Promised', 'Closed'];

  /** Set on first save attempt so "required" messages appear only after the user tries to save. */
  visitTried = false;
  orderTried = false;
  stockFuTried = false;
  reportTried = false;
  osTried = false;
  customerTried = false;

  readonly tab = signal<Tab>('dashboard');
  readonly flash = signal<{ text: string; type: 'success' | 'danger' | 'info' } | null>(null);
  private flashTimer?: ReturnType<typeof setTimeout>;
  readonly profileReady = signal(false);
  readonly blocked = computed(() => this.profileReady() && this.meta.businessType() === 'retail');
  readonly mrName = computed(() => this.auth.user()?.name ?? '');
  readonly today = this.iso(new Date());

  fromDate = this.monthStart();
  toDate = this.today;

  // ---- Dashboard ----
  readonly stats = signal<MrStats | null>(null);
  readonly statsLoading = signal(false);

  // ---- Customers (shared by order / outstanding / customers tab) ----
  readonly customers = signal<MrCustomer[]>([]);
  readonly customersLoading = signal(false);
  readonly customerSearch = signal('');
  readonly filteredCustomers = computed(() => {
    const q = this.customerSearch().trim().toLowerCase();
    const list = this.customers();
    if (!q) return list;
    return list.filter((c) =>
      [c.name, c.phone, c.area, c.city, c.code].some((v) => (v ?? '').toString().toLowerCase().includes(q)),
    );
  });
  readonly showCustomerForm = signal(false);
  readonly savingCustomer = signal(false);
  customerForm: CustomerForm = this.blankCustomer();

  // ---- Visits ----
  visitForm: MrVisit = this.blankVisit();
  readonly discussed = signal<string[]>([]);
  readonly visits = signal<MrVisit[]>([]);
  readonly visitsLoading = signal(false);
  readonly savingVisit = signal(false);

  // ---- Orders ----
  orderForm: OrderForm = this.blankOrder();
  readonly orderNo = signal('');
  readonly editingId = signal<number | null>(null);
  readonly orderItems = signal<MrOrderItem[]>([]);
  readonly orderTotal = computed(() => Math.round(this.orderItems().reduce((s, r) => s + (Number(r.amount) || 0), 0) * 100) / 100);
  readonly selectedStock = signal<MrStockRow | null>(null);
  readonly lineQty = signal(1);
  readonly lineRate = signal(0);
  readonly previousRate = signal(0);
  readonly rateHistory = signal<MrRateHistory[]>([]);
  readonly savingOrder = signal(false);
  readonly orders = signal<MrOrder[]>([]);
  readonly ordersLoading = signal(false);
  readonly viewOrder = signal<{ order: MrOrder; items: MrOrderItem[] } | null>(null);
  readonly pdfBusy = signal<number | null>(null);

  // ---- Stock follow-up ----
  stockFuForm: MrStockFollowup = this.blankStockFu();
  readonly stockFuProduct = signal('');
  readonly stockList = signal<MrStockRow[]>([]);
  readonly stockListLoading = signal(false);
  stockQuery = '';
  readonly stockFollowups = signal<MrStockFollowup[]>([]);
  readonly savingStockFu = signal(false);

  // ---- Monitor ----
  readonly monitor = signal<MrMonitor | null>(null);
  readonly monitorLoading = signal(false);

  // ---- Daily report ----
  reportForm: MrDailyReport = this.blankReport();
  readonly dailyReports = signal<MrDailyReport[]>([]);
  readonly savingReport = signal(false);

  // ---- Outstanding ----
  readonly outstanding = signal<MrOutstandingRow[]>([]);
  readonly osFollowups = signal<MrOsFollowup[]>([]);
  readonly osLoading = signal(false);
  osForm: MrOsFollowup = this.blankOs();
  readonly savingOs = signal(false);

  ngOnInit(): void {
    this.meta.loadProfile().subscribe({
      next: () => this.profileReady.set(true),
      error: () => this.profileReady.set(true),
    });
    this.loadDashboard();
    this.loadCustomers();
  }

  setTab(t: Tab): void {
    this.tab.set(t);
    switch (t) {
      case 'dashboard': this.loadDashboard(); break;
      case 'visits': this.loadVisits(); break;
      case 'orders':
        this.loadOrders();
        if (!this.editingId()) this.loadNextOrderNo();
        break;
      case 'stock': this.loadStockList(); this.loadStockFollowups(); break;
      case 'monitor': this.loadMonitor(); break;
      case 'reports': this.loadDailyReports(); break;
      case 'outstanding': this.loadOutstanding(); break;
      case 'customers': this.loadCustomers(); break;
    }
  }

  // =================================================================== Dashboard

  loadDashboard(): void {
    this.statsLoading.set(true);
    this.svc.dashboard().subscribe({
      next: (s) => { this.stats.set(s); this.statsLoading.set(false); },
      error: (e: HttpErrorResponse) => { this.statsLoading.set(false); this.showError(e, 'Could not load MR dashboard.'); },
    });
  }

  // =================================================================== Customers

  loadCustomers(): void {
    this.customersLoading.set(true);
    this.svc.customers().subscribe({
      next: (list) => { this.customers.set(list ?? []); this.customersLoading.set(false); },
      error: () => this.customersLoading.set(false),
    });
  }

  saveCustomer(): void {
    if (!this.canEdit()) return;
    this.customerTried = true;
    const err = firstError(this.customerErrors());
    if (err) { this.toast(err, 'danger'); return; }
    const f = upperText(this.customerForm);
    const name = f.name;
    const phone = formatPhone(f.phone);
    const gstin = f.gstin;
    this.savingCustomer.set(true);
    this.crud.create<{ id: number }>('customers', {
      customer_type: 'wholesale',
      medical_type: 'Retail',
      name,
      phone,
      email: f.email.trim().toLowerCase() || undefined,
      address: f.address || undefined,
      state: f.state || undefined,
      city: f.city || undefined,
      area: f.area || undefined,
      gstin: gstin || undefined,
      gst_registered: gstin ? 1 : 0,
      credit_days: Number(f.credit_days) || 0,
      dl_no: f.dl_no || undefined,
    }).subscribe({
      next: () => {
        this.savingCustomer.set(false);
        this.toast(`Customer added — ${name}`, 'success');
        this.customerForm = this.blankCustomer();
        this.customerTried = false;
        this.showCustomerForm.set(false);
        this.loadCustomers();
        this.cdr.markForCheck();
      },
      error: (e: HttpErrorResponse) => { this.savingCustomer.set(false); this.showError(e, 'Could not add customer.'); },
    });
  }

  useInOrder(c: MrCustomer): void {
    this.applyOrderCustomer(c);
    this.setTab('orders');
  }

  // =================================================================== Visits

  loadVisits(): void {
    this.visitsLoading.set(true);
    this.svc.visits(this.fromDate, this.toDate).subscribe({
      next: (rows) => { this.visits.set(rows ?? []); this.visitsLoading.set(false); },
      error: (e: HttpErrorResponse) => { this.visitsLoading.set(false); this.showError(e, 'Could not load visits.'); },
    });
  }

  addDiscussed(s: MrStockRow): void {
    const name = s.p_name.trim();
    if (!name || this.discussed().includes(name)) return;
    this.discussed.update((l) => [...l, name]);
  }

  removeDiscussed(name: string): void {
    this.discussed.update((l) => l.filter((x) => x !== name));
  }

  saveVisit(): void {
    if (!this.canEdit()) return;
    this.visitTried = true;
    const err = firstError(this.visitErrors());
    if (err) { this.toast(err, 'danger'); return; }
    this.savingVisit.set(true);
    this.svc.saveVisit(upperText({ ...this.visitForm, products_discussed: this.discussed().join(', ') })).subscribe({
      next: () => {
        this.savingVisit.set(false);
        this.toast('Visit saved.', 'success');
        this.visitForm = this.blankVisit();
        this.visitTried = false;
        this.discussed.set([]);
        this.loadVisits();
        this.cdr.markForCheck();
      },
      error: (e: HttpErrorResponse) => { this.savingVisit.set(false); this.showError(e, 'Could not save visit.'); },
    });
  }

  // =================================================================== Orders

  loadNextOrderNo(): void {
    this.svc.nextOrderNo().subscribe({ next: (no) => this.orderNo.set(no), error: () => this.orderNo.set('') });
  }

  loadOrders(): void {
    this.ordersLoading.set(true);
    this.svc.orders(this.fromDate, this.toDate).subscribe({
      next: (rows) => { this.orders.set(rows ?? []); this.ordersLoading.set(false); },
      error: (e: HttpErrorResponse) => { this.ordersLoading.set(false); this.showError(e, 'Could not load orders.'); },
    });
  }

  onOrderCustomerPicked(c: MrCustomer | null): void {
    if (c) {
      this.applyOrderCustomer(c);
      return;
    }
    this.orderForm.customer_id = null;
    this.orderForm.customer_name = '';
    this.orderForm.customer_contact = '';
    this.orderForm.customer_address = '';
    this.orderForm.customer_dl_no = '';
    this.previousRate.set(0);
    this.rateHistory.set([]);
  }

  /** "+ Add new customer" from a picker → Customers tab with the form open. */
  openAddCustomer(): void {
    if (!this.canEdit()) return;
    this.setTab('customers');
    this.showCustomerForm.set(true);
  }

  private applyOrderCustomer(c: MrCustomer): void {
    this.orderForm.customer_id = c.id;
    this.orderForm.customer_name = c.name;
    this.orderForm.customer_contact = c.phone ?? '';
    this.orderForm.customer_address = c.address ?? '';
    this.orderForm.customer_dl_no = c.dl_no ?? '';
    const s = this.selectedStock();
    if (s) this.loadPreviousRates(s);
    this.cdr.markForCheck();
  }

  selectStock(s: MrStockRow): void {
    this.selectedStock.set(s);
    this.lineQty.set(1);
    this.lineRate.set(Number(s.sale_rate || s.ptr || s.pts || s.purchase_rate || s.mrp || 0));
    this.previousRate.set(0);
    this.rateHistory.set([]);
    // Scheme % / Disc % / corrected PTR-PTS come from the batch's purchase line.
    this.salesSvc.batches(s.binding_id).subscribe({
      next: (bs) => {
        const b = (bs ?? []).find((x) => x.batch_id === s.batch_id);
        if (!b || this.selectedStock()?.batch_id !== s.batch_id) return;
        this.selectedStock.set({
          ...s,
          mrp: Number(b.pack_mrp) || s.mrp,
          purchase_rate: Number(b.pack_purchase_rate) || s.purchase_rate,
          ptr: Number(b.ptr) || s.ptr,
          pts: Number(b.pts) || s.pts,
          scheme_pct: Number(b.scheme_pct) || 0,
          discount_pct: Number(b.discount_pct) || 0,
        });
      },
      error: () => {},
    });
    if (this.orderForm.customer_name.trim()) this.loadPreviousRates(s);
  }

  private loadPreviousRates(s: MrStockRow): void {
    this.svc.previousRates(this.orderForm.customer_id, this.orderForm.customer_name, s.binding_id, s.p_name).subscribe({
      next: (r) => {
        if (this.selectedStock()?.batch_id !== s.batch_id) return;
        const prev = Number(r.previous_rate) || 0;
        this.previousRate.set(prev);
        this.rateHistory.set(r.history ?? []);
        if (prev > 0) this.lineRate.set(prev);
      },
      error: () => {},
    });
  }

  usePreviousRate(): void {
    if (this.previousRate() > 0) this.lineRate.set(this.previousRate());
  }

  clearSelectedStock(): void {
    this.selectedStock.set(null);
    this.rateHistory.set([]);
    this.previousRate.set(0);
  }

  addOrderLine(): void {
    const s = this.selectedStock();
    if (!s) { this.toast('Select a stock product first.', 'danger'); return; }
    const lineErr = firstError(this.lineErrors());
    if (lineErr) { this.toast(lineErr, 'danger'); return; }
    const qty = Number(this.lineQty()) || 0;
    const rate = Math.round((Number(this.lineRate()) || 0) * 100) / 100;
    const already = this.orderItems()
      .filter((it) => it.batch_id === s.batch_id)
      .reduce((sum, it) => sum + (Number(it.qty) || 0), 0);
    if (already + qty > s.qty) {
      this.toast(`Only ${s.qty} in stock for batch ${s.batch_no} (${already} already added).`, 'danger');
      return;
    }
    this.orderItems.update((l) => [
      ...l,
      {
        product_binding_id: s.binding_id,
        batch_id: s.batch_id,
        product_name: s.p_name,
        pack_size: s.pack_size,
        batch_no: s.batch_no,
        expiry_date: s.expiry_date,
        qty,
        mrp: Number(s.mrp) || 0,
        rate,
        amount: Math.round(qty * rate * 100) / 100,
        stock_qty: s.qty,
        gst: Number(s.gst) || 0,
        hsn: s.hsn,
        m_name: s.m_name,
        previous_rate: this.previousRate() || 0,
      },
    ]);
    this.clearSelectedStock();
  }

  removeOrderLine(i: number): void {
    this.orderItems.update((l) => l.filter((_, idx) => idx !== i));
  }

  saveOrder(status: 'Order' | 'Quotation'): void {
    if (!this.canEdit()) return;
    this.orderTried = true;
    const err = firstError(this.orderErrors());
    if (err) { this.toast(err, 'danger'); return; }
    const win = this.openPending(status === 'Order' ? 'Order' : 'Quotation');
    this.savingOrder.set(true);
    this.svc.saveOrder({
      id: this.editingId() ?? undefined,
      ...upperText(this.orderForm),
      status,
      items: this.orderItems(),
    }).subscribe({
      next: (res) => {
        this.savingOrder.set(false);
        const d = res.data;
        this.toast(`${d.status} saved — ${d.order_no}`, 'success');
        this.resetOrderForm();
        this.loadOrders();
        this.loadDashboard();
        this.loadPdfInto(d.id, win);
      },
      error: (e: HttpErrorResponse) => {
        this.savingOrder.set(false);
        win?.close();
        this.showError(e, 'Could not save order.');
      },
    });
  }

  resetOrderForm(): void {
    this.orderForm = this.blankOrder();
    this.orderTried = false;
    this.orderItems.set([]);
    this.editingId.set(null);
    this.clearSelectedStock();
    this.loadNextOrderNo();
    this.cdr.markForCheck();
  }

  showOrder(o: MrOrder): void {
    this.svc.order(o.id).subscribe({
      next: (d) => this.viewOrder.set(d),
      error: (e: HttpErrorResponse) => this.showError(e, 'Could not load order.'),
    });
  }

  editOrder(o: MrOrder): void {
    if (!this.canEdit()) return;
    if (o.sale_id) { this.toast(`${o.order_no} is already sold (${o.sale_invoice_no}).`, 'danger'); return; }
    this.svc.order(o.id).subscribe({
      next: ({ order, items }) => {
        this.viewOrder.set(null);
        this.tab.set('orders');
        this.editingId.set(order.id);
        this.orderNo.set(order.order_no);
        this.orderForm = {
          order_date: order.order_date || this.today,
          customer_id: order.customer_id,
          customer_name: order.customer_name,
          customer_contact: order.customer_contact ?? '',
          customer_address: order.customer_address ?? '',
          customer_dl_no: order.customer_dl_no ?? '',
          order_type: order.order_type || 'Wholesaler',
          expected_delivery_date: order.expected_delivery_date ?? '',
          delivery_type: order.delivery_type || 'Regular',
          remarks: order.remarks ?? '',
        };
        this.orderItems.set((items ?? []).map((it) => ({
          ...it,
          qty: Number(it.qty) || 0,
          rate: Number(it.rate) || 0,
          mrp: Number(it.mrp) || 0,
          amount: Number(it.amount) || 0,
          gst: Number(it.gst) || 0,
          stock_qty: it.stock_qty != null ? Number(it.stock_qty) : null,
          previous_rate: 0,
        })));
        this.clearSelectedStock();
        this.toast(`Editing ${order.order_no} — update and save.`, 'info');
        this.cdr.markForCheck();
        window.scrollTo({ top: 0, behavior: 'smooth' });
      },
      error: (e: HttpErrorResponse) => this.showError(e, 'Could not load order.'),
    });
  }

  deleteOrder(o: MrOrder): void {
    if (!this.canDelete()) return;
    if (!confirm(`Delete ${o.status} ${o.order_no}?`)) return;
    this.svc.deleteOrder(o.id).subscribe({
      next: () => {
        this.toast(`Deleted ${o.order_no}`, 'success');
        if (this.editingId() === o.id) this.resetOrderForm();
        if (this.viewOrder()?.order.id === o.id) this.viewOrder.set(null);
        this.loadOrders();
        this.loadDashboard();
      },
      error: (e: HttpErrorResponse) => this.showError(e, 'Delete failed.'),
    });
  }

  printOrder(o: MrOrder): void {
    const win = this.openPending(o.is_quotation ? 'Quotation' : 'Order');
    this.loadPdfInto(o.id, win);
  }

  canDoSale(o: MrOrder | null | undefined): boolean {
    if (!o || !this.canSell()) return false;
    if (o.sale_id || o.sale_invoice_no) return false;
    const st = (o.status || '').toLowerCase();
    return st !== 'sold' && st !== 'converted';
  }

  /** Open Sales → New in wholesale mode with this order's customer and lines pre-filled. */
  doSale(o: MrOrder): void {
    if (!this.canDoSale(o)) return;
    this.svc.order(o.id).subscribe({
      next: ({ order, items }) => {
        const lines = (items ?? [])
          .filter((it) => Number(it.product_binding_id) > 0 && Number(it.batch_id) > 0 && Number(it.qty) > 0)
          .map((it) => ({
            binding_id: Number(it.product_binding_id),
            batch_id: Number(it.batch_id),
            qty: Number(it.qty),
            rate: Number(it.rate) || 0,
            product_name: it.product_name,
            gst: Number(it.gst) || 0,
            hsn: it.hsn ?? null,
            pack_size: it.pack_size ?? null,
            m_name: it.m_name ?? null,
          }));
        const payload: MrDoSalePayload = {
          order_id: order.id,
          order_no: order.order_no,
          customer_id: order.customer_id,
          customer_name: order.customer_name,
          customer_contact: order.customer_contact ?? '',
          customer_address: order.customer_address ?? '',
          lines,
        };
        try {
          sessionStorage.setItem('mr_do_sale', JSON.stringify(payload));
        } catch {
          /* ignore */
        }
        this.viewOrder.set(null);
        void this.router.navigate(['/app/sales/new']);
      },
      error: (e: HttpErrorResponse) => this.showError(e, 'Could not load order.'),
    });
  }

  statusClass(o: MrOrder): string {
    if (o.sale_id || o.status === 'Sold') return 'text-bg-success';
    if (o.status === 'Order') return 'text-bg-primary';
    return 'text-bg-warning';
  }

  statusLabel(o: MrOrder): string {
    return o.sale_invoice_no ? `Sold · ${o.sale_invoice_no}` : o.status;
  }

  private openPending(kind: string): Window | null {
    const win = window.open('', '_blank');
    if (win) {
      win.document.title = `MR ${kind}`;
      win.document.body.innerHTML =
        `<p style="font-family:Segoe UI,Arial,sans-serif;color:#475569;padding:24px">Preparing ${kind} receipt…</p>`;
    }
    return win;
  }

  private loadPdfInto(id: number, win: Window | null): void {
    this.pdfBusy.set(id);
    this.svc.orderPdf(id).subscribe({
      next: (blob) => {
        this.pdfBusy.set(null);
        const url = URL.createObjectURL(new Blob([blob], { type: 'application/pdf' }));
        if (win && !win.closed) win.location.href = url;
        else window.open(url, '_blank');
        setTimeout(() => URL.revokeObjectURL(url), 60_000);
      },
      error: () => {
        this.pdfBusy.set(null);
        win?.close();
        this.toast('Could not open the order PDF.', 'danger');
      },
    });
  }

  // =================================================================== Stock follow-up

  loadStockList(): void {
    this.stockListLoading.set(true);
    this.svc.stock(this.stockQuery.trim()).subscribe({
      next: (rows) => { this.stockList.set(rows ?? []); this.stockListLoading.set(false); },
      error: () => this.stockListLoading.set(false),
    });
  }

  loadStockFollowups(): void {
    this.svc.stockFollowups(this.fromDate, this.toDate).subscribe({
      next: (rows) => this.stockFollowups.set(rows ?? []),
      error: (e: HttpErrorResponse) => this.showError(e, 'Could not load stock follow-ups.'),
    });
  }

  pickStockFuProduct(s: MrStockRow): void {
    this.stockFuProduct.set(`${s.p_name} · B: ${s.batch_no}`);
    this.stockFuForm.product_name = s.p_name;
    this.stockFuForm.product_binding_id = s.binding_id;
    this.stockFuForm.available_qty = s.qty;
  }

  saveStockFollowup(): void {
    if (!this.canEdit()) return;
    this.stockFuTried = true;
    const err = firstError(this.stockFuErrors());
    if (err) { this.toast(err, 'danger'); return; }
    this.savingStockFu.set(true);
    this.svc.saveStockFollowup(upperText(this.stockFuForm)).subscribe({
      next: () => {
        this.savingStockFu.set(false);
        this.toast('Stock follow-up saved.', 'success');
        this.stockFuForm = { ...this.stockFuForm, location_name: '', product_name: '', product_binding_id: null, available_qty: '', near_expiry_qty: '' };
        this.stockFuProduct.set('');
        this.stockFuTried = false;
        this.loadStockFollowups();
        this.cdr.markForCheck();
      },
      error: (e: HttpErrorResponse) => { this.savingStockFu.set(false); this.showError(e, 'Could not save follow-up.'); },
    });
  }

  // =================================================================== Monitor

  loadMonitor(): void {
    this.monitorLoading.set(true);
    this.svc.monitor(this.fromDate, this.toDate).subscribe({
      next: (m) => { this.monitor.set(m); this.monitorLoading.set(false); },
      error: (e: HttpErrorResponse) => { this.monitorLoading.set(false); this.showError(e, 'Could not load sales monitor.'); },
    });
  }

  isExpired(d: string | null | undefined): boolean {
    return !!d && d < this.today;
  }

  // =================================================================== Daily report

  loadDailyReports(): void {
    this.svc.dailyReports(this.fromDate, this.toDate).subscribe({
      next: (rows) => this.dailyReports.set(rows ?? []),
      error: (e: HttpErrorResponse) => this.showError(e, 'Could not load daily reports.'),
    });
  }

  saveDailyReport(): void {
    if (!this.canEdit()) return;
    this.reportTried = true;
    const err = firstError(this.reportErrors());
    if (err) { this.toast(err, 'danger'); return; }
    this.savingReport.set(true);
    this.svc.saveDailyReport(upperText(this.reportForm)).subscribe({
      next: () => {
        this.savingReport.set(false);
        this.toast('Daily report submitted.', 'success');
        this.loadDailyReports();
      },
      error: (e: HttpErrorResponse) => { this.savingReport.set(false); this.showError(e, 'Could not submit report.'); },
    });
  }

  // =================================================================== Outstanding

  loadOutstanding(): void {
    this.osLoading.set(true);
    this.svc.outstanding().subscribe({
      next: (r) => {
        this.outstanding.set(r.outstanding ?? []);
        this.osFollowups.set(r.followups ?? []);
        this.osLoading.set(false);
      },
      error: (e: HttpErrorResponse) => { this.osLoading.set(false); this.showError(e, 'Could not load outstanding.'); },
    });
  }

  pickOutstanding(row: MrOutstandingRow): void {
    this.osForm.customer_id = Number(row.customer_id) || null;
    this.osForm.customer_name = row.customer_name;
    this.osForm.outstanding_amt = Number(row.outstanding_amt) || 0;
  }

  onOsCustomerPicked(c: MrCustomer | null): void {
    this.osForm.customer_id = c?.id ?? null;
    this.osForm.customer_name = c?.name ?? '';
    if (c) {
      const due = this.outstanding().find((o) => Number(o.customer_id) === c.id || o.customer_name === c.name);
      if (due) this.osForm.outstanding_amt = Number(due.outstanding_amt) || 0;
    }
  }

  saveOsFollowup(): void {
    if (!this.canEdit()) return;
    this.osTried = true;
    const err = firstError(this.osErrors());
    if (err) { this.toast(err, 'danger'); return; }
    this.savingOs.set(true);
    this.svc.saveOsFollowup(upperText(this.osForm)).subscribe({
      next: () => {
        this.savingOs.set(false);
        this.toast('Outstanding follow-up saved.', 'success');
        this.osForm = { ...this.osForm, followup_note: '', promised_date: '' };
        this.osTried = false;
        this.loadOutstanding();
        this.loadDashboard();
        this.cdr.markForCheck();
      },
      error: (e: HttpErrorResponse) => { this.savingOs.set(false); this.showError(e, 'Could not save follow-up.'); },
    });
  }

  // =================================================================== Validation

  visitErrors(): Errors {
    const f = this.visitForm;
    const t = this.visitTried;
    return {
      visit_date: this.dateError(f.visit_date, 'Visit date', t, true),
      doctor_name: textError(f.doctor_name, 'Doctor name', 'person', t, 160),
      clinic_name: textError(f.clinic_name, 'Clinic / Hospital', 'org', false, 160),
      specialty: textError(f.specialty, 'Specialty', 'place', false, 120),
      area_name: textError(f.area_name, 'Area', 'org', false, 120),
      contact_no: phoneError(f.contact_no),
      next_visit_date: f.next_visit_date && f.visit_date && f.next_visit_date < f.visit_date
        ? 'Next visit must be on or after the visit date.' : null,
      indications: maxLenError(f.indications, 'Indications', 1000),
      dosage_notes: maxLenError(f.dosage_notes, 'Dosage / clinical info', 1000),
      availability_notes: maxLenError(f.availability_notes, 'Availability', 255),
      competitor_info: maxLenError(f.competitor_info, 'Competitor info', 255),
      remarks: maxLenError(f.remarks, 'Remarks', 1000),
    };
  }

  orderErrors(): Errors {
    const f = this.orderForm;
    const t = this.orderTried;
    return {
      order_date: this.dateError(f.order_date, 'Order date', t, true),
      customer: t && !f.customer_id ? 'Select a wholesale customer.' : null,
      customer_contact: phoneError(f.customer_contact),
      customer_dl_no: this.dlError(f.customer_dl_no),
      customer_address: addressError(f.customer_address),
      expected_delivery_date: f.expected_delivery_date && f.order_date && f.expected_delivery_date < f.order_date
        ? 'Expected delivery cannot be before the order date.' : null,
      items: t && !this.orderItems().length ? 'Add at least one product line.' : null,
      remarks: maxLenError(f.remarks, 'Remarks', 500),
    };
  }

  lineErrors(): Errors {
    const s = this.selectedStock();
    if (!s) return { qty: null, rate: null };
    const qty = this.lineQty();
    const rate = this.lineRate();
    let qtyErr = numberError(qty, 'Qty', { required: true, integer: true, min: 1 });
    if (!qtyErr && qty > s.qty) qtyErr = `Only ${s.qty} in stock for batch ${s.batch_no}.`;
    let rateErr = numberError(rate, 'Sale rate', { required: true, max: 9999999 });
    if (!rateErr && !(Number(rate) > 0)) rateErr = 'Sale rate must be greater than 0.';
    if (!rateErr && Number(s.mrp) > 0 && Number(rate) > Number(s.mrp)) rateErr = `Sale rate cannot exceed MRP (${s.mrp}).`;
    return { qty: qtyErr, rate: rateErr };
  }

  stockFuErrors(): Errors {
    const f = this.stockFuForm;
    const t = this.stockFuTried;
    const avail = numberError(f.available_qty, 'Available qty', { integer: true, max: 9999999 });
    let near = numberError(f.near_expiry_qty, 'Near-expiry qty', { integer: true, max: 9999999 });
    if (!near && !avail && f.near_expiry_qty !== '' && f.near_expiry_qty != null
      && f.available_qty !== '' && f.available_qty != null && Number(f.near_expiry_qty) > Number(f.available_qty)) {
      near = 'Near-expiry qty cannot exceed available qty.';
    }
    return {
      followup_date: this.dateError(f.followup_date, 'Date', t, true),
      location_name: textError(f.location_name, 'Location name', 'org', t, 160),
      product_name: t && !f.product_name.trim() ? 'Select a product from current stock.' : null,
      available_qty: avail,
      near_expiry_qty: near,
      scheme_notes: maxLenError(f.scheme_notes, 'Scheme notes', 255),
      remarks: maxLenError(f.remarks, 'Remarks', 1000),
    };
  }

  reportErrors(): Errors {
    const f = this.reportForm;
    const count = (v: unknown, label: string) => numberError(v, label, { integer: true, max: 999 });
    return {
      report_date: this.dateError(f.report_date, 'Report date', this.reportTried, true),
      calls_planned: count(f.calls_planned, 'Calls planned'),
      calls_done: count(f.calls_done, 'Calls done'),
      doctor_visits: count(f.doctor_visits, 'Doctor visits'),
      orders_taken: count(f.orders_taken, 'Orders taken'),
      order_value: numberError(f.order_value, 'Order value', { max: 99999999 }),
      stock_checks: count(f.stock_checks, 'Stock checks'),
      sales_summary: maxLenError(f.sales_summary, 'Sales summary', 1000),
      competitor_info: maxLenError(f.competitor_info, 'Competitor info', 1000),
      outstanding_notes: maxLenError(f.outstanding_notes, 'Outstanding notes', 1000),
      remarks: maxLenError(f.remarks, 'Remarks', 1000),
    };
  }

  osErrors(): Errors {
    const f = this.osForm;
    const t = this.osTried;
    let promised: string | null = null;
    if (f.status === 'Promised' && !f.promised_date && t) promised = 'Promised date is required when status is Promised.';
    else if (f.promised_date && f.followup_date && f.promised_date < f.followup_date) {
      promised = 'Promised date cannot be before the follow-up date.';
    }
    return {
      followup_date: this.dateError(f.followup_date, 'Date', t, true),
      customer: textError(f.customer_name, 'Customer', 'org', t, 160),
      outstanding_amt: numberError(f.outstanding_amt, 'Amount', { max: 99999999 }),
      promised_date: promised,
      followup_note: maxLenError(f.followup_note, 'Note', 1000),
    };
  }

  customerErrors(): Errors {
    const f = this.customerForm;
    const t = this.customerTried;
    const phone = formatPhone(f.phone);
    let phoneErr = phoneError(phone, t);
    if (!phoneErr && phone && this.customers().some((c) => (c.phone ?? '').replace(/\D+/g, '') === phone)) {
      phoneErr = 'Contact already exists.';
    }
    return {
      name: textError(f.name, 'Name', 'org', t, 150),
      phone: phoneErr,
      email: emailError(f.email),
      address: addressError(f.address),
      state: textError(f.state, 'State', 'place', false, 80),
      city: textError(f.city, 'City', 'place', false, 80),
      area: textError(f.area, 'Area', 'org', false, 120),
      gstin: gstinError(f.gstin),
      credit_days: numberError(f.credit_days, 'Credit days', { integer: true, max: 365 }),
      dl_no: this.dlError(f.dl_no),
    };
  }

  /** Strip non-digits as the user types (max 10) and keep the input in sync. */
  cleanPhone(ev: Event): string {
    const el = ev.target as HTMLInputElement;
    const v = formatPhone(el.value);
    if (el.value !== v) el.value = v;
    return v;
  }

  cleanGstin(ev: Event): string {
    const el = ev.target as HTMLInputElement;
    const v = formatGstin(el.value);
    if (el.value !== v) el.value = v;
    return v;
  }

  private dateError(value: string | null | undefined, label: string, required: boolean, noFuture: boolean): string | null {
    const v = (value ?? '').trim();
    if (!v) return required ? `${label} is required.` : null;
    if (!/^\d{4}-\d{2}-\d{2}$/.test(v)) return `${label} is not a valid date.`;
    if (noFuture && v > this.today) return `${label} cannot be in the future.`;
    return null;
  }

  private dlError(value: string | null | undefined): string | null {
    const v = (value ?? '').trim();
    if (!v) return null;
    if (v.length > 60) return 'DL No must be at most 60 characters.';
    if (!DL_RE.test(v)) return 'DL No can contain letters, digits, / , . - only.';
    return null;
  }

  // =================================================================== Helpers

  reloadRange(): void {
    switch (this.tab()) {
      case 'visits': this.loadVisits(); break;
      case 'orders': this.loadOrders(); break;
      case 'stock': this.loadStockFollowups(); break;
      case 'monitor': this.loadMonitor(); break;
      case 'reports': this.loadDailyReports(); break;
    }
  }

  private toast(text: string, type: 'success' | 'danger' | 'info'): void {
    this.flash.set({ text, type });
    clearTimeout(this.flashTimer);
    this.flashTimer = setTimeout(() => this.flash.set(null), type === 'danger' ? 7000 : 4500);
  }

  private showError(e: HttpErrorResponse, fallback: string): void {
    const errors = e.error?.errors as Record<string, string[] | string> | undefined;
    let first: string | null = null;
    if (errors) {
      const v = Object.values(errors)[0];
      first = Array.isArray(v) ? v[0] : typeof v === 'string' ? v : null;
    }
    this.toast(first ?? e.error?.message ?? fallback, 'danger');
  }

  private blankVisit(): MrVisit {
    return {
      visit_date: this.iso(new Date()), doctor_name: '', clinic_name: '', specialty: '', area_name: '', contact_no: '',
      products_discussed: '', indications: '', dosage_notes: '', clinical_info: '', availability_notes: '',
      competitor_info: '', next_visit_date: '', outcome: 'Promoted', remarks: '',
    };
  }

  private blankOrder(): OrderForm {
    return {
      order_date: this.iso(new Date()), customer_id: null, customer_name: '', customer_contact: '', customer_address: '',
      customer_dl_no: '', order_type: 'Wholesaler', expected_delivery_date: '', delivery_type: 'Regular', remarks: '',
    };
  }

  private blankStockFu(): MrStockFollowup {
    return {
      followup_date: this.iso(new Date()), location_type: 'Pharmacy', location_name: '', product_binding_id: null,
      product_name: '', available_qty: '', near_expiry_qty: '', stock_status: 'Available', scheme_notes: '', remarks: '',
    };
  }

  private blankReport(): MrDailyReport {
    return {
      report_date: this.iso(new Date()), calls_planned: 0, calls_done: 0, doctor_visits: 0, orders_taken: 0,
      order_value: 0, stock_checks: 0, sales_summary: '', competitor_info: '', outstanding_notes: '', remarks: '',
    };
  }

  private blankOs(): MrOsFollowup {
    return {
      followup_date: this.iso(new Date()), customer_id: null, customer_name: '', outstanding_amt: 0,
      followup_note: '', promised_date: '', status: 'Open',
    };
  }

  private blankCustomer(): CustomerForm {
    return { name: '', phone: '', email: '', address: '', state: '', city: '', area: '', gstin: '', credit_days: 20, dl_no: '' };
  }

  private iso(d: Date): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  }

  private monthStart(): string {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`;
  }
}
