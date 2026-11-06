import { Component, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { PublicBranch, PublicProduct, PublicService } from '../../core/services/public.service';

interface CartItem {
  binding_id: number;
  product_name: string;
  mrp: number;
  quantity: number;
}

@Component({
  selector: 'app-shop',
  imports: [CommonModule, FormsModule, RouterLink],
  templateUrl: './shop.html',
  styleUrl: './shop.scss',
})
export class Shop {
  private readonly publicSvc = inject(PublicService);

  readonly branches = signal<PublicBranch[]>([]);
  readonly branchesLoading = signal(true);
  readonly selectedBranch = signal<number | null>(null);
  readonly products = signal<PublicProduct[]>([]);
  readonly productsLoading = signal(false);
  readonly searchTerm = signal('');
  private searchTimer?: ReturnType<typeof setTimeout>;

  readonly cart = signal<CartItem[]>([]);
  readonly cartOpen = signal(false);
  readonly checkout = signal({ customer_name: '', phone: '', address: '', pincode: '', email: '' });
  readonly placing = signal(false);
  readonly error = signal<string | null>(null);
  readonly confirmation = signal<{ order_no: string; tracking_token: string; total_estimate: number } | null>(null);

  readonly cartTotal = computed(() => this.cart().reduce((s, i) => s + i.mrp * i.quantity, 0));
  readonly cartCount = computed(() => this.cart().reduce((s, i) => s + i.quantity, 0));

  readonly selectedBranchInfo = computed(() =>
    this.branches().find((b) => b.id === this.selectedBranch()) ?? null
  );

  constructor() {
    this.publicSvc.branches().subscribe({
      next: (b) => { this.branches.set(b); this.branchesLoading.set(false); },
      error: () => this.branchesLoading.set(false),
    });
  }

  selectBranch(id: number): void {
    this.selectedBranch.set(id);
    this.products.set([]);
    this.searchTerm.set('');
    this.cartOpen.set(false);
    this.loadCatalog('');
  }

  clearBranch(): void {
    this.selectedBranch.set(null);
    this.products.set([]);
    this.searchTerm.set('');
    this.cartOpen.set(false);
  }

  onSearch(value: string): void {
    this.searchTerm.set(value);
    clearTimeout(this.searchTimer);
    this.searchTimer = setTimeout(() => this.loadCatalog(value), 250);
  }

  loadCatalog(q: string): void {
    if (!this.selectedBranch()) return;
    this.productsLoading.set(true);
    this.publicSvc.catalog(this.selectedBranch()!, q).subscribe({
      next: (p) => { this.products.set(p); this.productsLoading.set(false); },
      error: () => this.productsLoading.set(false),
    });
  }

  addToCart(p: PublicProduct): void {
    const idx = this.cart().findIndex((c) => c.binding_id === p.binding_id);
    if (idx >= 0) {
      this.updateQty(idx, this.cart()[idx].quantity + 1);
    } else {
      this.cart.update((c) => [...c, { binding_id: p.binding_id, product_name: p.product_name, mrp: Number(p.mrp) || 0, quantity: 1 }]);
    }
    this.cartOpen.set(true);
  }

  updateQty(i: number, qty: number): void {
    if (qty <= 0) { this.removeItem(i); return; }
    this.cart.update((c) => c.map((item, idx) => (idx === i ? { ...item, quantity: qty } : item)));
  }

  removeItem(i: number): void {
    this.cart.update((c) => c.filter((_, idx) => idx !== i));
    if (this.cart().length === 0) this.cartOpen.set(false);
  }

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  updateCheckout(field: string, value: any): void {
    this.checkout.update((c) => ({ ...c, [field]: value }));
  }

  placeOrder(): void {
    this.error.set(null);
    const c = this.checkout();
    if (!c.customer_name.trim() || !c.phone.trim() || !c.address.trim()) {
      this.error.set('Please fill in name, phone and delivery address.');
      return;
    }
    if (this.cart().length === 0) {
      this.error.set('Your cart is empty.');
      return;
    }
    this.placing.set(true);
    this.publicSvc
      .placeOrder({
        branch_id: this.selectedBranch(),
        ...c,
        items: this.cart().map((i) => ({ product_binding_id: i.binding_id, product_name: i.product_name, quantity: i.quantity })),
      })
      .subscribe({
        next: (res) => {
          this.placing.set(false);
          this.confirmation.set(res.data);
          this.cart.set([]);
          this.cartOpen.set(false);
        },
        error: (err: HttpErrorResponse) => {
          this.placing.set(false);
          this.error.set(err.error?.message ?? 'Could not place the order.');
        },
      });
  }

  startNewOrder(): void {
    this.confirmation.set(null);
    this.clearBranch();
  }

  scheduleClass(code: string): string {
    const c = (code || '').toUpperCase();
    if (c === 'H1' || c === 'X' || c === 'RESTRICTED') return 'sch-alert';
    if (c === 'H' || c === 'G') return 'sch-rx';
    return 'sch-otc';
  }
}
