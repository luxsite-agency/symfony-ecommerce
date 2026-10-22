// Підсвітка знайдених слів у таблиці списку адмінки: слова — з data-search-terms (AdminSearchState, у т. ч. виправлена
// розкладка), шукаються без регістру в тексті клітинок; телефони й номери з цифр не підсвічуються (там нормалізація).
// Власну підсвітку EasyAdmin (mark.js по сирому запиту: «050 111 22 33» → «050», «111»…, не та розкладка) знімаємо — тому 'load',
// після обробників DOMContentLoaded EasyAdmin.
window.addEventListener('load', () => {
    document.querySelectorAll('table.datagrid mark[data-markjs]').forEach((mark) => mark.replaceWith(document.createTextNode(mark.textContent)));
    document.querySelectorAll('table.datagrid td').forEach((cell) => cell.normalize());
    const status = document.querySelector('[data-search-terms]');
    const table = document.querySelector('table.datagrid tbody');
    if (!status || !table) return;
    let terms;
    try { terms = JSON.parse(status.dataset.searchTerms || '[]'); } catch (e) { return; }
    terms = terms.filter((t) => t.length >= 2 && !/^\+?\d+$/.test(t));
    if (!terms.length) return;
    const escape = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const pattern = new RegExp('(' + terms.sort((a, b) => b.length - a.length).map(escape).join('|') + ')', 'giu');
    table.querySelectorAll('td').forEach((cell) => {
        const walker = document.createTreeWalker(cell, NodeFilter.SHOW_TEXT);
        const nodes = [];
        while (walker.nextNode()) nodes.push(walker.currentNode);
        nodes.forEach((node) => {
            const text = node.nodeValue;
            pattern.lastIndex = 0;
            if (!text.trim() || !pattern.test(text) || node.parentElement.closest('mark, script, style, .actions')) return;
            const fragment = document.createDocumentFragment();
            text.split(pattern).forEach((part, i) => {
                if (i % 2) {
                    const mark = document.createElement('mark');
                    mark.className = 'admin-search-hit';
                    mark.textContent = part;
                    fragment.appendChild(mark);
                } else if (part) {
                    fragment.appendChild(document.createTextNode(part));
                }
            });
            node.parentNode.replaceChild(fragment, node);
        });
    });
});
