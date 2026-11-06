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

export interface OrderTrackingEvent {
  status: string;
  note: string | null;
  created_at: string;
}

export interface OrderTracking {
  mode?: 'detail' | 'list';
  order_no: string;
  customer_name: string;
  phone?: string | null;
  status: string;
  total_estimate: number;
  created_at: string;
  cancellable?: boolean;
  events: OrderTrackingEvent[];
}

export interface OrderListItem {
  order_no: string;
  customer_name: string;
  status: string;
  total_estimate: number;
  created_at: string;
}

export interface OrderTrackList {
  mode: 'list';
  phone: string;
  orders: OrderListItem[];
}

export type TrackResult = OrderTracking | OrderTrackList;

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

  /** Lookup by phone, order number, or tracking token. */
  track(value: string, by: 'phone' | 'ref' = 'ref'): Observable<TrackResult> {
    let params = new HttpParams();
    const v = value.trim();
    if (by === 'phone') {
      params = params.set('phone', v);
    } else {
      params = params.set('token', v).set('order_no', v);
    }
    return this.http.get<ApiResponse<TrackResult>>(`${this.base}/orders/track`, { params }).pipe(map((r) => r.data));
  }

  cancelOrder(payload: { order_no?: string; token?: string; phone?: string }): Observable<ApiResponse<{ order_no: string; status: string }>> {
    return this.http.post<ApiResponse<{ order_no: string; status: string }>>(`${this.base}/orders/cancel`, payload);
  }
}
