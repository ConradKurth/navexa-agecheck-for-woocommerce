/*! Navexa AgeCheck classic checkout | Navexa AgeCheck for WooCommerce | GPL-2.0-or-later | source: assets/src/loader/ */
"use strict";
(() => {
  // assets/src/loader/store-api.ts
  var StoreApiError = class extends Error {
  };
  var StartRefused = class extends Error {
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
    async function post(action, value, extra) {
      return doFetch(`${base}cart/extensions`, {
        method: "POST",
        credentials: "same-origin",
        cache: "no-store",
        headers: headers({ "Content-Type": "application/json", Nonce: value }),
        body: JSON.stringify({ namespace: options.namespace, data: { ...extra, v: 1, action } })
      });
    }
    return async (action) => {
      var _a2;
      let extra = {};
      if (action === "start") {
        if (options.beforeStart) await options.beforeStart();
        if (options.startData) {
          try {
            extra = await options.startData();
          } catch (err) {
            if (err instanceof StartRefused) return { v: 1, status: "refused", message: err.message };
            throw err;
          }
        }
      }
      let res = await post(action, nonce != null ? nonce : await freshNonce(), extra);
      if (res.status === 401 || res.status === 403) {
        res = await post(action, await freshNonce(), extra);
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

  // assets/src/loader/a11y.ts
  var LIVE_REGION_ID = "navexa-agecheck-live";
  var VISUALLY_HIDDEN = "position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0";
  function liveRegion(doc) {
    const existing = doc.getElementById(LIVE_REGION_ID);
    if (existing) return existing;
    if (!doc.body) return null;
    const region = doc.createElement("div");
    region.id = LIVE_REGION_ID;
    region.setAttribute("role", "status");
    region.setAttribute("aria-live", "polite");
    region.setAttribute("aria-atomic", "true");
    region.setAttribute("style", VISUALLY_HIDDEN);
    doc.body.appendChild(region);
    return region;
  }
  function announce(doc, text, defer = (fn) => void setTimeout(fn, 50)) {
    const region = liveRegion(doc);
    if (!region || !text) return "";
    region.textContent = "";
    defer(() => {
      region.textContent = text;
    });
    return text;
  }
  function mayRestoreFocus(doc, container, owned) {
    if (!owned) return false;
    const active = doc.activeElement;
    if (active === null || active === void 0 || active === doc.body) return true;
    return !!container && typeof container.contains === "function" && container.contains(active);
  }
  function focusElement(target) {
    if (!target || typeof target.focus !== "function") return false;
    try {
      target.focus();
      return true;
    } catch {
      return false;
    }
  }
  var FOCUS_RETRIES = 10;
  var FOCUS_RETRY_MS = 100;
  function focusOnReturn(target, win, mayMove = () => true) {
    const done = focusElement(target);
    if (!done || !win) return done;
    let tries = 0;
    const retry = () => {
      if (win.document.activeElement === target || ++tries > FOCUS_RETRIES || !mayMove()) return;
      focusElement(target);
      win.setTimeout(retry, FOCUS_RETRY_MS);
    };
    win.setTimeout(retry, FOCUS_RETRY_MS);
    return done;
  }

  // assets/src/loader/classic.ts
  var CONTAINER = "[data-navexa-agecheck]";
  var BUTTON = "[data-navexa-agecheck-verify]";
  var MESSAGE = ".navexa-agecheck__message";
  var TURNSTILE = "[data-navexa-agecheck-turnstile]";
  var UPDATE_TIMEOUT_MS = 8e3;
  function boot() {
    var _a, _b;
    const data = window.navexaAgeCheckClassic;
    const api = window.NavexaAgeCheck;
    if (!data || !api) return;
    const $ = window.jQuery;
    const doc = document;
    const t = (key) => {
      var _a2;
      return (_a2 = data.i18n[key]) != null ? _a2 : "";
    };
    const turnstile = data.turnstile && window.NavexaAgeCheckTurnstile ? window.NavexaAgeCheckTurnstile.create(data.turnstile) : null;
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
    const startData = async () => {
      if (!data.turnstile) return {};
      const token = turnstile ? await turnstile.token(document.querySelector(TURNSTILE)) : null;
      if (!token) throw new StartRefused((turnstile == null ? void 0 : turnstile.challenging()) ? t("challenge") : t("turnstile"));
      return { turnstile: token };
    };
    const initial = (_b = (_a = document.querySelector(CONTAINER)) == null ? void 0 : _a.getAttribute("data-status")) != null ? _b : "none";
    let lastPassed = initial === "passed";
    let lastAnnounced = "";
    let owned = false;
    let focusAfterUpdate = false;
    const messageFor = (state) => state.message || (state.reason === "closed" ? t("closed") : state.busy && state.status !== "pending" ? t("checking") : t(state.status === "none" || state.status === "refused" ? "prompt" : state.status));
    const restoreFocus = (passed) => {
      const container = document.querySelector(CONTAINER);
      if (!mayRestoreFocus(doc, container, owned)) {
        owned = false;
        return;
      }
      owned = false;
      const target = passed ? document.querySelector(`${CONTAINER} ${MESSAGE}`) : (turnstile == null ? void 0 : turnstile.challenging()) ? document.querySelector(TURNSTILE) : document.querySelector(BUTTON);
      focusOnReturn(
        target != null ? target : document.querySelector(`${CONTAINER} ${MESSAGE}`),
        window,
        () => mayRestoreFocus(doc, document.querySelector(CONTAINER), true)
      );
    };
    const render = (state) => {
      const message = messageFor(state);
      document.querySelectorAll(CONTAINER).forEach((container) => {
        container.setAttribute("data-status", state.status);
        container.setAttribute("aria-busy", state.busy ? "true" : "false");
        const text = container.querySelector(MESSAGE);
        if (text && message) text.textContent = message;
        const button = container.querySelector(BUTTON);
        if (button) {
          button.setAttribute("aria-disabled", state.busy ? "true" : "false");
          button.textContent = state.busy ? t("verifying") : t("verify");
          button.hidden = state.status === "passed";
        }
      });
      if (message && message !== lastAnnounced && !(state.busy && state.status === "pending")) {
        lastAnnounced = announce(doc, message);
      }
      if (state.status === "passed" && !state.busy && !lastPassed && $) {
        focusAfterUpdate = owned;
        $(document.body).trigger("update_checkout");
      } else if (!state.busy && state.status !== "pending" && owned) {
        restoreFocus(state.status === "passed");
      }
      lastPassed = state.status === "passed";
    };
    const loader = api.create({
      transport: storeApiTransport({ base: data.storeApi, namespace: data.namespace, restNonce: data.restNonce, beforeStart: updateCheckout, startData }),
      status: initial,
      openingText: t("opening"),
      onChange: render
    });
    document.addEventListener("click", (event) => {
      const target = event.target;
      if (!target || typeof target.closest !== "function" || !target.closest(BUTTON)) return;
      event.preventDefault();
      if (loader.getState().busy) return;
      owned = true;
      lastAnnounced = "";
      loader.verify();
    });
    if ($) {
      $(document.body).on("updated_checkout", () => {
        var _a2, _b2;
        const state = loader.getState();
        if (state.busy) {
          render(state);
          return;
        }
        const text = (_b2 = (_a2 = document.querySelector(`${CONTAINER} ${MESSAGE}`)) == null ? void 0 : _a2.textContent) != null ? _b2 : "";
        if (text && text !== lastAnnounced) lastAnnounced = announce(doc, text);
        if (focusAfterUpdate) {
          focusAfterUpdate = false;
          owned = true;
          restoreFocus(state.status === "passed");
        }
      });
    }
    if (initial === "pending") void loader.refresh();
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
  else boot();
})();
