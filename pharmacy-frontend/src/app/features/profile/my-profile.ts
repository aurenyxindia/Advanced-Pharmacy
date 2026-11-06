import { Component, inject, OnDestroy, OnInit, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { AuthService } from '../../core/services/auth.service';
import { ClientProfile, ProfileService } from '../../core/services/profile.service';
import { UserRightsService } from '../../core/services/user-rights.service';
import { UserRightsPanel } from './user-rights-panel';

type UploadKey = 'stamp' | 'logo' | 'scanner' | 'photo' | 'dl';
type ProfileTab = 'profile' | 'userRights';

@Component({
  selector: 'app-my-profile',
  imports: [CommonModule, FormsModule, UserRightsPanel],
  templateUrl: './my-profile.html',
  styleUrl: './my-profile.scss',
})
export class MyProfile implements OnInit, OnDestroy {
  private readonly profileSvc = inject(ProfileService);
  private readonly auth = inject(AuthService);
  private readonly rightsSvc = inject(UserRightsService);

  readonly loading = signal(true);
  readonly saving = signal(false);
  readonly error = signal('');
  readonly success = signal('');
  readonly activeTab = signal<ProfileTab>('profile');
  readonly profile = signal<ClientProfile | null>(null);
  readonly previews = signal<Record<UploadKey, string>>({
    stamp: '',
    logo: '',
    scanner: '',
    photo: '',
    dl: '',
  });
  readonly uploadBusy = signal<UploadKey | ''>('');

  form = {
    email: '',
    alternate_phone: '',
    bank_name: '',
    account_no: '',
    ifsc_code: '',
    dob: '',
    hide_master_products: false,
    hide_master_distributors: false,
  };

  validation: Record<string, string> = {};
  private blobUrls: string[] = [];
  private pendingFiles: Partial<Record<UploadKey, File>> = {};

  readonly uploadKeys: UploadKey[] = ['stamp', 'logo', 'scanner', 'photo', 'dl'];

  readonly maxDob = new Date().toISOString().slice(0, 10);

  get isAdmin(): boolean {
    return this.rightsSvc.isAdminUser(this.auth.user()?.role);
  }

  ngOnInit(): void {
    this.loadProfile();
  }

  ngOnDestroy(): void {
    this.revokeBlobs();
  }

  setTab(tab: ProfileTab): void {
    if (tab === 'userRights' && !this.isAdmin) return;
    this.activeTab.set(tab);
  }

  loadProfile(): void {
    this.loading.set(true);
    this.error.set('');
    this.profileSvc.load().subscribe({
      next: (p) => {
        this.profile.set(p);
        this.form = {
          email: p.email ?? '',
          alternate_phone: (p.alternate_phone ?? '').replace(/\D+/g, '').slice(0, 10),
          bank_name: p.bank_name ?? '',
          account_no: p.account_no ?? '',
          ifsc_code: p.ifsc_code ?? '',
          dob: p.dob ? String(p.dob).slice(0, 10) : '',
          hide_master_products: !!p.hide_master_products,
          hide_master_distributors: !!p.hide_master_distributors,
        };
        this.loadAssets(p);
        this.loading.set(false);
      },
      error: (e) => {
        this.error.set(e?.error?.message ?? 'Could not load profile.');
        this.loading.set(false);
      },
    });
  }

  saveProfile(): void {
    this.validation = {};
    if (!this.validate()) return;

    this.saving.set(true);
    this.success.set('');
    this.error.set('');
    const payload: Parameters<ProfileService['save']>[0] = {
      email: this.form.email.trim(),
      alternate_phone: this.form.alternate_phone.replace(/\D+/g, '').slice(0, 10),
      bank_name: this.form.bank_name.trim(),
      account_no: this.form.account_no.trim(),
      ifsc_code: this.form.ifsc_code.trim().toUpperCase(),
      dob: this.form.dob || null,
    };
    if (this.isAdmin) {
      payload.hide_master_products = !!this.form.hide_master_products;
      payload.hide_master_distributors = !!this.form.hide_master_distributors;
    }
    this.profileSvc
      .save(payload)
      .subscribe({
        next: () => {
          this.success.set('Profile saved successfully.');
          this.saving.set(false);
          this.loadProfile();
        },
        error: (e) => {
          const errs = e?.error?.errors;
          if (errs && typeof errs === 'object') {
            for (const [k, v] of Object.entries(errs)) {
              this.validation[k] = Array.isArray(v) ? String(v[0]) : String(v);
            }
          } else {
            this.error.set(e?.error?.message ?? 'Save failed.');
          }
          this.saving.set(false);
        },
      });
  }

  onFilePick(type: UploadKey, event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;

    const maxMb = 50;
    if (file.size > maxMb * 1024 * 1024) {
      this.error.set(`File must be under ${maxMb} MB.`);
      input.value = '';
      return;
    }

    this.pendingFiles[type] = file;
    const url = URL.createObjectURL(file);
    this.trackBlob(url);
    this.previews.update((p) => ({ ...p, [type]: url }));
    this.upload(type);
  }

  upload(type: UploadKey): void {
    const file = this.pendingFiles[type];
    if (!file) {
      this.error.set('Choose a file first.');
      return;
    }
    this.uploadBusy.set(type);
    this.error.set('');
    this.success.set('');
    this.profileSvc.upload(type, file).subscribe({
      next: () => {
        delete this.pendingFiles[type];
        const p = this.profile();
        if (p) this.loadAsset(type, p.client_id);
        this.success.set(`${this.uploadLabel(type)} uploaded.`);
        this.uploadBusy.set('');
      },
      error: (e) => {
        this.error.set(this.uploadErrorMessage(e));
        this.uploadBusy.set('');
      },
    });
  }

  private uploadErrorMessage(e: { error?: { message?: string; errors?: Record<string, string[] | string> } }): string {
    const fileErr = e?.error?.errors?.['file'];
    if (Array.isArray(fileErr) && fileErr[0]) {
      return String(fileErr[0]);
    }
    if (typeof fileErr === 'string' && fileErr) {
      return fileErr;
    }
    return e?.error?.message ?? 'Upload failed.';
  }

  uploadLabel(type: UploadKey): string {
    const map: Record<UploadKey, string> = {
      stamp: 'Stamp & Sign',
      logo: 'Logo',
      scanner: 'Scanner',
      photo: 'Photo',
      dl: 'Drug License',
    };
    return map[type];
  }

  private loadAssets(p: ClientProfile): void {
    this.revokeBlobs();
    this.previews.set({ stamp: '', logo: '', scanner: '', photo: '', dl: '' });
    (['stamp', 'logo', 'scanner', 'photo', 'dl'] as UploadKey[]).forEach((t) => {
      if (p.assets?.[t]) this.loadAsset(t, p.client_id);
    });
  }

  private loadAsset(type: UploadKey, clientId: number): void {
    this.profileSvc.assetBlob(clientId, type).subscribe({
      next: (blob) => {
        const url = URL.createObjectURL(blob);
        this.trackBlob(url);
        this.previews.update((p) => ({ ...p, [type]: url }));
      },
      error: () => undefined,
    });
  }

  private trackBlob(url: string): void {
    this.blobUrls.push(url);
  }

  private revokeBlobs(): void {
    this.blobUrls.forEach((u) => URL.revokeObjectURL(u));
    this.blobUrls = [];
  }

  private validate(): boolean {
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(this.form.email)) {
      this.validation['email'] = 'Enter a valid email address.';
    }
    const alt = this.form.alternate_phone.replace(/\D+/g, '');
    if (alt && alt.length !== 10) {
      this.validation['alternate_phone'] = 'Enter a valid 10-digit mobile number.';
    }
    if (this.form.ifsc_code && !/^[A-Z]{4}0[A-Z0-9]{6}$/.test(this.form.ifsc_code.toUpperCase())) {
      this.validation['ifsc_code'] = 'Invalid IFSC code.';
    }
    return Object.keys(this.validation).length === 0;
  }

  /** Read-only line from registration (clients record). */
  formatAddress(p: ClientProfile): string {
    const parts = [p.address, p.city, p.state, p.pincode].filter((x) => (x ?? '').toString().trim() !== '');
    return parts.join(', ') || '—';
  }
}
