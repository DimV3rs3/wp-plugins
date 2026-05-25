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
        
        // Обработчик сохранения настроек эргономики
        add_action( 'admin_post_wsergo_toggle_settings', [ $this, 'handle_ergo_settings' ] );
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
        
        // Получаем статус эргономики
        $ergo_enabled = get_option( 'wsergo_neural_active', true );
        $ergo_auto_calc = get_option( 'wsergo_auto_calculate', true );
        $last_ergo_update = get_option( 'wsergo_last_update', '' );
        ?>
        <div class="wrap wsp-admin-wrap">
            <h1 class="wsp-admin-title">
                <span class="dashicons dashicons-networking"></span>
                Districts Extension — Импорт данных
            </h1>
            
            <!-- ============================================ -->
            <!-- ПЛАШКА 0: НАСТРОЙКИ ЭРГОНОМИКИ -->
            <!-- ============================================ -->
            <div class="wsergo-settings-panel" style="background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); color: white; padding: 25px; border-radius: 12px; margin-bottom: 30px;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                    <div>
                        <h2 style="color: white; margin: 0 0 10px 0;">
                            <span class="dashicons dashicons-chart-area" style="color: #10b981;"></span>
                            🧠 Нейро-эргономика районов
                        </h2>
                        <p style="margin: 0; opacity: 0.8; font-size: 14px;">
                            Автоматический расчет 6 измерений эргономичности: комфортность, безопасность, 
                            функциональность, освояемость, обитаемость, управляемость на основе ML моделей
                        </p>
                    </div>
                    <div>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline-block;">
                            <input type="hidden" name="action" value="wsergo_toggle_settings">
                            <?php wp_nonce_field( 'wsergo_toggle' ); ?>
                            <input type="hidden" name="ergo_enabled" value="<?php echo $ergo_enabled ? '0' : '1'; ?>">
                            <button type="submit" class="button" style="background: <?php echo $ergo_enabled ? '#ef4444' : '#10b981'; ?>; border: none; color: white; padding: 8px 20px; border-radius: 20px; cursor: pointer;">
                                <?php if ( $ergo_enabled ): ?>
                                    🔴 Отключить эргономику
                                <?php else: ?>
                                    🟢 Включить эргономику
                                <?php endif; ?>
                            </button>
                        </form>
                    </div>
                </div>
                
                <?php if ( $ergo_enabled ): ?>
                <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid rgba(255,255,255,0.2);">
                    <div style="display: flex; gap: 30px; flex-wrap: wrap; justify-content: space-between; align-items: center;">
                        <div style="display: flex; gap: 20px;">
                            <div>
                                <div style="font-size: 28px; font-weight: bold; color: #10b981;">6</div>
                                <div style="font-size: 12px; opacity: 0.7;">Измерений</div>
                            </div>
                            <div>
                                <div style="font-size: 28px; font-weight: bold; color: #f59e0b;">12</div>
                                <div style="font-size: 12px; opacity: 0.7;">Признаков</div>
                            </div>
                            <div>
                                <div style="font-size: 28px; font-weight: bold; color: #8b5cf6;">64-32-6</div>
                                <div style="font-size: 12px; opacity: 0.7;">Архитектура</div>
                            </div>
                        </div>
                        
                        <div>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline-block;">
                                <input type="hidden" name="action" value="wsergo_toggle_settings">
                                <?php wp_nonce_field( 'wsergo_toggle' ); ?>
                                <input type="hidden" name="recalc_ergo" value="1">
                                <button type="submit" class="button" style="background: #3b82f6; border: none; color: white; padding: 8px 16px; border-radius: 6px; cursor: pointer;">
                                    🔄 Пересчитать эргономику для всех районов
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <?php if ( $last_ergo_update ): ?>
                    <div style="margin-top: 15px; font-size: 12px; opacity: 0.6;">
                        Последнее обновление: <?php echo esc_html( $last_ergo_update ); ?>
                    </div>
                    <?php endif; ?>
                    
                    <div style="margin-top: 15px; background: rgba(255,255,255,0.1); border-radius: 8px; padding: 12px;">
                        <div style="display: flex; gap: 20px; flex-wrap: wrap; font-size: 13px;">
                            <span>✅ Комфортность — качество воздуха, зелень, шум</span>
                            <span>✅ Безопасность — преступность, ДТП, экология</span>
                            <span>✅ Функциональность — доступность, смешение, связность</span>
                            <span>✅ Освояемость — навигация, читаемость, легендирование</span>
                            <span>✅ Обитаемость — интегральный показатель</span>
                            <span>✅ Управляемость — реакция служб, мониторинг</span>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid rgba(255,255,255,0.2);">
                    <div style="background: rgba(0,0,0,0.3); border-radius: 8px; padding: 15px; text-align: center;">
                        <p style="margin: 0; font-size: 14px;">
                            ⚠️ Расчёт эргономичности отключен. Включите его для автоматического анализа районов 
                            на основе данных о качестве воздуха, преступности и пешеходной мобильности.
                        </p>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="wsdistricts-status" style="background: #f0f6fc; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
                <span>Районов в базе: <strong id="wsdistricts-count"><?php echo $total_districts; ?></strong></span>
                <span style="margin-left: 20px;">📊 Данных о воздухе: <strong><?php echo $total_air_records; ?></strong></span>
                <?php if ( class_exists( 'WSErgo_District_Bridge' ) && $ergo_enabled ): ?>
                <span style="margin-left: 20px;">🧠 Эргономика: <strong style="color: #10b981;">Активна</strong></span>
                <?php endif; ?>
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

    // ==================== ОБРАБОТЧИК НАСТРОЕК ЭРГОНОМИКИ ====================
    
    public function handle_ergo_settings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied' );
        }
        check_admin_referer( 'wsergo_toggle' );
        
        if ( isset( $_POST['ergo_enabled'] ) ) {
            $new_status = (int) $_POST['ergo_enabled'];
            update_option( 'wsergo_neural_active', $new_status );
            
            if ( $new_status && class_exists( 'WSErgo_District_Bridge' ) ) {
                update_option( 'wsergo_last_update', current_time( 'mysql' ) );
            }
        }
        
        if ( isset( $_POST['recalc_ergo'] ) && class_exists( 'WSErgo_District_Bridge' ) ) {
            WSErgo_District_Bridge::update_all_districts();
            update_option( 'wsergo_last_update', current_time( 'mysql' ) );
        }
        
        wp_redirect( wp_get_referer() );
        exit;
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
        
        // Добавляем метабокс эргономики если плагин активен
        if ( class_exists( 'WSErgo_District_Bridge' ) && get_option( 'wsergo_neural_active', true ) ) {
            add_meta_box(
                'wsdistrict_ergonomics',
                '🧠 Нейро-эргономика района',
                [ $this, 'render_ergo_metabox' ],
                WSDistricts_CPT::SLUG,
                'side',
                'high'
            );
        }
    }
    
    public function render_ergo_metabox( $post ): void {
        $comfort = (float) get_post_meta( $post->ID, 'wsdistrict_comfort_score', true );
        $safety = (float) get_post_meta( $post->ID, 'wsdistrict_safety_score', true );
        $functionality = (float) get_post_meta( $post->ID, 'wsdistrict_functionality_score', true );
        $walkability = (float) get_post_meta( $post->ID, 'wsdistrict_walkability_score', true );
        $air_class = get_post_meta( $post->ID, 'wsdistrict_air_quality_class', true );
        $crime_level = get_post_meta( $post->ID, 'wsdistrict_crime_level', true );
        
        $air_color = '#6b7280';
        $air_text = 'Нет данных';
        if ( $air_class == 'Good' ) {
            $air_color = '#10b981';
            $air_text = 'Хорошее';
        } elseif ( $air_class == 'Moderate' ) {
            $air_color = '#f59e0b';
            $air_text = 'Среднее';
        } elseif ( $air_class == 'Poor' ) {
            $air_color = '#ef4444';
            $air_text = 'Плохое';
        }
        
        $crime_color = '#6b7280';
        $crime_text = 'Нет данных';
        if ( $crime_level == 'Low' ) {
            $crime_color = '#10b981';
            $crime_text = 'Низкий';
        } elseif ( $crime_level == 'Medium' ) {
            $crime_color = '#f59e0b';
            $crime_text = 'Средний';
        } elseif ( $crime_level == 'High' ) {
            $crime_color = '#ef4444';
            $crime_text = 'Высокий';
        }
        ?>
        <div style="background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%); padding: 15px; border-radius: 8px;">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 15px;">
                <div style="text-align: center;">
                    <div style="font-size: 11px; color: #666;">Качество воздуха</div>
                    <div style="font-size: 18px; font-weight: bold; color: <?php echo $air_color; ?>;"><?php echo $air_text; ?></div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: 11px; color: #666;">Преступность</div>
                    <div style="font-size: 18px; font-weight: bold; color: <?php echo $crime_color; ?>;"><?php echo $crime_text; ?></div>
                </div>
            </div>
            
            <div style="margin-bottom: 10px;">
                <div style="font-size: 11px; color: #666;">Комфортность</div>
                <div style="height: 6px; background: #e5e7eb; border-radius: 3px; margin-top: 4px;">
                    <div style="width: <?php echo $comfort; ?>%; height: 100%; background: #10b981; border-radius: 3px;"></div>
                </div>
                <div style="text-align: right; font-size: 12px; font-weight: bold;"><?php echo round( $comfort ); ?>/100</div>
            </div>
            
            <div style="margin-bottom: 10px;">
                <div style="font-size: 11px; color: #666;">Безопасность</div>
                <div style="height: 6px; background: #e5e7eb; border-radius: 3px; margin-top: 4px;">
                    <div style="width: <?php echo $safety; ?>%; height: 100%; background: #8b5cf6; border-radius: 3px;"></div>
                </div>
                <div style="text-align: right; font-size: 12px; font-weight: bold;"><?php echo round( $safety ); ?>/100</div>
            </div>
            
            <div style="margin-bottom: 10px;">
                <div style="font-size: 11px; color: #666;">Функциональность</div>
                <div style="height: 6px; background: #e5e7eb; border-radius: 3px; margin-top: 4px;">
                    <div style="width: <?php echo $functionality; ?>%; height: 100%; background: #f59e0b; border-radius: 3px;"></div>
                </div>
                <div style="text-align: right; font-size: 12px; font-weight: bold;"><?php echo round( $functionality ); ?>/100</div>
            </div>
            
            <div style="margin-top: 12px; padding-top: 10px; border-top: 1px solid rgba(0,0,0,0.1);">
                <div style="font-size: 11px; color: #666;">Пешеходная доступность</div>
                <div style="font-size: 16px; font-weight: bold; color: #8b5cf6;"><?php echo round( $walkability ); ?>/100</div>
            </div>
            
            <div style="margin-top: 10px; font-size: 10px; color: #666; text-align: center;">
                🤖 Нейросетевая модель v2.0
            </div>
        </div>
        <?php
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