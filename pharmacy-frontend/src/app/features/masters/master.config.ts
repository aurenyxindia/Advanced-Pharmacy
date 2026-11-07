export type FieldType =
  | 'text'
  | 'number'
  | 'email'
  | 'select'
  | 'packSelect'
  | 'genericSelect'
  | 'manufacturerSelect'
  | 'textarea'
  | 'date'
  | 'password';

export type OptionSource = 'schedule' | 'branches' | 'roles' | 'generics' | 'manufacturers' | 'gstStates' | 'packSizes';

/** Built-in validation presets used by MasterPage forms. */
export type FieldValidation = 'phone' | 'phoneDistributor' | 'gstin' | 'pincode' | 'hsn' | 'hsnStrict' | 'fssai' | 'pan' | 'ifsc';

export interface ShowWhenRule {
  field: string;
  equals: string | number | Array<string | number>;
}

export interface FormField {
  key: string;
  label: string;
  type: FieldType;
  required?: boolean;
  col?: string; // bootstrap column class e.g. 'col-md-6'
  placeholder?: string;
  options?: { value: string | number; label: string }[];
  optionSource?: OptionSource;
  min?: number;
  max?: number;
  hideOnEdit?: boolean; // e.g. password on edit
  /** Auto-convert typed text to UPPERCASE (skipped for email/password/number/date). */
  uppercase?: boolean;
  /** Digits-only / GSTIN / pincode helpers. */
  validation?: FieldValidation;
  maxLength?: number;
  hint?: string;
  /** Show field only when another control matches (AND if array). */
  showWhen?: ShowWhenRule | ShowWhenRule[];
  /** Visual group inside a master popup (used by Product Master). */
  section?: string;
}

export type ColumnType = 'text' | 'currency' | 'number' | 'badge' | 'date';

export interface TableColumn {
  key: string;
  label: string;
  type?: ColumnType;
  badgeClass?: Record<string, string>;
}

export interface MasterListFilter {
  /** Query-string param sent to the API. */
  key: string;
  label: string;
  /** Built-in option sets. */
  source: 'schedule' | 'genericCategories';
  /** Show count next to each option (from stats). */
  showCount?: boolean;
}

export interface MasterConfig {
  key: string;
  title: string;
  subtitle: string;
  icon: string;
  resourcePath: string;
  columns: TableColumn[];
  fields: FormField[];
  canCreate?: boolean;
  canEdit?: boolean;
  canDelete?: boolean;
  bindable?: boolean; // products: allow binding to branch
  statsPath?: string; // optional endpoint returning { cards: [{label,value,sub,icon}] }
  /** Soft KPI cards derived from list rows (payments / notes). */
  kpiPreset?: 'payments' | 'credit-notes' | 'debit-notes' | 'accounts';
  /** Toolbar filters (e.g. Generic Master schedule / therapeutic class). */
  listFilters?: MasterListFilter[];
}

export const PRODUCT_CATEGORIES = [
  'Allopathy',
  'ALLOPATHIC',
  'ALLOPATHIC (Ethical/Generic/PCD/Other)',
  'Ayurvedic',
  'AYURVEDIC',
  'AYURVEDIC (Branded/Other)',
  'Generic',
  'Surgical',
  'SURGICAL (Equipment/IV/Hosp/Common)',
  'Cosmetic',
  'COSMETICS (Branded / Generic/Market)',
  'FOOD',
  'NUTRA',
  'NUTRA/FOOD (Speciality)',
  'HOMEOPATHIC',
  'VETERINORY (Branded/Generic/Feed/Suppliment)',
  'PCD',
  'PCD1',
  'PCD2',
  'PCD3',
];

/** Retail product type (Medicine / General / …). */
export const PRODUCT_TYPES = ['Medicine', 'General', 'Food/Nutra', 'Cosmetics', 'Patanjali'];

/** Common dosage forms (Retail `form` / d_form). */
export const DOSAGE_FORMS = [
  'TABLET', 'CAPSULE', 'SYRUP', 'INJECTION', 'CREAM', 'OINTMENT', 'DROPS',
  'POWDER', 'SUSPENSION', 'GEL', 'LOTION', 'INHALER', 'SACHET', 'SPRAY',
  'SOLUTION', 'INFUSION', 'PATCH', 'OTHER',
];

/** Indian GST state codes (Retail distributor master). */
export const GST_STATE_CODES = [
  { value: 'Jammu & Kashmir', label: 'Jammu & Kashmir', code: '01' },
  { value: 'Himachal Pradesh', label: 'Himachal Pradesh', code: '02' },
  { value: 'Punjab', label: 'Punjab', code: '03' },
  { value: 'Chandigarh', label: 'Chandigarh', code: '04' },
  { value: 'Uttarakhand', label: 'Uttarakhand', code: '05' },
  { value: 'Haryana', label: 'Haryana', code: '06' },
  { value: 'Delhi', label: 'Delhi', code: '07' },
  { value: 'Rajasthan', label: 'Rajasthan', code: '08' },
  { value: 'Uttar Pradesh', label: 'Uttar Pradesh', code: '09' },
  { value: 'Bihar', label: 'Bihar', code: '10' },
  { value: 'Sikkim', label: 'Sikkim', code: '11' },
  { value: 'Arunachal Pradesh', label: 'Arunachal Pradesh', code: '12' },
  { value: 'Nagaland', label: 'Nagaland', code: '13' },
  { value: 'Manipur', label: 'Manipur', code: '14' },
  { value: 'Mizoram', label: 'Mizoram', code: '15' },
  { value: 'Tripura', label: 'Tripura', code: '16' },
  { value: 'Meghalaya', label: 'Meghalaya', code: '17' },
  { value: 'Assam', label: 'Assam', code: '18' },
  { value: 'West Bengal', label: 'West Bengal', code: '19' },
  { value: 'Jharkhand', label: 'Jharkhand', code: '20' },
  { value: 'Odisha', label: 'Odisha', code: '21' },
  { value: 'Chhattisgarh', label: 'Chhattisgarh', code: '22' },
  { value: 'Madhya Pradesh', label: 'Madhya Pradesh', code: '23' },
  { value: 'Gujarat', label: 'Gujarat', code: '24' },
  { value: 'Daman & Diu', label: 'Daman & Diu', code: '25' },
  { value: 'Dadra & Nagar Haveli', label: 'Dadra & Nagar Haveli', code: '26' },
  { value: 'Maharashtra', label: 'Maharashtra', code: '27' },
  { value: 'Andhra Pradesh', label: 'Andhra Pradesh', code: '28' },
  { value: 'Karnataka', label: 'Karnataka', code: '29' },
  { value: 'Goa', label: 'Goa', code: '30' },
  { value: 'Lakshadweep', label: 'Lakshadweep', code: '31' },
  { value: 'Kerala', label: 'Kerala', code: '32' },
  { value: 'Tamil Nadu', label: 'Tamil Nadu', code: '33' },
  { value: 'Puducherry', label: 'Puducherry', code: '34' },
  { value: 'Andaman & Nicobar Islands', label: 'Andaman & Nicobar Islands', code: '35' },
  { value: 'Telangana', label: 'Telangana', code: '36' },
  { value: 'Andhra Pradesh (New)', label: 'Andhra Pradesh (New)', code: '37' },
  { value: 'Ladakh', label: 'Ladakh', code: '38' },
];

const scheduleBadge: Record<string, string> = {
  OTC: 'text-bg-secondary',
  H: 'text-bg-warning',
  H1: 'text-bg-danger',
  X: 'text-bg-dark',
  G: 'text-bg-info',
  RESTRICTED: 'text-bg-danger',
};

export const MASTER_CONFIGS: Record<string, MasterConfig> = {
  generics: {
    key: 'generics',
    title: 'Generic Master',
    subtitle: 'Generic / molecule catalog with schedule class',
    icon: 'bi-droplet-half',
    resourcePath: 'generics',
    statsPath: 'generics/stats',
    canCreate: true,
    canDelete: true,
    columns: [
      { key: 'name', label: 'Generic Name' },
      { key: 'therapeutic_class', label: 'Therapeutic Class' },
      { key: 'schedule_category', label: 'Class of Drug', type: 'badge', badgeClass: scheduleBadge },
    ],
    fields: [
      { key: 'name', label: 'Generic Name', type: 'text', required: true, section: 'identity', col: 'col-12', uppercase: true, placeholder: 'Enter generic / molecule name' },
      { key: 'therapeutic_class', label: 'Therapeutic Class', type: 'text', section: 'class', col: 'col-12 col-md-6', uppercase: true, placeholder: 'e.g. ANALGESIC' },
      {
        key: 'schedule_category',
        label: 'Class of Drug',
        type: 'select',
        required: true,
        section: 'class',
        col: 'col-12 col-md-6',
        optionSource: 'schedule',
      },
    ],
    listFilters: [
      { key: 'therapeutic_class', label: 'Category', source: 'genericCategories', showCount: true },
      { key: 'schedule_category', label: 'Class of Drug', source: 'schedule', showCount: true },
    ],
  },

  hsn: {
    key: 'hsn',
    title: 'HSN Master',
    subtitle: 'HSN codes for GST billing',
    icon: 'bi-upc-scan',
    resourcePath: 'hsn-codes',
    canCreate: true,
    canEdit: false,
    canDelete: false,
    columns: [
      { key: 'code', label: 'HSN' },
    ],
    fields: [
      {
        key: 'code',
        label: 'HSN',
        type: 'text',
        required: true,
        col: 'col-md-6',
        validation: 'hsnStrict',
        maxLength: 8,
        placeholder: 'Enter HSN (4, 6 or 8 digits)',
        hint: '4, 6, or 8 digits only — duplicate not allowed',
      },
    ],
  },

  manufacturers: {
    key: 'manufacturers',
    title: 'Manufacturer Master',
    subtitle: 'Companies used on products and bills',
    icon: 'bi-building',
    resourcePath: 'manufacturers',
    canCreate: true,
    canDelete: true,
    columns: [
      { key: 'name', label: 'Manufacturer Name' },
      { key: 's_code', label: 'S Code' },
    ],
    fields: [
      {
        key: 'name',
        label: 'Manufacturer Name',
        type: 'text',
        required: true,
        section: 'identity',
        col: 'col-12',
        uppercase: true,
        placeholder: 'Enter Manufacturer Name',
      },
      {
        key: 's_code',
        label: 'S Code',
        type: 'text',
        required: true,
        section: 'code',
        col: 'col-12 col-md-6',
        uppercase: true,
        maxLength: 20,
        placeholder: 'e.g. CIP, SUN',
      },
    ],
  },

  products: {
    key: 'products',
    title: 'Product Master',
    subtitle: 'Product catalog with schedule classification',
    icon: 'bi-capsule',
    resourcePath: 'products',
    canCreate: true,
    canDelete: true,
    bindable: true,
    statsPath: 'products/stats',
    columns: [
      { key: 'name', label: 'Product' },
      { key: 'generic_name', label: 'Generic' },
      { key: 'product_type', label: 'Type' },
      { key: 'manufacturer', label: 'Manufacturer' },
      { key: 'manufacturer_short', label: 'Mfg' },
      { key: 'schedule_category', label: 'Schedule', type: 'badge', badgeClass: scheduleBadge },
      { key: 'dosage_form', label: 'Dosage' },
      { key: 'strength', label: 'Strength' },
      { key: 'pack_size', label: 'Pack' },
      { key: 'mrp', label: 'MRP', type: 'currency' },
      { key: 'old_mrp', label: 'Old MRP', type: 'currency' },
      { key: 'default_gst_rate', label: 'GST %', type: 'number' },
    ],
    fields: [
      {
        key: 'product_type',
        label: 'Product Type',
        type: 'select',
        required: true,
        section: 'identity',
        col: 'col-12 col-md-4',
        options: PRODUCT_TYPES.map((t) => ({ value: t, label: t })),
      },
      { key: 'name', label: 'Product Name', type: 'text', required: true, section: 'identity', col: 'col-12 col-md-8', uppercase: true },
      { key: 'generic_id', label: 'Generic', type: 'genericSelect', optionSource: 'generics', section: 'catalog', col: 'col-12 col-md-6', placeholder: 'Search generic…' },
      {
        key: 'manufacturer',
        label: 'Manufacturer',
        type: 'manufacturerSelect',
        optionSource: 'manufacturers',
        section: 'catalog',
        col: 'col-12 col-md-6',
        placeholder: 'Search manufacturer…',
      },
      {
        key: 'manufacturer_short',
        label: 'S Code / Mfg Short',
        type: 'text',
        section: 'catalog',
        col: 'col-6 col-md-4',
        placeholder: 'Auto from manufacturer',
        uppercase: true,
        maxLength: 20,
      },
      {
        key: 'schedule_category',
        label: 'Schedule',
        type: 'select',
        required: true,
        optionSource: 'schedule',
        section: 'catalog',
        col: 'col-6 col-md-4',
      },
      {
        key: 'product_category',
        label: 'Margin Category',
        type: 'select',
        section: 'catalog',
        col: 'col-12 col-md-4',
        options: PRODUCT_CATEGORIES.map((c) => ({ value: c, label: c })),
      },
      {
        key: 'dosage_form',
        label: 'Dosage Form',
        type: 'select',
        section: 'pack',
        col: 'col-6 col-md-4',
        options: DOSAGE_FORMS.map((d) => ({ value: d, label: d })),
      },
      {
        key: 'strength',
        label: 'Strength',
        type: 'text',
        section: 'pack',
        col: 'col-6 col-md-4',
        uppercase: true,
        maxLength: 80,
        placeholder: 'e.g. 500 MG',
      },
      {
        key: 'pack_size',
        label: 'Pack Size',
        type: 'packSelect',
        optionSource: 'packSizes',
        required: true,
        section: 'pack',
        col: 'col-6 col-md-4',
        placeholder: 'Search pack…',
        uppercase: true,
      },
      {
        key: 'packing_desc',
        label: 'Packing Description',
        type: 'text',
        section: 'pack',
        col: 'col-6 col-md-4',
        uppercase: true,
        placeholder: 'e.g. 10×10 STRIP',
      },
      { key: 'units_per_pack', label: 'Units / Pack', type: 'number', section: 'pack', col: 'col-6 col-md-4', min: 1 },
      {
        key: 'allow_loose', label: 'Loose Sale', type: 'select', section: 'pack', col: 'col-6 col-md-4',
        options: [{ value: 1, label: 'Allowed' }, { value: 0, label: 'Not allowed' }],
      },
      {
        key: 'hsn_code', label: 'HSN Code', type: 'text', section: 'price', col: 'col-6 col-md-4',
        validation: 'hsn', maxLength: 8, placeholder: '4–8 digits',
      },
      { key: 'default_gst_rate', label: 'GST %', type: 'number', section: 'price', col: 'col-6 col-md-4', min: 0, max: 28 },
      { key: 'mrp', label: 'MRP (₹)', type: 'number', required: true, section: 'price', col: 'col-12 col-md-4', min: 0, placeholder: 'Retail MRP' },
      { key: 'composition', label: 'Composition', type: 'textarea', section: 'notes', col: 'col-12', uppercase: true },
    ],
    listFilters: [
      { key: 'schedule_category', label: 'Schedule', source: 'schedule', showCount: true },
    ],
  },

  customers: {
    key: 'customers',
    title: 'Customer Master',
    subtitle: 'Retail patients and wholesale buyers',
    icon: 'bi-person-vcard',
    resourcePath: 'customers',
    canCreate: true,
    canDelete: true,
    columns: [
      { key: 'name', label: 'Name' },
      { key: 'customer_type', label: 'Type' },
      { key: 'phone', label: 'Phone' },
      { key: 'gstin', label: 'GSTIN' },
      { key: 'dl_no', label: 'DL No.' },
      { key: 'city', label: 'City' },
    ],
    fields: [
      { key: 'name', label: 'Customer Name', type: 'text', required: true, col: 'col-md-6', uppercase: true },
      {
        key: 'customer_type', label: 'Customer Type', type: 'select', col: 'col-md-6', required: true,
        options: [
          { value: 'retail', label: 'Retail' },
          { value: 'wholesale', label: 'Wholesale' },
          { value: 'ipd', label: 'IPD' },
        ],
      },
      {
        key: 'phone', label: 'Phone', type: 'text', col: 'col-md-6', required: true,
        validation: 'phone', maxLength: 10, placeholder: '10-digit mobile', hint: 'Exactly 10 digits',
      },
      { key: 'email', label: 'Email', type: 'email', col: 'col-md-6' },
      {
        key: 'gender', label: 'Gender', type: 'select', col: 'col-md-6', required: true,
        options: [{ value: 'Male', label: 'Male' }, { value: 'Female', label: 'Female' }],
        showWhen: { field: 'customer_type', equals: 'retail' },
      },
      { key: 'dob', label: 'Date of Birth', type: 'date', col: 'col-md-6' },
      {
        key: 'gst_registered', label: 'GST Registered', type: 'select', col: 'col-md-6', required: true,
        options: [{ value: 0, label: 'NO' }, { value: 1, label: 'YES' }],
      },
      {
        key: 'gstin', label: 'GSTIN', type: 'text', col: 'col-md-6', required: true,
        validation: 'gstin', maxLength: 15, uppercase: true, placeholder: 'Enter 15-character GSTIN',
        hint: 'Exactly 15 characters',
        showWhen: { field: 'gst_registered', equals: [1, '1'] },
      },
      {
        key: 'is_chronic', label: 'Chronic Patient', type: 'select', col: 'col-md-6',
        options: [{ value: 0, label: 'No' }, { value: 1, label: 'Yes' }],
        showWhen: { field: 'customer_type', equals: 'retail' },
      },
      {
        key: 'chronic_val', label: 'Chronic Type', type: 'select', col: 'col-md-6',
        options: [
          { value: 'Diabetic', label: 'Diabetic' },
          { value: 'BP', label: 'BP' },
          { value: 'Heart Disease', label: 'Heart Disease' },
          { value: 'Cancer', label: 'Cancer' },
          { value: 'Arthritis', label: 'Arthritis' },
          { value: 'Others', label: 'Others' },
        ],
        showWhen: [
          { field: 'customer_type', equals: 'retail' },
          { field: 'is_chronic', equals: [1, '1'] },
        ],
      },
      {
        key: 'others_val', label: 'Other Chronic Type', type: 'text', col: 'col-md-6', required: true, uppercase: true,
        showWhen: [
          { field: 'customer_type', equals: 'retail' },
          { field: 'is_chronic', equals: [1, '1'] },
          { field: 'chronic_val', equals: 'Others' },
        ],
      },
      {
        key: 'state', label: 'State', type: 'select', col: 'col-md-6', required: true,
        optionSource: 'gstStates',
        showWhen: { field: 'customer_type', equals: 'wholesale' },
      },
      {
        key: 'city', label: 'City', type: 'text', col: 'col-md-6', required: true, uppercase: true,
        showWhen: { field: 'customer_type', equals: 'wholesale' },
      },
      {
        key: 'area', label: 'Area', type: 'text', col: 'col-md-6', uppercase: true,
        showWhen: { field: 'customer_type', equals: 'wholesale' },
      },
      {
        key: 'dl_no', label: 'DL No.', type: 'text', col: 'col-md-6', uppercase: true,
        placeholder: 'Drug licence number',
        showWhen: { field: 'customer_type', equals: 'wholesale' },
      },
      {
        key: 'medical_type', label: 'Medical Type', type: 'select', col: 'col-md-6', required: true,
        options: [
          { value: 'Retail', label: 'Retail' },
          { value: 'Wholesale', label: 'Wholesale' },
        ],
        showWhen: { field: 'customer_type', equals: 'wholesale' },
      },
      {
        key: 'fssai_no', label: 'FSSAI No.', type: 'text', col: 'col-md-6',
        validation: 'fssai', maxLength: 14, placeholder: '14-digit FSSAI (optional)',
        hint: 'Exactly 14 digits if filled',
        showWhen: { field: 'customer_type', equals: 'wholesale' },
      },
      {
        key: 'pan_no', label: 'PAN No.', type: 'text', col: 'col-md-6',
        validation: 'pan', maxLength: 10, uppercase: true, placeholder: 'ABCDE1234F',
        hint: '10-character PAN if filled',
        showWhen: { field: 'customer_type', equals: 'wholesale' },
      },
      {
        key: 'credit_days', label: 'Credit Days', type: 'select', col: 'col-md-6',
        options: [
          { value: 0, label: 'NA' },
          { value: 7, label: '7' },
          { value: 10, label: '10' },
          { value: 15, label: '15' },
          { value: 20, label: '20' },
          { value: 30, label: '30' },
          { value: 45, label: '45' },
          { value: 60, label: '60' },
          { value: 90, label: '90' },
        ],
        showWhen: { field: 'customer_type', equals: 'wholesale' },
      },
      {
        key: 'pp_client_no', label: 'Paperless Pharmacy Client ID', type: 'text', col: 'col-md-6',
        maxLength: 32, placeholder: 'Client ID (optional)',
        hint: 'Link to another Paperless shop client ID',
        showWhen: { field: 'customer_type', equals: 'wholesale' },
      },
      { key: 'address', label: 'Address', type: 'textarea', col: 'col-12', uppercase: true },
    ],
  },

  doctors: {
    key: 'doctors',
    title: 'Doctor Master',
    subtitle: 'Prescribing doctors',
    icon: 'bi-person-badge',
    resourcePath: 'doctors',
    canCreate: true,
    canDelete: true,
    columns: [
      { key: 'name', label: 'Doctor' },
      { key: 'clinic_hospital_name', label: 'Clinic / Hospital' },
      { key: 'registration_no', label: 'Reg. No' },
      { key: 'phone', label: 'Phone' },
      { key: 'email', label: 'Email' },
    ],
    fields: [
      { key: 'name', label: 'Doctor Name', type: 'text', required: true, col: 'col-md-6', uppercase: true },
      { key: 'clinic_hospital_name', label: 'Clinic / Hospital Name', type: 'text', col: 'col-md-6', uppercase: true },
      { key: 'registration_no', label: 'Registration No', type: 'text', col: 'col-md-6', uppercase: true },
      { key: 'qualification', label: 'Qualification', type: 'text', col: 'col-md-6', uppercase: true },
      {
        key: 'phone', label: 'Phone', type: 'text', col: 'col-md-6',
        validation: 'phone', maxLength: 10, placeholder: '10-digit mobile', hint: 'Exactly 10 digits',
      },
      { key: 'email', label: 'Email', type: 'email', col: 'col-md-6' },
      { key: 'address', label: 'Address', type: 'textarea', col: 'col-12', required: true, uppercase: true },
    ],
  },

  hospitals: {
    key: 'hospitals',
    title: 'Hospital Master',
    subtitle: 'Attached hospitals for dispensing — mark one as default',
    icon: 'bi-hospital',
    resourcePath: 'hospitals',
    canCreate: true,
    canDelete: true,
    columns: [
      { key: 'name', label: 'Hospital Name' },
      { key: 'address', label: 'Address' },
      { key: 'phone', label: 'Ph. No.' },
      { key: 'default_label', label: 'Default', type: 'badge', badgeClass: { Default: 'text-bg-success' } },
    ],
    fields: [
      { key: 'name', label: 'Hospital Name', type: 'text', required: true, col: 'col-md-6', uppercase: true, placeholder: 'Enter hospital name' },
      {
        key: 'phone', label: 'Ph. No.', type: 'text', required: true, col: 'col-md-6',
        maxLength: 15, placeholder: 'Phone / landline', hint: '6 to 15 digits',
      },
      { key: 'address', label: 'Address', type: 'textarea', required: true, col: 'col-12', uppercase: true },
      {
        key: 'is_default', label: 'Default Hospital', type: 'select', col: 'col-md-6',
        options: [{ value: 0, label: 'No' }, { value: 1, label: 'Yes' }],
        hint: 'Pre-selected in Dispensing. Only one default per branch.',
      },
    ],
  },

  distributors: {
    key: 'distributors',
    title: 'Distributor Master',
    subtitle: 'Distributors with GST, DL and contact details',
    icon: 'bi-truck',
    resourcePath: 'distributors',
    canCreate: true,
    canDelete: false,
    columns: [
      { key: 'name', label: 'Distributor Name' },
      { key: 'phone', label: 'Contact' },
      { key: 'address', label: 'Address' },
      { key: 'contact_person', label: 'Contact Person' },
    ],
    fields: [
      { key: 'name', label: 'Distributor Name', type: 'text', required: true, col: 'col-md-6', uppercase: true, placeholder: 'Enter Name' },
      {
        key: 'phone', label: 'Contact No', type: 'text', col: 'col-md-6', required: true,
        validation: 'phoneDistributor', maxLength: 12, placeholder: '10–12 digit contact', hint: 'Retail: 10 to 12 digits',
      },
      { key: 'contact_person', label: 'Contact Person', type: 'text', required: true, col: 'col-md-6', uppercase: true },
      { key: 'email', label: 'Email', type: 'email', col: 'col-md-6' },
      {
        key: 'state', label: 'State', type: 'select', required: true, col: 'col-md-6',
        optionSource: 'gstStates', hint: 'Auto-fills GST state code',
      },
      {
        key: 'gstin', label: 'GST No.', type: 'text', col: 'col-md-6', required: true,
        validation: 'gstin', maxLength: 15, uppercase: true, placeholder: 'Enter GST No.',
      },
      {
        key: 'gst_state_code', label: 'GST Code', type: 'text', col: 'col-md-6', required: true,
        maxLength: 2, placeholder: 'e.g. 27 for Maharashtra', hint: '2-digit state GST code',
      },
      { key: 'dl_no1', label: 'DL No. 1', type: 'text', required: true, col: 'col-md-6', uppercase: true, placeholder: 'Enter DL No. 1' },
      { key: 'dl_no2', label: 'DL No. 2', type: 'text', col: 'col-md-6', uppercase: true },
      { key: 'dl_no3', label: 'DL No. 3', type: 'text', col: 'col-md-6', uppercase: true },
      { key: 'dl_no4', label: 'DL No. 4', type: 'text', col: 'col-md-6', uppercase: true },
      { key: 'address', label: 'Address', type: 'text', col: 'col-12', uppercase: true, placeholder: 'Enter Address' },
    ],
  },

  accounts: {
    key: 'accounts',
    title: 'Accounts Master',
    subtitle: 'Chart of accounts / ledgers',
    icon: 'bi-journal-text',
    resourcePath: 'accounts',
    canCreate: true,
    canDelete: true,
    kpiPreset: 'accounts',
    columns: [
      { key: 'name', label: 'Account' },
      { key: 'type', label: 'Type', type: 'badge' },
      { key: 'bank_name', label: 'Bank' },
      { key: 'account_no', label: 'A/C No' },
      { key: 'opening_balance', label: 'Opening Balance', type: 'currency' },
      { key: 'balance_type', label: 'Dr/Cr' },
    ],
    fields: [
      { key: 'name', label: 'Account Name', type: 'text', required: true, col: 'col-md-6', uppercase: true },
      {
        key: 'type',
        label: 'Type',
        type: 'select',
        required: true,
        col: 'col-md-6',
        options: [
          { value: 'asset', label: 'Asset' },
          { value: 'liability', label: 'Liability' },
          { value: 'income', label: 'Income' },
          { value: 'expense', label: 'Expense' },
          { value: 'equity', label: 'Equity' },
          { value: 'customer', label: 'Customer' },
          { value: 'supplier', label: 'Supplier' },
          { value: 'bank', label: 'Bank' },
          { value: 'cash', label: 'Cash' },
        ],
      },
      { key: 'opening_balance', label: 'Opening Balance', type: 'number', col: 'col-md-6' },
      {
        key: 'balance_type',
        label: 'Balance Type',
        type: 'select',
        col: 'col-md-6',
        options: [
          { value: 'debit', label: 'Debit' },
          { value: 'credit', label: 'Credit' },
        ],
      },
      {
        key: 'bank_name', label: 'Bank Name', type: 'text', col: 'col-md-6', required: true, uppercase: true,
        showWhen: { field: 'type', equals: 'bank' },
      },
      {
        key: 'account_no', label: 'Account No.', type: 'text', col: 'col-md-6', required: true,
        showWhen: { field: 'type', equals: 'bank' },
      },
      {
        key: 'account_holder_name', label: 'Account Name', type: 'text', col: 'col-md-6', required: true, uppercase: true,
        showWhen: { field: 'type', equals: 'bank' },
      },
      {
        key: 'ifsc_code', label: 'IFSC Code', type: 'text', col: 'col-md-6', required: true,
        validation: 'ifsc', maxLength: 11, uppercase: true, placeholder: 'ABCD0123456',
        showWhen: { field: 'type', equals: 'bank' },
      },
      {
        key: 'bank_branch', label: 'Branch', type: 'text', col: 'col-md-6', required: true, uppercase: true,
        showWhen: { field: 'type', equals: 'bank' },
      },
      {
        key: 'account_type', label: 'Account Type', type: 'select', col: 'col-md-6', required: true,
        options: [
          { value: 'CURRENT', label: 'CURRENT' },
          { value: 'SAVING', label: 'SAVING' },
        ],
        showWhen: { field: 'type', equals: 'bank' },
      },
    ],
  },

  branches: {
    key: 'branches',
    title: 'Branch Master',
    subtitle: 'Manage your pharmacy branches / units',
    icon: 'bi-shop-window',
    resourcePath: 'branches',
    canCreate: true,
    canDelete: true,
    columns: [
      { key: 'code', label: 'Code' },
      { key: 'name', label: 'Branch' },
      { key: 'city', label: 'City' },
      { key: 'phone', label: 'Phone' },
      { key: 'status', label: 'Status', type: 'badge' },
    ],
    fields: [
      { key: 'code', label: 'Branch Code', type: 'text', required: true, col: 'col-md-4', uppercase: true, maxLength: 20 },
      { key: 'name', label: 'Branch Name', type: 'text', required: true, col: 'col-md-8', uppercase: true },
      { key: 'drug_license_no', label: 'Drug License No', type: 'text', col: 'col-md-6', uppercase: true },
      {
        key: 'gstin', label: 'GSTIN', type: 'text', col: 'col-md-6', required: true,
        validation: 'gstin', maxLength: 15, uppercase: true, placeholder: 'Enter 15-character GSTIN',
        hint: 'Exactly 15 characters — e.g. 22AAAAA0000A1Z5',
      },
      {
        key: 'phone', label: 'Phone', type: 'text', col: 'col-md-6',
        validation: 'phone', maxLength: 10, placeholder: '10-digit mobile', hint: 'Exactly 10 digits',
      },
      {
        key: 'status', label: 'Status', type: 'select', col: 'col-md-6',
        options: [{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }],
      },
      { key: 'address', label: 'Address', type: 'textarea', col: 'col-12', uppercase: true },
      { key: 'city', label: 'City', type: 'text', col: 'col-md-4', uppercase: true },
      { key: 'state', label: 'State', type: 'text', col: 'col-md-4', uppercase: true },
      {
        key: 'pincode', label: 'Pincode', type: 'text', col: 'col-md-4',
        validation: 'pincode', maxLength: 6, placeholder: '6-digit PIN', hint: 'Exactly 6 digits',
      },
    ],
  },

  'rate-margins': {
    key: 'rate-margins',
    title: 'Rate Margin Master',
    subtitle: 'Category margins, credit & discounts',
    icon: 'bi-percent',
    resourcePath: 'rate-margins',
    canCreate: true,
    canDelete: true,
    columns: [
      { key: 'category', label: 'Category' },
      { key: 'ptr_margin', label: 'PTR %', type: 'number' },
      { key: 'pts_margin', label: 'PTS %', type: 'number' },
      { key: 'max_discount', label: 'Max Disc %', type: 'number' },
      { key: 'credit_days', label: 'Credit Days', type: 'number' },
      { key: 'cash_discount', label: 'Cash Disc %', type: 'number' },
    ],
    fields: [
      {
        key: 'category', label: 'Category', type: 'select', required: true, col: 'col-md-4',
        options: [
          { value: 'COMMON', label: 'COMMON (Fixed - no category)' },
          ...PRODUCT_CATEGORIES.map((c) => ({ value: c, label: c })),
        ],
      },
      { key: 'ptr_margin', label: 'PTR Margin % (Retailer)', type: 'number', required: true, col: 'col-md-4', min: 0, max: 100 },
      { key: 'pts_margin', label: 'PTS Margin % (Stockist)', type: 'number', required: true, col: 'col-md-4', min: 0, max: 100 },
      {
        key: 'rate_by', label: 'Rate By', type: 'select', col: 'col-md-4',
        options: [
          { value: 'Rate By Margin', label: 'Rate By Margin' },
          { value: 'Rate By Purchase', label: 'Rate By Purchase' },
        ],
      },
      { key: 'max_discount', label: 'Max Discount %', type: 'number', col: 'col-md-4', min: 0, max: 100 },
      { key: 'std_discount', label: 'Standard Discount %', type: 'number', col: 'col-md-4', min: 0, max: 100 },
      { key: 'credit_days', label: 'Credit Days', type: 'number', col: 'col-md-4', min: 0, max: 365 },
      { key: 'cash_discount', label: 'Cash Discount %', type: 'number', col: 'col-md-4', min: 0, max: 100 },
    ],
  },

  payments: {
    key: 'payments',
    title: 'Payments (Vouchers)',
    subtitle: 'Receipt & payment vouchers',
    icon: 'bi-cash-coin',
    resourcePath: 'payments',
    canCreate: true,
    canDelete: true,
    kpiPreset: 'payments',
    columns: [
      { key: 'voucher_no', label: 'Voucher' },
      { key: 'voucher_type', label: 'Type', type: 'badge' },
      { key: 'party_name', label: 'Party' },
      { key: 'amount', label: 'Amount', type: 'currency' },
      { key: 'mode', label: 'Mode' },
      { key: 'voucher_date', label: 'Date', type: 'date' },
    ],
    fields: [
      {
        key: 'voucher_type', label: 'Type', type: 'select', required: true, col: 'col-md-4',
        options: [{ value: 'receipt', label: 'Receipt (money in)' }, { value: 'payment', label: 'Payment (money out)' }],
      },
      { key: 'voucher_date', label: 'Date', type: 'date', required: true, col: 'col-md-4' },
      {
        key: 'party_type', label: 'Party Type', type: 'select', col: 'col-md-4',
        options: [{ value: 'customer', label: 'Customer' }, { value: 'supplier', label: 'Supplier' }, { value: 'other', label: 'Other' }],
      },
      { key: 'party_name', label: 'Party Name', type: 'text', required: true, col: 'col-md-6', uppercase: true },
      { key: 'amount', label: 'Amount', type: 'number', required: true, col: 'col-md-3' },
      {
        key: 'mode', label: 'Mode', type: 'select', col: 'col-md-3',
        options: [{ value: 'cash', label: 'Cash' }, { value: 'card', label: 'Card' }, { value: 'upi', label: 'UPI' }, { value: 'bank', label: 'Bank' }, { value: 'cheque', label: 'Cheque' }],
      },
      { key: 'ref_no', label: 'Reference No', type: 'text', col: 'col-md-6', uppercase: true },
      { key: 'narration', label: 'Narration', type: 'textarea', col: 'col-12', uppercase: true },
    ],
  },

  'credit-notes': {
    key: 'credit-notes',
    title: 'Credit Notes',
    subtitle: 'Credit notes (sales return / customer credit)',
    icon: 'bi-file-earmark-minus',
    resourcePath: 'credit-notes',
    canCreate: true,
    canDelete: true,
    kpiPreset: 'credit-notes',
    columns: [
      { key: 'note_no', label: 'Note No' },
      { key: 'party_name', label: 'Party' },
      { key: 'against_invoice', label: 'Against Inv.' },
      { key: 'amount', label: 'Amount', type: 'currency' },
      { key: 'gst_amount', label: 'GST', type: 'currency' },
      { key: 'note_date', label: 'Date', type: 'date' },
    ],
    fields: [
      { key: 'note_date', label: 'Date', type: 'date', required: true, col: 'col-md-4' },
      { key: 'party_name', label: 'Party Name', type: 'text', required: true, col: 'col-md-8', uppercase: true },
      { key: 'against_invoice', label: 'Against Invoice', type: 'text', col: 'col-md-4', uppercase: true },
      { key: 'amount', label: 'Amount', type: 'number', required: true, col: 'col-md-4' },
      { key: 'gst_amount', label: 'GST Amount', type: 'number', col: 'col-md-4' },
      { key: 'reason', label: 'Reason', type: 'textarea', col: 'col-12', uppercase: true },
    ],
  },

  'debit-notes': {
    key: 'debit-notes',
    title: 'Debit Notes',
    subtitle: 'Debit notes (purchase return / supplier debit)',
    icon: 'bi-file-earmark-plus',
    resourcePath: 'debit-notes',
    canCreate: true,
    canDelete: true,
    kpiPreset: 'debit-notes',
    columns: [
      { key: 'note_no', label: 'Note No' },
      { key: 'party_name', label: 'Party' },
      { key: 'against_invoice', label: 'Against Inv.' },
      { key: 'amount', label: 'Amount', type: 'currency' },
      { key: 'gst_amount', label: 'GST', type: 'currency' },
      { key: 'note_date', label: 'Date', type: 'date' },
    ],
    fields: [
      { key: 'note_date', label: 'Date', type: 'date', required: true, col: 'col-md-4' },
      { key: 'party_name', label: 'Party Name', type: 'text', required: true, col: 'col-md-8', uppercase: true },
      { key: 'against_invoice', label: 'Against Invoice', type: 'text', col: 'col-md-4', uppercase: true },
      { key: 'amount', label: 'Amount', type: 'number', required: true, col: 'col-md-4' },
      { key: 'gst_amount', label: 'GST Amount', type: 'number', col: 'col-md-4' },
      { key: 'reason', label: 'Reason', type: 'textarea', col: 'col-12', uppercase: true },
    ],
  },

  employees: {
    key: 'employees',
    title: 'Employee / User Master',
    subtitle: 'Staff logins and roles',
    icon: 'bi-people',
    resourcePath: 'employees',
    canCreate: true,
    canDelete: true,
    columns: [
      { key: 'name', label: 'Name' },
      { key: 'email', label: 'Email' },
      { key: 'reg_no', label: 'Reg. No' },
      { key: 'role', label: 'Role', type: 'badge' },
      { key: 'status', label: 'Status', type: 'badge' },
    ],
    fields: [
      { key: 'name', label: 'Full Name / Pharmacist Name', type: 'text', required: true, col: 'col-md-6', uppercase: true },
      { key: 'email', label: 'Email / Login Id', type: 'email', required: true, col: 'col-md-6' },
      {
        key: 'phone', label: 'Phone', type: 'text', col: 'col-md-6',
        validation: 'phone', maxLength: 10, placeholder: '10-digit mobile', hint: 'Exactly 10 digits',
      },
      { key: 'reg_no', label: 'Pharmacist Reg. No.', type: 'text', col: 'col-md-6', uppercase: true },
      { key: 'joining_date', label: 'Date Of Joining', type: 'date', col: 'col-md-6' },
      { key: 'role', label: 'Role', type: 'select', required: true, optionSource: 'roles', col: 'col-md-6' },
      { key: 'branch_id', label: 'Branch', type: 'select', required: true, optionSource: 'branches', col: 'col-md-6' },
      {
        key: 'status',
        label: 'Status',
        type: 'select',
        col: 'col-md-6',
        options: [
          { value: 'active', label: 'Active' },
          { value: 'inactive', label: 'Inactive' },
          { value: 'suspended', label: 'Suspended' },
        ],
      },
      { key: 'password', label: 'Password', type: 'password', required: true, col: 'col-md-6', hideOnEdit: false },
      { key: 'address', label: 'Address', type: 'textarea', col: 'col-12', uppercase: true },
    ],
  },
};
