/*! Navexa AgeCheck entry age gate (self-attestation) | Navexa AgeCheck for WooCommerce | GPL-2.0-or-later | source: assets/src/loader/ */
"use strict";
(() => {
  // assets/src/loader/gate.ts
  var STORAGE_KEY = "navexa-agecheck-gate";
  var ROOT_ATTRIBUTE = "data-navexa-agecheck-gate";
  var ROOT_CLASS = "navexa-agecheck-gate";
  var DAY_MS = 864e5;
  function readConfig(raw) {
    if (!raw || typeof raw !== "object") return null;
    const c = raw;
    if (c.v !== 1 || typeof c.minimumAge !== "number" || !Number.isInteger(c.minimumAge) || c.minimumAge < 1) return null;
    if (typeof c.days !== "number" || !Number.isInteger(c.days) || c.days < 0) return null;
    if (!c.i18n || typeof c.i18n !== "object") return null;
    const exitUrl = typeof c.exitUrl === "string" && place(c.exitUrl) !== null ? c.exitUrl : "";
    return { v: 1, minimumAge: c.minimumAge, days: c.days, exitUrl, i18n: c.i18n };
  }
  function remembered(raw, minimumAge, days, now) {
    if (!raw) return false;
    let value;
    try {
      value = JSON.parse(raw);
    } catch {
      return false;
    }
    if (!value || typeof value !== "object") return false;
    const { v, age, at } = value;
    if (v !== 1 || typeof age !== "number" || typeof at !== "number" || age < minimumAge || at > now) return false;
    return days === 0 || now - at < days * DAY_MS;
  }
  function answer(minimumAge, now) {
    return JSON.stringify({ v: 1, age: minimumAge, at: now });
  }
  function storageFor(days, local, session) {
    return days === 0 ? session : local;
  }
  function isRemembered(config, local, session, now) {
    for (const store of config.days === 0 ? [session] : [local, session]) {
      if (!store) continue;
      try {
        if (remembered(store.getItem(STORAGE_KEY), config.minimumAge, config.days, now)) return true;
      } catch {
      }
    }
    return false;
  }
  function remember(config, local, session, now) {
    const store = storageFor(config.days, local, session);
    if (!store) return false;
    try {
      store.setItem(STORAGE_KEY, answer(config.minimumAge, now));
      return true;
    } catch {
      return false;
    }
  }
  function place(url) {
    try {
      const u = new URL(url);
      if (u.protocol !== "http:" && u.protocol !== "https:") return null;
      return `${u.origin}${u.pathname.replace(/\/+$/, "")}`.toLowerCase();
    } catch {
      return null;
    }
  }
  function isExitPage(exitUrl, href) {
    if (!exitUrl) return false;
    const exit = place(exitUrl);
    return exit !== null && exit === place(href);
  }
  function nextFocus(focusables, active, backwards) {
    if (focusables.length === 0) return null;
    const index = focusables.indexOf(active);
    if (index < 0) return backwards ? focusables[focusables.length - 1] : focusables[0];
    const step = backwards ? -1 : 1;
    return focusables[(index + step + focusables.length) % focusables.length];
  }
  var SCOPE = `html[${ROOT_ATTRIBUTE}] .${ROOT_CLASS}`;
  var STYLE = `html[${ROOT_ATTRIBUTE}]{overflow:hidden}
html[${ROOT_ATTRIBUTE}] body>:not(.${ROOT_CLASS}){visibility:hidden}
${SCOPE}{position:fixed;inset:0;z-index:2147483646;display:flex;align-items:center;justify-content:center;margin:0;padding:16px;background:#1e1e1e;visibility:visible;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;font-size:16px;line-height:1.5;letter-spacing:normal;text-transform:none}
${SCOPE} .${ROOT_CLASS}__dialog{box-sizing:border-box;width:100%;max-width:28em;max-height:100%;overflow:auto;margin:0;padding:2em 1.5em;border-radius:8px;background:#fff;color:#1e1e1e;font-size:1em;text-align:center;box-shadow:0 8px 32px rgba(0,0,0,.4)}
${SCOPE} .${ROOT_CLASS}__title{margin:0 0 .5em;padding:0;font-family:inherit;font-size:1.5em;line-height:1.25;font-weight:700;letter-spacing:normal;text-transform:none;color:#1e1e1e}
${SCOPE} .${ROOT_CLASS}__text{margin:0 0 1.5em;padding:0;font-family:inherit;font-size:1em;line-height:1.5;color:#1e1e1e}
${SCOPE} .${ROOT_CLASS}__denied{margin:1em 0 0;padding:0;font-family:inherit;font-size:1em;line-height:1.5;font-weight:600;color:#b32d2e}
${SCOPE} .${ROOT_CLASS}__actions{display:flex;flex-direction:column;gap:.75em;margin:0;padding:0}
${SCOPE} .${ROOT_CLASS}__actions .${ROOT_CLASS}__button{box-sizing:border-box;min-height:44px;width:100%;margin:0;padding:.625em 1em;border:2px solid #1e1e1e;border-radius:6px;box-shadow:none;font-family:inherit;font-size:1em;line-height:1.5;font-weight:600;letter-spacing:normal;text-transform:none;text-decoration:none;text-shadow:none;cursor:pointer}
${SCOPE} .${ROOT_CLASS}__actions .${ROOT_CLASS}__yes{background:#1e1e1e;color:#fff}
${SCOPE} .${ROOT_CLASS}__actions .${ROOT_CLASS}__no{background:#fff;color:#1e1e1e}
${SCOPE} :focus-visible{outline:3px solid #2271b1;outline-offset:2px}
${SCOPE} .${ROOT_CLASS}__title:focus{outline:none}`;
  function runGate(env, raw) {
    const doc = env.document;
    const root = doc.documentElement;
    try {
      const config = readConfig(raw);
      if (!config || isExitPage(config.exitUrl, env.href) || isRemembered(config, env.local, env.session, env.now())) return null;
      root.setAttribute(ROOT_ATTRIBUTE, "");
      if (doc.head) {
        const style = doc.createElement("style");
        style.setAttribute("id", `${ROOT_CLASS}-style`);
        style.textContent = STYLE;
        doc.head.appendChild(style);
      }
      const gate = createGate(env, config);
      if (doc.readyState === "loading") {
        const ready = () => {
          doc.removeEventListener("DOMContentLoaded", ready);
          gate.open();
        };
        doc.addEventListener("DOMContentLoaded", ready);
      } else {
        gate.open();
      }
      return gate;
    } catch {
      root.removeAttribute(ROOT_ATTRIBUTE);
      return null;
    }
  }
  function createGate(env, config) {
    const doc = env.document;
    const t = (key) => {
      var _a;
      return (_a = config.i18n[key]) != null ? _a : "";
    };
    let open = false;
    let container = null;
    let focusables = [];
    let title = null;
    let denied = null;
    const inerted = [];
    let watcher = null;
    const hide = (node) => {
      if (node === container || node.hasAttribute("inert")) return;
      inerted.push({ node, ariaHidden: node.getAttribute("aria-hidden") });
      node.setAttribute("inert", "");
      node.setAttribute("aria-hidden", "true");
    };
    const el = (tag, className, text) => {
      const node = doc.createElement(tag);
      node.className = className;
      if (text !== void 0) node.textContent = text;
      return node;
    };
    const onKeydown = (event) => {
      var _a;
      if (!open) return;
      if (event.key === "Escape" || event.key === "Esc") {
        event.preventDefault();
        event.stopPropagation();
        return;
      }
      if (event.key === "Tab") {
        event.preventDefault();
        (_a = nextFocus(focusables, doc.activeElement, !!event.shiftKey)) == null ? void 0 : _a.focus();
      }
    };
    const onFocusin = (event) => {
      var _a;
      if (open && container && !container.contains(event.target)) (_a = focusables[0]) == null ? void 0 : _a.focus();
    };
    const showPage = () => {
      doc.documentElement.removeAttribute(ROOT_ATTRIBUTE);
    };
    const close = () => {
      open = false;
      doc.removeEventListener("keydown", onKeydown, true);
      doc.removeEventListener("focusin", onFocusin, true);
      watcher == null ? void 0 : watcher.disconnect();
      watcher = null;
      for (const { node, ariaHidden } of inerted) {
        node.removeAttribute("inert");
        if (ariaHidden === null) node.removeAttribute("aria-hidden");
        else node.setAttribute("aria-hidden", ariaHidden);
      }
      inerted.length = 0;
      if (container && doc.body) doc.body.removeChild(container);
      container = null;
      showPage();
    };
    const handle = {
      isOpen: () => open,
      open() {
        try {
          const body = doc.body;
          if (!body) {
            showPage();
            return;
          }
          container = el("div", ROOT_CLASS);
          const dialog = el("div", `${ROOT_CLASS}__dialog`);
          dialog.setAttribute("role", "dialog");
          dialog.setAttribute("aria-modal", "true");
          dialog.setAttribute("aria-labelledby", `${ROOT_CLASS}-title`);
          dialog.setAttribute("aria-describedby", `${ROOT_CLASS}-text`);
          title = el("h2", `${ROOT_CLASS}__title`, t("title"));
          title.setAttribute("id", `${ROOT_CLASS}-title`);
          title.setAttribute("tabindex", "-1");
          const text = el("p", `${ROOT_CLASS}__text`, t("body"));
          text.setAttribute("id", `${ROOT_CLASS}-text`);
          const actions = el("div", `${ROOT_CLASS}__actions`);
          const yes = el("button", `${ROOT_CLASS}__button ${ROOT_CLASS}__yes`, t("yes"));
          yes.setAttribute("type", "button");
          const no = el("button", `${ROOT_CLASS}__button ${ROOT_CLASS}__no`, t("no"));
          no.setAttribute("type", "button");
          denied = el("p", `${ROOT_CLASS}__denied`);
          denied.setAttribute("role", "alert");
          denied.hidden = true;
          yes.addEventListener("click", () => handle.accept());
          no.addEventListener("click", () => handle.refuse());
          actions.appendChild(yes);
          actions.appendChild(no);
          dialog.appendChild(title);
          dialog.appendChild(text);
          dialog.appendChild(actions);
          dialog.appendChild(denied);
          container.appendChild(dialog);
          focusables = [yes, no];
          const children = body.children ? Array.from(body.children) : [];
          for (const child of children) hide(child);
          body.appendChild(container);
          watcher = env.watchChildren ? env.watchChildren(body, hide) : null;
          open = true;
          doc.addEventListener("keydown", onKeydown, true);
          doc.addEventListener("focusin", onFocusin, true);
          title.focus();
        } catch {
          close();
        }
      },
      accept() {
        if (!open) return;
        remember(config, env.local, env.session, env.now());
        close();
      },
      refuse() {
        if (!open) return;
        if (config.exitUrl) {
          if (denied) {
            denied.textContent = t("leaving");
            denied.hidden = false;
          }
          env.assign(config.exitUrl);
          return;
        }
        if (denied) {
          denied.textContent = t("denied");
          denied.hidden = false;
        }
      }
    };
    return handle;
  }
  function safeStorage(read) {
    try {
      return read();
    } catch {
      return null;
    }
  }
  if (typeof window !== "undefined" && typeof document !== "undefined") {
    runGate(
      {
        document,
        href: window.location.href,
        local: safeStorage(() => window.localStorage),
        session: safeStorage(() => window.sessionStorage),
        now: () => Date.now(),
        assign: (url) => window.location.assign(url),
        watchChildren: (body, added) => {
          if (typeof MutationObserver !== "function") return null;
          const observer = new MutationObserver((records) => {
            for (const record of records) {
              record.addedNodes.forEach((node) => {
                if (node.nodeType === 1) added(node);
              });
            }
          });
          observer.observe(body, { childList: true });
          return observer;
        }
      },
      window.navexaAgeCheckGate
    );
  }
})();
