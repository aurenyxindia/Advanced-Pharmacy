import { Component, computed, HostListener, inject, input, OnInit, output, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import {
  AbstractControl,
  FormBuilder,
  FormGroup,
  FormsModule,
  ReactiveFormsModule,
  ValidationErrors,
  ValidatorFn,
  Validators,
} from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { CrudService, Paginated } from '../../core/services/crud.service';
import { MetaService } from '../../core/services/meta.service';
import { ApiResponse } from '../../core/models/auth.models';
import { PurchaseDraftService } from '../../core/services/purchase-draft.service';
import { FormField, FieldValidation, MASTER_CONFIGS, MasterConfig, GST_STATE_CODES, ShowWhenRule } from './master.config';

type Row = Record<string, unknown>;
interface Option { value: string | number; label: string; sCode?: string; units?: number; }

/** Allow empty, otherwise enforce the pattern (for optional fields). */
function optionalPattern(regex: RegExp): ValidatorFn {
  return (control: AbstractControl): ValidationErrors | null => {
    const v = (control.value ?? '').toString().trim();
    if (v === '') return null;
    return regex.test(v) ? null : { pattern: true };
  };
}

/** GSTIN: exactly 15 chars + Indian GST format. Empty allowed only when optional. */
function gstinValidator(required = false): ValidatorFn {
  const format = /^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/;
  return (control: AbstractControl): ValidationErrors | null => {
    const v = (control.value ?? '').toString().trim().toUpperCase();
    if (v === '') return required ? { required: true } : null;
    if (v.length !== 15) return { gstinLength: { actual: v.length } };
    if (!format.test(v)) return { gstinFormat: true };
    return null;
  };
}

const VALIDATION_PATTERNS: Record<Exclude<FieldValidation, 'gstin'>, RegExp> = {
  phone: /^[6-9]\d{9}$/,
  phoneDistributor: /^\d{10,12}$/,
  pincode: /^[1-9][0-9]{5}$/,
  hsn: /^\d{4,8}$/,
  hsnStrict: /^(?:\d{4}|\d{6}|\d{8})$/,
  fssai: /^\d{14}$/,
  pan: /^[A-Z]{5}[0-9]{4}[A-Z]$/,
  ifsc: /^[A-Z]{4}0[A-Z0-9]{6}$/,
};

const VALIDATION_MESSAGES: Record<string, string> = {
  required: 'This field is required.',
  email: 'Enter a valid email address.',
  phone: 'Enter a valid 10-digit mobile number (starts with 6–9).',
  phoneDistributor: 'Enter a valid contact number (10 to 12 digits).',
  gstin: 'Enter a valid 15-character GSTIN (e.g. 22AAAAA0000A1Z5).',
  gstinLength: 'GSTIN must be exactly 15 characters.',
  gstinFormat: 'Invalid GSTIN format. Example: 22AAAAA0000A1Z5',
  pincode: 'Enter a valid 6-digit pincode.',
  hsn: 'HSN must be 4 to 8 digits.',
  hsnStrict: 'Enter a valid HSN: 4, 6, or 8 digits only.',
  fssai: 'FSSAI must be exactly 14 digits.',
  pan: 'Enter a valid PAN (e.g. ABCDE1234F).',
  ifsc: 'Enter a valid 11-character IFSC (e.g. ABCD0123456).',
  min: 'Value is too small.',
  max: 'Value is too large.',
  minlength: 'Value is too short.',
  maxlength: 'Value is too long.',
};

@Component({
  selector: 'app-master-page',
  imports: [CommonModule, ReactiveFormsModule, FormsModule],
  templateUrl: './master-page.html',
  styleUrl: './master-page.scss',
})
export class MasterPage implements OnInit {
  /** Embedded mode: render only the create popup for this config (e.g. from a sale form). */
  readonly embedKey = input<string | null>(null);
  readonly embedPreset = input<Record<string, unknown> | null>(null);
  /** Fields shown but not editable in embedded create (e.g. customer_type). */
  readonly embedLock = input<string[]>([]);
  readonly embedSaved = output<{ id: number; values: Record<string, unknown> }>();
  readonly embedClosed = output<void>();
  readonly embedded = signal(false);

  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly crud = inject(CrudService);
  private readonly meta = inject(MetaService);
  private readonly fb = inject(FormBuilder);
  private readonly purchaseDraft = inject(PurchaseDraftService);

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

  /** Rate Margin Master: Common (Fixed) margin row, category = COMMON. */
  readonly commonMargin = signal<{ id: number | null; ptr: number | null; pts: number | null }>({ id: null, ptr: null, pts: null });
  readonly commonSaving = signal(false);
  readonly commonError = signal<string | null>(null);

  /** When opened from Purchase / Opening Stock (or similar), return here after create. */
  readonly returnUrl = signal<string | null>(null);
  readonly returnBanner = signal<string | null>(null);
  readonly returnBackLabel = signal('Back');

  readonly optionsMap = signal<Record<string, Option[]>>({});
  readonly statCards = signal<{ label: string; value: number; sub?: string; icon?: string }[]>([]);
  /** Soft KPI cards (payments / credit / debit). */
  readonly kpiCards = signal<{ label: string; value: string; icon: string; tone: string }[]>([]);
  readonly kpiLoading = signal(false);
  /** Active list filters (query params). */
  readonly listFilterValues = signal<Record<string, string>>({});
  /** Category options with counts from stats (generics). */
  readonly categoryOptions = signal<{ name: string; count: number }[]>([]);
  readonly scheduleCounts = signal<Record<string, number>>({});
  form!: FormGroup;

  /** Searchable pack dropdown in product modal (Retail parity). */
  readonly packSizeSearch = signal('');
  readonly showPackDropdown = signal(false);
  readonly filteredPackSizes = signal<{ pack: string; units: number; form: string; label?: string }[]>([]);
  private packBlurTimer?: ReturnType<typeof setTimeout>;

  /** Searchable generic dropdown + inline create (Generic Master API). */
  readonly genericSearch = signal('');
  readonly showGenericDropdown = signal(false);
  readonly filteredGenerics = signal<Option[]>([]);
  private genericBlurTimer?: ReturnType<typeof setTimeout>;
  readonly genericCreateOpen = signal(false);
  readonly genericSaving = signal(false);
  readonly genericCreateError = signal<string | null>(null);
  genericCreateForm!: FormGroup;

  /** Searchable manufacturer dropdown + inline create (Manufacturer Master API). */
  readonly manufacturerSearch = signal('');
  readonly showManufacturerDropdown = signal(false);
  readonly filteredManufacturers = signal<Option[]>([]);
  private manufacturerBlurTimer?: ReturnType<typeof setTimeout>;
  readonly manufacturerCreateOpen = signal(false);
  readonly manufacturerSaving = signal(false);
  readonly manufacturerCreateError = signal<string | null>(null);
  manufacturerCreateForm!: FormGroup;

  readonly totalPages = computed(() => Math.max(1, Math.ceil(this.total() / this.perPage())));

  ngOnInit(): void {
    const embedKey = this.embedKey();
    const key = embedKey || (this.route.snapshot.data['configKey'] as string);
    this.config = MASTER_CONFIGS[key];
    this.embedded.set(!!embedKey);
    this.buildForm();
    this.buildGenericCreateForm();
    this.buildManufacturerCreateForm();
    this.loadOptions();
    if (this.embedded()) {
      this.openEmbeddedCreate();
      return;
    }
    this.loadStats();
    this.loadKpis();
    this.load();
    this.handleExternalCreate();
  }

  private buildGenericCreateForm(): void {
    this.genericCreateForm = this.fb.group({
      name: ['', [Validators.required, Validators.minLength(2), Validators.maxLength(180)]],
      therapeutic_class: ['', [Validators.maxLength(120)]],
      schedule_category: ['OTC', [Validators.required]],
    });
  }

  private buildManufacturerCreateForm(): void {
    this.manufacturerCreateForm = this.fb.group({
      name: ['', [Validators.required, Validators.minLength(2), Validators.maxLength(150)]],
      s_code: ['', [Validators.required, Validators.minLength(1), Validators.maxLength(20)]],
    });
  }

  /** Deep-link from purchase: ?returnUrl=/app/purchase/new&create=1&name=FOO */
  private handleExternalCreate(): void {
    const q = this.route.snapshot.queryParamMap;
    const returnRaw = q.get('returnUrl');
    const create = q.get('create') === '1';
    const prefillName = (q.get('name') ?? '').trim();

    if (returnRaw) {
      const safe = this.purchaseDraft.safeReturnUrl(returnRaw, '/app/purchase/new');
      this.returnUrl.set(safe);
      const fromOpening = safe.includes('opening-stock');
      const fromPurchase = safe.includes('purchase') && !safe.includes('purchase/import');
      const fromImport = safe.includes('purchase/import');
      if (this.config.key === 'products') {
        this.returnBackLabel.set(fromOpening ? 'Back to Opening Stock' : fromPurchase ? 'Back to Purchase' : 'Back');
        this.returnBanner.set(
          fromOpening
            ? 'Creating a product for Opening Stock — you will return after save.'
            : fromPurchase
              ? 'Creating a product for New Purchase — you will return after save.'
              : 'You will return to the previous form after save.',
        );
      } else if (this.config.key === 'distributors') {
        this.returnBackLabel.set(
          fromImport ? 'Back to Import Bill' : fromPurchase ? 'Back to Purchase' : 'Back',
        );
        this.returnBanner.set(
          fromImport
            ? 'Creating a distributor for Import Bill — you will return after save.'
            : fromPurchase
              ? 'Creating a distributor for New Purchase — you will return after save.'
              : 'You will return to the previous form after save.',
        );
      } else {
        this.returnBackLabel.set('Back');
        this.returnBanner.set('You will return to the previous form after save.');
      }
    }

    if (create && this.config.canCreate) {
      // Defer so form controls exist and options can load.
      setTimeout(() => {
        this.openCreate();
        if (prefillName && this.form.get('name')) {
          this.form.patchValue({ name: prefillName.toUpperCase() });
        }
      }, 0);
    }
  }

  cancelReturn(): void {
    const url = this.returnUrl();
    if (url) {
      this.router.navigateByUrl(url);
      return;
    }
    this.closeModal();
  }

  goBack(): void {
    this.router.navigateByUrl(this.config.key === 'branches' ? '/app/ho' : '/app/masters');
  }

  private loadStats(): void {
    if (!this.config.statsPath) return;
    this.crud
      .fetch<{
        cards: { label: string; value: number; sub?: string; icon?: string }[];
        categories?: { name: string; count: number }[];
        schedule_counts?: Record<string, number>;
      }>(this.config.statsPath)
      .subscribe((d) => {
        this.statCards.set(d.cards ?? []);
        this.categoryOptions.set(d.categories ?? []);
        this.scheduleCounts.set(d.schedule_counts ?? {});
      });
  }

  filterOptions(_filterKey: string, source: 'schedule' | 'genericCategories'): Option[] {
    if (source === 'genericCategories') {
      return this.categoryOptions().map((c) => ({
        value: c.name === 'Uncategorised' ? '__UNCATEGORISED__' : c.name,
        label: `${c.name} (${c.count.toLocaleString('en-IN')})`,
      }));
    }
    const counts = this.scheduleCounts();
    const base = this.optionsMap()['schedule'] ?? [
      { value: 'OTC', label: 'OTC - Over The Counter' },
      { value: 'H', label: 'H - Schedule H' },
      { value: 'H1', label: 'H1 - Schedule H1' },
      { value: 'X', label: 'X - Schedule X' },
      { value: 'G', label: 'G - Schedule G' },
      { value: 'RESTRICTED', label: 'RESTRICTED' },
    ];
    return base.map((o) => {
      const code = String(o.value);
      const n = counts[code];
      return {
        value: o.value,
        label: n != null ? `${o.label} (${n.toLocaleString('en-IN')})` : o.label,
      };
    });
  }

  onListFilter(key: string, value: string): void {
    this.listFilterValues.update((m) => {
      const next = { ...m };
      if (!value) delete next[key];
      else next[key] = value;
      return next;
    });
    this.page.set(1);
    this.load();
  }

  clearListFilters(): void {
    this.listFilterValues.set({});
    this.page.set(1);
    this.load();
  }

  /** Soft KPI summary for payments / credit-notes / debit-notes. */
  private loadKpis(): void {
    const preset = this.config.kpiPreset;
    if (!preset) {
      this.kpiCards.set([]);
      return;
    }
    this.kpiLoading.set(true);
    this.crud.list<Row>(this.config.resourcePath, { perPage: 2000, page: 1 }).subscribe({
      next: (res) => {
        this.kpiCards.set(this.buildKpiCards(preset, res.data ?? []));
        this.kpiLoading.set(false);
      },
      error: () => {
        this.kpiCards.set([]);
        this.kpiLoading.set(false);
      },
    });
  }

  private buildKpiCards(
    preset: 'payments' | 'credit-notes' | 'debit-notes' | 'accounts',
    rows: Row[],
  ): { label: string; value: string; icon: string; tone: string }[] {
    const fmt = (n: number) =>
      n.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    if (preset === 'payments') {
      let receipts = 0;
      let payments = 0;
      for (const r of rows) {
        const amt = Number(r['amount']) || 0;
        const type = String(r['voucher_type'] ?? '').toLowerCase();
        if (type === 'receipt') receipts += amt;
        else if (type === 'payment') payments += amt;
      }
      const net = Math.round((receipts - payments) * 100) / 100;
      return [
        { label: 'Vouchers', value: String(rows.length), icon: 'bi-journal-text', tone: 'kpi-teal' },
        { label: 'Receipts In', value: fmt(receipts), icon: 'bi-arrow-down-left', tone: 'kpi-green' },
        { label: 'Payments Out', value: fmt(payments), icon: 'bi-arrow-up-right', tone: 'kpi-amber' },
        { label: 'Net', value: fmt(net), icon: 'bi-cash-stack', tone: net >= 0 ? 'kpi-slate' : 'kpi-rose' },
      ];
    }

    if (preset === 'accounts') {
      let active = 0;
      let opening = 0;
      let bankCash = 0;
      for (const r of rows) {
        if (Number(r['is_active'] ?? 1) === 1) active++;
        opening += Number(r['opening_balance']) || 0;
        const t = String(r['type'] ?? '').toLowerCase();
        if (t === 'bank' || t === 'cash') bankCash++;
      }
      return [
        { label: 'Accounts', value: String(rows.length), icon: 'bi-journal-text', tone: 'kpi-teal' },
        { label: 'Active', value: String(active), icon: 'bi-check2-circle', tone: 'kpi-green' },
        { label: 'Opening Balance', value: fmt(opening), icon: 'bi-cash-stack', tone: 'kpi-slate' },
        { label: 'Bank & Cash', value: String(bankCash), icon: 'bi-bank', tone: 'kpi-blue' },
      ];
    }

    // credit-notes / debit-notes
    let amount = 0;
    let gst = 0;
    for (const r of rows) {
      amount += Number(r['amount']) || 0;
      gst += Number(r['gst_amount']) || 0;
    }
    const noteLabel = preset === 'credit-notes' ? 'Credit Notes' : 'Debit Notes';
    return [
      { label: noteLabel, value: String(rows.length), icon: preset === 'credit-notes' ? 'bi-file-earmark-minus' : 'bi-file-earmark-plus', tone: 'kpi-teal' },
      { label: 'Total Amount', value: fmt(amount), icon: 'bi-cash-stack', tone: 'kpi-slate' },
      { label: 'Total GST', value: fmt(gst), icon: 'bi-percent', tone: 'kpi-blue' },
    ];
  }

  private buildForm(): void {
    const group: Record<string, unknown> = {};
    for (const field of this.config.fields) {
      group[field.key] = ['', this.validatorsFor(field)];
    }
    this.form = this.fb.group(group);
    this.wireManufacturerShortSync();
    this.wireProductTypeBehavior();
    this.wireDistributorStateBehavior();
    this.wireShowWhenBehavior();
  }

  /** Distributor Master: state selection auto-fills GST state code (Retail). */
  private wireDistributorStateBehavior(): void {
    if (this.config.key !== 'distributors') return;
    const stateCtrl = this.form.get('state');
    if (!stateCtrl) return;
    stateCtrl.valueChanges.subscribe((stateName: string | null) => {
      const match = GST_STATE_CODES.find((s) => s.value === stateName);
      if (match) {
        this.form.patchValue({ gst_state_code: match.code }, { emitEvent: false });
      }
    });
  }

  /** Product Master: picking a manufacturer fills S Code / manufacturer_short. */
  private wireManufacturerShortSync(): void {
    if (this.config.key !== 'products') return;
    const mfr = this.form.get('manufacturer');
    if (!mfr) return;
    mfr.valueChanges.subscribe((name: string | number | null) => {
      const key = String(name ?? '').trim();
      if (!key) return;
      const opt = (this.optionsMap()['manufacturers'] ?? []).find((o) => String(o.value) === key);
      if (opt?.sCode) {
        this.form.patchValue({ manufacturer_short: opt.sCode }, { emitEvent: false });
      }
    });
  }

  /** Product Master: adjust field rules/visibility when product type changes. */
  private wireProductTypeBehavior(): void {
    if (this.config.key !== 'products') return;
    const typeCtrl = this.form.get('product_type');
    if (!typeCtrl) return;
    typeCtrl.valueChanges.subscribe(() => {
      this.applyEditValidators(!!this.editingId());
      this.clearHiddenProductFields();
    });
  }

  /** Customer Master: wholesale-only fields (DL No, FSSAI, etc.). */
  private wireCustomerTypeBehavior(): void {
    // Replaced by wireShowWhenBehavior.
  }

  /** Re-validate / clear when any showWhen dependency changes. */
  private wireShowWhenBehavior(): void {
    const deps = new Set<string>();
    for (const field of this.config.fields) {
      for (const rule of this.showWhenRules(field)) {
        deps.add(rule.field);
      }
    }
    for (const key of deps) {
      this.form.get(key)?.valueChanges.subscribe(() => {
        this.applyEditValidators(!!this.editingId());
        this.clearHiddenConditionalFields();
      });
    }
  }

  private currentProductType(): string {
    return String(this.form?.get('product_type')?.value ?? 'Medicine');
  }

  private isGeneralProduct(): boolean {
    return this.currentProductType() === 'General';
  }

  private isMedicineProduct(): boolean {
    return this.currentProductType() === 'Medicine';
  }

  private hideDosageForType(): boolean {
    const t = this.currentProductType();
    return t === 'General' || t === 'Food/Nutra';
  }

  private showWhenRules(field: FormField): ShowWhenRule[] {
    if (!field.showWhen) return [];
    return Array.isArray(field.showWhen) ? field.showWhen : [field.showWhen];
  }

  private matchesShowWhen(field: FormField): boolean {
    const rules = this.showWhenRules(field);
    if (!rules.length) return true;
    return rules.every((rule) => {
      const current = String(this.form?.get(rule.field)?.value ?? '');
      const expected = rule.equals;
      if (Array.isArray(expected)) {
        return expected.map(String).includes(current);
      }
      return current === String(expected);
    });
  }

  /** Compact colored popup used by Product, Generic, and Manufacturer masters. */
  isSectionedForm(): boolean {
    return this.config?.key === 'products' || this.config?.key === 'generics' || this.config?.key === 'manufacturers';
  }

  formHeader(): { icon: string; subtitle: string } | null {
    switch (this.config?.key) {
      case 'products':
        return { icon: 'bi bi-capsule', subtitle: 'Schedule, pack, tax and MRP' };
      case 'generics':
        return { icon: 'bi bi-droplet-half', subtitle: 'Molecule name and drug class' };
      case 'manufacturers':
        return { icon: 'bi bi-building', subtitle: 'Company name and bill code' };
      default:
        return null;
    }
  }

  /** Popup groups. Empty sections stay hidden (e.g. dosage on a General product). */
  formSections(): { id: string; title: string; icon: string; tone: string; fields: FormField[] }[] {
    const byKey: Record<string, { id: string; title: string; icon: string; tone: string }[]> = {
      products: [
        { id: 'identity', title: 'Product', icon: 'bi bi-capsule', tone: 'teal' },
        { id: 'catalog', title: 'Catalog', icon: 'bi bi-diagram-3', tone: 'indigo' },
        { id: 'pack', title: 'Pack & dosage', icon: 'bi bi-box-seam', tone: 'amber' },
        { id: 'price', title: 'Price & tax', icon: 'bi bi-receipt', tone: 'mint' },
        { id: 'notes', title: 'Composition', icon: 'bi bi-card-text', tone: 'slate' },
      ],
      generics: [
        { id: 'identity', title: 'Molecule', icon: 'bi bi-droplet-half', tone: 'teal' },
        { id: 'class', title: 'Classification', icon: 'bi bi-diagram-3', tone: 'indigo' },
      ],
      manufacturers: [
        { id: 'identity', title: 'Company', icon: 'bi bi-building', tone: 'teal' },
        { id: 'code', title: 'Bill code', icon: 'bi bi-upc', tone: 'amber' },
      ],
    };
    const meta = byKey[this.config?.key] ?? [];
    return meta
      .map((section) => ({
        ...section,
        fields: this.config.fields.filter((field) => field.section === section.id && this.showField(field)),
      }))
      .filter((section) => section.fields.length > 0);
  }

  /** Used by template to keep product/customer forms aligned with Retail behavior. */
  showField(field: FormField): boolean {
    if (!this.matchesShowWhen(field)) return false;
    if (this.config.key !== 'products') return true;
    if (field.key === 'generic_id') return this.isMedicineProduct();
    if (field.key === 'manufacturer' || field.key === 'manufacturer_short') return !this.isGeneralProduct();
    if (field.key === 'dosage_form' || field.key === 'strength') return !this.hideDosageForType();
    return true;
  }

  private clearHiddenProductFields(): void {
    if (this.config.key !== 'products') return;
    const patch: Record<string, unknown> = {};
    if (!this.isMedicineProduct()) {
      patch['generic_id'] = null;
      this.genericSearch.set('');
      this.showGenericDropdown.set(false);
    }
    if (this.isGeneralProduct()) {
      patch['manufacturer'] = null;
      patch['manufacturer_short'] = null;
      this.manufacturerSearch.set('');
      this.showManufacturerDropdown.set(false);
    }
    if (this.hideDosageForType()) {
      patch['dosage_form'] = null;
      patch['strength'] = null;
    }
    if (Object.keys(patch).length) {
      this.form.patchValue(patch, { emitEvent: false });
    }
  }

  private clearHiddenCustomerFields(): void {
    this.clearHiddenConditionalFields();
  }

  private clearHiddenConditionalFields(): void {
    const patch: Record<string, unknown> = {};
    for (const field of this.config.fields) {
      if (this.showWhenRules(field).length && !this.matchesShowWhen(field)) {
        patch[field.key] = null;
      }
    }
    if (Object.keys(patch).length) {
      this.form.patchValue(patch, { emitEvent: false });
    }
  }

  private validatorsFor(field: FormField, isEdit = false): ValidatorFn[] {
    const v: ValidatorFn[] = [];
    // Hidden / conditional fields are not required while hidden.
    if (!this.showField(field)) {
      return v;
    }
    // Manufacturer is optional in Product Master (Retail parity).
    if (this.config.key === 'products' && field.key === 'manufacturer') {
      return v;
    }
    if (field.validation === 'gstin') {
      v.push(gstinValidator(!!field.required));
      return v;
    }
    if (field.required && !(isEdit && field.type === 'password')) {
      v.push(Validators.required);
    }
    if (field.type === 'email') {
      v.push(Validators.email);
    }
    if (field.min != null) v.push(Validators.min(field.min));
    if (field.max != null) v.push(Validators.max(field.max));
    if (field.maxLength != null) v.push(Validators.maxLength(field.maxLength));
    if (field.validation) {
      v.push(optionalPattern(VALIDATION_PATTERNS[field.validation]));
    }
    return v;
  }

  /** Live input transform: uppercase / digits-only / GSTIN sanitize. */
  onFieldInput(field: FormField, event: Event): void {
    const el = event.target as HTMLInputElement | HTMLTextAreaElement;
    let value = el.value ?? '';
    const ctrl = this.form.get(field.key);
    if (!ctrl) return;

    if (field.validation === 'phone' || field.validation === 'phoneDistributor' || field.validation === 'pincode' || field.validation === 'hsn' || field.validation === 'hsnStrict' || field.validation === 'fssai') {
      value = value.replace(/\D/g, '');
    } else if (field.validation === 'gstin' || field.validation === 'pan' || field.validation === 'ifsc') {
      value = value.replace(/[^a-zA-Z0-9]/g, '').toUpperCase();
    } else if (field.uppercase && field.type !== 'email' && field.type !== 'password') {
      value = value.toUpperCase();
    }

    if (field.maxLength != null && value.length > field.maxLength) {
      value = value.slice(0, field.maxLength);
    }

    if (value !== el.value) {
      el.value = value;
    }
    if (ctrl.value !== value) {
      ctrl.setValue(value, { emitEvent: false });
      ctrl.markAsDirty();
      ctrl.updateValueAndValidity({ emitEvent: false });
    }
  }

  fieldError(field: FormField): string | null {
    const ctrl = this.form.get(field.key);
    if (!ctrl || !ctrl.touched || !ctrl.invalid) return null;
    const errors = ctrl.errors ?? {};
    if (errors['required']) return VALIDATION_MESSAGES['required'];
    if (errors['gstinLength']) {
      const actual = (errors['gstinLength'] as { actual: number }).actual;
      return `GSTIN must be exactly 15 characters (currently ${actual}).`;
    }
    if (errors['gstinFormat']) return VALIDATION_MESSAGES['gstinFormat'];
    if (errors['email']) return VALIDATION_MESSAGES['email'];
    if (errors['pattern'] && field.validation) return VALIDATION_MESSAGES[field.validation];
    if (errors['min']) return VALIDATION_MESSAGES['min'];
    if (errors['max']) return VALIDATION_MESSAGES['max'];
    if (errors['maxlength']) return VALIDATION_MESSAGES['maxlength'];
    if (errors['minlength']) return VALIDATION_MESSAGES['minlength'];
    return 'Invalid value.';
  }

  /** Live character count for GSTIN (e.g. 12/15). */
  gstinCount(field: FormField): string | null {
    if (field.validation !== 'gstin') return null;
    const len = String(this.form.get(field.key)?.value ?? '').length;
    return `${len}/15`;
  }

  private loadOptions(): void {
    const needs = new Set(this.config.fields.map((f) => f.optionSource).filter(Boolean) as string[]);
    for (const f of this.config.listFilters ?? []) {
      if (f.source === 'schedule') needs.add('schedule');
    }
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
      this.crud.list<Row>('generics', { perPage: 5000, page: 1 }).subscribe((d) =>
        this.setOptions(
          'generics',
          d.data.map((g) => ({ value: g['id'] as number, label: String(g['name'] ?? '') })),
        ),
      );
    }
    if (needs.has('manufacturers')) {
      this.crud.list<Row>('manufacturers', { perPage: 5000, page: 1 }).subscribe((d) =>
        this.setOptions(
          'manufacturers',
          d.data.map((m) => {
            const name = String(m['name'] ?? '');
            const sCode = String(m['s_code'] ?? '').toUpperCase();
            return {
              value: name,
              label: sCode ? `${name} (${sCode})` : name,
              sCode,
            };
          }),
        ),
      );
    }
    if (needs.has('gstStates')) {
      this.setOptions(
        'gstStates',
        GST_STATE_CODES.map((s) => ({ value: s.value, label: s.label })),
      );
    }
    if (needs.has('packSizes')) {
      this.meta.loadPackSizes().subscribe((list) => {
        this.setOptions(
          'packSizes',
          (list ?? []).map((p) => ({
            value: p.pack,
            label: (p.label || p.pack).trim(),
            units: p.units,
          })),
        );
      });
    }
  }

  private setOptions(source: string, opts: Option[]): void {
    this.optionsMap.update((m) => ({ ...m, [source]: opts }));
    if (source === 'generics' && this.modalOpen()) {
      this.syncGenericSearchFromForm();
    }
    if (source === 'manufacturers' && this.modalOpen()) {
      this.syncManufacturerSearchFromForm();
    }
  }

  optionsFor(field: FormField): Option[] {
    if (field.options) return field.options;
    let opts = field.optionSource ? (this.optionsMap()[field.optionSource] ?? []) : [];
    // Keep legacy product manufacturer text visible if not yet in Manufacturer Master.
    if (field.key === 'manufacturer' && this.form) {
      const cur = String(this.form.get('manufacturer')?.value ?? '').trim();
      if (cur && !opts.some((o) => String(o.value) === cur)) {
        const short = String(this.form.get('manufacturer_short')?.value ?? '').trim();
        opts = [{ value: cur, label: `${cur} (not in master)`, sCode: short }, ...opts];
      }
    }
    return opts;
  }

  private loadCommonMargin(): void {
    this.crud.list<Row>('rate-margins', { search: 'COMMON', perPage: 50, page: 1 }).subscribe({
      next: (r) => {
        const row = (r.data ?? []).find((x) => String(x['category'] ?? '').toUpperCase() === 'COMMON');
        this.commonMargin.set(row
          ? { id: Number(row['id']), ptr: Number(row['ptr_margin']), pts: Number(row['pts_margin']) }
          : { id: null, ptr: null, pts: null });
      },
    });
  }

  patchCommonMargin(field: 'ptr' | 'pts', value: unknown): void {
    const n = value === '' || value === null || value === undefined ? null : Number(value);
    this.commonMargin.update((m) => ({ ...m, [field]: Number.isFinite(n as number) ? n : null }));
    this.commonError.set(null);
  }

  saveCommonMargin(): void {
    const m = this.commonMargin();
    const valid = (v: number | null) => v !== null && v >= 0 && v <= 100;
    if (!valid(m.ptr) || !valid(m.pts)) {
      this.commonError.set('Enter PTR % and P.T.S % between 0 and 100.');
      return;
    }
    const body = { category: 'COMMON', ptr_margin: m.ptr, pts_margin: m.pts, rate_by: 'Rate By Margin', is_active: 1 };
    const req = m.id
      ? this.crud.update<{ id?: number }>('rate-margins', m.id, body)
      : this.crud.create<{ id?: number }>('rate-margins', body);
    this.commonSaving.set(true);
    req.subscribe({
      next: () => {
        this.commonSaving.set(false);
        this.showToast('Common margin saved.');
        this.load();
      },
      error: (err: HttpErrorResponse) => {
        this.commonSaving.set(false);
        const errors = err.error?.errors;
        const first = errors ? (Object.values(errors)[0] as string[])?.[0] : null;
        this.commonError.set(first ?? err.error?.message ?? 'Could not save common margin.');
      },
    });
  }

  load(): void {
    if (this.config.key === 'rate-margins') this.loadCommonMargin();
    this.loading.set(true);
    const rawFilters = this.listFilterValues();
    const filters: Record<string, string> = {};
    for (const [k, v] of Object.entries(rawFilters)) {
      if (v === '__UNCATEGORISED__') {
        // Backend treats empty therapeutic_class as Uncategorised via sentinel
        filters[k] = '__UNCATEGORISED__';
      } else if (v) {
        filters[k] = v;
      }
    }
    this.crud
      .list<Row>(this.config.resourcePath, {
        search: this.search() || undefined,
        page: this.page(),
        perPage: this.perPage(),
        filters,
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

  changePerPage(n: number): void {
    this.perPage.set(n);
    this.page.set(1);
    this.load();
  }

  openCreate(): void {
    this.editingId.set(null);
    this.formError.set(null);
    this.form.reset();
    this.packSizeSearch.set('');
    this.showPackDropdown.set(false);
    this.genericSearch.set('');
    this.showGenericDropdown.set(false);
    this.manufacturerSearch.set('');
    this.showManufacturerDropdown.set(false);
    // Defaults for select fields that should not start empty.
    const defaults: Record<string, unknown> = {};
    for (const field of this.config.fields) {
      if (field.key === 'customer_type') defaults['customer_type'] = 'retail';
      if (field.key === 'is_chronic') defaults['is_chronic'] = 0;
      if (field.key === 'gst_registered') defaults['gst_registered'] = 0;
      if (field.key === 'gender') defaults['gender'] = 'Male';
      if (field.key === 'chronic_val') defaults['chronic_val'] = 'Diabetic';
      if (field.key === 'credit_days') defaults['credit_days'] = 0;
      if (field.key === 'medical_type') defaults['medical_type'] = 'Retail';
      if (field.key === 'rate_by') defaults['rate_by'] = 'Rate By Margin';
      if (field.key === 'max_discount') defaults['max_discount'] = 0;
      if (field.key === 'std_discount') defaults['std_discount'] = 0;
      if (field.key === 'cash_discount') defaults['cash_discount'] = 0;
      if (field.key === 'account_type') defaults['account_type'] = 'CURRENT';
      if (field.key === 'schedule_category') defaults['schedule_category'] = 'OTC';
      if (field.key === 'product_type') defaults['product_type'] = 'Medicine';
      if (field.key === 'allow_loose') defaults['allow_loose'] = 1;
    }
    if (Object.keys(defaults).length) this.form.patchValue(defaults);
    this.applyEditValidators(false);
    this.clearHiddenProductFields();
    this.clearHiddenCustomerFields();
    this.modalOpen.set(true);
  }

  openEdit(row: Row): void {
    this.editingId.set(row['id'] as number);
    this.formError.set(null);
    this.form.reset();
    this.packSizeSearch.set('');
    this.showPackDropdown.set(false);
    this.genericSearch.set('');
    this.showGenericDropdown.set(false);
    this.manufacturerSearch.set('');
    this.showManufacturerDropdown.set(false);
    const patch: Record<string, unknown> = {};
    for (const field of this.config.fields) {
      if (field.type === 'password') continue;
      let val = row[field.key] ?? '';
      if (typeof val === 'string' && field.uppercase) {
        val = val.toUpperCase();
      }
      patch[field.key] = val;
    }
    this.form.patchValue(patch);
    this.syncPackSearchFromForm();
    this.syncGenericSearchFromForm();
    this.syncManufacturerSearchFromForm();
    this.applyEditValidators(true);
    this.clearHiddenProductFields();
    this.clearHiddenCustomerFields();
    this.modalOpen.set(true);
  }

  private applyEditValidators(isEdit: boolean): void {
    for (const field of this.config.fields) {
      const ctrl = this.form.get(field.key);
      if (!ctrl) continue;
      ctrl.setValidators(this.validatorsFor(field, isEdit));
      ctrl.updateValueAndValidity({ emitEvent: false });
    }
  }

  private openEmbeddedCreate(): void {
    this.openCreate();
    const preset = this.embedPreset();
    if (preset) this.form.patchValue(preset);
    for (const key of this.embedLock()) {
      this.form.get(key)?.disable({ emitEvent: false });
    }
    this.clearHiddenCustomerFields();
  }

  closeModal(): void {
    if (this.embedded()) {
      this.modalOpen.set(false);
      this.embedClosed.emit();
      return;
    }
    const url = this.returnUrl();
    if (url && !this.editingId()) {
      // Cancel create-from-purchase: return with draft intact.
      this.router.navigateByUrl(url);
      return;
    }
    this.modalOpen.set(false);
  }

  save(): void {
    // Normalize once more before submit.
    this.clearHiddenProductFields();
    for (const field of this.config.fields) {
      const ctrl = this.form.get(field.key);
      if (!ctrl || typeof ctrl.value !== 'string') continue;
      let value = ctrl.value.trim();
      if (field.uppercase && field.type !== 'email' && field.type !== 'password') {
        value = value.toUpperCase();
      }
      if (field.validation === 'phone' || field.validation === 'phoneDistributor' || field.validation === 'pincode' || field.validation === 'hsn' || field.validation === 'hsnStrict') {
        value = value.replace(/\D/g, '');
      }
      if (field.validation === 'gstin') {
        value = value.replace(/[^A-Z0-9]/gi, '').toUpperCase();
      }
      ctrl.setValue(value, { emitEvent: false });
    }
    this.form.updateValueAndValidity();

    if (this.form.invalid) {
      this.form.markAllAsTouched();
      this.formError.set('Please fix the highlighted fields.');
      return;
    }
    this.saving.set(true);
    this.formError.set(null);

    const raw = this.form.getRawValue() as Record<string, unknown>;
    // Drop values for hidden conditional fields.
    for (const field of this.config.fields) {
      if (!this.showField(field)) {
        raw[field.key] = null;
      }
    }
    // Drop empty password on edit.
    if (this.editingId() && (raw['password'] === '' || raw['password'] == null)) {
      delete raw['password'];
    }
    // Omit empty optional fields — null breaks NOT NULL DB columns (e.g. product_category).
    for (const key of Object.keys(raw)) {
      if (raw[key] === '' || raw[key] === null) {
        delete raw[key];
      }
    }

    const req = this.editingId()
      ? this.crud.update<{ id?: number }>(this.config.resourcePath, this.editingId()!, raw)
      : this.crud.create<{ id?: number }>(this.config.resourcePath, raw);

    req.subscribe({
      next: (res: ApiResponse<{ id?: number } | null>) => {
        this.saving.set(false);
        this.modalOpen.set(false);
        const createdId = !this.editingId() ? Number(res.data?.id ?? 0) : 0;
        const returnUrl = this.returnUrl();

        if (this.embedded()) {
          this.embedSaved.emit({ id: createdId, values: raw });
          return;
        }

        if (returnUrl && createdId > 0) {
          if (this.config.key === 'products') {
            this.purchaseDraft.setHandshake({ createdProductId: createdId });
          } else if (this.config.key === 'distributors') {
            this.purchaseDraft.setHandshake({ createdSupplierId: createdId });
          }
          this.router.navigateByUrl(returnUrl);
          return;
        }

        this.showToast(this.editingId() ? 'Record updated.' : 'Record created.');
        this.load();
        this.loadStats();
        this.loadKpis();
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
        this.loadKpis();
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

  @HostListener('document:mousedown', ['$event'])
  onDocumentMouseDown(ev: MouseEvent): void {
    const t = ev.target as HTMLElement | null;
    if (!t?.closest('.master-pack-select')) {
      this.showPackDropdown.set(false);
    }
    if (!t?.closest('.master-generic-select')) {
      this.showGenericDropdown.set(false);
    }
    if (!t?.closest('.master-manufacturer-select')) {
      this.showManufacturerDropdown.set(false);
    }
  }

  private syncGenericSearchFromForm(): void {
    const id = this.form?.get('generic_id')?.value;
    if (id == null || id === '') {
      this.genericSearch.set('');
      return;
    }
    const match = (this.optionsMap()['generics'] ?? []).find((o) => String(o.value) === String(id));
    this.genericSearch.set(match ? match.label : '');
  }

  openGenericDropdown(): void {
    if (this.genericBlurTimer) {
      clearTimeout(this.genericBlurTimer);
      this.genericBlurTimer = undefined;
    }
    this.filterGenerics();
    this.showGenericDropdown.set(true);
  }

  onGenericSearch(value: string): void {
    this.genericSearch.set(value);
    // Typing clears previous selection until user picks from list.
    const curId = this.form.get('generic_id')?.value;
    if (curId != null && curId !== '') {
      const match = (this.optionsMap()['generics'] ?? []).find((o) => String(o.value) === String(curId));
      if (!match || match.label.toLowerCase() !== value.trim().toLowerCase()) {
        this.form.patchValue({ generic_id: null });
      }
    }
    this.filterGenerics();
    this.showGenericDropdown.set(true);
  }

  filterGenerics(): void {
    const list = this.optionsMap()['generics'] ?? [];
    const q = this.genericSearch().trim().toLowerCase();
    const filtered = !q
      ? list
      : list.filter((g) => String(g.label).toLowerCase().includes(q));
    this.filteredGenerics.set(filtered.slice(0, 80));
  }

  selectGeneric(opt: Option): void {
    this.form.patchValue({ generic_id: opt.value });
    this.genericSearch.set(opt.label);
    this.showGenericDropdown.set(false);
  }

  clearGeneric(): void {
    this.form.patchValue({ generic_id: null });
    this.genericSearch.set('');
    this.showGenericDropdown.set(false);
  }

  onGenericBlur(): void {
    this.genericBlurTimer = setTimeout(() => {
      this.showGenericDropdown.set(false);
      this.syncGenericSearchFromForm();
      this.genericBlurTimer = undefined;
    }, 180);
  }

  openGenericCreatePopup(): void {
    const typed = this.genericSearch().trim().toUpperCase();
    this.genericCreateError.set(null);
    this.genericCreateForm.reset({
      name: typed,
      therapeutic_class: '',
      schedule_category: 'OTC',
    });
    this.showGenericDropdown.set(false);
    this.genericCreateOpen.set(true);
  }

  closeGenericCreatePopup(): void {
    this.genericCreateOpen.set(false);
    this.genericCreateError.set(null);
    this.genericSaving.set(false);
  }

  saveNewGeneric(): void {
    if (this.genericCreateForm.invalid) {
      this.genericCreateForm.markAllAsTouched();
      return;
    }
    const raw = this.genericCreateForm.getRawValue() as Record<string, unknown>;
    const payload = {
      name: String(raw['name'] ?? '').trim().toUpperCase(),
      therapeutic_class: String(raw['therapeutic_class'] ?? '').trim().toUpperCase() || null,
      schedule_category: String(raw['schedule_category'] ?? 'OTC'),
      is_active: 1,
    };
    this.genericSaving.set(true);
    this.genericCreateError.set(null);
    this.crud.create<Row>('generics', payload).subscribe({
      next: (res) => {
        const created = (res.data ?? {}) as Row;
        const id = Number(created['id'] ?? 0);
        const name = String(created['name'] ?? payload.name);
        if (id > 0) {
          const opts = [...(this.optionsMap()['generics'] ?? [])];
          if (!opts.some((o) => Number(o.value) === id)) {
            opts.unshift({ value: id, label: name });
            opts.sort((a, b) => String(a.label).localeCompare(String(b.label)));
            this.setOptions('generics', opts);
          }
          this.form.patchValue({ generic_id: id });
          this.genericSearch.set(name);
        }
        this.genericSaving.set(false);
        this.genericCreateOpen.set(false);
        this.toast.set(`Generic "${name}" added`);
        setTimeout(() => this.toast.set(null), 2500);
      },
      error: (err: HttpErrorResponse) => {
        this.genericSaving.set(false);
        const errors = err.error?.errors as Record<string, string[]> | undefined;
        const first = errors ? (Object.values(errors)[0] as string[])?.[0] : null;
        this.genericCreateError.set(first || err.error?.message || 'Could not save generic.');
      },
    });
  }

  scheduleOptionsForGenericCreate(): Option[] {
    return this.optionsMap()['schedule'] ?? [
      { value: 'OTC', label: 'OTC' },
      { value: 'H', label: 'H' },
      { value: 'H1', label: 'H1' },
      { value: 'X', label: 'X' },
      { value: 'G', label: 'G' },
      { value: 'RESTRICTED', label: 'RESTRICTED' },
    ];
  }

  private syncManufacturerSearchFromForm(): void {
    const name = String(this.form?.get('manufacturer')?.value ?? '').trim();
    if (!name) {
      this.manufacturerSearch.set('');
      return;
    }
    const match = (this.optionsMap()['manufacturers'] ?? []).find((o) => String(o.value) === name);
    this.manufacturerSearch.set(match ? match.label : name);
  }

  openManufacturerDropdown(): void {
    if (this.manufacturerBlurTimer) {
      clearTimeout(this.manufacturerBlurTimer);
      this.manufacturerBlurTimer = undefined;
    }
    this.filterManufacturers();
    this.showManufacturerDropdown.set(true);
  }

  onManufacturerSearch(value: string): void {
    this.manufacturerSearch.set(value);
    const cur = String(this.form.get('manufacturer')?.value ?? '').trim();
    if (cur) {
      const match = (this.optionsMap()['manufacturers'] ?? []).find((o) => String(o.value) === cur);
      const label = match ? match.label : cur;
      if (label.toLowerCase() !== value.trim().toLowerCase() && cur.toLowerCase() !== value.trim().toUpperCase()) {
        this.form.patchValue({ manufacturer: null, manufacturer_short: null }, { emitEvent: false });
      }
    }
    this.filterManufacturers();
    this.showManufacturerDropdown.set(true);
  }

  filterManufacturers(): void {
    const list = this.optionsMap()['manufacturers'] ?? [];
    const q = this.manufacturerSearch().trim().toLowerCase();
    const filtered = !q
      ? list
      : list.filter(
          (m) =>
            String(m.label).toLowerCase().includes(q) ||
            String(m.value).toLowerCase().includes(q) ||
            String(m.sCode ?? '').toLowerCase().includes(q),
        );
    this.filteredManufacturers.set(filtered.slice(0, 80));
  }

  selectManufacturer(opt: Option): void {
    this.form.patchValue({
      manufacturer: opt.value,
      manufacturer_short: opt.sCode || this.form.get('manufacturer_short')?.value || '',
    });
    this.manufacturerSearch.set(opt.label);
    this.showManufacturerDropdown.set(false);
  }

  clearManufacturer(): void {
    this.form.patchValue({ manufacturer: null, manufacturer_short: null });
    this.manufacturerSearch.set('');
    this.showManufacturerDropdown.set(false);
  }

  onManufacturerBlur(): void {
    this.manufacturerBlurTimer = setTimeout(() => {
      this.showManufacturerDropdown.set(false);
      this.syncManufacturerSearchFromForm();
      this.manufacturerBlurTimer = undefined;
    }, 180);
  }

  /** Suggest a short S Code from manufacturer name (first 3 letters). */
  private suggestSCode(name: string): string {
    const letters = name.replace(/[^A-Za-z]/g, '').toUpperCase();
    if (letters.length >= 3) return letters.slice(0, 3);
    return letters || 'MFG';
  }

  openManufacturerCreatePopup(): void {
    const typed = this.manufacturerSearch().trim().toUpperCase()
      // Strip trailing "(CODE)" if user typed/filtered a label
      .replace(/\s*\([^)]*\)\s*$/, '')
      .trim();
    this.manufacturerCreateError.set(null);
    this.manufacturerCreateForm.reset({
      name: typed,
      s_code: typed ? this.suggestSCode(typed) : '',
    });
    this.showManufacturerDropdown.set(false);
    this.manufacturerCreateOpen.set(true);
  }

  closeManufacturerCreatePopup(): void {
    this.manufacturerCreateOpen.set(false);
    this.manufacturerCreateError.set(null);
    this.manufacturerSaving.set(false);
  }

  saveNewManufacturer(): void {
    if (this.manufacturerCreateForm.invalid) {
      this.manufacturerCreateForm.markAllAsTouched();
      return;
    }
    const raw = this.manufacturerCreateForm.getRawValue() as Record<string, unknown>;
    const payload = {
      name: String(raw['name'] ?? '').trim().toUpperCase(),
      s_code: String(raw['s_code'] ?? '').trim().toUpperCase(),
      is_active: 1,
    };
    this.manufacturerSaving.set(true);
    this.manufacturerCreateError.set(null);
    this.crud.create<Row>('manufacturers', payload).subscribe({
      next: (res) => {
        const created = (res.data ?? {}) as Row;
        const name = String(created['name'] ?? payload.name);
        const sCode = String(created['s_code'] ?? payload.s_code).toUpperCase();
        const opts = [...(this.optionsMap()['manufacturers'] ?? [])];
        if (!opts.some((o) => String(o.value).toUpperCase() === name)) {
          opts.unshift({
            value: name,
            label: sCode ? `${name} (${sCode})` : name,
            sCode,
          });
          opts.sort((a, b) => String(a.label).localeCompare(String(b.label)));
          this.setOptions('manufacturers', opts);
        }
        this.form.patchValue({ manufacturer: name, manufacturer_short: sCode });
        this.manufacturerSearch.set(sCode ? `${name} (${sCode})` : name);
        this.manufacturerSaving.set(false);
        this.manufacturerCreateOpen.set(false);
        this.toast.set(`Manufacturer "${name}" added`);
        setTimeout(() => this.toast.set(null), 2500);
      },
      error: (err: HttpErrorResponse) => {
        this.manufacturerSaving.set(false);
        const errors = err.error?.errors as Record<string, string[]> | undefined;
        const first = errors ? (Object.values(errors)[0] as string[])?.[0] : null;
        this.manufacturerCreateError.set(first || err.error?.message || 'Could not save manufacturer.');
      },
    });
  }

  private syncPackSearchFromForm(): void {
    const pack = String(this.form?.get('pack_size')?.value ?? '').trim();
    if (!pack) {
      this.packSizeSearch.set('');
      return;
    }
    const match = (this.optionsMap()['packSizes'] ?? []).find((o) => String(o.value).toLowerCase() === pack.toLowerCase());
    this.packSizeSearch.set(match ? match.label : pack);
  }

  openPackFieldDropdown(): void {
    if (this.packBlurTimer) {
      clearTimeout(this.packBlurTimer);
      this.packBlurTimer = undefined;
    }
    this.filterPackFieldSizes();
    this.showPackDropdown.set(true);
  }

  onPackFieldSearch(value: string): void {
    this.packSizeSearch.set(value);
    this.filterPackFieldSizes();
    this.showPackDropdown.set(true);
  }

  filterPackFieldSizes(): void {
    const list = this.optionsMap()['packSizes'] ?? [];
    const q = this.packSizeSearch().trim().toLowerCase();
    const filtered = !q
      ? list
      : list.filter((p) => String(p.label).toLowerCase().includes(q) || String(p.value).toLowerCase().includes(q));
    this.filteredPackSizes.set(
      filtered.slice(0, 40).map((p) => ({
        pack: String(p.value),
        label: p.label,
        units: Number((p as Option & { units?: number }).units) || 1,
        form: '',
      })),
    );
  }

  selectPackField(p: { pack: string; units?: number }): void {
    const pack = String(p.pack ?? '').trim().toUpperCase();
    this.form.patchValue({ pack_size: pack });
    if (p.units && p.units > 0) {
      this.form.patchValue({ units_per_pack: p.units });
    }
    this.packSizeSearch.set(pack);
    this.showPackDropdown.set(false);
  }

  onPackFieldBlur(): void {
    this.packBlurTimer = setTimeout(() => {
      this.showPackDropdown.set(false);
      const typed = this.packSizeSearch().trim();
      if (typed) {
        const list = this.optionsMap()['packSizes'] ?? [];
        const lower = typed.toLowerCase();
        const found = list.find((p) => String(p.value).toLowerCase() === lower || String(p.label).toLowerCase() === lower);
        const pack = found ? String(found.value).trim().toUpperCase() : typed.toUpperCase();
        this.form.patchValue({ pack_size: pack });
        this.packSizeSearch.set(found ? found.label : pack);
      } else {
        this.syncPackSearchFromForm();
      }
      this.packBlurTimer = undefined;
    }, 180);
  }
}
