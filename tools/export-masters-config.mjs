import { writeFileSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const repoRoot = join(dirname(fileURLToPath(import.meta.url)), '..');
const configUrl = pathToFileURL(
  join(repoRoot, 'pharmacy-frontend/src/app/features/masters/master.config.ts'),
).href;

const { MASTER_CONFIGS, GST_STATE_CODES } = await import(configUrl);

function strip(c) {
  return {
    key: c.key,
    title: c.title,
    subtitle: c.subtitle,
    icon: c.icon,
    resourcePath: c.resourcePath,
    canCreate: c.canCreate ?? true,
    canEdit: c.canEdit ?? true,
    canDelete: c.canDelete ?? true,
    bindable: c.bindable ?? false,
    statsPath: c.statsPath ?? null,
    kpiPreset: c.kpiPreset ?? null,
    columns: c.columns,
    fields: (c.fields ?? []).map((f) => ({
      key: f.key,
      label: f.label,
      type: f.type,
      required: !!f.required,
      hideOnEdit: !!f.hideOnEdit,
      options: f.options ?? null,
      optionSource: f.optionSource ?? null,
      validation: f.validation ?? null,
      maxLength: f.maxLength ?? null,
      min: f.min ?? null,
      max: f.max ?? null,
      showWhen: f.showWhen ?? null,
      uppercase: !!f.uppercase,
    })),
    listFilters: c.listFilters ?? null,
  };
}

const out = {};
for (const [k, v] of Object.entries(MASTER_CONFIGS)) out[k] = strip(v);

const dest = join(repoRoot, 'advanced-pharmacy-android/app/src/main/assets/masters_config.json');
mkdirSync(dirname(dest), { recursive: true });
writeFileSync(dest, JSON.stringify(out));
const gstDest = join(repoRoot, 'advanced-pharmacy-android/app/src/main/assets/gst_states.json');
writeFileSync(
  gstDest,
  JSON.stringify(GST_STATE_CODES.map((s) => ({ value: s.value, label: s.label, code: s.code }))),
);
console.log('Wrote', dest, Object.keys(out).length, 'masters');
console.log('Wrote', gstDest);
