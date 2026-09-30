<?php
/**
 * AzarPixel WP Admin UI Kit — settings page renderer.
 *
 * Drop this file (plus class-apx-icons.php, assets/admin-ui.css, assets/admin-ui.js)
 * into any plugin, describe the page as a config array, and the whole shell —
 * sidebar, topbar, panels, sticky save bar, modal, REST save endpoint — is built
 * for you with the kit's markup contract.
 *
 * RENAME NOTE: if two plugins on the same site both ship this kit, prefix the
 * class names (APX_Admin_Page -> MYPLUGIN_Admin_Page) to avoid collisions. The
 * CSS classes can stay `apx-` — they are scoped to `.apx-wrap` on your own page.
 *
 * ---------------------------------------------------------------------------
 * MINIMAL USAGE
 *
 *   add_action( 'plugins_loaded', function () {
 *       new APX_Admin_Page( array(
 *           'slug'        => 'my-plugin',
 *           'menu_title'  => 'My Plugin',
 *           'page_title'  => 'My Plugin Settings',
 *           'brand'       => 'My Plugin',
 *           'version'     => MYPLUGIN_VERSION,
 *           'assets_url'  => plugin_dir_url( __FILE__ ) . '../assets/',
 *           'option_name' => 'myplugin_settings',   // single serialized option
 *           'rest_ns'     => 'myplugin/v1',
 *           'nav'         => array(
 *               array( 'group' => 'Setup', 'items' => array(
 *                   array( 'key' => 'general', 'label' => 'General', 'icon' => 'gear' ),
 *               ) ),
 *           ),
 *           'panels'      => array(
 *               'general' => array(
 *                   'title'    => 'General',
 *                   'desc'     => 'Core behaviour of the plugin.',
 *                   'sections' => array( array(
 *                       'heading' => 'Basics',
 *                       'fields'  => array(
 *                           array( 'type' => 'toggle', 'name' => 'enabled', 'label' => 'Enable the plugin',
 *                                  'desc' => 'Master switch.', 'dep_master' => 'core' ),
 *                           array( 'type' => 'select', 'name' => 'mode', 'label' => 'Mode', 'dep' => 'core',
 *                                  'options' => array( 'fast' => 'Fast', 'safe' => 'Safe' ) ),
 *                           array( 'type' => 'list', 'name' => 'excludes', 'label' => 'Excluded URLs',
 *                                  'dep' => 'core', 'help' => 'One per line.' ),
 *                       ),
 *                   ) ),
 *               ),
 *           ),
 *       ) );
 *   } );
 *
 * FIELD TYPES: toggle, text, password, number, select, list, html, actions
 * ---------------------------------------------------------------------------
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class APX_Admin_Page {

	/** @var array */
	protected $cfg;

	public function __construct( array $cfg ) {
		$this->cfg = wp_parse_args( $cfg, array(
			'slug'        => 'apx-plugin',
			'menu_title'  => 'Plugin',
			'page_title'  => 'Plugin Settings',
			'brand'       => 'Plugin',
			'version'     => '1.0.0',
			'capability'  => 'manage_options',
			'menu_icon'   => 'dashicons-admin-generic',
			'position'    => 81,
			'assets_url'  => '',
			'option_name' => 'apx_settings',
			'defaults'    => array(),
			'rest_ns'     => 'apx/v1',
			'help_html'   => '',
			'nav'         => array(),
			'panels'      => array(),
		) );

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest' ) );
	}

	/* ── settings storage ────────────────────────────────────────────── */

	public function get_all() {
		$saved = get_option( $this->cfg['option_name'], array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $this->cfg['defaults'] );
	}

	public function get( $key, $fallback = '' ) {
		$all = $this->get_all();
		return isset( $all[ $key ] ) ? $all[ $key ] : $fallback;
	}

	/* ── menu + assets ───────────────────────────────────────────────── */

	public function register_menu() {
		$hook = add_menu_page(
			$this->cfg['page_title'],
			$this->cfg['menu_title'],
			$this->cfg['capability'],
			$this->cfg['slug'],
			array( $this, 'render' ),
			$this->cfg['menu_icon'],
			$this->cfg['position']
		);
		add_action( 'admin_print_scripts-' . $hook, array( $this, 'enqueue' ) );
	}

	public function enqueue() {
		$v = $this->cfg['version'];
		wp_enqueue_style( 'apx-admin-ui', $this->cfg['assets_url'] . 'admin-ui.css', array(), $v );
		wp_enqueue_script( 'apx-admin-ui', $this->cfg['assets_url'] . 'admin-ui.js', array(), $v, true );
		wp_add_inline_script( 'apx-admin-ui', 'window.APX_UI = ' . wp_json_encode( array(
			'slug'      => $this->cfg['slug'],
			'restUrl'   => esc_url_raw( rest_url( $this->cfg['rest_ns'] ) ),
			'restNonce' => wp_create_nonce( 'wp_rest' ),
			'i18n'      => array(
				'unsavedOne'  => __( 'You have 1 unsaved change.', 'smart-login' ),
				/* translators: %d: number of unsaved changes */
				'unsavedMany' => __( 'You have %d unsaved changes.', 'smart-login' ),
				'saved'       => __( 'Settings saved.', 'smart-login' ),
				'saveFailed'  => __( 'Could not save — please try again.', 'smart-login' ),
				'discarded'   => __( 'Changes discarded.', 'smart-login' ),
				'editList'    => __( 'Edit list', 'smart-login' ),
				/* translators: %d: number of entries in the list */
				'editListOne' => __( 'Edit list (1 entry)', 'smart-login' ),
				'editListMany' => __( 'Edit list (%d entries)', 'smart-login' ),
			),
		) ) . ';', 'before' );
	}

	/* ── REST ────────────────────────────────────────────────────────── */

	public function register_rest() {
		register_rest_route( $this->cfg['rest_ns'], '/settings', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_get' ),
				'permission_callback' => array( $this, 'rest_permission' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_save' ),
				'permission_callback' => array( $this, 'rest_permission' ),
			),
		) );
	}

	public function rest_permission() {
		return current_user_can( $this->cfg['capability'] );
	}

	public function rest_get() {
		return rest_ensure_response( $this->get_all() );
	}

	/**
	 * Accepts a flat { name: value } patch of ONLY the changed fields and
	 * merges it in. Unknown keys are dropped; values are sanitised per the
	 * declared field type.
	 */
	public function rest_save( WP_REST_Request $req ) {
		$patch  = (array) $req->get_json_params();
		$schema = $this->field_index();
		$all    = $this->get_all();

		foreach ( $patch as $name => $value ) {
			if ( ! isset( $schema[ $name ] ) ) { continue; }
			$all[ $name ] = $this->sanitize( $value, $schema[ $name ] );
		}

		update_option( $this->cfg['option_name'], $all );

		/**
		 * Fires after the settings page saves. Use it to flush caches,
		 * rewrite config files, reschedule cron, etc.
		 */
		do_action( 'apx_settings_saved', $all, array_keys( $patch ), $this->cfg['slug'] );

		return rest_ensure_response( array( 'saved' => true, 'settings' => $all ) );
	}

	protected function sanitize( $value, array $field ) {
		switch ( $field['type'] ) {
			case 'toggle':
				return (int) $value === 1 || '1' === $value ? 1 : 0;
			case 'number':
				$n = (int) $value;
				if ( isset( $field['min'] ) ) { $n = max( (int) $field['min'], $n ); }
				if ( isset( $field['max'] ) ) { $n = min( (int) $field['max'], $n ); }
				return $n;
			case 'select':
				$allowed = array_keys( (array) $field['options'] );
				return in_array( $value, $allowed, true ) ? $value : reset( $allowed );
			case 'list':
				$lines = array_filter( array_map( 'trim', preg_split( '/\R/', (string) $value ) ) );
				return implode( "\n", array_map( 'sanitize_text_field', $lines ) );
			case 'password':
			case 'text':
			default:
				return sanitize_text_field( (string) $value );
		}
	}

	/** Flat map of field name => field config, across every panel. */
	protected function field_index() {
		$out = array();
		foreach ( $this->cfg['panels'] as $panel ) {
			foreach ( (array) $panel['sections'] as $section ) {
				foreach ( (array) $section['fields'] as $field ) {
					if ( ! empty( $field['name'] ) ) {
						$out[ $field['name'] ] = wp_parse_args( $field, array( 'type' => 'text' ) );
					}
				}
			}
		}
		return $out;
	}

	/* ── rendering ───────────────────────────────────────────────────── */

	public function render() {
		if ( ! current_user_can( $this->cfg['capability'] ) ) { return; }
		$s     = $this->get_all();
		$first = array_key_first( $this->cfg['panels'] );

		APX_Icons::sprite();
		?>
		<div class="apx-wrap">
			<div class="apx-app">

				<aside class="apx-sidebar">
					<div class="apx-brand">
						<div class="apx-brand__mark"><?php echo APX_Icons::icon( 'zap' ); // phpcs:ignore ?></div>
						<div>
							<div class="apx-brand__name"><?php echo esc_html( $this->cfg['brand'] ); ?></div>
							<div class="apx-brand__meta">v<?php echo esc_html( $this->cfg['version'] ); ?></div>
						</div>
					</div>

					<nav class="apx-nav">
						<?php foreach ( $this->cfg['nav'] as $group ) : ?>
							<div class="apx-nav__group">
								<?php if ( ! empty( $group['group'] ) ) : ?>
									<div class="apx-nav__group-label"><?php echo esc_html( $group['group'] ); ?></div>
								<?php endif; ?>
								<?php foreach ( $group['items'] as $item ) : ?>
									<a href="#<?php echo esc_attr( $item['key'] ); ?>"
									   class="apx-nav__item<?php echo $item['key'] === $first ? ' active' : ''; ?>"
									   data-apx-tab="<?php echo esc_attr( $item['key'] ); ?>">
										<?php echo APX_Icons::icon( isset( $item['icon'] ) ? $item['icon'] : 'gear' ); // phpcs:ignore ?>
										<span><?php echo esc_html( $item['label'] ); ?></span>
									</a>
								<?php endforeach; ?>
							</div>
						<?php endforeach; ?>
					</nav>

					<?php if ( $this->cfg['help_html'] ) : ?>
						<div class="apx-sidebar__help">
							<div class="apx-help__title"><?php esc_html_e( 'Need help?', 'smart-login' ); ?></div>
							<div class="apx-help__body"><?php echo wp_kses_post( $this->cfg['help_html'] ); ?></div>
						</div>
					<?php endif; ?>
				</aside>

				<main class="apx-main">
					<div class="apx-topbar">
						<div class="apx-crumbs">
							<span class="apx-crumbs__home"><?php echo esc_html( $this->cfg['brand'] ); ?></span>
							<span class="apx-crumbs__sep">&rsaquo;</span>
							<span class="apx-crumbs__active" data-apx-crumb></span>
						</div>
						<div class="apx-topbar__right"><?php do_action( 'apx_topbar_' . $this->cfg['slug'] ); ?></div>
					</div>

					<div class="apx-content">
						<?php foreach ( $this->cfg['panels'] as $key => $panel ) : ?>
							<div class="apx-panel" id="apx-panel-<?php echo esc_attr( $key ); ?>"
							     data-apx-panel="<?php echo esc_attr( $key ); ?>"
							     style="display:<?php echo $key === $first ? 'block' : 'none'; ?>">

								<?php if ( ! empty( $panel['title'] ) ) : ?>
									<header class="apx-page-header">
										<h1 class="apx-page-title"><?php echo esc_html( $panel['title'] ); ?></h1>
										<?php if ( ! empty( $panel['desc'] ) ) : ?>
											<p class="apx-page-desc"><?php echo esc_html( $panel['desc'] ); ?></p>
										<?php endif; ?>
									</header>
								<?php endif; ?>

								<?php foreach ( (array) $panel['sections'] as $section ) : ?>
									<?php if ( ! empty( $section['heading'] ) ) : ?>
										<h2 class="apx-section-h"><?php echo esc_html( $section['heading'] ); ?></h2>
									<?php endif; ?>
									<?php if ( ! empty( $section['desc'] ) ) : ?>
										<p class="description"><?php echo wp_kses_post( $section['desc'] ); ?></p>
									<?php endif; ?>
									<?php foreach ( (array) $section['fields'] as $field ) { $this->render_field( $field, $s ); } ?>
								<?php endforeach; ?>
							</div>
						<?php endforeach; ?>
					</div>
				</main>
			</div>
		</div>

		<div class="apx-savebar" role="region" aria-live="polite" aria-hidden="true">
			<span class="apx-savebar__dot" aria-hidden="true"></span>
			<span class="apx-savebar__label"><strong class="apx-savebar__count"></strong></span>
			<button type="button" class="apx-savebar__discard"><?php esc_html_e( 'Discard', 'smart-login' ); ?></button>
			<button type="button" class="apx-savebar__save"><?php esc_html_e( 'Save changes', 'smart-login' ); ?></button>
		</div>

		<div class="apx-toast" role="status" aria-live="polite"></div>

		<div class="apx-popup-overlay" hidden>
			<div class="apx-popup" role="dialog" aria-modal="true">
				<div class="apx-popup-header">
					<span class="apx-popup-title"></span>
					<button type="button" class="apx-popup-close" aria-label="<?php echo esc_attr__( 'Close', 'smart-login' ); ?>"><?php echo APX_Icons::icon( 'x', 'apx-icon apx-icon--sm' ); // phpcs:ignore ?></button>
				</div>
				<div class="apx-popup-body">
					<p class="apx-popup-help"></p>
					<textarea class="apx-popup-textarea" rows="12" spellcheck="false"></textarea>
				</div>
				<div class="apx-popup-footer">
					<button type="button" class="apx-popup-cancel"><?php esc_html_e( 'Cancel', 'smart-login' ); ?></button>
					<button type="button" class="apx-popup-save"><?php esc_html_e( 'Save', 'smart-login' ); ?></button>
				</div>
			</div>
		</div>
		<?php
	}

	protected function render_field( array $f, array $s ) {
		$f    = wp_parse_args( $f, array( 'type' => 'text', 'name' => '', 'label' => '', 'desc' => '' ) );
		$name = $f['name'];
		$val  = isset( $s[ $name ] ) ? $s[ $name ] : '';
		$dep  = ! empty( $f['dep'] ) ? ' data-apx-dep="' . esc_attr( $f['dep'] ) . '"' : '';

		switch ( $f['type'] ) {

			case 'toggle':
				$master = ! empty( $f['dep_master'] )
					? ' data-apx-dep-master="' . esc_attr( $f['dep_master'] ) . '"' : '';
				echo '<div class="apx-field-row"' . $dep . '>'; // phpcs:ignore
				printf(
					'<label><input type="checkbox" name="%s" value="1" %s data-apx-field%s><strong>%s</strong></label>',
					esc_attr( $name ),
					checked( 1, (int) $val, false ),
					$master, // phpcs:ignore
					esc_html( $f['label'] )
				);
				if ( $f['desc'] ) { echo '<p class="description">' . wp_kses_post( $f['desc'] ) . '</p>'; }
				echo '</div>';
				break;

			case 'select':
				echo '<div class="apx-row"' . $dep . '>'; // phpcs:ignore
				printf( '<label for="%1$s">%2$s</label><select id="%1$s" name="%1$s" data-apx-field>',
					esc_attr( $name ), esc_html( $f['label'] ) );
				foreach ( (array) $f['options'] as $k => $label ) {
					printf( '<option value="%s"%s>%s</option>',
						esc_attr( $k ), selected( $val, $k, false ), esc_html( $label ) );
				}
				echo '</select>';
				if ( $f['desc'] ) { echo '<p class="description">' . wp_kses_post( $f['desc'] ) . '</p>'; }
				echo '</div>';
				break;

			case 'number':
			case 'text':
			case 'password':
				$style = 'number' === $f['type'] ? 'width:100px' : 'min-width:340px';
				echo '<div class="apx-row"' . $dep . '>'; // phpcs:ignore
				printf(
					'<label for="%1$s">%2$s</label><input type="%3$s" id="%1$s" name="%1$s" value="%4$s" style="%5$s"%6$s%7$s data-apx-field>',
					esc_attr( $name ),
					esc_html( $f['label'] ),
					esc_attr( $f['type'] ),
					esc_attr( $val ),
					esc_attr( $style ),
					isset( $f['min'] ) ? ' min="' . esc_attr( $f['min'] ) . '"' : '',
					isset( $f['max'] ) ? ' max="' . esc_attr( $f['max'] ) . '"' : ''
				);
				if ( $f['desc'] ) { echo '<p class="description">' . wp_kses_post( $f['desc'] ) . '</p>'; }
				echo '</div>';
				break;

			case 'list':
				echo '<div class="apx-field-row"' . $dep . '>'; // phpcs:ignore
				printf( '<label><strong>%s</strong></label>', esc_html( $f['label'] ) );
				if ( $f['desc'] ) { echo '<p class="description">' . wp_kses_post( $f['desc'] ) . '</p>'; }
				printf(
					'<div class="apx-popup-field">
						<button type="button" class="apx-popup-open" data-apx-popup="%1$s"
						        data-apx-popup-title="%2$s" data-apx-popup-help="%3$s"
						        data-apx-popup-placeholder="%4$s">%6$s</button>
						<textarea name="%1$s" hidden data-apx-field>%5$s</textarea>
					</div>',
					esc_attr( $name ),
					esc_attr( $f['label'] ),
					esc_attr( isset( $f['help'] ) ? $f['help'] : '' ),
					esc_attr( isset( $f['placeholder'] ) ? $f['placeholder'] : '' ),
					esc_textarea( $val ),
					esc_html__( 'Edit list', 'smart-login' )
				);
				echo '</div>';
				break;

			case 'actions': // array of [ 'label' => ..., 'class' => ..., 'attrs' => ... ]
				echo '<div class="apx-field-row"' . $dep . ' style="margin-top:10px">'; // phpcs:ignore
				foreach ( (array) $f['buttons'] as $b ) {
					printf( '<button type="button" class="%s"%s>%s</button> ',
						esc_attr( isset( $b['class'] ) ? $b['class'] : 'button button-secondary' ),
						isset( $b['attrs'] ) ? ' ' . $b['attrs'] : '', // phpcs:ignore
						esc_html( $b['label'] )
					);
				}
				echo '<span class="apx-action-status"></span></div>';
				break;

			case 'html': // escape hatch — raw markup using kit classes
				echo '<div' . $dep . '>' . wp_kses_post( $f['html'] ) . '</div>'; // phpcs:ignore
				break;
		}
	}
}
