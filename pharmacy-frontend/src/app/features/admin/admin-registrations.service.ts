import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { map, Observable } from 'rxjs';
import { environment } from '../../../environments/environment';
import { ApiResponse } from '../../core/models/auth.models';

export interface PlatformPharmacy {
  client_id: number;
  business_name: string;
  owner_name?: string;
  owner_login_name?: string;
  email?: string;
  phone?: string;
  drug_license_no?: string;
  city?: string;
  state?: string;
  business_type?: string;
  subscription_status?: string;
  subscription_plan?: string;
  registered_at?: string;
  platform_active: number;
  user_id?: number;
  user_status?: string;
  web_android?: string;
  android_expiry_date?: string | null;
}

export interface SubscriptionPlan {
  id: number;
  code: string;
  name: string;
  months: number;
  monthly_rate: number;
  discount_pct: number;
  gst_pct: number;
  lifetime_base: number | null;
  is_lifetime: boolean;
  is_active: boolean;
  sort_order: number;
  base_amount: number;
  discount_amount: number;
  gst_amount: number;
  payable: number;
  currency: string;
}

@Injectable({ providedIn: 'root' })
export class AdminRegistrationsService {
  private readonly http = inject(HttpClient);
  private readonly base = `${environment.apiUrl}/admin/registrations`;

  list(): Observable<{
    rows: PlatformPharmacy[];
    pending: PlatformPharmacy[];
    recent: PlatformPharmacy[];
  }> {
    return this.http
      .get<ApiResponse<{ rows: PlatformPharmacy[]; pending: PlatformPharmacy[]; recent: PlatformPharmacy[] }>>(
        this.base,
      )
      .pipe(map((r) => r.data));
  }

  toggle(clientId: number, active: boolean): Observable<{ client_id: number; platform_active: number }> {
    return this.http
      .post<ApiResponse<{ client_id: number; platform_active: number }>>(
        `${this.base}/${clientId}/toggle`,
        { platform_active: active ? 1 : 0 },
      )
      .pipe(map((r) => r.data));
  }

  listPlans(): Observable<SubscriptionPlan[]> {
    return this.http
      .get<ApiResponse<{ plans: SubscriptionPlan[] }>>(`${environment.apiUrl}/admin/subscription-plans`)
      .pipe(map((r) => r.data?.plans ?? []));
  }

  updatePlan(code: string, body: Partial<SubscriptionPlan>): Observable<SubscriptionPlan> {
    return this.http
      .put<ApiResponse<SubscriptionPlan>>(`${environment.apiUrl}/admin/subscription-plans/${code}`, body)
      .pipe(map((r) => r.data));
  }

  createPlan(body: {
    code: string;
    name: string;
    months: number;
    monthly_rate?: number;
    discount_pct?: number;
    gst_pct?: number;
    lifetime_base?: number | null;
    sort_order?: number;
    is_active?: boolean;
  }): Observable<SubscriptionPlan> {
    return this.http
      .post<ApiResponse<SubscriptionPlan>>(`${environment.apiUrl}/admin/subscription-plans`, body)
      .pipe(map((r) => r.data));
  }
}
