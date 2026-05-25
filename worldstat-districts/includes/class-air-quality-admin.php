<?php
/**
 * Air Quality Admin Interface
 *
 * @package WorldStatDistricts
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSAirQuality_Admin {

    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menus' ], 30 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_wsair_upload', [ $this, 'ajax_upload' ] );
        add_action( 'wp_ajax_wsair_process', [ $this, 'ajax_process' ] );
        add_action( 'wp_ajax_wsair_run_analysis', [ $this, 'ajax_run_analysis' ] );
    }

    /**
     * Add admin menus
     */
    public function add_menus() {
        add_submenu_page(
            'worldstat',
            'Качество воздуха',
            'Качество воздуха',
            'manage_options',
            'worldstat-air-quality',
            [ $this, 'render_page' ]
        );
    }

    /**
     * Enqueue assets
     */
    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'worldstat-air-quality' ) === false ) {
            return;
        }

        wp_enqueue_style( 'wsair-admin', WSDISTRICTS_URL . 'assets/css/air-quality.css', [], WSDISTRICTS_VERSION );
        wp_enqueue_script( 'wsair-admin', WSDISTRICTS_URL . 'assets/js/air-quality.js', [ 'jquery' ], WSDISTRICTS_VERSION, true );
        wp_enqueue_script( 'chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js', [], '4.4.0', true );

        wp_localize_script( 'wsair-admin', 'wsairAdmin', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'wsair_import' ),
        ] );
    }

    /**
     * Render admin page
     */
    public function render_page() {
        global $wpdb;
        
        $table_air = $wpdb->prefix . 'district_air_quality';
        $table_results = $wpdb->prefix . 'district_ml_results';
        
        // Get statistics
        $total_records = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_air}" );
        $total_districts = $wpdb->get_var( "SELECT COUNT(DISTINCT district_id) FROM {$table_air}" );
        
        // Get latest data
        $latest_data = $wpdb->get_results( "
            SELECT aq.*, p.post_title as district_name
            FROM {$table_air} aq
            LEFT JOIN {$wpdb->posts} p ON p.ID = aq.district_id
            ORDER BY aq.id DESC
            LIMIT 20
        " );
        
        // Get ML results
        $ml_results = $wpdb->get_results( "
            SELECT ml.*, p.post_title as district_name
            FROM {$table_results} ml
            LEFT JOIN {$wpdb->posts} p ON p.ID = ml.district_id
            ORDER BY ml.comfort_score DESC
        " );
        
        ?>
        <div class="wrap">
            <h1>Качество воздуха в районах Нью-Йорка</h1>
            
            <!-- Stats Cards -->
            <div class="air-stats-grid" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin: 20px 0;">
                <div class="stat-card">
                    <div class="stat-value"><?php echo $total_records; ?></div>
                    <div class="stat-label">Всего записей</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $total_districts; ?></div>
                    <div class="stat-label">Районов с данными</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" id="avg-comfort">--</div>
                    <div class="stat-label">Средний комфорт</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" id="avg-air-quality">--</div>
                    <div class="stat-label">Качество воздуха</div>
                </div>
            </div>
            
            <!-- Import Section -->
            <div class="card" style="margin-bottom: 30px;">
                <h2>Импорт данных качества воздуха</h2>
                <p>Загрузите CSV файл с данными качества воздуха из NYC Open Data</p>
                
                <div class="import-form">
                    <input type="file" id="air-quality-file" accept=".csv" />
                    <button type="button" id="import-air-quality" class="button button-primary">
                        Импортировать данные
                    </button>
                </div>
                
                <div id="import-progress" style="display: none; margin-top: 20px;">
                    <div class="progress-bar">
                        <div class="progress-fill"></div>
                    </div>
                    <div class="progress-text">Импорт...</div>
                </div>
                
                <div id="import-result" style="display: none; margin-top: 20px;"></div>
            </div>
            
            <!-- ML Analysis Section -->
            <div class="card" style="margin-bottom: 30px;">
                <h2>ML Анализ качества воздуха</h2>
                <p>Запустить кластеризацию, классификацию и регрессию для оценки комфортности районов</p>
                
                <button type="button" id="run-ml-analysis" class="button button-primary">
                    Запустить анализ
                </button>
                
                <div id="analysis-result" style="display: none; margin-top: 20px;"></div>
            </div>
            
            <!-- Latest Data Table -->
            <div class="card">
                <h2>Последние импортированные данные</h2>
                <div style="overflow-x: auto;">
                    <table class="wp-list-table widefat striped">
                        <thead>
                            <tr>
                                <th>Район</th>
                                <th>Загрязнитель</th>
                                <th>Значение</th>
                                <th>Период</th>
                                <th>Дата импорта</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $latest_data as $row ): ?>
                                <tr>
                                    <td><?php echo esc_html( $row->district_name ?: 'ID: ' . $row->district_id ); ?></td>
                                    <td><?php echo strtoupper( $row->pollutant ); ?></td>
                                    <td><?php echo number_format( $row->value, 2 ); ?></td>
                                    <td><?php echo esc_html( $row->period ); ?></td>
                                    <td><?php echo esc_html( $row->created_at ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            
                            <?php if ( empty( $latest_data ) ): ?>
                                <tr>
                                    <td colspan="5" style="text-align: center;">Нет данных. Загрузите CSV файл.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- ML Results Table -->
            <?php if ( ! empty( $ml_results ) ): ?>
                <div class="card" style="margin-top: 30px;">
                    <h2>Результаты ML анализа</h2>
                    <div style="overflow-x: auto;">
                        <table class="wp-list-table widefat striped">
                            <thead>
                                <tr>
                                    <th>Район</th>
                                    <th>Кластер</th>
                                    <th>Классификация</th>
                                    <th>Комфорт</th>
                                    <th>Безопасность</th>
                                    <th>Функциональность</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $ml_results as $row ): ?>
                                    <tr>
                                        <td><?php echo esc_html( $row->district_name ?: 'ID: ' . $row->district_id ); ?></td>
                                        <td>
                                            <?php 
                                            $cluster_class = '';
                                            $cluster_label = '';
                                            if ( $row->cluster == 0 ) {
                                                $cluster_class = 'cluster-good';
                                                $cluster_label = 'Низкое загрязнение';
                                            } elseif ( $row->cluster == 1 ) {
                                                $cluster_class = 'cluster-medium';
                                                $cluster_label = 'Среднее загрязнение';
                                            } else {
                                                $cluster_class = 'cluster-bad';
                                                $cluster_label = 'Высокое загрязнение';
                                            }
                                            ?>
                                            <span class="cluster-badge <?php echo $cluster_class; ?>"><?php echo $cluster_label; ?></span>
                                        </td>
                                        <td>
                                            <?php 
                                            $class_class = '';
                                            if ( $row->classification == 'Good' ) $class_class = 'good';
                                            elseif ( $row->classification == 'Moderate' ) $class_class = 'moderate';
                                            else $class_class = 'poor';
                                            ?>
                                            <span class="classification-badge <?php echo $class_class; ?>"><?php echo esc_html( $row->classification ); ?></span>
                                        </td>
                                        <td><?php echo round( $row->comfort_score ); ?>/100</td>
                                        <td><?php echo round( $row->safety_score ); ?>/100</td>
                                        <td><?php echo round( $row->functionality_score ); ?>/100</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <style>
        .stat-card {
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            text-align: center;
        }
        .stat-value {
            font-size: 32px;
            font-weight: bold;
            color: #2271b1;
        }
        .stat-label {
            color: #666;
            margin-top: 5px;
        }
        .card {
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .progress-bar {
            height: 20px;
            background: #e5e7eb;
            border-radius: 10px;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            background: #2271b1;
            width: 0%;
            transition: width 0.3s;
        }
        .cluster-badge, .classification-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }
        .cluster-good, .good { background: #10b98120; color: #10b981; }
        .cluster-medium, .moderate { background: #f59e0b20; color: #f59e0b; }
        .cluster-bad, .poor { background: #ef444420; color: #ef4444; }
        </style>
        
        <?php
    }

    /**
     * AJAX upload handler
     */
    public function ajax_upload() {
        check_ajax_referer( 'wsair_import', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Access denied' );
        }
        
        if ( empty( $_FILES['csv_file'] ) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( 'File upload failed' );
        }
        
        $importer = new WSAirQuality_Importer();
        $result = $importer->import_from_csv( $_FILES['csv_file']['tmp_name'] );
        
        if ( isset( $result['error'] ) ) {
            wp_send_json_error( $result['error'] );
        }
        
        wp_send_json_success( $result );
    }

    /**
     * AJAX process handler
     */
    public function ajax_process() {
        check_ajax_referer( 'wsair_import', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Access denied' );
        }
        
        // Process in batches
        $offset = intval( $_POST['offset'] ?? 0 );
        $batch_size = intval( $_POST['batch_size'] ?? 100 );
        
        // For now, just return success
        wp_send_json_success( [
            'processed' => $batch_size,
            'total' => 0,
            'offset' => $offset + $batch_size,
            'completed' => false,
        ] );
    }

    /**
     * AJAX run ML analysis
     */
    public function ajax_run_analysis() {
        check_ajax_referer( 'wsair_import', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Access denied' );
        }
        
        $result = WSAirQuality_Importer::run_analysis();
        
        wp_send_json_success( $result );
    }
}