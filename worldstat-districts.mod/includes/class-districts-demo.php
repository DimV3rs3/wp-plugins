<?php
/**
 * Demo data generator for Districts extension
 * 
 * @package WorldStatDistricts
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSDistricts_Demo {

    /**
     * Create demo districts for Moscow
     */
    public static function create_demo_districts() {
        // Check if Moscow city exists
        $moscow_id = self::get_or_create_moscow();
        if ( ! $moscow_id ) {
            return 0;
        }

        // Demo districts data
        $districts = [
            [
                'name' => 'Арбат',
                'lat' => 55.751574,
                'lng' => 37.590699,
                'population' => 35000,
                'area' => 211,
                'green_index' => 65,
                'infrastructure_index' => 95,
                'transport_index' => 98,
                'ecology_index' => 70,
                'social_index' => 92,
                'economic_index' => 95,
                'crime_rate' => 15,
                'noise_level' => 75,
                'air_quality' => 68,
                'description' => 'Исторический центр Москвы, пешеходная улица Арбат, множество театров и музеев.'
            ],
            [
                'name' => 'Хамовники',
                'lat' => 55.727029,
                'lng' => 37.567633,
                'population' => 105000,
                'area' => 980,
                'green_index' => 82,
                'infrastructure_index' => 88,
                'transport_index' => 85,
                'ecology_index' => 75,
                'social_index' => 87,
                'economic_index' => 85,
                'crime_rate' => 18,
                'noise_level' => 65,
                'air_quality' => 72,
                'description' => 'Престижный район с парками, спорткомплексом Лужники, Новодевичьим монастырем.'
            ],
            [
                'name' => 'Раменки',
                'lat' => 55.704323,
                'lng' => 37.518395,
                'population' => 135000,
                'area' => 1870,
                'green_index' => 78,
                'infrastructure_index' => 82,
                'transport_index' => 75,
                'ecology_index' => 80,
                'social_index' => 79,
                'economic_index' => 78,
                'crime_rate' => 22,
                'noise_level' => 58,
                'air_quality' => 76,
                'description' => 'Спокойный спальный район с хорошей экологией, рядом МГУ и Воробьевы горы.'
            ],
            [
                'name' => 'Якиманка',
                'lat' => 55.735748,
                'lng' => 37.608915,
                'population' => 27000,
                'area' => 480,
                'green_index' => 70,
                'infrastructure_index' => 92,
                'transport_index' => 94,
                'ecology_index' => 68,
                'social_index' => 88,
                'economic_index' => 92,
                'crime_rate' => 16,
                'noise_level' => 72,
                'air_quality' => 66,
                'description' => 'Центральный район с Третьяковской галереей, Парком Горького, Музеоном.'
            ],
            [
                'name' => 'Крылатское',
                'lat' => 55.756624,
                'lng' => 37.408312,
                'population' => 82000,
                'area' => 1250,
                'green_index' => 95,
                'infrastructure_index' => 84,
                'transport_index' => 72,
                'ecology_index' => 92,
                'social_index' => 81,
                'economic_index' => 76,
                'crime_rate' => 12,
                'noise_level' => 45,
                'air_quality' => 88,
                'description' => 'Экологически чистый район с гребным каналом, велодромом, лесопарком.'
            ]
        ];

        $created = 0;
        foreach ( $districts as $data ) {
            $result = self::create_district_post( $data, $moscow_id, 'Moscow' );
            if ( $result ) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * Get or create Moscow city
     */
    private static function get_or_create_moscow() {
        global $wpdb;

        // Check if Cities extension is active
        if ( ! class_exists( 'WSCities_CPT' ) ) {
            return null;
        }

        // Try to find existing Moscow
        $city_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'wscity_country_iso2'
             WHERE p.post_type = 'wsp_city' 
               AND p.post_title = %s 
               AND pm.meta_value = 'RU'
             LIMIT 1",
            'Moscow'
        ) );

        if ( $city_id ) {
            return (int) $city_id;
        }

        // Create Moscow if not exists
        $city_data = [
            'post_title' => 'Moscow',
            'post_type' => 'wsp_city',
            'post_status' => 'publish',
            'post_author' => 1,
        ];

        $city_id = wp_insert_post( $city_data );
        if ( is_wp_error( $city_id ) ) {
            return null;
        }

        // Add city meta
        update_post_meta( $city_id, 'wscity_country_iso2', 'RU' );
        update_post_meta( $city_id, 'wscity_country_name', 'Russia' );
        update_post_meta( $city_id, 'wscity_lat', 55.755826 );
        update_post_meta( $city_id, 'wscity_lng', 37.617300 );
        update_post_meta( $city_id, 'wscity_pop_t3', 12600000 );
        update_post_meta( $city_id, 'wscity_builtup_t3', 251100 );

        return $city_id;
    }

    /**
     * Create a district post
     */
    private static function create_district_post( array $data, int $city_id, string $city_name ) {
        global $wpdb;

        // Check if district already exists
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} 
             WHERE post_type = %s AND post_title = %s",
            WSDistricts_CPT::SLUG,
            $data['name']
        ) );

        if ( $exists ) {
            return null;
        }

        // Create post
        $post_data = [
            'post_title' => $data['name'],
            'post_content' => $data['description'],
            'post_type' => WSDistricts_CPT::SLUG,
            'post_status' => 'publish',
            'post_author' => 1,
        ];

        $post_id = wp_insert_post( $post_data );
        if ( is_wp_error( $post_id ) ) {
            return null;
        }

        // Calculate density
        $density = $data['area'] > 0 ? round( $data['population'] / $data['area'] ) : 0;

        // Calculate composite scores
        $calculator = new WSDistricts_Metrics_Calculator();
        
        $comfort = $calculator->calculate_comfort_score([
            'green_index' => $data['green_index'],
            'ecology_index' => $data['ecology_index'],
            'air_quality' => $data['air_quality'],
            'noise_level' => $data['noise_level'],
            'social_index' => $data['social_index'],
        ]);
        
        $safety = $calculator->calculate_safety_score([
            'crime_rate' => $data['crime_rate'],
            'infrastructure_index' => $data['infrastructure_index'],
            'social_index' => $data['social_index'],
        ]);
        
        $functionality = $calculator->calculate_functionality_score([
            'infrastructure_index' => $data['infrastructure_index'],
            'transport_index' => $data['transport_index'],
            'economic_index' => $data['economic_index'],
            'social_index' => $data['social_index'],
        ]);

        $metrics_data = [
            'comfort_components' => $calculator->get_last_comfort_components(),
            'safety_components' => $calculator->get_last_safety_components(),
            'functionality_components' => $calculator->get_last_functionality_components(),
        ];

        // Save meta
        update_post_meta( $post_id, 'wsdistrict_country_iso2', 'RU' );
        update_post_meta( $post_id, 'wsdistrict_country_name', 'Russia' );
        update_post_meta( $post_id, 'wsdistrict_city_id', $city_id );
        update_post_meta( $post_id, 'wsdistrict_city_name', $city_name );
        update_post_meta( $post_id, 'wsdistrict_lat', $data['lat'] );
        update_post_meta( $post_id, 'wsdistrict_lng', $data['lng'] );
        update_post_meta( $post_id, 'wsdistrict_population', $data['population'] );
        update_post_meta( $post_id, 'wsdistrict_area', $data['area'] );
        update_post_meta( $post_id, 'wsdistrict_density', $density );
        update_post_meta( $post_id, 'wsdistrict_green_index', $data['green_index'] );
        update_post_meta( $post_id, 'wsdistrict_infrastructure_index', $data['infrastructure_index'] );
        update_post_meta( $post_id, 'wsdistrict_transport_index', $data['transport_index'] );
        update_post_meta( $post_id, 'wsdistrict_ecology_index', $data['ecology_index'] );
        update_post_meta( $post_id, 'wsdistrict_social_index', $data['social_index'] );
        update_post_meta( $post_id, 'wsdistrict_economic_index', $data['economic_index'] );
        update_post_meta( $post_id, 'wsdistrict_crime_rate', $data['crime_rate'] );
        update_post_meta( $post_id, 'wsdistrict_noise_level', $data['noise_level'] );
        update_post_meta( $post_id, 'wsdistrict_air_quality', $data['air_quality'] );
        update_post_meta( $post_id, 'wsdistrict_comfort_score', $comfort );
        update_post_meta( $post_id, 'wsdistrict_safety_score', $safety );
        update_post_meta( $post_id, 'wsdistrict_functionality_score', $functionality );
        update_post_meta( $post_id, 'wsdistrict_metrics_data', wp_json_encode( $metrics_data ) );

        return $post_id;
    }

    /**
     * Add demo button to admin
     */
    public static function add_demo_button() {
        add_submenu_page(
            'worldstat',
            'Демо-данные районов',
            'Демо-данные',
            'manage_options',
            'worldstat-districts-demo',
            [ __CLASS__, 'render_demo_page' ]
        );
    }

    /**
     * Render demo page
     */
    public static function render_demo_page() {
        $created = 0;
        if ( isset( $_POST['create_demo'] ) && check_admin_referer( 'wsdistricts_demo' ) ) {
            $created = self::create_demo_districts();
        }
        ?>
        <div class="wrap">
            <h1>Демо-данные для районов</h1>
            
            <?php if ( $created > 0 ): ?>
                <div class="notice notice-success">
                    <p>Создано демо-районов: <?php echo $created; ?></p>
                </div>
            <?php endif; ?>

            <div class="card" style="max-width: 600px; padding: 20px;">
                <h2>Создать демо-районы Москвы</h2>
                <p>Будут созданы 5 районов Москвы с демо-данными:</p>
                <ul>
                    <li>Арбат - исторический центр</li>
                    <li>Хамовники - престижный район</li>
                    <li>Раменки - спальный район</li>
                    <li>Якиманка - культурный центр</li>
                    <li>Крылатское - экологичный район</li>
                </ul>
                
                <form method="post">
                    <?php wp_nonce_field( 'wsdistricts_demo' ); ?>
                    <input type="submit" name="create_demo" class="button button-primary" value="Создать демо-данные">
                </form>
            </div>

            <div class="card" style="max-width: 600px; padding: 20px; margin-top: 20px;">
                <h3>Как это работает</h3>
                <p>После создания демо-данных:</p>
                <ol>
                    <li>Перейдите в раздел "Районы" в меню World Statistics</li>
                    <li>Вы увидите главную страницу с аналитикой районов</li>
                    <li>Кликните на любой район для детального просмотра</li>
                </ol>
            </div>
        </div>
        <?php
    }
}