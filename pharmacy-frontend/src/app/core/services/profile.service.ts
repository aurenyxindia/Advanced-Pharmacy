import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { map, Observable } from 'rxjs';
import { environment } from '../../../environments/environment';
import { ApiResponse } from '../models/auth.models';

export interface ClientProfile {
  business_name: string;
  contact_person: string;
  email: string;
  phone: string;
  /** Extra WhatsApp number for daily closing (editable). */
  alternate_phone?: string;
  gstin: string;
  pan: string;
  fssai_no: string;
  bank_name: string;
  account_no: string;
  ifsc_code: string;
  dob: string | null;
  address: string;
  state: string;
  city: string;
  pincode: string;
  drug_license_no: string;
  registration_locked?: boolean;
  plan_type: string;
  business_type: string;
  client_id: number;
  assets: Record<string, string | null>;
  /** When true, shared product catalogue is hidden — only this client’s products. */
  hide_master_products?: boolean;
  /** When true, shared distributor catalogue is hidden — only this client’s distributors. */
  hide_master_distributors?: boolean;
}

/** Editable profile fields (registration data is read-only in My Profile). */
export interface ProfileUpdatePayload {
  email?: string;
  bank_name?: string;
  account_no?: string;
  ifsc_code?: string;
  dob?: string | null;
  alternate_phone?: string;
  hide_master_products?: boolean;
  hide_master_distributors?: boolean;
}

@Injectable({ providedIn: 'root' })
export class ProfileService {
  private readonly http = inject(HttpClient);
  private readonly base = `${environment.apiUrl}/profile`;

  load(): Observable<ClientProfile> {
    return this.http.get<ApiResponse<ClientProfile>>(this.base).pipe(map((r) => r.data));
  }

  save(payload: ProfileUpdatePayload): Observable<void> {
    return this.http.put<ApiResponse<null>>(this.base, payload).pipe(map(() => undefined));
  }

  upload(type: string, file: File): Observable<{ type: string; url: string }> {
    const fd = new FormData();
    fd.append('file', file, file.name || `${type}.bin`);
    return this.http
      .post<ApiResponse<{ type: string; url: string }>>(`${this.base}/upload/${type}`, fd)
      .pipe(map((r) => r.data));
  }

  assetBlob(clientId: number, type: string): Observable<Blob> {
    return this.http.get(`${this.base}/assets/${clientId}/${type}`, { responseType: 'blob' });
  }
}
