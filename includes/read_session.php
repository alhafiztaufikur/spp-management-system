<?php
/** Finish session writes before a read-only model/renderer holds up another tab. */
function read_session_release(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'
        || session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['admin_id'])) return;
    // The sidebar normally creates these later, while rendering the document.
    // Persist them now so its unit switch/logout forms remain valid.
    foreach (['csrf_unit_switch', 'csrf_logout'] as $key) {
        if (empty($_SESSION[$key])) $_SESSION[$key] = bin2hex(random_bytes(32));
    }
    session_write_close();
}
