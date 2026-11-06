import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { environment } from '../../../environments/environment';
import { ApiResponse } from '../models/auth.models';
import { Paginated } from './crud.service';

@Injectable({ providedIn: 'root' })
export class OrderService {
  private readonly http = inject(HttpClient);
  private readonly base = environment.apiUrl;

  list(filter: { status?: string; search?: string; perPage?: number } = {}): Observable<Paginated<Record<string, unknown>>> {
    let params = new HttpParams();
    if (filter.status) params = params.set('status', filter.status);
    if (filter.search) params = params.set('search', filter.search);
    if (filter.perPage) params = params.set('per_page', String(filter.perPage));
    return this.http
      .get<ApiResponse<Paginated<Record<string, unknown>>>>(`${this.base}/orders`, { params })
      .pipe(map((r) => r.data));
  }

  get(id: number): Observable<Record<string, unknown>> {
    return this.http.get<ApiResponse<Record<string, unknown>>>(`${this.base}/orders/${id}`).pipe(map((r) => r.data));
  }

  updateStatus(id: number, payload: unknown): Observable<ApiResponse<unknown>> {
    return this.http.put<ApiResponse<unknown>>(`${this.base}/orders/${id}/status`, payload);
  }
}
