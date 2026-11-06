import { Component, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import {
  AdminRegistrationsService,
  PlatformPharmacy,
  SubscriptionPlan,
} from './admin-registrations.service';

@Component({
  selector: 'app-admin-page',
  imports: [CommonModule, FormsModule],
  templateUrl: './admin-page.html',
  styleUrl: './admin-page.scss',
})
export class AdminPage implements OnInit {
  private readonly svc = inject(AdminRegistrationsService);

  readonly tab = signal<'pharmacies' | 'plans'>('pharmacies');
  readonly loading = signal(true);
  readonly plansLoading = signal(false);
  readonly togglingId = signal<number | null>(null);
  readonly savingCode = signal<string | null>(null);
  readonly error = signal('');
  readonly success = signal('');
  readonly rows = signal<PlatformPharmacy[]>([]);
  readonly plans = signal<SubscriptionPlan[]>([]);
  readonly filter = signal('');
  readonly showAddPlan = signal(false);
  readonly creatingPlan = signal(false);
  newPlan: {
    code: string;
    name: string;
    months: number;
    monthly_rate: number;
    discount_pct: number;
    gst_pct: number;
    lifetime_base: number | null;
    is_active: boolean;
  } = this.emptyNewPlan();

  ngOnInit(): void {
    this.reload();
  }

  private emptyNewPlan() {
    return {
      code: 'test_1rs',
      name: 'Test ₹1',
      months: 1,
      monthly_rate: 1,
      discount_pct: 0,
      gst_pct: 0,
      lifetime_base: null as number | null,
      is_active: true,
    };
  }

  openAddPlan(): void {
    this.newPlan = this.emptyNewPlan();
    this.showAddPlan.set(true);
    this.error.set('');
    this.success.set('');
  }

  cancelAddPlan(): void {
    this.showAddPlan.set(false);
    this.creatingPlan.set(false);
  }

  createPlanPreviewPayable(): number {
    return this.previewPayable({
      ...this.newPlan,
      id: 0,
      is_lifetime: Number(this.newPlan.months) <= 0,
      sort_order: 0,
      base_amount: 0,
      discount_amount: 0,
      gst_amount: 0,
      payable: 0,
      currency: 'INR',
      lifetime_base: this.newPlan.lifetime_base,
    } as SubscriptionPlan);
  }

  submitNewPlan(): void {
    const code = (this.newPlan.code || '').trim().toLowerCase().replace(/[^a-z0-9_]+/g, '_').replace(/^_+|_+$/g, '');
    const name = (this.newPlan.name || '').trim();
    const months = Number(this.newPlan.months);
    if (!code) {
      this.error.set('Plan code is required (e.g. test_1rs).');
      return;
    }
    if (!name) {
      this.error.set('Plan name is required.');
      return;
    }
    if (Number.isNaN(months) || months < 0) {
      this.error.set('Months must be 0 (lifetime) or a positive number.');
      return;
    }

    this.creatingPlan.set(true);
    this.error.set('');
    this.success.set('');
    this.svc
      .createPlan({
        code,
        name,
        months,
        monthly_rate: Number(this.newPlan.monthly_rate) || 0,
        discount_pct: Number(this.newPlan.discount_pct) || 0,
        gst_pct: Number(this.newPlan.gst_pct) || 0,
        lifetime_base: months <= 0 ? Number(this.newPlan.lifetime_base) || 0 : null,
        is_active: !!this.newPlan.is_active,
      })
      .subscribe({
        next: (created) => {
          this.plans.update((list) => [...list, created].sort((a, b) => a.sort_order - b.sort_order));
          this.success.set(`Created ${created.name}: ${this.inr(created.payable)}`);
          this.creatingPlan.set(false);
          this.showAddPlan.set(false);
          this.newPlan = this.emptyNewPlan();
        },
        error: (e) => {
          const errs = e?.error?.errors;
          const first =
            errs && typeof errs === 'object'
              ? (Object.values(errs).flat()[0] as string | undefined)
              : undefined;
          this.error.set(first || e?.error?.message || 'Could not create plan.');
          this.creatingPlan.set(false);
        },
      });
  }

  setTab(tab: 'pharmacies' | 'plans'): void {
    this.tab.set(tab);
    this.error.set('');
    this.success.set('');
    if (tab === 'plans' && this.plans().length === 0) {
      this.reloadPlans();
    }
  }

  filtered(): PlatformPharmacy[] {
    const q = this.filter().trim().toLowerCase();
    const all = this.rows();
    if (!q) return all;
    return all.filter((r) =>
      [r.business_name, r.email, r.owner_name, r.owner_login_name, r.phone, String(r.client_id), r.web_android]
        .some((v) => (v ?? '').toString().toLowerCase().includes(q)),
    );
  }

  pendingCount(): number {
    return this.rows().filter((r) => !this.isActive(r)).length;
  }

  isActive(r: PlatformPharmacy): boolean {
    return Number(r.platform_active) === 1;
  }

  accessLabel(r: PlatformPharmacy): string {
    const v = (r.web_android || 'both').toLowerCase();
    if (v === 'web') return 'Web';
    if (v === 'android') return 'Android';
    return 'Both';
  }

  reload(): void {
    this.loading.set(true);
    this.error.set('');
    this.svc.list().subscribe({
      next: (data) => {
        this.rows.set(data.rows ?? []);
        this.loading.set(false);
      },
      error: (e) => {
        this.error.set(e?.error?.message ?? 'Could not load pharmacies.');
        this.loading.set(false);
      },
    });
    if (this.tab() === 'plans') {
      this.reloadPlans();
    }
  }

  reloadPlans(): void {
    this.plansLoading.set(true);
    this.svc.listPlans().subscribe({
      next: (plans) => {
        this.plans.set(plans.map((p) => ({ ...p })));
        this.plansLoading.set(false);
      },
      error: (e) => {
        this.error.set(e?.error?.message ?? 'Could not load plans.');
        this.plansLoading.set(false);
      },
    });
  }

  previewPayable(p: SubscriptionPlan): number {
    const months = Number(p.months) || 0;
    const gst = Number(p.gst_pct) || 0;
    const disc = Number(p.discount_pct) || 0;
    if (months <= 0) {
      const base = Number(p.lifetime_base) || 0;
      return Math.round(base * (1 + gst / 100) * 100) / 100;
    }
    const base = (Number(p.monthly_rate) || 0) * months;
    const after = base * (1 - disc / 100);
    return Math.round(after * (1 + gst / 100) * 100) / 100;
  }

  inr(n: number): string {
    return new Intl.NumberFormat('en-IN', {
      style: 'currency',
      currency: 'INR',
      maximumFractionDigits: 2,
    }).format(Number(n) || 0);
  }

  savePlan(p: SubscriptionPlan): void {
    this.savingCode.set(p.code);
    this.error.set('');
    this.success.set('');
    this.svc
      .updatePlan(p.code, {
        name: p.name,
        monthly_rate: Number(p.monthly_rate),
        discount_pct: Number(p.discount_pct),
        gst_pct: Number(p.gst_pct),
        lifetime_base: p.is_lifetime ? Number(p.lifetime_base) : null,
        is_active: p.is_active,
      })
      .subscribe({
        next: (saved) => {
          this.plans.update((list) => list.map((row) => (row.code === saved.code ? saved : row)));
          this.success.set(`Updated ${saved.name}: ${this.inr(saved.payable)}`);
          this.savingCode.set(null);
        },
        error: (e) => {
          this.error.set(e?.error?.message ?? 'Could not save plan.');
          this.savingCode.set(null);
        },
      });
  }

  onToggle(row: PlatformPharmacy, event: Event): void {
    const input = event.target as HTMLInputElement;
    const next = !!input.checked;
    const prev = Number(row.platform_active) === 1;
    if (next === prev) return;

    const label = next ? 'activate' : 'inactivate';
    if (!confirm(`${label.charAt(0).toUpperCase() + label.slice(1)} login for "${row.business_name}"?`)) {
      input.checked = prev;
      return;
    }

    this.togglingId.set(row.client_id);
    this.error.set('');
    this.success.set('');
    this.svc.toggle(row.client_id, next).subscribe({
      next: (res) => {
        this.rows.update((list) =>
          list.map((r) =>
            r.client_id === row.client_id
              ? { ...r, platform_active: res.platform_active }
              : r,
          ),
        );
        this.success.set(
          next
            ? `Activated: ${row.business_name}`
            : `Inactivated: ${row.business_name}`,
        );
        this.togglingId.set(null);
      },
      error: (e) => {
        input.checked = prev;
        this.error.set(e?.error?.message ?? 'Update failed.');
        this.togglingId.set(null);
      },
    });
  }

  formatDate(v?: string | null): string {
    if (!v) return '—';
    const d = new Date(String(v).replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return v;
    const dd = String(d.getDate()).padStart(2, '0');
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const yyyy = d.getFullYear();
    return `${dd}-${mm}-${yyyy}`;
  }
}
