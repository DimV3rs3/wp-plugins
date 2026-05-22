<?php
/**
 * Zones Custom Post Type
 *
 * @package WorldStatZone
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSZ_CPT {
    const SLUG = 'wsz_zone';

    public function __construct() {
        add_action( 'init', [ $this, 'register' ] );
    }

    public function register(): void {
        $labels = [
            'name'               => 'Зоны',
            'singular_name'      => 'Зона',
            'menu_name'          => 'Зоны',
            'add_new'            => 'Добавить зону',
            'add_new_item'       => 'Добавить новую зону',
            'edit_item'          => 'Редактировать зону',
            'new_item'           => 'Новая зона',
            'view_item'          => 'Просмотр зоны',
            'search_items'       => 'Поиск зон',
            'not_found'          => 'Зоны не найдены',
            'not_found_in_trash' => 'В корзине зон нет',
            'all_items'          => 'Все зоны',
        ];

        register_post_type( self::SLUG, [
            'labels'       => $labels,
            'public'       => true,
            'show_ui'      => true,
            'show_in_menu' => true,
            'show_in_admin_bar' => true,
            'show_in_nav_menus' => true,
            'menu_icon'    => 'dashicons-admin-home',
            'menu_position' => 25,
            'supports'     => [ 'title', 'editor', 'thumbnail', 'excerpt' ],
            'has_archive'  => true,
            'rewrite'      => [ 'slug' => 'zone' ],
            'capability_type' => 'post',
            'map_meta_cap'    => true,
            'publicly_queryable' => true,
        ] );
    }
    
    /**
     * Get single product data by ID
     */
    public static function get_product_data( int $product_id ): array {
        $post = get_post( $product_id );
        if ( ! $post || $post->post_type !== self::SLUG ) {
            return [];
        }
        
        $meta = get_post_meta( $product_id );
        
        $get_meta = function( $key, $default = '' ) use ( $meta ) {
            return isset( $meta[ $key ][0] ) ? maybe_unserialize( $meta[ $key ][0] ) : $default;
        };
        
        // Получаем объекты из JSON
        $objects_json = $get_meta( 'wsz_objects_json', '[]' );
        $objects = json_decode( $objects_json, true );
        if ( ! is_array( $objects ) ) {
            $objects = [];
        }
        
        return [
            'id' => $product_id,
            'name' => $post->post_title,
            'description' => $post->post_content,
            'excerpt' => $post->post_excerpt,
            'permalink' => get_permalink( $product_id ),
            'thumbnail' => get_the_post_thumbnail_url( $product_id, 'large' ),
            'objects' => $objects,
            'objects_json' => $objects_json,
            'furniture_type' => $get_meta( 'wsz_furniture_type', '' ),
            'furniture_style' => $get_meta( 'wsz_furniture_style', '' ),
            'furniture_material' => $get_meta( 'wsz_furniture_material', '' ),
            'room_type' => $get_meta( 'wsz_zone_type', '' ),
            'category' => $get_meta( 'wsz_category', 'дом' ),
            'ergonomics' => (float) $get_meta( 'wsz_ergonomics', 0 ),
            'lighting' => (float) $get_meta( 'wsz_lighting', 0 ),
            'safety' => (float) $get_meta( 'wsz_safety', 0 ),
            'comfort' => (float) $get_meta( 'wsz_comfort', 0 ),
            'functionality' => (float) $get_meta( 'wsz_functionality', 0 ),
            'controllability' => (float) $get_meta( 'wsz_controllability', 0 ),
            'habitability' => (float) $get_meta( 'wsz_habitability', 0 ),
            'masterability' => (float) $get_meta( 'wsz_masterability', 0 ),
            'area' => (float) $get_meta( 'wsz_area', 0 ),
            'temp' => (float) $get_meta( 'wsz_temp', 0 ),
            'humidity' => (float) $get_meta( 'wsz_humidity', 0 ),
            'noise_level' => (float) $get_meta( 'wsz_noise_level', 0 ),
            'co2' => (float) $get_meta( 'wsz_co2', 0 ),
            'city_name' => $get_meta( 'wsz_city_name', '' ),
            'country_name' => $get_meta( 'wsz_country_name', '' ),
            'country_iso2' => $get_meta( 'wsz_country_iso2', '' ),
            'zone_type' => $get_meta( 'wsz_zone_type', '' ),
            // Дополнительные метрики
            'object_count' => (int) $get_meta( 'wsz_object_count', 0 ),
            'avg_object_height' => (float) $get_meta( 'wsz_avg_object_height', 0 ),
            'avg_object_width' => (float) $get_meta( 'wsz_avg_object_width', 0 ),
            'avg_object_depth' => (float) $get_meta( 'wsz_avg_object_depth', 0 ),
            'ergonomic_objects' => (int) $get_meta( 'wsz_ergonomic_objects', 0 ),
            'adjustable_objects' => (int) $get_meta( 'wsz_adjustable_objects', 0 ),
            'temp_normalized' => (float) $get_meta( 'wsz_temp_normalized', 0 ),
            'humidity_normalized' => (float) $get_meta( 'wsz_humidity_normalized', 0 ),
            'co2_normalized' => (float) $get_meta( 'wsz_co2_normalized', 0 ),
            'noise_normalized' => (float) $get_meta( 'wsz_noise_normalized', 0 ),
        ];
    }
    
    /**
     * Get products by country ISO2
     */
    public static function get_products_by_country( string $iso2 ): array {
        global $wpdb;
        
        $posts = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID 
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'wsz_country_iso2'
             WHERE p.post_type = %s AND p.post_status = 'publish' AND pm.meta_value = %s
             ORDER BY p.post_title ASC",
            self::SLUG,
            strtoupper( $iso2 )
        ) );
        
        $products = [];
        foreach ( $posts as $post ) {
            $products[] = self::get_product_data( $post->ID );
        }
        
        return $products;
    }
    
    /**
     * Get all zones
     */
    public static function get_all_zones(): array {
        global $wpdb;
        
        $posts = $wpdb->get_results( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} 
             WHERE post_type = %s AND post_status = 'publish'
             ORDER BY ID DESC",
            self::SLUG
        ) );
        
        $zones = [];
        foreach ( $posts as $post ) {
            $zones[] = self::get_product_data( $post->ID );
        }
        
        return $zones;
    }
}