/* =========================================================
   LACE - submit_source.html script
   Validates the form, then POSTs the source to participation.php
   (status defaults to 'pending' for moderator review).
   The bill is identified by ?congress=&type=&number= in the URL
   (carried over from the "Submit Source" button on bill_details),
   falling back to the demo bill 118/HR/123.
   ========================================================= */
(function () {
  "use strict";

  var params = new URLSearchParams(location.search);
  var bill = {
    congress: params.get("congress") || "118",
    type: (params.get("type") || "HR").toUpperCase(),
    number: params.get("number") || "123"
  };
  var billQuery = "congress=" + encodeURIComponent(bill.congress) +
                  "&type=" + encodeURIComponent(bill.type) +
                  "&number=" + encodeURIComponent(bill.number);

  document.addEventListener("DOMContentLoaded", setupSourceForm);

  function setupSourceForm() {
    var form = document.getElementById("source-form");
    if (!form) return;

    // Keep the Cancel / Back links pointed at the same bill.
    var cancelLink = document.getElementById("cancel-btn");
    if (cancelLink) cancelLink.href = "bill_details.html?" + billQuery;
    document.querySelectorAll('#success-notice a[href="bill_details.html"]').forEach(function (a) {
      a.href = "bill_details.html?" + billQuery;
    });

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

    Object.keys(rules).forEach(function (id) {
      var input = document.getElementById(id);
      input.addEventListener("input", function () {
        if (input.closest(".form-field").classList.contains("invalid")) check(id);
      });
      input.addEventListener("change", function () {
        if (input.closest(".form-field").classList.contains("invalid")) check(id);
      });
    });

    var submitBtn = form.querySelector('button[type="submit"]');

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

      if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = "Submitting..."; }

      var body = new URLSearchParams({
        action: "source_add",
        congress: bill.congress,
        type: bill.type,
        number: bill.number,
        source_type: document.getElementById("source-type").value,
        cited_claim: document.getElementById("cited-claim").value.trim(),
        url: document.getElementById("source-url").value.trim(),
        notes: document.getElementById("notes").value.trim()
      });

      fetch("participation.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: body
      })
        .then(function (response) {
          return response.json().then(function (result) {
            if (!response.ok) throw new Error(result.error || "Submission failed.");
            return result.data;
          });
        })
        .then(function () {
          document.getElementById("source-form-card").hidden = true;
          document.getElementById("review-notice").hidden = true;
          document.getElementById("success-notice").hidden = false;
          window.scrollTo({ top: 0, behavior: "smooth" });
        })
        .catch(function (err) {
          alert("Could not submit the source: " + err.message);
          if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = "Submit Source"; }
        });
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
})();