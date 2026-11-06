/** Shared pack-size helpers for import / upload purchase flows. */

export type PackSizeOption = {
  pack: string;
  label?: string;
  units: number;
  form: string;
};

export function packKey(s: string): string {
  return String(s || '')
    .replace(/\s+/g, '')
    .toLowerCase();
}

export function findPackOption(list: PackSizeOption[], raw: string): PackSizeOption | undefined {
  const key = packKey(raw);
  if (!key) return undefined;
  return list.find((p) => packKey(p.pack) === key || packKey(p.label || '') === key);
}

/** Prefer master list label; keep unknown CSV/AI values so nothing is dropped. */
export function resolvePackValue(list: PackSizeOption[], raw: string): string {
  const cur = String(raw || '').trim();
  if (!cur) return '';
  return findPackOption(list, cur)?.pack ?? cur;
}

export function packOptionsIncluding(list: PackSizeOption[], current: string): PackSizeOption[] {
  const cur = String(current || '').trim();
  if (!cur || findPackOption(list, cur)) return list;
  return [{ pack: cur, label: cur, units: 1, form: 'Custom' }, ...list];
}

export function unitsForPack(list: PackSizeOption[], pack: string, fallback = 1): number {
  const found = findPackOption(list, pack);
  if (found?.units) return found.units;
  const m = packKey(pack).match(/^(\d+)x(\d+)/);
  if (m) return Math.max(1, Number(m[2]) || 1);
  return fallback;
}
