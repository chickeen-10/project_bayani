<script>
(() => {
    const themeKey = 'bayani.theme';
    const validThemes = ['light', 'dark', 'system'];
    const root = document.documentElement;
    const media = window.matchMedia?.('(prefers-color-scheme: dark)');

    const savedPreference = () => {
        try {
            const direct = localStorage.getItem(themeKey);
            if (validThemes.includes(direct)) return direct;
            const data = JSON.parse(localStorage.getItem('bayani.demo.v1') || 'null');
            const nested = data?.accountPreferences?.theme;
            return validThemes.includes(nested) ? nested : 'light';
        } catch (_) {
            return 'light';
        }
    };

    const apply = (preference) => {
        const selected = validThemes.includes(preference) ? preference : 'light';
        const resolved = selected === 'system' ? (media?.matches ? 'dark' : 'light') : selected;
        root.setAttribute('data-theme', resolved);
        root.setAttribute('data-bs-theme', resolved);
        root.style.colorScheme = resolved;
        return resolved;
    };

    const save = (preference) => {
        const selected = validThemes.includes(preference) ? preference : 'light';
        try { localStorage.setItem(themeKey, selected); } catch (_) { /* nested prototype preference remains the fallback */ }
        return apply(selected);
    };

    window.BayaniTheme = { apply, save, preference: savedPreference };
    apply(savedPreference());
    media?.addEventListener?.('change', () => {
        if (savedPreference() === 'system') apply('system');
    });
})();
</script>
