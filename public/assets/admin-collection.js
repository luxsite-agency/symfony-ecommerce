/*! Symfony ecommerce LuxSite · luxsite.agency · Apache-2.0 */
// Адмінка: «Додати»/«Прибрати» для Symfony CollectionType, який рендериться без теми EasyAdmin
// (колекції всередині форм перекладів, позначені класом .js-collection з data-prototype).
// Колекції, що йдуть через EasyAdmin CollectionField/тему, мають власні кнопки — цей код їх не чіпає.
// Портовано з внутрішнього проєкту LuxSite (admin-ux-ref).
(function () {
    function enhance(root) {
        root.querySelectorAll('.js-collection').forEach((col) => {
            if (col.dataset.enhanced) return;
            col.dataset.enhanced = '1';
            const proto = col.dataset.prototype;
            let index = col.children.length;
            const wrap = (entry) => {
                if (entry.querySelector(':scope > .js-col-remove')) return;
                entry.classList.add('js-col-entry');
                const rm = document.createElement('button');
                rm.type = 'button';
                rm.className = 'btn btn-link btn-sm text-danger js-col-remove';
                rm.textContent = col.dataset.removeLabel || 'Прибрати';
                rm.addEventListener('click', () => entry.remove());
                entry.appendChild(rm);
            };
            Array.from(col.children).forEach(wrap);
            const add = document.createElement('button');
            add.type = 'button';
            add.className = 'btn btn-secondary btn-sm mt-2';
            add.textContent = col.dataset.addLabel || 'Додати';
            add.addEventListener('click', () => {
                const holder = document.createElement('div');
                holder.innerHTML = proto.replace(/__name__/g, String(index++));
                const entry = holder.firstElementChild;
                wrap(entry);
                col.appendChild(entry);
                entry.querySelector('input,textarea')?.focus();
            });
            col.insertAdjacentElement('afterend', add);
        });
    }
    // CollectionField EasyAdmin: свій підпис «Додати …» з data-add-label рядка поля (у EasyAdmin він один на всі колекції)
    function relabel(root) {
        root.querySelectorAll('.field-collection-add-button').forEach((btn) => {
            // лише кнопка самої колекції з підписом, не вкладених (пороги цін усередині варіанта)
            const label = btn.closest('[data-ea-collection-field]')?.dataset.addLabel;
            if (!label) return;
            const text = Array.from(btn.childNodes).find((n) => n.nodeType === Node.TEXT_NODE && n.textContent.trim() !== '');
            if (text && text.textContent.trim() !== label) text.textContent = ' ' + label;
        });
    }
    document.addEventListener('DOMContentLoaded', () => { enhance(document); relabel(document); });
    // EasyAdmin може підвантажувати форми динамічно (модалки/таби) — доганяємо
    new MutationObserver(() => { enhance(document); relabel(document); }).observe(document.body, { childList: true, subtree: true });
})();
