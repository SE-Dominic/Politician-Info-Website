import {
    getAllCurrentMembers,
    getMemberDetails,
    getCurrentCongress,
    getSponsoredLegislation,
    getCosponsoredLegislation,
    getHouseVotes,
    getHouseVoteMembers,
} from "./congressService.js";

const $ = (id) => document.getElementById(id);

let members = [];
let selectedMembers = { a: null, b: null };
let comparisonRequest = 0;

const VOTE_BATCH_SIZE = 5;
const voteMembersCache = new Map();

const getTerms = (member) =>
    member.terms?.item || member.terms || [];

const getChamber = (member) => {
    const terms = getTerms(member);
    return terms.length ? terms[terms.length - 1].chamber : "";
};

const getParty = (member) =>
    member.partyName || member.party || "Unknown";

const getName = (member) =>
    member.directOrderName || member.name || "Unknown legislator";

const getDistrict = (member, chamber) => {
    if (chamber === "Senate") return "Statewide";
    if (member.district == null) return "Unavailable";
    if (Number(member.district) === 0) return "At-large";
    return `District ${member.district}`;
};

const getYearsInOffice = (member) => {
    const terms = getTerms(member);
    const years = terms
        .map((term) => Number(term.startYear))
        .filter(Number.isFinite);

    if (!years.length) return "Unavailable";

    const firstYear = Math.min(...years);
    const current =
        String(member.currentMember).toLowerCase() === "true";

    return `${firstYear} – ${current ? "Present" : "Last recorded term"}`;
};

const setText = (id, value) => {
    $(id).textContent = value;
};

const renderSearchResults = (slot) => {
    const input = $(`search-input-${slot}`);
    const results = $(`search-results-${slot}`);
    const status = $(`search-status-${slot}`);
    const query = input.value.trim().toLowerCase();

    results.replaceChildren();

    if (!query) {
        results.hidden = true;
        status.textContent = "Enter a name, state, district, or party.";
        return;
    }

    const matches = members.filter((member) => {
        const searchText = [
            getName(member),
            member.name || "",
            member.state || "",
            member.district ?? "",
            getParty(member),
            getChamber(member),
        ]
            .join(" ")
            .toLowerCase();

        return searchText.includes(query);
    });

    const shownMembers = matches.slice(0, 10);

    for (const member of shownMembers) {
        const item = document.createElement("li");
        const button = document.createElement("button");
        const name = document.createElement("strong");
        const detail = document.createElement("span");

        button.type = "button";
        button.className = "search-result-button";

        name.textContent = getName(member);
        detail.textContent =
            `${getParty(member)} · ${member.state || "State unavailable"} · ` +
            `${getChamber(member) || "Chamber unavailable"}`;

        button.append(name, detail);
        button.addEventListener("click", () => {
            selectMember(slot, member);
        });

        item.appendChild(button);
        results.appendChild(item);
    }

    results.hidden = shownMembers.length === 0;

    if (matches.length === 0) {
        status.textContent = "No matching current legislators found.";
    } else if (matches.length > shownMembers.length) {
        status.textContent =
            `${matches.length} legislators found. Showing the first 10; refine your search.`;
    } else {
        status.textContent = `${matches.length} matching legislator(s).`;
    }
};

const selectMember = (slot, member, startComparison = true) => {
    selectedMembers[slot] = member;

    $(`search-input-${slot}`).value = getName(member);
    $(`search-results-${slot}`).replaceChildren();
    $(`search-results-${slot}`).hidden = true;
    $(`search-status-${slot}`).textContent =
        `Selected ${getName(member)}.`;

    $("comparison-results").hidden = true;

    if (startComparison && selectedMembers.a && selectedMembers.b) {
        loadComparison();
    }
};

const renderProfileCard = (slot, member) => {
    const chamber = getChamber(member);
    const photo = $(`photo-${slot}`);
    const imageUrl = member.depiction?.imageUrl;

    setText(`name-${slot}`, getName(member));
    setText(`chamber-${slot}`, chamber || "Chamber unavailable");
    setText(`party-${slot}`, getParty(member));
    setText(`state-${slot}`, member.state || "Unavailable");
    setText(
        `district-${slot}`,
        getDistrict(member, chamber),
    );
    setText(`years-${slot}`, getYearsInOffice(member));

    photo.alt = `Portrait of ${getName(member)}`;
    photo.onerror = () => {
        photo.onerror = null;
        photo.src = "./assets/profile_photo_icon.jpg";
    };

    if (imageUrl && imageUrl.startsWith("https://")) {
        photo.src = imageUrl;
    } else {
        photo.src = "./assets/profile_photo_icon.jpg";
    }

    $(`profile-link-${slot}`).href =
        `legislator.html?id=${encodeURIComponent(member.bioguideId)}`;
};

const getCachedVoteMembers = (congress, session, rollCallNumber) => {
    const key = `${congress}-${session}-${rollCallNumber}`;

    if (!voteMembersCache.has(key)) {
        const request = getHouseVoteMembers(
            congress,
            session,
            rollCallNumber,
        ).catch((error) => {
            voteMembersCache.delete(key);
            throw error;
        });

        voteMembersCache.set(key, request);
    }

    return voteMembersCache.get(key);
};

const createVoteStats = () => ({
    withMajority: 0,
    againstMajority: 0,
    notVoting: 0,
    present: 0,
    tie: 0,
    total: 0,
});

const getVoteChoice = (memberVote) =>
    memberVote?.voteCast?.trim().toLowerCase() || "";

const addMemberVoteToStats = (stats, memberVote, majority) => {
    const choice = getVoteChoice(memberVote);

    if (choice === "not voting") {
        stats.notVoting++;
        stats.total++;
    } else if (choice === "present") {
        stats.present++;
        stats.total++;
    } else if (choice === "aye" || choice === "yea" || choice === "nay") {
        stats.total++;

        if (!majority) {
            stats.tie++;
        } else if (
            (majority === "aye" && (choice === "aye" || choice === "yea")) ||
            (majority === "nay" && choice === "nay")
        ) {
            stats.withMajority++;
        } else {
            stats.againstMajority++;
        }
    }
};

const getVotingComparison = async (memberAId, memberBId, requestId) => {
    const congress = await getCurrentCongress();
    const session =
        new Date().getUTCFullYear() % 2 === 0 ? 2 : 1;
    const sessionVotes = await getHouseVotes(congress, session);
    const stats = {
        a: createVoteStats(),
        b: createVoteStats(),
    };
    let failed = 0;

    for (
        let start = 0;
        start < sessionVotes.length;
        start += VOTE_BATCH_SIZE
    ) {
        if (requestId !== comparisonRequest) return null;

        const batch = sessionVotes.slice(
            start,
            start + VOTE_BATCH_SIZE,
        );

        setText(
            "vote-status",
            `Loading vote records: ${Math.min(
                start + batch.length,
                sessionVotes.length,
            )} of ${sessionVotes.length}...`,
        );

        const outcomes = await Promise.all(
            batch.map(async (vote) => {
                try {
                    const results = await getCachedVoteMembers(
                        congress,
                        session,
                        vote.rollCallNumber,
                    );

                    return { vote, results, failed: false };
                } catch {
                    return { vote, results: [], failed: true };
                }
            }),
        );

        if (requestId !== comparisonRequest) return null;

        for (const outcome of outcomes) {
            if (outcome.failed) {
                failed++;
                continue;
            }

            const voteMembers = outcome.results;
            let yeaCount = 0;
            let nayCount = 0;

            for (const memberVote of voteMembers) {
                const choice = getVoteChoice(memberVote);

                if (choice === "aye" || choice === "yea") {
                    yeaCount++;
                } else if (choice === "nay") {
                    nayCount++;
                }
            }

            let majority = null;

            if (yeaCount > nayCount) {
                majority = "aye";
            } else if (nayCount > yeaCount) {
                majority = "nay";
            }

            const memberVoteA = voteMembers.find(
                (memberVote) =>
                    (memberVote.bioguideId ||
                        memberVote.bioguideID) === memberAId,
            );

            const memberVoteB = voteMembers.find(
                (memberVote) =>
                    (memberVote.bioguideId ||
                        memberVote.bioguideID) === memberBId,
            );

            if (memberVoteA) {
                addMemberVoteToStats(stats.a, memberVoteA, majority);
            }

            if (memberVoteB) {
                addMemberVoteToStats(stats.b, memberVoteB, majority);
            }
        }
    }

    return {
        congress,
        session,
        totalVotes: sessionVotes.length,
        failed,
        stats,
    };
};

const getVoteCategories = (stats) => [
    {
        key: "withMajority",
        label: "Voted with majority",
        className: "legend-majority",
    },
    {
        key: "againstMajority",
        label: "Voted against majority",
        className: "legend-against",
    },
    {
        key: "notVoting",
        label: "Not voting",
        className: "legend-not-voting",
    },
    {
        key: "present",
        label: "Present",
        className: "legend-present",
    },
    {
        key: "tie",
        label: "Tie / no majority",
        className: "legend-tie",
    },
];

const renderVoteSummary = (slot, stats) => {
    const categories = getVoteCategories(stats);
    const chart = $(`vote-chart-${slot}`);
    const legend = $(`vote-legend-${slot}`);
    const colors = [
        "var(--vote-majority)",
        "var(--vote-against)",
        "var(--vote-not-voting)",
        "var(--vote-present)",
        "var(--vote-tie)",
    ];

    let currentDegree = 0;
    const segments = [];

    categories.forEach((category, index) => {
        const value = stats.total
            ? (stats[category.key] / stats.total) * 360
            : 0;
        const endDegree = currentDegree + value;

        segments.push(
            `${colors[index]} ${currentDegree}deg ${endDegree}deg`,
        );
        currentDegree = endDegree;
    });

    chart.style.background = stats.total
        ? `conic-gradient(${segments.join(", ")})`
        : "Gainsboro";

    chart.setAttribute(
        "aria-label",
        stats.total
            ? `Vote distribution for ${stats.total} matched votes`
            : "No matched vote data is available",
    );

    legend.replaceChildren();

    for (const category of categories) {
        const item = document.createElement("li");
        const swatch = document.createElement("span");
        const text = document.createElement("span");
        const percent = stats.total
            ? Math.round((stats[category.key] / stats.total) * 100)
            : 0;

        swatch.className = `legend-swatch ${category.className}`;
        text.textContent =
            `${category.label}: ${stats[category.key]} (${percent}%)`;

        item.append(swatch, text);
        legend.appendChild(item);
    }

    const valueFor = (key) => {
        if (!stats.total) return "No data";
        const percent = Math.round((stats[key] / stats.total) * 100);
        return `${stats[key]} (${percent}%)`;
    };

    setText(`vote-${slot}-majority`, valueFor("withMajority"));
    setText(`vote-${slot}-against`, valueFor("againstMajority"));
    setText(`vote-${slot}-not-voting`, valueFor("notVoting"));
    setText(`vote-${slot}-present`, valueFor("present"));
    setText(`vote-${slot}-tie`, valueFor("tie"));

    setText(`presence-not-voting-${slot}`, String(stats.notVoting));
    setText(`presence-present-${slot}`, String(stats.present));
};

const setVoteUnavailable = (message) => {
    setText("vote-status", message);

    const slots = ["a", "b"];
    const metrics = [
        "majority",
        "against",
        "not-voting",
        "present",
        "tie",
    ];

    for (const slot of slots) {
        for (const metric of metrics) {
            setText(`vote-${slot}-${metric}`, "Unavailable");
        }

        setText(`presence-not-voting-${slot}`, "Unavailable");
        setText(`presence-present-${slot}`, "Unavailable");
        $(`vote-legend-${slot}`).replaceChildren();
        $(`vote-chart-${slot}`).style.background = "Gainsboro";
    }
};

const loadComparison = async () => {
    const memberA = selectedMembers.a;
    const memberB = selectedMembers.b;

    if (!memberA || !memberB) return;

    if (memberA.bioguideId === memberB.bioguideId) {
        setText("compare-status", "Choose two different legislators.");
        $("comparison-results").hidden = true;
        return;
    }

    const requestId = ++comparisonRequest;
    $("comparison-results").hidden = false;
    setText("compare-status", "Loading legislator comparison...");
    setVoteUnavailable("Waiting for vote data...");

    history.replaceState(
        null,
        "",
        `compare.html?memberA=${encodeURIComponent(
            memberA.bioguideId,
        )}&memberB=${encodeURIComponent(memberB.bioguideId)}`,
    );

    try {
        const [
            detailsA,
            detailsB,
            sponsoredA,
            sponsoredB,
            cosponsoredA,
            cosponsoredB,
        ] = await Promise.all([
            getMemberDetails(memberA.bioguideId),
            getMemberDetails(memberB.bioguideId),
            getSponsoredLegislation(memberA.bioguideId, 1, 0),
            getSponsoredLegislation(memberB.bioguideId, 1, 0),
            getCosponsoredLegislation(memberA.bioguideId, 1, 0),
            getCosponsoredLegislation(memberB.bioguideId, 1, 0),
        ]);

        if (requestId !== comparisonRequest) return;

        renderProfileCard("a", detailsA);
        renderProfileCard("b", detailsB);

        setText("sponsored-a", String(sponsoredA.count));
        setText("sponsored-b", String(sponsoredB.count));
        setText("cosponsored-a", String(cosponsoredA.count));
        setText("cosponsored-b", String(cosponsoredB.count));

        const chamberA = getChamber(detailsA);
        const chamberB = getChamber(detailsB);

        if (
            chamberA !== "House of Representatives" ||
            chamberB !== "House of Representatives"
        ) {
            setVoteUnavailable(
                "Voting comparison currently supports House members only.",
            );
        } else {
            setText("vote-status", "Loading current-session vote records...");

            try {
                const voteComparison = await getVotingComparison(
                    memberA.bioguideId,
                    memberB.bioguideId,
                    requestId,
                );

                if (!voteComparison || requestId !== comparisonRequest) return;

                renderVoteSummary("a", voteComparison.stats.a);
                renderVoteSummary("b", voteComparison.stats.b);

                setText(
                    "vote-status",
                    `Based on matched roll calls in Congress ${voteComparison.congress}, ` +
                        `session ${voteComparison.session}. ` +
                        `${voteComparison.failed
                            ? `${voteComparison.failed} vote requests failed. `
                            : ""}` +
                        "The source may not include every type of House vote.",
                );
            } catch (error) {
                if (requestId === comparisonRequest) {
                    setVoteUnavailable(
                        `Voting data unavailable: ${error.message}`,
                    );
                }
            }
        }

        setText("compare-status", "Comparison loaded.");
    } catch (error) {
        if (requestId === comparisonRequest) {
            setText(
                "compare-status",
                `Unable to load comparison: ${error.message}`,
            );
            $("comparison-results").hidden = true;
        }
    }
};

const addSearchListeners = (slot) => {
    const form = $(`search-form-${slot}`);
    const input = $(`search-input-${slot}`);

    form.addEventListener("submit", (event) => {
        event.preventDefault();
        renderSearchResults(slot);
    });

    input.addEventListener("input", () => {
        selectedMembers[slot] = null;
        $("comparison-results").hidden = true;
        renderSearchResults(slot);
    });
};

const addEventListeners = () => {
    addSearchListeners("a");
    addSearchListeners("b");
};

const initialize = async () => {
    addEventListeners();

    setText("search-status-a", "Loading current legislators...");
    setText("search-status-b", "Loading current legislators...");

    try {
        members = await getAllCurrentMembers();

        setText(
            "search-status-a",
            `${members.length} current legislators available.`,
        );
        setText(
            "search-status-b",
            `${members.length} current legislators available.`,
        );

        const params = new URLSearchParams(window.location.search);
        const idA =
            params.get("memberA") || params.get("member") || "";
        const idB = params.get("memberB") || "";

        const memberA = members.find(
            (member) => member.bioguideId === idA,
        );
        const memberB = members.find(
            (member) => member.bioguideId === idB,
        );

        if (memberA) selectMember("a", memberA, false);
        if (memberB) selectMember("b", memberB, false);

        if (memberA && memberB) {
            loadComparison();
        } else if (memberA) {
            setText(
                "compare-status",
                `${getName(memberA)} is selected as Legislator A. Choose Legislator B.`,
            );
        }
    } catch (error) {
        setText(
            "compare-status",
            `Unable to load current legislators: ${error.message}`,
        );
        setText("search-status-a", "Legislator search is unavailable.");
        setText("search-status-b", "Legislator search is unavailable.");
    }
};

initialize();