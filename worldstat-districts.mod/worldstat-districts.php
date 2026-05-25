<?php
/**
 * Plugin Name:       WorldStat — Districts Extension MOD to worldstat-ergonomics
 * Plugin URI:        https://worldstatistics.dev/extensions/districts
 * Description:       Анализ районов города: качество воздуха, комфортность, безопасность.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Requires Plugins:  world-statistics-platform
 * Author:            World Statistics Team
 * License:           GPL v2 or later
 * Text Domain:       worldstat-districts
 *
 * @package WorldStatDistricts
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WSDISTRICTS_VERSION', '1.0.0' );
define( 'WSDISTRICTS_FILE',    __FILE__ );
define( 'WSDISTRICTS_DIR',     plugin_dir_path( __FILE__ ) );
define( 'WSDISTRICTS_URL',     plugin_dir_url( __FILE__ ) );

/* ── Check that the platform is active ─────────────────── */
if ( ! class_exists( 'WorldStat_Core' ) ) {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>WorldStat Districts</strong> requires <strong>World Statistics Platform</strong>.</p></div>';
    } );
    return;
}

/* ── Include files ─────────────────────────────────────── */
require_once WSDISTRICTS_DIR . 'includes/class-districts-cpt.php';
require_once WSDISTRICTS_DIR . 'includes/class-districts-importer.php';
require_once WSDISTRICTS_DIR . 'includes/class-districts-data.php';
require_once WSDISTRICTS_DIR . 'includes/class-districts-renderer.php';
require_once WSDISTRICTS_DIR . 'includes/class-districts-admin.php';
require_once WSDISTRICTS_DIR . 'includes/class-air-quality-importer.php';
require_once WSDISTRICTS_DIR . 'includes/class-db-tables.php';
require_once WSDISTRICTS_DIR . 'includes/class-districts-regression.php';

require_once WSDISTRICTS_DIR . 'includes/class-districts-ml-clustering.php';
// Create database tables on activation
register_activation_hook( __FILE__, function() {
    WSDistricts_DB_Tables::create_tables();
});

/* ── Register extension on platform init ──────────────── */
add_action( 'worldstat_init', function () {

    // Register the extension
    WorldStat_Extensions::register( [
        'id'                => 'districts',
        'name'              => 'Districts & Urban Analysis',
        'version'           => WSDISTRICTS_VERSION,
        'author'            => 'World Statistics Team',
        'description'       => 'Анализ районов города: качество воздуха, комфортность, безопасность.',
        'icon'              => 'dashicons-networking',
        'requires_platform' => '1.0.0',
    ] );

    // Data providers
    WorldStat_Extensions::add_data_provider( 'districts', [
        'metrics' => [
            'districts_total' => [
                'label'       => 'Всего районов',
                'type'        => 'integer',
                'unit'        => '',
                'description' => 'Общее количество районов в базе',
                'callback'    => [ 'WSDistricts_Data', 'get_total_districts' ],
            ],
        ],
    ] );

} );

/* ── Init CPT and admin ───────────────────────────────── */
new WSDistricts_CPT();

if ( is_admin() ) {
    new WSDistricts_Admin();
}

/* ── Template loader for single district pages ────────── */
add_filter( 'worldstat_single_template', function ( string $template, string $post_type ): string {
    if ( $post_type === WSDistricts_CPT::SLUG ) {
        $path = WSDISTRICTS_DIR . 'templates/single-wsp_district.php';
        if ( file_exists( $path ) ) return $path;
    }
    return $template;
}, 50, 2 );

/* ── Register wsp_district as platform page type ──────── */
add_filter( 'worldstat_extension_post_types', function ( array $types ): array {
    $types[] = WSDistricts_CPT::SLUG;
    return $types;
} );

/* ── Handle district creation from POST request ──────── */
add_action( 'init', function() {
    if ( isset( $_POST['create_ny_districts'] ) && isset( $_POST['create_ny_nonce'] ) ) {
        if ( wp_verify_nonce( $_POST['create_ny_nonce'], 'create_ny_districts_action' ) ) {
            $city_id = isset( $_POST['city_id'] ) ? intval( $_POST['city_id'] ) : 0;
            if ( $city_id ) {
                WSDistricts_Renderer::create_ny_districts( $city_id );
                wp_redirect( remove_query_arg( 'create_ny_districts', wp_get_referer() ) );
                exit;
            }
        }
    }
});

add_action('init', function() {
    if (!wp_next_scheduled('wsdistricts_create_ml_nonce')) {
        wp_schedule_event(time(), 'daily', 'wsdistricts_create_ml_nonce');
    }
});

add_action('wsdistricts_create_ml_nonce', function() {
    wp_create_nonce('wsdistricts_ml');
});