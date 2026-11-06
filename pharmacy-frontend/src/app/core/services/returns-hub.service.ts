import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { environment } from '../../../environments/environment';
import { ApiResponse } from '../models/auth.models';

@Injectable({ providedIn: 'root' })
export class ReturnDeductionApi {
  private readonly http = inject(HttpClient);
  private readonly base = `${environment.apiUrl}/return-deductions`;

  list(): Observable<{ id: number; return_months: number; deduction_pct: number }[]> {
    return this.http.get<ApiResponse<any[]>>(this.base).pipe(map((r) => r.data ?? []));
  }

  save(payload: { return_months: number; deduction_pct: number }): Observable<unknown> {
    return this.http.post(this.base, payload);
  }
}

@Injectable({ providedIn: 'root' })
export class RetailSalesReturnApi {
  private readonly http = inject(HttpClient);
  private readonly base = `${environment.apiUrl}/retail-sales-returns`;

  sales(search = '', limit = 30): Observable<any[]> {
    let params = new HttpParams().set('limit', String(limit));
    if (search) params = params.set('search', search);
    return this.http.get<ApiResponse<any[]>>(`${this.base}/sales`, { params }).pipe(map((r) => r.data ?? []));
  }

  saleDetails(id: number): Observable<any> {
    return this.http.get<ApiResponse<any>>(`${this.base}/sales/${id}`).pipe(map((r) => r.data));
  }

  save(payload: any): Observable<{ id: number; return_no: string }> {
    return this.http
      .post<ApiResponse<{ id: number; return_no: string }>>(this.base, payload)
      .pipe(map((r) => r.data));
  }

  /** External return — customer had no sale bill in this software. */
  saveManual(payload: any): Observable<{ id: number; return_no: string; return_total?: number }> {
    return this.http
      .post<ApiResponse<{ id: number; return_no: string; return_total?: number }>>(`${this.base}/manual`, payload)
      .pipe(map((r) => r.data));
  }

  history(search = ''): Observable<any[]> {
    let params = new HttpParams();
    if (search) params = params.set('search', search);
    return this.http.get<ApiResponse<any[]>>(`${this.base}/history`, { params }).pipe(map((r) => r.data ?? []));
  }

  historyDetails(id: number): Observable<any> {
    return this.http.get<ApiResponse<any>>(`${this.base}/history/${id}`).pipe(map((r) => r.data));
  }

  historyPdfBlob(id: number): Observable<Blob> {
    return this.http.get(`${this.base}/history/${id}/pdf`, { responseType: 'blob' });
  }
}

@Injectable({ providedIn: 'root' })
export class WholesaleCreditNoteApi {
  private readonly http = inject(HttpClient);
  private readonly base = `${environment.apiUrl}/credit-notes`;

  list(search = ''): Observable<any[]> {
    let params = new HttpParams();
    if (search) params = params.set('search', search);
    return this.http
      .get<ApiResponse<{ data: any[] }>>(this.base, { params })
      .pipe(map((r) => r.data?.data ?? []));
  }

  get(id: number): Observable<any> {
    return this.http.get<ApiResponse<any>>(`${this.base}/${id}`).pipe(map((r) => r.data));
  }

  pdfBlob(id: number): Observable<Blob> {
    return this.http.get(`${this.base}/${id}/pdf`, { responseType: 'blob' });
  }

  customers(): Observable<any[]> {
    return this.http.get<ApiResponse<any[]>>(`${this.base}/customers`).pipe(map((r) => r.data ?? []));
  }

  products(customer: string): Observable<{ value: string; label: string }[]> {
    const params = new HttpParams().set('customer', customer);
    return this.http
      .get<ApiResponse<{ value: string; label: string }[]>>(`${this.base}/return-products`, { params })
      .pipe(map((r) => r.data ?? []));
  }

  batches(customer: string, productName: string): Observable<any[]> {
    const params = new HttpParams().set('customer', customer).set('product_name', productName);
    return this.http.get<ApiResponse<any[]>>(`${this.base}/return-batches`, { params }).pipe(map((r) => r.data ?? []));
  }

  openForParty(party: string): Observable<{ note_no: string; label: string; amount: number }[]> {
    const params = new HttpParams().set('party', party);
    return this.http
      .get<ApiResponse<{ note_no: string; label: string; amount: number }[]>>(`${this.base}/open`, { params })
      .pipe(map((r) => r.data ?? []));
  }

  save(payload: any): Observable<{ id: number; note_no: string }> {
    return this.http
      .post<ApiResponse<{ id: number; note_no: string }>>(`${this.base}/return`, payload)
      .pipe(map((r) => r.data));
  }

  update(id: number, payload: any): Observable<{ id: number; note_no: string }> {
    return this.http
      .put<ApiResponse<{ id: number; note_no: string }>>(`${this.base}/${id}/return`, payload)
      .pipe(map((r) => r.data));
  }
}
