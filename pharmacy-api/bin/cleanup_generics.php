<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Support\Env;
use Dotenv\Dotenv;

Dotenv::createImmutable(dirname(__DIR__) . '/config', '.env')->safeLoad();

$pdo = new PDO(
    'mysql:host=' . Env::get('DB_HOST', '127.0.0.1') . ';dbname=' . Env::get('DB_MASTER', 'pharma_master') . ';charset=utf8mb4',
    (string) Env::get('DB_USER', 'root'),
    (string) Env::get('DB_PASS', ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$rows = $pdo->query('SELECT id, name FROM generics')->fetchAll();
$upd = $pdo->prepare('UPDATE generics SET name = :n, updated_at = NOW() WHERE id = :id');
$del = $pdo->prepare('DELETE FROM generics WHERE id = :id');
$fixed = 0;
$deleted = 0;
$seen = [];

foreach ($rows as $r) {
    $n = (string) $r['name'];
    $n = preg_replace('/\s*\(\s*\)/', '', $n) ?? $n;
    $n = preg_replace('/\((\d+(?:\.\d+)?)\s*[Mm][Gg]\)/', '$1mg', $n) ?? $n;
    $n = preg_replace('/\s+/', ' ', trim($n)) ?? $n;

    // Truncated OCR junk like "Propranolol 401" without unit
    if ($n === '' || (preg_match('/\b\d{3,}$/', $n) && !preg_match('/\d+\s*(mg|mcg|ml|g|iu|%)\b/i', $n))) {
        $del->execute(['id' => $r['id']]);
        $deleted++;
        continue;
    }

    $key = strtolower(preg_replace('/[^a-z0-9+]+/', '', $n) ?? '');
    if ($key === '' || isset($seen[$key])) {
        $del->execute(['id' => $r['id']]);
        $deleted++;
        continue;
    }
    $seen[$key] = true;

    if ($n !== $r['name']) {
        $upd->execute(['n' => $n, 'id' => $r['id']]);
        $fixed++;
    }
}

$total = (int) $pdo->query('SELECT COUNT(*) FROM generics')->fetchColumn();
$withClass = (int) $pdo->query("SELECT COUNT(*) FROM generics WHERE therapeutic_class IS NOT NULL AND therapeutic_class <> ''")->fetchColumn();
echo "fixed={$fixed} deleted={$deleted} total={$total} with_class={$withClass}\n";
