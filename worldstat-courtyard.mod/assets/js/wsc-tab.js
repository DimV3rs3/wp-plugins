/**
 * Country tab: handles city card clicks and mounts the WSCApp.
 */
(function () {
	'use strict';

	var INIT_FLAG = '__wscBound';

	/**
	 * После маунта города добавляем секцию «Типологические кластеры зданий».
	 * Использует endpoint /wsergo/v1/city/{id}/clusters и публичный window.wsergoClustersBind
	 * из плагина worldstat-ergonomics.
	 */
	function mountClustersSection(appEl, cityId) {
		if (!appEl || !cityId) { return; }
		// Удаляем секцию от прошлого города (если была).
		var prev = appEl.querySelector(':scope > .wsergo-clusters');
		if (prev) { prev.parentNode.removeChild(prev); }

		var cfg = window.wsergoClusters || {};
		var restBase = (cfg.restBase || (window.wscConfig && window.wscConfig.wsergoRestUrl) || '/wp-json/wsergo/v1/').replace(/\/?$/, '/');
		var section = document.createElement('section');
		section.className = 'wsergo-clusters';
		section.setAttribute('data-type', 'city');
		section.setAttribute('data-post-id', String(cityId));
		section.setAttribute('data-endpoint', restBase + 'city/' + cityId + '/clusters');
		section.setAttribute('data-nonce', cfg.nonce || '');
		section.innerHTML =
			'<h3 class="wsp-section-title">Типологические кластеры зданий</h3>'
			+ '<div class="wsergo-clusters__controls"></div>'
			+ '<div class="wsergo-clusters__summary" aria-live="polite"></div>'
			+ '<div class="wsergo-clusters__map"></div>'
			+ '<div class="wsergo-clusters__legend"></div>'
			+ '<div class="wsergo-clusters__cards"></div>';
		appEl.appendChild(section);

		if (typeof window.wsergoClustersBind === 'function') {
			window.wsergoClustersBind(section);
		} else {
			console.warn('[wsc-tab] wsergoClustersBind not loaded — cluster section static');
		}
	}

	function bindTab(tab) {
		if (!tab || tab[INIT_FLAG]) return;
		var grid = tab.querySelector('.wsc-city-grid');
		var app  = tab.querySelector('.wsc-city-app');
		if (!grid || !app) return;
		tab[INIT_FLAG] = true;

		grid.addEventListener('click', function (e) {
			var btn = e.target.closest('.wsc-city-card');
			if (!btn) return;
			e.preventDefault();
			grid.querySelectorAll('.wsc-city-card.is-active').forEach(function (b) { b.classList.remove('is-active'); });
			btn.classList.add('is-active');
			var cid  = parseInt(btn.dataset.cityId, 10);
			var name = btn.dataset.cityName || '';
			var lat  = parseFloat(btn.dataset.lat || '0');
			var lng  = parseFloat(btn.dataset.lng || '0');
			if (window.WSCApp && typeof window.WSCApp.mount === 'function') {
				try {
					window.WSCApp.mount(app, cid, name, lat, lng);
					try { localStorage.setItem('wsc_last_city_' + (tab.dataset.iso2 || ''), String(cid)); } catch (e) {}
					mountClustersSection(app, cid);
					app.scrollIntoView({ behavior: 'smooth', block: 'start' });
				} catch (err) {
					console.error('[wsc] mount failed:', err);
					app.hidden = false;
					app.innerHTML = '<div class="wsc-error">Не удалось загрузить карту: ' + (err && err.message ? err.message : err) + '</div>';
				}
			} else {
				console.error('[wsc] WSCApp not loaded');
				app.hidden = false;
				app.innerHTML = '<div class="wsc-error">Скрипт карты не загружен (window.WSCApp отсутствует). Проверьте загрузку MapLibre.</div>';
			}
		});

		// Restore last city.
		try {
			var iso2 = tab.dataset.iso2 || '';
			var last = localStorage.getItem('wsc_last_city_' + iso2);
			if (last) {
				var saved = grid.querySelector('.wsc-city-card[data-city-id="' + last + '"]');
				if (saved) setTimeout(function () { saved.click(); }, 200);
			}
		} catch (e) {}
	}

	function init() {
		document.querySelectorAll('.wsc-country-tab').forEach(bindTab);
	}

	if (document.readyState !== 'loading') init();
	document.addEventListener('DOMContentLoaded', init);

	// Platform fires jQuery custom event 'wsp:tab:loaded' after AJAX-loaded tab content.
	if (window.jQuery) {
		window.jQuery(document).on('wsp:tab:loaded', function () {
			setTimeout(init, 0);
		});
	}

	// MutationObserver fallback — match the node itself OR its descendants.
	var mo = new MutationObserver(function (muts) {
		for (var i = 0; i < muts.length; i++) {
			var added = muts[i].addedNodes;
			for (var j = 0; j < added.length; j++) {
				var n = added[j];
				if (!n || n.nodeType !== 1) continue;
				if ((n.matches && n.matches('.wsc-country-tab')) ||
				    (n.querySelector && n.querySelector('.wsc-country-tab'))) {
					init();
					return;
				}
			}
		}
	});
	mo.observe(document.body, { childList: true, subtree: true });
})();
