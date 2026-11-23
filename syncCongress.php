<?php

/**
 * Imports congressional data on the first visit, on a schedule, or from the CLI.
 * @package LACE
 */

declare(strict_types=1);

require_once __DIR__ . "/laceDatabase.php";
const CONGRESS_API_BASE_URL = "https://api.congress.gov/v3";

// Temporary campus test. Enter your Congress.gov API key here before uploading.
const CONGRESS_API_KEY = "67TrQGCpQJMPLBBtFzpfpj88nRQpRB6fGmPG6drK";

// Website and CLI entry points

/**
 * Serves the fixed refresh POST or process a private CLI command. Return the process exit status.
 *
 * @param array $args Command-line arguments. Empty for website calls.
 * @param bool $web Whether a webpage triggered the check.
 * @return int Zero on success, one on failure.
 * @throws InvalidArgumentException If the command-line arguments are invalid.
 */
function runCongressSync_UPDATE(array $args, bool $web): int
{
    $mode = $web ? "scheduled" : ($args[1] ?? "");
    $step = "";
    if ($web) {
        header("Content-Type: application/json; charset=utf-8");
        header("Cache-Control: no-store");
        if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") {
            header("Allow: POST");
            refreshReply(405, "use_post");
            return 0;
        }
        if (($_SERVER["HTTP_X_LACE_REFRESH"] ?? "") !== "1" || $_GET || $_POST ||
            ($_SERVER["HTTP_SEC_FETCH_SITE"] ?? "") === "cross-site"
        ) {
            refreshReply(400, "invalid_refresh_request");
            return 0;
        }
        ignore_user_abort(true);
    }

    try {
        if (!$web) {
            $arity = [
                "scheduled" => 2,
                "run" => 2,
                "reconcile" => 2,
                "scope" => 2,
                "number" => 2,
                "member" => 3,
                "bill" => 5,
                "house-roll" => 5,
                "bulk" => 3,
                "service-dates" => 3
            ];
            $fileInput = in_array($mode, ["bulk", "service-dates"], true) && count($args) === 4;
            if (!$fileInput && count($args) !== ($arity[$mode] ?? -1)) {
                throw new InvalidArgumentException("Expected a sync command and its arguments. See README.md.");
            }
        }
        $DBConnect = connectLaceDatabase();
        if ($mode === "number") {
            $day = (new DateTimeImmutable("today", new DateTimeZone("America/New_York")))->format("Y-m-d");
            echo cachedCongressNumber_SELECT($DBConnect, $day) . PHP_EOL;
        } else {
            $result = coordinateRefresh_UPDATE($DBConnect, $mode, $args, $web, $step);
            if (!$web) {
                echo $result . PHP_EOL;
            } elseif (!headers_sent()) {
                $accepted = in_array($result, ["queued", "running"], true);
                refreshReply($accepted ? 202 : 200, $result);
            }
        }
        return 0;
    } catch (Throwable $error) {
        if ($web) {
            error_log("LACE refresh: " . $error->getMessage());
            if (!headers_sent()) {
                $details = $step === "" ? [] : ["step" => $step, "code" => refreshErrorCode($error)];
                refreshReply(503, "refresh_unavailable", $details);
            }
        } else {
            fwrite(STDERR, $error->getMessage() . PHP_EOL);
        }
        return 1;
    } finally {
        if (isset($DBConnect)) {
            $DBConnect->close();
        }
    }
}

/** Writes a short JSON status to the refresh request.
 * 
 * @param int $statusCode The HTTP status code to send.
 * @param string $status The status message to include in the JSON response.
 * @param array $details Optional public step and diagnostic code.
 * @return void
 */
function refreshReply(int $statusCode, string $status, array $details = []): void
{
    http_response_code($statusCode);
    echo json_encode(["status" => $status] + $details, JSON_THROW_ON_ERROR);
}

/**
 * Converts an import exception to a public diagnostic without logging credentials or SQL in the response.
 *
 * @param Throwable $error The failed directory or inventory operation.
 * @return string A short diagnostic suitable for the Network panel.
 */
function refreshErrorCode(Throwable $error): string
{
    if ($error instanceof mysqli_sql_exception) {
        return "mysql_" . $error->getCode();
    }
    $message = $error->getMessage();
    if (preg_match('~^Congress\.gov /[a-z0-9/-]+ returned HTTP ([0-9]{3})\.$~i', $message, $matches)) {
        return "congress_http_" . $matches[1];
    }
    if (preg_match('~^Congress\.gov /[a-z0-9/-]+: cURL ([0-9]+) ~i', $message, $matches)) {
        return "curl_" . $matches[1];
    }
    if (preg_match('/^Call to undefined function ([a-zA-Z0-9_]+)\(\)$/', $message, $matches)) {
        return "missing_function_" . $matches[1];
    }
    if (preg_match('/^Call to undefined method (mysqli(?:_stmt|_result)?)::([a-zA-Z0-9_]+)\(\)$/', $message, $matches)) {
        return "missing_method_" . $matches[1] . "_" . $matches[2];
    }
    if (str_starts_with($message, "Incomplete list for ") || str_starts_with($message, "List changed while paging ")) {
        return "incomplete_api_list";
    }
    if (str_starts_with($message, "Invalid next page for ")) {
        return "invalid_api_pagination";
    }
    if (str_ends_with($message, " returned invalid JSON data.")) {
        return "invalid_congress_json";
    }
    $codes = [
        "Enable the PHP cURL extension for the Congress.gov importer." => "php_curl_missing",
        "Invalid current Congress." => "invalid_congress_number",
        "Invalid regular session." => "invalid_session",
        "Invalid session." => "invalid_session_number",
        "No regular sessions returned." => "no_regular_sessions",
        "Unrecognized sessions collection." => "invalid_sessions_list",
        "Unrecognized members collection." => "invalid_members_list",
        "Unrecognized directory terms collection." => "invalid_member_terms",
        "Invalid bioguideId." => "invalid_bioguide_id",
        "Empty current-Congress roster. Prior snapshot retained." => "empty_member_list",
        "Duplicate identity within a roster page set." => "duplicate_member_id"
    ];
    return $codes[$message] ?? "import_error";
}

/**
 * Claims a due run, serializes writers and persists its outcome.
 *
 * @param mysqli $DBConnect The database connection to use.
 * @param string $mode The mode of the import (e.g., "scheduled", "run", "reconcile").
 * @param array $args Additional arguments for the import.
 * @param bool $web Whether the request is coming from the web interface.
 * @param string $step Receives the import step for the public error response.
 * @return string Run status for the calling page or scheduled worker.
 * @throws RuntimeException If an import is already running and cannot be acquired.
 */
function coordinateRefresh_UPDATE(mysqli $DBConnect, string $mode, array $args, bool $web, string &$step = ""): string
{
    $timed = $mode === "scheduled";
    $track = $timed || $mode === "run" || $mode === "reconcile";
    $delayStatus = ["idle" => "waiting", "failed" => "retry_pending", "running" => "running", "queued" => "queued"];
    $schedule = $track ? refreshSchedule_SELECT($DBConnect) : null;
    if ($track && $schedule === null) {
        if ($timed && !$web) {
            return "awaiting_first_visit";
        }
        refreshSchedule_INSERT($DBConnect);
        $schedule = refreshSchedule_SELECT($DBConnect);
    }
    if ($timed && (int) $schedule["is_due"] === 0) {
        return $delayStatus[$schedule["status"]];
    }
    if ($web && (laceSetting("LACE_WEB_SYNC") === "queue" || trim(CONGRESS_API_KEY) === "")) {
        return "queued";
    }

    $lock = importLockName_SELECT($DBConnect);
    if (!importLockAcquired_SELECT($DBConnect, $lock)) {
        if ($timed) {
            return "running";
        }
        throw new RuntimeException("An import is running. Retry this command later.");
    }

    $started = false;
    try {
        // A different worker may have finished between the initial check and this lock.
        if ($timed) {
            $schedule = refreshSchedule_SELECT($DBConnect);
            if ((int) $schedule["is_due"] === 0) {
                return $delayStatus[$schedule["status"]];
            }
        }
        if ($track) {
            refreshStarted_UPDATE($DBConnect);
            $started = true;
        }
        $canFinishResponse = $web && function_exists("fastcgi_finish_request");
        $replyAfterMembers = $canFinishResponse && ($schedule["last_success_at"] ?? null) === null;
        if ($canFinishResponse && !$replyAfterMembers) {
            refreshReply(202, "started");
            fastcgi_finish_request();
        }
        importCongressMode_UPDATE($DBConnect, $timed ? "run" : $mode, $args, $step, $replyAfterMembers);
        if ($track) {
            refreshSucceeded_UPDATE($DBConnect);
        }
        return "completed";
    } catch (Throwable $error) {
        if ($started) {
            refreshFailed_UPDATE($DBConnect);
        }
        throw $error;
    } finally {
        releaseImportLock($DBConnect, $lock);
    }
}

/** Executes the requested import workflow using the caller’s locked connection. 
 * 
 * @param mysqli $DBConnect The database connection to use.
 * @param string $mode The mode of the import (e.g., "run", "reconcile", "scope", "member", "bill", "house-roll").
 * @param array $args Additional arguments for the import.
 * @param string $step Receives the name of the operation in progress.
 * @param bool $replyAfterMembers Acknowledge the first FastCGI request after the directory commits.
 * @return void
*/
function importCongressMode_UPDATE(
    mysqli $DBConnect, string $mode, array $args, string &$step = "", bool $replyAfterMembers = false
): void
{
    $when = gmdate("Y-m-d H:i:s");
    $day = (new DateTimeImmutable("now", new DateTimeZone("America/New_York")))->format("Y-m-d");
    if (in_array($mode, ["run", "reconcile", "scope"], true)) {
        $step = "calendar";
        [$congress, $session] = congressCalendar_UPDATE($DBConnect, $day);
        $step = "members";
        congressMembership_UPDATE($DBConnect, $congress, $when);
        if ($replyAfterMembers) {
            if ($session === null && currentSession_SELECT($DBConnect)["congress"] !== $congress) {
                refreshReply(202, "directory_loaded", ["code" => "no_session_for_today"]);
            } else {
                refreshReply(202, "directory_ready");
            }
            fastcgi_finish_request();
        }
        if ($mode !== "scope") {
            $step = "bills";
            billDirectory_UPDATE($DBConnect, $congress, $when, $mode === "reconcile");
            if ($session !== null) {
                $step = "house_votes";
                houseRollDirectory_UPDATE($DBConnect, $congress, $session, $when);
            }
            $step = "details";
            $limit = (int) laceSetting("LACE_ENRICH_LIMIT", "25");
            pendingDetails_UPDATE($DBConnect, $congress, $session, max(0, min(500, $limit)));
        }
    } elseif ($mode === "member") {
        legislatorDetail_UPDATE($DBConnect, bioguideKey($args[2]));
    } elseif ($mode === "bill") {
        billDetails_UPDATE(
            $DBConnect,
            billKey(["congress" => $args[2], "type" => $args[3], "number" => $args[4]]),
            true,
        );
    } elseif ($mode === "house-roll") {
        houseBallots_UPDATE(
            $DBConnect,
            positiveInteger($args[2], "Congress"),
            positiveInteger($args[3], "session"),
            positiveInteger($args[4], "roll"),
        );
    } else {
        bulkStream_UPDATE($DBConnect, $mode, positiveInteger($args[2], "Congress"), $when, $args[3] ?? null);
    }
}


// Reads and verify Congress.gov responses

/**
 * Requests one Congress.gov v3 resource with cURL and decode its JSON response.
 *
 * @param string $endpoint Path under the Congress.gov v3 API.
 * @param array $params Optional query parameters.
 * @return array Decoded Congress.gov response.
 * @throws RuntimeException If the request or response fails.
 */
function congressApiResponse_SELECT(string $endpoint, array $params = []): array
{
    $key = trim(CONGRESS_API_KEY);
    if ($key === "") {
        throw new RuntimeException("Enter CONGRESS_API_KEY at the top of syncCongress.php for this test.");
    }
    if (!function_exists("curl_init")) {
        throw new RuntimeException("Enable the PHP cURL extension for the Congress.gov importer.");
    }
    $query = http_build_query(["format" => "json", "api_key" => $key] + $params, "", "&", PHP_QUERY_RFC3986);
    $curl = curl_init(CONGRESS_API_BASE_URL . $endpoint . "?" . $query);
    if (!$curl) {
        throw new RuntimeException("Could not start the Congress.gov request.");
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ["Accept: application/json"]
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if ($body === false) {
        $code = curl_errno($curl);
        throw new RuntimeException("Congress.gov $endpoint: cURL $code (" . curl_strerror($code) . ").", 502);
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("Congress.gov $endpoint returned HTTP $status.", $status);
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException("Congress.gov $endpoint returned invalid JSON data.", 502);
    }
    return $data;
}

/** Read a paced API response and retry transient upstream failures. 
 * 
 * @param string $path The API endpoint path.
 * @param array $params Optional query parameters.
 * @return array Decoded Congress.gov response.
 * @throws RuntimeException If the request or response fails.
*/
function congressApiWithRetry_SELECT(string $path, array $params = []): array
{
    $milliseconds = max(0, (int) laceSetting("LACE_API_INTERVAL_MS", "800"));
    usleep(1000 * $milliseconds);
    for ($attempt = 1;; $attempt++) {
        try {
            return congressApiResponse_SELECT($path, $params);
        } catch (RuntimeException $error) {
            if ($attempt === 3 || !in_array($error->getCode(), [502, 503, 504], true)) {
                throw $error;
            }
            sleep($attempt);
        }
    }
}

/**
 * Follow verified pagination offsets and check the reported record count.
 *
 * @param string $path The API endpoint path.
 * @param array $params Optional query parameters for the request.
 * @param callable $extract Reads this endpoint’s list from a response.
 * @param bool $strict Require a stable, verified pagination count.
 * @return array{rows:array,count:?int} Results across all pages.
 * @throws RuntimeException If the request or response fails.
 */
function congressPages_SELECT(string $path, array $params, callable $extract, bool $strict = true): array
{
    $offset = 0;
    $expected = null;
    $rows = [];
    do {
        $data = congressApiWithRetry_SELECT($path, $params + ["limit" => 250, "offset" => $offset]);
        $page = $extract($data);
        array_push($rows, ...$page);
        $pagination = $data["pagination"] ?? [];
        if (isset($pagination["count"])) {
            $count = (int) $pagination["count"];
            if ($strict && $expected !== null && $expected !== $count) {
                throw new RuntimeException("List changed while paging $path.");
            }
            $expected = $count;
        }
        $next = $pagination["next"] ?? "";
        if ($next !== "") {
            parse_str((string) parse_url($next, PHP_URL_QUERY), $query);
            $newOffset = filter_var($query["offset"] ?? null, FILTER_VALIDATE_INT);
            if (!$page || $newOffset === false || $newOffset <= $offset) {
                throw new RuntimeException("Invalid next page for $path.");
            }
            $offset = $newOffset;
        }
    } while ($next !== "");
    if ($strict && count($rows) !== $expected) {
        throw new RuntimeException("Incomplete list for $path.");
    }
    return ["rows" => $rows, "count" => $expected];
}

/** Read every page of a named Congress.gov list.
 *
 * @param string $path The API endpoint path.
 * @param string $field The field containing the list items.
 * @param array $params Optional query parameters for the request.
 * @return array The combined list of items across all pages.
 */
function congressList_SELECT(string $path, string $field, array $params = []): array
{
    return congressPages_SELECT($path, $params, fn($d) => sourceItems($d[$field] ?? null, $field))["rows"];
}


// Refreshes inventories and pending details

/**
 * Reads a single Congress from a detail response or the current-Congress list.
 *
 * @param string $path Local API path for the current or numbered Congress.
 * @return array The source record, or an empty array if missing.
 */
function congressRecord_SELECT(string $path): array
{
    $data = congressApiWithRetry_SELECT($path);
    $record = $data["congress"] ?? $data["congresses"] ?? [];
    $record = $record[0] ?? $record;
    return is_array($record) ? $record : [];
}

/**
 * Stores the current Congress and its chamber sessions in a transaction.
 * 
 * @param mysqli $DBConnect The database connection.
 * @param string $day The reference date for determining the active House session.
 * @return array{0:int,1:?int} Congress and active House session.
 * @throws RuntimeException If the request or response fails.
 */
function congressCalendar_UPDATE(mysqli $DBConnect, string $day): array
{
    $record = congressRecord_SELECT("/congress/current");
    $number = $record["number"] ?? null;
    if ($number === null) {
        // A list record can identify its Congress by its item URL or official name.
        $path = (string) parse_url($record["url"] ?? "", PHP_URL_PATH);
        if (preg_match('~/congress/([1-9][0-9]*)/?$~i', $path, $matches)
            || preg_match('/^([1-9][0-9]*)(?:st|nd|rd|th) Congress$/i', $record["name"] ?? "", $matches)) {
            $number = $matches[1];
        }
    }
    $congress = positiveInteger($number, "current Congress");
    if (empty($record["sessions"])) {
        $record = congressRecord_SELECT("/congress/$congress");
    }
    $sessions = [];
    $houseSession = null;
    $latestStart = "";
    foreach (sourceItems($record["sessions"] ?? null, "sessions") as $session) {
        if (empty($session["number"]) || ($session["type"] ?? "R") !== "R") {
            continue;
        }
        $chamber = $session["chamber"] ?? "";
        $chamber = $chamber === "House" ? "House of Representatives" : $chamber;
        $start = $session["startDate"] ?? "";
        $end = ($session["endDate"] ?? "") === "" ? null : $session["endDate"];
        if (!$start || !in_array($chamber, ["House of Representatives", "Senate"], true)) {
            throw new RuntimeException("Invalid regular session.");
        }
        $number = positiveInteger($session["number"], "session");
        $sessions[] = [$chamber, $number, $start, $end];
        if ($chamber === "House of Representatives" && $start <= $day && ($end === null || $end >= $day) && $start > $latestStart) {
            $houseSession = $number;
            $latestStart = $start;
        }
    }
    if (!$sessions) {
        throw new RuntimeException("No regular sessions returned.");
    }
    congressSessions_UPDATE($DBConnect, $congress, $sessions, $record);
    return [$congress, $houseSession];
}

/** Replaces verified Congress membership and refresh current-member flags.
 * 
 * @param mysqli $DBConnect Database connection.
 * @param int $congress The Congress number.
 * @param string $when The reference date for the snapshot.
 * @throws RuntimeException If the roster is empty or contains duplicates.
 */
function congressMembership_UPDATE(mysqli $DBConnect, int $congress, string $when): void
{
    $all = [];
    $active = [];
    foreach (["false", "true"] as $filter) {
        $batch = congressList_SELECT("/member/congress/$congress", "members", ["currentMember" => $filter]);
        $unique = [];
        foreach ($batch as $member) {
            $id = bioguideKey($member["bioguideId"] ?? "");
            if (isset($unique[$id])) {
                throw new RuntimeException("Duplicate identity within a roster page set.");
            }
            $unique[$id] = true;
            $all[$id] = $member;
            if ($filter === "true") {
                $active[$id] = true;
            }
        }
    }
    if (!$all || !$active) {
        throw new RuntimeException("Empty current-Congress roster. Prior snapshot retained.");
    }
    congressMembersSnapshot_UPDATE($DBConnect, $congress, $all, $active, $when);
}

/** Refreshes one member profile and replaces its validated service terms.
 * 
 * @param mysqli $DBConnect Database connection.
 * @param string $id The bioguide ID of the legislator.
 * @return void
 */
function legislatorDetail_UPDATE(mysqli $DBConnect, string $id): void
{
    $record = congressApiWithRetry_SELECT("/member/$id")["member"] ?? [];
    legislatorProfile_UPDATE($DBConnect, $id, $record);
}

/** Loads the first complete bill list or resumes from an overlapping change window.
 * 
 * @param mysqli $DBConnect Database connection.
 * @param int $congress The Congress number.
 * @param string $when The reference date for the snapshot.
 * @param bool $full Whether to load the full bill list.
 * @return void
 * @throws RuntimeException If the initial bill list is empty or contains duplicates.
 */
function billDirectory_UPDATE(mysqli $DBConnect, int $congress, string $when, bool $full): void
{
    $previous = feedBoundary_SELECT($DBConnect, "bills", $congress);
    if (!$previous && !$full) {
        $previous = feedBoundary_SELECT($DBConnect, "bulk-floor", $congress);
        if ($previous && $previous > $when) {
            $previous = null;
        }
    }
    $params = ["toDateTime" => gmdate("Y-m-d\TH:i:s\Z", strtotime($when . " UTC"))];
    if ($previous && !$full) {
        $params["fromDateTime"] = gmdate("Y-m-d\TH:i:s\Z", strtotime($previous . " UTC") - 86400);
    }
    $rows = congressList_SELECT("/bill/$congress", "bills", $params);
    $ids = [];
    if (!$rows && ($full || !$previous)) {
        throw new RuntimeException("Empty initial bill list. Checkpoint retained.");
    }
    foreach ($rows as $bill) {
        $key = billKey($bill);
        $id = implode("/", $key);
        if ($key["congress"] !== $congress || isset($ids[$id])) {
            throw new RuntimeException("Duplicate or out-of-scope bill page. Retry.");
        }
        $ids[$id] = true;
    }
    inLaceTransaction($DBConnect, function () use ($DBConnect, $rows, $congress, $when) {
        foreach ($rows as $bill) {
            billRecord_INSERT_OR_UPDATE($DBConnect, $bill);
        }
        syncCheckpoint_INSERT_OR_UPDATE($DBConnect, "bills", $congress, 0, $when);
    });
}

/** Fetches one bill and atomically applies its verified detail collections.
 * 
 * @param mysqli $DBConnect Database connection.
 * @param array $billIdentity The identity of the bill.
 * @param bool $children Whether to fetch child collections.
 * @return void
 * @throws RuntimeException If the bill identity does not match.
 */
function billDetails_UPDATE(mysqli $DBConnect, array $billIdentity, bool $children): void
{
    $path = "/bill/" . $billIdentity["congress"] . "/" . strtolower($billIdentity["type"]) . "/" . $billIdentity["number"];
    $bill = congressApiWithRetry_SELECT($path)["bill"] ?? [];
    if (billKey($bill) !== $billIdentity) {
        throw new RuntimeException("Bill identity mismatch.");
    }
    if (!$children) {
        billRecord_INSERT_OR_UPDATE($DBConnect, $bill, true);
        return;
    }
    foreach (["actions", "cosponsors", "committees", "subjects", "summaries"] as $field) {
        if (isset($bill[$field]["count"]) && (int) $bill[$field]["count"] === 0) {
            $bill[$field] = [];
            continue;
        }
        $extract = function ($page) use ($field) {
            $items = $page[$field] ?? null;
            if ($field === "subjects" && is_array($items) && !array_is_list($items)) {
                if (!array_key_exists("legislativeSubjects", $items) && !array_key_exists("policyArea", $items)) {
                    throw new RuntimeException("Unrecognized bill subjects.");
                }
                $items = $items["legislativeSubjects"] ?? [];
            }
            return sourceItems($items, $field);
        };
        $rows = congressPages_SELECT("$path/$field", [], $extract, $field !== "subjects");
        if ($field === "subjects" && $rows["count"] !== null && $rows["count"] > count($rows["rows"]) + 1) {
            throw new RuntimeException("Incomplete legislative subjects. Prior snapshot retained.");
        }
        $bill[$field] = $rows["rows"];
    }
    $bill["sponsors"] = sourceItems($bill["sponsors"] ?? [], "sponsors");
    inLaceTransaction($DBConnect, fn() => billCollections_UPDATE($DBConnect, $bill));
}

/** Refreshes all roll headers for one House session and saves its checkpoint.
 * 
 * @param mysqli $DBConnect Database connection.
 * @param int $congress The Congress number.
 * @param int $houseSession The House session number.
 * @param string $when The reference date for the snapshot.
 * @return void
 * @throws RuntimeException If any roll listing is invalid.
 */
function houseRollDirectory_UPDATE(mysqli $DBConnect, int $congress, int $houseSession, string $when): void
{
    $rolls = congressList_SELECT("/house-vote/$congress/$houseSession", "houseRollCallVotes");
    inLaceTransaction($DBConnect, function () use ($DBConnect, $congress, $houseSession, $rolls, $when) {
        $seen = [];
        foreach ($rolls as $roll) {
            if (
                (int) $roll["congress"] !== $congress ||
                (int) $roll["sessionNumber"] !== $houseSession ||
                isset($seen[$roll["identifier"]])
            ) {
                throw new RuntimeException("Invalid roll listing.");
            }
            $seen[$roll["identifier"]] = true;
            houseRoll_INSERT_OR_UPDATE($DBConnect, $roll, true);
        }
        syncCheckpoint_INSERT_OR_UPDATE($DBConnect, "house-rolls", $congress, $houseSession, $when);
    });
}

/** Replaces a complete, unique roster of individual ballots for one House roll.
 * 
 * @param mysqli $DBConnect Database connection.
 * @param int $congress The Congress number.
 * @param int $houseSession The House session number.
 * @param int $rollNumber The roll call number.
 * @return void
 * @throws RuntimeException If the ballot roster is invalid.
 */
function houseBallots_UPDATE(mysqli $DBConnect, int $congress, int $houseSession, int $rollNumber): void
{
    $path = "/house-vote/$congress/$houseSession/$rollNumber";
    $meta = null;
    $data = congressPages_SELECT(
        "$path/members",
        [],
        function ($page) use ($congress, $houseSession, $rollNumber, &$meta) {
            $header = $page["houseRollCallVoteMemberVotes"] ?? [];
            $number = [
                (int) ($header["congress"] ?? 0),
                (int) ($header["sessionNumber"] ?? 0),
                (int) ($header["rollCallNumber"] ?? 0)
            ];
            if ($number !== [$congress, $houseSession, $rollNumber]) {
                throw new RuntimeException("Wrong ballot roster.");
            }
            if ($meta !== null && (($meta["identifier"] ?? null) != ($header["identifier"] ?? null) ||
                ($meta["updateDate"] ?? null) !== ($header["updateDate"] ?? null))) {
                throw new RuntimeException("Roster changed mid-fetch.");
            }
            unset($header["results"]);
            $meta = $header;
            return sourceItems($page["houseRollCallVoteMemberVotes"]["results"] ?? null, "ballots");
        },
        false,
    );
    $rows = $data["rows"];
    $expected = $data["count"];
    if (!$rows || $expected === null || $expected <= 1 || $expected !== count($rows)) {
        // Some responses count the enclosing vote rather than individual voters.
        $header = congressApiWithRetry_SELECT($path)["houseRollCallVote"] ?? [];
        if ((string) ($header["identifier"] ?? "") !== (string) ($meta["identifier"] ?? "")) {
            throw new RuntimeException("Wrong House roll detail.");
        }
        $expected = 0;
        foreach (sourceItems($header["votePartyTotal"] ?? [], "votePartyTotal") as $party) {
            foreach (["yeaTotal", "nayTotal", "presentTotal", "notVotingTotal"] as $choice) {
                $expected += (int) ($party[$choice] ?? 0);
            }
        }
        if (!$rows || $expected !== count($rows)) {
            throw new RuntimeException("Roster count mismatch. Previous ballots retained.");
        }
        $meta = $meta + $header;
    }
    $votes = [];
    foreach ($rows as $ballot) {
        $id = bioguideKey($ballot["bioguideId"] ?? "");
        if (isset($votes[$id]) || trim($ballot["voteCast"] ?? "") === "") {
            throw new RuntimeException("Repeated voter or missing voteCast.");
        }
        $votes[$id] = $ballot;
    }
    houseBallotSnapshot_UPDATE($DBConnect, $meta, $votes);
}

/** Refreshes a bounded number of pending members, bills and House roll rosters.
 * 
 * @param mysqli $DBConnect Database connection.
 * @param int $congress The Congress number.
 * @param int|null $houseSession The House session number, or null for all sessions.
 * @param int $budget The maximum number of records to refresh.
 * @return void
 */
function pendingDetails_UPDATE(mysqli $DBConnect, int $congress, ?int $houseSession, int $budget): void
{
    if ($budget === 0) {
        return;
    }
    $pending = pendingCongressRecords_SELECT($DBConnect, $congress, $houseSession, $budget);
    foreach ($pending["members"] as $id) {
        legislatorDetail_UPDATE($DBConnect, $id);
    }
    foreach ($pending["bills"] as $bill) {
        billDetails_UPDATE($DBConnect, billKey($bill), true);
    }
    foreach ($pending["rolls"] as $number) {
        houseBallots_UPDATE($DBConnect, $congress, $houseSession, (int) $number);
    }
}


// Optional command-line bulk import

/** Reads a private CLI bulk stream and verifies its completion trailer.
 * 
 * @param mysqli $DBConnect Database connection.
 * @param string $mode The bulk import mode, either "service-dates" or "bills".
 * @param int $congress The Congress number.
 * @param string $when The reference date for the import.
 * @param string|null $file The optional local file to read from, or null for STDIN.
 * @return void
 * @throws RuntimeException If the bulk stream is invalid or incomplete.
 */
function bulkStream_UPDATE(mysqli $DBConnect, string $mode, int $congress, string $when, ?string $file = null): void
{
    $service = $mode === "service-dates";
    $allowed = $service ? array_flip(congressMemberIds_SELECT($DBConnect, $congress)) : [];
    if ($service && !$allowed) {
        throw new RuntimeException("Load the Congress member directory before service dates.");
    }

    // The optional filename is accepted only by the private CLI. gzip also reads plain JSON lines.
    $input = $file === null ? STDIN : @gzopen($file, "rb");
    if (!$input) {
        throw new RuntimeException("Cannot open the local seed file.");
    }
    $readBatch = function () use ($DBConnect, $congress, $service, $allowed, $when, $file, $input) {
        $count = 0;
        $matched = 0;
        $trailer = null;
        if ($service) {
            memberServiceDates_DELETE($DBConnect, $congress);
        }
        while (($line = $file === null ? fgets($input) : gzgets($input)) !== false) {
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if ($trailer !== null || !is_array($row)) {
                throw new RuntimeException("Invalid bulk stream.");
            }
            if (isset($row["_complete"])) {
                $trailer = $row;
                continue;
            }
            $count++;
            if ($service) {
                $id = $row["bioguideId"] ?? "";
                if (isset($allowed[$id])) {
                    datedMemberTerms_INSERT_OR_UPDATE($DBConnect, $id, sourceItems($row["terms"] ?? null, "dated terms"));
                    $matched++;
                }
            } else {
                if ((int) ($row["congress"] ?? 0) !== $congress) {
                    throw new RuntimeException("Wrong Congress in bulk stream.");
                }
                inLaceTransaction($DBConnect, fn() => billCollections_UPDATE($DBConnect, $row));
            }
        }
        $kind = $service ? "service-dates" : "bills";
        if (
            !$count || (int) ($trailer["count"] ?? -1) !== $count ||
            (int) ($trailer["congress"] ?? 0) !== $congress || ($trailer["_complete"] ?? "") !== $kind
        ) {
            throw new RuntimeException("Incomplete bulk stream. Checkpoint retained.");
        }
        if ($service) {
            if (!$matched) {
                throw new RuntimeException("No dated records matched the congressional directory.");
            }
            syncCheckpoint_INSERT_OR_UPDATE($DBConnect, $kind, $congress, 0, $when);
        } elseif (count(array_unique($trailer["types"] ?? [])) === 8) {
            inLaceTransaction($DBConnect, function () use ($DBConnect, $congress, $when, $trailer) {
                syncCheckpoint_INSERT_OR_UPDATE($DBConnect, "bulk-bills", $congress, 0, $when);
                if (!empty($trailer["bulkFloor"])) {
                    syncCheckpoint_INSERT_OR_UPDATE($DBConnect, "bulk-floor", $congress, 0, utcTimestamp($trailer["bulkFloor"]));
                }
            });
        }
        return $count;
    };

    // Service dates are one snapshot. Each bulk bill is its own complete transaction.
    try {
        $count = $service ? inLaceTransaction($DBConnect, $readBatch) : $readBatch();
    } finally {
        if ($file !== null) gzclose($input);
    }
    fwrite(STDOUT, "Processed $count source records." . PHP_EOL);
}

// Entry point for the script when executed directly from the CLI or web server.
if (realpath($_SERVER["SCRIPT_FILENAME"] ?? "") === __FILE__) {
    date_default_timezone_set("UTC");
    $web = PHP_SAPI !== "cli";
    exit(runCongressSync_UPDATE($web ? [] : $argv, $web));
}
