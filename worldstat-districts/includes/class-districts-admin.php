<?php
/**
 * Admin interface for Districts extension
 *
 * @package WorldStatDistricts
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSDistricts_Admin {

    public function __construct() {
        add_action( 'admin_menu',    [ $this, 'register_menus' ], 20 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        add_action( 'wp_ajax_wsdistricts_upload',        [ $this, 'ajax_upload' ] );
        add_action( 'wp_ajax_wsdistricts_process_batch',  [ $this, 'ajax_process_batch' ] );
        add_action( 'wp_ajax_wsdistricts_delete_all',     [ $this, 'ajax_delete_all' ] );

        add_action( 'wp_ajax_wsair_prepare',              [ $this, 'ajax_air_prepare' ] );
        add_action( 'wp_ajax_wsair_process_batch',        [ $this, 'ajax_air_process_batch' ] );
        add_action( 'wp_ajax_wsair_run_analysis',         [ $this, 'ajax_air_run_analysis' ] );
        add_action( 'wp_ajax_wsair_delete_all',           [ $this, 'ajax_air_delete_all' ] );

        add_action( 'wp_ajax_wscrime_import',             [ $this, 'ajax_crime_import' ] );
        add_action( 'wp_ajax_wscrime_delete_all',         [ $this, 'ajax_crime_delete_all' ] );
        add_action( 'wp_ajax_wscrime_analyze',            [ $this, 'ajax_crime_analyze' ] );

        add_action( 'wp_ajax_wspedestrian_import',        [ $this, 'ajax_pedestrian_import' ] );
        add_action( 'wp_ajax_wspedestrian_delete_all',    [ $this, 'ajax_pedestrian_delete_all' ] );
        add_action( 'wp_ajax_wspedestrian_analyze',       [ $this, 'ajax_pedestrian_analyze' ] );

        add_filter( 'manage_' . WSDistricts_CPT::SLUG . '_posts_columns',  [ $this, 'columns' ] );
        add_action( 'manage_' . WSDistricts_CPT::SLUG . '_posts_custom_column', [ $this, 'column_content' ], 10, 2 );
        
        add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
        add_action( 'save_post_' . WSDistricts_CPT::SLUG, [ $this, 'save_meta' ] );
        
    }

    public function register_menus(): void {
        add_submenu_page(
            'worldstat',
            'Районы — Импорт',
            'Районы',
            'manage_options',
            'worldstat-districts',
            [ $this, 'render_import_page' ]
        );

        add_submenu_page(
            'worldstat',
            'Список районов',
            'Список районов',
            'manage_options',
            'edit.php?post_type=' . WSDistricts_CPT::SLUG
        );
    }

    public function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'worldstat-districts' ) === false && 
             get_post_type() !== WSDistricts_CPT::SLUG ) {
            return;
        }

        wp_enqueue_style( 'wsdistricts-admin', WSDISTRICTS_URL . 'assets/css/admin.css', [], WSDISTRICTS_VERSION );
        wp_enqueue_script( 'wsdistricts-admin', WSDISTRICTS_URL . 'assets/js/admin.js', [ 'jquery' ], WSDISTRICTS_VERSION, true );

        wp_localize_script( 'wsdistricts-admin', 'wsdistrictsAdmin', [
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'wsdistricts_import' ),
            'batchSize' => 50,
        ] );
    }

    /**
     * Страница импорта с плашкой эргономики
     */
    public function render_import_page(): void {
        global $wpdb;
        
        $total_districts = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
            WSDistricts_CPT::SLUG
        ) );
        
        $table_air = $wpdb->prefix . 'district_air_quality';
        $total_air_records = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_air}" );
        
        ?>
        <div class="wrap wsp-admin-wrap">
            <h1 class="wsp-admin-title">
                <span class="dashicons dashicons-networking"></span>
                Districts Extension — Импорт данных
            </h1>
            
            <?php
            if ( class_exists( 'WSErgo_Extension_Notices' ) ) {
                WSErgo_Extension_Notices::render_moved_notice( 'territory', 'Districts Extension — Импорт данных' );
            } else {
                $ergo_url = admin_url( 'admin.php?page=wsergo-settings#ergo-territory' );
                ?>
                <div class="notice notice-info" style="margin:16px 0 24px;padding:14px 18px;">
                    <p style="margin:0;">
                        <?php esc_html_e( 'Данная часть плагина была перенесена в', 'worldstat-districts' ); ?>
                        <a href="<?php echo esc_url( $ergo_url ); ?>"><strong><?php esc_html_e( 'Эргономичность', 'worldstat-districts' ); ?></strong></a>
                        <?php esc_html_e( '(раздел «Территория (районы)»).', 'worldstat-districts' ); ?>
                    </p>
                </div>
                <?php
            }
            ?>

            <div class="wsdistricts-status" style="background: #f0f6fc; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
                <span>Районов в базе: <strong id="wsdistricts-count"><?php echo $total_districts; ?></strong></span>
                <span style="margin-left: 20px;">📊 Данных о воздухе: <strong><?php echo $total_air_records; ?></strong></span>
            </div>

            <!-- Плашка 1: Импорт районов -->
            <div style="background:#fff;padding:20px;margin-bottom:30px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                <h2>1. Импорт данных районов</h2>
                <div>
                    <input type="file" id="wsdistricts-csv-file" accept=".csv" />
                    <label><input type="checkbox" id="wsdistricts-update" /> Обновлять существующие</label>
                    <button type="button" class="button button-primary wsdistricts-start-import">Начать импорт</button>
                </div>
                <div id="wsdistricts-progress" style="display:none;margin-top:15px;">
                    <div style="height:20px;background:#e5e7eb;border-radius:10px;"><div id="wsdistricts-progress-fill" style="height:100%;width:0%;background:#2271b1;"></div></div>
                    <div id="wsdistricts-stats" style="display:none;margin-top:10px;">
                        Импортировано: <strong id="wsdistricts-stat-imported">0</strong> | 
                        Обновлено: <strong id="wsdistricts-stat-updated">0</strong> | 
                        Пропущено: <strong id="wsdistricts-stat-skipped">0</strong>
                    </div>
                </div>
            </div>

            <!-- Плашка 2: Импорт качества воздуха -->
            <div style="background:#fff;padding:20px;margin-bottom:30px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                <h2>2. Импорт данных качества воздуха</h2>
                <div>
                    <input type="file" id="wsair-csv-file" accept=".csv" />
                    <button type="button" class="button button-primary wsair-start-import">Импортировать качество воздуха</button>
                </div>
                <div id="wsair-progress" style="display:none;margin-top:15px;">
                    <div style="height:20px;background:#e5e7eb;border-radius:10px;"><div id="wsair-progress-fill" style="height:100%;width:0%;background:#10b981;"></div></div>
                    <div id="wsair-stats" style="display:none;margin-top:10px;">
                        Импортировано: <strong id="wsair-stat-imported">0</strong> | 
                        Обновлено: <strong id="wsair-stat-updated">0</strong> | 
                        Пропущено: <strong id="wsair-stat-skipped">0</strong>
                    </div>
                </div>
                <div style="margin-top:15px;">
                    <button type="button" id="wsair-run-analysis" class="button button-secondary">Запустить ML анализ воздуха</button>
                    <span id="wsair-analysis-status" style="margin-left:10px;"></span>
                </div>
            </div>
            
            <!-- Плашка 3: Импорт данных о преступности -->
            <div style="background:#fff;padding:20px;margin-bottom:30px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                <h2><span class="dashicons dashicons-shield"></span> 3. Импорт данных о преступности</h2>
                <p class="description">
                    Загрузите CSV-файл с данными о преступности по районам.<br>
                    <strong>Формат:</strong> OBJECTID, Loc, Borough, Crime_Type, Count, Rate
                </p>
                <div>
                    <input type="file" id="wscrime-csv-file" accept=".csv" />
                    <button type="button" class="button button-primary wscrime-start-import">Импортировать данные о преступности</button>
                </div>
                <div id="wscrime-progress" style="display:none;margin-top:15px;">
                    <div style="height:20px;background:#e5e7eb;border-radius:10px;"><div id="wscrime-progress-fill" style="height:100%;width:0%;background:#ef4444;"></div></div>
                    <div id="wscrime-stats" style="display:none;margin-top:10px;">
                        Импортировано: <strong id="wscrime-stat-imported">0</strong> | 
                        Обновлено: <strong id="wscrime-stat-updated">0</strong> | 
                        Пропущено: <strong id="wscrime-stat-skipped">0</strong>
                    </div>
                    <div id="wscrime-analysis-result" style="display:none;margin-top:10px;padding:10px;background:#f0fdf4;border-radius:5px;"></div>
                </div>
            </div>
            
            <!-- Плашка 4: Импорт данных пешеходной мобильности -->
            <div style="background:#fff;padding:20px;margin-bottom:30px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                <h2><span class="dashicons dashicons-walking"></span> 4. Импорт данных пешеходной мобильности</h2>
                <p class="description">
                    Загрузите CSV-файл с данными о пешеходной мобильности (Pedestrian Mobility Plan).<br>
                    <strong>Формат:</strong> данные из NYC Open Data: BoroName, street, Rank, category, segmentid, nta2020
                </p>
                <div>
                    <input type="file" id="wspedestrian-csv-file" accept=".csv" />
                    <button type="button" class="button button-primary wspedestrian-start-import">Импортировать данные пешеходной мобильности</button>
                </div>
                <div id="wspedestrian-progress" style="display:none;margin-top:15px;">
                    <div style="height:20px;background:#e5e7eb;border-radius:10px;">
                        <div id="wspedestrian-progress-fill" style="height:100%;width:0%;background:#8b5cf6;"></div>
                    </div>
                    <div id="wspedestrian-stats" style="display:none;margin-top:10px;">
                        Импортировано: <strong id="wspedestrian-stat-imported">0</strong> | 
                        Обновлено: <strong id="wspedestrian-stat-updated">0</strong> | 
                        Пропущено: <strong id="wspedestrian-stat-skipped">0</strong>
                    </div>
                    <div id="wspedestrian-analysis-result" style="display:none;margin-top:10px;padding:10px;background:#f0fdf4;border-radius:5px;"></div>
                </div>
            </div>
            <!-- Плашка 5: Регрессионный анализ -->
<div style="background:#fff;padding:20px;margin-bottom:30px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
    <h2><span class="dashicons dashicons-chart-line"></span> 5. Регрессионный анализ</h2>
    <p class="description">
        Анализ взаимосвязей между показателями районов. Модели предсказывают комфорт, безопасность и функциональность
        на основе пешеходной доступности, озеленения, качества воздуха и других факторов.
    </p>
    
    <div style="margin: 15px 0;">
        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wsdistricts_run_regression' ), 'wsdistricts_regression' ) ); ?>" 
           class="button button-primary">
            📊 Запустить регрессионный анализ
        </a>
        <span id="regression-status" style="margin-left: 15px;"></span>
    </div>
    
    <?php
    $regression_results = get_transient( 'wsdistricts_regression_results' );
    if ( $regression_results ) :
    ?>
    <div class="regression-results">
        <h3>Результаты регрессионного анализа</h3>
        <p class="description">Последнее обновление: <?php echo esc_html( $regression_results['timestamp'] ); ?></p>
        
        <div class="regression-grid" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-top: 20px;">
            
            <!-- Комфортность -->
            <div class="regression-card" style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 15px;">
                <h4 style="margin: 0 0 10px 0; color: #10b981;">🌿 Комфортность</h4>
                <?php if ( isset( $regression_results['comfort']['r_squared'] ) ): ?>
                    <p><strong>R² (качество модели):</strong> <?php echo round( $regression_results['comfort']['r_squared'] * 100, 1 ); ?>%</p>
                    <p><strong>RMSE (ошибка):</strong> ±<?php echo $regression_results['comfort']['rmse']; ?> баллов</p>
                    <p><strong>Формула:</strong> <code style="font-size: 11px;"><?php echo esc_html( $regression_results['comfort']['formula'] ); ?></code></p>
                    <p><strong>Выборка:</strong> <?php echo $regression_results['comfort']['sample_size']; ?> районов</p>
                <?php else: ?>
                    <p>Нет данных. Запустите анализ.</p>
                <?php endif; ?>
            </div>
            
            <!-- Безопасность -->
            <div class="regression-card" style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 15px;">
                <h4 style="margin: 0 0 10px 0; color: #8b5cf6;">🛡️ Безопасность</h4>
                <?php if ( isset( $regression_results['safety']['r_squared'] ) ): ?>
                    <p><strong>R² (качество модели):</strong> <?php echo round( $regression_results['safety']['r_squared'] * 100, 1 ); ?>%</p>
                    <p><strong>RMSE (ошибка):</strong> ±<?php echo $regression_results['safety']['rmse']; ?> баллов</p>
                    <p><strong>Формула:</strong> <code style="font-size: 11px;"><?php echo esc_html( $regression_results['safety']['formula'] ); ?></code></p>
                    <p><strong>Выборка:</strong> <?php echo $regression_results['safety']['sample_size']; ?> районов</p>
                <?php else: ?>
                    <p>Нет данных. Запустите анализ.</p>
                <?php endif; ?>
            </div>
            
            <!-- Функциональность -->
            <div class="regression-card" style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 15px;">
                <h4 style="margin: 0 0 10px 0; color: #f59e0b;">⚙️ Функциональность</h4>
                <?php if ( isset( $regression_results['functionality']['r_squared'] ) ): ?>
                    <p><strong>R² (качество модели):</strong> <?php echo round( $regression_results['functionality']['r_squared'] * 100, 1 ); ?>%</p>
                    <p><strong>RMSE (ошибка):</strong> ±<?php echo $regression_results['functionality']['rmse']; ?> баллов</p>
                    <p><strong>Формула:</strong> <code style="font-size: 11px;"><?php echo esc_html( $regression_results['functionality']['formula'] ); ?></code></p>
                    <p><strong>Выборка:</strong> <?php echo $regression_results['functionality']['sample_size']; ?> районов</p>
                <?php else: ?>
                    <p>Нет данных. Запустите анализ.</p>
                <?php endif; ?>
            </div>
        </div>
        
        <div style="margin-top: 20px;">
            <canvas id="regression-chart" style="height: 300px; width: 100%;"></canvas>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if ( isset( $_GET['regression_run'] ) ): ?>
    <div class="notice notice-success"><p>Регрессионный анализ успешно выполнен!</p></div>
    <?php endif; ?>
    </div>
<script>
jQuery(document).ready(function($) {
    // График важности признаков
    <?php if ( $regression_results && isset( $regression_results['comfort']['feature_importance'] ) ): ?>
    var ctx = document.getElementById('regression-chart').getContext('2d');
    var importance = <?php echo json_encode( array_values( $regression_results['comfort']['feature_importance'] ) ); ?>;
    var labels = <?php echo json_encode( array_keys( $regression_results['comfort']['feature_importance'] ) ); ?>;
    
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Важность признака',
                data: importance,
                backgroundColor: ['#10b981', '#8b5cf6', '#f59e0b', '#ef4444']
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: { position: 'top' },
                title: { display: true, text: 'Важность факторов для комфортности' }
            }
        }
    });
    <?php endif; ?>
});
</script>
            <!-- Danger Zone -->
            <div style="border:2px dashed #d63638;background:#fff5f5;padding:20px;border-radius:8px;">
                <button type="button" id="wsdistricts-delete-all" class="button" style="color:#d63638;">Удалить все районы</button>
                <button type="button" id="wsair-delete-all" class="button" style="color:#d63638;margin-left:10px;">Удалить данные о качестве воздуха</button>
                <button type="button" id="wscrime-delete-all" class="button" style="color:#d63638;margin-left:10px;">Удалить данные о преступности</button>
                <button type="button" id="wspedestrian-delete-all" class="button" style="color:#d63638;margin-left:10px;">Удалить данные пешеходной мобильности</button>
            </div>
        </div>

        <style>
        .wsergo-settings-panel {
            transition: all 0.3s ease;
        }
        .wsergo-settings-panel .button {
            transition: all 0.2s ease;
        }
        .wsergo-settings-panel .button:hover {
            transform: translateY(-1px);
            opacity: 0.9;
        }
        </style>
        
        <?php
    }

    // ==================== AJAX ДЛЯ РАЙОНОВ ====================
    
    public function ajax_upload() {
        check_ajax_referer( 'wsdistricts_import', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Access denied.' );
        if ( empty( $_FILES['csv_file'] ) ) wp_send_json_error( 'Файл не загружен.' );
        $importer = new WSDistricts_Importer();
        $result = $importer->prepare( $_FILES['csv_file']['tmp_name'] );
        wp_send_json_success( $result );
    }

    public function ajax_process_batch() {
        check_ajax_referer( 'wsdistricts_import', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Access denied.' );
        $file = sanitize_text_field( $_POST['file'] ?? '' );
        $offset = (int) ( $_POST['offset'] ?? 0 );
        $batch_size = (int) ( $_POST['batch_size'] ?? 50 );
        $update = ! empty( $_POST['update'] );
        if ( ! file_exists( $file ) ) wp_send_json_error( 'Файл не найден.' );
        $importer = new WSDistricts_Importer();
        $result = $importer->process_batch( $file, $offset, $batch_size, $update );
        wp_send_json_success( $result );
    }

    public function ajax_delete_all() {
        check_ajax_referer( 'wsdistricts_import', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Access denied.' );
        $count = WSDistricts_Importer::delete_all();
        $upload_dir = wp_upload_dir();
        $csv = $upload_dir['basedir'] . '/wsdistricts-import.csv';
        if ( file_exists( $csv ) ) unlink( $csv );
        wp_send_json_success( [ 'deleted' => $count ] );
    }

    // ==================== AJAX ДЛЯ КАЧЕСТВА ВОЗДУХА ====================
    
    public function ajax_air_prepare() {
        check_ajax_referer( 'wsdistricts_import', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Access denied.' );
        if ( empty( $_FILES['csv_file'] ) ) wp_send_json_error( 'Файл не загружен.' );
        $result = WSAirQuality_Importer::prepare( $_FILES['csv_file']['tmp_name'] );
        wp_send_json_success( $result );
    }

    public function ajax_air_process_batch() {
        check_ajax_referer( 'wsdistricts_import', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Access denied.' );
        $file = sanitize_text_field( $_POST['file'] ?? '' );
        $offset = (int) ( $_POST['offset'] ?? 0 );
        $batch_size = (int) ( $_POST['batch_size'] ?? 100 );
        if ( ! file_exists( $file ) ) wp_send_json_error( 'Файл не найден.' );
        $result = WSAirQuality_Importer::process_batch( $file, $offset, $batch_size );
        wp_send_json_success( $result );
    }

    public function ajax_air_run_analysis() {
        check_ajax_referer( 'wsdistricts_import', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Access denied.' );
        $result = WSAirQuality_Importer::run_analysis();
        wp_send_json_success( $result );
    }

    public function ajax_air_delete_all() {
        check_ajax_referer( 'wsdistricts_import', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Access denied.' );
        $count = WSAirQuality_Importer::delete_all();
        wp_send_json_success( [ 'deleted' => $count ] );
    }

    // ==================== AJAX ДЛЯ ПРЕСТУПНОСТИ ====================
    
    public function ajax_crime_import() {
        check_ajax_referer( 'wsdistricts_import', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Access denied.' );
        if ( empty( $_FILES['csv_file'] ) ) wp_send_json_error( 'Файл не загружен.' );
        
        require_once WSDISTRICTS_DIR . 'includes/class-crime-importer.php';
        $result = WSCrime_Importer::import_from_csv( $_FILES['csv_file']['tmp_name'] );
        
        wp_send_json_success( $result );
    }

    public function ajax_crime_analyze() {
        check_ajax_referer( 'wsdistricts_import', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Access denied.' );
        
        require_once WSDISTRICTS_DIR . 'includes/class-crime-importer.php';
        $result = WSCrime_Importer::run_crime_analysis();
        
        wp_send_json_success( $result );
    }

    public function ajax_crime_delete_all() {
        check_ajax_referer( 'wsdistricts_import', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Access denied.' );
        
        require_once WSDISTRICTS_DIR . 'includes/class-crime-importer.php';
        $count = WSCrime_Importer::delete_all();
        
        wp_send_json_success( [ 'deleted' => $count ] );
    }

    // ==================== AJAX ДЛЯ ПЕШЕХОДНОЙ МОБИЛЬНОСТИ ====================
    
    public function ajax_pedestrian_import() {
        check_ajax_referer( 'wsdistricts_import', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Access denied.' );
        if ( empty( $_FILES['csv_file'] ) ) wp_send_json_error( 'Файл не загружен.' );
        
        require_once WSDISTRICTS_DIR . 'includes/class-pedestrian-importer.php';
        $result = WSPedestrian_Importer::import_from_csv( $_FILES['csv_file']['tmp_name'] );
        
        wp_send_json_success( $result );
    }

    public function ajax_pedestrian_analyze() {
        check_ajax_referer( 'wsdistricts_import', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Access denied.' );
        
        require_once WSDISTRICTS_DIR . 'includes/class-pedestrian-importer.php';
        $result = WSPedestrian_Importer::run_pedestrian_analysis();
        
        wp_send_json_success( $result );
    }

    public function ajax_pedestrian_delete_all() {
        check_ajax_referer( 'wsdistricts_import', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Access denied.' );
        
        require_once WSDISTRICTS_DIR . 'includes/class-pedestrian-importer.php';
        $count = WSPedestrian_Importer::delete_all();
        
        wp_send_json_success( [ 'deleted' => $count ] );
    }

    // ==================== КОЛОНКИ И МЕТАБОКСЫ ====================

    public function columns( array $cols ): array {
        return [
            'cb'    => $cols['cb'],
            'title' => 'Район',
            'wsdistrict_city' => 'Город',
            'wsdistrict_country' => 'Страна',
            'wsdistrict_population' => 'Население',
            'wsdistrict_area' => 'Площадь (га)',
            'wsdistrict_comfort' => 'Комфорт',
            'wsdistrict_safety' => 'Безопасность',
            'date'  => 'Дата',
        ];
    }

    public function column_content( string $col, int $id ): void {
        switch ( $col ) {
            case 'wsdistrict_city':
                $city_name = get_post_meta( $id, 'wsdistrict_city_name', true );
                $city_id = get_post_meta( $id, 'wsdistrict_city_id', true );
                if ( $city_id && class_exists( 'WSCities_CPT' ) ) {
                    $city_url = get_permalink( $city_id );
                    echo '<a href="' . esc_url( $city_url ) . '">' . esc_html( $city_name ) . '</a>';
                } else {
                    echo esc_html( $city_name );
                }
                break;
                
            case 'wsdistrict_country':
                $country = get_post_meta( $id, 'wsdistrict_country_name', true );
                $iso2 = get_post_meta( $id, 'wsdistrict_country_iso2', true );
                echo esc_html( $country ) . ' (' . esc_html( $iso2 ) . ')';
                break;
                
            case 'wsdistrict_population':
                $pop = (int) get_post_meta( $id, 'wsdistrict_population', true );
                echo number_format( $pop, 0, '', ' ' );
                break;
                
            case 'wsdistrict_area':
                $area = (float) get_post_meta( $id, 'wsdistrict_area', true );
                echo number_format( $area, 1 );
                break;
                
            case 'wsdistrict_comfort':
                $comfort = (float) get_post_meta( $id, 'wsdistrict_comfort_score', true );
                echo $comfort > 0 ? round( $comfort ) . '/100' : '—';
                break;
                
            case 'wsdistrict_safety':
                $safety = (float) get_post_meta( $id, 'wsdistrict_safety_score', true );
                echo $safety > 0 ? round( $safety ) . '/100' : '—';
                break;
        }
    }

    public function add_meta_boxes(): void {
        add_meta_box(
            'wsdistrict_info',
            'Информация о районе',
            [ $this, 'render_info_metabox' ],
            WSDistricts_CPT::SLUG,
            'normal',
            'high'
        );
        
    }

    public function render_info_metabox( $post ): void {
        wp_nonce_field( 'wsdistrict_save_meta', 'wsdistrict_meta_nonce' );
        
        $fields = [
            'wsdistrict_country_iso2' => 'Код страны (ISO2)',
            'wsdistrict_country_name' => 'Название страны',
            'wsdistrict_city_id' => 'ID города',
            'wsdistrict_city_name' => 'Название города',
            'wsdistrict_lat' => 'Широта',
            'wsdistrict_lng' => 'Долгота',
            'wsdistrict_population' => 'Население',
            'wsdistrict_area' => 'Площадь (га)',
            'wsdistrict_density' => 'Плотность (чел/га)',
            'wsdistrict_established' => 'Год основания',
            'wsdistrict_postal_code' => 'Почтовый индекс',
            'wsdistrict_website' => 'Веб-сайт',
        ];
        
        echo '<table class="form-table">';
        foreach ( $fields as $key => $label ) {
            $value = get_post_meta( $post->ID, $key, true );
            echo '<tr>';
            echo '<th><label for="' . $key . '">' . $label . '</label></th>';
            echo '<td><input type="text" id="' . $key . '" name="' . $key . '" value="' . esc_attr( $value ) . '" class="regular-text" /></td>';
            echo '</tr>';
        }
        echo '</table>';
        // Добавьте в метод render_info_metabox после существующих полей:
    $ergo = WSDistricts_CPT::get_ergonomics_index( $post->ID );
        if ( $ergo['score'] > 0 ) {
	?>
	<div style="margin-top: 20px; padding: 15px; background: <?php echo $ergo['color']; ?>10; border-radius: 12px; border-left: 4px solid <?php echo $ergo['color']; ?>;">
		<h3 style="margin: 0 0 10px 0; color: <?php echo $ergo['color']; ?>;">📊 Общий индекс эргономичности</h3>
		<div style="display: flex; align-items: center; gap: 20px;">
			<div style="font-size: 48px; font-weight: bold; color: <?php echo $ergo['color']; ?>;"><?php echo $ergo['score']; ?></div>
			<div>
				<div style="font-size: 18px; font-weight: bold;"><?php echo $ergo['level']; ?></div>
				<div style="font-size: 12px; color: #666;">Из 6 измерений</div>
			</div>
		</div>
		<div style="margin-top: 10px; display: flex; gap: 10px; flex-wrap: wrap;">
			<?php foreach ( $ergo['scores'] as $key => $score ): ?>
				<?php if ( $score > 0 ): ?>
					<span style="background: #f0f0f0; padding: 4px 8px; border-radius: 4px; font-size: 11px;">
						<?php 
						$labels = [
							'comfort' => '🌿',
							'safety' => '🛡️',
							'functionality' => '⚙️',
							'masterability' => '🚶',
							'livability' => '🏡',
							'manageability' => '📊',
						];
						echo $labels[ $key ] ?? ''; ?> <?php echo round( $score ); ?>
					</span>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
	</div>
	<?php
    }
    }
    public function save_meta( int $post_id ): void {
        if ( ! isset( $_POST['wsdistrict_meta_nonce'] ) || 
             ! wp_verify_nonce( $_POST['wsdistrict_meta_nonce'], 'wsdistrict_save_meta' ) ) {
            return;
        }
        
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;
        
        $fields = [
            'wsdistrict_country_iso2',
            'wsdistrict_country_name',
            'wsdistrict_city_id',
            'wsdistrict_city_name',
            'wsdistrict_lat',
            'wsdistrict_lng',
            'wsdistrict_population',
            'wsdistrict_area',
            'wsdistrict_density',
            'wsdistrict_established',
            'wsdistrict_postal_code',
            'wsdistrict_website',
        ];
        
        foreach ( $fields as $key ) {
            if ( isset( $_POST[ $key ] ) ) {
                update_post_meta( $post_id, $key, sanitize_text_field( $_POST[ $key ] ) );
            }
        }
        
        $pop = (int) get_post_meta( $post_id, 'wsdistrict_population', true );
        $area = (float) get_post_meta( $post_id, 'wsdistrict_area', true );
        $density = $area > 0 ? round( $pop / $area ) : 0;
        update_post_meta( $post_id, 'wsdistrict_density', $density );
    }
}