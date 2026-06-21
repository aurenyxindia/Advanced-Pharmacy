import { Component, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ChartConfiguration } from 'chart.js';
import { DashboardService } from '../../core/services/dashboard.service';
import { AuthService } from '../../core/services/auth.service';
import { DashboardSummary } from '../../core/models/dashboard.models';
import { ChartComponent } from '../../shared/chart/chart';

@Component({
  selector: 'app-dashboard',
  imports: [CommonModule, ChartComponent],
  templateUrl: './dashboard.html',
  styleUrl: './dashboard.scss',
})
export class Dashboard {
  private readonly dashboard = inject(DashboardService);
  private readonly auth = inject(AuthService);

  readonly user = this.auth.user;
  readonly loading = signal(true);
  readonly error = signal<string | null>(null);
  readonly data = signal<DashboardSummary | null>(null);

  readonly kpis = computed(() => this.data()?.kpis ?? null);

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
  }

  readonly salesTrendConfig = computed<ChartConfiguration>(() => {
    const trend = this.data()?.sales_trend ?? [];
    return {
      type: 'line',
      data: {
        labels: trend.map((p) => new Date(p.date).toLocaleDateString('en-IN', { day: '2-digit', month: 'short' })),
        datasets: [
          {
            label: 'Sales',
            data: trend.map((p) => p.total),
            borderColor: '#0d9488',
            backgroundColor: 'rgba(13,148,136,0.12)',
            fill: true,
            tension: 0.35,
            pointRadius: 3,
            pointBackgroundColor: '#0d9488',
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          y: { beginAtZero: true, ticks: { callback: (v) => '₹' + v } },
          x: { grid: { display: false } },
        },
      },
    };
  });

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
}
