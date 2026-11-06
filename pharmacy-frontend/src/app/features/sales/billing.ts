import {
  AfterViewInit,
  Component,
  ElementRef,
  HostListener,
  OnDestroy,
  OnInit,
  computed,
  effect,
  inject,
  input,
  signal,
  viewChild,
  WritableSignal,
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import {
  BatchOption,
  MasterProduct,
  PatientRetailHistoryBill,
  PatientRetailHistoryLine,
  PatientHistoryFulfillmentSegment,
  SaleProduct,
  SalePayload,
  SalesService,
  SaleResult,
  Substitute,
} from '../../core/services/sales.service';
import { CrudService } from '../../core/services/crud.service';
import { MetaService } from '../../core/services/meta.service';
import type { MrDoSalePayload } from '../mr/mr';
import {
  emailError,
  addressError,
  reminderDaysError,
  formatReminderDays,
  formatHsn,
  formatPhone,
  phoneError,
  toUpper,
  formatExpiryMonth,
} from '../../core/utils/input-format';
import { PosModalService } from '../../core/services/pos-modal.service';
import { WholesaleCreditNoteApi } from '../../core/services/returns-hub.service';
import { AgtOrder, AgtOrderItem, AgtService } from '../../core/services/agt.service';
import { AgtRxFloater } from '../../shared/agt-rx-floater/agt-rx-floater';
import { MasterPage } from '../masters/master-page';
import { UserRightsService } from '../../core/services/user-rights.service';
import { AuthService } from '../../core/services/auth.service';
import { SalesDraftService } from '../../core/services/sales-draft.service';
import { ReportService } from '../../core/services/report.service';
import { ReportExcelService, ReportPharmacyHeader } from '../../core/services/report-excel.service';
import { ProfileService, ClientProfile } from '../../core/services/profile.service';

type BillMode = 'retail' | 'wholesale';
type RateType = 'mrp' | 'ptr' | 'pts';
type BillingWith = 'Sale Rate' | 'Net Rate';

/** Retail wsales sale scheme — scmPercentage drives free qty = qty × pct / 100. */
const SALE_SCHEME_OPTIONS: { pct: number; label: string }[] = [
  { pct: 0, label: 'NA' },
  { pct: 5, label: '5 + 0.25' },
  { pct: 10, label: '10 + 1' },
  { pct: 11.11, label: '11 + 1' },
  { pct: 20, label: '20 + 4' },
];

interface RateMarginRow {
  category: string;
  ptr_margin?: number;
  pts_margin?: number;
  std_discount?: number;
  rate_by?: 'Rate By Purchase' | 'Rate By Margin' | string;
}

// Schedule X / G / Restricted -> prescription upload mandatory.
const PRESCRIPTION_SCHEDULES = ['X', 'G', 'RESTRICTED'];
// Schedule H1 & X -> blink red alert in the bill.
const ALERT_SCHEDULES = ['H1', 'X'];
const COUNTERS = ['COUNTER 1', 'COUNTER 2', 'COUNTER 3', 'CASH COUNTER', 'IPD COUNTER'];
const CASH_COUNTER = 'CASH COUNTER';
const IPD_COUNTER = 'IPD COUNTER';
/** Cash sale type cannot hold these on Counter 1–3 — bill auto-switches to Regular (NRX maps to X). */
const SCHEDULED_FOR_REGULAR = new Set(['H', 'H1', 'X', 'NRX', 'G', 'RESTRICTED']);
/**
 * On Cash Counter these lines are auto-sent to Counter 1–3 (not billed as walk-in).
 * Schedule H is treated as a normal product on Cash Counter — only H1 / X / G / Restricted.
 */
const CASH_COUNTER_SEND_SCHEDULES = new Set(['H1', 'X', 'NRX', 'G', 'RESTRICTED']);

/** Human meaning of each drug schedule (X = narcotic/psychotropic). */
export const SCHEDULE_MEANING: Record<string, string> = {
  OTC: 'Over the Counter',
  H: 'Schedule H — Prescription drug',
  H1: 'Schedule H1 — Habit-forming / Antibiotic',
  X: 'Schedule X — Narcotic / Psychotropic',
  G: 'Schedule G — Under medical supervision',
  RESTRICTED: 'Restricted — Prescription mandatory',
};

interface BillLine {
  binding_id: number;
  product_name: string;
  mfg: string | null;
  hsn_code: string | null;
  category: string;
  product_category: string;
  gst_rate: number;
  units_per_pack: number;
  allow_loose: boolean;
  pack: string | null;
  shelf: string | null;
  unit_type: 'unit' | 'pack';
  batch_id: number | null;
  batch_no: string | null;
  expiry: string | null;
  unit_mrp: number;     // per unit
  pack_mrp: number;     // per pack — synced from purchase batch
  ptr: number;          // per pack
  pts: number;          // per pack
  rate: number;         // per chosen unit
  qty: number;
  disc: number;
  disc_amt: number;     // line discount ₹ (Retail — synced with disc %)
  cost: number;         // per unit purchase rate (inventory batch)
  unit_net_cost: number; // per unit net cost for profit (Retail unit_net_rate)
  pack_purchase_rate: number; // per pack gross purchase rate (Retail Rate column)
  scheme_pct: number;         // purchase scheme % (Retail sch_per — adjusts cost for profit)
  pur_disc_pct: number;       // purchase discount % (Retail PurDiscPer)
  base_rate: number;          // catalog PTR/PTS before net-rate adjustment (mrp2)
  oth_rate: number;           // alternate PTR/PTS (Retail othRate)
  billing_with: BillingWith;  // Sale Rate vs Net Rate billing
  sale_scheme_pct: number;    // sale scheme % (0 = NA)
  free_qty: number;           // free strips/packs
  total_qty: number;          // qty + free_qty
  net_rate: number;           // effective rate when Billing With = Net Rate
  batches: BatchOption[];
  /** True after user picks a batch from the dropdown — qty must stay on that batch (no FEFO reallocation / MRP swap). */
  batch_locked: boolean;
}

interface PhonePatientOption {
  selectKey: number;
  id: number | null;
  name: string;
  phone?: string;
  address?: string;
  email?: string;
  is_chronic?: number;
  customer_type?: string;
  source: 'master' | 'sales_history';
}

interface Bill {
  mode: BillMode;
  rateType: RateType;
  billDate: string;
  saleType: string;
  paymentMode: string;
  accountId: number | null;
  cashAmount?: number | null;
  customerId: number | null;
  contact: string;
  email: string;
  patientName: string;   // retail patient OR wholesale customer name
  address: string;
  doctor: string;
  narration: string;
  reminderDays: number | null;
  cdDiscPct: number;
  creditNoteNo: string;
  creditDeductionAmt: number;
  /** Retail bill-level discount (₹) on line net total before payment. */
  billDiscountAmt: number;
  isInterstate: boolean;
  isChronic: boolean;
  customerKnown: boolean;   // true when the phone matched an existing patient
  prescriptionId: number | null;
  prescriptionName: string | null;
  lines: BillLine[];
  /** Wholesale entry form — one product at a time (Retail wsales parity). */
  wsDraft: BillLine | null;
  wsDraftEditIndex: number | null;
}

/** Local calendar YYYY-MM-DD (IST-safe). Do not use toISOString — UTC can be yesterday before 5:30 AM. */
function localYmd(d = new Date()): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function emptyBill(mode: BillMode): Bill {
  return {
    mode,
    rateType: 'ptr',
    billDate: localYmd(),
    saleType: mode === 'wholesale' ? 'Regular' : 'Regular',
    paymentMode: 'Cash',
    accountId: null,
    customerId: null,
    contact: '',
    email: '',
    patientName: '',
    address: '',
    doctor: '',
    narration: '',
    reminderDays: null,
    cdDiscPct: 0,
    creditNoteNo: '',
    creditDeductionAmt: 0,
    billDiscountAmt: 0,
    isInterstate: false,
    isChronic: false,
    customerKnown: false,
    prescriptionId: null,
    prescriptionName: null,
    lines: [],
    wsDraft: null,
    wsDraftEditIndex: null,
  };
}

/** Cash Counter is always retail Cash/Counter (walk-in, no patient block). */
function emptyBillForCounter(counter: string, defaultMode: BillMode): Bill {
  if (counter === CASH_COUNTER) {
    return {
      ...emptyBill('retail'),
      rateType: 'mrp',
      saleType: 'Cash',
      patientName: 'Walk-in',
    };
  }
  return emptyBill(defaultMode);
}

interface CashCounterDayRow {
  id: number;
  invoice_no: string;
  invoice_date: string;
  counter: string | null;
  payment_mode: string | null;
  patient_name: string | null;
  item_count: number;
  gross_amount: number;
  discount_amount: number;
  gst_amount: number;
  net_amount: number;
}

interface CashCounterDayReport {
  from: string;
  to: string;
  rows: CashCounterDayRow[];
  totals: {
    count: number;
    items: number;
    gross: number;
    discount: number;
    gst: number;
    net: number;
  };
}

interface QuickStock {
  masterId: number | null;
  name: string;
  batch: string;
  qty: number | null;
  mrp: number;
  rate: number | null;
  gst: number;
  expiry: string;
  // master fields (only used when the product is NOT already in the master)
  manufacturer: string;
  manufacturerShort: string;
  schedule: string;
  category: string;
  hsn: string;
  packSize: string;
  unitsPerPack: number;
}

/** Common strip / bottle packs for the quick stock-in dropdown (editable). */
const QUICK_PACK_OPTIONS = [
  '1x1', '1x2', '1x3', '1x4', '1x5', '1x6', '1x8', '1x10', '1x12', '1x14', '1x15',
  '1x16', '1x20', '1x21', '1x24', '1x28', '1x30', '1x50', '1x60', '1x100',
  '10 ML', '15 ML', '20 ML', '30 ML', '50 ML', '60 ML', '100 ML', '200 ML',
  '10 GM', '15 GM', '20 GM', '25 GM', '30 GM', '50 GM', '100 GM',
];

function emptyQuick(): QuickStock {
  return {
    masterId: null, name: '', batch: '', qty: null, mrp: 0, rate: null, gst: 12, expiry: '',
    manufacturer: '', manufacturerShort: '', schedule: 'OTC', category: 'Allopathy',
    hsn: '', packSize: '', unitsPerPack: 1,
  };
}

@Component({
  selector: 'app-billing',
  imports: [CommonModule, FormsModule, AgtRxFloater, MasterPage],
  templateUrl: './billing.html',
  styleUrl: './billing.scss',
  host: {
    '[class.pos-overlay-mode]': 'posOverlay()',
  },
})
export class Billing implements OnInit, AfterViewInit, OnDestroy {
  /** True when embedded in the dashboard full-screen POS popup. */
  readonly posOverlay = input(false);

  /** Unique Esc-lock owner so nested popups never close the POS to dashboard. */
  private readonly escapeOwnerId = `billing-${Math.random().toString(36).slice(2)}`;

  private readonly host = inject(ElementRef<HTMLElement>);
  private readonly salesSvc = inject(SalesService);
  private readonly crud = inject(CrudService);
  private readonly meta = inject(MetaService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly posModal = inject(PosModalService);
  private readonly creditNoteApi = inject(WholesaleCreditNoteApi);
  private readonly agtSvc = inject(AgtService);
  private readonly rights = inject(UserRightsService);
  private readonly auth = inject(AuthService);
  private readonly reportSvc = inject(ReportService);
  private readonly excelSvc = inject(ReportExcelService);
  private readonly profileSvc = inject(ProfileService);
  private readonly video = viewChild<ElementRef<HTMLVideoElement>>('video');
  private readonly wsModalPanel = viewChild<ElementRef<HTMLElement>>('wsModalPanel');
  private readonly wsModalRateInput = viewChild<ElementRef<HTMLInputElement>>('wsModalRateInput');

  /** Full saved-bill edit: HO Admin or Sales Edit right. */
  readonly canEditSavedBill = () => this.rights.hasFullAccess();

  readonly openCreditNotes = signal<{ note_no: string; label: string; amount: number }[]>([]);

  readonly businessType = this.meta.businessType;
  private readonly draftStore = inject(SalesDraftService);
  /** Shared across visits — leaving New Sales does not drop open counter bills. */
  readonly counters = this.draftStore.counters;
  readonly activeCounter = this.draftStore.activeCounter;
  readonly bills = this.draftStore.bills as WritableSignal<Record<string, Bill>>;

  // IPD link (set when billing was opened from an admission)
  readonly ipdAdmissionId = signal<number | null>(null);
  readonly linkedAgtOrderId = signal<number | null>(null);
  /** MR order being billed via MR → Do Sale; marked Sold by the API when this bill saves. */
  readonly linkedMrOrderId = signal<number | null>(null);
  readonly linkedMrOrderNo = signal<string>('');
  readonly linkedMrCounter = signal<string>('');
  private pendingMrDoSale: MrDoSalePayload | null = null;
  /** AGT patient Rx shown on billing after Do Sale / add to bill (Retail parity). */
  readonly agtLinkedRx = signal<{ orderNo: string; prescriptionUrl: string } | null>(null);
  readonly agtRxFloaterVisible = signal(true);
  readonly agtOrderLoading = signal(false);
  readonly agtPendingOrders = signal<AgtOrder[]>([]);
  readonly agtSelectedOrder = signal<AgtOrder | null>(null);
  readonly agtModalOpen = signal(false);
  readonly patientHistoryLoading = signal(false);
  readonly patientHistoryBills = signal<PatientRetailHistoryBill[]>([]);
  readonly patientHistoryError = signal<string | null>(null);
  readonly patientHistoryExpanded = signal<string | null>(null);
  readonly patientHistoryModalOpen = signal(false);
  private patientHistoryAutoKey = '';
  private patientHistoryFetchTimer?: ReturnType<typeof setTimeout>;
  readonly ipdPatient = signal<string>('');
  readonly bill = computed(() => this.bills()[this.activeCounter()] ?? emptyBillForCounter(this.activeCounter(), 'retail'));
  readonly isCashCounterActive = computed(() => this.isCashCounter(this.activeCounter()));
  readonly isWholesale = computed(() => {
    if (this.isCashCounterActive()) return false;
    return this.bill().mode === 'wholesale';
  });
  readonly wsDraft = computed(() => this.bill().wsDraft);
  readonly wsDraftEditIndex = computed(() => this.bill().wsDraftEditIndex);
  readonly wsDraftIsEdit = computed(() => this.wsDraftEditIndex() !== null);

  /** Draggable wholesale line-entry popup offset (px from center). */
  readonly wsModalOffset = signal({ x: 0, y: 0 });
  private wsModalDragActive = false;
  private wsModalDragStart = { px: 0, py: 0, ox: 0, oy: 0 };
  /** One-shot autofocus when popup opens (never on draft field edits). */
  private wsModalShouldAutofocus = false;
  /** Cash Counter never shows patient details. Other counters follow Regular vs Cash sale type. */
  readonly isRegularSale = computed(() => {
    if (this.isCashCounterActive()) return false;
    if (this.isWholesale()) return true;
    const t = (this.bill().saleType || 'Regular').toLowerCase();
    return t !== 'cash';
  });
  /** Save & WhatsApp on wholesale and on Regular retail (not Cash/Counter). */
  readonly showSaveWhatsapp = computed(() => {
    if (this.isWholesale()) return true;
    return (this.bill().saleType || 'Regular').toLowerCase() !== 'cash';
  });

  onSaleTypeChange(value: string): void {
    if ((value || '').toLowerCase() === 'cash' && this.billHasScheduledDrug()) {
      this.set('saleType', 'Regular');
      this.error.set(
        'This bill has scheduled drugs (H / H1 / X / Restricted). Sale type must stay Regular — patient details are required.',
      );
      // Re-apply after Angular finishes the select's own ngModel write.
      setTimeout(() => this.set('saleType', 'Regular'), 0);
      return;
    }
    this.billInfo.set(null);
    this.set('saleType', value);
    if ((value || '').toLowerCase() === 'cash') {
      this.addingPatient.set(false);
      this.patchBill({
        customerId: null,
        patientName: 'Walk-in',
        contact: '',
        email: '',
        address: '',
        doctor: '',
        narration: '',
        reminderDays: null,
        isChronic: false,
        customerKnown: false,
      });
    } else if (!this.bill().patientName || this.bill().patientName === 'Walk-in') {
      this.patchBill({ patientName: '' });
    }
  }

  onPaymentModeChange(value: string): void {
    this.set('paymentMode', value);
    if (value !== 'Card') this.set('accountId', null);
    if (value !== 'Cash+UPI') this.set('cashAmount', null);
  }

  // search / add
  readonly searchTerm = signal('');
  readonly searchResults = signal<SaleProduct[]>([]);
  readonly highlight = signal(0);
  readonly searching = signal(false);
  private searchTimer?: ReturnType<typeof setTimeout>;
  readonly generics = signal<{ id: number; name: string }[]>([]);
  readonly selectedGeneric = signal<number | null>(null);
  readonly substituteMode = signal(false); // when true, search lists same-molecule alternatives
  readonly genericSearch = signal('');
  readonly showGenericDropdown = signal(false);
  readonly genericActiveIndex = signal(0);
  readonly filteredGenerics = computed(() => {
    const q = this.genericSearch().trim().toLowerCase();
    const list = this.generics();
    if (!q) return list.slice(0, 80);
    return list.filter((g) => g.name.toLowerCase().includes(q)).slice(0, 80);
  });
  readonly customers = signal<{
    id: number;
    name: string;
    phone?: string;
    address?: string;
    email?: string;
    is_chronic?: number;
    customer_type?: string;
  }[]>([]);
  /** Sentinel option value for "+ New patient/customer…" in the bill dropdown. */
  readonly NEW_PATIENT = -1;
  readonly addingPatient = signal(false);
  readonly retailCustomers = computed(() =>
    this.customers().filter((c) => (c.customer_type ?? 'retail') !== 'wholesale'),
  );
  /** Patients sharing the active 10-digit mobile (from last phone lookup). */
  readonly patientsOnPhone = signal<PhonePatientOption[]>([]);
  readonly showPatientDropdown = signal(false);
  /** Open Cash Counter “Send to” menu for this line index. */
  readonly sendMenuIndex = signal<number | null>(null);
  readonly sendMenuPos = signal<{ top: number; left: number; width: number } | null>(null);
  /** Stable identity of the row whose Send menu is open (avoids wrong product after list reorder). */
  private sendMenuLineKey: string | null = null;
  /** Collapse CUSTOMER & BILL DETAILS to free vertical space on the bill. */
  readonly customerDetailsHidden = signal(
    typeof localStorage !== 'undefined' && localStorage.getItem('billing.customerDetailsHidden') === '1',
  );

  toggleCustomerDetails(): void {
    this.customerDetailsHidden.update((v) => {
      const next = !v;
      try {
        localStorage.setItem('billing.customerDetailsHidden', next ? '1' : '0');
      } catch {
        /* ignore */
      }
      return next;
    });
  }
  /** Cash Counter: S activates product-row multi-select; arrows move, Enter toggles. */
  readonly cashSelectMode = signal(false);
  readonly cashSelectFocus = signal(0);
  /** Selected line indices on the Cash Counter bill grid. */
  readonly cashSelectedIndexes = signal<ReadonlySet<number>>(new Set());
  readonly patientSearchQuery = signal('');
  /** Highlighted row while moving through the patient list with the arrow keys. */
  readonly patientActiveIndex = signal(0);
  /** Dropdown value while a phone lookup list is active (master id or negative history key). */
  readonly phonePatientSelectKey = signal<number | null>(null);
  readonly multiplePatientsOnPhone = computed(() => {
    const contact = formatPhone(this.bill().contact);
    return contact.length === 10 && this.patientsOnPhone().length > 1;
  });
  /** Patients for dropdown when contact is a full 10-digit mobile (small list only). */
  readonly retailPatientsForPhone = computed((): PhonePatientOption[] => {
    const b = this.bill();
    const contact = formatPhone(b.contact);
    if (contact.length !== 10) {
      return [];
    }

    const onPhone = this.patientsOnPhone();
    if (onPhone.length > 0) {
      return onPhone;
    }

    const byPhone = this.dedupePatientOptions(
      this.customers()
        .filter((c) => this.customerMatchesPhone(c, contact))
        .map((c, index) => this.mapPhoneLookupRow(
          { ...c, is_chronic: c.is_chronic, lookup_source: 'master' },
          index,
          contact,
        )),
    );
    if (byPhone.length > 0) {
      return byPhone;
    }

    const matched = this.matchedBillPatient(b);
    if (matched) {
      return [matched];
    }

    return [];
  });

  readonly patientPhoneListActive = computed(
    () => formatPhone(this.bill().contact).length === 10,
  );

  /** Max rows rendered — type-to-search when not on a fixed phone list. */
  readonly filteredRetailPatientsForSelect = computed((): PhonePatientOption[] => {
    const q = this.patientSearchQuery().trim().toLowerCase();
    const qDigits = q.replace(/\D/g, '');

    if (this.patientPhoneListActive()) {
      const list = this.retailPatientsForPhone();
      if (!q) {
        return list;
      }
      return list.filter((p) => this.patientOptionMatchesQuery(p, q, qDigits));
    }

    if (q.length < 2) {
      return [];
    }

    const hits: PhonePatientOption[] = [];
    for (const c of this.retailCustomers()) {
      if (hits.length >= 40) {
        break;
      }
      const name = c.name.toLowerCase();
      const phone = formatPhone(String(c.phone ?? ''));
      if (!name.includes(q) && !(qDigits.length >= 2 && phone.includes(qDigits))) {
        continue;
      }
      hits.push(this.masterPatientOption(c));
    }
    return hits;
  });

  readonly patientDropdownKey = computed((): number | null => {
    if (this.addingPatient()) {
      return this.NEW_PATIENT;
    }
    if (this.patientPhoneListActive() && this.patientsOnPhone().length > 0) {
      return this.phonePatientSelectKey();
    }
    return this.bill().customerId;
  });
  readonly patientComboDisplayLabel = computed(() => {
    const name = this.bill().patientName.trim();
    if (name) {
      return name;
    }
    const id = this.bill().customerId;
    if (id != null) {
      const c = this.customers().find((x) => x.id === id);
      if (c?.name) {
        return c.name;
      }
    }
    return '';
  });
  readonly wholesaleCustomers = computed(() =>
    this.customers().filter((c) => (c.customer_type ?? '') === 'wholesale'),
  );
  /** Customer Master create popup (Type = Wholesale) opened from the wholesale bill. */
  readonly wholesaleCustomerPopup = signal(false);
  readonly wholesaleCustomerPreset = { customer_type: 'wholesale', medical_type: 'Retail' };
  readonly wholesaleCustomerLock = ['customer_type'];
  readonly doctors = signal<{ id: number; name: string }[]>([]);
  readonly showDoctorDropdown = signal(false);
  readonly doctorActiveIndex = signal(-1);
  readonly filteredDoctors = computed(() => {
    const q = String(this.bill().doctor ?? '').trim().toLowerCase();
    const list = this.doctors();
    return (q ? list.filter((d) => d.name.toLowerCase().includes(q)) : list).slice(0, 40);
  });
  private doctorBlurTimer?: ReturnType<typeof setTimeout>;
  readonly lookingUp = signal(false);

  // substitute lookup for an existing line
  readonly subOpen = signal(false);
  readonly subLoading = signal(false);
  readonly subs = signal<Substitute[]>([]);
  readonly subSourceName = signal('');
  private subReplaceIndex: number | null = null;

  readonly saving = signal(false);
  readonly error = signal<string | null>(null);
  readonly billInfo = signal<string | null>(null);
  readonly completed = signal<SaleResult | null>(null);
  readonly cashDayOpen = signal(false);
  readonly cashDayDate = signal(this.todayLocalDate());
  readonly cashDayLoading = signal(false);
  readonly cashDayError = signal<string | null>(null);
  readonly cashDay = signal<CashCounterDayReport | null>(null);
  readonly cashDayExporting = signal(false);
  private pharmacyProfile: ClientProfile | null = null;
  readonly whatsappSending = signal(false);
  readonly whatsappMsg = signal<string | null>(null);

  // camera
  readonly cameraOpen = signal(false);
  private stream?: MediaStream;
  readonly uploadingPrescription = signal(false);

  readonly paymentModes = ['Cash', 'Card', 'UPI', 'Credit'];
  readonly retailPaymentModes = [...this.paymentModes, 'Cash+UPI'];
  /** Retail Sale Type — matches Retail ERP (Cash/Counter hides patient block). */
  readonly saleTypes = [
    { value: 'Cash', label: 'Cash/Counter' },
    { value: 'Regular', label: 'Regular' },
    { value: 'Return', label: 'Return' },
  ];
  readonly bankAccounts = signal<{ id: number; name: string; account_no?: string; bank_name?: string; type?: string }[]>([]);
  readonly scheduleCodes = ['OTC', 'H', 'H1', 'X', 'G', 'RESTRICTED'];
  readonly productCategories = ['Allopathy', 'Ayurvedic', 'Generic', 'Surgical', 'Cosmetic'];
  readonly rateTypes: { value: RateType; label: string }[] = [
    { value: 'ptr', label: 'PTR' }, { value: 'pts', label: 'PTS' }, { value: 'mrp', label: 'MRP' },
  ];
  readonly saleSchemeOptions = SALE_SCHEME_OPTIONS;
  readonly billingWithOptions: BillingWith[] = ['Sale Rate', 'Net Rate'];

  /** Retail wholesale bills PTR/PTS only (no MRP rate type). */
  readonly wholesaleRateTypes: { value: RateType; label: string }[] = [
    { value: 'ptr', label: 'PTR' }, { value: 'pts', label: 'PTS' },
  ];
  readonly prevCustomerRates = signal<Array<{
    sale_rate: number;
    discount_pct: number;
    batch_no: string | null;
    invoice_no: string;
    invoice_date: string;
    payment_mode: string | null;
  }>>([]);
  readonly prevRatesLineIndex = signal<number | null>(null);
  /** Rate margin master — Retail retailMargineTable / rate_by parity for wholesale PTR/PTS. */
  readonly rateMargins = signal<RateMarginRow[]>([]);

  constructor() {
    if (this.counters().length === 0 || Object.keys(this.bills()).length === 0) {
      this.counters.set([...COUNTERS]);
      this.activeCounter.set('COUNTER 1');
      this.bills.set(Object.fromEntries(COUNTERS.map((c) => [c, emptyBillForCounter(c, 'retail')])));
    }
    // Drafts persist overnight in localStorage — a stale Bill Date (e.g. yesterday)
    // was saved when billing a returning patient on an old counter. Snap past dates to today.
    this.refreshStaleDraftBillDates();
    this.forceCashCounterWalkIn();
    this.pendingMrDoSale = this.takeMrDoSalePayload();
    // Keep POS Esc from dismissing the whole sale while any nested popup is open.
    effect(() => {
      this.posModal.setEscapeBlocked(this.escapeOwnerId, this.hasSalesOverlayOpen());
    });
    this.meta.loadProfile().subscribe((p) => {
      // Wholesale-only client: default every counter to wholesale.
      if (p.business_type === 'wholesale') {
        this.bills.update((all) =>
          Object.fromEntries(Object.entries(all).map(([k, b]) =>
            k === CASH_COUNTER
              ? [k, b.lines.length ? b : emptyBillForCounter(CASH_COUNTER, 'retail')]
              : [k, { ...b, mode: 'wholesale' as BillMode, rateType: b.rateType === 'mrp' ? 'ptr' : b.rateType }],
          )),
        );
      }
      if (p.business_type === 'wholesale' || p.business_type === 'both') {
        this.loadWholesaleDefaults();
      }
      const mr = this.pendingMrDoSale;
      this.pendingMrDoSale = null;
      if (mr) this.applyMrDoSale(mr, p.business_type);
    });
    this.crud.list<{ id: number; name: string }>('generics', { perPage: 5000 }).subscribe((r) => this.generics.set(r.data ?? []));
    this.crud.list<{ id: number; name: string; phone?: string; address?: string; email?: string; is_chronic?: number; customer_type?: string }>('customers', { perPage: 500 })
      .subscribe((r) => this.customers.set(r.data));
    this.crud.list<{ id: number; name: string }>('doctors', { perPage: 500 }).subscribe((r) => this.doctors.set(r.data));
    this.crud.list<{ id: number; name: string; account_no?: string; bank_name?: string; type?: string }>('accounts', { perPage: 200 })
      .subscribe((r) => this.bankAccounts.set(r.data.filter((a) => (a.type ?? '') === 'bank' || !!(a.account_no || a.bank_name))));
    this.loadInitial();

    // If opened from an IPD admission, tag this bill to it.
    const admission = this.route.snapshot.queryParamMap.get('admission');
    if (admission) {
      this.ipdAdmissionId.set(Number(admission));
      const patient = this.route.snapshot.queryParamMap.get('patient') ?? '';
      this.ipdPatient.set(patient);
      this.ensureCounter('IPD COUNTER');
      this.setActiveCounter('IPD COUNTER');
      this.patchBill({ patientName: patient });
    }

    this.applyAgtDoSaleDeepLink();
  }

  openEditSavedBill(id?: number): void {
    const saleId = id ?? this.completed()?.id;
    if (!saleId || !this.canEditSavedBill()) {
      return;
    }
    void this.router.navigate(['/app/sales', saleId, 'edit']);
  }

  canUseAgt(): boolean {
    if (this.rights.hasFullAccess()) return true;
    if (!this.rights.isReady()) return false;
    return this.rights.canView('AURA_GHAR_TAK') || this.rights.canEdit('AURA_GHAR_TAK');
  }

  /** AuraGharTak → Do Sale deep link (one-shot sessionStorage / query params). */
  private applyAgtDoSaleDeepLink(): void {
    if (!this.canUseAgt()) return;
    let payload: {
      order_id?: number;
      patient_no?: string;
      patient_name?: string;
      patient_contact?: string;
      patient_address?: string;
    } | null = null;
    try {
      const raw = sessionStorage.getItem('agt_do_sale');
      if (raw) {
        payload = JSON.parse(raw) as typeof payload;
        sessionStorage.removeItem('agt_do_sale');
      }
    } catch {
      try {
        sessionStorage.removeItem('agt_do_sale');
      } catch {
        /* ignore */
      }
    }
    if (!payload) {
      const oid = Number(this.route.snapshot.queryParamMap.get('agt_order_id') || 0);
      const contact = this.route.snapshot.queryParamMap.get('patient_contact') ?? '';
      if (!oid && !contact) return;
      payload = {
        order_id: oid,
        patient_no: this.route.snapshot.queryParamMap.get('patient_no') ?? '',
        patient_name: this.route.snapshot.queryParamMap.get('patient_name') ?? '',
        patient_contact: contact,
        patient_address: '',
      };
    }
    if (!payload?.order_id && !payload?.patient_contact && !payload?.patient_name) return;

    if (payload.patient_name) {
      this.patchBill({ patientName: payload.patient_name.toUpperCase(), contact: payload.patient_contact ?? '' });
    }
    if (payload.patient_address) {
      this.patchBill({ address: payload.patient_address });
    }
    if (payload.patient_no) {
      const id = Number(payload.patient_no);
      if (id > 0) this.patchBill({ customerId: id });
    }

    void this.router.navigate([], { relativeTo: this.route, queryParams: {}, replaceUrl: true });
    this.openAgtOrderForDoSale(Number(payload.order_id) || 0, payload.patient_no ?? '', payload.patient_contact ?? '');
  }

  /** MR → Do Sale handoff (one-shot sessionStorage written by the MR page). */
  private takeMrDoSalePayload(): MrDoSalePayload | null {
    let payload: MrDoSalePayload | null = null;
    try {
      const raw = sessionStorage.getItem('mr_do_sale');
      if (raw) payload = JSON.parse(raw) as MrDoSalePayload;
      sessionStorage.removeItem('mr_do_sale');
    } catch {
      payload = null;
    }
    if (!payload?.order_id) return null;
    const allowed = this.rights.hasFullAccess()
      || (this.rights.canView('SALES') && (this.rights.canView('MR') || this.rights.canEdit('MR')));
    return allowed ? payload : null;
  }

  private applyMrDoSale(payload: MrDoSalePayload, businessType: string | undefined): void {
    if (businessType === 'retail') {
      this.error.set('MR orders are billed as Wholesale sales — wholesale billing is not enabled for this client.');
      return;
    }
    if (this.isCashCounter()) {
      const counter = this.counters().find((c) => !this.isCashCounter(c)) ?? 'COUNTER 1';
      this.ensureCounter(counter);
      this.setActiveCounter(counter);
    }
    this.loadWholesaleDefaults();
    const rateType = this.bill().rateType === 'mrp' ? 'ptr' : this.bill().rateType;
    this.patchBill({
      mode: 'wholesale',
      rateType,
      lines: [],
      customerId: payload.customer_id && payload.customer_id > 0 ? payload.customer_id : null,
      patientName: (payload.customer_name || '').toUpperCase(),
      contact: payload.customer_contact || '',
      address: payload.customer_address || '',
      customerKnown: !!(payload.customer_id && payload.customer_id > 0),
    });
    if (payload.customer_name) this.loadOpenCreditNotes(payload.customer_name);
    this.linkedMrOrderId.set(payload.order_id);
    this.linkedMrOrderNo.set(payload.order_no || '');
    this.linkedMrCounter.set(this.activeCounter());

    const lines = (payload.lines ?? []).filter((l) => l.binding_id > 0 && l.qty > 0);
    const skipped: string[] = [];
    const next = (i: number): void => {
      if (i >= lines.length) {
        if (skipped.length) {
          this.error.set(`MR order ${payload.order_no}: no sellable stock for ${skipped.join(', ')}.`);
        }
        return;
      }
      const l = lines[i];
      const stub: SaleProduct = {
        binding_id: l.binding_id,
        product_name: l.product_name,
        manufacturer_short: l.m_name ?? null,
        schedule_category: 'OTC',
        gst_rate: Number(l.gst) || 0,
        hsn_code: l.hsn ?? null,
        pack_size: l.pack_size ?? null,
        master_product_id: 0,
        available_qty: Math.floor(l.qty),
        mrp: null,
        units_per_pack: 1,
        allow_loose: 0,
      };
      this.salesSvc.searchProducts(l.product_name).subscribe({
        next: (found) => this.addMrLine(found.find((p) => p.binding_id === l.binding_id) ?? stub, l, skipped, () => next(i + 1)),
        error: () => this.addMrLine(stub, l, skipped, () => next(i + 1)),
      });
    };
    next(0);
  }

  clearLinkedMrOrder(): void {
    this.linkedMrOrderId.set(null);
    this.linkedMrOrderNo.set('');
    this.linkedMrCounter.set('');
  }

  private addMrLine(
    product: SaleProduct,
    l: MrDoSalePayload['lines'][number],
    skipped: string[],
    done: () => void,
  ): void {
    this.salesSvc.batches(l.binding_id).subscribe({
      next: (batches) => {
        const prevError = this.error();
        let line = this.buildBillLineFromProduct(product, batches, l.batch_id);
        if (line) {
          line = { ...line, unit_type: 'pack', qty: l.qty };
          line = this.repriceLine(line, 'wholesale', this.bill().rateType);
          const rate = this.round2(Number(l.rate) || 0);
          if (rate > 0) line = this.recalcWholesaleLine({ ...line, rate, base_rate: rate });
          this.patchBill({ lines: [...this.bill().lines, line] });
        } else {
          this.error.set(prevError);
          skipped.push(l.product_name);
        }
        done();
      },
      error: () => {
        skipped.push(l.product_name);
        done();
      },
    });
  }

  private openAgtOrderForDoSale(orderId: number, patientNo: string, contact: string): void {
    this.agtOrderLoading.set(true);
    this.agtSvc.pendingByPatient(patientNo, contact).subscribe({
      next: (orders) => {
        this.agtOrderLoading.set(false);
        this.agtPendingOrders.set(orders);
        let selected = orderId > 0 ? orders.find((o) => o.id === orderId) ?? null : null;
        if (!selected && orders.length) selected = orders[0];
        if (selected) {
          this.agtSelectedOrder.set(selected);
          this.syncAgtLinkedRxFromOrder(selected);
          this.agtModalOpen.set(true);
        } else if (orderId > 0) {
          this.agtSvc.getOrder(orderId).subscribe((o) => {
            if (o) {
              this.agtPendingOrders.set([o]);
              this.agtSelectedOrder.set(o);
              this.syncAgtLinkedRxFromOrder(o);
              this.agtModalOpen.set(true);
            }
          });
        }
      },
      error: () => this.agtOrderLoading.set(false),
    });
  }

  closeAgtModal(): void {
    this.agtModalOpen.set(false);
  }

  openAgtPendingOrders(): void {
    if (!this.agtPendingOrders().length) {
      this.refreshAgtPendingForBill(false);
    }
    if (this.agtPendingOrders().length && !this.agtSelectedOrder()) {
      this.agtSelectedOrder.set(this.agtPendingOrders()[0]);
    }
    this.agtModalOpen.set(true);
  }

  private refreshAgtPendingForBill(autoOpen: boolean): void {
    if (!this.canUseAgt() || this.isWholesale()) return;
    const b = this.bill();
    const contact = formatPhone(b.contact);
    if (contact.length < 10 && !b.customerId) {
      this.agtPendingOrders.set([]);
      return;
    }
    const patientNo = b.customerId ? String(b.customerId) : '';
    this.agtOrderLoading.set(true);
    this.agtSvc.pendingByPatient(patientNo, contact).subscribe({
      next: (orders) => {
        this.agtOrderLoading.set(false);
        this.agtPendingOrders.set(orders);
        if (autoOpen && orders.length) {
          this.agtSelectedOrder.set(orders[0]);
          this.agtModalOpen.set(true);
        }
      },
      error: () => this.agtOrderLoading.set(false),
    });
  }

  agtPrescriptionIsPdf(url: string): boolean {
    return String(url).toLowerCase().includes('.pdf');
  }

  /** AGT order still open for billing (Placed, not yet linked to a sale). */
  agtOrderOpenForSale(order: AgtOrder | null | undefined): boolean {
    if (!order) return false;
    if (order.status !== 'Placed') return false;
    const sid = Number(order.sale_id ?? 0);
    return !(sid > 0);
  }

  private linkAgtOrderForSale(order: AgtOrder): void {
    if (this.agtOrderOpenForSale(order)) {
      this.linkedAgtOrderId.set(order.id);
    } else {
      this.linkedAgtOrderId.set(null);
    }
  }

  private syncAgtLinkedRxFromOrder(order: AgtOrder): void {
    this.linkAgtOrderForSale(order);
    if (order.prescription_url) {
      this.agtLinkedRx.set({ orderNo: order.order_no, prescriptionUrl: order.prescription_url });
      this.agtRxFloaterVisible.set(true);
    }
  }

  hideAgtRxFloater(): void {
    this.agtRxFloaterVisible.set(false);
  }

  showAgtRxFloater(): void {
    if (this.agtLinkedRx()) this.agtRxFloaterVisible.set(true);
  }

  selectAgtPendingOrder(o: AgtOrder): void {
    this.agtSelectedOrder.set(o);
    this.syncAgtLinkedRxFromOrder(o);
  }

  addAgtOrderToBill(): void {
    const order = this.agtSelectedOrder();
    if (!order?.items?.length) return;
    if (!this.agtOrderOpenForSale(order)) {
      this.error.set(
        order.sale_invoice_no
          ? `Order ${order.order_no} is already billed (${order.sale_invoice_no}).`
          : `Order ${order.order_no} cannot be billed again (status: ${order.status}).`,
      );
      return;
    }
    const addable = order.items.filter((it) => (it.fulfillment_segments?.length ?? 0) > 0);
    if (!addable.length) {
      this.error.set('All items are out of stock for this order.');
      return;
    }
    if (!confirm(`Add AuraGharTak order ${order.order_no} to this bill? (${addable.length} item(s))`)) return;

    const segments: Array<{ item: AgtOrderItem; seg: NonNullable<AgtOrderItem['fulfillment_segments']>[number] }> = [];
    for (const it of addable) {
      for (const seg of it.fulfillment_segments ?? []) {
        if (seg.binding_id && seg.batch_id && seg.qty > 0) {
          segments.push({ item: it, seg });
        }
      }
    }
    this.appendAgtSegmentLines(segments, 0, () => {
      this.linkedAgtOrderId.set(order.id);
      if (order.prescription_url) {
        this.agtLinkedRx.set({ orderNo: order.order_no, prescriptionUrl: order.prescription_url });
      }
      this.patchBill({
        patientName: (order.patient_name || this.bill().patientName).toUpperCase(),
        contact: order.patient_contact || this.bill().contact,
        address: order.patient_address || this.bill().address,
      });
      this.closeAgtModal();
    });
  }

  private appendAgtSegmentLines(
    segments: Array<{ item: AgtOrderItem; seg: NonNullable<AgtOrderItem['fulfillment_segments']>[number] }>,
    index: number,
    done: () => void,
  ): void {
    if (index >= segments.length) {
      done();
      return;
    }
    const { item, seg } = segments[index];
    const stub: SaleProduct = {
      binding_id: seg.binding_id,
      product_name: String(item.product_name ?? item.p_name ?? ''),
      schedule_category: 'OTC',
      gst_rate: 0,
      master_product_id: 0,
      available_qty: Math.floor(seg.qty),
      mrp: seg.pack_mrp ?? seg.unit_mrp ?? null,
      units_per_pack: 1,
      allow_loose: 0,
    };
    this.salesSvc.batches(seg.binding_id).subscribe((batches) => {
      let line = this.buildBillLineFromProduct(stub, batches, seg.batch_id);
      if (line) {
        line = { ...line, unit_type: 'pack', qty: seg.qty };
        line = this.repriceLine(line, this.bill().mode, this.bill().rateType);
        this.patchBill({ lines: [...this.bill().lines, line] });
      }
      this.appendAgtSegmentLines(segments, index + 1, done);
    });
  }

  private patientHistoryLookupKey(): string {
    const id = this.bill().customerId;
    if (id != null && id > 0) return String(id);
    return formatPhone(this.bill().contact);
  }

  canShowPatientHistory(): boolean {
    return this.isRegularSale() && !this.isWholesale();
  }

  openPatientPurchaseHistory(): void {
    if (!this.canShowPatientHistory()) return;
    const contact = formatPhone(this.bill().contact);
    const key = this.patientHistoryLookupKey();
    if (!key || (!this.bill().customerId && contact.length < 10)) {
      alert('Enter a valid 10-digit contact or select the patient from the list first.');
      return;
    }
    this.patientHistoryAutoKey = '';
    this.patientHistoryError.set(null);
    this.patientHistoryModalOpen.set(true);
    this.fetchPatientPurchaseHistory(false, true);
  }

  private schedulePatientPurchaseHistoryFetch(autoOpen: boolean): void {
    if (!this.canShowPatientHistory()) return;
    clearTimeout(this.patientHistoryFetchTimer);
    this.patientHistoryFetchTimer = setTimeout(() => {
      this.fetchPatientPurchaseHistory(autoOpen, false);
    }, 400);
  }

  private fetchPatientPurchaseHistory(autoOpen: boolean, forceOpen: boolean): void {
    if (!this.canShowPatientHistory()) return;
    const key = this.patientHistoryLookupKey();
    const contact = formatPhone(this.bill().contact);
    if (!key || (!this.bill().customerId && contact.length < 10)) return;

    const patientNo = this.bill().customerId ? String(this.bill().customerId) : '';
    this.patientHistoryLoading.set(true);
    this.patientHistoryError.set(null);
    this.salesSvc.patientRetailHistory(patientNo, contact || this.bill().contact, 40).subscribe({
      next: (res) => {
        this.patientHistoryLoading.set(false);
        const bills = res.bills ?? [];
        this.patientHistoryBills.set(bills);
        if (bills.length && !this.patientHistoryExpanded()) {
          this.patientHistoryExpanded.set(bills[0].order_no);
        }
        if (autoOpen && bills.length > 0 && this.patientHistoryAutoKey !== key) {
          this.patientHistoryAutoKey = key;
          this.patientHistoryModalOpen.set(true);
        }
        if (forceOpen) this.patientHistoryModalOpen.set(true);
      },
      error: (err) => {
        this.patientHistoryLoading.set(false);
        this.patientHistoryBills.set([]);
        this.patientHistoryError.set(err.error?.message ?? 'Could not load purchase history.');
        if (forceOpen) this.patientHistoryModalOpen.set(true);
      },
    });
  }

  closePatientHistoryModal(): void {
    this.patientHistoryModalOpen.set(false);
  }

  togglePatientHistoryBill(orderNo: string): void {
    this.patientHistoryExpanded.update((c) => (c === orderNo ? null : orderNo));
  }

  patientHistoryStockSummary(line: PatientRetailHistoryLine): string {
    switch (line.stock_status) {
      case 'same_batch':
        return `Same batch (${line.fulfillment_segments?.[0]?.available_qty ?? 0} avail)`;
      case 'split_batch':
        return `Old batch + other batch(es)`;
      case 'split_batch_partial':
        return `Partial (short ${line.qty_unfulfilled ?? 0})`;
      case 'new_batch':
        return 'Other batch';
      case 'none':
        return 'Out of stock';
      default:
        return line.in_stock ? 'In stock' : 'Out of stock';
    }
  }

  historyFulfillmentMessage(line: PatientRetailHistoryLine): string {
    const segs = line.fulfillment_segments ?? [];
    if (!segs.length) return `Add ${line.p_name} to this bill?`;
    const parts = segs.map(
      (s) =>
        `${s.qty} × batch ${s.batch_no} (${s.rate_source === 'historical' ? 'prev. rate' : 'current MRP'} ₹${s.unit_mrp?.toFixed(2) ?? '0'})`,
    );
    let msg = `Add ${line.p_name}:\n${parts.join('\n')}`;
    if ((line.qty_unfulfilled ?? 0) > 0) {
      msg += `\n\nNote: ${line.qty_unfulfilled} qty cannot be fulfilled.`;
    }
    return msg;
  }

  addPatientHistoryLine(line: PatientRetailHistoryLine): void {
    if (!this.rights.hasFullAccess() && !this.rights.canView('SALES')) {
      this.error.set('You need Sales rights to add items.');
      return;
    }
    const segs = (line.fulfillment_segments ?? []).filter((s) => s.binding_id && s.batch_id && s.qty > 0);
    if (!segs.length) {
      alert(`"${line.p_name}" is out of stock.`);
      return;
    }
    if (!confirm(this.historyFulfillmentMessage(line))) return;
    this.appendHistorySegmentLines(segs, line, 0, () => {});
  }

  reorderPatientHistoryBill(bill: PatientRetailHistoryBill): void {
    if (!bill.products?.length) return;
    if (
      !confirm(
        `Reorder all ${bill.products.length} product(s) from bill ${bill.order_no}? Stock uses same batch when available.`,
      )
    ) {
      return;
    }
    const lines = bill.products;
    const addNext = (idx: number): void => {
      if (idx >= lines.length) {
        this.closePatientHistoryModal();
        return;
      }
      const ln = lines[idx];
      const segs = (ln.fulfillment_segments ?? []).filter((s) => s.binding_id && s.batch_id && s.qty > 0);
      if (!segs.length) {
        addNext(idx + 1);
        return;
      }
      this.appendHistorySegmentLines(segs, ln, 0, () => addNext(idx + 1));
    };
    addNext(0);
  }

  private appendHistorySegmentLines(
    segments: PatientHistoryFulfillmentSegment[],
    historyLine: PatientRetailHistoryLine,
    index: number,
    done: () => void,
  ): void {
    if (index >= segments.length) {
      done();
      return;
    }
    const seg = segments[index];
    const stub: SaleProduct = {
      binding_id: seg.binding_id,
      product_name: historyLine.p_name,
      schedule_category: 'OTC',
      gst_rate: 0,
      master_product_id: 0,
      available_qty: Math.floor(seg.qty),
      mrp: seg.pack_mrp ?? null,
      units_per_pack: 1,
      allow_loose: 0,
    };
    this.salesSvc.batches(seg.binding_id).subscribe((batches) => {
      let line = this.buildBillLineFromProduct(stub, batches, seg.batch_id);
      if (line) {
        const upp = line.units_per_pack || 1;
        const unitMrp = seg.unit_mrp ?? line.unit_mrp;
        line = {
          ...line,
          qty: seg.qty,
          unit_type: 'unit',
          unit_mrp: unitMrp,
          pack_mrp: seg.pack_mrp ?? unitMrp * upp,
          disc: historyLine.disc_per ?? seg.disc_per ?? 0,
        };
        line = this.repriceLine(line, this.bill().mode, this.bill().rateType);
        line = {
          ...line,
          ...this.retailDiscSync(line, { disc: line.disc }),
        };
        this.patchBill({ lines: [...this.bill().lines, line] });
      }
      this.appendHistorySegmentLines(segments, historyLine, index + 1, done);
    });
  }

  private markLinkedAgtReady(invoiceNo: string, saleId: number, net: number): void {
    const oid = this.linkedAgtOrderId();
    if (!oid) return;
    this.agtSvc.markReady(oid, invoiceNo, saleId, net).subscribe({
      complete: () => {
        this.linkedAgtOrderId.set(null);
        this.agtLinkedRx.set(null);
        this.agtPendingOrders.set([]);
      },
      error: () => {
        this.linkedAgtOrderId.set(null);
        this.agtLinkedRx.set(null);
      },
    });
  }

  // ---- dynamic counters ----
  private defaultMode(): BillMode {
    return this.businessType() === 'wholesale' ? 'wholesale' : 'retail';
  }
  isCashCounter(name?: string): boolean {
    return (name ?? this.activeCounter()).toUpperCase() === CASH_COUNTER;
  }

  private scheduleCode(schedule?: string | null): string {
    return String(schedule ?? 'OTC').trim().toUpperCase();
  }

  private isCashSaleType(bill: Bill = this.bill()): boolean {
    return (bill.saleType || 'Regular').toLowerCase() === 'cash';
  }

  isNormalSaleCounter(name: string): boolean {
    const u = (name || '').toUpperCase();
    return u !== CASH_COUNTER && u !== IPD_COUNTER;
  }

  /** Counters a Cash Counter line can be sent to (Counter 1 / 2 / 3 and extras). */
  cashSendCounters(): string[] {
    return this.counters().filter((c) => this.isNormalSaleCounter(c));
  }

  private isScheduledForRegular(schedule?: string | null): boolean {
    return SCHEDULED_FOR_REGULAR.has(this.scheduleCode(schedule));
  }

  private shouldSendFromCashCounter(schedule?: string | null): boolean {
    return CASH_COUNTER_SEND_SCHEDULES.has(this.scheduleCode(schedule));
  }

  private billHasScheduledDrug(extraSchedule?: string | null): boolean {
    if (this.isScheduledForRegular(extraSchedule)) return true;
    return this.bill().lines.some((l) => this.isScheduledForRegular(l.category));
  }

  /** Cash Counter save / send check — Schedule H is excluded (normal product). */
  private billHasCashSendDrug(extraSchedule?: string | null): boolean {
    if (this.shouldSendFromCashCounter(extraSchedule)) return true;
    return this.bill().lines.some((l) => this.shouldSendFromCashCounter(l.category));
  }

  /** Last Counter 1–3 a Cash Counter line was sent to — keep following products on the same bill. */
  private lastCashLineDest: string | null = null;

  /** Cash + H/H1/X on Counter 1–3 → Regular. Never runs on the Cash Counter tab. */
  private promoteCashBillToRegularIfScheduled(schedule?: string | null): boolean {
    if (this.isCashCounterActive()) return false;
    if (this.isWholesale()) return false;
    if (!this.isCashSaleType()) return false;
    if (!this.billHasScheduledDrug(schedule)) return false;
    this.set('saleType', 'Regular');
    const name = (this.bill().patientName || '').trim();
    if (!name || name.toLowerCase() === 'walk-in') {
      this.patchBill({ patientName: '' });
    }
    this.error.set(null);
    this.billInfo.set(
      'Scheduled product added — bill type switched to Regular. Enter patient details to continue.',
    );
    return true;
  }

  private pickNormalCounterForLineMove(): string {
    if (
      this.lastCashLineDest
      && this.counters().includes(this.lastCashLineDest)
      && this.isNormalSaleCounter(this.lastCashLineDest)
    ) {
      this.ensureCounter(this.lastCashLineDest);
      return this.lastCashLineDest;
    }
    const candidates = this.cashSendCounters();
    const free = candidates.find((c) => !this.counterHasDraft(c));
    if (free) {
      this.ensureCounter(free);
      return free;
    }
    const nums = candidates
      .map((c) => /^COUNTER\s+(\d+)$/i.exec(c)?.[1])
      .filter((n): n is string => !!n)
      .map((n) => Number(n));
    const name = `COUNTER ${(nums.length ? Math.max(...nums) : 0) + 1}`;
    this.ensureCounter(name);
    return name;
  }

  /** After a line is added on Cash Counter, send scheduled drugs to Counter 1–3 and open that counter. */
  private afterLineAdded(schedule?: string | null, lineIndex?: number): void {
    if (this.isCashCounterActive() && this.shouldSendFromCashCounter(schedule)) {
      const idx = lineIndex ?? 0;
      if (idx < 0) return;
      this.moveLineToCounter(idx, this.pickNormalCounterForLineMove(), true);
      return;
    }
    this.promoteCashBillToRegularIfScheduled(schedule);
  }

  /** Unique @for track key — binding_id alone collides when same product has 2 batches. */
  trackBillLine(index: number, line: BillLine): string {
    return `${line.binding_id}:${line.batch_id ?? 'n'}:${line.batch_no ?? ''}:${index}`;
  }

  private billLineKey(line: BillLine, index: number): string {
    return this.trackBillLine(index, line);
  }

  /** Max sellable qty for the qty input (retail = all batches FEFO; wholesale = selected batch). */
  batchMaxSoldQty(line: BillLine, index: number): number {
    const maxQty = this.isWholesale()
      ? this.batchRemainingBillQty(line, index)
      : this.productRemainingBillQty(line, index);
    const free = this.isWholesale() ? (Number(line.free_qty) || 0) : 0;
    return Math.max(1, Math.floor(maxQty - free));
  }

  /** FEFO order — earliest expiry first (null expiry last), then batch id. */
  private sortBatchesFefo(batches: BatchOption[]): BatchOption[] {
    return [...batches].sort((a, b) => {
      const ae = a.expiry_date || '';
      const be = b.expiry_date || '';
      if (!ae && be) return 1;
      if (ae && !be) return -1;
      if (ae !== be) return ae < be ? -1 : ae > be ? 1 : 0;
      return (a.batch_id || 0) - (b.batch_id || 0);
    });
  }

  /**
   * Total sellable qty in line unit_type across all non-expired batches for this product,
   * after subtracting stock already used by other bill lines (this line excluded).
   */
  productRemainingBillQty(line: BillLine, index: number): number {
    const upp = Math.max(1, line.units_per_pack || 1);
    let totalBase = 0;
    for (const b of this.sellableBatches(line.batches)) {
      const stockBase = Number(b.quantity) || 0;
      if (stockBase <= 0) continue;
      let otherBase = 0;
      this.bill().lines.forEach((l, i) => {
        if (i === index || l.batch_id !== b.batch_id) return;
        otherBase += this.lineBaseQty(l);
      });
      totalBase += Math.max(0, stockBase - otherBase);
    }
    return line.unit_type === 'pack' ? totalBase / upp : totalBase;
  }

  toggleSendMenu(index: number, event: Event): void {
    event.preventDefault();
    event.stopPropagation();
    if (this.sendMenuIndex() === index) {
      this.closeSendMenu();
      return;
    }
    const line = this.bill().lines[index];
    if (!line) return;
    const btn = event.currentTarget as HTMLElement;
    this.sendMenuIndex.set(index);
    this.sendMenuLineKey = this.billLineKey(line, index);
    this.sendMenuPos.set(this.placeSendMenu(btn));
  }

  closeSendMenu(): void {
    this.sendMenuIndex.set(null);
    this.sendMenuPos.set(null);
    this.sendMenuLineKey = null;
  }

  /** Prefer the line captured when the menu opened (not a stale index after prepend/reorder). */
  private resolveSendLineIndex(fallbackIndex: number): number {
    const lines = this.bill().lines;
    const key = this.sendMenuLineKey;
    if (key) {
      const found = lines.findIndex((l, i) => this.billLineKey(l, i) === key);
      if (found >= 0) return found;
      // Key includes index; if list shifted, match by binding + batch only.
      const parts = key.split(':');
      if (parts.length >= 3) {
        const bindingId = Number(parts[0]);
        const batchPart = parts[1];
        const batchId = batchPart === 'n' ? null : Number(batchPart);
        const batchNo = parts[2] || '';
        const byProduct = lines.findIndex(
          (l) =>
            l.binding_id === bindingId
            && (l.batch_id ?? null) === (Number.isFinite(batchId as number) ? batchId : null)
            && (l.batch_no || '') === batchNo,
        );
        if (byProduct >= 0) return byProduct;
      }
    }
    if (fallbackIndex >= 0 && fallbackIndex < lines.length) return fallbackIndex;
    return -1;
  }

  /** Viewport coords, adjusted if an ancestor creates a `position:fixed` containing block. */
  private placeSendMenu(btn: HTMLElement): { top: number; left: number; width: number } {
    const r = btn.getBoundingClientRect();
    const width = Math.max(r.width, 152);
    let n: HTMLElement | null = btn.parentElement;
    while (n && n !== document.body) {
      const s = getComputedStyle(n);
      const shifted =
        (s.transform && s.transform !== 'none')
        || (s.filter && s.filter !== 'none')
        || (s.perspective && s.perspective !== 'none')
        || /paint|layout|strict|content/.test(s.contain)
        || /\b(transform|filter|perspective)\b/.test(s.willChange);
      if (shifted) {
        const b = n.getBoundingClientRect();
        return { top: r.bottom - b.top + 4, left: r.left - b.left, width };
      }
      n = n.parentElement;
    }
    const maxLeft = window.innerWidth - width - 8;
    return {
      top: r.bottom + 4,
      left: Math.min(Math.max(8, r.left), Math.max(8, maxLeft)),
      width,
    };
  }

  /** Counter 1 / Counter 2 — readable labels for the Send-to menu. */
  formatCounterName(c: string): string {
    const m = /^COUNTER\s+(\d+)$/i.exec((c || '').trim());
    return m ? `Counter ${m[1]}` : c;
  }

  lastCashSendDest(): string | null {
    return this.lastCashLineDest;
  }

  /** Send one Cash Counter line to Counter 1 / 2 / 3. `follow` opens that counter. */
  moveLineToCounter(index: number, destName: string, follow = false): void {
    const dest = (destName || '').trim();
    if (!dest || !this.isCashCounterActive()) return;
    if (!this.isNormalSaleCounter(dest) || dest === this.activeCounter()) return;
    const srcName = this.activeCounter();
    const src = this.bills()[srcName];
    const line = src?.lines[index];
    if (!line) return;
    this.ensureCounter(dest);
    const destBill = this.bills()[dest];
    if (!destBill) return;

    let moved: BillLine = {
      ...line,
      batches: line.batches.map((b) => ({ ...b })),
    };
    moved = this.repriceLine(moved, destBill.mode, destBill.rateType);

    const destLines = destBill.lines.map((l) => ({ ...l, batches: l.batches.map((b) => ({ ...b })) }));
    const existing = destLines.findIndex(
      (l) => l.binding_id === moved.binding_id && l.batch_id === moved.batch_id,
    );
    if (existing >= 0) {
      destLines[existing] = { ...destLines[existing], qty: destLines[existing].qty + moved.qty };
    } else {
      destLines.push(moved);
    }

    const srcLines = src.lines.filter((_, i) => i !== index);
    let destNext: Bill = { ...destBill, lines: destLines };
    if (this.shouldSendFromCashCounter(moved.category) && (destNext.saleType || '').toLowerCase() === 'cash') {
      destNext = {
        ...destNext,
        saleType: 'Regular',
        patientName: destNext.patientName.trim().toLowerCase() === 'walk-in' ? '' : destNext.patientName,
      };
    }
    destNext = this.syncRetailBillDiscount(destNext);
    const srcNext = this.syncRetailBillDiscount({ ...src, lines: srcLines });
    this.lastCashLineDest = dest;
    this.closeSendMenu();
    this.bills.update((all) => ({ ...all, [srcName]: srcNext, [dest]: destNext }));
    this.error.set(null);
    if (follow) {
      this.switchCounter(dest);
    }
    const destLabel = this.formatCounterName(dest);
    if (this.shouldSendFromCashCounter(moved.category)) {
      const msg =
        `This is ${this.scheduleAlertPhrase(moved.category)} drug. `
        + `Customer details are required. Please continue on ${destLabel}.`;
      this.billInfo.set(msg);
      window.alert(msg);
    } else {
      this.billInfo.set(`${line.product_name} moved to ${destLabel}. Continue this bill there.`);
    }
  }

  private scheduleAlertPhrase(schedule?: string | null): string {
    const c = this.scheduleCode(schedule);
    if (c === 'H1') return 'an H1';
    if (c === 'H') return 'a Schedule H';
    if (c === 'X' || c === 'NRX') return 'a Schedule X';
    if (c === 'G') return 'a Schedule G';
    if (c === 'RESTRICTED') return 'a restricted';
    return c ? `a Schedule ${c}` : 'a scheduled';
  }

  onSendLineToCounter(index: number, destName: string): void {
    if (!destName) return;
    const resolved = this.resolveSendLineIndex(index);
    if (resolved < 0) {
      this.closeSendMenu();
      this.error.set('Could not find that product line to send. Try again.');
      return;
    }
    this.moveLineToCounter(resolved, destName, true);
  }

  /** Keep Cash Counter as walk-in Cash/Counter even if a draft was Regular. */
  private forceCashCounterWalkIn(): void {
    const b = this.bills()[CASH_COUNTER];
    if (!b) return;
    const lines = b.mode === 'retail'
      ? b.lines
      : b.lines.map((l) => this.repriceLine(l, 'retail', 'mrp'));
    this.bills.update((all) => ({
      ...all,
      [CASH_COUNTER]: {
        ...b,
        mode: 'retail',
        rateType: 'mrp',
        lines,
        saleType: 'Cash',
        patientName: 'Walk-in',
        customerId: null,
        contact: '',
        email: '',
        address: '',
        doctor: '',
        reminderDays: null,
        isChronic: false,
        customerKnown: false,
      },
    }));
  }

  private ensureCounter(name: string): void {
    if (!this.counters().includes(name)) {
      this.counters.update((cs) => [...cs, name]);
      this.bills.update((all) => ({ ...all, [name]: emptyBillForCounter(name, this.defaultMode()) }));
    }
  }
  addCounter(): void {
    const input = (prompt('New counter name:', 'COUNTER ' + (this.counters().length + 1)) || '').trim();
    if (!input) return;
    if (this.counters().includes(input)) { this.setActiveCounter(input); return; }
    this.counters.update((cs) => [...cs, input]);
    this.bills.update((all) => ({ ...all, [input]: emptyBillForCounter(input, this.defaultMode()) }));
    this.counterPatientUi.delete(input);
    this.setActiveCounter(input);
  }
  removeCounter(name: string, event: Event): void {
    event.stopPropagation();
    if (this.counters().length <= 1) return;
    if (this.bills()[name]?.lines.length && !confirm(`Counter "${name}" has items. Remove and discard?`)) return;
    this.counters.update((cs) => cs.filter((c) => c !== name));
    this.bills.update((all) => { const copy = { ...all }; delete copy[name]; return copy; });
    if (this.activeCounter() === name) this.setActiveCounter(this.counters()[0]);
    this.counterPatientUi.delete(name);
  }
  clearIpd(): void {
    this.ipdAdmissionId.set(null);
    this.ipdPatient.set('');
  }

  // ---- mode ----
  canSwitch(): boolean {
    return this.businessType() === 'both';
  }
  setMode(mode: BillMode): void {
    if (this.isCashCounterActive()) return;
    if (!this.canSwitch() && this.businessType() !== mode) return;
    this.addingPatient.set(false);
    let rateType = this.bill().rateType;
    if (mode === 'wholesale' && rateType === 'mrp') {
      rateType = 'ptr';
    }
    if (mode === 'wholesale') {
      this.loadWholesaleDefaults();
    }
    const lines = this.bill().lines.map((l) => this.repriceLine(l, mode, rateType));
    this.patchBill({ mode, rateType, lines });
  }
  setRateType(rt: RateType): void {
    const lines = this.bill().lines.map((l) => this.repriceLine(l, this.bill().mode, rt));
    this.patchBill({ rateType: rt, lines });
  }

  /** Category row → Common (Fixed) margin → legacy Allopathy fallback. */
  private marginForCategory(category: string): RateMarginRow | undefined {
    const rows = this.rateMargins();
    const cat = (category || '').trim().toLowerCase();
    return (cat ? rows.find((r) => (r.category || '').trim().toLowerCase() === cat) : undefined)
      ?? rows.find((r) => (r.category || '').toUpperCase() === 'COMMON')
      ?? rows.find((r) => r.category === 'Allopathy')
      ?? rows[0];
  }

  /** Wholesale PTR/PTS = pack MRP − margin% (GST is added on top of this rate on the bill). */
  private deriveWholesalePackRates(packMrp: number, ptrMargin: number, ptsMargin: number): { ptr: number; pts: number } {
    return {
      ptr: this.round2(packMrp * (1 - ptrMargin / 100)),
      pts: this.round2(packMrp * (1 - ptsMargin / 100)),
    };
  }

  /** Always from the Rate Margin master; batch PTR/PTS only when the pack has no MRP. */
  private resolveWholesalePackRates(line: BillLine, rt: RateType): { ptr: number; pts: number; rate: number } {
    const margin = this.marginForCategory(line.product_category || '');
    const ptrM = Number(margin?.ptr_margin ?? 20);
    const ptsM = Number(margin?.pts_margin ?? 30);
    const derived = this.deriveWholesalePackRates(line.pack_mrp, ptrM, ptsM);
    const ptr = derived.ptr > 0 ? derived.ptr : line.ptr;
    const pts = derived.pts > 0 ? derived.pts : line.pts;

    const packRate = rt === 'pts'
      ? (pts > 0 ? pts : ptr)
      : (ptr > 0 ? ptr : pts);
    return { ptr, pts, rate: packRate > 0 ? packRate : line.rate };
  }

  /** Retail getWholesaleStripPurchaseRate — scheme-adjusted pack purchase for profit. */
  private wholesalePackPurchaseRate(line: BillLine): number {
    let packPurchase = line.pack_purchase_rate > 0
      ? line.pack_purchase_rate
      : (Number(line.cost) || 0) * Math.max(1, line.units_per_pack);
    const sch = Number(line.scheme_pct ?? 0);
    if (packPurchase > 0 && sch > 0) {
      packPurchase = this.round2((packPurchase * 100) / (100 + sch));
    }
    return packPurchase;
  }

  wholesalePrimaryRateLabel(): string {
    return this.bill().rateType === 'pts' ? 'Sale Rate / P.T.S' : 'Sale Rate / P.T.R';
  }

  wholesaleOthRateLabel(): string {
    return this.bill().rateType === 'pts' ? 'P.T.R' : 'P.T.S';
  }

  /** Retail wsales CalculateFreeQty — net rate, scheme free qty, billing mode. */
  private recalcWholesaleLine(l: BillLine): BillLine {
    const upp = Math.max(1, l.units_per_pack || 1);
    const stripMrp = this.wholesaleStripMrpFromLine(l);
    if (stripMrp > 0) {
      l = {
        ...l,
        pack_mrp: stripMrp,
        unit_mrp: Math.round((stripMrp / upp) * 10000) / 10000,
      };
    }
    const qty = Math.max(1, Math.floor(Number(l.qty)) || 1);
    const baseRate = Number(l.base_rate) > 0 ? Number(l.base_rate) : Number(l.rate);
    const schemePct = Number(l.sale_scheme_pct) || 0;
    let freeQty = Math.max(0, Number(l.free_qty) || 0);
    if (schemePct > 0) {
      freeQty = this.round2((qty * schemePct) / 100);
    }
    let rate = baseRate;
    let netRate = baseRate;
    if (l.billing_with === 'Net Rate') {
      const denom = qty + freeQty;
      netRate = denom > 0 ? this.round2((baseRate * qty) / denom) : baseRate;
      rate = netRate;
    }
    return {
      ...l,
      qty,
      base_rate: baseRate,
      rate,
      net_rate: netRate,
      free_qty: freeQty,
      total_qty: this.round2(qty + freeQty),
    };
  }

  private defaultWholesaleFields(rate: number, othRate: number, qty = 1): Pick<
    BillLine,
    'base_rate' | 'oth_rate' | 'billing_with' | 'sale_scheme_pct' | 'free_qty' | 'total_qty' | 'net_rate'
  > {
    return {
      base_rate: rate,
      oth_rate: othRate,
      billing_with: 'Sale Rate',
      sale_scheme_pct: 0,
      free_qty: 0,
      total_qty: qty,
      net_rate: rate,
    };
  }

  private repriceLine(l: BillLine, mode: BillMode, rt: RateType): BillLine {
    if (mode === 'wholesale') {
      const { ptr, pts, rate } = this.resolveWholesalePackRates(l, rt);
      const othRate = rt === 'pts' ? ptr : pts;
      const packPurchase = this.wholesalePackPurchaseRate({ ...l, ptr, pts });
      const repriced: BillLine = {
        ...l,
        ptr,
        pts,
        unit_type: 'pack',
        rate: Math.round(rate * 100) / 100,
        pack_purchase_rate: packPurchase,
        ...this.defaultWholesaleFields(
          Math.round(rate * 100) / 100,
          Math.round(othRate * 100) / 100,
          l.qty || 1,
        ),
        billing_with: l.billing_with ?? 'Sale Rate',
        sale_scheme_pct: l.sale_scheme_pct ?? 0,
        free_qty: l.free_qty ?? 0,
        pur_disc_pct: l.pur_disc_pct ?? 0,
      };
      return this.recalcWholesaleLine(repriced);
    }
    // Retail: sell at unit MRP; Rate column shows purchase cost (see displayPurchaseRate).
    return { ...l, unit_type: 'unit', rate: l.unit_mrp };
  }

  /** Apply batch + purchase rates to a bill line (Retail purchase ↔ wsales sync). */
  private applyBatchToLine(line: BillLine, batch: BatchOption): BillLine {
    const upp = Math.max(1, batch.units_per_pack ?? line.units_per_pack ?? 1);
    // Always take strip MRP from the selected batch (never keep previous line MRP).
    const packMrp = this.stripMrpFromBatch(batch);
    const unitMrp =
      packMrp > 0 && upp > 0
        ? Math.round((packMrp / upp) * 10000) / 10000
        : Number(batch.unit_mrp ?? batch.mrp) || 0;
    const packPurchase =
      Number(batch.pack_purchase_rate) > 0
        ? Number(batch.pack_purchase_rate)
        : Math.round((Number(batch.purchase_rate) || 0) * upp * 100) / 100;
    const packNet = Number(batch.pack_net_cost) > 0 ? Number(batch.pack_net_cost) : packPurchase;
    const unitNet = Math.round((packNet / upp) * 10000) / 10000;
    return {
      ...line,
      units_per_pack: upp,
      batch_id: batch.batch_id,
      batch_no: batch.batch_no,
      expiry: batch.expiry_date,
      shelf: batch.shelf_location ?? line.shelf,
      unit_mrp: unitMrp,
      pack_mrp: packMrp,
      ptr: batch.ptr ?? 0,
      pts: batch.pts ?? 0,
      cost: batch.purchase_rate ?? 0,
      unit_net_cost: unitNet,
      pack_purchase_rate: packPurchase,
      scheme_pct: Number(batch.scheme_pct ?? 0),
      pur_disc_pct: Number(batch.discount_pct ?? line.pur_disc_pct ?? 0),
      batches: line.batches.length ? line.batches : [batch],
      batch_locked: line.batch_locked ?? false,
    };
  }

  /** Retail wsales: CD% + rate margins (PTR/PTS / rate_by) from master. */
  private loadWholesaleDefaults(): void {
    this.crud.list<RateMarginRow>('rate-margins', { perPage: 20 }).subscribe({
      next: (r) => {
        const rows = r.data ?? [];
        this.rateMargins.set(rows);
        const row = rows.find((x) => x.category === 'Allopathy') ?? rows[0];
        const std = Number(row?.std_discount ?? 0);
        const patch: Partial<Bill> = {};
        if (this.isWholesale() && std > 0 && !this.bill().cdDiscPct) {
          patch.cdDiscPct = std;
        }
        if (this.isWholesale() && this.bill().lines.length) {
          patch.lines = this.bill().lines.map((l) =>
            this.repriceLine(l, 'wholesale', this.bill().rateType),
          );
        }
        if (Object.keys(patch).length) {
          this.patchBill(patch);
        }
      },
    });
  }

  onWholesaleDraftFocus(): void {
    if (!this.isWholesale()) return;
    const draft = this.wsDraft();
    if (!draft) return;
    this.loadPrevCustomerRates(draft.binding_id, this.wsDraftEditIndex());
  }

  private loadPrevCustomerRates(bindingId: number, lineIndex: number | null): void {
    const customerId = this.bill().customerId;
    if (!this.isWholesale() || !customerId) {
      this.prevCustomerRates.set([]);
      this.prevRatesLineIndex.set(null);
      return;
    }
    this.salesSvc.prevCustomerRates(customerId, bindingId).subscribe({
      next: (rows) => {
        this.prevCustomerRates.set(rows);
        this.prevRatesLineIndex.set(lineIndex);
      },
      error: () => {
        this.prevCustomerRates.set([]);
        this.prevRatesLineIndex.set(null);
      },
    });
  }

  applyPrevCustomerRate(prev: { sale_rate: number; discount_pct: number }): void {
    const rate = Math.round(prev.sale_rate * 100) / 100;
    const disc = Number(prev.discount_pct) || 0;
    const draft = this.wsDraft();
    if (draft) {
      this.patchWholesaleDraft({ rate, base_rate: rate, disc });
      return;
    }
    const idx = this.prevRatesLineIndex();
    if (idx === null || idx < 0) return;
    const line = this.bill().lines[idx];
    if (!line) return;
    if (this.isWholesale()) {
      this.updateLine(idx, { rate, base_rate: rate, disc });
    } else {
      const merged = { ...line, rate, base_rate: rate };
      this.updateLine(idx, { rate, base_rate: rate, ...this.retailDiscSync(merged, { disc }) });
    }
  }

  prevRateToneFor(prev: { sale_rate: number }): 'high' | 'low' | 'same' {
    const draft = this.wsDraft();
    const line = draft ?? (() => {
    const idx = this.prevRatesLineIndex();
      return idx !== null && idx >= 0 ? this.bill().lines[idx] : null;
    })();
    if (!line) return 'same';
    return this.prevRateTone(line.rate, prev.sale_rate);
  }

  prevRateTooltip(prev: { sale_rate: number; discount_pct: number; invoice_no: string; invoice_date: string; payment_mode: string | null }, index: number): string {
    const parts = [
      index === 0 ? 'Latest' : 'Previous',
      `₹${prev.sale_rate.toFixed(2)}`,
      prev.discount_pct ? `Disc ${prev.discount_pct}%` : null,
      prev.invoice_no || null,
      prev.invoice_date || null,
      prev.payment_mode || null,
    ].filter(Boolean);
    return parts.join(' · ');
  }

  private prevRateTone(current: number, prev: number): 'high' | 'low' | 'same' {
    const diff = current - prev;
    if (Math.abs(diff) < 0.005) return 'same';
    return diff > 0 ? 'high' : 'low';
  }

  /** Retail/Wholesale MRP column = strip/pack MRP for the selected batch. */
  displayPackMrp(l: BillLine): number {
    if (l.batch_id != null) {
      const b = l.batches.find((x) => x.batch_id === l.batch_id);
      if (b) {
        const fromBatch = this.stripMrpFromBatch(b);
        if (fromBatch > 0) return fromBatch;
      }
    }
    if (this.isWholesale()) {
      return this.wholesaleStripMrpFromLine(l);
    }
    if (l.pack_mrp > 0) return Math.round(l.pack_mrp * 100) / 100;
    const upp = Math.max(1, l.units_per_pack || 1);
    return Math.round(l.unit_mrp * upp * 100) / 100;
  }

  /** Product sidebar — strip/pack MRP (inventory batch stores per-unit MRP). */
  /** Search API already returns pack MRP (purchase line / batch unit × pack / binding). */
  sidebarProductMrp(p: SaleProduct): number {
    const mrp = Number(p.mrp) || 0;
    return mrp > 0 ? Math.round(mrp * 100) / 100 : 0;
  }

  /**
   * Strip/pack MRP from a stock batch (purchase_items.mrp = strip; ib.mrp = per loose unit).
   * Used for both retail and wholesale so batch select always refreshes MRP.
   */
  private stripMrpFromBatch(b: BatchOption): number {
    const upp = Math.max(1, b.units_per_pack ?? 1);
    let pack = Number(b.pack_mrp) || 0;
    const unit = Number(b.unit_mrp ?? b.mrp ?? 0);
    if (upp > 1 && pack > 0 && unit > 0 && Math.abs(pack - unit) < 0.02) {
      pack = unit * upp;
    } else if (pack <= 0 && unit > 0) {
      pack = unit * upp;
    }
    if (pack > 0) {
      return Math.round(pack * 100) / 100;
    }
    return this.wholesaleStripMrpFromUnitOrPack(unit, upp);
  }

  /** Batch dropdown MRP label (strip/pack for the option). */
  batchStripMrp(b: BatchOption): number {
    return this.stripMrpFromBatch(b);
  }

  /** @deprecated alias — use stripMrpFromBatch */
  private wholesaleStripMrpFromBatch(b: BatchOption): number {
    return this.stripMrpFromBatch(b);
  }

  private wholesaleStripMrpFromLine(l: BillLine): number {
    const upp = Math.max(1, l.units_per_pack || 1);
    let pack = Number(l.pack_mrp) || 0;
    const unit = Number(l.unit_mrp) || 0;
    if (upp > 1 && pack > 0 && unit > 0 && Math.abs(pack - unit) < 0.02) {
      pack = unit * upp;
    } else if (pack <= 0 && unit > 0) {
      pack = unit * upp;
    }
    if (pack > 0) {
      return Math.round(pack * 100) / 100;
    }
    return this.wholesaleStripMrpFromUnitOrPack(unit, upp);
  }

  /**
   * Search MRP should be strip/pack (API prefers purchase_items.mrp). Legacy rows may still be per-tab.
   */
  private wholesaleStripMrpFromUnitOrPack(mrp: number, upp: number): number {
    if (mrp <= 0) return 0;
    upp = Math.max(1, upp);
    if (upp > 1 && mrp > 0 && mrp <= 15) {
      return Math.round(mrp * upp * 100) / 100;
    }
    return Math.round(mrp * 100) / 100;
  }

  /** Sidebar stock — wholesale shows sellable strips; retail shows units. */
  sidebarProductStockLabel(p: SaleProduct): string {
    const qty = Number(p.available_qty) || 0;
    if (p.is_expired) {
      const eq = Number(p.expired_qty) || qty;
      if (this.isWholesale()) {
        const upp = Math.max(1, Number(p.units_per_pack) || 1);
        const strips = Math.floor(eq / upp);
        return `Expired · ${strips} strip${strips === 1 ? '' : 's'}`;
      }
      return `Expired · ${eq}`;
    }
    if (qty <= 0) return 'Out of stock';
    if (this.isWholesale()) {
      const upp = Math.max(1, Number(p.units_per_pack) || 1);
      const strips = Math.floor(qty / upp);
      if (strips <= 0) return 'Out of stock';
      return `${strips} strip${strips === 1 ? '' : 's'}`;
    }
    return `${qty} in stock`;
  }

  /** Retail Exp column — YYYY-MM (same as purchase entry). */
  displayExpiry(raw: string | null | undefined): string {
    return formatExpiryMonth(raw) || '-';
  }

  /** Compact MM/yy for dense wholesale grid. */
  displayExpiryShort(raw: string | null | undefined): string {
    const ym = formatExpiryMonth(raw);
    if (!ym) return '-';
    const [y, m] = ym.split('-');
    return `${m}/${y.slice(-2)}`;
  }

  /** Batch/product expired when expiry date is before today. */
  isExpiryPast(raw: string | null | undefined): boolean {
    if (!raw) return false;
    const d = String(raw).slice(0, 10);
    if (!/^\d{4}-\d{2}-\d{2}/.test(d)) {
      const ym = formatExpiryMonth(raw);
      if (!ym) return false;
      const end = new Date(Number(ym.slice(0, 4)), Number(ym.slice(5, 7)), 0);
      const today = new Date();
      today.setHours(0, 0, 0, 0);
      return end < today;
    }
    const exp = new Date(d + 'T00:00:00');
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    return exp < today;
  }

  isBatchExpired(b: BatchOption): boolean {
    return b.is_expired === true || this.isExpiryPast(b.expiry_date);
  }

  sellableBatches(batches: BatchOption[]): BatchOption[] {
    return batches.filter((b) => !this.isBatchExpired(b));
  }

  /** Retail Rate column = pack purchase rate from batch (Retail sales-form `rate` parity). */
  displayPurchaseRate(l: BillLine): number {
    if (l.pack_purchase_rate > 0) {
      return Math.round(l.pack_purchase_rate * 100) / 100;
    }
    const cost = Number(l.cost) || 0;
    const upp = Math.max(1, l.units_per_pack || 1);
    return Math.round(cost * upp * 100) / 100;
  }

  // ---- counter ----
  /** Patient lookup UI lives outside Bill; keep it per counter so bills on other counters stay independent. */
  private readonly counterPatientUi = new Map<string, {
    query: string;
    phoneKey: number | null;
    adding: boolean;
    onPhone: PhonePatientOption[];
    historyBills: PatientRetailHistoryBill[];
    historyExpanded: string | null;
    historyKey: string;
  }>();

  private setActiveCounter(c: string): void {
    this.refreshStaleDraftBillDates();
    const prev = this.activeCounter();
    if (prev === c) return;
    this.counterPatientUi.set(prev, {
      query: this.patientSearchQuery(),
      phoneKey: this.phonePatientSelectKey(),
      adding: this.addingPatient(),
      onPhone: this.patientsOnPhone(),
      historyBills: this.patientHistoryBills(),
      historyExpanded: this.patientHistoryExpanded(),
      historyKey: this.patientHistoryAutoKey,
    });
    clearTimeout(this.phoneLookupTimer);
    clearTimeout(this.patientHistoryFetchTimer);
    this.lookingUp.set(false);
    this.showPatientDropdown.set(false);
    this.showDoctorDropdown.set(false);
    this.patientHistoryModalOpen.set(false);
    this.patientHistoryError.set(null);
    const saved = this.counterPatientUi.get(c);
    this.patientSearchQuery.set(saved?.query ?? '');
    this.phonePatientSelectKey.set(saved?.phoneKey ?? null);
    this.addingPatient.set(saved?.adding ?? false);
    this.patientsOnPhone.set(saved?.onPhone ?? []);
    this.patientHistoryBills.set(saved?.historyBills ?? []);
    this.patientHistoryExpanded.set(saved?.historyExpanded ?? null);
    this.patientHistoryAutoKey = saved?.historyKey ?? '';
    this.activeCounter.set(c);
  }

  /**
   * Ctrl+Left / Ctrl+Right changes counter from anywhere on the bill.
   * Plain arrows stay free for caret move and Cash Counter product select.
   */
  onSalesCounterArrows(event: KeyboardEvent): void {
    if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
    if (!event.ctrlKey || event.altKey || event.metaKey) return;
    if (!this.shouldTrapSalesFocus()) return;
    const root = this.host.nativeElement as HTMLElement;
    const active = document.activeElement as HTMLElement | null;
    if (!active || !root.contains(active)) return;
    if (
      this.cameraOpen()
      || this.subOpen()
      || this.quickOpen()
      || this.wholesaleCustomerPopup()
      || this.patientHistoryModalOpen()
      || this.agtModalOpen()
      || this.cashDayOpen()
      || this.sendMenuIndex() !== null
    ) {
      return;
    }
    if (active.closest('.modal-custom, .ws-entry-panel--modal')) return;
    const list = this.counters();
    if (list.length < 2) return;
    const idx = Math.max(0, list.indexOf(this.activeCounter()));
    const step = event.key === 'ArrowRight' ? 1 : -1;
    event.preventDefault();
    event.stopPropagation();
    this.switchCounter(list[(idx + step + list.length) % list.length]);
  }

  /** Up / Down on the product table moves to the previous or next product row. */
  private onProductRowArrows(event: KeyboardEvent): void {
    if (event.key !== 'ArrowUp' && event.key !== 'ArrowDown') return;
    if (event.altKey || event.ctrlKey || event.metaKey) return;
    if (!this.shouldTrapSalesFocus()) return;
    const active = document.activeElement as HTMLElement | null;
    if (!active || active.tagName === 'SELECT') return;
    const root = this.host.nativeElement as HTMLElement;
    const row = active.closest('tr.bill-line-row, tr.ws-list-row') as HTMLTableRowElement | null;
    if (!row || !root.contains(row)) return;
    const rows = Array.from(row.parentElement?.querySelectorAll<HTMLTableRowElement>(':scope > tr.bill-line-row, :scope > tr.ws-list-row') ?? []);
    const idx = rows.indexOf(row);
    if (idx < 0 || rows.length < 2) {
      event.preventDefault();
      return;
    }
    const step = event.key === 'ArrowDown' ? 1 : -1;
    const next = rows[(idx + step + rows.length) % rows.length];
    const target = this.sameProductCell(active, next);
    if (!target) return;
    event.preventDefault();
    event.stopPropagation();
    this.focusProductCell(target);
  }

  private sameProductCell(active: HTMLElement, row: HTMLTableRowElement): HTMLElement | null {
    const classes = ['line-send-btn', 'g-input-batch', 'g-input-qty', 'g-input-disc', 'g-input-disc-amt', 'sub-link', 'ws-del-btn'];
    for (const name of classes) {
      if (!active.classList.contains(name)) continue;
      const match = row.querySelector('.' + name) as HTMLElement | null;
      if (match && !match.hasAttribute('disabled')) return match;
    }
    if (active.closest('.col-action')) {
      const btn = row.querySelector('.col-action button') as HTMLElement | null;
      if (btn && !btn.hasAttribute('disabled')) return btn;
    }
    return (row.querySelector('.g-input-qty') as HTMLElement | null)
      ?? (row.querySelector('button:not([disabled])') as HTMLElement | null);
  }

  private focusProductCell(el: HTMLElement): void {
    el.focus({ preventScroll: true });
    el.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    if (el instanceof HTMLInputElement && (el.type === 'number' || el.type === 'text')) el.select();
  }

  /** Ctrl+Left / Ctrl+Right while a counter tab is focused moves to the previous or next counter. */
  onCounterArrow(event: KeyboardEvent, name: string): void {
    if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
    if (!event.ctrlKey || event.altKey || event.metaKey) return;
    const list = this.counters();
    const idx = list.indexOf(name);
    if (idx < 0 || list.length < 2) return;
    const step = event.key === 'ArrowRight' ? 1 : -1;
    const next = list[(idx + step + list.length) % list.length];
    event.preventDefault();
    event.stopPropagation();
    this.switchCounter(next);
    queueMicrotask(() => this.focusCounterTab(next));
  }

  private focusCounterTab(name: string): void {
    const btn = this.host.nativeElement.querySelector(
      `.counter-tab[data-counter="${CSS.escape(name)}"]`,
    ) as HTMLElement | null;
    btn?.focus({ preventScroll: true });
  }

  /** Rounded net of that counter's open bill, same figure as the bill total. */
  counterNet(c: string): number {
    const b = this.bills()[c];
    if (!b) return 0;
    if (c === this.activeCounter()) return this.netTotal();
    return this.netOfBill(b, this.isCashCounter(c));
  }

  private netOfBill(b: Bill, cashCounter: boolean): number {
    const wholesale = !cashCounter && b.mode === 'wholesale';
    let sell = 0;
    for (const l of b.lines) {
      if (wholesale) {
        const gross = this.round2(l.rate * l.qty);
        const disc = this.round2(gross * ((Number(l.disc) || 0) / 100));
        const afterDisc = gross - disc;
        const cd = this.round2(afterDisc * ((Number(b.cdDiscPct) || 0) / 100));
        const taxable = this.round2(afterDisc - cd);
        sell += this.round2(taxable + taxable * ((Number(l.gst_rate) || 0) / 100));
      } else {
        sell += this.round2(l.rate * l.qty * (1 - (Number(l.disc) || 0) / 100));
      }
    }
    if (wholesale) {
      sell -= Number(b.creditDeductionAmt) || 0;
    } else {
      const disc = Math.max(0, Number(b.billDiscountAmt) || 0);
      sell -= Math.min(disc, sell);
    }
    return Math.round(Math.max(0, this.round2(sell)));
  }

  switchCounter(c: string): void {
    this.exitCashSelectMode();
    this.setActiveCounter(c);
    if (this.isCashCounter(c)) {
      this.applyCashCounterRetail();
      if (this.searchTerm().trim() || this.selectedGeneric() != null) {
        this.runSearch();
      } else {
        this.loadInitial();
      }
    } else if (this.searchTerm().trim() || this.selectedGeneric() != null) {
      this.runSearch();
    } else if (this.searchResults().length) {
      this.loadInitial();
    }
    this.completed.set(null);
    this.error.set(null);
    this.closeSendMenu();
    this.billInfo.set(null);
  }

  /** Cash Counter: retail bill, Cash/Counter sale type, walk-in — no patient block. */
  private applyCashCounterRetail(): void {
    this.forceCashCounterWalkIn();
    const b = this.bill();
    if (b.mode !== 'retail') {
      const lines = b.lines.map((l) => this.repriceLine(l, 'retail', 'mrp'));
      this.patchBill({ mode: 'retail', rateType: 'mrp', lines });
    }
  }
  counterCount(c: string): number {
    return this.bills()[c].lines.length;
  }
  /** Counter is "live" when it holds a patient name or any items. */
  counterInUse(c: string): boolean {
    const b = this.bills()[c];
    if (!b) return false;
    const name = b.patientName.trim();
    const named = name.length > 0 && name.toLowerCase() !== 'walk-in';
    return named || b.lines.length > 0;
  }
  /** True when this counter has customer details or lines worth keeping. */
  counterHasDraft(c: string = this.activeCounter()): boolean {
    const b = this.bills()[c];
    if (!b) return false;
    if (b.lines.length > 0 || b.wsDraft) return true;
    if (b.prescriptionId || b.prescriptionName) return true;
    if ((b.billDiscountAmt || 0) > 0 || (b.creditDeductionAmt || 0) > 0 || !!b.creditNoteNo) return true;
    if (b.doctor.trim() || b.narration.trim() || b.address.trim() || b.email.trim() || b.contact.trim()) return true;
    if (b.reminderDays != null && Number(b.reminderDays) > 0) return true;
    if (b.isChronic) return true;
    const name = b.patientName.trim().toLowerCase();
    return name.length > 0 && name !== 'walk-in';
  }

  /** Drop the open bill on this counter. Other counters stay as they are. */
  discardDraft(): void {
    const c = this.activeCounter();
    if (!this.counterHasDraft(c)) return;
    if (!confirm(`Discard the unsaved bill on ${this.counterLabel(c)}?`)) return;
    this.bills.update((all) => ({ ...all, [c]: emptyBillForCounter(c, this.defaultMode()) }));
    this.patientSearchQuery.set('');
    this.phonePatientSelectKey.set(null);
    this.addingPatient.set(false);
    this.patientsOnPhone.set([]);
    this.showPatientDropdown.set(false);
    this.showDoctorDropdown.set(false);
    this.counterPatientUi.delete(c);
    this.error.set(null);
    this.billInfo.set(null);
    this.tabToCustomerAfterAdd = false;
    if (this.isCashCounter(c)) this.applyCashCounterRetail();
    this.focusProductSearch();
  }

  /** Tab caption: the patient name once entered, else the counter name. */
  counterLabel(c: string): string {
    const name = this.bills()[c]?.patientName.trim();
    if (name && name.toLowerCase() !== 'walk-in') return name;
    return c;
  }
  private patchBill(patch: Partial<Bill>): void {
    this.bills.update((all) => {
      const cur = all[this.activeCounter()];
      const next = this.syncRetailBillDiscount({ ...cur, ...patch });
      return { ...all, [this.activeCounter()]: next };
    });
  }

  /** Retail line net sum (for bulk-disc clamp before bill signal updates). */
  private retailItemsSellFromLines(lines: BillLine[]): number {
    return lines.reduce((s, l) => {
      const base = l.rate * l.qty * (1 - (Number(l.disc) || 0) / 100);
      return s + this.round2(base);
    }, 0);
  }

  /** Keep bulk disc ≤ line net; clear on wholesale (Retail bulkDiscCalc parity). */
  private syncRetailBillDiscount(b: Bill): Bill {
    if (b.mode === 'wholesale') {
      return b.billDiscountAmt === 0 ? b : { ...b, billDiscountAmt: 0 };
    }
    const sell = this.retailItemsSellFromLines(b.lines);
    const disc = Math.max(0, Number(b.billDiscountAmt) || 0);
    if (disc <= sell) return b;
    return { ...b, billDiscountAmt: this.round2(sell) };
  }
  set<K extends keyof Bill>(field: K, value: Bill[K]): void {
    let v = value;
    if (field === 'reminderDays') {
      v = formatReminderDays(value) as Bill[K];
    } else if (typeof v === 'string') {
      if (field === 'contact') {
        v = formatPhone(v) as Bill[K];
      } else if (field === 'email') {
        v = v.trim().toLowerCase().slice(0, 120) as Bill[K];
      } else if (
        field === 'patientName' ||
        field === 'doctor' ||
        field === 'address' ||
        field === 'narration'
      ) {
        v = toUpper(v).slice(0, field === 'address' ? 255 : 200) as Bill[K];
      }
    }
    this.patchBill({ [field]: v } as Partial<Bill>);
  }

  /** Retail: extra discount on bill net (after line totals). */
  onBillDiscountAmtChange(value: string | number): void {
    if (this.isWholesale()) return;
    const n = Number(value);
    if (value !== '' && value !== null && !Number.isFinite(n)) {
      this.set('billDiscountAmt', 0);
      return;
    }
    const max = this.itemsSell();
    let amt = Math.max(0, n || 0);
    if (amt > max) amt = max;
    this.set('billDiscountAmt', this.round2(amt));
  }

  /** Sum of selected Cash Counter line nets (selection mode). */
  readonly selectedTotal = computed(() => {
    if (!this.isCashCounterActive()) return 0;
    const lines = this.bill().lines;
    let sum = 0;
    for (const i of this.cashSelectedIndexes()) {
      const line = lines[i];
      if (line) sum += this.lineSell(line);
    }
    return this.round2(sum);
  });

  readonly cashSelectedCount = computed(() => this.cashSelectedIndexes().size);

  isCashLineSelected(index: number): boolean {
    return this.cashSelectedIndexes().has(index);
  }

  enterCashSelectMode(): void {
    if (!this.isCashCounterActive()) return;
    const n = this.bill().lines.length;
    if (n === 0) return;
    this.cashSelectMode.set(true);
    this.cashSelectFocus.set(0);
    this.closeSendMenu();
    queueMicrotask(() => this.focusCashSelectRow(0));
  }

  exitCashSelectMode(): void {
    this.cashSelectMode.set(false);
    this.cashSelectedIndexes.set(new Set());
    this.cashSelectFocus.set(0);
  }

  toggleCashLineSelected(index: number): void {
    if (!this.cashSelectMode() || !this.isCashCounterActive()) return;
    const lines = this.bill().lines;
    if (index < 0 || index >= lines.length) return;
    const next = new Set(this.cashSelectedIndexes());
    if (next.has(index)) next.delete(index);
    else next.add(index);
    this.cashSelectedIndexes.set(next);
  }

  onCashSelectRowClick(index: number, event?: Event): void {
    if (!this.cashSelectMode() || !this.isCashCounterActive()) return;
    const t = event?.target as HTMLElement | undefined;
    if (t?.closest('button, a, input, select, .line-send, .sub-link')) return;
    this.cashSelectFocus.set(index);
    this.toggleCashLineSelected(index);
  }

  private focusCashSelectRow(index: number): void {
    const root = this.host.nativeElement as HTMLElement;
    const rows = root.querySelectorAll<HTMLElement>('tr.bill-line-row[data-cash-select]');
    const row = rows[index];
    if (!row) return;
    row.focus({ preventScroll: true });
    row.scrollIntoView({ block: 'nearest', inline: 'nearest' });
  }

  private isTypingInField(el: HTMLElement | null): boolean {
    if (!el) return false;
    const tag = el.tagName;
    if (tag === 'TEXTAREA' || tag === 'SELECT') return true;
    if (tag !== 'INPUT') return false;
    const type = ((el as HTMLInputElement).type || 'text').toLowerCase();
    return type === 'text' || type === 'search' || type === 'email' || type === 'tel' || type === 'password' || type === 'number' || type === 'date';
  }

  /**
   * Shift+S on every counter → Product Search (always; intentional shortcut).
   * Plain S stays Cash Counter select-mode only (see onCashSelectKeys).
   */
  private onShiftSToProductSearch(event: KeyboardEvent): boolean {
    const key = event.key;
    const code = event.code;
    const isS = key === 's' || key === 'S' || code === 'KeyS';
    if (!isS || !event.shiftKey) return false;
    if (event.ctrlKey || event.altKey || event.metaKey) return false;
    if (!this.shouldTrapSalesFocus()) return false;
    if (
      this.cameraOpen()
      || this.subOpen()
      || this.quickOpen()
      || this.patientHistoryModalOpen()
      || this.agtModalOpen()
      || this.cashDayOpen()
      || this.wholesaleCustomerPopup()
    ) {
      return false;
    }
    event.preventDefault();
    event.stopPropagation();
    if (this.cashSelectMode()) this.exitCashSelectMode();
    this.closeSendMenu();
    this.focusProductSearch();
    return true;
  }

  /** Cash Counter plain-S selection navigation — runs before other bill key handlers. */
  private onCashSelectKeys(event: KeyboardEvent): boolean {
    if (!this.isCashCounterActive() || !this.shouldTrapSalesFocus()) return false;
    if (
      this.cameraOpen()
      || this.subOpen()
      || this.quickOpen()
      || this.patientHistoryModalOpen()
      || this.agtModalOpen()
      || this.cashDayOpen()
      || this.sendMenuIndex() !== null
    ) {
      return false;
    }

    const active = document.activeElement as HTMLElement | null;
    const key = event.key;

    if (!this.cashSelectMode()) {
      // Plain S only (Shift+S is handled by onShiftSToProductSearch).
      if ((key === 's' || key === 'S') && !event.shiftKey && !event.ctrlKey && !event.altKey && !event.metaKey) {
        // Allow S from empty inputs / product search; don't steal while typing a value.
        if (this.isTypingInField(active)) {
          const input = active as HTMLInputElement | HTMLTextAreaElement;
          if ((input.value ?? '').trim().length > 0) return false;
        }
        if (this.bill().lines.length === 0) return false;
        event.preventDefault();
        event.stopPropagation();
        this.enterCashSelectMode();
        return true;
      }
      return false;
    }

    // Selection mode active
    if (key === 'Escape') {
      event.preventDefault();
      event.stopPropagation();
      this.exitCashSelectMode();
      this.focusProductSearch();
      return true;
    }
    if ((key === 's' || key === 'S') && !event.shiftKey && !event.ctrlKey && !event.altKey && !event.metaKey && !this.isTypingInField(active)) {
      event.preventDefault();
      event.stopPropagation();
      this.exitCashSelectMode();
      this.focusProductSearch();
      return true;
    }

    const n = this.bill().lines.length;
    if (n === 0) {
      this.exitCashSelectMode();
      return true;
    }

    if (key === 'ArrowDown' || key === 'ArrowUp') {
      event.preventDefault();
      event.stopPropagation();
      const cur = this.cashSelectFocus();
      const step = key === 'ArrowDown' ? 1 : -1;
      const next = (cur + step + n) % n;
      this.cashSelectFocus.set(next);
      this.focusCashSelectRow(next);
      return true;
    }
    if (key === 'Enter') {
      event.preventDefault();
      event.stopPropagation();
      this.toggleCashLineSelected(this.cashSelectFocus());
      return true;
    }
    if (key === ' ') {
      event.preventDefault();
      event.stopPropagation();
      this.toggleCashLineSelected(this.cashSelectFocus());
      return true;
    }
    return false;
  }

  onCustomerChange(id: number | null): void {
    const c = this.customers().find((x) => x.id === id);
    this.addingPatient.set(false);
    if (!c) {
      this.patchBill({
        customerId: id,
        patientName: '',
        contact: id == null ? this.bill().contact : '',
        address: '',
        email: '',
        isChronic: false,
        customerKnown: false,
      });
      this.prevCustomerRates.set([]);
      this.prevRatesLineIndex.set(null);
      return;
    }
    this.applyCustomer(c);
  }

  /** Retail patient dropdown: existing customer, walk-in, or + New patient. */
  onPatientSelect(id: number | null): void {
    if (id === this.NEW_PATIENT) {
      this.addingPatient.set(true);
      this.patchBill({
        customerId: null,
        patientName: '',
        customerKnown: false,
      });
      return;
    }
    this.addingPatient.set(false);
    if (id == null) {
      this.patchBill({
        customerId: null,
        patientName: '',
        customerKnown: false,
      });
      return;
    }
    this.onCustomerChange(id);
  }

  /** Wholesale customer dropdown: existing wholesale buyer or + New customer. */
  onWholesaleCustomerSelect(id: number | null): void {
    if (id === this.NEW_PATIENT) {
      this.wholesaleCustomerPopup.set(true);
      return;
    }
    this.addingPatient.set(false);
    if (id == null) {
      this.patchBill({
        customerId: null,
        patientName: '',
        contact: '',
        address: '',
        email: '',
        customerKnown: false,
        creditNoteNo: '',
        creditDeductionAmt: 0,
      });
      this.openCreditNotes.set([]);
      return;
    }
    this.onCustomerChange(id);
    const c = this.wholesaleCustomers().find((x) => x.id === id);
    if (c?.name) this.loadOpenCreditNotes(c.name);
  }

  closeWholesaleCustomerPopup(): void {
    this.wholesaleCustomerPopup.set(false);
  }

  /** New wholesale customer saved in the Customer Master popup — reload list and select it. */
  onWholesaleCustomerCreated(ev: { id: number; values: Record<string, unknown> }): void {
    this.wholesaleCustomerPopup.set(false);
    this.crud.list<{ id: number; name: string; phone?: string; address?: string; email?: string; is_chronic?: number; customer_type?: string }>('customers', { perPage: 500 })
      .subscribe((r) => {
        const v = ev.values;
        const name = String(v['name'] ?? '');
        const list = [...(r.data ?? [])];
        const id = ev.id || list.find((c) => c.name === name && c.customer_type === 'wholesale')?.id;
        if (id && !list.some((c) => c.id === id)) {
          list.push({
            id,
            name,
            phone: String(v['phone'] ?? ''),
            address: String(v['address'] ?? ''),
            email: String(v['email'] ?? ''),
            customer_type: 'wholesale',
          });
        }
        this.customers.set(list);
        if (id) this.onWholesaleCustomerSelect(id);
      });
  }

  loadOpenCreditNotes(party: string): void {
    this.patchBill({ creditNoteNo: '', creditDeductionAmt: 0 });
    this.openCreditNotes.set([]);
    const name = (party || '').trim();
    if (!name) return;
    this.creditNoteApi.openForParty(name).subscribe({
      next: (rows) => this.openCreditNotes.set(rows),
      error: () => this.openCreditNotes.set([]),
    });
  }

  onCreditNoteSelected(noteNo: string): void {
    const found = this.openCreditNotes().find((n) => n.note_no === noteNo);
    this.patchBill({
      creditNoteNo: noteNo || '',
      creditDeductionAmt: found ? found.amount : 0,
    });
  }

  private reloadCustomers(): void {
    this.crud.list<{ id: number; name: string; phone?: string; address?: string; email?: string; is_chronic?: number; customer_type?: string }>('customers', { perPage: 500 })
      .subscribe((r) => this.customers.set(r.data));
  }

  private customerMatchesPhone(c: { phone?: string | null }, contact: string): boolean {
    return formatPhone(String(c.phone ?? '')) === contact;
  }

  /** One row per distinct patient name on the mobile. */
  private dedupePatientOptions(rows: PhonePatientOption[]): PhonePatientOption[] {
    const byName = new Map<string, PhonePatientOption>();
    for (const row of rows) {
      const key = this.patientIdentityKey(row);
      if (!key || byName.has(key)) {
        continue;
      }
      byName.set(key, row);
    }
    return [...byName.values()];
  }

  private patientIdentityKey(c: { name: string; phone?: string; customer_type?: string }): string {
    return `${toUpper(c.name.trim())}|${formatPhone(c.phone ?? '')}|${(c.customer_type ?? 'retail').toLowerCase()}`;
  }

  private masterPatientOption(c: {
    id: number;
    name: string;
    phone?: string;
    address?: string;
    email?: string;
    is_chronic?: number;
    customer_type?: string;
  }): PhonePatientOption {
    return {
      selectKey: c.id,
      id: c.id,
      name: c.name,
      phone: c.phone ? formatPhone(String(c.phone)) : undefined,
      address: c.address,
      email: c.email,
      is_chronic: c.is_chronic,
      customer_type: c.customer_type,
      source: 'master',
    };
  }

  private mapPhoneLookupRow(m: {
    id?: number | null;
    name: string;
    phone?: string | null;
    address?: string | null;
    email?: string | null;
    is_chronic?: number | string | boolean | null;
    customer_type?: string | null;
    lookup_source?: string | null;
  }, index: number, clean: string): PhonePatientOption {
    const isHistory = (m.lookup_source ?? '') === 'sales_history' || m.id == null;
    const id = m.id != null && Number(m.id) > 0 ? Number(m.id) : null;
    return {
      selectKey: id ?? -(index + 1),
      id,
      name: m.name,
      phone: m.phone ? formatPhone(String(m.phone)) : clean,
      address: m.address ?? undefined,
      email: m.email ?? undefined,
      is_chronic: Number(m.is_chronic) === 1 ? 1 : 0,
      customer_type: m.customer_type ?? undefined,
      source: isHistory ? 'sales_history' : 'master',
    };
  }

  patientOptionLabel(c: PhonePatientOption): string {
    const type = (c.customer_type ?? '').trim().toLowerCase();
    const typeSuffix = type && type !== 'retail' ? ` (${type.toUpperCase()})` : '';
    const histSuffix = c.source === 'sales_history' ? ' · Past bill' : '';
    const phoneSuffix = c.phone ? ` · ${c.phone}` : '';
    return `${c.name}${typeSuffix}${histSuffix}${phoneSuffix}`;
  }

  private patientOptionMatchesQuery(p: PhonePatientOption, q: string, qDigits: string): boolean {
    const name = p.name.toLowerCase();
    const phone = (p.phone ?? '').replace(/\D/g, '');
    return name.includes(q) || (qDigits.length >= 2 && phone.includes(qDigits));
  }

  /** @deprecated use patientDropdownKey signal in template */
  patientDropdownValue(): number | null {
    return this.patientDropdownKey();
  }

  openPatientDropdown(ev?: Event): void {
    ev?.preventDefault();
    ev?.stopPropagation();
    this.showPatientDropdown.set(true);
  }

  onPatientSearchQuery(q: string): void {
    const upper = toUpper(q);
    this.patientSearchQuery.set(upper);
    this.patientActiveIndex.set(0);
    if (!upper.trim()) {
      this.showPatientDropdown.set(false);
      if (!this.bill().customerId) {
        this.applyTypedPatientName('');
      }
      return;
    }
    this.showPatientDropdown.set(true);
    this.applyTypedPatientName(upper);
  }

  /** Ensure bill.patientName matches the search box before validate/save. */
  private syncPatientFieldFromSearch(): void {
    const q = this.patientSearchQuery().trim();
    if (!q || !this.isRegularSale() || this.isWholesale()) {
      return;
    }
    this.applyTypedPatientName(q);
  }

  /** Retail: typed name in search = bill patient (saved on sale if new + mobile). */
  private applyTypedPatientName(q: string): void {
    if (!this.isRegularSale() || this.isWholesale()) {
      return;
    }
    const trimmed = q.trim();
    if (!trimmed) {
      if (!this.bill().customerId) {
        this.addingPatient.set(false);
        this.patchBill({ patientName: '', customerKnown: false });
      }
      return;
    }
    const cid = this.bill().customerId;
    if (cid != null && cid > 0) {
      const c = this.customers().find((x) => x.id === cid);
      if (c && c.name.toLowerCase() === trimmed.toLowerCase()) {
        return;
      }
    }

    this.addingPatient.set(false);
    this.phonePatientSelectKey.set(null);
    this.patchBill({
      customerId: null,
      patientName: toUpper(trimmed),
      customerKnown: false,
    });
  }

  private findPatientOptionExact(name: string): PhonePatientOption | null {
    const q = name.trim().toLowerCase();
    if (!q) {
      return null;
    }
    const fromFilter = this.filteredRetailPatientsForSelect().find((p) => p.name.toLowerCase() === q);
    if (fromFilter) {
      return fromFilter;
    }
    return this.retailPatientsForPhone().find((p) => p.name.toLowerCase() === q) ?? null;
  }

  onDoctorInput(value: string): void {
    this.set('doctor', value);
    this.doctorActiveIndex.set(-1);
    this.showDoctorDropdown.set(true);
  }

  onDoctorFocus(): void {
    clearTimeout(this.doctorBlurTimer);
    this.doctorActiveIndex.set(-1);
    this.showDoctorDropdown.set(true);
  }

  onDoctorBlur(): void {
    this.doctorBlurTimer = setTimeout(() => this.showDoctorDropdown.set(false), 150);
  }

  pickDoctor(name: string): void {
    clearTimeout(this.doctorBlurTimer);
    this.set('doctor', name.toUpperCase());
    this.showDoctorDropdown.set(false);
  }

  clearDoctor(): void {
    this.set('doctor', '');
    this.showDoctorDropdown.set(false);
  }

  openPrescriptionPicker(ev: Event): void {
    ev.preventDefault();
    const host = ev.target instanceof HTMLElement ? ev.target.closest('label') : null;
    const input = host?.querySelector('input[type="file"]') as HTMLInputElement | null;
    input?.click();
  }

  onDoctorKeydown(ev: KeyboardEvent): void {
    const list = this.filteredDoctors();
    if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
      ev.preventDefault();
      this.showDoctorDropdown.set(true);
      if (!list.length) return;
      const step = ev.key === 'ArrowDown' ? 1 : -1;
      const next = (this.doctorActiveIndex() + step + list.length) % list.length;
      this.doctorActiveIndex.set(next);
      this.scrollComboActive('.rb-span-doctor');
    } else if (ev.key === 'Enter' && this.showDoctorDropdown()) {
      const d = list[this.doctorActiveIndex()];
      if (d) {
        ev.preventDefault();
        this.pickDoctor(d.name);
      } else {
        this.showDoctorDropdown.set(false);
      }
    } else if (ev.key === 'Escape') {
      this.showDoctorDropdown.set(false);
    }
  }

  onPatientKeydown(ev: KeyboardEvent): void {
    const list = this.filteredRetailPatientsForSelect();
    if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
      ev.preventDefault();
      ev.stopPropagation();
      if (!this.patientSearchQuery().trim()) return;
      this.showPatientDropdown.set(true);
      if (!list.length) return;
      const step = ev.key === 'ArrowDown' ? 1 : -1;
      const next = (this.patientActiveIndex() + step + list.length) % list.length;
      this.patientActiveIndex.set(next);
      this.scrollComboActive('.patient-combo');
      return;
    }
    if (ev.key === 'Enter' && this.showPatientDropdown() && list.length) {
      const pick = list[this.patientActiveIndex()] ?? list[0];
      if (pick) {
        ev.preventDefault();
        ev.stopPropagation();
        this.pickPatientComboOption(pick.selectKey);
      }
      return;
    }
    if (ev.key === 'Escape') {
      this.showPatientDropdown.set(false);
    }
  }

  onPatientComboBlur(): void {
    setTimeout(() => {
      this.showPatientDropdown.set(false);
      const q = this.patientSearchQuery().trim();
      if (!q || !this.isRegularSale()) {
        return;
      }
      const exact = this.findPatientOptionExact(q);
      if (exact) {
        this.patientSearchQuery.set('');
        if (exact.id != null && exact.id > 0) {
          this.onCustomerChange(exact.id);
        } else {
          this.applyHistoryPatient(exact);
        }
        return;
      }
      this.applyTypedPatientName(q);
    }, 150);
  }

  onPatientComboFocus(ev: FocusEvent): void {
    if (this.patientSearchQuery().trim()) {
      this.showPatientDropdown.set(true);
    }
    const el = ev.target;
    if (el instanceof HTMLInputElement && el.hasAttribute('readonly')) {
      el.removeAttribute('readonly');
    }
  }

  clearPatientCombo(ev: Event): void {
    ev.stopPropagation();
    this.showPatientDropdown.set(false);
    this.patientSearchQuery.set('');
    this.phonePatientSelectKey.set(null);
    this.onPatientDropdownChange(null);
  }

  pickPatientComboOption(key: number | null): void {
    this.showPatientDropdown.set(false);
    this.patientSearchQuery.set('');
    this.onPatientDropdownChange(key);
  }

  onPatientDropdownChange(key: number | null): void {
    if (key === this.NEW_PATIENT) {
      this.phonePatientSelectKey.set(null);
      this.onPatientSelect(this.NEW_PATIENT);
      return;
    }
    if (key == null) {
      this.phonePatientSelectKey.set(null);
      this.onPatientSelect(null);
      return;
    }

    const contact = formatPhone(this.bill().contact);
    if (contact.length === 10) {
      const opt = this.patientsOnPhone().find((p) => p.selectKey === key);
      if (opt) {
        this.phonePatientSelectKey.set(key);
        if (opt.source === 'sales_history' || opt.id == null) {
          this.applyHistoryPatient(opt);
        } else {
          this.onCustomerChange(opt.id);
        }
        return;
      }
    }

    this.onPatientSelect(key);
  }

  private applyHistoryPatient(p: PhonePatientOption): void {
    this.addingPatient.set(false);
    this.patchBill({
      customerId: null,
      patientName: toUpper(p.name),
      contact: formatPhone(p.phone ?? this.bill().contact),
      address: toUpper(p.address ?? ''),
      email: String(p.email ?? '').trim().toLowerCase(),
      isChronic: false,
      customerKnown: true,
    });
    this.schedulePatientPurchaseHistoryFetch(true);
  }

  onPatientContactBlur(): void {
    if (!this.canShowPatientHistory()) return;
    if (formatPhone(this.bill().contact).length >= 10) {
      this.schedulePatientPurchaseHistoryFetch(true);
    }
  }

  /** Ensure the phone-matched patient always has a dropdown option (even if missing from the paged cache). */
  private matchedBillPatient(b: Bill): PhonePatientOption | null {
    if (!b.customerKnown || b.customerId == null || b.customerId <= 0) {
      return null;
    }
    const id = Number(b.customerId);
    const fromCache = this.customers().find((c) => Number(c.id) === id);
    if (fromCache) {
      return this.masterPatientOption(fromCache);
    }
    if (!b.patientName.trim()) {
      return null;
    }
    return {
      selectKey: id,
      id,
      name: b.patientName,
      phone: formatPhone(b.contact) || undefined,
      address: b.address || undefined,
      email: b.email || undefined,
      is_chronic: b.isChronic ? 1 : 0,
      source: 'master',
    };
  }

  private upsertCustomerCache(c: {
    id: number;
    name: string;
    phone?: string | null;
    address?: string | null;
    email?: string | null;
    is_chronic?: number | string | boolean | null;
    customer_type?: string | null;
  }): void {
    const id = Number(c.id);
    const entry = {
      id,
      name: c.name,
      phone: c.phone ? formatPhone(String(c.phone)) : undefined,
      address: c.address ?? undefined,
      email: c.email ?? undefined,
      is_chronic: Number(c.is_chronic) === 1 ? 1 : 0,
      customer_type: c.customer_type ?? undefined,
    };
    this.customers.update((list) => {
      const idx = list.findIndex((x) => Number(x.id) === id);
      if (idx >= 0) {
        const next = [...list];
        next[idx] = { ...next[idx], ...entry };
        return next;
      }
      return [...list, entry];
    });
  }

  /** Fill bill fields from a customer master record. */
  private applyCustomer(c: {
    id: number;
    name: string;
    phone?: string | null;
    address?: string | null;
    email?: string | null;
    state?: string | null;
    is_chronic?: number | string | boolean | null;
    customer_type?: string | null;
  }): void {
    this.addingPatient.set(false);
    const branchState = (this.meta.state() ?? '').trim().toLowerCase();
    const custState = (c.state ?? '').trim().toLowerCase();
    const interstate = !!(custState && branchState && custState !== branchState);
    const phone = formatPhone(String(c.phone ?? this.bill().contact ?? ''));
    this.patchBill({
      customerId: Number(c.id),
      patientName: toUpper(c.name ?? ''),
      contact: phone,
      address: toUpper(c.address ?? ''),
      email: String(c.email ?? '').trim().toLowerCase(),
      isChronic: Number(c.is_chronic) === 1,
      customerKnown: true,
      isInterstate: this.isWholesale() ? interstate : this.bill().isInterstate,
    });
    if (formatPhone(this.bill().contact).length === 10) {
      this.phonePatientSelectKey.set(Number(c.id));
    }
    this.upsertCustomerCache({ ...c, phone: phone || c.phone });
    if (this.isWholesale()) {
      const idx = this.prevRatesLineIndex();
      if (idx != null) {
        const line = this.bill().lines[idx];
        if (line) {
          this.loadPrevCustomerRates(line.binding_id, idx);
        }
      } else {
        this.prevCustomerRates.set([]);
        this.prevRatesLineIndex.set(null);
      }
    } else {
      this.schedulePatientPurchaseHistoryFetch(true);
    }
  }

  private phoneLookupTimer?: ReturnType<typeof setTimeout>;

  /**
   * Retail: typing a 10-digit mobile loads all patients on that number.
   * Auto-fills only when exactly one match; otherwise user picks from the dropdown.
   */
  onContactChange(phone: string): void {
    const clean = formatPhone(phone);
    const prev = this.bill();
    const typedName = this.patientSearchQuery().trim();
    const keepManualName =
      prev.customerId == null &&
      (typedName || (prev.patientName.trim() && !prev.customerKnown));
    const nameToKeep = typedName || prev.patientName.trim();
    // Keep contact live; clear linked customer until phone lookup runs again.
    this.patchBill({
      contact: clean,
      customerKnown: false,
      customerId: null,
      patientName: keepManualName && nameToKeep ? toUpper(nameToKeep) : '',
      address: prev.customerId != null ? '' : prev.address,
      email: prev.customerId != null ? '' : prev.email,
      isChronic: false,
    });
    this.patientsOnPhone.set([]);
    this.phonePatientSelectKey.set(null);

    clearTimeout(this.phoneLookupTimer);
    clearTimeout(this.patientHistoryFetchTimer);
    this.patientHistoryAutoKey = '';
    if (clean.length < 10) {
      this.lookingUp.set(false);
      this.addingPatient.set(false);
      this.patientHistoryBills.set([]);
      return;
    }

    this.lookingUp.set(true);
    this.phoneLookupTimer = setTimeout(() => {
      this.salesSvc.lookupCustomersByPhone(clean).subscribe({
        next: (matches) => {
          this.lookingUp.set(false);
          if (this.bill().contact !== clean) return;
          this.resolvePhoneMatches(matches, clean, prev);
          if (!this.isWholesale()) {
            this.refreshAgtPendingForBill(false);
          }
        },
        error: () => this.lookingUp.set(false),
      });
    }, 150);
  }

  private resolvePhoneMatches(
    matches: {
      id?: number | null;
      name: string;
      phone?: string | null;
      address?: string | null;
      email?: string | null;
      state?: string | null;
      is_chronic?: number | string | boolean | null;
      customer_type?: string | null;
      lookup_source?: string | null;
    }[],
    clean: string,
    prev: Bill,
  ): void {
    const unique = this.dedupePatientOptions(
      matches.map((m, index) => this.mapPhoneLookupRow(m, index, clean)),
    );
    unique.filter((m) => m.id != null).forEach((m) => this.upsertCustomerCache({
      id: m.id as number,
      name: m.name,
      phone: m.phone,
      address: m.address,
      email: m.email,
      is_chronic: m.is_chronic,
      customer_type: m.customer_type,
    }));
    this.patientsOnPhone.set(unique);

    if (unique.length === 1) {
      const only = unique[0];
      this.phonePatientSelectKey.set(only.selectKey);
      if (only.source === 'sales_history' || only.id == null) {
        this.applyHistoryPatient(only);
      } else {
        this.applyCustomer({
          id: only.id as number,
          name: only.name,
          phone: only.phone,
          address: only.address,
          email: only.email,
          is_chronic: only.is_chronic,
          customer_type: only.customer_type,
        });
      }
            return;
          }

    if (unique.length > 1) {
      this.addingPatient.set(false);
      this.phonePatientSelectKey.set(null);
            this.patchBill({
        contact: clean,
              customerId: null,
              patientName: '',
              address: '',
              email: '',
              isChronic: false,
        customerKnown: true,
      });
      return;
    }

    // Unknown mobile → new patient; keep name typed in Patient Name search.
    this.patientsOnPhone.set([]);
    const typed = this.patientSearchQuery().trim() || prev.patientName.trim();
    this.addingPatient.set(!!typed);
    if (prev.customerId != null || typed) {
      this.patchBill({
        customerId: null,
        patientName: typed ? toUpper(typed) : '',
        address: '',
        email: '',
        isChronic: false,
        customerKnown: false,
      });
    }
  }

  contactError(): string | null {
    return phoneError(this.bill().contact, false);
  }

  emailFieldError(): string | null {
    return emailError(this.bill().email, false);
  }

  addressFieldError(): string | null {
    return addressError(this.bill().address, false);
  }

  reminderDaysFieldError(): string | null {
    return reminderDaysError(this.bill().reminderDays, false);
  }

  // ---- search ----
  loadInitial(): void {
    this.salesSvc.searchProducts('').subscribe((r) => {
      this.searchResults.set(r);
      this.highlight.set(0);
    });
  }
  private runSearch(): void {
    this.searching.set(true);
    this.salesSvc.searchProducts(this.searchTerm(), this.selectedGeneric() ?? undefined).subscribe({
      next: (r) => {
        this.searchResults.set(r);
        this.highlight.set(0);
        this.searching.set(false);
      },
      error: () => this.searching.set(false),
    });
  }
  onSearch(value: string): void {
    this.searchTerm.set(value);
    clearTimeout(this.searchTimer);
    this.searchTimer = setTimeout(() => this.runSearch(), 180);
  }
  onGenericChange(id: number | null): void {
    this.selectedGeneric.set(id);
    this.substituteMode.set(id !== null);
    if (id == null) {
      this.genericSearch.set('');
    } else {
      const match = this.generics().find((g) => g.id === id);
      if (match) this.genericSearch.set(match.name);
    }
    this.showGenericDropdown.set(false);
    this.runSearch();
  }

  onGenericSearchInput(value: string): void {
    this.genericSearch.set(value);
    this.genericActiveIndex.set(0);
    this.showGenericDropdown.set(true);
    // Typing a new query clears a previous pick until they choose again.
    if (this.selectedGeneric() != null) {
      const current = this.generics().find((g) => g.id === this.selectedGeneric());
      if (!current || current.name.toLowerCase() !== value.trim().toLowerCase()) {
        this.selectedGeneric.set(null);
        this.substituteMode.set(false);
      }
    }
  }

  openGenericDropdown(): void {
    this.showGenericDropdown.set(true);
  }

  onGenericBlur(): void {
    // Allow mousedown on a list item before the dropdown closes.
    setTimeout(() => {
      this.showGenericDropdown.set(false);
      const id = this.selectedGeneric();
      if (id != null) {
        const match = this.generics().find((g) => g.id === id);
        if (match) this.genericSearch.set(match.name);
      } else if (this.genericSearch().trim()) {
        // Keep typed text so user can reopen and refine; don't force-clear.
      }
    }, 150);
  }

  selectGenericOption(g: { id: number; name: string }): void {
    this.genericSearch.set(g.name);
    this.onGenericChange(g.id);
  }

  onGenericSearchKey(event: KeyboardEvent): void {
    const list = this.filteredGenerics();
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      event.stopPropagation();
      this.showGenericDropdown.set(true);
      if (!list.length) return;
      const step = event.key === 'ArrowDown' ? 1 : -1;
      const next = (this.genericActiveIndex() + step + list.length) % list.length;
      this.genericActiveIndex.set(next);
      this.scrollComboActive('.pp-generic-select');
      return;
    }
    if (event.key === 'Escape') {
      event.preventDefault();
      this.showGenericDropdown.set(false);
      return;
    }
    if (event.key === 'Enter') {
      const pick = list[this.genericActiveIndex()] ?? list[0];
      if (pick) {
        event.preventDefault();
        this.selectGenericOption(pick);
      }
    }
  }

  private scrollComboActive(panelSelector: string): void {
    queueMicrotask(() => {
      const root = this.host.nativeElement as HTMLElement;
      const el = root.querySelector(
        `${panelSelector} .combo-item.active-item, ${panelSelector} li.active`,
      ) as HTMLElement | null;
      el?.scrollIntoView({ block: 'nearest' });
    });
  }

  clearGeneric(): void {
    this.selectedGeneric.set(null);
    this.substituteMode.set(false);
    this.genericSearch.set('');
    this.showGenericDropdown.set(false);
    this.runSearch();
  }

  clearSearch(): void {
    this.searchTerm.set('');
    this.searchResults.set([]);
    this.highlight.set(0);
    clearTimeout(this.searchTimer);
  }

  /** Keyboard navigation on the search box: Up/Down to move, Enter to add. */
  onSearchKey(event: KeyboardEvent): void {
    const n = this.searchResults().length;
    if (n === 0) return;
    if (event.key === 'ArrowDown') {
      this.highlight.update((i) => Math.min(i + 1, n - 1));
      event.preventDefault();
    } else if (event.key === 'ArrowUp') {
      this.highlight.update((i) => Math.max(i - 1, 0));
      event.preventDefault();
    } else if (event.key === 'Enter') {
      const p = this.searchResults()[this.highlight()];
      if (p) {
        if (this.needsRestock(p)) this.openQuickAddForProduct(p);
        else this.selectProduct(p);
      }
      event.preventDefault();
    } else if (event.key === 'Escape') {
      this.searchResults.set([]);
    }
  }

  /** Expired or zero available stock — offer counter stock-in instead of sell. */
  needsRestock(p: SaleProduct): boolean {
    return !!p.is_expired || (Number(p.available_qty) || 0) <= 0;
  }

  // ---- quick counter stock-in (product not yet purchased) ----
  readonly quickOpen = signal(false);
  readonly quickSaving = signal(false);
  readonly quickError = signal<string | null>(null);
  readonly quickResults = signal<MasterProduct[]>([]);
  readonly quickHighlight = signal(0);
  private quickTimer?: ReturnType<typeof setTimeout>;
  readonly quick = signal<QuickStock>(emptyQuick());

  // Product is in master only when a master id is set; otherwise we collect
  // the full master fields so a proper catalog entry is created.
  readonly quickIsNew = computed(() => !this.quick().masterId && this.quick().name.trim().length > 0);
  readonly quickPackOptions = computed(() => {
    const cur = this.normalizeQuickPack(this.quick().packSize);
    const base = QUICK_PACK_OPTIONS.map((p) => this.normalizeQuickPack(p));
    if (cur && !base.some((p) => p.toUpperCase() === cur.toUpperCase())) {
      return [cur, ...base];
    }
    return base;
  });
  readonly quickPackCustom = signal(false);
  /** Shift+A only → open Add Purchase. Lone A / Tab+A do not open it. */
  private onQuickAddHotkey(event: KeyboardEvent): boolean {
    if (event.key !== 'a' && event.key !== 'A') return false;
    if (!event.shiftKey || event.ctrlKey || event.altKey || event.metaKey) return false;
    if (!this.shouldTrapSalesFocus()) return false;
    if (this.quickOpen() || this.cameraOpen() || this.subOpen() || this.cashDayOpen() || this.agtModalOpen()) {
      return false;
    }
    event.preventDefault();
    event.stopPropagation();
    const highlighted = this.searchResults()[this.highlight()];
    if (highlighted && this.needsRestock(highlighted)) {
      this.openQuickAddForProduct(highlighted);
    } else {
      this.openQuickAdd();
    }
    return true;
  }

  openQuickAdd(): void {
    this.quickError.set(null);
    this.quickResults.set([]);
    this.quickHighlight.set(0);
    this.quickPackCustom.set(false);
    const name = this.searchTerm().trim();
    const packHint = this.packFromProductName(name);
    this.quick.set({
      ...emptyQuick(),
      name,
      qty: null,
      packSize: this.normalizeQuickPack(packHint?.packSize ?? ''),
      unitsPerPack: packHint?.unitsPerPack ?? 1,
    });
    this.quickOpen.set(true);
    queueMicrotask(() => this.focusQuickAddModal());
    if (name.length >= 2) this.runQuickSearch(true);
  }

  /** Open quick purchase for an expired / out-of-stock product from the search list. */
  openQuickAddForProduct(p: SaleProduct, event?: Event): void {
    event?.preventDefault();
    event?.stopPropagation();
    this.quickError.set(null);
    this.quickResults.set([]);
    this.quickHighlight.set(0);
    this.quickPackCustom.set(false);
    const packHint = this.packFromProductName(p.product_name);
    const packSize = this.normalizeQuickPack(p.pack_size || packHint?.packSize || '');
    const units = Math.max(
      1,
      Number(p.units_per_pack) || packHint?.unitsPerPack || this.unitsFromPackLabel(packSize) || 1,
    );
    this.quick.set({
      ...emptyQuick(),
      masterId: p.master_product_id || null,
      name: p.product_name,
      qty: null,
      gst: Number(p.gst_rate) || 12,
      mrp: Number(p.mrp) > 0 ? Number(p.mrp) : 0,
      hsn: p.hsn_code || '',
      unitsPerPack: units,
      packSize: packSize || (units > 1 ? `1x${units}` : ''),
      manufacturer: p.manufacturer || '',
      manufacturerShort: p.manufacturer_short || '',
      schedule: p.schedule_category || 'OTC',
      category: p.product_category || 'Allopathy',
    });
    if (packSize && !QUICK_PACK_OPTIONS.some((o) => this.normalizeQuickPack(o).toUpperCase() === packSize.toUpperCase())) {
      this.quickPackCustom.set(true);
    }
    this.quickOpen.set(true);
    queueMicrotask(() => this.focusQuickAddModal());
    if (p.binding_id) {
      this.fillQuickFromBinding(p.binding_id);
    } else if (p.master_product_id) {
      this.fillQuickFromBranchStock(p.product_name, p.master_product_id);
    } else if (p.product_name.trim().length >= 2) {
      this.runQuickSearch(true);
    }
  }

  private focusQuickAddModal(): void {
    this.focusWhenReady('.quick-add-card .quick-add-body input.form-control');
  }

  private quickAddFocusables(): HTMLElement[] {
    const card = this.host.nativeElement.querySelector('.quick-add-card') as HTMLElement | null;
    if (!card) return [];
    const nodes = card.querySelectorAll(
      'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
    );
    return Array.from(nodes).filter((node): node is HTMLElement => {
      if (!(node instanceof HTMLElement)) return false;
      if (node.tabIndex < 0) return false;
      if (node.getAttribute('aria-hidden') === 'true') return false;
      return node.getClientRects().length > 0;
    });
  }

  /** True while any nested sales popup / dropdown should own Esc (stay on bill). */
  private hasSalesOverlayOpen(): boolean {
    return (
      this.sendMenuIndex() !== null
      || this.patientHistoryModalOpen()
      || this.wholesaleCustomerPopup()
      || this.quickOpen()
      || this.agtModalOpen()
      || this.cameraOpen()
      || this.subOpen()
      || this.cashDayOpen()
      || !!this.bill().wsDraft
      || this.cashSelectMode()
      || this.showPatientDropdown()
      || this.showDoctorDropdown()
      || this.showGenericDropdown()
      || this.searchResults().length > 0
      || !!this.completed()
    );
  }

  /**
   * Esc closes the topmost nested popup and stays on the sales form
   * (never returns to dashboard while a popup is open).
   */
  private onSalesOverlayEscape(_event: KeyboardEvent): boolean {
    if (this.sendMenuIndex() !== null) {
      this.closeSendMenu();
      return true;
    }
    if (this.patientHistoryModalOpen()) {
      this.closePatientHistoryModal();
      queueMicrotask(() => this.focusWhenReady('.patient-combo input, input[data-bill-tab="patient"]'));
      return true;
    }
    if (this.wholesaleCustomerPopup()) {
      this.closeWholesaleCustomerPopup();
      return true;
    }
    if (this.quickOpen()) {
      if (this.quickResults().length) {
        this.quickResults.set([]);
        this.quickHighlight.set(0);
        return true;
      }
      this.closeQuickAdd();
      queueMicrotask(() => this.focusProductSearch());
      return true;
    }
    if (this.agtModalOpen()) {
      this.closeAgtModal();
      return true;
    }
    if (this.cameraOpen()) {
      this.closeCamera();
      return true;
    }
    if (this.subOpen()) {
      this.subOpen.set(false);
      return true;
    }
    if (this.cashDayOpen()) {
      this.closeCashCounterDayReport();
      return true;
    }
    if (this.bill().wsDraft) {
      this.clearWholesaleDraft();
      return true;
    }
    if (this.cashSelectMode()) {
      this.exitCashSelectMode();
      queueMicrotask(() => this.focusProductSearch());
      return true;
    }
    if (this.showPatientDropdown()) {
      this.showPatientDropdown.set(false);
      return true;
    }
    if (this.showDoctorDropdown()) {
      this.showDoctorDropdown.set(false);
      return true;
    }
    if (this.showGenericDropdown()) {
      this.showGenericDropdown.set(false);
      return true;
    }
    if (this.searchResults().length > 0) {
      this.searchResults.set([]);
      return true;
    }
    if (this.completed()) {
      this.newBill();
      return true;
    }
    return false;
  }

  /** Tab stays inside quick-add popup. Esc is handled by onSalesOverlayEscape. */
  private onQuickAddFocusTrap(event: KeyboardEvent): boolean {
    if (!this.quickOpen()) return false;
    if (event.key !== 'Tab' || event.ctrlKey || event.altKey || event.metaKey) return false;
    const items = this.quickAddFocusables();
    if (items.length === 0) return true;
    const active = document.activeElement as HTMLElement | null;
    const first = items[0];
    const last = items[items.length - 1];
    const inside = !!active && items.includes(active);
    event.preventDefault();
    event.stopPropagation();
    if (!inside) {
      (event.shiftKey ? last : first).focus({ preventScroll: true });
      return true;
    }
    if (event.shiftKey) {
      const idx = items.indexOf(active!);
      (idx <= 0 ? last : items[idx - 1]).focus({ preventScroll: true });
    } else {
      const idx = items.indexOf(active!);
      (idx < 0 || idx >= items.length - 1 ? first : items[idx + 1]).focus({ preventScroll: true });
    }
    return true;
  }

  onQuickPackSelect(value: string): void {
    if (value === '__custom__') {
      this.quickPackCustom.set(true);
      return;
    }
    this.quickPackCustom.set(false);
    this.setQuick('packSize', this.normalizeQuickPack(value));
  }

  /** Normalize pack labels for dropdown + API (1 X 10 → 1x10). */
  private normalizeQuickPack(raw: string): string {
    const t = String(raw || '').trim();
    if (!t) return '';
    const spaced = t.replace(/\s*[xX×*]\s*/g, 'x').replace(/\s+/g, ' ').trim();
    const m = spaced.match(/^(\d+)\s*[xX]\s*(\d+)$/i) || spaced.match(/^(\d+)x(\d+)$/i);
    if (m) return `${Number(m[1])}x${Number(m[2])}`;
    return spaced.toUpperCase();
  }
  setQuick<K extends keyof QuickStock>(field: K, value: QuickStock[K]): void {
    let v = value;
    if (field === 'qty') {
      if (v === null || v === ('' as unknown as QuickStock[K]) || !Number.isFinite(Number(v))) {
        v = null as QuickStock[K];
      } else {
        v = Number(v) as QuickStock[K];
      }
    }
    if (typeof v === 'string') {
      if (field === 'hsn') v = formatHsn(v) as QuickStock[K];
      else if (field === 'packSize') v = this.normalizeQuickPack(v) as QuickStock[K];
      else if (
        field === 'name' ||
        field === 'batch' ||
        field === 'manufacturer' ||
        field === 'manufacturerShort'
      ) {
        v = toUpper(v) as QuickStock[K];
      }
    }
    this.quick.update((q) => {
      const next = { ...q, [field]: v };
      if (field === 'packSize' && typeof v === 'string') {
        const parsed = this.unitsFromPackLabel(v);
        if (parsed > 0) next.unitsPerPack = parsed;
      }
      if (field === 'name' && typeof v === 'string' && !q.masterId) {
        const hint = this.packFromProductName(v);
        if (hint && (!q.packSize || q.packSize === '1x1' || q.unitsPerPack <= 1)) {
          next.packSize = hint.packSize;
          next.unitsPerPack = hint.unitsPerPack;
        }
      }
      return next;
    });
  }

  closeQuickAdd(): void {
    this.quickOpen.set(false);
    this.quickResults.set([]);
    this.quickHighlight.set(0);
    this.quickPackCustom.set(false);
  }

  /** Typing the product name searches the master so an existing item can be reused. */
  onQuickName(value: string): void {
    const name = toUpper(value);
    this.quick.update((q) => {
      const hint = this.packFromProductName(name);
      return {
        ...q,
        name,
        masterId: null,
        packSize: hint?.packSize || q.packSize,
        unitsPerPack: hint?.unitsPerPack || q.unitsPerPack,
      };
    });
    clearTimeout(this.quickTimer);
    this.quickHighlight.set(0);
    if (name.trim().length < 2) { this.quickResults.set([]); return; }
    this.quickTimer = setTimeout(() => this.runQuickSearch(false), 220);
  }

  /** Arrow / Enter on Product Name — move through master matches and pick with Enter. */
  onQuickNameKey(event: KeyboardEvent): void {
    const list = this.quickResults();
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      if (!list.length) return;
      event.preventDefault();
      event.stopPropagation();
      const n = list.length;
      const step = event.key === 'ArrowDown' ? 1 : -1;
      const next = (this.quickHighlight() + step + n) % n;
      this.quickHighlight.set(next);
      this.scrollQuickActive();
      return;
    }
    if (event.key === 'Enter') {
      const pick = list[this.quickHighlight()] ?? list[0];
      if (pick) {
        event.preventDefault();
        event.stopPropagation();
        this.pickQuickMaster(pick);
        queueMicrotask(() => this.focusWhenReady('.quick-add-card input[placeholder="Batch no"]'));
      }
      return;
    }
    if (event.key === 'Escape' && list.length) {
      event.preventDefault();
      event.stopPropagation();
      this.quickResults.set([]);
      this.quickHighlight.set(0);
    }
  }

  private scrollQuickActive(): void {
    queueMicrotask(() => {
      const el = this.host.nativeElement.querySelector(
        '.quick-dropdown .quick-item.active',
      ) as HTMLElement | null;
      el?.scrollIntoView({ block: 'nearest' });
    });
  }

  private runQuickSearch(autoPickExact: boolean): void {
    const qName = this.quick().name.trim();
    if (qName.length < 2) return;
    this.salesSvc.searchMaster(qName).subscribe((r) => {
      const sorted = [...r].sort((a, b) => (Number(b.id) || 0) - (Number(a.id) || 0));
      this.quickResults.set(sorted);
      this.quickHighlight.set(0);
      if (!autoPickExact || !sorted.length) return;
      const exact = sorted.find((p) => p.name.trim().toUpperCase() === qName.toUpperCase())
        ?? (sorted.length === 1 ? sorted[0] : null);
      if (exact) this.pickQuickMaster(exact);
    });
  }

  /** Pick an existing master product and autofill stock + catalog fields (still editable). */
  pickQuickMaster(p: MasterProduct): void {
    const packHint = this.packFromProductName(p.name);
    const packSize = this.normalizeQuickPack(p.pack_size || packHint?.packSize || '');
    const units = Math.max(1, Number(p.units_per_pack) || packHint?.unitsPerPack || this.unitsFromPackLabel(packSize) || 1);
    this.quick.update((q) => ({
      ...q,
      masterId: p.id,
      name: p.name,
      gst: Number(p.default_gst_rate) || q.gst || 12,
      mrp: Number(p.mrp) > 0 ? Number(p.mrp) : q.mrp,
      hsn: p.hsn_code || q.hsn,
      unitsPerPack: units,
      packSize: packSize || (units > 1 ? `1x${units}` : q.packSize),
      manufacturer: p.manufacturer || q.manufacturer,
      manufacturerShort: p.manufacturer_short || q.manufacturerShort,
      schedule: p.schedule_category || q.schedule,
      category: p.product_category || q.category || 'Allopathy',
    }));
    this.quickPackCustom.set(
      !!packSize && !QUICK_PACK_OPTIONS.some((o) => this.normalizeQuickPack(o).toUpperCase() === packSize.toUpperCase()),
    );
    this.quickResults.set([]);
    this.quickHighlight.set(0);
    this.fillQuickFromBranchStock(p.name, p.id);
  }

  /** Prefill batch / expiry / rates from last branch stock for this product (editable). */
  private fillQuickFromBranchStock(name: string, masterId: number): void {
    this.salesSvc.searchProducts(name).subscribe({
      next: (products) => {
        const match =
          products.find((x) => Number(x.master_product_id) === masterId)
          ?? products.find((x) => (x.product_name || '').trim().toUpperCase() === name.trim().toUpperCase())
          ?? products[0];
        if (!match) return;
        this.quick.update((q) => ({
          ...q,
          gst: q.gst || Number(match.gst_rate) || 12,
          mrp: q.mrp > 0 ? q.mrp : (Number(match.mrp) || 0),
          hsn: q.hsn || match.hsn_code || '',
          packSize: q.packSize || this.normalizeQuickPack(match.pack_size || ''),
          unitsPerPack: q.unitsPerPack > 1 ? q.unitsPerPack : Math.max(1, Number(match.units_per_pack) || 1),
          manufacturer: q.manufacturer || match.manufacturer || '',
          manufacturerShort: q.manufacturerShort || match.manufacturer_short || '',
          schedule: q.schedule || match.schedule_category || 'OTC',
          category: q.category || match.product_category || 'Allopathy',
        }));
        if (!match.binding_id) return;
        this.fillQuickFromBinding(match.binding_id);
      },
    });
  }

  /** Load last known batch (incl. expired) onto the quick-add form — all fields stay editable. */
  private fillQuickFromBinding(bindingId: number): void {
    this.salesSvc.batches(bindingId).subscribe({
      next: (batches) => {
        if (!batches?.length) return;
        const b = batches.find((x) => !x.is_expired && (x.quantity || 0) > 0)
          ?? batches.find((x) => !x.is_expired)
          ?? batches[0];
        if (!b) return;
        const month = this.expiryToMonthInput(b.expiry_date);
        this.quick.update((q) => ({
          ...q,
          batch: q.batch || b.batch_no || '',
          expiry: q.expiry || month,
          mrp: q.mrp > 0 ? q.mrp : (Number(b.pack_mrp) || Number(b.mrp) || 0),
          rate: q.rate != null && q.rate > 0
            ? q.rate
            : (Number(b.pack_purchase_rate) || Number(b.purchase_rate) || null),
          unitsPerPack: q.unitsPerPack > 1 ? q.unitsPerPack : Math.max(1, Number(b.units_per_pack) || 1),
        }));
      },
    });
  }

  /** e.g. ALMOX 250 1*15CAP → 1x15 / 15 */
  private packFromProductName(name: string): { packSize: string; unitsPerPack: number } | null {
    const n = String(name || '').toUpperCase().replace(/\*/g, 'X').replace(/×/g, 'X');
    const m = n.match(/(\d+)\s*X\s*(\d+)/);
    if (!m) return null;
    const a = Number(m[1]);
    const b = Number(m[2]);
    if (a === 1 && b >= 2 && b <= 500) {
      return { packSize: `1x${b}`, unitsPerPack: b };
    }
    if (a >= 1 && a <= 20 && b >= 2 && b <= 500) {
      return { packSize: `${a}x${b}`, unitsPerPack: b };
    }
    return null;
  }

  private unitsFromPackLabel(pack: string): number {
    const s = String(pack || '').toUpperCase().replace(/\s+/g, '');
    const m = s.match(/^(\d+)X(\d+)/);
    if (m) {
      const a = Number(m[1]);
      const b = Number(m[2]);
      return b > 1 ? b : (a > 1 ? a : 1);
    }
    const n = s.match(/^(\d+)/);
    return n ? Math.max(1, Number(n[1])) : 0;
  }

  private expiryToMonthInput(raw: string | null | undefined): string {
    if (!raw) return '';
    const t = String(raw).trim();
    if (/^\d{4}-\d{2}/.test(t)) return t.slice(0, 7);
    const d = new Date(t);
    if (!Number.isNaN(d.getTime())) {
      return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
    }
    return '';
  }

  saveQuickAdd(): void {
    const q = this.quick();
    if (!q.name.trim()) { this.quickError.set('Product name is required.'); return; }
    if (!q.batch.trim()) { this.quickError.set('Batch No is required.'); return; }
    const qty = Number(q.qty);
    if (!Number.isFinite(qty) || qty <= 0) {
      this.quickError.set('Quantity must be greater than zero.');
      return;
    }
    if (!(Number(q.mrp) > 0)) { this.quickError.set('MRP must be greater than zero.'); return; }
    if (q.rate == null || !(Number(q.rate) > 0)) {
      this.quickError.set('Purchase Rate is required.');
      return;
    }
    if (!String(q.expiry || '').trim()) { this.quickError.set('Expiry is required.'); return; }
    let pack = this.normalizeQuickPack(q.packSize);
    if (!pack && q.unitsPerPack > 1) pack = `1x${q.unitsPerPack}`;
    if (!pack) {
      this.quickError.set('Pack size is required.');
      return;
    }
    if (q.hsn.trim() && !/^\d{4,8}$/.test(q.hsn.trim())) {
      this.quickError.set('HSN must be 4 to 8 digits.');
      return;
    }
    const units = Math.max(1, q.unitsPerPack || this.unitsFromPackLabel(pack) || 1);
    this.quickSaving.set(true);
    this.quickError.set(null);
    this.salesSvc.quickPurchase({
      master_product_id: q.masterId,
      product_name: q.name.trim(),
      batch_no: q.batch.trim(),
      quantity: qty,
      mrp: q.mrp,
      purchase_rate: Number(q.rate),
      gst_rate: q.gst,
      expiry_date: q.expiry.trim(),
      // Master fields used only when creating a new catalog product.
      manufacturer: q.manufacturer || undefined,
      manufacturer_short: q.manufacturerShort || undefined,
      schedule_category: q.schedule,
      product_category: q.category,
      hsn_code: q.hsn || undefined,
      pack_size: pack,
      units_per_pack: units,
    }).subscribe({
      next: (product) => {
        this.quickSaving.set(false);
        this.closeQuickAdd();
        if (product?.binding_id) {
          this.selectProduct(product);
        }
        this.searchTerm.set('');
        this.searchResults.set([]);
        queueMicrotask(() => this.focusProductSearch());
      },
      error: (err: HttpErrorResponse) => {
        this.quickSaving.set(false);
        const errors = err.error?.errors;
        const first = errors ? (Object.values(errors)[0] as string[])?.[0] : null;
        this.quickError.set(first ?? err.error?.message ?? 'Could not add stock.');
      },
    });
  }

  // ---- barcode scan (TSC / wedge scanner → auto fetch + add to bill) ----
  readonly scanCode = signal('');
  readonly scanError = signal<string | null>(null);
  readonly scanOk = signal<string | null>(null);
  readonly scanBusy = signal(false);
  private scanDebounce: ReturnType<typeof setTimeout> | null = null;

  /**
   * Barcode wedge: only capture when focus is NOT in a form typing field.
   * Digits in contact / email / address / reminder / qty / disc / search must
   * never be redirected to /sales/scan.
   */
  private wedgeBuf = '';
  private lastDigitAt = 0;
  private wedgeFlushTimer: ReturnType<typeof setTimeout> | null = null;
  private readonly wedgeFlushMs = 90;
  private pendingScanCode: string | null = null;
  private scanBusyWatchdog: ReturnType<typeof setTimeout> | null = null;
  private readonly onWedgeCapture = (e: KeyboardEvent): void => this.handleWedgeCapture(e);

  /** Capture-phase Esc so nested popups close before main-layout closes the POS. */
  private readonly onSalesEscapeCapture = (e: KeyboardEvent): void => {
    if (e.key !== 'Escape') return;
    if (!this.shouldTrapSalesFocus()) return;
    if (this.onSalesOverlayEscape(e)) {
      e.preventDefault();
      e.stopPropagation();
      e.stopImmediatePropagation();
    }
  };

  /** Capture-phase Shift+S so Product Search wins over typing fields / cash-S / wedge. */
  private readonly onSalesShortcutCapture = (e: KeyboardEvent): void => {
    if (this.onShiftSToProductSearch(e)) {
      e.stopImmediatePropagation();
    }
  };

  @HostListener('document:mousedown', ['$event'])
  onDocumentMouseDown(ev: MouseEvent): void {
    const t = ev.target as HTMLElement | null;
    if (!t?.closest('.patient-combo')) {
      this.showPatientDropdown.set(false);
    }
    if (!t?.closest('.line-send, .line-send-menu')) {
      this.closeSendMenu();
    }
  }

  @HostListener('window:resize')
  onWindowResize(): void {
    this.closeSendMenu();
  }

  ngOnInit(): void {
    this.refreshStaleDraftBillDates();
    window.addEventListener('keydown', this.onWedgeCapture, true);
    document.addEventListener('keydown', this.onSalesEscapeCapture, true);
    document.addEventListener('keydown', this.onSalesShortcutCapture, true);
  }

  ngAfterViewInit(): void {
    queueMicrotask(() => this.focusProductSearch());
  }

  /**
   * Keep Tab / Shift+Tab inside the sales form (sidebar and top bar stay out of the cycle).
   * Retail uses ONE linear focus ring (buildRetailTabRing) — no competing section traps.
   */
  /** Header fields before the product grid (Chronic is last → next is Batch/Qty). */
  private readonly retailHeaderTabKeys = [
    'patient',
    'contact',
    'saleType',
    'payment',
    'account',
    'cashAmount',
    'bulkDisc',
    'address',
    'doctor',
    'prescription',
    'camera',
    'reminder',
    'narration',
    'email',
    'chronic',
  ] as const;

  @HostListener('document:keydown', ['$event'])
  onSalesFocusTrap(event: KeyboardEvent): void {
    // Esc / Shift+S are handled in capture phase.
    if (this.onQuickAddFocusTrap(event)) return;
    if (this.onQuickAddHotkey(event)) return;
    if (this.onCashSelectKeys(event)) return;
    this.onSalesCounterArrows(event);
    if (!this.cashSelectMode()) {
      this.onProductRowArrows(event);
    }
    if (event.key !== 'Tab' || event.ctrlKey || event.altKey || event.metaKey) return;
    if (!this.shouldTrapSalesFocus()) return;

    // Retail: single ring — Chronic → Batch → Qty → … → Save → Close → Search.
    if (!this.isWholesale()) {
      this.moveAlongRetailTabRing(event);
      return;
    }

    if (this.moveSalesSectionTab(event)) return;

    const items = this.salesFocusables();
    if (items.length === 0) return;

    const active = document.activeElement as HTMLElement | null;
    const root = this.host.nativeElement as HTMLElement;
    const inside = !!active && root.contains(active);
    const first = items[0];
    const last = items[items.length - 1];

    if (!inside) {
      event.preventDefault();
      (event.shiftKey ? last : first).focus({ preventScroll: true });
      return;
    }
    if (event.shiftKey && active === first) {
      event.preventDefault();
      last.focus({ preventScroll: true });
    } else if (!event.shiftKey && active === last) {
      event.preventDefault();
      first.focus({ preventScroll: true });
    }
  }

  /** If focus leaves the bill (Close → AuraAI / other chrome), put it back on the form. */
  @HostListener('document:focusin', ['$event'])
  onSalesFocusLeave(event: FocusEvent): void {
    if (!this.shouldTrapSalesFocus()) return;
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;
    if (this.quickOpen()) {
      const card = this.host.nativeElement.querySelector('.quick-add-card');
      if (card && !card.contains(target)) {
        this.focusQuickAddModal();
      }
      return;
    }
    if (this.host.nativeElement.contains(target)) return;
    if (target === document.body || target === document.documentElement) return;
    // After batch/qty re-render, restore the line — never yank to Product Search mid-edit.
    if (!this.isWholesale() && this.lastRetailLineFocusIndex != null) {
      const rows = this.host.nativeElement.querySelectorAll('tr.bill-line-row');
      const row = rows[this.lastRetailLineFocusIndex] as HTMLElement | undefined;
      const resume =
        (row?.querySelector('.g-input-qty') as HTMLElement | null)
        ?? (row?.querySelector('select.g-input-batch') as HTMLElement | null);
      if (resume) {
        resume.focus({ preventScroll: true });
        return;
      }
    }
    this.focusProductSearch();
  }

  private shouldTrapSalesFocus(): boolean {
    if (this.posOverlay()) return this.posModal.open();
    return !this.posModal.open();
  }

  /** Enter added a product; the next Tab leaves the product list for customer details. */
  private tabToCustomerAfterAdd = false;

  private focusProductSearch(): void {
    this.focusWhenReady('.pp-search-field input');
  }

  private focusWhenReady(selector: string, left = 12): void {
    const el = this.host.nativeElement.querySelector(selector) as HTMLElement | null;
    if (el) {
      if (document.activeElement !== el) el.focus({ preventScroll: true });
      return;
    }
    if (left > 0) setTimeout(() => this.focusWhenReady(selector, left - 1), 40);
  }

  /**
   * Section cycle: add product → customer details → product line → close → add product.
   * Field-by-field moves inside a section stay on the normal Tab path.
   */
  private moveSalesSectionTab(event: KeyboardEvent): boolean {
    const root = this.host.nativeElement as HTMLElement;
    const active = document.activeElement as HTMLElement | null;
    if (!active || !root.contains(active)) return false;

    const search = root.querySelector('.pp-search-field input') as HTMLElement | null;
    const addCards = this.addProductCards(root);
    const customer = this.sectionFocusables(root.querySelector('.details-card'));
    const product = this.productLineFocusables(root);
    const close = this.closeFocusables(root);
    const card = root.querySelector('.grid-card');

    const go = (el: HTMLElement | null | undefined): boolean => {
      if (!el) return false;
      event.preventDefault();
      event.stopPropagation();
      el.focus({ preventScroll: true });
      return true;
    };

    const patientField =
      (root.querySelector('[data-bill-tab="patient"]') as HTMLElement | null) ?? customer[0];

    if (search && active === search) {
      if (event.shiftKey) {
        return go(addCards[addCards.length - 1] ?? close[close.length - 1] ?? product[product.length - 1]);
      }
      if (this.tabToCustomerAfterAdd) {
        this.tabToCustomerAfterAdd = false;
        return go(patientField);
      }
      return go(addCards[0] ?? patientField);
    }

    const addIdx = addCards.indexOf(active);
    if (addIdx >= 0) {
      if (event.shiftKey && addIdx === 0) return go(search);
      if (!event.shiftKey && addIdx === addCards.length - 1) {
        if (this.tabToCustomerAfterAdd) {
          this.tabToCustomerAfterAdd = false;
          return go(patientField ?? search);
        }
        return go(search);
      }
      return false;
    }

    if (!event.shiftKey && customer.length && active === customer[customer.length - 1]) {
      return false;
    }
    if (event.shiftKey && customer.length && active === customer[0]) {
      return go(search);
    }

    const onProductEnd = product.length > 0 && active === product[product.length - 1];
    const onEmptyProduct = active === card && product.length === 0;
    if (!event.shiftKey && (onProductEnd || onEmptyProduct)) {
      return go(close[0] ?? search);
    }

    if (close.length && active === close[0] && event.shiftKey) {
      return go(product[product.length - 1] ?? (card as HTMLElement | null) ?? customer[customer.length - 1]);
    }
    if (close.length && active === close[close.length - 1] && !event.shiftKey) {
      return go(search);
    }
    return false;
  }

  private sectionFocusables(container: Element | null): HTMLElement[] {
    if (!container) return [];
    const nodes = container.querySelectorAll(
      'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled])',
    );
    return Array.from(nodes).filter((node): node is HTMLElement => {
      if (!(node instanceof HTMLElement)) return false;
      if (node.tabIndex < 0) return false;
      if (node.closest('.combo-panel, .bill-save-actions, .grid-card')) return false;
      return node.getClientRects().length > 0;
    });
  }

  private addProductCards(root: HTMLElement): HTMLElement[] {
    return Array.from(root.querySelectorAll('.product-card:not([disabled])')).filter(
      (node): node is HTMLElement => node instanceof HTMLElement && node.getClientRects().length > 0,
    );
  }

  private productLineFocusables(root: HTMLElement): HTMLElement[] {
    const nodes = root.querySelectorAll(
      '.grid-card .bill-grid input:not([disabled]), .grid-card .bill-grid select:not([disabled]), .grid-card .bill-grid button:not([disabled]), .grid-card .bulk-disc-input:not([disabled])',
    );
    return Array.from(nodes).filter((node): node is HTMLElement => {
      if (!(node instanceof HTMLElement)) return false;
      if (node.tabIndex < 0) return false;
      // Dropdown options stay mouse/arrow-only; Counter "Send to" is tabbable.
      if (node.classList.contains('line-send-item')) return false;
      return node.getClientRects().length > 0;
    });
  }

  /** Enter / Space / ArrowDown on Counter Send opens the menu (Tab can land here). */
  onSendMenuBtnKey(index: number, event: KeyboardEvent): void {
    if (event.key !== 'Enter' && event.key !== ' ' && event.key !== 'ArrowDown') return;
    event.preventDefault();
    event.stopPropagation();
    if (this.sendMenuIndex() === index && event.key === 'Enter') {
      const dest = this.lastCashSendDest() || this.cashSendCounters()[0];
      if (dest) this.onSendLineToCounter(index, dest);
      return;
    }
    this.toggleSendMenu(index, event);
  }

  private closeFocusables(root: HTMLElement): HTMLElement[] {
    const nodes = root.querySelectorAll(
      '.success-panel [data-bill-tab="close"], .bill-save-actions button:not([disabled])',
    );
    return Array.from(nodes).filter((node): node is HTMLElement => {
      if (!(node instanceof HTMLElement)) return false;
      if (node.tabIndex < 0) return false;
      return node.getClientRects().length > 0;
    });
  }

  /**
   * ONE retail Tab ring (replaces moveRetailBillTab + section traps that fought each other).
   * Order: Search → cards → Patient…Chronic → each line (Send → Batch → Qty → Disc% → Disc₹)
   * → Save → WhatsApp → Discard → Close → (wrap) Search.
   * Chronic Tab → first Batch select when present, else Qty.
   */
  private buildRetailTabRing(): HTMLElement[] {
    const root = this.host.nativeElement as HTMLElement;
    const out: HTMLElement[] = [];
    const push = (el: Element | null | undefined): void => {
      if (!(el instanceof HTMLElement)) return;
      if (el.hasAttribute('disabled')) return;
      if (el.getAttribute('aria-hidden') === 'true') return;
      if (el.tabIndex < 0) return;
      if (el.getClientRects().length === 0) return;
      if (out.includes(el)) return;
      out.push(el);
    };

    push(root.querySelector('.pp-search-field input'));
    for (const card of this.addProductCards(root)) push(card);

    for (const key of this.retailHeaderTabKeys) {
      push(root.querySelector(`[data-bill-tab="${key}"]`));
    }

    root.querySelectorAll('tr.bill-line-row').forEach((row) => {
      // DOM column order: Counter Send → Batch → Qty → Disc% → Disc₹
      push(row.querySelector('.line-send-btn'));
      push(row.querySelector('select.g-input-batch'));
      push(row.querySelector('.g-input-qty'));
      push(row.querySelector('.g-input-disc'));
      push(row.querySelector('.g-input-disc-amt'));
    });

    push(root.querySelector('.bill-save-actions [data-bill-tab="save"]:not([disabled]), .bill-save-actions button.btn-primary:not([disabled])'));
    push(root.querySelector('.bill-save-actions [data-bill-tab="saveWhatsapp"]:not([disabled])'));
    push(root.querySelector('.bill-save-actions [data-bill-tab="discard"]:not([disabled])'));
    push(root.querySelector('.bill-save-actions [data-bill-tab="close"]:not([disabled]), .success-panel [data-bill-tab="close"]'));

    return out;
  }

  /** Resolve active element onto the ring (handles recreated batch <select>). */
  private retailRingIndex(ring: HTMLElement[], active: HTMLElement | null): number {
    if (!active) return -1;
    const direct = ring.indexOf(active);
    if (direct >= 0) return direct;

    const root = this.host.nativeElement as HTMLElement;
    if (!root.contains(active)) return -1;

    const row = active.closest('tr.bill-line-row');
    if (row) {
      // Prefer same-kind control if still present; else Qty (post batch change).
      const candidates = [
        row.querySelector('select.g-input-batch'),
        row.querySelector('.g-input-qty'),
        row.querySelector('.g-input-disc'),
        row.querySelector('.g-input-disc-amt'),
        row.querySelector('.line-send-btn'),
      ];
      for (const c of candidates) {
        if (c instanceof HTMLElement) {
          const i = ring.indexOf(c);
          if (i >= 0) return i;
        }
      }
    }

    const marked = (active.closest('[data-bill-tab]') as HTMLElement | null)
      ?? (active.getAttribute('data-bill-tab') ? active : null);
    if (marked) {
      const i = ring.indexOf(marked);
      if (i >= 0) return i;
    }
    return -1;
  }

  private moveAlongRetailTabRing(event: KeyboardEvent): boolean {
    const ring = this.buildRetailTabRing();
    if (!ring.length) return false;

    const root = this.host.nativeElement as HTMLElement;
    const active = document.activeElement as HTMLElement | null;
    let idx = this.retailRingIndex(ring, active && root.contains(active) ? active : null);

    // Focus dropped off the form (batch re-render) — land back on Qty/Batch (do not skip to Search).
    if (idx < 0 && this.lastRetailLineFocusIndex != null) {
      const rows = root.querySelectorAll('tr.bill-line-row');
      const row = rows[this.lastRetailLineFocusIndex] as HTMLElement | undefined;
      const resume =
        (row?.querySelector('.g-input-qty') as HTMLElement | null)
        ?? (row?.querySelector('select.g-input-batch') as HTMLElement | null);
      if (resume) {
        event.preventDefault();
        event.stopPropagation();
        resume.focus({ preventScroll: true });
        if (resume instanceof HTMLInputElement) resume.select();
        return true;
      }
    }

    event.preventDefault();
    event.stopPropagation();

    if (active?.closest('[data-bill-tab="doctor"]') || active?.getAttribute('data-bill-tab') === 'doctor') {
      this.showDoctorDropdown.set(false);
    }
    if (active?.closest('[data-bill-tab="patient"]') || active?.getAttribute('data-bill-tab') === 'patient') {
      this.showPatientDropdown.set(false);
    }

    if (idx < 0) {
      const target = event.shiftKey ? ring[ring.length - 1] : ring[0];
      target.focus({ preventScroll: true });
      return true;
    }

    // After Enter-add: next Tab from Search skips cards → Patient.
    const onSearch = ring[idx]?.classList?.contains('pp-search-input')
      || !!ring[idx]?.closest('.pp-search-field');
    if (!event.shiftKey && onSearch && this.tabToCustomerAfterAdd) {
      this.tabToCustomerAfterAdd = false;
      const patient = ring.find((el) => el.getAttribute('data-bill-tab') === 'patient');
      if (patient) {
        patient.focus({ preventScroll: true });
        return true;
      }
    }

    const step = event.shiftKey ? -1 : 1;
    const next = ring[(idx + step + ring.length) % ring.length];
    next.focus({ preventScroll: true });
    if (
      next instanceof HTMLInputElement
      && (next.classList.contains('g-input-qty')
        || next.classList.contains('g-input-disc')
        || next.classList.contains('g-input-disc-amt'))
    ) {
      next.select();
    }

    // Remember line so focus recovery stays on the grid, not Search.
    const lineRow = next.closest('tr.bill-line-row');
    if (lineRow) {
      const rows = Array.from(root.querySelectorAll('tr.bill-line-row'));
      const li = rows.indexOf(lineRow);
      if (li >= 0) this.lastRetailLineFocusIndex = li;
    }
    return true;
  }

  /** Last retail bill line the user edited (batch/qty) — recovers Tab if focus drops off the form. */
  private lastRetailLineFocusIndex: number | null = null;

  /** Re-focus a control on a bill line after Angular re-renders the row (e.g. batch change). */
  private focusBillLineControl(index: number, selector: string): void {
    this.lastRetailLineFocusIndex = index;
    this.suppressWedgeForBillEdit();
    const tryFocus = (left: number): void => {
      const root = this.host.nativeElement as HTMLElement;
      const rows = root.querySelectorAll('tr.bill-line-row');
      const row = rows[index] as HTMLElement | undefined;
      const el = row?.querySelector(selector) as HTMLElement | null;
      if (el && el.getClientRects().length > 0) {
        this.suppressWedgeForBillEdit();
        el.focus({ preventScroll: true });
        if (el instanceof HTMLInputElement) el.select();
        return;
      }
      if (left > 0) {
        requestAnimationFrame(() => tryFocus(left - 1));
      }
    };
    // Wait for Angular to recreate the <select>/<input> after updateLine.
    requestAnimationFrame(() => requestAnimationFrame(() => tryFocus(8)));
  }

  private salesFocusables(): HTMLElement[] {
    const root = this.host.nativeElement;
    const nodes = root.querySelectorAll(
      'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]',
    );
    return Array.from(nodes).filter((node): node is HTMLElement => {
      if (!(node instanceof HTMLElement)) return false;
      if (node.tabIndex < 0) return false;
      if (node.getAttribute('aria-hidden') === 'true') return false;
      return node.getClientRects().length > 0;
    });
  }

  ngOnDestroy(): void {
    window.removeEventListener('keydown', this.onWedgeCapture, true);
    document.removeEventListener('keydown', this.onSalesEscapeCapture, true);
    document.removeEventListener('keydown', this.onSalesShortcutCapture, true);
    this.posModal.setEscapeBlocked(this.escapeOwnerId, false);
    this.clearWedgeState();
    if (this.scanDebounce) clearTimeout(this.scanDebounce);
    if (this.scanBusyWatchdog) clearTimeout(this.scanBusyWatchdog);
  }

  /** True when the user is editing a normal form control (not the scan box). */
  private isTypingTarget(el: Element | null): boolean {
    if (!el || !(el instanceof HTMLElement)) return false;
    // Any nested control inside bill details / line grid / quick-add, etc.
    if (el.closest('[data-no-barcode="1"]')) return true;
    // Product search / add panel / bill lines — never steal digits for barcode.
    if (el.closest('.pp-search-field, .pp-search-zone, .product-panel, .pp-generic-body, tr.bill-line-row, .grid-card, .details-card, .bill-save-actions')) {
      return true;
    }

    const tag = el.tagName.toLowerCase();
    if (tag === 'textarea') return true;
    if (tag === 'select') return true; // batch / payment selects — not barcode
    if (tag !== 'input') return false;
    const input = el as HTMLInputElement;
    if (input.disabled || input.readOnly) return false;
    if (input.classList.contains('pp-scan-input')) return false;
    const type = (input.type || 'text').toLowerCase();
    return [
      'text', 'search', 'tel', 'email', 'number', 'password', 'url',
      'date', 'datetime-local', 'month', 'time',
    ].includes(type);
  }

  /** Suppress barcode wedge while editing a bill line (batch → qty focus gap). */
  private suppressWedgeForBillEdit(): void {
    this.clearWedgeState();
    this.pendingScanCode = null;
    if (this.scanDebounce) {
      clearTimeout(this.scanDebounce);
      this.scanDebounce = null;
    }
    this.scanError.set(null);
    this.scanCode.set('');
  }

  private handleWedgeCapture(e: KeyboardEvent): void {
    if (e.isComposing || e.ctrlKey || e.altKey || e.metaKey) return;

    // Form fields fully own the keyboard — never divert digits to barcode.
    if (this.isTypingTarget(document.activeElement)) {
      this.clearWedgeState();
      return;
    }
    // Brief focus gap after batch re-render (body) — do not flush a stale scan.
    if (this.lastRetailLineFocusIndex != null) {
      const active = document.activeElement;
      if (!active || active === document.body || active === document.documentElement) {
        this.clearWedgeState();
        return;
      }
    }

    if (e.key === 'Enter') {
      if (this.wedgeBuf.length === 0) return;
      e.preventDefault();
      e.stopImmediatePropagation();
      const code = this.wedgeBuf;
      this.clearWedgeState();
      this.submitWedgeCode(code);
      return;
    }

    if (e.key === 'Backspace' || e.key === 'Delete' || e.key === 'Tab' || e.key === 'Escape') {
      this.clearWedgeState();
      return;
    }

    if (!/^[0-9]$/.test(e.key)) {
      if (this.wedgeBuf.length > 0) {
        const code = this.wedgeBuf;
        this.clearWedgeState();
        this.submitWedgeCode(code);
      }
      return;
    }

    const now = Date.now();
    const gap = this.lastDigitAt ? now - this.lastDigitAt : 9999;
    this.lastDigitAt = now;

    e.preventDefault();
    e.stopImmediatePropagation();
    if (this.wedgeBuf.length > 0 && gap > 200) {
      this.wedgeBuf = '';
    }
    this.wedgeBuf += e.key;
    this.scanCode.set(this.wedgeBuf);
    if (this.wedgeFlushTimer) clearTimeout(this.wedgeFlushTimer);
    this.wedgeFlushTimer = setTimeout(() => this.flushWedgeBuffer(), this.wedgeFlushMs);
  }

  private flushWedgeBuffer(): void {
    const code = this.wedgeBuf;
    this.clearWedgeState();
    if (!code) return;
    // Never auto-scan while the user is on (or returning to) a bill line.
    if (this.lastRetailLineFocusIndex != null) {
      const active = document.activeElement as HTMLElement | null;
      if (
        !active
        || active === document.body
        || active === document.documentElement
        || this.isTypingTarget(active)
        || active.closest?.('tr.bill-line-row, .grid-card, [data-no-barcode="1"]')
      ) {
        return;
      }
    }
    this.submitWedgeCode(code);
  }

  private submitWedgeCode(code: string): void {
    const cleaned = code.replace(/\D/g, '');
    if (!cleaned) return;
    if (this.isTypingTarget(document.activeElement)) return;
    if (this.lastRetailLineFocusIndex != null) {
      const active = document.activeElement as HTMLElement | null;
      if (
        !active
        || active === document.body
        || this.isTypingTarget(active)
        || active.closest?.('tr.bill-line-row, .grid-card, [data-no-barcode="1"]')
      ) {
        return;
      }
    }
    this.scanCode.set(cleaned);
    this.onScan();
  }

  private clearWedgeState(): void {
    this.wedgeBuf = '';
    this.lastDigitAt = 0;
    if (this.wedgeFlushTimer) {
      clearTimeout(this.wedgeFlushTimer);
      this.wedgeFlushTimer = null;
    }
  }

  onScanCodeChange(value: string): void {
    // Manual paste / rare path into the visible scan box
    const digits = String(value ?? '').replace(/\D/g, '');
    this.scanCode.set(digits);
    if (this.scanDebounce) clearTimeout(this.scanDebounce);
    if (digits.length >= 1) {
      this.scanDebounce = setTimeout(() => this.onScan(), 80);
    }
  }

  onScan(): void {
    if (this.scanDebounce) {
      clearTimeout(this.scanDebounce);
      this.scanDebounce = null;
    }
    const code = this.scanCode().trim().replace(/\D/g, '');
    if (!code) return;
    if (this.scanBusy()) {
      // Don't drop short barcodes while a previous scan is in flight.
      this.pendingScanCode = code;
      return;
    }

    this.scanBusy.set(true);
    this.scanError.set(null);
    this.scanOk.set(null);
    if (this.scanBusyWatchdog) clearTimeout(this.scanBusyWatchdog);
    this.scanBusyWatchdog = setTimeout(() => {
      if (this.scanBusy()) {
        this.scanBusy.set(false);
        this.scanError.set('Scan timed out — try again.');
        setTimeout(() => this.scanError.set(null), 2800);
        this.drainPendingScan();
      }
    }, 10000);

    this.salesSvc.scan(code).subscribe({
      next: (p) => {
        if (this.scanBusyWatchdog) {
          clearTimeout(this.scanBusyWatchdog);
          this.scanBusyWatchdog = null;
        }
        this.addScannedProduct(p);
        this.scanCode.set('');
        this.scanBusy.set(false);
        this.scanOk.set(p.product_name);
        setTimeout(() => this.scanOk.set(null), 1800);
        this.drainPendingScan();
      },
      error: (err: HttpErrorResponse) => {
        if (this.scanBusyWatchdog) {
          clearTimeout(this.scanBusyWatchdog);
          this.scanBusyWatchdog = null;
        }
        this.scanBusy.set(false);
        const fromField = (err.error?.errors?.code as string[] | undefined)?.[0];
        this.scanError.set(fromField || err.error?.message || `Barcode not found: ${code}`);
        this.scanCode.set('');
        setTimeout(() => this.scanError.set(null), 2800);
        this.drainPendingScan();
      },
    });
  }

  private drainPendingScan(): void {
    const next = this.pendingScanCode;
    this.pendingScanCode = null;
    if (!next) return;
    this.scanCode.set(next);
    queueMicrotask(() => this.onScan());
  }

  /**
   * Resolve scan → product+batch and append (or +1 qty) on the bill lines table.
   * Prefers the exact inventory batch encoded in the barcode.
   */
  private addScannedProduct(
    p: SaleProduct & {
      batch_id: number;
      batch_no?: string | null;
      expiry_date?: string | null;
      unit_mrp?: number;
      ptr?: number;
      pts?: number;
      purchase_rate?: number;
    }
  ): void {
    if (p.is_expired) {
      this.error.set(`${p.product_name} is expired and cannot be sold.`);
      return;
    }
    if ((Number(p.available_qty) || 0) <= 0) {
      this.error.set(`${p.product_name} has no stock on this barcode batch.`);
      return;
    }
    if (this.isWholesale()) {
      this.loadWholesaleProduct(p as SaleProduct, Number(p.batch_id));
      return;
    }

    const existing = this.bill().lines.findIndex(
      (l) => l.binding_id === p.binding_id && (l.batch_id == null || l.batch_id === p.batch_id)
    );
    if (existing >= 0) {
      this.onQtyChange(existing, this.bill().lines[existing].qty + 1);
      this.afterLineAdded(p.schedule_category, this.moveBillLineToTop(existing));
      this.searchTerm.set('');
      return;
    }

    const upp = Math.max(1, Number(p.units_per_pack) || 1);
    const unitMrp = Number(p.unit_mrp ?? p.mrp ?? 0);
    const purchaseRate = Number(p.purchase_rate) || 0;
    const scannedBatch: BatchOption = {
      batch_id: Number(p.batch_id),
      batch_no: String(p.batch_no ?? 'NA'),
      expiry_date: p.expiry_date ?? null,
      mrp: unitMrp,
      unit_mrp: unitMrp,
      pack_mrp: Math.round(unitMrp * upp * 100) / 100,
      ptr: Number(p.ptr) || 0,
      pts: Number(p.pts) || 0,
      purchase_rate: purchaseRate,
      pack_purchase_rate: Math.round(purchaseRate * upp * 100) / 100,
      quantity: Number(p.available_qty) || 0,
      packs: Math.floor((Number(p.available_qty) || 0) / upp),
      shelf_location: p.shelf_location ?? null,
      units_per_pack: upp,
    };

    const finishAdd = (batches: BatchOption[]): void => {
      let sellable = this.sellableBatches(batches);
      if (!sellable.some((b) => b.batch_id === scannedBatch.batch_id)) {
        sellable = [scannedBatch, ...sellable];
      }
      const b = sellable.find((x) => x.batch_id === scannedBatch.batch_id) ?? scannedBatch;
      const packMrp = this.stripMrpFromBatch(b);
      const packPurchase = b.pack_purchase_rate ?? b.purchase_rate * Math.max(1, b.units_per_pack || upp);
      const baseLine: BillLine = {
        binding_id: p.binding_id,
        product_name: p.product_name,
        mfg: p.manufacturer_short ?? null,
        hsn_code: p.hsn_code ?? null,
        category: p.schedule_category,
        product_category: p.product_category ?? '',
        gst_rate: Number(p.gst_rate) || 0,
        units_per_pack: Math.max(1, b.units_per_pack || upp),
        allow_loose: (p.allow_loose ?? 1) === 1,
        pack: p.pack_size ?? null,
        shelf: b.shelf_location ?? p.shelf_location ?? null,
        unit_type: 'unit',
        batch_id: b.batch_id,
        batch_no: b.batch_no,
        expiry: b.expiry_date,
        unit_mrp: b.unit_mrp,
        pack_mrp: packMrp,
        ptr: b.ptr ?? 0,
        pts: b.pts ?? 0,
        rate: 0,
        qty: 1,
        disc: 0,
        disc_amt: 0,
        cost: b.purchase_rate ?? 0,
        unit_net_cost: b.pack_net_cost
          ? Math.round((b.pack_net_cost / Math.max(1, b.units_per_pack || upp)) * 10000) / 10000
          : (b.purchase_rate ?? 0),
        pack_purchase_rate: packPurchase,
        scheme_pct: Number(b.scheme_pct ?? 0),
        pur_disc_pct: 0,
        ...this.defaultWholesaleFields(0, 0),
        batches: sellable.length ? sellable : [b],
        batch_locked: false,
      };
      let line = this.applyBatchToLine(baseLine, b);
      line = this.repriceLine(line, this.bill().mode, this.bill().rateType);
      const idx = this.prependBillLine(line);
      this.afterLineAdded(p.schedule_category, idx);
      if (this.isWholesale()) {
        this.loadPrevCustomerRates(p.binding_id, idx);
      }
      this.searchTerm.set('');
      this.highlight.set(0);
    };

    this.salesSvc.batches(p.binding_id).subscribe({
      next: (batches) => finishAdd(batches),
      error: () => finishAdd([scannedBatch]),
    });
  }

  // ---- lines ----
  /** Product list / search click — wholesale loads form; retail adds to grid. */
  selectProduct(p: SaleProduct, preferredBatchId?: number): void {
    if (this.isWholesale()) {
      this.loadWholesaleProduct(p, preferredBatchId);
      return;
    }
    this.addProduct(p, preferredBatchId);
  }

  addProduct(p: SaleProduct, preferredBatchId?: number): void {
    if (p.is_expired || ((p.available_qty ?? 0) <= 0 && (p.expired_qty ?? 0) > 0)) {
      this.error.set(`${p.product_name} is expired and cannot be sold.`);
      return;
    }
    this.salesSvc.batches(p.binding_id).subscribe((batches) => {
      const sellable = this.sellableBatches(batches).filter((b) => (Number(b.quantity) || 0) > 0);
      if (sellable.length === 0) {
        this.tabToCustomerAfterAdd = false;
        this.error.set(`No sellable stock for ${p.product_name}.`);
        this.focusProductSearch();
        return;
      }
      const batchId = this.pickBatchForAdd(p.binding_id, sellable, preferredBatchId);
      if (batchId == null) {
        this.tabToCustomerAfterAdd = false;
        this.error.set(`All batches of ${p.product_name} are already fully used on this bill.`);
        this.focusProductSearch();
        return;
      }
      const existing = this.bill().lines.findIndex(
        (l) => l.binding_id === p.binding_id && l.batch_id === batchId,
      );
    if (existing >= 0) {
        this.onQtyChange(existing, this.bill().lines[existing].qty + 1);
        this.error.set(null);
        this.afterLineAdded(p.schedule_category, this.moveBillLineToTop(existing));
        this.stayOnAddProductAfterAdd();
        return;
      }
      const line = this.buildBillLineFromProduct(p, batches, batchId);
      if (!line) {
        this.tabToCustomerAfterAdd = false;
        this.focusProductSearch();
        return;
      }
      this.error.set(null);
      this.afterLineAdded(p.schedule_category, this.prependBillLine(line));
      this.stayOnAddProductAfterAdd();
    });
    this.searchTerm.set('');
    this.highlight.set(0);
    if (this.searchResults().length === 0) {
      this.loadInitial();
    }
  }

  /** Insert a new bill line at the top (newest first). */
  private prependBillLine(line: BillLine): number {
    this.patchBill({ lines: [line, ...this.bill().lines] });
    return 0;
  }

  /** Move an existing bill line to row 0 after it was re-added / qty bumped. */
  private moveBillLineToTop(index: number): number {
    if (index <= 0) return 0;
    const lines = [...this.bill().lines];
    if (index >= lines.length) return 0;
    const [row] = lines.splice(index, 1);
    lines.unshift(row);
    this.patchBill({ lines });
    return 0;
  }

  /** Product is on the bill. Stay in the add-product list; the next Tab opens customer details. */
  private stayOnAddProductAfterAdd(): void {
    this.tabToCustomerAfterAdd = true;
    this.focusProductSearch();
  }

  /** Wholesale: load product into entry form; same product + new batch = new line. */
  loadWholesaleProduct(p: SaleProduct, preferredBatchId?: number): void {
    if (p.is_expired || ((p.available_qty ?? 0) <= 0 && (p.expired_qty ?? 0) > 0)) {
      this.error.set(`${p.product_name} is expired and cannot be sold.`);
      return;
    }
    this.salesSvc.batches(p.binding_id).subscribe((batches) => {
      const sellable = this.sellableBatches(batches).filter((b) => (Number(b.quantity) || 0) > 0);
      if (sellable.length === 0) {
        this.error.set(`No stock batch for ${p.product_name}. Enter purchase first.`);
        return;
      }
      const batchId = this.pickBatchForAdd(p.binding_id, sellable, preferredBatchId);
      if (batchId == null) {
        this.error.set(`All batches of ${p.product_name} are already fully used on this bill.`);
        return;
      }
      const existing = this.bill().lines.findIndex(
        (l) => l.binding_id === p.binding_id && l.batch_id === batchId,
      );
      if (existing >= 0) {
        this.editWholesaleLine(existing);
        this.searchTerm.set('');
        this.highlight.set(0);
        return;
      }
      const line = this.buildBillLineFromProduct(p, batches, batchId);
      if (!line) return;
      this.resetWsModalPosition();
      this.patchBill({ wsDraft: line, wsDraftEditIndex: null });
      this.loadPrevCustomerRates(p.binding_id, null);
      this.wsModalShouldAutofocus = true;
      this.focusWholesaleModal();
      this.error.set(null);
    });
    this.searchTerm.set('');
    this.highlight.set(0);
  }

  /**
   * Prefer preferred batch, else first sellable batch not yet on the bill,
   * else first batch that still has remaining qty after other lines.
   */
  private pickBatchForAdd(
    bindingId: number,
    sellable: BatchOption[],
    preferredBatchId?: number,
  ): number | null {
    const candidates =
      preferredBatchId != null
        ? sellable.filter((b) => b.batch_id === preferredBatchId)
        : sellable;
    const pool = candidates.length ? candidates : sellable;
    const unused = pool.find((b) =>
      !this.bill().lines.some((l) => l.binding_id === bindingId && l.batch_id === b.batch_id),
    );
    if (unused) {
      return unused.batch_id;
    }
    for (const b of pool) {
      const idx = this.bill().lines.findIndex(
        (l) => l.binding_id === bindingId && l.batch_id === b.batch_id,
      );
      if (idx < 0) {
        return b.batch_id;
      }
      const rem = this.batchRemainingBillQty(this.bill().lines[idx], idx);
      if (rem > this.bill().lines[idx].qty) {
        return b.batch_id;
      }
    }
    return null;
  }

  /** Max qty (in line unit_type) allowed for this line from its batch stock. */
  batchRemainingBillQty(line: BillLine, index: number): number {
    if (!line.batch_id) {
      return Number.POSITIVE_INFINITY;
    }
    const batch = line.batches.find((b) => b.batch_id === line.batch_id);
    const stockBase = Number(batch?.quantity) || 0;
    const upp = Math.max(1, line.units_per_pack || 1);
    let otherBase = 0;
    this.bill().lines.forEach((l, i) => {
      if (i === index || l.batch_id !== line.batch_id) {
        return;
      }
      otherBase += this.lineBaseQty(l);
    });
    const remainingBase = Math.max(0, stockBase - otherBase);
    return line.unit_type === 'pack' ? remainingBase / upp : remainingBase;
  }

  private lineBaseQty(l: BillLine): number {
    const upp = Math.max(1, l.units_per_pack || 1);
    const sold = l.unit_type === 'pack' ? l.qty * upp : l.qty;
    const free = this.isWholesale()
      ? (l.unit_type === 'pack' ? (l.free_qty || 0) * upp : (l.free_qty || 0))
      : 0;
    return sold + free;
  }

  private buildBillLineFromProduct(
    p: SaleProduct,
    batches: BatchOption[],
    preferredBatchId?: number,
  ): BillLine | null {
    const sellable = this.sellableBatches(batches);
    if (sellable.length === 0) {
      if (batches.some((b) => this.isBatchExpired(b))) {
        this.error.set(`${p.product_name} has only expired stock and cannot be sold.`);
      } else if (this.isWholesale()) {
        this.error.set(`No stock batch for ${p.product_name}. Enter purchase first.`);
      } else {
        this.error.set(`No sellable stock for ${p.product_name}.`);
      }
      return null;
    }
    const preferred =
      preferredBatchId != null
        ? sellable.find((x) => x.batch_id === preferredBatchId)
        : undefined;
    const b = preferred ?? sellable[0];
      const upp = b?.units_per_pack ?? p.units_per_pack ?? 1;
    const packMrp = b
      ? this.stripMrpFromBatch(b)
      : this.sidebarProductMrp(p);
      const packPurchase = b?.pack_purchase_rate ?? (b ? b.purchase_rate * upp : 0);
      const baseLine: BillLine = {
        binding_id: p.binding_id,
        product_name: p.product_name,
        mfg: p.manufacturer_short ?? null,
        hsn_code: p.hsn_code ?? null,
        category: p.schedule_category,
      product_category: p.product_category ?? '',
        gst_rate: Number(p.gst_rate) || 0,
        units_per_pack: upp,
        allow_loose: (p.allow_loose ?? 1) === 1,
        pack: p.pack_size ?? null,
        shelf: b?.shelf_location ?? p.shelf_location ?? null,
        unit_type: 'unit',
        batch_id: b?.batch_id ?? null,
        batch_no: b?.batch_no ?? null,
        expiry: b?.expiry_date ?? null,
      unit_mrp: packMrp > 0 && upp > 0 ? Math.round((packMrp / upp) * 10000) / 10000 : (b?.unit_mrp ?? 0),
        pack_mrp: packMrp,
        ptr: b?.ptr ?? 0,
        pts: b?.pts ?? 0,
        rate: 0,
        qty: 1,
        disc: 0,
      disc_amt: 0,
        cost: b?.purchase_rate ?? 0,
        unit_net_cost: b?.pack_net_cost
          ? Math.round((b.pack_net_cost / upp) * 10000) / 10000
          : (b?.purchase_rate ?? 0),
        pack_purchase_rate: packPurchase,
      scheme_pct: Number(b?.scheme_pct ?? 0),
      pur_disc_pct: 0,
      ...this.defaultWholesaleFields(0, 0),
        batches,
        batch_locked: false,
      };
      let line = b ? this.applyBatchToLine(baseLine, b) : baseLine;
      line = this.repriceLine(line, this.bill().mode, this.bill().rateType);
    if (this.bill().mode === 'retail') {
      return { ...line, ...this.retailDiscSync(line, { disc: 0 }) };
    }
    return line;
  }

  editWholesaleLine(index: number): void {
    const line = this.bill().lines[index];
    if (!line) return;
    this.resetWsModalPosition();
    this.patchBill({
      wsDraft: this.recalcWholesaleLine({ ...line }),
      wsDraftEditIndex: index,
    });
    this.loadPrevCustomerRates(line.binding_id, index);
    this.wsModalShouldAutofocus = true;
    this.focusWholesaleModal();
  }

  patchWholesaleDraft(patch: Partial<BillLine>): void {
    const draft = this.bill().wsDraft;
    if (!draft) return;
    let merged: BillLine = { ...draft, ...patch };
    if ('rate' in patch && merged.billing_with === 'Sale Rate') {
      merged.base_rate = merged.rate;
    }
    merged = this.recalcWholesaleLine(merged);
    this.patchBill({ wsDraft: merged });
  }

  selectWholesaleDraftBatch(batchId: number): void {
    const draft = this.bill().wsDraft;
    if (!draft) return;
    const b = draft.batches.find((x) => x.batch_id === batchId);
    if (!b) return;
    if (this.isBatchExpired(b)) {
      this.error.set(`Batch ${b.batch_no} is expired and cannot be sold.`);
      return;
    }
    const patched = this.applyBatchToLine({ ...draft, batch_locked: true }, b);
    this.patchBill({
      wsDraft: this.recalcWholesaleLine(
        this.repriceLine({ ...patched, batch_locked: true }, 'wholesale', this.bill().rateType),
      ),
    });
  }

  commitWholesaleAdd(): void {
    const draft = this.bill().wsDraft;
    if (!draft?.binding_id) {
      this.error.set('Select a product first.');
      return;
    }
    const qty = Number(draft.qty);
    if (!Number.isFinite(qty) || qty <= 0) {
      this.error.set('Enter quantity of at least 1.');
      return;
    }
    const line = this.recalcWholesaleLine(draft);
    const editIndex = this.bill().wsDraftEditIndex;
    const maxQty = this.batchRemainingBillQty(
      line,
      editIndex !== null && editIndex >= 0 ? editIndex : this.bill().lines.length,
    );
    const free = Number(line.free_qty) || 0;
    if (qty + free > maxQty + 0.0001) {
      this.error.set(
        `${line.product_name}: batch ${line.batch_no || ''} has only ${this.round2(maxQty)} available (qty + free).`,
      );
      return;
    }
    let lines = [...this.bill().lines];
    if (editIndex !== null && editIndex >= 0 && editIndex < lines.length) {
      lines = lines.map((l, i) => (i === editIndex ? line : l));
      if (editIndex > 0) {
        const [row] = lines.splice(editIndex, 1);
        lines.unshift(row);
      }
    } else {
      // Same product + same batch → replace that line; different batch → new line at top.
      const dup = lines.findIndex(
        (l) => l.binding_id === line.binding_id && l.batch_id === line.batch_id,
      );
      if (dup >= 0) {
        lines = lines.map((l, i) => (i === dup ? line : l));
        if (dup > 0) {
          const [row] = lines.splice(dup, 1);
          lines.unshift(row);
        }
      } else {
        lines = [line, ...lines];
      }
    }
    this.patchBill({ lines, wsDraft: null, wsDraftEditIndex: null });
    this.prevCustomerRates.set([]);
    this.prevRatesLineIndex.set(null);
    this.resetWsModalPosition();
    this.error.set(null);
  }

  clearWholesaleDraft(): void {
    this.patchBill({ wsDraft: null, wsDraftEditIndex: null });
    this.prevCustomerRates.set([]);
    this.prevRatesLineIndex.set(null);
    this.resetWsModalPosition();
  }

  resetWsModalPosition(): void {
    this.wsModalOffset.set({ x: 0, y: 0 });
  }

  wsModalTransform(): string {
    const { x, y } = this.wsModalOffset();
    return `translate(calc(-50% + ${x}px), calc(-50% + ${y}px))`;
  }

  onWsModalDragStart(ev: MouseEvent): void {
    const t = ev.target as HTMLElement | null;
    if (t?.closest('button, input, select, textarea, a, label')) {
      return;
    }
    ev.preventDefault();
    const o = this.wsModalOffset();
    this.wsModalDragActive = true;
    this.wsModalDragStart = { px: ev.clientX, py: ev.clientY, ox: o.x, oy: o.y };
  }

  @HostListener('document:mousemove', ['$event'])
  onWsModalDragMove(ev: MouseEvent): void {
    if (!this.wsModalDragActive) return;
    const dx = ev.clientX - this.wsModalDragStart.px;
    const dy = ev.clientY - this.wsModalDragStart.py;
    this.wsModalOffset.set({
      x: this.wsModalDragStart.ox + dx,
      y: this.wsModalDragStart.oy + dy,
    });
  }

  @HostListener('document:mouseup')
  onWsModalDragEnd(): void {
    this.wsModalDragActive = false;
  }

  onWsModalBackdropClick(ev: MouseEvent): void {
    if ((ev.target as HTMLElement).classList.contains('ws-modal-backdrop')) {
      this.clearWholesaleDraft();
    }
  }

  /** Focus sale-rate once when the popup opens — not on every field change. */
  focusWholesaleModal(): void {
    if (!this.wsModalShouldAutofocus) {
      return;
    }
    this.wsModalShouldAutofocus = false;
    setTimeout(() => {
      const rate = this.wsModalRateInput()?.nativeElement;
      if (rate && !rate.readOnly) {
        rate.focus({ preventScroll: true });
        rate.select();
        return;
      }
      this.wsModalPanel()?.nativeElement?.focus({ preventScroll: true });
    }, 50);
  }

  /** Parse numeric draft input without coercing empty to 0 while user is editing. */
  private wsDraftNum(raw: string | number, fallback: number): number {
    if (raw === '' || raw === null || raw === undefined) {
      return fallback;
    }
    const n = typeof raw === 'number' ? raw : Number.parseFloat(String(raw));
    return Number.isFinite(n) ? n : fallback;
  }

  patchWholesaleDraftRate(raw: string | number): void {
    const draft = this.bill().wsDraft;
    if (!draft) return;
    const rate = this.wsDraftNum(raw, draft.rate);
    this.patchWholesaleDraft({ rate, base_rate: rate });
  }

  patchWholesaleDraftOthRate(raw: string | number): void {
    const draft = this.bill().wsDraft;
    if (!draft) return;
    this.patchWholesaleDraft({ oth_rate: this.wsDraftNum(raw, draft.oth_rate) });
  }

  patchWholesaleDraftQty(raw: string | number): void {
    const draft = this.bill().wsDraft;
    if (!draft) return;
    this.patchWholesaleDraft({ qty: this.wsDraftNum(raw, draft.qty) });
  }

  patchWholesaleDraftDisc(raw: string | number): void {
    const draft = this.bill().wsDraft;
    if (!draft) return;
    this.patchWholesaleDraft({ disc: Math.max(0, this.wsDraftNum(raw, 0)) });
  }

  patchWholesaleDraftFreeQty(raw: string | number): void {
    const draft = this.bill().wsDraft;
    if (!draft) return;
    this.patchWholesaleDraft({ free_qty: Math.max(0, this.wsDraftNum(raw, 0)) });
  }

  onWsModalKeydown(ev: KeyboardEvent): void {
    if (ev.key === 'Escape') {
      ev.preventDefault();
      ev.stopPropagation();
      this.clearWholesaleDraft();
      return;
    }
    if (ev.key !== 'Enter' || ev.shiftKey || ev.altKey || ev.ctrlKey || ev.metaKey) {
      return;
    }
    const t = ev.target as HTMLElement | null;
    const tag = t?.tagName ?? '';
    if (tag === 'TEXTAREA' || tag === 'SELECT') {
      return;
    }
    if (t?.closest('.ws-modal-drag-handle')) {
      return;
    }
    ev.preventDefault();
    ev.stopPropagation();
    this.commitWholesaleAdd();
  }

  // ---- substitute lookup for a line (same molecule alternatives) ----
  openSubstitutes(index: number): void {
    const l = this.bill().lines[index];
    this.subReplaceIndex = index;
    this.subSourceName.set(l.product_name);
    this.subOpen.set(true);
    this.subLoading.set(true);
    this.subs.set([]);
    this.salesSvc.substitutes(l.binding_id).subscribe({
      next: (s) => {
        this.subs.set(s);
        this.subLoading.set(false);
      },
      error: () => this.subLoading.set(false),
    });
  }

  /** Add a substitute as a new line, or replace the source line. */
  addSubstitute(sub: Substitute, replace: boolean): void {
    if (!sub.binding_id) return;
    const product: SaleProduct = {
      binding_id: sub.binding_id,
      product_name: sub.name,
      schedule_category: sub.schedule_category,
      gst_rate: Number(sub.default_gst_rate) || 0,
      master_product_id: sub.id,
      available_qty: sub.available_qty,
      mrp: null,
      generic_name: sub.generic_name,
    };
    if (replace && this.subReplaceIndex !== null) {
      this.removeLine(this.subReplaceIndex);
    }
    this.selectProduct(product);
    this.subOpen.set(false);
  }

  updateLine(index: number, patch: Partial<BillLine>): void {
    this.patchBill({
      lines: this.bill().lines.map((l, i) => {
        if (i !== index) return l;
        let merged: BillLine = { ...l, ...patch };
        if (this.isWholesale()) {
          if ('rate' in patch && merged.billing_with === 'Sale Rate') {
            merged.base_rate = merged.rate;
          }
          merged = this.recalcWholesaleLine(merged);
        }
        return merged;
      }),
    });
  }

  onWholesaleRateChange(index: number, value: string | number): void {
    const rate = typeof value === 'number' ? value : Number(value);
    if (!Number.isFinite(rate)) return;
    this.updateLine(index, { rate: this.round2(rate), base_rate: this.round2(rate) });
  }

  onWholesaleSchemeChange(index: number, pct: number): void {
    this.updateLine(index, { sale_scheme_pct: Number(pct) || 0 });
  }

  onWholesaleBillingWithChange(index: number, mode: BillingWith): void {
    this.updateLine(index, { billing_with: mode });
  }

  onWholesaleFreeQtyChange(index: number, value: string | number): void {
    const line = this.bill().lines[index];
    if (!line || line.sale_scheme_pct > 0) return;
    const n = typeof value === 'number' ? value : Number(value);
    this.updateLine(index, { free_qty: Number.isFinite(n) && n >= 0 ? this.round2(n) : 0 });
  }

  onQtyChange(index: number, value: string | number): void {
    if (!this.isWholesale()) {
      const line = this.bill().lines[index];
      // Manual batch pick: keep qty on that batch only so MRP does not jump back to FEFO batch.
      if (line?.batch_locked && line.batch_id != null) {
        this.applyRetailLockedBatchQty(index, value);
        return;
      }
      this.applyRetailFifoQty(index, value);
      return;
    }
    const n = typeof value === 'number' ? value : Number(value);
    let qty = Number.isFinite(n) && n > 0 ? Math.floor(n) : 1;
    const line = this.bill().lines[index];
    if (!line) return;
    const maxQty = this.batchRemainingBillQty(line, index);
    const free = Number(line.free_qty) || 0;
    const maxSold = Math.max(0, Math.floor(maxQty - free));
    if (maxSold < 1) {
      qty = 1;
      this.error.set(
        `${line.product_name}: batch ${line.batch_no || ''} has no remaining stock.`,
      );
    } else if (qty > maxSold) {
      qty = maxSold;
      this.error.set(
        `${line.product_name}: batch ${line.batch_no || ''} has only ${maxSold} available.`,
      );
    } else {
      this.error.set(null);
    }
    this.updateLine(index, { qty });
  }

  /** Retail qty for a manually selected batch — clamp to that batch; never re-FEFO / swap MRP. */
  private applyRetailLockedBatchQty(index: number, value: string | number): void {
    const n = typeof value === 'number' ? value : Number(value);
    let qty = Number.isFinite(n) && n > 0 ? Math.floor(n) : 1;
    const line = this.bill().lines[index];
    if (!line) return;
    const maxQty = this.batchRemainingBillQty(line, index);
    const maxSold = Math.max(0, Math.floor(maxQty));
    if (maxSold < 1) {
      qty = 1;
      this.error.set(
        `${line.product_name}: batch ${line.batch_no || ''} has no remaining stock.`,
      );
    } else if (qty > maxSold) {
      qty = maxSold;
      this.error.set(
        `${line.product_name}: batch ${line.batch_no || ''} has only ${maxSold} available.`,
      );
    } else {
      this.error.set(null);
    }
    const patched: BillLine = {
      ...line,
      qty,
      unit_type: 'unit',
      batch_locked: true,
    };
    // Keep selected batch rates/MRP; only refresh sell rate + disc for new qty.
    const repriced = this.repriceLine(patched, this.bill().mode, this.bill().rateType);
    this.updateLine(index, {
      ...repriced,
      qty,
      batch_locked: true,
      pack_mrp: line.pack_mrp,
      unit_mrp: line.unit_mrp,
      ...this.retailDiscSync({ ...repriced, qty, pack_mrp: line.pack_mrp, unit_mrp: line.unit_mrp }, { disc: line.disc }),
    });
  }

  /**
   * Retail: allocate entered qty across batches FEFO (earliest expiry first).
   * Example: need 10, batch A has 5 → line A=5 + new line B=5.
   * Qty cannot exceed total sellable stock across all batches.
   */
  private applyRetailFifoQty(index: number, value: string | number): void {
    const n = typeof value === 'number' ? value : Number(value);
    let wantedDisplay = Number.isFinite(n) && n > 0 ? Math.floor(n) : 1;
    const line = this.bill().lines[index];
    if (!line) return;

    const upp = Math.max(1, line.units_per_pack || 1);
    const maxDisplay = Math.floor(this.productRemainingBillQty(line, index));
    if (maxDisplay < 1) {
      this.error.set(`${line.product_name}: no remaining sellable stock across batches.`);
      const patch: Partial<BillLine> = { qty: 1 };
      Object.assign(patch, this.retailDiscSync({ ...line, qty: 1 }, { disc: line.disc }));
      this.updateLine(index, patch);
      return;
    }
    if (wantedDisplay > maxDisplay) {
      wantedDisplay = maxDisplay;
      this.error.set(
        `${line.product_name}: only ${maxDisplay} available across all batches (FIFO).`,
      );
    } else {
      this.error.set(null);
    }

    let wantedBase =
      line.unit_type === 'pack' ? wantedDisplay * upp : wantedDisplay;

    const fefo = this.sortBatchesFefo(
      this.sellableBatches(line.batches).filter((b) => (Number(b.quantity) || 0) > 0),
    );
    const allocs: Array<{ batch: BatchOption; baseQty: number }> = [];
    let remaining = wantedBase;
    for (const b of fefo) {
      if (remaining <= 0) break;
      let otherBase = 0;
      this.bill().lines.forEach((l, i) => {
        if (i === index || l.batch_id !== b.batch_id) return;
        otherBase += this.lineBaseQty(l);
      });
      const avail = Math.max(0, (Number(b.quantity) || 0) - otherBase);
      const take = Math.min(remaining, avail);
      if (take > 0) {
        allocs.push({ batch: b, baseQty: take });
        remaining -= take;
      }
    }

    if (allocs.length === 0) {
      this.error.set(`${line.product_name}: no remaining sellable stock across batches.`);
      return;
    }

    // Fast path: single batch, same as current line — just update qty (retail = tablets/units).
    if (allocs.length === 1 && allocs[0].batch.batch_id === line.batch_id) {
      const qty = allocs[0].baseQty;
      let patched: BillLine = { ...line, qty, unit_type: 'unit' };
      patched = this.repriceLine(patched, this.bill().mode, this.bill().rateType);
      patched = { ...patched, qty, ...this.retailDiscSync({ ...patched, qty }, { disc: line.disc }) };
      this.updateLine(index, patched);
      return;
    }

    const mode = this.bill().mode;
    const rateType = this.bill().rateType;
    const disc = line.disc;
    const working = [...this.bill().lines];
    working.splice(index, 1);

    const buildSeg = (batch: BatchOption, baseQty: number): BillLine => {
      let seg: BillLine = {
        ...line,
        unit_type: 'unit',
        qty: baseQty,
        free_qty: 0,
        total_qty: baseQty,
        batches: line.batches,
        batch_locked: false,
      };
      seg = this.applyBatchToLine(seg, batch);
      seg = this.repriceLine(seg, mode, rateType);
      seg = {
        ...seg,
        qty: baseQty,
        batch_locked: false,
        ...this.retailDiscSync({ ...seg, qty: baseQty }, { disc }),
      };
      return seg;
    };

    let insertAt = index;
    for (const { batch, baseQty } of allocs) {
      const existingIdx = working.findIndex(
        (l) => l.binding_id === line.binding_id && l.batch_id === batch.batch_id,
      );
      if (existingIdx >= 0) {
        const existing = working[existingIdx];
        const addUnits =
          existing.unit_type === 'pack'
            ? baseQty / Math.max(1, existing.units_per_pack || 1)
            : baseQty;
        const newQty = (Number(existing.qty) || 0) + addUnits;
        let merged: BillLine = { ...existing, qty: newQty };
        merged = {
          ...merged,
          ...this.retailDiscSync(merged, { disc: existing.disc }),
        };
        working[existingIdx] = merged;
      } else {
        working.splice(insertAt, 0, buildSeg(batch, baseQty));
        insertAt += 1;
      }
    }

    this.patchBill({ lines: working });
  }

  onDiscPctChange(index: number, value: string | number): void {
    if (this.isWholesale()) {
      this.updateLine(index, { disc: Math.max(0, Number(value) || 0) });
      return;
    }
    const line = this.bill().lines[index];
    if (!line) return;
    const disc = Math.max(0, Number(value) || 0);
    this.updateLine(index, this.retailDiscSync(line, { disc }));
  }

  onDiscAmtChange(index: number, value: string | number): void {
    if (this.isWholesale()) return;
    const line = this.bill().lines[index];
    if (!line) return;
    const disc_amt = Math.max(0, Number(value) || 0);
    this.updateLine(index, this.retailDiscSync(line, { disc_amt }));
  }

  /** Editable Disc₹ field — back-fills from % when older lines lack disc_amt. */
  discAmtField(line: BillLine): number {
    if (Number.isFinite(line.disc_amt) && line.disc_amt >= 0) {
      return this.round2(line.disc_amt);
    }
    return this.round2(line.rate * line.qty * (Math.max(0, line.disc) / 100));
  }

  /** Retail: keep disc % and disc ₹ in sync (Retail sales-form parity). */
  private retailDiscSync(
    line: BillLine,
    opts: { disc?: number; disc_amt?: number },
  ): Pick<BillLine, 'disc' | 'disc_amt'> {
    const gross = this.round2(line.rate * line.qty);
    if (opts.disc_amt !== undefined) {
      const disc_amt = Math.max(0, this.round2(opts.disc_amt));
      const disc = gross > 0 ? this.round2((disc_amt / gross) * 100) : 0;
      return { disc, disc_amt };
    }
    const disc = Math.max(0, this.round2(opts.disc ?? line.disc ?? 0));
    const disc_amt = gross > 0 ? this.round2((gross * disc) / 100) : 0;
    return { disc, disc_amt };
  }
  onUnitTypeChange(index: number, type: 'unit' | 'pack'): void {
    const l = this.bill().lines[index];
    if (type === l.unit_type) return;
    const upp = Math.max(1, l.units_per_pack);
    const rate = type === 'pack' ? l.rate * upp : l.rate / upp;
    this.updateLine(index, { unit_type: type, rate: Math.round(rate * 100) / 100 });
  }
  selectBatch(index: number, batchId: number): void {
    const l = this.bill().lines[index];
    const b = l.batches.find((x) => x.batch_id === batchId);
    if (!b) return;
    if (this.isBatchExpired(b)) {
      this.error.set(`Batch ${b.batch_no} is expired and cannot be sold.`);
      return;
    }
    if ((Number(b.quantity) || 0) <= 0) {
      this.error.set(`Batch ${b.batch_no} has no stock.`);
      return;
    }
    // Another line already using this batch — keep separate lines; block duplicate batch on same product.
    const dup = this.bill().lines.findIndex(
      (x, i) => i !== index && x.binding_id === l.binding_id && x.batch_id === batchId,
    );
    if (dup >= 0) {
      this.error.set(`Batch ${b.batch_no} is already on another line — add qty there instead.`);
      return;
    }
      const patched = this.applyBatchToLine(l, b);
    let repriced = this.repriceLine(patched, this.bill().mode, this.bill().rateType);
    if (!this.isWholesale()) {
      repriced = { ...repriced, ...this.retailDiscSync(repriced, { disc: repriced.disc }) };
    }
    // Lock batch so later qty edits do not FEFO-reallocate onto another batch / MRP.
    this.updateLine(index, { ...repriced, batch_locked: true });
    // Manual batch pick: clamp to that batch only (do not FEFO-spill).
    const after = this.bill().lines[index];
    if (after) {
      const maxQty = this.batchRemainingBillQty(after, index);
      const free = this.isWholesale() ? (Number(after.free_qty) || 0) : 0;
      const maxSold = Math.max(1, Math.floor(maxQty - free));
      if (after.qty > maxSold) {
        const qty = maxSold;
        const clampPatch: Partial<BillLine> = { qty, batch_locked: true };
        if (!this.isWholesale()) {
          Object.assign(clampPatch, this.retailDiscSync({ ...after, qty }, { disc: after.disc }));
        }
        this.updateLine(index, clampPatch);
        this.error.set(
          `${after.product_name}: batch ${after.batch_no || ''} has only ${maxSold} available.`,
        );
      }
    }
      if (this.isWholesale()) {
        this.loadPrevCustomerRates(l.binding_id, index);
    } else {
      // Next field after batch is Qty; Tab then continues Disc% → Disc₹ → …
      // Clear barcode wedge so a stale buffer cannot fire "invalid barcode" and yank to search.
      this.suppressWedgeForBillEdit();
      this.focusBillLineControl(index, '.g-input-qty');
    }
  }
  removeLine(index: number): void {
    const b = this.bill();
    let wsDraft = b.wsDraft;
    let wsDraftEditIndex = b.wsDraftEditIndex;
    if (wsDraftEditIndex === index) {
      wsDraft = null;
      wsDraftEditIndex = null;
    } else if (wsDraftEditIndex !== null && wsDraftEditIndex > index) {
      wsDraftEditIndex -= 1;
    }
    this.patchBill({
      lines: b.lines.filter((_, i) => i !== index),
      wsDraft,
      wsDraftEditIndex,
    });
    if (this.cashSelectMode()) {
      const next = new Set<number>();
      for (const i of this.cashSelectedIndexes()) {
        if (i === index) continue;
        next.add(i > index ? i - 1 : i);
      }
      this.cashSelectedIndexes.set(next);
      const n = b.lines.length - 1;
      if (n <= 0) {
        this.exitCashSelectMode();
      } else {
        this.cashSelectFocus.set(Math.min(this.cashSelectFocus(), n - 1));
      }
    }
  }

  // ---- GST mode ----
  // Retail and wholesale-MRP rates are GST-INCLUSIVE (reverse-calc).
  // Wholesale PTR/PTS: GST-EXCLUSIVE (Retail wsales calculate()).
  readonly gstInclusive = computed(() => {
    const b = this.bill();
    if (b.mode === 'wholesale') return false;
    return true;
  });

  private round2(n: number): number {
    return Math.round(n * 100) / 100;
  }

  /** Gross before product discount — wholesale strip rate × qty (Retail mrp×qty). */
  lineGross(l: BillLine): number {
    return this.round2(l.rate * l.qty);
  }

  // ---- maths (Retail wsales parity for wholesale) ----
  lineDiscountAmt(l: BillLine): number {
    if (!this.isWholesale()) {
      if (Number.isFinite(l.disc_amt) && l.disc_amt >= 0) {
        return this.round2(l.disc_amt);
      }
      return this.round2(l.rate * l.qty * (l.disc / 100));
    }
    return this.round2(this.lineGross(l) * (l.disc / 100));
  }

  lineCdAmt(l: BillLine): number {
    if (!this.isWholesale()) return 0;
    const afterDisc = this.lineGross(l) - this.lineDiscountAmt(l);
    return this.round2(afterDisc * (this.bill().cdDiscPct / 100));
  }

  lineTaxable(l: BillLine): number {
    if (this.isWholesale()) {
      return this.round2(this.lineGross(l) - this.lineDiscountAmt(l) - this.lineCdAmt(l));
    }
    const base = l.rate * l.qty * (1 - l.disc / 100);
    return this.gstInclusive() ? this.round2(base / (1 + l.gst_rate / 100)) : this.round2(base);
  }

  lineGstAmt(l: BillLine): number {
    if (this.isWholesale()) {
      return this.round2(this.lineTaxable(l) * (l.gst_rate / 100));
    }
    const base = l.rate * l.qty * (1 - l.disc / 100);
    return this.gstInclusive()
      ? this.round2(base - this.lineTaxable(l))
      : this.round2(this.lineTaxable(l) * (l.gst_rate / 100));
  }

  lineSell(l: BillLine): number {
    if (this.isWholesale()) {
      return this.round2(this.lineTaxable(l) + this.lineGstAmt(l));
    }
    const base = l.rate * l.qty * (1 - l.disc / 100);
    return this.gstInclusive() ? this.round2(base) : this.round2(this.lineTaxable(l) + this.lineGstAmt(l));
  }

  lineProfit(l: BillLine): number {
    if (this.isWholesale()) {
      const packCost = this.wholesalePackPurchaseRate(l);
      return this.round2(this.lineGross(l) - this.lineDiscountAmt(l) - packCost * l.qty);
    }
    const unitCost = l.unit_net_cost > 0 ? l.unit_net_cost : l.cost;
    const grossMrp = l.rate * l.qty;
    const discAmt = grossMrp * (l.disc / 100);
    return this.round2(grossMrp - discAmt - unitCost * l.qty);
  }

  readonly grossTotal = computed(() =>
    this.round2(this.bill().lines.reduce((s, l) => s + this.lineGross(l), 0)),
  );
  readonly taxableTotal = computed(() =>
    this.round2(this.bill().lines.reduce((s, l) => s + this.lineTaxable(l), 0)),
  );
  readonly itemsSell = computed(() => this.bill().lines.reduce((s, l) => s + this.lineSell(l), 0));
  readonly gstTotal = computed(() => this.bill().lines.reduce((s, l) => s + this.lineGstAmt(l), 0));
  /** Retail bill-level discount applied (₹), capped to line net — matches API bill_discount_amount. */
  readonly retailBillDiscountEffective = computed(() => {
    if (this.isWholesale()) return 0;
    const sell = this.itemsSell();
    const disc = Math.max(0, Number(this.bill().billDiscountAmt) || 0);
    return this.round2(Math.min(disc, sell));
  });
  readonly profitTotal = computed(() => {
    let p = this.bill().lines.reduce((s, l) => s + this.lineProfit(l), 0);
    if (!this.isWholesale()) {
      p -= this.retailBillDiscountEffective();
    }
    return this.round2(Math.max(0, p));
  });
  readonly cdAmount = computed(() => {
    if (!this.isWholesale()) return 0;
    return this.round2(this.bill().lines.reduce((s, l) => s + this.lineCdAmt(l), 0));
  });
  readonly netBeforeRound = computed(() => {
    const b = this.bill();
    let net = this.itemsSell();
    if (this.isWholesale()) {
      net -= Number(b.creditDeductionAmt) || 0;
    } else {
      net -= this.retailBillDiscountEffective();
    }
    return Math.max(0, this.round2(net));
  });
  readonly netTotal = computed(() => Math.round(this.netBeforeRound()));
  readonly roundOff = computed(() => this.round2(this.netTotal() - this.netBeforeRound()));
  readonly splitUpiAmount = computed(() =>
    Math.max(0, this.round2(this.netTotal() - (Number(this.bill().cashAmount) || 0))),
  );
  readonly cgst = computed(() => (this.bill().isInterstate ? 0 : this.gstTotal() / 2));
  readonly sgst = computed(() => (this.bill().isInterstate ? 0 : this.gstTotal() - this.cgst()));
  readonly igst = computed(() => (this.bill().isInterstate ? this.gstTotal() : 0));

  // Wholesale is B2B: the buyer is a licensed party, so prescription / doctor
  // gates are relaxed to warnings only (no hard enforcement).
  readonly hasScheduleX = computed(() => this.bill().lines.some((l) => l.category === 'X'));
  readonly hasH1 = computed(() => this.bill().lines.some((l) => l.category === 'H1'));
  readonly hasScheduledItem = computed(() =>
    this.bill().lines.some((l) => PRESCRIPTION_SCHEDULES.includes(l.category)),
  );
  // Enforcement only applies to retail billing.
  readonly prescriptionRequired = computed(() => !this.isWholesale() && this.hasScheduledItem());
  readonly hasX = computed(() => !this.isWholesale() && this.hasScheduleX());
  readonly enforceH1 = computed(() => !this.isWholesale() && this.hasH1());

  scheduleMeaning(code: string): string {
    return SCHEDULE_MEANING[code] ?? code;
  }
  isAlertSchedule(code: string): boolean {
    return ALERT_SCHEDULES.includes(code);
  }

  // ---- prescription / camera ----
  onPrescriptionFile(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;
    this.uploadingPrescription.set(true);
    this.salesSvc.uploadPrescription(file, 'upload', this.bill().doctor).subscribe({
      next: (res) => { this.patchBill({ prescriptionId: res.data.id, prescriptionName: file.name }); this.uploadingPrescription.set(false); },
      error: () => this.uploadingPrescription.set(false),
    });
    input.value = '';
  }
  async openCamera(): Promise<void> {
    this.cameraOpen.set(true);
    try {
      this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
      const v = this.video()?.nativeElement;
      if (v) { v.srcObject = this.stream; await v.play(); }
    } catch { this.error.set('Unable to access camera.'); this.cameraOpen.set(false); }
  }
  capture(): void {
    const v = this.video()?.nativeElement;
    if (!v) return;
    const canvas = document.createElement('canvas');
    canvas.width = v.videoWidth; canvas.height = v.videoHeight;
    canvas.getContext('2d')!.drawImage(v, 0, 0);
    canvas.toBlob((blob) => {
      if (!blob) return;
      this.uploadingPrescription.set(true);
      this.salesSvc.uploadPrescription(blob, 'camera', this.bill().doctor).subscribe({
        next: (res) => { this.patchBill({ prescriptionId: res.data.id, prescriptionName: 'Camera capture' }); this.uploadingPrescription.set(false); this.closeCamera(); },
        error: () => this.uploadingPrescription.set(false),
      });
    }, 'image/png');
  }
  closeCamera(): void {
    this.stream?.getTracks().forEach((t) => t.stop());
    this.stream = undefined;
    this.cameraOpen.set(false);
  }

  // ---- save (Save | Save & WhatsApp — retail Regular + wholesale) ----
  save(mode: 'save' | 'saveWithWhatsapp' = 'save'): void {
    this.syncPatientFieldFromSearch();
    if (this.isCashCounterActive() && this.billHasCashSendDrug()) {
      this.error.set(
        'Send Schedule H1 / X / Restricted products to Counter 1–3 using the Counter dropdown on the product row.',
      );
      return;
    }
    if (this.promoteCashBillToRegularIfScheduled()) {
      this.error.set('Scheduled drugs require a Regular bill. Enter patient details and save again.');
      return;
    }
    const b = this.bill();
    if (b.lines.length === 0) { this.error.set('Add at least one product.'); return; }

    for (let i = 0; i < b.lines.length; i++) {
      const l = b.lines[i];
      const qty = Number(l.qty);
      if (!Number.isFinite(qty) || qty <= 0) {
        this.error.set(`${l.product_name}: enter a quantity of at least 1.`);
        return;
      }
      if (!l.binding_id) {
        this.error.set(`${l.product_name}: product is not bound to this branch.`);
        return;
      }
      if (this.isExpiryPast(l.expiry)) {
        this.error.set(`${l.product_name}: batch is expired and cannot be sold.`);
        return;
      }
      if (!l.batch_id) {
        this.error.set(`${l.product_name}: no batch selected — stock must deduct from a specific batch.`);
        return;
      }
      if (this.isWholesale() && (!Number.isFinite(Number(l.rate)) || Number(l.rate) <= 0)) {
        this.error.set(`${l.product_name}: enter a valid sale rate.`);
        return;
      }
      const dupBatch = b.lines.findIndex(
        (x, j) => j !== i && x.binding_id === l.binding_id && x.batch_id === l.batch_id,
      );
      if (dupBatch >= 0) {
        this.error.set(
          `${l.product_name}: batch ${l.batch_no || ''} appears twice — use one line per batch.`,
        );
        return;
      }
      const upp = Math.max(1, l.units_per_pack || 1);
      const baseQty = l.unit_type === 'pack' ? Math.round(qty * upp) : Math.round(qty);
      const freeBase = this.isWholesale()
        ? (l.unit_type === 'pack'
          ? Math.round((l.free_qty || 0) * upp)
          : Math.round(l.free_qty || 0))
        : 0;
      const batch = l.batches.find((b) => b.batch_id === l.batch_id);
      const batchAvail = Number(batch?.quantity) || 0;
      // Sum demand for this batch across all lines (batch-wise, not product-total).
      let batchNeed = 0;
      for (const x of b.lines) {
        if (x.batch_id !== l.batch_id) continue;
        const xUpp = Math.max(1, x.units_per_pack || 1);
        const xQty = Number(x.qty) || 0;
        const xSold = x.unit_type === 'pack' ? Math.round(xQty * xUpp) : Math.round(xQty);
        const xFree = this.isWholesale()
          ? (x.unit_type === 'pack'
            ? Math.round((x.free_qty || 0) * xUpp)
            : Math.round(x.free_qty || 0))
          : 0;
        batchNeed += xSold + xFree;
      }
      if (batchNeed > batchAvail) {
        this.error.set(
          `Insufficient stock for ${l.product_name} batch ${l.batch_no || ''} (have ${batchAvail}, need ${batchNeed}).`,
        );
        return;
      }
      if (baseQty + freeBase > batchAvail) {
        this.error.set(
          `Insufficient sellable stock for ${l.product_name} batch ${l.batch_no || ''} (have ${batchAvail}, need ${baseQty + freeBase}).`,
        );
        return;
      }
    }

    const phoneRequired = this.isRegularSale() && !this.isWholesale();
    const phoneErr = phoneError(b.contact, phoneRequired);
    if (phoneErr) { this.error.set(phoneErr); return; }
    const mailErr = emailError(b.email, false);
    if (mailErr) { this.error.set(mailErr); return; }
    const addrErr = addressError(b.address, false);
    if (addrErr) { this.error.set(addrErr); return; }
    if (b.mode === 'retail' && this.isRegularSale()) {
      const remErr = reminderDaysError(b.reminderDays, false);
      if (remErr) { this.error.set(remErr); return; }
    }

    if (b.paymentMode === 'Card' && !b.accountId) {
      this.error.set('Select a bank Account for Card payment.');
      return;
    }
    if (b.paymentMode === 'Cash+UPI') {
      const cash = Number(b.cashAmount) || 0;
      if (cash <= 0 || cash >= this.netTotal()) {
        this.error.set(`Cash+UPI: enter the Cash amount — more than 0 and less than Net ₹${this.netTotal().toFixed(2)}.`);
        return;
      }
    }

    if (this.isRegularSale() && !this.isWholesale()) {
      if (!b.patientName.trim() || b.patientName.trim().toLowerCase() === 'walk-in') {
        this.error.set('Patient Name is required for Regular sale.');
        return;
      }
    }

    if (this.prescriptionRequired() && !b.prescriptionId) {
      this.error.set(this.hasX()
        ? 'Schedule X (Narcotic): prescription upload is mandatory.'
        : 'Prescription is mandatory for the scheduled drugs in this bill.');
      return;
    }
    // Schedule H1: doctor & customer details are mandatory for RETAIL Regular only.
    if (this.enforceH1() && this.isRegularSale()) {
      if (!b.doctor.trim()) { this.error.set('Schedule H1: Doctor details are mandatory.'); return; }
      if (!b.patientName.trim()) { this.error.set('Schedule H1: Customer / Patient details are mandatory.'); return; }
      const h1Phone = phoneError(b.contact, true);
      if (h1Phone) { this.error.set(h1Phone); return; }
    }
    if (this.isWholesale() && !b.patientName.trim()) {
      this.error.set('Customer name is required for wholesale bill.');
      return;
    }

    // New patient / wholesale customer: require name + mobile so Customers master is updated.
    const isNewCustomer = this.addingPatient() || (!b.customerId && !!b.patientName.trim() && b.patientName.trim().toLowerCase() !== 'walk-in');
    if (isNewCustomer && (this.isRegularSale() || this.isWholesale())) {
      if (!b.patientName.trim()) {
        this.error.set(this.isWholesale() ? 'Enter the new customer name.' : 'Enter the new patient name.');
        return;
      }
      const newPhone = phoneError(b.contact, true);
      if (newPhone) {
        this.error.set(this.isWholesale()
          ? 'Mobile number is required to save a new wholesale customer.'
          : 'Mobile number is required to save a new patient to Customers master.');
        return;
      }
    }
    if (this.isWholesale() && !b.customerId && !this.addingPatient()) {
      this.error.set('Select a wholesale customer or choose + New wholesale customer…');
      return;
    }
    if (mode === 'saveWithWhatsapp') {
      const waPhone = phoneError(b.contact, true);
      if (waPhone) {
        this.error.set('Customer mobile number is required for Save & WhatsApp.');
        return;
      }
    }

    this.saving.set(true);
    this.error.set(null);

    const patientName = (!this.isRegularSale() && !this.isWholesale())
      ? (b.patientName.trim() || 'Walk-in')
      : b.patientName;

    const customerType: SalePayload['customer_type'] =
      b.mode === 'wholesale' ? 'wholesale' : this.ipdAdmissionId() ? 'ipd' : 'retail';
    const mrOrderId = b.mode === 'wholesale' && this.linkedMrCounter() === this.activeCounter()
      ? this.linkedMrOrderId()
      : null;
    const payload: SalePayload = {
      counter: this.activeCounter(),
      sale_type: b.saleType,
      bill_mode: b.mode,
      invoice_date: b.billDate || undefined,
      rate_type: b.mode === 'wholesale' ? b.rateType : undefined,
      customer_id: b.customerId != null && b.customerId > 0 ? b.customerId : null,
      customer_name: patientName || 'Walk-in',
      patient_name: patientName,
      customer_type: customerType,
      contact_no: b.contact,
      email: b.email,
      address: b.address,
      narration: b.narration,
      reminder_days: b.mode === 'retail' && this.isRegularSale() ? b.reminderDays : null,
      doctor_name: b.doctor,
      payment_mode: b.paymentMode.toLowerCase(),
      account_id: b.paymentMode === 'Card' ? b.accountId : null,
      cash_amount: b.paymentMode === 'Cash+UPI' ? this.round2(Number(b.cashAmount) || 0) : undefined,
      cd_disc_pct: b.mode === 'wholesale' ? b.cdDiscPct : 0,
      bill_discount_amount:
        b.mode === 'retail'
          ? Math.min(
              Math.max(0, Number(b.billDiscountAmt) || 0),
              b.lines.reduce((s, l) => s + this.lineSell(l), 0),
            )
          : undefined,
      credit_note_no: b.mode === 'wholesale' && b.creditNoteNo ? b.creditNoteNo : undefined,
      credit_deduction_amt: b.mode === 'wholesale' ? b.creditDeductionAmt : 0,
      is_interstate: b.isInterstate ? 1 : 0,
      gst_inclusive: this.gstInclusive() ? 1 : 0,
      is_chronic: b.isChronic ? 1 : 0,
      ipd_admission_id: this.ipdAdmissionId(),
      prescription_id: b.prescriptionId,
      send_whatsapp: mode === 'saveWithWhatsapp' ? 1 : 0,
      agt_order_id: this.linkedAgtOrderId(),
      mr_order_id: mrOrderId,
      items: b.lines.map((l) => {
        const upp = Math.max(1, l.units_per_pack);
        const billPacks = l.qty;
        const freePacks = this.isWholesale() ? (l.free_qty || 0) : 0;
        const baseQty = l.unit_type === 'pack'
          ? Math.round(billPacks * upp)
          : Math.round(billPacks);
        const freeBaseQty = this.isWholesale()
          ? Math.round(freePacks * upp)
          : 0;
        const packRate = l.rate;
        const baseRate = l.unit_type === 'pack' ? packRate / upp : packRate;
        return {
          product_binding_id: l.binding_id,
          schedule_category: l.category,
          quantity: baseQty,
          free_quantity: freeBaseQty,
          rate: Math.round(baseRate * 100) / 100,
          discount_pct: Number(l.disc) || 0,
          batch_id: l.batch_id,
          unit_type: l.unit_type,
          pack: l.pack,
          shelf: l.shelf,
          hsn_code: l.hsn_code,
          oth_rate: this.isWholesale() ? l.oth_rate : undefined,
          base_rate: this.isWholesale() ? l.base_rate : undefined,
          net_rate: this.isWholesale() ? l.net_rate : undefined,
          billing_with: this.isWholesale() ? l.billing_with : undefined,
          sale_scheme_pct: this.isWholesale() ? l.sale_scheme_pct : undefined,
        };
      }),
    };
    this.salesSvc.create(payload).subscribe({
      next: (res) => {
        this.saving.set(false);
        this.completed.set(res.data);
        this.whatsappMsg.set(res.data.whatsapp_message ?? null);
        this.addingPatient.set(false);
        this.reloadCustomers();
        const counter = this.activeCounter();
        const billMode = b.mode;
        this.bills.update((all) => ({ ...all, [counter]: emptyBillForCounter(counter, billMode) }));
        if (this.isCashCounter(counter)) {
          this.applyCashCounterRetail();
        }
        this.clearIpd();
        if (mrOrderId) this.clearLinkedMrOrder();
        const sale = res.data;
        if (sale?.id) {
          this.markLinkedAgtReady(
            String(sale.invoice_no ?? sale.id),
            Number(sale.id),
            Number(sale.net_amount ?? 0),
          );
        }
        this.loadInitial();
      },
      error: (err: HttpErrorResponse) => {
        this.saving.set(false);
        const errors = err.error?.errors as Record<string, string[] | string> | undefined;
        let first: string | null = null;
        if (errors) {
          const val = Object.values(errors)[0];
          first = Array.isArray(val) ? val[0] : (typeof val === 'string' ? val : null);
        }
        this.error.set(first ?? err.error?.message ?? 'Could not complete the sale.');
      },
    });
  }

  printInvoice(id?: number): void {
    const saleId = id ?? this.completed()?.id;
    if (!saleId) return;
    this.salesSvc.invoiceBlob(saleId).subscribe((blob) => window.open(URL.createObjectURL(blob), '_blank'));
  }

  printDual(id?: number): void {
    const saleId = id ?? this.completed()?.id;
    if (!saleId) return;
    this.salesSvc.invoiceDualBlob(saleId).subscribe((blob) => window.open(URL.createObjectURL(blob), '_blank'));
  }

  sendWhatsApp(): void {
    const saleId = this.completed()?.id;
    if (!saleId) return;
    this.whatsappSending.set(true);
    this.whatsappMsg.set(null);
    this.salesSvc.sendWhatsApp(saleId).subscribe({
      next: (res) => {
        this.whatsappSending.set(false);
        this.whatsappMsg.set(res.message || 'WhatsApp bill sent.');
      },
      error: (err: HttpErrorResponse) => {
        this.whatsappSending.set(false);
        const errors = err.error?.errors as Record<string, string[]> | undefined;
        const first = errors ? (Object.values(errors)[0] as string[] | undefined)?.[0] : null;
        this.whatsappMsg.set(first ?? err.error?.message ?? 'Failed to send WhatsApp bill.');
      },
    });
  }

  newBill(): void {
    this.completed.set(null);
    this.error.set(null);
    this.whatsappMsg.set(null);
    this.tabToCustomerAfterAdd = false;
    this.focusProductSearch();
  }

  closePos(): void {
    if (this.posOverlay()) {
      this.posModal.hide();
      return;
    }
    this.newBill();
  }

  viewSales(): void {
    // Close POS first — otherwise the full-screen billing overlay hides the log
    // (common when NEW SALE was opened from /app/sales).
    this.posModal.hide();
    this.salesSvc.notifyLogChanged();
    const onSalesLog = this.router.url.startsWith('/app/sales') && !this.router.url.startsWith('/app/sales/new');
    if (!onSalesLog) {
      void this.router.navigate(['/app/sales']);
    }
  }

  todayLocalDate(): string {
    return localYmd();
  }

  /**
   * Open counters keep billDate from when the draft was first opened.
   * If that date is in the past, use today so a new bill is not dated yesterday
   * (common when continuing a draft and picking a previously billed patient).
   */
  private refreshStaleDraftBillDates(): void {
    const today = localYmd();
    const all = this.bills();
    let changed = false;
    const next: Record<string, Bill> = {};
    for (const [key, bill] of Object.entries(all)) {
      if (!bill) continue;
      const d = String(bill.billDate || '').slice(0, 10);
      if (d && /^\d{4}-\d{2}-\d{2}$/.test(d) && d < today) {
        next[key] = { ...bill, billDate: today };
        changed = true;
      } else {
        next[key] = bill;
      }
    }
    if (changed) this.bills.set(next);
  }

  openCashCounterDayReport(): void {
    this.cashDayOpen.set(true);
    this.cashDayError.set(null);
    if (!this.cashDayDate()) this.cashDayDate.set(this.todayLocalDate());
    if (!this.pharmacyProfile) {
      this.profileSvc.load().subscribe({
        next: (p) => { this.pharmacyProfile = p; },
        error: () => { this.pharmacyProfile = null; },
      });
    }
    this.loadCashCounterDay();
  }

  closeCashCounterDayReport(): void {
    this.cashDayOpen.set(false);
    this.cashDayError.set(null);
  }

  onCashDayDateChange(value: string): void {
    this.cashDayDate.set(value || this.todayLocalDate());
    this.loadCashCounterDay();
  }

  loadCashCounterDay(): void {
    const day = this.cashDayDate() || this.todayLocalDate();
    this.cashDayLoading.set(true);
    this.cashDayError.set(null);
    this.reportSvc.get<CashCounterDayReport>('cash-counter-day', { from: day, to: day, date: day }).subscribe({
      next: (d) => {
        this.cashDay.set(d);
        this.cashDayLoading.set(false);
      },
      error: (err: HttpErrorResponse) => {
        this.cashDayLoading.set(false);
        this.cashDay.set(null);
        this.cashDayError.set(err.error?.message || 'Could not load Cash/Counter day report.');
      },
    });
  }

  formatCashDayDate(raw: string | null | undefined): string {
    if (!raw) return '';
    const d = String(raw).slice(0, 10);
    if (!/^\d{4}-\d{2}-\d{2}$/.test(d)) return String(raw);
    const [y, m, day] = d.split('-');
    return `${day}-${m}-${y}`;
  }

  private cashDayPeriodLabel(): string {
    const day = this.cashDay()?.from || this.cashDayDate();
    return this.formatCashDayDate(day);
  }

  private cashDayPharmacyHeader(): ReportPharmacyHeader {
    const p = this.pharmacyProfile;
    const user = this.auth.user() as { business_name?: string; branch_name?: string } | null;
    return {
      businessName: (p?.business_name || '').trim() || user?.business_name || this.meta.businessName() || 'Pharmacy',
      address: (p?.address || '').trim() || undefined,
      city: (p?.city || '').trim() || undefined,
      state: (p?.state || '').trim() || undefined,
      phone: (p?.phone || '').trim() || undefined,
      email: (p?.email || '').trim() || undefined,
      gstin: (p?.gstin || '').trim() || undefined,
      drugLicenseNo: (p?.drug_license_no || '').trim() || undefined,
      fssaiNo: (p?.fssai_no || '').trim() || undefined,
      branchName: user?.branch_name || undefined,
      period: this.cashDayPeriodLabel(),
    };
  }

  async downloadCashCounterDayExcel(): Promise<void> {
    const data = this.cashDay();
    if (!data?.rows?.length) return;
    this.cashDayExporting.set(true);
    try {
      const headers = ['Invoice', 'Date', 'Counter', 'Payment', 'Items', 'Gross', 'Discount', 'GST', 'Net'];
      const rows = data.rows.map((r) => [
        r.invoice_no,
        this.formatCashDayDate(r.invoice_date),
        r.counter || '',
        (r.payment_mode || '').toUpperCase(),
        Number(r.item_count) || 0,
        Number(r.gross_amount) || 0,
        Number(r.discount_amount) || 0,
        Number(r.gst_amount) || 0,
        Number(r.net_amount) || 0,
      ]);
      const t = data.totals;
      await this.excelSvc.exportWithStyle({
        reportTitle: 'Cash/Counter Bill Day Report',
        sheetName: 'Cash Counter Day',
        headers,
        rows,
        filename: `Cash_Counter_Day_${data.from || this.cashDayDate()}.xlsx`,
        numericColumnStartIndex: 5,
        footerRow: ['Total', '', '', '', t.items, t.gross, t.discount, t.gst, t.net],
        pharmacy: this.cashDayPharmacyHeader(),
      });
    } finally {
      this.cashDayExporting.set(false);
    }
  }

  downloadCashCounterDayPdf(): void {
    const data = this.cashDay();
    if (!data) return;
    const html = this.buildCashCounterDayPdf(data);
    document.getElementById('cash-day-pdf-frame')?.remove();
    const iframe = document.createElement('iframe');
    iframe.id = 'cash-day-pdf-frame';
    iframe.setAttribute('title', 'Cash/Counter Bill Day Report');
    iframe.style.cssText = 'position:fixed;left:-10000px;top:0;width:1100px;height:800px;border:0;';
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

  private moneyInr(n: number): string {
    return Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  private escHtml(v: string | number | null | undefined): string {
    return String(v ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  private buildCashCounterDayPdf(data: CashCounterDayReport): string {
    const ph = this.cashDayPharmacyHeader();
    const addr = [ph.address, ph.city, ph.state, ph.pincode].filter(Boolean).join(', ');
    const ids: string[] = [];
    if (ph.drugLicenseNo) ids.push(`DL No.: ${ph.drugLicenseNo}`);
    if (ph.gstin) ids.push(`GSTIN: ${ph.gstin}`);
    if (ph.fssaiNo) ids.push(`FSSAI: ${ph.fssaiNo}`);
    if (ph.phone) ids.push(`Ph: ${ph.phone}`);
    const t = data.totals;
    const body = (data.rows || []).map((r) => `
      <tr>
        <td>${this.escHtml(r.invoice_no)}</td>
        <td>${this.escHtml(this.formatCashDayDate(r.invoice_date))}</td>
        <td>${this.escHtml(r.counter)}</td>
        <td>${this.escHtml((r.payment_mode || '').toUpperCase())}</td>
        <td class="num">${this.escHtml(r.item_count)}</td>
        <td class="num">${this.escHtml(this.moneyInr(r.gross_amount))}</td>
        <td class="num">${this.escHtml(this.moneyInr(r.discount_amount))}</td>
        <td class="num">${this.escHtml(this.moneyInr(r.gst_amount))}</td>
        <td class="num">${this.escHtml(this.moneyInr(r.net_amount))}</td>
      </tr>`).join('');
    return `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>Cash/Counter Bill Day Report — ${this.escHtml(ph.businessName)}</title>
  <style>
    @page { size: A4 landscape; margin: 12mm; }
    body { font-family: "Segoe UI", Calibri, Arial, sans-serif; color: #0f172a; font-size: 11px; margin: 0; }
    .pdf-header { border-bottom: 2px solid #0f766e; padding-bottom: 10px; margin-bottom: 12px; text-align: center; }
    .brand { font-size: 18px; font-weight: 700; color: #0f766e; margin: 0 0 4px; }
    .addr, .ids { margin: 2px 0; color: #334155; font-size: 10px; }
    h1 { font-size: 14px; margin: 10px 0; text-align: center; }
    .totals { display: flex; flex-wrap: wrap; gap: 14px; margin: 0 0 12px; padding: 8px 10px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #0f766e; color: #fff; padding: 6px 8px; text-align: left; font-size: 10px; }
    td { border-bottom: 1px solid #e2e8f0; padding: 5px 8px; }
    td.num, th.num { text-align: right; }
    tfoot td { font-weight: 700; background: #e7eef7; border-top: 2px solid #0f172a; }
  </style>
</head>
<body>
  <div class="pdf-header">
    <p class="brand">${this.escHtml(ph.businessName)}</p>
    ${addr ? `<p class="addr">${this.escHtml(addr)}</p>` : ''}
    ${ids.length ? `<p class="ids">${this.escHtml(ids.join('  |  '))}</p>` : ''}
  </div>
  <h1>Cash/Counter Bill Day Report</h1>
  <div class="totals">
    <span>Date: <b>${this.escHtml(this.cashDayPeriodLabel())}</b></span>
    <span>Memos: <b>${this.escHtml(t.count)}</b></span>
    <span>Items: <b>${this.escHtml(t.items)}</b></span>
    <span>Gross: <b>₹${this.escHtml(this.moneyInr(t.gross))}</b></span>
    <span>Discount: <b>₹${this.escHtml(this.moneyInr(t.discount))}</b></span>
    <span>GST: <b>₹${this.escHtml(this.moneyInr(t.gst))}</b></span>
    <span>Net: <b>₹${this.escHtml(this.moneyInr(t.net))}</b></span>
  </div>
  <table>
    <thead>
      <tr>
        <th>Invoice</th><th>Date</th><th>Counter</th><th>Payment</th>
        <th class="num">Items</th><th class="num">Gross</th><th class="num">Discount</th>
        <th class="num">GST</th><th class="num">Net</th>
      </tr>
    </thead>
    <tbody>
      ${body || '<tr><td colspan="9" style="text-align:center;color:#64748b;padding:16px;">No cash/counter memos for this date.</td></tr>'}
    </tbody>
    ${data.rows.length ? `<tfoot><tr>
      <td colspan="4">Total</td>
      <td class="num">${this.escHtml(t.items)}</td>
      <td class="num">${this.escHtml(this.moneyInr(t.gross))}</td>
      <td class="num">${this.escHtml(this.moneyInr(t.discount))}</td>
      <td class="num">${this.escHtml(this.moneyInr(t.gst))}</td>
      <td class="num">${this.escHtml(this.moneyInr(t.net))}</td>
    </tr></tfoot>` : ''}
  </table>
</body>
</html>`;
  }
}
