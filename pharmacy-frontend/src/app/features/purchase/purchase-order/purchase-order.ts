import { Component, HostListener, OnInit, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpErrorResponse } from '@angular/common/http';
import { ActivatedRoute } from '@angular/router';
import { CrudService } from '../../../core/services/crud.service';
import { ProductSearchResult, PurchaseService } from '../../../core/services/purchase.service';
import { UserRightsService } from '../../../core/services/user-rights.service';
import {
  PurchaseOrder,
  PurchaseOrderItem,
  PurchaseOrderPayload,
  PurchaseOrderService,
  SalesRangeProduct,
  ShortBookProduct,
} from '../../../core/services/purchase-order.service';
import { compactDistributorAddress } from '../../../core/utils/distributor-label';

type View = 'list' | 'customise' | 'auto' | 'sales';

interface Distributor {
  id: number;
  name: string;
  address?: string | null;
}

interface ShortBookRow extends ShortBookProduct {
  checked: boolean;
  req_qty: number;
}

interface AutoGroup {
  key: string;
  distributor_name: string;
  distributor_id: number | null;
  products: ShortBookRow[];
  saving: boolean;
  error: string | null;
}

interface SalesRow extends SalesRangeProduct {
  checked: boolean;
  order_qty: number;
}

@Component({
  selector: 'app-purchase-order',
  imports: [CommonModule, FormsModule],
  templateUrl: './purchase-order.html',
  styleUrl: './purchase-order.scss',
})
export class PurchaseOrderPage implements OnInit {
  private readonly svc = inject(PurchaseOrderService);
  private readonly purchaseSvc = inject(PurchaseService);
  private readonly crud = inject(CrudService);
  private readonly rights = inject(UserRightsService);
  private readonly route = inject(ActivatedRoute);

  readonly canEdit = () => this.rights.canEdit('PURCHASE_ORDER');

  readonly view = signal<View>('list');
  readonly toast = signal<string | null>(null);

  // ---- List ----
  readonly orders = signal<PurchaseOrder[]>([]);
  readonly loading = signal(true);
  readonly error = signal<string | null>(null);
  search = '';
  fromDate = '';
  toDate = '';
  readonly typeFilter = signal<'' | 'Customise' | 'Auto' | 'Sales'>('');
  private searchTimer?: ReturnType<typeof setTimeout>;

  readonly kpis = computed(() => {
    const list = this.orders();
    return {
      total: list.length,
      customise: list.filter((o) => o.po_type === 'Customise').length,
      auto: list.filter((o) => o.po_type === 'Auto').length,
      sales: list.filter((o) => o.po_type === 'Sales').length,
      items: list.reduce((s, o) => s + (Number(o.total_items) || 0), 0),
    };
  });

  readonly detailOpen = signal(false);
  readonly detailLoading = signal(false);
  readonly detail = signal<PurchaseOrder | null>(null);
  readonly pdfBusyId = signal<number | null>(null);

  // ---- Distributors (shared by Customise + Auto) ----
  readonly distributors = signal<Distributor[]>([]);
  readonly distributorsLoading = signal(false);
  private distributorsLoaded = false;

  /** Open distributor dropdown: 'cust' (Customise) or an Auto group key. */
  readonly openCombo = signal<string | null>(null);
  readonly distQuery = signal('');
  readonly distActive = signal(0);
  readonly filteredDistributors = computed(() => {
    const q = this.distQuery().trim().toLowerCase();
    const list = this.distributors();
    return (
      q
        ? list.filter((d) => {
            const name = (d.name ?? '').toLowerCase();
            const addr = (d.address ?? '').toLowerCase();
            return name.includes(q) || addr.includes(q);
          })
        : list
    ).slice(0, 200);
  });

  /** Compact address helper for dropdown labels (same as Purchase form). */
  readonly distributorAddr = compactDistributorAddress;

  distributorAddressById(id: number | null | undefined): string | null {
    if (!id) return null;
    return this.distributors().find((d) => d.id === id)?.address ?? null;
  }

  // ---- Customise PO ----
  readonly today = new Date();
  readonly custDist = signal<Distributor | null>(null);
  readonly productQuery = signal('');
  readonly searchResults = signal<ProductSearchResult[]>([]);
  readonly activeResult = signal(-1);
  readonly productOpen = signal(false);
  readonly productSearching = signal(false);
  readonly selectedProduct = signal<ProductSearchResult | null>(null);
  custQty: number | null = null;
  readonly custLines = signal<PurchaseOrderItem[]>([]);
  readonly custSaving = signal(false);
  readonly custError = signal<string | null>(null);
  readonly custTotalQty = computed(() => this.custLines().reduce((s, l) => s + (Number(l.qty) || 0), 0));
  /** Set while editing a saved PO (Customise screen reused). */
  readonly editing = signal<PurchaseOrder | null>(null);
  readonly editLoadingId = signal<number | null>(null);
  private productTimer?: ReturnType<typeof setTimeout>;
  private productReq = 0;

  // ---- Auto-Purchase (Short Book) ----
  readonly shortMonths = signal<string[]>([]);
  readonly shortRows = signal<ShortBookRow[]>([]);
  readonly shortLoading = signal(false);
  readonly shortError = signal<string | null>(null);
  readonly shortFilter = signal('');
  readonly reqDays = signal(0);
  readonly dayOptions = [7, 15, 30, 60];
  readonly autoGroups = signal<AutoGroup[] | null>(null);

  readonly visibleShortRows = computed(() => {
    const q = this.shortFilter().trim().toLowerCase();
    const rows = this.shortRows();
    if (!q) return rows;
    return rows.filter(
      (r) =>
        r.product_name.toLowerCase().includes(q) ||
        (r.distributor_name ?? '').toLowerCase().includes(q) ||
        (r.manufacturer ?? '').toLowerCase().includes(q),
    );
  });
  readonly checkedCount = computed(() => this.shortRows().filter((r) => r.checked).length);
  readonly allChecked = computed(() => {
    const rows = this.visibleShortRows();
    return rows.length > 0 && rows.every((r) => r.checked);
  });

  // ---- Sales-based PO (previous / today sale days) ----
  readonly salesDays = signal(1);
  readonly salesDayOptions = [1, 3, 7, 15, 30];
  readonly salesFrom = signal('');
  readonly salesTo = signal('');
  readonly salesRows = signal<SalesRow[]>([]);
  readonly salesLoading = signal(false);
  readonly salesLoaded = signal(false);
  readonly salesError = signal<string | null>(null);
  readonly salesFilter = signal('');
  readonly salesDist = signal<Distributor | null>(null);
  readonly salesSaving = signal(false);

  readonly visibleSalesRows = computed(() => {
    const q = this.salesFilter().trim().toLowerCase();
    const rows = this.salesRows();
    if (!q) return rows;
    return rows.filter(
      (r) =>
        r.product_name.toLowerCase().includes(q) ||
        (r.manufacturer ?? '').toLowerCase().includes(q) ||
        (r.distributor_name ?? '').toLowerCase().includes(q),
    );
  });
  readonly salesCheckedCount = computed(() => this.salesRows().filter((r) => r.checked).length);
  readonly salesAllChecked = computed(() => {
    const rows = this.visibleSalesRows();
    return rows.length > 0 && rows.every((r) => r.checked);
  });
  readonly salesOrderTotal = computed(() =>
    this.salesRows()
      .filter((r) => r.checked)
      .reduce((s, r) => s + (Number(r.order_qty) || 0), 0),
  );

  ngOnInit(): void {
    this.reload();
    if (this.route.snapshot.queryParamMap.get('auto') === '1') {
      this.openAuto();
    }
  }

  // =================== List ===================

  reload(): void {
    this.loading.set(true);
    this.error.set(null);
    this.svc
      .list({
        search: this.search.trim(),
        from_date: this.fromDate,
        to_date: this.toDate,
        po_type: this.typeFilter(),
      })
      .subscribe({
        next: (rows) => {
          this.orders.set(rows);
          this.loading.set(false);
        },
        error: (err: HttpErrorResponse) => {
          this.error.set(err.error?.message ?? 'Could not load purchase orders.');
          this.loading.set(false);
        },
      });
  }

  onSearchInput(): void {
    clearTimeout(this.searchTimer);
    this.searchTimer = setTimeout(() => this.reload(), 300);
  }

  setType(t: '' | 'Customise' | 'Auto' | 'Sales'): void {
    this.typeFilter.set(t);
    this.reload();
  }

  clearFilters(): void {
    this.search = '';
    this.fromDate = '';
    this.toDate = '';
    this.typeFilter.set('');
    this.reload();
  }

  openDetail(o: PurchaseOrder): void {
    this.detailOpen.set(true);
    this.detailLoading.set(true);
    this.detail.set(null);
    this.svc.get(o.id).subscribe({
      next: (d) => {
        this.detail.set(d);
        this.detailLoading.set(false);
      },
      error: (err: HttpErrorResponse) => {
        this.error.set(err.error?.message ?? 'Could not load purchase order.');
        this.detailLoading.set(false);
        this.detailOpen.set(false);
      },
    });
  }

  closeDetail(): void {
    this.detailOpen.set(false);
    this.detail.set(null);
  }

  openPdf(o: PurchaseOrder): void {
    this.pdfBusyId.set(o.id);
    this.svc.pdfBlob(o.id).subscribe({
      next: (blob) => {
        this.pdfBusyId.set(null);
        window.open(URL.createObjectURL(blob), '_blank');
      },
      error: () => {
        this.pdfBusyId.set(null);
        this.error.set('Could not open the PO PDF.');
      },
    });
  }

  // =================== Distributors ===================

  private ensureDistributors(): void {
    if (this.distributorsLoaded || this.distributorsLoading()) return;
    this.distributorsLoading.set(true);
    this.fetchDistributorPages(1, []);
  }

  private mapDistRow(row: { id: number; name: string; address?: string | null } | Record<string, unknown>): Distributor {
    const r = row as Record<string, unknown>;
    return {
      id: Number(r['id'] ?? 0),
      name: String(r['name'] ?? ''),
      address: r['address'] != null && String(r['address']).trim() !== '' ? String(r['address']) : null,
    };
  }

  private fetchDistributorPages(page: number, acc: Distributor[]): void {
    this.crud.list<{ id: number; name: string; address?: string | null }>('distributors', { page, perPage: 200 }).subscribe({
      next: (r) => {
        const batch = (r.data ?? []).map((row) => this.mapDistRow(row));
        const combined = [...acc, ...batch];
        const total = r.total ?? combined.length;
        if (batch.length > 0 && combined.length < total) {
          this.fetchDistributorPages(page + 1, combined);
          return;
        }
        this.setDistributors(combined);
      },
      error: () => this.setDistributors(acc),
    });
  }

  private setDistributors(list: Distributor[]): void {
    const sorted = list
      .filter((d) => d && d.name)
      .sort((a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: 'base' }));
    this.distributors.set(sorted);
    this.distributorsLoaded = true;
    this.distributorsLoading.set(false);
    this.autoGroups.update((groups) => groups?.map((g) => this.resolveGroupDistributor(g)) ?? null);
    if (this.salesRows().length) {
      this.salesRows.update((rows) => rows.map((r) => this.resolveSalesRowDistributor(r)));
    }
  }

  private findDistributor(name: string): Distributor | undefined {
    const key = name.trim().toLowerCase();
    if (!key) return undefined;
    return this.distributors().find((d) => d.name.trim().toLowerCase() === key);
  }

  @HostListener('document:click')
  onDocumentClick(): void {
    this.openCombo.set(null);
  }

  toggleDistCombo(key: string, ev: Event): void {
    ev.stopPropagation();
    if (this.openCombo() === key) {
      this.openCombo.set(null);
      return;
    }
    this.ensureDistributors();
    this.distQuery.set('');
    this.distActive.set(0);
    this.openCombo.set(key);
    setTimeout(() => document.getElementById('po-dist-search')?.focus(), 0);
  }

  onDistQuery(value: string): void {
    this.distQuery.set(value);
    this.distActive.set(0);
  }

  onDistKeydown(key: string, ev: KeyboardEvent): void {
    const list = this.filteredDistributors();
    if (ev.key === 'Escape') {
      this.openCombo.set(null);
      return;
    }
    if (!list.length) return;
    if (ev.key === 'ArrowDown') {
      ev.preventDefault();
      this.distActive.update((i) => Math.min(list.length - 1, i + 1));
      this.scrollActive('.po-dist-panel');
    } else if (ev.key === 'ArrowUp') {
      ev.preventDefault();
      this.distActive.update((i) => Math.max(0, i - 1));
      this.scrollActive('.po-dist-panel');
    } else if (ev.key === 'Enter') {
      ev.preventDefault();
      const hit = list[this.distActive()];
      if (hit) this.pickDistributor(key, hit);
    }
  }

  pickDistributor(key: string, d: Distributor): void {
    if (key === 'cust') {
      this.custDist.set(d);
      this.custError.set(null);
    } else if (key === 'sales') {
      this.salesDist.set(d);
      this.salesError.set(null);
    } else if (key.startsWith('sales-row:')) {
      const bindingId = Number(key.slice('sales-row:'.length));
      this.setSalesRowDistributor(bindingId, d);
    } else {
      this.patchGroup(key, { distributor_name: d.name, distributor_id: d.id, error: null });
    }
    this.openCombo.set(null);
  }

  clearDistCombo(key: string, ev: Event): void {
    ev.stopPropagation();
    if (key === 'cust') {
      this.custDist.set(null);
    } else if (key === 'sales') {
      this.salesDist.set(null);
    } else if (key.startsWith('sales-row:')) {
      const bindingId = Number(key.slice('sales-row:'.length));
      this.setSalesRowDistributor(bindingId, null);
    }
  }

  clearCustDistributor(ev: Event): void {
    this.clearDistCombo('cust', ev);
  }

  clearSalesDistributor(ev: Event): void {
    this.clearDistCombo('sales', ev);
  }

  salesRowDistKey(bindingId: number): string {
    return `sales-row:${bindingId}`;
  }

  isSalesRowComboOpen(): boolean {
    const key = this.openCombo();
    return !!key && key.startsWith('sales-row:');
  }

  private scrollActive(panelSelector: string): void {
    setTimeout(() => document.querySelector(`${panelSelector} .active-item`)?.scrollIntoView({ block: 'nearest' }), 0);
  }

  // =================== Customise PO ===================

  openCustomise(): void {
    if (!this.canEdit()) return;
    this.ensureDistributors();
    this.editing.set(null);
    this.custDist.set(null);
    this.custLines.set([]);
    this.custError.set(null);
    this.resetProductEntry();
    this.view.set('customise');
  }

  /** Edit a saved PO: add products, change qty, remove lines or switch distributor. */
  openEdit(o: PurchaseOrder): void {
    if (!this.canEdit() || this.editLoadingId() !== null) return;
    this.ensureDistributors();
    const start = (po: PurchaseOrder) => {
      this.editLoadingId.set(null);
      this.closeDetail();
      this.editing.set(po);
      this.custDist.set(
        po.distributor_name
          ? {
              id: po.distributor_id ?? 0,
              name: po.distributor_name,
              address:
                this.distributors().find((d) => d.id === (po.distributor_id ?? 0))?.address ??
                this.findDistributor(po.distributor_name)?.address ??
                null,
            }
          : null,
      );
      this.custLines.set((po.items ?? []).map((it) => ({ ...it, qty: Number(it.qty) || 0 })));
      this.custError.set(null);
      this.resetProductEntry();
      this.view.set('customise');
    };
    if (o.items) {
      start(o);
      return;
    }
    this.editLoadingId.set(o.id);
    this.svc.get(o.id).subscribe({
      next: (po) => start(po),
      error: (err: HttpErrorResponse) => {
        this.editLoadingId.set(null);
        this.error.set(err.error?.message ?? 'Could not load purchase order.');
      },
    });
  }

  onProductInput(value: string): void {
    this.productQuery.set(value);
    this.selectedProduct.set(null);
    clearTimeout(this.productTimer);
    const q = value.trim();
    if (q.length < 2) {
      this.productReq++;
      this.searchResults.set([]);
      this.productSearching.set(false);
      this.productOpen.set(false);
      return;
    }
    this.productOpen.set(true);
    this.productSearching.set(true);
    const req = ++this.productReq;
    this.productTimer = setTimeout(() => {
      this.purchaseSvc.searchProducts(q, 30).subscribe({
        next: (list) => {
          if (req !== this.productReq) return;
          this.searchResults.set(list ?? []);
          this.activeResult.set(list?.length ? 0 : -1);
          this.productSearching.set(false);
        },
        error: () => {
          if (req !== this.productReq) return;
          this.searchResults.set([]);
          this.productSearching.set(false);
        },
      });
    }, 250);
  }

  onProductFocus(): void {
    if (this.productQuery().trim().length >= 2 && !this.selectedProduct()) {
      this.productOpen.set(true);
    }
  }

  onProductKeydown(ev: KeyboardEvent): void {
    if (ev.key === 'Escape') {
      this.productOpen.set(false);
      return;
    }
    const list = this.searchResults();
    if (!this.productOpen() || !list.length) return;
    if (ev.key === 'ArrowDown') {
      ev.preventDefault();
      this.activeResult.update((i) => Math.min(list.length - 1, i + 1));
      this.scrollActive('.po-product-panel');
    } else if (ev.key === 'ArrowUp') {
      ev.preventDefault();
      this.activeResult.update((i) => Math.max(0, i - 1));
      this.scrollActive('.po-product-panel');
    } else if (ev.key === 'Enter') {
      ev.preventDefault();
      const hit = list[this.activeResult()];
      if (hit) this.pickProduct(hit);
    }
  }

  pickProduct(p: ProductSearchResult): void {
    this.selectedProduct.set(p);
    this.productQuery.set(p.name);
    this.productOpen.set(false);
    this.activeResult.set(-1);
    this.custError.set(null);
    setTimeout(() => document.getElementById('po-cust-qty')?.focus(), 0);
  }

  closeProductResults(): void {
    setTimeout(() => this.productOpen.set(false), 150);
  }

  clearProduct(): void {
    this.resetProductEntry();
    setTimeout(() => document.getElementById('po-cust-product')?.focus(), 0);
  }

  addCustomLine(): void {
    const p = this.selectedProduct();
    const qty = Number(this.custQty) || 0;
    if (!p) {
      this.custError.set('Select a product from the search list.');
      return;
    }
    if (qty <= 0) {
      this.custError.set('Enter a qty greater than 0.');
      setTimeout(() => document.getElementById('po-cust-qty')?.focus(), 0);
      return;
    }
    this.custError.set(null);
    const existing = this.custLines().findIndex((l) => l.master_product_id === p.id);
    if (existing >= 0) {
      this.custLines.update((lines) =>
        lines.map((l, i) => (i === existing ? { ...l, qty: (Number(l.qty) || 0) + qty } : l)),
      );
    } else {
      this.custLines.update((lines) => [
        ...lines,
        {
          master_product_id: p.id,
          product_name: p.name,
          pack_size: p.pack_size ?? '',
          product_category: p.product_category ?? '',
          dosage_form: p.dosage_form ?? '',
          mrp: Number(p.mrp) || 0,
          hsn_code: p.hsn_code ?? '',
          manufacturer: p.manufacturer ?? '',
          generic_name: p.generic_name ?? '',
          qty,
        },
      ]);
    }
    this.resetProductEntry();
    setTimeout(() => document.getElementById('po-cust-product')?.focus(), 0);
  }

  setLineQty(index: number, value: string | number): void {
    const qty = Math.max(0, Number(value) || 0);
    this.custLines.update((lines) => lines.map((l, i) => (i === index ? { ...l, qty } : l)));
  }

  removeCustomLine(index: number): void {
    this.custLines.update((lines) => lines.filter((_, i) => i !== index));
  }

  private resetProductEntry(): void {
    this.productReq++;
    clearTimeout(this.productTimer);
    this.productQuery.set('');
    this.searchResults.set([]);
    this.selectedProduct.set(null);
    this.productOpen.set(false);
    this.productSearching.set(false);
    this.activeResult.set(-1);
    this.custQty = null;
  }

  saveCustomise(): void {
    const dist = this.custDist();
    if (!dist) {
      this.custError.set('Please select a distributor.');
      return;
    }
    if (!this.custLines().length) {
      this.custError.set('Add at least one product.');
      return;
    }
    const zero = this.custLines().find((l) => !(Number(l.qty) > 0));
    if (zero) {
      this.custError.set(`Enter qty for ${zero.product_name}.`);
      return;
    }
    this.custSaving.set(true);
    this.custError.set(null);
    const editing = this.editing();
    const payload: PurchaseOrderPayload = {
      po_type: editing?.po_type ?? 'Customise',
      distributor_id: dist.id || null,
      distributor_name: dist.name,
      items: this.custLines(),
    };
    const req = editing ? this.svc.update(editing.id, payload) : this.svc.create(payload);
    req.subscribe({
      next: (res) => {
        this.custSaving.set(false);
        const no = res.data?.po_no ?? '';
        this.showToast(editing ? `Purchase Order updated successfully. PO NO: ${no}` : `Purchase Order saved successfully. PO NO: ${no}`);
        this.backToList();
      },
      error: (err: HttpErrorResponse) => {
        this.custSaving.set(false);
        this.custError.set(this.errorText(err, 'Could not save purchase order.'));
      },
    });
  }

  // =================== Auto-Purchase (Short Book) ===================

  openAuto(): void {
    if (!this.canEdit()) return;
    this.ensureDistributors();
    this.view.set('auto');
    this.autoGroups.set(null);
    this.reqDays.set(0);
    this.shortFilter.set('');
    this.shortLoading.set(true);
    this.shortError.set(null);
    this.svc.shortBook().subscribe({
      next: (sb) => {
        this.shortMonths.set(sb.months ?? []);
        this.shortRows.set((sb.products ?? []).map((p) => ({ ...p, checked: false, req_qty: 0 })));
        this.shortLoading.set(false);
      },
      error: (err: HttpErrorResponse) => {
        this.shortError.set(err.error?.message ?? 'Could not load low-stock products.');
        this.shortLoading.set(false);
      },
    });
  }

  monthLabel(ym: string): string {
    const [y, m] = ym.split('-').map(Number);
    if (!y || !m) return ym;
    return new Date(y, m - 1, 1).toLocaleString('en-IN', { month: 'short' }) + ' ' + String(y).slice(-2);
  }

  /** Retail: req qty = (4-month sold ÷ (months × 30)) × days. */
  applyDays(days: number): void {
    this.reqDays.set(days);
    if (!days) return;
    const months = Math.max(1, this.shortMonths().length);
    this.shortRows.update((rows) =>
      rows.map((r) => ({
        ...r,
        req_qty: r.total_sold_qty > 0 ? Math.round((r.total_sold_qty / (months * 30)) * days) : 0,
      })),
    );
  }

  toggleRow(bindingId: number, checked: boolean): void {
    this.shortRows.update((rows) => rows.map((r) => (r.product_binding_id === bindingId ? { ...r, checked } : r)));
  }

  toggleAll(checked: boolean): void {
    const visible = new Set(this.visibleShortRows().map((r) => r.product_binding_id));
    this.shortRows.update((rows) => rows.map((r) => (visible.has(r.product_binding_id) ? { ...r, checked } : r)));
  }

  setReqQty(bindingId: number, value: string | number): void {
    const qty = Math.max(0, Number(value) || 0);
    this.shortRows.update((rows) =>
      rows.map((r) => (r.product_binding_id === bindingId ? { ...r, req_qty: qty, checked: qty > 0 || r.checked } : r)),
    );
  }

  makePo(): void {
    const selected = this.shortRows().filter((r) => r.checked);
    if (!selected.length) {
      this.shortError.set('Please select at least one product.');
      return;
    }
    const zero = selected.find((r) => !(r.req_qty > 0));
    if (zero) {
      this.shortError.set(`Enter required qty for ${zero.product_name}.`);
      return;
    }
    this.shortError.set(null);
    const map = new Map<string, AutoGroup>();
    for (const r of selected) {
      const name = r.distributor_name?.trim() || 'Unknown';
      const key = r.distributor_id ? `id:${r.distributor_id}` : `name:${name.toLowerCase()}`;
      if (!map.has(key)) {
        map.set(key, {
          key,
          distributor_name: name === 'Unknown' ? '' : name,
          distributor_id: r.distributor_id,
          products: [],
          saving: false,
          error: null,
        });
      }
      map.get(key)!.products.push(r);
    }
    this.autoGroups.set([...map.values()].map((g) => this.resolveGroupDistributor(g)));
  }

  private resolveGroupDistributor(g: AutoGroup): AutoGroup {
    if (g.distributor_id) {
      const byId = this.distributors().find((d) => d.id === g.distributor_id);
      if (byId) return { ...g, distributor_name: byId.name };
      return g;
    }
    const byName = this.findDistributor(g.distributor_name);
    return byName ? { ...g, distributor_id: byName.id, distributor_name: byName.name } : g;
  }

  backToShortBook(): void {
    this.autoGroups.set(null);
    this.openCombo.set(null);
  }

  groupTotalQty(g: AutoGroup): number {
    return g.products.reduce((s, p) => s + (Number(p.req_qty) || 0), 0);
  }

  saveGroup(g: AutoGroup): void {
    const dist = g.distributor_id
      ? this.distributors().find((d) => d.id === g.distributor_id) ?? { id: g.distributor_id, name: g.distributor_name }
      : null;
    if (!dist) {
      this.patchGroup(g.key, { error: 'Please select a distributor.' });
      return;
    }
    this.patchGroup(g.key, { saving: true, error: null });
    const items: PurchaseOrderItem[] = g.products.map((p) => ({
      product_binding_id: p.product_binding_id,
      master_product_id: p.master_product_id,
      product_name: p.product_name,
      pack_size: p.pack_size,
      product_category: p.product_category,
      mrp: p.mrp,
      hsn_code: p.hsn_code,
      manufacturer: p.manufacturer,
      qty: p.req_qty,
    }));
    this.svc.create({ po_type: 'Auto', distributor_id: dist.id, distributor_name: dist.name, items }).subscribe({
      next: (res) => {
        this.showToast(`Purchase Order saved successfully. PO NO: ${res.data?.po_no ?? ''}`);
        const savedIds = new Set(g.products.map((p) => p.product_binding_id));
        this.shortRows.update((rows) => rows.filter((r) => !savedIds.has(r.product_binding_id)));
        const left = (this.autoGroups() ?? []).filter((x) => x.key !== g.key);
        if (left.length) {
          this.autoGroups.set(left);
        } else {
          this.backToList();
        }
      },
      error: (err: HttpErrorResponse) => {
        this.patchGroup(g.key, { saving: false, error: this.errorText(err, 'Could not save purchase order.') });
      },
    });
  }

  private patchGroup(key: string, patch: Partial<AutoGroup>): void {
    this.autoGroups.update((groups) => groups?.map((x) => (x.key === key ? { ...x, ...patch } : x)) ?? null);
  }

  // =================== Sales-based PO ===================

  openSales(): void {
    if (!this.canEdit()) return;
    this.ensureDistributors();
    this.salesDays.set(1);
    this.salesFrom.set('');
    this.salesTo.set('');
    this.salesRows.set([]);
    this.salesLoaded.set(false);
    this.salesLoading.set(false);
    this.salesError.set(null);
    this.salesFilter.set('');
    this.salesDist.set(null);
    this.salesSaving.set(false);
    this.view.set('sales');
  }

  setSalesDays(days: number): void {
    const n = Math.max(1, Math.min(365, Math.floor(Number(days) || 1)));
    this.salesDays.set(n);
  }

  loadSalesRange(): void {
    const days = Math.max(1, Math.min(365, this.salesDays() || 1));
    this.salesDays.set(days);
    this.salesLoading.set(true);
    this.salesError.set(null);
    this.salesLoaded.set(false);
    this.svc.salesRange(days).subscribe({
      next: (data) => {
        this.salesFrom.set(data.from_date ?? '');
        this.salesTo.set(data.to_date ?? '');
        this.salesRows.set(
          (data.products ?? []).map((p) => this.resolveSalesRowDistributor({
            ...p,
            checked: false,
            order_qty: Math.max(0, Number(p.suggested_qty) || 0),
          })),
        );
        this.salesLoading.set(false);
        this.salesLoaded.set(true);
      },
      error: (err: HttpErrorResponse) => {
        this.salesError.set(err.error?.message ?? 'Could not load sold products for the selected days.');
        this.salesLoading.set(false);
        this.salesLoaded.set(false);
        this.salesRows.set([]);
      },
    });
  }

  toggleSalesRow(bindingId: number, checked: boolean): void {
    this.salesRows.update((rows) =>
      rows.map((r) => (r.product_binding_id === bindingId ? { ...r, checked } : r)),
    );
  }

  toggleSalesAll(checked: boolean): void {
    const visible = new Set(this.visibleSalesRows().map((r) => r.product_binding_id));
    this.salesRows.update((rows) =>
      rows.map((r) => (visible.has(r.product_binding_id) ? { ...r, checked } : r)),
    );
  }

  setSalesOrderQty(bindingId: number, value: string | number): void {
    const qty = Math.max(0, Number(value) || 0);
    this.salesRows.update((rows) =>
      rows.map((r) =>
        r.product_binding_id === bindingId ? { ...r, order_qty: qty, checked: qty > 0 || r.checked } : r,
      ),
    );
  }

  setSalesRowDistributor(bindingId: number, dist: Distributor | null): void {
    this.salesRows.update((rows) =>
      rows.map((r) =>
        r.product_binding_id === bindingId
          ? {
              ...r,
              distributor_id: dist?.id ?? null,
              distributor_name: dist?.name ?? '',
              checked: dist ? true : r.checked,
            }
          : r,
      ),
    );
    this.salesError.set(null);
  }

  private resolveSalesRowDistributor(row: SalesRow): SalesRow {
    if (row.distributor_id) {
      const byId = this.distributors().find((d) => d.id === row.distributor_id);
      if (byId) return { ...row, distributor_name: byId.name };
      return row;
    }
    const byName = this.findDistributor(row.distributor_name ?? '');
    return byName
      ? { ...row, distributor_id: byName.id, distributor_name: byName.name }
      : row;
  }

  saveSalesPo(): void {
    const selected = this.salesRows().filter((r) => r.checked);
    if (!selected.length) {
      this.salesError.set('Please select at least one product to order.');
      return;
    }
    const zero = selected.find((r) => !(Number(r.order_qty) > 0));
    if (zero) {
      this.salesError.set(`Enter order qty for ${zero.product_name}.`);
      return;
    }

    const headerDist = this.salesDist();
    type DistGroup = { distributor_id: number; distributor_name: string; products: SalesRow[] };
    const groups: DistGroup[] = [];

    if (headerDist) {
      groups.push({
        distributor_id: headerDist.id,
        distributor_name: headerDist.name,
        products: selected,
      });
    } else {
      const missing = selected.find((r) => !(r.distributor_id && r.distributor_id > 0) && !r.distributor_name?.trim());
      if (missing) {
        this.salesError.set(
          `Select Last Distributor for ${missing.product_name}, or choose one distributor for all products above.`,
        );
        return;
      }
      const map = new Map<string, DistGroup>();
      for (const r of selected) {
        const resolved = this.resolveSalesRowDistributor(r);
        const name = resolved.distributor_name?.trim() || '';
        const id = resolved.distributor_id && resolved.distributor_id > 0 ? resolved.distributor_id : 0;
        if (!id && !name) {
          this.salesError.set(
            `Select Last Distributor for ${r.product_name}, or choose one distributor for all products above.`,
          );
          return;
        }
        // Prefer master id when available; otherwise fail — API needs a real distributor.
        if (!id) {
          this.salesError.set(
            `“${name}” for ${r.product_name} is not in Distributor Master. Pick a distributor from the list.`,
          );
          return;
        }
        const key = `id:${id}`;
        if (!map.has(key)) {
          map.set(key, { distributor_id: id, distributor_name: name, products: [] });
        }
        map.get(key)!.products.push(resolved);
      }
      groups.push(...map.values());
    }

    this.salesSaving.set(true);
    this.salesError.set(null);
    this.createSalesGroups(groups, 0, []);
  }

  private createSalesGroups(
    groups: { distributor_id: number; distributor_name: string; products: SalesRow[] }[],
    index: number,
    saved: { id: number; po_no: string }[],
  ): void {
    if (index >= groups.length) {
      this.salesSaving.set(false);
      const nos = saved.map((s) => s.po_no).filter(Boolean).join(', ');
      this.showToast(
        saved.length === 1
          ? `Purchase Order saved successfully. PO NO: ${nos}`
          : `${saved.length} purchase orders saved. PO NO: ${nos}`,
      );
      for (const s of saved) {
        this.svc.pdfBlob(s.id).subscribe({
          next: (blob) => window.open(URL.createObjectURL(blob), '_blank'),
          error: () => undefined,
        });
      }
      this.backToList();
      return;
    }

    const g = groups[index];
    const items: PurchaseOrderItem[] = g.products.map((p) => ({
      product_binding_id: p.product_binding_id,
      master_product_id: p.master_product_id,
      product_name: p.product_name,
      pack_size: p.pack_size,
      product_category: p.product_category,
      mrp: p.mrp,
      hsn_code: p.hsn_code,
      manufacturer: p.manufacturer,
      qty: p.order_qty,
    }));

    this.svc
      .create({
        po_type: 'Sales',
        distributor_id: g.distributor_id,
        distributor_name: g.distributor_name,
        items,
      })
      .subscribe({
        next: (res) => {
          const id = res.data?.id;
          const po_no = res.data?.po_no ?? '';
          if (id) saved.push({ id, po_no });
          this.createSalesGroups(groups, index + 1, saved);
        },
        error: (err: HttpErrorResponse) => {
          this.salesSaving.set(false);
          const done = saved.length
            ? ` Saved ${saved.length} PO(s) first (${saved.map((s) => s.po_no).join(', ')}).`
            : '';
          this.salesError.set(this.errorText(err, 'Could not save purchase order.') + done);
        },
      });
  }

  // =================== Shared ===================

  backToList(): void {
    this.view.set('list');
    this.editing.set(null);
    this.autoGroups.set(null);
    this.openCombo.set(null);
    this.reload();
  }

  typeBadge(t: string): string {
    if (t === 'Auto') return 'badge-auto';
    if (t === 'Sales') return 'badge-sales';
    return 'badge-custom';
  }

  private showToast(msg: string): void {
    this.toast.set(msg);
    setTimeout(() => this.toast.set(null), 4000);
  }

  private errorText(err: HttpErrorResponse, fallback: string): string {
    const errors = err.error?.errors as Record<string, string[]> | undefined;
    const first = errors ? Object.values(errors).flat()[0] : null;
    return first || err.error?.message || fallback;
  }
}
