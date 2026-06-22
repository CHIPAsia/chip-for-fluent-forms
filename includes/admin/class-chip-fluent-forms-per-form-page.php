<?php
/**
 * Per-form CHIP settings admin page.
 *
 * Gives merchants a dedicated UI to override the global CHIP
 * credentials (Brand ID / Secret Key / Payment Mode / Due Strict /
 * Whitelist) on a per-form basis — the same identity the plugin
 * had in 1.x under codestar, now built on the native WP Settings
 * API / admin pages (no codestar).
 *
 * The page registers as a submenu under `fluent_forms` (the FF
 * parent admin menu), gated on `fluentform_dashboard_access` so
 * it inherits the parent menu's visibility — admins who can see
 * FF Forms can also see CHIP Per-Form. (Earlier revisions tried
 * a top-level menu; reverted to match the 1.x codestar UX where
 * the page lived under the FF sidebar.)
 *
 * URL:
 *   - List view:  wp-admin/admin.php?page=chip-form-settings
 *   - Per-form:   wp-admin/admin.php?page=chip-form-settings&form_id=N
 *   - Save POST:  wp-admin/admin-post.php?action=chip_save_per_form_settings
 *
 * Persists via Chip_Fluent_Forms_Settings::save_form() so the
 * runtime read path (Chip_Fluent_Forms_Settings::for_form()) picks
 * up the values without any further changes.
 *
 * @package CHIPForFluentForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chip_Fluent_Forms_Per_Form_Page — see file-level docblock above.
 */
class Chip_Fluent_Forms_Per_Form_Page {

	const MENU_SLUG      = 'chip-form-settings';
	const SAVE_ACTION    = 'chip_save_per_form_settings';
	const NONCE_ACTION   = 'chip_save_per_form_settings';
	const SETTINGS_GROUP = 'chip_per_form_settings_group';

	/**
	 * Capability the page is registered with.
	 *
	 * Cached at first access in resolve_page_cap() so render() and
	 * handle_save() use the same cap that add_submenu_page() used.
	 *
	 * @var string|null
	 */
	private $page_cap;

	/**
	 * Resolve the capability this page uses for menu registration and
	 * in-page access checks.
	 *
	 * We support two roles:
	 *   - Stock WP administrators: they have `manage_options` (and all
	 *     other caps). We use that — it's a stock WP cap, well-known
	 *     to WordPress core.
	 *   - Custom FF roles (e.g. "Forms Manager") set up via FF's
	 *     permission UI: they have `fluentform_dashboard_access`
	 *     directly but NOT `manage_options`. We fall back to that
	 *     FF cap so they can still reach the page.
	 *
	 * The fallback chain is computed in `register()` and stored as a
	 * class property, then re-used in `render()` and `handle_save()`
	 * so the in-page `wp_die()` matches the cap the menu was
	 * registered with.
	 *
	 * @return string Capability name.
	 */
	private function resolve_page_cap() {
		if ( null !== $this->page_cap ) {
			return $this->page_cap;
		}
		$this->page_cap = current_user_can( 'manage_options' )
			? 'manage_options'
			: 'fluentform_dashboard_access';
		return $this->page_cap;
	}

	/**
	 * Register the submenu + save handler.
	 *
	 * Hooked from Chip_Fluent_Forms::includes() under the is_admin()
	 * branch so this code never runs on public requests.
	 *
	 * @return void
	 */
	public function register() {
		// Submenu under Fluent Forms so the page lives alongside the
		// other FF admin items (same UX as 1.x codestar). The cap is
		// resolved at registration time from the fallback chain in
		// resolve_page_cap() — see that method for why this matters.
		add_submenu_page(
			'fluent_forms',
			__( 'CHIP Per-Form Settings', 'chip-for-fluent-forms' ),
			__( 'CHIP Per-Form', 'chip-for-fluent-forms' ),
			$this->resolve_page_cap(),
			self::MENU_SLUG,
			array( $this, 'render' )
		);

		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );

		// Backward-compat redirect. The page used to be a top-level
		// menu (commit 0093ced) — old bookmarks, browser history, and
		// stale LS cache entries still resolve /wp-admin/chip-form-settings
		// to a 404. Forward them to the new submenu URL.
		add_action( 'admin_init', array( $this, 'maybe_redirect_legacy_url' ) );
	}

	/**
	 * Redirect requests to the legacy top-level URL to the submenu URL.
	 *
	 * In commit 0093ced the page was a top-level menu, so the URL was
	 * /wp-admin/chip-form-settings. In subsequent commits it moved
	 * under Fluent Forms as a submenu, so the URL became
	 * /wp-admin/admin.php?page=chip-form-settings. This redirect
	 * handles any leftover references to the old URL (bookmarks,
	 * browser history, LiteSpeed cache, or anything else).
	 *
	 * @return void
	 */
	public function maybe_redirect_legacy_url() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compare against a known constant; only path/query used.
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		if ( '' === $request_uri ) {
			return;
		}

		// Match the exact legacy URL (/wp-admin/chip-form-settings) and
		// any subpaths under it (/wp-admin/chip-form-settings/...). The
		// new submenu URL has ?page= in it and never matches this.
		// Use '~' as the delimiter so the '#' inside the pattern is
		// not misinterpreted as the closing delimiter.
		$pattern = '~/wp-admin/' . preg_quote( self::MENU_SLUG, '~' ) . '(?:[/?#]|$)~';
		if ( ! preg_match( $pattern, $request_uri ) ) {
			return;
		}

		$target = add_query_arg( 'page', self::MENU_SLUG, admin_url( 'admin.php' ) );
		wp_safe_redirect( $target, 301 );
		exit;
	}

	/**
	 * Dispatch the request to the list view or per-form view.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( $this->resolve_page_cap() ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'chip-for-fluent-forms' ) );
		}

		$form_id = isset( $_GET['form_id'] ) ? (int) $_GET['form_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display.
		if ( $form_id > 0 ) {
			$this->render_form( $form_id );
			return;
		}

		$this->render_list();
	}

	/**
	 * List view: every FF form, with a link to its per-form page.
	 *
	 * @return void
	 */
	private function render_list() {
		$result = $this->get_forms();
		$forms  = $result['forms'];

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'CHIP Per-Form Settings', 'chip-for-fluent-forms' ); ?></h1>
			<p>
				<?php
				echo esc_html__( 'Override the global CHIP credentials (Brand ID / Secret Key / Payment Mode / Due Strict / Whitelist) on a per-form basis. When the per-form toggle is on, the form uses its own values; otherwise it falls back to the global settings.', 'chip-for-fluent-forms' );
				?>
			</p>
			<?php if ( empty( $forms ) ) : ?>
				<p><?php esc_html_e( 'No Fluent Forms forms found. Create a form first.', 'chip-for-fluent-forms' ); ?></p>
				<?php if ( current_user_can( 'manage_options' ) ) : ?>
					<div class="notice notice-warning inline" style="max-width: 880px;">
						<p><strong><?php esc_html_e( 'Diagnostic (visible to admins only):', 'chip-for-fluent-forms' ); ?></strong></p>
						<ul style="list-style:disc;margin-left:1.5em;">
							<li><?php esc_html_e( 'wpFluent() available:', 'chip-for-fluent-forms' ); ?> <code><?php echo $result['wpf'] ? 'yes' : 'no'; ?></code></li>
							<li><?php esc_html_e( 'Queried table:', 'chip-for-fluent-forms' ); ?> <code><?php echo esc_html( $result['table'] ); ?></code> (<?php esc_html_e( 'with WP table prefix prepended at runtime', 'chip-for-fluent-forms' ); ?>)</li>
							<li><?php esc_html_e( 'Rows returned:', 'chip-for-fluent-forms' ); ?> <code><?php echo (int) $result['count']; ?></code></li>
							<?php if ( ! empty( $result['table_status'] ) ) : ?>
								<li><?php esc_html_e( 'Table existence check:', 'chip-for-fluent-forms' ); ?> <code><?php echo esc_html( $result['table_status'] ); ?></code></li>
							<?php endif; ?>
							<?php if ( ! empty( $result['error'] ) ) : ?>
								<li><?php esc_html_e( 'Error:', 'chip-for-fluent-forms' ); ?> <code><?php echo esc_html( $result['error'] ); ?></code></li>
							<?php endif; ?>
						</ul>
						<p class="description"><?php esc_html_e( 'Check wp-content/debug.log for a [chip-for-fluent-forms] line with the same info.', 'chip-for-fluent-forms' ); ?></p>
					</div>
				<?php endif; ?>
			<?php else : ?>
				<table class="widefat striped" style="max-width: 880px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Form', 'chip-for-fluent-forms' ); ?></th>
							<th><?php esc_html_e( 'Customized', 'chip-for-fluent-forms' ); ?></th>
							<th><?php esc_html_e( 'Action', 'chip-for-fluent-forms' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $forms as $form ) : ?>
							<?php
							$form_id       = (int) $form->id;
							$is_customized = $this->is_form_customized( $form_id );
							$edit_url      = admin_url( 'admin.php?page=' . self::MENU_SLUG . '&form_id=' . $form_id );
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( $form->title ); ?></strong>
									<br><span style="color:#646970;">#<?php echo (int) $form_id; ?></span>
								</td>
								<td>
									<?php if ( $is_customized ) : ?>
										<span style="color:#1a7f37;"><?php esc_html_e( 'Yes', 'chip-for-fluent-forms' ); ?></span>
									<?php else : ?>
										<span style="color:#646970;"><?php esc_html_e( 'No', 'chip-for-fluent-forms' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<a class="button" href="<?php echo esc_url( $edit_url ); ?>">
										<?php esc_html_e( 'Edit', 'chip-for-fluent-forms' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Per-form view: a WP Settings API form for one form's settings.
	 *
	 * @param int $form_id Fluent Forms form id.
	 * @return void
	 */
	private function render_form( $form_id ) {
		$form_id = (int) $form_id;
		$form    = $this->get_form( $form_id );

		if ( ! $form ) {
			wp_die( esc_html__( 'Form not found.', 'chip-for-fluent-forms' ) );
		}

		$settings   = Chip_Fluent_Forms_Settings::for_form( $form_id );
		$is_active  = 'yes' === $settings['is_active'];
		$mode       = $settings['payment_mode'];
		$brand_id   = $settings['brand_id'];
		$secret_key = $settings['secret_key'];
		$due_strict = '1' === $settings['due_strict'];
		$timing     = (int) $settings['due_strict_timing'];
		$whitelist  = is_array( $settings['payment_method_whitelist'] ) ? $settings['payment_method_whitelist'] : array();

		$methods = Chip_Fluent_Forms_Settings::payment_methods();

		$list_url   = admin_url( 'admin.php?page=' . self::MENU_SLUG );
		$action_url = admin_url( 'admin-post.php?action=' . self::SAVE_ACTION );
		$saved_flag = ! empty( $_GET['saved'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display.
		?>
		<div class="wrap">
			<h1>
				<?php
				printf(
					/* translators: %s: form title */
					esc_html__( 'CHIP Per-Form Settings &mdash; %s', 'chip-for-fluent-forms' ),
					esc_html( $form->title )
				);
				?>
			</h1>
			<p>
				<a href="<?php echo esc_url( $list_url ); ?>">&larr; <?php esc_html_e( 'Back to all forms', 'chip-for-fluent-forms' ); ?></a>
			</p>

			<?php if ( $saved_flag ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Settings saved.', 'chip-for-fluent-forms' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( $action_url ); ?>" style="max-width: 880px;">
				<input type="hidden" name="form_id" value="<?php echo (int) $form_id; ?>" />
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Customize for this form', 'chip-for-fluent-forms' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="is_active" value="yes" <?php checked( $is_active, true ); ?> />
									<?php esc_html_e( 'Enable per-form override. When off, the global CHIP settings are used.', 'chip-for-fluent-forms' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="chip-pf-payment-mode"><?php esc_html_e( 'Payment Mode', 'chip-for-fluent-forms' ); ?></label></th>
							<td>
								<select id="chip-pf-payment-mode" name="payment_mode">
									<option value="test" <?php selected( $mode, 'test' ); ?>><?php esc_html_e( 'Test', 'chip-for-fluent-forms' ); ?></option>
									<option value="live" <?php selected( $mode, 'live' ); ?>><?php esc_html_e( 'Live', 'chip-for-fluent-forms' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="chip-pf-brand-id"><?php esc_html_e( 'Brand ID', 'chip-for-fluent-forms' ); ?></label></th>
							<td>
								<input type="text" class="regular-text" id="chip-pf-brand-id" name="brand_id" value="<?php echo esc_attr( $brand_id ); ?>" autocomplete="off" />
								<p class="description"><?php esc_html_e( 'Leave empty to use the global Brand ID when the customize toggle is off.', 'chip-for-fluent-forms' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="chip-pf-secret-key"><?php esc_html_e( 'Secret Key', 'chip-for-fluent-forms' ); ?></label></th>
							<td>
								<input type="password" class="regular-text" id="chip-pf-secret-key" name="secret_key" value="<?php echo esc_attr( $secret_key ); ?>" autocomplete="new-password" />
								<p class="description"><?php esc_html_e( 'Leave empty to use the global Secret Key when the customize toggle is off.', 'chip-for-fluent-forms' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Due Strict', 'chip-for-fluent-forms' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="due_strict" value="1" <?php checked( $due_strict, true ); ?> />
									<?php esc_html_e( 'Enable strict-due timing for this form.', 'chip-for-fluent-forms' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="chip-pf-timing"><?php esc_html_e( 'Due Strict Timing (minutes)', 'chip-for-fluent-forms' ); ?></label></th>
							<td>
								<input type="number" min="1" id="chip-pf-timing" name="due_strict_timing" value="<?php echo (int) $timing; ?>" />
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Payment Method Whitelist', 'chip-for-fluent-forms' ); ?></th>
							<td>
								<fieldset>
									<?php foreach ( $methods as $key => $label ) : ?>
										<?php $is_on = ! empty( $whitelist[ $key ] ); ?>
										<label style="display:block;margin-bottom:6px;">
											<input type="checkbox" name="payment_method_whitelist[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $is_on, true ); ?> />
											<?php echo esc_html( $label ); ?>
										</label>
									<?php endforeach; ?>
								</fieldset>
								<p class="description"><?php esc_html_e( 'Pick which payment methods to allow at checkout for this form. Leave empty to let CHIP decide.', 'chip-for-fluent-forms' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button( __( 'Save per-form settings', 'chip-for-fluent-forms' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle the per-form save POST.
	 *
	 * Capability + nonce checks, then sanitize via
	 * Chip_Fluent_Forms_Settings::sanitize_form() and persist with
	 * save_form(). Fires the public `ff_chip_form_settings_saved`
	 * action for downstream integrations.
	 *
	 * @return void
	 */
	public function handle_save() {
		if ( ! current_user_can( $this->resolve_page_cap() ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'chip-for-fluent-forms' ) );
		}

		check_admin_referer( self::NONCE_ACTION );

		$form_id = isset( $_POST['form_id'] ) ? (int) $_POST['form_id'] : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized via sanitize_form() below.
		if ( $form_id <= 0 || ! $this->get_form( $form_id ) ) {
			wp_die( esc_html__( 'Invalid form id.', 'chip-for-fluent-forms' ) );
		}

		// Read raw POST into the same array shape sanitize_form() expects.
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- sanitization happens in Chip_Fluent_Forms_Settings::sanitize_form() below.
		$raw = array(
			'is_active'                => isset( $_POST['is_active'] ) ? 'yes' : 'no',
			'payment_mode'             => isset( $_POST['payment_mode'] ) ? (string) wp_unslash( $_POST['payment_mode'] ) : 'test',
			'brand_id'                 => isset( $_POST['brand_id'] ) ? (string) wp_unslash( $_POST['brand_id'] ) : '',
			'secret_key'               => isset( $_POST['secret_key'] ) ? (string) wp_unslash( $_POST['secret_key'] ) : '',
			'due_strict'               => isset( $_POST['due_strict'] ) ? '1' : '0',
			'due_strict_timing'        => isset( $_POST['due_strict_timing'] ) ? (string) wp_unslash( $_POST['due_strict_timing'] ) : '60',
			'payment_method_whitelist' => isset( $_POST['payment_method_whitelist'] ) && is_array( $_POST['payment_method_whitelist'] )
				? (array) wp_unslash( $_POST['payment_method_whitelist'] )
				: array(),
		);
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput

		$saved = Chip_Fluent_Forms_Settings::save_form( $form_id, $raw );

		/**
		 * Fires after per-form CHIP settings are saved.
		 *
		 * Mirrors the legacy fluentform/after_save_form_settings hook but is
		 * dedicated to our per-form settings row so downstream integrations
		 * don't have to filter FF Pro's general settings payload.
		 *
		 * @param int   $form_id Fluent Forms form id.
		 * @param array $saved   The sanitized settings that were persisted.
		 */
		do_action( 'ff_chip_form_settings_saved', $form_id, $saved );

		$redirect = add_query_arg(
			array(
				'page'    => self::MENU_SLUG,
				'form_id' => $form_id,
				'saved'   => '1',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Whether the form has a customize-enabled _chip_payment_settings row.
	 *
	 * @param int $form_id Fluent Forms form id.
	 * @return bool
	 */
	private function is_form_customized( $form_id ) {
		$settings = Chip_Fluent_Forms_Settings::for_form( $form_id );
		return 'yes' === $settings['is_active'];
	}

	/**
	 * Get the FF form row by id.
	 *
	 * @param int $form_id Fluent Forms form id.
	 * @return object|null
	 */
	private function get_form( $form_id ) {
		if ( ! function_exists( 'wpFluent' ) ) {
			return null;
		}
		$form = wpFluent()->table( 'fluentform_forms' )
			->where( 'id', (int) $form_id )
			->first();
		return $form ? $form : null;
	}

	/**
	 * Get all FF forms, ordered by id.
	 *
	 * Defensive wrapper around `wpFluent()`: returns `array( 'forms' => array(),
	 * 'error' => string|null, 'wpf' => bool )` so the caller can both
	 * render the list AND surface diagnostics when the query returns
	 * empty (e.g. table-prefix mismatch, wpFluent not loaded, query
	 * threw). The `error_log()` lines help debug the same problem from
	 * the WP debug log when the admin UI is hidden.
	 *
	 * @return array{forms: array, error: ?string, wpf: bool, table: string, count: int, table_status: ?string}
	 */
	private function get_forms() {
		$table = 'fluentform_forms';

		if ( ! function_exists( 'wpFluent' ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic only, fires when the empty list view renders.
			error_log( '[chip-for-fluent-forms] get_forms: wpFluent() is not defined (Fluent Forms Pro not loaded?)' );
			return array(
				'forms'        => array(),
				'error'        => 'wpFluent() function is not defined. Fluent Forms Pro is required.',
				'wpf'          => false,
				'table'        => $table,
				'count'        => 0,
				'table_status' => null,
			);
		}

		try {
			$forms = wpFluent()->table( $table )
				->select( array( 'id', 'title' ) )
				->orderBy( 'id', 'DESC' )
				->get();
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic only, fires when the empty list view renders.
			error_log( '[chip-for-fluent-forms] get_forms: wpFluent() threw: ' . $e->getMessage() );
			return array(
				'forms'        => array(),
				'error'        => $e->getMessage(),
				'wpf'          => true,
				'table'        => $table,
				'count'        => 0,
				'table_status' => null,
			);
		}

		if ( ! is_array( $forms ) ) {
			$forms = array();
		}

		$count = count( $forms );

		// When the table is empty, also try to detect whether the table
		// actually exists with the expected prefix. This helps the
		// diagnostic distinguish between "no forms created yet" and
		// "table prefix is non-default so the query targeted the wrong
		// table". We use a global \$wpdb query (raw SQL) since the
		// wpFluent API abstracts the prefix and we want to inspect it.
		$table_status = null;
		if ( 0 === $count ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- diagnostic only, fires when the empty list view renders; result is read once and shown inline.
			$found_table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'fluentform_forms' ) );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic only.
			error_log( '[chip-for-fluent-forms] get_forms: wpFluent()->table(fluentform_forms) returned 0 rows. SHOW TABLES LIKE "' . $wpdb->prefix . 'fluentform_forms" returned: ' . ( $found_table ? $found_table : 'NOT FOUND' ) );
			$table_status = $found_table ? 'found: ' . $found_table : 'not found under prefix "' . $wpdb->prefix . '"';
		}

		return array(
			'forms'        => $forms,
			'error'        => null,
			'wpf'          => true,
			'table'        => $table,
			'count'        => $count,
			'table_status' => $table_status,
		);
	}
}
