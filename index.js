// Starts off at the home page
if (document.documentElement.dataset.entry === "home") {
    window.location.href = "home.html";
}

const refreshWebpage = new URL("./syncCongress.php", import.meta.url);
const twelveHours = 12 * 60 * 60 * 1000;

/**
 * Sends a request to PHP backend to refresh congressional schedule.
 * 
 * @returns {Promise<void>}
 */
const checkRefresh = async () => {
    try {
        await fetch(refreshWebpage, { method: "POST", headers: { "X-LACE-Refresh": "1" }, cache: "no-store" });
    } catch {
        // An offline refresh check should leave already displayed content available.
    }
};

// Refreshes congressional schedule if not on the home page.
if (document.documentElement.dataset.entry !== "home") {
    checkRefresh();
    window.setInterval(checkRefresh, twelveHours);
}

/**
 * Retries fetching the endpoint up to 5 times if it is initializing.
 * 
 * @param {string|URL} url Local PHP endpoint.
 * @returns {Promise<*>} Data returned by the endpoint.
 */
export const readLaceJSON = async (url) => {
    for (let attempt = 0; ; attempt++) {
        const response = await fetch(url, { cache: "no-store" });
        const result = await response.json();
        if (response.ok) return result.data;
        if (result.code !== "initializing" || attempt === 5) {
            throw new Error(result.error || "Unable to read the LACE database.");
        }
        await new Promise(resolve => window.setTimeout(resolve, 5000));
    }
};