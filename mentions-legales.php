<?php
/**
 * Mentions légales — page publique.
 *
 * ⚠️ Si vous forkez ce projet : remplacez les informations d'éditeur et
 * d'hébergeur ci-dessous par les vôtres avant toute mise en ligne.
 */

require __DIR__ . '/app/bootstrap.php';

ui_top('Mentions légales', current_user());
ui_page_header('Mentions légales', 'En vigueur au ' . format_date(now()));

ui_card_open();
?>
<div class="prose">
  <h2>1. Éditeur du site</h2>
  <p>
    Le présent site et le service <?= e(APP_NAME) ?> sont édités par :<br>
    <strong>OXCA</strong>, société par actions simplifiée (SAS) au capital de 900&nbsp;€<br>
    Siège social : 35&nbsp;rue Jean Bouin, 66280&nbsp;Saleilles, France<br>
    SIRET : 894&nbsp;325&nbsp;414&nbsp;00015 — RCS Perpignan 894&nbsp;325&nbsp;414<br>
    TVA intracommunautaire : FR01&nbsp;894&nbsp;325&nbsp;414<br>
    Téléphone : (+33)&nbsp;07&nbsp;66&nbsp;89&nbsp;32&nbsp;06 — Email : <a href="mailto:oxca.fr@gmail.com">oxca.fr@gmail.com</a>
  </p>
  <p>Directeur de la publication : Joseph Cascales, président.</p>

  <h2>2. Hébergement</h2>
  <p>
    Le site est hébergé par <strong>Infomaniak Network SA</strong>,
    Rue Eugène-Marziano&nbsp;25, 1227&nbsp;Les Acacias (Genève), Suisse —
    Téléphone : +41&nbsp;22&nbsp;820&nbsp;35&nbsp;44 —
    <a href="https://www.infomaniak.com" target="_blank" rel="noopener">www.infomaniak.com</a>.
  </p>

  <h2>3. Objet du service</h2>
  <p>
    <?= e(APP_NAME) ?> permet à ses utilisateurs de configurer des connecteurs
    MCP (Model Context Protocol) — notamment un connecteur LinkedIn — et de les
    utiliser comme connecteurs personnalisés avec les produits Claude
    (Claude Code, claude.ai). Chaque utilisateur connecte ses propres comptes
    tiers et reste seul maître des actions réalisées via ses connecteurs.
  </p>

  <h2>4. Propriété intellectuelle</h2>
  <p>
    L'interface, la marque et les contenus édités par OXCA sur ce site sont
    protégés par le droit de la propriété intellectuelle ; toute reproduction
    sans autorisation écrite préalable d'OXCA est interdite et constituerait
    une contrefaçon. Le code source du logiciel <?= e(APP_NAME) ?> est quant à
    lui publié sous licence libre MIT.
  </p>
  <p>
    LinkedIn est une marque de LinkedIn Corporation. Claude est une marque
    d'Anthropic, PBC. Ce service n'est ni édité, ni approuvé par ces sociétés.
  </p>

  <h2>5. Responsabilité</h2>
  <p>
    Le service est fourni «&nbsp;en l'état&nbsp;». Son fonctionnement dépend de
    services tiers (notamment les API de LinkedIn) dont la disponibilité, les
    conditions et les limites peuvent évoluer à tout moment ; OXCA ne saurait
    être tenue responsable d'une interruption ou d'une évolution de ces
    services tiers. L'utilisateur est seul responsable des contenus qu'il
    publie par l'intermédiaire de ses connecteurs et du respect des conditions
    d'utilisation des plateformes tierces auxquelles il les relie.
  </p>

  <h2>6. Liens hypertextes</h2>
  <p>
    Le site peut contenir des liens vers des sites tiers dont le contenu ne
    relève pas de la responsabilité d'OXCA.
  </p>

  <h2>7. Données personnelles</h2>
  <p>
    Le traitement des données personnelles est décrit dans la
    <a href="<?= e(base_url('/confidentialite.php')) ?>">politique de confidentialité</a>.
    Conformément au RGPD, vous disposez notamment de droits d'accès, de
    rectification et de suppression de vos données.
  </p>

  <h2>8. Droit applicable</h2>
  <p>
    Les présentes mentions sont régies par le droit français. À défaut de
    résolution amiable, tout litige relève des juridictions compétentes de
    Perpignan.
  </p>
</div>
<?php
ui_card_close();
ui_bottom();
