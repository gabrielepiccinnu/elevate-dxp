/*
 * Elevate DXP experiments runtime (frontend). GPL-3.0-or-later.
 *
 *   edxp.track('signup', {value: 49.9, meta: {plan: 'pro'}})
 *   <button data-edxp-track="signup">            -> tracked on click
 *   <form data-edxp-track-submit="lead">         -> tracked on submit
 *   edxp.variant('hero')                          -> 'A' | 'B' | null
 */
(function (window, document) {
    'use strict';
    var cfg = window.edxpConfig || {};
    var trackUrl = cfg.trackUrl || '/_edxp/track';
    var experiments = cfg.experiments || {};

    function track(event, options) {
        options = options || {};
        var body = JSON.stringify({
            event: String(event || '').toLowerCase(),
            value: options.value,
            meta: options.meta,
            type: options.type,
            url: window.location.href
        });
        try {
            if (navigator.sendBeacon && !options.sync) {
                return navigator.sendBeacon(trackUrl, new Blob([body], {type: 'application/json'}));
            }
        } catch (e) {
        }
        return window.fetch(trackUrl, {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: true,
            headers: {'Content-Type': 'application/json'},
            body: body
        });
    }

    document.addEventListener('click', function (e) {
        var el = e.target && e.target.closest ? e.target.closest('[data-edxp-track]') : null;
        if (el) {
            track(el.getAttribute('data-edxp-track'), {
                value: el.getAttribute('data-edxp-value') || undefined,
                type: 'click'
            });
        }
    }, true);

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (form && form.getAttribute && form.getAttribute('data-edxp-track-submit')) {
            track(form.getAttribute('data-edxp-track-submit'));
        }
    }, true);

    window.edxp = {
        track: track,
        experiments: experiments,
        variant: function (key) {
            return Object.prototype.hasOwnProperty.call(experiments, key) ? experiments[key] : null;
        }
    };
})(window, document);
