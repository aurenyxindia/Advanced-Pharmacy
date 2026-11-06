import { Component, OnInit, inject, OnDestroy, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import {
  AbstractControl,
  FormBuilder,
  ReactiveFormsModule,
  ValidationErrors,
  Validators,
} from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { AuthService } from '../../../core/services/auth.service';
import { PosModalService } from '../../../core/services/pos-modal.service';
import { UserRightsService } from '../../../core/services/user-rights.service';

function emailOrPhone(control: AbstractControl): ValidationErrors | null {
  const raw = (control.value ?? '').toString().trim();
  if (raw === '') return null;
  if (raw.includes('@')) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(raw) ? null : { emailFormat: true };
  }
  const digits = raw.replace(/\D/g, '');
  return /^[6-9]\d{9}$/.test(digits) ? null : { phoneFormat: true };
}

@Component({
  selector: 'app-login',
  imports: [CommonModule, ReactiveFormsModule, RouterLink],
  templateUrl: './login.html',
  styleUrl: './login.scss',
})
export class Login implements OnInit, OnDestroy {
  private readonly fb = inject(FormBuilder);
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly pos = inject(PosModalService);
  private readonly rights = inject(UserRightsService);

  readonly loading = signal(false);
  readonly errorMessage = signal<string | null>(null);
  readonly infoMessage = signal<string | null>(null);
  readonly showPassword = signal(false);
  readonly showNewPassword = signal(false);
  readonly mode = signal<'login' | 'forgot'>('login');
  readonly otpSent = signal(false);
  /** Live countdown while login is locked after failed attempts. */
  readonly lockSecondsLeft = signal(0);
  readonly attemptsLeft = signal<number | null>(null);

  private lockTimer: ReturnType<typeof setInterval> | null = null;

  readonly form = this.fb.nonNullable.group({
    email: ['', [Validators.required, emailOrPhone]],
    password: ['', [Validators.required, Validators.minLength(6)]],
  });

  readonly forgotForm = this.fb.nonNullable.group({
    username: ['', [Validators.required, Validators.maxLength(150), emailOrPhone]],
    password: ['', [Validators.required, Validators.minLength(6)]],
    security_code: ['', [Validators.required, Validators.maxLength(16)]],
  });

  ngOnInit(): void {
    const q = this.route.snapshot.queryParamMap;
    if (q.get('registered') === '1') {
      this.infoMessage.set(
        q.get('msg') ||
          'Registration received. Your login will be activated after admin approval.',
      );
    }
  }

  ngOnDestroy(): void {
    this.clearLockTimer();
  }

  loginHint(): string | null {
    return this.loginFieldHint(this.form.controls.email);
  }

  forgotUsernameHint(): string | null {
    return this.loginFieldHint(this.forgotForm.controls.username);
  }

  private loginFieldHint(ctrl: AbstractControl): string | null {
    if (!ctrl.touched || !ctrl.invalid) return null;
    if (ctrl.errors?.['required']) return 'Email or mobile number is required.';
    if (ctrl.errors?.['emailFormat']) return 'Enter a valid email address.';
    if (ctrl.errors?.['phoneFormat']) return 'Enter a valid 10-digit mobile number starting with 6–9.';
    return null;
  }

  onLoginInput(event: Event): void {
    const el = event.target as HTMLInputElement;
    const raw = (el.value ?? '').trim();
    // Allow typing email local-part (letters) — only normalize pure mobile input.
    if (raw.includes('@') || raw === '' || /[a-zA-Z]/.test(raw)) return;
    const digits = raw.replace(/\D/g, '').slice(0, 10);
    if (digits !== raw.replace(/\s/g, '')) {
      el.value = digits;
      this.form.controls.email.setValue(digits, { emitEvent: false });
    }
  }

  onForgotUsernameInput(event: Event): void {
    const el = event.target as HTMLInputElement;
    const raw = (el.value ?? '').trim();
    if (raw.includes('@') || raw === '' || /[a-zA-Z]/.test(raw)) return;
    const digits = raw.replace(/\D/g, '').slice(0, 10);
    if (digits !== raw.replace(/\s/g, '')) {
      el.value = digits;
      this.forgotForm.controls.username.setValue(digits, { emitEvent: false });
    }
  }

  togglePassword(): void {
    this.showPassword.update((v) => !v);
  }

  toggleNewPassword(): void {
    this.showNewPassword.update((v) => !v);
  }

  openForgot(): void {
    this.mode.set('forgot');
    this.otpSent.set(false);
    this.errorMessage.set(null);
    this.infoMessage.set(null);
  }

  backToLogin(): void {
    this.mode.set('login');
    this.otpSent.set(false);
    this.errorMessage.set(null);
    this.infoMessage.set(null);
  }

  private normalizeLoginId(value: string): string {
    const raw = value.trim();
    return raw.includes('@') ? raw.toLowerCase() : raw.replace(/\D/g, '');
  }

  sendOtp(): void {
    if (this.forgotForm.controls.username.invalid) {
      this.forgotForm.controls.username.markAsTouched();
      return;
    }
    const username = this.normalizeLoginId(this.forgotForm.controls.username.value);
    if (!username) {
      this.forgotForm.controls.username.markAsTouched();
      return;
    }
    this.loading.set(true);
    this.errorMessage.set(null);
    this.infoMessage.set(null);

    this.auth.sendForgotPasswordOtp(username).subscribe({
      next: (res) => {
        this.loading.set(false);
        this.otpSent.set(true);
        const notice = res.data?.notice ? ` ${res.data.notice}` : '';
        this.infoMessage.set((res.message ?? 'OTP sent.') + notice);
      },
      error: (err: HttpErrorResponse) => {
        this.loading.set(false);
        this.errorMessage.set(err.error?.message ?? 'Could not send OTP. Please try again.');
      },
    });
  }

  resetPassword(): void {
    if (this.forgotForm.invalid) {
      this.forgotForm.markAllAsTouched();
      return;
    }
    const v = this.forgotForm.getRawValue();
    this.loading.set(true);
    this.errorMessage.set(null);
    this.infoMessage.set(null);

    this.auth.resetForgotPassword({
      username: this.normalizeLoginId(v.username),
      security_code: v.security_code.trim(),
      password: v.password,
    }).subscribe({
      next: (res) => {
        this.loading.set(false);
        this.infoMessage.set(res.message ?? 'Password changed successfully.');
        this.mode.set('login');
        this.otpSent.set(false);
        this.forgotForm.reset();
        this.form.patchValue({ email: v.username.includes('@') ? v.username.trim() : this.form.controls.email.value });
      },
      error: (err: HttpErrorResponse) => {
        this.loading.set(false);
        this.errorMessage.set(err.error?.message ?? 'Invalid OTP or could not reset password.');
      },
    });
  }

  submit(): void {
    if (this.lockSecondsLeft() > 0) {
      return;
    }
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }
    this.loading.set(true);
    this.errorMessage.set(null);
    this.infoMessage.set(null);

    const raw = this.form.getRawValue();
    const loginId = this.normalizeLoginId(raw.email);

    this.auth.login({ email: loginId, password: raw.password }).subscribe({
      next: (res) => {
        this.clearLockTimer();
        this.attemptsLeft.set(null);
        const role = res.data.user.role;
        this.rights.loadMyRights().subscribe({
          next: () => {
            this.loading.set(false);
            if (PosModalService.canSell(role) && this.rights.canView('SALES')) {
              this.pos.show();
            } else {
              this.pos.hide();
            }
            void this.router.navigateByUrl(this.rights.firstAllowedRoute());
          },
          error: () => {
            this.loading.set(false);
            this.pos.hide();
            void this.router.navigateByUrl(this.rights.firstAllowedRoute());
          },
        });
      },
      error: (err: HttpErrorResponse) => {
        this.loading.set(false);
        this.handleLoginError(err);
      },
    });
  }

  private handleLoginError(err: HttpErrorResponse): void {
    const body = err.error ?? {};
    const errors = body.errors ?? {};
    const retryAfter = Number(errors.retry_after ?? body.retry_after ?? 0);

    if (err.status === 429 || retryAfter > 0) {
      const secs = retryAfter > 0 ? Math.ceil(retryAfter) : 30;
      this.startLockCountdown(secs);
      return;
    }

    if (typeof errors.attempts_left === 'number') {
      this.attemptsLeft.set(errors.attempts_left);
      const left = errors.attempts_left;
      this.errorMessage.set(
        left > 0
          ? `Invalid credentials. ${left} attempt${left === 1 ? '' : 's'} left.`
          : (body.message ?? 'Invalid credentials.')
      );
      return;
    }

    this.attemptsLeft.set(null);
    this.errorMessage.set(body.message ?? 'Unable to sign in. Please try again.');
  }

  private startLockCountdown(seconds: number): void {
    this.clearLockTimer();
    const secs = Math.max(1, Math.ceil(seconds));
    this.lockSecondsLeft.set(secs);
    this.attemptsLeft.set(null);
    this.errorMessage.set(`Too many failed attempts. Try again in ${secs}s.`);

    this.lockTimer = setInterval(() => {
      const next = this.lockSecondsLeft() - 1;
      if (next <= 0) {
        this.clearLockTimer();
        this.lockSecondsLeft.set(0);
        this.errorMessage.set(null);
        this.infoMessage.set('You can sign in again now.');
        return;
      }
      this.lockSecondsLeft.set(next);
      this.errorMessage.set(`Too many failed attempts. Try again in ${next}s.`);
    }, 1000);
  }

  private clearLockTimer(): void {
    if (this.lockTimer !== null) {
      clearInterval(this.lockTimer);
      this.lockTimer = null;
    }
    this.lockSecondsLeft.set(0);
  }
}
