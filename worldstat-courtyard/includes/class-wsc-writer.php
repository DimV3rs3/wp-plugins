<?php
/**
 * Writer: upsert по (osm_type, osm_id) в кастомные таблицы + инвалидация MVT-кэша.
 *
 * @package WorldStatCourtyard
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSC_Writer {

	/* ── Compatibility constants for worldstat-ergonomics ── */
	const META_CITY_ID      = 'wsosm_city_id';
	const META_ENTITY_TYPE  = 'wsosm_entity_type';
	const META_ADDRESS_FULL = 'wsosm_address_full';
	const META_STATUS       = 'wsosm_status';

	/* ── Bulk upsert methods (INSERT ... ON DUPLICATE KEY UPDATE) ── */

	public static function bulk_upsert_buildings( int $city_id, array $records ): int {
		if ( empty( $records ) ) return 0;
		global $wpdb;
		$tb    = WSC_Installer::table_buildings();
		$now   = current_time( 'mysql', true );
		// 500 — компромисс: 17 колонок × 500 ≈ 8500 placeholders, влезает в max_allowed_packet
		// и даёт ~10× меньше INSERT-операций по сравнению со старым default=50 на batch'е
		// 4 км × 4 км (3-5K записей). Каждый bulk-INSERT даже на InnoDB сильно дешевле,
		// чем 10 отдельных по 50 строк.
		$chunk = (int) apply_filters( 'wsc_bulk_chunk_size', 500 );
		$total = 0;

		$wpdb->query( 'START TRANSACTION' );
		foreach ( array_chunk( $records, $chunk ) as $batch ) {
			$ph = [];
			$vals = [];
			foreach ( $batch as $rec ) {
				$ph[] = '(%s,%d,%d,%s,%s,%s,%f,%d,%s,%f,%f,%f,%f,%f,%f,%s,%s)';
				array_push( $vals,
					substr( (string) $rec['osm_type'], 0, 8 ),
					(int) $rec['osm_id'],
					$city_id,
					substr( (string) $rec['category'], 0, 32 ),
					mb_substr( (string) $rec['name'], 0, 255 ),
					mb_substr( (string) $rec['address'], 0, 255 ),
					(float) ( $rec['height_m'] ?? 0 ),
					(int) ( $rec['levels'] ?? 0 ),
					(string) $rec['geojson'],
					(float) $rec['centroid'][1],
					(float) $rec['centroid'][0],
					(float) $rec['bbox']['w'],
					(float) $rec['bbox']['s'],
					(float) $rec['bbox']['e'],
					(float) $rec['bbox']['n'],
					(string) $rec['tags_json'],
					$now
				);
			}
			$sql = "INSERT INTO {$tb}
				(osm_type,osm_id,city_id,category,name,address,height_m,levels,
				 footprint_geojson,centroid_lat,centroid_lng,
				 bbox_west,bbox_south,bbox_east,bbox_north,tags_json,scanned_at)
				VALUES " . implode( ',', $ph ) . "
				ON DUPLICATE KEY UPDATE
					city_id=VALUES(city_id),category=VALUES(category),name=VALUES(name),
					address=VALUES(address),height_m=VALUES(height_m),levels=VALUES(levels),
					footprint_geojson=VALUES(footprint_geojson),
					centroid_lat=VALUES(centroid_lat),centroid_lng=VALUES(centroid_lng),
					bbox_west=VALUES(bbox_west),bbox_south=VALUES(bbox_south),
					bbox_east=VALUES(bbox_east),bbox_north=VALUES(bbox_north),
					tags_json=VALUES(tags_json),scanned_at=VALUES(scanned_at)";
			$res = $wpdb->query( $wpdb->prepare( $sql, ...$vals ) );
			if ( $res !== false ) $total += count( $batch );
		}
		$wpdb->query( 'COMMIT' );

		do_action( 'wsc_buildings_bulk_imported', $city_id, $total );
		return $total;
	}

	public static function bulk_upsert_pois( int $city_id, array $records ): int {
		if ( empty( $records ) ) return 0;
		global $wpdb;
		$tp    = WSC_Installer::table_pois();
		$now   = current_time( 'mysql', true );
		// 500 — компромисс: 17 колонок × 500 ≈ 8500 placeholders, влезает в max_allowed_packet
		// и даёт ~10× меньше INSERT-операций по сравнению со старым default=50 на batch'е
		// 4 км × 4 км (3-5K записей). Каждый bulk-INSERT даже на InnoDB сильно дешевле,
		// чем 10 отдельных по 50 строк.
		$chunk = (int) apply_filters( 'wsc_bulk_chunk_size', 500 );
		$total = 0;

		$wpdb->query( 'START TRANSACTION' );
		foreach ( array_chunk( $records, $chunk ) as $batch ) {
			$ph = [];
			$vals = [];
			foreach ( $batch as $rec ) {
				$geo   = json_decode( (string) $rec['geojson'], true );
				$gtype = is_array( $geo ) ? ( $geo['type'] ?? 'Point' ) : 'Point';
				$ph[]  = '(%s,%d,%d,%s,%s,%s,%s,%f,%f,%s,%s)';
				array_push( $vals,
					substr( (string) $rec['osm_type'], 0, 8 ),
					(int) $rec['osm_id'],
					$city_id,
					substr( (string) $rec['category'], 0, 32 ),
					mb_substr( (string) $rec['name'], 0, 255 ),
					$gtype,
					(string) $rec['geojson'],
					(float) $rec['centroid'][1],
					(float) $rec['centroid'][0],
					(string) $rec['tags_json'],
					$now
				);
			}
			$sql = "INSERT INTO {$tp}
				(osm_type,osm_id,city_id,category,name,geom_type,geojson,lat,lng,tags_json,scanned_at)
				VALUES " . implode( ',', $ph ) . "
				ON DUPLICATE KEY UPDATE
					city_id=VALUES(city_id),category=VALUES(category),name=VALUES(name),
					geom_type=VALUES(geom_type),geojson=VALUES(geojson),
					lat=VALUES(lat),lng=VALUES(lng),
					tags_json=VALUES(tags_json),scanned_at=VALUES(scanned_at)";
			$res = $wpdb->query( $wpdb->prepare( $sql, ...$vals ) );
			if ( $res !== false ) $total += count( $batch );
		}
		$wpdb->query( 'COMMIT' );
		return $total;
	}

	public static function bulk_upsert_landuse( int $city_id, array $records ): int {
		if ( empty( $records ) ) return 0;
		global $wpdb;
		$tl    = WSC_Installer::table_landuse();
		$now   = current_time( 'mysql', true );
		// 500 — компромисс: 17 колонок × 500 ≈ 8500 placeholders, влезает в max_allowed_packet
		// и даёт ~10× меньше INSERT-операций по сравнению со старым default=50 на batch'е
		// 4 км × 4 км (3-5K записей). Каждый bulk-INSERT даже на InnoDB сильно дешевле,
		// чем 10 отдельных по 50 строк.
		$chunk = (int) apply_filters( 'wsc_bulk_chunk_size', 500 );
		$total = 0;

		$wpdb->query( 'START TRANSACTION' );
		foreach ( array_chunk( $records, $chunk ) as $batch ) {
			$ph = [];
			$vals = [];
			foreach ( $batch as $rec ) {
				$bbox = is_array( $rec['bbox'] ?? null ) ? $rec['bbox'] : [ 'w' => 0, 's' => 0, 'e' => 0, 'n' => 0 ];
				$ph[] = '(%s,%d,%d,%s,%s,%s,%s,%f,%f,%f,%f,%s)';
				array_push( $vals,
					substr( (string) $rec['osm_type'], 0, 8 ),
					(int) $rec['osm_id'],
					$city_id,
					substr( (string) $rec['kind'], 0, 32 ),
					mb_substr( (string) $rec['name'], 0, 255 ),
					(string) $rec['geojson'],
					(string) $rec['tags_json'],
					(float) $bbox['w'],
					(float) $bbox['s'],
					(float) $bbox['e'],
					(float) $bbox['n'],
					$now
				);
			}
			$sql = "INSERT INTO {$tl}
				(osm_type,osm_id,city_id,kind,name,geojson,tags_json,
				 bbox_west,bbox_south,bbox_east,bbox_north,scanned_at)
				VALUES " . implode( ',', $ph ) . "
				ON DUPLICATE KEY UPDATE
					city_id=VALUES(city_id),kind=VALUES(kind),name=VALUES(name),
					geojson=VALUES(geojson),tags_json=VALUES(tags_json),
					bbox_west=VALUES(bbox_west),bbox_south=VALUES(bbox_south),
					bbox_east=VALUES(bbox_east),bbox_north=VALUES(bbox_north),
					scanned_at=VALUES(scanned_at)";
			$res = $wpdb->query( $wpdb->prepare( $sql, ...$vals ) );
			if ( $res !== false ) $total += count( $batch );
		}
		$wpdb->query( 'COMMIT' );
		return $total;
	}

	/* ── Single-record upserts (kept for backward compatibility) ── */

	public static function upsert_building( int $city_id, array $rec ): int {
		global $wpdb;
		$tb = WSC_Installer::table_buildings();

		$row = [
			'osm_type'   => substr( (string) $rec['osm_type'], 0, 8 ),
			'osm_id'     => (int) $rec['osm_id'],
			'city_id'    => $city_id,
			'category'   => substr( (string) $rec['category'], 0, 32 ),
			'name'       => mb_substr( (string) $rec['name'], 0, 255 ),
			'address'    => mb_substr( (string) $rec['address'], 0, 255 ),
			'height_m'   => (float) ( $rec['height_m'] ?? 0 ),
			'levels'     => (int) ( $rec['levels'] ?? 0 ),
			'footprint_geojson' => (string) $rec['geojson'],
			'centroid_lat' => (float) $rec['centroid'][1],
			'centroid_lng' => (float) $rec['centroid'][0],
			'bbox_west'  => (float) $rec['bbox']['w'],
			'bbox_south' => (float) $rec['bbox']['s'],
			'bbox_east'  => (float) $rec['bbox']['e'],
			'bbox_north' => (float) $rec['bbox']['n'],
			'tags_json'  => (string) $rec['tags_json'],
			'scanned_at' => current_time( 'mysql', true ),
		];

		$existing = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$tb} WHERE osm_type=%s AND osm_id=%d", $row['osm_type'], $row['osm_id']
		) );

		if ( $existing > 0 ) {
			$wpdb->update( $tb, $row, [ 'id' => $existing ] );
			$id = $existing;
		} else {
			$wpdb->insert( $tb, $row );
			$id = (int) $wpdb->insert_id;
		}

		do_action( 'wsc_building_imported', $id, $row );
		return $id;
	}

	public static function upsert_poi( int $city_id, array $rec ): int {
		global $wpdb;
		$tp = WSC_Installer::table_pois();
		$geo = json_decode( (string) $rec['geojson'], true );
		$gtype = is_array( $geo ) ? ( $geo['type'] ?? 'Point' ) : 'Point';

		$row = [
			'osm_type'  => substr( (string) $rec['osm_type'], 0, 8 ),
			'osm_id'    => (int) $rec['osm_id'],
			'city_id'   => $city_id,
			'category'  => substr( (string) $rec['category'], 0, 32 ),
			'name'      => mb_substr( (string) $rec['name'], 0, 255 ),
			'geom_type' => $gtype,
			'geojson'   => (string) $rec['geojson'],
			'lat'       => (float) $rec['centroid'][1],
			'lng'       => (float) $rec['centroid'][0],
			'tags_json' => (string) $rec['tags_json'],
			'scanned_at'=> current_time( 'mysql', true ),
		];

		$existing = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$tp} WHERE osm_type=%s AND osm_id=%d", $row['osm_type'], $row['osm_id']
		) );
		if ( $existing > 0 ) {
			$wpdb->update( $tp, $row, [ 'id' => $existing ] );
			return $existing;
		}
		$wpdb->insert( $tp, $row );
		return (int) $wpdb->insert_id;
	}

	public static function upsert_landuse( int $city_id, array $rec ): int {
		global $wpdb;
		$tl = WSC_Installer::table_landuse();
		$row = [
			'osm_type'  => substr( (string) $rec['osm_type'], 0, 8 ),
			'osm_id'    => (int) $rec['osm_id'],
			'city_id'   => $city_id,
			'kind'      => substr( (string) $rec['kind'], 0, 32 ),
			'name'      => mb_substr( (string) $rec['name'], 0, 255 ),
			'geojson'   => (string) $rec['geojson'],
			'tags_json' => (string) $rec['tags_json'],
			'scanned_at'=> current_time( 'mysql', true ),
		];

		$existing = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$tl} WHERE osm_type=%s AND osm_id=%d", $row['osm_type'], $row['osm_id']
		) );
		if ( $existing > 0 ) {
			$wpdb->update( $tl, $row, [ 'id' => $existing ] );
			return $existing;
		}
		$wpdb->insert( $tl, $row );
		return (int) $wpdb->insert_id;
	}

	public static function save_yard( int $building_id, float $buffer_m, array $geom ): int {
		global $wpdb;
		$ty       = WSC_Installer::table_yards();
		$geojson  = wp_json_encode( $geom );
		$area_m2  = self::polygon_area_m2( $geom );
		$now      = current_time( 'mysql', true );

		// Один запрос вместо SELECT+INSERT/UPDATE — на UNIQUE building_unique (building_id).
		// LAST_INSERT_ID(id) гарантирует, что insert_id корректен и для INSERT, и для UPDATE.
		// post_id не трогаем при upsert: задаётся отдельно через set_yard_post().
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$ty} (building_id, buffer_m, geojson, area_m2, updated_at)
			 VALUES (%d, %f, %s, %f, %s)
			 ON DUPLICATE KEY UPDATE
			   id=LAST_INSERT_ID(id),
			   buffer_m=VALUES(buffer_m),
			   geojson=VALUES(geojson),
			   area_m2=VALUES(area_m2),
			   updated_at=VALUES(updated_at)",
			$building_id, $buffer_m, $geojson, $area_m2, $now
		) );

		$yid = (int) $wpdb->insert_id;
		do_action( 'wsc_buffer_recomputed', $building_id, $yid );
		return $yid;
	}

	public static function set_yard_post( int $yard_id, int $post_id ): void {
		global $wpdb;
		// UPDATE с дополнительным WHERE post_id != %d пропускает write на диск, если значение
		// не изменилось. На bulk-recompute большинство зданий повторяют тот же post_id —
		// убирает no-op writes в InnoDB.
		$table = WSC_Installer::table_yards();
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET post_id=%d WHERE id=%d AND post_id<>%d",
			$post_id, $yard_id, $post_id
		) );
	}

	/**
	 * Денормализация: ergo_post_id хранится прямо в wsc_buildings.
	 * Заменяет дорогой JOIN по wp_postmeta('_wsc_building_id') в ergo-list.
	 */
	public static function set_ergo_post_id( int $building_id, int $post_id ): void {
		if ( $building_id <= 0 ) return;
		global $wpdb;
		$table = WSC_Installer::table_buildings();
		$new   = max( 0, $post_id );
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET ergo_post_id=%d WHERE id=%d AND ergo_post_id<>%d",
			$new, $building_id, $new
		) );
	}

	public static function get_building( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . WSC_Installer::table_buildings() . ' WHERE id=%d', $id ), ARRAY_A );
		return $row ?: null;
	}

	public static function get_yard_by_building( int $building_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . WSC_Installer::table_yards() . ' WHERE building_id=%d', $building_id ), ARRAY_A );
		return $row ?: null;
	}

	private static array $table_exists_cache = [];

	public static function table_exists( string $table ): bool {
		if ( isset( self::$table_exists_cache[ $table ] ) ) return self::$table_exists_cache[ $table ];
		global $wpdb;
		$prev = $wpdb->suppress_errors( true );
		$found = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$wpdb->suppress_errors( $prev );
		return self::$table_exists_cache[ $table ] = $found;
	}

	public static function count_buildings_for_city( int $city_id ): int {
		global $wpdb;
		$tb = WSC_Installer::table_buildings();
		if ( ! self::table_exists( $tb ) ) return 0;
		$prev = $wpdb->suppress_errors( true );
		$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tb} WHERE city_id=%d", $city_id ) );
		$wpdb->suppress_errors( $prev );
		return $n;
	}

	public static function count_buildings_for_country( string $iso2 ): int {
		$cities = WSCities_CPT::get_cities_for_country( strtoupper( $iso2 ) );
		$ids = array_map( 'intval', wp_list_pluck( $cities, 'id' ) );
		if ( empty( $ids ) ) return 0;
		global $wpdb;
		$tb = WSC_Installer::table_buildings();
		if ( ! self::table_exists( $tb ) ) return 0;
		$in = implode( ',', $ids );
		$prev = $wpdb->suppress_errors( true );
		$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tb} WHERE city_id IN ({$in})" );
		$wpdb->suppress_errors( $prev );
		return $n;
	}

	public static function count_yards_for_country( string $iso2 ): int {
		$cities = WSCities_CPT::get_cities_for_country( strtoupper( $iso2 ) );
		$ids = array_map( 'intval', wp_list_pluck( $cities, 'id' ) );
		if ( empty( $ids ) ) return 0;
		global $wpdb;
		$tb = WSC_Installer::table_buildings();
		$ty = WSC_Installer::table_yards();
		if ( ! self::table_exists( $tb ) || ! self::table_exists( $ty ) ) return 0;
		$in = implode( ',', $ids );
		$prev = $wpdb->suppress_errors( true );
		$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ty} y JOIN {$tb} b ON b.id=y.building_id WHERE b.city_id IN ({$in})" );
		$wpdb->suppress_errors( $prev );
		return $n;
	}

	private static function polygon_area_m2( array $geo ): float {
		// Approximate area via local equirectangular projection.
		$type = $geo['type'] ?? '';
		if ( $type !== 'Polygon' && $type !== 'MultiPolygon' ) return 0;
		$bbox = WSC_Parser::bbox_of( $geo );
		$proj = new WSC_LocalProjection( ( $bbox['w'] + $bbox['e'] ) / 2, ( $bbox['s'] + $bbox['n'] ) / 2 );

		$polys = $type === 'Polygon' ? [ $geo['coordinates'] ] : $geo['coordinates'];
		$total = 0.0;
		foreach ( $polys as $rings ) {
			$ring = $rings[0] ?? [];
			$pts = array_map( fn( $pt ) => $proj->forward( $pt[0], $pt[1] ), $ring );
			$n = count( $pts );
			if ( $n < 3 ) continue;
			$a = 0.0;
			for ( $i = 0, $j = $n - 1; $i < $n; $j = $i++ ) {
				$a += ( $pts[ $j ][0] + $pts[ $i ][0] ) * ( $pts[ $j ][1] - $pts[ $i ][1] );
			}
			$total += abs( $a / 2 );
		}
		return $total;
	}
}
