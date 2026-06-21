import { Injectable, computed, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, tap } from 'rxjs';
import { environment } from '../../../environments/environment';
import { TokenService } from './token.service';
import {
  ApiResponse,
  AuthTokens,
  AuthUser,
  LoginRequest,
  RegisterRequest,
} from '../models/auth.models';

@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly http = inject(HttpClient);
  private readonly tokens = inject(TokenService);
  private readonly base = `${environment.apiUrl}/auth`;

  private readonly currentUser = signal<AuthUser | null>(this.tokens.user);

  readonly user = this.currentUser.asReadonly();
  readonly isAuthenticated = computed(() => this.currentUser() !== null);
  readonly isHoAdmin = computed(() => this.currentUser()?.role === 'ho_admin');

  login(payload: LoginRequest): Observable<ApiResponse<AuthTokens>> {
    return this.http
      .post<ApiResponse<AuthTokens>>(`${this.base}/login`, payload)
      .pipe(tap((res) => this.handleAuth(res.data)));
  }

  register(payload: RegisterRequest): Observable<ApiResponse<AuthTokens>> {
    return this.http
      .post<ApiResponse<AuthTokens>>(`${this.base}/register`, payload)
      .pipe(tap((res) => this.handleAuth(res.data)));
  }

  refresh(): Observable<ApiResponse<AuthTokens>> {
    return this.http
      .post<ApiResponse<AuthTokens>>(`${this.base}/refresh`, {
        refresh_token: this.tokens.refreshToken,
      })
      .pipe(
        tap((res) => {
          this.tokens.updateAccess(res.data.access_token, res.data.refresh_token);
        }),
      );
  }

  logout(): void {
    const refresh = this.tokens.refreshToken;
    if (refresh) {
      this.http.post(`${this.base}/logout`, { refresh_token: refresh }).subscribe({
        error: () => undefined,
      });
    }
    this.tokens.clear();
    this.currentUser.set(null);
  }

  private handleAuth(data: AuthTokens): void {
    this.tokens.store(data.access_token, data.refresh_token, data.user);
    this.currentUser.set(data.user);
  }
}
