<?php
/**
 * Rendu d'une page de l'application hors serveur web, pour les tests.
 *
 * La page appelée charge elle-même app/bootstrap.php : on ne peut donc pas le
 * pré-charger ici. La session est amorcée en écrivant directement son fichier,
 * puis en présentant l'identifiant correspondant dans $_COOKIE.
 *
 * Usage : php tests/render.php <fichier.php> [uid] [clé=valeur ...]
 */

$file = $argv[1] ?? '';
$uid  = (int) ($argv[2] ?? 1);

$root = dirname(__DIR__);
$dir  = $root . '/storage/sessions';
@mkdir($dir, 0700, true);

$sid = 'testsession' . $uid;
file_put_contents($dir . '/sess_' . $sid, 'uid|i:' . $uid . ';csrf|s:5:"tcsrf";');

$_COOKIE['oxcahub']        = $sid;
$_SERVER['REQUEST_METHOD'] = getenv('T_METHOD') ?: 'GET';
$_SERVER['REQUEST_URI']    = '/' . $file;
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['HTTP_HOST']      = '127.0.0.1';

foreach (array_slice($argv, 3) as $pair) {
    [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $_POST[$k] = $v;
    } else {
        $_GET[$k] = $v;
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_POST['csrf'] = 'tcsrf';
}

require $root . '/' . $file;
