import { Component, ElementRef, computed, inject, signal, viewChild } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import {
  BatchOption,
  SaleProduct,
  SalesService,
  SaleResult,
  Substitute,
} from '../../core/services/sales.service';
import { CrudService } from '../../core/services/crud.service';
import { MetaService } from '../../core/services/meta.service';

type BillMode = 'retail' | 'wholesale';
type RateType = 'mrp' | 'ptr' | 'pts';

const PRESCRIPTION_SCHEDULES = ['H1', 'X', 'G', 'RESTRICTED'];
const COUNTERS = ['COUNTER 1', 'COUNTER 2', 'COUNTER 3', 'CASH COUNTER', 'IPD COUNTER'];

interface BillLine {
  binding_id: number;
  product_name: string;
  mfg: string | null;
  category: string;
  gst_rate: number;
  units_per_pack: number;
  allow_loose: boolean;
  pack: string | null;
  shelf: string | null;
  unit_type: 'unit' | 'pack';
  batch_id: number | null;
  batch_no: string | null;
  expiry: string | null;
  unit_mrp: number;     // per unit
  ptr: number;          // per pack
  pts: number;          // per pack
  rate: number;         // per chosen unit
  qty: number;
  disc: number;
  cost: number;         // per unit incl gst
  batches: BatchOption[];
}

interface Bill {
  mode: BillMode;
  rateType: RateType;
  billDate: string;
  saleType: string;
  paymentMode: string;
  customerId: number | null;
  contact: string;
  email: string;
  patientName: string;   // retail patient OR wholesale customer name
  address: string;
  doctor: string;
  narration: string;
  reminderDays: number | null;
  cdDiscPct: number;
  isInterstate: boolean;
  prescriptionId: number | null;
  prescriptionName: string | null;
  lines: BillLine[];
}

function emptyBill(mode: BillMode): Bill {
  return {
    mode,
    rateType: 'ptr',
    billDate: new Date().toISOString().slice(0, 10),
    saleType: 'Regular',
    paymentMode: 'Cash',
    customerId: null,
    contact: '',
    email: '',
    patientName: '',
    address: '',
    doctor: '',
    narration: '',
    reminderDays: null,
    cdDiscPct: 0,
    isInterstate: false,
    prescriptionId: null,
    prescriptionName: null,
    lines: [],
  };
}

@Component({
  selector: 'app-billing',
  imports: [CommonModule, FormsModule],
  templateUrl: './billing.html',
  styleUrl: './billing.scss',
})
export class Billing {
  private readonly salesSvc = inject(SalesService);
  private readonly crud = inject(CrudService);
  private readonly meta = inject(MetaService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly video = viewChild<ElementRef<HTMLVideoElement>>('video');

  readonly businessType = this.meta.businessType;
  readonly counters = signal<string[]>([...COUNTERS]);
  readonly activeCounter = signal('COUNTER 1');
  readonly bills = signal<Record<string, Bill>>(
    Object.fromEntries(COUNTERS.map((c) => [c, emptyBill('retail')])),
  );

  // IPD link (set when billing was opened from an admission)
  readonly ipdAdmissionId = signal<number | null>(null);
  readonly ipdPatient = signal<string>('');
  readonly bill = computed(() => this.bills()[this.activeCounter()]);
  readonly isWholesale = computed(() => this.bill().mode === 'wholesale');

  // search / add
  readonly searchTerm = signal('');
  readonly searchResults = signal<SaleProduct[]>([]);
  readonly highlight = signal(0);
  readonly searching = signal(false);
  private searchTimer?: ReturnType<typeof setTimeout>;
  readonly generics = signal<{ id: number; name: string }[]>([]);
  readonly selectedGeneric = signal<number | null>(null);
  readonly substituteMode = signal(false); // when true, search lists same-molecule alternatives
  readonly customers = signal<{ id: number; name: string; phone?: string; address?: string }[]>([]);

  // substitute lookup for an existing line
  readonly subOpen = signal(false);
  readonly subLoading = signal(false);
  readonly subs = signal<Substitute[]>([]);
  readonly subSourceName = signal('');
  private subReplaceIndex: number | null = null;

  readonly saving = signal(false);
  readonly error = signal<string | null>(null);
  readonly completed = signal<SaleResult | null>(null);

  // camera
  readonly cameraOpen = signal(false);
  private stream?: MediaStream;
  readonly uploadingPrescription = signal(false);

  readonly paymentModes = ['Cash', 'Card', 'UPI', 'Credit'];
  readonly saleTypes = ['Regular', 'Return'];
  readonly rateTypes: { value: RateType; label: string }[] = [
    { value: 'ptr', label: 'PTR' }, { value: 'pts', label: 'PTS' }, { value: 'mrp', label: 'MRP' },
  ];

  constructor() {
    this.meta.loadProfile().subscribe((p) => {
      // Wholesale-only client: default every counter to wholesale.
      if (p.business_type === 'wholesale') {
        this.bills.update((all) =>
          Object.fromEntries(Object.entries(all).map(([k, b]) => [k, { ...b, mode: 'wholesale' as BillMode }])),
        );
      }
    });
    this.crud.list<{ id: number; name: string }>('generics', { perPage: 500 }).subscribe((r) => this.generics.set(r.data));
    this.crud.list<{ id: number; name: string; phone?: string; address?: string }>('customers', { perPage: 500 })
      .subscribe((r) => this.customers.set(r.data));
    this.loadInitial();

    // If opened from an IPD admission, tag this bill to it.
    const admission = this.route.snapshot.queryParamMap.get('admission');
    if (admission) {
      this.ipdAdmissionId.set(Number(admission));
      const patient = this.route.snapshot.queryParamMap.get('patient') ?? '';
      this.ipdPatient.set(patient);
      this.ensureCounter('IPD COUNTER');
      this.activeCounter.set('IPD COUNTER');
      this.patchBill({ patientName: patient });
    }
  }

  // ---- dynamic counters ----
  private defaultMode(): BillMode {
    return this.businessType() === 'wholesale' ? 'wholesale' : 'retail';
  }
  private ensureCounter(name: string): void {
    if (!this.counters().includes(name)) {
      this.counters.update((cs) => [...cs, name]);
      this.bills.update((all) => ({ ...all, [name]: emptyBill(this.defaultMode()) }));
    }
  }
  addCounter(): void {
    const input = (prompt('New counter name:', 'COUNTER ' + (this.counters().length + 1)) || '').trim();
    if (!input) return;
    if (this.counters().includes(input)) { this.activeCounter.set(input); return; }
    this.counters.update((cs) => [...cs, input]);
    this.bills.update((all) => ({ ...all, [input]: emptyBill(this.defaultMode()) }));
    this.activeCounter.set(input);
  }
  removeCounter(name: string, event: Event): void {
    event.stopPropagation();
    if (this.counters().length <= 1) return;
    if (this.bills()[name]?.lines.length && !confirm(`Counter "${name}" has items. Remove and discard?`)) return;
    this.counters.update((cs) => cs.filter((c) => c !== name));
    this.bills.update((all) => { const copy = { ...all }; delete copy[name]; return copy; });
    if (this.activeCounter() === name) this.activeCounter.set(this.counters()[0]);
  }
  clearIpd(): void {
    this.ipdAdmissionId.set(null);
    this.ipdPatient.set('');
  }

  // ---- mode ----
  canSwitch(): boolean {
    return this.businessType() === 'both';
  }
  setMode(mode: BillMode): void {
    if (!this.canSwitch() && this.businessType() !== mode) return;
    const lines = this.bill().lines.map((l) => this.repriceLine(l, mode, this.bill().rateType));
    this.patchBill({ mode, lines });
  }
  setRateType(rt: RateType): void {
    const lines = this.bill().lines.map((l) => this.repriceLine(l, this.bill().mode, rt));
    this.patchBill({ rateType: rt, lines });
  }

  private repriceLine(l: BillLine, mode: BillMode, rt: RateType): BillLine {
    if (mode === 'wholesale') {
      const upp = Math.max(1, l.units_per_pack);
      const packRate = rt === 'ptr' ? l.ptr : rt === 'pts' ? l.pts : l.unit_mrp * upp;
      return { ...l, unit_type: 'pack', rate: Math.round(packRate * 100) / 100 };
    }
    return { ...l, unit_type: 'unit', rate: l.unit_mrp };
  }

  // ---- counter ----
  switchCounter(c: string): void {
    this.activeCounter.set(c);
    this.completed.set(null);
    this.error.set(null);
  }
  counterCount(c: string): number {
    return this.bills()[c].lines.length;
  }
  private patchBill(patch: Partial<Bill>): void {
    this.bills.update((all) => ({ ...all, [this.activeCounter()]: { ...all[this.activeCounter()], ...patch } }));
  }
  set<K extends keyof Bill>(field: K, value: Bill[K]): void {
    this.patchBill({ [field]: value } as Partial<Bill>);
  }

  onCustomerChange(id: number | null): void {
    const c = this.customers().find((x) => x.id === id);
    this.patchBill({ customerId: id, patientName: c?.name ?? '', contact: c?.phone ?? '', address: c?.address ?? '' });
  }

  // ---- search ----
  loadInitial(): void {
    this.salesSvc.searchProducts('').subscribe((r) => { this.searchResults.set(r); this.highlight.set(0); });
  }
  private runSearch(): void {
    this.searching.set(true);
    this.salesSvc.searchProducts(this.searchTerm(), this.selectedGeneric() ?? undefined).subscribe({
      next: (r) => { this.searchResults.set(r); this.highlight.set(0); this.searching.set(false); },
      error: () => this.searching.set(false),
    });
  }
  onSearch(value: string): void {
    this.searchTerm.set(value);
    clearTimeout(this.searchTimer);
    this.searchTimer = setTimeout(() => this.runSearch(), 180);
  }
  onGenericChange(id: number | null): void {
    this.selectedGeneric.set(id);
    this.substituteMode.set(id !== null);
    this.runSearch();
  }
  clearGeneric(): void {
    this.selectedGeneric.set(null);
    this.substituteMode.set(false);
    this.runSearch();
  }

  /** Keyboard navigation on the search box: Up/Down to move, Enter to add. */
  onSearchKey(event: KeyboardEvent): void {
    const n = this.searchResults().length;
    if (n === 0) return;
    if (event.key === 'ArrowDown') {
      this.highlight.update((i) => Math.min(i + 1, n - 1));
      event.preventDefault();
    } else if (event.key === 'ArrowUp') {
      this.highlight.update((i) => Math.max(i - 1, 0));
      event.preventDefault();
    } else if (event.key === 'Enter') {
      const p = this.searchResults()[this.highlight()];
      if (p) this.addProduct(p);
      event.preventDefault();
    } else if (event.key === 'Escape') {
      this.searchResults.set([]);
    }
  }

  // ---- lines ----
  addProduct(p: SaleProduct): void {
    const existing = this.bill().lines.findIndex((l) => l.binding_id === p.binding_id);
    if (existing >= 0) {
      this.updateLine(existing, { qty: this.bill().lines[existing].qty + 1 });
      return;
    }
    this.salesSvc.batches(p.binding_id).subscribe((batches) => {
      const b = batches[0];
      const upp = b?.units_per_pack ?? p.units_per_pack ?? 1;
      const unitMrp = b?.unit_mrp ?? Number(p.mrp) ?? 0;
      const baseLine: BillLine = {
        binding_id: p.binding_id,
        product_name: p.product_name,
        mfg: p.manufacturer_short ?? null,
        category: p.schedule_category,
        gst_rate: Number(p.gst_rate) || 0,
        units_per_pack: upp,
        allow_loose: (p.allow_loose ?? 1) === 1,
        pack: p.pack_size ?? null,
        shelf: b?.shelf_location ?? p.shelf_location ?? null,
        unit_type: 'unit',
        batch_id: b?.batch_id ?? null,
        batch_no: b?.batch_no ?? null,
        expiry: b?.expiry_date ?? null,
        unit_mrp: unitMrp,
        ptr: b?.ptr ?? 0,
        pts: b?.pts ?? 0,
        rate: unitMrp,
        qty: 1,
        disc: 0,
        cost: b?.purchase_rate ?? 0,
        batches,
      };
      const line = this.repriceLine(baseLine, this.bill().mode, this.bill().rateType);
      this.patchBill({ lines: [...this.bill().lines, line] });
    });
    // Clear the query for the next quick add but keep the result list usable.
    this.searchTerm.set('');
    this.searchResults.set([]);
    this.highlight.set(0);
  }

  // ---- substitute lookup for a line (same molecule alternatives) ----
  openSubstitutes(index: number): void {
    const l = this.bill().lines[index];
    this.subReplaceIndex = index;
    this.subSourceName.set(l.product_name);
    this.subOpen.set(true);
    this.subLoading.set(true);
    this.subs.set([]);
    this.salesSvc.substitutes(l.binding_id).subscribe({
      next: (s) => { this.subs.set(s); this.subLoading.set(false); },
      error: () => this.subLoading.set(false),
    });
  }

  /** Add a substitute as a new line, or replace the source line. */
  addSubstitute(sub: Substitute, replace: boolean): void {
    if (!sub.binding_id) return;
    const product: SaleProduct = {
      binding_id: sub.binding_id,
      product_name: sub.name,
      schedule_category: sub.schedule_category,
      gst_rate: Number(sub.default_gst_rate) || 0,
      master_product_id: sub.id,
      available_qty: sub.available_qty,
      mrp: null,
      generic_name: sub.generic_name,
    };
    if (replace && this.subReplaceIndex !== null) {
      this.removeLine(this.subReplaceIndex);
    }
    this.addProduct(product);
    this.subOpen.set(false);
  }

  updateLine(index: number, patch: Partial<BillLine>): void {
    this.patchBill({ lines: this.bill().lines.map((l, i) => (i === index ? { ...l, ...patch } : l)) });
  }
  onUnitTypeChange(index: number, type: 'unit' | 'pack'): void {
    const l = this.bill().lines[index];
    if (type === l.unit_type) return;
    const upp = Math.max(1, l.units_per_pack);
    const rate = type === 'pack' ? l.rate * upp : l.rate / upp;
    this.updateLine(index, { unit_type: type, rate: Math.round(rate * 100) / 100 });
  }
  selectBatch(index: number, batchId: number): void {
    const l = this.bill().lines[index];
    const b = l.batches.find((x) => x.batch_id === batchId);
    if (b) {
      const patched = { ...l, batch_id: b.batch_id, batch_no: b.batch_no, expiry: b.expiry_date,
        unit_mrp: b.unit_mrp, ptr: b.ptr, pts: b.pts, cost: b.purchase_rate, shelf: b.shelf_location };
      this.updateLine(index, this.repriceLine(patched, this.bill().mode, this.bill().rateType));
    }
  }
  removeLine(index: number): void {
    this.patchBill({ lines: this.bill().lines.filter((_, i) => i !== index) });
  }

  // ---- GST mode ----
  // Retail and wholesale-MRP rates are GST-INCLUSIVE (reverse-calc).
  // Wholesale PTR/PTS rates are GST-EXCLUSIVE (GST added forward).
  readonly gstInclusive = computed(() => {
    const b = this.bill();
    return !(b.mode === 'wholesale' && (b.rateType === 'ptr' || b.rateType === 'pts'));
  });

  // ---- maths ----
  lineBase(l: BillLine): number { return l.rate * l.qty * (1 - l.disc / 100); }
  lineTaxable(l: BillLine): number {
    return this.gstInclusive() ? this.lineBase(l) / (1 + l.gst_rate / 100) : this.lineBase(l);
  }
  lineGstAmt(l: BillLine): number {
    return this.gstInclusive() ? this.lineBase(l) - this.lineTaxable(l) : this.lineTaxable(l) * (l.gst_rate / 100);
  }
  lineSell(l: BillLine): number {
    return this.gstInclusive() ? this.lineBase(l) : this.lineTaxable(l) + this.lineGstAmt(l);
  }
  lineDiscountAmt(l: BillLine): number { return l.rate * l.qty * (l.disc / 100); }
  lineProfit(l: BillLine): number {
    // l.cost is the Net Rate (cost per unit, excl GST). For a pack it scales by units/pack.
    // Profit = (Sale rate - discount - tax) - Net Rate
    const effCost = l.unit_type === 'pack' ? l.cost * Math.max(1, l.units_per_pack) : l.cost;
    return this.lineTaxable(l) - effCost * l.qty;
  }

  readonly itemsSell = computed(() => this.bill().lines.reduce((s, l) => s + this.lineSell(l), 0));
  readonly gstTotal = computed(() => this.bill().lines.reduce((s, l) => s + this.lineGstAmt(l), 0));
  readonly profitTotal = computed(() => this.bill().lines.reduce((s, l) => s + this.lineProfit(l), 0));
  readonly cdAmount = computed(() => this.itemsSell() * (this.bill().cdDiscPct / 100));
  readonly netTotal = computed(() => Math.max(0, this.itemsSell() - this.cdAmount()));
  readonly cgst = computed(() => (this.bill().isInterstate ? 0 : this.gstTotal() / 2));
  readonly igst = computed(() => (this.bill().isInterstate ? this.gstTotal() : 0));

  readonly prescriptionRequired = computed(() =>
    this.bill().lines.some((l) => PRESCRIPTION_SCHEDULES.includes(l.category)),
  );

  // ---- prescription / camera ----
  onPrescriptionFile(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;
    this.uploadingPrescription.set(true);
    this.salesSvc.uploadPrescription(file, 'upload', this.bill().doctor).subscribe({
      next: (res) => { this.patchBill({ prescriptionId: res.data.id, prescriptionName: file.name }); this.uploadingPrescription.set(false); },
      error: () => this.uploadingPrescription.set(false),
    });
    input.value = '';
  }
  async openCamera(): Promise<void> {
    this.cameraOpen.set(true);
    try {
      this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
      const v = this.video()?.nativeElement;
      if (v) { v.srcObject = this.stream; await v.play(); }
    } catch { this.error.set('Unable to access camera.'); this.cameraOpen.set(false); }
  }
  capture(): void {
    const v = this.video()?.nativeElement;
    if (!v) return;
    const canvas = document.createElement('canvas');
    canvas.width = v.videoWidth; canvas.height = v.videoHeight;
    canvas.getContext('2d')!.drawImage(v, 0, 0);
    canvas.toBlob((blob) => {
      if (!blob) return;
      this.uploadingPrescription.set(true);
      this.salesSvc.uploadPrescription(blob, 'camera', this.bill().doctor).subscribe({
        next: (res) => { this.patchBill({ prescriptionId: res.data.id, prescriptionName: 'Camera capture' }); this.uploadingPrescription.set(false); this.closeCamera(); },
        error: () => this.uploadingPrescription.set(false),
      });
    }, 'image/png');
  }
  closeCamera(): void {
    this.stream?.getTracks().forEach((t) => t.stop());
    this.stream = undefined;
    this.cameraOpen.set(false);
  }

  // ---- save ----
  save(printAfter = false): void {
    const b = this.bill();
    if (b.lines.length === 0) { this.error.set('Add at least one product.'); return; }
    if (this.prescriptionRequired() && !b.prescriptionId) {
      this.error.set('Prescription is mandatory for the scheduled drugs in this bill.');
      return;
    }
    this.saving.set(true);
    this.error.set(null);

    this.salesSvc.create({
      counter: this.activeCounter(),
      sale_type: b.saleType,
      bill_mode: b.mode,
      rate_type: b.mode === 'wholesale' ? b.rateType : undefined,
      customer_id: b.customerId,
      customer_name: b.patientName || 'Walk-in',
      patient_name: b.mode === 'retail' ? b.patientName : b.patientName,
      contact_no: b.contact,
      email: b.email,
      address: b.address,
      narration: b.narration,
      reminder_days: b.mode === 'retail' ? b.reminderDays : null,
      doctor_name: b.doctor,
      payment_mode: b.paymentMode.toLowerCase(),
      cd_disc_pct: b.mode === 'wholesale' ? b.cdDiscPct : 0,
      is_interstate: b.isInterstate ? 1 : 0,
      gst_inclusive: this.gstInclusive() ? 1 : 0,
      ipd_admission_id: this.ipdAdmissionId(),
      prescription_id: b.prescriptionId,
      items: b.lines.map((l) => {
        const upp = Math.max(1, l.units_per_pack);
        const baseQty = l.unit_type === 'pack' ? l.qty * upp : l.qty;
        const baseRate = l.unit_type === 'pack' ? l.rate / upp : l.rate;
        return {
          product_binding_id: l.binding_id,
          schedule_category: l.category,
          quantity: baseQty,
          rate: Math.round(baseRate * 100) / 100,
          discount_pct: l.disc,
          batch_id: l.batch_id,
          unit_type: l.unit_type,
          pack: l.pack,
          shelf: l.shelf,
        };
      }),
    }).subscribe({
      next: (res) => {
        this.saving.set(false);
        this.completed.set(res.data);
        const mode = b.mode;
        this.bills.update((all) => ({ ...all, [this.activeCounter()]: emptyBill(mode) }));
        this.clearIpd();
        if (printAfter) this.printInvoice(res.data.id);
      },
      error: (err: HttpErrorResponse) => {
        this.saving.set(false);
        const errors = err.error?.errors;
        const first = errors ? (Object.values(errors)[0] as string[])?.[0] : null;
        this.error.set(first ?? err.error?.message ?? 'Could not complete the sale.');
      },
    });
  }

  printInvoice(id?: number): void {
    const saleId = id ?? this.completed()?.id;
    if (!saleId) return;
    this.salesSvc.invoiceBlob(saleId).subscribe((blob) => window.open(URL.createObjectURL(blob), '_blank'));
  }
  newBill(): void { this.completed.set(null); this.error.set(null); }
  viewSales(): void { this.router.navigate(['/app/sales']); }
}
