/**
 * Custom MapLibre layer for trees (Three.js instanced billboards).
 * Lightweight: instead of glTF we render textured camera-facing quads
 * using a procedural conifer/deciduous sprite drawn on a canvas.
 */
(function () {
	'use strict';

	function makeTreeTexture(kind) {
		var c = document.createElement('canvas');
		c.width = 64; c.height = 96;
		var ctx = c.getContext('2d');
		ctx.clearRect(0, 0, 64, 96);
		// Trunk
		ctx.fillStyle = '#7c4a1c';
		ctx.fillRect(28, 70, 8, 26);
		// Crown
		if (kind === 'conifer') {
			ctx.fillStyle = '#1b5e20';
			for (var i = 0; i < 4; i++) {
				ctx.beginPath();
				ctx.moveTo(32, 8 + i * 14);
				ctx.lineTo(8 + i * 2, 38 + i * 14);
				ctx.lineTo(56 - i * 2, 38 + i * 14);
				ctx.closePath();
				ctx.fill();
			}
		} else {
			ctx.fillStyle = '#2e7d32';
			ctx.beginPath();
			ctx.arc(32, 36, 28, 0, Math.PI * 2);
			ctx.fill();
		}
		var tex = new THREE.CanvasTexture(c);
		tex.minFilter = THREE.LinearFilter;
		tex.magFilter = THREE.LinearFilter;
		tex.needsUpdate = true;
		return tex;
	}

	window.WSCCreateTreesLayer = function (id, treesGeoJSON) {
		return {
			id: id,
			type: 'custom',
			renderingMode: '3d',
			_trees: treesGeoJSON || { type: 'FeatureCollection', features: [] },
			onAdd: function (map, gl) {
				this.map = map;
				this.camera = new THREE.PerspectiveCamera();
				this.scene = new THREE.Scene();
				var ambient = new THREE.AmbientLight(0xffffff, 0.7);
				this.scene.add(ambient);
				var dir = new THREE.DirectionalLight(0xffffff, 0.6);
				dir.position.set(0, 1, 0.5);
				this.scene.add(dir);

				this.renderer = new THREE.WebGLRenderer({ canvas: map.getCanvas(), context: gl, antialias: true });
				this.renderer.autoClear = false;

				var conifer = makeTreeTexture('conifer');
				var leaf    = makeTreeTexture('leaf');

				var matC = new THREE.MeshBasicMaterial({ map: conifer, transparent: true, alphaTest: 0.4 });
				var matL = new THREE.MeshBasicMaterial({ map: leaf,    transparent: true, alphaTest: 0.4 });

				var feats = (this._trees.features || []).slice(0, 4000); // cap
				if (feats.length === 0) return;

				var origin = feats[0].geometry.coordinates;
				this._origin = origin;
				var mc = maplibregl.MercatorCoordinate.fromLngLat({ lng: origin[0], lat: origin[1] }, 0);
				this._origin_mc = mc;
				var meterScale = mc.meterInMercatorCoordinateUnits();

				var geom = new THREE.PlaneGeometry(8, 12); // ~8m wide, 12m tall
				geom.translate(0, 6, 0); // base at y=0

				var instCount = feats.length;
				var meshC = new THREE.InstancedMesh(geom, matC, instCount);
				var meshL = new THREE.InstancedMesh(geom, matL, instCount);
				var dummy = new THREE.Object3D();
				var ic = 0, il = 0;
				for (var i = 0; i < instCount; i++) {
					var f = feats[i];
					var coords = f.geometry.coordinates;
					var p = maplibregl.MercatorCoordinate.fromLngLat({ lng: coords[0], lat: coords[1] }, 0);
					var dx = (p.x - mc.x) / meterScale;
					var dz = (p.y - mc.y) / meterScale;
					dummy.position.set(dx, 0, dz);
					dummy.rotation.set(0, 0, 0);
					dummy.scale.set(1, 1, 1);
					dummy.updateMatrix();
					var isConifer = ((f.properties || {}).leaf_type || '') === 'needleleaved' || (i % 3 === 0);
					if (isConifer) { meshC.setMatrixAt(ic++, dummy.matrix); }
					else            { meshL.setMatrixAt(il++, dummy.matrix); }
				}
				meshC.count = ic;
				meshL.count = il;
				this.scene.add(meshC);
				this.scene.add(meshL);
				this.meshC = meshC; this.meshL = meshL;
			},
			render: function (gl, matrix) {
				if (!this._origin_mc) return;
				var mc = this._origin_mc;
				var s  = mc.meterInMercatorCoordinateUnits();
				// Build a transform that places our scene at origin in world.
				var m = new THREE.Matrix4().makeTranslation(mc.x, mc.y, mc.z);
				var rot = new THREE.Matrix4().makeRotationX(Math.PI / 2);
				var scale = new THREE.Matrix4().makeScale(s, s, s);
				var l = new THREE.Matrix4().multiplyMatrices(m, rot).multiply(scale);
				var pm = new THREE.Matrix4().fromArray(matrix);
				this.camera.projectionMatrix = pm.multiply(l);
				this.renderer.resetState();
				this.renderer.render(this.scene, this.camera);
				this.map.triggerRepaint();
			},
			updateData: function (geojson) {
				this._trees = geojson || { type: 'FeatureCollection', features: [] };
				if (this.scene && this.meshC) { this.scene.remove(this.meshC); this.scene.remove(this.meshL); }
				if (this.map) this.onAdd(this.map, this.map.painter.context.gl);
				if (this.map) this.map.triggerRepaint();
			},
			onRemove: function () { /* noop */ },
		};
	};
})();
