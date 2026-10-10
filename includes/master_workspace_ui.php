<?php
/** Small presentation helpers; no queries or form processing. */
function master_workspace_icon(string $name): string {
    $paths = [
        'students' => '<circle cx="9" cy="7" r="3"/><path d="M3 21v-3a5 5 0 0 1 10 0v3M17 4a3 3 0 0 1 0 6M17 14a5 5 0 0 1 4 5v2"/>',
        'student' => '<circle cx="12" cy="7" r="4"/><path d="M5 21v-2a7 7 0 0 1 14 0v2"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 11h18M8 15h2M14 15h2M8 18h2"/>',
        'hourglass' => '<path d="M6 3h12M6 21h12M7 3v4c0 3 10 7 10 10v4M17 3v4c0 3-10 7-10 10v4M9 6h6M9 18h6"/>',
        'document' => '<path d="M14 3H5v18h14V8l-5-5ZM14 3v5h5M8 12h8M8 16h6"/>',
        'document-add' => '<path d="M14 3H5v18h7M14 3v5h5v4M8 12h4M17 14v7M13.5 17.5h7"/>',
        'send' => '<path d="m21 3-7 18-4-8-8-4L21 3ZM10 13 21 3"/>',
        'coins' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v5c0 4 16 4 16 0V5M4 10v5c0 4 16 4 16 0v-5M4 15v4c0 4 16 4 16 0v-4"/>',
        'layers' => '<path d="m12 3 10 5-10 5L2 8l10-5ZM2 12l10 5 10-5M2 16l10 5 10-5"/>',
        'check' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
        'discount' => '<path d="m5 19 14-14"/><circle cx="7" cy="7" r="3"/><circle cx="17" cy="17" r="3"/>',
        'settings' => '<path d="m9 3-1 3-3 1 1 3-2 2 2 2-1 3 3 1 1 3h6l1-3 3-1-1-3 2-2-2-2 1-3-3-1-1-3H9Z"/><circle cx="12" cy="12" r="3"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7h.01"/>',
        'lock' => '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3"/>',
        'save' => '<path d="M4 3h13l4 4v14H3V3h1ZM7 3v6h9V3M7 21v-8h10v8"/>',
        'edit' => '<path d="m16 3 5 5-12 12H4v-5L16 3ZM14 5l5 5"/>',
        'plus' => '<path d="M12 4v16M4 12h16"/>',
        'reset' => '<path d="M3 10a9 9 0 1 1 2 8M3 4v6h6"/>',
        'calculator' => '<rect x="5" y="2" width="14" height="20" rx="2"/><path d="M8 5h8v4H8zM8 13h1M12 13h1M16 13h.01M8 17h1M12 17h1M16 17h.01"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.($paths[$name] ?? $paths['document']).'</svg>';
}

function master_workspace_roman(int $grade): string {
    return [1=>'I',2=>'II',3=>'III',4=>'IV',5=>'V',6=>'VI',7=>'VII',8=>'VIII',9=>'IX',10=>'X',11=>'XI',12=>'XII'][$grade] ?? (string)$grade;
}
