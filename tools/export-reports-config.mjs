// Exports the web Reports catalog + column maps (reports.ts) to the Android asset reports_config.json.
// Run from repo root: node tools/export-reports-config.mjs
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const repoRoot = join(dirname(fileURLToPath(import.meta.url)), '..');
const src = readFileSync(join(repoRoot, 'pharmacy-frontend/src/app/features/reports/reports.ts'), 'utf8');

/** Returns the bracketed literal that starts right after `marker` (balanced [] / {}). */
function literalAfter(marker) {
  const at = src.indexOf(marker);
  if (at < 0) throw new Error(`Marker not found: ${marker}`);
  let i = at + marker.length;
  while (src[i] !== '[' && src[i] !== '{') i++;
  const open = src[i];
  const close = open === '[' ? ']' : '}';
  let depth = 0;
  let inStr = null;
  for (let j = i; j < src.length; j++) {
    const ch = src[j];
    if (inStr) {
      if (ch === '\\') { j++; continue; }
      if (ch === inStr) inStr = null;
      continue;
    }
    if (ch === "'" || ch === '"' || ch === '`') { inStr = ch; continue; }
    if (ch === '/' && src[j + 1] === '/') { j = src.indexOf('\n', j); continue; }
    if (ch === open) depth++;
    else if (ch === close && --depth === 0) return src.slice(i, j + 1);
  }
  throw new Error(`Unbalanced literal after ${marker}`);
}

function evalLiteral(text, scope = {}) {
  const names = Object.keys(scope);
  // eslint-disable-next-line no-new-func
  return new Function(...names, `return (${text.replace(/this\./g, '')});`)(...names.map((n) => scope[n]));
}

const scheduleCols = evalLiteral(literalAfter('private readonly scheduleCols: Column[] ='));
const portalHsnCols = evalLiteral(literalAfter('private readonly portalHsnCols: Column[] ='));
// stock columns are built at runtime via stockBatchCols(); catalog stores empty arrays.
const stockBatchCols = [];
const scope = { scheduleCols, portalHsnCols, stockBatchCols };

const out = {
  categories: evalLiteral(literalAfter('readonly categories: CategoryChip[] =')),
  reports: evalLiteral(literalAfter('readonly reports: ReportDef[] =')),
  columns: evalLiteral(literalAfter('readonly columns: Record<string, Column[]> ='), scope),
  detailColumns: evalLiteral(literalAfter('private readonly detailColumns: Record<string, Column[]> ='), scope),
};

const dest = join(repoRoot, 'advanced-pharmacy-android/app/src/main/assets/reports_config.json');
mkdirSync(dirname(dest), { recursive: true });
writeFileSync(dest, JSON.stringify(out));
console.log('Wrote', dest, out.reports.length, 'reports,', Object.keys(out.columns).length, 'column sets');
