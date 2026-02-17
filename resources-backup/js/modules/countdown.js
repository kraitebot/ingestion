// resources/js/modules/countdown.js
// Days-only countdown helper
// Usage: <span data-days-countdown="2025-12-31T00:00:00Z"></span>

function computeDays(targetIso) {
    const MS_PER_DAY = 86_400_000;
    const target = new Date(targetIso).getTime();
    if (Number.isNaN(target)) return 0;
    const diff = Math.max(0, target - Date.now());
    return Math.max(0, Math.ceil(diff / MS_PER_DAY));
}

function updateElement(element) {
    const targetIso = element.getAttribute("data-days-countdown");
    if (!targetIso) return;
    element.textContent = String(computeDays(targetIso));
}

export function initCountdown() {
    const elements = Array.from(
        document.querySelectorAll("[data-days-countdown]"),
    );
    if (elements.length === 0) return;

    elements.forEach(updateElement);

    setInterval(() => {
        elements.forEach(updateElement);
    }, 60_000);
}
