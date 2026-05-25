<?php
/**
 * REST endpoints (namespace wsc/v1).
 *
 * @package WorldStatCourtyard
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSC_REST {

	public static function register(): void {
		$ns = WSC_REST_NS;
		$admin_perm = function () { return current_user_can( 'manage_options' ); };
		$public_perm = '__return_true';

		register_rest_route( $ns, '/country/(?P<iso2>[a-zA-Z]{2})/cities', [
			'methods' => 'GET', 'permission_callback' => $public_perm,
			'callback' => [ __CLASS__, 'route_country_cities' ],
		] );

		register_rest_route( $ns, '/city/(?P<id>\d+)', [
			'methods' => 'GET', 'permission_callback' => $public_perm,
			'callback' => [ __CLASS__, 'route_city_summary' ],
		] );

		register_rest_route( $ns, '/city/(?P<id>\d+)/stats', [
			'methods' => 'GET', 'permission_callback' => $public_perm,
			'callback' => [ __CLASS__, 'route_city_stats' ],
		] );

		register_rest_route( $ns, '/city/(?P<id>\d+)/scan', [
			'methods' => 'POST', 'permission_callback' => $admin_perm,
			'callback' => [ __CLASS__, 'route_scan_start' ],
			'args' => [ 'mode' => [ 'type' => 'string', 'default' => 'auto' ], 'polygon' => [ 'type' => 'object', 'default' => null ] ],
		] );

		register_rest_route( $ns, '/overpass/precheck', [
			'methods' => 'GET', 'permission_callback' => $admin_perm,
			'callback' => [ __CLASS__, 'route_overpass_precheck' ],
		] );

		register_rest_route( $ns, '/overpass/status', [
			'methods' => 'GET', 'permission_callback' => $admin_perm,
			'callback' => [ __CLASS__, 'route_overpass_status' ],
		] );

		register_rest_route( $ns, '/city/(?P<id>\d+)/scan/status', [
			'methods' => 'GET', 'permission_callback' => $admin_perm,
			'callback' => [ __CLASS__, 'route_scan_status' ],
		] );

		register_rest_route( $ns, '/city/(?P<id>\d+)/scan/abort', [
			'methods' => 'POST', 'permission_callback' => $admin_perm,
			'callback' => [ __CLASS__, 'route_scan_abort' ],
		] );

		register_rest_route( $ns, '/city/(?P<id>\d+)/scan/resume', [
			'methods' => 'POST', 'permission_callback' => $admin_perm,
			'callback' => [ __CLASS__, 'route_scan_resume' ],
		] );

		register_rest_route( $ns, '/city/(?P<id>\d+)/scan/finish', [
			'methods' => 'POST', 'permission_callback' => $admin_perm,
			'callback' => [ __CLASS__, 'route_scan_finish' ],
		] );

		register_rest_route( $ns, '/city/(?P<id>\d+)/upload-pbf', [
			'methods' => 'POST', 'permission_callback' => $admin_perm,
			'callback' => [ __CLASS__, 'route_upload_pbf' ],
		] );

		register_rest_route( $ns, '/city/(?P<id>\d+)/recompute-buffers', [
			'methods' => 'POST', 'permission_callback' => $admin_perm,
			'callback' => [ __CLASS__, 'route_recompute_buffers' ],
		] );

		register_rest_route( $ns, '/city/(?P<id>\d+)/jobs', [
			'methods' => 'GET', 'permission_callback' => $admin_perm,
			'callback' => [ __CLASS__, 'route_city_jobs' ],
			'args' => [ 'limit' => [ 'type' => 'integer', 'default' => 50 ] ],
		] );

		register_rest_route( $ns, '/city/(?P<id>\d+)/jobs/delete', [
			'methods' => 'POST', 'permission_callback' => $admin_perm,
			'callback' => [ __CLASS__, 'route_city_jobs_delete' ],
			'args' => [ 'scope' => [ 'type' => 'string', 'default' => 'running' ] ],
		] );

		register_rest_route( $ns, '/city/(?P<id>\d+)/jobs/abort', [
			'methods' => 'POST', 'permission_callback' => $admin_perm,
			'callback' => [ __CLASS__, 'route_city_jobs_abort' ],
		] );

		register_rest_route( $ns, '/job/(?P<job_id>\d+)/abort', [
			'methods' => 'POST', 'permission_callback' => $admin_perm,
			'callback' => [ __CLASS__, 'route_job_abort' ],
		] );

		register_rest_route( $ns, '/job/(?P<job_id>\d+)/delete', [
			'methods' => 'POST', 'permission_callback' => $admin_perm,
			'callback' => [ __CLASS__, 'route_job_delete' ],
		] );

		register_rest_route( $ns, '/city/(?P<id>\d+)/layer/(?P<layer>[a-z]+)', [
			'methods' => 'GET', 'permission_callback' => $public_perm,
			'callback' => [ __CLASS__, 'route_layer' ],
		] );

		register_rest_route( $ns, '/city/(?P<id>\d+)/boundary', [
			'methods' => 'GET', 'permission_callback' => $public_perm,
			'callback' => [ __CLASS__, 'route_boundary' ],
		] );

		register_rest_route( $ns, '/city/(?P<id>\d+)/export.geojson', [
			'methods' => 'GET', 'permission_callback' => $public_perm,
			'callback' => [ __CLASS__, 'route_export_geojson' ],
		] );

		register_rest_route( $ns, '/basemap/(?P<z>\d+)/(?P<x>\d+)/(?P<y>\d+)', [
			'methods' => 'GET', 'permission_callback' => $public_perm,
			'callback' => [ __CLASS__, 'route_basemap_tile' ],
		] );

		register_rest_route( $ns, '/style.json', [
			'methods' => 'GET', 'permission_callback' => $public_perm,
			'callback' => [ __CLASS__, 'route_style_json' ],
		] );

		register_rest_route( $ns, '/building-ergo', [
			'methods' => 'GET', 'permission_callback' => $public_perm,
			'callback' => [ __CLASS__, 'route_building_ergo' ],
			'args' => [
				'osm_type' => [ 'type' => 'string', 'required' => true ],
				'osm_id'   => [ 'type' => 'integer', 'required' => true ],
			],
		] );

		register_rest_route( $ns, '/city/(?P<id>\d+)/buildings-ergo-list', [
			'methods'             => 'GET',
			'permission_callback' => $public_perm,
			'callback'            => [ __CLASS__, 'route_buildings_ergo_list' ],
			'args'                => [
				'page'     => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
				'per_page' => [ 'type' => 'integer', 'default' => 100, 'minimum' => 1, 'maximum' => 500 ],
				'orderby'  => [ 'type' => 'string', 'enum' => [ 'e', 'address', 'id', 'building_id' ], 'default' => 'id' ],
				'order'    => [ 'type' => 'string', 'enum' => [ 'asc', 'desc' ], 'default' => 'asc' ],
			],
		] );

		register_rest_route( $ns, '/city/(?P<id>\d+)/building/(?P<building_id>\d+)/courtyard-contents', [
			'methods'             => 'GET',
			'permission_callback' => $public_perm,
			'callback'            => [ __CLASS__, 'route_courtyard_contents' ],
		] );

		register_rest_route( $ns, '/city/(?P<id>\d+)/ergo-recompute-chunk', [
			'methods'             => 'POST',
			'permission_callback' => $admin_perm,
			'callback'            => [ __CLASS__, 'route_ergo_recompute_chunk' ],
			'args'                => [
				'offset' => [ 'type' => 'integer', 'default' => 0, 'minimum' => 0 ],
				'limit'  => [ 'type' => 'integer', 'default' => 35, 'minimum' => 1, 'maximum' => 50 ],
			],
		] );
	}

	/* ─── Routes ─── */

	public static function route_country_cities( WP_REST_Request $req ) {
		$iso2 = strtoupper( $req['iso2'] );
		$cities = WSCities_CPT::get_cities_for_country( $iso2 );
		$out = [];
		foreach ( $cities as $c ) {
			$out[] = [
				'id'        => (int) $c['id'],
				'name'      => (string) $c['name'],
				'pop'       => (int) $c['pop_t3'],
				'lat'       => (float) $c['lat'],
				'lng'       => (float) $c['lng'],
				'permalink' => get_permalink( (int) $c['id'] ),
				'buildings' => WSC_Writer::count_buildings_for_city( (int) $c['id'] ),
			];
		}
		return rest_ensure_response( $out );
	}

	public static function route_city_summary( WP_REST_Request $req ) {
		$cid = (int) $req['id'];
		$post = get_post( $cid );
		if ( ! $post || $post->post_type !== WSCities_CPT::SLUG ) {
			return new WP_Error( 'not_found', 'City not found', [ 'status' => 404 ] );
		}
		$active = WSC_Jobs_Import::get_active_job_for_city( $cid );
		return rest_ensure_response( [
			'id'        => $cid,
			'name'      => get_the_title( $cid ),
			'iso2'      => strtoupper( (string) get_post_meta( $cid, 'wscity_country_iso2', true ) ),
			'lat'       => (float) get_post_meta( $cid, 'wscity_lat', true ),
			'lng'       => (float) get_post_meta( $cid, 'wscity_lng', true ),
			'buildings' => WSC_Writer::count_buildings_for_city( $cid ),
			'active_job'=> $active ? [
				'id' => (int) $active['id'], 'total' => (int) $active['total'],
				'done' => (int) $active['done'], 'imported' => (int) $active['imported'],
				'status' => $active['status'],
			] : null,
		] );
	}

	/**
	 * Сводка для панели «Сводка по городу» под картой.
	 * Один HTTP-запрос → 4–5 дешёвых GROUP BY по индексу city_id.
	 */
	public static function route_city_stats( WP_REST_Request $req ) {
		$cid = (int) $req['id'];
		if ( $cid <= 0 ) {
			return new WP_Error( 'bad_id', 'Invalid city id', [ 'status' => 400 ] );
		}
		global $wpdb;
		$tb = WSC_Installer::table_buildings();
		$tp = WSC_Installer::table_pois();
		$tl = WSC_Installer::table_landuse();
		$ty = WSC_Installer::table_yards();

		$has_b = WSC_Writer::table_exists( $tb );
		$has_p = WSC_Writer::table_exists( $tp );
		$has_l = WSC_Writer::table_exists( $tl );
		$has_y = WSC_Writer::table_exists( $ty );

		$buildings_by_cat = [];
		$levels_buckets   = [ 'b_1_2' => 0, 'b_3_5' => 0, 'b_6_9' => 0, 'b_10_16' => 0, 'b_17p' => 0, 'unknown' => 0 ];
		$avg_height       = 0.0;
		$avg_levels       = 0.0;
		$total_buildings  = 0;

		if ( $has_b ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT category, COUNT(*) AS n FROM {$tb} WHERE city_id=%d GROUP BY category", $cid
			), ARRAY_A );
			foreach ( (array) $rows as $r ) {
				$buildings_by_cat[ (string) $r['category'] ] = (int) $r['n'];
				$total_buildings += (int) $r['n'];
			}

			$lv = $wpdb->get_results( $wpdb->prepare(
				"SELECT
					SUM(CASE WHEN levels BETWEEN 1 AND 2  THEN 1 ELSE 0 END) AS b_1_2,
					SUM(CASE WHEN levels BETWEEN 3 AND 5  THEN 1 ELSE 0 END) AS b_3_5,
					SUM(CASE WHEN levels BETWEEN 6 AND 9  THEN 1 ELSE 0 END) AS b_6_9,
					SUM(CASE WHEN levels BETWEEN 10 AND 16 THEN 1 ELSE 0 END) AS b_10_16,
					SUM(CASE WHEN levels >= 17 THEN 1 ELSE 0 END) AS b_17p,
					SUM(CASE WHEN levels IS NULL OR levels <= 0 THEN 1 ELSE 0 END) AS unknown,
					AVG(NULLIF(height_m,0)) AS avg_h,
					AVG(NULLIF(levels,0))   AS avg_l
				 FROM {$tb} WHERE city_id=%d",
				$cid
			), ARRAY_A );
			if ( ! empty( $lv[0] ) ) {
				$row = $lv[0];
				$levels_buckets = [
					'b_1_2'   => (int) $row['b_1_2'],
					'b_3_5'   => (int) $row['b_3_5'],
					'b_6_9'   => (int) $row['b_6_9'],
					'b_10_16' => (int) $row['b_10_16'],
					'b_17p'   => (int) $row['b_17p'],
					'unknown' => (int) $row['unknown'],
				];
				$avg_height = (float) ( $row['avg_h'] ?? 0 );
				$avg_levels = (float) ( $row['avg_l'] ?? 0 );
			}
		}

		$pois_by_cat = [];
		$total_pois  = 0;
		if ( $has_p ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT category, COUNT(*) AS n FROM {$tp} WHERE city_id=%d GROUP BY category", $cid
			), ARRAY_A );
			foreach ( (array) $rows as $r ) {
				$pois_by_cat[ (string) $r['category'] ] = (int) $r['n'];
				$total_pois += (int) $r['n'];
			}
		}

		$top_landuse = [];
		if ( $has_l ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT kind, COUNT(*) AS n FROM {$tl} WHERE city_id=%d GROUP BY kind ORDER BY n DESC LIMIT 5", $cid
			), ARRAY_A );
			foreach ( (array) $rows as $r ) {
				$top_landuse[] = [ 'kind' => (string) $r['kind'], 'count' => (int) $r['n'] ];
			}
		}

		$yards = [ 'count' => 0, 'avg_area_m2' => 0.0 ];
		if ( $has_y && $has_b ) {
			// JOIN: yards.building_id -> buildings.id, фильтр по city_id зданий.
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT COUNT(*) AS n, AVG(NULLIF(y.area_m2,0)) AS avg_a
				 FROM {$ty} y JOIN {$tb} b ON b.id=y.building_id
				 WHERE b.city_id=%d",
				$cid
			), ARRAY_A );
			if ( $row ) {
				$yards = [
					'count'       => (int) ( $row['n'] ?? 0 ),
					'avg_area_m2' => (float) ( $row['avg_a'] ?? 0 ),
				];
			}
		}

		return rest_ensure_response( [
			'city_id'        => $cid,
			'totals'         => [
				'buildings' => $total_buildings,
				'pois'      => $total_pois,
				'yards'     => (int) $yards['count'],
				'avg_height_m' => round( $avg_height, 1 ),
				'avg_levels'   => round( $avg_levels, 1 ),
			],
			'buildings_by_category' => $buildings_by_cat,
			'pois_by_category'      => $pois_by_cat,
			'levels_buckets'        => $levels_buckets,
			'top_landuse'           => $top_landuse,
			'yards'                 => $yards,
		] );
	}

	public static function route_scan_start( WP_REST_Request $req ) {
		$cid = (int) $req['id'];
		$mode = (string) ( $req->get_param( 'mode' ) ?: 'auto' );
		$polygon = $req->get_param( 'polygon' );
		if ( ! is_array( $polygon ) ) $polygon = null;

		// Pre-flight: статус Overpass до постановки скана. Если занят — отдаём 503 без занятия лока.
		$pre = WSC_Overpass::precheck();
		if ( ! $pre['ok'] ) {
			return new WP_Error( 'overpass_unavailable', $pre['message'], [ 'status' => 503, 'precheck' => $pre ] );
		}

		// Lock: 1 active scan per city. Атомарный CAS через add_option (INSERT ... ON DUPLICATE KEY ignore)
		// — без TOCTOU-окна. Транзиент-ключ оставляем для совместимости со старыми проверками.
		$lock_key = 'wsc_lock_city_' . $cid;
		if ( ! WSC_Jobs_Import::acquire_lock( $cid, 'scan' ) ) {
			return new WP_Error( 'busy', 'Scan or buffer recompute already running for this city', [ 'status' => 409 ] );
		}
		set_transient( $lock_key, time(), 30 * MINUTE_IN_SECONDS );

		// start_scan() теперь только вставляет job-строку и возвращает job_id — это занимает ~50мс.
		// Тяжёлый batch-цикл уехал в dispatch_scan_batches() и запускается из shutdown-хука
		// уже ПОСЛЕ того, как WordPress отдаст ответ браузеру (см. ниже fastcgi_finish_request).
		// Раньше start_scan крутил все batch'и синхронно — браузер ждал 10+ мин до job_id.
		try {
			$job_id = WSC_Jobs_Import::start_scan( $cid, $polygon, $mode, (string) ( $pre['message'] ?? '' ) );
		} catch ( Throwable $e ) {
			WSC_Jobs_Import::release_lock( $cid );
			return new WP_Error( 'scan_failed', $e->getMessage(), [ 'status' => 500 ] );
		}

		add_action( 'shutdown', static function () use ( $job_id, $cid ): void {
			// Снять ограничения ДО разрыва соединения: иначе PHP может прервать скрипт,
			// заметив disconnect клиента на следующем выводе.
			ignore_user_abort( true );
			@set_time_limit( 0 );
			// fastcgi_finish_request — отдать тело клиенту и закрыть FastCGI-канал; PHP продолжит
			// крутиться в фоне. На mod_php/PHP-CLI функции нет — там просто остаёмся в обычном
			// (синхронном) поведении.
			if ( function_exists( 'fastcgi_finish_request' ) ) {
				fastcgi_finish_request();
			}
			try {
				WSC_Jobs_Import::dispatch_scan_batches( $job_id );
			} catch ( Throwable $e ) {
				// Шансов залогировать в ответ уже нет — пишем в job.log и освобождаем lock,
				// чтобы город не оставался заблокированным навечно.
				global $wpdb;
				$wpdb->update(
					WSC_Installer::table_jobs(),
					[
						'status'     => 'aborted',
						'updated_at' => current_time( 'mysql', true ),
						'log'        => '[shutdown-fail] ' . $e->getMessage() . "\n",
					],
					[ 'id' => $job_id ]
				);
				WSC_Jobs_Import::release_lock( $cid );
			}
		}, 0 );

		return rest_ensure_response( [
			'job_id'   => $job_id,
			'precheck' => $pre,
		] );
	}

	public static function route_overpass_precheck( WP_REST_Request $req ) {
		return rest_ensure_response( WSC_Overpass::precheck() );
	}

	public static function route_overpass_status( WP_REST_Request $req ) {
		$endpoint = (string) ( $req->get_param( 'endpoint' ) ?: '' );
		$out = [];
		if ( $endpoint !== '' ) {
			$out['endpoints'][ $endpoint ] = WSC_Overpass::get_status( $endpoint );
		} else {
			foreach ( WSC_Overpass::fallback_endpoints() as $ep ) {
				$out['endpoints'][ $ep ] = WSC_Overpass::get_status( $ep );
			}
		}
		$out['best'] = WSC_Overpass::pick_best_endpoint();
		return rest_ensure_response( $out );
	}

	public static function route_scan_status( WP_REST_Request $req ) {
		$cid = (int) $req['id'];
		$job = WSC_Jobs_Import::get_active_job_for_city( $cid );
		if ( ! $job ) {
			WSC_Jobs_Import::release_lock( $cid );
			return rest_ensure_response( [ 'status' => 'idle' ] );
		}
		if ( $job['status'] !== 'running' ) {
			WSC_Jobs_Import::release_lock( $cid );
		}
		return rest_ensure_response( [
			'status'   => $job['status'],
			'job_id'   => (int) $job['id'],
			'source'   => (string) ( $job['source'] ?? 'overpass' ),
			'total'    => (int) $job['total'],
			'done'     => (int) $job['done'],
			'imported' => (int) $job['imported'],
			'log'      => (string) $job['log'],
		] );
	}

	public static function route_scan_abort( WP_REST_Request $req ) {
		$cid = (int) $req['id'];
		$job = WSC_Jobs_Import::get_active_job_for_city( $cid );
		if ( ! $job ) {
			// Fall back to the most recent job for this city.
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM " . WSC_Installer::table_jobs() . " WHERE city_id=%d ORDER BY id DESC LIMIT 1",
				$cid
			), ARRAY_A );
			$job = $row;
		}
		if ( ! $job ) return new WP_Error( 'no_job', 'No job found', [ 'status' => 404 ] );
		WSC_Jobs_Import::abort( (int) $job['id'] );
		return rest_ensure_response( [ 'aborted' => (int) $job['id'] ] );
	}

	public static function route_scan_resume( WP_REST_Request $req ) {
		$cid = (int) $req['id'];
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM " . WSC_Installer::table_jobs() . " WHERE city_id=%d ORDER BY id DESC LIMIT 1",
			$cid
		), ARRAY_A );
		if ( ! $row ) return new WP_Error( 'no_job', 'No job found', [ 'status' => 404 ] );
		$n = WSC_Jobs_Import::resume( (int) $row['id'] );
		return rest_ensure_response( [ 'rescheduled' => $n, 'job_id' => (int) $row['id'] ] );
	}

	public static function route_scan_finish( WP_REST_Request $req ) {
		$cid = (int) $req['id'];
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM " . WSC_Installer::table_jobs() . " WHERE city_id=%d ORDER BY id DESC LIMIT 1",
			$cid
		), ARRAY_A );
		if ( ! $row ) return new WP_Error( 'no_job', 'No job found', [ 'status' => 404 ] );
		WSC_Jobs_Import::force_finish( (int) $row['id'] );
		return rest_ensure_response( [ 'finished' => (int) $row['id'] ] );
	}

	/**
	 * Сохранить загруженный PBF и поставить фоновый импорт (общая логика REST и админки).
	 *
	 * @param int   $city_id ID поста wsp_city.
	 * @param array $file    Элемент из $_FILES (например $_FILES['file']).
	 * @return array|WP_Error { job_id: int } или ошибка.
	 */
	public static function save_pbf_upload_for_city( int $city_id, array $file ) {
		if ( $city_id <= 0 ) {
			return new WP_Error( 'bad_id', 'Invalid city id', [ 'status' => 400 ] );
		}
		$post = get_post( $city_id );
		if ( ! $post || ! class_exists( 'WSCities_CPT' ) || $post->post_type !== WSCities_CPT::SLUG ) {
			return new WP_Error( 'not_city', 'City post not found', [ 'status' => 404 ] );
		}
		if ( empty( $file['tmp_name'] ) ) {
			return new WP_Error( 'no_file', 'No file uploaded', [ 'status' => 400 ] );
		}
		$err_code = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_OK;
		if ( $err_code !== UPLOAD_ERR_OK ) {
			$msg = 'Upload failed';
			if ( $err_code === UPLOAD_ERR_INI_SIZE || $err_code === UPLOAD_ERR_FORM_SIZE ) {
				$msg = 'File too large (check upload_max_filesize / post_max_size in php.ini)';
			}
			return new WP_Error( 'upload_err', $msg, [ 'status' => 400 ] );
		}
		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'no_file', 'Invalid upload', [ 'status' => 400 ] );
		}

		$dir = WSC_Settings::uploads_dir()['basedir'] . '/pbf';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$dest = $dir . '/city-' . $city_id . '-' . wp_generate_password( 6, false ) . '.osm.pbf';
		if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
			return new WP_Error( 'upload_failed', 'Could not save file', [ 'status' => 500 ] );
		}

		try {
			$job_id = WSC_Jobs_Import::start_pbf( $city_id, $dest );
		} catch ( Throwable $e ) {
			if ( file_exists( $dest ) ) {
				@unlink( $dest );
			}
			return new WP_Error( 'pbf_failed', $e->getMessage(), [ 'status' => 500 ] );
		}

		return [ 'job_id' => $job_id ];
	}

	public static function route_upload_pbf( WP_REST_Request $req ) {
		$cid = (int) $req['id'];
		if ( empty( $_FILES['file']['tmp_name'] ) ) {
			return new WP_Error( 'no_file', 'No file uploaded', [ 'status' => 400 ] );
		}
		$r = self::save_pbf_upload_for_city( $cid, $_FILES['file'] );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return rest_ensure_response( $r );
	}

	public static function route_recompute_buffers( WP_REST_Request $req ) {
		$cid = (int) $req['id'];

		// Атомарный lock: исключает гонку при двойном клике.
		$lock_key = 'wsc_lock_city_' . $cid;
		if ( ! WSC_Jobs_Import::acquire_lock( $cid, 'buffers' ) ) {
			return new WP_Error( 'busy', 'A scan or buffer recompute is already running for this city', [ 'status' => 409 ] );
		}
		set_transient( $lock_key, time(), 30 * MINUTE_IN_SECONDS );

		try {
			$job_id = WSC_Jobs_Import::start_recompute_buffers( $cid );
		} catch ( Throwable $e ) {
			WSC_Jobs_Import::release_lock( $cid );
			return new WP_Error( 'buffers_failed', $e->getMessage(), [ 'status' => 500 ] );
		}

		return rest_ensure_response( [
			'job_id'  => $job_id,
			'backend' => WSC_Buffer::backend_available(),
			'message' => 'Buffer recompute scheduled — poll /scan/status for progress',
		] );
	}

	public static function route_city_jobs( WP_REST_Request $req ) {
		$cid = (int) $req['id'];
		$limit = (int) ( $req->get_param( 'limit' ) ?: 50 );
		$rows = WSC_Jobs_Import::list_jobs_for_city( $cid, $limit );
		$out = array_map( static function ( array $r ): array {
			return [
				'id'         => (int) $r['id'],
				'status'     => (string) $r['status'],
				'source'     => (string) $r['source'],
				'total'      => (int) $r['total'],
				'done'       => (int) $r['done'],
				'imported'   => (int) $r['imported'],
				'created_at' => (string) $r['created_at'],
				'updated_at' => (string) $r['updated_at'],
				'log_tail'   => implode( "\n", array_slice( preg_split( "/\r\n|\n|\r/", (string) $r['log'] ), -10 ) ),
			];
		}, $rows );
		return rest_ensure_response( [ 'city_id' => $cid, 'jobs' => $out ] );
	}

	public static function route_city_jobs_delete( WP_REST_Request $req ) {
		$cid = (int) $req['id'];
		$scope = sanitize_key( (string) ( $req->get_param( 'scope' ) ?: 'running' ) );
		$only_running = $scope !== 'all';
		$deleted = WSC_Jobs_Import::delete_jobs_for_city( $cid, $only_running );
		return rest_ensure_response( [ 'city_id' => $cid, 'deleted' => (int) $deleted, 'scope' => $only_running ? 'running' : 'all' ] );
	}

	public static function route_city_jobs_abort( WP_REST_Request $req ) {
		$cid = (int) $req['id'];
		$aborted = WSC_Jobs_Import::abort_jobs_for_city( $cid );
		return rest_ensure_response( [ 'city_id' => $cid, 'aborted' => (int) $aborted ] );
	}

	public static function route_job_abort( WP_REST_Request $req ) {
		$jid = (int) $req['job_id'];
		$ok = WSC_Jobs_Import::abort_job( $jid );
		return rest_ensure_response( [ 'job_id' => $jid, 'aborted' => $ok ? 1 : 0 ] );
	}

	public static function route_job_delete( WP_REST_Request $req ) {
		$jid = (int) $req['job_id'];
		$ok = WSC_Jobs_Import::delete_job( $jid );
		return rest_ensure_response( [ 'job_id' => $jid, 'deleted' => $ok ? 1 : 0 ] );
	}

	public static function route_layer( WP_REST_Request $req ) {
		$cid   = (int) $req['id'];
		$layer = sanitize_key( $req['layer'] );
		if ( ! in_array( $layer, [ 'buildings', 'yards', 'pois', 'landuse', 'roads' ], true ) ) {
			return new WP_Error( 'bad_layer', 'Unknown layer', [ 'status' => 400 ] );
		}

		// Опциональный bbox=w,s,e,n — ограничение выборки прямоугольником вьюпорта.
		// Молчаливый fallback на full-city при битом bbox = DoS-вектор (атакующий шлёт
		// мусор и заставляет сервер строить тяжёлую коллекцию). Поэтому возвращаем 400.
		$bbox = null;
		$bbox_raw = (string) ( $req->get_param( 'bbox' ) ?? '' );
		if ( $bbox_raw !== '' ) {
			$parts = array_map( 'trim', explode( ',', $bbox_raw ) );
			$valid = false;
			if ( count( $parts ) === 4 ) {
				$w = (float) $parts[0]; $s = (float) $parts[1];
				$e = (float) $parts[2]; $n = (float) $parts[3];
				if ( is_finite( $w ) && is_finite( $s ) && is_finite( $e ) && is_finite( $n )
					&& $e > $w && $n > $s
					&& $w >= -180 && $e <= 180 && $s >= -90 && $n <= 90
				) {
					$bbox = [ 'w' => $w, 's' => $s, 'e' => $e, 'n' => $n ];
					$valid = true;
				}
			}
			if ( ! $valid ) {
				return new WP_Error( 'bad_bbox', 'bbox must be "w,s,e,n" with valid finite numbers, e>w, n>s, lon[-180..180], lat[-90..90]', [ 'status' => 400 ] );
			}
		}

		$fc = WSC_MVT::build_layer( $layer, $cid, $bbox );
		$resp = rest_ensure_response( $fc );
		// Браузерный кэш: на повторных pan/zoom те же bbox-параметры реюзаются без сетевого запроса.
		// private + short max-age — даёт скорость без риска отдать устаревшие данные другим клиентам.
		if ( $resp instanceof WP_REST_Response ) {
			$resp->header( 'Cache-Control', 'private, max-age=60' );
		}
		return $resp;
	}

	public static function route_boundary( WP_REST_Request $req ) {
		$cid = (int) $req['id'];
		$res = WSC_Nominatim::get_city_polygon( $cid );
		return rest_ensure_response( $res );
	}

	public static function route_export_geojson( WP_REST_Request $req ) {
		$cid = (int) $req['id'];
		$layer = sanitize_key( (string) $req->get_param( 'layer' ) ) ?: 'buildings';
		$fc = WSC_MVT::build_layer( $layer, $cid );
		$resp = new WP_REST_Response( $fc );
		$resp->header( 'Content-Type', 'application/geo+json' );
		$resp->header( 'Content-Disposition', sprintf( 'attachment; filename="city-%d-%s.geojson"', $cid, $layer ) );
		return $resp;
	}

	public static function route_basemap_tile( WP_REST_Request $req ) {
		$z = (int) $req['z']; $x = (int) $req['x']; $y = (int) $req['y'];
		$file = WSC_MBTiles::get_default_file();
		if ( ! $file ) return new WP_Error( 'no_mbtiles', 'No mbtiles configured', [ 'status' => 404 ] );
		$blob = WSC_MBTiles::get_tile( $file, $z, $x, $y );
		if ( $blob === null ) {
			status_header( 204 );
			return null;
		}
		$meta = WSC_MBTiles::get_metadata( $file );
		$format = $meta['format'] ?? 'pbf';
		header( 'Access-Control-Allow-Origin: *' );
		// MBTiles иммутабельны по {z}/{x}/{y}: содержимое тайла не меняется без переключения файла.
		// immutable + 1 год — браузер больше не дергает сервер при reload карты.
		header( 'Cache-Control: public, max-age=31536000, immutable' );
		if ( $format === 'pbf' ) {
			header( 'Content-Type: application/x-protobuf' );
			header( 'Content-Encoding: gzip' );
		} elseif ( $format === 'png' ) {
			header( 'Content-Type: image/png' );
		} elseif ( in_array( $format, [ 'jpg', 'jpeg' ], true ) ) {
			header( 'Content-Type: image/jpeg' );
		}
		echo $blob;
		exit;
	}

	public static function route_style_json( WP_REST_Request $req ) {
		// Minimal MapLibre style for buildings/yards/pois/landuse from REST GeoJSON sources.
		$cid = (int) $req->get_param( 'city' );
		$tiles = WSC_Settings::get_tiles_source();

		if ( $tiles['basemap'] === 'mbtiles' && WSC_MBTiles::get_default_file() ) {
			$base_sources = [
				'basemap' => [
					'type'   => 'vector',
					'tiles'  => [ rest_url( WSC_REST_NS . '/basemap/{z}/{x}/{y}' ) ],
					'minzoom'=> 0, 'maxzoom' => 14,
				],
			];
			$base_layers  = [
				[ 'id' => 'background', 'type' => 'background', 'paint' => [ 'background-color' => '#f8fafc' ] ],
			];
			$style_url = null;
		} else {
			$base_sources = [];
			$base_layers  = [];
			$style_url = 'https://tiles.openfreemap.org/styles/' . ( $tiles['style'] ?: 'positron' );
		}

		$style = [
			'version' => 8,
			'sources' => array_merge( $base_sources, [
				'wsc-buildings' => [ 'type' => 'geojson', 'data' => rest_url( WSC_REST_NS . '/city/' . $cid . '/layer/buildings' ) ],
				'wsc-yards'     => [ 'type' => 'geojson', 'data' => rest_url( WSC_REST_NS . '/city/' . $cid . '/layer/yards' ) ],
				'wsc-pois'      => [ 'type' => 'geojson', 'data' => rest_url( WSC_REST_NS . '/city/' . $cid . '/layer/pois' ) ],
				'wsc-roads'     => [ 'type' => 'geojson', 'data' => rest_url( WSC_REST_NS . '/city/' . $cid . '/layer/roads' ) ],
				'wsc-landuse'   => [ 'type' => 'geojson', 'data' => rest_url( WSC_REST_NS . '/city/' . $cid . '/layer/landuse' ) ],
			] ),
			'layers'  => array_merge( $base_layers, self::default_layers() ),
			'glyphs'  => 'https://tiles.openfreemap.org/fonts/{fontstack}/{range}.pbf',
		];
		if ( $style_url ) $style['_external_style'] = $style_url;
		return rest_ensure_response( $style );
	}

	public static function default_layers(): array {
		$col = WSC_Categories::colors();
		$cat_match = [ 'match', [ 'get', 'category' ] ];
		foreach ( $col as $k => $v ) { $cat_match[] = $k; $cat_match[] = $v; }
		$cat_match[] = '#94a3b8';

		$road_line_color = [
			'match',
			[ 'get', 'road_class' ],
			'motor',
			'#b91c1c',
			'foot',
			'#15803d',
			'parking',
			'#64748b',
			'#57534e',
		];

		$lu_fill = ( class_exists( 'WSC_Landcover' ) ) ? WSC_Landcover::map_style_fill_match() : '#dcfce7';

		return [
			[
				'id' => 'wsc-landuse-fill', 'type' => 'fill', 'source' => 'wsc-landuse',
				'paint' => [ 'fill-color' => $lu_fill, 'fill-opacity' => 0.52 ],
			],
			[
				'id' => 'wsc-yards-fill', 'type' => 'fill', 'source' => 'wsc-yards',
				'paint' => [ 'fill-color' => '#0ea5e9', 'fill-opacity' => 0.18, 'fill-outline-color' => '#0369a1' ],
			],
			[
				'id'    => 'wsc-roads-parking-fill',
				'type'  => 'fill',
				'source'=> 'wsc-roads',
				'filter'=> [ 'in', [ 'geometry-type' ], [ 'literal', [ 'Polygon', 'MultiPolygon' ] ] ],
				'paint' => [ 'fill-color' => '#94a3b8', 'fill-opacity' => 0.38 ],
			],
			[
				'id'    => 'wsc-roads-line',
				'type'  => 'line',
				'source'=> 'wsc-roads',
				'filter'=> [ 'in', [ 'geometry-type' ], [ 'literal', [ 'LineString', 'MultiLineString' ] ] ],
				'paint' => [
					'line-color'   => $road_line_color,
					'line-width'   => [ 'match', [ 'get', 'road_class' ], 'motor', 3.2, 'foot', 2, 'parking', 2.3, 2 ],
					'line-opacity' => 0.9,
				],
			],
			[
				'id' => 'wsc-buildings-fill', 'type' => 'fill', 'source' => 'wsc-buildings',
				'paint' => [ 'fill-color' => $cat_match, 'fill-opacity' => 0.7, 'fill-outline-color' => '#0f172a' ],
			],
			[
				'id' => 'wsc-buildings-3d', 'type' => 'fill-extrusion', 'source' => 'wsc-buildings',
				'layout' => [ 'visibility' => 'none' ],
				'paint' => [
					'fill-extrusion-color'   => $cat_match,
					'fill-extrusion-height'  => [ 'get', 'height' ],
					'fill-extrusion-opacity' => 0.85,
				],
			],
			[
				'id'     => 'wsc-pois-polygon-fill',
				'type'   => 'fill',
				'source' => 'wsc-pois',
				'filter' => [ 'in', [ 'geometry-type' ], [ 'literal', [ 'Polygon', 'MultiPolygon' ] ] ],
				'paint'  => [
					'fill-color'         => $cat_match,
					'fill-opacity'       => 0.42,
					'fill-outline-color' => '#475569',
				],
			],
			[
				'id' => 'wsc-pois-circle', 'type' => 'circle', 'source' => 'wsc-pois',
				'filter'=> [ 'in', [ 'geometry-type' ], [ 'literal', [ 'Point', 'MultiPoint' ] ] ],
				'paint' => [
					'circle-radius' => 4, 'circle-color' => $cat_match,
					'circle-stroke-color' => '#fff', 'circle-stroke-width' => 1,
				],
			],
		];
	}

	/* ─── Ergonomics ─── */

	/**
	 * GET /building-ergo?osm_type=way&osm_id=12345
	 * Индекс E, оценки по 6 dimensions, раскладка показателей. Перед выдачей всегда
	 * вызывает WSC_Ergo_Bridge::ensure_building_post (создание/актуализация мета индикаторов),
	 * чтобы жилые здания с уже существовавшим постом получали те же расчёты, что дома «с нуля».
	 */
	public static function route_building_ergo( WP_REST_Request $req ) {
		$osm_type = sanitize_key( (string) $req->get_param( 'osm_type' ) );
		$osm_id   = (int) $req->get_param( 'osm_id' );
		if ( ! in_array( $osm_type, [ 'way', 'relation', 'node' ], true ) || $osm_id <= 0 ) {
			return new WP_Error( 'bad_params', 'osm_type/osm_id required', [ 'status' => 400 ] );
		}

		global $wpdb;
		$tb = WSC_Installer::table_buildings();
		$building_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$tb} WHERE osm_type=%s AND osm_id=%d LIMIT 1",
			$osm_type, $osm_id
		) );
		if ( $building_id <= 0 ) {
			return new WP_Error( 'not_found', 'Building not scanned yet', [ 'status' => 404 ] );
		}

		// Жилые здания часто уже имеют пост после авто-синка; без повторного ensure мета показателей
		// остаётся старым (до появления b_* значений по умолчанию), попап противоречиво показывает 1 строку против 3 у свежего дома.
		$post_before = self::find_wsp_building_post( $building_id );
		if ( ! class_exists( 'WSC_Ergo_Bridge' ) ) {
			if ( $post_before <= 0 ) {
				return new WP_Error( 'no_bridge', 'Ergonomics plugin not active', [ 'status' => 503 ] );
			}
			return rest_ensure_response( self::build_ergo_payload( $post_before, $building_id, false ) );
		}

		$post_id = WSC_Ergo_Bridge::ensure_building_post( $building_id );
		if ( $post_id <= 0 ) {
			return new WP_Error( 'ensure_failed', 'Cannot create or sync wsp_building post', [ 'status' => 500 ] );
		}
		$created = $post_before <= 0;

		return rest_ensure_response( self::build_ergo_payload( $post_id, $building_id, $created ) );
	}

	/**
	 * GET /city/{id}/buildings-ergo-list — таблица зданий города с E (0–100) и постом придомовой эргономики при наличии.
	 */
	public static function route_buildings_ergo_list( WP_REST_Request $req ) {
		$cid = (int) $req['id'];
		$post = get_post( $cid );
		if ( ! $post || $post->post_type !== WSCities_CPT::SLUG ) {
			return new WP_Error( 'not_found', 'City not found', [ 'status' => 404 ] );
		}

		$page     = max( 1, (int) $req->get_param( 'page' ) );
		$per_page = (int) $req->get_param( 'per_page' );
		$per_page = max( 1, min( 500, $per_page ?: 100 ) );
		$orderby_raw = strtolower( (string) ( $req->get_param( 'orderby' ) ?: 'id' ) );
		if ( ! in_array( $orderby_raw, [ 'e', 'address', 'id', 'building_id' ], true ) ) {
			$orderby_raw = 'id';
		}
		$orderby = ( 'building_id' === $orderby_raw ) ? 'id' : $orderby_raw;

		$order = strtolower( (string) $req->get_param( 'order' ) ?: 'asc' );
		$order = 'desc' === $order ? 'DESC' : 'ASC';

		global $wpdb;
		$tb = WSC_Installer::table_buildings();
		$pm = $wpdb->postmeta;
		$p  = $wpdb->posts;

		$idx_meta = class_exists( 'WSErgo_CPT' ) ? WSErgo_CPT::META_INDEX : 'wsergo_index';

		if ( 'e' === $orderby ) {
			// Сначала с рассчитанным E, затем без индекса; NULL в конце среди «нет E» через tie-break по id.
			$null_rank = "( CASE WHEN pm_idx.meta_value IS NULL OR pm_idx.meta_value = '' THEN 1 ELSE 0 END )";
			// REPLACE(',', '.') — meta_value может быть локализован с запятой как десятичным разделителем
			// (русские/европейские настройки PHP/JS); CAST без замены вернёт 0 и поломает сортировку.
			$mv        = "REPLACE(pm_idx.meta_value, ',', '.')";
			$e_scalar  = "LEAST( 100.0, GREATEST( 0.0, IF( CAST( {$mv} AS DECIMAL(12,6) ) > 10, CAST( {$mv} AS DECIMAL(12,6) ), CAST( {$mv} AS DECIMAL(12,6) ) * 10.0 ) ) )";
			if ( $order === 'DESC' ) {
				$order_sql = "{$null_rank} ASC, {$e_scalar} DESC, b.id ASC";
			} else {
				$order_sql = "{$null_rank} ASC, {$e_scalar} ASC, b.id ASC";
			}
		} elseif ( 'address' === $orderby ) {
			$order_sql = "b.address {$order}, b.id ASC";
		} else {
			$order_sql = "b.id {$order}";
		}

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tb} WHERE city_id = %d", $cid ) );

		$offset = ( $page - 1 ) * $per_page;
		// JOIN с wp_posts по b.ergo_post_id (PK lookup) валидирует «есть ли опубликованный wsp_building пост».
		// Это полностью убирает derived subquery с GROUP BY по всей wp_postmeta, которая раньше делалась
		// на каждую страницу пагинации.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- таблицы и ORDER BY whitelist.
		$sql = $wpdb->prepare(
			"SELECT b.id AS building_id, b.osm_type, b.osm_id, b.category, b.name, b.address,
				IF(pst.ID IS NULL, 0, b.ergo_post_id) AS post_id,
				pm_idx.meta_value AS idx_raw
			FROM {$tb} b
			LEFT JOIN {$p}  pst    ON pst.ID = b.ergo_post_id AND b.ergo_post_id > 0
			                          AND pst.post_type = 'wsp_building' AND pst.post_status = 'publish'
			LEFT JOIN {$pm} pm_idx ON pm_idx.post_id = pst.ID AND pm_idx.meta_key = %s
			WHERE b.city_id = %d
			ORDER BY {$order_sql}
			LIMIT %d OFFSET %d",
			$idx_meta,
			$cid,
			$per_page,
			$offset
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );

		$out_rows = [];
		foreach ( (array) $rows as $row ) {
			$post_id   = isset( $row['post_id'] ) && $row['post_id'] ? (int) $row['post_id'] : 0;
			$idx_raw   = isset( $row['idx_raw'] ) && $row['idx_raw'] !== '' && $row['idx_raw'] !== null ? (float) str_replace( ',', '.', (string) $row['idx_raw'] ) : null;
			$e_scaled  = null;
			if ( $idx_raw !== null ) {
				$v          = $idx_raw > 10.0 ? $idx_raw : ( $idx_raw * 10.0 );
				$e_scaled = max( 0.0, min( 100.0, round( $v, 1 ) ) );
			}
			unset( $row['idx_raw'] );
			$out_rows[] = [
				'building_id' => (int) $row['building_id'],
				'osm_type'    => (string) $row['osm_type'],
				'osm_id'      => (int) $row['osm_id'],
				'category'    => (string) $row['category'],
				'name'        => (string) $row['name'],
				'address'     => (string) $row['address'],
				'post_id'     => $post_id ?: null,
				'permalink'   => $post_id ? get_permalink( $post_id ) : '',
				'e'           => $e_scaled,
			];
		}

		return rest_ensure_response( [
			'city_id'    => $cid,
			'page'       => $page,
			'per_page'   => $per_page,
			'total'      => $total,
			'total_pages'=> $total > 0 ? (int) ceil( $total / $per_page ) : 0,
			'orderby'    => $orderby,
			'order'      => $order === 'DESC' ? 'desc' : 'asc',
			'buildings'  => $out_rows,
		] );
	}

	/**
	 * POST /city/{id}/ergo-recompute-chunk — пачка ensure_building_post для устранения таймаутов браузера/PHP.
	 */
	public static function route_ergo_recompute_chunk( WP_REST_Request $req ) {
		if ( ! class_exists( 'WSC_Ergo_Bridge' ) ) {
			return new WP_Error( 'no_bridge', 'WorldStat Ergonomics / bridge not active', [ 'status' => 503 ] );
		}
		$cid = (int) $req['id'];
		$post = get_post( $cid );
		if ( ! $post || $post->post_type !== WSCities_CPT::SLUG ) {
			return new WP_Error( 'not_found', 'City not found', [ 'status' => 404 ] );
		}

		$offset = max( 0, absint( $req->get_param( 'offset' ) ) );
		$limit  = absint( $req->get_param( 'limit' ) );
		$limit  = max( 1, min( 50, $limit ?: 35 ) );

		// По умолчанию обрабатываем только жилые: индекс E осмысленен для квартирок и ИЖС,
		// а пускать его на 50K «building=yes» сараев + 645 индустриалок = часами впустую.
		// Параметром ?category=any можно вернуть старое поведение (для отладки/админа).
		$category = (string) ( $req->get_param( 'category' ) ?: 'residential' );
		$cat_clause = '';
		$cat_args   = [];
		if ( $category !== 'any' ) {
			$cat_clause = ' AND category = %s';
			$cat_args[] = $category;
		}

		global $wpdb;
		$tb = WSC_Installer::table_buildings();
		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$tb} WHERE city_id = %d" . $cat_clause,
			array_merge( [ $cid ], $cat_args )
		) );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$tb} WHERE city_id = %d" . $cat_clause . " ORDER BY id ASC LIMIT %d OFFSET %d",
				array_merge( [ $cid ], $cat_args, [ $limit, $offset ] )
			)
		);

		$processed = 0;
		$errors    = [];
		foreach ( (array) $ids as $bid ) {
			$bid = (int) $bid;
			try {
				$pid = WSC_Ergo_Bridge::ensure_building_post( $bid );
				if ( $pid <= 0 && count( $errors ) < 15 ) {
					$errors[] = [
						'building_id' => $bid,
						'message'     => __( 'Не удалось создать/синхронизировать пост здания', 'worldstat-courtyard' ),
					];
				}
			} catch ( Throwable $e ) {
				if ( count( $errors ) < 15 ) {
					$errors[] = [
						'building_id' => $bid,
						'message'     => $e->getMessage(),
					];
				}
			}
			$processed++;
		}

		$next_offset = $offset + $processed;
		$done        = $next_offset >= $total || empty( $ids );

		if ( $done && class_exists( 'WSC_MVT' ) ) {
			WSC_MVT::flush_cache_for_city( $cid );
		}

		return rest_ensure_response( [
			'city_id'     => $cid,
			'total'       => $total,
			'offset'      => $offset,
			'processed'   => $processed,
			'next_offset' => $next_offset,
			'done'        => $done,
			'errors'      => $errors,
		] );
	}

	/**
	 * GET /city/{id}/building/{building_id}/courtyard-contents — POI/landuse в полигоне буфера (как для эргономики).
	 */
	public static function route_courtyard_contents( WP_REST_Request $req ) {
		$cid = (int) $req['id'];
		$bid = (int) $req['building_id'];

		$post = get_post( $cid );
		if ( ! $post || $post->post_type !== WSCities_CPT::SLUG ) {
			return new WP_Error( 'not_found', __( 'Город не найден.', 'worldstat-courtyard' ), [ 'status' => 404 ] );
		}

		if ( ! class_exists( 'WSC_Geom' ) ) {
			return new WP_Error( 'no_geom', 'WSC_Geom not loaded', [ 'status' => 503 ] );
		}

		$b = WSC_Writer::get_building( $bid );
		if ( ! $b ) {
			return new WP_Error( 'not_found', __( 'Здание не найдено.', 'worldstat-courtyard' ), [ 'status' => 404 ] );
		}
		if ( (int) $b['city_id'] !== $cid ) {
			return new WP_Error( 'not_found', __( 'Здание не относится к этому городу.', 'worldstat-courtyard' ), [ 'status' => 404 ] );
		}

		$yard = WSC_Writer::get_yard_by_building( $bid );
		if ( ! $yard ) {
			return rest_ensure_response( [
				'status'       => 'no_yard',
				'message'      => __( 'Буфер придомовой территории ещё не рассчитан. Пересчитайте буферы для города или дождитесь завершения импорта.', 'worldstat-courtyard' ),
				'building_id'  => $bid,
				'yard_polygon' => null,
			] );
		}

		$geo_raw = isset( $yard['geojson'] ) ? trim( (string) $yard['geojson'] ) : '';
		if ( $geo_raw === '' ) {
			return rest_ensure_response( [
				'status'       => 'no_yard',
				'message'      => __( 'Буфер придомовой территории ещё не рассчитан. Пересчитайте буферы для города или дождитесь завершения импорта.', 'worldstat-courtyard' ),
				'building_id'  => $bid,
				'buffer_m'     => (float) ( $yard['buffer_m'] ?? 35 ),
				'yard_polygon' => null,
			] );
		}

		$buf = json_decode( $geo_raw, true );
		if ( ! is_array( $buf ) ) {
			return rest_ensure_response( [
				'status'       => 'no_yard',
				'message'      => __( 'Некорректные данные буфера. Пересчитайте буферы.', 'worldstat-courtyard' ),
				'building_id'  => $bid,
				'yard_polygon' => null,
			] );
		}

		$bbox      = WSC_Parser::bbox_of( $buf );
		$buffer_m  = (float) ( $yard['buffer_m'] ?? 35 );
		$area_m2   = (float) ( $yard['area_m2'] ?? 0 );
		$yard_feat = [
			'type'       => 'Feature',
			'properties' => [ 'building_id' => $bid ],
			'geometry'   => $buf,
		];

		global $wpdb;
		$t_pois          = WSC_Installer::table_pois();
		// Не тянем LONGTEXT geojson: point_in_polygon работает с lat/lng. Якорь для редких
		// LineString-объектов мы посчитаем по centroid (lat/lng) — погрешность в метрах,
		// которая не влияет на принадлежность буферу здания.
		$pois_candidates = $wpdb->get_results( $wpdb->prepare(
			'SELECT category, name, osm_type, osm_id, lat, lng, geom_type FROM `' . esc_sql( $t_pois ) . '` ' .
			'WHERE city_id=%d AND lat BETWEEN %f AND %f AND lng BETWEEN %f AND %f',
			$cid,
			$bbox['s'],
			$bbox['n'],
			$bbox['w'],
			$bbox['e']
		), ARRAY_A );

		$counts = [];
		$inside_full = [];

		foreach ( (array) $pois_candidates as $p ) {
			[ $lng, $lat ] = self::courtyard_poi_anchor_lng_lat( $p );
			if ( ! is_finite( $lng ) || ! is_finite( $lat ) ) {
				continue;
			}
			if ( ! WSC_Geom::point_in_polygon( [ $lng, $lat ], $buf ) ) {
				continue;
			}

			$cat = isset( $p['category'] ) ? (string) $p['category'] : 'other';
			$counts[ $cat ] = ( $counts[ $cat ] ?? 0 ) + 1;

			$inside_full[] = [
				'category'  => $cat,
				'name'      => (string) ( $p['name'] ?? '' ),
				'osm_type'  => (string) ( $p['osm_type'] ?? '' ),
				'osm_id'    => (int) ( $p['osm_id'] ?? 0 ),
				'lat'       => $lat,
				'lng'       => $lng,
				'geom_type' => (string) ( $p['geom_type'] ?? '' ),
			];
		}

		usort(
			$inside_full,
			static function ( array $a, array $b ): int {
				$c = strcmp( $a['category'], $b['category'] );
				if ( $c !== 0 ) {
					return $c;
				}
				return strcmp( mb_strtolower( $a['name'] ), mb_strtolower( $b['name'] ) );
			}
		);

		$max_items    = 400;
		$total_inside = count( $inside_full );
		$list          = array_slice( $inside_full, 0, $max_items );

		/** Геометрии для подсветки на карте при выборе здания (POI точки + полигоны landuse). */
		$hl_poi_max = 650;
		$hl_geo_pois = [];
		$n_hl       = min( count( $inside_full ), $hl_poi_max );
		for ( $hi = 0; $hi < $n_hl; $hi++ ) {
			$ph               = $inside_full[ $hi ];
			$hl_geo_pois[] = [
				'type'       => 'Feature',
				'geometry'   => [
					'type'        => 'Point',
					'coordinates' => [ (float) $ph['lng'], (float) $ph['lat'] ],
				],
				'properties' => [
					'_hl' => 'poi',
					'cat' => (string) ( $ph['category'] ?? 'other' ),
				],
			];
		}

		$hl_land_feats = [];

		$t_land            = WSC_Installer::table_landuse();
		$green_kind_sql    = class_exists( 'WSC_Landcover' )
			? WSC_Landcover::green_zone_sql_in_list()
			: "'" . implode( "','", array_map( 'esc_sql', [ 'park', 'grass', 'recreation_ground', 'playground', 'garden' ] ) ) . "'";
		$t_land_esc      = esc_sql( $t_land );
		// Bbox-фильтр: пересечение прямоугольников через wsc_landuse.bbox_*. Старые записи (нулевой bbox)
		// деградируют в обычное условие — корректность важнее микро-производительности на legacy-данных.
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- IN-список из палитры kind.
		$land_candidates = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT osm_type, osm_id, kind, name, geojson FROM `{$t_land_esc}`
				 WHERE city_id = %d
				   AND kind IN ($green_kind_sql)
				   AND (
				     (bbox_west = 0 AND bbox_east = 0)
				     OR (bbox_east >= %f AND bbox_west <= %f AND bbox_north >= %f AND bbox_south <= %f)
				   )",
				$cid,
				(float) $bbox['w'], (float) $bbox['e'], (float) $bbox['s'], (float) $bbox['n']
			),
			ARRAY_A
		);

		$land_items = [];

		foreach ( (array) $land_candidates as $row ) {
			$gj = json_decode( (string) ( $row['geojson'] ?? '' ), true );
			if ( ! is_array( $gj ) ) {
				continue;
			}

			$b_l = WSC_Parser::bbox_of( $gj );

			if ( ! self::courtyard_bboxes_intersect( $bbox, $b_l ) ) {
				continue;
			}

			$anchor = self::geojson_polygon_representative_lng_lat( $gj );
			if ( ! $anchor ) {
				continue;
			}

			if ( ! WSC_Geom::point_in_polygon( $anchor, $buf ) ) {
				continue;
			}

			$lt = strtolower( (string) ( $gj['type'] ?? '' ) );
			if ( count( $hl_land_feats ) < 140 && ( 'polygon' === $lt || 'multipolygon' === $lt ) ) {
				$hl_land_feats[] = [
					'type'       => 'Feature',
					'geometry'   => $gj,
					'properties' => [
						'_hl' => 'landuse',
						'lk'  => (string) ( $row['kind'] ?? '' ),
					],
				];
			}

			$land_items[] = [
				'kind'     => (string) ( $row['kind'] ?? '' ),
				'name'     => (string) ( $row['name'] ?? '' ),
				'osm_type' => (string) ( $row['osm_type'] ?? '' ),
				'osm_id'   => (int) ( $row['osm_id'] ?? 0 ),
				'lat'      => $anchor[1],
				'lng'      => $anchor[0],
			];
		}

		$max_land   = 120;
		$trunc_land = count( $land_items ) > $max_land;

		usort(
			$land_items,
			static function ( array $a, array $b ): int {
				$c = strcmp( $a['kind'], $b['kind'] );
				if ( $c !== 0 ) {
					return $c;
				}
				return strcmp( mb_strtolower( $a['name'] ), mb_strtolower( $b['name'] ) );
			}
		);
		$list_land = array_slice( $land_items, 0, $max_land );

		$highlight_fc = [
			'type'     => 'FeatureCollection',
			'features' => array_merge( $hl_geo_pois, $hl_land_feats ),
		];

		return rest_ensure_response(
			[
				'status'               => 'ok',
				'building_id'          => $bid,
				'buffer_m'             => $buffer_m,
				'area_m2'              => $area_m2,
				'yard_polygon'        => $yard_feat,
				'highlight_geojson'    => $highlight_fc,
				'counts_by_category'   => $counts,
				'total_pois_inside'    => $total_inside,
				'pois_truncated'       => $total_inside > $max_items,
				'pois_max'             => $max_items,
				'pois'                 => $list,
				'total_landuse_inside' => count( $land_items ),
				'landuse_truncated'    => $trunc_land,
				'landuse'              => $list_land,
			]
		);
	}

	/**
	 * @param array<string, mixed> $p poi row from DB (lat,lng,geojson,geom_type).
	 *
	 * @return float[] [ lng, lat ].
	 */
	private static function courtyard_poi_anchor_lng_lat( array $p ): array {
		$geom_type = strtolower( (string) ( $p['geom_type'] ?? 'Point' ) );
		if ( $geom_type === 'linestring' || $geom_type === 'multilinestring' ) {
			$gj = isset( $p['geojson'] ) ? json_decode( (string) $p['geojson'], true ) : null;
			if ( is_array( $gj ) ) {
				$stype = strtolower( (string) ( $gj['type'] ?? '' ) );
				if ( $stype === 'linestring' ) {
					$ring = $gj['coordinates'] ?? [];
					if ( isset( $ring[0][0], $ring[0][1] ) ) {
						return [ (float) $ring[0][0], (float) $ring[0][1] ];
					}
				} elseif ( $stype === 'multilinestring' ) {
					$lines = $gj['coordinates'] ?? [];
					$f     = $lines[0][0] ?? [];
					if ( isset( $f[0], $f[1] ) ) {
						return [ (float) $f[0], (float) $f[1] ];
					}
				}
			}
		}
		return [ (float) $p['lng'], (float) $p['lat'] ];
	}

	/**
	 * @param float[] $a bbox (s,n,w,e).
	 * @param float[] $b bbox.
	 */
	private static function courtyard_bboxes_intersect( array $a, array $b ): bool {
		return ! ( $a['e'] < $b['w'] || $a['w'] > $b['e'] || $a['n'] < $b['s'] || $a['s'] > $b['n'] );
	}

	/**
	 * Центроид внешнего контура Polygon / первого контура первого полигона MultiPolygon.
	 *
	 * @return float[]|null [ lng, lat ]
	 */
	private static function geojson_polygon_representative_lng_lat( array $gj ): ?array {
		$type = strtolower( (string) ( $gj['type'] ?? '' ) );
		if ( $type === 'polygon' ) {
			$ring = $gj['coordinates'][0] ?? [];
		} elseif ( $type === 'multipolygon' ) {
			// Первый полигон → внешнее кольцо.
			$ring = $gj['coordinates'][0][0] ?? [];
		} else {
			return null;
		}
		if ( count( $ring ) < 3 ) {
			return null;
		}
		$c = WSC_Parser::centroid( $ring );
		if ( empty( $c ) || count( $c ) < 2 ) {
			return null;
		}
		return [ (float) $c[0], (float) $c[1] ];
	}

	private static function find_wsp_building_post( int $building_id ): int {
		$q = get_posts( [
			'post_type'      => 'wsp_building',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => [ [ 'key' => '_wsc_building_id', 'value' => $building_id ] ],
		] );
		return $q ? (int) $q[0] : 0;
	}

	private static function build_ergo_payload( int $post_id, int $building_id, bool $created ): array {
		$meta_key  = class_exists( 'WSErgo_CPT' ) ? WSErgo_CPT::META_INDEX : 'wsergo_index';
		$index_raw = get_post_meta( $post_id, $meta_key, true );
		$index     = ( $index_raw === '' || $index_raw === null ) ? null : (float) $index_raw;
		$e_scaled  = null;
		if ( $index !== null ) {
			$v = $index > 10.0 ? $index : ( $index * 10.0 );
			$e_scaled = max( 0.0, min( 100.0, round( $v, 1 ) ) );
		}

		$scores = class_exists( 'WSErgo_Model' ) ? WSErgo_Model::get_scores_from_post( $post_id ) : [];
		// Приводим оценки к шкале 0–100 для UI.
		$scores_100 = [];
		foreach ( (array) $scores as $k => $v ) {
			$vf = (float) $v;
			$scaled = $vf > 10.0 ? $vf : ( $vf * 10.0 );
			$scores_100[ $k ] = max( 0.0, min( 100.0, round( $scaled, 1 ) ) );
		}

		$labels = class_exists( 'WSErgo_Model' ) ? WSErgo_Model::get_dimension_labels() : [];

		$breakdown = [];
		if ( class_exists( 'WSErgo_Indicators' ) ) {
			$breakdown = WSErgo_Indicators::get_dimension_breakdown_for_post( $post_id );
		}

		return [
			'building_id' => $building_id,
			'post_id'     => $post_id,
			'permalink'   => get_permalink( $post_id ),
			'index'       => $index,
			'e'           => $e_scaled,
			'scores'      => $scores_100,
			'labels'      => $labels,
			'breakdown'   => $breakdown,
			'method'      => (string) get_option( 'wsergo_weighting_method', 'integral' ),
			'version'     => (string) get_option( 'wsergo_methodology_version', '1.2' ),
			'created'     => $created,
		];
	}
}
