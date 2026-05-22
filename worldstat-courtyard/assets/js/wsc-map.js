/**
 * MapLibre city app: тайлы, слои зданий/POI/придомовых, 2D/3D, скан, прогресс.
 */
(function () {
	'use strict';

	var cfg = window.wscConfig || {};

	/**
	 * Собирает URL REST wsc/v1 с учётом plain permalinks (?rest_route=…):
	 * параметры добавляются как & после полного пути, а не ? внутрь rest_route.
	 */
	function wscApiUrl(pathTail, queryKv) {
		var base = (cfg.restUrl || '').replace(/\/?$/, '');
		var url = base + '/' + String(pathTail || '').replace(/^\//, '');
		if (!queryKv) return url;
		var parts = [];
		Object.keys(queryKv).forEach(function (k) {
			if (queryKv[k] === undefined || queryKv[k] === null) return;
			parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(queryKv[k]));
		});
		if (!parts.length) return url;
		return url + (url.indexOf('?') === -1 ? '?' : '&') + parts.join('&');
	}

	function el(tag, attrs, html) {
		var n = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			if (k === 'class') n.className = attrs[k];
			else if (k.indexOf('data-') === 0) n.setAttribute(k, attrs[k]);
			else n[k] = attrs[k];
		});
		if (html != null) n.innerHTML = html;
		return n;
	}

	function wscRoadLinePaint() {
		// Визуальная иерархия:
		//   - magistrali (motor) видны всегда, но НА ШИРОКОМ ZOOM приглушены,
		//     чтобы не «забивать» здания категорийных цветов.
		//   - тротуары (foot) и парковки (parking) — детали для близкого зума,
		//     opacity растёт от 0 (zoom 13) к 0.85 (zoom 16+).
		// Это убирает «красную паутину» на обзорном виде, оставляя её только когда юзер реально
		// рассматривает квартал.
		return {
			'line-color': ['match', ['get', 'road_class'],
				'motor', '#ef4444',
				'foot',  '#16a34a',
				'parking', '#64748b',
				'#94a3b8'
			],
			'line-width': ['interpolate', ['linear'], ['zoom'],
				11, ['match', ['get', 'road_class'], 'motor', 0.8, 'foot', 0.0, 'parking', 0.0, 0.4],
				14, ['match', ['get', 'road_class'], 'motor', 1.8, 'foot', 0.6, 'parking', 0.8, 0.8],
				16, ['match', ['get', 'road_class'], 'motor', 3.2, 'foot', 1.6, 'parking', 2.2, 1.6],
				18, ['match', ['get', 'road_class'], 'motor', 5.0, 'foot', 3.0, 'parking', 4.0, 3.0]
			],
			'line-opacity': ['interpolate', ['linear'], ['zoom'],
				11, ['match', ['get', 'road_class'], 'motor', 0.45, 'foot', 0.0, 'parking', 0.0, 0.3],
				13, ['match', ['get', 'road_class'], 'motor', 0.55, 'foot', 0.0, 'parking', 0.0, 0.45],
				15, ['match', ['get', 'road_class'], 'motor', 0.75, 'foot', 0.55, 'parking', 0.6, 0.7],
				17, ['match', ['get', 'road_class'], 'motor', 0.9, 'foot', 0.85, 'parking', 0.85, 0.85]
			],
		};
	}

	/** Layout для road-layer: round joins/caps дают плавные углы. */
	function wscRoadLineLayout() {
		return { 'line-cap': 'round', 'line-join': 'round' };
	}


	var POI_POINT_FILTER = ['in', ['geometry-type'], ['literal', ['Point', 'MultiPoint']]];
	var POI_POLYGON_FILTER = ['in', ['geometry-type'], ['literal', ['Polygon', 'MultiPolygon']]];

	function buildStyle(cityId) {
		var sources = {
			'wsc-buildings': { type: 'geojson', data: cfg.restUrl + 'city/' + cityId + '/layer/buildings' },
			'wsc-yards':     { type: 'geojson', data: cfg.restUrl + 'city/' + cityId + '/layer/yards' },
			'wsc-pois':      { type: 'geojson', data: cfg.restUrl + 'city/' + cityId + '/layer/pois' },
			'wsc-roads':     { type: 'geojson', data: cfg.restUrl + 'city/' + cityId + '/layer/roads' },
		};
		var catMatch = ['match', ['get', 'category']];
		Object.keys(cfg.colors || {}).forEach(function (k) { catMatch.push(k); catMatch.push(cfg.colors[k]); });
		catMatch.push('#94a3b8');

		var layers = [
			{ id: 'wsc-yards-fill',   type: 'fill', source: 'wsc-yards',
				paint: {
					// Буферы дворов нужны только когда юзер рассматривает квартал. На обзоре
					// они только добавляют визуальный шум поверх зданий.
					'fill-color': '#0ea5e9',
					'fill-opacity': ['interpolate', ['linear'], ['zoom'], 13, 0, 15, 0.08, 17, 0.14],
					'fill-outline-color': 'rgba(2, 132, 199, 0.35)'
				}
			},
			{
				id: 'wsc-roads-parking-fill', type: 'fill', source: 'wsc-roads',
				filter: ['in', ['geometry-type'], ['literal', ['Polygon', 'MultiPolygon']]],
				paint: {
					'fill-color': '#94a3b8',
					'fill-opacity': ['interpolate', ['linear'], ['zoom'], 13, 0, 15, 0.18, 17, 0.32],
				},
			},
			{
				id: 'wsc-roads-line', type: 'line', source: 'wsc-roads',
				filter: ['in', ['geometry-type'], ['literal', ['LineString', 'MultiLineString']]],
				layout: wscRoadLineLayout(),
				paint: wscRoadLinePaint(),
			},
			{ id: 'wsc-buildings-fill', type: 'fill', source: 'wsc-buildings',
				paint: {
					'fill-color': catMatch,
					// На обзоре здания приглушены (acuarele-вид), при приближении — насыщенные.
					'fill-opacity': ['interpolate', ['linear'], ['zoom'], 12, 0.45, 14, 0.65, 16, 0.82, 18, 0.92],
					'fill-outline-color': 'rgba(15, 23, 42, 0.28)',
				}
			},
			{ id: 'wsc-buildings-3d', type: 'fill-extrusion', source: 'wsc-buildings',
				layout: { visibility: 'none' },
				paint: {
					'fill-extrusion-color': catMatch,
					'fill-extrusion-height': ['get', 'height'],
					// vertical-gradient = MapLibre автоматом затемняет грани относительно крыши.
					'fill-extrusion-vertical-gradient': true,
					'fill-extrusion-opacity': 0.92,
				}
			},
			{
				id: 'wsc-pois-polygon-fill', type: 'fill', source: 'wsc-pois', filter: POI_POLYGON_FILTER,
				paint: {
					'fill-color': catMatch,
					// POI-полигоны (например, площади, площадки) — деталь для близкого зума.
					'fill-opacity': ['interpolate', ['linear'], ['zoom'], 13, 0, 15, 0.22, 17, 0.42],
					'fill-outline-color': 'rgba(71, 85, 105, 0.4)'
				},
			},
			{ id: 'wsc-pois-circle-halo', type: 'circle', source: 'wsc-pois',
				// Glow только для критических категорий (медицина, безопасность) и только когда
				// юзер достаточно близко (zoom ≥ 14) — иначе halo «забивает» обзор.
				filter: ['all',
					POI_POINT_FILTER,
					['in', ['get', 'category'], ['literal', ['healthcare', 'infra_safety']]]
				],
				paint: {
					'circle-radius': ['interpolate', ['linear'], ['zoom'], 13, 0, 15, 12, 18, 22],
					'circle-color': catMatch,
					'circle-opacity': ['interpolate', ['linear'], ['zoom'], 13, 0, 15, 0.22, 18, 0.28],
					'circle-blur': 0.6,
				}
			},
			{ id: 'wsc-pois-circle', type: 'circle', source: 'wsc-pois', filter: POI_POINT_FILTER,
				paint: {
					// На широком zoom POI скрыты вообще, появляются с zoom 14, заметны с zoom 15+.
					'circle-radius': ['interpolate', ['linear'], ['zoom'], 13, 0, 14, 2, 16, 4, 18, 6.5],
					'circle-color': catMatch,
					'circle-stroke-color': '#fff',
					'circle-stroke-width': ['interpolate', ['linear'], ['zoom'], 13, 0, 15, 1, 17, 1.4],
					'circle-opacity': ['interpolate', ['linear'], ['zoom'], 13, 0, 14, 0.4, 16, 0.9],
				}
			},
		];

		var t = cfg.tiles || {};
		if (t.basemap === 'mbtiles') {
			sources.basemap = { type: 'vector', tiles: [ cfg.restUrl + 'basemap/{z}/{x}/{y}' ], minzoom: 0, maxzoom: 14 };
			return {
				version: 8, sources: sources, glyphs: 'https://tiles.openfreemap.org/fonts/{fontstack}/{range}.pbf',
				layers: [{ id: 'bg', type: 'background', paint: { 'background-color': '#f8fafc' } }].concat(layers),
			};
		}

		// External style: fetch and merge.
		return null; // signal to caller
	}

	function externalStyleUrl() {
		var t = cfg.tiles || {};
		if (t.basemap === 'maptiler') {
			var key = t.api_key || '';
			// Basic — минималистичный стиль MapTiler без цветных landuse и POI-меток.
			// Streets был шумным (фиолетовые/розовые подложки зон).
			return 'https://api.maptiler.com/maps/basic-v2/style.json?key=' + encodeURIComponent(key);
		}
		// Positron — точная копия CARTO Positron: светло-серый фон без landuse-полигонов
		// и POI-меток. Под наш кейс (категорийные цветные здания + дороги поверх) идеально.
		return 'https://tiles.openfreemap.org/styles/' + (t.style || 'positron');
	}

	window.WSCApp = {
		mount: function (container, cityId, cityName, lat, lng) {
			container.innerHTML = '';
			container.hidden = false;

			// Двухколоночный layout: панель управления слева, карта справа.
			// На узком экране сворачивается в обычный стэк через CSS media query.
			var layout = el('div', { class: 'wsc-app-layout' });
			var sidebar = el('aside', { class: 'wsc-sidebar' });
			var mapArea = el('div', { class: 'wsc-map-area' });
			var wrap = el('div', { class: 'wsc-map-wrap' });
			var canvas = el('div', { class: 'wsc-map-canvas', id: 'wsc-canvas-' + cityId });
			wrap.appendChild(canvas);
			// Floating info-panel — slide-in справа поверх карты. Используется
			// и для «клик по зданию» и для overlay-значений. Унификация: одна карточка
			// вместо двух перекрывающих maplibregl-попапов.
			var infoPanel = el('aside', { class: 'wsc-info-panel' });
			infoPanel.hidden = true;
			wrap.appendChild(infoPanel);
			mapArea.appendChild(wrap);
			layout.appendChild(sidebar);
			layout.appendChild(mapArea);
			container.appendChild(layout);

			// Сводка по городу (быстрая классификация): здания/POI/этажность/landuse.
			// Перерисовывается автоматически из _renderImportStatus при завершении импорта.
			var statsHost = el('section', { class: 'wsc-stats' });
			container.appendChild(statsHost);
			WSCApp._renderCityStats(statsHost, cityId);

			// «Загрузка данных OSM» — техническая информация о фоновых задачах.
			// Унесена в самый низ страницы как diagnostic-блок: collapsed by default
			// если активных задач нет (показывает только summary-строку).
			var importHost = el('section', { class: 'wsc-import-status' });
			// Сам блок появится после toolbar — добавим его в самом конце mount().

			// Toolbar — теперь под картой, сгруппирован по смыслу для лучшего UX.
			var tb = el('div', { class: 'wsc-toolbar wsc-toolbar--below' });

			// Заголовок города + режим 2D/3D в одной шапке.
			var tbHeader = el('div', { class: 'wsc-toolbar-header' });
			tbHeader.appendChild(el('h4', { class: 'wsc-toolbar-title' }, cityName));
			var modeWrap = el('div', { class: 'wsc-mode-switch' });
			var btn2D = el('button', { class: 'is-active', type: 'button' }, '2D');
			var btn3D = el('button', { type: 'button' }, '3D');
			modeWrap.appendChild(btn2D); modeWrap.appendChild(btn3D);
			tbHeader.appendChild(modeWrap);
			tb.appendChild(tbHeader);

			// Sticky scan-status badge: единственная точка отображения активной задачи
			// (overpass-скан, импорт PBF, пересчёт буферов). Заменяет ранее существовавшие
			// floating-плашку .wsc-progress поверх карты и модальную панель .wsc-jobs.
			// Подробный список задач + лог — в нижней секции «Загрузка данных OSM».
			var scanStatus = el('div', { class: 'wsc-scan-status' });
			scanStatus.hidden = true;
			scanStatus.innerHTML =
				'<span class="wsc-scan-status-icon" aria-hidden="true">⏳</span>' +
				'<span class="wsc-scan-status-text">Старт…</span>' +
				'<div class="wsc-scan-status-actions">' +
					'<button type="button" class="wsc-scan-btn-resume" title="Повторить незавершённые">↻</button>' +
					'<button type="button" class="wsc-scan-btn-finish" title="Завершить как готово">✓</button>' +
					'<button type="button" class="wsc-scan-btn-abort" title="Прервать">⏹</button>' +
				'</div>' +
				'<div class="wsc-scan-status-bar"><div class="wsc-scan-status-bar-fill" style="width:0%"></div></div>';
			tb.appendChild(scanStatus);


			// Группа «Эргономика на карте»: dropdown с цветовой шкалой + кнопка heatmap.
			var wsergoBase = (cfg.wsergoRestUrl || '/wp-json/wsergo/v1/').replace(/\/?$/, '/');
			var ergoGroup = el('div', { class: 'wsc-toolbar-group wsc-toolbar-group--ergo' });
			ergoGroup.appendChild(el('span', { class: 'wsc-toolbar-group-label' }, '🎨 Слой эргономики'));
			var ergoWrap = el('div', { class: 'wsc-ergo-overlay' });
			var ergoSelect = el('select', { class: 'wsc-ergo-overlay-select' });
			ergoSelect.appendChild(el('option', { value: '' }, '— выключено —'));
			ergoSelect.appendChild(el('option', { value: '__loading__', disabled: true }, 'загрузка каталога…'));
			ergoWrap.appendChild(ergoSelect);
			var ergoLegend = el('span', { class: 'wsc-ergo-overlay-legend' });
			ergoWrap.appendChild(ergoLegend);
			ergoGroup.appendChild(ergoWrap);

			// «Тепловая карта E» — отдельный MapLibre heatmap-слой со сглаживанием (kernel density).
			// Показывает «горячие/холодные зоны» города интегрально, не отдельные здания.
			var heatRow = el('div', { class: 'wsc-toolbar-row wsc-ergo-heatmap-row' });
			var btnHeat = el('button', { type: 'button', class: 'wsc-ergo-heatmap-btn' }, '🔥 Тепловая карта E');
			heatRow.appendChild(btnHeat);
			var heatLegend = el('span', { class: 'wsc-ergo-heatmap-legend' });
			heatRow.appendChild(heatLegend);
			ergoGroup.appendChild(heatRow);

			// «Кластеры» — типологическая раскраска зданий по k-means на 6 измерениях эргономики.
			var clusterRow = el('div', { class: 'wsc-toolbar-row wsc-ergo-cluster-row' });
			var btnCluster = el('button', { type: 'button', class: 'wsc-ergo-cluster-btn' }, '🧬 Кластеры');
			clusterRow.appendChild(btnCluster);
			var clusterLegend = el('span', { class: 'wsc-ergo-cluster-legend' });
			clusterRow.appendChild(clusterLegend);
			ergoGroup.appendChild(clusterRow);

			tb.appendChild(ergoGroup);

			if (cfg.canScan) {
				// Группа «Загрузка данных»: импорт OSM, буферы, агрегации.
				var dataGroup = el('div', { class: 'wsc-toolbar-group wsc-toolbar-group--admin' });
				dataGroup.appendChild(el('span', { class: 'wsc-toolbar-group-label' }, 'Данные OSM'));
				var dataRow = el('div', { class: 'wsc-toolbar-row' });
				var btnScan   = el('button', { class: 'is-primary', type: 'button' }, '📡 Сканировать');
				var btnScanV  = el('button', { type: 'button' }, 'Вьюпорт');
				var btnDraw   = el('button', { type: 'button' }, '✏️ Область');
				var btnRecalc = el('button', { type: 'button' }, '↻ Буферы');
				dataRow.appendChild(btnScan);
				dataRow.appendChild(btnScanV);
				dataRow.appendChild(btnDraw);
				dataRow.appendChild(btnRecalc);
				dataGroup.appendChild(dataRow);
				tb.appendChild(dataGroup);
			}
			// Toolbar теперь в левом sidebar — постоянный доступ без скролла.
			sidebar.appendChild(tb);

			// Legend (с кликабельными категориями: каждая строка — независимый toggle).
			// Per-map state: фильтры не «протекают» между разными городами на одной странице.
			// yardFilterPolygon — придомовая территория активного клика по зданию; пока null,
			// слои POI-кружков, POI-полигонов и 3D-деревьев скрыты целиком.
			var mapState = {
				categoriesDisabled: {},
				yardFilterPolygon: null,
				treesAllFC: null,
			};
			var lg = el('div', { class: 'wsc-legend' });
			lg.appendChild(el('h4', {}, 'Категории построек'));
			lg.appendChild(el('p', { class: 'wsc-legend-hint' }, 'Клик по строке — скрыть/показать категорию'));
			var legendRowsByCat = {};
			Object.keys(cfg.cats || {}).forEach(function (k) {
				var disabled = !!mapState.categoriesDisabled[k];
				var row = el('div', { class: 'wsc-legend-row wsc-legend-row--clickable' + (disabled ? ' is-disabled' : ''), 'data-cat': k, role: 'button', tabindex: '0' });
				row.appendChild(el('span', { class: 'wsc-legend-swatch', style: 'background:' + (cfg.colors[k] || '#94a3b8') }));
				row.appendChild(el('span', { class: 'wsc-legend-label' }, cfg.cats[k]));
				legendRowsByCat[k] = row;
				function toggle() {
					mapState.categoriesDisabled[k] = !mapState.categoriesDisabled[k];
					row.classList.toggle('is-disabled', mapState.categoriesDisabled[k]);
					if (mapInstance) WSCApp._applyMapLegendVisibility(mapInstance, { roads: wscVisInputs.roads.checked });
				}
				row.addEventListener('click', toggle);
				row.addEventListener('keydown', function (e) {
					if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); }
				});
				lg.appendChild(row);
			});

			// Сводные действия по всем категориям зданий.
			var legendActions = el('div', { class: 'wsc-legend-actions' });
			var btnAll = el('button', { type: 'button', class: 'wsc-legend-mini-btn' }, 'Все');
			var btnNone = el('button', { type: 'button', class: 'wsc-legend-mini-btn' }, 'Ни одной');
			var btnInvert = el('button', { type: 'button', class: 'wsc-legend-mini-btn' }, 'Инвертировать');
			function applyAfterBulk() {
				if (mapInstance) WSCApp._applyMapLegendVisibility(mapInstance, { roads: wscVisInputs.roads.checked });
			}
			btnAll.addEventListener('click', function () {
				Object.keys(cfg.cats || {}).forEach(function (k) {
					mapState.categoriesDisabled[k] = false;
					if (legendRowsByCat[k]) legendRowsByCat[k].classList.remove('is-disabled');
				});
				applyAfterBulk();
			});
			btnNone.addEventListener('click', function () {
				Object.keys(cfg.cats || {}).forEach(function (k) {
					mapState.categoriesDisabled[k] = true;
					if (legendRowsByCat[k]) legendRowsByCat[k].classList.add('is-disabled');
				});
				applyAfterBulk();
			});
			btnInvert.addEventListener('click', function () {
				Object.keys(cfg.cats || {}).forEach(function (k) {
					mapState.categoriesDisabled[k] = !mapState.categoriesDisabled[k];
					if (legendRowsByCat[k]) legendRowsByCat[k].classList.toggle('is-disabled', mapState.categoriesDisabled[k]);
				});
				applyAfterBulk();
			});
			legendActions.appendChild(btnAll);
			legendActions.appendChild(btnNone);
			legendActions.appendChild(btnInvert);
			lg.appendChild(legendActions);

			lg.appendChild(el('h4', { class: 'wsc-legend-vis-heading' }, 'Дополнительно'));
			var wscVisInputs = {};
			[
				{ key: 'roads', label: 'Дороги и тротуары' },
			].forEach(function (d) {
				var row = el('label', { class: 'wsc-legend-toggle' });
				var inp = document.createElement('input');
				inp.type = 'checkbox';
				inp.checked = true;
				wscVisInputs[d.key] = inp;
				row.appendChild(inp);
				row.appendChild(document.createTextNode(' ' + d.label));
				lg.appendChild(row);
			});
			// Легенда теперь в sidebar рядом с toolbar — единый блок управления слева.
			sidebar.appendChild(lg);

			// Build map
			var styleObj = buildStyle(cityId);
			var styleArg = styleObj || externalStyleUrl();

			var map = new maplibregl.Map({
				container: canvas,
				style: styleArg,
				center: [lng || 0, lat || 0],
				zoom: 13, pitch: 0, bearing: 0, hash: false,
			});
			// Привязываем per-map состояние к самому объекту карты — оно живёт
			// ровно столько же, сколько MapLibre-instance, и не «протекает» в другие города.
			map._wscState = mapState;
			map._wscInfoPanel = infoPanel;
			var mapInstance = map;
			map.addControl(new maplibregl.NavigationControl({ visualizePitch: true }), 'top-right');

			var added3DLayers = false;
			map.on('load', function () {
				// If using external style, inject our sources & layers AFTER style load.
				if (typeof styleArg === 'string') {
					var s = buildStyle(-1) ? null : null; // no-op, just placeholder
					// Build sources/layers manually here.
					var dataSources = {
						'wsc-buildings': cfg.restUrl + 'city/' + cityId + '/layer/buildings',
						'wsc-yards':     cfg.restUrl + 'city/' + cityId + '/layer/yards',
						'wsc-pois':      cfg.restUrl + 'city/' + cityId + '/layer/pois',
						'wsc-roads':     cfg.restUrl + 'city/' + cityId + '/layer/roads',
					};
					Object.keys(dataSources).forEach(function (k) {
						if (!map.getSource(k)) map.addSource(k, { type: 'geojson', data: dataSources[k] });
					});
					var catMatch = ['match', ['get', 'category']];
					Object.keys(cfg.colors).forEach(function (k) { catMatch.push(k); catMatch.push(cfg.colors[k]); });
					catMatch.push('#94a3b8');

					[
						{ id: 'wsc-yards-fill',   type: 'fill', source: 'wsc-yards',
							paint: {
								'fill-color': '#0ea5e9',
								'fill-opacity': ['interpolate', ['linear'], ['zoom'], 13, 0, 15, 0.08, 17, 0.14],
								'fill-outline-color': 'rgba(2, 132, 199, 0.35)'
							}
						},
						{
							id: 'wsc-roads-parking-fill', type: 'fill', source: 'wsc-roads',
							filter: ['in', ['geometry-type'], ['literal', ['Polygon', 'MultiPolygon']]],
							paint: {
								'fill-color': '#94a3b8',
								'fill-opacity': ['interpolate', ['linear'], ['zoom'], 13, 0, 15, 0.18, 17, 0.32],
							},
						},
						{
							id: 'wsc-roads-line', type: 'line', source: 'wsc-roads',
							filter: ['in', ['geometry-type'], ['literal', ['LineString', 'MultiLineString']]],
							layout: wscRoadLineLayout(),
							paint: wscRoadLinePaint(),
						},
						{ id: 'wsc-buildings-fill', type: 'fill', source: 'wsc-buildings',
							paint: {
								'fill-color': catMatch,
								'fill-opacity': ['interpolate', ['linear'], ['zoom'], 12, 0.45, 14, 0.65, 16, 0.82, 18, 0.92],
								'fill-outline-color': 'rgba(15, 23, 42, 0.28)',
							}
						},
						{ id: 'wsc-buildings-3d', type: 'fill-extrusion', source: 'wsc-buildings',
							layout: { visibility: 'none' },
							paint: {
								'fill-extrusion-color': catMatch,
								'fill-extrusion-height': ['get', 'height'],
								'fill-extrusion-vertical-gradient': true,
								'fill-extrusion-opacity': 0.92,
							}
						},
						{
							id: 'wsc-pois-polygon-fill', type: 'fill', source: 'wsc-pois', filter: POI_POLYGON_FILTER,
							paint: {
								'fill-color': catMatch,
								'fill-opacity': ['interpolate', ['linear'], ['zoom'], 13, 0, 15, 0.22, 17, 0.42],
								'fill-outline-color': 'rgba(71, 85, 105, 0.4)'
							},
						},
						{ id: 'wsc-pois-circle-halo', type: 'circle', source: 'wsc-pois',
							filter: ['all',
								POI_POINT_FILTER,
								['in', ['get', 'category'], ['literal', ['healthcare', 'infra_safety']]]
							],
							paint: {
								'circle-radius': ['interpolate', ['linear'], ['zoom'], 13, 0, 15, 12, 18, 22],
								'circle-color': catMatch,
								'circle-opacity': ['interpolate', ['linear'], ['zoom'], 13, 0, 15, 0.22, 18, 0.28],
								'circle-blur': 0.6,
							}
						},
						{ id: 'wsc-pois-circle', type: 'circle', source: 'wsc-pois', filter: POI_POINT_FILTER,
							paint: {
								'circle-radius': ['interpolate', ['linear'], ['zoom'], 13, 0, 14, 2, 16, 4, 18, 6.5],
								'circle-color': catMatch,
								'circle-stroke-color': '#fff',
								'circle-stroke-width': ['interpolate', ['linear'], ['zoom'], 13, 0, 15, 1, 17, 1.4],
								'circle-opacity': ['interpolate', ['linear'], ['zoom'], 13, 0, 14, 0.4, 16, 0.9],
							}
						},
					].forEach(function (lyr) { if (!map.getLayer(lyr.id)) map.addLayer(lyr); });
				}

				function bindWscLegendVisibility() {
					function applyVis() {
						WSCApp._applyMapLegendVisibility(map, { roads: wscVisInputs.roads.checked });
					}
					if (wscVisInputs.roads && !wscVisInputs.roads._wscLegendBound) {
						wscVisInputs.roads._wscLegendBound = true;
						wscVisInputs.roads.addEventListener('change', applyVis);
					}
					applyVis();
				}
				bindWscLegendVisibility();

				// Click → popup + cursor pointer на hover (affordance: «по зданию можно кликнуть»).
				map.on('click', 'wsc-buildings-fill', function (e) {
					if (!e.features || !e.features.length) return;
					WSCApp._openBuildingPopup(map, cityId, e.features[0], e.lngLat);
				});
				map.on('mouseenter', 'wsc-buildings-fill', function () { map.getCanvas().style.cursor = 'pointer'; });
				map.on('mouseleave', 'wsc-buildings-fill', function () { map.getCanvas().style.cursor = ''; });
				map.on('mouseenter', 'wsc-pois-circle', function () { map.getCanvas().style.cursor = 'pointer'; });
				map.on('mouseleave', 'wsc-pois-circle', function () { map.getCanvas().style.cursor = ''; });

				// Trees layer.
				WSCApp._loadTrees(map, cityId);

				// Ergonomics overlay (data-driven coloring of buildings by chosen metric).
				WSCApp._initErgoOverlay(map, cityId, ergoSelect, ergoLegend, wsergoBase);
				// Тепловая карта E-индекса (сглаженный heatmap по центроидам зданий).
				WSCApp._initErgoHeatmap(map, cityId, btnHeat, heatLegend, wsergoBase);
				// Кластеры зданий по wsergo-профилям (k-means).
				WSCApp._initErgoClusters(map, cityId, btnCluster, clusterLegend, wsergoBase);

				// Fit to bounds (city polygon).
				WSCApp._fitToCity(map, cityId);

				// Стартовое состояние: точки POI и деревья скрыты, пока юзер не кликнет на здание
				// и не покажется его придомовая территория.
				WSCApp._applyPointsFilter(map);
			});

			// 2D/3D switch
			function setMode(mode) {
				if (!map.isStyleLoaded()) return;
				if (mode === '3d') {
					btn2D.classList.remove('is-active');
					btn3D.classList.add('is-active');
					map.easeTo({ pitch: 55, bearing: -20, duration: 600 });
					if (map.getLayer('wsc-buildings-3d')) map.setLayoutProperty('wsc-buildings-3d', 'visibility', 'visible');
					if (map.getLayer('wsc-buildings-fill')) map.setLayoutProperty('wsc-buildings-fill', 'visibility', 'none');
					// Включаем sky-слой: даёт градиент-«небо» вместо плоского фона на наклонном виде.
					if (!map.getLayer('wsc-sky')) {
						try {
							map.addLayer({
								id: 'wsc-sky',
								type: 'sky',
								paint: {
									'sky-type': 'atmosphere',
									'sky-atmosphere-sun': [0.0, 90.0],
									'sky-atmosphere-sun-intensity': 8,
								},
							});
						} catch (e) { /* старые MapLibre без sky-слоя — игнорируем */ }
					}
				} else {
					btn3D.classList.remove('is-active');
					btn2D.classList.add('is-active');
					map.easeTo({ pitch: 0, bearing: 0, duration: 600 });
					if (map.getLayer('wsc-buildings-3d')) map.setLayoutProperty('wsc-buildings-3d', 'visibility', 'none');
					if (map.getLayer('wsc-buildings-fill')) map.setLayoutProperty('wsc-buildings-fill', 'visibility', 'visible');
					if (map.getLayer('wsc-sky')) {
						try { map.removeLayer('wsc-sky'); } catch (e) {}
					}
				}
			}
			btn2D.addEventListener('click', function () { setMode('2d'); });
			btn3D.addEventListener('click', function () { setMode('3d'); });

			// Scan
			if (cfg.canScan) {
				btnScan.addEventListener('click', function () {
					if (WSCApp._scanBusy) { return; }
					WSCApp._startScan(map, cityId, 'auto', null);
				});
				btnScanV.addEventListener('click', function () {
					if (WSCApp._scanBusy) { return; }
					var b = map.getBounds();
					var poly = WSCApp._bboxPolygon(b);
					WSCApp._startScan(map, cityId, 'viewport', poly);
				});
				// Дефолтный обработчик «Сканировать» — на addEventListener выше. btnDraw
				// перенаправляет btnScan.onclick в drawn-режим, а второй клик по «Нарисовать»
				// сбрасывает onclick обратно. Раньше выход был невозможен без перезагрузки.
				var drawActive = false;
				btnDraw.addEventListener('click', function () {
					if (drawActive) {
						// Выход из режима рисования.
						drawActive = false;
						btnScan.onclick = null;
						btnDraw.classList.remove('is-primary');
						btnDraw.textContent = '✏️ Нарисовать область';
						if (window.WSCBboxEditor && window.WSCBboxEditor.detach) {
							try { window.WSCBboxEditor.detach(map); } catch (_) {}
						}
						return;
					}
					drawActive = true;
					btnDraw.classList.add('is-primary');
					btnDraw.textContent = '✕ Завершить рисование';
					window.WSCBboxEditor.attach(map);
					alert('Нарисуйте полигон на карте, затем нажмите «Сканировать»');
					btnScan.onclick = function () {
						var poly = window.WSCBboxEditor.getPolygon();
						if (!poly) { alert('Нарисуйте полигон сначала'); return; }
						WSCApp._startScan(map, cityId, 'drawn', poly);
					};
				});
				btnRecalc.addEventListener('click', function () { WSCApp._recompute(cityId); });
			}

			WSCApp._currentMap = map;
			map._wscScanStatusEl = scanStatus;

			// Wire scan-status badge action buttons (один раз — кнопки переиспользуются между
			// последовательными сканами/импортами; раньше они создавались заново вместе с floating-плашкой).
			function callScanAction(action, label) {
				if (!confirm(label + '?')) return;
				fetch(cfg.restUrl + 'city/' + cityId + '/scan/' + action, {
					method: 'POST', headers: { 'X-WP-Nonce': cfg.nonce },
				}).then(function (r) { return r.json(); }).then(function () {
					WSCApp._reloadSources(map, cityId);
				});
			}
			scanStatus.querySelector('.wsc-scan-btn-abort').addEventListener('click', function () { callScanAction('abort', 'Прервать сканирование'); });
			scanStatus.querySelector('.wsc-scan-btn-finish').addEventListener('click', function () { callScanAction('finish', 'Завершить как готово'); });
			scanStatus.querySelector('.wsc-scan-btn-resume').addEventListener('click', function () { callScanAction('resume', 'Повторить незавершённые плитки'); });

			// If there's an active/stuck job for this city — surface it in the badge.
			fetch(cfg.restUrl + 'city/' + cityId + '/scan/status', { headers: { 'X-WP-Nonce': cfg.nonce } })
				.then(function (r) { return r.json(); }).then(function (j) {
					if (j && j.status && j.status !== 'idle' && j.status !== 'done') {
						WSCApp._pollProgress(map, cityId);
					}
				}).catch(function () {});

			WSCApp._mountCityErgoBatch(container, map, cityId);

			// Загрузка данных OSM — в самом низу страницы как diagnostic-секция.
			// (Объявлена выше в начале mount, но добавляется в DOM только сейчас.)
			container.appendChild(importHost);
			WSCApp._renderImportStatus(importHost, cityId);
		},

		_bboxPolygon: function (b) {
			var w = b.getWest(), s = b.getSouth(), e = b.getEast(), n = b.getNorth();
			return { type: 'Polygon', coordinates: [[ [w,s],[e,s],[e,n],[w,n],[w,s] ]] };
		},

		_fitToCity: function (map, cityId) {
			fetch(cfg.restUrl + 'city/' + cityId + '/boundary')
				.then(function (r) { return r.json(); })
				.then(function (d) {
					if (!d || !d.bbox) return;
					var bb = d.bbox;
					if (bb.w === 0 && bb.e === 0) return;
					map.fitBounds([[bb.w, bb.s], [bb.e, bb.n]], { padding: 24, maxZoom: 16, duration: 800 });
				}).catch(function () {});
		},

		_clearSelectedYardHighlight: function (map) {
			if (!map || !map.isStyleLoaded()) return;
			['wsc-selected-yard-line', 'wsc-selected-yard-fill'].forEach(function (lid) {
				if (map.getLayer(lid)) map.removeLayer(lid);
			});
			if (map.getSource && map.getSource('wsc-selected-yard')) map.removeSource('wsc-selected-yard');
		},

		/** Фильтр зданий по легенде (торговые / прочее) + видимость дорог из OSM. */
		_applyMapLegendVisibility: function (map, vis) {
			if (!map || !map.isStyleLoaded()) return;
			// Источник истины для категорий зданий — per-map state (map._wscState.categoriesDisabled).
			// Не «протекает» между разными городами на одной странице.
			var disabledMap = (map._wscState && map._wscState.categoriesDisabled) || {};
			var allowed = [];
			Object.keys(cfg.cats || {}).forEach(function (k) {
				if (disabledMap[k]) return;
				allowed.push(k);
			});
			// Фильтр для обоих представлений зданий: 2D-fill и 3D-extrusion. Если все категории
			// отключены — заведомо-ложный фильтр, чтобы слой стал пустым.
			var lyrFilter = allowed.length
				? ['in', ['get', 'category'], ['literal', allowed]]
				: ['==', ['get', 'category'], '__none__'];
			['wsc-buildings-fill', 'wsc-buildings-3d'].forEach(function (lid) {
				if (map.getLayer(lid)) map.setFilter(lid, lyrFilter);
			});
			var roadVis = !vis || vis.roads !== false ? 'visible' : 'none';
			['wsc-roads-line', 'wsc-roads-parking-fill'].forEach(function (lid) {
				if (map.getLayer(lid)) map.setLayoutProperty(lid, 'visibility', roadVis);
			});
			// Halo и точки POI учитывают и категории, и придомовую территорию активного здания.
			WSCApp._applyPointsFilter(map);
			// Тепловая карта и кластеры тоже должны исчезать при выключении категории.
			if (typeof map._wscHeatmapApplyFilter === 'function') { map._wscHeatmapApplyFilter(); }
			if (typeof map._wscClustersApplyFilter === 'function') { map._wscClustersApplyFilter(); }
		},

		/**
		 * Заведомо-ложный фильтр для скрытия слоя, когда нет yard-полигона.
		 * Использует __wsc_hidden__ — несуществующее свойство, чтобы выражение всегда давало false.
		 */
		_hiddenFilter: function () {
			return ['==', ['get', '__wsc_hidden__'], 1];
		},

		/** Ray-casting point-in-polygon. Зеркало WSC_Geom::point_in_polygon(). */
		_pointInPolygon: function (pt, geom) {
			if (!pt || !geom) return false;
			var x = pt[0], y = pt[1];
			function inRing(ring) {
				var inside = false;
				for (var i = 0, j = ring.length - 1; i < ring.length; j = i++) {
					var xi = ring[i][0], yi = ring[i][1];
					var xj = ring[j][0], yj = ring[j][1];
					var intersect = ((yi > y) !== (yj > y))
						&& (x < (xj - xi) * (y - yi) / ((yj - yi) || 1e-12) + xi);
					if (intersect) inside = !inside;
				}
				return inside;
			}
			function inPolygon(poly) {
				if (!poly || !poly.length) return false;
				// outer ring + holes: внутри если в outer и НЕ в holes.
				if (!inRing(poly[0])) return false;
				for (var h = 1; h < poly.length; h++) {
					if (inRing(poly[h])) return false;
				}
				return true;
			}
			if (geom.type === 'Polygon') return inPolygon(geom.coordinates);
			if (geom.type === 'MultiPolygon') {
				for (var i = 0; i < geom.coordinates.length; i++) {
					if (inPolygon(geom.coordinates[i])) return true;
				}
			}
			return false;
		},

		/** Среднее координат внешнего кольца. Для мелких POI-полигонов достаточно. */
		_polygonCentroid: function (geom) {
			if (!geom) return null;
			var ring = null;
			if (geom.type === 'Polygon') ring = (geom.coordinates || [])[0];
			else if (geom.type === 'MultiPolygon') ring = ((geom.coordinates || [])[0] || [])[0];
			if (!ring || !ring.length) return null;
			var sx = 0, sy = 0, n = 0;
			for (var i = 0; i < ring.length; i++) {
				sx += ring[i][0];
				sy += ring[i][1];
				n++;
			}
			if (!n) return null;
			return [sx / n, sy / n];
		},

		/**
		 * Halo для критических POI (healthcare / infra_safety): учитывает и активные категории
		 * легенды, и придомовую территорию. Возвращает MapLibre-фильтр.
		 */
		_buildHaloFilter: function (map) {
			var state = (map && map._wscState) || {};
			var disabledMap = state.categoriesDisabled || {};
			var critical = ['healthcare', 'infra_safety'].filter(function (k) { return !disabledMap[k]; });
			if (!critical.length) return WSCApp._hiddenFilter();
			var poly = state.yardFilterPolygon;
			if (!poly) return WSCApp._hiddenFilter();
			return ['all',
				['in', ['geometry-type'], ['literal', ['Point', 'MultiPoint']]],
				['in', ['get', 'category'], ['literal', critical]],
				['within', { type: 'Feature', geometry: poly, properties: {} }]
			];
		},

		/**
		 * Главный фильтр POI/деревьев по придомовой территории. Когда yardFilterPolygon === null,
		 * все слои становятся пустыми.
		 */
		_applyPointsFilter: function (map) {
			if (!map || !map.isStyleLoaded()) return;
			var state = map._wscState || {};
			var poly = state.yardFilterPolygon;
			var POI_POINT = ['in', ['geometry-type'], ['literal', ['Point', 'MultiPoint']]];
			var POI_POLYGON = ['in', ['geometry-type'], ['literal', ['Polygon', 'MultiPolygon']]];

			if (map.getLayer('wsc-pois-circle')) {
				var pointFilter = poly
					? ['all', POI_POINT, ['within', { type: 'Feature', geometry: poly, properties: {} }]]
					: WSCApp._hiddenFilter();
				map.setFilter('wsc-pois-circle', pointFilter);
			}

			if (map.getLayer('wsc-pois-circle-halo')) {
				map.setFilter('wsc-pois-circle-halo', WSCApp._buildHaloFilter(map));
			}

			if (map.getLayer('wsc-pois-polygon-fill')) {
				var polyFilter;
				if (!poly) {
					polyFilter = WSCApp._hiddenFilter();
				} else {
					var allowed = [];
					try {
						var feats = map.querySourceFeatures('wsc-pois', { filter: POI_POLYGON });
						for (var i = 0; i < feats.length; i++) {
							var f = feats[i];
							var c = WSCApp._polygonCentroid(f.geometry);
							if (c && WSCApp._pointInPolygon(c, poly)) {
								var id = (f.id != null) ? f.id
									: (f.properties && f.properties.id != null ? f.properties.id : null);
								if (id != null) allowed.push(id);
							}
						}
					} catch (_) {}
					polyFilter = allowed.length
						? ['all', POI_POLYGON, ['in', ['id'], ['literal', allowed]]]
						: WSCApp._hiddenFilter();
				}
				map.setFilter('wsc-pois-polygon-fill', polyFilter);
			}

			WSCApp._applyTreesFilter(map);
		},

		/** Пересоздаёт instanced-mesh деревьев только теми точками, что лежат внутри двора. */
		_applyTreesFilter: function (map) {
			var state = (map && map._wscState) || {};
			var fc = state.treesAllFC;
			if (!fc || !WSCApp._treesLayer || !WSCApp._treesLayer.updateData) return;
			var poly = state.yardFilterPolygon;
			var features = [];
			if (poly) {
				features = (fc.features || []).filter(function (f) {
					return f.geometry && f.geometry.type === 'Point'
						&& WSCApp._pointInPolygon(f.geometry.coordinates, poly);
				});
			}
			WSCApp._treesLayer.updateData({ type: 'FeatureCollection', features: features });
		},

		/** Следующий слой в стеке после baseId — для вставки «между» слоями. */
		_layerIdAbove: function (map, baseId) {
			var ls = map && map.getStyle && map.getStyle().layers;
			if (!ls) return undefined;
			for (var i = 0; i < ls.length; i++) {
				if (ls[i].id === baseId && i + 1 < ls.length) return ls[i + 1].id;
			}
			return undefined;
		},

		_clearCourtyardObjectFocus: function (map) {
			if (!map || !map.isStyleLoaded()) return;
			['wsc-courtyard-focus-poi', 'wsc-courtyard-focus-land-line', 'wsc-courtyard-focus-land-fill'].forEach(function (lid) {
				if (map.getLayer(lid)) map.removeLayer(lid);
			});
			if (map.getSource && map.getSource('wsc-courtyard-focus')) map.removeSource('wsc-courtyard-focus');
		},

		/**
		 * POI и landuse из ответа courtyard-contents — поверх базового слоя POI.
		 */
		_applyCourtyardObjectFocus: function (map, fc) {
			WSCApp._clearCourtyardObjectFocus(map);
			if (!fc || !fc.features || !fc.features.length || !map || !map.isStyleLoaded()) return;
			var beforeId = WSCApp._layerIdAbove(map, 'wsc-pois-circle');
			try {
				map.addSource('wsc-courtyard-focus', { type: 'geojson', data: fc });
				var landFill = {
					id: 'wsc-courtyard-focus-land-fill',
					type: 'fill',
					source: 'wsc-courtyard-focus',
					filter: ['==', ['get', '_hl'], 'landuse'],
					paint: {
						'fill-color': '#4ade80',
						'fill-opacity': 0.38,
					},
				};
				var landLine = {
					id: 'wsc-courtyard-focus-land-line',
					type: 'line',
					source: 'wsc-courtyard-focus',
					filter: ['==', ['get', '_hl'], 'landuse'],
					paint: {
						'line-color': '#166534',
						'line-width': 2.2,
						'line-opacity': 0.9,
					},
				};
				var catColors = cfg.colors || {};
				var catMatch = ['match', ['get', 'cat']];
				Object.keys(catColors).forEach(function (k) {
					catMatch.push(k);
					catMatch.push(catColors[k]);
				});
				catMatch.push('#fbbf24');

				var poiCirc = {
					id: 'wsc-courtyard-focus-poi',
					type: 'circle',
					source: 'wsc-courtyard-focus',
					filter: ['==', ['get', '_hl'], 'poi'],
					paint: {
						'circle-radius': 9,
						'circle-color': catMatch,
						'circle-stroke-width': 3,
						'circle-stroke-color': '#0f172a',
						'circle-opacity': 0.96,
					},
				};
				function addLyr(layerDef) {
					if (beforeId) map.addLayer(layerDef, beforeId);
					else map.addLayer(layerDef);
				}
				addLyr(landFill);
				addLyr(landLine);
				addLyr(poiCirc);
			} catch (err) {
				WSCApp._clearCourtyardObjectFocus(map);
			}
		},

		_applySelectedYardHighlight: function (map, yardFeat) {
			WSCApp._clearSelectedYardHighlight(map);
			if (!yardFeat || !yardFeat.geometry || !map || !map.isStyleLoaded()) return;
			var before = map.getLayer('wsc-pois-circle') ? 'wsc-pois-circle' : undefined;
			try {
				map.addSource('wsc-selected-yard', {
					type: 'geojson',
					data: { type: 'FeatureCollection', features: [yardFeat] },
				});
				var fillOpts = {
					id: 'wsc-selected-yard-fill',
					type: 'fill',
					source: 'wsc-selected-yard',
					paint: { 'fill-color': '#0369a1', 'fill-opacity': 0.32 },
				};
				var lineOpts = {
					id: 'wsc-selected-yard-line',
					type: 'line',
					source: 'wsc-selected-yard',
					paint: {
						'line-color': '#0284c7',
						'line-width': 3,
						'line-opacity': 0.95,
					},
				};
				if (before) {
					map.addLayer(fillOpts, before);
					map.addLayer(lineOpts, before);
				} else {
					map.addLayer(fillOpts);
					map.addLayer(lineOpts);
				}
			} catch (err) {
				WSCApp._clearSelectedYardHighlight(map);
			}
		},

		/**
		 * Русские множественные формы: [одна, две-четыре, пять+/ноль].
		 * Пример: _pluralRu(11, ['объект','объекта','объектов']) → 'объектов'.
		 */
		_pluralRu: function (n, forms) {
			var n10 = Math.abs(n) % 10;
			var n100 = Math.abs(n) % 100;
			if (n100 >= 11 && n100 <= 14) return forms[2];
			if (n10 === 1) return forms[0];
			if (n10 >= 2 && n10 <= 4) return forms[1];
			return forms[2];
		},

		_renderCourtyardContentsHtml: function (j) {
			var esc = WSCApp._escapeHtml;
			var plural = WSCApp._pluralRu;

			if (!j || j.status === 'no_yard') {
				return '<p class="wsc-courtyard-msg">' +
					esc((j && j.message) ? j.message : 'Нет данных о буфере.') + '</p>';
			}
			if (j.code || j.status === 'error') {
				var em = j.message || JSON.stringify(j);
				return '<p class="wsc-courtyard-msg">' + esc(String(em || 'Не удалось загрузить список.')) + '</p>';
			}
			if (j.status !== 'ok') {
				return '<p class="wsc-courtyard-msg">' + esc('Не удалось загрузить список.') + '</p>';
			}

			var totalIn = typeof j.total_pois_inside === 'number'
				? j.total_pois_inside
				: ((j.pois && j.pois.length) || 0);
			var hasPois = totalIn > 0;
			var hasLand = !!(j.landuse && j.landuse.length);

			// Чипы наверху: радиус буфера + общее число объектов + предупреждение об усечении.
			var chips = '<div class="wsc-courtyard-stats">';
			if (typeof j.buffer_m === 'number') {
				chips += '<span class="wsc-courtyard-chip wsc-courtyard-chip--buffer" title="Радиус буфера">' +
					'<span class="wsc-courtyard-chip-ico" aria-hidden="true">⌖</span>' +
					esc(String(Math.round(j.buffer_m))) + ' м</span>';
			}
			if (hasPois) {
				chips += '<span class="wsc-courtyard-chip wsc-courtyard-chip--count">' +
					'<b>' + esc(String(totalIn)) + '</b> ' +
					esc(plural(totalIn, ['объект', 'объекта', 'объектов'])) + '</span>';
			}
			if (j.pois_truncated) {
				var lim = j.pois_max || (j.pois && j.pois.length) || 0;
				chips += '<span class="wsc-courtyard-chip wsc-courtyard-chip--note">показано ' + esc(String(lim)) + '</span>';
			}
			chips += '</div>';

			// POI группы по категориям с цветной точкой (тот же цвет, что в легенде).
			var secPoi = '';
			if (hasPois) {
				var groups = {};
				(j.pois || []).forEach(function (it) {
					var c = it.category || 'other';
					if (!groups[c]) groups[c] = [];
					groups[c].push(it);
				});

				var poiKeys = Object.keys(groups);
				var catOrder = Object.keys(cfg.cats || {});
				poiKeys.sort(function (a, b) {
					var ia = catOrder.indexOf(a);
					var ib = catOrder.indexOf(b);
					if (ia < 0 && ib < 0) return String(a).localeCompare(String(b));
					if (ia < 0) return 1;
					if (ib < 0) return -1;
					return ia - ib;
				});

				secPoi = '<section class="wsc-courtyard-section">' +
					'<div class="wsc-courtyard-section-h">Точечные объекты</div>' +
					'<div class="wsc-courtyard-scroll">';

				poiKeys.forEach(function (k) {
					var list = groups[k];
					if (!list.length) return;
					var title = (cfg.cats && cfg.cats[k]) ? cfg.cats[k] : k;
					var color = (cfg.colors && cfg.colors[k]) ? cfg.colors[k] : '#94a3b8';

					// Делим на названные и безымянные. Безымянные не показываем по одному —
					// в OSM это `way #1390913096`, что юзеру не говорит ровно ничего; собираем
					// их в одну строку «+ N без названия».
					var named = [];
					var nameless = 0;
					list.forEach(function (it) {
						var nm = it.name && String(it.name).trim();
						if (nm) named.push(nm); else nameless++;
					});

					secPoi += '<div class="wsc-courtyard-grp">' +
						'<header class="wsc-courtyard-grp-h">' +
							'<span class="wsc-courtyard-grp-dot" style="background:' + esc(color) + '"></span>' +
							'<span class="wsc-courtyard-grp-title">' + esc(title) + '</span>' +
							'<span class="wsc-courtyard-badge">' + esc(String(list.length)) + '</span>' +
						'</header>';

					if (named.length) {
						secPoi += '<ul class="wsc-courtyard-list">';
						named.forEach(function (nm) {
							secPoi += '<li class="wsc-courtyard-item">' + esc(nm) + '</li>';
						});
						secPoi += '</ul>';
					}
					if (nameless > 0) {
						secPoi += '<div class="wsc-courtyard-noname">' +
							(named.length ? '+ ' : '') +
							esc(String(nameless)) + ' ' +
							esc(plural(nameless, ['объект без названия', 'объекта без названия', 'объектов без названия'])) +
							'</div>';
					}
					secPoi += '</div>';
				});

				secPoi += '</div></section>';
			} else {
				secPoi = '<div class="wsc-courtyard-empty">Внутри буфера нет учтённых POI.</div>';
			}

			// Landuse: тот же подход — безымянные не дублируем как «way #...».
			var secLu = '';
			if (hasLand) {
				secLu = '<section class="wsc-courtyard-section">' +
					'<div class="wsc-courtyard-section-h">Землепользование ' +
					'<span class="wsc-courtyard-section-sub">парк, газон и др.</span></div>';
				if (j.landuse_truncated) {
					secLu += '<p class="wsc-courtyard-note">Показана часть списка.</p>';
				}
				secLu += '<div class="wsc-courtyard-scroll"><ul class="wsc-courtyard-list wsc-courtyard-list--land">';
				var luNameless = 0;
				j.landuse.forEach(function (u) {
					var name = u.name && String(u.name).trim();
					var kind = u.kind || '';
					if (name) {
						secLu += '<li class="wsc-courtyard-item">' + esc(name) +
							(kind ? ' <span class="wsc-courtyard-mini">' + esc(kind) + '</span>' : '') +
							'</li>';
					} else if (kind) {
						secLu += '<li class="wsc-courtyard-item wsc-courtyard-item--kind">' + esc(kind) + '</li>';
					} else {
						luNameless++;
					}
				});
				secLu += '</ul>';
				if (luNameless > 0) {
					secLu += '<div class="wsc-courtyard-noname">+ ' + esc(String(luNameless)) + ' ' +
						esc(plural(luNameless, ['участок без названия', 'участка без названия', 'участков без названия'])) +
						'</div>';
				}
				secLu += '</div></section>';
			}

			return '<div class="wsc-courtyard-inner">' + chips + secPoi + secLu + '</div>';
		},

		_loadCourtyardContentsInPopup: function (map, cityId, innerId, bodyWrap) {
			if (!cfg.restUrl || !innerId || !bodyWrap) return;
			var url = wscApiUrl('city/' + cityId + '/building/' + innerId + '/courtyard-contents');
			bodyWrap.innerHTML = '<div class="wsc-courtyard-loading">' + WSCApp._escapeHtml('Загрузка…') + '</div>';

			fetch(url)
				.then(function (r) {
					return r.json().catch(function () { return {}; }).then(function (j) {
						j = j || {};
						if (!r.ok) {
							return {
								status: 'error',
								code: j.code,
								message: j.message || j.code || ('HTTP ' + r.status),
							};
						}
						return j;
					});
				})
				.then(function (j) {
					bodyWrap.innerHTML = WSCApp._renderCourtyardContentsHtml(j);
					var state = map._wscState || (map._wscState = {});
					if (j && j.status === 'ok' && j.yard_polygon && map.isStyleLoaded && map.isStyleLoaded()) {
						WSCApp._applySelectedYardHighlight(map, j.yard_polygon);
						// Активируем фильтр POI/деревьев по придомовой территории.
						state.yardFilterPolygon = (j.yard_polygon && j.yard_polygon.geometry) || null;
						WSCApp._applyPointsFilter(map);
						var hg = j.highlight_geojson;
						if (hg && hg.features && hg.features.length) {
							WSCApp._applyCourtyardObjectFocus(map, hg);
						} else {
							WSCApp._clearCourtyardObjectFocus(map);
						}
					} else {
						WSCApp._clearSelectedYardHighlight(map);
						WSCApp._clearCourtyardObjectFocus(map);
						state.yardFilterPolygon = null;
						WSCApp._applyPointsFilter(map);
					}
				})
				.catch(function () {
					bodyWrap.innerHTML = '<p class="wsc-courtyard-msg">' +
						WSCApp._escapeHtml('Ошибка сети при загрузке списка.') + '</p>';
					WSCApp._clearSelectedYardHighlight(map);
					WSCApp._clearCourtyardObjectFocus(map);
					var st = map._wscState || (map._wscState = {});
					st.yardFilterPolygon = null;
					WSCApp._applyPointsFilter(map);
				});
		},

		/**
		 * Открывает floating info-panel поверх карты с сводкой по зданию.
		 *
		 * Унифицировано:
		 *  - Шапка: категория + название + адрес + кнопка закрытия.
		 *  - Активный overlay (если включён) — значение этого здания + балл.
		 *  - Эргономичность: 6 dim-баров + общий E (auto-load).
		 *  - Придомовая территория: accordion с точечными объектами и landuse.
		 *
		 * Заменяет старый maplibregl.Popup → единый компактный slide-in справа.
		 */
		_openBuildingPopup: function (map, cityId, feature, lngLat) {
			var esc = WSCApp._escapeHtml;
			var p = feature.properties || {};
			var innerId = parseInt(p.id, 10) || 0;
			var osmType = String(p.osm_type || '');
			var osmId   = parseInt(p.osm_id, 10) || 0;

			var nameTxt = (p.name && String(p.name).trim());
			var addrTxt = (p.address && String(p.address).trim());
			var title = nameTxt || addrTxt || 'Здание';
			var subline = (nameTxt && addrTxt) ? addrTxt : '';

			var catKey = String(p.category || 'other');
			var catLabel = (cfg.cats && cfg.cats[catKey]) ? cfg.cats[catKey] : catKey;
			var catColor = (cfg.colors && cfg.colors[catKey]) ? cfg.colors[catKey] : '#94a3b8';

			var dims = [];
			if (p.height) dims.push(esc(String(p.height)) + ' м');
			if (p.levels) dims.push(esc(String(p.levels)) + ' эт.');

			var html = [
				'<header class="wsc-info-panel__head">',
					'<span class="wsc-info-panel__cat" style="background:' + esc(catColor) + '">' + esc(catLabel) + '</span>',
					'<h3 class="wsc-info-panel__title">' + esc(title) + '</h3>',
					subline ? '<div class="wsc-info-panel__sub">' + esc(subline) + '</div>' : '',
					dims.length ? '<div class="wsc-info-panel__dims">' + dims.join(' · ') + '</div>' : '',
					'<button type="button" class="wsc-info-panel__close" aria-label="Закрыть">×</button>',
				'</header>',
				'<section class="wsc-info-panel__overlay" hidden></section>',
				(osmType && osmId) ? '<section class="wsc-info-panel__ergo"><div class="wsc-ergo-loading">Считаем эргономичность…</div></section>' : '',
				innerId
					? '<details class="wsc-info-panel__yard"><summary>Придомовая территория</summary><div class="wsc-info-panel__yard-body"></div></details>'
					: '',
			].join('');

			var panel = WSCApp._showInfoPanel(map, html);
			if (!panel) { return; }

			// Async: подгружаем overlay-значение если overlay включён.
			WSCApp._fillOverlaySection(map, panel, p);

			// Async: подгружаем эргономичность.
			if (osmType && osmId) {
				var ergoPane = panel.querySelector('.wsc-info-panel__ergo');
				var url = wscApiUrl('building-ergo', { osm_type: osmType, osm_id: osmId });
				fetch(url, { headers: { 'X-WP-Nonce': cfg.nonce } })
					.then(function (r) {
						return r.text().then(function (body) {
							try { return JSON.parse(body); }
							catch (e) { return { code: 'parse_error', message: body.substring(0, 200) }; }
						});
					})
					.then(function (j) {
						if (!ergoPane) { return; }
						if (!j || j.code) {
							ergoPane.innerHTML = '<div class="wsc-ergo-error">' + esc((j && j.message) || 'Не удалось получить') + '</div>';
							return;
						}
						ergoPane.innerHTML = WSCApp._renderErgoPanel(j);
						WSCApp._bindErgoPanelClicks(ergoPane);
					})
					.catch(function (err) {
						if (ergoPane) {
							ergoPane.innerHTML = '<div class="wsc-ergo-error">Ошибка: ' + esc(err && err.message || err) + '</div>';
						}
					});
			}

			// Async: подгружаем содержимое двора.
			if (innerId) {
				var cBody = panel.querySelector('.wsc-info-panel__yard-body');
				if (cBody) {
					WSCApp._loadCourtyardContentsInPopup(map, cityId, innerId, cBody);
				}
			}
		},

		/**
		 * Показывает floating info-panel поверх карты с заданным HTML.
		 * Биндит обработчик закрытия.
		 */
		_showInfoPanel: function (map, html) {
			var panel = map && map._wscInfoPanel;
			if (!panel) { return null; }
			panel.innerHTML = html;
			panel.hidden = false;
			// requestAnimationFrame чтобы CSS-transition сработал.
			window.requestAnimationFrame(function () {
				panel.classList.add('is-visible');
			});
			var closeBtn = panel.querySelector('.wsc-info-panel__close');
			if (closeBtn) {
				closeBtn.addEventListener('click', function () {
					WSCApp._hideInfoPanel(map);
				});
			}
			return panel;
		},

		_hideInfoPanel: function (map) {
			var panel = map && map._wscInfoPanel;
			if (!panel) { return; }
			panel.classList.remove('is-visible');
			// Очистка состояний (была в popup.on('close')).
			WSCApp._clearSelectedYardHighlight(map);
			WSCApp._clearCourtyardObjectFocus(map);
			var st = map._wscState || (map._wscState = {});
			st.yardFilterPolygon = null;
			WSCApp._applyPointsFilter(map);
			// Прячем после анимации.
			setTimeout(function () {
				if (!panel.classList.contains('is-visible')) {
					panel.hidden = true;
					panel.innerHTML = '';
				}
			}, 240);
		},

		/**
		 * Заполняет секцию `.wsc-info-panel__overlay` значением активного слоя
		 * эргономики для текущего здания. Если overlay не активен — секция
		 * остаётся скрытой.
		 *
		 * Источник значений: уже загруженный source `wsergo-metric` (если активен
		 * choropleth-оверлей) — там в каждом feature есть value/score/has_data.
		 */
		_fillOverlaySection: function (map, panelEl, buildingProps) {
			var section = panelEl.querySelector('.wsc-info-panel__overlay');
			if (!section) { return; }
			var src = map.getSource && map.getSource('wsergo-metric');
			if (!src) { return; } // overlay не активен — секция остаётся hidden
			var meta = map._wscOverlayMeta || null;
			if (!meta) { return; }
			// Ищем feature этого здания в source.
			var data = src._data;
			if (!data || !data.features) { return; }
			var targetId = (buildingProps && buildingProps.id != null) ? Number(buildingProps.id) : null;
			var found = null;
			for (var i = 0; i < data.features.length; i++) {
				var fp = data.features[i].properties || {};
				if (fp.id != null && Number(fp.id) === targetId) { found = fp; break; }
			}
			if (!found) { return; }
			var esc = WSCApp._escapeHtml;
			var label = meta.label || meta.indicator || '';
			var unit = meta.unit ? (' ' + meta.unit) : '';
			var valStr = (found.value != null && !isNaN(found.value)) ? (Number(found.value).toFixed(2).replace(/\.?0+$/, '') + unit) : '—';
			var scoreNum = found.score != null ? Math.round(found.score) : null;
			var scoreCls = WSCApp._ergoClass(scoreNum);
			section.innerHTML =
				'<div class="wsc-info-panel__overlay-row">' +
					'<span class="wsc-info-panel__overlay-label">🎨 ' + esc(label) + '</span>' +
					'<span class="wsc-info-panel__overlay-value">' + esc(valStr) + '</span>' +
					(scoreNum != null
						? '<span class="wsc-info-panel__overlay-score ' + scoreCls + '">' + scoreNum + '/100</span>'
						: '<span class="wsc-info-panel__overlay-score wsc-ergo--na">—</span>'
					) +
				'</div>';
			section.hidden = false;
		},

		_escapeHtml: function (s) {
			return String(s).replace(/[&<>'"]/g, function (ch) {
				return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[ch] || ch;
			});
		},

		_renderErgoPanel: function (j) {
			var e = (typeof j.e === 'number') ? j.e : null;
			var eCls = WSCApp._ergoClass(e);
			var head = '<div class="wsc-ergo-head ' + eCls + '">' +
				'<span class="wsc-ergo-e-label">Эргономичность</span>' +
				'<span class="wsc-ergo-e-value">' + (e === null ? '—' : Math.round(e)) + '</span>' +
			'</div>';

			var esc = function (t) { return WSCApp._escapeHtml(t); };
			var bars = '';
			var labels = j.labels || {};
			var scores = j.scores || {};
			var bd = j.breakdown || {};

			function renderDetail(items) {
				if (!items || !items.length) {
					return '<ul class="wsc-ergo-detail-list"><li class="wsc-ergo-detail-empty">' +
						esc('Нет показателей с заполненными данными.') + '</li></ul>';
				}
				var ul = '';
				items.forEach(function (it) {
					var summary = esc(it.summary || it.label || '');
					var norm = (typeof it.normalized_0_100 === 'number' && !isNaN(it.normalized_0_100))
						? ' <span class="wsc-ergo-detail-norm">' + esc('оценка: ') +
							Math.round(it.normalized_0_100) + '/100</span>'
						: '';
					var src = it.source ? ' <span class="wsc-ergo-detail-src">(' +
						esc(String(it.source)) + ')</span>' : '';
					ul += '<li>' + summary + norm + src + '</li>';
				});
				return '<ul class="wsc-ergo-detail-list">' + ul + '</ul>';
			}

			['functionality', 'safety', 'comfort', 'livability', 'masterability', 'manageability'].forEach(function (k) {
				if (!(k in scores)) return;
				var v = scores[k];
				var cls = WSCApp._ergoClass(v);
				var pct = Math.max(0, Math.min(100, v));
				var items = bd[k] || [];
				bars +=
					'<div class="wsc-ergo-row-wrap">' +
						'<div class="wsc-ergo-row wsc-ergo-row-toggle" role="button" tabindex="0" aria-expanded="false" title="' +
						esc('Показать параметры по этому критерию') + '">' +
							'<div class="wsc-ergo-row-label">' + esc(labels[k] || k) + '</div>' +
							'<div class="wsc-ergo-bar"><div class="wsc-ergo-bar-fill ' + cls +
								'" style="width:' + pct + '%"></div></div>' +
							'<div class="wsc-ergo-row-value">' + Math.round(v) + '</div>' +
							'<span class="wsc-ergo-chevron" aria-hidden="true"></span>' +
						'</div>' +
						'<div class="wsc-ergo-detail" hidden>' +
							renderDetail(items) +
						'</div>' +
					'</div>';
			});

			var more = j.permalink
				? '<a class="wsc-ergo-more" href="' + esc(j.permalink) + '" target="_blank" rel="noopener">Подробнее →</a>'
				: '';
			return head + '<div class="wsc-ergo-bars">' + bars + '</div>' + more;
		},

		_bindErgoPanelClicks: function (container) {
			var wraps = container.querySelectorAll('.wsc-ergo-row-wrap');
			wraps.forEach(function (wrap) {
				var t = wrap.querySelector('.wsc-ergo-row-toggle');
				var detail = wrap.querySelector('.wsc-ergo-detail');
				if (!t || !detail) return;
				var toggle = function () {
					var open = wrap.classList.toggle('wsc-ergo-row--open');
					t.setAttribute('aria-expanded', open ? 'true' : 'false');
					detail.hidden = !open;
				};
				t.addEventListener('click', toggle);
				t.addEventListener('keydown', function (e) {
					if (e.key === 'Enter' || e.key === ' ') {
						e.preventDefault();
						toggle();
					}
				});
			});
		},

		_ergoClass: function (v) {
			if (v === null || v === undefined || isNaN(v)) return 'wsc-ergo--na';
			if (v < 30) return 'wsc-ergo--red';
			if (v < 70) return 'wsc-ergo--orange';
			return 'wsc-ergo--green';
		},

		/**
		 * Блок под картой: пакетный пересчёт E (админы) и таблица зданий с сортировкой.
		 */
		_mountCityErgoBatch: function (container, map, cityId) {
			var esc = function (t) { return WSCApp._escapeHtml(t); };
			var panel = el('div', { class: 'wsc-ergo-city-panel' });
			panel.appendChild(el('h4', { class: 'wsc-ergo-city-heading' }, 'Эргономичность всех зданий'));

			var controls = el('div', { class: 'wsc-ergo-city-controls' });
			var hint = el('p', { class: 'wsc-ergo-city-hint' }, '');
			var btnRun = el('button', { type: 'button', class: 'wsc-ergo-city-btn wsc-ergo-city-btn--primary' }, 'Рассчитать эргономичность города');
			var btnAbort = el('button', { type: 'button', class: 'wsc-ergo-city-btn' }, 'Отмена');
			btnAbort.hidden = true;
			btnAbort.disabled = true;

			var progRow = el('div', { class: 'wsc-ergo-city-progress' });
			progRow.hidden = true;
			var progLbl = el('div', { class: 'wsc-ergo-city-progress-label' }, '');
			var progTrack = el('div', { class: 'wsc-progress wsc-progress--city' });
			var progFill = el('div', { class: 'wsc-progress-bar' });
			var progInner = el('div', { class: 'wsc-progress-bar-fill' });
			progFill.appendChild(progInner);
			progTrack.appendChild(progFill);
			progRow.appendChild(progLbl);
			progRow.appendChild(progTrack);

			if (cfg.canScan) {
				controls.appendChild(btnRun);
				controls.appendChild(btnAbort);
				controls.appendChild(progRow);
				panel.appendChild(controls);
			}
			panel.appendChild(hint);

			var tblWrap = el('div', { class: 'wsc-ergo-city-table-wrap' });
			tblWrap.innerHTML =
				'<table class="wsc-ergo-city-table">' +
				'<thead><tr>' +
				'<th class="wsc-th-sort" tabindex="0" data-wsc-sort="address" scope="col">Адрес <span class="wsc-sort-marker"></span></th>' +
				'<th scope="col">Категория</th>' +
				'<th class="wsc-th-sort" tabindex="0" data-wsc-sort="e" scope="col">E (0–100) <span class="wsc-sort-marker"></span></th>' +
				'<th class="wsc-th-sort" tabindex="0" data-wsc-sort="building_id" scope="col">Здание (id) <span class="wsc-sort-marker"></span></th>' +
				'<th scope="col">Ссылка</th>' +
				'</tr></thead><tbody><tr><td colspan="5" class="wsc-ergo-city-loading">Загрузка…</td></tr></tbody></table>';
			panel.appendChild(tblWrap);
			var tableFoot = el('p', { class: 'wsc-ergo-city-table-foot', hidden: true }, '');
			panel.appendChild(tableFoot);
			container.appendChild(panel);

			var sortState = { orderby: 'e', order: 'desc' };
			var aborted = false;
			var cityBuildings = 0;

			function updateSortMarkers() {
				tblWrap.querySelectorAll('[data-wsc-sort]').forEach(function (th) {
					var k = th.getAttribute('data-wsc-sort');
					var m = th.querySelector('.wsc-sort-marker');
					if (!m) return;
					if (sortState.orderby === k) {
						th.classList.add('is-sorted');
						m.textContent = sortState.order === 'asc' ? '▲' : '▼';
					} else {
						th.classList.remove('is-sorted');
						m.textContent = '';
					}
				});
			}

			function refreshTable() {
				updateSortMarkers();
				var tbody = tblWrap.querySelector('tbody');
				if (!tbody) return;
				tbody.innerHTML = '<tr><td colspan="5" class="wsc-ergo-city-loading">Загрузка…</td></tr>';
				var url = wscApiUrl('city/' + cityId + '/buildings-ergo-list', {
					page: 1,
					per_page: 500,
					orderby: sortState.orderby,
					order: sortState.order,
					_: Date.now(),
				});
				fetch(url, { credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (j) {
						if (!j || j.code) {
							tbody.innerHTML = '<tr><td colspan="5" class="wsc-ergo-city-err">' +
								esc((j && j.message) || 'Ошибка списка зданий') + '</td></tr>';
							return;
						}
						var rows = j.buildings || [];
						if (!rows.length) {
							tbody.innerHTML = '<tr><td colspan="5" class="wsc-ergo-city-empty">' +
								'Нет зданий для этого города.</td></tr>';
							return;
						}
						tbody.innerHTML = '';
						rows.forEach(function (b) {
							var tr = document.createElement('tr');
							var addr = (b.address && String(b.address).trim())
								? esc(b.address)
								: (b.name ? esc(b.name) : esc('#' + (b.building_id || '')));
							var catRaw = cfg.cats && cfg.cats[b.category] ? cfg.cats[b.category] : (b.category || '');
							var catLbl = esc(String(catRaw));
							var eCell = typeof b.e === 'number'
								? '<span class="wsc-ergo-e-cell ' + WSCApp._ergoClass(b.e) + '">' +
									Math.round(b.e) + '</span>'
								: '<span class="wsc-ergo-e-cell wsc-ergo--na">—</span>';
							var link = b.permalink
								? '<a href="' + esc(b.permalink) + '" target="_blank" rel="noopener">' +
									esc('Подробнее') + '</a>'
								: '—';
							tr.innerHTML =
								'<td>' + addr + '</td>' +
								'<td>' + catLbl + '</td>' +
								'<td class="wsc-td-num">' + eCell + '</td>' +
								'<td class="wsc-td-num">' + esc(String(b.building_id || '')) + '</td>' +
								'<td>' + link + '</td>';
							tbody.appendChild(tr);
						});
						if ((j.total || 0) > rows.length && tableFoot) {
							tableFoot.hidden = false;
							tableFoot.textContent = 'Показано ' + rows.length + ' из ' + j.total +
								' зданий (на странице до 500).';
						} else if (tableFoot) {
							tableFoot.hidden = true;
						}
					})
					.catch(function () {
						tbody.innerHTML = '<tr><td colspan="5" class="wsc-ergo-city-err">Сеть или сервер недоступны.</td></tr>';
					});
			}

			tblWrap.querySelectorAll('[data-wsc-sort]').forEach(function (th) {
				th.addEventListener('click', function () {
					var key = th.getAttribute('data-wsc-sort');
					if (!key) return;
					if (sortState.orderby === key) {
						sortState.order = sortState.order === 'asc' ? 'desc' : 'asc';
					} else {
						sortState.orderby = key;
						sortState.order = key === 'e' ? 'desc' : 'asc';
					}
					refreshTable();
				});
				th.addEventListener('keydown', function (e) {
					if (e.key === 'Enter' || e.key === ' ') {
						e.preventDefault();
						th.click();
					}
				});
			});

			fetch(cfg.restUrl + 'city/' + cityId, { credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (sum) {
					cityBuildings = (sum && sum.buildings !== undefined)
						? parseInt(sum.buildings, 10) || 0
						: 0;
					if (cfg.canScan) {
						btnRun.disabled = cityBuildings <= 0;
						hint.textContent = cityBuildings <= 0
							? 'Сначала просканируйте здания в этом городе.'
							: 'Зданий в базе: ' + cityBuildings + '. Пакетный расчёт обновляет индекс E ' +
							  'только для жилых (residential) — для остальных категорий придомовая ' +
							  'эргономика не применима.';
					} else {
						hint.textContent = 'Список зданий города по данным базы.';
					}
					refreshTable();
				})
				.catch(function () {
					hint.textContent = 'Не удалось загрузить сводку города.';
					refreshTable();
				});

			function batchFinish() {
				btnAbort.hidden = true;
				btnAbort.disabled = true;
				btnRun.disabled = cityBuildings <= 0;
				progRow.hidden = true;
			}

			if (cfg.canScan) {
				btnRun.addEventListener('click', function () {
					if (btnRun.disabled) return;
					aborted = false;
					btnRun.disabled = true;
					btnAbort.hidden = false;
					btnAbort.disabled = false;
					progRow.hidden = false;
					progInner.style.width = '0%';

					function chunk(off) {
						if (aborted) {
							progLbl.textContent = 'Остановлено.';
							batchFinish();
							return;
						}
						fetch(wscApiUrl('city/' + cityId + '/ergo-recompute-chunk'), {
							method: 'POST',
							headers: {
								'Content-Type': 'application/json',
								'X-WP-Nonce': cfg.nonce,
							},
							body: JSON.stringify({ offset: off, limit: 35 }),
							credentials: 'same-origin',
						})
							.then(function (r) {
								return r.json().then(function (body) {
									return { ok: r.ok, status: r.status, body: body };
								});
							})
							.then(function (res) {
								var body = res.body || {};
								if (!res.ok || body.code) {
									progLbl.textContent = (body && body.message)
										? body.message
										: ('Ошибка ' + (res.status || ''));
									batchFinish();
									return;
								}
								var total = body.total !== undefined ? body.total : cityBuildings;
								var pct = total > 0
									? Math.min(100, Math.round((body.next_offset / total) * 100))
									: 100;
								progInner.style.width = pct + '%';
								progLbl.textContent = body.next_offset + ' / ' + total;
								if (body.errors && body.errors.length && window.console) {
									console.warn('[WSC ergo chunk] errors:', body.errors);
								}
								if (body.done) {
									progLbl.textContent = 'Готово: ' + total;
									refreshTable();
									batchFinish();
								} else {
									chunk(body.next_offset || (off + (body.processed || 0)));
								}
							})
							.catch(function (err) {
								progLbl.textContent = (err && err.message) ? err.message : 'Сбой запроса';
								batchFinish();
							});
					}

					chunk(0);
				});

				btnAbort.addEventListener('click', function () {
					aborted = true;
				});
			}
		},

		_loadTrees: function (map, cityId) {
			// Bbox-фильтр: иначе грузим ВЕСЬ /layer/pois по городу (мегабайты JSON)
			// и фильтруем 99% на клиенте. Со вьюпорт-bbox сервер сам отдаёт только нужное.
			// Параметры только через wscApiUrl: при plain permalinks (?rest_route=…) второй «?»
			// ломает rest_route и даёт 404 на /layer/pois.
			var bboxKv = null;
			if (map && map.getBounds) {
				var b = map.getBounds();
				bboxKv = { bbox: [b.getWest(), b.getSouth(), b.getEast(), b.getNorth()].join(',') };
			}
			var url = wscApiUrl('city/' + cityId + '/layer/pois', bboxKv);
			fetch(url)
				.then(function (r) { return r.json(); })
				.then(function (fc) {
					var trees = (fc.features || []).filter(function (f) {
						var t = f.properties || {};
						return t.category === 'other' && (((t.name || '').toLowerCase() === 'tree') ||
							(f.geometry && f.geometry.type === 'Point'));
					});
					var treesFC = { type: 'FeatureCollection', features: trees.slice(0, 4000) };
					// Сохраняем полный FC для дальнейшей фильтрации по yard_polygon.
					// Сам слой создаётся пустым — реальные деревья подаст _applyTreesFilter.
					var state = map._wscState || (map._wscState = {});
					state.treesAllFC = treesFC;
					if (window.WSCCreateTreesLayer) {
						if (!map.getLayer('wsc-trees-3d')) {
							var lyr = window.WSCCreateTreesLayer('wsc-trees-3d', { type: 'FeatureCollection', features: [] });
							map.addLayer(lyr);
							WSCApp._treesLayer = lyr;
						}
						WSCApp._applyTreesFilter(map);
					}
				}).catch(function () {});
		},

		_startScan: function (map, cityId, mode, polygon) {
			var body = { mode: mode };
			if (polygon) body.polygon = polygon;

			fetch(cfg.restUrl + 'overpass/precheck', { headers: { 'X-WP-Nonce': cfg.nonce } })
				.then(function (r) { return r.json(); })
				.then(function (pre) {
					if (!pre || !pre.ok) {
						var msg = (pre && pre.message) ? pre.message : 'Overpass недоступен';
						alert('Перед скан-проверка не пройдена:\n' + msg + '\n\nПопробуйте позже или смените endpoint в настройках Courtyard.');
						return;
					}
					if (pre.message) {
						console.info('[WSC precheck] ' + pre.message);
					}
					fetch(cfg.restUrl + 'city/' + cityId + '/scan', {
						method: 'POST',
						headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
						body: JSON.stringify(body),
					}).then(function (r) { return r.json(); }).then(function (j) {
						if (j && j.code) { alert('Ошибка: ' + j.message); return; }
						WSCApp._pollProgress(map, cityId);
					}).catch(function (e) {
						alert('Ошибка скана: ' + e.message);
					});
				})
				.catch(function (e) {
					alert('Не удалось проверить статус Overpass: ' + e.message);
				});
		},

		_pollProgress: function (map, cityId) {
			// Защита от двойного запуска: если уже опрашиваем этот город — отменяем старый цикл.
			// Иначе два setTimeout-цикла наперебой обновляют одну плашку прогресса и утекают.
			if (!WSCApp._pollHandles) WSCApp._pollHandles = {};
			var existing = WSCApp._pollHandles[cityId];
			if (existing) { existing.cancelled = true; }
			var handle = { cancelled: false, timer: 0 };
			WSCApp._pollHandles[cityId] = handle;

			// Бэдж в шапке тулбара — единственный визуальный канал прогресса.
			// Если на этой странице он не смонтирован (read-only-режим? чужой контейнер?),
			// просто молча выходим — детальный лог по job'ам всё равно покажет нижняя секция.
			var badge = map && map._wscScanStatusEl;
			if (!badge) { WSCApp._scanBusy = false; return; }

			var textEl    = badge.querySelector('.wsc-scan-status-text');
			var iconEl    = badge.querySelector('.wsc-scan-status-icon');
			var fillEl    = badge.querySelector('.wsc-scan-status-bar-fill');
			var actionsEl = badge.querySelector('.wsc-scan-status-actions');
			var btnResume = badge.querySelector('.wsc-scan-btn-resume');
			var btnFinish = badge.querySelector('.wsc-scan-btn-finish');

			function setVariant(v) {
				badge.classList.remove('wsc-scan-status--done', 'wsc-scan-status--aborted', 'wsc-scan-status--stuck');
				if (v) badge.classList.add('wsc-scan-status--' + v);
			}
			function hideBadge() {
				badge.hidden = true;
				setVariant(null);
				if (actionsEl) actionsEl.hidden = false;
				if (btnResume) btnResume.hidden = false;
				if (btnFinish) btnFinish.hidden = false;
			}

			// Стартовое состояние: показываем «Старт…», action-кнопки доступны, прогресс=0.
			setVariant(null);
			badge.hidden = false;
			if (actionsEl) actionsEl.hidden = false;
			if (iconEl) iconEl.textContent = '⏳';
			if (textEl) textEl.textContent = 'Старт…';
			if (fillEl) fillEl.style.width = '0%';

			var stallCount = 0;
			var lastDone   = -1;
			// Адаптивный backoff: 2 с при активном прогрессе, плавно до 10 с при «застое».
			var MIN_INTERVAL = 2000;
			var MAX_INTERVAL = 10000;
			var interval = MIN_INTERVAL;

			WSCApp._scanBusy = true;
			function stop() {
				WSCApp._scanBusy = false;
				handle.cancelled = true;
				if (handle.timer) clearTimeout(handle.timer);
				if (WSCApp._pollHandles && WSCApp._pollHandles[cityId] === handle) {
					delete WSCApp._pollHandles[cityId];
				}
			}

			// Подпись по типу job: source приходит с сервера ('overpass' | 'buffers' | 'pbf').
			var SOURCE_LABEL = {
				'overpass': 'Сканирование',
				'buffers':  'Пересчёт буферов',
				'pbf':      'Импорт PBF',
			};
			var DONE_LABEL = {
				'overpass': 'Готово: ',
				'buffers':  'Буферы пересчитаны: ',
				'pbf':      'PBF импортирован: ',
			};

			function tick() {
				if (handle.cancelled) return;
				fetch(cfg.restUrl + 'city/' + cityId + '/scan/status', { headers: { 'X-WP-Nonce': cfg.nonce } })
					.then(function (r) { return r.json(); }).then(function (j) {
						if (handle.cancelled) return;
						if (j.status === 'idle') { hideBadge(); stop(); return; }
						var src = (j.source || 'overpass');
						var label = SOURCE_LABEL[src] || 'Задача';
						var pct = j.total > 0 ? Math.round(100 * j.done / j.total) : 0;
						var stallNote = '';
						if (j.done === lastDone) {
							stallCount++;
							interval = Math.min(MAX_INTERVAL, MIN_INTERVAL + stallCount * 1500);
						} else {
							stallCount = 0;
							lastDone = j.done;
							interval = MIN_INTERVAL;
						}
						if (stallCount >= 4 && src === 'overpass') {
							stallNote = ' · нет прогресса';
						}

						// Resume/Finish осмысленны только для tile-based overpass-скана.
						// Для pbf и buffers оставляем только «Прервать».
						if (btnResume) btnResume.hidden = (src !== 'overpass');
						if (btnFinish) btnFinish.hidden = (src !== 'overpass');

						if (iconEl) iconEl.textContent = '⏳';
						if (textEl) {
							textEl.textContent = label + ': ' + pct + '% (' + j.done + '/' + j.total +
								', ' + j.imported + ' объектов)' + stallNote;
						}
						if (fillEl) fillEl.style.width = pct + '%';

						if (j.status === 'done' || j.status === 'aborted' || j.status === 'stuck') {
							var doneLbl = DONE_LABEL[src] || 'Готово: ';
							var prefix = j.status === 'aborted' ? 'Прервано на '
								: j.status === 'stuck'   ? 'Задача зависла на '
								: doneLbl;
							if (textEl) textEl.textContent = prefix + j.imported + ' объектов';
							if (iconEl) iconEl.textContent = j.status === 'done' ? '✓'
								: j.status === 'aborted' ? '✕' : '⚠';
							setVariant(j.status);
							if (actionsEl) actionsEl.hidden = true;
							setTimeout(function () { hideBadge(); }, 4000);
							WSCApp._reloadSources(map, cityId);
							stop();
						} else {
							handle.timer = setTimeout(tick, interval);
						}
					}).catch(function () {
						if (handle.cancelled) return;
						handle.timer = setTimeout(tick, Math.max(interval, 5000));
					});
			}
			tick();
		},

		_reloadSources: function (map, cityId) {
			// При наличии карты передаём bbox текущего вьюпорта — сервер фильтрует через
			// составные индексы (city_id, centroid_lat/lng | lat/lng) и отдаёт на порядки меньше JSON.
			var params = { _: Date.now() };
			if (map && map.getBounds) {
				var b = map.getBounds();
				params.bbox = [b.getWest(), b.getSouth(), b.getEast(), b.getNorth()].join(',');
			}
			['wsc-buildings', 'wsc-yards', 'wsc-pois', 'wsc-roads'].forEach(function (s) {
				var src = map.getSource(s);
				if (src && src.setData) src.setData(wscApiUrl('city/' + cityId + '/layer/' + s.replace('wsc-', ''), params));
			});
		},

		_recompute: function (cityId) {
			fetch(cfg.restUrl + 'city/' + cityId + '/recompute-buffers', {
				method: 'POST', headers: { 'X-WP-Nonce': cfg.nonce },
			}).then(function (r) { return r.json(); }).then(function (j) {
				if (j && j.code) { alert('Ошибка: ' + j.message); return; }
				// Сервер теперь обрабатывает в фоне через Action Scheduler — показываем тот же прогресс-бар, что и при скане.
				if (WSCApp._currentMap) WSCApp._pollProgress(WSCApp._currentMap, cityId);
			}).catch(function (e) {
				alert('Ошибка пересчёта буферов: ' + e.message);
			});
		},

		// Сводка по городу: горизонтальные CSS-полоски по категориям. Без чарт-библиотек.
		// Один fetch на /city/{id}/stats. Перерисовывается вызовом _renderCityStats(host, cityId).
		_renderCityStats: function (host, cityId) {
			if (!host) return;
			var COLORS = (cfg.colors || {});
			var CATS   = (cfg.cats   || {});

			var BUCKETS = [
				{ key: 'b_1_2',   label: '1–2 эт.',   color: '#bae6fd' },
				{ key: 'b_3_5',   label: '3–5 эт.',   color: '#7dd3fc' },
				{ key: 'b_6_9',   label: '6–9 эт.',   color: '#38bdf8' },
				{ key: 'b_10_16', label: '10–16 эт.', color: '#0ea5e9' },
				{ key: 'b_17p',   label: '17+ эт.',   color: '#0369a1' },
				{ key: 'unknown', label: 'нет данных', color: '#cbd5e1' },
			];

			function escapeHtml(s) {
				return String(s == null ? '' : s).replace(/[<>&]/g, function (c) {
					return ({ '<': '&lt;', '>': '&gt;', '&': '&amp;' })[c];
				});
			}
			function fmt(n) {
				return (n || 0).toLocaleString('ru-RU');
			}
			function row(label, color, count, totalForPct) {
				var pct = totalForPct > 0 ? Math.round(100 * count / totalForPct) : 0;
				return '<div class="wsc-stats-row">' +
					'<span class="wsc-stats-label">' + escapeHtml(label) + '</span>' +
					'<div class="wsc-stats-bar-track"><div class="wsc-stats-bar" style="width:' + pct + '%;background:' + escapeHtml(color) + ';"></div></div>' +
					'<span class="wsc-stats-val">' + fmt(count) + '</span>' +
				'</div>';
			}

			host.innerHTML = '<div class="wsc-stats-head"><strong>Сводка по городу</strong><span class="wsc-stats-spin" aria-hidden="true"></span></div><div class="wsc-stats-body">Загрузка…</div>';
			var body = host.querySelector('.wsc-stats-body');
			var spin = host.querySelector('.wsc-stats-spin');
			if (spin) spin.classList.add('is-on');

			fetch(wscApiUrl('city/' + cityId + '/stats'), { headers: { 'X-WP-Nonce': cfg.nonce } })
				.then(function (r) { return r.json(); })
				.then(function (d) {
					if (spin) spin.classList.remove('is-on');
					if (!d || !d.totals) {
						body.innerHTML = '<div class="wsc-stats-empty">Нет данных для города.</div>';
						return;
					}
					var t = d.totals;
					var bbc = d.buildings_by_category || {};
					var pbc = d.pois_by_category || {};
					var buckets = d.levels_buckets || {};
					var lu = d.top_landuse || [];
					var y = d.yards || { count: 0, avg_area_m2: 0 };

					var html = '';
					// Шапка: всего зданий, POI, дворов и средние характеристики.
					html += '<div class="wsc-stats-totals">' +
						'<div class="wsc-stats-total"><div class="wsc-stats-total-n">' + fmt(t.buildings) + '</div><div class="wsc-stats-total-l">зданий</div></div>' +
						'<div class="wsc-stats-total"><div class="wsc-stats-total-n">' + fmt(t.pois) + '</div><div class="wsc-stats-total-l">POI</div></div>' +
						'<div class="wsc-stats-total"><div class="wsc-stats-total-n">' + fmt(t.yards) + '</div><div class="wsc-stats-total-l">дворов</div></div>' +
						'<div class="wsc-stats-total"><div class="wsc-stats-total-n">' + (t.avg_levels || 0) + '</div><div class="wsc-stats-total-l">эт. в среднем</div></div>' +
					'</div>';

					html += '<div class="wsc-stats-grid">';

					// Здания по категориям
					html += '<section class="wsc-stats-section"><h5>Здания по категориям</h5>';
					if (t.buildings > 0) {
						Object.keys(CATS).forEach(function (k) {
							var lbl = CATS[k] || k;
							var c   = COLORS[k] || '#94a3b8';
							var n   = bbc[k] || 0;
							html += row(lbl, c, n, t.buildings);
						});
					} else {
						html += '<div class="wsc-stats-empty">нет зданий</div>';
					}
					html += '</section>';

					// POI по категориям
					html += '<section class="wsc-stats-section"><h5>POI по категориям</h5>';
					if (t.pois > 0) {
						Object.keys(CATS).forEach(function (k) {
							var lbl = CATS[k] || k;
							var c   = COLORS[k] || '#94a3b8';
							var n   = pbc[k] || 0;
							if (n > 0) html += row(lbl, c, n, t.pois);
						});
					} else {
						html += '<div class="wsc-stats-empty">нет POI</div>';
					}
					html += '</section>';

					// Этажность
					html += '<section class="wsc-stats-section"><h5>Этажность</h5>';
					var levelsTotal = 0;
					BUCKETS.forEach(function (b) { levelsTotal += (buckets[b.key] || 0); });
					if (levelsTotal > 0) {
						BUCKETS.forEach(function (b) {
							html += row(b.label, b.color, buckets[b.key] || 0, levelsTotal);
						});
					} else {
						html += '<div class="wsc-stats-empty">нет данных по этажности</div>';
					}
					html += '</section>';

					// Landuse: топ-5
					html += '<section class="wsc-stats-section"><h5>Landuse: топ-5</h5>';
					if (lu.length) {
						var maxLu = lu.reduce(function (m, r) { return Math.max(m, r.count); }, 0);
						lu.forEach(function (r) {
							html += row(r.kind, '#22c55e', r.count, maxLu);
						});
					} else {
						html += '<div class="wsc-stats-empty">нет landuse</div>';
					}
					html += '</section>';

					html += '</div>'; // /grid

					// Дворы — отдельной нижней строкой, если есть.
					if (y && y.count > 0) {
						html += '<div class="wsc-stats-foot">' +
							'Дворов: <b>' + fmt(y.count) + '</b>' +
							', средняя площадь буфера: <b>' + fmt(Math.round(y.avg_area_m2 || 0)) + ' м²</b>' +
						'</div>';
					}

					body.innerHTML = html;
				})
				.catch(function (e) {
					if (spin) spin.classList.remove('is-on');
					body.innerHTML = '<div class="wsc-stats-error">Ошибка загрузки сводки: ' + escapeHtml(e.message) + '</div>';
				});
		},

		// Постоянная панель «Загрузка данных OSM» под картой. Поллит /jobs (короткие
		// интервалы при наличии running, длинные — иначе) и рисует компактный список.
		_renderImportStatus: function (host, cityId) {
			if (!WSCApp._importHandles) WSCApp._importHandles = {};
			// Отмена предыдущего поллера для этого города (важно при переоткрытии карты).
			var prev = WSCApp._importHandles[cityId];
			if (prev) { prev.cancelled = true; if (prev.timer) clearTimeout(prev.timer); }
			var handle = { cancelled: false, timer: 0 };
			WSCApp._importHandles[cityId] = handle;

			host.innerHTML =
				'<details class="wsc-import-details">' +
					'<summary class="wsc-import-summary">' +
						'<span class="wsc-import-summary-title">Загрузка данных OSM</span>' +
						'<span class="wsc-import-summary-status" aria-live="polite">…</span>' +
						'<span class="wsc-import-spin" aria-hidden="true"></span>' +
						'<button type="button" class="wsc-import-refresh">Обновить</button>' +
					'</summary>' +
					'<div class="wsc-import-body">Загрузка…</div>' +
				'</details>';

			var details = host.querySelector('.wsc-import-details');
			var summaryStatus = host.querySelector('.wsc-import-summary-status');
			var body = host.querySelector('.wsc-import-body');
			var spin = host.querySelector('.wsc-import-spin');

			function escapeHtml(s) {
				return String(s == null ? '' : s).replace(/[<>&]/g, function (c) {
					return ({ '<': '&lt;', '>': '&gt;', '&': '&amp;' })[c];
				});
			}

			var SRC_LABEL = { 'overpass': 'Overpass', 'pbf': 'PBF', 'buffers': 'Буферы' };
			var STATUS_ICON = {
				'running': '⏳',
				'done':    '✓',
				'aborted': '✕',
				'stuck':   '⚠',
			};
			var STATUS_LABEL = {
				'running': 'выполняется',
				'done':    'готово',
				'aborted': 'прервано',
				'stuck':   'зависла',
			};

			function formatTimeShort(s) {
				// Преобразует "2026-05-17 20:08:59" → "17 мая 20:08"
				if (!s) return '—';
				var m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
				if (!m) return s;
				var months = ['янв','фев','мар','апр','мая','июн','июл','авг','сен','окт','ноя','дек'];
				return parseInt(m[3], 10) + ' ' + months[parseInt(m[2], 10) - 1] + ' ' + m[4] + ':' + m[5];
			}

			function tick() {
				if (handle.cancelled) return;
				if (spin) spin.classList.add('is-on');
				fetch(wscApiUrl('city/' + cityId + '/jobs', { limit: 5 }), { headers: { 'X-WP-Nonce': cfg.nonce } })
					.then(function (r) { return r.json(); })
					.then(function (d) {
						if (handle.cancelled) return;
						if (spin) spin.classList.remove('is-on');

						var jobs = (d && d.jobs) ? d.jobs : [];
						if (!jobs.length) {
							body.innerHTML = '<div class="wsc-import-empty">Импортов по этому городу пока нет.</div>';
							if (summaryStatus) summaryStatus.textContent = 'нет задач';
						} else {
							var hasRunning = false;
							var runningCount = 0;
							var html = '';
							jobs.forEach(function (j) {
								if (j.status === 'running') { hasRunning = true; runningCount++; }
								var pct = j.total > 0 ? Math.round(100 * j.done / j.total) : 0;
								var srcLbl = SRC_LABEL[j.source] || j.source;
								var stIcon = STATUS_ICON[j.status] || '';
								var stLbl  = STATUS_LABEL[j.status] || j.status;
								// Однострочная карточка: статус | название | bar | проценты | объекты | дата.
								html +=
									'<article class="wsc-import-job wsc-import-job--' + escapeHtml(j.status) + '" title="#' + j.id + '">' +
										'<span class="wsc-import-job-icon">' + stIcon + '</span>' +
										'<span class="wsc-import-job-src">' + escapeHtml(srcLbl) + '</span>' +
										'<div class="wsc-import-job-bar"><div class="wsc-import-job-bar-fill" style="width:' + pct + '%"></div></div>' +
										'<span class="wsc-import-job-pct">' + pct + '%</span>' +
										'<span class="wsc-import-job-objects"><b>' + j.imported + '</b><em>объектов</em></span>' +
										'<span class="wsc-import-job-time">' + escapeHtml(formatTimeShort(j.updated_at)) + '</span>' +
										(j.log_tail
											? '<details class="wsc-import-job-log"><summary aria-label="Лог задачи ' + j.id + '">···</summary><pre>' + escapeHtml(j.log_tail) + '</pre></details>'
											: '<span class="wsc-import-job-nolog"></span>') +
									'</article>';
							});
							body.innerHTML = html;

							if (summaryStatus) {
								if (hasRunning) {
									summaryStatus.textContent = runningCount === 1
										? '1 задача выполняется'
										: runningCount + ' задач выполняются';
									summaryStatus.className = 'wsc-import-summary-status is-running';
								} else {
									summaryStatus.textContent = 'всё готово';
									summaryStatus.className = 'wsc-import-summary-status is-done';
								}
							}

							// Auto-open при наличии running, auto-collapse когда все готовы.
							// Уважаем явное действие юзера: один раз свернул вручную — больше не разворачиваем.
							if (details && !details.dataset.userTouched) {
								details.open = hasRunning;
							}

							// Если только что появилась завершённая job — освежим тайлы карты
							// и пере-фетчим сводку, чтобы новые цифры подъехали без F5.
							if (!hasRunning && WSCApp._currentMap && WSCApp._lastImportRunning) {
								try { WSCApp._reloadSources(WSCApp._currentMap, cityId); } catch (e) {}
								try {
									var statsHost = host && host.parentNode ? host.parentNode.querySelector('.wsc-stats') : null;
									if (statsHost) WSCApp._renderCityStats(statsHost, cityId);
								} catch (e) {}
							}
							WSCApp._lastImportRunning = hasRunning;
						}

						var nextDelay = (jobs.some(function (j) { return j.status === 'running'; })) ? 3000 : 20000;
						handle.timer = setTimeout(tick, nextDelay);
					})
					.catch(function (e) {
						if (handle.cancelled) return;
						if (spin) spin.classList.remove('is-on');
						body.innerHTML = '<div class="wsc-import-error">Ошибка обновления: ' + escapeHtml(e.message) + '</div>';
						handle.timer = setTimeout(tick, 10000);
					});
			}
			// Отслеживаем явное действие юзера (toggle details), чтобы не auto-open поверх него.
			if (details) {
				details.addEventListener('toggle', function () { details.dataset.userTouched = '1'; });
			}
			host.querySelector('.wsc-import-refresh').addEventListener('click', function (e) {
				// Кнопка внутри <summary> — без stopPropagation клик переключит details.
				e.preventDefault();
				e.stopPropagation();
				if (handle.timer) clearTimeout(handle.timer);
				tick();
			});
			tick();
		},

		/**
		 * Overlay-слой: перекрашивает здания по выбранной метрике эргономичности.
		 * Источник `wsergo-metric` создаётся лениво при первом выборе метрики.
		 * При выборе пустого значения слой скрывается, видимы стандартные цвета категорий.
		 */
		_initErgoOverlay: function (map, cityId, selectEl, legendEl, wsergoBase) {
			if (!selectEl) return;
			var SOURCE_ID = 'wsergo-metric';
			var LAYER_FILL = 'wsergo-metric-fill';
			var LAYER_OUT  = 'wsergo-metric-outline';
			// Generation counter защищает от race condition: если пользователь быстро
			// переключает метрики, ответ предыдущего запроса не должен перезаписывать
			// данные более свежего. Игнорируем устаревшие ответы по generation.
			var reqGen = 0;
			var clickHandler = null;
			var enterHandler = null;
			var leaveHandler = null;

			// 1) Один раз тащим каталог метрик и заполняем select.
			fetch(wsergoBase + 'ergo-metric/catalog')
				.then(function (r) { return r.json(); })
				.then(function (data) {
					selectEl.innerHTML = '';
					selectEl.appendChild(el('option', { value: '' }, '— выключено —'));
					var groups = (data && data.groups) || {};
					Object.keys(groups).forEach(function (gkey) {
						var g = groups[gkey];
						if (!g || !g.metrics || !g.metrics.length) return;
						var og = document.createElement('optgroup');
						og.label = g.label || gkey;
						g.metrics.forEach(function (m) {
							var opt = document.createElement('option');
							opt.value = m.id;
							var labelText = m.label || m.id;
							if (m.unit) labelText += ' (' + m.unit + ')';
							opt.textContent = labelText;
							og.appendChild(opt);
						});
						selectEl.appendChild(og);
					});
				})
				.catch(function () {
					selectEl.innerHTML = '';
					selectEl.appendChild(el('option', { value: '' }, '— каталог недоступен —'));
				});

			// Источники, для которых данные тянутся из API → кнопка «Заполнить» имеет смысл.
			// Чисто-OSM и computed-метрики не сидируются — они приходят из обычного скана.
			var EXTERNAL_PREFIXES = ['b_air_', 'b_pollen_', 'b_temp_', 'b_sun_', 'b_uv_', 'b_population_'];
			function metricSource(indicator) {
				if (!indicator) return null;
				if (indicator.indexOf('b_air_') === 0 || indicator.indexOf('b_pollen_') === 0) return 'open_meteo_air';
				if (indicator.indexOf('b_temp_') === 0 || indicator.indexOf('b_sun_') === 0 || indicator.indexOf('b_uv_') === 0) return 'open_meteo_climate';
				if (indicator.indexOf('b_population_') === 0) return 'worldpop';
				return null;
			}
			function isExternalMetric(indicator) {
				if (!indicator) return false;
				for (var i = 0; i < EXTERNAL_PREFIXES.length; i++) {
					if (indicator.indexOf(EXTERNAL_PREFIXES[i]) === 0) return true;
				}
				return false;
			}

			function showLegend(meta) {
				if (!meta) { legendEl.innerHTML = ''; return; }
				var unit = meta.unit ? ' ' + meta.unit : '';
				var direction = meta.direction || '';
				var hint = '';
				if (direction === 'lower_better') hint = 'меньше — лучше';
				else if (direction === 'higher_better') hint = 'больше — лучше';
				else if (direction === 'optimal' && meta.vopt != null) hint = 'оптимум ' + meta.vopt + unit;
				legendEl.innerHTML = '';
				var grad = el('span', { class: 'wsc-ergo-overlay-gradient' });
				grad.title = 'Шкала баллов 0..100';
				legendEl.appendChild(grad);
				legendEl.appendChild(el('span', { class: 'wsc-ergo-overlay-stats' },
					' данные: ' + (meta.with_data || 0) + ' / ' + (meta.count || 0)
					+ (hint ? ' · ' + hint : '')
				));
				// Кнопка «Заполнить из API» — только для внешних метрик с нулевым покрытием.
				if (isExternalMetric(meta.indicator) && (meta.with_data || 0) === 0 && (meta.count || 0) > 0) {
					var src = metricSource(meta.indicator);
					if (!src) return;
					var seedBtn = el('button', { type: 'button', class: 'wsc-ergo-seed-btn' }, '⟳ Заполнить из API');
					seedBtn.title = 'Запустит фоновую задачу для всех ' + meta.count + ' зданий города';
					seedBtn.addEventListener('click', function () {
						startSeed(meta.indicator, src, meta.count);
					});
					legendEl.appendChild(seedBtn);
				}
			}

			function startSeed(indicator, source, totalBuildings) {
				if (!confirm('Запустить прогон API для ' + totalBuildings + ' зданий?\n\n'
					+ 'Это фоновая задача, обновится за 5-30 минут. Кэш округляет координаты,'
					+ ' так что реальных HTTP-запросов будет в разы меньше.')) return;
				fetch(wsergoBase + 'city/' + cityId + '/seed-external', {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': (window.wsergoBuildingPanel && window.wsergoBuildingPanel.nonce) || cfg.nonce || '' },
					body: JSON.stringify({ source: source })
				}).then(function (r) { return r.json(); }).then(function (j) {
					if (j && j.status === 'queued') {
						legendEl.innerHTML = '<span class="wsc-ergo-overlay-stats">⏳ задача запущена, оценка ' + (j.estimate_seconds || '?') + ' сек. Открывайте карту позже.</span>';
						pollSeedStatus(indicator, source);
					} else if (j && j.status === 'already_running') {
						legendEl.innerHTML = '<span class="wsc-ergo-overlay-stats">⏳ уже запущено</span>';
						pollSeedStatus(indicator, source);
					} else {
						legendEl.innerHTML = '<span class="wsc-ergo-overlay-stats wsc-ergo-error">не удалось запустить: ' + (j && j.message ? j.message : '?') + '</span>';
					}
				}).catch(function (e) {
					legendEl.innerHTML = '<span class="wsc-ergo-overlay-stats wsc-ergo-error">сеть: ' + e.message + '</span>';
				});
			}

			function pollSeedStatus(indicator, source) {
				var statusUrl = wsergoBase + 'city/' + cityId + '/seed-external/status?source=' + encodeURIComponent(source);
				var pollTimer = setInterval(function () {
					fetch(statusUrl).then(function (r) { return r.json(); }).then(function (j) {
						if (!j || !j.running) {
							clearInterval(pollTimer);
							// Перезагружаем слой — данные должны появиться.
							loadMetric(indicator);
							return;
						}
						legendEl.innerHTML = '<span class="wsc-ergo-overlay-stats">⏳ '
							+ j.offset + ' / ' + j.total + ' (' + j.percent + '%)</span>';
					}).catch(function () {});
				}, 10000); // каждые 10 сек
			}

			function removeOverlay() {
				// Снимаем event handlers перед удалением слоя, чтобы избежать утечки
				// при многократных reset-enable циклах.
				if (enterHandler) { map.off('mouseenter', LAYER_FILL, enterHandler); enterHandler = null; }
				if (leaveHandler) { map.off('mouseleave', LAYER_FILL, leaveHandler); leaveHandler = null; }
				if (map.getLayer(LAYER_OUT)) map.removeLayer(LAYER_OUT);
				if (map.getLayer(LAYER_FILL)) map.removeLayer(LAYER_FILL);
				if (map.getSource(SOURCE_ID)) map.removeSource(SOURCE_ID);
				map._wscOverlayMeta = null;
				legendEl.innerHTML = '';
			}

			function loadMetric(indicator) {
				if (!indicator) { removeOverlay(); return; }
				reqGen++;
				var myGen = reqGen;
				var url = wsergoBase + 'city/' + cityId + '/ergo-metric?indicator=' + encodeURIComponent(indicator);
				selectEl.disabled = true;
				legendEl.innerHTML = '<span class="wsc-ergo-overlay-stats">загрузка…</span>';
				fetch(url)
					.then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
					.then(function (res) {
						// Если пользователь переключился на другую метрику ИЛИ отключил overlay —
						// игнорируем устаревший ответ. Иначе он перепишет более свежие данные.
						if (myGen !== reqGen) return;
						selectEl.disabled = false;
						if (!res.ok) {
							legendEl.innerHTML = '<span class="wsc-ergo-overlay-stats wsc-ergo-error">ошибка ' + (res.body && res.body.message || '') + '</span>';
							return;
						}
						var fc = res.body;
						// Сохраняем мета слоя для использования в info-panel при клике по зданию.
						map._wscOverlayMeta = fc && fc.meta ? fc.meta : null;
						if (map.getSource(SOURCE_ID)) {
							map.getSource(SOURCE_ID).setData(fc);
						} else {
							map.addSource(SOURCE_ID, { type: 'geojson', data: fc });
							// Над wsc-buildings-fill, чтобы перекрыть категорийные цвета.
							var beforeLayer = WSCApp._layerIdAbove(map, 'wsc-buildings-fill');
							map.addLayer({
								id: LAYER_FILL,
								type: 'fill',
								source: SOURCE_ID,
								paint: {
									'fill-color': ['coalesce', ['get', 'color'], '#cbd5e1'],
									'fill-opacity': 0.82,
								},
							}, beforeLayer);
							map.addLayer({
								id: LAYER_OUT,
								type: 'line',
								source: SOURCE_ID,
								paint: { 'line-color': '#0f172a', 'line-width': 0.5, 'line-opacity': 0.55 },
							}, beforeLayer);
							// Click по overlay-полигону обслуживает unified info-panel
							// (вызывается через wsc-buildings-fill handler ниже по стеку).
							// Здесь — только cursor pointer для hover-affordance.
							enterHandler = function () { map.getCanvas().style.cursor = 'pointer'; };
							leaveHandler = function () { map.getCanvas().style.cursor = ''; };
							map.on('mouseenter', LAYER_FILL, enterHandler);
							map.on('mouseleave', LAYER_FILL, leaveHandler);
						}
						showLegend(fc.meta);
					})
					.catch(function (err) {
						if (myGen !== reqGen) return;
						selectEl.disabled = false;
						legendEl.innerHTML = '<span class="wsc-ergo-overlay-stats wsc-ergo-error">сеть: ' + err.message + '</span>';
					});
			}

			selectEl.addEventListener('change', function () {
				loadMetric(selectEl.value);
			});
		},

		/**
		 * Тепловая карта E-индекса (kernel density через MapLibre `heatmap`).
		 *
		 * В отличие от _initErgoOverlay (choropleth — раскраска каждого здания), heatmap
		 * агрегирует значения в зоны влияния: видны «горячие» (зелёные/высокий E) и
		 * «холодные» (красные/низкий E) районы города на обзорных зумах.
		 *
		 * Источник — Point-фичи на центроидах зданий с weight = score/100.
		 * Используем ту же ergo-metric endpoint что и choropleth — данные общие.
		 */
		_initErgoHeatmap: function (map, cityId, btnEl, legendEl, wsergoBase) {
			if (!btnEl) return;
			var SOURCE_ID = 'wsergo-heat';
			var LAYER_ID  = 'wsergo-heat-layer';
			var active = false;
			var loading = false;

			// Привязываем апдейтер фильтра к карте, чтобы _applyMapLegendVisibility
			// мог его дёрнуть при переключении категорий в легенде.
			map._wscHeatmapApplyFilter = function () { applyCategoryFilter(); };

			function applyCategoryFilter() {
				if (!map.getLayer(LAYER_ID)) return;
				var state = map._wscState || {};
				var disabledMap = state.categoriesDisabled || {};
				var allowed = [];
				Object.keys(cfg.cats || {}).forEach(function (k) {
					if (!disabledMap[k]) allowed.push(k);
				});
				// Если все категории отключены — заведомо-ложный фильтр, heatmap пропадает.
				var filter = allowed.length
					? ['in', ['get', 'category'], ['literal', allowed]]
					: ['==', ['get', 'category'], '__none__'];
				map.setFilter(LAYER_ID, filter);
			}

			function setLegend(html) {
				if (legendEl) legendEl.innerHTML = html || '';
			}

			function showActive(isOn) {
				active = isOn;
				btnEl.classList.toggle('is-active', isOn);
				if (isOn) {
					setLegend(
						'<span class="wsc-ergo-heatmap-gradient"></span>' +
						'<span class="wsc-ergo-heatmap-hint">0 (низкий E) → 100 (высокий E)</span>'
					);
				} else {
					setLegend('');
				}
			}

			function removeLayer() {
				if (map.getLayer(LAYER_ID)) map.removeLayer(LAYER_ID);
				if (map.getSource(SOURCE_ID)) map.removeSource(SOURCE_ID);
				showActive(false);
			}

			function enableLayer() {
				if (loading) return;
				loading = true;
				btnEl.disabled = true;
				var origTxt = btnEl.textContent;
				btnEl.textContent = '⏳ загрузка…';
				var url = wsergoBase + 'city/' + cityId + '/ergo-metric?indicator=wsergo_index';
				fetch(url)
					.then(function (r) { return r.json(); })
					.then(function (fc) {
						if (!fc || !fc.features) throw new Error('нет данных');
						// Конвертируем footprint-полигоны → Point на центроиде, weight = score/100.
						var points = { type: 'FeatureCollection', features: [] };
						fc.features.forEach(function (f) {
							var p = f.properties || {};
							var score = p.score;
							if (score == null) return;
							// Центроид считаем по footprint bbox — быстрее чем по всем точкам.
							var g = f.geometry;
							if (!g) return;
							var lng, lat;
							try {
								var rings = (g.type === 'Polygon') ? g.coordinates :
									(g.type === 'MultiPolygon' ? g.coordinates[0] : null);
								if (!rings || !rings[0] || !rings[0].length) return;
								var ring = rings[0];
								var minLng = Infinity, maxLng = -Infinity, minLat = Infinity, maxLat = -Infinity;
								for (var i = 0; i < ring.length; i++) {
									if (ring[i][0] < minLng) minLng = ring[i][0];
									if (ring[i][0] > maxLng) maxLng = ring[i][0];
									if (ring[i][1] < minLat) minLat = ring[i][1];
									if (ring[i][1] > maxLat) maxLat = ring[i][1];
								}
								lng = (minLng + maxLng) / 2;
								lat = (minLat + maxLat) / 2;
							} catch (e) { return; }
							points.features.push({
								type: 'Feature',
								geometry: { type: 'Point', coordinates: [lng, lat] },
								properties: {
									score: score,
									weight: score / 100.0,
									category: (p.category || 'other')
								}
							});
						});

						if (map.getSource(SOURCE_ID)) {
							map.getSource(SOURCE_ID).setData(points);
						} else {
							map.addSource(SOURCE_ID, { type: 'geojson', data: points });
						}

						if (!map.getLayer(LAYER_ID)) {
							// Считаем текущий список разрешённых категорий из легенды.
							var state = map._wscState || {};
							var disabledMap = state.categoriesDisabled || {};
							var allowedInit = [];
							Object.keys(cfg.cats || {}).forEach(function (k) {
								if (!disabledMap[k]) allowedInit.push(k);
							});
							var initFilter = allowedInit.length
								? ['in', ['get', 'category'], ['literal', allowedInit]]
								: ['==', ['get', 'category'], '__none__'];
							// MapLibre heatmap-paint:
							// - weight по score 0..1
							// - intensity растёт с зумом (на обзоре пятна крупнее)
							// - radius тоже растёт с зумом (физически фикс. радиус)
							// - color-ramp идёт от красного (low) → жёлтый → зелёный (high)
							//   тот же градиент что и в choropleth для визуальной согласованности.
							map.addLayer({
								id: LAYER_ID,
								type: 'heatmap',
								source: SOURCE_ID,
								filter: initFilter,
								maxzoom: 19,
								paint: {
									'heatmap-weight': ['get', 'weight'],
									'heatmap-intensity': ['interpolate', ['linear'], ['zoom'], 10, 0.4, 13, 0.8, 16, 1.4, 18, 2.0],
									'heatmap-radius':    ['interpolate', ['linear'], ['zoom'], 10, 8, 13, 16, 16, 28, 18, 42],
									'heatmap-opacity':   ['interpolate', ['linear'], ['zoom'], 10, 0.85, 18, 0.55],
									'heatmap-color': [
										'interpolate', ['linear'], ['heatmap-density'],
										0,    'rgba(0,0,0,0)',
										0.05, 'rgba(220,38,38,0.5)',   // красный — низкий E
										0.25, 'rgba(251,146,60,0.75)', // оранж
										0.5,  'rgba(251,191,36,0.85)', // жёлтый — средний E
										0.75, 'rgba(132,204,22,0.85)', // лайм
										1,    'rgba(22,163,74,0.95)'   // зелёный — высокий E
									]
								}
							});
						}
						showActive(true);
					})
					.catch(function (err) {
						setLegend('<span class="wsc-ergo-heatmap-error">не удалось: ' + (err && err.message ? err.message : err) + '</span>');
						active = false;
					})
					.then(function () {
						loading = false;
						btnEl.disabled = false;
						btnEl.textContent = origTxt;
					});
			}

			btnEl.addEventListener('click', function () {
				if (active) { removeLayer(); }
				else { enableLayer(); }
			});
		},

		/**
		 * Кластеры зданий: третий overlay рядом с choropleth и heatmap.
		 *
		 * Алгоритм:
		 *  1. Тянем clusters: post_id → cluster index (k-means на 6 измерениях).
		 *  2. Тянем ergo-metric polygon-FeatureCollection с ergo_post_id + footprint geometry.
		 *  3. Джойним {ergo_post_id → cluster.color} и создаём source `wsergo-cluster`.
		 *  4. Слой type:'fill' с match-выражением для fill-color по cluster.
		 *  5. Фильтр респектит категории легенды (как и heatmap).
		 *
		 * Toggle button, легенда показывает цветовые точки с авто-метками кластеров.
		 */
		_initErgoClusters: function (map, cityId, btnEl, legendEl, wsergoBase) {
			if (!btnEl) return;
			var SOURCE_ID  = 'wsergo-cluster';
			var LAYER_FILL = 'wsergo-cluster-fill';
			var LAYER_OUT  = 'wsergo-cluster-outline';
			var active = false;
			var loading = false;
			var lastClusters = null; // для рендера легенды

			map._wscClustersApplyFilter = function () { applyCategoryFilter(); };

			function applyCategoryFilter() {
				if (!map.getLayer(LAYER_FILL)) return;
				var state = map._wscState || {};
				var disabledMap = state.categoriesDisabled || {};
				var allowed = [];
				Object.keys(cfg.cats || {}).forEach(function (k) {
					if (!disabledMap[k]) allowed.push(k);
				});
				var filter = allowed.length
					? ['in', ['get', 'category'], ['literal', allowed]]
					: ['==', ['get', 'category'], '__none__'];
				map.setFilter(LAYER_FILL, filter);
				if (map.getLayer(LAYER_OUT)) { map.setFilter(LAYER_OUT, filter); }
			}

			function setLegend(html) {
				if (legendEl) legendEl.innerHTML = html || '';
			}

			function renderClusterLegend(clusters) {
				if (!clusters || !clusters.length) { setLegend(''); return; }
				var html = '<div class="wsc-ergo-cluster-legend-items">';
				clusters.forEach(function (c) {
					html += '<span class="wsc-ergo-cluster-legend-item" title="' + (c.label || '').replace(/"/g, '&quot;') + '">'
						+ '<span class="wsc-ergo-cluster-dot" style="background:' + c.color + '"></span>'
						+ '<span class="wsc-ergo-cluster-mini">' + (c.n || 0) + '</span>'
						+ '</span>';
				});
				html += '</div>';
				setLegend(html);
			}

			function showActive(on) {
				active = on;
				btnEl.classList.toggle('is-active', on);
				if (on && lastClusters) { renderClusterLegend(lastClusters); }
				else { setLegend(''); }
			}

			function removeLayer() {
				if (map.getLayer(LAYER_OUT))  { map.removeLayer(LAYER_OUT); }
				if (map.getLayer(LAYER_FILL)) { map.removeLayer(LAYER_FILL); }
				if (map.getSource(SOURCE_ID)) { map.removeSource(SOURCE_ID); }
				showActive(false);
			}

			function enableLayer() {
				if (loading) return;
				loading = true;
				btnEl.disabled = true;
				var origTxt = btnEl.textContent;
				btnEl.textContent = '⏳ загрузка…';

				// Параллельно: cluster API (post_id → cluster id) + ergo-metric (polygons + ergo_post_id).
				Promise.all([
					fetch(wsergoBase + 'city/' + cityId + '/clusters?k=4').then(function (r) { return r.json(); }),
					fetch(wsergoBase + 'city/' + cityId + '/ergo-metric?indicator=wsergo_index').then(function (r) { return r.json(); })
				]).then(function (results) {
					var clusterData = results[0];
					var metricFC = results[1];
					if (!clusterData || !clusterData.clusters || !clusterData.buildings) {
						throw new Error(clusterData && clusterData.message ? clusterData.message : 'нет кластеров');
					}
					if (!metricFC || !metricFC.features) {
						throw new Error('нет полигонов');
					}
					var clusters = clusterData.clusters;
					lastClusters = clusters;
					// post_id → color
					var colorByPost = {};
					var labelByPost = {};
					(clusterData.buildings || []).forEach(function (b) {
						var c = clusters[b.cluster];
						if (c) {
							colorByPost[b.post_id] = c.color;
							labelByPost[b.post_id] = c.label;
						}
					});
					// Конвертируем metric-полигоны в новый FC: оставляем только те у которых есть кластер.
					var features = [];
					metricFC.features.forEach(function (f) {
						var p = f.properties || {};
						var pid = p.ergo_post_id;
						if (!pid || !colorByPost[pid]) return;
						features.push({
							type: 'Feature',
							geometry: f.geometry,
							properties: {
								ergo_post_id: pid,
								category: p.category || 'other',
								color: colorByPost[pid],
								label: labelByPost[pid] || '',
								name: p.name || '',
								address: p.address || ''
							}
						});
					});
					var fc = { type: 'FeatureCollection', features: features };

					if (map.getSource(SOURCE_ID)) {
						map.getSource(SOURCE_ID).setData(fc);
					} else {
						map.addSource(SOURCE_ID, { type: 'geojson', data: fc });
					}

					var state = map._wscState || {};
					var disabledMap = state.categoriesDisabled || {};
					var allowedInit = [];
					Object.keys(cfg.cats || {}).forEach(function (k) {
						if (!disabledMap[k]) allowedInit.push(k);
					});
					var initFilter = allowedInit.length
						? ['in', ['get', 'category'], ['literal', allowedInit]]
						: ['==', ['get', 'category'], '__none__'];

					if (!map.getLayer(LAYER_FILL)) {
						map.addLayer({
							id: LAYER_FILL,
							type: 'fill',
							source: SOURCE_ID,
							filter: initFilter,
							paint: {
								'fill-color': ['get', 'color'],
								'fill-opacity': ['interpolate', ['linear'], ['zoom'], 12, 0.7, 16, 0.85, 18, 0.92]
							}
						});
					}
					if (!map.getLayer(LAYER_OUT)) {
						map.addLayer({
							id: LAYER_OUT,
							type: 'line',
							source: SOURCE_ID,
							filter: initFilter,
							paint: {
								'line-color': ['get', 'color'],
								'line-width': 0.6,
								'line-opacity': 0.6
							}
						});
					}

					showActive(true);
				}).catch(function (err) {
					setLegend('<span class="wsc-ergo-cluster-error">не удалось: ' + (err && err.message ? err.message : err) + '</span>');
					active = false;
				}).then(function () {
					loading = false;
					btnEl.disabled = false;
					btnEl.textContent = origTxt;
				});
			}

			btnEl.addEventListener('click', function () {
				if (active) { removeLayer(); }
				else { enableLayer(); }
			});
		},
	};
})();
