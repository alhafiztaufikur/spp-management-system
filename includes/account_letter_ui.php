<?php
/** Small shared SVG vocabulary for account and parent-letter workspaces. */
function account_letter_icon(string $name): string {
    $paths = [
        'user'=>'<circle cx="12" cy="8" r="4"/><path d="M5 21v-3a7 7 0 0 1 14 0v3H5Z"/>',
        'users'=>'<circle cx="9" cy="8" r="3"/><path d="M3 20v-3a6 6 0 0 1 12 0v3M16 5a3 3 0 0 1 0 6M17 14a5 5 0 0 1 4 5"/>',
        'add'=>'<circle cx="9" cy="7" r="4"/><path d="M2 21v-3a7 7 0 0 1 14 0v3M19 8v6M16 11h6"/>',
        'shield'=>'<path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Z"/>',
        'lock'=>'<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3"/>',
        'unit'=>'<path d="M3 21h18M5 21V6l7-3v18M12 9h7v12M8 8v1M8 12v1M8 16v1M15 12h1M15 16h1"/>',
        'search'=>'<circle cx="10" cy="10" r="7"/><path d="m15 15 6 6"/>',
        'reload'=>'<path d="M20 8a8 8 0 1 0 1 7M20 3v5h-5"/>',
        'external'=>'<path d="M14 3h7v7M21 3l-9 9M10 3H3v18h18v-7"/>',
        'user_off'=>'<circle cx="9" cy="7" r="4"/><path d="M2 21v-3a7 7 0 0 1 14 0v3M17 10l5 5M22 10l-5 5"/>',
        'user_check'=>'<circle cx="9" cy="7" r="4"/><path d="M2 21v-3a7 7 0 0 1 14 0v3m1-9 2 2 4-4"/>',
        'info'=>'<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7h.01"/>',
        'document'=>'<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6ZM14 2v6h6M8 12h8M8 16h8"/>',
        'edit'=>'<path d="m16 3 5 5-12 12H4v-5L16 3ZM14 5l5 5M3 22h18"/>',
        'left'=>'<path d="m10 6-6 6 6 6M4 12h16"/>',
        'right'=>'<path d="m14 6 6 6-6 6M4 12h16"/>',
        'save'=>'<path d="M3 3h15l3 3v15H3V3ZM7 3v6h10V3M7 21v-8h10v8"/>',
        'print'=>'<path d="M7 8V3h10v5M7 17H3V8h18v9h-4M7 14h10v7H7v-7M17 11h.01"/>',
        'download'=>'<path d="M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5"/>',
        'close'=>'<path d="m6 6 12 12M18 6 6 18"/>',
        'copy'=>'<rect x="8" y="8" width="13" height="13" rx="2"/><path d="M16 8V3H3v13h5"/>',
        'menu'=>'<path d="M4 6h16M4 12h16M4 18h16"/>',
    ];
    return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.($paths[$name]??$paths['document']).'</svg>';
}
