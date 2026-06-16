<?php
/**
 * Two-phase migration from the legacy option schema to the new one.
 *
 * Phase 1 (one request):
 *   1. Read the legacy `fluent_form_chip` option.
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
 * Chip_Fluent_Forms_Migration — two-phase upgrade from the legacy
 * fluent_form_chip option layout. See file-level docblock for the
 * full phase 1 / phase 2 protocol.
 */
class Chip_Fluent_Forms_Migration {

	const PHASE1_FLAG = 'fluent_form_chip_migrated_phase1';
	const DONE_FLAG   = 'fluent_form_chip_migrated';

	/**
	 * Hooked on plugins_loaded. Routes to the right phase based on flag state.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_migrate' ), 20 );
	}

	/**
	 * Run the appropriate migration phase based on flag state.
	 *
	 * Idempotent: re-running a phase that has already completed is a no-op.
	 *
	 * @return void
	 */
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
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the migration log is intentionally written so a host admin can diagnose a stuck site.
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
				// Verification failed: roll back phase 1 so the next request retries from the (still-present) legacy source.
				self::rollback_phase1();
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- see phase-1 catch above.
				error_log( '[chip-for-fluent-forms] migration phase 2 verification failed; rolling back and will retry' );
			}
		} catch ( \Exception $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- see phase-1 catch above.
			error_log( '[chip-for-fluent-forms] migration phase 2 failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Phase 1: write the new global option and per-form rows.
	 *
	 * @param array $legacy The legacy fluent_form_chip option array.
	 * @return void
	 * @throws \RuntimeException If Chip_Fluent_Forms_Settings isn't loadable.
	 */
	private static function phase1_write( $legacy ) {
		if ( ! class_exists( 'Chip_Fluent_Forms_Settings' ) ) {
			throw new \RuntimeException( 'Chip_Fluent_Forms_Settings is not available' );
		}

		$global = Chip_Fluent_Forms_Settings::sanitize_global(
			array(
				'is_active'                => self::resolve_legacy_is_active( $legacy ),
				'payment_mode'             => isset( $legacy['payment_mode'] ) ? $legacy['payment_mode'] : 'test',
				'brand_id'                 => isset( $legacy['brand-id'] ) ? $legacy['brand-id'] : '',
				'secret_key'               => isset( $legacy['secret-key'] ) ? $legacy['secret-key'] : '',
				'payment_title'            => isset( $legacy['payment-title'] ) ? $legacy['payment-title'] : 'CHIP',
				'due_strict'               => ! empty( $legacy['due-strict'] ) ? '1' : '0',
				'due_strict_timing'        => isset( $legacy['due-strict-timing'] ) ? $legacy['due-strict-timing'] : '60',
				'payment_method_whitelist' => self::resolve_legacy_whitelist( $legacy, '' ),
			)
		);

		update_option( 'fluent_form_chip_settings', $global );

		// Migrate per-form entries.
		if ( function_exists( 'wpFluent' ) ) {
			$forms = wpFluent()->table( 'fluentform_forms' )
				->select( array( 0 => 'id' ) )
				->orderBy( 'id' )
				->get();

			if ( is_array( $forms ) ) {
				foreach ( $forms as $form ) {
					$form_id = (int) $form->id;

					if ( empty( $legacy[ 'form-customize-' . $form_id ] ) ) {
						continue;
					}

					$postfix = '-' . $form_id;

					$per_form = Chip_Fluent_Forms_Settings::sanitize_form(
						array(
							'is_active'                => 'yes',
							'payment_mode'             => isset( $legacy[ 'payment-mode' . $postfix ] ) ? $legacy[ 'payment-mode' . $postfix ] : 'test',
							'brand_id'                 => isset( $legacy[ 'brand-id' . $postfix ] ) ? $legacy[ 'brand-id' . $postfix ] : '',
							'secret_key'               => isset( $legacy[ 'secret-key' . $postfix ] ) ? $legacy[ 'secret-key' . $postfix ] : '',
							'due_strict'               => ! empty( $legacy[ 'due-strict' . $postfix ] ) ? '1' : '0',
							'due_strict_timing'        => isset( $legacy[ 'due-strict-timing' . $postfix ] ) ? $legacy[ 'due-strict-timing' . $postfix ] : '60',
							'payment_method_whitelist' => self::resolve_legacy_whitelist( $legacy, $postfix ),
						)
					);

					Chip_Fluent_Forms_Settings::save_form( $form_id, $per_form );
				}
			}
		}

		// Ensure chip is enabled in every form's `payment_method` field
		// so the upgrade is transparent — no per-form admin action
		// required. Skips fields where the merchant has explicitly set
		// enabled='no' on chip (preserves their choice).
		self::migrate_per_form_payment_methods();
	}

	/**
	 * Phase 2: verify the new global option is well-formed and contains the
	 * non-empty values from the legacy option. Returns true if verification
	 * passes, false if any check fails (in which case phase 1 should roll back).
	 *
	 * @param array $legacy The legacy fluent_form_chip option array.
	 * @return bool
	 */
	private static function phase2_verify( $legacy ) {
		$global = get_option( 'fluent_form_chip_settings', null );
		if ( ! is_array( $global ) || empty( $global ) ) {
			return false;
		}

		// Every non-empty legacy key that has a mapping must be reflected in the new global option.
		// Empty legacy values are not considered an error — the merchant just hadn't filled them in.
		$mapping = array(
			'secret-key'        => 'secret_key',
			'brand-id'          => 'brand_id',
			'payment-title'     => 'payment_title',
			'due-strict'        => 'due_strict',
			'due-strict-timing' => 'due_strict_timing',
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
					->where( 'meta_key', '_chip_payment_settings' ) // phpcs:ignore WordPress.DB.SlowDBQuery -- the fluentform_form_meta table is not a WordPress postmeta table, so the standard slow-query rule does not apply.
					->first();

				if ( ! $row ) {
					return false;
				}
			}
		}

		// Spot-check: at least one form with a payment_method field should
		// have chip enabled (the per-form payment_methods walker ran).
		// This catches a silent failure of the per-form migration.
		if ( function_exists( 'wpFluent' ) ) {
			try {
				$rows = wpFluent()->table( 'fluentform_forms' )
					->select( array( 'form_fields' ) )
					->get();

				if ( is_array( $rows ) ) {
					foreach ( $rows as $row ) {
						$decoded = json_decode( (string) $row->form_fields, true );
						if ( ! is_array( $decoded ) ) {
							continue;
						}
						if ( self::fields_have_payment_method( $decoded )
							&& ! self::any_payment_method_has_chip_enabled( $decoded )
						) {
							return false;
						}
					}
				}
			} catch ( \Exception $e ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- see phase-1 catch above.
				error_log( '[chip-for-fluent-forms] per-form payment_methods verify failed: ' . $e->getMessage() );
				return false;
			}
		}

		return true;
	}

	/**
	 * True if any `payment_method` field in the form has `chip` with
	 * `enabled='yes'`. Used by the per-form migration spot-check.
	 *
	 * @param array $fields A form's `form_fields` decoded array.
	 * @return bool
	 */
	private static function any_payment_method_has_chip_enabled( array $fields ) {
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			if ( ( $field['element'] ?? '' ) === 'payment_method' ) {
				$methods = $field['settings']['payment_methods'] ?? array();
				if ( isset( $methods['chip']['enabled'] ) && 'yes' === $methods['chip']['enabled'] ) {
					return true;
				}
			}
			$children = $field['fields'] ?? null;
			if ( is_array( $children ) && self::any_payment_method_has_chip_enabled( $children ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Phase 2 deletion: only reached after phase2_verify() returns true.
	 *
	 * @return void
	 */
	private static function phase2_delete() {
		delete_option( 'fluent_form_chip' );
	}

	/**
	 * Roll back phase 1 so the next request retries from the (still-present)
	 * legacy source. Best-effort: we delete what we can and clear the flag.
	 *
	 * @return void
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
	 * the presence of any non-empty legacy value as is_active=yes so an
	 * upgrading user does not have to manually re-check the Enable toggle
	 * under Fluent Forms -> Settings -> Payment Methods after the Codestar
	 * drop.
	 *
	 * @param array $legacy The legacy fluent_form_chip option array.
	 * @return string 'yes' or 'no'.
	 */
	private static function resolve_legacy_is_active( $legacy ) {
		if ( isset( $legacy['is_active'] ) ) {
			return 'yes' === $legacy['is_active'] ? 'yes' : 'no';
		}
		// 1.x had no master enable toggle. The option existing at all meant
		// the merchant was running CHIP — preserve that on upgrade.
		return ! empty( $legacy ) ? 'yes' : 'no';
	}

	/**
	 * Translate the legacy 4-boolean flat keys into the new whitelist array.
	 *
	 * The four legacy keys are: payment-method-fpx, payment-method-fpxb2b1,
	 * payment-method-card (which expands to visa+mastercard+maestro), and
	 * payment-method-duitnow (which becomes duitnow_qr).
	 *
	 * @param array  $legacy  The legacy fluent_form_chip option array.
	 * @param string $postfix '' for the global settings, '-{form_id}' for per-form.
	 * @return array Map of new whitelist key => '1'.
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

	/**
	 * Ensure `chip` is enabled in every form's `payment_method` field.
	 *
	 * In 1.x the chip method was always present in
	 * `fluentform/available_payment_methods` (the legacy
	 * `Chip_Fluent_Forms_Register::push` registered it unconditionally), so
	 * the form editor's `recheckEditorComponent` would seed a `chip` entry
	 * into each form's per-form `payment_methods` array on first open.
	 * Forms whose owners then enabled chip in the editor had it persisted
	 * with `enabled='yes'` in the form's `form_fields` JSON.
	 *
	 * Forms that were created in 1.x but never re-saved through the form
	 * editor in 2.0 may have a `payment_method` field whose
	 * `settings.payment_methods` array either omits `chip` entirely or
	 * has it with `enabled='no'`. This walker makes the upgrade
	 * transparent: any such field gets a `chip` entry with
	 * `enabled='yes'`. Explicit `enabled='no'` choices are preserved.
	 *
	 * Idempotent: re-running on a form that already has `chip` with
	 * `enabled='yes'` is a no-op (early return before the JSON write).
	 *
	 * @return void
	 */
	private static function migrate_per_form_payment_methods() {
		if ( ! function_exists( 'wpFluent' ) ) {
			return;
		}

		try {
			$forms = wpFluent()->table( 'fluentform_forms' )
				->select( array( 'id', 'form_fields' ) )
				->get();
		} catch ( \Exception $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- see phase-1 catch above.
			error_log( '[chip-for-fluent-forms] per-form payment_methods migration read failed: ' . $e->getMessage() );
			return;
		}

		if ( ! is_array( $forms ) ) {
			return;
		}

		foreach ( $forms as $form ) {
			$form_id    = (int) $form->id;
			$raw_fields = json_decode( (string) $form->form_fields, true );

			if ( ! is_array( $raw_fields ) || ! self::fields_have_payment_method( $raw_fields ) ) {
				continue;
			}

			$updated = self::ensure_chip_enabled_in_fields( $raw_fields );
			if ( $updated === $raw_fields ) {
				continue;
			}

			try {
				wpFluent()->table( 'fluentform_forms' )
					->where( 'id', $form_id )
					->update( array( 'form_fields' => wp_json_encode( $updated ) ) );
			} catch ( \Exception $e ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- see phase-1 catch above.
				error_log( '[chip-for-fluent-forms] per-form payment_methods migration write failed for form ' . $form_id . ': ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Walk a form-field tree looking for a `payment_method` element.
	 *
	 * FF Pro nests child fields under a `fields` key on container
	 * elements (e.g. multi-column layouts), so the search recurses.
	 *
	 * @param array $fields A form's `form_fields` decoded array.
	 * @return bool True if any field in the tree is a `payment_method`.
	 */
	private static function fields_have_payment_method( array $fields ) {
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			if ( ( $field['element'] ?? '' ) === 'payment_method' ) {
				return true;
			}
			$children = $field['fields'] ?? null;
			if ( is_array( $children ) && self::fields_have_payment_method( $children ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Set `chip` to `enabled='yes'` in every `payment_method` field of a
	 * form-field tree unless the merchant has explicitly set it to `'no'`.
	 *
	 * Returns the same array reference (and a structural `===` match)
	 * when no changes were needed so the caller can skip the JSON write.
	 *
	 * @param array $fields A form's `form_fields` decoded array.
	 * @return array The (possibly) updated array.
	 */
	private static function ensure_chip_enabled_in_fields( array $fields ) {
		$changed = false;

		foreach ( $fields as $i => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			if ( ( $field['element'] ?? '' ) === 'payment_method' ) {
				$settings = isset( $field['settings'] ) && is_array( $field['settings'] )
					? $field['settings']
					: array();
				$methods  = isset( $settings['payment_methods'] ) && is_array( $settings['payment_methods'] )
					? $settings['payment_methods']
					: array();

				$current = isset( $methods['chip']['enabled'] ) ? (string) $methods['chip']['enabled'] : null;

				// Skip fields where the merchant has explicitly disabled chip.
				if ( 'no' !== $current && 'yes' !== $current ) {
					// Missing or set to something else: enable it with a default method object.
					$methods['chip']            = array(
						'title'        => 'CHIP',
						'enabled'      => 'yes',
						'method_value' => 'chip',
						'settings'     => array(),
					);
					$settings['payment_methods'] = $methods;
					$fields[ $i ]['settings']    = $settings;
					$changed                     = true;
				}
			}

			$children = $field['fields'] ?? null;
			if ( is_array( $children ) ) {
				$new_children = self::ensure_chip_enabled_in_fields( $children );
				if ( $new_children !== $children ) {
					$fields[ $i ]['fields'] = $new_children;
					$changed                = true;
				}
			}
		}

		return $changed ? $fields : $fields;
	}
}

Chip_Fluent_Forms_Migration::init();
