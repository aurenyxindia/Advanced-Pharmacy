import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { environment } from '../../../environments/environment';
import { ApiResponse } from '../models/auth.models';

export interface Admission {
  id: number;
  admission_no: string;
  patient_name: string;
  contact_no: string | null;
  doctor_name: string | null;
  ward: string | null;
  bed_no: string | null;
  status: 'admitted' | 'discharged';
  admitted_at: string;
  discharged_at: string | null;
  billed?: number;
  paid?: number;
  balance?: number;
}

export interface AdmissionDetail extends Admission {
  bills: { id: number; invoice_no: string; invoice_date: string; net_amount: number; gst_amount: number }[];
  payments: { id: number; amount: number; mode: string; note: string | null; paid_at: string }[];
  totals: { billed: number; paid: number; balance: number };
}

@Injectable({ providedIn: 'root' })
export class IpdService {
  private readonly http = inject(HttpClient);
  private readonly base = `${environment.apiUrl}/ipd/admissions`;

  list(status?: string): Observable<Admission[]> {
    let params = new HttpParams();
    if (status) params = params.set('status', status);
    return this.http.get<ApiResponse<Admission[]>>(this.base, { params }).pipe(map((r) => r.data));
  }
  get(id: number): Observable<AdmissionDetail> {
    return this.http.get<ApiResponse<AdmissionDetail>>(`${this.base}/${id}`).pipe(map((r) => r.data));
  }
  admit(payload: unknown): Observable<ApiResponse<{ id: number }>> {
    return this.http.post<ApiResponse<{ id: number }>>(this.base, payload);
  }
  payment(id: number, payload: unknown): Observable<ApiResponse<unknown>> {
    return this.http.post<ApiResponse<unknown>>(`${this.base}/${id}/payment`, payload);
  }
  discharge(id: number): Observable<ApiResponse<{ billed: number; paid: number; balance: number }>> {
    return this.http.post<ApiResponse<{ billed: number; paid: number; balance: number }>>(`${this.base}/${id}/discharge`, {});
  }
}
