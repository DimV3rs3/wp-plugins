/**
 * Открытие вкладок страницы страны по hash (#cities, #ergonomics/cities).
 */
(function ($) {
    'use strict';

    function parseHash() {
        var raw = (window.location.hash || '').replace(/^#/, '').trim();
        if (!raw) {
            return null;
        }
        var parts = raw.split('/').filter(Boolean);
        if (!parts.length) {
            return null;
        }
        var tab = parts[0];
        if (tab === 'ergo-compare') {
            tab = 'compare';
        }
        return {
            tab: tab,
            subtab: parts[1] || ''
        };
    }

    function getMainTabs() {
        return $('.wsp-country-page > .wsp-container > .wsp-tabs').first();
    }

    function activateSubtab($container, subtabId) {
        if (!subtabId || !$container.length) {
            return;
        }
        var $btn = $container.find('> nav.wsp-tab-nav .wsp-tab-btn[data-tab="' + subtabId + '"]');
        if ($btn.length && !$btn.hasClass('wsp-tab-active')) {
            $btn.trigger('click');
        }
    }

    function scrollToCitiesTable() {
        var el = document.getElementById('wscities-country-cities-table');
        if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    function afterTabReady(parsed, $panel) {
        if (parsed.subtab) {
            activateSubtab($panel.find('.wsergo-country-subtabs').first(), parsed.subtab);
        }
        if (parsed.tab === 'cities') {
            scrollToCitiesTable();
        }
    }

    function openTabFromHash() {
        var parsed = parseHash();
        if (!parsed) {
            return;
        }

        var $mainTabs = getMainTabs();
        if (!$mainTabs.length) {
            return;
        }

        var $btn = $mainTabs.find('> nav.wsp-tab-nav .wsp-tab-btn[data-tab="' + parsed.tab + '"]');
        if (!$btn.length) {
            return;
        }

        var $panel = $mainTabs.find('> .wsp-tab-panels > .wsp-tab-panel[data-tab="' + parsed.tab + '"]');
        var needsLoad = $panel.find('.wsp-tab-loading').length > 0;

        function finish($loadedPanel) {
            afterTabReady(parsed, $loadedPanel && $loadedPanel.length ? $loadedPanel : $panel);
        }

        if ($btn.hasClass('wsp-tab-active') && !needsLoad) {
            finish($panel);
            return;
        }

        $(document).one('wsp:tab:loaded', function (e, tabId, iso2, $loadedPanel) {
            if (tabId !== parsed.tab) {
                return;
            }
            finish($loadedPanel);
        });

        $btn.trigger('click');

        if (!needsLoad) {
            window.setTimeout(function () {
                finish($panel);
            }, 50);
        }
    }

    $(document).ready(function () {
        if (!getMainTabs().length) {
            return;
        }
        openTabFromHash();
        $(window).on('hashchange', openTabFromHash);
    });
})(jQuery);
