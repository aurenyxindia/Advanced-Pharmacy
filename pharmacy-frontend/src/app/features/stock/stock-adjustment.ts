import {
  Component,
  ElementRef,
  HostListener,
  OnInit,
  inject,
  signal,
  computed,
  viewChild,
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { StockAdjustmentBatch, StockService } from '../../core/services/stock.service';
import { UserRightsService } from '../../core/services/user-rights.service';
import { MetaService } from '../../core/services/meta.service';

type PackOpt = { pack: string; units: number; form: string; label?: string };

interface AdjustRow extends StockAdjustmentBatch {
  physical_unit_qty: number;
  physical_qty: number;
  reason: string;
  /** Snapshot at load — used to detect pack edits. */
  original_pack_size: string;
  original_units_per_pack: number;
}

@Component({
  selector: 'app-stock-adjustment',
  imports: [CommonModule, FormsModule, RouterLink],
  templateUrl: './stock-adjustment.html',
  styleUrl: './stock-adjustment.scss',
})
export class StockAdjustment implements OnInit {
  private readonly stockSvc = inject(StockService);
  private readonly rights = inject(UserRightsService);
  private readonly meta = inject(MetaService);
  private readonly reasonBox = viewChild<ElementRef<HTMLTextAreaElement>>('reasonBox');

  readonly reasonMax = 255;
  readonly quickReasons = [
    'Physical count mismatch',
    'Damaged / broken',
    'Expired stock write-off',
    'Theft / lost',
    'Wrong entry correction',
    'Free goods received',
    'Pack size correction',
  ];
  readonly reasonTouched = signal(false);
  readonly canViewAudit = () => this.rights.canView('ALL_REPORTS');

  readonly adjustmentTypes = [
    { value: 'PHYSICAL_COUNT', label: 'Physical count' },
    { value: 'DAMAGE', label: 'Damage' },
    { value: 'EXPIRY', label: 'Expiry write-off' },
    { value: 'THEFT', label: 'Theft' },
    { value: 'OTHER', label: 'Other' },
  ];

  readonly adjustmentType = signal('PHYSICAL_COUNT');
  readonly remarks = signal('');
  readonly searchText = signal('');
  readonly rows = signal<AdjustRow[]>([]);
  readonly loading = signal(false);
  readonly saving = signal(false);
  readonly error = signal<string | null>(null);
  readonly toast = signal<string | null>(null);

  readonly packSizeOptions = signal<PackOpt[]>([]);
  readonly openPackBatchId = signal<number | null>(null);
  readonly packQuery = signal('');
  readonly filteredPackSizes = signal<PackOpt[]>([]);
  /** Fixed overlay style so pack list is not clipped by table overflow. */
  readonly packDropStyle = signal<Record<string, string>>({});
  private packBlurTimer?: ReturnType<typeof setTimeout>;
  private packAnchorEl: HTMLElement | null = null;

  readonly changedCount = computed(
    () => this.rows().filter((r) => this.isChanged(r)).length,
  );
  readonly missingReasonCount = computed(
    () => this.rows().filter((r) => this.isChanged(r) && this.needsReason(r)).length,
  );

  isChanged(row: AdjustRow): boolean {
    return this.unitDiff(row) !== 0 || this.packDiff(row) !== 0 || this.packChanged(row);
  }

  packChanged(row: AdjustRow): boolean {
    const pack = (row.pack_size || '').trim();
    const orig = (row.original_pack_size || '').trim();
    const upp = this.unitsPerPack(row);
    return this.packKey(pack) !== this.packKey(orig) || upp !== row.original_units_per_pack;
  }

  /** A changed row is covered by its own reason or the common reason. */
  needsReason(row: AdjustRow): boolean {
    return !(row.reason || '').trim() && !this.remarks().trim();
  }

  pickReason(text: string): void {
    this.remarks.set(text);
    this.reasonTouched.set(true);
    const el = this.reasonBox()?.nativeElement;
    if (el) {
      setTimeout(() => {
        el.focus();
        el.setSelectionRange(el.value.length, el.value.length);
      });
    }
  }

  ngOnInit(): void {
    this.meta.loadPackSizes().subscribe({
      next: (list) => this.packSizeOptions.set(list ?? []),
      error: () => this.packSizeOptions.set([]),
    });
    this.load();
  }

  @HostListener('document:mousedown', ['$event'])
  onDocumentMouseDown(ev: MouseEvent): void {
    const t = ev.target as HTMLElement | null;
    if (!t?.closest('.pack-select-wrap') && !t?.closest('.sa-pack-float')) {
      this.closePackDropdown();
    }
  }

  @HostListener('window:resize')
  onWindowResize(): void {
    if (this.openPackBatchId() != null) this.repositionPackDrop();
  }

  @HostListener('window:scroll')
  onWindowScroll(): void {
    if (this.openPackBatchId() != null) this.closePackDropdown();
  }

  private closePackDropdown(): void {
    this.openPackBatchId.set(null);
    this.packDropStyle.set({});
    this.packAnchorEl = null;
  }

  private repositionPackDrop(): void {
    if (!this.packAnchorEl) return;
    this.positionPackDrop(this.packAnchorEl);
  }

  private positionPackDrop(anchor: HTMLElement): void {
    const r = anchor.getBoundingClientRect();
    const spaceBelow = window.innerHeight - r.bottom;
    const spaceAbove = r.top;
    const maxH = 220;
    const openUp = spaceBelow < 160 && spaceAbove > spaceBelow;
    const width = Math.max(r.width, 160);
    if (openUp) {
      this.packDropStyle.set({
        position: 'fixed',
        left: `${Math.min(r.left, window.innerWidth - width - 8)}px`,
        width: `${width}px`,
        bottom: `${window.innerHeight - r.top + 4}px`,
        top: 'auto',
        maxHeight: `${Math.min(maxH, Math.max(80, spaceAbove - 8))}px`,
        zIndex: '3000',
      });
    } else {
      this.packDropStyle.set({
        position: 'fixed',
        left: `${Math.min(r.left, window.innerWidth - width - 8)}px`,
        width: `${width}px`,
        top: `${r.bottom + 4}px`,
        bottom: 'auto',
        maxHeight: `${Math.min(maxH, Math.max(80, spaceBelow - 8))}px`,
        zIndex: '3000',
      });
    }
  }

  load(): void {
    this.loading.set(true);
    this.error.set(null);
    this.openPackBatchId.set(null);
    this.stockSvc.adjustmentBatches(this.searchText()).subscribe({
      next: (list) => {
        this.rows.set(
          (list ?? []).map((p) => {
            const units = Number(p.system_unit_qty ?? p.current_unit_qty ?? 0) || 0;
            const upp = Math.max(1, Number(p.units_per_pack) || this.parseUnitsFromPack(p.pack_size) || 1);
            const packs = Number(p.system_qty ?? p.current_qty ?? 0) || Math.floor(units / upp);
            const pack = (p.pack_size || '').trim();
            return {
              ...p,
              pack_size: pack,
              system_unit_qty: units,
              system_qty: packs,
              units_per_pack: upp,
              physical_unit_qty: units,
              physical_qty: packs,
              reason: '',
              original_pack_size: pack,
              original_units_per_pack: upp,
            };
          }),
        );
        this.loading.set(false);
      },
      error: (err: HttpErrorResponse) => {
        this.rows.set([]);
        this.loading.set(false);
        this.error.set(err.error?.message || 'Failed to load stock for adjustment.');
      },
    });
  }

  onSearch(): void {
    this.load();
  }

  unitsPerPack(row: AdjustRow): number {
    return Math.max(1, Number(row.units_per_pack) || this.parseUnitsFromPack(row.pack_size) || 1);
  }

  private parseUnitsFromPack(packSize: string | null | undefined): number {
    if (!packSize) return 1;
    const s = packSize.trim();
    const lower = s.toLowerCase();
    if (/(tab|cap)/i.test(s) || /1\s*[x*]/i.test(s)) {
      const match = s.match(/(?:1\s*[x*]\s*)?(\d+)|(\d+)\s*Tab|\s*(\d+)\s*Cap/i) || s.match(/(\d+)/);
      if (match) {
        const n = parseInt(match[1] || match[2] || match[3] || match[0], 10);
        return n > 0 ? n : 1;
      }
    }
    if (/(ml|gm|g|kg|ltr|l|nos|no\.)\b/i.test(lower)) return 1;
    return 1;
  }

  private packKey(value: string): string {
    return value.trim().toUpperCase().replace(/\s+/g, ' ');
  }

  private normalizePack(value: string): string {
    return value.trim().toUpperCase().replace(/\s+/g, ' ');
  }

  packLabel(p: PackOpt): string {
    return (p.label || p.pack || '').trim() || p.pack;
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

  linePackDisplay(row: AdjustRow): string {
    if (this.openPackBatchId() === row.batch_id) return this.packQuery();
    return row.pack_size || '';
  }

  openPackDropdown(row: AdjustRow, ev?: Event): void {
    if (this.packBlurTimer) {
      clearTimeout(this.packBlurTimer);
      this.packBlurTimer = undefined;
    }
    this.openPackBatchId.set(row.batch_id);
    const pack = (row.pack_size || '').trim();
    const match = this.findPackOption(pack);
    this.packQuery.set(match ? this.packLabel(match) : pack);
    this.filteredPackSizes.set(this.filterPackSizeList(''));
    const el =
      (ev?.target as HTMLElement | null) ??
      (document.querySelector(`[data-pack-batch="${row.batch_id}"]`) as HTMLElement | null);
    if (el) {
      this.packAnchorEl = el;
      this.positionPackDrop(el);
    }
  }

  onPackSearchInput(row: AdjustRow, value: string): void {
    this.openPackBatchId.set(row.batch_id);
    this.packQuery.set(value);
    this.filteredPackSizes.set(this.filterPackSizeList(value));
    this.repositionPackDrop();
  }

  onPackTableScroll(): void {
    if (this.openPackBatchId() != null) this.closePackDropdown();
  }

  selectPackSize(row: AdjustRow, p: PackOpt): void {
    if (this.packBlurTimer) {
      clearTimeout(this.packBlurTimer);
      this.packBlurTimer = undefined;
    }
    const pack = this.normalizePack(p.pack);
    this.packQuery.set(this.packLabel(p));
    this.applyPackChange(row, pack, Math.max(1, Number(p.units) || 1));
    this.closePackDropdown();
  }

  onPackSearchBlur(row: AdjustRow): void {
    this.packBlurTimer = setTimeout(() => {
      if (this.openPackBatchId() === row.batch_id) {
        this.closePackDropdown();
      }
      const typed = this.packQuery().trim();
      if (typed) {
        this.applyPackFromSearch(row, typed);
      }
      this.packBlurTimer = undefined;
    }, 180);
  }

  /** Active row for the floating pack panel. */
  openPackRow(): AdjustRow | null {
    const id = this.openPackBatchId();
    if (id == null) return null;
    return this.rows().find((r) => r.batch_id === id) ?? null;
  }

  private applyPackFromSearch(row: AdjustRow, typed: string): void {
    const current = (row.pack_size || '').trim();
    if (current && this.packKey(current) === this.packKey(typed)) return;
    const found = this.findPackOption(typed);
    if (found) {
      this.applyPackChange(row, this.normalizePack(found.pack), Math.max(1, Number(found.units) || 1));
      return;
    }
    const pack = this.normalizePack(typed);
    this.applyPackChange(row, pack, this.parseUnitsFromPack(pack) || this.unitsPerPack(row));
    this.meta.parsePack(pack).subscribe({
      next: (parsed) => {
        if (!parsed?.pack) return;
        const master = this.findPackOption(parsed.pack);
        this.applyPackChange(
          row,
          this.normalizePack(master ? master.pack : parsed.pack),
          master?.units || parsed.units || this.parseUnitsFromPack(parsed.pack) || 1,
        );
      },
    });
  }

  /**
   * Pack size change: keep pack counts, recalculate units = packs × new U/Pk
   * (e.g. 16 packs × 1x2 → 32 units). Save then adjusts inventory so Sales sees new units.
   * Syncs all rows that share the same product binding.
   */
  private applyPackChange(row: AdjustRow, pack: string, units: number): void {
    const newUpp = Math.max(1, units || 1);
    const oldUpp = this.unitsPerPack(row);
    const bindingId = row.product_binding_id;
    this.rows.update((list) =>
      list.map((r) => {
        if (r.product_binding_id !== bindingId && r.batch_id !== row.batch_id) return r;
        const rowOldUpp = Math.max(1, Number(r.units_per_pack) || oldUpp || 1);
        // Prefer existing pack counts; fall back from units ÷ old U/Pk.
        const sysPacks = Math.max(
          0,
          Number(r.system_qty) || Math.floor((Number(r.system_unit_qty) || 0) / rowOldUpp),
        );
        const physPacks = Math.max(
          0,
          Number(r.physical_qty) || Math.floor((Number(r.physical_unit_qty) || 0) / rowOldUpp),
        );
        return {
          ...r,
          pack_size: pack,
          units_per_pack: newUpp,
          system_qty: sysPacks,
          system_unit_qty: sysPacks * newUpp,
          physical_qty: physPacks,
          physical_unit_qty: physPacks * newUpp,
        };
      }),
    );
  }

  onPhysicalUnitsChange(row: AdjustRow, value: number | string): void {
    const u = this.unitsPerPack(row);
    const units = Math.max(0, Math.trunc(Number(value) || 0));
    this.patchRow(row.batch_id, {
      physical_unit_qty: units,
      physical_qty: Math.floor(units / u),
    });
  }

  onPhysicalPacksChange(row: AdjustRow, value: number | string): void {
    const u = this.unitsPerPack(row);
    const packs = Math.max(0, Math.trunc(Number(value) || 0));
    this.patchRow(row.batch_id, {
      physical_qty: packs,
      physical_unit_qty: packs * u,
    });
  }

  onReasonChange(row: AdjustRow, value: string): void {
    this.patchRow(row.batch_id, { reason: value });
  }

  private patchRow(batchId: number, patch: Partial<AdjustRow>): void {
    this.rows.update((list) =>
      list.map((r) => (r.batch_id === batchId ? { ...r, ...patch } : r)),
    );
  }

  unitDiff(row: AdjustRow): number {
    return (row.physical_unit_qty || 0) - (row.system_unit_qty || 0);
  }

  packDiff(row: AdjustRow): number {
    return (row.physical_qty || 0) - (row.system_qty || 0);
  }

  save(): void {
    this.reasonTouched.set(true);
    if (this.changedCount() > 0 && this.missingReasonCount() > 0) {
      this.error.set('Reason is required. Enter a reason before saving the adjustment.');
      this.reasonBox()?.nativeElement.focus();
      return;
    }
    const lines = this.rows()
      .filter((r) => this.isChanged(r))
      .map((r) => ({
        batch_id: r.batch_id,
        product_binding_id: r.product_binding_id,
        system_qty: r.system_qty,
        system_unit_qty: r.system_unit_qty,
        physical_qty: r.physical_qty,
        physical_unit_qty: r.physical_unit_qty,
        pack_size: (r.pack_size || '').trim(),
        units_per_pack: this.unitsPerPack(r),
        pack_changed: this.packChanged(r),
        reason: (r.reason || this.remarks() || (this.packChanged(r) ? 'Pack size correction' : '')).trim(),
      }));

    if (lines.length === 0) {
      this.error.set('No changes to save. Adjust pack size or physical qty where it differs from system.');
      return;
    }

    this.saving.set(true);
    this.error.set(null);
    this.stockSvc
      .adjustmentSave({
        adjustment_type: this.adjustmentType(),
        remarks: this.remarks().trim(),
        lines,
      })
      .subscribe({
        next: (res) => {
          this.saving.set(false);
          const data = res.data;
          const ref = data?.adjustment_no || '';
          const errs = data?.errors ?? [];
          if (errs.length) {
            this.error.set(`Saved ${ref} with warnings: ${errs.join('; ')}`);
          } else {
            this.toast.set(`Adjustment saved: ${ref}`);
            setTimeout(() => this.toast.set(null), 4000);
            this.remarks.set('');
            this.reasonTouched.set(false);
          }
          this.load();
        },
        error: (err: HttpErrorResponse) => {
          this.saving.set(false);
          const field = err.error?.errors ? (Object.values(err.error.errors)[0] as string[])?.[0] : null;
          this.error.set(field || err.error?.message || 'Failed to save adjustment.');
        },
      });
  }
}
