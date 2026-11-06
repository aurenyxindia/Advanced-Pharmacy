import { Injectable, computed, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, tap } from 'rxjs';
import { environment } from '../../../environments/environment';
import { TokenService } from './token.service';
import { UserRightsService } from './user-rights.service';
import { SalesDraftService } from './sales-draft.service';
import { PurchaseDraftService } from './purchase-draft.service';
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
  private readonly rights = inject(UserRightsService);
  private readonly salesDraft = inject(SalesDraftService);
  private readonly purchaseDraft = inject(PurchaseDraftService);
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

  sendForgotPasswordOtp(username: string): Observable<ApiResponse<{ email: string; notice?: string }>> {
    return this.http.post<ApiResponse<{ email: string; notice?: string }>>(
      `${this.base}/forgot-password/send-otp`,
      { username },
    );
  }

  resetForgotPassword(payload: {
    username: string;
    security_code: string;
    password: string;
  }): Observable<ApiResponse<null>> {
    return this.http.post<ApiResponse<null>>(`${this.base}/forgot-password/reset`, payload);
  }

  register(payload: RegisterRequest): Observable<ApiResponse<{ pending_activation?: boolean; email?: string; client_id?: number }>> {
    // No auto-login — account stays pending until platform Admin activates.
    return this.http.post<ApiResponse<{ pending_activation?: boolean; email?: string; client_id?: number }>>(
      `${this.base}/register`,
      payload,
    );
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
    this.salesDraft.clear();
    this.purchaseDraft.clearAll();
    this.tokens.clear();
    this.currentUser.set(null);
    this.rights.reset();
  }

  private handleAuth(data: AuthTokens): void {
    this.tokens.store(data.access_token, data.refresh_token, data.user);
    this.currentUser.set(data.user);
    this.rights.reset();
    this.rights.loadMyRights().subscribe({ error: () => undefined });
  }
}
