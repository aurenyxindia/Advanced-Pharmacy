import { Component, computed, effect, HostListener, inject, signal, untracked } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { ProductSearchResult, PurchaseService } from '../../core/services/purchase.service';
import { MetaService } from '../../core/services/meta.service';
import { CrudService } from '../../core/services/crud.service';
import { PurchaseDraftService, PurchaseDraft } from '../../core/services/purchase-draft.service';
import { ReturnNoteService } from '../../core/services/return-note.service';
import { expiryForApiSave, formatExpiryMonth, formatHsn, isValidHsn, toUpper } from '../../core/utils/input-format';
import { compactDistributorAddress } from '../../core/utils/distributor-label';

type DistOption = { id: number; name: string; address?: string | null };

interface Entry {
  item_id?: number;
  product_binding_id?: number;
  master_product_id?: number;
  product_name: string;
  schedule_category: string;
  product_category: string;
  hsn_code: string;
  batch_no: string;
  expiry_date: string;
  shelf_location: string;
  pack_size: string;
  quantity: number;       // packs
  units_per_pack: number;
  free_quantity: number;
  purchase_rate: number;  // per pack
  mrp: number;            // per pack
  ptr: number;
  pts: number;
  gst_rate: number;
  discount_pct: number;
  discount_amt: number;
  scheme_pct: number;
}

function emptyEntry(): Entry {
  return {
    product_name: '', schedule_category: 'OTC', product_category: '', hsn_code: '', batch_no: '', expiry_date: '',
    shelf_location: '', pack_size: '', quantity: 1, units_per_pack: 1, free_quantity: 0, purchase_rate: 0,
    mrp: 0, ptr: 0, pts: 0, gst_rate: 5, discount_pct: 0, discount_amt: 0, scheme_pct: 0,
  };
}

interface SoldEditLine {
  product_name: string;
  batch_no: string;
  purchased: number;
  sold: number;
  in_stock: number;
}

@Component({
  selector: 'app-purchase-entry',
  imports: [CommonModule, FormsModule],
  templateUrl: './purchase-entry.html',
  styleUrl: './purchase-entry.scss',
})
export class PurchaseEntry {
  private readonly purchaseSvc = inject(PurchaseService);
  private readonly meta = inject(MetaService);
  private readonly crud = inject(CrudService);
  private readonly returnNoteSvc = inject(ReturnNoteService);
  private readonly draftSvc = inject(PurchaseDraftService);
  private readonly route = inject(ActivatedRoute);
  readonly router = inject(Router);
  readonly suppliers = signal<DistOption[]>([]);
  readonly distributorAddr = compactDistributorAddress;

  readonly purchaseId = signal<number | null>(null);
  readonly poNoLabel = signal('');
  readonly isEditMode = computed(() => this.purchaseId() != null);
  readonly loadingPurchase = signal(false);

  readonly invoiceNo = signal('');
  readonly invoiceDate = signal(new Date().toISOString().slice(0, 10));
  readonly supplierId = signal<number | null>(null);
  readonly supplierName = signal('');
  readonly supplierQuery = signal('');
  readonly showSupplierDropdown = signal(false);
  /** Retail header parity */
  readonly cashCredit = signal<'Cash' | 'UPI' | 'Cheque' | 'Credit'>('Credit');
  readonly dueDate = signal(this.defaultDueDate());
  readonly cdMode = signal<'Percentage' | 'Amount'>('Percentage');
  readonly cdPct = signal(0);
  readonly cdAmt = signal(0);
  /** Retail: base amount for CD % when Total Bill CD = Amount. */
  readonly netBillAmt = signal(0);
  readonly transportCharge = signal(0);
  readonly transportGstPct = signal(0);
  readonly debitNoteNo = signal('');
  readonly debitDeductionAmt = signal(0);
  readonly openDebitNotes = signal<{ note_no: string; label: string; amount: number }[]>([]);

  readonly entry = signal<Entry>(emptyEntry());
  readonly lines = signal<Entry[]>([]);
  readonly saving = signal(false);
  readonly error = signal<string | null>(null);
  readonly toast = signal<string | null>(null);

  // product searchable dropdown
  readonly searchResults = signal<ProductSearchResult[]>([]);
  private searchTimer?: ReturnType<typeof setTimeout>;
  readonly showResults = signal(false);
  readonly productQuery = signal('');

  readonly scheduleOptions = ['OTC', 'H', 'H1', 'X', 'G', 'RESTRICTED'];
  readonly categoryOptions = ['Allopathy', 'Ayurvedic', 'Generic', 'Surgical', 'Cosmetic'];
  readonly gstOptions = [0, 5, 12, 18, 28];
  /** Searchable pack dropdown (Retail parity). */
  readonly packSizeOptions = signal<{ pack: string; units: number; form: string }[]>([]);
  readonly packSizeSearch = signal('');
  readonly showPackDropdown = signal(false);
  readonly filteredPackSizes = signal<{ pack: string; units: number; form: string }[]>([]);
  /** Pack dropdown on a saved line row (after ADD / edit purchase). */
  readonly openLinePackIndex = signal<number | null>(null);
  readonly linePackQuery = signal('');
  readonly filteredLinePackSizes = signal<{ pack: string; units: number; form: string }[]>([]);
  private packBlurTimer?: ReturnType<typeof setTimeout>;
  private linePackBlurTimer?: ReturnType<typeof setTimeout>;
  private rateTimer?: ReturnType<typeof setTimeout>;
  private invoiceCheckTimer?: ReturnType<typeof setTimeout>;
  private restored = false;
  readonly invoiceWarning = signal<string | null>(null);
  readonly distributorsError = signal<string | null>(null);
  readonly distributorsReady = signal(false);
  private distributorSearchTimer?: ReturnType<typeof setTimeout>;
  /** Server search hits while user types (covers full catalog). */
  private readonly supplierSearchHits = signal<DistOption[]>([]);
  private readonly supplierSearchMode = signal(false);

  readonly filteredSuppliers = computed(() => {
    const q = this.supplierQuery().trim().toUpperCase();
    if (q && this.supplierSearchMode()) {
      return this.supplierSearchHits();
    }
    const list = this.suppliers();
    if (!q) return list;
    return list.filter((s) => {
      const name = (s.name ?? '').toUpperCase();
      const addr = (s.address ?? '').toUpperCase();
      return name.includes(q) || addr.includes(q);
    });
  });

  /** Compact address for the selected distributor (closed combo). */
  readonly selectedSupplierAddr = computed(() => {
    const id = this.supplierId();
    if (!id) return '';
    const row = this.suppliers().find((s) => s.id === id);
    return compactDistributorAddress(row?.address);
  });

  /** Product dropdown keyboard highlight (Retail parity). */
  readonly selectedProductIndex = signal(-1);

  constructor() {
    this.meta.loadPackSizes().subscribe((list) => {
      this.packSizeOptions.set(list ?? []);
      this.syncPackSearchFromEntry();
    });
    const editId = this.route.snapshot.paramMap.get('id');
    const isEdit = this.route.snapshot.url.some((s) => s.path === 'edit');
    if (isEdit && editId) {
      this.purchaseId.set(+editId);
      this.loadAllDistributors(() => this.loadPurchaseForEdit(+editId));
    } else {
      this.loadAllDistributors(() => this.restoreDraftIfAny());
    }
    effect(() => {
      if (!this.draftReady() || this.purchaseId() != null) return;
      const draft: PurchaseDraft = {
        invoiceNo: this.invoiceNo(),
        invoiceDate: this.invoiceDate(),
        supplierId: this.supplierId(),
        supplierName: this.supplierName(),
        cashCredit: this.cashCredit(),
        dueDate: this.dueDate(),
        cdMode: this.cdMode(),
        cdPct: this.cdPct(),
        cdAmt: this.cdAmt(),
        netBillAmt: this.netBillAmt(),
        transportCharge: this.transportCharge(),
        transportGstPct: this.transportGstPct(),
        debitNoteNo: this.debitNoteNo(),
        debitDeductionAmt: this.debitDeductionAmt(),
        entry: { ...this.entry() },
        lines: this.lines().map((l) => ({ ...l })),
        productQuery: this.productQuery(),
        packSelect: this.packSizeSearch(),
      };
      untracked(() => {
        if (this.draftIsWorthSaving(draft)) this.draftSvc.saveDraft(draft);
        else this.draftSvc.clearDraft();
      });
    });
  }

  /** Becomes true after the stored draft is applied, so an empty first paint cannot wipe it. */
  private readonly draftReady = signal(false);

  private loadPurchaseForEdit(id: number): void {
    this.loadingPurchase.set(true);
    this.purchaseSvc.get(id).subscribe({
      next: (p) => {
        this.poNoLabel.set(String(p['po_no'] ?? p['id'] ?? id));
        this.invoiceNo.set(String(p['invoice_no'] ?? ''));
        this.invoiceDate.set(String(p['invoice_date'] ?? '').slice(0, 10));
        this.supplierId.set(p['supplier_id'] != null ? Number(p['supplier_id']) : null);
        this.supplierName.set(String(p['supplier_name'] ?? ''));
        const sid = this.supplierId();
        const sname = this.supplierName().trim();
        if (sid && sname && !this.suppliers().some((s) => s.id === sid)) {
          this.suppliers.update((list) => this.sortDistributors([...list, { id: sid, name: sname }]));
        }
        const cc = String(p['cash_credit'] ?? 'Credit');
        this.cashCredit.set((['Cash', 'UPI', 'Cheque', 'Credit'].includes(cc) ? cc : 'Credit') as 'Cash' | 'UPI' | 'Cheque' | 'Credit');
        this.dueDate.set(String(p['due_date'] ?? '').slice(0, 10) || this.defaultDueDate());
        const cdm = String(p['cd_mode'] ?? 'Percentage');
        this.cdMode.set(cdm === 'Amount' ? 'Amount' : 'Percentage');
        this.cdPct.set(Number(p['cd_pct']) || 0);
        this.cdAmt.set(Number(p['cd_amount']) || 0);
        this.transportCharge.set(Number(p['transport_charge']) || 0);
        this.transportGstPct.set(Number(p['transport_gst_pct']) || 0);
        this.debitNoteNo.set(String(p['debit_note_no'] ?? ''));
        this.debitDeductionAmt.set(Number(p['debit_deduction_amt']) || 0);

        const items = Array.isArray(p['items']) ? p['items'] as Record<string, unknown>[] : [];
        const mapped: Entry[] = items.map((it) => ({
          item_id: Number(it['id'] ?? 0) || undefined,
          product_binding_id: it['product_binding_id'] != null ? Number(it['product_binding_id']) : undefined,
          master_product_id: undefined,
          product_name: String(it['product_name'] ?? ''),
          schedule_category: 'OTC',
          product_category: 'Allopathy',
          hsn_code: String(it['hsn_code'] ?? ''),
          batch_no: String(it['batch_no'] ?? ''),
          expiry_date: formatExpiryMonth(it['expiry_date']),
          shelf_location: String(it['shelf_location'] ?? ''),
          pack_size: String(it['pack_size'] ?? ''),
          quantity: Number(it['quantity']) || 0,
          units_per_pack: Number(it['units_per_pack']) || 1,
          free_quantity: Number(it['free_quantity']) || 0,
          purchase_rate: Number(it['purchase_rate']) || 0,
          mrp: Number(it['mrp']) || 0,
          ptr: Number(it['ptr']) || 0,
          pts: Number(it['pts']) || 0,
          gst_rate: Number(it['gst_rate']) || 5,
          discount_pct: Number(it['discount_pct']) || 0,
          discount_amt: this.lineDiscAmtFromRow(it),
          scheme_pct: Number(it['scheme_pct']) || 0,
        }));
        this.lines.set(mapped);

        if (this.supplierName()) this.loadOpenDebitNotes();
        this.loadingPurchase.set(false);
      },
      error: (err: HttpErrorResponse) => {
        this.loadingPurchase.set(false);
        this.error.set(err.error?.message ?? 'Could not load purchase for edit.');
      },
    });
  }

  /** Month bounds for expiry inputs (YYYY-MM). */
  readonly minExpiry = (() => {
    const d = new Date();
    d.setFullYear(d.getFullYear() - 1);
    return d.toISOString().slice(0, 7);
  })();
  readonly maxExpiry = (() => {
    const d = new Date();
    d.setFullYear(d.getFullYear() + 12);
    return d.toISOString().slice(0, 7);
  })();

  displayExpiry(raw: string | null | undefined): string {
    return formatExpiryMonth(raw) || '-';
  }

  patchLine(i: number, field: keyof Entry, value: unknown): void {
    this.lines.update((ls) => {
      const next = [...ls];
      const row = { ...next[i] };
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      let v: any = value;
      if (typeof v === 'string') {
        if (field === 'hsn_code') v = formatHsn(v);
        else if (field === 'expiry_date') v = formatExpiryMonth(v);
        else if (field === 'batch_no' || field === 'product_name' || field === 'shelf_location' || field === 'pack_size') {
          v = toUpper(v);
        }
      }
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      (row as any)[field] = v;
      this.syncLineDiscount(row, field);
      next[i] = row;
      return next;
    });
  }

  /** Retail: disc % ↔ disc amt stay in sync on line edits. */
  private syncLineDiscount(row: Entry, changed: keyof Entry): void {
    const gross = this.lineGross(row);
    if (changed === 'discount_amt') {
      row.discount_pct = gross > 0
        ? Math.round(((Number(row.discount_amt) || 0) * 100) / gross * 10000) / 10000
        : 0;
      return;
    }
    if (changed === 'discount_pct' || changed === 'purchase_rate' || changed === 'quantity') {
      row.discount_amt = Math.round(gross * ((Number(row.discount_pct) || 0) / 100) * 100) / 100;
    }
  }

  private lineDiscAmtFromRow(row: Record<string, unknown> | Entry): number {
    const qty = Number(row['quantity']) || 0;
    const rate = Number(row['purchase_rate']) || 0;
    const gross = qty * rate;
    const pct = Number(row['discount_pct']) || 0;
    return Math.round(gross * (pct / 100) * 100) / 100;
  }

  /** Edit pack on a saved line — recalculate units/pack from the pack label. */
  onLinePackChange(i: number, value: string, units?: number): void {
    const pack = this.normalizePack(value);
    const upp = units ?? this.unitsFromPackLabel(pack);
    if (!Number.isInteger(i) || i < 0 || i >= this.lines().length) return;
    this.lines.update((ls) => {
      const next = [...ls];
      next[i] = { ...next[i], pack_size: pack, units_per_pack: upp };
      return next;
    });
    this.persistDraft();
  }

  @HostListener('document:mousedown', ['$event'])
  onDocumentMouseDown(ev: MouseEvent): void {
    const t = ev.target as HTMLElement | null;
    if (!t?.closest('.supplier-combo')) this.showSupplierDropdown.set(false);
    if (!t?.closest('.product-combo')) this.showResults.set(false);
    if (!t?.closest('.pack-select-wrap')) {
      this.showPackDropdown.set(false);
      this.openLinePackIndex.set(null);
    }
  }

  /** Load entire distributor catalog (API caps 200/page). */
  private loadAllDistributors(after?: () => void): void {
    this.distributorsError.set(null);
    this.fetchDistributorPages(1, [], after);
  }

  private mapDistRow(row: { id: number; name: string; address?: string | null } | Record<string, unknown>): DistOption {
    const r = row as Record<string, unknown>;
    return {
      id: Number(r['id'] ?? 0),
      name: String(r['name'] ?? ''),
      address: r['address'] != null && String(r['address']).trim() !== '' ? String(r['address']) : null,
    };
  }

  private fetchDistributorPages(
    page: number,
    acc: DistOption[],
    after?: () => void,
  ): void {
    this.crud.list<{ id: number; name: string; address?: string | null }>('distributors', { page, perPage: 200 }).subscribe({
      next: (r) => {
        const batch = (r.data ?? []).map((row) => this.mapDistRow(row));
        const combined = [...acc, ...batch];
        const total = r.total ?? combined.length;
        if (batch.length > 0 && combined.length < total) {
          this.fetchDistributorPages(page + 1, combined, after);
          return;
        }
        this.suppliers.set(this.sortDistributors(combined));
        this.distributorsReady.set(true);
        after?.();
      },
      error: (err: HttpErrorResponse) => {
        if (acc.length) {
          this.suppliers.set(this.sortDistributors(acc));
          this.distributorsReady.set(true);
        }
        this.distributorsError.set(err.error?.message ?? 'Could not load distributors.');
        after?.();
      },
    });
  }

  /** @deprecated use loadAllDistributors */
  loadDistributors(search = '', after?: () => void): void {
    this.distributorsError.set(null);
    this.crud.list<{ id: number; name: string; address?: string | null }>('distributors', {
      search: search.trim() || undefined,
      perPage: 200,
      page: 1,
    }).subscribe({
      next: (r) => {
        const rows = (r.data ?? []).map((row) => this.mapDistRow(row));
        if (search.trim()) {
          this.supplierSearchHits.set(this.sortDistributors(rows));
          this.supplierSearchMode.set(true);
        } else {
          this.suppliers.set(this.sortDistributors(rows));
          this.distributorsReady.set(true);
        }
        after?.();
      },
      error: (err: HttpErrorResponse) => {
        this.distributorsError.set(err.error?.message ?? 'Could not load distributors.');
        after?.();
      },
    });
  }

  private sortDistributors(rows: DistOption[]): DistOption[] {
    return [...rows].sort((a, b) =>
      a.name.localeCompare(b.name, undefined, { sensitivity: 'base' }),
    );
  }

  /** @deprecated use loadDistributors */
  loadSuppliers(after?: () => void): void {
    this.loadDistributors('', after);
  }

  /** Persist header + lines so leaving New Purchase and coming back restores the bill. */
  persistDraft(): void {
    if (this.isEditMode()) return;
    this.draftSvc.saveDraft({
      invoiceNo: this.invoiceNo(),
      invoiceDate: this.invoiceDate(),
      supplierId: this.supplierId(),
      supplierName: this.supplierName(),
      cashCredit: this.cashCredit(),
      dueDate: this.dueDate(),
      cdMode: this.cdMode(),
      cdPct: this.cdPct(),
      cdAmt: this.cdAmt(),
      netBillAmt: this.netBillAmt(),
      transportCharge: this.transportCharge(),
      transportGstPct: this.transportGstPct(),
      debitNoteNo: this.debitNoteNo(),
      debitDeductionAmt: this.debitDeductionAmt(),
      entry: { ...this.entry() },
      lines: this.lines().map((l) => ({ ...l })),
      productQuery: this.productQuery(),
      packSelect: this.packSizeSearch(),
    });
  }

  private applyDraftHeader(draft: PurchaseDraft): void {
    this.invoiceNo.set(draft.invoiceNo ?? '');
    this.invoiceDate.set(draft.invoiceDate || new Date().toISOString().slice(0, 10));
    this.supplierId.set(draft.supplierId ?? null);
    this.supplierName.set(draft.supplierName ?? '');
    if (draft.cashCredit) this.cashCredit.set(draft.cashCredit);
    if (draft.dueDate) this.dueDate.set(draft.dueDate);
    if (draft.cdMode) this.cdMode.set(draft.cdMode);
    if (draft.cdPct != null) this.cdPct.set(Number(draft.cdPct) || 0);
    if (draft.cdAmt != null) this.cdAmt.set(Number(draft.cdAmt) || 0);
    if (draft.netBillAmt != null) this.netBillAmt.set(Number(draft.netBillAmt) || 0);
    if (draft.transportCharge != null) this.transportCharge.set(Number(draft.transportCharge) || 0);
    if (draft.transportGstPct != null) this.transportGstPct.set(Number(draft.transportGstPct) || 0);
    if (draft.debitNoteNo != null) this.debitNoteNo.set(String(draft.debitNoteNo));
    if (draft.debitDeductionAmt != null) this.debitDeductionAmt.set(Number(draft.debitDeductionAmt) || 0);
  }

  private restoreDraftIfAny(): void {
    if (this.restored) return;
    this.restored = true;
    this.draftReady.set(true);

    const resume = this.draftSvc.consumeExpectResume();
    const handshake = this.draftSvc.consumeHandshake();
    const draft = this.draftSvc.loadDraft();
    if (!draft && !handshake) return;

    if (draft) {
      this.applyDraftHeader(draft);
      const merged = { ...emptyEntry(), ...(draft.entry as Partial<Entry>) };
      // Blank ADD row: keep form default GST 5 (ignore stale draft left on 12).
      if (!String(merged.product_name || '').trim()) {
        merged.gst_rate = 5;
      } else if (merged.gst_rate == null || Number.isNaN(Number(merged.gst_rate))) {
        merged.gst_rate = 5;
      }
      this.entry.set(merged);
      this.lines.set(
        Array.isArray(draft.lines)
          ? (draft.lines as unknown as Entry[]).map((l) => ({
              ...l,
              expiry_date: formatExpiryMonth(l.expiry_date),
              gst_rate: Number(l.gst_rate) || 5,
            }))
          : [],
      );
      this.productQuery.set(draft.productQuery ?? '');
      this.packSizeSearch.set(draft.packSelect ?? '');
      this.syncPackSearchFromEntry();
      if (this.supplierName()) this.loadOpenDebitNotes();
    }

    if (handshake?.createdSupplierId) {
      const id = handshake.createdSupplierId;
      const found = this.suppliers().find((s) => s.id === id);
      if (found) {
        this.pickSupplier(found);
        this.showToast('Distributor added and selected. Continue your purchase.');
      } else {
        this.crud.get<{ id: number; name: string; address?: string | null }>('distributors', id).subscribe({
          next: (row) => {
            const entry = this.mapDistRow(row);
            this.pickSupplier(entry);
            this.suppliers.update((list) => this.sortDistributors([...list, entry]));
            this.showToast('Distributor added and selected. Continue your purchase.');
          },
          error: () => this.showToast('Distributor created. Please select it from the list.'),
        });
      }
      return;
    }

    if (handshake?.createdProductId) {
      const id = handshake.createdProductId;
      this.crud.get<Record<string, unknown>>('products', id).subscribe({
        next: (p) => {
          this.selectProduct({
            id: Number(p['id']),
            name: String(p['name'] ?? ''),
            manufacturer: (p['manufacturer'] as string | null) ?? null,
            manufacturer_short: (p['manufacturer_short'] as string | null) ?? null,
            schedule_category: String(p['schedule_category'] ?? 'OTC'),
            product_category: String(p['product_category'] ?? ''),
            default_gst_rate: Number(p['default_gst_rate']) || 5,
            generic_name: null,
            hsn_code: (p['hsn_code'] as string | null) ?? null,
            pack_size: (p['pack_size'] as string | null) ?? null,
            units_per_pack: Number(p['units_per_pack']) || 1,
            shelf_location: (p['shelf_location'] as string | null) ?? null,
            mrp: Number(p['mrp']) || 0,
            ptr: Number(p['ptr']) || 0,
            pts: Number(p['pts']) || 0,
          });
          this.showToast('Product added and selected. Continue your purchase.');
        },
        error: () => {
          this.purchaseSvc.searchProducts('', 50).subscribe((list) => {
            const hit = list.find((x) => x.id === id);
            if (hit) {
              this.selectProduct(hit);
              this.showToast('Product added and selected. Continue your purchase.');
            } else {
              this.showToast('Product created. Search and select it to continue.');
            }
          });
        },
      });
      return;
    }

    if (draft && resume && !handshake?.createdSupplierId && !handshake?.createdProductId) {
      this.showToast('Purchase draft restored.');
    }
  }

  /** True when New Purchase has an invoice, distributor, or lines worth keeping. */
  hasDraft(): boolean {
    if (this.isEditMode()) return false;
    return this.draftIsWorthSaving({
      invoiceNo: this.invoiceNo(),
      invoiceDate: this.invoiceDate(),
      supplierId: this.supplierId(),
      supplierName: this.supplierName(),
      cashCredit: this.cashCredit(),
      entry: { ...this.entry() },
      lines: this.lines().map((l) => ({ ...l })),
      productQuery: this.productQuery(),
      packSelect: this.packSizeSearch(),
      transportCharge: this.transportCharge(),
      transportGstPct: this.transportGstPct(),
      debitNoteNo: this.debitNoteNo(),
      debitDeductionAmt: this.debitDeductionAmt(),
      cdPct: this.cdPct(),
      cdAmt: this.cdAmt(),
      netBillAmt: this.netBillAmt(),
    });
  }

  private draftIsWorthSaving(draft: PurchaseDraft): boolean {
    const entry = draft.entry ?? {};
    if ((draft.lines?.length ?? 0) > 0) return true;
    if ((draft.invoiceNo ?? '').trim() || (draft.supplierName ?? '').trim() || draft.supplierId) return true;
    if ((draft.productQuery ?? '').trim() || String(entry['product_name'] ?? '').trim() || String(entry['batch_no'] ?? '').trim()) return true;
    if ((draft.transportCharge ?? 0) > 0 || (draft.transportGstPct ?? 0) > 0) return true;
    if ((draft.debitDeductionAmt ?? 0) > 0 || (draft.debitNoteNo ?? '').trim()) return true;
    if ((draft.cdPct ?? 0) > 0 || (draft.cdAmt ?? 0) > 0 || (draft.netBillAmt ?? 0) > 0) return true;
    if (draft.cashCredit && draft.cashCredit !== 'Credit') return true;
    return false;
  }

  /** Clear the open New Purchase. A saved bill is not affected. */
  discardDraft(): void {
    if (!this.hasDraft()) return;
    if (!confirm('Discard this unsaved purchase?')) return;
    this.invoiceNo.set('');
    this.invoiceDate.set(new Date().toISOString().slice(0, 10));
    this.supplierId.set(null);
    this.supplierName.set('');
    this.supplierQuery.set('');
    this.showSupplierDropdown.set(false);
    this.cashCredit.set('Credit');
    this.dueDate.set(this.defaultDueDate());
    this.cdMode.set('Percentage');
    this.cdPct.set(0);
    this.cdAmt.set(0);
    this.netBillAmt.set(0);
    this.transportCharge.set(0);
    this.transportGstPct.set(0);
    this.debitNoteNo.set('');
    this.debitDeductionAmt.set(0);
    this.openDebitNotes.set([]);
    this.entry.set(emptyEntry());
    this.lines.set([]);
    this.productQuery.set('');
    this.packSizeSearch.set('');
    this.showResults.set(false);
    this.searchResults.set([]);
    this.showPackDropdown.set(false);
    this.error.set(null);
    this.invoiceWarning.set(null);
    this.draftSvc.clearDraft();
  }

  goAddProduct(ev?: Event): void {
    ev?.preventDefault();
    ev?.stopPropagation();
    this.showResults.set(false);
    this.persistDraft();
    this.draftSvc.markExpectResume();
    const name = (this.entry().product_name || this.productQuery()).trim();
    this.router.navigate(['/app/masters/products'], {
      queryParams: {
        returnUrl: '/app/purchase/new',
        create: '1',
        ...(name ? { name } : {}),
      },
    });
  }

  goAddSupplier(ev?: Event): void {
    ev?.preventDefault();
    ev?.stopPropagation();
    this.showSupplierDropdown.set(false);
    this.persistDraft();
    this.draftSvc.markExpectResume();
    const name = (this.supplierQuery() || this.supplierName()).trim();
    this.router.navigate(['/app/masters/distributors'], {
      queryParams: {
        returnUrl: '/app/purchase/new',
        create: '1',
        ...(name ? { name } : {}),
      },
    });
  }

  openSupplierDropdown(ev?: Event): void {
    ev?.preventDefault();
    ev?.stopPropagation();
    const opening = !this.showSupplierDropdown();
    this.supplierQuery.set('');
    this.supplierSearchMode.set(false);
    this.supplierSearchHits.set([]);
    this.showSupplierDropdown.set(opening);
    this.showResults.set(false);
  }

  onSupplierQuery(q: string): void {
    const term = toUpper(q);
    this.supplierQuery.set(term);
    this.showSupplierDropdown.set(true);
    if (this.distributorSearchTimer) clearTimeout(this.distributorSearchTimer);
    const trimmed = term.trim();
    if (!trimmed) {
      this.supplierSearchMode.set(false);
      this.supplierSearchHits.set([]);
      return;
    }
    this.distributorSearchTimer = setTimeout(() => {
      this.searchDistributorsFromServer(trimmed);
    }, 250);
  }

  private searchDistributorsFromServer(term: string): void {
    this.crud.list<{ id: number; name: string; address?: string | null }>('distributors', {
      search: term,
      perPage: 200,
      page: 1,
    }).subscribe({
      next: (r) => {
        if (this.supplierQuery().trim().toUpperCase() !== term.toUpperCase()) return;
        this.supplierSearchHits.set(this.sortDistributors((r.data ?? []).map((row) => this.mapDistRow(row))));
        this.supplierSearchMode.set(true);
      },
      error: () => {
        if (this.supplierQuery().trim().toUpperCase() !== term.toUpperCase()) return;
        this.supplierSearchHits.set([]);
        this.supplierSearchMode.set(true);
      },
    });
  }

  pickSupplier(s: DistOption): void {
    this.supplierId.set(s.id);
    this.supplierName.set(toUpper(s.name));
    this.supplierQuery.set('');
    this.supplierSearchMode.set(false);
    this.supplierSearchHits.set([]);
    this.showSupplierDropdown.set(false);
    this.loadOpenDebitNotes();
    this.persistDraft();
  }

  clearSupplier(ev?: Event): void {
    ev?.stopPropagation();
    this.supplierId.set(null);
    this.supplierName.set('');
    this.supplierQuery.set('');
    this.debitNoteNo.set('');
    this.debitDeductionAmt.set(0);
    this.openDebitNotes.set([]);
    this.persistDraft();
  }

  onInvoiceNo(value: string): void {
    this.invoiceNo.set(toUpper(value));
    this.invoiceWarning.set(null);
    if (this.invoiceCheckTimer) clearTimeout(this.invoiceCheckTimer);
    const inv = toUpper(value).trim();
    if (!inv || this.isEditMode()) return;
    this.invoiceCheckTimer = setTimeout(() => {
      this.purchaseSvc.checkInvoice(inv).subscribe({
        next: (r) => {
          if (r.exists) {
            const po = r.purchase?.['po_no'] ?? r.purchase?.['id'] ?? '';
            this.invoiceWarning.set(`Invoice already exists${po ? ` (PO ${po})` : ''}.`);
          } else {
            this.invoiceWarning.set(null);
          }
        },
        error: () => this.invoiceWarning.set(null),
      });
    }, 400);
  }

  /** Display label for pack dropdown rows (Retail: plain pack text). */
  packLabel(p: { pack: string; label?: string }): string {
    return (p.label || p.pack).trim();
  }

  private normalizePack(value: string): string {
    return String(value ?? '').trim();
  }

  /** "1 x 15", "1X15", "1*15" → "1x15" so the same pack never lists twice. */
  private packKey(value: string): string {
    return String(value ?? '')
      .toLowerCase()
      .replace(/\s*[x×*&]\s*/g, 'x')
      .replace(/\s+/g, '');
  }

  private findPackOption(value: string): { pack: string; units: number; form: string; label?: string } | undefined {
    const key = this.packKey(value);
    if (!key) return undefined;
    const list = this.packSizeOptions();
    return (
      list.find((p) => this.packKey(p.pack) === key) ??
      list.find((p) => this.packKey(this.packLabel(p)) === key)
    );
  }

  openPackDropdown(): void {
    if (this.packBlurTimer) {
      clearTimeout(this.packBlurTimer);
      this.packBlurTimer = undefined;
    }
    this.openLinePackIndex.set(null);
    this.filterPackSizes();
    this.showPackDropdown.set(true);
  }

  onPackSearchInput(value: string): void {
    this.packSizeSearch.set(value);
    this.filterPackSizes();
    this.showPackDropdown.set(true);
  }

  filterPackSizes(): void {
    this.filteredPackSizes.set(this.filterPackSizeList(this.packSizeSearch()));
  }

  filterLinePackSizes(): void {
    this.filteredLinePackSizes.set(this.filterPackSizeList(this.linePackQuery()));
  }

  private filterPackSizeList(query: string): { pack: string; units: number; form: string }[] {
    const list = this.packSizeOptions();
    const q = query.trim().toLowerCase();
    const filtered = !q
      ? list
      : list.filter((p) => {
          const label = this.packLabel(p).toLowerCase();
          const key = this.packKey(q);
          return label.includes(q) || p.pack.toLowerCase().includes(q)
            || this.packKey(label).includes(key) || this.packKey(p.pack).includes(key);
        });
    return filtered.slice(0, 40);
  }

  openLinePackDropdown(i: number, line: Entry): void {
    if (this.linePackBlurTimer) {
      clearTimeout(this.linePackBlurTimer);
      this.linePackBlurTimer = undefined;
    }
    this.showPackDropdown.set(false);
    this.openLinePackIndex.set(i);
    const pack = line.pack_size?.trim() || '';
    const match = this.findPackOption(pack);
    this.linePackQuery.set(match ? this.packLabel(match) : pack);
    this.filteredLinePackSizes.set(this.filterPackSizeList(''));
  }

  onLinePackSearchInput(i: number, value: string): void {
    this.openLinePackIndex.set(i);
    this.linePackQuery.set(value);
    this.filterLinePackSizes();
  }

  selectLinePackSize(i: number, p: { pack: string; units: number; form?: string; label?: string }): void {
    if (this.linePackBlurTimer) {
      clearTimeout(this.linePackBlurTimer);
      this.linePackBlurTimer = undefined;
    }
    const pack = this.normalizePack(p.pack);
    this.linePackQuery.set(this.packLabel(p));
    this.onLinePackChange(i, pack, p.units);
    this.openLinePackIndex.set(null);
  }

  onLinePackSearchBlur(i: number): void {
    this.linePackBlurTimer = setTimeout(() => {
      this.openLinePackIndex.set(null);
      const typed = this.linePackQuery().trim();
      if (typed) {
        this.applyLinePackFromSearch(i, typed);
      }
      this.linePackBlurTimer = undefined;
    }, 180);
  }

  private applyLinePackFromSearch(i: number, typed: string): void {
    const current = this.lines()[i]?.pack_size ?? '';
    if (current && this.packKey(current) === this.packKey(typed)) return;
    const found = this.findPackOption(typed);
    if (found) {
      this.onLinePackChange(i, this.normalizePack(found.pack), found.units);
      return;
    }
    const pack = this.normalizePack(typed);
    this.onLinePackChange(i, pack);
    this.meta.parsePack(pack).subscribe({
      next: (parsed) => {
        if (!parsed?.pack) return;
        const master = this.findPackOption(parsed.pack);
        if (master) {
          this.onLinePackChange(i, this.normalizePack(master.pack), master.units);
          return;
        }
        this.onLinePackChange(i, this.normalizePack(parsed.pack), parsed.units || undefined);
      },
    });
  }

  linePackDisplay(i: number, line: Entry): string {
    if (this.openLinePackIndex() === i) return this.linePackQuery();
    return line.pack_size || '';
  }

  selectPackSize(p: { pack: string; units: number; form?: string; label?: string }): void {
    const pack = this.normalizePack(p.pack);
    this.packSizeSearch.set(this.packLabel(p));
    this.entry.update((e) => ({ ...e, pack_size: pack, units_per_pack: p.units }));
    this.showPackDropdown.set(false);
    this.persistDraft();
  }

  onPackSearchBlur(): void {
    this.packBlurTimer = setTimeout(() => {
      this.showPackDropdown.set(false);
      const typed = this.packSizeSearch().trim();
      if (typed) {
        this.applyPackFromSearch(typed);
      } else {
        this.syncPackSearchFromEntry();
      }
      this.packBlurTimer = undefined;
    }, 180);
  }

  private applyPackFromSearch(typed: string): void {
    const found = this.findPackOption(typed);
    if (found) {
      const pack = this.normalizePack(found.pack);
      this.entry.update((e) => ({ ...e, pack_size: pack, units_per_pack: found.units }));
      this.packSizeSearch.set(this.packLabel(found));
      this.persistDraft();
      return;
    }
    const pack = this.normalizePack(typed);
    this.entry.update((e) => ({ ...e, pack_size: pack }));
    this.meta.parsePack(pack).subscribe({
      next: (parsed) => {
        if (!parsed?.pack) return;
        const master = this.findPackOption(parsed.pack);
        this.entry.update((e) => ({
          ...e,
          pack_size: this.normalizePack(master ? master.pack : parsed.pack),
          units_per_pack: master?.units || parsed.units || e.units_per_pack,
        }));
        this.syncPackSearchFromEntry();
        this.persistDraft();
      },
      error: () => this.persistDraft(),
    });
  }

  private syncPackSearchFromEntry(): void {
    const pack = this.entry().pack_size?.trim();
    if (!pack) {
      this.packSizeSearch.set('');
      return;
    }
    const match = this.findPackOption(pack);
    this.packSizeSearch.set(match ? this.packLabel(match) : pack);
  }

  // ---- entry edits ----
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  patch(field: keyof Entry, value: any): void {
    let v = value;
    if (typeof v === 'string') {
      if (field === 'hsn_code') v = formatHsn(v);
      else if (field === 'expiry_date') v = formatExpiryMonth(v);
      else if (
        field === 'product_name' ||
        field === 'batch_no' ||
        field === 'shelf_location'
      ) {
        v = toUpper(v);
      }
    }
    this.entry.update((e) => {
      const next = { ...e, [field]: v } as Entry;
      // Scheme % → free packs (same unit as billed qty). Stock conversion is (qty+free)×upp.
      if (field === 'scheme_pct' || (field === 'quantity' && next.scheme_pct > 0)) {
        next.free_quantity = Math.floor(Math.max(0, next.quantity) * (Math.max(0, next.scheme_pct) / 100));
      }
      this.syncLineDiscount(next, field);
      return next;
    });
    // Auto-derive PTR/PTS from MRP + GST + category margin.
    if (field === 'mrp' || field === 'gst_rate' || field === 'product_category') {
      clearTimeout(this.rateTimer);
      this.rateTimer = setTimeout(() => this.autoRates(), 250);
    }
  }

  private autoRates(): void {
    const e = this.entry();
    if (e.mrp <= 0) return;
    this.purchaseSvc.computeRates(e.mrp, e.gst_rate, e.product_category).subscribe((r) => {
      this.entry.update((cur) => ({ ...cur, ptr: r.ptr, pts: r.pts }));
    });
  }

  openProductDropdown(ev?: Event): void {
    ev?.stopPropagation();
    this.showSupplierDropdown.set(false);
    this.showResults.set(true);
    const q = this.productQuery() || this.entry().product_name || '';
    this.runProductSearch(q);
  }

  onProductInput(value: string): void {
    const name = toUpper(value);
    this.productQuery.set(name);
    this.patch('product_name', name);
    this.patch('master_product_id', undefined as unknown as number);
    this.showResults.set(true);
    clearTimeout(this.searchTimer);
    this.searchTimer = setTimeout(() => this.runProductSearch(name), 180);
  }

  private runProductSearch(value: string): void {
    this.selectedProductIndex.set(-1);
    this.purchaseSvc.searchProducts(value.trim()).subscribe((r) => this.searchResults.set(r));
  }

  handleProductKeyDown(event: KeyboardEvent): void {
    const list = this.searchResults();
    if (!this.showResults() || list.length === 0) return;

    const key = event.key;
    if (key === 'ArrowDown') {
      event.preventDefault();
      const next = this.selectedProductIndex() < 0 ? 0 : (this.selectedProductIndex() + 1) % list.length;
      this.selectedProductIndex.set(next);
      this.scrollSelectedProductIntoView();
    } else if (key === 'ArrowUp') {
      event.preventDefault();
      const cur = this.selectedProductIndex();
      const next = cur < 0 ? list.length - 1 : (cur - 1 + list.length) % list.length;
      this.selectedProductIndex.set(next);
      this.scrollSelectedProductIntoView();
    } else if (key === 'Enter') {
      const idx = this.selectedProductIndex();
      if (idx >= 0 && idx < list.length) {
        event.preventDefault();
        this.selectProduct(list[idx]);
      }
    } else if (key === 'Escape') {
      this.showResults.set(false);
      this.selectedProductIndex.set(-1);
    }
  }

  private scrollSelectedProductIntoView(): void {
    setTimeout(() => {
      const el = document.querySelector('.product-combo .combo-item.active-item') as HTMLElement | null;
      el?.scrollIntoView({ block: 'nearest' });
    }, 0);
  }

  selectProduct(p: ProductSearchResult): void {
    const pack = (p.pack_size ?? '').trim();
    const match = this.findPackOption(pack);
    const units = match?.units ?? this.unitsFromPackLabel(pack) ?? Math.max(1, p.units_per_pack || 1);
    this.entry.update((e) => ({
      ...e,
      master_product_id: p.id,
      product_name: p.name,
      schedule_category: p.schedule_category,
      product_category: p.product_category || '',
      hsn_code: p.hsn_code ?? '',
      gst_rate: Number(p.default_gst_rate) || 5,
      units_per_pack: units,
      pack_size: pack,
      shelf_location: p.shelf_location || '',
      mrp: Number(p.mrp) || 0,
      ptr: Number(p.ptr) || 0,
      pts: Number(p.pts) || 0,
    }));
    this.productQuery.set(p.name);
    if (match) {
      this.packSizeSearch.set(this.packLabel(match));
    } else if (pack) {
      this.packSizeSearch.set(pack);
    } else {
      this.packSizeSearch.set('');
    }
    this.searchResults.set([]);
    this.showResults.set(false);
    this.selectedProductIndex.set(-1);
    setTimeout(() => this.autoRates(), 50);
    this.persistDraft();
  }

  /** Match backend PackSize rules: 60ml / 100g = 1 unit, 1x10 = 10 units. */
  private unitsFromPackLabel(pack: string): number {
    const s = pack.trim().toLowerCase().replace(/\s+/g, '');
    if (!s) return 1;
    if (/^\d+(?:\.\d+)?(ml|l|gm|g|mg|mcg|kg|lit)$/.test(s)) return 1;
    const multiVol = s.match(/^(\d+)x\d+(?:\.\d+)?(ml|l|gm|g|mg|mcg|kg|lit)$/);
    if (multiVol) return Math.max(1, parseInt(multiVol[1], 10));
    const strip = s.match(/^(\d+)[x×*](\d+)$/);
    if (strip) {
      const a = parseInt(strip[1], 10);
      const b = parseInt(strip[2], 10);
      return Math.max(1, b > 1 ? b : a > 1 ? a : 1);
    }
    const tabs = s.match(/^(\d+)(?:'?s|tabs?|caps?|nos?|pcs?)$/);
    if (tabs) return Math.max(1, parseInt(tabs[1], 10));
    if (/^\d+$/.test(s)) return Math.max(1, parseInt(s, 10));
    return 1;
  }

  // ---- entry computed ----
  lineGross(e: Entry): number { return e.purchase_rate * e.quantity; }
  lineDiscAmt(e: Entry): number {
    if (e.discount_amt != null && e.discount_amt > 0) return e.discount_amt;
    return Math.round(this.lineGross(e) * ((e.discount_pct || 0) / 100) * 100) / 100;
  }
  baseUnits(e: Entry): number {
    // Free qty is packs (same as billed qty) — Retail: (qty + free) × units_per_pack
    return (e.quantity + (e.free_quantity || 0)) * (e.units_per_pack || 1);
  }
  netRate(e: Entry): number {
    const gross = this.lineGross(e);
    const disc = this.lineDiscAmt(e);
    const units = this.baseUnits(e);
    return units > 0 ? (gross - disc) / units : 0;
  }
  entryTaxable(e: Entry): number { return this.lineGross(e) - this.lineDiscAmt(e); }
  entryTotal(e: Entry): number { return this.entryTaxable(e) * (1 + e.gst_rate / 100); }

  addLine(): void {
    const e = this.entry();
    const err = this.validateEntry(e);
    if (err) {
      this.error.set(err);
      return;
    }
    this.error.set(null);
    this.lines.update((ls) => [...ls, { ...e }]);
    this.entry.set({ ...emptyEntry(), gst_rate: e.gst_rate });
    this.productQuery.set('');
    this.packSizeSearch.set('');
    this.persistDraft();
  }

  private validateEntry(e: Entry): string | null {
    if (!e.product_name.trim()) return 'Product name is required.';
    if (!e.pack_size?.trim()) return 'Pack size is required.';
    if (e.quantity <= 0) return 'Quantity must be greater than 0.';
    if (e.units_per_pack <= 0) return 'Units per pack must be greater than 0.';
    if (e.hsn_code.trim() && !isValidHsn(e.hsn_code)) return 'HSN must be 4 to 8 digits.';
    if (!e.batch_no.trim()) return 'Batch number is required.';
    if (!e.expiry_date?.trim()) return 'Expiry date is required.';
    if (e.mrp <= 0) return 'MRP must be greater than 0.';
    if (e.purchase_rate <= 0) return 'Purchase rate must be greater than 0.';
    if (e.purchase_rate > e.mrp) return 'Purchase rate cannot be greater than MRP.';
    if (![0, 5, 12, 18, 28].includes(Number(e.gst_rate))) return 'Select a valid GST % (0, 5, 12, 18, 28).';
    return null;
  }
  removeLine(i: number): void {
    this.lines.update((ls) => ls.filter((_, idx) => idx !== i));
    this.persistDraft();
  }

  // ---- totals ----
  readonly grossTotal = computed(() => this.lines().reduce((s, l) => s + l.purchase_rate * l.quantity, 0));
  readonly gstTotal = computed(() => this.lines().reduce((s, l) => s + this.entryTaxable(l) * (l.gst_rate / 100), 0));
  readonly itemsTotal = computed(() => this.lines().reduce((s, l) => s + this.entryTotal(l), 0));
  readonly taxableTotal = computed(() => this.lines().reduce((s, l) => s + this.entryTaxable(l), 0));
  readonly lineDiscTotal = computed(() =>
    this.lines().reduce((s, l) => s + this.lineDiscAmt(l), 0),
  );
  readonly cdAmountComputed = computed(() => {
    const items = this.itemsTotal();
    if (this.cdMode() === 'Amount') return Math.max(0, this.cdAmt());
    return Math.round(items * (this.cdPct() / 100) * 100) / 100;
  });
  readonly transportTotal = computed(() => {
    const base = Math.max(0, this.transportCharge());
    return Math.round(base * (1 + this.transportGstPct() / 100) * 100) / 100;
  });
  readonly billNetBeforeDebit = computed(() =>
    Math.max(0, this.itemsTotal() - this.cdAmountComputed()) + this.transportTotal(),
  );
  readonly netTotal = computed(() => Math.max(0, this.billNetBeforeDebit() - (this.debitDeductionAmt() || 0)));
  readonly amountInWords = computed(() => this.rupeesInWords(this.netTotal()));
  readonly isCredit = computed(() => this.cashCredit() === 'Credit');
  readonly isDueDateReadonly = computed(() => this.cashCredit() === 'Cash');

  private defaultDueDate(): string {
    const d = new Date();
    d.setDate(d.getDate() + 45);
    return d.toISOString().slice(0, 10);
  }

  onCashCreditChange(mode: string): void {
    const m = (['Cash', 'UPI', 'Cheque', 'Credit'].includes(mode) ? mode : 'Credit') as
      'Cash' | 'UPI' | 'Cheque' | 'Credit';
    this.cashCredit.set(m);
    if (m === 'Cash') {
      this.dueDate.set(this.invoiceDate());
    } else if (m !== 'Credit' && !this.dueDate()) {
      this.dueDate.set(this.invoiceDate());
    } else if (m === 'Credit' && !this.dueDate()) {
      this.dueDate.set(this.defaultDueDate());
    }
    this.persistDraft();
  }

  onCdModeChange(mode: string): void {
    const next = mode === 'Amount' ? 'Amount' : 'Percentage';
    this.cdMode.set(next);
    if (next === 'Amount') {
      const base = this.itemsTotal();
      if (this.netBillAmt() <= 0) {
        this.netBillAmt.set(Math.round(base * 100) / 100);
      }
      this.recalculateCdFromAmount();
    }
    this.persistDraft();
  }

  onCdPctChange(v: number): void {
    this.cdPct.set(+v || 0);
    if (this.cdMode() === 'Percentage') {
      this.cdAmt.set(this.cdAmountComputed());
    }
    this.persistDraft();
  }

  onCdAmtChange(v: number): void {
    this.cdAmt.set(+v || 0);
    this.recalculateCdFromAmount();
    this.persistDraft();
  }

  onNetBillAmtChange(v: number): void {
    this.netBillAmt.set(+v || 0);
    this.recalculateCdFromAmount();
    this.persistDraft();
  }

  /** Retail calculateCdPer: CD % = 100 × CD Amt / Net Bill. */
  private recalculateCdFromAmount(): void {
    if (this.cdMode() !== 'Amount') return;
    const base = this.netBillAmt();
    this.cdPct.set(base > 0 ? Math.round((this.cdAmt() / base) * 10000) / 100 : 0);
  }

  loadOpenDebitNotes(): void {
    const party = this.supplierName().trim();
    this.debitNoteNo.set('');
    this.debitDeductionAmt.set(0);
    this.openDebitNotes.set([]);
    if (!party) {
      return;
    }
    this.returnNoteSvc.openForParty(party).subscribe({
      next: (rows) => this.openDebitNotes.set(rows),
      error: () => this.openDebitNotes.set([]),
    });
  }

  onDebitNoteSelected(noteNo: string): void {
    this.debitNoteNo.set(noteNo);
    const found = this.openDebitNotes().find((n) => n.note_no === noteNo);
    this.debitDeductionAmt.set(found ? found.amount : 0);
    this.persistDraft();
  }

  private rupeesInWords(amount: number): string {
    const n = Math.round(amount);
    if (n <= 0) return 'Zero Rupees Only';
    const ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
      'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    const tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    const two = (x: number) => (x < 20 ? ones[x] : `${tens[Math.floor(x / 10)]}${ones[x % 10] ? ' ' + ones[x % 10] : ''}`.trim());
    const three = (x: number) => {
      const h = Math.floor(x / 100);
      const r = x % 100;
      return `${h ? ones[h] + ' Hundred' : ''}${h && r ? ' ' : ''}${r ? two(r) : ''}`.trim();
    };
    let rem = n;
    const crore = Math.floor(rem / 10000000); rem %= 10000000;
    const lakh = Math.floor(rem / 100000); rem %= 100000;
    const thousand = Math.floor(rem / 1000); rem %= 1000;
    const parts = [
      crore ? `${three(crore)} Crore` : '',
      lakh ? `${three(lakh)} Lakh` : '',
      thousand ? `${three(thousand)} Thousand` : '',
      rem ? three(rem) : '',
    ].filter(Boolean);
    return `${parts.join(' ')} Rupees Only`;
  }

  // ---- CSV import ----
  onCsvSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;
    this.purchaseSvc.importCsv(file).subscribe({
      next: (res) => {
        const imported: Entry[] = res.data.lines.map((l) => ({
          master_product_id: l.master_product_id,
          product_name: l.product_name,
          schedule_category: 'OTC',
          product_category: 'Allopathy',
          hsn_code: '',
          batch_no: l.batch_no ?? '',
          expiry_date: formatExpiryMonth(l.expiry_date ?? ''),
          shelf_location: '',
          pack_size: l.pack_size ?? '',
          quantity: l.quantity || 1,
          units_per_pack: l.units_per_pack || 1,
          free_quantity: l.free_quantity || 0,
          purchase_rate: l.purchase_rate || 0,
          mrp: l.mrp || 0,
          ptr: 0, pts: 0,
          gst_rate: l.gst_rate || 5,
          discount_pct: l.discount_pct || 0,
          discount_amt: Math.round((l.purchase_rate || 0) * (l.quantity || 1) * ((l.discount_pct || 0) / 100) * 100) / 100,
          scheme_pct: 0,
        }));
        if (imported.length) this.lines.update((ls) => [...ls, ...imported]);
        this.persistDraft();
        this.showToast(`${res.data.total_lines} lines imported (${res.data.matched} matched).`);
      },
      error: (err: HttpErrorResponse) => this.showToast(err.error?.message ?? 'Import failed.'),
    });
    input.value = '';
  }

  readonly soldEditLines = signal<SoldEditLine[] | null>(null);
  /** true = server refused the sold-stock edit; dialog explains instead of offering "Edit Anyway". */
  readonly soldEditBlocked = signal(false);

  cancelSoldEdit(): void {
    this.soldEditLines.set(null);
    this.soldEditBlocked.set(false);
  }

  confirmSoldEdit(): void {
    this.soldEditLines.set(null);
    this.save(true);
  }

  // ---- save ----
  save(confirmSoldEdit = false): void {
    if (!this.invoiceNo().trim()) { this.error.set('Invoice number is required.'); return; }
    if (this.invoiceWarning() && !this.isEditMode()) {
      this.error.set(this.invoiceWarning()!);
      return;
    }
    if (!this.supplierId() || !this.supplierName().trim()) {
      this.error.set('Please select a distributor (or add one in Distributor Master).');
      return;
    }
    if (this.lines().length === 0) { this.error.set('Add at least one product to the list.'); return; }
    for (let i = 0; i < this.lines().length; i++) {
      const lineErr = this.validateEntry(this.lines()[i]);
      if (lineErr) {
        this.error.set(`Line ${i + 1}: ${lineErr}`);
        return;
      }
    }
    this.saving.set(true);
    this.error.set(null);

    const items = this.buildItemsPayload();
    const payload = {
      invoice_no: this.invoiceNo(),
      invoice_date: this.invoiceDate(),
      supplier_id: this.supplierId(),
      supplier_name: this.supplierName(),
      cash_credit: this.cashCredit(),
      due_date: this.dueDate(),
      cd_mode: this.cdMode(),
      cd_pct: this.cdPct(),
      cd_amount: this.cdAmountComputed(),
      transport_charge: this.transportCharge(),
      transport_gst_pct: this.transportGstPct(),
      debit_note_no: this.debitNoteNo() || undefined,
      debit_deduction_amt: this.debitDeductionAmt(),
      ...(confirmSoldEdit ? { confirm_sold_edit: true } : {}),
      items,
    };

    const req = this.isEditMode()
      ? this.purchaseSvc.update(this.purchaseId()!, payload)
      : this.purchaseSvc.create(payload);

    req.subscribe({
      next: (res) => {
        this.saving.set(false);
        if (!this.isEditMode()) {
          this.draftSvc.clearAll();
        } else {
          this.showToast('Purchase updated successfully.');
        }
        const newId = !this.isEditMode() ? res.data?.id : null;
        void this.router.navigate(['/app/purchase'], {
          queryParams: newId ? { view: newId } : {},
        });
      },
      error: (err: HttpErrorResponse) => {
        this.saving.set(false);
        const soldLines = err.error?.errors?.sold_lines as SoldEditLine[] | undefined;
        if (err.status === 409 && err.error?.errors?.sold_edit_confirm) {
          this.soldEditBlocked.set(false);
          this.soldEditLines.set(soldLines ?? []);
          return;
        }
        if (err.status === 422 && soldLines?.length) {
          this.soldEditBlocked.set(true);
          this.soldEditLines.set(soldLines);
          return;
        }
        const errors = err.error?.errors as Record<string, string[]> | undefined;
        const first = errors ? (Object.values(errors)[0] as string[] | undefined)?.[0] : null;
        this.error.set(first ?? err.error?.message ?? (this.isEditMode() ? 'Could not update purchase.' : 'Could not save purchase.'));
      },
    });
  }

  private buildItemsPayload() {
    return this.lines().map((l) => {
      const base = {
        product_name: l.product_name,
        hsn_code: l.hsn_code,
        batch_no: l.batch_no,
        expiry_date: expiryForApiSave(l.expiry_date),
        shelf_location: l.shelf_location,
        pack_size: l.pack_size,
        quantity: l.quantity,
        units_per_pack: l.units_per_pack || 1,
        free_quantity: l.free_quantity,
        purchase_rate: l.purchase_rate,
        mrp: l.mrp,
        ptr: l.ptr,
        pts: l.pts,
        scheme_pct: l.scheme_pct,
        discount_pct: l.discount_pct,
        discount_amt: this.lineDiscAmt(l),
        gst_rate: l.gst_rate,
        ...(l.product_binding_id ? { product_binding_id: l.product_binding_id } : {}),
      };
      return l.master_product_id
        ? { ...base, master_product_id: l.master_product_id }
        : l.product_binding_id
          ? base
        : {
            ...base,
            new_product: {
              name: l.product_name, schedule_category: l.schedule_category,
              product_category: l.product_category,
              default_gst_rate: l.gst_rate, units_per_pack: l.units_per_pack || 1,
              shelf_location: l.shelf_location, hsn_code: l.hsn_code, pack_size: l.pack_size,
            },
          };
    });
  }

  private showToast(msg: string): void {
    this.toast.set(msg);
    setTimeout(() => this.toast.set(null), 3000);
  }
}
