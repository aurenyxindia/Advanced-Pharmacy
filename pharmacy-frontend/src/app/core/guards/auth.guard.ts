import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { TokenService } from '../services/token.service';
import { AuthService } from '../services/auth.service';

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
  const router = inject(Router);

  const access = tokens.accessToken;
  if (access && !tokens.isExpired(access)) {
    return router.createUrlTree(['/app/dashboard']);
  }
  return true;
};

/** Restricts a route to specific roles via route.data.roles. */
export const roleGuard: CanActivateFn = (route) => {
  const auth = inject(AuthService);
  const router = inject(Router);

  const allowed = (route.data?.['roles'] as string[] | undefined) ?? [];
  const role = auth.user()?.role;

  if (allowed.length === 0 || (role && allowed.includes(role))) {
    return true;
  }
  return router.createUrlTree(['/app/dashboard']);
};
