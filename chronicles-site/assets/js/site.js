const menuButton = document.querySelector('.menu-toggle');
const navigation = document.querySelector('.site-header nav');
const accountModal = document.querySelector('.account-modal');
const glow = document.querySelector('.cursor-glow');
const languageMenus = document.querySelectorAll('.language-menu');

function framePath(context, width, height, inset) {
    const left = inset;
    const right = width - inset;
    const top = inset;
    const bottom = height - inset;

    context.beginPath();
    context.rect(left, top, right - left, bottom - top);
}

function drawCanvasButton(canvas, secondary, hover, shine) {
    const button = canvas.parentElement;
    const bounds = button.getBoundingClientRect();
    if (bounds.width < 1 || bounds.height < 1) return;

    const ratio = Math.min(window.devicePixelRatio || 1, 2.5);
    const pixelWidth = Math.round(bounds.width * ratio);
    const pixelHeight = Math.round(bounds.height * ratio);
    if (canvas.width !== pixelWidth || canvas.height !== pixelHeight) {
        canvas.width = pixelWidth;
        canvas.height = pixelHeight;
    }

    const context = canvas.getContext('2d');
    const width = bounds.width;
    const height = bounds.height;
    const palette = secondary
        ? { top: '#ffffff', middle: '#e9f4fa', bottom: '#b4d3e6' }
        : { top: '#1686cb', middle: '#07519a', bottom: '#052759' };

    context.setTransform(ratio, 0, 0, ratio, 0, 0);
    context.clearRect(0, 0, width, height);
    context.save();

    context.shadowColor = secondary ? 'rgba(22, 74, 111, .28)' : 'rgba(3, 30, 74, .48)';
    context.shadowBlur = 7 + hover * 6;
    context.shadowOffsetY = 4;
    framePath(context, width, height, 3);
    const face = context.createLinearGradient(0, 3, 0, height - 3);
    face.addColorStop(0, palette.top);
    face.addColorStop(0.2, palette.middle);
    face.addColorStop(0.72, secondary ? '#d7ebf7' : '#06377f');
    face.addColorStop(1, palette.bottom);
    context.fillStyle = face;
    context.fill();
    context.shadowColor = 'transparent';

    context.save();
    framePath(context, width, height, 3);
    context.clip();
    const glowGradient = context.createRadialGradient(width * 0.5, height * 0.05, 0, width * 0.5, height * 0.05, width * 0.5);
    glowGradient.addColorStop(0, secondary ? `rgba(255,255,255,${0.52 + hover * 0.16})` : `rgba(83,217,255,${0.2 + hover * 0.15})`);
    glowGradient.addColorStop(1, 'rgba(255,255,255,0)');
    context.fillStyle = glowGradient;
    context.fillRect(0, 0, width, height);

    const lowerShade = context.createLinearGradient(0, height * 0.55, 0, height);
    lowerShade.addColorStop(0, 'rgba(0,0,0,0)');
    lowerShade.addColorStop(1, secondary ? 'rgba(27,72,105,.18)' : 'rgba(0,8,30,.48)');
    context.fillStyle = lowerShade;
    context.fillRect(0, height * 0.5, width, height * 0.5);

    if (shine >= 0 && shine <= 1) {
        const shineX = -width * 0.2 + shine * width * 1.4;
        const gleam = context.createLinearGradient(shineX - 32, 0, shineX + 32, 0);
        gleam.addColorStop(0, 'rgba(255,255,255,0)');
        gleam.addColorStop(0.5, secondary ? 'rgba(255,255,255,.58)' : 'rgba(185,239,255,.35)');
        gleam.addColorStop(1, 'rgba(255,255,255,0)');
        context.fillStyle = gleam;
        context.transform(1, 0, -0.34, 1, height * 0.2, 0);
        context.fillRect(shineX - 34, 0, 68, height);
    }
    context.restore();

    context.strokeStyle = secondary ? 'rgba(255,255,255,.82)' : 'rgba(187,242,255,.58)';
    context.lineWidth = 0.8;
    context.beginPath();
    context.moveTo(23, 8);
    context.lineTo(width - 23, 8);
    context.stroke();

    context.strokeStyle = secondary ? 'rgba(31,97,139,.28)' : 'rgba(103,214,255,.22)';
    context.lineWidth = 0.8;
    context.beginPath();
    context.moveTo(26, height - 8);
    context.lineTo(width - 26, height - 8);
    context.stroke();

    context.restore();
}

const canvasButtons = document.querySelectorAll('.fantasy-button, .glass-button');
const buttonRenderers = [];

canvasButtons.forEach((button) => {
    const canvas = document.createElement('canvas');
    canvas.className = 'button-canvas';
    canvas.setAttribute('aria-hidden', 'true');
    button.prepend(canvas);
    button.classList.add('canvas-ready');

    let hover = 0;
    let target = 0;
    let shineStarted = null;
    let animationFrame = 0;
    const secondary = button.classList.contains('glass-button');

    const render = (time = performance.now()) => {
        hover += (target - hover) * 0.16;
        const shine = shineStarted === null ? -1 : (time - shineStarted) / 720;
        drawCanvasButton(canvas, secondary, hover, shine);
        const changing = Math.abs(target - hover) > 0.01;
        const shining = shineStarted !== null && shine <= 1;
        if (changing || shining) animationFrame = requestAnimationFrame(render);
        else animationFrame = 0;
    };

    const requestRender = () => {
        if (!animationFrame) animationFrame = requestAnimationFrame(render);
    };

    button.addEventListener('pointerenter', () => {
        target = 1;
        shineStarted = performance.now();
        requestRender();
    });
    button.addEventListener('pointerleave', () => {
        target = 0;
        shineStarted = null;
        requestRender();
    });
    buttonRenderers.push({ button, render: requestRender });
    requestRender();
});

if ('ResizeObserver' in window) {
    const buttonResizeObserver = new ResizeObserver((entries) => {
        entries.forEach((entry) => buttonRenderers.find(({ button }) => button === entry.target)?.render());
    });
    buttonRenderers.forEach(({ button }) => buttonResizeObserver.observe(button));
} else {
    window.addEventListener('resize', () => buttonRenderers.forEach(({ render }) => render()));
}

{
    const ambientOrbs = document.createElement('div');
    ambientOrbs.className = 'ambient-orbs';
    ambientOrbs.setAttribute('aria-hidden', 'true');
    for (let index = 0; index < 24; index += 1) {
        const orb = document.createElement('i');
        orb.style.setProperty('--orb-x', `${(index * 37 + 7) % 100}%`);
        orb.style.setProperty('--orb-size', `${3 + (index % 5) * 1.35}px`);
        orb.style.setProperty('--orb-speed', `${9 + (index % 7) * 1.45}s`);
        orb.style.setProperty('--orb-delay', `${-((index * 1.73) % 14)}s`);
        orb.style.setProperty('--orb-opacity', `${0.28 + (index % 4) * 0.11}`);
        orb.style.setProperty('--orb-drift', `${((index % 7) - 3) * 16}px`);
        ambientOrbs.append(orb);
    }
    document.body.append(ambientOrbs);
}

menuButton?.addEventListener('click', () => {
    const isOpen = navigation.classList.toggle('open');
    menuButton.setAttribute('aria-expanded', String(isOpen));
});

navigation?.querySelectorAll('a').forEach((link) => {
    link.addEventListener('click', () => {
        navigation.classList.remove('open');
        menuButton?.setAttribute('aria-expanded', 'false');
    });
});

languageMenus.forEach((menu) => {
    menu.addEventListener('toggle', () => {
        if (!menu.open) return;
        languageMenus.forEach((otherMenu) => {
            if (otherMenu !== menu) otherMenu.removeAttribute('open');
        });
    });
});

document.addEventListener('click', (event) => {
    languageMenus.forEach((menu) => {
        if (!menu.contains(event.target)) menu.removeAttribute('open');
    });
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') languageMenus.forEach((menu) => menu.removeAttribute('open'));
});

document.querySelectorAll('[data-open-account]').forEach((button) => {
    button.addEventListener('click', () => {
        document.body.classList.add('modal-open');
        accountModal?.showModal();
    });
});

function closeAccountModal() {
    accountModal?.close();
    document.body.classList.remove('modal-open');
}

document.querySelector('[data-close-account]')?.addEventListener('click', closeAccountModal);
accountModal?.addEventListener('close', () => document.body.classList.remove('modal-open'));
accountModal?.addEventListener('click', (event) => {
    if (event.target === accountModal) closeAccountModal();
});

if (accountModal?.hasAttribute('data-auto-open')) {
    document.body.classList.add('modal-open');
    accountModal.showModal();
}

const registrationForm = accountModal?.querySelector('form');
const password = registrationForm?.querySelector('[name="password"]');
const confirmation = registrationForm?.querySelector('[name="password_confirmation"]');
const usernameField = registrationForm?.querySelector('[name="username"]');
const checkButton = registrationForm?.querySelector('[data-check-username]');
const usernameStatus = registrationForm?.querySelector('[data-username-status]');
const formMessage = registrationForm?.querySelector('[data-form-message]');
const i18n = window.CHRONICLES_I18N || {};
const namePattern = /^[A-Za-z0-9_]{3,24}$/;

function setStatus(element, text, kind) {
    if (!element) return;
    element.textContent = text;
    element.classList.remove('status-ok', 'status-error');
    if (kind) element.classList.add(kind);
}

async function checkUsernameAvailability() {
    const name = usernameField?.value.trim() ?? '';
    if (!namePattern.test(name)) {
        setStatus(usernameStatus, i18n.nameInvalid ?? '', 'status-error');
        return;
    }
    setStatus(usernameStatus, i18n.checking ?? '', null);
    try {
        const response = await fetch(`${registrationForm.dataset.checkUrl}?name=${encodeURIComponent(name)}`);
        const data = await response.json();
        setStatus(usernameStatus, data.available ? (i18n.nameAvailable ?? '') : (i18n.nameTaken ?? ''), data.available ? 'status-ok' : 'status-error');
    } catch {
        setStatus(usernameStatus, i18n.registerErrorGeneric ?? '', 'status-error');
    }
}

checkButton?.addEventListener('click', checkUsernameAvailability);
usernameField?.addEventListener('blur', () => { if (usernameField.value.trim()) checkUsernameAvailability(); });
usernameField?.addEventListener('input', () => setStatus(usernameStatus, '', null));

registrationForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (password?.value !== confirmation?.value) {
        confirmation.setCustomValidity(i18n.passwordMismatch ?? 'Passwords do not match.');
        confirmation.reportValidity();
        return;
    }
    confirmation?.setCustomValidity('');
    if (registrationForm.dataset.accountReady !== 'true') {
        setStatus(formMessage, i18n.registerErrorDisabled ?? '', 'status-error');
        return;
    }

    const submitButton = registrationForm.querySelector('.modal-submit');
    if (submitButton) submitButton.disabled = true;
    setStatus(formMessage, '', null);
    try {
        const response = await fetch(registrationForm.dataset.registerUrl, { method: 'POST', body: new FormData(registrationForm) });
        const data = await response.json();
        if (data.ok) {
            setStatus(formMessage, i18n.registerSuccess ?? '', 'status-ok');
            registrationForm.reset();
        } else {
            setStatus(formMessage, data.error === 'name_taken' ? (i18n.nameTaken ?? '') : (i18n.registerErrorGeneric ?? ''), 'status-error');
        }
    } catch {
        setStatus(formMessage, i18n.registerErrorGeneric ?? '', 'status-error');
    } finally {
        if (submitButton) submitButton.disabled = false;
    }
});

confirmation?.addEventListener('input', () => confirmation.setCustomValidity(''));

const classButtons = document.querySelectorAll('.class-tabs button');

classButtons.forEach((button) => {
    if (button.dataset.classImage) new Image().src = button.dataset.classImage;

    button.addEventListener('click', () => {
        document.querySelector('.class-tabs .active')?.classList.remove('active');
        button.classList.add('active');
        const art = document.querySelector('.class-art');
        const portrait = art?.querySelector('[data-class-portrait]');
        const title = art?.querySelector('[data-class-title]');
        art?.classList.add('is-switching');
        window.setTimeout(() => {
            if (portrait && button.dataset.classImage) {
                portrait.src = button.dataset.classImage;
                portrait.alt = button.dataset.classLabel ?? '';
            }
            if (title) title.textContent = button.dataset.classLabel ?? '';
        }, 150);
        window.setTimeout(() => art?.classList.remove('is-switching'), 360);
    });
});

const hero = document.querySelector('.hero');
const heroScene = document.querySelector('.hero-scene');

if (hero && heroScene) {
    hero.addEventListener('pointermove', (event) => {
        const bounds = hero.getBoundingClientRect();
        const horizontal = (event.clientX - bounds.left) / bounds.width - 0.5;
        const vertical = (event.clientY - bounds.top) / bounds.height - 0.5;
        heroScene.style.setProperty('--hero-x', `${horizontal * -10}px`);
        heroScene.style.setProperty('--hero-y', `${vertical * -7}px`);
    });

    hero.addEventListener('pointerleave', () => {
        heroScene.style.setProperty('--hero-x', '0px');
        heroScene.style.setProperty('--hero-y', '0px');
    });
}

function initializeScrollAnimations() {
    const gsap = window.gsap;
    const ScrollTrigger = window.ScrollTrigger;
    if (!gsap || !ScrollTrigger) return;

    gsap.registerPlugin(ScrollTrigger);
    document.documentElement.classList.add('motion-gsap-ready');
    const motionElements = gsap.utils.toArray('.reveal');
    motionElements.forEach((element) => element.classList.add('gsap-motion'));

    gsap.fromTo('.hero-content', {
        '--reveal-y': '48px', autoAlpha: 0, filter: 'blur(10px)',
    }, {
        '--reveal-y': '0px', autoAlpha: 1, filter: 'blur(0px)', duration: 1.35, delay: 0.12, ease: 'power3.out',
    });

    gsap.fromTo('.section-heading', {
        '--reveal-y': '80px', autoAlpha: 0, filter: 'blur(12px)',
    }, {
        '--reveal-y': '0px', autoAlpha: 1, filter: 'blur(0px)', duration: 1.1, ease: 'power4.out',
        scrollTrigger: { trigger: '.section-heading', start: 'top 82%', toggleActions: 'play none none reverse' },
    });

    const newsCards = gsap.utils.toArray('.news-card');
    newsCards.forEach((card) => card.classList.add('gsap-motion'));
    gsap.fromTo(newsCards, {
        '--reveal-y': '105px', autoAlpha: 0, filter: 'blur(9px)', clipPath: 'inset(12% 0 0 0 round 15px)',
    }, {
        '--reveal-y': '0px', autoAlpha: 1, filter: 'blur(0px)', clipPath: 'inset(0% 0 0 0 round 15px)',
        duration: 1.05, stagger: 0.16, ease: 'power4.out',
        scrollTrigger: { trigger: '.news-grid', start: 'top 80%', toggleActions: 'play none none reverse' },
    });

    const lateralDistance = window.innerWidth < 700 ? 0 : 115;
    gsap.fromTo('.class-copy', {
        '--reveal-x': `${-lateralDistance}px`, autoAlpha: 0, filter: 'blur(10px)',
    }, {
        '--reveal-x': '0px', autoAlpha: 1, filter: 'blur(0px)', duration: 1.15, ease: 'power4.out',
        scrollTrigger: { trigger: '.classes-section', start: 'top 68%', toggleActions: 'play none none reverse' },
    });
    gsap.fromTo('.class-art', {
        '--reveal-x': `${lateralDistance}px`, autoAlpha: 0, filter: 'blur(10px)',
    }, {
        '--reveal-x': '0px', autoAlpha: 1, filter: 'blur(0px)', duration: 1.25, delay: 0.08, ease: 'power4.out',
        scrollTrigger: { trigger: '.classes-section', start: 'top 68%', toggleActions: 'play none none reverse' },
    });

    gsap.fromTo('.download-content', {
        '--reveal-y': '100px', autoAlpha: 0, filter: 'blur(12px)',
    }, {
        '--reveal-y': '0px', autoAlpha: 1, filter: 'blur(0px)', duration: 1.2, ease: 'power4.out',
        scrollTrigger: { trigger: '.download-section', start: 'top 72%', toggleActions: 'play none none reverse' },
    });

    newsCards.forEach((card, index) => {
        gsap.fromTo(card, { '--parallax-y': `${18 + index * 4}px` }, {
            '--parallax-y': `${-18 - index * 4}px`, ease: 'none',
            scrollTrigger: { trigger: '.news-section', start: 'top bottom', end: 'bottom top', scrub: 0.8 },
        });
    });
    gsap.fromTo('.class-copy', { '--parallax-y': '24px' }, {
        '--parallax-y': '-24px', ease: 'none',
        scrollTrigger: { trigger: '.classes-section', start: 'top bottom', end: 'bottom top', scrub: 1 },
    });
    gsap.fromTo('.class-art', { '--parallax-y': '34px' }, {
        '--parallax-y': '-34px', ease: 'none',
        scrollTrigger: { trigger: '.classes-section', start: 'top bottom', end: 'bottom top', scrub: 1.1 },
    });
    gsap.fromTo('.hero-scene', { '--hero-scroll-y': '0px' }, {
        '--hero-scroll-y': '72px', ease: 'none',
        scrollTrigger: { trigger: '.hero', start: 'top top', end: 'bottom top', scrub: 1.1 },
    });
    gsap.fromTo('.class-orbit', { '--orbit-shift': '-34px' }, {
        '--orbit-shift': '34px', ease: 'none',
        scrollTrigger: { trigger: '.classes-section', start: 'top bottom', end: 'bottom top', scrub: 1.2 },
    });
    gsap.fromTo('.download-bg', { '--download-shift': '-42px' }, {
        '--download-shift': '42px', ease: 'none',
        scrollTrigger: { trigger: '.download-section', start: 'top bottom', end: 'bottom top', scrub: 1.1 },
    });

    window.addEventListener('load', () => ScrollTrigger.refresh(), { once: true });
}

initializeScrollAnimations();

const navigationLinks = document.querySelectorAll('.site-header nav > a');
const pageSections = [...navigationLinks]
    .map((link) => document.querySelector(link.getAttribute('href')))
    .filter(Boolean);

const sectionObserver = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        navigationLinks.forEach((link) => {
            link.classList.toggle('active', link.getAttribute('href') === `#${entry.target.id}`);
        });
    });
}, { rootMargin: '-38% 0px -54%', threshold: 0 });

pageSections.forEach((section) => sectionObserver.observe(section));

window.addEventListener('pointermove', (event) => {
    if (glow) {
        glow.style.opacity = '1';
        glow.style.left = `${event.clientX}px`;
        glow.style.top = `${event.clientY}px`;
    }
});
