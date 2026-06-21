import { Injectable, signal } from '@angular/core';

/** Controls the global Sales POS modal that floats above the dashboard. */
@Injectable({ providedIn: 'root' })
export class PosModalService {
  readonly open = signal(false);

  /** Roles allowed to use point-of-sale billing. */
  static readonly SALES_ROLES = ['ho_admin', 'branch_admin', 'pharmacist', 'cashier'];

  show(): void {
    this.open.set(true);
  }

  hide(): void {
    this.open.set(false);
  }

  toggle(): void {
    this.open.update((v) => !v);
  }

  static canSell(role: string | undefined | null): boolean {
    return !!role && PosModalService.SALES_ROLES.includes(role);
  }
}
