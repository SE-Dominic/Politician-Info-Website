const PHP_API_URL = new URL(
    "../backend/php/congressService.php",
    import.meta.url,
);

const requestPHPService = async (action, params = {}) => {
    const requestURL = new URL(PHP_API_URL);
    requestURL.search = new URLSearchParams({
        action,
        ...params,
    });

    const response = await fetch(requestURL);
    const payload = await response.json();

    if (!response.ok) {
        throw new Error(
            payload.error ||
                `PHP service request failed with status ${response.status}.`,
        );
    }

    return payload.data;
};

export const getCurrentCongress = () =>
    requestPHPService("getCurrentCongress");

export const getRecentBills = (limit = 5) =>
    requestPHPService("getRecentBills", { limit: String(limit) });

export const getCurrentMembers = (limit = 5) =>
    requestPHPService("getCurrentMembers", { limit: String(limit) });

export const getAllCurrentMembers = () =>
    requestPHPService("getAllCurrentMembers");

export const getMemberDetails = (bioguideId) =>
    requestPHPService("getMemberDetails", { bioguideId });

export const getSponsoredLegislation = (
    id,
    limit = 3,
    offset = 0,
) =>
    requestPHPService("getSponsoredLegislation", {
        id,
        limit: String(limit),
        offset: String(offset),
    });

export const getCosponsoredLegislation = (
    id,
    limit = 3,
    offset = 0,
) =>
    requestPHPService("getCosponsoredLegislation", {
        id,
        limit: String(limit),
        offset: String(offset),
    });

export const getHouseVotes = (
    congress,
    session,
    limit = 250,
) =>
    requestPHPService("getHouseVotes", {
        congress: String(congress),
        session: String(session),
        limit: String(limit),
    });

export const getHouseVoteMembers = (
    congress,
    session,
    rollCall,
) =>
    requestPHPService("getHouseVoteMembers", {
        congress: String(congress),
        session: String(session),
        rollCall: String(rollCall),
    });