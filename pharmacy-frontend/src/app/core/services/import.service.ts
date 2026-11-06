import { Injectable, NgZone, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, OperatorFunction, forkJoin, from, map, of, switchMap, throwError, timeout } from 'rxjs';
import { environment } from '../../../environments/environment';
import { ApiResponse } from '../models/auth.models';
import { PurchaseLine } from './purchase.service';

/** Keep in sync with pharmacy-api UPLOAD_MAX_MB. */
export const IMPORT_MAX_UPLOAD_MB = 50;
const IMPORT_MAX_BYTES = IMPORT_MAX_UPLOAD_MB * 1024 * 1024;
const PARSE_TIMEOUT_MS = 180_000;
const AI_TIMEOUT_MS = 280_000;

export interface ImportFormat {
  key: string;
  label: string;
}

export interface ImportDraftLine extends PurchaseLine {
  matched_name?: string | null;
  manufacturer_short?: string;
  mfg_name?: string;
  hsn_code?: string | null;
  pack?: string;
  /** Line amounts (captured / recalculated like retail upload-purchase). */
  discount_amt?: number;
  gross_amount?: number;
  cd_amt?: number;
  taxable_amount?: number;
  gst_amount?: number;
  net_amount?: number;
}

export interface ImportDraftHeader {
  invoice_no?: string | null;
  invoice_date?: string | null;
  supplier_name?: string | null;
  bill_gross?: number | null;
  bill_add?: number | null;
  bill_extra_less?: number | null;
  bill_less?: number | null;
  bill_net?: number | null;
  bill_round?: number | null;
}

export interface ImportParseResult {
  lines: ImportDraftLine[];
  total_lines: number;
  matched: number;
  format?: string;
  format_requested?: string;
  header?: ImportDraftHeader;
  filename?: string;
  needs_excel_convert?: boolean;
  base64?: string;
  ai_fallback?: boolean;
  ai_error?: string;
}

export interface GmailAttachment {
  messageId: string;
  attachmentId: string;
  subject: string;
  from: string;
  date: string;
  filename: string;
  size: number;
}

export interface GmailStatus {
  configured: boolean;
  linked: boolean;
  email?: string | null;
  message?: string | null;
}

export interface AiStatus {
  configured: boolean;
  provider: string;
  message?: string | null;
}

@Injectable({ providedIn: 'root' })
export class ImportService {
  private readonly http = inject(HttpClient);
  private readonly zone = inject(NgZone);
  private readonly base = environment.apiUrl;

  /**
   * PDF.js / FileReader / exceljs often complete outside NgZone.
   * Re-enter the zone so busy/extracting/matching flags update without a click.
   */
  private enterZone<T>(): OperatorFunction<T, T> {
    return (source) =>
      new Observable<T>((subscriber) => {
        const sub = source.subscribe({
          next: (v) => this.zone.run(() => subscriber.next(v)),
          error: (e) => this.zone.run(() => subscriber.error(e)),
          complete: () => this.zone.run(() => subscriber.complete()),
        });
        return () => sub.unsubscribe();
      });
  }

  formats(): Observable<ImportFormat[]> {
    return this.http
      .get<ApiResponse<ImportFormat[]>>(`${this.base}/import/purchase/formats`)
      .pipe(map((r) => r.data));
  }

  assertFileSize(file: File | { size: number; name?: string }): void {
    if ((file.size ?? 0) > IMPORT_MAX_BYTES) {
      throw new Error(`File exceeds the maximum allowed size of ${IMPORT_MAX_UPLOAD_MB} MB.`);
    }
  }

  parseFile(file: File, format: string): Observable<ImportParseResult> {
    try {
      this.assertFileSize(file);
    } catch (e) {
      return throwError(() => e);
    }
    const ext = file.name.split('.').pop()?.toLowerCase() ?? '';
    if (ext === 'xlsx' || ext === 'xls') {
      if (ext === 'xls') {
        return throwError(
          () =>
            new Error(
              'Legacy .xls is not supported. Save as .xlsx or .csv and try again.',
            ),
        );
      }
      return from(this.excelToCsvText(file)).pipe(
        switchMap((csvText) => this.parseCsvText(csvText, format, file.name.replace(/\.xlsx$/i, '.csv'))),
        map((r) => ({ ...r, filename: r.filename || file.name })),
        this.enterZone(),
      );
    }
    const form = new FormData();
    form.append('file', file, file.name);
    form.append('format', format);
    return this.http
      .post<ApiResponse<ImportParseResult>>(`${this.base}/import/purchase/parse`, form)
      .pipe(
        timeout({ first: PARSE_TIMEOUT_MS }),
        map((r) => ({ ...r.data, filename: r.data.filename || file.name })),
        this.enterZone(),
      );
  }

  parseCsvText(csvText: string, format: string, filename = 'converted.csv'): Observable<ImportParseResult> {
    // Upload as a file (not a huge form field) to avoid double-buffering in PHP.
    const blob = new Blob([csvText], { type: 'text/csv' });
    if (blob.size > IMPORT_MAX_BYTES) {
      return throwError(() => new Error(`Converted file exceeds ${IMPORT_MAX_UPLOAD_MB} MB.`));
    }
    const file = new File([blob], filename, { type: 'text/csv' });
    const form = new FormData();
    form.append('file', file, filename);
    form.append('format', format);
    return this.http
      .post<ApiResponse<ImportParseResult>>(`${this.base}/import/purchase/parse`, form)
      .pipe(
        timeout({ first: PARSE_TIMEOUT_MS }),
        map((r) => ({ ...r.data, filename: r.data.filename || filename })),
      );
  }

  matchSearch(q: string, limit = 20): Observable<{
    id: number;
    name: string;
    manufacturer: string | null;
    default_gst_rate: number;
    hsn_code?: string | null;
    pack_size?: string | null;
  }[]> {
    return this.http
      .post<ApiResponse<{ matches: {
        id: number;
        name: string;
        manufacturer: string | null;
        default_gst_rate: number;
        hsn_code?: string | null;
        pack_size?: string | null;
      }[] }>>(
        `${this.base}/import/purchase/match`,
        { q, limit },
      )
      .pipe(map((r) => r.data.matches ?? []));
  }

  /**
   * Batch-match many product names in one (or few) requests.
   * Dedupes client-side; returns map of lowercased name → top matches.
   */
  matchByNames(names: string[], limit = 3): Observable<Record<string, {
    id: number;
    name: string;
    manufacturer: string | null;
    default_gst_rate: number;
    hsn_code?: string | null;
    pack_size?: string | null;
  }[]>> {
    const unique: string[] = [];
    const seen = new Set<string>();
    for (const n of names) {
      const t = String(n || '').trim();
      if (!t) continue;
      const key = t.toLowerCase();
      if (seen.has(key)) continue;
      seen.add(key);
      unique.push(t);
    }
    if (!unique.length) {
      return of({});
    }

    type MatchRow = {
      id: number;
      name: string;
      manufacturer: string | null;
      default_gst_rate: number;
      hsn_code?: string | null;
      pack_size?: string | null;
    };

    // Chunk unique names so a huge CSV does not create one giant request.
    const chunkSize = 40;
    const chunks: string[][] = [];
    for (let i = 0; i < unique.length; i += chunkSize) {
      chunks.push(unique.slice(i, i + chunkSize));
    }

    const calls = chunks.map((chunk) =>
      this.http
        .post<ApiResponse<{ by_name?: Record<string, MatchRow[]>; lines?: { product_name: string; matches?: MatchRow[] }[] }>>(
          `${this.base}/import/purchase/match`,
          { names: chunk, limit },
        )
        .pipe(
          map((r) => {
            const mapOut: Record<string, MatchRow[]> = {};
            if (r.data.by_name) {
              for (const [k, v] of Object.entries(r.data.by_name)) {
                mapOut[k.toLowerCase()] = v ?? [];
              }
            } else {
              for (const line of r.data.lines ?? []) {
                const key = String(line.product_name || '').toLowerCase();
                if (key) mapOut[key] = line.matches ?? [];
              }
            }
            return mapOut;
          }),
        ),
    );

    return forkJoin(calls).pipe(
      map((parts) => Object.assign({}, ...parts) as Record<string, MatchRow[]>),
      this.enterZone(),
    );
  }

  checkInvoice(invoiceNo: string): Observable<{ exists: boolean; purchase: Record<string, unknown> | null }> {
    const params = new HttpParams().set('invoice_no', invoiceNo);
    return this.http
      .get<ApiResponse<{ exists: boolean; purchase: Record<string, unknown> | null }>>(
        `${this.base}/import/purchase/check-invoice`,
        { params },
      )
      .pipe(map((r) => r.data));
  }

  aiStatus(): Observable<AiStatus> {
    return this.http
      .get<ApiResponse<AiStatus>>(`${this.base}/import/purchase/ai/status`)
      .pipe(map((r) => r.data));
  }

  /**
   * Extract via AI. Prefer page images (PDF converted client-side) for OpenAI vision accuracy.
   */
  extractAi(file: File): Observable<ImportParseResult> {
    try {
      this.assertFileSize(file);
    } catch (e) {
      return throwError(() => e);
    }
    return from(this.prepareAiImages(file)).pipe(
      switchMap((images) => this.extractAiFromImages(images)),
      this.enterZone(),
    );
  }

  extractAiFromImages(images: { base64: string; mimeType: string }[]): Observable<ImportParseResult> {
    if (!images.length) {
      return throwError(() => new Error('No invoice pages to extract.'));
    }
    return this.http
      .post<ApiResponse<ImportParseResult>>(`${this.base}/import/purchase/ai`, { images })
      .pipe(
        timeout({ first: AI_TIMEOUT_MS }),
        map((r) => r.data),
        this.enterZone(),
      );
  }

  private async prepareAiImages(file: File): Promise<{ base64: string; mimeType: string }[]> {
    const ext = file.name.split('.').pop()?.toLowerCase() ?? '';
    const { pdfFileToImages, fileToBase64Image } = await import(
      '../../features/purchase/import-hub/pdf-to-images'
    );
    if (ext === 'pdf' || file.type === 'application/pdf') {
      return pdfFileToImages(file);
    }
    const one = await fileToBase64Image(file);
    return [one];
  }

  gmailStatus(): Observable<GmailStatus> {
    return this.http
      .get<ApiResponse<GmailStatus>>(`${this.base}/import/gmail/status`)
      .pipe(map((r) => r.data));
  }

  gmailStart(): Observable<{ url: string }> {
    return this.http
      .get<ApiResponse<{ url: string }>>(`${this.base}/import/gmail/start`)
      .pipe(map((r) => r.data));
  }

  gmailDisconnect(): Observable<unknown> {
    return this.http.post(`${this.base}/import/gmail/disconnect`, {});
  }

  gmailList(): Observable<GmailAttachment[]> {
    return this.http
      .get<ApiResponse<{ attachments: GmailAttachment[] }>>(`${this.base}/import/gmail/list`)
      .pipe(map((r) => r.data.attachments ?? []));
  }

  gmailDownload(att: GmailAttachment, format: string): Observable<ImportParseResult> {
    try {
      this.assertFileSize(att);
    } catch (e) {
      return throwError(() => e);
    }
    const params = new HttpParams()
      .set('messageId', att.messageId)
      .set('attachmentId', att.attachmentId)
      .set('filename', att.filename)
      .set('format', format);
    return this.http
      .get<ApiResponse<ImportParseResult>>(`${this.base}/import/gmail/download`, { params })
      .pipe(
        timeout({ first: PARSE_TIMEOUT_MS }),
        switchMap((r) => {
          const data = r.data;
          if (data.needs_excel_convert && data.base64) {
            const b64 = data.base64;
            return from(this.excelBase64ToCsv(b64)).pipe(
              switchMap((csv) => this.parseCsvText(csv, format)),
            );
          }
          return of(data);
        }),
        this.enterZone(),
      );
  }

  /** First sheet → CSV text via exceljs (yields to UI between heavy steps). */
  async excelToCsvText(file: File): Promise<string> {
    this.assertFileSize(file);
    await this.yieldUi();
    const ExcelJS = (await import('exceljs')).default;
    const buf = await file.arrayBuffer();
    await this.yieldUi();
    const wb = new ExcelJS.Workbook();
    await wb.xlsx.load(buf);
    await this.yieldUi();
    const csv = this.workbookToCsv(wb);
    return csv;
  }

  private async excelBase64ToCsv(b64: string): Promise<string> {
    await this.yieldUi();
    const ExcelJS = (await import('exceljs')).default;
    const binary = atob(b64);
    const bytes = new Uint8Array(binary.length);
    const chunk = 0x8000;
    for (let i = 0; i < binary.length; i += chunk) {
      const slice = binary.slice(i, i + chunk);
      for (let j = 0; j < slice.length; j++) bytes[i + j] = slice.charCodeAt(j);
      if (i % (chunk * 32) === 0) await this.yieldUi();
    }
    await this.yieldUi();
    const wb = new ExcelJS.Workbook();
    await wb.xlsx.load(bytes.buffer);
    await this.yieldUi();
    return this.workbookToCsv(wb);
  }

  private yieldUi(): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve, 0));
  }

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  private workbookToCsv(wb: any): string {
    const sheet = wb.worksheets[0];
    if (!sheet) return '';
    const rows: string[] = [];
    sheet.eachRow({ includeEmpty: false }, (row: { values: unknown[] }) => {
      const vals = (row.values as unknown[]).slice(1).map((v) => {
        const s = v == null ? '' : String(v);
        if (s.includes(',') || s.includes('"') || s.includes('\n')) {
          return `"${s.replace(/"/g, '""')}"`;
        }
        return s;
      });
      rows.push(vals.join(','));
    });
    return rows.join('\n');
  }
}
