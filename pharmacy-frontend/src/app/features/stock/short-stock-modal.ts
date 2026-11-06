import { Component, OnInit, computed, inject, output, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { MinInventoryRow, MinInventoryService } from '../../core/services/min-inventory.service';
import { UserRightsService } from '../../core/services/user-rights.service';

interface EditableRow extends MinInventoryRow {
  draft: number | null;
  saving: boolean;
}

const PAGE = 50;

/** Dashboard "View All" — every short-stock product, most-sold first, with per-product minimum edit. */
@Component({
  selector: 'app-short-stock-modal',
  imports: [CommonModule, FormsModule, RouterLink],
  templateUrl: './short-stock-modal.html',
  styleUrl: './short-stock-modal.scss',
})
export class ShortStockModal implements OnInit {
  private readonly svc = inject(MinInventoryService);
  private readonly rights = inject(UserRightsService);

  readonly closed = output<void>();
  readonly changed = output<void>();

  readonly canEdit = () => this.rights.canEdit('OPENING_STOCK');
  readonly canGenerateOrder = () => this.rights.canEdit('PURCHASE_ORDER');

  readonly rows = signal<EditableRow[]>([]);
  readonly loading = signal(true);
  readonly error = signal<string | null>(null);
  readonly toast = signal<string | null>(null);
  readonly defaultMin = signal(15);
  readonly salesMonths = signal(4);
  readonly search = signal('');
  readonly shown = signal(PAGE);
  private dirty = false;

  readonly filtered = computed(() => {
    const q = this.search().trim().toLowerCase();
    const list = this.rows();
    return q ? list.filter((r) => r.product_name.toLowerCase().includes(q)) : list;
  });
  readonly visible = computed(() => this.filtered().slice(0, this.shown()));
  readonly outOfStock = computed(() => this.rows().filter((r) => r.available_qty <= 0).length);

  ngOnInit(): void {
    this.svc.alerts(0).subscribe({
      next: (data) => {
        this.defaultMin.set(data.default_min);
        this.salesMonths.set(data.sales_months);
        this.rows.set(data.products.map((p) => ({ ...p, draft: p.min_qty, saving: false })));
        this.loading.set(false);
      },
      error: (err: HttpErrorResponse) => {
        this.error.set(err.error?.message ?? 'Could not load short stock.');
        this.loading.set(false);
      },
    });
  }

  onSearch(value: string): void {
    this.search.set(value);
    this.shown.set(PAGE);
  }

  showMore(): void {
    this.shown.update((n) => n + PAGE);
  }

  setDraft(id: number, value: number | null): void {
    this.patch(id, { draft: value });
  }

  isDirty(r: EditableRow): boolean {
    return r.draft !== null && Number(r.draft) !== r.min_qty;
  }

  saveMin(r: EditableRow): void {
    const qty = Math.trunc(Number(r.draft));
    if (!Number.isFinite(qty) || qty < 1) {
      this.error.set(`Minimum qty for ${r.product_name} must be at least 1.`);
      return;
    }
    this.error.set(null);
    this.patch(r.id, { saving: true });
    this.svc.save(r.id, qty).subscribe({
      next: () => {
        this.patch(r.id, {
          saving: false,
          min_qty: qty,
          custom_min: qty,
          is_custom: true,
          draft: qty,
          is_low: r.available_qty < qty,
        });
        this.dirty = true;
        this.showToast(`Minimum for ${r.product_name} set to ${qty}.`);
      },
      error: (err: HttpErrorResponse) => {
        this.patch(r.id, { saving: false });
        this.error.set(this.errorText(err));
      },
    });
  }

  resetMin(r: EditableRow): void {
    const def = this.defaultMin();
    this.patch(r.id, { saving: true });
    this.svc.remove(r.id).subscribe({
      next: () => {
        this.patch(r.id, {
          saving: false,
          min_qty: def,
          custom_min: 0,
          is_custom: false,
          draft: def,
          is_low: r.available_qty < def,
        });
        this.dirty = true;
        this.showToast(`${r.product_name} reset to default minimum (${def}).`);
      },
      error: (err: HttpErrorResponse) => {
        this.patch(r.id, { saving: false });
        this.error.set(this.errorText(err));
      },
    });
  }

  close(): void {
    if (this.dirty) this.changed.emit();
    this.closed.emit();
  }

  private patch(id: number, patch: Partial<EditableRow>): void {
    this.rows.update((rows) => rows.map((x) => (x.id === id ? { ...x, ...patch } : x)));
  }

  private showToast(msg: string): void {
    this.toast.set(msg);
    setTimeout(() => this.toast.set(null), 3000);
  }

  private errorText(err: HttpErrorResponse): string {
    const errors = err.error?.errors as Record<string, string[]> | undefined;
    const first = errors ? Object.values(errors).flat()[0] : null;
    return first || err.error?.message || 'Save failed. Please try again.';
  }
}
