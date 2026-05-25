/**
 * Bbox / polygon editor wrapper around mapbox-gl-draw.
 */
(function () {
	'use strict';

	window.WSCBboxEditor = {
		_draw: null,
		attach: function (map) {
			if (this._draw) return this._draw;
			if (!window.MapboxDraw) return null;
			this._draw = new window.MapboxDraw({
				displayControlsDefault: false,
				controls: { polygon: true, trash: true },
			});
			map.addControl(this._draw, 'top-left');
			return this._draw;
		},
		getPolygon: function () {
			if (!this._draw) return null;
			var fc = this._draw.getAll();
			if (!fc.features || !fc.features.length) return null;
			var f = fc.features[0];
			if (!f.geometry || (f.geometry.type !== 'Polygon' && f.geometry.type !== 'MultiPolygon')) return null;
			return f.geometry;
		},
		clear: function () { if (this._draw) this._draw.deleteAll(); },
	};
})();
