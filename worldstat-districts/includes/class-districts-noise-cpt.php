<?php
/**
 * Custom Post Type for Noise Records (Шумовые записи)
 *
 * @package WorldStatDistricts
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSDistricts_Noise_CPT {
    
    const SLUG = 'wsp_noise_record';
    
    public function __construct() {
        add_action( 'init', [ $this, 'register_post_type' ] );
        add_filter( 'manage_' . self::SLUG . '_posts_columns', [ $this, 'add_columns' ] );
        add_action( 'manage_' . self::SLUG . '_posts_custom_column', [ $this, 'render_columns' ], 10, 2 );
    }
    
    public function register_post_type(): void {
        $labels = [
            'name'               => 'Шумовые записи',
            'singular_name'      => 'Шумовая запись',
            'menu_name'          => 'Шумовые записи',
            'name_admin_bar'     => 'Шумовая запись',
            'add_new'            => 'Добавить новую',
            'add_new_item'       => 'Добавить новую шумовую запись',
            'edit_item'          => 'Редактировать шумовую запись',
            'new_item'           => 'Новая шумовая запись',
            'view_item'          => 'Просмотр шумовой записи',
            'search_items'       => 'Поиск шумовых записей',
            'not_found'          => 'Шумовых записей не найдено',
            'not_found_in_trash' => 'Шумовых записей в корзине не найдено',
            'all_items'          => 'Все шумовые записи',
            'archives'           => 'Архивы шумовых записей',
            'attributes'         => 'Атрибуты шумовой записи',
            'insert_into_item'   => 'Вставить в шумовую запись',
            'uploaded_to_this_item' => 'Загружено для этой записи',
            'filter_items_list'  => 'Фильтровать список записей',
            'items_list_navigation' => 'Навигация по списку записей',
            'items_list'         => 'Список шумовых записей',
        ];
        
        register_post_type( self::SLUG, [
            'labels'              => $labels,
            'public'              => true,
            'has_archive'         => true,
            'supports'            => [ 'title', 'editor', 'custom-fields' ],
            'menu_icon'           => 'dashicons-format-audio',
            'show_in_rest'        => true,
            'rewrite'             => [ 'slug' => 'noise-record' ],
            'hierarchical'        => false,
            'description'         => 'Записи о шумовых ограничениях (E-Designations)',
        ] );
    }
    
    /**
     * Add custom columns to admin list
     */
    public function add_columns( $columns ): array {
        $new_columns = [];
        foreach ( $columns as $key => $value ) {
            $new_columns[ $key ] = $value;
            if ( $key === 'title' ) {
                $new_columns['enumber'] = 'E-Number';
                $new_columns['district'] = 'Район';
                $new_columns['restrictions'] = 'Ограничения';
                $new_columns['effective_date'] = 'Дата вступления';
            }
        }
        return $new_columns;
    }
    
    /**
     * Render custom column content
     */
    public function render_columns( $column, $post_id ): void {
        switch ( $column ) {
            case 'enumber':
                echo esc_html( get_post_meta( $post_id, 'wsnoise_enumber', true ) );
                break;
            case 'district':
                $district_id = wp_get_post_parent_id( $post_id );
                if ( $district_id ) {
                    echo '<a href="' . get_edit_post_link( $district_id ) . '">' . esc_html( get_the_title( $district_id ) ) . '</a>';
                } else {
                    echo '—';
                }
                break;
            case 'restrictions':
                $restrictions = [];
                if ( get_post_meta( $post_id, 'wsnoise_has_noise_restriction', true ) ) {
                    $restrictions[] = '🔊 Шум';
                }
                if ( get_post_meta( $post_id, 'wsnoise_has_air_restriction', true ) ) {
                    $restrictions[] = '💨 Воздух';
                }
                if ( get_post_meta( $post_id, 'wsnoise_has_hazmat_restriction', true ) ) {
                    $restrictions[] = '⚠️ Опасные материалы';
                }
                echo implode( ', ', $restrictions ) ?: '—';
                break;
            case 'effective_date':
                echo esc_html( get_post_meta( $post_id, 'wsnoise_effective_date', true ) );
                break;
        }
    }
}