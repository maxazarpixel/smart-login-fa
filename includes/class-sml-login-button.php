<?php
/**
 * A single "Log In" / "Log out" button that follows the visitor's state.
 *
 *   [smart_login_button]
 *   [smart_login_button login_text="Sign in" logout_text="Sign out" style="link"]
 *
 * It also works in navigation menus, where shortcodes normally do not run:
 * add a Custom Link whose URL is  #sml-loginout  (any label becomes the
 * login text) or whose label is the shortcode itself. Both classic menus
 * (Appearance → Menus) and the block theme Navigation block are handled.
 *
 * Attributes:
 *   login_text       Label while logged out.        Default "Log In".
 *   logout_text      Label while logged in.         Default "Log out".
 *   login_url        Login page.                    Default: the configured form page.
 *   redirect         After logging in: current | home | none | a URL.   Default current.
 *   logout_redirect  After logging out: login | home | current | none | a URL.  Default login.
 *   style            button | link.                 Default button.
 *   class            Extra CSS classes.
 *
 * @package Smart_Login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SML_Login_Button {

	const MENU_URL = '#sml-loginout';

	public static function init() {
		add_shortcode( 'smart_login_button', array( __CLASS__, 'render' ) );
		add_filter( 'wp_nav_menu_objects', array( __CLASS__, 'filter_menu_items' ) );
		add_filter( 'render_block_core/navigation-link', array( __CLASS__, 'filter_navigation_block' ), 10, 2 );
	}

	/**
	 * @param array|string $atts
	 * @return array
	 */
	protected static function atts( $atts ) {
		return shortcode_atts(
			array(
				'login_text'      => __( 'Log In', 'smart-login' ),
				'logout_text'     => __( 'Log out', 'smart-login' ),
				'login_url'       => '',
				'redirect'        => 'current',
				'logout_redirect' => 'login',
				'style'           => 'button',
				'class'           => '',
			),
			(array) $atts,
			'smart_login_button'
		);
	}

	/**
	 * @param string $setting current | home | login | none | URL
	 * @return string URL or ''.
	 */
	protected static function target_url( $setting ) {
		$setting = trim( (string) $setting );

		if ( '' === $setting || 'none' === $setting ) {
			return '';
		}
		if ( 'home' === $setting ) {
			return home_url( '/' );
		}
		if ( 'login' === $setting ) {
			return SML_Settings::form_page_url();
		}
		if ( 'current' === $setting ) {
			return SML_Page_Guard::current_url();
		}

		return esc_url_raw( $setting );
	}

	/**
	 * @param array $a Normalised attributes.
	 * @return array{state:string,url:string,label:string}
	 */
	protected static function resolve( array $a ) {
		if ( is_user_logged_in() ) {
			// The logout link carries a per-user nonce, so a cached copy of this
			// page must never be shared.
			SML_Loader::bypass_page_cache();

			return array(
				'state' => 'logout',
				'url'   => wp_logout_url( self::target_url( $a['logout_redirect'] ) ),
				'label' => (string) $a['logout_text'],
			);
		}

		$base = '' !== trim( (string) $a['login_url'] ) ? esc_url_raw( $a['login_url'] ) : SML_Settings::form_page_url();
		$dest = self::target_url( $a['redirect'] );

		// Do not send the visitor "back" to the login page itself.
		if ( $dest && untrailingslashit( (string) strtok( $dest, '?' ) ) === untrailingslashit( (string) strtok( $base, '?' ) ) ) {
			$dest = '';
		}

		return array(
			'state' => 'login',
			'url'   => $dest ? add_query_arg( 'redirect_to', rawurlencode( $dest ), $base ) : $base,
			'label' => (string) $a['login_text'],
		);
	}

	/**
	 * @param array|string $atts
	 * @return string
	 */
	public static function render( $atts = array() ) {
		wp_enqueue_style( 'smart-login', SML_PLUGIN_URL . 'public/css/smart-login.css', array(), SML_VERSION );

		$a = self::atts( $atts );
		$r = self::resolve( $a );

		$classes = array( 'sml-login-button', 'sml-login-button--' . $r['state'] );
		$style   = '';

		if ( 'link' === $a['style'] ) {
			$classes[] = 'sml-login-button--link';
		} else {
			$classes[] = 'sml-login-button--solid';

			$bg   = sanitize_hex_color( SML_Settings::get( 'button_bg_color' ) );
			$text = sanitize_hex_color( SML_Settings::get( 'button_text_color' ) );
			if ( $bg ) {
				$style .= '--sml-accent:' . $bg . ';';
			}
			if ( $text ) {
				$style .= '--sml-accent-fg:' . $text . ';';
			}
		}

		foreach ( preg_split( '/\s+/', trim( (string) $a['class'] ) ) as $extra ) {
			if ( '' !== $extra ) {
				$classes[] = sanitize_html_class( $extra );
			}
		}

		return sprintf(
			'<a class="%1$s" href="%2$s"%3$s%4$s>%5$s</a>',
			esc_attr( implode( ' ', $classes ) ),
			esc_url( $r['url'] ),
			'logout' === $r['state'] ? ' rel="nofollow"' : '',
			$style ? ' style="' . esc_attr( $style ) . '"' : '',
			esc_html( $r['label'] )
		);
	}

	/**
	 * Recognises a menu entry meant to be the login/logout switch.
	 *
	 * @param string $url
	 * @param string $title
	 * @return array|null Normalised attributes, or null for an ordinary entry.
	 */
	protected static function match_item( $url, $title ) {
		$title = trim( wp_strip_all_tags( (string) $title ) );

		if ( 1 === preg_match( '/^\[smart_login_button(\s[^\]]*)?\]$/i', $title, $m ) ) {
			$parsed = shortcode_parse_atts( isset( $m[1] ) ? trim( $m[1] ) : '' );

			return self::atts( is_array( $parsed ) ? $parsed : array() );
		}

		if ( self::MENU_URL === $url ) {
			return self::atts( '' !== $title ? array( 'login_text' => $title ) : array() );
		}

		return null;
	}

	/**
	 * Classic menus (Appearance → Menus, wp_nav_menu()).
	 *
	 * @param object[] $items
	 * @return object[]
	 */
	public static function filter_menu_items( $items ) {
		foreach ( (array) $items as $item ) {
			$a = self::match_item( (string) $item->url, (string) $item->title );
			if ( null === $a ) {
				continue;
			}

			$r              = self::resolve( $a );
			$item->url      = $r['url'];
			$item->title    = $r['label'];
			$item->classes  = array_merge(
				array_diff( (array) $item->classes, array( 'current-menu-item', 'current_page_item' ) ),
				array( 'menu-item-sml-loginout', 'menu-item-sml-' . $r['state'] )
			);
			$item->current  = false;
		}

		return $items;
	}

	/**
	 * Block theme Navigation block: a Custom Link item.
	 *
	 * @param string $content Rendered block.
	 * @param array  $block   Parsed block.
	 * @return string
	 */
	public static function filter_navigation_block( $content, $block ) {
		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$a     = self::match_item(
			isset( $attrs['url'] ) ? (string) $attrs['url'] : '',
			isset( $attrs['label'] ) ? (string) $attrs['label'] : ''
		);

		if ( null === $a ) {
			return $content;
		}

		$r = self::resolve( $a );

		return sprintf(
			'<li class="wp-block-navigation-item wp-block-navigation-link sml-nav-loginout sml-nav-loginout--%1$s"><a class="wp-block-navigation-item__content" href="%2$s"%3$s><span class="wp-block-navigation-item__label">%4$s</span></a></li>',
			esc_attr( $r['state'] ),
			esc_url( $r['url'] ),
			'logout' === $r['state'] ? ' rel="nofollow"' : '',
			esc_html( $r['label'] )
		);
	}
}
