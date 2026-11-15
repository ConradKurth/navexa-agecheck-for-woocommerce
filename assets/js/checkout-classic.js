/*! Navexa AgeCheck classic checkout | Navexa AgeCheck for WooCommerce | GPL-2.0-or-later | source: assets/src/loader/ */
"use strict";
(() => {
  // assets/src/loader/store-api.ts
  var StoreApiError = class extends Error {
  };
  function storeApiTransport(options) {
    var _a;
    const doFetch = (_a = options.fetch) != null ? _a : ((input, init) => window.fetch(input, init));
    const base = options.base.endsWith("/") ? options.base : `${options.base}/`;
    let nonce = null;
    const headers = (extra = {}) => {
      const out = { Accept: "application/json", ...extra };
      if (options.restNonce) out["X-WP-Nonce"] = options.restNonce;
      return out;
    };
    async function freshNonce() {
      const res = await doFetch(`${base}cart`, { method: "GET", credentials: "same-origin", cache: "no-store", headers: headers() });
      const value = res.headers.get("Nonce");
      if (!res.ok || !value) throw new StoreApiError(`cart ${res.status}`);
      nonce = value;
      return value;
    }
    async function post(action, value) {
      return doFetch(`${base}cart/extensions`, {
        method: "POST",
        credentials: "same-origin",
        cache: "no-store",
        headers: headers({ "Content-Type": "application/json", Nonce: value }),
        body: JSON.stringify({ namespace: options.namespace, data: { v: 1, action } })
      });
    }
    return async (action) => {
      var _a2;
      if (action === "start" && options.beforeStart) await options.beforeStart();
      let res = await post(action, nonce != null ? nonce : await freshNonce());
      if (res.status === 401 || res.status === 403) {
        res = await post(action, await freshNonce());
      }
      const next = res.headers.get("Nonce");
      if (next) nonce = next;
      if (!res.ok) throw new StoreApiError(`extensions ${res.status}`);
      const cart = await res.json();
      const answer = (_a2 = cart == null ? void 0 : cart.extensions) == null ? void 0 : _a2[options.namespace];
      if (!answer || typeof answer.status !== "string") throw new StoreApiError("no answer");
      return answer;
    };
  }

  // assets/src/loader/classic.ts
  var CONTAINER = "[data-navexa-agecheck]";
  var BUTTON = "[data-navexa-agecheck-verify]";
  var UPDATE_TIMEOUT_MS = 8e3;
  function boot() {
    var _a, _b;
    const data = window.navexaAgeCheckClassic;
    const api = window.NavexaAgeCheck;
    if (!data || !api) return;
    const $ = window.jQuery;
    const t = (key) => {
      var _a2;
      return (_a2 = data.i18n[key]) != null ? _a2 : "";
    };
    const updateCheckout = () => new Promise((resolve) => {
      if (!$ || !document.querySelector("form.checkout")) {
        resolve();
        return;
      }
      let done = false;
      const finish = () => {
        if (done) return;
        done = true;
        $(document.body).off("updated_checkout", finish);
        $(document.body).off("checkout_error", finish);
        resolve();
      };
      $(document.body).one("updated_checkout", finish);
      $(document.body).one("checkout_error", finish);
      window.setTimeout(finish, UPDATE_TIMEOUT_MS);
      $(document.body).trigger("update_checkout");
    });
    const initial = (_b = (_a = document.querySelector(CONTAINER)) == null ? void 0 : _a.getAttribute("data-status")) != null ? _b : "none";
    let lastPassed = initial === "passed";
    const render = (state) => {
      const message = state.message || (state.reason === "closed" ? t("closed") : state.busy && state.status !== "pending" ? t("checking") : t(state.status === "none" || state.status === "refused" ? "prompt" : state.status));
      document.querySelectorAll(CONTAINER).forEach((container) => {
        container.setAttribute("data-status", state.status);
        const text = container.querySelector(".navexa-agecheck__message");
        if (text && message) text.textContent = message;
        const button = container.querySelector(BUTTON);
        if (button) {
          button.disabled = state.busy;
          button.textContent = state.busy ? t("verifying") : t("verify");
          button.hidden = state.status === "passed";
        }
      });
      if (state.status === "passed" && !state.busy && !lastPassed && $) {
        $(document.body).trigger("update_checkout");
      }
      lastPassed = state.status === "passed";
    };
    const loader = api.create({
      transport: storeApiTransport({ base: data.storeApi, namespace: data.namespace, restNonce: data.restNonce, beforeStart: updateCheckout }),
      status: initial,
      openingText: t("opening"),
      onChange: render
    });
    document.addEventListener("click", (event) => {
      const target = event.target;
      if (!target || typeof target.closest !== "function" || !target.closest(BUTTON)) return;
      event.preventDefault();
      loader.verify();
    });
    if ($) {
      $(document.body).on("updated_checkout", () => {
        const state = loader.getState();
        if (state.busy) render(state);
      });
    }
    if (initial === "pending") void loader.refresh();
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
  else boot();
})();
