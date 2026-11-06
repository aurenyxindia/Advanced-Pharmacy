import { Component, HostListener, OnDestroy, OnInit, computed, inject, signal } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { Subscription, finalize, timeout } from 'rxjs';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import {
  GmailAttachment,
  IMPORT_MAX_UPLOAD_MB,
  ImportDraftLine,
  ImportFormat,
  ImportService,
} from '../../../core/services/import.service';
import { PurchaseService } from '../../../core/services/purchase.service';
import { CrudService } from '../../../core/services/crud.service';
import { MetaService } from '../../../core/services/meta.service';
import { ApiResponse } from '../../../core/models/auth.models';
import { toUpper } from '../../../core/utils/input-format';
import { compactDistributorAddress } from '../../../core/utils/distributor-label';
import { UploadPurchaseTab } from './upload-purchase/upload-purchase-tab';
import { ScanBillTab } from './scan-bill/scan-bill-tab';
import {
  PackSizeOption,
  findPackOption,
  resolvePackValue,
  unitsForPack,
} from './pack-size.util';
import { PurchaseDraftService } from '../../../core/services/purchase-draft.service';

type Panel = 'upload' | 'scan' | 'ai' | 'csv' | 'email';
type Step = 'source' | 'verify';
type DistOption = { id: number; name: string; address?: string | null };

@Component({
  selector: 'app-import-hub',
  imports: [CommonModule, FormsModule, RouterLink, UploadPurchaseTab, ScanBillTab],
  templateUrl: './import-hub.html',
  styleUrl: './import-hub.scss',
})
export class ImportHub implements OnInit, OnDestroy {
  private readonly importSvc = inject(ImportService);
  private readonly purchaseSvc = inject(PurchaseService);
  private readonly crud = inject(CrudService);
  private readonly meta = inject(MetaService);
  private readonly purchaseDraft = inject(PurchaseDraftService);
  private readonly route = inject(ActivatedRoute);
  readonly router = inject(Router);

  readonly panel = signal<Panel>('upload');
  readonly step = signal<Step>('source');
  readonly packSizeOptions = signal<PackSizeOption[]>([]);

  readonly formats = signal<ImportFormat[]>([]);
  readonly format = signal('Auto');
  readonly busy = signal(false);
  readonly busyLabel = signal('Working…');
  readonly error = signal<string | null>(null);
  readonly toast = signal<string | null>(null);
  readonly maxUploadMb = IMPORT_MAX_UPLOAD_MB;

  readonly aiConfigured = signal(true);
  readonly aiMessage = signal<string | null>(null);
  readonly gmailConfigured = signal(true);
  readonly gmailLinked = signal(false);
  readonly gmailEmail = signal<string | null>(null);
  readonly gmailMessage = signal<string | null>(null);
  readonly attachments = signal<GmailAttachment[]>([]);

  readonly lines = signal<ImportDraftLine[]>([]);
  readonly invoiceNo = signal('');
  readonly invoiceDate = signal(new Date().toISOString().slice(0, 10));
  readonly supplierId = signal<number | null>(null);
  readonly supplierName = signal('');
  readonly supplierQuery = signal('');
  readonly suppliers = signal<DistOption[]>([]);
  readonly showSupplierDropdown = signal(false);
  readonly distributorAddr = compactDistributorAddress;
  readonly invoiceDuplicate = signal(false);
  readonly duplicateInfo = signal<string | null>(null);
  /** Bill-level cash discount % (retail CD) applied to each line. */
  readonly billCdPct = signal(0);

  readonly matchRow = signal<number | null>(null);
  readonly matchQuery = signal('');
  readonly matchHits = signal<{
    id: number;
    name: string;
    manufacturer: string | null;
    default_gst_rate: number;
    hsn_code?: string | null;
  }[]>([]);

  readonly saving = signal(false);
  private invoiceCheckTimer: ReturnType<typeof setTimeout> | null = null;
  private productSaveSub: Subscription | null = null;
  private addPackBlurTimer?: ReturnType<typeof setTimeout>;

  showAddProductModal = false;
  savingProduct = false;
  addProductError: string | null = null;
  addProductForIndex: number | null = null;
  manufacturers: string[] = [];
  showAddPackDropdown = false;
  addPackSearch = '';
  filteredAddPackSizes: PackSizeOption[] = [];
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

  readonly filteredSuppliers = computed(() => {
    const q = this.supplierQuery().trim().toLowerCase();
    const all = this.suppliers();
    if (!q) return all.slice(0, 40);
    return all
      .filter((s) => {
        const name = (s.name ?? '').toLowerCase();
        const addr = (s.address ?? '').toLowerCase();
        return name.includes(q) || addr.includes(q);
      })
      .slice(0, 40);
  });

  readonly selectedSupplierAddr = computed(() => {
    const id = this.supplierId();
    if (!id) return '';
    const row = this.suppliers().find((s) => s.id === id);
    return compactDistributorAddress(row?.address);
  });

  readonly matchedCount = computed(() => this.lines().filter((l) => !!l.master_product_id).length);
  readonly unmatchedCount = computed(() => this.lines().filter((l) => !l.master_product_id).length);

  /** Parse meta shown as Import Report on verify. */
  readonly importReport = signal<{
    filename: string;
    format: string;
    formatRequested: string;
    aiFallback: boolean;
    aiError: string | null;
  } | null>(null);
  readonly csvFileName = signal<string | null>(null);
  readonly lineFilter = signal<'all' | 'unmatched' | 'matched'>('all');

  readonly visibleLines = computed(() => {
    const all = this.lines();
    const f = this.lineFilter();
    if (f === 'unmatched') return all.map((l, i) => ({ l, i })).filter((x) => !x.l.master_product_id);
    if (f === 'matched') return all.map((l, i) => ({ l, i })).filter((x) => !!x.l.master_product_id);
    return all.map((l, i) => ({ l, i }));
  });

  readonly billTotals = computed(() => {
    let gross = 0, disc = 0, cd = 0, taxable = 0, gst = 0, net = 0;
    for (const l of this.lines()) {
      gross += Number(l.gross_amount) || 0;
      disc += Number(l.discount_amt) || 0;
      cd += Number(l.cd_amt) || 0;
      taxable += Number(l.taxable_amount) || 0;
      gst += Number(l.gst_amount) || 0;
      net += Number(l.net_amount) || 0;
    }
    const r = (n: number) => Math.round(n * 100) / 100;
    return { gross: r(gross), disc: r(disc), cd: r(cd), taxable: r(taxable), gst: r(gst), net: r(net) };
  });

  readonly checks = computed(() => {
    const supplierOk = !!(this.supplierId() || this.supplierName().trim());
    const invoiceOk = this.invoiceNo().trim().length > 0 && !!this.invoiceDate();
    const linesOk = this.lines().length > 0;
    const allMatched = linesOk && this.unmatchedCount() === 0;
    const qtyOk = this.lines().every((l) => (l.quantity ?? 0) >= 1);
    const ratesOk = this.lines().every((l) => (l.purchase_rate ?? 0) >= 0 && (l.mrp ?? 0) >= 0);
    const noDup = !this.invoiceDuplicate();
    return {
      supplierOk,
      invoiceOk,
      linesOk,
      allMatched,
      qtyOk,
      ratesOk,
      noDup,
      canSave: supplierOk && invoiceOk && linesOk && allMatched && qtyOk && ratesOk && noDup,
    };
  });

  ngOnInit(): void {
    const qPanel = this.route.snapshot.queryParamMap.get('panel');
    if (qPanel === 'upload' || qPanel === 'scan' || qPanel === 'ai' || qPanel === 'csv' || qPanel === 'email') {
      this.panel.set(qPanel);
    }
    const qStep = this.route.snapshot.queryParamMap.get('step');
    if (qStep === 'verify') {
      this.step.set('verify');
    }

    const snap = this.purchaseDraft.consumeImportResume('hub');
    if (snap) {
      this.applyHubImportResume(snap.payload);
      const hs = this.purchaseDraft.consumeHandshake();
      if (hs?.createdSupplierId) {
        this.selectSupplierById(hs.createdSupplierId);
      }
    }
  }

  constructor() {
    this.meta.loadPackSizes().subscribe({
      next: (list) => this.packSizeOptions.set(list ?? []),
      error: () => this.packSizeOptions.set([]),
    });
    this.importSvc.formats().subscribe({
      next: (f) => this.formats.set(f),
      error: () => this.formats.set([{ key: 'Generic', label: 'Generic (header aliases)' }]),
    });
    this.crud.list<Record<string, unknown>>('manufacturers', { perPage: 500 }).subscribe({
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
    this.crud.list<{ id: number; name: string; address?: string | null }>('distributors', { perPage: 5000 }).subscribe({
      next: (r) => {
        const byName = new Map<string, DistOption>();
        for (const row of r.data ?? []) {
          const key = String(row.name ?? '').trim().toUpperCase();
          if (!key) continue;
          const entry: DistOption = {
            id: Number(row.id),
            name: String(row.name ?? ''),
            address: row.address != null && String(row.address).trim() !== '' ? String(row.address) : null,
          };
          const prev = byName.get(key);
          if (!prev || entry.id < prev.id) byName.set(key, entry);
        }
        this.suppliers.set(
          Array.from(byName.values()).sort((a, b) =>
            a.name.localeCompare(b.name, undefined, { sensitivity: 'base' }),
          ),
        );
      },
      error: () => this.suppliers.set([]),
    });
    this.refreshAiStatus();
    this.refreshGmailStatus();

    window.addEventListener('message', this.onOauthMessage);
  }

  ngOnDestroy(): void {
    window.removeEventListener('message', this.onOauthMessage);
    this.productSaveSub?.unsubscribe();
    if (this.addPackBlurTimer) clearTimeout(this.addPackBlurTimer);
  }

  private readonly onOauthMessage = (ev: MessageEvent): void => {
    if (ev?.data?.type === 'gmail_oauth_done') {
      this.refreshGmailStatus();
      this.loadAttachments();
      this.toast.set('Gmail connected.');
    }
  };

  setPanel(p: Panel): void {
    this.panel.set(p);
    this.error.set(null);
    if (p === 'email') {
      this.refreshGmailStatus(true);
    }
    if (p === 'ai' || p === 'scan') this.refreshAiStatus();
  }

  refreshAiStatus(): void {
    this.importSvc.aiStatus().subscribe({
      next: (s) => {
        this.aiConfigured.set(!!s.configured);
        this.aiMessage.set(s.message ?? null);
      },
      error: () => {
        this.aiConfigured.set(false);
        this.aiMessage.set('Could not reach AI status endpoint.');
      },
    });
  }

  refreshGmailStatus(loadList = false): void {
    this.importSvc.gmailStatus().subscribe({
      next: (s) => {
        this.gmailConfigured.set(!!s.configured);
        this.gmailLinked.set(!!s.linked);
        this.gmailEmail.set(s.email ?? null);
        this.gmailMessage.set(s.message ?? null);
        if (loadList && s.linked) this.loadAttachments();
      },
      error: () => {
        this.gmailConfigured.set(false);
        this.gmailLinked.set(false);
        this.gmailEmail.set(null);
        this.gmailMessage.set('Could not reach Gmail status endpoint.');
      },
    });
  }

  connectGmail(): void {
    this.error.set(null);
    this.importSvc.gmailStart().subscribe({
      next: ({ url }) => {
        window.open(url, 'gmail_oauth', 'width=520,height=640');
      },
      error: (e: HttpErrorResponse) => this.error.set(this.errMsg(e, 'Could not start Gmail OAuth.')),
    });
  }

  disconnectGmail(): void {
    this.importSvc.gmailDisconnect().subscribe({
      next: () => {
        this.gmailLinked.set(false);
        this.gmailEmail.set(null);
        this.attachments.set([]);
        this.toast.set('Gmail disconnected.');
      },
      error: (e: HttpErrorResponse) => this.error.set(this.errMsg(e)),
    });
  }

  loadAttachments(): void {
    this.busy.set(true);
    this.importSvc.gmailList().subscribe({
      next: (list) => {
        this.attachments.set(list);
        this.busy.set(false);
      },
      error: (e: HttpErrorResponse) => {
        this.busy.set(false);
        this.error.set(this.errMsg(e, 'Failed to list Gmail attachments.'));
      },
    });
  }

  onCsvSelected(ev: Event): void {
    const input = ev.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file) return;
    if (!this.guardFile(file)) return;
    this.csvFileName.set(file.name);
    const large = file.size > 8 * 1024 * 1024;
    const aiMode = this.format() === 'AI';
    this.busyLabel.set(
      aiMode
        ? `AI capturing all CSV columns (${this.fmtMb(file.size)}) — please wait…`
        : large
          ? `Parsing large file (${this.fmtMb(file.size)}) — please wait…`
          : 'Parsing…',
    );
    this.busy.set(true);
    this.error.set(null);
    this.importSvc.parseFile(file, this.format()).subscribe({
      next: (r) => this.applyDraft(r),
      error: (e: unknown) => {
        this.busy.set(false);
        this.error.set(this.errMsg(e, 'Parse failed.'));
      },
    });
  }

  onAiSelected(ev: Event): void {
    const input = ev.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file) return;
    if (!this.guardFile(file)) return;
    const isPdf = (file.name.split('.').pop()?.toLowerCase() === 'pdf') || file.type === 'application/pdf';
    this.busyLabel.set(
      isPdf
        ? `Converting PDF pages, then AI extracting (${this.fmtMb(file.size)})…`
        : `AI extracting (${this.fmtMb(file.size)}) — please wait…`,
    );
    this.busy.set(true);
    this.error.set(null);
    this.importSvc.extractAi(file).subscribe({
      next: (r) => this.applyDraft(r),
      error: (e: unknown) => {
        this.busy.set(false);
        this.error.set(this.errMsg(e, 'AI extraction failed.'));
      },
    });
  }

  importAttachment(att: GmailAttachment): void {
    if (!this.guardFile(att)) return;
    this.busyLabel.set(`Importing ${att.filename}…`);
    this.busy.set(true);
    this.error.set(null);
    this.importSvc.gmailDownload(att, this.format()).subscribe({
      next: (r) => this.applyDraft(r),
      error: (e: unknown) => {
        this.busy.set(false);
        this.error.set(this.errMsg(e, 'Download/parse failed.'));
      },
    });
  }

  private guardFile(file: { size: number; name?: string }): boolean {
    try {
      this.importSvc.assertFileSize(file);
      return true;
    } catch (e) {
      this.error.set(e instanceof Error ? e.message : `Max file size is ${IMPORT_MAX_UPLOAD_MB} MB.`);
      return false;
    }
  }

  private fmtMb(bytes: number): string {
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
  }

  private applyDraft(r: {
    lines: ImportDraftLine[];
    format?: string;
    format_requested?: string;
    filename?: string;
    ai_fallback?: boolean;
    ai_error?: string;
    header?: { invoice_no?: string | null; invoice_date?: string | null; supplier_name?: string | null };
  }): void {
    this.busy.set(false);
    this.lines.set((r.lines ?? []).map((l) => this.normalizeLine(l, this.billCdPct())));
    this.lineFilter.set('all');
    if (r.header?.invoice_no) this.invoiceNo.set(toUpper(String(r.header.invoice_no)));
    if (r.header?.invoice_date) this.invoiceDate.set(String(r.header.invoice_date).slice(0, 10));
    if (r.header?.supplier_name) {
      const name = toUpper(String(r.header.supplier_name));
      this.supplierName.set(name);
      const found = this.suppliers().find((s) => s.name.toUpperCase() === name);
      this.supplierId.set(found?.id ?? null);
    }
    const fmt = r.format || 'Generic';
    const requested = r.format_requested || this.format();
    this.importReport.set({
      filename: r.filename || this.csvFileName() || 'file.csv',
      format: fmt,
      formatRequested: requested,
      aiFallback: !!r.ai_fallback,
      aiError: r.ai_error || null,
    });
    this.step.set('verify');
    this.runInvoiceCheck();
    this.autoFillMissingPtrPts();
    const n = this.lines().length;
    const matched = this.matchedCount();
    if (r.ai_fallback) {
      this.toast.set(
        `OpenAI was busy — loaded ${n} line(s) via ${fmt} (${matched} matched). Verify the import report below.`,
      );
    } else {
      this.toast.set(
        `Import report ready: ${n} line(s) · ${matched} matched · format ${fmt}. Review before saving.`,
      );
    }
  }

  private normalizeLine(l: ImportDraftLine, cdPct = 0): ImportDraftLine {
    const packs = this.packSizeOptions();
    const rawPack = String(l.pack_size || l.pack || '');
    const pack = resolvePackValue(packs, rawPack) || rawPack;
    const row: ImportDraftLine = {
      ...l,
      product_name: toUpper(l.product_name || ''),
      batch_no: toUpper(l.batch_no || ''),
      hsn_code: (l.hsn_code || '').toString(),
      mfg_name: toUpper(l.mfg_name || l.manufacturer_short || ''),
      manufacturer_short: toUpper(l.mfg_name || l.manufacturer_short || ''),
      pack_size: pack,
      pack: pack,
      units_per_pack: unitsForPack(packs, pack, Number(l.units_per_pack) || 1),
      quantity: Number(l.quantity) || 0,
      free_quantity: Number(l.free_quantity) || 0,
      purchase_rate: Number(l.purchase_rate) || 0,
      mrp: Number(l.mrp) || 0,
      ptr: Number(l.ptr) || 0,
      pts: Number(l.pts) || 0,
      discount_pct: Number(l.discount_pct) || 0,
      discount_amt: Number(l.discount_amt) || 0,
      gst_rate: Number(l.gst_rate) || 0,
      expiry_date: l.expiry_date || '',
      gross_amount: Number(l.gross_amount) || 0,
      cd_amt: Number(l.cd_amt) || 0,
      taxable_amount: Number(l.taxable_amount) || 0,
      gst_amount: Number(l.gst_amount) || 0,
      net_amount: Number(l.net_amount) || 0,
    };
    // Trust server-reconciled bill footer amounts (Medivision/PDF NET) when CD% is 0
    if (
      cdPct <= 0 &&
      (Number(l.net_amount) > 0 || Number(l.taxable_amount) > 0) &&
      Number(l.gross_amount) > 0
    ) {
      return {
        ...row,
        cd_amt: 0,
        gross_amount: Number(l.gross_amount),
        discount_amt: Number(l.discount_amt) || 0,
        discount_pct: Number(l.discount_pct) || 0,
        taxable_amount: Number(l.taxable_amount) || 0,
        gst_amount: Number(l.gst_amount) || 0,
        net_amount: Number(l.net_amount) || 0,
        gst_rate: Number(l.gst_rate) || 0,
      };
    }
    return this.recalcLine(row, cdPct);
  }

  /**
   * Exact retail upload-purchase calculateTaxable():
   * gross = qty × rate
   * disc_amt = gross × disc% / 100
   * cd_amt = (gross − disc) × CD% / 100
   * taxable = gross − disc − cd
   * gst_amt = taxable × gst% / 100
   * net = taxable + gst_amt
   */
  private recalcLine(row: ImportDraftLine, cdPct = this.billCdPct()): ImportDraftLine {
    const round2 = (n: number) => Math.round((n + Number.EPSILON) * 100) / 100;
    const qty = Number(row.quantity) || 0;
    const rate = Number(row.purchase_rate) || 0;
    let discPct = Number(row.discount_pct) || 0;
    const gst = Number(row.gst_rate) || 0;
    const csvDiscAmt = Number(row.discount_amt) || 0;
    const csvGross = Number(row.gross_amount) || 0;

    const grossQr = round2(qty * rate);
    const gross =
      csvGross > 0 && grossQr > 0 && Math.abs(csvGross - grossQr) <= Math.max(0.05, csvGross * 0.01)
        ? round2(csvGross)
        : grossQr;

    if (discPct <= 0 && csvDiscAmt > 0 && gross > 0) {
      discPct = round2((csvDiscAmt * 100) / gross);
    }
    let discAmt = round2((gross * discPct) / 100);
    if (csvDiscAmt > 0 && Math.abs(discAmt - csvDiscAmt) <= Math.max(0.05, csvDiscAmt * 0.02)) {
      discAmt = round2(csvDiscAmt);
      if (gross > 0) discPct = round2((discAmt * 100) / gross);
    }
    const cd = Number(cdPct) || 0;
    // Retail: CD only from bill CD%. Never keep imported cd_amt when CD% is 0.
    const cdAmt = cd > 0 ? round2(((gross - discAmt) * cd) / 100) : 0;
    const taxable = round2(Math.max(0, gross - discAmt - cdAmt));
    const gstAmt = round2((taxable * gst) / 100);
    const netAmt = round2(taxable + gstAmt);
    return {
      ...row,
      gross_amount: gross,
      discount_pct: discPct,
      discount_amt: discAmt,
      cd_amt: cdAmt,
      taxable_amount: taxable,
      gst_amount: gstAmt,
      net_amount: netAmt,
    };
  }

  onBillCdPct(v: number | string): void {
    const pct = Number(v) || 0;
    this.billCdPct.set(pct);
    this.lines.update((rows) => rows.map((r) => this.recalcLine(r, pct)));
  }

  private autoFillMissingPtrPts(): void {
    this.lines().forEach((l, i) => {
      if ((l.ptr && l.ptr > 0) || !l.mrp || l.mrp <= 0) return;
      this.purchaseSvc.computeRates(l.mrp, l.gst_rate || 0, 'Allopathy').subscribe({
        next: (r) => {
          this.lines.update((rows) => {
            const copy = [...rows];
            if (!copy[i]) return rows;
            copy[i] = { ...copy[i], ptr: r.ptr, pts: r.pts };
            return copy;
          });
        },
      });
    });
  }

  backToSource(): void {
    this.step.set('source');
    this.error.set(null);
    this.lineFilter.set('all');
  }

  downloadUnmatchedReport(): void {
    const rows = this.lines().filter((l) => !l.master_product_id);
    if (rows.length === 0) {
      this.toast.set('All lines are matched — nothing to export.');
      return;
    }
    const headers = [
      'Product Name', 'Batch', 'Qty', 'Free', 'MRP', 'Rate', 'PTR', 'PTS', 'Pack', 'MFG', 'Expiry', 'GST %',
    ];
    const esc = (v: unknown) => {
      const s = String(v ?? '');
      return s.includes(',') || s.includes('"') ? `"${s.replace(/"/g, '""')}"` : s;
    };
    const lines = rows.map((l) =>
      [
        l.product_name, l.batch_no, l.quantity, l.free_quantity, l.mrp, l.purchase_rate,
        l.ptr, l.pts, l.pack_size, l.mfg_name, l.expiry_date, l.gst_rate,
      ].map(esc).join(','),
    );
    const csv = [headers.join(','), ...lines].join('\n');
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
    const base = (this.importReport()?.filename || 'import').replace(/\.[^.]+$/, '');
    a.download = `${base}_unmatched.csv`;
    a.click();
    URL.revokeObjectURL(a.href);
  }

  downloadImportReport(): void {
    const meta = this.importReport();
    const t = this.billTotals();
    const lines = this.lines();
    const summary = [
      ['File', meta?.filename ?? ''],
      ['Format requested', meta?.formatRequested ?? ''],
      ['Format used', meta?.format ?? ''],
      ['Lines', String(lines.length)],
      ['Matched', String(this.matchedCount())],
      ['Unmatched', String(this.unmatchedCount())],
      ['Invoice', this.invoiceNo()],
      ['Invoice date', this.invoiceDate()],
      ['Supplier', this.supplierName()],
      ['Gross', String(t.gross)],
      ['Discount', String(t.disc)],
      ['CD', String(t.cd)],
      ['Taxable', String(t.taxable)],
      ['GST', String(t.gst)],
      ['Net', String(t.net)],
    ];
    const esc = (v: string) => (v.includes(',') || v.includes('"') ? `"${v.replace(/"/g, '""')}"` : v);
    const body = [
      'Metric,Value',
      ...summary.map(([k, v]) => `${esc(k)},${esc(v)}`),
      '',
      '#,Matched,Product,Batch,Qty,Rate,MRP,Gross,Net',
      ...lines.map((l, i) =>
        [
          i + 1,
          l.master_product_id ? 'Y' : 'N',
          esc(l.product_name || ''),
          esc(l.batch_no || ''),
          l.quantity,
          l.purchase_rate,
          l.mrp,
          l.gross_amount,
          l.net_amount,
        ].join(','),
      ),
    ].join('\n');
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([body], { type: 'text/csv;charset=utf-8' }));
    const base = (meta?.filename || 'import').replace(/\.[^.]+$/, '');
    a.download = `${base}_import_report.csv`;
    a.click();
    URL.revokeObjectURL(a.href);
  }

  runInvoiceCheck(): void {
    const inv = this.invoiceNo().trim();
    if (!inv) {
      this.invoiceDuplicate.set(false);
      this.duplicateInfo.set(null);
      return;
    }
    this.importSvc.checkInvoice(inv).subscribe({
      next: (r) => {
        this.invoiceDuplicate.set(!!r.exists);
        if (r.exists && r.purchase) {
          this.duplicateInfo.set(
            `Invoice already used (PO ${r.purchase['po_no'] ?? r.purchase['id']}, ${r.purchase['invoice_date'] ?? ''}).`,
          );
        } else {
          this.duplicateInfo.set(null);
        }
      },
      error: () => {
        this.invoiceDuplicate.set(false);
        this.duplicateInfo.set(null);
      },
    });
  }

  onInvoiceNo(v: string): void {
    this.invoiceNo.set(toUpper(v));
    if (this.invoiceCheckTimer) clearTimeout(this.invoiceCheckTimer);
    this.invoiceCheckTimer = setTimeout(() => this.runInvoiceCheck(), 400);
  }

  openSupplierDropdown(ev?: Event): void {
    ev?.stopPropagation();
    this.showSupplierDropdown.set(true);
  }

  onSupplierQuery(q: string): void {
    this.supplierQuery.set(toUpper(q));
    this.showSupplierDropdown.set(true);
  }

  pickSupplier(s: DistOption): void {
    this.supplierId.set(s.id);
    this.supplierName.set(toUpper(s.name));
    this.supplierQuery.set('');
    this.showSupplierDropdown.set(false);
  }

  clearSupplier(ev?: Event): void {
    ev?.stopPropagation();
    this.supplierId.set(null);
    this.supplierName.set('');
  }

  goAddSupplier(ev?: Event): void {
    ev?.preventDefault();
    ev?.stopPropagation();
    this.showSupplierDropdown.set(false);
    const panel = this.panel();
    const returnUrl = `/app/purchase/import?panel=${panel}&step=verify`;
    this.purchaseDraft.saveImportResume({
      source: 'hub',
      returnUrl,
      payload: this.hubImportResumePayload(),
    });
    const name = (this.supplierQuery() || this.supplierName()).trim();
    void this.router.navigate(['/app/masters/distributors'], {
      queryParams: {
        returnUrl,
        create: '1',
        ...(name ? { name } : {}),
      },
    });
  }

  private hubImportResumePayload(): Record<string, unknown> {
    return {
      panel: this.panel(),
      step: this.step(),
      lines: this.lines(),
      invoiceNo: this.invoiceNo(),
      invoiceDate: this.invoiceDate(),
      supplierId: this.supplierId(),
      supplierName: this.supplierName(),
      billCdPct: this.billCdPct(),
      importReport: this.importReport(),
      csvFileName: this.csvFileName(),
      lineFilter: this.lineFilter(),
    };
  }

  private applyHubImportResume(p: Record<string, unknown>): void {
    const panel = p['panel'];
    if (panel === 'upload' || panel === 'scan' || panel === 'ai' || panel === 'csv' || panel === 'email') {
      this.panel.set(panel);
    }
    if (p['step'] === 'verify') {
      this.step.set('verify');
    }
    if (Array.isArray(p['lines'])) {
      this.lines.set(p['lines'] as ImportDraftLine[]);
    }
    if (typeof p['invoiceNo'] === 'string') this.invoiceNo.set(p['invoiceNo']);
    if (typeof p['invoiceDate'] === 'string') this.invoiceDate.set(p['invoiceDate']);
    if (p['supplierId'] != null) this.supplierId.set(Number(p['supplierId']) || null);
    if (typeof p['supplierName'] === 'string') this.supplierName.set(p['supplierName']);
    if (typeof p['billCdPct'] === 'number') this.billCdPct.set(p['billCdPct']);
    if (p['importReport'] && typeof p['importReport'] === 'object') {
      this.importReport.set(p['importReport'] as {
        filename: string;
        format: string;
        formatRequested: string;
        aiFallback: boolean;
        aiError: string | null;
      });
    }
    if (typeof p['csvFileName'] === 'string') this.csvFileName.set(p['csvFileName']);
    const lf = p['lineFilter'];
    if (lf === 'all' || lf === 'matched' || lf === 'unmatched') this.lineFilter.set(lf);
  }

  private selectSupplierById(id: number): void {
    if (id <= 0) return;
    const found = this.suppliers().find((s) => s.id === id);
    if (found) {
      this.pickSupplier(found);
      return;
    }
    this.crud.get<{ id: number; name: string; address?: string | null }>('distributors', id).subscribe({
      next: (row) => {
        const entry: DistOption = {
          id: Number(row.id),
          name: String(row.name ?? ''),
          address: row.address != null && String(row.address).trim() !== '' ? String(row.address) : null,
        };
        this.suppliers.update((list) =>
          [...list, entry].sort((a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: 'base' })),
        );
        this.pickSupplier(entry);
      },
    });
  }

  @HostListener('document:click', ['$event'])
  onDocClick(ev: MouseEvent): void {
    const t = ev.target as HTMLElement | null;
    if (!t?.closest('.supplier-combo')) this.showSupplierDropdown.set(false);
    if (!t?.closest('.match-cell')) {
      this.matchRow.set(null);
      this.matchHits.set([]);
    }
    if (!t?.closest('.pack-select-wrap')) this.showAddPackDropdown = false;
  }

  patchLine(idx: number, field: keyof ImportDraftLine, value: string | number): void {
    this.lines.update((rows) => {
      const copy = [...rows];
      const row = { ...copy[idx] };
      const upperFields: (keyof ImportDraftLine)[] = ['product_name', 'batch_no', 'mfg_name', 'hsn_code'];
      if (field === 'pack_size' && typeof value === 'string') {
        const packs = this.packSizeOptions();
        const pack = resolvePackValue(packs, value) || value.trim();
        row.pack_size = pack;
        row.pack = pack;
        row.units_per_pack = unitsForPack(packs, pack, Number(row.units_per_pack) || 1);
      } else if (typeof value === 'string' && upperFields.includes(field)) {
        (row as Record<string, unknown>)[field] = toUpper(value);
        if (field === 'mfg_name') row.manufacturer_short = toUpper(value);
      } else {
        (row as Record<string, unknown>)[field] = value;
      }
      const amountFields: (keyof ImportDraftLine)[] = [
        'quantity', 'purchase_rate', 'discount_pct', 'discount_amt', 'gst_rate', 'free_quantity',
      ];
      copy[idx] = amountFields.includes(field) ? this.recalcLine(row) : row;
      return copy;
    });
    if (field === 'mrp' || field === 'gst_rate') {
      const row = this.lines()[idx];
      if (row?.mrp && row.mrp > 0) {
        this.purchaseSvc.computeRates(row.mrp, row.gst_rate || 0, 'Allopathy').subscribe({
          next: (r) => {
            this.lines.update((rows) => {
              const copy = [...rows];
              if (!copy[idx]) return rows;
              copy[idx] = { ...copy[idx], ptr: r.ptr, pts: r.pts };
              return copy;
            });
          },
        });
      }
    }
  }

  lineOrphanPack(l: ImportDraftLine): string | null {
    const cur = String(l.pack_size || l.pack || '').trim();
    if (!cur || findPackOption(this.packSizeOptions(), cur)) return null;
    return cur;
  }

  linePackValue(l: ImportDraftLine): string {
    return resolvePackValue(this.packSizeOptions(), String(l.pack_size || l.pack || ''));
  }

  money(n: number | undefined | null): string {
    return (Number(n) || 0).toFixed(2);
  }

  openMatch(idx: number): void {
    this.matchRow.set(idx);
    const q = this.lines()[idx]?.product_name || '';
    this.matchQuery.set(q);
    this.searchMatch(q);
  }

  searchMatch(q: string): void {
    this.matchQuery.set(q);
    if (!q.trim()) {
      this.matchHits.set([]);
      return;
    }
    this.importSvc.matchSearch(q, 25).subscribe({
      next: (hits) => this.matchHits.set(hits),
      error: () => this.matchHits.set([]),
    });
  }

  openAddProductModal(idx: number): void {
    const item = this.lines()[idx];
    if (!item) return;
    this.productSaveSub?.unsubscribe();
    this.productSaveSub = null;
    this.savingProduct = false;
    this.addProductForIndex = idx;
    this.addProductError = null;
    this.matchRow.set(null);
    this.matchHits.set([]);
    this.addForm = {
      product_type: 'Medicine',
      name: toUpper(String(item.product_name || '')),
      schedule_category: 'OTC',
      product_category: 'Allopathy',
      pack_size: resolvePackValue(this.packSizeOptions(), String(item.pack_size || item.pack || '')),
      dosage_form: '',
      strength: '',
      mrp: Number(item.mrp) || 0,
      default_gst_rate: Number(item.gst_rate) || 5,
      hsn_code: String(item.hsn_code || ''),
      packing_desc: '',
      manufacturer: String(item.mfg_name || item.manufacturer_short || ''),
    };
    const found = findPackOption(this.packSizeOptions(), this.addForm.pack_size);
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
    this.addProductForIndex = null;
    this.addProductError = null;
    this.savingProduct = false;
    this.showAddPackDropdown = false;
    if (this.addPackBlurTimer) {
      clearTimeout(this.addPackBlurTimer);
      this.addPackBlurTimer = undefined;
    }
  }

  onAddProductBackdrop(ev: Event): void {
    ev.preventDefault();
    ev.stopPropagation();
    if (this.savingProduct) return;
    this.closeAddProductModal();
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
        const found = findPackOption(this.packSizeOptions(), typed);
        this.addForm.pack_size = found?.pack ?? typed;
        this.addPackSearch = found ? (found.label || found.pack) : typed;
      } else {
        this.addForm.pack_size = '';
      }
      this.addPackBlurTimer = undefined;
    }, 180);
  }

  saveProductFromModal(): void {
    this.addProductError = null;
    const name = this.addForm.name.trim().toUpperCase();
    const pack = resolvePackValue(this.packSizeOptions(), this.addForm.pack_size.trim()) || this.addForm.pack_size.trim();
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
      units_per_pack: unitsForPack(this.packSizeOptions(), pack, 1),
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

    const sourceIdx = this.addProductForIndex;
    const sourceRow = sourceIdx != null ? this.lines()[sourceIdx] : null;
    const csvKey = this.normProductName(String(sourceRow?.product_name || name));

    this.productSaveSub?.unsubscribe();
    this.savingProduct = true;
    this.addProductError = null;

    this.productSaveSub = this.crud.create<{ id: number }>('products', payload).pipe(
      timeout({ first: 25_000 }),
      finalize(() => {
        this.savingProduct = false;
      }),
    ).subscribe({
      next: (res) => {
        const id = Number(res.data?.id ?? 0);
        this.closeAddProductModal(false);

        if (!id) {
          this.toast.set('Product added successfully.');
          setTimeout(() => this.toast.set(null), 2500);
          return;
        }

        const hit = {
          id,
          name,
          pack_size: pack,
          manufacturer: (payload['manufacturer'] as string) || null,
          default_gst_rate: Number(payload['default_gst_rate']) || 0,
          hsn_code: (payload['hsn_code'] as string) || null,
        };

        const indices: number[] = [];
        this.lines().forEach((l, i) => {
          if (this.normProductName(String(l.product_name || '')) === csvKey) indices.push(i);
        });
        for (const i of indices) {
          this.applyMatch(i, hit);
        }

        this.toast.set(
          indices.length > 1
            ? `Product added — matched ${indices.length} rows with the same name.`
            : 'Product added successfully.',
        );
        setTimeout(() => this.toast.set(null), 2800);
      },
      error: (err) => {
        if (err?.name === 'TimeoutError') {
          this.addProductError = 'Save timed out. Refresh and check if the product was added, then rematch.';
        } else {
          const msg = err?.error?.message
            || (err?.error?.errors ? Object.values(err.error.errors as Record<string, unknown>).flat().join(' ') : null)
            || 'Could not add product.';
          this.addProductError = String(msg);
        }
      },
    });
  }

  private filterAddPackList(q: string): PackSizeOption[] {
    const list = this.packSizeOptions();
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

  private normProductName(s: string): string {
    return String(s || '').toLowerCase().replace(/\s+/g, ' ').trim();
  }

  applyMatch(idx: number, hit: {
    id: number;
    name: string;
    default_gst_rate: number;
    manufacturer?: string | null;
    hsn_code?: string | null;
    pack_size?: string | null;
  }): void {
    this.lines.update((rows) => {
      const copy = [...rows];
      const row = { ...copy[idx] };
      row.master_product_id = hit.id;
      row.matched_name = hit.name;
      row.product_name = toUpper(hit.name);
      if (!row.gst_rate) row.gst_rate = hit.default_gst_rate || 0;
      if (!row.hsn_code && hit.hsn_code) row.hsn_code = String(hit.hsn_code);
      if (!row.mfg_name && hit.manufacturer) {
        row.mfg_name = toUpper(hit.manufacturer);
        row.manufacturer_short = row.mfg_name;
      }
      if (hit.pack_size) {
        const packs = this.packSizeOptions();
        const pack = resolvePackValue(packs, String(hit.pack_size)) || String(hit.pack_size);
        row.pack_size = pack;
        row.pack = pack;
        row.units_per_pack = unitsForPack(packs, pack, Number(row.units_per_pack) || 1);
      }
      copy[idx] = this.recalcLine(row);
      return copy;
    });
    this.matchRow.set(null);
    this.matchHits.set([]);
    const row = this.lines()[idx];
    if (row?.mrp && row.mrp > 0 && !(row.ptr && row.ptr > 0)) {
      this.purchaseSvc.computeRates(row.mrp, row.gst_rate || 0, 'Allopathy').subscribe({
        next: (r) => {
          this.lines.update((rows) => {
            const copy = [...rows];
            if (!copy[idx]) return rows;
            copy[idx] = { ...copy[idx], ptr: r.ptr, pts: r.pts };
            return copy;
          });
        },
      });
    }
  }

  removeLine(idx: number): void {
    this.lines.update((rows) => rows.filter((_, i) => i !== idx));
  }

  confirmSave(): void {
    if (!this.checks().canSave) {
      this.error.set('Complete all verification checks before saving.');
      return;
    }
    const packs = this.packSizeOptions();
    const lines = this.lines();
    for (let i = 0; i < lines.length; i++) {
      const l = lines[i];
      const n = i + 1;
      if (!l.master_product_id) {
        this.error.set(`Row ${n}: Match product first.`);
        return;
      }
      const pack = resolvePackValue(packs, String(l.pack_size || l.pack || '')) || String(l.pack_size || l.pack || '').trim();
      if (!pack) {
        this.error.set(`Row ${n}: Pack size is required.`);
        return;
      }
      if (!String(l.batch_no || '').trim()) {
        this.error.set(`Row ${n}: Batch number is required.`);
        return;
      }
      if (!String(l.expiry_date || '').trim()) {
        this.error.set(`Row ${n}: Expiry date is required.`);
        return;
      }
      if (!(Number(l.quantity) > 0)) {
        this.error.set(`Row ${n}: Quantity must be greater than 0.`);
        return;
      }
      if (!(Number(l.mrp) > 0) || !(Number(l.purchase_rate) > 0)) {
        this.error.set(`Row ${n}: MRP and Rate must be greater than 0.`);
        return;
      }
    }

    this.saving.set(true);
    this.error.set(null);
    const items = lines.map((l) => {
      const pack = resolvePackValue(packs, String(l.pack_size || l.pack || '')) || String(l.pack_size || l.pack || '').trim();
      return {
        master_product_id: l.master_product_id,
        product_name: l.product_name,
        batch_no: l.batch_no || '',
        expiry_date: l.expiry_date || '',
        quantity: Number(l.quantity) || 0,
        free_quantity: Number(l.free_quantity) || 0,
        purchase_rate: Number(l.purchase_rate) || 0,
        mrp: Number(l.mrp) || 0,
        ptr: Number(l.ptr) || 0,
        pts: Number(l.pts) || 0,
        discount_pct: Number(l.discount_pct) || 0,
        gst_rate: Number(l.gst_rate) || 0,
        hsn_code: l.hsn_code || null,
        units_per_pack: unitsForPack(packs, pack, Number(l.units_per_pack) || 1),
        pack_size: pack,
        pack_raw: l.pack_raw || l.pack || '',
      };
    });
    this.purchaseSvc
      .create({
        invoice_no: this.invoiceNo().trim(),
        invoice_date: this.invoiceDate(),
        supplier_id: this.supplierId(),
        supplier_name: this.supplierName().trim() || undefined,
        bill_discount_pct: this.billCdPct() || undefined,
        items,
      })
      .subscribe({
        next: (r: ApiResponse<{ id: number; net_amount: number }>) => {
          this.saving.set(false);
          this.toast.set(`Purchase saved (PO #${r.data?.id}).`);
          void this.router.navigateByUrl('/app/purchase');
        },
        error: (e: HttpErrorResponse) => {
          this.saving.set(false);
          this.error.set(this.errMsg(e, 'Save failed.'));
        },
      });
  }

  private errMsg(e: unknown, fallback = 'Request failed.'): string {
    if (e instanceof HttpErrorResponse) {
      if (e.status === 0) {
        return 'Could not reach the API (or the response was blocked). Check that pharmacy-api is running on localhost:8080, then retry. For Sale_*.csv use Auto-detect or Medivision.';
      }
      const body = e.error as { message?: string; errors?: Record<string, unknown> } | null;
      if (body?.errors && typeof body.errors === 'object') {
        const parts = Object.values(body.errors).flat().map(String).filter(Boolean);
        if (parts.length) return parts.join(' ');
      }
      if (body?.message) return body.message;
      return e.message || fallback;
    }
    if (e instanceof Error) {
      if (e.name === 'TimeoutError') {
        return 'Request timed out. Try Auto-detect / Medivision for ERP CSVs, or a smaller file.';
      }
      return e.message || fallback;
    }
    return fallback;
  }
}
