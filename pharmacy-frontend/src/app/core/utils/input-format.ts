/** Shared frontend input formatting & validation (India pharmacy forms). */

const PHONE_RE = /^[6-9]\d{9}$/;
const GSTIN_RE = /^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/;
const PINCODE_RE = /^[1-9][0-9]{5}$/;
const HSN_RE = /^\d{4,8}$/;
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

export function digitsOnly(value: string, maxLen?: number): string {
  let v = (value ?? '').replace(/\D/g, '');
  if (maxLen != null) v = v.slice(0, maxLen);
  return v;
}

export function toUpper(value: string): string {
  return (value ?? '').toUpperCase();
}

export function formatPhone(value: string): string {
  return digitsOnly(value, 10);
}

export function formatGstin(value: string): string {
  return (value ?? '').replace(/[^a-zA-Z0-9]/g, '').toUpperCase().slice(0, 15);
}

export function formatPincode(value: string): string {
  return digitsOnly(value, 6);
}

export function formatHsn(value: string): string {
  return digitsOnly(value, 8);
}

export function isValidPhone(value: string, required = false): boolean {
  const v = (value ?? '').trim();
  if (v === '') return !required;
  return PHONE_RE.test(v);
}

export function isValidGstin(value: string, required = false): boolean {
  const v = (value ?? '').trim().toUpperCase();
  if (v === '') return !required;
  return v.length === 15 && GSTIN_RE.test(v);
}

export function isValidPincode(value: string, required = false): boolean {
  const v = (value ?? '').trim();
  if (v === '') return !required;
  return PINCODE_RE.test(v);
}

export function isValidHsn(value: string, required = false): boolean {
  const v = (value ?? '').trim();
  if (v === '') return !required;
  return HSN_RE.test(v);
}

export function isValidEmail(value: string, required = false): boolean {
  const v = (value ?? '').trim();
  if (v === '') return !required;
  return EMAIL_RE.test(v);
}

export function phoneError(value: string, required = false): string | null {
  const v = (value ?? '').trim();
  if (v === '') return required ? 'Mobile number is required.' : null;
  if (v.length !== 10) return `Mobile must be exactly 10 digits (currently ${v.length}).`;
  if (!PHONE_RE.test(v)) return 'Mobile must start with 6–9.';
  return null;
}

export function gstinError(value: string, required = false): string | null {
  const v = (value ?? '').trim().toUpperCase();
  if (v === '') return required ? 'GSTIN is required.' : null;
  if (v.length !== 15) return `GSTIN must be exactly 15 characters (currently ${v.length}).`;
  if (!GSTIN_RE.test(v)) return 'Invalid GSTIN format. Example: 22AAAAA0000A1Z5';
  return null;
}

export function emailError(value: string, required = false): string | null {
  const v = (value ?? '').trim();
  if (v === '') return required ? 'Email is required.' : null;
  if (/\s/.test(v)) return 'Email cannot contain spaces.';
  if (!EMAIL_RE.test(v)) return 'Enter a valid email address.';
  if (v.length > 120) return 'Email must be at most 120 characters.';
  return null;
}

export function addressError(value: string, required = false): string | null {
  const v = (value ?? '').trim();
  if (v === '') return required ? 'Address is required.' : null;
  if (v.length < 3) return 'Address must be at least 3 characters.';
  if (v.length > 255) return 'Address must be at most 255 characters.';
  return null;
}

/** Reminder / follow-up days on retail sale (optional unless required). */
export function reminderDaysError(value: number | string | null | undefined, required = false): string | null {
  if (value === null || value === undefined || value === '') {
    return required ? 'Reminder days is required.' : null;
  }
  const n = typeof value === 'number' ? value : Number(String(value).trim());
  if (!Number.isFinite(n)) return 'Enter a valid number of days.';
  if (!Number.isInteger(n)) return 'Reminder days must be a whole number.';
  if (n < 0) return 'Reminder days cannot be negative.';
  if (n > 365) return 'Reminder days cannot exceed 365.';
  return null;
}

/** Keep reminder days as a non-negative integer (or null if empty). */
export function formatReminderDays(value: unknown): number | null {
  if (value === null || value === undefined || value === '') return null;
  const n = Math.trunc(Number(value));
  if (!Number.isFinite(n)) return null;
  if (n < 0) return 0;
  if (n > 365) return 365;
  return n;
}

/** Normalize any expiry value to YYYY-MM for month inputs / display (Retail parity). */
export function formatExpiryMonth(raw: unknown): string {
  const s = String(raw ?? '').trim();
  if (!s) return '';
  if (/^\d{4}-\d{2}$/.test(s)) return s;
  if (/^\d{4}-\d{2}-\d{2}/.test(s)) return s.slice(0, 7);
  const m = s.match(/^(\d{1,2})[/-](\d{2,4})$/);
  if (m) {
    const month = m[1].padStart(2, '0');
    const year = m[2].length === 2 ? `20${m[2]}` : m[2];
    return `${year}-${month}`;
  }
  const ts = Date.parse(s);
  if (!Number.isNaN(ts)) return new Date(ts).toISOString().slice(0, 7);
  return '';
}

/** Convert YYYY-MM (or legacy date) to API date string for save. */
export function expiryForApiSave(raw: string): string {
  const s = String(raw ?? '').trim();
  if (/^\d{4}-\d{2}-\d{2}$/.test(s)) return s;
  const ym = formatExpiryMonth(s);
  return ym ? `${ym}-01` : s;
}
