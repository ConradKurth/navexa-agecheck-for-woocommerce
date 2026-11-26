/*! @navexa/agecheck-js v1.0.0 | MIT | Copyright (c) 2026 Navexa | loader, debug build | https://verify.navexa.co/js/manifest.json */
"use strict";
(() => {
  // src/age.ts
  function localDay(ms) {
    const t = new Date(ms);
    return { y: t.getFullYear(), m: t.getMonth() + 1, d: t.getDate() };
  }
  function daysInMonth(y, m) {
    return new Date(Date.UTC(y, m, 0)).getUTCDate();
  }
  function validDate(day) {
    const { y, m, d } = day;
    return Number.isInteger(y) && Number.isInteger(m) && Number.isInteger(d) && y >= 1900 && m >= 1 && m <= 12 && d >= 1 && d <= daysInMonth(y, m);
  }
  function compareDays(a, b) {
    return Math.sign(a.y - b.y || a.m - b.m || a.d - b.d);
  }
  function ageOn(birth, today) {
    let age = today.y - birth.y;
    if (today.m < birth.m || today.m === birth.m && today.d < birth.d) age--;
    return age;
  }
  function judgeBirth(birth, today, minAge) {
    if (!validDate(birth) || compareDays(birth, today) > 0) return "invalid";
    return ageOn(birth, today) >= minAge ? "ok" : "under";
  }

  // src/crawler.ts
  var CRAWLERS = /\b(googlebot|google-inspectiontool|storebot-google|adsbot-google|mediapartners-google|bingbot|adidxbot|bingpreview|duckduckbot|applebot|yandex(?:bot|images)|baiduspider|slurp)\b/i;
  function isCrawler(userAgent) {
    return CRAWLERS.test(userAgent);
  }

  // src/dom.ts
  var ROOT = "nac";
  var GATE_ATTR = "data-navexa-agecheck-gate";
  var STYLE_ID = "navexa-agecheck-style";
  var FONT = 'system-ui,-apple-system,"Segoe UI",Roboto,sans-serif';
  var BASE_CSS = `.${ROOT}{all:initial;position:fixed;inset:0;z-index:2147483646;display:flex;align-items:center;justify-content:center;box-sizing:border-box;padding:16px;overflow:auto;overscroll-behavior:contain;background:var(--nac-backdrop,rgba(0,0,0,.6));color:var(--nac-text,#1e1e1e);font:16px/1.5 var(--nac-font,${FONT});visibility:visible;animation:nac-in .15s ease-out}
.${ROOT} *{box-sizing:border-box;letter-spacing:normal;text-transform:none;text-shadow:none}
.${ROOT} :focus-visible{outline:3px solid var(--nac-accent,#2271b1);outline-offset:2px}
.nac-card,.nac-modal{position:relative;width:100%;max-height:100%;margin:auto;border-radius:8px;background:var(--nac-card,#fff);color:var(--nac-text,#1e1e1e);box-shadow:0 8px 32px rgba(0,0,0,.4)}
.${ROOT} .nac-btn{display:block;min-height:44px;width:100%;margin:0;padding:.625em 1em;border:2px solid var(--nac-accent,#1e1e1e);border-radius:6px;background:var(--nac-accent,#1e1e1e);color:var(--nac-accent-text,#fff);font-family:inherit;font-size:1em;font-weight:600;line-height:1.5;text-decoration:none;cursor:pointer}
.${ROOT} [hidden]{display:none!important}
@keyframes nac-in{from{opacity:0}to{opacity:1}}
@media (prefers-reduced-motion:reduce){.${ROOT},.nac-spin{animation:none!important}}`;
  var GATE_CSS = `html[${GATE_ATTR}]{background:var(--nac-pre)!important}
html[${GATE_ATTR}] body>:not(.${ROOT}){visibility:hidden!important}
.nac-card{max-width:28em;overflow:auto;padding:2em 1.5em;text-align:center}
.nac-logo{display:block;max-width:12em;max-height:5em;width:auto;height:auto;margin:0 auto 1em}
.nac-title{margin:0 0 .5em;padding:0;font-family:inherit;font-size:1.5em;font-weight:700;line-height:1.25;color:inherit}
.nac-title:focus{outline:none}
.nac-msg{margin:0 0 1.5em;padding:0;font:inherit;color:inherit}
.nac-actions{display:flex;flex-direction:column;gap:.75em;margin:0;padding:0}
.${ROOT} .nac-alt{background:transparent;color:inherit}
.nac-dob{margin:0 0 1em;padding:0;border:0;min-width:0}
.nac-dob legend{margin:0 0 .5em;padding:0;font-weight:600}
.nac-fields{display:flex;gap:.5em;justify-content:center}
.nac-fields label{display:flex;flex-direction:column;gap:.25em;flex:1 1 0;max-width:7em;font-size:.875em}
.${ROOT} .nac-fields input{width:100%;min-height:44px;margin:0;padding:.5em;border:2px solid currentColor;border-radius:6px;background:#fff;color:#1e1e1e;font-family:inherit;font-size:1.125em;line-height:1.25;text-align:center}
.nac-err,.nac-denied{margin:1em 0 0;padding:0;font-weight:600}
.nac-err:empty,.nac-denied:empty{display:none}`;
  var MODAL_CSS = `.${ROOT}.nac-v{padding:0}
.nac-modal{display:flex;flex-direction:column;height:100%;border-radius:0;overflow:hidden}
.nac-head{display:flex;align-items:center;justify-content:space-between;gap:.5em;min-height:48px;padding:0 .25em 0 1em;border-bottom:1px solid rgba(0,0,0,.12)}
.nac-head h2{margin:0;font-family:inherit;font-size:1em;font-weight:600;line-height:1.25;color:inherit}
.${ROOT} .nac-x{display:flex;align-items:center;justify-content:center;width:44px;height:44px;margin:0;padding:0;border:0;border-radius:6px;background:transparent;color:inherit;font-family:inherit;font-size:1.75em;font-weight:400;line-height:1;cursor:pointer}
.nac-body{position:relative;flex:1;min-height:0;display:flex;flex-direction:column}
.nac-body iframe{display:block;flex:1;width:100%;min-height:0;border:0}
.nac-wait{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:1em;padding:1.5em;background:var(--nac-card,#fff);text-align:center}
.nac-wait p{margin:0}
.nac-wait .nac-btn{width:auto}
.${ROOT} .nac-link{color:inherit;text-decoration:underline;font-weight:600}
.nac-spin{width:2em;height:2em;border:3px solid rgba(0,0,0,.15);border-top-color:currentColor;border-radius:50%;animation:nac-spin 1s linear infinite}
@keyframes nac-spin{to{transform:rotate(1turn)}}
@media (min-width:541px){.${ROOT}.nac-v{padding:16px}.nac-modal{width:500px;max-width:100%;height:min(var(--nac-h,640px),100%);border-radius:8px}}
@media (min-width:1024px){.nac-modal{width:min(960px,90vw);height:min(var(--nac-h,660px),660px,90vh)}}`;
  function addStyle(doc, name, css) {
    for (const [key, text] of [
      ["base", BASE_CSS],
      [name, css]
    ]) {
      const id = `${STYLE_ID}-${key}`;
      const marked = doc;
      const seen = marked.__nac = marked.__nac || {};
      if (seen[key] || doc.getElementById(id)) continue;
      seen[key] = true;
      try {
        if ("adoptedStyleSheets" in doc && typeof CSSStyleSheet === "function") {
          const sheet = new CSSStyleSheet();
          sheet.replaceSync(text);
          doc.adoptedStyleSheets = [...doc.adoptedStyleSheets, sheet];
          continue;
        }
      } catch {
      }
      const style = doc.createElement("style");
      style.id = id;
      style.textContent = text;
      (doc.head || doc.documentElement).appendChild(style);
    }
  }
  function el(doc, tag, className, text) {
    const node = doc.createElement(tag);
    if (className) node.className = className;
    if (text !== void 0) node.textContent = text;
    return node;
  }
  var FOCUSABLE = "button,input,select,textarea,iframe,a[href],[tabindex]";
  function focusables(root) {
    return Array.from(root.querySelectorAll(FOCUSABLE)).filter(
      (node) => !node.hasAttribute("data-nac-edge") && node.tabIndex >= 0 && !node.disabled && !node.closest("[hidden]")
    );
  }
  var layers = /* @__PURE__ */ new Set();
  function openLayer(doc, root, opts) {
    const body = doc.body;
    const before = doc.activeElement;
    const hidden = [];
    const hide = (node) => {
      if (node === root || node.hasAttribute("inert") || node.tagName === "SCRIPT" || node.tagName === "STYLE") return;
      hidden.push([node, node.getAttribute("aria-hidden")]);
      node.setAttribute("inert", "");
      node.setAttribute("aria-hidden", "true");
    };
    const first = () => focusables(root)[0];
    const last = () => {
      const all = focusables(root);
      return all[all.length - 1];
    };
    const edge = (pick) => {
      const node = el(doc, "span");
      node.tabIndex = 0;
      node.setAttribute("data-nac-edge", "");
      node.addEventListener("focus", () => pick()?.focus());
      return node;
    };
    root.insertBefore(edge(last), root.firstChild);
    root.appendChild(edge(first));
    const onKey = (event) => {
      if (event.key === "Escape" || event.key === "Esc") {
        event.preventDefault();
        event.stopPropagation();
        opts.onEscape?.();
        return;
      }
      if (event.key !== "Tab") return;
      const all = focusables(root);
      if (all.length === 0) return;
      const active2 = doc.activeElement;
      const at = active2 ? all.indexOf(active2) : -1;
      if (at < 0 || (event.shiftKey ? at === 0 : at === all.length - 1)) {
        event.preventDefault();
        all[event.shiftKey ? all.length - 1 : 0].focus();
      }
    };
    const onFocusIn = (event) => {
      const target = event.target;
      if (target && !root.contains(target)) first()?.focus();
    };
    for (const child of Array.from(body.children)) hide(child);
    body.appendChild(root);
    const watcher = typeof MutationObserver === "function" ? new MutationObserver((records) => {
      for (const record of records) record.addedNodes.forEach((node) => node.nodeType === 1 && hide(node));
    }) : null;
    watcher?.observe(body, { childList: true });
    doc.addEventListener("keydown", onKey, true);
    doc.addEventListener("focusin", onFocusIn, true);
    (opts.initialFocus() || first())?.focus();
    let open2 = true;
    const layer = {
      close() {
        if (!open2) return;
        open2 = false;
        layers.delete(layer);
        watcher?.disconnect();
        doc.removeEventListener("keydown", onKey, true);
        doc.removeEventListener("focusin", onFocusIn, true);
        for (const [node, aria] of hidden) {
          node.removeAttribute("inert");
          if (aria === null) node.removeAttribute("aria-hidden");
          else node.setAttribute("aria-hidden", aria);
        }
        root.remove();
        if (before && before !== doc.body && before.isConnected) {
          try {
            before.focus({ preventScroll: true });
          } catch {
          }
        }
      }
    };
    layers.add(layer);
    return layer;
  }

  // src/env.ts
  var storage = (read) => {
    try {
      return read();
    } catch {
      return null;
    }
  };
  var env = {
    open: (url, name, features) => window.open(url, name, features),
    assign: (url) => window.location.assign(url),
    fetch: (url, init) => window.fetch(url, init),
    now: () => Date.now(),
    userAgent: () => typeof navigator === "undefined" ? "" : navigator.userAgent || "",
    href: () => window.location.href,
    local: () => storage(() => window.localStorage),
    session: () => storage(() => window.sessionStorage)
  };

  // src/contrast.ts
  var MIN_CONTRAST = 4.5;
  function luminance(hex) {
    let h = hex.slice(1);
    if (h.length === 3) h = h.replace(/./g, "$&$&");
    const [r, g, b] = [0, 2, 4].map((i) => {
      const c = parseInt(h.slice(i, i + 2), 16) / 255;
      return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    });
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
  }
  function contrastRatio(a, b) {
    const [x, y] = [luminance(a), luminance(b)].sort((p, q) => q - p);
    return (x + 0.05) / (y + 0.05);
  }

  // src/options.ts
  var GATE_LABEL_KEYS = ["yes", "no", "submit", "dob", "month", "day", "year", "invalid", "denied", "leaving"];
  var DEFAULT_MIN_AGE = 21;
  var DEFAULT_REMEMBER_DAYS = 30;
  var DEFAULT_THEME = {
    backdrop: "#1e1e1e",
    card: "#ffffff",
    text: "#1e1e1e",
    accent: "#1e1e1e",
    accentText: "#ffffff",
    font: 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif'
  };
  var DEFAULT_TEXT = {
    title: "Welcome",
    titleSite: "Welcome to {site}",
    messageAttest: "You must be {age} or older to enter this site. Please confirm your age.",
    messageDob: "You must be {age} or older to enter this site. Please enter your date of birth."
  };
  var DEFAULT_LABELS = {
    yes: "I am {age} or older",
    no: "I am under {age}",
    submit: "Enter",
    dob: "Date of birth",
    month: "Month",
    day: "Day",
    year: "Year",
    invalid: "Enter a real date of birth: month, day and year.",
    denied: "Sorry, you must be {age} or older to visit this site.",
    leaving: "Leaving this site…"
  };
  var MAX_LENGTH = { siteName: 60, title: 80, message: 300, url: 2048, label: 40, longLabel: 160 };
  var LONG_LABELS = ["invalid", "denied", "leaving"];
  var GATE_ATTRIBUTES = {
    mode: "data-mode",
    minAge: "data-min-age",
    rememberDays: "data-remember-days",
    exitUrl: "data-exit-url",
    siteName: "data-site-name",
    title: "data-title",
    message: "data-message",
    logoUrl: "data-logo-url"
  };
  var THEME_KEYS = Object.keys(DEFAULT_THEME);
  var COLOUR = /^#(?:[0-9a-f]{3}){1,2}$/i;
  var FONT2 = /^[A-Za-z0-9 ,'"-]{1,100}$/;
  var URL_TEXT = /^https?:\/\/[^\s"<>\\\u0000-\u001f\u007f]+$/i;
  var CONTROL = /[\u0000-\u001f\u007f]+/g;
  var isObject = (value) => !!value && typeof value === "object" && !Array.isArray(value);
  function cleanText(value, max) {
    return typeof value === "string" ? value.replace(CONTROL, " ").trim().slice(0, max) : "";
  }
  function fill(text, age, site) {
    return text.replace(/\{age\}/g, String(age)).replace(/\{site\}/g, site);
  }
  function gateConfig(options, issues = []) {
    const bad = (field, problem) => {
      issues.push({ field, problem });
    };
    const object = (value, field, known) => {
      if (value === void 0) return {};
      if (!isObject(value)) {
        bad(field, "not an object");
        return {};
      }
      for (const key of Object.keys(value)) if (!known.includes(key)) bad(field ? `${field}.${key}` : key, "unknown option");
      return value;
    };
    const oneOf = (value, allowed, fallback, field) => {
      if (value === void 0) return fallback;
      if (allowed.includes(value)) return value;
      bad(field, `not one of ${allowed.join(", ")}`);
      return fallback;
    };
    const text = (value, max, field) => {
      if (value === void 0 || value === "") return "";
      if (typeof value !== "string") bad(field, "not text");
      else if ([...value].length > max) bad(field, `over ${max} characters`);
      else return value.replace(CONTROL, " ").trim();
      return "";
    };
    const url = (value, field, https) => {
      if (value === void 0 || value === "") return "";
      let ok = typeof value === "string" && value.length <= MAX_LENGTH.url && URL_TEXT.test(value);
      if (ok) {
        try {
          const u = new URL(value);
          ok = u.protocol === "https:" || !https && u.protocol === "http:";
        } catch {
          ok = false;
        }
      }
      if (ok) return value;
      bad(field, https ? "not an absolute https: URL" : "not an absolute http(s) URL");
      return "";
    };
    const o = object(options, "", [...Object.keys(GATE_ATTRIBUTES), "theme", "labels"]);
    const mode = oneOf(o.mode, ["attest", "dob"], "attest", "mode");
    const minAge = oneOf(o.minAge, [18, 19, 21], DEFAULT_MIN_AGE, "minAge");
    let rememberDays = DEFAULT_REMEMBER_DAYS;
    if (o.rememberDays !== void 0) {
      const n = o.rememberDays;
      if (typeof n === "number" && Number.isInteger(n) && n >= 0 && n <= 365) rememberDays = n;
      else bad("rememberDays", "not a whole number 0..365");
    }
    const siteName = text(o.siteName, MAX_LENGTH.siteName, "siteName");
    const t = object(o.theme, "theme", THEME_KEYS);
    const theme = { ...DEFAULT_THEME };
    for (const key of THEME_KEYS) {
      const value = t[key];
      if (value === void 0 || value === "") continue;
      const ok = typeof value === "string" && (key === "font" ? FONT2 : COLOUR).test(value);
      if (ok) theme[key] = key === "font" ? value.trim() || DEFAULT_THEME.font : value.toLowerCase();
      else bad(`theme.${key}`, key === "font" ? "not a plain font-family list" : "not #rgb or #rrggbb");
    }
    for (const [fg, bg] of [
      ["text", "card"],
      ["accentText", "accent"]
    ]) {
      const ratio = contrastRatio(theme[fg], theme[bg]);
      if (ratio < MIN_CONTRAST) {
        bad(`contrast.${fg}-${bg}`, `${ratio.toFixed(2)}:1, under ${MIN_CONTRAST}:1; using the default ${fg} and ${bg}`);
        theme[fg] = DEFAULT_THEME[fg];
        theme[bg] = DEFAULT_THEME[bg];
      }
    }
    const given = object(o.labels, "labels", GATE_LABEL_KEYS);
    const labels = { ...DEFAULT_LABELS };
    for (const key of GATE_LABEL_KEYS) {
      const max = LONG_LABELS.includes(key) ? MAX_LENGTH.longLabel : MAX_LENGTH.label;
      labels[key] = fill(text(given[key], max, `labels.${key}`) || labels[key], minAge, siteName);
    }
    const title = text(o.title, MAX_LENGTH.title, "title") || (siteName ? DEFAULT_TEXT.titleSite : DEFAULT_TEXT.title);
    const message = text(o.message, MAX_LENGTH.message, "message") || (mode === "dob" ? DEFAULT_TEXT.messageDob : DEFAULT_TEXT.messageAttest);
    return {
      mode,
      minAge,
      rememberDays,
      exitUrl: url(o.exitUrl, "exitUrl", false),
      siteName,
      title: fill(title, minAge, siteName),
      message: fill(message, minAge, siteName),
      logoUrl: url(o.logoUrl, "logoUrl", true),
      theme,
      labels
    };
  }
  function camel(name) {
    return name.replace(/-([a-z])/g, (_, c) => c.toUpperCase());
  }
  function attributeOf(field) {
    return `data-${field.replace(/^labels\./, "label-").replace(/^theme\./, "theme-")}`.replace(/[A-Z]/g, (c) => `-${c.toLowerCase()}`);
  }
  function gateOptionsFrom(script) {
    const out = {};
    const theme = {};
    const labels = {};
    for (const { name, value } of Array.from(script.attributes)) {
      const v = value.trim();
      if (!v) continue;
      if (name.startsWith("data-theme-")) theme[camel(name.slice(11))] = v;
      else if (name.startsWith("data-label-")) labels[camel(name.slice(11))] = v;
    }
    for (const [key, attr] of Object.entries(GATE_ATTRIBUTES)) {
      const v = (script.getAttribute(attr) || "").trim();
      if (v) out[key] = (key === "minAge" || key === "rememberDays") && /^\d{1,3}$/.test(v) ? Number(v) : v;
    }
    if (Object.keys(theme).length) out.theme = theme;
    if (Object.keys(labels).length) out.labels = labels;
    return out;
  }

  // src/gate.ts
  var STORAGE_KEY = "navexa_agecheck_gate_v1";
  var DAY_MS = 864e5;
  var ID = "navexa-agecheck-gate";
  function remembered(raw, cfg, now) {
    if (!raw) return false;
    let value;
    try {
      value = JSON.parse(raw);
    } catch {
      return false;
    }
    if (!value || typeof value !== "object") return false;
    const { passedAt, minAge } = value;
    if (typeof passedAt !== "number" || typeof minAge !== "number" || minAge < cfg.minAge || passedAt > now) return false;
    return cfg.rememberDays === 0 || now - passedAt < cfg.rememberDays * DAY_MS;
  }
  function stores(cfg) {
    return cfg.rememberDays === 0 ? [env.session()] : [env.local(), env.session()];
  }
  function rememberedIn(cfg, now) {
    return stores(cfg).some((store) => {
      try {
        return !!store && remembered(store.getItem(STORAGE_KEY), cfg, now);
      } catch {
        return false;
      }
    });
  }
  function isRemembered(minAge, rememberDays = DEFAULT_REMEMBER_DAYS) {
    const days = Number.isInteger(rememberDays) && rememberDays >= 0 && rememberDays <= 365 ? rememberDays : DEFAULT_REMEMBER_DAYS;
    return typeof minAge === "number" && rememberedIn({ minAge, rememberDays: days }, env.now());
  }
  function warn(issues) {
    for (const { field, problem } of issues) {
      try {
        console.warn(`NavexaAgeCheck gate: ignored ${field}${field.startsWith("contrast.") ? "" : ` (${attributeOf(field)})`}: ${problem}`);
      } catch {
      }
    }
  }
  function remember(cfg, now) {
    const value = JSON.stringify({ passedAt: now, minAge: cfg.minAge });
    for (const store of stores(cfg)) {
      try {
        if (store) {
          store.setItem(STORAGE_KEY, value);
          return;
        }
      } catch {
      }
    }
  }
  function place(url) {
    try {
      const u = new URL(url);
      return `${u.origin}${u.pathname.replace(/\/+$/, "")}`.toLowerCase();
    } catch {
      return null;
    }
  }
  function gate(options) {
    const doc = document;
    const html = doc.documentElement;
    if (html.hasAttribute(GATE_ATTR)) return null;
    let cfg;
    try {
      const issues = [];
      cfg = gateConfig(options, issues);
      warn(issues);
      const exit = cfg.exitUrl && place(cfg.exitUrl);
      if (isCrawler(env.userAgent()) || exit && exit === place(env.href()) || rememberedIn(cfg, env.now())) return null;
    } catch {
      return null;
    }
    let layer = null;
    let closed = false;
    let waiting = null;
    const unhide = () => {
      html.removeAttribute(GATE_ATTR);
      html.style.removeProperty("--nac-pre");
    };
    const close = () => {
      if (closed) return;
      closed = true;
      waiting?.disconnect();
      doc.removeEventListener("DOMContentLoaded", show);
      layer?.close();
      layer = null;
      unhide();
    };
    function show() {
      if (closed || layer || !doc.body) return;
      waiting?.disconnect();
      doc.removeEventListener("DOMContentLoaded", show);
      try {
        layer = render(doc, cfg, close);
      } catch {
        close();
      }
    }
    try {
      addStyle(doc, "gate", GATE_CSS);
      html.style.setProperty("--nac-pre", cfg.theme.backdrop);
      html.setAttribute(GATE_ATTR, "");
      if (doc.body) show();
      else {
        if (typeof MutationObserver === "function") {
          waiting = new MutationObserver(show);
          waiting.observe(html, { childList: true });
        }
        doc.addEventListener("DOMContentLoaded", show);
      }
    } catch {
      close();
      return null;
    }
    return { isOpen: () => !!layer, close };
  }
  function render(doc, cfg, close) {
    const { labels, theme } = cfg;
    const root = el(doc, "div", `${ROOT} nac-g`);
    root.setAttribute("data-navexa-agecheck", "gate");
    const vars = [
      ["backdrop", theme.backdrop],
      ["card", theme.card],
      ["text", theme.text],
      ["accent", theme.accent],
      ["accent-text", theme.accentText],
      ["font", theme.font]
    ];
    for (const [name, value] of vars) root.style.setProperty(`--nac-${name}`, value);
    const card = el(doc, "div", "nac-card");
    card.setAttribute("role", "dialog");
    card.setAttribute("aria-modal", "true");
    card.setAttribute("aria-labelledby", `${ID}-title`);
    card.setAttribute("aria-describedby", `${ID}-msg`);
    if (cfg.logoUrl) {
      const logo = el(doc, "img", "nac-logo");
      logo.alt = cfg.siteName;
      logo.decoding = "async";
      logo.addEventListener("error", () => logo.style.visibility = "hidden");
      logo.src = cfg.logoUrl;
      card.appendChild(logo);
    }
    const title = el(doc, "h2", "nac-title", cfg.title);
    title.id = `${ID}-title`;
    title.tabIndex = -1;
    const msg = el(doc, "p", "nac-msg", cfg.message);
    msg.id = `${ID}-msg`;
    const denied = el(doc, "p", "nac-denied");
    denied.setAttribute("role", "alert");
    card.append(title, msg);
    const now = () => env.now();
    const pass = () => {
      remember(cfg, now());
      close();
    };
    const under = () => {
      if (cfg.exitUrl) {
        denied.textContent = labels.leaving;
        env.assign(cfg.exitUrl);
        return;
      }
      denied.textContent = labels.denied;
    };
    const button = (text, className, type = "button") => {
      const b = el(doc, "button", className, text);
      b.type = type;
      return b;
    };
    if (cfg.mode === "attest") {
      const actions = el(doc, "div", "nac-actions");
      const yes = button(labels.yes, "nac-btn nac-yes");
      const no = button(labels.no, "nac-btn nac-alt nac-no");
      yes.addEventListener("click", pass);
      no.addEventListener("click", under);
      actions.append(yes, no);
      card.appendChild(actions);
    } else {
      const form = el(doc, "form", "nac-form");
      form.noValidate = true;
      const set = el(doc, "fieldset", "nac-dob");
      set.appendChild(el(doc, "legend", void 0, labels.dob));
      const fields = el(doc, "div", "nac-fields");
      const err = el(doc, "p", "nac-err");
      err.id = `${ID}-err`;
      err.setAttribute("role", "alert");
      const input = (key, size) => {
        const label = el(doc, "label");
        label.appendChild(el(doc, "span", void 0, labels[key]));
        const field = el(doc, "input", `nac-${key}`);
        field.type = "text";
        field.inputMode = "numeric";
        field.maxLength = size;
        field.setAttribute("pattern", "[0-9]*");
        field.setAttribute("autocomplete", `bday-${key}`);
        field.setAttribute("aria-describedby", err.id);
        label.appendChild(field);
        fields.appendChild(label);
        return field;
      };
      const inputs = [input("month", 2), input("day", 2), input("year", 4)];
      set.appendChild(fields);
      form.append(set, err, button(labels.submit, "nac-btn nac-yes", "submit"));
      form.addEventListener("submit", (event) => {
        event.preventDefault();
        denied.textContent = "";
        const [m, d, y] = inputs.map((i) => /^\d{1,4}$/.test(i.value.trim()) ? Number(i.value) : NaN);
        const verdict = judgeBirth({ y, m, d }, localDay(now()), cfg.minAge);
        for (const i of inputs) i.setAttribute("aria-invalid", String(verdict === "invalid"));
        err.textContent = verdict === "invalid" ? labels.invalid : "";
        if (verdict === "invalid") inputs[0].focus();
        else if (verdict === "under") under();
        else pass();
      });
      card.appendChild(form);
    }
    card.appendChild(denied);
    root.appendChild(card);
    return openLayer(doc, root, { initialFocus: () => title });
  }

  // src/autogate.ts
  function autoGate(script) {
    if (script && script.hasAttribute("data-navexa-gate")) gate(gateOptionsFrom(script));
  }

  // src/global.ts
  function publish(members) {
    const g = window.NavexaAgeCheck = window.NavexaAgeCheck || members;
    for (const [key, value] of Object.entries(members)) if (!(key in g)) g[key] = value;
    return g;
  }

  // src/verify.ts
  var DEFAULT_HOST = "https://verify.navexa.co";
  var REDIRECT_MARKER = "navexa-agecheck-redirect";
  var START_STATUSES = ["passed", "refused", "not_required", "unavailable"];
  var DEFAULT_VERIFY_LABELS = {
    title: "Verify your age",
    close: "Close",
    loading: "Loading the secure age check…",
    slow: "The age check is taking longer than usual to load.",
    newWindow: "Continue in a new window",
    popup: "The age check is open in another window. Finish it there; this page updates when you are done.",
    reopen: "Show the age check window",
    blocked: "Your browser blocked the new window.",
    samePage: "Continue the age check on this page",
    frame: "Age check"
  };
  var VERIFY_LABEL_KEYS = Object.keys(DEFAULT_VERIFY_LABELS);
  var TYPE = "navexa-agecheck";
  var POPUP_NAME = "navexa-agecheck";
  var POPUP_FEATURES = "popup,width=480,height=760";
  var FINAL = ["passed", "failed"];
  var STATUS = /^[a-z_]{1,40}$/;
  var MAX_POLL_MS = 30 * 60 * 1e3;
  var MAX_POLL_INTERVAL_MS = 15e3;
  var HEAD_PX = 49;
  function hostOrigin(host = DEFAULT_HOST) {
    try {
      const url = new URL(host);
      const local = ["localhost", "127.0.0.1", "[::1]"].includes(url.hostname);
      return url.protocol === "https:" || url.protocol === "http:" && local ? url.origin : null;
    } catch {
      return null;
    }
  }
  function parseHostedUrl(url, origin, parentOrigin) {
    let u;
    try {
      u = new URL(url);
    } catch {
      return null;
    }
    const match = /^\/v\/([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)$/.exec(u.pathname);
    if (u.origin !== origin || !match) return null;
    u.searchParams.delete("embed");
    u.searchParams.delete("parent_origin");
    const page = u.href;
    u.searchParams.set("embed", "1");
    if (parentOrigin && parentOrigin !== "null") u.searchParams.set("parent_origin", parentOrigin);
    return { page, embed: u.href, token: match[1] };
  }
  var active = null;
  function verify(source, options = {}) {
    if (active) return active;
    const promise = new Promise((resolve, reject) => run(source, options || {}, resolve, reject));
    active = promise;
    const clear = () => {
      if (active === promise) active = null;
    };
    promise.then(clear, clear);
    return promise;
  }
  function positive(value, fallback) {
    return typeof value === "number" && value > 0 && Number.isFinite(value) ? value : fallback;
  }
  var id200 = (value) => typeof value === "string" && value ? value.slice(0, 200) : void 0;
  function run(source, o, resolve, reject) {
    const origin = hostOrigin(o.host);
    const src = source || {};
    if (!origin || typeof src.url !== "string" && typeof src.start !== "function") {
      reject(new TypeError("NavexaAgeCheck.verify: needs {url} or {start} and an https host"));
      return;
    }
    const settle = (result, callback) => {
      try {
        callback?.();
      } catch {
      }
      resolve(result);
    };
    const got = typeof src.url === "string" ? Promise.resolve(src) : Promise.resolve().then(() => src.start());
    got.then((answer) => {
      const a = answer || {};
      if (typeof a.url === "string") {
        const hosted = parseHostedUrl(a.url, origin, window.location.origin);
        if (!hosted) throw new TypeError(`NavexaAgeCheck.verify: not a hosted page URL on ${origin}`);
        open(hosted, id200(a.verificationId), origin, o, settle);
        return;
      }
      const status = a.status;
      if (!START_STATUSES.includes(status)) throw new TypeError("NavexaAgeCheck.verify: start() answered neither a url nor a status");
      const result = { status };
      const id = id200(a.verificationId);
      if (id) result.verificationId = id;
      if (typeof a.message === "string" && a.message) result.message = cleanText(a.message, 500);
      settle(result, () => o.onResult?.(result));
    }).catch((err) => reject(err instanceof Error ? err : new Error("NavexaAgeCheck.verify: start() failed")));
  }
  function open(hosted, givenId, origin, o, settle) {
    const doc = document;
    const win = window;
    const labels = { ...DEFAULT_VERIFY_LABELS };
    const given = o.labels || {};
    for (const key of VERIFY_LABEL_KEYS) labels[key] = cleanText(given[key], 200) || labels[key];
    const readyMs = positive(o.readyTimeoutMs, 1e4);
    const pollMs = positive(o.pollIntervalMs, 3e3);
    const page = hosted.page;
    let token = hosted.token;
    let verificationId = givenId;
    addStyle(doc, "modal", MODAL_CSS);
    const root = el(doc, "div", `${ROOT} nac-v`);
    root.setAttribute("data-navexa-agecheck", "verify");
    const modal = el(doc, "div", "nac-modal");
    modal.setAttribute("role", "dialog");
    modal.setAttribute("aria-modal", "true");
    modal.setAttribute("aria-labelledby", "navexa-agecheck-verify-title");
    const head = el(doc, "div", "nac-head");
    const title = el(doc, "h2", void 0, labels.title);
    title.id = "navexa-agecheck-verify-title";
    const x = el(doc, "button", "nac-x", "×");
    x.type = "button";
    x.setAttribute("aria-label", labels.close);
    const body = el(doc, "div", "nac-body");
    const wait = el(doc, "div", "nac-wait");
    wait.setAttribute("role", "status");
    const spin = el(doc, "div", "nac-spin");
    spin.setAttribute("aria-hidden", "true");
    const waitText = el(doc, "p", void 0, labels.loading);
    const action = el(doc, "button", "nac-btn");
    action.type = "button";
    const link = el(doc, "a", "nac-link", labels.samePage);
    link.href = page;
    wait.append(spin, waitText, action, link);
    body.appendChild(wait);
    head.append(title, x);
    modal.append(head, body);
    root.appendChild(modal);
    let frame = null;
    let popup = null;
    let done = false;
    let readyTimer;
    let pollTimer;
    let pollDelay = pollMs;
    let polling = false;
    const pollStart = env.now();
    let channel = null;
    const show = (text, button, withLink = false) => {
      wait.hidden = false;
      spin.hidden = !!button;
      waitText.textContent = text;
      action.hidden = !button;
      action.textContent = button || "";
      link.hidden = !withLink;
    };
    show(labels.loading);
    const end = (result, callback) => {
      if (done) return;
      done = true;
      clearTimeout(readyTimer);
      clearTimeout(pollTimer);
      win.removeEventListener("message", onMessage);
      win.removeEventListener("focus", pollSoon);
      doc.removeEventListener("visibilitychange", pollSoon);
      try {
        channel?.close();
      } catch {
      }
      try {
        if (popup && !popup.closed) popup.close?.();
      } catch {
      }
      layer.close();
      settle(result, callback);
    };
    const finish = (status, id) => {
      const result = { status };
      const vid = id || verificationId;
      if (vid) result.verificationId = vid;
      end(result, () => o.onResult?.(result));
    };
    const cancel = () => {
      const result = { status: "closed" };
      if (verificationId) result.verificationId = verificationId;
      end(result, () => o.onClose?.());
    };
    const openWindow = () => {
      if (popup && !popup.closed) {
        try {
          popup.focus?.();
        } catch {
        }
        return;
      }
      let w = null;
      try {
        w = env.open(page, POPUP_NAME, POPUP_FEATURES);
      } catch {
        w = null;
      }
      if (!w) {
        show(labels.blocked, labels.newWindow, true);
        return;
      }
      popup = w;
      clearTimeout(readyTimer);
      frame?.remove();
      frame = null;
      show(labels.popup, labels.reopen);
    };
    function onMessage(event) {
      if (done || event.origin !== origin || !event.source) return;
      if (event.source !== frame?.contentWindow && event.source !== popup) return;
      const data = event.data;
      if (!data || typeof data !== "object" || data.type !== TYPE) return;
      const status = typeof data.status === "string" && STATUS.test(data.status) ? data.status : "";
      if (data.v === 2) {
        if (data.event === "ready") {
          if (!frame || event.source !== frame.contentWindow) return;
          verificationId = verificationId || id200(data.verification_id);
          clearTimeout(readyTimer);
          wait.hidden = true;
        } else if (data.event === "resize" && typeof data.height === "number" && Number.isFinite(data.height)) {
          modal.style.setProperty("--nac-h", `${Math.round(Math.min(Math.max(data.height, 120), 4e3)) + HEAD_PX}px`);
        } else if (data.event === "decided" && status) finish(status, id200(data.verification_id));
        else if (data.event === "close_requested") cancel();
      } else if (data.v === 1 && FINAL.includes(status)) {
        finish(status, id200(data.verification_id));
      }
    }
    const schedulePoll = (ms) => {
      clearTimeout(pollTimer);
      pollTimer = setTimeout(poll, ms);
    };
    async function poll() {
      if (done || polling || !token) return;
      if (env.now() - pollStart > MAX_POLL_MS) return;
      polling = true;
      let next = Math.min(Math.round(pollDelay * 1.5), MAX_POLL_INTERVAL_MS);
      try {
        const res = await env.fetch(`${origin}/hosted/v1/status`, {
          method: "GET",
          headers: { Authorization: `Bearer ${token}` },
          credentials: "omit",
          cache: "no-store",
          mode: "cors"
        });
        if (res.status === 200) {
          const answer = await res.json();
          const status = answer && typeof answer.status === "string" ? answer.status : "";
          if (FINAL.includes(status)) {
            polling = false;
            finish(status);
            return;
          }
          next = pollMs;
        } else if (res.status === 429) {
          const retry = Number(res.headers.get("Retry-After"));
          if (retry > 0) next = Math.max(next, retry * 1e3);
        } else if (res.status === 401 || res.status === 403 || res.status === 404) {
          token = "";
        }
      } catch {
      }
      polling = false;
      if (done || !token) return;
      pollDelay = next;
      schedulePoll(next);
    }
    function pollSoon() {
      if (!done && token && !polling && doc.visibilityState !== "hidden") schedulePoll(0);
    }
    const layer = openLayer(doc, root, { onEscape: cancel, initialFocus: () => x });
    x.addEventListener("click", cancel);
    action.addEventListener("click", openWindow);
    link.addEventListener("click", () => {
      try {
        env.session()?.setItem(REDIRECT_MARKER, "1");
      } catch {
      }
    });
    win.addEventListener("message", onMessage);
    win.addEventListener("focus", pollSoon);
    doc.addEventListener("visibilitychange", pollSoon);
    try {
      channel = typeof BroadcastChannel === "function" ? new BroadcastChannel(TYPE) : null;
      if (channel) channel.onmessage = (event) => event.data?.type === TYPE && pollSoon();
    } catch {
      channel = null;
    }
    frame = el(doc, "iframe");
    frame.title = labels.frame;
    frame.setAttribute("allow", "camera; microphone; fullscreen; clipboard-write");
    frame.src = hosted.embed;
    body.insertBefore(frame, wait);
    readyTimer = setTimeout(() => {
      if (!done && frame) show(labels.slow, labels.newWindow);
    }, readyMs);
    schedulePoll(pollMs);
  }

  // src/version.ts
  var VERSION = "1.0.0";

  // src/iife.ts
  publish({ version: VERSION, verify, gate, isRemembered });
  autoGate(document.currentScript);
})();
