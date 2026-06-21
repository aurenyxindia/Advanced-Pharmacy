import { Component, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterLink } from '@angular/router';
import { AuthService } from '../../core/services/auth.service';
import { MASTER_CONFIGS } from './master.config';

interface MasterTile {
  key: string;
  title: string;
  subtitle: string;
  icon: string;
  route: string;
  adminOnly?: boolean;
}

@Component({
  selector: 'app-masters-home',
  imports: [CommonModule, RouterLink],
  template: `
    <div>
      <h4 class="fw-bold mb-1">Masters</h4>
      <p class="text-secondary mb-4">Manage your core data — products, people and ledgers.</p>

      <div class="row g-3">
        @for (tile of visibleTiles(); track tile.key) {
          <div class="col-12 col-sm-6 col-xl-4">
            <a [routerLink]="tile.route" class="tile card-pharma p-3 d-flex align-items-center gap-3 text-decoration-none">
              <div class="tile-icon"><i class="bi" [ngClass]="tile.icon"></i></div>
              <div>
                <div class="fw-semibold text-dark">{{ tile.title }}</div>
                <div class="text-secondary small">{{ tile.subtitle }}</div>
              </div>
              <i class="bi bi-chevron-right ms-auto text-secondary"></i>
            </a>
          </div>
        }
      </div>
    </div>
  `,
  styles: [`
    .tile { transition: transform .12s ease, box-shadow .12s ease; }
    .tile:hover { transform: translateY(-2px); box-shadow: var(--pharma-card-shadow-lg); }
    .tile-icon { width: 48px; height: 48px; border-radius: 14px; background: rgba(13,148,136,.12);
      color: var(--pharma-primary); display:flex; align-items:center; justify-content:center; font-size:1.4rem; flex-shrink:0; }
  `],
})
export class MastersHome {
  private readonly auth = inject(AuthService);

  private readonly tiles: MasterTile[] = [
    { key: 'products', title: MASTER_CONFIGS['products'].title, subtitle: MASTER_CONFIGS['products'].subtitle, icon: MASTER_CONFIGS['products'].icon, route: '/app/masters/products' },
    { key: 'generics', title: MASTER_CONFIGS['generics'].title, subtitle: MASTER_CONFIGS['generics'].subtitle, icon: MASTER_CONFIGS['generics'].icon, route: '/app/masters/generics' },
    { key: 'customers', title: MASTER_CONFIGS['customers'].title, subtitle: MASTER_CONFIGS['customers'].subtitle, icon: MASTER_CONFIGS['customers'].icon, route: '/app/masters/customers' },
    { key: 'doctors', title: MASTER_CONFIGS['doctors'].title, subtitle: MASTER_CONFIGS['doctors'].subtitle, icon: MASTER_CONFIGS['doctors'].icon, route: '/app/masters/doctors' },
    { key: 'suppliers', title: MASTER_CONFIGS['suppliers'].title, subtitle: MASTER_CONFIGS['suppliers'].subtitle, icon: MASTER_CONFIGS['suppliers'].icon, route: '/app/masters/suppliers' },
    { key: 'accounts', title: MASTER_CONFIGS['accounts'].title, subtitle: MASTER_CONFIGS['accounts'].subtitle, icon: MASTER_CONFIGS['accounts'].icon, route: '/app/masters/accounts' },
    { key: 'employees', title: MASTER_CONFIGS['employees'].title, subtitle: MASTER_CONFIGS['employees'].subtitle, icon: MASTER_CONFIGS['employees'].icon, route: '/app/masters/employees', adminOnly: true },
  ];

  visibleTiles(): MasterTile[] {
    const role = this.auth.user()?.role;
    return this.tiles.filter((t) => !t.adminOnly || role === 'ho_admin' || role === 'branch_admin');
  }
}
