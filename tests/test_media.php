<?php
/** Mise en scène des médias : dépôt, service, expiration, purge. */

group('Mise en scène des médias');

test('un dépôt produit une clé de 128 bits et une URL en ASCII pur', function () {
    $put = media_put('des-octets', 'image/jpeg');
    assert_true(media_key_valid($put['key']), 'la clé doit faire 32 caractères hexadécimaux');
    assert_contains('/media.php?k=' . $put['key'], $put['url']);
    // Meta refuse les URL contenant autre chose que de l'US-ASCII.
    assert_eq(0, preg_match('/[^\x21-\x7E]/', $put['url']), 'URL non ASCII');
    media_forget($put['key']);
});

test('les métadonnées relisent le type et la taille déposés', function () {
    $bytes = fixture_jpeg(400, 400);
    $put   = media_put($bytes, 'image/jpeg');
    $meta  = media_meta($put['key']);
    assert_true($meta !== null, 'métadonnées introuvables');
    assert_eq('image/jpeg', $meta['mime']);
    assert_eq(strlen($bytes), $meta['size']);
    media_forget($put['key']);
});

test('une clé mal formée est rejetée sans toucher au disque', function () {
    assert_false(media_key_valid('../../etc/passwd'));
    assert_false(media_key_valid('ZZZZ'));
    assert_false(media_key_valid(str_repeat('a', 31)));
    assert_true(media_key_valid(str_repeat('a', 32)));
    assert_eq(null, media_meta('../../etc/passwd'));
});

test('un dépôt expiré n\'est plus lisible et disparaît à la purge', function () {
    $put = media_put('périmé', 'image/jpeg');
    // On antidate l'expiration comme le ferait le temps qui passe.
    file_put_contents(media_path($put['key'], 'json'), json_encode([
        'mime' => 'image/jpeg', 'size' => 7, 'created_at' => time() - 100000, 'expires_at' => time() - 10,
    ]));
    assert_eq(null, media_meta($put['key']), 'un dépôt expiré ne doit plus être servi');
    media_gc();
    assert_false(is_file(media_path($put['key'], 'bin')), 'la purge doit supprimer les octets');
    assert_false(is_file(media_path($put['key'], 'json')), 'la purge doit supprimer les métadonnées');
});

test('la purge épargne les dépôts encore valides', function () {
    $keep = media_put('à garder', 'image/jpeg');
    media_gc();
    assert_true(media_meta($keep['key']) !== null, 'un dépôt valide ne doit pas être purgé');
    media_forget($keep['key']);
});

test('l\'oubli d\'un dépôt supprime les deux fichiers', function () {
    $put = media_put('temporaire', 'image/jpeg');
    media_forget($put['key']);
    assert_false(is_file(media_path($put['key'], 'bin')));
    assert_false(is_file(media_path($put['key'], 'json')));
    assert_eq(null, media_meta($put['key']));
});

test('storage/media n\'est pas servi directement par le serveur web', function () {
    // Le .htaccess de storage/ interdit tout accès : c'est media.php qui sert
    // les octets. On vérifie que la règle est bien là, elle est structurante.
    $htaccess = file_get_contents(dirname(__DIR__) . '/storage/.htaccess');
    assert_contains('Require all denied', $htaccess);
    assert_true(str_starts_with(media_dir(), dirname(__DIR__) . '/storage/'),
        'les dépôts doivent rester sous storage/');
});

test('un dépôt écrit exactement les octets fournis, ou rien', function () {
    // Sur un disque plein, file_put_contents rend false mais laisse un fichier
    // partiel derrière lui (vérifié sur un système de fichiers de 64 Ko).
    // Publier ce fichier tronqué donnerait une erreur incompréhensible côté
    // Instagram, et il resterait sur le disque sans métadonnées — donc
    // invisible au ramasse-miettes. media_put() doit donc échouer *et* nettoyer.
    $avant = count(glob(media_dir() . '/*') ?: []);

    $contenu = random_bytes(50000);
    $put     = media_put($contenu, 'image/jpeg');
    assert_eq($contenu, file_get_contents(media_path($put['key'], 'bin')),
        'les octets déposés doivent être exactement ceux fournis');
    assert_eq(strlen($contenu), media_meta($put['key'])['size']);

    media_forget($put['key']);
    assert_eq($avant, count(glob(media_dir() . '/*') ?: []),
        'aucun fichier ne doit subsister après un dépôt puis son oubli');
});

test('un dépôt sans métadonnées lisibles finit par être purgé', function () {
    // C'est l'état que laisserait une écriture interrompue entre les deux
    // fichiers : des octets sans métadonnées. Le ramasse-miettes se replie
    // alors sur la date du fichier.
    $put = media_put('orphelin', 'image/jpeg');
    file_put_contents(media_path($put['key'], 'json'), 'métadonnées illisibles');
    touch(media_path($put['key'], 'json'), time() - MEDIA_TTL - 60);

    assert_eq(null, media_meta($put['key']), 'des métadonnées illisibles ne doivent rien servir');
    media_gc();
    assert_false(is_file(media_path($put['key'], 'bin')), 'les octets orphelins doivent être purgés');
    assert_false(is_file(media_path($put['key'], 'json')));
});
