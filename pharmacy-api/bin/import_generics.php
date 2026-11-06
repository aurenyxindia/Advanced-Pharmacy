<?php

declare(strict_types=1);

/**
 * Import retail generic dump + enrich Advanced Pharmacy generics master.
 *
 * - Deduplicate by normalised scientific name
 * - Fix common misspellings / scientific naming
 * - Map therapeutic class
 * - Assign schedule_category (OTC/H/H1/X/G/RESTRICTED)
 * - Enrich missing generics from Indian medicine dataset compositions
 *
 * Usage: php bin/import_generics.php [--dry-run]
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Support\Env;
use Dotenv\Dotenv;

$configDir = dirname(__DIR__) . '/config';
if (is_file($configDir . '/.env')) {
    Dotenv::createImmutable($configDir, '.env')->safeLoad();
}

$dryRun = in_array('--dry-run', $argv ?? [], true);

$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        Env::get('DB_HOST', '127.0.0.1'),
        (int) Env::get('DB_PORT', 3306),
        Env::get('DB_MASTER', 'pharma_master')
    ),
    (string) Env::get('DB_USER', 'root'),
    (string) Env::get('DB_PASS', ''),
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]
);

$now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
$csvPath = dirname(__DIR__) . '/storage/indian_medicine_data.csv';

/** Scientific spelling / typo fixes (whole-word, case-insensitive) */
$spellFixes = [
    'propanolol' => 'Propranolol',
    'propranalol' => 'Propranolol',
    'propanololhcl' => 'Propranolol HCl',
    'propranololhcl' => 'Propranolol HCl',
    'metoprolole' => 'Metoprolol',
    'metoprololtartrate' => 'Metoprolol Tartrate',
    'metoprololtart' => 'Metoprolol Tartrate',
    'metoprolol tart' => 'Metoprolol Tartrate',
    'metoprololsuccinate' => 'Metoprolol Succinate',
    'amlodepine' => 'Amlodipine',
    'amlodipin' => 'Amlodipine',
    'atnolol' => 'Atenolol',
    'indepamide' => 'Indapamide',
    'hydrallazine' => 'Hydralazine',
    'hydrallazinehcl' => 'Hydralazine HCl',
    'hydrochlorothiazide' => 'Hydrochlorothiazide',
    'cetirizin' => 'Cetirizine',
    'cetrizine' => 'Cetirizine',
    'amoxycillin' => 'Amoxicillin',
    'amoxicilin' => 'Amoxicillin',
    'clavulanicacid' => 'Clavulanic Acid',
    'paracetamole' => 'Paracetamol',
    'acetaminophen' => 'Paracetamol',
    'diclofenacna' => 'Diclofenac Sodium',
    'diclofenacsodium' => 'Diclofenac Sodium',
    'omeprazol' => 'Omeprazole',
    'pantoprazol' => 'Pantoprazole',
    'rabeprazol' => 'Rabeprazole',
    'esomeprazol' => 'Esomeprazole',
    'atorvastatine' => 'Atorvastatin',
    'rosuvastatine' => 'Rosuvastatin',
    'telmisartane' => 'Telmisartan',
    'losartane' => 'Losartan',
    'metformine' => 'Metformin',
    'glimepiride' => 'Glimepiride',
    'azithromycine' => 'Azithromycin',
    'ciprofloxacine' => 'Ciprofloxacin',
    'ofloxacine' => 'Ofloxacin',
    'levofloxacine' => 'Levofloxacin',
    'moxifloxacine' => 'Moxifloxacin',
    'domperidone' => 'Domperidone',
    'ondansetrone' => 'Ondansetron',
    'montelukast' => 'Montelukast',
    'levocetrizine' => 'Levocetirizine',
    'levocetirizin' => 'Levocetirizine',
    'fexofenadine' => 'Fexofenadine',
    'chlopheniramine' => 'Chlorpheniramine',
    'chlorphenaramine' => 'Chlorpheniramine',
    'dextromethorphan' => 'Dextromethorphan',
    'phenylephrine' => 'Phenylephrine',
    'ibuprofene' => 'Ibuprofen',
    'aceclofenac' => 'Aceclofenac',
    'serratiopeptidase' => 'Serratiopeptidase',
    'trypsin' => 'Trypsin',
    'chymotrypsin' => 'Chymotrypsin',
    'vitamin' => 'Vitamin',
    '1omg' => '10mg',
    '5omg' => '50mg',
    '2omg' => '20mg',
    'hcl' => 'HCl',
    'hcl.' => 'HCl',
];

$theraFixes = [
    'beta bloker' => 'Beta Blocker',
    'beta blocker' => 'Beta Blocker',
    'ani-anginal drugs' => 'Anti-anginal Drugs',
    'topical antifungall and anti- parasitics' => 'Topical Antifungal and Antiparasitic',
    'topical steroids' => 'Topical Steroids',
    'hyper and hypoglycaemics' => 'Anti-diabetic',
    'h2blockers & ulcer healing' => 'H2 Blockers & Ulcer Healing',
    'sedatives and tranquillisers' => 'Sedatives and Tranquillisers',
    'anti-infective eye preparations' => 'Anti-Infective Eye Preparations',
    'cephalosporin' => 'Cephalosporins',
    'penicillins' => 'Penicillins',
    'non opioid analgesics' => 'Non-Opioid Analgesics',
    'hormones and related drugs' => 'Hormones and Related Drugs',
    'antihypertensive drug' => 'Antihypertensive',
    'anti-allergics' => 'Anti-Allergic',
    'muscle relaxants' => 'Muscle Relaxants',
];

/**
 * Molecule / salt → Indian schedule (CDSCO-oriented practical retail map).
 * More specific / longer keys win via matching logic.
 */
$scheduleByMolecule = [
    // Schedule X
    'amphetamine' => 'X', 'dexamphetamine' => 'X', 'methamphetamine' => 'X',
    'methylphenidate' => 'X', 'amobarbital' => 'X', 'pentobarbital' => 'X',
    'secobarbital' => 'X', 'glutethimide' => 'X', 'meprobamate' => 'X',
    'phencyclidine' => 'X', 'methaqualone' => 'X', 'barbitone' => 'X',
    'phenobarbitone' => 'X', 'phenobarbital' => 'X',

    // Schedule H1 — 3rd/4th gen cephalosporins, carbapenems, anti-TB, habit-forming
    'cefixime' => 'H1', 'cefpodoxime' => 'H1', 'ceftriaxone' => 'H1', 'ceftazidime' => 'H1',
    'cefepime' => 'H1', 'cefoperazone' => 'H1', 'cefdinir' => 'H1', 'cefpirome' => 'H1',
    'cefuroxime' => 'H1', 'cefotaxime' => 'H1', 'cefprozil' => 'H1',
    'meropenem' => 'H1', 'imipenem' => 'H1', 'ertapenem' => 'H1', 'doripenem' => 'H1',
    'faropenem' => 'H1', 'colistin' => 'H1', 'polymyxin' => 'H1',
    'moxifloxacin' => 'H1', 'gemifloxacin' => 'H1', 'gatifloxacin' => 'H1',
    'linezolid' => 'H1', 'vancomycin' => 'H1', 'teicoplanin' => 'H1',
    'isoniazid' => 'H1', 'rifampicin' => 'H1', 'rifampin' => 'H1', 'ethambutol' => 'H1',
    'pyrazinamide' => 'H1', 'streptomycin' => 'H1', 'cycloserine' => 'H1',
    'ethionamide' => 'H1', 'prothionamide' => 'H1', 'capreomycin' => 'H1',
    'clofazimine' => 'H1', 'rifabutin' => 'H1', 'bedaquiline' => 'H1',
    'alprazolam' => 'H1', 'lorazepam' => 'H1', 'diazepam' => 'H1', 'clonazepam' => 'H1',
    'nitrazepam' => 'H1', 'midazolam' => 'H1', 'chlordiazepoxide' => 'H1',
    'tramadol' => 'H1', 'codeine' => 'H1', 'dextropropoxyphene' => 'H1',
    'pentazocine' => 'H1', 'buprenorphine' => 'H1', 'diphenoxylate' => 'H1',
    'ketamine' => 'H1', 'zolpidem' => 'H1', 'zopiclone' => 'H1',
    'tapentadol' => 'H1', 'fentanyl' => 'H1', 'morphine' => 'H1', 'pethidine' => 'H1',

    // Schedule G
    'insulin' => 'G', 'testosterone' => 'G', 'thyroxine' => 'G', 'levothyroxine' => 'G',
    'carbimazole' => 'G', 'methimazole' => 'G', 'tolbutamide' => 'G', 'chlorpropamide' => 'G',
    'glibenclamide' => 'G', 'glipizide' => 'G', 'gliclazide' => 'G', 'glimepiride' => 'G',

    // Schedule H — common prescription
    'amoxicillin' => 'H', 'ampicillin' => 'H', 'cloxacillin' => 'H', 'dicloxacillin' => 'H',
    'azithromycin' => 'H', 'erythromycin' => 'H', 'clarithromycin' => 'H', 'roxithromycin' => 'H',
    'ciprofloxacin' => 'H', 'ofloxacin' => 'H', 'norfloxacin' => 'H', 'levofloxacin' => 'H',
    'doxycycline' => 'H', 'tetracycline' => 'H', 'minocycline' => 'H',
    'metronidazole' => 'H', 'tinidazole' => 'H', 'ornidazole' => 'H',
    'fluconazole' => 'H', 'itraconazole' => 'H', 'ketoconazole' => 'H', 'terbinafine' => 'H',
    'acyclovir' => 'H', 'valacyclovir' => 'H', 'oseltamivir' => 'H',
    'atorvastatin' => 'H', 'rosuvastatin' => 'H', 'simvastatin' => 'H', 'fenofibrate' => 'H',
    'amlodipine' => 'H', 'telmisartan' => 'H', 'losartan' => 'H', 'olmesartan' => 'H',
    'ramipril' => 'H', 'enalapril' => 'H', 'lisinopril' => 'H', 'perindopril' => 'H',
    'metoprolol' => 'H', 'atenolol' => 'H', 'propranolol' => 'H', 'bisoprolol' => 'H',
    'carvedilol' => 'H', 'nebivolol' => 'H', 'clonidine' => 'H',
    'hydrochlorothiazide' => 'H', 'indapamide' => 'H', 'furosemide' => 'H', 'spironolactone' => 'H',
    'metformin' => 'H', 'sitagliptin' => 'H', 'vildagliptin' => 'H', 'dapagliflozin' => 'H',
    'empagliflozin' => 'H', 'pioglitazone' => 'H', 'gliclazide' => 'H',
    'pantoprazole' => 'H', 'rabeprazole' => 'H', 'omeprazole' => 'H', 'esomeprazole' => 'H',
    'domperidone' => 'H', 'ondansetron' => 'H', 'itopride' => 'H', 'levosulpiride' => 'H',
    'aceclofenac' => 'H', 'diclofenac' => 'H', 'nimesulide' => 'H', 'etoricoxib' => 'H',
    'tramadol' => 'H1', // already H1 above
    'montelukast' => 'H', 'budesonide' => 'H', 'salbutamol' => 'H', 'formoterol' => 'H',
    'levocetirizine' => 'H', 'fexofenadine' => 'H', 'desloratadine' => 'H',
    'prednisolone' => 'H', 'methylprednisolone' => 'H', 'dexamethasone' => 'H',
    'hydrocortisone' => 'H', 'betamethasone' => 'H', 'clobetasol' => 'H', 'mometasone' => 'H',
    'warfarin' => 'H', 'clopidogrel' => 'H', 'aspirin' => 'H', 'ecosprin' => 'H',
    'thyroxine' => 'G', 'carbamazepine' => 'H', 'valproate' => 'H', 'levetiracetam' => 'H',
    'gabapentin' => 'H', 'pregabalin' => 'H', 'amitriptyline' => 'H', 'sertraline' => 'H',
    'fluoxetine' => 'H', 'escitalopram' => 'H', 'olanzapine' => 'H', 'quetiapine' => 'H',
    'risperidone' => 'H', 'haloperidol' => 'H',

    // OTC
    'paracetamol' => 'OTC', 'acetaminophen' => 'OTC', 'ibuprofen' => 'OTC',
    'cetirizine' => 'OTC', 'loratadine' => 'OTC', 'chlorpheniramine' => 'OTC',
    'ranitidine' => 'OTC', 'antacid' => 'OTC', 'ORS' => 'OTC', 'oral rehydration' => 'OTC',
    'vitamin c' => 'OTC', 'ascorbic' => 'OTC', 'calcium carbonate' => 'OTC',
    'simethicone' => 'OTC', 'activated charcoal' => 'OTC', 'povidone iodine' => 'OTC',
    'benzoyl peroxide' => 'OTC', 'clotrimazole' => 'OTC', 'miconazole' => 'OTC',
];

function normKey(string $s): string
{
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = mb_strtolower(trim($s));
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    $s = preg_replace('/[^a-z0-9+.\-\/\s]/u', '', $s) ?? $s;
    return trim($s);
}

function titleCaseDrug(string $s): string
{
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    // Keep HCl, ER, SR, XR, CR, MR, DT, OD as-is after lower→ucwords
    $s = mb_convert_case(mb_strtolower($s), MB_CASE_TITLE, 'UTF-8');
    $s = preg_replace('/\bHcl\b/', 'HCl', $s) ?? $s;
    $s = preg_replace_callback('/\b(Er|Sr|Xr|Cr|Mr|Dt|Od|Bd|Md)\b/', static function ($m) {
        return strtoupper($m[1]);
    }, $s) ?? $s;
    return $s;
}

function cleanGenericName(string $raw, array $spellFixes): string
{
    $s = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    if ($s === '') {
        return '';
    }

    // Fix glued salt+HCl
    $s = preg_replace('/([a-z])HCL\b/i', '$1 HCl', $s) ?? $s;
    $s = preg_replace('/\bHCL\b/i', 'HCl', $s) ?? $s;

    // Apply spelling fixes on normalised tokens
    $lower = mb_strtolower($s);
    foreach ($spellFixes as $wrong => $right) {
        if (str_contains(str_replace([' ', '+', ','], '', $lower), str_replace([' ', '+', ','], '', $wrong))) {
            $lower = preg_replace('/\b' . preg_quote($wrong, '/') . '\b/i', $right, $lower) ?? $lower;
        }
    }
    // Digit letter o typos: 1omg
    $lower = preg_replace('/\b(\d)o(mg|mcg|ml|g)\b/i', '$1$2', $lower) ?? $lower;
    $lower = preg_replace('/\b(\d+)o(mg|mcg)\b/i', '$1$2', $lower) ?? $lower;

    // Normalise separators around +
    $lower = preg_replace('/\s*\+\s*/', ' + ', $lower) ?? $lower;
    $lower = preg_replace('/\s*,\s*/', ', ', $lower) ?? $lower;

    // Drop trailing redundant "Tablet/Capsule" noise but keep strength
    // Keep dosage form lightly for uniqueness of retail rows; trim trailing spaces
    $name = titleCaseDrug($lower);
    if (mb_strlen($name) > 180) {
        $name = mb_substr($name, 0, 180);
    }
    return trim($name);
}

function cleanThera(?string $raw, array $theraFixes): ?string
{
    $s = trim((string) $raw);
    if ($s === '' || strcasecmp($s, 'NULL') === 0) {
        return null;
    }
    $key = mb_strtolower($s);
    if (isset($theraFixes[$key])) {
        return $theraFixes[$key];
    }
    foreach ($theraFixes as $w => $r) {
        if (str_contains($key, $w)) {
            return $r;
        }
    }
    $s = titleCaseDrug($s);
    if (mb_strlen($s) > 120) {
        $s = mb_substr($s, 0, 120);
    }
    return $s;
}

function moleculeTokens(string $name): array
{
    $n = normKey($name);
    // Remove dosage / form noise
    $n = preg_replace(
        '/\b(\d+(\.\d+)?\s*(mg|mcg|g|ml|iu|%|units?)|tablet|tablets|capsule|capsules|syrup|injection|inj|drops?|cream|ointment|gel|suspension|solution|powder|sachet|vial|ampoule|softgel|patch|spray|lotion|shampoo|soap|dt|sr|er|xr|cr|mr|forte|plus)\b/u',
        ' ',
        $n
    ) ?? $n;
    $n = preg_replace('/\s+/', ' ', trim($n)) ?? '';
    $parts = preg_split('/\s*\+\s*|\s*,\s*|\//', $n) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') {
            continue;
        }
        // first significant word of each salt
        if (preg_match('/^([a-z][a-z0-9-]{2,})/', $p, $m)) {
            $out[] = $m[1];
        }
        $out[] = $p;
    }
    return array_values(array_unique($out));
}

function inferSchedule(string $name, ?string $thera, array $scheduleByMolecule): string
{
    $tokens = moleculeTokens($name);
    $best = null;
    $bestLen = 0;
    foreach ($scheduleByMolecule as $mol => $sch) {
        $mol = mb_strtolower($mol);
        foreach ($tokens as $t) {
            if ($t === $mol || str_starts_with($t, $mol) || str_contains($t, $mol)) {
                if (strlen($mol) > $bestLen) {
                    $bestLen = strlen($mol);
                    $best = $sch;
                }
            }
        }
        // also search full name
        if (str_contains(normKey($name), $mol) && strlen($mol) > $bestLen) {
            $bestLen = strlen($mol);
            $best = $sch;
        }
    }
    if ($best !== null) {
        return $best;
    }

    $th = mb_strtolower((string) $thera);
    if ($th !== '') {
        if (preg_match('/cephalospor|carbapenem|anti.?tb|tubercul|habit|benzodiazep|hypnotic|sedative|tranquill/', $th)) {
            return 'H1';
        }
        if (preg_match('/penicillin|antibiotic|anti.?infect|quinolone|macrolide|antifungal|antiviral|steroid|corticosteroid|antihypertens|beta.?block|anti.?anginal|hypoglyc|diabet|ulcer|h2|nsaid|analgesic|anti.?allergic|hormone|miotic|acne|muscle relax/', $th)) {
            // Non-opioid analgesics may be OTC — refine
            if (preg_match('/non.?opioid analgesic/', $th) && preg_match('/paracetamol|ibuprofen/i', $name)) {
                return 'OTC';
            }
            return 'H';
        }
        if (preg_match('/mouthwash|toothpaste|vitamin|nutraceut|cosmetic|soap/', $th)) {
            return 'OTC';
        }
    }

    // Default prescription if looks like a drug with strength, else OTC
    if (preg_match('/\d+\s*(mg|mcg|ml)\b/i', $name)) {
        return 'H';
    }
    return 'OTC';
}

function inferTheraFromName(string $name, ?string $existing): ?string
{
    if ($existing) {
        return $existing;
    }
    $n = normKey($name);
    $map = [
        'paracetamol|ibuprofen|diclofenac|aceclofenac|nimesulide' => 'Non-Opioid Analgesics',
        'amoxicillin|ampicillin|cloxacillin' => 'Penicillins',
        'cef' => 'Cephalosporins',
        'azithromycin|erythromycin|clarithromycin' => 'Macrolides',
        'ciprofloxacin|ofloxacin|levofloxacin|moxifloxacin|norfloxacin' => 'Quinolones',
        'metronidazole|tinidazole|ornidazole' => 'Antiprotozoal',
        'metformin|glimepiride|gliclazide|sitagliptin|dapagliflozin|insulin' => 'Anti-diabetic',
        'amlodipine|telmisartan|losartan|atenolol|metoprolol|propranolol|ramipril' => 'Antihypertensive',
        'atorvastatin|rosuvastatin|simvastatin|fenofibrate' => 'Lipid Lowering',
        'pantoprazole|rabeprazole|omeprazole|esomeprazole|ranitidine|domperidone' => 'H2 Blockers & Ulcer Healing',
        'cetirizine|levocetirizine|fexofenadine|montelukast|loratadine' => 'Anti-Allergic',
        'alprazolam|diazepam|clonazepam|lorazepam|zolpidem' => 'Sedatives and Tranquillisers',
        'prednisolone|dexamethasone|betamethasone|clobetasol|mometasone|hydrocortisone' => 'Corticosteroids and Related Drugs',
        'fluconazole|itraconazole|ketoconazole|terbinafine|clotrimazole' => 'Antifungal',
        'salbutamol|budesonide|formoterol|theophylline' => 'Respiratory',
        'vitamin|calcium|iron|folic' => 'Nutritional Supplement',
    ];
    foreach ($map as $pat => $cls) {
        if (preg_match('/' . $pat . '/i', $n)) {
            return $cls;
        }
    }
    return null;
}

$stats = [
    'staging_rows' => 0,
    'skipped' => 0,
    'dup_skipped' => 0,
    'inserted' => 0,
    'updated' => 0,
    'enriched' => 0,
    'schedule_fixed' => 0,
    'errors' => 0,
];

// ---- Existing generics index ----
$existing = []; // normKey => row
foreach ($pdo->query('SELECT id, name, therapeutic_class, schedule_category, is_active FROM generics') as $row) {
    $existing[normKey($row['name'])] = $row;
}

$insertStmt = $pdo->prepare(
    'INSERT INTO generics (name, therapeutic_class, schedule_category, is_active, created_at, updated_at)
     VALUES (:name, :thera, :schedule, :active, :created_at, :updated_at)'
);
$updateStmt = $pdo->prepare(
    'UPDATE generics SET
        name = :name,
        therapeutic_class = COALESCE(:thera, therapeutic_class),
        schedule_category = :schedule,
        is_active = :active,
        updated_at = :updated_at
     WHERE id = :id'
);

$upsert = static function (string $name, ?string $thera, string $schedule, int $active) use (
    &$existing, $insertStmt, $updateStmt, $now, $dryRun, &$stats, $pdo
): void {
    $key = normKey($name);
    if ($key === '') {
        $stats['skipped']++;
        return;
    }
    if (isset($existing[$key])) {
        $ex = $existing[$key];
        $sch = (string) $ex['schedule_category'];
        if ($sch === 'OTC' && $schedule !== 'OTC') {
            $sch = $schedule;
            $stats['schedule_fixed']++;
        } elseif ($sch === 'H' && in_array($schedule, ['H1', 'X', 'RESTRICTED'], true)) {
            $sch = $schedule;
            $stats['schedule_fixed']++;
        }
        $curThera = trim((string) ($ex['therapeutic_class'] ?? ''));
        $newThera = $curThera !== '' ? $curThera : $thera;
        if (!$dryRun) {
            $updateStmt->execute([
                'name' => mb_strlen($name) >= mb_strlen((string) $ex['name']) ? $name : $ex['name'],
                'thera' => $newThera ?: null,
                'schedule' => $sch,
                'active' => $active,
                'updated_at' => $now,
                'id' => (int) $ex['id'],
            ]);
        }
        $existing[$key]['therapeutic_class'] = $newThera;
        $existing[$key]['schedule_category'] = $sch;
        $stats['updated']++;
        return;
    }

    if (!$dryRun) {
        $insertStmt->execute([
            'name' => $name,
            'thera' => $thera,
            'schedule' => $schedule,
            'active' => $active,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $existing[$key] = [
            'id' => (int) $pdo->lastInsertId(),
            'name' => $name,
            'therapeutic_class' => $thera,
            'schedule_category' => $schedule,
            'is_active' => $active,
        ];
    } else {
        $existing[$key] = [
            'id' => 0,
            'name' => $name,
            'therapeutic_class' => $thera,
            'schedule_category' => $schedule,
            'is_active' => $active,
        ];
    }
    $stats['inserted']++;
};

echo ($dryRun ? "[DRY-RUN] " : '') . "Importing retail generics...\n";
if (!$dryRun) {
    $pdo->beginTransaction();
}

$seenStaging = [];
$stmt = $pdo->query('SELECT sr, generic, thera, status FROM retail_generic_staging ORDER BY sr ASC');
while ($row = $stmt->fetch()) {
    $stats['staging_rows']++;
    $status = strtoupper(trim((string) ($row['status'] ?? 'Active')));
    if ($status === 'DELETED') {
        $stats['skipped']++;
        continue;
    }
    $name = cleanGenericName((string) ($row['generic'] ?? ''), $spellFixes);
    if ($name === '' || mb_strlen($name) < 3) {
        $stats['skipped']++;
        continue;
    }
    $key = normKey($name);
    if (isset($seenStaging[$key])) {
        $stats['dup_skipped']++;
        continue;
    }
    $seenStaging[$key] = true;

    $thera = cleanThera($row['thera'] ?? null, $theraFixes);
    $thera = inferTheraFromName($name, $thera);
    $schedule = inferSchedule($name, $thera, $scheduleByMolecule);
    $active = in_array($status, ['INACTIVE', '0', 'NO'], true) ? 0 : 1;

    try {
        $upsert($name, $thera, $schedule, $active);
    } catch (Throwable $e) {
        $stats['errors']++;
        if ($stats['errors'] <= 8) {
            fwrite(STDERR, 'ERR: ' . $name . ' — ' . $e->getMessage() . PHP_EOL);
        }
    }

    if (!$dryRun && ($stats['inserted'] + $stats['updated']) % 500 === 0) {
        $pdo->commit();
        $pdo->beginTransaction();
    }
}

if (!$dryRun && $pdo->inTransaction()) {
    $pdo->commit();
}

// ---- Enrich from Indian medicine dataset compositions ----
echo "Enriching from Indian medicine dataset + product compositions...\n";
$compSet = []; // normKey => display name

$addComp = static function (string $raw) use (&$compSet, $spellFixes): void {
    $raw = trim($raw);
    if ($raw === '' || strlen($raw) < 3) {
        return;
    }
    // Split multi-ingredient
    $parts = preg_split('/\s*\|\s*|\s*;\s*/', $raw) ?: [$raw];
    foreach ($parts as $part) {
        $part = trim($part);
        // Strip trailing dose in parentheses sometimes: Amoxycillin (500mg)
        $part = preg_replace('/\s*\(\s*\d+(\.\d+)?\s*(mg|mcg|g|ml|%)\s*\)\s*$/i', '', $part) ?? $part;
        // Keep dose in name if embedded: Amoxycillin 500mg
        $name = cleanGenericName($part, $spellFixes);
        if ($name === '' || mb_strlen($name) < 3) {
            continue;
        }
        // Skip junk
        if (preg_match('/^\d+$/', $name)) {
            continue;
        }
        $compSet[normKey($name)] = $name;
    }
};

if (is_file($csvPath)) {
    $fh = fopen($csvPath, 'rb');
    $header = fgetcsv($fh);
    $c1 = array_search('short_composition1', $header ?: [], true);
    $c2 = array_search('short_composition2', $header ?: [], true);
    if ($c1 === false) {
        $c1 = 7;
        $c2 = 8;
    }
    $n = 0;
    while (($r = fgetcsv($fh)) !== false) {
        if (isset($r[$c1])) {
            $addComp((string) $r[$c1]);
        }
        if ($c2 !== false && isset($r[$c2]) && trim((string) $r[$c2]) !== '') {
            $addComp((string) $r[$c2]);
        }
        // Also combined display
        if (isset($r[$c1], $r[$c2]) && trim((string) $r[$c2]) !== '') {
            $addComp(trim($r[$c1]) . ' + ' . trim($r[$c2]));
        }
        $n++;
        if ($n > 260000) {
            break;
        }
    }
    fclose($fh);
    echo "  composition candidates from dataset: " . count($compSet) . "\n";
}

// Product compositions from master
foreach ($pdo->query("SELECT DISTINCT composition FROM products WHERE composition IS NOT NULL AND TRIM(composition) <> ''") as $row) {
    $addComp((string) $row['composition']);
}

// Vote schedule from products by composition
$prodSchedule = [];
foreach ($pdo->query("SELECT composition, schedule_category, COUNT(*) c FROM products
    WHERE composition IS NOT NULL AND TRIM(composition) <> '' AND schedule_category IS NOT NULL
    GROUP BY composition, schedule_category") as $row) {
    $k = normKey((string) $row['composition']);
    $sch = (string) $row['schedule_category'];
    $c = (int) $row['c'];
    if (!isset($prodSchedule[$k]) || $c > $prodSchedule[$k]['c']) {
        $prodSchedule[$k] = ['schedule' => $sch, 'c' => $c];
    }
}

if (!$dryRun) {
    $pdo->beginTransaction();
}
$enrichCount = 0;
foreach ($compSet as $key => $name) {
        if (isset($existing[$key])) {
        // Still try schedule upgrade from product votes
        if (isset($prodSchedule[$key])) {
            $sch = $prodSchedule[$key]['schedule'];
            $ex = $existing[$key];
            if ($ex['schedule_category'] === 'OTC' && $sch !== 'OTC') {
                try {
                    $upsert($name, null, $sch, 1);
                } catch (Throwable $e) {
                    $stats['errors']++;
                }
            }
        }
        continue;
    }
    $thera = inferTheraFromName($name, null);
    $schedule = $prodSchedule[$key]['schedule'] ?? inferSchedule($name, $thera, $scheduleByMolecule);
    try {
        $upsert($name, $thera, $schedule, 1);
        $stats['enriched']++;
        $enrichCount++;
    } catch (Throwable $e) {
        $stats['errors']++;
    }
    if (!$dryRun && $enrichCount % 500 === 0) {
        $pdo->commit();
        $pdo->beginTransaction();
        echo "  … enriched {$enrichCount}\n";
    }
}

// Pass: fix schedules on all generics still OTC but molecule says otherwise
echo "Re-scoring schedules on existing generics...\n";
$fix = 0;
foreach ($existing as $key => $ex) {
    $name = (string) $ex['name'];
    $thera = $ex['therapeutic_class'] ?? null;
    $inferred = inferSchedule($name, $thera ? (string) $thera : null, $scheduleByMolecule);
    $cur = (string) $ex['schedule_category'];
    $rank = ['OTC' => 0, 'G' => 1, 'H' => 2, 'H1' => 3, 'RESTRICTED' => 4, 'X' => 5];
    if (($rank[$inferred] ?? 0) > ($rank[$cur] ?? 0)) {
        try {
            $upsert($name, $thera ? (string) $thera : null, $inferred, (int) ($ex['is_active'] ?? 1));
            $fix++;
        } catch (Throwable $e) {
            $stats['errors']++;
        }
    } elseif ((trim((string) ($ex['therapeutic_class'] ?? '')) === '') && ($t = inferTheraFromName($name, null))) {
        try {
            $upsert($name, $t, $cur, (int) ($ex['is_active'] ?? 1));
        } catch (Throwable $e) {
            $stats['errors']++;
        }
    }
}

if (!$dryRun && $pdo->inTransaction()) {
    $pdo->commit();
}

echo "\n=== Generics import summary ===\n";
foreach ($stats as $k => $v) {
    echo str_pad($k, 16) . ": {$v}\n";
}
echo "schedule rescored : {$fix}\n";

$total = (int) $pdo->query('SELECT COUNT(*) FROM generics')->fetchColumn();
echo "generics total    : {$total}\n";
$rows = $pdo->query("SELECT schedule_category, COUNT(*) c FROM generics GROUP BY schedule_category ORDER BY FIELD(schedule_category,'OTC','H','H1','X','G','RESTRICTED')")->fetchAll();
echo "\nBy schedule:\n";
foreach ($rows as $r) {
    echo "  {$r['schedule_category']}: {$r['c']}\n";
}
$empty = (int) $pdo->query("SELECT COUNT(*) FROM generics WHERE therapeutic_class IS NULL OR TRIM(therapeutic_class)=''")->fetchColumn();
echo "empty therapeutic : {$empty}\n";

$sample = $pdo->query("SELECT name, therapeutic_class, schedule_category FROM generics ORDER BY id DESC LIMIT 12")->fetchAll();
echo "\nLatest samples:\n";
foreach ($sample as $s) {
    echo sprintf("  %-45s | %-28s | %s\n", mb_substr($s['name'], 0, 45), mb_substr((string) $s['therapeutic_class'], 0, 28), $s['schedule_category']);
}

// Cleanup staging optional
if (!$dryRun) {
    $pdo->exec('DROP TABLE IF EXISTS retail_generic_staging');
}

echo "\nDone.\n";
