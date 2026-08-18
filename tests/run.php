<?php
/**
 * Lanceur de la suite de tests.
 *
 *   php tests/run.php            tous les fichiers tests/test_*.php
 *   php tests/run.php instagram  seulement ceux dont le nom contient « instagram »
 *
 * Prérequis : un config.php pointant vers une base de test (les tests créent
 * des utilisateurs et des connecteurs jetables), et aucun accès réseau requis
 * — les appels aux API sont simulés par http_fake().
 */

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/lib.php';

$filter = $argv[1] ?? '';
$files  = glob(__DIR__ . '/test_*.php') ?: [];
sort($files);

$started = microtime(true);
foreach ($files as $file) {
    if ($filter !== '' && !str_contains(basename($file), $filter)) {
        continue;
    }
    require $file;
    http_real(); // aucun test ne laisse un transport simulé derrière lui
}

$elapsed = round((microtime(true) - $started) * 1000);
$passed  = $GLOBALS['t_passed'];
$failed  = $GLOBALS['t_failed'];

echo "\n" . str_repeat('─', 60) . "\n";
if ($failed === 0) {
    echo "\033[32m$passed tests passés\033[0m en {$elapsed} ms\n";
    exit(0);
}
echo "\033[31m$failed échec(s)\033[0m sur " . ($passed + $failed) . " tests, en {$elapsed} ms\n\n";
foreach ($GLOBALS['t_errors'] as $error) {
    echo "  • $error\n\n";
}
exit(1);
