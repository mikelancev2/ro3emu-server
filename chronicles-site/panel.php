<?php

declare(strict_types=1);

require __DIR__ . '/includes/csrf.php';
require __DIR__ . '/includes/donations.php';
require __DIR__ . '/includes/stripe.php';
site_session_start();

$languages = ['pt' => 'Português', 'en' => 'English', 'es' => 'Español'];
$language = $_GET['lang'] ?? 'pt';
if (!isset($languages[$language])) {
    $language = 'pt';
}

$authenticatedAt = (int) ($_SESSION['authenticated_at'] ?? 0);
if (!is_string($_SESSION['account'] ?? null) || $authenticatedAt <= time() - 43200) {
    unset($_SESSION['account'], $_SESSION['authenticated_at']);
    header('Location: index.php?login=1&lang=' . rawurlencode($language));
    exit;
}

$csrfToken = csrf_token();
$assetVersion = (string) max(
    filemtime(__DIR__ . '/assets/css/site.css'),
    filemtime(__DIR__ . '/assets/js/panel.js'),
);
$copy = [
    'pt' => [
        'home' => 'Início', 'news' => 'Novidades', 'classes' => 'Classes', 'download' => 'Download', 'panel' => 'Painel',
        'eyebrow' => 'Área do aventureiro', 'title' => 'Sua jornada em <em>Chronicles.</em>',
        'lead' => 'Acompanhe seus personagens, seus recursos e tudo que estará disponível na loja.',
        'account' => 'Conta', 'logout' => 'Sair', 'characters' => 'Personagens',
        'loading' => 'Carregando seus personagens…',
        'error' => 'Não foi possível carregar seus personagens agora.', 'empty' => 'Você ainda não criou nenhum personagem.',
        'level' => 'Nível', 'job_level' => 'Classe', 'paragon' => 'Paragon', 'last_login' => 'Último acesso',
        'online' => 'Online', 'offline' => 'Offline', 'never' => 'Nunca', 'zeny' => 'Zeny', 'crystals' => 'Cristais',
        'store' => 'Donate',
        'crystal_label' => 'Moeda premium',
        'crystal_pack' => 'Pacote de cristais', 'crystal_unit' => 'Cristais', 'crystal_copy' => 'Para usar nos sistemas e na loja dentro do jogo.',
        'soon' => 'Em preparação', 'buy' => 'Comprar', 'choose_character' => 'Personagem que receberá os cristais',
        'select_character' => 'Selecione um personagem',
        'payment_processing' => 'Pagamento aprovado. Os cristais serão enviados ao correio do personagem em instantes.',
        'payment_cancelled' => 'Pagamento cancelado. Nenhuma cobrança ou entrega foi realizada.',
        'payment_error' => 'Não foi possível iniciar o pagamento. Tente novamente.',
        'store_notice' => 'Após a confirmação, os cristais serão entregues pelo correio dentro do jogo.',
        'footer' => 'Uma nova aventura começa em breve.',
    ],
    'en' => [
        'home' => 'Home', 'news' => 'News', 'classes' => 'Classes', 'download' => 'Download', 'panel' => 'Panel',
        'eyebrow' => 'Adventurer area', 'title' => 'Your journey in <em>Chronicles.</em>',
        'lead' => 'View your characters, resources, and everything coming to the shop.',
        'account' => 'Account', 'logout' => 'Sign out', 'characters' => 'Characters',
        'loading' => 'Loading your characters…',
        'error' => "Couldn't load your characters right now.", 'empty' => "You haven't created a character yet.",
        'level' => 'Level', 'job_level' => 'Job', 'paragon' => 'Paragon', 'last_login' => 'Last login',
        'online' => 'Online', 'offline' => 'Offline', 'never' => 'Never', 'zeny' => 'Zeny', 'crystals' => 'Crystals',
        'store' => 'Donate',
        'crystal_label' => 'Premium currency',
        'crystal_pack' => 'Crystal pack', 'crystal_unit' => 'Crystals', 'crystal_copy' => 'For in-game systems and the in-game shop.',
        'soon' => 'Coming soon', 'buy' => 'Buy', 'choose_character' => 'Character that will receive the crystals',
        'select_character' => 'Select a character',
        'payment_processing' => 'Payment approved. The crystals will be sent to the character’s in-game mail shortly.',
        'payment_cancelled' => 'Payment cancelled. No charge or delivery was made.',
        'payment_error' => 'The payment could not be started. Please try again.',
        'store_notice' => 'After confirmation, crystals are delivered through in-game mail.',
        'footer' => 'A new adventure begins soon.',
    ],
    'es' => [
        'home' => 'Inicio', 'news' => 'Novedades', 'classes' => 'Clases', 'download' => 'Descargar', 'panel' => 'Panel',
        'eyebrow' => 'Área del aventurero', 'title' => 'Tu jornada en <em>Chronicles.</em>',
        'lead' => 'Consulta tus personajes, recursos y todo lo que llegará a la tienda.',
        'account' => 'Cuenta', 'logout' => 'Salir', 'characters' => 'Personajes',
        'loading' => 'Cargando tus personajes…',
        'error' => 'No se pudieron cargar tus personajes ahora.', 'empty' => 'Todavía no has creado ningún personaje.',
        'level' => 'Nivel', 'job_level' => 'Clase', 'paragon' => 'Paragon', 'last_login' => 'Último acceso',
        'online' => 'Online', 'offline' => 'Offline', 'never' => 'Nunca', 'zeny' => 'Zeny', 'crystals' => 'Cristales',
        'store' => 'Donate',
        'crystal_label' => 'Moneda premium',
        'crystal_pack' => 'Paquete de cristales', 'crystal_unit' => 'Cristales', 'crystal_copy' => 'Para los sistemas y la tienda dentro del juego.',
        'soon' => 'En preparación', 'buy' => 'Comprar', 'choose_character' => 'Personaje que recibirá los cristales',
        'select_character' => 'Selecciona un personaje',
        'payment_processing' => 'Pago aprobado. Los cristales se enviarán pronto al correo del personaje.',
        'payment_cancelled' => 'Pago cancelado. No se realizó ningún cobro ni entrega.',
        'payment_error' => 'No se pudo iniciar el pago. Inténtalo de nuevo.',
        'store_notice' => 'Tras la confirmación, los cristales se entregan por el correo del juego.',
        'footer' => 'Una nueva aventura comienza pronto.',
    ],
][$language];

$crystalPackages = donation_packages();
$donationsEnabled = stripe_is_configured();
$paymentState = in_array($_GET['payment'] ?? '', ['processing', 'cancelled', 'error'], true)
    ? (string) $_GET['payment'] : '';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

?>
<!doctype html>
<html lang="<?= h($language) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#111a35">
    <meta name="description" content="Chronicles — <?= h($copy['panel']) ?>">
    <title><?= h($copy['panel']) ?> — Chronicles</title>
    <link rel="stylesheet" href="assets/css/site.css?v=<?= h($assetVersion) ?>">
</head>
<body class="dashboard-page" data-profile-url="api/profile.php" data-logout-url="api/logout.php" data-csrf="<?= h($csrfToken) ?>">
    <div class="cursor-glow" aria-hidden="true"></div>
    <header class="site-header dashboard-header">
        <a class="logo" href="index.php?lang=<?= h($language) ?>#home" aria-label="Chronicles"><span class="logo-wing">✦</span><span class="logo-type"><strong class="brand-word">Chronicles</strong></span></a>
        <button class="menu-toggle" type="button" aria-controls="site-nav" aria-expanded="false"><span></span><span></span></button>
        <nav id="site-nav">
            <a href="index.php?lang=<?= h($language) ?>#home"><?= h($copy['home']) ?></a>
            <a href="index.php?lang=<?= h($language) ?>#news"><?= h($copy['news']) ?></a>
            <a href="index.php?lang=<?= h($language) ?>#classes"><?= h($copy['classes']) ?></a>
            <a href="index.php?lang=<?= h($language) ?>#download"><?= h($copy['download']) ?></a>
            <button class="mobile-account-entry" type="button" data-logout><?= h($copy['logout']) ?></button>
            <details class="language-menu mobile-language-menu"><summary><span><?= h(strtoupper($language)) ?></span><i aria-hidden="true"></i></summary><div class="language-options"><?php foreach ($languages as $code => $label): ?><a class="<?= $code === $language ? 'selected' : '' ?>" href="panel.php?lang=<?= h($code) ?>" lang="<?= h($code) ?>"><span><?= h(strtoupper($code)) ?></span><strong><?= h($label) ?></strong><b><?= $code === $language ? '✓' : '' ?></b></a><?php endforeach; ?></div></details>
        </nav>
        <div class="header-actions">
            <details class="language-menu"><summary><span><?= h(strtoupper($language)) ?></span><i aria-hidden="true"></i></summary><div class="language-options"><?php foreach ($languages as $code => $label): ?><a class="<?= $code === $language ? 'selected' : '' ?>" href="panel.php?lang=<?= h($code) ?>" lang="<?= h($code) ?>"><span><?= h(strtoupper($code)) ?></span><strong><?= h($label) ?></strong><b><?= $code === $language ? '✓' : '' ?></b></a><?php endforeach; ?></div></details>
            <button class="fantasy-button small" type="button" data-logout><span><?= h($copy['logout']) ?></span></button>
        </div>
    </header>

    <main class="dashboard-main">
        <section class="dashboard-hero">
            <div class="dashboard-hero-art" aria-hidden="true"></div>
            <div class="container dashboard-hero-content">
                <div><p class="eyebrow"><?= h($copy['eyebrow']) ?></p></div>
            </div>
        </section>

        <div class="container dashboard-content">
            <section class="dashboard-section" id="characters">
                <header class="dashboard-section-heading"><h2 class="dashboard-kicker"><?= h($copy['characters']) ?></h2></header>
                <p class="panel-status" data-panel-status role="status"><?= h($copy['loading']) ?></p>
                <div class="character-grid" data-character-grid></div>
            </section>

            <section class="dashboard-section store-section" id="shop">
                <header class="dashboard-section-heading"><h2 class="dashboard-kicker"><?= h($copy['store']) ?></h2></header>
                <?php if ($paymentState !== ''): ?>
                    <p class="donation-message donation-message-<?= h($paymentState) ?>" role="status"><?= h($copy['payment_' . $paymentState]) ?></p>
                <?php endif; ?>
                <label class="donation-recipient">
                    <span><?= h($copy['choose_character']) ?></span>
                    <select data-donation-character disabled><option value=""><?= h($copy['select_character']) ?></option></select>
                </label>
                <div class="shop-grid crystal-shop-grid">
                    <?php foreach ($crystalPackages as $packageId => $package): $tier = array_search($packageId, array_keys($crystalPackages), true); ?>
                        <article class="shop-card shop-card-crystals crystal-tier-<?= $tier + 1 ?>">
                            <div class="shop-card-visual crystal-visual" aria-hidden="true">
                                <span class="crystal-radiance"></span>
                                <img src="assets/client/marketplace/sacred-relic-crystal.png" alt="">
                                <span class="shop-card-mark"><?= $tier + 1 ?></span>
                            </div>
                            <div class="shop-card-body">
                                <span class="shop-card-label"><?= h($copy['crystal_pack']) ?></span>
                                <h4 class="crystal-package-amount"><?= h(number_format($package['crystals'], 0, '', $language === 'en' ? ',' : '.')) ?> <small><?= h($copy['crystal_unit']) ?></small></h4>
                                <strong class="crystal-package-price">R$ <?= h(number_format($package['amount_cents'] / 100, 2, ',', '.')) ?></strong>
                                <p><?= h($copy['crystal_copy']) ?></p>
                                <form method="post" action="api/create-checkout.php" data-donation-form>
                                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                    <input type="hidden" name="package_id" value="<?= h($packageId) ?>">
                                    <input type="hidden" name="role_id" value="" data-donation-role>
                                    <button type="submit" disabled data-donation-button data-stripe-enabled="<?= $donationsEnabled ? '1' : '0' ?>"><span><?= h($donationsEnabled ? $copy['buy'] : $copy['soon']) ?></span><b aria-hidden="true">→</b></button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <p class="store-notice">✦ <?= h($copy['store_notice']) ?></p>
            </section>
        </div>
    </main>

    <footer class="footer container"><a class="logo dark" href="index.php?lang=<?= h($language) ?>#home"><span class="logo-wing">✦</span><span class="logo-type"><strong class="brand-word">Chronicles</strong></span></a><p>© <?= date('Y') ?> Chronicles. <?= h($copy['footer']) ?></p></footer>
    <script>window.CHRONICLES_PANEL_I18N = <?= json_encode([
        'loading' => $copy['loading'], 'error' => $copy['error'], 'empty' => $copy['empty'], 'level' => $copy['level'],
        'jobLevel' => $copy['job_level'], 'paragon' => $copy['paragon'], 'lastLogin' => $copy['last_login'],
        'online' => $copy['online'], 'offline' => $copy['offline'], 'never' => $copy['never'],
        'zeny' => $copy['zeny'], 'crystals' => $copy['crystals'],
        'buy' => $copy['buy'],
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
    <script src="assets/js/panel.js?v=<?= h($assetVersion) ?>"></script>
</body>
</html>
