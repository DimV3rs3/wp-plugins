<?php
/**
 * Renderer for Districts extension
 *
 * @package WorldStatDistricts
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSDistricts_Renderer {

    /**
     * Render districts list for city page
     * 
     * @param int $city_id ID of the city
     */
    public static function render_city_districts_list( int $city_id ): void {
        // Проверяем существование метода
        if ( ! method_exists( 'WSDistricts_CPT', 'get_districts_for_city' ) ) {
            echo '<div class="wsp-notice"><p>Ошибка: метод get_districts_for_city не найден. Проверьте установку плагина.</p></div>';
            return;
        }
        
        $districts = WSDistricts_CPT::get_districts_for_city( $city_id );

        if ( empty( $districts ) ) {
            echo '<div class="wsp-notice">';
            echo '<p>Нет данных о районах для этого города.</p>';
            
            $city = get_post( $city_id );
            if ( $city && ( $city->post_title === 'New York' || $city->post_title === 'Нью-Йорк' ) ) {
                echo '<form method="post" style="margin-top: 15px;">';
                echo '<input type="hidden" name="create_ny_districts" value="1">';
                echo '<input type="hidden" name="city_id" value="' . $city_id . '">';
                wp_nonce_field( 'create_ny_districts_action', 'create_ny_nonce' );
                echo '<button type="submit" class="button button-primary">Создать районы Нью-Йорка</button>';
                echo '</form>';
            }
            echo '</div>';
            return;
        }

        // Calculate total statistics
        $total_population = array_sum( array_column( $districts, 'population' ) );
        $total_area = array_sum( array_column( $districts, 'area' ) );
        
        // Find largest district by population
        $largest_district = array_reduce( $districts, function( $carry, $item ) {
            if ( ! $carry ) return $item;
            return $item['population'] > $carry['population'] ? $item : $carry;
        } );
        
        $avg_density = $total_area > 0 ? round( $total_population / $total_area ) : 0;
        $avg_area = $total_area / count( $districts );
        ?>
        
        <div class="wsp-section">
            <h3 class="wsp-section-title">
                <span class="dashicons dashicons-networking"></span> 
                Районы города
            </h3>
            
            <!-- Statistics Grid -->
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin: 20px 0;">
                <div style="background: #f8fafc; padding: 15px; border-radius: 8px; text-align: center;">
                    <div style="font-size: 28px; font-weight: bold; color: #3b82f6;"><?php echo count( $districts ); ?></div>
                    <div style="color: #666;">Всего районов</div>
                </div>
                
                <div style="background: #f8fafc; padding: 15px; border-radius: 8px; text-align: center;">
                    <div style="font-size: 28px; font-weight: bold; color: #10b981;"><?php echo number_format( $total_population, 0, '', ' ' ); ?></div>
                    <div style="color: #666;">Население районов</div>
                </div>
                
                <div style="background: #f8fafc; padding: 15px; border-radius: 8px; text-align: center;">
                    <div style="font-size: 28px; font-weight: bold; color: #f59e0b;"><?php echo number_format( $total_area, 0, '', ' ' ); ?></div>
                    <div style="color: #666;">Площадь (га)</div>
                </div>
                
                <div style="background: #f8fafc; padding: 15px; border-radius: 8px; text-align: center;">
                    <div style="font-size: 18px; font-weight: bold; color: #8b5cf6;"><?php echo esc_html( $largest_district['name'] ); ?></div>
                    <div style="color: #666;">Крупнейший район</div>
                </div>
            </div>
            
            <!-- Additional Statistics -->
            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; margin: 20px 0;">
                <div style="background: linear-gradient(135deg, #10b98110 0%, #10b98105 100%); padding: 15px; border-radius: 8px;">
                    <div style="font-size: 14px; color: #666;">Средняя плотность населения</div>
                    <div style="font-size: 28px; font-weight: bold; color: #10b981;">
                        <?php echo number_format( $avg_density ); ?>
                        <span style="font-size: 14px;">чел/га</span>
                    </div>
                </div>
                
                <div style="background: linear-gradient(135deg, #8b5cf610 0%, #8b5cf605 100%); padding: 15px; border-radius: 8px;">
                    <div style="font-size: 14px; color: #666;">Средняя площадь района</div>
                    <div style="font-size: 28px; font-weight: bold; color: #8b5cf6;">
                        <?php echo number_format( $avg_area, 1 ); ?>
                        <span style="font-size: 14px;">га</span>
                    </div>
                </div>
            </div>
            
            <!-- Districts Table -->
            <div style="overflow-x: auto; margin-top: 20px;">
                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr style="background: #f1f5f9;">
                            <th style="padding: 12px; text-align: left;">Район</th>
                            <th style="padding: 12px; text-align: right;">Население</th>
                            <th style="padding: 12px; text-align: right;">Площадь (га)</th>
                            <th style="padding: 12px; text-align: right;">Плотность</th>
                            <th style="padding: 12px; text-align: left;">Год основания</th>
                            <th style="padding: 12px; text-align: left;">Почтовый индекс</th>
                         </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $districts as $district ): 
                            $district_url = get_permalink( $district['id'] );
                            $density = $district['area'] > 0 ? round( $district['population'] / $district['area'] ) : 0;
                        ?>
                            <tr>
                                <td style="padding: 12px;">
                                    <strong><a href="<?php echo esc_url( $district_url ); ?>"><?php echo esc_html( $district['name'] ); ?></a></strong>
                                    <?php if ( ! empty( $district['description'] ) ): ?>
                                        <div style="font-size: 12px; color: #666;"><?php echo esc_html( wp_trim_words( $district['description'], 10 ) ); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px; text-align: right;"><?php echo number_format( $district['population'], 0, '', ' ' ); ?></td>
                                <td style="padding: 12px; text-align: right;"><?php echo number_format( $district['area'], 1 ); ?></td>
                                <td style="padding: 12px; text-align: right;"><?php echo number_format( $density ); ?> чел/га</td>
                                <td style="padding: 12px;"><?php echo esc_html( $district['established'] ?: '—' ); ?></td>
                                <td style="padding: 12px;"><?php echo esc_html( $district['postal_code'] ?: '—' ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <style>
        .wsp-section-title {
            font-size: 20px;
            margin: 20px 0 15px 0;
            padding-bottom: 10px;
            border-bottom: 2px solid #e5e7eb;
        }
        .wsp-notice {
            background: #fef2f2;
            border-left: 4px solid #ef4444;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
        }
        </style>
        
        <?php
    }

    /**
     * Handle district creation from city page
     */
    public static function handle_district_creation() {
        if ( isset( $_POST['create_ny_districts'] ) && isset( $_POST['create_ny_nonce'] ) ) {
            if ( ! wp_verify_nonce( $_POST['create_ny_nonce'], 'create_ny_districts_action' ) ) {
                return;
            }
            
            $city_id = isset( $_POST['city_id'] ) ? intval( $_POST['city_id'] ) : 0;
            if ( ! $city_id ) {
                return;
            }
            
            self::create_ny_districts( $city_id );
            
            wp_redirect( remove_query_arg( 'create_ny_districts' ) );
            exit;
        }
    }

    /**
     * Create New York districts for a city
     */
    public static function create_ny_districts( int $city_id ) {
        $districts_data = [
            [
                'name' => 'Manhattan',
                'lat' => 40.7831,
                'lng' => -73.9712,
                'population' => 1694260,
                'area' => 5914,
                'established' => '1624',
                'postal_code' => '10001-10040',
                'website' => 'https://manhattan.nyc',
                'description' => 'Manhattan is the most densely populated borough of New York City.'
            ],
            [
                'name' => 'Brooklyn',
                'lat' => 40.6782,
                'lng' => -73.9442,
                'population' => 2736074,
                'area' => 18340,
                'established' => '1634',
                'postal_code' => '11201-11256',
                'website' => 'https://brooklyn.nyc',
                'description' => 'Brooklyn is the most populous borough of New York City.'
            ],
            [
                'name' => 'Queens',
                'lat' => 40.7282,
                'lng' => -73.7949,
                'population' => 2405464,
                'area' => 28300,
                'established' => '1683',
                'postal_code' => '11101-11697',
                'website' => 'https://queens.nyc',
                'description' => 'Queens is the largest borough by area.'
            ],
            [
                'name' => 'Bronx',
                'lat' => 40.8448,
                'lng' => -73.8648,
                'population' => 1472654,
                'area' => 10940,
                'established' => '1639',
                'postal_code' => '10451-10475',
                'website' => 'https://bronx.nyc',
                'description' => 'The Bronx is known for Yankee Stadium.'
            ],
            [
                'name' => 'Staten Island',
                'lat' => 40.5795,
                'lng' => -74.1502,
                'population' => 495747,
                'area' => 15150,
                'established' => '1661',
                'postal_code' => '10301-10314',
                'website' => 'https://statenisland.nyc',
                'description' => 'Staten Island is known for its suburban character.'
            ]
        ];
        
        $city_name = get_the_title( $city_id );
        
        foreach ( $districts_data as $d ) {
            $existing = get_posts( [
                'post_type' => WSDistricts_CPT::SLUG,
                'title' => $d['name'],
                'posts_per_page' => 1,
                'post_status' => 'any'
            ] );
            
            if ( ! empty( $existing ) ) {
                $post_id = $existing[0]->ID;
            } else {
                $post_id = wp_insert_post( [
                    'post_title' => $d['name'],
                    'post_type' => WSDistricts_CPT::SLUG,
                    'post_status' => 'publish',
                    'post_author' => 1,
                    'post_content' => $d['description'],
                ] );
            }
            
            if ( $post_id && ! is_wp_error( $post_id ) ) {
                $density = $d['area'] > 0 ? round( $d['population'] / $d['area'] ) : 0;
                
                update_post_meta( $post_id, 'wsdistrict_country_iso2', 'US' );
                update_post_meta( $post_id, 'wsdistrict_country_name', 'United States' );
                update_post_meta( $post_id, 'wsdistrict_city_id', $city_id );
                update_post_meta( $post_id, 'wsdistrict_city_name', $city_name );
                update_post_meta( $post_id, 'wsdistrict_lat', $d['lat'] );
                update_post_meta( $post_id, 'wsdistrict_lng', $d['lng'] );
                update_post_meta( $post_id, 'wsdistrict_population', $d['population'] );
                update_post_meta( $post_id, 'wsdistrict_area', $d['area'] );
                update_post_meta( $post_id, 'wsdistrict_density', $density );
                update_post_meta( $post_id, 'wsdistrict_established', $d['established'] );
                update_post_meta( $post_id, 'wsdistrict_postal_code', $d['postal_code'] );
                update_post_meta( $post_id, 'wsdistrict_website', $d['website'] );
            }
        }
        
        wp_cache_flush();
    }
}