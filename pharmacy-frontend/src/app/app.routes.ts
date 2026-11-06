import { Routes } from '@angular/router';
import { authGuard, guestGuard, roleGuard } from './core/guards/auth.guard';
import { APP_ROLES } from './core/models/auth.models';

export const routes: Routes = [
  // ---- Public ePharmacy storefront (no authentication) ----
  {
    path: 'shop',
    loadComponent: () => import('./features/shop/shop').then((m) => m.Shop),
  },
  {
    path: 'track',
    loadComponent: () => import('./features/shop/track').then((m) => m.Track),
  },
  {
    path: 'agt-shop',
    loadComponent: () => import('./features/agt-shop/agt-shop').then((m) => m.AgtShopPage),
  },
  {
    path: 'login',
    canActivate: [guestGuard],
    loadComponent: () => import('./features/auth/login/login').then((m) => m.Login),
  },
  {
    path: 'register',
    canActivate: [guestGuard],
    loadComponent: () => import('./features/auth/register/register').then((m) => m.Register),
  },
  {
    path: 'app',
    canActivate: [authGuard],
    loadComponent: () =>
      import('./layout/main-layout/main-layout').then((m) => m.MainLayout),
    children: [
      { path: '', pathMatch: 'full', redirectTo: 'dashboard' },
      {
        path: 'dashboard',
        canActivate: [roleGuard],
        data: { roles: APP_ROLES, moduleCode: 'HOME' },
        loadComponent: () =>
          import('./features/dashboard/dashboard').then((m) => m.Dashboard),
      },
      {
        path: 'masters',
        canActivate: [roleGuard],
        data: { roles: APP_ROLES },
        loadComponent: () =>
          import('./features/masters/masters-home').then((m) => m.MastersHome),
      },
      {
        path: 'masters/products',
        canActivate: [roleGuard],
        data: { configKey: 'products', roles: APP_ROLES },
        loadComponent: () => import('./features/masters/master-page').then((m) => m.MasterPage),
      },
      {
        path: 'masters/generics',
        canActivate: [roleGuard],
        data: { configKey: 'generics', roles: APP_ROLES },
        loadComponent: () => import('./features/masters/master-page').then((m) => m.MasterPage),
      },
      {
        path: 'masters/manufacturers',
        canActivate: [roleGuard],
        data: { configKey: 'manufacturers', roles: APP_ROLES },
        loadComponent: () => import('./features/masters/master-page').then((m) => m.MasterPage),
      },
      {
        path: 'masters/hsn',
        canActivate: [roleGuard],
        data: { configKey: 'hsn', roles: APP_ROLES },
        loadComponent: () => import('./features/masters/master-page').then((m) => m.MasterPage),
      },
      {
        path: 'masters/customers',
        canActivate: [roleGuard],
        data: { configKey: 'customers', roles: APP_ROLES, moduleCode: 'MASTERS' },
        loadComponent: () => import('./features/masters/master-page').then((m) => m.MasterPage),
      },
      {
        path: 'masters/doctors',
        canActivate: [roleGuard],
        data: { configKey: 'doctors', roles: APP_ROLES, moduleCode: 'MASTERS' },
        loadComponent: () => import('./features/masters/master-page').then((m) => m.MasterPage),
      },
      {
        path: 'masters/hospitals',
        canActivate: [roleGuard],
        data: { configKey: 'hospitals', roles: APP_ROLES, moduleCode: 'MASTERS' },
        loadComponent: () => import('./features/masters/master-page').then((m) => m.MasterPage),
      },
      {
        path: 'masters/distributors',
        canActivate: [roleGuard],
        data: { configKey: 'distributors', roles: APP_ROLES },
        loadComponent: () => import('./features/masters/master-page').then((m) => m.MasterPage),
      },
      {
        path: 'masters/suppliers',
        canActivate: [roleGuard],
        data: { configKey: 'distributors', roles: APP_ROLES },
        loadComponent: () => import('./features/masters/master-page').then((m) => m.MasterPage),
      },
      {
        path: 'masters/accounts',
        canActivate: [roleGuard],
        data: { configKey: 'accounts', roles: ['ho_admin', 'branch_admin'] },
        loadComponent: () => import('./features/masters/master-page').then((m) => m.MasterPage),
      },
      {
        path: 'masters/rate-margins',
        canActivate: [roleGuard],
        data: { configKey: 'rate-margins', roles: ['ho_admin', 'branch_admin'] },
        loadComponent: () => import('./features/masters/master-page').then((m) => m.MasterPage),
      },
      {
        path: 'masters/employees',
        canActivate: [roleGuard],
        data: { configKey: 'employees', roles: ['ho_admin', 'branch_admin'] },
        loadComponent: () => import('./features/masters/master-page').then((m) => m.MasterPage),
      },
      {
        path: 'purchase',
        canActivate: [roleGuard],
        data: { roles: APP_ROLES, moduleCode: 'PURCHASE', openLog: true },
        loadComponent: () => import('./features/purchase/purchase-list').then((m) => m.PurchaseList),
      },
      {
        path: 'purchase/new',
        canActivate: [roleGuard],
        data: { roles: APP_ROLES, moduleCode: 'PURCHASE' },
        loadComponent: () => import('./features/purchase/purchase-entry').then((m) => m.PurchaseEntry),
      },
      {
        path: 'purchase/import',
        canActivate: [roleGuard],
        data: { roles: APP_ROLES, moduleCode: 'IMPORT_BILL', requireEdit: true },
        loadComponent: () => import('./features/purchase/import-hub/import-hub').then((m) => m.ImportHub),
      },
      {
        path: 'purchase/inward-stock',
        canActivate: [roleGuard],
        data: { roles: APP_ROLES, moduleCode: 'PURCHASE', requireEdit: true },
        loadComponent: () =>
          import('./features/purchase/inward-stock/inward-stock').then((m) => m.InwardStock),
      },
      {
        path: 'purchase-order',
        canActivate: [roleGuard],
        data: { roles: APP_ROLES, moduleCode: 'PURCHASE_ORDER' },
        loadComponent: () =>
          import('./features/purchase/purchase-order/purchase-order').then((m) => m.PurchaseOrderPage),
      },
      {
        path: 'purchase/dispensing',
        canActivate: [roleGuard],
        data: { roles: APP_ROLES, moduleCode: 'DISPENSING' },
        loadComponent: () => import('./features/purchase/dispensing/dispensing').then((m) => m.Dispensing),
      },
      {
        path: 'purchase/:id/edit',
        canActivate: [roleGuard],
        data: { roles: APP_ROLES, moduleCode: 'PURCHASE', requireEdit: true },
        loadComponent: () => import('./features/purchase/purchase-entry').then((m) => m.PurchaseEntry),
      },
      {
        path: 'sales',
        canActivate: [roleGuard],
        data: { roles: APP_ROLES, moduleCode: 'SALES', openLog: true },
        loadComponent: () => import('./features/sales/sales-list').then((m) => m.SalesList),
      },
      {
        path: 'sales/new',
        canActivate: [roleGuard],
        data: { roles: APP_ROLES, moduleCode: 'SALES' },
        loadComponent: () => import('./features/sales/billing').then((m) => m.Billing),
      },
      {
        path: 'sales/:id/edit',
        canActivate: [roleGuard],
        data: { roles: ['ho_admin', 'branch_admin'], moduleCode: 'SALES', requireEdit: true },
        loadComponent: () => import('./features/sales/sale-bill-edit').then((m) => m.SaleBillEdit),
      },
      {
        path: 'opening-stock',
        canActivate: [roleGuard],
        data: { roles: APP_ROLES, moduleCode: 'OPENING_STOCK', requireEdit: true },
        loadComponent: () => import('./features/stock/opening-stock').then((m) => m.OpeningStock),
      },
      {
        path: 'reports',
        canActivate: [roleGuard],
        data: { roles: APP_ROLES, moduleCode: 'ALL_REPORTS' },
        loadComponent: () => import('./features/reports/reports').then((m) => m.Reports),
      },
      {
        path: 'daily-closing',
        canActivate: [roleGuard],
        data: { roles: ['ho_admin', 'branch_admin', 'pharmacist', 'cashier'] },
        loadComponent: () => import('./features/daily-closing/daily-closing').then((m) => m.DailyClosing),
      },
      {
        path: 'masters/branches',
        canActivate: [roleGuard],
        data: { configKey: 'branches', roles: ['ho_admin'] },
        loadComponent: () => import('./features/masters/master-page').then((m) => m.MasterPage),
      },
      {
        path: 'ho',
        canActivate: [roleGuard],
        data: { roles: ['ho_admin'] },
        loadComponent: () => import('./features/ho/ho-center').then((m) => m.HoCenter),
      },
      {
        path: 'refills',
        canActivate: [roleGuard],
        data: { roles: ['ho_admin', 'branch_admin', 'pharmacist'] },
        loadComponent: () => import('./features/refills/refills').then((m) => m.Refills),
      },
      {
        path: 'deliveries',
        canActivate: [roleGuard],
        data: { roles: ['ho_admin', 'branch_admin', 'pharmacist'] },
        loadComponent: () => import('./features/deliveries/deliveries').then((m) => m.Deliveries),
      },
      {
        path: 'aura-ghar-tak',
        canActivate: [roleGuard],
        data: { roles: [...APP_ROLES], moduleCode: 'AURA_GHAR_TAK' },
        loadComponent: () => import('./features/aura-ghar-tak/aura-ghar-tak').then((m) => m.AuraGharTakPage),
      },
      {
        path: 'mr',
        canActivate: [roleGuard],
        data: { roles: [...APP_ROLES], moduleCode: 'MR' },
        loadComponent: () => import('./features/mr/mr').then((m) => m.MrPage),
      },
      {
        path: 'ipd',
        canActivate: [roleGuard],
        data: { roles: ['ho_admin', 'branch_admin', 'pharmacist'] },
        loadComponent: () => import('./features/ipd/ipd').then((m) => m.Ipd),
      },
      {
        path: 'payments',
        canActivate: [roleGuard],
        data: { roles: ['ho_admin', 'branch_admin'], moduleCode: 'PAYMENTS' },
        loadComponent: () => import('./features/payments/payments-hub').then((m) => m.PaymentsHubPage),
      },
      {
        path: 'credit-notes',
        canActivate: [roleGuard],
        data: { roles: APP_ROLES, moduleCode: 'RETURN_NOTE' },
        loadComponent: () => import('./features/returns/wholesale-return').then((m) => m.WholesaleReturnPage),
      },
      {
        path: 'debit-notes',
        canActivate: [roleGuard],
        data: { roles: APP_ROLES, moduleCode: 'RETURN_NOTE' },
        loadComponent: () => import('./features/return-note/return-note').then((m) => m.ReturnNotePage),
      },
      {
        path: 'return-note',
        canActivate: [roleGuard],
        data: { roles: APP_ROLES, moduleCode: 'RETURN_NOTE' },
        loadComponent: () => import('./features/return-note/return-note').then((m) => m.ReturnNotePage),
      },
      {
        path: 'sales-return',
        canActivate: [roleGuard],
        data: { roles: APP_ROLES, moduleCode: 'RETURN_NOTE' },
        loadComponent: () => import('./features/returns/retail-sales-return').then((m) => m.RetailSalesReturnPage),
      },
      {
        path: 'wholesale-return',
        canActivate: [roleGuard],
        data: { roles: APP_ROLES, moduleCode: 'RETURN_NOTE' },
        loadComponent: () => import('./features/returns/wholesale-return').then((m) => m.WholesaleReturnPage),
      },
      {
        path: 'return-deduction',
        canActivate: [roleGuard],
        data: { roles: APP_ROLES, moduleCode: 'RETURN_NOTE' },
        loadComponent: () => import('./features/returns/return-deduction').then((m) => m.ReturnDeductionPage),
      },
      {
        path: 'profile',
        canActivate: [roleGuard],
        data: { roles: APP_ROLES, moduleCode: 'MY_PROFILE' },
        loadComponent: () => import('./features/profile/my-profile').then((m) => m.MyProfile),
      },
      {
        path: 'admin',
        canActivate: [roleGuard],
        data: { roles: ['ho_admin', 'branch_admin'], platformAdminOnly: true },
        loadComponent: () => import('./features/admin/admin-page').then((m) => m.AdminPage),
      },
      {
        path: 'support',
        canActivate: [roleGuard],
        data: { roles: APP_ROLES },
        loadComponent: () => import('./features/support/support').then((m) => m.SupportPage),
      },
    ],
  },
  { path: '', pathMatch: 'full', redirectTo: 'app/dashboard' },
  { path: '**', redirectTo: 'app/dashboard' },
];
