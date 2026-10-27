<?php // Decorative view only. The academic year follows the selected year. ?>
<svg class="du-hero-art" viewBox="0 0 540 270" fill="none" aria-hidden="true" focusable="false">
  <defs>
    <linearGradient id="du-art-blob" x1="170" y1="20" x2="440" y2="260" gradientUnits="userSpaceOnUse"><stop stop-color="var(--du-art-light, #b8ecd6)"/><stop offset="1" stop-color="var(--du-art-mid, #60c399)" stop-opacity=".55"/></linearGradient>
    <linearGradient id="du-art-cover" x1="0" y1="0" x2="1" y2="1"><stop stop-color="var(--du-art-mid, #65c1a4)"/><stop offset=".5" stop-color="var(--du-art-strong, #218c69)"/><stop offset="1" stop-color="var(--du-art-deep, #106b4e)"/></linearGradient>
    <linearGradient id="du-art-paper" x1="0" y1="0" x2="1" y2="1"><stop stop-color="#fff"/><stop offset="1" stop-color="var(--du-art-paper, #e2f4eb)"/></linearGradient>
    <linearGradient id="du-art-ring" x1="0" y1="0" x2="1" y2="0"><stop stop-color="var(--du-art-paper, #e0fff0)"/><stop offset=".5" stop-color="var(--du-art-light, #8bd6b7)"/><stop offset="1" stop-color="var(--du-art-deep, #1c8565)"/></linearGradient>
    <linearGradient id="du-art-badge" x1="425" y1="125" x2="461" y2="201" gradientUnits="userSpaceOnUse"><stop stop-color="var(--du-art-paper, #f0fff5)"/><stop offset="1" stop-color="var(--du-art-light, #b4e9c8)"/></linearGradient>
    <filter id="du-art-shadow" x="-30%" y="-30%" width="170%" height="180%"><feDropShadow dx="5" dy="10" stdDeviation="8" flood-color="var(--du-art-deep, #126a4d)" flood-opacity=".23"/></filter>
    <pattern id="du-art-dots" width="14" height="14" patternUnits="userSpaceOnUse"><circle cx="2" cy="2" r="1.4" fill="var(--du-art-mid, #65c59a)"/></pattern>
  </defs>
  <path d="M12 270c86-94 117-74 185-17s154 18 210-39 96-64 133-47v103H12Z" fill="var(--du-art-light, #b3e5d3)" opacity=".3"/>
  <path d="M510 0c-18 45-8 68-43 95s-65 7-90 39-3 83-47 102-91-20-119-55-79-35-86-85 23-76 62-78 73 16 106 9S362 8 385 0h125Z" fill="url(#du-art-blob)"/>
  <path d="M541 125c-70-33-107 4-115 51s-40 63-13 77 95-4 103-38 0-53 25-64v-26Z" fill="url(#du-art-blob)"/>
  <path d="M360 0h151c-18 39-12 71-50 99s-73 9-103 34-34 80-65 92c25-48 2-55 33-97s63-32 66-62S363 21 360 0Z" fill="#fff" opacity=".32"/>
  <path fill="url(#du-art-dots)" d="M382 18h108v90H382zM124 136h100v102H124z" opacity=".7"/>
  <g transform="translate(231 33) rotate(12)" filter="url(#du-art-shadow)">
    <rect x="7" y="7" width="186" height="181" rx="15" fill="var(--du-art-deep, #238960)"/>
    <rect width="186" height="187" rx="14" fill="url(#du-art-cover)"/>
    <path d="M0 44h186v130a13 13 0 0 1-13 13H13a13 13 0 0 1-13-13V44Z" fill="url(#du-art-paper)"/>
    <path d="M2 44h182" stroke="var(--du-art-paper, #d8f1e5)" stroke-width="2"/>
    <g fill="var(--du-art-deep, #145e48)" opacity=".4"><ellipse cx="40" cy="16" rx="10" ry="6"/><ellipse cx="130" cy="16" rx="10" ry="6"/></g>
    <rect x="32" y="-17" width="17" height="41" rx="8.5" fill="url(#du-art-ring)"/>
    <rect x="122" y="-17" width="17" height="41" rx="8.5" fill="url(#du-art-ring)"/>
    <text x="93" y="78" text-anchor="middle" font-family="Inter, sans-serif" font-size="17" font-weight="600" fill="var(--du-art-deep, #165c47)"><?= master_du_e($selectedYear) ?></text>
    <g fill="var(--du-art-light, #cce9dd)"><rect x="24" y="96" width="25" height="25" rx="5"/><rect x="60" y="96" width="25" height="25" rx="5"/><rect x="96" y="96" width="25" height="25" rx="5"/><rect x="132" y="96" width="25" height="25" rx="5"/><rect x="24" y="133" width="25" height="25" rx="5"/><rect x="60" y="133" width="25" height="25" rx="5"/><rect x="132" y="133" width="25" height="25" rx="5"/></g>
    <rect x="96" y="133" width="25" height="25" rx="5" fill="url(#du-art-cover)"/>
  </g>
  <g filter="url(#du-art-shadow)"><circle cx="438" cy="165" r="42" fill="url(#du-art-badge)"/><circle cx="438" cy="165" r="41.5" stroke="var(--du-art-paper, #e1ffec)"/><path d="m416 158 22-10 22 10-22 10-22-10Z" fill="var(--du-art-strong, #12834a)"/><path d="M424 165v13c8 5 20 5 28 0v-13l-14 6-14-6Z" fill="var(--du-art-strong, #12834a)"/><path d="M460 159v19" stroke="var(--du-art-strong, #12834a)" stroke-width="3" stroke-linecap="round"/></g>
</svg>
