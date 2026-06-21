import { Component, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { Admission, AdmissionDetail, IpdService } from '../../core/services/ipd.service';

@Component({
  selector: 'app-ipd',
  imports: [CommonModule, FormsModule],
  templateUrl: './ipd.html',
  styleUrl: './ipd.scss',
})
export class Ipd {
  private readonly ipd = inject(IpdService);
  private readonly router = inject(Router);

  readonly rows = signal<Admission[]>([]);
  readonly loading = signal(false);
  readonly filter = signal('admitted');

  readonly selected = signal<AdmissionDetail | null>(null);

  // admit modal
  readonly admitOpen = signal(false);
  readonly admitForm = signal({ patient_name: '', contact_no: '', doctor_name: '', ward: '', bed_no: '', address: '' });
  readonly saving = signal(false);

  // payment modal
  readonly payOpen = signal(false);
  readonly payForm = signal({ amount: 0, mode: 'cash', note: '' });

  readonly toast = signal<string | null>(null);
  readonly error = signal<string | null>(null);

  readonly openCount = computed(() => this.rows().filter((r) => r.status === 'admitted').length);

  constructor() { this.load(); }

  load(): void {
    this.loading.set(true);
    this.ipd.list(this.filter() || undefined).subscribe({
      next: (d) => { this.rows.set(d); this.loading.set(false); },
      error: () => this.loading.set(false),
    });
  }
  setFilter(f: string): void { this.filter.set(f); this.load(); }

  // ---- detail ----
  openDetail(id: number): void {
    this.ipd.get(id).subscribe((d) => this.selected.set(d));
  }
  closeDetail(): void { this.selected.set(null); }

  // ---- admit ----
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  setAdmit(field: string, value: any): void { this.admitForm.update((f) => ({ ...f, [field]: value })); }
  openAdmit(): void {
    this.admitForm.set({ patient_name: '', contact_no: '', doctor_name: '', ward: '', bed_no: '', address: '' });
    this.admitOpen.set(true);
  }
  admit(): void {
    if (!this.admitForm().patient_name.trim()) { this.error.set('Patient name is required.'); return; }
    this.saving.set(true);
    this.ipd.admit(this.admitForm()).subscribe({
      next: () => { this.saving.set(false); this.admitOpen.set(false); this.showToast('Patient admitted.'); this.load(); },
      error: () => { this.saving.set(false); this.error.set('Could not admit patient.'); },
    });
  }

  // ---- payment ----
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  setPay(field: string, value: any): void { this.payForm.update((f) => ({ ...f, [field]: value })); }
  openPay(): void { this.payForm.set({ amount: 0, mode: 'cash', note: '' }); this.payOpen.set(true); }
  recordPayment(): void {
    const adm = this.selected();
    if (!adm || this.payForm().amount <= 0) return;
    this.ipd.payment(adm.id, this.payForm()).subscribe(() => {
      this.payOpen.set(false);
      this.showToast('Payment recorded.');
      this.openDetail(adm.id);
      this.load();
    });
  }

  // ---- add bill (go to billing tagged to this admission) ----
  addBill(adm: AdmissionDetail): void {
    this.router.navigate(['/app/sales/new'], { queryParams: { admission: adm.id, patient: adm.patient_name } });
  }

  discharge(adm: AdmissionDetail): void {
    if (!confirm(`Discharge ${adm.patient_name}? Final settlement will be locked.`)) return;
    this.ipd.discharge(adm.id).subscribe((res) => {
      this.showToast(`Discharged. Balance due: ₹${res.data.balance.toFixed(2)}`);
      this.openDetail(adm.id);
      this.load();
    });
  }

  private showToast(msg: string): void { this.toast.set(msg); setTimeout(() => this.toast.set(null), 3000); }
}
