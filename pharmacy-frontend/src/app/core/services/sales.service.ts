import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { environment } from '../../../environments/environment';
import { ApiResponse } from '../models/auth.models';
import { Paginated } from './crud.service';

export interface SaleProduct {
  binding_id: number;
  product_name: string;
  manufacturer?: string | null;
  manufacturer_short?: string | null;
  schedule_category: string;
  gst_rate: number;
  master_product_id: number;
  available_qty: number;
  mrp: number | null;
  units_per_pack?: number;
  allow_loose?: number;
  shelf_location?: string | null;
  pack_size?: string | null;
  generic_name?: string | null;
}

export interface BatchOption {
  batch_id: number;
  batch_no: string;
  expiry_date: string | null;
  mrp: number;
  unit_mrp: number;
  ptr: number;
  pts: number;
  purchase_rate: number;
  quantity: number;
  packs: number;
  shelf_location: string | null;
  units_per_pack: number;
}

export interface Substitute {
  id: number;
  name: string;
  manufacturer: string | null;
  schedule_category: string;
  default_gst_rate: number;
  generic_name: string | null;
  binding_id: number | null;
  available_qty: number;
}

export interface SaleItemPayload {
  product_binding_id: number;
  schedule_category: string;
  quantity: number;
  rate: number;
  discount_pct: number;
  batch_id?: number | null;
  unit_type?: string;
  pack?: string | null;
  shelf?: string | null;
}

export interface SalePayload {
  counter?: string;
  sale_type?: string;
  bill_mode?: string;
  rate_type?: string;
  customer_name?: string;
  customer_id?: number | null;
  patient_name?: string;
  contact_no?: string;
  email?: string;
  address?: string;
  narration?: string;
  reminder_days?: number | null;
  doctor_name?: string;
  payment_mode?: string;
  bill_discount_pct?: number;
  cd_disc_pct?: number;
  is_interstate?: number;
  gst_inclusive?: number;
  is_chronic?: number;
  ipd_admission_id?: number | null;
  prescription_id?: number | null;
  items: SaleItemPayload[];
}

export interface CustomerLookup {
  id: number;
  name: string;
  phone: string | null;
  email: string | null;
  address: string | null;
  is_chronic: number;
}

export interface SaleResult {
  id: number;
  invoice_no: string;
  net_amount: number;
  gst_amount: number;
  cd_amount?: number;
  cgst?: number;
  sgst?: number;
  igst?: number;
  profit_amount: number;
  is_locked: boolean;
}

@Injectable({ providedIn: 'root' })
export class SalesService {
  private readonly http = inject(HttpClient);
  private readonly base = environment.apiUrl;

  searchProducts(q: string, genericId?: number): Observable<SaleProduct[]> {
    let params = new HttpParams().set('q', q);
    if (genericId) params = params.set('generic_id', String(genericId));
    return this.http
      .get<ApiResponse<SaleProduct[]>>(`${this.base}/sales/search-products`, { params })
      .pipe(map((r) => r.data));
  }

  batches(bindingId: number): Observable<BatchOption[]> {
    return this.http
      .get<ApiResponse<BatchOption[]>>(`${this.base}/bound-products/${bindingId}/batches`)
      .pipe(map((r) => r.data));
  }

  scan(code: string): Observable<SaleProduct & { batch_id: number }> {
    const params = new HttpParams().set('code', code);
    return this.http
      .get<ApiResponse<SaleProduct & { batch_id: number }>>(`${this.base}/sales/scan`, { params })
      .pipe(map((r) => r.data));
  }

  substitutes(bindingId: number): Observable<Substitute[]> {
    return this.http
      .get<ApiResponse<Substitute[]>>(`${this.base}/bound-products/${bindingId}/substitutes`)
      .pipe(map((r) => r.data));
  }

  create(payload: SalePayload): Observable<ApiResponse<SaleResult>> {
    return this.http.post<ApiResponse<SaleResult>>(`${this.base}/sales`, payload);
  }

  /** Counter stock-in: create a quick one-line purchase and return the sellable product. */
  quickPurchase(payload: {
    product_name: string; batch_no?: string; quantity: number; mrp: number;
    purchase_rate?: number; gst_rate?: number; expiry_date?: string;
  }): Observable<SaleProduct & { batch_id?: number }> {
    return this.http
      .post<ApiResponse<SaleProduct & { batch_id?: number }>>(`${this.base}/sales/quick-purchase`, payload)
      .pipe(map((r) => r.data));
  }

  /** Look up an existing patient by mobile number. Returns null when not found. */
  lookupCustomer(phone: string): Observable<CustomerLookup | null> {
    const params = new HttpParams().set('phone', phone);
    return this.http
      .get<ApiResponse<CustomerLookup | null>>(`${this.base}/customers/lookup`, { params })
      .pipe(map((r) => r.data));
  }

  list(opts: { search?: string; page?: number } = {}): Observable<Paginated<Record<string, unknown>>> {
    let params = new HttpParams();
    if (opts.search) params = params.set('search', opts.search);
    if (opts.page) params = params.set('page', String(opts.page));
    return this.http
      .get<ApiResponse<Paginated<Record<string, unknown>>>>(`${this.base}/sales`, { params })
      .pipe(map((r) => r.data));
  }

  uploadPrescription(file: File | Blob, source: 'upload' | 'camera', doctorName?: string): Observable<ApiResponse<{ id: number; file_path: string }>> {
    const form = new FormData();
    form.append('file', file, source === 'camera' ? 'capture.png' : (file as File).name ?? 'prescription');
    form.append('source', source);
    if (doctorName) form.append('doctor_name', doctorName);
    return this.http.post<ApiResponse<{ id: number; file_path: string }>>(`${this.base}/prescriptions`, form);
  }

  invoiceBlob(saleId: number): Observable<Blob> {
    return this.http.get(`${this.base}/sales/${saleId}/invoice`, { responseType: 'blob' });
  }
}
