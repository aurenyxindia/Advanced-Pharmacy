import { Component, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpErrorResponse } from '@angular/common/http';
import { Admission, AdmissionDetail, IpdService } from '../../core/services/ipd.service';
import {
  BatchOption,
  SaleProduct,
  SalesService,
} from '../../core/services/sales.service';
import { CrudService } from '../../core/services/crud.service';

interface IpdBillLine {
  binding_id: number;
  product_name: string;
  batch_id: number | null;
  batch_no: string;
  shelf: string;
  category: string;
  pack: string;
  mrp: number;
  gst_rate: number;
  qty: number;
  disc: number;
  order_date: string;
  cost: number;
}

@Component({
  selector: 'app-ipd',
  imports: [CommonModule, FormsModule],
  templateUrl: './ipd.html',
  styleUrl: './ipd.scss',
})
export class Ipd {
  private readonly ipd = inject(IpdService);
  private readonly salesSvc = inject(SalesService);
  private readonly crud = inject(CrudService);

  readonly activeTab = signal<'admissions' | 'billing'>('admissions');

  readonly allRows = signal<Admission[]>([]);
  readonly loading = signal(false);
  readonly filter = signal('admitted');

  readonly selected = signal<AdmissionDetail | null>(null);

  /** Table rows for current filter (from full admission list). */
  readonly rows = computed(() => {
    const f = this.filter();
    const all = this.allRows();
    if (!f) return all;
    return all.filter((r) => r.status === f);
  });

  /** IPD KPIs always from full list (not affected by Admitted/Discharged filter). */
  readonly kpis = computed(() => {
    const all = this.allRows();
    let admitted = 0;
    let billed = 0;
    let paid = 0;
    let outstanding = 0;
    for (const r of all) {
      if (r.status === 'admitted') {
        admitted++;
        outstanding += Number(r.balance) || 0;
      }
      billed += Number(r.billed) || 0;
      paid += Number(r.paid) || 0;
    }
    return {
      admitted,
      billed: Math.round(billed * 100) / 100,
      paid: Math.round(paid * 100) / 100,
      outstanding: Math.round(outstanding * 100) / 100,
    };
  });

  // admit modal
  readonly admitOpen = signal(false);
  readonly admitForm = signal({
    patient_name: '', contact_no: '', doctor_name: '', ward: '', bed_no: '', address: '',
  });
  readonly saving = signal(false);

  // payment modal
  readonly payOpen = signal(false);
  readonly payForm = signal({ amount: 0, mode: 'cash', note: '' });

  readonly toast = signal<string | null>(null);
  readonly error = signal<string | null>(null);

  // ---- IPD billing (retail-style form) ----
  readonly billAdmissionId = signal<number | null>(null);
  readonly billForm = signal({
    contact_no: '',
    patient_name: '',
    email: '',
    address: '',
    hospital_name: '',
    doctor_name: '',
    ward_type: '',
    ward_no: '',
    bed_no: '',
    relative_phone: '',
    relation: '',
    order_date: new Date().toISOString().slice(0, 10),
    paid_amt: 0,
  });
  readonly billLines = signal<IpdBillLine[]>([]);
  readonly billSaving = signal(false);
  readonly billError = signal<string | null>(null);
  readonly priorBalance = signal(0);

  readonly doctors = signal<{ id: number; name: string }[]>([]);
  readonly generics = signal<{ id: number; name: string }[]>([]);
  readonly selectedGeneric = signal<number | null>(null);

  /** Admitted IPD patients for the billing Patient Name dropdown. */
  readonly ipdPatients = signal<Admission[]>([]);
  readonly selectedPatientId = signal<number | null>(null);

  readonly products = signal<SaleProduct[]>([]);
  readonly productQuery = signal('');
  readonly showProductDrop = signal(false);
  readonly productsLoading = signal(false);

  readonly draft = signal({
    product: null as SaleProduct | null,
    batch: null as BatchOption | null,
    batches: [] as BatchOption[],
    qty: 1,
    disc: 0,
  });

  readonly wardTypes = [
    'General', 'Ladies', 'Private', 'Semi-Private', 'ICU', 'Pediatric', 'Maternity', 'Deluxe',
  ];
  readonly relations = [
    'Father', 'Mother', 'Son', 'Daughter', 'Brother', 'Sister', 'Uncle', 'Aunt',
    'Nephew', 'Niece', 'Grandfather', 'Grandmother', 'Grandson', 'Granddaughter',
    'Father-in-law', 'Mother-in-law', 'Son-in-law', 'Daughter-in-law', 'Cousin',
  ];

  readonly openCount = computed(() => this.kpis().admitted);

  readonly billTotals = computed(() => {
    let taxable = 0, disc = 0, gst = 0, net = 0, profit = 0;
    for (const l of this.billLines()) {
      const t = this.lineTaxable(l);
      const d = this.lineDiscAmt(l);
      const g = this.lineGst(l);
      const n = this.lineNet(l);
      taxable += t; disc += d; gst += g; net += n;
      profit += t - (l.cost * l.qty);
    }
    return {
      taxable: Math.round(taxable * 100) / 100,
      disc: Math.round(disc * 100) / 100,
      gst: Math.round(gst * 100) / 100,
      net: Math.round(net * 100) / 100,
      profit: Math.round(profit * 100) / 100,
    };
  });

  readonly balanceAmt = computed(() => {
    const due = this.priorBalance() + this.billTotals().net;
    const paid = Number(this.billForm().paid_amt) || 0;
    return Math.max(0, Math.round((due - paid) * 100) / 100);
  });

  readonly filteredProducts = computed(() => {
    const q = this.productQuery().trim().toLowerCase();
    const list = this.products();
    if (!q) return list.slice(0, 80);
    return list.filter((p) =>
      p.product_name.toLowerCase().includes(q)
      || (p.manufacturer_short || '').toLowerCase().includes(q)
      || (p.generic_name || '').toLowerCase().includes(q)
    ).slice(0, 80);
  });

  constructor() {
    this.load();
    this.loadIpdPatients();
    this.crud.list<{ id: number; name: string }>('doctors', { perPage: 500 }).subscribe((r) => this.doctors.set(r.data));
    this.crud.list<{ id: number; name: string }>('generics', { perPage: 2000 }).subscribe((r) => this.generics.set(r.data));
    this.loadProducts();
  }

  setTab(tab: 'admissions' | 'billing'): void {
    this.activeTab.set(tab);
    this.error.set(null);
    this.billError.set(null);
    if (tab === 'billing') this.loadIpdPatients();
  }

  load(): void {
    this.loading.set(true);
    // Load all admissions so KPIs stay accurate while the table filter changes.
    this.ipd.list().subscribe({
      next: (d) => {
        this.allRows.set(d);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  /** Always load currently admitted patients for the billing dropdown. */
  loadIpdPatients(): void {
    this.ipd.list('admitted').subscribe({
      next: (d) => this.ipdPatients.set(d),
      error: () => this.ipdPatients.set([]),
    });
  }

  setFilter(f: string): void { this.filter.set(f); }

  // ---- detail ----
  openDetail(id: number): void {
    this.ipd.get(id).subscribe((d) => this.selected.set(d));
  }
  closeDetail(): void { this.selected.set(null); }

  // ---- admit ----
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  setAdmit(field: string, value: any): void { this.admitForm.update((f) => ({ ...f, [field]: value })); }
  openAdmit(): void {
    this.admitForm.set({ patient_name: '', contact_no: '', doctor_name: '', ward: '', bed_no: '', address: '' });
    this.admitOpen.set(true);
  }
  admit(): void {
    if (!this.admitForm().patient_name.trim()) { this.error.set('Patient name is required.'); return; }
    this.saving.set(true);
    this.ipd.admit(this.admitForm()).subscribe({
      next: () => {
        this.saving.set(false);
        this.admitOpen.set(false);
        this.showToast('Patient admitted.');
        this.load();
        this.loadIpdPatients();
      },
      error: () => { this.saving.set(false); this.error.set('Could not admit patient.'); },
    });
  }

  // ---- payment ----
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  setPay(field: string, value: any): void { this.payForm.update((f) => ({ ...f, [field]: value })); }
  openPay(): void { this.payForm.set({ amount: 0, mode: 'cash', note: '' }); this.payOpen.set(true); }
  recordPayment(): void {
    const adm = this.selected();
    if (!adm || this.payForm().amount <= 0) return;
    this.ipd.payment(adm.id, this.payForm()).subscribe(() => {
      this.payOpen.set(false);
      this.showToast('Payment recorded.');
      this.openDetail(adm.id);
      this.load();
    });
  }

  /** Open retail-style IPD bill form, prefilled from admission. */
  addBill(adm: AdmissionDetail): void {
    this.closeDetail();
    this.resetBill();
    this.applyAdmissionToBill(adm);
    this.setTab('billing');
  }

  /** Fill billing form from an admitted IPD patient selection. */
  onPatientSelect(admissionId: number | string | null): void {
    const id = Number(admissionId) || 0;
    if (!id) {
      this.selectedPatientId.set(null);
      this.billAdmissionId.set(null);
      this.priorBalance.set(0);
      this.patchBill('patient_name', '');
      return;
    }
    this.selectedPatientId.set(id);
    const row = this.ipdPatients().find((p) => p.id === id);
    if (row) {
      this.billAdmissionId.set(row.id);
      this.priorBalance.set(Number(row.balance) || 0);
      this.billForm.update((f) => ({
        ...f,
        contact_no: row.contact_no || '',
        patient_name: row.patient_name || '',
        address: row.address || '',
        doctor_name: row.doctor_name || '',
        ward_no: row.ward || '',
        bed_no: row.bed_no || '',
      }));
    }
    // Refresh balance from full detail
    this.ipd.get(id).subscribe({
      next: (d) => this.applyAdmissionToBill(d),
      error: () => { /* keep list values */ },
    });
  }

  private applyAdmissionToBill(adm: Admission | AdmissionDetail): void {
    this.selectedPatientId.set(adm.id);
    this.billAdmissionId.set(adm.id);
    const balance = 'totals' in adm && adm.totals
      ? Number(adm.totals.balance) || 0
      : Number(adm.balance) || 0;
    this.priorBalance.set(balance);
    this.billForm.update((f) => ({
      ...f,
      contact_no: adm.contact_no || '',
      patient_name: adm.patient_name || '',
      address: adm.address || '',
      doctor_name: adm.doctor_name || '',
      ward_no: adm.ward || '',
      bed_no: adm.bed_no || '',
    }));
  }

  discharge(adm: AdmissionDetail): void {
    if (!confirm(`Discharge ${adm.patient_name}? Final settlement will be locked.`)) return;
    this.ipd.discharge(adm.id).subscribe((res) => {
      this.showToast(`Discharged. Balance due: ₹${res.data.balance.toFixed(2)}`);
      this.openDetail(adm.id);
      this.load();
    });
  }

  // ---- billing form helpers ----
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  patchBill(field: string, value: any): void { this.billForm.update((f) => ({ ...f, [field]: value })); }

  resetBill(): void {
    this.billAdmissionId.set(null);
    this.selectedPatientId.set(null);
    this.priorBalance.set(0);
    this.billLines.set([]);
    this.billError.set(null);
    this.selectedGeneric.set(null);
    this.productQuery.set('');
    this.draft.set({ product: null, batch: null, batches: [], qty: 1, disc: 0 });
    this.billForm.set({
      contact_no: '', patient_name: '', email: '', address: '',
      hospital_name: '', doctor_name: '', ward_type: '', ward_no: '', bed_no: '',
      relative_phone: '', relation: '',
      order_date: new Date().toISOString().slice(0, 10),
      paid_amt: 0,
    });
    this.loadProducts();
    this.loadIpdPatients();
  }

  onContactLookup(phone: string): void {
    this.patchBill('contact_no', phone);
    const p = String(phone || '').replace(/\D/g, '');
    if (p.length < 10) return;
    this.salesSvc.lookupCustomer(p).subscribe((c) => {
      if (!c) return;
      this.billForm.update((f) => ({
        ...f,
        patient_name: c.name || f.patient_name,
        email: c.email || f.email,
        address: c.address || f.address,
      }));
    });
  }

  loadProducts(genericId?: number | null): void {
    this.productsLoading.set(true);
    this.salesSvc.searchProducts('', genericId ?? undefined).subscribe({
      next: (list) => { this.products.set(list); this.productsLoading.set(false); },
      error: () => this.productsLoading.set(false),
    });
  }

  onGenericFilter(id: number | string | null): void {
    const gid = id ? Number(id) : null;
    this.selectedGeneric.set(gid && gid > 0 ? gid : null);
    this.loadProducts(this.selectedGeneric());
  }

  clearGenericFilter(): void {
    this.selectedGeneric.set(null);
    this.loadProducts();
  }

  selectProduct(p: SaleProduct): void {
    this.showProductDrop.set(false);
    this.productQuery.set(p.product_name);
    this.draft.update((d) => ({ ...d, product: p, batch: null, batches: [], qty: 1, disc: 0 }));
    this.salesSvc.batches(p.binding_id).subscribe((batches) => {
      const first = batches.find((b) => b.quantity > 0) || batches[0] || null;
      this.draft.update((d) => ({
        ...d,
        batches,
        batch: first,
        qty: 1,
      }));
    });
  }

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  patchDraft(field: string, value: any): void { this.draft.update((d) => ({ ...d, [field]: value })); }

  onBatchSelect(batchId: number | string): void {
    const id = Number(batchId);
    const b = this.draft().batches.find((x) => x.batch_id === id) || null;
    this.draft.update((d) => ({ ...d, batch: b }));
  }

  draftPreview(): { taxable: number; disc: number; gst: number; net: number } {
    const d = this.draft();
    const mrp = Number(d.batch?.unit_mrp ?? d.batch?.mrp ?? d.product?.mrp ?? 0);
    const gst = Number(d.product?.gst_rate ?? 0);
    const qty = Number(d.qty) || 0;
    const disc = Number(d.disc) || 0;
    const gross = mrp * qty;
    const discAmt = gross * (disc / 100);
    const net = gross - discAmt;
    const taxable = gst > 0 ? net / (1 + gst / 100) : net;
    return {
      taxable: Math.round(taxable * 100) / 100,
      disc: Math.round(discAmt * 100) / 100,
      gst: Math.round((net - taxable) * 100) / 100,
      net: Math.round(net * 100) / 100,
    };
  }

  addProductLine(): void {
    const d = this.draft();
    const p = d.product;
    const b = d.batch;
    if (!p) { this.billError.set('Select a product.'); return; }
    if (!b) { this.billError.set('Select a batch.'); return; }
    const qty = Number(d.qty) || 0;
    if (qty <= 0) { this.billError.set('Enter quantity.'); return; }
    if (qty > b.quantity) { this.billError.set(`Only ${b.quantity} units available in batch.`); return; }

    const line: IpdBillLine = {
      binding_id: p.binding_id,
      product_name: p.product_name,
      batch_id: b.batch_id,
      batch_no: b.batch_no,
      shelf: b.shelf_location || p.shelf_location || '',
      category: p.schedule_category || '',
      pack: p.pack_size || '',
      mrp: Number(b.unit_mrp ?? b.mrp ?? p.mrp ?? 0),
      gst_rate: Number(p.gst_rate) || 0,
      qty,
      disc: Number(d.disc) || 0,
      order_date: this.billForm().order_date,
      cost: Number(b.purchase_rate) || 0,
    };
    this.billLines.update((ls) => [...ls, line]);
    this.billError.set(null);
    this.productQuery.set('');
    this.draft.set({ product: null, batch: null, batches: [], qty: 1, disc: 0 });
  }

  removeLine(i: number): void {
    this.billLines.update((ls) => ls.filter((_, idx) => idx !== i));
  }

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  patchLine(i: number, field: 'qty' | 'disc', value: any): void {
    this.billLines.update((ls) => ls.map((l, idx) => idx === i ? { ...l, [field]: Number(value) || 0 } : l));
  }

  lineTaxable(l: IpdBillLine): number {
    const net = this.lineNet(l);
    return l.gst_rate > 0 ? net / (1 + l.gst_rate / 100) : net;
  }
  lineDiscAmt(l: IpdBillLine): number { return (l.mrp * l.qty) * (l.disc / 100); }
  lineGst(l: IpdBillLine): number { return this.lineNet(l) - this.lineTaxable(l); }
  lineNet(l: IpdBillLine): number { return (l.mrp * l.qty) - this.lineDiscAmt(l); }
  lineProfit(l: IpdBillLine): number { return this.lineTaxable(l) - (l.cost * l.qty); }

  saveBill(): void {
    const f = this.billForm();
    if (!f.patient_name.trim()) { this.billError.set('Patient name is required.'); return; }
    if (!f.relative_phone.trim()) { this.billError.set('Relative phone is required.'); return; }
    if (this.billLines().length === 0) { this.billError.set('Add at least one product.'); return; }

    this.billSaving.set(true);
    this.billError.set(null);

    const ensureAdmission = (cb: (admissionId: number) => void): void => {
      const existing = this.billAdmissionId();
      if (existing) { cb(existing); return; }
      const wardLabel = [f.ward_type, f.ward_no].filter(Boolean).join(' / ');
      this.ipd.admit({
        patient_name: f.patient_name.trim(),
        contact_no: f.contact_no || null,
        address: f.address || null,
        doctor_name: f.doctor_name || null,
        ward: wardLabel || null,
        bed_no: f.bed_no || null,
      }).subscribe({
        next: (res) => {
          const id = res.data.id;
          this.billAdmissionId.set(id);
          cb(id);
        },
        error: (err: HttpErrorResponse) => {
          this.billSaving.set(false);
          this.billError.set(err.error?.message ?? 'Could not create admission.');
        },
      });
    };

    ensureAdmission((admissionId) => {
      const narration = [
        f.hospital_name ? `Hospital: ${f.hospital_name}` : '',
        f.ward_type ? `Ward Type: ${f.ward_type}` : '',
        f.ward_no ? `Ward No: ${f.ward_no}` : '',
        f.bed_no ? `Bed: ${f.bed_no}` : '',
        f.relation ? `Relation: ${f.relation}` : '',
        f.relative_phone ? `Relative Ph: ${f.relative_phone}` : '',
      ].filter(Boolean).join(' | ');

      this.salesSvc.create({
        sale_type: 'IPD',
        bill_mode: 'retail',
        customer_name: f.patient_name.trim(),
        patient_name: f.patient_name.trim(),
        contact_no: f.contact_no || undefined,
        email: f.email || undefined,
        address: f.address || undefined,
        doctor_name: f.doctor_name || undefined,
        narration: narration || undefined,
        payment_mode: 'credit',
        gst_inclusive: 1,
        ipd_admission_id: admissionId,
        invoice_date: f.order_date,
        items: this.billLines().map((l) => ({
          product_binding_id: l.binding_id,
          schedule_category: l.category,
          quantity: Math.round(l.qty),
          rate: Math.round(l.mrp * 100) / 100,
          discount_pct: Number(l.disc) || 0,
          batch_id: l.batch_id,
          unit_type: 'unit',
          pack: l.pack || null,
          shelf: l.shelf || null,
        })),
      }).subscribe({
        next: () => {
          const paid = Number(f.paid_amt) || 0;
          const finish = (): void => {
            this.billSaving.set(false);
            this.showToast('IPD bill saved.');
            this.resetBill();
            this.load();
            this.setTab('admissions');
            this.openDetail(admissionId);
          };
          if (paid > 0) {
            this.ipd.payment(admissionId, { amount: paid, mode: 'cash', note: 'IPD bill payment' }).subscribe({
              next: () => finish(),
              error: () => finish(),
            });
          } else {
            finish();
          }
        },
        error: (err: HttpErrorResponse) => {
          this.billSaving.set(false);
          const errors = err.error?.errors as Record<string, string[] | string> | undefined;
          let first: string | null = null;
          if (errors) {
            const val = Object.values(errors)[0];
            first = Array.isArray(val) ? val[0] : (typeof val === 'string' ? val : null);
          }
          this.billError.set(first ?? err.error?.message ?? 'Could not save IPD bill.');
        },
      });
    });
  }

  private showToast(msg: string): void { this.toast.set(msg); setTimeout(() => this.toast.set(null), 3000); }
}
