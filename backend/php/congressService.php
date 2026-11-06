<?php
declare(strict_types=1);

const CONGRESS_API_BASE_URL = "https://api.congress.gov/v3";

/**
 * Loads simple KEY=value entries from a local .env file.
 *
 * @param string $filePath Path to the .env file.
 * @return void
 */
function loadEnvironmentFile(string $filePath): void
{
    if (!is_readable($filePath)) {
        return;
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === "" || $line[0] === "#") {
            continue;
        }

        $separator = strpos($line, "=");

        if ($separator === false) {
            continue;
        }

        $name = trim(substr($line, 0, $separator));
        $value = trim(substr($line, $separator + 1));

        if (
            strlen($value) >= 2
            && (
                ($value[0] === '"' && substr($value, -1) === '"')
                || ($value[0] === "'" && substr($value, -1) === "'")
            )
        ) {
            $value = substr($value, 1, -1);
        }

        if ($name !== "" && getenv($name) === false) {
            putenv($name . "=" . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

loadEnvironmentFile(__DIR__ . "/../.env");


/**
 * Reads the Congress.gov API key from the server environment.
 *
 * @return string The configured API key.
 * @throws RuntimeException If the key is missing.
 */
function getCongressApiKey(): string
{
    $apiKey = getenv("CONGRESS_API_KEY");

    if ($apiKey === false || trim($apiKey) === "") {
        throw new RuntimeException(
            "The Congress.gov API key is not configured on the server."
        );
    }

    return trim($apiKey);
}

/**
 * Makes a GET request to the Congress.gov API.
 *
 * @param string $endpoint API endpoint path.
 * @param array<string, scalar> $params Query parameters.
 * @return array<string, mixed> Decoded JSON response.
 * @throws RuntimeException If the request fails.
 */
function requestCongressAPI(string $endpoint, array $params = []): array
{
    if (!function_exists("curl_init")) {
        throw new RuntimeException("The PHP cURL extension is required.");
    }

    $queryParams = array_merge(
        [
            "format" => "json",
            "api_key" => getCongressApiKey(),
        ],
        $params
    );

    $requestUrl = CONGRESS_API_BASE_URL
        . $endpoint
        . "?"
        . http_build_query($queryParams, "", "&", PHP_QUERY_RFC3986);

    $curl = curl_init($requestUrl);

    if ($curl === false) {
        throw new RuntimeException("Unable to initialize the Congress API request.");
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => ["Accept: application/json"],
    ]);

    $responseBody = curl_exec($curl);

    if ($responseBody === false) {
        $errorMessage = curl_error($curl);
        curl_close($curl);

        throw new RuntimeException(
            "Congress API connection failed: " . $errorMessage,
            502
        );
    }

    $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($statusCode < 200 || $statusCode >= 300) {
        $messages = [
            400 => "Bad Request. Check the request parameters.",
            401 => "Unauthorized. Check the API key configuration.",
            403 => "Forbidden.",
            404 => "Not Found.",
            429 => "Too Many Requests.",
        ];

        $message = $messages[$statusCode]
            ?? ($statusCode >= 500
                ? "Congress.gov is experiencing a server error."
                : "The Congress API request failed.");

        throw new RuntimeException(
            "Congress API request failed with status "
                . $statusCode
                . ". "
                . $message,
            $statusCode
        );
    }

    try {
        $data = json_decode(
            $responseBody,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException $error) {
        throw new RuntimeException(
            "Congress.gov returned invalid JSON.",
            502,
            $error
        );
    }

    if (!is_array($data)) {
        throw new RuntimeException(
            "Congress.gov returned an unexpected response format.",
            502
        );
    }

    return $data;
}

/**
 * Gets the current Congress number.
 *
 * @return int Current Congress number.
 */
function getCurrentCongress(): int
{
    static $currentCongress = null;

    if ($currentCongress !== null) {
        return $currentCongress;
    }

    $data = requestCongressAPI("/congress/current");
    $congress = $data["congresses"][0] ?? $data["congress"] ?? null;
    $number = $congress["number"] ?? null;

    if (!is_numeric($number)) {
        throw new RuntimeException(
            "The current Congress number was not returned."
        );
    }

    $currentCongress = (int) $number;

    return $currentCongress;
}

/**
 * Gets the most recent bills from the current Congress.
 *
 * @param int $limit Maximum number of bills to retrieve.
 * @return array<int, array<string, mixed>> Recent bills.
 */
function getRecentBills(int $limit = 5): array
{
    $limit = max(1, min(250, $limit));
    $congress = getCurrentCongress();
    $data = requestCongressAPI("/bill/" . $congress, [
        "limit" => $limit,
    ]);

    return is_array($data["bills"] ?? null)
        ? $data["bills"]
        : [];
}

/**
 * Gets current members of Congress.
 *
 * @param int $limit Maximum number of members to retrieve.
 * @return array<int, array<string, mixed>> Current members.
 */
function getCurrentMembers(int $limit = 5): array
{
    $limit = max(1, min(250, $limit));
    $congress = getCurrentCongress();

    $data = requestCongressAPI("/member/congress/" . $congress, [
        "currentMember" => "true",
        "limit" => $limit,
    ]);

    return is_array($data["members"] ?? null)
        ? $data["members"]
        : [];
}

/**
 * Gets all current members of Congress, following API pagination.
 *
 * @return array<int, array<string, mixed>> All current members.
 */
function getAllCurrentMembers(): array
{
    $congress = getCurrentCongress();
    $members = [];
    $offset = 0;
    $limit = 250;

    while (true) {
        $data = requestCongressAPI(
            "/member/congress/" . $congress,
            [
                "currentMember" => "true",
                "limit" => $limit,
                "offset" => $offset,
            ]
        );

        $pageMembers = is_array($data["members"] ?? null)
            ? $data["members"]
            : [];

        foreach ($pageMembers as $member) {
            $members[] = $member;
        }

        if (empty($data["pagination"]["next"]) || count($pageMembers) === 0) {
            break;
        }

        $offset += count($pageMembers);
    }

    return $members;
}

/**
 * Validates and returns a Bioguide ID.
 *
 * @param string $bioguideId Legislator Bioguide ID.
 * @return string Validated Bioguide ID.
 * @throws InvalidArgumentException If the ID format is invalid.
 */
function validateBioguideId(string $bioguideId): string
{
    if (!preg_match("/^[A-Za-z][0-9]{6}$/", $bioguideId)) {
        throw new InvalidArgumentException("Invalid Bioguide ID.");
    }

    return $bioguideId;
}

/**
 * Gets detailed information for a member of Congress.
 *
 * @param string $bioguideId Member Bioguide ID.
 * @return array<string, mixed> Member details.
 */
function getMemberDetails(string $bioguideId): array
{
    $bioguideId = validateBioguideId($bioguideId);
    $data = requestCongressAPI("/member/" . rawurlencode($bioguideId));
    $member = $data["member"] ?? null;

    if (!is_array($member)) {
        throw new RuntimeException("Member details were not returned.", 502);
    }

    return $member;
}

/**
 * Gets legislation sponsored by a member.
 *
 * @param string $id Member Bioguide ID.
 * @param int $limit Maximum number of items to retrieve.
 * @param int $offset Starting position for pagination.
 * @return array{items: array, count: int} Sponsored legislation and total count.
 */
function getSponsoredLegislation(
    string $id,
    int $limit = 3,
    int $offset = 0
): array {
    $id = validateBioguideId($id);
    $limit = max(1, min(250, $limit));
    $offset = max(0, $offset);

    $data = requestCongressAPI(
        "/member/" . rawurlencode($id) . "/sponsored-legislation",
        [
            "limit" => $limit,
            "offset" => $offset,
        ]
    );

    return [
        "items" => is_array($data["sponsoredLegislation"] ?? null)
            ? $data["sponsoredLegislation"]
            : [],
        "count" => (int) ($data["pagination"]["count"] ?? 0),
    ];
}

/**
 * Gets legislation cosponsored by a member.
 *
 * @param string $id Member Bioguide ID.
 * @param int $limit Maximum number of items to retrieve.
 * @param int $offset Starting position for pagination.
 * @return array{items: array, count: int} Cosponsored legislation and total count.
 */
function getCosponsoredLegislation(
    string $id,
    int $limit = 3,
    int $offset = 0
): array {
    $id = validateBioguideId($id);
    $limit = max(1, min(250, $limit));
    $offset = max(0, $offset);

    $data = requestCongressAPI(
        "/member/" . rawurlencode($id) . "/cosponsored-legislation",
        [
            "limit" => $limit,
            "offset" => $offset,
        ]
    );

    return [
        "items" => is_array($data["cosponsoredLegislation"] ?? null)
            ? $data["cosponsoredLegislation"]
            : [],
        "count" => (int) ($data["pagination"]["count"] ?? 0),
    ];
}

/**
 * Gets House roll call votes for a Congress and session.
 *
 * @param int $congress Congress number.
 * @param int $session Session number.
 * @param int $limit Maximum number of votes per API page.
 * @return array<int, array<string, mixed>> House roll call votes.
 */
function getHouseVotes(int $congress, int $session, int $limit = 250): array
{
    if ($congress < 1 || $session < 1) {
        throw new InvalidArgumentException(
            "Congress and session must be positive numbers."
        );
    }

    $limit = max(1, min(250, $limit));
    $votes = [];
    $offset = 0;

    while (true) {
        $data = requestCongressAPI(
            "/house-vote/" . $congress . "/" . $session,
            [
                "limit" => $limit,
                "offset" => $offset,
            ]
        );

        $pageVotes = is_array($data["houseRollCallVotes"] ?? null)
            ? $data["houseRollCallVotes"]
            : [];

        foreach ($pageVotes as $vote) {
            $votes[] = $vote;
        }

        if (empty($data["pagination"]["next"]) || count($pageVotes) === 0) {
            break;
        }

        $offset += count($pageVotes);
    }

    return $votes;
}

/**
 * Gets member vote records for one House roll call vote.
 *
 * @param int $congress Congress number.
 * @param int $session Session number.
 * @param int $rollCall Roll call number.
 * @return array<int, array<string, mixed>> Member vote records.
 */
function getHouseVoteMembers(
    int $congress,
    int $session,
    int $rollCall
): array {
    if ($congress < 1 || $session < 1 || $rollCall < 1) {
        throw new InvalidArgumentException(
            "Congress, session, and roll call must be positive numbers."
        );
    }

    $endpoint =
        "/house-vote/"
        . $congress
        . "/"
        . $session
        . "/"
        . $rollCall
        . "/members";

    $members = [];
    $limit = 250;
    $offset = 0;

    while (true) {
        $data = requestCongressAPI($endpoint, [
            "limit" => $limit,
            "offset" => $offset,
        ]);

        $voteData = $data["houseRollCallVoteMemberVotes"] ?? $data;
        $results = $voteData["results"]
            ?? $voteData["members"]
            ?? $voteData;

        if (isset($results["item"]) && is_array($results["item"])) {
            $pageResults = $results["item"];
        } elseif (
            is_array($results)
            && (
                $results === []
                || array_keys($results) === range(0, count($results) - 1)
            )
        ) {
            $pageResults = $results;
        } else {
            $pageResults = [];
        }

        foreach ($pageResults as $member) {
            if (!is_array($member)) {
                continue;
            }

            $member["bioguideId"] =
                $member["bioguideId"]
                ?? $member["bioguideID"]
                ?? $member["bioguide_id"]
                ?? null;

            $members[] = $member;
        }

        if (empty($data["pagination"]["next"]) || count($pageResults) === 0) {
            break;
        }

        $offset += count($pageResults);
    }

    return $members;
}

/**
 * Sends a JSON response from the PHP service endpoint.
 *
 * @param array<string, mixed> $payload Response body.
 * @param int $status HTTP status code.
 * @return void
 */
function sendCongressServiceResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    header("Content-Type: application/json; charset=utf-8");
    header("Cache-Control: no-store");

    try {
        echo json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    } catch (JsonException $error) {
        http_response_code(500);
        echo '{"error":"Unable to encode the response as JSON."}';
    }
}

/**
 * Reads an integer query parameter or returns its default value.
 *
 * @param string $name Query parameter name.
 * @param int $default Default value.
 * @return int Query parameter value.
 * @throws InvalidArgumentException If the value is not an integer.
 */
function getIntegerQueryParameter(string $name, int $default): int
{
    if (!isset($_GET[$name])) {
        return $default;
    }

    $value = filter_var($_GET[$name], FILTER_VALIDATE_INT);

    if ($value === false) {
        throw new InvalidArgumentException(
            "The " . $name . " parameter must be an integer."
        );
    }

    return $value;
}

/**
 * Routes allowlisted browser requests to the PHP service functions.
 *
 * @return void
 */
function handleCongressServiceRequest(): void
{
    header("Content-Type: application/json; charset=utf-8");
    header("Cache-Control: no-store");

    if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "GET") {
        header("Allow: GET");
        sendCongressServiceResponse(
            ["error" => "Only GET requests are supported."],
            405
        );
        return;
    }

    try {
        $action = (string) ($_GET["action"] ?? "");

        switch ($action) {
            case "getCurrentCongress":
                $result = getCurrentCongress();
                break;

            case "getRecentBills":
                $result = getRecentBills(
                    getIntegerQueryParameter("limit", 5)
                );
                break;

            case "getCurrentMembers":
                $result = getCurrentMembers(
                    getIntegerQueryParameter("limit", 5)
                );
                break;

            case "getAllCurrentMembers":
                $result = getAllCurrentMembers();
                break;

            case "getMemberDetails":
                $result = getMemberDetails(
                    (string) ($_GET["bioguideId"] ?? "")
                );
                break;

            case "getSponsoredLegislation":
                $result = getSponsoredLegislation(
                    (string) ($_GET["id"] ?? ""),
                    getIntegerQueryParameter("limit", 3),
                    getIntegerQueryParameter("offset", 0)
                );
                break;

            case "getCosponsoredLegislation":
                $result = getCosponsoredLegislation(
                    (string) ($_GET["id"] ?? ""),
                    getIntegerQueryParameter("limit", 3),
                    getIntegerQueryParameter("offset", 0)
                );
                break;

            case "getHouseVotes":
                $result = getHouseVotes(
                    getIntegerQueryParameter("congress", 0),
                    getIntegerQueryParameter("session", 0),
                    getIntegerQueryParameter("limit", 250)
                );
                break;

            case "getHouseVoteMembers":
                $result = getHouseVoteMembers(
                    getIntegerQueryParameter("congress", 0),
                    getIntegerQueryParameter("session", 0),
                    getIntegerQueryParameter("rollCall", 0)
                );
                break;

            default:
                throw new InvalidArgumentException(
                    "Unknown Congress service action."
                );
        }

        sendCongressServiceResponse(["data" => $result]);
    } catch (Throwable $error) {
        if ($error instanceof InvalidArgumentException) {
            $status = 400;
        } else {
            $status = (int) $error->getCode();

            if ($status < 400 || $status > 599) {
                $status = 500;
            }
        }

        sendCongressServiceResponse(
            ["error" => $error->getMessage()],
            $status
        );
    }
}

// Runs the HTTP router only when this PHP file is requested directly.
$scriptFilename = $_SERVER["SCRIPT_FILENAME"] ?? "";
if (
    $scriptFilename !== ""
    && realpath($scriptFilename) === __FILE__
) {
    handleCongressServiceRequest();
}