/* =========================================================
   LACE - discussion.html script
   Comments, replies, edits, deletes and likes, wired to
   participation.php. The bill is identified by
   ?congress=&type=&number= in the URL (demo fallback 118/HR/123).
   Comments are kept in a local array and the thread is re-rendered
   after each change, so the DOM always matches the database.
   ========================================================= */
(function () {
  "use strict";

  var params = new URLSearchParams(location.search);
  var bill = {
    congress: params.get("congress") || "118",
    type: (params.get("type") || "HR").toUpperCase(),
    number: params.get("number") || "123"
  };

  function apiGet(action) {
    var qs = "congress=" + bill.congress + "&type=" + bill.type + "&number=" + bill.number;
    return fetch("participation.php?action=" + action + "&" + qs, { cache: "no-store" }).then(readData);
  }
  function apiPost(action, fields) {
    var body = new URLSearchParams(Object.assign(
      { action: action, congress: bill.congress, type: bill.type, number: bill.number }, fields || {}));
    return fetch("participation.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: body
    }).then(readData);
  }
  function readData(response) {
    return response.json().then(function (result) {
      if (!response.ok) throw new Error(result.error || "Request failed.");
      return result.data;
    });
  }

  var ICONS = {
    reply: '<svg class="icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 7L4 12l5 5"/><path d="M4 12h10a6 6 0 0 1 6 6v1"/></svg>',
    edit: '<svg class="icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 20l1-5L16 4l4 4L9 19z"/><line x1="14" y1="6" x2="18" y2="10"/></svg>',
    trash: '<svg class="icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 7h16"/><path d="M9 7V4h6v3"/><path d="M6 7l1 13h10l1-13"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>',
    flag: '<svg class="icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 21V4"/><path d="M5 4h12l-2 4 2 4H5"/></svg>'
  };

  function escapeHtml(text) {
    var div = document.createElement("div");
    div.textContent = text;
    return div.innerHTML;
  }
  function parseTime(sqlDateTime) {
    // "YYYY-MM-DD HH:MM:SS" -> Date (treated as local time)
    var d = new Date(String(sqlDateTime).replace(" ", "T"));
    return isNaN(d.getTime()) ? new Date() : d;
  }
  function formatTime(date) {
    var datePart = date.toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" });
    var timePart = date.toLocaleTimeString("en-US", { hour: "numeric", minute: "2-digit" });
    return datePart + " at " + timePart;
  }

  var comments = [];       // flat list from the server
  var listEl, sortSelect, filterSelect;

  function setupDiscussion() {
    listEl = document.getElementById("comment-list");
    if (!listEl) return;

    var composer = document.getElementById("comment-form");
    var textarea = document.getElementById("new-comment");
    var counter = document.getElementById("char-count");
    sortSelect = document.getElementById("sort-comments");
    filterSelect = document.getElementById("filter-comments");

    textarea.addEventListener("input", function () {
      counter.textContent = textarea.value.length + "/1000 characters";
    });

    composer.addEventListener("submit", function (event) {
      event.preventDefault();
      var text = textarea.value.trim();
      if (!text) { textarea.focus(); return; }
      apiPost("comment_add", { body: text })
        .then(function (item) {
          comments.push(item);
          textarea.value = "";
          counter.textContent = "0/1000 characters";
          render();
        })
        .catch(function (err) { alert("Could not post: " + err.message); });
    });

    // One delegated handler for likes and the reply/edit/delete actions.
    listEl.addEventListener("click", function (event) {
      var likeBtn = event.target.closest(".like");
      if (likeBtn) { onLike(likeBtn); return; }
      var actionBtn = event.target.closest("[data-action]");
      if (!actionBtn) return;
      var article = actionBtn.closest("[data-comment-id]");
      var id = Number(article.getAttribute("data-comment-id"));
      var action = actionBtn.getAttribute("data-action");
      if (action === "reply") openReplyForm(article, id);
      else if (action === "edit") openEditForm(article, id);
      else if (action === "delete") onDelete(id);
    });

    sortSelect.addEventListener("change", render);
    filterSelect.addEventListener("change", render);

    load();
  }

  function load() {
    listEl.innerHTML = '<p class="muted" style="padding:14px 4px">Loading comments...</p>';
    apiGet("comments")
      .then(function (data) { comments = data.items || []; render(); })
      .catch(function (err) {
        listEl.innerHTML = '<p class="muted" style="padding:14px 4px">Could not load comments: ' +
          escapeHtml(err.message) + "</p>";
      });
  }

  function byId(id) { return comments.find(function (c) { return c.comment_id === id; }); }
  function childrenOf(id) {
    return comments.filter(function (c) { return c.parent_id === id; })
      .sort(function (a, b) { return parseTime(a.created_at) - parseTime(b.created_at); });
  }
  // A thread "belongs to me" if the top comment or any descendant is mine.
  function threadMine(top) {
    if (top.mine) return true;
    var stack = childrenOf(top.comment_id);
    while (stack.length) {
      var c = stack.pop();
      if (c.mine) return true;
      stack = stack.concat(childrenOf(c.comment_id));
    }
    return false;
  }

  function render() {
    var tops = comments.filter(function (c) { return c.parent_id === null; });
    var mode = sortSelect ? sortSelect.value : "newest";
    tops.sort(function (a, b) {
      if (mode === "liked") return b.like_count - a.like_count;
      var diff = parseTime(a.created_at) - parseTime(b.created_at);
      return mode === "oldest" ? diff : -diff;
    });
    if (filterSelect && filterSelect.value === "mine") {
      tops = tops.filter(threadMine);
    }

    listEl.innerHTML = "";
    if (!tops.length) {
      listEl.innerHTML = '<p class="muted" style="padding:14px 4px">No comments yet. Be the first to post.</p>';
      return;
    }
    tops.forEach(function (top) {
      var thread = document.createElement("article");
      thread.className = "card comment-thread";
      thread.setAttribute("data-comment-id", top.comment_id);
      thread.innerHTML = commentInner(top);
      var kids = childrenOf(top.comment_id);
      if (kids.length) thread.appendChild(buildReplies(kids));
      listEl.appendChild(thread);
    });
  }

  function buildReplies(kids) {
    var wrap = document.createElement("div");
    wrap.className = "replies";
    kids.forEach(function (c) {
      var card = document.createElement("article");
      card.className = "reply-card";
      card.setAttribute("data-comment-id", c.comment_id);
      card.innerHTML = commentInner(c);
      var sub = childrenOf(c.comment_id);
      if (sub.length) card.appendChild(buildReplies(sub));
      wrap.appendChild(card);
    });
    return wrap;
  }

  function commentInner(c) {
    var heart = c.liked ? "&#9829;" : "&#9825;";
    var likedClass = c.liked ? " liked" : "";
    var actions =
      '<button type="button" class="action" data-action="reply">' + ICONS.reply + "Reply</button>";
    if (c.mine) {
      actions +=
        '<button type="button" class="action" data-action="edit">' + ICONS.edit + "Edit</button>" +
        '<button type="button" class="action" data-action="delete">' + ICONS.trash + "Delete</button>";
    }
    actions += '<a class="action" href="report_issue.html?comment_id=' + c.comment_id + '">' + ICONS.flag + "Report</a>";

    return (
      '<div class="comment">' +
        '<div class="avatar"></div>' +
        '<div class="comment-body">' +
          '<div class="comment-head">' +
            '<h3 class="comment-author">' + escapeHtml(c.author) + "</h3> " +
            '<span class="badge">Constituent</span> ' +
            '<button type="button" class="like' + likedClass + '" aria-pressed="' + (c.liked ? "true" : "false") + '">' +
              '<span class="heart">' + heart + '</span><span class="like-count">' + c.like_count + "</span></button>" +
          "</div>" +
          '<div class="comment-time">' + formatTime(parseTime(c.created_at)) + "</div>" +
          '<p class="comment-text">' + escapeHtml(c.body) + "</p>" +
          '<div class="comment-actions">' + actions + "</div>" +
        "</div>" +
      "</div>"
    );
  }

  function onLike(button) {
    var article = button.closest("[data-comment-id]");
    var id = Number(article.getAttribute("data-comment-id"));
    apiPost("like_toggle", { comment_id: id })
      .then(function (data) {
        var c = byId(id);
        if (c) { c.liked = data.liked; c.like_count = data.like_count; render(); }
      })
      .catch(function (err) { alert("Could not update like: " + err.message); });
  }

  function onDelete(id) {
    if (!confirm("Delete this comment and its replies?")) return;
    apiPost("comment_delete", { comment_id: id })
      .then(function () {
        // Drop the comment and every descendant from the local list.
        var remove = {}; remove[id] = true;
        var changed = true;
        while (changed) {
          changed = false;
          comments.forEach(function (c) {
            if (c.parent_id !== null && remove[c.parent_id] && !remove[c.comment_id]) {
              remove[c.comment_id] = true; changed = true;
            }
          });
        }
        comments = comments.filter(function (c) { return !remove[c.comment_id]; });
        render();
      })
      .catch(function (err) { alert("Could not delete: " + err.message); });
  }

  function openReplyForm(article, parentId) {
    var body = article.querySelector(":scope > .comment > .comment-body");
    if (!body || body.querySelector(":scope > .reply-form")) return;
    var form = document.createElement("form");
    form.className = "inline-form reply-form";
    form.innerHTML =
      '<textarea rows="3" maxlength="1000" placeholder="Write a reply..."></textarea>' +
      '<div class="btn-row"><button type="submit" class="btn btn-primary">Post Reply</button>' +
      '<button type="button" class="btn cancel">Cancel</button></div>';
    body.appendChild(form);
    form.querySelector("textarea").focus();
    form.querySelector(".cancel").addEventListener("click", function () { form.remove(); });
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      var text = form.querySelector("textarea").value.trim();
      if (!text) return;
      apiPost("comment_add", { body: text, parent_id: parentId })
        .then(function (item) { comments.push(item); render(); })
        .catch(function (err) { alert("Could not reply: " + err.message); });
    });
  }

  function openEditForm(article, id) {
    var body = article.querySelector(":scope > .comment > .comment-body");
    var textEl = body ? body.querySelector(":scope > .comment-text") : null;
    if (!textEl || body.querySelector(":scope > .edit-form")) return;
    var form = document.createElement("form");
    form.className = "inline-form edit-form";
    form.innerHTML =
      '<textarea rows="3" maxlength="1000"></textarea>' +
      '<div class="btn-row"><button type="submit" class="btn btn-primary">Save</button>' +
      '<button type="button" class="btn cancel">Cancel</button></div>';
    form.querySelector("textarea").value = textEl.textContent;
    textEl.hidden = true;
    textEl.after(form);
    form.querySelector("textarea").focus();
    form.querySelector(".cancel").addEventListener("click", function () { form.remove(); textEl.hidden = false; });
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      var text = form.querySelector("textarea").value.trim();
      if (!text) return;
      apiPost("comment_edit", { comment_id: id, body: text })
        .then(function (data) {
          var c = byId(id);
          if (c) { c.body = data.body; }
          render();
        })
        .catch(function (err) { alert("Could not save: " + err.message); });
    });
  }

  document.addEventListener("DOMContentLoaded", setupDiscussion);
})();