/** Distributor row fields used in purchase / import / scan combos. */
export type DistributorLabelRow = {
  name: string;
  address?: string | null;
};

/** Collapse whitespace and truncate for compact dropdown display. */
export function compactDistributorAddress(address: string | null | undefined, max = 42): string {
  const s = String(address ?? '').replace(/\s+/g, ' ').trim();
  if (!s) return '';
  if (s.length <= max) return s;
  return `${s.slice(0, Math.max(1, max - 1)).trimEnd()}…`;
}

/**
 * Dropdown label: `NAME (compact address)`.
 * Address omitted when empty.
 */
export function distributorDropdownLabel(row: DistributorLabelRow, maxAddr = 42): string {
  const name = String(row.name ?? '').trim();
  const addr = compactDistributorAddress(row.address, maxAddr);
  if (!name) return addr ? `(${addr})` : '';
  if (!addr) return name;
  return `${name} (${addr})`;
}
