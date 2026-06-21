import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, map } from 'rxjs';
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
  hsn_code: string | null;
  pack_size: string | null;
}

export interface PurchaseLine {
  master_product_id?: number;
  product_name: string;
  batch_no: string;
  expiry_date: string;
  quantity: number;
  free_quantity: number;
  purchase_rate: number;
  mrp: number;
  discount_pct: number;
  gst_rate: number;
  new_product?: Record<string, unknown>;
}

export interface PurchasePayload {
  invoice_no: string;
  invoice_date: string;
  supplier_name?: string;
  supplier_id?: number | null;
  bill_discount_pct?: number;
  bill_discount_amount?: number;
  items: PurchaseLine[];
}

@Injectable({ providedIn: 'root' })
export class PurchaseService {
  private readonly http = inject(HttpClient);
  private readonly base = environment.apiUrl;

  searchProducts(q: string): Observable<ProductSearchResult[]> {
    const params = new HttpParams().set('q', q).set('limit', '12');
    return this.http
      .get<ApiResponse<ProductSearchResult[]>>(`${this.base}/products/search`, { params })
      .pipe(map((r) => r.data));
  }

  create(payload: PurchasePayload): Observable<ApiResponse<{ id: number; net_amount: number }>> {
    return this.http.post<ApiResponse<{ id: number; net_amount: number }>>(`${this.base}/purchases`, payload);
  }

  list(opts: { search?: string; page?: number } = {}): Observable<Paginated<Record<string, unknown>>> {
    let params = new HttpParams();
    if (opts.search) params = params.set('search', opts.search);
    if (opts.page) params = params.set('page', String(opts.page));
    return this.http
      .get<ApiResponse<Paginated<Record<string, unknown>>>>(`${this.base}/purchases`, { params })
      .pipe(map((r) => r.data));
  }

  get(id: number): Observable<Record<string, unknown>> {
    return this.http
      .get<ApiResponse<Record<string, unknown>>>(`${this.base}/purchases/${id}`)
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
}
