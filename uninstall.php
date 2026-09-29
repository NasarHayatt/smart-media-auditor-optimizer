<?php
/**
 * Recovery-first uninstall: never remove uploads, backups or recovery journals.
 *
 * @package SMAO
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Explicit opt-in removes only disposable plugin reports/settings, one blog at
// a time. Network data is preserved for a deliberate per-site maintenance pass.
if ( ! defined( 'SMAO_REMOVE_DATA' ) || true !== SMAO_REMOVE_DATA || is_multisite() ) {
	return;
}
global $wpdb;
$vault  = $wpdb->prefix . 'smao_vault';
$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $vault ) ) );
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers/predicates come from fixed internal maps; all request values use prepared placeholders.
if ( $exists && (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$vault`" ) > 0 ) {
	// Removing manifests would make recovery harder: retain everything.
	return;
}
wp_clear_scheduled_hook( 'smao_worker' );
wp_clear_scheduled_hook( 'smao_scheduled_scan' );
foreach ( array( 'media', 'tokens', 'evidence', 'vault', 'jobs', 'log', 'pages', 'render' ) as $name ) {
	$table = $wpdb->prefix . 'smao_' . $name;
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers/predicates come from fixed internal maps; all request values use prepared placeholders.
	$wpdb->query( "DROP TABLE IF EXISTS `$table`" );
}
foreach ( array( 'smao_schema', 'smao_scan', 'smao_settings', 'smao_epoch', 'smao_jobs_paused', 'smao_vault_path', 'smao_critical_pages', 'smao_styles_forgot', 'smao_critical_css', 'smao_warm_queue', 'smao_warm_last', 'smao_webp_map', 'smao_psi_last' ) as $name ) {
	delete_option( $name );
}
// Attachment metadata, original media and generated alternate files are retained.
