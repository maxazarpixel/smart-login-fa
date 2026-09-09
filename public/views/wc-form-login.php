<?php
/**
 * Replacement for WooCommerce's myaccount/form-login.php — renders the
 * Smart Login shortcode instead of WooCommerce's default markup.
 *
 * @package Smart_Login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="woocommerce-smart-login">
	<?php echo do_shortcode( '[smart_login_form]' ); ?>
</div>
