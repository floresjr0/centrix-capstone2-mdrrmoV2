<?php
require_once __DIR__ . '/session.php';
require_login();
$user = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<!--
  iOS FIX #1: Added viewport-fit=cover
  
-->
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<title>MDRRMO Navigation</title>
<link rel="stylesheet" href="../asset/css/usernavigation.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<link rel="stylesheet" href="https://unpkg.com/leaflet-routing-machine@latest/dist/leaflet-routing-machine.css"/>
<link href="https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.css" rel="stylesheet"/>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>


</style>
</head>

<body>
<div id="app">

  <!-- MAP -->
  <div id="map"></div>

  <!-- TOP DIRECTION CARD -->
  <div id="dirCard">
    <div id="turnArrowBox">
      <svg id="turnArrowSvg" width="34" height="34" viewBox="0 0 24 24">
        <path d="M12 3v15M12 3L7 8M12 3L17 8" stroke="white" stroke-width="2.5" stroke-linecap="round" fill="none"/>
      </svg>
    </div>
    <div class="dir-info">
      <div id="turnInstruction">Head toward destination</div>
      <div id="stepDist">Calculating…</div>
    </div>
    <div id="etaBadge">
      <div id="etaMin">--</div>
      <div id="etaLabel">min</div>
    </div>
  </div>

  <!-- OFF-ROUTE BANNER -->
  <div id="offrouteBanner">
    <svg class="offroute-icon" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
      <path d="M8 2L15 14H1L8 2Z" stroke="#fff" stroke-width="1.5" stroke-linejoin="round" fill="none"/>
      <line x1="8" y1="7" x2="8" y2="10" stroke="#fff" stroke-width="1.5" stroke-linecap="round"/>
      <circle cx="8" cy="12.5" r="0.75" fill="#fff"/>
    </svg>
    <div>
      <div class="offroute-text">Off Route!</div>
      <div class="offroute-sub">Recalculating…</div>
    </div>
  </div>

  <!-- REROUTE TOAST -->
  <div id="rerouteToast">
    <span class="toast-icon">🔄</span>
    <span id="rerouteToastMsg">Rerouting to next available center…</span>
  </div>

  <!-- BACK TO DASHBOARD -->
  <a id="backBtn" href="citizen_dashboard.php" title="Back to Dashboard">
    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
      <path d="M10 3L5 8L10 13" stroke="#d45f10" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
  </a>

  <!-- TOP-RIGHT CONTROLS -->
  <div id="topRightControls">
    <div class="top-pill">
      <button id="mapModeBtn" onclick="toggleMapMode()" title="Toggle dark map">
        <svg class="mode-icon-light" width="20" height="20" viewBox="0 0 20 20" fill="none">
          <circle cx="10" cy="10" r="4" stroke="#d45f10" stroke-width="1.8"/>
          <line x1="10" y1="1"  x2="10" y2="3.5" stroke="#d45f10" stroke-width="1.6" stroke-linecap="round"/>
          <line x1="10" y1="16.5" x2="10" y2="19" stroke="#d45f10" stroke-width="1.6" stroke-linecap="round"/>
          <line x1="1"  y1="10" x2="3.5" y2="10" stroke="#d45f10" stroke-width="1.6" stroke-linecap="round"/>
          <line x1="16.5" y1="10" x2="19" y2="10" stroke="#d45f10" stroke-width="1.6" stroke-linecap="round"/>
          <line x1="3.5" y1="3.5"  x2="5.3" y2="5.3"  stroke="#d45f10" stroke-width="1.5" stroke-linecap="round"/>
          <line x1="14.7" y1="14.7" x2="16.5" y2="16.5" stroke="#d45f10" stroke-width="1.5" stroke-linecap="round"/>
          <line x1="14.7" y1="5.3"  x2="16.5" y2="3.5"  stroke="#d45f10" stroke-width="1.5" stroke-linecap="round"/>
          <line x1="3.5"  y1="16.5" x2="5.3"  y2="14.7" stroke="#d45f10" stroke-width="1.5" stroke-linecap="round"/>
        </svg>
        <svg class="mode-icon-dark" width="20" height="20" viewBox="0 0 20 20" fill="none">
          <path d="M15.5 10.5A6.5 6.5 0 0 1 9 4a6.5 6.5 0 1 0 6.5 6.5z" stroke="rgba(255,220,120,.90)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="rgba(255,220,120,.12)"/>
        </svg>
      </button>
      <div id="mapModeLabel">MAP</div>
    </div>
    <div class="top-pill" id="compassWrap">
      <div id="compassRing" onclick="recenter()">
        <span id="compassNeedle">🧭</span>
      </div>
      <div id="compassLabel">N</div>
    </div>
  </div>

  <!-- SPEED BUBBLE -->
  <div id="speedBubble">
    <div id="speedVal">0</div>
    <div id="speedUnit">km/h</div>
  </div>

  <!-- RECENTER -->
  <button id="recenterBtn" onclick="recenter()" title="Recenter map">
    <svg width="22" height="22" viewBox="0 0 22 22" fill="none" xmlns="http://www.w3.org/2000/svg">
      <circle cx="11" cy="11" r="4" stroke="#d45f10" stroke-width="1.8"/>
      <line x1="11" y1="1" x2="11" y2="5"  stroke="#d45f10" stroke-width="1.8" stroke-linecap="round"/>
      <line x1="11" y1="17" x2="11" y2="21" stroke="#d45f10" stroke-width="1.8" stroke-linecap="round"/>
      <line x1="1"  y1="11" x2="5"  y2="11" stroke="#d45f10" stroke-width="1.8" stroke-linecap="round"/>
      <line x1="17" y1="11" x2="21" y2="11" stroke="#d45f10" stroke-width="1.8" stroke-linecap="round"/>
    </svg>
  </button>

  <!-- SIDE TOGGLE BUTTON -->
  <button id="panelToggleBtn" onclick="togglePanel()" title="Toggle panel">
    <svg id="toggleArrow" viewBox="0 0 16 16">
      <polyline points="3,5 8,11 13,5"/>
    </svg>
  </button>

  <!-- BOTTOM PANEL -->
  <div id="bottomPanel">
    <div class="bottom-handle-wrap" id="panelHandle">
      <div class="bottom-handle"></div>
      <div class="handle-hint" id="handleHint">drag to hide</div>
    </div>

    <div id="destName" style="display:flex;align-items:center;gap:6px;">
      <svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg" style="flex-shrink:0;">
        <path d="M7 1C4.79 1 3 2.79 3 5c0 3.25 4 8 4 8s4-4.75 4-8c0-2.21-1.79-4-4-4Z" fill="#d45f10"/>
        <circle cx="7" cy="5" r="1.5" fill="#fff"/>
      </svg>
      <span>Select an evacuation center</span>
    </div>
    <div id="remainDist">We will suggest the nearest available center.</div>

    <div class="mode-label">Evacuation Centers (nearest first)</div>
    <div class="center-scroll-shell" id="centerScrollShell">
      <button type="button" class="center-scroll-hint top" id="centerHintTop" aria-label="Scroll up">
        <svg viewBox="0 0 24 24"><polyline points="6 14 12 8 18 14"/></svg>
      </button>
      <div id="centerList">Requesting your location…</div>
      <button type="button" class="center-scroll-hint bottom" id="centerHintBottom" aria-label="Scroll down">
        <svg viewBox="0 0 24 24"><polyline points="6 10 12 16 18 10"/></svg>
      </button>
    </div>

 


<div class="mode-label">Travel Mode</div>
<div class="mode-scroll-shell" id="modeScrollShell">
  <button type="button" class="mode-hint left" id="modeHintLeft" aria-label="Scroll modes left">
    <svg viewBox="0 0 24 24"><polyline points="14 6 8 12 14 18"/></svg>
  </button>
  <div id="modeSelector">
    <div class="mode-track" id="modeTrack">
      <div class="mode-liquid" id="modeLiquid"></div>
      <button class="mode-btn active" data-mode="walk" onclick="selectMode('walk')">
        <div class="mode-icon">
          <svg viewBox="0 0 24 24">
            <circle cx="13" cy="4" r="2"></circle>
            <path d="M15 22l-2-8-3 2-2 6"></path>
            <path d="M9 8l3-1 3 3 3-1"></path>
            <path d="M6 22l3-6-2-5"></path>
          </svg>
        </div>
        <span class="mode-name">Walk</span>
      </button>
      <button class="mode-btn" data-mode="bike" onclick="selectMode('bike')">
        <div class="mode-icon">
          <svg viewBox="0 0 24 24">
            <circle cx="5.5" cy="17.5" r="3.5"></circle>
            <circle cx="18.5" cy="17.5" r="3.5"></circle>
            <path d="M15 6a1 1 0 100-2 1 1 0 000 2z"></path>
            <path d="M12 17.5V14l-3-3 4-3 2 3h3"></path>
          </svg>
        </div>
        <span class="mode-name">Bike</span>
      </button>
      <button class="mode-btn" data-mode="tricycle" onclick="selectMode('tricycle')">
        <div class="mode-icon">
          <svg viewBox="0 0 24 24">
            <circle cx="6" cy="18" r="2.5"></circle>
            <circle cx="18" cy="18" r="2.5"></circle>
            <path d="M6 18h9V9H9l-3 5"></path>
            <path d="M9 9V5h3"></path>
            <path d="M15 18h4l-1-6h-3"></path>
          </svg>
        </div>
        <span class="mode-name">Tricycle</span>
      </button>
      <button class="mode-btn" data-mode="moto" onclick="selectMode('moto')">
        <div class="mode-icon">
          <svg viewBox="0 0 24 24">
            <circle cx="5" cy="17" r="3"></circle>
            <circle cx="19" cy="17" r="3"></circle>
            <path d="M5 17l2-7h5l3 4h4"></path>
            <path d="M10 10L8 6H5"></path>
          </svg>
        </div>
        <span class="mode-name">Moto</span>
      </button>
      <button class="mode-btn" data-mode="car" onclick="selectMode('car')">
        <div class="mode-icon">
          <svg viewBox="0 0 24 24">
            <path d="M5 17h14M5 17a2 2 0 100 4 2 2 0 000-4zM19 17a2 2 0 100 4 2 2 0 000-4z"></path>
            <path d="M5 17l1.5-5.5A2 2 0 018.4 10h7.2a2 2 0 011.9 1.5L19 17"></path>
            <path d="M3 13h18"></path>
          </svg>
        </div>
        <span class="mode-name">Car</span>
      </button>
    </div>
  </div>
  <button type="button" class="mode-hint right" id="modeHintRight" aria-label="Scroll modes right">
    <svg viewBox="0 0 24 24"><polyline points="10 6 16 12 10 18"/></svg>
  </button>
</div>

    <div class="mode-stats">
      <div class="stat-chip">
        <div class="stat-val" id="previewDist">--</div>
        <div class="stat-lbl">km</div>
      </div>
      <div class="stat-chip">
        <div class="stat-val" id="previewTime">--</div>
        <div class="stat-lbl">min ETA</div>
      </div>
      <div class="stat-chip">
        <div class="stat-val" id="previewSpeed">--</div>
        <div class="stat-lbl">avg km/h</div>
      </div>
    </div>

    <div class="traffic-legend">
      <div class="tleg"><div class="tleg-dot" style="background:#16a34a"></div>Free</div>
      <div class="tleg"><div class="tleg-dot" style="background:#b45309"></div>Slow</div>
      <div class="tleg"><div class="tleg-dot" style="background:#dc2626"></div>Jammed</div>
    </div>

    <button id="startBtn" class="nav-btn" onclick="startNavigation()">
      <div class="btn-glow-ring"></div>
      <div class="btn-shimmer"></div>
      <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" style="position:relative;z-index:1;">
        <path d="M8 2C10 2 13 3.5 13 8C13 11 11 13.5 8 14C5 13.5 3 11 3 8C3 3.5 6 2 8 2Z" stroke="#fff" stroke-width="1.4" fill="none"/>
        <path d="M8 2L9.5 5.5H12.5L10 7.5L11 11L8 9L5 11L6 7.5L3.5 5.5H6.5L8 2Z" fill="#fff" opacity="0.9"/>
      </svg>
      <span style="position:relative;z-index:1;">START NAVIGATION</span>
    </button>

    <!-- SLIDE-TO-END -->
    <div id="stopSlider" style="display:none;">
      <div class="slide-track">
        <div class="slide-fill" id="slideFill"></div>
        <div class="slide-thumb" id="slideThumb">
          <div class="slide-chevrons">
            <svg width="10" height="14" viewBox="0 0 10 14" fill="none">
              <path d="M2 2l6 5-6 5" stroke="#dc2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <svg width="10" height="14" viewBox="0 0 10 14" fill="none" style="opacity:.45">
              <path d="M2 2l6 5-6 5" stroke="#dc2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </div>
        </div>
        <div class="slide-label" id="slideLabel">
          <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
            <rect x="1.5" y="1.5" width="9" height="9" rx="2" fill="rgba(185,28,28,.65)"/>
          </svg>
          END NAVIGATION
          <div class="slide-dots"><span></span><span></span><span></span></div>
        </div>
      </div>
    </div>
    <button id="stopBtn" style="display:none;" onclick="_confirmStop()"></button>
  </div>

  <!-- ARRIVAL OVERLAY -->
  <div id="arrivalOverlay">
    <svg class="arrival-icon" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
      <circle cx="32" cy="32" r="30" fill="rgba(212,95,16,0.12)" stroke="#d45f10" stroke-width="2.5"/>
      <path d="M18 33L28 43L46 22" stroke="#d45f10" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
    <div class="arrival-title">Arrived!</div>
    <div class="arrival-sub">You've reached your destination</div>
    <button class="arrival-close" onclick="closeArrival()">
      <svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg" style="position:relative;z-index:1;">
        <path d="M2 7h10M7 2l5 5-5 5" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
      <span style="position:relative;z-index:1;">Done</span>
    </button>
  </div>

</div><!-- #app -->

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet-routing-machine@latest/dist/leaflet-routing-machine.js"></script>
<script src="https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.js"></script>
<script src="https://unpkg.com/@maplibre/maplibre-gl-leaflet@0.0.20/leaflet-maplibre-gl.js"></script>

<script>
// ═══════════════════════════════════════════════════════════════════
//  iOS FIX — VIEWPORT HEIGHT MANAGEMENT

// ═══════════════════════════════════════════════════════════════════
(function() {
  /**
   * setVh()
   * Sets --vh on the root element to 1% of the ACTUAL visible viewport height.
   * window.innerHeight on iOS correctly excludes the browser chrome (address bar,
   * toolbar) whereas 100vh does NOT — this is the root cause of the layout bug.
   */
  function setVh() {
    const vh = window.innerHeight * 0.01;
    document.documentElement.style.setProperty('--vh', vh + 'px');
  }

  // Run immediately on load
  setVh();

  // Re-run on regular resize (orientation change, desktop window resize)
  window.addEventListener('resize', setVh);

  /**
   * visualViewport API listener
   * On iOS, the visualViewport shrinks/grows as the toolbar collapses/expands
   * while scrolling. This fires more reliably than the regular resize event
   * for those toolbar changes.
   */
  if (window.visualViewport) {
    window.visualViewport.addEventListener('resize', setVh);
    window.visualViewport.addEventListener('scroll', setVh);
  }
})();

// ─── CONFIG ───────────────────────────────────────────────────────────────
const DEFAULT_DEST = {
  lat: 15.137222,
  lon: 120.976111,
  name: 'San Miguel National High School'
};
let destLat  = DEFAULT_DEST.lat;
let destLon  = DEFAULT_DEST.lon;
let destName = DEFAULT_DEST.name;

const REROUTE_COOLDOWN     = 15000;
const STATUS_POLL_INTERVAL = 30000;

const MODES = {
  walk:     { label:'Walking',    icon:'🚶', speed:5,  accentColor:'#d45f10', offRouteM:40 },
  bike:     { label:'Cycling',    icon:'🚲', speed:18, accentColor:'#d45f10', offRouteM:60 },
  tricycle: { label:'Tricycle',   icon:'🛺', speed:25, accentColor:'#d45f10', offRouteM:70 },
  moto:     { label:'Motorcycle', icon:'🏍️', speed:45, accentColor:'#d45f10', offRouteM:80 },
  car:      { label:'Driving',    icon:'🚗', speed:60, accentColor:'#d45f10', offRouteM:80 },
};

// ─── STATE ────────────────────────────────────────────────────────────────
let map, userMarker, routingControl, watchId, destMarker;
let compassHeading = 0, lastPosition = null;
let routeCoords = [], routeInstructions = [];
let currentStepIdx = 0;
let isNavigating = false, isOffRoute = false, isMapLocked = true;
let lastRerouteTime = 0;
let arrowLayers = [];
let selectedMode = 'walk';
let centers = [];
let userLoc  = null;
let selectedCenterId = null;
let statusPollTimer  = null;

// ─── TRACKING ─────────────────────────────────────────────────────────────
let _trackedCenterId = null;

function trackSelectCenter(centerId, centerName) {
  if (_trackedCenterId === centerId) return;
  _trackedCenterId = centerId;
  fetch('citizen_track_navigation.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify({ action: 'select', center_id: centerId }),
  }).catch(() => {});
  if (window.opener && !window.opener.closed) {
    try { window.opener.postMessage({ type:'evac_select', center_id:centerId, center_name:centerName }, window.location.origin); } catch(e){}
  }
}
function trackArrived() {
  _trackedCenterId = null;
  fetch('citizen_track_navigation.php', {
    method: 'POST', headers: { 'Content-Type':'application/json' }, credentials: 'same-origin',
    body: JSON.stringify({ action:'arrived' }),
  }).catch(()=>{});
  if (window.opener && !window.opener.closed) {
    try { window.opener.postMessage({ type:'evac_arrived' }, window.location.origin); } catch(e){}
  }
}
function trackCancel(useBeacon) {
  if (_trackedCenterId === null) return;
  _trackedCenterId = null;
  const payload = JSON.stringify({ action: 'cancel' });
  if (useBeacon && navigator.sendBeacon) {
    const fd = new FormData();
    fd.append('action', 'cancel');
    navigator.sendBeacon('citizen_track_navigation.php', fd);
  } else {
    fetch('citizen_track_navigation.php', {
      method: 'POST', headers: { 'Content-Type':'application/json' }, credentials: 'same-origin',
      body: payload,
    }).catch(()=>{});
  }
  if (window.opener && !window.opener.closed) {
    try { window.opener.postMessage({ type:'evac_cancel' }, window.location.origin); } catch(e){}
  }
}

window.addEventListener('pagehide', () => {
  if (_trackedCenterId !== null) {
    trackCancel(true);
  }
});

// ─── STATUS POLLING ───────────────────────────────────────────────────────
function startStatusPolling() {
  stopStatusPolling();
  statusPollTimer = setInterval(pollCenterStatus, STATUS_POLL_INTERVAL);
}
function stopStatusPolling() {
  if (statusPollTimer) { clearInterval(statusPollTimer); statusPollTimer = null; }
}
function pollCenterStatus() {
  if (!isNavigating || selectedCenterId === null) return;
  fetch('centers.php?action=list_available', { credentials:'same-origin' })
    .then(r => r.ok ? r.json() : null)
    .then(data => {
      if (!data || !data.ok) return;
      const freshMap = {};
      (data.centers || []).forEach(c => { freshMap[c.id] = c; });
      centers = centers.map(c => freshMap[c.id] ? Object.assign({}, c, freshMap[c.id]) : c);
      rebuildCenterList();
      const dest = centers.find(c => c.id === selectedCenterId);
      if (dest && dest.status === 'full') autoRerouteFromFull(dest.name);
    })
    .catch(()=>{});
}

// ─── AUTO-REROUTE ─────────────────────────────────────────────────────────
function autoRerouteFromFull(fullCenterName) {
  const ref  = userLoc || lastPosition;
  const next = centers.find(c => c.id !== selectedCenterId && c.status !== 'full' && c.status !== 'closed');
  if (!next) {
    showRerouteToast('⚠ ' + (fullCenterName||'Center') + ' is full. No other centers available right now.');
    return;
  }
  showRerouteToast((fullCenterName||'Center') + ' is full — rerouting to ' + next.name);
  speak((fullCenterName||'Your destination') + ' is now full. Rerouting to ' + next.name);
  setTimeout(() => {
    chooseCenter(next.id, false);
    if (isNavigating && ref) {
      clearLayers();
      if (routingControl) { map.removeControl(routingControl); routingControl = null; }
      currentStepIdx = 0; routeCoords = [];
      createOrUpdateRoute(ref.lat, ref.lon);
    }
  }, 1200);
}

// ─── REROUTE TOAST ────────────────────────────────────────────────────────
let toastTimer = null;
function showRerouteToast(msg) {
  const toast = document.getElementById('rerouteToast');
  document.getElementById('rerouteToastMsg').textContent = msg;
  toast.classList.add('show');
  if (toastTimer) clearTimeout(toastTimer);
  toastTimer = setTimeout(() => toast.classList.remove('show'), 5000);
}

// ─── PANEL DRAG ───────────────────────────────────────────────────────────
let panelCollapsed = false;
let dragStartY = 0;
let isDragging = false;

function syncToggleBtn() {
  const btn   = document.getElementById('panelToggleBtn');
  const panel = document.getElementById('bottomPanel');
  if (panelCollapsed) {
    btn.classList.add('collapsed');
    btn.style.bottom = '5rem';
    document.getElementById('speedBubble').style.bottom = '4.2rem';
    document.getElementById('recenterBtn').style.bottom = '4.2rem';
  } else {
    btn.classList.remove('collapsed');
    const panelH = panel.offsetHeight || 300;
    btn.style.bottom = (panelH - 30) + 'px';
    document.getElementById('speedBubble').style.bottom = '18rem';
    document.getElementById('recenterBtn').style.bottom = '18rem';
  }
}

function togglePanel() { snapPanel(!panelCollapsed); }

function initPanelDrag() {
  const panel  = document.getElementById('bottomPanel');
  const handle = document.getElementById('panelHandle');

  handle.addEventListener('click', () => { if (!isDragging && panelCollapsed) snapPanel(false); });

  handle.addEventListener('touchstart', e => {
    dragStartY = e.touches[0].clientY;
    isDragging = false;
    panel.classList.add('no-transition');
  }, { passive: true });

  handle.addEventListener('touchmove', e => {
    const dy = e.touches[0].clientY - dragStartY;
    if (Math.abs(dy) > 6) isDragging = true;
    if (!isDragging) return;
    const newBottom = panelCollapsed
      ? Math.max(-88, Math.min(0, -(dy / window.innerHeight * 100)))
      : Math.max(-88, Math.min(0, dy < 0 ? 0 : -(dy / window.innerHeight * 100)));
    panel.style.bottom = newBottom + '%';
  }, { passive: true });

  handle.addEventListener('touchend', e => {
    if (!isDragging) return;
    const dy = e.changedTouches[0].clientY - dragStartY;
    snapPanel(dy > 60 ? true : dy < -40 ? false : panelCollapsed);
    isDragging = false;
  }, { passive: true });

  handle.addEventListener('mousedown', e => {
    dragStartY = e.clientY; isDragging = false;
    panel.classList.add('no-transition');
    function onMove(e) {
      const dy = e.clientY - dragStartY;
      if (Math.abs(dy) > 6) isDragging = true;
      if (!isDragging) return;
      panel.style.bottom = Math.max(-88, Math.min(0, -(dy / window.innerHeight * 100))) + '%';
    }
    function onUp(e) {
      document.removeEventListener('mousemove', onMove);
      document.removeEventListener('mouseup', onUp);
      if (!isDragging) return;
      const dy = e.clientY - dragStartY;
      snapPanel(dy > 60 ? true : dy < -40 ? false : panelCollapsed);
      isDragging = false;
    }
    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup', onUp);
  });
}

window.snapPanel = function(collapsed) {
  panelCollapsed = collapsed;
  const panel = document.getElementById('bottomPanel');
  const hint  = document.getElementById('handleHint');
  panel.classList.remove('no-transition');
  if (collapsed) {
    panel.classList.add('collapsed'); panel.classList.remove('show');
    hint.textContent = 'tap to show';
  } else {
    panel.classList.remove('collapsed'); panel.classList.add('show');
    hint.textContent = 'drag to hide';
  }
  panel.style.bottom = '';
  requestAnimationFrame(() => setTimeout(syncToggleBtn, 520));
};
window.snapPanel = function(collapsed) {
  panelCollapsed = collapsed;
  const panel = document.getElementById('bottomPanel');
  const hint  = document.getElementById('handleHint');
  panel.classList.remove('no-transition');
  if (collapsed) {
    panel.classList.add('collapsed'); panel.classList.remove('show');
    hint.textContent = 'tap to show';
  } else {
    panel.classList.remove('collapsed'); panel.classList.add('show');
    hint.textContent = 'drag to hide';
  }
  panel.style.bottom = '';
  requestAnimationFrame(() => setTimeout(syncToggleBtn, 520));
};

// ─── SIMPLE LOCATION TOAST (parehong pattern gaya ng sign up) ─────────────
function showNavToast(message) {
  let toast = document.getElementById('navGeoToast');
  if (!toast) {
    toast = document.createElement('div');
    toast.id = 'navGeoToast';
    toast.style.cssText = 'position:fixed;left:50%;bottom:110px;transform:translate(-50%,20px);' +
      'background:rgba(20,20,20,.92);color:#fff;padding:10px 18px;border-radius:12px;' +
      'font-size:.78rem;font-weight:500;max-width:82%;text-align:center;z-index:9999;' +
      'opacity:0;transition:opacity .3s ease,transform .3s ease;pointer-events:none;';
    document.body.appendChild(toast);
  }
  toast.textContent = message;
  toast.style.opacity = '1';
  toast.style.transform = 'translate(-50%,0)';
  clearTimeout(toast._t);
  toast._t = setTimeout(() => {
    toast.style.opacity = '0';
    toast.style.transform = 'translate(-50%,20px)';
  }, 5000);
}

// ─── INIT ─────────────────────────────────────────────────────────────────
function initApp() {
  initMap();
  initCompass();
  initPanelDrag();
  window.snapPanel(false);
  initCenterScrollHints();
  initModeScrollHints();
  requestAnimationFrame(() => {
    moveModeLiquid(selectedMode || 'walk', false);
    updateModeScrollHints();
    setTimeout(() => {
      moveModeLiquid(selectedMode || 'walk', false);
      updateModeScrollHints();
    }, 280);
  });
  window.addEventListener('resize', () => {
    moveModeLiquid(selectedMode || 'walk', false);
    updateModeScrollHints();
    updateCenterScrollHints();
  });
  if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(
      pos => {
        userLoc = { lat: pos.coords.latitude, lon: pos.coords.longitude };
        updatePreview(userLoc.lat, userLoc.lon);
        loadCenters();
      },
      err => {
        if (err.code === err.PERMISSION_DENIED) {
          showNavToast('Naka-off ang Lokasyon. I-on ito sa Settings ng iyong phone para makita ang pinakamalapit na evacuation center.');
        }
        document.getElementById('centerList').textContent = 'Unable to get your location: ' + err.message;
        loadCenters();
      },
      { enableHighAccuracy: true }
    );
  } else {
    document.getElementById('centerList').textContent = 'Geolocation not supported on this device.';
    loadCenters();
  }
}
function initMap() {
  const mapEl = document.getElementById('map');

  /*
    iOS FIX: Use window.innerHeight (actual visible px) for map height
    instead of window.screen.height or any vh-based value.
    This is re-applied on resize via the window resize listener below.
  */
  function applyMapSize() {
    mapEl.style.cssText =
      'position:absolute!important;top:0!important;left:0!important;' +
      'width:'  + window.innerWidth  + 'px!important;' +
      'height:' + window.innerHeight + 'px!important;' +
      'z-index:0!important;';
  }
  applyMapSize();

  map = L.map('map', { zoomControl: false, maxZoom: 20 }).setView([destLat, destLon], 15);

  const BASEMAP_STYLES = {
    light: 'https://tiles.openfreemap.org/styles/liberty',
    dark:  'https://tiles.openfreemap.org/styles/dark',
  };
  const BASEMAP_ATTR = '&copy; <a href="https://openfreemap.org">OpenFreeMap</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';

  let currentTileMode = 'light';
  const basemapLayer = L.maplibreGL({
    style: BASEMAP_STYLES[currentTileMode],
    attribution: BASEMAP_ATTR,
  }).addTo(map);

  window.toggleMapMode = function() {
    currentTileMode = currentTileMode === 'light' ? 'dark' : 'light';
    const mlMap = basemapLayer.getMaplibreMap();
    if (mlMap) mlMap.setStyle(BASEMAP_STYLES[currentTileMode]);
    const btn = document.getElementById('mapModeBtn');
    const lbl = document.getElementById('mapModeLabel');
    if (currentTileMode === 'dark') { btn.classList.add('is-dark'); lbl.textContent = 'DARK'; }
    else                            { btn.classList.remove('is-dark'); lbl.textContent = 'MAP'; }
  };

  // Force tile refresh — fixes blank map on first load (especially iOS)
  setTimeout(() => map.invalidateSize(true), 200);
  setTimeout(() => map.invalidateSize(true), 800);

  updateDestinationMarker();
  map.on('dragstart', () => { isMapLocked = false; });

  window.addEventListener('resize', () => {
    applyMapSize();
    map.invalidateSize(true);
    syncToggleBtn();
  });

  /*
    iOS FIX: Also listen on visualViewport for toolbar collapse/expand.
    Safari fires this when the browser chrome animates in/out (e.g. when
    the user scrolls or the page first loads), which changes innerHeight.
  */
  if (window.visualViewport) {
    window.visualViewport.addEventListener('resize', () => {
      applyMapSize();
      map.invalidateSize(true);
    });
  }
}

// ─── CAPACITY HELPERS ─────────────────────────────────────────────────────
function getCapacityClass(center) {
  if (center.status === 'full')         return 'full';
  if (center.status === 'near_capacity')return 'near';
  return 'ok';
}
function getSlotsLabel(center) {
  const slots = Math.max(0, (center.max_capacity_people||0) - (center.current_occupancy||0));
  if (center.status === 'full')         return { text:'FULL — no slots',    cls:'full' };
  if (center.status === 'near_capacity')return { text:slots+' slots left',  cls:'near' };
  return { text:slots+' slots available', cls:'ok' };
}
function getOccupancyPct(center) {
  const max = center.max_capacity_people || 0;
  if (!max) return 0;
  return Math.min(100, Math.round((center.current_occupancy / max) * 100));
}

// ─── CENTER LIST SCROLL HINTS ─────────────────────────────────────────────
function updateCenterScrollHints() {
  const el = document.getElementById('centerList');
  const shell = document.getElementById('centerScrollShell');
  const top = document.getElementById('centerHintTop');
  const bot = document.getElementById('centerHintBottom');
  if (!el || !top || !bot) return;
  const max = el.scrollHeight - el.clientHeight;
  const canScroll = max > 6;
  const atTop = el.scrollTop <= 6;
  const atBot = el.scrollTop >= max - 6;
  if (shell) {
    shell.classList.toggle('can-scroll-up', canScroll && !atTop);
    shell.classList.toggle('can-scroll-down', canScroll && !atBot);
  }
  top.classList.toggle('show', canScroll && !atTop);
  bot.classList.toggle('show', canScroll && !atBot);
}

function initCenterScrollHints() {
  const el = document.getElementById('centerList');
  const top = document.getElementById('centerHintTop');
  const bot = document.getElementById('centerHintBottom');
  if (!el || el._scrollHintsBound) return;
  el._scrollHintsBound = true;
  el.addEventListener('scroll', updateCenterScrollHints, { passive: true });
  window.addEventListener('resize', updateCenterScrollHints);
  const step = () => Math.max(80, Math.round(el.clientHeight * 0.75));
  const bindTap = (btn, dir) => {
    if (!btn || btn._bound) return;
    btn._bound = true;
    const go = (e) => {
      e.preventDefault();
      e.stopPropagation();
      el.scrollBy({ top: dir * step(), behavior: 'smooth' });
    };
    btn.addEventListener('click', go);
    btn.addEventListener('pointerup', (e) => {
      if (e.pointerType === 'touch' || e.pointerType === 'pen') go(e);
    });
  };
  bindTap(top, -1);
  bindTap(bot, 1);
  updateCenterScrollHints();
}

// ─── BUILD CENTER LIST UI ─────────────────────────────────────────────────
function rebuildCenterList() {
  const listEl = document.getElementById('centerList');
  if (!centers.length) {
    listEl.textContent = 'No available evacuation centers at the moment.';
    requestAnimationFrame(updateCenterScrollHints);
    return;
  }

  const frag = document.createDocumentFragment();
  centers.forEach(c => {
    const km         = c.distanceM != null ? (c.distanceM/1000).toFixed(2) : '–';
    const isFull     = c.status === 'full';
    const capCls     = getCapacityClass(c);
    const slots      = getSlotsLabel(c);
    const pct        = getOccupancyPct(c);
    const isSelected = (c.id === selectedCenterId);

    let badgeHtml = '';
    if      (c.status==='available')     badgeHtml=`<span class="cbadge cbadge-available">Available</span>`;
    else if (c.status==='near_capacity') badgeHtml=`<span class="cbadge cbadge-near">Near Full</span>`;
    else if (c.status==='full')          badgeHtml=`<span class="cbadge cbadge-full">Full</span>`;
    else if (c.status==='temp_shelter')  badgeHtml=`<span class="cbadge cbadge-temp">Temp Shelter</span>`;

    const div = document.createElement('div');
    div.className = 'center-item' + (isFull?' is-full':'') + (isSelected?' selected':'');
    div.dataset.centerId = c.id;
    if (!isFull) div.onclick = () => chooseCenter(c.id);

    const coordInfo =
      `<svg width="11" height="11" viewBox="0 0 12 12" fill="none" style="flex-shrink:0;">
        <circle cx="6" cy="4" r="2.2" stroke="#d45f10" stroke-width="1.3"/>
        <path d="M1.5 10.5C1.5 8.57 3.57 7 6 7s4.5 1.57 4.5 3.5" stroke="#d45f10" stroke-width="1.3" stroke-linecap="round" fill="none"/>
      </svg>
      <span style="color:var(--accent);font-weight:600;">${c.coordinator_name??'Unassigned'}</span>`
      + (c.coordinator_contact
          ? `<svg width="11" height="11" viewBox="0 0 12 12" fill="none" style="flex-shrink:0;margin-left:3px;">
              <path d="M2 2.5C2 2.5 3 4.5 4.5 6S9.5 10 9.5 10l1-1.5-1.5-1.5-1 1C7.5 8 5 5.5 4.5 4.5l1-1L4 2 2 2.5Z" stroke="#d45f10" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
            </svg>
            <span style="color:var(--accent);">${c.coordinator_contact}</span>`
          : '');

    div.innerHTML = `
      <div class="center-body">
        <div class="center-name">${c.name}</div>
        <div class="center-badges">${badgeHtml}</div>
        <div class="center-sub" style="margin-top:2px;">
          <svg width="10" height="10" viewBox="0 0 14 14" fill="none" style="flex-shrink:0;">
            <path d="M7 1C4.79 1 3 2.79 3 5c0 3.25 4 8 4 8s4-4.75 4-8c0-2.21-1.79-4-4-4Z" fill="#d45f10"/>
          </svg>
          <span>${c.barangay}</span>
        </div>
        <div class="center-sub" style="margin-top:1px;">${coordInfo}</div>
        <div class="cap-bar-wrap">
          <div class="cap-bar-track">
            <div class="cap-bar-fill fill-${capCls}" style="width:${pct}%"></div>
          </div>
          <div class="cap-label">
            <span class="slots ${slots.cls}">${slots.text}</span>
            <span>${c.current_occupancy??0} / ${c.max_capacity_people??'?'} people</span>
          </div>
        </div>
      </div>
      <div class="center-meta">
        <div class="center-distance">${km} km</div>
        <div class="center-status-text center-status-${c.status}">
          ${c.status.replace(/_/g,' ').toUpperCase()}
        </div>
      </div>
    `;
    frag.appendChild(div);
  });
  listEl.innerHTML = '';
  listEl.appendChild(frag);
  requestAnimationFrame(() => {
    initCenterScrollHints();
    updateCenterScrollHints();
  });
}

// ─── LOAD CENTERS FROM API ────────────────────────────────────────────────
function loadCenters() {
  const listEl = document.getElementById('centerList');
  listEl.textContent = 'Loading available centers…';
  fetch('centers.php?action=list_available', { credentials:'same-origin' })
    .then(r => r.ok ? r.json() : Promise.reject(new Error('Failed to load centers')))
    .then(data => {
      if (!data.ok) throw new Error(data.error||'Failed to load centers');
      centers = data.centers || [];
      if (userLoc) {
        centers.forEach(c => { c.distanceM = getDist(userLoc.lat, userLoc.lon, c.lat, c.lng); });
        centers.sort((a,b) => {
          const aF = a.status==='full'?1:0, bF = b.status==='full'?1:0;
          if (aF !== bF) return aF - bF;
          return (a.distanceM||Infinity) - (b.distanceM||Infinity);
        });
      }
      if (!centers.length) { listEl.textContent = 'No available evacuation centers at the moment.'; return; }
      rebuildCenterList();
      const first = centers.find(c => c.status !== 'full');
      if (first) { chooseCenter(first.id, false); }
      else { chooseCenter(centers[0].id, false); showRerouteToast('⚠ All centers are currently full. Please contact MDRRMO.'); }
      setTimeout(syncToggleBtn, 100);
    })
    .catch(err => { listEl.textContent = 'Unable to load centers: ' + err.message; });
}

// ─── CHOOSE CENTER ────────────────────────────────────────────────────────
function chooseCenter(centerId, speakIt = true) {
  const center = centers.find(c => c.id == centerId);
  if (!center || center.status === 'full') return;
  selectedCenterId = center.id;
  destLat  = center.lat;
  destLon  = center.lng;
  destName = center.name;
  document.getElementById('destName').innerHTML = `
    <svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg" style="flex-shrink:0;">
      <path d="M7 1C4.79 1 3 2.79 3 5c0 3.25 4 8 4 8s4-4.75 4-8c0-2.21-1.79-4-4-4Z" fill="#d45f10"/>
      <circle cx="7" cy="5" r="1.5" fill="#fff"/>
    </svg>
    <span>${center.name} (${center.barangay})</span>
  `;
  document.querySelectorAll('.center-item').forEach(el => {
    el.classList.toggle('selected', el.dataset.centerId == centerId);
  });
  updateDestinationMarker();
  if (userLoc) updatePreview(userLoc.lat, userLoc.lon);
  if (speakIt) speak('Destination set to ' + center.name);
  if (isNavigating) trackSelectCenter(center.id, center.name);
}

function updateDestinationMarker() {
  if (!map) return;
  const destIcon = L.divIcon({
    className: '',
    html: `<div class="dest-pin-head">
             <svg class="dest-pin-icon" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
               <rect x="3" y="9" width="14" height="9" rx="1" stroke="#fff" stroke-width="1.4" fill="none"/>
               <path d="M1 10L10 4L19 10" stroke="#fff" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
               <rect x="8" y="13" width="4" height="5" rx="0.5" stroke="#fff" stroke-width="1.2" fill="none"/>
             </svg>
           </div>`,
    iconSize: [32, 40], iconAnchor: [16, 40]
  });
  if (destMarker) { destMarker.setLatLng([destLat,destLon]); destMarker.setPopupContent('<b>'+destName+'</b>'); }
  else { destMarker = L.marker([destLat,destLon],{icon:destIcon}).addTo(map).bindPopup('<b>'+destName+'</b>'); }
}

// ─── MODE SELECTOR ────────────────────────────────────────────────────────
function buildUserIconHtml(mode) {
  const svgs = {
    walk: '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="13" cy="4" r="2"/><path d="M15 22l-2-8-3 2-2 6"/><path d="M9 8l3-1 3 3 3-1"/><path d="M6 22l3-6-2-5"/></svg>',
    bike: '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="5.5" cy="17.5" r="3.5"/><circle cx="18.5" cy="17.5" r="3.5"/><path d="M15 6a1 1 0 100-2 1 1 0 000 2z"/><path d="M12 17.5V14l-3-3 4-3 2 3h3"/></svg>',
    tricycle: '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="18" r="2.5"/><circle cx="18" cy="18" r="2.5"/><path d="M6 18h9V9H9l-3 5"/><path d="M9 9V5h3"/><path d="M15 18h4l-1-6h-3"/></svg>',
    moto: '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="5" cy="17" r="3"/><circle cx="19" cy="17" r="3"/><path d="M5 17l2-7h5l3 4h4"/><path d="M10 10L8 6H5"/></svg>',
    car: '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 17h14M5 17a2 2 0 100 4 2 2 0 000-4zM19 17a2 2 0 100 4 2 2 0 000-4z"/><path d="M5 17l1.5-5.5A2 2 0 018.4 10h7.2a2 2 0 011.9 1.5L19 17"/><path d="M3 13h18"/></svg>',
  };
  const svg = svgs[mode] || svgs.walk;
  return `<div class="user-dot-wrap">
            <div class="user-halo"></div>
            <div class="user-dot" style="display:flex;align-items:center;justify-content:center;">${svg}</div>
            <div class="user-pip"></div>
          </div>`;
}

// ─── MODE SELECTOR (liquid + scroll hints) ────────────────────────────────
const MODE_ORDER = ['walk', 'bike', 'tricycle', 'moto', 'car'];
let _liquidLeft = null;

function updateModeScrollHints() {
  const el = document.getElementById('modeSelector');
  const shell = document.getElementById('modeScrollShell');
  const left = document.getElementById('modeHintLeft');
  const right = document.getElementById('modeHintRight');
  if (!el || !left || !right) return;
  const max = el.scrollWidth - el.clientWidth;
  const can = max > 6;
  const atL = el.scrollLeft <= 6;
  const atR = el.scrollLeft >= max - 6;
  if (shell) {
    shell.classList.toggle('can-scroll-left', can && !atL);
    shell.classList.toggle('can-scroll-right', can && !atR);
  }
  left.classList.toggle('show', can && !atL);
  right.classList.toggle('show', can && !atR);
}

function initModeScrollHints() {
  const el = document.getElementById('modeSelector');
  const left = document.getElementById('modeHintLeft');
  const right = document.getElementById('modeHintRight');
  if (!el || el._hintsBound) return;
  el._hintsBound = true;
  el.addEventListener('scroll', updateModeScrollHints, { passive: true });
  const step = () => Math.max(100, Math.round(el.clientWidth * 0.7));
  const bindTap = (btn, dir) => {
    if (!btn || btn._bound) return;
    btn._bound = true;
    const go = (e) => {
      e.preventDefault();
      e.stopPropagation();
      el.scrollBy({ left: dir * step(), behavior: 'smooth' });
    };
    btn.addEventListener('click', go);
    btn.addEventListener('pointerup', (e) => {
      if (e.pointerType === 'touch' || e.pointerType === 'pen') go(e);
    });
  };
  bindTap(left, -1);
  bindTap(right, 1);
  updateModeScrollHints();
}

function moveModeLiquid(mode, withPop) {
  const liquid = document.getElementById('modeLiquid');
  const btn = document.querySelector(`.mode-btn[data-mode="${mode}"]`);
  if (!liquid || !btn) return;
  const left = btn.offsetLeft;
  const width = btn.offsetWidth;
  if (!withPop) {
    liquid.style.width = width + 'px';
    liquid.style.transform = `translateX(${left}px)`;
    _liquidLeft = left;
    return;
  }
  const prev = _liquidLeft != null ? _liquidLeft : left;
  const dir = left >= prev ? 1 : -1;
  const dist = Math.abs(left - prev);
  const stretch = 1.08 + Math.min(0.18, dist / 280);
  liquid.classList.remove('is-morphing-left');
  liquid.classList.add('is-morphing');
  if (dir < 0) liquid.classList.add('is-morphing-left');
  liquid.style.width = (width * stretch) + 'px';
  liquid.style.transform = `translateX(${left - dir * 6}px) scaleY(0.92) scaleX(1.04)`;
  liquid.style.boxShadow = '0 10px 28px rgba(212,95,16,.55), 0 2px 0 rgba(255,210,160,.5) inset, 0 -2px 0 rgba(0,0,0,.15) inset';
  requestAnimationFrame(() => {
    requestAnimationFrame(() => {
      liquid.style.width = width + 'px';
      liquid.style.transform = `translateX(${left}px) scaleY(1) scaleX(1)`;
      _liquidLeft = left;
    });
  });
  clearTimeout(liquid._t);
  liquid._t = setTimeout(() => {
    liquid.classList.remove('is-morphing', 'is-morphing-left');
    liquid.style.boxShadow = '';
  }, 560);
}

function selectMode(mode) {
  if (isNavigating) return;
  if (mode === selectedMode) {
    moveModeLiquid(mode, false);
    return;
  }
  selectedMode = mode;
  document.querySelectorAll('.mode-btn').forEach(b => b.classList.remove('active'));
  const activeBtn = document.querySelector(`[data-mode="${mode}"]`);
  if (activeBtn) {
    activeBtn.classList.add('active');
    activeBtn.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
  }
  document.documentElement.style.setProperty('--accent', MODES[mode].accentColor);
  moveModeLiquid(mode, true);
  setTimeout(updateModeScrollHints, 320);
  if (userMarker) {
    userMarker.setIcon(L.divIcon({
      className: '',
      html: buildUserIconHtml(selectedMode),
      iconSize: [40, 40],
      iconAnchor: [20, 20]
    }));
  }
  if (lastPosition) updatePreview(lastPosition.lat, lastPosition.lon);
}

function updatePreview(lat, lon) {
  const dist = getDist(lat, lon, destLat, destLon);
  const km   = (dist/1000).toFixed(2);
  const spd  = MODES[selectedMode].speed;
  const eta  = Math.round((dist/1000)/spd*60);
  document.getElementById('previewDist').textContent  = km;
  document.getElementById('previewTime').textContent  = eta;
  document.getElementById('previewSpeed').textContent = spd;
  document.getElementById('remainDist').textContent   = `~${km} km · ~${eta} min`;
}

// ─── COMPASS ──────────────────────────────────────────────────────────────
function initCompass() {
  if (!window.DeviceOrientationEvent) return;
  const attach = () => window.addEventListener('deviceorientation', handleOrientation);
  if (typeof DeviceOrientationEvent.requestPermission === 'function') {
    DeviceOrientationEvent.requestPermission().then(s => { if (s==='granted') attach(); }).catch(()=>{});
  } else { attach(); }
}
function handleOrientation(e) {
  let h = null;
  if (e.webkitCompassHeading != null) h = e.webkitCompassHeading;
  else if (e.alpha != null) h = (360 - e.alpha) % 360;
  if (h == null) return;
  compassHeading = h;
  document.getElementById('compassNeedle').style.transform = `rotate(${-h}deg)`;
  document.getElementById('compassLabel').style.color = (h<20||h>340) ? '#dc2626' : '#9c9288';
}

// ─── START NAVIGATION ─────────────────────────────────────────────────────
function startNavigation() {
  if (!navigator.geolocation) return alert('Geolocation not supported');
  isNavigating = true;
  document.querySelectorAll('.mode-btn').forEach(b => { b.style.opacity='0.45'; b.style.pointerEvents='none'; });
  document.getElementById('startBtn').style.display = 'none';
  window._showStopSlider && window._showStopSlider();
  document.getElementById('dirCard').classList.add('show');
  document.getElementById('turnInstruction').textContent = 'Getting location…';
  watchId = navigator.geolocation.watchPosition(onPosition, onGeoError, {
    enableHighAccuracy: true, maximumAge: 0, timeout: 8000
  });
  startStatusPolling();
  const center = centers.find(c => c.id == selectedCenterId);
  if (center) trackSelectCenter(center.id, center.name);
}

// ─── SLIDE-TO-END ─────────────────────────────────────────────────────────
(function() {
  let dragging=false, startX=0, thumbLeft=0, trackW=0, thumbW=0;

  function getEls() {
    return {
      track: document.getElementById('stopSlider')?.querySelector('.slide-track'),
      thumb: document.getElementById('slideThumb'),
      fill:  document.getElementById('slideFill'),
      label: document.getElementById('slideLabel'),
    };
  }
  function resetSlider() {
    const {thumb,fill,label} = getEls();
    if (!thumb) return;
    dragging = false;
    thumb.style.left = '4px';
    thumb.classList.remove('confirmed');
    fill.style.width = '0%';
    label.style.opacity = '1';
  }
  function onMove(clientX) {
    if (!dragging) return;
    const {thumb,fill,label} = getEls();
    const maxLeft = trackW - thumbW - 4;
    const newLeft = Math.max(4, Math.min(maxLeft, clientX - startX + thumbLeft));
    thumb.style.left = newLeft + 'px';
    const pct = ((newLeft-4)/(maxLeft-4))*100;
    fill.style.width = pct + '%';
    fill.classList.toggle('active', pct>5);
    label.style.opacity   = pct>20 ? Math.max(0,1-(pct-20)/35) : '1';
    label.style.transform = pct>20 ? `translateX(${Math.min(pct*0.18,12)}px)` : 'translateX(0)';
    if (newLeft >= maxLeft-2) {
      dragging = false;
      thumb.classList.add('confirmed');
      label.style.opacity = '0';
      setTimeout(() => _confirmStop(), 300);
    }
  }
  function initSlider() {
    const {track,thumb} = getEls();
    if (!track||!thumb) return;
    thumb.addEventListener('touchstart', e => {
      e.preventDefault();
      dragging=true; thumbLeft=thumb.offsetLeft; trackW=track.offsetWidth; thumbW=thumb.offsetWidth; startX=e.touches[0].clientX;
    }, {passive:false});
    document.addEventListener('touchmove', e => { if (dragging){e.preventDefault();onMove(e.touches[0].clientX);} }, {passive:false});
    document.addEventListener('touchend',  () => { if (dragging){dragging=false;resetSlider();} });
    thumb.addEventListener('mousedown', e => {
      dragging=true; thumbLeft=thumb.offsetLeft; trackW=track.offsetWidth; thumbW=thumb.offsetWidth; startX=e.clientX;
    });
    document.addEventListener('mousemove', e => { if (dragging) onMove(e.clientX); });
    document.addEventListener('mouseup',   () => { if (dragging){dragging=false;resetSlider();} });
  }
  window._showStopSlider = function() {
    const s = document.getElementById('stopSlider');
    if (s) { s.style.display='block'; resetSlider(); initSlider(); }
  };
  window._hideStopSlider = function() {
    const s = document.getElementById('stopSlider');
    if (s) { s.style.display='none'; resetSlider(); }
  };
})();

function _confirmStop() {
  window._hideStopSlider && window._hideStopSlider();
  isNavigating = false;
  stopStatusPolling();
  if (watchId) { navigator.geolocation.clearWatch(watchId); watchId = null; }
  if (routingControl) { map.removeControl(routingControl); routingControl = null; }
  clearLayers();
  document.querySelectorAll('.mode-btn').forEach(b => { b.style.opacity='1'; b.style.pointerEvents='auto'; });
  document.getElementById('startBtn').style.display = 'flex';
  document.getElementById('dirCard').classList.remove('show');
  document.getElementById('offrouteBanner').classList.remove('show');
  document.getElementById('turnInstruction').textContent = 'Head toward destination';
  document.getElementById('stepDist').textContent        = 'Calculating…';
  document.getElementById('etaMin').textContent          = '--';
  currentStepIdx=0; isOffRoute=false; routeCoords=[]; routeInstructions=[];
  trackCancel();
}
function stopNavigation() { _confirmStop(); }

// ─── POSITION UPDATE ──────────────────────────────────────────────────────
function onPosition(pos) {
  const lat   = pos.coords.latitude;
  const lon   = pos.coords.longitude;
  const speed = pos.coords.speed ? pos.coords.speed*3.6 : 0;

  const sv = document.getElementById('speedVal');
  sv.textContent = Math.round(speed);
  const modeSpd = MODES[selectedMode].speed;
  sv.style.color = speed<3 ? 'var(--text)' : speed<modeSpd ? '#16a34a' : speed<modeSpd*1.4 ? '#b45309' : '#dc2626';

  if (userMarker) {
    userMarker.setLatLng([lat,lon]);
  } else {
    const icon = L.divIcon({
      className:'',
      html: buildUserIconHtml(selectedMode),
      iconSize:[40,40], iconAnchor:[20,20]
    });
    userMarker = L.marker([lat,lon],{icon,zIndexOffset:1000}).addTo(map);
  }

  if (lastPosition) {
    const bearing = getBearing(lastPosition.lat, lastPosition.lon, lat, lon);
    const el = userMarker.getElement();
    if (el) { const w = el.querySelector('.user-dot-wrap'); if (w) w.style.transform=`rotate(${bearing}deg)`; }
  }

  if (isMapLocked) map.setView([lat,lon], 17, {animate:true, duration:0.8});

  if (routeCoords.length>0) {
    const offDist = distanceToRoute(lat, lon, routeCoords);
    if (offDist > MODES[selectedMode].offRouteM) triggerOffRoute(lat,lon);
    else if (isOffRoute) { isOffRoute=false; document.getElementById('offrouteBanner').classList.remove('show'); }
  }

  updateCurrentStep(lat,lon);
  createOrUpdateRoute(lat,lon);

  if (getDist(lat,lon,destLat,destLon) < 20) { onArrival(); return; }

  const remDist = getDist(lat,lon,destLat,destLon);
  const remKm   = (remDist/1000).toFixed(1);
  const eta     = Math.round((remDist/1000)/MODES[selectedMode].speed*60);
  document.getElementById('remainDist').textContent = `${remKm} km remaining`;
  document.getElementById('etaMin').textContent     = eta;
  updatePreview(lat,lon);
  lastPosition = {lat,lon};
}

// ─── ROUTE ────────────────────────────────────────────────────────────────
function createOrUpdateRoute(lat, lon) {
  if (routingControl) { routingControl.setWaypoints([L.latLng(lat,lon), L.latLng(destLat,destLon)]); return; }
  routingControl = L.Routing.control({
    waypoints: [L.latLng(lat,lon), L.latLng(destLat,destLon)],
    lineOptions:       { styles:[{color:'transparent',weight:0}] },
    createMarker:      () => null,
    addWaypoints:      false,
    draggableWaypoints:false,
    fitSelectedRoutes: false,
    show:              false,
  }).addTo(map);
  routingControl.on('routesfound', e => {
    const route = e.routes[0];
    routeCoords       = route.coordinates;
    routeInstructions = route.instructions||[];
    drawTrafficRoute(routeCoords);
    drawRouteArrows(routeCoords);
    updateStepDisplay();
    if (lastPosition) updatePreview(lastPosition.lat, lastPosition.lon);
  });
}

function drawTrafficRoute(coords) {
  arrowLayers.filter(l=>l._isRoute).forEach(l=>map.removeLayer(l));
  const border = L.polyline(coords.map(c=>[c.lat,c.lng]),{color:'rgba(0,0,0,0.12)',weight:12,opacity:.5,lineCap:'round',lineJoin:'round'});
  border._isRoute=true; border.addTo(map); border.bringToBack(); arrowLayers.push(border);
  for (let i=1;i<coords.length;i++) {
    const p=coords[i-1],c=coords[i];
    const seed  = (i*7+Math.round(p.lat*1000))%10;
    const color = seed<5 ? '#16a34a' : seed<8 ? '#b45309' : '#dc2626';
    const seg = L.polyline([[p.lat,p.lng],[c.lat,c.lng]],{color,weight:7,opacity:.88,lineCap:'round',lineJoin:'round'});
    seg._isRoute=true; seg.addTo(map); arrowLayers.push(seg);
  }
}

function drawRouteArrows(coords) {
  arrowLayers.filter(l=>l._isArrow).forEach(l=>map.removeLayer(l));
  let accum=0;
  for (let i=1;i<coords.length;i++) {
    const p=coords[i-1],c=coords[i];
    accum += getDist(p.lat,p.lng,c.lat,c.lng);
    if (accum>=120) {
      accum=0;
      const bearing = getBearing(p.lat,p.lng,c.lat,c.lng);
      const mid  = [(p.lat+c.lat)/2,(p.lng+c.lng)/2];
      const icon = L.divIcon({
        className:'',
        html:`<svg width="16" height="16" viewBox="0 0 16 16" style="transform:rotate(${bearing}deg);filter:drop-shadow(0 1px 3px rgba(0,0,0,0.25))">
          <polygon points="8,1 14,13 8,10 2,13" fill="white" opacity="0.88"/>
        </svg>`,
        iconSize:[16,16],iconAnchor:[8,8]
      });
      const m = L.marker(mid,{icon,zIndexOffset:-50,interactive:false});
      m._isArrow=true; m.addTo(map); arrowLayers.push(m);
    }
  }
}

function clearLayers() { arrowLayers.forEach(l=>map.removeLayer(l)); arrowLayers=[]; }

// ─── TURN INSTRUCTIONS ────────────────────────────────────────────────────
const TURN_SVG = {
  Straight:   `<path d="M12 3v15M12 3L7 8M12 3L17 8" stroke="white" stroke-width="2.5" stroke-linecap="round" fill="none"/>`,
  Right:      `<path d="M7 20 Q7 10 17 5M17 5l-4 1M17 5l-1 4" stroke="white" stroke-width="2.5" stroke-linecap="round" fill="none"/>`,
  SlightRight:`<path d="M8 20 Q10 8 17 6M17 6l-4 0M17 6l0 4" stroke="white" stroke-width="2.5" stroke-linecap="round" fill="none"/>`,
  SharpRight: `<path d="M5 20 Q14 16 17 5M17 5l-4 2M17 5l-2 4" stroke="white" stroke-width="2.5" stroke-linecap="round" fill="none"/>`,
  Left:       `<path d="M17 20 Q17 10 7 5M7 5l4 1M7 5l1 4" stroke="white" stroke-width="2.5" stroke-linecap="round" fill="none"/>`,
  SlightLeft: `<path d="M16 20 Q14 8 7 6M7 6l4 0M7 6l0 4" stroke="white" stroke-width="2.5" stroke-linecap="round" fill="none"/>`,
  SharpLeft:  `<path d="M19 20 Q10 16 7 5M7 5l4 2M7 5l2 4" stroke="white" stroke-width="2.5" stroke-linecap="round" fill="none"/>`,
  Dest:       `<circle cx="12" cy="12" r="7" fill="none" stroke="white" stroke-width="2.5"/><circle cx="12" cy="12" r="3" fill="white"/>`,
};

function getTurnType(text) {
  if (!text) return 'Straight';
  const t = text.toLowerCase();
  if (t.includes('arrive')||t.includes('destination'))  return 'Dest';
  if (t.includes('sharp right'))                         return 'SharpRight';
  if (t.includes('slight right')||t.includes('bear right')||t.includes('keep right')) return 'SlightRight';
  if (t.includes('right'))                               return 'Right';
  if (t.includes('sharp left'))                          return 'SharpLeft';
  if (t.includes('slight left')||t.includes('bear left')||t.includes('keep left'))    return 'SlightLeft';
  if (t.includes('left'))                                return 'Left';
  return 'Straight';
}

function updateStepDisplay() {
  if (!routeInstructions.length) return;
  const step = routeInstructions[Math.min(currentStepIdx, routeInstructions.length-1)];
  document.getElementById('turnInstruction').textContent = step.text;
  document.getElementById('stepDist').textContent        = `In ${Math.round(step.distance)} m`;
  const type = getTurnType(step.text);
  document.getElementById('turnArrowSvg').innerHTML = TURN_SVG[type]||TURN_SVG.Straight;
  const isArrival = type==='Dest';
  document.getElementById('turnArrowBox').style.background = isArrival
    ? 'linear-gradient(145deg,#16a34a,#15803d)'
    : `linear-gradient(145deg,${MODES[selectedMode].accentColor},#c0391e)`;
}

function updateCurrentStep(lat, lon) {
  if (!routeInstructions.length) return;
  const remDist = getDist(lat,lon,destLat,destLon);
  for (let i=currentStepIdx+1;i<routeInstructions.length;i++) {
    if (remDist < routeInstructions[i].distance+50) {
      currentStepIdx=i; updateStepDisplay(); speak(routeInstructions[i].text); break;
    }
  }
}

// ─── OFF-ROUTE ────────────────────────────────────────────────────────────
function distanceToRoute(lat,lon,coords) {
  let min=Infinity;
  for (let i=1;i<coords.length;i++) {
    const d = ptSegDist(lat,lon,coords[i-1].lat,coords[i-1].lng,coords[i].lat,coords[i].lng);
    if (d<min) min=d;
  }
  return min;
}
function ptSegDist(px,py,ax,ay,bx,by) {
  const dx=bx-ax,dy=by-ay;
  if (!dx&&!dy) return getDist(px,py,ax,ay);
  const t=Math.max(0,Math.min(1,((px-ax)*dx+(py-ay)*dy)/(dx*dx+dy*dy)));
  return getDist(px,py,ax+t*dx,ay+t*dy);
}
function triggerOffRoute(lat,lon) {
  if (isOffRoute) return;
  isOffRoute=true;
  document.getElementById('offrouteBanner').classList.add('show');
  speak('Off route. Recalculating.');
  const now=Date.now();
  if (now-lastRerouteTime>REROUTE_COOLDOWN) { lastRerouteTime=now; setTimeout(()=>reroute(lat,lon),1500); }
}
function reroute(lat,lon) {
  if (!isNavigating) return;
  clearLayers();
  if (routingControl) { map.removeControl(routingControl); routingControl=null; }
  currentStepIdx=0; routeCoords=[];
  document.getElementById('offrouteBanner').classList.remove('show');
  isOffRoute=false;
  createOrUpdateRoute(lat,lon);
  speak('Route updated.');
}

// ─── ARRIVAL ──────────────────────────────────────────────────────────────
function onArrival() {
  speak(`You have arrived! Great ${MODES[selectedMode].label.toLowerCase()}!`);
  trackArrived();
  stopNavigation();
  document.getElementById('arrivalOverlay').classList.add('show');
}
function closeArrival() { document.getElementById('arrivalOverlay').classList.remove('show'); }

// ─── RECENTER ─────────────────────────────────────────────────────────────
function recenter() {
  isMapLocked=true;
  if (userMarker) map.flyTo(userMarker.getLatLng(), 17, {duration:0.8});
}

// ─── SPEECH ───────────────────────────────────────────────────────────────
function speak(text) {
  if (!window.speechSynthesis) return;
  window.speechSynthesis.cancel();
  const u=new SpeechSynthesisUtterance(text);
  u.lang='en-US'; u.rate=1.05;
  window.speechSynthesis.speak(u);
}

// ─── MATH ─────────────────────────────────────────────────────────────────
function getDist(lat1,lon1,lat2,lon2) {
  const R=6371e3,r=Math.PI/180;
  const p1=lat1*r,p2=lat2*r,dp=(lat2-lat1)*r,dl=(lon2-lon1)*r;
  const a=Math.sin(dp/2)**2+Math.cos(p1)*Math.cos(p2)*Math.sin(dl/2)**2;
  return R*2*Math.atan2(Math.sqrt(a),Math.sqrt(1-a));
}
function getBearing(lat1,lon1,lat2,lon2) {
  const r=Math.PI/180,f1=lat1*r,f2=lat2*r,dl=(lon2-lon1)*r;
  const y=Math.sin(dl)*Math.cos(f2),x=Math.cos(f1)*Math.sin(f2)-Math.sin(f1)*Math.cos(f2)*Math.cos(dl);
  return (Math.atan2(y,x)*180/Math.PI+360)%360;
}
function onGeoError(err) {
  if (err.code === err.PERMISSION_DENIED) {
    showNavToast('Naka-block ang Lokasyon. I-on ito sa Settings ng iyong phone.');
    document.getElementById('turnInstruction').textContent = '⚠ Naka-block ang Lokasyon — pumunta sa Settings para i-on';
    document.getElementById('stepDist').textContent = 'Kailangang i-allow ang location access';
    document.getElementById('turnArrowBox').style.background = 'linear-gradient(145deg,#dc2626,#991b1b)';
  } else {
    document.getElementById('turnInstruction').textContent = '⚠ ' + err.message;
  }
}

// ─── BOOT ─────────────────────────────────────────────────────────────────
initApp();
</script>
</body>
</html>