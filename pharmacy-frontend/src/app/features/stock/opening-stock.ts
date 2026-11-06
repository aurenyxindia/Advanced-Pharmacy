import { Component, computed, ElementRef, HostListener, inject, signal, viewChild } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpErrorResponse } from '@angular/common/http';
import { Router } from '@angular/router';
import { OpeningEntry, StockService } from '../../core/services/stock.service';
import { ProductSearchResult, PurchaseService } from '../../core/services/purchase.service';
import { CrudService } from '../../core/services/crud.service';
import { PurchaseDraftService } from '../../core/services/purchase-draft.service';
import { OpeningStockDraftService } from '../../core/services/opening-stock-draft.service';
import { MetaService } from '../../core/services/meta.service';
import { StockAdjustment } from './stock-adjustment';
import { MinInventory } from './min-inventory';
import {
  OpeningLine,
  applyMatchToLine,
  applyProductToLine,
  buildOpeningLine,
  buildProductMatchIndex,
  downloadOpeningTemplate,
  parseOpeningExcel,
} from './opening-stock-excel.util';

type PackOpt = { pack: string; units: number; form: string; label?: string };

interface EntryDraft {
  master_product_id: number;
  p_no: string;
  product_name: string;
  productQuery: string;
  batch_no: string;
  expiry_date: string;
  qty: number;
  mrp: number;
  purchase_rate: number;
  gst_rate: number;
  hsn_code: string;
  shelf_location: string;
  pack_size: string;
  units_per_pack: number;
  m_name: string;
  p_cat: string;
}

function emptyDraft(): EntryDraft {
  return {
    master_product_id: 0,
    p_no: '',
    product_name: '',
    productQuery: '',
    batch_no: '',
    expiry_date: '',
    qty: 0,
    mrp: 0,
    purchase_rate: 0,
    gst_rate: 5,
    hsn_code: '',
    shelf_location: '',
    pack_size: '',
    units_per_pack: 1,
    m_name: '',
    p_cat: '',
  };
}

function productFromRecord(p: Record<string, unknown>): ProductSearchResult {
  return {
    id: Number(p['id']),
    name: String(p['name'] ?? ''),
    manufacturer: (p['manufacturer'] as string | null) ?? null,
    manufacturer_short: (p['manufacturer_short'] as string | null) ?? null,
    schedule_category: String(p['schedule_category'] ?? 'OTC'),
    product_category: String(p['product_category'] ?? 'Allopathy'),
    default_gst_rate: Number(p['default_gst_rate']) || 12,
    generic_name: null,
    hsn_code: (p['hsn_code'] as string | null) ?? null,
    pack_size: (p['pack_size'] as string | null) ?? null,
    units_per_pack: Number(p['units_per_pack']) || 1,
    shelf_location: (p['shelf_location'] as string | null) ?? null,
    mrp: Number(p['mrp']) || 0,
    ptr: Number(p['ptr']) || 0,
    pts: Number(p['pts']) || 0,
  };
}

@Component({
  selector: 'app-opening-stock',
  imports: [CommonModule, FormsModule, StockAdjustment, MinInventory],
  templateUrl: './opening-stock.html',
  styleUrl: './opening-stock.scss',
})
export class OpeningStock {
  private readonly stockSvc = inject(StockService);
  private readonly purchaseSvc = inject(PurchaseService);
  private readonly crud = inject(CrudService);
  private readonly meta = inject(MetaService);
  private readonly router = inject(Router);
  private readonly purchaseDraft = inject(PurchaseDraftService);
  private readonly osDraft = inject(OpeningStockDraftService);
  private restored = false;

  readonly activeTab = signal<'opening' | 'adjustment' | 'mitrigger'>('opening');

  readonly excelInput = viewChild<ElementRef<HTMLInputElement>>('excelFileInput');

  readonly draft = signal<EntryDraft>(emptyDraft());
  readonly productResults = signal<ProductSearchResult[]>([]);
  readonly productHighlight = signal(0);
  readonly showProductDropdown = signal(false);

  readonly lines = signal<OpeningLine[]>([]);
  readonly totalAmount = computed(() =>
    Math.round(this.lines().reduce((s, l) => s + (Number(l.purchase_rate) * Number(l.qty) || 0), 0) * 100) / 100,
  );
  readonly unmatchedCount = computed(() => this.lines().filter((l) => !l.product_matched).length);

  readonly masterProducts = signal<ProductSearchResult[]>([]);
  private matchIndex = buildProductMatchIndex([]);

  readonly hsns = signal<{ id: number; code: string; description?: string }[]>([]);
  readonly shelves = signal<string[]>([]);

  readonly rows = signal<OpeningEntry[]>([]);
  readonly listLoading = signal(true);
  readonly saving = signal(false);
  readonly excelBusy = signal(false);
  readonly error = signal<string | null>(null);
  readonly toast = signal<string | null>(null);
  readonly showUnmatchedOnly = signal(false);

  readonly addProductConfirmOpen = signal(false);
  readonly addProductConfirmTerm = signal('');
  private productSearchTimer: ReturnType<typeof setTimeout> | null = null;
  private lastAddPromptTerm = '';

  readonly packSizeOptions = signal<PackOpt[]>([]);
  readonly showPackDropdown = signal(false);
  readonly packSizeSearch = signal('');
  readonly filteredPackSizes = signal<PackOpt[]>([]);
  private packBlurTimer?: ReturnType<typeof setTimeout>;

  readonly editOpen = signal(false);
  readonly editSaving = signal(false);
  readonly editError = signal<string | null>(null);
  readonly editShowPackDropdown = signal(false);
  readonly editPackSearch = signal('');
  readonly editFilteredPackSizes = signal<PackOpt[]>([]);
  private editPackBlurTimer?: ReturnType<typeof setTimeout>;
  readonly editForm = signal({
    batch_id: 0,
    product_binding_id: 0,
    product_name: '',
    batch_no: '',
    expiry_date: '',
    quantity: 0,
    mrp: 0,
    purchase_rate: 0,
    pack_size: '',
    units_per_pack: 1,
  });

  readonly minExpiry = (() => {
    const d = new Date();
    d.setFullYear(d.getFullYear() - 1);
    return d.toISOString().slice(0, 7);
  })();
  readonly maxExpiry = (() => {
    const d = new Date();
    d.setFullYear(d.getFullYear() + 10);
    return d.toISOString().slice(0, 7);
  })();

  readonly gstOptions = [0, 5, 12, 18, 28, 40];

  readonly displayLines = computed(() => {
    const all = this.lines();
    return this.showUnmatchedOnly() ? all.filter((l) => !l.product_matched) : all;
  });

  readonly kpis = computed(() => {
    const list = this.rows();
    let qty = 0;
    let costValue = 0;
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    let expiring = 0;
    for (const r of list) {
      const q = Number(r.quantity) || 0;
      qty += q;
      costValue += q * (Number(r.net_rate) || 0);
      if (r.expiry_date) {
        const exp = new Date(String(r.expiry_date).slice(0, 10));
        if (!isNaN(exp.getTime())) {
          const days = (exp.getTime() - today.getTime()) / 86400000;
          if (days >= 0 && days <= 90) expiring++;
        }
      }
    }
    return { entries: list.length, qty, costValue: Math.round(costValue * 100) / 100, expiring };
  });

  readonly lineTaxable = computed(() => {
    const d = this.draft();
    return Math.round(d.purchase_rate * d.qty * 100) / 100;
  });

  constructor() {
    this.load();
    this.meta.loadPackSizes().subscribe({
      next: (list) => this.packSizeOptions.set(list ?? []),
      error: () => this.packSizeOptions.set([]),
    });
    this.loadMasters();
    this.loadMatchProducts();
    setTimeout(() => this.restoreDraftIfAny(), 0);
  }

  private persistOpeningDraft(lineKey: string | null = null): void {
    this.osDraft.saveDraft({
      draft: { ...this.draft() },
      lines: this.lines(),
      addProductForLineKey: lineKey,
      activeTab: this.activeTab(),
    });
  }

  private restoreDraftIfAny(): void {
    if (this.restored) return;
    this.restored = true;

    const resume = this.osDraft.consumeExpectResume();
    const handshake = this.purchaseDraft.consumeHandshake();
    if (!resume && !handshake) {
      this.osDraft.clearAll();
      return;
    }

    const saved = this.osDraft.loadDraft();
    if (saved) {
      this.activeTab.set(saved.activeTab);
      this.draft.set({ ...emptyDraft(), ...(saved.draft as unknown as EntryDraft) });
      this.lines.set(saved.lines);
    }

    if (handshake?.createdProductId) {
      this.applyCreatedProduct(handshake.createdProductId, saved?.addProductForLineKey ?? null);
      return;
    }

    if (saved) {
      this.showToast('Opening stock draft restored.');
    }
  }

  private applyCreatedProduct(id: number, lineKey: string | null): void {
    const bind = (p: ProductSearchResult) => {
      if (lineKey) {
        this.lines.update((list) =>
          list.map((l) => {
            if (l._key !== lineKey) return l;
            const copy = { ...l };
            applyProductToLine(copy, p);
            return copy;
          }),
        );
        this.masterProducts.update((m) => [...m, p]);
        this.matchIndex = buildProductMatchIndex(this.masterProducts());
        this.showToast('Product added and matched to the line.');
      } else {
        this.selectProduct(p);
        this.masterProducts.update((m) => [...m, p]);
        this.matchIndex = buildProductMatchIndex(this.masterProducts());
        this.showToast('Product added and selected. Continue opening stock.');
      }
    };

    this.crud.get<Record<string, unknown>>('products', id).subscribe({
      next: (p) => bind(productFromRecord(p)),
      error: () => {
        this.purchaseSvc.searchProducts('', 50).subscribe((list) => {
          const hit = list.find((x) => x.id === id);
          if (hit) bind(hit);
          else this.showToast('Product created. Search and select it to continue.');
        });
      },
    });
  }

  /** Same flow as New Purchase — full Product Master create form. */
  goAddProductMaster(ev?: Event, opts?: { name?: string; lineKey?: string | null }): void {
    ev?.preventDefault();
    ev?.stopPropagation();
    const name = (opts?.name ?? this.draft().productQuery ?? this.draft().product_name).trim();
    this.showProductDropdown.set(false);
    this.addProductConfirmOpen.set(false);
    this.persistOpeningDraft(opts?.lineKey ?? null);
    this.osDraft.markExpectResume();
    this.router.navigate(['/app/masters/products'], {
      queryParams: {
        returnUrl: '/app/opening-stock',
        create: '1',
        ...(name ? { name } : {}),
      },
    });
  }

  load(): void {
    this.listLoading.set(true);
    this.stockSvc.openingList().subscribe({
      next: (d) => { this.rows.set(d); this.listLoading.set(false); },
      error: () => this.listLoading.set(false),
    });
  }

  private loadMasters(): void {
    this.crud.list<{ id: number; code: string; description?: string }>('hsn-codes', { perPage: 500 })
      .subscribe((r) => this.hsns.set(r.data.map((h) => ({ id: h.id, code: h.code, description: h.description }))));
    this.purchaseSvc.searchProducts('', 500).subscribe((list) => {
      const shelves = [...new Set(list.map((p) => (p.shelf_location ?? '').trim()).filter(Boolean))].sort();
      this.shelves.set(shelves);
    });
  }

  private loadMatchProducts(): void {
    this.stockSvc.openingMatchProducts().subscribe({
      next: (rows) => {
        const mapped: ProductSearchResult[] = rows.map((r) => ({
          id: Number(r['id']),
          name: String(r['name'] ?? ''),
          manufacturer: null,
          schedule_category: String(r['schedule_category'] ?? 'OTC'),
          default_gst_rate: Number(r['gst_rate'] ?? 12),
          generic_name: null,
          hsn_code: r['hsn_code'] != null ? String(r['hsn_code']) : null,
          pack_size: r['pack_size'] != null ? String(r['pack_size']) : null,
          units_per_pack: Number(r['units_per_pack'] ?? 1),
          mrp: r['mrp'] != null ? Number(r['mrp']) : undefined,
          manufacturer_short: r['manufacturer_short'] != null ? String(r['manufacturer_short']) : null,
          product_category: r['product_category'] != null ? String(r['product_category']) : undefined,
        }));
        this.masterProducts.set(mapped);
        this.matchIndex = buildProductMatchIndex(mapped);
      },
    });
  }

  patchDraft(field: keyof EntryDraft, value: unknown): void {
    this.draft.update((d) => ({ ...d, [field]: value }));
  }

  onProductSearch(term: string): void {
    this.patchDraft('productQuery', term);
    this.patchDraft('product_name', term);
    this.patchDraft('master_product_id', 0);
    this.patchDraft('p_no', '');
    this.productHighlight.set(0);
    if (this.productSearchTimer) {
      clearTimeout(this.productSearchTimer);
    }
    const trimmed = term.trim();
    if (trimmed.length < 2) {
      this.productResults.set([]);
      this.showProductDropdown.set(false);
      this.lastAddPromptTerm = '';
      return;
    }
    this.productSearchTimer = setTimeout(() => {
      this.purchaseSvc.searchProducts(trimmed, 40).subscribe({
        next: (list) => {
          this.productResults.set(list);
          if (list.length > 0) {
            this.showProductDropdown.set(true);
            this.lastAddPromptTerm = '';
            return;
          }
          this.showProductDropdown.set(false);
          if (this.lastAddPromptTerm !== trimmed) {
            this.lastAddPromptTerm = trimmed;
            this.promptAddProductConfirm(trimmed);
          }
        },
      });
    }, 300);
  }

  selectProduct(p: ProductSearchResult): void {
    const pack = (p.pack_size ?? '').trim();
    const upp = Math.max(1, Number(p.units_per_pack) || this.unitsFromPackLabel(pack) || 1);
    this.draft.update((d) => ({
      ...d,
      master_product_id: p.id,
      p_no: String(p.id),
      product_name: p.name,
      productQuery: p.name,
      pack_size: pack,
      units_per_pack: upp,
      m_name: p.manufacturer_short ?? p.manufacturer ?? '',
      p_cat: p.schedule_category,
      hsn_code: p.hsn_code ?? d.hsn_code,
      gst_rate: Number(p.default_gst_rate) || 5,
      shelf_location: p.shelf_location ?? d.shelf_location,
      mrp: p.mrp && Number(p.mrp) > 0 ? Number(p.mrp) : d.mrp,
    }));
    this.syncPackSearchFromDraft(pack);
    this.productResults.set([]);
    this.showProductDropdown.set(false);
  }

  // ---- Pack size (same dropdown as Purchase) ----
  private packKey(value: string): string {
    return value.trim().toUpperCase().replace(/\s+/g, ' ');
  }

  private normalizePack(value: string): string {
    return value.trim().toUpperCase().replace(/\s+/g, ' ');
  }

  packLabel(p: PackOpt): string {
    return (p.label || p.pack || '').trim() || p.pack;
  }

  private unitsFromPackLabel(pack: string): number {
    if (!pack) return 1;
    const s = pack.trim();
    if (/(tab|cap)/i.test(s) || /1\s*[x*]/i.test(s)) {
      const match = s.match(/(?:1\s*[x*]\s*)?(\d+)|(\d+)\s*Tab|\s*(\d+)\s*Cap/i) || s.match(/(\d+)/);
      if (match) {
        const n = parseInt(match[1] || match[2] || match[3] || match[0], 10);
        return n > 0 ? n : 1;
      }
    }
    return 1;
  }

  private findPackOption(value: string): PackOpt | undefined {
    const key = this.packKey(value);
    if (!key) return undefined;
    const list = this.packSizeOptions();
    return (
      list.find((p) => this.packKey(p.pack) === key) ??
      list.find((p) => this.packKey(this.packLabel(p)) === key)
    );
  }

  private filterPackSizeList(query: string): PackOpt[] {
    const list = this.packSizeOptions();
    const q = query.trim().toLowerCase();
    const filtered = !q
      ? list
      : list.filter((p) => {
          const label = this.packLabel(p).toLowerCase();
          const key = this.packKey(q);
          return (
            label.includes(q) ||
            p.pack.toLowerCase().includes(q) ||
            this.packKey(label).includes(key) ||
            this.packKey(p.pack).includes(key)
          );
        });
    return filtered.slice(0, 40);
  }

  private syncPackSearchFromDraft(pack: string): void {
    const match = this.findPackOption(pack);
    this.packSizeSearch.set(match ? this.packLabel(match) : pack);
  }

  openPackDropdown(): void {
    if (this.packBlurTimer) {
      clearTimeout(this.packBlurTimer);
      this.packBlurTimer = undefined;
    }
    this.showProductDropdown.set(false);
    this.filteredPackSizes.set(this.filterPackSizeList(this.packSizeSearch()));
    this.showPackDropdown.set(true);
  }

  onPackSearchInput(value: string): void {
    this.packSizeSearch.set(value);
    this.filteredPackSizes.set(this.filterPackSizeList(value));
    this.showPackDropdown.set(true);
  }

  selectPackSize(p: PackOpt): void {
    if (this.packBlurTimer) {
      clearTimeout(this.packBlurTimer);
      this.packBlurTimer = undefined;
    }
    const pack = this.normalizePack(p.pack);
    const upp = Math.max(1, Number(p.units) || 1);
    this.packSizeSearch.set(this.packLabel(p));
    this.draft.update((d) => ({ ...d, pack_size: pack, units_per_pack: upp }));
    this.showPackDropdown.set(false);
    this.persistOpeningDraft();
  }

  onPackSearchBlur(): void {
    this.packBlurTimer = setTimeout(() => {
      this.showPackDropdown.set(false);
      const typed = this.packSizeSearch().trim();
      if (typed) {
        this.applyPackFromSearch(typed);
      } else {
        this.syncPackSearchFromDraft(this.draft().pack_size);
      }
      this.packBlurTimer = undefined;
    }, 180);
  }

  private applyPackFromSearch(typed: string): void {
    const found = this.findPackOption(typed);
    if (found) {
      this.selectPackSize(found);
      return;
    }
    const pack = this.normalizePack(typed);
    const upp = this.unitsFromPackLabel(pack) || 1;
    this.draft.update((d) => ({ ...d, pack_size: pack, units_per_pack: upp }));
    this.packSizeSearch.set(pack);
    this.meta.parsePack(pack).subscribe({
      next: (parsed) => {
        if (!parsed?.pack) return;
        const master = this.findPackOption(parsed.pack);
        const nextPack = this.normalizePack(master ? master.pack : parsed.pack);
        const nextUpp = master?.units || parsed.units || upp;
        this.draft.update((d) => ({ ...d, pack_size: nextPack, units_per_pack: nextUpp }));
        this.syncPackSearchFromDraft(nextPack);
        this.persistOpeningDraft();
      },
      error: () => this.persistOpeningDraft(),
    });
  }

  handleProductKeyDown(ev: KeyboardEvent): void {
    const list = this.productResults();
    if (ev.key === 'Enter') {
      ev.preventDefault();
      if (list.length) {
        const p = list[this.productHighlight()];
        if (p) this.selectProduct(p);
        return;
      }
      const term = this.draft().productQuery.trim();
      if (term.length >= 2) {
        this.promptAddProductConfirm(term);
      }
      return;
    }
    if (!list.length) {
      if (ev.key === 'Escape') {
        this.showProductDropdown.set(false);
      }
      return;
    }
    if (ev.key === 'ArrowDown') {
      ev.preventDefault();
      this.productHighlight.update((i) => Math.min(i + 1, list.length - 1));
    } else if (ev.key === 'ArrowUp') {
      ev.preventDefault();
      this.productHighlight.update((i) => Math.max(i - 1, 0));
    } else if (ev.key === 'Escape') {
      this.showProductDropdown.set(false);
    }
  }

  @HostListener('document:mousedown', ['$event'])
  onDocumentMouseDown(ev: MouseEvent): void {
    const t = ev.target as HTMLElement | null;
    if (!t?.closest('.os-product-wrap')) this.showProductDropdown.set(false);
    if (!t?.closest('.pack-select-wrap')) {
      this.showPackDropdown.set(false);
      this.editShowPackDropdown.set(false);
    }
  }

  /** Retail parity: ask first, then Product Master (same as New Purchase). */
  promptAddProductConfirm(term: string): void {
    const name = term.trim();
    if (name.length < 2) return;
    this.addProductConfirmTerm.set(name);
    this.addProductConfirmOpen.set(true);
  }

  confirmAddProductYes(): void {
    const name = this.addProductConfirmTerm().trim();
    if (name) {
      this.goAddProductMaster(undefined, { name });
    } else {
      this.addProductConfirmOpen.set(false);
    }
  }

  confirmAddProductNo(): void {
    this.addProductConfirmOpen.set(false);
    this.addProductConfirmTerm.set('');
    this.lastAddPromptTerm = '';
    this.patchDraft('productQuery', '');
    this.patchDraft('product_name', '');
  }

  openAddProductForLine(line: OpeningLine): void {
    this.goAddProductMaster(undefined, { name: line.product_name, lineKey: line._key });
  }

  addLine(): void {
    const d = this.draft();
    if (!d.product_name.trim() || !d.batch_no.trim() || !d.expiry_date || !d.qty || !d.mrp || !d.purchase_rate) {
      this.error.set('Product, Batch, Expiry, Qty, MRP and Rate are required.');
      return;
    }
    if (!d.master_product_id) {
      this.error.set('Select a product from search or add it in Product Master.');
      return;
    }
    this.error.set(null);
    if (!d.pack_size.trim()) {
      this.error.set('Pack size is required.');
      return;
    }
    const line = buildOpeningLine({
      master_product_id: d.master_product_id,
      product_name: d.product_name,
      p_no: d.p_no,
      hsn_code: d.hsn_code,
      batch_no: d.batch_no,
      expiry_date: d.expiry_date,
      shelf_location: d.shelf_location,
      qty: d.qty,
      mrp: d.mrp,
      purchase_rate: d.purchase_rate,
      gst_rate: d.gst_rate,
      pack_size: d.pack_size,
      units_per_pack: Math.max(1, d.units_per_pack || 1),
      m_name: d.m_name,
      p_cat: d.p_cat,
      product_matched: true,
    });
    this.lines.update((list) => [...list, line]);
    this.draft.set(emptyDraft());
    this.packSizeSearch.set('');
  }

  removeLine(line: OpeningLine): void {
    this.lines.update((list) => list.filter((l) => l._key !== line._key));
  }

  onLineProductPick(line: OpeningLine, productId: number): void {
    const p = this.masterProducts().find((x) => x.id === productId);
    if (!p) return;
    this.lines.update((list) => list.map((l) => {
      if (l._key !== line._key) return l;
      const copy = { ...l };
      applyProductToLine(copy, p);
      return copy;
    }));
  }

  async downloadTemplate(): Promise<void> {
    await downloadOpeningTemplate();
  }

  triggerExcelUpload(): void {
    this.excelInput()?.nativeElement.click();
  }

  async onExcelSelected(ev: Event): Promise<void> {
    const input = ev.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file) return;
    this.excelBusy.set(true);
    this.error.set(null);
    try {
      const imported = await parseOpeningExcel(file);
      if (this.lines().length && !confirm(`Import ${imported.length} row(s)? They will be added to the current list.`)) {
        return;
      }
      for (const row of imported) {
        applyMatchToLine(row, row.product_name, this.masterProducts(), this.matchIndex);
      }
      this.lines.update((list) => [...list, ...imported]);
      const unmatched = imported.filter((r) => !r.product_matched).length;
      this.showToast(
        `Imported ${imported.length} row(s)` + (unmatched ? ` — ${unmatched} need product match.` : ' — all matched.'),
      );
    } catch (e) {
      this.error.set(e instanceof Error ? e.message : 'Failed to read Excel.');
    } finally {
      this.excelBusy.set(false);
    }
  }

  saveBulk(): void {
    const list = this.lines();
    if (!list.length) {
      this.error.set('At least 1 product is required.');
      return;
    }
    const unmatched = list.filter((l) => !l.product_matched);
    if (unmatched.length) {
      this.error.set(`${unmatched.length} product(s) are not matched. Match or add them before saving.`);
      return;
    }
    this.saving.set(true);
    this.error.set(null);
    this.stockSvc.openingBulkSave({
      opening_date: new Date().toISOString().slice(0, 10),
      items: list.map((l) => ({
        master_product_id: l.master_product_id,
        batch_no: l.batch_no,
        expiry_date: l.expiry_date,
        shelf_location: l.shelf_location,
        qty: l.qty,
        mrp: l.mrp,
        purchase_rate: l.purchase_rate,
        gst_rate: l.gst_rate,
        hsn_code: l.hsn_code,
        pack_size: l.pack_size,
        units_per_pack: Math.max(1, Number(l.units_per_pack) || this.unitsFromPackLabel(l.pack_size) || 1),
      })),
    }).subscribe({
      next: (res) => {
        this.saving.set(false);
        this.lines.set([]);
        this.showToast(`Opening stock saved (${res.data.ref_no}).`);
        this.load();
      },
      error: (err: HttpErrorResponse) => {
        this.saving.set(false);
        this.error.set(err.error?.message ?? 'Could not save opening stock.');
      },
    });
  }

  // ---- saved entries edit/delete (unchanged) ----
  patchEdit(field: string, value: unknown): void {
    this.editForm.update((f) => ({ ...f, [field]: value }));
  }

  startEdit(r: OpeningEntry): void {
    this.editError.set(null);
    const pack = (r.pack_size || '').trim();
    const upp = Math.max(1, Number(r.units_per_pack) || this.unitsFromPackLabel(pack) || 1);
    this.editForm.set({
      batch_id: Number(r.batch_id),
      product_binding_id: Number(r.product_binding_id) || 0,
      product_name: r.product_name,
      batch_no: r.batch_no || '',
      expiry_date: r.expiry_date ? String(r.expiry_date).slice(0, 10) : '',
      quantity: Number(r.quantity) || 0,
      mrp: Number(r.mrp) || 0,
      purchase_rate: Number(r.net_rate) || 0,
      pack_size: pack,
      units_per_pack: upp,
    });
    const match = this.findPackOption(pack);
    this.editPackSearch.set(match ? this.packLabel(match) : pack);
    this.editShowPackDropdown.set(false);
    this.editOpen.set(true);
  }

  closeEdit(): void {
    this.editOpen.set(false);
    this.editError.set(null);
    this.editShowPackDropdown.set(false);
  }

  openEditPackDropdown(): void {
    if (this.editPackBlurTimer) {
      clearTimeout(this.editPackBlurTimer);
      this.editPackBlurTimer = undefined;
    }
    this.editFilteredPackSizes.set(this.filterPackSizeList(this.editPackSearch()));
    this.editShowPackDropdown.set(true);
  }

  onEditPackSearchInput(value: string): void {
    this.editPackSearch.set(value);
    this.editFilteredPackSizes.set(this.filterPackSizeList(value));
    this.editShowPackDropdown.set(true);
  }

  selectEditPackSize(p: PackOpt): void {
    if (this.editPackBlurTimer) {
      clearTimeout(this.editPackBlurTimer);
      this.editPackBlurTimer = undefined;
    }
    const pack = this.normalizePack(p.pack);
    const upp = Math.max(1, Number(p.units) || 1);
    this.editPackSearch.set(this.packLabel(p));
    this.editForm.update((f) => ({ ...f, pack_size: pack, units_per_pack: upp }));
    this.editShowPackDropdown.set(false);
  }

  onEditPackSearchBlur(): void {
    this.editPackBlurTimer = setTimeout(() => {
      this.editShowPackDropdown.set(false);
      const typed = this.editPackSearch().trim();
      if (!typed) {
        this.editPackBlurTimer = undefined;
        return;
      }
      const found = this.findPackOption(typed);
      if (found) {
        this.selectEditPackSize(found);
      } else {
        const pack = this.normalizePack(typed);
        this.editForm.update((f) => ({
          ...f,
          pack_size: pack,
          units_per_pack: this.unitsFromPackLabel(pack) || f.units_per_pack || 1,
        }));
        this.editPackSearch.set(pack);
      }
      this.editPackBlurTimer = undefined;
    }, 180);
  }

  saveEdit(): void {
    const f = this.editForm();
    if (!f.batch_id || !f.batch_no.trim()) {
      this.editError.set('Batch is required.');
      return;
    }
    this.editSaving.set(true);
    this.stockSvc
      .openingUpdate(f.batch_id, {
        batch_no: f.batch_no,
        expiry_date: f.expiry_date || null,
        quantity: f.quantity,
        mrp: f.mrp,
        purchase_rate: f.purchase_rate,
        pack_size: f.pack_size || null,
        units_per_pack: Math.max(1, f.units_per_pack || 1),
      })
      .subscribe({
        next: () => {
          this.editSaving.set(false);
          this.editOpen.set(false);
          this.showToast('Updated.');
          this.load();
        },
        error: (err: HttpErrorResponse) => {
          this.editSaving.set(false);
          this.editError.set(err.error?.message ?? 'Update failed.');
        },
      });
  }

  remove(r: OpeningEntry): void {
    if (!r.batch_id || !confirm(`Delete opening stock for "${r.product_name}"?`)) return;
    this.stockSvc.openingDelete(Number(r.batch_id)).subscribe({
      next: () => { this.showToast('Deleted.'); this.load(); },
      error: (err: HttpErrorResponse) => this.error.set(err.error?.message ?? 'Delete failed.'),
    });
  }

  writeOff(): void {
    if (!confirm('Write off all expired stock?')) return;
    this.stockSvc.writeOffExpired().subscribe((r) => {
      this.showToast(`Wrote off ${r.data.batches} batch(es).`);
      this.load();
    });
  }

  private showToast(m: string): void { this.toast.set(m); setTimeout(() => this.toast.set(null), 3500); }
}
