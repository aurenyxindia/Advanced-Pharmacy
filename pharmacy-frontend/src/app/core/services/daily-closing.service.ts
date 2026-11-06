import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { environment } from '../../../environments/environment';
import { ApiResponse } from '../models/auth.models';

export interface ClosingSales {
  cash: number;
  upi: number;
  card: number;
  credit: number;
  total: number;
  customers: number;
  return: number;
  net: number;
}
export interface ClosingVoucher {
  cash: number;
  card: number;
  cheque: number;
  total: number;
}
export interface DailyClosingSummary {
  date: string;
  sales: ClosingSales;
  customer_payment: ClosingVoucher;
  debit_payment: ClosingVoucher;
  expected_cash: number;
  closing: {
    available_cash: number;
    difference: number;
    updated_at: string;
    retail_return?: number;
    net_sales?: number;
  } | null;
}
export interface ClosingReportRow {
  closing_date: string;
  sales_cash: number;
  sales_upi: number;
  sales_card: number;
  sales_credit: number;
  total_sales: number;
  retail_return?: number;
  net_sales?: number;
  customer_payment: number;
  debit_payment: number;
  today_customers: number;
  expected_cash: number;
  available_cash: number;
  difference: number;
}

@Injectable({ providedIn: 'root' })
export class DailyClosingService {
  private readonly http = inject(HttpClient);
  private readonly base = environment.apiUrl;

  summary(date: string): Observable<DailyClosingSummary> {
    const params = new HttpParams().set('date', date);
    return this.http
      .get<ApiResponse<DailyClosingSummary>>(`${this.base}/daily-closing/summary`, { params })
      .pipe(map((r) => r.data));
  }

  dayEnd(date: string, availableCash: number): Observable<
    ApiResponse<{ difference: number; expected_cash: number; whatsapp?: { ok?: boolean; skipped?: string; error?: string } }>
  > {
    return this.http.post<
      ApiResponse<{ difference: number; expected_cash: number; whatsapp?: { ok?: boolean; skipped?: string; error?: string } }>
    >(`${this.base}/daily-closing/day-end`, { date, available_cash: availableCash });
  }

  report(from: string, to: string): Observable<{ rows: ClosingReportRow[]; from: string; to: string }> {
    const params = new HttpParams().set('from', from).set('to', to);
    return this.http
      .get<ApiResponse<{ rows: ClosingReportRow[]; from: string; to: string }>>(`${this.base}/daily-closing/report`, { params })
      .pipe(map((r) => r.data));
  }

  /** Opens server SpreadsheetML export (Retail-style .xls). */
  downloadReportExcel(from: string, to: string): void {
    this.downloadBlob(`${this.base}/daily-closing/export?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`,
      `daily_closing_report_${from}_to_${to}.xls`);
  }

  downloadRowExcel(closingDate: string): void {
    this.downloadBlob(
      `${this.base}/daily-closing/export-row?closing_date=${encodeURIComponent(closingDate)}`,
      `daily_closing_${closingDate}.xls`,
    );
  }

  private downloadBlob(url: string, filename: string): void {
    this.http.get(url, { responseType: 'blob' }).subscribe({
      next: (blob) => {
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = filename;
        a.click();
        URL.revokeObjectURL(a.href);
      },
    });
  }
}
