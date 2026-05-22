<?php
/**
 * Districts main page renderer
 * 
 * @package WorldStatDistricts
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSDistricts_Page {
    
    /**
     * Render the main districts page
     */
    public static function render_page(): void {
        global $wpdb;
        
        // Get statistics
        $total_districts = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
            WSDistricts_CPT::SLUG
        ) );
        
        $total_cities = self::get_cities_with_districts();
        $total_countries = self::get_countries_with_districts();
        
        // Get top districts by rating
        $top_districts = self::get_top_districts( 6 );
        
        // Get districts by country
        $districts_by_country = self::get_districts_by_country();
        
        // Calculate global averages
        $global_avg_comfort = WSDistricts_Data::get_global_avg_comfort();
        $global_avg_safety = WSDistricts_Data::get_global_avg_safety();
        $global_avg_functionality = WSDistricts_Data::get_global_avg_functionality();
        
        ?>
        <div class="wsp-container wsp-districts-main">
            
            <!-- Hero Section -->
            <div class="wsp-hero" style="background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); color: white; padding: 40px; border-radius: 12px; margin-bottom: 30px;">
                <div class="wsp-hero-content" style="text-align: center;">
                    <h1 style="color: white; margin: 0 0 10px 0;">
                        <span class="dashicons dashicons-networking" style="font-size: 48px; width: 48px; height: 48px;"></span>
                        Анализ районов мира
                    </h1>
                    <p style="font-size: 18px; margin-bottom: 30px;">Данные о комфортности, безопасности и функциональности городских районов</p>
                    
                    <!-- Global Stats -->
                    <div class="wsp-stats-grid" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; max-width: 800px; margin: 0 auto;">
                        <div class="wsp-stat-card" style="background: rgba(255,255,255,0.1); backdrop-filter: blur(10px);">
                            <div class="wsp-stat-icon"><span class="dashicons dashicons-networking"></span></div>
                            <div class="wsp-stat-value" style="font-size: 32px;"><?php echo number_format( $total_districts ); ?></div>
                            <div class="wsp-stat-label">Всего районов</div>
                        </div>
                        
                        <div class="wsp-stat-card" style="background: rgba(255,255,255,0.1); backdrop-filter: blur(10px);">
                            <div class="wsp-stat-icon"><span class="dashicons dashicons-building"></span></div>
                            <div class="wsp-stat-value" style="font-size: 32px;"><?php echo $total_cities; ?></div>
                            <div class="wsp-stat-label">Городов</div>
                        </div>
                        
                        <div class="wsp-stat-card" style="background: rgba(255,255,255,0.1); backdrop-filter: blur(10px);">
                            <div class="wsp-stat-icon"><span class="dashicons dashicons-admin-site"></span></div>
                            <div class="wsp-stat-value" style="font-size: 32px;"><?php echo $total_countries; ?></div>
                            <div class="wsp-stat-label">Стран</div>
                        </div>
                        
                        <div class="wsp-stat-card" style="background: rgba(255,255,255,0.1); backdrop-filter: blur(10px);">
                            <div class="wsp-stat-icon"><span class="dashicons dashicons-groups"></span></div>
                            <div class="wsp-stat-value" style="font-size: 32px;"><?php echo number_format( self::get_total_population() ); ?></div>
                            <div class="wsp-stat-label">Население</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Global Averages Section -->
            <div class="wsp-section" style="margin-bottom: 40px;">
                <h2 class="wsp-section-title">Глобальные показатели</h2>
                <div class="wsp-stats-grid" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px;">
                    <?php self::render_score_card( 'Комфорт', $global_avg_comfort, '#10b981', 'leaf' ); ?>
                    <?php self::render_score_card( 'Безопасность', $global_avg_safety, '#8b5cf6', 'shield' ); ?>
                    <?php self::render_score_card( 'Функциональность', $global_avg_functionality, '#f59e0b', 'admin-tools' ); ?>
                </div>
            </div>
            
            <!-- Top Districts Section -->
            <div class="wsp-section" style="margin-bottom: 40px;">
                <h2 class="wsp-section-title">Лучшие районы мира</h2>
                <div class="wsp-districts-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 20px;">
                    <?php foreach ( $top_districts as $district ): ?>
                        <?php self::render_district_card( $district ); ?>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Districts by Country Section -->
            <div class="wsp-section">
                <h2 class="wsp-section-title">Районы по странам</h2>
                <div class="wsp-accordion">
                    <?php foreach ( $districts_by_country as $country_name => $country_data ): ?>
                        <div class="wsp-accordion-item" style="margin-bottom: 10px; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
                            <div class="wsp-accordion-header" style="background: #f9fafb; padding: 15px; cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <strong><?php echo esc_html( $country_name ); ?></strong>
                                    <span style="color: #666; margin-left: 10px;">(<?php echo count( $country_data['districts'] ); ?> районов)</span>
                                </div>
                                <div>
                                    <span style="margin-right: 15px;">Ср. комфорт: <?php echo round( $country_data['avg_comfort'] ); ?></span>
                                    <span class="dashicons dashicons-arrow-down-alt2"></span>
                                </div>
                            </div>
                            <div class="wsp-accordion-content" style="display: none; padding: 15px; background: white;">
                                <div class="wsp-districts-mini-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 15px;">
                                    <?php foreach ( $country_data['districts'] as $district ): ?>
                                        <div style="background: #f9fafb; padding: 12px; border-radius: 6px;">
                                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                                <div>
                                                    <strong><a href="<?php echo get_permalink( $district['id'] ); ?>" style="text-decoration: none;"><?php echo esc_html( $district['name'] ); ?></a></strong>
                                                    <div style="font-size: 12px; color: #666;"><?php echo esc_html( $district['city_name'] ); ?></div>
                                                </div>
                                                <div style="text-align: right;">
                                                    <div style="font-size: 18px; font-weight: bold; color: <?php echo self::get_score_color( $district['avg_score'] ); ?>">
                                                        <?php echo round( $district['avg_score'] ); ?>
                                                    </div>
                                                    <div style="font-size: 11px; color: #666;">рейтинг</div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
        </div>
        
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const accordionHeaders = document.querySelectorAll('.wsp-accordion-header');
            accordionHeaders.forEach(header => {
                header.addEventListener('click', function() {
                    const content = this.nextElementSibling;
                    const icon = this.querySelector('.dashicons');
                    if (content.style.display === 'none' || !content.style.display) {
                        content.style.display = 'block';
                        if (icon) icon.classList.replace('dashicons-arrow-down-alt2', 'dashicons-arrow-up-alt2');
                    } else {
                        content.style.display = 'none';
                        if (icon) icon.classList.replace('dashicons-arrow-up-alt2', 'dashicons-arrow-down-alt2');
                    }
                });
            });
        });
        </script>
        
        <style>
        .wsp-districts-main .wsp-stat-card {
            background: #fff;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            text-align: center;
            transition: transform 0.2s;
        }
        .wsp-districts-main .wsp-stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .wsp-districts-main .wsp-stat-icon .dashicons {
            font-size: 32px;
            width: 32px;
            height: 32px;
            color: #2271b1;
        }
        .wsp-districts-main .wsp-stat-value {
            font-size: 24px;
            font-weight: bold;
            margin: 10px 0;
        }
        .wsp-stat-label {
            color: #666;
            font-size: 14px;
        }
        .wsp-section-title {
            font-size: 24px;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e5e7eb;
        }
        </style>
        
        <?php
    }
    
    /**
     * Render a score card
     */
    private static function render_score_card( string $title, float $score, string $color, string $icon ): void {
        ?>
        <div class="wsp-score-card" style="background: linear-gradient(135deg, <?php echo $color; ?>10 0%, <?php echo $color; ?>05 100%); padding: 20px; border-radius: 12px; text-align: center;">
            <span class="dashicons dashicons-<?php echo $icon; ?>" style="font-size: 36px; width: 36px; height: 36px; color: <?php echo $color; ?>;"></span>
            <div style="font-size: 42px; font-weight: bold; color: <?php echo $color; ?>; margin: 10px 0;">
                <?php echo round( $score ); ?><span style="font-size: 20px;">/100</span>
            </div>
            <div style="font-size: 16px; font-weight: 500;"><?php echo $title; ?></div>
            <div style="height: 8px; background: #e5e7eb; margin-top: 15px; border-radius: 4px;">
                <div style="height: 100%; width: <?php echo $score; ?>%; background: <?php echo $color; ?>; border-radius: 4px;"></div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render a district card
     */
    private static function render_district_card( array $district ): void {
        $avg_score = ( $district['comfort_score'] + $district['safety_score'] + $district['functionality_score'] ) / 3;
        $color = self::get_score_color( $avg_score );
        
        ?>
        <div class="wsp-district-card" style="background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1); transition: transform 0.2s;">
            <div style="background: <?php echo $color; ?>; padding: 15px; color: white;">
                <h3 style="margin: 0; font-size: 18px;">
                    <a href="<?php echo get_permalink( $district['id'] ); ?>" style="color: white; text-decoration: none;">
                        <?php echo esc_html( $district['name'] ); ?>
                    </a>
                </h3>
                <div style="font-size: 14px; margin-top: 5px;"><?php echo esc_html( $district['city_name'] ); ?>, <?php echo esc_html( $district['country_name'] ); ?></div>
            </div>
            <div style="padding: 15px;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 15px;">
                    <div>
                        <div style="font-size: 12px; color: #666;">Рейтинг</div>
                        <div style="font-size: 28px; font-weight: bold; color: <?php echo $color; ?>;"><?php echo round( $avg_score ); ?></div>
                    </div>
                    <div style="text-align: right;">
                        <div style="font-size: 12px; color: #666;">Население</div>
                        <div style="font-size: 16px; font-weight: 500;"><?php echo number_format( $district['population'], 0, '', ' ' ); ?></div>
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 15px;">
                    <div>
                        <div style="font-size: 11px; color: #666;">Комфорт</div>
                        <div style="font-weight: 500;"><?php echo round( $district['comfort_score'] ); ?></div>
                        <div style="height: 3px; background: #e5e7eb; margin-top: 4px;">
                            <div style="height: 100%; width: <?php echo $district['comfort_score']; ?>%; background: #10b981;"></div>
                        </div>
                    </div>
                    <div>
                        <div style="font-size: 11px; color: #666;">Безопасность</div>
                        <div style="font-weight: 500;"><?php echo round( $district['safety_score'] ); ?></div>
                        <div style="height: 3px; background: #e5e7eb; margin-top: 4px;">
                            <div style="height: 100%; width: <?php echo $district['safety_score']; ?>%; background: #8b5cf6;"></div>
                        </div>
                    </div>
                </div>
                
                <a href="<?php echo get_permalink( $district['id'] ); ?>" class="button" style="width: 100%; text-align: center;">
                    Подробнее о районе →
                </a>
            </div>
        </div>
        <style>
        .wsp-district-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 16px rgba(0,0,0,0.1);
        }
        </style>
        <?php
    }
    
    /**
     * Get top districts by rating
     */
    private static function get_top_districts( int $limit = 6 ): array {
        global $wpdb;
        
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title as name,
                pm_comfort.meta_value as comfort_score,
                pm_safety.meta_value as safety_score,
                pm_func.meta_value as functionality_score,
                pm_pop.meta_value as population,
                pm_city.meta_value as city_name,
                pm_country.meta_value as country_name
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm_comfort ON pm_comfort.post_id = p.ID AND pm_comfort.meta_key = 'wsdistrict_comfort_score'
             JOIN {$wpdb->postmeta} pm_safety ON pm_safety.post_id = p.ID AND pm_safety.meta_key = 'wsdistrict_safety_score'
             JOIN {$wpdb->postmeta} pm_func ON pm_func.post_id = p.ID AND pm_func.meta_key = 'wsdistrict_functionality_score'
             LEFT JOIN {$wpdb->postmeta} pm_pop ON pm_pop.post_id = p.ID AND pm_pop.meta_key = 'wsdistrict_population'
             LEFT JOIN {$wpdb->postmeta} pm_city ON pm_city.post_id = p.ID AND pm_city.meta_key = 'wsdistrict_city_name'
             LEFT JOIN {$wpdb->postmeta} pm_country ON pm_country.post_id = p.ID AND pm_country.meta_key = 'wsdistrict_country_name'
             WHERE p.post_type = %s AND p.post_status = 'publish'
             ORDER BY (CAST(pm_comfort.meta_value AS DECIMAL) + CAST(pm_safety.meta_value AS DECIMAL) + CAST(pm_func.meta_value AS DECIMAL)) / 3 DESC
             LIMIT %d",
            WSDistricts_CPT::SLUG,
            $limit
        ) );
        
        $districts = [];
        foreach ( $rows as $r ) {
            $districts[] = [
                'id' => (int) $r->ID,
                'name' => $r->name,
                'comfort_score' => (float) $r->comfort_score,
                'safety_score' => (float) $r->safety_score,
                'functionality_score' => (float) $r->functionality_score,
                'population' => (int) $r->population,
                'city_name' => $r->city_name,
                'country_name' => $r->country_name,
            ];
        }
        
        return $districts;
    }
    
    /**
     * Get districts grouped by country
     */
    private static function get_districts_by_country(): array {
        global $wpdb;
        
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title as name,
                pm_comfort.meta_value as comfort_score,
                pm_safety.meta_value as safety_score,
                pm_func.meta_value as functionality_score,
                pm_city.meta_value as city_name,
                pm_country.meta_value as country_name
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm_comfort ON pm_comfort.post_id = p.ID AND pm_comfort.meta_key = 'wsdistrict_comfort_score'
             JOIN {$wpdb->postmeta} pm_safety ON pm_safety.post_id = p.ID AND pm_safety.meta_key = 'wsdistrict_safety_score'
             JOIN {$wpdb->postmeta} pm_func ON pm_func.post_id = p.ID AND pm_func.meta_key = 'wsdistrict_functionality_score'
             LEFT JOIN {$wpdb->postmeta} pm_city ON pm_city.post_id = p.ID AND pm_city.meta_key = 'wsdistrict_city_name'
             LEFT JOIN {$wpdb->postmeta} pm_country ON pm_country.post_id = p.ID AND pm_country.meta_key = 'wsdistrict_country_name'
             WHERE p.post_type = %s AND p.post_status = 'publish'
               AND pm_country.meta_value != ''
             ORDER BY pm_country.meta_value, pm_city.meta_value, p.post_title",
            WSDistricts_CPT::SLUG
        ) );
        
        $countries = [];
        foreach ( $rows as $r ) {
            $country = $r->country_name;
            if ( ! isset( $countries[ $country ] ) ) {
                $countries[ $country ] = [
                    'districts' => [],
                    'total_comfort' => 0,
                    'total_safety' => 0,
                    'total_functionality' => 0,
                    'count' => 0,
                ];
            }
            
            $avg_score = ( (float) $r->comfort_score + (float) $r->safety_score + (float) $r->functionality_score ) / 3;
            
            $countries[ $country ]['districts'][] = [
                'id' => (int) $r->ID,
                'name' => $r->name,
                'city_name' => $r->city_name,
                'comfort_score' => (float) $r->comfort_score,
                'safety_score' => (float) $r->safety_score,
                'functionality_score' => (float) $r->functionality_score,
                'avg_score' => $avg_score,
            ];
            
            $countries[ $country ]['total_comfort'] += (float) $r->comfort_score;
            $countries[ $country ]['total_safety'] += (float) $r->safety_score;
            $countries[ $country ]['total_functionality'] += (float) $r->functionality_score;
            $countries[ $country ]['count']++;
        }
        
        // Calculate averages
        foreach ( $countries as &$data ) {
            $data['avg_comfort'] = $data['total_comfort'] / $data['count'];
            $data['avg_safety'] = $data['total_safety'] / $data['count'];
            $data['avg_functionality'] = $data['total_functionality'] / $data['count'];
        }
        
        // Sort by country name
        ksort( $countries );
        
        return $countries;
    }
    
    /**
     * Get cities count with districts
     */
    private static function get_cities_with_districts(): int {
        global $wpdb;
        
        return (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT pm.meta_value) 
             FROM {$wpdb->postmeta} pm
             WHERE pm.meta_key = 'wsdistrict_city_id' 
               AND pm.meta_value != ''"
        );
    }
    
    /**
     * Get countries count with districts
     */
    private static function get_countries_with_districts(): int {
        global $wpdb;
        
        return (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT pm.meta_value) 
             FROM {$wpdb->postmeta} pm
             WHERE pm.meta_key = 'wsdistrict_country_iso2' 
               AND pm.meta_value != ''"
        );
    }
    
    /**
     * Get total population of all districts
     */
    private static function get_total_population(): int {
        global $wpdb;
        
        return (int) $wpdb->get_var(
            "SELECT SUM(pm.meta_value) 
             FROM {$wpdb->postmeta} pm
             WHERE pm.meta_key = 'wsdistrict_population'"
        );
    }
    
    /**
     * Get color based on score
     */
    private static function get_score_color( float $score ): string {
        if ( $score >= 75 ) return '#10b981';
        if ( $score >= 60 ) return '#3b82f6';
        if ( $score >= 40 ) return '#f59e0b';
        return '#ef4444';
    }
}