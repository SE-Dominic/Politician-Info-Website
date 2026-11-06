import {
    getAllCurrentMembers,
    getMemberDetails,
    getCurrentCongress,
    getSponsoredLegislation,
    getCosponsoredLegislation,
    getHouseVotes,
    getHouseVoteMembers,
} from "./congressService.js";
import { US_CHAMBERS, US_STATES, US_POLITICAL_PARTIES } from "./config.js";

// DOM2: select elements by ID, and attach listeners using addEventListener().
const $ = (id) => document.getElementById(id);
const searchInput = $("member-search");
const stateFilter = $("state-filter");
const districtFilter = $("district-filter");
const chamberFilter = $("chamber-filter");
const partyFilter = $("party-filter");
let members = [];
let activeId = "";
const PAGE_SIZE = 3;
const billPages = { sponsored: 0, cosponsored: 0 };

const getTerms = (member) => member.terms?.item || member.terms || [];
const getChamber = (member) => {
    const terms = getTerms(member);
    return terms.length ? terms[terms.length - 1].chamber : "";
};
const getParty = (member) => member.partyName || member.party || "Unknown";
const getName = (member) =>
    member.directOrderName || member.name || "Unknown member";
const getDistrict = (member) => member.district ?? null;

/** Makes a dropdown option using DOM methods (no HTML strings). */
const addOption = (select, value, label) => {
    const option = document.createElement("option");
    option.value = value;
    option.textContent = label;
    select.appendChild(option);
};

/** Loads state, district and party options from the real directory. */
const populateFilters = () => {
    // Populates the state filters with predefined options.
    for (const state of US_STATES) {
        addOption(stateFilter, state, state);
    }
    // Populates the political party filters with predefined options.
    for (const party of US_POLITICAL_PARTIES) {
        addOption(partyFilter, party, party);
    }
    // Populates the chamber filters with predefined options.
    for (const chamber of US_CHAMBERS) {
        addOption(chamberFilter, chamber.value, chamber.label);
    }

    updateDistricts();
};

const updateDistricts = () => {
    districtFilter.replaceChildren();
    addOption(districtFilter, "", "All Districts");
    const districts = new Set();
    for (const member of members) {
        if (stateFilter.value && member.state !== stateFilter.value) continue;
        if (
            getChamber(member) === "House of Representatives" &&
            getDistrict(member) !== null
        )
            districts.add(Number(member.district));
    }
    for (const district of [...districts].sort((a, b) => a - b)) {
        addOption(
            districtFilter,
            String(district),
            district === 0 ? "All Districts" : `District ${district}`,
        );
    }
    districtFilter.disabled = chamberFilter.value === "Senate";
};

/** Live client-side filtering across every loaded current member. */
const filterMembers = () => {
    const query = searchInput.value.trim().toLowerCase();
    const matches = [];
    for (const member of members) {
        const name = `${getName(member)} ${member.name || ""}`.toLowerCase();
        if (query && !name.includes(query)) continue;
        if (stateFilter.value && member.state !== stateFilter.value) continue;
        if (chamberFilter.value && getChamber(member) !== chamberFilter.value)
            continue;
        if (partyFilter.value && getParty(member) !== partyFilter.value) continue;
        if (
            districtFilter.value &&
            String(member.district) !== districtFilter.value
        )
            continue;
        matches.push(member);
    }
    $("member-results").replaceChildren();
    for (let i = 0; i < Math.min(matches.length, 12); i++) {
        const member = matches[i];
        const button = document.createElement("button");
        button.type = "button";
        button.className = "member-result";
        const strong = document.createElement("strong");
        strong.textContent = getName(member);
        const small = document.createElement("small");
        small.textContent = `${getParty(member)} · ${member.state} · ${getChamber(member)}`;
        button.append(strong, small);
        button.addEventListener("click", () => selectMember(member.bioguideId));
        $("member-results").appendChild(button);
    }
    $("search-status").textContent =
        `${matches.length} matching legislators` +
        (matches.length > 12 ? " (showing first 12; narrow your filters)" : "");
};

/** Displays a member profile using the detail API response. */
const renderProfile = (member) => {
    const chamber = getChamber(member);
    const terms = getTerms(member);
    const years = terms
        .map((term) => Number(term.startYear))
        .filter(Number.isFinite);
    const firstYear = years.length ? Math.min(...years) : null;
    const current = String(member.currentMember).toLowerCase() === "true";
    const title =
        chamber === "Senate"
            ? "Sen. "
            : chamber === "House of Representatives"
                ? "Rep. "
                : "";
    $("member-name").textContent = `${title}${getName(member)}`;
    $("member-heading").textContent = `${chamber}  |  ${getParty(member)}`;
    $("member-state").textContent = member.state || "Unavailable";
    $("member-district").textContent =
        chamber === "Senate"
            ? "N/A (Statewide)"
            : member.district == null
                ? "Unavailable"
                : Number(member.district) === 0
                    ? "At-large"
                    : String(member.district);
    $("member-years").textContent = firstYear
        ? `${firstYear} – ${current ? "Present" : "Last recorded term"}`
        : "Unavailable";
    const photo = $("member-photo");
    photo.hidden = true;
    $("photo-placeholder").hidden = false;
    if (member.depiction?.imageUrl?.startsWith("https://")) {
        photo.alt = `Portrait of ${getName(member)}`;
        photo.src = member.depiction.imageUrl;
        photo.hidden = false;
        $("photo-placeholder").hidden = true;
    }
    $("compare-link").href =
        `compare.html?member=${encodeURIComponent(activeId)}`;
    $("compare-link").removeAttribute("aria-disabled");
    $("follow-button").disabled = false;
    updateFollow();
};

/** Prototype follows: browser storage only, not the user database. */
const followedIds = () => {
    try {
        const ids = JSON.parse(localStorage.getItem("laceFollows") || "[]");
        return Array.isArray(ids) ? ids : [];
    } catch {
        return [];
    }
};
const updateFollow = () => {
    const following = followedIds().includes(activeId);
    $("follow-button").textContent = following
        ? "Unfollow Legislator"
        : "Follow Legislator";
    $("follow-button").setAttribute("aria-pressed", String(following));
};
const toggleFollow = () => {
    const ids = followedIds();
    const updated = ids.includes(activeId)
        ? ids.filter((id) => id !== activeId)
        : [...ids, activeId];
    try {
        localStorage.setItem("laceFollows", JSON.stringify(updated));
        updateFollow();
        $("follow-status").textContent = "Saved on this device only.";
    } catch {
        $("follow-status").textContent = "Unable to save follow preference.";
    }
};

/** Creates text-only table rows to prevent HTML injection from API data. */
const showMessage = (body, message) => {
    body.replaceChildren();
    const row = body.insertRow();
    const cell = row.insertCell();
    cell.colSpan = 4;
    cell.textContent = message;
};
const addCell = (row, text) => {
    const cell = row.insertCell();
    cell.textContent = text;
    return cell;
};

const billLabel = (bill) => {
    const types = {
        HR: "H.R.",
        S: "S.",
        HJRES: "H.J.Res.",
        SJRES: "S.J.Res.",
        HRES: "H.Res.",
        SRES: "S.Res.",
    };
    return `${types[bill.type] || bill.type || "Bill"} ${bill.number || "?"}`;
};
const addBillRow = (body, bill) => {
    const row = body.insertRow();
    addCell(row, billLabel(bill));
    addCell(row, bill.latestTitle || bill.title || "Untitled legislation");
    addCell(row, bill.latestAction?.text || "Latest action unavailable");
    const link = document.createElement("a");
    link.className = "button button-secondary";
    link.textContent = "View Bill";
    link.href = `bill-details.html?${new URLSearchParams({
        congress: String(bill.congress),
        type: String(bill.type).toLowerCase(),
        number: String(bill.number),
    })}`;
    row.insertCell().appendChild(link);
};

/** Loads three bill rows at a time, with pagination on View More. */
const loadBills = async (kind, id) => {
    const body = $(`${kind}-body`);
    const button = $(`more-${kind}`);
    const count = $(`${kind}-count`);
    const offset = billPages[kind];
    const request =
        kind === "sponsored" ? getSponsoredLegislation : getCosponsoredLegislation;
    button.hidden = true;
    button.disabled = true;
    if (offset === 0) showMessage(body, "Loading legislation...");
    try {
        const data = await request(id, PAGE_SIZE, offset);
        if (id !== activeId) return;
        if (offset === 0) body.replaceChildren();
        for (const bill of data.items) addBillRow(body, bill);
        billPages[kind] += data.items.length;
        count.textContent = `${data.count} total (all Congresses)`;
        if (!billPages[kind]) showMessage(body, "No legislation found.");
        button.hidden = !data.items.length || billPages[kind] >= data.count;
    } catch (error) {
        if (id === activeId)
            showMessage(body, `Unable to load bills: ${error.message}`);
    } finally {
        button.disabled = false;
    }
};

/** Calculates statistics from verified voteCast values using a for-loop. */
const calculateParticipation = (votes) => {
    let participated = 0;
    let notVoting = 0;
    for (let i = 0; i < votes.length; i++) {
        const vote = votes[i].voteCast;
        if (vote === "Not Voting") notVoting++;
        else if (["Aye", "Yea", "Nay", "Present"].includes(vote)) participated++;
    }
    const total = participated + notVoting;
    return { participated, notVoting, total };
};

/**
 * Loads available House roll calls for the current session and calculates
 * participation from the selected legislator's recorded votes.
 */
const loadVotes = async (id, chamber) => {
    const body = $("votes-body");

    showMessage(body, "Loading current-session House votes...");
    $("participated-count").textContent = "—";
    $("not-voting-count").textContent = "—";
    $("participated-percent").textContent = "—";
    $("not-voting-percent").textContent = "—";

    if (chamber !== "House of Representatives") {
        showMessage(
            body,
            "Senate roll-call data requires a separate Senate data source.",
        );
        $("participation-note").textContent =
            "Senate vote integration is not yet available.";
        return;
    }

    try {
        const congress = await getCurrentCongress();

        // Congress session 1 is in odd-numbered years; session 2 is in even-numbered years.
        const session =
            new Date().getUTCFullYear() % 2 === 0 ? 2 : 1;

        const sessionVotes = await getHouseVotes(congress, session);

        if (id !== activeId) return;

        const records = [];
        let failed = 0;

        for (let i = 0; i < sessionVotes.length; i++) {
            const vote = sessionVotes[i];

            $("participation-note").textContent =
                `Loading vote details: ${i + 1} of ${sessionVotes.length}...`;

            try {
                const results = await getHouseVoteMembers(
                    congress,
                    session,
                    vote.rollCallNumber,
                );

                if (id !== activeId) return;

                const match = results.find(
                    (person) => person.bioguideId === id,
                );

                if (match) {
                    records.push({
                        ...vote,
                        voteCast: match.voteCast,
                    });
                }
            } catch {
                failed++;
            }
        }

        if (id !== activeId) return;

        body.replaceChildren();

        for (let i = 0; i < records.length; i++) {
            const vote = records[i];
            const row = body.insertRow();

            addCell(
                row,
                vote.startDate
                    ? new Date(vote.startDate).toLocaleDateString()
                    : "—",
            );

            addCell(
                row,
                vote.legislationType && vote.legislationNumber
                    ? billLabel({
                        type: vote.legislationType,
                        number: vote.legislationNumber,
                    })
                    : `House roll ${vote.rollCallNumber}`,
            );

            addCell(
                row,
                vote.voteQuestion ||
                    vote.voteType ||
                    "Recorded House vote",
            );

            addCell(row, vote.voteCast || "Unavailable");
        }

        if (records.length === 0) {
            showMessage(
                body,
                "No matching votes were found in the available session records.",
            );
        }

        const stats = calculateParticipation(records);

        if (stats.total > 0) {
            $("participated-count").textContent =
                String(stats.participated);
            $("not-voting-count").textContent =
                String(stats.notVoting);
            $("participated-percent").textContent =
                `${Math.round(
                    (stats.participated / stats.total) * 100,
                )}%`;
            $("not-voting-percent").textContent =
                `${Math.round(
                    (stats.notVoting / stats.total) * 100,
                )}%`;
        }

        $("participation-note").textContent =
            `Based on ${stats.total} matched roll-call votes for ` +
            `Congress ${congress}, session ${session}. ` +
            `${failed ? `${failed} vote requests failed. ` : ""}` +
            "This is roll-call participation, not a measure of all floor attendance.";
    } catch (error) {
        if (id === activeId) {
            showMessage(body, `Unable to load votes: ${error.message}`);
            $("participation-note").textContent =
                "Participation data unavailable.";
        }
    }
};

/** Loads one profile; ID checks prevent older requests overwriting newer choices. */
const selectMember = async (id) => {
    activeId = id;
    $("member-results").replaceChildren();
    $("member-name").textContent = "Loading legislator...";
    $("profile-status").textContent = "Retrieving Congress.gov data...";
    $("follow-button").disabled = true;
    $("compare-link").setAttribute("aria-disabled", "true");
    $("follow-status").textContent = "";
    billPages.sponsored = 0;
    billPages.cosponsored = 0;
    showMessage($("sponsored-body"), "Loading...");
    showMessage($("cosponsored-body"), "Loading...");
    history.replaceState(
        null,
        "",
        `legislator.html?id=${encodeURIComponent(id)}`,
    );
    try {
        const member = await getMemberDetails(id);
        if (id !== activeId) return;
        renderProfile(member);
        $("profile-status").textContent = "";
        loadBills("sponsored", id);
        loadBills("cosponsored", id);
        loadVotes(id, getChamber(member));
    } catch (error) {
        if (id === activeId)
            $("profile-status").textContent = `Profile unavailable: ${error.message}`;
    }
};

/** DOM2 event listeners, separate from API and rendering functions. */
const addEventListeners = () => {
    $("member-search-form").addEventListener("submit", (event) => {
        event.preventDefault();
        filterMembers();
    });
    searchInput.addEventListener("input", filterMembers);
    $("header-search-form").addEventListener("submit", (event) => {
        event.preventDefault();
        searchInput.value = $("header-search").value;
        filterMembers();
        searchInput.focus();
    });
    stateFilter.addEventListener("change", () => {
        updateDistricts();
        filterMembers();
    });
    chamberFilter.addEventListener("change", () => {
        updateDistricts();
        filterMembers();
    });
    districtFilter.addEventListener("change", filterMembers);
    partyFilter.addEventListener("change", filterMembers);
    $("follow-button").addEventListener("click", toggleFollow);
    $("more-sponsored").addEventListener("click", () =>
        loadBills("sponsored", activeId),
    );
    $("more-cosponsored").addEventListener("click", () =>
        loadBills("cosponsored", activeId),
    );
    $("member-photo").addEventListener("error", () => {
        $("member-photo").hidden = true;
        $("photo-placeholder").hidden = false;
    });
};

const initialize = async () => {
    addEventListeners();
    const id = new URLSearchParams(window.location.search).get("id");
    if (id) selectMember(id);
    try {
        members = await getAllCurrentMembers();
        populateFilters();
        if (!id) filterMembers();
        else
            $("search-status").textContent =
                `${members.length} current legislators available to search.`;
    } catch (error) {
        $("search-status").textContent =
            `Unable to load directory: ${error.message}`;
    }
};
initialize();
