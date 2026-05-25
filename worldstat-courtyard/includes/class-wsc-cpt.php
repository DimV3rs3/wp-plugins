<?php
/**
 * CPT для buildings/yards/pois (геометрия в кастомных таблицах, CPT — для permalinks/админки).
 *
 * @package WorldStatCourtyard
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSC_CPT {

	const SLUG_BUILDING = 'wsc_building';
	const SLUG_YARD     = 'wsc_yard';
	const SLUG_POI      = 'wsc_poi';

	const META_ROW_ID  = '_wsc_row_id';
	const META_CITY_ID = '_wsc_city_id';
	const META_CATEGORY= '_wsc_category';

	public static function register(): void {
		$cap = [ 'capability_type' => 'post', 'show_in_menu' => false, 'public' => true, 'show_ui' => true, 'show_in_rest' => true ];

		register_post_type( self::SLUG_BUILDING, $cap + [
			'labels' => [
				'name' => 'Здания (OSM)', 'singular_name' => 'Здание',
				'all_items' => 'Все здания', 'edit_item' => 'Редактировать здание',
			],
			'rest_base' => 'wsc-buildings',
			'rewrite'   => [ 'slug' => 'wsc-building', 'with_front' => false ],
			'has_archive' => false,
			'supports' => [ 'title', 'editor' ],
			'menu_icon' => 'dashicons-building',
		] );

		register_post_type( self::SLUG_YARD, $cap + [
			'labels' => [
				'name' => 'Придомовые (Courtyard)', 'singular_name' => 'Придомовая',
				'all_items' => 'Все участки', 'edit_item' => 'Редактировать участок',
			],
			'rest_base' => 'wsc-yards',
			'rewrite'   => [ 'slug' => 'wsc-yard', 'with_front' => false ],
			'has_archive' => false,
			'supports' => [ 'title', 'editor' ],
			'menu_icon' => 'dashicons-admin-home',
		] );

		register_post_type( self::SLUG_POI, $cap + [
			'labels' => [
				'name' => 'POI (OSM)', 'singular_name' => 'POI',
				'all_items' => 'Все POI', 'edit_item' => 'Редактировать POI',
			],
			'rest_base' => 'wsc-pois',
			'rewrite'   => [ 'slug' => 'wsc-poi', 'with_front' => false ],
			'has_archive' => false,
			'supports' => [ 'title', 'editor' ],
			'menu_icon' => 'dashicons-location',
		] );

		foreach ( [ self::SLUG_BUILDING, self::SLUG_YARD, self::SLUG_POI ] as $pt ) {
			foreach ( [ self::META_ROW_ID, self::META_CITY_ID, self::META_CATEGORY ] as $key ) {
				register_post_meta( $pt, $key, [
					'type'         => 'string',
					'single'       => true,
					'show_in_rest' => false,
					'auth_callback'=> function () { return current_user_can( 'manage_options' ); },
				] );
			}
		}
	}
}
