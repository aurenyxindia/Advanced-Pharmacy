import { Component, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RefillReminder, RefillService, RefillSummary } from '../../core/services/refill.service';

@Component({
  selector: 'app-refills',
  imports: [CommonModule, FormsModule],
  templateUrl: './refills.html',
  styleUrl: './refills.scss',
})
export class Refills {
  private readonly refillSvc = inject(RefillService);

  readonly rows = signal<RefillReminder[]>([]);
  readonly summary = signal<RefillSummary>({ overdue: 0, today: 0, upcoming: 0 });
  readonly loading = signal(false);
  readonly filter = signal<string>('');

  // new reminder modal
  readonly modalOpen = signal(false);
  readonly saving = signal(false);
  readonly form = signal({ customer_name: '', phone: '', product_name: '', days_supply: 30, notes: '' });
  readonly toast = signal<string | null>(null);

  constructor() {
    this.load();
    this.loadSummary();
  }

  load(): void {
    this.loading.set(true);
    const due = ['overdue', 'today', 'upcoming'].includes(this.filter()) ? this.filter() : undefined;
    this.refillSvc.list({ due }).subscribe({
      next: (d) => { this.rows.set(d); this.loading.set(false); },
      error: () => this.loading.set(false),
    });
  }
  loadSummary(): void {
    this.refillSvc.summary().subscribe((s) => this.summary.set(s));
  }

  setFilter(f: string): void {
    this.filter.set(this.filter() === f ? '' : f);
    this.load();
  }

  openModal(): void {
    this.form.set({ customer_name: '', phone: '', product_name: '', days_supply: 30, notes: '' });
    this.modalOpen.set(true);
  }

  save(): void {
    if (!this.form().customer_name.trim()) return;
    this.saving.set(true);
    this.refillSvc.create(this.form()).subscribe({
      next: () => {
        this.saving.set(false);
        this.modalOpen.set(false);
        this.showToast('Reminder scheduled.');
        this.load();
        this.loadSummary();
      },
      error: () => this.saving.set(false),
    });
  }

  mark(row: RefillReminder, status: string): void {
    this.refillSvc.update(row.id, { status }).subscribe(() => {
      this.showToast('Reminder updated.');
      this.load();
      this.loadSummary();
    });
  }

  isOverdue(row: RefillReminder): boolean {
    return new Date(row.refill_date) < new Date(new Date().toDateString()) &&
      (row.status === 'pending' || row.status === 'notified');
  }

  statusClass(status: string): string {
    return {
      pending: 'text-bg-secondary',
      notified: 'text-bg-info',
      completed: 'text-bg-success',
      cancelled: 'text-bg-light',
    }[status] ?? 'text-bg-light';
  }

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  update(field: string, value: any): void {
    this.form.update((f) => ({ ...f, [field]: value }));
  }

  private showToast(msg: string): void {
    this.toast.set(msg);
    setTimeout(() => this.toast.set(null), 2800);
  }
}
