import { Component, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { ProductSearchResult, PurchaseService } from '../../core/services/purchase.service';

interface Entry {
  master_product_id?: number;
  product_name: string;
  schedule_category: string;
  product_category: string;
  hsn_code: string;
  batch_no: string;
  expiry_date: string;
  shelf_location: string;
  quantity: number;       // packs
  units_per_pack: number;
  free_quantity: number;
  purchase_rate: number;  // per pack
  mrp: number;            // per pack
  ptr: number;
  pts: number;
  gst_rate: number;
  discount_pct: number;
  scheme_pct: number;
}

function emptyEntry(): Entry {
  return {
    product_name: '', schedule_category: 'OTC', product_category: 'Allopathy', hsn_code: '', batch_no: '', expiry_date: '',
    shelf_location: '', quantity: 1, units_per_pack: 1, free_quantity: 0, purchase_rate: 0,
    mrp: 0, ptr: 0, pts: 0, gst_rate: 12, discount_pct: 0, scheme_pct: 0,
  };
}

@Component({
  selector: 'app-purchase-entry',
  imports: [CommonModule, FormsModule],
  templateUrl: './purchase-entry.html',
  styleUrl: './purchase-entry.scss',
})
export class PurchaseEntry {
  private readonly purchaseSvc = inject(PurchaseService);
  readonly router = inject(Router);

  readonly invoiceNo = signal('');
  readonly invoiceDate = signal(new Date().toISOString().slice(0, 10));
  readonly supplierName = signal('');
  readonly billDiscountPct = signal(0);

  readonly entry = signal<Entry>(emptyEntry());
  readonly lines = signal<Entry[]>([]);
  readonly saving = signal(false);
  readonly error = signal<string | null>(null);
  readonly toast = signal<string | null>(null);

  // product typeahead for the entry row
  readonly searchResults = signal<ProductSearchResult[]>([]);
  private searchTimer?: ReturnType<typeof setTimeout>;
  readonly showResults = signal(false);

  readonly scheduleOptions = ['OTC', 'H', 'H1', 'X', 'G', 'RESTRICTED'];
  readonly categoryOptions = ['Allopathy', 'Ayurvedic', 'Generic', 'Surgical', 'Cosmetic'];
  private rateTimer?: ReturnType<typeof setTimeout>;

  // ---- entry edits ----
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  patch(field: keyof Entry, value: any): void {
    this.entry.update((e) => ({ ...e, [field]: value }));
    // Auto-derive PTR/PTS from MRP + GST + category margin.
    if (field === 'mrp' || field === 'gst_rate' || field === 'product_category') {
      clearTimeout(this.rateTimer);
      this.rateTimer = setTimeout(() => this.autoRates(), 250);
    }
  }

  private autoRates(): void {
    const e = this.entry();
    if (e.mrp <= 0) return;
    this.purchaseSvc.computeRates(e.mrp, e.gst_rate, e.product_category).subscribe((r) => {
      this.entry.update((cur) => ({ ...cur, ptr: r.ptr, pts: r.pts }));
    });
  }

  onProductInput(value: string): void {
    this.patch('product_name', value);
    this.patch('master_product_id', undefined as unknown as number);
    clearTimeout(this.searchTimer);
    this.showResults.set(true);
    if (value.trim().length < 2) { this.searchResults.set([]); return; }
    this.searchTimer = setTimeout(() => {
      this.purchaseSvc.searchProducts(value).subscribe((r) => this.searchResults.set(r));
    }, 220);
  }
  selectProduct(p: ProductSearchResult): void {
    this.entry.update((e) => ({
      ...e,
      master_product_id: p.id,
      product_name: p.name,
      schedule_category: p.schedule_category,
      product_category: p.product_category || 'Allopathy',
      hsn_code: p.hsn_code ?? '',
      gst_rate: Number(p.default_gst_rate) || 12,
      units_per_pack: p.units_per_pack || 1,
      shelf_location: p.shelf_location || '',
    }));
    this.searchResults.set([]);
    this.showResults.set(false);
    setTimeout(() => this.autoRates(), 50);
  }

  // ---- entry computed ----
  // Total saleable units = packs x units/pack + free (free entered in units).
  baseUnits(e: Entry): number { return e.quantity * (e.units_per_pack || 1) + (e.free_quantity || 0); }
  // Net Rate per saleable unit (excl GST) = (rate x packs - product discount) / base units
  netRate(e: Entry): number {
    const gross = e.purchase_rate * e.quantity;
    const disc = gross * (e.discount_pct / 100);
    const units = this.baseUnits(e);
    return units > 0 ? (gross - disc) / units : 0;
  }
  entryTaxable(e: Entry): number { return e.purchase_rate * e.quantity * (1 - e.discount_pct / 100); }
  entryTotal(e: Entry): number { return this.entryTaxable(e) * (1 + e.gst_rate / 100); }

  addLine(): void {
    const e = this.entry();
    if (!e.product_name.trim() || e.quantity <= 0) {
      this.error.set('Enter a product and quantity before adding.');
      return;
    }
    this.error.set(null);
    this.lines.update((ls) => [...ls, { ...e }]);
    // keep gst default for fast entry
    this.entry.set({ ...emptyEntry(), gst_rate: e.gst_rate });
  }
  removeLine(i: number): void {
    this.lines.update((ls) => ls.filter((_, idx) => idx !== i));
  }

  // ---- totals ----
  readonly grossTotal = computed(() => this.lines().reduce((s, l) => s + l.purchase_rate * l.quantity, 0));
  readonly gstTotal = computed(() => this.lines().reduce((s, l) => s + this.entryTaxable(l) * (l.gst_rate / 100), 0));
  readonly itemsTotal = computed(() => this.lines().reduce((s, l) => s + this.entryTotal(l), 0));
  readonly netTotal = computed(() =>
    Math.max(0, this.itemsTotal() - this.itemsTotal() * (this.billDiscountPct() / 100)),
  );

  // ---- CSV import ----
  onCsvSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;
    this.purchaseSvc.importCsv(file).subscribe({
      next: (res) => {
        const imported: Entry[] = res.data.lines.map((l) => ({
          master_product_id: l.master_product_id,
          product_name: l.product_name,
          schedule_category: 'OTC',
          product_category: 'Allopathy',
          hsn_code: '',
          batch_no: l.batch_no ?? '',
          expiry_date: l.expiry_date ?? '',
          shelf_location: '',
          quantity: l.quantity || 1,
          units_per_pack: 1,
          free_quantity: l.free_quantity || 0,
          purchase_rate: l.purchase_rate || 0,
          mrp: l.mrp || 0,
          ptr: 0, pts: 0,
          gst_rate: l.gst_rate || 12,
          discount_pct: l.discount_pct || 0,
          scheme_pct: 0,
        }));
        if (imported.length) this.lines.update((ls) => [...ls, ...imported]);
        this.showToast(`${res.data.total_lines} lines imported (${res.data.matched} matched).`);
      },
      error: (err: HttpErrorResponse) => this.showToast(err.error?.message ?? 'Import failed.'),
    });
    input.value = '';
  }

  // ---- save ----
  save(): void {
    if (!this.invoiceNo().trim()) { this.error.set('Invoice number is required.'); return; }
    if (this.lines().length === 0) { this.error.set('Add at least one product to the list.'); return; }
    this.saving.set(true);
    this.error.set(null);

    const items = this.lines().map((l) => {
      const base = {
        product_name: l.product_name,
        hsn_code: l.hsn_code,
        batch_no: l.batch_no,
        expiry_date: l.expiry_date,
        shelf_location: l.shelf_location,
        quantity: l.quantity,
        units_per_pack: l.units_per_pack || 1,
        free_quantity: l.free_quantity,
        purchase_rate: l.purchase_rate,
        mrp: l.mrp,
        ptr: l.ptr,
        pts: l.pts,
        scheme_pct: l.scheme_pct,
        discount_pct: l.discount_pct,
        gst_rate: l.gst_rate,
      };
      return l.master_product_id
        ? { ...base, master_product_id: l.master_product_id }
        : {
            ...base,
            new_product: {
              name: l.product_name, schedule_category: l.schedule_category,
              product_category: l.product_category,
              default_gst_rate: l.gst_rate, units_per_pack: l.units_per_pack || 1,
              shelf_location: l.shelf_location, hsn_code: l.hsn_code,
            },
          };
    });

    this.purchaseSvc.create({
      invoice_no: this.invoiceNo(), invoice_date: this.invoiceDate(),
      supplier_name: this.supplierName(), bill_discount_pct: this.billDiscountPct(),
      items,
    }).subscribe({
      next: (res) => {
        this.saving.set(false);
        // Open the printable barcode labels for the received packs, then go to the list.
        const id = res.data?.id;
        if (id) {
          this.purchaseSvc.barcodeBlob(id).subscribe((blob) => window.open(URL.createObjectURL(blob), '_blank'));
        }
        this.router.navigate(['/app/purchase']);
      },
      error: (err: HttpErrorResponse) => {
        this.saving.set(false);
        this.error.set(err.error?.message ?? 'Could not save purchase.');
      },
    });
  }

  private showToast(msg: string): void {
    this.toast.set(msg);
    setTimeout(() => this.toast.set(null), 3000);
  }
}
