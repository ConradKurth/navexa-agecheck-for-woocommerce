/*! Navexa AgeCheck Turnstile glue | Navexa AgeCheck for WooCommerce | GPL-2.0-or-later | source: assets/src/loader/ */
"use strict";
(() => {
  // assets/src/loader/turnstile.ts
  var READY_MAX_AGE_MS = 24e4;
  function browserEnv() {
    return {
      api: () => typeof window !== "undefined" ? window.turnstile : void 0,
      setTimeout: (fn, ms) => setTimeout(fn, ms),
      clearTimeout: (handle) => clearTimeout(handle),
      now: () => Date.now()
    };
  }
  function createTurnstile(options) {
    var _a, _b;
    const env = { ...browserEnv(), ...options.env };
    const loadTimeoutMs = (_a = options.loadTimeoutMs) != null ? _a : 1e4;
    const tokenTimeoutMs = (_b = options.tokenTimeoutMs) != null ? _b : 12e4;
    let widget = null;
    let rendered = null;
    let settle = null;
    let challenge = false;
    let ready = null;
    const finish = (token) => {
      const done = settle;
      settle = null;
      if (done) done(token);
    };
    const solved = (token) => {
      challenge = false;
      const value = typeof token === "string" && token !== "" ? token : null;
      if (settle) {
        finish(value);
        return;
      }
      ready = value ? { token: value, at: env.now() } : null;
    };
    const waitForApi = () => new Promise((resolve) => {
      let waited = 0;
      const check = () => {
        const api = env.api();
        if (api && typeof api.render === "function") {
          resolve(api);
          return;
        }
        if (waited >= loadTimeoutMs) {
          resolve(null);
          return;
        }
        waited += 100;
        env.setTimeout(check, 100);
      };
      check();
    });
    return {
      challenging: () => challenge,
      async token(container) {
        if (!options.siteKey || !container) return null;
        const kept = ready;
        ready = null;
        if (kept && env.now() - kept.at < READY_MAX_AGE_MS) return kept.token;
        const api = await waitForApi();
        if (!api) return null;
        if (challenge && widget !== null && rendered === container) return null;
        finish(null);
        return new Promise((resolve) => {
          const timer = env.setTimeout(() => finish(null), tokenTimeoutMs);
          settle = (token) => {
            env.clearTimeout(timer);
            resolve(token);
          };
          challenge = false;
          try {
            if (widget !== null && rendered !== container) {
              try {
                api.remove(widget);
              } catch {
              }
              widget = null;
            }
            if (widget === null) {
              const id = api.render(container, {
                sitekey: options.siteKey,
                action: options.action,
                execution: "execute",
                appearance: "interaction-only",
                callback: solved,
                "error-callback": () => {
                  challenge = false;
                  finish(null);
                  return true;
                },
                "expired-callback": () => {
                  ready = null;
                  finish(null);
                },
                "timeout-callback": () => {
                  challenge = false;
                  finish(null);
                },
                "before-interactive-callback": () => {
                  challenge = true;
                  finish(null);
                },
                "unsupported-callback": () => finish(null)
              });
              if (typeof id !== "string" || id === "") {
                finish(null);
                return;
              }
              widget = id;
              rendered = container;
            } else {
              api.reset(widget);
            }
            api.execute(widget);
          } catch {
            finish(null);
          }
        });
      }
    };
  }
  if (typeof window !== "undefined" && !window.NavexaAgeCheckTurnstile) {
    window.NavexaAgeCheckTurnstile = { create: createTurnstile };
  }
})();
