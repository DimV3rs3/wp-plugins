<?php
/**
 * Installer: dbDelta для кастомных таблиц + миграции версий.
 *
 * @package WorldStatCourtyard
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSC_Installer {

	const OPT_DB_VERSION = 'wsc_db_version';

	public static function activate(): void {
		self::install_tables();
		WSC_CPT::register();
		flush_rewrite_rules();
		WSC_Settings::uploads_dir();
		update_option( self::OPT_DB_VERSION, WSC_DB_VERSION );
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	private static bool $upgraded = false;

	public static function maybe_upgrade(): void {
		if ( self::$upgraded ) return;
		self::$upgraded = true;
		$cur = (string) get_option( self::OPT_DB_VERSION, '0.0.0' );
		// version_compare вместо !==: даунгрейд (1.2 → 1.1) теперь не триггерит повторную миграцию,
		// а апгрейд через несколько версий обрабатывается корректно.
		if ( version_compare( $cur, WSC_DB_VERSION, '<' ) ) {
			self::install_tables();
			self::backfill_after_install( $cur );
			update_option( self::OPT_DB_VERSION, WSC_DB_VERSION );
		}
	}

	/**
	 * Одноразовые миграции данных после применения новых таблиц через dbDelta.
	 */
	private static function backfill_after_install( string $previous_version ): void {
		global $wpdb;
		$tb = self::table_buildings();
		$pm = $wpdb->postmeta;
		$p  = $wpdb->posts;

		// Заполняем wsc_buildings.ergo_post_id из postmeta для старых записей.
		// На больших инсталляциях это один UPDATE с JOIN — на порядки быстрее, чем итеративный backfill.
		$wpdb->query(
			"UPDATE {$tb} b
			 INNER JOIN {$pm} pm ON pm.meta_key = '_wsc_building_id' AND pm.meta_value = b.id
			 INNER JOIN {$p}  p  ON p.ID = pm.post_id AND p.post_type = 'wsp_building' AND p.post_status = 'publish'
			 SET b.ergo_post_id = pm.post_id
			 WHERE b.ergo_post_id = 0"
		);
	}

	public static function table_buildings(): string { global $wpdb; return $wpdb->prefix . 'wsc_buildings'; }
	public static function table_yards():     string { global $wpdb; return $wpdb->prefix . 'wsc_yards'; }
	public static function table_pois():      string { global $wpdb; return $wpdb->prefix . 'wsc_pois'; }
	public static function table_landuse():   string { global $wpdb; return $wpdb->prefix . 'wsc_landuse'; }
	public static function table_jobs():      string { global $wpdb; return $wpdb->prefix . 'wsc_jobs'; }

	public static function install_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$tb = self::table_buildings();
		$ty = self::table_yards();
		$tp = self::table_pois();
		$tl = self::table_landuse();
		$tj = self::table_jobs();

		$sql = "CREATE TABLE {$tb} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			osm_type VARCHAR(8) NOT NULL DEFAULT 'way',
			osm_id BIGINT NOT NULL,
			city_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			category VARCHAR(32) NOT NULL DEFAULT 'other',
			name VARCHAR(255) NOT NULL DEFAULT '',
			address VARCHAR(255) NOT NULL DEFAULT '',
			height_m FLOAT NOT NULL DEFAULT 0,
			levels INT NOT NULL DEFAULT 0,
			footprint_geojson LONGTEXT NULL,
			centroid_lat DOUBLE NOT NULL DEFAULT 0,
			centroid_lng DOUBLE NOT NULL DEFAULT 0,
			bbox_west DOUBLE NOT NULL DEFAULT 0,
			bbox_south DOUBLE NOT NULL DEFAULT 0,
			bbox_east DOUBLE NOT NULL DEFAULT 0,
			bbox_north DOUBLE NOT NULL DEFAULT 0,
			tags_json LONGTEXT NULL,
			ergo_post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			ergo_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
			scanned_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY (id),
			UNIQUE KEY osm_unique (osm_type, osm_id),
			KEY city (city_id),
			KEY cat (category),
			KEY bbox (centroid_lat, centroid_lng),
			KEY idx_city_centroid (city_id, centroid_lat, centroid_lng),
			KEY idx_ergo_post (ergo_post_id),
			KEY idx_city_ergo (city_id, ergo_score)
		) {$charset};";
		dbDelta( $sql );

		$sql = "CREATE TABLE {$ty} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			building_id BIGINT UNSIGNED NOT NULL,
			buffer_m FLOAT NOT NULL DEFAULT 35,
			geojson LONGTEXT NULL,
			area_m2 DOUBLE NOT NULL DEFAULT 0,
			post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			updated_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY (id),
			UNIQUE KEY building_unique (building_id),
			KEY post (post_id)
		) {$charset};";
		dbDelta( $sql );

		$sql = "CREATE TABLE {$tp} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			osm_type VARCHAR(8) NOT NULL DEFAULT 'node',
			osm_id BIGINT NOT NULL,
			city_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			category VARCHAR(32) NOT NULL DEFAULT 'other',
			name VARCHAR(255) NOT NULL DEFAULT '',
			geom_type VARCHAR(16) NOT NULL DEFAULT 'Point',
			geojson LONGTEXT NULL,
			lat DOUBLE NOT NULL DEFAULT 0,
			lng DOUBLE NOT NULL DEFAULT 0,
			tags_json LONGTEXT NULL,
			scanned_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY (id),
			UNIQUE KEY osm_unique (osm_type, osm_id),
			KEY city (city_id),
			KEY cat (category),
			KEY geo (lat, lng),
			KEY idx_city_geo (city_id, lat, lng)
		) {$charset};";
		dbDelta( $sql );

		$sql = "CREATE TABLE {$tl} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			osm_type VARCHAR(8) NOT NULL DEFAULT 'way',
			osm_id BIGINT NOT NULL,
			city_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			kind VARCHAR(32) NOT NULL DEFAULT 'park',
			name VARCHAR(255) NOT NULL DEFAULT '',
			geojson LONGTEXT NULL,
			tags_json LONGTEXT NULL,
			bbox_west DOUBLE NOT NULL DEFAULT 0,
			bbox_south DOUBLE NOT NULL DEFAULT 0,
			bbox_east DOUBLE NOT NULL DEFAULT 0,
			bbox_north DOUBLE NOT NULL DEFAULT 0,
			scanned_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY (id),
			UNIQUE KEY osm_unique (osm_type, osm_id),
			KEY city (city_id),
			KEY kind (kind),
			KEY idx_city_kind (city_id, kind),
			KEY idx_city_bbox (city_id, bbox_west, bbox_east)
		) {$charset};";
		dbDelta( $sql );

		$sql = "CREATE TABLE {$tj} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			city_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(16) NOT NULL DEFAULT 'pending',
			source VARCHAR(16) NOT NULL DEFAULT 'overpass',
			total INT NOT NULL DEFAULT 0,
			done INT NOT NULL DEFAULT 0,
			imported INT NOT NULL DEFAULT 0,
			payload LONGTEXT NULL,
			log LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY (id),
			KEY city (city_id),
			KEY status (status),
			KEY idx_city_status (city_id, status),
			KEY idx_status_updated (status, updated_at)
		) {$charset};";
		dbDelta( $sql );
	}
}
