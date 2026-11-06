import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { environment } from '../../../environments/environment';
import { ApiResponse } from '../models/auth.models';

export type PoType = 'Customise' | 'Auto' | 'Sales';

export interface PurchaseOrderItem {
  product_binding_id?: number | null;
  master_product_id?: number | null;
  product_name: string;
  pack_size?: string;
  product_category?: string;
  dosage_form?: string;
  mrp?: number;
  hsn_code?: string;
  manufacturer?: string;
  generic_name?: string;
  qty: number;
}

export interface PurchaseOrder {
  id: number;
  po_no: string;
  po_type: PoType;
  distributor_id: number | null;
  distributor_name: string;
  total_items: number;
  order_date: string;
  status: string;
  created_at: string;
  items?: PurchaseOrderItem[];
}

export interface ShortBookProduct {
  product_binding_id: number;
  master_product_id: number;
  product_name: string;
  pack_size: string;
  product_category: string;
  mrp: number;
  hsn_code: string;
  manufacturer: string;
  units_per_pack: number;
  reorder_level: number;
  available_qty: number;
  sold_by_month: Record<string, number>;
  total_sold_qty: number;
  distributor_id: number | null;
  distributor_name: string;
}

export interface ShortBook {
  months: string[];
  products: ShortBookProduct[];
}

/** Products sold in a date range for Sales-based PO (qty in packs). */
export interface SalesRangeProduct {
  product_binding_id: number;
  master_product_id: number;
  product_name: string;
  pack_size: string;
  product_category: string;
  mrp: number;
  hsn_code: string;
  manufacturer: string;
  units_per_pack: number;
  sold_qty: number;
  available_qty: number;
  suggested_qty: number;
  distributor_id: number | null;
  distributor_name: string;
}

export interface SalesRange {
  from_date: string;
  to_date: string;
  days: number;
  products: SalesRangeProduct[];
}

export interface PurchaseOrderPayload {
  po_type: PoType;
  distributor_id?: number | null;
  distributor_name?: string;
  items: PurchaseOrderItem[];
}

@Injectable({ providedIn: 'root' })
export class PurchaseOrderService {
  private readonly http = inject(HttpClient);
  private readonly base = `${environment.apiUrl}/purchase-orders`;

  list(filters: { search?: string; from_date?: string; to_date?: string; po_type?: string } = {}): Observable<PurchaseOrder[]> {
    let params = new HttpParams();
    for (const [k, v] of Object.entries(filters)) {
      if (v) params = params.set(k, v);
    }
    return this.http.get<ApiResponse<PurchaseOrder[]>>(this.base, { params }).pipe(map((r) => r.data ?? []));
  }

  get(id: number): Observable<PurchaseOrder> {
    return this.http.get<ApiResponse<PurchaseOrder>>(`${this.base}/${id}`).pipe(map((r) => r.data));
  }

  create(payload: PurchaseOrderPayload): Observable<ApiResponse<{ id: number; po_no: string }>> {
    return this.http.post<ApiResponse<{ id: number; po_no: string }>>(this.base, payload);
  }

  update(id: number, payload: PurchaseOrderPayload): Observable<ApiResponse<{ id: number; po_no: string }>> {
    return this.http.put<ApiResponse<{ id: number; po_no: string }>>(`${this.base}/${id}`, payload);
  }

  shortBook(): Observable<ShortBook> {
    return this.http.get<ApiResponse<ShortBook>>(`${this.base}/short-book`).pipe(map((r) => r.data));
  }

  salesRange(days: number): Observable<SalesRange> {
    const params = new HttpParams().set('days', String(Math.max(1, days || 1)));
    return this.http
      .get<ApiResponse<SalesRange>>(`${this.base}/sales-range`, { params })
      .pipe(map((r) => r.data));
  }

  pdfBlob(id: number): Observable<Blob> {
    return this.http.get(`${this.base}/${id}/pdf`, { responseType: 'blob' });
  }
}
