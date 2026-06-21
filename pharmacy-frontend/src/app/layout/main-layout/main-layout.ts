import { Component, effect, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { NavigationEnd, RouterLink, RouterLinkActive, RouterOutlet, Router } from '@angular/router';
import { filter } from 'rxjs';
import { AuthService } from '../../core/services/auth.service';
import { PosModalService } from '../../core/services/pos-modal.service';
import { Billing } from '../../features/sales/billing';

interface NavItem {
  label: string;
  icon: string;
  route?: string;
  roles?: string[];
  comingSoon?: boolean;
}

@Component({
  selector: 'app-main-layout',
  imports: [CommonModule, RouterOutlet, RouterLink, RouterLinkActive, Billing],
  templateUrl: './main-layout.html',
  styleUrl: './main-layout.scss',
})
export class MainLayout {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  readonly pos = inject(PosModalService);

  readonly user = this.auth.user;
  readonly sidebarOpen = signal(true);
  readonly mobileNav = signal(false);

  canSell(): boolean {
    return PosModalService.canSell(this.user()?.role);
  }

  constructor() {
    // The POS popup should only float over the dashboard. As soon as the user
    // navigates to any other section (Masters, Purchase, etc.) close it so it
    // never covers those pages.
    this.router.events
      .pipe(filter((e): e is NavigationEnd => e instanceof NavigationEnd))
      .subscribe((e) => {
        if (this.pos.open() && !e.urlAfterRedirects.startsWith('/app/dashboard')) {
          this.pos.hide();
        }
      });

    // Lock background scroll while the popup is open.
    effect(() => {
      document.body.style.overflow = this.pos.open() ? 'hidden' : '';
    });
  }

  readonly navItems: NavItem[] = [
    { label: 'Dashboard', icon: 'bi-grid-1x2', route: '/app/dashboard', roles: ['ho_admin', 'branch_admin', 'purchase', 'pharmacist', 'cashier'] },
    { label: 'Masters', icon: 'bi-box-seam', route: '/app/masters', roles: ['ho_admin', 'branch_admin', 'purchase'] },
    { label: 'Purchase', icon: 'bi-cart-plus', route: '/app/purchase', roles: ['ho_admin', 'branch_admin', 'purchase'] },
    { label: 'Opening Stock', icon: 'bi-clipboard-plus', route: '/app/opening-stock', roles: ['ho_admin', 'branch_admin', 'purchase'] },
    { label: 'Sales / Billing', icon: 'bi-receipt', route: '/app/sales', roles: ['ho_admin', 'branch_admin', 'pharmacist', 'cashier'] },
    { label: 'IPD / Admissions', icon: 'bi-hospital', route: '/app/ipd', roles: ['ho_admin', 'branch_admin', 'pharmacist'] },
    { label: 'Refill Reminders', icon: 'bi-bell', route: '/app/refills', roles: ['ho_admin', 'branch_admin', 'pharmacist'] },
    { label: 'Deliveries', icon: 'bi-truck', route: '/app/deliveries', roles: ['ho_admin', 'branch_admin', 'pharmacist'] },
    { label: 'Accounts', icon: 'bi-cash-coin', route: '/app/masters/accounts', roles: ['ho_admin', 'branch_admin'] },
    { label: 'Payments', icon: 'bi-wallet2', route: '/app/payments', roles: ['ho_admin', 'branch_admin'] },
    { label: 'Credit Notes', icon: 'bi-file-earmark-minus', route: '/app/credit-notes', roles: ['ho_admin', 'branch_admin'] },
    { label: 'Debit Notes', icon: 'bi-file-earmark-plus', route: '/app/debit-notes', roles: ['ho_admin', 'branch_admin'] },
    { label: 'Daily Closing', icon: 'bi-calendar2-check', route: '/app/daily-closing', roles: ['ho_admin', 'branch_admin', 'pharmacist', 'cashier'] },
    { label: 'Reports', icon: 'bi-bar-chart-line', route: '/app/reports', roles: ['ho_admin', 'branch_admin', 'purchase'] },
    { label: 'Head Office', icon: 'bi-buildings', route: '/app/ho', roles: ['ho_admin'] },
  ];

  visibleNav(): NavItem[] {
    const role = this.user()?.role;
    return this.navItems.filter((i) => !i.roles || (role && i.roles.includes(role)));
  }

  toggleSidebar(): void {
    // Mobile hamburger: open/close the drawer.
    this.mobileNav.update((v) => !v);
  }

  /** Desktop edge arrow: collapse/expand the sidebar. */
  toggleCollapse(): void {
    this.sidebarOpen.update((v) => !v);
  }

  closeMobile(): void {
    this.mobileNav.set(false);
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
