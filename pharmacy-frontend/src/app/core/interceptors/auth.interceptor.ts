import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { BehaviorSubject, catchError, filter, switchMap, take, throwError } from 'rxjs';
import { TokenService } from '../services/token.service';
import { AuthService } from '../services/auth.service';

let isRefreshing = false;
const refreshed$ = new BehaviorSubject<string | null>(null);

export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const tokens = inject(TokenService);
  const auth = inject(AuthService);
  const router = inject(Router);

  const isAuthCall = req.url.includes('/auth/login') ||
    req.url.includes('/auth/register') ||
    req.url.includes('/auth/refresh');

  const accessToken = tokens.accessToken;
  const authReq = accessToken && !isAuthCall
    ? req.clone({ setHeaders: { Authorization: `Bearer ${accessToken}` } })
    : req;

  return next(authReq).pipe(
    catchError((error: HttpErrorResponse) => {
      if (error.status !== 401 || isAuthCall || !tokens.refreshToken) {
        return throwError(() => error);
      }

      // Attempt a single token refresh and replay queued requests.
      if (!isRefreshing) {
        isRefreshing = true;
        refreshed$.next(null);

        return auth.refresh().pipe(
          switchMap((res) => {
            isRefreshing = false;
            refreshed$.next(res.data.access_token);
            return next(
              req.clone({ setHeaders: { Authorization: `Bearer ${res.data.access_token}` } }),
            );
          }),
          catchError((refreshErr) => {
            isRefreshing = false;
            auth.logout();
            router.navigate(['/login']);
            return throwError(() => refreshErr);
          }),
        );
      }

      // A refresh is already in flight - wait for the new token.
      return refreshed$.pipe(
        filter((token): token is string => token !== null),
        take(1),
        switchMap((token) =>
          next(req.clone({ setHeaders: { Authorization: `Bearer ${token}` } })),
        ),
      );
    }),
  );
};
