/* =========================================================
   LACE - submit_source.html script
   Source submission form validation.
   Front-end behavior only. Nothing is saved to a server yet;
   the TODO comments mark where backend/API calls will go.
   ========================================================= */

document.addEventListener("DOMContentLoaded", setupSourceForm);

/* ---------------------------------------------------------
   submit_source.html - form validation
   --------------------------------------------------------- */
function setupSourceForm() {
  var form = document.getElementById("source-form");
  if (!form) return;

  var rules = {
    "linked-item": function (v) { return v.trim() !== ""; },
    "source-type": function (v) { return v !== ""; },
    "cited-claim": function (v) { return v.trim() !== ""; },
    "source-url": function (v) { return /^https?:\/\/\S+\.\S+/.test(v.trim()); }
  };

  function check(id) {
    var input = document.getElementById(id);
    var valid = rules[id](input.value);
    var field = input.closest(".form-field");
    field.classList.toggle("invalid", !valid);
    field.querySelector(".error-msg").hidden = valid;
    return valid;
  }

  // Clear an error as soon as the user fixes the field
  Object.keys(rules).forEach(function (id) {
    var input = document.getElementById(id);
    input.addEventListener("input", function () {
      if (input.closest(".form-field").classList.contains("invalid")) check(id);
    });
    input.addEventListener("change", function () {
      if (input.closest(".form-field").classList.contains("invalid")) check(id);
    });
  });

  form.addEventListener("submit", function (event) {
    event.preventDefault();

    var firstBad = null;
    Object.keys(rules).forEach(function (id) {
      if (!check(id) && !firstBad) firstBad = id;
    });

    if (firstBad) {
      document.getElementById(firstBad).focus();
      return;
    }

    // TODO: send the form data to the backend, e.g. POST /api/sources
    document.getElementById("source-form-card").hidden = true;
    document.getElementById("review-notice").hidden = true;
    document.getElementById("success-notice").hidden = false;
    window.scrollTo({ top: 0, behavior: "smooth" });
  });

  var cancel = document.getElementById("cancel-btn");
  cancel.addEventListener("click", function (event) {
    var hasText = Array.prototype.some.call(
      form.querySelectorAll("input, textarea, select"),
      function (el) { return el.value.trim() !== ""; }
    );
    if (hasText && !confirm("Discard this submission?")) {
      event.preventDefault();
    }
  });
}
