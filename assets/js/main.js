// Плавный скролл к якорям
document.querySelectorAll('a[href^="#"]').forEach((link) => {
    link.addEventListener('click', (e) => {
        const id = link.getAttribute('href');
        if (id.length < 2) return;
        const target = document.querySelector(id);
        if (!target) return;
        e.preventDefault();
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
});

// Анимация появления карточек
const observer = new IntersectionObserver(
    (entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
                observer.unobserve(entry.target);
            }
        });
    },
    { threshold: 0.12 }
);

document.querySelectorAll('.feature, .cta, .activity-card').forEach((el) => {
    el.style.opacity = '0';
    el.style.transform = 'translateY(24px)';
    el.style.transition = 'opacity .6s ease, transform .6s ease';
    observer.observe(el);
});

/* ============================================================
   HEADER: mobile menu + user dropdown
   ============================================================ */
(function () {
    'use strict';

    // ---- Бургер и мобильное меню ----
    var burger = document.getElementById('burger');
    var mobileMenu = document.getElementById('mobile-menu');
    var mobileBackdrop = document.getElementById('mobile-menu-backdrop');

    function openMobileMenu() {
        if (!mobileMenu || !burger) return;
        mobileMenu.hidden = false;
        burger.setAttribute('aria-expanded', 'true');
        document.body.style.overflow = 'hidden';
    }

    function closeMobileMenu() {
        if (!mobileMenu || !burger) return;
        mobileMenu.hidden = true;
        burger.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
    }

    if (burger) {
        burger.addEventListener('click', function () {
            if (burger.getAttribute('aria-expanded') === 'true') {
                closeMobileMenu();
            } else {
                openMobileMenu();
            }
        });
    }

    if (mobileBackdrop) {
        mobileBackdrop.addEventListener('click', closeMobileMenu);
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeMobileMenu();
    });

    // ---- User dropdown ----
    var userToggle = document.getElementById('user-menu-toggle');
    var userDropdown = document.getElementById('user-menu-dropdown');

    function closeUserMenu() {
        if (!userDropdown || !userToggle) return;
        userDropdown.hidden = true;
        userToggle.setAttribute('aria-expanded', 'false');
    }

    if (userToggle && userDropdown) {
        userToggle.addEventListener('click', function (e) {
            e.stopPropagation();
            var isOpen = !userDropdown.hidden;
            if (isOpen) {
                closeUserMenu();
            } else {
                userDropdown.hidden = false;
                userToggle.setAttribute('aria-expanded', 'true');
            }
        });

        // Клик вне меню — закрыть
        document.addEventListener('click', function (e) {
            if (!userDropdown.hidden && !userDropdown.contains(e.target) && e.target !== userToggle) {
                closeUserMenu();
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeUserMenu();
        });
    }
})();