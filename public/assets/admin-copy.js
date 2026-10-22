/*! Symfony ecommerce LuxSite · luxsite.agency · Apache-2.0 */
// Адмінка: кнопка «Копіювати» біля полів лише для читання (адреси для Google Cloud Console у Налаштуваннях):
// data-copy="#id поля", data-copied="підпис після копіювання". Без clipboard API (http, старий браузер) — виділяє поле.
document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-copy]');
    if (!button) {
        return;
    }
    const input = document.querySelector(button.dataset.copy);
    if (!input) {
        return;
    }
    input.select();
    const done = () => {
        const label = button.textContent;
        button.textContent = button.dataset.copied || label;
        setTimeout(() => { button.textContent = label; }, 1500);
    };
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(input.value).then(done, () => document.execCommand('copy') && done());
    } else if (document.execCommand('copy')) {
        done();
    }
});
