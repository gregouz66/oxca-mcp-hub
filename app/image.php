<?php
/**
 * Préparation des images avant publication.
 *
 * Les plateformes imposent des contraintes strictes et pour partie
 * *rejetantes* : Instagram n'accepte que le JPEG, refuse les rapports
 * hors 4:5–1.91:1 (sans recadrer) et redimensionne silencieusement hors
 * 320–1440 px de large. Plutôt que de laisser l'API renvoyer une erreur
 * opaque après un aller-retour, on normalise ici et on refuse tôt, avec un
 * message qui dit quoi faire.
 *
 * GD est utilisé quand il est présent (c'est le cas de la quasi-totalité des
 * hébergements mutualisés) ; sinon, seules les images déjà conformes passent.
 */

/** Qualités JPEG essayées successivement pour tenir sous la limite de poids. */
const IMAGE_JPEG_QUALITIES = [90, 82, 74, 66, 58, 50];

/**
 * Nombre de pixels au-delà duquel on refuse de décoder une image.
 *
 * C'est la seule protection utile contre une « bombe de décompression » : un
 * JPEG uni de 4 Mo peut couvrir 256 mégapixels et occuper 1 Go une fois
 * décodé. Cette mémoire est allouée par GD, donc **invisible à memory_limit**,
 * qui ne la plafonne pas : sans borne, le processus se fait tuer par le
 * système plutôt que d'échouer proprement.
 *
 * Mesuré sur ce code : environ 4 Mo de mémoire résidente par mégapixel pour
 * le bitmap source, et jusqu'au double au moment d'un redimensionnement, où
 * la source et sa réduction coexistent. 24 mégapixels — ce que produit un
 * capteur haut de gamme — plafonnent ainsi autour de 230 Mo, mesurés.
 */
const IMAGE_MAX_PIXELS = 24000000;

/** GD est-il utilisable pour transcoder ? */
function image_gd_available(): bool
{
    return function_exists('imagecreatefromstring') && function_exists('imagejpeg');
}

/**
 * Décrit des octets supposés être une image.
 * Retourne ['mime', 'width', 'height', 'ratio', 'size'].
 */
function image_inspect(string $bytes, string $label): array
{
    if ($bytes === '') {
        throw new McpToolError("« $label » est vide.");
    }
    if (!function_exists('getimagesizefromstring')) {
        // Sans les fonctions d'image, on ne peut rien affirmer : on laisse
        // passer et c'est la plateforme qui tranchera.
        return ['mime' => '', 'width' => 0, 'height' => 0, 'ratio' => 0.0, 'size' => strlen($bytes)];
    }
    $info = @getimagesizefromstring($bytes);
    if ($info === false || empty($info[0]) || empty($info[1])) {
        throw new McpToolError("« $label » n'est pas une image lisible (format inconnu ou fichier tronqué).");
    }
    return [
        'mime'   => (string) ($info['mime'] ?? ''),
        'width'  => (int) $info[0],
        'height' => (int) $info[1],
        'ratio'  => $info[1] > 0 ? $info[0] / $info[1] : 0.0,
        'size'   => strlen($bytes),
    ];
}

/**
 * Normalise une image pour une plateforme donnée.
 *
 * $spec : mime, max_bytes, min_width, max_width, min_ratio, max_ratio, platform.
 * $fit  : 'reject' — refuser une image hors rapport (défaut) ;
 *         'pad'    — la compléter par des marges pour la ramener dans les clous.
 *
 * Retourne ['bytes', 'mime', 'width', 'height', 'notes' => string[]].
 */
function image_prepare(string $bytes, array $spec, string $fit, string $label): array
{
    $info  = image_inspect($bytes, $label);
    $notes = [];

    $conforms = $info['mime'] === $spec['mime']
        && $info['size'] <= $spec['max_bytes']
        && $info['width'] >= $spec['min_width']
        && $info['width'] <= $spec['max_width']
        && $info['ratio'] >= $spec['min_ratio']
        && $info['ratio'] <= $spec['max_ratio'];

    if ($conforms) {
        return $info + ['bytes' => $bytes, 'notes' => []];
    }

    if (!image_gd_available()) {
        throw new McpToolError(image_nonconformity_message($info, $spec, $label)
            . ' L\'extension GD n\'est pas disponible sur cet hébergement : la conversion automatique est impossible, fournissez une image déjà conforme.');
    }

    // Contrôle AVANT décodage : passé ce point, c'est le système qui arbitre.
    $pixels = $info['width'] * $info['height'];
    if ($pixels > IMAGE_MAX_PIXELS) {
        throw new McpToolError(sprintf(
            '« %s » fait %d × %d pixels (%s mégapixels), au-delà de ce que ce serveur peut décoder sans risquer de manquer de mémoire (%s mégapixels). '
            . 'Le poids du fichier n\'est pas en cause : une image très compressée peut occuper plusieurs centaines de mégaoctets une fois décodée. '
            . 'Réduisez ses dimensions avant de l\'envoyer — Instagram n\'affiche de toute façon pas plus de %d px de large.',
            $label,
            $info['width'],
            $info['height'],
            round($pixels / 1000000),
            round(IMAGE_MAX_PIXELS / 1000000),
            (int) $spec['max_width']
        ));
    }

    $im = @imagecreatefromstring($bytes);
    if ($im === false) {
        throw new McpToolError("« $label » n'a pas pu être décodée : le fichier est peut-être corrompu ou dans un format que le serveur ne sait pas lire.");
    }
    $im = image_apply_exif_orientation($im, $bytes, $notes);

    $width  = imagesx($im);
    $height = imagesy($im);

    // 1. Ramener l'image dans le cadre utile AVANT toute autre opération.
    //
    // Rien de publiable ne dépasse max_width de large, ni max_width/min_ratio
    // de haut : au-delà, la plateforme réduirait de toute façon. Réduire ici
    // évite surtout que l'étape suivante ne construise un canevas démesuré —
    // compléter une image de 30000×2 en 1.91:1 demanderait 1,8 Go de mémoire
    // pour un fichier de 8 Ko.
    $maxHeight = (int) ceil($spec['max_width'] / $spec['min_ratio']);
    if ($width > $spec['max_width'] || $height > $maxHeight) {
        $facteur = min($spec['max_width'] / $width, $maxHeight / $height);
        [$im, $width, $height] = image_scale($im, max(1, (int) round($width * $facteur)),
            max(1, (int) round($height * $facteur)));
        $notes[] = sprintf('réduite à %d × %d px, le format utile de la plateforme', $width, $height);
    }

    // 2. Rapport d'aspect : la plateforme rejette, elle ne recadre pas.
    $ratio = $height > 0 ? $width / $height : 0.0;
    if ($ratio < $spec['min_ratio'] || $ratio > $spec['max_ratio']) {
        if ($fit !== 'pad') {
            imagedestroy($im);
            throw new McpToolError(image_ratio_message($ratio, $spec, $label));
        }
        [$im, $width, $height] = image_pad_to_ratio($im, $width, $height, $spec);
        $notes[] = sprintf('rapport ramené à %.2f:1 par ajout de marges blanches', $width / $height);
    }

    // 3. Largeur : bornée des deux côtés, en conservant les proportions.
    $target = min(max($width, (int) $spec['min_width']), (int) $spec['max_width']);
    if ($target !== $width) {
        $avant = $width;
        [$im, $width, $height] = image_scale($im, $target, max(1, (int) round($height * $target / $width)));
        $notes[] = sprintf('redimensionnée de %d à %d px de large', $avant, $width);
    }

    // 4. Encodage JPEG, qualité dégressive jusqu'à tenir sous la limite.
    $encoded = image_encode_jpeg_within($im, (int) $spec['max_bytes'], (int) $spec['min_width'], $width, $height, $notes);
    imagedestroy($im);

    if ($encoded === null) {
        throw new McpToolError(sprintf(
            'Impossible de faire tenir « %s » sous %s pour %s, même très compressée et réduite à %d px de large. Fournissez une image moins détaillée.',
            $label,
            image_format_bytes((int) $spec['max_bytes']),
            $spec['platform'],
            (int) $spec['min_width']
        ));
    }
    [$out, $width, $height] = $encoded;

    if ($info['mime'] !== $spec['mime'] && $info['mime'] !== '') {
        array_unshift($notes, 'convertie de ' . $info['mime'] . ' en ' . $spec['mime']);
    }

    return [
        'bytes'  => $out,
        'mime'   => $spec['mime'],
        'width'  => $width,
        'height' => $height,
        'ratio'  => $height > 0 ? $width / $height : 0.0,
        'size'   => strlen($out),
        'notes'  => $notes,
    ];
}

/**
 * Encode en JPEG en descendant la qualité, puis la taille, jusqu'à tenir sous
 * la limite. Retourne [octets, largeur, hauteur] ou null si c'est impossible.
 */
function image_encode_jpeg_within($im, int $maxBytes, int $minWidth, int $width, int $height, array &$notes): ?array
{
    // Fond blanc : le JPEG ne gère pas la transparence, et sans aplatissement
    // les zones transparentes ressortent en noir.
    $flat = image_new_canvas($width, $height);
    imagecopy($flat, $im, 0, 0, 0, 0, $width, $height);

    // On réduit tant qu'il reste de la marge au-dessus de la largeur minimale
    // acceptée : s'arrêter avant, c'est refuser une image qui aurait pu passer.
    while (true) {
        foreach (IMAGE_JPEG_QUALITIES as $quality) {
            ob_start();
            imagejpeg($flat, null, $quality);
            $out = (string) ob_get_clean();
            if (strlen($out) <= $maxBytes) {
                if ($quality !== IMAGE_JPEG_QUALITIES[0]) {
                    $notes[] = 'compressée en qualité ' . $quality . ' pour tenir sous la limite de poids';
                }
                imagedestroy($flat);
                return [$out, $width, $height];
            }
        }
        if ($width <= $minWidth) {
            break; // plus rien à réduire sans passer sous la largeur minimale
        }
        // Toujours trop lourde à qualité minimale : on réduit les dimensions,
        // sans jamais descendre sous la largeur minimale de la plateforme.
        $width  = max($minWidth, (int) round($width * 0.8));
        $height = max(1, (int) round($height * $width / imagesx($flat)));

        $smaller = image_new_canvas($width, $height);
        imagecopyresampled($smaller, $flat, 0, 0, 0, 0, $width, $height, imagesx($flat), imagesy($flat));
        imagedestroy($flat);
        $flat    = $smaller;
        $notes[] = 'réduite à ' . $width . ' px de large pour tenir sous la limite de poids';
    }
    imagedestroy($flat);
    return null;
}

/** Formate un nombre d'octets pour un message destiné à l'utilisateur. */
function image_format_bytes(int $bytes): string
{
    if ($bytes >= 1000000) {
        return rtrim(rtrim(number_format($bytes / 1000000, 1, ',', ' '), '0'), ',') . ' Mo';
    }
    return rtrim(rtrim(number_format($bytes / 1000, 1, ',', ' '), '0'), ',') . ' Ko';
}

/**
 * Redimensionne sur un canevas au fond blanc.
 *
 * Un canevas truecolor naît noir : sans ce fond, les zones transparentes d'un
 * PNG ressortiraient en noir. Retourne [image, largeur, hauteur].
 */
function image_scale($im, int $newWidth, int $newHeight): array
{
    $canvas = image_new_canvas($newWidth, $newHeight);
    imagecopyresampled($canvas, $im, 0, 0, 0, 0, $newWidth, $newHeight, imagesx($im), imagesy($im));
    imagedestroy($im);
    return [$canvas, $newWidth, $newHeight];
}

/** Complète l'image par des marges blanches pour ramener son rapport dans les bornes. */
function image_pad_to_ratio($im, int $width, int $height, array $spec): array
{
    $ratio  = $width / $height;
    $target = max((float) $spec['min_ratio'], min((float) $spec['max_ratio'], $ratio));

    if ($ratio > $target) {   // trop large → on ajoute en hauteur
        $newWidth  = $width;
        $newHeight = (int) round($width / $target);
    } else {                  // trop haute → on ajoute en largeur
        $newHeight = $height;
        $newWidth  = (int) round($height * $target);
    }
    $newWidth  = max($newWidth, 1);
    $newHeight = max($newHeight, 1);

    // Filet de sécurité : l'appelant a déjà ramené l'image dans le cadre utile,
    // donc ce canevas est petit. S'il ne l'était pas, mieux vaut un refus lisible
    // qu'une allocation de plusieurs gigaoctets.
    if ($newWidth * $newHeight > IMAGE_MAX_PIXELS) {
        imagedestroy($im);
        throw new McpToolError(sprintf(
            'Compléter une image de %d × %d pixels pour atteindre le rapport %.2f:1 demanderait un canevas de %d × %d, trop grand pour ce serveur. Recadrez l\'image vous-même.',
            $width, $height, $target, $newWidth, $newHeight
        ));
    }

    $canvas = image_new_canvas($newWidth, $newHeight);
    imagecopy($canvas, $im, (int) (($newWidth - $width) / 2), (int) (($newHeight - $height) / 2), 0, 0, $width, $height);
    imagedestroy($im);

    return [$canvas, $newWidth, $newHeight];
}

/**
 * Alloue un canevas blanc, en refusant proprement si la mémoire manque.
 *
 * imagecreatetruecolor() rend false plutôt que de lever : le laisser filer
 * produirait une TypeError transformée en « Internal error » sans indice.
 */
function image_new_canvas(int $width, int $height)
{
    $canvas = @imagecreatetruecolor($width, $height);
    if ($canvas === false) {
        throw new McpToolError('Le serveur n\'a pas assez de mémoire pour traiter une image de '
            . $width . ' × ' . $height . ' pixels. Réduisez ses dimensions avant de l\'envoyer.');
    }
    imagefilledrectangle($canvas, 0, 0, $width, $height, imagecolorallocate($canvas, 255, 255, 255));
    return $canvas;
}

/**
 * Applique l'orientation EXIF d'une photo. Sans cela, une image prise en
 * portrait avec un téléphone part couchée — GD ignore le champ Orientation.
 */
function image_apply_exif_orientation($im, string $bytes, array &$notes)
{
    if (!function_exists('exif_read_data')) {
        return $im;
    }
    $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($bytes));
    $orientation = (int) ($exif['Orientation'] ?? 0);
    if ($orientation < 2 || $orientation > 8) {
        return $im;
    }

    // 2,4,5,7 comportent un miroir ; 3,6,8 sont de simples rotations.
    if (in_array($orientation, [2, 4, 5, 7], true) && function_exists('imageflip')) {
        imageflip($im, IMG_FLIP_HORIZONTAL);
    }
    // 6 et 8 sont les cas courants (photo prise à la verticale) ; 5 et 7 sont
    // leurs variantes en miroir, et tournent dans l'autre sens.
    $angle = match ($orientation) {
        3, 4    => 180,
        6, 7    => -90,
        5, 8    => 90,
        default => 0,
    };
    if ($angle !== 0) {
        $rotated = @imagerotate($im, $angle, 0);
        if ($rotated !== false) {
            imagedestroy($im);
            $im = $rotated;
        }
    }
    $notes[] = 'redressée d\'après son orientation EXIF';
    return $im;
}

/** Message expliquant en quoi une image n'est pas conforme. */
function image_nonconformity_message(array $info, array $spec, string $label): string
{
    $reasons = [];
    if ($info['mime'] !== $spec['mime'] && $info['mime'] !== '') {
        $reasons[] = 'son format est ' . $info['mime'] . ' au lieu de ' . $spec['mime'];
    }
    if ($info['size'] > $spec['max_bytes']) {
        $reasons[] = 'elle pèse ' . round($info['size'] / 1048576, 1) . ' Mo';
    }
    if ($info['width'] > 0 && ($info['width'] < $spec['min_width'] || $info['width'] > $spec['max_width'])) {
        $reasons[] = 'elle fait ' . $info['width'] . ' px de large (attendu entre '
            . $spec['min_width'] . ' et ' . $spec['max_width'] . ')';
    }
    if ($info['ratio'] > 0 && ($info['ratio'] < $spec['min_ratio'] || $info['ratio'] > $spec['max_ratio'])) {
        $reasons[] = sprintf('son rapport est %.2f:1', $info['ratio']);
    }
    return "« $label » n'est pas conforme aux attentes de " . $spec['platform']
        . ($reasons !== [] ? ' : ' . implode(', ', $reasons) . '.' : '.');
}

/** Message dédié au rapport d'aspect, le refus le plus fréquent et le plus opaque. */
function image_ratio_message(float $ratio, array $spec, string $label): string
{
    return sprintf(
        '« %s » a un rapport de %.2f:1, hors des bornes acceptées par %s (%.2f:1 à %.2f:1) — l\'API refuse ces images au lieu de les recadrer. '
        . 'Recadrez l\'image, ou passez « fit » à « pad » pour la compléter automatiquement par des marges blanches.',
        $label,
        $ratio,
        $spec['platform'],
        $spec['min_ratio'],
        $spec['max_ratio']
    );
}
