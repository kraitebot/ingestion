// resources/js/landing.js
// Landing page JavaScript entry point
// Imports and initializes modular functionality for the early access page

import { initCountdown } from "./modules/countdown.js";

function init() {
    initCountdown();
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
} else {
    init();
}
