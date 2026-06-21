import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { environment } from '../../../environments/environment';
import { ApiResponse } from '../models/auth.models';

export interface OpeningEntry {
  txn_date: string;
  product_name: string;
  manufacturer_short: string | null;
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
  writeOffExpired(): Observable<ApiResponse<{ batches: number; quantity: number }>> {
    return this.http.post<ApiResponse<{ batches: number; quantity: number }>>(`${this.base}/write-off-expired`, {});
  }
}
