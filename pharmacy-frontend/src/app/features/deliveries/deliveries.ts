import { Component, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { OrderService } from '../../core/services/order.service';

const STATUSES = ['placed', 'confirmed', 'packed', 'out_for_delivery', 'delivered', 'cancelled'];

@Component({
  selector: 'app-deliveries',
  imports: [CommonModule, FormsModule],
  templateUrl: './deliveries.html',
  styleUrl: './deliveries.scss',
})
export class Deliveries {
  private readonly orderSvc = inject(OrderService);

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  readonly allRows = signal<Record<string, any>[]>([]);
  readonly loading = signal(false);
  readonly filter = signal('');
  readonly statuses = STATUSES;

  /** Table rows for the active status filter. */
  readonly rows = computed(() => {
    const f = this.filter();
    const all = this.allRows();
    if (!f) return all;
    return all.filter((r) => String(r['status']) === f);
  });

  /** KPIs from full order list (not affected by filter chips). */
  readonly kpis = computed(() => {
    const all = this.allRows();
    let active = 0;
    let outForDelivery = 0;
    let delivered = 0;
    let estimate = 0;
    for (const r of all) {
      const status = String(r['status'] ?? '');
      estimate += Number(r['total_estimate']) || 0;
      if (status === 'out_for_delivery') outForDelivery++;
      if (status === 'delivered') delivered++;
      if (status !== 'delivered' && status !== 'cancelled') active++;
    }
    return {
      orders: all.length,
      active,
      outForDelivery,
      delivered,
      estimate: Math.round(estimate * 100) / 100,
    };
  });

  // detail drawer
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  readonly selected = signal<Record<string, any> | null>(null);
  readonly newStatus = signal('');
  readonly assignedTo = signal('');
  readonly note = signal('');
  readonly toast = signal<string | null>(null);

  constructor() {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.orderSvc.list({ perPage: 500 }).subscribe({
      next: (res) => {
        this.allRows.set(res.data ?? []);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  setFilter(s: string): void {
    this.filter.set(this.filter() === s ? '' : s);
  }

  open(id: number): void {
    this.orderSvc.get(id).subscribe((o) => {
      this.selected.set(o);
      this.newStatus.set(String(o['status']));
      this.assignedTo.set(String(o['assigned_to'] ?? ''));
      this.note.set('');
    });
  }

  saveStatus(): void {
    const order = this.selected();
    if (!order) return;
    if (this.newStatus() === 'cancelled' && String(order['status']) !== 'cancelled') {
      if (!confirm('Cancel this order? Reserved stock will return to inventory.')) return;
    }
    this.orderSvc.updateStatus(Number(order['id']), {
      status: this.newStatus(),
      assigned_to: this.assignedTo() || undefined,
      note: this.note() || undefined,
    }).subscribe({
      next: () => {
        this.toast.set(this.newStatus() === 'cancelled' ? 'Order cancelled · stock returned.' : 'Order updated.');
        setTimeout(() => this.toast.set(null), 2500);
        this.selected.set(null);
        this.load();
      },
      error: (err) => {
        this.toast.set(err.error?.message ?? 'Could not update order.');
        setTimeout(() => this.toast.set(null), 3500);
      },
    });
  }

  cancelOrder(): void {
    const order = this.selected();
    if (!order) return;
    this.newStatus.set('cancelled');
    this.note.set(this.note() || 'Cancelled by pharmacy');
    this.saveStatus();
  }

  label(status: string): string {
    return ({
      placed: 'Placed', confirmed: 'Confirmed', packed: 'Packed',
      out_for_delivery: 'Out for delivery', delivered: 'Delivered', cancelled: 'Cancelled',
    } as Record<string, string>)[status] ?? status;
  }
  badge(status: string): string {
    return ({
      placed: 'text-bg-secondary', confirmed: 'text-bg-info', packed: 'text-bg-primary',
      out_for_delivery: 'text-bg-warning', delivered: 'text-bg-success', cancelled: 'text-bg-danger',
    } as Record<string, string>)[status] ?? 'text-bg-light';
  }
}
