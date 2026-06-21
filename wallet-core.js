/**
 * Veyra wallet core — shared Apple Pay sequence for the WooCommerce
 * plugin. Used by BOTH the checkout express button (classic.js) and the
 * mini-cart drawer button (minicart.js). One implementation, one set of
 * correctness guards.
 *
 * Apple Pay runs NATIVELY on the TOP-LEVEL page (never in an iframe).
 * Apple's PassKit validates the calling page's domain; Basis Theory owns
 * the Apple Pay merchant certificate and signs the merchant session for
 * that domain under its managed-cert tenant. The tokenized payment is
 * forwarded to Veyra's customer charge lane by the caller — this module
 * only paints the sheet and returns a token.
 *
 * ── LAYER SPLIT (read before "hardening" the address handling) ──────────
 * The CLIENT never promotes the wallet postal address into top-level
 * billing. The address travels ONLY inside `wallet_contact.shipping`.
 * The Veyra SERVER (`pay-bt/route.ts` -> `stripe-via-proxy-pure.ts`)
 * forwards that wallet address to the card processor's
 * billing_details[address] + shipping[*] for AMEX AVS, under
 * `collectBilling:"wallet_match_shape"`. Do NOT "harden" either side to
 * strip the address — stripping it re-breaks AMEX (F-AMEX-AVS). The
 * billing sheet stays name + email only; the shipping block carries the
 * address.
 *
 * No Stripe.js, ever. The only client credential touched is the Basis
 * Theory PUBLIC key (safe in the browser).
 */
(function () {
  "use strict";

  if (typeof window === "undefined") return;
  if (window.VeyraWalletCore) return; // single definition per page

  var BT_PROD = "https://api.basistheory.com";
  var BT_TEST = "https://api.test.basistheory.com";

  /**
   * Capability gate. Reflects native Apple Pay on Safari (mac/iOS); on a
   * non-Safari desktop, once Apple's web SDK has loaded, this returns
   * true when the cross-device "scan with your iPhone" flow is
   * available. We gate on canMakePayments(), NOT a UA sniff, so the
   * desktop QR flow is offered where Apple supports it. Wrapped because a
   * partial polyfill can throw.
   *
   * @returns {boolean}
   */
  function isAvailable() {
    if (typeof window === "undefined" || !window.ApplePaySession) return false;
    // Apple-platform gate (blueprint AF / evolabs F306): only Mac/iOS can
    // actually complete Apple Pay. A non-Apple desktop with the SDK loaded
    // must NEVER mint an order or paint an empty wallet section, so require an
    // Apple UA in addition to canMakePayments(). (iPadOS desktop-mode reports
    // "Macintosh", which this covers.)
    try {
      var ua = (navigator && navigator.userAgent) || "";
      if (!/iPhone|iPad|iPod|Macintosh|Mac OS X/i.test(ua)) return false;
    } catch (_e) {
      return false;
    }
    try {
      return typeof window.ApplePaySession.canMakePayments === "function"
        ? Boolean(window.ApplePaySession.canMakePayments())
        : false;
    } catch (_e) {
      return false;
    }
  }

  /**
   * Run the Apple Pay sheet and tokenize through Basis Theory.
   *
   * Resolves with { tokenId, walletContact, cardSummary } once the
   * customer authorizes AND BT returns a token id. The caller is then
   * responsible for charging via /pay-bt and calling `finalize(status)`
   * so the sheet closes ONLY after the real charge result is known.
   *
   * Rejects with an Error whose message is a stable code (never shown
   * raw to the customer): apple_pay_unavailable, apple_pay_cancelled,
   * apple_pay_merchant_validation_failed, apple_pay_tokenization_failed,
   * apple_pay_token_id_missing, apple_pay_begin_failed.
   *
   * @param {Object} args
   * @param {number} args.amountCents Server-authoritative total (minor units).
   * @param {string} args.currency ISO-4217 alpha-3 (lowercased ok).
   * @param {string} args.merchantName Neutral sheet name; falls back to "Secure Checkout".
   * @param {string} args.btPublicKey Basis Theory PUBLIC key.
   * @param {"test"|"production"} args.environment
   * @param {string} [args.countryCode]
   * @param {function(boolean):void} [args.finalize] Caller calls this with the
   *   real charge result; true => completePayment(SUCCESS), false => FAILURE.
   *   If omitted, the sheet is closed SUCCESS on token capture.
   * @returns {Promise<{tokenId:string, walletContact:Object, cardSummary:Object}>}
   */
  function runApplePay(args) {
    args = args || {};
    if (!window.ApplePaySession) {
      return Promise.reject(new Error("apple_pay_unavailable"));
    }

    var merchantName = args.merchantName || "Secure Checkout";
    var currency = String(args.currency || "USD").toUpperCase();
    var country = String(args.countryCode || "US").toUpperCase();
    var amountCents = Number(args.amountCents) || 0;
    var amountStr = (amountCents / 100).toFixed(2);
    var btHost = args.environment === "production" ? BT_PROD : BT_TEST;
    var btKey = args.btPublicKey;

    var ApplePaySession = window.ApplePaySession;

    return new Promise(function (resolve, reject) {
      // submittingRef  — true during the in-flight tokenize/charge handoff
      // paymentDoneRef — true permanently once we've handed a token back
      var submittingRef = false;
      var paymentDoneRef = false;
      var settled = false;

      function settle(fn) {
        if (settled) return;
        settled = true;
        fn();
      }

      var session;
      try {
        session = new ApplePaySession(6, {
          countryCode: country,
          currencyCode: currency,
          merchantCapabilities: ["supports3DS"],
          supportedNetworks: ["visa", "masterCard", "amex", "discover"],
          // Shipping fields drive AMEX AVS (the address rides
          // wallet_contact.shipping server-side). Intentional + matches
          // the proven evobones lane — NOT masked-posture scope creep.
          requiredShippingContactFields: ["postalAddress", "name", "email", "phone"],
          // Billing stays name + email ONLY — never postalAddress. iOS
          // needs 'email' here or billingContact.emailAddress is empty
          // and AMEX issuers decline (code 100). The postal address is
          // forwarded by the SERVER from wallet_contact.shipping; the
          // client never promotes it into billing.
          requiredBillingContactFields: ["name", "email"],
          // type MUST be "final": the total is fixed (flat shipping is baked
          // into amountStr and there is no dynamic shipping/total recompute
          // handler on this sheet). "pending" makes Apple render the total as
          // "Amount Pending" instead of the dollar amount — the bug a shopper
          // saw when tapping Apple Pay.
          total: { label: merchantName, amount: amountStr, type: "final" },
        });
      } catch (_e) {
        reject(new Error("apple_pay_begin_failed"));
        return;
      }

      // ── Merchant validation — BT signs under its managed cert ──
      session.onvalidatemerchant = function (event) {
        fetch(btHost + "/apple-pay/session", {
          method: "POST",
          headers: {
            "BT-API-KEY": btKey,
            "Content-Type": "application/json",
          },
          body: JSON.stringify({
            validation_url: event && event.validationURL,
            display_name: merchantName,
            domain:
              window.location && window.location.hostname
                ? window.location.hostname
                : "",
          }),
        })
          .then(function (res) {
            if (!res.ok) {
              try { session.abort(); } catch (_e) {}
              settle(function () {
                reject(new Error("apple_pay_merchant_validation_failed"));
              });
              return null;
            }
            return res.json();
          })
          .then(function (merchantSession) {
            if (!merchantSession) return;
            try { session.completeMerchantValidation(merchantSession); } catch (_e) {}
          })
          .catch(function () {
            try { session.abort(); } catch (_e) {}
            settle(function () {
              reject(new Error("apple_pay_merchant_validation_failed"));
            });
          });
      };

      // ── Payment authorized — tokenize → hand back to caller ──
      session.onpaymentauthorized = function (event) {
        // iOS can fire onpaymentauthorized twice for one approval. On the
        // duplicate we MUST ack the sheet or it hangs (surfaces as a
        // generic failure modal). Never tokenize twice.
        if (submittingRef || paymentDoneRef) {
          try { session.completePayment(ApplePaySession.STATUS_SUCCESS); } catch (_e) {}
          return;
        }
        submittingRef = true;

        var payment = (event && event.payment) || {};
        var token = payment.token;
        var bc = payment.billingContact || {};
        var sc = payment.shippingContact || {};

        if (!token) {
          try { session.completePayment(ApplePaySession.STATUS_FAILURE); } catch (_e) {}
          submittingRef = false;
          settle(function () { reject(new Error("apple_pay_token_missing")); });
          return;
        }

        // Cardholder name: prefer billing contact, fall back to shipping.
        var bcGiven = (bc.givenName || "").trim();
        var bcFamily = (bc.familyName || "").trim();
        var scGiven = (sc.givenName || "").trim();
        var scFamily = (sc.familyName || "").trim();
        var cardholderName =
          bcGiven && bcFamily
            ? bcGiven + " " + bcFamily
            : bcGiven ||
              bcFamily ||
              (scGiven && scFamily
                ? scGiven + " " + scFamily
                : scGiven || scFamily || null);
        // Email: prefer billing, then shipping (required on shipping so
        // it's usually present). Never substitute a placeholder.
        var cardholderEmail =
          (bc.emailAddress || "").trim() ||
          (sc.emailAddress || "").trim() ||
          null;

        // wallet_contact — the address rides the nested `shipping` block.
        // The client NEVER promotes it into top-level billing (layer
        // split above).
        var walletContact = {
          name: cardholderName,
          email: cardholderEmail,
          phone: (bc.phoneNumber || sc.phoneNumber || "").trim() || null,
          shipping: {
            name:
              [sc.givenName, sc.familyName].filter(Boolean).join(" ") || null,
            address1: (sc.addressLines && sc.addressLines[0]) || null,
            address2: (sc.addressLines && sc.addressLines[1]) || null,
            city: sc.locality || null,
            state: sc.administrativeArea || null,
            zip: sc.postalCode || null,
            country: sc.countryCode || null,
            email: sc.emailAddress || null,
            phone: sc.phoneNumber || null,
          },
        };

        fetch(btHost + "/apple-pay", {
          method: "POST",
          headers: {
            "BT-API-KEY": btKey,
            "Content-Type": "application/json",
          },
          body: JSON.stringify({ apple_payment_data: token }),
        })
          .then(function (res) {
            if (!res.ok) {
              try { session.completePayment(ApplePaySession.STATUS_FAILURE); } catch (_e) {}
              submittingRef = false;
              settle(function () {
                reject(new Error("apple_pay_tokenization_failed"));
              });
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
              try { session.completePayment(ApplePaySession.STATUS_FAILURE); } catch (_e) {}
              submittingRef = false;
              settle(function () {
                reject(new Error("apple_pay_token_id_missing"));
              });
              return;
            }

            paymentDoneRef = true;
            // Hand the token + contact back to the caller. The caller
            // charges via /pay-bt and decides the sheet outcome via the
            // `finalize` callback; we close the sheet ourselves only when
            // no finalize was supplied.
            var finalize =
              typeof args.finalize === "function"
                ? args.finalize
                : function (ok) {
                    try {
                      session.completePayment(
                        ok
                          ? ApplePaySession.STATUS_SUCCESS
                          : ApplePaySession.STATUS_FAILURE
                      );
                    } catch (_e) {}
                  };
            // Bind finalize to the live session so the caller can close
            // the sheet after the real charge result. Stash it on the
            // resolved object too for callers that prefer the explicit
            // handle.
            var boundFinalize = function (ok) {
              try {
                session.completePayment(
                  ok
                    ? ApplePaySession.STATUS_SUCCESS
                    : ApplePaySession.STATUS_FAILURE
                );
              } catch (_e) {}
            };
            if (typeof args.finalize !== "function") {
              // No caller finalize — default to SUCCESS so the sheet
              // closes on token capture (the caller is expected to then
              // run the charge; this branch only fires when finalize was
              // intentionally omitted).
              try { session.completePayment(ApplePaySession.STATUS_SUCCESS); } catch (_e) {}
            }
            void finalize;

            settle(function () {
              resolve({
                tokenId: tokenId,
                walletContact: walletContact,
                cardSummary: {},
                finalize: boundFinalize,
              });
            });
          })
          .catch(function () {
            if (!paymentDoneRef) {
              try { session.completePayment(ApplePaySession.STATUS_FAILURE); } catch (_e) {}
              submittingRef = false;
              settle(function () {
                reject(new Error("apple_pay_authorization_failed"));
              });
            }
          });
      };

      session.oncancel = function () {
        // User backed out — release the guard so a retry can proceed and
        // surface a stable cancel code (callers treat it as silent).
        submittingRef = false;
        settle(function () { reject(new Error("apple_pay_cancelled")); });
      };

      try {
        session.begin();
      } catch (_e) {
        settle(function () { reject(new Error("apple_pay_begin_failed")); });
      }
    });
  }

  window.VeyraWalletCore = {
    isAvailable: isAvailable,
    runApplePay: runApplePay,
  };
})();
