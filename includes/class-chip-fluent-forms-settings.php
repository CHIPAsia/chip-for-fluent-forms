<?php
/**
 * Centralized reader for the new CHIP settings schema.
 *
 * @package CHIPForFluentForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chip_Fluent_Forms_Settings — centralized reader for the new CHIP settings schema.
 *
 * The legacy plugin stored every setting (global and per-form) in a single
 * option `fluent_form_chip` with hyphenated keys and `-{form_id}` postfixes.
 * The refactored plugin uses:
 *
 *   - `fluent_form_chip_settings` (global)
 *   - `fluentform_form_meta` rows with `meta_key = '_chip_payment_settings'`
 *     (per-form overrides)
 *
 * The legacy option is migrated by Chip_Fluent_Forms_Migration on plugins_loaded.
 * While a site is in the pre-migration window, the legacy keys still take effect
 * via the migration fallback so the plugin keeps working.
 */
class Chip_Fluent_Forms_Settings {

	/**
	 * Payment methods the user can enable for whitelisting.
	 *
	 * Keys are the canonical names sent to the CHIP API, except for `cards`
	 * which is a UI shortcut that expands to visa+mastercard+maestro at
	 * send-time (see expand_whitelist()).
	 *
	 * @return array Map of method key => human label.
	 */
	public static function payment_methods() {
		return array(
			'fpx'             => __( 'FPX', 'chip-for-fluent-forms' ),
			'fpx_b2b1'        => __( 'FPX B2B1', 'chip-for-fluent-forms' ),
			'crypto_coin'     => __( 'Crypto', 'chip-for-fluent-forms' ),
			'dnqr'            => __( 'DuitNow QR', 'chip-for-fluent-forms' ),
			'duitnow_qr'      => __( 'DuitNow QR (Legacy)', 'chip-for-fluent-forms' ),
			'cards'           => __( 'Cards (Visa, Mastercard, Maestro)', 'chip-for-fluent-forms' ),
			'mpgs_apple_pay'  => __( 'Apple Pay', 'chip-for-fluent-forms' ),
			'mpgs_google_pay' => __( 'Google Pay', 'chip-for-fluent-forms' ),
			'razer_atome'     => __( 'Atome', 'chip-for-fluent-forms' ),
			'razer_grabpay'   => __( 'GrabPay', 'chip-for-fluent-forms' ),
			'razer_maybankqr' => __( 'Maybank QR', 'chip-for-fluent-forms' ),
			'razer_shopeepay' => __( 'ShopeePay', 'chip-for-fluent-forms' ),
			'razer_tng'       => __( "Touch 'n Go", 'chip-for-fluent-forms' ),
			'shopee_pay'      => __( 'Shopee Pay', 'chip-for-fluent-forms' ),
		);
	}

	/**
	 * Whitelist entries that expand to multiple keys at send-time.
	 *
	 * The merchant only ever enables `cards` once, but the CHIP API expects
	 * `visa`, `mastercard`, and `maestro` to be listed individually.
	 *
	 * @return array Map of source key => list of expansion targets.
	 */
	public static function whitelist_expansions() {
		return array(
			'cards' => array( 'visa', 'mastercard', 'maestro' ),
		);
	}

	/**
	 * Schema for the new global settings option.
	 *
	 * Used by the admin page, the migration, and the test/live sanity check.
	 *
	 * @return array
	 */
	public static function global_defaults() {
		return array(
			'is_active'                => 'no',
			'payment_mode'             => 'test',
			'brand_id'                 => '',
			'secret_key'               => '',
			'payment_title'            => 'CHIP',
			'send_receipt'             => '0',
			'due_strict'               => '1',
			'due_strict_timing'        => '60',
			'payment_method_whitelist' => array(),
			'synchronize_refund'       => '0',
			'public_key'               => '',
		);
	}

	/**
	 * Schema for per-form payment settings.
	 *
	 * Per-form settings are merged on top of the global settings; only the
	 * `is_active` flag differentiates (when `is_active=yes` for a form, the
	 * per-form values override; otherwise the global values are used).
	 *
	 * @return array
	 */
	public static function form_defaults() {
		return array(
			'is_active'                => 'no',
			'payment_mode'             => 'test',
			'brand_id'                 => '',
			'secret_key'               => '',
			'send_receipt'             => '0',
			'due_strict'               => '1',
			'due_strict_timing'        => '60',
			'payment_method_whitelist' => array(),
			'synchronize_refund'       => '0',
			'public_key'               => '',
		);
	}

	/**
	 * Read the global settings.
	 *
	 * @return array
	 */
	public static function global() {
		$stored = get_option( 'fluent_form_chip_settings', array() );

		if ( ! is_array( $stored ) || empty( $stored ) ) {
			// Pre-migration fallback: read the legacy option and re-shape on the fly.
			$stored = self::migrate_legacy_to_global( get_option( FF_CHIP_FSLUG, array() ) );
		}

		return wp_parse_args( $stored, self::global_defaults() );
	}

	/**
	 * Read the effective per-form settings.
	 *
	 * Returns the global settings with per-form overrides applied on top.
	 *
	 * @param int $form_id Fluent Forms form id.
	 * @return array
	 */
	public static function for_form( $form_id ) {
		$form_id  = (int) $form_id;
		$global   = self::global();
		$defaults = self::form_defaults();

		// Per-form storage lives in fluentform_form_meta.
		$stored = array();
		if ( function_exists( 'wpFluent' ) ) {
			$row = wpFluent()->table( 'fluentform_form_meta' )
				->where( 'form_id', $form_id )
				->where( 'meta_key', '_chip_payment_settings' )
				->first();
			if ( $row && ! empty( $row->value ) ) {
				$unserialized = maybe_unserialize( $row->value );
				if ( is_array( $unserialized ) ) {
					$stored = $unserialized;
				}
			}
		}

		if ( empty( $stored ) ) {
			// Pre-migration fallback: read the legacy per-form postfix keys.
			$legacy = get_option( FF_CHIP_FSLUG, array() );
			if ( ! empty( $legacy[ 'form-customize-' . $form_id ] ) ) {
				$stored = self::migrate_legacy_to_form( $legacy, $form_id );
			}
		}

		$merged = wp_parse_args( $stored, $defaults );

		// If the per-form toggle is off, fall back to the global values for every
		// effective field except the per-form "is_active" flag itself.
		if ( 'yes' !== $merged['is_active'] ) {
			$merged = array_merge( $merged, array(
				'brand_id'                => $global['brand_id'],
				'secret_key'              => $global['secret_key'],
				'payment_mode'            => $global['payment_mode'],
				'send_receipt'            => $global['send_receipt'],
				'due_strict'              => $global['due_strict'],
				'due_strict_timing'       => $global['due_strict_timing'],
				'payment_method_whitelist' => $global['payment_method_whitelist'],
				'synchronize_refund'      => $global['synchronize_refund'],
				'public_key'              => $global['public_key'],
			) );
		}

		return $merged;
	}

	/**
	 * Expand a list of enabled whitelist keys into the list the CHIP API expects.
	 *
	 * @param array $enabled Map of key => truthy.
	 * @return array Flat list of CHIP API method keys.
	 */
	public static function expand_whitelist( $enabled ) {
		$out      = array();
		$expanded = self::whitelist_expansions();

		foreach ( array_keys( array_filter( $enabled ) ) as $key ) {
			if ( isset( $expanded[ $key ] ) ) {
				$out = array_merge( $out, $expanded[ $key ] );
			} else {
				$out[] = $key;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Persist the global settings.
	 *
	 * @param array $settings The global settings payload.
	 * @return array The sanitized settings that were saved.
	 */
	public static function save_global( $settings ) {
		$clean = self::sanitize_global( $settings );
		update_option( 'fluent_form_chip_settings', $clean );
		return $clean;
	}

	/**
	 * Persist per-form settings.
	 *
	 * @param int   $form_id  Fluent Forms form id.
	 * @param array $settings The per-form settings payload.
	 * @return array The sanitized settings that were saved.
	 */
	public static function save_form( $form_id, $settings ) {
		$form_id = (int) $form_id;
		$clean   = self::sanitize_form( $settings );

		if ( function_exists( 'wpFluent' ) ) {
			$row = wpFluent()->table( 'fluentform_form_meta' )
				->where( 'form_id', $form_id )
				->where( 'meta_key', '_chip_payment_settings' )
				->first();

			if ( $row ) {
				wpFluent()->table( 'fluentform_form_meta' )
					->where( 'id', $row->id )
					->update( array(
						'value'      => maybe_serialize( $clean ),
						'updated_at' => current_time( 'mysql' ),
					) );
			} else {
				wpFluent()->table( 'fluentform_form_meta' )->insert( array(
					'form_id'    => $form_id,
					'meta_key'   => '_chip_payment_settings',
					'value'      => maybe_serialize( $clean ),
					'created_at' => current_time( 'mysql' ),
					'updated_at' => current_time( 'mysql' ),
				) );
			}
		}

		/**
		 * Fires after per-form CHIP settings have been persisted.
		 *
		 * Used internally to drive the per-form refund webhook setup.
		 *
		 * @param int   $form_id  The Fluent Forms form id.
		 * @param array $clean    The sanitized per-form settings.
		 */
		do_action( 'ff_chip_form_settings_saved', $form_id, $clean );

		return $clean;
	}

	/**
	 * Sanitize a global settings payload.
	 *
	 * @param mixed $settings The untrusted settings payload.
	 * @return array
	 */
	public static function sanitize_global( $settings ) {
		$defaults = self::global_defaults();

		$is_active  = ! empty( $settings['is_active'] ) && 'yes' === $settings['is_active'] ? 'yes' : 'no';
		$mode       = isset( $settings['payment_mode'] ) && 'live' === $settings['payment_mode'] ? 'live' : 'test';
		$send_rcpt  = ! empty( $settings['send_receipt'] ) ? '1' : '0';
		$due_strict = ! empty( $settings['due_strict'] ) ? '1' : '0';
		$sync_rfnd  = ! empty( $settings['synchronize_refund'] ) ? '1' : '0';

		$timing = isset( $settings['due_strict_timing'] ) ? absint( $settings['due_strict_timing'] ) : 60;
		if ( $timing <= 0 ) {
			$timing = 60;
		}

		$whitelist = array();
		if ( isset( $settings['payment_method_whitelist'] ) && is_array( $settings['payment_method_whitelist'] ) ) {
			$valid = array_keys( self::payment_methods() );
			foreach ( $settings['payment_method_whitelist'] as $key => $on ) {
				if ( in_array( $key, $valid, true ) && ! empty( $on ) ) {
					$whitelist[ $key ] = '1';
				}
			}
		}

		return array(
			'is_active'                => $is_active,
			'payment_mode'             => $mode,
			'brand_id'                 => isset( $settings['brand_id'] ) ? sanitize_text_field( $settings['brand_id'] ) : '',
			'secret_key'               => isset( $settings['secret_key'] ) ? sanitize_text_field( $settings['secret_key'] ) : '',
			'payment_title'            => isset( $settings['payment_title'] ) ? sanitize_text_field( $settings['payment_title'] ) : 'CHIP',
			'send_receipt'             => $send_rcpt,
			'due_strict'               => $due_strict,
			'due_strict_timing'        => (string) $timing,
			'payment_method_whitelist' => $whitelist,
			'synchronize_refund'       => $sync_rfnd,
			'public_key'               => isset( $settings['public_key'] ) ? (string) $settings['public_key'] : '',
		);
	}

	/**
	 * Sanitize a per-form settings payload.
	 *
	 * @param mixed $settings The untrusted per-form settings payload.
	 * @return array
	 */
	public static function sanitize_form( $settings ) {
		$defaults = self::form_defaults();

		$is_active  = ! empty( $settings['is_active'] ) && 'yes' === $settings['is_active'] ? 'yes' : 'no';
		$mode       = isset( $settings['payment_mode'] ) && 'live' === $settings['payment_mode'] ? 'live' : 'test';
		$send_rcpt  = ! empty( $settings['send_receipt'] ) ? '1' : '0';
		$due_strict = ! empty( $settings['due_strict'] ) ? '1' : '0';
		$sync_rfnd  = ! empty( $settings['synchronize_refund'] ) ? '1' : '0';

		$timing = isset( $settings['due_strict_timing'] ) ? absint( $settings['due_strict_timing'] ) : 60;
		if ( $timing <= 0 ) {
			$timing = 60;
		}

		$whitelist = array();
		if ( isset( $settings['payment_method_whitelist'] ) && is_array( $settings['payment_method_whitelist'] ) ) {
			$valid = array_keys( self::payment_methods() );
			foreach ( $settings['payment_method_whitelist'] as $key => $on ) {
				if ( in_array( $key, $valid, true ) && ! empty( $on ) ) {
					$whitelist[ $key ] = '1';
				}
			}
		}

		return array(
			'is_active'                => $is_active,
			'payment_mode'             => $mode,
			'brand_id'                 => isset( $settings['brand_id'] ) ? sanitize_text_field( $settings['brand_id'] ) : '',
			'secret_key'               => isset( $settings['secret_key'] ) ? sanitize_text_field( $settings['secret_key'] ) : '',
			'send_receipt'             => $send_rcpt,
			'due_strict'               => $due_strict,
			'due_strict_timing'        => (string) $timing,
			'payment_method_whitelist' => $whitelist,
			'synchronize_refund'       => $sync_rfnd,
			'public_key'               => isset( $settings['public_key'] ) ? (string) $settings['public_key'] : '',
		);
	}

	/**
	 * @internal
	 *
	 * Map the legacy flat option keys into the new global settings shape.
	 * Used only while a site is in the pre-migration window.
	 *
	 * @param mixed $legacy The legacy option payload.
	 * @return array
	 */
	private static function migrate_legacy_to_global( $legacy ) {
		if ( ! is_array( $legacy ) ) {
			return array();
		}

		$is_active = ! empty( $legacy['is_active'] ) ? $legacy['is_active'] : 'yes';

		$whitelist = array();
		foreach ( array(
			'fpx', 'fpx_b2b1', 'duitnow',
		) as $legacy_key ) {
			$option_key = 'payment-method-' . $legacy_key;
			if ( ! empty( $legacy[ $option_key ] ) ) {
				$whitelist[ 'fpx' === $legacy_key ? 'fpx' : ( 'fpx_b2b1' === $legacy_key ? 'fpx_b2b1' : 'duitnow_qr' ) ] = '1';
			}
		}
		if ( ! empty( $legacy['payment-method-card'] ) ) {
			$whitelist['cards'] = '1';
		}

		return array(
			'is_active'                => $is_active,
			'payment_mode'             => isset( $legacy['payment_mode'] ) ? $legacy['payment_mode'] : 'test',
			'brand_id'                 => isset( $legacy['brand-id'] ) ? (string) $legacy['brand-id'] : '',
			'secret_key'               => isset( $legacy['secret-key'] ) ? (string) $legacy['secret-key'] : '',
			'payment_title'            => isset( $legacy['payment-title'] ) ? (string) $legacy['payment-title'] : 'CHIP',
			'send_receipt'             => ! empty( $legacy['send-receipt'] ) ? '1' : '0',
			'due_strict'               => ! empty( $legacy['due-strict'] ) ? '1' : '0',
			'due_strict_timing'        => isset( $legacy['due-strict-timing'] ) ? (string) $legacy['due-strict-timing'] : '60',
			'payment_method_whitelist' => $whitelist,
			'synchronize_refund'       => ! empty( $legacy['refund'] ) ? '1' : '0',
			'public_key'               => '',
		);
	}

	/**
	 * @internal
	 *
	 * Map the legacy per-form postfix keys into the new per-form shape.
	 *
	 * @param mixed $legacy  The legacy option payload.
	 * @param int   $form_id Fluent Forms form id.
	 * @return array
	 */
	private static function migrate_legacy_to_form( $legacy, $form_id ) {
		$postfix = '-' . (int) $form_id;
		$whitelist = array();
		foreach ( array( 'fpx', 'fpx_b2b1', 'duitnow' ) as $legacy_key ) {
			$option_key = 'payment-method-' . $legacy_key . $postfix;
			if ( ! empty( $legacy[ $option_key ] ) ) {
				$whitelist[ 'fpx' === $legacy_key ? 'fpx' : ( 'fpx_b2b1' === $legacy_key ? 'fpx_b2b1' : 'duitnow_qr' ) ] = '1';
			}
		}
		if ( ! empty( $legacy[ 'payment-method-card' . $postfix ] ) ) {
			$whitelist['cards'] = '1';
		}

		return array(
			'is_active'                => 'yes',
			'payment_mode'             => isset( $legacy[ 'payment-mode' . $postfix ] ) ? $legacy[ 'payment-mode' . $postfix ] : 'test',
			'brand_id'                 => isset( $legacy[ 'brand-id' . $postfix ] ) ? (string) $legacy[ 'brand-id' . $postfix ] : '',
			'secret_key'               => isset( $legacy[ 'secret-key' . $postfix ] ) ? (string) $legacy[ 'secret-key' . $postfix ] : '',
			'send_receipt'             => ! empty( $legacy[ 'send-receipt' . $postfix ] ) ? '1' : '0',
			'due_strict'               => ! empty( $legacy[ 'due-strict' . $postfix ] ) ? '1' : '0',
			'due_strict_timing'        => isset( $legacy[ 'due-strict-timing' . $postfix ] ) ? (string) $legacy[ 'due-strict-timing' . $postfix ] : '60',
			'payment_method_whitelist' => $whitelist,
			'synchronize_refund'       => ! empty( $legacy[ 'refund' . $postfix ] ) ? '1' : '0',
			'public_key'               => '',
		);
	}
}
