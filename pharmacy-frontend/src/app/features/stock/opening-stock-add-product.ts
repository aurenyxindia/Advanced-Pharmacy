import { Component, effect, inject, input, output, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpErrorResponse } from '@angular/common/http';
import { CrudService } from '../../core/services/crud.service';
import { MetaService } from '../../core/services/meta.service';
import { ProductSearchResult } from '../../core/services/purchase.service';
import { DOSAGE_FORMS } from '../masters/master.config';
import { toUpper } from '../../core/utils/input-format';

export interface OpeningAddProductPrefill {
  name?: string;
  pack_size?: string;
  mrp?: number;
  manufacturer?: string;
}

interface MfgOption {
  name: string;
  sCode: string;
}

@Component({
  selector: 'app-opening-stock-add-product',
  imports: [CommonModule, FormsModule],
  templateUrl: './opening-stock-add-product.html',
  styleUrl: './opening-stock-add-product.scss',
})
export class OpeningStockAddProduct {
  private readonly crud = inject(CrudService);
  private readonly meta = inject(MetaService);

  readonly open = input(false);
  readonly prefill = input<OpeningAddProductPrefill | null>(null);
  readonly saved = output<ProductSearchResult>();
  readonly close = output<void>();

  readonly saving = signal(false);
  readonly error = signal<string | null>(null);
  readonly generics = signal<{ id: number; name: string }[]>([]);
  readonly manufacturers = signal<MfgOption[]>([]);

  readonly packSizeOptions = signal<{ pack: string; units: number; form: string; label?: string }[]>([]);
  readonly packSizeSearch = signal('');
  readonly showPackDropdown = signal(false);
  readonly filteredPackSizes = signal<{ pack: string; units: number; form: string; label?: string }[]>([]);

  readonly dosageSearch = signal('');
  readonly showDosageDropdown = signal(false);
  readonly filteredDosages = signal<string[]>([]);
  readonly dosageForms = DOSAGE_FORMS;

  readonly mfgSearch = signal('');
  readonly showMfgDropdown = signal(false);
  readonly filteredManufacturers = signal<MfgOption[]>([]);

  readonly genericSearch = signal('');
  readonly showGenericDropdown = signal(false);
  readonly filteredGenerics = signal<{ id: number; name: string }[]>([]);

  private packBlurTimer?: ReturnType<typeof setTimeout>;
  private dosageBlurTimer?: ReturnType<typeof setTimeout>;
  private mfgBlurTimer?: ReturnType<typeof setTimeout>;
  private genericBlurTimer?: ReturnType<typeof setTimeout>;
  private genericSearchTimer?: ReturnType<typeof setTimeout>;

  readonly productTypes = ['Medicine', 'General', 'Food/Nutra', 'Cosmetic', 'Patanjali'];
  readonly schedules = ['OTC', 'H', 'H1', 'X', 'G', 'RESTRICTED'];
  readonly categories = ['Allopathy', 'Ayurvedic', 'Generic', 'Surgical', 'Cosmetic'];

  readonly form = signal({
    product_type: 'Medicine',
    name: '',
    generic_id: null as number | null,
    schedule_category: 'OTC',
    product_category: 'Allopathy',
    pack_size: '',
    units_per_pack: 1,
    dosage_form: '',
    strength: '',
    mrp: 0,
    packing_desc: '',
    manufacturer: '',
    manufacturer_short: '',
  });

  constructor() {
    this.crud.list<{ id: number; name: string }>('generics', { perPage: 5000 }).subscribe((r) =>
      this.generics.set(r.data ?? []),
    );
    this.crud.list<Record<string, unknown>>('manufacturers', { perPage: 500 }).subscribe((r) => {
      this.manufacturers.set(
        (r.data ?? []).map((m) => ({
          name: String(m['name'] ?? ''),
          sCode: String(m['s_code'] ?? '').toUpperCase(),
        })).filter((m) => m.name),
      );
    });
    this.meta.loadPackSizes().subscribe((list) => {
      this.packSizeOptions.set(list ?? []);
    });

    effect(() => {
      if (this.open()) {
        this.applyPrefill(this.prefill());
      }
    });
  }

  applyPrefill(p: OpeningAddProductPrefill | null): void {
    const pack = toUpper(p?.pack_size ?? '');
    const mfg = p?.manufacturer ?? '';
    this.form.set({
      product_type: 'Medicine',
      name: toUpper(p?.name ?? ''),
      generic_id: null,
      schedule_category: 'OTC',
      product_category: 'Allopathy',
      pack_size: pack,
      units_per_pack: 1,
      dosage_form: '',
      strength: '',
      mrp: Number(p?.mrp) || 0,
      packing_desc: '',
      manufacturer: mfg,
      manufacturer_short: '',
    });
    this.packSizeSearch.set(pack);
    this.dosageSearch.set('');
    this.mfgSearch.set(mfg);
    this.genericSearch.set('');
    if (pack) this.ensurePackOptionInList(pack);
    this.error.set(null);
    this.closeAllDropdowns();
  }

  patch(field: string, value: unknown): void {
    this.form.update((f) => ({ ...f, [field]: value }));
  }

  onModalClick(ev: MouseEvent): void {
    const t = ev.target as HTMLElement;
    if (!t.closest('.field-select-wrap')) {
      this.closeAllDropdowns();
    }
  }

  closeAllDropdowns(): void {
    this.showPackDropdown.set(false);
    this.showDosageDropdown.set(false);
    this.showMfgDropdown.set(false);
    this.showGenericDropdown.set(false);
  }

  // ---- Pack size (Retail / purchase parity) ----
  packLabel(p: { pack: string; label?: string }): string {
    return (p.label || p.pack).trim();
  }

  openPackDropdown(): void {
    if (this.packBlurTimer) {
      clearTimeout(this.packBlurTimer);
      this.packBlurTimer = undefined;
    }
    this.filterPackSizes();
    this.showPackDropdown.set(true);
    this.showDosageDropdown.set(false);
    this.showMfgDropdown.set(false);
    this.showGenericDropdown.set(false);
  }

  onPackSearchInput(value: string): void {
    this.packSizeSearch.set(value);
    this.filterPackSizes();
    this.showPackDropdown.set(true);
  }

  filterPackSizes(): void {
    const list = this.packSizeOptions();
    const q = this.packSizeSearch().trim().toLowerCase();
    const filtered = !q
      ? list
      : list.filter((p) => {
          const label = this.packLabel(p).toLowerCase();
          return label.includes(q) || p.pack.toLowerCase().includes(q);
        });
    this.filteredPackSizes.set(filtered.slice(0, 40));
  }

  selectPackSize(p: { pack: string; units: number; form?: string; label?: string }): void {
    const pack = p.pack.trim();
    this.packSizeSearch.set(this.packLabel(p));
    this.form.update((f) => ({ ...f, pack_size: pack, units_per_pack: p.units || 1 }));
    this.showPackDropdown.set(false);
  }

  onPackSearchBlur(): void {
    this.packBlurTimer = setTimeout(() => {
      this.showPackDropdown.set(false);
      const typed = this.packSizeSearch().trim();
      if (typed) this.applyPackFromSearch(typed);
      this.packBlurTimer = undefined;
    }, 180);
  }

  private applyPackFromSearch(typed: string): void {
    const list = this.packSizeOptions();
    const lower = typed.toLowerCase();
    const found =
      list.find((p) => p.pack.toLowerCase() === lower) ??
      list.find((p) => this.packLabel(p).toLowerCase() === lower);
    if (found) {
      this.selectPackSize(found);
      return;
    }
    const pack = typed.toUpperCase();
    this.ensurePackOptionInList(pack);
    this.form.update((f) => ({ ...f, pack_size: pack }));
    this.meta.parsePack(pack).subscribe({
      next: (parsed) => {
        if (!parsed?.pack) return;
        this.ensurePackOptionInList(parsed.pack, parsed.units, parsed.form);
        this.form.update((f) => ({
          ...f,
          pack_size: parsed.pack.trim().toUpperCase(),
          units_per_pack: parsed.units || f.units_per_pack,
        }));
        this.packSizeSearch.set(parsed.pack.trim().toUpperCase());
      },
    });
  }

  ensurePackOptionInList(pack: string, units = 1, form = ''): void {
    const target = pack.trim();
    if (!target) return;
    const list = this.packSizeOptions();
    if (list.some((p) => p.pack.toLowerCase() === target.toLowerCase())) return;
    this.packSizeOptions.set([{ pack: target, units, form }, ...list]);
  }

  // ---- Dosage ----
  openDosageDropdown(): void {
    if (this.dosageBlurTimer) {
      clearTimeout(this.dosageBlurTimer);
      this.dosageBlurTimer = undefined;
    }
    this.filterDosages();
    this.showDosageDropdown.set(true);
    this.showPackDropdown.set(false);
    this.showMfgDropdown.set(false);
    this.showGenericDropdown.set(false);
  }

  onDosageSearchInput(value: string): void {
    this.dosageSearch.set(value);
    this.filterDosages();
    this.showDosageDropdown.set(true);
  }

  filterDosages(): void {
    const q = this.dosageSearch().trim().toLowerCase();
    const filtered = !q
      ? [...this.dosageForms]
      : this.dosageForms.filter((d) => d.toLowerCase().includes(q));
    this.filteredDosages.set(filtered.slice(0, 30));
  }

  selectDosage(d: string): void {
    const val = d.trim().toUpperCase();
    this.dosageSearch.set(val);
    this.patch('dosage_form', val);
    this.showDosageDropdown.set(false);
  }

  onDosageSearchBlur(): void {
    this.dosageBlurTimer = setTimeout(() => {
      this.showDosageDropdown.set(false);
      const typed = this.dosageSearch().trim();
      if (typed) this.patch('dosage_form', typed.toUpperCase());
      this.dosageBlurTimer = undefined;
    }, 180);
  }

  // ---- Manufacturer ----
  openMfgDropdown(): void {
    if (this.mfgBlurTimer) {
      clearTimeout(this.mfgBlurTimer);
      this.mfgBlurTimer = undefined;
    }
    this.filterManufacturers();
    this.showMfgDropdown.set(true);
    this.showPackDropdown.set(false);
    this.showDosageDropdown.set(false);
    this.showGenericDropdown.set(false);
  }

  // ---- Generic (searchable — Retail / product master parity) ----
  openGenericDropdown(): void {
    if (this.genericBlurTimer) {
      clearTimeout(this.genericBlurTimer);
      this.genericBlurTimer = undefined;
    }
    this.filterGenerics();
    this.showGenericDropdown.set(true);
    this.showPackDropdown.set(false);
    this.showDosageDropdown.set(false);
    this.showMfgDropdown.set(false);
  }

  onGenericSearchInput(value: string): void {
    const term = toUpper(value);
    this.genericSearch.set(term);
    const curId = this.form().generic_id;
    if (curId != null) {
      const match = this.generics().find((g) => g.id === curId);
      if (!match || match.name.toUpperCase() !== term.trim()) {
        this.patch('generic_id', null);
      }
    }
    this.filterGenerics();
    this.showGenericDropdown.set(true);

    if (this.genericSearchTimer) clearTimeout(this.genericSearchTimer);
    const q = term.trim();
    if (q.length >= 2) {
      this.genericSearchTimer = setTimeout(() => this.fetchGenericsFromServer(q), 250);
    }
  }

  private fetchGenericsFromServer(term: string): void {
    this.crud.list<{ id: number; name: string }>('generics', { search: term, perPage: 80 }).subscribe({
      next: (r) => {
        if (this.genericSearch().trim().toUpperCase() !== term.toUpperCase()) return;
        const rows = r.data ?? [];
        this.filteredGenerics.set(rows);
        if (rows.length) {
          const merged = [...this.generics()];
          for (const row of rows) {
            if (!merged.some((g) => g.id === row.id)) merged.push(row);
          }
          merged.sort((a, b) => a.name.localeCompare(b.name));
          this.generics.set(merged);
        }
      },
    });
  }

  filterGenerics(): void {
    const q = this.genericSearch().trim().toLowerCase();
    const list = this.generics();
    const filtered = !q ? list : list.filter((g) => g.name.toLowerCase().includes(q));
    this.filteredGenerics.set(filtered.slice(0, 80));
  }

  selectGeneric(g: { id: number; name: string }): void {
    this.genericSearch.set(g.name);
    this.patch('generic_id', g.id);
    this.showGenericDropdown.set(false);
  }

  onGenericSearchBlur(): void {
    this.genericBlurTimer = setTimeout(() => {
      this.showGenericDropdown.set(false);
      const id = this.form().generic_id;
      if (id != null) {
        const match = this.generics().find((g) => g.id === id);
        if (match) this.genericSearch.set(match.name);
      } else if (!this.genericSearch().trim()) {
        this.patch('generic_id', null);
      }
      this.genericBlurTimer = undefined;
    }, 180);
  }

  onMfgSearchInput(value: string): void {
    this.mfgSearch.set(value);
    this.filterManufacturers();
    this.showMfgDropdown.set(true);
  }

  filterManufacturers(): void {
    const q = this.mfgSearch().trim().toLowerCase();
    const list = this.manufacturers();
    const filtered = !q
      ? list
      : list.filter((m) =>
          m.name.toLowerCase().includes(q) || m.sCode.toLowerCase().includes(q),
        );
    this.filteredManufacturers.set(filtered.slice(0, 40));
  }

  selectManufacturer(m: MfgOption): void {
    this.mfgSearch.set(m.name);
    this.form.update((f) => ({
      ...f,
      manufacturer: m.name,
      manufacturer_short: m.sCode,
    }));
    this.showMfgDropdown.set(false);
  }

  onMfgSearchBlur(): void {
    this.mfgBlurTimer = setTimeout(() => {
      this.showMfgDropdown.set(false);
      const typed = this.mfgSearch().trim();
      if (!typed) {
        this.form.update((f) => ({ ...f, manufacturer: '', manufacturer_short: '' }));
        this.mfgBlurTimer = undefined;
        return;
      }
      const match = this.manufacturers().find(
        (m) => m.name.toLowerCase() === typed.toLowerCase() || m.sCode.toLowerCase() === typed.toLowerCase(),
      );
      if (match) {
        this.selectManufacturer(match);
      } else {
        this.form.update((f) => ({ ...f, manufacturer: typed, manufacturer_short: f.manufacturer_short }));
      }
      this.mfgBlurTimer = undefined;
    }, 180);
  }

  save(): void {
    const f = this.form();
    if (!f.name.trim()) { this.error.set('Product name is required.'); return; }
    if (!f.pack_size.trim()) { this.error.set('Pack size is required.'); return; }
    if (!(Number(f.mrp) > 0)) { this.error.set('MRP is required.'); return; }

    this.saving.set(true);
    this.error.set(null);
    this.crud.create<Record<string, unknown>>('products', {
      product_type: f.product_type,
      name: f.name.trim(),
      generic_id: f.generic_id,
      schedule_category: f.schedule_category,
      product_category: f.product_category,
      pack_size: f.pack_size.trim(),
      units_per_pack: Math.max(1, f.units_per_pack || 1),
      dosage_form: f.dosage_form || null,
      strength: f.strength?.trim() ? f.strength.trim().toUpperCase() : null,
      mrp: f.mrp,
      packing_desc: f.packing_desc || null,
      manufacturer: f.manufacturer || null,
      manufacturer_short: f.manufacturer_short || null,
      default_gst_rate: 12,
      allow_loose: 1,
    }).subscribe({
      next: (res) => {
        const id = Number(res.data?.['id']);
        this.crud.post('products/bind', { master_product_id: id, reorder_level: 0 }).subscribe({
          next: () => {
            this.saving.set(false);
            this.saved.emit({
              id,
              name: f.name.trim(),
              manufacturer: f.manufacturer || null,
              schedule_category: f.schedule_category,
              default_gst_rate: 12,
              generic_name: null,
              hsn_code: null,
              pack_size: f.pack_size.trim(),
              units_per_pack: Math.max(1, f.units_per_pack || 1),
              mrp: f.mrp,
              manufacturer_short: f.manufacturer_short || null,
              product_category: f.product_category,
            });
          },
          error: (err: HttpErrorResponse) => {
            this.saving.set(false);
            this.error.set(err.error?.message ?? 'Product saved but branch bind failed.');
          },
        });
      },
      error: (err: HttpErrorResponse) => {
        this.saving.set(false);
        this.error.set(err.error?.message ?? 'Could not save product.');
      },
    });
  }
}
