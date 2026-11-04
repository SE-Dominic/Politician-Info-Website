/* =========================================================
   LACE - discussion.html script
   Posting, replying, editing, deleting, liking, sorting and filtering comments.
   Front-end behavior only. Nothing is saved to a server yet;
   the TODO comments mark where backend/API calls will go.
   ========================================================= */

document.addEventListener("DOMContentLoaded", setupDiscussion);

/* ---------------------------------------------------------
   discussion.html - comments
   --------------------------------------------------------- */

var ICONS = {
  reply: '<svg class="icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 7L4 12l5 5"/><path d="M4 12h10a6 6 0 0 1 6 6v1"/></svg>',
  edit: '<svg class="icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 20l1-5L16 4l4 4L9 19z"/><line x1="14" y1="6" x2="18" y2="10"/></svg>',
  trash: '<svg class="icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 7h16"/><path d="M9 7V4h6v3"/><path d="M6 7l1 13h10l1-13"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>',
  flag: '<svg class="icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 21V4"/><path d="M5 4h12l-2 4 2 4H5"/></svg>'
};

// Turns user-typed text into safe HTML, so text like <b> shows
// as text instead of becoming real markup. Use it any time
// user content is added to a page.
function escapeHtml(text) {
  var div = document.createElement("div");
  div.textContent = text;
  return div.innerHTML;
}

// Formats a date like: Apr 25, 2025 at 2:14 PM
function formatTime(date) {
  var datePart = date.toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" });
  var timePart = date.toLocaleTimeString("en-US", { hour: "numeric", minute: "2-digit" });
  return datePart + " at " + timePart;
}

// Builds the inner markup shared by top-level comments and replies
function commentMarkup(name, text, date) {
  return (
    '<div class="comment">' +
      '<div class="avatar"></div>' +
      '<div class="comment-body">' +
        '<div class="comment-head">' +
          "<strong>" + escapeHtml(name) + "</strong> " +
          '<span class="badge">Constituent</span> ' +
          '<button type="button" class="like" aria-pressed="false"><span class="heart">&#9825;</span><span class="like-count">0</span></button>' +
        "</div>" +
        '<div class="comment-time">' + formatTime(date) + "</div>" +
        '<p class="comment-text">' + escapeHtml(text) + "</p>" +
        '<div class="comment-actions">' +
          '<button type="button" class="action" data-action="reply">' + ICONS.reply + "Reply</button>" +
          '<button type="button" class="action" data-action="edit">' + ICONS.edit + "Edit</button>" +
          '<button type="button" class="action" data-action="delete">' + ICONS.trash + "Delete</button>" +
          '<a class="action" href="report_issue.html">' + ICONS.flag + "Report</a>" +
        "</div>" +
      "</div>" +
    "</div>"
  );
}

function setupDiscussion() {
  var list = document.getElementById("comment-list");
  if (!list) return;

  var composer = document.getElementById("comment-form");
  var textarea = document.getElementById("new-comment");
  var counter = document.getElementById("char-count");
  var sortSelect = document.getElementById("sort-comments");
  var filterSelect = document.getElementById("filter-comments");

  // Character counter
  textarea.addEventListener("input", function () {
    counter.textContent = textarea.value.length + "/1000 characters";
  });

  // Post a new top-level comment
  composer.addEventListener("submit", function (event) {
    event.preventDefault();
    var text = textarea.value.trim();
    if (!text) {
      textarea.focus();
      return;
    }

    var now = new Date();
    var thread = document.createElement("article");
    thread.className = "card comment-thread";
    thread.dataset.time = now.toISOString();
    thread.dataset.likes = "0";
    thread.dataset.mine = "true";
    thread.innerHTML = commentMarkup("You", text, now);

    list.prepend(thread);
    textarea.value = "";
    counter.textContent = "0/1000 characters";
    sortComments();
    applyFilter();
    // TODO: POST /api/comments
  });

  // One click handler for every comment, including ones added later
  list.addEventListener("click", function (event) {
    var likeBtn = event.target.closest(".like");
    if (likeBtn) {
      toggleLike(likeBtn);
      return;
    }

    var actionBtn = event.target.closest("[data-action]");
    if (!actionBtn) return;

    var body = actionBtn.closest(".comment-body");
    var action = actionBtn.dataset.action;

    if (action === "reply") openReplyForm(body);
    else if (action === "edit") openEditForm(body);
    else if (action === "delete") deleteComment(actionBtn);
  });

  function toggleLike(button) {
    var countEl = button.querySelector(".like-count");
    var liked = button.classList.toggle("liked");
    var count = Number(countEl.textContent) + (liked ? 1 : -1);

    countEl.textContent = count;
    button.querySelector(".heart").innerHTML = liked ? "&#9829;" : "&#9825;";
    button.setAttribute("aria-pressed", liked);

    // Keep the thread's like count current for "Most Liked" sorting
    var thread = button.closest(".comment-thread");
    if (thread && button.closest(".comment").parentElement === thread) {
      thread.dataset.likes = count;
    }
  }

  function openReplyForm(body) {
    if (body.querySelector(":scope > .reply-form")) return;

    var form = document.createElement("form");
    form.className = "inline-form reply-form";
    form.innerHTML =
      '<textarea rows="3" maxlength="1000" placeholder="Write a reply..."></textarea>' +
      '<div class="btn-row">' +
        '<button type="submit" class="btn btn-primary">Post Reply</button>' +
        '<button type="button" class="btn cancel">Cancel</button>' +
      "</div>";
    body.appendChild(form);
    form.querySelector("textarea").focus();

    form.querySelector(".cancel").addEventListener("click", function () {
      form.remove();
    });

    form.addEventListener("submit", function (event) {
      event.preventDefault();
      var text = form.querySelector("textarea").value.trim();
      if (!text) return;

      // Find (or create) the replies container right under this comment
      var holder = body.closest(".comment").parentElement;
      var replies = holder.querySelector(":scope > .replies");
      if (!replies) {
        replies = document.createElement("div");
        replies.className = "replies";
        holder.appendChild(replies);
      }

      var reply = document.createElement("article");
      reply.className = "reply-card";
      reply.innerHTML = commentMarkup("You", text, new Date());
      replies.appendChild(reply);

      // A reply counts as "my comment" for the filter
      var thread = body.closest(".comment-thread");
      if (thread) thread.dataset.hasMine = "true";

      form.remove();
      // TODO: POST /api/comments with a parent comment id
    });
  }

  function openEditForm(body) {
    var textEl = body.querySelector(":scope > .comment-text");
    if (!textEl || body.querySelector(":scope > .edit-form")) return;

    var form = document.createElement("form");
    form.className = "inline-form edit-form";
    form.innerHTML =
      '<textarea rows="3" maxlength="1000"></textarea>' +
      '<div class="btn-row">' +
        '<button type="submit" class="btn btn-primary">Save</button>' +
        '<button type="button" class="btn cancel">Cancel</button>' +
      "</div>";
    form.querySelector("textarea").value = textEl.textContent;

    textEl.hidden = true;
    textEl.after(form);
    form.querySelector("textarea").focus();

    form.querySelector(".cancel").addEventListener("click", function () {
      form.remove();
      textEl.hidden = false;
    });

    form.addEventListener("submit", function (event) {
      event.preventDefault();
      var text = form.querySelector("textarea").value.trim();
      if (!text) return;
      textEl.textContent = text;
      textEl.hidden = false;
      form.remove();
      // TODO: PUT /api/comments/{id}
    });
  }

  function deleteComment(button) {
    if (!confirm("Delete this comment and its replies?")) return;
    var item = button.closest(".reply-card") || button.closest(".comment-thread");
    item.remove();
    // TODO: DELETE /api/comments/{id}
  }

  // Sorting top-level threads
  function sortComments() {
    var mode = sortSelect.value;
    var threads = Array.prototype.slice.call(list.querySelectorAll(":scope > .comment-thread"));

    threads.sort(function (a, b) {
      if (mode === "liked") return Number(b.dataset.likes) - Number(a.dataset.likes);
      var diff = new Date(a.dataset.time) - new Date(b.dataset.time);
      return mode === "oldest" ? diff : -diff;
    });

    threads.forEach(function (thread) { list.appendChild(thread); });
  }

  // Filtering: all comments, or only threads you posted/replied in
  function applyFilter() {
    var mineOnly = filterSelect.value === "mine";
    list.querySelectorAll(":scope > .comment-thread").forEach(function (thread) {
      var isMine = thread.dataset.mine === "true" || thread.dataset.hasMine === "true";
      thread.hidden = mineOnly && !isMine;
    });
  }

  sortSelect.addEventListener("change", sortComments);
  filterSelect.addEventListener("change", applyFilter);
  sortComments();
}
