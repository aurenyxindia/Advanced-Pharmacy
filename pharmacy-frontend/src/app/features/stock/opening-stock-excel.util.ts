import ExcelJS from 'exceljs';
import { ProductSearchResult } from '../../core/services/purchase.service';

export const OPENING_STOCK_EXCEL_HEADERS = [
  'product', 'batch_no', 'expiry_date', 'shelf', 'qty', 'mrp', 'purchase_rate', 'gst', 'hsn',
];

export interface OpeningLine {
  _key: string;
  master_product_id: number;
  product_name: string;
  p_no: string;
  hsn_code: string;
  batch_no: string;
  expiry_date: string;
  shelf_location: string;
  qty: number;
  mrp: number;
  purchase_rate: number;
  gst_rate: number;
  pack_size: string;
  units_per_pack?: number;
  m_name: string;
  p_cat: string;
  taxable: number;
  amount: number;
  product_matched: boolean;
  matchedProducts?: ProductSearchResult[];
  selectedProduct?: ProductSearchResult | null;
}

let rowKey = 0;
export function nextOpeningRowKey(): string {
  rowKey += 1;
  return 'os-' + rowKey;
}

export function buildOpeningLine(fields: Partial<OpeningLine> & { product_name: string }): OpeningLine {
  const rate = Number(fields.purchase_rate) || 0;
  const qty = Number(fields.qty) || 0;
  const amount = Math.round(rate * qty * 100) / 100;
  return {
    _key: nextOpeningRowKey(),
    master_product_id: fields.master_product_id ?? 0,
    product_name: fields.product_name,
    p_no: fields.p_no ?? (fields.master_product_id ? String(fields.master_product_id) : ''),
    hsn_code: fields.hsn_code ?? '',
    batch_no: fields.batch_no ?? '',
    expiry_date: fields.expiry_date ?? '',
    shelf_location: fields.shelf_location ?? '',
    qty,
    mrp: Number(fields.mrp) || 0,
    purchase_rate: rate,
    gst_rate: fields.gst_rate ?? 5,
    pack_size: fields.pack_size ?? '',
    m_name: fields.m_name ?? '',
    p_cat: fields.p_cat ?? '',
    taxable: amount,
    amount,
    product_matched: fields.product_matched !== false && !!(fields.master_product_id),
    matchedProducts: fields.matchedProducts,
    selectedProduct: fields.selectedProduct ?? null,
  };
}

export function recalcOpeningTotals(lines: OpeningLine[]): number {
  let total = 0;
  for (const d of lines) {
    const amt = Math.round((Number(d.purchase_rate) * Number(d.qty)) * 100) / 100;
    d.amount = amt;
    d.taxable = amt;
    total += amt;
  }
  return Math.round(total * 100) / 100;
}

const normName = (s: string) => String(s || '').toLowerCase().replace(/\s+/g, ' ').trim();

function levenshtein(a: string, b: string): number {
  if (a === b) return 0;
  if (!a.length) return b.length;
  if (!b.length) return a.length;
  if (a.length > b.length) { const t = a; a = b; b = t; }
  const n = b.length;
  let prev = new Array<number>(n + 1);
  let curr = new Array<number>(n + 1);
  for (let j = 0; j <= n; j++) prev[j] = j;
  for (let i = 1; i <= a.length; i++) {
    curr[0] = i;
    const ac = a.charCodeAt(i - 1);
    for (let j = 1; j <= n; j++) {
      const cost = ac === b.charCodeAt(j - 1) ? 0 : 1;
      curr[j] = Math.min(prev[j] + 1, curr[j - 1] + 1, prev[j - 1] + cost);
    }
    const swap = prev; prev = curr; curr = swap;
  }
  return prev[n];
}

export function buildProductMatchIndex(products: ProductSearchResult[]): {
  exact: Map<string, ProductSearchResult>;
  prefix: Map<string, ProductSearchResult[]>;
} {
  const exact = new Map<string, ProductSearchResult>();
  const prefix = new Map<string, ProductSearchResult[]>();
  for (const p of products) {
    const name = normName(p.name);
    if (!name) continue;
    exact.set(name, p);
    for (const len of [3, 2, 1]) {
      const key = name.substring(0, len);
      if (!key) continue;
      if (!prefix.has(key)) prefix.set(key, []);
      prefix.get(key)!.push(p);
    }
  }
  return { exact, prefix };
}

export function fuzzyMatchProducts(
  csvName: string,
  products: ProductSearchResult[],
  index: { exact: Map<string, ProductSearchResult>; prefix: Map<string, ProductSearchResult[]> },
  topN = 3,
): ProductSearchResult[] {
  const a = normName(csvName);
  if (!a || !products.length) return [];
  const exact = index.exact.get(a);
  if (exact) return [exact];

  let candidates: ProductSearchResult[] = [];
  for (const len of [3, 2, 1]) {
    const key = a.substring(0, len);
    if (!key) break;
    const c = index.prefix.get(key) || [];
    if (c.length) { candidates = c.length > 400 ? c.slice(0, 400) : c; break; }
  }
  if (!candidates.length) return [];

  const scored: { p: ProductSearchResult; score: number }[] = [];
  for (const p of candidates) {
    const b = normName(p.name);
    if (!b) continue;
    let score: number;
    if (b === a) score = 1;
    else if (b.includes(a) || a.includes(b)) score = 0.85;
    else {
      if (Math.abs(a.length - b.length) > Math.max(a.length, b.length) * 0.45) continue;
      score = 1 - levenshtein(a, b) / Math.max(a.length, b.length);
    }
    if (score >= 0.5) scored.push({ p, score });
  }
  scored.sort((x, y) => y.score - x.score);
  return scored.slice(0, topN).map((s) => s.p);
}

export function applyMatchToLine(
  line: OpeningLine,
  csvName: string,
  products: ProductSearchResult[],
  index: ReturnType<typeof buildProductMatchIndex>,
): void {
  const matches = fuzzyMatchProducts(csvName, products, index, 3);
  if (matches.length >= 1) {
    line.matchedProducts = matches.length > 1 ? matches : [];
    line.selectedProduct = matches[0];
    applyProductToLine(line, matches[0]);
    line.product_matched = true;
  } else {
    line.product_matched = false;
    line.master_product_id = 0;
    line.matchedProducts = [];
    line.selectedProduct = null;
  }
}

export function applyProductToLine(line: OpeningLine, p: ProductSearchResult): void {
  line.master_product_id = p.id;
  line.product_name = p.name;
  line.p_no = String(p.id);
  line.pack_size = p.pack_size ?? '';
  line.m_name = p.manufacturer_short ?? p.manufacturer ?? '';
  line.p_cat = p.schedule_category ?? '';
  line.hsn_code = p.hsn_code ?? line.hsn_code;
  line.gst_rate = Number(p.default_gst_rate) || line.gst_rate || 5;
  if (p.mrp && Number(p.mrp) > 0 && !(line.mrp > 0)) {
    line.mrp = Number(p.mrp);
  }
  line.product_matched = true;
  line.selectedProduct = p;
}

export async function downloadOpeningTemplate(): Promise<void> {
  const wb = new ExcelJS.Workbook();
  const ws = wb.addWorksheet('Opening Stock');
  ws.addRow(OPENING_STOCK_EXCEL_HEADERS);
  ws.addRow(['DOLO 650 TAB', 'BATCH001', '2028-05', 'A1', 10, 30, 25, 12, '30049099']);
  const buf = await wb.xlsx.writeBuffer();
  const blob = new Blob([buf], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'opening_stock_template.xlsx';
  a.click();
  URL.revokeObjectURL(a.href);
}

function normalizeHeader(v: unknown): string {
  return String(v ?? '').trim().toLowerCase().replace(/\s+/g, '_');
}

function cellText(v: unknown): string {
  if (v == null) return '';
  if (v instanceof Date) return v.toISOString().slice(0, 7);
  return String(v).trim();
}

export function formatExpiryFromCell(v: unknown): string {
  if (v instanceof Date) {
    const y = v.getFullYear();
    const m = String(v.getMonth() + 1).padStart(2, '0');
    return `${y}-${m}`;
  }
  const s = cellText(v);
  if (!s) return '';
  if (/^\d{4}-\d{2}$/.test(s)) return s;
  if (/^\d{4}\/\d{2}$/.test(s)) return s.replace('/', '-');
  const dmy = s.match(/^(\d{1,2})[/-](\d{1,2})[/-](\d{2,4})$/);
  if (dmy) {
    const year = dmy[3].length === 2 ? '20' + dmy[3] : dmy[3];
    const month = String(parseInt(dmy[2], 10)).padStart(2, '0');
    return `${year}-${month}`;
  }
  return s;
}

export async function parseOpeningExcel(file: File): Promise<OpeningLine[]> {
  const ExcelJSMod = (await import('exceljs')).default;
  const wb = new ExcelJSMod.Workbook();
  const buf = await file.arrayBuffer();
  await wb.xlsx.load(buf);
  const sheet = wb.worksheets[0];
  if (!sheet || sheet.rowCount < 2) {
    throw new Error('Excel file is empty or has no data rows.');
  }

  const headerMap: Record<string, number> = {};
  sheet.getRow(1).eachCell((cell, col) => {
    const key = normalizeHeader(cell.value);
    if (key) headerMap[key] = col;
  });

  const missing = OPENING_STOCK_EXCEL_HEADERS.filter((h) => !headerMap[h]);
  if (missing.length) {
    throw new Error('Missing required columns: ' + missing.join(', '));
  }

  const imported: OpeningLine[] = [];
  const lastRow = sheet.actualRowCount || sheet.rowCount;
  for (let r = 2; r <= lastRow; r++) {
    const row = sheet.getRow(r);
    if (!row?.hasValues) continue;
    const product = cellText(row.getCell(headerMap['product']).value);
    if (!product) continue;
    const qty = parseFloat(cellText(row.getCell(headerMap['qty']).value).replace(/,/g, '')) || 0;
    const mrp = parseFloat(cellText(row.getCell(headerMap['mrp']).value).replace(/,/g, '')) || 0;
    const purchase_rate = parseFloat(cellText(row.getCell(headerMap['purchase_rate']).value).replace(/,/g, '')) || 0;
    const gstRaw = cellText(row.getCell(headerMap['gst']).value);
    const gstNum = parseFloat(gstRaw.replace(/,/g, '').replace(/%/g, ''));
    imported.push(buildOpeningLine({
      product_name: product,
      batch_no: cellText(row.getCell(headerMap['batch_no']).value),
      expiry_date: formatExpiryFromCell(row.getCell(headerMap['expiry_date']).value),
      shelf_location: cellText(row.getCell(headerMap['shelf']).value),
      qty,
      mrp,
      purchase_rate,
      gst_rate: Number.isFinite(gstNum) ? gstNum : 5,
      hsn_code: cellText(row.getCell(headerMap['hsn']).value),
      product_matched: false,
    }));
  }
  if (!imported.length) throw new Error('No valid product rows found.');
  return imported;
}
