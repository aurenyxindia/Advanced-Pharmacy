import { Component, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpErrorResponse } from '@angular/common/http';
import { MinInventoryRow, MinInventoryService } from '../../core/services/min-inventory.service';

@Component({
  selector: 'app-min-inventory',
  imports: [CommonModule, FormsModule],
  templateUrl: './min-inventory.html',
  styleUrl: './min-inventory.scss',
})
export class MinInventory implements OnInit {
  private readonly svc = inject(MinInventoryService);

  readonly query = signal('');
  readonly results = signal<MinInventoryRow[]>([]);
  readonly resultsOpen = signal(false);
  readonly activeResult = signal(-1);
  readonly selected = signal<MinInventoryRow | null>(null);
  minQty: number | null = null;
  readonly defaultMin = 15;

  readonly triggers = signal<MinInventoryRow[]>([]);
  readonly loading = signal(true);
  readonly saving = signal(false);
  readonly removingId = signal<number | null>(null);
  readonly error = signal<string | null>(null);
  readonly toast = signal<string | null>(null);

  private searchTimer?: ReturnType<typeof setTimeout>;

  ngOnInit(): void {
    this.loadTriggers();
  }

  loadTriggers(): void {
    this.loading.set(true);
    this.svc.triggers().subscribe({
      next: (rows) => {
        this.triggers.set(rows);
        this.loading.set(false);
      },
      error: (err: HttpErrorResponse) => {
        this.triggers.set([]);
        this.loading.set(false);
        this.error.set(err.error?.message ?? 'Could not load minimum inventory triggers.');
      },
    });
  }

  openResults(): void {
    this.resultsOpen.set(true);
    if (!this.results().length) this.search(this.query());
  }

  onQueryInput(value: string): void {
    this.query.set(value);
    this.selected.set(null);
    this.resultsOpen.set(true);
    clearTimeout(this.searchTimer);
    this.searchTimer = setTimeout(() => this.search(value), 300);
  }

  private search(value: string): void {
    this.svc.products(value.trim()).subscribe({
      next: (rows) => {
        this.results.set(rows);
        this.activeResult.set(rows.length ? 0 : -1);
      },
      error: () => this.results.set([]),
    });
  }

  onQueryKeydown(ev: KeyboardEvent): void {
    const list = this.results();
    if (!this.resultsOpen() || !list.length) return;
    if (ev.key === 'ArrowDown') {
      ev.preventDefault();
      this.activeResult.update((i) => (i + 1) % list.length);
    } else if (ev.key === 'ArrowUp') {
      ev.preventDefault();
      this.activeResult.update((i) => (i - 1 + list.length) % list.length);
    } else if (ev.key === 'Enter') {
      ev.preventDefault();
      const hit = list[this.activeResult()];
      if (hit) this.pick(hit);
    } else if (ev.key === 'Escape') {
      this.resultsOpen.set(false);
    }
  }

  pick(row: MinInventoryRow): void {
    this.selected.set(row);
    this.query.set(row.product_name);
    this.minQty = row.min_qty > 0 ? row.min_qty : this.defaultMin;
    this.resultsOpen.set(false);
    setTimeout(() => document.getElementById('mi-min-qty')?.focus(), 0);
  }

  edit(row: MinInventoryRow): void {
    this.pick(row);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  closeResults(): void {
    setTimeout(() => this.resultsOpen.set(false), 150);
  }

  packHint(units: number, upp: number): string {
    if (!upp || upp <= 1) return '';
    const packs = Math.floor(units / upp);
    const loose = units % upp;
    return loose ? `${packs} pack + ${loose}` : `${packs} pack`;
  }

  save(): void {
    const row = this.selected();
    if (!row) {
      this.error.set('Please select a product.');
      return;
    }
    const qty = Number(this.minQty);
    if (this.minQty === null || !Number.isFinite(qty) || qty < 1) {
      this.error.set('Minimum qty must be at least 1.');
      return;
    }
    this.saving.set(true);
    this.error.set(null);
    this.svc.save(row.id, Math.trunc(qty)).subscribe({
      next: (res) => {
        this.saving.set(false);
        this.showToast(res.message || 'Minimum inventory trigger saved.');
        this.clear();
        this.loadTriggers();
      },
      error: (err: HttpErrorResponse) => {
        this.saving.set(false);
        const field = err.error?.errors ? (Object.values(err.error.errors)[0] as string[])?.[0] : null;
        this.error.set(field || err.error?.message || 'Save failed. Please try again.');
      },
    });
  }

  remove(row: MinInventoryRow): void {
    if (!confirm(`Reset ${row.product_name} to the default minimum (${this.defaultMin})?`)) return;
    this.removingId.set(row.id);
    this.svc.remove(row.id).subscribe({
      next: (res) => {
        this.removingId.set(null);
        this.showToast(res.message || 'Minimum reset to default.');
        this.loadTriggers();
      },
      error: (err: HttpErrorResponse) => {
        this.removingId.set(null);
        this.error.set(err.error?.message ?? 'Delete failed.');
      },
    });
  }

  clear(): void {
    this.selected.set(null);
    this.query.set('');
    this.results.set([]);
    this.minQty = null;
  }

  private showToast(msg: string): void {
    this.toast.set(msg);
    setTimeout(() => this.toast.set(null), 4000);
  }
}
