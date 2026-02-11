(function () {
    const key = 'updater_theme';
    const root = document.documentElement;
    const saved = localStorage.getItem(key);
    if (saved === 'dark') root.classList.add('dark');

    document.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-toggle-drawer]');
        if (btn) {
            document.body.classList.toggle('drawer-open');
        }

        const themeBtn = e.target.closest('[data-toggle-theme]');
        if (themeBtn) {
            root.classList.toggle('dark');
            localStorage.setItem(key, root.classList.contains('dark') ? 'dark' : 'light');
        }
    });

    setTimeout(function () {
        document.querySelectorAll('.toast').forEach(function (el) {
            el.style.opacity = '0';
        });
    }, 3500);
})();
