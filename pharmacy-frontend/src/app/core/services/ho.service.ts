import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { environment } from '../../../environments/environment';
import { ApiResponse } from '../models/auth.models';

export interface BranchPerformance {
  branch_id: number;
  code: string;
  name: string;
  is_head_office: number;
  sales: number;
  purchase: number;
  profit: number;
  skus: number;
  stock_value: number;
}

export interface PortfolioItem {
  master_product_id: number;
  product_name: string;
  schedule_category: string;
  branch_count: number;
  total_stock: number;
}

export interface BranchStockItem {
  binding_id: number;
  master_product_id: number;
  product_name: string;
  manufacturer_short: string | null;
  pack_size: string | null;
  schedule_category: string;
  available_qty: number;
}

@Injectable({ providedIn: 'root' })
export class HoService {
  private readonly http = inject(HttpClient);
  private readonly base = environment.apiUrl;

  branchPerformance(): Observable<{ branches: BranchPerformance[]; totals: Record<string, number> }> {
    return this.http
      .get<ApiResponse<{ branches: BranchPerformance[]; totals: Record<string, number> }>>(`${this.base}/ho/branch-performance`)
      .pipe(map((r) => r.data));
  }

  portfolio(): Observable<PortfolioItem[]> {
    return this.http.get<ApiResponse<PortfolioItem[]>>(`${this.base}/ho/portfolio`).pipe(map((r) => r.data));
  }

  /** Products with stock &gt; 0 at the given branch (for transfer picker). */
  branchStock(branchId: number, q = ''): Observable<BranchStockItem[]> {
    let params = new HttpParams().set('branch_id', String(branchId));
    if (q.trim()) params = params.set('q', q.trim());
    return this.http
      .get<ApiResponse<BranchStockItem[]>>(`${this.base}/ho/branch-stock`, { params })
      .pipe(map((r) => r.data ?? []));
  }

  transfers(): Observable<Record<string, unknown>[]> {
    return this.http.get<ApiResponse<Record<string, unknown>[]>>(`${this.base}/ho/transfers`).pipe(map((r) => r.data));
  }

  transferLog(id: number): Observable<Record<string, unknown>> {
    return this.http
      .get<ApiResponse<Record<string, unknown>>>(`${this.base}/ho/transfers/${id}`)
      .pipe(map((r) => r.data));
  }

  transfer(payload: unknown): Observable<ApiResponse<unknown>> {
    return this.http.post<ApiResponse<unknown>>(`${this.base}/ho/transfers`, payload);
  }
}
