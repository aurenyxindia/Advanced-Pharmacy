import { Injectable, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { map, tap } from 'rxjs';
import { environment } from '../../../environments/environment';
import { ApiResponse } from '../models/auth.models';

export interface ScheduleCategory {
  code: string;
  name: string;
  requires_prescription: number;
  locked_after_sale: number;
  description: string;
}

export interface BranchOption {
  id: number;
  code: string;
  name: string;
}

export interface RoleOption {
  name: string;
  label: string;
}

@Injectable({ providedIn: 'root' })
export class MetaService {
  private readonly http = inject(HttpClient);

  readonly scheduleCategories = signal<ScheduleCategory[]>([]);
  readonly branches = signal<BranchOption[]>([]);
  readonly roles = signal<RoleOption[]>([]);
  readonly businessType = signal<'retail' | 'wholesale' | 'both'>('both');
  readonly state = signal<string | null>(null);
  readonly packSizes = signal<{ pack: string; units: number; form: string }[]>([]);

  loadPackSizes() {
    return this.http
      .get<ApiResponse<{ pack: string; units: number; form: string }[]>>(`${environment.apiUrl}/meta/pack-sizes`)
      .pipe(
        map((r) => r.data),
        tap((d) => this.packSizes.set(d)),
      );
  }

  loadProfile() {
    return this.http
      .get<ApiResponse<{ business_type: 'retail' | 'wholesale' | 'both'; state: string | null }>>(
        `${environment.apiUrl}/meta/profile`,
      )
      .pipe(
        map((r) => r.data),
        tap((d) => { this.businessType.set(d.business_type); this.state.set(d.state); }),
      );
  }

  loadScheduleCategories() {
    return this.http
      .get<ApiResponse<ScheduleCategory[]>>(`${environment.apiUrl}/meta/schedule-categories`)
      .pipe(
        map((r) => r.data),
        tap((d) => this.scheduleCategories.set(d)),
      );
  }

  loadBranches() {
    return this.http
      .get<ApiResponse<BranchOption[]>>(`${environment.apiUrl}/meta/branches`)
      .pipe(
        map((r) => r.data),
        tap((d) => this.branches.set(d)),
      );
  }

  loadRoles() {
    return this.http
      .get<ApiResponse<RoleOption[]>>(`${environment.apiUrl}/meta/roles`)
      .pipe(
        map((r) => r.data),
        tap((d) => this.roles.set(d)),
      );
  }
}
