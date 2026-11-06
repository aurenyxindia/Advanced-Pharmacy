import { Component, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterLink } from '@angular/router';
import { forkJoin } from 'rxjs';
import { AuthService } from '../../core/services/auth.service';
import { MASTERS_OPS_ROLES } from '../../core/models/auth.models';
import { CrudService } from '../../core/services/crud.service';
import { UserRightsService } from '../../core/services/user-rights.service';
import { MASTER_CONFIGS } from './master.config';

interface MasterTile {
  key: string;
  title: string;
  subtitle: string;
  icon: string;
  route: string;
  /** Shared catalog master — all logged-in roles. */
  universal?: boolean;
  adminOnly?: boolean;
}

@Component({
  selector: 'app-masters-home',
  imports: [CommonModule, RouterLink],
  template: `
    <div>
      <h4 class="fw-bold mb-1">Masters</h4>
      <p class="text-secondary mb-3">Shared catalog (products, generics, HSN, distributors) plus branch masters for your role.</p>

      <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
          <div class="kpi-card kpi-teal">
            <div class="kpi-icon"><i class="bi bi-capsule"></i></div>
            <div class="kpi-body">
              <span class="kpi-label">Products</span>
              <b class="kpi-value">{{ kpiLoading() ? '—' : (counts().products | number) }}</b>
            </div>
          </div>
        </div>
        <div class="col-6 col-lg-3">
          <div class="kpi-card kpi-blue">
            <div class="kpi-icon"><i class="bi bi-people"></i></div>
            <div class="kpi-body">
              <span class="kpi-label">Customers</span>
              <b class="kpi-value">{{ kpiLoading() ? '—' : (counts().customers | number) }}</b>
            </div>
          </div>
        </div>
        <div class="col-6 col-lg-3">
          <div class="kpi-card kpi-amber">
            <div class="kpi-icon"><i class="bi bi-truck"></i></div>
            <div class="kpi-body">
              <span class="kpi-label">Distributors</span>
              <b class="kpi-value">{{ kpiLoading() ? '—' : (counts().distributors | number) }}</b>
            </div>
          </div>
        </div>
        <div class="col-6 col-lg-3">
          <div class="kpi-card kpi-indigo">
            <div class="kpi-icon"><i class="bi bi-person-badge"></i></div>
            <div class="kpi-body">
              <span class="kpi-label">Doctors</span>
              <b class="kpi-value">{{ kpiLoading() ? '—' : (counts().doctors | number) }}</b>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3 masters-tiles">
        @for (tile of visibleTiles(); track tile.key) {
          <div class="col-12 col-sm-6 col-xl-4 d-flex">
            <a [routerLink]="tile.route" class="tile card-pharma p-3 d-flex align-items-center gap-3 text-decoration-none w-100">
              <div class="tile-icon"><i class="bi" [ngClass]="tile.icon"></i></div>
              <div class="tile-text min-w-0 flex-grow-1">
                <div class="fw-semibold text-dark tile-title">{{ tile.title }}</div>
                <div class="text-secondary small tile-subtitle">{{ tile.subtitle }}</div>
              </div>
              <i class="bi bi-chevron-right ms-auto text-secondary flex-shrink-0"></i>
            </a>
          </div>
        }
      </div>
    </div>
  `,
  styles: [`
    .masters-tiles > [class*='col-'] { display: flex; }
    .tile {
      min-height: 88px;
      transition: transform .12s ease, box-shadow .12s ease;
    }
    .tile:hover { transform: translateY(-2px); box-shadow: var(--pharma-card-shadow-lg); }
    .tile-icon { width: 48px; height: 48px; border-radius: 14px; background: rgba(13,148,136,.12);
      color: var(--pharma-primary); display:flex; align-items:center; justify-content:center; font-size:1.4rem; flex-shrink:0; }
    .tile-title { line-height: 1.25; }
    .tile-subtitle {
      display: -webkit-box;
      -webkit-box-orient: vertical;
      -webkit-line-clamp: 2;
      line-clamp: 2;
      overflow: hidden;
      line-height: 1.35;
      min-height: calc(1.35em * 2);
      margin-top: 2px;
    }
  `],
})
export class MastersHome {
  private readonly auth = inject(AuthService);
  private readonly crud = inject(CrudService);
  private readonly rights = inject(UserRightsService);

  /** Customer / Doctor / Hospital masters: ops roles, or any staff with MASTERS rights. */
  private canOpsMasters(): boolean {
    const role = this.auth.user()?.role;
    return (role != null && (MASTERS_OPS_ROLES as string[]).includes(role))
      || this.rights.canView('MASTERS');
  }

  readonly kpiLoading = signal(true);
  readonly counts = signal({ products: 0, customers: 0, distributors: 0, doctors: 0 });

  private readonly tiles: MasterTile[] = [
    { key: 'products', title: MASTER_CONFIGS['products'].title, subtitle: MASTER_CONFIGS['products'].subtitle, icon: MASTER_CONFIGS['products'].icon, route: '/app/masters/products', universal: true },
    { key: 'generics', title: MASTER_CONFIGS['generics'].title, subtitle: MASTER_CONFIGS['generics'].subtitle, icon: MASTER_CONFIGS['generics'].icon, route: '/app/masters/generics', universal: true },
    { key: 'manufacturers', title: MASTER_CONFIGS['manufacturers'].title, subtitle: MASTER_CONFIGS['manufacturers'].subtitle, icon: MASTER_CONFIGS['manufacturers'].icon, route: '/app/masters/manufacturers', universal: true },
    { key: 'hsn', title: MASTER_CONFIGS['hsn'].title, subtitle: MASTER_CONFIGS['hsn'].subtitle, icon: MASTER_CONFIGS['hsn'].icon, route: '/app/masters/hsn', universal: true },
    { key: 'distributors', title: MASTER_CONFIGS['distributors'].title, subtitle: MASTER_CONFIGS['distributors'].subtitle, icon: MASTER_CONFIGS['distributors'].icon, route: '/app/masters/distributors', universal: true },
    { key: 'customers', title: MASTER_CONFIGS['customers'].title, subtitle: MASTER_CONFIGS['customers'].subtitle, icon: MASTER_CONFIGS['customers'].icon, route: '/app/masters/customers' },
    { key: 'doctors', title: MASTER_CONFIGS['doctors'].title, subtitle: MASTER_CONFIGS['doctors'].subtitle, icon: MASTER_CONFIGS['doctors'].icon, route: '/app/masters/doctors' },
    { key: 'hospitals', title: MASTER_CONFIGS['hospitals'].title, subtitle: MASTER_CONFIGS['hospitals'].subtitle, icon: MASTER_CONFIGS['hospitals'].icon, route: '/app/masters/hospitals' },
    { key: 'accounts', title: MASTER_CONFIGS['accounts'].title, subtitle: MASTER_CONFIGS['accounts'].subtitle, icon: MASTER_CONFIGS['accounts'].icon, route: '/app/masters/accounts' },
    { key: 'rate-margins', title: MASTER_CONFIGS['rate-margins'].title, subtitle: MASTER_CONFIGS['rate-margins'].subtitle, icon: MASTER_CONFIGS['rate-margins'].icon, route: '/app/masters/rate-margins' },
    { key: 'employees', title: MASTER_CONFIGS['employees'].title, subtitle: MASTER_CONFIGS['employees'].subtitle, icon: MASTER_CONFIGS['employees'].icon, route: '/app/masters/employees', adminOnly: true },
  ];

  constructor() {
    const ops = this.canOpsMasters();

    const reqs: Record<string, ReturnType<CrudService['list']>> = {
      products: this.crud.list('products', { perPage: 1 }),
      distributors: this.crud.list('distributors', { perPage: 1 }),
    };
    if (ops) {
      reqs['customers'] = this.crud.list('customers', { perPage: 1 });
      reqs['doctors'] = this.crud.list('doctors', { perPage: 1 });
    }

    forkJoin(reqs).subscribe({
      next: (r) => {
        this.counts.set({
          products: r['products']?.total ?? 0,
          customers: r['customers']?.total ?? 0,
          distributors: r['distributors']?.total ?? 0,
          doctors: r['doctors']?.total ?? 0,
        });
        this.kpiLoading.set(false);
      },
      error: () => this.kpiLoading.set(false),
    });
  }

  visibleTiles(): MasterTile[] {
    const role = this.auth.user()?.role;
    const ops = this.canOpsMasters();
    return this.tiles.filter((t) => {
      if (t.adminOnly && role !== 'ho_admin' && role !== 'branch_admin') return false;
      if (t.universal) return true;
      return ops;
    });
  }
}
