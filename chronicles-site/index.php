<?php

declare(strict_types=1);

require __DIR__ . '/includes/csrf.php';
$csrfToken = csrf_token();
// Set once by auth/google-callback.php after a verified Google sign-in; read once and gone, nothing is
// persisted beyond this single page load.
$prefillEmail = $_SESSION['prefill_email'] ?? '';
unset($_SESSION['prefill_email']);
$openAccountModal = isset($_GET['open_account']) || isset($_GET['google_error']);
$googleError = isset($_GET['google_error']);

$config = require __DIR__ . '/site-config.php';
$assetVersion = (string) max(
    filemtime(__DIR__ . '/assets/css/site.css'),
    filemtime(__DIR__ . '/assets/js/site.js'),
);
$languages = ['pt' => 'Português', 'en' => 'English', 'es' => 'Español'];
$language = $_GET['lang'] ?? 'pt';
if (!isset($languages[$language])) {
    $language = 'pt';
}
$languageMenuLabel = [
    'pt' => 'Escolher idioma',
    'en' => 'Choose language',
    'es' => 'Elegir idioma',
][$language];

$copy = [
    'pt' => [
        'nav_home' => 'Início', 'nav_news' => 'Novidades', 'nav_classes' => 'Classes', 'nav_download' => 'Download', 'account' => 'Login',
        'eyebrow' => 'O velho mundo está chamando', 'hero_title' => 'A história ainda<br><em>não acabou.</em>',
        'hero_copy' => 'Reúna seus amigos, escolha seu caminho e viva uma aventura feita para ser lembrada.', 'play' => 'Começar agora', 'discover' => 'Descobrir Chronicles',
        'online' => 'Servidor online', 'news_label' => 'Últimas novidades', 'news_title' => 'O próximo capítulo<br><em>começa aqui.</em>',
        'notice' => 'Aviso', 'community' => 'Comunidade', 'guide' => 'Guia',
        'card1_title' => 'Os portões abrirão em breve', 'card1_text' => 'Acompanhe a preparação do servidor e todas as novidades da estreia.',
        'card2_title' => 'Entre para a nossa comunidade', 'card2_text' => 'Conheça outros aventureiros, forme seu grupo e participe das conversas.',
        'card3_title' => 'Prepare-se para a jornada', 'card3_text' => 'Crie sua conta agora e deixe tudo pronto para o seu primeiro dia.', 'read' => 'Ler mais',
        'class_label' => 'Escolha o seu caminho', 'class_title' => 'Toda grande história<br>começa com uma <em>escolha.</em>',
        'class_copy' => 'Encontre o estilo que combina com você. Proteja seus aliados, domine a magia, ataque de longe ou mantenha o grupo de pé.',
        'swordsman' => 'Espadachim', 'mage' => 'Mago', 'archer' => 'Arqueiro', 'acolyte' => 'Acolito',
        'download_label' => 'A aventura espera por você', 'download_title' => 'Seu próximo capítulo<br><em>está quase pronto.</em>',
        'download_copy' => 'Baixe o launcher, crie sua conta e prepare-se para entrar em Chronicles.', 'download_button' => 'Baixar launcher', 'download_soon' => 'Download disponível em breve',
        'discord_button' => 'Entrar no Discord', 'modal_label' => 'Comece sua jornada', 'modal_title' => 'Crie sua <em>conta.</em>',
        'modal_copy' => 'Escolha suas credenciais para começar a sua história.', 'username' => 'Nome de usuário', 'email' => 'E-mail', 'password' => 'Senha', 'confirm' => 'Confirmar senha',
        'terms' => 'Li e aceito os termos da comunidade.', 'submit' => 'Criar minha conta', 'close' => 'Fechar', 'form_notice' => 'O cadastro será ativado quando a API de contas estiver configurada.',
        'footer' => 'Uma nova aventura começa em breve.',
        'check' => 'Verificar', 'checking' => 'Verificando…', 'name_available' => 'Nome disponível.',
        'name_taken' => 'Esse nome já está em uso, escolha outro.', 'name_invalid' => 'Use 3–24 letras, números ou _.',
        'google_button' => 'Entrar com Google', 'or_divider' => 'ou cadastre-se com usuário e senha',
        'password_mismatch' => 'As senhas não coincidem.',
        'register_success' => 'Conta criada! Baixe o launcher e entre com esse usuário e senha.',
        'register_error_generic' => 'Não foi possível criar a conta agora. Tente de novo em instantes.',
        'register_error_disabled' => 'Cadastro ainda não está disponível.',
        'google_error' => 'Não foi possível confirmar sua conta Google. Tente de novo ou cadastre-se com usuário e senha.',
    ],
    'en' => [
        'nav_home' => 'Home', 'nav_news' => 'News', 'nav_classes' => 'Classes', 'nav_download' => 'Download', 'account' => 'Login',
        'eyebrow' => 'The old world is calling', 'hero_title' => 'The story is<br><em>not over yet.</em>',
        'hero_copy' => 'Gather your friends, choose your path, and live an adventure made to be remembered.', 'play' => 'Start now', 'discover' => 'Discover Chronicles',
        'online' => 'Server online', 'news_label' => 'Latest news', 'news_title' => 'The next chapter<br><em>starts here.</em>',
        'notice' => 'Notice', 'community' => 'Community', 'guide' => 'Guide',
        'card1_title' => 'The gates will open soon', 'card1_text' => 'Follow the server preparations and every opening-day update.',
        'card2_title' => 'Join our community', 'card2_text' => 'Meet other adventurers, form your party, and join the conversation.',
        'card3_title' => 'Prepare for the journey', 'card3_text' => 'Create your account now and be ready for your first day.', 'read' => 'Read more',
        'class_label' => 'Choose your path', 'class_title' => 'Every great story<br>begins with a <em>choice.</em>',
        'class_copy' => 'Find the style that suits you. Protect allies, master magic, strike from afar, or keep the party standing.',
        'swordsman' => 'Swordsman', 'mage' => 'Mage', 'archer' => 'Archer', 'acolyte' => 'Acolyte',
        'download_label' => 'Adventure is waiting', 'download_title' => 'Your next chapter<br><em>is almost ready.</em>',
        'download_copy' => 'Download the launcher, create your account, and get ready to enter Chronicles.', 'download_button' => 'Download launcher', 'download_soon' => 'Download available soon',
        'discord_button' => 'Join Discord', 'modal_label' => 'Begin your journey', 'modal_title' => 'Create your <em>account.</em>',
        'modal_copy' => 'Choose your credentials to begin your story.', 'username' => 'Username', 'email' => 'Email', 'password' => 'Password', 'confirm' => 'Confirm password',
        'terms' => 'I have read and accept the community terms.', 'submit' => 'Create my account', 'close' => 'Close', 'form_notice' => 'Registration will be enabled when the account API is configured.',
        'footer' => 'A new adventure begins soon.',
        'check' => 'Check', 'checking' => 'Checking…', 'name_available' => 'Name available.',
        'name_taken' => 'That name is already taken, choose another.', 'name_invalid' => 'Use 3–24 letters, numbers or _.',
        'google_button' => 'Sign in with Google', 'or_divider' => 'or sign up with a username and password',
        'password_mismatch' => 'Passwords do not match.',
        'register_success' => 'Account created! Download the launcher and log in with this username and password.',
        'register_error_generic' => "Couldn't create the account right now. Try again in a moment.",
        'register_error_disabled' => 'Registration is not available yet.',
        'google_error' => "Couldn't confirm your Google account. Try again or sign up with a username and password.",
    ],
    'es' => [
        'nav_home' => 'Inicio', 'nav_news' => 'Novedades', 'nav_classes' => 'Clases', 'nav_download' => 'Descargar', 'account' => 'Login',
        'eyebrow' => 'El viejo mundo te llama', 'hero_title' => 'La historia aún<br><em>no ha terminado.</em>',
        'hero_copy' => 'Reúne a tus amigos, elige tu camino y vive una aventura hecha para ser recordada.', 'play' => 'Empezar ahora', 'discover' => 'Descubrir Chronicles',
        'online' => 'Servidor en línea', 'news_label' => 'Últimas novedades', 'news_title' => 'El próximo capítulo<br><em>empieza aquí.</em>',
        'notice' => 'Aviso', 'community' => 'Comunidad', 'guide' => 'Guía',
        'card1_title' => 'Las puertas abrirán pronto', 'card1_text' => 'Sigue la preparación del servidor y todas las novedades de la apertura.',
        'card2_title' => 'Únete a nuestra comunidad', 'card2_text' => 'Conoce a otros aventureros, forma tu grupo y participa en las conversaciones.',
        'card3_title' => 'Prepárate para la jornada', 'card3_text' => 'Crea tu cuenta ahora y deja todo listo para tu primer día.', 'read' => 'Leer más',
        'class_label' => 'Elige tu camino', 'class_title' => 'Toda gran historia<br>comienza con una <em>elección.</em>',
        'class_copy' => 'Encuentra el estilo ideal para ti. Protege aliados, domina la magia, ataca a distancia o mantén al grupo en pie.',
        'swordsman' => 'Espadachín', 'mage' => 'Mago', 'archer' => 'Arquero', 'acolyte' => 'Acólito',
        'download_label' => 'La aventura te espera', 'download_title' => 'Tu próximo capítulo<br><em>está casi listo.</em>',
        'download_copy' => 'Descarga el launcher, crea tu cuenta y prepárate para entrar en Chronicles.', 'download_button' => 'Descargar launcher', 'download_soon' => 'Descarga disponible pronto',
        'discord_button' => 'Unirse a Discord', 'modal_label' => 'Comienza tu jornada', 'modal_title' => 'Crea tu <em>cuenta.</em>',
        'modal_copy' => 'Elige tus credenciales para comenzar tu historia.', 'username' => 'Nombre de usuario', 'email' => 'Correo electrónico', 'password' => 'Contraseña', 'confirm' => 'Confirmar contraseña',
        'terms' => 'He leído y acepto los términos de la comunidad.', 'submit' => 'Crear mi cuenta', 'close' => 'Cerrar', 'form_notice' => 'El registro se activará cuando se configure la API de cuentas.',
        'footer' => 'Una nueva aventura comienza pronto.',
        'check' => 'Verificar', 'checking' => 'Verificando…', 'name_available' => 'Nombre disponible.',
        'name_taken' => 'Ese nombre ya está en uso, elige otro.', 'name_invalid' => 'Usa 3–24 letras, números o _.',
        'google_button' => 'Iniciar sesión con Google', 'or_divider' => 'o regístrate con usuario y contraseña',
        'password_mismatch' => 'Las contraseñas no coinciden.',
        'register_success' => 'Cuenta creada. Descarga el launcher e inicia sesión con este usuario y contraseña.',
        'register_error_generic' => 'No se pudo crear la cuenta ahora. Inténtalo de nuevo en un momento.',
        'register_error_disabled' => 'El registro todavía no está disponible.',
        'google_error' => 'No se pudo confirmar tu cuenta de Google. Inténtalo de nuevo o regístrate con usuario y contraseña.',
    ],
][$language];

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
    <meta name="description" content="Chronicles — <?= h(strip_tags($copy['hero_title'])) ?>">
    <title>Chronicles</title>
    <link rel="preload" as="image" href="assets/client/hero-journey.png">
    <link rel="stylesheet" href="assets/css/site.css?v=<?= h($assetVersion) ?>">
</head>
<body>
    <div class="cursor-glow" aria-hidden="true"></div>
    <header class="site-header">
        <a class="logo" href="#home" aria-label="Chronicles"><span class="logo-wing">✦</span><span class="logo-type"><strong class="brand-word">Chronicles</strong></span></a>
        <button class="menu-toggle" type="button" aria-controls="site-nav" aria-expanded="false"><span></span><span></span></button>
        <nav id="site-nav">
            <a class="active" href="#home"><?= h($copy['nav_home']) ?></a>
            <a href="#news"><?= h($copy['nav_news']) ?></a>
            <a href="#classes"><?= h($copy['nav_classes']) ?></a>
            <a href="#download"><?= h($copy['nav_download']) ?></a>
            <details class="language-menu mobile-language-menu">
                <summary aria-label="<?= h($languageMenuLabel) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"></circle><path d="M3.8 12h16.4M12 3.5c2.1 2.3 3.2 5.1 3.2 8.5S14.1 18.2 12 20.5M12 3.5C9.9 5.8 8.8 8.6 8.8 12s1.1 6.2 3.2 8.5"></path></svg><span><?= h(strtoupper($language)) ?></span><i aria-hidden="true"></i></summary>
                <div class="language-options"><?php foreach ($languages as $code => $label): ?><a class="<?= $code === $language ? 'selected' : '' ?>" href="?lang=<?= h($code) ?>" lang="<?= h($code) ?>" hreflang="<?= h($code) ?>"<?= $code === $language ? ' aria-current="page"' : '' ?>><span><?= h(strtoupper($code)) ?></span><strong><?= h($label) ?></strong><b aria-hidden="true"><?= $code === $language ? '✓' : '' ?></b></a><?php endforeach; ?></div>
            </details>
        </nav>
        <div class="header-actions">
            <details class="language-menu">
                <summary aria-label="<?= h($languageMenuLabel) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"></circle><path d="M3.8 12h16.4M12 3.5c2.1 2.3 3.2 5.1 3.2 8.5S14.1 18.2 12 20.5M12 3.5C9.9 5.8 8.8 8.6 8.8 12s1.1 6.2 3.2 8.5"></path></svg><span><?= h(strtoupper($language)) ?></span><i aria-hidden="true"></i></summary>
                <div class="language-options"><?php foreach ($languages as $code => $label): ?><a class="<?= $code === $language ? 'selected' : '' ?>" href="?lang=<?= h($code) ?>" lang="<?= h($code) ?>" hreflang="<?= h($code) ?>"<?= $code === $language ? ' aria-current="page"' : '' ?>><span><?= h(strtoupper($code)) ?></span><strong><?= h($label) ?></strong><b aria-hidden="true"><?= $code === $language ? '✓' : '' ?></b></a><?php endforeach; ?></div>
            </details>
            <button class="fantasy-button small" type="button" data-open-account><span><?= h($copy['account']) ?></span></button>
        </div>
    </header>

    <main>
        <section id="home" class="hero">
            <div class="hero-scene" aria-hidden="true"></div><div class="hero-mist" aria-hidden="true"></div>
            <div class="particles" aria-hidden="true"><?php for ($i = 0; $i < 12; $i++): ?><i></i><?php endfor; ?></div>
            <div class="hero-content container reveal">
                <div class="status"><i></i><?= h($copy['online']) ?></div>
                <p class="eyebrow"><?= h($copy['eyebrow']) ?></p>
                <h1><?= $copy['hero_title'] ?></h1>
                <p class="hero-lead"><?= h($copy['hero_copy']) ?></p>
                <div class="hero-actions"><button class="fantasy-button" type="button" data-open-account><span class="button-label"><?= h($copy['play']) ?></span><b>→</b></button><a class="glass-button" href="#news"><span class="button-label"><?= h($copy['discover']) ?></span><b>↓</b></a></div>
            </div>
            <a class="scroll" href="#news"><span></span>Scroll</a>
            <div class="hero-bottom" aria-hidden="true"></div>
        </section>

        <section id="news" class="news-section section">
            <div class="container"><div class="section-heading reveal"><div><p class="eyebrow"><?= h($copy['news_label']) ?></p><h2><?= $copy['news_title'] ?></h2></div><a class="all-news" href="<?= h($config['discord_url']) ?>" target="_blank" rel="noopener noreferrer">Discord <span>↗</span></a></div>
                <div class="news-grid">
                    <article class="news-card reveal"><span class="card-tag blue"><?= h($copy['notice']) ?></span><div class="card-character"><img src="assets/client/head_job_icon_novice_01.png" alt="" loading="lazy"></div><h3><?= h($copy['card1_title']) ?></h3><p><?= h($copy['card1_text']) ?></p><a href="#download"><?= h($copy['read']) ?> <span>→</span></a></article>
                    <article class="news-card featured reveal"><span class="card-tag gold"><?= h($copy['community']) ?></span><div class="card-character"><img src="assets/client/head_job_icon_merchant_01.png" alt="" loading="lazy"></div><h3><?= h($copy['card2_title']) ?></h3><p><?= h($copy['card2_text']) ?></p><a href="<?= h($config['discord_url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($copy['read']) ?> <span>→</span></a></article>
                    <article class="news-card reveal"><span class="card-tag green"><?= h($copy['guide']) ?></span><div class="card-character"><img src="assets/client/head_job_icon_thief_01.png" alt="" loading="lazy"></div><h3><?= h($copy['card3_title']) ?></h3><p><?= h($copy['card3_text']) ?></p><button type="button" data-open-account><?= h($copy['read']) ?> <span>→</span></button></article>
                </div>
            </div>
        </section>

        <section id="classes" class="classes-section section">
            <div class="class-orbit" aria-hidden="true"></div>
            <div class="container class-layout">
                <div class="class-copy reveal">
                    <p class="eyebrow light"><?= h($copy['class_label']) ?></p><h2><?= $copy['class_title'] ?></h2><p><?= h($copy['class_copy']) ?></p>
                    <div class="class-tabs" role="list">
                        <button class="active" type="button" data-class="swordsman" data-class-image="assets/client/class-swordman.png" data-class-label="<?= h($copy['swordsman']) ?>"><img src="assets/client/head_job_icon_swordman_01.png" alt=""><span><?= h($copy['swordsman']) ?></span></button>
                        <button type="button" data-class="mage" data-class-image="assets/client/class-magician.png" data-class-label="<?= h($copy['mage']) ?>"><img src="assets/client/head_job_icon_magician_01.png" alt=""><span><?= h($copy['mage']) ?></span></button>
                        <button type="button" data-class="archer" data-class-image="assets/client/class-archer.png" data-class-label="<?= h($copy['archer']) ?>"><img src="assets/client/head_job_icon_archer_01.png" alt=""><span><?= h($copy['archer']) ?></span></button>
                        <button type="button" data-class="acolyte" data-class-image="assets/client/class-acolyte.png" data-class-label="<?= h($copy['acolyte']) ?>"><img src="assets/client/head_job_icon_acolyte_01.png" alt=""><span><?= h($copy['acolyte']) ?></span></button>
                    </div>
                </div>
                <div class="class-art reveal" aria-live="polite">
                    <div class="class-halo" aria-hidden="true"></div>
                    <div class="class-stage"><span class="stage-rune" aria-hidden="true">✦</span><img src="assets/client/class-swordman.png" alt="<?= h($copy['swordsman']) ?>" data-class-portrait><div class="class-nameplate"><strong data-class-title><?= h($copy['swordsman']) ?></strong><small>Chronicles</small></div></div>
                </div>
            </div>
        </section>

        <section id="download" class="download-section section"><div class="download-bg" aria-hidden="true"></div><div class="download-stars" aria-hidden="true">✦　·　✧</div><div class="download-content container reveal"><p class="eyebrow light"><?= h($copy['download_label']) ?></p><h2><?= $copy['download_title'] ?></h2><p><?= h($copy['download_copy']) ?></p><div class="download-actions"><?php if ($config['download_url'] !== ''): ?><a class="fantasy-button" href="<?= h($config['download_url']) ?>"><span class="button-label"><?= h($copy['download_button']) ?></span><b>↓</b></a><?php else: ?><span class="soon-pill"><?= h($copy['download_soon']) ?></span><?php endif; ?><a class="glass-button" href="<?= h($config['discord_url']) ?>" target="_blank" rel="noopener noreferrer"><span class="button-label"><?= h($copy['discord_button']) ?></span><b>↗</b></a></div></div></section>
    </main>

    <footer class="footer container"><a class="logo dark" href="#home"><span class="logo-wing">✦</span><span class="logo-type"><strong class="brand-word">Chronicles</strong></span></a><p>© <?= date('Y') ?> Chronicles. <?= h($copy['footer']) ?></p><div class="footer-links"><a href="#news"><?= h($copy['nav_news']) ?></a><a href="#download"><?= h($copy['nav_download']) ?></a></div></footer>

    <dialog class="account-modal" aria-labelledby="account-title"<?= $openAccountModal ? ' data-auto-open' : '' ?>><div class="modal-glow" aria-hidden="true"></div><button class="modal-close" type="button" data-close-account aria-label="<?= h($copy['close']) ?>">×</button><div class="modal-crest">✦</div><p class="eyebrow"><?= h($copy['modal_label']) ?></p><h2 id="account-title"><?= $copy['modal_title'] ?></h2><p class="modal-copy"><?= h($copy['modal_copy']) ?></p>
    <?php if ($config['google_client_id'] !== '' && $config['google_redirect_uri'] !== ''): ?>
    <a class="glass-button google-button" href="auth/google.php"><span class="button-label"><?= h($copy['google_button']) ?></span></a>
    <p class="or-divider"><?= h($copy['or_divider']) ?></p>
    <?php endif; ?>
    <?php if ($googleError): ?>
    <p class="form-message status-error"><?= h($copy['google_error']) ?></p>
    <?php endif; ?>
    <form method="post" data-account-ready="<?= $config['accounts_api'] !== '' ? 'true' : 'false' ?>" data-register-url="api/register.php" data-check-url="api/check-username.php">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        <label><?= h($copy['username']) ?><span class="field-with-action"><input name="username" autocomplete="username" minlength="3" maxlength="24" pattern="[A-Za-z0-9_]{3,24}" value="<?= $prefillEmail !== '' ? h(explode('@', $prefillEmail)[0]) : '' ?>" required><button type="button" class="cream small" data-check-username><?= h($copy['check']) ?></button></span></label>
        <p class="username-status" data-username-status role="status"></p>
        <label><?= h($copy['email']) ?><span><input name="email" type="email" autocomplete="email" value="<?= h($prefillEmail) ?>" required></span></label>
        <div class="form-row"><label><?= h($copy['password']) ?><span><input name="password" type="password" autocomplete="new-password" minlength="8" required></span></label><label><?= h($copy['confirm']) ?><span><input name="password_confirmation" type="password" autocomplete="new-password" minlength="8" required></span></label></div>
        <label class="terms"><input type="checkbox" required><i></i><span><?= h($copy['terms']) ?></span></label>
        <button class="fantasy-button modal-submit" type="submit"><span><?= h($copy['submit']) ?></span><b>→</b></button>
        <p class="form-message" data-form-message role="status"></p>
        <p class="form-note" role="status"><?= h($copy['form_notice']) ?></p>
    </form></dialog>
    <script>window.CHRONICLES_I18N = <?= json_encode([
        'check' => $copy['check'], 'checking' => $copy['checking'], 'nameAvailable' => $copy['name_available'],
        'nameTaken' => $copy['name_taken'], 'nameInvalid' => $copy['name_invalid'], 'passwordMismatch' => $copy['password_mismatch'],
        'registerSuccess' => $copy['register_success'], 'registerErrorGeneric' => $copy['register_error_generic'],
        'registerErrorDisabled' => $copy['register_error_disabled'],
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
    <script src="assets/vendor/gsap/gsap.min.js?v=3.15.0"></script>
    <script src="assets/vendor/gsap/ScrollTrigger.min.js?v=3.15.0"></script>
    <script src="assets/js/site.js?v=<?= h($assetVersion) ?>"></script>
</body>
</html>
