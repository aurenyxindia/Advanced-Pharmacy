import { Injectable, signal } from '@angular/core';

/** Controls the global Sales POS modal that floats above the dashboard. */
@Injectable({ providedIn: 'root' })
export class PosModalService {
  readonly open = signal(false);
  /**
   * When true, Esc must close a nested sales popup (history / quick-add / etc.)
   * and must NOT close the full-screen POS back to the dashboard.
   */
  readonly escapeBlocked = signal(false);
  private readonly escapeBlockers = new Set<string>();

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

  /** Per billing-instance Esc lock so nested modals win over POS close. */
  setEscapeBlocked(ownerId: string, blocked: boolean): void {
    if (blocked) this.escapeBlockers.add(ownerId);
    else this.escapeBlockers.delete(ownerId);
    this.escapeBlocked.set(this.escapeBlockers.size > 0);
  }

  static canSell(role: string | undefined | null): boolean {
    return !!role && PosModalService.SALES_ROLES.includes(role);
  }
}
