const body = document.body;
const menuButton = document.querySelector('.menu-toggle');
const navigation = document.querySelector('.site-header nav');
const languageMenus = document.querySelectorAll('.language-menu');
const status = document.querySelector('[data-panel-status]');
const characterGrid = document.querySelector('[data-character-grid]');
const donationCharacter = document.querySelector('[data-donation-character]');
const donationForms = document.querySelectorAll('[data-donation-form]');
const i18n = window.CHRONICLES_PANEL_I18N || {};

menuButton?.addEventListener('click', () => {
    const isOpen = navigation.classList.toggle('open');
    menuButton.setAttribute('aria-expanded', String(isOpen));
});

languageMenus.forEach((menu) => menu.addEventListener('toggle', () => {
    if (!menu.open) return;
    languageMenus.forEach((other) => { if (other !== menu) other.removeAttribute('open'); });
}));

document.addEventListener('click', (event) => {
    languageMenus.forEach((menu) => { if (!menu.contains(event.target)) menu.removeAttribute('open'); });
});

function setStatus(message, error = false) {
    if (!status) return;
    status.textContent = message;
    status.classList.toggle('status-error', error);
}

function jobIcon(careerId) {
    if (careerId === 1000) return 'head_job_icon_novice_01.png';
    const family = Math.floor(careerId / 1000);
    if (family === 1) return careerId >= 1200 ? 'head_job_icon_knight_01.png' : 'head_job_icon_swordman_01.png';
    return ({
        2: 'head_job_icon_magician_01.png',
        3: 'head_job_icon_archer_01.png',
        4: 'head_job_icon_acolyte_01.png',
        5: 'head_job_icon_thief_01.png',
        6: 'head_job_icon_merchant_01.png',
    })[family] ?? 'head_job_icon_novice_01.png';
}

function profileStat(label, value) {
    const item = document.createElement('span');
    const name = document.createElement('small');
    const number = document.createElement('strong');
    name.textContent = label;
    number.textContent = value;
    item.append(name, number);
    return item;
}

function characterCard(character) {
    const locale = document.documentElement.lang || 'pt-BR';
    const card = document.createElement('article');
    card.className = 'character-card';
    const portrait = document.createElement('img');
    portrait.src = `assets/client/${jobIcon(Number(character.careerId))}`;
    portrait.alt = '';
    const info = document.createElement('div');
    info.className = 'character-info';
    const titleRow = document.createElement('div');
    titleRow.className = 'character-title';
    const title = document.createElement('h3');
    title.textContent = character.name ?? '';
    const state = document.createElement('span');
    state.className = character.online ? 'online' : '';
    state.textContent = character.online ? (i18n.online ?? 'Online') : (i18n.offline ?? 'Offline');
    titleRow.append(title, state);
    const job = document.createElement('p');
    job.textContent = character.career ?? '';
    const stats = document.createElement('div');
    stats.className = 'character-stats';
    stats.append(
        profileStat(i18n.level ?? 'Level', String(character.level ?? 0)),
        profileStat(i18n.jobLevel ?? 'Job', String(character.jobLevel ?? 0)),
        profileStat(i18n.paragon ?? 'Paragon', String(character.paragonLevel ?? 0)),
    );
    const wallet = document.createElement('div');
    wallet.className = 'character-wallet';
    wallet.append(
        profileStat(i18n.zeny ?? 'Zeny', Number(character.zeny ?? 0).toLocaleString(locale)),
        profileStat(i18n.crystals ?? 'Crystals', Number(character.crystals ?? 0).toLocaleString(locale)),
    );
    const lastLogin = document.createElement('small');
    lastLogin.className = 'last-login';
    const timestamp = Number(character.lastLogin ?? 0);
    const date = timestamp > 0
        ? new Date(timestamp * 1000).toLocaleString(locale, { dateStyle: 'short', timeStyle: 'short' })
        : (i18n.never ?? 'Never');
    lastLogin.textContent = `${i18n.lastLogin ?? 'Last login'}: ${date}`;
    info.append(titleRow, job, stats, wallet, lastLogin);
    card.append(portrait, info);
    return card;
}

function loadDonationCharacters(characters) {
    if (!donationCharacter) return;
    const options = characters.map((character) => {
        const option = document.createElement('option');
        option.value = String(character.id);
        option.textContent = character.name ?? '';
        return option;
    });
    donationCharacter.append(...options);
    donationCharacter.disabled = characters.length === 0;
    if (characters.length === 1) donationCharacter.value = String(characters[0].id);
    const update = () => donationForms.forEach((form) => {
        const role = form.querySelector('[data-donation-role]');
        const button = form.querySelector('[data-donation-button]');
        if (role) role.value = donationCharacter.value;
        if (button) button.disabled = button.dataset.stripeEnabled !== '1' || donationCharacter.value === '';
    });
    donationCharacter.addEventListener('change', update);
    update();
}

async function loadProfile() {
    setStatus(i18n.loading ?? '');
    try {
        const response = await fetch(body.dataset.profileUrl, { headers: { Accept: 'application/json' } });
        if (response.status === 401) {
            window.location.replace(`index.php?login=1&lang=${encodeURIComponent(document.documentElement.lang)}`);
            return;
        }
        const profile = await response.json();
        if (!response.ok || !profile.ok) throw new Error('profile');
        const characters = Array.isArray(profile.characters) ? profile.characters : [];
        characterGrid?.replaceChildren(...characters.map(characterCard));
        loadDonationCharacters(characters);
        setStatus(characters.length === 0 ? (i18n.empty ?? '') : '');
    } catch {
        setStatus(i18n.error ?? '', true);
    }
}

document.querySelectorAll('[data-logout]').forEach((button) => button.addEventListener('click', async () => {
    const request = new FormData();
    request.set('csrf_token', body.dataset.csrf);
    try {
        await fetch(body.dataset.logoutUrl, { method: 'POST', body: request });
    } finally {
        window.location.assign(`index.php?lang=${encodeURIComponent(document.documentElement.lang)}`);
    }
}));

window.addEventListener('pointermove', (event) => {
    const glow = document.querySelector('.cursor-glow');
    if (!glow) return;
    glow.style.opacity = '1';
    glow.style.left = `${event.clientX}px`;
    glow.style.top = `${event.clientY}px`;
});

loadProfile();
