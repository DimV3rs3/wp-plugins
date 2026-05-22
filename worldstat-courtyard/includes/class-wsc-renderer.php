<?php
/**
 * Renderer: country tab (card grid + city app), public assets.
 *
 * @package WorldStatCourtyard
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSC_Renderer {

	public static function metric_buildings_count( string $iso2 ): int {
		return WSC_Writer::count_buildings_for_country( $iso2 );
	}

	public static function metric_yards_count( string $iso2 ): int {
		return WSC_Writer::count_yards_for_country( $iso2 );
	}

	public static function render_country_tab( string $iso2 ): void {
		try {
			self::do_render_country_tab( $iso2 );
		} catch ( Throwable $e ) {
			echo '<div class="wsp-notice"><p>Не удалось отрисовать вкладку «Придомовая территория»: ' . esc_html( $e->getMessage() ) . '</p></div>';
		}
	}

	private static function do_render_country_tab( string $iso2 ): void {
		// Make sure custom tables exist (frontend-safe upgrade).
		if ( class_exists( 'WSC_Installer' ) ) {
			WSC_Installer::maybe_upgrade();
		}

		$iso2 = strtoupper( $iso2 );
		$cities = WSCities_CPT::get_cities_for_country( $iso2 );
		if ( empty( $cities ) ) {
			echo '<div class="wsp-notice"><p>Нет городов для этой страны. Загрузите базу городов в плагине WorldStat Cities.</p></div>';
			return;
		}

		echo '<div class="wsc-country-tab" data-iso2="' . esc_attr( $iso2 ) . '">';
		echo '<p class="wsc-tab-intro">Выберите город, чтобы открыть карту и просканировать здания и POI из OpenStreetMap. ' .
			 'Буфер придомовой территории по умолчанию — 35&nbsp;м (настраивается).</p>';

		echo '<div class="wsc-city-grid">';
		foreach ( $cities as $c ) {
			try {
				$cnt = WSC_Writer::count_buildings_for_city( (int) $c['id'] );
			} catch ( Throwable $e ) {
				$cnt = 0;
			}
			$meta = number_format( (int) $c['pop_t3'], 0, '', ' ' ) . ' чел.';
			if ( $cnt > 0 ) {
				$meta .= ' · ' . $cnt . ' зданий';
			}
			printf(
				'<button type="button" class="wsc-city-card" data-city-id="%d" data-city-name="%s" data-lat="%s" data-lng="%s">' .
					'<span class="wsc-city-name">%s</span>' .
					'<span class="wsc-city-meta">%s</span>' .
				'</button>',
				(int) $c['id'],
				esc_attr( (string) $c['name'] ),
				esc_attr( (string) (float) $c['lat'] ),
				esc_attr( (string) (float) $c['lng'] ),
				esc_html( (string) $c['name'] ),
				esc_html( $meta )
			);
		}
		echo '</div>';

		echo '<div class="wsc-city-app" id="wsc-city-app" hidden></div>';
		echo '</div>';
	}

	public static function enqueue_public_assets(): void {
		if ( ! is_singular() ) return;

		$pid = get_queried_object_id();
		$is_country = $pid && get_post_type( $pid ) === WorldStat_Country_CPT::SLUG;
		$is_yard    = $pid && in_array( get_post_type( $pid ), [ WSC_CPT::SLUG_YARD, WSC_CPT::SLUG_BUILDING, 'wsp_yard' ], true );
		if ( ! $is_country && ! $is_yard ) return;

		// MapLibre via CDN.
		wp_enqueue_style( 'maplibre-gl', 'https://unpkg.com/maplibre-gl@4.5.0/dist/maplibre-gl.css', [], '4.5.0' );
		wp_enqueue_script( 'maplibre-gl', 'https://unpkg.com/maplibre-gl@4.5.0/dist/maplibre-gl.js', [], '4.5.0', true );
		wp_enqueue_style( 'mapbox-gl-draw', 'https://unpkg.com/@mapbox/mapbox-gl-draw@1.4.3/dist/mapbox-gl-draw.css', [], '1.4.3' );
		wp_enqueue_script( 'mapbox-gl-draw', 'https://unpkg.com/@mapbox/mapbox-gl-draw@1.4.3/dist/mapbox-gl-draw.js', [ 'maplibre-gl' ], '1.4.3', true );
		wp_enqueue_script( 'three', 'https://unpkg.com/three@0.160.0/build/three.min.js', [], '0.160.0', true );

		// filemtime-версия для нашего CSS/JS: WSC_VERSION константа правится редко, поэтому
		// при горячих правках JS браузеры тянут старую копию из кэша и кластеризация/пузырьки
		// не появляются до Ctrl+Shift+R. CDN-либы (maplibre, gl-draw, three) — pinned, их не трогаем.
		$asset_ver = static function ( string $rel ) {
			$path = WSC_DIR . $rel;
			return file_exists( $path ) ? (string) filemtime( $path ) : WSC_VERSION;
		};

		wp_enqueue_style( 'wsc-public', WSC_URL . 'assets/css/wsc.css', [], $asset_ver( 'assets/css/wsc.css' ) );
		wp_enqueue_script( 'wsc-trees', WSC_URL . 'assets/js/wsc-trees-layer.js', [ 'maplibre-gl', 'three' ], $asset_ver( 'assets/js/wsc-trees-layer.js' ), true );
		wp_enqueue_script( 'wsc-bbox',  WSC_URL . 'assets/js/wsc-bbox-editor.js', [ 'maplibre-gl', 'mapbox-gl-draw' ], $asset_ver( 'assets/js/wsc-bbox-editor.js' ), true );
		wp_enqueue_script( 'wsc-map',   WSC_URL . 'assets/js/wsc-map.js',   [ 'maplibre-gl', 'wsc-trees', 'wsc-bbox' ], $asset_ver( 'assets/js/wsc-map.js' ), true );
		wp_enqueue_script( 'wsc-tab',   WSC_URL . 'assets/js/wsc-tab.js',   [ 'wsc-map' ], $asset_ver( 'assets/js/wsc-tab.js' ), true );

		$tiles = WSC_Settings::get_tiles_source();
		$wsergo_rest_base = class_exists( 'WSErgo_REST' )
			? rest_url( WSErgo_REST::NAMESPACE_V1 . '/' )
			: rest_url( 'wsergo/v1/' );
		wp_localize_script( 'wsc-map', 'wscConfig', [
			'restUrl'   => rest_url( WSC_REST_NS . '/' ),
			'wsergoRestUrl' => $wsergo_rest_base,
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'tiles'     => $tiles,
			'pluginUrl' => WSC_URL,
			'colors'    => WSC_Categories::colors(),
			'cats'      => WSC_Categories::all(),
			'canScan'   => current_user_can( 'manage_options' ),
			// MapLibre fill-color для wsc-landuse-fill (match по kind).
			'landuseFillColorExpr' => class_exists( 'WSC_Landcover' ) ? WSC_Landcover::map_style_fill_match() : null,
			// Списки kind для тогглов «жилые зоны» / «зелёные зоны» на тулбаре карты.
			'landuseGreenKinds'    => class_exists( 'WSC_Landcover' ) ? WSC_Landcover::green_zone_kinds() : [],
			'landuseResidentialKinds' => [ 'residential' ],
		] );
	}
}
