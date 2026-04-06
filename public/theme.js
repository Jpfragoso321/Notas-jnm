(function () {
    const STORAGE_KEY = 'portal_ecmnm_theme';
    const root = document.documentElement;

    const getPreferred = () => {
        const saved = localStorage.getItem(STORAGE_KEY);
        if (saved === 'light' || saved === 'dark') {
            return saved;
        }

        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches
            ? 'dark'
            : 'light';
    };

    const apply = (theme) => {
        root.setAttribute('data-theme', theme);
        const isDark = theme === 'dark';

        const btn = document.getElementById('theme-toggle-btn');
        if (btn) {
            btn.setAttribute('aria-label', isDark ? 'Ativar modo claro' : 'Ativar modo escuro');
            btn.setAttribute('title', isDark ? 'Modo claro' : 'Modo escuro');
            btn.innerHTML = isDark ? '<span aria-hidden="true">☀</span><span>Tema</span>' : '<span aria-hidden="true">🌙</span><span>Tema</span>';
        }
    };

    const toggleTheme = () => {
        const current = root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
        const next = current === 'dark' ? 'light' : 'dark';
        localStorage.setItem(STORAGE_KEY, next);
        apply(next);
    };

    const renderButton = () => {
        if (document.getElementById('theme-toggle-btn')) {
            return;
        }

        const button = document.createElement('button');
        button.type = 'button';
        button.id = 'theme-toggle-btn';
        button.className = 'btn btn-secondary theme-toggle-btn';
        button.addEventListener('click', toggleTheme);

        const row = document.querySelector('.topbar .actions-row');
        if (row) {
            row.prepend(button);
        } else {
            button.classList.add('theme-toggle-floating');
            document.body.appendChild(button);
        }

        apply(getPreferred());
    };

    document.addEventListener('DOMContentLoaded', () => {
        apply(getPreferred());
        renderButton();
    });
})();
