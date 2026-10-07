/* Compatibility hook; presentation lives in dropdowns.js. */
window.syncOtherFeePickers = () => window.syncSppDropdowns?.();
document.addEventListener("DOMContentLoaded", () => window.syncSppDropdowns?.());
