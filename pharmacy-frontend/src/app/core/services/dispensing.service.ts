import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { environment } from '../../../environments/environment';
import { ApiResponse } from '../models/auth.models';

export interface Hospital {
  id: number;
  name: string;
  address?: string | null;
  phone?: string | null;
  is_default: number;
}

export interface DispensingSummary {
  dispensing_no: string;
  dispensed_at: string;
  hospital_name: string;
  patient_name: string;
  department: string;
  items: number;
  total_qty: number;
  amount: number;
  user_name: string;
  /** Product [batch] pairs for the history log. */
  batch_summary?: string;
}

export interface DispensingDetail {
  dispensing_no: string;
  dispensed_at: string;
  hospital_name: string;
  patient_name: string;
  department: string;
  remarks: string;
  user_name: string;
  amount: number;
  items: {
    product_name: string;
    pack_size: string;
    batch_no: string;
    expiry_date: string | null;
    qty: number;
    unit_mrp: number;
    discount_pct: number;
    amount: number;
  }[];
}

export interface DispensingPayload {
  hospital_id: number;
  patient_name?: string;
  department?: string;
  remarks?: string;
  lines: { batch_id: number; qty: number; discount_pct: number }[];
}

export interface DispensingResult {
  dispensing_no: string;
  items: number;
  total_qty: number;
  amount: number;
}

@Injectable({ providedIn: 'root' })
export class DispensingService {
  private readonly http = inject(HttpClient);
  private readonly base = `${environment.apiUrl}/dispensing`;

  hospitals(): Observable<Hospital[]> {
    const params = new HttpParams().set('per_page', '500');
    return this.http
      .get<ApiResponse<{ data: Hospital[] }>>(`${environment.apiUrl}/hospitals`, { params })
      .pipe(map((r) => r.data?.data ?? []));
  }

  list(filters: { from?: string; to?: string; hospital_id?: number | null; search?: string } = {}): Observable<DispensingSummary[]> {
    let params = new HttpParams();
    for (const [k, v] of Object.entries(filters)) {
      if (v !== undefined && v !== null && v !== '' && v !== 0) params = params.set(k, String(v));
    }
    return this.http.get<ApiResponse<DispensingSummary[]>>(this.base, { params }).pipe(map((r) => r.data ?? []));
  }

  reportPdf(filters: { from?: string; to?: string; hospital_id?: number | null; search?: string } = {}): Observable<Blob> {
    let params = new HttpParams();
    for (const [k, v] of Object.entries(filters)) {
      if (v !== undefined && v !== null && v !== '' && v !== 0) params = params.set(k, String(v));
    }
    return this.http.get(`${this.base}/report-pdf`, { params, responseType: 'blob' });
  }

  get(no: string): Observable<DispensingDetail> {
    const params = new HttpParams().set('no', no);
    return this.http.get<ApiResponse<DispensingDetail>>(`${this.base}/view`, { params }).pipe(map((r) => r.data));
  }

  create(payload: DispensingPayload): Observable<ApiResponse<DispensingResult>> {
    return this.http.post<ApiResponse<DispensingResult>>(this.base, payload);
  }
}
