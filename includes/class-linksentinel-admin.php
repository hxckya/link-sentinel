<?php
/**
 * Admin screens: the links table, settings, dashboard widget.
 *
 * @package LinkSentinel
 */

defined( 'ABSPATH' ) || exit;

class LinkSentinel_Admin {

	const PAGE = 'link-sentinel';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'dashboard_widget' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( LINKSENTINEL_FILE ), array( __CLASS__, 'action_links' ) );
		LinkSentinel_Import_BLC::init();
	}

	public static function menu() {
		$counts = LinkSentinel_DB::counts();
		$badge  = $counts['broken'] ? ' <span class="awaiting-mod">' . (int) $counts['broken'] . '</span>' : '';
		add_menu_page( __( 'Link Sentinel', 'link-sentinel' ), __( 'Link Sentinel', 'link-sentinel' ) . $badge, 'manage_options', self::PAGE, array( __CLASS__, 'render_links' ), 'dashicons-admin-links', 81 );
		add_submenu_page( self::PAGE, __( 'Broken Links', 'link-sentinel' ), __( 'Broken Links', 'link-sentinel' ), 'manage_options', self::PAGE, array( __CLASS__, 'render_links' ) );
		add_submenu_page( self::PAGE, __( 'Link Sentinel Settings', 'link-sentinel' ), __( 'Settings', 'link-sentinel' ), 'manage_options', self::PAGE . '-settings', array( __CLASS__, 'render_settings' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Broken Links', 'link-sentinel' ) . '</a>' );
		return $links;
	}

	public static function assets( $hook ) {
		if ( false === strpos( $hook, self::PAGE ) ) {
			return;
		}
		wp_enqueue_style( 'link-sentinel-admin', LINKSENTINEL_URL . 'assets/admin.css', array(), LINKSENTINEL_VERSION );
		wp_enqueue_script( 'link-sentinel-admin', LINKSENTINEL_URL . 'assets/admin.js', array( 'wp-api-fetch', 'wp-i18n' ), LINKSENTINEL_VERSION, true );
		wp_set_script_translations( 'link-sentinel-admin', 'link-sentinel' );
		wp_localize_script(
			'link-sentinel-admin',
			'LinkSentinel',
			array(
				'running' => LinkSentinel_Scanner::is_running(),
				'i18n'    => array(
					'newUrl'     => __( 'New URL for this link (applies everywhere it appears):', 'link-sentinel' ),
					'unlink'     => __( 'Remove this link everywhere and keep the text?', 'link-sentinel' ),
					'failed'     => __( 'That did not work:', 'link-sentinel' ),
					'stopping'   => __( 'Stopping…', 'link-sentinel' ),
					'done'       => __( 'Scan finished. Reloading…', 'link-sentinel' ),
					'cancel'     => __( 'Cancel', 'link-sentinel' ),
					'save'       => __( 'Save', 'link-sentinel' ),
					'redirectTo' => __( 'Send visitors of this dead URL to:', 'link-sentinel' ),
				),
			)
		);
	}

	// ----- Links page --------------------------------------------------------

	public static function render_links() {
		require_once LINKSENTINEL_DIR . 'includes/class-linksentinel-list-table.php';
		$table = new LinkSentinel_List_Table();
		$table->process_bulk_action();
		$table->prepare_items();
		$state  = LinkSentinel_Scanner::state();
		$counts = LinkSentinel_DB::counts();
		?>
		<div class="wrap lsn-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Link Sentinel', 'link-sentinel' ); ?></h1>
			<hr class="wp-header-end">

			<div class="lsn-panel" id="lsn-panel" data-running="<?php echo LinkSentinel_Scanner::is_running() ? '1' : '0'; ?>">
				<div class="lsn-panel-row">
					<div class="lsn-stats">
						<span class="lsn-stat lsn-broken"><strong><?php echo (int) $counts['broken']; ?></strong> <?php esc_html_e( 'broken', 'link-sentinel' ); ?></span>
						<span class="lsn-stat lsn-redirect"><strong><?php echo (int) $counts['redirect']; ?></strong> <?php esc_html_e( 'redirecting', 'link-sentinel' ); ?></span>
						<span class="lsn-stat lsn-blocked"><strong><?php echo (int) $counts['blocked']; ?></strong> <?php esc_html_e( 'blocked', 'link-sentinel' ); ?></span>
						<span class="lsn-stat lsn-error"><strong><?php echo (int) $counts['error']; ?></strong> <?php esc_html_e( 'unreachable', 'link-sentinel' ); ?></span>
						<span class="lsn-stat lsn-ok"><strong><?php echo (int) $counts['ok']; ?></strong> <?php esc_html_e( 'fine', 'link-sentinel' ); ?></span>
					</div>
					<div class="lsn-actions">
						<button type="button" class="button button-primary" id="lsn-start"><?php esc_html_e( 'Scan now', 'link-sentinel' ); ?></button>
						<button type="button" class="button" id="lsn-start-full" title="<?php esc_attr_e( 'Re-fetch every link, even ones checked recently', 'link-sentinel' ); ?>"><?php esc_html_e( 'Full re-check', 'link-sentinel' ); ?></button>
						<button type="button" class="button" id="lsn-stop"><?php esc_html_e( 'Stop', 'link-sentinel' ); ?></button>
						<?php do_action( 'linksentinel_links_toolbar', $table->view ); ?>
					</div>
				</div>
				<div class="lsn-progress"><div class="lsn-progress-bar" id="lsn-progress-bar" style="width:0"></div></div>
				<p class="lsn-message" id="lsn-message"><?php echo esc_html( self::describe_state( $state ) ); ?></p>
			</div>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
				<input type="hidden" name="view" value="<?php echo esc_attr( $table->view ); ?>">
				<?php $table->views(); ?>
				<?php $table->search_box( __( 'Search URLs', 'link-sentinel' ), 'lsn' ); ?>
			</form>
			<form method="post">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
				<input type="hidden" name="view" value="<?php echo esc_attr( $table->view ); ?>">
				<?php wp_nonce_field( 'bulk-links' ); ?>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	public static function describe_state( $state ) {
		if ( 'done' === $state['phase'] && $state['finished'] ) {
			/* translators: 1: human time diff, 2: number of items, 3: number of links */
			$text = sprintf( __( 'Last scan finished %1$s ago: %2$d items read, %3$d links.', 'link-sentinel' ), human_time_diff( (int) $state['finished'] ), (int) $state['sources'], (int) $state['found'] );
			if ( 0 === (int) $state['to_check'] && (int) $state['found'] > 0 ) {
				/* translators: %d: hours */
				$text .= ' ' . sprintf( __( 'Every link had been checked within the last %d hours, so none was fetched again — use “Full re-check” to fetch them all.', 'link-sentinel' ), (int) LinkSentinel_Settings::get( 'recheck_hours' ) );
			}
			return $text;
		}
		if ( in_array( $state['phase'], array( 'collect', 'check' ), true ) ) {
			return __( 'Scan in progress…', 'link-sentinel' );
		}
		if ( 'idle' === $state['phase'] && $state['finished'] ) {
			/* translators: %s: human time diff */
			return sprintf( __( 'Last scan was stopped %s ago. Results above are from what had been checked by then.', 'link-sentinel' ), human_time_diff( (int) $state['finished'] ) );
		}
		return __( 'No scan has run yet. Click “Scan now” — you can leave this page; the scan continues in the background.', 'link-sentinel' );
	}

	// ----- Settings ----------------------------------------------------------

	public static function register_settings() {
		register_setting( 'linksentinel', LinkSentinel_Settings::OPTION, array( 'sanitize_callback' => array( 'LinkSentinel_Settings', 'sanitize' ) ) );
	}

	public static function render_settings() {
		$s     = LinkSentinel_Settings::all();
		$types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $types['attachment'] );
		?>
		<div class="wrap lsn-wrap">
			<h1><?php esc_html_e( 'Link Sentinel Settings', 'link-sentinel' ); ?></h1>
			<?php LinkSentinel_Import_BLC::render(); ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'linksentinel' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Content to scan', 'link-sentinel' ); ?></th>
						<td>
							<fieldset>
								<?php foreach ( $types as $t ) : ?>
									<label><input type="checkbox" name="<?php echo esc_attr( LinkSentinel_Settings::OPTION ); ?>[post_types][]" value="<?php echo esc_attr( $t->name ); ?>" <?php checked( in_array( $t->name, (array) $s['post_types'], true ) ); ?>> <?php echo esc_html( $t->labels->name ); ?></label><br>
								<?php endforeach; ?>
								<label><input type="checkbox" name="<?php echo esc_attr( LinkSentinel_Settings::OPTION ); ?>[include_drafts]" value="1" <?php checked( count( (array) $s['post_statuses'] ) > 1 ); ?>> <?php esc_html_e( 'Include drafts, pending, scheduled and private items', 'link-sentinel' ); ?></label><br>
								<label><input type="checkbox" name="<?php echo esc_attr( LinkSentinel_Settings::OPTION ); ?>[scan_menus]" value="1" <?php checked( ! empty( $s['scan_menus'] ) ); ?>> <?php esc_html_e( 'Custom links in navigation menus', 'link-sentinel' ); ?></label><br>
								<label><input type="checkbox" name="<?php echo esc_attr( LinkSentinel_Settings::OPTION ); ?>[scan_widgets]" value="1" <?php checked( ! empty( $s['scan_widgets'] ) ); ?>> <?php esc_html_e( 'Block widgets (sidebars, footers)', 'link-sentinel' ); ?></label><br>
								<label><input type="checkbox" name="<?php echo esc_attr( LinkSentinel_Settings::OPTION ); ?>[scan_terms]" value="1" <?php checked( ! empty( $s['scan_terms'] ) ); ?>> <?php esc_html_e( 'Category, tag and taxonomy descriptions', 'link-sentinel' ); ?></label><br>
								<label><input type="checkbox" name="<?php echo esc_attr( LinkSentinel_Settings::OPTION ); ?>[scan_comments]" value="1" <?php checked( ! empty( $s['scan_comments'] ) ); ?>> <?php esc_html_e( 'Approved comments', 'link-sentinel' ); ?></label>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lsn-schedule"><?php esc_html_e( 'Automatic scan', 'link-sentinel' ); ?></label></th>
						<td>
							<select id="lsn-schedule" name="<?php echo esc_attr( LinkSentinel_Settings::OPTION ); ?>[schedule]">
								<?php foreach ( LinkSentinel_Settings::schedules() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $s['schedule'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Runs through WP-Cron, in small steps, while your site receives visits.', 'link-sentinel' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Email report', 'link-sentinel' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( LinkSentinel_Settings::OPTION ); ?>[notify_email]" value="1" <?php checked( ! empty( $s['notify_email'] ) ); ?>> <?php esc_html_e( 'Email me when a scheduled scan finds broken links', 'link-sentinel' ); ?></label>
							<p><input type="email" name="<?php echo esc_attr( LinkSentinel_Settings::OPTION ); ?>[notify_to]" value="<?php echo esc_attr( $s['notify_to'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"></p>
							<p class="description"><?php esc_html_e( 'Leave empty to use the site admin email. Manual scans never send email; one message per scheduled scan, and only when something is broken.', 'link-sentinel' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lsn-recheck"><?php esc_html_e( 'Re-check links after', 'link-sentinel' ); ?></label></th>
						<td><input type="number" id="lsn-recheck" min="1" max="720" name="<?php echo esc_attr( LinkSentinel_Settings::OPTION ); ?>[recheck_hours]" value="<?php echo (int) $s['recheck_hours']; ?>" class="small-text"> <?php esc_html_e( 'hours', 'link-sentinel' ); ?>
							<p class="description"><?php esc_html_e( 'A link checked more recently than this is not fetched again during a normal scan. “Full re-check” ignores it.', 'link-sentinel' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="lsn-timeout"><?php esc_html_e( 'Timeout', 'link-sentinel' ); ?></label></th>
						<td><input type="number" id="lsn-timeout" min="3" max="60" name="<?php echo esc_attr( LinkSentinel_Settings::OPTION ); ?>[timeout]" value="<?php echo (int) $s['timeout']; ?>" class="small-text"> <?php esc_html_e( 'seconds per request', 'link-sentinel' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="lsn-concurrency"><?php esc_html_e( 'Parallel requests', 'link-sentinel' ); ?></label></th>
						<td><input type="number" id="lsn-concurrency" min="1" max="20" name="<?php echo esc_attr( LinkSentinel_Settings::OPTION ); ?>[concurrency]" value="<?php echo (int) $s['concurrency']; ?>" class="small-text">
							<p class="description"><?php esc_html_e( 'Lower this on very small hosting plans.', 'link-sentinel' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="lsn-exclude"><?php esc_html_e( 'Never check', 'link-sentinel' ); ?></label></th>
						<td><textarea id="lsn-exclude" name="<?php echo esc_attr( LinkSentinel_Settings::OPTION ); ?>[exclusions]" rows="4" class="large-text code" placeholder="example.com&#10;https://example.org/private/"><?php echo esc_textarea( $s['exclusions'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One domain or URL prefix per line. Subdomains of a listed domain are excluded too.', 'link-sentinel' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Bot-blocked sites', 'link-sentinel' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( LinkSentinel_Settings::OPTION ); ?>[blocked_is_broken]" value="1" <?php checked( ! empty( $s['blocked_is_broken'] ) ); ?>> <?php esc_html_e( 'Count 401/403/429 responses as broken', 'link-sentinel' ); ?></label>
							<p class="description"><?php esc_html_e( 'Off by default: LinkedIn, Cloudflare-protected sites and many shops refuse automated requests while working fine for visitors. They are listed under “Blocked” instead.', 'link-sentinel' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="lsn-ua"><?php esc_html_e( 'User agent', 'link-sentinel' ); ?></label></th>
						<td><input type="text" id="lsn-ua" name="<?php echo esc_attr( LinkSentinel_Settings::OPTION ); ?>[user_agent]" value="<?php echo esc_attr( $s['user_agent'] ); ?>" class="large-text code"></td>
					</tr>
					<?php do_action( 'linksentinel_settings_rows', $s ); ?>
				</table>
				<?php submit_button(); ?>
			</form>
			<?php self::render_pro_box(); ?>
		</div>
		<?php
	}

	/** What Pro adds, shown to free installs only. */
	public static function render_pro_box() {
		if ( LinkSentinel_License::can_use_pro() ) {
			return;
		}
		?>
		<div class="lsn-pro-box">
			<h2><?php esc_html_e( 'Link Sentinel Pro', 'link-sentinel' ); ?></h2>
			<p><?php esc_html_e( 'The free plugin is complete. Pro adds what agencies and larger sites asked for:', 'link-sentinel' ); ?></p>
			<ul>
				<?php foreach ( LinkSentinel_License::features() as $f ) : ?>
					<li><?php echo esc_html( $f ); ?></li>
				<?php endforeach; ?>
			</ul>
			<a class="button button-primary" href="<?php echo esc_url( LinkSentinel_License::upgrade_url() ); ?>"><?php esc_html_e( 'See Pro', 'link-sentinel' ); ?></a>
		</div>
		<?php
	}

	// ----- Dashboard widget --------------------------------------------------

	public static function dashboard_widget() {
		if ( current_user_can( 'manage_options' ) ) {
			wp_add_dashboard_widget( 'linksentinel_widget', __( 'Link Sentinel', 'link-sentinel' ), array( __CLASS__, 'render_widget' ) );
		}
	}

	public static function render_widget() {
		$c     = LinkSentinel_DB::counts();
		$state = LinkSentinel_Scanner::state();
		echo '<p class="lsn-widget-counts">';
		printf( '<a href="%s"><strong>%d</strong> %s</a> · ', esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&view=broken' ) ), (int) $c['broken'], esc_html__( 'broken', 'link-sentinel' ) );
		printf( '<a href="%s"><strong>%d</strong> %s</a> · ', esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&view=redirect' ) ), (int) $c['redirect'], esc_html__( 'redirecting', 'link-sentinel' ) );
		printf( '<a href="%s"><strong>%d</strong> %s</a>', esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&view=blocked' ) ), (int) $c['blocked'], esc_html__( 'blocked', 'link-sentinel' ) );
		echo '</p><p class="description">' . esc_html( self::describe_state( $state ) ) . '</p>';
	}
}
