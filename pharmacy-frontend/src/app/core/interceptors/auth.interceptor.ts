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

  const isAuthCall =
    req.url.includes('/auth/login') ||
    req.url.includes('/auth/register') ||
    req.url.includes('/auth/refresh');

  const attach = (token: string | null) =>
    token && !isAuthCall
      ? req.clone({ setHeaders: { Authorization: `Bearer ${token}` } })
      : req;

  const handle401 = (error: HttpErrorResponse) => {
    if (error.status !== 401 || isAuthCall || !tokens.refreshToken) {
      return throwError(() => error);
    }

    if (!isRefreshing) {
      isRefreshing = true;
      refreshed$.next(null);

      return auth.refresh().pipe(
        switchMap((res) => {
          isRefreshing = false;
          refreshed$.next(res.data.access_token);
          return next(attach(res.data.access_token));
        }),
        catchError((refreshErr) => {
          isRefreshing = false;
          auth.logout();
          router.navigate(['/login']);
          return throwError(() => refreshErr);
        }),
      );
    }

    return refreshed$.pipe(
      filter((token): token is string => token !== null),
      take(1),
      switchMap((token) => next(attach(token))),
    );
  };

  // Proactively refresh an expired access token before calling the API.
  const accessToken = tokens.accessToken;
  if (
    !isAuthCall &&
    accessToken &&
    tokens.isExpired(accessToken) &&
    tokens.refreshToken
  ) {
    if (!isRefreshing) {
      isRefreshing = true;
      refreshed$.next(null);

      return auth.refresh().pipe(
        switchMap((res) => {
          isRefreshing = false;
          refreshed$.next(res.data.access_token);
          return next(attach(res.data.access_token)).pipe(catchError(handle401));
        }),
        catchError((refreshErr) => {
          isRefreshing = false;
          auth.logout();
          router.navigate(['/login']);
          return throwError(() => refreshErr);
        }),
      );
    }

    return refreshed$.pipe(
      filter((token): token is string => token !== null),
      take(1),
      switchMap((token) => next(attach(token)).pipe(catchError(handle401))),
    );
  }

  return next(attach(accessToken)).pipe(catchError(handle401));
};
