<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-time migration from the legacy option schema to the new one.
 *
 * Triggered on `plugins_loaded` at priority 20. Reads the legacy `fluent_form_chip`
 * option (and the cross-cutting `fluent_form_chip_public_key` option), writes the
 * new global option and per-form fluentform_form_meta rows, sets a `fluent_form_chip_migrated`
 * flag, and deletes the legacy options.
 *
 * Idempotent: re-running is a no-op. Wrapped in try/catch so a migration failure
 * never blocks the rest of the plugin from loading.
 */
class Chip_Fluent_Forms_Migration {

	const FLAG_OPTION = 'fluent_form_chip_migrated';

	/**
	 * Hooked on plugins_loaded. Does nothing if the migration has already run.
	 */
	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_migrate' ), 20 );
	}

	public static function maybe_migrate() {
		if ( '1' === get_option( self::FLAG_OPTION, '0' ) ) {
			return;
		}

		// Nothing to migrate from.
		$legacy = get_option( 'fluent_form_chip', array() );
		if ( ! is_array( $legacy ) || empty( $legacy ) ) {
			// Still mark complete so we don't re-check on every request.
			update_option( self::FLAG_OPTION, '1' );
			return;
		}

		try {
			self::migrate( $legacy );
			update_option( self::FLAG_OPTION, '1' );
		} catch ( \Exception $e ) {
			// Don't block plugin load; the legacy read path inside Chip_Fluent_Forms_Settings
			// will keep the plugin working until the next request retries.
			error_log( '[chip-for-fluent-forms] migration failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Execute the migration.
	 */
	private static function migrate( $legacy ) {
		if ( ! class_exists( 'Chip_Fluent_Forms_Settings' ) ) {
			throw new \RuntimeException( 'Chip_Fluent_Forms_Settings is not available' );
		}

		$global = Chip_Fluent_Forms_Settings::sanitize_global( array(
			'is_active'                => self::resolve_legacy_is_active( $legacy ),
			'payment_mode'             => isset( $legacy['payment_mode'] ) ? $legacy['payment_mode'] : 'test',
			'brand_id'                 => isset( $legacy['brand-id'] ) ? $legacy['brand-id'] : '',
			'secret_key'               => isset( $legacy['secret-key'] ) ? $legacy['secret-key'] : '',
			'payment_title'            => isset( $legacy['payment-title'] ) ? $legacy['payment-title'] : 'CHIP',
			'send_receipt'             => ! empty( $legacy['send-receipt'] ) ? '1' : '0',
			'due_strict'               => ! empty( $legacy['due-strict'] ) ? '1' : '0',
			'due_strict_timing'        => isset( $legacy['due-strict-timing'] ) ? $legacy['due-strict-timing'] : '60',
			'payment_method_whitelist' => self::resolve_legacy_whitelist( $legacy, '' ),
			'synchronize_refund'       => ! empty( $legacy['refund'] ) ? '1' : '0',
		) );

		// Migrate global public key.
		$public_key_legacy = get_option( 'fluent_form_chip_public_key', array() );
		if ( is_array( $public_key_legacy ) && ! empty( $public_key_legacy['public-key'] ) ) {
			$global['public_key'] = (string) $public_key_legacy['public-key'];
		}

		update_option( 'fluent_form_chip_settings', $global );

		// Migrate per-form entries.
		if ( function_exists( 'wpFluent' ) ) {
			$forms = wpFluent()->table( 'fluentform_forms' )
				->select( array( 'id' ) )
				->orderBy( 'id' )
				->get();

			if ( is_array( $forms ) ) {
				foreach ( $forms as $form ) {
					$form_id = (int) $form->id;

					if ( empty( $legacy[ 'form-customize-' . $form_id ] ) ) {
						continue;
					}

					$postfix = '-' . $form_id;

					$per_form = Chip_Fluent_Forms_Settings::sanitize_form( array(
						'is_active'                => 'yes',
						'payment_mode'             => isset( $legacy[ 'payment-mode' . $postfix ] ) ? $legacy[ 'payment-mode' . $postfix ] : 'test',
						'brand_id'                 => isset( $legacy[ 'brand-id' . $postfix ] ) ? $legacy[ 'brand-id' . $postfix ] : '',
						'secret_key'               => isset( $legacy[ 'secret-key' . $postfix ] ) ? $legacy[ 'secret-key' . $postfix ] : '',
						'send_receipt'             => ! empty( $legacy[ 'send-receipt' . $postfix ] ) ? '1' : '0',
						'due_strict'               => ! empty( $legacy[ 'due-strict' . $postfix ] ) ? '1' : '0',
						'due_strict_timing'        => isset( $legacy[ 'due-strict-timing' . $postfix ] ) ? $legacy[ 'due-strict-timing' . $postfix ] : '60',
						'payment_method_whitelist' => self::resolve_legacy_whitelist( $legacy, $postfix ),
						'synchronize_refund'       => ! empty( $legacy[ 'refund' . $postfix ] ) ? '1' : '0',
					) );

					$per_form_public_key = isset( $public_key_legacy[ 'public-key' . $postfix ] )
						? (string) $public_key_legacy[ 'public-key' . $postfix ]
						: '';
					if ( '' !== $per_form_public_key ) {
						$per_form['public_key'] = $per_form_public_key;
					}

					Chip_Fluent_Forms_Settings::save_form( $form_id, $per_form );
				}
			}
		}

		// Delete legacy options only after the new ones are written. A failure
		// mid-write still leaves the legacy option in place so the next request
		// can retry from a clean source.
		delete_option( 'fluent_form_chip' );
		delete_option( 'fluent_form_chip_public_key' );
	}

	/**
	 * The legacy schema did not have an is_active toggle at the global level
	 * (CHIP was effectively always active when the option was present). Treat
	 * the presence of any global setting as is_active=yes.
	 */
	private static function resolve_legacy_is_active( $legacy ) {
		if ( isset( $legacy['is_active'] ) ) {
			return 'yes' === $legacy['is_active'] ? 'yes' : 'no';
		}
		return ! empty( $legacy['secret-key'] ) ? 'yes' : 'no';
	}

	/**
	 * Translate the legacy 4-boolean flat keys into the new whitelist array.
	 *
	 * The four legacy keys are: payment-method-fpx, payment-method-fpxb2b1,
	 * payment-method-card (which expands to visa+mastercard+maestro), and
	 * payment-method-duitnow (which becomes duitnow_qr).
	 */
	private static function resolve_legacy_whitelist( $legacy, $postfix ) {
		$out = array();

		if ( ! empty( $legacy[ 'payment-method-fpx' . $postfix ] ) ) {
			$out['fpx'] = '1';
		}
		if ( ! empty( $legacy[ 'payment-method-fpxb2b1' . $postfix ] ) ) {
			$out['fpx_b2b1'] = '1';
		}
		if ( ! empty( $legacy[ 'payment-method-card' . $postfix ] ) ) {
			$out['cards'] = '1';
		}
		if ( ! empty( $legacy[ 'payment-method-duitnow' . $postfix ] ) ) {
			$out['duitnow_qr'] = '1';
		}

		return $out;
	}
}

Chip_Fluent_Forms_Migration::init();
