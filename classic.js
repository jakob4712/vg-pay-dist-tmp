/**
 * Veyra Hosted Fields — WooCommerce classic checkout adapter.
 *
 * Mounts Veyra Hosted Fields inside #veyra-card-fields, tokenizes
 * before WooCommerce submits the order, populates the hidden inputs
 * so process_payment() receives only safe metadata.
 *
 * The WooCommerce "Place order" button stays as the only CTA. This
 * file only hooks the existing submit flow to insert a tokenize step
 * before form submission.
 *
 * Phase 4.12y2 (2026-05-19) — Apple Pay parity with the in-app hosted
 * iframe lane. When the server-side
 * /api/checkout/<id>/card_capture_config returns wallets.enable_wallets
 * = true (tier_1/2 only), this adapter renders an Apple Pay button
 * ABOVE the card iframe. Clicking the button drives BT's native Apple
 * Pay tokenization, then submits the same WooCommerce form with
 * `veyra_wallet_type` set so the PHP gateway forwards it to /pay-bt.
 * The SAUCE refusal on tier_3+ is enforced both at render time (server
 * returns enable_wallets=false, we render nothing) and again
 * server-side on /pay-bt (422 with wallet_disallowed_by_policy on a
 * wallet-flagged request).
 *
 * Phase 4.12y3 (2026-05-19) — BT-managed wallet mode. The Apple Pay
 * client driver no longer needs a per-merchant Apple identifier — BT
 * owns the Apple Pay merchant identifier + signing cert server-side
 * (configured once at portal.basistheory.com). The client sends
 * `display_name` + `domain` (window.location.host) on
 * /apple-pay/session; BT signs under its managed-cert tenant.
 *
 * F47 (2026-05-19) — Google Pay removed. Apple-Pay-only mode.
 *
 * F240 (2026-05-23) — Visible brand-icon badge.
 *
 * BT's combined CardElement (the single iframe owning card-number + MM/YY
 * + CVC) does NOT natively paint a brand icon inside its iframe — that
 * feature only exists on the standalone `cardNumber` element via its
 * `iconPosition` option. So even with cardTypes + validateOnChange wired
 * correctly per F211, the customer never sees a Visa / Mastercard / Amex
 * / Discover badge anywhere as they type. They DID see the BIN-based
 * brand detection working (card-number max length caps at 16/15/etc. as
 * the BIN identifies), but the visible confirmation was missing.
 *
 * The fix below renders a small inline-SVG badge OUTSIDE the BT iframe,
 * just above the card field, and subscribes to the SDK's `onValid`
 * callback to swap the badge based on `card_brand` from each change
 * event. The SDK already surfaces `card_brand` on the `valid` payload
 * (see hosted-fields.js v0.4.7-bt change notes) — we just weren't
 * consuming it on the WC surface.
 *
 * SAQ-A: the badge lives on the merchant page (our DOM), and only
 * displays metadata BT emits (brand string). No PAN, no Luhn check, no
 * card-number length leaks across the iframe boundary.
 *
 * F286 (2026-05-23) — Conversion polish wave.
 *
 * On top of the F240 baseline, F286 ships:
 *   - Descriptor disclosure UNDER the iframe: "Your bank will show a
 *     charge from {merchant}." Tier-aware — merchant can pass a
 *     descriptor override (mask-pool name) via data-veyra-descriptor.
 *   - Loading shimmer on the mount target until BT's `ready` fires.
 *   - Persisted last-payment-method (card vs ACH) in localStorage so
 *     return visitors land on the same lane.
 *   - Sticky Pay-button container on mobile viewports.
 *   - Smooth focus transitions on the iframe wrapper (via CSS in
 *     classic.css, no JS needed).
 *
 * 2026-05-25 — trust-strip / assurance-microcopy painter REMOVED. The
 * customer surface no longer carries "Encrypted in your browser",
 * "PCI-DSS compliant", or "Refunds processed within ..." lines (these
 * were deleted from the app surface in the F286 cleanup).
 *
 * Brand-clean: no "Veyra" / "Stripe" leaks in any rendered string per
 * docs/BRANDING.md customer-surface rules.
 */
(function () {
  "use strict";

  /**
   * Detect Apple Pay availability. Safe to call before user interaction;
   * canMakePayments() is synchronous and returns true on Safari + macOS
   * Ventura/Sonoma + iOS with a paired Apple Pay-capable device.
   */
  function isAppleWalletAvailable() {
    if (typeof window === "undefined") return false;
    var session = window.ApplePaySession;
    if (!session) return false;
    try {
      return typeof session.canMakePayments === "function"
        ? Boolean(session.canMakePayments())
        : false;
    } catch (_e) {
      return false;
    }
  }

  /**
   * Drive an Apple Pay session. Mirrors lib/basis-theory/wallets.ts so
   * the WC plugin lane and the in-app iframe lane share the same BT
   * wallet endpoints and the same protocol shape.
   *
   * Phase 4.12y3 — BT-managed mode: the `/apple-pay/session` POST body
   * is `display_name` + `domain` + `validation_url`. No
   * `merchant_identifier` (BT signs under its managed-cert tenant). No
   * `merchant_registration_id` (that's BYOK-only).
   *
   * @param {Object} args
   * @param {string} args.basisTheoryPublicKey
   * @param {"test"|"production"} args.environment
   * @param {number} args.amountCents
   * @param {string} args.currency
   * @param {string} args.merchantName
   * @param {string} args.domain - Fully-qualified host of the page hosting the Apple Pay button (window.location.host).
   * @param {string} [args.countryCode]
   * @returns {Promise<{ tokenId: string, walletType: "apple_pay" }>}
   */
  function createApplePaySession(args) {
    if (typeof window === "undefined") {
      return Promise.reject(new Error("apple_pay_unavailable_ssr"));
    }
    var ApplePaySession = window.ApplePaySession;
    if (!ApplePaySession) {
      return Promise.reject(new Error("apple_pay_unavailable"));
    }
    var country = (args.countryCode || "US").toUpperCase();
    var currency = String(args.currency || "USD").toUpperCase();
    var amountString = (args.amountCents / 100).toFixed(2);

    var paymentRequest = {
      countryCode: country,
      currencyCode: currency,
      supportedNetworks: ["visa", "masterCard", "amex", "discover"],
      merchantCapabilities: ["supports3DS"],
      total: { label: args.merchantName, amount: amountString, type: "final" },
    };

    var session = new ApplePaySession(3, paymentRequest);

    var btHost =
      args.environment === "production"
        ? "https://api.basistheory.com"
        : "https://api.test.basistheory.com";

    return new Promise(function (resolve, reject) {
      var settled = false;
      function settle(fn) {
        if (settled) return;
        settled = true;
        fn();
      }

      session.onvalidatemerchant = function (event) {
        fetch(btHost + "/apple-pay/session", {
          method: "POST",
          headers: {
            "BT-API-KEY": args.basisTheoryPublicKey,
            "Content-Type": "application/json",
          },
          body: JSON.stringify({
            validation_url: event && event.validationURL,
            display_name: args.merchantName,
            domain: args.domain,
          }),
        })
          .then(function (res) {
            if (!res.ok) {
              settle(function () {
                reject(new Error("apple_pay_merchant_validation_failed"));
              });
              try { session.abort(); } catch (_e) {}
              return null;
            }
            return res.json();
          })
          .then(function (sessionBlob) {
            if (sessionBlob) {
              try { session.completeMerchantValidation(sessionBlob); } catch (_e) {}
            }
          })
          .catch(function () {
            settle(function () {
              reject(new Error("apple_pay_merchant_validation_failed"));
            });
            try { session.abort(); } catch (_e) {}
          });
      };

      session.onpaymentauthorized = function (event) {
        var paymentData = event && event.payment && event.payment.token;
        if (!paymentData) {
          try { session.completePayment(ApplePaySession.STATUS_FAILURE || 1); } catch (_e) {}
          settle(function () { reject(new Error("apple_pay_token_missing")); });
          return;
        }
        fetch(btHost + "/apple-pay", {
          method: "POST",
          headers: {
            "BT-API-KEY": args.basisTheoryPublicKey,
            "Content-Type": "application/json",
          },
          body: JSON.stringify({ apple_payment_data: paymentData }),
        })
          .then(function (res) {
            if (!res.ok) {
              try { session.completePayment(ApplePaySession.STATUS_FAILURE || 1); } catch (_e) {}
              settle(function () { reject(new Error("apple_pay_tokenization_failed")); });
              return null;
            }
            return res.json();
          })
          .then(function (body) {
            if (!body) return;
            var tokenId =
              (body.apple_pay && body.apple_pay.id) ||
              body.id ||
              (body.token && body.token.id) ||
              null;
            if (typeof tokenId !== "string" || tokenId.length === 0) {
              try { session.completePayment(ApplePaySession.STATUS_FAILURE || 1); } catch (_e) {}
              settle(function () { reject(new Error("apple_pay_token_id_missing")); });
              return;
            }
            try { session.completePayment(ApplePaySession.STATUS_SUCCESS || 0); } catch (_e) {}
            settle(function () {
              resolve({ tokenId: tokenId, walletType: "apple_pay" });
            });
          })
          .catch(function () {
            try { session.completePayment(ApplePaySession.STATUS_FAILURE || 1); } catch (_e) {}
            settle(function () { reject(new Error("apple_pay_authorization_failed")); });
          });
      };

      session.oncancel = function () {
        settle(function () { reject(new Error("apple_pay_cancelled")); });
      };

      try {
        session.begin();
      } catch (_e) {
        settle(function () { reject(new Error("apple_pay_begin_failed")); });
      }
    });
  }

  var googlePaySdkPromise = null;

  function isGoogleWalletAvailable() {
    if (typeof window === "undefined") return false;
    return Boolean(
      window.google &&
        window.google.payments &&
        window.google.payments.api &&
        window.google.payments.api.PaymentsClient
    );
  }

  function loadGooglePaySdk() {
    if (isGoogleWalletAvailable()) return Promise.resolve(true);
    if (googlePaySdkPromise) return googlePaySdkPromise;
    googlePaySdkPromise = new Promise(function (resolve) {
      var settled = false;
      function done(v) {
        if (settled) return;
        settled = true;
        resolve(v);
      }
      // Watchdog: pay.google.com can be blocked (CSP / network / privacy
      // extension / Safari) without ever firing load OR error. Never let
      // this promise dangle — resolve false after 4s and clear the cached
      // promise so a later checkout update can retry.
      setTimeout(function () {
        if (!settled) {
          googlePaySdkPromise = null;
          done(false);
        }
      }, 4000);
      var existing = document.querySelector('script[data-veyra-google-pay-sdk="1"]');
      if (existing) {
        existing.addEventListener("load", function () { done(isGoogleWalletAvailable()); }, { once: true });
        existing.addEventListener("error", function () { done(false); }, { once: true });
        return;
      }
      var script = document.createElement("script");
      script.src = "https://pay.google.com/gp/p/js/pay.js";
      script.async = true;
      script.setAttribute("data-veyra-google-pay-sdk", "1");
      script.onload = function () { done(isGoogleWalletAvailable()); };
      script.onerror = function () { done(false); };
      document.head.appendChild(script);
    });
    return googlePaySdkPromise;
  }

  function isGoogleReadyToPay(environment) {
    if (!isGoogleWalletAvailable()) return Promise.resolve(false);
    var PaymentsClient = window.google.payments.api.PaymentsClient;
    var client = new PaymentsClient({
      environment: environment === "production" ? "PRODUCTION" : "TEST",
    });
    return client
      .isReadyToPay({
        apiVersion: 2,
        apiVersionMinor: 0,
        allowedPaymentMethods: [
          {
            type: "CARD",
            parameters: {
              allowedAuthMethods: ["PAN_ONLY", "CRYPTOGRAM_3DS"],
              allowedCardNetworks: ["AMEX", "DISCOVER", "JCB", "MASTERCARD", "VISA"],
            },
          },
        ],
      })
      .then(function (r) { return Boolean(r && r.result); })
      .catch(function () { return false; });
  }

  function createGooglePaySession(args) {
    if (!isGoogleWalletAvailable()) {
      return Promise.reject(new Error("google_pay_unavailable"));
    }
    var PaymentsClient = window.google.payments.api.PaymentsClient;
    var isProd = args.environment === "production";
    var btHost = isProd
      ? "https://api.basistheory.com"
      : "https://api.test.basistheory.com";
    var client = new PaymentsClient({ environment: isProd ? "PRODUCTION" : "TEST" });
    var cardPaymentMethod = {
      type: "CARD",
      parameters: {
        allowedAuthMethods: ["PAN_ONLY", "CRYPTOGRAM_3DS"],
        allowedCardNetworks: ["AMEX", "DISCOVER", "JCB", "MASTERCARD", "VISA"],
        billingAddressRequired: false,
      },
      tokenizationSpecification: {
        type: "PAYMENT_GATEWAY",
        parameters: {
          gateway: "basistheory",
          gatewayMerchantId: args.basisTheoryTenantId,
        },
      },
    };
    return client
      .loadPaymentData({
        apiVersion: 2,
        apiVersionMinor: 0,
        allowedPaymentMethods: [cardPaymentMethod],
        transactionInfo: {
          totalPriceStatus: "FINAL",
          totalPrice: (args.amountCents / 100).toFixed(2),
          currencyCode: String(args.currency || "USD").toUpperCase(),
          countryCode: (args.countryCode || "US").toUpperCase(),
        },
        merchantInfo: {
          merchantName: args.merchantName,
          merchantId: args.googleMerchantId || (isProd ? "" : "TEST_MERCHANT"),
        },
      })
      .then(function (paymentData) {
        var encryptedToken =
          paymentData &&
          paymentData.paymentMethodData &&
          paymentData.paymentMethodData.tokenizationData &&
          paymentData.paymentMethodData.tokenizationData.token;
        if (!encryptedToken) throw new Error("google_pay_token_missing");
        return fetch(btHost + "/google-pay", {
          method: "POST",
          headers: {
            "BT-API-KEY": args.basisTheoryPublicKey,
            "Content-Type": "application/json",
          },
          body: JSON.stringify({ google_payment_data: encryptedToken }),
        });
      })
      .then(function (res) {
        if (!res.ok) throw new Error("google_pay_bt_tokenization_failed");
        return res.json();
      })
      .then(function (body) {
        var tokenId =
          body.id ||
          (body.token_intent && body.token_intent.id) ||
          (body.google_pay && body.google_pay.id) ||
          (body.token && body.token.id) ||
          null;
        if (typeof tokenId !== "string" || tokenId.length === 0) {
          throw new Error("google_pay_token_id_missing");
        }
        return { tokenId: tokenId, walletType: "google_pay" };
      })
      .catch(function (err) {
        if (err && err.statusCode === "CANCELED") {
          var cancelled = new Error("google_pay_cancelled");
          cancelled.name = "GooglePayCancelled";
          throw cancelled;
        }
        throw err;
      });
  }

  function prepareGooglePayReadiness(walletCfg) {
    if (
      !walletCfg ||
      !walletCfg.enable_wallets ||
      !walletCfg.google_pay_enabled ||
      !walletCfg.basis_theory_tenant_id
    ) {
      return Promise.resolve(walletCfg);
    }
    return loadGooglePaySdk()
      .then(function (loaded) {
        if (!loaded) return false;
        return isGoogleReadyToPay(walletCfg.environment);
      })
      .then(function (ready) {
        walletCfg.google_pay_ready = Boolean(ready);
        return walletCfg;
      })
      .catch(function () {
        walletCfg.google_pay_ready = false;
        return walletCfg;
      });
  }

  /**
   * Fetch wallet config from the Veyra API. Returns either:
   *   { enable_wallets: false, ... }  — tier_3+ or BT not configured
   *   { enable_wallets: true, merchant_name, apple_pay_enabled,
   *     basis_theory_tenant_id, environment, amount_cents, currency,
   *     basis_theory_public_api_key }
   *
   * Phase 4.12y3 — `apple_pay_enabled` replaces the deprecated
   * `apple_pay_merchant_identifier`. The string is no longer needed
   * because BT-managed mode owns the Apple Pay merchant identifier
   * server-side.
   *
   * F47 (2026-05-19) — Google Pay removed. Apple-Pay-only mode.
   *
   * The caller passes a sessionId; if it's empty (no checkout session
   * yet), the function returns `enable_wallets=false` without making
   * any HTTP request. This keeps the wallet UI hidden until the WC
   * cart-to-session bridge has minted a session.
   */
  function fetchWalletConfig(apiBaseUrl, sessionId) {
    if (!sessionId) {
      return Promise.resolve({ enable_wallets: false });
    }
    // 0.5.7-hotfix — fetch the SAME-ORIGIN admin-ajax proxy, not
    // veyragate.com directly (it sends no CORS headers, so a cross-origin
    // fetch fails and the wallet button never renders). The proxy returns
    // the card_capture_config body verbatim.
    var url =
      cfg.expressConfigAjaxUrl +
      "?action=veyragate_pay_express_config&nonce=" +
      encodeURIComponent(cfg.expressNonce) +
      "&session_id=" +
      encodeURIComponent(sessionId);
    return fetch(url, { credentials: "same-origin", headers: { Accept: "application/json" } })
      .then(function (res) {
        if (!res.ok) return { enable_wallets: false };
        return res.json();
      })
      .then(function (cfg) {
        if (!cfg || typeof cfg !== "object") return { enable_wallets: false };
        if (cfg.capture_engine !== "basis_theory_elements") {
          return { enable_wallets: false };
        }
        var w = cfg.wallets || {};
        var bt = cfg.basis_theory || {};
        // Phase 4.12y3 — BT-managed mode. Apple Pay enablement comes
        // from the `apple_pay_enabled` boolean on new servers; older
        // servers ship `apple_pay_merchant_identifier` instead — we
        // treat a non-null value of that as "enabled" for back-compat
        // during the rollout window. The string is no longer consumed
        // by the client (BT signs under its managed-cert tenant).
        var applePayEnabled =
          typeof w.apple_pay_enabled === "boolean"
            ? w.apple_pay_enabled
            : Boolean(w.apple_pay_merchant_identifier);
        return {
          enable_wallets: Boolean(w.enable_wallets),
          merchant_name: w.merchant_name || "",
          apple_pay_enabled: applePayEnabled,
          google_pay_enabled: Boolean(w.google_pay_enabled),
          google_pay_merchant_id: w.google_pay_merchant_id || null,
          basis_theory_tenant_id: w.basis_theory_tenant_id || null,
          basis_theory_public_api_key: bt.public_api_key || "",
          environment: bt.environment === "production" ? "production" : "test",
          amount_cents:
            typeof cfg.amount_cents === "number" ? cfg.amount_cents : 0,
          currency: cfg.currency || "usd",
        };
      })
      .catch(function () {
        return { enable_wallets: false };
      });
  }

  /**
   * Render a wallet button container above the card iframe. The button
   * styles match the iframe-lane component intent: Apple Pay button is
   * black-on-white. Visually approximates the standard wallet sheet
   * without loading the official wallet brand SVGs (which would require
   * additional CSP entries we don't want to take on).
   *
   * F47 (2026-05-19) — Google Pay removed. Apple-Pay-only mode.
   */
  function renderWalletButtons(rootEl, cfg, onWalletClick) {
    if (!rootEl || !cfg || !cfg.enable_wallets) return;

    var container = document.createElement("div");
    container.className = "veyragate-pay-wallets";
    container.setAttribute("data-veyra-wallets", "1");

    var any = false;
    // Phase 4.12y3 — Apple Pay enablement gated on BT-managed boolean.
    if (isAppleWalletAvailable() && cfg.apple_pay_enabled) {
      var apple = document.createElement("button");
      apple.type = "button";
      apple.className = "veyragate-pay-wallet veyragate-pay-wallet--apple";
      apple.setAttribute("data-veyra-wallet", "apple_pay");
      apple.textContent = "Pay with Apple Pay";
      apple.addEventListener("click", function (ev) {
        ev.preventDefault();
        onWalletClick("apple_pay");
      });
      container.appendChild(apple);
      any = true;
    }

    if (
      cfg.google_pay_enabled &&
      cfg.google_pay_ready &&
      cfg.basis_theory_tenant_id
    ) {
      var google = document.createElement("button");
      google.type = "button";
      google.className = "veyragate-pay-wallet veyragate-pay-wallet--google";
      google.setAttribute("data-veyra-wallet", "google_pay");
      google.textContent = "Pay with G Pay";
      google.addEventListener("click", function (ev) {
        ev.preventDefault();
        onWalletClick("google_pay");
      });
      container.appendChild(google);
      any = true;
    }

    if (!any) return;

    var divider = document.createElement("div");
    divider.className = "veyragate-pay-wallets__divider";
    var line1 = document.createElement("span");
    line1.className = "veyragate-pay-wallets__divider-line";
    var label = document.createElement("span");
    label.className = "veyragate-pay-wallets__divider-label";
    label.textContent = "or pay with card";
    var line2 = document.createElement("span");
    line2.className = "veyragate-pay-wallets__divider-line";
    divider.appendChild(line1);
    divider.appendChild(label);
    divider.appendChild(line2);
    container.appendChild(divider);

    // Insert ABOVE the card fields mount, inside the parent payment
    // method area. rootEl is the .veyragate-pay-fields wrapper.
    rootEl.insertBefore(container, rootEl.firstChild);
  }

  // F192 (2026-05-21) — module-scoped controller singleton.
  //
  // WooCommerce's `update_order_review` AJAX call (fired on initial
  // checkout render, on shipping changes, and on coupon updates)
  // re-injects the entire `.woocommerce-checkout-payment` fragment.
  // This destroys whatever was inside `#veyra-card-fields` — including
  // the BT iframe — and replaces it with a freshly-empty `<div>`.
  //
  // Before F192, classic.js ran `Veyra.init(...)` exactly once on
  // DOMContentLoaded; after the WC fragment swap the mount target was
  // gone and the iframe was orphaned. The checkout looked normal
  // (no errors, no console warnings) but the customer had nowhere to
  // type their card and got a "Please complete your card details" red
  // banner when they clicked Place Order. Discovered by F184.
  //
  // The fix below:
  //   1. Hoists `controller` to module scope so we keep one BT
  //      instance for the page lifetime.
  //   2. Wraps the mount logic in `mountVeyraIntoCheckout()` which is
  //      safe to call multiple times — on the FIRST call it builds
  //      the controller; on subsequent calls it calls
  //      `controller.unmount()` followed by `controller.mount(newEl)`
  //      against the fresh DOM target.
  //   3. Binds jQuery body events `updated_checkout` and
  //      `payment_method_selected` so the re-mount fires after every
  //      WC fragment swap.
  //
  // The SDK's mount() was patched in tandem (v0.4.4-bt) to skip
  // `BasisTheory().init()` when the BT instance already exists,
  // because BT's v3 SDK is a singleton and throws "This BasisTheory
  // instance has been already initialized" on a second init call.
  var controller = null;
  var cfg = null;
  var tokenizingInFlight = false;
  // F192c — guard against `mountVeyraIntoCheckout` being re-entered
  // while a previous call's async BT mount is still in flight. WC's
  // habit of firing `updated_checkout` multiple times in quick
  // succession (initial AJAX cart-update + each billing-field blur)
  // means our handler runs many times before the first BT.init()
  // resolves. Without this gate, the second call's
  // `controller.mount()` races the first call's pending
  // `elCard.mount(...)` and BT throws "Couldn't find an empty
  // element". The flag is cleared when the iframe lands; subsequent
  // genuine WC fragment-swap re-mounts run as expected.
  var mountingInFlight = false;

  function setHidden(name, value) {
    var el = document.querySelector('input[name="' + name + '"]');
    if (el) el.value = value == null ? "" : String(value);
  }

  function setError(msg) {
    var errEl = document.querySelector("[data-veyra-error]");
    if (errEl) errEl.textContent = msg || "";
  }

  // F240 (2026-05-23) — Inline-SVG brand icons rendered next to the
  // card-number field as BIN detection identifies the brand. SVG bodies
  // are minimal (no gradients, no embedded fonts, no external assets) so
  // they stay loadable under the host theme's CSP without needing
  // additional script-src or img-src entries. Each glyph fits a 36x24
  // viewBox and uses brand-recognizable color blocks; tap-target friendly
  // when the badge sits inside the field-host's padding.
  //
  // Brand list mirrors VEYRA_CARD_TYPES in lib/basis-theory/card-element-config.ts
  // so the badge only renders for brands the BT iframe is configured to
  // accept. The "unknown" key is intentional — BT emits cardBrand:"unknown"
  // on a BIN it can't resolve yet (typically the first 1-3 digits), so we
  // collapse that case to no-badge.
  var BRAND_ICON_SVGS = {
    visa:
      '<svg viewBox="0 0 36 24" width="36" height="24" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Visa">' +
      '<rect width="36" height="24" rx="3" fill="#1A1F71"/>' +
      '<path fill="#fff" d="M14.9 8.3l-2.9 7.5h-1.9L8.7 9.5c-.1-.4-.2-.5-.5-.7-.5-.3-1.3-.5-2-.7l.1-.2h3c.4 0 .8.3.9.7l.8 4.5 2-5.2h1.9zm7.7 5c0-2-2.8-2.1-2.8-3 0-.3.3-.6.9-.7.3 0 1.2-.1 2.1.4l.3-1.5c-.5-.2-1.2-.4-2-.4-2.1 0-3.5 1.1-3.5 2.7 0 1.2 1.1 1.8 1.9 2.2.8.4 1.1.6 1.1.9 0 .5-.6.7-1.1.7-.9 0-1.5-.3-1.9-.4l-.3 1.6c.4.2 1.2.4 2.1.4 2.2 0 3.7-1.1 3.7-2.9zm4.7 2.5h1.8L27.5 8.3h-1.6c-.4 0-.7.2-.8.6l-2.7 6.9h2.1l.4-1.2h2.6l.2 1.2zm-2.2-2.8l1-2.9.6 2.9h-1.6zM17.7 8.3l-1.7 7.5h-2l1.7-7.5h2z"/>' +
      "</svg>",
    mastercard:
      '<svg viewBox="0 0 36 24" width="36" height="24" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Mastercard">' +
      '<rect width="36" height="24" rx="3" fill="#fff"/>' +
      '<circle cx="14.5" cy="12" r="6" fill="#EB001B"/>' +
      '<circle cx="21.5" cy="12" r="6" fill="#F79E1B"/>' +
      '<path fill="#FF5F00" d="M18 7.4c1.5 1.1 2.4 2.8 2.4 4.6S19.5 15.5 18 16.6c-1.5-1.1-2.4-2.8-2.4-4.6s.9-3.5 2.4-4.6z"/>' +
      "</svg>",
    "american-express":
      '<svg viewBox="0 0 36 24" width="36" height="24" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="American Express">' +
      '<rect width="36" height="24" rx="3" fill="#2E77BC"/>' +
      '<path fill="#fff" d="M7.4 14.3h2.3l.4-.9h1l.4.9h4.6V13.6l.4.7h2.4l.4-.7v.7H29l1-1 1 1h2v-1.9l-1.4-1.6 1.4-1.5V8.5h-4l-1 1.1-1-1.1h-7.4l-.7 1.4-.7-1.4H10.5l-.9 2-.4-2H7.6V14.3zm.7-1.1l1.2-2.8 1.2 2.8H7.5l.6-1.4-.6 1.4zm6 .1h-1.5V12h1.5v-.6h-1.5v-1h1.6l.6.8-.7 1zm3.3 0l-1-1.2 1-1.4v2.6zm2.7 0H19l-.9-1.3-.9 1.3h-.6l1.3-1.6L17 11h.7l.9 1.2.9-1.2h.6l-1.3 1.6 1.3 1.7zM26 11.1h-3v.6h2.9v.7h-2.9v.7H26v.7l-2.1-2.3 2.1-2.3v.6zm-2.7 2c.7 0 .7-1.7 0-1.7-.7 0-.7 1.7 0 1.7zm5 .1l1.4-1.5 1.4 1.5v.5h-1.9l-.4-.5-.4.5h-.1v-.5z"/>' +
      "</svg>",
    discover:
      '<svg viewBox="0 0 36 24" width="36" height="24" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Discover">' +
      '<rect width="36" height="24" rx="3" fill="#fff"/>' +
      '<path fill="#F26E21" d="M21 12a4 4 0 1 1-8 0 4 4 0 0 1 8 0z"/>' +
      '<path fill="#000" d="M6 14.5h1.2v-1.4h.3c.4 0 .6.1.7.4l.5 1H10l-.6-1.2c-.1-.2-.3-.4-.5-.5.5-.1.8-.5.8-1 0-.5-.4-1-1.3-1H6v3.7zm1.2-2.2v-.7h.4c.4 0 .5.1.5.3s-.1.4-.5.4h-.4zm3.3 2.2h.9v-3.7h-.9v3.7zm1.9-1.9c0 1.2.8 2 1.9 2 .4 0 .8-.1 1.1-.2v-.9c-.2.2-.6.3-.9.3-.6 0-1-.4-1-1.1s.4-1.1 1-1.1c.3 0 .7.1.9.3v-.9c-.3-.1-.7-.2-1.1-.2-1.1 0-1.9.8-1.9 1.8zm10.5 1.9l1.5-3.7h-1l-.9 2.5-.9-2.5h-1l1.5 3.7h.8zm2.1 0h2.1v-.7h-1.2v-.9h1.2v-.7h-1.2v-.7h1.2v-.7H25v3.7zm2.8 0h1.2v-1.4h.3c.4 0 .6.1.7.4l.5 1h1l-.6-1.2c-.1-.2-.3-.4-.5-.5.5-.1.8-.5.8-1 0-.5-.4-1-1.3-1h-2.1v3.7zm1.2-2.2v-.7h.4c.4 0 .5.1.5.3s-.1.4-.5.4h-.4z"/>' +
      "</svg>",
    "diners-club":
      '<svg viewBox="0 0 36 24" width="36" height="24" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Diners Club">' +
      '<rect width="36" height="24" rx="3" fill="#fff"/>' +
      '<circle cx="18" cy="12" r="7" fill="#0079BE"/>' +
      '<path fill="#fff" d="M14.5 8a4 4 0 0 0 0 8V8zm7 0v8a4 4 0 0 0 0-8z"/>' +
      "</svg>",
    jcb:
      '<svg viewBox="0 0 36 24" width="36" height="24" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="JCB">' +
      '<rect width="36" height="24" rx="3" fill="#fff"/>' +
      '<rect x="3" y="6" width="10" height="12" rx="2" fill="#0E4C96"/>' +
      '<rect x="13" y="6" width="10" height="12" rx="2" fill="#C82127"/>' +
      '<rect x="23" y="6" width="10" height="12" rx="2" fill="#3E8C3D"/>' +
      '<path fill="#fff" d="M7 10.5h2v3c0 .8-.5 1.3-1.5 1.3S6 14.3 6 13.5v-.3h.7v.3c0 .4.2.6.7.6s.7-.2.7-.6v-3zm9.5 0H18c.7 0 1.2.4 1.2 1 0 .5-.3.8-.7.9.5.1.9.4.9 1 0 .7-.5 1.1-1.3 1.1h-1.7v-4zm.7.6v1.1h.7c.4 0 .6-.2.6-.5s-.2-.5-.6-.5h-.7zm0 1.7v1.2h.8c.5 0 .7-.2.7-.6s-.3-.6-.7-.6h-.8zm9.5-1.4c0-.6.5-1 1.4-1 .6 0 1 .2 1.3.5l-.5.4c-.2-.2-.4-.3-.8-.3-.4 0-.6.1-.6.3 0 .2.2.3.7.4.8.1 1.3.4 1.3 1.1 0 .7-.6 1.1-1.5 1.1-.7 0-1.2-.2-1.5-.5l.5-.4c.2.2.6.4 1 .4.5 0 .7-.1.7-.4 0-.2-.2-.3-.7-.4-.8-.2-1.3-.5-1.3-1.2z"/>' +
      "</svg>",
  };

  // F240 — paint the brand-badge element next to the card field. Called
  // once after the SDK is initialised. The badge is anchored to the
  // right edge of the card label row so it sits beside the field
  // without colliding with the BT iframe contents.
  function ensureBrandBadge() {
    var existing = document.querySelector("[data-veyra-card-brand-badge]");
    if (existing) return existing;
    var fields = document.querySelector("[data-veyra-gateway]");
    if (!fields) return null;
    var badge = document.createElement("span");
    badge.setAttribute("data-veyra-card-brand-badge", "");
    badge.style.display = "inline-flex";
    badge.style.alignItems = "center";
    badge.style.justifyContent = "flex-end";
    badge.style.minHeight = "24px";
    badge.style.marginBottom = "4px";
    badge.style.transition = "opacity 120ms ease";
    badge.style.opacity = "0";
    // Insert ABOVE the mount target so the badge sits in the label row.
    // Falls back to prepending to the gateway container when the mount
    // target is missing (defensive — should never happen at runtime).
    var mount = document.getElementById("veyra-card-fields");
    if (mount && mount.parentNode === fields) {
      fields.insertBefore(badge, mount);
    } else {
      fields.insertBefore(badge, fields.firstChild);
    }
    return badge;
  }

  // F240 — swap the badge content based on the BT-reported brand. We
  // normalise the brand string (some BT versions emit `americanexpress`
  // sans hyphen, others use `american-express`) so the lookup is robust
  // across SDK versions. Unknown / empty brand collapses the badge to
  // invisible so it never paints a stale icon on a freshly-cleared
  // card field.
  function updateBrandBadge(brandRaw) {
    var badge = ensureBrandBadge();
    if (!badge) return;
    var key = String(brandRaw || "").toLowerCase().trim();
    if (key === "americanexpress" || key === "amex") key = "american-express";
    if (key === "dinersclub" || key === "diners") key = "diners-club";
    if (!key || key === "unknown" || !BRAND_ICON_SVGS[key]) {
      badge.innerHTML = "";
      badge.style.opacity = "0";
      badge.removeAttribute("data-brand");
      return;
    }
    badge.innerHTML = BRAND_ICON_SVGS[key];
    badge.style.opacity = "1";
    badge.setAttribute("data-brand", key);
  }

  function isVeyraSelected() {
    var checked = document.querySelector('input[name="payment_method"]:checked');
    return checked && checked.value === ((cfg && cfg.gatewayId) || "veyragate_pay");
  }

  function jqOrFallback() {
    if (typeof window.jQuery === "function") return window.jQuery;
    return null;
  }

  function ready() {
    if (!window.Veyra || typeof window.Veyra.init !== "function") {
      // SDK failed to load. Show a checkout-safe error and disable.
      var mount = document.getElementById("veyra-card-fields");
      var errEl = document.querySelector("[data-veyra-error]");
      if (errEl) {
        errEl.textContent =
          (window.veyragatePayConfig && window.veyragatePayConfig.i18n && window.veyragatePayConfig.i18n.sdk_failed) ||
          "Payment fields could not load. Please try again or contact support.";
      }
      if (mount) {
        mount.innerHTML = "";
      }
      return;
    }

    cfg = window.veyragatePayConfig || {};

    // First mount + first-time handler attach.
    mountVeyraIntoCheckout();
    attachHandlers();

    // F192 — bind WC's body-level events that fire after a fragment
    // swap. `updated_checkout` fires on every wc-ajax update_order_review
    // response; `payment_method_selected` fires when the customer picks
    // a different payment method (e.g. checking the radio for our
    // gateway after a different one was selected on render).
    //
    // Both handlers re-run `mountVeyraIntoCheckout` which is
    // idempotent: it no-ops when the current mount target is fine and
    // re-attaches the BT iframe when the target has been replaced by
    // WC's fragment swap.
    var $ = jqOrFallback();
    if ($ && typeof $(document.body).on === "function") {
      $(document.body).on(
        "updated_checkout payment_method_selected",
        function () {
          mountVeyraIntoCheckout();
          // Re-attach the native-submit fallback against the (possibly
          // fresh) form element. The delegated jQuery
          // `checkout_place_order_*` listener bound by attachHandlers()
          // is on document.body and survives the fragment swap, so we
          // don't need to re-bind it.
          attachHandlers();
          // Apple Pay express (0.5.7) — re-bootstrap the wallet button
          // after a fragment swap so the express order/session + amount
          // stay current (coupon / shipping changes). The bootstrap-token
          // guard inside tryBootstrapWallets prevents a stale render.
          tryBootstrapWallets();
        }
      );
    }

    // Phase 4.12y2 — wallet bootstrap. We need a checkout session id to
    // fetch the wallet config. The SDK creates one as part of
    // controller.tokenize() — but that's too late for rendering the
    // wallet button. Best path: ask the SDK to mint or surface its
    // session id eagerly. If the SDK exposes a getSessionId() helper
    // (post-0.4 builds), use it; otherwise we leave wallets dormant
    // until the SDK exposes a stable bridge. The tier_1/2 first-merchant
    // onboarding is what activates this surface anyway (per
    // docs/WALLETS_2026-05-18.md §"What blocks live rollout") and that
    // milestone will ship alongside an SDK that exposes the session id
    // up-front. Until then the wallet bootstrap is a graceful no-op on
    // tier_3+ (the server returns enable_wallets=false even if we did
    // mint a session) and the wallet UI just doesn't render.
    tryBootstrapWallets();
    setTimeout(tryBootstrapWallets, 600);
  }

  // F192 — single-init, multi-remount helper. Safe to call repeatedly.
  //
  // The shape changed twice across the hotfix arc:
  //
  //   F192a: hoist controller to module scope; on re-runs call
  //     controller.unmount() + controller.mount(newTarget).
  //   F192b: cache the in-flight BT.init() promise so concurrent
  //     mount() calls during the first init window don't race past
  //     the singleton guard.
  //   F192c: switch the re-mount path from controller.mount() back
  //     to controller.destroy() + Veyra.init(...). BT v3 elements
  //     are NOT idempotent on re-mount — even with btInstance
  //     reused, `elCard.mount("#cardHost.id")` errors with
  //     "Couldn't find an empty element" if any prior elCard.mount
  //     call is still in flight, and the wider Promise.all race
  //     between two concurrent mount() calls is hard to close
  //     without instrumenting BT itself. A clean destroy + re-init
  //     is the simpler contract: tear down EVERYTHING (controller,
  //     elCard, internal BT.init state), then build a new instance.
  //     The BT v3 SDK is fine with multiple BasisTheory().init()
  //     calls AS LONG AS the previous instance was discarded — the
  //     "already initialized" message means "this SDK module is
  //     already wired to one tenant", which falls away once the
  //     controller's btInstance reference is released and a fresh
  //     init() runs.
  function mountVeyraIntoCheckout() {
    if (!window.Veyra || typeof window.Veyra.init !== "function") return;
    if (!cfg) cfg = window.veyragatePayConfig || {};

    var mount = document.getElementById("veyra-card-fields");
    if (!mount) return;

    // True-idempotency: if the iframe is already inside the current
    // mount target, the fragment swap didn't actually destroy our
    // mount (or this is a no-op echo from a different WC event).
    // Skip the destroy/re-init cycle so we don't flicker the card
    // field on every AJAX update.
    if (controller && mount.querySelector("iframe")) {
      mountingInFlight = false;
      return;
    }

    // If a previous mount is still settling (controller exists but
    // its iframe hasn't landed yet), drop this call — don't tear it
    // down mid-init. The flag clears either via the onReady callback
    // (BT iframe painted) OR via the onError callback (BT load
    // failed; user must refresh).
    if (mountingInFlight) return;

    var billingPostcode = "";
    var bp = document.getElementById("billing_postcode");
    if (bp && bp.value) billingPostcode = bp.value;

    // Tear down any prior controller before re-initializing.
    if (controller) {
      try { controller.destroy(); } catch (_e) {}
      controller = null;
      try { window.__veyraClassicCtrl = null; } catch (_e) {}
    }

    // F286 — paint loading shimmer + descriptor disclosure BEFORE the
    // BT iframe lands. The shimmer comes off on the SDK's `ready`
    // callback; the descriptor disclosure stays.
    try {
      mount.setAttribute("data-veyra-loading", "1");
    } catch (_e) {}
    paintDescriptorDisclosure();

    // F286 — remember that the customer picked card so a return visit
    // lands them on the same lane (only effective when both card +
    // ACH plugins are active on the same store).
    rememberPaymentMethodSelection(
      (cfg && cfg.gatewayId) || "veyragate_pay",
    );

    mountingInFlight = true;
    controller = window.Veyra.init({
      publishableKey: cfg.publishableKey || "",
      apiBaseUrl: cfg.apiBaseUrl || "",
      billingPostcode: billingPostcode,
      target: mount,
      // F286 — register an abandonment callback so the merchant page
      // can fire its own analytics (e.g. a GA4 `begin_checkout` +
      // `cart_abandoned` pair). Pure custom-event dispatch — no card
      // data flows through.
      onAbandonment: function (payload) {
        try {
          var ev = new CustomEvent("veyragate_pay:abandonment", { detail: payload });
          document.dispatchEvent(ev);
        } catch (_e) { /* ignore */ }
      },
      onReady: function () {
        // BT iframe is fully painted — flag clears so subsequent
        // fragment-swap re-mounts can proceed.
        mountingInFlight = false;
        try {
          mount.removeAttribute("data-veyra-loading");
        } catch (_e) {}
        // F240 — paint a blank badge placeholder so the layout doesn't
        // shift when the customer starts typing and the first brand
        // detection fires. The brand icon swaps in via onValid below.
        ensureBrandBadge();
      },
      // F240 (2026-05-23) — subscribe to BT's validity / brand-detect
      // event so we can paint a visible Visa / MC / Amex / Discover /
      // Diners / JCB badge as soon as the BIN (first 4-6 digits) is
      // identified. The SDK already emits `card_brand` on this payload
      // (hosted-fields.js v0.4.7-bt onward); we just weren't consuming
      // it on the WC plugin surface, which made it look like brand
      // detection was broken when it was actually just invisible.
      onValid: function (payload) {
        var brand = payload && payload.card_brand;
        updateBrandBadge(brand || "");
      },
      onError: function (e) {
        setError((e && e.message) || "Card error.");
        mountingInFlight = false;
        try {
          mount.removeAttribute("data-veyra-loading");
        } catch (_e) {}
      },
    });
    // Expose the controller for diagnostics + proof harnesses. The
    // controller does NOT expose card values — only metadata.
    try { window.__veyraClassicCtrl = controller; } catch (_e) {}

    // F286 — mark the Place Order button container sticky on mobile
    // so the customer never has to scroll down past the BT iframe to
    // pay. The CSS media query in classic.css does the actual lift.
    applyStickyPayContainerIfMobile();
  }

  // Descriptor disclosure painter. Idempotent — replaces the existing
  // node on each call, so re-painting after a WC fragment swap doesn't
  // stack duplicate disclosures.
  //
  // 2026-05-25 — trust-strip painter REMOVED. The customer surface no
  // longer renders "Encrypted in your browser", "PCI-DSS compliant",
  // or "Refunds processed within ..." rows (these were deleted from
  // the Veyra app surface in the F286 cleanup). The descriptor
  // disclosure stays — tier_3/4 merchants need it to align customer
  // expectations with the actual bank statement.
  function paintDescriptorDisclosure() {
    var gateway = document.querySelector("[data-veyra-gateway]");
    if (!gateway) return;
    var hideDescriptor =
      gateway.getAttribute("data-veyra-hide-descriptor") === "1";

    // Descriptor disclosure — inserted BELOW the mount target. The
    // merchant can pass the mask-pool name via data-veyra-descriptor,
    // which is what tier_3/4 merchants do to keep the customer's
    // statement-side string aligned with what the bank actually shows.
    if (!hideDescriptor && window.Veyra && typeof window.Veyra.renderDescriptorDisclosure === "function") {
      var descHost = ensureDescriptorHost(gateway);
      var descriptor =
        gateway.getAttribute("data-veyra-descriptor") ||
        (cfg && cfg.descriptor) ||
        "";
      var merchantName =
        gateway.getAttribute("data-veyra-merchant-name") ||
        (cfg && cfg.merchantName) ||
        "";
      if (descHost) {
        window.Veyra.renderDescriptorDisclosure(descHost, {
          theme: detectThemeFromHost(),
          descriptorOverride: descriptor,
          merchantName: merchantName,
        });
      }
    }
  }

  // Descriptor disclosure host (created once per gateway container).
  // Sits BELOW the mount target so the bank-statement copy is the last
  // thing the customer reads before clicking Place Order.
  function ensureDescriptorHost(gateway) {
    if (!gateway) return null;
    var existing = gateway.querySelector("[data-veyra-descriptor-host]");
    if (existing) return existing;
    var host = document.createElement("div");
    host.setAttribute("data-veyra-descriptor-host", "1");
    gateway.appendChild(host);
    return host;
  }

  // F286 — Theme detector. Walks up the DOM looking for a parent with
  // a `data-theme` or `data-veyra-theme` attribute, OR a parent whose
  // computed background-color is dark enough that the customer is
  // clearly on a dark theme. Falls back to "auto" so the SDK uses
  // prefers-color-scheme.
  function detectThemeFromHost() {
    try {
      var el = document.querySelector("[data-veyra-gateway]");
      while (el && el !== document.body) {
        var explicit =
          el.getAttribute("data-veyra-theme") || el.getAttribute("data-theme");
        if (explicit === "dark" || explicit === "light") return explicit;
        el = el.parentElement;
      }
      // Background luminance probe — cheap heuristic for dark themes
      // that don't expose data-theme. The SDK ALSO honors prefers-
      // color-scheme as a fallback, so this is best-effort only.
      var bodyBg = window.getComputedStyle(document.body).backgroundColor;
      if (bodyBg && bodyBg.indexOf("rgb") === 0) {
        var rgbMatch = bodyBg.match(/\d+/g);
        if (rgbMatch && rgbMatch.length >= 3) {
          var r = parseInt(rgbMatch[0], 10);
          var g = parseInt(rgbMatch[1], 10);
          var b = parseInt(rgbMatch[2], 10);
          // Rec 709 luma; <0.4 → dark
          var luma = (0.2126 * r + 0.7152 * g + 0.0722 * b) / 255;
          if (luma < 0.4) return "dark";
        }
      }
    } catch (_e) { /* defensive */ }
    return "auto";
  }

  // F286 — Persist the customer's chosen lane (card vs ACH) so a
  // return visit lands on the same option. Read by the ACH plugin too
  // (shared localStorage key). LocalStorage is namespaced by the
  // browser per-origin; on a multi-merchant install the merchant_id
  // would be needed to scope further, but storefronts are typically
  // single-merchant so origin scope is enough.
  function rememberPaymentMethodSelection(gatewayId) {
    try {
      if (typeof window === "undefined" || !window.localStorage) return;
      window.localStorage.setItem("veyra_pay_last_method", String(gatewayId));
    } catch (_e) { /* QuotaExceededError / SecurityError / etc. */ }
  }

  // F286 — Sticky Pay button on mobile. We can't move the WC Place
  // Order button (themes wrap it differently across Storefront / Astra
  // / Twenty Twenty-Four), so we instead opt-in the closest existing
  // ".place-order" container by adding a data-attribute the CSS
  // matches. Idempotent: re-marking the same container is a no-op.
  function applyStickyPayContainerIfMobile() {
    try {
      if (typeof window === "undefined") return;
      // CSS media query handles the actual viewport gate, but the
      // data-attribute has to be present so the @media rule can match.
      // We always set it; the media query in classic.css scopes the
      // sticky positioning to ≤640px viewports.
      var placeOrder = document.querySelector(
        ".place-order, .wc-block-checkout__actions",
      );
      if (!placeOrder) return;
      placeOrder.setAttribute("data-veyra-sticky-pay", "1");
    } catch (_e) { /* swallow */ }
  }

  /**
   * Apple Pay express (0.5.7) — wallet click handler.
   *
   * REWIRED: the checkout express button no longer submits the WC form
   * (the card confirm lane does not read wallet fields and would fetch
   * the Apple Pay token as a token-intent and 404). Instead it drives
   * the shared VeyraWalletCore Apple Pay sheet, then charges the
   * customer lane `POST /api/checkout/{session_id}/pay-bt` with
   * `wallet_type:"apple_pay"` + `wallet_contact`, exactly like the
   * proven evobones lane. The card tokenize/confirm path is UNTOUCHED.
   *
   * The express WC order was created up front (handle_express_order_ajax)
   * so the resulting merchant webhook binds it by `wc_order_id` and
   * fires payment_complete server-side; the client redirect to
   * order-received is UX only.
   */
  function onWalletClick(walletType) {
    if (tokenizingInFlight) return;
    setError("");

    var walletCfg = window.__veyraWalletCfg || null;
    if (!walletCfg || !walletCfg.enable_wallets) {
      setError("This payment method isn't available. Please use a card.");
      return;
    }
    if (walletType !== "apple_pay") {
      // Apple-Pay-only on this express surface (the customer charge lane
      // accepts apple_pay; Google Pay would need its own minted lane).
      setError("This payment method isn't available. Please use a card.");
      return;
    }
    if (!walletCfg.apple_pay_enabled || !walletCfg.basis_theory_public_api_key) {
      setError("Apple Pay is not configured. Please use a card.");
      return;
    }
    if (!window.VeyraWalletCore || typeof window.VeyraWalletCore.runApplePay !== "function") {
      setError("Apple Pay is not available. Please use a card.");
      return;
    }

    var sessionId = walletCfg.session_id || "";
    var wcOrderId = walletCfg.wc_order_id || "";
    if (!sessionId) {
      setError("Apple Pay isn't ready yet. Please use a card.");
      return;
    }
    // Server-authoritative amount from the mint/config (never a DOM cell).
    var amountCents = walletCfg.amount_cents || 0;
    var currency = walletCfg.currency || "usd";
    var apiBaseUrl = (cfg && cfg.apiBaseUrl) || "";

    tokenizingInFlight = true;

    window.VeyraWalletCore.runApplePay({
      amountCents: amountCents,
      currency: currency,
      merchantName:
        walletCfg.merchant_name ||
        (cfg && cfg.walletMerchantNameFallback) ||
        "Secure Checkout",
      btPublicKey: walletCfg.basis_theory_public_api_key,
      environment: walletCfg.environment,
    })
      .then(function (result) {
        if (!result || !result.tokenId) {
          if (result && typeof result.finalize === "function") result.finalize(false);
          throw new Error("wallet_no_token");
        }
        var idempotencyKey = "wc_" + wcOrderId;
        // 0.5.7-hotfix — charge through the SAME-ORIGIN admin-ajax proxy
        // (veyragate.com sends no CORS headers, so a direct cross-origin POST
        // fails). session_id moves into the body; the proxy forwards to
        // /api/checkout/{session_id}/pay-bt server-to-server.
        return fetch(
          cfg.expressPayAjaxUrl +
            "?action=veyragate_pay_express_pay&nonce=" +
            encodeURIComponent(cfg.expressNonce),
          {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            credentials: "same-origin",
            body: JSON.stringify({
              session_id: sessionId,
              basis_theory_token_intent_id: result.tokenId,
              card_summary: {},
              wallet_type: "apple_pay",
              idempotency_key: idempotencyKey,
              wallet_contact: result.walletContact || undefined,
            }),
          }
        )
          .then(function (res) {
            return res
              .json()
              .catch(function () { return {}; })
              .then(function (body) {
                return { res: res, body: body };
              });
          })
          .then(function (out) {
            var ok = out.res.ok && out.body && out.body.public_status === "succeeded";
            if (typeof result.finalize === "function") result.finalize(ok);
            tokenizingInFlight = false;
            if (!ok) {
              // requires_action / failed / blocked => fall back to card.
              // Apple Pay is server 3DS-exempt on masked tiers, so
              // requires_action is not expected; treat it as a failure
              // rather than silently leaving the customer stuck.
              setError(
                "We couldn't complete that wallet payment. Please use a card."
              );
              return;
            }
            var redirect =
              (out.body && out.body.redirect_url) ||
              (walletCfg && walletCfg.order_received_url) ||
              defaultOrderReceivedUrl(wcOrderId);
            if (redirect) {
              window.location.assign(redirect);
            }
          });
      })
      .catch(function (err) {
        tokenizingInFlight = false;
        var msg = err && err.message ? err.message : "wallet_failed";
        // User-cancel is silent; everything else => friendly fall-back.
        // NEVER expose the raw code to the customer.
        if (msg === "apple_pay_cancelled") {
          setError("");
        } else {
          setError(
            "We couldn't complete that wallet payment. Please use a card."
          );
        }
      });
  }

  // Apple Pay express (0.5.7) — best-effort WC order-received URL when
  // the charge response omits a redirect_url. The webhook completes the
  // order server-side regardless; this is UX only. We can't compute the
  // order key client-side, so we route to the order-received endpoint by
  // id and let WC resolve it for the logged-in/session-owning shopper.
  function defaultOrderReceivedUrl(wcOrderId) {
    try {
      if (!wcOrderId) return "";
      var base = window.location.origin + "/checkout/order-received/" + encodeURIComponent(wcOrderId) + "/";
      return base;
    } catch (_e) {
      return "";
    }
  }

  // F192 — Track whether we've already bound the body-level jQuery
  // delegate so the re-mount path (which re-calls attachHandlers) does
  // not stack duplicate listeners on every fragment swap. The
  // native-form submit handler IS rebound each time because the form
  // element itself is replaced by WC's fragment swap.
  var jqDelegatesBound = false;

  // F-WC-SINGLE-CLICK (2026-06-05) — re-enter the checkout submit
  // pipeline after an async tokenize() resolves, WITHOUT the customer
  // clicking Place Order a second time. We re-click the WC place-order
  // button so the SAME pipeline the shopper used (standard WC AJAX OR a
  // custom checkout plugin like Checkout Form Designer / Beaver Builder)
  // runs again — and this time the hidden token-intent field is
  // populated, so every submit handler short-circuits via
  // `alreadyTokenized` and the order goes through. Falls back to a
  // jQuery form submit when the button id is not the WC default.
  // Deferred to a macrotask so the current submit handler finishes
  // unwinding before we re-fire it.
  function resubmitCheckout() {
    setTimeout(function () {
      var btn = document.getElementById("place_order");
      if (btn) {
        btn.click();
        return;
      }
      var $form = $("form.checkout, form.woocommerce-checkout, form#order_review");
      if ($form && $form.length) {
        $form.first().trigger("submit");
      }
    }, 0);
  }

  function attachHandlers() {
    var $ = jqOrFallback();
    if (!$ || typeof $("body").on !== "function") {
      // Non-jQuery WooCommerce build — fall back to a native submit handler.
      var form = document.querySelector("form.checkout, form.woocommerce-checkout, form#order_review");
      if (!form) return;
      // Guard against double-binding on the SAME form element (idempotent
      // re-attach). WC's fragment swap replaces the form, so a fresh form
      // needs a fresh listener; an unchanged form must not get two.
      if (form.dataset && form.dataset.veyragatePayBound === "1") return;
      if (form.dataset) form.dataset.veyragatePayBound = "1";
      form.addEventListener("submit", function (ev) {
        if (!isVeyraSelected()) return;
        if (tokenizingInFlight) return;
        var btIntentField = document.querySelector('input[name="veyra_basis_theory_token_intent_id"]');
        var legacyTokField = document.querySelector('input[name="veyra_token_id"]');
        if ((btIntentField && btIntentField.value) || (legacyTokField && legacyTokField.value)) return;
        ev.preventDefault();
        tokenize().then(function (ok) {
          tokenizingInFlight = false;
          if (ok) form.submit();
        });
        tokenizingInFlight = true;
      });
      return;
    }

    // jQuery delegated listener on document.body survives fragment
    // swaps, so bind it exactly once for the page lifetime.
    if (jqDelegatesBound) return;
    jqDelegatesBound = true;
    $(document.body).on("checkout_place_order_" + ((cfg && cfg.gatewayId) || "veyragate_pay"), function () {
      if (tokenizingInFlight) return false;
      // Treat the BT-canonical token-intent field as the primary
      // "already tokenized" marker; fall back to the legacy token id
      // when the SDK still emits it.
      var btIntent = document.querySelector('input[name="veyra_basis_theory_token_intent_id"]');
      var legacyTok = document.querySelector('input[name="veyra_token_id"]');
      var alreadyTokenized = (btIntent && btIntent.value) || (legacyTok && legacyTok.value);
      if (alreadyTokenized) return true;
      // F-WC-SINGLE-CLICK (2026-06-05) — first pass: tokenize, then
      // re-enter the submit pipeline once it resolves. We return false
      // to abort WC's in-flight AJAX submit (the hidden fields are still
      // empty); resubmitCheckout() re-clicks Place Order after the
      // token-intent lands, and the `alreadyTokenized` branch above then
      // short-circuits so the order goes through on what feels like a
      // single click. The in-flight guard prevents a second manual click
      // from kicking off a duplicate tokenize while one is pending.
      tokenizingInFlight = true;
      tokenize().then(function (ok) {
        tokenizingInFlight = false;
        if (ok) resubmitCheckout();
      });
      return false;
    });

    // v0.5.3 (2026-06-05) — defensive native form-submit listener that
    // ALSO fires the tokenize() step in the jQuery branch.
    //
    // The `checkout_place_order_<gateway_id>` event is fired by WC core
    // JS (`wc-checkout.js`) just before the AJAX checkout request goes
    // out. Custom-checkout plugins (e.g. Checkout Form Designer, which
    // replaces WC's submit pipeline with its own AJAX flow) often
    // bypass this event entirely, leaving the BT token-intent fields
    // empty — `validate_fields()` then fails with "Please complete
    // your card details before placing the order." Discovered against
    // apexpeptidesupply.com on 2026-06-05.
    //
    // The defensive listener wraps the form's native `submit` event so
    // we still get a chance to tokenize even when the WC checkout
    // pipeline is replaced. Idempotent: bails if the BT field is
    // already populated (so the standard wc_checkout flow isn't
    // double-processed) and uses a data-* flag to avoid re-binding to
    // the SAME form across fragment swaps.
    function bindNativeSubmitFallback() {
      var form = document.querySelector(
        "form.checkout, form.woocommerce-checkout, form#order_review"
      );
      if (!form) return;
      if (form.dataset && form.dataset.veyragatePayNativeBound === "1") return;
      if (form.dataset) form.dataset.veyragatePayNativeBound = "1";
      form.addEventListener("submit", function (ev) {
        if (!isVeyraSelected()) return;
        if (tokenizingInFlight) return;
        var btIntentField = document.querySelector(
          'input[name="veyra_basis_theory_token_intent_id"]'
        );
        var legacyTokField = document.querySelector(
          'input[name="veyra_token_id"]'
        );
        if (
          (btIntentField && btIntentField.value) ||
          (legacyTokField && legacyTokField.value)
        )
          return;
        ev.preventDefault();
        tokenizingInFlight = true;
        tokenize().then(function (ok) {
          tokenizingInFlight = false;
          // F-WC-SINGLE-CLICK (2026-06-05) — re-enter via the place-order
          // button so custom-checkout pipelines (Checkout Form Designer,
          // Beaver Builder) that ignore a native form.submit() still run.
          if (ok) resubmitCheckout();
        });
      });
    }
    bindNativeSubmitFallback();
    // Re-bind after every WC fragment swap (`updated_checkout` is
    // fired after WC's update_order_review AJAX) so we catch a freshly
    // replaced form element.
    $(document.body).on("updated_checkout", bindNativeSubmitFallback);
  }

  function tokenize() {
    setError("");
    if (!controller) {
      setError(
        (cfg && cfg.i18n && cfg.i18n.tokenize_err) ||
          "We could not securely store your card. Please try again."
      );
      return Promise.resolve(false);
    }
    return controller
      .tokenize()
      .then(function (result) {
        tokenizingInFlight = false;
        if (!result || !result.ok) {
          setError(
            (result && result.message) ||
              (cfg && cfg.i18n && cfg.i18n.tokenize_err) ||
              "We could not securely store your card. Please try again."
          );
          return false;
        }
        // BT-canonical fields (SDK 0.4.0-bt). PHP validate_fields()
        // accepts the BT-intent path when veyra_basis_theory_token_intent_id
        // is set; the legacy pair (pm_id + tok_id) is the fallback for
        // older plugin builds.
        var summary = result.card_summary || {};
        setHidden(
          "veyra_basis_theory_token_intent_id",
          result.basis_theory_token_intent_id || ""
        );
        setHidden(
          "veyra_card_summary_json",
          JSON.stringify(summary)
        );
        // No wallet was used — clear the wallet_type field so a stale
        // value from a previous interaction doesn't tag this submit.
        setHidden("veyra_wallet_type", "");
        // Legacy fields — best-effort mapping so order meta + back-compat
        // PHP gateways stay populated. payment_method_id is null on the
        // BT lane; legacy validation only fires when the BT-intent field
        // is empty.
        setHidden("veyra_payment_method_id", result.payment_method_id || "");
        setHidden("veyra_token_id", result.token_id || "");
        setHidden("veyra_session_id", result.session_id || "");
        setHidden("veyra_tokenization_mode", result.mode || "");
        setHidden(
          "veyra_last4",
          (summary && summary.last4) || result.last4 || ""
        );
        setHidden(
          "veyra_brand",
          (summary && summary.brand) || result.brand || ""
        );
        // F-WC-SINGLE-CLICK (2026-06-05) — tokenize() is PURE: it
        // tokenizes, populates the hidden fields, and returns true. It
        // intentionally does NOT submit the form. Each caller re-submits
        // exactly once via resubmitCheckout(). The old internal native
        // form.submit() here, combined with a caller-side submit, double-
        // submitted on custom-checkout pipelines (→ risk of double
        // charge) and bypassed WC's AJAX validation on the classic flow.
        return true;
      })
      .catch(function (err) {
        tokenizingInFlight = false;
        setError(err && err.message ? err.message : "Tokenization error.");
        return false;
      });
  }

  // Phase 4.12y2 — wallet bootstrap. We need a checkout session id to
  // fetch the wallet config. The SDK creates one as part of
  // controller.tokenize() — but that's too late for rendering the
  // wallet button. Best path: ask the SDK to mint or surface its
  // session id eagerly. If the SDK exposes a getSessionId() helper
  // (post-0.4 builds), use it; otherwise we leave wallets dormant
  // until the SDK exposes a stable bridge. The tier_1/2 first-merchant
  // onboarding is what activates this surface anyway (per
  // docs/WALLETS_2026-05-18.md §"What blocks live rollout") and that
  // milestone will ship alongside an SDK that exposes the session id
  // up-front. Until then the wallet bootstrap is a graceful no-op on
  // tier_3+ (the server returns enable_wallets=false even if we did
  // mint a session) and the wallet UI just doesn't render.
  // Apple Pay express (0.5.7) — guard so a re-fire (WC `updated_checkout`
  // fragment swap) can't paint a stale button against an old mint. Each
  // bootstrap run bumps the token; only the latest run is allowed to
  // render.
  var walletBootstrapToken = 0;

  // Apple Pay express (0.5.7) — POST an admin-ajax express action with
  // the express nonce. Returns the parsed `data` payload on success, or
  // null on any failure (the wallet button just stays dormant — no
  // banner).
  function postExpressAjax(url, params) {
    if (!url) return Promise.resolve(null);
    var body = "nonce=" + encodeURIComponent((cfg && cfg.expressNonce) || "");
    Object.keys(params || {}).forEach(function (k) {
      body += "&" + encodeURIComponent(k) + "=" + encodeURIComponent(params[k]);
    });
    return fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: body,
      credentials: "same-origin",
    })
      .then(function (res) {
        return res.json().catch(function () { return null; });
      })
      .then(function (json) {
        if (!json || json.success !== true || !json.data) return null;
        return json.data;
      })
      .catch(function () { return null; });
  }

  // Apple Pay express (0.5.7) — replaces the dead getSessionId() chain.
  // The checkout express button now mints a real WC order + Veyra
  // session up front, then gates on the server wallet config exactly
  // like the in-app iframe lane. On any failure the surface stays
  // dormant with no banner (graceful no-op on tier_3+/disabled).
  function tryBootstrapWallets() {
    if (!cfg) cfg = window.veyragatePayConfig || {};

    var fields = document.querySelector("[data-veyra-gateway]");
    if (!fields) return;

    // Capability gate first — never mint an order on a browser that
    // can't paint the sheet.
    if (!window.VeyraWalletCore || !window.VeyraWalletCore.isAvailable()) {
      return;
    }
    // Loading guard — wait for the express endpoints to be localized.
    if (!cfg.expressOrderAjaxUrl || !cfg.expressSessionAjaxUrl) {
      return; // the ready() setTimeout retry re-enters once localized
    }

    var myToken = ++walletBootstrapToken;
    var cancelled = false;
    function isStale() {
      return cancelled || myToken !== walletBootstrapToken;
    }

    // 1. Create / reuse the pending express WC order.
    postExpressAjax(cfg.expressOrderAjaxUrl, {
      action: "veyragate_pay_express_order",
    })
      .then(function (orderData) {
        if (isStale() || !orderData || !orderData.wc_order_id) return null;
        // 2. Mint a Veyra checkout session for that order.
        return postExpressAjax(cfg.expressSessionAjaxUrl, {
          action: "veyragate_pay_express_session",
          wc_order_id: orderData.wc_order_id,
        }).then(function (sessionData) {
          if (isStale() || !sessionData || !sessionData.session_id) return null;
          return {
            sessionId: sessionData.session_id,
            wcOrderId: orderData.wc_order_id,
            orderReceivedUrl: sessionData.order_received_url || "",
          };
        });
      })
      .then(function (minted) {
        if (isStale() || !minted) return null;
        // 3. Gate on the server wallet config (tier_3 apex => enabled).
        return fetchWalletConfig(cfg.apiBaseUrl, minted.sessionId).then(
          function (walletCfg) {
            // No isStale() here: once a chain reaches the config step we
            // always render. On a multi-plugin checkout (crypto/Zelle
            // here) co-installed gateways fire their own `updated_checkout`,
            // which would bump the bootstrap token and make an in-flight
            // render go stale — leaving NO wallet button at all. Render is
            // idempotent (renderWalletBlock removes any prior block), so a
            // late/duplicate render is harmless; a missing button is not.
            if (!walletCfg || !walletCfg.enable_wallets) return null;
            walletCfg.session_id = minted.sessionId;
            walletCfg.wc_order_id = minted.wcOrderId;
            walletCfg.order_received_url = minted.orderReceivedUrl || "";
            return walletCfg;
          }
        );
      })
      .then(function (walletCfg) {
        if (!walletCfg || !walletCfg.enable_wallets) return;
        // 4. Render IMMEDIATELY. Apple Pay must NEVER wait on the Google
        //    Pay SDK. Previously this awaited prepareGooglePayReadiness()
        //    before rendering, so a slow/blocked pay.google.com load
        //    stalled the entire wallet surface and the Apple Pay button
        //    never painted (the bug behind apex showing no Apple Pay).
        //    Apple renders now; Google is added in the background below.
        renderWalletBlock(walletCfg);

        // 5. Best-effort Google Pay readiness in the BACKGROUND. If the
        //    SDK loads and the device is ready, re-render to add the
        //    Google button alongside Apple. Never blocks Apple; the
        //    watchdog in loadGooglePaySdk guarantees this can't hang.
        if (walletCfg.google_pay_enabled && walletCfg.basis_theory_tenant_id) {
          prepareGooglePayReadiness(walletCfg)
            .then(function (readyCfg) {
              if (!readyCfg || !readyCfg.google_pay_ready) return;
              renderWalletBlock(readyCfg);
            })
            .catch(function () {});
        }
      });

    // Render (or re-render) the wallet button block for this bootstrap
    // run, replacing any prior block to avoid stacking duplicates.
    function renderWalletBlock(walletCfg) {
      try { window.__veyraWalletCfg = walletCfg; } catch (_e) {}
      var prior = fields.querySelector('[data-veyra-wallets="1"]');
      if (prior && prior.parentNode) {
        try { prior.parentNode.removeChild(prior); } catch (_e) {}
      }
      renderWalletButtons(fields, walletCfg, onWalletClick);
    }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", ready, { once: true });
  } else {
    setTimeout(ready, 0);
  }
})();
