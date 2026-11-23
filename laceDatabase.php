<?php
/**
 * MySQL queries for LACE webpages and the Congress.gov importer.
 * 
 * @package LACE
 */
declare(strict_types=1);

// LACE measure types and bill-related constants
const LACE_MEASURE_TYPES = ["HR", "S", "HRES", "SRES", "HJRES", "SJRES", "HCONRES", "SCONRES"];
const LACE_BILL_COLUMNS = "b.congress,b.type,b.number,b.title,b.originChamber,b.introducedDate,
    b.updateDate,b.updateDateIncludingText,a.actionDate,a.text AS actionText";
const LACE_LATEST_ACTION = " LEFT JOIN bill_actions a ON a.congress=b.congress AND a.billType=b.type
    AND a.billNumber=b.number AND a.actionIndex=1 ";

// U.S. states and territories mapping (need full coverage for all territories)
const LACE_STATES = [
    "AL" => "Alabama", "AK" => "Alaska", "AZ" => "Arizona", "AR" => "Arkansas",
    "CA" => "California", "CO" => "Colorado", "CT" => "Connecticut", "DE" => "Delaware",
    "DC" => "District of Columbia", "FL" => "Florida", "GA" => "Georgia", "HI" => "Hawaii",
    "ID" => "Idaho", "IL" => "Illinois", "IN" => "Indiana", "IA" => "Iowa",
    "KS" => "Kansas", "KY" => "Kentucky", "LA" => "Louisiana", "ME" => "Maine",
    "MD" => "Maryland", "MA" => "Massachusetts", "MI" => "Michigan", "MN" => "Minnesota",
    "MS" => "Mississippi", "MO" => "Missouri", "MT" => "Montana", "NE" => "Nebraska",
    "NV" => "Nevada", "NH" => "New Hampshire", "NJ" => "New Jersey", "NM" => "New Mexico",
    "NY" => "New York", "NC" => "North Carolina", "ND" => "North Dakota", "OH" => "Ohio",
    "OK" => "Oklahoma", "OR" => "Oregon", "PA" => "Pennsylvania", "RI" => "Rhode Island",
    "SC" => "South Carolina", "SD" => "South Dakota", "TN" => "Tennessee", "TX" => "Texas",
    "UT" => "Utah", "VT" => "Vermont", "VA" => "Virginia", "WA" => "Washington",
    "WV" => "West Virginia", "WI" => "Wisconsin", "WY" => "Wyoming", "AS" => "American Samoa",
    "GU" => "Guam", "MP" => "Northern Mariana Islands", "PR" => "Puerto Rico", "VI" => "Virgin Islands"
];

// Hardcoded campus test. Database credentials and the Congress.gov key are read from PHP literals.

/**
 * Read an optional process setting for refresh mode, pacing or batch size.
 *
 * @param string $name Server variable name.
 * @param string|null $default Value used when the variable is absent.
 * @return string|null The configured value.
 */
function laceSetting(string $name, ?string $default = null): ?string
{
    $value = getenv($name);
    return $value === false ? $default : $value;
}

/**
 * Open MySQL with the hardcoded campus test settings below.
 *
 * @return mysqli Open connection to the assigned LACE database.
 * @throws UnexpectedValueException If the test password is empty.
 * @throws mysqli_sql_exception If the connection or database selection fails.
 */
function connectLaceDatabase(): mysqli
{
    $host = "localhost";
    $user = "Group2";
    $password = "p7zcoebv"; // Enter the current Group2 MySQL password here before uploading.
    $port = 3306;
    $database = "Group2";
    if ($password === "") {
        throw new UnexpectedValueException("Enter the Group2 password in connectLaceDatabase() for this test.");
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $DBConnect = new mysqli($host, $user, $password, $database, $port);
    return $DBConnect;
}


// Public GET endpoint and webpage queries

/**
 * Serves public GET endpoint as JSON and closes the database connection.
 *
 * @return void Writes the JSON HTTP response.
 */
function servePublicCongress_SELECT(): void
{
    header("Content-Type: application/json; charset=utf-8");
    header("Cache-Control: no-store");
    if (($_SERVER["REQUEST_METHOD"] ?? "") !== "GET") {
        header("Allow: GET");
        http_response_code(405);
        echo json_encode(["error" => "Use GET."]);
        return;
    }

    try {
        $DBConnect = connectLaceDatabase();
        $data = inLaceTransaction($DBConnect, fn() => publicCongressData_SELECT($DBConnect));
        echo json_encode(["data" => $data], JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        sendReadError($error);
    } finally {
        if (isset($DBConnect)) {
            $DBConnect->close();
        }
    }
}

/**
 * Returns HTTP status and JSON message the webpage uses for this read error.
 * 
 * @param Throwable $error exception that occurred during the read operation.
 * @return void writes JSON HTTP response.
 */
function sendReadError(Throwable $error): void
{
    $body = ["error" => $error->getMessage()];
    $status = 503;
    if ($error instanceof InvalidArgumentException) {
        $status = 400;
    } elseif ($error instanceof OutOfBoundsException) {
        $status = 404;
    } elseif ($error instanceof UnderflowException) {
        header("Retry-After: 5");
        $body["code"] = "initializing";
    } else {
        error_log("LACE database: " . $error->getMessage());
        $body = ["error" => "The LACE database is unavailable."];
        if ($error instanceof mysqli_sql_exception) {
            $body["code"] = "mysql_" . $error->getCode();
        } elseif ($error instanceof UnexpectedValueException) {
            $body["code"] = "test_config";
        } elseif (!function_exists("mysqli_init") || !method_exists("mysqli_stmt", "get_result")) {
            $body["code"] = "php_driver";
        } else {
            $body["code"] = "server_error";
        }
    }
    http_response_code($status);
    echo json_encode($body);
}

/**
 * Resolves a supported public action and read its data in the current transaction.
 *
 * @param mysqli $DBConnect Active database connection.
 * @return mixed Result in the format consumed by the selected webpage.
 */
function publicCongressData_SELECT(mysqli $DBConnect): mixed
{
    $legacyActions = [
        "getCurrentCongress" => "currentCongress_SELECT",
        "getCurrentScope" => "currentScope_SELECT",
        "getCurrentMembers" => "currentMembers_SELECT",
        "getAllCurrentMembers" => "allCurrentMembers_SELECT",
        "getMemberDetails" => "legislatorProfile_SELECT",
        "getHouseVotes" => "houseRollCalls_SELECT",
        "getHouseVoteMembers" => "houseRollBallots_SELECT",
        "getBillDetails" => "billDetails_SELECT",
        "getRecentBills" => "recentBills_SELECT",
        "searchBills" => "searchBills_SELECT",
        "getSponsoredLegislation" => "sponsoredBills_SELECT",
        "getCosponsoredLegislation" => "cosponsoredBills_SELECT",
    ];
    $action = queryText("action");
    $action = $legacyActions[$action] ?? $action;
    if (!in_array($action, $legacyActions, true)) {
        throw new InvalidArgumentException("Unknown database action.");
    }

    $limit = integerParameter("limit", 5, 1, 250);
    $offset = integerParameter("offset", 0, 0, 1000000);
    if ($action === "billDetails_SELECT") {
        return billDetails_SELECT($DBConnect);
    }
    if ($action === "houseRollCalls_SELECT" || $action === "houseRollBallots_SELECT") {
        return houseVoteData_SELECT($DBConnect, $action);
    }

    $scope = currentSession_SELECT($DBConnect);
    $congress = $scope["congress"];
    if (!$congress) {
        throw new UnderflowException("LACE is preparing the congressional directory. Try again shortly.");
    }
    switch ($action) {
        case "currentCongress_SELECT":
            return $congress;
        case "currentScope_SELECT":
            return $scope;
        case "currentMembers_SELECT":
        case "allCurrentMembers_SELECT":
            checkDirectory_SELECT($DBConnect, $congress, "members");
            $count = $action === "allCurrentMembers_SELECT" ? 1000 : $limit;
            return legislatorProfiles_SELECT($DBConnect, $congress, null, $count);
        case "searchBills_SELECT":
            checkDirectory_SELECT($DBConnect, $congress, "bills");
            $search = queryText("q");
            $match = "%" . strtr($search, ["!" => "!!", "%" => "!%", "_" => "!_"]) . "%";
            $where = " FROM bills WHERE congress=? AND title IS NOT NULL AND
                (title LIKE ? ESCAPE '!' OR CONCAT(type,number) LIKE ? ESCAPE '!' OR CONCAT(type,' ',number) LIKE ? ESCAPE '!')";
            $bind = [$congress,$match,$match,$match];
            $total = (int) laceResult_SELECT($DBConnect, "SELECT COUNT(*)".$where, $bind)->fetch_column();
            return ["total"=>$total, "items"=>laceRows_SELECT($DBConnect,
                "SELECT congress,type,number,title".$where." ORDER BY updateDate DESC,type,number LIMIT ? OFFSET ?",
                array_merge($bind,[$limit,$offset]))];
        case "recentBills_SELECT":
            checkDirectory_SELECT($DBConnect, $congress, "bills");
            return recentBills_SELECT($DBConnect, $congress, $limit);
    }

    $id = strtoupper(queryText(isset($_GET["bioguideId"]) ? "bioguideId" : "id"));
    if (!preg_match('/^[A-Z][0-9]{6}$/D', $id)) {
        throw new InvalidArgumentException("Invalid bioguideId.");
    }
    if ($action === "legislatorProfile_SELECT") {
        checkDirectory_SELECT($DBConnect, $congress, "members");
        $profiles = legislatorProfiles_SELECT($DBConnect, $congress, $id, 1);
        if (!$profiles) {
            throw new OutOfBoundsException("Member unavailable.");
        }
        return $profiles[0];
    }
    $role = $action === "sponsoredBills_SELECT" ? "sponsor" : "cosponsor";
    return memberLegislation_SELECT($DBConnect, $role, $congress, $id, $limit, $offset);
}

/** Reads a single text parameter from query string. 
 * 
 * @param string $name Parameter name.
 * @param string $default Default value if parameter is not set.
 * @return string Parameter value.
 * @throws InvalidArgumentException if the parameter is not a string.
 */
function queryText(string $name, string $default = ""): string
{
    $value = $_GET[$name] ?? $default;
    if (!is_string($value)) {
        throw new InvalidArgumentException("Invalid $name.");
    }
    return $value;
}

/** Validates an integer from the request against its allowed range. 
 * 
 * @param string $key Parameter name.
 * @param int $default Default value if parameter is not set.
 * @param int $min Minimum allowed value.
 * @param int $max Maximum allowed value.
 * @return int Validated integer value.
 * @throws InvalidArgumentException if the parameter is not a valid integer within the specified range.
 */
function integerParameter(string $key, int $default, int $min, int $max): int
{
    $value = filter_var($_GET[$key] ?? $default, FILTER_VALIDATE_INT, [
        "options" => ["min_range" => $min, "max_range" => $max],
    ]);
    if ($value === false) {
        throw new InvalidArgumentException("Invalid $key.");
    }
    return $value;
}

/**
 * Reads the current Congress and active House session on the Eastern calendar.
 *
 * @param mysqli $DBConnect Active database connection.
 * @return array{congress:int,session:?int} Current Congress and optional House session.
 */
function currentSession_SELECT(mysqli $DBConnect): array
{
    $today = (new DateTimeImmutable("today", new DateTimeZone("America/New_York")))->format("Y-m-d");
    $rows = laceRows_SELECT($DBConnect, "SELECT congress, number, chamber FROM congress_sessions
        WHERE startDate <= ? AND (endDate IS NULL OR endDate >= ?)
        ORDER BY startDate DESC, congress DESC, number DESC", [$today, $today]);
    $congress = (int) ($rows[0]["congress"] ?? 0);
    $session = null;
    foreach ($rows as $row) {
        if ((int) $row["congress"] === $congress && $row["chamber"] === "House of Representatives") {
            $session = (int) $row["number"];
            break;
        }
    }
    return ["congress" => $congress, "session" => $session];
}

/** Signals that the first directory is loading until its feed or initial rows exist.
 * 
 * @param mysqli $DBConnect Active database connection.
 * @param int $congress Congress number to check.
 * @param string $feed Feed type, either "members" or "bills".
 * @throws UnderflowException if the first directory is still loading.
 */
function checkDirectory_SELECT(mysqli $DBConnect, int $congress, string $feed): void
{
    if (feedBoundary_SELECT($DBConnect, $feed, $congress)) {
        return;
    }
    $table = $feed === "members" ? "congress_members" : "bills";
    if (!laceRows_SELECT($DBConnect, "SELECT 1 FROM $table WHERE congress=? LIMIT 1", [$congress])) {
        throw new UnderflowException("LACE is loading the first directory. Try again shortly.");
    }
}

/**
 * Reads member profiles and attach their terms in a second batched query.
 *
 * @param string|null $id A member ID, or null to list the current directory.
 * @param int $congress Congress number to filter by.
 * @param int $limit Maximum number of members to return.
 * @return array List of member profiles with their terms attached.
 * @throws UnderflowException if the first directory is still loading.
 */
function legislatorProfiles_SELECT(mysqli $DBConnect, int $congress, ?string $id, int $limit): array
{
    $filter = $id === null ? "m.congress=? AND l.currentMember=1" : "l.bioguideId=?";
    $join = $id === null ? "JOIN congress_members m USING (bioguideId)" : "";
    $members = laceRows_SELECT(
        $DBConnect,
        "SELECT l.bioguideId,l.name,l.partyName,l.state,l.district,l.chamber,l.startYear,
        l.imageUrl,l.attribution,l.currentMember FROM lace_member_profiles l $join WHERE $filter ORDER BY l.name,l.bioguideId LIMIT $limit",
        [$id ?? $congress],
    );
    if (!$members) {
        return [];
    }
    $marks = implode(",", array_fill(0, count($members), "?"));
    $terms = laceRows_SELECT(
        $DBConnect,
        "SELECT bioguideId,congress,chamber,memberType,stateCode,district,startYear,endYear,partyName
        FROM legislator_terms WHERE bioguideId IN ($marks) ORDER BY bioguideId,termIndex",
        array_column($members, "bioguideId"),
    );
    $group = [];
    foreach ($terms as $term) {
        $key = $term["bioguideId"];
        unset($term["bioguideId"]);
        $group[$key][] = $term;
    }
    foreach ($members as &$member) {
        $member["state"] = LACE_STATES[$member["state"] ?? ""] ?? $member["state"];
        $member["directOrderName"] = $member["name"];
        $member["depiction"] =
            $member["imageUrl"] === null
                ? null
                : ["imageUrl" => $member["imageUrl"], "attribution" => $member["attribution"]];
        unset($member["imageUrl"], $member["attribution"]);
        $member["currentMember"] = $member["currentMember"] === null ? null : (bool) $member["currentMember"];
        $member["terms"] = ["item" => $group[$member["bioguideId"]] ?? []];
    }
    unset($member);
    return $members;
}

/** Arranges the latest action for each bill in the browser response. */
function formatBillRows(array $rows): array
{
    foreach ($rows as &$row) {
        $row["latestAction"] = isset($row["actionDate"])
            ? ["actionDate" => $row["actionDate"], "text" => $row["actionText"]]
            : null;
        unset($row["actionDate"], $row["actionText"]);
    }
    return $rows;
}

/** Reads the most recently updated measures in the selected Congress. */
function recentBills_SELECT(mysqli $DBConnect, int $congress, int $limit): array
{
    $sql = "SELECT " . LACE_BILL_COLUMNS . " FROM bills b " . LACE_LATEST_ACTION
        . " WHERE b.congress=? AND b.title IS NOT NULL ORDER BY b.updateDate DESC,b.type,b.number LIMIT ?";
    return formatBillRows(laceRows_SELECT($DBConnect, $sql, [$congress, $limit]));
}

/**
 * Counts and pages the bills a member sponsored or cosponsored.
 *
 * @return array{count:int,items:array} Total matches and one page of bills.
 */
function memberLegislation_SELECT(mysqli $DBConnect, string $role, int $congress, string $id, int $limit, int $offset): array
{
    $where = " WHERE s.congress=? AND s.bioguideId=? AND s.sponsor_role=? ";
    $values = [$congress, $id, $role];
    $count = laceResult_SELECT($DBConnect, "SELECT COUNT(*) FROM bill_sponsors s" . $where, $values)->fetch_column();
    $sql = "SELECT " . LACE_BILL_COLUMNS . " FROM bill_sponsors s JOIN bills b
        ON (b.congress,b.type,b.number)=(s.congress,s.billType,s.billNumber) "
        . LACE_LATEST_ACTION . $where . " ORDER BY b.introducedDate DESC,b.type,b.number LIMIT ? OFFSET ?";
    $bills = laceRows_SELECT($DBConnect, $sql, array_merge($values, [$limit, $offset]));
    return ["count" => (int) $count, "items" => formatBillRows($bills)];
}

/** Reads one bill with its current evidence, sponsorship, actions and feedback totals. */
function billDetails_SELECT(mysqli $DBConnect): array
{
    $congress = integerParameter("congress", 0, 1, 65535);
    $number = integerParameter("number", 0, 1, 1000000);
    $type = strtoupper(queryText("type"));
    if (!in_array($type, LACE_MEASURE_TYPES, true)) {
        throw new InvalidArgumentException("Invalid measure type.");
    }
    $key = [$congress, $type, $number];
    $child = " WHERE congress=? AND billType=? AND billNumber=? ";
    $data = laceRows_SELECT($DBConnect, "SELECT congress,type,number,title,originChamber,introducedDate,name,relatedStatus
        FROM bills WHERE congress=? AND type=? AND number=?", $key)[0] ?? null;
    if (!$data) {
        $schedule = refreshSchedule_SELECT($DBConnect);
        if (($schedule["last_success_at"] ?? null) === null &&
            !laceRows_SELECT($DBConnect, "SELECT 1 FROM bills LIMIT 1") &&
            !feedBoundary_SELECT($DBConnect, "bills", $congress)) {
            throw new UnderflowException("LACE is loading its first bill inventory. Try again shortly.");
        }
        throw new OutOfBoundsException("Bill unavailable.");
    }
    $summary = laceRows_SELECT($DBConnect, "SELECT text FROM bill_summaries" . $child, $key);
    $data["summaryText"] = null;
    if ($summary) {
        $text = strip_tags(str_ireplace(["</p>", "</li>"], ["\n", "\n"], $summary[0]["text"]));
        $data["summaryText"] = trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, "UTF-8"));
    }

    $data["actions"] = laceRows_SELECT($DBConnect, "SELECT actionDate,type,text FROM bill_actions"
        . $child . " ORDER BY actionIndex", $key);
    $data["sponsors"] = laceRows_SELECT($DBConnect, "SELECT s.bioguideId,
        COALESCE(l.name,s.bioguideId) AS displayName, s.sponsor_role
        FROM bill_sponsors s JOIN lace_member_profiles l USING (bioguideId)
        WHERE s.congress=? AND s.billType=? AND s.billNumber=? ORDER BY s.sponsor_role,displayName", $key);
    $data["committees"] = laceRows_SELECT($DBConnect, "SELECT c.name,c.systemCode
        FROM bill_committees s JOIN committees c USING (systemCode)
        WHERE s.congress=? AND s.billType=? AND s.billNumber=? ORDER BY c.name", $key);
    $subjects = laceRows_SELECT($DBConnect, "SELECT name FROM bill_subjects" . $child . " ORDER BY name", $key);
    $data["subjects"] = array_column($subjects, "name");
    $data["rolls"] = laceRows_SELECT($DBConnect, "SELECT identifier,sessionNumber,rollCallNumber,result,voteQuestion,rosterStatus
        FROM roll_calls WHERE congress=? AND legislationType=? AND legislationNumber=?
        ORDER BY sessionNumber DESC, rollCallNumber DESC", $key);
    $data["feedback"] = laceRows_SELECT($DBConnect, "SELECT position,COUNT(*) AS total FROM bill_feedback"
        . $child . " GROUP BY position", $key);
    $data["sources"] = laceRows_SELECT($DBConnect, "SELECT url,cited_claim FROM sources"
        . $child . " AND status='approved' ORDER BY source_id DESC", $key);
    return $data;
}

/** Reads House roll calls or the complete ballot roster for one recorded vote. */
function houseVoteData_SELECT(mysqli $DBConnect, string $action): array
{
    $congress = integerParameter("congress", 0, 1, 65535);
    $session = integerParameter("session", 0, 1, 2);
    if ($action === "houseRollCalls_SELECT") {
        return laceRows_SELECT($DBConnect, "SELECT identifier,congress,sessionNumber,rollCallNumber,
            legislationType,legislationNumber,startDate,voteQuestion,voteType,result,updateDate,rosterStatus
            FROM roll_calls WHERE congress=? AND sessionNumber=? ORDER BY rollCallNumber DESC", [$congress, $session]);
    }
    $number = integerParameter("rollCall", 0, 1, 1000000);
    $rows = laceRows_SELECT($DBConnect, "SELECT identifier FROM roll_calls
        WHERE congress=? AND sessionNumber=? AND rollCallNumber=? AND rosterStatus='complete'", [$congress, $session, $number]);
    if (!$rows) {
        throw new OutOfBoundsException("Complete roll roster unavailable.");
    }
    return laceRows_SELECT($DBConnect, "SELECT bioguideId,voteCast,voteParty,voteState
        FROM legislator_votes WHERE identifier=? ORDER BY bioguideId", [$rows[0]["identifier"]]);
}


// Prepared statements and transactions

/**
 * Prepares a SQL template, binds its values, and executes it on the open connection.
 *
 * @param array $values Values in placeholder order.
 * @return mysqli_stmt Executed statement. The caller closes it.
 */
function executeLaceStatement(mysqli $DBConnect, string $SQLstring, array $values = []): mysqli_stmt
{
    $statement = $DBConnect->prepare($SQLstring);
    if ($values) {
        $types = "";
        foreach ($values as $value) {
            $types .= is_int($value) ? "i" : "s";
        }
        $statement->bind_param($types, ...$values);
    }
    $statement->execute();
    return $statement;
}

/** Runs a prepared SELECT and returns the buffered result. */
function laceResult_SELECT(mysqli $DBConnect, string $SQLstring, array $values = []): mysqli_result
{
    $statement = executeLaceStatement($DBConnect, $SQLstring, $values);
    $result = $statement->get_result();
    $statement->close();
    return $result;
}

/**
 * Reads all matching MySQL rows as associative arrays.
 *
 * @return array<int, array<string, mixed>> Rows keyed by database column name.
 */
function laceRows_SELECT(mysqli $DBConnect, string $SQLstring, array $values = []): array
{
    $QueryResult = laceResult_SELECT($DBConnect, $SQLstring, $values);
    $rows = [];
    while ($Row = $QueryResult->fetch_assoc()) {
        $rows[] = $Row;
    }
    $QueryResult->free_result();
    return $rows;
}

/** Deletes matching records within the import transaction. */
function congressRows_DELETE(mysqli $DBConnect, string $SQLstring, array $values = []): void
{
    executeLaceStatement($DBConnect, $SQLstring, $values)->close();
}

/** Applies a prepared update to the importer’s matching rows. */
function congressRows_UPDATE(mysqli $DBConnect, string $SQLstring, array $values = []): void
{
    executeLaceStatement($DBConnect, $SQLstring, $values)->close();
}

/**
 * Inserts a record under its natural key or updates only the supplied columns.
 *
 * @param array $key Primary-key columns and values.
 * @param array $fields Non-key columns supplied by the source.
 */
function congressRecord_INSERT_OR_UPDATE(mysqli $DBConnect, string $table, array $key, array $fields = []): void
{
    // The importer supplies table and column names. Source values always use placeholders.
    $record = $key + $fields;
    $columns = "`" . implode("`, `", array_keys($record)) . "`";
    $marks = implode(", ", array_fill(0, count($record), "?"));
    $assignments = [];
    foreach (array_keys($fields) as $column) {
        $assignments[] = "`$column` = ?";
    }
    if (!$assignments) {
        $firstKey = array_key_first($key);
        $assignments[] = "`$firstKey` = `$firstKey`";  // An existing identity-only row needs no edits.
    }
    $sql = "INSERT INTO `$table` ($columns) VALUES ($marks) ON DUPLICATE KEY UPDATE "
        . implode(", ", $assignments);
    $values = array_merge(array_values($record), array_values($fields));
    executeLaceStatement($DBConnect, $sql, $values)->close();
}

/**
 * Commits a complete unit of work or rolls it back when an exception occurs.
 *
 * @param callable $work A complete read or write operation.
 * @return mixed The callback result after a successful commit.
 * @throws Throwable Rolls back and rethrows the original error.
 */
function inLaceTransaction(mysqli $DBConnect, callable $work): mixed
{
    $DBConnect->begin_transaction();
    try {
        $result = $work();
        $DBConnect->commit();
        return $result;
    } catch (Throwable $error) {
        $DBConnect->rollback();
        throw $error;
    }
}


// Congress.gov field and identity checks

/** Validates a positive numeric identifier. */
function positiveInteger(mixed $value, string $field): int
{
    $n = filter_var($value, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
    if ($n === false) {
        throw new RuntimeException("Invalid $field.");
    }
    return $n;
}

/** Validates and normalizes a Congressional Bioguide identifier. */
function bioguideKey(mixed $value): string
{
    $id = strtoupper(trim((string) $value));
    if (!preg_match('/^[A-Z][0-9]{6}$/D', $id)) {
        throw new RuntimeException("Invalid bioguideId.");
    }
    return $id;
}

/** Validates the source abbreviation for a federal measure. */
function measureType(mixed $value): string
{
    $value = strtoupper((string) $value);
    if (!in_array($value, LACE_MEASURE_TYPES, true)) {
        throw new RuntimeException("Unknown federal measure type.");
    }
    return $value;
}

/** Converts an optional source timestamp to a MySQL UTC date and time. */
function utcTimestamp(mixed $timestamp): ?string
{
    if ($timestamp === null || $timestamp === "") {
        return null;
    }
    return (new DateTimeImmutable((string) $timestamp, new DateTimeZone("UTC")))
        ->setTimezone(new DateTimeZone("UTC"))
        ->format("Y-m-d H:i:s");
}

/**
 * Compares an incoming source version with a stored UTC timestamp at the precision supplied by Congress.gov.
 *
 * @return int|null Negative, zero or positive for older, same or newer. Null means a date is missing.
 */
function compareSourceDate(?string $incoming, ?string $stored, string $original): ?int
{
    if ($incoming === null || $stored === null) {
        return null;
    }
    $length = strlen($original) === 10 ? 10 : 19;
    return strcmp(substr($incoming, 0, $length), substr($stored, 0, $length));
}

/**
 * Reads a source collection in list form or unwraps its item element.
 *
 * @return array A list, including a list nested under the API’s item key.
 */
function sourceItems(mixed $value, string $field): array
{
    if (is_array($value) && isset($value["item"])) {
        $value = $value["item"];
    }
    if (!is_array($value) || !array_is_list($value)) {
        throw new RuntimeException("Unrecognized $field collection.");
    }
    return $value;
}

/** Copies the requested fields that are present in a source record. */
function sourceFields(array $record, array $columns): array
{
    return array_intersect_key($record, array_flip($columns));
}

/**
 * Builds the three-column bill identity from a source record.
 *
 * @return array{congress:int,type:string,number:int} Natural bill identity.
 */
function billKey(array $record): array
{
    return [
        "congress" => positiveInteger($record["congress"] ?? null, "Congress"),
        "type" => measureType($record["type"] ?? ""),
        "number" => positiveInteger($record["number"] ?? null, "bill number"),
    ];
}

/** Maps a bill primary key to the foreign-key column names in child tables. */
function billChildKey(array $billIdentity): array
{
    return ["congress" => $billIdentity["congress"], "billType" => $billIdentity["type"], "billNumber" => $billIdentity["number"]];
}


// Congress, member, bill and roll-call writes

/** Returns the official postal abbreviation used by the team table. */
function laceStateCode(string $state): ?string
{
    $code = strtoupper(trim($state));
    if (isset(LACE_STATES[$code])) {
        return $code;
    }
    if (in_array($state, ["U.S. Virgin Islands", "United States Virgin Islands"], true)) {
        return "VI";
    }
    return array_search($state, LACE_STATES, true) ?: null;
}

/** Selects current chamber and earliest known service year from unordered source terms. */
function memberServiceFields(array $terms): array
{
    if (!$terms) {
        return [];
    }
    usort($terms, fn($a, $b) => ($b["startYear"] ?? 0) <=> ($a["startYear"] ?? 0));
    $latest = $terms[0];
    $fields = sourceFields($latest, ["chamber", "district"]);
    if (($fields["chamber"] ?? "") === "Senate") {
        $fields["district"] = null;
    }
    if (!empty($latest["stateCode"])) {
        $fields["state"] = $latest["stateCode"];
    }
    $years = array_filter(array_column($terms, "startYear"));
    if ($years && min($years) >= 1901) {
        $fields["startYear"] = min($years); // MySQL YEAR can represent 1901 and later.
    }
    return $fields;
}

/** Matches a Congress.gov person by the team's unique bioguide_id, preserving legislator_id. */
function legislatorRecord_INSERT_OR_UPDATE(mysqli $db, string $id, array $fields): void
{
    $columns = ["name" => "full_name", "partyName" => "party", "startYear" => "start_year"];
    $row = [];
    foreach ($fields as $key => $value) {
        if ($key === "state" && $value !== null) {
            $value = laceStateCode($value);
        }
        if ($key === "chamber" && $value === "House of Representatives") {
            $value = "House";
        }
        $row[$columns[$key] ?? $key] = $value;
    }
    congressRecord_INSERT_OR_UPDATE($db, "legislators", ["bioguide_id" => $id], $row);
}

/** Saves a verified chamber calendar and replaces the sessions for this Congress. */
function congressSessions_UPDATE(mysqli $DBConnect, int $congress, array $sessions, array $record): void
{
    inLaceTransaction($DBConnect, function () use ($DBConnect, $congress, $sessions, $record) {
        congressRecord_INSERT_OR_UPDATE($DBConnect, "congresses", ["number" => $congress], sourceFields($record, ["name", "startYear", "endYear"]));
        congressRows_DELETE($DBConnect, "DELETE FROM congress_sessions WHERE congress=?", [$congress]);
        foreach ($sessions as [$chamber, $number, $start, $end]) {
            congressRecord_INSERT_OR_UPDATE(
                $DBConnect,
                "congress_sessions",
                ["congress" => $congress, "chamber" => $chamber, "number" => $number],
                ["startDate" => $start, "endDate" => $end],
            );
        }
    });
}

/** Replaces a fully paginated membership snapshot and preserves existing people. */
function congressMembersSnapshot_UPDATE(mysqli $DBConnect, int $congress, array $all, array $active, string $when): void
{
    inLaceTransaction($DBConnect, function () use ($DBConnect, $congress, $all, $active, $when) {
        // Changes apply only after both scoped lists have been completely verified.
        congressRows_UPDATE($DBConnect, "UPDATE legislators SET currentMember=0 WHERE currentMember=1");
        congressRows_DELETE($DBConnect, "DELETE FROM congress_members WHERE congress=?", [$congress]);
        foreach ($all as $id => $member) {
            $old = laceResult_SELECT(
                $DBConnect,
                "SELECT updateDate,name,partyName,state,district FROM lace_member_profiles WHERE bioguideId=?",
                [$id],
            )->fetch_assoc();
            $date = utcTimestamp($member["updateDate"] ?? null);
            $fields = ["currentMember" => (int) isset($active[$id])];
            $version = compareSourceDate($date, $old["updateDate"] ?? null, (string) ($member["updateDate"] ?? ""));
            if ($version === null || $version >= 0) {
                $profile = sourceFields($member, ["name", "partyName", "state", "district"]);
                if (isset($profile["state"])) {
                    $profile["state"] = laceStateCode($profile["state"]);
                }
                $fields += $profile;
                $listTerms = sourceItems($member["terms"] ?? [], "directory terms");
                $fields += memberServiceFields($listTerms);
                // Keep the detailed display name when this list item is otherwise unchanged.
                if ($version === 0 && !empty($old["name"])) {
                    unset($fields["name"], $profile["name"]);
                }
                if (is_array($member["depiction"] ?? null)) {
                    $fields += sourceFields($member["depiction"], ["imageUrl", "attribution"]);
                }
                if ($date !== null && $date >= ($old["updateDate"] ?? "")) {
                    $fields["updateDate"] = $date;
                }
                $changed = $version !== 0;
                foreach ($profile as $column => $value) {
                    if ($value != ($old[$column] ?? null)) {
                        $changed = true;
                    }
                }
                if ($changed) {
                    $fields["detailPending"] = 1;
                }
            }
            legislatorRecord_INSERT_OR_UPDATE($DBConnect, $id, $fields);
            congressRecord_INSERT_OR_UPDATE($DBConnect, "congress_members", ["congress" => $congress, "bioguideId" => $id]);
            if (isset($member["terms"]) && !laceResult_SELECT($DBConnect,
                "SELECT 1 FROM legislator_terms WHERE bioguideId=? LIMIT 1", [$id])->fetch_column()) {
                // List terms are career chamber spans. Detail terms later replace them with Congress-specific rows.
                foreach (sourceItems($member["terms"], "directory terms") as $index => $term) {
                    congressRecord_INSERT_OR_UPDATE(
                        $DBConnect,
                        "legislator_terms",
                        ["bioguideId" => $id, "termIndex" => $index + 1],
                        sourceFields($term, ["chamber", "startYear", "endYear"]),
                    );
                }
            }
        }
        syncCheckpoint_INSERT_OR_UPDATE($DBConnect, "members", $congress, 0, $when);
    });
}

/** Checks the source version and saves the member profile with its service terms. */
function legislatorProfile_UPDATE(mysqli $DBConnect, string $id, array $record): void
{
    if (bioguideKey($record["bioguideId"] ?? "") !== $id) {
        throw new RuntimeException("Member identity mismatch.");
    }
    $old = [];
    if (!empty($record["updateDate"])) {
        $old = laceRows_SELECT($DBConnect, "SELECT updateDate FROM lace_member_profiles WHERE bioguideId=?", [$id]);
        $version = compareSourceDate(utcTimestamp($record["updateDate"]), $old[0]["updateDate"] ?? null, $record["updateDate"]);
        if ($version !== null && $version < 0) {
            throw new RuntimeException("Older member detail. Prior snapshot retained.");
        }
    }
    $terms = sourceItems($record["terms"] ?? null, "member terms");
    inLaceTransaction($DBConnect, function () use ($DBConnect, $id, $record, $terms, $old) {
        $fields = sourceFields($record, ["state"]);
        $display = $record["directOrderName"] ?? $record["invertedOrderName"] ?? trim(($record["firstName"] ?? "") . " " . ($record["lastName"] ?? ""));
        if ($display !== "") {
            $fields["name"] = $display;
        }
        $fields += memberServiceFields($terms);
        $parties = $record["partyHistory"] ?? [];
        if ($parties) {
            usort($parties, fn($a, $b) => ($b["startYear"] ?? 0) <=> ($a["startYear"] ?? 0));
            $fields += sourceFields($parties[0], ["partyName"]);
        }
        $stamp = utcTimestamp($record["updateDate"] ?? null);
        if ($stamp !== null && $stamp >= ($old[0]["updateDate"] ?? "")) {
            $fields["updateDate"] = $stamp;
        }
        $fields["district"] = $record["district"] ?? ($fields["district"] ?? null);
        $fields += [
            "imageUrl" => $record["depiction"]["imageUrl"] ?? null,
            "attribution" => $record["depiction"]["attribution"] ?? null,
        ];
        if (isset($record["currentMember"]) && !laceResult_SELECT($DBConnect,
            "SELECT 1 FROM lace_member_profiles WHERE bioguideId=? AND currentMember IS NOT NULL", [$id])->fetch_column()) {
            $flag = filter_var($record["currentMember"], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($flag === null) {
                throw new RuntimeException("Invalid currentMember.");
            }
            $fields["currentMember"] = (int) $flag;
        }
        $fields["detailPending"] = 0;
        legislatorRecord_INSERT_OR_UPDATE($DBConnect, $id, $fields);
        congressRows_DELETE($DBConnect, "DELETE FROM legislator_terms WHERE bioguideId=?", [$id]);
        foreach ($terms as $index => $term) {
            if (isset($term["congress"])) {
                congressRecord_INSERT_OR_UPDATE($DBConnect, "congresses", ["number" => positiveInteger($term["congress"], "term Congress")]);
            }
            $termKey = ["bioguideId" => $id, "termIndex" => $index + 1];
            $termFields = sourceFields($term, ["congress", "chamber", "memberType", "stateCode",
                "district", "startYear", "endYear", "partyName"]);
            congressRecord_INSERT_OR_UPDATE($DBConnect, "legislator_terms", $termKey, $termFields);
        }
    });
}

/**
 * Merges a bill by Congress, type and number, preserving newer source data.
 *
 * @return bool Whether the incoming version was accepted.
 */
function billRecord_INSERT_OR_UPDATE(mysqli $DBConnect, array $record, bool $detail = false): bool
{
    $billKey = billKey($record);
    congressRecord_INSERT_OR_UPDATE($DBConnect, "congresses", ["number" => $billKey["congress"]]);
    $sourceDate = $record["updateDate"] ?? null;
    $date = utcTimestamp($sourceDate);
    $textDate = utcTimestamp($record["updateDateIncludingText"] ?? null);
    $old = laceResult_SELECT(
        $DBConnect,
        "SELECT updateDate,updateDateIncludingText,title,originChamber,relatedStatus FROM bills WHERE congress=? AND type=? AND number=?",
        array_values($billKey),
    )->fetch_assoc();
    $version = compareSourceDate($date, $old["updateDate"] ?? null, (string) $sourceDate);
    if ($version !== null && $version < 0) {
        return false;
    }
    $fields = sourceFields($record, ["title", "originChamber"]);
    $changed = $version !== 0 || ($textDate !== null && $textDate !== ($old["updateDateIncludingText"] ?? null));
    foreach ($fields as $column => $value) {
        if ($value !== ($old[$column] ?? null)) {
            $changed = true;
        }
    }
    if ($date !== null && (!$old || $old["updateDate"] === null || $date >= $old["updateDate"])) {
        $fields["updateDate"] = $date;
    }
    if ($textDate !== null) {
        $fields["updateDateIncludingText"] = $textDate;
    }
    if (isset($record["introducedDate"])) {
        $fields["introducedDate"] = $record["introducedDate"];
    }
    if ($detail) {
        $fields["introducedDate"] = $record["introducedDate"] ?? null;
        $fields["name"] = $record["policyArea"]["name"] ?? null;
        $fields["detailPending"] = 0;
    } elseif ($changed) {
        $fields["detailPending"] = 1;
        if (($old["relatedStatus"] ?? "none") === "current") {
            $fields["relatedStatus"] = "stale";
        }
    }
    congressRecord_INSERT_OR_UPDATE($DBConnect, "bills", $billKey, $fields);
    return true;
}

/** Replaces a validated bill’s actions, sponsors, committees, subjects and latest summary. */
function billCollections_UPDATE(mysqli $DBConnect, array $record): void
{
    $key = billKey($record);
    $childKey = billChildKey($key);
    $keyValues = array_values($key);
    if (!billRecord_INSERT_OR_UPDATE($DBConnect, $record, true)) {
        return;
    }
    $childTables = ["bill_actions", "bill_sponsors", "bill_subjects", "bill_committees", "bill_summaries"];
    foreach ($childTables as $table) {
        congressRows_DELETE($DBConnect, "DELETE FROM $table WHERE congress=? AND billType=? AND billNumber=?", $keyValues);
    }
    $actions = sourceItems($record["actions"] ?? null, "actions");
    foreach ($actions as $index => $action) {
        $fields = sourceFields($action, ["actionDate", "type", "text"]);
        congressRecord_INSERT_OR_UPDATE($DBConnect, "bill_actions", $childKey + ["actionIndex" => $index + 1], $fields);
    }
    $roles = [];
    foreach (["cosponsors" => "cosponsor", "sponsors" => "sponsor"] as $list => $role) {
        foreach (sourceItems($record[$list] ?? null, $list) as $person) {
            if (empty($person["sponsorshipWithdrawnDate"])) {
                $roles[bioguideKey($person["bioguideId"] ?? "")] = $role;
            }
        }
    }
    foreach ($roles as $id => $role) {
        legislatorRecord_INSERT_OR_UPDATE($DBConnect, $id, []); // Identity-only until the official directory supplies the name.
        congressRecord_INSERT_OR_UPDATE($DBConnect, "bill_sponsors", $childKey + ["bioguideId" => $id], ["sponsor_role" => $role]);
    }
    foreach (sourceItems($record["subjects"] ?? null, "subjects") as $subject) {
        if (trim((string) ($subject["name"] ?? "")) === "") {
            throw new RuntimeException("Missing subject name.");
        }
        congressRecord_INSERT_OR_UPDATE($DBConnect, "bill_subjects", $childKey + ["name" => $subject["name"]]);
    }
    foreach (sourceItems($record["committees"] ?? null, "committees") as $committee) {
        foreach (array_merge([$committee], sourceItems($committee["subcommittees"] ?? [], "subcommittees")) as $item) {
            $code = trim($item["systemCode"] ?? "");
            if ($code === "") {
                throw new RuntimeException("Missing systemCode.");
            }
            congressRecord_INSERT_OR_UPDATE(
                $DBConnect,
                "committees",
                ["systemCode" => $code],
                [
                    "name" => $item["name"] ?? null,
                ],
            );
            congressRecord_INSERT_OR_UPDATE($DBConnect, "bill_committees", $childKey + ["systemCode" => $code]);
        }
    }
    $latest = null;
    $latestVersion = [];
    foreach (sourceItems($record["summaries"] ?? null, "summaries") as $summary) {
        $version = [$summary["actionDate"] ?? "", $summary["updateDate"] ?? "", $summary["versionCode"] ?? ""];
        if ($latest === null || $version > $latestVersion) {
            $latest = $summary;
            $latestVersion = $version;
        }
    }
    if ($latest !== null) {
        if (!isset($latest["text"])) {
            throw new RuntimeException("Missing summary text.");
        }
        congressRecord_INSERT_OR_UPDATE($DBConnect, "bill_summaries", $childKey, [
            "text" => $latest["text"]
        ]);
    }
    congressRows_UPDATE(
        $DBConnect,
        "UPDATE bills SET relatedStatus='current' WHERE congress=? AND type=? AND number=?",
        $keyValues,
    );
}

/** Merges one House roll header and mark changed ballot coverage for refresh. */
function houseRoll_INSERT_OR_UPDATE(mysqli $DBConnect, array $record, bool $authoritativeList = false): string
{
    $id = (string) ($record["identifier"] ?? "");
    if (!preg_match('/^[0-9]{1,32}$/D', $id)) {
        throw new RuntimeException("Invalid House identifier.");
    }
    $congress = positiveInteger($record["congress"] ?? null, "roll Congress");
    $session = positiveInteger($record["sessionNumber"] ?? null, "sessionNumber");
    $rollNumber = positiveInteger($record["rollCallNumber"] ?? null, "roll number");
    $old = laceRows_SELECT(
        $DBConnect,
        'SELECT identifier,congress,sessionNumber,rollCallNumber,updateDate,rosterStatus FROM roll_calls
        WHERE identifier=? OR (congress=? AND sessionNumber=? AND rollCallNumber=?)',
        [$id, $congress, $session, $rollNumber],
    );
    foreach ($old as $stored) {
        $storedNumber = [(int) $stored["congress"], (int) $stored["sessionNumber"], (int) $stored["rollCallNumber"]];
        if ($stored["identifier"] !== $id || $storedNumber !== [$congress, $session, $rollNumber]) {
            throw new RuntimeException("Official identifier conflicts with the roll number.");
        }
    }
    $updateDate = utcTimestamp($record["updateDate"] ?? null);
    $stored = $old[0] ?? [];
    if (!empty($stored["updateDate"]) && $updateDate !== null && $updateDate < $stored["updateDate"]) {
        throw new RuntimeException("Older roll snapshot. Retry later.");
    }
    congressRecord_INSERT_OR_UPDATE($DBConnect, "congresses", ["number" => $congress]);
    $fields =
        ["congress" => $congress, "sessionNumber" => $session, "rollCallNumber" => $rollNumber] +
        sourceFields($record, ["voteType", "voteQuestion", "result", "amendmentType", "amendmentNumber"]);
    if (array_key_exists("legislationType", $record) !== array_key_exists("legislationNumber", $record)) {
        throw new RuntimeException("Partial official bill reference.");
    }
    if (isset($record["legislationType"], $record["legislationNumber"])) {
        $billKey = billKey([
            "congress" => $congress,
            "type" => $record["legislationType"],
            "number" => $record["legislationNumber"],
        ]);
        congressRecord_INSERT_OR_UPDATE($DBConnect, "bills", $billKey);
        $fields += ["legislationType" => $billKey["type"], "legislationNumber" => $billKey["number"]];
    } elseif ($authoritativeList || array_key_exists("legislationType", $record)) {
        $fields += ["legislationType" => null, "legislationNumber" => null];
    }
    if (array_key_exists("startDate", $record)) {
        $fields["startDate"] = utcTimestamp($record["startDate"]);
    }
    if ($updateDate !== null) {
        $fields["updateDate"] = $updateDate;
    }
    if ($stored && $stored["rosterStatus"] === "complete" && ($updateDate === null || $stored["updateDate"] !== $updateDate)) {
        $fields["rosterStatus"] = "stale";
    }
    congressRecord_INSERT_OR_UPDATE($DBConnect, "roll_calls", ["identifier" => $id], $fields);
    return $id;
}

/** Applies verified ballots, removes obsolete roster links and marks the roll complete. */
function houseBallotSnapshot_UPDATE(mysqli $DBConnect, array $meta, array $votes): void
{
    inLaceTransaction($DBConnect, function () use ($DBConnect, $meta, $votes) {
        $id = houseRoll_INSERT_OR_UPDATE($DBConnect, $meta);
        $existing = array_column(
            laceRows_SELECT($DBConnect, "SELECT bioguideId FROM legislator_votes WHERE identifier=?", [$id]),
            "bioguideId",
        );
        foreach ($votes as $bioguideId => $ballot) {
            legislatorRecord_INSERT_OR_UPDATE($DBConnect, $bioguideId, []);
            congressRecord_INSERT_OR_UPDATE(
                $DBConnect,
                "legislator_votes",
                ["identifier" => $id, "bioguideId" => $bioguideId],
                [
                    "voteCast" => $ballot["voteCast"],
                    "voteParty" => $ballot["voteParty"] ?? null,
                    "voteState" => $ballot["voteState"] ?? null,
                ],
            );
        }
        foreach (array_diff($existing, array_keys($votes)) as $bioguideId) {
            congressRows_DELETE(
                $DBConnect,
                "DELETE FROM legislator_votes WHERE identifier=? AND bioguideId=?",
                [$id, $bioguideId],
            );
        }
        congressRows_UPDATE($DBConnect, "UPDATE roll_calls SET rosterStatus='complete' WHERE identifier=?", [
            $id,
        ]);
    });
}

/** Records dated service terms for one verified member in the bulk snapshot. */
function datedMemberTerms_INSERT_OR_UPDATE(mysqli $DBConnect, string $id, array $terms): void
{
    foreach ($terms as $term) {
        if (in_array($term["type"] ?? "", ["rep", "sen"], true)) {
            $key = ["bioguideId" => $id, "type" => $term["type"], "start" => $term["start"]];
            congressRecord_INSERT_OR_UPDATE($DBConnect, "member_service_dates", $key, ["end" => $term["end"]]);
        }
    }
}

/** Clears service intervals for people in the selected verified Congress roster. */
function memberServiceDates_DELETE(mysqli $DBConnect, int $congress): void
{
    congressRows_DELETE(
        $DBConnect,
        "DELETE d FROM member_service_dates d JOIN congress_members m USING (bioguideId) WHERE m.congress=?",
        [$congress],
    );
}


// Inventory checkpoints, pending details and the site clock

/** Stores the last complete feed boundary for one Congress and scope. */
function syncCheckpoint_INSERT_OR_UPDATE(mysqli $DBConnect, string $feed, int $congress, int $scope, string $through): void
{
    congressRecord_INSERT_OR_UPDATE(
        $DBConnect,
        "sync_state",
        ["feed" => $feed, "congress" => $congress, "scopeNo" => $scope],
        ["checkedThrough" => $through],
    );
}

/** Reads a Congress-wide feed’s most recent complete checkpoint. */
function feedBoundary_SELECT(mysqli $DBConnect, string $feed, int $congress): ?string
{
    $rows = laceRows_SELECT($DBConnect, "SELECT checkedThrough FROM sync_state
        WHERE feed=? AND congress=? AND scopeNo=0", [$feed, $congress]);
    return $rows[0]["checkedThrough"] ?? null;
}

/** Lists the identities allowed in a dated service import. */
function congressMemberIds_SELECT(mysqli $DBConnect, int $congress): array
{
    $rows = laceRows_SELECT($DBConnect, "SELECT bioguideId FROM congress_members WHERE congress=?", [$congress]);
    return array_column($rows, "bioguideId");
}

/**
 * Selects a bounded batch of member, bill and House roll keys awaiting detail.
 *
 * @return array{members:array,bills:array,rolls:array} Keys waiting for detail.
 */
function pendingCongressRecords_SELECT(mysqli $DBConnect, int $congress, ?int $houseSession, int $budget): array
{
    $members = laceRows_SELECT($DBConnect, "SELECT l.bioguideId FROM lace_member_profiles l
        JOIN congress_members m USING (bioguideId)
        WHERE m.congress=? AND l.detailPending=1 ORDER BY l.bioguideId LIMIT ?", [$congress, $budget]);
    $bills = laceRows_SELECT($DBConnect, "SELECT congress,type,number FROM bills
        WHERE congress=? AND (detailPending=1 OR relatedStatus<>'current')
        ORDER BY (introducedDate IS NULL) DESC,(relatedStatus='stale') DESC,type,number LIMIT ?", [$congress, $budget]);
    $rolls = [];
    if ($houseSession !== null) {
        $rolls = laceRows_SELECT($DBConnect, "SELECT rollCallNumber FROM roll_calls
            WHERE congress=? AND sessionNumber=? AND rosterStatus<>'complete'
            ORDER BY rollCallNumber DESC LIMIT ?", [$congress, $houseSession, $budget]);
    }
    return ["members" => array_column($members, "bioguideId"), "bills" => $bills,
        "rolls" => array_column($rolls, "rollCallNumber")];
}

/** Looks up the Congress in the stored chamber calendar for one date. */
function cachedCongressNumber_SELECT(mysqli $DBConnect, string $today): int
{
    $rows = laceRows_SELECT($DBConnect, "SELECT congress FROM congress_sessions
        WHERE startDate<=? AND (endDate IS NULL OR endDate>=?) ORDER BY startDate DESC LIMIT 1", [$today, $today]);
    return positiveInteger($rows[0]["congress"] ?? null, "cached current Congress");
}

/** Creates the site-wide schedule once, preserving any existing row. */
function refreshSchedule_INSERT(mysqli $DBConnect): void
{
    // Concurrent first visits share the same primary key. A repeat preserves all timestamps.
    $DBConnect->query("INSERT INTO refresh_schedule (schedule_id, activated_at, status, next_run_at)
        VALUES (1, UTC_TIMESTAMP(), 'queued', UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE schedule_id = 1");
}

/**
 * Reads the shared schedule and evaluates the due time on the MySQL UTC clock.
 *
 * @return array|null The shared clock row, or null before activation.
 */
function refreshSchedule_SELECT(mysqli $DBConnect): ?array
{
    $rows = laceRows_SELECT($DBConnect, "SELECT status, next_run_at, last_success_at,
        (next_run_at <= UTC_TIMESTAMP()) AS is_due FROM refresh_schedule WHERE schedule_id=1");
    return $rows[0] ?? null;
}

/** Marks the import running and sets the recovery time for an interrupted worker. */
function refreshStarted_UPDATE(mysqli $DBConnect): void
{
    // The lock is held on this connection. If PHP exits, MySQL releases it automatically.
    $DBConnect->query("UPDATE refresh_schedule SET status='running', last_started_at=UTC_TIMESTAMP(),
        next_run_at=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE) WHERE schedule_id=1");
}

/** Records success and sets the next due time twelve hours after the start. */
function refreshSucceeded_UPDATE(mysqli $DBConnect): void
{
    $DBConnect->query("UPDATE refresh_schedule SET status='idle', last_success_at=UTC_TIMESTAMP(),
        next_run_at=DATE_ADD(last_started_at, INTERVAL 12 HOUR) WHERE schedule_id=1");
}

/** Records the failure and allows another attempt after fifteen minutes. */
function refreshFailed_UPDATE(mysqli $DBConnect): void
{
    $DBConnect->query("UPDATE refresh_schedule SET status='failed', last_failure_at=UTC_TIMESTAMP(),
        next_run_at=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE) WHERE schedule_id=1");
}

/** Builds the advisory lock name from the selected MySQL database. */
function importLockName_SELECT(mysqli $DBConnect): string
{
    $database = laceRows_SELECT($DBConnect, "SELECT DATABASE() AS db_name")[0]["db_name"];
    return "lace-" . substr(hash("sha256", $database), 0, 40);
}

/** Requests MySQL import lock without blocking the caller. */
function importLockAcquired_SELECT(mysqli $DBConnect, string $lock): bool
{
    $held = laceRows_SELECT($DBConnect, "SELECT GET_LOCK(?, 0) AS held", [$lock])[0]["held"];
    if ($held === null) {
        throw new RuntimeException("MySQL could not acquire the import lock.");
    }
    return (int) $held === 1;
}

/** Releases the import lock owned by this connection. */
function releaseImportLock(mysqli $DBConnect, string $lock): void
{
    laceRows_SELECT($DBConnect, "SELECT RELEASE_LOCK(?)", [$lock]);
}

if (realpath($_SERVER["SCRIPT_FILENAME"] ?? "") === __FILE__) {
    servePublicCongress_SELECT();
}
