/*! Navexa AgeCheck loader 1.0.0 | Navexa AgeCheck for WooCommerce | GPL-2.0-or-later | source: assets/src/loader/ */
"use strict";
(() => {
  // assets/src/loader/agecheck.ts
  var LOADER_VERSION = "1.0.0";
  var CHANNEL = "navexa-agecheck";
  var POPUP_NAME = "navexa-agecheck";
  var RETURN_STORAGE_KEY = "navexa-agecheck-return";
  var REDIRECT_STORAGE_KEY = "navexa-agecheck-redirect";
  var POPUP_FEATURES = "popup,width=480,height=760";
  var TERMINAL = /* @__PURE__ */ new Set(["passed", "failed"]);
  var STATUSES = /* @__PURE__ */ new Set(["none", "pending", "passed", "failed", "refused", "unavailable"]);
  function parseHostedUrl(hostedUrl) {
    let url;
    try {
      url = new URL(hostedUrl);
    } catch {
      return null;
    }
    const local = url.hostname === "localhost" || url.hostname === "127.0.0.1" || url.hostname === "host.docker.internal";
    if (url.protocol !== "https:" && !(url.protocol === "http:" && local)) return null;
    const match = /^\/v\/([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)$/.exec(url.pathname);
    if (!match) return null;
    return { origin: url.origin, token: match[1], statusUrl: `${url.origin}/hosted/v1/status` };
  }
  function browserEnv() {
    const w = window;
    return {
      open: (url, name, features) => w.open(url, name, features),
      assign: (url) => w.location.assign(url),
      fetch: (url, init) => w.fetch(url, init),
      setTimeout: (fn, ms) => w.setTimeout(fn, ms),
      clearTimeout: (handle) => w.clearTimeout(handle),
      now: () => Date.now(),
      addEventListener: (type, listener) => (type === "visibilitychange" ? document : w).addEventListener(type, listener),
      removeEventListener: (type, listener) => (type === "visibilitychange" ? document : w).removeEventListener(type, listener),
      visible: () => document.visibilityState !== "hidden",
      broadcastChannel: (name) => typeof BroadcastChannel === "function" ? new BroadcastChannel(name) : null,
      sessionStorage: () => {
        try {
          return w.sessionStorage;
        } catch {
          return null;
        }
      }
    };
  }
  var AgeCheckLoader = class {
    constructor(options) {
      this.watch = null;
      /** Origin of the last hosted page opened; postMessage is accepted from it only. */
      this.hostedOrigin = "";
      this.refreshing = null;
      this.lastFocusRefresh = 0;
      this.channel = null;
      this.listeners = [];
      this.destroyed = false;
      var _a, _b, _c, _d, _e, _f;
      this.transport = options.transport;
      this.onChange = (_a = options.onChange) != null ? _a : (() => {
      });
      this.env = { ...typeof window === "undefined" ? {} : browserEnv(), ...options.env };
      this.opts = {
        pollIntervalMs: (_b = options.pollIntervalMs) != null ? _b : 3e3,
        maxPollIntervalMs: (_c = options.maxPollIntervalMs) != null ? _c : 15e3,
        maxPollMs: (_d = options.maxPollMs) != null ? _d : 30 * 60 * 1e3,
        focusRefreshMs: (_e = options.focusRefreshMs) != null ? _e : 5e3,
        openingText: (_f = options.openingText) != null ? _f : "Opening the secure age check\u2026"
      };
      this.state = { status: options.status && STATUSES.has(options.status) ? options.status : "none", busy: false };
      this.listen();
    }
    /** The current state. */
    getState() {
      return { ...this.state };
    }
    /**
     * Starts (or resumes) verification. Call it synchronously from a click
     * handler, so the popup is not blocked.
     */
    verify() {
      if (this.destroyed || this.state.busy) return;
      let popup = null;
      try {
        popup = this.env.open("", POPUP_NAME, POPUP_FEATURES);
      } catch {
        popup = null;
      }
      if (popup) {
        try {
          if (popup.document) {
            popup.document.title = this.opts.openingText;
            if (popup.document.body) popup.document.body.textContent = this.opts.openingText;
          }
        } catch {
        }
      }
      this.set({ status: this.state.status, busy: true, message: void 0 });
      this.transport("start").then(
        (answer) => this.afterStart(answer, popup),
        () => {
          closeQuietly(popup);
          this.set({ status: "unavailable", busy: false });
        }
      );
    }
    /**
     * Asks the store to re-read the verification; resolves to its answer, or
     * null when the call failed. Concurrent calls share one request.
     */
    refresh() {
      if (this.destroyed) return Promise.resolve(null);
      if (this.refreshing) return this.refreshing;
      this.set({ ...this.state, busy: true });
      this.refreshing = this.transport("refresh").then(
        (answer) => {
          this.apply(answer);
          return answer;
        },
        () => {
          this.set({ status: "unavailable", busy: false });
          return null;
        }
      ).finally(() => {
        this.refreshing = null;
      });
      return this.refreshing;
    }
    /** Stops polling and listening. */
    destroy() {
      var _a;
      this.destroyed = true;
      this.stopWatch();
      for (const [type, listener] of this.listeners) this.env.removeEventListener(type, listener);
      this.listeners.length = 0;
      (_a = this.channel) == null ? void 0 : _a.close();
      this.channel = null;
    }
    afterStart(answer, popup) {
      var _a, _b;
      const status = normalizeStatus(answer);
      if (status !== "pending" || !answer.hosted_url) {
        closeQuietly(popup);
        this.apply(answer);
        return;
      }
      const hosted = parseHostedUrl(answer.hosted_url);
      if (!hosted) {
        closeQuietly(popup);
        this.set({ status: "unavailable", busy: false });
        return;
      }
      if (popup && popup.closed) {
        this.set({ status: "none", busy: false, reason: "closed" });
        return;
      }
      if (popup) {
        try {
          popup.location.replace(answer.hosted_url);
          (_a = popup.focus) == null ? void 0 : _a.call(popup);
          this.set({ status: "pending", busy: false, message: answer.message });
          this.startWatch(hosted);
          return;
        } catch {
          closeQuietly(popup);
        }
      }
      try {
        (_b = this.env.sessionStorage()) == null ? void 0 : _b.setItem(REDIRECT_STORAGE_KEY, "1");
      } catch {
      }
      this.set({ status: "pending", busy: true });
      this.env.assign(answer.hosted_url);
    }
    apply(answer) {
      const status = normalizeStatus(answer);
      if (status !== "pending") this.stopWatch();
      this.set({ status, busy: false, message: answer.message });
    }
    listen() {
      try {
        this.channel = this.env.broadcastChannel ? this.env.broadcastChannel(CHANNEL) : null;
        if (this.channel) this.channel.onmessage = (event) => this.onSignal(event.data);
      } catch {
        this.channel = null;
      }
      this.on("storage", (event) => {
        if (event && event.key === RETURN_STORAGE_KEY) this.onSignal({ type: CHANNEL, v: 1, event: "return" });
      });
      this.on("message", (event) => {
        if (this.hostedOrigin !== "" && event && event.origin === this.hostedOrigin) this.onSignal(event.data);
      });
      const onVisible = () => {
        if (!this.env.visible() || this.state.status !== "pending") return;
        if (this.watch) {
          this.pollNow();
          return;
        }
        const now = this.env.now();
        if (now - this.lastFocusRefresh < this.opts.focusRefreshMs) return;
        this.lastFocusRefresh = now;
        void this.refresh();
      };
      this.on("focus", onVisible);
      this.on("visibilitychange", onVisible);
    }
    on(type, listener) {
      if (!this.env.addEventListener) return;
      this.env.addEventListener(type, listener);
      this.listeners.push([type, listener]);
    }
    onSignal(data) {
      if (!data || typeof data !== "object") return;
      const message = data;
      if (message.type !== CHANNEL || message.v !== 1) return;
      if (message.event === "return" || typeof message.status === "string" && TERMINAL.has(message.status)) {
        if (this.state.status === "pending") void this.refresh();
      }
    }
    startWatch(hosted) {
      this.stopWatch();
      this.hostedOrigin = hosted.origin;
      this.watch = { ...hosted, startedAt: this.env.now(), interval: this.opts.pollIntervalMs, timer: null, inFlight: false };
      this.schedule(this.opts.pollIntervalMs);
    }
    stopWatch() {
      if (this.watch && this.watch.timer !== null) this.env.clearTimeout(this.watch.timer);
      this.watch = null;
    }
    schedule(ms) {
      const watch = this.watch;
      if (!watch) return;
      if (watch.timer !== null) this.env.clearTimeout(watch.timer);
      watch.timer = this.env.setTimeout(() => {
        watch.timer = null;
        void this.poll();
      }, ms);
    }
    pollNow() {
      if (this.watch && !this.watch.inFlight) this.schedule(0);
    }
    async poll() {
      const watch = this.watch;
      if (!watch || watch.inFlight) return;
      if (this.env.now() - watch.startedAt > this.opts.maxPollMs) {
        this.stopWatch();
        return;
      }
      watch.inFlight = true;
      let next = Math.min(Math.round(watch.interval * 1.5), this.opts.maxPollIntervalMs);
      try {
        const res = await this.env.fetch(watch.statusUrl, {
          method: "GET",
          headers: { Authorization: `Bearer ${watch.token}` },
          credentials: "omit",
          cache: "no-store",
          mode: "cors"
        });
        if (res.status === 200) {
          const body = await res.json();
          if (typeof (body == null ? void 0 : body.status) === "string" && TERMINAL.has(body.status)) {
            watch.inFlight = false;
            this.stopWatch();
            void this.refresh();
            return;
          }
          next = watch.interval;
        } else if (res.status === 429) {
          const retry = Number(res.headers.get("Retry-After"));
          if (Number.isFinite(retry) && retry > 0) next = Math.max(next, retry * 1e3);
        } else if (res.status === 401 || res.status === 403 || res.status === 404) {
          watch.inFlight = false;
          this.stopWatch();
          return;
        }
      } catch {
      }
      watch.inFlight = false;
      if (this.watch !== watch) return;
      watch.interval = next;
      this.schedule(next);
    }
    set(state) {
      this.state = state;
      try {
        this.onChange({ ...state });
      } catch {
      }
    }
  };
  function normalizeStatus(answer) {
    const status = answer && typeof answer.status === "string" ? answer.status : "unavailable";
    return STATUSES.has(status) ? status : "unavailable";
  }
  function closeQuietly(popup) {
    try {
      if (popup && !popup.closed) popup.close();
    } catch {
    }
  }
  function create(options) {
    return new AgeCheckLoader(options);
  }

  // assets/src/loader/index.ts
  if (typeof window !== "undefined" && !window.NavexaAgeCheck) {
    window.NavexaAgeCheck = { version: LOADER_VERSION, create, parseHostedUrl };
  }
})();
