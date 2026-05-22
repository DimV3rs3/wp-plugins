<?php
/**
 * Plugin Name:       WorldStat — Cities Extension
 * Plugin URI:        https://worldstatistics.dev/extensions/cities
 * Description:       Данные о городах мира: население, площадь, плотность, фрагментация, компактность, дороги, кварталы, планировка. Импорт из CSV (Areas & Densities, Blocks & Roads).
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Requires Plugins:  world-statistics-platform
 * Author:            World Statistics Team
 * License:           GPL v2 or later
 * Text Domain:       worldstat-cities
 *
 * @package WorldStatCities
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WSCITIES_VERSION', '1.1.0' );
define( 'WSCITIES_FILE',    __FILE__ );
define( 'WSCITIES_DIR',     plugin_dir_path( __FILE__ ) );
define( 'WSCITIES_URL',     plugin_dir_url( __FILE__ ) );

/* ── Check that the platform is active ─────────────────── */
if ( ! class_exists( 'WorldStat_Core' ) ) {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>WorldStat Cities</strong> requires <strong>World Statistics Platform</strong>.</p></div>';
    } );
    return;
}

/* ── Include files ─────────────────────────────────────── */
require_once WSCITIES_DIR . 'includes/class-cities-cpt.php';
require_once WSCITIES_DIR . 'includes/class-cities-importer.php';
require_once WSCITIES_DIR . 'includes/class-cities-data.php';
require_once WSCITIES_DIR . 'includes/class-cities-renderer.php';
require_once WSCITIES_DIR . 'includes/class-cities-admin.php';

/* ── Register extension on platform init ──────────────── */
add_action( 'worldstat_init', function () {

    // 1. Register the extension
    WorldStat_Extensions::register( [
        'id'                => 'cities',
        'name'              => 'Cities & Urban Areas',
        'version'           => WSCITIES_VERSION,
        'author'            => 'World Statistics Team',
        'description'       => 'Данные о городах: население, площадь, плотность, урбанизация, дороги, кварталы, планировка территорий.',
        'icon'              => 'dashicons-building',
        'requires_platform' => '1.0.0',
    ] );

    // 2. Data providers
    WorldStat_Extensions::add_data_provider( 'cities', [
        'metrics' => [
            'cities_count' => [
                'label'       => 'Количество городов',
                'type'        => 'integer',
                'unit'        => '',
                'description' => 'Число городов в базе для данной страны',
                'callback'    => [ 'WSCities_Data', 'get_cities_count' ],
            ],
            'largest_city_pop' => [
                'label'       => 'Крупнейший город (нас.)',
                'type'        => 'integer',
                'unit'        => 'чел.',
                'description' => 'Население крупнейшего города',
                'callback'    => [ 'WSCities_Data', 'get_largest_city_population' ],
            ],
            'total_urban_pop' => [
                'label'       => 'Городское население',
                'type'        => 'integer',
                'unit'        => 'чел.',
                'description' => 'Суммарное население всех городов',
                'callback'    => [ 'WSCities_Data', 'get_total_urban_population' ],
            ],
            'avg_arterial_density' => [
                'label'       => 'Ср. плотность арт. дорог',
                'type'        => 'number',
                'unit'        => 'км/км²',
                'description' => 'Средняя плотность артериальных дорог по городам страны (1990–2015)',
                'callback'    => [ 'WSCities_Data', 'get_avg_arterial_density' ],
            ],
            'avg_block_size' => [
                'label'       => 'Ср. размер квартала',
                'type'        => 'number',
                'unit'        => 'га',
                'description' => 'Средний размер квартала по городам страны (1990–2015)',
                'callback'    => [ 'WSCities_Data', 'get_avg_block_size' ],
            ],
            'avg_walkability' => [
                'label'       => 'Ср. коэффициент пешеходности',
                'type'        => 'number',
                'unit'        => '',
                'description' => 'Средний коэффициент пешеходности по городам страны (1990–2015)',
                'callback'    => [ 'WSCities_Data', 'get_avg_walkability' ],
            ],
        ],
    ] );

    // 3. Country page tab
    WorldStat_Extensions::add_country_tab( 'cities', [
        'title'    => 'Города',
        'icon'     => 'dashicons-building',
        'callback' => [ 'WSCities_Renderer', 'render_country_tab' ],
        'priority' => 20,
    ] );

    // 4. Map layer — urban population
    WorldStat_Extensions::add_map_layer( 'cities', [
        'label'         => 'Городское население',
        'type'          => 'choropleth',
        'color_scale'   => [ '#e0f2fe', '#0c4a6e' ],
        'data_callback' => [ 'WSCities_Data', 'get_map_data' ],
    ] );

    // 5. Map markers — city coordinates on the map
    WorldStat_Extensions::add_map_markers( 'cities', [
        'label'            => 'Города мира',
        'icon'             => 'circle',
        'color'            => '#ef4444',
        'radius'           => 5,
        'data_callback'    => [ 'WSCities_Data', 'get_all_city_markers' ],
        'country_callback' => [ 'WSCities_Data', 'get_country_city_markers' ],
    ] );

} );

/* ── Init CPT and admin ───────────────────────────────── */
new WSCities_CPT();
if ( is_admin() ) {
    new WSCities_Admin();
}

/* ── Template loader for single city pages ────────────── */
add_filter( 'worldstat_single_template', function ( string $template, string $post_type ): string {
    if ( $post_type === WSCities_CPT::SLUG ) {
        $path = WSCITIES_DIR . 'templates/single-wsp_city.php';
        if ( file_exists( $path ) ) return $path;
    }
    return $template;
}, 10, 2 );

/* ── Register wsp_city as platform page type (for CSS/JS enqueue) ── */
add_filter( 'worldstat_extension_post_types', function ( array $types ): array {
    $types[] = WSCities_CPT::SLUG;
    return $types;
} );

/* ── Shared assets for city template + country tab layout ── */
add_action( 'wp_enqueue_scripts', function () {
    $is_city    = is_singular( WSCities_CPT::SLUG );
    $is_country = is_singular( 'wsp_country' );

    if ( ! $is_city && ! $is_country ) {
        return;
    }

    wp_enqueue_style(
        'wscities-city-inter',
        'https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap',
        [],
        null
    );
    wp_enqueue_style(
        'wscities-city-fontawesome',
        'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css',
        [],
        null
    );
    wp_enqueue_style(
        'wscities-city-css',
        WSCITIES_URL . 'assets/css/city-page.css',
        [],
        WSCITIES_VERSION
    );

    if ( $is_country ) {
        wp_enqueue_script(
            'wscities-country-tab-nav',
            WSCITIES_URL . 'assets/js/country-tab-nav.js',
            [ 'jquery' ],
            WSCITIES_VERSION,
            true
        );
    }
}, 20 );
