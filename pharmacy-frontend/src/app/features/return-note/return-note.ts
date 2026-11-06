import { Component, HostListener, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { MetaService } from '../../core/services/meta.service';
import { UserRightsService } from '../../core/services/user-rights.service';
import {
  ReturnBatchOpt,
  ReturnNote,
  ReturnNoteLine,
  ReturnNoteService,
  ReturnSupplierOpt,
} from '../../core/services/return-note.service';
import { ReturnTabsComponent } from '../returns/return-tabs';

@Component({
  selector: 'app-return-note',
  imports: [CommonModule, FormsModule, ReturnTabsComponent],
  templateUrl: './return-note.html',
  styleUrl: './return-note.scss',
})
export class ReturnNotePage implements OnInit {
  private readonly svc = inject(ReturnNoteService);
  private readonly meta = inject(MetaService);
  private readonly rights = inject(UserRightsService);

  canEditReturn(): boolean {
    return this.rights.canEdit('RETURN_NOTE');
  }

  readonly mode = signal<'list' | 'create' | 'view' | 'edit'>('list');
  readonly loading = signal(false);
  readonly saving = signal(false);
  readonly error = signal<string | null>(null);
  readonly toast = signal<string | null>(null);

  readonly notes = signal<ReturnNote[]>([]);
  readonly search = signal('');
  readonly selected = signal<ReturnNote | null>(null);

  readonly productOptions = signal<{ value: string; label: string }[]>([]);
  readonly productQuery = signal('');
  readonly showProductMenu = signal(false);
  readonly batches = signal<ReturnBatchOpt[]>([]);
  readonly suppliers = signal<ReturnSupplierOpt[]>([]);
  readonly loadingBatches = signal(false);
  readonly loadingSuppliers = signal(false);

  p_name = '';
  batch_no = '';
  batch_id = 0;
  product_binding_id = 0;
  distributor_name = '';
  stock_qty = 0;
  net_rate = 0;
  gst_per = 0;
  exp_date: string | null = null;
  invoice_no = '';
  invoice_date: string | null = null;
  return_qty = 0;
  reason = 'Expiry';
  hsn = '';
  pack_size = '';
  po_no = '';
  purchase_id = 0;
  d_cont = '';
  d_mail = '';
  d_gst = '';
  d_add = '';
  d_no: string | number = '';
  party_state = '';

  taxable = 0;
  tax = 0;
  cgst = 0;
  sgst = 0;
  igst = 0;
  netTotal = 0;

  returnProducts: ReturnNoteLine[] = [];
  readonly reasons = ['Expiry', 'Damage', 'Excess Stock', 'Near Expiry', 'Wrong Supply', 'Other'];

  editId: number | null = null;
  editNoteNo = '';
  editNoteDate = '';

  ngOnInit(): void {
    this.meta.loadProfile();
    this.reload();
  }

  reload(): void {
    this.loading.set(true);
    this.error.set(null);
    this.svc.list(this.search()).subscribe({
      next: (rows) => {
        this.notes.set(rows);
        this.loading.set(false);
      },
      error: (e) => {
        this.loading.set(false);
        this.error.set(e?.error?.message || 'Failed to load return notes.');
      },
    });
  }

  onSearch(q: string): void {
    this.search.set(q);
    this.reload();
  }

  statusOf(n: ReturnNote): string {
    const s = (n.deduction_status || 'Open').trim();
    return s === 'Applied' || s === 'Cleared' ? 'Cleared' : s || 'Open';
  }

  openCreate(): void {
    this.resetEntry();
    this.returnProducts = [];
    this.editId = null;
    this.mode.set('create');
    this.svc.products().subscribe({
      next: (p) => this.productOptions.set(p),
      error: () => this.productOptions.set([]),
    });
  }

  backToList(): void {
    this.mode.set('list');
    this.selected.set(null);
    this.reload();
  }

  viewNote(n: ReturnNote): void {
    this.loading.set(true);
    this.svc.get(n.id).subscribe({
      next: (full) => {
        this.selected.set(full);
        this.mode.set('view');
        this.loading.set(false);
      },
      error: (e) => {
        this.loading.set(false);
        this.error.set(e?.error?.message || 'Failed to load note.');
      },
    });
  }

  printNote(id: number): void {
    this.svc.pdfBlob(id).subscribe({
      next: (blob) => window.open(URL.createObjectURL(blob), '_blank'),
      error: () => this.error.set('Failed to open print PDF.'),
    });
  }

  openEdit(n: ReturnNote): void {
    if (this.statusOf(n) === 'Cleared') {
      this.error.set('Cleared notes cannot be edited.');
      return;
    }
    this.loading.set(true);
    this.svc.get(n.id).subscribe({
      next: (full) => {
        this.editId = full.id;
        this.editNoteNo = full.note_no || full.order_no || '';
        this.editNoteDate = String(full.note_date || full.order_date || '').slice(0, 10);
        this.returnProducts = (full.products || []).map((p) => ({ ...p }));
        this.distributor_name = full.distributor_name || full.party_name || '';
        this.party_state = full.state || '';
        this.d_cont = full.d_cont || '';
        this.d_mail = full.d_mail || '';
        this.d_gst = full.d_gst || '';
        this.resetLineFields();
        this.mode.set('edit');
        this.loading.set(false);
        this.svc.products().subscribe({ next: (p) => this.productOptions.set(p) });
      },
      error: (e) => {
        this.loading.set(false);
        this.error.set(e?.error?.message || 'Failed to load note for edit.');
      },
    });
  }

  filteredProducts(): { value: string; label: string }[] {
    const q = this.productQuery().trim().toLowerCase();
    const list = this.productOptions();
    if (!q) return list.slice(0, 80);
    return list.filter((p) => p.label.toLowerCase().includes(q)).slice(0, 80);
  }

  onProductQuery(q: string): void {
    this.productQuery.set(q);
    this.showProductMenu.set(true);
    this.p_name = '';
    this.batch_no = '';
    this.batches.set([]);
    this.suppliers.set([]);
  }

  pickFirstProduct(): void {
    const first = this.filteredProducts()[0];
    if (first) this.pickProduct(first.value);
  }

  pickProduct(name: string): void {
    this.p_name = name;
    this.productQuery.set(name);
    this.showProductMenu.set(false);
    this.batch_no = '';
    this.batches.set([]);
    this.suppliers.set([]);
    this.distributor_name = this.mode() === 'edit' ? this.distributor_name : '';
    this.loadingBatches.set(true);
    this.svc.batches(name).subscribe({
      next: (b) => {
        this.batches.set(b);
        this.loadingBatches.set(false);
        if (b.length === 1) {
          this.batch_no = b[0].batch_no;
          this.onBatchChange();
        }
      },
      error: (e) => {
        this.batches.set([]);
        this.loadingBatches.set(false);
        this.error.set(e?.error?.message || 'Failed to load batches.');
      },
    });
  }

  onBatchChange(): void {
    const b = this.batches().find((x) => x.batch_no === this.batch_no);
    if (!b) return;
    this.batch_id = b.batch_id;
    this.product_binding_id = b.product_binding_id;
    this.stock_qty = b.stock_qty ?? b.wAvailableQty ?? 0;
    this.net_rate = Number(b.net_rate) || 0;
    this.gst_per = Number(b.gst_per) || 0;
    this.exp_date = b.expiry || b.exp_date || null;
    this.hsn = b.hsn || '';
    this.pack_size = b.pack_size || '';
    this.suppliers.set([]);
    this.distributor_name = this.mode() === 'edit' && this.distributor_name ? this.distributor_name : '';
    this.loadingSuppliers.set(true);
    this.svc.suppliers(this.p_name, this.batch_no).subscribe({
      next: (s) => {
        this.suppliers.set(s);
        this.loadingSuppliers.set(false);
        if (s.length === 1) {
          this.distributor_name = s[0].distributor_name;
          this.applySupplier(s[0]);
        } else if (this.distributor_name) {
          const match = s.find((x) => x.distributor_name === this.distributor_name);
          if (match) this.applySupplier(match);
        }
      },
      error: (e) => {
        this.suppliers.set([]);
        this.loadingSuppliers.set(false);
        this.error.set(e?.error?.message || 'Failed to load distributors.');
      },
    });
    this.recalc();
  }

  onSupplierChange(): void {
    const s = this.suppliers().find((x) => x.distributor_name === this.distributor_name);
    if (s) this.applySupplier(s);
  }

  private applySupplier(s: ReturnSupplierOpt): void {
    this.invoice_no = s.invoice_no || '';
    this.invoice_date = s.invoice_date || null;
    this.purchase_id = s.purchase_id || 0;
    this.po_no = s.po_no || '';
    if (s.purchase_rate != null && Number(s.purchase_rate) > 0) {
      this.net_rate = Number(s.purchase_rate);
    }
    if (s.gst_rate != null && Number(s.gst_rate) > 0) {
      this.gst_per = Number(s.gst_rate);
    }
    if (s.product_binding_id) this.product_binding_id = s.product_binding_id;
    this.d_cont = s.d_cont || '';
    this.d_mail = s.d_mail || '';
    this.d_gst = s.d_gst || '';
    this.d_add = s.d_add || '';
    this.d_no = s.d_no || s.distributor_id || '';
    this.party_state = s.state || '';
    this.recalc();
  }

  checkQty(): void {
    if (this.return_qty > this.stock_qty) {
      this.error.set('Return qty cannot be greater than stock qty.');
      this.return_qty = 0;
    }
    this.recalc();
  }

  recalc(): void {
    this.taxable = Math.round(this.net_rate * this.return_qty * 100) / 100;
    this.tax = Math.round(this.taxable * (this.gst_per / 100) * 100) / 100;
    const phState = (this.meta.state() || '').trim();
    const same = phState && this.party_state && phState.toLowerCase() === this.party_state.toLowerCase();
    if (same || !phState || !this.party_state) {
      this.cgst = Math.round((this.tax / 2) * 100) / 100;
      this.sgst = Math.round((this.tax - this.cgst) * 100) / 100;
      this.igst = 0;
    } else {
      this.igst = this.tax;
      this.cgst = 0;
      this.sgst = 0;
    }
    this.netTotal = Math.round((this.taxable + this.tax) * 100) / 100;
  }

  addLine(): void {
    this.error.set(null);
    if (!this.p_name || !this.batch_no || !this.distributor_name) {
      this.error.set('Select product, batch and distributor.');
      return;
    }
    if (this.return_qty <= 0) {
      this.error.set('Enter return qty.');
      return;
    }
    if (this.mode() === 'edit' && this.returnProducts.length && this.distributor_name !== (this.returnProducts[0].distributor_name || this.distributor_name)) {
      // allow same note distributor
    }
    this.returnProducts = [
      ...this.returnProducts,
      {
        p_name: this.p_name,
        batch_no: this.batch_no,
        batch_id: this.batch_id,
        product_binding_id: this.product_binding_id,
        saleQty: this.stock_qty,
        net_rate: this.net_rate,
        gst_per: this.gst_per,
        exp_date: this.exp_date,
        return_qty: this.return_qty,
        reason: this.reason,
        taxable: this.taxable,
        tax: this.tax,
        cgst: this.cgst,
        sgst: this.sgst,
        igst: this.igst,
        netTotal: this.netTotal,
        invoice_no: this.invoice_no,
        invoice_date: this.invoice_date,
        hsn: this.hsn,
        pack_size: this.pack_size,
        po_no: this.po_no,
        purchase_id: this.purchase_id,
        distributor_name: this.distributor_name,
        state: this.party_state,
        d_cont: this.d_cont,
        d_mail: this.d_mail,
        d_gst: this.d_gst,
        d_add: this.d_add,
        d_no: this.d_no,
      },
    ];
    this.resetLineFields();
  }

  removeLine(i: number): void {
    this.returnProducts = this.returnProducts.filter((_, idx) => idx !== i);
  }

  lineTotals(): { taxable: number; tax: number; net: number } {
    return this.returnProducts.reduce(
      (a, p) => ({
        taxable: a.taxable + Number(p.taxable || 0),
        tax: a.tax + Number(p.tax || 0),
        net: a.net + Number(p.netTotal || 0),
      }),
      { taxable: 0, tax: 0, net: 0 },
    );
  }

  save(): void {
    this.error.set(null);
    if (!this.returnProducts.length) {
      this.error.set('Add at least one product line.');
      return;
    }
    const first = this.returnProducts[0];
    const party = first.distributor_name || this.distributor_name;
    const payload = {
      distributor_name: party,
      party_name: party,
      state: first.state || this.party_state,
      d_cont: first.d_cont || this.d_cont,
      d_mail: first.d_mail || this.d_mail,
      d_gst: first.d_gst || this.d_gst,
      d_add: first.d_add || this.d_add,
      d_no: first.d_no || this.d_no,
      note_date: this.editNoteDate || new Date().toISOString().slice(0, 10),
      products: this.returnProducts,
    };
    this.saving.set(true);
    const req$ = this.editId
      ? this.svc.update(this.editId, payload)
      : this.svc.save(payload);
    req$.subscribe({
      next: (r) => {
        this.saving.set(false);
        this.toast.set(`Return note ${r.note_no} saved.`);
        this.mode.set('list');
        this.reload();
      },
      error: (e) => {
        this.saving.set(false);
        const msg = e?.error?.message || e?.error?.errors
          ? JSON.stringify(e?.error?.errors || e?.error?.message)
          : 'Save failed.';
        this.error.set(typeof msg === 'string' ? msg : 'Save failed.');
      },
    });
  }

  private resetEntry(): void {
    this.resetLineFields();
    this.error.set(null);
    this.toast.set(null);
  }

  private resetLineFields(): void {
    this.p_name = '';
    this.productQuery.set('');
    this.batch_no = '';
    this.batch_id = 0;
    this.product_binding_id = 0;
    this.stock_qty = 0;
    this.net_rate = 0;
    this.gst_per = 0;
    this.exp_date = null;
    this.invoice_no = '';
    this.invoice_date = null;
    this.return_qty = 0;
    this.reason = 'Expiry';
    this.hsn = '';
    this.pack_size = '';
    this.po_no = '';
    this.purchase_id = 0;
    this.taxable = 0;
    this.tax = 0;
    this.cgst = 0;
    this.sgst = 0;
    this.igst = 0;
    this.netTotal = 0;
    this.batches.set([]);
    this.suppliers.set([]);
    if (this.mode() !== 'edit') {
      this.distributor_name = '';
      this.party_state = '';
      this.d_cont = '';
      this.d_mail = '';
      this.d_gst = '';
      this.d_add = '';
      this.d_no = '';
    }
  }

  formatExpiry(v: string | null | undefined): string {
    if (!v) return '—';
    const s = String(v);
    if (/^\d{4}-\d{2}/.test(s)) return s.slice(0, 7);
    return s;
  }

  @HostListener('document:click')
  closeMenus(): void {
    this.showProductMenu.set(false);
  }
}
