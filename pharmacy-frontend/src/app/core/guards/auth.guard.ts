import { inject } from '@angular/core';
import { CanActivateFn, Router, UrlTree } from '@angular/router';
import { map, Observable } from 'rxjs';
import { TokenService } from '../services/token.service';
import { AuthService } from '../services/auth.service';
import { UserRightsService } from '../services/user-rights.service';
import { roleHome } from '../models/auth.models';

/** Blocks access unless a (non-expired or refreshable) session exists. */
export const authGuard: CanActivateFn = () => {
  const tokens = inject(TokenService);
  const auth = inject(AuthService);
  const router = inject(Router);

  const access = tokens.accessToken;
  if (access && (!tokens.isExpired(access) || tokens.refreshToken)) {
    return true;
  }

  auth.logout();
  return router.createUrlTree(['/login']);
};

/** Keeps authenticated users out of guest pages (login/register). */
export const guestGuard: CanActivateFn = () => {
  const tokens = inject(TokenService);
  const rights = inject(UserRightsService);
  const router = inject(Router);

  const access = tokens.accessToken;
  if (access && !tokens.isExpired(access)) {
    if (rights.isReady()) {
      return router.createUrlTree([rights.firstAllowedRoute()]);
    }
    return rights.loadMyRights().pipe(map(() => router.createUrlTree([rights.firstAllowedRoute()])));
  }
  return true;
};

/**
 * Restricts a route by role (data.roles) and optional module rights:
 * - data.openLog — sales/purchase log: role only (universal bill log)
 * - data.moduleCode + requireEdit — needs canEdit
 * - data.moduleCode + requireDelete — needs canDelete
 * - data.moduleCode alone — needs canView or canEdit (HOME included)
 */
export const roleGuard: CanActivateFn = (route): boolean | UrlTree | Observable<boolean | UrlTree> => {
  const auth = inject(AuthService);
  const rights = inject(UserRightsService);
  const router = inject(Router);

  const allowed = (route.data?.['roles'] as string[] | undefined) ?? [];
  const role = auth.user()?.role;

  const fallback = (): UrlTree => router.createUrlTree([rights.firstAllowedRoute()]);

  if (allowed.length > 0 && (!role || !allowed.includes(role))) {
    return fallback();
  }

  if (route.data?.['platformAdminOnly']) {
    const u = auth.user();
    if (Number(u?.client_id) !== 1 || Number(u?.branch_id) !== 1) {
      return fallback();
    }
  }

  const moduleCode = (route.data?.['moduleCode'] as string | undefined)?.trim();
  const openLog = !!route.data?.['openLog'];
  // Universal sales/purchase logs: any allowed role can open the log.
  if (!moduleCode || openLog) {
    return true;
  }

  const requireEdit = !!route.data?.['requireEdit'];
  const requireDelete = !!route.data?.['requireDelete'];

  const listPath = (code: string): UrlTree => {
    if (code === 'PURCHASE' || code === 'IMPORT_BILL' || code === 'OPENING_STOCK') {
      return router.createUrlTree(['/app/purchase']);
    }
    if (code === 'SALES') {
      return router.createUrlTree(['/app/sales']);
    }
    if (code === 'RETURN_NOTE' || code === 'DEBIT_NOTE' || code === 'CREDIT_NOTE') {
      return router.createUrlTree(['/app/return-note']);
    }
    return fallback();
  };

  const decide = (): boolean | UrlTree => {
    if (rights.hasFullAccess()) {
      return true;
    }
    const hasEdit = rights.canEdit(moduleCode);

    if (requireDelete && !rights.canDelete(moduleCode)) {
      return listPath(moduleCode);
    }
    if (requireEdit && !hasEdit) {
      return listPath(moduleCode);
    }
    if (!requireEdit && !requireDelete && !rights.canView(moduleCode) && !hasEdit) {
      return fallback();
    }
    return true;
  };

  if (rights.isReady()) {
    return decide();
  }

  return rights.loadMyRights().pipe(map(() => decide()));
};
