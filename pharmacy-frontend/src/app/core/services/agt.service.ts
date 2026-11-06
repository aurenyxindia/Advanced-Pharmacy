import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { environment } from '../../../environments/environment';
import { ApiResponse } from '../models/auth.models';

export interface AgtCustomer {
  id?: number;
  c_no?: string;
  c_name: string;
  c_cont?: string;
  c_add?: string;
  c_mail?: string;
}

export interface AgtOrderItem {
  id?: number;
  product_name?: string;
  p_name?: string;
  batch_no?: string;
  qty?: number;
  unit_mrp?: number;
  line_total?: number;
  fulfillment_segments?: Array<{
    binding_id: number;
    batch_id: number;
    batch_no: string;
    qty: number;
    unit_mrp?: number;
    pack_mrp?: number;
  }>;
  qty_unfulfilled?: number;
  in_stock?: boolean;
}

export interface AgtOrder {
  id: number;
  order_no: string;
  status: string;
  patient_name: string;
  patient_contact: string;
  patient_address?: string;
  patient_no?: string;
  patient_note?: string;
  prescription_url?: string;
  estimated_total?: number;
  entry_date?: string;
  items?: AgtOrderItem[];
  sale_id?: number;
  sale_invoice_no?: string;
  sale_net?: number;
  item_count?: number;
  rider_user_id?: number | null;
  rider_name?: string | null;
  rider_phone?: string | null;
  tracking_active?: number | boolean;
}

export interface AgtRider {
  id: number;
  name: string;
  phone: string;
  branch_id: number;
}

@Injectable({ providedIn: 'root' })
export class AgtService {
  private readonly http = inject(HttpClient);
  private readonly base = `${environment.apiUrl}/agt`;

  searchCustomers(q: string): Observable<AgtCustomer[]> {
    const params = new HttpParams().set('q', q);
    return this.http
      .get<ApiResponse<{ customers: AgtCustomer[] }>>(`${this.base}/customers`, { params })
      .pipe(map((r) => r.data?.customers ?? []));
  }

  createShare(payload: {
    patient_no?: string;
    patient_name: string;
    patient_contact: string;
    patient_address?: string;
    send_whatsapp: boolean;
  }): Observable<ApiResponse<{
    token: string;
    shop_url: string;
    whatsapp_sent: boolean;
    message: string;
  }>> {
    return this.http.post<ApiResponse<{
      token: string;
      shop_url: string;
      whatsapp_sent: boolean;
      message: string;
    }>>(`${this.base}/share-link`, payload);
  }

  orderCounts(): Observable<Record<string, number>> {
    return this.http
      .get<ApiResponse<{ counts: Record<string, number> }>>(`${this.base}/order-counts`)
      .pipe(map((r) => r.data?.counts ?? {}));
  }

  listOrders(status: string, limit = 60): Observable<AgtOrder[]> {
    let params = new HttpParams().set('limit', String(limit));
    if (status) params = params.set('status', status);
    return this.http
      .get<ApiResponse<{ orders: AgtOrder[] }>>(`${this.base}/orders`, { params })
      .pipe(map((r) => r.data?.orders ?? []));
  }

  getOrder(orderId: number): Observable<AgtOrder | null> {
    const params = new HttpParams().set('order_id', String(orderId));
    return this.http
      .get<ApiResponse<{ order: AgtOrder }>>(`${this.base}/orders/detail`, { params })
      .pipe(map((r) => r.data?.order ?? null));
  }

  pendingByPatient(patientNo: string, contact: string): Observable<AgtOrder[]> {
    let params = new HttpParams();
    if (patientNo) params = params.set('patient_no', patientNo);
    if (contact) params = params.set('patient_contact', contact);
    return this.http
      .get<ApiResponse<{ orders: AgtOrder[] }>>(`${this.base}/orders/pending`, { params })
      .pipe(map((r) => r.data?.orders ?? []));
  }

  markReady(orderId: number, saleInvoiceNo: string, saleId: number, saleNet: number): Observable<unknown> {
    return this.http.post(`${this.base}/orders/mark-ready`, {
      order_id: orderId,
      sale_invoice_no: saleInvoiceNo,
      sale_id: saleId,
      sale_net: saleNet,
    });
  }

  updateStatus(
    orderId: number,
    status: string,
    sendWhatsApp = true,
  ): Observable<ApiResponse<{ message: string; whatsapp?: { ok?: boolean; error?: string } }>> {
    return this.http.post<ApiResponse<{ message: string; whatsapp?: { ok?: boolean; error?: string } }>>(`${this.base}/orders/status`, {
      order_id: orderId,
      status,
      send_whatsapp: sendWhatsApp,
    });
  }

  listRiders(): Observable<AgtRider[]> {
    return this.http
      .get<ApiResponse<{ riders: AgtRider[] }>>(`${this.base}/riders`)
      .pipe(map((r) => r.data?.riders ?? []));
  }

  assignRider(orderId: number, riderUserId: number): Observable<ApiResponse<{ message: string }>> {
    return this.http.post<ApiResponse<{ message: string }>>(`${this.base}/orders/rider`, {
      order_id: orderId,
      rider_user_id: riderUserId,
    });
  }

  getBranchSettings(): Observable<{ allow_customer_cancel: boolean }> {
    return this.http
      .get<ApiResponse<{ allow_customer_cancel: boolean }>>(`${this.base}/settings`)
      .pipe(map((r) => r.data ?? { allow_customer_cancel: false }));
  }

  saveBranchSettings(allowCustomerCancel: boolean): Observable<ApiResponse<{ allow_customer_cancel: boolean }>> {
    return this.http.post<ApiResponse<{ allow_customer_cancel: boolean }>>(`${this.base}/settings`, {
      allow_customer_cancel: allowCustomerCancel,
    });
  }

  /** Public patient shop (no auth). */
  publicShop(token: string, q = ''): Observable<ApiResponse<{
    pharmacy: { name: string; contact: string; address: string };
    patient: { name: string; contact: string; address: string };
    policy?: { allow_customer_cancel: boolean; cancel_hint: string };
    products: Array<Record<string, unknown>>;
    token: string;
  }>> {
    let params = new HttpParams().set('token', token);
    if (q) params = params.set('q', q);
    return this.http.get<ApiResponse<{
      pharmacy: { name: string; contact: string; address: string };
      patient: { name: string; contact: string; address: string };
      policy?: { allow_customer_cancel: boolean; cancel_hint: string };
      products: Array<Record<string, unknown>>;
      token: string;
    }>>(`${environment.apiUrl}/public/agt/shop`, { params });
  }

  publicCancelOrder(token: string, orderId: number): Observable<ApiResponse<{ message: string }>> {
    return this.http.post<ApiResponse<{ message: string }>>(
      `${environment.apiUrl}/public/agt/orders/cancel`,
      { token, order_id: orderId },
    );
  }

  placePublicOrder(body: {
    token: string;
    items: Array<{ p_name: string; batch_no: string; qty: number; unit_mrp?: number }>;
    patient_note?: string;
    prescription_base64?: string;
  }): Observable<ApiResponse<{ order_no: string; order_id: number; estimated_total: number }>> {
    return this.http.post<ApiResponse<{ order_no: string; order_id: number; estimated_total: number }>>(
      `${environment.apiUrl}/public/agt/orders`,
      body,
    );
  }
}
