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
    assert_contains('réduite à 1440', implode(' ', $out['notes']));
});

test('une image démesurément allongée n\'explose plus la mémoire', function () use ($spec) {
    // Un fichier de quelques kilo-octets suffisait : compléter une image de
    // 30000 × 2 en 1.91:1 demandait un canevas de 471 mégapixels, soit 1,8 Go.
    // L'image est désormais ramenée au cadre utile avant d'être complétée.
    $im = imagecreatetruecolor(30000, 2);
    imagefilledrectangle($im, 0, 0, 30000, 2, imagecolorallocate($im, 180, 190, 200));
    ob_start();
    imagejpeg($im, null, 70);
    imagedestroy($im);
    $bande = (string) ob_get_clean();

    $out = image_prepare($bande, $spec, 'pad', 'bande');
    assert_true($out['width'] <= $spec['max_width'], 'largeur finale : ' . $out['width']);
    $ratio = $out['width'] / $out['height'];
    assert_true($ratio <= $spec['max_ratio'] + 0.01, 'rapport final : ' . $ratio);
    assert_contains('format utile', implode(' ', $out['notes']));
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

test('une photo prise à la verticale est redressée', function () {
    // Orientations 6 et 8 : le cas courant. L'image est stockée en paysage
    // avec une consigne de rotation ; après redressement elle doit être en
    // portrait. Sans EXIF (orientation 1), elle doit rester telle quelle.
    $paysage = fixture_jpeg(800, 400);
    foreach ([1 => [800, 400], 3 => [800, 400], 6 => [400, 800], 8 => [400, 800]] as $orientation => [$w, $h]) {
        $avec  = fixture_jpeg_with_orientation($paysage, $orientation);
        $notes = [];
        $im    = image_apply_exif_orientation(imagecreatefromstring($avec), $avec, $notes);
        assert_eq($w, imagesx($im), "orientation $orientation : largeur");
        assert_eq($h, imagesy($im), "orientation $orientation : hauteur");
        imagedestroy($im);
    }
});

test('les orientations en miroir tournent à l\'opposé de leurs jumelles', function () {
    // 5 et 7 sont les variantes en miroir de 8 et 6 : elles tournent dans
    // l'autre sens. Les intervertir sortait l'image à 180° de la bonne.
    // On repère un coin par sa couleur et on suit où il atterrit.
    $im = imagecreatetruecolor(400, 200);
    imagefilledrectangle($im, 0, 0, 400, 200, imagecolorallocate($im, 255, 255, 255));
    imagefilledrectangle($im, 0, 0, 40, 40, imagecolorallocate($im, 255, 0, 0)); // coin haut-gauche rouge
    ob_start();
    imagejpeg($im, null, 95);
    imagedestroy($im);
    $base = (string) ob_get_clean();

    $coinRouge = function ($im): string {
        $w = imagesx($im);
        $h = imagesy($im);
        $coins = [
            'haut-gauche'  => [10, 10],
            'haut-droit'   => [$w - 10, 10],
            'bas-gauche'   => [10, $h - 10],
            'bas-droit'    => [$w - 10, $h - 10],
        ];
        foreach ($coins as $nom => [$x, $y]) {
            $c = imagecolorsforindex($im, imagecolorat($im, $x, $y));
            if ($c['red'] > 180 && $c['green'] < 90 && $c['blue'] < 90) {
                return $nom;
            }
        }
        return 'introuvable';
    };

    $position = [];
    foreach ([5, 6, 7, 8] as $orientation) {
        $avec  = fixture_jpeg_with_orientation($base, $orientation);
        $notes = [];
        $im    = image_apply_exif_orientation(imagecreatefromstring($avec), $avec, $notes);
        $position[$orientation] = $coinRouge($im);
        imagedestroy($im);
    }

    // 5 et 8 partagent leur sens de rotation, 6 et 7 le leur : si 5 et 7
    // étaient intervertis, ces égalités tomberaient.
    assert_true($position[5] !== $position[6],
        'les orientations 5 et 6 ne peuvent pas placer le repère au même endroit');
    assert_true($position[7] !== $position[8],
        'les orientations 7 et 8 ne peuvent pas placer le repère au même endroit');
    foreach ($position as $orientation => $coin) {
        assert_true($coin !== 'introuvable', "orientation $orientation : repère perdu");
    }
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
