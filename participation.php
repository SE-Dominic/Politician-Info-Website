<?php
/**
 * LACE - participation endpoints (comments, likes, bill_feedback, sources).
 *
 * Owned by Nathan: backs bill_details.html, submit_source.html, discussion.html.
 * Reuses the team's connection and prepared-statement helpers in laceDatabase.php.
 *
 * Schema notes (matches the deployed Group2 database):
 *   - bill_feedback is keyed by (congress, billType, billNumber, user_id).
 *   - sources is keyed by source_id and references a bill by (congress, billType, billNumber).
 *   - comments hang off a DISCUSSION (discussions.discussion_id), not a bill directly.
 *     A discussion row carries the bill identity (congress, billType, billNumber), so
 *     these endpoints take a bill key and find-or-create that bill's discussion.
 *   - comments use parent_comment_id (exposed to the browser as parent_id) and edited_at,
 *     and are soft-deleted (status='removed') to match the comments/ discussions design.
 *
 * Reads (GET ?action=):   feedback, comments
 * Writes (POST action=):  feedback_set, source_add, comment_add,
 *                         comment_edit, comment_delete, like_toggle
 *
 * Responses match the team's shape: { "data": ... } on success, { "error": ... }
 * with an HTTP error code on failure (so the existing readLaceJSON() works).
 *
 * @package LACE
 */
declare(strict_types=1);

require_once __DIR__ . "/laceDatabase.php";

session_start();
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

/**
 * Current signed-in user. Until Dominic's login sets $_SESSION['user_id'],
 * this falls back to the demo member (user_id 1) so writes work in testing.
 * TODO: remove the fallback once access.html / login.php set the session.
 */
function participationUserId(): int
{
    return (int) ($_SESSION["user_id"] ?? 1);
}

/** Reads a request value from POST (writes) or GET (reads). */
function reqValue(string $name, ?string $default = null): ?string
{
    $value = $_POST[$name] ?? $_GET[$name] ?? $default;
    return is_string($value) ? $value : $default;
}

/** Reads and validates a required integer request value. */
function reqInt(string $name, int $min, int $max): int
{
    $value = filter_var(reqValue($name), FILTER_VALIDATE_INT, [
        "options" => ["min_range" => $min, "max_range" => $max],
    ]);
    if ($value === false) {
        throw new InvalidArgumentException("Invalid $name.");
    }
    return $value;
}

/**
 * Builds and validates the (congress, type, number) bill identity from the request.
 *
 * @return array{congress:int,type:string,number:int}
 */
function requestBillKey(): array
{
    return [
        "congress" => reqInt("congress", 1, 65535),
        "type" => measureType((string) reqValue("type", "")),
        "number" => reqInt("number", 1, 1000000),
    ];
}

/** Requires that the request used POST. */
function requirePost(): void
{
    if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
        header("Allow: POST");
        throw new RuntimeException("Use POST for this action.");
    }
}

/** Runs an INSERT/UPDATE/DELETE and returns the number of affected rows. */
function participationWrite(mysqli $db, string $sql, array $values): int
{
    $statement = executeLaceStatement($db, $sql, $values);
    $affected = $db->affected_rows;
    $statement->close();
    return $affected;
}

/** Returns support/oppose totals plus the current user's standing vote. */
function feedbackPayload(mysqli $db, array $key, int $uid): array
{
    $rows = laceRows_SELECT(
        $db,
        "SELECT position, COUNT(*) AS total FROM bill_feedback
         WHERE congress=? AND billType=? AND billNumber=? GROUP BY position",
        [$key["congress"], $key["type"], $key["number"]],
    );
    $counts = ["support" => 0, "oppose" => 0];
    foreach ($rows as $row) {
        $counts[$row["position"]] = (int) $row["total"];
    }
    $mine = laceRows_SELECT(
        $db,
        "SELECT position FROM bill_feedback
         WHERE congress=? AND billType=? AND billNumber=? AND user_id=?",
        [$key["congress"], $key["type"], $key["number"], $uid],
    );
    return [
        "support" => $counts["support"],
        "oppose" => $counts["oppose"],
        "total" => $counts["support"] + $counts["oppose"],
        "myPosition" => $mine[0]["position"] ?? null,
    ];
}

/**
 * Finds the discussion row for a bill, optionally creating one.
 *
 * Comments belong to a discussion, and a discussion carries the bill identity.
 * Since no discussions-listing/creation flow exists yet, the first person to
 * comment on a bill starts its discussion here.
 *
 * @return int|null The discussion_id, or null when none exists and create=false.
 */
function billDiscussionId(mysqli $db, array $key, int $uid, bool $create): ?int
{
    $rows = laceRows_SELECT(
        $db,
        "SELECT discussion_id FROM discussions
         WHERE congress=? AND billType=? AND billNumber=?
         ORDER BY (status='open') DESC, discussion_id ASC LIMIT 1",
        [$key["congress"], $key["type"], $key["number"]],
    );
    if ($rows) {
        return (int) $rows[0]["discussion_id"];
    }
    if (!$create) {
        return null;
    }
    $title = "Discussion: " . $key["type"] . " " . $key["number"];
    $statement = executeLaceStatement(
        $db,
        "INSERT INTO discussions (created_by, title, congress, billType, billNumber, status)
         VALUES (?,?,?,?,?, 'open')",
        [$uid, $title, $key["congress"], $key["type"], $key["number"]],
    );
    $id = $db->insert_id;
    $statement->close();
    return $id;
}

/** Reads the visible comment thread for a discussion (parent_comment_id exposed as parent_id). */
function commentsPayload(mysqli $db, ?int $discussionId, int $uid): array
{
    if ($discussionId === null) {
        return ["items" => []]; // no discussion started for this bill yet
    }
    $rows = laceRows_SELECT(
        $db,
        "SELECT c.comment_id, c.parent_comment_id AS parent_id, c.user_id,
                u.username AS author, c.body, c.created_at,
                (SELECT COUNT(*) FROM comment_likes k WHERE k.comment_id = c.comment_id) AS like_count,
                EXISTS(SELECT 1 FROM comment_likes k2 WHERE k2.comment_id = c.comment_id AND k2.user_id = ?) AS liked
         FROM comments c JOIN users u ON u.user_id = c.user_id
         WHERE c.discussion_id = ? AND c.status = 'visible'
         ORDER BY c.created_at ASC, c.comment_id ASC",
        [$uid, $discussionId],
    );
    $items = [];
    foreach ($rows as $row) {
        $items[] = [
            "comment_id" => (int) $row["comment_id"],
            "parent_id" => $row["parent_id"] === null ? null : (int) $row["parent_id"],
            "author" => $row["author"],
            "body" => $row["body"],
            "created_at" => $row["created_at"],
            "like_count" => (int) $row["like_count"],
            "liked" => (bool) $row["liked"],
            "mine" => (int) $row["user_id"] === $uid,
        ];
    }
    return ["items" => $items];
}

/** Confirms the comment belongs to this user; returns its discussion_id. */
function requireCommentOwner(mysqli $db, int $commentId, int $uid): int
{
    $rows = laceRows_SELECT($db, "SELECT user_id, discussion_id FROM comments WHERE comment_id=?", [$commentId]);
    if (!$rows) {
        throw new OutOfBoundsException("Comment not found.");
    }
    if ((int) $rows[0]["user_id"] !== $uid) {
        throw new RuntimeException("You can only change your own comment.");
    }
    return (int) $rows[0]["discussion_id"];
}

/** Soft-deletes a comment and every reply beneath it (status='removed'). */
function softDeleteSubtree(mysqli $db, int $rootId): void
{
    $ids = [$rootId];
    $frontier = [$rootId];
    while ($frontier) {
        $marks = implode(",", array_fill(0, count($frontier), "?"));
        $children = laceRows_SELECT(
            $db,
            "SELECT comment_id FROM comments WHERE status='visible' AND parent_comment_id IN ($marks)",
            $frontier,
        );
        $frontier = array_map(fn($r) => (int) $r["comment_id"], $children);
        foreach ($frontier as $cid) {
            $ids[] = $cid;
        }
    }
    $marks = implode(",", array_fill(0, count($ids), "?"));
    participationWrite($db, "UPDATE comments SET status='removed' WHERE comment_id IN ($marks)", $ids);
}

/**
 * Resolves the requested action, performs it, and returns the JSON payload.
 *
 * @return mixed Data for the { "data": ... } response.
 */
function participationAction(mysqli $db): mixed
{
    $action = (string) reqValue("action", "");
    $uid = participationUserId();

    switch ($action) {

        // ---- bill_details.html -------------------------------------
        case "feedback": // GET
            return feedbackPayload($db, requestBillKey(), $uid);

        case "feedback_set": // POST  position=support|oppose (click again clears)
            requirePost();
            $key = requestBillKey();
            $position = (string) reqValue("position", "");
            if (!in_array($position, ["support", "oppose"], true)) {
                throw new InvalidArgumentException("Invalid position.");
            }
            return inLaceTransaction($db, function () use ($db, $key, $uid, $position) {
                $current = laceRows_SELECT(
                    $db,
                    "SELECT position FROM bill_feedback
                     WHERE congress=? AND billType=? AND billNumber=? AND user_id=?",
                    [$key["congress"], $key["type"], $key["number"], $uid],
                );
                $existing = $current[0]["position"] ?? null;
                if ($existing === $position) {
                    participationWrite(
                        $db,
                        "DELETE FROM bill_feedback
                         WHERE congress=? AND billType=? AND billNumber=? AND user_id=?",
                        [$key["congress"], $key["type"], $key["number"], $uid],
                    );
                } else {
                    participationWrite(
                        $db,
                        "INSERT INTO bill_feedback (congress, billType, billNumber, user_id, position)
                         VALUES (?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE position = VALUES(position)",
                        [$key["congress"], $key["type"], $key["number"], $uid, $position],
                    );
                }
                return feedbackPayload($db, $key, $uid);
            });

        // ---- submit_source.html ------------------------------------
        case "source_add": // POST
            requirePost();
            $key = requestBillKey();
            $type = (string) reqValue("source_type", "");
            if (!in_array($type, ["news", "government", "data", "video"], true)) {
                throw new InvalidArgumentException("Invalid source type.");
            }
            $claim = trim((string) reqValue("cited_claim", ""));
            $url = trim((string) reqValue("url", ""));
            $notes = trim((string) reqValue("notes", ""));
            if ($claim === "") {
                throw new InvalidArgumentException("A cited claim is required.");
            }
            if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
                throw new InvalidArgumentException("A valid http(s) URL is required.");
            }
            $statement = executeLaceStatement(
                $db,
                "INSERT INTO sources (congress, billType, billNumber, submitted_by, source_type, cited_claim, url, notes, status)
                 VALUES (?,?,?,?,?,?,?,?, 'pending')",
                [$key["congress"], $key["type"], $key["number"], $uid, $type, $claim, $url, $notes === "" ? null : $notes],
            );
            $id = $db->insert_id;
            $statement->close();
            return ["source_id" => $id, "status" => "pending"];

        // ---- discussion.html (comments live under a discussion) ----
        case "comments": // GET
            $discussionId = billDiscussionId($db, requestBillKey(), $uid, false);
            return commentsPayload($db, $discussionId, $uid);

        case "comment_add": // POST  body, parent_id?
            requirePost();
            $key = requestBillKey();
            $body = trim((string) reqValue("body", ""));
            if ($body === "") {
                throw new InvalidArgumentException("A comment cannot be empty.");
            }
            if (mb_strlen($body) > 1000) {
                throw new InvalidArgumentException("A comment must be 1000 characters or fewer.");
            }
            return inLaceTransaction($db, function () use ($db, $key, $uid, $body) {
                $discussionId = billDiscussionId($db, $key, $uid, true);
                $parentRaw = reqValue("parent_id");
                $parentId = null;
                if ($parentRaw !== null && $parentRaw !== "") {
                    $parentId = (int) $parentRaw;
                    $parent = laceRows_SELECT(
                        $db,
                        "SELECT discussion_id FROM comments WHERE comment_id=? AND status='visible'",
                        [$parentId],
                    );
                    if (!$parent || (int) $parent[0]["discussion_id"] !== $discussionId) {
                        throw new OutOfBoundsException("Parent comment not found in this discussion.");
                    }
                }
                $statement = executeLaceStatement(
                    $db,
                    "INSERT INTO comments (discussion_id, user_id, parent_comment_id, body)
                     VALUES (?,?,?,?)",
                    [$discussionId, $uid, $parentId, $body],
                );
                $id = $db->insert_id;
                $statement->close();
                $username = laceRows_SELECT($db, "SELECT username FROM users WHERE user_id=?", [$uid]);
                return [
                    "comment_id" => $id,
                    "parent_id" => $parentId,
                    "author" => $username[0]["username"] ?? "You",
                    "body" => $body,
                    "created_at" => date("Y-m-d H:i:s"),
                    "like_count" => 0,
                    "liked" => false,
                    "mine" => true,
                ];
            });

        case "comment_edit": // POST  comment_id, body
            requirePost();
            $commentId = reqInt("comment_id", 1, PHP_INT_MAX);
            $body = trim((string) reqValue("body", ""));
            if ($body === "") {
                throw new InvalidArgumentException("A comment cannot be empty.");
            }
            if (mb_strlen($body) > 1000) {
                throw new InvalidArgumentException("A comment must be 1000 characters or fewer.");
            }
            requireCommentOwner($db, $commentId, $uid);
            participationWrite(
                $db,
                "UPDATE comments SET body=?, edited_at=NOW() WHERE comment_id=? AND user_id=?",
                [$body, $commentId, $uid],
            );
            return ["comment_id" => $commentId, "body" => $body];

        case "comment_delete": // POST  comment_id  (soft delete: status='removed')
            requirePost();
            $commentId = reqInt("comment_id", 1, PHP_INT_MAX);
            requireCommentOwner($db, $commentId, $uid);
            inLaceTransaction($db, function () use ($db, $commentId) {
                softDeleteSubtree($db, $commentId);
            });
            return ["deleted" => $commentId];

        case "like_toggle": // POST  comment_id
            requirePost();
            $commentId = reqInt("comment_id", 1, PHP_INT_MAX);
            return inLaceTransaction($db, function () use ($db, $commentId, $uid) {
                $has = laceRows_SELECT(
                    $db,
                    "SELECT 1 FROM comment_likes WHERE comment_id=? AND user_id=?",
                    [$commentId, $uid],
                );
                if ($has) {
                    participationWrite($db, "DELETE FROM comment_likes WHERE comment_id=? AND user_id=?", [$commentId, $uid]);
                    $liked = false;
                } else {
                    participationWrite($db, "INSERT INTO comment_likes (comment_id, user_id) VALUES (?,?)", [$commentId, $uid]);
                    $liked = true;
                }
                $count = laceRows_SELECT($db, "SELECT COUNT(*) AS n FROM comment_likes WHERE comment_id=?", [$commentId]);
                return ["liked" => $liked, "like_count" => (int) ($count[0]["n"] ?? 0)];
            });

        default:
            throw new InvalidArgumentException("Unknown action.");
    }
}

// --- entry point ------------------------------------------------------
try {
    $db = connectLaceDatabase();
    $data = participationAction($db);
    echo json_encode(["data" => $data], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    // Reuse the team's error-to-HTTP mapping (400/404/503 + JSON message).
    sendReadError($error);
} finally {
    if (isset($db) && $db instanceof mysqli) {
        $db->close();
    }
}