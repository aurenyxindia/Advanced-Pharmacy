import { Injectable, inject } from '@angular/core';
import { BehaviorSubject } from 'rxjs';
import { AgtOrder, AgtService } from './agt.service';

export interface AgtOrderNotification {
  orderId: number;
  order: AgtOrder | null;
  loading: boolean;
}

/** Polls Placed AGT orders and queues popups for newly seen orders (Retail parity). */
@Injectable({ providedIn: 'root' })
export class AgtOrderNotificationService {
  private readonly agt = inject(AgtService);

  private polling = false;
  private pollTimer: ReturnType<typeof setInterval> | null = null;
  private pollRefCount = 0;
  private seeded = false;
  private readonly seenOrderIds = new Set<number>();
  private queue: number[] = [];
  private loadingNext = false;
  private readonly pollIntervalMs = 5000;

  readonly activeNotification$ = new BehaviorSubject<AgtOrderNotification | null>(null);

  startPolling(): void {
    this.pollRefCount++;
    if (this.polling) return;
    this.polling = true;
    this.pollOnce();
    this.pollTimer = setInterval(() => this.pollOnce(), this.pollIntervalMs);
  }

  stopPolling(): void {
    this.pollRefCount = Math.max(0, this.pollRefCount - 1);
    if (this.pollRefCount > 0 || !this.polling) return;
    this.polling = false;
    if (this.pollTimer) {
      clearInterval(this.pollTimer);
      this.pollTimer = null;
    }
  }

  dismissActive(): void {
    this.activeNotification$.next(null);
    this.showNext();
  }

  private pollOnce(): void {
    this.agt.listOrders('Placed', 50).subscribe({
      next: (orders) => {
        for (const row of orders) {
          const id = Number(row?.id) || 0;
          if (!id || this.seenOrderIds.has(id)) continue;
          this.seenOrderIds.add(id);
          if (!this.seeded) continue;
          this.queue.push(id);
        }
        this.seeded = true;
        if (this.queue.length > 0 && !this.activeNotification$.value && !this.loadingNext) {
          this.showNext();
        }
      },
      error: () => {
        this.seeded = true;
      },
    });
  }

  private showNext(): void {
    if (this.loadingNext) return;
    const orderId = this.queue.shift();
    if (!orderId) {
      this.activeNotification$.next(null);
      return;
    }
    this.loadingNext = true;
    this.activeNotification$.next({ orderId, order: null, loading: true });
    this.agt.getOrder(orderId).subscribe({
      next: (order) => {
        this.loadingNext = false;
        if (!order || order.status !== 'Placed') {
          this.showNext();
          return;
        }
        this.activeNotification$.next({ orderId, order, loading: false });
      },
      error: () => {
        this.loadingNext = false;
        this.showNext();
      },
    });
  }
}
