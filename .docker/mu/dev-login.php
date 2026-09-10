<?php
/**
 * Dev-only: log the admin user in when the request carries ?lsn_dev_login=1,
 * so headless screenshots of wp-admin need no cookie dance. Never shipped —
 * this file lives in .docker/, which is excluded from the distributed ZIP.
 */
add_action( 'init', function () {
	if ( isset( $_GET['lsn_dev_login'] ) && ! is_user_logged_in() ) {
		$user = get_user_by( 'login', 'admin' );
		if ( $user ) {
			wp_set_current_user( $user->ID );
			wp_set_auth_cookie( $user->ID, true );
			wp_safe_redirect( remove_query_arg( 'lsn_dev_login' ) );
			exit;
		}
	}
} );
