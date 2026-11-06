import { Component, inject, OnInit, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import {
  AbstractControl,
  FormBuilder,
  ReactiveFormsModule,
  ValidationErrors,
  Validators,
} from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { AuthService } from '../../../core/services/auth.service';
import { BusinessType } from '../../../core/models/auth.models';

function passwordsMatch(group: AbstractControl): ValidationErrors | null {
  const pwd = group.get('password')?.value;
  const confirm = group.get('password_confirmation')?.value;
  return pwd && confirm && pwd !== confirm ? { mismatch: true } : null;
}

const GSTIN_FORMAT = /^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/;

function gstinOptional(control: AbstractControl): ValidationErrors | null {
  const v = (control.value ?? '').toString().trim().toUpperCase();
  if (v === '') return null;
  if (v.length !== 15) return { gstinLength: { actual: v.length } };
  if (!GSTIN_FORMAT.test(v)) return { gstinFormat: true };
  return null;
}

function panOptional(control: AbstractControl): ValidationErrors | null {
  const v = (control.value ?? '').toString().trim().toUpperCase();
  if (v === '') return null;
  if (!/^[A-Z]{5}[0-9]{4}[A-Z]$/.test(v)) return { panFormat: true };
  return null;
}

function fssaiOptional(control: AbstractControl): ValidationErrors | null {
  const v = (control.value ?? '').toString().trim();
  if (v === '') return null;
  if (!/^\d{14}$/.test(v)) return { fssaiFormat: true };
  return null;
}

@Component({
  selector: 'app-register',
  imports: [CommonModule, ReactiveFormsModule, RouterLink],
  templateUrl: './register.html',
  styleUrl: './register.scss',
})
export class Register implements OnInit {
  private readonly fb = inject(FormBuilder);
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  readonly loading = signal(false);
  readonly errorMessage = signal<string | null>(null);
  readonly serverErrors = signal<Record<string, string[]>>({});
  readonly showPassword = signal(false);
  readonly showConfirmPassword = signal(false);

  readonly businessTypeOptions: { value: BusinessType; label: string }[] = [
    { value: 'retail', label: 'Retail pharmacy' },
    { value: 'wholesale', label: 'Wholesale / PCD' },
    { value: 'both', label: 'Retail + Wholesale (Both)' },
  ];

  readonly form = this.fb.nonNullable.group(
    {
      business_type: ['retail' as BusinessType, [Validators.required]],
      business_name: ['', [Validators.required, Validators.minLength(3)]],
      owner_name: ['', [Validators.required, Validators.minLength(3)]],
      email: ['', [Validators.required, Validators.email, Validators.maxLength(150)]],
      phone: ['', [Validators.required, Validators.pattern(/^[6-9]\d{9}$/)]],
      drug_license_no: ['', [Validators.required, Validators.minLength(3), Validators.maxLength(60)]],
      gstin: ['', [gstinOptional, Validators.maxLength(15)]],
      pan: ['', [panOptional, Validators.maxLength(10)]],
      fssai_no: ['', [fssaiOptional, Validators.maxLength(14)]],
      address: ['', [Validators.maxLength(255)]],
      city: ['', [Validators.maxLength(80)]],
      state: ['', [Validators.maxLength(80)]],
      pincode: ['', [Validators.pattern(/^$|^[1-9][0-9]{5}$/)]],
      password: ['', [Validators.required, Validators.minLength(8), Validators.maxLength(64)]],
      password_confirmation: ['', [Validators.required]],
    },
    { validators: passwordsMatch },
  );

  ngOnInit(): void {
    this.applyBusinessTypeRules(this.form.controls.business_type.value);
    this.form.controls.business_type.valueChanges.subscribe((type) => {
      this.applyBusinessTypeRules(type as BusinessType);
    });
  }

  businessType(): BusinessType {
    return this.form.controls.business_type.value;
  }

  showsRetail(): boolean {
    const t = this.businessType();
    return t === 'retail' || t === 'both';
  }

  showsWholesale(): boolean {
    const t = this.businessType();
    return t === 'wholesale' || t === 'both';
  }

  drugLicenseLabel(): string {
    switch (this.businessType()) {
      case 'retail':
        return 'Drug License (Form 20 & 21)';
      case 'wholesale':
        return 'Drug License (Form 20B & 21B)';
      default:
        return 'Drug License No.';
    }
  }

  drugLicensePlaceholder(): string {
    switch (this.businessType()) {
      case 'wholesale':
        return 'e.g. MH-DL-20B-12345 / 21B-12345';
      default:
        return 'e.g. MH-DL-20-12345 / 21-12345';
    }
  }

  businessTypeHint(): string {
    switch (this.businessType()) {
      case 'retail':
        return 'Retail billing, POS counters, patient sales and schedule registers.';
      case 'wholesale':
        return 'Wholesale / PCD billing, distributor purchases, CD discount and B2B customers.';
      default:
        return 'Full suite — retail counter billing plus wholesale / PCD on the same account.';
    }
  }

  gstinRequired(): boolean {
    return this.showsWholesale();
  }

  fieldError(field: string): string | null {
    return this.serverErrors()[field]?.[0] ?? null;
  }

  controlHint(field: string): string | null {
    if (this.fieldError(field)) return null;
    const ctrl = this.form.get(field);
    if (!ctrl || (!ctrl.touched && !ctrl.dirty)) return null;
    if (!ctrl.invalid) return null;

    const errors = ctrl.errors ?? {};
    switch (field) {
      case 'business_type':
        if (errors['required']) return 'Please select retail, wholesale, or both.';
        break;
      case 'business_name':
        if (errors['required']) return 'Pharmacy name is required.';
        if (errors['minlength']) return 'Pharmacy name must be at least 3 characters.';
        break;
      case 'owner_name':
        if (errors['required']) return 'Owner name is required.';
        if (errors['minlength']) return 'Owner name must be at least 3 characters.';
        break;
      case 'drug_license_no':
        if (errors['required']) return 'Drug license number is required.';
        if (errors['minlength']) return 'Enter a valid drug license number.';
        break;
      case 'email':
        if (errors['required']) return 'Email address is required.';
        if (errors['email']) return 'Enter a valid email address (e.g. owner@pharmacy.com).';
        break;
      case 'phone':
        if (errors['required']) return 'Mobile number is required.';
        if (errors['pattern']) return 'Enter a valid 10-digit Indian mobile number starting with 6–9.';
        break;
      case 'pincode':
        if (errors['pattern']) return 'Pincode must be 6 digits and cannot start with 0.';
        break;
      case 'fssai_no':
        if (errors['fssaiFormat']) return 'FSSAI number must be exactly 14 digits.';
        break;
      case 'password':
        if (errors['required']) return 'Password is required.';
        if (errors['minlength']) return 'Password must be at least 8 characters.';
        break;
      case 'password_confirmation':
        if (errors['required']) return 'Please confirm your password.';
        break;
    }
    return null;
  }

  formHint(): string | null {
    if (!this.form.errors?.['mismatch']) return null;
    if (!this.form.controls.password_confirmation.touched) return null;
    return 'Passwords do not match.';
  }

  gstinHint(): string | null {
    const ctrl = this.form.controls.gstin;
    if (!ctrl.touched && !ctrl.dirty) return null;
    if (this.gstinRequired() && !(ctrl.value ?? '').toString().trim()) {
      return 'GSTIN is required for wholesale / PCD pharmacy.';
    }
    if (!ctrl.invalid) return null;
    if (ctrl.errors?.['gstinLength']) {
      return `GSTIN must be exactly 15 characters (currently ${ctrl.errors['gstinLength'].actual}).`;
    }
    if (ctrl.errors?.['gstinFormat']) {
      return 'Invalid GSTIN format. Example: 22AAAAA0000A1Z5';
    }
    return null;
  }

  panHint(): string | null {
    const ctrl = this.form.controls.pan;
    if (!ctrl.touched || !ctrl.invalid) return null;
    if (ctrl.errors?.['panFormat']) return 'Invalid PAN format. Example: ABCDE1234F';
    return null;
  }

  onGstinInput(event: Event): void {
    const el = event.target as HTMLInputElement;
    const value = (el.value ?? '').replace(/[^a-zA-Z0-9]/g, '').toUpperCase().slice(0, 15);
    el.value = value;
    this.form.controls.gstin.setValue(value, { emitEvent: false });
    this.form.controls.gstin.updateValueAndValidity({ emitEvent: false });
  }

  onPanInput(event: Event): void {
    const el = event.target as HTMLInputElement;
    const value = (el.value ?? '').replace(/[^a-zA-Z0-9]/g, '').toUpperCase().slice(0, 10);
    el.value = value;
    this.form.controls.pan.setValue(value, { emitEvent: false });
    this.form.controls.pan.updateValueAndValidity({ emitEvent: false });
  }

  onPhoneInput(event: Event): void {
    const el = event.target as HTMLInputElement;
    const value = (el.value ?? '').replace(/\D/g, '').slice(0, 10);
    el.value = value;
    this.form.controls.phone.setValue(value, { emitEvent: false });
    this.form.controls.phone.updateValueAndValidity({ emitEvent: false });
  }

  onPincodeInput(event: Event): void {
    const el = event.target as HTMLInputElement;
    const value = (el.value ?? '').replace(/\D/g, '').slice(0, 6);
    el.value = value;
    this.form.controls.pincode.setValue(value, { emitEvent: false });
    this.form.controls.pincode.updateValueAndValidity({ emitEvent: false });
  }

  togglePassword(): void {
    this.showPassword.update((v) => !v);
  }

  toggleConfirmPassword(): void {
    this.showConfirmPassword.update((v) => !v);
  }

  gstinLooksValid(): boolean {
    const ctrl = this.form.controls.gstin;
    return !!(ctrl.value && ctrl.touched && ctrl.valid);
  }

  onFssaiInput(event: Event): void {
    const el = event.target as HTMLInputElement;
    const value = (el.value ?? '').replace(/\D/g, '').slice(0, 14);
    el.value = value;
    this.form.controls.fssai_no.setValue(value, { emitEvent: false });
    this.form.controls.fssai_no.updateValueAndValidity({ emitEvent: false });
  }

  submit(): void {
    const gst = (this.form.controls.gstin.value ?? '').toString().trim().toUpperCase();
    this.form.controls.gstin.setValue(gst);
    const pan = (this.form.controls.pan.value ?? '').toString().trim().toUpperCase();
    this.form.controls.pan.setValue(pan);

    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }
    this.loading.set(true);
    this.errorMessage.set(null);
    this.serverErrors.set({});

    const raw = this.form.getRawValue();
    this.auth.register({
      business_type: raw.business_type,
      business_name: raw.business_name,
      owner_name: raw.owner_name,
      email: raw.email.trim(),
      phone: raw.phone,
      drug_license_no: raw.drug_license_no,
      gstin: raw.gstin || undefined,
      pan: raw.pan || undefined,
      fssai_no: raw.fssai_no || undefined,
      address: raw.address || undefined,
      city: raw.city || undefined,
      state: raw.state || undefined,
      pincode: raw.pincode || undefined,
      password: raw.password,
      password_confirmation: raw.password_confirmation,
      access_channel: 'web',
    }).subscribe({
      next: (res) => {
        this.loading.set(false);
        const msg =
          res.message ||
          'Registration received. Your login will be activated after admin approval.';
        void this.router.navigate(['/login'], { queryParams: { registered: '1', msg } });
      },
      error: (err: HttpErrorResponse) => {
        this.loading.set(false);
        this.errorMessage.set(err.error?.message ?? 'Registration failed. Please try again.');
        if (err.error?.errors) {
          this.serverErrors.set(err.error.errors);
        }
      },
    });
  }

  private applyBusinessTypeRules(type: BusinessType): void {
    const gst = this.form.controls.gstin;
    if (type === 'wholesale' || type === 'both') {
      gst.setValidators([Validators.required, gstinOptional, Validators.maxLength(15)]);
    } else {
      gst.setValidators([gstinOptional, Validators.maxLength(15)]);
      if (!gst.value) gst.setErrors(null);
    }
    gst.updateValueAndValidity({ emitEvent: false });
  }
}
