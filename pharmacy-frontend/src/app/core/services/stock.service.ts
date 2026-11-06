import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { environment } from '../../../environments/environment';
import { ApiResponse } from '../models/auth.models';

export interface OpeningEntry {
  batch_id: number;
  product_binding_id?: number;
  txn_date: string;
  product_name: string;
  manufacturer_short: string | null;
  pack_size?: string | null;
  units_per_pack?: number;
  batch_no: string | null;
  expiry_date: string | null;
  mrp: number | null;
  net_rate: number | null;
  quantity: number;
  note: string | null;
}

@Injectable({ providedIn: 'root' })
export class StockService {
  private readonly http = inject(HttpClient);
  private readonly base = `${environment.apiUrl}/stock`;

  openingList(): Observable<OpeningEntry[]> {
    return this.http.get<ApiResponse<OpeningEntry[]>>(`${this.base}/opening`).pipe(map((r) => r.data));
  }
  openingStore(payload: unknown): Observable<ApiResponse<{ binding_id: number }>> {
    return this.http.post<ApiResponse<{ binding_id: number }>>(`${this.base}/opening`, payload);
  }
  openingBulkSave(payload: {
    opening_date?: string;
    items: Array<{
      master_product_id: number;
      batch_no: string;
      expiry_date?: string;
      shelf_location?: string;
      qty: number;
      mrp: number;
      purchase_rate: number;
      gst_rate?: number;
      hsn_code?: string;
      pack_size?: string;
      units_per_pack?: number;
    }>;
  }): Observable<ApiResponse<{ ref_no: string; lines_saved: number; total_amount: number }>> {
    return this.http.post<ApiResponse<{ ref_no: string; lines_saved: number; total_amount: number }>>(
      `${this.base}/opening/bulk`, payload,
    );
  }
  openingMatchProducts(): Observable<Array<Record<string, unknown>>> {
    return this.http
      .get<ApiResponse<Array<Record<string, unknown>>>>(`${this.base}/opening/match-products`)
      .pipe(map((r) => r.data));
  }
  openingUpdate(batchId: number, payload: unknown): Observable<ApiResponse<{ batch_id: number }>> {
    return this.http.put<ApiResponse<{ batch_id: number }>>(`${this.base}/opening/${batchId}`, payload);
  }
  openingDelete(batchId: number): Observable<ApiResponse<{ batch_id: number }>> {
    return this.http.delete<ApiResponse<{ batch_id: number }>>(`${this.base}/opening/${batchId}`);
  }
  writeOffExpired(): Observable<ApiResponse<{ batches: number; quantity: number }>> {
    return this.http.post<ApiResponse<{ batches: number; quantity: number }>>(`${this.base}/write-off-expired`, {});
  }

  adjustmentBatches(search = ''): Observable<StockAdjustmentBatch[]> {
    let params = new HttpParams();
    if (search.trim()) params = params.set('search', search.trim());
    return this.http
      .get<ApiResponse<StockAdjustmentBatch[]>>(`${this.base}/adjustment/batches`, { params })
      .pipe(map((r) => r.data));
  }

  adjustmentSave(payload: {
    adjustment_type: string;
    remarks?: string;
    lines: Array<{
      batch_id: number;
      product_binding_id?: number;
      system_qty: number;
      system_unit_qty: number;
      physical_qty: number;
      physical_unit_qty: number;
      pack_size?: string;
      units_per_pack?: number;
      pack_changed?: boolean;
      reason?: string;
    }>;
  }): Observable<ApiResponse<{ adjustment_no: string; lines_saved: number; errors: string[] }>> {
    return this.http.post<ApiResponse<{ adjustment_no: string; lines_saved: number; errors: string[] }>>(
      `${this.base}/adjustment`,
      payload,
    );
  }
}

export interface StockAdjustmentBatch {
  batch_id: number;
  product_binding_id: number;
  p_name: string;
  batch_no: string;
  pack_size: string | null;
  units_per_pack: number;
  expiry: string | null;
  shelf: string | null;
  m_name: string | null;
  system_unit_qty: number;
  system_qty: number;
  current_unit_qty?: number;
  current_qty?: number;
  mrp?: number;
  purchase_rate?: number;
}
