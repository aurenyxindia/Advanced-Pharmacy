import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { environment } from '../../../environments/environment';
import { ApiResponse } from '../models/auth.models';

export interface MrStats {
  visits_today: number;
  orders_today: number;
  order_value_today: number;
  stock_checks_today: number;
  open_outstanding: number;
  customers: number;
}

export interface MrVisit {
  id?: number;
  mr_name?: string;
  visit_date: string;
  doctor_name: string;
  clinic_name: string;
  specialty: string;
  area_name: string;
  contact_no: string;
  products_discussed: string;
  indications: string;
  dosage_notes: string;
  clinical_info: string;
  availability_notes: string;
  competitor_info: string;
  next_visit_date: string;
  outcome: string;
  remarks: string;
}

export interface MrStockRow {
  binding_id: number;
  batch_id: number;
  p_name: string;
  pack_size: string;
  batch_no: string;
  expiry_date: string | null;
  qty: number;
  units_qty: number;
  units_per_pack: number;
  mrp: number;
  purchase_rate: number;
  ptr: number;
  pts: number;
  sale_rate: number;
  hsn: string;
  gst: number;
  m_name: string;
  category: string;
  shelf: string;
  scheme_pct?: number;
  discount_pct?: number;
}

export interface MrRateHistory {
  date: string;
  ref_no: string;
  p_name: string;
  batch_no: string;
  qty: number;
  sale_rate: number;
  source: string;
}

export interface MrOrderItem {
  product_binding_id: number | null;
  batch_id: number | null;
  product_name: string;
  pack_size: string;
  batch_no: string;
  expiry_date: string | null;
  qty: number;
  mrp: number;
  rate: number;
  amount: number;
  stock_qty: number | null;
  gst: number;
  hsn: string;
  m_name: string;
  previous_rate?: number;
}

export interface MrOrder {
  id: number;
  order_no: string;
  order_date: string;
  mr_name: string | null;
  customer_id: number | null;
  customer_name: string;
  customer_contact: string | null;
  customer_address: string | null;
  customer_dl_no: string | null;
  expected_delivery_date: string | null;
  delivery_type: string | null;
  order_type: string;
  is_dummy_bill: number;
  is_quotation: number;
  total_items: number;
  total_qty: number;
  total_amount: number;
  status: string;
  remarks: string | null;
  sale_id: number | null;
  sale_invoice_no: string | null;
}

export interface MrOrderPayload {
  id?: number;
  order_date: string;
  customer_id: number | null;
  customer_name: string;
  customer_contact: string;
  customer_address: string;
  customer_dl_no: string;
  expected_delivery_date: string;
  delivery_type: string;
  order_type: string;
  remarks: string;
  status: 'Order' | 'Quotation';
  items: MrOrderItem[];
}

export interface MrStockFollowup {
  id?: number;
  followup_date: string;
  location_type: string;
  location_name: string;
  product_binding_id?: number | null;
  product_name: string;
  available_qty: number | string | null;
  near_expiry_qty: number | string | null;
  stock_status: string;
  scheme_notes: string;
  remarks: string;
}

export interface MrMonitor {
  bills: { id: number; invoice_no: string; invoice_date: string; customer_name: string; net_amount: number; balance: number; payment_mode: string }[];
  near_expiry: { p_name: string; pack_size: string; batch_no: string; expiry_date: string; qty: number; mrp: number }[];
  returns: { id: number; invoice_no: string; invoice_date: string; customer_name: string; return_total: number; net_amount: number }[];
}

export interface MrDailyReport {
  id?: number;
  mr_name?: string;
  report_date: string;
  calls_planned: number;
  calls_done: number;
  doctor_visits: number;
  orders_taken: number;
  order_value: number;
  stock_checks: number;
  sales_summary: string;
  competitor_info: string;
  outstanding_notes: string;
  remarks: string;
}

export interface MrOutstandingRow {
  customer_id: number | null;
  customer_name: string;
  outstanding_amt: number;
}

export interface MrOsFollowup {
  id?: number;
  followup_date: string;
  customer_id: number | null;
  customer_name: string;
  outstanding_amt: number;
  followup_note: string;
  promised_date: string;
  status: string;
}

export interface MrCustomer {
  id: number;
  code: string;
  name: string;
  phone: string | null;
  email: string | null;
  address: string | null;
  state: string | null;
  city: string | null;
  area: string | null;
  gstin: string | null;
  credit_days: number | null;
  dl_no: string | null;
}

@Injectable({ providedIn: 'root' })
export class MrService {
  private readonly http = inject(HttpClient);
  private readonly base = `${environment.apiUrl}/mr`;

  private params(obj: Record<string, string | number | null | undefined>): HttpParams {
    let p = new HttpParams();
    for (const [k, v] of Object.entries(obj)) {
      if (v !== undefined && v !== null && v !== '') p = p.set(k, String(v));
    }
    return p;
  }

  private getData<T>(path: string, params: Record<string, string | number | null | undefined> = {}): Observable<T> {
    return this.http
      .get<ApiResponse<T>>(`${this.base}/${path}`, { params: this.params(params) })
      .pipe(map((r) => r.data));
  }

  dashboard(): Observable<MrStats> {
    return this.getData<MrStats>('dashboard');
  }

  visits(from: string, to: string): Observable<MrVisit[]> {
    return this.getData<MrVisit[]>('visits', { from, to });
  }

  saveVisit(v: MrVisit): Observable<ApiResponse<{ id: number }>> {
    return this.http.post<ApiResponse<{ id: number }>>(`${this.base}/visits`, v);
  }

  stock(q: string): Observable<MrStockRow[]> {
    return this.getData<MrStockRow[]>('stock', { q });
  }

  previousRates(customerId: number | null, customerName: string, bindingId: number, productName: string) {
    return this.getData<{ previous_rate: number; history: MrRateHistory[] }>('previous-rates', {
      customer_id: customerId,
      customer_name: customerName,
      product_binding_id: bindingId,
      product_name: productName,
    });
  }

  nextOrderNo(): Observable<string> {
    return this.getData<{ order_no: string }>('orders/next-no').pipe(map((d) => d.order_no));
  }

  orders(from: string, to: string): Observable<MrOrder[]> {
    return this.getData<MrOrder[]>('orders', { from, to });
  }

  order(id: number): Observable<{ order: MrOrder; items: MrOrderItem[] }> {
    return this.getData<{ order: MrOrder; items: MrOrderItem[] }>(`orders/${id}`);
  }

  saveOrder(p: MrOrderPayload): Observable<ApiResponse<{ id: number; order_no: string; total_amount: number; status: string }>> {
    return this.http.post<ApiResponse<{ id: number; order_no: string; total_amount: number; status: string }>>(`${this.base}/orders`, p);
  }

  deleteOrder(id: number): Observable<ApiResponse<{ order_no: string }>> {
    return this.http.delete<ApiResponse<{ order_no: string }>>(`${this.base}/orders/${id}`);
  }

  orderPdf(id: number): Observable<Blob> {
    return this.http.get(`${this.base}/orders/${id}/pdf`, { responseType: 'blob' });
  }

  stockFollowups(from: string, to: string): Observable<MrStockFollowup[]> {
    return this.getData<MrStockFollowup[]>('stock-followups', { from, to });
  }

  saveStockFollowup(f: MrStockFollowup): Observable<ApiResponse<{ id: number }>> {
    return this.http.post<ApiResponse<{ id: number }>>(`${this.base}/stock-followups`, f);
  }

  monitor(from: string, to: string, customer = ''): Observable<MrMonitor> {
    return this.getData<MrMonitor>('monitor', { from, to, customer });
  }

  dailyReports(from: string, to: string): Observable<MrDailyReport[]> {
    return this.getData<MrDailyReport[]>('daily-reports', { from, to });
  }

  saveDailyReport(r: MrDailyReport): Observable<ApiResponse<unknown>> {
    return this.http.post<ApiResponse<unknown>>(`${this.base}/daily-reports`, r);
  }

  outstanding(): Observable<{ outstanding: MrOutstandingRow[]; followups: MrOsFollowup[] }> {
    return this.getData<{ outstanding: MrOutstandingRow[]; followups: MrOsFollowup[] }>('outstanding');
  }

  saveOsFollowup(f: MrOsFollowup): Observable<ApiResponse<{ id: number }>> {
    return this.http.post<ApiResponse<{ id: number }>>(`${this.base}/outstanding-followups`, f);
  }

  customers(q = ''): Observable<MrCustomer[]> {
    return this.getData<MrCustomer[]>('customers', { q });
  }
}
