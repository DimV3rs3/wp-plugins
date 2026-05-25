<?php
/**
 * Admin: страница настроек плагина.
 *
 * @package WorldStatCourtyard
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSC_Admin {

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_post_wsc_save_settings', [ __CLASS__, 'save_settings' ] );
		add_action( 'admin_post_wsc_save_categories', [ __CLASS__, 'save_categories' ] );
		add_action( 'admin_post_wsc_city_upload_pbf', [ __CLASS__, 'handle_city_pbf_upload' ] );
		add_action( 'add_meta_boxes', [ __CLASS__, 'register_city_pbf_metabox' ] );
		add_action( 'admin_notices', [ __CLASS__, 'city_pbf_admin_notice' ] );
	}

	/**
	 * Метабокс «Загрузить PBF» на экране редактирования города (wsp_city).
	 */
	public static function register_city_pbf_metabox(): void {
		if ( ! class_exists( 'WSCities_CPT' ) ) {
			return;
		}
		add_meta_box(
			'wsc_city_pbf',
			'Импорт OSM (PBF)',
			[ __CLASS__, 'render_city_pbf_metabox' ],
			WSCities_CPT::SLUG,
			'side',
			'high'
		);
	}

	/**
	 * @param WP_Post $post Текущий пост города.
	 */
	public static function render_city_pbf_metabox( $post ): void {
		if ( ! class_exists( 'WSCities_CPT' ) || (string) $post->post_type !== WSCities_CPT::SLUG ) {
			return;
		}
		$osmium_ok = WSC_PBF::is_available();
		$df        = array_filter( array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) ) );
		$shell_ok  = function_exists( 'shell_exec' ) && ! in_array( 'shell_exec', $df, true );
		$as_ok     = function_exists( 'as_schedule_single_action' );
		?>
		<p class="description" style="margin-top:0">
			Загрузите региональный дамп <strong>.osm.pbf</strong> (Geofabrik и т.п.). Импорт идёт в фоне: здания, POI, landuse в пределах контура города (Nominatim).
		</p>
		<?php if ( ! $osmium_ok || ! $shell_ok ) : ?>
			<div class="notice notice-error inline" style="margin:8px 0;padding:8px 10px;">
				<strong>Не готово к PBF:</strong>
				<?php if ( ! $shell_ok ) : ?>в PHP отключён <code>shell_exec</code>.<?php endif; ?>
				<?php if ( ! $osmium_ok ) : ?>не найден <strong>osmium-tool</strong> (укажите путь в <a href="<?php echo esc_url( admin_url( 'options-general.php?page=wsc-settings' ) ); ?>">настройках WS Courtyard</a>).<?php endif; ?>
			</div>
		<?php endif; ?>
		<?php if ( ! $as_ok ) : ?>
			<p class="description" style="color:#92400e;">
				Action Scheduler не активен — для фона нужен рабочий <strong>WP‑Cron</strong> (откройте сайт по cron или поставьте системный вызов <code>wp cron event run</code>).
			</p>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" style="margin-top:10px;">
			<?php wp_nonce_field( 'wsc_city_pbf_' . (int) $post->ID ); ?>
			<input type="hidden" name="action" value="wsc_city_upload_pbf" />
			<input type="hidden" name="city_id" value="<?php echo (int) $post->ID; ?>" />
			<p>
				<input type="file" name="pbf_file" accept=".pbf,.osm.pbf,application/octet-stream" <?php disabled( ! $osmium_ok || ! $shell_ok ); ?> />
			</p>
			<?php
			if ( $osmium_ok && $shell_ok ) {
				submit_button( 'Загрузить и поставить в очередь', 'secondary', 'submit', false );
			} else {
				submit_button( 'Загрузить и поставить в очередь', 'secondary', 'submit', false, [ 'disabled' => 'disabled' ] );
			}
			?>
		</form>

		<details style="margin-top:12px;font-size:12px;line-height:1.45;">
			<summary style="cursor:pointer;font-weight:600;">Что установить и как пользоваться</summary>
			<ol style="margin:8px 0 0 1em;padding:0 0 0 1em;">
				<li><strong>osmium-tool</strong> — в PATH или полный путь в «Настройки → WS Courtyard → Путь к osmium». Windows: установите из <a href="https://github.com/osmcode/osmium-tool/releases" target="_blank" rel="noopener noreferrer">релизов osmium-tool</a> или через пакетный менеджер; на сервере чаще <code>apt install osmium-tool</code>.</li>
				<li>В <strong>php.ini</strong> разрешите <code>shell_exec</code> (не входит в <code>disable_functions</code>), увеличьте при необходимости <code>upload_max_filesize</code> и <code>post_max_size</code> под размер PBF.</li>
				<li>Файл: обычно <code>*-latest.osm.pbf</code> по региону; для одного города можно взять область побольше — лишнее отрежется по bbox из Nominatim.</li>
				<li>После отправки формы откройте карту города на странице страны (вкладка «Придомовая территория») и кнопку «Задачи города» — там виден прогресс job с источником PBF.</li>
			</ol>
		</details>
		<?php
	}

	public static function handle_city_pbf_upload(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'worldstat-courtyard' ), '', [ 'response' => 403 ] );
		}
		$city_id = isset( $_POST['city_id'] ) ? (int) $_POST['city_id'] : 0;
		check_admin_referer( 'wsc_city_pbf_' . $city_id );

		$redirect = $city_id > 0 ? admin_url( 'post.php?post=' . $city_id . '&action=edit' ) : '';
		if ( $redirect === '' ) {
			wp_safe_redirect( admin_url( 'edit.php?post_type=wsp_city' ) );
			exit;
		}

		$file = isset( $_FILES['pbf_file'] ) && is_array( $_FILES['pbf_file'] ) ? $_FILES['pbf_file'] : null;
		if ( ! $file ) {
			set_transient( 'wsc_pbf_err_' . get_current_user_id(), 'Файл не выбран.', 120 );
			wp_safe_redirect( add_query_arg( 'wsc_pbf', 'err', $redirect ) );
			exit;
		}

		$r = WSC_REST::save_pbf_upload_for_city( $city_id, $file );
		if ( is_wp_error( $r ) ) {
			set_transient( 'wsc_pbf_err_' . get_current_user_id(), $r->get_error_message(), 120 );
			wp_safe_redirect( add_query_arg( 'wsc_pbf', 'err', $redirect ) );
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				[
					'wsc_pbf' => 'ok',
					'wsc_job' => (int) $r['job_id'],
				],
				$redirect
			)
		);
		exit;
	}

	public static function city_pbf_admin_notice(): void {
		if ( empty( $_GET['wsc_pbf'] ) || empty( $_GET['post'] ) ) {
			return;
		}
		if ( ! class_exists( 'WSCities_CPT' ) ) {
			return;
		}
		$post_id = (int) $_GET['post'];
		if ( $post_id <= 0 || get_post_type( $post_id ) !== WSCities_CPT::SLUG ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->base !== 'post' ) {
			return;
		}

		if ( $_GET['wsc_pbf'] === 'ok' ) {
			$jid = isset( $_GET['wsc_job'] ) ? (int) $_GET['wsc_job'] : 0;
			echo '<div class="notice notice-success is-dismissible"><p>';
			echo esc_html( $jid > 0 ? "Импорт PBF поставлен в очередь (задача №{$jid}). Откройте карту города на сайте и при необходимости «Задачи города»." : 'Импорт PBF поставлен в очередь.' );
			echo '</p></div>';
			return;
		}

		if ( $_GET['wsc_pbf'] === 'err' ) {
			$msg = get_transient( 'wsc_pbf_err_' . get_current_user_id() );
			delete_transient( 'wsc_pbf_err_' . get_current_user_id() );
			$msg = is_string( $msg ) && $msg !== '' ? $msg : 'Ошибка загрузки или импорта.';
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
	}

	public static function menu(): void {
		add_submenu_page(
			'options-general.php', 'WorldStat Courtyard', 'WS Courtyard',
			'manage_options', 'wsc-settings', [ __CLASS__, 'render_page' ]
		);
	}

	public static function render_page(): void {
		$buf      = WSC_Settings::get_buffers_map();
		$hgt      = WSC_Settings::get_heights_map();
		$tiles    = WSC_Settings::get_tiles_source();
		$lang     = WSC_Settings::get_language();
		$osmium   = get_option( WSC_Settings::OPT_OSMIUM, '' );
		$ogr2ogr  = get_option( WSC_Settings::OPT_OGR2OGR, '' );
		$overpass = get_option( WSC_Settings::OPT_OVERPASS, '' );
		$cats     = WSC_Categories::all();
		$rules    = WSC_Categories::get_rules();
		$mbtiles  = WSC_MBTiles::list_files();

		$buf_backend = WSC_Buffer::backend_available();
		$has_osmium  = WSC_PBF::is_available();
		?>
		<div class="wrap">
			<h1>WorldStat Courtyard — настройки</h1>

			<h2>Состояние окружения</h2>
			<table class="widefat striped" style="max-width:760px">
				<tr><th>Backend для буфера</th><td><code><?php echo esc_html( $buf_backend ); ?></code> <?php
					echo $buf_backend === 'php' ? '<em>(чистый PHP — fallback, рекомендуется поставить php-geos или GDAL/ogr2ogr)</em>' : ''; ?></td></tr>
				<tr><th>osmium-tool</th><td><?php echo $has_osmium ? '<span style="color:#15803d">✓ доступен</span>' : '<span style="color:#dc2626">не найден</span>'; ?></td></tr>
				<tr><th>MBTiles файлы</th><td><?php echo $mbtiles ? esc_html( implode( ', ', $mbtiles ) ) : '<em>нет (положите .mbtiles в uploads/wsc/tiles/)</em>'; ?></td></tr>
				<tr><th>Uploads dir</th><td><code><?php echo esc_html( WSC_Settings::uploads_dir()['basedir'] ); ?></code></td></tr>
			</table>

			<h2>Основные настройки</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wsc_save_settings' ); ?>
				<input type="hidden" name="action" value="wsc_save_settings" />

				<h3>Буфер по категориям (метры)</h3>
				<table class="form-table">
					<?php foreach ( $cats as $key => $label ) : ?>
						<tr>
							<th><?php echo esc_html( $label ); ?></th>
							<td><input type="number" min="1" max="500" step="1" name="buffer[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $buf[ $key ] ?? 35 ); ?>" /> м</td>
						</tr>
					<?php endforeach; ?>
				</table>

				<h3>Высоты по умолчанию (м, для 3D без OSM-тегов)</h3>
				<table class="form-table">
					<?php foreach ( $cats as $key => $label ) : ?>
						<tr>
							<th><?php echo esc_html( $label ); ?></th>
							<td><input type="number" min="1" max="200" step="0.5" name="height[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $hgt[ $key ] ?? 6 ); ?>" /> м</td>
						</tr>
					<?php endforeach; ?>
				</table>

				<h3>Карта</h3>
				<table class="form-table">
					<tr>
						<th>Источник тайлов</th>
						<td>
							<select name="tiles[basemap]">
								<option value="openfreemap" <?php selected( $tiles['basemap'], 'openfreemap' ); ?>>OpenFreeMap (бесплатно)</option>
								<option value="maptiler"    <?php selected( $tiles['basemap'], 'maptiler' ); ?>>MapTiler (нужен ключ)</option>
								<option value="mbtiles"     <?php selected( $tiles['basemap'], 'mbtiles' ); ?>>Локальный MBTiles</option>
							</select>
						</td>
					</tr>
					<tr><th>OpenFreeMap стиль</th><td><input type="text" name="tiles[style]" value="<?php echo esc_attr( $tiles['style'] ?? 'positron' ); ?>" placeholder="positron / bright / fiord / liberty" /><p class="description">positron — минимальный без landuse-зон (рекомендуется); liberty — полноцветный (много розовых пятен).</p></td></tr>
					<tr><th>MBTiles файл</th><td><input type="text" name="tiles[mbtiles_file]" value="<?php echo esc_attr( $tiles['mbtiles_file'] ?? '' ); ?>" placeholder="city.mbtiles" /></td></tr>
					<tr><th>API key (MapTiler)</th><td><input type="text" name="tiles[api_key]" value="<?php echo esc_attr( $tiles['api_key'] ?? '' ); ?>" /></td></tr>
				</table>

				<h3>Прочее</h3>
				<table class="form-table">
					<tr><th>Язык наименований</th><td>
						<select name="language">
							<option value="ru"    <?php selected( $lang, 'ru' ); ?>>name:ru → name:en → name</option>
							<option value="en"    <?php selected( $lang, 'en' ); ?>>name:en → name → name:ru</option>
							<option value="local" <?php selected( $lang, 'local' ); ?>>name → name:en → name:ru</option>
						</select>
					</td></tr>
					<tr><th>Overpass endpoint</th><td><input type="text" size="60" name="overpass" value="<?php echo esc_attr( $overpass ); ?>" placeholder="https://overpass-api.de/api/interpreter" /></td></tr>
					<tr><th>Путь к osmium</th><td><input type="text" size="60" name="osmium" value="<?php echo esc_attr( $osmium ); ?>" placeholder="osmium" /></td></tr>
					<tr><th>Путь к ogr2ogr</th><td><input type="text" size="60" name="ogr2ogr" value="<?php echo esc_attr( $ogr2ogr ); ?>" placeholder="ogr2ogr" /></td></tr>
				</table>

				<?php submit_button( 'Сохранить' ); ?>
			</form>

			<hr/>
			<h2>Правила категорий (тег OSM → категория)</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wsc_save_categories' ); ?>
				<input type="hidden" name="action" value="wsc_save_categories" />
				<p><textarea name="rules" rows="20" cols="100" style="font-family:monospace"><?php echo esc_textarea( wp_json_encode( $rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></textarea></p>
				<p>Формат: <code>{ "building": { "apartments": "residential", "*": "other" }, "amenity": { ... } }</code></p>
				<?php submit_button( 'Сохранить правила' ); ?>
			</form>
		</div>
		<?php
	}

	public static function save_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );
		check_admin_referer( 'wsc_save_settings' );

		WSC_Settings::set_buffers_map( (array) ( $_POST['buffer'] ?? [] ) );
		WSC_Settings::set_heights_map( (array) ( $_POST['height'] ?? [] ) );

		$tiles_in = (array) ( $_POST['tiles'] ?? [] );
		$tiles = [
			'basemap'      => in_array( $tiles_in['basemap'] ?? '', [ 'openfreemap', 'maptiler', 'mbtiles' ], true ) ? $tiles_in['basemap'] : 'openfreemap',
			'style'        => sanitize_text_field( (string) ( $tiles_in['style'] ?? 'positron' ) ),
			'mbtiles_file' => sanitize_file_name( (string) ( $tiles_in['mbtiles_file'] ?? '' ) ),
			'api_key'      => sanitize_text_field( (string) ( $tiles_in['api_key'] ?? '' ) ),
		];
		update_option( WSC_Settings::OPT_TILES, $tiles );
		update_option( WSC_Settings::OPT_LANGUAGE, in_array( $_POST['language'] ?? '', [ 'ru', 'en', 'local' ], true ) ? $_POST['language'] : 'ru' );
		update_option( WSC_Settings::OPT_OSMIUM,   sanitize_text_field( (string) ( $_POST['osmium'] ?? '' ) ) );
		update_option( WSC_Settings::OPT_OGR2OGR,  sanitize_text_field( (string) ( $_POST['ogr2ogr'] ?? '' ) ) );
		update_option( WSC_Settings::OPT_OVERPASS, esc_url_raw( (string) ( $_POST['overpass'] ?? '' ) ) );

		WSC_MVT::flush_all_cache();
		wp_safe_redirect( add_query_arg( 'updated', 1, admin_url( 'options-general.php?page=wsc-settings' ) ) );
		exit;
	}

	public static function save_categories(): void {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );
		check_admin_referer( 'wsc_save_categories' );

		$raw = wp_unslash( (string) ( $_POST['rules'] ?? '' ) );
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			WSC_Categories::set_rules( $decoded );
		}
		wp_safe_redirect( add_query_arg( 'updated', 1, admin_url( 'options-general.php?page=wsc-settings' ) ) );
		exit;
	}
}
