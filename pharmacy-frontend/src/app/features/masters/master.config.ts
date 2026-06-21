export type FieldType =
  | 'text'
  | 'number'
  | 'email'
  | 'select'
  | 'textarea'
  | 'date'
  | 'password';

export type OptionSource = 'schedule' | 'branches' | 'roles' | 'generics';

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
}

export type ColumnType = 'text' | 'currency' | 'number' | 'badge' | 'date';

export interface TableColumn {
  key: string;
  label: string;
  type?: ColumnType;
  badgeClass?: Record<string, string>;
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
  canDelete?: boolean;
  bindable?: boolean; // products: allow binding to branch
}

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
    subtitle: 'Global generic / molecule catalog (shared across all branches)',
    icon: 'bi-droplet-half',
    resourcePath: 'generics',
    canCreate: true,
    canDelete: true,
    columns: [
      { key: 'name', label: 'Generic Name' },
      { key: 'therapeutic_class', label: 'Therapeutic Class' },
    ],
    fields: [
      { key: 'name', label: 'Generic Name', type: 'text', required: true, col: 'col-md-6' },
      { key: 'therapeutic_class', label: 'Therapeutic Class', type: 'text', col: 'col-md-6' },
    ],
  },

  products: {
    key: 'products',
    title: 'Product Master',
    subtitle: 'Global product catalog with schedule classification',
    icon: 'bi-capsule',
    resourcePath: 'products',
    canCreate: true,
    canDelete: true,
    bindable: true,
    columns: [
      { key: 'name', label: 'Product' },
      { key: 'manufacturer', label: 'Manufacturer' },
      { key: 'schedule_category', label: 'Schedule', type: 'badge', badgeClass: scheduleBadge },
      { key: 'default_gst_rate', label: 'GST %', type: 'number' },
      { key: 'pack_size', label: 'Pack' },
    ],
    fields: [
      { key: 'name', label: 'Product Name', type: 'text', required: true, col: 'col-md-6' },
      { key: 'generic_id', label: 'Generic', type: 'select', optionSource: 'generics', col: 'col-md-6' },
      { key: 'manufacturer', label: 'Manufacturer', type: 'text', col: 'col-md-6' },
      {
        key: 'schedule_category',
        label: 'Schedule Category',
        type: 'select',
        required: true,
        optionSource: 'schedule',
        col: 'col-md-6',
      },
      { key: 'hsn_code', label: 'HSN Code', type: 'text', col: 'col-md-4' },
      { key: 'default_gst_rate', label: 'GST %', type: 'number', col: 'col-md-4', min: 0, max: 28 },
      { key: 'pack_size', label: 'Pack Size', type: 'text', col: 'col-md-4', placeholder: 'e.g. 10 tablets' },
      { key: 'units_per_pack', label: 'Units / Pack', type: 'number', col: 'col-md-4', min: 1 },
      {
        key: 'allow_loose', label: 'Loose Sale', type: 'select', col: 'col-md-4',
        options: [{ value: 1, label: 'Allowed' }, { value: 0, label: 'Not allowed' }],
      },
      { key: 'shelf_location', label: 'Shelf Location', type: 'text', col: 'col-md-4' },
      { key: 'composition', label: 'Composition', type: 'textarea', col: 'col-12' },
    ],
  },

  customers: {
    key: 'customers',
    title: 'Customer Master',
    subtitle: 'Walk-in and registered customers',
    icon: 'bi-person-vcard',
    resourcePath: 'customers',
    canCreate: true,
    canDelete: true,
    columns: [
      { key: 'name', label: 'Name' },
      { key: 'phone', label: 'Phone' },
      { key: 'email', label: 'Email' },
      { key: 'gstin', label: 'GSTIN' },
    ],
    fields: [
      { key: 'name', label: 'Customer Name', type: 'text', required: true, col: 'col-md-6' },
      { key: 'phone', label: 'Phone', type: 'text', col: 'col-md-6' },
      { key: 'email', label: 'Email', type: 'email', col: 'col-md-6' },
      { key: 'dob', label: 'Date of Birth', type: 'date', col: 'col-md-6' },
      { key: 'gstin', label: 'GSTIN', type: 'text', col: 'col-md-6' },
      { key: 'address', label: 'Address', type: 'textarea', col: 'col-12' },
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
      { key: 'registration_no', label: 'Reg. No' },
      { key: 'qualification', label: 'Qualification' },
      { key: 'phone', label: 'Phone' },
    ],
    fields: [
      { key: 'name', label: 'Doctor Name', type: 'text', required: true, col: 'col-md-6' },
      { key: 'registration_no', label: 'Registration No', type: 'text', col: 'col-md-6' },
      { key: 'qualification', label: 'Qualification', type: 'text', col: 'col-md-6' },
      { key: 'phone', label: 'Phone', type: 'text', col: 'col-md-6' },
    ],
  },

  suppliers: {
    key: 'suppliers',
    title: 'Supplier Master',
    subtitle: 'Distributors and vendors',
    icon: 'bi-truck',
    resourcePath: 'suppliers',
    canCreate: true,
    canDelete: true,
    columns: [
      { key: 'name', label: 'Supplier' },
      { key: 'phone', label: 'Phone' },
      { key: 'gstin', label: 'GSTIN' },
    ],
    fields: [
      { key: 'name', label: 'Supplier Name', type: 'text', required: true, col: 'col-md-6' },
      { key: 'phone', label: 'Phone', type: 'text', col: 'col-md-6' },
      { key: 'email', label: 'Email', type: 'email', col: 'col-md-6' },
      { key: 'gstin', label: 'GSTIN', type: 'text', col: 'col-md-6' },
      { key: 'address', label: 'Address', type: 'textarea', col: 'col-12' },
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
    columns: [
      { key: 'name', label: 'Account' },
      { key: 'type', label: 'Type', type: 'badge' },
      { key: 'opening_balance', label: 'Opening Balance', type: 'currency' },
      { key: 'balance_type', label: 'Dr/Cr' },
    ],
    fields: [
      { key: 'name', label: 'Account Name', type: 'text', required: true, col: 'col-md-6' },
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
      { key: 'code', label: 'Branch Code', type: 'text', required: true, col: 'col-md-4' },
      { key: 'name', label: 'Branch Name', type: 'text', required: true, col: 'col-md-8' },
      { key: 'drug_license_no', label: 'Drug License No', type: 'text', col: 'col-md-6' },
      { key: 'gstin', label: 'GSTIN', type: 'text', col: 'col-md-6' },
      { key: 'phone', label: 'Phone', type: 'text', col: 'col-md-6' },
      {
        key: 'status', label: 'Status', type: 'select', col: 'col-md-6',
        options: [{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }],
      },
      { key: 'address', label: 'Address', type: 'textarea', col: 'col-12' },
      { key: 'city', label: 'City', type: 'text', col: 'col-md-4' },
      { key: 'state', label: 'State', type: 'text', col: 'col-md-4' },
      { key: 'pincode', label: 'Pincode', type: 'text', col: 'col-md-4' },
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
      { key: 'role', label: 'Role', type: 'badge' },
      { key: 'status', label: 'Status', type: 'badge' },
    ],
    fields: [
      { key: 'name', label: 'Full Name', type: 'text', required: true, col: 'col-md-6' },
      { key: 'email', label: 'Email', type: 'email', required: true, col: 'col-md-6' },
      { key: 'phone', label: 'Phone', type: 'text', col: 'col-md-6' },
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
    ],
  },
};
