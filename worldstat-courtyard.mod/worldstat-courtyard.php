<?php
/**
 * Plugin Name:       WorldStat — Courtyard (Придомовая территория) MOD to worldstat-ergonomics
 * Plugin URI:        https://worldstatistics.dev/extensions/courtyard
 * Description:       Расширение World Statistics Platform: сканирование зданий и POI из OSM (Overpass/PBF), классификация по 8 категориям, буфер придомовой территории (по умолчанию 35 м), MapLibre 2D/3D, MVT-тайлы, авто-интеграция с эргономикой.
 * Version:           1.0.3
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Requires Plugins:  world-statistics-platform, worldstat-cities
 * Author:            World Statistics Team
 * License:           GPL v2 or later
 * Text Domain:       worldstat-courtyard
 *
 * @package WorldStatCourtyard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WSC_VERSION',     '1.0.3' );
define( 'WSC_FILE',        __FILE__ );
define( 'WSC_DIR',         plugin_dir_path( __FILE__ ) );
define( 'WSC_URL',         plugin_dir_url( __FILE__ ) );
define( 'WSC_BASENAME',    plugin_basename( __FILE__ ) );
define( 'WSC_REST_NS',     'wsc/v1' );
define( 'WSC_DB_VERSION',  '1.2.0' );

/* ─── Dependency checks ─────────────────────────────────────── */
if ( ! class_exists( 'WorldStat_Core' ) ) {
	add_action( 'admin_notices', function () {
		if ( ! current_user_can( 'activate_plugins' ) ) return;
		echo '<div class="notice notice-error"><p><strong>WorldStat Courtyard</strong> требует <strong>World Statistics Platform</strong>.</p></div>';
	} );
	return;
}
if ( ! class_exists( 'WSCities_CPT' ) ) {
	add_action( 'admin_notices', function () {
		if ( ! current_user_can( 'activate_plugins' ) ) return;
		echo '<div class="notice notice-error"><p><strong>WorldStat Courtyard</strong> требует <strong>WorldStat Cities</strong>.</p></div>';
	} );
	return;
}

/* ─── Includes ──────────────────────────────────────────────── */
$wsc_files = [
	'includes/class-wsc-settings.php',
	'includes/class-wsc-categories.php',
	'includes/class-wsc-landcover.php',
	'includes/class-wsc-installer.php',
	'includes/class-wsc-cpt.php',
	'includes/class-wsc-parser.php',
	'includes/class-wsc-geom.php',
	'includes/class-wsc-buffer.php',
	'includes/class-wsc-writer.php',
	'includes/class-wsc-overpass.php',
	'includes/class-wsc-nominatim.php',
	'includes/class-wsc-pbf.php',
	'includes/class-wsc-jobs-import.php',
	'includes/class-wsc-mvt.php',
	'includes/class-wsc-mbtiles.php',
	'includes/class-wsc-rest.php',
	'includes/class-wsc-ergo-bridge.php',
	'includes/class-wsc-renderer.php',
	'includes/class-wsc-compat.php',
	'includes/class-wsc-admin.php',
];
foreach ( $wsc_files as $f ) {
	$p = WSC_DIR . $f;
	if ( file_exists( $p ) ) require_once $p;
}

/* ─── Activation / Deactivation ─────────────────────────────── */
register_activation_hook( __FILE__, [ 'WSC_Installer', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'WSC_Installer', 'deactivate' ] );

/* ─── Init ──────────────────────────────────────────────────── */
add_action( 'init', static function () {
	load_plugin_textdomain( 'worldstat-courtyard', false, dirname( WSC_BASENAME ) . '/languages' );
}, 0 );

add_action( 'init', [ 'WSC_Installer', 'maybe_upgrade' ], 0 );
add_action( 'init', [ 'WSC_CPT', 'register' ] );
add_action( 'admin_init', [ 'WSC_Installer', 'maybe_upgrade' ] );

if ( is_admin() ) {
	add_action( 'plugins_loaded', [ 'WSC_Admin', 'init' ], 20 );
}

add_action( 'rest_api_init', [ 'WSC_REST', 'register' ] );

/* ─── Register extension on the platform ────────────────────── */
add_action( 'worldstat_init', function () {
	WorldStat_Extensions::register( [
		'id'                => 'courtyard',
		'name'              => 'Придомовая территория',
		'version'           => WSC_VERSION,
		'author'            => 'World Statistics Team',
		'description'       => 'Сканирование OSM, буфер придомовых территорий, MapLibre 2D/3D, интеграция с эргономикой.',
		'icon'              => 'dashicons-admin-home',
		'requires_platform' => '1.0.0',
		'depends'           => [ 'cities' ],
	] );

	WorldStat_Extensions::add_country_tab( 'courtyard', [
		'title'    => 'Придомовая территория',
		'icon'     => 'dashicons-admin-home',
		'callback' => [ 'WSC_Renderer', 'render_country_tab' ],
		'priority' => 30,
	] );

	WorldStat_Extensions::add_data_provider( 'courtyard', [
		'metrics' => [
			'wsc_buildings_count' => [
				'label'       => 'Отсканировано зданий',
				'type'        => 'integer',
				'unit'        => '',
				'description' => 'Число зданий в базе courtyard для страны',
				'callback'    => [ 'WSC_Renderer', 'metric_buildings_count' ],
			],
			'wsc_yards_count' => [
				'label'       => 'Придомовых территорий',
				'type'        => 'integer',
				'unit'        => '',
				'description' => 'Число рассчитанных буферов',
				'callback'    => [ 'WSC_Renderer', 'metric_yards_count' ],
			],
		],
	] );
}, 20 );

add_action( 'wp_enqueue_scripts', [ 'WSC_Renderer', 'enqueue_public_assets' ], 20 );

add_filter( 'worldstat_extension_post_types', function ( array $types ): array {
	$types[] = WSC_CPT::SLUG_BUILDING;
	$types[] = WSC_CPT::SLUG_YARD;
	$types[] = WSC_CPT::SLUG_POI;
	return $types;
} );

/* ─── Cleanup: при удалении wsp_building поста обнуляем wsc_buildings.ergo_post_id ─── */
add_action( 'before_delete_post', function ( int $post_id ): void {
	$post = get_post( $post_id );
	if ( ! $post || $post->post_type !== 'wsp_building' ) return;
	global $wpdb;
	$tb = WSC_Installer::table_buildings();
	$wpdb->update( $tb, [ 'ergo_post_id' => 0 ], [ 'ergo_post_id' => $post_id ] );
} );
