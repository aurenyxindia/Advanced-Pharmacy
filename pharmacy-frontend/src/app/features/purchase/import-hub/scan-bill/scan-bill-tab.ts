import { ChangeDetectorRef, Component, ElementRef, HostListener, NgZone, OnDestroy, OnInit, ViewChild, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { Subscription, timeout } from 'rxjs';
import { finalize } from 'rxjs/operators';
import { CrudService } from '../../../../core/services/crud.service';
import { ImportDraftLine, ImportService } from '../../../../core/services/import.service';
import { MetaService } from '../../../../core/services/meta.service';
import { PurchasePayload, PurchaseService } from '../../../../core/services/purchase.service';
import { ReturnNoteService } from '../../../../core/services/return-note.service';
import { PurchaseDraftService } from '../../../../core/services/purchase-draft.service';
import { formatExpiryToYYYYMM } from '../upload-purchase/upload-purchase-csv';
import {
  PackSizeOption,
  findPackOption,
  resolvePackValue,
  unitsForPack,
} from '../pack-size.util';
import { compactDistributorAddress } from '../../../../core/utils/distributor-label';

type ScanDistOption = { id: number; name: string; state?: string | null; address?: string | null };

export interface ScanBillRow {
  product?: string;
  extracted_pack?: string;
  p_name?: string;
  hsn?: string;
  batch_no?: string;
  qty?: number;
  mrp?: number;
  purchase_rate?: number;
  ptr?: number | string;
  pts?: number | string;
  pack_size?: string;
  packSizeFromCsv?: string;
  scheme?: number;
  m_name?: string;
  m_nameCsv?: string;
  expiry_date?: string;
  totalAmt?: number;
  disc?: number;
  disc_amt?: number;
  prodCdAmt?: number;
  gst?: number;
  taxable?: number;
  gst_amt?: number;
  net_amt?: number;
  sgst?: number;
  cgst?: number;
  igst?: number;
  product_matched?: boolean;
  master_product_id?: number | null;
  matchedProducts?: { id: number; name: string; pack_size?: string | null; manufacturer?: string | null; default_gst_rate?: number; hsn_code?: string | null }[];
  selectedProduct?: { id: number; name: string; pack_size?: string | null; manufacturer?: string | null; default_gst_rate?: number; hsn_code?: string | null } | null;
  [key: string]: unknown;
}

@Component({
  selector: 'app-scan-bill-tab',
  imports: [CommonModule, FormsModule, RouterLink],
  templateUrl: './scan-bill-tab.html',
  styleUrl: './scan-bill-tab.scss',
})
export class ScanBillTab implements OnInit, OnDestroy {
  private readonly crud = inject(CrudService);
  private readonly returnNoteSvc = inject(ReturnNoteService);
  private readonly purchaseSvc = inject(PurchaseService);
  private readonly importSvc = inject(ImportService);
  private readonly meta = inject(MetaService);
  private readonly zone = inject(NgZone);
  private readonly cdr = inject(ChangeDetectorRef);
  readonly router = inject(Router);
  private readonly purchaseDraft = inject(PurchaseDraftService);

  @ViewChild('fileInput') fileInput?: ElementRef<HTMLInputElement>;
  @ViewChild('cameraInput') cameraInput?: ElementRef<HTMLInputElement>;

  readonly maxFileMb = 10;
  packSizeOptions: PackSizeOption[] = [];

  invoiceNo = '';
  invoiceDate = new Date().toISOString().slice(0, 10);
  distributorId: number | null = null;
  distributorName = '';
  distributorState = '';
  cashCredit = 'Credit';
  totalBillCd: 'Percentage' | 'Amount' = 'Percentage';
  netBillAmt = 0;
  cdAmt = 0;
  cdPer = 0;
  gstType: 'GST' | 'IGST' = 'GST';
  branchState = '';

  distributors: ScanDistOption[] = [];
  distributorsLoaded = false;
  distributorQuery = '';
  showDistributorDropdown = false;
  readonly distributorAddr = compactDistributorAddress;
  openDebitNotes: { note_no: string; label: string; amount: number }[] = [];
  selectedDebitNoteNo = '';
  debitDeductionAmt = 0;

  rows: ScanBillRow[] = [];
  selectedFileName = '';
  extracting = false;
  matching = false;
  saving = false;
  aiConfigured = true;
  aiMessage: string | null = null;
  error: string | null = null;
  toast: string | null = null;
  private matchEpoch = 0;
  private matchSub: Subscription | null = null;
  private saveSub: Subscription | null = null;
  private productSaveSub: Subscription | null = null;
  private extractSub: Subscription | null = null;

  showPtrModal = false;
  ptrMrp = 0;
  ptrMargin = 0;
  ptrGst = 5;
  ptrResult = 0;

  showAddProductModal = false;
  savingProduct = false;
  addProductError: string | null = null;
  addProductForRow: ScanBillRow | null = null;
  manufacturers: string[] = [];
  /** Searchable pack dropdown in Add Product modal. */
  addPackSearch = '';
  showAddPackDropdown = false;
  filteredAddPackSizes: PackSizeOption[] = [];
  private addPackBlurTimer?: ReturnType<typeof setTimeout>;
  readonly productTypes = ['Medicine', 'General', 'Food/Nutra', 'Cosmetics', 'Patanjali'];
  readonly schedules = ['OTC', 'H', 'H1', 'X', 'G', 'RESTRICTED'];
  readonly marginCategories = ['Allopathy', 'Ayurvedic', 'Surgical', 'General', 'Nutraceutical', 'Cosmetic'];
  readonly dosageForms = ['TABLET', 'CAPSULE', 'SYRUP', 'INJECTION', 'OINTMENT', 'DROPS', 'POWDER', 'CREAM', 'GEL', 'SUSPENSION'];
  addForm = {
    product_type: 'Medicine',
    name: '',
    schedule_category: 'OTC',
    product_category: 'Allopathy',
    pack_size: '',
    dosage_form: '',
    strength: '',
    mrp: 0,
    default_gst_rate: 5,
    hsn_code: '',
    packing_desc: '',
    manufacturer: '',
  };

  grossTotal = 0;
  totalDiscAmt = 0;
  totalCdAmt = 0;
  totalTaxable = 0;
  taxTotal = 0;
  billNet = 0;

  ngOnInit(): void {
    this.meta.loadPackSizes().subscribe({
      next: (list) => {
        this.packSizeOptions = list ?? [];
        this.cdr.markForCheck();
      },
      error: () => {
        this.packSizeOptions = [];
      },
    });
    this.importSvc.aiStatus().subscribe({
      next: (s) => {
        this.aiConfigured = !!s.configured;
        this.aiMessage = s.message ?? null;
      },
      error: () => {
        this.aiConfigured = false;
        this.aiMessage = 'Unable to check AI status.';
      },
    });
    this.crud.list<Record<string, unknown>>('distributors', { perPage: 5000 }).subscribe({
      next: (r) => {
        this.distributors = (r.data ?? []).map((d) => this.mapScanDist(d));
        this.distributorsLoaded = true;
        this.cdr.markForCheck();
      },
      error: () => {
        this.distributorsLoaded = true;
        this.cdr.markForCheck();
      },
    });
    this.crud.list<Record<string, unknown>>('manufacturers', { perPage: 300 }).subscribe({
      next: (r) => {
        this.manufacturers = (r.data ?? [])
          .map((m) => String(m['name'] ?? '').trim())
          .filter(Boolean)
          .sort((a, b) => a.localeCompare(b));
      },
      error: () => {
        this.manufacturers = [];
      },
    });
    this.tryRestoreImportRoundTrip();
  }

  private importResumePayload(): Record<string, unknown> {
    return {
      invoiceNo: this.invoiceNo,
      invoiceDate: this.invoiceDate,
      distributorId: this.distributorId,
      distributorName: this.distributorName,
      distributorState: this.distributorState,
      cashCredit: this.cashCredit,
      totalBillCd: this.totalBillCd,
      netBillAmt: this.netBillAmt,
      cdAmt: this.cdAmt,
      cdPer: this.cdPer,
      gstType: this.gstType,
      selectedDebitNoteNo: this.selectedDebitNoteNo,
      debitDeductionAmt: this.debitDeductionAmt,
      rows: this.rows,
      selectedFileName: this.selectedFileName,
    };
  }

  private applyImportResumePayload(p: Record<string, unknown>): void {
    if (typeof p['invoiceNo'] === 'string') this.invoiceNo = p['invoiceNo'];
    if (typeof p['invoiceDate'] === 'string') this.invoiceDate = p['invoiceDate'];
    if (p['distributorId'] != null) this.distributorId = Number(p['distributorId']) || null;
    if (typeof p['distributorName'] === 'string') this.distributorName = p['distributorName'];
    if (typeof p['distributorState'] === 'string') this.distributorState = p['distributorState'];
    if (typeof p['cashCredit'] === 'string') this.cashCredit = p['cashCredit'];
    if (p['totalBillCd'] === 'Percentage' || p['totalBillCd'] === 'Amount') this.totalBillCd = p['totalBillCd'];
    if (typeof p['netBillAmt'] === 'number') this.netBillAmt = p['netBillAmt'];
    if (typeof p['cdAmt'] === 'number') this.cdAmt = p['cdAmt'];
    if (typeof p['cdPer'] === 'number') this.cdPer = p['cdPer'];
    if (p['gstType'] === 'GST' || p['gstType'] === 'IGST') this.gstType = p['gstType'];
    if (typeof p['selectedDebitNoteNo'] === 'string') this.selectedDebitNoteNo = p['selectedDebitNoteNo'];
    if (typeof p['debitDeductionAmt'] === 'number') this.debitDeductionAmt = p['debitDeductionAmt'];
    if (Array.isArray(p['rows'])) this.rows = p['rows'] as ScanBillRow[];
    if (typeof p['selectedFileName'] === 'string') this.selectedFileName = p['selectedFileName'];
  }

  private tryRestoreImportRoundTrip(): void {
    const snap = this.purchaseDraft.consumeImportResume('scan');
    if (snap) {
      this.applyImportResumePayload(snap.payload);
    }
    const hs = this.purchaseDraft.consumeHandshake();
    if (hs?.createdSupplierId) {
      this.selectDistributorById(hs.createdSupplierId);
    }
  }

  goAddDistributor(ev?: Event): void {
    ev?.preventDefault();
    ev?.stopPropagation();
    this.showDistributorDropdown = false;
    const returnUrl = '/app/purchase/import?panel=scan';
    this.purchaseDraft.saveImportResume({
      source: 'scan',
      returnUrl,
      payload: this.importResumePayload(),
    });
    const name = (this.distributorQuery || this.distributorName).trim();
    void this.router.navigate(['/app/masters/distributors'], {
      queryParams: {
        returnUrl,
        create: '1',
        ...(name ? { name } : {}),
      },
    });
  }

  private mapScanDist(d: Record<string, unknown>): ScanDistOption {
    const addr = d['address'] != null ? String(d['address']).trim() : '';
    return {
      id: Number(d['id']),
      name: String(d['name'] ?? ''),
      state: d['state'] != null ? String(d['state']) : null,
      address: addr || null,
    };
  }

  private selectDistributorById(id: number): void {
    if (id <= 0) return;
    const found = this.distributors.find((d) => d.id === id);
    if (found) {
      this.pickDistributor(found);
      return;
    }
    this.crud.get<Record<string, unknown>>('distributors', id).subscribe({
      next: (row) => {
        const entry = this.mapScanDist(row);
        this.distributors = [...this.distributors, entry].sort((a, b) => a.name.localeCompare(b.name));
        this.pickDistributor(entry);
        this.cdr.markForCheck();
      },
    });
  }

  selectedDistributorAddr(): string {
    if (!this.distributorId) return '';
    const row = this.distributors.find((d) => d.id === this.distributorId);
    return compactDistributorAddress(row?.address);
  }

  rowPackValue(row: ScanBillRow): string {
    return resolvePackValue(this.packSizeOptions, String(row.pack_size || row.packSizeFromCsv || ''));
  }

  rowOrphanPack(row: ScanBillRow): string | null {
    const cur = String(row.pack_size || row.packSizeFromCsv || '').trim();
    if (!cur || findPackOption(this.packSizeOptions, cur)) return null;
    return cur;
  }

  formOrphanPack(): string | null {
    const cur = String(this.addForm.pack_size || '').trim();
    if (!cur || findPackOption(this.packSizeOptions, cur)) return null;
    return cur;
  }

  openAddPackDropdown(): void {
    this.showAddPackDropdown = true;
    this.filteredAddPackSizes = this.filterAddPackList(this.addPackSearch);
  }

  onAddPackSearchInput(q: string): void {
    this.addPackSearch = q ?? '';
    this.addForm.pack_size = this.addPackSearch.trim();
    this.showAddPackDropdown = true;
    this.filteredAddPackSizes = this.filterAddPackList(this.addPackSearch);
  }

  selectAddPack(p: PackSizeOption): void {
    this.addForm.pack_size = p.pack;
    this.addPackSearch = p.label || p.pack;
    this.showAddPackDropdown = false;
  }

  onAddPackBlur(): void {
    this.addPackBlurTimer = setTimeout(() => {
      this.showAddPackDropdown = false;
      const typed = this.addPackSearch.trim();
      if (typed) {
        const found = findPackOption(this.packSizeOptions, typed);
        this.addForm.pack_size = found?.pack ?? typed;
        this.addPackSearch = found ? (found.label || found.pack) : typed;
      } else {
        this.addForm.pack_size = '';
      }
      this.addPackBlurTimer = undefined;
      this.cdr.markForCheck();
    }, 180);
  }

  private filterAddPackList(q: string): PackSizeOption[] {
    const list = this.packSizeOptions;
    const s = String(q || '').trim().toLowerCase().replace(/\s+/g, '');
    if (!s) return list.slice(0, 80);
    return list
      .filter((p) => {
        const pack = (p.pack || '').toLowerCase().replace(/\s+/g, '');
        const label = (p.label || '').toLowerCase().replace(/\s+/g, '');
        return pack.includes(s) || label.includes(s);
      })
      .slice(0, 80);
  }

  onRowPackChange(row: ScanBillRow, pack: string): void {
    row.pack_size = resolvePackValue(this.packSizeOptions, pack) || pack;
  }

  private linePack(row: ScanBillRow): string {
    return (
      resolvePackValue(this.packSizeOptions, String(row.pack_size || row.packSizeFromCsv || '')) ||
      String(row.pack_size || row.packSizeFromCsv || '').trim()
    );
  }

  private formatApiError(err: unknown, fallback: string): string {
    const e = (err as { error?: { message?: string; errors?: Record<string, unknown> } })?.error;
    if (e?.errors && typeof e.errors === 'object') {
      const parts = Object.values(e.errors).flat().map(String).filter(Boolean);
      if (parts.length) return parts.join(' ');
    }
    return e?.message || fallback;
  }

  private validateRowsForSave(): string | null {
    for (let i = 0; i < this.rows.length; i++) {
      const r = this.rows[i];
      const n = i + 1;
      if (!r.master_product_id) return `Row ${n}: Match or Add product first.`;
      if (!this.linePack(r)) return `Row ${n}: Pack size is required.`;
      if (!String(r.batch_no || '').trim()) return `Row ${n}: Batch number is required.`;
      if (!String(r.expiry_date || '').trim()) return `Row ${n}: Expiry date is required.`;
      if (!(Number(r.qty) > 0)) return `Row ${n}: Quantity must be greater than 0.`;
      if (!(Number(r.mrp) > 0)) return `Row ${n}: MRP must be greater than 0.`;
      if (!(Number(r.purchase_rate) > 0)) return `Row ${n}: Purchase rate must be greater than 0.`;
    }
    return null;
  }

  ngOnDestroy(): void {
    this.cancelMatching();
    this.saveSub?.unsubscribe();
    this.productSaveSub?.unsubscribe();
    this.extractSub?.unsubscribe();
  }

  getFinalPayable(): number {
    return parseFloat(Math.max(0, (this.billNet || 0) - (this.debitDeductionAmt || 0)).toFixed(2));
  }

  onDistributorChange(): void {
    const d = this.distributors.find((x) => x.id === this.distributorId);
    this.distributorName = d?.name ?? '';
    this.distributorState = (d?.state ?? '').trim();
    if (this.branchState && this.distributorState) {
      this.gstType = this.distributorState.toUpperCase() === this.branchState.toUpperCase() ? 'GST' : 'IGST';
    } else {
      this.gstType = 'GST';
    }
    this.loadOpenDebitNotes();
    this.calculateAll();
  }

  filteredDistributors(): ScanDistOption[] {
    const q = this.distributorQuery.trim().toLowerCase();
    const list = this.distributors;
    if (!q) return list.slice(0, 200);
    return list
      .filter((d) => {
        const name = (d.name ?? '').toLowerCase();
        const addr = (d.address ?? '').toLowerCase();
        return name.includes(q) || addr.includes(q);
      })
      .slice(0, 200);
  }

  openDistributorDropdown(ev?: Event): void {
    ev?.stopPropagation();
    this.showDistributorDropdown = true;
    this.distributorQuery = '';
  }

  onDistributorQuery(q: string): void {
    this.distributorQuery = q ?? '';
    this.showDistributorDropdown = true;
  }

  pickDistributor(d: ScanDistOption): void {
    this.distributorId = d.id;
    this.distributorQuery = '';
    this.showDistributorDropdown = false;
    this.onDistributorChange();
  }

  clearDistributor(ev?: Event): void {
    ev?.stopPropagation();
    this.distributorId = null;
    this.distributorName = '';
    this.distributorState = '';
    this.distributorQuery = '';
    this.showDistributorDropdown = false;
    this.loadOpenDebitNotes();
    this.calculateAll();
  }

  @HostListener('document:click', ['$event'])
  onDocClickCloseMenus(ev: MouseEvent): void {
    const t = ev.target as HTMLElement | null;
    if (!t?.closest('.distributor-combo')) this.showDistributorDropdown = false;
    if (!t?.closest('.pack-select-wrap')) this.showAddPackDropdown = false;
  }

  loadOpenDebitNotes(): void {
    this.selectedDebitNoteNo = '';
    this.debitDeductionAmt = 0;
    this.openDebitNotes = [];
    const party = this.distributorName.trim();
    if (!party) return;
    this.returnNoteSvc.openForParty(party).subscribe({
      next: (rows) => {
        this.openDebitNotes = rows;
      },
      error: () => {
        this.openDebitNotes = [];
      },
    });
  }

  onDebitNoteSelected(): void {
    const found = this.openDebitNotes.find((n) => n.note_no === this.selectedDebitNoteNo);
    this.debitDeductionAmt = found ? found.amount : 0;
  }

  calculateCdPer(): void {
    if (this.totalBillCd !== 'Amount') return;
    const gross = Number(this.netBillAmt) || 0;
    const cd = Number(this.cdAmt) || 0;
    this.cdPer = gross > 0 ? parseFloat(((cd / gross) * 100).toFixed(2)) : 0;
    this.calculateAll();
  }

  triggerFilePicker(): void {
    this.fileInput?.nativeElement?.click();
  }

  triggerCamera(): void {
    this.cameraInput?.nativeElement?.click();
  }

  onFileSelected(ev: Event): void {
    const file = (ev.target as HTMLInputElement).files?.[0];
    (ev.target as HTMLInputElement).value = '';
    if (file) this.processBillFile(file);
  }

  onCameraSelected(ev: Event): void {
    const file = (ev.target as HTMLInputElement).files?.[0];
    (ev.target as HTMLInputElement).value = '';
    if (file) this.processBillFile(file);
  }

  private processBillFile(file: File): void {
    this.error = null;
    this.toast = null;
    if (!this.aiConfigured) {
      this.error = this.aiMessage || 'AI is not configured on the server.';
      return;
    }
    const maxBytes = this.maxFileMb * 1024 * 1024;
    if (file.size > maxBytes) {
      this.error = `File too large. Max ${this.maxFileMb} MB.`;
      return;
    }
    const ok = /\.(pdf|png|jpe?g|webp)$/i.test(file.name) || /^(application\/pdf|image\/)/i.test(file.type);
    if (!ok) {
      this.error = 'Use PDF, PNG, JPG or WEBP.';
      return;
    }

    this.cancelMatching();
    this.extractSub?.unsubscribe();
    this.selectedFileName = file.name;
    this.extracting = true;
    this.rows = [];

    this.extractSub = this.importSvc.extractAi(file).pipe(
      finalize(() => {
        this.extracting = false;
        this.cdr.markForCheck();
      }),
    ).subscribe({
      next: (draft) => {
        if (draft.header?.invoice_no) this.invoiceNo = String(draft.header.invoice_no);
        if (draft.header?.invoice_date) {
          const d = String(draft.header.invoice_date).slice(0, 10);
          if (/^\d{4}-\d{2}-\d{2}$/.test(d)) this.invoiceDate = d;
        }
        if (draft.header?.supplier_name) {
          const name = String(draft.header.supplier_name).trim();
          const found = this.distributors.find((d) => d.name.toLowerCase() === name.toLowerCase())
            || this.distributors.find((d) => d.name.toLowerCase().includes(name.toLowerCase()) || name.toLowerCase().includes(d.name.toLowerCase()));
          if (found) {
            this.distributorId = found.id;
            this.onDistributorChange();
          }
        }
        this.rows = this.buildRowsFromAi(draft.lines ?? []);
        this.calculateAll();
        this.toast = `Extracted ${this.rows.length} product(s). Click “Match with Product Master” when ready.`;
        this.cdr.markForCheck();
        setTimeout(() => {
          this.toast = null;
          this.cdr.markForCheck();
        }, 4000);
      },
      error: (err) => {
        this.error = err?.error?.message || err?.message || 'AI extraction failed.';
        this.cdr.markForCheck();
      },
    });
  }

  /** Retail: AI rows start unmatched — Match with Product Master is explicit. */
  private buildRowsFromAi(lines: ImportDraftLine[]): ScanBillRow[] {
    return lines.map((l) => {
      const qty = Number(l.quantity) || 0;
      const rate = Number(l.purchase_rate) || 0;
      const pack = String(l.pack_size || l.pack || l.pack_raw || '');
      const resolvedPack = resolvePackValue(this.packSizeOptions, pack) || pack;
      const extracted = String(l.product_name || '').trim();
      const expRaw = String(l.expiry_date || '');
      const expiry = expRaw ? formatExpiryToYYYYMM(expRaw) || expRaw.slice(0, 7) : '';
      const totalAmt = parseFloat((qty * rate).toFixed(2));
      const discPct = Number(l.discount_pct) || 0;
      const discAmt = Number(l.discount_amt) || 0;
      return {
        product: extracted,
        extracted_pack: pack,
        p_name: '',
        hsn: String(l.hsn_code || ''),
        batch_no: String(l.batch_no || ''),
        qty,
        mrp: Number(l.mrp) || 0,
        purchase_rate: rate,
        ptr: l.ptr ?? '',
        pts: l.pts ?? '',
        pack_size: resolvedPack,
        packSizeFromCsv: pack,
        scheme: Number(l.free_quantity) || 0,
        m_name: '',
        m_nameCsv: String(l.mfg_name || l.manufacturer_short || ''),
        expiry_date: expiry,
        totalAmt,
        disc: discPct,
        disc_amt: discAmt,
        prodCdAmt: 0,
        gst: Number(l.gst_rate) || 5,
        taxable: totalAmt,
        gst_amt: 0,
        net_amt: totalAmt,
        sgst: 0,
        cgst: 0,
        igst: 0,
        product_matched: false,
        master_product_id: null,
        matchedProducts: [],
        selectedProduct: null,
      } as ScanBillRow;
    });
  }

  /** Retail “Match with Product Master” — deferred batch match. */
  startMatchingWithMaster(): void {
    if (!this.rows.length) {
      this.error = 'Extract a bill first.';
      return;
    }
    this.error = null;
    this.cancelMatching();
    const epoch = ++this.matchEpoch;
    this.matching = true;

    const names = this.rows.map((r) => String(r.product || '').trim());
    this.matchSub = this.importSvc.matchByNames(names, 3).subscribe({
      next: (byName) => {
        if (epoch !== this.matchEpoch) return;
        for (const row of this.rows) {
          const key = String(row.product || '').trim().toLowerCase();
          const matches = key ? (byName[key] ?? []) : [];
          if (matches.length) {
            row.matchedProducts = matches;
            row.selectedProduct = matches[0];
            this.applyMatchedProduct(row, matches[0]);
            row.product_matched = true;
          } else {
            row.matchedProducts = [];
            row.selectedProduct = null;
            row.product_matched = false;
            row.master_product_id = null;
            row.p_name = '';
          }
        }
        this.matching = false;
        this.calculateAll();
        const ok = this.rows.filter((r) => r.product_matched).length;
        this.toast = `Matched ${ok} of ${this.rows.length} products.`;
        this.cdr.markForCheck();
        setTimeout(() => {
          this.toast = null;
          this.cdr.markForCheck();
        }, 3000);
      },
      error: () => {
        if (epoch !== this.matchEpoch) return;
        this.matching = false;
        this.error = 'Product matching failed. Try again.';
        this.cdr.markForCheck();
      },
    });
  }

  clearBill(): void {
    this.cancelMatching();
    this.extractSub?.unsubscribe();
    this.rows = [];
    this.selectedFileName = '';
    this.error = null;
    this.calculateTotals();
  }

  /** Abort in-flight match HTTP so Save Product is never stuck behind the queue. */
  private cancelMatching(): void {
    this.matchEpoch++;
    this.matching = false;
    if (this.matchSub) {
      this.matchSub.unsubscribe();
      this.matchSub = null;
    }
  }

  applyMatchedProduct(row: ScanBillRow, product: NonNullable<ScanBillRow['selectedProduct']>): void {
    if (!product) return;
    row.master_product_id = product.id;
    row.p_name = product.name;
    const rawPack = product.pack_size || row.packSizeFromCsv || row.pack_size || '';
    row.pack_size = resolvePackValue(this.packSizeOptions, String(rawPack)) || String(rawPack);
    row.m_name = product.manufacturer || row.m_nameCsv || row.m_name;
    if (!row.hsn && product.hsn_code) row.hsn = product.hsn_code;
    if ((!row.gst || row.gst === 0) && product.default_gst_rate) {
      row.gst = Math.round(product.default_gst_rate);
    }
  }

  onProductSelect(row: ScanBillRow, productId: number): void {
    const product = row.matchedProducts?.find((m) => m.id === productId) ?? null;
    row.selectedProduct = product;
    if (product) this.applyMatchedProduct(row, product);
  }

  calculateTaxable(row: ScanBillRow): void {
    const disc = parseFloat(String(row.disc ?? 0)) || 0;
    const qty = parseFloat(String(row.qty ?? 0)) || 0;
    const rate = parseFloat(String(row.purchase_rate ?? 0)) || 0;
    const cdPer = parseFloat(String(this.cdPer)) || 0;

    row.totalAmt = parseFloat((qty * rate).toFixed(2));
    row.disc_amt = parseFloat(((row.totalAmt * disc) / 100).toFixed(2));
    row.prodCdAmt = parseFloat((((row.totalAmt - row.disc_amt) * cdPer) / 100).toFixed(2));
    row.taxable = parseFloat((row.totalAmt - row.disc_amt - row.prodCdAmt).toFixed(2));

    const taxable = row.taxable || 0;
    const gst = parseFloat(String(row.gst ?? 0)) || 0;
    if (this.gstType === 'GST') {
      row.sgst = parseFloat((taxable * (gst / (100 * 2))).toFixed(2));
      row.cgst = parseFloat((taxable * (gst / (100 * 2))).toFixed(2));
      row.igst = 0;
    } else {
      row.sgst = 0;
      row.cgst = 0;
      row.igst = parseFloat((taxable * (gst / 100)).toFixed(2));
    }
    row.gst_amt = parseFloat(((row.sgst || 0) + (row.cgst || 0) + (row.igst || 0)).toFixed(2));
    row.net_amt = parseFloat((taxable + row.gst_amt).toFixed(2));
    this.calculateTotals();
  }

  calculateAll(): void {
    for (const row of this.rows) {
      const qty = parseFloat(String(row.qty ?? 0)) || 0;
      const rate = parseFloat(String(row.purchase_rate ?? 0)) || 0;
      if (qty > 0 && rate > 0 && row.disc_amt != null && row.disc_amt !== ('' as unknown as number)) {
        if (row.disc == null || row.disc === 0) {
          row.disc = parseFloat((((100 * Number(row.disc_amt)) / (qty * rate))).toFixed(2));
        }
      }
      this.calculateTaxable(row);
    }
  }

  calculateDiscPer(row: ScanBillRow): void {
    const qty = parseFloat(String(row.qty ?? 0)) || 0;
    const rate = parseFloat(String(row.purchase_rate ?? 0)) || 0;
    if (qty > 0 && rate > 0) {
      row.disc = parseFloat((((100 * Number(row.disc_amt || 0)) / (qty * rate))).toFixed(2));
    }
    this.calculateTaxable(row);
  }

  calculateTotals(): void {
    let gross = 0, disc = 0, cd = 0, taxable = 0, tax = 0, net = 0;
    for (const d of this.rows) {
      gross += Number(d.totalAmt) || 0;
      disc += Number(d.disc_amt) || 0;
      cd += Number(d.prodCdAmt) || 0;
      taxable += Number(d.taxable) || 0;
      tax += Number(d.gst_amt) || 0;
      net += Number(d.net_amt) || 0;
    }
    this.grossTotal = parseFloat(gross.toFixed(2));
    this.totalDiscAmt = parseFloat(disc.toFixed(2));
    this.totalCdAmt = parseFloat(cd.toFixed(2));
    this.totalTaxable = parseFloat(taxable.toFixed(2));
    this.taxTotal = parseFloat(tax.toFixed(2));
    this.billNet = parseFloat(net.toFixed(2));
  }

  deleteRow(index: number): void {
    this.rows.splice(index, 1);
    this.calculateAll();
  }

  openPtrModal(): void {
    this.showPtrModal = true;
    this.ptrResult = 0;
  }

  closePtrModal(): void {
    this.showPtrModal = false;
  }

  calcPtrPts(): void {
    const mrp = Number(this.ptrMrp) || 0;
    const margin = Number(this.ptrMargin) || 0;
    const gst = Number(this.ptrGst) || 0;
    const base = gst > 0 ? mrp / (1 + gst / 100) : mrp;
    this.ptrResult = parseFloat(Math.max(0, base - margin).toFixed(2));
  }

  openAddProductModal(item: ScanBillRow): void {
    this.cancelMatching();
    this.productSaveSub?.unsubscribe();
    this.productSaveSub = null;
    this.savingProduct = false;
    this.addProductForRow = item;
    this.addProductError = null;
    this.addForm = {
      product_type: 'Medicine',
      name: String(item.product || '').toUpperCase(),
      schedule_category: 'OTC',
      product_category: 'Allopathy',
      pack_size: resolvePackValue(
        this.packSizeOptions,
        String(item.packSizeFromCsv || item.pack_size || ''),
      ),
      dosage_form: '',
      strength: '',
      mrp: Number(item.mrp) || 0,
      default_gst_rate: Number(item.gst) || 5,
      hsn_code: String(item.hsn || ''),
      packing_desc: '',
      manufacturer: String(item.m_nameCsv || item.m_name || ''),
    };
    const found = findPackOption(this.packSizeOptions, this.addForm.pack_size);
    this.addPackSearch = found ? (found.label || found.pack) : this.addForm.pack_size;
    this.showAddPackDropdown = false;
    this.filteredAddPackSizes = this.filterAddPackList(this.addPackSearch);
    this.showAddProductModal = true;
  }

  closeAddProductModal(cancelInFlight = true): void {
    if (cancelInFlight) {
      this.productSaveSub?.unsubscribe();
      this.productSaveSub = null;
    }
    this.showAddProductModal = false;
    this.addProductForRow = null;
    this.addProductError = null;
    this.savingProduct = false;
    this.showAddPackDropdown = false;
    if (this.addPackBlurTimer) {
      clearTimeout(this.addPackBlurTimer);
      this.addPackBlurTimer = undefined;
    }
    this.cdr.markForCheck();
  }

  /** Backdrop / outside click closes only — never saves. */
  onAddProductBackdrop(ev: Event): void {
    ev.preventDefault();
    ev.stopPropagation();
    if (this.savingProduct) return;
    this.closeAddProductModal();
  }

  saveProductFromModal(): void {
    this.addProductError = null;
    const name = this.addForm.name.trim().toUpperCase();
    const pack = resolvePackValue(this.packSizeOptions, this.addForm.pack_size.trim()) || this.addForm.pack_size.trim();
    if (!name) {
      this.addProductError = 'Product Name is required.';
      return;
    }
    if (!pack) {
      this.addProductError = 'Pack Size is required.';
      return;
    }
    if (!(Number(this.addForm.mrp) >= 0)) {
      this.addProductError = 'MRP is required.';
      return;
    }
    const type = this.addForm.product_type;
    const schedule = type === 'Medicine' ? this.addForm.schedule_category : 'OTC';
    if (type === 'Medicine' && !schedule) {
      this.addProductError = 'Schedule Category is required.';
      return;
    }

    const payload: Record<string, unknown> = {
      product_type: type,
      name,
      pack_size: pack,
      mrp: Number(this.addForm.mrp) || 0,
      schedule_category: schedule,
      default_gst_rate: Number(this.addForm.default_gst_rate) || 0,
      units_per_pack: this.unitsFromPack(pack),
      is_active: 1,
    };
    if (this.addForm.product_category) payload['product_category'] = this.addForm.product_category;
    if (this.addForm.dosage_form.trim()) payload['dosage_form'] = this.addForm.dosage_form.trim().toUpperCase();
    if (this.addForm.strength.trim()) payload['strength'] = this.addForm.strength.trim().toUpperCase();
    if (this.addForm.packing_desc.trim()) payload['packing_desc'] = this.addForm.packing_desc.trim().toUpperCase();
    if (this.addForm.hsn_code.trim()) payload['hsn_code'] = this.addForm.hsn_code.trim();
    if (type !== 'General' && this.addForm.manufacturer.trim()) {
      payload['manufacturer'] = this.addForm.manufacturer.trim();
    }

    this.cancelMatching();
    this.productSaveSub?.unsubscribe();
    this.savingProduct = true;
    this.addProductError = null;
    this.cdr.markForCheck();

    this.productSaveSub = this.crud.create<{ id: number }>('products', payload).pipe(
      timeout({ first: 25_000 }),
      finalize(() => {
        this.zone.run(() => {
          this.savingProduct = false;
          this.cdr.markForCheck();
        });
      }),
    ).subscribe({
      next: (res) => {
        this.zone.run(() => {
          const id = Number(res.data?.id ?? 0);
          const sourceRow = this.addProductForRow;
          const csvKey = sourceRow
            ? this.normProductName(String(sourceRow.product || name))
            : this.normProductName(name);

          this.closeAddProductModal(false);

          if (!id) {
            this.toast = 'Product added successfully.';
            this.cdr.markForCheck();
            setTimeout(() => (this.toast = null), 2500);
            return;
          }

          const matched = {
            id,
            name,
            pack_size: pack,
            manufacturer: (payload['manufacturer'] as string) || null,
            default_gst_rate: Number(payload['default_gst_rate']) || 0,
            hsn_code: (payload['hsn_code'] as string) || null,
          };

          let applied = 0;
          for (const row of this.rows) {
            const rowKey = this.normProductName(String(row.product || row.p_name || ''));
            if (rowKey !== csvKey) continue;
            row.matchedProducts = [matched];
            row.selectedProduct = matched;
            this.applyMatchedProduct(row, matched);
            row.product_matched = true;
            if (!row.hsn && matched.hsn_code) row.hsn = matched.hsn_code;
            if ((!row.gst || Number(row.gst) === 0) && matched.default_gst_rate) {
              row.gst = Math.round(matched.default_gst_rate);
            }
            applied++;
          }
          this.calculateAll();
          this.toast = applied > 1
            ? `Product added — matched ${applied} rows with the same name.`
            : 'Product added successfully.';
          this.cdr.markForCheck();
          setTimeout(() => (this.toast = null), 2800);
        });
      },
      error: (err) => {
        this.zone.run(() => {
          if (err?.name === 'TimeoutError') {
            this.addProductError = 'Save timed out. Refresh and check if the product was added, then rematch.';
          } else {
            const msg = err?.error?.message
              || (err?.error?.errors ? Object.values(err.error.errors as Record<string, unknown>).flat().join(' ') : null)
              || 'Could not add product.';
            this.addProductError = String(msg);
          }
          this.cdr.markForCheck();
        });
      },
    });
  }

  /** Normalize CSV product name for same-name batch matching. */
  private normProductName(s: string): string {
    return String(s || '').toLowerCase().replace(/\s+/g, ' ').trim();
  }

  save(): void {
    this.error = null;
    this.toast = null;
    if (!this.invoiceNo.trim()) {
      this.error = 'Invoice No is required.';
      return;
    }
    if (!this.invoiceDate) {
      this.error = 'Invoice Date is required.';
      return;
    }
    if (!this.distributorId) {
      this.error = 'Distributor is required.';
      return;
    }
    if (!this.rows.length) {
      this.error = 'At least 1 product is required.';
      return;
    }
    const invalid = this.validateRowsForSave();
    if (invalid) {
      this.error = invalid;
      this.cdr.markForCheck();
      return;
    }

    for (const r of this.rows) {
      r.pack_size = this.linePack(r);
    }

    const payload: PurchasePayload = {
      invoice_no: this.invoiceNo.trim(),
      invoice_date: this.invoiceDate,
      supplier_id: this.distributorId,
      supplier_name: this.distributorName,
      cash_credit: this.cashCredit,
      cd_mode: this.totalBillCd,
      cd_pct: Number(this.cdPer) || 0,
      cd_amount: this.totalBillCd === 'Amount' ? Number(this.cdAmt) || 0 : undefined,
      debit_note_no: this.selectedDebitNoteNo || undefined,
      debit_deduction_amt: this.debitDeductionAmt || 0,
      bill_discount_pct: 0,
      items: this.rows.map((r) => ({
        master_product_id: r.master_product_id || undefined,
        product_name: String(r.p_name || r.product || ''),
        hsn_code: r.hsn || null,
        batch_no: String(r.batch_no || ''),
        expiry_date: this.expiryForSave(String(r.expiry_date || '')),
        quantity: Number(r.qty) || 0,
        free_quantity: Number(r.scheme) || 0,
        purchase_rate: Number(r.purchase_rate) || 0,
        mrp: Number(r.mrp) || 0,
        ptr: Number(r.ptr) || undefined,
        pts: Number(r.pts) || undefined,
        discount_pct: Number(r.disc) || 0,
        gst_rate: Number(r.gst) || 0,
        pack_size: this.linePack(r),
        units_per_pack: unitsForPack(this.packSizeOptions, this.linePack(r), this.unitsFromPack(this.linePack(r))),
      })),
    };

    this.saving = true;
    this.cancelMatching();
    this.saveSub?.unsubscribe();
    this.saveSub = this.purchaseSvc.create(payload).pipe(
      timeout({ first: 90_000 }),
      finalize(() => {
        this.zone.run(() => {
          this.saving = false;
          this.cdr.markForCheck();
        });
      }),
    ).subscribe({
      next: (res) => {
        this.zone.run(() => {
          this.toast = `Purchase saved. Net ₹${Number(res.data?.net_amount ?? this.getFinalPayable()).toFixed(2)}`;
          this.rows = [];
          this.selectedFileName = '';
          this.invoiceNo = '';
          this.selectedDebitNoteNo = '';
          this.debitDeductionAmt = 0;
          this.calculateTotals();
          this.cdr.markForCheck();
          setTimeout(() => {
            const m = (this.invoiceDate || '').slice(0, 7);
            const q = /^\d{4}-\d{2}$/.test(m) ? `?month=${m}` : '?month=all';
            this.router.navigateByUrl('/app/purchase' + q);
          }, 800);
        });
      },
      error: (err) => {
        this.zone.run(() => {
          if (err?.name === 'TimeoutError') {
            this.error = 'Save timed out. Check Purchases list — the bill may still have saved.';
          } else {
            this.error = this.formatApiError(err, 'Purchase bill not saved.');
          }
          this.cdr.markForCheck();
        });
      },
    });
  }

  private expiryForSave(ym: string): string {
    const s = ym.trim();
    if (/^\d{4}-\d{2}$/.test(s)) return `${s}-01`;
    if (/^\d{4}-\d{2}-\d{2}$/.test(s)) return s;
    return s;
  }

  private unitsFromPack(pack: string): number {
    const s = pack.trim().toLowerCase().replace(/\s+/g, '');
    if (!s) return 1;
    // Bottle/tube volume or weight is ONE saleable unit (60ml ≠ 60 tablets).
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
}
