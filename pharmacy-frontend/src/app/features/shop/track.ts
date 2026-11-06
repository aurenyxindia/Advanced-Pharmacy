import { Component, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import {
  OrderListItem,
  OrderTracking,
  OrderTrackList,
  PublicService,
  TrackResult,
} from '../../core/services/public.service';

@Component({
  selector: 'app-track',
  imports: [CommonModule, FormsModule, RouterLink],
  templateUrl: './track.html',
  styleUrl: './track.scss',
})
export class Track {
  private readonly publicSvc = inject(PublicService);
  private readonly route = inject(ActivatedRoute);

  readonly mode = signal<'phone' | 'ref'>('phone');
  readonly query = signal('');
  readonly detail = signal<OrderTracking | null>(null);
  readonly list = signal<OrderListItem[] | null>(null);
  readonly listPhone = signal('');
  readonly loading = signal(false);
  readonly cancelling = signal(false);
  readonly error = signal<string | null>(null);
  readonly successMsg = signal<string | null>(null);

  constructor() {
    const q = this.route.snapshot.queryParamMap.get('q');
    const phone = this.route.snapshot.queryParamMap.get('phone');
    if (phone) {
      this.mode.set('phone');
      this.query.set(phone);
      this.track();
    } else if (q) {
      this.mode.set('ref');
      this.query.set(q);
      this.track();
    }
  }

  setMode(m: 'phone' | 'ref'): void {
    this.mode.set(m);
    this.error.set(null);
    this.detail.set(null);
    this.list.set(null);
  }

  track(): void {
    const q = this.query().trim();
    if (!q) {
      this.error.set(
        this.mode() === 'phone'
          ? 'Enter the mobile number used when placing the order.'
          : 'Enter your order number or tracking number.'
      );
      return;
    }
    if (this.mode() === 'phone') {
      const digits = q.replace(/\D+/g, '');
      if (digits.length < 10) {
        this.error.set('Enter a valid 10-digit mobile number.');
        return;
      }
    }

    this.loading.set(true);
    this.error.set(null);
    this.detail.set(null);
    this.list.set(null);

    this.publicSvc.track(q, this.mode()).subscribe({
      next: (r) => this.applyResult(r),
      error: (err) => {
        this.error.set(err.error?.message ?? 'Order not found.');
        this.loading.set(false);
      },
    });
  }

  openOrder(orderNo: string): void {
    this.mode.set('ref');
    this.query.set(orderNo);
    this.loading.set(true);
    this.error.set(null);
    this.detail.set(null);
    this.list.set(null);
    this.publicSvc.track(orderNo, 'ref').subscribe({
      next: (r) => this.applyResult(r),
      error: (err) => {
        this.error.set(err.error?.message ?? 'Order not found.');
        this.loading.set(false);
      },
    });
  }

  backToList(): void {
    if (!this.listPhone()) return;
    this.mode.set('phone');
    this.query.set(this.listPhone());
    this.track();
  }

  canCancel(status: string, flag?: boolean): boolean {
    if (flag === false) return false;
    if (flag === true) return true;
    return !['delivered', 'cancelled', 'out_for_delivery'].includes(status);
  }

  cancelOrder(): void {
    const d = this.detail();
    if (!d || !this.canCancel(d.status, d.cancellable)) return;
    if (!confirm(`Cancel order ${d.order_no}? Reserved medicines will return to pharmacy stock.`)) return;

    this.cancelling.set(true);
    this.error.set(null);
    this.successMsg.set(null);

    const phone = d.phone || (this.mode() === 'phone' ? this.query() : this.listPhone());
    this.publicSvc.cancelOrder({ order_no: d.order_no, phone: phone || undefined }).subscribe({
      next: () => {
        this.cancelling.set(false);
        this.successMsg.set('Order cancelled. Stock has been returned to the pharmacy.');
        this.openOrder(d.order_no);
      },
      error: (err) => {
        this.cancelling.set(false);
        this.error.set(err.error?.message ?? 'Could not cancel the order.');
      },
    });
  }

  private applyResult(r: TrackResult): void {
    this.loading.set(false);
    if ((r as OrderTrackList).mode === 'list') {
      const list = r as OrderTrackList;
      this.list.set(list.orders);
      this.listPhone.set(list.phone);
      this.detail.set(null);
      return;
    }
    const d = r as OrderTracking;
    this.detail.set(d);
    this.list.set(null);
  }

  label(status: string): string {
    return (
      {
        placed: 'Order placed',
        confirmed: 'Confirmed',
        packed: 'Packed',
        out_for_delivery: 'Out for delivery',
        delivered: 'Delivered',
        cancelled: 'Cancelled',
      } as Record<string, string>
    )[status] ?? status;
  }

  badge(status: string): string {
    return (
      {
        placed: 'st-placed',
        confirmed: 'st-confirmed',
        packed: 'st-packed',
        out_for_delivery: 'st-out',
        delivered: 'st-delivered',
        cancelled: 'st-cancelled',
      } as Record<string, string>
    )[status] ?? 'st-placed';
  }
}
