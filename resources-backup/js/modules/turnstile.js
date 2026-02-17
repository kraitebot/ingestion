// resources/js/modules/turnstile.js
// Cloudflare Turnstile (Invisible) integration
// Requirements on the page:
//  - <form id="early-access-form" novalidate> ... </form>
//  - An invisible Turnstile widget inside that form, e.g.:
//      <x-turnstile id="pre_turnstile" data-size="invisible" data-theme="dark" />
//
// Behavior:
//  - On submit: run a local preflight with the Constraint Validation API.
//  - If form is NOT valid locally -> DO NOT execute Turnstile; let submit proceed.
//    Server will return field errors (no Turnstile noise).
//  - If form IS valid locally -> preventDefault, execute Turnstile, then submit.

const FORM_SELECTOR = "#early-access-form";
const BTN_SELECTOR = "#early-access-submit";
const DEFAULT_WIDGET_ID = "pre_turnstile";

function findWidgetId(formElement) {
    const byId = formElement.querySelector(`#${CSS.escape(DEFAULT_WIDGET_ID)}`);
    if (byId) return DEFAULT_WIDGET_ID;

    const cloudflare = formElement.querySelector(
        ".cf-turnstile,[data-sitekey]",
    );
    if (cloudflare) {
        if (!cloudflare.id) cloudflare.id = "turnstile_auto_1";
        return cloudflare.id;
    }
    return null;
}

function whenTurnstileReady(callback, retries = 100) {
    if (
        typeof window.turnstile !== "undefined" &&
        typeof window.turnstile.execute === "function"
    ) {
        callback(true);
        return;
    }
    if (retries <= 0) {
        callback(false);
        return;
    }
    setTimeout(() => whenTurnstileReady(callback, retries - 1), 100);
}

export function initTurnstile() {
    const form = document.querySelector(FORM_SELECTOR);
    if (!form) return;

    const submitButton = document.querySelector(BTN_SELECTOR);
    let submitting = false;

    form.addEventListener("submit", function (event) {
        if (form.dataset.turnstileOk === "1") return;

        const isLocallyValid = form.checkValidity();
        if (!isLocallyValid) {
            return;
        }

        event.preventDefault();
        if (submitting) return;
        submitting = true;
        if (submitButton) {
            submitButton.disabled = true;
            submitButton.setAttribute("aria-disabled", "true");
        }

        const widgetId = findWidgetId(form);

        whenTurnstileReady((ready) => {
            if (!ready || !widgetId) {
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.setAttribute("aria-disabled", "false");
                }
                submitting = false;
                form.submit();
                return;
            }

            try {
                window.turnstile.execute(widgetId, {
                    callback: function () {
                        form.dataset.turnstileOk = "1";
                        if (submitButton) {
                            submitButton.disabled = false;
                            submitButton.setAttribute("aria-disabled", "false");
                        }
                        submitting = false;
                        form.submit();
                    },
                    "error-callback": function () {
                        if (submitButton) {
                            submitButton.disabled = false;
                            submitButton.setAttribute("aria-disabled", "false");
                        }
                        submitting = false;
                    },
                    "expired-callback": function () {
                        if (submitButton) {
                            submitButton.disabled = false;
                            submitButton.setAttribute("aria-disabled", "false");
                        }
                        submitting = false;
                    },
                });
            } catch (error) {
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.setAttribute("aria-disabled", "false");
                }
                submitting = false;
                form.submit();
            }
        });
    });
}
