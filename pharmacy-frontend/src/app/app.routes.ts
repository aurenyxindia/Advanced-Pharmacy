import { Routes } from '@angular/router';
import { authGuard, guestGuard, roleGuard } from './core/guards/auth.guard';

export const routes: Routes = [
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
        loadComponent: () =>
          import('./features/dashboard/dashboard').then((m) => m.Dashboard),
      },
      {
        path: 'masters',
        loadComponent: () =>
          import('./features/masters/masters-home').then((m) => m.MastersHome),
      },
      {
        path: 'masters/products',
        data: { configKey: 'products' },
        loadComponent: () => import('./features/masters/master-page').then((m) => m.MasterPage),
      },
      {
        path: 'masters/generics',
        data: { configKey: 'generics' },
        loadComponent: () => import('./features/masters/master-page').then((m) => m.MasterPage),
      },
      {
        path: 'masters/customers',
        data: { configKey: 'customers' },
        loadComponent: () => import('./features/masters/master-page').then((m) => m.MasterPage),
      },
      {
        path: 'masters/doctors',
        data: { configKey: 'doctors' },
        loadComponent: () => import('./features/masters/master-page').then((m) => m.MasterPage),
      },
      {
        path: 'masters/suppliers',
        data: { configKey: 'suppliers' },
        loadComponent: () => import('./features/masters/master-page').then((m) => m.MasterPage),
      },
      {
        path: 'masters/accounts',
        data: { configKey: 'accounts' },
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
        loadComponent: () => import('./features/purchase/purchase-list').then((m) => m.PurchaseList),
      },
      {
        path: 'purchase/new',
        loadComponent: () => import('./features/purchase/purchase-entry').then((m) => m.PurchaseEntry),
      },
      {
        path: 'sales',
        loadComponent: () => import('./features/sales/sales-list').then((m) => m.SalesList),
      },
      {
        path: 'sales/new',
        loadComponent: () => import('./features/sales/billing').then((m) => m.Billing),
      },
      {
        path: 'reports',
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
        loadComponent: () => import('./features/refills/refills').then((m) => m.Refills),
      },
    ],
  },
  { path: '', pathMatch: 'full', redirectTo: 'app/dashboard' },
  { path: '**', redirectTo: 'app/dashboard' },
];
