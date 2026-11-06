import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { environment } from '../../../environments/environment';
import { ApiResponse } from '../models/auth.models';

export interface ReturnNoteLine {
  p_name: string;
  product_name?: string;
  batch_no: string;
  batch_id: number;
  product_binding_id: number;
  saleQty: number;
  stock_qty?: number;
  net_rate: number;
  gst_per: number;
  exp_date?: string | null;
  return_qty: number;
  reason: string;
  taxable: number;
  tax: number;
  cgst: number;
  sgst: number;
  igst: number;
  netTotal: number;
  invoice_no?: string;
  invoice_date?: string | null;
  hsn?: string;
  pack_size?: string;
  po_no?: string;
  purchase_id?: number;
  distributor_name?: string;
  state?: string;
  d_cont?: string;
  d_mail?: string;
  d_gst?: string;
  d_add?: string;
  d_no?: string | number;
}

export interface ReturnNote {
  id: number;
  note_no: string;
  order_no?: string;
  party_name: string;
  distributor_name?: string;
  note_date: string;
  order_date?: string;
  taxable: number;
  tax: number;
  gst_amount?: number;
  netTotal: number;
  amount?: number;
  deduction_status: string;
  applied_po_no?: string | null;
  applied_on?: string | null;
  editable?: boolean;
  d_cont?: string;
  d_mail?: string;
  d_gst?: string;
  state?: string;
  products?: ReturnNoteLine[];
}

export interface ReturnBatchOpt {
  batch_id: number;
  product_binding_id: number;
  batch_no: string;
  stock_qty: number;
  wAvailableQty?: number;
  expiry?: string | null;
  exp_date?: string | null;
  net_rate: number;
  hsn?: string;
  pack_size?: string;
  gst_per: number;
  p_name?: string;
}

export interface ReturnSupplierOpt {
  distributor_name: string;
  distributor_id?: number | null;
  invoice_no?: string;
  invoice_date?: string | null;
  purchase_id?: number;
  po_no?: string;
  purchase_rate?: number;
  gst_rate?: number;
  product_binding_id?: number;
  d_cont?: string;
  d_mail?: string;
  d_add?: string;
  d_gst?: string;
  state?: string;
  d_no?: string | number;
}

@Injectable({ providedIn: 'root' })
export class ReturnNoteService {
  private readonly http = inject(HttpClient);
  private readonly base = `${environment.apiUrl}/debit-notes`;

  list(search = ''): Observable<ReturnNote[]> {
    let params = new HttpParams();
    if (search) params = params.set('search', search);
    return this.http
      .get<ApiResponse<{ data: ReturnNote[] }>>(`${this.base}`, { params })
      .pipe(map((r) => r.data?.data ?? []));
  }

  get(id: number): Observable<ReturnNote> {
    return this.http.get<ApiResponse<ReturnNote>>(`${this.base}/${id}`).pipe(map((r) => r.data));
  }

  pdfBlob(id: number): Observable<Blob> {
    return this.http.get(`${this.base}/${id}/pdf`, { responseType: 'blob' });
  }

  products(): Observable<{ value: string; label: string }[]> {
    return this.http
      .get<ApiResponse<{ value: string; label: string }[]>>(`${this.base}/return-products`)
      .pipe(map((r) => r.data ?? []));
  }

  batches(productName: string): Observable<ReturnBatchOpt[]> {
    const params = new HttpParams().set('product_name', productName);
    return this.http
      .get<ApiResponse<ReturnBatchOpt[]>>(`${this.base}/return-batches`, { params })
      .pipe(map((r) => r.data ?? []));
  }

  suppliers(productName: string, batchNo: string): Observable<ReturnSupplierOpt[]> {
    const params = new HttpParams().set('product_name', productName).set('batch_no', batchNo);
    return this.http
      .get<ApiResponse<ReturnSupplierOpt[]>>(`${this.base}/return-suppliers`, { params })
      .pipe(map((r) => r.data ?? []));
  }

  openForParty(party: string): Observable<{ note_no: string; label: string; amount: number }[]> {
    const params = new HttpParams().set('party', party);
    return this.http
      .get<ApiResponse<{ note_no: string; label: string; amount: number }[]>>(`${this.base}/open`, { params })
      .pipe(map((r) => r.data ?? []));
  }

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  save(payload: Record<string, any>): Observable<{ id: number; note_no: string }> {
    return this.http
      .post<ApiResponse<{ id: number; note_no: string }>>(`${this.base}/return`, payload)
      .pipe(map((r) => r.data));
  }

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  update(id: number, payload: Record<string, any>): Observable<{ id: number; note_no: string }> {
    return this.http
      .put<ApiResponse<{ id: number; note_no: string }>>(`${this.base}/${id}/return`, payload)
      .pipe(map((r) => r.data));
  }
}
