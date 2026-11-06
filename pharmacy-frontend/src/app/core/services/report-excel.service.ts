import { Injectable } from '@angular/core';
import ExcelJS from 'exceljs';

const BORDER_BLACK = { argb: 'FF000000' };

type CellBorderOutline = NonNullable<ExcelJS.Cell['border']>;

const BORDER_THIN: CellBorderOutline = {
  top: { style: 'thin', color: BORDER_BLACK },
  bottom: { style: 'thin', color: BORDER_BLACK },
  left: { style: 'thin', color: BORDER_BLACK },
  right: { style: 'thin', color: BORDER_BLACK },
};

const BORDER_FOOTER_TOP: CellBorderOutline = {
  top: { style: 'double', color: BORDER_BLACK },
  bottom: { style: 'thin', color: BORDER_BLACK },
  left: { style: 'thin', color: BORDER_BLACK },
  right: { style: 'thin', color: BORDER_BLACK },
};

/** Retail-parity multicolor header palette (ARGB). */
const HEADER_COLORS = [
  'FF2563EB', 'FF059669', 'FFD97706', 'FF7C3AED', 'FF0284C7',
  'FFDC2626', 'FF16A34A', 'FF2563EB', 'FF9333EA', 'FFEA580C',
  'FF0891B2', 'FFBE185D',
];

export interface ReportPharmacyHeader {
  businessName: string;
  address?: string;
  city?: string;
  state?: string;
  pincode?: string;
  phone?: string;
  email?: string;
  gstin?: string;
  drugLicenseNo?: string;
  fssaiNo?: string;
  branchName?: string;
  period?: string;
}

export interface ReportExcelExportOptions {
  reportTitle: string;
  sheetName: string;
  headers: string[];
  rows: (string | number | null | undefined)[][];
  filename: string;
  /** 1-based column index from which to right-align numeric cells */
  numericColumnStartIndex?: number;
  footerRow?: (string | number | null | undefined)[];
  pharmacy?: ReportPharmacyHeader;
}

/**
 * Retail-style Excel export: pharmacy letterhead, navy title, multicolor headers, totals row.
 */
@Injectable({ providedIn: 'root' })
export class ReportExcelService {
  private sanitizeSheetName(name: string): string {
    const cleaned = (name || 'Report').replace(/[\\/?*[\]:]/g, ' ').replace(/\s+/g, ' ').trim();
    return cleaned.substring(0, 31) || 'Report';
  }

  private computeColumnWidths(headers: string[], rows: (string | number | null | undefined)[][]): number[] {
    const colCount = headers.length;
    const widths: number[] = [];
    for (let c = 0; c < colCount; c++) {
      let maxLen = (headers[c] || '').toString().length;
      for (const row of rows) {
        const len = row?.[c] != null ? String(row[c]).length : 0;
        if (len > maxLen) maxLen = len;
      }
      widths.push(Math.min(55, Math.max(12, maxLen + 5)));
    }
    return widths;
  }

  private pharmacyLines(p?: ReportPharmacyHeader): string[] {
    if (!p?.businessName) return [];
    const lines: string[] = [p.businessName];
    const addr = [p.address, p.city, p.state, p.pincode]
      .map((x) => (x || '').trim())
      .filter(Boolean)
      .join(', ');
    if (addr) lines.push(addr);
    const ids: string[] = [];
    if (p.drugLicenseNo) ids.push(`DL No.: ${p.drugLicenseNo}`);
    if (p.gstin) ids.push(`GSTIN: ${p.gstin}`);
    if (p.fssaiNo) ids.push(`FSSAI: ${p.fssaiNo}`);
    if (p.phone) ids.push(`Ph: ${p.phone}`);
    if (p.email) ids.push(`Email: ${p.email}`);
    if (ids.length) lines.push(ids.join('  |  '));
    const meta: string[] = [];
    if (p.branchName) meta.push(`Branch: ${p.branchName}`);
    if (p.period) meta.push(`Period: ${p.period}`);
    if (meta.length) lines.push(meta.join('  |  '));
    return lines;
  }

  async exportWithStyle(opts: ReportExcelExportOptions): Promise<void> {
    const {
      reportTitle, sheetName, headers, rows, filename,
      numericColumnStartIndex = 0, footerRow, pharmacy,
    } = opts;
    const colCount = Math.max(1, headers.length);
    const hasData = rows.length > 0;
    const displayRows = hasData ? rows : [['No data']];
    const sizing = hasData ? [...rows] : displayRows;
    if (hasData && footerRow?.length === colCount) {
      sizing.push(footerRow);
    }
    const widths = this.computeColumnWidths(headers, sizing);
    const letterhead = this.pharmacyLines(pharmacy);

    const wb = new ExcelJS.Workbook();
    // Freeze below letterhead + title + column header
    const freezeAt = letterhead.length + 2;
    const ws = wb.addWorksheet(this.sanitizeSheetName(sheetName), {
      views: [{ state: 'frozen', ySplit: freezeAt }],
    });
    ws.columns = widths.map((w) => ({ width: w }));

    let rowNum = 1;
    for (let i = 0; i < letterhead.length; i++) {
      ws.mergeCells(rowNum, 1, rowNum, colCount);
      const cell = ws.getCell(rowNum, 1);
      cell.value = letterhead[i];
      cell.alignment = { horizontal: 'center', vertical: 'middle', wrapText: true };
      if (i === 0) {
        cell.font = { bold: true, size: 16, color: { argb: 'FF0F766E' } };
        ws.getRow(rowNum).height = 24;
      } else {
        cell.font = { size: 10, color: { argb: 'FF334155' } };
        ws.getRow(rowNum).height = 16;
      }
      rowNum++;
    }

    ws.mergeCells(rowNum, 1, rowNum, colCount);
    const titleCell = ws.getCell(rowNum, 1);
    titleCell.value = reportTitle;
    titleCell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF1F4E78' } };
    titleCell.font = { bold: true, color: { argb: 'FFFFFFFF' }, size: 14 };
    titleCell.alignment = { horizontal: 'center', vertical: 'middle' };
    titleCell.border = {
      top: { style: 'medium', color: BORDER_BLACK },
      bottom: { style: 'medium', color: BORDER_BLACK },
      left: { style: 'medium', color: BORDER_BLACK },
      right: { style: 'medium', color: BORDER_BLACK },
    };
    ws.getRow(rowNum).height = 22;
    rowNum++;

    const headerRow = ws.getRow(rowNum);
    headers.forEach((h, col) => {
      const cell = headerRow.getCell(col + 1);
      cell.value = h;
      cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: HEADER_COLORS[col % HEADER_COLORS.length] } };
      cell.font = { bold: true, color: { argb: 'FFFFFFFF' }, size: 11 };
      cell.alignment = { horizontal: 'center', vertical: 'middle', wrapText: true };
      cell.border = BORDER_THIN;
    });
    headerRow.height = 22;
    const dataStart = rowNum + 1;
    rowNum++;

    displayRows.forEach((rowValues, i) => {
      const dataRow = ws.getRow(dataStart + i);
      const isNoData = !hasData && i === 0;
      for (let col = 1; col <= colCount; col++) {
        const cell = dataRow.getCell(col);
        if (isNoData && col === 1) {
          cell.value = 'No data';
          cell.alignment = { horizontal: 'center', vertical: 'middle', wrapText: true };
        } else if (!isNoData) {
          cell.value = rowValues[col - 1] ?? '';
          const isNumeric = numericColumnStartIndex > 0 && col >= numericColumnStartIndex;
          cell.alignment = { horizontal: isNumeric ? 'right' : 'left', vertical: 'middle', wrapText: true };
          if (isNumeric && typeof cell.value === 'number') {
            cell.numFmt = '#,##0.00';
          }
        }
        cell.border = BORDER_THIN;
        if (!isNoData && i % 2 === 1) {
          cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFF2F2F2' } };
        }
      }
      if (isNoData && colCount > 1) {
        ws.mergeCells(dataStart + i, 1, dataStart + i, colCount);
      }
    });

    if (hasData && footerRow && footerRow.length === colCount) {
      const totalRow = ws.getRow(dataStart + rows.length);
      for (let col = 1; col <= colCount; col++) {
        const v = footerRow[col - 1];
        const cell = totalRow.getCell(col);
        cell.value = v === null || v === undefined ? '' : v;
        cell.font = { bold: true };
        cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFE7EEF7' } };
        cell.border = BORDER_FOOTER_TOP;
        const isNum = typeof v === 'number';
        cell.alignment = { horizontal: isNum ? 'right' : 'left', vertical: 'middle', wrapText: true };
        if (isNum) {
          cell.numFmt = '#,##0.00';
        }
      }
      totalRow.height = 22;
    }

    const buffer = await wb.xlsx.writeBuffer();
    const blob = new Blob([buffer], {
      type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    URL.revokeObjectURL(url);
  }
}
