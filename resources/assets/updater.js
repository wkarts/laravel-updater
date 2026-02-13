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

    const progressFill = document.getElementById('update-progress-fill');
    const progressMessage = document.getElementById('update-progress-message');
    const progressLogs = document.getElementById('update-progress-logs');
    const updateButtons = document.querySelectorAll('[data-update-action="1"]');

    function toggleUpdateButtons(disabled) {
        updateButtons.forEach(function (btn) {
            btn.disabled = disabled;
            btn.style.opacity = disabled ? '0.65' : '1';
            btn.style.pointerEvents = disabled ? 'none' : 'auto';
        });
    }

    function renderLogs(logs) {
        if (!progressLogs) {
            return;
        }

        progressLogs.innerHTML = '';
        (logs || []).slice(-8).forEach(function (log) {
            const li = document.createElement('li');
            li.textContent = '[' + (log.level || 'info') + '] ' + (log.message || '');
            progressLogs.appendChild(li);
        });
    }

    function resolveProgressUrl() {
        if (typeof window.UPDATER_UPDATE_PROGRESS_URL === 'string' && window.UPDATER_UPDATE_PROGRESS_URL !== '') {
            return window.UPDATER_UPDATE_PROGRESS_URL;
        }

        const prefix = (window.UPDATER_PREFIX || '_updater').replace(/^\/+|\/+$/g, '');
        return window.location.origin + '/' + prefix + '/updates/progress/status';
    }

    function pollProgress() {
        if (!progressFill || !progressMessage) {
            return;
        }

        const progressUrl = resolveProgressUrl();
        if (!progressUrl) {
            return;
        }

        fetch(progressUrl, { credentials: 'same-origin' })
            .then(function (res) {
                if (!res.ok) {
                    throw new Error('Falha ao consultar progresso');
                }

                return res.json();
            })
            .then(function (data) {
                const progress = Number(data.progress || 0);
                progressFill.style.width = Math.max(0, Math.min(100, progress)) + '%';
                progressMessage.textContent = data.message || 'Aguardando execução.';
                renderLogs(data.logs || []);
                toggleUpdateButtons(Boolean(data.active));
            })
            .catch(function () {});
    }

    if (progressFill && progressMessage) {
        pollProgress();
        window.setInterval(pollProgress, 3000);
    }

    window.setTimeout(function () {
        document.querySelectorAll('.toast').forEach(function (toast) {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(-6px)';
        });
    }, 3500);
})();
