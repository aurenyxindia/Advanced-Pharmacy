import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { environment } from '../../../environments/environment';
import { ApiResponse } from '../models/auth.models';

@Injectable({ providedIn: 'root' })
export class ReportService {
  private readonly http = inject(HttpClient);

  get<T>(path: string, params: Record<string, string> = {}): Observable<T> {
    let httpParams = new HttpParams();
    for (const [k, v] of Object.entries(params)) {
      if (v) httpParams = httpParams.set(k, v);
    }
    return this.http
      .get<ApiResponse<T>>(`${environment.apiUrl}/reports/${path}`, { params: httpParams })
      .pipe(map((r) => r.data));
  }
}
