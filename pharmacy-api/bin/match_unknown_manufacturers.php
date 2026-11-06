<?php

declare(strict_types=1);

/**
 * Resolve products with manufacturer UNKNOWN by matching brand names
 * against (1) Indian medicine open dataset and (2) known products in DB.
 *
 * Usage: php bin/match_unknown_manufacturers.php [--dry-run]
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Support\Env;
use Dotenv\Dotenv;

$configDir = dirname(__DIR__) . '/config';
if (is_file($configDir . '/.env')) {
    Dotenv::createImmutable($configDir, '.env')->safeLoad();
}

$dryRun = in_array('--dry-run', $argv ?? [], true);

$host = Env::get('DB_HOST', '127.0.0.1');
$port = (int) Env::get('DB_PORT', 3306);
$user = Env::get('DB_USER', 'root');
$pass = Env::get('DB_PASS', '');
$db   = Env::get('DB_MASTER', 'pharma_master');

$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
    $user,
    $pass,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]
);

$now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
$csvPath = dirname(__DIR__) . '/storage/indian_medicine_data.csv';
if (!is_file($csvPath)) {
    fwrite(STDERR, "Missing dataset: {$csvPath}\n");
    exit(1);
}

function normName(string $s): string
{
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = strtoupper(trim($s));
    $s = str_replace(['%', '=', '_'], ' ', $s);
    $s = preg_replace('/[^A-Z0-9.+\-\/\s]/', ' ', $s) ?? $s;
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    return trim($s);
}

/** Strip dosage / form noise → brand-ish key */
function brandKeys(string $name): array
{
    $n = normName($name);
    if ($n === '') {
        return [];
    }

    // Remove common dosage / form tokens
    $stripped = preg_replace(
        '/\b(\d+(\.\d+)?\s*(MG|MCG|GM|G|ML|IU|%|MCG|µg)?|TAB(?:LET)?S?|CAP(?:SULE)?S?|SYP|SYRUP|INJ(?:ECTION)?|DROPS?|CREAM|OINT(?:MENT)?|GEL|LOTION|SPRAY|SUSP(?:ENSION)?|SOLUTION|POWDER|SACHET|VIAL|AMPOULE|RESPULES?|DT|SR|ER|XR|MR|CR|FORTE|PLUS|KID|DS|OD|BD|MD|SOFTGEL|EYE|EAR|NASAL|ORAL|PAEDIATRIC|PEDIATRIC|ADULT)\b/u',
        ' ',
        $n
    ) ?? $n;
    $stripped = preg_replace('/\s+/', ' ', trim($stripped)) ?? '';

    $keys = [];
    $keys[] = $n; // full
    if ($stripped !== '' && $stripped !== $n) {
        $keys[] = $stripped;
    }

    // Leading alpha brand (AUGMENTIN from AUGMENTIN 625...)
    if (preg_match('/^([A-Z][A-Z0-9.+-]{2,})/u', $n, $m)) {
        $keys[] = $m[1];
    }
    if ($stripped !== '' && preg_match('/^([A-Z][A-Z0-9.+-]{2,}(?:\s+[A-Z][A-Z0-9.+-]{1,})?)/u', $stripped, $m2)) {
        $keys[] = trim($m2[1]);
    }

    // First token / first two tokens
    $parts = preg_split('/\s+/', $stripped !== '' ? $stripped : $n) ?: [];
    if (!empty($parts[0]) && strlen($parts[0]) >= 3) {
        $keys[] = $parts[0];
    }
    if (count($parts) >= 2 && strlen($parts[0]) >= 3) {
        $keys[] = $parts[0] . ' ' . $parts[1];
    }

    $keys = array_values(array_unique(array_filter($keys, static fn ($k) => strlen($k) >= 3)));
    // Prefer longer keys first
    usort($keys, static fn ($a, $b) => strlen($b) <=> strlen($a));
    return $keys;
}

function mfgKey(string $name): string
{
    $s = strtolower(trim($name));
    $s = preg_replace('/\b(pvt|private|ltd|limited|labs?|laboratories|pharma(?:ceuticals?)?|healthcare|india|inc|llc|biotech|remedies|lifesciences?|drugs?|industries|company|co)\b/u', '', $s) ?? $s;
    $s = preg_replace('/[^a-z0-9]+/u', '', $s) ?? $s;
    return $s === '' ? '' : ('m:' . $s);
}

function generateSCode(string $name, array &$used): string
{
    $clean = preg_replace('/\b(PVT|PRIVATE|LTD|LIMITED|LABS?|LABORATORIES|PHARMA(?:CEUTICALS?)?|HEALTHCARE|INDIA|INC|LLC|INDUSTRIES)\b/i', '', $name) ?? $name;
    $words = preg_split('/\s+/', trim($clean)) ?: [];
    $letters = '';
    foreach ($words as $w) {
        $w = preg_replace('/[^A-Za-z0-9]/', '', $w) ?? '';
        if ($w === '') {
            continue;
        }
        $letters .= strtoupper(substr($w, 0, 3));
        if (strlen($letters) >= 3) {
            break;
        }
    }
    if ($letters === '') {
        $letters = 'MFG';
    }
    $base = substr($letters, 0, 3);
    $code = $base;
    $n = 2;
    while (isset($used[$code])) {
        $code = substr($base, 0, 2) . $n;
        $n++;
        if ($n > 99) {
            $code = strtoupper(substr(md5($name), 0, 3));
            break;
        }
    }
    $used[$code] = true;
    return $code;
}

// ---- Load manufacturers ----
$mfgByKey = [];
$usedCodes = [];
foreach ($pdo->query('SELECT id, name, s_code FROM manufacturers') as $row) {
    $k = mfgKey($row['name']);
    if ($k !== '') {
        $mfgByKey[$k] = $row;
    }
    $usedCodes[strtoupper((string) $row['s_code'])] = true;
}

$resolveMfg = static function (string $rawName) use (&$mfgByKey, &$usedCodes, $pdo, $now, $dryRun): ?array {
    $rawName = trim(preg_replace('/\s+/', ' ', $rawName) ?? $rawName);
    if ($rawName === '' || strcasecmp($rawName, 'UNKNOWN') === 0) {
        return null;
    }
    if (mb_strlen($rawName) > 150) {
        $rawName = mb_substr($rawName, 0, 150);
    }
    $k = mfgKey($rawName);
    if ($k !== '' && isset($mfgByKey[$k])) {
        return $mfgByKey[$k];
    }
    // stem match
    $stem = substr($k, 2);
    if (strlen($stem) >= 5) {
        foreach ($mfgByKey as $ek => $m) {
            $other = substr((string) $ek, 2);
            if (strlen($other) < 5) {
                continue;
            }
            if (substr($stem, 0, 6) === substr($other, 0, 6)
                || str_starts_with($stem, $other)
                || str_starts_with($other, $stem)) {
                return $m;
            }
        }
    }
    $code = generateSCode($rawName, $usedCodes);
    if (!$dryRun) {
        try {
            $st = $pdo->prepare('INSERT INTO manufacturers (name, s_code, is_active, created_at, updated_at) VALUES (:n,:c,1,:a,:u)');
            $st->execute(['n' => $rawName, 'c' => $code, 'a' => $now, 'u' => $now]);
            $row = ['id' => (int) $pdo->lastInsertId(), 'name' => $rawName, 's_code' => $code];
        } catch (Throwable $e) {
            $q = $pdo->prepare('SELECT id, name, s_code FROM manufacturers WHERE name = :n OR s_code = :c LIMIT 1');
            $q->execute(['n' => $rawName, 'c' => $code]);
            $row = $q->fetch() ?: null;
            if (!$row) {
                return null;
            }
        }
    } else {
        $row = ['id' => 0, 'name' => $rawName, 's_code' => $code];
    }
    if ($k !== '') {
        $mfgByKey[$k] = $row;
    }
    return $row;
};

/**
 * brandKey => [ manufacturer_name => votes ]
 * @var array<string, array<string,int>>
 */
$brandVotes = [];
/** exact normalised name => manufacturer_name */
$exactMap = [];

$addVote = static function (string $brand, string $mfg) use (&$brandVotes): void {
    $brand = normName($brand);
    $mfg = trim($mfg);
    if (strlen($brand) < 3 || $mfg === '') {
        return;
    }
    // Skip ultra-generic / ambiguous stems that collide across companies
    static $block = [
        'PARACETAMOL' => 1, 'AMOXYCILLIN' => 1, 'AMOXICILLIN' => 1, 'CETIRIZINE' => 1,
        'OMEPRAZOLE' => 1, 'PANTOPRAZOLE' => 1, 'METFORMIN' => 1, 'ATORVASTATIN' => 1,
        'DICLOFENAC' => 1, 'IBUPROFEN' => 1, 'ASPIRIN' => 1, 'VITAMIN' => 1,
        'CALCIUM' => 1, 'IRON' => 1, 'MULTIVITAMIN' => 1, 'ORS' => 1,
        'FAIR' => 1, 'GOOD' => 1, 'HOT' => 1, 'PURE' => 1, 'SUPER' => 1, 'NEW' => 1,
        'BEST' => 1, 'GOLD' => 1, 'PLUS' => 1, 'CARE' => 1, 'LIFE' => 1, 'HEALTH' => 1,
        'BABY' => 1, 'KIDS' => 1, 'ALMOND' => 1, 'COCONUT' => 1, 'ROSE' => 1,
        'WATER' => 1, 'OIL' => 1, 'SOAP' => 1, 'CREAM' => 1, 'GEL' => 1,
    ];
    if (isset($block[$brand])) {
        return;
    }
    if (preg_match('/^\*+$/', $mfg) || strlen($mfg) < 3) {
        return;
    }
    // Prefix prevents PHP casting numeric manufacturer names to int keys
    $mk = 'c:' . $mfg;
    $brandVotes[$brand][$mk] = ($brandVotes[$brand][$mk] ?? 0) + 1;
};

// High-confidence brand → manufacturer overrides (Indian retail common brands)
$brandOverrides = [
    'AUGMENTIN' => 'GlaxoSmithKline',
    'AZITHRAL' => 'Alembic Pharmaceuticals',
    'ASTHALIN' => 'Cipla',
    'ALMOX' => 'Alkem Laboratories',
    'CROCIN' => 'GlaxoSmithKline',
    'DOLO' => 'Micro Labs',
    'COMBIFLAM' => 'Sanofi India',
    'VOVERAN' => 'Novartis India',
    'PANTOCID' => 'Sun Pharma',
    'PANTODAC' => 'Zydus Cadila',
    'TAXIM' => 'Alkem Laboratories',
    'MONOCEF' => 'Aristo Pharma',
    'MEFTAL' => 'Blue Cross',
    'SHELCAL' => 'Torrent Pharmaceuticals',
    'REVITAL' => 'Sun Pharma',
    'LIV 52' => 'Himalaya',
    'LIV52' => 'Himalaya',
    'HIMALAYA' => 'Himalaya',
    'PARACHUTE' => 'Marico',
    'SENSODYNE' => 'GlaxoSmithKline',
    'COLGATE' => 'Colgate-Palmolive',
    'PEPSODENT' => 'Hindustan Unilever',
    'DOVE' => 'Hindustan Unilever',
    'PEARS' => 'Hindustan Unilever',
    'DETTOL' => 'Reckitt Benckiser',
    'DETTOL' => 'Reckitt Benckiser',
    'VEET' => 'Reckitt Benckiser',
    'HARPIC' => 'Reckitt Benckiser',
    'GOOD KNIGHT' => 'Godrej',
    'GOODNIGHT' => 'Godrej',
    'GOOD NIGHT' => 'Godrej',
    'CALPOL' => 'GlaxoSmithKline',
    'ATARAX' => 'Dr. Reddy\'s',
    'ALKOF' => 'Alkem Laboratories',
    'TOREX' => 'Torque Pharmaceuticals',
    'MOXIFORCE' => 'Mankind Pharma',
    'RABIFAST' => 'Zuventus',
    'ACILOC' => 'Cadila Pharmaceuticals',
    'VITCOFOL' => 'FDC',
    'KANCIN' => 'Alembic Pharmaceuticals',
    'UACE' => 'Unison Pharmaceuticals',
    'TELMIKIND' => 'Mankind Pharma',
    'AMLOKIND' => 'Mankind Pharma',
    'NIMEKIND' => 'Mankind Pharma',
    'OMNIKIND' => 'Mankind Pharma',
    'ATORKIND' => 'Mankind Pharma',
    'PANTIKIND' => 'Mankind Pharma',
    'RABEKIND' => 'Mankind Pharma',
    'MOXIKIND' => 'Mankind Pharma',
    'GUDCEF' => 'Mankind Pharma',
    'UNIENZYME' => 'Unichem Laboratories',
    'COLGATE' => 'Colgate-Palmolive',
    'COLG' => 'Colgate-Palmolive',
    'MAMY POKO' => 'Unicharm',
    'MAMYPOKO' => 'Unicharm',
    'HIMALAYA' => 'Himalaya',
    'AVIPATTIKAR' => 'Baidyanath',

    'SANTOOR' => 'Wipro Consumer Care',
    'GODREJ NUPUR' => 'Godrej',
    'GODREJ' => 'Godrej',
    'FAIR LOVELY' => 'Hindustan Unilever',
    'GLOW LOVELY' => 'Hindustan Unilever',
    'BOROLINE' => 'G.D. Pharmaceuticals',
    'MOOV' => 'Reckitt Benckiser',
    'IODEX' => 'GlaxoSmithKline',
    'VOLINI' => 'Sun Pharma',
    'ZANDU' => 'Emami',
    'DABUR' => 'Dabur',
    'PATANJALI' => 'Patanjali Ayurved',
    'ENO' => 'GlaxoSmithKline',
    'GELUSIL' => 'Pfizer',
    'DIGENE' => 'Abbott India',
    'ELECTRAL' => 'FDC',
    'ORS-L' => 'Cipla',
    'CIPLOX' => 'Cipla',
    'FORACORT' => 'Cipla',
    'BUDECORT' => 'Cipla',
    'MONTAIR' => 'Cipla',
    'ATORVA' => 'Zydus Cadila',
    'ROSUVAS' => 'Sun Pharma',
    'TELMIRIDE' => 'Cipla',
    'AMLOKIND' => 'Mankind Pharma',
    'TELMA' => 'Glenmark',
    'STORVAS' => 'Sun Pharma',
    'GLYCOMET' => 'USV',
    'JANUMET' => 'MSD Pharmaceuticals',
    'HUMAN MIXTARD' => 'Novo Nordisk',
    'LANTUS' => 'Sanofi India',
    'BETADINE' => 'Win-Medicare',
    'SOFRAMYCIN' => 'Sanofi India',
    'CANDID' => 'Glenmark',
    'RINGWORM' => 'Glenmark',
];

echo "Loading Indian medicine dataset...\n";
$fh = fopen($csvPath, 'rb');
if (!$fh) {
    fwrite(STDERR, "Cannot open CSV\n");
    exit(1);
}
$header = fgetcsv($fh);
// Find columns
$colName = array_search('name', $header ?: [], true);
$colMfg = array_search('manufacturer_name', $header ?: [], true);
if ($colName === false || $colMfg === false) {
    // fallback positional: id,name,...,manufacturer_name at index 4
    $colName = 1;
    $colMfg = 4;
}
$csvRows = 0;
while (($row = fgetcsv($fh)) !== false) {
    if (!isset($row[$colName], $row[$colMfg])) {
        continue;
    }
    $med = trim((string) $row[$colName]);
    $mfg = trim((string) $row[$colMfg]);
    if ($med === '' || $mfg === '') {
        continue;
    }
    $exactMap[normName($med)] = $mfg;
    foreach (brandKeys($med) as $bk) {
        // Only vote with keys length >= 4 to reduce noise; full name always voted via exactMap
        if (strlen($bk) >= 4) {
            $addVote($bk, $mfg);
        }
    }
    $csvRows++;
    if ($csvRows % 100000 === 0) {
        echo "  … {$csvRows} dataset rows\n";
    }
}
fclose($fh);
echo "Dataset rows: {$csvRows}, exact keys: " . count($exactMap) . ", brand keys: " . count($brandVotes) . "\n";

echo "Adding votes from known DB products...\n";
$dbVotes = 0;
$q = $pdo->query("SELECT name, manufacturer FROM products
    WHERE manufacturer IS NOT NULL AND manufacturer NOT IN ('UNKNOWN','U LABS','')
      AND manufacturer_short NOT IN ('UNK','U','')");
foreach ($q as $row) {
    $mfg = (string) $row['manufacturer'];
    $exactMap[normName((string) $row['name'])] = $mfg;
    foreach (brandKeys((string) $row['name']) as $bk) {
        if (strlen($bk) >= 4) {
            $addVote($bk, $mfg);
            $dbVotes++;
        }
    }
}
echo "DB brand votes added: {$dbVotes}\n";

/** Pick best manufacturer for a brand key */
$pickMfg = static function (string $brand) use (&$brandVotes): ?string {
    if (!isset($brandVotes[$brand])) {
        return null;
    }
    $votes = $brandVotes[$brand];
    arsort($votes);
    $topKey = (string) array_key_first($votes);
    $top = (int) $votes[$topKey];
    $second = 0;
    $i = 0;
    foreach ($votes as $v) {
        if ($i === 1) {
            $second = (int) $v;
            break;
        }
        $i++;
    }
    $mfgName = str_starts_with($topKey, 'c:') ? substr($topKey, 2) : $topKey;
    if (preg_match('/^\*+$/', $mfgName) || strlen($mfgName) < 3) {
        return null;
    }
    // Require clear winner
    if ($top < 1) {
        return null;
    }
    $isMultiWord = str_contains($brand, ' ');
    $uniqueMfg = count($votes) === 1;
    // Unique brand→company in the Indian dataset: allow single vote if brand looks real
    if ($uniqueMfg && strlen($brand) >= 5) {
        $minVotes = 1;
    } else {
        $minVotes = $isMultiWord ? 1 : (strlen($brand) >= 7 ? 2 : 4);
    }
    if ($top < $minVotes) {
        return null;
    }
    // Ambiguous if runner-up is close
    if (!$uniqueMfg && $second > 0 && $top < ($second * 2)) {
        return null;
    }
    return $mfgName;
};

$stats = [
    'unknown'   => 0,
    'matched'   => 0,
    'exact'     => 0,
    'brand'     => 0,
    'unmatched' => 0,
    'updated'   => 0,
    'mfg_new'   => 0,
];

$upd = $pdo->prepare('UPDATE products SET manufacturer = :m, manufacturer_short = :c, updated_at = :u WHERE id = :id');

echo ($dryRun ? "[DRY-RUN] " : '') . "Matching UNKNOWN products...\n";
if (!$dryRun) {
    $pdo->beginTransaction();
}

$stmt = $pdo->query("SELECT id, name FROM products
    WHERE manufacturer_short = 'UNK' OR manufacturer = 'UNKNOWN'
    ORDER BY id ASC");

$batch = 0;
$samples = [];

while ($row = $stmt->fetch()) {
    $stats['unknown']++;
    $name = (string) $row['name'];
    $mfgName = null;
    $how = '';

    $exact = normName($name);
    // Curated overrides first (highest confidence)
    foreach (brandKeys($name) as $bk) {
        $bkN = normName($bk);
        if (isset($brandOverrides[$bkN])) {
            $mfgName = $brandOverrides[$bkN];
            $how = 'override:' . $bkN;
            $stats['brand']++;
            break;
        }
        // Mankind family: *KIND brands
        if (preg_match('/^[A-Z]{3,}KIND\b/', $bkN) || str_ends_with($bkN, 'KIND')) {
            $mfgName = 'Mankind Pharma';
            $how = 'override:KIND';
            $stats['brand']++;
            break;
        }
    }
    if (!$mfgName && isset($exactMap[$exact]) && !preg_match('/^\*+$/', (string) $exactMap[$exact])) {
        $mfgName = $exactMap[$exact];
        $how = 'exact';
        $stats['exact']++;
    }
    if (!$mfgName) {
        foreach (brandKeys($name) as $bk) {
            if (strlen($bk) < 4) {
                continue;
            }
            $cand = null;
            // exactMap hit only for reasonably long brand keys (avoid FAIR, OIL…)
            if (strlen($bk) >= 6 && isset($exactMap[$bk]) && !preg_match('/^\*+$/', (string) $exactMap[$bk])) {
                $cand = $exactMap[$bk];
            } else {
                $cand = $pickMfg($bk);
            }
            if ($cand && !preg_match('/^\*+$/', $cand)) {
                $mfgName = $cand;
                $how = 'brand:' . $bk;
                $stats['brand']++;
                break;
            }
        }
    }

    if (!$mfgName || preg_match('/^\*+$/', $mfgName)) {
        $stats['unmatched']++;
        continue;
    }

    $beforeMfgCount = count($mfgByKey);
    $mfg = $resolveMfg($mfgName);
    if (!$mfg) {
        $stats['unmatched']++;
        continue;
    }
    if (count($mfgByKey) > $beforeMfgCount) {
        $stats['mfg_new']++;
    }

    $stats['matched']++;
    if (count($samples) < 20) {
        $samples[] = sprintf('%-32s → %-28s (%s) [%s]', mb_substr($name, 0, 32), mb_substr($mfg['name'], 0, 28), $mfg['s_code'], $how);
    }

    if (!$dryRun) {
        $upd->execute([
            'm'  => $mfg['name'],
            'c'  => strtoupper(substr((string) $mfg['s_code'], 0, 20)),
            'u'  => $now,
            'id' => (int) $row['id'],
        ]);
        $stats['updated']++;
    } else {
        $stats['updated']++;
    }

    $batch++;
    if (!$dryRun && $batch % 1000 === 0) {
        $pdo->commit();
        $pdo->beginTransaction();
        echo "  … matched {$stats['matched']} / scanned {$stats['unknown']}\n";
    }
}

if (!$dryRun && $pdo->inTransaction()) {
    $pdo->commit();
}

echo "\n=== Match summary ===\n";
foreach ($stats as $k => $v) {
    echo str_pad($k, 12) . ": {$v}\n";
}
echo "\nSamples:\n";
foreach ($samples as $s) {
    echo "  {$s}\n";
}

$left = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE manufacturer_short='UNK' OR manufacturer='UNKNOWN'")->fetchColumn();
echo "\nRemaining UNKNOWN: {$left}\n";
echo "Done.\n";
