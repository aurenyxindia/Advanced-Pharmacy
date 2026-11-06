import { Injectable, inject, signal } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, map, tap } from 'rxjs';
import { environment } from '../../../environments/environment';
import { ApiResponse } from '../models/auth.models';
import { Paginated } from './crud.service';

export interface SaleProduct {
  binding_id: number;
  product_name: string;
  manufacturer?: string | null;
  manufacturer_short?: string | null;
  schedule_category: string;
  product_category?: string | null;
  gst_rate: number;
  master_product_id: number;
  available_qty: number;
  expired_qty?: number;
  is_expired?: boolean;
  mrp: number | null;
  hsn_code?: string | null;
  units_per_pack?: number;
  allow_loose?: number;
  shelf_location?: string | null;
  pack_size?: string | null;
  generic_name?: string | null;
}

export interface BatchOption {
  batch_id: number;
  batch_no: string;
  expiry_date: string | null;
  is_expired?: boolean;
  mrp: number;
  unit_mrp: number;
  pack_mrp: number;
  ptr: number;
  pts: number;
  purchase_rate: number;
  pack_purchase_rate: number;
  pack_net_cost?: number;
  scheme_pct?: number;
  discount_pct?: number;
  quantity: number;
  packs: number;
  shelf_location: string | null;
  units_per_pack: number;
}

export interface PatientHistoryFulfillmentSegment {
  binding_id: number;
  batch_id: number;
  batch_no: string;
  qty: number;
  rate_source: 'historical' | 'current' | string;
  unit_mrp: number;
  pack_mrp?: number;
  exp_date?: string;
  available_qty?: number;
  disc_per?: number;
}

export interface PatientRetailHistoryLine {
  p_name: string;
  product_name?: string;
  m_name?: string;
  batch_no: string;
  exp_date?: string;
  qty: number;
  disc_per: number;
  historical_unit_mrp?: number;
  net: number;
  stock_status: string;
  fulfillment_segments?: PatientHistoryFulfillmentSegment[];
  qty_unfulfilled?: number;
  in_stock?: boolean;
}

export interface PatientRetailHistoryBill {
  order_no: string;
  order_date: string;
  patient_name?: string;
  net: number;
  line_count?: number;
  products: PatientRetailHistoryLine[];
}

export interface Substitute {
  id: number;
  name: string;
  manufacturer: string | null;
  schedule_category: string;
  default_gst_rate: number;
  generic_name: string | null;
  binding_id: number | null;
  available_qty: number;
}

export interface SaleItemPayload {
  product_binding_id: number;
  schedule_category: string;
  quantity: number;
  rate: number;
  discount_pct: number;
  batch_id?: number | null;
  unit_type?: string;
  pack?: string | null;
  shelf?: string | null;
  hsn_code?: string | null;
  free_quantity?: number;
  oth_rate?: number;
  base_rate?: number;
  net_rate?: number;
  billing_with?: string;
  sale_scheme_pct?: number;
}

export interface SalePayload {
  counter?: string;
  sale_type?: string;
  bill_mode?: string;
  rate_type?: string;
  invoice_date?: string;
  customer_name?: string;
  customer_id?: number | null;
  patient_name?: string;
  contact_no?: string;
  customer_type?: 'retail' | 'wholesale' | 'ipd';
  email?: string;
  address?: string;
  narration?: string;
  reminder_days?: number | null;
  doctor_name?: string;
  payment_mode?: string;
  account_id?: number | null;
  cash_amount?: number;
  bill_discount_pct?: number;
  bill_discount_amount?: number;
  cd_disc_pct?: number;
  credit_note_no?: string;
  credit_deduction_amt?: number;
  is_interstate?: number;
  gst_inclusive?: number;
  is_chronic?: number;
  ipd_admission_id?: number | null;
  prescription_id?: number | null;
  send_whatsapp?: boolean | number;
  /** AuraGharTak order to mark Ready when this bill is saved (Placed → Ready). */
  agt_order_id?: number | null;
  mr_order_id?: number | null;
  items: SaleItemPayload[];
}

export interface MasterProduct {
  id: number;
  name: string;
  manufacturer: string | null;
  manufacturer_short: string | null;
  schedule_category: string;
  product_category: string | null;
  default_gst_rate: number;
  hsn_code: string | null;
  pack_size: string | null;
  units_per_pack: number | null;
  mrp: number | null;
  ptr: number | null;
  pts: number | null;
}

export interface CustomerLookup {
  id: number | null;
  name: string;
  phone: string | null;
  email: string | null;
  address: string | null;
  is_chronic: number;
  customer_type?: string | null;
  state?: string | null;
  lookup_source?: 'master' | 'sales_history' | string | null;
}

export interface SaleResult {
  id: number;
  invoice_no: string;
  net_amount: number;
  gst_amount: number;
  cd_amount?: number;
  cgst?: number;
  sgst?: number;
  igst?: number;
  profit_amount: number;
  is_locked: boolean;
  whatsapp_sent?: boolean;
  whatsapp_message?: string;
}

@Injectable({ providedIn: 'root' })
export class SalesService {
  private readonly http = inject(HttpClient);
  private readonly base = environment.apiUrl;

  /** Bumped after create so Sales log can refresh without a full page reload. */
  readonly logVersion = signal(0);

  notifyLogChanged(): void {
    this.logVersion.update((v) => v + 1);
  }

  searchProducts(q: string, genericId?: number): Observable<SaleProduct[]> {
    let params = new HttpParams().set('q', q);
    if (genericId) params = params.set('generic_id', String(genericId));
    return this.http
      .get<ApiResponse<SaleProduct[]>>(`${this.base}/sales/search-products`, { params })
      .pipe(map((r) => r.data));
  }

  batches(bindingId: number): Observable<BatchOption[]> {
    return this.http
      .get<ApiResponse<BatchOption[]>>(`${this.base}/bound-products/${bindingId}/batches`)
      .pipe(map((r) => r.data));
  }

  scan(code: string): Observable<SaleProduct & {
    batch_id: number;
    batch_no?: string | null;
    expiry_date?: string | null;
    unit_mrp?: number;
    ptr?: number;
    pts?: number;
    purchase_rate?: number;
  }> {
    const params = new HttpParams().set('code', code);
    return this.http
      .get<ApiResponse<SaleProduct & {
        batch_id: number;
        batch_no?: string | null;
        expiry_date?: string | null;
        unit_mrp?: number;
        ptr?: number;
        pts?: number;
        purchase_rate?: number;
      }>>(`${this.base}/sales/scan`, { params })
      .pipe(map((r) => r.data));
  }

  substitutes(bindingId: number): Observable<Substitute[]> {
    return this.http
      .get<ApiResponse<Substitute[]>>(`${this.base}/bound-products/${bindingId}/substitutes`)
      .pipe(map((r) => r.data));
  }

  patientRetailHistory(patientNo: string, patientContact: string, limit = 40): Observable<{
    bills: PatientRetailHistoryBill[];
    bill_count: number;
  }> {
    let params = new HttpParams().set('limit', String(limit));
    if (patientNo) params = params.set('patient_no', patientNo);
    if (patientContact) params = params.set('patient_contact', patientContact);
    return this.http
      .get<ApiResponse<{ bills: PatientRetailHistoryBill[]; bill_count: number }>>(
        `${this.base}/sales/patient-history`,
        { params },
      )
      .pipe(map((r) => r.data ?? { bills: [], bill_count: 0 }));
  }

  prevCustomerRates(customerId: number, productBindingId: number, limit = 8): Observable<Array<{
    sale_rate: number;
    discount_pct: number;
    batch_no: string | null;
    invoice_no: string;
    invoice_date: string;
    payment_mode: string | null;
  }>> {
    const params = new HttpParams()
      .set('customer_id', String(customerId))
      .set('product_binding_id', String(productBindingId))
      .set('limit', String(limit));
    return this.http
      .get<ApiResponse<Array<{
        sale_rate: number;
        discount_pct: number;
        batch_no: string | null;
        invoice_no: string;
        invoice_date: string;
        payment_mode: string | null;
      }>>>(`${this.base}/sales/prev-customer-rates`, { params })
      .pipe(map((r) => r.data ?? []));
  }

  create(payload: SalePayload): Observable<ApiResponse<SaleResult>> {
    return this.http.post<ApiResponse<SaleResult>>(`${this.base}/sales`, payload).pipe(
      tap(() => this.notifyLogChanged()),
    );
  }

  /** HO Admin only — full bill edit (API enforces role). */
  update(id: number, payload: SalePayload): Observable<ApiResponse<SaleResult>> {
    return this.http.put<ApiResponse<SaleResult>>(`${this.base}/sales/${id}`, payload).pipe(
      tap(() => this.notifyLogChanged()),
    );
  }

  /** Search the global Product Master (used by the quick stock-in popup). */
  searchMaster(q: string): Observable<MasterProduct[]> {
    const params = new HttpParams().set('q', q).set('limit', '10');
    return this.http
      .get<ApiResponse<MasterProduct[]>>(`${this.base}/products/search`, { params })
      .pipe(map((r) => r.data));
  }

  /** Counter stock-in: create a quick one-line purchase and return the sellable product. */
  quickPurchase(payload: {
    master_product_id?: number | null;
    product_name: string; batch_no?: string; quantity: number; mrp: number;
    purchase_rate?: number; gst_rate?: number; expiry_date?: string;
    manufacturer?: string; manufacturer_short?: string; schedule_category?: string;
    product_category?: string; hsn_code?: string; pack_size?: string; units_per_pack?: number;
  }): Observable<SaleProduct & { batch_id?: number }> {
    return this.http
      .post<ApiResponse<SaleProduct & { batch_id?: number }>>(`${this.base}/sales/quick-purchase`, payload)
      .pipe(map((r) => r.data));
  }

  /** All patients/customers registered on the same mobile number. */
  lookupCustomersByPhone(phone: string): Observable<CustomerLookup[]> {
    const params = new HttpParams().set('phone', phone);
    return this.http
      .get<ApiResponse<CustomerLookup[]>>(`${this.base}/customers/lookup`, { params })
      .pipe(map((r) => r.data ?? []));
  }

  /** First match only — used where a single auto-fill is enough (e.g. IPD). */
  lookupCustomer(phone: string): Observable<CustomerLookup | null> {
    return this.lookupCustomersByPhone(phone).pipe(map((rows) => rows[0] ?? null));
  }

  list(opts: {
    search?: string;
    page?: number;
    per_page?: number;
    bill_mode?: string;
    sale_type?: string;
  } = {}): Observable<Paginated<Record<string, unknown>>> {
    let params = new HttpParams();
    if (opts.search) params = params.set('search', opts.search);
    if (opts.page) params = params.set('page', String(opts.page));
    if (opts.per_page) params = params.set('per_page', String(opts.per_page));
    if (opts.bill_mode) params = params.set('bill_mode', opts.bill_mode);
    if (opts.sale_type) params = params.set('sale_type', opts.sale_type);
    return this.http
      .get<ApiResponse<Paginated<Record<string, unknown>>>>(`${this.base}/sales`, { params })
      .pipe(map((r) => r.data));
  }

  get(id: number): Observable<Record<string, unknown>> {
    return this.http
      .get<ApiResponse<Record<string, unknown>>>(`${this.base}/sales/${id}`)
      .pipe(map((r) => r.data));
  }

  remove(id: number): Observable<ApiResponse<null>> {
    return this.http.delete<ApiResponse<null>>(`${this.base}/sales/${id}`).pipe(
      tap(() => this.notifyLogChanged()),
    );
  }

  updatePaymentMode(
    id: number,
    paymentMode: string,
    accountId?: number | null,
    cashAmount?: number | null,
  ): Observable<{ id: number; payment_mode: string; paid: number; balance: number; account_id: number | null; net_amount: number }> {
    const body: { payment_mode: string; account_id?: number | null; cash_amount?: number } = {
      payment_mode: paymentMode,
    };
    if (accountId != null) {
      body.account_id = accountId;
    }
    if (cashAmount != null) {
      body.cash_amount = cashAmount;
    }
    return this.http
      .patch<ApiResponse<{ id: number; payment_mode: string; paid: number; balance: number; account_id: number | null; net_amount: number }>>(
        `${this.base}/sales/${id}/payment-mode`,
        body,
      )
      .pipe(
        map((r) => r.data),
        tap(() => this.notifyLogChanged()),
      );
  }

  uploadPrescription(file: File | Blob, source: 'upload' | 'camera', doctorName?: string): Observable<ApiResponse<{ id: number; file_path: string }>> {
    const form = new FormData();
    form.append('file', file, source === 'camera' ? 'capture.png' : (file as File).name ?? 'prescription');
    form.append('source', source);
    if (doctorName) form.append('doctor_name', doctorName);
    return this.http.post<ApiResponse<{ id: number; file_path: string }>>(`${this.base}/prescriptions`, form);
  }

  invoiceBlob(saleId: number): Observable<Blob> {
    return this.http.get(`${this.base}/sales/${saleId}/invoice`, { responseType: 'blob' });
  }

  invoiceDualBlob(saleId: number): Observable<Blob> {
    return this.http.get(`${this.base}/sales/${saleId}/invoice-dual`, { responseType: 'blob' });
  }

  invoiceDotMatrixBlob(saleId: number): Observable<Blob> {
    return this.http.get(`${this.base}/sales/${saleId}/invoice-dotmatrix`, { responseType: 'blob' });
  }

  sendWhatsApp(saleId: number): Observable<{ to: string; invoice_no: string; message: string }> {
    return this.http
      .post<ApiResponse<{ to: string; invoice_no: string; message: string }>>(
        `${this.base}/sales/${saleId}/whatsapp`,
        {},
      )
      .pipe(map((r) => r.data));
  }
}
