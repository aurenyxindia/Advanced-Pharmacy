import { Component, ElementRef, OnDestroy, WritableSignal, computed, inject, signal, viewChild } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { Subscription } from 'rxjs';
import {
  SupportFile,
  SupportReply,
  SupportService,
  SupportTicket,
} from '../../core/services/support.service';
import { SupportNotificationService } from '../../core/services/support-notification.service';

const IMAGE_RE = /\.(jpe?g|png|gif|webp|bmp)$/i;
const DOC_RE = /\.(pdf|docx?|xlsx?|csv|txt|zip|rar|pptx?)$/i;
const DOC_ACCEPT = '.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.zip,.rar,.ppt,.pptx';

@Component({
  selector: 'app-support',
  imports: [CommonModule, FormsModule],
  templateUrl: './support.html',
  styleUrl: './support.scss',
})
export class SupportPage implements OnDestroy {
  private readonly svc = inject(SupportService);
  private readonly notify = inject(SupportNotificationService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);

  readonly docAccept = DOC_ACCEPT;
  readonly statuses = ['Generated', 'Pending', 'In Process', 'Resolved', 'Rejected'];

  readonly isAdmin = signal(false);
  readonly tickets = signal<SupportTicket[]>([]);
  readonly loading = signal(false);
  readonly error = signal<string | null>(null);
  readonly limitedLog = signal(false);
  readonly logLimit = signal(20);
  statusFilter = '';
  search = '';
  readonly activeSearch = signal('');

  readonly counts = computed(() => {
    const list = this.tickets();
    const by = (s: string) => list.filter((t) => t.status === s).length;
    return {
      total: list.length,
      open: by('In Process') + by('Pending') + by('Generated'),
      resolved: by('Resolved'),
      rejected: by('Rejected'),
    };
  });

  // New ticket
  readonly newOpen = signal(false);
  readonly saving = signal(false);
  subject = '';
  queryText = '';
  readonly newShots = signal<File[]>([]);
  readonly newError = signal<string | null>(null);

  // Ticket detail + chat
  readonly view = signal<SupportTicket | null>(null);
  readonly replies = signal<SupportReply[]>([]);
  readonly loadingReplies = signal(false);
  readonly canReply = signal(false);
  replyText = '';
  readonly replyShots = signal<File[]>([]);
  readonly replyFiles = signal<File[]>([]);
  readonly sending = signal(false);
  readonly replyError = signal<string | null>(null);

  // Admin update
  adminStatus = '';
  adminRemark = '';
  readonly adminShots = signal<File[]>([]);
  readonly adminFiles = signal<File[]>([]);
  readonly updating = signal(false);
  readonly adminError = signal<string | null>(null);

  readonly blobs = signal<Record<string, string>>({});
  private readonly blobLoading = new Set<string>();
  readonly toast = signal<string | null>(null);

  private readonly chatScroll = viewChild<ElementRef<HTMLDivElement>>('chatScroll');
  private replyTimer: ReturnType<typeof setInterval> | null = null;
  private readonly sub: Subscription;

  constructor() {
    this.load();
    this.sub = this.notify.ticketsUpdated$.subscribe(() => {
      this.load(true);
      const v = this.view();
      if (v) this.loadReplies(v.id, true);
    });
  }

  ngOnDestroy(): void {
    this.sub.unsubscribe();
    this.stopReplyPolling();
    for (const url of Object.values(this.blobs())) URL.revokeObjectURL(url);
  }

  // ---- List ----
  load(silent = false): void {
    if (!silent) this.loading.set(true);
    this.error.set(null);
    const search = this.isAdmin() ? this.activeSearch() : '';
    this.svc.list({ status: this.statusFilter, search }).subscribe({
      next: (res) => {
        this.isAdmin.set(res.is_admin);
        this.limitedLog.set(res.limited_log);
        this.logLimit.set(res.log_limit ?? 20);
        this.tickets.set(res.tickets ?? []);
        this.loading.set(false);
        const v = this.view();
        if (v) {
          const fresh = res.tickets.find((t) => t.id === v.id);
          if (fresh) this.view.set(fresh);
        }
        this.openFromRoute();
      },
      error: (e: HttpErrorResponse) => {
        this.loading.set(false);
        this.error.set(this.msg(e, 'Could not load tickets.'));
      },
    });
  }

  runSearch(): void {
    this.activeSearch.set(this.search.trim());
    this.load();
  }

  clearSearch(): void {
    this.search = '';
    this.activeSearch.set('');
    this.load();
  }

  private openFromRoute(): void {
    const qp = this.route.snapshot.queryParamMap;
    const id = Number(qp.get('openTicket') || 0);
    if (!id || this.view()) return;
    const t = this.tickets().find((x) => x.id === id);
    if (!t) return;
    this.openTicket(t, qp.get('focusReply') === '1');
    void this.router.navigate([], { relativeTo: this.route, queryParams: {} });
  }

  // ---- New ticket ----
  openNew(): void {
    this.closeTicket();
    this.subject = '';
    this.queryText = '';
    this.newShots.set([]);
    this.newError.set(null);
    this.newOpen.set(true);
  }

  closeNew(): void {
    if (!this.saving()) this.newOpen.set(false);
  }

  onNewShots(ev: Event): void {
    this.newShots.set(this.pick(ev, this.newShots(), IMAGE_RE, 5, 8, this.newError));
  }

  removeNewShot(i: number): void {
    this.newShots.update((l) => l.filter((_, idx) => idx !== i));
  }

  submitTicket(): void {
    const subject = this.subject.trim();
    const query = this.queryText.trim();
    if (!subject) return this.newError.set('Please enter subject / query title.');
    if (!query) return this.newError.set('Please enter query details.');
    if (this.saving()) return;
    this.saving.set(true);
    this.newError.set(null);
    const fd = new FormData();
    fd.append('subject', subject);
    fd.append('query_text', query);
    for (const f of this.newShots()) fd.append('screenshots[]', f, f.name);
    this.svc.create(fd).subscribe({
      next: (res) => {
        this.saving.set(false);
        this.newOpen.set(false);
        this.showToast(`Ticket ${res.ticket_no} raised. Status: In Process.`);
        this.load();
      },
      error: (e: HttpErrorResponse) => {
        this.saving.set(false);
        this.newError.set(this.msg(e, 'Could not raise ticket.'));
      },
    });
  }

  // ---- Detail / chat ----
  openTicket(t: SupportTicket, focusReply = false): void {
    this.view.set(t);
    this.replies.set([]);
    this.replyText = '';
    this.replyShots.set([]);
    this.replyFiles.set([]);
    this.replyError.set(null);
    this.resetAdminForm(t);
    this.preloadTicket(t);
    this.loadReplies(t.id);
    this.startReplyPolling(t.id);
    if (focusReply) {
      setTimeout(() => document.querySelector<HTMLTextAreaElement>('.chat-input')?.focus(), 350);
    }
  }

  closeTicket(): void {
    this.stopReplyPolling();
    this.view.set(null);
    this.replies.set([]);
  }

  private resetAdminForm(t: SupportTicket): void {
    this.adminStatus = t.status;
    this.adminRemark = t.admin_remark || '';
    this.adminShots.set([]);
    this.adminFiles.set([]);
    this.adminError.set(null);
  }

  loadReplies(id: number, silent = false): void {
    if (!silent) this.loadingReplies.set(true);
    this.svc.replies(id).subscribe({
      next: (res) => {
        this.loadingReplies.set(false);
        if (this.view()?.id !== id) return;
        const prevCount = this.replies().length;
        const prevStatus = this.view()?.status;
        this.view.set(res.ticket);
        if (prevStatus !== res.ticket.status && !this.updating()) this.resetAdminForm(res.ticket);
        this.replies.set(res.replies ?? []);
        this.canReply.set(res.can_reply);
        this.preloadTicket(res.ticket);
        for (const r of res.replies ?? []) this.preload(r.screenshot_urls);
        if (!silent || (res.replies?.length ?? 0) > prevCount) this.scrollChat();
      },
      error: () => this.loadingReplies.set(false),
    });
  }

  private startReplyPolling(id: number): void {
    this.stopReplyPolling();
    this.replyTimer = setInterval(() => {
      if (this.view()?.id === id && !this.sending()) this.loadReplies(id, true);
    }, 5000);
  }

  private stopReplyPolling(): void {
    if (this.replyTimer) {
      clearInterval(this.replyTimer);
      this.replyTimer = null;
    }
  }

  onReplyShots(ev: Event): void {
    this.replyShots.set(this.pick(ev, this.replyShots(), IMAGE_RE, 5, 5, this.replyError));
  }

  onReplyFiles(ev: Event): void {
    this.replyFiles.set(this.pick(ev, this.replyFiles(), DOC_RE, 10, 5, this.replyError));
  }

  removeReplyShot(i: number): void {
    this.replyShots.update((l) => l.filter((_, idx) => idx !== i));
  }

  removeReplyFile(i: number): void {
    this.replyFiles.update((l) => l.filter((_, idx) => idx !== i));
  }

  sendReply(): void {
    const t = this.view();
    if (!t || this.sending()) return;
    const message = this.replyText.trim();
    if (!message && !this.replyShots().length && !this.replyFiles().length) {
      this.replyError.set('Enter a message or attach a file.');
      return;
    }
    this.sending.set(true);
    this.replyError.set(null);
    const fd = new FormData();
    fd.append('message', message);
    for (const f of this.replyShots()) fd.append('reply_screenshots[]', f, f.name);
    for (const f of this.replyFiles()) fd.append('reply_files[]', f, f.name);
    this.svc.postReply(t.id, fd).subscribe({
      next: (res) => {
        this.sending.set(false);
        this.replyText = '';
        this.replyShots.set([]);
        this.replyFiles.set([]);
        this.view.set(res.ticket);
        if (res.reply?.id) {
          this.replies.update((l) => [...l, res.reply]);
          this.preload(res.reply.screenshot_urls);
        }
        if (res.reopened) {
          this.resetAdminForm(res.ticket);
          this.showToast('Reply sent — ticket reopened for support.');
        }
        this.scrollChat();
        this.load(true);
      },
      error: (e: HttpErrorResponse) => {
        this.sending.set(false);
        this.replyError.set(this.msg(e, 'Could not send reply.'));
      },
    });
  }

  // ---- Admin update ----
  onAdminShots(ev: Event): void {
    this.adminShots.set(this.pick(ev, this.adminShots(), IMAGE_RE, 5, 8, this.adminError));
  }

  onAdminFiles(ev: Event): void {
    this.adminFiles.set(this.pick(ev, this.adminFiles(), DOC_RE, 10, 8, this.adminError));
  }

  removeAdminShot(i: number): void {
    this.adminShots.update((l) => l.filter((_, idx) => idx !== i));
  }

  removeAdminFile(i: number): void {
    this.adminFiles.update((l) => l.filter((_, idx) => idx !== i));
  }

  saveAdminUpdate(): void {
    const t = this.view();
    if (!t || !this.isAdmin() || this.isLocked(t) || this.updating()) return;
    if (!this.adminStatus) {
      this.adminError.set('Select a status.');
      return;
    }
    this.updating.set(true);
    this.adminError.set(null);
    const fd = new FormData();
    fd.append('status', this.adminStatus);
    fd.append('admin_remark', this.adminRemark || '');
    for (const f of this.adminShots()) fd.append('admin_screenshots[]', f, f.name);
    for (const f of this.adminFiles()) fd.append('admin_files[]', f, f.name);
    this.svc.updateStatus(t.id, fd).subscribe({
      next: (ticket) => {
        this.updating.set(false);
        this.view.set(ticket);
        this.resetAdminForm(ticket);
        this.preloadTicket(ticket);
        this.showToast(`Ticket ${ticket.ticket_no} updated.`);
        this.load(true);
        this.loadReplies(ticket.id, true);
      },
      error: (e: HttpErrorResponse) => {
        this.updating.set(false);
        this.adminError.set(this.msg(e, 'Could not update ticket.'));
      },
    });
  }

  // ---- Helpers ----
  isLocked(t: SupportTicket | null): boolean {
    return (t?.status || '').toLowerCase() === 'resolved';
  }

  isResolved(t: SupportTicket | null): boolean {
    return this.isLocked(t);
  }

  statusClass(status: string): string {
    switch ((status || '').toLowerCase()) {
      case 'generated': return 'st-generated';
      case 'pending': return 'st-pending';
      case 'in process': return 'st-process';
      case 'resolved': return 'st-resolved';
      case 'rejected': return 'st-rejected';
      default: return '';
    }
  }

  /** `2026-10-05 14:20:11` → `05-10-2026 14:20` */
  fmt(dt: string): string {
    const m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(dt || '');
    return m ? `${m[3]}-${m[2]}-${m[1]} ${m[4]}:${m[5]}` : dt || '-';
  }

  attachmentCount(t: SupportTicket): number {
    return (t.screenshot_urls?.length || 0) + (t.admin_screenshot_urls?.length || 0) + (t.admin_files?.length || 0);
  }

  private preloadTicket(t: SupportTicket): void {
    this.preload(t.screenshot_urls);
    this.preload(t.admin_screenshot_urls);
  }

  private preload(urls: string[] | undefined): void {
    for (const url of urls ?? []) {
      if (this.blobs()[url] || this.blobLoading.has(url)) continue;
      this.blobLoading.add(url);
      this.svc.fileBlob(url).subscribe({
        next: (blob) => {
          this.blobLoading.delete(url);
          this.blobs.update((m) => ({ ...m, [url]: URL.createObjectURL(blob) }));
        },
        error: () => this.blobLoading.delete(url),
      });
    }
  }

  openImage(url: string): void {
    const src = this.blobs()[url];
    if (src) window.open(src, '_blank');
  }

  downloadFile(f: SupportFile): void {
    this.svc.fileBlob(f.url).subscribe({
      next: (blob) => {
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = f.name || 'attachment';
        a.click();
        setTimeout(() => URL.revokeObjectURL(a.href), 4000);
      },
      error: () => this.showToast('Could not download file.'),
    });
  }

  private pick(
    ev: Event,
    current: File[],
    re: RegExp,
    maxMb: number,
    maxCount: number,
    err: WritableSignal<string | null>,
  ): File[] {
    const input = ev.target as HTMLInputElement;
    const next = [...current];
    const problems: string[] = [];
    for (const f of Array.from(input.files ?? [])) {
      if (!re.test(f.name)) { problems.push(`Unsupported file: ${f.name}`); continue; }
      if (f.size > maxMb * 1024 * 1024) { problems.push(`Too large (max ${maxMb} MB): ${f.name}`); continue; }
      if (next.length >= maxCount) { problems.push(`Maximum ${maxCount} files allowed.`); break; }
      next.push(f);
    }
    input.value = '';
    err.set(problems.length ? problems.join(' ') : null);
    return next;
  }

  private scrollChat(): void {
    setTimeout(() => {
      const el = this.chatScroll()?.nativeElement;
      if (el) el.scrollTop = el.scrollHeight;
    }, 60);
  }

  private msg(e: HttpErrorResponse, fallback: string): string {
    const errors = e?.error?.errors as Record<string, string[]> | undefined;
    const first = errors ? Object.values(errors)[0]?.[0] : null;
    return first || e?.error?.message || fallback;
  }

  private showToast(m: string): void {
    this.toast.set(m);
    setTimeout(() => this.toast.set(null), 3200);
  }
}
