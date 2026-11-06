/** Retail #/uploadPurchase formatConfigs — column maps after skipCols. */

export interface UploadFormatConfig {
  skipRows: number[];
  skipCols: number[];
  headerRowIndex: number | null;
  headerKeys: string[] | null;
}

export const UPLOAD_PURCHASE_FORMATS = [
  'Medivision',
  'Marg ERP Format',
  'Marg ERP Nano',
  'Marg ERP 2',
  'Marg ERP 3',
  'Medica',
  'Easysol',
  'Care',
  'PHARMACC',
  'Reliable',
  'Samarth',
  'MicroPro',
  'Dawa',
] as const;

export type UploadPurchaseFormat = (typeof UPLOAD_PURCHASE_FORMATS)[number];

export const UPLOAD_FORMAT_CONFIGS: Record<string, UploadFormatConfig> = {
  Medivision: {
    skipRows: [0],
    skipCols: [0, 1, 5, 13, 14, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33],
    headerRowIndex: 0,
    headerKeys: [
      'product', 'm_nameCsv', 'packSizeFromCsv', 'qty', 'scheme', 'mrp', 'purchase_rate', 'batch_no',
      'expiry_date', 'totalAmt', 'disc_amt', 'gst',
    ],
  },
  'Marg ERP Format': {
    skipRows: [0],
    skipCols: [0, 1, 3, 4, 7, 10, 11, 14, 15, 17, 18, 19, 24, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 37, 38, 39, 40, 41, 42, 43, 44, 45, 46, 47, 48, 49],
    headerRowIndex: 0,
    headerKeys: [
      'm_nameCsv', 'product', 'packSizeFromCsv', 'batch_no', 'expiry_date', 'gst', 'purchase_rate', 'mrp',
      'qty', 'scheme', 'disc', 'disc_amt', 'totalAmt',
    ],
  },
  'Marg ERP 2': {
    skipRows: [0],
    skipCols: [0, 1, 4, 7, 10, 11, 13, 15, 17, 18, 19, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 37, 38, 39, 40, 41, 42, 43, 44, 45, 46, 47, 48],
    headerRowIndex: 0,
    headerKeys: [
      'm_nameCsv', 'hsn', 'product', 'packSizeFromCsv', 'batch_no', 'expiry_date', 'gst', 'purchase_rate', 'mrp',
      'qty', 'scheme',
    ],
  },
  'Marg ERP 3': {
    skipRows: [0],
    skipCols: [0, 1, 3, 4, 7, 10, 13, 14, 17, 18, 19, 20, 21, 23, 24, 25, 27, 28, 29, 31, 32, 33, 34, 35, 36, 37, 38, 39, 40, 41, 42, 43, 44, 45, 46, 47, 48],
    headerRowIndex: 0,
    headerKeys: [
      'm_nameCsv', 'product', 'packSizeFromCsv', 'batch_no', 'expiry_date', 'purchase_rate', 'mrp',
      'qty', 'scheme', 'cgstPer', 'sgstPer', 'hsn',
    ],
  },
  Medica: {
    skipRows: [0],
    skipCols: [
      0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 24, 27, 28, 33, 36, 38, 39, 40, 42, 43, 44, 45, 46, 47, 48, 49,
      50, 51, 52, 53, 54, 55, 56, 57, 60, 62, 64, 65, 66, 67, 68, 69, 70, 71, 72, 73, 74, 75,
    ],
    headerRowIndex: 0,
    headerKeys: [
      'm_nameCsv', 'product', 'packSizeFromCsv', 'batch_no', 'expiry_date', 'qty', 'scheme', 'purchase_rate', 'totalAmt',
      'mrp', 'disc', 'hsn', 'igstPer', 'cgstPer', 'sgstPer',
    ],
  },
  Easysol: {
    skipRows: [0],
    skipCols: [0, 1, 2, 3, 7, 16, 17, 18, 19, 20, 21, 22, 23, 24, 26, 28, 30, 31, 32, 34, 35, 36, 37],
    headerRowIndex: 0,
    headerKeys: [
      'product', 'packSizeFromCsv', 'm_nameCsv', 'batch_no', 'expiry_date', 'purchase_rate', 'mrp',
      'totalAmt', 'qty', 'scheme', 'disc_amt', 'sgstPer', 'cgstPer', 'igstPer', 'hsn',
    ],
  },
  Care: {
    skipRows: [0],
    skipCols: [0, 1, 3, 4, 7, 10, 11, 14, 15, 17, 18, 19, 22, 23, 24, 25, 26, 28, 29, 30, 31, 32, 33, 34, 35, 37],
    headerRowIndex: 0,
    headerKeys: [
      'm_nameCsv', 'product', 'packSizeFromCsv', 'batch_no', 'expiry_date', 'gst', 'purchase_rate', 'mrp', 'qty', 'scheme',
      'totalAmt', 'hsn',
    ],
  },
  PHARMACC: {
    skipRows: [0],
    skipCols: [0, 1, 2, 3, 4, 10, 11, 12, 14, 15, 17, 18, 19, 22, 23, 24, 25, 26, 27, 29, 31, 32, 33],
    headerRowIndex: 0,
    headerKeys: [
      'product', 'packSizeFromCsv', 'm_nameCsv', 'batch_no', 'expiry_date', 'purchase_rate', 'mrp', 'qty', 'scheme',
      'gst', 'hsn',
    ],
  },
  Reliable: {
    skipRows: [0],
    skipCols: [0, 3, 4, 5, 9, 15, 16, 17, 18, 19, 20, 22, 23, 24, 25, 27, 28, 29, 31, 32, 33, 34, 35, 36, 37],
    headerRowIndex: 0,
    headerKeys: [
      'product', 'm_nameCsv', 'batch_no', 'expiry_date', 'mrp', 'gst', 'qty', 'scheme',
      'packSizeFromCsv', 'disc_amt', 'totalAmt', 'hsn', 'purchase_rate',
    ],
  },
  Samarth: {
    skipRows: [0],
    skipCols: [0, 1, 2, 3, 6, 15, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 30, 31, 32, 34, 35, 36, 37, 38],
    headerRowIndex: 0,
    headerKeys: [
      'product', 'packSizeFromCsv', 'm_nameCsv', 'batch_no', 'expiry_date', 'purchase_rate', 'mrp', 'totalAmt',
      'qty', 'scheme', 'disc', 'disc_amt', 'gst', 'hsn',
    ],
  },
  'Marg ERP Nano': {
    skipRows: [0],
    skipCols: [
      0, 1, 2, 3, 4, 6, 7, 8, 9, 10, 11, 13, 15, 17, 18, 19, 24, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 37, 39,
      40, 41, 42, 43, 44, 45, 46, 47, 48, 49, 50,
    ],
    headerRowIndex: 0,
    headerKeys: [
      'product', 'gst', 'purchase_rate', 'mrp', 'qty', 'scheme', 'disc', 'disc_amt',
      'totalAmt', 'hsn',
    ],
  },
  MicroPro: {
    skipRows: [0],
    skipCols: [0, 1, 2, 3, 4, 11, 13, 14, 17, 18, 19, 20, 22, 23, 25, 26, 27, 28, 29, 31],
    headerRowIndex: 0,
    headerKeys: [
      'product', 'packSizeFromCsv', 'm_nameCsv', 'batch_no', 'expiry_date', 'purchase_rate', 'mrp', 'qty', 'scheme', 'totalAmt',
      'gst', 'hsn',
    ],
  },
  Dawa: {
    skipRows: [0],
    skipCols: [0, 1, 3, 4, 7, 10, 11, 13, 15, 17, 18, 19, 22, 23, 24, 25, 27],
    headerRowIndex: 0,
    headerKeys: [
      'm_nameCsv', 'product', 'packSizeFromCsv', 'batch_no', 'expiry_date', 'gst', 'purchase_rate', 'mrp', 'qty',
      'scheme', 'hsn',
    ],
  },
};
