import { Component, HostListener, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { MetaService } from '../../core/services/meta.service';
import { ReturnTabsComponent } from './return-tabs';
import { WholesaleCreditNoteApi } from '../../core/services/returns-hub.service';

@Component({
  selector: 'app-wholesale-return',
  imports: [CommonModule, FormsModule, ReturnTabsComponent],
  templateUrl: './wholesale-return.html',
  styleUrl: './wholesale-return.scss',
})
export class WholesaleReturnPage implements OnInit {
  private readonly api = inject(WholesaleCreditNoteApi);
  private readonly meta = inject(MetaService);

  readonly mode = signal<'list' | 'create' | 'view' | 'edit'>('list');
  readonly loading = signal(false);
  readonly saving = signal(false);
  readonly error = signal<string | null>(null);
  readonly toast = signal<string | null>(null);

  readonly notes = signal<any[]>([]);
  readonly customers = signal<any[]>([]);
  readonly productOptions = signal<{ value: string; label: string }[]>([]);
  readonly batches = signal<any[]>([]);
  readonly showCustMenu = signal(false);
  readonly showProdMenu = signal(false);
  readonly custQuery = signal('');
  readonly productQuery = signal('');

  search = '';
  customer_name = '';
  customer_id: number | null = null;
  c_cont = '';
  c_mail = '';
  c_add = '';
  gst_no = '';
  party_state = '';

  p_name = '';
  batch_no = '';
  sale_item_id = 0;
  product_binding_id = 0;
  batch_id = 0;
  saleQty = 0;
  sale_rate = 0;
  gst_per = 0;
  exp_date: string | null = null;
  diduction = 0;
  return_qty = 0;
  reason = 'Expiry';
  hsn = '';
  pack_size = '';
  taxable = 0;
  tax = 0;
  cgst = 0;
  sgst = 0;
  igst = 0;
  netTotal = 0;

  returnProducts: any[] = [];
  readonly reasons = ['Expiry', 'Breakages', 'Non Movable', 'Other'];
  selected: any = null;
  editId: number | null = null;
  editNoteNo = '';

  ngOnInit(): void {
    this.meta.loadProfile();
    this.reload();
    this.api.customers().subscribe({ next: (c) => this.customers.set(c) });
  }

  statusOf(n: any): string {
    const s = String(n?.deduction_status || 'Open').trim();
    return s === 'Applied' || s === 'Cleared' ? 'Cleared' : s || 'Open';
  }

  reload(): void {
    this.loading.set(true);
    this.api.list(this.search).subscribe({
      next: (r) => {
        this.notes.set(r);
        this.loading.set(false);
      },
      error: (e) => {
        this.loading.set(false);
        this.error.set(e?.error?.message || 'Failed to load.');
      },
    });
  }

  filteredCustomers(): any[] {
    const q = this.custQuery().trim().toLowerCase();
    const list = this.customers();
    if (!q) return list.slice(0, 60);
    return list.filter((c) => String(c.label || c.value || '').toLowerCase().includes(q)).slice(0, 60);
  }

  filteredProducts(): { value: string; label: string }[] {
    const q = this.productQuery().trim().toLowerCase();
    const list = this.productOptions();
    if (!q) return list.slice(0, 80);
    return list.filter((p) => p.label.toLowerCase().includes(q)).slice(0, 80);
  }

  openCreate(): void {
    this.resetEntry();
    this.returnProducts = [];
    this.editId = null;
    this.mode.set('create');
  }

  pickCustomer(c: any): void {
    this.customer_name = c.value || c.customer_name || c.label;
    this.custQuery.set(this.customer_name);
    this.showCustMenu.set(false);
    this.customer_id = c.customer_id || null;
    this.c_cont = c.c_cont || c.phone || '';
    this.c_mail = c.c_mail || c.email || '';
    this.c_add = c.c_add || c.address || '';
    this.gst_no = c.gst_no || c.gstin || '';
    this.party_state = c.state || '';
    this.p_name = '';
    this.productQuery.set('');
    this.batches.set([]);
    this.api.products(this.customer_name).subscribe({
      next: (p) => this.productOptions.set(p),
      error: () => this.productOptions.set([]),
    });
  }

  pickProduct(name: string): void {
    this.p_name = name;
    this.productQuery.set(name);
    this.showProdMenu.set(false);
    this.batch_no = '';
    this.batches.set([]);
    this.api.batches(this.customer_name, name).subscribe({
      next: (b) => {
        this.batches.set(b);
        if (b.length === 1) {
          this.batch_no = b[0].batch_no;
          this.onBatchChange();
        }
      },
      error: () => this.batches.set([]),
    });
  }

  onBatchChange(): void {
    const b = this.batches().find((x) => x.batch_no === this.batch_no);
    if (!b) return;
    this.sale_item_id = b.sale_item_id || b.id;
    this.product_binding_id = b.product_binding_id || 0;
    this.batch_id = b.batch_id || 0;
    this.saleQty = b.saleQty || b.sale_qty || 0;
    this.sale_rate = Number(b.sale_rate) || 0;
    this.gst_per = Number(b.gst || b.gst_per) || 0;
    this.exp_date = b.exp_date || b.expiry || null;
    this.diduction = Number(b.diduction || b.deduction_pct) || 0;
    this.hsn = b.hsn || '';
    this.pack_size = b.pack_size || '';
    this.recalc();
  }

  checkQty(): void {
    if (this.return_qty > this.saleQty) {
      this.error.set('Return qty cannot exceed sold qty available.');
      this.return_qty = 0;
    }
    this.recalc();
  }

  recalc(): void {
    this.taxable = Math.round(this.sale_rate * this.return_qty * (100 - this.diduction) / 100 * 100) / 100;
    this.tax = Math.round(this.taxable * (this.gst_per / 100) * 100) / 100;
    const ph = (this.meta.state() || '').trim();
    const same = ph && this.party_state && ph.toLowerCase() === this.party_state.toLowerCase();
    if (same || !ph || !this.party_state) {
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
    if (!this.customer_name || !this.p_name || !this.batch_no) {
      this.error.set('Select customer, product and batch.');
      return;
    }
    if (this.return_qty <= 0) {
      this.error.set('Enter return qty.');
      return;
    }
    this.returnProducts = [
      ...this.returnProducts,
      {
        p_name: this.p_name,
        batch_no: this.batch_no,
        id: this.sale_item_id,
        sale_item_id: this.sale_item_id,
        product_binding_id: this.product_binding_id,
        batch_id: this.batch_id,
        saleQty: this.saleQty,
        sale_rate: this.sale_rate,
        gst: this.gst_per,
        gst_per: this.gst_per,
        exp_date: this.exp_date,
        diduction: this.diduction,
        return_qty: this.return_qty,
        reason: this.reason,
        taxable: this.taxable,
        tax: this.tax,
        cgst: this.cgst,
        sgst: this.sgst,
        igst: this.igst,
        netTotal: this.netTotal,
        hsn: this.hsn,
        pack_size: this.pack_size,
      },
    ];
    this.resetLine();
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
    const payload = {
      customer_name: this.customer_name,
      party_name: this.customer_name,
      customer_id: this.customer_id,
      c_cont: this.c_cont,
      c_mail: this.c_mail,
      c_add: this.c_add,
      gst_no: this.gst_no,
      state: this.party_state,
      products: this.returnProducts,
    };
    this.saving.set(true);
    const req$ = this.editId ? this.api.update(this.editId, payload) : this.api.save(payload);
    req$.subscribe({
      next: (r) => {
        this.saving.set(false);
        this.toast.set(`Credit note ${r.note_no} saved.`);
        this.mode.set('list');
        this.reload();
      },
      error: (e) => {
        this.saving.set(false);
        this.error.set(e?.error?.message || 'Save failed.');
      },
    });
  }

  viewNote(n: any): void {
    this.api.get(n.id).subscribe({
      next: (full) => {
        this.selected = full;
        this.mode.set('view');
      },
      error: (e) => this.error.set(e?.error?.message || 'Failed to load.'),
    });
  }

  printNote(id: number): void {
    this.api.pdfBlob(id).subscribe({
      next: (blob) => window.open(URL.createObjectURL(blob), '_blank'),
      error: () => this.error.set('Failed to open print PDF.'),
    });
  }

  openEdit(n: any): void {
    if (this.statusOf(n) === 'Cleared') {
      this.error.set('Cleared notes cannot be edited.');
      return;
    }
    this.api.get(n.id).subscribe({
      next: (full) => {
        this.editId = full.id;
        this.editNoteNo = full.note_no;
        this.customer_name = full.customer_name || full.party_name;
        this.custQuery.set(this.customer_name);
        this.c_cont = full.d_cont || '';
        this.c_mail = full.d_mail || '';
        this.gst_no = full.d_gst || '';
        this.party_state = full.state || '';
        this.returnProducts = (full.products || []).map((p: any) => ({ ...p }));
        this.mode.set('edit');
        this.api.products(this.customer_name).subscribe({ next: (p) => this.productOptions.set(p) });
      },
      error: (e) => this.error.set(e?.error?.message || 'Failed to load for edit.'),
    });
  }

  backToList(): void {
    this.mode.set('list');
    this.selected = null;
    this.reload();
  }

  private resetEntry(): void {
    this.customer_name = '';
    this.custQuery.set('');
    this.c_cont = '';
    this.c_mail = '';
    this.c_add = '';
    this.gst_no = '';
    this.party_state = '';
    this.productOptions.set([]);
    this.resetLine();
    this.error.set(null);
  }

  private resetLine(): void {
    this.p_name = '';
    this.productQuery.set('');
    this.batch_no = '';
    this.batches.set([]);
    this.saleQty = 0;
    this.sale_rate = 0;
    this.gst_per = 0;
    this.exp_date = null;
    this.diduction = 0;
    this.return_qty = 0;
    this.reason = 'Expiry';
    this.taxable = 0;
    this.tax = 0;
    this.netTotal = 0;
  }

  @HostListener('document:click')
  closeMenus(): void {
    this.showCustMenu.set(false);
    this.showProdMenu.set(false);
  }
}
