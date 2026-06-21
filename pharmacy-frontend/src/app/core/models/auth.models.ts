export type UserRole = 'ho_admin' | 'branch_admin' | 'purchase' | 'pharmacist' | 'cashier';

/** Landing route per role. Pharmacist/cashier go straight to billing. */
export function roleHome(role: string | undefined | null): string {
  switch (role) {
    case 'pharmacist':
    case 'cashier':
      return '/app/sales/new';
    case 'purchase':
      return '/app/purchase/new';
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

export interface LoginRequest {
  email: string;
  password: string;
}

export interface RegisterRequest {
  business_name: string;
  owner_name: string;
  email: string;
  phone: string;
  drug_license_no: string;
  gstin?: string;
  address?: string;
  city?: string;
  state?: string;
  pincode?: string;
  password: string;
  password_confirmation: string;
}

export interface ApiResponse<T> {
  success: boolean;
  message: string;
  data: T;
  errors?: Record<string, string[]>;
}
