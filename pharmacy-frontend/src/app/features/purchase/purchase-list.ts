import { Component, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterLink } from '@angular/router';
import { PurchaseService } from '../../core/services/purchase.service';

@Component({
  selector: 'app-purchase-list',
  imports: [CommonModule, RouterLink],
  template: `
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
      <div>
        <h4 class="fw-bold mb-0">Purchases</h4>
        <p class="text-secondary mb-0 small">Supplier invoices and goods received</p>
      </div>
      <a routerLink="/app/purchase/new" class="btn btn-pharma"><i class="bi bi-plus-lg me-1"></i> New Purchase</a>
    </div>

    <div class="card-pharma">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead>
            <tr>
              <th>Invoice</th><th>Date</th><th>Supplier</th>
              <th class="text-end">GST</th><th class="text-end">Net</th>
            </tr>
          </thead>
          <tbody>
            @if (loading()) {
              <tr><td colspan="5" class="text-center py-5"><span class="spinner-border text-pharma"></span></td></tr>
            } @else if (rows().length === 0) {
              <tr><td colspan="5" class="text-center py-5 text-secondary">
                <i class="bi bi-inbox fs-3 d-block mb-2"></i> No purchases yet.
              </td></tr>
            } @else {
              @for (r of rows(); track r['id']) {
                <tr>
                  <td class="fw-semibold">{{ r['invoice_no'] }}</td>
                  <td>{{ r['invoice_date'] | date: 'dd MMM yyyy' }}</td>
                  <td>{{ r['supplier_name'] || '-' }}</td>
                  <td class="text-end">{{ +(r['gst_amount'] || 0) | currency: 'INR' : 'symbol' : '1.2-2' }}</td>
                  <td class="text-end fw-semibold">{{ +(r['net_amount'] || 0) | currency: 'INR' : 'symbol' : '1.2-2' }}</td>
                </tr>
              }
            }
          </tbody>
        </table>
      </div>
    </div>
  `,
})
export class PurchaseList {
  private readonly purchaseSvc = inject(PurchaseService);
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  readonly rows = signal<Record<string, any>[]>([]);
  readonly loading = signal(true);

  constructor() {
    this.purchaseSvc.list().subscribe({
      next: (res) => { this.rows.set(res.data); this.loading.set(false); },
      error: () => this.loading.set(false),
    });
  }
}
