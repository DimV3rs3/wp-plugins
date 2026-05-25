<?php
/**
 * Settings: глобальные опции плагина (буферы, высоты, источник тайлов, язык, ключи).
 *
 * @package WorldStatCourtyard
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSC_Settings {

	const OPT_BUFFER     = 'wsc_buffer_by_category';
	const OPT_HEIGHT     = 'wsc_height_by_category';
	const OPT_TILES      = 'wsc_tiles_source';
	const OPT_LANGUAGE   = 'wsc_language';
	const OPT_OSMIUM     = 'wsc_osmium_path';
	const OPT_OGR2OGR    = 'wsc_ogr2ogr_path';
	const OPT_OVERPASS   = 'wsc_overpass_endpoint';

	const DEFAULT_BUFFER = [
		'residential'   => 35,
		'commercial'    => 25,
		'education'     => 50,
		'healthcare'    => 30,
		'sport_leisure' => 20,
		'office'        => 15,
		'industrial'    => 50,
		'other'         => 25,
	];

	const DEFAULT_HEIGHT = [
		'residential'   => 9,
		'commercial'    => 5,
		'education'     => 8,
		'healthcare'    => 8,
		'sport_leisure' => 6,
		'office'        => 12,
		'industrial'    => 8,
		'other'         => 6,
	];

	private static ?array $buffer_cfg_cache = null;

	public static function get_buffer_for_category( string $cat ): float {
		// Static cache: get_option декодируется один раз за запрос.
		// Критично для recompute_buffers — функция дёргается N × раз в цикле.
		if ( self::$buffer_cfg_cache === null ) {
			self::$buffer_cfg_cache = (array) get_option( self::OPT_BUFFER, self::DEFAULT_BUFFER );
		}
		$cat = $cat ?: 'other';
		$v   = isset( self::$buffer_cfg_cache[ $cat ] )
			? (float) self::$buffer_cfg_cache[ $cat ]
			: (float) ( self::DEFAULT_BUFFER[ $cat ] ?? 35 );
		return max( 1.0, min( 500.0, $v ) );
	}

	public static function get_height_for_category( string $cat ): float {
		$cfg = (array) get_option( self::OPT_HEIGHT, self::DEFAULT_HEIGHT );
		$cat = $cat ?: 'other';
		return (float) ( $cfg[ $cat ] ?? self::DEFAULT_HEIGHT[ $cat ] ?? 6 );
	}

	public static function get_buffers_map(): array {
		return wp_parse_args( (array) get_option( self::OPT_BUFFER, [] ), self::DEFAULT_BUFFER );
	}

	public static function get_heights_map(): array {
		return wp_parse_args( (array) get_option( self::OPT_HEIGHT, [] ), self::DEFAULT_HEIGHT );
	}

	public static function set_buffers_map( array $map ): void {
		$out = [];
		foreach ( self::DEFAULT_BUFFER as $k => $def ) {
			$out[ $k ] = isset( $map[ $k ] ) ? max( 1.0, min( 500.0, (float) $map[ $k ] ) ) : $def;
		}
		update_option( self::OPT_BUFFER, $out );
	}

	public static function set_heights_map( array $map ): void {
		$out = [];
		foreach ( self::DEFAULT_HEIGHT as $k => $def ) {
			$out[ $k ] = isset( $map[ $k ] ) ? max( 1.0, min( 200.0, (float) $map[ $k ] ) ) : $def;
		}
		update_option( self::OPT_HEIGHT, $out );
	}

	public static function get_tiles_source(): array {
		$def = [ 'basemap' => 'openfreemap', 'mbtiles_file' => '', 'api_key' => '', 'style' => 'positron' ];
		$cfg = wp_parse_args( (array) get_option( self::OPT_TILES, [] ), $def );
		// Liberty показывает цветные landuse-зоны (фиолетовые/розовые) поверх категорийных
		// зданий — это визуальный шум для нашего use case. Прозрачно мигрируем на positron.
		if ( ( $cfg['style'] ?? '' ) === 'liberty' ) {
			$cfg['style'] = 'positron';
		}
		return $cfg;
	}

	public static function get_language(): string {
		return (string) get_option( self::OPT_LANGUAGE, 'ru' );
	}

	public static function get_osmium_path(): string {
		$opt = trim( (string) get_option( self::OPT_OSMIUM, '' ) );
		return $opt !== '' ? $opt : 'osmium';
	}

	public static function get_ogr2ogr_path(): string {
		$opt = trim( (string) get_option( self::OPT_OGR2OGR, '' ) );
		return $opt !== '' ? $opt : 'ogr2ogr';
	}

	public static function get_overpass_endpoint(): string {
		$opt = trim( (string) get_option( self::OPT_OVERPASS, '' ) );
		return $opt !== '' ? $opt : 'https://overpass-api.de/api/interpreter';
	}

	public static function uploads_dir(): array {
		$up   = wp_upload_dir();
		$base = trailingslashit( $up['basedir'] ) . 'wsc';
		$url  = trailingslashit( $up['baseurl'] ) . 'wsc';
		if ( ! file_exists( $base ) ) {
			wp_mkdir_p( $base );
			wp_mkdir_p( $base . '/tiles' );
			wp_mkdir_p( $base . '/cache/mvt' );
			wp_mkdir_p( $base . '/pbf' );
		}
		return [ 'basedir' => $base, 'baseurl' => $url ];
	}
}
