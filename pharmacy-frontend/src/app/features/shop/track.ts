import { Component, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { OrderTracking, PublicService } from '../../core/services/public.service';

@Component({
  selector: 'app-track',
  imports: [CommonModule, FormsModule, RouterLink],
  template: `
    <div class="track-page">
      <header class="shop-header">
        <div class="container d-flex align-items-center justify-content-between">
          <a routerLink="/shop" class="d-flex align-items-center gap-2 text-decoration-none text-dark">
            <i class="bi bi-capsule-pill brand-icon"></i><span class="fw-bold">ePharmacy</span>
          </a>
          <a routerLink="/shop" class="btn btn-sm btn-light"><i class="bi bi-shop me-1"></i> Shop</a>
        </div>
      </header>

      <div class="container py-4" style="max-width: 640px;">
        <h3 class="fw-bold mb-3">Track your order</h3>
        <div class="input-group mb-4">
          <input class="form-control" placeholder="Enter tracking number or order no." [ngModel]="query()" (ngModelChange)="query.set($event)" />
          <button class="btn btn-pharma" (click)="track()" [disabled]="loading()">
            @if (loading()) { <span class="spinner-border spinner-border-sm"></span> } @else { Track }
          </button>
        </div>

        @if (error()) { <div class="alert alert-danger">{{ error() }}</div> }

        @if (result(); as r) {
          <div class="card-pharma p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <div>
                <div class="text-secondary small">Order</div>
                <div class="fw-bold">{{ r.order_no }}</div>
              </div>
              <span class="badge fs-6" [ngClass]="badge(r.status)">{{ label(r.status) }}</span>
            </div>
            <div class="text-secondary small mb-3">
              {{ r.customer_name }} · Est. {{ r.total_estimate | currency: 'INR' : 'symbol' : '1.2-2' }}
            </div>
            <div class="timeline">
              @for (e of r.events; track $index) {
                <div class="tl-item">
                  <div class="tl-dot"></div>
                  <div>
                    <div class="fw-semibold">{{ label(e.status) }}</div>
                    @if (e.note) { <div class="text-secondary small">{{ e.note }}</div> }
                    <div class="text-secondary small">{{ e.created_at | date: 'dd MMM yyyy, HH:mm' }}</div>
                  </div>
                </div>
              }
            </div>
          </div>
        }
      </div>
    </div>
  `,
  styles: [`
    :host { display: block; min-height: 100vh; background: var(--pharma-bg); }
    .shop-header { background:#fff; border-bottom:1px solid #e2e8f0; padding:.85rem 0; }
    .brand-icon { font-size:1.5rem; color: var(--pharma-primary); }
    .timeline { position: relative; padding-left: 8px; }
    .tl-item { display:flex; gap:.85rem; padding-bottom:1.1rem; position:relative; }
    .tl-item:not(:last-child)::before { content:''; position:absolute; left:5px; top:14px; bottom:-4px; width:2px; background:#e2e8f0; }
    .tl-dot { width:12px; height:12px; border-radius:50%; background: var(--pharma-primary); margin-top:4px; flex-shrink:0; }
  `],
})
export class Track {
  private readonly publicSvc = inject(PublicService);
  private readonly route = inject(ActivatedRoute);

  readonly query = signal('');
  readonly result = signal<OrderTracking | null>(null);
  readonly loading = signal(false);
  readonly error = signal<string | null>(null);

  constructor() {
    const q = this.route.snapshot.queryParamMap.get('q');
    if (q) { this.query.set(q); this.track(); }
  }

  track(): void {
    if (!this.query().trim()) return;
    this.loading.set(true);
    this.error.set(null);
    this.result.set(null);
    this.publicSvc.track(this.query().trim()).subscribe({
      next: (r) => { this.result.set(r); this.loading.set(false); },
      error: (err) => { this.error.set(err.error?.message ?? 'Order not found.'); this.loading.set(false); },
    });
  }

  label(status: string): string {
    return ({
      placed: 'Order Placed', confirmed: 'Confirmed', packed: 'Packed',
      out_for_delivery: 'Out for Delivery', delivered: 'Delivered', cancelled: 'Cancelled',
    } as Record<string, string>)[status] ?? status;
  }
  badge(status: string): string {
    return ({
      placed: 'text-bg-secondary', confirmed: 'text-bg-info', packed: 'text-bg-primary',
      out_for_delivery: 'text-bg-warning', delivered: 'text-bg-success', cancelled: 'text-bg-danger',
    } as Record<string, string>)[status] ?? 'text-bg-light';
  }
}
