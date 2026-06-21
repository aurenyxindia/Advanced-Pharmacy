import { Component, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterLink } from '@angular/router';
import { SalesService } from '../../core/services/sales.service';

@Component({
  selector: 'app-sales-list',
  imports: [CommonModule, RouterLink],
  template: `
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
      <div>
        <h4 class="fw-bold mb-0">Sales</h4>
        <p class="text-secondary mb-0 small">Billing history</p>
      </div>
      <a routerLink="/app/sales/new" class="btn btn-pharma"><i class="bi bi-plus-lg me-1"></i> New Bill</a>
    </div>

    <div class="card-pharma">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead>
            <tr>
              <th>Invoice</th><th>Date</th><th>Customer</th>
              <th class="text-end">GST</th><th class="text-end">Net</th>
              <th class="text-end">Profit</th><th></th><th></th>
            </tr>
          </thead>
          <tbody>
            @if (loading()) {
              <tr><td colspan="8" class="text-center py-5"><span class="spinner-border text-pharma"></span></td></tr>
            } @else if (rows().length === 0) {
              <tr><td colspan="8" class="text-center py-5 text-secondary">
                <i class="bi bi-inbox fs-3 d-block mb-2"></i> No sales yet.
              </td></tr>
            } @else {
              @for (r of rows(); track r['id']) {
                <tr>
                  <td class="fw-semibold">{{ r['invoice_no'] }}
                    @if (r['is_locked'] == 1) { <i class="bi bi-lock-fill text-warning ms-1" title="Locked"></i> }
                  </td>
                  <td>{{ r['invoice_date'] | date: 'dd MMM, HH:mm' }}</td>
                  <td>{{ r['customer_name'] || 'Walk-in' }}</td>
                  <td class="text-end">{{ +(r['gst_amount'] || 0) | currency: 'INR' : 'symbol' : '1.2-2' }}</td>
                  <td class="text-end fw-semibold">{{ +(r['net_amount'] || 0) | currency: 'INR' : 'symbol' : '1.2-2' }}</td>
                  <td class="text-end text-success">{{ +(r['profit_amount'] || 0) | currency: 'INR' : 'symbol' : '1.2-2' }}</td>
                  <td><span class="badge text-bg-light">{{ r['payment_mode'] }}</span></td>
                  <td class="text-end">
                    <button class="btn btn-sm btn-outline-secondary" (click)="invoice(+r['id'])"><i class="bi bi-printer"></i></button>
                  </td>
                </tr>
              }
            }
          </tbody>
        </table>
      </div>
    </div>
  `,
})
export class SalesList {
  private readonly salesSvc = inject(SalesService);
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  readonly rows = signal<Record<string, any>[]>([]);
  readonly loading = signal(true);

  constructor() {
    this.salesSvc.list().subscribe({
      next: (res) => { this.rows.set(res.data); this.loading.set(false); },
      error: () => this.loading.set(false),
    });
  }

  invoice(id: number): void {
    this.salesSvc.invoiceBlob(id).subscribe((blob) => window.open(URL.createObjectURL(blob), '_blank'));
  }
}
