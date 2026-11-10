/* =========================================================
   LACE - bill_details.html script
   Support / Oppose feedback buttons.
   Front-end behavior only. Nothing is saved to a server yet;
   the TODO comments mark where backend/API calls will go.
   ========================================================= */

document.addEventListener("DOMContentLoaded", setupBillFeedback);

/* ---------------------------------------------------------
   bill_details.html - Support / Oppose buttons
   --------------------------------------------------------- */
function setupBillFeedback() {
  var supportBtn = document.getElementById("support-btn");
  var opposeBtn = document.getElementById("oppose-btn");
  if (!supportBtn || !opposeBtn) return;

  var supportEl = document.getElementById("support-count");
  var opposeEl = document.getElementById("oppose-count");
  var baseSupport = Number(supportEl.dataset.count);
  var baseOppose = Number(opposeEl.dataset.count);
  var choice = null; // null, "support", or "oppose"

  function render() {
    var support = baseSupport + (choice === "support" ? 1 : 0);
    var oppose = baseOppose + (choice === "oppose" ? 1 : 0);
    var total = support + oppose;
    var supportPct = Math.round((support / total) * 100);
    var opposePct = 100 - supportPct;

    supportEl.textContent = support.toLocaleString();
    opposeEl.textContent = oppose.toLocaleString();
    document.getElementById("support-pct").textContent = supportPct + "%";
    document.getElementById("oppose-pct").textContent = opposePct + "%";
    document.getElementById("support-bar").style.width = supportPct + "%";
    document.getElementById("oppose-bar").style.width = opposePct + "%";

    // Default look (no choice) matches the mockup: Support is the dark button.
    supportBtn.classList.toggle("btn-primary", choice !== "oppose");
    opposeBtn.classList.toggle("btn-primary", choice === "oppose");
    supportBtn.setAttribute("aria-pressed", choice === "support");
    opposeBtn.setAttribute("aria-pressed", choice === "oppose");

    var status = document.getElementById("vote-status");
    if (choice === "support") status.textContent = "You support this bill. Click again to remove your vote.";
    else if (choice === "oppose") status.textContent = "You oppose this bill. Click again to remove your vote.";
    else status.textContent = "";
  }

  function choose(position) {
    choice = (choice === position) ? null : position;
    render();
    // TODO: send choice to the backend, e.g. POST /api/bills/HB123/feedback
  }

  supportBtn.addEventListener("click", function () { choose("support"); });
  opposeBtn.addEventListener("click", function () { choose("oppose"); });

  // Draw the counts, percentages and bar widths from the data on page load
  render();
}