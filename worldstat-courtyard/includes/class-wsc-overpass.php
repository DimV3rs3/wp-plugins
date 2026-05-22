<?php
/**
 * Overpass API client.
 *
 * Особенности:
 * - предпроверка `/api/status` (свободные слоты, очередь);
 * - автоматический фолбэк на пул альтернативных эндпоинтов;
 * - уважение `Retry-After`-хедера и текстового сигнала «Slot Available After»;
 * - адаптивный backoff при 429/5xx;
 * - короткий ping-таймаут отдельно от длинного query-таймаута.
 *
 * @package WorldStatCourtyard
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Постоянная ошибка Overpass (HTTP 4xx, кроме 429): синтаксис QL, неверный bbox и т.п.
 * Перебор fallback-эндпоинтов и retries бесполезен — запрос одинаков везде.
 * Выбрасывается из run_ql_on(), не ловится в run_ql() и заставляет process_tile()
 * сразу зафиксировать FINAL FAIL вместо TILE_MAX_RETRY×endpoints зависаний.
 */
class WSC_OverpassPermanentError extends RuntimeException {}

class WSC_Overpass {

	// HTTP-таймаут даём с запасом относительно server-side `[timeout:N]` в QL (см. build_ql):
	// QL=90, HTTP=120 → 30 сек на TLS handshake, DNS, transport jitter и финальный send body.
	// Без запаса HTTP-клиент рвал бы соединение раньше, чем Overpass дочитал бы query.
	const TIMEOUT     = 120;
	const PING_TIMEOUT = 8;
	const MAX_RETRIES = 4;
	const RETRY_BASE  = 2;   // секунды, экспонента
	const MAX_WAIT    = 60;  // потолок ожидания одного backoff-цикла, сек
	const STATUS_CACHE_TTL = 30;

	/**
	 * Резервные эндпоинты на случай, если основной отдал 5xx/429 несколько раз подряд.
	 *
	 * @return string[]
	 */
	public static function fallback_endpoints(): array {
		$default = [
			'https://overpass-api.de/api/interpreter',
			'https://lz4.overpass-api.de/api/interpreter',
			'https://z.overpass-api.de/api/interpreter',
			'https://overpass.kumi.systems/api/interpreter',
			'https://overpass.private.coffee/api/interpreter',
		];
		return apply_filters( 'wsc_overpass_endpoints', $default );
	}

	/**
	 * Преобразует /interpreter URL в /status URL того же сервера.
	 */
	public static function status_url_for( string $endpoint ): string {
		return preg_replace( '#/interpreter/?$#', '/status', rtrim( $endpoint, '/' ) );
	}

	/**
	 * Запрашивает /api/status и парсит число свободных слотов и время ожидания.
	 *
	 * @return array{
	 *   ok: bool,
	 *   endpoint: string,
	 *   http: int,
	 *   slots_free: int,
	 *   slots_total: int,
	 *   running: int,
	 *   wait_s: int,           // сколько секунд ждать до ближайшего слота (0 если есть свободные)
	 *   raw: string,
	 *   error?: string
	 * }
	 */
	public static function get_status( ?string $endpoint = null ): array {
		$endpoint = $endpoint ?: WSC_Settings::get_overpass_endpoint();
		$status_url = self::status_url_for( $endpoint );

		$cache_key = 'wsc_op_status_' . md5( $status_url );
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$resp = wp_remote_get( $status_url, [
			'timeout' => self::PING_TIMEOUT,
			'headers' => [ 'Accept' => 'text/plain' ],
		] );

		$out = [
			'ok'          => false,
			'endpoint'    => $endpoint,
			'status_url'  => $status_url,
			'http'        => 0,
			'slots_free'  => 0,
			'slots_total' => 0,
			'running'     => 0,
			'wait_s'      => 0,
			'raw'         => '',
		];

		if ( is_wp_error( $resp ) ) {
			$out['error'] = 'transport: ' . $resp->get_error_message();
			set_transient( $cache_key, $out, 5 );
			return $out;
		}
		$out['http'] = (int) wp_remote_retrieve_response_code( $resp );
		$body = (string) wp_remote_retrieve_body( $resp );
		$out['raw'] = $body;

		if ( $out['http'] !== 200 || $body === '' ) {
			$out['error'] = 'status http ' . $out['http'];
			set_transient( $cache_key, $out, 5 );
			return $out;
		}

		// Парсинг текстового ответа Overpass /api/status:
		//   Connected as: 12345
		//   Current time: 2026-05-10T22:00:00Z
		//   Rate limit: 6
		//   6 slots available now.        ← или
		//   Slot available after: <ts>, in <N> seconds.
		//   Currently running queries (...): N
		if ( preg_match( '/Rate limit:\s*(\d+)/i', $body, $m ) ) {
			$out['slots_total'] = (int) $m[1];
		}
		if ( preg_match( '/(\d+)\s+slots?\s+available\s+now/i', $body, $m ) ) {
			$out['slots_free'] = (int) $m[1];
		}
		if ( preg_match_all( '/Slot available after:[^,]+,\s*in\s+(-?\d+)\s+seconds/i', $body, $matches ) ) {
			$waits = array_map( 'intval', $matches[1] );
			$waits = array_filter( $waits, static function ( $v ) { return $v > 0; } );
			if ( ! empty( $waits ) ) {
				$out['wait_s'] = (int) min( $waits );
			}
		}
		if ( preg_match( '/Currently running queries[^:]*:\s*(\d+)/i', $body, $m ) ) {
			$out['running'] = (int) $m[1];
		}
		// Если нет явного "slots available now", но есть "Rate limit: N" и нет ожиданий — считаем все свободными.
		if ( $out['slots_total'] > 0 && $out['slots_free'] === 0 && $out['wait_s'] === 0 ) {
			$out['slots_free'] = max( 0, $out['slots_total'] - $out['running'] );
		}

		$out['ok'] = ( $out['slots_free'] > 0 ) || ( $out['wait_s'] > 0 && $out['wait_s'] <= self::MAX_WAIT );

		set_transient( $cache_key, $out, self::STATUS_CACHE_TTL );
		return $out;
	}

	/**
	 * Выбирает лучший эндпоинт из пула: первый с свободным слотом, иначе с минимальным wait_s.
	 *
	 * @return array{endpoint:string, status:array}|null
	 */
	public static function pick_best_endpoint(): ?array {
		$primary = WSC_Settings::get_overpass_endpoint();
		$pool    = self::fallback_endpoints();
		// Primary первым.
		array_unshift( $pool, $primary );
		$pool = array_values( array_unique( $pool ) );

		$best = null;
		foreach ( $pool as $ep ) {
			$st = self::get_status( $ep );
			if ( ! empty( $st['error'] ) ) continue;
			if ( $st['slots_free'] > 0 ) {
				return [ 'endpoint' => $ep, 'status' => $st ];
			}
			if ( $best === null || ( ( $st['wait_s'] ?: PHP_INT_MAX ) < ( $best['status']['wait_s'] ?: PHP_INT_MAX ) ) ) {
				$best = [ 'endpoint' => $ep, 'status' => $st ];
			}
		}
		return $best;
	}

	/**
	 * Pre-flight: гарантирует, что хотя бы один эндпоинт доступен и не перегружен.
	 *
	 * @return array{ok:bool, endpoint:string, message:string, status:array|null}
	 */
	public static function precheck(): array {
		$best = self::pick_best_endpoint();
		if ( $best === null ) {
			return [
				'ok'       => false,
				'endpoint' => '',
				'message'  => __( 'Не удалось связаться ни с одним сервером Overpass. Проверьте интернет-соединение, SSL-сертификаты и брандмауэр.', 'worldstat-courtyard' ),
				'status'   => null,
			];
		}
		$st = $best['status'];
		if ( $st['slots_free'] > 0 ) {
			return [
				'ok'       => true,
				'endpoint' => $best['endpoint'],
				'message'  => sprintf(
					/* translators: 1 host, 2 free slots, 3 total */
					__( 'Overpass готов: %1$s, свободно слотов %2$d/%3$d.', 'worldstat-courtyard' ),
					wp_parse_url( $best['endpoint'], PHP_URL_HOST ),
					$st['slots_free'],
					max( $st['slots_total'], $st['slots_free'] )
				),
				'status'   => $st,
			];
		}
		if ( $st['wait_s'] > 0 && $st['wait_s'] <= self::MAX_WAIT ) {
			return [
				'ok'       => true,
				'endpoint' => $best['endpoint'],
				'message'  => sprintf(
					/* translators: 1 host, 2 wait seconds */
					__( 'Overpass занят, но слот скоро освободится: %1$s, ~%2$d с ожидания.', 'worldstat-courtyard' ),
					wp_parse_url( $best['endpoint'], PHP_URL_HOST ),
					$st['wait_s']
				),
				'status'   => $st,
			];
		}
		return [
			'ok'       => false,
			'endpoint' => $best['endpoint'],
			'message'  => sprintf(
				/* translators: 1 host, 2 wait seconds */
				__( 'Overpass перегружен: %1$s, ожидание ~%2$d с превышает лимит. Попробуйте позже или смените endpoint.', 'worldstat-courtyard' ),
				wp_parse_url( $best['endpoint'], PHP_URL_HOST ),
				$st['wait_s']
			),
			'status'   => $st,
		];
	}

	/**
	 * Запрос Overpass для bbox.
	 *
	 * @param array $bbox [ 's', 'w', 'n', 'e' ] in WGS84
	 * @return array elements (Overpass JSON)
	 */
	public static function query_bbox( array $bbox ): array {
		$s = (float) $bbox['s']; $w = (float) $bbox['w'];
		$n = (float) $bbox['n']; $e = (float) $bbox['e'];

		$ql = self::build_ql( $s, $w, $n, $e );
		return self::run_ql( $ql );
	}

	private static function build_ql( float $s, float $w, float $n, float $e ): string {
		// Фильтр классов дорог можно ослабить/расширить без правки кода.
		$motor_regex = (string) apply_filters(
			'wsc_overpass_highway_motor_regex',
			'^(residential|living_street|service|unclassified|tertiary|secondary|primary|trunk)$'
		);
		$motor_regex = str_replace( [ '"', "'", ';', "\n", "\r" ], '', $motor_regex );

		// Overpass regex — POSIX ERE: `(?:...)` НЕ поддерживается (HTTP 400).
		$landuse_regex = class_exists( 'WSC_Landcover' )
			? WSC_Landcover::overpass_landuse_regex()
			: '^(park|grass|recreation_ground|forest|cemetery|residential|commercial|industrial|retail)$';
		$natural_poly_regex = class_exists( 'WSC_Landcover' )
			? WSC_Landcover::overpass_natural_polygon_regex()
			: '^(wood|grassland|scrub|heath|wetland)$';

		return <<<QL
[out:json][timeout:90][bbox:{$s},{$w},{$n},{$e}];
(
  way["building"];
  relation["building"];
  nwr["amenity"];
  nwr["shop"];
  node["office"];
  node["healthcare"];
  nwr["leisure"];
  way["landuse"~"{$landuse_regex}"];
  relation["landuse"~"{$landuse_regex}"];
  way["natural"~"{$natural_poly_regex}"];
  relation["natural"~"{$natural_poly_regex}"];
  node["natural"="tree"];
  node["highway"="street_lamp"];
  node["emergency"="fire_hydrant"];
  way["highway"~"^(footway|path|pedestrian)$"];
  way["highway"~"{$motor_regex}"];
  way["sidewalk"~"^(both|left|right|yes)$"];
);
out tags geom qt;
QL;
	}

	/**
	 * Выполняет запрос Overpass с авто-фолбэком на резервные эндпоинты.
	 */
	public static function run_ql( string $ql ): array {
		$primary = WSC_Settings::get_overpass_endpoint();
		$pool    = self::fallback_endpoints();
		array_unshift( $pool, $primary );
		$pool    = array_values( array_unique( $pool ) );

		$last_err = '';
		foreach ( $pool as $endpoint ) {
			try {
				return self::run_ql_on( $endpoint, $ql );
			} catch ( WSC_OverpassPermanentError $e ) {
				// QL невалиден — следующий эндпоинт вернёт ту же ошибку, не тратим
				// время на остальные 4 фолбэка и не повторяем по TILE_MAX_RETRY.
				throw $e;
			} catch ( Throwable $e ) {
				$last_err = $e->getMessage();
				continue; // следующий эндпоинт
			}
		}
		throw new RuntimeException( 'Overpass: все эндпоинты недоступны. Последняя ошибка: ' . $last_err );
	}

	/**
	 * Один эндпоинт + цикл повторов с уважением Retry-After / Slot Available.
	 */
	private static function run_ql_on( string $endpoint, string $ql ): array {
		$last_err = '';
		for ( $attempt = 0; $attempt < self::MAX_RETRIES; $attempt++ ) {
			if ( $attempt > 0 ) {
				$backoff = (int) min( self::MAX_WAIT, pow( self::RETRY_BASE, $attempt ) ) + random_int( 0, 2 );
				sleep( $backoff );
			}
			$resp = wp_remote_post( $endpoint, [
				'timeout' => self::TIMEOUT,
				'headers' => [
					'Content-Type' => 'application/x-www-form-urlencoded',
					'Accept'       => 'application/json',
				],
				'body'    => 'data=' . rawurlencode( $ql ),
			] );
			if ( is_wp_error( $resp ) ) {
				$last_err = 'transport: ' . $resp->get_error_message();
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $resp );
			$body = (string) wp_remote_retrieve_body( $resp );

			if ( $code === 200 ) {
				$data = json_decode( $body, true );
				if ( ! is_array( $data ) ) {
					$last_err = 'invalid JSON (length=' . strlen( $body ) . ', preview=' . substr( $body, 0, 80 ) . ')';
					continue;
				}
				return (array) ( $data['elements'] ?? [] );
			}

			// 429 / 5xx — ждём согласно Retry-After или Slot-Available и повторяем.
			if ( in_array( $code, [ 429, 500, 502, 503, 504 ], true ) ) {
				$wait = self::extract_wait_seconds( $resp, $body );
				if ( $wait > 0 && $wait <= self::MAX_WAIT ) {
					$last_err = "HTTP {$code}, wait {$wait}s";
					sleep( $wait );
					continue;
				}
				$last_err = "HTTP {$code}";
				continue;
			}

			// 4xx (400/422 — кривой QL/regex, 404 — endpoint неверен): постоянная
			// ошибка. Тело Overpass обычно содержит «static error: …» — берём первые
			// ~400 символов без HTML, чтобы причина была видна в логе job.
			if ( $code >= 400 && $code < 500 ) {
				$snippet = preg_replace( '/\s+/', ' ', strip_tags( (string) substr( $body, 0, 400 ) ) );
				throw new WSC_OverpassPermanentError(
					"Overpass {$endpoint} HTTP {$code}: " . trim( (string) $snippet )
				);
			}

			// Прочие коды — выходим из цикла этого эндпоинта.
			throw new RuntimeException( "Overpass {$endpoint} returned HTTP {$code}" );
		}
		throw new RuntimeException( "Overpass {$endpoint} exhausted retries: " . $last_err );
	}

	/**
	 * Сколько секунд ждать перед повтором: смотрит Retry-After хедер и текст «Slot available after» в теле.
	 */
	private static function extract_wait_seconds( $resp, string $body ): int {
		$retry_after = wp_remote_retrieve_header( $resp, 'retry-after' );
		if ( $retry_after !== '' ) {
			if ( is_numeric( $retry_after ) ) {
				return (int) $retry_after;
			}
			$ts = strtotime( (string) $retry_after );
			if ( $ts !== false ) {
				return max( 1, $ts - time() );
			}
		}
		if ( preg_match_all( '/Slot available after:[^,]+,\s*in\s+(\d+)\s+seconds/i', $body, $m ) ) {
			$waits = array_map( 'intval', $m[1] );
			$waits = array_filter( $waits, static function ( $v ) { return $v > 0; } );
			if ( ! empty( $waits ) ) return (int) min( $waits );
		}
		return 0;
	}

	/**
	 * Разбивка bbox полигона на квадратные тайлы заданного размера в МЕТРАХ.
	 *
	 * По умолчанию 500×500 м. Шаг в градусах считается отдельно по широте и долготе
	 * (через cos(средней широты)) — иначе на широте 55° «квадрат 0.005°» вытягивался
	 * бы в прямоугольник ~556×319 м.
	 *
	 * Обратная совместимость: если передан скалярный $tile, он трактуется как градусы
	 * (старое поведение), а фильтр `wsc_tile_size_deg` имеет приоритет над метрами.
	 *
	 * @param array      $bbox      [ 's', 'w', 'n', 'e' ]
	 * @param float|null $tile      DEPRECATED: размер тайла в градусах.
	 * @param float|null $tile_m    Размер тайла в метрах (по умолчанию 500).
	 *
	 * @return array<int, array{s:float,w:float,n:float,e:float}>
	 */
	public static function split_bbox( array $bbox, ?float $tile = null, ?float $tile_m = null ): array {
		$s = (float) $bbox['s']; $w = (float) $bbox['w'];
		$n = (float) $bbox['n']; $e = (float) $bbox['e'];

		// Режим в градусах — только если явно вызван со старой сигнатурой
		// или задан фильтр `wsc_tile_size_deg`.
		$deg_override = apply_filters( 'wsc_tile_size_deg', null );
		if ( $tile !== null || $deg_override !== null ) {
			$step = (float) ( $deg_override !== null ? $deg_override : $tile );
			$out  = [];
			for ( $lat = $s; $lat < $n; $lat += $step ) {
				for ( $lng = $w; $lng < $e; $lng += $step ) {
					$out[] = [
						's' => $lat,
						'w' => $lng,
						'n' => min( $n, $lat + $step ),
						'e' => min( $e, $lng + $step ),
					];
				}
			}
			return $out;
		}

		// Метровый режим. 2000 м — компромисс между плотностью покрытия и числом запросов
		// к Overpass: для города 20×20 км получится ~100 тайлов. Меньшие тайлы (например, 500 м)
		// порождают тысячи последовательных HTTP-запросов и сканирование тянется часами.
		// При 2 км и батче 2×2 запрос покрывает 4 км × 4 км (~6 МБ / ~8 с в плотном центре).
		if ( $tile_m === null ) {
			$tile_m = (float) apply_filters( 'wsc_tile_size_m', 2000.0 );
		}
		$tile_m = max( 50.0, $tile_m ); // sanity floor

		$mean_lat   = ( $s + $n ) * 0.5;
		$meters_lat = 111320.0;
		$meters_lng = 111320.0 * max( 0.01, cos( deg2rad( $mean_lat ) ) );

		$dlat = $tile_m / $meters_lat;
		$dlng = $tile_m / $meters_lng;

		$out = [];
		for ( $lat = $s; $lat < $n; $lat += $dlat ) {
			for ( $lng = $w; $lng < $e; $lng += $dlng ) {
				$out[] = [
					's' => $lat,
					'w' => $lng,
					'n' => min( $n, $lat + $dlat ),
					'e' => min( $e, $lng + $dlng ),
				];
			}
		}
		return $out;
	}

	/**
	 * Параметры регулярной сетки тайлов в метрах (когда не задан wsc_tile_size_deg).
	 *
	 * @return array{dlat:float,dlng:float,nrows:int,ncols:int,tile_m:float}|null
	 */
	public static function metres_grid_metrics( array $bbox, ?float $tile_m = null ): ?array {
		if ( apply_filters( 'wsc_tile_size_deg', null ) !== null ) {
			return null;
		}
		if ( $tile_m === null ) {
			$tile_m = (float) apply_filters( 'wsc_tile_size_m', 2000.0 );
		}
		$tile_m = max( 50.0, $tile_m );

		$s = (float) $bbox['s'];
		$w = (float) $bbox['w'];
		$n = (float) $bbox['n'];
		$e = (float) $bbox['e'];

		$mean_lat   = ( $s + $n ) * 0.5;
		$meters_lat = 111320.0;
		$meters_lng = 111320.0 * max( 0.01, cos( deg2rad( $mean_lat ) ) );

		$dlat = $tile_m / $meters_lat;
		$dlng = $tile_m / $meters_lng;

		$nrows = 0;
		for ( $lat = $s; $lat < $n; $lat += $dlat ) {
			$nrows++;
		}
		$ncols = 0;
		for ( $lng = $w; $lng < $e; $lng += $dlng ) {
			$ncols++;
		}

		return [
			'dlat'   => $dlat,
			'dlng'   => $dlng,
			'nrows'  => max( 1, $nrows ),
			'ncols'  => max( 1, $ncols ),
			'tile_m' => $tile_m,
		];
	}

	/**
	 * Объединённый bbox нескольких ячеек (ключи s,w,n,e как у split_bbox).
	 *
	 * @param array<int,array{s:float,w:float,n:float,e:float}> $tiles
	 * @return array{s:float,w:float,n:float,e:float}
	 */
	public static function union_tile_bbox( array $tiles ): array {
		$s = INF;
		$w = INF;
		$n = -INF;
		$e = -INF;
		foreach ( $tiles as $t ) {
			$s = min( $s, (float) $t['s'] );
			$w = min( $w, (float) $t['w'] );
			$n = max( $n, (float) $t['n'] );
			$e = max( $e, (float) $t['e'] );
		}
		return [
			's' => $s === INF ? 0.0 : $s,
			'w' => $w === INF ? 0.0 : $w,
			'n' => $n === -INF ? 0.0 : $n,
			'e' => $e === -INF ? 0.0 : $e,
		];
	}

	/**
	 * Группирует соседние ячейки сетки в один пакет для одного запроса Overpass.
	 * Нужен в синхронном режиме при мелких (500 м) тайлах, чтобы не делать тысячи HTTP-запросов подряд.
	 *
	 * В режиме wsc_tile_size_deg каждая ячейка остаётся отдельным пакетом [ одна ячейка ].
	 *
	 * Фильтры: wsc_overpass_batch_width_tiles, wsc_overpass_batch_height_tiles (целые ≥1, по умолчанию 4).
	 *
	 * @param array<int,array{s:float,w:float,n:float,e:float}> $tiles
	 * @return array<int,array<int,array{s:float,w:float,n:float,e:float}>>
	 */
	public static function group_tiles_for_overpass( array $tiles, array $bbox ): array {
		if ( $tiles === [] ) {
			return [];
		}
		$metrics = self::metres_grid_metrics( $bbox );
		if ( $metrics === null ) {
			return array_map(
				static function ( array $t ): array {
					return [ $t ];
				},
				$tiles
			);
		}

		// 2×2 при tile_size_m=2 км даёт 4 км × 4 км на один запрос Overpass — безопасно
		// и в десятки раз быстрее, чем 4×4 при 500 м (16 км² против 1 км² на запрос).
		// При увеличении tile_size_m имеет смысл уменьшать batch, чтобы один запрос
		// не упирался в 5xx/таймаут на плотном городе.
		$bw = max( 1, (int) apply_filters( 'wsc_overpass_batch_width_tiles', 2 ) );
		$bh = max( 1, (int) apply_filters( 'wsc_overpass_batch_height_tiles', 2 ) );

		$dlat   = $metrics['dlat'];
		$dlng   = $metrics['dlng'];
		$base_s = (float) $bbox['s'];
		$base_w = (float) $bbox['w'];

		$groups = [];
		foreach ( $tiles as $t ) {
			$row = (int) floor( ( (float) $t['s'] - $base_s ) / $dlat + 1e-9 );
			$col = (int) floor( ( (float) $t['w'] - $base_w ) / $dlng + 1e-9 );
			$row = max( 0, $row );
			$col = max( 0, $col );
			$br  = intdiv( $row, $bh );
			$bc  = intdiv( $col, $bw );
			$key = $br . "\t" . $bc;
			$groups[ $key ][] = $t;
		}

		return array_values( $groups );
	}
}
