/**
 * Admin WhatsApp Gateway — tampilkan field sesuai provider.
 *
 * Hanya mengatur tampilan/required di browser. Validasi sebenarnya tetap
 * dilakukan server-side; token tidak pernah ditulis ke JS atau HTML.
 */
(function () {
    'use strict';

    function init() {
        var provider = document.getElementById('wa-provider');
        if (!provider) {
            return;
        }

        var blocks = Array.prototype.slice.call(document.querySelectorAll('[data-wa-provider-fields]'));
        var metaRequired = Array.prototype.slice.call(document.querySelectorAll('[data-wa-required-meta]'));
        var tokenLabel = document.querySelector('[data-wa-token-label]');
        var tokenInput = document.getElementById('wa-token');
        var nameInput = document.getElementById('wa-name');

        function sync() {
            var value = provider.value;

            blocks.forEach(function (block) {
                block.hidden = block.getAttribute('data-wa-provider-fields') !== value;
            });

            metaRequired.forEach(function (input) {
                input.required = value === 'meta_cloud';
            });

            if (tokenLabel) {
                tokenLabel.textContent = value === 'fonnte' ? 'API Token Fonnte' : 'Access token';
            }

            if (tokenInput) {
                var placeholder = tokenInput.getAttribute(
                    value === 'fonnte' ? 'data-placeholder-fonnte' : 'data-placeholder-meta'
                );
                if (placeholder) {
                    tokenInput.placeholder = placeholder;
                }
            }

            if (nameInput) {
                var namePlaceholder = nameInput.getAttribute(
                    value === 'fonnte' ? 'data-placeholder-fonnte' : 'data-placeholder-meta'
                );
                if (namePlaceholder) {
                    nameInput.placeholder = namePlaceholder;
                }
            }
        }

        provider.addEventListener('change', sync);
        sync();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
