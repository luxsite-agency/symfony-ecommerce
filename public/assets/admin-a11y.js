// Адмінка, доступність (аудит UX A23): поле пошуку tom-select (автокомпліт EasyAdmin для бренду, категорій, батьківської
// категорії) створюється без підпису — даємо йому aria-label з <label> вихідного select. Форми підвантажуються й динамічно.
(function () {
    function label(root) {
        root.querySelectorAll('.ts-wrapper input:not([aria-label])').forEach((input) => {
            const select = input.closest('.ts-wrapper')?.previousElementSibling;
            // tom-select переводить <label for> на свій контрол (id + "-ts-control"), тож шукаємо обидва варіанти
            const id = select && select.id ? CSS.escape(select.id) : null;
            const text = id ? document.querySelector('label[for="' + id + '"], label[for="' + id + '-ts-control"]')?.textContent.trim() : '';
            if (text) input.setAttribute('aria-label', text);
        });
    }
    document.addEventListener('DOMContentLoaded', () => label(document));
    new MutationObserver(() => label(document)).observe(document.documentElement, { childList: true, subtree: true });
})();
