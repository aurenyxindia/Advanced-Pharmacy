import { Component, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ReportService } from '../../core/services/report.service';

interface ReportDef {
  key: string;
  label: string;
  icon: string;
  path: string;
  hasDate: boolean;
}

interface Column {
  key: string;
  label: string;
  type?: 'currency' | 'date' | 'number' | 'badge';
  align?: 'end';
}

@Component({
  selector: 'app-reports',
  imports: [CommonModule, FormsModule],
  templateUrl: './reports.html',
  styleUrl: './reports.scss',
})
export class Reports {
  private readonly reportSvc = inject(ReportService);

  readonly reports: ReportDef[] = [
    { key: 'gst', label: 'GST Summary', icon: 'bi-percent', path: 'gst-summary', hasDate: true },
    { key: 'sales', label: 'Sales Register', icon: 'bi-receipt', path: 'sales-register', hasDate: true },
    { key: 'purchase', label: 'Purchase Register', icon: 'bi-cart', path: 'purchase-register', hasDate: true },
    { key: 'stock', label: 'Stock Report', icon: 'bi-boxes', path: 'stock', hasDate: false },
    { key: 'expiry', label: 'Expiry Report', icon: 'bi-hourglass-split', path: 'expiry', hasDate: false },
    { key: 'profit', label: 'Profit Report', icon: 'bi-graph-up-arrow', path: 'profit', hasDate: true },
    { key: 'schedule', label: 'Schedule Register', icon: 'bi-shield-check', path: 'schedule-register', hasDate: true },
  ];

  readonly columns: Record<string, Column[]> = {
    sales: [
      { key: 'invoice_no', label: 'Invoice' },
      { key: 'invoice_date', label: 'Date', type: 'date' },
      { key: 'customer_name', label: 'Customer' },
      { key: 'gst_amount', label: 'GST', type: 'currency', align: 'end' },
      { key: 'net_amount', label: 'Net', type: 'currency', align: 'end' },
      { key: 'profit_amount', label: 'Profit', type: 'currency', align: 'end' },
    ],
    purchase: [
      { key: 'invoice_no', label: 'Invoice' },
      { key: 'invoice_date', label: 'Date', type: 'date' },
      { key: 'supplier_name', label: 'Supplier' },
      { key: 'gst_amount', label: 'GST', type: 'currency', align: 'end' },
      { key: 'net_amount', label: 'Net', type: 'currency', align: 'end' },
    ],
    stock: [
      { key: 'product_name', label: 'Product' },
      { key: 'schedule_category', label: 'Sch', type: 'badge' },
      { key: 'quantity', label: 'Qty', type: 'number', align: 'end' },
      { key: 'mrp', label: 'MRP', type: 'currency', align: 'end' },
      { key: 'stock_value', label: 'Value', type: 'currency', align: 'end' },
    ],
    expiry: [
      { key: 'product_name', label: 'Product' },
      { key: 'batch_no', label: 'Batch' },
      { key: 'expiry_date', label: 'Expiry', type: 'date' },
      { key: 'quantity', label: 'Qty', type: 'number', align: 'end' },
      { key: 'days_to_expiry', label: 'Days left', type: 'number', align: 'end' },
    ],
    schedule: [
      { key: 'invoice_no', label: 'Invoice' },
      { key: 'invoice_date', label: 'Date', type: 'date' },
      { key: 'product_name', label: 'Product' },
      { key: 'schedule_category', label: 'Sch', type: 'badge' },
      { key: 'customer_name', label: 'Customer' },
      { key: 'doctor_name', label: 'Doctor' },
      { key: 'quantity', label: 'Qty', type: 'number', align: 'end' },
    ],
  };

  readonly active = signal<ReportDef>(this.reports[0]);
  readonly from = signal('');
  readonly to = signal('');
  readonly loading = signal(false);
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  readonly data = signal<any>(null);

  constructor() {
    this.run();
  }

  select(report: ReportDef): void {
    this.active.set(report);
    this.run();
  }

  run(): void {
    this.loading.set(true);
    this.data.set(null);
    const params: Record<string, string> = {};
    if (this.active().hasDate) {
      if (this.from()) params['from'] = this.from();
      if (this.to()) params['to'] = this.to();
    }
    this.reportSvc.get(this.active().path, params).subscribe({
      next: (d) => { this.data.set(d); this.loading.set(false); },
      error: () => this.loading.set(false),
    });
  }

  activeColumns(): Column[] {
    return this.columns[this.active().key] ?? [];
  }

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  cell(row: any, col: Column): string {
    const v = row[col.key];
    if (v == null || v === '') return '-';
    if (col.type === 'currency') {
      return '₹' + Number(v).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    if (col.type === 'date') {
      const d = new Date(v);
      return isNaN(d.getTime()) ? String(v) : d.toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' });
    }
    return String(v);
  }
}
