import { Component, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { forkJoin } from 'rxjs';
import { HttpErrorResponse } from '@angular/common/http';
import {
  BatchOption,
  SalePayload,
  SaleProduct,
  SalesService,
} from '../../core/services/sales.service';
import { AuthService } from '../../core/services/auth.service';
import { CrudService } from '../../core/services/crud.service';
import { UserRightsService } from '../../core/services/user-rights.service';

interface EditLine {
  binding_id: number;
  product_name: string;
  batch_id: number;
  batch_no: string;
  expiry: string | null;
  category: string;
  pack: string | null;
  gst_rate: number;
  units_per_pack: number;
  unit_type: 'unit' | 'pack';
  rate: number;
  qty: number;
  disc: number;
  disc_amt: number;
  cost: number;
  taxable: number;
  tax: number;
  net: number;
  profit: number;
  max_qty: number;
  /** Inventory units currently in stock for this batch. */
  batch_stock_base: number;
  /** Units this line credits back to the pool (original on-bill qty; 0 for newly added). */
  batch_credit_base: number;
  isNew?: boolean;
  /** Wholesale free packs (display); saved as base units. */
  free_qty?: number;
  oth_rate?: number;
  base_rate?: number;
  net_rate?: number;
  billing_with?: string;
  sale_scheme_pct?: number;
  pack_purchase_rate?: number;
}

interface BatchPickState {
  product: SaleProduct;
  batches: BatchOption[];
}

@Component({
  selector: 'app-sale-bill-edit',
  imports: [CommonModule, FormsModule, RouterLink],
  templateUrl: './sale-bill-edit.html',
  styleUrl: './sale-bill-edit.scss',
})
export class SaleBillEdit {
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly salesSvc = inject(SalesService);
  private readonly auth = inject(AuthService);
  private readonly crud = inject(CrudService);
  private readonly rights = inject(UserRightsService);

  readonly paymentModes = ['Cash', 'Card', 'UPI', 'Credit', 'Cash+UPI'] as const;
  readonly loading = signal(true);
  readonly saving = signal(false);
  readonly error = signal<string | null>(null);
  readonly saleId = signal(0);
  readonly header = signal<Record<string, unknown> | null>(null);
  readonly lines = signal<EditLine[]>([]);
  readonly paymentMode = signal('Cash');
  readonly accountId = signal<number | null>(null);
  readonly cashAmount = signal<number | null>(null);
  readonly billDiscountAmt = signal(0);
  readonly cdDiscPct = signal(0);
  readonly rateType = signal<'ptr' | 'pts'>('ptr');
  readonly accounts = signal<{ id: number; name: string; account_no?: string; bank_name?: string }[]>([]);

  readonly searchTerm = signal('');
  readonly searchResults = signal<SaleProduct[]>([]);
  readonly searching = signal(false);
  /** When product has multiple sellable batches, user picks one. */
  readonly batchPick = signal<BatchPickState | null>(null);

  readonly showProfit = computed(() => this.auth.isHoAdmin());
  readonly isWholesale = computed(() => String(this.header()?.['bill_mode'] ?? '') === 'wholesale');

  readonly totals = computed(() => {
    const rows = this.lines();
    let taxable = 0;
    let discount = 0;
    let tax = 0;
    let profit = 0;
    let net = 0;
    for (const l of rows) {
      taxable += l.taxable;
      discount += l.disc_amt;
      tax += l.tax;
      profit += l.profit;
      net += l.net;
    }
    const bulk = this.isWholesale() ? 0 : Math.min(Math.max(0, this.billDiscountAmt()), net);
    const netBeforeRound = this.round2(net - bulk);
    const netRounded = Math.round(netBeforeRound);
    return {
      taxable: this.round2(taxable),
      discount: this.round2(discount),
      tax: this.round2(tax),
      profit: this.round2(Math.max(0, profit - bulk + (netRounded - netBeforeRound))),
      itemsNet: this.round2(net),
      bulk,
      roundOff: this.round2(netRounded - netBeforeRound),
      net: netRounded,
    };
  });

  private searchTimer: ReturnType<typeof setTimeout> | null = null;

  ngOnInit(): void {
    if (!this.rights.hasFullAccess()) {
      void this.router.navigate(['/app/sales']);
      return;
    }
    this.crud.list<{ id: number; name: string; account_no?: string; bank_name?: string; type?: string }>('accounts', {
      perPage: 200,
    }).subscribe({
      next: (res) => {
        this.accounts.set(
          (res.data ?? []).filter((a) => (a.type ?? '') === 'bank' || !!(a.account_no || a.bank_name)),
        );
      },
      error: () => this.accounts.set([]),
    });

    const id = Number(this.route.snapshot.paramMap.get('id'));
    if (!id) {
      void this.router.navigate(['/app/sales']);
      return;
    }
    this.saleId.set(id);
    this.loadSale(id);
  }

  private loadSale(id: number): void {
    this.loading.set(true);
    this.error.set(null);
    this.salesSvc.get(id).subscribe({
      next: (sale) => {
        const mode = String(sale['bill_mode'] ?? 'retail').toLowerCase();
        if (mode !== 'retail' && mode !== 'wholesale') {
          this.loading.set(false);
          this.error.set('This screen edits retail and wholesale sale bills only. Use Sales log for payment mode on other bill types.');
          this.header.set(sale);
          return;
        }
        if (mode === 'wholesale' && String(sale['credit_note_no'] ?? '').trim() !== '') {
          this.loading.set(false);
          this.error.set('Wholesale bill with credit note applied cannot be edited here.');
          this.header.set(sale);
          return;
        }
        const items = Array.isArray(sale['items']) ? (sale['items'] as Record<string, unknown>[]) : [];
        const bindingIds = [...new Set(items.map((it) => Number(it['product_binding_id'])).filter((x) => x > 0))];
        if (bindingIds.length === 0) {
          this.applyHeader(sale, []);
          this.loading.set(false);
          return;
        }
        forkJoin(bindingIds.map((bid) => this.salesSvc.batches(bid))).subscribe({
          next: (groups) => {
            const batchMap = new Map<number, BatchOption[]>();
            bindingIds.forEach((bid, idx) => batchMap.set(bid, groups[idx] ?? []));
            this.applyHeader(sale, items);
            const editLines = items
              .map((it) => this.lineFromItem(it, batchMap.get(Number(it['product_binding_id'])) ?? []))
              .filter((l): l is EditLine => l != null);
            this.lines.set(editLines);
            this.refreshAllMaxQty();
            for (let i = 0; i < editLines.length; i++) {
              this.recalcLine(editLines[i]);
            }
            this.lines.set([...editLines]);
            this.loading.set(false);
          },
          error: () => {
            this.loading.set(false);
            this.error.set('Could not load stock batches for this bill.');
          },
        });
      },
      error: () => {
        this.loading.set(false);
        this.error.set('Could not load sale bill.');
      },
    });
  }

  private applyHeader(sale: Record<string, unknown>, items: Record<string, unknown>[]): void {
    this.header.set(sale);
    const payRaw = String(sale['payment_mode'] ?? 'cash');
    const payLabel = payRaw.toLowerCase() === 'cash+upi'
      ? 'Cash+UPI'
      : payRaw.charAt(0).toUpperCase() + payRaw.slice(1).toLowerCase();
    this.paymentMode.set(
      this.paymentModes.includes(payLabel as (typeof this.paymentModes)[number]) ? payLabel : 'Cash',
    );
    this.accountId.set(sale['account_id'] != null ? Number(sale['account_id']) : null);
    this.cashAmount.set(payLabel === 'Cash+UPI' ? Number(sale['cash_amount']) || null : null);

    const rt = String(sale['rate_type'] ?? 'ptr').toLowerCase();
    this.rateType.set(rt === 'pts' ? 'pts' : 'ptr');
    this.cdDiscPct.set(Math.max(0, Number(sale['cd_disc_pct']) || 0));

    const isWs = String(sale['bill_mode'] ?? '') === 'wholesale';
    if (isWs) {
      this.billDiscountAmt.set(0);
      return;
    }

    const netStored = Number(sale['net_amount']) || 0;
    const bulkFromApi = Number(sale['bill_bulk_discount_amount']);
    const lineNet = items.reduce((s, it) => s + (Number(it['line_total']) || 0), 0);
    const bulk =
      Number.isFinite(bulkFromApi) && bulkFromApi > 0
        ? bulkFromApi
        : Math.max(0, this.round2(lineNet - netStored));
    this.billDiscountAmt.set(bulk);
  }

  private lineFromItem(it: Record<string, unknown>, batches: BatchOption[]): EditLine | null {
    const bindingId = Number(it['product_binding_id']) || 0;
    if (bindingId <= 0) {
      return null;
    }
    const upp = Math.max(1, Number(it['units_per_pack']) || 1);
    const unitType = String(it['unit_type'] ?? 'unit') === 'pack' ? 'pack' : 'unit';
    const baseQty = Number(it['quantity']) || 0;
    const freeBase = Math.max(0, Number(it['free_quantity']) || 0);
    const qty = unitType === 'pack' ? baseQty / upp : baseQty;
    const freeQty = unitType === 'pack' ? freeBase / upp : freeBase;
    const unitRate = Number(it['rate']) || 0;
    const packMrp = Number(it['mrp']) || Number(it['display_mrp']) || 0;
    const batchId = it['batch_id'] != null ? Number(it['batch_id']) : 0;
    const batch = batches.find((b) => b.batch_id === batchId) ?? batches[0];
    let rate = unitType === 'pack' ? unitRate * upp : unitRate;
    if (rate <= 0 && packMrp > 0) {
      rate = unitType === 'pack' ? packMrp : packMrp / upp;
    }
    const packPurchase =
      batch?.pack_purchase_rate
      ?? (batch ? Math.round((batch.purchase_rate ?? 0) * upp * 100) / 100 : 0);
    return this.buildLine({
      binding_id: bindingId,
      product_name: String(it['product_name'] ?? ''),
      batch_id: batch?.batch_id ?? batchId,
      batch_no: String(it['batch_no'] ?? batch?.batch_no ?? ''),
      expiry: (it['expiry_date'] as string) ?? batch?.expiry_date ?? null,
      category: String(it['schedule_category'] ?? 'OTC'),
      pack: (it['pack'] as string) ?? null,
      gst_rate: Number(it['gst_rate']) || 0,
      units_per_pack: upp,
      unit_type: unitType,
      rate,
      qty: qty > 0 ? qty : 1,
      disc: Number(it['discount_pct']) || 0,
      cost: batch?.purchase_rate ?? 0,
      batch_stock_base: batch?.quantity ?? 0,
      batch_credit_base: baseQty + freeBase,
      max_qty: 0,
      free_qty: freeQty,
      oth_rate: Number(it['oth_rate']) || 0,
      base_rate: Number(it['base_rate']) || rate,
      net_rate: Number(it['net_rate']) || rate,
      billing_with: String(it['billing_with'] ?? 'Sale Rate'),
      sale_scheme_pct: Number(it['sale_scheme_pct']) || 0,
      pack_purchase_rate: packPurchase,
    });
  }

  private lineBaseUnits(l: EditLine): number {
    const upp = Math.max(1, l.units_per_pack);
    const sold = l.unit_type === 'pack' ? l.qty * upp : l.qty;
    const free = this.isWholesale()
      ? (l.unit_type === 'pack' ? (l.free_qty || 0) * upp : (l.free_qty || 0))
      : 0;
    return sold + free;
  }

  /** Fixed pool = inventory now + original bill credits for the batch (does not grow with edits). */
  private batchPoolBase(batchId: number): number {
    const rows = this.lines().filter((l) => l.batch_id === batchId);
    if (rows.length === 0) {
      return 0;
    }
    const stock = Math.max(...rows.map((l) => l.batch_stock_base));
    const credit = rows.reduce((s, l) => s + (l.batch_credit_base || 0), 0);
    return stock + credit;
  }

  private maxQtyForLine(line: EditLine, index: number): number {
    const upp = Math.max(1, line.units_per_pack);
    const pool = this.batchPoolBase(line.batch_id);
    let other = 0;
    this.lines().forEach((l, i) => {
      if (i === index || l.batch_id !== line.batch_id) {
        return;
      }
      other += this.lineBaseUnits(l);
    });
    const freeBase = this.isWholesale()
      ? (line.unit_type === 'pack' ? (line.free_qty || 0) * upp : (line.free_qty || 0))
      : 0;
    const remainingBase = Math.max(0, pool - other - freeBase);
    return line.unit_type === 'pack' ? remainingBase / upp : remainingBase;
  }

  private refreshAllMaxQty(): void {
    const rows = this.lines();
    rows.forEach((l, i) => {
      l.max_qty = this.maxQtyForLine(l, i);
    });
  }

  private buildLine(partial: Omit<EditLine, 'disc_amt' | 'taxable' | 'tax' | 'net' | 'profit'>): EditLine {
    const line: EditLine = {
      ...partial,
      disc_amt: 0,
      taxable: 0,
      tax: 0,
      net: 0,
      profit: 0,
    };
    this.recalcLine(line);
    return line;
  }

  onSearchInput(q: string): void {
    this.searchTerm.set(q);
    this.batchPick.set(null);
    if (this.searchTimer) {
      clearTimeout(this.searchTimer);
    }
    const term = q.trim();
    if (term.length < 1) {
      this.searchResults.set([]);
      return;
    }
    this.searching.set(true);
    this.searchTimer = setTimeout(() => {
      this.salesSvc.searchProducts(term).subscribe({
        next: (rows) => {
          this.searchResults.set(rows.filter((p) => !p.is_expired && (p.available_qty ?? 0) > 0));
          this.searching.set(false);
        },
        error: () => {
          this.searchResults.set([]);
          this.searching.set(false);
        },
      });
    }, 280);
  }

  pickProduct(p: SaleProduct): void {
    this.searchTerm.set('');
    this.searchResults.set([]);
    this.salesSvc.batches(p.binding_id).subscribe({
      next: (batches) => {
        const candidates = batches.filter(
          (b) => !b.is_expired && this.batchPickAvail(b) > 0,
        );
        if (candidates.length === 0) {
          this.error.set(`No sellable stock for ${p.product_name}.`);
          return;
        }
        const alreadyOnBill = this.lines().some((l) => l.binding_id === p.binding_id);
        const unused = candidates.filter(
          (b) => !this.lines().some((l) => l.batch_id === b.batch_id),
        );
        // Product already on bill, or multiple batches → always let user pick batch/MRP.
        // Never auto-merge into the first line when another batch exists.
        if (alreadyOnBill || candidates.length > 1) {
          const ordered = [...unused, ...candidates.filter((b) => !unused.some((u) => u.batch_id === b.batch_id))];
          this.batchPick.set({ product: p, batches: ordered });
          this.error.set(null);
          return;
        }
        this.addLineWithBatch(p, candidates[0], false);
      },
      error: () => this.error.set('Could not load batches for product.'),
    });
  }

  pickBatch(b: BatchOption): void {
    const state = this.batchPick();
    if (!state) {
      return;
    }
    this.batchPick.set(null);
    // Same batch already on bill → bump qty; different batch → new line (different MRP).
    this.addLineWithBatch(state.product, b, true);
  }

  cancelBatchPick(): void {
    this.batchPick.set(null);
  }

  batchPickAvail(b: BatchOption): number {
    const wholesale = this.isWholesale();
    const upp = Math.max(1, b.units_per_pack || 1);
    const existing = this.lines().filter((l) => l.batch_id === b.batch_id);
    const stock = Number(b.quantity) || 0;
    const credit = existing.reduce((s, l) => s + (l.batch_credit_base || 0), 0);
    const used = existing.reduce((s, l) => s + this.lineBaseUnits(l), 0);
    const rem = Math.max(0, stock + credit - used);
    return wholesale ? rem / upp : rem;
  }

  /**
   * @param allowIncrement same batch already on bill may +1 qty; different batch always new row
   */
  private addLineWithBatch(p: SaleProduct, b: BatchOption, allowIncrement: boolean): void {
    const upp = Math.max(1, b.units_per_pack ?? p.units_per_pack ?? 1);
    const wholesale = this.isWholesale();
    const existingIdx = this.lines().findIndex(
      (l) => l.binding_id === p.binding_id && Number(l.batch_id) === Number(b.batch_id),
    );
    if (existingIdx >= 0) {
      if (allowIncrement) {
        this.onQtyChange(existingIdx, this.lines()[existingIdx].qty + 1);
        return;
      }
      // Should not auto-merge — open picker instead (caller handles).
      this.error.set(`Batch ${b.batch_no} is already on this bill. Pick it again to increase qty, or choose another batch.`);
      return;
    }
    let unitType: 'unit' | 'pack' = 'unit';
    // Retail: sell at this batch's unit MRP so different-MRP batches stay distinct.
    let rate = Number(b.unit_mrp) || 0;
    if (rate <= 0 && (b.pack_mrp || 0) > 0) {
      rate = Math.round(((b.pack_mrp || 0) / upp) * 10000) / 10000;
    }
    const packPurchase = b.pack_purchase_rate ?? Math.round((b.purchase_rate ?? 0) * upp * 100) / 100;
    if (wholesale) {
      unitType = 'pack';
      const ptr = Number(b.ptr) || 0;
      const pts = Number(b.pts) || 0;
      const packMrp = b.pack_mrp ?? b.unit_mrp * upp;
      const packRate =
        this.rateType() === 'pts'
          ? (pts > 0 ? pts : ptr > 0 ? ptr : packMrp)
          : (ptr > 0 ? ptr : pts > 0 ? pts : packMrp);
      rate = Math.round(packRate * 100) / 100;
    }
    const remBase = Number(b.quantity) || 0;
    const avail = this.batchPickAvail(b);
    if (avail <= 0 || (remBase <= 0 && existingIdx < 0)) {
      this.error.set(`Batch ${b.batch_no} has no available stock.`);
      return;
    }
    const line = this.buildLine({
      binding_id: p.binding_id,
      product_name: p.product_name,
      batch_id: Number(b.batch_id),
      batch_no: b.batch_no,
      expiry: b.expiry_date,
      category: p.schedule_category,
      pack: p.pack_size ?? null,
      gst_rate: Number(p.gst_rate) || 0,
      units_per_pack: upp,
      unit_type: unitType,
      rate,
      qty: 1,
      disc: 0,
      cost: b.purchase_rate,
      batch_stock_base: remBase,
      batch_credit_base: 0,
      max_qty: unitType === 'pack' ? remBase / upp : remBase,
      isNew: true,
      free_qty: 0,
      oth_rate: 0,
      base_rate: rate,
      net_rate: rate,
      billing_with: 'Sale Rate',
      sale_scheme_pct: 0,
      pack_purchase_rate: packPurchase,
    });
    this.lines.update((rows) => [...rows, line]);
    this.refreshAllMaxQty();
    this.lines.update((rows) => rows.map((l) => ({ ...l })));
    this.error.set(null);
  }

  onQtyChange(index: number, raw: number): void {
    const rows = [...this.lines()];
    const line = rows[index];
    if (!line) {
      return;
    }
    const maxQty = this.maxQtyForLine(line, index);
    line.max_qty = maxQty;
    let qty = Math.max(0, Number(raw) || 0);
    if (qty > maxQty) {
      qty = maxQty;
      this.error.set(
        `${line.product_name}: batch ${line.batch_no} has only ${this.round2(maxQty)} available.`,
      );
    } else {
      this.error.set(null);
    }
    line.qty = qty;
    this.syncDiscFromPct(line);
    rows[index] = { ...line };
    this.lines.set(rows);
    this.refreshAllMaxQty();
    this.lines.update((r) => r.map((l) => ({ ...l })));
  }

  /** Editable MRP (retail) / pack rate (wholesale) — defaults from batch MRP on add. */
  onRateChange(index: number, raw: number): void {
    const rows = [...this.lines()];
    const line = rows[index];
    if (!line) {
      return;
    }
    const rate = Math.max(0, this.round2(Number(raw) || 0));
    line.rate = rate;
    if (this.isWholesale()) {
      line.base_rate = rate;
      line.net_rate = rate;
    }
    this.syncDiscFromPct(line);
    rows[index] = { ...line };
    this.lines.set(rows);
  }

  onDiscPctChange(index: number, raw: number): void {
    const rows = [...this.lines()];
    const line = rows[index];
    if (!line) {
      return;
    }
    line.disc = Math.min(100, Math.max(0, Number(raw) || 0));
    this.syncDiscFromPct(line);
    rows[index] = { ...line };
    this.lines.set(rows);
  }

  onDiscAmtChange(index: number, raw: number): void {
    const rows = [...this.lines()];
    const line = rows[index];
    if (!line) {
      return;
    }
    const gross = this.lineGross(line);
    const disc_amt = Math.max(0, this.round2(Number(raw) || 0));
    line.disc_amt = disc_amt;
    line.disc = gross > 0 ? this.round2((disc_amt / gross) * 100) : 0;
    this.recalcLine(line);
    rows[index] = { ...line };
    this.lines.set(rows);
  }

  removeLine(index: number): void {
    this.lines.update((rows) => rows.filter((_, i) => i !== index));
    this.refreshAllMaxQty();
    this.lines.update((rows) => rows.map((l) => ({ ...l })));
  }

  onPaymentModeChange(mode: string): void {
    this.paymentMode.set(mode);
    if (mode !== 'Card') {
      this.accountId.set(null);
    }
    if (mode !== 'Cash+UPI') {
      this.cashAmount.set(null);
    }
  }

  save(): void {
    const h = this.header();
    const rows = this.lines();
    if (!h || this.saleId() <= 0) {
      return;
    }
    if (rows.length === 0) {
      this.error.set('Add at least one product line.');
      return;
    }
    if (rows.some((l) => l.qty <= 0)) {
      this.error.set('Each line needs quantity of at least 1.');
      return;
    }
    if (rows.some((l) => !l.batch_id || l.batch_id <= 0)) {
      this.error.set('Each line must have a stock batch so quantity is deducted from the correct batch.');
      return;
    }
    // Same product may appear on multiple lines only with different batches.
    const seenBatch = new Set<string>();
    for (const l of rows) {
      const key = `${l.binding_id}:${l.batch_id}`;
      if (seenBatch.has(key)) {
        this.error.set(
          `${l.product_name}: batch ${l.batch_no} is duplicated — merge qty on one line or use another batch.`,
        );
        return;
      }
      seenBatch.add(key);
    }
    for (let i = 0; i < rows.length; i++) {
      const l = rows[i];
      const maxQty = this.maxQtyForLine(l, i);
      if (l.qty > maxQty + 0.0001) {
        this.error.set(
          `${l.product_name}: batch ${l.batch_no} allows only ${this.round2(maxQty)}.`,
        );
        return;
      }
    }
    const mode = this.paymentMode();
    if (mode === 'Card' && !this.accountId()) {
      this.error.set('Select a bank / card account for Card payments.');
      return;
    }
    const cash = Number(this.cashAmount()) || 0;
    if (mode === 'Cash+UPI' && (cash <= 0 || cash >= this.totals().net)) {
      this.error.set(`Cash+UPI: enter the Cash amount — more than 0 and less than Net ₹${this.totals().net.toFixed(2)}.`);
      return;
    }

    this.saving.set(true);
    this.error.set(null);

    const wholesale = this.isWholesale();
    const itemsNet = rows.reduce((s, l) => s + l.net, 0);
    const bulk = wholesale ? 0 : Math.min(this.billDiscountAmt(), itemsNet);
    const billMode = wholesale ? 'wholesale' : 'retail';

    const payload: SalePayload = {
      counter: String(h['counter'] ?? 'COUNTER 1'),
      sale_type: String(h['sale_type'] ?? 'Regular'),
      bill_mode: billMode,
      rate_type: wholesale ? this.rateType() : undefined,
      invoice_date: String(h['invoice_date'] ?? '').slice(0, 10) || undefined,
      customer_id: h['customer_id'] != null ? Number(h['customer_id']) : null,
      customer_name: String(h['patient_name'] ?? h['customer_name'] ?? 'Walk-in'),
      patient_name: String(h['patient_name'] ?? h['customer_name'] ?? 'Walk-in'),
      customer_type: wholesale ? 'wholesale' : 'retail',
      contact_no: String(h['contact_no'] ?? ''),
      email: String(h['email'] ?? ''),
      address: String(h['address'] ?? ''),
      narration: String(h['narration'] ?? ''),
      reminder_days: wholesale ? null : (h['reminder_days'] != null ? Number(h['reminder_days']) : null),
      doctor_name: String(h['doctor_name'] ?? ''),
      payment_mode: mode.toLowerCase(),
      account_id: mode === 'Card' ? this.accountId() : null,
      cash_amount: mode === 'Cash+UPI' ? this.round2(cash) : undefined,
      cd_disc_pct: wholesale ? this.cdDiscPct() : 0,
      bill_discount_amount: !wholesale && bulk > 0 ? bulk : undefined,
      is_interstate: Number(h['is_interstate']) === 1 ? 1 : 0,
      gst_inclusive: wholesale ? 0 : 1,
      is_chronic: Number(h['is_chronic']) === 1 ? 1 : 0,
      ipd_admission_id: h['ipd_admission_id'] != null ? Number(h['ipd_admission_id']) : null,
      prescription_id: h['prescription_id'] != null ? Number(h['prescription_id']) : null,
      items: rows.map((l) => {
        const upp = Math.max(1, l.units_per_pack);
        const baseQty = l.unit_type === 'pack' ? Math.round(l.qty * upp) : Math.round(l.qty);
        const freeBaseQty = wholesale
          ? (l.unit_type === 'pack' ? Math.round((l.free_qty || 0) * upp) : Math.round(l.free_qty || 0))
          : 0;
        const baseRate = l.unit_type === 'pack' ? l.rate / upp : l.rate;
        return {
          product_binding_id: l.binding_id,
          schedule_category: l.category,
          quantity: baseQty,
          free_quantity: freeBaseQty,
          rate: Math.round(baseRate * 100) / 100,
          discount_pct: Number(l.disc) || 0,
          batch_id: l.batch_id,
          unit_type: l.unit_type,
          pack: l.pack,
          shelf: null,
          hsn_code: null,
          oth_rate: wholesale ? (l.oth_rate ?? 0) : undefined,
          base_rate: wholesale ? (l.base_rate ?? l.rate) : undefined,
          net_rate: wholesale ? (l.net_rate ?? l.rate) : undefined,
          billing_with: wholesale ? (l.billing_with ?? 'Sale Rate') : undefined,
          sale_scheme_pct: wholesale ? (l.sale_scheme_pct ?? 0) : undefined,
        };
      }),
    };

    this.salesSvc.update(this.saleId(), payload).subscribe({
      next: () => {
        this.saving.set(false);
        void this.router.navigate(['/app/sales']);
      },
      error: (err: HttpErrorResponse) => {
        this.saving.set(false);
        const errors = err.error?.errors as Record<string, string[] | string> | undefined;
        let first: string | null = null;
        if (errors) {
          const val = Object.values(errors)[0];
          first = Array.isArray(val) ? val[0] : typeof val === 'string' ? val : null;
        }
        this.error.set(first ?? err.error?.message ?? 'Could not save bill changes.');
      },
    });
  }

  scheduleBg(cat: string): string {
    const c = (cat || '').toUpperCase();
    if (c.includes('H1')) {
      return '#F8B7B6';
    }
    if (c.includes('SCHEDULE H') || c === 'H') {
      return '#85C81A';
    }
    if (c.includes('X') || c.includes('NARCO')) {
      return '#fecaca';
    }
    return 'transparent';
  }

  expLabel(iso: string | null): string {
    if (!iso) {
      return '—';
    }
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) {
      return iso;
    }
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const yy = String(d.getFullYear()).slice(-2);
    return `${mm}/${yy}`;
  }

  private syncDiscFromPct(line: EditLine): void {
    const gross = this.lineGross(line);
    line.disc_amt = gross > 0 ? this.round2((gross * line.disc) / 100) : 0;
    this.recalcLine(line);
  }

  /** Retail GST-inclusive; wholesale PTR/PTS GST-exclusive (+ CD%). */
  private recalcLine(line: EditLine): void {
    const gross = this.lineGross(line);
    const disc_per = line.disc;
    const gst = line.gst_rate;
    const upp = Math.max(1, line.units_per_pack);
    const unitsSold = line.unit_type === 'pack' ? line.qty * upp : line.qty;

    line.disc_amt = this.round2(gross * (disc_per / 100));

    if (this.isWholesale()) {
      const afterDisc = this.round2(gross - line.disc_amt);
      const cdAmt = this.round2(afterDisc * (this.cdDiscPct() / 100));
      line.taxable = this.round2(afterDisc - cdAmt);
      line.tax = this.round2(line.taxable * (gst / 100));
      line.net = this.round2(line.taxable + line.tax);
      const packCost = line.pack_purchase_rate ?? line.cost * upp;
      line.profit = this.round2(Math.max(0, gross - line.disc_amt - packCost * line.qty));
      return;
    }

    const net = this.round2(gross * (1 - disc_per / 100));
    line.net = net;
    line.taxable = this.round2(net / (1 + gst / 100));
    line.tax = this.round2(net - line.taxable);
    line.profit = this.round2(
      Math.max(0, gross - line.disc_amt - line.cost * unitsSold),
    );
  }

  private lineGross(line: EditLine): number {
    return this.round2(line.rate * line.qty);
  }

  private round2(n: number): number {
    return Math.round(n * 100) / 100;
  }
}
