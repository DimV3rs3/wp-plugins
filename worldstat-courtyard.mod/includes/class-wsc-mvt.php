<?php
/**
 * MVT: REST endpoint /wsc/v1/tiles/{layer}/{z}/{x}/{y}.pbf — отдаёт GeoJSON
 * (для простоты: на клиенте используется как `source: 'geojson'` через REST,
 * либо для совместимости — как бинарный MVT, который собирает MapLibre сам).
 *
 * В нашей реализации возвращаем GeoJSON FeatureCollection — MapLibre этого
 * напрямую не понимает в формате pbf, поэтому клиент использует REST как
 * GeoJSON source. Кеш — файловый.
 *
 * @package WorldStatCourtyard
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSC_MVT {

	public static function flush_cache_for_city( int $city_id ): void {
		$dir = WSC_Settings::uploads_dir()['basedir'] . '/cache/mvt';
		if ( ! is_dir( $dir ) ) return;
		foreach ( (array) glob( $dir . '/city-' . $city_id . '-*.json' ) as $f ) @unlink( $f );
	}

	public static function flush_all_cache(): void {
		$dir = WSC_Settings::uploads_dir()['basedir'] . '/cache/mvt';
		if ( ! is_dir( $dir ) ) return;
		foreach ( (array) glob( $dir . '/*.json' ) as $f ) @unlink( $f );
	}

	private static function cache_path( string $layer, int $city_id ): string {
		$dir = WSC_Settings::uploads_dir()['basedir'] . '/cache/mvt';
		if ( ! is_dir( $dir ) ) wp_mkdir_p( $dir );
		return $dir . sprintf( '/city-%d-%s.json', $city_id, sanitize_key( $layer ) );
	}

	/**
	 * Build (or load from cache) FeatureCollection for a city/layer.
	 *
	 * @param array|null $bbox optional ['w','s','e','n'] — если задан, кэш не используется,
	 *                         выборка ограничена прямоугольником (для вьюпорт-загрузки слоёв).
	 */
	public static function build_layer( string $layer, int $city_id, ?array $bbox = null ): array {
		// Кэшируем только полный город — bbox-вырезка слишком вариативна.
		if ( $bbox === null ) {
			$cache = self::cache_path( $layer, $city_id );
			if ( file_exists( $cache ) && ( time() - filemtime( $cache ) ) < HOUR_IN_SECONDS ) {
				$j = json_decode( (string) file_get_contents( $cache ), true );
				if ( is_array( $j ) ) return $j;
			}
			$fc = self::compose_layer( $layer, $city_id, null );
			// Атомарная запись: temp + rename. Без этого крах PHP во время file_put_contents
			// оставит «полу-валидный» JSON, и следующее чтение json_decode вернёт null
			// → перестройка с нуля (CPU-spike). rename() атомарен на ext4/NTFS.
			$tmp = $cache . '.tmp.' . wp_generate_password( 6, false );
			$bytes = @file_put_contents( $tmp, wp_json_encode( $fc ) );
			if ( $bytes !== false ) {
				if ( ! @rename( $tmp, $cache ) ) {
					@unlink( $tmp );
				}
			}
			return $fc;
		}
		return self::compose_layer( $layer, $city_id, $bbox );
	}

	private static function compose_layer( string $layer, int $city_id, ?array $bbox ): array {
		global $wpdb;
		$features = [];
		$has_bbox = is_array( $bbox ) && isset( $bbox['w'], $bbox['s'], $bbox['e'], $bbox['n'] );
		switch ( $layer ) {
			case 'buildings':
				if ( $has_bbox ) {
					$rows = $wpdb->get_results( $wpdb->prepare(
						'SELECT id, osm_type, osm_id, category, name, address, height_m, levels, footprint_geojson FROM ' . WSC_Installer::table_buildings() .
						' WHERE city_id=%d AND centroid_lat BETWEEN %f AND %f AND centroid_lng BETWEEN %f AND %f',
						$city_id, (float) $bbox['s'], (float) $bbox['n'], (float) $bbox['w'], (float) $bbox['e']
					), ARRAY_A );
				} else {
					$rows = $wpdb->get_results( $wpdb->prepare(
						'SELECT id, osm_type, osm_id, category, name, address, height_m, levels, footprint_geojson FROM ' . WSC_Installer::table_buildings() . ' WHERE city_id=%d',
						$city_id
					), ARRAY_A );
				}
				foreach ( (array) $rows as $r ) {
					$g = json_decode( (string) $r['footprint_geojson'], true );
					if ( ! is_array( $g ) ) continue;
					$features[] = [
						'type' => 'Feature',
						'geometry' => $g,
						'properties' => [
							'id'       => (int) $r['id'],
							'osm_type' => (string) $r['osm_type'],
							'osm_id'   => (int) $r['osm_id'],
							'category' => (string) $r['category'],
							'name'     => (string) $r['name'],
							'address'  => (string) $r['address'],
							'height'   => (float) $r['height_m'],
							'levels'   => (int) $r['levels'],
						],
					];
				}
				break;

			case 'yards':
				$ty = WSC_Installer::table_yards();
				$tb = WSC_Installer::table_buildings();
				if ( $has_bbox ) {
					$rows = $wpdb->get_results( $wpdb->prepare(
						"SELECT y.id, y.building_id, y.buffer_m, y.geojson, b.category, b.name FROM {$ty} y JOIN {$tb} b ON b.id=y.building_id
						 WHERE b.city_id=%d AND b.centroid_lat BETWEEN %f AND %f AND b.centroid_lng BETWEEN %f AND %f",
						$city_id, (float) $bbox['s'], (float) $bbox['n'], (float) $bbox['w'], (float) $bbox['e']
					), ARRAY_A );
				} else {
					$rows = $wpdb->get_results( $wpdb->prepare(
						"SELECT y.id, y.building_id, y.buffer_m, y.geojson, b.category, b.name FROM {$ty} y JOIN {$tb} b ON b.id=y.building_id WHERE b.city_id=%d",
						$city_id
					), ARRAY_A );
				}
				foreach ( (array) $rows as $r ) {
					$g = json_decode( (string) $r['geojson'], true );
					if ( ! is_array( $g ) ) continue;
					$features[] = [
						'type' => 'Feature', 'geometry' => $g,
						'properties' => [
							'id' => (int) $r['id'], 'building_id' => (int) $r['building_id'],
							'buffer_m' => (float) $r['buffer_m'], 'category' => (string) $r['category'],
							'name' => (string) $r['name'],
						],
					];
				}
				break;

			case 'pois':
				if ( $has_bbox ) {
					$rows = $wpdb->get_results( $wpdb->prepare(
						'SELECT id, osm_id, category, name, geom_type, geojson, lat, lng, tags_json FROM ' . WSC_Installer::table_pois() .
						' WHERE city_id=%d AND lat BETWEEN %f AND %f AND lng BETWEEN %f AND %f',
						$city_id, (float) $bbox['s'], (float) $bbox['n'], (float) $bbox['w'], (float) $bbox['e']
					), ARRAY_A );
				} else {
					$rows = $wpdb->get_results( $wpdb->prepare(
						'SELECT id, osm_id, category, name, geom_type, geojson, lat, lng, tags_json FROM ' . WSC_Installer::table_pois() . ' WHERE city_id=%d',
						$city_id
					), ARRAY_A );
				}
				foreach ( (array) $rows as $r ) {
					$g = json_decode( (string) $r['geojson'], true );
					if ( ! is_array( $g ) ) {
						$g = [ 'type' => 'Point', 'coordinates' => [ (float) $r['lng'], (float) $r['lat'] ] ];
					}
					$tags = json_decode( (string) ( $r['tags_json'] ?? '' ), true );
					$tags = is_array( $tags ) ? $tags : [];
					$features[] = [
						'type' => 'Feature', 'geometry' => $g,
						'properties' => [
							'id' => (int) $r['id'], 'osm_id' => (int) $r['osm_id'],
							'category' => (string) $r['category'], 'name' => (string) $r['name'],
							'amenity' => (string) ( $tags['amenity'] ?? '' ),
						],
					];
				}
				break;

			case 'roads':
				$motor_hw = [ 'residential', 'living_street', 'service', 'unclassified', 'tertiary', 'secondary', 'primary', 'trunk' ];
				$foot_hw  = [ 'footway', 'path', 'pedestrian' ];
				if ( $has_bbox ) {
					$rows = $wpdb->get_results( $wpdb->prepare(
						'SELECT id, osm_id, geojson, tags_json, geom_type FROM ' . WSC_Installer::table_pois() .
						" WHERE city_id=%d AND geom_type IN ('LineString','MultiLineString','Polygon','MultiPolygon')
						   AND lat BETWEEN %f AND %f AND lng BETWEEN %f AND %f",
						$city_id, (float) $bbox['s'], (float) $bbox['n'], (float) $bbox['w'], (float) $bbox['e']
					), ARRAY_A );
				} else {
					$rows = $wpdb->get_results( $wpdb->prepare(
						'SELECT id, osm_id, geojson, tags_json, geom_type FROM ' . WSC_Installer::table_pois() .
						" WHERE city_id=%d AND geom_type IN ('LineString','MultiLineString','Polygon','MultiPolygon')",
						$city_id
					), ARRAY_A );
				}
				foreach ( (array) $rows as $r ) {
					$tags = json_decode( (string) ( $r['tags_json'] ?? '' ), true );
					$tags = is_array( $tags ) ? $tags : [];
					$g    = json_decode( (string) ( $r['geojson'] ?? '' ), true );
					if ( ! is_array( $g ) ) {
						continue;
					}
					$hw      = (string) ( $tags['highway'] ?? '' );
					$sw      = (string) ( $tags['sidewalk'] ?? '' );
					$amenity = (string) ( $tags['amenity'] ?? '' );
					$gt      = (string) ( $r['geom_type'] ?? '' );
					$line_ok = in_array( $gt, [ 'LineString', 'MultiLineString' ], true );
					$poly_ok = in_array( $gt, [ 'Polygon', 'MultiPolygon' ], true );
					$sw_ok   = in_array( $sw, [ 'both', 'left', 'right', 'yes' ], true );

					$road_class = null;
					if ( $amenity === 'parking' && ( $line_ok || $poly_ok ) ) {
						$road_class = 'parking';
					} elseif ( $line_ok && $hw !== '' ) {
						if ( in_array( $hw, $foot_hw, true ) ) {
							$road_class = 'foot';
						} elseif ( in_array( $hw, $motor_hw, true ) ) {
							$road_class = 'motor';
						}
					} elseif ( $line_ok && $sw_ok ) {
						$road_class = 'foot';
					}
					if ( ! $road_class ) {
						continue;
					}

					$features[] = [
						'type'       => 'Feature',
						'geometry'   => $g,
						'properties' => [
							'id'         => (int) $r['id'],
							'osm_id'     => (int) $r['osm_id'],
							'road_class' => $road_class,
							'highway'    => $hw,
						],
					];
				}
				break;

			case 'landuse':
				if ( $has_bbox ) {
					// Bbox-overlap: предполагаем, что bbox_* колонки заполнены (см. WSC_Writer::bulk_upsert_landuse).
					// Старые записи с нулевыми bbox-полями попадут в выборку через OR — деградация в безопасный путь.
					$rows = $wpdb->get_results( $wpdb->prepare(
						'SELECT id, kind, name, geojson, bbox_west, bbox_east, bbox_south, bbox_north FROM ' . WSC_Installer::table_landuse() .
						' WHERE city_id=%d AND (
							(bbox_west=0 AND bbox_east=0)
							OR (bbox_east >= %f AND bbox_west <= %f AND bbox_north >= %f AND bbox_south <= %f)
						 )',
						$city_id, (float) $bbox['w'], (float) $bbox['e'], (float) $bbox['s'], (float) $bbox['n']
					), ARRAY_A );
				} else {
					$rows = $wpdb->get_results( $wpdb->prepare(
						'SELECT id, kind, name, geojson FROM ' . WSC_Installer::table_landuse() . ' WHERE city_id=%d',
						$city_id
					), ARRAY_A );
				}
				foreach ( (array) $rows as $r ) {
					$g = json_decode( (string) $r['geojson'], true );
					if ( ! is_array( $g ) ) continue;
					$features[] = [
						'type' => 'Feature', 'geometry' => $g,
						'properties' => [ 'id' => (int) $r['id'], 'kind' => (string) $r['kind'], 'name' => (string) $r['name'] ],
					];
				}
				break;
		}
		return [ 'type' => 'FeatureCollection', 'features' => $features ];
	}
}
