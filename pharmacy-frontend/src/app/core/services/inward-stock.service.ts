import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { environment } from '../../../environments/environment';

export interface InwardStockRow {
  id: number;
  source_host: string;
  source_client_no: string;
  source_order_no: string;
  source_medical_name?: string | null;
  /** Purchase-log fields (mapped from Paperless share). */
  po_no?: string;
  invoice_no?: string;
  invoice_date?: string | null;
  distributor?: string | null;
  supplier_name?: string | null;
  paid?: number;
  balance?: number;
  target_order_no?: string | null;
  target_sale_id?: number | null;
  target_purchase_id?: number | null;
  status: 'pending' | 'approved' | 'rejected' | string;
  patient_name?: string | null;
  patient_contact?: string | null;
  doctor_name?: string | null;
  cash_credit?: string | null;
  order_date?: string | null;
  taxable: number;
  discount_amt: number;
  gst_amt: number;
  net_amt: number;
  item_count: number;
  warnings?: string[];
  created_at?: string;
  decided_at?: string | null;
}

export interface InwardStockDetail extends InwardStockRow {
  items: Record<string, unknown>[];
  payload?: Record<string, unknown>;
  bill_type?: string | null;
  bill_narration?: string | null;
  patient_no?: string | null;
  doctor_no?: string | null;
  patient_email?: string | null;
  patient_address?: string | null;
}

interface ApiWrap<T> {
  success: boolean;
  message?: string;
  data: T;
}

@Injectable({ providedIn: 'root' })
export class InwardStockService {
  private readonly http = inject(HttpClient);
  private readonly base = `${environment.apiUrl}/inward-stock`;

  list(status: string = 'pending'): Observable<InwardStockRow[]> {
    const params = new HttpParams().set('status', status);
    return this.http
      .get<ApiWrap<{ rows: InwardStockRow[] }>>(this.base, { params })
      .pipe(map((r) => r.data?.rows ?? []));
  }

  show(id: number): Observable<InwardStockDetail> {
    return this.http
      .get<ApiWrap<InwardStockDetail>>(`${this.base}/${id}`)
      .pipe(map((r) => r.data));
  }

  approve(id: number): Observable<Record<string, unknown>> {
    return this.http
      .post<ApiWrap<Record<string, unknown>>>(`${this.base}/${id}/approve`, {})
      .pipe(map((r) => r.data));
  }

  reject(id: number, reason = ''): Observable<Record<string, unknown>> {
    return this.http
      .post<ApiWrap<Record<string, unknown>>>(`${this.base}/${id}/reject`, { reason })
      .pipe(map((r) => r.data));
  }
}
