import { Component, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterLink } from '@angular/router';
import { ChartConfiguration } from 'chart.js';
import { DashboardService } from '../../core/services/dashboard.service';
import { AuthService } from '../../core/services/auth.service';
import { DashboardSummary, ExpiryLossPeriod } from '../../core/models/dashboard.models';
import { ChartComponent } from '../../shared/chart/chart';
import { MinInventoryRow, MinInventoryService } from '../../core/services/min-inventory.service';
import { UserRightsService } from '../../core/services/user-rights.service';
import { ShortStockModal } from '../stock/short-stock-modal';
import { DiwaliTheme } from './diwali-theme/diwali-theme';

@Component({
  selector: 'app-dashboard',
  imports: [CommonModule, RouterLink, ChartComponent, ShortStockModal, DiwaliTheme],
  templateUrl: './dashboard.html',
  styleUrl: './dashboard.scss',
})
export class Dashboard {
  private readonly dashboard = inject(DashboardService);
  private readonly auth = inject(AuthService);
  private readonly minInventory = inject(MinInventoryService);
  private readonly rights = inject(UserRightsService);

  readonly user = this.auth.user;
  readonly miAlerts = signal<MinInventoryRow[]>([]);
  readonly miLoading = signal(true);
  readonly miTotal = signal(0);
  readonly miSalesMonths = signal(4);
  readonly miShowAll = signal(false);
  readonly canGenerateOrder = () => this.rights.canEdit('PURCHASE_ORDER');
  readonly canViewReports = () => this.rights.canView('ALL_REPORTS');
  readonly isHo = computed(() => this.user()?.scope === 'ho' || this.user()?.role === 'ho_admin');
  readonly loading = signal(true);
  readonly error = signal<string | null>(null);
  readonly data = signal<DashboardSummary | null>(null);

  readonly kpis = computed(() => this.data()?.kpis ?? null);
  readonly expiryLoss = computed(() => this.data()?.expiry_loss ?? null);
  readonly profitAnalytics = computed(() => this.data()?.profit_analytics ?? null);
  readonly storeAnalytics = computed(() => this.data()?.store_analytics ?? []);

  readonly expiryPeriods = computed(() => {
    const empty: ExpiryLossPeriod = {
      batches: 0, qty: 0, purchase_value: 0, sale_value: 0,
      expiry_loss: 0, revenue_loss: 0, margin_loss: 0,
    };
    const loss = this.expiryLoss();
    return [
      { key: 'last_week', label: 'Last week', tone: 'rose', data: loss?.last_week ?? empty },
      { key: 'last_month', label: 'Last month', tone: 'amber', data: loss?.last_month ?? empty },
      { key: 'mtd', label: 'Month till date', tone: 'violet', data: loss?.mtd ?? empty },
    ];
  });

  constructor() {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.error.set(null);
    this.dashboard.summary().subscribe({
      next: (summary) => {
        this.data.set(summary);
        this.loading.set(false);
      },
      error: () => {
        this.error.set('Unable to load dashboard data.');
        this.loading.set(false);
      },
    });
    this.loadMinInventoryAlerts();
  }

  loadMinInventoryAlerts(): void {
    this.miLoading.set(true);
    this.minInventory.alerts(5).subscribe({
      next: (data) => {
        this.miAlerts.set(data.products);
        this.miTotal.set(data.total);
        this.miSalesMonths.set(data.sales_months);
        this.miLoading.set(false);
      },
      error: () => {
        this.miAlerts.set([]);
        this.miTotal.set(0);
        this.miLoading.set(false);
      },
    });
  }

  /** Green for profit / positive, red for loss / negative. */
  moneyClass(value: number | null | undefined): string {
    const n = Number(value) || 0;
    if (n < 0) return 'amt-bad';
    if (n > 0) return 'amt-good';
    return 'amt-zero';
  }

  readonly salesTrendConfig = computed<ChartConfiguration>(() => {
    const sales = this.data()?.sales_trend ?? [];
    const purchase = this.data()?.purchase_trend ?? [];
    return {
      type: 'line',
      data: {
        labels: sales.map((p) => {
          const dt = new Date(p.date);
          const dd = String(dt.getDate()).padStart(2, '0');
          const mm = String(dt.getMonth() + 1).padStart(2, '0');
          return `${dd}-${mm}`;
        }),
        datasets: [
          {
            label: 'Sales',
            data: sales.map((p) => p.total),
            borderColor: '#0d9488',
            backgroundColor: 'rgba(13,148,136,0.12)',
            fill: true,
            tension: 0.35,
            pointRadius: 2,
            pointBackgroundColor: '#0d9488',
          },
          {
            label: 'Purchase',
            data: purchase.map((p) => p.total),
            borderColor: '#6366f1',
            backgroundColor: 'rgba(99,102,241,0.10)',
            fill: true,
            tension: 0.35,
            pointRadius: 2,
            pointBackgroundColor: '#6366f1',
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'top', labels: { boxWidth: 12, usePointStyle: true } } },
        scales: {
          y: { beginAtZero: true, ticks: { callback: (v) => '₹' + v } },
          x: { grid: { display: false } },
        },
      },
    };
  });

  readonly distributorConfig = computed<ChartConfiguration>(() => {
    const d = this.data()?.distributor_purchase ?? [];
    return {
      type: 'bar',
      data: {
        labels: d.map((x) => x.supplier),
        datasets: [
          {
            label: 'Purchase',
            data: d.map((x) => Number(x.total)),
            backgroundColor: ['#0d9488', '#6366f1', '#f59e0b', '#0ea5e9', '#ef4444', '#16a34a', '#a855f7'],
            borderRadius: 6,
            maxBarThickness: 30,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { callback: (v) => '₹' + v } }, x: { grid: { display: false } } },
      },
    };
  });

  readonly hasDistributor = computed(() => (this.data()?.distributor_purchase ?? []).length > 0);

  readonly categoryConfig = computed<ChartConfiguration>(() => {
    const split = this.data()?.category_split ?? [];
    const palette = ['#0d9488', '#6366f1', '#f59e0b', '#0ea5e9', '#ef4444', '#16a34a'];
    return {
      type: 'doughnut',
      data: {
        labels: split.map((c) => c.category),
        datasets: [
          {
            data: split.map((c) => c.cnt),
            backgroundColor: palette,
            borderWidth: 0,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '65%',
        plugins: { legend: { position: 'bottom' } },
      },
    };
  });

  readonly topProductsConfig = computed<ChartConfiguration>(() => {
    const top = this.data()?.top_products ?? [];
    return {
      type: 'bar',
      data: {
        labels: top.map((p) => p.product_name),
        datasets: [
          {
            label: 'Revenue',
            data: top.map((p) => p.revenue),
            backgroundColor: '#6366f1',
            borderRadius: 6,
            maxBarThickness: 28,
          },
        ],
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true } },
      },
    };
  });

  readonly hasCategoryData = computed(() => (this.data()?.category_split ?? []).length > 0);
  readonly hasTopProducts = computed(() => (this.data()?.top_products ?? []).length > 0);

  readonly expiryLossConfig = computed<ChartConfiguration>(() => {
    const periods = this.expiryPeriods();
    return {
      type: 'bar',
      data: {
        labels: periods.map((p) => p.label),
        datasets: [
          {
            label: 'Purchase value',
            data: periods.map((p) => p.data.purchase_value),
            backgroundColor: '#f43f5e',
            borderRadius: 6,
            maxBarThickness: 22,
          },
          {
            label: 'Sale value',
            data: periods.map((p) => p.data.sale_value),
            backgroundColor: '#f59e0b',
            borderRadius: 6,
            maxBarThickness: 22,
          },
          {
            label: 'Margin loss',
            data: periods.map((p) => p.data.margin_loss),
            backgroundColor: '#8b5cf6',
            borderRadius: 6,
            maxBarThickness: 22,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'bottom', labels: { boxWidth: 10, usePointStyle: true, font: { size: 11 } } },
        },
        scales: {
          y: { beginAtZero: true, ticks: { callback: (v) => '₹' + v } },
          x: { grid: { display: false } },
        },
      },
    };
  });

  readonly profitChannelConfig = computed<ChartConfiguration>(() => {
    const rows = this.profitAnalytics()?.by_channel ?? [];
    return {
      type: 'doughnut',
      data: {
        labels: rows.map((r) => r.channel ?? ''),
        datasets: [
          {
            data: rows.map((r) => r.profit),
            backgroundColor: ['#0d9488', '#6366f1'],
            borderWidth: 0,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '62%',
        plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } },
      },
    };
  });

  readonly profitMonthlyConfig = computed<ChartConfiguration>(() => {
    const rows = this.profitAnalytics()?.monthly ?? [];
    return {
      type: 'bar',
      data: {
        labels: rows.map((r) => r.label),
        datasets: [
          {
            label: 'Profit earned',
            data: rows.map((r) => r.profit),
            backgroundColor: '#16a34a',
            borderRadius: 5,
            maxBarThickness: 18,
          },
          {
            label: 'Lost on discount',
            data: rows.map((r) => r.discount_loss),
            backgroundColor: '#dc2626',
            borderRadius: 5,
            maxBarThickness: 18,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'bottom', labels: { boxWidth: 8, usePointStyle: true, font: { size: 10 } } },
        },
        scales: {
          y: { beginAtZero: true, ticks: { callback: (v) => '₹' + v } },
          x: { grid: { display: false } },
        },
      },
    };
  });
}
