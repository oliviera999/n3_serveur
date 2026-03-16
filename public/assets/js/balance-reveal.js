/**
 * Révélation fluide des sections Bilan Hydrique (consommation et marnage) sur les pages aquaponie.
 * Les cadres sont cachés par défaut et apparaissent au clic sur "En savoir plus".
 */
(function() {
    function initBalanceReveal() {
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.balance-reveal-btn');
            if (!btn) return;
            e.preventDefault();
            const contentId = btn.getAttribute('aria-controls');
            const content = contentId ? document.getElementById(contentId) : null;
            if (!content) return;
            const isExpanded = btn.getAttribute('aria-expanded') === 'true';
            content.classList.toggle('is-visible', !isExpanded);
            btn.setAttribute('aria-expanded', !isExpanded);
            btn.querySelector('.balance-reveal-btn-icon')?.classList.toggle('fa-chevron-down', isExpanded);
            btn.querySelector('.balance-reveal-btn-icon')?.classList.toggle('fa-chevron-up', !isExpanded);
            const textNode = Array.from(btn.childNodes).find(function(n) { return n.nodeType === 3 && n.textContent.trim(); });
            if (textNode) {
                textNode.textContent = isExpanded ? ' En savoir plus' : ' Masquer';
            } else {
                const span = btn.querySelector('.balance-reveal-btn-text');
                if (span) span.textContent = isExpanded ? 'En savoir plus' : 'Masquer';
            }
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initBalanceReveal);
    } else {
        initBalanceReveal();
    }
})();
