<?php
/**
 * Veyra Hosted Fields WooCommerce gateway.
 *
 * The gateway renders Veyra Hosted Fields inline inside WooCommerce
 * checkout. Veyra owns only the secure card fields. WooCommerce keeps
 * the page layout, the order summary, the billing/shipping fields,
 * and the "Place order" button as the only CTA.
 *
 * Tokenization happens before WooCommerce submit via Basis Theory. The
 * order receives only safe metadata: basis_theory_token_intent_id,
 * card summary (brand/last4/funding/issuer_country/etc.), and session
 * id. No raw card data is ever stored in WooCommerce order metadata,
 * logs, or notes. Server-side confirmation calls
 * POST /api/v1/checkout_sessions/confirm (secret-key authenticated,
 * session_id in the body) with the BT token intent + the safe card
 * summary — never PAN/CVC.
 *
 * @package VeyraGatePay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Gateway_VeyraGate_Pay extends WC_Payment_Gateway {

	const GATEWAY_ID = 'veyragate_pay';

	public $api_base_url;
	public $publishable_key;
	public $secret_key;
	public $webhook_secret;
	public $debug;

	public function __construct() {
		$this->id                 = self::GATEWAY_ID;
		$this->method_title       = __( 'Veyra Hosted Fields', 'veyragate-pay' );
		$this->method_description = __( 'Veyra renders only the secure card fields inline inside your WooCommerce checkout. WordPress never receives card numbers or CVC. The "Place order" button stays as the only CTA.', 'veyragate-pay' );
		$this->has_fields         = true;
		$this->supports           = array( 'products', 'refunds' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title           = $this->get_option( 'title', __( 'Credit / debit card', 'veyragate-pay' ) );
		// Default description is intentionally empty. Customers don't need
		// to be told where their card data goes — the Place Order button
		// and the fields themselves are enough. Merchants can still set a
		// custom description in the gateway settings if they want one.
		$this->description     = $this->get_option( 'description', '' );
		$this->enabled         = $this->get_option( 'enabled', 'no' );
		$this->api_base_url    = $this->get_option( 'api_base_url', 'https://veyragate.com' );
		$this->publishable_key = $this->get_option( 'publishable_key', '' );
		$this->secret_key      = $this->get_option( 'secret_key', '' );
		$this->webhook_secret  = $this->get_option( 'webhook_secret', '' );
		$this->debug           = 'yes' === $this->get_option( 'debug', 'no' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'admin_notices', array( $this, 'ssl_check' ) );
		add_action( 'admin_notices', array( $this, 'live_credentials_check' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_checkout_assets' ) );
		// F-WC-CUSTOM-CHECKOUT-ENQUEUE (2026-06-04) — broaden the SDK enqueue trigger
		// so it fires on custom checkout pages (Checkout Form Designer, CheckoutWC,
		// custom theme overrides) where `is_checkout()` returns false on
		// `wp_enqueue_scripts`. These WC actions fire during the order-review render
		// for ANY checkout flow, classic or custom. Calling wp_enqueue_script() here
		// is safe — the resulting <script> tag is printed by wp_footer() which always
		// runs after these hooks fire. The underlying enqueue is idempotent (WP
		// dedupes via the script handle), so the extra hooks are harmless on a
		// standard checkout where the wp_enqueue_scripts pass already enqueued.
		add_action( 'woocommerce_review_order_before_payment', array( $this, 'enqueue_checkout_assets_late' ) );
		add_action( 'woocommerce_after_checkout_form', array( $this, 'enqueue_checkout_assets_late' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		// Apple Pay express (0.5.7) — site-wide front-end enqueue for the
		// mini-cart drawer wallet button. The method itself bails before
		// enqueuing anything when the gateway is disabled or the mini-cart
		// wallet opt-in is off, so the footprint on a store that hasn't
		// turned it on is exactly zero.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_minicart_assets' ) );
	}

	/**
	 * Enqueue lightweight admin polish on the gateway settings page.
	 *
	 * The script wires a dirty-state visual on the Save Changes button
	 * so it only enables after the merchant has edited a field. No
	 * card data is touched — admin-only surface.
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		// WooCommerce settings pages all render under
		// `woocommerce_page_wc-settings`. Be conservative and only
		// enqueue when we're confident this is a WC settings page
		// AND the URL gestures at the gateway section.
		if ( 'woocommerce_page_wc-settings' !== $hook_suffix ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( self::GATEWAY_ID !== $section ) {
			return;
		}
		wp_enqueue_script(
			'veyragate-pay-admin-settings',
			VEYRAGATE_PAY_PLUGIN_URL . 'assets/dist/admin-settings.js',
			array(),
			VEYRAGATE_PAY_VERSION,
			true
		);
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title'   => __( 'Enable / Disable', 'veyragate-pay' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Veyra Hosted Fields', 'veyragate-pay' ),
				'default' => 'no',
			),
			'title' => array(
				'title'       => __( 'Title', 'veyragate-pay' ),
				'type'        => 'text',
				'description' => __( 'Payment method title shown to customers.', 'veyragate-pay' ),
				'default'     => __( 'Credit / debit card', 'veyragate-pay' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Description', 'veyragate-pay' ),
				'type'        => 'textarea',
				'description' => __( 'Optional payment method description shown to customers. Leave blank for a clean payment area.', 'veyragate-pay' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'api_base_url' => array(
				'title'       => __( 'API base URL', 'veyragate-pay' ),
				'type'        => 'text',
				'description' => __( 'Usually https://veyragate.com. Use a preview URL only for testing.', 'veyragate-pay' ),
				'default'     => 'https://veyragate.com',
				'desc_tip'    => true,
			),
			'publishable_key' => array(
				'title'       => __( 'Publishable key', 'veyragate-pay' ),
				'type'        => 'text',
				'description' => __( 'Your Veyra publishable key (pk_test_… / pk_live_…). Safe to expose in the browser.', 'veyragate-pay' ),
				'desc_tip'    => true,
			),
			'secret_key' => array(
				'title'       => __( 'Secret key', 'veyragate-pay' ),
				'type'        => 'password',
				'description' => __( 'Your Veyra secret key. Server-side only. Never sent to the browser.', 'veyragate-pay' ),
				'desc_tip'    => true,
			),
			'webhook_secret' => array(
				'title'       => __( 'Webhook signing secret', 'veyragate-pay' ),
				'type'        => 'password',
				'description' => __( 'Optional but recommended. Verifies Veyragate-Signature on incoming order updates.', 'veyragate-pay' ),
				'desc_tip'    => true,
			),
			// Phase 4.12i-follow — publishable site key for the integration
			// heartbeat / events / manifest model.  Distinct from the
			// publishable_key above (pk_*) which gates the hosted-fields
			// SDK.  Both are safe to expose in the browser.
			'vgs_site_key' => array(
				'title'       => __( 'Veyra site key (heartbeat)', 'veyragate-pay' ),
				'type'        => 'text',
				'description' => __( 'Optional. Paste the vgs_* publishable site key from your Veyra dashboard → Checkout Integrations to enable the integration health dashboard. No card data is sent through this channel.', 'veyragate-pay' ),
				'desc_tip'    => true,
			),
			'vgs_environment' => array(
				'title'       => __( 'Veyra site environment', 'veyragate-pay' ),
				'type'        => 'select',
				'options'     => array(
					'test' => __( 'test', 'veyragate-pay' ),
					'live' => __( 'live', 'veyragate-pay' ),
				),
				'default'     => 'test',
				'description' => __( 'Must match the environment band of the site key above.', 'veyragate-pay' ),
				'desc_tip'    => true,
			),
			// WC card-only masked build (2026-06-01) — masked channel tag.
			// Operator-facing. When set (e.g. "evobones_masked"), the value
			// is sent as the top-level `channel` on the checkout-session
			// create so the originating channel is recorded on the session
			// row. On the server the channel drives the masked high-alert
			// (Resend to ops) when it is on the dropin-channel allowlist.
			// It is NOT card data and is NEVER forwarded to the card
			// processor. Leave blank for a standard hosted-checkout session.
			'channel' => array(
				'title'       => __( 'Masked channel tag', 'veyragate-pay' ),
				'type'        => 'text',
				'description' => __( 'Optional. Internal channel tag for this storefront (e.g. your-store_masked). Set by your Veyra account manager. Leave blank unless instructed. This is an operations label only — it is never sent to the card network and never shown to customers.', 'veyragate-pay' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			// F286 — optional descriptor override for the customer-facing
			// "Your bank will show a charge from {…}" disclosure. Tier_3
			// / tier_4 merchants set this to their mask-pool descriptor
			// so the customer sees the same string their bank statement
			// will eventually show. Leave blank to inherit the site title.
			'descriptor_override' => array(
				'title'       => __( 'Bank statement descriptor', 'veyragate-pay' ),
				'type'        => 'text',
				'description' => __( 'Optional. The text the customer\'s bank statement will show for this charge. Leave blank to use your store name. Tip: keep this short and recognizable.', 'veyragate-pay' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			// Apple Pay express (0.5.7) — mini-cart drawer wallet button.
			// Default OFF so existing installs are byte-for-byte unaffected
			// until a merchant explicitly opts in. When off, the front-end
			// enqueue for the drawer adds NO scripts site-wide.
			'enable_minicart_wallet' => array(
				'title'       => __( 'Mini-cart Apple Pay', 'veyragate-pay' ),
				'type'        => 'checkbox',
				'label'       => __( 'Show Apple Pay in the mini-cart drawer', 'veyragate-pay' ),
				'default'     => 'no',
				'description' => __( 'When enabled, an Apple Pay express button appears inside the mini-cart drawer on Apple devices. Leave off until your account manager confirms the storefront is ready.', 'veyragate-pay' ),
				'desc_tip'    => true,
			),
			'minicart_wallet_selector' => array(
				'title'       => __( 'Mini-cart container selector', 'veyragate-pay' ),
				'type'        => 'text',
				'description' => __( 'Optional CSS selector for the mini-cart drawer container (e.g. .menu-cart-pro-content or .widget_shopping_cart_content). Leave blank to auto-detect. This is a CSS selector, not HTML.', 'veyragate-pay' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'debug' => array(
				'title'       => __( 'Debug log', 'veyragate-pay' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable logging', 'veyragate-pay' ),
				'default'     => 'no',
				'description' => __( 'Log Veyra Hosted Fields events to WooCommerce status logs. Card data is never logged.', 'veyragate-pay' ),
			),
		);
	}

	/**
	 * Settings-API save-time sanitizer for the mini-cart container
	 * selector. process_admin_options() auto-invokes
	 * validate_<key>_field() before persisting. It is a CSS selector,
	 * never HTML — strip tags + trim so a stored value can't inject
	 * markup and can't carry stray whitespace into the localize bag.
	 *
	 * @param string $key   Field key ('minicart_wallet_selector').
	 * @param string $value Raw posted value.
	 * @return string Sanitized selector.
	 */
	public function validate_minicart_wallet_selector_field( $key, $value ) {
		return trim( wp_strip_all_tags( (string) $value ) );
	}

	public function ssl_check() {
		if ( 'yes' === $this->enabled && ! is_ssl() && function_exists( 'is_admin' ) && is_admin() ) {
			echo '<div class="notice notice-warning"><p><strong>Card payment gateway</strong> requires HTTPS in production. Test / playground stacks may use HTTP.</p></div>';
		}
	}

	/**
	 * Surface a hard error in WP admin if the gateway is enabled in live
	 * mode but missing credentials. Prevents merchants from silently
	 * shipping orders that would otherwise hit the test-mode mock-
	 * success branch in process_payment().
	 */
	public function live_credentials_check() {
		if ( ! function_exists( 'is_admin' ) || ! is_admin() ) {
			return;
		}
		if ( 'yes' !== $this->enabled ) {
			return;
		}
		$is_live = 'live' === $this->get_option( 'vgs_environment', 'test' );
		if ( ! $is_live ) {
			return;
		}
		$missing = array();
		if ( empty( $this->secret_key ) ) {
			$missing[] = __( 'Secret key', 'veyragate-pay' );
		}
		if ( empty( $this->webhook_secret ) ) {
			$missing[] = __( 'Webhook signing secret', 'veyragate-pay' );
		}
		if ( empty( $missing ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>'
			. esc_html__( 'Veyra Hosted Fields', 'veyragate-pay' )
			. '</strong> '
			. esc_html__( 'is enabled in LIVE mode but is missing required credentials:', 'veyragate-pay' )
			. ' <strong>' . esc_html( implode( ', ', $missing ) ) . '</strong>. '
			. esc_html__( 'Live-mode payments will fail until these are configured. Customers will not be charged.', 'veyragate-pay' )
			. '</p></div>';
	}

	/**
	 * Enqueue the hosted-fields JS on the standard WooCommerce checkout
	 * / order-pay page. Plain-tag enqueueing — Woo classic checkout will
	 * fire the inline init on render.
	 *
	 * F-WC-CUSTOM-CHECKOUT-ENQUEUE (2026-06-04) — for CUSTOM checkout
	 * pages (Checkout Form Designer, CheckoutWC, custom theme overrides)
	 * where `is_checkout()` returns false at `wp_enqueue_scripts` time,
	 * the late-enqueue hook `enqueue_checkout_assets_late()` (bound to
	 * `woocommerce_review_order_before_payment` + `woocommerce_after_checkout_form`)
	 * picks up the slack. Site admins can also force-enqueue via the
	 * `veyragate_pay_force_enqueue_checkout` filter.
	 */
	public function enqueue_checkout_assets() {
		if ( ! function_exists( 'is_checkout' ) ) {
			return;
		}
		if ( 'yes' !== $this->enabled ) {
			return;
		}
		// Primary gate — fast path for the standard WC checkout.
		$is_checkout_context = is_checkout() || is_wc_endpoint_url( 'order-pay' );
		// Filter escape hatch — site admins can force-enqueue on a
		// non-standard page where is_checkout() returns false but a
		// WC payment form is actually being rendered.
		$force = (bool) apply_filters( 'veyragate_pay_force_enqueue_checkout', false );
		if ( ! $is_checkout_context && ! $force ) {
			return;
		}
		$this->_do_enqueue_checkout_assets();
	}

	/**
	 * F-WC-CUSTOM-CHECKOUT-ENQUEUE (2026-06-04) — late-enqueue fallback.
	 *
	 * Bound to `woocommerce_review_order_before_payment` and
	 * `woocommerce_after_checkout_form`. Both fire on the order-review
	 * render path for ANY WC checkout flow — classic AND custom — so a
	 * site using Checkout Form Designer / CheckoutWC / a custom theme
	 * page that doesn't satisfy `is_checkout()` still gets the SDK + JS
	 * properly enqueued before wp_footer prints.
	 *
	 * WP dedupes via the script handle, so calling enqueue twice (this
	 * method + the wp_enqueue_scripts path) is a harmless no-op on
	 * standard checkouts. We deliberately skip the `is_checkout()` gate
	 * here — by definition, WC just ran an action that only fires
	 * during checkout rendering, so we KNOW we're on a checkout flow.
	 */
	public function enqueue_checkout_assets_late() {
		if ( 'yes' !== $this->enabled ) {
			return;
		}
		$this->_do_enqueue_checkout_assets();
	}

	/**
	 * Private helper — registers + enqueues the SDK, the classic
	 * adapter script, and the stylesheet. Idempotent; WordPress
	 * dedupes by handle on the second call. Centralised here so both
	 * `enqueue_checkout_assets()` (early, on `wp_enqueue_scripts`) and
	 * `enqueue_checkout_assets_late()` (late, on WC checkout actions)
	 * stay in lockstep.
	 */
	private function _do_enqueue_checkout_assets() {
		// SDK enqueue. The hosted-fields SDK ships at the same URL on
		// every storefront — `<api_base_url>/v1/hosted-fields.js`. Both
		// the classic adapter (this method) and the Blocks adapter
		// (`WC_Blocks_VeyraGate_Pay::get_payment_method_script_handles`)
		// reference the SAME handle (`veyragate-pay-sdk`) so WordPress's
		// dependency manager dedupes the registration even on a Blocks
		// checkout page that also fires the classic enqueue hook. The
		// defensive `wp_script_is( $handle, 'registered' )` check below
		// guarantees the SDK is registered exactly once per page-load
		// even if some other extension already loaded it.
		$sdk_url = trailingslashit( untrailingslashit( $this->api_base_url ) ) . 'v1/hosted-fields.js';
		if ( ! wp_script_is( 'veyragate-pay-sdk', 'registered' ) ) {
			wp_register_script(
				'veyragate-pay-sdk',
				$sdk_url,
				array(),
				VEYRAGATE_PAY_VERSION,
				true
			);
		}
		wp_enqueue_script( 'veyragate-pay-sdk' );

		$assets_url = VEYRAGATE_PAY_PLUGIN_URL . 'assets/dist/';
		wp_enqueue_script(
			'veyragate-pay-classic',
			$assets_url . 'classic.js',
			array( 'jquery', 'wc-checkout', 'veyragate-pay-sdk' ),
			VEYRAGATE_PAY_VERSION,
			true
		);

		wp_localize_script(
			'veyragate-pay-classic',
			'veyragatePayConfig',
			array(
				'apiBaseUrl'      => esc_url_raw( untrailingslashit( $this->api_base_url ) ),
				'publishableKey'  => (string) $this->publishable_key,
				'gatewayId'       => self::GATEWAY_ID,
				// F286 — surface the merchant name + the optional
				// descriptor override to the front-end. Used by the
				// descriptor-disclosure helper to render "Your bank
				// will show a charge from {name}." Tier_3/4 stores
				// override the descriptor to the mask-pool string so
				// what the customer sees matches what their bank shows.
				'merchantName'    => (string) get_bloginfo( 'name' ),
				'descriptor'      => (string) $this->get_option( 'descriptor_override', '' ),
				// Apple Pay express (0.5.7) — admin-ajax mint endpoints for
				// the checkout express button. Both point at admin-ajax.php;
				// the `action=` param selects order-create vs session-mint.
				// `walletMerchantNameFallback` is the literal neutral name
				// used in the wallet sheet header when the server config
				// omits a merchant_name — NEVER the legal/store name.
				'expressOrderAjaxUrl'        => admin_url( 'admin-ajax.php' ),
				'expressSessionAjaxUrl'      => admin_url( 'admin-ajax.php' ),
				'expressConfigAjaxUrl'       => admin_url( 'admin-ajax.php' ),
				'expressPayAjaxUrl'          => admin_url( 'admin-ajax.php' ),
				'expressNonce'               => wp_create_nonce( 'veyragate_pay_express' ),
				'walletMerchantNameFallback' => 'Secure Checkout',
				'i18n'            => array(
					'incomplete'   => __( 'Please complete your card details.', 'veyragate-pay' ),
					'tokenize_err' => __( 'We could not securely store your card. Please try again.', 'veyragate-pay' ),
					'sdk_failed'   => __( 'Payment fields could not load. Please try again or contact support.', 'veyragate-pay' ),
				),
			)
		);

		// Apple Pay express (0.5.7) — Apple's PassKit web SDK + the shared
		// wallet core. The SDK provides window.ApplePaySession on the few
		// surfaces that need a polyfill (and enables the desktop QR flow);
		// wallet-core.js encapsulates the ApplePaySession sequence + BT
		// tokenization shared by the checkout express button and the
		// mini-cart drawer. Idempotent — WP dedupes by handle if the
		// mini-cart enqueue already registered these on the same page.
		if ( ! wp_script_is( 'veyragate-applepay-sdk', 'registered' ) ) {
			wp_register_script(
				'veyragate-applepay-sdk',
				'https://applepay.cdn-apple.com/jsapi/1.latest/apple-pay-sdk.js',
				array(),
				null,
				true
			);
		}
		wp_enqueue_script( 'veyragate-applepay-sdk' );
		wp_enqueue_script(
			'veyragate-pay-wallet-core',
			$assets_url . 'wallet-core.js',
			array( 'veyragate-pay-classic' ),
			VEYRAGATE_PAY_VERSION,
			true
		);

		wp_enqueue_style(
			'veyragate-pay-classic',
			$assets_url . 'classic.css',
			array(),
			VEYRAGATE_PAY_VERSION
		);
	}

	/**
	 * Render hosted fields inside the WooCommerce payment method area.
	 * Hidden inputs capture token metadata before submit.
	 */
	public function payment_fields() {
		if ( $this->description ) {
			echo '<p class="veyragate-pay-description">' . wp_kses_post( $this->description ) . '</p>';
		}
		// Surface the descriptor + merchant name on the gateway container
		// so classic.js can pick them up at mount time. The merchant
		// name + descriptor are passed to the SDK's
		// renderDescriptorDisclosure helper (tier_3/4 mask-pool aware).
		$descriptor_override = (string) $this->get_option( 'descriptor_override', '' );
		?>
		<div class="veyragate-pay-fields"
			data-veyra-gateway
			data-veyra-merchant-name="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"
			data-veyra-descriptor="<?php echo esc_attr( $descriptor_override ); ?>"
			>
			<div id="veyra-card-fields" class="veyragate-pay-fields__mount"
				data-veyra-publishable-key="<?php echo esc_attr( $this->publishable_key ); ?>"
				data-veyra-api-base="<?php echo esc_attr( untrailingslashit( $this->api_base_url ) ); ?>">
			</div>
			<div class="veyragate-pay-fields__error" data-veyra-error role="alert" aria-live="polite"></div>
			<?php // Phase 4.12p (BT-canonical) — primary tokenization fields. ?>
			<input type="hidden" name="veyra_basis_theory_token_intent_id" data-veyra-token-intent-id value="" />
			<input type="hidden" name="veyra_card_summary_json" data-veyra-card-summary value="" />
			<input type="hidden" name="veyra_session_id" data-veyra-session-id value="" />
			<?php // WC card-only masked build (2026-06-01) — this is the CARD
				// lane. The wallet flag is retained as a hidden input for SDK
				// backward-compat but is NOT read or forwarded by
				// process_payment(); the card confirm never sends wallet_type.
				// Wallet payments use their own button + tokenization path. ?>
			<input type="hidden" name="veyra_wallet_type" data-veyra-wallet-type value="" />
			<?php // Deprecated post-4.12p — kept for backward-compat with older SDK builds. ?>
			<input type="hidden" name="veyra_payment_method_id" data-veyra-pm-id value="" />
			<input type="hidden" name="veyra_token_id" data-veyra-token-id value="" />
			<input type="hidden" name="veyra_tokenization_mode" data-veyra-mode value="" />
			<input type="hidden" name="veyra_last4" data-veyra-last4 value="" />
			<input type="hidden" name="veyra_brand" data-veyra-brand value="" />
			<noscript>
				<p><?php esc_html_e( 'JavaScript is required to enter card details securely. Please enable JavaScript and reload.', 'veyragate-pay' ); ?></p>
			</noscript>
		</div>
		<?php
	}

	/**
	 * Validate that the front-end produced a token. Card numbers are
	 * NEVER posted here — only the safe metadata fields are. Accepts
	 * either the BT-canonical token-intent shape (post-4.12p) or the
	 * legacy payment_method_id + token_id shape, so a stale SDK build
	 * still fails closed rather than hard-erroring.
	 */
	public function validate_fields() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$token_intent_id = isset( $_POST['veyra_basis_theory_token_intent_id'] )
			? sanitize_text_field( wp_unslash( $_POST['veyra_basis_theory_token_intent_id'] ) ) : '';
		$pm_id   = isset( $_POST['veyra_payment_method_id'] ) ? sanitize_text_field( wp_unslash( $_POST['veyra_payment_method_id'] ) ) : '';
		$tok_id  = isset( $_POST['veyra_token_id'] ) ? sanitize_text_field( wp_unslash( $_POST['veyra_token_id'] ) ) : '';
		$mode    = isset( $_POST['veyra_tokenization_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['veyra_tokenization_mode'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$has_bt_intent = ! empty( $token_intent_id );
		$has_legacy    = ! empty( $pm_id ) && ! empty( $tok_id );
		if ( ! $has_bt_intent && ! $has_legacy ) {
			wc_add_notice( __( 'Please complete your card details before placing the order.', 'veyragate-pay' ), 'error' );
			return false;
		}
		// Legacy path only — tokenization mode must be one of the known
		// values. BT-canonical path doesn't ship a tokenization_mode hint
		// at all (the backend reads it from the token intent itself).
		if ( ! $has_bt_intent && ! in_array( $mode, array( 'basis_theory', 'basis_theory_mock', 'synthetic' ), true ) ) {
			wc_add_notice( __( 'Card tokenization mode is invalid.', 'veyragate-pay' ), 'error' );
			return false;
		}
		return true;
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( __( 'Order not found.', 'veyragate-pay' ), 'error' );
			return array( 'result' => 'failure' );
		}

		// Fail-closed: refuse live mode if the credentials aren't present.
		// Without a secret key (and a webhook secret) we cannot tell a
		// real charge succeeded from a development mock, so we MUST NOT
		// drift into the mock-success branch on a live storefront.
		$is_live            = 'live' === $this->get_option( 'vgs_environment', 'test' );
		$has_secret_key     = ! empty( $this->secret_key );
		$has_webhook_secret = ! empty( $this->webhook_secret );
		if ( 'yes' === $this->enabled && $is_live && ( ! $has_secret_key || ! $has_webhook_secret ) ) {
			$this->log( 'Refusing live-mode payment: missing secret_key or webhook_secret on order ' . $order_id );
			wc_add_notice(
				__( 'Veyra is enabled in live mode but is missing API credentials. Contact site administrator.', 'veyragate-pay' ),
				'error'
			);
			return array( 'result' => 'failure' );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		// Phase 4.12p — BT-canonical token intent + safe card summary.
		$token_intent_id   = isset( $_POST['veyra_basis_theory_token_intent_id'] )
			? sanitize_text_field( wp_unslash( $_POST['veyra_basis_theory_token_intent_id'] ) ) : '';
		$card_summary_json = isset( $_POST['veyra_card_summary_json'] )
			? wp_unslash( $_POST['veyra_card_summary_json'] ) : '';
		$card_summary      = $card_summary_json ? json_decode( $card_summary_json, true ) : array();
		if ( ! is_array( $card_summary ) ) {
			$card_summary = array();
		}

		// WC card-only masked build (2026-06-01) — this gateway is the
		// CARD lane. It never sends `wallet_type` to the confirm endpoint.
		// Wallet payments (Apple Pay / Google Pay) ride their own button +
		// tokenization path, not this `process_payment` flow. The hidden
		// `veyra_wallet_type` input is retained for SDK compatibility but
		// is deliberately NOT read or forwarded here.

		// Legacy/back-compat fields — still read so older SDK builds can
		// land successfully on test, and so order meta stays populated.
		$pm_id      = isset( $_POST['veyra_payment_method_id'] ) ? sanitize_text_field( wp_unslash( $_POST['veyra_payment_method_id'] ) ) : '';
		$tok_id     = isset( $_POST['veyra_token_id'] ) ? sanitize_text_field( wp_unslash( $_POST['veyra_token_id'] ) ) : '';
		$session_id = isset( $_POST['veyra_session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['veyra_session_id'] ) ) : '';
		$mode       = isset( $_POST['veyra_tokenization_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['veyra_tokenization_mode'] ) ) : 'synthetic';
		$last4      = isset( $_POST['veyra_last4'] ) ? preg_replace( '/[^0-9]/', '', wp_unslash( $_POST['veyra_last4'] ) ) : '';
		$brand      = isset( $_POST['veyra_brand'] ) ? sanitize_text_field( wp_unslash( $_POST['veyra_brand'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Prefer card summary values when present; fall back to legacy
		// flat fields for order meta only.
		if ( isset( $card_summary['last4'] ) && is_string( $card_summary['last4'] ) ) {
			$last4 = preg_replace( '/[^0-9]/', '', $card_summary['last4'] );
		}
		if ( isset( $card_summary['brand'] ) && is_string( $card_summary['brand'] ) ) {
			$brand = sanitize_text_field( $card_summary['brand'] );
		}

		// Defensive: refuse if any value looks like a card number.
		// v0.5.4 (2026-06-05) — match CONTIGUOUS digit runs of 12+, not the
		// stripped-non-digits-total. A BT token-intent UUID like
		// `9f1505e0-415a-435c-85c3-beabd3c9c8b6` strips to 18 digits but its
		// longest contiguous digit run is only 4 ("1505"); the older
		// stripped-total heuristic flagged every legit BT-intent as
		// "looks like a card number" and failed every order. Card PANs are
		// ALWAYS contiguous digits, so a regex match for `\d{12,}` is the
		// right shape — UUIDs slip past, real card numbers still get
		// refused. Discovered against Apex 2026-06-05.
		foreach ( array( $pm_id, $tok_id, $session_id, $brand, $token_intent_id ) as $candidate ) {
			if ( preg_match( '/\d{12,}/', (string) $candidate ) ) {
				$order->update_status( 'failed', __( 'Refusing token fields that look like a card number.', 'veyragate-pay' ) );
				return array( 'result' => 'failure' );
			}
		}

		if ( strlen( $last4 ) > 4 ) {
			$last4 = substr( $last4, -4 );
		}

		// WC card-only masked build (2026-06-01) — CARD lane confirm.
		// No wallet_type is passed; the confirm endpoint resolves the
		// charge from the session id in the body.
		try {
			$response = $this->confirm_with_veyra( $order, $token_intent_id, $card_summary, $pm_id, $tok_id, $session_id, $mode );
		} catch ( Exception $e ) {
			$this->log( 'Payment confirmation failed for order ' . $order_id . ': ' . $e->getMessage() );
			$order->update_status( 'failed', __( 'Veyra confirmation failed.', 'veyragate-pay' ) );
			wc_add_notice( $e->getMessage(), 'error' );
			return array( 'result' => 'failure' );
		}

		// Resolve the session id the charge actually ran against. The
		// confirm response echoes it back; fall back to whatever the
		// front-end posted. Stored on the order so process_refund() can
		// resolve the transaction by session id.
		$resolved_session_id = ! empty( $response['session_id'] )
			? (string) $response['session_id']
			: (string) $session_id;
		$redirect_url        = isset( $response['redirect_url'] ) ? (string) $response['redirect_url'] : '';

		$order->update_meta_data( '_veyragate_basis_theory_token_intent_id', $token_intent_id );
		$order->update_meta_data( '_veyragate_payment_method_id', $pm_id );
		$order->update_meta_data( '_veyragate_token_id', $tok_id );
		$order->update_meta_data( '_veyragate_session_id', $resolved_session_id );
		$order->update_meta_data( '_veyragate_tokenization_mode', $mode );
		$order->update_meta_data( '_veyragate_last4', $last4 );
		$order->update_meta_data( '_veyragate_brand', $brand );
		$order->update_meta_data( '_veyragate_status', $response['status'] );
		$order->update_meta_data( '_veyragate_transaction_id', $response['transaction_id'] );

		if ( 'succeeded' === $response['status'] ) {
			$order->payment_complete( $response['transaction_id'] );
			$order->add_order_note(
				sprintf(
					/* translators: 1: brand 2: last4 3: tokenization mode */
					__( 'Veyra Hosted Fields charged %1$s ending %2$s (mode: %3$s).', 'veyragate-pay' ),
					$brand ? $brand : 'card',
					$last4 ? $last4 : '****',
					$mode
				)
			);
		} elseif ( 'requires_action' === $response['status'] && '' !== $redirect_url ) {
			// 3DS / additional verification. Hand the customer off to the
			// Veyra-hosted challenge URL. Do NOT complete the order — the
			// session flips to succeeded on the challenge return and the
			// async webhook (or the customer landing back on the order-pay
			// page) reconciles the order. WooCommerce treats result=success
			// + a redirect as "send the browser here next".
			$order->update_status( 'pending', __( 'Awaiting card verification.', 'veyragate-pay' ) );
			$order->save();
			return array(
				'result'   => 'success',
				'redirect' => $redirect_url,
			);
		} elseif ( in_array( $response['status'], array( 'requires_action', 'pending' ), true ) ) {
			// requires_action without a redirect URL (or a pending async
			// rail): park the order on-hold for webhook reconciliation.
			$order->update_status( 'on-hold', __( 'Veyra Hosted Fields is awaiting additional verification.', 'veyragate-pay' ) );
		} else {
			// failed / blocked / anything else — decline the order.
			$order->update_status( 'failed', __( 'Veyra Hosted Fields could not complete the payment.', 'veyragate-pay' ) );
			wc_add_notice( __( 'Payment was declined. Please try a different card.', 'veyragate-pay' ), 'error' );
			$order->save();
			return array( 'result' => 'failure' );
		}

		$order->save();

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Server-side confirmation. Sends only the Basis Theory token intent
	 * id + the safe card summary to Veyra — never raw card data. In
	 * test-environment harnesses where no secret key is configured we
	 * return a labelled mock-succeeded result so the harness can prove
	 * the order-status path; this branch is gated by
	 * `vgs_environment === 'test'` AND already short-circuited at the
	 * top of process_payment() in live mode (fail-closed).
	 *
	 * WC card-only masked build (2026-06-01):
	 *   Endpoint switched from the iframe-only
	 *     POST {api_base_url}/api/checkout/{session_id}/pay-bt
	 *   to the merchant secret-key endpoint
	 *     POST {api_base_url}/api/v1/checkout_sessions/confirm
	 *   with `session_id` moved INTO the JSON body. CARD-ONLY: no
	 *   `wallet_type` is ever sent (wallets ride their own button +
	 *   tokenization path, not this card lane).
	 *
	 * Body shape (server schema is liberal/passthrough on extras):
	 *   session_id, basis_theory_token_intent_id, card_summary,
	 *   customer_email, idempotency_key.
	 *
	 * Returns an array describing the outcome for process_payment():
	 *   status         — public status (succeeded | requires_action |
	 *                    failed | blocked | pending)
	 *   transaction_id — merchant-safe synthetic id (vcs_conf_…)
	 *   session_id     — the session id the charge ran against
	 *   redirect_url   — present + non-empty only on requires_action (3DS)
	 */
	private function confirm_with_veyra(
		WC_Order $order,
		$token_intent_id,
		$card_summary,
		$pm_id,
		$tok_id,
		$session_id,
		$mode
	) {
		$is_live = 'live' === $this->get_option( 'vgs_environment', 'test' );

		if ( empty( $this->secret_key ) ) {
			// Hard refusal in live mode — should already be caught
			// earlier in process_payment(), but belt-and-braces here so
			// no path ever drifts into mock-success on a live storefront.
			if ( $is_live ) {
				throw new Exception( __( 'Veyra is in live mode but no secret key is configured.', 'veyragate-pay' ) );
			}
			// Test / playground mode without a configured secret. Mark the
			// order as a mock-succeeded charge so the harness can prove
			// the full WooCommerce status path without ever shipping raw
			// card data anywhere.
			return array(
				'status'         => 'succeeded',
				'transaction_id' => 'veyra_test_' . substr( wp_generate_password( 20, false, false ), 0, 16 ),
				'session_id'     => (string) $session_id,
				'redirect_url'   => '',
			);
		}

		// The BT token intent is the canonical proof-of-tokenization.
		// Without it we cannot call the backend safely.
		if ( empty( $token_intent_id ) ) {
			throw new Exception( __( 'Card details did not tokenize. Please try again.', 'veyragate-pay' ) );
		}

		// The confirm endpoint resolves the charge from the session id in
		// the body. The classic JS bundle is responsible for creating a
		// session before submit; if it didn't, we create one server-side
		// here so the order still carries the full masked metadata.
		if ( empty( $session_id ) ) {
			$session_id = $this->create_checkout_session_for_order( $order );
		}
		if ( empty( $session_id ) ) {
			throw new Exception( __( 'Could not create a Veyra checkout session for this order.', 'veyragate-pay' ) );
		}

		$endpoint = trailingslashit( untrailingslashit( $this->api_base_url ) )
			. 'api/v1/checkout_sessions/confirm';

		// Sanitize card_summary down to the shape the backend accepts.
		// Drop unknown keys — keep the payload minimal + safe.
		$summary = $this->sanitize_card_summary( $card_summary );

		// CARD-ONLY confirm body. `session_id` is in the body (not the
		// URL). No `wallet_type` is ever sent on the card lane.
		$body = array(
			'session_id'                   => (string) $session_id,
			'basis_theory_token_intent_id' => $token_intent_id,
			'card_summary'                 => (object) $summary, // keep as JSON object even if empty
			'customer_email'               => (string) $order->get_billing_email(),
			'idempotency_key'              => 'wc_' . (string) $order->get_id(),
		);
		if ( empty( $body['customer_email'] ) ) {
			unset( $body['customer_email'] );
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'method'  => 'POST',
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->secret_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Exception( __( 'Could not reach Veyra. Please try again.', 'veyragate-pay' ) );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$raw         = wp_remote_retrieve_body( $response );
		$json        = json_decode( $raw, true );

		if ( $status_code >= 400 || ! is_array( $json ) ) {
			$msg = isset( $json['error']['message'] )
				? $json['error']['message']
				: ( isset( $json['message'] ) ? $json['message'] : __( 'Veyra rejected the payment.', 'veyragate-pay' ) );
			throw new Exception( esc_html( $msg ) );
		}

		// Confirm returns a merchant-safe envelope:
		//   { ok, status, public_status, message, redirect_url,
		//     transaction_id: "vcs_conf_<id>", session_id, livemode }
		// We surface status (preferring public_status), the synthetic
		// transaction id, the resolved session id, and the 3DS redirect.
		$public_status  = isset( $json['public_status'] )
			? sanitize_text_field( $json['public_status'] )
			: ( isset( $json['status'] ) ? sanitize_text_field( $json['status'] ) : 'succeeded' );
		$transaction_id = isset( $json['transaction_id'] )
			? sanitize_text_field( $json['transaction_id'] )
			: ( isset( $json['id'] ) ? sanitize_text_field( $json['id'] ) : 'veyra_session_' . $session_id );
		$resolved_sid   = isset( $json['session_id'] ) && is_string( $json['session_id'] )
			? sanitize_text_field( $json['session_id'] )
			: (string) $session_id;
		$redirect_url   = isset( $json['redirect_url'] ) && is_string( $json['redirect_url'] )
			? esc_url_raw( $json['redirect_url'] )
			: '';

		return array(
			'status'         => $public_status,
			'transaction_id' => $transaction_id,
			'session_id'     => $resolved_sid,
			'redirect_url'   => $redirect_url,
		);
	}

	/**
	 * Reduce the front-end-provided card summary to the safe shape the
	 * confirm endpoint expects. Unknown keys are dropped so we send a
	 * minimal, predictable `card_summary` (brand / last4 / expiry /
	 * funding / authentication / issuer country) — never PAN or CVC.
	 */
	private function sanitize_card_summary( $summary ) {
		if ( ! is_array( $summary ) ) {
			return array();
		}
		$out = array();
		if ( isset( $summary['brand'] ) && is_string( $summary['brand'] ) ) {
			$out['brand'] = substr( sanitize_text_field( $summary['brand'] ), 0, 40 );
		}
		if ( isset( $summary['last4'] ) && is_string( $summary['last4'] ) ) {
			$digits = preg_replace( '/[^0-9]/', '', $summary['last4'] );
			if ( 4 === strlen( $digits ) ) {
				$out['last4'] = $digits;
			}
		}
		if ( isset( $summary['expiration_month'] ) ) {
			$m = (int) $summary['expiration_month'];
			if ( $m >= 1 && $m <= 12 ) {
				$out['expiration_month'] = $m;
			}
		}
		if ( isset( $summary['expiration_year'] ) ) {
			$y = (int) $summary['expiration_year'];
			$now_year = (int) gmdate( 'Y' );
			if ( $y >= $now_year - 1 && $y <= $now_year + 30 ) {
				$out['expiration_year'] = $y;
			}
		}
		if ( isset( $summary['funding'] ) && in_array( $summary['funding'], array( 'credit', 'debit', 'prepaid', 'unknown' ), true ) ) {
			$out['funding'] = $summary['funding'];
		}
		if ( isset( $summary['authentication'] ) && is_string( $summary['authentication'] ) ) {
			$out['authentication'] = substr( sanitize_text_field( $summary['authentication'] ), 0, 40 );
		}
		foreach ( array( 'issuer_country', 'issuer_country_alpha2' ) as $cc_key ) {
			if ( isset( $summary[ $cc_key ] ) && is_string( $summary[ $cc_key ] ) ) {
				$cc = strtoupper( preg_replace( '/[^A-Za-z]/', '', $summary[ $cc_key ] ) );
				if ( 2 === strlen( $cc ) ) {
					$out[ $cc_key ] = $cc;
				}
			}
		}
		return $out;
	}

	/**
	 * Compute the canonical `cart_hash` HMAC over the cart for the
	 * /api/v1/checkout_sessions create request.
	 *
	 * The canonicalization MUST match the server-side verifier in
	 * `app/api/v1/checkout_sessions/route.ts::verifyCartHash`:
	 *
	 *   payload  = "<amount_cents>.<currency-lower>"
	 *   cart_hash = hash_hmac('sha256', payload, $secret_key)  // hex
	 *
	 * The HMAC key is the merchant's API secret (the same Bearer token
	 * used in the Authorization header). Currency is lowercased; the
	 * amount is rendered without separators or decimals. The server
	 * compares using `timingSafeEqual` over the hex-decoded bytes.
	 *
	 * Returns an empty string when the secret key is not configured —
	 * callers should omit the field in that branch (which is the test /
	 * playground branch only; live mode short-circuits earlier).
	 *
	 * @param int    $amount_cents Cart total in minor units.
	 * @param string $currency     ISO-4217 alpha-3 currency code.
	 * @return string Hex HMAC-SHA256 digest, lowercase, or '' when
	 *                no secret key is configured.
	 */
	private function compute_cart_hash( $amount_cents, $currency ) {
		if ( empty( $this->secret_key ) ) {
			return '';
		}
		$payload = (string) (int) $amount_cents . '.' . strtolower( (string) $currency );
		return hash_hmac( 'sha256', $payload, (string) $this->secret_key );
	}

	/**
	 * Apple Pay express (0.5.7) — shared live-mode fail-closed guard for
	 * the express AJAX endpoints. Mirrors process_payment() (line ~470):
	 * in live mode we refuse to proceed without a secret key AND a
	 * webhook secret, because without them we cannot tell a real charge
	 * from a development mock and MUST NOT drift into mock-success on a
	 * live storefront. Returns true when the endpoint may proceed.
	 *
	 * NOTE: there is NO is_test_mode() method on this gateway — the live
	 * flag is derived from the `vgs_environment` option exactly as in
	 * process_payment(). Do not call a non-existent is_test_mode().
	 *
	 * @return bool True when configured; false means the caller already
	 *              sent a 503 response and should return.
	 */
	private function express_live_mode_ok() {
		$is_live = 'live' === $this->get_option( 'vgs_environment', 'test' );
		if ( $is_live && ( empty( $this->secret_key ) || empty( $this->webhook_secret ) ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Apple Pay express (0.5.7) — create a pending WC order from the
	 * current cart so the wallet charge has a real order to bind BEFORE
	 * any money moves. Mirrors WC_Checkout order population closely
	 * enough that the order total equals the cart total to the cent.
	 *
	 * Guards:
	 *   - nonce (`veyragate_pay_express`) → 403 bad_nonce
	 *   - empty cart → 400 empty_cart
	 *   - live-mode-without-secrets → 503 not_configured
	 * Idempotency: a still-pending express order bound to this WC session
	 * is returned as-is rather than minting a duplicate (re-fires on
	 * drawer re-open / cart change reuse the same order).
	 *
	 * Emits wp_send_json_* + die().
	 */
	public function handle_express_order_ajax() {
		if ( ! check_ajax_referer( 'veyragate_pay_express', 'nonce', false ) ) {
			wp_send_json_error( array( 'reason' => 'bad_nonce' ), 403 );
			return;
		}
		if ( ! $this->express_live_mode_ok() ) {
			wp_send_json_error( array( 'reason' => 'not_configured' ), 503 );
			return;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			wp_send_json_error( array( 'reason' => 'empty_cart' ), 400 );
			return;
		}

		// Idempotency — reuse a still-pending express order bound to this
		// WC session instead of minting a duplicate on every drawer-open
		// or cart-fragment refresh.
		if ( WC()->session ) {
			$existing_id = absint( WC()->session->get( 'veyragate_express_order_id' ) );
			if ( $existing_id ) {
				$existing = wc_get_order( $existing_id );
				if ( $existing && $existing->has_status( 'pending' ) && 'apple_pay' === $existing->get_meta( '_veyragate_express_lane' ) ) {
					// Refresh totals against the current cart so the amount
					// stays authoritative after a qty/coupon change.
					$this->populate_express_order_from_cart( $existing );
					wp_send_json_success(
						array(
							'wc_order_id' => $existing->get_id(),
							'amount_cents' => (int) round( (float) $existing->get_total( 'edit' ) * 100 ),
							'currency'    => strtolower( $existing->get_currency() ),
						)
					);
					return;
				}
			}
		}

		WC()->cart->calculate_totals();

		$order = wc_create_order( array( 'status' => 'pending' ) );
		if ( is_wp_error( $order ) || ! $order ) {
			wp_send_json_error( array( 'reason' => 'order_create_failed' ), 502 );
			return;
		}
		$order->update_meta_data( '_veyragate_express_lane', 'apple_pay' );
		$this->populate_express_order_from_cart( $order );

		if ( WC()->session ) {
			WC()->session->set( 'veyragate_express_order_id', $order->get_id() );
		}

		wp_send_json_success(
			array(
				'wc_order_id' => $order->get_id(),
				'amount_cents' => (int) round( (float) $order->get_total( 'edit' ) * 100 ),
				'currency'    => strtolower( $order->get_currency() ),
			)
		);
	}

	/**
	 * Apple Pay express (0.5.7) — copy the current cart's line items,
	 * shipping, fees, coupons and taxes onto an express order so the
	 * order total equals the cart total to the cent. Mirrors the subset
	 * of WC_Checkout::create_order() needed for a wallet express charge.
	 *
	 * Re-callable: clears prior express line items first so a re-fire
	 * after a cart change rebuilds cleanly rather than stacking lines.
	 *
	 * @param WC_Order $order The pending express order.
	 */
	private function populate_express_order_from_cart( WC_Order $order ) {
		$cart = WC()->cart;
		if ( ! $cart ) {
			return;
		}
		$cart->calculate_totals();

		// Clear any previously-copied lines (idempotent rebuild).
		foreach ( $order->get_items( array( 'line_item', 'fee', 'shipping', 'coupon', 'tax' ) ) as $existing_item ) {
			$order->remove_item( $existing_item->get_id() );
		}

		// Line items.
		foreach ( $cart->get_cart() as $cart_item_key => $values ) {
			$product = isset( $values['data'] ) ? $values['data'] : null;
			if ( ! $product ) {
				continue;
			}
			$qty       = isset( $values['quantity'] ) ? (int) $values['quantity'] : 1;
			$item_args = array(
				'subtotal'     => isset( $values['line_subtotal'] ) ? $values['line_subtotal'] : 0,
				'total'        => isset( $values['line_total'] ) ? $values['line_total'] : 0,
				'subtotal_tax' => isset( $values['line_subtotal_tax'] ) ? $values['line_subtotal_tax'] : 0,
				'total_tax'    => isset( $values['line_tax'] ) ? $values['line_tax'] : 0,
			);
			if ( isset( $values['line_tax_data'] ) ) {
				$item_args['taxes'] = $values['line_tax_data'];
			}
			$order->add_product( $product, $qty, $item_args );
		}

		// Fees.
		foreach ( $cart->get_fees() as $fee_key => $fee ) {
			$item = new WC_Order_Item_Fee();
			$item->set_name( $fee->name );
			$item->set_amount( $fee->amount );
			$item->set_total( $fee->total );
			$item->set_tax_class( isset( $fee->tax_class ) ? $fee->tax_class : '' );
			if ( isset( $fee->tax_data ) ) {
				$item->set_taxes( array( 'total' => $fee->tax_data ) );
			}
			$order->add_item( $item );
		}

		// Shipping — copy the chosen packages/rates if present.
		$packages = WC()->shipping() ? WC()->shipping()->get_packages() : array();
		$chosen   = WC()->session ? (array) WC()->session->get( 'chosen_shipping_methods' ) : array();
		foreach ( $packages as $package_key => $package ) {
			if ( ! isset( $chosen[ $package_key ] ) ) {
				continue;
			}
			$rate_id = $chosen[ $package_key ];
			if ( ! isset( $package['rates'][ $rate_id ] ) ) {
				continue;
			}
			$rate         = $package['rates'][ $rate_id ];
			$shipping_item = new WC_Order_Item_Shipping();
			$shipping_item->set_shipping_rate( $rate );
			$order->add_item( $shipping_item );
		}

		// Coupons.
		foreach ( $cart->get_coupons() as $code => $coupon ) {
			$order->apply_coupon( $code );
		}

		// Customer + addresses from the WC customer session (best effort).
		$customer = WC()->customer;
		if ( $customer ) {
			if ( method_exists( $customer, 'get_id' ) && $customer->get_id() ) {
				$order->set_customer_id( $customer->get_id() );
			}
			$order->set_address(
				array(
					'first_name' => $customer->get_billing_first_name(),
					'last_name'  => $customer->get_billing_last_name(),
					'company'    => $customer->get_billing_company(),
					'email'      => $customer->get_billing_email(),
					'phone'      => $customer->get_billing_phone(),
					'address_1'  => $customer->get_billing_address_1(),
					'address_2'  => $customer->get_billing_address_2(),
					'city'       => $customer->get_billing_city(),
					'state'      => $customer->get_billing_state(),
					'postcode'   => $customer->get_billing_postcode(),
					'country'    => $customer->get_billing_country(),
				),
				'billing'
			);
			$order->set_address(
				array(
					'first_name' => $customer->get_shipping_first_name(),
					'last_name'  => $customer->get_shipping_last_name(),
					'company'    => $customer->get_shipping_company(),
					'address_1'  => $customer->get_shipping_address_1(),
					'address_2'  => $customer->get_shipping_address_2(),
					'city'       => $customer->get_shipping_city(),
					'state'      => $customer->get_shipping_state(),
					'postcode'   => $customer->get_shipping_postcode(),
					'country'    => $customer->get_shipping_country(),
				),
				'shipping'
			);
		}

		$order->set_payment_method( self::GATEWAY_ID );
		$order->set_payment_method_title( $this->title );
		if ( method_exists( $cart, 'get_cart_hash' ) ) {
			$order->set_cart_hash( $cart->get_cart_hash() );
		}
		$order->calculate_totals();
		$order->save();
	}

	/**
	 * Apple Pay express (0.5.7) — mint a Veyra checkout session for the
	 * session-bound pending express order via the EXISTING hardened
	 * create_checkout_session_for_order(). Reusing the order-lane mint
	 * (rather than a new cart-twin path) means the metadata bag is
	 * already audited, the wc_order_id is already emitted (so the webhook
	 * binds), and wc_order_* refs stay local — no new metadata surface.
	 *
	 * Guards:
	 *   - nonce (`veyragate_pay_express`) → 403 bad_nonce
	 *   - live-mode-without-secrets → 503 not_configured
	 *   - the posted wc_order_id MUST be the session-bound pending express
	 *     order → otherwise 403 not_bound (IDOR guard against minting a
	 *     session for an arbitrary order id)
	 *   - amount below the 50¢ floor → 400 amount_too_low
	 *
	 * Emits wp_send_json_* + die().
	 */
	public function handle_express_session_ajax() {
		if ( ! check_ajax_referer( 'veyragate_pay_express', 'nonce', false ) ) {
			wp_send_json_error( array( 'reason' => 'bad_nonce' ), 403 );
			return;
		}
		if ( ! $this->express_live_mode_ok() ) {
			wp_send_json_error( array( 'reason' => 'not_configured' ), 503 );
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$wc_order_id = isset( $_POST['wc_order_id'] ) ? absint( wp_unslash( $_POST['wc_order_id'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( ! $wc_order_id ) {
			wp_send_json_error( array( 'reason' => 'missing_order' ), 400 );
			return;
		}

		// IDOR guard — the order MUST be the one this WC session created
		// via handle_express_order_ajax(), and it MUST still be pending.
		$bound_id = WC()->session ? absint( WC()->session->get( 'veyragate_express_order_id' ) ) : 0;
		if ( ! $bound_id || $bound_id !== $wc_order_id ) {
			wp_send_json_error( array( 'reason' => 'not_bound' ), 403 );
			return;
		}
		$order = wc_get_order( $wc_order_id );
		if ( ! $order || ! $order->has_status( 'pending' ) || 'apple_pay' !== $order->get_meta( '_veyragate_express_lane' ) ) {
			wp_send_json_error( array( 'reason' => 'not_bound' ), 403 );
			return;
		}

		$amount_cents = (int) round( (float) $order->get_total( 'edit' ) * 100 );
		if ( $amount_cents < 50 ) {
			wp_send_json_error( array( 'reason' => 'amount_too_low' ), 400 );
			return;
		}

		// MF-1 — reuse the bound session when the order + amount are unchanged.
		// The ONLY server-side double-charge guard is the per-session_id atomic
		// claim in pay-bt (it derives its own idempotency key from session_id and
		// ignores the client-sent one), so minting a fresh session for the same
		// order on every bootstrap re-run / cart re-mint / iOS double-fire would
		// create a SECOND independently-chargeable session bound to one order.
		// Reuse keeps exactly one chargeable session per (order, amount); a real
		// cart change re-populates the order to a new total (amount_cents differs)
		// and legitimately mints a fresh session below. (Long-term hardening:
		// per-wc_order_id charge-uniqueness server-side in pay-bt.)
		$existing_session = (string) $order->get_meta( '_veyragate_session_id' );
		$existing_amount  = (int) $order->get_meta( '_veyragate_session_amount_cents' );
		$existing_minted  = (int) $order->get_meta( '_veyragate_session_minted_at' );
		// MF-3 — only reuse a FRESH bound session. A Veyra checkout session
		// expires server-side; reusing a long-idle one makes
		// card_capture_config (and pay-bt) fail with "session_expired", so
		// the wallet button never renders and a tap can't charge. This bit
		// when a shopper re-opens an old checkout or reloads after the
		// session TTL lapsed (the bound order + amount are unchanged, so the
		// pre-MF-3 reuse handed back a dead session). A short reuse window
		// keeps MF-1's one-chargeable-session-per-(order,amount) guarantee
		// for rapid re-bootstraps / iOS double-fire (which happen within
		// seconds) while re-minting a fresh session once the old one ages.
		// Re-minting after expiry is double-charge-safe: an expired session
		// cannot be charged, and the client only ever holds the latest id.
		$session_reuse_ttl = 300; // seconds — well under any session TTL.
		$session_is_fresh  = $existing_minted > 0 && ( time() - $existing_minted ) < $session_reuse_ttl;
		if ( '' !== $existing_session && $existing_amount === $amount_cents && $session_is_fresh ) {
			wp_send_json_success(
				array(
					'session_id'         => $existing_session,
					'amount_cents'       => $amount_cents,
					'currency'           => strtolower( $order->get_currency() ),
					// MED-1 — keyed order-received URL so a guest lands on the
					// real thank-you page (the client can't compute the order key).
					'order_received_url' => $order->get_checkout_order_received_url(),
				)
			);
			return;
		}

		$public_id = $this->create_checkout_session_for_order( $order );
		if ( ! is_string( $public_id ) || '' === $public_id ) {
			wp_send_json_error( array( 'reason' => 'mint_failed' ), 502 );
			return;
		}
		$order->update_meta_data( '_veyragate_session_id', $public_id );
		$order->update_meta_data( '_veyragate_session_amount_cents', $amount_cents );
		$order->update_meta_data( '_veyragate_session_minted_at', time() );
		$order->save();

		wp_send_json_success(
			array(
				'session_id'         => $public_id,
				'amount_cents'       => $amount_cents,
				'currency'           => strtolower( $order->get_currency() ),
				'order_received_url' => $order->get_checkout_order_received_url(),
			)
		);
	}

	/**
	 * 0.5.7-hotfix — same-origin proxy for the session-scoped
	 * card_capture_config (GET). veyragate.com sends no CORS headers, so the
	 * browser cannot fetch it cross-origin; the wallet JS calls this proxy
	 * same-origin and we forward server-to-server, returning the upstream
	 * body verbatim so the JS sees the exact card_capture_config shape.
	 */
	public function handle_express_config_ajax() {
		if ( ! check_ajax_referer( 'veyragate_pay_express', 'nonce', false ) ) {
			wp_send_json_error( array( 'reason' => 'bad_nonce' ), 403 );
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$session_id = isset( $_REQUEST['session_id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['session_id'] ) ) : '';
		if ( '' === $session_id ) {
			wp_send_json_error( array( 'reason' => 'missing_session' ), 400 );
			return;
		}
		$url  = trailingslashit( untrailingslashit( $this->api_base_url ) ) . 'api/checkout/' . rawurlencode( $session_id ) . '/card_capture_config';
		$resp = wp_remote_get( $url, array( 'timeout' => 20, 'headers' => array( 'Accept' => 'application/json' ) ) );
		if ( is_wp_error( $resp ) ) {
			wp_send_json_error( array( 'reason' => 'upstream' ), 502 );
			return;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		status_header( $code > 0 ? $code : 200 );
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_remote_retrieve_body( $resp ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- verbatim upstream JSON
		exit;
	}

	/**
	 * 0.5.7-hotfix — same-origin proxy for the wallet charge (pay-bt POST).
	 * Reads a JSON body, forwards only the wallet-safe fields to the
	 * session-scoped /pay-bt server-to-server, and returns the upstream body
	 * verbatim ({ public_status, message, redirect_url }). SAUCE: forwards
	 * name + email + wallet_contact only; wallet_type is fixed to apple_pay.
	 */
	public function handle_express_pay_ajax() {
		if ( ! check_ajax_referer( 'veyragate_pay_express', 'nonce', false ) ) {
			wp_send_json_error( array( 'reason' => 'bad_nonce' ), 403 );
			return;
		}
		$raw = file_get_contents( 'php://input' );
		$in  = json_decode( (string) $raw, true );
		if ( ! is_array( $in ) ) {
			$in = array();
		}
		$session_id = isset( $in['session_id'] ) ? sanitize_text_field( $in['session_id'] ) : '';
		$token      = isset( $in['basis_theory_token_intent_id'] ) ? sanitize_text_field( $in['basis_theory_token_intent_id'] ) : '';
		if ( '' === $session_id || '' === $token ) {
			wp_send_json_error( array( 'reason' => 'missing_fields' ), 400 );
			return;
		}
		$payload = array(
			'basis_theory_token_intent_id' => $token,
			'wallet_type'                  => 'apple_pay',
			'card_summary'                 => array(),
		);
		if ( isset( $in['customer_name'] ) && is_string( $in['customer_name'] ) && '' !== $in['customer_name'] ) {
			$payload['customer_name'] = sanitize_text_field( $in['customer_name'] );
		}
		if ( isset( $in['customer_email'] ) && is_string( $in['customer_email'] ) && '' !== $in['customer_email'] ) {
			$payload['customer_email'] = sanitize_email( $in['customer_email'] );
		}
		if ( isset( $in['idempotency_key'] ) && is_string( $in['idempotency_key'] ) && '' !== $in['idempotency_key'] ) {
			$payload['idempotency_key'] = sanitize_text_field( $in['idempotency_key'] );
		}
		if ( isset( $in['wallet_contact'] ) && is_array( $in['wallet_contact'] ) ) {
			$payload['wallet_contact'] = $this->sanitize_wallet_contact( $in['wallet_contact'] );
		}
		$url  = trailingslashit( untrailingslashit( $this->api_base_url ) ) . 'api/checkout/' . rawurlencode( $session_id ) . '/pay-bt';
		$resp = wp_remote_post(
			$url,
			array(
				'method'  => 'POST',
				'timeout' => 45,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $resp ) ) {
			wp_send_json_error( array( 'reason' => 'upstream' ), 502 );
			return;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		status_header( $code > 0 ? $code : 200 );
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_remote_retrieve_body( $resp ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- verbatim upstream JSON
		exit;
	}

	/**
	 * Shallow-recursive sanitize of a decoded wallet_contact array (string
	 * values via sanitize_text_field), preserving the nested shipping shape.
	 * VeyraGate validates + normalizes server-side.
	 */
	private function sanitize_wallet_contact( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				$out[ sanitize_text_field( (string) $k ) ] = $this->sanitize_wallet_contact( $v );
			}
			return $out;
		}
		if ( is_string( $value ) ) {
			return sanitize_text_field( $value );
		}
		return $value;
	}

	/**
	 * Apple Pay express (0.5.7) — front-end enqueue for the mini-cart
	 * drawer wallet button. Site-wide on the front end so the drawer
	 * button works on ANY page that shows the mini-cart, NOT just
	 * checkout.
	 *
	 * Footprint guard: bails BEFORE enqueuing anything when we're in
	 * wp-admin, when the gateway is disabled, or when the mini-cart
	 * wallet opt-in (`enable_minicart_wallet`) is not 'yes'. On a store
	 * that hasn't turned it on, this method adds zero scripts.
	 */
	public function enqueue_minicart_assets() {
		if ( function_exists( 'is_admin' ) && is_admin() ) {
			return;
		}
		if ( 'yes' !== $this->enabled ) {
			return;
		}
		if ( 'yes' !== $this->get_option( 'enable_minicart_wallet', 'no' ) ) {
			return;
		}

		$assets_url = VEYRAGATE_PAY_PLUGIN_URL . 'assets/dist/';

		// Apple PassKit SDK + shared wallet core (dedup-safe — WP merges
		// by handle if the checkout enqueue already registered them).
		if ( ! wp_script_is( 'veyragate-applepay-sdk', 'registered' ) ) {
			wp_register_script(
				'veyragate-applepay-sdk',
				'https://applepay.cdn-apple.com/jsapi/1.latest/apple-pay-sdk.js',
				array(),
				null,
				true
			);
		}
		wp_enqueue_script( 'veyragate-applepay-sdk' );

		if ( ! wp_script_is( 'veyragate-pay-wallet-core', 'registered' ) ) {
			wp_register_script(
				'veyragate-pay-wallet-core',
				$assets_url . 'wallet-core.js',
				array(),
				VEYRAGATE_PAY_VERSION,
				true
			);
		}
		wp_enqueue_script( 'veyragate-pay-wallet-core' );

		wp_enqueue_script(
			'veyragate-pay-minicart',
			$assets_url . 'minicart.js',
			array( 'jquery', 'veyragate-pay-wallet-core' ),
			VEYRAGATE_PAY_VERSION,
			true
		);

		// The mini-cart drawer button reuses the shared wallet button
		// styles already shipped in classic.css. Enqueue it here too so
		// the drawer surface is styled even on pages that don't load the
		// checkout adapter.
		wp_enqueue_style(
			'veyragate-pay-classic',
			$assets_url . 'classic.css',
			array(),
			VEYRAGATE_PAY_VERSION
		);

		wp_localize_script(
			'veyragate-pay-minicart',
			'veyragatePayMiniCart',
			array(
				'apiBaseUrl'           => esc_url_raw( untrailingslashit( $this->api_base_url ) ),
				'expressOrderAjaxUrl'  => admin_url( 'admin-ajax.php' ),
				'expressSessionAjaxUrl' => admin_url( 'admin-ajax.php' ),
				'expressConfigAjaxUrl' => admin_url( 'admin-ajax.php' ),
				'expressPayAjaxUrl'    => admin_url( 'admin-ajax.php' ),
				'expressNonce'         => wp_create_nonce( 'veyragate_pay_express' ),
				'containerSelector'    => (string) $this->get_option( 'minicart_wallet_selector', '' ),
				'merchantNameFallback' => 'Secure Checkout',
				'enabled'              => 1,
			)
		);
	}

	/**
	 * Server-side fallback: create a Veyra checkout session for this
	 * order via POST /api/v1/checkout_sessions, using the merchant's
	 * secret key. Returns the session's public id (vcs_…) or empty.
	 *
	 * Phase 4.12u-followup — always sends a `cart_hash` HMAC so the
	 * session create succeeds even when the merchant has
	 * `require_cart_hash=true` on `veyragate_merchants`. The hash is
	 * computed via `compute_cart_hash()` below; the canonical payload
	 * matches the server-side verifier in
	 * `app/api/v1/checkout_sessions/route.ts`:
	 *   hash_hmac('sha256', "<amount_cents>.<currency-lower>", $secret_key)
	 */
	/**
	 * Build a human cart summary string from the order line items, e.g.
	 * "1x Coat Gloss Bites; 2x Bowl Oil". Truncated to 500 chars to stay
	 * within the session-metadata per-value limit. This is order context
	 * for the masked ops high-alert ONLY — it is kept local on the
	 * session row and is NEVER forwarded to the card processor.
	 *
	 * @param WC_Order $order The WooCommerce order.
	 * @return string Cart summary, max 500 chars.
	 */
	private function build_cart_lines_summary( WC_Order $order ) {
		$parts = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! is_a( $item, 'WC_Order_Item' ) ) {
				continue;
			}
			$name = method_exists( $item, 'get_name' ) ? (string) $item->get_name() : '';
			$qty  = method_exists( $item, 'get_quantity' ) ? (int) $item->get_quantity() : 1;
			$name = trim( wp_strip_all_tags( $name ) );
			if ( '' === $name ) {
				continue;
			}
			$parts[] = $qty . 'x ' . $name;
		}
		$summary = implode( '; ', $parts );
		// MB-SAFE truncation. A byte-based substr() can slice a multi-byte
		// UTF-8 character (emoji / accented product name) in half, producing
		// invalid UTF-8 that makes wp_json_encode() return false in
		// create_checkout_session_for_order() — which sends an empty body and
		// 400s the whole checkout closed. mb_substr() never splits a code
		// point, so the result is always valid UTF-8 and within the server's
		// 500-char metadata-value limit.
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $summary, 'UTF-8' ) > 500 ) {
			$summary = mb_substr( $summary, 0, 500, 'UTF-8' );
		} elseif ( ! function_exists( 'mb_strlen' ) && strlen( $summary ) > 500 ) {
			$summary = substr( $summary, 0, 500 );
		}
		return $summary;
	}

	/**
	 * Build the session `metadata` bag for the checkout-session create
	 * call. Two groups of keys, both within the server's ≤50-key limit:
	 *
	 *   §2a LOCAL order/customer context (kept on the session row, read
	 *   back by the masked high-alert emitter, NEVER forwarded to the
	 *   card processor — they are not on the Stripe metadata allowlist):
	 *     cart_lines, ship_line1, ship_city, ship_state, ship_zip,
	 *     cust_phone, cust_name, src_brand, src_route
	 *
	 *   §2b order references that ARE allowlisted through to the card
	 *   processor (the only three that survive the scrub):
	 *     wc_order_id, wc_order_number, wc_order_key
	 *
	 * Empty values are omitted so we never send blank metadata keys. No
	 * card data is ever placed here.
	 *
	 * @param WC_Order $order The WooCommerce order.
	 * @return array Metadata bag (string keys → string values).
	 */
	/**
	 * 2026-06-04 (wallet-shape parity) — build a `billing_details` /
	 * `shipping_details` payload from $order. Shape mirrors what the
	 * /v1/checkout_sessions Zod schema accepts:
	 *
	 *   {
	 *     name?:  string,
	 *     email?: string,
	 *     phone?: string,
	 *     address: {
	 *       line1?: string,
	 *       line2?: string,
	 *       city?:  string,
	 *       state?: string,
	 *       postal_code?: string,
	 *       country?: string,
	 *     }
	 *   }
	 *
	 * Empty values are omitted so the server never receives blank fields
	 * (Stripe rejects empty-string billing_details subkeys with
	 * `parameter_invalid_empty`). When $kind is "billing" the email comes
	 * from `$order->get_billing_email()`; "shipping" mode does NOT include
	 * an email (WooCommerce shipping addresses don't carry one). When
	 * "shipping" mode finds the shipping address blank, it falls back to
	 * the billing address (mirrors how WooCommerce treats virtual orders).
	 *
	 * @param WC_Order $order Order.
	 * @param string   $kind  'billing' or 'shipping'.
	 * @return array Empty array when nothing usable is set.
	 */
	private function build_party_details_from_order( WC_Order $order, $kind ) {
		$is_shipping = ( 'shipping' === $kind );
		if ( $is_shipping ) {
			$line1 = trim( (string) $order->get_shipping_address_1() );
			$line2 = trim( (string) $order->get_shipping_address_2() );
			$city  = trim( (string) $order->get_shipping_city() );
			$state = trim( (string) $order->get_shipping_state() );
			$zip   = trim( (string) $order->get_shipping_postcode() );
			$country = trim( (string) $order->get_shipping_country() );
			$name  = trim( (string) $order->get_formatted_shipping_full_name() );
			// Fall back to billing when the order has no shipping address
			// (virtual / digital orders); this mirrors how WooCommerce
			// itself treats those orders downstream.
			if ( '' === $line1 ) {
				$line1 = trim( (string) $order->get_billing_address_1() );
				$line2 = trim( (string) $order->get_billing_address_2() );
				$city  = trim( (string) $order->get_billing_city() );
				$state = trim( (string) $order->get_billing_state() );
				$zip   = trim( (string) $order->get_billing_postcode() );
				$country = trim( (string) $order->get_billing_country() );
				if ( '' === $name ) {
					$name = trim( (string) $order->get_formatted_billing_full_name() );
				}
			}
			$email = '';
			$phone = trim( (string) $order->get_billing_phone() );
		} else {
			$line1 = trim( (string) $order->get_billing_address_1() );
			$line2 = trim( (string) $order->get_billing_address_2() );
			$city  = trim( (string) $order->get_billing_city() );
			$state = trim( (string) $order->get_billing_state() );
			$zip   = trim( (string) $order->get_billing_postcode() );
			$country = trim( (string) $order->get_billing_country() );
			$name  = trim( (string) $order->get_formatted_billing_full_name() );
			$email = trim( (string) $order->get_billing_email() );
			$phone = trim( (string) $order->get_billing_phone() );
		}

		$address = array();
		if ( '' !== $line1 ) {
			$address['line1'] = $line1;
		}
		if ( '' !== $line2 ) {
			$address['line2'] = $line2;
		}
		if ( '' !== $city ) {
			$address['city'] = $city;
		}
		if ( '' !== $state ) {
			$address['state'] = $state;
		}
		if ( '' !== $zip ) {
			$address['postal_code'] = $zip;
		}
		if ( '' !== $country ) {
			$address['country'] = $country;
		}

		$party = array();
		if ( '' !== $name ) {
			$party['name'] = $name;
		}
		if ( '' !== $email ) {
			$party['email'] = $email;
		}
		if ( '' !== $phone ) {
			$party['phone'] = $phone;
		}
		if ( ! empty( $address ) ) {
			$party['address'] = $address;
		}
		// Empty array when the order carries no contact info at all —
		// caller omits the field on the JSON body in that case.
		return $party;
	}

	/**
	 * MB-safe truncate a metadata VALUE to the server's 500-char limit.
	 * Always returns valid UTF-8 (never splits a multi-byte code point) so the
	 * bag can be wp_json_encode()'d without the encode silently returning false.
	 * Mirrors the server's per-value clamp in app/api/v1/checkout_sessions.
	 *
	 * @param string $value Raw value.
	 * @return string Value clamped to <= 500 chars, valid UTF-8.
	 */
	private function clamp_metadata_value( $value ) {
		$value = (string) $value;
		if ( function_exists( 'mb_strlen' ) ) {
			if ( mb_strlen( $value, 'UTF-8' ) > 500 ) {
				$value = mb_substr( $value, 0, 500, 'UTF-8' );
			}
		} elseif ( strlen( $value ) > 500 ) {
			$value = substr( $value, 0, 500 );
		}
		return $value;
	}

	/**
	 * MB-safe truncate a metadata KEY to the server's 40-char limit.
	 *
	 * @param string $key Raw key.
	 * @return string Key clamped to <= 40 chars, valid UTF-8.
	 */
	private function clamp_metadata_key( $key ) {
		$key = (string) $key;
		if ( function_exists( 'mb_strlen' ) ) {
			if ( mb_strlen( $key, 'UTF-8' ) > 40 ) {
				$key = mb_substr( $key, 0, 40, 'UTF-8' );
			}
		} elseif ( strlen( $key ) > 40 ) {
			$key = substr( $key, 0, 40 );
		}
		return $key;
	}

	private function build_session_order_metadata( WC_Order $order ) {
		$meta = array();

		// §2b — allowlisted order references (reach the card processor).
		$meta['wc_order_id']     = (string) $order->get_id();
		$meta['wc_order_number'] = (string) $order->get_order_number();
		$meta['wc_order_key']    = (string) $order->get_order_key();

		// §2a — local order/customer context (kept local; feed high-alert).
		$cart_lines = $this->build_cart_lines_summary( $order );
		if ( '' !== $cart_lines ) {
			$meta['cart_lines'] = $cart_lines;
		}

		// Shipping address. Fall back to billing address only for the
		// street line when no shipping address is present, mirroring how
		// WooCommerce treats virtual / billing-only orders.
		$ship_line1 = trim( (string) $order->get_shipping_address_1() );
		$ship_city  = trim( (string) $order->get_shipping_city() );
		$ship_state = trim( (string) $order->get_shipping_state() );
		$ship_zip   = trim( (string) $order->get_shipping_postcode() );
		if ( '' !== $ship_line1 ) {
			$meta['ship_line1'] = $ship_line1;
		}
		if ( '' !== $ship_city ) {
			$meta['ship_city'] = $ship_city;
		}
		if ( '' !== $ship_state ) {
			$meta['ship_state'] = $ship_state;
		}
		if ( '' !== $ship_zip ) {
			$meta['ship_zip'] = $ship_zip;
		}

		// Customer contact. cust_name is the alert fallback when the
		// processor-side name is absent (it is on masked tiers).
		$cust_phone = trim( (string) $order->get_billing_phone() );
		if ( '' !== $cust_phone ) {
			$meta['cust_phone'] = $cust_phone;
		}
		$cust_name = trim( (string) $order->get_formatted_billing_full_name() );
		if ( '' !== $cust_name ) {
			$meta['cust_name'] = $cust_name;
		}

		// Source context — store/brand label + the integration route.
		$src_brand = trim( (string) get_bloginfo( 'name' ) );
		if ( '' !== $src_brand ) {
			$meta['src_brand'] = $src_brand;
		}
		$meta['src_route'] = 'woocommerce';

		// DEFENSIVE SWEEP — clamp EVERY key (<=40) and value (<=500) so no
		// uncapped free-text field (src_brand from the site title, cust_name,
		// ship_*, cust_phone) can overflow the server's per-key/per-value
		// limits and 400 the session-create. mb-safe so the resulting bag is
		// always valid UTF-8 (a prerequisite for wp_json_encode not returning
		// false in create_checkout_session_for_order). Mirrors the server
		// schema: key <=40, value <=500, total <=50 keys.
		$clamped = array();
		foreach ( $meta as $key => $value ) {
			$ckey = $this->clamp_metadata_key( (string) $key );
			if ( '' === $ckey ) {
				continue; // server requires key min length 1
			}
			$clamped[ $ckey ] = $this->clamp_metadata_value( $value );
		}
		// HARD CAP our contribution well under the server's <=50 (it merges its
		// own keys — veyra_allowed_origin, shipping_methods, etc. — into the
		// same bag before that limit). We only build ~13 keys; this guarantees
		// the invariant can never be violated if the list grows.
		if ( count( $clamped ) > 40 ) {
			$clamped = array_slice( $clamped, 0, 40, true );
		}

		return $clamped;
	}

	/**
	 * Normalize the operator-entered channel tag to the exact shape the
	 * /api/v1/checkout_sessions create route accepts (zod `/^[a-z0-9_]+$/`,
	 * max 60). A non-conforming value — uppercase, spaces, hyphens,
	 * punctuation — would 400 the session-create, which (because the WC
	 * server-side create runs on EVERY order) fails the whole checkout
	 * closed. So we COERCE instead of forwarding verbatim: lowercase + trim,
	 * collapse every run of disallowed chars to a single underscore, trim
	 * stray underscores, cap at 60. e.g. "Acme Masked" / "acme-masked" ->
	 * "acme_masked". Returns '' when nothing usable remains (caller omits the
	 * field, so the column falls back to its "hosted" default).
	 *
	 * @param string $raw Operator-entered tag.
	 * @return string Normalized tag, or '' when empty.
	 */
	private function normalize_channel_tag( $raw ) {
		$value = strtolower( trim( (string) $raw ) );
		if ( '' === $value ) {
			return '';
		}
		$value = preg_replace( '/[^a-z0-9_]+/', '_', $value );
		$value = trim( $value, '_' );
		if ( strlen( $value ) > 60 ) {
			$value = rtrim( substr( $value, 0, 60 ), '_' );
		}
		return $value;
	}

	/**
	 * WooCommerce Settings API save-time sanitizer for the `channel` field.
	 * process_admin_options() auto-invokes validate_<key>_field() before
	 * persisting, so the STORED option is normalized to the server's accepted
	 * shape and the operator sees the clean value after saving. Belt-and-
	 * suspenders with the send-time normalize in
	 * create_checkout_session_for_order().
	 *
	 * @param string $key   Field key ('channel').
	 * @param string $value Raw posted value.
	 * @return string Normalized tag, or '' when empty.
	 */
	public function validate_channel_field( $key, $value ) {
		return $this->normalize_channel_tag( $value );
	}

	private function create_checkout_session_for_order( WC_Order $order ) {
		$endpoint     = trailingslashit( untrailingslashit( $this->api_base_url ) ) . 'api/v1/checkout_sessions';
		$amount_cents = (int) round( (float) $order->get_total() * 100 );
		$currency     = strtolower( $order->get_currency() );
		$body = array(
			'amount_cents' => $amount_cents,
			'currency'     => $currency,
			// WC card-only masked build (2026-06-01) — the session metadata
			// carries the FULL order/customer record so the server can keep
			// it locally (records / risk / fulfillment / masked high-alert).
			// The Stripe-bound allowlist strips everything except the three
			// wc_order_* refs before any card-network payload is built, so
			// cart_lines / ship_* / cust_* / src_* never reach the processor.
			'metadata'     => $this->build_session_order_metadata( $order ),
		);
		// 2026-06-04 (wallet-shape parity, plugin v0.5.1) — forward
		// billing_details + shipping_details from WooCommerce's own
		// checkout form so the Stripe card lane sends the SAME shape
		// Apple Pay already sends (full name + email + phone + address).
		// The /v1/checkout_sessions create route persists these onto the
		// session row's metadata; the /confirm route reads them back and
		// forwards them through confirm-bt -> runBtPayment ->
		// buildBtProxyForm, where the `customerIdentityScope === "name_email"`
		// branch (tier_3/4 default) emits them on
		// `payment_method_data[billing_details]`. The Veyra Pay iframe
		// stays card-only — these come from WooCommerce's own $order,
		// not from any new iframe field. AMEX issuers require AVS data
		// to authorize CNP; without this forwarding the AMEX rail hits
		// 100% do_not_honor on masked tiers.
		$billing = $this->build_party_details_from_order( $order, 'billing' );
		if ( ! empty( $billing ) ) {
			$body['billing_details'] = $billing;
		}
		$shipping = $this->build_party_details_from_order( $order, 'shipping' );
		if ( ! empty( $shipping ) ) {
			$body['shipping_details'] = $shipping;
		}
		// WC card-only masked build (2026-06-01) — top-level masked channel
		// tag. Omitted when the merchant has not set one (the session
		// column then defaults to the standard hosted channel server-side).
		// Normalized to the server's accepted shape so a hand-entered value
		// can never 400 the session-create and fail the whole checkout closed
		// (this create runs on every WC order).
		$channel = $this->normalize_channel_tag( $this->get_option( 'channel', '' ) );
		if ( '' !== $channel ) {
			$body['channel'] = $channel;
		}
		$cart_hash = $this->compute_cart_hash( $amount_cents, $currency );
		if ( ! empty( $cart_hash ) ) {
			$body['cart_hash'] = $cart_hash;
		}
		if ( $order->get_billing_email() ) {
			$body['customer_email'] = (string) $order->get_billing_email();
		}

		$encoded_body = wp_json_encode( $body );
		if ( false === $encoded_body ) {
			// Last-resort guard: a value somewhere in $body was invalid UTF-8
			// (wp_json_encode returns false rather than throwing). Sending the
			// literal false as the body would 400 the create and fail the
			// checkout closed. Re-encode with substitution so we degrade to a
			// valid (if lossy) body instead of an empty one. The metadata
			// builder already mb-clamps every value, so this should never fire.
			$encoded_body = wp_json_encode( $body, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE );
			if ( false === $encoded_body ) {
				$this->log( 'Could not JSON-encode checkout-session body for order ' . $order->get_id() . ' (invalid UTF-8); aborting session create.' );
				return '';
			}
		}
		$response = wp_remote_post(
			$endpoint,
			array(
				'method'  => 'POST',
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->secret_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => $encoded_body,
			)
		);
		if ( is_wp_error( $response ) ) {
			$this->log( 'Could not create checkout session for order ' . $order->get_id() . ': ' . $response->get_error_message() );
			return '';
		}
		$status_code = wp_remote_retrieve_response_code( $response );
		$json        = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status_code >= 400 || ! is_array( $json ) ) {
			return '';
		}
		if ( isset( $json['public_id'] ) && is_string( $json['public_id'] ) ) {
			return sanitize_text_field( $json['public_id'] );
		}
		if ( isset( $json['id'] ) && is_string( $json['id'] ) ) {
			return sanitize_text_field( $json['id'] );
		}
		return '';
	}

	/**
	 * Refund a WooCommerce order via Veyra. Supports full and partial
	 * refunds. The merchant's WooCommerce admin (or a programmatic call
	 * to `wc_create_refund`) triggers this through WooCommerce's
	 * standard refund pipeline.
	 *
	 * Returns:
	 *   true        on success
	 *   WP_Error    on failure (WooCommerce displays the message)
	 *
	 * No card data is ever read here — only the stored token / session
	 * IDs.
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'veyragate_refund_no_order', __( 'Order not found.', 'veyragate-pay' ) );
		}

		$tx_id     = $order->get_meta( '_veyragate_transaction_id' );
		$tok_id    = $order->get_meta( '_veyragate_token_id' );
		$session_id = $order->get_meta( '_veyragate_session_id' );
		$mode       = $order->get_meta( '_veyragate_tokenization_mode' );

		if ( empty( $tx_id ) && empty( $tok_id ) && empty( $session_id ) ) {
			return new WP_Error( 'veyragate_refund_no_charge', __( 'No Veyra transaction id is attached to this order.', 'veyragate-pay' ) );
		}

		$amount_cents = $amount !== null
			? (int) round( (float) $amount * 100 )
			: (int) round( (float) $order->get_total() * 100 );

		if ( empty( $this->secret_key ) ) {
			// Test-mode (no live secret) — emit a mock refund so the
			// harness can prove the full refund path. Clearly labelled
			// so it cannot be mistaken for a real refund.
			$mock_id = 'veyra_test_refund_' . substr( wp_generate_password( 20, false, false ), 0, 16 );
			$order->add_order_note(
				sprintf(
					/* translators: %1$s mock refund id, %2$d amount cents, %3$s mode */
					__( 'Veyra mock refund recorded: %1$s for %2$d cents (mode: %3$s). Live refund will run when a real secret key is configured.', 'veyragate-pay' ),
					$mock_id,
					$amount_cents,
					$mode ? $mode : 'unknown'
				)
			);
			$order->update_meta_data( '_veyragate_last_refund_id', $mock_id );
			$order->update_meta_data( '_veyragate_last_refund_amount_cents', $amount_cents );
			$order->update_meta_data( '_veyragate_last_refund_mode', 'mock' );
			$order->save();
			return true;
		}

		$endpoint = trailingslashit( untrailingslashit( $this->api_base_url ) ) . 'api/v1/refunds';
		$body     = array(
			'charge'      => $tx_id,
			'token_id'    => $tok_id,
			'session_id'  => $session_id,
			'amount'      => $amount_cents,
			'reason'      => is_string( $reason ) ? $reason : '',
			'metadata'    => array(
				'woocommerce_order_id'     => (string) $order->get_id(),
				'woocommerce_order_number' => (string) $order->get_order_number(),
				'integration'              => 'woocommerce',
			),
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'method'  => 'POST',
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->secret_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'veyragate_refund_network', __( 'Could not reach Veyra.', 'veyragate-pay' ) );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$raw         = wp_remote_retrieve_body( $response );
		$json        = json_decode( $raw, true );

		if ( $status_code >= 400 || ! is_array( $json ) ) {
			$msg = is_array( $json ) && isset( $json['error']['message'] ) ? $json['error']['message'] : __( 'Veyra rejected the refund.', 'veyragate-pay' );
			return new WP_Error( 'veyragate_refund_failed', esc_html( $msg ) );
		}

		$refund_id = isset( $json['id'] ) ? sanitize_text_field( $json['id'] ) : 'veyra_refund_unknown';
		$order->add_order_note(
			sprintf(
				/* translators: %1$s refund id, %2$d amount cents */
				__( 'Veyra refund issued: %1$s for %2$d cents.', 'veyragate-pay' ),
				$refund_id,
				$amount_cents
			)
		);
		$order->update_meta_data( '_veyragate_last_refund_id', $refund_id );
		$order->update_meta_data( '_veyragate_last_refund_amount_cents', $amount_cents );
		$order->update_meta_data( '_veyragate_last_refund_mode', 'live' );
		$order->save();

		return true;
	}

	public function handle_webhook() {
		$raw       = file_get_contents( 'php://input' );
		$signature = isset( $_SERVER['HTTP_VEYRAGATE_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_VEYRAGATE_SIGNATURE'] ) ) : '';

		if ( ! empty( $this->webhook_secret ) && ! $this->verify_signature( $raw, $signature, $this->webhook_secret ) ) {
			status_header( 400 );
			echo 'invalid signature';
			exit;
		}

		$payload = json_decode( $raw, true );
		if ( ! is_array( $payload ) ) {
			status_header( 400 );
			echo 'invalid json';
			exit;
		}

		$event_type = isset( $payload['type'] ) ? sanitize_text_field( $payload['type'] ) : '';
		$object     = isset( $payload['data']['object'] ) && is_array( $payload['data']['object'] ) ? $payload['data']['object'] : array();
		$metadata   = isset( $object['metadata'] ) && is_array( $object['metadata'] ) ? $object['metadata'] : array();

		// WC card-only masked build (2026-06-01) — the order id key that
		// actually arrives is `wc_order_id`: it is the only WooCommerce
		// order reference on the Stripe metadata allowlist
		// (STRIPE_METADATA_ALLOWLIST), so any charge-derived merchant
		// webhook carries it. `woocommerce_order_id` is read as a
		// back-compat fallback for older emitters. When no order-id
		// reference is present we reconcile by the session id stored on
		// the order at confirm time — the synchronous confirm path is the
		// primary completion route, so the webhook is a best-effort
		// backstop and must fail soft when it cannot bind an order.
		$order = null;

		$order_id = 0;
		if ( isset( $metadata['wc_order_id'] ) ) {
			$order_id = absint( $metadata['wc_order_id'] );
		} elseif ( isset( $metadata['woocommerce_order_id'] ) ) {
			$order_id = absint( $metadata['woocommerce_order_id'] );
		}
		if ( $order_id ) {
			$order = wc_get_order( $order_id );
		}

		// Fallback: reconcile by the session id carried on the event (the
		// confirm response stores it as `_veyragate_session_id` on the
		// order). Accept either `session_id` or `checkout_session_id`.
		if ( ! $order ) {
			$session_ref = '';
			if ( isset( $metadata['session_id'] ) && is_string( $metadata['session_id'] ) ) {
				$session_ref = sanitize_text_field( $metadata['session_id'] );
			} elseif ( isset( $object['checkout_session_id'] ) && is_string( $object['checkout_session_id'] ) ) {
				$session_ref = sanitize_text_field( $object['checkout_session_id'] );
			} elseif ( isset( $object['session_id'] ) && is_string( $object['session_id'] ) ) {
				$session_ref = sanitize_text_field( $object['session_id'] );
			}
			if ( '' !== $session_ref ) {
				$order = $this->find_order_by_session_id( $session_ref );
			}
		}

		if ( ! $order ) {
			// No bindable order reference — ack without acting so the
			// emitter stops retrying. The confirm path already completed
			// the order synchronously in the common case.
			status_header( 202 );
			echo 'ignored';
			exit;
		}

		$transaction_id = isset( $object['id'] ) ? sanitize_text_field( $object['id'] ) : '';

		if ( in_array( $event_type, array( 'charge.succeeded', 'payment.succeeded' ), true ) ) {
			if ( ! $order->is_paid() ) {
				$order->payment_complete( $transaction_id );
			}
			$order->add_order_note(
				sprintf(
					/* translators: %s event type */
					__( 'Veyra confirmed payment. Event: %s', 'veyragate-pay' ),
					$event_type
				)
			);
		} elseif ( in_array( $event_type, array( 'charge.failed', 'payment.failed' ), true ) ) {
			$order->update_status( 'failed', __( 'Veyra reported payment failure.', 'veyragate-pay' ) );
		} elseif ( in_array( $event_type, array( 'charge.refunded', 'refund.succeeded' ), true ) ) {
			$order->add_order_note( __( 'Veyra reported a refund.', 'veyragate-pay' ) );
		}

		status_header( 200 );
		echo 'ok';
		exit;
	}

	/**
	 * Resolve a WooCommerce order from a Veyra checkout session id. Used
	 * as the webhook reconciliation fallback when the event carries no
	 * `wc_order_id` metadata — the confirm response stores the session id
	 * on the order as `_veyragate_session_id`, so we look up by that meta
	 * key. Returns the order, or null when no order matches.
	 *
	 * @param string $session_id The Veyra checkout session id.
	 * @return WC_Order|null
	 */
	private function find_order_by_session_id( $session_id ) {
		$session_id = trim( (string) $session_id );
		if ( '' === $session_id ) {
			return null;
		}
		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'return'     => 'objects',
				'meta_key'   => '_veyragate_session_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $session_id,             // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		if ( is_array( $orders ) && ! empty( $orders ) ) {
			$candidate = $orders[0];
			return is_a( $candidate, 'WC_Order' ) ? $candidate : null;
		}
		return null;
	}

	private function verify_signature( $raw_body, $header, $secret ) {
		if ( empty( $header ) ) {
			return false;
		}

		$timestamp = null;
		$signature = null;
		foreach ( explode( ',', $header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			if ( 't' === $pair[0] ) {
				$timestamp = absint( $pair[1] );
			}
			if ( 'v1' === $pair[0] ) {
				$signature = $pair[1];
			}
		}

		if ( ! $timestamp || ! $signature || abs( time() - $timestamp ) > 300 ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $raw_body, $secret );
		return hash_equals( $expected, $signature );
	}

	private function log( $message ) {
		if ( ! $this->debug || ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		// Defensive: redact anything that looks like a long digit sequence.
		$safe = is_string( $message )
			? preg_replace( '/\d{9,}/', '<redacted>', $message )
			: $message;
		wc_get_logger()->debug( $safe, array( 'source' => self::GATEWAY_ID ) );
	}
}
