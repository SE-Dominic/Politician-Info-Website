"use strict";

const moderatorMessage = document.getElementById("moderator-message");
const sourceRows = Array.from(document.querySelectorAll(".source-row"));
const sourceFilters = document.getElementById("source-filters");
const statusFilter = document.getElementById("status-filter");
const dateFilter = document.getElementById("date-filter");
const typeFilter = document.getElementById("type-filter");
const queueCount = document.getElementById("queue-count");
const latestSubmissionTime = Math.max(
  ...sourceRows.map(function (row) {
    return Date.parse(row.dataset.date);
  })
);
const filterReferenceTime = Number.isFinite(latestSubmissionTime)
  ? latestSubmissionTime
  : Date.now();
const sourceTabs = document.querySelectorAll(
  '[aria-label="Review queues"] a'
);
const sourceQueue = document.getElementById("pending-sources");
const commentsQueue = document.getElementById("reported-comments");
const activityLog = document.getElementById("activity-log");

function updateSourcePreview(row) {
  document.getElementById("preview-type").textContent = row.dataset.type;
  document.getElementById("preview-title").textContent = row.dataset.title;

  const sourceLink = document.getElementById("preview-source");
  sourceLink.href = row.dataset.source;
  sourceLink.textContent = row.dataset.source;

  document.getElementById("preview-description").textContent =
    row.dataset.description;
  document.getElementById("preview-submitter").textContent =
    row.dataset.submitter;
  document.getElementById("preview-date").textContent = row.dataset.date;
}

function applySourceFilters() {
  const selectedStatus = statusFilter.value;
  const selectedType = typeFilter.value;
  const days = dateFilter.value === "all" ? null : Number(dateFilter.value);
  const referenceDate = new Date(filterReferenceTime);
  let visibleCount = 0;

  sourceRows.forEach(function (row) {
    const matchesStatus =
      selectedStatus === "all" || row.dataset.status === selectedStatus;
    const matchesType =
      selectedType === "all" || row.dataset.type === selectedType;
    const submittedAt = new Date(row.dataset.date);
    const matchesDate =
      days === null ||
      Number.isNaN(submittedAt.getTime()) ||
      referenceDate - submittedAt <= days * 24 * 60 * 60 * 1000;
    const isVisible = matchesStatus && matchesType && matchesDate;

    row.hidden = !isVisible;
    if (isVisible) {
      visibleCount += 1;
    }
  });

  queueCount.textContent = visibleCount;
}

function addActivity(action, item, notes) {
  const row = document.createElement("tr");
  const values = [
    new Intl.DateTimeFormat(undefined, {
      dateStyle: "medium",
      timeStyle: "short"
    }).format(new Date()),
    "You",
    action,
    item,
    notes || "—"
  ];

  values.forEach(function (value) {
    const cell = document.createElement("td");
    cell.textContent = value;
    row.appendChild(cell);
  });

  activityLog.prepend(row);
}

function reviewSource(form, decision) {
  const row = form.closest("tr");
  const submissionId = form.elements.submission_id.value;
  const notes = document.getElementById("moderation-notes").value.trim();
  const statusByDecision = {
    approve: "approved",
    reject: "rejected",
    keep: "kept",
    remove: "removed",
    escalate: "escalated"
  };
  const status = statusByDecision[decision];

  if (!status) {
    return;
  }

  row.dataset.status = status;
  row.querySelector(".source-status").textContent =
    status.charAt(0).toUpperCase() + status.slice(1);
  moderatorMessage.textContent =
    "Submission " + submissionId + " was " + status + ".";
  addActivity(
    status.charAt(0).toUpperCase() + status.slice(1),
    row.dataset.title,
    notes
  );
  applySourceFilters();
}

sourceFilters.addEventListener("change", applySourceFilters);
sourceFilters.addEventListener("reset", function () {
  window.setTimeout(applySourceFilters, 0);
});

document.querySelectorAll(".row-select").forEach(function (radio) {
  radio.addEventListener("change", function () {
    const selectedRow = radio.closest(".source-row");
    if (selectedRow) {
      updateSourcePreview(selectedRow);
    }
  });
});

document.querySelectorAll(".source-review-form").forEach(function (form) {
  form.addEventListener("submit", function (event) {
    event.preventDefault();
    if (event.submitter) {
      reviewSource(form, event.submitter.value);
    }
  });
});

document.querySelectorAll(".comment-review-form").forEach(function (form) {
  form.addEventListener("submit", function (event) {
    event.preventDefault();

    const decision = event.submitter.value;
    const row = form.closest("tr");
    const reportId = form.elements.report_id.value;
    const statusCell = row.querySelector(".comment-status");
    let action;

    if (decision === "dismiss") {
      statusCell.textContent = "Dismissed";
      row.lastElementChild.textContent = "No action required";
      action = "dismissed";
    } else {
      row.cells[1].textContent = "Comment removed";
      statusCell.textContent = "Resolved";
      row.lastElementChild.textContent = "Comment removed";
      action = "removed";
    }

    moderatorMessage.textContent =
      "Report " + reportId + " was " + action + ".";
    addActivity(
      action.charAt(0).toUpperCase() + action.slice(1),
      "Comment report #" + reportId,
      ""
    );
  });
});

document.querySelectorAll(".issue-status-form").forEach(function (form) {
  form.addEventListener("submit", function (event) {
    event.preventDefault();

    const issueId = form.elements.issue_id.value;
    const statusMenu = form.elements.status;
    const selectedOption = statusMenu.options[statusMenu.selectedIndex];
    const row = form.closest("tr");

    row.querySelector(".issue-status").textContent = selectedOption.text;
    moderatorMessage.textContent =
      "Issue " + issueId + " was updated to " + selectedOption.text + ".";
    addActivity(
      "Updated to " + selectedOption.text,
      "Technical issue #" + issueId,
      ""
    );
  });
});

document.querySelectorAll("[data-action]").forEach(function (button) {
  button.addEventListener("click", function () {
    const selectedRow = document.querySelector(
      ".source-row .row-select:checked"
    );
    if (!selectedRow) {
      moderatorMessage.textContent = "Select a source before choosing an action.";
      return;
    }

    const form = selectedRow.closest("tr").querySelector(".source-review-form");
    reviewSource(form, button.dataset.action);
  });
});

document.getElementById("suspend-user").addEventListener("click", function () {
  const selectedRow = document.querySelector(
    ".source-row .row-select:checked"
  );
  if (!selectedRow) {
    moderatorMessage.textContent = "Select a source before recommending suspension.";
    return;
  }

  const row = selectedRow.closest(".source-row");
  moderatorMessage.textContent =
    "Suspension of " + row.dataset.submitter +
    " was recommended for administrator review.";
  addActivity(
    "Suspension recommended",
    "User – " + row.dataset.submitter,
    document.getElementById("moderation-notes").value.trim()
  );
});

sourceTabs.forEach(function (tab) {
  tab.addEventListener("click", function () {
    const showSources = tab.getAttribute("href") === "#pending-sources";
    sourceQueue.hidden = !showSources;
    commentsQueue.hidden = showSources;

    sourceTabs.forEach(function (queueTab) {
      if (queueTab === tab) {
        queueTab.setAttribute("aria-current", "page");
      } else {
        queueTab.removeAttribute("aria-current");
      }
    });
  });
});

const initiallySelectedSource = document.querySelector(
  ".source-row .row-select:checked"
);
if (initiallySelectedSource) {
  updateSourcePreview(initiallySelectedSource.closest(".source-row"));
}
applySourceFilters();
