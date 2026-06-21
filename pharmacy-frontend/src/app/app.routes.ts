import { Routes } from '@angular/router';
import { authGuard, guestGuard, roleGuard } from './core/guards/auth.guard';

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
        data: { roles: ['ho_admin', 'branch_admin', 'purchase'] },
        loadComponent: () =>
          import('./features/dashboard/dashboard').then((m) => m.Dashboard),
      },
      {
        path: 'masters',
        canActivate: [roleGuard],
        data: { roles: ['ho_admin', 'branch_admin', 'purchase'] },
        loadComponent: () =>
          import('./features/masters/masters-home').then((m) => m.MastersHome),
      },
      {
        path: 'masters/products',
        canActivate: [roleGuard],
        data: { configKey: 'products', roles: ['ho_admin', 'branch_admin', 'purchase'] },
        loadComponent: () => import('./features/masters/master-page').then((m) => m.MasterPage),
      },
      {
        path: 'masters/generics',
        canActivate: [roleGuard],
        data: { configKey: 'generics', roles: ['ho_admin', 'branch_admin', 'purchase'] },
        loadComponent: () => import('./features/masters/master-page').then((m) => m.MasterPage),
      },
      {
        path: 'masters/customers',
        canActivate: [roleGuard],
        data: { configKey: 'customers', roles: ['ho_admin', 'branch_admin', 'purchase'] },
        loadComponent: () => import('./features/masters/master-page').then((m) => m.MasterPage),
      },
      {
        path: 'masters/doctors',
        canActivate: [roleGuard],
        data: { configKey: 'doctors', roles: ['ho_admin', 'branch_admin', 'purchase'] },
        loadComponent: () => import('./features/masters/master-page').then((m) => m.MasterPage),
      },
      {
        path: 'masters/suppliers',
        canActivate: [roleGuard],
        data: { configKey: 'suppliers', roles: ['ho_admin', 'branch_admin', 'purchase'] },
        loadComponent: () => import('./features/masters/master-page').then((m) => m.MasterPage),
      },
      {
        path: 'masters/accounts',
        canActivate: [roleGuard],
        data: { configKey: 'accounts', roles: ['ho_admin', 'branch_admin'] },
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
        data: { roles: ['ho_admin', 'branch_admin', 'purchase'] },
        loadComponent: () => import('./features/purchase/purchase-list').then((m) => m.PurchaseList),
      },
      {
        path: 'purchase/new',
        canActivate: [roleGuard],
        data: { roles: ['ho_admin', 'branch_admin', 'purchase'] },
        loadComponent: () => import('./features/purchase/purchase-entry').then((m) => m.PurchaseEntry),
      },
      {
        path: 'sales',
        canActivate: [roleGuard],
        data: { roles: ['ho_admin', 'branch_admin', 'pharmacist', 'cashier'] },
        loadComponent: () => import('./features/sales/sales-list').then((m) => m.SalesList),
      },
      {
        path: 'sales/new',
        canActivate: [roleGuard],
        data: { roles: ['ho_admin', 'branch_admin', 'pharmacist', 'cashier'] },
        loadComponent: () => import('./features/sales/billing').then((m) => m.Billing),
      },
      {
        path: 'reports',
        canActivate: [roleGuard],
        data: { roles: ['ho_admin', 'branch_admin', 'purchase'] },
        loadComponent: () => import('./features/reports/reports').then((m) => m.Reports),
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
        path: 'ipd',
        canActivate: [roleGuard],
        data: { roles: ['ho_admin', 'branch_admin', 'pharmacist'] },
        loadComponent: () => import('./features/ipd/ipd').then((m) => m.Ipd),
      },
    ],
  },
  { path: '', pathMatch: 'full', redirectTo: 'app/dashboard' },
  { path: '**', redirectTo: 'app/dashboard' },
];
