"use strict";

const reportForm = document.getElementById("report-form");
const formMessage = document.getElementById("form-message");
const detailsField = document.getElementById("additional-details");
const characterCount = document.getElementById("character-count");

function updateCharacterCount() {
  characterCount.textContent = detailsField.value.length;
}

detailsField.addEventListener("input", updateCharacterCount);

reportForm.addEventListener("submit", function (event) {
  event.preventDefault();

  if (!reportForm.checkValidity()) {
    formMessage.textContent =
      "Please fill out all required fields before submitting.";
    reportForm.reportValidity();
    return;
  }

  const formData = new FormData(reportForm);
  const reportType = formData.get("report_type");
  const reportNumber = Math.floor(1000 + Math.random() * 9000);
  const reportTypeLabel =
    reportType === "Content Report" ? "content" : "technical issue";

  formMessage.textContent =
    "Your " + reportTypeLabel +
    " report has been submitted. Your report number is " +
    reportNumber +
    ".";

  reportForm.reset();
  updateCharacterCount();
});