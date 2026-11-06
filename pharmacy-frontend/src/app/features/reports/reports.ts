import { Component, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute } from '@angular/router';
import { ReportService } from '../../core/services/report.service';
import { AuthService } from '../../core/services/auth.service';
import { ProfileService, ClientProfile } from '../../core/services/profile.service';
import { ReportExcelService, ReportPharmacyHeader } from '../../core/services/report-excel.service';

type ReportCategory = 'gst' | 'purchase' | 'sales' | 'stock' | 'accounts';

interface ReportDef {
  key: string;
  label: string;
  icon: string;
  path: string;
  hasDate: boolean;
  category: ReportCategory;
  hasMonths?: boolean;
  hasMonth?: boolean;
  hasMargin?: boolean;
  group?: string;
  /** Retail-style entity dropdown (product / category / distributor / …). */
  entityFilter?: {
    source: string;
    param: string;
    label: string;
    /** When true, empty value = All (optional filter). Default: required for detail. */
    optional?: boolean;
  };
}

interface Column {
  key: string;
  label: string;
  type?: 'currency' | 'currencyRound' | 'date' | 'number' | 'badge' | 'expiry';
  align?: 'end';
}

interface BranchOpt { id: number; code: string; name: string; }
interface EntityOpt { value: string; label: string; from_opening?: boolean; }

interface CategoryChip {
  id: 'all' | ReportCategory;
  name: string;
  icon: string;
  color: string;
}

@Component({
  selector: 'app-reports',
  imports: [CommonModule, FormsModule],
  templateUrl: './reports.html',
  styleUrl: './reports.scss',
})
export class Reports {
  private readonly reportSvc = inject(ReportService);
  private readonly auth = inject(AuthService);
  private readonly profileSvc = inject(ProfileService);
  private readonly excelSvc = inject(ReportExcelService);
  private readonly route = inject(ActivatedRoute);

  /** Deep link `?report=<key>&export=view|pdf` (e.g. Dashboard → Short Stock Report). */
  private pendingAction: 'view' | 'pdf' | null = null;
  private profileReady = false;

  readonly isHo = computed(() => this.auth.user()?.role === 'ho_admin' || this.auth.user()?.scope === 'ho');
  readonly branches = signal<BranchOpt[]>([]);
  readonly branchId = signal<number | null>(null);
  readonly pharmacyProfile = signal<ClientProfile | null>(null);

  readonly mode = signal<'catalog' | 'runner'>('catalog');
  readonly searchQuery = signal('');
  readonly selectedCategory = signal<'all' | ReportCategory>('all');
  readonly catalogLoading = signal(false);

  readonly categories: CategoryChip[] = [
    { id: 'all', name: 'All Reports', icon: 'bi-grid', color: '#4c51bf' },
    { id: 'gst', name: 'GST Reports', icon: 'bi-file-earmark-text', color: '#059669' },
    { id: 'purchase', name: 'Purchase Report', icon: 'bi-cart3', color: '#d97706' },
    { id: 'sales', name: 'Sales Report', icon: 'bi-graph-up', color: '#0284c7' },
    { id: 'stock', name: 'Stock Report', icon: 'bi-archive', color: '#7c3aed' },
    { id: 'accounts', name: 'Accounts Report', icon: 'bi-calculator', color: '#dc2626' },
  ];

  readonly reports: ReportDef[] = [
    // —— Accounts / GST ——
    { key: 'purchaseGst', label: 'Purchase GST', icon: 'bi-receipt-cutoff', path: 'purchase-gst', hasDate: true, category: 'accounts', group: 'GST' },
    { key: 'salesGst', label: 'Sales GST', icon: 'bi-file-earmark-ruled', path: 'sales-gst', hasDate: true, category: 'accounts', group: 'GST' },
    { key: 'saleGstSlab', label: 'Sale GST Slab Wise', icon: 'bi-bar-chart-steps', path: 'sale-gst-slab', hasDate: true, category: 'accounts', group: 'GST' },
    { key: 'saleGstSlabBill', label: 'Sale GST Slab Bill Wise', icon: 'bi-receipt', path: 'sale-gst-slab-bill', hasDate: true, category: 'accounts', group: 'GST' },
    { key: 'purGstSlab', label: 'Purchase GST Slab Wise', icon: 'bi-bar-chart', path: 'purchase-gst-slab', hasDate: true, category: 'accounts', group: 'GST' },
    { key: 'purGstSlabBill', label: 'Purchase GST Slab Bill Wise', icon: 'bi-pie-chart', path: 'purchase-gst-slab-bill', hasDate: true, category: 'accounts', group: 'GST' },
    { key: 'allPurGst', label: 'All Product Purchase GST', icon: 'bi-grid', path: 'all-product-purchase-gst', hasDate: true, category: 'accounts', group: 'GST' },
    { key: 'allSaleGst', label: 'All Product Sale GST', icon: 'bi-grid-3x3', path: 'all-product-sale-gst', hasDate: true, category: 'accounts', group: 'GST' },
    { key: 'hsnB2c', label: 'HSN B2C (GST Portal)', icon: 'bi-file-earmark-spreadsheet', path: 'hsn-b2c', hasDate: false, hasMonth: true, category: 'accounts', group: 'GST' },
    { key: 'hsnB2b', label: 'HSN B2B (GST Portal)', icon: 'bi-file-earmark-spreadsheet', path: 'hsn-b2b', hasDate: false, hasMonth: true, category: 'accounts', group: 'GST' },
    { key: 'b2bInvoices', label: 'B2B Invoices (GSTR-1)', icon: 'bi-file-earmark-text', path: 'b2b-invoices', hasDate: false, hasMonth: true, category: 'accounts', group: 'GST' },
    { key: 'b2cSmall', label: 'B2C Small (GSTR-1 Table 7)', icon: 'bi-file-earmark', path: 'b2c-small', hasDate: false, hasMonth: true, category: 'accounts', group: 'GST' },
    { key: 'documentIssued', label: 'Document Issued (GSTR-1)', icon: 'bi-files', path: 'document-issued', hasDate: false, hasMonth: true, category: 'accounts', group: 'GST' },
    { key: 'gst', label: 'GST Summary', icon: 'bi-percent', path: 'gst-summary', hasDate: true, category: 'accounts', group: 'GST' },
    { key: 'gsthsn', label: 'GST Rate-wise (HSN)', icon: 'bi-table', path: 'gst-hsn', hasDate: true, category: 'accounts', group: 'GST' },
    { key: 'distPay', label: 'Distributor Payments', icon: 'bi-wallet2', path: 'distributor-payments', hasDate: true, category: 'accounts', group: 'Payments' },
    { key: 'custPay', label: 'Customer Payments', icon: 'bi-cash', path: 'customer-payments', hasDate: true, category: 'accounts', group: 'Payments' },
    { key: 'areaPay', label: 'Area Wise Payment', icon: 'bi-geo-alt', path: 'area-wise-payment', hasDate: true, category: 'accounts', group: 'Payments' },
    { key: 'cashbook', label: 'Cash Book', icon: 'bi-cash-stack', path: 'cash-book', hasDate: true, category: 'accounts', group: 'Accounts' },
    { key: 'profit', label: 'Profit Report', icon: 'bi-graph-up-arrow', path: 'profit', hasDate: true, category: 'accounts', group: 'Profit' },
    { key: 'pl', label: 'Profit / Loss', icon: 'bi-graph-up', path: 'profit-loss', hasDate: true, category: 'accounts', group: 'Profit' },
    { key: 'accuratePl', label: 'Updated Profit / Loss', icon: 'bi-graph-up-arrow', path: 'accurate-profit-loss', hasDate: true, category: 'accounts', group: 'Profit' },
    { key: 'distPl', label: 'Distributor Profit / Loss', icon: 'bi-pie-chart', path: 'distributor-profit-loss', hasDate: true, category: 'accounts', group: 'Profit' },
    { key: 'doctorMargin', label: 'Doctor Margin', icon: 'bi-person-hearts', path: 'doctor-margin', hasDate: true, hasMargin: true, category: 'accounts', group: 'Profit' },
    { key: 'h1', label: 'Schedule H1 Sales', icon: 'bi-shield-exclamation', path: 'h1-sales', hasDate: true, category: 'accounts', group: 'Compliance' },
    { key: 'x', label: 'Schedule X Sales', icon: 'bi-shield-lock', path: 'x-sales', hasDate: true, category: 'accounts', group: 'Compliance' },
    { key: 'schedule', label: 'All Schedule Register', icon: 'bi-shield-check', path: 'schedule-register', hasDate: true, category: 'accounts', group: 'Compliance' },

    // —— Purchase ——
    { key: 'purDate', label: 'Date Wise Purchase', icon: 'bi-calendar-event', path: 'date-wise-purchase', hasDate: true, category: 'purchase' },
    { key: 'purCat', label: 'Category Wise Purchase', icon: 'bi-bookmark', path: 'category-wise-purchase', hasDate: true, category: 'purchase',
      entityFilter: { source: 'purchase-categories', param: 'category', label: 'Category', optional: true } },
    { key: 'purProduct', label: 'Product Wise Purchase', icon: 'bi-box-seam', path: 'product-wise-purchase', hasDate: true, category: 'purchase',
      entityFilter: { source: 'purchase-products', param: 'product_name', label: 'Product', optional: true } },
    { key: 'purDist', label: 'Distributor Wise Purchase', icon: 'bi-truck', path: 'distributor-wise-purchase', hasDate: true, category: 'purchase',
      entityFilter: { source: 'purchase-distributors', param: 'distributor_name', label: 'Distributor', optional: true } },
    { key: 'purMfr', label: 'Manufacturer Wise Purchase', icon: 'bi-buildings', path: 'manufacturer-wise-purchase', hasDate: true, category: 'purchase',
      entityFilter: { source: 'purchase-manufacturers', param: 'manufacturer', label: 'Manufacturer', optional: true } },
    { key: 'purAll', label: 'All Purchase Report', icon: 'bi-journal-text', path: 'all-purchase', hasDate: true, category: 'purchase' },
    { key: 'purchase', label: 'Purchase Register', icon: 'bi-cart', path: 'purchase-register', hasDate: true, category: 'purchase' },
    { key: 'purEditAudit', label: 'Purchase Edit Audit Log', icon: 'bi-shield-check', path: 'purchase-edit-audit', hasDate: true, category: 'purchase' },
    { key: 'hospDispensing', label: 'Hospital Dispensing Report', icon: 'bi-hospital', path: 'hospital-dispensing', hasDate: true, category: 'purchase',
      entityFilter: { source: 'dispensing-hospitals', param: 'hospital_name', label: 'Hospital', optional: true } },

    // —— Sales ——
    { key: 'saleDate', label: 'Sale Date Wise', icon: 'bi-calendar3', path: 'date-wise-sale', hasDate: true, category: 'sales' },
    { key: 'saleCategory', label: 'Category Wise Sale', icon: 'bi-tags', path: 'category-wise-sale', hasDate: true, category: 'sales',
      entityFilter: { source: 'sale-categories', param: 'category', label: 'Category', optional: true } },
    { key: 'saleDist', label: 'Distributor Wise Sale', icon: 'bi-truck', path: 'distributor-wise-sale', hasDate: true, category: 'sales',
      entityFilter: { source: 'sale-distributors', param: 'distributor_name', label: 'Distributor', optional: true } },
    { key: 'saleMfr', label: 'Manufacturer Wise Sale', icon: 'bi-building', path: 'manufacturer-wise-sale', hasDate: true, category: 'sales',
      entityFilter: { source: 'sale-manufacturers', param: 'manufacturer', label: 'Manufacturer', optional: true } },
    { key: 'saleProduct', label: 'Product Wise Sale', icon: 'bi-capsule', path: 'product-wise-sale', hasDate: true, category: 'sales',
      entityFilter: { source: 'sale-products', param: 'product_name', label: 'Product', optional: true } },
    { key: 'saleAllProduct', label: 'All Product Wise Sale', icon: 'bi-list-ul', path: 'all-product-wise-sale', hasDate: true, category: 'sales' },
    { key: 'doctor', label: 'Doctor Wise Sale', icon: 'bi-person-badge', path: 'doctor-wise', hasDate: true, category: 'sales',
      entityFilter: { source: 'sale-doctors', param: 'doctor_name', label: 'Doctor', optional: true } },
    { key: 'saleCustomer', label: 'Customer Wise Sale', icon: 'bi-people', path: 'customer-wise-sale', hasDate: true, category: 'sales',
      entityFilter: { source: 'sale-customers', param: 'customer', label: 'Customer', optional: true } },
    { key: 'freeQty', label: 'Free Qty Report', icon: 'bi-gift', path: 'free-qty', hasDate: true, category: 'sales' },
    { key: 'online', label: 'Online Sales', icon: 'bi-globe', path: 'online-sales', hasDate: true, category: 'sales' },
    { key: 'sales', label: 'Sales Register', icon: 'bi-receipt', path: 'sales-register', hasDate: true, category: 'sales' },
    { key: 'cashCounterDay', label: 'Cash/Counter Bill Day Report', icon: 'bi-journal-text', path: 'cash-counter-day', hasDate: true, category: 'sales' },
    { key: 'chronic', label: 'Chronic / Repeat Patients', icon: 'bi-heart-pulse', path: 'chronic', hasDate: false, category: 'sales' },

    // —— Stock ——
    { key: 'stock', label: 'Stock Report', icon: 'bi-boxes', path: 'stock', hasDate: false, category: 'stock' },
    { key: 'stockDist', label: 'Distributor Wise Stock', icon: 'bi-people', path: 'distributor-wise-stock', hasDate: false, category: 'stock',
      entityFilter: { source: 'stock-distributors', param: 'distributor_name', label: 'Distributor', optional: true } },
    { key: 'stockMfr', label: 'Manufacturer Wise Stock', icon: 'bi-diagram-3', path: 'manufacturer-wise-stock', hasDate: false, category: 'stock',
      entityFilter: { source: 'stock-manufacturers', param: 'manufacturer', label: 'Manufacturer', optional: true } },
    { key: 'expired', label: 'Expired Stock', icon: 'bi-x-octagon', path: 'expired-stock', hasDate: false, category: 'stock' },
    { key: 'shortStock', label: 'Short Stock Report', icon: 'bi-bell', path: 'short-stock', hasDate: false, category: 'stock' },
    { key: 'stockAdjAudit', label: 'Stock Adjustment Audit Log', icon: 'bi-shield-check', path: 'stock-adjustment-audit', hasDate: true, category: 'stock' },
    { key: 'nearExp', label: 'Near Expiry', icon: 'bi-hourglass', path: 'near-expiry', hasDate: false, category: 'stock', hasMonths: true },
    { key: 'asof', label: 'Stock As On Date', icon: 'bi-calendar-check', path: 'stock-as-of', hasDate: true, category: 'stock' },
    { key: 'saleStock', label: 'Sale Stock', icon: 'bi-bag', path: 'sale-stock', hasDate: true, category: 'stock' },
    { key: 'prodTxn', label: 'Product Transaction', icon: 'bi-arrow-left-right', path: 'product-transaction', hasDate: true, category: 'stock',
      entityFilter: { source: 'purchase-products', param: 'product_name', label: 'Product', optional: true } },
    { key: 'stockbook', label: 'Stock Book', icon: 'bi-journal-text', path: 'stock-book', hasDate: true, category: 'stock' },
    { key: 'fda', label: 'FDA Tally', icon: 'bi-clipboard-data', path: 'fda-tally', hasDate: true, category: 'stock' },
    { key: 'nonmoving', label: 'Non-Moving Stock', icon: 'bi-snow', path: 'non-moving', hasDate: false, category: 'stock' },
    { key: 'expiry', label: 'Expiry Report', icon: 'bi-hourglass-split', path: 'expiry', hasDate: false, category: 'stock' },
    { key: 'expalert', label: 'Expiry Alert', icon: 'bi-exclamation-octagon', path: 'expiry-alert', hasDate: false, category: 'stock' },
  ];

  private readonly scheduleCols: Column[] = [
    { key: 'invoice_no', label: 'Invoice' },
    { key: 'invoice_date', label: 'Date', type: 'date' },
    { key: 'product_name', label: 'Product' },
    { key: 'manufacturer_short', label: 'Mfg' },
    { key: 'batch_no', label: 'Batch' },
    { key: 'patient_name', label: 'Patient' },
    { key: 'doctor_name', label: 'Doctor' },
    { key: 'quantity', label: 'Qty', type: 'number', align: 'end' },
  ];

  private readonly portalHsnCols: Column[] = [
    { key: 'hsn', label: 'HSN' },
    { key: 'description', label: 'Description' },
    { key: 'uqc', label: 'UQC' },
    { key: 'total_quantity', label: 'Total Qty', type: 'number', align: 'end' },
    { key: 'total_value', label: 'Total Value', type: 'currency', align: 'end' },
    { key: 'taxable_value', label: 'Taxable', type: 'currency', align: 'end' },
    { key: 'integrated_tax', label: 'IGST', type: 'currency', align: 'end' },
    { key: 'central_tax', label: 'CGST', type: 'currency', align: 'end' },
    { key: 'state_ut_tax', label: 'SGST/UT', type: 'currency', align: 'end' },
    { key: 'cess_amount', label: 'Cess', type: 'currency', align: 'end' },
    { key: 'rate', label: 'Rate %', type: 'number', align: 'end' },
  ];

  /** Stock qty unit: wholesale = strips; retail/both = tablets. */
  private stockQtySuffix(): string {
    const fromApi = String(this.data()?.qty_unit ?? '').toLowerCase();
    if (fromApi === 'strip' || fromApi === 'tablet') {
      return fromApi === 'strip' ? ' (Strip)' : ' (Tab)';
    }
    const bt = String(this.pharmacyProfile()?.business_type ?? 'both').toLowerCase();
    return bt === 'wholesale' ? ' (Strip)' : ' (Tab)';
  }

  private stockBatchCols(): Column[] {
    const u = this.stockQtySuffix();
    return [
      { key: 'hsn', label: 'HSN' },
      { key: 'product_name', label: 'Product Name' },
      { key: 'batch_no', label: 'Batch No' },
      { key: 'pack_size', label: 'Pack Size' },
      { key: 'rate', label: 'Rate', type: 'number', align: 'end' },
      { key: 'mrp', label: 'MRP', type: 'number', align: 'end' },
      { key: 'expiry', label: 'Expiry', type: 'expiry' },
      { key: 'purchase_qty', label: 'Purchase Qty' + u, type: 'number', align: 'end' },
      { key: 'dbnote_qty', label: 'DB Note Qty' + u, type: 'number', align: 'end' },
      { key: 'sale_free_qty', label: 'Sale Qty+free Qty' + u, type: 'number', align: 'end' },
      { key: 'return_qty', label: 'Return Qty' + u, type: 'number', align: 'end' },
      { key: 'available_qty', label: 'Available Qty' + u, type: 'number', align: 'end' },
      { key: 'stock_value', label: 'Stock Value', type: 'currency', align: 'end' },
    ];
  }

  readonly columns: Record<string, Column[]> = {
    sales: [
      { key: 'invoice_no', label: 'Invoice' }, { key: 'invoice_date', label: 'Date', type: 'date' },
      { key: 'customer_name', label: 'Customer' }, { key: 'gst_amount', label: 'GST', type: 'currency', align: 'end' },
      { key: 'net_amount', label: 'Net', type: 'currency', align: 'end' }, { key: 'profit_amount', label: 'Profit', type: 'currency', align: 'end' },
    ],
    purchase: [
      { key: 'invoice_no', label: 'Invoice' }, { key: 'invoice_date', label: 'Date', type: 'date' },
      { key: 'supplier_name', label: 'Supplier' }, { key: 'gst_amount', label: 'GST', type: 'currency', align: 'end' },
      { key: 'net_amount', label: 'Net', type: 'currency', align: 'end' },
    ],
    doctor: [
      { key: 'doctor_name', label: 'Doctor' }, { key: 'bills', label: 'Bills', type: 'number', align: 'end' },
      { key: 'net', label: 'Net Sales', type: 'currency', align: 'end' }, { key: 'profit', label: 'Profit', type: 'currency', align: 'end' },
    ],
    online: [
      { key: 'order_no', label: 'Order' }, { key: 'created_at', label: 'Date', type: 'date' },
      { key: 'customer_name', label: 'Customer' }, { key: 'phone', label: 'Phone' },
      { key: 'status', label: 'Status', type: 'badge' }, { key: 'total_estimate', label: 'Value', type: 'currency', align: 'end' },
    ],
    chronic: [
      { key: 'patient', label: 'Patient' }, { key: 'contact_no', label: 'Contact' },
      { key: 'visits', label: 'Visits', type: 'number', align: 'end' }, { key: 'spend', label: 'Total Spend', type: 'currency', align: 'end' },
      { key: 'last_visit', label: 'Last Visit', type: 'date' },
    ],
    stock: [], // built via stockBatchCols() in columnsFor()
    nonmoving: [
      { key: 'product_name', label: 'Product' }, { key: 'manufacturer_short', label: 'Mfg' },
      { key: 'schedule_category', label: 'Sch', type: 'badge' }, { key: 'quantity', label: 'Qty', type: 'number', align: 'end' },
      { key: 'last_sold', label: 'Last Sold', type: 'date' },
    ],
    expiry: [
      { key: 'product_name', label: 'Product' }, { key: 'batch_no', label: 'Batch' },
      { key: 'expiry_date', label: 'Expiry', type: 'date' }, { key: 'quantity', label: 'Qty', type: 'number', align: 'end' },
      { key: 'days_to_expiry', label: 'Days left', type: 'number', align: 'end' },
    ],
    expalert: [
      { key: 'product_name', label: 'Product' }, { key: 'manufacturer_short', label: 'Mfg' }, { key: 'batch_no', label: 'Batch' },
      { key: 'expiry_date', label: 'Expiry', type: 'date' }, { key: 'quantity', label: 'Qty', type: 'number', align: 'end' },
      { key: 'days_to_expiry', label: 'Days left', type: 'number', align: 'end' },
    ],
    gsthsn: [
      { key: 'gst_rate', label: 'GST %', type: 'number' }, { key: 'taxable', label: 'Taxable', type: 'currency', align: 'end' },
      { key: 'cgst', label: 'CGST', type: 'currency', align: 'end' }, { key: 'sgst', label: 'SGST', type: 'currency', align: 'end' },
      { key: 'gst', label: 'Total GST', type: 'currency', align: 'end' }, { key: 'total', label: 'Total', type: 'currency', align: 'end' },
    ],
    gst: [
      { key: 'output_gst', label: 'Output GST', type: 'currency', align: 'end' },
      { key: 'input_gst', label: 'Input GST', type: 'currency', align: 'end' },
      { key: 'net_payable', label: 'Net Payable', type: 'currency', align: 'end' },
    ],
    profit: [
      { key: 'product_name', label: 'Product' },
      { key: 'qty', label: 'Qty', type: 'number', align: 'end' },
      { key: 'revenue', label: 'Revenue', type: 'currency', align: 'end' },
      { key: 'profit', label: 'Profit', type: 'currency', align: 'end' },
    ],
    cashbook: [
      { key: 'date', label: 'Date', type: 'date' }, { key: 'in', label: 'Cash In', type: 'currency', align: 'end' },
      { key: 'out', label: 'Cash Out', type: 'currency', align: 'end' }, { key: 'net', label: 'Net', type: 'currency', align: 'end' },
    ],
    asof: [
      { key: 'product_name', label: 'Product' }, { key: 'manufacturer_short', label: 'Mfg' },
      { key: 'schedule_category', label: 'Sch', type: 'badge' }, { key: 'balance', label: 'Closing Qty', type: 'number', align: 'end' },
    ],
    stockbook: [
      { key: 'txn_date', label: 'Date', type: 'date' }, { key: 'product_name', label: 'Product' },
      { key: 'manufacturer_short', label: 'Mfg' }, { key: 'txn_type', label: 'Type', type: 'badge' },
      { key: 'qty_in', label: 'In', type: 'number', align: 'end' }, { key: 'qty_out', label: 'Out', type: 'number', align: 'end' },
    ],
    fda: [
      { key: 'product_name', label: 'Product' }, { key: 'manufacturer_short', label: 'Mfg' },
      { key: 'opening', label: 'Opening', type: 'number', align: 'end' }, { key: 'purchase', label: 'Purchase', type: 'number', align: 'end' },
      { key: 'sale', label: 'Sale', type: 'number', align: 'end' }, { key: 'expired', label: 'Expired', type: 'number', align: 'end' },
      { key: 'closing', label: 'Closing', type: 'number', align: 'end' },
    ],
    schedule: this.scheduleCols,
    h1: this.scheduleCols,
    x: this.scheduleCols,
    saleDate: [
      { key: 'invoice_date', label: 'Date', type: 'date' }, { key: 'bills', label: 'Bills', type: 'number', align: 'end' },
      { key: 'net_amount', label: 'Net', type: 'currency', align: 'end' }, { key: 'gst_amount', label: 'GST', type: 'currency', align: 'end' },
      { key: 'profit_amount', label: 'Profit', type: 'currency', align: 'end' },
    ],
    cashCounterDay: [
      { key: 'invoice_no', label: 'Invoice' }, { key: 'invoice_date', label: 'Date', type: 'date' },
      { key: 'counter', label: 'Counter' }, { key: 'payment_mode', label: 'Payment' },
      { key: 'item_count', label: 'Items', type: 'number', align: 'end' },
      { key: 'gross_amount', label: 'Gross', type: 'currency', align: 'end' },
      { key: 'discount_amount', label: 'Discount', type: 'currency', align: 'end' },
      { key: 'gst_amount', label: 'GST', type: 'currency', align: 'end' },
      { key: 'net_amount', label: 'Net', type: 'currency', align: 'end' },
    ],
    saleProduct: [
      { key: 'product_name', label: 'Product' }, { key: 'quantity', label: 'Qty', type: 'number', align: 'end' },
      { key: 'bills', label: 'Bills', type: 'number', align: 'end' },
      { key: 'net_amount', label: 'Net', type: 'currency', align: 'end' }, { key: 'profit', label: 'Profit', type: 'currency', align: 'end' },
    ],
    saleAllProduct: [
      { key: 'invoice_date', label: 'Date', type: 'date' }, { key: 'invoice_no', label: 'Bill' },
      { key: 'customer_name', label: 'Customer' }, { key: 'product_name', label: 'Product' },
      { key: 'batch_no', label: 'Batch' }, { key: 'quantity', label: 'Qty', type: 'number', align: 'end' },
      { key: 'rate', label: 'Rate', type: 'currency', align: 'end' }, { key: 'line_total', label: 'Net', type: 'currency', align: 'end' },
    ],
    saleCategory: [
      { key: 'category', label: 'Category' }, { key: 'quantity', label: 'Qty', type: 'number', align: 'end' },
      { key: 'net_amount', label: 'Net', type: 'currency', align: 'end' }, { key: 'profit', label: 'Profit', type: 'currency', align: 'end' },
    ],
    saleMfr: [
      { key: 'manufacturer', label: 'Manufacturer' }, { key: 'quantity', label: 'Qty', type: 'number', align: 'end' },
      { key: 'net_amount', label: 'Net', type: 'currency', align: 'end' }, { key: 'profit', label: 'Profit', type: 'currency', align: 'end' },
    ],
    saleCustomer: [
      { key: 'customer_name', label: 'Customer' }, { key: 'bills', label: 'Bills', type: 'number', align: 'end' },
      { key: 'net_amount', label: 'Net', type: 'currency', align: 'end' }, { key: 'balance', label: 'Balance', type: 'currency', align: 'end' },
    ],
    saleDist: [
      { key: 'supplier_name', label: 'Distributor' }, { key: 'sale_date', label: 'Date', type: 'date' },
      { key: 'customer_name', label: 'Customer' }, { key: 'invoice_no', label: 'Invoice' },
      { key: 'product_name', label: 'Product' }, { key: 'batch_no', label: 'Batch' },
      { key: 'quantity', label: 'Qty', type: 'number', align: 'end' },
      { key: 'net_amount', label: 'Net', type: 'currency', align: 'end' },
    ],
    freeQty: [
      { key: 'product_name', label: 'Product' }, { key: 'batch_no', label: 'Batch' },
      { key: 'quantity', label: 'Qty', type: 'number', align: 'end' },
      { key: 'free_quantity', label: 'Free Qty', type: 'number', align: 'end' },
    ],
    purDate: [
      { key: 'invoice_date', label: 'Date', type: 'date' }, { key: 'invoice_no', label: 'Invoice' },
      { key: 'supplier_name', label: 'Distributor' }, { key: 'invoice_amt', label: 'Amount', type: 'currency', align: 'end' },
      { key: 'due_amt', label: 'Due', type: 'currency', align: 'end' }, { key: 'cash_credit', label: 'C/D' },
    ],
    purAll: [
      { key: 'invoice_date', label: 'Date', type: 'date' }, { key: 'invoice_no', label: 'Invoice' },
      { key: 'supplier_name', label: 'Distributor' }, { key: 'product_name', label: 'Product' },
      { key: 'batch_no', label: 'Batch' }, { key: 'expiry_date', label: 'Expiry', type: 'expiry' },
      { key: 'quantity', label: 'P.Qty', type: 'number', align: 'end' },
      { key: 'free_quantity', label: 'F.Qty', type: 'number', align: 'end' },
      { key: 'purchase_rate', label: 'Rate', type: 'currency', align: 'end' },
      { key: 'mrp', label: 'MRP', type: 'currency', align: 'end' },
      { key: 'discount_pct', label: 'Disc%', type: 'number', align: 'end' },
      { key: 'gst_rate', label: 'GST%', type: 'number', align: 'end' },
      { key: 'line_total', label: 'Net', type: 'currency', align: 'end' },
    ],
    purDist: [
      { key: 'supplier_name', label: 'Distributor' }, { key: 'bills', label: 'Bills', type: 'number', align: 'end' },
      { key: 'net_amount', label: 'Net', type: 'currency', align: 'end' }, { key: 'gst_amount', label: 'GST', type: 'currency', align: 'end' },
      { key: 'balance', label: 'Balance', type: 'currency', align: 'end' },
    ],
    purProduct: [
      { key: 'product_name', label: 'Product' }, { key: 'quantity', label: 'Qty', type: 'number', align: 'end' },
      { key: 'free_quantity', label: 'Free', type: 'number', align: 'end' },
      { key: 'net_amount', label: 'Net', type: 'currency', align: 'end' },
    ],
    purMfr: [
      { key: 'manufacturer', label: 'Manufacturer' }, { key: 'quantity', label: 'Qty', type: 'number', align: 'end' },
      { key: 'net_amount', label: 'Net', type: 'currency', align: 'end' },
    ],
    purCat: [
      { key: 'category', label: 'Category' }, { key: 'quantity', label: 'Qty', type: 'number', align: 'end' },
      { key: 'net_amount', label: 'Net', type: 'currency', align: 'end' },
    ],
    stockMfr: [], // built via stockBatchCols() in columnsFor()
    stockDist: [
      { key: 'product_name', label: 'Product Name' },
      { key: 'pack_size', label: 'Pack Size' },
      { key: 'distributor_name', label: 'Distributor Name' },
      { key: 'invoice_no', label: 'Invoice No' },
      { key: 'invoice_date', label: 'Invoice Date', type: 'date' },
      { key: 'batch_no', label: 'Batch No' },
      { key: 'rate', label: 'Rate', type: 'number', align: 'end' },
      { key: 'mrp', label: 'MRP', type: 'number', align: 'end' },
      { key: 'purchase_qty', label: 'Purchase Qty', type: 'number', align: 'end' },
      { key: 'available_qty', label: 'Available Qty', type: 'number', align: 'end' },
    ],
    saleStock: [
      { key: 'product_name', label: 'Product Name' },
      { key: 'batch_no', label: 'Batch No' },
      { key: 'sale_qty', label: 'Sale Qty (Pack)', type: 'number', align: 'end' },
      { key: 'available_qty', label: 'Available Pack Qty', type: 'number', align: 'end' },
      { key: 'stock_value', label: 'Stock Value', type: 'currency', align: 'end' },
      { key: 'sale_value', label: 'Sale Value', type: 'currency', align: 'end' },
    ],
    prodTxn: [
      { key: 'txn_date', label: 'Date', type: 'date' },
      { key: 'party', label: 'Party Name' },
      { key: 'voucher_no', label: 'Vh No.' },
      { key: 'voucher_type', label: 'Vh Type', type: 'badge' },
      { key: 'batch_no', label: 'Batch No' },
      { key: 'expiry', label: 'Expiry', type: 'expiry' },
      { key: 'qty_in', label: 'In Qty', type: 'number', align: 'end' },
      { key: 'qty_out', label: 'Out Qty', type: 'number', align: 'end' },
      { key: 'rate', label: 'Rate', type: 'currency', align: 'end' },
      { key: 'mrp', label: 'MRP', type: 'currency', align: 'end' },
      { key: 'amount', label: 'Amount', type: 'currency', align: 'end' },
    ],
    shortStock: [
      { key: 'product_name', label: 'Product' },
      { key: 'pack_size', label: 'Pack' },
      { key: 'mrp', label: 'MRP', type: 'currency', align: 'end' },
      { key: 'sold_qty', label: 'Sold Qty', type: 'number', align: 'end' },
      { key: 'available_qty', label: 'Available Qty', type: 'number', align: 'end' },
      { key: 'distributor_name', label: 'Last Distributor' },
    ],
    stockAdjAudit: [
      { key: 'date', label: 'Date', type: 'date' },
      { key: 'time', label: 'Time' },
      { key: 'adjustment_no', label: 'Adj. No' },
      { key: 'adjustment_type', label: 'Type' },
      { key: 'product_name', label: 'Product' },
      { key: 'batch_no', label: 'Batch' },
      { key: 'system_qty', label: 'System Qty', align: 'end' },
      { key: 'physical_qty', label: 'Physical Qty', align: 'end' },
      { key: 'difference_qty', label: 'Difference', align: 'end' },
      { key: 'reason', label: 'Reason' },
      { key: 'user_name', label: 'User' },
      { key: 'ip_address', label: 'IP Address' },
    ],
    purEditAudit: [
      { key: 'when', label: 'Edited On' },
      { key: 'bill', label: 'PO / Invoice' },
      { key: 'supplier', label: 'Distributor' },
      { key: 'change', label: 'Change', type: 'badge' },
      { key: 'product', label: 'Product · Batch' },
      { key: 'pack', label: 'Pack' },
      { key: 'qty', label: 'Qty', align: 'end' },
      { key: 'rate', label: 'Rate', align: 'end' },
      { key: 'mrp', label: 'MRP', align: 'end' },
      { key: 'sold', label: 'Sold', type: 'badge' },
      { key: 'stock', label: 'Stock', align: 'end' },
      { key: 'user', label: 'User' },
      { key: 'ip', label: 'IP' },
    ],
    hospDispensing: [
      { key: 'date', label: 'Date', type: 'date' },
      { key: 'time', label: 'Time' },
      { key: 'dispensing_no', label: 'Disp. No' },
      { key: 'hospital_name', label: 'Hospital' },
      { key: 'patient_name', label: 'Patient' },
      { key: 'department', label: 'OT / Ward' },
      { key: 'product_name', label: 'Product' },
      { key: 'pack_size', label: 'Pack' },
      { key: 'batch_no', label: 'Batch' },
      { key: 'expiry', label: 'Expiry', type: 'expiry' },
      { key: 'qty', label: 'Qty', type: 'number', align: 'end' },
      { key: 'mrp', label: 'MRP', type: 'currency', align: 'end' },
      { key: 'discount_pct', label: 'Disc %', type: 'number', align: 'end' },
      { key: 'amount', label: 'Amount', type: 'currency', align: 'end' },
      { key: 'user_name', label: 'User' },
    ],
    expired: [
      { key: 'product_name', label: 'Product Name' },
      { key: 'pack_size', label: 'Pack Size' },
      { key: 'batch_no', label: 'Batch No.' },
      { key: 'expiry_date', label: 'Expiry Date', type: 'expiry' },
      { key: 'category', label: 'Category', type: 'badge' },
      { key: 'available_qty', label: 'Aval Qty', type: 'number', align: 'end' },
      { key: 'mrp', label: 'MRP', type: 'number', align: 'end' },
      { key: 'stock_value', label: 'Stock Value', type: 'currency', align: 'end' },
    ],
    nearExp: [
      { key: 'product_name', label: 'Product Name' },
      { key: 'batch_no', label: 'Batch No' },
      { key: 'purchase_qty', label: 'Purchase Qty', type: 'number', align: 'end' },
      { key: 'available_qty', label: 'Available Qty', type: 'number', align: 'end' },
      { key: 'expiry_date', label: 'Expiry Date', type: 'date' },
      { key: 'days_to_expiry', label: 'Days left', type: 'number', align: 'end' },
      { key: 'stock_value', label: 'Stock Value', type: 'currency', align: 'end' },
    ],
    purchaseGst: [
      { key: 'invoice_date', label: 'Date', type: 'date' }, { key: 'invoice_no', label: 'Invoice' },
      { key: 'supplier_name', label: 'Distributor' }, { key: 'net_amount', label: 'Net', type: 'currency', align: 'end' },
      { key: 'cgst', label: 'CGST', type: 'currency', align: 'end' }, { key: 'sgst', label: 'SGST', type: 'currency', align: 'end' },
      { key: 'gst_amount', label: 'Total GST', type: 'currency', align: 'end' },
    ],
    salesGst: [
      { key: 'invoice_date', label: 'Date', type: 'date' }, { key: 'invoice_no', label: 'Bill' },
      { key: 'customer_name', label: 'Customer' }, { key: 'taxable_amount', label: 'Taxable', type: 'currency', align: 'end' },
      { key: 'cgst', label: 'CGST', type: 'currency', align: 'end' }, { key: 'sgst', label: 'SGST', type: 'currency', align: 'end' },
      { key: 'igst', label: 'IGST', type: 'currency', align: 'end' }, { key: 'gst_amount', label: 'GST', type: 'currency', align: 'end' },
    ],
    saleGstSlab: [
      { key: 'gst_rate', label: 'GST %', type: 'number' }, { key: 'taxable', label: 'Taxable', type: 'currency', align: 'end' },
      { key: 'cgst', label: 'CGST', type: 'currency', align: 'end' }, { key: 'sgst', label: 'SGST', type: 'currency', align: 'end' },
      { key: 'gst', label: 'Total Tax', type: 'currency', align: 'end' },
    ],
    saleGstSlabBill: [
      { key: 'bill_no', label: 'Bill' }, { key: 'gst_rate', label: 'GST %', type: 'number' },
      { key: 'taxable', label: 'Taxable', type: 'currency', align: 'end' },
      { key: 'cgst', label: 'CGST', type: 'currency', align: 'end' }, { key: 'sgst', label: 'SGST', type: 'currency', align: 'end' },
      { key: 'igst', label: 'IGST', type: 'currency', align: 'end' },
      { key: 'gst', label: 'Total Tax', type: 'currency', align: 'end' },
      { key: 'net_amount', label: 'Net', type: 'currency', align: 'end' },
    ],
    purGstSlab: [
      { key: 'gst_rate', label: 'GST %', type: 'number' }, { key: 'taxable', label: 'Taxable', type: 'currency', align: 'end' },
      { key: 'cgst', label: 'CGST', type: 'currency', align: 'end' }, { key: 'sgst', label: 'SGST', type: 'currency', align: 'end' },
      { key: 'gst', label: 'Total Tax', type: 'currency', align: 'end' },
    ],
    purGstSlabBill: [
      { key: 'invoice_no', label: 'Invoice' }, { key: 'gst_rate', label: 'GST %', type: 'number' },
      { key: 'taxable', label: 'Taxable', type: 'currency', align: 'end' },
      { key: 'cgst', label: 'CGST', type: 'currency', align: 'end' }, { key: 'sgst', label: 'SGST', type: 'currency', align: 'end' },
      { key: 'igst', label: 'IGST', type: 'currency', align: 'end' },
      { key: 'gst', label: 'Total Tax', type: 'currency', align: 'end' },
      { key: 'net_amount', label: 'Net', type: 'currency', align: 'end' },
    ],
    allSaleGst: [
      { key: 'invoice_date', label: 'Date', type: 'date' }, { key: 'invoice_no', label: 'Bill' },
      { key: 'product_name', label: 'Product' }, { key: 'hsn_code', label: 'HSN' },
      { key: 'gst_rate', label: 'GST %', type: 'number' }, { key: 'taxable', label: 'Taxable', type: 'currency', align: 'end' },
      { key: 'gst', label: 'GST', type: 'currency', align: 'end' }, { key: 'net_amount', label: 'Net', type: 'currency', align: 'end' },
    ],
    allPurGst: [
      { key: 'invoice_date', label: 'Date', type: 'date' }, { key: 'invoice_no', label: 'Invoice' },
      { key: 'product_name', label: 'Product' }, { key: 'hsn_code', label: 'HSN' },
      { key: 'gst_rate', label: 'GST %', type: 'number' }, { key: 'taxable', label: 'Taxable', type: 'currency', align: 'end' },
      { key: 'gst', label: 'GST', type: 'currency', align: 'end' }, { key: 'net_amount', label: 'Net', type: 'currency', align: 'end' },
    ],
    hsnB2c: this.portalHsnCols,
    hsnB2b: this.portalHsnCols,
    b2bInvoices: [
      { key: 'recipient_gstin', label: 'GSTIN' }, { key: 'receiver_name', label: 'Receiver' },
      { key: 'invoice_number', label: 'Invoice' }, { key: 'invoice_date', label: 'Date', type: 'date' },
      { key: 'invoice_value', label: 'Invoice Value', type: 'currency', align: 'end' },
      { key: 'place_of_supply', label: 'Place of Supply' },
      { key: 'reverse_charge', label: 'RCM' }, { key: 'invoice_type', label: 'Type' },
      { key: 'rate', label: 'Rate %', type: 'number', align: 'end' },
      { key: 'taxable_value', label: 'Taxable', type: 'currency', align: 'end' },
      { key: 'cess_amount', label: 'Cess', type: 'currency', align: 'end' },
      { key: 'ecommerce_gstin', label: 'E-comm GSTIN' },
    ],
    b2cSmall: [
      { key: 'supply_type', label: 'Supply Type' }, { key: 'place_of_supply', label: 'Place of Supply' },
      { key: 'rate', label: 'Rate %', type: 'number', align: 'end' },
      { key: 'taxable_value', label: 'Taxable', type: 'currency', align: 'end' },
      { key: 'cess_amount', label: 'Cess', type: 'currency', align: 'end' },
      { key: 'ecommerce_gstin', label: 'E-comm GSTIN' },
    ],
    documentIssued: [
      { key: 'nature_of_document', label: 'Nature of Document' },
      { key: 'sr_no_from', label: 'Sr From' }, { key: 'sr_no_to', label: 'Sr To' },
      { key: 'total_number', label: 'Total', type: 'number', align: 'end' },
      { key: 'cancelled', label: 'Cancelled', type: 'number', align: 'end' },
    ],
    distPay: [
      { key: 'invoice_date', label: 'Date', type: 'date' }, { key: 'invoice_no', label: 'Invoice' },
      { key: 'supplier_name', label: 'Distributor' }, { key: 'bill_amount', label: 'Bill', type: 'currencyRound', align: 'end' },
      { key: 'paid', label: 'Paid', type: 'currencyRound', align: 'end' }, { key: 'balance', label: 'Balance', type: 'currencyRound', align: 'end' },
      { key: 'cash_credit', label: 'C/D' },
    ],
    custPay: [
      { key: 'invoice_date', label: 'Date', type: 'date' }, { key: 'invoice_no', label: 'Bill' },
      { key: 'customer_name', label: 'Customer' }, { key: 'bill_amount', label: 'Bill', type: 'currency', align: 'end' },
      { key: 'paid', label: 'Paid', type: 'currency', align: 'end' }, { key: 'balance', label: 'Balance', type: 'currency', align: 'end' },
    ],
    areaPay: [
      { key: 'area', label: 'Area' }, { key: 'city', label: 'City' }, { key: 'state', label: 'State' },
      { key: 'patient_name', label: 'Patient' }, { key: 'contact_no', label: 'Contact' },
      { key: 'bill_amount', label: 'Bill', type: 'currency', align: 'end' },
      { key: 'paid_amount', label: 'Paid', type: 'currency', align: 'end' },
      { key: 'balance', label: 'Balance', type: 'currency', align: 'end' },
    ],
    pl: [
      { key: 'product_name', label: 'Product' }, { key: 'quantity', label: 'Qty', type: 'number', align: 'end' },
      { key: 'sale_rate', label: 'Sale Rate', type: 'currency', align: 'end' },
      { key: 'purchase_rate', label: 'Pur Rate', type: 'currency', align: 'end' },
      { key: 'sale_value', label: 'Sale Value', type: 'currency', align: 'end' },
      { key: 'purchase_value', label: 'Pur Value', type: 'currency', align: 'end' },
      { key: 'profit', label: 'Profit', type: 'currency', align: 'end' },
    ],
    accuratePl: [
      { key: 'product_name', label: 'Product' }, { key: 'batch_no', label: 'Batch' },
      { key: 'quantity', label: 'Qty', type: 'number', align: 'end' },
      { key: 'sale_rate', label: 'Sale Rate', type: 'currency', align: 'end' },
      { key: 'cost_rate', label: 'Cost Rate', type: 'currency', align: 'end' },
      { key: 'sale_value', label: 'Sale Value', type: 'currency', align: 'end' },
      { key: 'cost_value', label: 'Cost Value', type: 'currency', align: 'end' },
      { key: 'profit', label: 'Profit', type: 'currency', align: 'end' },
    ],
    distPl: [
      { key: 'supplier_name', label: 'Distributor' },
      { key: 'purchase_value', label: 'Purchase', type: 'currency', align: 'end' },
      { key: 'sale_value', label: 'Sale', type: 'currency', align: 'end' },
      { key: 'profit', label: 'Profit', type: 'currency', align: 'end' },
    ],
    doctorMargin: [
      { key: 'doctor_name', label: 'Doctor' }, { key: 'product_name', label: 'Product' },
      { key: 'batch_no', label: 'Batch' }, { key: 'quantity', label: 'Qty', type: 'number', align: 'end' },
      { key: 'net_amount', label: 'Net', type: 'currency', align: 'end' },
      { key: 'profit', label: 'Profit', type: 'currency', align: 'end' },
      { key: 'margin_pct', label: 'Margin %', type: 'number', align: 'end' },
      { key: 'margin_amount', label: 'Margin Amt', type: 'currency', align: 'end' },
    ],
  };

  /** Retail-style line columns when an entity filter is selected. */
  private readonly detailColumns: Record<string, Column[]> = {
    purProduct: [
      { key: 'entry_type', label: 'Type', type: 'badge' },
      { key: 'invoice_no', label: 'Invoice / Ref' }, { key: 'invoice_date', label: 'Date', type: 'date' },
      { key: 'supplier_name', label: 'Distributor / Source' }, { key: 'product_name', label: 'Product' },
      { key: 'batch_no', label: 'Batch' }, { key: 'expiry', label: 'Expiry', type: 'expiry' },
      { key: 'quantity', label: 'Qty', type: 'number', align: 'end' },
      { key: 'rate', label: 'Rate', type: 'currency', align: 'end' },
      { key: 'mrp', label: 'MRP', type: 'currency', align: 'end' },
      { key: 'amount', label: 'Amount', type: 'currency', align: 'end' },
    ],
    purCat: [
      { key: 'invoice_no', label: 'Invoice' }, { key: 'invoice_date', label: 'Date', type: 'date' },
      { key: 'supplier_name', label: 'Distributor' }, { key: 'product_name', label: 'Product' },
      { key: 'batch_no', label: 'Batch' }, { key: 'quantity', label: 'Qty', type: 'number', align: 'end' },
      { key: 'rate', label: 'Rate', type: 'currency', align: 'end' },
      { key: 'amount', label: 'Amount', type: 'currency', align: 'end' },
    ],
    purDist: [
      { key: 'invoice_no', label: 'Invoice' }, { key: 'invoice_date', label: 'Date', type: 'date' },
      { key: 'supplier_name', label: 'Distributor' }, { key: 'product_name', label: 'Product' },
      { key: 'batch_no', label: 'Batch' }, { key: 'quantity', label: 'Qty', type: 'number', align: 'end' },
      { key: 'rate', label: 'Rate', type: 'currency', align: 'end' },
      { key: 'amount', label: 'Amount', type: 'currency', align: 'end' },
    ],
    purMfr: [
      { key: 'invoice_no', label: 'Invoice' }, { key: 'invoice_date', label: 'Date', type: 'date' },
      { key: 'supplier_name', label: 'Distributor' }, { key: 'product_name', label: 'Product' },
      { key: 'batch_no', label: 'Batch' }, { key: 'quantity', label: 'Qty', type: 'number', align: 'end' },
      { key: 'rate', label: 'Rate', type: 'currency', align: 'end' },
      { key: 'amount', label: 'Amount', type: 'currency', align: 'end' },
    ],
    saleProduct: [
      { key: 'sale_date', label: 'Date', type: 'date' }, { key: 'customer_name', label: 'Patient' },
      { key: 'bill_no', label: 'Bill' }, { key: 'product_name', label: 'Product' },
      { key: 'batch_no', label: 'Batch' }, { key: 'quantity', label: 'Qty', type: 'number', align: 'end' },
      { key: 'net_amount', label: 'Net', type: 'currency', align: 'end' },
    ],
    saleCategory: [
      { key: 'sale_date', label: 'Date', type: 'date' }, { key: 'customer_name', label: 'Patient' },
      { key: 'bill_no', label: 'Bill' }, { key: 'product_name', label: 'Product' },
      { key: 'batch_no', label: 'Batch' }, { key: 'quantity', label: 'Qty', type: 'number', align: 'end' },
      { key: 'net_amount', label: 'Net', type: 'currency', align: 'end' },
    ],
    saleMfr: [
      { key: 'sale_date', label: 'Date', type: 'date' }, { key: 'customer_name', label: 'Patient' },
      { key: 'bill_no', label: 'Bill' }, { key: 'product_name', label: 'Product' },
      { key: 'batch_no', label: 'Batch' }, { key: 'quantity', label: 'Qty', type: 'number', align: 'end' },
      { key: 'net_amount', label: 'Net', type: 'currency', align: 'end' },
    ],
    saleCustomer: [
      { key: 'bill_no', label: 'Bill' }, { key: 'bill_date', label: 'Date', type: 'date' },
      { key: 'product_name', label: 'Product' }, { key: 'batch_no', label: 'Batch' },
      { key: 'quantity', label: 'Qty', type: 'number', align: 'end' },
      { key: 'mrp', label: 'MRP', type: 'currency', align: 'end' },
      { key: 'net_amount', label: 'Net', type: 'currency', align: 'end' },
    ],
    doctor: [
      { key: 'bill_no', label: 'Bill' }, { key: 'bill_date', label: 'Date', type: 'date' },
      { key: 'patient_name', label: 'Patient' }, { key: 'product_name', label: 'Product' },
      { key: 'quantity', label: 'Qty', type: 'number', align: 'end' },
      { key: 'amount', label: 'Amount', type: 'currency', align: 'end' },
    ],
  };

  readonly active = signal<ReportDef>(this.reports[0]);
  readonly from = signal(this.monthStart());
  readonly to = signal(this.todayLocal());
  readonly month = signal(this.currentMonth());
  readonly months = signal(3);
  readonly marginPct = signal(0);
  readonly loading = signal(false);
  readonly viewOpen = signal(false);
  readonly entityOptions = signal<EntityOpt[]>([]);
  readonly entityValue = signal('');
  readonly entityLoading = signal(false);
  readonly entitySearch = signal('');
  readonly entityMenuOpen = signal(false);
  readonly resultsSearch = signal('');
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  readonly data = signal<any>(null);

  readonly filteredReports = computed(() => {
    let list = this.reports;
    const cat = this.selectedCategory();
    if (cat === 'gst') {
      list = list.filter((r) => r.category === 'gst' || /gst/i.test(r.label));
    } else if (cat !== 'all') {
      list = list.filter((r) => r.category === cat);
    }
    const q = this.searchQuery().trim().toLowerCase();
    if (q) {
      list = list.filter((r) => r.label.toLowerCase().includes(q));
    }
    return list;
  });

  readonly selectedCategoryColor = computed(() => {
    const cat = this.categories.find((c) => c.id === this.selectedCategory());
    return cat?.color ?? '#4c51bf';
  });

  constructor() {
    if (this.isHo()) {
      this.reportSvc.get<BranchOpt[]>('branches').subscribe((b) => this.branches.set(b));
    }
    this.profileSvc.load().subscribe({
      next: (p) => {
        this.pharmacyProfile.set(p);
        this.profileReady = true;
        this.flushPendingAction();
      },
      error: () => {
        this.pharmacyProfile.set(null);
        this.profileReady = true;
        this.flushPendingAction();
      },
    });

    const query = this.route.snapshot.queryParamMap;
    const linked = this.reports.find((r) => r.key === query.get('report'));
    if (linked) {
      const action = query.get('export');
      this.pendingAction = action === 'pdf' || action === 'view' ? action : null;
      this.openRunner(linked);
    }
  }

  private flushPendingAction(): void {
    const action = this.pendingAction;
    if (!action || this.loading() || !this.data()) return;
    if (action === 'pdf' && !this.profileReady) return;
    this.pendingAction = null;
    if (action === 'view') {
      this.openView();
    } else {
      setTimeout(() => this.downloadPdf(), 0);
    }
  }

  /** Medical store letterhead for PDF / Excel. */
  pharmacyHeader(report?: ReportDef): ReportPharmacyHeader {
    const p = this.pharmacyProfile();
    const user = this.auth.user();
    const name =
      (p?.business_name || '').trim() ||
      (user as { business_name?: string })?.business_name ||
      'Pharmacy';
    return {
      businessName: name,
      address: (p?.address || '').trim() || undefined,
      city: (p?.city || '').trim() || undefined,
      state: (p?.state || '').trim() || undefined,
      phone: (p?.phone || '').trim() || undefined,
      email: (p?.email || '').trim() || undefined,
      gstin: (p?.gstin || '').trim() || undefined,
      drugLicenseNo: (p?.drug_license_no || '').trim() || undefined,
      fssaiNo: (p?.fssai_no || '').trim() || undefined,
      branchName: this.selectedBranchLabel() || undefined,
      period: this.periodLabelFor(report || this.active()) || undefined,
    };
  }

  pharmacyAddressLine(): string {
    const h = this.pharmacyHeader();
    return [h.address, h.city, h.state].filter(Boolean).join(', ');
  }

  pharmacyIdsLine(): string {
    const h = this.pharmacyHeader();
    const p = this.pharmacyProfile();
    const bits: string[] = [];
    if (h.drugLicenseNo) bits.push(`DL No.: ${h.drugLicenseNo}`);
    if (h.gstin) bits.push(`GSTIN: ${h.gstin}`);
    const fssai = (p?.fssai_no || '').trim();
    if (fssai) bits.push(`FSSAI: ${fssai}`);
    if (h.phone) bits.push(`Ph: ${h.phone}`);
    if (h.email) bits.push(`Email: ${h.email}`);
    return bits.join('  |  ');
  }

  /** True when at least one summable column has values to total. */
  hasTotalRow(report?: ReportDef, data?: unknown): boolean {
    const r = report || this.active();
    // Single-row KPI summaries — no table total needed
    if (r.key === 'gst') return false;
    const d = data !== undefined ? data : this.data();
    const cols = this.columnsFor(r);
    const rows = this.excelRows(r, d);
    if (!rows.length || !cols.length) return false;
    return cols.some((c) => this.isSummableColumn(c));
  }

  /** Display cells for UI / PDF total row (formatted). */
  totalRowDisplay(report?: ReportDef, data?: unknown): string[] {
    const r = report || this.active();
    const d = data !== undefined ? data : this.data();
    const cols = this.columnsFor(r);
    const rows = this.excelRows(r, d);
    const raw = this.buildFooterRaw(cols, rows);
    if (!raw) return [];
    return cols.map((c, i) => {
      const v = raw[i];
      if (v === 'Total') return 'Total';
      if (v === '' || v == null) return '';
      if (typeof v === 'number') {
        if (c.type === 'currency') {
          return v.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        const frac = Number.isInteger(v) ? 0 : 2;
        return v.toLocaleString('en-IN', { minimumFractionDigits: frac, maximumFractionDigits: 2 });
      }
      return String(v);
    });
  }

  private isSummableColumn(col: Column): boolean {
    const k = (col.key || '').toLowerCase();
    // Unit rates, MRP, %, days — not meaningful to sum
    if (/(^rate$|_rate$|^mrp$|margin_pct|gst_rate|days_to_expiry|_pct$|percent)/.test(k)) return false;
    if (col.type === 'currency' || col.type === 'currencyRound' || col.type === 'number') return true;
    return false;
  }

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  private buildFooterRaw(cols: Column[], rows: any[]): (string | number)[] | null {
    if (!rows.length || !cols.length) return null;
    const summable = cols.map((c) => this.isSummableColumn(c));
    if (!summable.some(Boolean)) return null;

    const footer: (string | number)[] = cols.map(() => '');
    let labelPlaced = false;
    cols.forEach((col, i) => {
      if (summable[i]) {
        let sum = 0;
        let any = false;
        for (const row of rows) {
          const n = Number(row[col.key]);
          if (row[col.key] != null && row[col.key] !== '' && !isNaN(n)) {
            sum += n;
            any = true;
          }
        }
        footer[i] = any
          ? (col.type === 'currencyRound' ? Math.round(sum) : Math.round(sum * 100) / 100)
          : '';
      } else if (!labelPlaced) {
        footer[i] = 'Total';
        labelPlaced = true;
      }
    });
    if (!labelPlaced) footer[0] = 'Total';
    return footer;
  }

  private todayLocal(): string {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  }

  /** Calendar max = today (no future dates in report filters). */
  maxDate(): string {
    return this.todayLocal();
  }

  maxMonth(): string {
    return this.currentMonth();
  }

  onFromDateChange(value: string): void {
    const today = this.todayLocal();
    let from = (value || '').trim();
    if (from && from > today) from = today;
    this.from.set(from);
    const to = this.to();
    if (from && to && from > to) {
      this.to.set(from);
    }
  }

  onToDateChange(value: string): void {
    const today = this.todayLocal();
    let to = (value || '').trim();
    if (to && to > today) to = today;
    const from = this.from();
    if (from && to && to < from) to = from;
    this.to.set(to);
  }

  onMonthChange(value: string): void {
    const max = this.currentMonth();
    let m = (value || '').trim();
    if (m && m > max) m = max;
    this.month.set(m);
  }

  private monthStart(): string {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`;
  }

  private currentMonth(): string {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
  }

  selectCategory(id: 'all' | ReportCategory): void {
    this.selectedCategory.set(id);
  }

  clearFilters(): void {
    this.searchQuery.set('');
    this.selectedCategory.set('all');
  }

  backToCatalog(): void {
    this.closeView();
    this.mode.set('catalog');
    this.data.set(null);
  }

  openRunner(report: ReportDef): void {
    this.active.set(report);
    this.closeView();
    this.mode.set('runner');
    this.entityValue.set('');
    this.entityOptions.set([]);
    this.entitySearch.set('');
    this.entityMenuOpen.set(false);
    this.resultsSearch.set('');
    if (report.entityFilter) {
      this.loadEntityOptions(report, true);
    } else {
      this.run();
    }
  }

  private loadEntityOptions(report: ReportDef, thenRun: boolean): void {
    const ef = report.entityFilter;
    if (!ef) return;
    this.entityLoading.set(true);
    const params: Record<string, string> = { source: ef.source };
    if (this.isHo() && this.branchId()) params['branch_id'] = String(this.branchId());
    this.reportSvc.get<EntityOpt[]>('filter-options', params).subscribe({
      next: (opts) => {
        const list = Array.isArray(opts) ? opts : [];
        this.entityOptions.set(list);
        this.entityLoading.set(false);
        if (!ef.optional && list.length > 0 && !this.entityValue()) {
          this.entityValue.set(list[0].value);
          this.entitySearch.set(list[0].label);
        } else if (this.entityValue()) {
          const cur = list.find((o) => o.value === this.entityValue());
          if (cur) this.entitySearch.set(cur.label);
        }
        if (thenRun) this.run();
      },
      error: () => {
        this.entityOptions.set([]);
        this.entityLoading.set(false);
        if (thenRun) this.run();
      },
    });
  }

  onEntityFocus(ev: FocusEvent): void {
    this.entityMenuOpen.set(true);
    const el = ev.target as HTMLInputElement | null;
    // Select all so typing replaces the selected label and the full list stays visible until then.
    queueMicrotask(() => el?.select());
  }

  toggleEntityMenu(): void {
    if (this.entityLoading()) return;
    this.entityMenuOpen.update((v) => !v);
  }

  /** Client-side filter of loaded report rows (Retail "Search....." parity). */
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  displayRows(): any[] {
    const rows = (this.data()?.rows as unknown[]) || [];
    const q = this.resultsSearch().trim().toLowerCase();
    if (!q) return rows;
    return rows.filter((row) => {
      if (!row || typeof row !== 'object') return false;
      return Object.values(row as Record<string, unknown>).some((v) =>
        String(v ?? '').toLowerCase().includes(q),
      );
    });
  }

  onEntitySearchType(text: string): void {
    this.entitySearch.set(text ?? '');
    this.entityMenuOpen.set(true);
    const q = (text ?? '').trim().toLowerCase();
    if (!q) {
      this.entityValue.set('');
      return;
    }
    // Only lock value on exact match; partial typing must keep the full filtered list usable.
    const exact = this.entityOptions().find(
      (o) => o.label.toLowerCase() === q || o.value.toLowerCase() === q,
    );
    if (exact) {
      this.entityValue.set(exact.value);
    }
  }

  pickEntity(value: string): void {
    const v = value ?? '';
    this.entityValue.set(v);
    if (!v) {
      this.entitySearch.set('');
    } else {
      const opt = this.entityOptions().find((o) => o.value === v);
      this.entitySearch.set(opt?.label || v);
    }
    this.entityMenuOpen.set(false);
  }

  clearEntity(): void {
    this.entityValue.set('');
    this.entitySearch.set('');
    this.entityMenuOpen.set(true);
  }

  onEntityBlur(): void {
    // Allow mousedown on menu item to fire first.
    setTimeout(() => this.entityMenuOpen.set(false), 180);
    const q = this.entitySearch().trim().toLowerCase();
    if (!q) {
      this.entityValue.set('');
      return;
    }
    const match = this.entityOptions().find(
      (o) => o.label.toLowerCase() === q || o.value.toLowerCase() === q,
    );
    if (match) {
      this.entityValue.set(match.value);
      this.entitySearch.set(match.label);
    } else if (this.entityValue()) {
      // Restore selected label if user typed a partial string then left the field.
      const cur = this.entityOptions().find((o) => o.value === this.entityValue());
      if (cur) this.entitySearch.set(cur.label);
    }
  }

  onEntityChange(value: string): void {
    this.pickEntity(value);
  }

  filteredEntityOptions(): EntityOpt[] {
    const list = this.entityOptions();
    const q = this.entitySearch().trim().toLowerCase();
    if (!q) return list.slice(0, 200);

    // If the box still shows the currently selected label, treat as browsing (show full list).
    const selected = this.entityValue();
    if (selected) {
      const cur = list.find((o) => o.value === selected);
      if (cur && (q === cur.label.toLowerCase() || q === cur.value.toLowerCase())) {
        return list.slice(0, 200);
      }
    }

    return list
      .filter((o) => o.label.toLowerCase().includes(q) || o.value.toLowerCase().includes(q))
      .slice(0, 200);
  }

  selectedEntityIsOpeningStock(): boolean {
    const v = this.entityValue();
    if (!v) return false;
    const opt = this.entityOptions().find((o) => o.value === v);
    return !!(opt?.from_opening || /\(Opening Stock\)/i.test(opt?.label || ''));
  }

  /** Catalog Excel: fetch with defaults and export without opening runner; on failure open runner. */
  downloadExcelFromCatalog(report: ReportDef, event?: Event): void {
    event?.stopPropagation();
    this.catalogLoading.set(true);
    const params = this.buildParams(report, true);
    this.reportSvc.get(report.path, params).subscribe({
      next: async (d) => {
        this.catalogLoading.set(false);
        try {
          await this.exportExcelFor(report, d);
        } catch {
          this.openRunner(report);
        }
      },
      error: () => {
        this.catalogLoading.set(false);
        this.openRunner(report);
      },
    });
  }

  openView(): void {
    if (!this.canView()) return;
    this.viewOpen.set(true);
  }

  closeView(): void {
    this.viewOpen.set(false);
  }

  canView(): boolean {
    return this.canDownloadPdf();
  }

  private buildParams(report: ReportDef, useDefaults = false): Record<string, string> {
    const params: Record<string, string> = {};
    const from = useDefaults ? this.monthStart() : this.from();
    const to = useDefaults ? this.todayLocal() : this.to();
    const month = useDefaults ? this.currentMonth() : this.month();

    if (report.hasMonth) {
      params['month'] = month || this.currentMonth();
    } else if (report.hasDate) {
      if (from) params['from'] = from;
      if (to) params['to'] = to;
    }
    if (this.isHo() && this.branchId()) params['branch_id'] = String(this.branchId());
    if (report.key === 'asof') params['date'] = to || this.todayLocal();
    if (report.hasMonths) params['months'] = String(useDefaults ? 3 : (this.months() || 3));
    if (report.hasMargin) {
      const pct = useDefaults ? 0 : this.marginPct();
      if (pct) params['margin_pct'] = String(pct);
    }
    const ef = report.entityFilter;
    if (ef) {
      const v = (useDefaults ? '' : this.entityValue()).trim();
      if (v) params[ef.param] = v;
    }
    return params;
  }

  run(): void {
    this.loading.set(true);
    this.resultsSearch.set('');
    this.data.set(null);
    const report = this.active();
    this.reportSvc.get(report.path, this.buildParams(report)).subscribe({
      next: (d) => {
        this.data.set(d);
        this.loading.set(false);
        this.flushPendingAction();
      },
      error: () => {
        this.loading.set(false);
        this.pendingAction = null;
      },
    });
  }

  activeColumns(): Column[] {
    return this.columnsFor(this.active());
  }

  columnsFor(report: ReportDef): Column[] {
    if (report.key === 'stock' || report.key === 'stockMfr') {
      return this.stockBatchCols();
    }
    const detail = this.detailColumns[report.key];
    if (detail && this.entityValue().trim()) {
      return detail;
    }
    // API may return detail mode even if FE state lagged
    if (detail && this.data()?.mode === 'detail' && report.key === this.active().key) {
      return detail;
    }
    return this.columns[report.key] ?? [];
  }

  /** Download styled .xlsx via ReportExcelService. */
  async downloadCsv(): Promise<void> {
    await this.exportExcelFor(this.active(), this.data());
  }

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  private async exportExcelFor(report: ReportDef, data: any): Promise<void> {
    const cols = this.columnsFor(report);
    if (!cols.length) return;

    const rows = this.excelRows(report, data);
    const headers = cols.map((c) => c.label);
    const excelRows = rows.map((r) =>
      cols.map((c) => {
        const raw = r[c.key];
        if (c.type === 'currency' || c.type === 'number') {
          const n = Number(raw);
          return raw == null || raw === '' || isNaN(n) ? '' : n;
        }
        if (c.type === 'date' || c.type === 'expiry') return this.cell(r, c);
        return raw == null || raw === '' ? '' : String(raw);
      })
    );

    const footerRaw = this.buildFooterRaw(cols, rows);
    // Skip totals for GST summary (KPI cards only)
    const footer = report.key === 'gst' ? null : footerRaw;
    const firstNumeric = cols.findIndex((c) => c.type === 'currency' || c.type === 'number' || c.align === 'end');
    const stamp = new Date().toISOString().slice(0, 10);
    const safeName = report.label.replace(/\s+/g, '_').replace(/[^a-zA-Z0-9_]/g, '');

    await this.excelSvc.exportWithStyle({
      reportTitle: report.label,
      sheetName: report.label,
      headers,
      rows: excelRows,
      filename: `${safeName || report.path}_${stamp}.xlsx`,
      numericColumnStartIndex: firstNumeric >= 0 ? firstNumeric + 1 : 0,
      footerRow: footer ?? undefined,
      pharmacy: this.pharmacyHeader(report),
    });
  }

  /** Print-ready PDF layout via hidden iframe (Save as PDF in the print dialog). */
  downloadPdf(): void {
    if (!this.canDownloadPdf()) return;
    const html = this.buildPdfHtml();

    document.getElementById('report-pdf-frame')?.remove();

    const iframe = document.createElement('iframe');
    iframe.id = 'report-pdf-frame';
    iframe.setAttribute('title', 'Report PDF');
    iframe.style.cssText =
      'position:fixed;left:-10000px;top:0;width:1100px;height:800px;border:0;';
    document.body.appendChild(iframe);

    const win = iframe.contentWindow;
    const doc = win?.document;
    if (!win || !doc) {
      iframe.remove();
      return;
    }

    doc.open();
    doc.write(html);
    doc.close();

    let printed = false;
    const doPrint = () => {
      if (printed) return;
      printed = true;
      try {
        win.focus();
        win.print();
      } catch {
        /* ignore */
      }
      setTimeout(() => iframe.remove(), 1500);
    };

    setTimeout(doPrint, 350);
  }

  canDownloadCsv(): boolean {
    return this.excelRows(this.active(), this.data()).length > 0;
  }

  canDownloadPdf(): boolean {
    const d = this.data();
    if (!d) return false;
    const key = this.active().key;
    if (key === 'gst') return d.output_gst != null || d.input_gst != null;
    if (key === 'profit') return d.total_profit != null || (d.by_product?.length ?? 0) > 0;
    if (key === 'stock') return (d.rows?.length ?? 0) > 0 || d.total_items != null;
    return (d.rows?.length ?? 0) > 0;
  }

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  private excelRows(report: ReportDef, d: any): any[] {
    if (!d) return [];
    if (report.key === 'gst') {
      return [{
        output_gst: d.output_gst,
        input_gst: d.input_gst,
        net_payable: d.net_payable,
      }];
    }
    if (report.key === 'profit' && Array.isArray(d.by_product)) return d.by_product;
    return d.rows ?? [];
  }

  private buildPdfHtml(): string {
    const title = this.active().label;
    const user = this.auth.user();
    const branchName = this.selectedBranchLabel();
    const period = this.periodLabel();
    const ph = this.pharmacyHeader();
    const addr = this.pharmacyAddressLine();
    const ids = this.pharmacyIdsLine();
    const now = new Date();
    const pad = (n: number) => String(n).padStart(2, '0');
    const generated = `${pad(now.getDate())}-${pad(now.getMonth() + 1)}-${now.getFullYear()} ${pad(now.getHours())}:${pad(now.getMinutes())}`;
    const body = this.pdfBodyHtml();

    return `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>${this.esc(title)} — ${this.esc(ph.businessName)}</title>
  <style>
    @page { size: A4 landscape; margin: 12mm; }
    * { box-sizing: border-box; }
    body {
      font-family: "Segoe UI", Calibri, Arial, sans-serif;
      color: #0f172a;
      font-size: 11px;
      margin: 0;
      padding: 0;
    }
    .pdf-header {
      border-bottom: 2px solid #0f766e;
      padding-bottom: 10px;
      margin-bottom: 12px;
      text-align: center;
    }
    .brand { font-size: 18px; font-weight: 700; color: #0f766e; margin: 0 0 4px; }
    .addr { margin: 2px 0; color: #334155; font-size: 10px; line-height: 1.4; }
    .ids { margin: 4px 0 0; color: #475569; font-size: 10px; }
    .meta-bar {
      display: flex;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 8px 16px;
      margin: 10px 0 12px;
      padding: 8px 10px;
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 6px;
      font-size: 10px;
      color: #475569;
    }
    .meta-bar strong { color: #0f172a; }
    h1 {
      font-size: 14px;
      margin: 0 0 10px;
      font-weight: 700;
      text-align: center;
    }
    .summary {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 10px;
      margin-bottom: 14px;
    }
    .summary .box {
      border: 1px solid #e2e8f0;
      border-radius: 6px;
      padding: 10px 12px;
      text-align: center;
    }
    .summary .label { color: #64748b; font-size: 10px; margin-bottom: 4px; }
    .summary .value { font-size: 14px; font-weight: 700; }
    .totals {
      display: flex;
      flex-wrap: wrap;
      gap: 16px;
      margin-bottom: 10px;
      padding: 8px 10px;
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 6px;
    }
    .totals span { color: #64748b; }
    .totals b { color: #0f172a; }
    table {
      width: 100%;
      border-collapse: collapse;
      table-layout: auto;
    }
    thead { display: table-header-group; }
    tfoot { display: table-row-group; }
    th, td {
      border: 1px solid #cbd5e1;
      padding: 5px 7px;
      vertical-align: middle;
    }
    th {
      background: #f1f5f9;
      font-size: 9px;
      text-transform: uppercase;
      letter-spacing: 0.03em;
      color: #475569;
      font-weight: 700;
      white-space: nowrap;
    }
    td { font-size: 10px; }
    tfoot td {
      font-weight: 700;
      background: #e7eef7;
      border-top: 2px solid #0f766e;
    }
    .text-end { text-align: right; }
    .text-center { text-align: center; }
    .muted { color: #94a3b8; }
    tbody tr:nth-child(even) { background: #f8fafc; }
    .pdf-footer {
      margin-top: 14px;
      padding-top: 8px;
      border-top: 1px solid #e2e8f0;
      font-size: 9px;
      color: #94a3b8;
      display: flex;
      justify-content: space-between;
    }
    @media print {
      body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      .no-print { display: none !important; }
    }
  </style>
</head>
<body>
  <div class="pdf-header">
    <p class="brand">${this.esc(ph.businessName)}</p>
    ${addr ? `<p class="addr">${this.esc(addr)}</p>` : ''}
    ${ids ? `<p class="ids">${this.esc(ids)}</p>` : ''}
  </div>
  <h1>${this.esc(title)}</h1>
  <div class="meta-bar">
    <div>
      ${period ? `<div>Period: <strong>${this.esc(period)}</strong></div>` : ''}
      ${branchName ? `<div>Branch: <strong>${this.esc(branchName)}</strong></div>` : ''}
    </div>
    <div style="text-align:right">
      <div>Generated: <strong>${this.esc(generated)}</strong></div>
      ${user?.name ? `<div>By: <strong>${this.esc(user.name)}</strong></div>` : ''}
    </div>
  </div>
  ${body}
  <div class="pdf-footer">
    <span>${this.esc(ph.businessName)} · Confidential</span>
    <span>${this.esc(generated)}</span>
  </div>
</body>
</html>`;
  }

  private pdfBodyHtml(): string {
    const key = this.active().key;
    const d = this.data();
    if (!d) return '<p class="muted">No data.</p>';

    if (key === 'gst') {
      return `<div class="summary">
        <div class="box"><div class="label">Output GST (Sales)</div><div class="value">${this.money(d.output_gst)}</div></div>
        <div class="box"><div class="label">Input GST (Purchase)</div><div class="value">${this.money(d.input_gst)}</div></div>
        <div class="box"><div class="label">Net Payable</div><div class="value">${this.money(d.net_payable)}</div></div>
      </div>`;
    }

    if (key === 'profit') {
      const cols: Column[] = [
        { key: 'product_name', label: 'Product' },
        { key: 'qty', label: 'Qty', type: 'number', align: 'end' },
        { key: 'revenue', label: 'Revenue', type: 'currency', align: 'end' },
        { key: 'profit', label: 'Profit', type: 'currency', align: 'end' },
      ];
      return `<div class="totals"><span>Total Profit: <b>${this.money(d.total_profit)}</b></span></div>
        ${this.pdfTable(cols, d.by_product ?? [])}`;
    }

    let totals = '';
    if (d.totals) {
      const bits: string[] = [];
      if (d.totals.count != null) bits.push(`<span>Records: <b>${this.esc(d.totals.count)}</b></span>`);
      if (d.totals.items != null) bits.push(`<span>Items: <b>${this.esc(d.totals.items)}</b></span>`);
      if (d.totals.gross != null) bits.push(`<span>Gross: <b>${this.money(d.totals.gross)}</b></span>`);
      if (d.totals.discount != null) bits.push(`<span>Discount: <b>${this.money(d.totals.discount)}</b></span>`);
      if (d.totals.net != null) bits.push(`<span>Net: <b>${this.money(d.totals.net)}</b></span>`);
      if (d.totals.gst != null) bits.push(`<span>GST: <b>${this.money(d.totals.gst)}</b></span>`);
      if (d.totals.profit != null) bits.push(`<span>Profit: <b>${this.money(d.totals.profit)}</b></span>`);
      if (bits.length) totals = `<div class="totals">${bits.join('')}</div>`;
    }
    if (key === 'stock' && d.total_value != null) {
      totals = `<div class="totals">
        <span>Items: <b>${this.esc(d.total_items ?? (d.rows?.length ?? 0))}</b></span>
        <span>Stock Value: <b>${this.money(d.total_value)}</b></span>
      </div>`;
    }

    return totals + this.pdfTable(this.activeColumns(), d.rows ?? []);
  }

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  private pdfTable(cols: Column[], rows: any[]): string {
    if (!cols.length) return '<p class="muted">No columns for this report.</p>';
    if (!rows.length) return '<p class="muted text-center">No records.</p>';

    const head = cols
      .map((c) => `<th class="${c.align === 'end' ? 'text-end' : ''}">${this.esc(c.label)}</th>`)
      .join('');
    const body = rows
      .map((row) => {
        const cells = cols
          .map((c) => {
            const align = c.align === 'end' ? 'text-end' : '';
            return `<td class="${align}">${this.esc(this.cell(row, c))}</td>`;
          })
          .join('');
        return `<tr>${cells}</tr>`;
      })
      .join('');

    const footerRaw = this.buildFooterRaw(cols, rows);
    let foot = '';
    if (footerRaw) {
      const cells = cols
        .map((c, i) => {
          const v = footerRaw[i];
          const align = c.align === 'end' || typeof v === 'number' ? 'text-end' : '';
          let text = '';
          if (v === 'Total') text = 'Total';
          else if (typeof v === 'number') {
            text = c.type === 'currency'
              ? v.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
              : v.toLocaleString('en-IN', { maximumFractionDigits: 2 });
          } else if (v !== '' && v != null) text = String(v);
          return `<td class="${align}">${this.esc(text)}</td>`;
        })
        .join('');
      foot = `<tfoot><tr>${cells}</tr></tfoot>`;
    }

    return `<table><thead><tr>${head}</tr></thead><tbody>${body}</tbody>${foot}</table>`;
  }

  selectedBranchLabel(): string {
    if (!this.isHo()) return '';
    const id = this.branchId();
    if (!id) return 'All Branches';
    const b = this.branches().find((x) => x.id === id);
    return b ? `${b.code} - ${b.name}` : `Branch #${id}`;
  }

  periodLabel(): string {
    return this.periodLabelFor(this.active());
  }

  periodLabelFor(report: ReportDef): string {
    if (report.hasMonth) {
      const m = this.month();
      if (!m) return '';
      const parts = m.split('-');
      if (parts.length === 2) return `${parts[1]}-${parts[0]}`;
      return m;
    }
    if (!report.hasDate) return '';
    const fmt = (iso: string) => {
      const match = iso.trim().match(/^(\d{4})-(\d{2})-(\d{2})/);
      return match ? `${match[3]}-${match[2]}-${match[1]}` : iso;
    };
    if (report.key === 'asof') {
      const d = this.to() || this.todayLocal();
      return `As of ${fmt(d)}`;
    }
    const f = this.from();
    const t = this.to();
    if (f && t) return `${fmt(f)} to ${fmt(t)}`;
    if (f) return `From ${fmt(f)}`;
    if (t) return `Until ${fmt(t)}`;
    return 'All dates';
  }

  categoryLabel(cat: string): string {
    return cat.charAt(0).toUpperCase() + cat.slice(1);
  }

  private money(v: unknown): string {
    const n = Number(v);
    if (isNaN(n)) return '-';
    return n.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  private esc(v: unknown): string {
    return String(v ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  /** Badge tone for audit-style values; everything else stays light. */
  badgeClass(value: unknown): string {
    const v = String(value ?? '');
    if (v === 'Edited') return 'text-bg-warning';
    if (v === 'Added') return 'text-bg-success';
    if (v === 'Removed') return 'text-bg-danger';
    if (v.startsWith('Yes ·')) return 'bg-danger-subtle text-danger-emphasis';
    return 'text-bg-light';
  }

  cell(row: any, col: Column): string {
    const v = row[col.key];
    if (v == null || v === '') return '-';
    if (col.type === 'currency') {
      return Number(v).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    if (col.type === 'currencyRound') {
      return Math.round(Number(v) || 0).toLocaleString('en-IN', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    }
    if (col.type === 'number') {
      const n = Number(v);
      if (isNaN(n)) return String(v);
      const frac = Number.isInteger(n) ? 0 : (String(v).includes('.') && String(v).split('.')[1]?.length === 3 ? 3 : 2);
      return n.toLocaleString('en-IN', { minimumFractionDigits: frac, maximumFractionDigits: Math.max(frac, 4) });
    }
    if (col.type === 'expiry') {
      const raw = String(v).trim();
      if (/^\d{4}-\d{2}$/.test(raw)) return raw;
      if (/^\d{4}-\d{2}-\d{2}/.test(raw)) return raw.slice(0, 7);
      return raw;
    }
    if (col.type === 'date') {
      const raw = String(v).trim();
      const ymd = raw.match(/^(\d{4}-\d{2}-\d{2})/);
      if (ymd) {
        const [y, m, d] = ymd[1].split('-').map(Number);
        return `${String(d).padStart(2, '0')}-${String(m).padStart(2, '0')}-${y}`;
      }
      const parsed = new Date(raw);
      if (isNaN(parsed.getTime())) return raw;
      const dd = String(parsed.getDate()).padStart(2, '0');
      const mm = String(parsed.getMonth() + 1).padStart(2, '0');
      return `${dd}-${mm}-${parsed.getFullYear()}`;
    }
    return String(v);
  }
}
