<?php
/**
 * Plugin Name: Veyra Hosted Fields for WooCommerce
 * Plugin URI: https://veyragate.com
 * Description: Veyra hosted card fields embedded inline into WooCommerce checkout. WooCommerce keeps its Place Order button as the only CTA. Card data never enters WordPress.
 * Version: 0.5.8
 * Author: Veyra
 * Author URI: https://veyragate.com
 * License: GPL-2.0-or-later
 * Text Domain: veyragate-pay
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 8.2
 * WC tested up to: 9.5
 *
 * @package VeyraGatePay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VEYRAGATE_PAY_VERSION', '0.5.8' );
define( 'VEYRAGATE_PAY_PLUGIN_FILE', __FILE__ );
define( 'VEYRAGATE_PAY_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'VEYRAGATE_PAY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				__FILE__,
				true
			);
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'cart_checkout_blocks',
				__FILE__,
				true
			);
		}
	}
);

// Phase 4.12i-follow — load heartbeat dispatcher early so cron + admin_init
// hooks register regardless of WooCommerce presence.
require_once VEYRAGATE_PAY_PLUGIN_PATH . 'includes/class-vg-integration-heartbeat.php';

/**
 * 2026-05-25 — SQLite-environment notice suppression.
 *
 * When the plugin is exercised inside the WordPress Playground
 * (`/wp-content/plugins/sqlite-database-integration` shim), the WC
 * compat layer occasionally surfaces `mysqli`-flavoured warnings
 * about features the SQLite shim doesn't implement. The warnings are
 * harmless but pollute Playwright runs and make E2E logs hard to
 * read. Gate the suppression behind an explicit constant so it never
 * fires on a real merchant install:
 *
 *   define( 'WC_VEYRAGATE_SUPPRESS_SQLITE_NOTICES', true );
 *
 * The filter only drops `E_NOTICE` / `E_WARNING` strings whose
 * message includes the literal "mysqli" or "wpdb::" prefix; anything
 * else flows through to the normal error handler.
 */
if ( defined( 'WC_VEYRAGATE_SUPPRESS_SQLITE_NOTICES' ) && WC_VEYRAGATE_SUPPRESS_SQLITE_NOTICES ) {
	add_filter(
		'wp_php_error_message',
		function ( $message, $error ) {
			if ( ! is_array( $error ) ) {
				return $message;
			}
			$type = isset( $error['type'] ) ? (int) $error['type'] : 0;
			$text = isset( $error['message'] ) ? (string) $error['message'] : '';
			$buffered_types = E_NOTICE | E_WARNING | E_USER_NOTICE | E_USER_WARNING | E_DEPRECATED;
			if ( ! ( $type & $buffered_types ) ) {
				return $message;
			}
			if ( false !== stripos( $text, 'mysqli' ) || false !== stripos( $text, 'wpdb::' ) ) {
				return '';
			}
			return $message;
		},
		10,
		2
	);
}

add_action( 'plugins_loaded', 'veyragate_pay_init', 11 );

function veyragate_pay_init() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		add_action( 'admin_notices', 'veyragate_pay_missing_woocommerce_notice' );
		return;
	}

	require_once VEYRAGATE_PAY_PLUGIN_PATH . 'includes/class-wc-gateway-veyragate-pay.php';

	add_filter(
		'woocommerce_payment_gateways',
		function ( $gateways ) {
			$gateways[] = 'WC_Gateway_VeyraGate_Pay';
			return $gateways;
		}
	);

	add_action( 'woocommerce_api_veyragate_pay', 'veyragate_pay_webhook_entrypoint' );

	// Apple Pay express lanes (0.5.7) — nonce-protected admin-ajax
	// endpoints used by BOTH the checkout express button and the
	// mini-cart drawer button. `nopriv` variants cover guest checkout
	// (the common masked case). The merchant secret key never leaves
	// PHP; these endpoints only mint a WC order + a Veyra checkout
	// session server-side and return the public ids the browser needs.
	add_action( 'wp_ajax_veyragate_pay_express_order', 'veyragate_pay_express_order_endpoint' );
	add_action( 'wp_ajax_nopriv_veyragate_pay_express_order', 'veyragate_pay_express_order_endpoint' );
	add_action( 'wp_ajax_veyragate_pay_express_session', 'veyragate_pay_express_session_endpoint' );
	add_action( 'wp_ajax_nopriv_veyragate_pay_express_session', 'veyragate_pay_express_session_endpoint' );
	// 0.5.7-hotfix — same-origin proxies for card_capture_config (GET) and
	// pay-bt (POST). veyragate.com sends no Access-Control-Allow-Origin, so the
	// browser can't fetch them cross-origin; the wallet button fetches these
	// same-origin admin-ajax proxies and the plugin forwards server-to-server
	// (mirrors the Next.js storefront veyra-config / veyra-pay proxies).
	add_action( 'wp_ajax_veyragate_pay_express_config', 'veyragate_pay_express_config_endpoint' );
	add_action( 'wp_ajax_nopriv_veyragate_pay_express_config', 'veyragate_pay_express_config_endpoint' );
	add_action( 'wp_ajax_veyragate_pay_express_pay', 'veyragate_pay_express_pay_endpoint' );
	add_action( 'wp_ajax_nopriv_veyragate_pay_express_pay', 'veyragate_pay_express_pay_endpoint' );

	add_action( 'admin_notices', 'veyragate_pay_csp_admin_notice' );
}

add_action(
	'woocommerce_blocks_loaded',
	function () {
		if ( ! class_exists( 'Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType' ) ) {
			return;
		}
		require_once VEYRAGATE_PAY_PLUGIN_PATH . 'includes/class-wc-blocks-veyragate-pay.php';
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function ( $payment_method_registry ) {
				$payment_method_registry->register( new WC_Blocks_VeyraGate_Pay() );
			}
		);
	}
);

function veyragate_pay_missing_woocommerce_notice() {
	echo '<div class="notice notice-error"><p><strong>Card payment gateway</strong> requires WooCommerce to be installed and active.</p></div>';
}

function veyragate_pay_webhook_entrypoint() {
	if ( ! class_exists( 'WC_Gateway_VeyraGate_Pay' ) ) {
		require_once VEYRAGATE_PAY_PLUGIN_PATH . 'includes/class-wc-gateway-veyragate-pay.php';
	}
	$gateway = new WC_Gateway_VeyraGate_Pay();
	$gateway->handle_webhook();
}

/**
 * Apple Pay express — order-creation endpoint (0.5.7).
 *
 * Lazy-loads the gateway and delegates to handle_express_order_ajax(),
 * which creates a pending WC order from the current cart (so the charge
 * has a real order to bind BEFORE money moves). The method handles its
 * own nonce / cart / live-mode guards and emits a wp_send_json_* +
 * die() response.
 */
function veyragate_pay_express_order_endpoint() {
	if ( ! class_exists( 'WC_Gateway_VeyraGate_Pay' ) ) {
		require_once VEYRAGATE_PAY_PLUGIN_PATH . 'includes/class-wc-gateway-veyragate-pay.php';
	}
	$gateway = new WC_Gateway_VeyraGate_Pay();
	$gateway->handle_express_order_ajax();
}

/**
 * Apple Pay express — checkout-session-mint endpoint (0.5.7).
 *
 * Lazy-loads the gateway and delegates to handle_express_session_ajax(),
 * which mints a Veyra checkout session for the session-bound pending
 * express order via the EXISTING create_checkout_session_for_order().
 * The method handles its own nonce / IDOR / live-mode guards and emits a
 * wp_send_json_* + die() response.
 */
function veyragate_pay_express_session_endpoint() {
	if ( ! class_exists( 'WC_Gateway_VeyraGate_Pay' ) ) {
		require_once VEYRAGATE_PAY_PLUGIN_PATH . 'includes/class-wc-gateway-veyragate-pay.php';
	}
	$gateway = new WC_Gateway_VeyraGate_Pay();
	$gateway->handle_express_session_ajax();
}

/**
 * Apple Pay express — same-origin proxy for the session-scoped
 * card_capture_config (GET) and pay-bt (POST). veyragate.com does not send
 * CORS headers, so the browser cannot call it cross-origin from the
 * storefront; the wallet JS calls these admin-ajax proxies and the plugin
 * forwards the request server-to-server, returning the upstream body verbatim.
 */
function veyragate_pay_express_config_endpoint() {
	if ( ! class_exists( 'WC_Gateway_VeyraGate_Pay' ) ) {
		require_once VEYRAGATE_PAY_PLUGIN_PATH . 'includes/class-wc-gateway-veyragate-pay.php';
	}
	$gateway = new WC_Gateway_VeyraGate_Pay();
	$gateway->handle_express_config_ajax();
}

function veyragate_pay_express_pay_endpoint() {
	if ( ! class_exists( 'WC_Gateway_VeyraGate_Pay' ) ) {
		require_once VEYRAGATE_PAY_PLUGIN_PATH . 'includes/class-wc-gateway-veyragate-pay.php';
	}
	$gateway = new WC_Gateway_VeyraGate_Pay();
	$gateway->handle_express_pay_ajax();
}

/**
 * Show an admin notice when the configured API base URL is unreachable
 * or the publishable key looks empty. This is a CSP / config sanity
 * check, not a secret-exposure path — the notice mentions only the
 * required CSP origins and links to the CSP docs.
 */
function veyragate_pay_csp_admin_notice() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	$gateway = get_option( 'woocommerce_veyragate_pay_settings', array() );
	if ( ! is_array( $gateway ) || empty( $gateway['enabled'] ) || 'yes' !== $gateway['enabled'] ) {
		return;
	}
	if ( empty( $gateway['publishable_key'] ) ) {
		echo '<div class="notice notice-warning"><p><strong>Card payment gateway:</strong> publishable key is not configured. Set <code>Publishable key</code> under WooCommerce → Settings → Payments. See <a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=veyragate_pay' ) ) . '">settings</a>.</p></div>';
		return;
	}
	$api_base = isset( $gateway['api_base_url'] ) ? $gateway['api_base_url'] : '';
	if ( ! empty( $api_base ) && function_exists( 'wp_http_validate_url' ) && ! wp_http_validate_url( $api_base ) ) {
		echo '<div class="notice notice-warning"><p><strong>Card payment gateway:</strong> API base URL is invalid. Required CSP origins: <code>script-src ' . esc_html( esc_url_raw( $api_base ) ) . '</code>, <code>frame-src ' . esc_html( esc_url_raw( $api_base ) ) . '</code>, <code>connect-src ' . esc_html( esc_url_raw( $api_base ) ) . '</code>. See the bundled CSP docs.</p></div>';
	}
}

register_activation_hook(
	__FILE__,
	function () {
		if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die( esc_html__( 'Veyra Hosted Fields requires PHP 7.4 or higher.', 'veyragate-pay' ) );
		}
		if ( function_exists( 'veyragate_pay_heartbeat_activate' ) ) {
			veyragate_pay_heartbeat_activate();
		}
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		if ( function_exists( 'veyragate_pay_heartbeat_deactivate' ) ) {
			veyragate_pay_heartbeat_deactivate();
		}
	}
);
