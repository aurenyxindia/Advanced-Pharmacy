import { Injectable, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { catchError, finalize, map, Observable, of, shareReplay, tap } from 'rxjs';
import { environment } from '../../../environments/environment';
import { ApiResponse } from '../models/auth.models';
import { TokenService } from './token.service';

export interface ModuleRight {
  module_code: string;
  module_name: string;
  can_view: number | boolean;
  can_edit: number | boolean;
  can_delete: number | boolean;
}

export interface RightsUser {
  id: number;
  name: string;
  email: string;
  phone?: string;
  reg_no?: string;
  role: string;
  branch_id: number;
  joining_date?: string;
  address?: string;
  rights: ModuleRight[];
}

export interface UserRightsFormPayload {
  id?: number;
  name: string;
  email: string;
  phone?: string;
  reg_no?: string;
  staff_role: string;
  branch_id: number;
  password?: string;
  joining_date?: string;
  address?: string;
  rights: ModuleRight[];
}

@Injectable({ providedIn: 'root' })
export class UserRightsService {
  private readonly http = inject(HttpClient);
  private readonly tokens = inject(TokenService);
  private readonly base = `${environment.apiUrl}/user-rights`;

  private readonly admin = signal(false);
  private readonly ready = signal(false);
  private readonly map = signal<Record<string, ModuleRight>>({});
  private load$: Observable<ModuleRight[]> | null = null;

  readonly isAdmin = this.admin.asReadonly();
  readonly isReady = this.ready.asReadonly();

  /** HO / branch admin always bypass the staff rights matrix (Retail parity). */
  hasFullAccess(): boolean {
    if (this.admin()) return true;
    return this.isAdminUser(this.tokens.user?.role);
  }

  /** Load (or reuse in-flight) rights for the logged-in user. */
  loadMyRights(): Observable<ModuleRight[]> {
    if (this.ready()) {
      return of(Object.values(this.map()));
    }
    if (this.load$) {
      return this.load$;
    }
    // Nav/guards can run before /me returns — honour admin role from stored session immediately.
    if (this.isAdminUser(this.tokens.user?.role)) {
      this.admin.set(true);
    }
    this.load$ = this.http
      .get<ApiResponse<{ is_admin: boolean; rights: ModuleRight[] }>>(`${this.base}/me`)
      .pipe(
        tap((res) => this.apply(res.data.is_admin, res.data.rights ?? [])),
        map((res) => res.data.rights ?? []),
        catchError(() => {
          const isAdmin = this.isAdminUser(this.tokens.user?.role);
          this.apply(isAdmin, []);
          return of([]);
        }),
        finalize(() => {
          this.load$ = null;
        }),
        shareReplay(1),
      );
    return this.load$;
  }

  /** Clear cached rights (call on logout). */
  reset(): void {
    this.admin.set(false);
    this.ready.set(false);
    this.map.set({});
    this.load$ = null;
  }

  modules(): Observable<ModuleRight[]> {
    return this.http
      .get<ApiResponse<Array<{ code?: string; name?: string; module_code?: string; module_name?: string }>>>(
        `${this.base}/modules`,
      )
      .pipe(
        map((r) => {
          // Always collapse Debit/Credit into one Return Note row (covers retail sales return too).
          const byCode = new Map<string, ModuleRight>();
          for (const m of r.data ?? []) {
            let code = (m.module_code || m.code || '').toUpperCase();
            if (!code) continue;
            if (code === 'DEBIT_NOTE' || code === 'CREDIT_NOTE') {
              code = 'RETURN_NOTE';
            }
            if (byCode.has(code)) continue;
            byCode.set(code, {
              module_code: code,
              module_name: code === 'RETURN_NOTE' ? 'Return Note' : m.module_name || m.name || code,
              can_view: 0,
              can_edit: 0,
              can_delete: 0,
            });
          }
          if (!byCode.has('RETURN_NOTE')) {
            byCode.set('RETURN_NOTE', {
              module_code: 'RETURN_NOTE',
              module_name: 'Return Note',
              can_view: 0,
              can_edit: 0,
              can_delete: 0,
            });
          }
          return Array.from(byCode.values());
        }),
      );
  }

  users(): Observable<RightsUser[]> {
    return this.http.get<ApiResponse<RightsUser[]>>(`${this.base}/users`).pipe(map((r) => r.data));
  }

  saveUser(payload: UserRightsFormPayload): Observable<{ id: number }> {
    return this.http.post<ApiResponse<{ id: number }>>(`${this.base}/users`, payload).pipe(map((r) => r.data));
  }

  deleteUser(id: number): Observable<void> {
    return this.http.delete<ApiResponse<null>>(`${this.base}/users/${id}`).pipe(map(() => undefined));
  }

  canView(code: string): boolean {
    if (this.hasFullAccess()) return true;
    if (!this.ready()) return false;
    const key = this.normalizeCode(code);
    const r = this.map()[key];
    if (!r) return false;
    // Edit/Delete imply View (Retail saveRights also forces view when edit/delete).
    return this.flag(r.can_view) || this.flag(r.can_edit) || this.flag(r.can_delete);
  }

  canEdit(code: string): boolean {
    if (this.hasFullAccess()) return true;
    if (!this.ready()) return false;
    const r = this.map()[this.normalizeCode(code)];
    return !!(r && this.flag(r.can_edit));
  }

  canDelete(code: string): boolean {
    if (this.hasFullAccess()) return true;
    if (!this.ready()) return false;
    const r = this.map()[this.normalizeCode(code)];
    return !!(r && this.flag(r.can_delete));
  }

  /**
   * First screen the user may open after login / when a route is denied.
   * HOME requires canView; Sales/Purchase logs are always allowed (openLog).
   */
  firstAllowedRoute(): string {
    if (this.hasFullAccess()) {
      return '/app/dashboard';
    }
    const order: Array<{ code: string; route: string; openLog?: boolean }> = [
      { code: 'HOME', route: '/app/dashboard' },
      { code: 'SALES', route: '/app/sales', openLog: true },
      { code: 'PURCHASE', route: '/app/purchase', openLog: true },
      { code: 'MASTERS', route: '/app/masters' },
      { code: 'ALL_REPORTS', route: '/app/reports' },
      { code: 'IMPORT_BILL', route: '/app/purchase/import' },
      { code: 'OPENING_STOCK', route: '/app/opening-stock' },
      { code: 'PURCHASE_ORDER', route: '/app/purchase-order' },
      { code: 'PAYMENTS', route: '/app/payments' },
      { code: 'RETURN_NOTE', route: '/app/return-note' },
      { code: 'DAILY_CLOSING', route: '/app/daily-closing' },
      { code: 'IPD', route: '/app/ipd' },
      { code: 'REFILLS', route: '/app/refills' },
      { code: 'DELIVERIES', route: '/app/deliveries' },
      { code: 'AURA_GHAR_TAK', route: '/app/aura-ghar-tak' },
      { code: 'AURA_AI', route: '/app/dashboard' },
      { code: 'MY_PROFILE', route: '/app/profile' },
    ];
    for (const item of order) {
      if (item.openLog) {
        return item.route;
      }
      if (this.canView(item.code) || this.canEdit(item.code)) {
        return item.route;
      }
    }
    return '/app/profile';
  }

  isAdminUser(role: string | undefined | null): boolean {
    return role === 'ho_admin' || role === 'branch_admin';
  }

  private apply(isAdmin: boolean, rights: ModuleRight[]): void {
    this.admin.set(isAdmin || this.isAdminUser(this.tokens.user?.role));
    const m: Record<string, ModuleRight> = {};
    for (const r of rights) {
      const code = this.normalizeCode(r.module_code || '');
      if (!code) continue;
      const view = this.flag(r.can_view);
      const edit = this.flag(r.can_edit);
      const del = this.flag(r.can_delete);
      if (!view && !edit && !del) continue;
      const prev = m[code];
      m[code] = {
        ...r,
        module_code: code,
        module_name: code === 'RETURN_NOTE' ? 'Return Note' : r.module_name,
        can_view: view || edit || del || this.flag(prev?.can_view) ? 1 : 0,
        can_edit: edit || this.flag(prev?.can_edit) ? 1 : 0,
        can_delete: del || this.flag(prev?.can_delete) ? 1 : 0,
      };
    }
    this.map.set(m);
    this.ready.set(true);
  }

  /** Legacy DEBIT_NOTE / CREDIT_NOTE map to unified RETURN_NOTE. */
  private normalizeCode(code: string): string {
    const c = code.toUpperCase();
    if (c === 'DEBIT_NOTE' || c === 'CREDIT_NOTE') return 'RETURN_NOTE';
    return c;
  }

  private flag(v: unknown): boolean {
    return v === 1 || v === true || v === '1';
  }
}
