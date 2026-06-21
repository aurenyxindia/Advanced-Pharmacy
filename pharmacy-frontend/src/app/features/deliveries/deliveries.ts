import { Component, inject, signal } from '@angular/core';
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
  readonly rows = signal<Record<string, any>[]>([]);
  readonly loading = signal(false);
  readonly filter = signal('');
  readonly statuses = STATUSES;

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
    this.orderSvc.list({ status: this.filter() || undefined }).subscribe({
      next: (res) => { this.rows.set(res.data); this.loading.set(false); },
      error: () => this.loading.set(false),
    });
  }

  setFilter(s: string): void {
    this.filter.set(this.filter() === s ? '' : s);
    this.load();
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
    this.orderSvc.updateStatus(Number(order['id']), {
      status: this.newStatus(),
      assigned_to: this.assignedTo() || undefined,
      note: this.note() || undefined,
    }).subscribe(() => {
      this.toast.set('Order updated.');
      setTimeout(() => this.toast.set(null), 2500);
      this.selected.set(null);
      this.load();
    });
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
