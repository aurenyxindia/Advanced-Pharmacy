import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { environment } from '../../../environments/environment';
import { ApiResponse } from '../models/auth.models';

export interface RefillReminder {
  id: number;
  customer_name: string;
  phone: string | null;
  product_name: string | null;
  refill_date: string;
  status: 'pending' | 'notified' | 'completed' | 'cancelled';
  channel: string | null;
  notes: string | null;
}

export interface RefillSummary {
  overdue: number;
  today: number;
  upcoming: number;
}

@Injectable({ providedIn: 'root' })
export class RefillService {
  private readonly http = inject(HttpClient);
  private readonly base = environment.apiUrl;

  list(filter: { status?: string; due?: string } = {}): Observable<RefillReminder[]> {
    let params = new HttpParams();
    if (filter.status) params = params.set('status', filter.status);
    if (filter.due) params = params.set('due', filter.due);
    return this.http
      .get<ApiResponse<RefillReminder[]>>(`${this.base}/refills`, { params })
      .pipe(map((r) => r.data));
  }

  summary(): Observable<RefillSummary> {
    return this.http.get<ApiResponse<RefillSummary>>(`${this.base}/refills/summary`).pipe(map((r) => r.data));
  }

  create(payload: unknown): Observable<ApiResponse<{ id: number; refill_date: string }>> {
    return this.http.post<ApiResponse<{ id: number; refill_date: string }>>(`${this.base}/refills`, payload);
  }

  update(id: number, payload: unknown): Observable<ApiResponse<unknown>> {
    return this.http.put<ApiResponse<unknown>>(`${this.base}/refills/${id}`, payload);
  }
}
