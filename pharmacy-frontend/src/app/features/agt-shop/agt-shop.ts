import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute } from '@angular/router';
import { AgtService } from '../../core/services/agt.service';

interface CartLine {
  p_name: string;
  batch_no: string;
  qty: number;
  unit_mrp: number;
  availableQty: number;
}

@Component({
  selector: 'app-agt-shop',
  imports: [CommonModule, FormsModule],
  templateUrl: './agt-shop.html',
  styleUrl: './agt-shop.scss',
})
export class AgtShopPage implements OnInit {
  private readonly agt = inject(AgtService);
  private readonly route = inject(ActivatedRoute);

  token = signal('');
  loading = signal(true);
  error = signal<string | null>(null);
  pharmacy = signal({ name: '', contact: '', address: '' });
  patient = signal({ name: '', contact: '', address: '' });
  products = signal<Array<Record<string, unknown>>>([]);
  searchQ = signal('');
  cart = signal<CartLine[]>([]);
  patientNote = signal('');
  rxBase64 = signal('');
  placing = signal(false);
  done = signal<{ order_no: string; order_id: number; estimated_total: number } | null>(null);
  allowCustomerCancel = signal(false);
  cancelHint = signal('');
  cancelling = signal(false);
  cancelled = signal(false);

  cartTotal = computed(() =>
    this.cart().reduce((s, l) => s + l.unit_mrp * l.qty, 0),
  );

  ngOnInit(): void {
    const t = this.route.snapshot.queryParamMap.get('token') ?? '';
    this.token.set(t);
    if (!t) {
      this.error.set('Invalid or missing link.');
      this.loading.set(false);
      return;
    }
    this.loadShop();
  }

  loadShop(): void {
    this.loading.set(true);
    this.agt.publicShop(this.token(), this.searchQ()).subscribe({
      next: (res) => {
        const d = res.data;
        if (!d) {
          this.error.set('Could not load shop.');
          this.loading.set(false);
          return;
        }
        this.pharmacy.set(d.pharmacy);
        this.patient.set(d.patient);
        this.products.set(d.products ?? []);
        this.allowCustomerCancel.set(!!d.policy?.allow_customer_cancel);
        this.cancelHint.set(d.policy?.cancel_hint ?? '');
        this.loading.set(false);
      },
      error: (err) => {
        this.error.set(err.error?.message ?? 'Link expired or invalid.');
        this.loading.set(false);
      },
    });
  }

  onSearch(): void {
    this.loadShop();
  }

  qtyInCart(p: Record<string, unknown>): number {
    const name = String(p['p_name'] ?? '');
    const batch = String(p['batch_no'] ?? '');
    return this.cart().find((c) => c.p_name === name && c.batch_no === batch)?.qty ?? 0;
  }

  changeQty(p: Record<string, unknown>, delta: number): void {
    const name = String(p['p_name'] ?? '');
    const batch = String(p['batch_no'] ?? '');
    const max = Math.floor(Number(p['availableQty'] ?? 0));
    const unitMrp = Number(p['unit_mrp'] ?? p['mrp'] ?? 0);
    const cart = [...this.cart()];
    const idx = cart.findIndex((c) => c.p_name === name && c.batch_no === batch);
    let qty = (idx >= 0 ? cart[idx].qty : 0) + delta;
    qty = Math.max(0, Math.min(max, qty));
    if (qty === 0) {
      if (idx >= 0) cart.splice(idx, 1);
    } else if (idx >= 0) {
      cart[idx] = { ...cart[idx], qty };
    } else {
      cart.push({ p_name: name, batch_no: batch, qty, unit_mrp: unitMrp, availableQty: max });
    }
    this.cart.set(cart);
  }

  onRxFile(ev: Event): void {
    const input = ev.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = () => {
      this.rxBase64.set(String(reader.result ?? ''));
    };
    reader.readAsDataURL(file);
  }

  placeOrder(): void {
    if (!this.cart().length) {
      this.error.set('Add at least one product.');
      return;
    }
    this.placing.set(true);
    this.error.set(null);
    this.agt
      .placePublicOrder({
        token: this.token(),
        items: this.cart().map((c) => ({
          p_name: c.p_name,
          batch_no: c.batch_no,
          qty: c.qty,
          unit_mrp: c.unit_mrp,
        })),
        patient_note: this.patientNote(),
        prescription_base64: this.rxBase64() || undefined,
      })
      .subscribe({
        next: (res) => {
          this.placing.set(false);
          if (res.data) {
            this.done.set({
              order_no: res.data.order_no,
              order_id: res.data.order_id,
              estimated_total: res.data.estimated_total,
            });
            this.cart.set([]);
            this.cancelled.set(false);
          }
        },
        error: (err) => {
          this.placing.set(false);
          this.error.set(err.error?.message ?? 'Could not place order.');
        },
      });
  }

  cancelPlacedOrder(): void {
    const d = this.done();
    if (!d?.order_id || !this.allowCustomerCancel()) return;
    if (!confirm(`Cancel order ${d.order_no}?`)) return;
    this.cancelling.set(true);
    this.error.set(null);
    this.agt.publicCancelOrder(this.token(), d.order_id).subscribe({
      next: () => {
        this.cancelling.set(false);
        this.cancelled.set(true);
      },
      error: (err) => {
        this.cancelling.set(false);
        this.error.set(err.error?.message ?? 'Could not cancel order.');
      },
    });
  }
}
