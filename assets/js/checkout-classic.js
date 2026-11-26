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
  function focusElement(target, options) {
    if (!target || typeof target.focus !== "function") return false;
    try {
      if (options) target.focus(options);
      else target.focus();
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

  // assets/src/loader/types.ts
  var SUBMIT_ANYWAY_CODES = ["no_address", "not_required", "pickup"];
  function statusKey(status) {
    switch (status) {
      case "passed":
      case "failed":
      case "pending":
      case "unavailable":
        return status;
      case "none":
        return "pending";
      default:
        return "unavailable";
    }
  }
  function originOf(url) {
    try {
      return new URL(url).origin;
    } catch {
      return "";
    }
  }

  // assets/src/loader/place-order.ts
  function failureMessage(answer, closed, t) {
    if (answer.message) return answer.message;
    if (closed && answer.status !== "failed") return t("closed");
    return t(statusKey(answer.status));
  }
  function createPlaceOrderFlow(deps) {
    let bypass = false;
    let running = false;
    let blocked = false;
    const block = () => {
      if (!blocked) {
        blocked = true;
        deps.block();
      }
    };
    const unblock = () => {
      if (blocked) {
        blocked = false;
        deps.unblock();
      }
    };
    const resubmit = () => {
      unblock();
      bypass = true;
      try {
        deps.submit();
      } finally {
        bypass = false;
      }
    };
    async function run() {
      if (running) return;
      running = true;
      block();
      try {
        await deps.updateCheckout();
        if (deps.status() === "passed") {
          resubmit();
          return;
        }
        let answer = await deps.transport("start");
        if (answer.status === "refused" && answer.code && SUBMIT_ANYWAY_CODES.includes(answer.code)) {
          resubmit();
          return;
        }
        let closed = false;
        if (answer.status === "pending" && answer.hosted_url) {
          unblock();
          let result = null;
          try {
            result = await deps.verify(answer.hosted_url);
          } catch {
            result = null;
          }
          closed = (result == null ? void 0 : result.status) === "closed";
          block();
          answer = await deps.transport("refresh");
        }
        if (answer.status === "passed") {
          deps.passed();
          resubmit();
          return;
        }
        deps.fail(failureMessage(answer, closed, deps.t), answer.status === "refused" && deps.challenging());
      } catch {
        deps.fail(deps.t("unavailable"), false);
      } finally {
        running = false;
        unblock();
      }
    }
    return {
      onPlaceOrder(event) {
        if (bypass) return void 0;
        const status = deps.status();
        if (status === null || status === "passed") return void 0;
        event.stopImmediatePropagation();
        if (!running) void run();
        return false;
      },
      run,
      busy: () => running
    };
  }

  // assets/src/loader/lock.ts
  var LOCKED_ATTRIBUTE = "data-navexa-agecheck-locked";
  var MIRROR_ATTRIBUTE = "data-navexa-agecheck-mirror";
  var PARTS = ["first_name", "last_name", "country", "address_1", "address_2", "city", "state", "postcode"];
  var LOCKED_FIELDS = [...PARTS.map((p) => `billing_${p}`), ...PARTS.map((p) => `shipping_${p}`), "ship_to_different_address"];
  function lockKind(field) {
    const tag = field.tagName.toUpperCase();
    const type = (field.type || "").toLowerCase();
    if (tag === "SELECT") return field.disabled ? null : "disabled";
    if (tag === "TEXTAREA") return field.readOnly || field.disabled ? null : "readonly";
    if (tag !== "INPUT" || field.disabled) return null;
    if (type === "checkbox") return "disabled";
    if (["hidden", "radio", "button", "submit", "file", "image", "reset"].includes(type)) return null;
    return field.readOnly ? null : "readonly";
  }
  function controls(form) {
    const out = [];
    const all = form.querySelectorAll(LOCKED_FIELDS.map((name) => `[name="${name}"]`).join(","));
    for (let i = 0; i < all.length; i++) {
      const field = all[i];
      if (field.getAttribute(MIRROR_ATTRIBUTE) === null) out.push(field);
    }
    return out;
  }
  function isLocked(form) {
    return form.querySelectorAll(`[${LOCKED_ATTRIBUTE}]`).length > 0;
  }
  function lockForm(form, doc) {
    let changed = 0;
    for (const field of controls(form)) {
      if (field.getAttribute(LOCKED_ATTRIBUTE) !== null) continue;
      const kind = lockKind(field);
      if (kind === "readonly") {
        field.readOnly = true;
        field.setAttribute("readonly", "readonly");
      } else if (kind === "disabled") {
        const isBox = (field.type || "").toLowerCase() === "checkbox";
        if (!isBox || field.checked) {
          const mirror = doc.createElement("input");
          mirror.type = "hidden";
          mirror.name = field.name;
          mirror.value = isBox ? field.value || "1" : field.value;
          mirror.setAttribute(MIRROR_ATTRIBUTE, "1");
          form.appendChild(mirror);
        }
        field.disabled = true;
        field.setAttribute("disabled", "disabled");
      } else {
        continue;
      }
      field.setAttribute(LOCKED_ATTRIBUTE, kind);
      changed++;
    }
    return changed;
  }
  function unlockForm(form) {
    var _a;
    const locked = form.querySelectorAll(`[${LOCKED_ATTRIBUTE}]`);
    for (let i = 0; i < locked.length; i++) {
      const field = locked[i];
      if (field.getAttribute(LOCKED_ATTRIBUTE) === "readonly") {
        field.readOnly = false;
        field.removeAttribute("readonly");
      } else {
        field.disabled = false;
        field.removeAttribute("disabled");
      }
      field.removeAttribute(LOCKED_ATTRIBUTE);
    }
    const mirrors = form.querySelectorAll(`[${MIRROR_ATTRIBUTE}]`);
    const list = [];
    for (let i = 0; i < mirrors.length; i++) list.push(mirrors[i]);
    for (const mirror of list) {
      const node = mirror;
      if (typeof node.remove === "function") node.remove();
      else (_a = form.removeChild) == null ? void 0 : _a.call(form, mirror);
    }
  }

  // assets/src/loader/classic.ts
  var CONTAINER = '[data-navexa-agecheck="1"]';
  var MESSAGE = ".navexa-agecheck__message";
  var TURNSTILE = "[data-navexa-agecheck-turnstile]";
  var LOCK_BAR = "[data-navexa-agecheck-lock]";
  var UNLOCK = "[data-navexa-agecheck-unlock]";
  var NOTICE = "data-navexa-agecheck-notice";
  var UPDATE_TIMEOUT_MS = 8e3;
  function boot() {
    const data = window.navexaAgeCheckClassic;
    const api = window.NavexaAgeCheck;
    const $ = window.jQuery;
    const formEl = document.querySelector("form.checkout");
    if (!data || !api || typeof api.verify !== "function" || !$ || !formEl) return;
    const verify = api.verify;
    const $form = $(formEl);
    const doc = document;
    const t = (key) => {
      var _a;
      return (_a = data.i18n[key]) != null ? _a : "";
    };
    const turnstile = data.turnstile && window.NavexaAgeCheckTurnstile ? window.NavexaAgeCheckTurnstile.create(data.turnstile) : null;
    const form = formEl;
    let unlockedByShopper = false;
    const container = () => document.querySelector(CONTAINER);
    const status = () => {
      var _a, _b;
      return (_b = (_a = container()) == null ? void 0 : _a.getAttribute("data-status")) != null ? _b : null;
    };
    const updateCheckout = () => new Promise((resolve) => {
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
    const setMessage = (text) => {
      var _a;
      const el = (_a = container()) == null ? void 0 : _a.querySelector(MESSAGE);
      if (el && text) el.textContent = text;
    };
    const showLockBar = (show) => {
      const bar = document.querySelector(LOCK_BAR);
      if (!bar) return;
      bar.hidden = !show;
      bar.style.display = show ? "" : "none";
    };
    const lock = () => {
      if (unlockedByShopper) return;
      lockForm(form, document);
      showLockBar(isLocked(form));
    };
    const unlock = () => {
      unlockForm(form);
      showLockBar(false);
    };
    const clearNotices = () => {
      document.querySelectorAll(`[${NOTICE}]`).forEach((n) => n.remove());
    };
    const fail = (message, focusChallenge) => {
      clearNotices();
      document.querySelectorAll(".woocommerce-NoticeGroup-checkout").forEach((n) => n.remove());
      const group = document.createElement("div");
      group.className = "woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout";
      group.setAttribute(NOTICE, "1");
      group.tabIndex = -1;
      const list = document.createElement("ul");
      list.className = "woocommerce-error";
      list.setAttribute("role", "alert");
      const item = document.createElement("li");
      item.textContent = message;
      list.appendChild(item);
      group.appendChild(list);
      formEl.insertBefore(group, formEl.firstChild);
      setMessage(message);
      announce(doc, message);
      const target = focusChallenge ? document.querySelector(TURNSTILE) : group;
      focusOnReturn(target, window);
      $(document.body).trigger("checkout_error");
    };
    const flow = createPlaceOrderFlow({
      status,
      updateCheckout,
      transport: storeApiTransport({ base: data.storeApi, namespace: data.namespace, restNonce: data.restNonce, startData }),
      verify: (url) => verify({ url }, { host: originOf(url), labels: data.labels }),
      block: () => {
        var _a, _b;
        (_a = container()) == null ? void 0 : _a.setAttribute("aria-busy", "true");
        (_b = $form.block) == null ? void 0 : _b.call($form, { message: null, overlayCSS: { background: "#fff", opacity: 0.6 } });
      },
      unblock: () => {
        var _a, _b;
        (_a = container()) == null ? void 0 : _a.setAttribute("aria-busy", "false");
        (_b = $form.unblock) == null ? void 0 : _b.call($form);
      },
      submit: () => {
        clearNotices();
        $form.trigger("submit");
      },
      passed: () => {
        var _a;
        unlockedByShopper = false;
        (_a = container()) == null ? void 0 : _a.setAttribute("data-status", "passed");
        setMessage(t("passed"));
        announce(doc, t("passed"));
        lock();
      },
      fail,
      t,
      challenging: () => !!(turnstile == null ? void 0 : turnstile.challenging())
    });
    $form.on("checkout_place_order", (event) => flow.onPlaceOrder(event));
    document.addEventListener("click", (event) => {
      const target = event.target;
      if (!target || typeof target.closest !== "function" || !target.closest(UNLOCK)) return;
      event.preventDefault();
      unlockedByShopper = true;
      unlock();
      announce(doc, t("unlocked"));
      const first = document.querySelector('[name="billing_first_name"]');
      first == null ? void 0 : first.focus();
    });
    const syncWithServer = (data2) => {
      var _a, _b, _c, _d;
      const html = (_a = data2 == null ? void 0 : data2.fragments) == null ? void 0 : _a[".woocommerce-checkout-payment"];
      const live = container();
      if (typeof html !== "string" || !live) return;
      const template = document.createElement("template");
      template.innerHTML = html;
      const fresh = template.content.querySelector(CONTAINER);
      if (!fresh) return;
      live.setAttribute("data-status", (_b = fresh.getAttribute("data-status")) != null ? _b : "none");
      const text = (_d = (_c = fresh.querySelector(MESSAGE)) == null ? void 0 : _c.textContent) != null ? _d : "";
      if (text) setMessage(text);
    };
    $(document.body).on("updated_checkout", (_event, data2) => {
      var _a, _b, _c;
      syncWithServer(data2);
      if (flow.busy()) return;
      const now = status();
      if (now === "passed") lock();
      else if (now !== "pending") {
        unlockedByShopper = false;
        if (isLocked(form)) unlock();
        const text = (_c = (_b = (_a = container()) == null ? void 0 : _a.querySelector(MESSAGE)) == null ? void 0 : _b.textContent) != null ? _c : "";
        if (text && now !== null) announce(doc, text);
      }
    });
    const initial = status();
    if (initial === "passed") lock();
    else if (initial === "pending") {
      void storeApiTransport({ base: data.storeApi, namespace: data.namespace, restNonce: data.restNonce })("refresh").then((answer) => {
        var _a;
        if (answer.status === "passed") {
          (_a = container()) == null ? void 0 : _a.setAttribute("data-status", "passed");
          setMessage(t("passed"));
          announce(doc, t("passed"));
          lock();
        }
      }).catch(() => void 0);
    }
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
  else boot();
})();
