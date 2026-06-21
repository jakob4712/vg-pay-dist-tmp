/**
 * Veyra slide-out mini-cart drawer (0.7.0).
 *
 * Replaces the old "inject a button into a theme drawer" approach (apex has
 * no theme drawer — its cart icon just links to /cart). This builds an
 * actual slide-out drawer: click the cart icon -> a panel slides in with the
 * live cart (items + subtotal), a Checkout button, a View-cart link, and —
 * on Apple-capable browsers only — an Apple Pay express button.
 *
 * SAFETY — this is a FAIL-OPEN, ADDITIVE layer:
 *   - The cart-icon click is only intercepted if the drawer builds cleanly.
 *     If anything throws, we do NOT preventDefault, so the cart link just
 *     navigates to /cart exactly as it does today. It can never trap a click.
 *   - Cart contents come from a READ-ONLY server endpoint (WC()->cart is
 *     never mutated).
 *   - The Apple Pay path is identical to the checkout lane (order created
 *     BEFORE charge; charge via the same /pay-bt proxy). No Stripe.js, no
 *     provider branding. Self-hides on non-Apple browsers.
 */
(function () {
  "use strict";

  if (typeof window === "undefined") return;
  var CFG = window.veyragatePayMiniCart || null;
  if (!CFG || !CFG.enabled) return;

  var APPLE_MARK =
    '<svg class="veyragate-pay-wallet__mark" width="20" height="24" viewBox="0 0 17 21" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">' +
    '<path d="M13.34 11.09c-.02-2.17 1.77-3.21 1.85-3.26-1.01-1.47-2.58-1.67-3.14-1.7-1.34-.14-2.61.79-3.29.79-.68 0-1.73-.77-2.84-.75-1.46.02-2.81.85-3.56 2.16-1.52 2.63-.39 6.53 1.09 8.67.72 1.05 1.59 2.22 2.72 2.18 1.09-.04 1.5-.71 2.82-.71 1.32 0 1.7.71 2.85.69 1.17-.02 1.93-1.07 2.65-2.12.83-1.21 1.17-2.39 1.19-2.45-.03-.01-2.29-.88-2.31-3.49l-.03-.01zM11.15 4.44c.6-.73 1.01-1.74.9-2.75-.87.04-1.92.58-2.54 1.3-.56.64-1.05 1.67-.92 2.66.97.08 1.96-.49 2.56-1.21z" fill="currentColor"/>' +
    "</svg>";

  // ── drawer state ──
  var drawer = null, overlay = null, bodyEl = null, footEl = null;
  var built = false, isOpen = false;

  // ── wallet state ──
  var sessionId = null, wcOrderId = null, amountCents = 0, currency = "usd";
  var walletConfig = null, orderReceivedUrl = "";
  var inFlightMint = null, paymentDoneRef = false, submittingRef = false;

  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"]/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c];
    });
  }

  // ── express ajax (same contract as classic.js) ──
  function postExpressAjax(url, params) {
    if (!url) return Promise.resolve(null);
    var body = "nonce=" + encodeURIComponent(CFG.expressNonce || "");
    Object.keys(params || {}).forEach(function (k) {
      body += "&" + encodeURIComponent(k) + "=" + encodeURIComponent(params[k]);
    });
    return fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: body,
      credentials: "same-origin",
    })
      .then(function (res) { return res.json().catch(function () { return null; }); })
      .then(function (json) {
        if (!json || json.success !== true || !json.data) return null;
        return json.data;
      })
      .catch(function () { return null; });
  }

  function fetchWalletConfig(sid) {
    if (!sid) return Promise.resolve(null);
    var url =
      CFG.expressConfigAjaxUrl +
      "?action=veyragate_pay_express_config&nonce=" +
      encodeURIComponent(CFG.expressNonce) +
      "&session_id=" +
      encodeURIComponent(sid);
    return fetch(url, { credentials: "same-origin", headers: { Accept: "application/json" } })
      .then(function (res) { return res.ok ? res.json() : null; })
      .then(function (cfg) {
        if (!cfg || typeof cfg !== "object") return null;
        if (cfg.capture_engine !== "basis_theory_elements") return null;
        var w = cfg.wallets || {}, bt = cfg.basis_theory || {};
        var ap = typeof w.apple_pay_enabled === "boolean"
          ? w.apple_pay_enabled
          : Boolean(w.apple_pay_merchant_identifier);
        if (!w.enable_wallets || !ap || !bt.public_api_key) return null;
        return {
          enable_wallets: true,
          apple_pay_enabled: true,
          merchant_name: w.merchant_name || "",
          basis_theory_public_api_key: bt.public_api_key,
          environment: bt.environment === "production" ? "production" : "test",
          amount_cents: typeof cfg.amount_cents === "number" ? cfg.amount_cents : 0,
          currency: cfg.currency || "usd",
        };
      })
      .catch(function () { return null; });
  }

  // ── drawer DOM (built once, lazily) ──
  function buildDrawer() {
    if (built) return drawer;
    if (!document.body) return null;
    overlay = document.createElement("div");
    overlay.className = "veyra-drawer-overlay";
    overlay.addEventListener("click", closeDrawer);

    drawer = document.createElement("aside");
    drawer.className = "veyra-drawer";
    drawer.setAttribute("role", "dialog");
    drawer.setAttribute("aria-label", "Shopping cart");
    drawer.setAttribute("aria-hidden", "true");
    drawer.innerHTML =
      '<div class="veyra-drawer__head">' +
        '<span class="veyra-drawer__title">Your cart</span>' +
        '<button type="button" class="veyra-drawer__close" aria-label="Close cart">&times;</button>' +
      "</div>" +
      '<div class="veyra-drawer__body" aria-live="polite"></div>' +
      '<div class="veyra-drawer__foot"></div>';

    document.body.appendChild(overlay);
    document.body.appendChild(drawer);
    drawer.querySelector(".veyra-drawer__close").addEventListener("click", closeDrawer);
    bodyEl = drawer.querySelector(".veyra-drawer__body");
    footEl = drawer.querySelector(".veyra-drawer__foot");
    document.addEventListener("keydown", function (e) {
      if ((e.key === "Escape" || e.keyCode === 27) && isOpen) closeDrawer();
    });
    built = true;
    return drawer;
  }

  function openDrawer() {
    if (!buildDrawer()) return;
    isOpen = true;
    overlay.classList.add("is-open");
    drawer.classList.add("is-open");
    drawer.setAttribute("aria-hidden", "false");
    try { document.documentElement.classList.add("veyra-drawer-lock"); } catch (_e) {}
    refresh();
  }

  function closeDrawer() {
    if (!drawer) return;
    isOpen = false;
    overlay.classList.remove("is-open");
    drawer.classList.remove("is-open");
    drawer.setAttribute("aria-hidden", "true");
    try { document.documentElement.classList.remove("veyra-drawer-lock"); } catch (_e) {}
  }

  // ── render ──
  function renderLoading() {
    if (!bodyEl) return;
    bodyEl.innerHTML =
      '<div class="veyra-drawer__skel"></div><div class="veyra-drawer__skel"></div><div class="veyra-drawer__skel"></div>';
    footEl.innerHTML = "";
  }

  function renderError() {
    if (!bodyEl) return;
    bodyEl.innerHTML = '<div class="veyra-drawer__msg">We couldn’t load your cart.</div>';
    footEl.innerHTML =
      '<a class="veyra-drawer__btn veyra-drawer__btn--primary" href="' +
      esc(CFG.cartUrl || "/cart/") + '">Open cart</a>';
  }

  function renderCart(d) {
    if (!bodyEl) return;
    var count = d && d.count ? d.count : 0;
    if (!count) {
      bodyEl.innerHTML =
        '<div class="veyra-drawer__msg veyra-drawer__msg--empty">' +
        '<div class="veyra-drawer__emoji">🧪</div><p>Your cart is empty.</p></div>';
      footEl.innerHTML =
        '<a class="veyra-drawer__btn veyra-drawer__btn--ghost" href="' +
        esc(CFG.shopUrl || "/") + '">Continue shopping</a>';
      return;
    }
    var rows = (d.items || []).map(function (it) {
      return (
        '<div class="veyra-citem">' +
        '<div class="veyra-citem__thumb">' +
        (it.thumb ? '<img src="' + esc(it.thumb) + '" alt="" loading="lazy">' : "") +
        "</div>" +
        '<div class="veyra-citem__main">' +
        '<div class="veyra-citem__name">' + esc(it.name) + "</div>" +
        '<div class="veyra-citem__qty">Qty ' + (it.qty || 1) + "</div></div>" +
        '<div class="veyra-citem__price">' + (it.line_html || "") + "</div>" +
        "</div>"
      );
    }).join("");
    bodyEl.innerHTML = rows;
    footEl.innerHTML =
      '<div class="veyra-drawer__sub"><span>Subtotal</span><span class="veyra-drawer__sub-amt">' +
      (d.subtotal_html || "") + "</span></div>" +
      '<div class="veyra-drawer__wallet veyragate-pay-fields"></div>' +
      '<a class="veyra-drawer__btn veyra-drawer__btn--primary" href="' +
      esc(d.checkout_url || CFG.checkoutUrl || "/checkout/") + '">Checkout</a>' +
      '<a class="veyra-drawer__btn veyra-drawer__btn--ghost" href="' +
      esc(d.cart_url || CFG.cartUrl || "/cart/") + '">View cart</a>';
    maybeMintAndRenderWallet();
  }

  function refresh() {
    renderLoading();
    fetch(
      CFG.cartContentsAjaxUrl +
        "?action=veyragate_pay_minicart_contents&nonce=" +
        encodeURIComponent(CFG.expressNonce),
      { credentials: "same-origin", headers: { Accept: "application/json" } }
    )
      .then(function (r) { return r.json().catch(function () { return null; }); })
      .then(function (j) { if (!j || !j.success) { renderError(); return; } renderCart(j.data); })
      .catch(function () { renderError(); });
  }

  // ── Apple Pay express inside the drawer footer (Apple browsers only) ──
  function maybeMintAndRenderWallet() {
    if (paymentDoneRef) return;
    if (!window.VeyraWalletCore || !window.VeyraWalletCore.isAvailable()) return;
    var container = footEl && footEl.querySelector(".veyra-drawer__wallet");
    if (!container) return;

    var myRun = {};
    inFlightMint = myRun;
    function stale() { return paymentDoneRef || inFlightMint !== myRun || !isOpen; }

    postExpressAjax(CFG.expressOrderAjaxUrl, { action: "veyragate_pay_express_order" })
      .then(function (orderData) {
        if (stale() || !orderData || !orderData.wc_order_id) return null;
        return postExpressAjax(CFG.expressSessionAjaxUrl, {
          action: "veyragate_pay_express_session",
          wc_order_id: orderData.wc_order_id,
        }).then(function (sd) {
          if (stale() || !sd || !sd.session_id) return null;
          return { sd: sd, wcOrderId: orderData.wc_order_id };
        });
      })
      .then(function (m) {
        if (stale() || !m) return null;
        return fetchWalletConfig(m.sd.session_id).then(function (wc) {
          if (stale() || !wc) return null;
          return { m: m, wc: wc };
        });
      })
      .then(function (out) {
        if (stale() || !out) return;
        sessionId = out.m.sd.session_id;
        wcOrderId = out.m.wcOrderId;
        walletConfig = out.wc;
        orderReceivedUrl = out.m.sd.order_received_url || "";
        amountCents = out.wc.amount_cents || out.m.sd.amount_cents || 0;
        currency = out.wc.currency || out.m.sd.currency || "usd";
        renderWalletButton(container);
      })
      .catch(function () {});
  }

  function renderWalletButton(container) {
    if (!container || amountCents < 50 || !walletConfig || !walletConfig.apple_pay_enabled) return;
    if (container.querySelector("[data-veyra-minicart-wallet]")) return;
    var wrap = document.createElement("div");
    wrap.setAttribute("data-veyra-minicart-wallet", "1");
    wrap.className = "veyragate-pay-wallets";
    var btn = document.createElement("button");
    btn.type = "button";
    btn.className = "veyragate-pay-wallet veyragate-pay-wallet--apple";
    btn.setAttribute("aria-label", "Pay with Apple Pay");
    btn.innerHTML = APPLE_MARK + "<span>Pay</span>";
    btn.addEventListener("click", function (ev) {
      ev.preventDefault();
      onMiniCartPay();
    });
    wrap.appendChild(btn);
    var div = document.createElement("div");
    div.className = "veyragate-pay-wallets__divider";
    div.innerHTML =
      '<span class="veyragate-pay-wallets__divider-line"></span>' +
      '<span class="veyragate-pay-wallets__divider-label">or</span>' +
      '<span class="veyragate-pay-wallets__divider-line"></span>';
    container.appendChild(wrap);
    container.appendChild(div);
  }

  function onMiniCartPay() {
    if (submittingRef || paymentDoneRef || !walletConfig || !sessionId || !window.VeyraWalletCore) return;
    submittingRef = true;
    window.VeyraWalletCore.runApplePay({
      amountCents: amountCents,
      currency: currency,
      merchantName: walletConfig.merchant_name || CFG.merchantNameFallback || "Secure Checkout",
      btPublicKey: walletConfig.basis_theory_public_api_key,
      environment: walletConfig.environment,
    })
      .then(function (result) {
        if (!result || !result.tokenId) {
          if (result && typeof result.finalize === "function") result.finalize(false);
          throw new Error("wallet_no_token");
        }
        return fetch(
          CFG.expressPayAjaxUrl +
            "?action=veyragate_pay_express_pay&nonce=" +
            encodeURIComponent(CFG.expressNonce),
          {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            credentials: "same-origin",
            body: JSON.stringify({
              session_id: sessionId,
              basis_theory_token_intent_id: result.tokenId,
              card_summary: {},
              wallet_type: "apple_pay",
              idempotency_key: "wc_" + wcOrderId,
              wallet_contact: result.walletContact || undefined,
            }),
          }
        )
          .then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (body) {
              return { res: res, body: body };
            });
          })
          .then(function (o) {
            var ok = o.res.ok && o.body && o.body.public_status === "succeeded";
            if (typeof result.finalize === "function") result.finalize(ok);
            submittingRef = false;
            if (!ok) return;
            paymentDoneRef = true;
            var redirect =
              (o.body && o.body.redirect_url) ||
              orderReceivedUrl ||
              (window.location.origin + "/checkout/order-received/" + encodeURIComponent(wcOrderId) + "/");
            if (redirect) window.location.assign(redirect);
          });
      })
      .catch(function () { submittingRef = false; });
  }

  // ── cart-icon hook (FAIL-OPEN) ──
  function isCartLink(a) {
    if (!a || !a.getAttribute) return false;
    try {
      if (a.matches && a.matches("a.wpmenucart-contents, .wpmenucart a, .menu-cart-pro a, a.cart-contents")) return true;
    } catch (_e) {}
    var href = a.getAttribute("href") || "";
    return /\/cart\/?(?:[?#].*)?$/.test(href);
  }

  function onDocClick(e) {
    var a = e.target && e.target.closest ? e.target.closest("a") : null;
    if (!isCartLink(a)) return;
    var ok = false;
    try { ok = !!buildDrawer(); } catch (_e) { ok = false; }
    if (!ok) return; // fail-open: let the cart link navigate to /cart
    e.preventDefault();
    openDrawer();
  }

  function bindCartEvents() {
    if (!window.jQuery) return;
    var $ = window.jQuery;
    // Auto-open on add-to-cart (only fires on AJAX add-to-cart themes).
    $(document.body).on("added_to_cart", function () { try { openDrawer(); } catch (_e) {} });
    // Refresh the drawer if it's open when the cart changes.
    $(document.body).on("removed_from_cart wc_fragments_refreshed updated_wc_div", function () {
      if (isOpen) refresh();
    });
  }

  function init() {
    document.addEventListener("click", onDocClick, false);
    bindCartEvents();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init, { once: true });
  } else {
    setTimeout(init, 0);
  }
})();
