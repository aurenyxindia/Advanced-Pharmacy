import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { environment } from '../../../environments/environment';
import { ApiResponse } from '../models/auth.models';
import { Paginated } from './crud.service';

export interface SaleProduct {
  binding_id: number;
  product_name: string;
  schedule_category: string;
  gst_rate: number;
  master_product_id: number;
  available_qty: number;
  mrp: number | null;
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
}

export interface SalePayload {
  customer_name?: string;
  customer_id?: number | null;
  doctor_name?: string;
  payment_mode?: string;
  bill_discount_pct?: number;
  prescription_id?: number | null;
  items: SaleItemPayload[];
}

export interface SaleResult {
  id: number;
  invoice_no: string;
  net_amount: number;
  gst_amount: number;
  profit_amount: number;
  is_locked: boolean;
}

@Injectable({ providedIn: 'root' })
export class SalesService {
  private readonly http = inject(HttpClient);
  private readonly base = environment.apiUrl;

  searchProducts(q: string): Observable<SaleProduct[]> {
    const params = new HttpParams().set('q', q);
    return this.http
      .get<ApiResponse<SaleProduct[]>>(`${this.base}/sales/search-products`, { params })
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
