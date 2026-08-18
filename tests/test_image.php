<?php
/** Préparation des images aux contraintes d'Instagram. */

group('Préparation des images');

$spec = ig_image_spec();

test('une image est décrite correctement', function () {
    $info = image_inspect(fixture_png(600, 400), 'image');
    assert_eq('image/png', $info['mime']);
    assert_eq(600, $info['width']);
    assert_eq(400, $info['height']);
    assert_eq(1.5, round($info['ratio'], 2));
});

test('un contenu qui n\'est pas une image est refusé avec un message clair', function () {
    assert_throws(fn () => image_inspect('ceci n\'est pas une image', 'image'),
        'n\'est pas une image lisible', McpToolError::class);
});

test('un PNG est converti en JPEG', function () use ($spec) {
    $out = image_prepare(fixture_png(800, 800), $spec, 'reject', 'image');
    assert_eq('image/jpeg', $out['mime']);
    assert_eq('image/jpeg', image_inspect($out['bytes'], 'image')['mime']);
    assert_contains('convertie de image/png en image/jpeg', implode(' ', $out['notes']));
});

test('une image trop large est ramenée à 1440 px', function () use ($spec) {
    $out = image_prepare(fixture_png(3000, 3000), $spec, 'reject', 'image');
    assert_eq(1440, $out['width'], 'la largeur doit être bornée à 1440 px');
    assert_eq(1440, $out['height'], 'les proportions doivent être conservées');
    assert_contains('redimensionnée', implode(' ', $out['notes']));
});

test('une image trop étroite est agrandie à 320 px', function () use ($spec) {
    $out = image_prepare(fixture_png(100, 100), $spec, 'reject', 'image');
    assert_eq(320, $out['width'], 'la largeur minimale d\'Instagram est 320 px');
});

test('un rapport hors bornes est refusé, pas recadré', function () use ($spec) {
    // 16:9 = 1.78 passe ; 21:9 = 2.33 non.
    $e = assert_throws(fn () => image_prepare(fixture_png(2100, 900), $spec, 'reject', 'ma photo'),
        'hors des bornes acceptées', McpToolError::class);
    assert_contains('ma photo', $e->getMessage(), 'le message doit nommer l\'image en cause');
    assert_contains('fit', $e->getMessage(), 'le message doit indiquer la solution');
});

test('fit=pad ramène un panorama dans les bornes par des marges', function () use ($spec) {
    $out = image_prepare(fixture_png(2100, 900), $spec, 'pad', 'image');
    $ratio = $out['width'] / $out['height'];
    assert_true($ratio <= $spec['max_ratio'] + 0.01, 'rapport encore trop large : ' . $ratio);
    assert_true($ratio >= $spec['min_ratio'] - 0.01, 'rapport encore trop étroit : ' . $ratio);
    assert_contains('marges blanches', implode(' ', $out['notes']));
});

test('fit=pad redresse aussi une image trop haute', function () use ($spec) {
    $out   = image_prepare(fixture_png(400, 1600), $spec, 'pad', 'image');
    $ratio = $out['width'] / $out['height'];
    assert_true($ratio >= $spec['min_ratio'] - 0.01, 'rapport encore trop étroit : ' . $ratio);
});

test('les bornes de rapport acceptent 4:5 et 1.91:1', function () use ($spec) {
    foreach ([[800, 1000], [1146, 600]] as [$w, $h]) {
        $out = image_prepare(fixture_png($w, $h), $spec, 'reject', 'image');
        assert_eq('image/jpeg', $out['mime'], "le format {$w}×{$h} devrait passer");
    }
});

test('une image trop lourde est compressée sous la limite', function () {
    // Limite artificiellement basse : on force le chemin de dégradation.
    $spec = ig_image_spec();
    $spec['max_bytes'] = 25000;
    $out = image_prepare(fixture_jpeg(1400, 1400, 100), $spec, 'reject', 'image');
    assert_true(strlen($out['bytes']) <= 25000,
        'poids obtenu : ' . strlen($out['bytes']) . ' octets');
    assert_contains('pour tenir sous la limite', implode(' ', $out['notes']));
});

test('une image déjà conforme n\'est pas retouchée', function () use ($spec) {
    $bytes = fixture_jpeg(1080, 1080);
    $out   = image_prepare($bytes, $spec, 'reject', 'image');
    assert_eq($bytes, $out['bytes'], 'les octets doivent être transmis tels quels');
    assert_eq([], $out['notes']);
});

test('la transparence d\'un PNG devient du blanc, jamais du noir', function () use ($spec) {
    $im = imagecreatetruecolor(500, 500);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127)); // entièrement transparent
    ob_start();
    imagepng($im);
    imagedestroy($im);
    $png = (string) ob_get_clean();

    $out = image_prepare($png, $spec, 'reject', 'image');
    $jpeg = imagecreatefromstring($out['bytes']);
    $rgb  = imagecolorsforindex($jpeg, imagecolorat($jpeg, 250, 250));
    imagedestroy($jpeg);
    assert_true($rgb['red'] > 240 && $rgb['green'] > 240 && $rgb['blue'] > 240,
        'le fond devrait être blanc, obtenu rgb(' . $rgb['red'] . ',' . $rgb['green'] . ',' . $rgb['blue'] . ')');
});
