<?php
/**
 * Ergo Bridge: автосоздание wsp_yard для жилых зданий + регистрация POI-индикаторов
 * + запись wsergo_raw_poi_*_within на основании буфера и POI/landuse внутри.
 *
 * @package WorldStatCourtyard
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSC_Ergo_Bridge {

	const POI_INDICATORS = [
		// id => [ label, dimension ] — реальные ключи из WSErgo_Model::DIMENSION_KEYS
		'poi_education_within'   => [ 'POI: Образование рядом',     'functionality' ],
		'poi_healthcare_within'  => [ 'POI: Медицина рядом',         'functionality' ],
		'poi_shop_within'        => [ 'POI: Магазины рядом',         'functionality' ],
		'poi_sport_within'       => [ 'POI: Спорт/досуг рядом',       'comfort' ],
		'poi_office_within'      => [ 'POI: Офисы рядом',             'functionality' ],
		'poi_industrial_within'  => [ 'POI: Индустрия рядом',         'safety' ],
		'park_area_within'       => [ 'Зелень в буфере, % приблиз.',           'comfort' ],
		'tree_count_within'      => [ 'Деревья рядом',                'comfort' ],
	];

	/**
	 * Map between OUR 8 categories and the POI indicator that counts.
	 */
	const CAT_TO_POI = [
		'education'     => 'poi_education_within',
		'healthcare'    => 'poi_healthcare_within',
		'commercial'    => 'poi_shop_within',
		'sport_leisure' => 'poi_sport_within',
		'office'        => 'poi_office_within',
		'industrial'    => 'poi_industrial_within',
	];

	/**
	 * Классы автодорог для метрики b_motor_way_in_yard (пересечение линии с буфером).
	 */
	private const MOTOR_HW = [ 'residential', 'living_street', 'service', 'unclassified', 'tertiary', 'secondary', 'primary', 'trunk' ];

	/** Пешеходные highway для b_sidewalk_width_m при пересечении линии с буфером. */
	private const FOOT_HW = [ 'footway', 'path', 'pedestrian' ];

	/**
	 * Compute buffer + persist for any newly imported building.
	 */
	public static function on_building_imported( int $building_id, array $row ): void {
		// Compute buffer for ALL buildings (so map can show buffers for any category).
		$geo = json_decode( (string) $row['footprint_geojson'], true );
		if ( ! is_array( $geo ) ) return;

		$radius = WSC_Settings::get_buffer_for_category( (string) $row['category'] );

		// Cheap lock against parallel writes for the same building.
		$lock = 'wsc_buf_' . $building_id;
		if ( get_transient( $lock ) ) return;
		set_transient( $lock, 1, 60 );

		try {
			$buf = WSC_Buffer::compute( $geo, $radius );
			if ( $buf ) WSC_Writer::save_yard( $building_id, $radius, $buf );
		} finally {
			delete_transient( $lock );
		}
	}

	/**
	 * After a new buffer is computed: if building is residential, sync wsp_yard and indicators.
	 * Для остальных категорий — пропускаем (их синк инициируется кликом на карте через ensure_building_post).
	 */
	public static function on_buffer_recomputed( int $building_id, int $yard_row_id ): void {
		$b = WSC_Writer::get_building( $building_id );
		if ( ! $b ) return;
		if ( ( $b['category'] ?? '' ) !== WSC_Categories::RESIDENTIAL ) return;
		self::sync_full( $b, $yard_row_id );
	}

	/**
	 * Полный синк wsp_building/wsp_yard + индикаторы + расчёт E.
	 * Безопасно вызывать для любой категории — гейт residential снят.
	 * Возвращает ID wsp_building поста (0 если не удалось).
	 */
	public static function ensure_building_post( int $building_id ): int {
		$b = WSC_Writer::get_building( $building_id );
		if ( ! $b ) return 0;

		// Гарантируем, что у здания есть yard-запись (буфер). Если нет — считаем и сохраняем.
		$yard = WSC_Writer::get_yard_by_building( $building_id );
		if ( ! $yard ) {
			$geo = json_decode( (string) $b['footprint_geojson'], true );
			if ( is_array( $geo ) ) {
				$radius = WSC_Settings::get_buffer_for_category( (string) $b['category'] );
				try {
					$buf = WSC_Buffer::compute( $geo, $radius );
					if ( $buf ) {
						// save_yard вызовет wsc_buffer_recomputed → on_buffer_recomputed,
						// который для нежилых сразу выходит, поэтому fall-through к sync_full ниже.
						WSC_Writer::save_yard( $building_id, $radius, $buf );
						$yard = WSC_Writer::get_yard_by_building( $building_id );
					}
				} catch ( Throwable $e ) {
					// При невозможности посчитать буфер — продолжаем без него (индикаторы радиуса будут пустые).
				}
			}
		}

		$yard_row_id = $yard ? (int) $yard['id'] : 0;
		$ids = self::sync_full( $b, $yard_row_id );
		return (int) ( $ids['building_post_id'] ?? 0 );
	}

	/**
	 * Внутренний синк (общий код для on_buffer_recomputed и ensure_building_post).
	 * Возвращает [ 'yard_post_id' => int, 'building_post_id' => int ].
	 */
	private static function sync_full( array $b, int $yard_row_id ): array {
		if ( ! class_exists( 'WSErgo_CPT' ) || ! class_exists( 'WSErgo_Indicators' ) ) {
			return [ 'yard_post_id' => 0, 'building_post_id' => 0 ];
		}

		self::ensure_indicators_registered();
		if ( class_exists( 'WSErgo_Building_Indicators' ) ) {
			WSErgo_Building_Indicators::register();
		}

		$building_id = (int) $b['id'];

		// ОДИН SELECT yard для всего sync_full — раньше было 3 SELECT (sync_yard_post twice +
		// write_poi_indicators). Передаём через параметры.
		$yard_row = WSC_Writer::get_yard_by_building( $building_id );

		// Объединяем все meta-операции одного здания в ОДНУ DB-транзакцию.
		// Вложенные flush_*_meta_batch видят tx_depth > 0 и не открывают свою START/COMMIT.
		// Это режет 2 транзакции/здание в одну (~3 мс/здание экономия).
		self::begin_tx();
		try {
			// === Фаза 1: создание/обновление wsp_yard и wsp_building постов + их мета ===
			self::begin_meta_batch();
			try {
				$yard_post_id     = self::sync_yard_post( $building_id, $b, $yard_row );
				$building_post_id = self::sync_building_post( $building_id, $b );

				if ( $yard_row_id > 0 && $yard_post_id > 0 ) {
					WSC_Writer::set_yard_post( $yard_row_id, $yard_post_id );
				}

				if ( $yard_post_id && $building_post_id ) {
					self::write_post_meta_batched( $yard_post_id, '_wsergo_building_post_id', $building_post_id );
					self::write_post_meta_batched( $building_post_id, '_wsergo_yard_post_id', $yard_post_id );
				}
			} finally {
				self::flush_meta_batch();
			}

			// === Фаза 2: POI / индикаторы / compute_index ===
			self::write_poi_indicators( $yard_post_id, $building_post_id, $building_id, $yard_row, $b );

			self::commit_tx();
		} catch ( \Throwable $e ) {
			self::rollback_tx();
			throw $e;
		}

		return [ 'yard_post_id' => $yard_post_id ?? 0, 'building_post_id' => $building_post_id ?? 0 ];
	}

	/**
	 * Создать/обновить wsp_building пост, привязанный к OSM-зданию через мету _wsc_building_id.
	 * Вызывается рядом с sync_yard_post() на хук wsc_buffer_recomputed для residential-зданий.
	 */
	public static function sync_building_post( int $building_id, array $b ): int {
		// Быстрый lookup через денормализованный wsc_buildings.ergo_post_id.
		// get_post check убран: direct_upsert_post при UPDATE 0 rows автоматически fall through
		// к INSERT, поэтому stale ergo_post_id безопасен.
		$existing = (int) ( $b['ergo_post_id'] ?? 0 );

		// Fallback на meta_query для случая когда ergo_post_id=0 но wsp_building пост существует
		// (миграция со старой версии без денормализации).
		if ( $existing === 0 ) {
			$found_ids = get_posts( [
				'post_type'      => WSErgo_CPT::SLUG_BUILDING,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => [
					[ 'key' => '_wsc_building_id', 'value' => $building_id ],
				],
			] );
			$existing = ! empty( $found_ids ) ? (int) $found_ids[0] : 0;
		}

		$title = $b['address'] !== '' ? $b['address'] : ( $b['name'] !== '' ? $b['name'] : 'Building #' . $b['osm_id'] );

		// Прямой wpdb upsert — обходит wp_insert_post/wp_update_post (save_post hooks,
		// revisions, term_count). Те хуки и так подавлены через $suspend_autorecalc.
		$post_id = self::direct_upsert_post( WSErgo_CPT::SLUG_BUILDING, $existing, $title );

		if ( ! $post_id ) return 0;

		// В batch-mode write_post_meta_batched копит мету для общего bulk-flush; иначе пишет сразу.
		self::write_post_meta_batched( $post_id, '_wsc_building_id', $building_id );
		self::write_post_meta_batched( $post_id, WSErgo_CPT::META_LAT, (float) $b['centroid_lat'] );
		self::write_post_meta_batched( $post_id, WSErgo_CPT::META_LNG, (float) $b['centroid_lng'] );
		self::write_post_meta_batched( $post_id, WSErgo_CPT::META_ADDRESS, (string) $b['address'] );

		$footprint = json_decode( (string) $b['footprint_geojson'], true );
		if ( is_array( $footprint ) ) {
			self::write_post_meta_batched( $post_id, WSErgo_CPT::META_GEOJSON, wp_json_encode( $footprint ) );
		}

		// Совместимость со слоем данных эргономики (как у yard).
		self::write_post_meta_batched( $post_id, WSC_Writer::META_CITY_ID, (int) $b['city_id'] );
		self::write_post_meta_batched( $post_id, WSC_Writer::META_ENTITY_TYPE, 'building' );
		self::write_post_meta_batched( $post_id, WSC_Writer::META_ADDRESS_FULL, (string) $b['address'] );
		self::write_post_meta_batched( $post_id, WSC_Writer::META_STATUS, 'imported' );

		// Денормализация: убирает тройной JOIN с wp_postmeta в route_ergo_bubbles / route_buildings_ergo_list.
		WSC_Writer::set_ergo_post_id( $building_id, (int) $post_id );

		return (int) $post_id;
	}

	/**
	 * @param array<string,mixed>|null $yard_row Опционально: уже подгруженная строка wsc_yards
	 *                                            (post_id, geojson). Это убирает 2 SELECT'а из sync_yard_post:
	 *                                            один для post_id и один для geojson. На bulk-recompute
	 *                                            экономит ~5мс/здание.
	 */
	private static function sync_yard_post( int $building_id, array $b, ?array $yard_row = null ): int {
		$existing = $yard_row !== null
			? (int) ( $yard_row['post_id'] ?? 0 )
			: (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare(
				"SELECT post_id FROM " . WSC_Installer::table_yards() . " WHERE building_id=%d",
				$building_id
			) );
		// get_post check убран: direct_upsert_post при UPDATE 0 rows автоматически fall through
		// к INSERT, поэтому stale ergo_post_id / yard.post_id безопасны.

		// Fallback на meta_query для случая, когда wsc_yards.post_id не выставлен,
		// но wsp_yard пост существует с мета _wsc_building_id (миграция со старой версии).
		if ( $existing === 0 && $yard_row === null ) {
			$found_ids = get_posts( [
				'post_type' => WSErgo_CPT::SLUG_YARD, 'post_status' => 'any', 'posts_per_page' => 1,
				'fields' => 'ids',
				'meta_query' => [
					[ 'key' => '_wsc_building_id', 'value' => $building_id ],
				],
			] );
			$existing = ! empty( $found_ids ) ? (int) $found_ids[0] : 0;
		}

		$title = $b['address'] !== '' ? $b['address'] : ( $b['name'] !== '' ? $b['name'] : 'Building #' . $b['osm_id'] );

		$post_id = self::direct_upsert_post( WSErgo_CPT::SLUG_YARD, $existing, $title );

		if ( ! $post_id ) return 0;

		// Используем переданный yard_row или загружаем (legacy путь).
		$yard = $yard_row !== null ? $yard_row : WSC_Writer::get_yard_by_building( $building_id );
		$geom = $yard ? json_decode( (string) $yard['geojson'], true ) : null;

		self::write_post_meta_batched( $post_id, '_wsc_building_id', $building_id );
		self::write_post_meta_batched( $post_id, WSErgo_CPT::META_LAT, (float) $b['centroid_lat'] );
		self::write_post_meta_batched( $post_id, WSErgo_CPT::META_LNG, (float) $b['centroid_lng'] );
		if ( is_array( $geom ) ) self::write_post_meta_batched( $post_id, WSErgo_CPT::META_GEOJSON, wp_json_encode( $geom ) );

		// Compatibility fields used by ergo data layer.
		self::write_post_meta_batched( $post_id, WSC_Writer::META_CITY_ID, (int) $b['city_id'] );
		self::write_post_meta_batched( $post_id, WSC_Writer::META_ENTITY_TYPE, 'building' );
		self::write_post_meta_batched( $post_id, WSC_Writer::META_ADDRESS_FULL, (string) $b['address'] );
		self::write_post_meta_batched( $post_id, WSC_Writer::META_STATUS, 'imported' );

		return (int) $post_id;
	}

	/**
	 * Запись POI-индикаторов и b_*-индикаторов на оба поста (yard + building).
	 *
	 * @param int                       $yard_post_id     ID wsp_yard поста (может быть 0).
	 * @param int                       $building_post_id ID wsp_building поста (может быть 0).
	 * @param int                       $building_id      ID OSM-здания в wsc_buildings.
	 * @param array<string,mixed>|null  $yard_row         Опционально: pre-loaded wsc_yards row.
	 * @param array<string,mixed>|null  $building_row     Опционально: pre-loaded wsc_buildings row.
	 *                                                   Передача избегает 2 SELECT'а на здание.
	 */
	private static function write_poi_indicators( int $yard_post_id, int $building_post_id, int $building_id, ?array $yard_row = null, ?array $building_row = null ): void {
		$yard = $yard_row !== null ? $yard_row : WSC_Writer::get_yard_by_building( $building_id );
		if ( ! $yard ) return;
		$buf = json_decode( (string) $yard['geojson'], true );
		if ( ! is_array( $buf ) ) return;
		$bbox = WSC_Parser::bbox_of( $buf );
		$b = $building_row !== null ? $building_row : WSC_Writer::get_building( $building_id );
		if ( ! $b ) return;
		$city_id = (int) $b['city_id'];
		$blat    = (float) $b['centroid_lat'];
		$blng    = (float) $b['centroid_lng'];

		$qbbox = self::expand_bbox_meters( $bbox, 280.0 );

		global $wpdb;
		// Сначала пробуем chunk-scope cache (prefetch_chunk_pois). Если прогрет и покрывает —
		// фильтруем in-memory без SQL. Иначе legacy: SELECT по bbox+280м с индексом (city_id, lat, lng).
		$pois = self::get_chunk_pois_for_bbox( $city_id, $qbbox );
		if ( $pois === null ) {
			$pois = $wpdb->get_results( $wpdb->prepare(
				'SELECT category, lat, lng, geojson, geom_type, tags_json FROM ' . WSC_Installer::table_pois() .
				' WHERE city_id=%d AND lat>=%f AND lat<=%f AND lng>=%f AND lng<=%f',
				$city_id, $qbbox['s'], $qbbox['n'], $qbbox['w'], $qbbox['e']
			), ARRAY_A );
		}

		$motor_in_yard       = false;
		$parking_area_in_yard = false;

		// --- Старые POI-счётчики (категорийные). ---
		$counts = array_fill_keys( array_keys( self::POI_INDICATORS ), 0 );
		// --- Новые b_*-метрики. ---
		$min_dist = [
			'b_waste_dist_m'    => INF,
			'b_parking_dist_m'  => INF,
			'b_hydrant_dist_m'  => INF,
		];
		$flags = [
			'b_drinking_water_present' => 0,
			'b_ev_charging_present'    => 0,
			'b_playground_present'     => 0,
		];
		$bench_count = 0;
		$bike_count  = 0;
		$tree_count  = 0;
		$sw_widths   = [];

		foreach ( (array) $pois as $p ) {
			$plng = (float) $p['lng'];
			$plat = (float) $p['lat'];
			$tags = json_decode( (string) ( $p['tags_json'] ?? '' ), true );
			$tags = is_array( $tags ) ? $tags : [];
			$geo  = json_decode( (string) ( $p['geojson'] ?? '' ), true );
			$geo  = is_array( $geo ) ? $geo : [ 'type' => 'Point', 'coordinates' => [ $plng, $plat ] ];
			$gtyp = strtolower( (string) ( $geo['type'] ?? ( $p['geom_type'] ?? 'Point' ) ) );

			$pt     = [ $plng, $plat ];
			$inside = WSC_Geom::point_in_polygon( $pt, $buf );

			$amenity   = (string) ( $tags['amenity'] ?? '' );
			$emergency = (string) ( $tags['emergency'] ?? '' );
			$leisure   = (string) ( $tags['leisure'] ?? '' );
			$highway   = (string) ( $tags['highway'] ?? '' );
			$sidewalk  = (string) ( $tags['sidewalk'] ?? '' );
			$sw_ok     = in_array( $sidewalk, [ 'both', 'left', 'right', 'yes' ], true );

			// Полилинии / полигоны: пересечение с буфером (дороги через двор, парковки-площади).
			if ( in_array( $gtyp, [ 'linestring', 'multilinestring' ], true ) && class_exists( 'WSC_Geom' ) ) {
				if ( WSC_Geom::line_geo_intersects_polygon( $geo, $buf ) ) {
					if ( $highway && in_array( $highway, self::MOTOR_HW, true ) ) {
						$motor_in_yard = true;
					}
					$is_foot_hw = $highway && in_array( $highway, self::FOOT_HW, true );
					if ( $is_foot_hw || ( $sw_ok && $highway === '' ) ) {
						if ( isset( $tags['_wsc_sidewalk_width_m'] ) && is_numeric( $tags['_wsc_sidewalk_width_m'] ) ) {
							$sw_widths[] = (float) $tags['_wsc_sidewalk_width_m'];
						} else {
							$sw_widths[] = 1.0;
						}
					}
				}
			}
			if ( in_array( $gtyp, [ 'polygon', 'multipolygon' ], true ) && class_exists( 'WSC_Geom' ) && $amenity === 'parking' ) {
				if ( WSC_Geom::polygon_geometry_intersects( $geo, $buf ) ) {
					$parking_area_in_yard = true;
				}
			}

			// Дистанции: ближайшее расстояние от центроида здания до геометрии (касание полигона/линии).
			$is_hydrant = ( $amenity === 'fire_hydrant' ) || ( $emergency === 'fire_hydrant' );
			$is_waste   = in_array( $amenity, [ 'waste_basket', 'recycling', 'waste_disposal' ], true );
			$is_parking = ( $amenity === 'parking' );
			if ( $is_hydrant || $is_waste || $is_parking ) {
				$d = INF;
				if ( class_exists( 'WSC_Geom' ) ) {
					$d = WSC_Geom::min_distance_point_to_geometry_m( [ $blng, $blat ], $geo );
				}
				if ( ! is_finite( $d ) || INF === $d ) {
					$d = self::haversine_m( $blat, $blng, $plat, $plng );
				}
				if ( $is_waste && $d < $min_dist['b_waste_dist_m'] ) {
					$min_dist['b_waste_dist_m'] = $d;
				}
				if ( $is_parking && $d < $min_dist['b_parking_dist_m'] ) {
					$min_dist['b_parking_dist_m'] = $d;
				}
				if ( $is_hydrant && $d < $min_dist['b_hydrant_dist_m'] ) {
					$min_dist['b_hydrant_dist_m'] = $d;
				}
			}

			$in_zone = $inside;
			if ( ! $in_zone && in_array( $gtyp, [ 'polygon', 'multipolygon' ], true ) && class_exists( 'WSC_Geom' ) ) {
				$in_zone = WSC_Geom::polygon_geometry_intersects( $geo, $buf );
			}
			if ( ! $in_zone ) {
				continue;
			}

			$ind = self::CAT_TO_POI[ $p['category'] ] ?? null;
			if ( $ind ) $counts[ $ind ]++;

			if ( $amenity === 'bench' )                      $bench_count++;
			if ( $amenity === 'bicycle_parking' )            $bike_count++;
			if ( $amenity === 'charging_station' )           $flags['b_ev_charging_present'] = 1;
			if ( in_array( $amenity, [ 'drinking_water', 'fountain' ], true ) ) {
				$flags['b_drinking_water_present'] = 1;
			}
			if ( $leisure === 'playground' )                 $flags['b_playground_present'] = 1;

			// Точечный POI: пешеходная ширина по центроиду (линии уже учтены по пересечению выше).
			if ( ! in_array( $gtyp, [ 'linestring', 'multilinestring' ], true )
				&& in_array( $highway, self::FOOT_HW, true ) ) {
				if ( isset( $tags['_wsc_sidewalk_width_m'] ) && is_numeric( $tags['_wsc_sidewalk_width_m'] ) ) {
					$sw_widths[] = (float) $tags['_wsc_sidewalk_width_m'];
				} else {
					$sw_widths[] = 1.0;
				}
			}
		}

		// Гидрант: расширенная зона до 200 м (может быть за буфером ~35 м).
		$delta_lat = 250.0 / 111000.0; // ~250 м в градусах широты
		$cos_lat   = max( 0.1, cos( deg2rad( $blat ) ) );
		$delta_lng = 250.0 / ( 111000.0 * $cos_lat );
		$ext_bbox  = [
			's' => $bbox['s'] - $delta_lat,
			'n' => $bbox['n'] + $delta_lat,
			'w' => $bbox['w'] - $delta_lng,
			'e' => $bbox['e'] + $delta_lng,
		];
		// Гидранты: пробуем chunk cache, fallback на SELECT.
		$hyd_from_cache = self::get_chunk_pois_for_bbox( $city_id, $ext_bbox );
		if ( $hyd_from_cache !== null ) {
			foreach ( $hyd_from_cache as $p ) {
				$tj = (string) ( $p['tags_json'] ?? '' );
				if ( strpos( $tj, '"amenity":"fire_hydrant"' ) === false
				  && strpos( $tj, '"emergency":"fire_hydrant"' ) === false ) continue;
				$d = self::haversine_m( $blat, $blng, (float) $p['lat'], (float) $p['lng'] );
				if ( $d < $min_dist['b_hydrant_dist_m'] ) $min_dist['b_hydrant_dist_m'] = $d;
			}
		} else {
			$hydrants = $wpdb->get_results( $wpdb->prepare(
				'SELECT lat, lng FROM ' . WSC_Installer::table_pois() .
				' WHERE city_id=%d AND lat>=%f AND lat<=%f AND lng>=%f AND lng<=%f' .
				' AND ( tags_json LIKE %s OR tags_json LIKE %s )',
				$city_id, $ext_bbox['s'], $ext_bbox['n'], $ext_bbox['w'], $ext_bbox['e'],
				'%"amenity":"fire_hydrant"%', '%"emergency":"fire_hydrant"%'
			), ARRAY_A );
			foreach ( (array) $hydrants as $h ) {
				$d = self::haversine_m( $blat, $blng, (float) $h['lat'], (float) $h['lng'] );
				if ( $d < $min_dist['b_hydrant_dist_m'] ) $min_dist['b_hydrant_dist_m'] = $d;
			}
		}

		// Деревья: пробуем chunk cache, fallback на SELECT COUNT.
		$tree_from_cache = self::get_chunk_pois_for_bbox( $city_id, [ 's'=>$bbox['s'],'n'=>$bbox['n'],'w'=>$bbox['w'],'e'=>$bbox['e'] ] );
		if ( $tree_from_cache !== null ) {
			$tree_count = 0;
			foreach ( $tree_from_cache as $p ) {
				if ( strpos( (string) ( $p['tags_json'] ?? '' ), '"natural":"tree"' ) !== false ) $tree_count++;
			}
		} else {
			$tree_count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM " . WSC_Installer::table_pois() .
				" WHERE city_id=%d AND lat>=%f AND lat<=%f AND lng>=%f AND lng<=%f AND tags_json LIKE %s",
				$city_id, $bbox['s'], $bbox['n'], $bbox['w'], $bbox['e'], '%"natural":"tree"%'
			) );
		}
		$counts['tree_count_within'] = $tree_count;

		// Park overlap area in m² → relative to yard area as %.
		$counts['park_area_within'] = self::park_area_pct( $city_id, $bbox, $buf, (float) $yard['area_m2'] );

		// Собираем итоговую карту b_* + старых POI.
		$raw = [];
		foreach ( $counts as $ind_id => $val ) {
			$raw[ $ind_id ] = (float) $val;
		}
		// Дистанции — если ничего не нашли, не пишем (пусть индикатор пропустится).
		if ( $min_dist['b_waste_dist_m']   !== INF ) $raw['b_waste_dist_m']   = (float) min( 25.0, $min_dist['b_waste_dist_m'] );
		if ( $min_dist['b_parking_dist_m'] !== INF ) $raw['b_parking_dist_m'] = (float) min( 15.0, $min_dist['b_parking_dist_m'] );
		// Гидрант: бинарный флаг — присутствует в зоне 5–200 м (если не найден вообще — явный 0, иначе в среднем балле остаётся только «идеальное» промышление).
		if ( $min_dist['b_hydrant_dist_m'] !== INF ) {
			$h                        = $min_dist['b_hydrant_dist_m'];
			$raw['b_hydrant_present'] = ( $h >= 5.0 && $h <= 200.0 ) ? 1.0 : 0.0;
		} else {
			$raw['b_hydrant_present'] = 0.0;
		}
		$raw['b_bike_parking_count'] = (float) min( 5,  $bike_count );
		$raw['b_benches_count']      = (float) min( 10, $bench_count );
		$raw['b_drinking_water_present'] = (float) $flags['b_drinking_water_present'];
		$raw['b_ev_charging_present']    = (float) $flags['b_ev_charging_present'];
		$raw['b_playground_present']     = (float) $flags['b_playground_present'];

		// Новые признаки «проезды / парковка во дворе» (регистрация показателя — в модуле эргономики).
		$raw['b_motor_way_in_yard']     = $motor_in_yard ? 1.0 : 0.0;
		$raw['b_parking_area_in_yard']  = $parking_area_in_yard ? 1.0 : 0.0;

		// Тротуар: footway/path/pedestrian с пересечением буфера + точечный fallback.
		if ( ! empty( $sw_widths ) ) {
			$raw['b_sidewalk_width_m'] = (float) ( array_sum( $sw_widths ) / count( $sw_widths ) );
		} else {
			$raw['b_sidewalk_width_m'] = 0.0;
		}

		// Свой batch строго для индикаторов: открыли → записали → закрыли (с реальным SQL flush).
		// Если outer caller тоже держит batch, depth-counter сделает это вложение безопасным:
		// outer данные остаются в буфере, наши индикаторы добавляются и flush'атся вместе.
		// НО: чтобы compute_index ниже мог прочитать свежие meta из БД, нам нужен
		// ЯВНЫЙ flush на верхнем уровне. Поэтому в sync_full simple meta из sync_*_post
		// flush'атся ДО write_poi_indicators (контракт sync_full).
		self::begin_meta_batch();
		try {
			foreach ( [ $yard_post_id, $building_post_id ] as $pid ) {
				$pid = (int) $pid;
				if ( $pid <= 0 ) continue;
				foreach ( $raw as $ind_id => $val ) {
					self::write_indicator_raw( $pid, $ind_id, $val, 'osm' );
				}
			}
		} finally {
			self::flush_meta_batch();
		}

		// Пересчёт измерений и индекса.
		// В defer-режиме (bulk-import) только копим post_ids — caller сделает один проход
		// с warm meta cache в конце чанка. Без defer — считаем сразу (legacy path).
		foreach ( [ $yard_post_id, $building_post_id ] as $pid ) {
			$pid = (int) $pid;
			if ( $pid <= 0 ) continue;
			if ( self::$defer_index_compute ) {
				self::$pending_index_posts[ $pid ] = true;
				continue;
			}
			if ( method_exists( 'WSErgo_Indicators', 'sync_dimension_meta_from_indicators' ) ) {
				WSErgo_Indicators::sync_dimension_meta_from_indicators( $pid );
			}
			if ( class_exists( 'WSErgo_Calculator' ) ) {
				WSErgo_Calculator::compute_and_store_index( $pid );
			}
		}
	}

	/**
	 * Приоритет источников. Чем больше — тем «сильнее». Запись с меньшим приоритетом
	 * не перезаписывает запись с большим: ручной ввод человека не сбивается обновлением
	 * из OSM, API-данные не затирают ручной ввод и т.д.
	 */
	private const SRC_PRIORITY = [ 'manual' => 3, 'api' => 2, 'osm' => 1 ];

	/**
	 * Batch-режим: накопитель записей с приоритетом (для wsergo_raw_*).
	 * Формат: [ post_id => [ indicator_id => [ 'value' => float, 'source' => string ] ] ]
	 *
	 * @var array<int, array<string, array{value: float, source: string}>>
	 */
	private static array $meta_batch = [];

	/**
	 * Batch-режим: накопитель «простых» мет без priority gate (META_LAT/LNG/CITY_ID и т.п.
	 * из sync_*_post). Здесь нет конкуренции источников — каждый ключ всегда перезаписывается.
	 *
	 * @var array<int, array<string, string>>
	 */
	private static array $simple_meta_batch = [];

	/**
	 * Reference-counted batch state. Каждый begin_meta_batch() инкрементит depth,
	 * только первый реально открывает буфер. Только финальный flush (depth=0) делает SQL.
	 * Это защищает от:
	 *   - вложенных begin/flush (внутренний случайно сбрасывал бы внешний буфер),
	 *   - flush на уже закрытом batch (no-op),
	 *   - утечки batch_mode при exception в середине цепочки (caller обязан finally flush).
	 *
	 * Свойство $batch_mode остаётся public-read для уже существующих проверок снаружи
	 * (write_indicator_raw, write_post_meta_batched), но управляется только depth-счётчиком.
	 */
	private static bool $batch_mode = false;
	private static int  $batch_depth = 0;

	/**
	 * Reference-counted DB-транзакция. Внутренние flush_*_meta_batch проверяют tx_depth и
	 * НЕ открывают свою транзакцию когда уже есть внешняя. Это сливает 2-4 транзакции/здание
	 * в одну: sync_full открывает tx, simple+main batch flush'и пишут в неё, COMMIT в конце.
	 * Экономит ~3мс/здание (overhead START/COMMIT).
	 */
	private static int $tx_depth = 0;

	public static function begin_tx(): void {
		global $wpdb;
		if ( self::$tx_depth === 0 ) {
			$wpdb->query( 'START TRANSACTION' );
		}
		self::$tx_depth++;
	}

	public static function commit_tx(): void {
		global $wpdb;
		self::$tx_depth = max( 0, self::$tx_depth - 1 );
		if ( self::$tx_depth === 0 ) {
			$wpdb->query( 'COMMIT' );
		}
	}

	public static function rollback_tx(): void {
		global $wpdb;
		// При rollback всегда сбрасываем до 0, даже если были вложенные. Иначе зависшая
		// транзакция продолжит коптить connection после exception.
		if ( self::$tx_depth > 0 ) {
			$wpdb->query( 'ROLLBACK' );
		}
		self::$tx_depth = 0;
	}

	/**
	 * Defer-режим для compute_index: bridge накапливает post_ids, не дёргает
	 * compute_and_store_index в write_poi_indicators. Финальный пересчёт вызывает
	 * caller (например process_buffers_chunk) одним проходом с warm meta cache.
	 */
	public static bool $defer_index_compute = false;
	/** @var array<int,bool> post_ids, ожидающие compute_index */
	private static array $pending_index_posts = [];

	public static function begin_meta_batch(): void {
		if ( self::$batch_depth === 0 ) {
			// Только внешний begin реально открывает свежий буфер.
			self::$meta_batch = [];
			self::$simple_meta_batch = [];
			self::$batch_mode = true;
		}
		self::$batch_depth++;
	}

	/**
	 * NB: city-wide POI cache (один SELECT всех POI города) был регрессией: linear scan
	 * по 50k POI медленнее индекса БД. Поэтому prefetch_city_pois — no-op.
	 */
	public static function prefetch_city_pois( int $city_id ): void { /* intentionally empty */ }
	public static function clear_city_pois_cache(): void { /* intentionally empty */ }

	/**
	 * Chunk-scope POI cache: один SELECT POI для bbox объединения зданий ТЕКУЩЕГО чанка
	 * (~200 зданий, локализованная область ~1-2 км², 500-2000 POI). Linear scan такого
	 * массива (5-15 мс на проход) выгоднее 3 SELECT'ов на каждое здание (3 × 100 = 300 SQL).
	 *
	 * Отличие от city-wide: чанк локализован, POI на порядок меньше → linear scan быстрый.
	 *
	 * @var array<int, array<string,mixed>>|null
	 */
	private static ?array $chunk_pois_cache = null;
	private static int $chunk_pois_city = 0;
	private static array $chunk_pois_bbox = []; // ext_bbox: s,n,w,e

	public static function prefetch_chunk_pois( int $city_id, array $building_ids ): void {
		self::clear_chunk_pois_cache();
		if ( $city_id <= 0 || empty( $building_ids ) ) return;

		global $wpdb;
		$ids_in = implode( ',', array_map( 'absint', $building_ids ) );
		// bbox объединения buildings из wsc_buildings (есть денормализованные bbox_west/east/north/south).
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT MIN(bbox_south) AS s, MAX(bbox_north) AS n, MIN(bbox_west) AS w, MAX(bbox_east) AS e
			 FROM " . WSC_Installer::table_buildings() . "
			 WHERE city_id=%d AND id IN ({$ids_in})",
			$city_id
		), ARRAY_A );
		if ( ! $row || $row['s'] === null ) return;

		// Расширяем на 280м (как в write_poi_indicators) — нужен запас для POI чуть за буфером двора.
		$ext = self::expand_bbox_meters(
			[ 's' => (float) $row['s'], 'n' => (float) $row['n'], 'w' => (float) $row['w'], 'e' => (float) $row['e'] ],
			280.0
		);

		// ОДИН SELECT для всего чанка вместо 3 SELECT × N зданий.
		// SELECT с индексом (city_id, lat, lng) для широкого bbox быстрый: ~10-50 мс на 1-2 тыс POI.
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT category, lat, lng, geojson, geom_type, tags_json FROM ' . WSC_Installer::table_pois() .
			' WHERE city_id=%d AND lat>=%f AND lat<=%f AND lng>=%f AND lng<=%f',
			$city_id, $ext['s'], $ext['n'], $ext['w'], $ext['e']
		), ARRAY_A );

		self::$chunk_pois_cache = (array) $rows;
		self::$chunk_pois_city  = $city_id;
		self::$chunk_pois_bbox  = $ext;
	}

	public static function clear_chunk_pois_cache(): void {
		self::$chunk_pois_cache = null;
		self::$chunk_pois_city  = 0;
		self::$chunk_pois_bbox  = [];
	}

	/**
	 * Возвращает POI для bbox конкретного здания из chunk cache, если он прогрет и покрывает.
	 *
	 * @return array<int, array<string,mixed>>|null
	 */
	private static function get_chunk_pois_for_bbox( int $city_id, array $qbbox ): ?array {
		if ( self::$chunk_pois_cache === null || self::$chunk_pois_city !== $city_id ) {
			return null;
		}
		// Проверяем что qbbox реально внутри cache_bbox (если здание оказалось за пределами chunk bbox).
		$c = self::$chunk_pois_bbox;
		if ( $qbbox['s'] < $c['s'] || $qbbox['n'] > $c['n'] || $qbbox['w'] < $c['w'] || $qbbox['e'] > $c['e'] ) {
			return null; // выходит за cache → fall back к SELECT
		}
		$out = [];
		foreach ( self::$chunk_pois_cache as $p ) {
			$lt = (float) $p['lat'];
			$ln = (float) $p['lng'];
			if ( $lt >= $qbbox['s'] && $lt <= $qbbox['n'] && $ln >= $qbbox['w'] && $ln <= $qbbox['e'] ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	/**
	 * Прямой wpdb upsert поста — обход wp_insert_post/wp_update_post для bulk-импорта.
	 *
	 * Зачем: wp_insert_post + wp_update_post внутри делают ~5 SQL каждый (включая
	 * внутренние SELECT'ы, save_post action triggering, clean_post_cache).
	 * Для bulk-recompute эти хуки и так подавлены через WSErgo_CPT::$suspend_autorecalc,
	 * так что прямой INSERT/UPDATE в wp_posts эквивалентен по семантике, но ~30мс быстрее.
	 *
	 * Возвращает post_id (0 при ошибке).
	 */
	public static function direct_upsert_post( string $post_type, int $existing_id, string $title ): int {
		global $wpdb;
		$now     = current_time( 'mysql', false );
		$now_gmt = current_time( 'mysql', true );

		if ( $existing_id > 0 ) {
			$result = $wpdb->update(
				$wpdb->posts,
				[
					'post_title'        => $title,
					'post_modified'     => $now,
					'post_modified_gmt' => $now_gmt,
					'post_status'       => 'publish',
				],
				[ 'ID' => $existing_id ],
				[ '%s', '%s', '%s', '%s' ],
				[ '%d' ]
			);
			if ( $result !== false ) {
				// $result === 0 — UPDATE никого не задело (post удалён вручную). Fall through к INSERT.
				if ( $result > 0 ) {
					wp_cache_delete( $existing_id, 'posts' );
					return $existing_id;
				}
			}
			// Иначе INSERT новый.
		}

		$ok = $wpdb->insert(
			$wpdb->posts,
			[
				'post_author'           => get_current_user_id() ?: 1,
				'post_date'             => $now,
				'post_date_gmt'         => $now_gmt,
				'post_content'          => '',
				'post_title'            => $title,
				'post_excerpt'          => '',
				'post_status'           => 'publish',
				'comment_status'        => 'closed',
				'ping_status'           => 'closed',
				'post_password'         => '',
				'post_name'             => '',
				'to_ping'               => '',
				'pinged'                => '',
				'post_modified'         => $now,
				'post_modified_gmt'     => $now_gmt,
				'post_content_filtered' => '',
				'post_parent'           => 0,
				'guid'                  => '',
				'menu_order'            => 0,
				'post_type'             => $post_type,
				'post_mime_type'        => '',
				'comment_count'         => 0,
			]
		);
		if ( ! $ok ) {
			return 0;
		}
		$new_id = (int) $wpdb->insert_id;

		// guid: WP-конвенция `?post_type=X&p=ID`. Без неё в RSS/админке будет пусто.
		$guid = trailingslashit( home_url() ) . '?post_type=' . rawurlencode( $post_type ) . '&p=' . $new_id;
		$wpdb->update( $wpdb->posts, [ 'guid' => $guid ], [ 'ID' => $new_id ], [ '%s' ], [ '%d' ] );

		wp_cache_delete( $new_id, 'posts' );
		return $new_id;
	}

	/**
	 * Записать простую meta (без priority gate). В batch-режиме копит, иначе — update_post_meta.
	 * Используется sync_*_post для META_LAT/LNG/CITY_ID/ADDRESS/GEOJSON/STATUS и т.п.
	 */
	public static function write_post_meta_batched( int $post_id, string $meta_key, $meta_value ): void {
		if ( self::$batch_mode ) {
			self::$simple_meta_batch[ $post_id ][ $meta_key ] = is_scalar( $meta_value ) ? (string) $meta_value : (string) wp_json_encode( $meta_value );
			return;
		}
		update_post_meta( $post_id, $meta_key, $meta_value );
	}

	/**
	 * Накопить post_id для отложенного compute_index. Вызывается из write_poi_indicators
	 * когда $defer_index_compute=true. Caller потом разово зовёт flush_deferred_index().
	 *
	 * Перехват update_post_meta через filter `update_post_metadata` направляет все записи
	 * dimension/index мет в наш batch — финальный flush один SQL вместо 7×N update_post_meta.
	 * Это **главный win** этого метода: на чанке 200 buildings 1400 update_post_meta → 2 SQL.
	 */
	public static function flush_deferred_index(): array {
		$pids = array_keys( self::$pending_index_posts );
		self::$pending_index_posts = [];
		if ( empty( $pids ) ) {
			return [ 'count' => 0 ];
		}

		// Прогреваем meta cache одним SELECT-ом для всех post_ids сразу.
		// Это превратит ~12 cache-miss SELECT'ов на здание в 1 batch SELECT для всего чанка.
		update_meta_cache( 'post', $pids );

		// Перехват: каждый update_post_meta для наших ключей (wsergo_dim_*, wsergo_index)
		// от sync_dim/compute_index перенаправляем в batch. Снимаем фильтр в finally.
		self::begin_meta_batch();
		$filter_cb = static function ( $check, $object_id, $meta_key, $meta_value ) {
			if ( ! is_string( $meta_key ) ) return $check;
			if ( strpos( $meta_key, 'wsergo_dim_' ) !== 0 && $meta_key !== 'wsergo_index' ) {
				return $check; // не наш ключ — пропускаем
			}
			self::write_post_meta_batched( (int) $object_id, $meta_key, $meta_value );
			return true; // сигнал WP «обновление прошло», стандартный SQL пропускается
		};
		add_filter( 'update_post_metadata', $filter_cb, 10, 5 );
		// На всякий случай также для add_post_metadata (если значения раньше не было).
		$add_cb = static function ( $check, $object_id, $meta_key, $meta_value ) {
			if ( ! is_string( $meta_key ) ) return $check;
			if ( strpos( $meta_key, 'wsergo_dim_' ) !== 0 && $meta_key !== 'wsergo_index' ) {
				return $check;
			}
			self::write_post_meta_batched( (int) $object_id, $meta_key, $meta_value );
			return true;
		};
		add_filter( 'add_post_metadata', $add_cb, 10, 5 );

		$count = 0;
		try {
			foreach ( $pids as $pid ) {
				if ( method_exists( 'WSErgo_Indicators', 'sync_dimension_meta_from_indicators' ) ) {
					WSErgo_Indicators::sync_dimension_meta_from_indicators( $pid );
				}
				if ( class_exists( 'WSErgo_Calculator' ) ) {
					WSErgo_Calculator::compute_and_store_index( $pid );
				}
				$count++;
			}
		} finally {
			remove_filter( 'update_post_metadata', $filter_cb, 10 );
			remove_filter( 'add_post_metadata', $add_cb, 10 );
			self::flush_meta_batch();
		}
		return [ 'count' => $count ];
	}

	/**
	 * Запись сырого значения индикатора с учётом приоритета источника.
	 *
	 * В обычном режиме — два update_post_meta (как раньше).
	 * В batch-режиме (после begin_meta_batch()) — накопление в буфер, реальная запись
	 * происходит в flush_meta_batch() одним SQL вместо ~60-120 update_post_meta на здание.
	 *
	 * Метод публичный, чтобы его мог переиспользовать WSErgo_External_Bridge.
	 */
	public static function write_indicator_raw( int $post_id, string $indicator_id, float $value, string $source ): void {
		if ( self::$batch_mode ) {
			// В батче гейт приоритета применяется в flush, чтобы сделать его одним SELECT-ом для всего пула.
			// Если для одного и того же поста+индикатора накопилось несколько записей — выигрывает последняя
			// с наивысшим приоритетом (см. queue_meta).
			self::queue_meta( $post_id, $indicator_id, $value, $source );
			return;
		}
		$src_key     = WSErgo_Indicators::meta_key_for_raw( $indicator_id ) . '_src';
		$current_src = (string) get_post_meta( $post_id, $src_key, true );
		$new_p       = self::SRC_PRIORITY[ $source ] ?? 0;
		$cur_p       = self::SRC_PRIORITY[ $current_src ] ?? 0;
		if ( $cur_p > $new_p ) {
			return;
		}
		update_post_meta( $post_id, WSErgo_Indicators::meta_key_for_raw( $indicator_id ), $value );
		update_post_meta( $post_id, $src_key, $source );
	}

	private static function queue_meta( int $post_id, string $indicator_id, float $value, string $source ): void {
		if ( $post_id <= 0 ) return;
		$existing_p = isset( self::$meta_batch[ $post_id ][ $indicator_id ] )
			? ( self::SRC_PRIORITY[ self::$meta_batch[ $post_id ][ $indicator_id ]['source'] ] ?? 0 )
			: -1;
		$new_p = self::SRC_PRIORITY[ $source ] ?? 0;
		if ( $new_p < $existing_p ) {
			return; // в буфере уже более сильный источник
		}
		self::$meta_batch[ $post_id ][ $indicator_id ] = [ 'value' => $value, 'source' => $source ];
	}

	/**
	 * Запись накопленного буфера. Делает:
	 *   1) ОДИН SELECT текущих _src ключей для всех затронутых post_id (применяет priority gate).
	 *   2) ОДИН DELETE для меняемых meta_keys (значение + _src).
	 *   3) ОДИН INSERT для новых строк.
	 *   4) wp_cache_delete для затронутых post_id, чтобы последующие get_post_meta не возвращали stale.
	 *
	 * Без батч-режима: ~120 SQL на здание. С батчем: 3 SQL на 100 зданий = ~40× меньше IO.
	 */
	/**
	 * Bulk-flush для «простых» мет (без priority gate). DELETE + INSERT обёрнуты в транзакцию:
	 * если INSERT упадёт (max_allowed_packet, deadlock), DELETE откатывается — данные не теряются.
	 *
	 * @param array<int, array<string, string>> $buffer
	 */
	private static function flush_simple_meta_batch( array $buffer ): void {
		global $wpdb;
		$all_keys     = [];
		$post_ids     = array_keys( $buffer );
		$values_flat  = [];
		foreach ( $buffer as $pid => $kv ) {
			foreach ( $kv as $k => $v ) {
				$all_keys[ $k ] = true;
				$values_flat[]  = [ (int) $pid, (string) $k, (string) $v ];
			}
		}
		if ( empty( $values_flat ) ) return;

		$keys_list = array_keys( $all_keys );
		$ids_in    = implode( ',', array_map( 'absint', $post_ids ) );
		$ph_keys   = implode( ',', array_fill( 0, count( $keys_list ), '%s' ) );

		self::begin_tx();
		try {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$ids_in}) AND meta_key IN ({$ph_keys})",
				$keys_list
			) );

			$chunks = array_chunk( $values_flat, 500 );
			foreach ( $chunks as $chunk ) {
				$placeholders = [];
				$flat = [];
				foreach ( $chunk as $row ) {
					$placeholders[] = '(%d, %s, %s)';
					$flat[] = $row[0];
					$flat[] = $row[1];
					$flat[] = $row[2];
				}
				$sql = "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES "
					. implode( ', ', $placeholders );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$result = $wpdb->query( $wpdb->prepare( $sql, $flat ) );
				if ( $result === false ) {
					throw new \RuntimeException( 'wpdb INSERT failed in simple_meta_batch: ' . $wpdb->last_error );
				}
			}
			self::commit_tx();
		} catch ( \Throwable $e ) {
			self::rollback_tx();
			throw $e;
		}
		// Сбрасываем stale WP cache для затронутых post_id. Иначе последующий get_post_meta
		// внутри того же PHP-процесса вернёт устаревшее (cache pre-delete остаётся).
		foreach ( $post_ids as $pid ) {
			wp_cache_delete( (int) $pid, 'post_meta' );
		}
		update_meta_cache( 'post', $post_ids );
	}

	public static function flush_meta_batch(): void {
		if ( self::$batch_depth <= 0 ) {
			// flush на закрытом batch — no-op (повторный вызов или баг caller'а).
			self::$batch_depth = 0;
			self::$batch_mode = false;
			return;
		}
		self::$batch_depth--;
		if ( self::$batch_depth > 0 ) {
			// Вложенный flush — данные оставляем в буфере для внешнего caller'а.
			return;
		}
		$buffer        = self::$meta_batch;
		$simple_buffer = self::$simple_meta_batch;
		self::$meta_batch        = [];
		self::$simple_meta_batch = [];
		self::$batch_mode        = false;

		// Простые меты (без priority gate) — отдельный быстрый путь:
		// один DELETE для всех (post_id, meta_key) пар + один INSERT.
		if ( ! empty( $simple_buffer ) ) {
			self::flush_simple_meta_batch( $simple_buffer );
		}

		if ( empty( $buffer ) ) {
			return;
		}

		global $wpdb;
		$post_ids   = array_keys( $buffer );
		$value_keys = [];
		$src_keys   = [];
		foreach ( $buffer as $pid => $inds ) {
			foreach ( $inds as $ind_id => $row ) {
				$mk = WSErgo_Indicators::meta_key_for_raw( $ind_id );
				$value_keys[ $mk ] = true;
				$src_keys[ $mk . '_src' ] = true;
			}
		}
		$all_meta_keys = array_keys( $value_keys + $src_keys );
		if ( empty( $all_meta_keys ) || empty( $post_ids ) ) {
			return;
		}

		// 1) SELECT текущих _src — для priority gate.
		$ids_in  = implode( ',', array_map( 'absint', $post_ids ) );
		$ph_keys = implode( ',', array_fill( 0, count( $all_meta_keys ), '%s' ) );
		$sql_sel = "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
		            WHERE post_id IN ({$ids_in}) AND meta_key IN ({$ph_keys})";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql_sel, $all_meta_keys ), ARRAY_A );
		$current_src_by_pid_key = []; // [pid][meta_key_value] = current_src
		foreach ( (array) $rows as $r ) {
			$mk = (string) $r['meta_key'];
			if ( substr( $mk, -4 ) !== '_src' ) continue;
			$value_mk = substr( $mk, 0, -4 );
			$current_src_by_pid_key[ (int) $r['post_id'] ][ $value_mk ] = (string) $r['meta_value'];
		}

		// 2) Фильтрация буфера по приоритету.
		$values_to_write = []; // [ [pid, meta_key, meta_value], ... ]
		$keys_to_delete  = []; // [ pid => [meta_key, meta_key_src, ...] ]
		foreach ( $buffer as $pid => $inds ) {
			foreach ( $inds as $ind_id => $row ) {
				$mk      = WSErgo_Indicators::meta_key_for_raw( $ind_id );
				$mk_src  = $mk . '_src';
				$new_p   = self::SRC_PRIORITY[ $row['source'] ] ?? 0;
				$cur_src = $current_src_by_pid_key[ $pid ][ $mk ] ?? '';
				$cur_p   = self::SRC_PRIORITY[ $cur_src ] ?? 0;
				if ( $cur_p > $new_p ) {
					continue;
				}
				$values_to_write[] = [ $pid, $mk,     (string) $row['value']  ];
				$values_to_write[] = [ $pid, $mk_src, (string) $row['source'] ];
				$keys_to_delete[ $pid ][ $mk ]     = true;
				$keys_to_delete[ $pid ][ $mk_src ] = true;
			}
		}
		if ( empty( $values_to_write ) ) {
			return;
		}

		// 3+4) DELETE + INSERT обёрнуты в транзакцию: если INSERT упадёт (max_allowed_packet,
		// deadlock), DELETE откатывается, данные не теряются.
		$del_keys = [];
		$del_pids = [];
		foreach ( $keys_to_delete as $pid => $keys ) {
			$del_pids[ $pid ] = true;
			foreach ( $keys as $k => $_ ) {
				$del_keys[ $k ] = true;
			}
		}
		$del_ids_in  = implode( ',', array_map( 'absint', array_keys( $del_pids ) ) );
		$del_keys_in = array_keys( $del_keys );
		$ph_del      = implode( ',', array_fill( 0, count( $del_keys_in ), '%s' ) );

		self::begin_tx();
		try {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$del_result = $wpdb->query( $wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$del_ids_in}) AND meta_key IN ({$ph_del})",
				$del_keys_in
			) );
			if ( $del_result === false ) {
				throw new \RuntimeException( 'wpdb DELETE failed in flush_meta_batch: ' . $wpdb->last_error );
			}

			$chunks = array_chunk( $values_to_write, 500 );
			foreach ( $chunks as $chunk ) {
				$placeholders = [];
				$flat = [];
				foreach ( $chunk as $row ) {
					$placeholders[] = '(%d, %s, %s)';
					$flat[] = (int) $row[0];
					$flat[] = (string) $row[1];
					$flat[] = (string) $row[2];
				}
				$sql = "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES "
					. implode( ', ', $placeholders );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$ins_result = $wpdb->query( $wpdb->prepare( $sql, $flat ) );
				if ( $ins_result === false ) {
					throw new \RuntimeException( 'wpdb INSERT failed in flush_meta_batch: ' . $wpdb->last_error );
				}
			}
			self::commit_tx();
		} catch ( \Throwable $e ) {
			self::rollback_tx();
			throw $e;
		}

		// 5) Сбрасываем stale WP cache + сразу прогреваем свежими данными одним SELECT.
		// Важно: update_meta_cache() сама по себе НЕ инвалидирует уже-cached entries
		// (она пропускает ID если cache их уже содержит). Поэтому сначала wp_cache_delete.
		foreach ( $post_ids as $pid ) {
			wp_cache_delete( (int) $pid, 'post_meta' );
		}
		update_meta_cache( 'post', $post_ids );
	}

	/**
	 * Расширить bbox широты/долготы на указанный радиус (м) — для выборки POI крупнее придомовой.
	 *
	 * @param array{s: float, n: float, w: float, e: float} $bbox .
	 * @return array{s: float, n: float, w: float, e: float}
	 */
	private static function expand_bbox_meters( array $bbox, float $meters ): array {
		$mid_lat   = ( (float) $bbox['s'] + (float) $bbox['n'] ) / 2.0;
		$delta_lat = $meters / 111000.0;
		$cos_lat   = max( 0.15, cos( deg2rad( $mid_lat ) ) );
		$delta_lng = $meters / ( 111000.0 * $cos_lat );
		return [
			's' => (float) $bbox['s'] - $delta_lat,
			'n' => (float) $bbox['n'] + $delta_lat,
			'w' => (float) $bbox['w'] - $delta_lng,
			'e' => (float) $bbox['e'] + $delta_lng,
		];
	}

	/**
	 * Расстояние между двумя точками в метрах (haversine).
	 */
	private static function haversine_m( float $lat1, float $lng1, float $lat2, float $lng2 ): float {
		$r  = 6371000.0;
		$p1 = deg2rad( $lat1 );
		$p2 = deg2rad( $lat2 );
		$dp = deg2rad( $lat2 - $lat1 );
		$dl = deg2rad( $lng2 - $lng1 );
		$a  = sin( $dp / 2 ) ** 2 + cos( $p1 ) * cos( $p2 ) * sin( $dl / 2 ) ** 2;
		return 2 * $r * asin( min( 1.0, sqrt( $a ) ) );
	}

	/**
	 * Кэш {city_id => [['bbox' => {w,s,e,n}, 'ring' => [[lng,lat], ...]], ...]} для зелёных landuse.
	 * Заполняется лениво на первый вызов park_area_pct() в данном PHP-запросе и переиспользуется
	 * на всех последующих зданиях того же чанка/города. Декодирование LONGTEXT-geojson и
	 * вычисление bbox делается ровно один раз вместо N раз.
	 *
	 * @var array<int, array<int, array{bbox:array{w:float,s:float,e:float,n:float}, ring:array}>>
	 */
	private static array $green_cache = [];

	private static function load_green_polygons( int $city_id ): array {
		if ( isset( self::$green_cache[ $city_id ] ) ) {
			return self::$green_cache[ $city_id ];
		}
		global $wpdb;
		$table = WSC_Installer::table_landuse();
		$t_esc = esc_sql( $table );
		if ( class_exists( 'WSC_Landcover' ) ) {
			$kind_in = WSC_Landcover::green_zone_sql_in_list();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- kind IN уже esc_sql() в green_zone_sql_in_list().
			$rows = $wpdb->get_results(
				sprintf(
					'SELECT geojson, bbox_west, bbox_south, bbox_east, bbox_north FROM `%s` WHERE city_id=%d AND kind IN (%s)',
					$t_esc,
					absint( $city_id ),
					$kind_in
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare(
				'SELECT geojson, bbox_west, bbox_south, bbox_east, bbox_north FROM ' . $table . ' WHERE city_id=%d AND kind IN ("park","grass","recreation_ground","playground","garden")',
				$city_id
			), ARRAY_A );
		}
		$cached = [];
		foreach ( (array) $rows as $r ) {
			$g = json_decode( (string) $r['geojson'], true );
			if ( ! is_array( $g ) ) continue;
			$ring = $g['coordinates'][0] ?? [];
			if ( ! is_array( $ring ) || empty( $ring ) ) continue;
			$cached[] = [
				'bbox' => [
					'w' => (float) $r['bbox_west'],
					's' => (float) $r['bbox_south'],
					'e' => (float) $r['bbox_east'],
					'n' => (float) $r['bbox_north'],
				],
				'ring' => $ring,
			];
		}
		self::$green_cache[ $city_id ] = $cached;
		return $cached;
	}

	private static function park_area_pct( int $city_id, array $bbox, array $buf, float $yard_area_m2 ): float {
		if ( $yard_area_m2 <= 0 ) return 0.0;

		$polys = self::load_green_polygons( $city_id );
		if ( empty( $polys ) ) return 0.0;

		// Bbox-фильтр: считаем только полигоны, чей bbox пересекает bbox двора.
		// Раньше для каждого здания обходились ВСЕ полигоны города (тысячи), теперь — единицы.
		$yw = $bbox['w']; $ys = $bbox['s']; $ye = $bbox['e']; $yn = $bbox['n'];

		$total = 0;
		foreach ( $polys as $p ) {
			$pb = $p['bbox'];
			if ( $pb['e'] < $yw || $pb['w'] > $ye || $pb['n'] < $ys || $pb['s'] > $yn ) {
				continue;
			}
			$inside = 0;
			foreach ( $p['ring'] as $pt ) {
				if ( WSC_Geom::point_in_polygon( $pt, $buf ) ) {
					$inside++;
				}
			}
			if ( $inside > 0 ) $total += $inside;
		}
		// crude: each "inside vertex" contributes 1% capped at 100%.
		return min( 100.0, $total );
	}

	public static function ensure_indicators_registered(): void {
		$cur = get_option( WSErgo_Indicators::OPTION_DEFINITIONS, [] );
		$cur = is_array( $cur ) ? $cur : [];
		$changed = false;

		// Build index by id for quick lookup + update.
		$by_id = [];
		foreach ( $cur as $i => $row ) {
			if ( is_array( $row ) && isset( $row['id'] ) ) {
				$by_id[ (string) $row['id'] ] = $i;
			}
		}

		foreach ( self::POI_INDICATORS as $id => [ $label, $dim ] ) {
			$expected = [
				'id'        => $id,
				'label'     => $label,
				'dimension' => $dim,
				'unit'      => '',
				'vmin'      => 0,
				'vmax'      => $id === 'park_area_within' ? 100 : 5,
				'direction' => $id === 'poi_industrial_within' ? 'lower_better' : 'higher_better',
				'weight'    => 1.0,
			];
			if ( ! isset( $by_id[ $id ] ) ) {
				$cur[] = $expected;
				$changed = true;
				continue;
			}
			// Heal previously-broken dimension keys (dim_a..dim_f) and ensure dimension/direction stay correct.
			$idx = $by_id[ $id ];
			$row = is_array( $cur[ $idx ] ) ? $cur[ $idx ] : [];
			if ( ( $row['dimension'] ?? '' ) !== $dim ) {
				$cur[ $idx ]['dimension'] = $dim;
				$changed = true;
			}
			if ( ! isset( $row['direction'] ) || ! in_array( $row['direction'], [ 'higher_better', 'lower_better' ], true ) ) {
				$cur[ $idx ]['direction'] = $expected['direction'];
				$changed = true;
			}
			if ( ( $row['label'] ?? '' ) !== $label ) {
				$cur[ $idx ]['label'] = $label;
				$changed = true;
			}
		}
		if ( $changed ) {
			update_option( WSErgo_Indicators::OPTION_DEFINITIONS, $cur );
		}
	}
}
