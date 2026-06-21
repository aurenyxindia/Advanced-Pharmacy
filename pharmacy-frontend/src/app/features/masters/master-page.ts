import { Component, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { CrudService, Paginated } from '../../core/services/crud.service';
import { MetaService } from '../../core/services/meta.service';
import { FormField, MASTER_CONFIGS, MasterConfig } from './master.config';

type Row = Record<string, unknown>;
interface Option { value: string | number; label: string; }

@Component({
  selector: 'app-master-page',
  imports: [CommonModule, ReactiveFormsModule],
  templateUrl: './master-page.html',
  styleUrl: './master-page.scss',
})
export class MasterPage {
  private readonly route = inject(ActivatedRoute);
  private readonly crud = inject(CrudService);
  private readonly meta = inject(MetaService);
  private readonly fb = inject(FormBuilder);

  config!: MasterConfig;

  readonly rows = signal<Row[]>([]);
  readonly total = signal(0);
  readonly page = signal(1);
  readonly perPage = signal(10);
  readonly search = signal('');
  readonly loading = signal(false);

  readonly modalOpen = signal(false);
  readonly editingId = signal<number | null>(null);
  readonly saving = signal(false);
  readonly formError = signal<string | null>(null);
  readonly toast = signal<string | null>(null);

  readonly optionsMap = signal<Record<string, Option[]>>({});
  readonly statCards = signal<{ label: string; value: number; sub?: string; icon?: string }[]>([]);
  form!: FormGroup;

  readonly totalPages = computed(() => Math.max(1, Math.ceil(this.total() / this.perPage())));

  constructor() {
    const key = this.route.snapshot.data['configKey'] as string;
    this.config = MASTER_CONFIGS[key];
    this.buildForm();
    this.loadOptions();
    this.loadStats();
    this.load();
  }

  private loadStats(): void {
    if (!this.config.statsPath) return;
    this.crud.fetch<{ cards: { label: string; value: number; sub?: string; icon?: string }[] }>(this.config.statsPath)
      .subscribe((d) => this.statCards.set(d.cards ?? []));
  }

  private buildForm(): void {
    const group: Record<string, unknown> = {};
    for (const field of this.config.fields) {
      group[field.key] = ['', field.required ? [Validators.required] : []];
    }
    this.form = this.fb.group(group);
  }

  private loadOptions(): void {
    const needs = new Set(this.config.fields.map((f) => f.optionSource).filter(Boolean));
    if (needs.has('schedule')) {
      this.meta.loadScheduleCategories().subscribe((d) =>
        this.setOptions('schedule', d.map((s) => ({ value: s.code, label: `${s.code} - ${s.name}` }))),
      );
    }
    if (needs.has('branches')) {
      this.meta.loadBranches().subscribe((d) =>
        this.setOptions('branches', d.map((b) => ({ value: b.id, label: `${b.code} - ${b.name}` }))),
      );
    }
    if (needs.has('roles')) {
      this.meta.loadRoles().subscribe((d) =>
        this.setOptions('roles', d.map((r) => ({ value: r.name, label: r.label }))),
      );
    }
    if (needs.has('generics')) {
      this.crud.list<Row>('generics', { perPage: 200 }).subscribe((d) =>
        this.setOptions('generics', d.data.map((g) => ({ value: g['id'] as number, label: g['name'] as string }))),
      );
    }
  }

  private setOptions(source: string, opts: Option[]): void {
    this.optionsMap.update((m) => ({ ...m, [source]: opts }));
  }

  optionsFor(field: FormField): Option[] {
    if (field.options) return field.options;
    if (field.optionSource) return this.optionsMap()[field.optionSource] ?? [];
    return [];
  }

  load(): void {
    this.loading.set(true);
    this.crud
      .list<Row>(this.config.resourcePath, {
        search: this.search() || undefined,
        page: this.page(),
        perPage: this.perPage(),
      })
      .subscribe({
        next: (res: Paginated<Row>) => {
          this.rows.set(res.data);
          this.total.set(res.total);
          this.loading.set(false);
        },
        error: () => this.loading.set(false),
      });
  }

  onSearch(value: string): void {
    this.search.set(value);
    this.page.set(1);
    this.load();
  }

  goToPage(p: number): void {
    if (p < 1 || p > this.totalPages()) return;
    this.page.set(p);
    this.load();
  }

  openCreate(): void {
    this.editingId.set(null);
    this.formError.set(null);
    this.form.reset();
    this.applyEditValidators(false);
    this.modalOpen.set(true);
  }

  openEdit(row: Row): void {
    this.editingId.set(row['id'] as number);
    this.formError.set(null);
    this.form.reset();
    const patch: Record<string, unknown> = {};
    for (const field of this.config.fields) {
      if (field.type === 'password') continue;
      patch[field.key] = row[field.key] ?? '';
    }
    this.form.patchValue(patch);
    this.applyEditValidators(true);
    this.modalOpen.set(true);
  }

  private applyEditValidators(isEdit: boolean): void {
    // Password becomes optional when editing.
    const pwd = this.config.fields.find((f) => f.type === 'password');
    if (pwd) {
      const ctrl = this.form.get(pwd.key);
      ctrl?.setValidators(isEdit ? [] : pwd.required ? [Validators.required] : []);
      ctrl?.updateValueAndValidity();
    }
  }

  closeModal(): void {
    this.modalOpen.set(false);
  }

  save(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }
    this.saving.set(true);
    this.formError.set(null);

    const raw = this.form.getRawValue() as Record<string, unknown>;
    // Drop empty password on edit.
    if (this.editingId() && (raw['password'] === '' || raw['password'] == null)) {
      delete raw['password'];
    }

    const req = this.editingId()
      ? this.crud.update(this.config.resourcePath, this.editingId()!, raw)
      : this.crud.create(this.config.resourcePath, raw);

    req.subscribe({
      next: () => {
        this.saving.set(false);
        this.modalOpen.set(false);
        this.showToast(this.editingId() ? 'Record updated.' : 'Record created.');
        this.load();
        this.loadStats();
      },
      error: (err: HttpErrorResponse) => {
        this.saving.set(false);
        const errors = err.error?.errors;
        const first = errors ? (Object.values(errors)[0] as string[])?.[0] : null;
        this.formError.set(first ?? err.error?.message ?? 'Could not save record.');
      },
    });
  }

  remove(row: Row): void {
    if (!confirm(`Delete "${row['name']}"? This action cannot be undone.`)) return;
    this.crud.remove(this.config.resourcePath, row['id'] as number).subscribe({
      next: () => {
        this.showToast('Record deleted.');
        this.load();
        this.loadStats();
      },
      error: (err: HttpErrorResponse) => this.showToast(err.error?.message ?? 'Delete failed.'),
    });
  }

  bind(row: Row): void {
    this.crud.post('products/bind', { master_product_id: row['id'], reorder_level: 0 }).subscribe({
      next: () => { this.showToast(`"${row['name']}" added to your branch catalog.`); this.loadStats(); },
      error: (err: HttpErrorResponse) => this.showToast(err.error?.message ?? 'Bind failed.'),
    });
  }

  private showToast(msg: string): void {
    this.toast.set(msg);
    setTimeout(() => this.toast.set(null), 2800);
  }

  private readonly scheduleMeaning: Record<string, string> = {
    OTC: 'Over the Counter', H: 'Schedule H — Prescription drug',
    H1: 'Schedule H1 — Habit-forming / Antibiotic', X: 'Schedule X — Narcotic / Psychotropic',
    G: 'Schedule G — Under medical supervision', RESTRICTED: 'Restricted — Prescription mandatory',
  };

  badgeTitle(col: { key: string }, row: Row): string {
    if (col.key === 'schedule_category') {
      return this.scheduleMeaning[String(row[col.key])] ?? '';
    }
    return '';
  }

  formatCell(row: Row, col: { key: string; type?: string }): string {
    const value = row[col.key];
    if (value == null || value === '') return '-';
    if (col.type === 'currency') {
      return '₹' + Number(value).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    return String(value);
  }
}
