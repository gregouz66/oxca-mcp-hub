<?php
/**
 * Composants d'interface réutilisables. Toute page HTML est composée
 * exclusivement de ces fonctions — le balisage et les classes CSS ne sont
 * jamais dupliqués dans les pages.
 */

/** Ouvre le document : <head>, barre de navigation, <main>. */
function ui_top(string $title, ?array $user = null, bool $wide = false): void
{
    $app = e(APP_NAME);
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="color-scheme" content="light dark">'
        . '<title>' . e($title) . ' — ' . $app . '</title>'
        . '<link rel="icon" href="data:image/svg+xml,' . rawurlencode(ui_icon('logo')) . '">'
        . '<link rel="stylesheet" href="' . e(base_url('/assets/css/app.css')) . '?v=' . APP_VERSION . '">'
        . '</head><body>';

    echo '<header class="nav"><div class="nav-inner">'
        . '<a class="nav-brand" href="' . e(base_url($user ? '/dashboard.php' : '/')) . '">' . ui_icon('logo') . '<span>' . $app . '</span></a>'
        . '<nav class="nav-actions">';
    if ($user) {
        echo '<span class="nav-user" title="' . e($user['email']) . '">' . e($user['email']) . '</span>'
            . '<form method="post" action="' . e(base_url('/logout.php')) . '" class="inline-form">' . csrf_field()
            . '<button type="submit" class="btn btn-ghost btn-sm">Déconnexion</button></form>';
    } else {
        echo '<a class="btn btn-primary btn-sm" href="' . e(base_url('/login.php')) . '">Se connecter</a>';
    }
    echo '</nav></div></header>';

    echo '<main class="container' . ($wide ? ' container-wide' : '') . '">';
    ui_flashes();
}

/** Ferme le document : pied de page et scripts. */
function ui_bottom(): void
{
    echo '</main>'
        . '<footer class="footer">' . e(APP_NAME) . ' · connecteurs MCP auto-hébergés pour Claude</footer>'
        . '<script src="' . e(base_url('/assets/js/app.js')) . '?v=' . APP_VERSION . '"></script>'
        . '</body></html>';
}

/** Affiche puis vide les messages flash. */
function ui_flashes(): void
{
    foreach (flash_pull() as $f) {
        echo '<div class="flash flash-' . e($f['type']) . '" role="status">'
            . ui_icon($f['type'] === 'ok' ? 'check' : ($f['type'] === 'error' ? 'alert' : 'info'))
            . '<span>' . e($f['message']) . '</span></div>';
    }
}

/** En-tête de page : titre + sous-titre + action à droite (HTML). */
function ui_page_header(string $title, string $subtitle = '', string $actionsHtml = ''): void
{
    echo '<div class="page-head rise"><div>'
        . '<h1>' . e($title) . '</h1>'
        . ($subtitle !== '' ? '<p class="muted">' . e($subtitle) . '</p>' : '')
        . '</div>'
        . ($actionsHtml !== '' ? '<div class="page-head-actions">' . $actionsHtml . '</div>' : '')
        . '</div>';
}

/** Ouvre une carte de section ($delay : rang d'apparition pour l'animation). */
function ui_card_open(string $title = '', string $subtitle = '', int $delay = 0, string $extraClass = ''): void
{
    echo '<section class="card rise ' . e($extraClass) . '" style="--d:' . ($delay * 60) . 'ms">';
    if ($title !== '') {
        echo '<header class="card-head"><h2>' . e($title) . '</h2>'
            . ($subtitle !== '' ? '<p class="muted">' . e($subtitle) . '</p>' : '')
            . '</header>';
    }
}

function ui_card_close(): void
{
    echo '</section>';
}

/** Champ de formulaire (label + input + aide). */
function ui_field(array $opts): void
{
    $type  = $opts['type'] ?? 'text';
    $name  = $opts['name'];
    $id    = 'f-' . $name;
    $attrs = ' name="' . e($name) . '" id="' . e($id) . '"';
    foreach (['placeholder', 'value', 'autocomplete', 'inputmode', 'maxlength', 'pattern'] as $a) {
        if (isset($opts[$a])) {
            $attrs .= ' ' . $a . '="' . e((string) $opts[$a]) . '"';
        }
    }
    if (!empty($opts['required'])) {
        $attrs .= ' required';
    }
    if (!empty($opts['autofocus'])) {
        $attrs .= ' autofocus';
    }

    echo '<div class="field">';
    if (!empty($opts['label'])) {
        echo '<label for="' . e($id) . '">' . e($opts['label']) . '</label>';
    }
    echo '<input class="input' . (!empty($opts['class']) ? ' ' . e($opts['class']) : '') . '" type="' . e($type) . '"' . $attrs . '>';
    if (!empty($opts['hint'])) {
        echo '<p class="hint">' . $opts['hint'] . '</p>'; // le hint peut contenir des liens
    }
    echo '</div>';
}

/** Case à cocher stylée. */
function ui_checkbox(string $name, string $label, bool $checked, string $hint = ''): void
{
    echo '<label class="check"><input type="checkbox" name="' . e($name) . '" value="1"' . ($checked ? ' checked' : '') . '>'
        . '<span class="check-box">' . ui_icon('check') . '</span><span>' . e($label)
        . ($hint !== '' ? '<small class="hint">' . $hint . '</small>' : '')
        . '</span></label>';
}

/** Valeur en lecture seule avec bouton « copier ». */
function ui_copy_row(string $label, string $value, string $hint = ''): void
{
    $id = 'c-' . substr(md5($label . $value), 0, 8);
    echo '<div class="field"><label for="' . $id . '">' . e($label) . '</label>'
        . '<div class="copy-row"><input id="' . $id . '" class="input" type="text" readonly value="' . e($value) . '" onclick="this.select()">'
        . '<button type="button" class="btn btn-ghost btn-icon" data-copy="' . e($value) . '" aria-label="Copier ' . e($label) . '">' . ui_icon('copy') . '</button></div>'
        . ($hint !== '' ? '<p class="hint">' . $hint . '</p>' : '')
        . '</div>';
}

/** Bloc de code copiable (instructions CLI, JSON…). */
function ui_code_block(string $label, string $code): void
{
    // Le <pre> n'est pas un contrôle de formulaire : on utilise un intitulé de
    // section (span) plutôt qu'un <label> qui ne pointerait sur rien.
    echo '<div class="field"><span class="field-label">' . e($label) . '</span>'
        . '<div class="code-block"><pre>' . e($code) . '</pre>'
        . '<button type="button" class="btn btn-icon code-copy" data-copy="' . e($code) . '" aria-label="Copier ' . e($label) . '">' . ui_icon('copy') . '</button>'
        . '</div></div>';
}

/** Pastille d'état ($tone : ok | warn | muted | accent). */
function ui_badge(string $text, string $tone = 'muted'): string
{
    return '<span class="badge badge-' . e($tone) . '">' . e($text) . '</span>';
}

/** État vide illustré. */
function ui_empty(string $icon, string $title, string $text, string $ctaHtml = ''): void
{
    echo '<div class="empty rise">' . ui_icon($icon)
        . '<h3>' . e($title) . '</h3><p class="muted">' . e($text) . '</p>'
        . $ctaHtml . '</div>';
}

/**
 * Formulaire POST autonome sur une ligne (actions : supprimer, révoquer…).
 * $confirm non vide → demande de confirmation côté client.
 */
function ui_post_button(string $action, array $hidden, string $label, string $btnClass = 'btn btn-ghost btn-sm', string $confirm = '', string $icon = ''): void
{
    echo '<form method="post" action="' . e($action) . '" class="inline-form"' . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>'
        . csrf_field();
    foreach ($hidden as $name => $value) {
        echo '<input type="hidden" name="' . e($name) . '" value="' . e((string) $value) . '">';
    }
    echo '<button type="submit" class="' . e($btnClass) . '">' . ($icon !== '' ? ui_icon($icon) : '') . e($label) . '</button></form>';
}

/** Carte d'un connecteur sur le tableau de bord. */
function ui_config_card(array $config, string $href, string $badgeHtml, string $metaLine, int $delay = 0): void
{
    $type = mcp_type($config['type']);
    echo '<a class="card card-link rise" href="' . e($href) . '" style="--d:' . ($delay * 60) . 'ms">'
        . '<div class="card-link-head"><span class="type-icon">' . ui_icon($type['icon']) . '</span>' . $badgeHtml . '</div>'
        . '<h3>' . e($config['name']) . '</h3>'
        . '<p class="muted">' . e($metaLine) . '</p>'
        . '<span class="card-link-cta">Ouvrir ' . ui_icon('arrow') . '</span>'
        . '</a>';
}

/** Carte du catalogue (types de MCP disponibles). */
function ui_type_card(string $key, array $type, int $delay = 0): void
{
    echo '<div class="card rise" style="--d:' . ($delay * 60) . 'ms">'
        . '<div class="card-link-head"><span class="type-icon">' . ui_icon($type['icon']) . '</span></div>'
        . '<h3>' . e($type['label']) . '</h3>'
        . '<p class="muted">' . e($type['tagline']) . '</p>'
        . '<form method="post" action="' . e(base_url('/dashboard.php')) . '">' . csrf_field()
        . '<input type="hidden" name="action" value="create">'
        . '<input type="hidden" name="type" value="' . e($key) . '">'
        . '<button type="submit" class="btn btn-primary btn-sm">' . ui_icon('plus') . 'Créer un connecteur</button>'
        . '</form></div>';
}

/** Icônes SVG inline (trait 1.6, style « SF Symbols »). */
function ui_icon(string $name): string
{
    $paths = [
        'logo'     => '<rect x="3" y="3" width="18" height="18" rx="5.5" fill="#0071e3"/><path d="M8 12.5l2.6 2.6L16 9.7" stroke="#fff" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>',
        'linkedin' => '<rect x="3" y="3" width="18" height="18" rx="4" fill="#0A66C2"/><path d="M8.3 10.2v6.3M8.3 7.6v.1M11.6 16.5v-3.7c0-1.4 1-2.4 2.3-2.4s2.1 1 2.1 2.4v3.7" stroke="#fff" stroke-width="1.8" fill="none" stroke-linecap="round"/>',
        'plus'     => '<path d="M12 5v14M5 12h14" stroke-linecap="round"/>',
        'trash'    => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2" stroke-linecap="round" stroke-linejoin="round"/>',
        'copy'     => '<rect x="9" y="9" width="11" height="11" rx="2.5"/><path d="M5 15H4.5A1.5 1.5 0 0 1 3 13.5v-9A1.5 1.5 0 0 1 4.5 3h9A1.5 1.5 0 0 1 15 4.5V5" stroke-linecap="round"/>',
        'check'    => '<path d="M5 12.5l4.2 4.2L19 7" stroke-linecap="round" stroke-linejoin="round"/>',
        'alert'    => '<path d="M12 8v5M12 16.5v.1" stroke-linecap="round"/><circle cx="12" cy="12" r="9"/>',
        'info'     => '<path d="M12 11v5M12 7.5v.1" stroke-linecap="round"/><circle cx="12" cy="12" r="9"/>',
        'arrow'    => '<path d="M5 12h14M13 6l6 6-6 6" stroke-linecap="round" stroke-linejoin="round"/>',
        'refresh'  => '<path d="M20 11A8 8 0 1 0 18.9 15M20 5v6h-6" stroke-linecap="round" stroke-linejoin="round"/>',
        'share'    => '<circle cx="6" cy="12" r="2.6"/><circle cx="17.5" cy="5.5" r="2.6"/><circle cx="17.5" cy="18.5" r="2.6"/><path d="M8.4 10.8l6.8-4M8.4 13.2l6.8 4"/>',
        'key'      => '<circle cx="8" cy="14" r="4.5"/><path d="M11.5 10.5L19 3M15.5 6.5l3 3" stroke-linecap="round"/>',
        'bolt'     => '<path d="M13 3L5 13.5h6L11 21l8-10.5h-6L13 3z" stroke-linejoin="round"/>',
        'mail'     => '<rect x="3" y="5.5" width="18" height="13" rx="2.5"/><path d="M4 7.5l8 6 8-6"/>',
        'shield'   => '<path d="M12 3l7.5 3v5.4c0 4.6-3.1 8-7.5 9.6-4.4-1.6-7.5-5-7.5-9.6V6L12 3z" stroke-linejoin="round"/><path d="M9 11.8l2.2 2.2L15.4 9.5" stroke-linecap="round" stroke-linejoin="round"/>',
        'terminal' => '<rect x="3" y="4.5" width="18" height="15" rx="2.5"/><path d="M7 9.5l3 3-3 3M12.5 15.5H17" stroke-linecap="round" stroke-linejoin="round"/>',
        'users'    => '<circle cx="9" cy="8.5" r="3.2"/><path d="M3.5 19c.6-3 2.8-4.8 5.5-4.8s4.9 1.8 5.5 4.8M15.5 5.8a3.2 3.2 0 0 1 0 5.7M17 14.6c2 .6 3.2 2.1 3.6 4.4" stroke-linecap="round"/>',
        'spark'    => '<path d="M12 3v4M12 17v4M3 12h4M17 12h4M5.6 5.6l2.8 2.8M15.6 15.6l2.8 2.8M18.4 5.6l-2.8 2.8M8.4 15.6l-2.8 2.8" stroke-linecap="round"/>',
    ];
    $svg = $paths[$name] ?? $paths['info'];
    $stroked = !in_array($name, ['logo', 'linkedin'], true);
    return '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"'
        . ($stroked ? ' fill="none" stroke="currentColor" stroke-width="1.6"' : '')
        . '>' . $svg . '</svg>';
}
