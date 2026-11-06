import { Component, input, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router } from '@angular/router';
import { SupportNotification } from '../../core/services/support.service';
import { SupportNotificationService } from '../../core/services/support-notification.service';

@Component({
  selector: 'app-support-popup',
  imports: [CommonModule],
  templateUrl: './support-popup.html',
  styleUrl: './support-popup.scss',
})
export class SupportPopup {
  private readonly notify = inject(SupportNotificationService);
  private readonly router = inject(Router);

  readonly notification = input<SupportNotification | null>(null);

  badge(): string {
    switch (this.notification()?.type) {
      case 'new_ticket': return 'New Support Ticket';
      case 'ticket_reply': return 'New Ticket Reply';
      default: return 'Ticket Status Updated';
    }
  }

  title(): string {
    switch (this.notification()?.type) {
      case 'new_ticket': return 'A client needs assistance';
      case 'ticket_reply': return 'There is a new message on a support ticket';
      default: return 'Your ticket was updated by support';
    }
  }

  icon(): string {
    switch (this.notification()?.type) {
      case 'new_ticket': return 'bi-life-preserver';
      case 'ticket_reply': return 'bi-chat-dots';
      default: return 'bi-arrow-repeat';
    }
  }

  body(): string {
    const n = this.notification();
    if (!n) return '';
    if (n.type === 'ticket_reply') return n.reply?.message || '';
    if (n.type === 'status_update') return n.ticket.admin_remark || '';
    return n.ticket.query_text || '';
  }

  canReply(): boolean {
    const n = this.notification();
    return !this.notify.isAdmin && !!n?.ticket?.id && (n.ticket.status || '').toLowerCase() !== 'rejected';
  }

  dismiss(): void {
    this.notify.dismissActive();
  }

  open(focusReply = false): void {
    const id = this.notification()?.ticket?.id;
    this.dismiss();
    void this.router.navigate(['/app/support'], {
      queryParams: id ? { openTicket: id, ...(focusReply ? { focusReply: '1' } : {}) } : {},
    });
  }
}
