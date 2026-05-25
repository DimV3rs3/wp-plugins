<?php
/**
 * Buffer: расчёт буфера полигона на расстояние в метрах.
 *
 * Backends: php-geos → ogr2ogr CLI → pure PHP Minkowski (point+arc 16 segs).
 *
 * @package WorldStatCourtyard
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSC_Buffer {

	const ARC_SEGMENTS = 16;

	/**
	 * Кэш определения бэкенда — критично для производительности recompute_buffers.
	 * Без него compute() на каждом здании форкает shell_exec('ogr2ogr --version'),
	 * что на 5000 зданий = тысячи процесс-спавнов (минуты потерь на Windows).
	 */
	private static ?string $backend_cache = null;
	private static ?bool $ogr2ogr_cache   = null;
	private static ?bool $osmium_cache    = null;

	public static function backend_available(): string {
		if ( self::$backend_cache !== null ) return self::$backend_cache;
		if ( class_exists( 'GEOSGeometry' ) || function_exists( 'GEOSWKTReader' ) ) {
			return self::$backend_cache = 'geos';
		}
		if ( self::ogr2ogr_available() ) {
			return self::$backend_cache = 'ogr2ogr';
		}
		return self::$backend_cache = 'php';
	}

	public static function ogr2ogr_available(): bool {
		if ( self::$ogr2ogr_cache !== null ) return self::$ogr2ogr_cache;
		if ( ! function_exists( 'shell_exec' ) ) return self::$ogr2ogr_cache = false;
		$bin = WSC_Settings::get_ogr2ogr_path();
		$test = @shell_exec( escapeshellcmd( $bin ) . ' --version 2>&1' );
		return self::$ogr2ogr_cache = ( is_string( $test ) && stripos( $test, 'GDAL' ) !== false );
	}

	public static function osmium_available(): bool {
		if ( self::$osmium_cache !== null ) return self::$osmium_cache;
		if ( ! function_exists( 'shell_exec' ) ) return self::$osmium_cache = false;
		$bin = trim( WSC_Settings::get_osmium_path() );

		// WSL-режим: путь начинается с "wsl" (без расширения exe). Тогда дёргаем
		// `wsl osmium --version` без escapeshellcmd, иначе пробел между "wsl" и "osmium"
		// съедается и команда превращается в одиночный токен.
		$lower = strtolower( $bin );
		if ( $lower === 'wsl' || strpos( $lower, 'wsl ' ) === 0 ) {
			$cmd = ( $lower === 'wsl' ? 'wsl osmium' : $bin ) . ' --version 2>&1';
			$test = @shell_exec( $cmd );
		} else {
			$test = @shell_exec( escapeshellcmd( $bin ) . ' --version 2>&1' );
		}
		return self::$osmium_cache = ( is_string( $test ) && stripos( $test, 'osmium' ) !== false );
	}

	/**
	 * Compute buffered polygon (GeoJSON geometry) at given meters.
	 *
	 * @param array $geo  GeoJSON geometry (Polygon/MultiPolygon)
	 * @return array|null GeoJSON geometry (Polygon/MultiPolygon) or null
	 */
	public static function compute( array $geo, float $meters ): ?array {
		$meters = max( 0.5, $meters );
		$backend = self::backend_available();

		try {
			if ( $backend === 'geos' )    return self::compute_geos( $geo, $meters );
			if ( $backend === 'ogr2ogr' ) return self::compute_ogr2ogr( $geo, $meters );
		} catch ( Throwable $e ) {
			// Fall through to PHP backend.
		}
		return self::compute_php( $geo, $meters );
	}

	/* ───── GEOS backend ───── */

	private static function compute_geos( array $geo, float $meters ): ?array {
		// GEOS works in a flat Cartesian plane; we project to local AEQD (meters) around centroid.
		$center = self::geom_centroid( $geo );
		$proj   = new WSC_LocalProjection( $center[0], $center[1] );

		$projected = self::project_geom( $geo, $proj, true );
		$wkt       = self::geojson_to_wkt( $projected );

		$reader = function_exists( 'GEOSWKTReader' ) ? GEOSWKTReader() : new GEOSWKTReader();
		$writer = function_exists( 'GEOSWKTWriter' ) ? GEOSWKTWriter() : new GEOSWKTWriter();
		$g      = $reader->read( $wkt );
		$buf    = $g->buffer( $meters );
		$out    = $writer->write( $buf );
		$out_geo = self::wkt_to_geojson( $out );
		if ( ! $out_geo ) return null;
		return self::project_geom( $out_geo, $proj, false );
	}

	/* ───── ogr2ogr backend ───── */

	private static function compute_ogr2ogr( array $geo, float $meters ): ?array {
		$tmp_in  = wp_tempnam( 'wsc_in.geojson' );
		$tmp_out = wp_tempnam( 'wsc_out.geojson' );
		@unlink( $tmp_out ); // ogr2ogr refuses to overwrite by default.

		// Project locally so ST_Buffer works in meters.
		$center = self::geom_centroid( $geo );
		$proj   = new WSC_LocalProjection( $center[0], $center[1] );
		$projected = self::project_geom( $geo, $proj, true );
		$fc        = [ 'type' => 'FeatureCollection', 'features' => [ [ 'type' => 'Feature', 'properties' => new stdClass(), 'geometry' => $projected ] ] ];
		file_put_contents( $tmp_in, wp_json_encode( $fc ) );

		$bin = escapeshellcmd( WSC_Settings::get_ogr2ogr_path() );
		$sql = sprintf( 'SELECT ST_Buffer(geometry, %F) AS geometry FROM features', $meters );
		$cmd = sprintf( '%s -f GeoJSON %s %s -dialect SQLite -sql %s 2>&1',
			$bin, escapeshellarg( $tmp_out ), escapeshellarg( $tmp_in ), escapeshellarg( $sql ) );
		@shell_exec( $cmd );

		if ( ! file_exists( $tmp_out ) ) return null;
		$json = json_decode( (string) file_get_contents( $tmp_out ), true );
		@unlink( $tmp_in ); @unlink( $tmp_out );
		if ( ! is_array( $json ) || empty( $json['features'][0]['geometry'] ) ) return null;
		return self::project_geom( $json['features'][0]['geometry'], $proj, false );
	}

	/* ───── Pure PHP Minkowski ───── */

	private static function compute_php( array $geo, float $meters ): ?array {
		$type = $geo['type'] ?? '';
		if ( $type === 'Polygon' )      $polys = [ $geo['coordinates'] ];
		elseif ( $type === 'MultiPolygon' ) $polys = $geo['coordinates'];
		else return null;

		$center = self::geom_centroid( $geo );
		$proj   = new WSC_LocalProjection( $center[0], $center[1] );

		$out_polys = [];
		foreach ( $polys as $rings ) {
			if ( empty( $rings[0] ) ) continue;
			$ring_m = array_map( fn( $pt ) => $proj->forward( $pt[0], $pt[1] ), $rings[0] );
			$buffered = self::buffer_ring_minkowski( $ring_m, $meters, self::ARC_SEGMENTS );
			$ring_ll  = array_map( fn( $pt ) => $proj->inverse( $pt[0], $pt[1] ), $buffered );
			$ring_ll  = WSC_Parser::ensure_closed( $ring_ll );
			$out_polys[] = [ $ring_ll ];
		}

		if ( empty( $out_polys ) ) return null;
		if ( count( $out_polys ) === 1 ) return [ 'type' => 'Polygon', 'coordinates' => $out_polys[0] ];
		return [ 'type' => 'MultiPolygon', 'coordinates' => $out_polys ];
	}

	/**
	 * Minkowski sum of a closed ring with a circle of given radius in meters.
	 * Produces convex hull around the swept polygon (good enough for short distances on building footprints).
	 */
	private static function buffer_ring_minkowski( array $ring_m, float $r, int $arc_segments ): array {
		$pts = [];
		$n   = count( $ring_m );
		if ( $n < 3 ) return $ring_m;
		// Around each vertex we add a circle of $arc_segments points; offset each edge by perpendicular ±$r.
		for ( $i = 0; $i < $n; $i++ ) {
			$p = $ring_m[ $i ];
			for ( $k = 0; $k < $arc_segments; $k++ ) {
				$ang = ( 2 * M_PI * $k ) / $arc_segments;
				$pts[] = [ $p[0] + $r * cos( $ang ), $p[1] + $r * sin( $ang ) ];
			}
		}
		// Convex hull (Andrew's monotone chain).
		return self::convex_hull( $pts );
	}

	private static function convex_hull( array $pts ): array {
		usort( $pts, function ( $a, $b ) {
			if ( $a[0] === $b[0] ) return $a[1] <=> $b[1];
			return $a[0] <=> $b[0];
		} );
		$n = count( $pts );
		if ( $n < 3 ) return $pts;
		$lower = [];
		foreach ( $pts as $p ) {
			while ( count( $lower ) >= 2 && self::cross( $lower[ count( $lower ) - 2 ], $lower[ count( $lower ) - 1 ], $p ) <= 0 ) array_pop( $lower );
			$lower[] = $p;
		}
		$upper = [];
		for ( $i = $n - 1; $i >= 0; $i-- ) {
			$p = $pts[ $i ];
			while ( count( $upper ) >= 2 && self::cross( $upper[ count( $upper ) - 2 ], $upper[ count( $upper ) - 1 ], $p ) <= 0 ) array_pop( $upper );
			$upper[] = $p;
		}
		array_pop( $lower ); array_pop( $upper );
		return array_merge( $lower, $upper );
	}

	private static function cross( array $o, array $a, array $b ): float {
		return ( $a[0] - $o[0] ) * ( $b[1] - $o[1] ) - ( $a[1] - $o[1] ) * ( $b[0] - $o[0] );
	}

	/* ───── helpers ───── */

	public static function geom_centroid( array $geo ): array {
		$bbox = WSC_Parser::bbox_of( $geo );
		return [ ( $bbox['w'] + $bbox['e'] ) / 2, ( $bbox['s'] + $bbox['n'] ) / 2 ];
	}

	private static function project_geom( array $geo, WSC_LocalProjection $proj, bool $forward ): array {
		$walk = function ( $arr ) use ( &$walk, $proj, $forward ) {
			if ( ! is_array( $arr ) ) return $arr;
			if ( isset( $arr[0] ) && is_numeric( $arr[0] ) ) {
				return $forward ? $proj->forward( $arr[0], $arr[1] ) : $proj->inverse( $arr[0], $arr[1] );
			}
			$out = [];
			foreach ( $arr as $v ) $out[] = $walk( $v );
			return $out;
		};
		$geo['coordinates'] = $walk( $geo['coordinates'] );
		return $geo;
	}

	private static function geojson_to_wkt( array $geo ): string {
		$type = $geo['type'] ?? '';
		$c    = $geo['coordinates'] ?? [];
		$fmt_pt   = fn( $p ) => $p[0] . ' ' . $p[1];
		$fmt_ring = fn( $r ) => '(' . implode( ', ', array_map( $fmt_pt, $r ) ) . ')';
		$fmt_poly = fn( $rings ) => '(' . implode( ', ', array_map( $fmt_ring, $rings ) ) . ')';

		if ( $type === 'Polygon' )      return 'POLYGON ' . $fmt_poly( $c );
		if ( $type === 'MultiPolygon' ) return 'MULTIPOLYGON (' . implode( ', ', array_map( $fmt_poly, $c ) ) . ')';
		return '';
	}

	private static function wkt_to_geojson( string $wkt ): ?array {
		// Minimal WKT parser for POLYGON / MULTIPOLYGON.
		$wkt = trim( $wkt );
		if ( stripos( $wkt, 'POLYGON' ) === 0 && stripos( $wkt, 'MULTIPOLYGON' ) !== 0 ) {
			$body = substr( $wkt, strpos( $wkt, '(' ) );
			$rings = self::parse_wkt_rings( $body );
			return [ 'type' => 'Polygon', 'coordinates' => $rings ];
		}
		if ( stripos( $wkt, 'MULTIPOLYGON' ) === 0 ) {
			$body = substr( $wkt, strpos( $wkt, '(' ) + 1, -1 );
			// Split by '))'
			$polys = [];
			$depth = 0; $buf = '';
			for ( $i = 0, $L = strlen( $body ); $i < $L; $i++ ) {
				$ch = $body[ $i ];
				if ( $ch === '(' ) { $depth++; $buf .= $ch; }
				elseif ( $ch === ')' ) { $depth--; $buf .= $ch; if ( $depth === 0 ) { $polys[] = self::parse_wkt_rings( $buf ); $buf = ''; } }
				else $buf .= $ch;
			}
			return [ 'type' => 'MultiPolygon', 'coordinates' => $polys ];
		}
		return null;
	}

	private static function parse_wkt_rings( string $body ): array {
		$body  = trim( $body );
		if ( $body[0] === '(' && substr( $body, -1 ) === ')' ) $body = substr( $body, 1, -1 );
		$rings = [];
		preg_match_all( '/\(([^()]+)\)/', $body, $m );
		foreach ( $m[1] as $ring ) {
			$pts = [];
			foreach ( explode( ',', $ring ) as $pair ) {
				$xy = preg_split( '/\s+/', trim( $pair ) );
				if ( count( $xy ) >= 2 ) $pts[] = [ (float) $xy[0], (float) $xy[1] ];
			}
			if ( count( $pts ) >= 3 ) $rings[] = $pts;
		}
		return $rings;
	}
}

/**
 * Local equirectangular projection (meters around lon0/lat0). Good for ≤ a few km.
 */
class WSC_LocalProjection {
	private float $lon0;
	private float $lat0;
	private float $mPerDegLat;
	private float $mPerDegLon;

	public function __construct( float $lon0, float $lat0 ) {
		$this->lon0 = $lon0;
		$this->lat0 = $lat0;
		// Approximate WGS84 meters per degree.
		$this->mPerDegLat = 110574.0;
		$this->mPerDegLon = 111320.0 * cos( deg2rad( $lat0 ) );
	}

	public function forward( float $lon, float $lat ): array {
		return [ ( $lon - $this->lon0 ) * $this->mPerDegLon, ( $lat - $this->lat0 ) * $this->mPerDegLat ];
	}

	public function inverse( float $x, float $y ): array {
		return [ $this->lon0 + ( $x / $this->mPerDegLon ), $this->lat0 + ( $y / $this->mPerDegLat ) ];
	}
}
