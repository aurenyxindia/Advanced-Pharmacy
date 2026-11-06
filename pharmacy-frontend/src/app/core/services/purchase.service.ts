import { Injectable, inject, signal } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, map, tap } from 'rxjs';
import { environment } from '../../../environments/environment';
import { ApiResponse } from '../models/auth.models';
import { Paginated } from './crud.service';

export interface ProductSearchResult {
  id: number;
  name: string;
  manufacturer: string | null;
  schedule_category: string;
  default_gst_rate: number;
  generic_name: string | null;
  dosage_form?: string | null;
  strength?: string | null;
  hsn_code: string | null;
  pack_size: string | null;
  units_per_pack?: number;
  shelf_location?: string | null;
  product_category?: string;
  manufacturer_short?: string | null;
  mrp?: number;
  ptr?: number;
  pts?: number;
}

export interface PurchaseLine {
  master_product_id?: number;
  product_binding_id?: number;
  product_name: string;
  batch_no: string;
  expiry_date: string;
  quantity: number;
  free_quantity: number;
  purchase_rate: number;
  mrp: number;
  ptr?: number;
  pts?: number;
  discount_pct: number;
  gst_rate: number;
  hsn_code?: string | null;
  units_per_pack?: number;
  pack_size?: string;
  pack_raw?: string;
  new_product?: Record<string, unknown>;
}

export interface PurchasePayload {
  invoice_no: string;
  invoice_date: string;
  supplier_name?: string;
  supplier_id?: number | null;
  bill_discount_pct?: number;
  bill_discount_amount?: number;
  cash_credit?: string;
  due_date?: string;
  cd_mode?: string;
  cd_pct?: number;
  cd_amount?: number;
  transport_charge?: number;
  transport_gst_pct?: number;
  debit_note_no?: string;
  debit_deduction_amt?: number;
  confirm_sold_edit?: boolean;
  items: PurchaseLine[];
}

@Injectable({ providedIn: 'root' })
export class PurchaseService {
  private readonly http = inject(HttpClient);
  private readonly base = environment.apiUrl;

  /** Bumped after create/update/delete so Purchase log refreshes. */
  readonly logVersion = signal(0);

  notifyLogChanged(): void {
    this.logVersion.update((v) => v + 1);
  }

  searchProducts(q: string, limit = 40): Observable<ProductSearchResult[]> {
    const params = new HttpParams().set('q', q).set('limit', String(limit));
    return this.http
      .get<ApiResponse<ProductSearchResult[]>>(`${this.base}/products/search`, { params })
      .pipe(map((r) => r.data));
  }

  create(payload: PurchasePayload): Observable<ApiResponse<{ id: number; net_amount: number }>> {
    return this.http
      .post<ApiResponse<{ id: number; net_amount: number }>>(`${this.base}/purchases`, payload)
      .pipe(tap(() => this.notifyLogChanged()));
  }

  update(id: number, payload: PurchasePayload): Observable<ApiResponse<{ id: number; po_no?: string; net_amount: number }>> {
    return this.http
      .put<ApiResponse<{ id: number; po_no?: string; net_amount: number }>>(`${this.base}/purchases/${id}`, payload)
      .pipe(tap(() => this.notifyLogChanged()));
  }

  list(opts: {
    search?: string;
    page?: number;
    per_page?: number;
    month?: string;
    from_date?: string;
    to_date?: string;
  } = {}): Observable<Paginated<Record<string, unknown>>> {
    let params = new HttpParams();
    if (opts.search) params = params.set('search', opts.search);
    if (opts.page) params = params.set('page', String(opts.page));
    if (opts.per_page) params = params.set('per_page', String(opts.per_page));
    if (opts.month) params = params.set('month', opts.month);
    if (opts.from_date) params = params.set('from_date', opts.from_date);
    if (opts.to_date) params = params.set('to_date', opts.to_date);
    return this.http
      .get<ApiResponse<Paginated<Record<string, unknown>>>>(`${this.base}/purchases`, { params })
      .pipe(map((r) => r.data));
  }

  get(id: number): Observable<Record<string, unknown>> {
    return this.http
      .get<ApiResponse<Record<string, unknown>>>(`${this.base}/purchases/${id}`)
      .pipe(map((r) => r.data));
  }

  remove(id: number): Observable<ApiResponse<null>> {
    return this.http.delete<ApiResponse<null>>(`${this.base}/purchases/${id}`).pipe(
      tap(() => this.notifyLogChanged()),
    );
  }

  /** A4 sheet barcodes (office printer). */
  barcodeBlob(id: number): Observable<Blob> {
    return this.http.get(`${this.base}/purchases/${id}/barcodes`, { responseType: 'blob' });
  }

  /** TSC TE 210 / TE 244 — 40×15 mm thermal roll (one label per page). */
  labelBarcodeBlob(id: number): Observable<Blob> {
    return this.http.get(`${this.base}/purchases/${id}/label-barcodes`, { responseType: 'blob' });
  }

  computeRates(mrp: number, gst: number, category: string): Observable<{ ptr: number; pts: number; net_mrp: number }> {
    const params = new HttpParams().set('mrp', String(mrp)).set('gst', String(gst)).set('category', category);
    return this.http
      .get<ApiResponse<{ ptr: number; pts: number; net_mrp: number }>>(`${this.base}/rate-margins/compute`, { params })
      .pipe(map((r) => r.data));
  }

  importCsv(file: File): Observable<ApiResponse<{ lines: PurchaseLine[]; total_lines: number; matched: number }>> {
    const form = new FormData();
    form.append('file', file);
    return this.http.post<ApiResponse<{ lines: PurchaseLine[]; total_lines: number; matched: number }>>(
      `${this.base}/import/purchase/csv`,
      form,
    );
  }

  /** Same uniqueness check used by purchase import. */
  checkInvoice(invoiceNo: string): Observable<{ exists: boolean; purchase: Record<string, unknown> | null }> {
    const params = new HttpParams().set('invoice_no', invoiceNo);
    return this.http
      .get<ApiResponse<{ exists: boolean; purchase: Record<string, unknown> | null }>>(
        `${this.base}/import/purchase/check-invoice`,
        { params },
      )
      .pipe(map((r) => r.data));
  }
}
