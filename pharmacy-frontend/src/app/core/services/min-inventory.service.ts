import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { environment } from '../../../environments/environment';
import { ApiResponse } from '../models/auth.models';

/** Quantities are loose units (same as stock). min_qty is the effective minimum (custom or default). */
export interface MinInventoryRow {
  id: number;
  product_name: string;
  pack_size: string;
  units_per_pack: number;
  available_qty: number;
  min_qty: number;
  custom_min: number;
  is_custom: boolean;
  is_low: boolean;
  sold_qty: number;
}

export interface ShortStock {
  default_min: number;
  sales_months: number;
  total: number;
  products: MinInventoryRow[];
}

@Injectable({ providedIn: 'root' })
export class MinInventoryService {
  private readonly http = inject(HttpClient);
  private readonly base = `${environment.apiUrl}/min-inventory`;

  triggers(): Observable<MinInventoryRow[]> {
    return this.http.get<ApiResponse<MinInventoryRow[]>>(this.base).pipe(map((r) => r.data ?? []));
  }

  /** Short stock, most-sold first; limit = 0 → all. */
  alerts(limit = 0): Observable<ShortStock> {
    const params = new HttpParams().set('limit', String(limit));
    return this.http
      .get<ApiResponse<ShortStock>>(`${this.base}/alerts`, { params })
      .pipe(map((r) => r.data ?? { default_min: 15, sales_months: 4, total: 0, products: [] }));
  }

  products(search = ''): Observable<MinInventoryRow[]> {
    const params = new HttpParams().set('search', search);
    return this.http
      .get<ApiResponse<MinInventoryRow[]>>(`${this.base}/products`, { params })
      .pipe(map((r) => r.data ?? []));
  }

  save(productBindingId: number, minQty: number): Observable<ApiResponse<MinInventoryRow>> {
    return this.http.post<ApiResponse<MinInventoryRow>>(this.base, {
      product_binding_id: productBindingId,
      min_qty: minQty,
    });
  }

  /** Reset to the universal default minimum. */
  remove(productBindingId: number): Observable<ApiResponse<null>> {
    return this.http.delete<ApiResponse<null>>(`${this.base}/${productBindingId}`);
  }
}
