import { Component, Input } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterLink, RouterLinkActive } from '@angular/router';

@Component({
  selector: 'app-return-tabs',
  standalone: true,
  imports: [CommonModule, RouterLink, RouterLinkActive],
  template: `
    <div class="return-tabs card-pharma p-2 mb-3">
      <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-sm return-tab" routerLink="/app/return-note" routerLinkActive="active">Debit Note</a>
        <a class="btn btn-sm return-tab" routerLink="/app/sales-return" routerLinkActive="active">Retail Sales Return</a>
        <a class="btn btn-sm return-tab" routerLink="/app/wholesale-return" routerLinkActive="active">Wholesale Sales Return (Credit Note)</a>
        <a class="btn btn-sm return-tab" routerLink="/app/return-deduction" routerLinkActive="active">Return Deduction %</a>
      </div>
    </div>
  `,
  styles: [
    `
      .return-tab {
        border: 1px solid #cbd5e1;
        background: #fff;
        color: #334155;
        text-transform: uppercase;
        font-size: 0.72rem;
        letter-spacing: 0.02em;
        font-weight: 600;
      }
      .return-tab.active {
        background: #1d4ed8;
        border-color: #1d4ed8;
        color: #fff;
      }
    `,
  ],
})
export class ReturnTabsComponent {
  @Input() active: string = '';
}
