import { Component, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import {
  ProductSearchResult,
  PurchaseService,
} from '../../core/services/purchase.service';

interface Line {
  master_product_id?: number;
  product_name: string;
  schedule_category: string;
  batch_no: string;
  expiry_date: string;
  quantity: number;
  free_quantity: number;
  purchase_rate: number;
  mrp: number;
  discount_pct: number;
  gst_rate: number;
}

function emptyLine(): Line {
  return {
    product_name: '',
    schedule_category: 'OTC',
    batch_no: '',
    expiry_date: '',
    quantity: 1,
    free_quantity: 0,
    purchase_rate: 0,
    mrp: 0,
    discount_pct: 0,
    gst_rate: 12,
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

  readonly lines = signal<Line[]>([emptyLine()]);
  readonly saving = signal(false);
  readonly error = signal<string | null>(null);
  readonly toast = signal<string | null>(null);

  // Product typeahead state
  readonly searchResults = signal<ProductSearchResult[]>([]);
  readonly activeIndex = signal<number | null>(null);
  private searchTimer?: ReturnType<typeof setTimeout>;

  readonly scheduleOptions = ['OTC', 'H', 'H1', 'X', 'G', 'RESTRICTED'];

  // ---- line maths ----
  lineTaxable(l: Line): number {
    return l.purchase_rate * l.quantity * (1 - l.discount_pct / 100);
  }
  lineGst(l: Line): number {
    return this.lineTaxable(l) * (l.gst_rate / 100);
  }
  lineTotal(l: Line): number {
    return this.lineTaxable(l) + this.lineGst(l);
  }

  readonly grossTotal = computed(() =>
    this.lines().reduce((s, l) => s + l.purchase_rate * l.quantity, 0),
  );
  readonly discountTotal = computed(() => {
    const lineDisc = this.lines().reduce((s, l) => s + l.purchase_rate * l.quantity * (l.discount_pct / 100), 0);
    const itemsTotal = this.lines().reduce((s, l) => s + this.lineTotal(l), 0);
    return lineDisc + itemsTotal * (this.billDiscountPct() / 100);
  });
  readonly gstTotal = computed(() => this.lines().reduce((s, l) => s + this.lineGst(l), 0));
  readonly itemsTotal = computed(() => this.lines().reduce((s, l) => s + this.lineTotal(l), 0));
  readonly netTotal = computed(() =>
    Math.max(0, this.itemsTotal() - this.itemsTotal() * (this.billDiscountPct() / 100)),
  );

  // ---- line management ----
  addLine(): void {
    this.lines.update((ls) => [...ls, emptyLine()]);
  }
  removeLine(index: number): void {
    this.lines.update((ls) => (ls.length > 1 ? ls.filter((_, i) => i !== index) : ls));
  }
  updateLine(index: number, patch: Partial<Line>): void {
    this.lines.update((ls) => ls.map((l, i) => (i === index ? { ...l, ...patch } : l)));
  }

  // ---- product search ----
  onProductInput(index: number, value: string): void {
    this.updateLine(index, { product_name: value, master_product_id: undefined });
    this.activeIndex.set(index);
    clearTimeout(this.searchTimer);
    if (value.trim().length < 2) {
      this.searchResults.set([]);
      return;
    }
    this.searchTimer = setTimeout(() => {
      this.purchaseSvc.searchProducts(value).subscribe((r) => this.searchResults.set(r));
    }, 250);
  }

  selectProduct(index: number, p: ProductSearchResult): void {
    this.updateLine(index, {
      master_product_id: p.id,
      product_name: p.name,
      schedule_category: p.schedule_category,
      gst_rate: Number(p.default_gst_rate) || 12,
    });
    this.searchResults.set([]);
    this.activeIndex.set(null);
  }

  closeSearch(): void {
    setTimeout(() => {
      this.searchResults.set([]);
      this.activeIndex.set(null);
    }, 180);
  }

  // ---- CSV import ----
  onCsvSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;
    this.purchaseSvc.importCsv(file).subscribe({
      next: (res) => {
        const imported: Line[] = res.data.lines.map((l) => ({
          master_product_id: l.master_product_id,
          product_name: l.product_name,
          schedule_category: 'OTC',
          batch_no: l.batch_no ?? '',
          expiry_date: l.expiry_date ?? '',
          quantity: l.quantity || 1,
          free_quantity: l.free_quantity || 0,
          purchase_rate: l.purchase_rate || 0,
          mrp: l.mrp || 0,
          discount_pct: l.discount_pct || 0,
          gst_rate: l.gst_rate || 12,
        }));
        if (imported.length) this.lines.set(imported);
        this.showToast(`${res.data.total_lines} lines imported (${res.data.matched} matched to catalog).`);
      },
      error: (err: HttpErrorResponse) => this.showToast(err.error?.message ?? 'Import failed.'),
    });
    input.value = '';
  }

  // ---- save ----
  save(): void {
    if (!this.invoiceNo().trim()) {
      this.error.set('Invoice number is required.');
      return;
    }
    const valid = this.lines().every((l) => l.product_name.trim() && l.quantity > 0);
    if (!valid) {
      this.error.set('Each line needs a product name and quantity.');
      return;
    }

    this.saving.set(true);
    this.error.set(null);

    const items = this.lines().map((l) => {
      const base = {
        product_name: l.product_name,
        batch_no: l.batch_no,
        expiry_date: l.expiry_date,
        quantity: l.quantity,
        free_quantity: l.free_quantity,
        purchase_rate: l.purchase_rate,
        mrp: l.mrp,
        discount_pct: l.discount_pct,
        gst_rate: l.gst_rate,
      };
      return l.master_product_id
        ? { ...base, master_product_id: l.master_product_id }
        : {
            ...base,
            new_product: {
              name: l.product_name,
              schedule_category: l.schedule_category,
              default_gst_rate: l.gst_rate,
            },
          };
    });

    this.purchaseSvc
      .create({
        invoice_no: this.invoiceNo(),
        invoice_date: this.invoiceDate(),
        supplier_name: this.supplierName(),
        bill_discount_pct: this.billDiscountPct(),
        items,
      })
      .subscribe({
        next: () => {
          this.saving.set(false);
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
