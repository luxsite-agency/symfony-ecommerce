/*! Symfony ecommerce LuxSite · luxsite.agency · Apache-2.0 */
// Адмінка: якщо після сабміту помилка валідації схована в неактивному табі,
// відкриваємо перший таб з .is-invalid (інакше редактор бачить «нічого не сталося»).
// Портовано з внутрішнього проєкту LuxSite (admin-ux-ref).
document.addEventListener('DOMContentLoaded', () => {
    const invalidPane = document.querySelector('.tab-pane:has(.is-invalid)')
        || Array.from(document.querySelectorAll('.tab-pane'))
            .find((pane) => pane.querySelector('.is-invalid'));
    if (!invalidPane || invalidPane.classList.contains('active')) {
        return;
    }
    const trigger = document.querySelector(
        `[data-bs-toggle="tab"][href="#${invalidPane.id}"], [data-bs-toggle="tab"][data-bs-target="#${invalidPane.id}"]`,
    );
    trigger?.click();
});
