import { Component, inject, signal } from '@angular/core';
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

function passwordsMatch(group: AbstractControl): ValidationErrors | null {
  const pwd = group.get('password')?.value;
  const confirm = group.get('password_confirmation')?.value;
  return pwd && confirm && pwd !== confirm ? { mismatch: true } : null;
}

@Component({
  selector: 'app-register',
  imports: [CommonModule, ReactiveFormsModule, RouterLink],
  templateUrl: './register.html',
  styleUrl: './register.scss',
})
export class Register {
  private readonly fb = inject(FormBuilder);
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  readonly loading = signal(false);
  readonly errorMessage = signal<string | null>(null);
  readonly serverErrors = signal<Record<string, string[]>>({});

  readonly form = this.fb.nonNullable.group(
    {
      business_name: ['', [Validators.required, Validators.minLength(3)]],
      owner_name: ['', [Validators.required, Validators.minLength(3)]],
      email: ['', [Validators.required, Validators.email]],
      phone: ['', [Validators.required, Validators.minLength(7)]],
      drug_license_no: ['', [Validators.required, Validators.minLength(3)]],
      gstin: [''],
      address: [''],
      city: [''],
      state: [''],
      pincode: [''],
      password: ['', [Validators.required, Validators.minLength(8)]],
      password_confirmation: ['', [Validators.required]],
    },
    { validators: passwordsMatch },
  );

  fieldError(field: string): string | null {
    return this.serverErrors()[field]?.[0] ?? null;
  }

  submit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }
    this.loading.set(true);
    this.errorMessage.set(null);
    this.serverErrors.set({});

    this.auth.register(this.form.getRawValue()).subscribe({
      next: () => this.router.navigate(['/app/dashboard']),
      error: (err: HttpErrorResponse) => {
        this.loading.set(false);
        this.errorMessage.set(err.error?.message ?? 'Registration failed. Please try again.');
        if (err.error?.errors) {
          this.serverErrors.set(err.error.errors);
        }
      },
    });
  }
}
