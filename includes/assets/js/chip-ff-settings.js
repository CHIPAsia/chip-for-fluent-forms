/**
 * CHIP settings page behaviour.
 *
 * Covers the three interactions the settings page depends on: the switcher
 * toggle, tab navigation, and conditional field visibility. Dependency
 * matching reproduces the semantics the previous framework used, including
 * boolean coercion, so existing dependency rules keep working unchanged.
 */
( function ( $ ) {
	'use strict';

	/**
	 * Coerces a value the way the previous framework did.
	 *
	 * This is why a rule written as `array( 'some-switcher', '==', 'true' )`
	 * still matches a stored value of `1`.
	 *
	 * @param {*} value Value to coerce.
	 * @return {boolean} Coerced value.
	 */
	function toBoolean( value ) {
		switch ( value ) {
			case true:
			case 'true':
			case 1:
			case '1':
				return true;
			case null:
			case false:
			case 'false':
			case 0:
			case '0':
				return false;
		}

		return Boolean( value );
	}

	/**
	 * Reads the current value of a controlling field.
	 *
	 * @param {jQuery} $control Controlling field element.
	 * @return {*} Field value.
	 */
	function controlValue( $control ) {
		if ( ! $control.length ) {
			return undefined;
		}

		if ( $control.is( ':checkbox, :radio' ) ) {
			return $control.is( ':checked' );
		}

		return $control.val();
	}

	/**
	 * Evaluates one dependency condition.
	 *
	 * @param {string} condition Comparison operator.
	 * @param {string} expected  Expected value.
	 * @param {*}      actual    Actual value.
	 * @return {boolean} Whether the condition holds.
	 */
	function evalCondition( condition, expected, actual ) {
		if ( condition === '==' ) {
			return toBoolean( expected ) === toBoolean( actual );
		}

		if ( condition === '!=' ) {
			return toBoolean( expected ) !== toBoolean( actual );
		}

		if ( condition === '>=' ) {
			return Number( actual ) >= Number( expected );
		}

		if ( condition === '<=' ) {
			return Number( actual ) <= Number( expected );
		}

		if ( condition === '>' ) {
			return Number( actual ) > Number( expected );
		}

		if ( condition === '<' ) {
			return Number( actual ) < Number( expected );
		}

		return false;
	}

	/**
	 * Shows or hides fields according to their dependency rules.
	 *
	 * @param {jQuery} $scope Scope to evaluate within.
	 * @return {void}
	 */
	function applyDependencies( $scope ) {
		$scope.find( '.chip-ff-field[data-controller]' ).each( function () {
			var $field      = $( this ),
				controllers = String( $field.data( 'controller' ) ).split( '|' ),
				conditions  = String( $field.data( 'condition' ) ).split( '|' ),
				values      = String( $field.data( 'value' ) ).split( '|' ),
				visible     = true;

			$.each( controllers, function ( index, controller ) {
				if ( ! visible ) {
					return;
				}

				var expected  = values[ index ] || '',
					condition = conditions[ index ] || conditions[ 0 ],
					$control  = $scope.find( '[data-depend-id="' + controller + '"]' );

				if ( ! $control.length ) {
					$control = $( document ).find( '[data-depend-id="' + controller + '"]' );
				}

				var actual = controlValue( $control );

				if ( actual === undefined || ! evalCondition( condition, expected, actual ) ) {
					visible = false;
				}
			} );

			$field.toggleClass( 'chip-ff-depend-hidden', ! visible );
		} );
	}

	/**
	 * Activates a tab and shows its section.
	 *
	 * Sections are matched through each link's own data-section-id rather
	 * than by rebuilding a CSS selector, because child section tab ids
	 * contain a slash that a selector cannot express reliably.
	 *
	 * @param {string} tabId Tab id to activate.
	 * @return {void}
	 */
	function activateTab( tabId ) {
		var $nav     = $( '.chip-ff-nav' ),
			$link    = $nav.find( 'a[data-tab-id="' + tabId + '"]' ),
			$section = $link.length ? $( '#' + $link.data( 'section-id' ) ) : $();

		$nav.find( 'a' ).removeClass( 'chip-ff-active' );
		$link.addClass( 'chip-ff-active' );

		$( '.chip-ff-section' ).removeClass( 'chip-ff-onload' );

		if ( $section.length ) {
			$section.addClass( 'chip-ff-onload' );
		} else {
			$( '.chip-ff-section' ).first().addClass( 'chip-ff-onload' );
		}

		applyDependencies( $( '.chip-ff-settings' ) );
	}

	/**
	 * Reads the tab id out of the URL hash.
	 *
	 * @return {string} Tab id, or an empty string.
	 */
	function tabFromHash() {
		var hash = window.location.hash;

		if ( hash.indexOf( '#tab=' ) === 0 ) {
			return hash.substring( 5 );
		}

		return '';
	}

	$( function () {
		var $settings = $( '.chip-ff-settings' );

		if ( ! $settings.length ) {
			return;
		}

		// Switcher toggle.
		$settings.on( 'click', '.chip-ff--switcher', function () {
			var $switcher = $( this ),
				$input    = $switcher.find( 'input' ),
				value     = 0;

			if ( $switcher.hasClass( 'chip-ff--active' ) ) {
				$switcher.removeClass( 'chip-ff--active' );
			} else {
				value = 1;
				$switcher.addClass( 'chip-ff--active' );
			}

			$input.val( value ).trigger( 'change' );
		} );

		// Tab navigation.
		$settings.on( 'click', '.chip-ff-nav a', function ( event ) {
			event.preventDefault();
			activateTab( $( this ).data( 'tab-id' ) );
		} );

		// Update the hidden section id so the framework knows which section
		// was on screen when the form was submitted.
		$settings.on( 'click', '.chip-ff-nav a', function () {
			var index = $( '.chip-ff-nav a' ).index( $( this ) ) + 1;
			$settings.find( '.chip-ff-section-id' ).val( index );
		} );

		// Dependency re-evaluation on any control change.
		$settings.on( 'change keyup', '[data-depend-id]', function () {
			applyDependencies( $settings );
		} );

		// Confirm destructive buttons.
		$settings.on( 'click', '.chip-ff-confirm', function ( event ) {
			if ( ! window.confirm( $( this ).data( 'confirm' ) ) ) {
				event.preventDefault();
			}
		} );

		var initial = tabFromHash();

		if ( initial && $( '.chip-ff-nav a[data-tab-id="' + initial + '"]' ).length ) {
			activateTab( initial );
		} else {
			applyDependencies( $settings );
		}
	} );
}( jQuery ) );
