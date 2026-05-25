<?php
/**
 * Districts Custom Post Type
 *
 * @package WorldStatDistricts
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSDistricts_CPT {
    const SLUG = 'wsp_district';

    public function __construct() {
        add_action( 'init', [ $this, 'register' ] );
        add_filter( 'manage_' . self::SLUG . '_posts_columns', [ $this, 'add_ergo_columns' ] );
        add_action( 'manage_' . self::SLUG . '_posts_custom_column', [ $this, 'render_ergo_columns' ], 10, 2 );
    }

    public function register(): void {
        $labels = [
            'name'               => 'Районы',
            'singular_name'      => 'Район',
            'menu_name'          => 'Районы',
            'add_new'            => 'Добавить район',
            'add_new_item'       => 'Добавить новый район',
            'edit_item'          => 'Редактировать район',
            'new_item'           => 'Новый район',
            'view_item'          => 'Просмотр района',
            'search_items'       => 'Поиск районов',
            'not_found'          => 'Районы не найдены',
            'not_found_in_trash' => 'В корзине районов нет',
            'all_items'          => 'Все районы',
        ];

        register_post_type( self::SLUG, [
            'labels'       => $labels,
            'public'       => true,
            'show_ui'      => true,
            'show_in_menu' => false,
            'supports'     => [ 'title', 'editor', 'thumbnail', 'custom-fields' ],
            'has_archive'  => true,
            'rewrite'      => [ 'slug' => 'district' ],
            'capability_type' => 'post',
            'map_meta_cap'    => true,
            'show_in_rest'    => false,
        ] );
    }

    /**
     * Добавляем колонки для эргономики в список районов
     */
    public function add_ergo_columns( $columns ): array {
        $new_columns = [];
        
        foreach ( $columns as $key => $value ) {
            $new_columns[ $key ] = $value;
            
            // Добавляем колонки после заголовка
            if ( $key === 'title' ) {
                $new_columns['wsdistrict_comfort'] = '🌿 Комфорт';
                $new_columns['wsdistrict_safety'] = '🛡️ Безопасность';
                $new_columns['wsdistrict_functionality'] = '⚙️ Функц.';
                $new_columns['wsdistrict_livability'] = '🏡 Обитаемость';
            }
        }
        
        return $new_columns;
    }

    /**
     * Рендерим содержимое колонок эргономики
     */
    public function render_ergo_columns( $column, $post_id ): void {
        switch ( $column ) {
            case 'wsdistrict_comfort':
                $comfort = (float) get_post_meta( $post_id, 'wsdistrict_comfort_score', true );
                $air_class = get_post_meta( $post_id, 'wsdistrict_air_quality_class', true );
                
                if ( $comfort > 0 ) {
                    $color = '#10b981';
                    if ( $comfort < 40 ) $color = '#ef4444';
                    elseif ( $comfort < 60 ) $color = '#f59e0b';
                    elseif ( $comfort < 75 ) $color = '#3b82f6';
                    
                    echo '<div style="display: flex; align-items: center; gap: 8px;">';
                    echo '<div style="flex: 1; height: 6px; background: #e5e7eb; border-radius: 3px; overflow: hidden;">';
                    echo '<div style="width: ' . $comfort . '%; height: 100%; background: ' . $color . '; border-radius: 3px;"></div>';
                    echo '</div>';
                    echo '<span style="font-weight: bold; color: ' . $color . ';">' . round( $comfort ) . '</span>';
                    echo '</div>';
                    
                    if ( $air_class ) {
                        $air_icon = $air_class == 'Good' ? '🟢' : ( $air_class == 'Moderate' ? '🟡' : '🔴' );
                        echo '<div style="font-size: 11px; color: #666; margin-top: 4px;">' . $air_icon . ' ' . esc_html( $air_class ) . '</div>';
                    }
                } else {
                    echo '<span style="color: #999;">—</span>';
                }
                break;
                
            case 'wsdistrict_safety':
                $safety = (float) get_post_meta( $post_id, 'wsdistrict_safety_score', true );
                $crime_level = get_post_meta( $post_id, 'wsdistrict_crime_level', true );
                
                if ( $safety > 0 ) {
                    $color = '#8b5cf6';
                    if ( $safety < 40 ) $color = '#ef4444';
                    elseif ( $safety < 60 ) $color = '#f59e0b';
                    elseif ( $safety < 75 ) $color = '#3b82f6';
                    
                    echo '<div style="display: flex; align-items: center; gap: 8px;">';
                    echo '<div style="flex: 1; height: 6px; background: #e5e7eb; border-radius: 3px; overflow: hidden;">';
                    echo '<div style="width: ' . $safety . '%; height: 100%; background: ' . $color . '; border-radius: 3px;"></div>';
                    echo '</div>';
                    echo '<span style="font-weight: bold; color: ' . $color . ';">' . round( $safety ) . '</span>';
                    echo '</div>';
                    
                    if ( $crime_level ) {
                        $crime_icon = $crime_level == 'Low' ? '🟢' : ( $crime_level == 'Medium' ? '🟡' : '🔴' );
                        echo '<div style="font-size: 11px; color: #666; margin-top: 4px;">' . $crime_icon . ' ' . esc_html( $crime_level ) . '</div>';
                    }
                } else {
                    echo '<span style="color: #999;">—</span>';
                }
                break;
                
            case 'wsdistrict_functionality':
                $functionality = (float) get_post_meta( $post_id, 'wsdistrict_functionality_score', true );
                $walkability = (float) get_post_meta( $post_id, 'wsdistrict_walkability_score', true );
                
                if ( $functionality > 0 ) {
                    $color = '#f59e0b';
                    if ( $functionality < 40 ) $color = '#ef4444';
                    elseif ( $functionality < 60 ) $color = '#f59e0b';
                    elseif ( $functionality < 75 ) $color = '#3b82f6';
                    else $color = '#10b981';
                    
                    echo '<div style="display: flex; align-items: center; gap: 8px;">';
                    echo '<div style="flex: 1; height: 6px; background: #e5e7eb; border-radius: 3px; overflow: hidden;">';
                    echo '<div style="width: ' . $functionality . '%; height: 100%; background: ' . $color . '; border-radius: 3px;"></div>';
                    echo '</div>';
                    echo '<span style="font-weight: bold; color: ' . $color . ';">' . round( $functionality ) . '</span>';
                    echo '</div>';
                    
                    if ( $walkability > 0 ) {
                        echo '<div style="font-size: 11px; color: #666; margin-top: 4px;">🚶 Walk: ' . round( $walkability ) . '</div>';
                    }
                } else {
                    echo '<span style="color: #999;">—</span>';
                }
                break;
                
            case 'wsdistrict_livability':
                $livability = (float) get_post_meta( $post_id, 'wsdistrict_livability_score', true );
                
                if ( $livability > 0 ) {
                    $color = '#10b981';
                    if ( $livability < 40 ) $color = '#ef4444';
                    elseif ( $livability < 60 ) $color = '#f59e0b';
                    elseif ( $livability < 75 ) $color = '#3b82f6';
                    
                    echo '<div style="display: flex; align-items: center; gap: 8px;">';
                    echo '<div style="flex: 1; height: 6px; background: #e5e7eb; border-radius: 3px; overflow: hidden;">';
                    echo '<div style="width: ' . $livability . '%; height: 100%; background: ' . $color . '; border-radius: 3px;"></div>';
                    echo '</div>';
                    echo '<span style="font-weight: bold; color: ' . $color . ';">' . round( $livability ) . '</span>';
                    echo '</div>';
                } else {
                    echo '<span style="color: #999;">—</span>';
                }
                break;
        }
    }

    /**
     * Get all districts for a city
     */
    public static function get_districts_for_city( int $city_id ): array {
        global $wpdb;
        
        $district_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT p.ID 
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID 
                AND pm.meta_key = 'wsdistrict_city_id' 
                AND pm.meta_value = %d
             WHERE p.post_type = %s 
               AND p.post_status = 'publish'
             ORDER BY p.post_title",
            $city_id,
            self::SLUG
        ) );
        
        if ( empty( $district_ids ) ) {
            return [];
        }
        
        $districts = [];
        foreach ( $district_ids as $district_id ) {
            $district = self::get_district( $district_id );
            if ( $district ) {
                $districts[] = $district;
            }
        }
        
        return $districts;
    }

    /**
     * Get district by ID with all info
     */
    public static function get_district( int $district_id ): ?array {
        $post = get_post( $district_id );
        if ( ! $post || $post->post_type !== self::SLUG ) {
            return null;
        }
        
        $meta = get_post_meta( $district_id );
        
        $get_meta = function( $key, $default = '' ) use ( $meta ) {
            return isset( $meta[ $key ][0] ) ? $meta[ $key ][0] : $default;
        };
        
        return [
            'id'            => $district_id,
            'name'          => $post->post_title,
            'description'   => $post->post_content,
            'country_iso2'  => $get_meta( 'wsdistrict_country_iso2', '' ),
            'country_name'  => $get_meta( 'wsdistrict_country_name', '' ),
            'city_id'       => (int) $get_meta( 'wsdistrict_city_id', 0 ),
            'city_name'     => $get_meta( 'wsdistrict_city_name', '' ),
            'lat'           => (float) $get_meta( 'wsdistrict_lat', 0 ),
            'lng'           => (float) $get_meta( 'wsdistrict_lng', 0 ),
            'population'    => (int) $get_meta( 'wsdistrict_population', 0 ),
            'area'          => (float) $get_meta( 'wsdistrict_area', 0 ),
            'density'       => (float) $get_meta( 'wsdistrict_density', 0 ),
            'established'   => $get_meta( 'wsdistrict_established', '' ),
            'postal_code'   => $get_meta( 'wsdistrict_postal_code', '' ),
            'website'       => $get_meta( 'wsdistrict_website', '' ),
            // Эргономические показатели
            'comfort_score' => (float) $get_meta( 'wsdistrict_comfort_score', 0 ),
            'safety_score'  => (float) $get_meta( 'wsdistrict_safety_score', 0 ),
            'functionality_score' => (float) $get_meta( 'wsdistrict_functionality_score', 0 ),
            'livability_score' => (float) $get_meta( 'wsdistrict_livability_score', 0 ),
            'walkability_score' => (float) $get_meta( 'wsdistrict_walkability_score', 0 ),
            'air_quality_class' => $get_meta( 'wsdistrict_air_quality_class', '' ),
            'crime_level'   => $get_meta( 'wsdistrict_crime_level', '' ),
        ];
    }
    
    /**
     * Get all districts
     */
    public static function get_all_districts(): array {
        global $wpdb;
        
        $district_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} 
             WHERE post_type = %s AND post_status = 'publish'
             ORDER BY post_title",
            self::SLUG
        ) );
        
        $districts = [];
        foreach ( $district_ids as $district_id ) {
            $district = self::get_district( $district_id );
            if ( $district ) {
                $districts[] = $district;
            }
        }
        
        return $districts;
    }
    
    /**
     * Получить топ районов по комфорту
     */
    public static function get_top_districts_by_comfort( int $limit = 5 ): array {
        global $wpdb;
        
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title, pm.meta_value as comfort_score
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'wsdistrict_comfort_score'
             WHERE p.post_type = %s AND p.post_status = 'publish'
               AND pm.meta_value > 0
             ORDER BY CAST(pm.meta_value AS DECIMAL) DESC
             LIMIT %d",
            self::SLUG,
            $limit
        ) );
        
        return $results;
    }
    
    /**
     * Получить районы с низкой безопасностью
     */
    public static function get_districts_by_safety_level( string $level = 'Low' ): array {
        global $wpdb;
        
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title, pm.meta_value as safety_score
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'wsdistrict_crime_level'
             WHERE p.post_type = %s AND p.post_status = 'publish'
               AND pm.meta_value = %s",
            self::SLUG,
            $level
        ) );
    }

    /**
 * Общий индекс эргономичности района (делегат в WorldStat Ergonomics).
 *
 * @return array{score:float,level:string,color:string,scores:array<string,float>}
 */
public static function get_ergonomics_index( int $district_id ): array {
	if ( class_exists( 'WSErgo_District_Bridge' ) ) {
		return WSErgo_District_Bridge::get_ergonomics_index( $district_id );
	}
	return [
		'score'  => 0.0,
		'level'  => '',
		'color'  => '#6b7280',
		'scores' => [],
	];
}
}