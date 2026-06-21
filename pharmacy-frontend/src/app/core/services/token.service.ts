import { Injectable } from '@angular/core';
import { AuthUser } from '../models/auth.models';

const ACCESS_KEY = 'pharma_access_token';
const REFRESH_KEY = 'pharma_refresh_token';
const USER_KEY = 'pharma_user';

@Injectable({ providedIn: 'root' })
export class TokenService {
  get accessToken(): string | null {
    return localStorage.getItem(ACCESS_KEY);
  }

  get refreshToken(): string | null {
    return localStorage.getItem(REFRESH_KEY);
  }

  get user(): AuthUser | null {
    const raw = localStorage.getItem(USER_KEY);
    return raw ? (JSON.parse(raw) as AuthUser) : null;
  }

  store(accessToken: string, refreshToken: string, user: AuthUser): void {
    localStorage.setItem(ACCESS_KEY, accessToken);
    localStorage.setItem(REFRESH_KEY, refreshToken);
    localStorage.setItem(USER_KEY, JSON.stringify(user));
  }

  updateAccess(accessToken: string, refreshToken: string): void {
    localStorage.setItem(ACCESS_KEY, accessToken);
    localStorage.setItem(REFRESH_KEY, refreshToken);
  }

  clear(): void {
    localStorage.removeItem(ACCESS_KEY);
    localStorage.removeItem(REFRESH_KEY);
    localStorage.removeItem(USER_KEY);
  }

  /** Decode the JWT payload (without verifying the signature). */
  decode(token: string): Record<string, unknown> | null {
    try {
      const payload = token.split('.')[1];
      const json = atob(payload.replace(/-/g, '+').replace(/_/g, '/'));
      return JSON.parse(json);
    } catch {
      return null;
    }
  }

  isExpired(token: string): boolean {
    const payload = this.decode(token);
    if (!payload || typeof payload['exp'] !== 'number') {
      return true;
    }
    return (payload['exp'] as number) * 1000 < Date.now();
  }
}
