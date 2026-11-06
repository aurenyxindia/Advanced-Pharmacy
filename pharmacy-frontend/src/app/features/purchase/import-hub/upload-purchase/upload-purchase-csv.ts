import { UPLOAD_FORMAT_CONFIGS, type UploadFormatConfig } from './upload-purchase-formats';

/** Retail upload-purchase CSV line parser (quoted commas / tabs). */
export function parseCsvLine(line: string): string[] {
  const result: string[] = [];
  let current = '';
  let inQuotes = false;
  for (let i = 0; i < line.length; i++) {
    const char = line[i];
    if (char === '"') {
      inQuotes = !inQuotes;
    } else if ((char === ',' && !inQuotes) || char === '\t') {
      result.push(current.trim());
      current = '';
    } else {
      current += char;
    }
  }
  result.push(current.trim());
  return result;
}

/** Normalize expiry to YYYY-MM (Retail formatExpiryToYYYYMM). */
export function formatExpiryToYYYYMM(value: string): string {
  const s = String(value).trim().replace(/\s/g, '').replace(/^["']|["']$/g, '');
  if (!s) return '';

  const isoMatch = s.match(/^(\d{4})[\/\-](\d{1,2})/);
  if (isoMatch) return `${isoMatch[1]}-${isoMatch[2].padStart(2, '0')}`;

  const dmyMatch = s.match(/^(\d{1,2})\D(\d{1,2})\D(\d{4})$/);
  if (dmyMatch) {
    const [, p1, p2, year] = dmyMatch;
    const a = parseInt(p1, 10);
    const b = parseInt(p2, 10);
    const month = a > 12 ? p2 : b > 12 ? p1 : p2;
    return `${year}-${month.padStart(2, '0')}`;
  }

  const dmy2Match = s.match(/^(\d{1,2})\D(\d{1,2})\D(\d{2})$/);
  if (dmy2Match) {
    const [, p1, p2, yy] = dmy2Match;
    const a = parseInt(p1, 10);
    const b = parseInt(p2, 10);
    const month = a > 12 ? p2 : b > 12 ? p1 : p2;
    const yyNum = parseInt(yy, 10);
    const fullYear = yyNum >= 0 && yyNum <= 29 ? 2000 + yyNum : 1900 + yyNum;
    return `${fullYear}-${month.padStart(2, '0')}`;
  }

  const digitsOnly = s.replace(/\D/g, '');
  if (digitsOnly.length === 8) {
    const mm = digitsOnly.slice(2, 4);
    const yyyy = digitsOnly.slice(4, 8);
    const month = parseInt(mm, 10);
    if (month >= 1 && month <= 12) return `${yyyy}-${mm}`;
  }
  if (digitsOnly.length === 7) {
    const mm = digitsOnly.slice(1, 3);
    const yyyy = digitsOnly.slice(3, 7);
    const month = parseInt(mm, 10);
    if (month >= 1 && month <= 12) return `${yyyy}-${mm}`;
  }

  return s;
}

export function getFormatConfig(format: string): UploadFormatConfig {
  return UPLOAD_FORMAT_CONFIGS[format] ?? UPLOAD_FORMAT_CONFIGS['Medivision'];
}

/**
 * Parse CSV text with Retail formatConfigs (skipRows / skipCols / headerKeys).
 */
export function parseUploadPurchaseCsv(csvText: string, format: string): Record<string, unknown>[] {
  // Medivision Sale_*.csv H/T — billed qty = amount÷trailing rate (not stock qty col).
  if (
    (format === 'Medivision' || format === 'Auto') &&
    /^H,/im.test(csvText.trim()) &&
    /^T,/im.test(csvText)
  ) {
    return parseMedivisionHtCsv(csvText);
  }

  const { skipRows, skipCols, headerRowIndex, headerKeys } = getFormatConfig(format);
  const lines = csvText.split(/\r?\n/).filter((line) => line.trim());
  const result: Record<string, unknown>[] = [];

  let keys: string[];
  if (headerKeys?.length) {
    keys = headerKeys;
  } else if (headerRowIndex != null && lines[headerRowIndex]) {
    const headerCols = parseCsvLine(lines[headerRowIndex])
      .filter((_, colIdx) => !skipCols.includes(colIdx))
      .map((k, i) => (k && String(k).trim()) || `col${i}`);
    const seen = new Set<string>();
    keys = headerCols.map((k) => {
      let key = k;
      let n = 1;
      while (seen.has(key)) key = `${k}_${n++}`;
      seen.add(key);
      return key;
    });
  } else {
    const firstDataLine = lines.find((_, i) => !skipRows.includes(i));
    const colCount = firstDataLine
      ? parseCsvLine(firstDataLine).filter((_, i) => !skipCols.includes(i)).length
      : 0;
    keys = Array.from({ length: colCount }, (_, i) => `col${i}`);
  }

  const decimal2Keys = ['mrp', 'purchase_rate', 'totalAmt', 'disc_amt', 'scheme', 'ptr', 'pts', 'prodCdAmt', 'disc'];

  for (let rowIdx = 0; rowIdx < lines.length; rowIdx++) {
    if (skipRows.includes(rowIdx) || rowIdx === headerRowIndex) continue;

    const cols = parseCsvLine(lines[rowIdx]);
    const filteredCols = cols.filter((_, colIdx) => !skipCols.includes(colIdx));
    const obj: Record<string, unknown> = {};

    keys.forEach((key, i) => {
      let val: string | number = filteredCols[i] ?? '';
      if (key === 'expiry_date' && val) {
        val = formatExpiryToYYYYMM(String(val));
      } else if (key === 'qty' && val !== '') {
        val = parseFloat(String(val).replace(/,/g, '')) || 0;
      } else if (key === 'gst' && val !== '') {
        const n = parseFloat(String(val).replace(/,/g, ''));
        val = isNaN(n) ? val : Math.round(n);
      } else if (key === 'scheme') {
        const n = parseFloat(String(val || '').replace(/,/g, ''));
        val = !isNaN(n) && Number.isInteger(n) && n > 0 ? n : 0;
      } else if (decimal2Keys.includes(key) && val !== '') {
        const n = parseFloat(String(val).replace(/,/g, ''));
        val = isNaN(n) ? val : parseFloat(n.toFixed(2));
      }
      obj[key] = val;
    });

    if (format === 'Medica' || format === 'Easysol') {
      const igst = parseFloat(String(obj['igstPer'] ?? 0)) || 0;
      const cgst = parseFloat(String(obj['cgstPer'] ?? 0)) || 0;
      const sgst = parseFloat(String(obj['sgstPer'] ?? 0)) || 0;
      obj['gst'] = Math.round(igst + cgst + sgst);
    } else if (format === 'Marg ERP 3') {
      const cgst = parseFloat(String(obj['cgstPer'] ?? 0)) || 0;
      const sgst = parseFloat(String(obj['sgstPer'] ?? 0)) || 0;
      const summed = Math.round(cgst + sgst);
      obj['gst'] = summed > 0 ? summed : 5;
    }

    result.push(obj);
  }

  return result;
}

/** Medivision Sale_ H/T dump — matches pharmacy-api PurchaseFormatParser::parseMedivisionHt. */
function parseMedivisionHtCsv(csvText: string): Record<string, unknown>[] {
  const lines = csvText.split(/\r?\n/).filter((l) => l.trim());
  const result: Record<string, unknown>[] = [];
  const num = (v: unknown) => {
    const n = parseFloat(String(v ?? '').replace(/,/g, ''));
    return isNaN(n) ? 0 : n;
  };

  for (const line of lines) {
    const cols = parseCsvLine(line);
    if ((cols[0] || '').toUpperCase() !== 'T') continue;
    const product = String(cols[2] || '').trim();
    if (!product) continue;

    const pack = String(cols[4] || '').trim();
    let qty = Math.round(num(cols[6]));
    const scheme = Math.round(num(cols[7]));
    const mrp = num(cols[8]);
    const pts = num(cols[9]);
    const batch = String(cols[10] || '').trim();
    const expiry = formatExpiryToYYYYMM(String(cols[11] || ''));
    const amount = num(cols[12]);
    const discAmt = num(cols[15]);
    const gst = Math.round(num(cols[16]));

    let rate = num(cols[31]);
    if (rate <= 0) {
      for (let i = cols.length - 1; i >= 20; i--) {
        const v = num(cols[i]);
        if (v > 0) {
          rate = v;
          break;
        }
      }
    }
    if (rate <= 0) rate = pts;

    if (rate > 0 && amount > 0) {
      const qFromAmt = amount / rate;
      if (Math.abs(qFromAmt - Math.round(qFromAmt)) < 0.051) {
        const recon = Math.max(1, Math.round(qFromAmt));
        const errRaw = Math.abs(qty * rate - amount);
        const errRecon = Math.abs(recon * rate - amount);
        if (errRecon <= Math.max(0.05, amount * 0.01) && (qty <= 0 || errRaw > Math.max(0.5, amount * 0.05))) {
          qty = recon;
        }
      }
    }

    const totalAmt = amount > 0 ? parseFloat(amount.toFixed(2)) : parseFloat((qty * rate).toFixed(2));
    const disc =
      totalAmt > 0 && discAmt > 0 ? parseFloat(((discAmt * 100) / totalAmt).toFixed(4)) : 0;

    result.push({
      product,
      m_nameCsv: '',
      packSizeFromCsv: pack,
      qty,
      scheme,
      mrp: parseFloat(mrp.toFixed(2)),
      purchase_rate: parseFloat(rate.toFixed(2)),
      batch_no: batch,
      expiry_date: expiry,
      totalAmt,
      disc_amt: parseFloat(discAmt.toFixed(2)),
      disc,
      gst,
      ptr: parseFloat(rate.toFixed(2)),
      pts: parseFloat(pts.toFixed(2)),
      prodCdAmt: 0,
    });
  }
  return result;
}
