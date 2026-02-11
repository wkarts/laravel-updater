(function () {
    const themeKey = 'updater_theme';
    const root = document.documentElement;
    const body = document.body;

    const savedTheme = localStorage.getItem(themeKey);
    if (savedTheme === 'dark') {
        root.classList.add('dark');
    }

    function closeDrawer() {
        body.classList.remove('drawer-open');
    }

    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-toggle-drawer]')) {
            body.classList.toggle('drawer-open');
            return;
        }

        if (event.target.closest('[data-close-drawer]')) {
            closeDrawer();
            return;
        }

        if (event.target.closest('[data-toggle-theme]')) {
            root.classList.toggle('dark');
            localStorage.setItem(themeKey, root.classList.contains('dark') ? 'dark' : 'light');
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeDrawer();
        }
    });

    window.addEventListener('resize', function () {
        if (window.innerWidth > 1024) {
            closeDrawer();
        }
    });

    window.setTimeout(function () {
        document.querySelectorAll('.toast').forEach(function (toast) {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(-6px)';
        });
    }, 3500);
})();
