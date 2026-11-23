import {getCurrentMembers,getRecentBills,getCurrentCongress} from "./congressService.js";
import { US_STATES, US_POLITICAL_PARTIES, US_CHAMBERS } from "./config.js";

/**
 * Populates the state filter dropdown with US states.
 */
const populateStateFilter = () => {
    US_STATES.forEach((state) => {
        const option = document.createElement("option");
        option.value = state;
        option.textContent = state;
        document.getElementById("state-filter").appendChild(option);
    });
};

/**
 * Populates the party filter dropdown with US political parties.
 */
const populatePartyFilter = () => {
    US_POLITICAL_PARTIES.forEach((party) => {
        const option = document.createElement("option");
        option.value = party;
        option.textContent = party;
        document.getElementById("party-filter").appendChild(option);
    });
};

/**
 * Populates the chamber filter dropdown with US chambers.
 */
const populateChamberFilter = () => {
    US_CHAMBERS.forEach((chamber) => {
        const option = document.createElement("option");
        option.value = chamber.value;
        option.textContent = chamber.label;
        document.getElementById("chamber-filter").appendChild(option);
    });
};


const billsContainer = document.getElementById("recent-bills");
const membersContainer = document.getElementById("featured-legislators");
const billsStatus = document.getElementById("bills-status");
const membersStatus = document.getElementById("members-status");

const searchForm = document.getElementById("site-search-form");
const searchInput = document.getElementById("site-search");
const searchStatus = document.getElementById("search-status");

const stateFilter = document.getElementById("state-filter");
const partyFilter = document.getElementById("party-filter");
const chamberFilter = document.getElementById("chamber-filter");
const applyFiltersButton = document.getElementById("apply-filters");
const clearFiltersButton = document.getElementById("clear-filters");

let bills = [];
let members = [];

/**
 * Creates a bill card for the Recent Bills section.
 */
const createBillCard = (bill, congressNumber) => {
    const article = document.createElement("article");
    article.className = "mini-card searchable-card";

    const icon = document.createElement("div");
    icon.className = "mini-card-icon";
    icon.setAttribute("aria-hidden", "true");
    icon.innerHTML = `<img src="assets/bill_icon.png" alt="Bill Icon">`;

    const content = document.createElement("div");

    const title = document.createElement("h3");
    title.textContent = bill.title || "Untitled bill";

    const details = document.createElement("p");
    details.className = "muted";
    details.textContent =
        `${bill.type.toUpperCase()}.${bill.number} · ` +
        `${bill.updateDateIncludingText || bill.introducedDate || "Date unavailable"}`;

    const link = document.createElement("a");
    link.className = "button button-secondary";
    link.textContent = "View Bill";

    const query = new URLSearchParams({
        congress: String(bill.congress || congressNumber),
        type: bill.type,
        number: String(bill.number)
    });

    link.href = `bill-details.html?${query}`;

    article.dataset.search =
        `${bill.title || ""} ${bill.type || ""} ${bill.number || ""}`.toLowerCase();

    content.append(title, details, link);
    article.append(icon, content);

    return article;
};

/**
 * Returns the chamber listed in the member's most recent term.
 */
const getMemberChamber = (member) => {
    const terms = member.terms?.item || [];

    if (terms.length === 0) {
        return "";
    }

    return terms[terms.length - 1].chamber || "";
};

/**
 * Creates a legislator card for the Featured Legislators section.
 */
const createMemberCard = (member) => {
    const article = document.createElement("article");
    article.className = "mini-card searchable-card";

    const icon = document.createElement("div");
    icon.className = "mini-card-icon";
    icon.setAttribute("aria-hidden", "true");

    const photo = document.createElement("img");
const fallbackPhoto = "assets/profile_photo_icon.jpg";
const memberPhoto = member.depiction?.imageUrl;

photo.alt = "";
photo.src = memberPhoto?.startsWith("https://")
    ? memberPhoto
    : fallbackPhoto;

if (memberPhoto?.startsWith("https://")) {
    photo.addEventListener("error", () => {
        photo.src = fallbackPhoto;
    }, { once: true });
}

icon.appendChild(photo);

    const content = document.createElement("div");

    const name = document.createElement("h3");
    name.textContent = member.name || "Member of Congress";

    const details = document.createElement("p");
    details.className = "muted";

    const party = member.partyName || "Party unavailable";
    const state = member.state || "State unavailable";
    const chamber = getMemberChamber(member);

    details.textContent =
        `${party} · ${state}${chamber ? ` · ${chamber}` : ""}`;

    const link = document.createElement("a");
    link.className = "button button-secondary";
    link.textContent = "View Legislator";
    link.href =
        `legislator.html?id=${encodeURIComponent(member.bioguideId || "")}`;

    article.dataset.state = state;
    article.dataset.party = party;
    article.dataset.chamber = chamber;
    article.dataset.search =
        `${member.name || ""} ${party} ${state} ${chamber}`.toLowerCase();

    content.append(name, details, link);
    article.append(icon, content);

    return article;
};

/**
 * Loads bills and clears the loading message when the request succeeds.
 */
const loadBills = async () => {
    billsStatus.textContent = "Loading bills…";

    try {
        const congressNumber = await getCurrentCongress();
        bills = await getRecentBills(5);

        billsContainer.textContent = "";

        bills.forEach((bill) => {
            billsContainer.appendChild(createBillCard(bill, congressNumber));
        });

        billsStatus.textContent =
            bills.length === 0 ? "No bills were returned." : "";

        applySearch();
    } catch (error) {
        billsStatus.textContent = `Unable to load bills: ${error.message}`;
    }
};

/**
 * Loads members and clears the loading message when the request succeeds.
 */
const loadMembers = async () => {
    membersStatus.textContent = "Loading legislators…";

    try {
        members = await getCurrentMembers(5);
        applyMemberFilters();
    } catch (error) {
        membersStatus.textContent =
            `Unable to load legislators: ${error.message}`;
    }
};

/**
 * Applies the selected state, party, and chamber filters.
 */
const applyMemberFilters = () => {
    const filteredMembers = members.filter((member) => {
        const memberChamber = getMemberChamber(member);

        const matchesState =
            !stateFilter.value || member.state === stateFilter.value;
        const matchesParty =
            !partyFilter.value || member.partyName === partyFilter.value;
        const matchesChamber =
            !chamberFilter.value ||
            memberChamber === chamberFilter.value;

        return matchesState && matchesParty && matchesChamber;
    });

    membersContainer.textContent = "";

    filteredMembers.forEach((member) => {
        membersContainer.appendChild(createMemberCard(member));
    });

    membersStatus.textContent =
        filteredMembers.length === 0
            ? "No legislators match those filters."
            : "";

    applySearch();
};

/**
 * Filters visible bill, legislator, and discussion cards by the search text.
 */
const applySearch = () => {
    const searchText = searchInput.value.trim().toLowerCase();
    const cards = document.querySelectorAll(".searchable-card");
    let visibleCards = 0;

    cards.forEach((card) => {
        const cardText = card.dataset.search || card.textContent.toLowerCase();
        const matches = !searchText || cardText.includes(searchText);

        card.hidden = !matches;

        if (matches) {
            visibleCards += 1;
        }
    });

    searchStatus.textContent =
        searchText && visibleCards === 0
            ? "No matching items were found."
            : "";
};

/**
 * Registers DOM2 event listeners for search and filters.
 */
const addEventListeners = () => {
    searchForm.addEventListener("submit", (event) => {
        event.preventDefault();
        applySearch();
    });

    searchInput.addEventListener("input", applySearch);

    applyFiltersButton.addEventListener("click", applyMemberFilters);

    stateFilter.addEventListener("change", applyMemberFilters);
    partyFilter.addEventListener("change", applyMemberFilters);
    chamberFilter.addEventListener("change", applyMemberFilters);

    clearFiltersButton.addEventListener("click", () => {
        stateFilter.value = "";
        partyFilter.value = "";
        chamberFilter.value = "";
        searchInput.value = "";

        applyMemberFilters();
        applySearch();
    });
};

populateStateFilter();
populatePartyFilter();
populateChamberFilter();
addEventListeners();
applyMemberFilters();
applySearch();
loadMembers();
loadBills();

