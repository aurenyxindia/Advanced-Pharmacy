import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { environment } from '../../../environments/environment';
import { ApiResponse } from '../models/auth.models';

export interface PublicBranch {
  id: number;
  name: string;
  city: string | null;
  business_name: string;
}

export interface PublicProduct {
  binding_id: number;
  product_name: string;
  schedule_category: string;
  available_qty: number;
  mrp: number;
}

export interface OrderTracking {
  order_no: string;
  customer_name: string;
  status: string;
  total_estimate: number;
  created_at: string;
  events: { status: string; note: string | null; created_at: string }[];
}

@Injectable({ providedIn: 'root' })
export class PublicService {
  private readonly http = inject(HttpClient);
  private readonly base = `${environment.apiUrl}/public`;

  branches(): Observable<PublicBranch[]> {
    return this.http.get<ApiResponse<PublicBranch[]>>(`${this.base}/branches`).pipe(map((r) => r.data));
  }

  catalog(branchId: number, q: string): Observable<PublicProduct[]> {
    const params = new HttpParams().set('branch_id', String(branchId)).set('q', q);
    return this.http.get<ApiResponse<PublicProduct[]>>(`${this.base}/catalog`, { params }).pipe(map((r) => r.data));
  }

  placeOrder(payload: unknown): Observable<ApiResponse<{ order_no: string; tracking_token: string; total_estimate: number }>> {
    return this.http.post<ApiResponse<{ order_no: string; tracking_token: string; total_estimate: number }>>(`${this.base}/orders`, payload);
  }

  track(value: string): Observable<OrderTracking> {
    const params = new HttpParams().set('token', value).set('order_no', value);
    return this.http.get<ApiResponse<OrderTracking>>(`${this.base}/orders/track`, { params }).pipe(map((r) => r.data));
  }
}
