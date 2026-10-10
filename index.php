<?php
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#143d72">
  <meta name="description" content="Smart Campus Kiosk — visitor registration, RFID badge management, and campus monitoring for STI Global City.">
  <title>Smart Campus Kiosk · STI Global City</title>
  <link rel="icon" href="assets/favicon.svg?v=smart-campus-kiosk" type="image/svg+xml">
  <link rel="stylesheet" href="assets/style.css">
  <script src="assets/app.js" defer></script>
</head>
<body>
<svg class="svg-defs" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
<defs>
  <symbol id="i-grid" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></symbol>
  <symbol id="i-users" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2m20 0v-2a4 4 0 0 0-3-3.87M16 3a4 4 0 0 1 0 8"/><circle cx="9" cy="7" r="4"/></symbol>
  <symbol id="i-user" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/></symbol>
  <symbol id="i-history" viewBox="0 0 24 24"><path d="M3 10a9 9 0 1 1 2.8 8M3 4v6h6m3-3v5l3 2"/></symbol>
  <symbol id="i-archive" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="4" rx="1"/><path d="M5 7v14h14V7M10 11h4"/></symbol>
  <symbol id="i-trash" viewBox="0 0 24 24"><path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7m4-7v7"/></symbol>
  <symbol id="i-card" viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="3"/><path d="M2 9h20M6 15h3m5 0h4"/></symbol>
  <symbol id="i-chart" viewBox="0 0 24 24"><path d="M4 3v18h17M8 16v-5m5 5V6m5 10v-8"/></symbol>
  <symbol id="i-shield" viewBox="0 0 24 24"><path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Z"/><path d="m8 12 3 3 5-6"/></symbol>
  <symbol id="i-scan" viewBox="0 0 24 24"><path d="M8 3H3v5m13-5h5v5M3 16v5h5m13-5v5h-5M7 12h10m-5-4v8"/></symbol>
  <symbol id="i-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
  <symbol id="i-arrow" viewBox="0 0 24 24"><path d="M5 12h14m-5-5 5 5-5 5"/></symbol>
  <symbol id="i-down" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></symbol>
  <symbol id="i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>
  <symbol id="i-enter" viewBox="0 0 24 24"><path d="M15 3h5v18h-5M3 12h12m-5-5 5 5-5 5"/></symbol>
  <symbol id="i-exit" viewBox="0 0 24 24"><path d="M9 3H4v18h5m0-9h12m-5-5 5 5-5 5"/></symbol>
  <symbol id="i-search" viewBox="0 0 24 24"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/></symbol>
  <symbol id="i-download" viewBox="0 0 24 24"><path d="M12 3v12m-5-5 5 5 5-5M5 16v5h14v-5"/></symbol>
  <symbol id="i-building" viewBox="0 0 24 24"><path d="M4 21V7l8-4 8 4v14M2 21h20M10 21v-5h4v5M8 9h1m6 0h1m-8 3h1m6 0h1"/></symbol>
  <symbol id="i-help" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M9.1 9a3 3 0 1 1 5.8 1c-1 1.5-2.9 1.5-2.9 4m0 3h.01"/></symbol>
  <symbol id="i-lock" viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 4v3"/></symbol>
  <symbol id="i-menu" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></symbol>
  <symbol id="i-close" viewBox="0 0 24 24"><path d="m6 6 12 12M6 18 18 6"/></symbol>
  <symbol id="i-check" viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></symbol>
  <symbol id="i-refresh" viewBox="0 0 24 24"><path d="M20 8a9 9 0 0 0-15-3L3 7m0-5v5h5m-4 9a9 9 0 0 0 15 3l2-2m0 5v-5h-5"/></symbol>
  <symbol id="i-mail" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 6 9 7 9-7"/></symbol>
  <symbol id="i-edit" viewBox="0 0 24 24"><path d="m16 3 5 5-12 12-6 1 1-6L16 3Zm-3 3 5 5"/></symbol>
  <symbol id="i-leaf" viewBox="0 0 24 24"><path d="M20 3c0 12-3 17-9 17a7 7 0 0 1-7-7c0-6 5-9 16-10ZM3 21 15 9"/></symbol>
  <symbol id="campus-scene" viewBox="0 0 440 210">
    <path d="M8 187H425" stroke="currentColor" stroke-width="2" opacity=".3"/>
    <path d="M63 187V92l125-30 94 24v101" fill="#dae7f5" stroke="currentColor" stroke-width="2"/>
    <path d="M188 62v125M63 111l125-27 94 22M63 135l125-22 94 19M63 160l125-15 94 9" fill="none" stroke="currentColor" stroke-width="1.5"/>
    <path d="M87 103v12m26-18v12m26-19v12m26-18v13m-78 18v12m26-17v12m26-16v12m26-17v12m-78 18v12m26-15v12m26-16v12m26-16v13m39-57v14m24-8v14m24-8v14m-48 0v14m24-9v14m24-8v14m-48 0v14m24-10v14m24-9v14" stroke="currentColor" stroke-width="4" opacity=".5"/>
    <path d="M164 187v-25l24-4 26 4v25" fill="#a6c5e6" stroke="currentColor" stroke-width="1.5"/>
    <rect x="294" y="125" width="62" height="62" fill="#e3edf7" stroke="currentColor" stroke-width="1.5"/>
    <path d="M301 137h48m-48 12h48m-48 12h48m-39 26v-13h23v13" stroke="currentColor" stroke-width="2" opacity=".6"/>
    <rect x="136" y="58" width="43" height="21" rx="2" fill="#ffdc58"/>
    <text x="157" y="73" font-size="16" font-family="Arial,sans-serif" font-weight="900" font-style="italic" text-anchor="middle" fill="#1a518b">STI</text>
    <path d="M35 187v-34m-12 14c-16-13-8-33 13-36 21 3 30 23 13 36Z" fill="#bfd7d3" stroke="#679790" stroke-width="1.5"/>
    <path d="M392 187v-41m-13 16c-20-16-10-40 14-43 24 3 34 28 14 43Z" fill="#bfd7d3" stroke="#679790" stroke-width="1.5"/>
    <path d="M40 60c10-15 29-15 40 0m253-23c11-17 33-17 45 0" fill="none" stroke="currentColor" stroke-width="2" opacity=".2"/>
    <circle cx="332" cy="76" r="18" fill="#ffdd75" opacity=".85"/>
    <path d="M111 196h149" stroke="currentColor" stroke-width="2" opacity=".15"/>
  </symbol>
</defs>
</svg>
<a class="skip-link" href="#main">Skip to content</a>
<div id="loading-screen" class="loading-screen"><svg class="project-mark" viewBox="360 20 480 850" role="img" aria-label="Smart Campus Kiosk" focusable="false"><image href="assets/project-logo.png" width="1200" height="1200"/></svg><p>Opening your campus workspace…</p></div>
<section id="login-screen" class="login-screen" hidden aria-label="Sign in">
  <div class="login-story">
    <a class="login-brand" href="./" aria-label="Smart Campus Kiosk - STI Global City"><svg class="project-mark" viewBox="360 20 480 850" aria-hidden="true" focusable="false"><image href="assets/project-logo.png" width="1200" height="1200"/></svg><span class="brand-wordmark">SMART CAMPUS<span class="brand-project-name">KIOSK</span><span class="brand-sub">STI GLOBAL CITY</span></span></a>
    <div class="login-story-content">
      <span class="eyebrow light"><span class="status-dot"></span> A CONNECTED CAMPUS STARTS HERE</span>
      <h1>A smarter welcome.<br>A safer campus.</h1>
      <p>One connected workspace for welcoming visitors, managing RFID badges, and keeping your campus in view.</p>
      <svg class="login-campus" viewBox="0 0 440 210" width="440" height="210" aria-hidden="true" focusable="false"><use href="#campus-scene"/></svg>
      <div class="login-features"><span><svg class="icon"><use href="#i-shield"/></svg>Role-based access</span><span><svg class="icon"><use href="#i-clock"/></svg>Live visitor monitoring</span></div>
    </div>
    <div class="login-story-footer">Smart Campus Kiosk <span>STI College Global City</span></div>
  </div>
  <div class="login-panel">
    <div class="login-card">
      <div class="login-project-logo"><img class="project-logo-full" src="assets/project-logo.png" width="1200" height="1200" alt="Smart Campus Kiosk logo, STI Global City" decoding="async"></div>
      <span class="eyebrow">YOUR CAMPUS WORKSPACE</span>
      <h2>Welcome back</h2><p>Sign in to manage a smooth, secure campus visit.</p>
      <form id="login-form">
        <label for="login-email">Email address</label>
        <input id="login-email" name="email" type="email" autocomplete="username" placeholder="you@sti.local" required maxlength="160">
        <div class="label-row"><label for="login-password">Password</label><button class="text-button" type="button" id="login-help">Need help?</button></div>
        <input id="login-password" name="password" type="password" autocomplete="current-password" placeholder="Enter your password" required>
        <p id="login-error" class="form-error" role="alert" hidden></p>
        <button class="button primary login-submit" type="submit">Sign in to campus <svg class="icon"><use href="#i-arrow"/></svg></button>
      </form>
      <div class="or-divider"><span>just taking a look?</span></div>
      <button id="demo-login" class="button secondary demo-button" type="button"><svg class="icon"><use href="#i-grid"/></svg>Explore the demo dashboard</button>
      <p class="demo-caption">A working prototype with fictional visitor records.</p>
      <div class="login-privacy"><svg class="icon"><use href="#i-lock"/></svg><span>Visitor details are accessible to authorized personnel.</span></div>
    </div>
    <p class="login-bottom">Welcoming people. Connecting campus.</p>
  </div>
</section>
<div id="app" class="app" hidden>
  <div id="mobile-backdrop" class="mobile-backdrop" hidden></div>
  <aside id="sidebar" class="sidebar" aria-label="Main navigation">
    <a class="sidebar-brand" href="#dashboard" aria-label="Smart Campus Kiosk dashboard"><svg class="project-mark" viewBox="360 20 480 850" aria-hidden="true" focusable="false"><image href="assets/project-logo.png" width="1200" height="1200"/></svg><span class="brand-wordmark">SMART CAMPUS<span class="brand-project-name">KIOSK</span><span class="brand-sub">STI GLOBAL CITY</span></span></a>
    <div class="workspace-label">CAMPUS WORKSPACE</div>
    <nav id="navigation">
      <a class="nav-item" href="#dashboard" data-view="dashboard"><svg class="icon"><use href="#i-grid"/></svg>Overview</a>
      <a class="nav-item" href="#visitors" data-view="visitors"><svg class="icon"><use href="#i-users"/></svg>Visitors<span id="nav-count" class="nav-count">0</span></a>
      <a class="nav-item" href="#history" data-view="history"><svg class="icon"><use href="#i-history"/></svg>Visitor history</a>
      <a class="nav-item" href="#badges" data-view="badges"><svg class="icon"><use href="#i-card"/></svg>RFID badges</a>
      <a class="nav-item" href="#reports" data-view="reports"><svg class="icon"><use href="#i-chart"/></svg>Reports</a>
      <a class="nav-item" href="#archives" data-view="archives" data-admin><svg class="icon"><use href="#i-archive"/></svg>Archives</a>
      <a class="nav-item" href="#accounts" data-view="accounts" data-admin><svg class="icon"><use href="#i-shield"/></svg>User accounts</a>
    </nav>
    <div class="sidebar-kiosk"><div class="kiosk-illustration"><svg class="icon"><use href="#i-scan"/></svg><span class="mini-signal"></span></div><strong>A warm welcome starts here</strong><p>Ready for the next campus visitor?</p><a class="button secondary" href="#kiosk">Open gate kiosk <svg class="icon"><use href="#i-arrow"/></svg></a></div>
    <div class="sidebar-bottom"><a class="nav-item" href="#profile" data-view="profile"><svg class="icon"><use href="#i-user"/></svg>My account</a><button id="help-button" class="nav-item"><svg class="icon"><use href="#i-help"/></svg>Help & information</button><div class="network-state"><span id="connection-dot" class="status-dot"></span><span id="connection-label">Connected to campus</span></div><div class="sidebar-version">SMART CAMPUS KIOSK <span>v1.0</span></div></div>
  </aside>
  <div class="app-body">
    <header class="topbar">
      <div class="topbar-left"><button id="menu-toggle" class="icon-button menu-toggle" aria-label="Open navigation" aria-expanded="false" aria-controls="sidebar"><svg class="icon"><use href="#i-menu"/></svg></button><span class="campus-name"><svg class="icon"><use href="#i-building"/></svg>STI College Global City</span><span class="topbar-divider"></span><span class="topbar-page" id="topbar-page">Campus overview</span></div>
      <div class="topbar-right"><span id="header-clock" class="header-clock"></span><span class="live-indicator"><span class="status-dot"></span>Live</span><span class="topbar-divider"></span><button class="profile-button" id="profile-button" title="Account and sign out"><span class="avatar" id="profile-initials">AD</span><span class="profile-info"><strong id="profile-name">Administrator</strong><span id="profile-role">Campus administrator</span></span><svg class="icon chevron"><use href="#i-down"/></svg></button></div>
    </header>
    <main id="main" class="main" tabindex="-1"><div id="view"></div></main>
    <footer class="app-footer"><span>STI Global City <span class="footer-separator">/</span> Smart Campus Kiosk</span><span><svg class="icon"><use href="#i-lock"/></svg>Authorized personnel only <span class="footer-separator">·</span> <span id="last-sync">Waiting for connection</span></span></footer>
  </div>
</div>
<dialog id="modal" class="modal" aria-labelledby="modal-heading"><div class="modal-header"><div id="modal-heading"></div><button class="icon-button" id="modal-close" aria-label="Close dialog"><svg class="icon"><use href="#i-close"/></svg></button></div><div id="modal-content"></div></dialog>
<div id="toast-region" class="toast-region" aria-live="polite" aria-atomic="false"></div>
<noscript><div class="noscript-message">Enable JavaScript to use the Smart Campus Kiosk dashboard.</div></noscript>
</body>
</html>
