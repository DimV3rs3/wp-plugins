<?php
/**
 * Примитивы геометрии буфера (point-in-polygon).
 *
 * @package WorldStatCourtyard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WSC_Geom {

	/**
	 * Точка [lng, lat] внутри Polygon / MultiPolygon (GeoJSON).
	 *
	 * @param float[]               $pt   [ lng, lat ].
	 * @param array<string, mixed>  $geom Массив GeoJSON типа Polygon | MultiPolygon.
	 */
	public static function point_in_polygon( array $pt, array $geom ): bool {
		$type = $geom['type'] ?? '';
		if ( 'Polygon' === $type ) {
			$polys = [ $geom['coordinates'] ];
		} elseif ( 'MultiPolygon' === $type ) {
			$polys = $geom['coordinates'];
		} else {
			return false;
		}

		foreach ( $polys as $rings ) {
			$ring = $rings[0] ?? [];
			if ( self::pip_ring( $pt, $ring ) ) {
				$inside_hole = false;
				for ( $i = 1; $i < count( $rings ); $i++ ) {
					if ( self::pip_ring( $pt, $rings[ $i ] ) ) {
						$inside_hole = true;
						break;
					}
				}
				if ( ! $inside_hole ) {
					return true;
				}
			}
		}
		return false;
	}

	private static function pip_ring( array $pt, array $ring ): bool {
		$x = $pt[0];
		$y = $pt[1];
		$inside = false;
		$n      = count( $ring );
		for ( $i = 0, $j = $n - 1; $i < $n; $j = $i++ ) {
			$xi         = $ring[ $i ][0];
			$yi         = $ring[ $i ][1];
			$xj         = $ring[ $j ][0];
			$yj         = $ring[ $j ][1];
			$intersect  = ( ( $yi > $y ) !== ( $yj > $y ) )
				&& ( $x < ( $xj - $xi ) * ( $y - $yi ) / ( ( $yj - $yi ) ?: 1e-12 ) + $xi );
			if ( $intersect ) {
				$inside = ! $inside;
			}
		}
		return $inside;
	}

	/**
	 * Отрезок [a→b] пересекается с [c→d] в плоскости (исключение коллинеарного совпадения — редкий кейс для OSM).
	 *
	 * @param float[] $a [ lng, lat ].
	 * @param float[] $b .
	 * @param float[] $c .
	 * @param float[] $d .
	 */
	public static function segment_intersects_segment( array $a, array $b, array $c, array $d ): bool {
		$o = static function ( array $p, array $q, array $r ): float {
			return ( $q[1] - $p[1] ) * ( $r[0] - $q[0] ) - ( $q[0] - $p[0] ) * ( $r[1] - $q[1] );
		};
		$on = static function ( array $p, array $q, array $r ): bool {
			return min( $p[0], $q[0] ) <= $r[0] && $r[0] <= max( $p[0], $q[0] )
				&& min( $p[1], $q[1] ) <= $r[1] && $r[1] <= max( $p[1], $q[1] );
		};

		$o1 = $o( $a, $b, $c );
		$o2 = $o( $a, $b, $d );
		$o3 = $o( $c, $d, $a );
		$o4 = $o( $c, $d, $b );

		if ( ( $o1 > 1e-12 && $o2 < -1e-12 || $o1 < -1e-12 && $o2 > 1e-12 )
			&& ( $o3 > 1e-12 && $o4 < -1e-12 || $o3 < -1e-12 && $o4 > 1e-12 ) ) {
			return true;
		}
		$eps = 1e-9;
		if ( abs( $o1 ) <= $eps && $on( $a, $b, $c ) ) return true;
		if ( abs( $o2 ) <= $eps && $on( $a, $b, $d ) ) return true;
		if ( abs( $o3 ) <= $eps && $on( $c, $d, $a ) ) return true;
		if ( abs( $o4 ) <= $eps && $on( $c, $d, $b ) ) return true;

		return false;
	}

	/**
	 * Кольцо в GeoJSON-порядке (замкнутое или незамкнутое).
	 *
	 * @param float[][] $ring .
	 */
	public static function linestring_crosses_polygon_ring( array $coords, array $ring ): bool {
		if ( count( $coords ) < 2 ) {
			return false;
		}
		if ( empty( $ring ) ) {
			return false;
		}
		$n = count( $ring );
		foreach ( $coords as $v ) {
			if ( isset( $v[0], $v[1] ) && self::point_in_polygon( $v, [ 'type' => 'Polygon', 'coordinates' => [ $ring ] ] ) ) {
				return true;
			}
		}
		for ( $ci = 0, $cj = count( $coords ) - 1; $ci < count( $coords ); $cj = $ci++ ) {
			$a = $coords[ $cj ];
			$b = $coords[ $ci ];
			if ( ! isset( $a[0], $a[1], $b[0], $b[1] ) ) continue;
			for ( $ki = 0, $kj = $n - 1; $ki < $n; $kj = $ki++ ) {
				$r0 = $ring[ $kj ];
				$r1 = $ring[ $ki ];
				if ( ! isset( $r0[0], $r0[1], $r1[0], $r1[1] ) ) continue;
				if ( self::segment_intersects_segment( $a, $b, $r0, $r1 ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Линия (координаты LineString) пересекает Polygon / MultiPolygon (буфер).
	 *
	 * @param float[][] $coords Списк узлов полилинии.
	 */
	public static function linestring_intersects_polygon( array $coords, array $poly_geom ): bool {
		$t = strtolower( (string) ( $poly_geom['type'] ?? '' ) );
		if ( 'polygon' === $t ) {
			$coords_rings = isset( $poly_geom['coordinates'] ) ? $poly_geom['coordinates'] : [];
			if ( ! is_array( $coords_rings ) || ! isset( $coords_rings[0] ) ) return false;

			foreach ( $coords as $pt ) {
				if ( isset( $pt[0], $pt[1] ) && self::point_in_polygon( $pt, $poly_geom ) ) {
					return true;
				}
			}
			$outer = $coords_rings[0];
			if ( self::linestring_crosses_polygon_ring( $coords, $outer ) ) {
				return true;
			}
			return false;
		}

		if ( 'multipolygon' === $t ) {
			$polys = $poly_geom['coordinates'] ?? [];
			if ( ! is_array( $polys ) ) return false;

			foreach ( $polys as $rings ) {
				$geom = [ 'type' => 'Polygon', 'coordinates' => $rings ];
				if ( self::linestring_intersects_polygon( $coords, $geom ) ) {
					return true;
				}
			}
			return false;
		}

		return false;
	}

	/**
	 * @param array<int, float[]> $line_coords .
	 */
	public static function multilinestring_intersects_polygon( array $line_coords, array $poly_geom ): bool {
		foreach ( $line_coords as $part ) {
			if ( ! is_array( $part ) ) continue;
			if ( self::linestring_intersects_polygon( $part, $poly_geom ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * GeoJSON LineString / MultiLineString пересекает буфер (Polygon/MultiPolygon).
	 *
	 * @param array<string, mixed> $line_geom .
	 * @param array<string, mixed> $poly_geom .
	 */
	public static function line_geo_intersects_polygon( array $line_geom, array $poly_geom ): bool {
		$t = strtolower( (string) ( $line_geom['type'] ?? '' ) );
		if ( 'linestring' === $t ) {
			$c = $line_geom['coordinates'] ?? [];
			return is_array( $c ) && self::linestring_intersects_polygon( $c, $poly_geom );
		}
		if ( 'multilinestring' === $t ) {
			$c = $line_geom['coordinates'] ?? [];
			return is_array( $c ) && self::multilinestring_intersects_polygon( $c, $poly_geom );
		}
		return false;
	}

	/**
	 * Два полигона (Polygon / MultiPolygon) пересекаются (вершина одного внутри другого или пересечение рёбер).
	 *
	 * @param array<string, mixed> $g1 .
	 * @param array<string, mixed> $g2 .
	 */
	public static function polygon_geometry_intersects( array $g1, array $g2 ): bool {
		foreach ( self::polygon_exterior_rings( $g1 ) as $ring1 ) {
			foreach ( $ring1 as $pt ) {
				if ( isset( $pt[0], $pt[1] ) && self::point_in_polygon( $pt, $g2 ) ) {
					return true;
				}
			}
		}
		foreach ( self::polygon_exterior_rings( $g2 ) as $ring2 ) {
			foreach ( $ring2 as $pt ) {
				if ( isset( $pt[0], $pt[1] ) && self::point_in_polygon( $pt, $g1 ) ) {
					return true;
				}
			}
		}
		foreach ( self::polygon_exterior_rings( $g1 ) as $ring1 ) {
			foreach ( self::polygon_exterior_rings( $g2 ) as $ring2 ) {
				if ( self::rings_edges_intersect( $ring1, $ring2 ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * @return array<int, array<int, float[]>>
	 */
	private static function polygon_exterior_rings( array $geom ): array {
		$t = strtolower( (string) ( $geom['type'] ?? '' ) );
		if ( 'polygon' === $t ) {
			$c = $geom['coordinates'] ?? [];
			if ( isset( $c[0] ) && is_array( $c[0] ) ) {
				return [ $c[0] ];
			}
			return [];
		}
		if ( 'multipolygon' === $t ) {
			$out = [];
			foreach ( (array) ( $geom['coordinates'] ?? [] ) as $poly ) {
				if ( isset( $poly[0] ) && is_array( $poly[0] ) ) {
					$out[] = $poly[0];
				}
			}
			return $out;
		}
		return [];
	}

	/**
	 * @param float[][] $r1 .
	 * @param float[][] $r2 .
	 */
	private static function rings_edges_intersect( array $r1, array $r2 ): bool {
		$n1 = count( $r1 );
		$n2 = count( $r2 );
		for ( $i = 0, $j = $n1 - 1; $i < $n1; $j = $i++ ) {
			$a = $r1[ $j ];
			$b = $r1[ $i ];
			if ( ! isset( $a[0], $a[1], $b[0], $b[1] ) ) continue;
			for ( $k = 0, $l = $n2 - 1; $k < $n2; $l = $k++ ) {
				$c = $r2[ $l ];
				$d = $r2[ $k ];
				if ( ! isset( $c[0], $c[1], $d[0], $d[1] ) ) continue;
				if ( self::segment_intersects_segment( $a, $b, $c, $d ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Минимальное расстояние от точки [lng,lat] до GeoJSON-геометрии (м; приближение для городского масштаба).
	 * Полигон: 0 внутри; иначе расстояние до ближайшего ребра внешних контуров.
	 *
	 * @param float[]              $pt   [ lng, lat ].
	 * @param array<string, mixed> $geom GeoJSON.
	 */
	public static function min_distance_point_to_geometry_m( array $pt, array $geom ): float {
		if ( ! isset( $pt[0], $pt[1] ) ) {
			return INF;
		}
		$lng = (float) $pt[0];
		$lat = (float) $pt[1];
		$t   = strtolower( (string) ( $geom['type'] ?? '' ) );
		switch ( $t ) {
			case 'point':
				$c = $geom['coordinates'] ?? [];
				if ( ! isset( $c[0], $c[1] ) ) {
					return INF;
				}
				return self::haversine_m( $lat, $lng, (float) $c[1], (float) $c[0] );
			case 'linestring':
				return self::min_dist_point_linestring_m( $pt, (array) ( $geom['coordinates'] ?? [] ) );
			case 'multilinestring':
				$min = INF;
				foreach ( (array) ( $geom['coordinates'] ?? [] ) as $part ) {
					if ( is_array( $part ) ) {
						$min = min( $min, self::min_dist_point_linestring_m( $pt, $part ) );
					}
				}
				return is_finite( $min ) ? $min : INF;
			case 'polygon':
			case 'multipolygon':
				if ( self::point_in_polygon( $pt, $geom ) ) {
					return 0.0;
				}
				$d = INF;
				foreach ( self::polygon_exterior_rings( $geom ) as $ring ) {
					$n = count( $ring );
					if ( $n < 2 ) {
						continue;
					}
					for ( $i = 0; $i < $n - 1; $i++ ) {
						$a = $ring[ $i ];
						$b = $ring[ $i + 1 ];
						if ( ! isset( $a[0], $a[1], $b[0], $b[1] ) ) {
							continue;
						}
						$d = min( $d, self::dist_point_to_segment_m( $pt, $a, $b ) );
					}
				}
				return is_finite( $d ) ? $d : INF;
			default:
				return INF;
		}
	}

	/**
	 * @param float[]            $pt      [ lng, lat ].
	 * @param array<int, float[]> $coords LineString.
	 */
	private static function min_dist_point_linestring_m( array $pt, array $coords ): float {
		$n = count( $coords );
		if ( $n < 2 ) {
			return INF;
		}
		$min = INF;
		for ( $i = 0; $i < $n - 1; $i++ ) {
			$a = $coords[ $i ];
			$b = $coords[ $i + 1 ];
			if ( ! isset( $a[0], $a[1], $b[0], $b[1] ) ) {
				continue;
			}
			$min = min( $min, self::dist_point_to_segment_m( $pt, $a, $b ) );
		}
		return $min;
	}

	/**
	 * Расстояние от точки до отрезка (м, локально equirectangular).
	 *
	 * @param float[] $p [ lng, lat ].
	 * @param float[] $a .
	 * @param float[] $b .
	 */
	private static function dist_point_to_segment_m( array $p, array $a, array $b ): float {
		$lat0 = deg2rad( ( (float) $a[1] + (float) $b[1] ) / 2.0 );
		$px   = ( (float) $p[0] - (float) $a[0] ) * cos( $lat0 ) * 111320.0;
		$py   = ( (float) $p[1] - (float) $a[1] ) * 111320.0;
		$bx   = ( (float) $b[0] - (float) $a[0] ) * cos( $lat0 ) * 111320.0;
		$by   = ( (float) $b[1] - (float) $a[1] ) * 111320.0;
		$len2 = $bx * $bx + $by * $by;
		if ( $len2 < 1e-9 ) {
			return sqrt( $px * $px + $py * $py );
		}
		$t = max( 0.0, min( 1.0, ( $px * $bx + $py * $by ) / $len2 ) );
		$qx = $t * $bx;
		$qy = $t * $by;
		$dx = $px - $qx;
		$dy = $py - $qy;
		return sqrt( $dx * $dx + $dy * $dy );
	}

	private static function haversine_m( float $lat1, float $lng1, float $lat2, float $lng2 ): float {
		$earth = 6371000.0;
		$p1    = deg2rad( $lat1 );
		$p2    = deg2rad( $lat2 );
		$dphi  = deg2rad( $lat2 - $lat1 );
		$dl    = deg2rad( $lng2 - $lng1 );
		$h     = sin( $dphi / 2 ) ** 2 + cos( $p1 ) * cos( $p2 ) * sin( $dl / 2 ) ** 2;
		return 2.0 * $earth * asin( min( 1.0, sqrt( $h ) ) );
	}
}
