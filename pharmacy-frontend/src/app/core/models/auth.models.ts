export type UserRole = 'ho_admin' | 'branch_admin' | 'purchase' | 'pharmacist' | 'cashier';

/** Every authenticated app role. */
export const APP_ROLES: UserRole[] = ['ho_admin', 'branch_admin', 'purchase', 'pharmacist', 'cashier'];

/** Roles that manage branch ops masters (customers, doctors, purchase flows). */
export const MASTERS_OPS_ROLES: UserRole[] = ['ho_admin', 'branch_admin', 'purchase'];

/**
 * Landing route per role. Sales roles land on the dashboard with the POS
 * popup floating over it; purchase officers go straight to purchase entry.
 */
export function roleHome(role: string | undefined | null): string {
  switch (role) {
    case 'purchase':
      return '/app/purchase';
    default:
      return '/app/dashboard';
  }
}

export interface AuthUser {
  id: number;
  name: string;
  email: string;
  role: UserRole;
  client_id: number | null;
  branch_id: number | null;
  scope?: 'ho' | 'branch';
}

export interface AuthTokens {
  access_token: string;
  refresh_token: string;
  token_type: string;
  expires_in: number;
  user: AuthUser;
}

/** `email` accepts registered email or 10-digit mobile (API field name unchanged). */
export interface LoginRequest {
  email: string;
  password: string;
}

export type BusinessType = 'retail' | 'wholesale' | 'both';

export interface RegisterRequest {
  business_type: BusinessType;
  business_name: string;
  owner_name: string;
  email: string;
  phone: string;
  drug_license_no: string;
  gstin?: string;
  pan?: string;
  fssai_no?: string;
  address?: string;
  city?: string;
  state?: string;
  pincode?: string;
  password: string;
  password_confirmation: string;
  access_channel?: 'web' | 'android';
  plan_code?: string;
}

export interface ApiResponse<T> {
  success: boolean;
  message: string;
  data: T;
  errors?: Record<string, string[]>;
}
