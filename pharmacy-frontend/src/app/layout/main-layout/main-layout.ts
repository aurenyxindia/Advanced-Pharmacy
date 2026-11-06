import { Component, effect, inject, OnDestroy, signal, viewChild } from '@angular/core';
import { CommonModule } from '@angular/common';
import { NavigationEnd, RouterLink, RouterLinkActive, RouterOutlet, Router } from '@angular/router';
import { filter } from 'rxjs';
import { AuthService } from '../../core/services/auth.service';
import { MetaService } from '../../core/services/meta.service';
import { UserRightsService } from '../../core/services/user-rights.service';
import { APP_ROLES } from '../../core/models/auth.models';
import { PosModalService } from '../../core/services/pos-modal.service';
import { SalesService } from '../../core/services/sales.service';
import { Billing } from '../../features/sales/billing';
import { AgtOrderNotificationService, AgtOrderNotification } from '../../core/services/agt-order-notification.service';
import { AgtOrderPopup } from '../../shared/agt-order-popup/agt-order-popup';
import { AuraAiChat } from '../../shared/aura-ai-chat/aura-ai-chat';
import { SupportPopup } from '../../shared/support-popup/support-popup';
import { SupportNotificationService } from '../../core/services/support-notification.service';
import { SupportNotification } from '../../core/services/support.service';
import { Subscription } from 'rxjs';

interface NavItem {
  label: string;
  icon: string;
  route?: string;
  roles?: string[];
  moduleCode?: string;
  /** Sales/Purchase log — always visible; rights only gate edit/delete on the page. */
  openLog?: boolean;
  comingSoon?: boolean;
  /** Only client_id=1 + branch_id=1 (platform Admin page). */
  platformAdminOnly?: boolean;
}

@Component({
  selector: 'app-main-layout',
  imports: [CommonModule, RouterOutlet, RouterLink, RouterLinkActive, Billing, AgtOrderPopup, AuraAiChat, SupportPopup],
  templateUrl: './main-layout.html',
  styleUrl: './main-layout.scss',
})
export class MainLayout implements OnDestroy {
  private readonly auth = inject(AuthService);
  private readonly meta = inject(MetaService);
  private readonly rights = inject(UserRightsService);
  private readonly router = inject(Router);
  private readonly salesSvc = inject(SalesService);
  private readonly agtOrderNotify = inject(AgtOrderNotificationService);
  readonly pos = inject(PosModalService);

  readonly agtOrderNotification = signal<AgtOrderNotification | null>(null);
  private agtNotifySub: Subscription | null = null;
  private readonly supportNotify = inject(SupportNotificationService);
  readonly supportNotification = signal<SupportNotification | null>(null);
  private supportNotifySub: Subscription | null = null;

  readonly user = this.auth.user;
  /** Desktop: expanded (labels visible) by default; persisted in localStorage. */
  readonly sidebarOpen = signal(MainLayout.readSidebarPref());
  readonly mobileNav = signal(false);

  canViewProfile(): boolean {
    if (this.rights.hasFullAccess()) return true;
    if (!this.rights.isReady()) return false;
    return this.rights.canView('MY_PROFILE');
  }

  canViewAuraAi(): boolean {
    if (this.rights.hasFullAccess()) return true;
    if (!this.rights.isReady()) return false;
    return this.rights.canView('AURA_AI') || this.rights.canEdit('AURA_AI');
  }

  private readonly auraAiChat = viewChild(AuraAiChat);

  openAuraAi(): void {
    this.auraAiChat()?.openChat();
  }

  canSell(): boolean {
    return PosModalService.canSell(this.user()?.role) && this.rights.canView('SALES');
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

    // Esc closes full-screen POS.
    if (typeof document !== 'undefined') {
      document.addEventListener('keydown', this.onPosKeydown);
    }

    if (this.auth.isAuthenticated()) {
      this.meta.loadProfile().subscribe();
      this.rights.loadMyRights().subscribe({
        next: () => {
          // Close POS if it was opened on login without Sales edit right.
          if (this.pos.open() && !this.canSell()) {
            this.pos.hide();
          }
          this.startAgtOrderPollingIfAllowed();
        },
        error: () => {
          if (this.pos.open()) this.pos.hide();
        },
      });
      this.agtNotifySub = this.agtOrderNotify.activeNotification$.subscribe((n) => {
        this.agtOrderNotification.set(n);
      });
      this.supportNotifySub = this.supportNotify.activeNotification$.subscribe((n) => {
        this.supportNotification.set(n);
      });
      this.supportNotify.startPolling();
    }
  }

  private startAgtOrderPollingIfAllowed(): void {
    const role = this.user()?.role ?? '';
    const staffRole = ['ho_admin', 'branch_admin', 'pharmacist', 'cashier'].includes(role);
    if (!staffRole) return;
    if (
      !this.rights.hasFullAccess() &&
      !this.rights.canView('AURA_GHAR_TAK') &&
      !this.rights.canEdit('AURA_GHAR_TAK')
    ) {
      return;
    }
    this.agtOrderNotify.startPolling();
  }

  private readonly onPosKeydown = (event: KeyboardEvent): void => {
    if (event.key === 'Escape' && this.pos.open()) {
      // Nested sales popups (previous history, quick-add, etc.) own Esc first.
      if (this.pos.escapeBlocked() || event.defaultPrevented) {
        return;
      }
      event.preventDefault();
      this.pos.hide();
      return;
    }
    if (event.altKey && event.key.toLowerCase() === 'q' && this.canSell()) {
      event.preventDefault();
      this.goToSalesLog();
    }
  };

  /** Retail Alt+Q — close POS and show sales bill log. */
  goToSalesLog(): void {
    this.pos.hide();
    this.salesSvc.notifyLogChanged();
    const url = this.router.url;
    const onSalesLog = url.startsWith('/app/sales') && !url.startsWith('/app/sales/new');
    if (!onSalesLog) {
      void this.router.navigate(['/app/sales']);
    }
  }

  readonly navItems: NavItem[] = [
    { label: 'Dashboard', icon: 'bi-grid-1x2', route: '/app/dashboard', roles: [...APP_ROLES], moduleCode: 'HOME' },
    { label: 'Masters', icon: 'bi-box-seam', route: '/app/masters', roles: [...APP_ROLES], moduleCode: 'MASTERS' },
    // Daily flow: Purchase → Import → Sales → Payments → Return → Reports.
    // Sales / Purchase logs are universal — always in nav; Edit/Delete gated on screens.
    { label: 'Purchase', icon: 'bi-cart-plus', route: '/app/purchase', roles: [...APP_ROLES], moduleCode: 'PURCHASE', openLog: true },
    { label: 'Import Bill/CSV', icon: 'bi-file-earmark-arrow-up', route: '/app/purchase/import', roles: [...APP_ROLES], moduleCode: 'IMPORT_BILL' },
    { label: 'Sales / Billing', icon: 'bi-receipt', route: '/app/sales', roles: [...APP_ROLES], moduleCode: 'SALES', openLog: true },
    { label: 'Payments', icon: 'bi-wallet2', route: '/app/payments', roles: ['ho_admin', 'branch_admin'], moduleCode: 'PAYMENTS' },
    // Visible for any staff role once RETURN_NOTE View/Edit is granted (not admin-only).
    { label: 'Return Note', icon: 'bi-arrow-counterclockwise', route: '/app/return-note', roles: [...APP_ROLES], moduleCode: 'RETURN_NOTE' },
    { label: 'Reports', icon: 'bi-bar-chart-line', route: '/app/reports', roles: [...APP_ROLES], moduleCode: 'ALL_REPORTS' },
    { label: 'Purchase Order', icon: 'bi-clipboard-check', route: '/app/purchase-order', roles: [...APP_ROLES], moduleCode: 'PURCHASE_ORDER' },
    { label: 'Opening Stock', icon: 'bi-clipboard-plus', route: '/app/opening-stock', roles: [...APP_ROLES], moduleCode: 'OPENING_STOCK' },
    { label: 'IPD / Admissions', icon: 'bi-hospital', route: '/app/ipd', roles: ['ho_admin', 'branch_admin', 'pharmacist'], moduleCode: 'IPD' },
    { label: 'Refill Reminders', icon: 'bi-bell', route: '/app/refills', roles: ['ho_admin', 'branch_admin', 'pharmacist'], moduleCode: 'REFILLS' },
    { label: 'Deliveries', icon: 'bi-truck', route: '/app/deliveries', roles: ['ho_admin', 'branch_admin', 'pharmacist'], moduleCode: 'DELIVERIES' },
    { label: 'AuraGharTak', icon: 'bi-house-heart', route: '/app/aura-ghar-tak', roles: [...APP_ROLES], moduleCode: 'AURA_GHAR_TAK' },
    { label: 'MR', icon: 'bi-briefcase', route: '/app/mr', roles: [...APP_ROLES], moduleCode: 'MR' },
    { label: 'Accounts', icon: 'bi-cash-coin', route: '/app/masters/accounts', roles: ['ho_admin', 'branch_admin'], moduleCode: 'MASTERS' },
    { label: 'Daily Closing', icon: 'bi-calendar2-check', route: '/app/daily-closing', roles: ['ho_admin', 'branch_admin', 'pharmacist', 'cashier'], moduleCode: 'DAILY_CLOSING' },
    { label: 'Head Office', icon: 'bi-buildings', route: '/app/ho', roles: ['ho_admin'] },
    { label: 'My Profile', icon: 'bi-person-badge', route: '/app/profile', roles: [...APP_ROLES], moduleCode: 'MY_PROFILE' },
    {
      label: 'Admin',
      icon: 'bi-shield-lock',
      route: '/app/admin',
      roles: ['ho_admin', 'branch_admin'],
      platformAdminOnly: true,
    },
    { label: 'Support', icon: 'bi-life-preserver', route: '/app/support', roles: [...APP_ROLES] },
  ];

  visibleNav(): NavItem[] {
    const role = this.user()?.role;
    const u = this.user();
    const isPlatformAdmin =
      Number(u?.client_id) === 1 && Number(u?.branch_id) === 1;
    return this.navItems.filter((i) => {
      if (i.roles && (!role || !i.roles.includes(role))) return false;
      if (i.platformAdminOnly && !isPlatformAdmin) return false;
      // MR is a wholesale field-force module (Retail hides it for retail-only clients).
      if (i.moduleCode === 'MR' && this.meta.businessType() === 'retail') return false;
      if (i.moduleCode) {
        // Universal logs (Sales / Purchase): always show once role matches.
        if (i.openLog) return true;
        // Staff matrix never applies to HO / branch admin.
        if (this.rights.hasFullAccess()) return true;
        if (!this.rights.isReady()) return false;
        // Respect each module's own rights (Purchase Edit no longer unlocks Import / Opening Stock).
        return this.rights.canView(i.moduleCode) || this.rights.canEdit(i.moduleCode);
      }
      return true;
    });
  }

  toggleSidebar(): void {
    // Mobile hamburger: open/close the drawer.
    this.mobileNav.update((v) => !v);
  }

  /** Desktop edge arrow: collapse/expand the sidebar. */
  toggleCollapse(): void {
    this.sidebarOpen.update((v) => {
      const next = !v;
      MainLayout.persistSidebarPref(next);
      return next;
    });
  }

  private static readSidebarPref(): boolean {
    if (typeof localStorage === 'undefined') return true;
    const raw = localStorage.getItem('pp_sidebar_open');
    return raw === null ? true : raw === '1';
  }

  private static persistSidebarPref(open: boolean): void {
    if (typeof localStorage !== 'undefined') {
      localStorage.setItem('pp_sidebar_open', open ? '1' : '0');
    }
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
      purchase: 'Purchase',
    };
    return map[this.user()?.role ?? ''] ?? 'User';
  }

  /** Retail parity: medical / pharmacy name from client profile. */
  medicalName(): string {
    return this.meta.businessName() || '';
  }

  /** Contact person from profile; falls back to logged-in user name (Retail header behaviour). */
  concernedPerson(): string {
    return this.meta.contactPerson() || this.user()?.name || '';
  }

  headerDisplayName(): string {
    const medical = this.medicalName().trim();
    const person = this.concernedPerson().trim();
    if (medical && person) {
      return `${medical} (${person})`;
    }
    return medical || person || '';
  }

  logout(): void {
    this.supportNotify.stopPolling();
    this.auth.logout();
    this.router.navigate(['/login']);
  }

  ngOnDestroy(): void {
    document.removeEventListener('keydown', this.onPosKeydown);
    document.body.style.overflow = '';
    this.agtOrderNotify.stopPolling();
    this.agtNotifySub?.unsubscribe();
    this.supportNotify.stopPolling();
    this.supportNotifySub?.unsubscribe();
  }
}
