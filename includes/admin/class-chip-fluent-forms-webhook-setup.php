<?php
/**
 * Webhook setup for CHIP refund synchronization.
 *
 * Replaces the legacy `Chip_Fluent_Forms_Webhook_Setup` class. The new class:
 *
 *   - Stores public keys inside the new option layout (global option or
 *     per-form fluentform_form_meta row) instead of a cross-cutting
 *     `fluent_form_chip_public_key` option.
 *   - Has two entry points: setup_for_global_settings() (after the global
 *     settings option is saved) and setup_for_form_settings() (after a
 *     per-form row is saved).
 *   - The previous CSF hook (csf_fluent_form_chip_save_before) is gone,
 *     so we drive the setup directly from the sanitize callbacks of the
 *     two new settings pages.
 *
 * @package CHIPForFluentForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chip_Fluent_Forms_Webhook_Setup — see file-level docblock above.
 *
 * Public API: get_public_key_for_form() (used by the processor's
 * refund_callback) and the two setup_for_* entry points (called by
 * the sanitize callbacks in chip-for-fluent-forms.php).
 */
class Chip_Fluent_Forms_Webhook_Setup {

	const WEBHOOK_TITLE = 'CHIP for Fluent Forms';

	/**
	 * Read the public key used to verify refund webhooks for a given form.
	 *
	 * Order: per-form public key (if the per-form refund-sync is on and a
	 * key was registered) → global public key → empty string.
	 *
	 * Called by Chip_Fluent_Forms_Purchase::refund_callback().
	 *
	 * @param int $form_id Fluent Forms form id.
	 * @return string PEM-encoded public key, or empty string.
	 */
	public static function get_public_key_for_form( $form_id ) {
		$form_id = (int) $form_id;

		if ( function_exists( 'wpFluent' ) && $form_id > 0 ) {
			$row = wpFluent()->table( 'fluentform_form_meta' )
				->where( 'form_id', $form_id )
				->where( 'meta_key', '_chip_payment_settings' ) // phpcs:ignore WordPress.DB.SlowDBQuery -- the fluentform_form_meta table is not a WordPress postmeta table, so the standard slow-query rule does not apply.
				->first();
			if ( $row ) {
				$value = maybe_unserialize( $row->value );
				if ( is_array( $value ) && 'yes' === ( $value['synchronize_refund'] ?? '' ) && ! empty( $value['public_key'] ) ) {
					return str_replace( '\n', "\n", (string) $value['public_key'] );
				}
			}
		}

		$global = get_option( 'fluent_form_chip_settings', array() );
		if ( is_array( $global ) && ! empty( $global['public_key'] ) ) {
			return str_replace( '\n', "\n", (string) $global['public_key'] );
		}

		// Pre-migration fallback: read the legacy option.
		$legacy = get_option( 'fluent_form_chip_public_key', array() );
		if ( is_array( $legacy ) && ! empty( $legacy['public-key'] ) ) {
			return str_replace( '\n', "\n", (string) $legacy['public-key'] );
		}
		if ( is_array( $legacy ) && ! empty( $legacy[ 'public-key-' . $form_id ] ) ) {
			return str_replace( '\n', "\n", (string) $legacy[ 'public-key-' . $form_id ] );
		}

		return '';
	}

	/**
	 * After the global settings option has been saved: if refund sync is on
	 * and we have credentials, ensure a CHIP webhook exists for the global
	 * callback URL and persist the public key into the global option.
	 *
	 * @param array $settings The sanitized global settings.
	 * @return void
	 */
	public static function setup_for_global_settings( $settings ) {
		if ( ! is_array( $settings ) || empty( $settings['synchronize_refund'] ) ) {
			return;
		}
		if ( empty( $settings['secret_key'] ) ) {
			return;
		}

		$public_key = self::ensure_webhook(
			(string) $settings['secret_key'],
			self::get_callback_url()
		);

		if ( '' === $public_key ) {
			return;
		}

		$settings['public_key'] = $public_key;
		update_option( 'fluent_form_chip_settings', $settings );
	}

	/**
	 * After a per-form settings row has been saved: if refund sync is on and
	 * the per-form secret key is set, ensure a CHIP webhook exists for the
	 * per-form callback URL and persist the public key into the per-form row.
	 *
	 * @param int   $form_id  Fluent Forms form id.
	 * @param array $settings The sanitized per-form settings.
	 * @return void
	 */
	public static function setup_for_form_settings( $form_id, $settings ) {
		$form_id = (int) $form_id;
		if ( $form_id <= 0 || ! is_array( $settings ) || empty( $settings['synchronize_refund'] ) ) {
			return;
		}
		if ( empty( $settings['secret_key'] ) ) {
			return;
		}

		$public_key = self::ensure_webhook(
			(string) $settings['secret_key'],
			self::get_callback_url()
		);

		if ( '' === $public_key ) {
			return;
		}

		$settings['public_key'] = $public_key;
		Chip_Fluent_Forms_Settings::save_form( $form_id, $settings );
	}

	/**
	 * Idempotent: look up the existing CHIP webhook for our callback URL and
	 * title; if absent, create one. Returns the public key (PEM), or '' on
	 * failure.
	 *
	 * @param string $secret_key   CHIP API secret key.
	 * @param string $callback_url The IPN callback URL.
	 * @return string PEM public key, or empty string.
	 */
	private static function ensure_webhook( $secret_key, $callback_url ) {
		if ( ! class_exists( 'Chip_Fluent_Forms_API' ) ) {
			return '';
		}

		$chip     = Chip_Fluent_Forms_API::get_instance( $secret_key, '' );
		$webhooks = $chip->get_webhooks();

		if ( is_wp_error( $webhooks ) || ! is_array( $webhooks ) || empty( $webhooks['results'] ) ) {
			return '';
		}

		$existing = null;
		foreach ( $webhooks['results'] as $hook ) {
			if ( isset( $hook['callback'] ) && $hook['callback'] === $callback_url ) {
				$existing = $hook;
				break;
			}
		}

		if ( $existing ) {
			return str_replace( '\n', "\n", (string) $existing['public_key'] );
		}

		$created = $chip->create_webhook(
			array(
				'title'      => self::WEBHOOK_TITLE,
				'all_events' => false,
				'events'     => array( 'payment.refunded' ),
				'callback'   => $callback_url,
			)
		);

		if ( is_wp_error( $created ) || ! is_array( $created ) || empty( $created['public_key'] ) ) {
			return '';
		}

		return str_replace( '\n', "\n", (string) $created['public_key'] );
	}

	/**
	 * The single CHIP callback URL for this site. There is no per-form
	 * override (CHIP fires all events to the same URL; we dispatch on
	 * submission_id inside the URL).
	 *
	 * @return string
	 */
	private static function get_callback_url() {
		return add_query_arg(
			array(
				'fluentform_payment_api_notify' => 1,
				'payment_method'                => 'chip',
			),
			site_url( 'index.php' )
		);
	}
}
