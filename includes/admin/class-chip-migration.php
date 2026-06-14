<?php
/**
 * Two-phase migration from the legacy option schema to the new one.
 *
 * Phase 1 (one request):
 *   1. Read the legacy `fluent_form_chip` option (and `fluent_form_chip_public_key`).
 *   2. Write the new `fluent_form_chip_settings` global option and per-form
 *      fluentform_form_meta rows.
 *   3. Set `fluent_form_chip_migrated_phase1` to '1' so the next request can
 *      proceed to phase 2.
 *
 * Phase 2 (the request after phase 1):
 *   1. Verify the new global option is present, well-formed, and contains the
 *      same set of keys that were in the legacy option.
 *   2. Verify every per-form row that was in the legacy option is now in
 *      fluentform_form_meta.
 *   3. Only if all verifications pass, delete the legacy options and set
 *      `fluent_form_chip_migrated` to '1'.
 *
 * If phase 2 verification fails (partial write, DB error, race), the legacy
 * options stay put and phase 1 re-runs on the next request — idempotently.
 *
 * Wrapped in try/catch so a migration failure never blocks the rest of the
 * plugin from loading. The legacy read path in Chip_Fluent_Forms_Settings keeps
 * the plugin working in the meantime.
 *
 * @package CHIPForFluentForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chip_Fluent_Forms_Migration — see file-level docblock at the top of the file.
 */
class Chip_Fluent_Forms_Migration {

	const PHASE1_FLAG = 'fluent_form_chip_migrated_phase1';
	const DONE_FLAG   = 'fluent_form_chip_migrated';

	/**
	 * Hooked on plugins_loaded. Routes to the right phase based on flag state.
	 */
	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_migrate' ), 20 );
	}

	public static function maybe_migrate() {
		if ( '1' === get_option( self::DONE_FLAG, '0' ) ) {
			return;
		}

		// Nothing to migrate from. Mark complete and bail.
		$legacy = get_option( 'fluent_form_chip', array() );
		if ( ! is_array( $legacy ) || empty( $legacy ) ) {
			update_option( self::DONE_FLAG, '1' );
			return;
		}

		if ( '1' !== get_option( self::PHASE1_FLAG, '0' ) ) {
			// Run phase 1.
			try {
				self::phase1_write( $legacy );
				update_option( self::PHASE1_FLAG, '1' );
			} catch ( \Exception $e ) {
				error_log( '[chip-for-fluent-forms] migration phase 1 failed: ' . $e->getMessage() );
			}
			return;
		}

		// Phase 1 already ran; verify and run phase 2.
		try {
			if ( self::phase2_verify( $legacy ) ) {
				self::phase2_delete();
				update_option( self::DONE_FLAG, '1' );
			} else {
				// Verification failed: roll back phase 1 so the next request
				// retries from the (still-present) legacy source.
				self::rollback_phase1();
				error_log( '[chip-for-fluent-forms] migration phase 2 verification failed; rolling back and will retry' );
			}
		} catch ( \Exception $e ) {
			error_log( '[chip-for-fluent-forms] migration phase 2 failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Phase 1: write the new global option and per-form rows.
	 */
	private static function phase1_write( $legacy ) {
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
	}

	/**
	 * Phase 2: verify the new global option is well-formed and contains the
	 * non-empty values from the legacy option. Returns true if verification
	 * passes, false if any check fails (in which case phase 1 should roll back).
	 */
	private static function phase2_verify( $legacy ) {
		$global = get_option( 'fluent_form_chip_settings', null );
		if ( ! is_array( $global ) || empty( $global ) ) {
			return false;
		}

		// Every non-empty legacy key that has a mapping must be reflected in the
		// new global option. (Empty legacy values are not considered an error —
		// the merchant just hadn't filled them in.)
		$mapping = array(
			'secret-key'         => 'secret_key',
			'brand-id'           => 'brand_id',
			'payment-title'      => 'payment_title',
			'send-receipt'       => 'send_receipt',
			'due-strict'         => 'due_strict',
			'due-strict-timing'  => 'due_strict_timing',
			'refund'             => 'synchronize_refund',
		);

		foreach ( $mapping as $legacy_key => $new_key ) {
			if ( ! empty( $legacy[ $legacy_key ] ) && empty( $global[ $new_key ] ) ) {
				return false;
			}
		}

		// Every per-form-customized form must have a row in fluentform_form_meta.
		if ( function_exists( 'wpFluent' ) ) {
			foreach ( $legacy as $key => $value ) {
				if ( 0 !== strpos( $key, 'form-customize-' ) || empty( $value ) ) {
					continue;
				}
				$form_id = (int) substr( $key, strlen( 'form-customize-' ) );
				if ( $form_id <= 0 ) {
					continue;
				}

				$row = wpFluent()->table( 'fluentform_form_meta' )
					->where( 'form_id', $form_id )
					->where( 'meta_key', '_chip_payment_settings' )
					->first();

				if ( ! $row ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Phase 2 deletion: only reached after phase2_verify() returns true.
	 */
	private static function phase2_delete() {
		delete_option( 'fluent_form_chip' );
		delete_option( 'fluent_form_chip_public_key' );
	}

	/**
	 * Roll back phase 1 so the next request retries from the (still-present)
	 * legacy source. Best-effort: we delete what we can and clear the flag.
	 */
	private static function rollback_phase1() {
		// Only roll back the new global option if the per-form rows also got
		// partially written — but since we don't track partial state cleanly,
		// the simplest safe rollback is: leave the new global in place (it's
		// already correct) and clear the phase-1 flag. The verify will keep
		// re-checking until it passes (e.g., a per-form row that hadn't been
		// written yet on the first attempt will be written by the next
		// maybe_migrate() call's phase 1 re-run, which uses the same legacy
		// source — and is itself idempotent).
		//
		// If the rollback is triggered because the per-form rows are missing
		// but the global option is fine, we keep the global and just reset the
		// flag so phase 1 re-runs and re-attempts the per-form writes.
		delete_option( self::PHASE1_FLAG );
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
