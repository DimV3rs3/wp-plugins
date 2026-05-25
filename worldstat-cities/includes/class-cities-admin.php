<?php
/**
 * Admin interface — import page, city list, AJAX handlers.
 *
 * @package WorldStatCities
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSCities_Admin {

    public function __construct() {
        // Priority 20 — after platform menu (priority 5)
        add_action( 'admin_menu',    [ $this, 'register_menus' ], 20 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // AJAX handlers — main CSV
        add_action( 'wp_ajax_wscities_upload',        [ $this, 'ajax_upload' ] );
        add_action( 'wp_ajax_wscities_process_batch',  [ $this, 'ajax_process_batch' ] );
        add_action( 'wp_ajax_wscities_scan_conflicts', [ $this, 'ajax_scan_conflicts' ] );
        add_action( 'wp_ajax_wscities_delete_all',     [ $this, 'ajax_delete_all' ] );
        add_action( 'wp_ajax_wscities_merge_duplicates', [ $this, 'ajax_merge_duplicates' ] );
        add_action( 'wp_ajax_wscities_recalc_ergonomics', [ $this, 'ajax_recalc_ergonomics' ] );

        // AJAX handlers — Blocks & Roads Table 1
        add_action( 'wp_ajax_wscities_upload_br1',        [ $this, 'ajax_upload_br1' ] );
        add_action( 'wp_ajax_wscities_process_batch_br1',  [ $this, 'ajax_process_batch_br1' ] );

        // AJAX handlers — Blocks & Roads Table 2
        add_action( 'wp_ajax_wscities_upload_br2',        [ $this, 'ajax_upload_br2' ] );
        add_action( 'wp_ajax_wscities_upload_br2_chunk',  [ $this, 'ajax_upload_br2_chunk' ] );
        add_action( 'wp_ajax_wscities_process_batch_br2',  [ $this, 'ajax_process_batch_br2' ] );

        // AJAX handlers — Greenspace
        add_action( 'wp_ajax_wscities_upload_greenspace',        [ $this, 'ajax_upload_greenspace' ] );
        add_action( 'wp_ajax_wscities_process_batch_greenspace', [ $this, 'ajax_process_batch_greenspace' ] );

        // Custom columns
        add_filter( 'manage_' . WSCities_CPT::SLUG . '_posts_columns',  [ $this, 'columns' ] );
        add_action( 'manage_' . WSCities_CPT::SLUG . '_posts_custom_column', [ $this, 'column_content' ], 10, 2 );
    }

    public function register_menus(): void {
        // Sub-menu under World Statistics
        add_submenu_page(
            'worldstat',
            'Города — Импорт',
            'Города',
            'manage_options',
            'worldstat-cities',
            [ $this, 'render_import_page' ]
        );

        // Cities list
        add_submenu_page(
            'worldstat',
            'Список городов',
            'Список городов',
            'manage_options',
            'edit.php?post_type=' . WSCities_CPT::SLUG
        );
    }

    public function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'worldstat-cities' ) === false ) return;

        wp_enqueue_style( 'wscities-admin', WSCITIES_URL . 'assets/css/admin.css', [], WSCITIES_VERSION );
        wp_enqueue_script( 'wscities-admin', WSCITIES_URL . 'assets/js/admin.js', [ 'jquery' ], WSCITIES_VERSION, true );

        wp_localize_script( 'wscities-admin', 'wscitiesAdmin', [
            'ajaxUrl'              => admin_url( 'admin-ajax.php' ),
            'nonce'                => wp_create_nonce( 'wscities_import' ),
            'batchSize'            => 50,
            /** Пакет для массового пересчёта эргономики (городов за один AJAX-запрос). */
            'ergoRecalcBatchSize'  => (int) apply_filters( 'wscities_recalc_ergonomics_batch_size', 100 ),
        ] );
    }

    /* ═══════════════════════════════════════════════════════
       IMPORT PAGE
    ═══════════════════════════════════════════════════════ */

    public function render_import_page(): void {
        global $wpdb;
        $total_cities = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
            WSCities_CPT::SLUG
        ) );

        // Count cities with Blocks & Roads data
        $br1_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE p.post_type = %s AND p.post_status = 'publish'
               AND pm.meta_key = 'wscity_blocks_roads' AND pm.meta_value != ''",
            WSCities_CPT::SLUG
        ) );
        $br2_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE p.post_type = %s AND p.post_status = 'publish'
               AND pm.meta_key = 'wscity_blocks_roads_hist' AND pm.meta_value != ''",
            WSCities_CPT::SLUG
        ) );
        ?>
        <div class="wrap wsp-admin-wrap">
            <h1 class="wsp-admin-title">
                <span class="dashicons dashicons-building"></span>
                Cities Extension — Импорт данных
            </h1>

            <!-- Current status -->
            <div class="wscities-status">
                <span>Городов в базе: <strong id="wscities-count"><?php echo $total_cities; ?></strong></span>
                <span class="wscities-status-sep">|</span>
                <span>С данными Blocks &amp; Roads: <strong><?php echo $br1_count; ?></strong></span>
                <span class="wscities-status-sep">|</span>
                <span>С историч. данными: <strong><?php echo $br2_count; ?></strong></span>
            </div>

            <!-- ═══════ 1. Main CSV Upload ═══════ -->
            <div class="wsp-admin-section">
                <h2><span class="dashicons dashicons-database-import"></span> 1. Основные данные (combined_atlas_cities.csv)</h2>
                <p class="description">
                    Загрузите файл <code>combined_atlas_cities.csv</code> (или CSV-экспорт). Формат: 81 колонка, 2 строки заголовков, затем данные городов.
                </p>

                <?php $this->render_upload_form( 'main', 'Начать импорт' ); ?>
            </div>

            <!-- ═══════ 2. Blocks & Roads Table 1 ═══════ -->
            <div class="wsp-admin-section">
                <h2><span class="dashicons dashicons-layout"></span> 2. Кварталы и дороги — 200 городов (db-worldua.csv)</h2>
                <p class="description">
                    Загрузите файл <code>db-worldua.csv</code> (или CSV-экспорт).<br>
                    200 городов, 2 периода (до 1990 и 1990–2015). Метрики: доля дорог, ширина, плотность артериальных дорог, 
                    размер кварталов, перекрёстки, пешеходная доступность, планировка территорий.<br>
                    <strong>Внимание:</strong> города должны быть сначала загружены через основные данные (п.1).<br>
                    Данные для карточек «дороги / кварталы» сохраняются в метаполе города <code>wscity_blocks_roads</code> (JSON, ≥52 колонки в строке CSV).
                    Если город уже есть в базе и галочка «Обновлять существующие» выключена, JSON Blocks &amp; Roads всё равно будет записан, если для этого города он ещё пустой.
                </p>

                <?php $this->render_upload_form( 'br1', 'Импорт Blocks & Roads T1' ); ?>
            </div>

            <!-- ═══════ 3. Blocks & Roads Table 2 ═══════ -->
            <div class="wsp-admin-section">
                <h2><span class="dashicons dashicons-clock"></span> 3. Кварталы и дороги — 30 городов, историческая динамика (GHS_WUP_MTUC_MT_GLOBE_R2025A_v1_0.csv)</h2>
                <p class="description">
                    Загрузите файл <code>GHS_WUP_MTUC_MT_GLOBE_R2025A_v1_0.csv</code> (или CSV-экспорт).<br>
                    30 городов с 5 историческими периодами (от начала XX века до наших дней).
                    Те же метрики, что и в Table 1, но с более детальной исторической разбивкой.<br>
                    <strong>Внимание:</strong> города должны быть сначала загружены через основные данные (п.1).
                </p>

                <?php $this->render_upload_form( 'br2', 'Импорт Blocks & Roads T2' ); ?>
            </div>

            <!-- ═══════ 4. Greenspace ═══════ -->
            <div class="wsp-admin-section">
                <h2><span class="dashicons dashicons-leaf"></span> 4. Зеленые зоны (greenspace.csv)</h2>
                <p class="description">
                    Загрузите Excel-файл с данными по зеленым зонам. Он будет преобразован в CSV на стороне браузера и импортирован в метаданные городов.<br>
                    В карточках будет показано вычисленное среднее по городу для метрик, которые найдутся в заголовках файла (часть — позже).
                </p>

                <?php $this->render_upload_form( 'greenspace', 'Импорт GreenSpace' ); ?>
            </div>

            <!-- Danger zone -->
            <div class="wsp-admin-section wsp-danger-zone">
                <h2 style="color:#d63638">Удаление данных</h2>
                <p class="description">Удалить все импортированные города из базы.</p>
                <button type="button" id="wscities-delete-all" class="button" style="color:#d63638;border-color:#d63638">
                    Удалить все города (<?php echo $total_cities; ?>)
                </button>
                <hr style="margin:16px 0;border:none;border-top:1px solid #f1c4c4;">
                <p class="description">
                    Объединить дубли городов в пределах одной страны (например, <code>Saint Petersburg</code> и <code>St. Petersburg</code>).
                    Остаётся одна запись, метаданные переносятся.
                </p>
                <button type="button" id="wscities-merge-duplicates" class="button" style="color:#9a3412;border-color:#9a3412">
                    Объединить дубли городов
                </button>
            </div>

            <div class="wsp-admin-section">
                <h2><span class="dashicons dashicons-calculator"></span> Пересчёт эргономики</h2>
                <p class="description">
                    Массовый пересчёт по логике плагина <strong>WorldStat Ergonomics</strong>: листовой индекс E в мета <code>wsergo_city_leaf_index</code>
                    (карта полей и DSL при наличии сопоставления, иначе fallback по мета <code>wscity_*</code>), плюс подиндексы по шести измерениям для таблиц и карточек.
                    Нужны активные «Города» и «Ergonomics», включён расчёт по данным Cities в настройках эргономики.
                </p>
                <?php if ( class_exists( 'WSErgo_City_Bridge' ) ) : ?>
                    <button type="button" id="wscities-recalc-ergonomics" class="button button-secondary">
                        Пересчитать эргономику всех городов
                    </button>
                <?php else : ?>
                    <button type="button" class="button" disabled>
                        Плагин эргономики не активен
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <div id="wscities-conflict-modal" class="wscities-modal" style="display:none;" aria-hidden="true">
            <div class="wscities-modal__backdrop"></div>
            <div class="wscities-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="wscities-conflict-modal-title">
                <h2 id="wscities-conflict-modal-title">Изменения в данных городов</h2>
                <p class="wscities-modal__intro">
                    В загружаемом файле есть значения, отличные от данных в базе (основной CSV, Blocks &amp; Roads, GHS/WUP, Greenspace).
                    Для каждого поля выберите: оставить старое значение или заменить новым из файла.
                </p>
                <p id="wscities-conflict-truncated" class="description" style="display:none;color:#9a3412;"></p>
                <div class="wscities-modal__toolbar">
                    <button type="button" class="button" id="wscities-conflict-keep-all">Оставить все старые</button>
                    <button type="button" class="button" id="wscities-conflict-replace-all">Заменить все новыми</button>
                </div>
                <div id="wscities-conflict-list" class="wscities-conflict-list"></div>
                <div class="wscities-modal__actions">
                    <button type="button" class="button button-primary" id="wscities-conflict-apply">Продолжить импорт</button>
                    <button type="button" class="button" id="wscities-conflict-cancel">Отмена</button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render a reusable upload form section.
     */
    private function render_upload_form( string $type, string $button_label ): void {
        $prefix = 'wscities-' . $type;
        ?>
        <div class="wscities-upload-form">
            <input type="file" id="<?php echo $prefix; ?>-csv-file" accept=".csv" />
            <label>
                <input type="checkbox" id="<?php echo $prefix; ?>-update" value="1" />
                Обновлять существующие
            </label>
            <button type="button" class="button button-primary wscities-start-import" data-type="<?php echo esc_attr( $type ); ?>">
                <span class="dashicons dashicons-upload"></span> <?php echo esc_html( $button_label ); ?>
            </button>
        </div>

        <!-- Progress -->
        <div id="<?php echo $prefix; ?>-progress" style="display:none">
            <div class="wscities-progress-bar">
                <div class="wscities-progress-fill" id="<?php echo $prefix; ?>-progress-fill"></div>
            </div>
            <div class="wscities-progress-stats">
                <span id="<?php echo $prefix; ?>-progress-text">Подготовка...</span>
                <span id="<?php echo $prefix; ?>-progress-pct">0%</span>
            </div>
            <div class="wscities-stats" id="<?php echo $prefix; ?>-stats" style="display:none">
                <span class="stat-imported">Импортировано: <strong id="<?php echo $prefix; ?>-stat-imported">0</strong></span>
                <span class="stat-updated">Обновлено: <strong id="<?php echo $prefix; ?>-stat-updated">0</strong></span>
                <span class="stat-skipped">Пропущено: <strong id="<?php echo $prefix; ?>-stat-skipped">0</strong></span>
            </div>
            <div class="wscities-errors" id="<?php echo $prefix; ?>-errors" style="display:none">
                <h4>Ошибки:</h4>
                <ul id="<?php echo $prefix; ?>-error-list"></ul>
            </div>
        </div>

        <!-- Result -->
        <div id="<?php echo $prefix; ?>-result" style="display:none" class="notice notice-success">
            <p id="<?php echo $prefix; ?>-result-text"></p>
        </div>
        <?php
    }

    /* ═══════════════════════════════════════════════════════
       AJAX HANDLERS
    ═══════════════════════════════════════════════════════ */

    public function ajax_upload(): void {
        check_ajax_referer( 'wscities_import', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Access denied.' );
        }

        if ( empty( $_FILES['csv_file'] ) ) {
            wp_send_json_error( 'Файл не передан в запросе.' );
        }
        if ( (int) $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( $this->get_upload_error_message( (int) $_FILES['csv_file']['error'] ) );
        }

        $importer = new WSCities_Importer();
        $result   = $importer->prepare( $_FILES['csv_file']['tmp_name'] );

        if ( isset( $result['error'] ) ) {
            wp_send_json_error( $result['error'] );
        }

        wp_send_json_success( $result );
    }

    public function ajax_process_batch(): void {
        check_ajax_referer( 'wscities_import', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Access denied.' );
        }

        $file       = sanitize_text_field( $_POST['file'] ?? '' );
        $offset     = (int) ( $_POST['offset'] ?? 0 );
        $batch_size = (int) ( $_POST['batch_size'] ?? 50 );
        $update     = ! empty( $_POST['update'] );

        if ( ! $file || ! file_exists( $file ) ) {
            wp_send_json_error( 'Файл не найден.' );
        }

        $resolutions = $this->parse_import_resolutions( $_POST['resolutions'] ?? '' );

        $importer = new WSCities_Importer();
        $result   = $importer->process_batch( $file, $offset, $batch_size, $update, $resolutions );

        if ( isset( $result['error'] ) ) {
            wp_send_json_error( $result['error'] );
        }

        wp_send_json_success( $result );
    }

    public function ajax_scan_conflicts(): void {
        check_ajax_referer( 'wscities_import', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Access denied.' );
        }

        $file = sanitize_text_field( $_POST['file'] ?? '' );
        $type = sanitize_key( $_POST['import_type'] ?? 'main' );

        if ( ! $file || ! file_exists( $file ) ) {
            wp_send_json_error( 'Файл не найден.' );
        }

        $importer = new WSCities_Importer();
        $result   = $importer->scan_conflicts( $file, $type, 250 );

        wp_send_json_success( $result );
    }

    /**
     * @return array<string, mixed>
     */
    private function parse_import_resolutions( $raw ): array {
        if ( is_array( $raw ) ) {
            return $raw;
        }
        if ( ! is_string( $raw ) || $raw === '' ) {
            return [];
        }
        $decoded = json_decode( wp_unslash( $raw ), true );
        return is_array( $decoded ) ? $decoded : [];
    }

    /* ═══════════════════════════════════════════════════════
       AJAX: Blocks & Roads Table 1
    ═══════════════════════════════════════════════════════ */

    public function ajax_upload_br1(): void {
        check_ajax_referer( 'wscities_import', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Access denied.' );
        if ( empty( $_FILES['csv_file'] ) ) {
            wp_send_json_error( 'Файл не передан в запросе.' );
        }
        if ( (int) $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( $this->get_upload_error_message( (int) $_FILES['csv_file']['error'] ) );
        }

        $importer = new WSCities_Importer();
        $result   = $importer->prepare_br1( $_FILES['csv_file']['tmp_name'] );

        if ( isset( $result['error'] ) ) wp_send_json_error( $result['error'] );
        wp_send_json_success( $result );
    }

    public function ajax_process_batch_br1(): void {
        check_ajax_referer( 'wscities_import', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Access denied.' );

        $file       = sanitize_text_field( $_POST['file'] ?? '' );
        $offset     = (int) ( $_POST['offset'] ?? 0 );
        $batch_size = (int) ( $_POST['batch_size'] ?? 50 );
        $update     = ! empty( $_POST['update'] );

        if ( ! $file || ! file_exists( $file ) ) wp_send_json_error( 'Файл не найден.' );

        $resolutions = $this->parse_import_resolutions( $_POST['resolutions'] ?? '' );

        $importer = new WSCities_Importer();
        $result   = $importer->process_batch_br1( $file, $offset, $batch_size, $update, $resolutions );

        if ( isset( $result['error'] ) ) wp_send_json_error( $result['error'] );
        wp_send_json_success( $result );
    }

    /* ═══════════════════════════════════════════════════════
       AJAX: Blocks & Roads Table 2
    ═══════════════════════════════════════════════════════ */

    public function ajax_upload_br2(): void {
        check_ajax_referer( 'wscities_import', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Access denied.' );
        if ( empty( $_FILES['csv_file'] ) ) {
            wp_send_json_error( 'Файл не передан в запросе.' );
        }
        if ( (int) $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( $this->get_upload_error_message( (int) $_FILES['csv_file']['error'] ) );
        }

        $importer = new WSCities_Importer();
        $result   = $importer->prepare_br2( $_FILES['csv_file']['tmp_name'] );

        if ( isset( $result['error'] ) ) wp_send_json_error( $result['error'] );
        wp_send_json_success( $result );
    }

    /**
     * Chunked upload for very large BR2 CSV files.
     */
    public function ajax_upload_br2_chunk(): void {
        check_ajax_referer( 'wscities_import', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Access denied.' );

        if ( empty( $_FILES['chunk'] ) ) {
            wp_send_json_error( 'Чанк файла не передан.' );
        }
        if ( (int) $_FILES['chunk']['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( $this->get_upload_error_message( (int) $_FILES['chunk']['error'] ) );
        }

        $upload_id    = sanitize_key( (string) ( $_POST['upload_id'] ?? '' ) );
        $chunk_index  = (int) ( $_POST['chunk_index'] ?? 0 );
        $total_chunks = (int) ( $_POST['total_chunks'] ?? 0 );

        if ( $upload_id === '' || $total_chunks <= 0 ) {
            wp_send_json_error( 'Некорректные параметры чанковой загрузки.' );
        }

        $upload_dir = wp_upload_dir();
        $chunk_dir  = trailingslashit( $upload_dir['basedir'] ) . 'wscities-chunks';
        if ( ! file_exists( $chunk_dir ) ) {
            wp_mkdir_p( $chunk_dir );
        }

        $dest = trailingslashit( $chunk_dir ) . 'wscities-import-br2-' . $upload_id . '.csv';
        if ( $chunk_index === 0 && file_exists( $dest ) ) {
            unlink( $dest );
        }

        $chunk_tmp = (string) $_FILES['chunk']['tmp_name'];
        $chunk_data = file_get_contents( $chunk_tmp );
        if ( $chunk_data === false ) {
            wp_send_json_error( 'Не удалось прочитать чанк на сервере.' );
        }

        if ( file_put_contents( $dest, $chunk_data, FILE_APPEND ) === false ) {
            wp_send_json_error( 'Не удалось записать чанк на диск.' );
        }

        $is_last = ( $chunk_index + 1 ) >= $total_chunks;
        if ( ! $is_last ) {
            wp_send_json_success( [ 'done' => false ] );
        }

        $importer = new WSCities_Importer();
        $result   = $importer->prepare_br2_existing( $dest );
        if ( isset( $result['error'] ) ) {
            wp_send_json_error( $result['error'] );
        }
        $result['done'] = true;
        wp_send_json_success( $result );
    }

    public function ajax_process_batch_br2(): void {
        check_ajax_referer( 'wscities_import', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Access denied.' );

        $file       = sanitize_text_field( $_POST['file'] ?? '' );
        $offset     = (int) ( $_POST['offset'] ?? 0 );
        $batch_size = (int) ( $_POST['batch_size'] ?? 50 );
        $update     = ! empty( $_POST['update'] );

        if ( ! $file || ! file_exists( $file ) ) wp_send_json_error( 'Файл не найден.' );

        $resolutions = $this->parse_import_resolutions( $_POST['resolutions'] ?? '' );

        $importer = new WSCities_Importer();
        $result   = $importer->process_batch_br2( $file, $offset, $batch_size, $update, $resolutions );

        if ( isset( $result['error'] ) ) wp_send_json_error( $result['error'] );
        wp_send_json_success( $result );
    }

    /* ═══════════════════════════════════════════════════════
       AJAX: Greenspace
    ═══════════════════════════════════════════════════════ */
    public function ajax_upload_greenspace(): void {
        check_ajax_referer( 'wscities_import', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Access denied.' );

        if ( empty( $_FILES['csv_file'] ) ) {
            wp_send_json_error( 'Файл не передан в запросе.' );
        }
        if ( (int) $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( $this->get_upload_error_message( (int) $_FILES['csv_file']['error'] ) );
        }

        $importer = new WSCities_Importer();
        $result   = $importer->prepare_greenspace( $_FILES['csv_file']['tmp_name'] );

        if ( isset( $result['error'] ) ) wp_send_json_error( $result['error'] );
        wp_send_json_success( $result );
    }

    public function ajax_process_batch_greenspace(): void {
        check_ajax_referer( 'wscities_import', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Access denied.' );

        $file       = sanitize_text_field( $_POST['file'] ?? '' );
        $offset     = (int) ( $_POST['offset'] ?? 0 );
        $batch_size = (int) ( $_POST['batch_size'] ?? 50 );
        $update     = ! empty( $_POST['update'] );

        if ( ! $file || ! file_exists( $file ) ) wp_send_json_error( 'Файл не найден.' );

        $resolutions = $this->parse_import_resolutions( $_POST['resolutions'] ?? '' );

        $importer = new WSCities_Importer();
        $result   = $importer->process_batch_greenspace( $file, $offset, $batch_size, $update, $resolutions );

        if ( isset( $result['error'] ) ) wp_send_json_error( $result['error'] );
        wp_send_json_success( $result );
    }

    public function ajax_delete_all(): void {
        check_ajax_referer( 'wscities_import', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Access denied.' );
        }

        $count = WSCities_Importer::delete_all();

        // Clean up temp CSV
        $upload_dir = wp_upload_dir();
        $files = [
            $upload_dir['basedir'] . '/wscities-import.csv',
            $upload_dir['basedir'] . '/wscities-import-br1.csv',
            $upload_dir['basedir'] . '/wscities-import-br2.csv',
            $upload_dir['basedir'] . '/wscities-import-greenspace.csv',
        ];
        foreach ( $files as $csv ) {
            if ( file_exists( $csv ) ) unlink( $csv );
        }

        wp_send_json_success( [ 'deleted' => $count ] );
    }

    public function ajax_merge_duplicates(): void {
        check_ajax_referer( 'wscities_import', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Access denied.' );
        }

        $result = WSCities_Importer::merge_duplicates();
        wp_send_json_success( $result );
    }

    public function ajax_recalc_ergonomics(): void {
        check_ajax_referer( 'wscities_import', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Access denied.' );
        }
        if ( ! class_exists( 'WSErgo_City_Bridge' ) ) {
            wp_send_json_error( 'Плагин WorldStat Ergonomics не активен.' );
        }
        if ( method_exists( 'WSErgo_City_Bridge', 'is_city_import_ergo_enabled' ) && ! WSErgo_City_Bridge::is_city_import_ergo_enabled() ) {
            wp_send_json_error( 'Пересчёт остановлен: в настройках эргономики отключён расчёт по данным Cities. Включите его и повторите попытку.' );
        }

        if ( function_exists( 'wp_raise_memory_limit' ) ) {
            wp_raise_memory_limit( 'admin' );
        }
        if ( function_exists( 'set_time_limit' ) ) {
            set_time_limit( (int) apply_filters( 'wscities_recalc_ergonomics_time_limit', 120 ) );
        }

        global $wpdb;
        $total_cities = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts}
				WHERE post_type = %s AND post_status != 'trash' AND post_status != 'auto-draft'",
                WSCities_CPT::SLUG
            )
        );

        $batch = isset( $_POST['batch_size'] ) ? max( 1, min( 400, (int) wp_unslash( $_POST['batch_size'] ) ) ) : (int) apply_filters( 'wscities_recalc_ergonomics_batch_size', 100 );

        /*
         * Курсор по ID вместо OFFSET: при десятках тысяч городов OFFSET заставляет MySQL
         * просматривать всё растущее число строк на каждый пакет.
         */
        $after_id = isset( $_POST['after_id'] ) ? max( 0, (int) wp_unslash( $_POST['after_id'] ) ) : null;
        $offset   = isset( $_POST['offset'] ) ? max( 0, (int) wp_unslash( $_POST['offset'] ) ) : 0;

        if ( null !== $after_id ) {
            // LIMIT через целое в строке: часть драйверов/MySQL плохо переносит %d в prepare для LIMIT.
            $sql       = $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
				WHERE post_type = %s
				AND post_status NOT IN ( 'trash', 'auto-draft' )
				AND ID > %d
				ORDER BY ID ASC
				LIMIT " . (int) $batch,
                WSCities_CPT::SLUG,
                $after_id
            );
            $raw_ids  = $wpdb->get_col( $sql );
            if ( ! is_array( $raw_ids ) ) {
                if ( $wpdb->last_error ) {
                    wp_send_json_error( 'Ошибка выборки городов: ' . $wpdb->last_error );
                }
                $raw_ids = [];
            }
            $city_ids = array_map( 'intval', $raw_ids );
        } else {
            $city_ids = get_posts(
                [
                    'post_type'              => WSCities_CPT::SLUG,
                    'post_status'            => 'any',
                    'posts_per_page'         => $batch,
                    'offset'                 => $offset,
                    'orderby'                => 'ID',
                    'order'                  => 'ASC',
                    'fields'                 => 'ids',
                    'no_found_rows'          => true,
                    'update_post_meta_cache' => false,
                    'update_post_term_cache' => false,
                ]
            );
        }

        $updated = 0;
        $empty   = 0;
        foreach ( $city_ids as $city_id ) {
            $city_id = (int) $city_id;
            try {
                // Та же цепочка, что при просмотре города в эргономике: подиндексы + согласованный индекс E.
                if ( class_exists( 'WSErgo_Data' ) ) {
                    WSErgo_Data::get_city_import_subindices( $city_id );
                    $idx = WSErgo_Data::get_city_ergo_index( $city_id );
                } else {
                    $idx = WSErgo_City_Bridge::compute_and_store_city_leaf_index( $city_id );
                }
            } catch ( \Throwable $e ) {
                wp_send_json_error(
                    sprintf(
                        /* translators: 1: city post ID, 2: error message */
                        'Ошибка расчёта для города ID %1$d: %2$s',
                        $city_id,
                        $e->getMessage()
                    )
                );
            }
            if ( $idx !== null ) {
                ++$updated;
            } else {
                ++$empty;
            }
        }

        $processed_in_batch = count( $city_ids );
        $next_after_id      = null;
        if ( null !== $after_id ) {
            $last_id = $after_id;
            foreach ( $city_ids as $cid ) {
                $last_id = max( $last_id, (int) $cid );
            }
            $next_after_id = $last_id;
            $done          = ( $processed_in_batch === 0 ) || ( $processed_in_batch < $batch );
        } else {
            $next_offset    = $offset + $processed_in_batch;
            $done           = ( $processed_in_batch === 0 ) || ( $next_offset >= $total_cities );
            $next_after_id = null;
        }

        $payload = [
            'total'         => $total_cities,
            'batch_updated' => $updated,
            'batch_empty'   => $empty,
            'processed'     => $processed_in_batch,
            'done'          => $done,
        ];
        if ( null !== $after_id ) {
            $payload['after_id']       = $after_id;
            $payload['next_after_id']  = (int) $next_after_id;
        } else {
            $payload['offset']      = $offset;
            $payload['next_offset'] = $next_offset;
        }

        wp_send_json_success( $payload );
    }

    /**
     * Convert PHP upload error code to readable message.
     */
    private function get_upload_error_message( int $error_code ): string {
        $max_upload = (string) ini_get( 'upload_max_filesize' );
        $max_post   = (string) ini_get( 'post_max_size' );

        switch ( $error_code ) {
            case UPLOAD_ERR_INI_SIZE:
                return sprintf(
                    'Файл слишком большой для настроек сервера (upload_max_filesize=%s, post_max_size=%s). Увеличьте лимиты в php.ini и перезапустите Apache.',
                    $max_upload ?: 'unknown',
                    $max_post ?: 'unknown'
                );
            case UPLOAD_ERR_FORM_SIZE:
                return 'Файл превышает лимит формы загрузки.';
            case UPLOAD_ERR_PARTIAL:
                return 'Файл загружен частично. Повторите попытку.';
            case UPLOAD_ERR_NO_FILE:
                return 'Файл не выбран.';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'На сервере отсутствует временная папка для загрузок (upload_tmp_dir).';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Не удалось записать файл на диск.';
            case UPLOAD_ERR_EXTENSION:
                return 'Загрузка файла остановлена PHP-расширением.';
            default:
                return 'Неизвестная ошибка загрузки файла (код: ' . $error_code . ').';
        }
    }

    /* ═══════════════════════════════════════════════════════
       CUSTOM COLUMNS
    ═══════════════════════════════════════════════════════ */

    public function columns( array $cols ): array {
        return [
            'cb'    => $cols['cb'],
            'title' => $cols['title'],
            'wscity_country' => 'Страна',
            'wscity_pop'     => 'Население (T3)',
            'wscity_area'    => 'Площадь (га)',
            'wscity_density' => 'Плотность',
            'date'           => 'Дата',
        ];
    }

    public function column_content( string $col, int $id ): void {
        switch ( $col ) {
            case 'wscity_country':
                $iso2 = get_post_meta( $id, 'wscity_country_iso2', true );
                $name = get_post_meta( $id, 'wscity_country_name', true );
                echo esc_html( $name ) . ' (' . esc_html( $iso2 ) . ')';
                break;
            case 'wscity_pop':
                echo number_format( (int) get_post_meta( $id, 'wscity_pop_t3', true ), 0, '', ' ' );
                break;
            case 'wscity_area':
                echo number_format( (float) get_post_meta( $id, 'wscity_builtup_t3', true ), 0, '', ' ' );
                break;
            case 'wscity_density':
                $d = (float) get_post_meta( $id, 'wscity_density_builtup', true );
                echo $d > 0 ? round( $d ) . ' чел/га' : '—';
                break;
        }
    }
}
