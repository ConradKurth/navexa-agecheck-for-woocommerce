/*! Navexa AgeCheck entry gate settings | Navexa AgeCheck for WooCommerce | GPL-2.0-or-later | source: assets/src/admin/ */
"use strict";
(() => {
  // assets/src/admin/settings.ts
  function luminance(color) {
    const m = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(color.trim());
    if (!m) return null;
    const hex = m[1].length === 3 ? m[1].replace(/./g, (c) => c + c) : m[1];
    const channel = (i) => {
      const c = parseInt(hex.slice(i, i + 2), 16) / 255;
      return c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
    };
    return 0.2126 * channel(0) + 0.7152 * channel(2) + 0.0722 * channel(4);
  }
  function contrast(a, b) {
    const la = luminance(a);
    const lb = luminance(b);
    if (la === null || lb === null) return null;
    return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
  }
  function ratioText(ratio) {
    return (Math.floor(ratio * 100) / 100).toFixed(2);
  }
  function bootMedia(data) {
    document.querySelectorAll("[data-navexa-agecheck-media]").forEach((cell) => {
      var _a;
      const input = cell.querySelector("[data-navexa-agecheck-media-id]");
      const preview = cell.querySelector("[data-navexa-agecheck-media-preview]");
      const remove = cell.querySelector("[data-navexa-agecheck-media-remove]");
      (_a = cell.querySelector("[data-navexa-agecheck-media-choose]")) == null ? void 0 : _a.addEventListener("click", (event) => {
        var _a2;
        event.preventDefault();
        if (!((_a2 = window.wp) == null ? void 0 : _a2.media) || !input) return;
        const frame = window.wp.media({ title: data.i18n.choose, button: { text: data.i18n.use }, library: { type: "image" }, multiple: false });
        frame.on("select", () => {
          var _a3, _b, _c, _d;
          const picked = frame.state().get("selection").first().toJSON();
          input.value = String(picked.id);
          if (preview) {
            preview.textContent = "";
            const img = document.createElement("img");
            img.src = (_d = (_c = (_b = (_a3 = picked.sizes) == null ? void 0 : _a3.thumbnail) == null ? void 0 : _b.url) != null ? _c : picked.url) != null ? _d : "";
            img.alt = "";
            img.style.maxWidth = "80px";
            img.style.height = "auto";
            preview.appendChild(img);
          }
          if (remove) remove.hidden = false;
        });
        frame.open();
      });
      remove == null ? void 0 : remove.addEventListener("click", (event) => {
        event.preventDefault();
        if (input) input.value = "";
        if (preview) preview.textContent = "";
        remove.hidden = true;
      });
    });
  }
  function bootContrast(data) {
    const field = (key) => document.getElementById(`${data.theme}[${key}]`);
    for (const [fg, bg] of data.pairs) {
      const fgInput = field(fg);
      const bgInput = field(bg);
      if (!fgInput || !bgInput) continue;
      const out = document.createElement("span");
      out.className = "description";
      out.setAttribute("aria-live", "polite");
      out.style.marginLeft = "8px";
      fgInput.insertAdjacentElement("afterend", out);
      const update = () => {
        const ratio = contrast(fgInput.value, bgInput.value);
        if (ratio === null) {
          out.textContent = "";
          return;
        }
        const low = ratio < data.minContrast;
        out.textContent = (low ? data.i18n.tooLow : data.i18n.contrast).replace("%s", ratioText(ratio));
        out.style.color = low ? "#d63638" : "";
      };
      for (const input of [fgInput, bgInput]) {
        input.addEventListener("input", update);
        input.addEventListener("change", update);
      }
      const jq = window.jQuery;
      if (jq) for (const input of [fgInput, bgInput]) jq(input).on("irischange", () => window.setTimeout(update, 0));
      update();
    }
  }
  function boot() {
    const data = window.navexaAgeCheckSettings;
    if (!data) return;
    bootMedia(data);
    bootContrast(data);
  }
  if (typeof document !== "undefined") {
    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
    else boot();
  }
})();
