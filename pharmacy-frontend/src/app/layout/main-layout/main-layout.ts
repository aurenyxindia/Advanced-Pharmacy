import { Component, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterLink, RouterLinkActive, RouterOutlet, Router } from '@angular/router';
import { AuthService } from '../../core/services/auth.service';

interface NavItem {
  label: string;
  icon: string;
  route?: string;
  roles?: string[];
  comingSoon?: boolean;
}

@Component({
  selector: 'app-main-layout',
  imports: [CommonModule, RouterOutlet, RouterLink, RouterLinkActive],
  templateUrl: './main-layout.html',
  styleUrl: './main-layout.scss',
})
export class MainLayout {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  readonly user = this.auth.user;
  readonly sidebarOpen = signal(true);

  readonly navItems: NavItem[] = [
    { label: 'Dashboard', icon: 'bi-grid-1x2', route: '/app/dashboard' },
    { label: 'Masters', icon: 'bi-box-seam', route: '/app/masters' },
    { label: 'Purchase', icon: 'bi-cart-plus', route: '/app/purchase' },
    { label: 'Sales / Billing', icon: 'bi-receipt', route: '/app/sales' },
    { label: 'Inventory', icon: 'bi-boxes', comingSoon: true },
    { label: 'Prescriptions', icon: 'bi-file-medical', comingSoon: true },
    { label: 'Accounts', icon: 'bi-cash-coin', route: '/app/masters/accounts' },
    { label: 'Reports', icon: 'bi-bar-chart-line', route: '/app/reports' },
    { label: 'Branches', icon: 'bi-shop-window', roles: ['ho_admin'], comingSoon: true },
    { label: 'Settings', icon: 'bi-gear', comingSoon: true },
  ];

  visibleNav(): NavItem[] {
    const role = this.user()?.role;
    return this.navItems.filter((i) => !i.roles || (role && i.roles.includes(role)));
  }

  toggleSidebar(): void {
    this.sidebarOpen.update((v) => !v);
  }

  initials(): string {
    const name = this.user()?.name ?? '';
    return name
      .split(' ')
      .map((p) => p[0])
      .slice(0, 2)
      .join('')
      .toUpperCase();
  }

  roleLabel(): string {
    const map: Record<string, string> = {
      ho_admin: 'HO Administrator',
      branch_admin: 'Branch Admin',
      pharmacist: 'Pharmacist',
      cashier: 'Cashier',
    };
    return map[this.user()?.role ?? ''] ?? 'User';
  }

  logout(): void {
    this.auth.logout();
    this.router.navigate(['/login']);
  }
}
