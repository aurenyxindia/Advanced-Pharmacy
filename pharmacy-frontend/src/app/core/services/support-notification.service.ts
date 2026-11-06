import { Injectable, inject } from '@angular/core';
import { BehaviorSubject, Subject } from 'rxjs';
import { SupportNotification, SupportService } from './support.service';

/**
 * Polls support events (Retail parity): admin (client 1) is told about new
 * tickets and client replies; clients about status changes and support replies.
 */
@Injectable({ providedIn: 'root' })
export class SupportNotificationService {
  private readonly support = inject(SupportService);

  private pollTimer: ReturnType<typeof setInterval> | null = null;
  private since = '';
  private readonly seenKeys = new Set<string>();
  private queue: SupportNotification[] = [];
  private readonly pollIntervalMs = 15000;

  readonly activeNotification$ = new BehaviorSubject<SupportNotification | null>(null);
  /** Emits when the ticket list / open conversation should refresh. */
  readonly ticketsUpdated$ = new Subject<void>();
  isAdmin = false;

  startPolling(): void {
    if (this.pollTimer) return;
    this.pollOnce();
    this.pollTimer = setInterval(() => this.pollOnce(), this.pollIntervalMs);
  }

  stopPolling(): void {
    if (this.pollTimer) {
      clearInterval(this.pollTimer);
      this.pollTimer = null;
    }
    this.since = '';
    this.queue = [];
    this.seenKeys.clear();
    this.activeNotification$.next(null);
  }

  dismissActive(): void {
    this.activeNotification$.next(null);
    this.showNext();
  }

  private pollOnce(): void {
    this.support.poll(this.since).subscribe({
      next: (res) => {
        this.isAdmin = res.is_admin;
        if (res.server_time) this.since = res.server_time;
        let fresh = false;
        for (const n of res.notifications ?? []) {
          if (!n?.key || this.seenKeys.has(n.key)) continue;
          this.seenKeys.add(n.key);
          this.queue.push(n);
          fresh = true;
        }
        if (fresh) {
          this.ticketsUpdated$.next();
          if (!this.activeNotification$.value) this.showNext();
        }
      },
      error: () => {},
    });
  }

  private showNext(): void {
    this.activeNotification$.next(this.queue.shift() ?? null);
  }
}
