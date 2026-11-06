import { Component, ElementRef, HostListener, inject, input, output, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { MrService, MrStockRow } from '../../core/services/mr.service';

/** Search live wholesale stock (batch-wise) and pick one row — Retail MR stock ng-select. */
@Component({
  selector: 'app-mr-stock-picker',
  imports: [CommonModule],
  template: `
    <div class="mr-picker">
      <div class="input-icon">
        <i class="bi bi-search"></i>
        <input
          type="search"
          class="form-control form-control-sm"
          autocomplete="off"
          [placeholder]="placeholder()"
          [disabled]="disabled()"
          [value]="term()"
          (input)="onInput($any($event.target).value)"
          (focus)="open()"
          (keydown)="onKey($event)"
        />
      </div>
      @if (show()) {
        <div class="mr-picker-panel shadow-sm">
          @if (loading()) {
            <div class="px-3 py-2 small text-secondary"><span class="spinner-border spinner-border-sm me-2"></span>Searching stock…</div>
          } @else {
            @for (s of rows(); track s.batch_id; let i = $index) {
              <button type="button" class="mr-picker-row" [class.active]="i === hi()" (mousedown)="pick(s, $event)">
                <span class="fw-semibold text-truncate">{{ s.p_name }}</span>
                <span class="small text-secondary">
                  {{ s.pack_size || '—' }} · B: {{ s.batch_no }} · Exp {{ s.expiry_date ? (s.expiry_date | date: 'MM/yy') : '—' }}
                  · Qty {{ s.qty }} · MRP {{ s.mrp | number: '1.2-2' }} · Rate {{ s.sale_rate | number: '1.2-2' }}{{ s.m_name ? ' · ' + s.m_name : '' }}
                </span>
              </button>
            } @empty {
              <div class="px-3 py-2 small text-secondary">No stock found — type to search product / batch.</div>
            }
          }
        </div>
      }
    </div>
  `,
  styles: `
    :host { display: block; }
    .mr-picker { position: relative; }
    .input-icon { position: relative; }
    .input-icon > i { position: absolute; left: 0.6rem; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 0.8rem; pointer-events: none; }
    .input-icon .form-control { padding-left: 1.85rem; }
    .mr-picker-panel {
      position: absolute; left: 0; right: 0; top: calc(100% + 2px); z-index: 30;
      max-height: 300px; overflow-y: auto; background: #fff;
      border: 1px solid #e2e8f0; border-radius: 0.5rem;
    }
    .mr-picker-row {
      display: flex; flex-direction: column; align-items: flex-start; width: 100%; min-width: 0;
      padding: 0.4rem 0.75rem; border: 0; border-bottom: 1px solid #f1f5f9; background: #fff; text-align: left;
      font-size: 0.8rem; color: #0f172a;
    }
    .mr-picker-row > span { max-width: 100%; }
    .mr-picker-row:hover, .mr-picker-row.active { background: #f0fdfa; box-shadow: inset 3px 0 0 #0f766e; }
  `,
})
export class MrStockPicker {
  private readonly svc = inject(MrService);
  private readonly host = inject(ElementRef<HTMLElement>);

  readonly placeholder = input('Search product / batch from live stock');
  readonly disabled = input(false);
  readonly picked = output<MrStockRow>();

  readonly term = signal('');
  readonly rows = signal<MrStockRow[]>([]);
  readonly loading = signal(false);
  readonly show = signal(false);
  readonly hi = signal(0);
  private timer?: ReturnType<typeof setTimeout>;
  private req = 0;
  private loadedFor: string | null = null;

  @HostListener('document:mousedown', ['$event'])
  onDocDown(ev: MouseEvent): void {
    if (!this.host.nativeElement.contains(ev.target as Node)) this.show.set(false);
  }

  open(): void {
    this.show.set(true);
    if (this.loadedFor !== this.term().trim()) this.search();
  }

  onInput(v: string): void {
    this.term.set(v);
    this.show.set(true);
    clearTimeout(this.timer);
    this.timer = setTimeout(() => this.search(), 250);
  }

  onKey(ev: KeyboardEvent): void {
    const list = this.rows();
    if (ev.key === 'Escape') {
      this.show.set(false);
      return;
    }
    if (!list.length) return;
    if (ev.key === 'ArrowDown') {
      ev.preventDefault();
      this.show.set(true);
      this.hi.update((i) => Math.min(list.length - 1, i + 1));
    } else if (ev.key === 'ArrowUp') {
      ev.preventDefault();
      this.hi.update((i) => Math.max(0, i - 1));
    } else if (ev.key === 'Enter') {
      ev.preventDefault();
      const s = list[this.hi()];
      if (s) this.choose(s);
    }
  }

  pick(s: MrStockRow, ev: MouseEvent): void {
    ev.preventDefault();
    this.choose(s);
  }

  clear(): void {
    this.term.set('');
  }

  private choose(s: MrStockRow): void {
    this.picked.emit(s);
    this.term.set('');
    this.show.set(false);
  }

  private search(): void {
    const q = this.term().trim();
    const req = ++this.req;
    this.loading.set(true);
    this.svc.stock(q).subscribe({
      next: (rows) => {
        if (req !== this.req) return;
        this.rows.set((rows ?? []).slice(0, 150));
        this.hi.set(0);
        this.loading.set(false);
        this.loadedFor = q;
      },
      error: () => {
        if (req !== this.req) return;
        this.rows.set([]);
        this.loading.set(false);
      },
    });
  }
}
