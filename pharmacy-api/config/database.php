<?php

use App\Support\Env;

$host    = Env::get('DB_HOST', '127.0.0.1');
$port    = (int) Env::get('DB_PORT', 3306);
$user    = Env::get('DB_USER', 'root');
$pass    = Env::get('DB_PASS', '');
$charset = Env::get('DB_CHARSET', 'utf8mb4');

$build = static function (string $database) use ($host, $port, $user, $pass, $charset): array {
    return [
        'dsn'      => "mysql:host={$host};port={$port};dbname={$database};charset={$charset}",
        'database' => $database,
        'username' => $user,
        'password' => $pass,
        'options'  => [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ],
    ];
};

return [
    // Logical connection name => connection config
    'default'     => 'ho',
    'connections' => [
        'master' => $build(Env::get('DB_MASTER', 'pharma_master')),
        'ho'     => $build(Env::get('DB_HO', 'pharma_ho')),
        'ops'    => $build(Env::get('DB_OPS', 'pharma_ops')),
    ],
];
