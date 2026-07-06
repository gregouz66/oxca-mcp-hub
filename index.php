<?php
/** Page d'accueil publique. Redirige vers le tableau de bord si connecté. */

require __DIR__ . '/app/bootstrap.php';

if (current_user()) {
    redirect('/dashboard.php');
}

ui_top('Accueil');
?>

<div class="hero rise">
  <h1>Vos connecteurs MCP,<br>prêts pour Claude.</h1>
  <p>Configurez des connecteurs pré-codés — LinkedIn pour commencer —,
     branchez-les à Claude Code en une commande, partagez-les à qui vous voulez.</p>
  <div class="hero-actions">
    <a class="btn btn-primary btn-lg" href="<?= e(base_url('/login.php')) ?>">Commencer</a>
    <a class="btn btn-ghost btn-lg" href="https://modelcontextprotocol.io" target="_blank" rel="noopener">Qu'est-ce que MCP&nbsp;?</a>
  </div>
</div>

<div class="grid">
  <div class="card feature rise" style="--d:60ms">
    <?= ui_icon('mail') ?>
    <h3>Zéro mot de passe</h3>
    <p>Connexion par code ou lien magique envoyé par email. Rien à retenir, rien à faire fuiter.</p>
  </div>
  <div class="card feature rise" style="--d:120ms">
    <?= ui_icon('bolt') ?>
    <h3>MCP pré-codés</h3>
    <p>Les outils sont déjà écrits. Vous ne renseignez que le strict nécessaire pour les rendre fonctionnels.</p>
  </div>
  <div class="card feature rise" style="--d:180ms">
    <?= ui_icon('users') ?>
    <h3>Partage maîtrisé</h3>
    <p>Partagez un connecteur par simple adresse email, révoquez l'accès ou supprimez-le à tout moment.</p>
  </div>
</div>

<?php ui_card_open('Comment ça marche', '', 4); ?>
<div class="steps">
  <div class="step"><div><strong>Connectez-vous avec votre email</strong>
    <p>Un code à 6 chiffres et un lien magique arrivent dans votre boîte. Aucun compte à créer.</p></div></div>
  <div class="step"><div><strong>Créez un connecteur depuis le catalogue</strong>
    <p>Choisissez LinkedIn, renseignez le minimum demandé, cliquez sur « Connecter ».</p></div></div>
  <div class="step"><div><strong>Branchez Claude Code</strong>
    <p>Copiez la commande fournie : votre connecteur devient un serveur MCP personnel, sécurisé par token.</p></div></div>
  <div class="step"><div><strong>Partagez si vous voulez</strong>
    <p>Chaque invité reçoit son propre token, révocable individuellement, sans jamais voir vos secrets.</p></div></div>
</div>
<?php
ui_card_close();
ui_bottom();
