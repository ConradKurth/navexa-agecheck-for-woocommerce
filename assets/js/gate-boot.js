/*! Navexa AgeCheck entry age gate boot (self-attestation) | Navexa AgeCheck for WooCommerce | GPL-2.0-or-later | source: assets/src/gate/ */
"use strict";
(() => {
  // assets/src/gate/boot.ts
  var LEGACY_KEY = "navexa-agecheck-gate";
  var KEY = "navexa_agecheck_gate_v1";
  var DAY_MS = 864e5;
  function readConfig(text) {
    if (!text) return null;
    try {
      const value = JSON.parse(text);
      if (!value || typeof value !== "object" || value.v !== 2 || !value.options || typeof value.options !== "object" || Array.isArray(value.options)) return null;
      return { v: 2, options: value.options };
    } catch {
      return null;
    }
  }
  function migrated(raw, minAge, days, now) {
    if (!raw) return null;
    let value;
    try {
      value = JSON.parse(raw);
    } catch {
      return null;
    }
    if (!value || typeof value !== "object") return null;
    const { v, age, at } = value;
    if (v !== 1 || typeof age !== "number" || typeof at !== "number" || !Number.isFinite(at) || age < minAge || at > now) return null;
    if (days !== 0 && now - at >= days * DAY_MS) return null;
    return JSON.stringify({ passedAt: at, minAge: age });
  }
  function migrateLegacy(options, local, session, now) {
    const minAge = typeof options.minAge === "number" ? options.minAge : 21;
    const days = typeof options.rememberDays === "number" && Number.isInteger(options.rememberDays) && options.rememberDays >= 0 ? options.rememberDays : 30;
    let wrote = false;
    for (const store of days === 0 ? [session] : [local, session]) {
      if (!store) continue;
      try {
        if (store.getItem(KEY) !== null) continue;
        const value = migrated(store.getItem(LEGACY_KEY), minAge, days, now);
        if (value) {
          store.setItem(KEY, value);
          wrote = true;
        }
      } catch {
      }
    }
    return wrote;
  }
  function storages() {
    let local = null;
    let session = null;
    try {
      local = window.localStorage;
    } catch {
      local = null;
    }
    try {
      session = window.sessionStorage;
    } catch {
      session = null;
    }
    return { local, session };
  }
  function boot(script) {
    try {
      const config = readConfig(script == null ? void 0 : script.getAttribute("data-config"));
      const api = typeof window !== "undefined" ? window.NavexaAgeCheck : void 0;
      if (!config || !api || typeof api.gate !== "function") return;
      const { local, session } = storages();
      migrateLegacy(config.options, local, session, Date.now());
      api.gate(config.options);
    } catch {
    }
  }
  if (typeof document !== "undefined" && document.currentScript) boot(document.currentScript);
})();
