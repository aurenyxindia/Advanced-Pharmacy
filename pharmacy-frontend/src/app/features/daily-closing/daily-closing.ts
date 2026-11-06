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

  private today = this.localYmd();
  private monthStart = this.today.slice(0, 8) + '01';

  readonly date = signal(this.today);
  readonly loading = signal(false);
  readonly saving = signal(false);
  readonly toast = signal<string | null>(null);
  readonly toastError = signal(false);
  readonly summary = signal<DailyClosingSummary | null>(null);
  readonly availableCash = signal<number>(0);

  readonly fromDate = signal(this.monthStart);
  readonly toDate = signal(this.today);
  readonly reportRows = signal<ClosingReportRow[]>([]);
  readonly reportLoading = signal(false);

  readonly expected = computed(() => this.summary()?.expected_cash ?? 0);
  readonly difference = computed(() => Math.round((this.availableCash() - this.expected()) * 100) / 100);
  readonly netSales = computed(() => this.summary()?.sales?.net ?? this.summary()?.sales?.total ?? 0);

  constructor() {
    this.load();
    this.loadReport();
  }

  private localYmd(d = new Date()): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  }

  load(): void {
    this.loading.set(true);
    this.svc.summary(this.date()).subscribe({
      next: (s) => {
        this.summary.set(s);
        // If day already closed, show saved count. Otherwise prefill AVAILABLE CASH
        // with expected drawer total so DIFFERENCE starts at 0 (not a false shortfall).
        const expected = Number(s.expected_cash) || 0;
        const saved = s.closing?.available_cash;
        this.availableCash.set(saved != null ? Number(saved) : expected);
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
    if (Number.isNaN(this.availableCash())) {
      this.showToast('Enter a valid available cash amount.', true);
      return;
    }
    this.saving.set(true);
    this.svc.dayEnd(this.date(), this.availableCash()).subscribe({
      next: (res) => {
        this.saving.set(false);
        const wa = res.data?.whatsapp as
          | { ok?: boolean; skipped?: string; error?: string; to?: string }
          | undefined;
        let msg = res.message || 'Day End value saved successfully!';
        if (wa && wa.ok === false) {
          if (wa.skipped === 'no_phone') {
            msg = 'Day End saved. WhatsApp not sent — add mobile in client / branch profile.';
          } else if (wa.skipped === 'no_client') {
            msg = 'Day End saved. WhatsApp not sent — client record not found.';
          } else if (wa.skipped === 'not_configured') {
            msg = 'Day End saved. WhatsApp not sent — API not configured.';
          } else {
            msg = `Day End saved. WhatsApp not sent: ${wa.error || 'API error'}`;
          }
          this.showToast(msg, true);
        } else {
          this.showToast(msg, false);
        }
        this.load();
        this.loadReport();
      },
      error: (err) => {
        this.saving.set(false);
        this.showToast(err?.error?.message || 'Could not close the day.', true);
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
    if (this.reportRows().length === 0) {
      this.showToast('Load a report first.', true);
      return;
    }
    this.svc.downloadReportExcel(this.fromDate(), this.toDate());
  }

  exportRow(closingDate: string): void {
    this.svc.downloadRowExcel(closingDate);
  }

  private showToast(msg: string, isError = false): void {
    this.toastError.set(isError);
    this.toast.set(msg);
    setTimeout(() => this.toast.set(null), 4000);
  }
}
