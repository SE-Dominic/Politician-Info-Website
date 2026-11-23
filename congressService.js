import { readLaceJSON } from "./index.js";

/**
 * Provides functions to fetch current Congress data, members, bills, and vote records.
 * 
 * @param {string} action The PHP API action to call.
 * @param {Object} [params] Optional query parameters for the request.  
 * @returns {Promise<any>} The JSON-parsed response from the PHP API.
 */
const PHP_API_URL = new URL(
    "./laceDatabase.php",
    import.meta.url,
);

/**
 * Sends a request to the PHP API with the specified action and parameters.
 * 
 * @param {string} action The PHP API action to call.
 * @param {Object} [params] Optional query parameters for the request.
 * @returns {Promise<any>} The JSON-parsed response from the PHP API.
 */
const requestPHPService = async (action, params = {}) => {
    const requestURL = new URL(PHP_API_URL);
    requestURL.search = new URLSearchParams({
        action,
        ...params,
    });

    return readLaceJSON(requestURL);
};

/**
 * Fetches the current scope from the PHP API.
 * 
 * @returns {Promise<any>} The JSON-parsed response from the PHP API.
 */
export const getCurrentScope = () => requestPHPService("currentScope_SELECT");

/**
 * Fetches the current Congress from the PHP API.
 * 
 * @returns {Promise<any>} The JSON-parsed response from the PHP API.
 */
export const getCurrentCongress = () =>
    requestPHPService("currentCongress_SELECT");

/**
 * Fetches the recent bills from the PHP API.
 * 
 * @param {number} [limit=5] The maximum number of recent bills to fetch.
 * @returns {Promise<any>} The JSON-parsed response from the PHP API.
 */
export const getRecentBills = (limit = 5) =>
    requestPHPService("recentBills_SELECT", { limit: String(limit) });

/**
 * Fetches the current members from the PHP API.
 * 
 * @param {number} [limit=5] The maximum number of current members to fetch.
 * @returns {Promise<any>} The JSON-parsed response from the PHP API.
 */
export const getCurrentMembers = (limit = 5) =>
    requestPHPService("currentMembers_SELECT", { limit: String(limit) });

/**
 * Fetches all current members from the PHP API.
 * 
 * @returns {Promise<any>} The JSON-parsed response from the PHP API.
 */
export const getAllCurrentMembers = () =>
    requestPHPService("allCurrentMembers_SELECT");

/**
 * Fetches the details of a specific member from the PHP API.
 * 
 * @param {string} bioguideId The Bioguide ID of the member.
 * @returns {Promise<any>} The JSON-parsed response from the PHP API.
 */
export const getMemberDetails = (bioguideId) =>
    requestPHPService("legislatorProfile_SELECT", { bioguideId });

/**
 * Fetches the sponsored legislation of a specific member from the PHP API.
 * 
 * @param {string} id The Bioguide ID of the member.
 * @param {number} [limit=3] The maximum number of sponsored bills to fetch.
 * @param {number} [offset=0] The offset for pagination.
 * @returns {Promise<any>} The JSON-parsed response from the PHP API.
 */
export const getSponsoredLegislation = (
    id,
    limit = 3,
    offset = 0,
) =>
    requestPHPService("sponsoredBills_SELECT", {
        id,
        limit: String(limit),
        offset: String(offset),
    });

/**
 * Fetches the cosponsored legislation of a specific member from the PHP API.
 * 
 * @param {string} id The Bioguide ID of the member.
 * @param {number} [limit=3] The maximum number of cosponsored bills to fetch.
 * @param {number} [offset=0] The offset for pagination.
 * @returns {Promise<any>} The JSON-parsed response from the PHP API.
 */
export const getCosponsoredLegislation = (
    id,
    limit = 3,
    offset = 0,
) =>
    requestPHPService("cosponsoredBills_SELECT", {
        id,
        limit: String(limit),
        offset: String(offset),
    });

/**
 * Fetches the House votes from the PHP API.
 * 
 * @param {number} congress The congress number.
 * @param {number} session The session number.
 * @param {number} [limit=250] The maximum number of votes to fetch.
 * @returns {Promise<any>} The JSON-parsed response from the PHP API.
 */
export const getHouseVotes = (
    congress,
    session,
    limit = 250,
) =>
    requestPHPService("houseRollCalls_SELECT", {
        congress: String(congress),
        session: String(session),
        limit: String(limit),
    });
/**
 * Fetches the members who voted on a specific House roll call from the PHP API.
 * 
 * @param {number} congress The congress number.
 * @param {number} session The session number.
 * @param {number} rollCall The roll call number.
 * @returns {Promise<any>} The JSON-parsed response from the PHP API.
 */
export const getHouseVoteMembers = (
    congress,
    session,
    rollCall,
) =>
    requestPHPService("houseRollBallots_SELECT", {
        congress: String(congress),
        session: String(session),
        rollCall: String(rollCall),
    });
/**
 * Fetches local vote statistics for specific members from the PHP API.
 * 
 * @param {number} congress The congress number.
 * @param {number} session The session number.
 * @param {string[]} ids The Bioguide IDs of the members.
 * @param {boolean} [includeRecords=false] Whether to include detailed vote records.
 * @returns {Promise<any>} The JSON-parsed response from the PHP API.
 */
export const getLocalVoteStats = async (congress, session, ids, includeRecords = false) => {
    const url = new URL("./voteStats.php", import.meta.url);
    url.search = new URLSearchParams({ congress, session, members: ids.join(",") });
    if (includeRecords) url.searchParams.set("include", "records");
    const response = await fetch(url);
    const result = await response.json();
    if (!response.ok) throw new Error(result.error || "Unable to load vote data.");
    return result.data;
};
