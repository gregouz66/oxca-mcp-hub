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

test('la transparence reste blanche même quand l\'image est redimensionnée', function () use ($spec) {
    // Le chemin sans redimensionnement était déjà couvert ; c'est celui avec
    // réduction, agrandissement ou marges qui noircissait le fond.
    foreach ([[3000, 3000], [200, 200], [2100, 900]] as [$w, $h]) {
        $im = imagecreatetruecolor($w, $h);
        imagesavealpha($im, true);
        imagealphablending($im, false);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        ob_start();
        imagepng($im);
        imagedestroy($im);
        $png = (string) ob_get_clean();

        $out  = image_prepare($png, $spec, 'pad', 'image');
        $jpeg = imagecreatefromstring($out['bytes']);
        $rgb  = imagecolorsforindex($jpeg, imagecolorat($jpeg, (int) (imagesx($jpeg) / 2), (int) (imagesy($jpeg) / 2)));
        imagedestroy($jpeg);
        assert_true(
            $rgb['red'] > 240 && $rgb['green'] > 240 && $rgb['blue'] > 240,
            "{$w}×{$h} : fond rgb({$rgb['red']},{$rgb['green']},{$rgb['blue']}) au lieu de blanc"
        );
    }
});

test('les orientations EXIF en miroir tournent dans le bon sens', function () {
    // 6 et 8 sont les cas courants d'une photo prise à la verticale ; 5 et 7
    // sont leurs variantes en miroir et tournent à l'opposé. Les intervertir
    // sortait l'image à 180° de la bonne.
    $source = file_get_contents(__DIR__ . '/../app/image.php');
    assert_contains('6, 7    => -90', $source);
    assert_contains('5, 8    => 90', $source);
});

test('une image aux dimensions démesurées est refusée avant décodage', function () use ($spec) {
    // Une « bombe de décompression » : un JPEG uni de quelques Mo peut couvrir
    // des centaines de mégapixels. GD alloue environ 4 Mo par mégapixel, hors
    // du memory_limit de PHP — le processus se ferait donc tuer par le système
    // au lieu d'échouer proprement.
    $im = imagecreatetruecolor(7000, 6000); // 42 Mpx, au-delà de la borne
    imagefilledrectangle($im, 0, 0, 7000, 6000, imagecolorallocate($im, 210, 215, 220));
    ob_start();
    imagejpeg($im, null, 60);
    imagedestroy($im);
    $bombe = (string) ob_get_clean();

    assert_true(strlen($bombe) < $spec['max_bytes'], 'le fichier doit passer la garde de poids : c\'est tout l\'enjeu');
    $e = assert_throws(fn () => image_prepare($bombe, $spec, 'reject', 'photo'), 'mégapixels', McpToolError::class);
    assert_contains('Le poids du fichier n\'est pas en cause', $e->getMessage());
    assert_contains('1440', $e->getMessage(), 'le message doit dire à quoi réduire');
});

test('une photo d\'appareil courante reste acceptée', function () use ($spec) {
    // 24 Mpx : un capteur haut de gamme. La borne ne doit pas gêner un usage normal.
    $im = imagecreatetruecolor(6000, 4000);
    imagefilledrectangle($im, 0, 0, 6000, 4000, imagecolorallocate($im, 120, 160, 200));
    ob_start();
    imagejpeg($im, null, 70);
    imagedestroy($im);
    $photo = (string) ob_get_clean();

    $out = image_prepare($photo, $spec, 'pad', 'photo');
    assert_eq(1440, $out['width']);
    assert_eq('image/jpeg', $out['mime']);
});
