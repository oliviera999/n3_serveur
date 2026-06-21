(function () {
    'use strict';
    document.querySelectorAll('.inline-delete-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            var msg = form.getAttribute('data-confirm') || 'Confirmer la suppression ?';
            if (!window.confirm(msg)) {
                e.preventDefault();
            }
        });
    });
})();
