<?php
/**
 * Jobs: импорт сканов через Action Scheduler (с fallback на WP-Cron).
 *
 * @package WorldStatCourtyard
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSC_Jobs_Import {

	const HOOK_TILE      = 'wsc_import_tile';
	const HOOK_PBF       = 'wsc_import_pbf';
	const HOOK_BUFFERS   = 'wsc_recompute_buffers_chunk';
	// Размер чанка: больше = меньше overhead на AS tick'и (5 SQL на каждый старт),
	// но дольше один тик. 200 укладывается в типичный PHP timeout 30 сек с запасом.
	const TILE_MAX_RETRY = 3;
	const STUCK_AFTER    = 600; // seconds without progress → considered stuck
	const BUFFERS_CHUNK  = 200;

	const HOOK_SWEEP = 'wsc_sweep_stuck_jobs';

	public static function init(): void {
		add_action( self::HOOK_TILE, [ __CLASS__, 'process_tile' ], 10, 4 );
		add_action( self::HOOK_PBF,  [ __CLASS__, 'process_pbf' ], 10, 3 );
		add_action( self::HOOK_BUFFERS, [ __CLASS__, 'process_buffers_chunk' ], 10, 3 );
		add_action( self::HOOK_SWEEP, [ __CLASS__, 'sweep_stuck' ] );

		// Снимаем ранее зарегистрированную AS-периодику: без Action Scheduler она не нужна.
		// sweep_stuck доступен для ручного вызова через REST/админку.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_SWEEP, [], 'wsc' );
		}
	}

	/* ─────────────── start scan ─────────────── */

	/**
	 * Создаёт job-строку и возвращает job_id. Сам цикл по batch'ам сюда НЕ входит —
	 * это делает {@see dispatch_scan_batches()}. Так REST-хендлер может вернуть
	 * job_id браузеру сразу, а тяжёлый scan продолжить уже после `fastcgi_finish_request()`.
	 *
	 * Precheck Overpass здесь НЕ дублируется: его выполняет route_scan_start() и
	 * передаёт сообщение в $precheck_msg для лога.
	 */
	public static function start_scan( int $city_id, ?array $polygon = null, string $mode = 'auto', string $precheck_msg = '' ): int {
		global $wpdb;
		$tj = WSC_Installer::table_jobs();

		// Resolve polygon.
		if ( ! $polygon ) {
			$res = WSC_Nominatim::get_city_polygon( $city_id );
			$polygon = $res['geometry'] ?: null;
		}
		if ( ! $polygon ) throw new RuntimeException( 'Cannot resolve city polygon' );

		$bbox  = WSC_Parser::bbox_of( $polygon );
		$tiles = WSC_Overpass::split_bbox( $bbox );

		$wpdb->insert( $tj, [
			'city_id'    => $city_id,
			'status'     => 'running',
			'source'     => 'overpass',
			'total'      => count( $tiles ),
			'done'       => 0,
			'imported'   => 0,
			'payload'    => wp_json_encode( [ 'mode' => $mode, 'polygon' => $polygon ] ),
			'log'        => $precheck_msg !== '' ? '[precheck] ' . $precheck_msg . "\n" : '',
			'created_at' => current_time( 'mysql', true ),
			'updated_at' => current_time( 'mysql', true ),
		] );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Запускает batch-цикл по уже созданному scan-job. Безопасен к вызову
	 * как из rest-handler'а (после fastcgi_finish_request), так и из shutdown-хука.
	 *
	 * Читает polygon из payload (его положил туда {@see start_scan()}). Группировку
	 * tiles → batches делает здесь же, чтобы не таскать большой список через payload.
	 */
	public static function dispatch_scan_batches( int $job_id ): void {
		global $wpdb;
		$tj = WSC_Installer::table_jobs();

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, city_id, status, payload FROM {$tj} WHERE id=%d",
			$job_id
		), ARRAY_A );
		if ( ! $row || $row['status'] !== 'running' ) {
			return;
		}
		$city_id = (int) $row['city_id'];
		$payload = json_decode( (string) $row['payload'], true );
		$polygon = is_array( $payload ) ? ( $payload['polygon'] ?? null ) : null;
		if ( ! is_array( $polygon ) ) {
			return;
		}

		$bbox    = WSC_Parser::bbox_of( $polygon );
		$tiles   = WSC_Overpass::split_bbox( $bbox );
		$batches = WSC_Overpass::group_tiles_for_overpass( $tiles, $bbox );

		foreach ( $batches as $batch ) {
			self::schedule( 0, self::HOOK_TILE, [ $job_id, $city_id, $batch, 0 ] );
		}
	}

	public static function start_pbf( int $city_id, string $pbf_path ): int {
		global $wpdb;
		$tj = WSC_Installer::table_jobs();
		$wpdb->insert( $tj, [
			'city_id' => $city_id, 'status' => 'running', 'source' => 'pbf',
			'total' => 1, 'done' => 0, 'imported' => 0,
			'payload' => wp_json_encode( [ 'pbf' => $pbf_path ] ),
			'log' => '', 'created_at' => current_time( 'mysql', true ),
			'updated_at' => current_time( 'mysql', true ),
		] );
		$job_id = (int) $wpdb->insert_id;
		self::schedule( 0, self::HOOK_PBF, [ $job_id, $city_id, $pbf_path ] );
		return $job_id;
	}

	/**
	 * Синхронный диспетчер вместо Action Scheduler.
	 *
	 * Action Scheduler требует регулярного триггера WP-Cron, который на локалке (XAMPP)
	 * без посетителей сайта почти не срабатывает — отсюда сканы зависали на 0/N.
	 * Теперь все хуки выполняются в том же HTTP-запросе через do_action(). Это значит:
	 *  - PBF и пересчёт буферов отрабатывают целиком в одном запросе;
	 *  - retry-рекветы тайлов исполняются как обычная рекурсия (до TILE_MAX_RETRY).
	 *
	 * `$delay` сохраняем в сигнатуре, но игнорируем: ранее это была только страховка
	 * от перегруза Overpass, для синхронного режима смысла не имеет.
	 */
	private static function schedule( int $delay, string $hook, array $args ): void {
		@set_time_limit( 0 );
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		do_action_ref_array( $hook, $args );
	}

	/* ─────────────── workers ─────────────── */

	/**
	 * @param array<int,array{s:float,w:float,n:float,e:float}> $tiles одна или несколько ячеек сетки;
	 *        при нескольких — один запрос Overpass по объединённому bbox, импорт по каждой ячейке отдельно.
	 */
	public static function process_tile( int $job_id, int $city_id, array $tiles, int $retry = 0 ): void {
		if ( self::is_job_aborted( $job_id ) || $tiles === [] ) {
			return;
		}

		// В синхронном режиме «реквест задачи при wait_s>0» приводил бы к мгновенной
		// рекурсии (do_action → process_tile → do_action …). Полагаемся на ретраи
		// внутри run_ql_on(): он сам уважает Retry-After / Slot Available.

		try {
			$union    = WSC_Overpass::union_tile_bbox( $tiles );
			$elements = WSC_Overpass::query_bbox( $union );
			$features = WSC_Parser::parse_overpass( $elements );
			// Один ingest на весь batch. Раньше тут был foreach по tiles с повторной
			// фильтрацией featureset'а на bbox каждой sub-ячейки — это давало 16× upsert'ов
			// одних и тех же записей (UNIQUE по osm_type+osm_id всё равно дедуплицирует на
			// MySQL-стороне, но PHP-фильтрация и сетевые транзакции выполнялись каждый раз).
			$count = self::ingest_features( $city_id, $features );
			self::tick( $job_id, $city_id, $count, '', count( $tiles ) );
		} catch ( WSC_OverpassPermanentError $e ) {
			// QL принципиально невалиден (кривой regex/bbox) — повтор бессмыслен,
			// сразу фиксируем тайлы как пройденные, чтобы scan не повис до TILE_MAX_RETRY×endpoints.
			self::tick( $job_id, $city_id, 0, 'PERMANENT FAIL: ' . $e->getMessage(), count( $tiles ) );
		} catch ( Throwable $e ) {
			$msg = $e->getMessage();
			if ( $retry < self::TILE_MAX_RETRY ) {
				// Reschedule with longer backoff; do NOT increment done.
				$delay = ( $retry + 1 ) * 30 + random_int( 0, 10 );
				self::tick_log( $job_id, "[retry {$retry}] {$msg}\n" );
				self::schedule( $delay, self::HOOK_TILE, [ $job_id, $city_id, $tiles, $retry + 1 ] );
				return;
			}
			self::tick( $job_id, $city_id, 0, "FINAL FAIL after {$retry} retries: {$msg}", count( $tiles ) );
		}
		self::maybe_finish( $job_id );
	}

	/**
	 * Кэш для is_job_aborted в рамках одного PHP-запроса: SELECT по PK дешёвый, но при
	 * 100+ батчах синхронного сканирования это всё равно 100 SELECT-ов до Overpass-вызова.
	 * TTL=2 сек даёт реакции abort/delete не хуже текущего polling-интервала фронта.
	 *
	 * @var array<int, array{ts:int, aborted:bool}>
	 */
	private static array $aborted_cache = [];

	private static function is_job_aborted( int $job_id ): bool {
		$now = time();
		$c   = self::$aborted_cache[ $job_id ] ?? null;
		if ( $c !== null && ( $now - $c['ts'] ) < 2 ) {
			return $c['aborted'];
		}

		global $wpdb;
		$tj = WSC_Installer::table_jobs();
		$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$tj} WHERE id=%d", $job_id ) );
		// Orphan AS-action: job row deleted via "Удалить запущенные" → drop silently
		// (otherwise the orphan tile would execute a 30s+ Overpass query for a non-existent job,
		// blocking newly-scheduled tiles behind a long queue).
		if ( $status === null ) {
			self::$aborted_cache[ $job_id ] = [ 'ts' => $now, 'aborted' => true ];
			return true;
		}
		$aborted = $status === 'aborted' || $status === 'done';
		self::$aborted_cache[ $job_id ] = [ 'ts' => $now, 'aborted' => $aborted ];
		return $aborted;
	}

	private static function tick_log( int $job_id, string $line ): void {
		global $wpdb;
		$tj = WSC_Installer::table_jobs();
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$tj} SET log = RIGHT(CONCAT(IFNULL(log,''), %s), 16384), updated_at=%s WHERE id=%d",
			$line, current_time( 'mysql', true ), $job_id
		) );
	}

	public static function process_pbf( int $job_id, int $city_id, string $pbf_path ): void {
		try {
			$res      = WSC_Nominatim::get_city_polygon( $city_id );
			$bbox     = $res['geometry'] ? WSC_Parser::bbox_of( $res['geometry'] ) : null;
			$elements = WSC_PBF::extract_elements( $pbf_path, $bbox );
			$count    = self::ingest_elements( $city_id, $elements );
			self::tick( $job_id, $city_id, $count, '' );
		} catch ( Throwable $e ) {
			self::tick( $job_id, $city_id, 0, $e->getMessage() );
		}
		self::maybe_finish( $job_id );
	}

	private static function ingest_elements( int $city_id, array $elements ): int {
		return self::ingest_features( $city_id, WSC_Parser::parse_overpass( $elements ) );
	}

	/**
	 * Импорт уже распарсенного featureset (после parse_overpass).
	 *
	 * @param array{buildings:array,pois:array,landuse:array,trees:array} $features
	 */
	private static function ingest_features( int $city_id, array $features ): int {
		if ( ! empty( $features['trees'] ) ) {
			foreach ( $features['trees'] as &$rec ) {
				$rec['category'] = WSC_Categories::OTHER;
			}
			unset( $rec );
		}

		$count = 0;
		$count += WSC_Writer::bulk_upsert_buildings( $city_id, $features['buildings'] );
		$count += WSC_Writer::bulk_upsert_pois( $city_id, $features['pois'] );
		$count += WSC_Writer::bulk_upsert_landuse( $city_id, $features['landuse'] );
		if ( ! empty( $features['trees'] ) ) {
			$count += WSC_Writer::bulk_upsert_pois( $city_id, $features['trees'] );
		}

		return $count;
	}

	private static function tick( int $job_id, int $city_id, int $imported, string $err, int $done_delta = 1 ): void {
		global $wpdb;
		$tj = WSC_Installer::table_jobs();
		$done_delta = max( 1, $done_delta );
		// RIGHT(CONCAT(...), 16384) ограничивает log последними 16 КБ. Без этого CONCAT на каждом тайле
		// заставлял MySQL переписывать всё разрастающееся LONGTEXT-поле — O(N²) операций записи на скан.
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$tj}
			 SET done = done + %d,
			     imported = imported + %d,
			     updated_at = %s,
			     log = RIGHT(CONCAT(IFNULL(log,''), %s), 16384)
			 WHERE id = %d",
			$done_delta,
			$imported,
			current_time( 'mysql', true ),
			$err !== '' ? "[err] {$err}\n" : '',
			$job_id
		) );

		// Продлеваем lock на свежие LOCK_TTL — длинные сканы остаются защищены.
		// city_id передаётся через args, без лишнего SELECT.
		if ( $city_id > 0 ) {
			self::touch_lock( $city_id );
			set_transient( 'wsc_lock_city_' . $city_id, time(), self::LOCK_TTL );
		}
	}

	private static function maybe_finish( int $job_id ): void {
		global $wpdb;
		$tj = WSC_Installer::table_jobs();
		// SELECT по узким колонкам: log/payload — LONGTEXT, при синхронном скане
		// (сотни вызовов maybe_finish() подряд) каждый SELECT * тянул бы десятки KB.
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, city_id, status, done, total FROM {$tj} WHERE id=%d",
			$job_id
		), ARRAY_A );
		if ( ! $row ) return;
		if ( in_array( $row['status'], [ 'aborted', 'done' ], true ) ) return;
		if ( (int) $row['done'] >= (int) $row['total'] ) {
			$wpdb->update( $tj, [ 'status' => 'done', 'updated_at' => current_time( 'mysql', true ) ], [ 'id' => $job_id ] );
			WSC_MVT::flush_cache_for_city( (int) $row['city_id'] );
			self::release_lock( (int) $row['city_id'] );
		}
	}

	public static function get_job_status( int $job_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . WSC_Installer::table_jobs() . ' WHERE id=%d', $job_id ), ARRAY_A );
		return $row ?: null;
	}

	public static function get_active_job_for_city( int $city_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM " . WSC_Installer::table_jobs() . " WHERE city_id=%d AND status='running' ORDER BY id DESC LIMIT 1",
			$city_id
		), ARRAY_A );
		return $row ?: null;
	}

	public static function list_jobs_for_city( int $city_id, int $limit = 50 ): array {
		global $wpdb;
		$limit = max( 1, min( 200, $limit ) );
		// SUBSTRING — берём только последние ~4 КБ лога. Достаточно для отображения
		// последних 10 строк, и не тянет всю LONGTEXT-колонку, которая на скане может
		// дорасти до сотен KB на запись.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, city_id, status, source, total, done, imported, created_at, updated_at,
			        SUBSTRING(log, GREATEST(1, CHAR_LENGTH(log) - 4096)) AS log
			 FROM " . WSC_Installer::table_jobs() . " WHERE city_id=%d ORDER BY id DESC LIMIT %d",
			$city_id,
			$limit
		), ARRAY_A );
		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Delete jobs for city. If $only_running true — delete only running/stuck jobs.
	 *
	 * @return int deleted rows
	 */
	public static function delete_jobs_for_city( int $city_id, bool $only_running = true ): int {
		global $wpdb;
		$tj = WSC_Installer::table_jobs();

		// Always release lock.
		self::release_lock( $city_id );
		delete_option( 'wsc_lock_city_' . $city_id );

		// Cancel ТОЛЬКО действия этого города. Раньше as_unschedule_all_actions с пустым args
		// убивал тайлы всех параллельных сканов сразу.
		self::cancel_city_actions( $city_id );

		if ( $only_running ) {
			// Mark as aborted first for traceability.
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$tj} SET status='aborted', updated_at=%s WHERE city_id=%d AND status IN ('running','stuck')",
				current_time( 'mysql', true ),
				$city_id
			) );
			$deleted = $wpdb->query( $wpdb->prepare(
				"DELETE FROM {$tj} WHERE city_id=%d AND status IN ('running','stuck','aborted')",
				$city_id
			) );
			return (int) $deleted;
		}

		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$tj} WHERE city_id=%d", $city_id ) );
		return (int) $deleted;
	}

	/**
	 * Abort all running/stuck jobs for a city (no delete).
	 */
	public static function abort_jobs_for_city( int $city_id ): int {
		global $wpdb;
		$tj = WSC_Installer::table_jobs();
		self::release_lock( $city_id );
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$tj} SET status='aborted', updated_at=%s, log=CONCAT(IFNULL(log,''), %s)
			 WHERE city_id=%d AND status IN ('running','stuck')",
			current_time( 'mysql', true ),
			"[abort-all] aborted by user\n",
			$city_id
		) );
		return (int) $updated;
	}

	public static function delete_job( int $job_id ): bool {
		global $wpdb;
		$tj = WSC_Installer::table_jobs();
		$city_id = self::get_city_id( $job_id );
		// Release lock for safety.
		if ( $city_id > 0 ) self::release_lock( $city_id );
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$tj} WHERE id=%d", $job_id ) );
		return (bool) $deleted;
	}

	public static function abort_job( int $job_id ): bool {
		return self::abort( $job_id );
	}

	/**
	 * Mark jobs as 'stuck' if no progress for STUCK_AFTER seconds.
	 * Caller can then resume or abort them.
	 */
	public static function sweep_stuck(): void {
		global $wpdb;
		$tj = WSC_Installer::table_jobs();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::STUCK_AFTER );
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$tj} SET status='stuck', log=CONCAT(IFNULL(log,''), %s)
			 WHERE status='running' AND done < total AND updated_at < %s",
			"[watchdog] no progress for " . self::STUCK_AFTER . "s, marked as stuck\n",
			$cutoff
		) );
	}

	public static function abort( int $job_id ): bool {
		global $wpdb;
		$tj = WSC_Installer::table_jobs();
		$ok = $wpdb->update( $tj,
			[ 'status' => 'aborted', 'updated_at' => current_time( 'mysql', true ) ],
			[ 'id' => $job_id ]
		);
		$cid_release = self::get_city_id( $job_id );
		if ( $cid_release > 0 ) self::release_lock( $cid_release );
		return (bool) $ok;
	}

	public static function force_finish( int $job_id ): bool {
		global $wpdb;
		$tj  = WSC_Installer::table_jobs();
		$row = self::get_job_status( $job_id );
		if ( ! $row ) return false;
		$wpdb->update( $tj,
			[ 'status' => 'done', 'updated_at' => current_time( 'mysql', true ),
			  'log' => (string) $row['log'] . "[force-finish] marked done at {$row['done']}/{$row['total']}\n" ],
			[ 'id' => $job_id ]
		);
		WSC_MVT::flush_cache_for_city( (int) $row['city_id'] );
		self::release_lock( (int) $row['city_id'] );
		return true;
	}

	/**
	 * Re-fire any tiles that were never completed for a stuck/running job.
	 * Recomputes the tile grid from payload polygon, finds tiles whose bbox
	 * has NO buildings yet in DB, and reschedules them.
	 *
	 * @return int number of tiles rescheduled
	 */
	public static function resume( int $job_id ): int {
		global $wpdb;
		$row = self::get_job_status( $job_id );
		if ( ! $row ) return 0;
		$payload = json_decode( (string) $row['payload'], true );
		$polygon = $payload['polygon'] ?? null;
		if ( ! is_array( $polygon ) ) return 0;

		$bbox    = WSC_Parser::bbox_of( $polygon );
		$tiles   = WSC_Overpass::split_bbox( $bbox );
		$city_id = (int) $row['city_id'];

		// Один SELECT вместо N COUNT(*) на каждый тайл. Раньше большой город (1000+ тайлов)
		// порождал 1000 запросов до начала resume — на больших сетках это десятки секунд.
		$missing = self::filter_missing_tiles( $city_id, $tiles );

		// Reset job to running and recompute total/done so progress makes sense.
		$new_total = (int) $row['done'] + count( $missing );
		$wpdb->update( WSC_Installer::table_jobs(),
			[
				'status'     => 'running',
				'total'      => $new_total,
				'updated_at' => current_time( 'mysql', true ),
				'log'        => (string) $row['log'] . '[resume] rescheduling ' . count( $missing ) . " missing tiles\n",
			],
			[ 'id' => $job_id ]
		);

		$batches = WSC_Overpass::group_tiles_for_overpass( $missing, $bbox );
		foreach ( $batches as $batch ) {
			self::schedule( 0, self::HOOK_TILE, [ $job_id, $city_id, $batch, 0 ] );
		}
		return count( $missing );
	}

	/**
	 * Возвращает только те тайлы, для которых в wsc_buildings ещё нет ни одного здания
	 * с centroid в их bbox. Раньше тут было N+1: на каждый тайл — отдельный SELECT COUNT(*).
	 * Теперь один SELECT тянет все существующие центроиды города и хэшируется в PHP по
	 * row/col текущей сетки. Для города 30×30 км это сводит запросы с ~3600 до 1.
	 *
	 * @param array<int,array{s:float,w:float,n:float,e:float}> $tiles
	 * @return array<int,array{s:float,w:float,n:float,e:float}>
	 */
	private static function filter_missing_tiles( int $city_id, array $tiles ): array {
		if ( $tiles === [] ) return [];
		global $wpdb;
		$tb = WSC_Installer::table_buildings();

		// Метрики сетки нужны, чтобы O(1) сопоставить центроид → ячейка.
		$bbox = [ 's' => INF, 'w' => INF, 'n' => -INF, 'e' => -INF ];
		foreach ( $tiles as $t ) {
			$bbox['s'] = min( $bbox['s'], (float) $t['s'] );
			$bbox['w'] = min( $bbox['w'], (float) $t['w'] );
			$bbox['n'] = max( $bbox['n'], (float) $t['n'] );
			$bbox['e'] = max( $bbox['e'], (float) $t['e'] );
		}
		$metrics = WSC_Overpass::metres_grid_metrics( $bbox );
		if ( $metrics === null ) {
			return $tiles; // deg-режим: дёшево не дедуплицируем — вернём всё.
		}
		$dlat   = $metrics['dlat'];
		$dlng   = $metrics['dlng'];
		$base_s = (float) $bbox['s'];
		$base_w = (float) $bbox['w'];

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT centroid_lat, centroid_lng FROM {$tb}
			 WHERE city_id=%d
			   AND centroid_lat BETWEEN %f AND %f
			   AND centroid_lng BETWEEN %f AND %f",
			$city_id, $bbox['s'], $bbox['n'], $bbox['w'], $bbox['e']
		), ARRAY_A );

		$has_data = [];
		foreach ( (array) $rows as $r ) {
			$lat = (float) $r['centroid_lat'];
			$lng = (float) $r['centroid_lng'];
			$row = (int) floor( ( $lat - $base_s ) / $dlat + 1e-9 );
			$col = (int) floor( ( $lng - $base_w ) / $dlng + 1e-9 );
			$has_data[ $row . "\t" . $col ] = true;
		}

		$missing = [];
		foreach ( $tiles as $t ) {
			$row = (int) floor( ( (float) $t['s'] - $base_s ) / $dlat + 1e-9 );
			$col = (int) floor( ( (float) $t['w'] - $base_w ) / $dlng + 1e-9 );
			if ( ! isset( $has_data[ $row . "\t" . $col ] ) ) {
				$missing[] = $t;
			}
		}
		return $missing;
	}

	const LOCK_TTL = 30 * MINUTE_IN_SECONDS;

	/**
	 * Атомарный CAS-лок. Опирается на UNIQUE option_name в wp_options:
	 * add_option выполняет INSERT, и MySQL вернёт ошибку при duplicate key.
	 * Это устраняет TOCTOU-окно старого `get_transient + set_transient` подхода.
	 *
	 * Если уже существует, но stale (>LOCK_TTL) — пытаемся перехватить (с маленьким
	 * остаточным окном, но это намного безопаснее прежней реализации).
	 *
	 * @return bool true если лок захвачен, false если уже был занят.
	 */
	public static function acquire_lock( int $city_id, string $kind ): bool {
		$key   = 'wsc_lock_city_' . $city_id;
		$value = [ 'kind' => $kind, 'ts' => time() ];

		// autoload=no — лок не должен утяжелять wp_load_alloptions() на каждый запрос.
		if ( add_option( $key, $value, '', 'no' ) ) {
			return true;
		}

		// Перехват зависшего лока: если запись старше LOCK_TTL, считаем мёртвой.
		$existing = get_option( $key );
		$ts = is_array( $existing ) ? (int) ( $existing['ts'] ?? 0 ) : 0;
		if ( $ts > 0 && ( time() - $ts ) > self::LOCK_TTL ) {
			delete_option( $key );
			return (bool) add_option( $key, $value, '', 'no' );
		}
		return false;
	}

	/**
	 * Продление lock’а (вызывается из tick — длинный скан остаётся защищён).
	 */
	public static function touch_lock( int $city_id ): void {
		$key = 'wsc_lock_city_' . $city_id;
		$existing = get_option( $key );
		if ( is_array( $existing ) ) {
			$existing['ts'] = time();
			update_option( $key, $existing, false );
		}
	}

	public static function release_lock( int $city_id ): void {
		$key = 'wsc_lock_city_' . $city_id;
		delete_option( $key );
		delete_transient( $key );
	}

	/**
	 * Отменяет pending AS-действия только для указанного города.
	 * as_unschedule_all_actions с пустым $args отменяет ВСЕ — баг.
	 * Поэтому идём через as_get_scheduled_actions и фильтруем по args[1] (city_id).
	 */
	private static function cancel_city_actions( int $city_id ): void {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) return;
		$hooks = [ self::HOOK_TILE, self::HOOK_PBF, self::HOOK_BUFFERS ];
		foreach ( $hooks as $hook ) {
			$actions = (array) as_get_scheduled_actions( [
				'hook'     => $hook,
				'status'   => 'pending',
				'per_page' => 500,
			], 'ids' );
			foreach ( $actions as $action_id ) {
				$store = ActionScheduler::store();
				$action = $store ? $store->fetch_action( (int) $action_id ) : null;
				if ( ! $action ) continue;
				$args = (array) $action->get_args();
				// Сигнатуры: process_tile(job_id, city_id, tiles[], retry);
				//            process_pbf(job_id, city_id, path);
				//            process_buffers_chunk(job_id, city_id, last_id).
				if ( isset( $args[1] ) && (int) $args[1] === $city_id ) {
					if ( function_exists( 'as_unschedule_action' ) ) {
						as_unschedule_action( $hook, $args, 'wsc' );
					}
				}
			}
		}
	}

	private static function get_city_id( int $job_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT city_id FROM ' . WSC_Installer::table_jobs() . ' WHERE id=%d', $job_id
		) );
	}

	/* ─────────────── buffers (yards) recompute ─────────────── */

	/**
	 * Создаёт фоновую задачу пересчёта буферов (придомовых) и ставит первый чанк.
	 * Заменяет синхронный N+1 цикл в REST: тысячи зданий × тяжёлый PHP-Minkowski
	 * блокировали запрос на десятки секунд.
	 */
	public static function start_recompute_buffers( int $city_id ): int {
		global $wpdb;
		$tj = WSC_Installer::table_jobs();
		$tb = WSC_Installer::table_buildings();
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tb} WHERE city_id=%d", $city_id ) );

		$wpdb->insert( $tj, [
			'city_id'    => $city_id,
			'status'     => 'running',
			'source'     => 'buffers',
			'total'      => max( 1, $total ),
			'done'       => 0,
			'imported'   => 0,
			'payload'    => wp_json_encode( [ 'chunk' => self::BUFFERS_CHUNK, 'backend' => WSC_Buffer::backend_available() ] ),
			'log'        => '[buffers] start, total=' . $total . ', backend=' . WSC_Buffer::backend_available() . "\n",
			'created_at' => current_time( 'mysql', true ),
			'updated_at' => current_time( 'mysql', true ),
		] );
		$job_id = (int) $wpdb->insert_id;

		if ( $total === 0 ) {
			$wpdb->update( $tj,
				[ 'status' => 'done', 'done' => 1, 'updated_at' => current_time( 'mysql', true ) ],
				[ 'id' => $job_id ]
			);
			return $job_id;
		}

		self::schedule( 0, self::HOOK_BUFFERS, [ $job_id, $city_id, 0 ] );
		return $job_id;
	}

	/**
	 * @param int $last_id курсор: обрабатываем здания с id > $last_id. OFFSET-пагинация в больших
	 *                     городах сканировала тысячи строк ради пропуска; cursor-режим всегда O(chunk).
	 */
	public static function process_buffers_chunk( int $job_id, int $city_id, int $last_id ): void {
		if ( self::is_job_aborted( $job_id ) ) return;

		global $wpdb;
		$tb = WSC_Installer::table_buildings();
		$limit = self::BUFFERS_CHUNK;

		// Один SELECT на чанк — вместо N+1 get_building() в старом синхронном цикле.
		// WHERE id > %d использует PRIMARY KEY — стабильный O(chunk) независимо от позиции.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, footprint_geojson, category FROM {$tb}
			 WHERE city_id=%d AND id > %d ORDER BY id LIMIT %d",
			$city_id, $last_id, $limit
		), ARRAY_A );

		// === Bulk-mode WP-suspends + defer compute_index ===
		// Каждый wp_insert_post внутри WSC_Ergo_Bridge::sync_*_post() триггерит чистку
		// persistent object cache, обновление term counts и т.д. Для bulk-импорта
		// это всё бесполезный overhead — откладываем до конца чанка.
		$prev_cache_invalidation = wp_suspend_cache_invalidation( true );
		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );
		// Глушим инвалидацию metric-кэша внешних источников.
		$prev_suspend_ext = false;
		if ( class_exists( 'WSErgo_REST' ) ) {
			$prev_suspend_ext = WSErgo_REST::$suspend_cache_invalidation;
			WSErgo_REST::$suspend_cache_invalidation = true;
		}
		// Defer compute_index: каждое здание копит post_id в WSC_Ergo_Bridge::$pending_index_posts,
		// финальный пересчёт измерений+E одним проходом с warm meta cache — в finally.
		$prev_defer = false;
		if ( class_exists( 'WSC_Ergo_Bridge' ) ) {
			$prev_defer = WSC_Ergo_Bridge::$defer_index_compute;
			WSC_Ergo_Bridge::$defer_index_compute = true;
			// Prefetch POI для bbox объединения зданий ТЕКУЩЕГО чанка одним SELECT-ом.
			// Это убирает 3 SELECT × N зданий = ~300 SQL → 1 SELECT на чанк.
			$bid_list = array_map( fn($r) => (int) $r['id'], (array) $rows );
			WSC_Ergo_Bridge::prefetch_chunk_pois( $city_id, $bid_list );
		}

		$processed = 0;
		$saved = 0;
		$failed = 0;
		$max_id = $last_id;
		try {
			foreach ( (array) $rows as $b ) {
				$processed++;
				$bid = (int) $b['id'];
				if ( $bid > $max_id ) $max_id = $bid;
				try {
					$geo = json_decode( (string) $b['footprint_geojson'], true );
					if ( ! is_array( $geo ) ) continue;
					$radius   = WSC_Settings::get_buffer_for_category( (string) $b['category'] );
					$buffered = WSC_Buffer::compute( $geo, $radius );
					if ( $buffered ) {
						WSC_Writer::save_yard( $bid, $radius, $buffered );
						$saved++;
					}
				} catch ( Throwable $e ) {
					// Одно плохое здание (NaN координаты, self-intersection) не должно валить весь чанк.
					$failed++;
					self::tick_log( $job_id, "[buffer fail bid={$bid}] " . $e->getMessage() . "\n" );
				}
			}
		} finally {
			// Финальный compute_index одним проходом с warm meta cache.
			// До этого все накопленные wsergo_raw_* меты уже записаны batch'ами,
			// flush_deferred_index делает один update_meta_cache → 12 cache-hit get_post_meta'ов.
			if ( class_exists( 'WSC_Ergo_Bridge' ) ) {
				WSC_Ergo_Bridge::flush_deferred_index();
				WSC_Ergo_Bridge::$defer_index_compute = $prev_defer;
				WSC_Ergo_Bridge::clear_chunk_pois_cache();
			}
			// Снимаем все suspends даже если в чанке упало исключение.
			wp_suspend_cache_invalidation( $prev_cache_invalidation );
			wp_defer_term_counting( false );
			wp_defer_comment_counting( false );
			if ( class_exists( 'WSErgo_REST' ) ) {
				WSErgo_REST::$suspend_cache_invalidation = $prev_suspend_ext;
				WSErgo_REST::flush_pending_metric_cache();
			}
			// Сбрасываем WP runtime object cache: за чанк ~200 зданий он раздувается
			// до десятков MB postmeta и замедляет следующие лукапы. WP 6.0+.
			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		}

		// Прогресс: считаем done в реальных «обработанных», не в чанках.
		$wpdb->query( $wpdb->prepare(
			"UPDATE " . WSC_Installer::table_jobs() . "
			 SET done = LEAST(total, done + %d), imported = imported + %d, updated_at = %s
			 WHERE id = %d",
			$processed, $saved, current_time( 'mysql', true ), $job_id
		) );

		// Продлеваем lock, чтобы он не истёк на длинных пересчётах.
		self::touch_lock( $city_id );
		set_transient( 'wsc_lock_city_' . $city_id, time(), self::LOCK_TTL );

		if ( $processed === $limit ) {
			// Возможно, ещё есть здания — планируем следующий чанк сразу.
			self::schedule( 0, self::HOOK_BUFFERS, [ $job_id, $city_id, $max_id ] );
			return;
		}

		// Дошли до конца — финализируем.
		$wpdb->update( WSC_Installer::table_jobs(),
			[ 'status' => 'done', 'updated_at' => current_time( 'mysql', true ) ],
			[ 'id' => $job_id ]
		);
		WSC_MVT::flush_cache_for_city( $city_id );
		self::release_lock( $city_id );
	}
}

WSC_Jobs_Import::init();
