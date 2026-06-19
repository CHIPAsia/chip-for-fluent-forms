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
 * The page registers as a top-level menu (`add_menu_page`) rather
 * than a submenu of `fluent_forms` — the FF Pro parent menu gates
 * access on `fluentform_dashboard_access`, which stock admins
 * don't have, so a submenu there would always return "Sorry, you
 * are not allowed to access this page."
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
	 * Register the submenu + save handler.
	 *
	 * Hooked from Chip_Fluent_Forms::includes() under the is_admin()
	 * branch so this code never runs on public requests.
	 *
	 * @return void
	 */
	public function register() {
		// Register as a top-level menu under its own slug rather than a
		// submenu of `fluent_forms`. The FF Pro parent menu gates access
		// on the `fluentform_dashboard_access` cap, which the administrator
		// role does not automatically have — so a submenu there would
		// always return "Sorry, you are not allowed to access this page"
		// for stock admin users. A top-level menu gives us a clean cap
		// gate (`manage_options`) and the URL pattern the merchant
		// expects: /wp-admin/admin.php?page=chip-form-settings.
		add_menu_page(
			__( 'CHIP Per-Form Settings', 'chip-for-fluent-forms' ),
			__( 'CHIP Per-Form', 'chip-for-fluent-forms' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render' ),
			'dashicons-admin-generic',
			81 // Just under Fluent Forms (which is typically at 80.x).
		);

		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );
	}

	/**
	 * Dispatch the request to the list view or per-form view.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
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
		$forms = $this->get_forms();

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
		if ( ! current_user_can( 'manage_options' ) ) {
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
	 * @return array Array of {id, title} objects (empty on error).
	 */
	private function get_forms() {
		if ( ! function_exists( 'wpFluent' ) ) {
			return array();
		}
		$forms = wpFluent()->table( 'fluentform_forms' )
			->select( array( 'id', 'title' ) )
			->orderBy( 'id', 'DESC' )
			->get();
		return is_array( $forms ) ? $forms : array();
	}
}
