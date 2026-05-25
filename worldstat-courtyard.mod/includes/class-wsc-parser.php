<?php
/**
 * Parser: OSM Overpass JSON → нормализованные feature-структуры для импорта.
 *
 * @package WorldStatCourtyard
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSC_Parser {

	/**
	 * Из ответа Overpass JSON ($json['elements']) получает featureset.
	 *
	 * @return array{
	 *   buildings:array<int,array>,
	 *   pois:array<int,array>,
	 *   landuse:array<int,array>,
	 *   trees:array<int,array>
	 * }
	 */
	public static function parse_overpass( array $elements ): array {
		$nodes = [];
		$ways  = [];
		$out   = [ 'buildings' => [], 'pois' => [], 'landuse' => [], 'trees' => [] ];

		foreach ( $elements as $el ) {
			$type = $el['type'] ?? '';

			// Index for geometry resolution fallback (relations referencing ways).
			if ( $type === 'node' ) {
				$nodes[ (int) $el['id'] ] = [ (float) $el['lon'], (float) $el['lat'] ];
			} elseif ( $type === 'way' ) {
				$ways[ (int) $el['id'] ] = $el;
			}

			$tags = (array) ( $el['tags'] ?? [] );
			if ( empty( $tags ) && $type === 'node' ) continue;

			$entity = WSC_Categories::entity_type( $tags, $type );
			$cat    = WSC_Categories::categorize( $tags );
			$name   = self::pick_name( $tags );
			$addr   = self::compose_address( $tags );

			if ( $type === 'node' ) {
				$lon = (float) ( $el['lon'] ?? 0 );
				$lat = (float) ( $el['lat'] ?? 0 );
				if ( ! $lat && ! $lon ) continue;
				$geo = [ 'type' => 'Point', 'coordinates' => [ $lon, $lat ] ];
				if ( $entity === 'tree' ) {
					$out['trees'][] = self::pack_record( 'node', (int) $el['id'], $cat, $name, $addr, $geo, $tags, $lon, $lat );
				} else {
					$out['pois'][] = self::pack_record( 'node', (int) $el['id'], $cat, $name, $addr, $geo, $tags, $lon, $lat );
				}
				continue;
			}

			if ( $type === 'way' ) {
				$coords = self::resolve_way_coords( $el, $nodes );
				if ( empty( $coords ) ) continue;

				$is_closed = self::is_closed( $coords );
				if ( $entity === 'building' || ( $is_closed && ! empty( $tags['building'] ) ) ) {
					$ring  = self::ensure_closed( $coords );
					$geo   = [ 'type' => 'Polygon', 'coordinates' => [ $ring ] ];
					[ $clng, $clat ] = self::centroid( $ring );
					$out['buildings'][] = self::pack_record( 'way', (int) $el['id'], $cat, $name, $addr, $geo, $tags, $clng, $clat );
				} elseif ( $entity === 'landuse' ) {
					$ring = self::ensure_closed( $coords );
					$geo  = [ 'type' => 'Polygon', 'coordinates' => [ $ring ] ];
					$out['landuse'][] = self::pack_record_landuse( 'way', (int) $el['id'], $tags, $geo, $name );
				} else {
					if ( $is_closed ) {
						$ring = self::ensure_closed( $coords );
						$geo  = [ 'type' => 'Polygon', 'coordinates' => [ $ring ] ];
						[ $clng, $clat ] = self::centroid( $ring );
					} else {
						$geo  = [ 'type' => 'LineString', 'coordinates' => $coords ];
						[ $clng, $clat ] = $coords[ intdiv( count( $coords ), 2 ) ];
					}
					$out['pois'][] = self::pack_record( 'way', (int) $el['id'], $cat, $name, $addr, $geo, $tags, $clng, $clat );
				}
			}

			if ( $type === 'relation' ) {
				$rings = self::resolve_relation_polygon( $el, $ways, $nodes );
				if ( empty( $rings ) ) {
					continue;
				}
				$geo = [ 'type' => 'MultiPolygon', 'coordinates' => array_map( fn( $r ) => [ $r ], $rings ) ];
				if ( $entity === 'building' ) {
					[ $clng, $clat ] = self::centroid( $rings[0] );
					$out['buildings'][] = self::pack_record( 'relation', (int) $el['id'], $cat, $name, $addr, $geo, $tags, $clng, $clat );
				} elseif ( $entity === 'landuse' ) {
					$out['landuse'][] = self::pack_record_landuse( 'relation', (int) $el['id'], $tags, $geo, $name );
				} else {
					[ $clng, $clat ] = self::centroid( $rings[0] );
					$out['pois'][] = self::pack_record( 'relation', (int) $el['id'], $cat, $name, $addr, $geo, $tags, $clng, $clat );
				}
			}
		}

		// $nodes/$ways держат геометрию всех элементов batch'а (4 км × 4 км в плотном городе —
		// до 10K элементов, десятки МБ). После выхода из цикла они уже не нужны для caller'а —
		// явный unset освобождает память до того, как ingest_features() пойдёт пихать в БД.
		unset( $nodes, $ways );

		return $out;
	}

	private static function pack_record( string $osm_type, int $osm_id, string $cat, string $name, string $addr, array $geo, array $tags, float $lng, float $lat ): array {
		$bbox = self::bbox_of( $geo );
		$h    = self::resolve_height( $tags, $cat );
		$lvl  = isset( $tags['building:levels'] ) ? (int) $tags['building:levels'] : 0;

		// Footway / sidewalk-way: сохраним отдельно в tags_json явный признак + распарсенную ширину (м).
		$is_footway = isset( $tags['highway'] ) && in_array( (string) $tags['highway'], [ 'footway', 'path', 'pedestrian' ], true );
		$is_sidewalk = isset( $tags['sidewalk'] ) && in_array( (string) $tags['sidewalk'], [ 'both', 'left', 'right', 'yes' ], true );
		if ( $is_footway || $is_sidewalk ) {
			$width = null;
			if ( isset( $tags['sidewalk:width'] ) && is_numeric( $tags['sidewalk:width'] ) ) {
				$width = (float) $tags['sidewalk:width'];
			} elseif ( isset( $tags['width'] ) && is_numeric( $tags['width'] ) ) {
				$width = (float) $tags['width'];
			}
			if ( $width !== null ) {
				$tags['_wsc_sidewalk_width_m'] = $width;
			}
			$tags['_wsc_is_footway'] = $is_footway ? 1 : 0;
		}

		return [
			'osm_type'   => $osm_type,
			'osm_id'     => $osm_id,
			'category'   => $cat,
			'name'       => $name,
			'address'    => $addr,
			'height_m'   => $h,
			'levels'     => $lvl,
			'geojson'    => wp_json_encode( $geo ),
			'centroid'   => [ $lng, $lat ],
			'bbox'       => $bbox,
			'tags_json'  => wp_json_encode( $tags ),
		];
	}

	private static function pack_record_landuse( string $osm_type, int $osm_id, array $tags, array $geo, string $name ): array {
		$kind = class_exists( 'WSC_Landcover' )
			? WSC_Landcover::kind_from_landuse_tags( $tags )
			: ( $tags['leisure'] ?? $tags['landuse'] ?? $tags['natural'] ?? 'other' );
		$bbox = self::bbox_of( $geo );
		return [
			'osm_type'  => $osm_type,
			'osm_id'    => $osm_id,
			'kind'      => $kind,
			'name'      => $name,
			'geojson'   => wp_json_encode( $geo ),
			'tags_json' => wp_json_encode( $tags ),
			'bbox'      => $bbox,
		];
	}

	public static function pick_name( array $tags ): string {
		$lang = WSC_Settings::get_language();
		$order = $lang === 'ru'    ? [ 'name:ru', 'name:en', 'name' ]
		      : ( $lang === 'en'   ? [ 'name:en', 'name', 'name:ru' ]
		      : [ 'name', 'name:en', 'name:ru' ] );
		foreach ( $order as $k ) {
			if ( ! empty( $tags[ $k ] ) ) return (string) $tags[ $k ];
		}
		return '';
	}

	public static function compose_address( array $tags ): string {
		$lang = WSC_Settings::get_language();
		$keys = [ 'addr:street', 'addr:housenumber' ];
		$pref = $lang === 'ru' ? ':ru' : ( $lang === 'en' ? ':en' : '' );
		$street = $tags[ 'addr:street' . $pref ] ?? $tags['addr:street'] ?? '';
		$house  = $tags['addr:housenumber'] ?? '';
		$out = trim( $street . ' ' . $house );
		return $out;
	}

	public static function resolve_height( array $tags, string $cat ): float {
		if ( isset( $tags['height'] ) && is_numeric( $tags['height'] ) ) return (float) $tags['height'];
		if ( isset( $tags['building:levels'] ) ) {
			$lvl = (int) $tags['building:levels'];
			if ( $lvl > 0 ) return $lvl * 3.0;
		}
		return WSC_Settings::get_height_for_category( $cat );
	}

	private static function resolve_way_coords( array $el, array $nodes ): array {
		// Overpass with `out geom;` inlines geometry as 'geometry' array.
		if ( ! empty( $el['geometry'] ) && is_array( $el['geometry'] ) ) {
			$out = [];
			foreach ( $el['geometry'] as $pt ) {
				$out[] = [ (float) $pt['lon'], (float) $pt['lat'] ];
			}
			return $out;
		}
		// Otherwise resolve via 'nodes' array.
		$out = [];
		foreach ( (array) ( $el['nodes'] ?? [] ) as $nid ) {
			if ( isset( $nodes[ (int) $nid ] ) ) $out[] = $nodes[ (int) $nid ];
		}
		return $out;
	}

	private static function resolve_relation_polygon( array $el, array $ways, array $nodes ): array {
		$rings = [];
		foreach ( (array) ( $el['members'] ?? [] ) as $m ) {
			if ( ( $m['type'] ?? '' ) !== 'way' ) continue;
			if ( ! empty( $m['geometry'] ) ) {
				$coords = [];
				foreach ( $m['geometry'] as $pt ) $coords[] = [ (float) $pt['lon'], (float) $pt['lat'] ];
				if ( count( $coords ) >= 3 ) $rings[] = self::ensure_closed( $coords );
			} elseif ( isset( $ways[ (int) $m['ref'] ] ) ) {
				$coords = self::resolve_way_coords( $ways[ (int) $m['ref'] ], $nodes );
				if ( count( $coords ) >= 3 ) $rings[] = self::ensure_closed( $coords );
			}
		}
		return $rings;
	}

	public static function is_closed( array $coords ): bool {
		if ( count( $coords ) < 4 ) return false;
		$a = $coords[0]; $b = end( $coords );
		return abs( $a[0] - $b[0] ) < 1e-9 && abs( $a[1] - $b[1] ) < 1e-9;
	}

	public static function ensure_closed( array $coords ): array {
		if ( count( $coords ) < 3 ) return $coords;
		if ( ! self::is_closed( $coords ) ) $coords[] = $coords[0];
		return $coords;
	}

	public static function centroid( array $ring ): array {
		$sx = 0; $sy = 0; $n = 0;
		foreach ( $ring as $pt ) { $sx += $pt[0]; $sy += $pt[1]; $n++; }
		return $n > 0 ? [ $sx / $n, $sy / $n ] : [ 0, 0 ];
	}

	public static function bbox_of( array $geo ): array {
		$w = INF; $s = INF; $e = -INF; $n = -INF;
		$walk = function ( $arr ) use ( &$walk, &$w, &$s, &$e, &$n ) {
			if ( ! is_array( $arr ) ) return;
			if ( isset( $arr[0] ) && is_numeric( $arr[0] ) ) {
				$w = min( $w, $arr[0] ); $e = max( $e, $arr[0] );
				$s = min( $s, $arr[1] ); $n = max( $n, $arr[1] );
				return;
			}
			foreach ( $arr as $v ) $walk( $v );
		};
		$walk( $geo['coordinates'] ?? [] );
		return [ 'w' => $w === INF ? 0 : $w, 's' => $s === INF ? 0 : $s, 'e' => $e === -INF ? 0 : $e, 'n' => $n === -INF ? 0 : $n ];
	}
}
