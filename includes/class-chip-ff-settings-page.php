<?php
/**
 * Options page controller for the CHIP settings framework.
 *
 * Renders the admin settings page and persists submitted values. This is a
 * focused replacement for the Codestar Framework options page: only the
 * behaviour this plugin depends on is implemented.
 *
 * @package CHIPForFluentForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'CHIP_FF_Settings_Page' ) ) {

	/**
	 * Renders and saves a single options page.
	 */
	class CHIP_FF_Settings_Page {

		/**
		 * Options slug. Also the option name in the database.
		 *
		 * @var string
		 */
		public $unique = '';

		/**
		 * Page arguments.
		 *
		 * @var array
		 */
		public $args = array();

		/**
		 * Registered sections for this page.
		 *
		 * @var array
		 */
		public $sections = array();

		/**
		 * Top-level sections (tabs) with their children attached.
		 *
		 * @var array
		 */
		public $tabs = array();

		/**
		 * Every field with an id, flattened across all sections.
		 *
		 * @var array
		 */
		public $fields = array();

		/**
		 * Current stored options.
		 *
		 * @var array
		 */
		public $options = array();

		/**
		 * Validation errors keyed by field id.
		 *
		 * @var array
		 */
		public $errors = array();

		/**
		 * Notice shown after a save, import or reset.
		 *
		 * @var string
		 */
		public $notice = '';

		/**
		 * The WordPress hook suffix of the rendered page.
		 *
		 * @var string
		 */
		public $page_hook = '';

		/**
		 * Constructor.
		 *
		 * @param string $slug Options slug.
		 */
		public function __construct( $slug ) {

			$this->unique   = $slug;
			$this->args     = CHIP_FF_Settings::$args[ $slug ];
			$this->sections = isset( CHIP_FF_Settings::$sections[ $slug ] ) && is_array( CHIP_FF_Settings::$sections[ $slug ] )
				? CHIP_FF_Settings::$sections[ $slug ]
				: array();
			$this->options  = get_option( $slug, array() );

			if ( ! is_array( $this->options ) ) {
				$this->options = array();
			}

			// With no sections there is no page to draw and nothing to save.
			// Returning early keeps a mis-timed construction from emitting
			// warnings or overwriting the option with an empty array.
			if ( empty( $this->sections ) ) {
				return;
			}

			$this->build_tabs();
			$this->fields = $this->collect_fields( $this->sections );

			add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
			add_action( 'admin_init', array( $this, 'maybe_save' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
			add_action( 'wp_ajax_chip-ff-export', array( $this, 'export_options' ) );

			if ( $this->args['save_defaults'] && empty( $this->options ) ) {
				$this->save_defaults();
			}
		}

		/**
		 * Groups sections into tabs and attaches children to their parent.
		 *
		 * @return void
		 */
		private function build_tabs() {

			$tabs = array();

			foreach ( $this->sections as $section ) {
				if ( empty( $section['parent'] ) ) {
					$tabs[ $section['id'] ]         = $section;
					$tabs[ $section['id'] ]['subs'] = array();
				}
			}

			foreach ( $this->sections as $section ) {
				if ( ! empty( $section['parent'] ) ) {
					if ( isset( $tabs[ $section['parent'] ] ) ) {
						$tabs[ $section['parent'] ]['subs'][] = $section;
					} else {
						// Orphan child: surface it as its own tab so its fields
						// are still reachable rather than silently dropped.
						$tabs[ $section['id'] ]         = $section;
						$tabs[ $section['id'] ]['subs'] = array();
					}
				}
			}

			$this->tabs = $tabs;
		}

		/**
		 * Flattens every field with an id across the given sections.
		 *
		 * @param array $sections Sections to walk.
		 * @return array
		 */
		private function collect_fields( $sections ) {

			$fields = array();

			foreach ( $sections as $section ) {
				if ( empty( $section['fields'] ) ) {
					continue;
				}

				foreach ( $section['fields'] as $field ) {
					if ( ! empty( $field['id'] ) ) {
						$fields[ $field['id'] ] = $field;
					}
				}
			}

			return $fields;
		}

		/**
		 * Resolves a field's default value.
		 *
		 * @param array $field Field definition.
		 * @return mixed
		 */
		public function get_default( $field ) {

			return isset( $field['default'] ) ? $field['default'] : '';
		}

		/**
		 * Persists defaults for fields that have none stored yet.
		 *
		 * Mirrors the original framework: on a brand new site the option is
		 * primed with every field's default so downstream reads never hit a
		 * missing key.
		 *
		 * @return void
		 */
		public function save_defaults() {

			$defaults = $this->options;
			$changed  = false;

			foreach ( $this->fields as $id => $field ) {
				if ( ! isset( $defaults[ $id ] ) ) {
					$defaults[ $id ] = $this->get_default( $field );
					$changed         = true;
				}
			}

			if ( ! $changed ) {
				return;
			}

			// Never write an empty array over an existing option: on a page
			// with no fields that would wipe the site's settings.
			if ( empty( $defaults ) && ! empty( $this->options ) ) {
				return;
			}

			update_option( $this->unique, $defaults );

			// Keep the local copy in step and let the previous framework's
			// filter signature keep working for any existing hook.
			$defaults = apply_filters( "chip_ff_{$this->unique}_save", $defaults, $this );

			$this->options = $defaults;
		}

		/**
		 * Registers the admin menu entry.
		 *
		 * @return void
		 */
		public function add_admin_menu() {

			$capability = $this->args['menu_capability'];

			if ( 'submenu' === $this->args['menu_type'] ) {
				$this->page_hook = add_submenu_page(
					$this->args['menu_parent'],
					$this->args['menu_title'],
					$this->args['menu_title'],
					$capability,
					$this->args['menu_slug'],
					array( $this, 'render' )
				);
			} else {
				$this->page_hook = add_menu_page(
					$this->args['menu_title'],
					$this->args['menu_title'],
					$capability,
					$this->args['menu_slug'],
					array( $this, 'render' ),
					$this->args['menu_icon'],
					$this->args['menu_position']
				);
			}
		}

		/**
		 * Enqueues the settings assets, but only on this page.
		 *
		 * @param string $hook_suffix Current admin page hook suffix.
		 * @return void
		 */
		public function enqueue_assets( $hook_suffix ) {

			if ( empty( $this->page_hook ) || $hook_suffix !== $this->page_hook ) {
				return;
			}

			$base = plugin_dir_url( FF_CHIP_FILE ) . 'includes/';

			wp_enqueue_style( 'chip-ff-settings', $base . 'assets/css/chip-ff-settings.css', array(), FF_CHIP_MODULE_VERSION );
			wp_enqueue_script( 'chip-ff-settings', $base . 'assets/js/chip-ff-settings.js', array( 'jquery' ), FF_CHIP_MODULE_VERSION, true );
		}

		/**
		 * Handles a submitted settings form.
		 *
		 * Requires both a valid nonce and the page capability, so a leaked
		 * nonce alone is not enough to write settings.
		 *
		 * @return void
		 */
		public function maybe_save() {

			$nonce_key = 'chip_ff_options_nonce_' . $this->unique;

			// The nonce field is present on every submission, including Reset,
			// Import and Export-adjacent buttons that do not post a [_nonce]
			// value. Guarding on it rather than on the options array is what
			// keeps those buttons working.
			if ( ! isset( $_POST[ $nonce_key ] ) ) {
				return;
			}

			$nonce = sanitize_text_field( wp_unslash( $_POST[ $nonce_key ] ) );

			if ( ! wp_verify_nonce( $nonce, 'chip_ff_options_nonce' ) ) {
				return;
			}

			if ( ! current_user_can( $this->args['menu_capability'] ) ) {
				return;
			}

			$raw = isset( $_POST[ $this->unique ] ) && is_array( $_POST[ $this->unique ] )
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every value is sanitised per field in sanitize_and_validate().
				? wp_unslash( $_POST[ $this->unique ] )
				: array();

			// With no registered fields there is nothing to write, and saving
			// an empty array would wipe the site's settings. This can only
			// happen if the page is constructed outside an admin request.
			if ( empty( $this->fields ) ) {
				return;
			}

			$transient = isset( $_POST['chip_ff_transient'] ) && is_array( $_POST['chip_ff_transient'] )
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each key is sanitised where it is read.
				? wp_unslash( $_POST['chip_ff_transient'] )
				: array();

			$importing  = false;
			$section_id = isset( $transient['section'] ) ? sanitize_text_field( $transient['section'] ) : '';

			$import_raw = isset( $_POST['chip_ff_import_data'] ) ? trim( wp_unslash( $_POST['chip_ff_import_data'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON payload; every value is sanitised per field in sanitize_and_validate().

			if ( '' !== $import_raw ) {
				$import_data = json_decode( $import_raw, true );

				if ( is_array( $import_data ) && ! empty( $import_data ) ) {
					$raw       = $import_data;
					$importing = true;
				} else {
					$this->notice = __( 'Import failed: not valid JSON.', 'chip-for-fluent-forms' );
					$this->errors = array( 'chip_ff_import' => true );

					return;
				}
			}

			if ( ! empty( $transient['reset'] ) ) {
				$data = array();
				foreach ( $this->fields as $id => $field ) {
					$data[ $id ] = $this->get_default( $field );
				}
				$this->notice = __( 'Default settings restored.', 'chip-for-fluent-forms' );
			} elseif ( ! empty( $transient['reset_section'] ) && ! empty( $section_id ) ) {
				$data    = $this->options;
				$section = $this->find_section( $section_id );

				if ( $section && ! empty( $section['fields'] ) ) {
					foreach ( $section['fields'] as $field ) {
						if ( ! empty( $field['id'] ) ) {
							$data[ $field['id'] ] = $this->get_default( $field );
						}
					}
				}

				$this->notice = __( 'Default settings restored.', 'chip-for-fluent-forms' );
			} else {
				$data = $this->sanitize_and_validate( $raw, $importing );
			}

			if ( ! empty( $this->errors ) ) {
				$this->notice = __( 'Some settings were invalid and kept their previous value.', 'chip-for-fluent-forms' );
			} elseif ( ! $importing && empty( $transient['reset'] ) && empty( $transient['reset_section'] ) ) {
				// The previous framework only confirmed imports and resets.
				// Confirming a plain save too costs nothing and removes the
				// "did that work?" doubt on a reload.
				$this->notice = __( 'Settings saved.', 'chip-for-fluent-forms' );
			}

			$data = apply_filters( "chip_ff_{$this->unique}_save", $data, $this );

			do_action( "chip_ff_{$this->unique}_save_before", $data, $this );

			update_option( $this->unique, $data );

			do_action( "chip_ff_{$this->unique}_saved", $data, $this );

			$this->options = $data;

			if ( $importing && empty( $this->errors ) ) {
				$this->notice = __( 'Settings successfully imported.', 'chip-for-fluent-forms' );
			}
		}

		/**
		 * Sanitises and validates each submitted field value.
		 *
		 * A value that fails validation reverts to what is already stored, so
		 * a bad submission can never silently overwrite a good setting.
		 *
		 * @param array $raw       Raw submitted values.
		 * @param bool  $importing Whether the values came from an import.
		 * @return array
		 */
		private function sanitize_and_validate( $raw, $importing ) {

			$data = array();

			foreach ( $this->fields as $id => $field ) {
				$value = isset( $raw[ $id ] ) ? $raw[ $id ] : '';

				if ( ! $importing && ! is_array( $value ) ) {
					$value = (string) $value;
				}

				if ( isset( $field['sanitize'] ) && is_callable( $field['sanitize'] ) ) {
					$data[ $id ] = call_user_func( $field['sanitize'], $value );
				} elseif ( is_array( $value ) ) {
					$data[ $id ] = wp_kses_post_deep( $value );
				} else {
					$data[ $id ] = wp_kses_post( $value );
				}

				if ( isset( $field['validate'] ) && is_callable( $field['validate'] ) ) {
					$has_validated = call_user_func( $field['validate'], $value );

					if ( ! empty( $has_validated ) ) {
						$data[ $id ]         = isset( $this->options[ $id ] ) ? $this->options[ $id ] : '';
						$this->errors[ $id ] = $has_validated;
					}
				}
			}

			return $data;
		}

		/**
		 * Finds a section by id, including child sections.
		 *
		 * @param string $id Section id.
		 * @return array|null
		 */
		private function find_section( $id ) {

			foreach ( $this->tabs as $tab ) {
				if ( $tab['id'] === $id ) {
					return $tab;
				}

				foreach ( $tab['subs'] as $sub ) {
					if ( $sub['id'] === $id ) {
						return $sub;
					}
				}
			}

			return null;
		}

		/**
		 * Streams the stored options as a JSON download.
		 *
		 * The payload is the option array verbatim, so files exported by the
		 * previous framework stay importable both ways.
		 *
		 * @return void
		 */
		public function export_options() {

			$nonce  = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
			$unique = isset( $_GET['unique'] ) ? sanitize_text_field( wp_unslash( $_GET['unique'] ) ) : '';

			if ( ! wp_verify_nonce( $nonce, 'chip_ff_backup_nonce' ) ) {
				wp_die( esc_html__( 'Error: Invalid nonce verification.', 'chip-for-fluent-forms' ) );
			}

			if ( $unique !== $this->unique ) {
				wp_die( esc_html__( 'Error: Invalid key.', 'chip-for-fluent-forms' ) );
			}

			if ( ! current_user_can( $this->args['menu_capability'] ) ) {
				wp_die( esc_html__( 'Error: You are not allowed to export these settings.', 'chip-for-fluent-forms' ) );
			}

			$content = wp_json_encode( get_option( $this->unique ) );

			header( 'Content-Description: File Transfer' );
			header( 'Content-Type: application/json' );
			header( 'Content-Disposition: attachment; filename="' . $this->unique . '.json"' );
			header( 'Expires: 0' );
			header( 'Cache-Control: must-revalidate' );
			header( 'Pragma: public' );
			header( 'Content-Length: ' . strlen( $content ) );

			echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download, not HTML.

			exit;
		}

		/**
		 * Renders the options page.
		 *
		 * @return void
		 */
		public function render() {

			if ( ! current_user_can( $this->args['menu_capability'] ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'chip-for-fluent-forms' ) );
			}

			$has_nav = count( $this->tabs ) > 1;
			$first   = true;

			echo '<div class="chip-ff-settings">';
			echo '<div class="chip-ff-container">';
			echo '<form method="post" action="" enctype="multipart/form-data" id="chip-ff-form" autocomplete="off" novalidate="novalidate">';

			wp_nonce_field( 'chip_ff_options_nonce', 'chip_ff_options_nonce_' . $this->unique );

			echo '<input type="hidden" class="chip-ff-section-id" name="chip_ff_transient[section]" value="1">';

			echo '<div class="chip-ff-header">';
			echo '<div class="chip-ff-header-inner">';
			echo '<div class="chip-ff-header-left"><h1>' . esc_html( $this->args['framework_title'] ) . '</h1></div>';
			echo '<div class="chip-ff-header-right">';

			$notice_class = ( ! empty( $this->notice ) ) ? ' chip-ff-form-show' : '';
			echo '<div class="chip-ff-form-result chip-ff-form-success' . esc_attr( $notice_class ) . '">' . esc_html( $this->notice ) . '</div>';

			echo '<div class="chip-ff-buttons">';
			echo '<button type="submit" name="' . esc_attr( $this->unique ) . '[_nonce][save]" class="button button-primary chip-ff-save" value="save">' . esc_html__( 'Save', 'chip-for-fluent-forms' ) . '</button>';
			echo '<button type="submit" name="chip_ff_transient[reset]" value="reset" class="button chip-ff-warning-primary chip-ff-confirm" data-confirm="' . esc_attr__( 'Are you sure you want to reset all settings to default values?', 'chip-for-fluent-forms' ) . '">' . esc_html__( 'Reset', 'chip-for-fluent-forms' ) . '</button>';
			echo '</div>';

			echo '</div>';
			echo '</div>';
			echo '</div>';

			echo '<div class="chip-ff-wrapper">';

			if ( $has_nav ) {
				echo '<div class="chip-ff-nav">';
				echo '<ul>';

				foreach ( $this->tabs as $tab ) {
					$tab_id   = sanitize_title( $tab['title'] );
					$tab_icon = ( ! empty( $tab['icon'] ) ) ? '<i class="chip-ff-tab-icon ' . esc_attr( $tab['icon'] ) . '"></i>' : '';

					if ( ! empty( $tab['subs'] ) ) {
						// A tab that has children but no fields of its own has no
						// section of its own either, so its link must point at the
						// first child. Pointing at itself would select nothing and
						// leave the previously visible section on screen.
						$tab_target = ! empty( $tab['fields'] ) ? $tab['id'] : $tab['subs'][0]['id'];

						echo '<li class="chip-ff-tab-item">';
						echo '<a href="#tab=' . esc_attr( $tab_id ) . '" data-tab-id="' . esc_attr( $tab_id ) . '" data-section-id="' . esc_attr( $tab_target ) . '" class="chip-ff-arrow">' . wp_kses_post( $tab_icon . $tab['title'] ) . '</a>';
						echo '<ul>';

						foreach ( $tab['subs'] as $sub ) {
							$sub_id   = $tab_id . '/' . sanitize_title( $sub['title'] );
							$sub_icon = ( ! empty( $sub['icon'] ) ) ? '<i class="chip-ff-tab-icon ' . esc_attr( $sub['icon'] ) . '"></i>' : '';

							echo '<li><a href="#tab=' . esc_attr( $sub_id ) . '" data-tab-id="' . esc_attr( $sub_id ) . '" data-section-id="' . esc_attr( $sub['id'] ) . '">' . wp_kses_post( $sub_icon . $sub['title'] ) . '</a></li>';
						}

						echo '</ul>';
						echo '</li>';
					} else {
						echo '<li class="chip-ff-tab-item"><a href="#tab=' . esc_attr( $tab_id ) . '" data-tab-id="' . esc_attr( $tab_id ) . '" data-section-id="' . esc_attr( $tab['id'] ) . '">' . wp_kses_post( $tab_icon . $tab['title'] ) . '</a></li>';
					}
				}

				echo '</ul>';
				echo '</div>';
			}

			echo '<div class="chip-ff-content">';
			echo '<div class="chip-ff-sections">';

			foreach ( $this->tabs as $tab ) {
				$tab_id = sanitize_title( $tab['title'] );

				if ( ! empty( $tab['subs'] ) ) {
					// Render the tab's own fields first when it has any, so a
					// section that both holds fields and parents children does
					// not lose them.
					if ( ! empty( $tab['fields'] ) ) {
						$this->render_section( $tab, $tab_id, $first );
						$first = false;
					}

					foreach ( $tab['subs'] as $sub ) {
						$this->render_section( $sub, $tab_id . '/' . sanitize_title( $sub['title'] ), $first );
						$first = false;
					}
				} else {
					$this->render_section( $tab, $tab_id, $first );
					$first = false;
				}
			}

			echo '</div>';
			echo '</div>';
			echo '<div class="clear"></div>';
			echo '</div>';

			echo '<div class="chip-ff-footer">';
			echo '<div class="chip-ff-buttons">';
			echo '<button type="submit" name="' . esc_attr( $this->unique ) . '[_nonce][save]" class="button button-primary chip-ff-save" value="save">' . esc_html__( 'Save', 'chip-for-fluent-forms' ) . '</button>';
			echo '</div>';
			echo '<div class="chip-ff-footer-text">' . wp_kses_post( $this->args['footer_text'] ) . '</div>';
			echo '</div>';

			echo '</form>';
			echo '</div>';
			echo '</div>';
		}

		/**
		 * Renders one section and its fields.
		 *
		 * @param array  $section  Section definition.
		 * @param string $tab_id   Tab id used for the anchor.
		 * @param bool   $onload   Whether this section is the initially visible one.
		 * @return void
		 */
		private function render_section( $section, $tab_id, $onload ) {

			$icon_class = ( ! empty( $section['icon'] ) ) ? ' chip-ff-section-has-icon' : '';

			// The div id is the section's own id so the nav can target it
			// directly; child tab ids contain a slash and cannot be used as
			// an HTML id or a CSS selector.
			$dom_id = ! empty( $section['id'] ) ? $section['id'] : $tab_id;

			echo '<div id="' . esc_attr( $dom_id ) . '" class="chip-ff-section' . esc_attr( $icon_class ) . ( $onload ? ' chip-ff-onload' : '' ) . '">';

			if ( ! empty( $section['title'] ) ) {
				$icon = ( ! empty( $section['icon'] ) ) ? '<i class="chip-ff-section-icon ' . esc_attr( $section['icon'] ) . '"></i>' : '';
				echo '<div class="chip-ff-section-title">' . wp_kses_post( $icon . $section['title'] ) . '</div>';
			}

			if ( ! empty( $section['description'] ) ) {
				echo '<div class="chip-ff-section-desc">' . wp_kses_post( $section['description'] ) . '</div>';
			}

			if ( ! empty( $section['fields'] ) ) {
				foreach ( $section['fields'] as $field ) {
					if ( empty( $field['type'] ) ) {
						continue;
					}

					$field_id = ! empty( $field['id'] ) ? $field['id'] : '';
					$value    = ( '' !== $field_id && isset( $this->options[ $field_id ] ) ) ? $this->options[ $field_id ] : $this->get_default( $field );

					if ( '' !== $field_id && isset( $this->errors[ $field_id ] ) ) {
						$field['_error'] = $this->errors[ $field_id ];
					}

					$this->render_field( $field, $value );
				}
			}

			echo '<div class="clear"></div>';
			echo '</div>';
		}

		/**
		 * Renders a single field, including its wrapper, title and help text.
		 *
		 * @param array $field Field definition.
		 * @param mixed $value Current value.
		 * @return void
		 */
		private function render_field( $field, $value ) {

			$type     = $field['type'];
			$field_id = ! empty( $field['id'] ) ? $field['id'] : '';
			$classes  = ' chip-ff-field-' . $type;
			$depend   = '';
			$visible  = '';

			if ( ! empty( $field['class'] ) ) {
				$classes .= ' ' . $field['class'];
			}

			// The backup field renders its own layout and has no title column.
			if ( in_array( $type, array( 'subheading', 'notice' ), true ) ) {
				$classes .= ' chip-ff-field-full';
			}

			if ( ! empty( $field['dependency'] ) ) {
				$dependency = $field['dependency'];

				if ( is_array( $dependency[0] ) ) {
					$controller = implode( '|', array_column( $dependency, 0 ) );
					$condition  = implode( '|', array_column( $dependency, 1 ) );
					$expect     = implode( '|', array_column( $dependency, 2 ) );
				} else {
					$controller = isset( $dependency[0] ) ? $dependency[0] : '';
					$condition  = isset( $dependency[1] ) ? $dependency[1] : '';
					$expect     = isset( $dependency[2] ) ? $dependency[2] : '';
				}

				$depend .= ' data-controller="' . esc_attr( $controller ) . '"';
				$depend .= ' data-condition="' . esc_attr( $condition ) . '"';
				$depend .= ' data-value="' . esc_attr( $expect ) . '"';
				$visible = ' chip-ff-depend-hidden';
			}

			echo '<div class="chip-ff-field' . esc_attr( $classes ) . esc_attr( $visible ) . '"' . $depend . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attribute string built with esc_attr() above.

			if ( in_array( $type, array( 'subheading', 'notice' ), true ) ) {
				$this->render_field_control( $field, $value );
			} else {
				if ( ! empty( $field['title'] ) ) {
					echo '<div class="chip-ff-title"><h4>' . wp_kses_post( $field['title'] ) . '</h4></div>';
					echo '<div class="chip-ff-fieldset">';
				}

				$this->render_field_control( $field, $value );

				if ( ! empty( $field['title'] ) ) {
					echo '</div>';
				}
			}

			echo '<div class="clear"></div>';
			echo '</div>';
		}

		/**
		 * Builds an attribute string from already-sanitised values.
		 *
		 * Values are passed through esc_attr() here, so the returned string is
		 * safe to echo directly. phpcs cannot follow string concatenation
		 * across statements, which is why this lives in one function.
		 *
		 * @param array $attributes Attribute name => value pairs.
		 * @return string
		 */
		private function attributes( $attributes ) {

			$output = '';

			foreach ( $attributes as $key => $value ) {
				if ( '' === $value || null === $value ) {
					continue;
				}

				$output .= ' ' . esc_attr( $key ) . '="' . esc_attr( $value ) . '"';
			}

			return $output;
		}

		/**
		 * Renders the input markup for a field type.
		 *
		 * @param array $field Field definition.
		 * @param mixed $value Current value.
		 * @return void
		 */
		private function render_field_control( $field, $value ) {

			$field_id = ! empty( $field['id'] ) ? $field['id'] : '';
			$name     = '' !== $field_id ? $this->unique . '[' . $field_id . ']' : '';

			$shared = array(
				'name'           => $name,
				'data-depend-id' => $field_id,
				'placeholder'    => ! empty( $field['placeholder'] ) ? $field['placeholder'] : '',
			);

			switch ( $field['type'] ) {

				case 'subheading':
					echo ( ! empty( $field['content'] ) ) ? '<h4 class="chip-ff-subheading">' . wp_kses_post( $field['content'] ) . '</h4>' : '';
					break;

				case 'notice':
					$style = ( ! empty( $field['style'] ) ) ? $field['style'] : 'normal';
					echo ( ! empty( $field['content'] ) ) ? '<div class="chip-ff-notice chip-ff-notice-' . esc_attr( $style ) . '">' . wp_kses_post( $field['content'] ) . '</div>' : '';
					break;

				case 'text':
					$attributes = array_merge(
						$shared,
						array(
							'type'  => ! empty( $field['attributes']['type'] ) ? $field['attributes']['type'] : 'text',
							'value' => $value,
						)
					);

					echo '<input' . $this->attributes( $attributes ) . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes() escapes every key and value with esc_attr().
					break;

				case 'number':
					$attributes = array_merge(
						$shared,
						array(
							'type'  => 'number',
							'value' => $value,
							'min'   => 'any',
							'max'   => 'any',
							'step'  => 'any',
						)
					);

					echo '<div class="chip-ff-wrap">';
					echo '<input' . $this->attributes( $attributes ) . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes() escapes every key and value with esc_attr().
					echo '</div>';
					break;

				case 'switcher':
					$active   = ( ! empty( $value ) ) ? ' chip-ff--active' : '';
					$text_on  = ( ! empty( $field['text_on'] ) ) ? $field['text_on'] : __( 'On', 'chip-for-fluent-forms' );
					$text_off = ( ! empty( $field['text_off'] ) ) ? $field['text_off'] : __( 'Off', 'chip-for-fluent-forms' );

					$attributes = array_merge(
						$shared,
						array(
							'type'  => 'hidden',
							'value' => $value,
						)
					);

					echo '<div class="chip-ff--switcher' . esc_attr( $active ) . '">';
					echo '<span class="chip-ff--on">' . esc_html( $text_on ) . '</span>';
					echo '<span class="chip-ff--off">' . esc_html( $text_off ) . '</span>';
					echo '<span class="chip-ff--ball"></span>';
					echo '<input' . $this->attributes( $attributes ) . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes() escapes every key and value with esc_attr().
					echo '</div>';
					break;

				case 'backup':
					$nonce  = wp_create_nonce( 'chip_ff_backup_nonce' );
					$export = add_query_arg(
						array(
							'action' => 'chip-ff-export',
							'unique' => $this->unique,
							'nonce'  => $nonce,
						),
						admin_url( 'admin-ajax.php' )
					);

					echo '<textarea name="chip_ff_import_data" class="chip-ff-import-data" rows="6" placeholder="' . esc_attr__( 'Paste an exported settings file here to restore it.', 'chip-for-fluent-forms' ) . '"></textarea>';
					echo '<button type="submit" class="button button-primary chip-ff-import">' . esc_html__( 'Import', 'chip-for-fluent-forms' ) . '</button>';
					echo '<hr />';
					echo '<textarea readonly="readonly" class="chip-ff-export-data" rows="6">' . esc_textarea( wp_json_encode( $this->options ) ) . '</textarea>';
					echo '<a href="' . esc_url( $export ) . '" class="button button-primary chip-ff-export" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Export &amp; Download', 'chip-for-fluent-forms' ) . '</a>';
					break;
			}

			if ( ! empty( $field['after'] ) ) {
				echo '<div class="chip-ff-after-text">' . wp_kses_post( $field['after'] ) . '</div>';
			}

			if ( ! empty( $field['desc'] ) ) {
				echo '<div class="clear"></div><div class="chip-ff-desc-text">' . wp_kses_post( $field['desc'] ) . '</div>';
			}

			if ( ! empty( $field['help'] ) ) {
				echo '<div class="chip-ff-help"><span class="chip-ff-help-text">' . wp_kses_post( $field['help'] ) . '</span><i class="dashicons dashicons-editor-help"></i></div>';
			}

			if ( ! empty( $field['_error'] ) ) {
				echo '<div class="chip-ff-error-text">' . wp_kses_post( is_string( $field['_error'] ) ? $field['_error'] : __( 'Invalid value.', 'chip-for-fluent-forms' ) ) . '</div>';
			}
		}
	}
}
