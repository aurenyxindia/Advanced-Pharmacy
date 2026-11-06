import { Component, ElementRef, HostListener, computed, inject, input, output, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { MrCustomer } from '../../core/services/mr.service';

/** Searchable wholesale-customer dropdown (name / contact / area / code / GST). */
@Component({
  selector: 'app-mr-customer-picker',
  imports: [CommonModule],
  template: `
    <div class="mrc">
      <div class="mrc-field" [class.is-invalid]="invalid()" [class.disabled]="disabled()" [class.open]="show()">
        <i class="bi bi-person-vcard mrc-lead"></i>
        <input
          type="text"
          class="mrc-input"
          autocomplete="off"
          maxlength="160"
          [placeholder]="loading() ? 'Loading customers…' : placeholder()"
          [disabled]="disabled()"
          [value]="show() ? term() : displayName()"
          (input)="onInput($any($event.target).value)"
          (focus)="open()"
          (keydown)="onKey($event)"
        />
        @if (selectedId() || selectedName()) {
          <button type="button" class="mrc-icon-btn" title="Clear" aria-label="Clear customer" [disabled]="disabled()" (mousedown)="clear($event)">
            <i class="bi bi-x-lg"></i>
          </button>
        }
        <button type="button" class="mrc-icon-btn" tabindex="-1" aria-label="Show customers" [disabled]="disabled()" (mousedown)="toggle($event)">
          <i class="bi" [class.bi-chevron-up]="show()" [class.bi-chevron-down]="!show()"></i>
        </button>
      </div>

      @if (selected(); as c) {
        @if (!show()) {
          <div class="mrc-meta">
            @if (c.phone) { <span><i class="bi bi-telephone"></i>{{ c.phone }}</span> }
            @if (c.area || c.city) { <span><i class="bi bi-geo-alt"></i>{{ c.area || c.city }}</span> }
            @if (c.gstin) { <span><i class="bi bi-receipt"></i>{{ c.gstin }}</span> }
            <span class="text-secondary">{{ c.code }}</span>
          </div>
        }
      }

      @if (show()) {
        <div class="mrc-panel shadow-sm">
          <div class="mrc-panel-head">
            <span>{{ filtered().length }} of {{ customers().length }} wholesale customers</span>
          </div>
          <div class="mrc-list">
            @for (c of filtered(); track c.id; let i = $index) {
              <button type="button" class="mrc-row" [class.active]="i === hi()" [class.selected]="c.id === selectedId()" (mousedown)="pick(c, $event)">
                <span class="mrc-avatar">{{ initials(c.name) }}</span>
                <span class="mrc-body">
                  <span class="mrc-name">{{ c.name }}</span>
                  <span class="mrc-sub">
                    {{ c.phone || 'No contact' }}{{ (c.area || c.city) ? ' · ' + (c.area || c.city) : '' }}{{ c.gstin ? ' · GST ' + c.gstin : '' }}
                  </span>
                </span>
                <span class="mrc-code">{{ c.code }}</span>
              </button>
            } @empty {
              <div class="mrc-empty">
                <i class="bi bi-search me-1"></i>{{ term().trim() ? 'No customer matches "' + term().trim() + '"' : 'No wholesale customers yet.' }}
              </div>
            }
          </div>
          @if (allowAdd()) {
            <button type="button" class="mrc-add" (mousedown)="add($event)">
              <i class="bi bi-person-plus me-1"></i>Add new wholesale customer
            </button>
          }
        </div>
      }
    </div>
  `,
  styles: `
    :host { display: block; }
    .mrc { position: relative; }
    .mrc-field {
      display: flex; align-items: center; gap: 0.15rem; min-height: 31px;
      border: 1px solid #dee2e6; border-radius: 0.375rem; background: #fff; padding: 0 0.25rem 0 0.55rem;
      transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }
    .mrc-field.open, .mrc-field:focus-within { border-color: #5eead4; box-shadow: 0 0 0 0.2rem rgba(13, 148, 136, 0.15); }
    .mrc-field.is-invalid { border-color: #dc3545; }
    .mrc-field.is-invalid:focus-within { box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.15); }
    .mrc-field.disabled { background: #e9ecef; }
    .mrc-lead { color: #94a3b8; font-size: 0.85rem; }
    .mrc-input {
      flex: 1 1 auto; min-width: 0; border: 0; outline: 0; background: transparent;
      font-size: 0.875rem; padding: 0.25rem 0.35rem; color: #0f172a; text-transform: uppercase;
    }
    .mrc-input::placeholder { text-transform: none; color: #94a3b8; }
    .mrc-icon-btn {
      border: 0; background: transparent; color: #64748b; width: 26px; height: 26px; border-radius: 0.35rem;
      display: inline-flex; align-items: center; justify-content: center; font-size: 0.75rem; flex-shrink: 0;
    }
    .mrc-icon-btn:hover:not(:disabled) { background: #f1f5f9; color: #0f172a; }
    .mrc-meta { display: flex; flex-wrap: wrap; gap: 0.25rem 0.75rem; margin-top: 0.3rem; font-size: 0.72rem; color: #475569; }
    .mrc-meta .bi { margin-right: 0.25rem; color: #0f766e; }
    .mrc-panel {
      position: absolute; left: 0; right: 0; top: calc(100% + 3px); z-index: 40; min-width: 260px;
      background: #fff; border: 1px solid #e2e8f0; border-radius: 0.55rem; overflow: hidden;
    }
    .mrc-panel-head { padding: 0.35rem 0.75rem; font-size: 0.7rem; color: #64748b; background: #f8fafc; border-bottom: 1px solid #eef2f7; }
    .mrc-list { max-height: 280px; overflow-y: auto; }
    .mrc-row {
      display: flex; align-items: center; gap: 0.6rem; width: 100%; padding: 0.45rem 0.75rem;
      border: 0; border-bottom: 1px solid #f1f5f9; background: #fff; text-align: left; color: #0f172a;
    }
    .mrc-row:hover, .mrc-row.active { background: #f0fdfa; box-shadow: inset 3px 0 0 #0f766e; }
    .mrc-row.selected .mrc-name { color: #0f766e; }
    .mrc-avatar {
      width: 30px; height: 30px; border-radius: 50%; flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center;
      background: rgba(13, 148, 136, 0.1); color: #0f766e; font-size: 0.7rem; font-weight: 700;
    }
    .mrc-body { display: flex; flex-direction: column; min-width: 0; flex: 1 1 auto; }
    .mrc-name { font-size: 0.82rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .mrc-sub { font-size: 0.72rem; color: #64748b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .mrc-code { font-size: 0.7rem; color: #94a3b8; flex-shrink: 0; }
    .mrc-empty { padding: 0.75rem; font-size: 0.8rem; color: #64748b; }
    .mrc-add {
      display: block; width: 100%; padding: 0.5rem 0.75rem; border: 0; border-top: 1px solid #eef2f7;
      background: #f8fafc; color: #0f766e; font-size: 0.8rem; font-weight: 600; text-align: left;
    }
    .mrc-add:hover { background: #f0fdfa; }
  `,
})
export class MrCustomerPicker {
  private readonly host = inject(ElementRef<HTMLElement>);

  readonly customers = input<MrCustomer[]>([]);
  readonly selectedId = input<number | null>(null);
  /** Shown when the selected party is not in the customer list (e.g. outstanding by name only). */
  readonly selectedName = input('');
  readonly placeholder = input('Search wholesale customer by name, contact, area or code');
  readonly loading = input(false);
  readonly disabled = input(false);
  readonly invalid = input(false);
  readonly allowAdd = input(true);

  readonly picked = output<MrCustomer | null>();
  readonly addNew = output<void>();

  readonly term = signal('');
  readonly show = signal(false);
  readonly hi = signal(0);

  readonly selected = computed(() => {
    const id = this.selectedId();
    return id ? this.customers().find((c) => c.id === id) ?? null : null;
  });

  readonly displayName = computed(() => this.selected()?.name ?? this.selectedName() ?? '');

  readonly filtered = computed(() => {
    const q = this.term().trim().toLowerCase();
    const list = this.customers();
    if (!q) return list.slice(0, 200);
    const digits = q.replace(/\D+/g, '');
    return list
      .filter((c) =>
        [c.name, c.area, c.city, c.code, c.gstin].some((v) => (v ?? '').toString().toLowerCase().includes(q))
        || (digits.length >= 3 && (c.phone ?? '').includes(digits)),
      )
      .slice(0, 200);
  });

  @HostListener('document:mousedown', ['$event'])
  onDocDown(ev: MouseEvent): void {
    if (!this.host.nativeElement.contains(ev.target as Node)) this.close();
  }

  open(): void {
    if (this.disabled() || this.show()) return;
    this.term.set('');
    this.hi.set(0);
    this.show.set(true);
  }

  toggle(ev: MouseEvent): void {
    ev.preventDefault();
    if (this.show()) {
      this.close();
    } else {
      this.open();
      (this.host.nativeElement.querySelector('.mrc-input') as HTMLInputElement | null)?.focus();
    }
  }

  onInput(v: string): void {
    this.term.set(v);
    this.hi.set(0);
    this.show.set(true);
  }

  onKey(ev: KeyboardEvent): void {
    if (ev.key === 'Escape' || ev.key === 'Tab') {
      this.close();
      return;
    }
    const list = this.filtered();
    if (ev.key === 'ArrowDown') {
      ev.preventDefault();
      if (!this.show()) this.open();
      this.hi.update((i) => Math.min(list.length - 1, i + 1));
    } else if (ev.key === 'ArrowUp') {
      ev.preventDefault();
      this.hi.update((i) => Math.max(0, i - 1));
    } else if (ev.key === 'Enter') {
      ev.preventDefault();
      const c = list[this.hi()];
      if (c) this.choose(c);
    }
  }

  pick(c: MrCustomer, ev: MouseEvent): void {
    ev.preventDefault();
    this.choose(c);
  }

  clear(ev: MouseEvent): void {
    ev.preventDefault();
    this.term.set('');
    this.show.set(false);
    this.picked.emit(null);
  }

  add(ev: MouseEvent): void {
    ev.preventDefault();
    this.close();
    this.addNew.emit();
  }

  initials(name: string): string {
    const parts = (name || '').trim().split(/\s+/).filter(Boolean);
    return ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')).toUpperCase() || '?';
  }

  private choose(c: MrCustomer): void {
    this.picked.emit(c);
    this.term.set('');
    this.show.set(false);
  }

  private close(): void {
    this.show.set(false);
    this.term.set('');
  }
}
