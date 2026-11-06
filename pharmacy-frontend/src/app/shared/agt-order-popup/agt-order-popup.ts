import { Component, Input, inject, signal, OnInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { Router } from '@angular/router';
import { environment } from '../../../environments/environment';
import {
  AgtOrderNotification,
  AgtOrderNotificationService,
} from '../../core/services/agt-order-notification.service';
import { AgtOrder } from '../../core/services/agt.service';
import { ProfileService } from '../../core/services/profile.service';
import { AuthService } from '../../core/services/auth.service';

@Component({
  selector: 'app-agt-order-popup',
  imports: [CommonModule],
  templateUrl: './agt-order-popup.html',
  styleUrl: './agt-order-popup.scss',
})
export class AgtOrderPopup implements OnInit, OnDestroy {
  @Input({ required: true }) notification!: AgtOrderNotification | null;

  private readonly agtNotify = inject(AgtOrderNotificationService);
  private readonly router = inject(Router);
  private readonly profileSvc = inject(ProfileService);
  private readonly auth = inject(AuthService);
  private readonly http = inject(HttpClient);

  readonly paymentQrUrl = signal<string | null>(null);
  private qrObjectUrl: string | null = null;

  ngOnInit(): void {
    this.profileSvc.load().subscribe({
      next: (p) => {
        const cid = p.client_id || this.auth.user()?.client_id;
        if (!cid || !p.assets?.['scanner']) return;
        this.http
          .get(`${environment.apiUrl}/profile/assets/${cid}/scanner`, { responseType: 'blob' })
          .subscribe({
            next: (blob) => {
              this.qrObjectUrl = URL.createObjectURL(blob);
              this.paymentQrUrl.set(this.qrObjectUrl);
            },
            error: () => {},
          });
      },
      error: () => {},
    });
  }

  ngOnDestroy(): void {
    if (this.qrObjectUrl) {
      URL.revokeObjectURL(this.qrObjectUrl);
    }
  }

  dismiss(): void {
    this.agtNotify.dismissActive();
  }

  order(): AgtOrder | null {
    return this.notification?.order ?? null;
  }

  isLoading(): boolean {
    return !!this.notification?.loading;
  }

  canDoSale(): boolean {
    const o = this.order();
    if (this.isLoading() || !o) return false;
    if (o.status !== 'Placed') return false;
    return !(o.sale_id != null && Number(o.sale_id) > 0);
  }

  doSale(): void {
    const o = this.order();
    if (!o || !this.canDoSale()) return;
    try {
      sessionStorage.setItem(
        'agt_do_sale',
        JSON.stringify({
          order_id: o.id,
          order_no: o.order_no,
          patient_no: o.patient_no ?? '',
          patient_name: o.patient_name,
          patient_contact: o.patient_contact,
          patient_address: o.patient_address ?? '',
        }),
      );
    } catch {
      /* ignore */
    }
    this.dismiss();
    void this.router.navigate(['/app/sales/new'], {
      queryParams: {
        agt_order_id: o.id,
        patient_contact: o.patient_contact,
        patient_name: o.patient_name,
        patient_no: o.patient_no ?? '',
      },
    });
  }

  goToAuraGharTak(): void {
    const id = this.order()?.id ?? this.notification?.orderId ?? 0;
    this.dismiss();
    void this.router.navigate(['/app/aura-ghar-tak'], {
      queryParams: id ? { openOrder: id } : {},
    });
  }

  isPrescriptionPdf(): boolean {
    const u = String(this.order()?.prescription_url ?? '').toLowerCase();
    return u.includes('.pdf');
  }

  patientInitial(): string {
    const name = String(this.order()?.patient_name ?? 'P').trim();
    return name.charAt(0).toUpperCase();
  }

  itemCount(): number {
    return this.order()?.items?.length ?? 0;
  }

  itemName(it: { product_name?: string; p_name?: string }): string {
    return it.product_name ?? it.p_name ?? '';
  }
}
