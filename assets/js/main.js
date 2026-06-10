/**
 * PyContest — main JavaScript file
 * Common utilities and initializations
 */
(function() {
    'use strict';

    // CSRF token setup for AJAX requests
    // Add token to all fetch requests if needed
    const originalFetch = window.fetch;
    window.fetch = function(url, options = {}) {
        const token = document.querySelector('meta[name="csrf-token"]')?.content;
        if (token) {
            options.headers = options.headers || {};
            options.headers['X-CSRF-TOKEN'] = token;
        }
        return originalFetch.call(this, url, options);
    };

    // Generic confirmation dialog handler
    document.addEventListener('click', function(e) {
        const target = e.target.closest('[data-confirm]');
        if (target) {
            const message = target.getAttribute('data-confirm');
            if (!confirm(message)) {
                e.preventDefault();
            }
        }
    });

    // Flash message auto-dismiss
    document.addEventListener('DOMContentLoaded', function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            if (!alert.classList.contains('alert-persistent')) {
                setTimeout(function() {
                    alert.style.opacity = '0';
                    alert.style.transition = 'opacity 0.5s';
                    setTimeout(function() {
                        alert.remove();
                    }, 500);
                }, 5000);
            }
        });
    });

    console.log('PyContest initialized');
})();