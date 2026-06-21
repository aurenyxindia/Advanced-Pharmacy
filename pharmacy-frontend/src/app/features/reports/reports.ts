import { Component, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ReportService } from '../../core/services/report.service';
import { AuthService } from '../../core/services/auth.service';

interface ReportDef {
  key: string;
  label: string;
  icon: string;
  path: string;
  hasDate: boolean;
  group: string;
}

interface Column {
  key: string;
  label: string;
  type?: 'currency' | 'date' | 'number' | 'badge';
  align?: 'end';
}

interface BranchOpt { id: number; code: string; name: string; }

@Component({
  selector: 'app-reports',
  imports: [CommonModule, FormsModule],
  templateUrl: './reports.html',
  styleUrl: './reports.scss',
})
export class Reports {
  private readonly reportSvc = inject(ReportService);
  private readonly auth = inject(AuthService);

  readonly isHo = computed(() => this.auth.user()?.role === 'ho_admin' || this.auth.user()?.scope === 'ho');
  readonly branches = signal<BranchOpt[]>([]);
  readonly branchId = signal<number | null>(null);

  readonly reports: ReportDef[] = [
    { key: 'sales', label: 'Sales Register', icon: 'bi-receipt', path: 'sales-register', hasDate: true, group: 'Sales' },
    { key: 'doctor', label: 'Doctor-wise Sales', icon: 'bi-person-badge', path: 'doctor-wise', hasDate: true, group: 'Sales' },
    { key: 'online', label: 'Online Sales', icon: 'bi-globe', path: 'online-sales', hasDate: true, group: 'Sales' },
    { key: 'h1', label: 'Schedule H1 Sales', icon: 'bi-shield-exclamation', path: 'h1-sales', hasDate: true, group: 'Compliance' },
    { key: 'x', label: 'Schedule X Sales', icon: 'bi-shield-lock', path: 'x-sales', hasDate: true, group: 'Compliance' },
    { key: 'schedule', label: 'All Schedule Register', icon: 'bi-shield-check', path: 'schedule-register', hasDate: true, group: 'Compliance' },
    { key: 'chronic', label: 'Chronic / Repeat Patients', icon: 'bi-heart-pulse', path: 'chronic', hasDate: false, group: 'Patients' },
    { key: 'purchase', label: 'Purchase Register', icon: 'bi-cart', path: 'purchase-register', hasDate: true, group: 'Purchase' },
    { key: 'stock', label: 'Stock Report', icon: 'bi-boxes', path: 'stock', hasDate: false, group: 'Inventory' },
    { key: 'nonmoving', label: 'Non-Moving Stock', icon: 'bi-snow', path: 'non-moving', hasDate: false, group: 'Inventory' },
    { key: 'expiry', label: 'Expiry Report', icon: 'bi-hourglass-split', path: 'expiry', hasDate: false, group: 'Inventory' },
    { key: 'expalert', label: 'Expiry Alert', icon: 'bi-exclamation-octagon', path: 'expiry-alert', hasDate: false, group: 'Inventory' },
    { key: 'gst', label: 'GST Summary', icon: 'bi-percent', path: 'gst-summary', hasDate: true, group: 'GST & Accounts' },
    { key: 'gsthsn', label: 'GST Rate-wise (HSN)', icon: 'bi-table', path: 'gst-hsn', hasDate: true, group: 'GST & Accounts' },
    { key: 'cashbook', label: 'Cash Book', icon: 'bi-cash-stack', path: 'cash-book', hasDate: true, group: 'GST & Accounts' },
    { key: 'profit', label: 'Profit Report', icon: 'bi-graph-up-arrow', path: 'profit', hasDate: true, group: 'GST & Accounts' },
  ];

  private readonly scheduleCols: Column[] = [
    { key: 'invoice_no', label: 'Invoice' },
    { key: 'invoice_date', label: 'Date', type: 'date' },
    { key: 'product_name', label: 'Product' },
    { key: 'manufacturer_short', label: 'Mfg' },
    { key: 'batch_no', label: 'Batch' },
    { key: 'patient_name', label: 'Patient' },
    { key: 'doctor_name', label: 'Doctor' },
    { key: 'quantity', label: 'Qty', type: 'number', align: 'end' },
  ];

  readonly columns: Record<string, Column[]> = {
    sales: [
      { key: 'invoice_no', label: 'Invoice' }, { key: 'invoice_date', label: 'Date', type: 'date' },
      { key: 'customer_name', label: 'Customer' }, { key: 'gst_amount', label: 'GST', type: 'currency', align: 'end' },
      { key: 'net_amount', label: 'Net', type: 'currency', align: 'end' }, { key: 'profit_amount', label: 'Profit', type: 'currency', align: 'end' },
    ],
    purchase: [
      { key: 'invoice_no', label: 'Invoice' }, { key: 'invoice_date', label: 'Date', type: 'date' },
      { key: 'supplier_name', label: 'Supplier' }, { key: 'gst_amount', label: 'GST', type: 'currency', align: 'end' },
      { key: 'net_amount', label: 'Net', type: 'currency', align: 'end' },
    ],
    doctor: [
      { key: 'doctor_name', label: 'Doctor' }, { key: 'bills', label: 'Bills', type: 'number', align: 'end' },
      { key: 'net', label: 'Net Sales', type: 'currency', align: 'end' }, { key: 'profit', label: 'Profit', type: 'currency', align: 'end' },
    ],
    online: [
      { key: 'order_no', label: 'Order' }, { key: 'created_at', label: 'Date', type: 'date' },
      { key: 'customer_name', label: 'Customer' }, { key: 'phone', label: 'Phone' },
      { key: 'status', label: 'Status', type: 'badge' }, { key: 'total_estimate', label: 'Value', type: 'currency', align: 'end' },
    ],
    chronic: [
      { key: 'patient', label: 'Patient' }, { key: 'contact_no', label: 'Contact' },
      { key: 'visits', label: 'Visits', type: 'number', align: 'end' }, { key: 'spend', label: 'Total Spend', type: 'currency', align: 'end' },
      { key: 'last_visit', label: 'Last Visit', type: 'date' },
    ],
    stock: [
      { key: 'product_name', label: 'Product' }, { key: 'manufacturer_short', label: 'Mfg' },
      { key: 'schedule_category', label: 'Sch', type: 'badge' }, { key: 'quantity', label: 'Qty', type: 'number', align: 'end' },
      { key: 'mrp', label: 'MRP', type: 'currency', align: 'end' }, { key: 'stock_value', label: 'Value', type: 'currency', align: 'end' },
    ],
    nonmoving: [
      { key: 'product_name', label: 'Product' }, { key: 'manufacturer_short', label: 'Mfg' },
      { key: 'schedule_category', label: 'Sch', type: 'badge' }, { key: 'quantity', label: 'Qty', type: 'number', align: 'end' },
      { key: 'last_sold', label: 'Last Sold', type: 'date' },
    ],
    expiry: [
      { key: 'product_name', label: 'Product' }, { key: 'batch_no', label: 'Batch' },
      { key: 'expiry_date', label: 'Expiry', type: 'date' }, { key: 'quantity', label: 'Qty', type: 'number', align: 'end' },
      { key: 'days_to_expiry', label: 'Days left', type: 'number', align: 'end' },
    ],
    expalert: [
      { key: 'product_name', label: 'Product' }, { key: 'manufacturer_short', label: 'Mfg' }, { key: 'batch_no', label: 'Batch' },
      { key: 'expiry_date', label: 'Expiry', type: 'date' }, { key: 'quantity', label: 'Qty', type: 'number', align: 'end' },
      { key: 'days_to_expiry', label: 'Days left', type: 'number', align: 'end' },
    ],
    gsthsn: [
      { key: 'gst_rate', label: 'GST %', type: 'number' }, { key: 'taxable', label: 'Taxable', type: 'currency', align: 'end' },
      { key: 'cgst', label: 'CGST', type: 'currency', align: 'end' }, { key: 'sgst', label: 'SGST', type: 'currency', align: 'end' },
      { key: 'gst', label: 'Total GST', type: 'currency', align: 'end' }, { key: 'total', label: 'Total', type: 'currency', align: 'end' },
    ],
    cashbook: [
      { key: 'date', label: 'Date', type: 'date' }, { key: 'in', label: 'Cash In', type: 'currency', align: 'end' },
      { key: 'out', label: 'Cash Out', type: 'currency', align: 'end' }, { key: 'net', label: 'Net', type: 'currency', align: 'end' },
    ],
    schedule: this.scheduleCols,
    h1: this.scheduleCols,
    x: this.scheduleCols,
  };

  readonly active = signal<ReportDef>(this.reports[0]);
  readonly from = signal('');
  readonly to = signal('');
  readonly loading = signal(false);
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  readonly data = signal<any>(null);

  groups = computed(() => {
    const g: Record<string, ReportDef[]> = {};
    for (const r of this.reports) (g[r.group] ??= []).push(r);
    return Object.entries(g);
  });

  constructor() {
    if (this.isHo()) {
      this.reportSvc.get<BranchOpt[]>('branches').subscribe((b) => this.branches.set(b));
    }
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
    if (this.isHo() && this.branchId()) params['branch_id'] = String(this.branchId());

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
      return '\u20B9' + Number(v).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    if (col.type === 'date') {
      const d = new Date(v);
      return isNaN(d.getTime()) ? String(v) : d.toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' });
    }
    return String(v);
  }
}
