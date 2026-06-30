/* ══════════════════════════════════════════════════════════════════════
   GOBLIN — TOKEN CONFIG
   ────────────────────────────────────────────────────────────────────
   Ganti contract address (CA) cukup DI SINI, sekali, untuk SEMUA halaman
   (index.html · world.html · system.html).

   • SEBELUM launch : biarkan CA = ""   → halaman tampil "COMING SOON",
                      tombol BUY mengarah ke pump.fun (homepage).
   • SETELAH launch : tempel CA di antara tanda kutip → CA tampil & bisa
                      di-klik untuk copy, tombol BUY → pump.fun/coin/<CA>.
   ══════════════════════════════════════════════════════════════════════ */

window.GOBE_TOKEN = {
  CA: ""   // ←←← CONTRACT ADDRESS $GOBE
};

/* ─────────────── jangan ubah apa pun di bawah garis ini ─────────────── */
(function () {
  var cfg  = window.GOBE_TOKEN || (window.GOBE_TOKEN = { CA: "" });
  var ca   = String(cfg.CA || "").trim();
  var live = ca.length > 0;
  var pump = live ? ("https://pump.fun/coin/" + ca) : "https://pump.fun";
  cfg.LIVE = live;
  cfg.PUMP_URL = pump;

  function short(a) { return a.length > 14 ? (a.slice(0, 5) + "…" + a.slice(-5)) : a; }
  function all(sel) { return Array.prototype.slice.call(document.querySelectorAll(sel)); }

  function wire() {
    // BUY buttons → pump.fun
    all("[data-goblin-buy]").forEach(function (el) {
      el.href = pump;
      el.target = "_blank";
      el.rel = "noopener";
      el.classList.toggle("is-pending", !live);
    });

    // full CA text
    all("[data-goblin-ca]").forEach(function (el) {
      el.textContent = live ? ca : "Contract address revealed at launch";
    });

    // short CA chips
    all("[data-goblin-ca-short]").forEach(function (el) {
      el.textContent = live ? short(ca) : "COMING SOON";
    });

    // elements shown only AFTER CA is set
    all("[data-goblin-when-live]").forEach(function (el) { el.hidden = !live; });
    // elements shown only BEFORE launch
    all("[data-goblin-when-pending]").forEach(function (el) { el.hidden = live; });

    // click-to-copy the CA
    all("[data-goblin-copy]").forEach(function (el) {
      el.style.cursor = live ? "pointer" : "default";
      if (live) el.title = "Click to copy contract address";
      el.addEventListener("click", function (e) {
        if (!live) return;
        e.preventDefault();
        var flash = el.querySelector("[data-copy-flash]") || el;
        var prev = flash.textContent;
        function done() { flash.textContent = "✓ COPIED"; setTimeout(function () { flash.textContent = prev; }, 1200); }
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(ca).then(done, done);
        } else {
          try {
            var ta = document.createElement("textarea");
            ta.value = ca; document.body.appendChild(ta); ta.select();
            document.execCommand("copy"); document.body.removeChild(ta); done();
          } catch (err) {}
        }
      });
    });
  }

  if (document.readyState === "loading")
    document.addEventListener("DOMContentLoaded", wire);
  else
    wire();
})();
