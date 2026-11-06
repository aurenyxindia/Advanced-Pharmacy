import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { environment } from '../../../environments/environment';
import { ApiResponse } from '../models/auth.models';

@Injectable({ providedIn: 'root' })
export class PaymentsHubService {
  private readonly http = inject(HttpClient);
  private readonly base = `${environment.apiUrl}/payments`;

  distributorDues(): Observable<any[]> {
    return this.http.get<ApiResponse<any[]>>(`${this.base}/dues/distributors`).pipe(map((r) => r.data ?? []));
  }

  customerDues(): Observable<any[]> {
    return this.http.get<ApiResponse<any[]>>(`${this.base}/dues/customers`).pipe(map((r) => r.data ?? []));
  }

  wholesaleCustomerDues(): Observable<any[]> {
    return this.http
      .get<ApiResponse<any[]>>(`${this.base}/dues/wholesale-customers`)
      .pipe(map((r) => r.data ?? []));
  }

  settleDistributor(payload: any): Observable<any> {
    return this.http.post<ApiResponse<any>>(`${this.base}/settle/distributor`, payload).pipe(map((r) => r.data));
  }

  settleCustomer(payload: any): Observable<any> {
    return this.http.post<ApiResponse<any>>(`${this.base}/settle/customer`, payload).pipe(map((r) => r.data));
  }

  settleWholesaleCustomer(payload: any): Observable<any> {
    return this.http
      .post<ApiResponse<any>>(`${this.base}/settle/wholesale-customer`, payload)
      .pipe(map((r) => r.data));
  }

  history(partyType: string, party = '', phone = ''): Observable<any[]> {
    let params = new HttpParams().set('party_type', partyType);
    if (party) params = params.set('party', party);
    if (phone) params = params.set('phone', phone);
    return this.http.get<ApiResponse<any[]>>(`${this.base}/history`, { params }).pipe(map((r) => r.data ?? []));
  }

  /** Opens authenticated HTML payment receipt in a new tab. */
  printAllocation(id: number): void {
    this.http
      .get(`${this.base}/allocations/${id}/print`, { responseType: 'text' })
      .subscribe({
        next: (html) => {
          const blob = new Blob([html], { type: 'text/html' });
          const url = URL.createObjectURL(blob);
          window.open(url, '_blank');
          setTimeout(() => URL.revokeObjectURL(url), 60_000);
        },
      });
  }

  vouchers(search = ''): Observable<any[]> {
    let params = new HttpParams().set('per_page', '100');
    if (search) params = params.set('search', search);
    return this.http
      .get<ApiResponse<{ data: any[] }>>(this.base, { params })
      .pipe(map((r) => (r.data as any)?.data ?? (Array.isArray(r.data) ? r.data : [])));
  }

  saveVoucher(payload: any): Observable<any> {
    return this.http.post(this.base, payload);
  }

  deleteVoucher(id: number): Observable<any> {
    return this.http.delete(`${this.base}/${id}`);
  }
}
