import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { environment } from '../../../environments/environment';
import { ApiResponse } from '../models/auth.models';

export interface Paginated<T> {
  data: T[];
  total: number;
  page: number;
  per_page: number;
}

/**
 * Generic REST resource client. Instantiate per-resource via `forPath`.
 */
@Injectable({ providedIn: 'root' })
export class CrudService {
  private readonly http = inject(HttpClient);

  list<T>(
    path: string,
    opts: {
      search?: string;
      page?: number;
      perPage?: number;
      filters?: Record<string, string | number | undefined | null>;
    } = {},
  ): Observable<Paginated<T>> {
    let params = new HttpParams();
    if (opts.search) params = params.set('search', opts.search);
    if (opts.page) params = params.set('page', String(opts.page));
    if (opts.perPage) params = params.set('per_page', String(opts.perPage));
    if (opts.filters) {
      for (const [key, value] of Object.entries(opts.filters)) {
        if (value === undefined || value === null || value === '') continue;
        params = params.set(key, String(value));
      }
    }

    return this.http
      .get<ApiResponse<Paginated<T>>>(`${environment.apiUrl}/${path}`, { params })
      .pipe(map((r) => r.data));
  }

  get<T>(path: string, id: number): Observable<T> {
    return this.http
      .get<ApiResponse<T>>(`${environment.apiUrl}/${path}/${id}`)
      .pipe(map((r) => r.data));
  }

  create<T>(path: string, payload: unknown): Observable<ApiResponse<T>> {
    return this.http.post<ApiResponse<T>>(`${environment.apiUrl}/${path}`, payload);
  }

  update<T>(path: string, id: number, payload: unknown): Observable<ApiResponse<T>> {
    return this.http.put<ApiResponse<T>>(`${environment.apiUrl}/${path}/${id}`, payload);
  }

  remove<T>(path: string, id: number): Observable<ApiResponse<T>> {
    return this.http.delete<ApiResponse<T>>(`${environment.apiUrl}/${path}/${id}`);
  }

  post<T>(path: string, payload: unknown): Observable<ApiResponse<T>> {
    return this.http.post<ApiResponse<T>>(`${environment.apiUrl}/${path}`, payload);
  }

  fetch<T>(path: string): Observable<T> {
    return this.http.get<ApiResponse<T>>(`${environment.apiUrl}/${path}`).pipe(map((r) => r.data));
  }
}
