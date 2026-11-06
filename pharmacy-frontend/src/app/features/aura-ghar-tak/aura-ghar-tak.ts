import { Component, OnDestroy, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { AgtCustomer, AgtOrder, AgtRider, AgtService } from '../../core/services/agt.service';
import { UserRightsService } from '../../core/services/user-rights.service';

@Component({
  selector: 'app-aura-ghar-tak',
  imports: [CommonModule, FormsModule],
  templateUrl: './aura-ghar-tak.html',
  styleUrl: './aura-ghar-tak.scss',
})
export class AuraGharTakPage implements OnInit, OnDestroy {
  private readonly agt = inject(AgtService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly rights = inject(UserRightsService);

  tab = signal<'orders' | 'send'>('orders');
  statusFilter = signal('Placed');
  counts = signal<Record<string, number>>({});
  orders = signal<AgtOrder[]>([]);
  loadingOrders = signal(false);
  selectedOrder = signal<AgtOrder | null>(null);
  riders = signal<AgtRider[]>([]);
  riderChoice = 0;
  assigningRider = signal(false);

  customerQ = '';
  customers = signal<AgtCustomer[]>([]);
  selectedCustomer = signal<AgtCustomer | null>(null);
  searchingCustomers = signal(false);
  sharePatientMode = signal<'existing' | 'new'>('existing');
  newPatientName = '';
  newPatientContact = '';
  newPatientAddress = '';
  sending = signal(false);

  message = signal<string | null>(null);
  error = signal<string | null>(null);
  lastShopUrl = signal('');
  lastContact = signal('');
  whatsappSent = signal(false);
  copyDone = signal(false);
  showSharePopup = signal(false);
  sharePopupPatient = signal('');

  /** Shop-level: patient can cancel Placed orders online (not per product). */
  allowCustomerCancel = signal(false);
  settingsLoading = signal(false);
  settingsSaving = signal(false);

  private pollTimer: ReturnType<typeof setInterval> | null = null;
  private customerSearchTimer: ReturnType<typeof setTimeout> | null = null;

  readonly statusTabs = ['Placed', 'Ready', 'Out for Delivery', 'Delivered', 'Cancelled', 'All'];

  canViewAgt(): boolean {
    return this.rights.hasFullAccess() || this.rights.canView('AURA_GHAR_TAK') || this.rights.canEdit('AURA_GHAR_TAK');
  }

  canEditAgt(): boolean {
    return this.rights.hasFullAccess() || this.rights.canEdit('AURA_GHAR_TAK');
  }

  ngOnInit(): void {
    this.loadCounts();
    this.loadOrders();
    this.loadBranchSettings();
    const openId = Number(this.route.snapshot.queryParamMap.get('openOrder')) || 0;
    if (openId > 0) {
      this.openOrder({ id: openId } as AgtOrder);
    }
    this.pollTimer = setInterval(() => {
      this.loadCounts();
      if (this.tab() === 'orders') {
        this.loadOrders(false);
      }
    }, 45000);
  }

  ngOnDestroy(): void {
    if (this.pollTimer) clearInterval(this.pollTimer);
    if (this.customerSearchTimer) clearTimeout(this.customerSearchTimer);
  }

  loadBranchSettings(): void {
    if (!this.canViewAgt()) return;
    this.settingsLoading.set(true);
    this.agt.getBranchSettings().subscribe({
      next: (s) => {
        this.allowCustomerCancel.set(!!s.allow_customer_cancel);
        this.settingsLoading.set(false);
      },
      error: () => this.settingsLoading.set(false),
    });
  }

  saveCustomerCancelPolicy(allow: boolean): void {
    if (!this.canEditAgt()) return;
    this.settingsSaving.set(true);
    this.error.set(null);
    this.agt.saveBranchSettings(allow).subscribe({
      next: (res) => {
        this.allowCustomerCancel.set(!!res.data?.allow_customer_cancel);
        this.message.set(res.message ?? (allow ? 'Online cancellation enabled.' : 'Online cancellation disabled.'));
        this.settingsSaving.set(false);
      },
      error: (err) => {
        this.settingsSaving.set(false);
        this.error.set(err.error?.message ?? 'Could not save shop policy.');
      },
    });
  }

  setTab(t: 'orders' | 'send'): void {
    this.tab.set(t);
    this.message.set(null);
    this.error.set(null);
    if (t === 'orders') this.loadOrders();
  }

  setShareMode(mode: 'existing' | 'new'): void {
    this.sharePatientMode.set(mode);
    this.error.set(null);
    this.message.set(null);
    if (mode === 'new') {
      this.selectedCustomer.set(null);
      this.customers.set([]);
      this.customerQ = '';
    } else {
      this.newPatientName = '';
      this.newPatientContact = '';
      this.newPatientAddress = '';
    }
  }

  loadCounts(): void {
    this.agt.orderCounts().subscribe({
      next: (c) => this.counts.set(c),
      error: () => {},
    });
  }

  loadOrders(showSpinner = true): void {
    if (showSpinner) this.loadingOrders.set(true);
    this.agt.listOrders(this.statusFilter()).subscribe({
      next: (rows) => {
        this.orders.set(rows);
        this.loadingOrders.set(false);
      },
      error: () => {
        this.loadingOrders.set(false);
        this.error.set('Could not load orders');
      },
    });
  }

  onCustomerQChange(): void {
    this.selectedCustomer.set(null);
    if (this.customerSearchTimer) clearTimeout(this.customerSearchTimer);
    const q = (this.customerQ || '').trim();
    if (q.length < 2) {
      this.customers.set([]);
      return;
    }
    this.customerSearchTimer = setTimeout(() => this.searchCustomers(), 300);
  }

  searchCustomers(): void {
    const q = (this.customerQ || '').trim();
    if (!q) {
      this.customers.set([]);
      return;
    }
    this.searchingCustomers.set(true);
    this.agt.searchCustomers(q).subscribe({
      next: (rows) => {
        this.searchingCustomers.set(false);
        this.customers.set(rows);
        if (!rows.length) {
          this.error.set(`No patient found for "${q}". Check Customer Master.`);
        }
      },
      error: () => {
        this.searchingCustomers.set(false);
        this.customers.set([]);
        this.error.set('Could not load customers');
      },
    });
  }

  pickCustomer(c: AgtCustomer): void {
    this.selectedCustomer.set(c);
    this.customers.set([]);
    this.customerQ = (c.c_name || '') + (c.c_cont ? ' · ' + c.c_cont : '');
  }

  private sharePatient(): {
    patient_no: string;
    patient_name: string;
    patient_contact: string;
    patient_address: string;
    displayName: string;
  } | null {
    if (this.sharePatientMode() === 'existing') {
      const c = this.selectedCustomer();
      if (!c) return null;
      return {
        patient_no: String(c.c_no ?? c.id ?? ''),
        patient_name: String(c.c_name ?? ''),
        patient_contact: String(c.c_cont ?? ''),
        patient_address: String(c.c_add ?? ''),
        displayName: String(c.c_name ?? 'customer'),
      };
    }
    const name = (this.newPatientName || '').trim();
    const contact = (this.newPatientContact || '').replace(/[^0-9]/g, '');
    if (!name) {
      this.error.set('Enter patient name');
      return null;
    }
    if (contact.length < 10) {
      this.error.set('Enter valid 10-digit mobile number');
      return null;
    }
    return {
      patient_no: '',
      patient_name: name,
      patient_contact: contact.slice(-10),
      patient_address: (this.newPatientAddress || '').trim(),
      displayName: name,
    };
  }

  sendStockLink(): void {
    this.createShare(true);
  }

  createLinkOnly(): void {
    this.createShare(false);
  }

  private createShare(sendWa: boolean): void {
    const patient = this.sharePatient();
    if (!patient) {
      if (!this.error()) {
        this.error.set(
          this.sharePatientMode() === 'existing'
            ? 'Select a patient from customer master first'
            : 'Enter patient name and mobile number',
        );
      }
      return;
    }
    this.sending.set(true);
    this.error.set(null);
    this.message.set(null);
    this.lastShopUrl.set('');
    this.agt
      .createShare({
        patient_no: patient.patient_no,
        patient_name: patient.patient_name,
        patient_contact: patient.patient_contact,
        patient_address: patient.patient_address,
        send_whatsapp: sendWa,
      })
      .subscribe({
        next: (res) => {
          this.sending.set(false);
          const d = res.data;
          if (d?.shop_url) {
            this.lastShopUrl.set(d.shop_url);
            this.lastContact.set(patient.patient_contact.slice(-10));
            this.whatsappSent.set(!!d.whatsapp_sent);
            this.sharePopupPatient.set(patient.displayName);
            this.showSharePopup.set(true);
            this.copyDone.set(false);
            if (d.whatsapp_sent) {
              this.message.set(res.message || d.message || 'Stock link sent on WhatsApp');
              this.error.set(null);
            } else {
              this.message.set(null);
              this.error.set(d.message || 'Link created — share manually if WhatsApp failed.');
            }
          } else {
            this.error.set(res.message || 'Failed to create link');
          }
        },
        error: (err) => {
          this.sending.set(false);
          this.error.set(err.error?.message ?? 'Could not create link');
        },
      });
  }

  copyShopLink(): void {
    const url = this.lastShopUrl();
    if (!url) {
      this.error.set('No link yet. Send or create a link first.');
      return;
    }
    const done = (): void => {
      this.copyDone.set(true);
      this.message.set('Link copied to clipboard');
      this.error.set(null);
      setTimeout(() => this.copyDone.set(false), 2500);
    };
    if (navigator.clipboard?.writeText) {
      navigator.clipboard.writeText(url).then(done).catch(() => {
        prompt('Copy this link:', url);
        done();
      });
    } else {
      prompt('Copy this link:', url);
      done();
    }
  }

  isPrescriptionPdf(url?: string): boolean {
    return String(url ?? '').toLowerCase().includes('.pdf');
  }

  closeSharePopup(): void {
    this.showSharePopup.set(false);
  }

  openOrder(o: AgtOrder): void {
    this.agt.getOrder(o.id).subscribe({
      next: (order) => {
        this.selectedOrder.set(order);
        this.riderChoice = Number(order?.rider_user_id ?? 0) || 0;
        if (this.canEditAgt() && !this.riders().length) {
          this.agt.listRiders().subscribe({ next: (rows) => this.riders.set(rows), error: () => {} });
        }
      },
      error: () => this.error.set('Could not load order details'),
    });
  }

  canAssignRider(o: AgtOrder): boolean {
    return this.canEditAgt() && !['Delivered', 'Cancelled'].includes(o.status);
  }

  saveRider(): void {
    const o = this.selectedOrder();
    if (!o || this.assigningRider()) return;
    this.assigningRider.set(true);
    this.agt.assignRider(o.id, this.riderChoice).subscribe({
      next: (res) => {
        this.assigningRider.set(false);
        this.message.set(res.message ?? 'Rider updated.');
        this.openOrder(o);
      },
      error: (err) => {
        this.assigningRider.set(false);
        this.error.set(err.error?.message ?? 'Could not assign rider');
      },
    });
  }

  closeOrder(): void {
    this.selectedOrder.set(null);
  }

  doSale(): void {
    const o = this.selectedOrder();
    if (!o) return;
    if (o.status !== 'Placed' || (o.sale_id != null && Number(o.sale_id) > 0)) {
      this.error.set(
        o.sale_invoice_no
          ? `This order is already billed (${o.sale_invoice_no}). Use Ready tab for delivery.`
          : 'This order cannot be billed again.',
      );
      return;
    }
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
    this.selectedOrder.set(null);
    void this.router.navigate(['/app/sales/new'], {
      queryParams: {
        agt_order_id: o.id,
        patient_contact: o.patient_contact,
        patient_name: o.patient_name,
        patient_no: o.patient_no ?? '',
      },
    });
  }

  updateStatus(status: string, sendWhatsApp = true): void {
    const o = this.selectedOrder();
    if (!o) return;
    const label =
      status === 'Out for Delivery'
        ? sendWhatsApp
          ? 'Send WhatsApp with bill PDF to patient and mark Out for Delivery?'
          : 'Mark Out for Delivery without sending WhatsApp?'
        : status === 'Delivered'
          ? 'Mark as Delivered and notify patient?'
          : 'Cancel this order?';
    if (!confirm(label)) return;
    this.agt.updateStatus(o.id, status, sendWhatsApp).subscribe({
      next: (res) => {
        let msg = res.message ?? 'Updated';
        const wa = (res.data as { whatsapp?: { ok?: boolean; error?: string } } | undefined)?.whatsapp;
        if (wa?.ok) {
          msg += ' WhatsApp sent to patient.';
        } else if (wa && wa.ok === false && wa.error) {
          msg += ` WhatsApp was not sent: ${wa.error}`;
        }
        this.message.set(msg);
        this.selectedOrder.set(null);
        this.loadCounts();
        this.loadOrders();
      },
      error: (err) => this.error.set(err.error?.message ?? 'Update failed'),
    });
  }

  statusClass(st: string): string {
    const s = (st || '').toLowerCase();
    if (s === 'placed') return 'text-bg-warning';
    if (s === 'ready') return 'text-bg-info';
    if (s.includes('out')) return 'text-bg-primary';
    if (s === 'delivered') return 'text-bg-success';
    if (s === 'cancelled') return 'text-bg-secondary';
    return 'text-bg-light';
  }
}
