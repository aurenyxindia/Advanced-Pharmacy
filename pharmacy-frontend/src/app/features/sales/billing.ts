import { Component, computed, inject, signal, ElementRef, viewChild } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpErrorResponse } from '@angular/common/http';
import {
  SaleProduct,
  SalesService,
  SaleResult,
  Substitute,
} from '../../core/services/sales.service';

interface BillLine {
  binding_id: number;
  product_name: string;
  schedule_category: string;
  gst_rate: number;
  quantity: number;
  rate: number;
  discount_pct: number;
  available_qty: number;
}

const PRESCRIPTION_SCHEDULES = ['H1', 'X', 'G', 'RESTRICTED'];

@Component({
  selector: 'app-billing',
  imports: [CommonModule, FormsModule],
  templateUrl: './billing.html',
  styleUrl: './billing.scss',
})
export class Billing {
  private readonly salesSvc = inject(SalesService);
  private readonly video = viewChild<ElementRef<HTMLVideoElement>>('video');

  readonly customerName = signal('Walk-in');
  readonly doctorName = signal('');
  readonly paymentMode = signal('cash');
  readonly billDiscountPct = signal(0);

  readonly lines = signal<BillLine[]>([]);
  readonly saving = signal(false);
  readonly error = signal<string | null>(null);

  // search
  readonly searchTerm = signal('');
  readonly searchResults = signal<SaleProduct[]>([]);
  private searchTimer?: ReturnType<typeof setTimeout>;

  // prescription
  readonly prescriptionId = signal<number | null>(null);
  readonly prescriptionName = signal<string | null>(null);
  readonly uploadingPrescription = signal(false);

  // substitutes
  readonly subsOpen = signal(false);
  readonly subsLoading = signal(false);
  readonly substitutes = signal<Substitute[]>([]);

  // camera
  readonly cameraOpen = signal(false);
  private stream?: MediaStream;

  // completed sale
  readonly completed = signal<SaleResult | null>(null);

  readonly prescriptionRequired = computed(() =>
    this.lines().some((l) => PRESCRIPTION_SCHEDULES.includes(l.schedule_category)),
  );

  lineSell(l: BillLine): number {
    return l.rate * l.quantity * (1 - l.discount_pct / 100);
  }
  lineGst(l: BillLine): number {
    const sell = this.lineSell(l);
    return sell - sell / (1 + l.gst_rate / 100);
  }

  readonly grossTotal = computed(() => this.lines().reduce((s, l) => s + l.rate * l.quantity, 0));
  readonly gstTotal = computed(() => this.lines().reduce((s, l) => s + this.lineGst(l), 0));
  readonly itemsSell = computed(() => this.lines().reduce((s, l) => s + this.lineSell(l), 0));
  readonly netTotal = computed(() =>
    Math.max(0, this.itemsSell() - this.itemsSell() * (this.billDiscountPct() / 100)),
  );
  readonly itemCount = computed(() => this.lines().reduce((s, l) => s + l.quantity, 0));

  // ---- search ----
  onSearch(value: string): void {
    this.searchTerm.set(value);
    clearTimeout(this.searchTimer);
    if (value.trim().length < 2) {
      this.searchResults.set([]);
      return;
    }
    this.searchTimer = setTimeout(() => {
      this.salesSvc.searchProducts(value).subscribe((r) => this.searchResults.set(r));
    }, 250);
  }

  addProduct(p: SaleProduct): void {
    const existing = this.lines().findIndex((l) => l.binding_id === p.binding_id);
    if (existing >= 0) {
      this.updateLine(existing, { quantity: this.lines()[existing].quantity + 1 });
    } else {
      this.lines.update((ls) => [
        ...ls,
        {
          binding_id: p.binding_id,
          product_name: p.product_name,
          schedule_category: p.schedule_category,
          gst_rate: Number(p.gst_rate) || 0,
          quantity: 1,
          rate: Number(p.mrp) || 0,
          discount_pct: 0,
          available_qty: p.available_qty,
        },
      ]);
    }
    this.searchTerm.set('');
    this.searchResults.set([]);
  }

  updateLine(index: number, patch: Partial<BillLine>): void {
    this.lines.update((ls) => ls.map((l, i) => (i === index ? { ...l, ...patch } : l)));
  }
  removeLine(index: number): void {
    this.lines.update((ls) => ls.filter((_, i) => i !== index));
  }

  // ---- substitutes ----
  openSubstitutes(line: BillLine): void {
    this.subsOpen.set(true);
    this.subsLoading.set(true);
    this.substitutes.set([]);
    this.salesSvc.substitutes(line.binding_id).subscribe({
      next: (s) => { this.substitutes.set(s); this.subsLoading.set(false); },
      error: () => this.subsLoading.set(false),
    });
  }
  addSubstitute(sub: Substitute): void {
    if (!sub.binding_id) return;
    this.addProduct({
      binding_id: sub.binding_id,
      product_name: sub.name,
      schedule_category: sub.schedule_category,
      gst_rate: sub.default_gst_rate,
      master_product_id: sub.id,
      available_qty: sub.available_qty,
      mrp: 0,
    });
    this.subsOpen.set(false);
  }

  // ---- prescription upload ----
  onPrescriptionFile(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;
    this.uploadingPrescription.set(true);
    this.salesSvc.uploadPrescription(file, 'upload', this.doctorName()).subscribe({
      next: (res) => {
        this.prescriptionId.set(res.data.id);
        this.prescriptionName.set(file.name);
        this.uploadingPrescription.set(false);
      },
      error: () => this.uploadingPrescription.set(false),
    });
    input.value = '';
  }

  // ---- camera ----
  async openCamera(): Promise<void> {
    this.cameraOpen.set(true);
    try {
      this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
      const v = this.video()?.nativeElement;
      if (v) { v.srcObject = this.stream; await v.play(); }
    } catch {
      this.error.set('Unable to access camera.');
      this.cameraOpen.set(false);
    }
  }
  capture(): void {
    const v = this.video()?.nativeElement;
    if (!v) return;
    const canvas = document.createElement('canvas');
    canvas.width = v.videoWidth;
    canvas.height = v.videoHeight;
    canvas.getContext('2d')!.drawImage(v, 0, 0);
    canvas.toBlob((blob) => {
      if (!blob) return;
      this.uploadingPrescription.set(true);
      this.salesSvc.uploadPrescription(blob, 'camera', this.doctorName()).subscribe({
        next: (res) => {
          this.prescriptionId.set(res.data.id);
          this.prescriptionName.set('Camera capture');
          this.uploadingPrescription.set(false);
          this.closeCamera();
        },
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
  save(): void {
    if (this.lines().length === 0) {
      this.error.set('Add at least one item.');
      return;
    }
    if (this.prescriptionRequired() && !this.prescriptionId()) {
      this.error.set('A prescription is mandatory for the scheduled drugs in this bill.');
      return;
    }
    this.saving.set(true);
    this.error.set(null);

    this.salesSvc
      .create({
        customer_name: this.customerName(),
        doctor_name: this.doctorName(),
        payment_mode: this.paymentMode(),
        bill_discount_pct: this.billDiscountPct(),
        prescription_id: this.prescriptionId(),
        items: this.lines().map((l) => ({
          product_binding_id: l.binding_id,
          schedule_category: l.schedule_category,
          quantity: l.quantity,
          rate: l.rate,
          discount_pct: l.discount_pct,
        })),
      })
      .subscribe({
        next: (res) => {
          this.saving.set(false);
          this.completed.set(res.data);
        },
        error: (err: HttpErrorResponse) => {
          this.saving.set(false);
          const errors = err.error?.errors;
          const first = errors ? (Object.values(errors)[0] as string[])?.[0] : null;
          this.error.set(first ?? err.error?.message ?? 'Could not complete the sale.');
        },
      });
  }

  printInvoice(): void {
    const sale = this.completed();
    if (!sale) return;
    this.salesSvc.invoiceBlob(sale.id).subscribe((blob) => {
      const url = URL.createObjectURL(blob);
      window.open(url, '_blank');
    });
  }

  newBill(): void {
    this.lines.set([]);
    this.customerName.set('Walk-in');
    this.doctorName.set('');
    this.billDiscountPct.set(0);
    this.prescriptionId.set(null);
    this.prescriptionName.set(null);
    this.completed.set(null);
    this.error.set(null);
  }
}
