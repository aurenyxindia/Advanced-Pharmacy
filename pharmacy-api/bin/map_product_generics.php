<?php

declare(strict_types=1);

/**
 * Map products.generic_id → generics.id using composition / scientific name.
 * Also clears obviously wrong strength-only links.
 *
 * Usage: php bin/map_product_generics.php [--dry-run]
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

function norm(string $s): string
{
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = mb_strtolower(trim($s));
    $s = str_replace(['%', '=', '_'], ' ', $s);
    $s = preg_replace('/[^a-z0-9+.\-\/\s]/u', ' ', $s) ?? $s;
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    return trim($s);
}

function compactKey(string $s): string
{
    return preg_replace('/[^a-z0-9]+/u', '', norm($s)) ?? '';
}

/** Scientific cleanup for matching */
function cleanComp(string $raw): string
{
    $s = norm($raw);
    $s = preg_replace('/\bamoxycillin\b/', 'amoxicillin', $s) ?? $s;
    $s = preg_replace('/\bpropanolol\b/', 'propranolol', $s) ?? $s;
    $s = preg_replace('/\bamlodepine\b/', 'amlodipine', $s) ?? $s;
    $s = preg_replace('/\bmetorpolol\b/', 'metoprolol', $s) ?? $s;
    $s = preg_replace('/\bpottassium\b|\bpotassium\b/', 'potassium', $s) ?? $s;
    $s = preg_replace('/\bcalvunic\b|\bclavunic\b|\bclavulanate\b/', 'clavulanic', $s) ?? $s;
    $s = preg_replace('/\bhcl\b/', 'hcl', $s) ?? $s;
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    return trim($s);
}

function isJunkComp(string $c): bool
{
    $c = norm($c);
    if ($c === '' || strlen($c) < 3) {
        return true;
    }
    static $junk = [
        'generic' => 1, 'condo' => 1, 'condom' => 1, 'antacid' => 1,
        'na' => 1, 'n/a' => 1, 'nil' => 1, 'none' => 1, '-' => 1,
        'surgical' => 1, 'general' => 1, 'ayurvedic' => 1,
    ];
    return isset($junk[$c]);
}

/** Seed/demo junk compositions that must not drive mapping */
function isBogusComp(string $c): bool
{
    $c = norm($c);
    if (isJunkComp($c)) {
        return true;
    }
    return (bool) preg_match(
        '/\b(sunscreen|spf\d*|moisturiz|lotion standard|cream standard|shampoo standard|face wash|toothpaste standard|cosmetic|hair oil standard)\b/i',
        $c
    );
}

function isBogusGenericName(string $name): bool
{
    return isBogusComp($name);
}

/** Composition looks like a real drug formula */
function isCredibleComp(string $c): bool
{
    if (isBogusComp($c)) {
        return false;
    }
    // Has strength or multi-salt or known long molecule token
    if (preg_match('/\d+\s*(mg|mcg|g|ml|iu|%)/i', $c)) {
        return true;
    }
    $toks = molTokens($c);
    return count($toks) >= 1 && strlen($toks[0]) >= 5;
}

/**
 * Molecule tokens suitable for overlap checks (skip forms/strengths).
 * @return list<string>
 */
function molTokens(string $s): array
{
    $s = cleanComp($s);
    $s = preg_replace(
        '/\b(\d+(\.\d+)?\s*(mg|mcg|g|ml|iu|%|w\/v|units?)|tablet|tablets|capsule|capsules|syrup|injection|inj|drops?|cream|ointment|gel|suspension|solution|powder|sachet|oral|softgel|strip|bottle|vial|ampoule|dt|sr|er|xr|cr|mr|forte|plus|kid|ds|od)\b/u',
        ' ',
        $s
    ) ?? $s;
    $s = preg_replace('/\s+/', ' ', trim($s)) ?? '';
    $parts = preg_split('/\s*\+\s*|\s*,\s*|\//', $s) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') {
            continue;
        }
        if (preg_match_all('/[a-z][a-z]{3,}/', $p, $m)) {
            foreach ($m[0] as $w) {
                if (in_array($w, ['acid', 'sodium', 'potassium', 'calcium', 'hydrate', 'anhydrous', 'dihydrate', 'monohydrate', 'hydrochloride', 'sulphate', 'sulfate', 'phosphate', 'maleate', 'succinate', 'tartrate', 'acetate', 'with', 'and', 'the'], true)) {
                    continue;
                }
                $out[] = $w;
            }
        }
    }
    return array_values(array_unique($out));
}

function strengthKey(string $s): ?string
{
    if (preg_match_all('/(\d+(?:\.\d+)?)\s*(mg|mcg|g|ml|iu)\b/i', $s, $m)) {
        $bits = [];
        foreach ($m[1] as $i => $num) {
            $bits[] = ((float) $num) . strtolower($m[2][$i]);
        }
        sort($bits);
        return implode('+', $bits);
    }
    return null;
}

function tokenOverlap(array $a, array $b): int
{
    if (!$a || !$b) {
        return 0;
    }
    return count(array_intersect($a, $b));
}

$stats = [
    'products' => 0,
    'already_ok' => 0,
    'cleared_bad' => 0,
    'mapped_exact' => 0,
    'mapped_comp' => 0,
    'mapped_brand' => 0,
    'mapped_fuzzy' => 0,
    'unmapped' => 0,
    'updated' => 0,
];

echo "Loading generics index...\n";
/** @var array<string, array{id:int,name:string,tokens:array,strength:?string,compact:string}> */
$byExact = [];
$byCompact = [];
/** molecule primary token => list of generic ids (for fuzzy) */
$byMol = [];

foreach ($pdo->query('SELECT id, name FROM generics WHERE is_active = 1') as $g) {
    $id = (int) $g['id'];
    $name = (string) $g['name'];
    $n = cleanComp($name);
    $c = compactKey($name);
    $tokens = molTokens($name);
    $str = strengthKey($name);
    $row = [
        'id' => $id,
        'name' => $name,
        'tokens' => $tokens,
        'strength' => $str,
        'compact' => $c,
        'norm' => $n,
    ];
    $byExact[$n] = $row;
    if ($c !== '') {
        $byCompact[$c] = $row;
    }
    if (!empty($tokens[0])) {
        $byMol[$tokens[0]][] = $row;
    }
}
echo '  generics indexed: ' . count($byExact) . "\n";

// Brand → composition from Indian dataset (for products lacking composition)
$brandComp = [];
if (is_file($csvPath)) {
    echo "Loading brand→composition from dataset...\n";
    $fh = fopen($csvPath, 'rb');
    $header = fgetcsv($fh);
    $ciName = array_search('name', $header ?: [], true);
    $ciC1 = array_search('short_composition1', $header ?: [], true);
    $ciC2 = array_search('short_composition2', $header ?: [], true);
    if ($ciName === false) {
        $ciName = 1;
        $ciC1 = 7;
        $ciC2 = 8;
    }
    $n = 0;
    while (($r = fgetcsv($fh)) !== false) {
        $b = norm((string) ($r[$ciName] ?? ''));
        if ($b === '') {
            continue;
        }
        $c1 = trim((string) ($r[$ciC1] ?? ''));
        $c2 = trim((string) ($r[$ciC2] ?? ''));
        $comp = $c1;
        if ($c2 !== '') {
            $comp = $c1 . ' + ' . $c2;
        }
        if ($comp !== '') {
            // Keep first seen; brands are fairly unique in dataset
            if (!isset($brandComp[$b])) {
                $brandComp[$b] = $comp;
            }
            // Also first two words key
            $parts = explode(' ', $b);
            if (count($parts) >= 2) {
                $k2 = $parts[0] . ' ' . $parts[1];
                if (!isset($brandComp[$k2])) {
                    $brandComp[$k2] = $comp;
                }
            }
            // Only long unambiguous brand stems as single-token keys
            if (!isset($brandComp[$parts[0]]) && strlen($parts[0]) >= 6) {
                $brandComp[$parts[0]] = $comp;
            }
        }
        $n++;
        if ($n >= 260000) {
            break;
        }
    }
    fclose($fh);
    echo '  brand keys: ' . count($brandComp) . "\n";
}

/**
 * Find best generic id for a composition / cleaned text.
 */
$findGeneric = static function (string $compText) use (&$byExact, &$byCompact, &$byMol): ?array {
    $compText = trim($compText);
    if ($compText === '' || isJunkComp($compText) || isBogusComp($compText)) {
        return null;
    }
    $n = cleanComp($compText);
    if (isset($byExact[$n]) && !isBogusGenericName($byExact[$n]['name'])) {
        return ['row' => $byExact[$n], 'how' => 'exact'];
    }
    $c = compactKey($compText);
    if ($c !== '' && isset($byCompact[$c]) && !isBogusGenericName($byCompact[$c]['name'])) {
        return ['row' => $byCompact[$c], 'how' => 'compact'];
    }

    // Try without form words already cleaned — strip trailing "tablet" etc already in clean
    // Strength-aware molecule match
    $tokens = molTokens($compText);
    $str = strengthKey($compText);
    if (!$tokens) {
        return null;
    }

    $primary = $tokens[0];
    $candidates = $byMol[$primary] ?? [];
    // Also search other tokens as primary
    foreach (array_slice($tokens, 1, 3) as $t) {
        if (isset($byMol[$t])) {
            $candidates = array_merge($candidates, $byMol[$t]);
        }
    }

    $best = null;
    $bestScore = 0;
    $seen = [];
    foreach ($candidates as $cand) {
        $cid = $cand['id'];
        if (isset($seen[$cid])) {
            continue;
        }
        $seen[$cid] = true;
        $overlap = tokenOverlap($tokens, $cand['tokens']);
        if ($overlap < 1) {
            continue;
        }
        // Require primary molecule present in candidate
        if (!in_array($primary, $cand['tokens'], true) && tokenOverlap([$primary], $cand['tokens']) < 1) {
            // allow if any strong token matches
            if ($overlap < 1) {
                continue;
            }
        }
        $score = $overlap * 10;
        if ($str && $cand['strength'] && $str === $cand['strength']) {
            $score += 20;
        } elseif ($str && $cand['strength']) {
            // partial strength match
            $score += 2;
        }
        // Prefer similar token count (same combo complexity)
        $score -= abs(count($tokens) - count($cand['tokens']));
        // Prefer shorter name distance
        similar_text($n, $cand['norm'], $pct);
        $score += (int) ($pct / 10);

        if (isBogusGenericName($cand['name'])) {
            continue;
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $cand;
        }
    }

    // Threshold: need molecule overlap + (strength match OR high similarity)
    if ($best && $bestScore >= 18) {
        return ['row' => $best, 'how' => 'fuzzy:' . $bestScore];
    }
    return null;
};

$linkOk = static function (?int $gid, string $comp, string $pname, array $genericsById): bool {
    if (!$gid || !isset($genericsById[$gid])) {
        return false;
    }
    $gname = $genericsById[$gid];
    if (isBogusGenericName($gname)) {
        return false;
    }
    $src = isCredibleComp($comp) ? $comp : $pname;
    $pt = molTokens($src);
    $gt = molTokens($gname);
    if (!$pt || !$gt) {
        return false;
    }
    return tokenOverlap($pt, $gt) >= 1;
};

// Load generic id→name
$genericsById = [];
foreach ($byExact as $row) {
    $genericsById[$row['id']] = $row['name'];
}

$upd = $pdo->prepare('UPDATE products SET generic_id = :gid, updated_at = :u WHERE id = :id');
$updWithComp = $pdo->prepare('UPDATE products SET generic_id = :gid, composition = :comp, updated_at = :u WHERE id = :id');
$clear = $pdo->prepare('UPDATE products SET generic_id = NULL, updated_at = :u WHERE id = :id');

echo ($dryRun ? "[DRY-RUN] " : '') . "Mapping products...\n";
if (!$dryRun) {
    $pdo->beginTransaction();
}

$stmt = $pdo->query('SELECT id, name, composition, generic_id FROM products ORDER BY id ASC');
$batch = 0;

while ($p = $stmt->fetch()) {
    $stats['products']++;
    $id = (int) $p['id'];
    $pname = (string) $p['name'];
    $comp = trim((string) ($p['composition'] ?? ''));
    $curGid = $p['generic_id'] !== null ? (int) $p['generic_id'] : null;

    $matched = null;
    $how = '';

    $resolveBrandComp = static function (string $pname) use (&$brandComp): ?string {
        if (!$brandComp) {
            return null;
        }
        $bn = norm($pname);
        if (isset($brandComp[$bn])) {
            return $brandComp[$bn];
        }
        $parts = explode(' ', $bn);
        if (count($parts) >= 2) {
            $k2 = $parts[0] . ' ' . $parts[1];
            if (isset($brandComp[$k2])) {
                return $brandComp[$k2];
            }
        }
        // Strip trailing strength tokens from brand for lookup: "azee 500" → try dataset keys
        $stem = preg_replace('/\b\d+(\.\d+)?\s*(mg|mcg|ml|g|iu)?\b/i', '', $bn) ?? $bn;
        $stem = trim(preg_replace('/\b(tab|tablet|cap|capsule|syrup|susp|inj|cream|gel|drop|ointment|syp)\w*\b/i', '', $stem) ?? $stem);
        $stem = trim(preg_replace('/\s+/', ' ', $stem) ?? $stem);
        if ($stem !== '' && isset($brandComp[$stem])) {
            return $brandComp[$stem];
        }
        $sp = explode(' ', $stem);
        if (isset($sp[0]) && strlen($sp[0]) >= 6 && isset($brandComp[$sp[0]])) {
            return $brandComp[$sp[0]];
        }
        if (count($sp) >= 2) {
            $k2 = $sp[0] . ' ' . $sp[1];
            if (isset($brandComp[$k2])) {
                return $brandComp[$k2];
            }
        }
        return null;
    };

    $compFromBrand = $resolveBrandComp($pname);
    $brandConflicts = false;
    if ($compFromBrand && isCredibleComp($comp)) {
        // Seed data often pairs real brand names with wrong salts — trust brand dataset
        if (tokenOverlap(molTokens($comp), molTokens($compFromBrand)) < 1) {
            $brandConflicts = true;
        }
    }

    // 1) Product composition only when it agrees with brand (or brand unknown)
    if (!$brandConflicts && isCredibleComp($comp)) {
        $hit = $findGeneric($comp);
        if ($hit) {
            $matched = $hit['row'];
            $how = 'comp:' . $hit['how'];
        }
    }

    // 2) Brand → composition (also when product composition conflicts)
    if (!$matched && $compFromBrand && !isBogusComp($compFromBrand)) {
        $hit = $findGeneric($compFromBrand);
        if ($hit) {
            $matched = $hit['row'];
            $how = 'brand:' . $hit['how'];
        }
    }

    // 3) Product name equals a generic/salt name
    if (!$matched && isCredibleComp($pname)) {
        $hit = $findGeneric($pname);
        if ($hit && ($hit['how'] === 'exact' || $hit['how'] === 'compact')) {
            $matched = $hit['row'];
            $how = 'name:' . $hit['how'];
        } elseif ($hit && str_starts_with($hit['how'], 'fuzzy:')) {
            $score = (int) substr($hit['how'], 6);
            if ($score >= 25) {
                $matched = $hit['row'];
                $how = 'name:' . $hit['how'];
            }
        }
    }

    if ($matched) {
        $newGid = (int) $matched['id'];
        $fixComp = null;
        if (str_starts_with($how, 'brand:') && ($brandConflicts || isBogusComp($comp) || $comp === '')) {
            // Store real salt text when composition was junk or contradicted the brand
            if ($compFromBrand) {
                $fixComp = mb_substr(trim($compFromBrand), 0, 255);
            }
        }
        if ($curGid === $newGid && $fixComp === null) {
            $stats['already_ok']++;
        } else {
            if (!$dryRun) {
                if ($fixComp !== null) {
                    $updWithComp->execute(['gid' => $newGid, 'comp' => $fixComp, 'u' => $now, 'id' => $id]);
                } else {
                    $upd->execute(['gid' => $newGid, 'u' => $now, 'id' => $id]);
                }
            }
            $stats['updated']++;
            if (str_starts_with($how, 'comp:exact') || str_starts_with($how, 'comp:compact')) {
                $stats['mapped_exact']++;
            } elseif (str_starts_with($how, 'comp:')) {
                $stats['mapped_comp']++;
            } elseif (str_starts_with($how, 'brand:')) {
                $stats['mapped_brand']++;
            } else {
                $stats['mapped_fuzzy']++;
            }
        }
    } else {
        // Clear bad existing links
        if ($curGid && !$linkOk($curGid, $comp, $pname, $genericsById)) {
            if (!$dryRun) {
                $clear->execute(['u' => $now, 'id' => $id]);
            }
            $stats['cleared_bad']++;
            $stats['updated']++;
        } else {
            if ($curGid && $linkOk($curGid, $comp, $pname, $genericsById)) {
                $stats['already_ok']++;
            } else {
                $stats['unmapped']++;
            }
        }
    }

    $batch++;
    if (!$dryRun && $batch % 2000 === 0) {
        $pdo->commit();
        $pdo->beginTransaction();
        echo "  … processed {$stats['products']} (upd {$stats['updated']}, ok {$stats['already_ok']}, unmapped {$stats['unmapped']})\n";
    }
}

if (!$dryRun && $pdo->inTransaction()) {
    $pdo->commit();
}

echo "\n=== Product↔Generic mapping summary ===\n";
foreach ($stats as $k => $v) {
    echo str_pad($k, 14) . ": {$v}\n";
}

$row = $pdo->query("SELECT COUNT(*) t,
 SUM(generic_id IS NOT NULL) linked,
 SUM(generic_id IS NULL) unlinked FROM products")->fetch();
echo "\nProducts linked now: {$row['linked']} / {$row['t']} (unlinked {$row['unlinked']})\n";

echo "\nSample mapped:\n";
$samples = $pdo->query(
    "SELECT LEFT(p.name,32) product, LEFT(IFNULL(p.composition,''),28) comp, LEFT(g.name,36) generic
     FROM products p JOIN generics g ON g.id = p.generic_id
     ORDER BY p.updated_at DESC LIMIT 15"
)->fetchAll();
foreach ($samples as $s) {
    echo sprintf("  %-32s | %-28s → %s\n", $s['product'], $s['comp'], $s['generic']);
}

echo "\nDone.\n";
