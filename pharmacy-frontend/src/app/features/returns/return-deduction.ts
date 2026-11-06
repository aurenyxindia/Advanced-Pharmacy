import { Component, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ReturnTabsComponent } from './return-tabs';
import { ReturnDeductionApi } from '../../core/services/returns-hub.service';

@Component({
  selector: 'app-return-deduction',
  imports: [CommonModule, FormsModule, ReturnTabsComponent],
  template: `
    <div class="return-deduction-page">
      <app-return-tabs />
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
          <h4 class="fw-bold mb-0">Return Deduction %</h4>
          <p class="text-secondary small mb-0">Months before expiry → auto deduction on wholesale credit notes</p>
        </div>
      </div>
      @if (error()) { <div class="alert alert-danger py-2">{{ error() }}</div> }
      @if (toast()) { <div class="alert alert-success py-2">{{ toast() }}</div> }

      <div class="card-pharma p-3 mb-3">
        <form class="row g-2 align-items-end" (ngSubmit)="save()">
          <div class="col-6 col-md-3">
            <label class="form-label small mb-1">Return Months Before Expiry <span class="text-danger">*</span></label>
            <input type="number" class="form-control form-control-sm" [(ngModel)]="returnMonths" name="months" min="0" required />
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label small mb-1">Deduction % <span class="text-danger">*</span></label>
            <input type="number" class="form-control form-control-sm" [(ngModel)]="deductionPct" name="pct" min="0" max="100" step="0.01" required />
          </div>
          <div class="col-12 col-md-2">
            <button type="submit" class="btn btn-sm btn-pharma w-100" [disabled]="saving()">Submit</button>
          </div>
        </form>
      </div>

      <div class="card-pharma p-3">
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead>
              <tr>
                <th>#</th>
                <th>Return Months</th>
                <th class="text-end">Deduction %</th>
              </tr>
            </thead>
            <tbody>
              @for (r of rows(); track r.id; let i = $index) {
                <tr>
                  <td>{{ i + 1 }}</td>
                  <td>{{ r.return_months }}</td>
                  <td class="text-end">{{ r.deduction_pct | number: '1.2-2' }}</td>
                </tr>
              } @empty {
                <tr><td colspan="3" class="text-center text-secondary py-4">No deduction slots yet.</td></tr>
              }
            </tbody>
          </table>
        </div>
      </div>
    </div>
  `,
})
export class ReturnDeductionPage implements OnInit {
  private readonly api = inject(ReturnDeductionApi);
  readonly rows = signal<{ id: number; return_months: number; deduction_pct: number }[]>([]);
  readonly saving = signal(false);
  readonly error = signal<string | null>(null);
  readonly toast = signal<string | null>(null);
  returnMonths = 0;
  deductionPct = 0;

  ngOnInit(): void {
    this.reload();
  }

  reload(): void {
    this.api.list().subscribe({
      next: (r) => this.rows.set(r),
      error: (e) => this.error.set(e?.error?.message || 'Failed to load.'),
    });
  }

  save(): void {
    this.error.set(null);
    this.saving.set(true);
    this.api.save({ return_months: Number(this.returnMonths), deduction_pct: Number(this.deductionPct) }).subscribe({
      next: () => {
        this.saving.set(false);
        this.toast.set('Added successfully.');
        this.returnMonths = 0;
        this.deductionPct = 0;
        this.reload();
      },
      error: (e) => {
        this.saving.set(false);
        const msg = e?.error?.errors
          ? Object.values(e.error.errors).flat().join(' ')
          : e?.error?.message || 'Save failed.';
        this.error.set(String(msg));
      },
    });
  }
}
