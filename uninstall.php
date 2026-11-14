<?php
/**
 * Uninstall: deletes the plugin's options, secrets and cached connection info.
 * Order meta is kept as an audit trail.
 *
 * @package Navexa\AgeCheck
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Deletes this site's navexa_agecheck_* options and transients.
 */
function navexa_agecheck_uninstall_site(): void {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall: one-off scan of this plugin's own option names.
	$names = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( 'navexa_agecheck_' ) . '%',
			$wpdb->esc_like( '_transient_navexa_agecheck_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_navexa_agecheck_' ) . '%'
		)
	);
	foreach ( $names as $name ) {
		delete_option( $name );
	}
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( '', array(), 'navexa-agecheck' );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $navexa_agecheck_site_id ) {
		switch_to_blog( $navexa_agecheck_site_id );
		navexa_agecheck_uninstall_site();
		restore_current_blog();
	}
} else {
	navexa_agecheck_uninstall_site();
}
