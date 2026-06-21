import { Component, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import {
  ClosingReportRow,
  DailyClosingService,
  DailyClosingSummary,
} from '../../core/services/daily-closing.service';

@Component({
  selector: 'app-daily-closing',
  imports: [CommonModule, FormsModule],
  templateUrl: './daily-closing.html',
  styleUrl: './daily-closing.scss',
})
export class DailyClosing {
  private readonly svc = inject(DailyClosingService);

  private today = new Date().toISOString().slice(0, 10);
  private monthStart = this.today.slice(0, 8) + '01';

  readonly date = signal(this.today);
  readonly loading = signal(false);
  readonly saving = signal(false);
  readonly toast = signal<string | null>(null);
  readonly summary = signal<DailyClosingSummary | null>(null);
  readonly availableCash = signal<number>(0);

  // report
  readonly fromDate = signal(this.monthStart);
  readonly toDate = signal(this.today);
  readonly reportRows = signal<ClosingReportRow[]>([]);
  readonly reportLoading = signal(false);

  readonly expected = computed(() => this.summary()?.expected_cash ?? 0);
  readonly difference = computed(() => Math.round((this.availableCash() - this.expected()) * 100) / 100);

  constructor() {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.svc.summary(this.date()).subscribe({
      next: (s) => {
        this.summary.set(s);
        this.availableCash.set(s.closing?.available_cash ?? 0);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  onDateChange(d: string): void {
    this.date.set(d);
    this.load();
  }

  dayEnd(): void {
    this.saving.set(true);
    this.svc.dayEnd(this.date(), this.availableCash()).subscribe({
      next: () => {
        this.saving.set(false);
        this.showToast('Day closed successfully.');
        this.load();
        this.loadReport();
      },
      error: () => {
        this.saving.set(false);
        this.showToast('Could not close the day.');
      },
    });
  }

  loadReport(): void {
    this.reportLoading.set(true);
    this.svc.report(this.fromDate(), this.toDate()).subscribe({
      next: (r) => {
        this.reportRows.set(r.rows);
        this.reportLoading.set(false);
      },
      error: () => this.reportLoading.set(false),
    });
  }

  exportExcel(): void {
    const rows = this.reportRows();
    if (rows.length === 0) {
      this.showToast('Load a report first.');
      return;
    }
    const headers = [
      'Date', 'Sales Cash', 'UPI', 'Sales Card', 'Sales Credit', 'Total Sales',
      'Customer Payment', 'Debit Payment', 'Today Customers', 'Expected Cash',
      'Available Cash', 'Difference',
    ];
    const lines = rows.map((r) => [
      r.closing_date, r.sales_cash, r.sales_upi, r.sales_card, r.sales_credit, r.total_sales,
      r.customer_payment, r.debit_payment, r.today_customers, r.expected_cash,
      r.available_cash, r.difference,
    ].join(','));
    const csv = [headers.join(','), ...lines].join('\n');
    const blob = new Blob([csv], { type: 'text/csv' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `daily-closing_${this.fromDate()}_${this.toDate()}.csv`;
    a.click();
  }

  private showToast(msg: string): void {
    this.toast.set(msg);
    setTimeout(() => this.toast.set(null), 2800);
  }
}
