/* =========================================================
   LACE - bill_details.html script
   Support / Oppose feedback, wired to participation.php.
   The bill is identified by ?congress=&type=&number= in the URL;
   if those are absent it falls back to the demo bill 118/HR/123
   so the page still works standalone.
   ========================================================= */
(function () {
  "use strict";

  // ----- bill context from the URL (demo fallback) -----
  var params = new URLSearchParams(location.search);
  var bill = {
    congress: params.get("congress") || "118",
    type: (params.get("type") || "HR").toUpperCase(),
    number: params.get("number") || "123"
  };
  var billQuery = "congress=" + encodeURIComponent(bill.congress) +
                  "&type=" + encodeURIComponent(bill.type) +
                  "&number=" + encodeURIComponent(bill.number);

  // ----- tiny API helpers -----
  function apiGet(action) {
    return fetch("participation.php?action=" + action + "&" + billQuery, { cache: "no-store" })
      .then(readData);
  }
  function apiPost(action, fields) {
    var body = new URLSearchParams(Object.assign({ action: action, congress: bill.congress, type: bill.type, number: bill.number }, fields));
    return fetch("participation.php", { method: "POST", headers: { "Content-Type": "application/x-www-form-urlencoded" }, body: body })
      .then(readData);
  }
  function readData(response) {
    return response.json().then(function (result) {
      if (!response.ok) throw new Error(result.error || "Request failed.");
      return result.data;
    });
  }

  document.addEventListener("DOMContentLoaded", setupBillFeedback);

  function setupBillFeedback() {
    var supportBtn = document.getElementById("support-btn");
    var opposeBtn = document.getElementById("oppose-btn");
    if (!supportBtn || !opposeBtn) return;

    // Carry the bill identity onto the Join Discussion / Submit Source links.
    document.querySelectorAll('a[href="discussion.html"], a[href="submit_source.html"]').forEach(function (a) {
      a.href = a.getAttribute("href") + "?" + billQuery;
    });

    var statusEl = document.getElementById("vote-status");
    var busy = false;

    function render(data) {
      var support = Number(data.support) || 0;
      var oppose = Number(data.oppose) || 0;
      var total = support + oppose;
      var supportPct = total ? Math.round((support / total) * 100) : 0;
      var opposePct = total ? 100 - supportPct : 0;

      document.getElementById("support-count").textContent = support.toLocaleString();
      document.getElementById("oppose-count").textContent = oppose.toLocaleString();
      document.getElementById("support-pct").textContent = supportPct + "%";
      document.getElementById("oppose-pct").textContent = opposePct + "%";
      document.getElementById("support-bar").style.width = supportPct + "%";
      document.getElementById("oppose-bar").style.width = opposePct + "%";

      var choice = data.myPosition; // "support", "oppose", or null
      supportBtn.classList.toggle("btn-primary", choice !== "oppose");
      opposeBtn.classList.toggle("btn-primary", choice === "oppose");
      supportBtn.setAttribute("aria-pressed", choice === "support");
      opposeBtn.setAttribute("aria-pressed", choice === "oppose");

      if (choice === "support") statusEl.textContent = "You support this bill. Click again to remove your vote.";
      else if (choice === "oppose") statusEl.textContent = "You oppose this bill. Click again to remove your vote.";
      else statusEl.textContent = "";
    }

    function vote(position) {
      if (busy) return;
      busy = true;
      apiPost("feedback_set", { position: position })
        .then(render)
        .catch(function (err) { statusEl.textContent = err.message; })
        .finally(function () { busy = false; });
    }

    supportBtn.addEventListener("click", function () { vote("support"); });
    opposeBtn.addEventListener("click", function () { vote("oppose"); });

    // Load the real counts for this bill on page open.
    apiGet("feedback")
      .then(render)
      .catch(function (err) { statusEl.textContent = "Could not load feedback: " + err.message; });
  }
})();