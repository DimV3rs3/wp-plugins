<?php
/**
 * Admin menu for Zones (adds to WorldStat menu)
 *
 * @package WorldStatZone
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Добавляем пункты меню в World Statistics
add_action( 'worldstat_admin_menu', 'wsz_add_admin_menu' );

function wsz_add_admin_menu() {
    // Добавляем подменю "Зоны" (ссылка на список постов)
    add_submenu_page(
        'worldstat',
        'Зоны',
        '🏠 Зоны',
        'manage_options',
        'edit.php?post_type=wsz_zone',
        null
    );
    
    // Добавляем подменю "CSV Import"
    add_submenu_page(
        'worldstat',
        'CSV Import',
        '📁 CSV Import',
        'manage_options',
        'worldstat-csv',
        [ 'WSZ_Admin', 'render_page' ]
    );
}