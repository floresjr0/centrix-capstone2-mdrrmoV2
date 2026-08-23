<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';

require_login();
$user = current_user();
$pdo  = db();

require_once __DIR__ . '/config.php';

$lat = 15.0828;
$lon = 120.9417;
$url = "https://api.openweathermap.org/data/2.5/weather?lat=$lat&lon=$lon&appid=" . WEATHER_API_KEY . "&units=metric";
$cacheFile = sys_get_temp_dir() . '/mdrrmo_weather.json';
$cacheTTL  = 600;

if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTTL) {
    $response = file_get_contents($cacheFile);
} else {
    $response = @file_get_contents($url);
    if ($response !== false) file_put_contents($cacheFile, $response);
}

$weather = null;
if ($response !== false) {
    $data = json_decode($response, true);
    if (!empty($data['main'])) {
        $temp      = $data['main']['temp'];
        $humidity  = $data['main']['humidity'];
        $condition = $data['weather'][0]['description'] ?? 'N/A';
        $owm_icon  = $data['weather'][0]['icon'] ?? '01d';
        $t = $temp; $rh = $humidity; $heatIndex = $t;
        if ($t >= 27 && $rh >= 40) {
            $heatIndex = -8.784695 + 1.61139411*$t + 2.338549*$rh
                - 0.14611605*$t*$rh - 0.012308094*($t*$t)
                - 0.016424828*($rh*$rh) + 0.002211732*($t*$t*$rh)
                + 0.00072546*($t*$rh*$rh) - 0.000003582*($t*$t*$rh*$rh);
        }
        $level = 'low';
        if ($heatIndex >= 42)     $level = 'extreme';
        elseif ($heatIndex >= 40) $level = 'high';
        elseif ($heatIndex >= 38) $level = 'medium';
        $weather = [
            'temp_c'         => $temp,
            'humidity'       => $humidity,
            'heat_index'     => $heatIndex,
            'condition_text' => $condition,
            'owm_icon'       => $owm_icon,
            'level'          => $level,
        ];
    }
}

$disasterStmt   = $pdo->query("SELECT * FROM disasters WHERE status = 'ongoing' ORDER BY level DESC, started_at DESC LIMIT 1");
$activeDisaster = $disasterStmt->fetch();

$advice = null;
if ($activeDisaster) {
    $type  = $activeDisaster['type'];
    $level = (int)$activeDisaster['level'];
    $stmt  = $pdo->prepare("SELECT * FROM ready_bag_templates WHERE disaster_type=? AND level_min<=? AND level_max>=? ORDER BY level_min DESC LIMIT 1");
    $stmt->execute([$type, $level, $level]);
    $advice = $stmt->fetch();
} elseif ($weather) {
    $type  = 'heat';
    $level = $weather['level'] === 'extreme' ? 4 : ($weather['level'] === 'high' ? 3 : ($weather['level'] === 'medium' ? 2 : 1));
    $stmt  = $pdo->prepare("SELECT * FROM ready_bag_templates WHERE disaster_type=? AND level_min<=? AND level_max>=? ORDER BY level_min DESC LIMIT 1");
    $stmt->execute([$type, $level, $level]);
    $advice = $stmt->fetch();
}

$annStmt = $pdo->query("SELECT a.*, d.title AS disaster_title FROM announcements a LEFT JOIN disasters d ON d.id = a.disaster_id ORDER BY a.is_pinned DESC, a.published_at DESC LIMIT 6");
$announcements = $annStmt->fetchAll();

$currentHour = (int)date('H');
$isNightTime = ($currentHour >= 18 || $currentHour < 6);

function wx_category(string $desc): string {
    $d = strtolower($desc);
    if (preg_match('/thunder|storm|lightning/', $d)) return 'storm';
    if (preg_match('/rain|drizzle|shower/',     $d)) return 'rain';
    if (preg_match('/snow|sleet|hail/',         $d)) return 'rain';
    if (preg_match('/fog|mist|haze|smoke/',     $d)) return 'fog';
    if (preg_match('/cloud|overcast/',          $d)) return 'cloudy';
    if (preg_match('/wind|breezy/',             $d)) return 'windy';
    return 'sunny';
}

function wx_colors(string $cat, bool $isNight): array {
    if ($isNight) {
        $map = [
            'sunny'  => ['#0f1729','#1a2a52','#2d4080','rgba(15,23,41,.6)'],
            'cloudy' => ['#0d1520','#1e2d3d','#2e4158','rgba(13,21,32,.55)'],
            'rain'   => ['#080e1e','#0f1f3d','#162d5a','rgba(8,14,30,.6)'],
            'storm'  => ['#060810','#0d1020','#161c30','rgba(6,8,16,.7)'],
            'fog'    => ['#111820','#1c2530','#283545','rgba(17,24,32,.5)'],
            'windy'  => ['#091218','#112233','#1a3040','rgba(9,18,24,.5)'],
        ];
        return $map[$cat] ?? $map['sunny'];
    } else {
        $map = [
            'sunny'  => ['#F97316','#FB923C','#FBBF24','rgba(249,115,22,.5)'],
            'cloudy' => ['#475569','#64748B','#94A3B8','rgba(71,85,105,.4)'],
            'rain'   => ['#1D4ED8','#2563EB','#3B82F6','rgba(29,78,216,.5)'],
            'storm'  => ['#0F172A','#1E293B','#334155','rgba(15,23,42,.6)'],
            'fog'    => ['#475569','#64748B','#94A3B8','rgba(71,85,105,.35)'],
            'windy'  => ['#047857','#059669','#34D399','rgba(4,120,87,.4)'],
        ];
        return $map[$cat] ?? $map['sunny'];
    }
}

function wx_mascot_html(string $cat, bool $isNight, string $p = 'm'): string {
    if ($isNight) {
        return <<<SVG
<svg viewBox="0 0 160 160" xmlns="http://www.w3.org/2000/svg" style="width:100%;height:100%;overflow:visible">
<defs>
  <radialGradient id="{$p}moon" cx="42%" cy="36%" r="62%">
    <stop offset="0%" stop-color="#FDFBF0"/><stop offset="42%" stop-color="#E8DEBB"/>
    <stop offset="68%" stop-color="#C8BFA0"/><stop offset="100%" stop-color="#6B6555"/>
  </radialGradient>
  <radialGradient id="{$p}limb" cx="68%" cy="58%" r="55%">
    <stop offset="0%" stop-color="rgba(15,12,30,.0)"/><stop offset="100%" stop-color="rgba(15,12,30,.58)"/>
  </radialGradient>
  <radialGradient id="{$p}spec" cx="34%" cy="28%" r="38%">
    <stop offset="0%" stop-color="rgba(255,255,245,.55)"/><stop offset="100%" stop-color="rgba(255,255,245,.0)"/>
  </radialGradient>
  <radialGradient id="{$p}cr" cx="35%" cy="30%" r="65%">
    <stop offset="0%" stop-color="rgba(255,250,230,.18)"/><stop offset="100%" stop-color="rgba(80,75,60,.45)"/>
  </radialGradient>
  <radialGradient id="{$p}iris" cx="38%" cy="32%" r="62%">
    <stop offset="0%" stop-color="#8fa8c8"/><stop offset="100%" stop-color="#1e3050"/>
  </radialGradient>
  <radialGradient id="{$p}pupil" cx="36%" cy="30%" r="65%">
    <stop offset="0%" stop-color="#1a2a3a"/><stop offset="100%" stop-color="#050a10"/>
  </radialGradient>
  <radialGradient id="{$p}mare" cx="50%" cy="50%" r="50%">
    <stop offset="0%" stop-color="rgba(90,82,65,.52)"/><stop offset="100%" stop-color="rgba(90,82,65,.0)"/>
  </radialGradient>
</defs>
<style>
  .{$p}bob{animation:{$p}bob 4.2s ease-in-out infinite;transform-origin:80px 78px}
  .{$p}blnk{animation:{$p}blink 5.5s ease-in-out infinite}
  @keyframes {$p}bob{0%,100%{transform:translateY(0)}50%{transform:translateY(-9px)}}
  @keyframes {$p}blink{0%,80%,100%{transform:scaleY(1)}86%,89%{transform:scaleY(.06)}}
</style>
<g class="{$p}bob">
  <circle cx="80" cy="78" r="44" fill="url(#{$p}moon)"/>
  <ellipse cx="72" cy="66" rx="13" ry="10" fill="url(#{$p}mare)" transform="rotate(-15 72 66)"/>
  <ellipse cx="92" cy="82" rx="10" ry="7" fill="url(#{$p}mare)" transform="rotate(20 92 82)"/>
  <ellipse cx="65" cy="88" rx="8" ry="5" fill="url(#{$p}mare)" transform="rotate(-8 65 88)"/>
  <circle cx="60" cy="96" r="7.5" fill="url(#{$p}cr)"/>
  <circle cx="101" cy="68" r="5.5" fill="url(#{$p}cr)"/>
  <circle cx="80" cy="78" r="44" fill="url(#{$p}limb)"/>
  <circle cx="80" cy="78" r="44" fill="url(#{$p}spec)"/>
  <g class="{$p}blnk" style="transform-origin:80px 74px">
    <ellipse cx="68" cy="74" rx="6.5" ry="7" fill="url(#{$p}iris)"/>
    <ellipse cx="68" cy="74" rx="4.2" ry="4.8" fill="url(#{$p}pupil)"/>
    <circle cx="65.8" cy="71.5" r="2" fill="rgba(255,255,255,.88)"/>
    <ellipse cx="92" cy="74" rx="6.5" ry="7" fill="url(#{$p}iris)"/>
    <ellipse cx="92" cy="74" rx="4.2" ry="4.8" fill="url(#{$p}pupil)"/>
    <circle cx="89.8" cy="71.5" r="2" fill="rgba(255,255,255,.88)"/>
  </g>
  <path d="M70 90 Q80 97 90 90" stroke="#7a6a50" stroke-width="2.8" fill="none" stroke-linecap="round"/>
</g>
</svg>
SVG;
    } else {
        return <<<SVG
<svg viewBox="0 0 160 160" xmlns="http://www.w3.org/2000/svg" style="width:100%;height:100%;overflow:visible">
<defs>
  <radialGradient id="{$p}sph" cx="38%" cy="32%" r="62%">
    <stop offset="0%" stop-color="#FFFDE7"/><stop offset="25%" stop-color="#FFE57F"/>
    <stop offset="60%" stop-color="#FFD600"/><stop offset="100%" stop-color="#F57C00"/>
  </radialGradient>
  <radialGradient id="{$p}cor" cx="50%" cy="50%" r="50%">
    <stop offset="55%" stop-color="#FFAB40" stop-opacity=".45"/><stop offset="100%" stop-color="#FFAB40" stop-opacity="0"/>
  </radialGradient>
  <linearGradient id="{$p}ray" x1="0" y1="0" x2="0" y2="1">
    <stop offset="0%" stop-color="#FFD600" stop-opacity="1"/><stop offset="100%" stop-color="#FF6F00" stop-opacity="0"/>
  </linearGradient>
  <radialGradient id="{$p}spec" cx="35%" cy="28%" r="40%">
    <stop offset="0%" stop-color="#FFFFFF" stop-opacity=".9"/><stop offset="100%" stop-color="#FFFFFF" stop-opacity="0"/>
  </radialGradient>
</defs>
<style>
  .{$p}rays{animation:{$p}spin 12s linear infinite;transform-origin:80px 80px}
  .{$p}bob{animation:{$p}bob 2.8s ease-in-out infinite;transform-origin:80px 80px}
  .{$p}blnk{animation:{$p}blnk 4s ease-in-out infinite}
  @keyframes {$p}spin{to{transform:rotate(360deg)}}
  @keyframes {$p}bob{0%,100%{transform:translateY(0)}50%{transform:translateY(-8px)}}
  @keyframes {$p}blnk{0%,88%,100%{transform:scaleY(1)}93%,96%{transform:scaleY(.08)}}
</style>
<circle cx="80" cy="80" r="68" fill="url(#{$p}cor)"/>
<g class="{$p}rays">
  <ellipse cx="80" cy="22" rx="4.5" ry="14" fill="url(#{$p}ray)" transform="rotate(0 80 80)"/>
  <ellipse cx="80" cy="22" rx="4.5" ry="14" fill="url(#{$p}ray)" transform="rotate(45 80 80)"/>
  <ellipse cx="80" cy="22" rx="4.5" ry="14" fill="url(#{$p}ray)" transform="rotate(90 80 80)"/>
  <ellipse cx="80" cy="22" rx="4.5" ry="14" fill="url(#{$p}ray)" transform="rotate(135 80 80)"/>
  <ellipse cx="80" cy="22" rx="4.5" ry="14" fill="url(#{$p}ray)" transform="rotate(180 80 80)"/>
  <ellipse cx="80" cy="22" rx="4.5" ry="14" fill="url(#{$p}ray)" transform="rotate(225 80 80)"/>
  <ellipse cx="80" cy="22" rx="4.5" ry="14" fill="url(#{$p}ray)" transform="rotate(270 80 80)"/>
  <ellipse cx="80" cy="22" rx="4.5" ry="14" fill="url(#{$p}ray)" transform="rotate(315 80 80)"/>
</g>
<g class="{$p}bob">
  <circle cx="80" cy="80" r="36" fill="url(#{$p}sph)"/>
  <ellipse cx="68" cy="68" rx="14" ry="9" fill="url(#{$p}spec)"/>
  <g class="{$p}blnk" style="transform-origin:80px 80px">
    <ellipse cx="70" cy="77" rx="4.5" ry="5.5" fill="#7B3700"/>
    <ellipse cx="90" cy="77" rx="4.5" ry="5.5" fill="#7B3700"/>
    <circle cx="72" cy="75" r="1.8" fill="#fff" opacity=".9"/>
    <circle cx="92" cy="75" r="1.8" fill="#fff" opacity=".9"/>
    <circle cx="71" cy="77" r="2.2" fill="#3E1A00"/>
    <circle cx="91" cy="77" r="2.2" fill="#3E1A00"/>
  </g>
  <path d="M68 88 Q80 98 92 88" stroke="#7B3700" stroke-width="3" fill="none" stroke-linecap="round"/>
</g>
</svg>
SVG;
    }
}

$wx_cat    = isset($weather) ? wx_category($weather['condition_text'] ?? '') : 'sunny';
$wx_colors = wx_colors($wx_cat, $isNightTime);
$wx_particles = $isNightTime
    ? ['sunny'=>['✨','⭐','🌙'],'cloudy'=>['☁','🌙','✦'],'rain'=>['💧','🌧','💦'],'storm'=>['⚡','🌩','💥'],'fog'=>['🌫','✦','👻'],'windy'=>['🌬','🍃','💨']]
    : ['sunny'=>['✨','☀','⭐'],'cloudy'=>['☁','💨','🌤'],'rain'=>['💧','🌧','💦'],'storm'=>['⚡','🌩','💥'],'fog'=>['👻','🌫','✦'],'windy'=>['🍃','🌬','💨']];
$wx_ptcls = $wx_particles[$wx_cat] ?? $wx_particles['sunny'];

require_once __DIR__ . '/notify.php';
maybeSendDisasterNotification($pdo);

$readyBagTitle   = $advice ? htmlspecialchars($advice['title']   ?? 'Ready Bag', ENT_QUOTES) : 'Ready Bag';
$readyBagMessage = $advice ? htmlspecialchars($advice['message'] ?? '',           ENT_QUOTES) : '';
$readyBagJson    = json_encode(['title'=>$readyBagTitle,'message'=>$readyBagMessage], JSON_UNESCAPED_UNICODE);

$disasterModalJson = json_encode($activeDisaster ? [
    'title'       => $activeDisaster['title']      ?? '',
    'type'        => $activeDisaster['type']        ?? '',
    'level'       => (int)($activeDisaster['level'] ?? 0),
    'description' => $activeDisaster['description'] ?? '',
    'status'      => $activeDisaster['status']      ?? '',
    'started_at'  => $activeDisaster['started_at']  ?? '',
] : null, JSON_UNESCAPED_UNICODE);

$disasterLevel = $activeDisaster ? (int)$activeDisaster['level'] : 0;
$disasterType  = $activeDisaster ? htmlspecialchars($activeDisaster['type'], ENT_QUOTES) : '';
$isMedianCo    = isset($_SERVER['HTTP_USER_AGENT']) && strpos($_SERVER['HTTP_USER_AGENT'], 'MedianWebView') !== false;

$announcementsJson = json_encode(array_map(function($a) {
    return [
        'title'         => $a['title']          ?? '',
        'body'          => $a['body']            ?? '',
        'is_pinned'     => (bool)($a['is_pinned'] ?? false),
        'disaster_title'=> $a['disaster_title']  ?? '',
        'published_at'  => $a['published_at']    ?? '',
    ];
}, $announcements), JSON_UNESCAPED_UNICODE);

$lvlLabel = ['1'=>'Low','2'=>'Moderate','3'=>'High','4'=>'Severe'];
$riskLabels = ['low'=>'LOW','medium'=>'MODERATE','high'=>'HIGH','extreme'=>'SEVERE'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Citizen Dashboard - MDRRMO San Ildefonso</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<link rel="stylesheet" href="../asset/css/userdashboard.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<?php if (!$isMedianCo): ?>
<script src="https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.page.js" defer></script>
<?php endif; ?>
<script>
<?php if ($isMedianCo): ?>
  window.MedialPush = window.MedialPush || {};
  window.MedialPush.onesignalAppId = "8704d450-f3b9-4bc8-a1a9-a376abd93131";
  function registerForPush() {
    if (window.MedialPush && window.MedialPush.registerForPushNotifications)
      window.MedialPush.registerForPushNotifications();
  }
  document.addEventListener('deviceready', function(){ registerForPush(); }, false);
  setTimeout(function(){ if(window.MedialPush&&window.MedialPush.registerForPushNotifications) registerForPush(); }, 1000);
<?php else: ?>
  window.OneSignalDeferred = window.OneSignalDeferred || [];
  OneSignalDeferred.push(async function(OneSignal) {
    await OneSignal.init({
      appId: "8704d450-f3b9-4bc8-a1a9-a376abd93131",
      serviceWorkerPath: "/OneSignalSDK.sw.js",
      promptOptions: { slidedown: { prompts: [{ type:"push", autoPrompt:true, text:{
        actionMessage:"Nais mong makatanggap ng alerto sa sakuna at matinding init mula sa MDRRMO San Ildefonso?",
        acceptButton:"Oo, payagan", cancelButton:"Hindi muna"
      }, delay:{ timeDelay:5, pageViews:1 }}]}},
    });
    const barangay = <?php echo json_encode($user['barangay_name'] ?? ''); ?>;
    const userId   = <?php echo json_encode((string)($user['id'] ?? '')); ?>;
    if (barangay) await OneSignal.User.addTag("barangay", barangay);
    if (userId)   await OneSignal.User.addTag("user_id", userId);
    await OneSignal.User.addTag("disaster_level", "<?php echo $disasterLevel; ?>");
  });
<?php endif; ?>
</script>
<style>
</style>
</head>
<body>

<!-- SIDEBAR OVERLAY -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>
<div class="sidebar-drawer" id="sidebarDrawer">
  <div class="drawer-brand">
    <div class="drawer-logo">
      <img src="../img/mdrrmo.png" alt="MDRRMO" onerror="this.style.display='none'">
      <svg viewBox="0 0 24 24"><path d="M12 2L3 7v5c0 5.25 3.75 10.15 9 11.35C17.25 22.15 21 17.25 21 12V7L12 2z"/></svg>
    </div>
    <div>
      <div class="drawer-brand-title">MDRRMO</div>
      <div class="drawer-brand-sub">San Ildefonso, Bulacan</div>
    </div>
  </div>
  <nav class="drawer-nav">
    <div class="drawer-nav-label">Menu</div>
    <div class="drawer-profile-row" onclick="openProfileAndCloseSidebar()">
      <div class="drawer-profile-avatar" id="drawerAvatar">?</div>
      <div>
        <div class="drawer-profile-name" id="drawerName">My Profile</div>
        <div class="drawer-profile-sub">Tap to edit profile & household</div>
      </div>
      <div class="drawer-profile-edit">Edit ›</div>
    </div>
    <a href="citizen_dashboard.php" class="drawer-nav-item active">
      <svg viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>Dashboard
    </a>
  </nav>
  <div class="drawer-footer">
    <a href="logout.php" class="drawer-logout">
      <svg viewBox="0 0 24 24"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5-5-5zM4 5h4V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg>Logout
    </a>
    <div class="drawer-partner-logos">
      <div class="drawer-partner-logo"><img src="../img/mdrrmo.png" alt="MDRRMO" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'"><svg viewBox="0 0 24 24" style="display:none"><path d="M12 2L3 7v5c0 5.25 3.75 10.15 9 11.35C17.25 22.15 21 17.25 21 12V7L12 2z"/></svg></div>
      <div class="drawer-partner-logo"><img src="../img/basc.png" alt="BASC" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'"><svg viewBox="0 0 24 24" style="display:none"><path d="M12 3L1 9l11 6 9-4.91V17h2V9L12 3zM5 13.18v4L12 21l7-3.82v-4L12 17l-7-3.82z"/></svg></div>
      <div class="drawer-partner-logo"><img src="../img/ics.jpg" alt="ICS" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'"><svg viewBox="0 0 24 24" style="display:none"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z"/></svg></div>
    </div>
    <div class="drawer-partner-credit">&copy; <?php echo date('Y'); ?> MDRRMOxBASC_ICS. All rights reserved.</div>
  </div>
</div>

<!-- PROFILE MODAL -->
<div class="profile-toast" id="profileToast"></div>
<div class="profile-backdrop" id="profileBackdrop" onclick="handleProfileBackdropClick(event)">
  <div class="profile-sheet" id="profileSheet">
    <div class="profile-handle-wrap" onclick="closeProfileModal()"><div class="profile-handle"></div></div>
    <div class="profile-head">
      <div class="profile-head-avatar" id="profileHeadAvatar">?</div>
      <div class="profile-head-info">
        <div class="profile-head-name" id="profileHeadName">Loading…</div>
        <div class="profile-head-role">Citizen · San Ildefonso</div>
        <div class="profile-head-brgy" id="profileHeadBrgy"></div>
      </div>
    </div>
    <div class="profile-section">
      <div class="profile-section-label">Personal Information</div>
      <div class="profile-field"><label>First Name</label><input type="text" id="pfFirstName" placeholder="Juan" maxlength="100"></div>
      <div class="profile-field"><label>Middle Name <span style="font-weight:400;color:#999">(optional)</span></label><input type="text" id="pfMiddleName" placeholder="Santos" maxlength="100"></div>
      <div class="profile-field"><label>Last Name</label><input type="text" id="pfLastName" placeholder="Dela Cruz" maxlength="100"></div>
      <div class="profile-field"><label>Suffix <span style="font-weight:400;color:#999">(optional)</span></label>
        <select id="pfSuffix"><option value="">— None —</option><option value="Jr.">Jr.</option><option value="Sr.">Sr.</option><option value="II">II</option><option value="III">III</option><option value="IV">IV</option><option value="V">V</option></select>
      </div>
      <div class="profile-field"><label>Contact Number</label><input type="tel" id="pfContact" placeholder="09XXXXXXXXX"></div>
      <div style="display:flex;gap:12px">
        <div class="profile-field" style="flex:1"><label>Birthday</label><input type="date" id="pfBirthday" max="<?php echo date('Y-m-d'); ?>"></div>
        <div class="profile-field" style="flex:1"><label>Sex</label>
          <select id="pfSex"><option value="">— Select —</option><option value="male">Male</option><option value="female">Female</option><option value="prefer_not_to_say">Prefer not to say</option></select>
        </div>
      </div>
      <div id="ageDisplayWrap"><span>Age: <span id="ageDisplay">—</span></span></div>
      <div class="profile-field"><label>Barangay</label><input type="text" id="pfBarangay" readonly></div>
      <div class="profile-field"><label>House No. / Street</label><input type="text" id="pfHouseNo" readonly></div>
    </div>
    <div class="profile-section"><div class="profile-section-label">Household Members</div></div>
    <div class="household-grid">
      <div class="hh-card"><div class="hh-card-label">Adults (18-59)</div><div class="hh-counter"><button type="button" class="hh-counter-btn" onclick="hhChange('adults',-1)">−</button><div class="hh-counter-val" id="hhAdults">1</div><button type="button" class="hh-counter-btn" onclick="hhChange('adults',1)">+</button></div></div>
      <div class="hh-card"><div class="hh-card-label">Children (&lt;18)</div><div class="hh-counter"><button type="button" class="hh-counter-btn" onclick="hhChange('children',-1)">−</button><div class="hh-counter-val" id="hhChildren">0</div><button type="button" class="hh-counter-btn" onclick="hhChange('children',1)">+</button></div></div>
      <div class="hh-card"><div class="hh-card-label">Senior Citizens (60+)</div><div class="hh-counter"><button type="button" class="hh-counter-btn" onclick="hhChange('seniors',-1)">−</button><div class="hh-counter-val" id="hhSeniors">0</div><button type="button" class="hh-counter-btn" onclick="hhChange('seniors',1)">+</button></div></div>
      <div class="hh-card"><div class="hh-card-label">PWD</div><div class="hh-counter"><button type="button" class="hh-counter-btn" onclick="hhChange('pwds',-1)">−</button><div class="hh-counter-val" id="hhPwds">0</div><button type="button" class="hh-counter-btn" onclick="hhChange('pwds',1)">+</button></div></div>
      <div class="hh-card"><div class="hh-card-label">Pregnant Women</div><div class="hh-counter"><button type="button" class="hh-counter-btn" onclick="hhChange('pregnant_women',-1)">−</button><div class="hh-counter-val" id="hhPregnantWomen">0</div><button type="button" class="hh-counter-btn" onclick="hhChange('pregnant_women',1)">+</button></div></div>
      <div class="hh-card"><div class="hh-card-label">Lactating / Breastfeeding</div><div class="hh-counter"><button type="button" class="hh-counter-btn" onclick="hhChange('lactating_mothers',-1)">−</button><div class="hh-counter-val" id="hhLactatingMothers">0</div><button type="button" class="hh-counter-btn" onclick="hhChange('lactating_mothers',1)">+</button></div></div>
      <div class="hh-card"><div class="hh-card-label">Infants / Toddlers</div><div class="hh-counter"><button type="button" class="hh-counter-btn" onclick="hhChange('infants_toddlers',-1)">−</button><div class="hh-counter-val" id="hhInfantsToddlers">0</div><button type="button" class="hh-counter-btn" onclick="hhChange('infants_toddlers',1)">+</button></div></div>
    </div>
    <div class="hh-total-banner">
      <div><div class="hh-total-label">Total Household Members</div><div style="font-size:.60rem;color:var(--muted)">Sent to coordinators when you evacuate</div></div>
      <div class="hh-total-val" id="hhTotal">1</div>
    </div>
    <button class="profile-save-btn" id="profileSaveBtn" onclick="saveProfile()">✓ Save Profile</button>
  </div>
</div>

<!-- READY BAG MODAL -->
<div class="rbmodal-backdrop" id="rbModalBackdrop" onclick="closeReadyBagModal(event)">
  <div class="rbmodal-sheet" id="rbModalSheet">
    <div class="rbmodal-head">
      <div class="rbmodal-handle"></div>
      <div class="rbmodal-head-row">
        
        <div><div class="rbmodal-head-title" id="rbModalTitle">Ready Bag</div><div class="rbmodal-head-sub">Suriin ang iyong mga gamit bago lumikas</div></div>
      </div>
    </div>
    <div class="rbmodal-progress-wrap">
      <div class="rbmodal-progress-label"><span>Progreso</span><span id="rbProgressText">0 / 0 nacheck</span></div>
      <div class="rbmodal-progress-bar"><div class="rbmodal-progress-fill" id="rbProgressFill"></div></div>
    </div>
    <div class="rbmodal-section-label">Mga Kailangan</div>
    <div id="rbChecklistWrap"></div>
    <button class="rbmodal-close-btn" onclick="closeReadyBagModal()">Naintindihan — Isara</button>
  </div>
</div>

<!-- DISASTER MODAL -->
<div class="dsmodal-backdrop" id="dsModalBackdrop" onclick="closeDisasterModal(event)">
  <div class="dsmodal-sheet" id="dsModalSheet">
    <div class="dsmodal-head">
      <div class="dsmodal-handle"></div>
      <div class="dsmodal-head-row">
        <div class="dsmodal-head-icon" id="dsModalIcon"></div>
        <div>
          <div class="dsmodal-head-title" id="dsModalTitle">Alerto sa Sakuna</div>
          <div class="dsmodal-head-type" id="dsModalType">Uri</div>
          <div class="dsmodal-level-badge"><div class="dsmodal-level-dot"></div><div class="dsmodal-level-text" id="dsModalLevelText">Signal #0</div></div>
        </div>
      </div>
    </div>
    <div class="dsmodal-body">
      <div class="dsmodal-section-label">Mga Detalye</div>
      <div class="dsmodal-chips" id="dsModalChips"></div>
      <div class="dsmodal-section-label">Paglalarawan</div>
      <div class="dsmodal-desc-box" id="dsModalDesc">Walang karagdagang impormasyon na available.</div>
    </div>
    <button class="dsmodal-close-btn" onclick="closeDisasterModal()">Naintindihan — Isara</button>
  </div>
</div>

<!-- ANNOUNCEMENT DETAIL MODAL -->
<div class="annmodal-backdrop" id="annModalBackdrop" onclick="closeAnnModal(event)">
  <div class="annmodal-sheet" id="annModalSheet">
    <div class="annmodal-head" id="annModalHead">
      <div class="annmodal-handle"></div>
      <div class="annmodal-head-row">
        <div class="annmodal-head-icon" id="annModalIcon"><svg viewBox="0 0 24 24"><path d="M18 11v2h4v-2h-4zm-2 6.61c.96.71 2.21 1.65 3.2 2.39.4-.53.8-1.07 1.2-1.6-.99-.74-2.24-1.68-3.2-2.4-.4.54-.8 1.08-1.2 1.61zM20.4 5.6c-.4-.53-.8-1.07-1.2-1.6-.99.74-2.24 1.68-3.2 2.4.4.53.8 1.07 1.2 1.6.96-.72 2.21-1.65 3.2-2.4zM4 9c-1.1 0-2 .9-2 2v2c0 1.1.9 2 2 2h1v4h2v-4h1l5 3V6L8 9H4zm11.5 3c0-1.33-.58-2.53-1.5-3.35v6.69c.92-.81 1.5-2.01 1.5-3.34z"/></svg></div>
        <div class="annmodal-head-title" id="annModalTitle">Anunsyo</div>
      </div>
      <div class="annmodal-head-meta" id="annModalMeta"></div>
    </div>
    <div class="annmodal-scroll-hint" id="annModalScrollHint">
      <svg viewBox="0 0 24 24"><path d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z"/></svg>
      I-scroll pababa para basahin lahat
    </div>
    <div class="annmodal-body" id="annModalBody">
      <div class="annmodal-body-label">Buong Anunsyo</div>
      <div class="annmodal-body-text" id="annModalText"></div>
    </div>
    <div class="annmodal-footer">
      <button class="annmodal-close-btn" onclick="closeAnnModal()">Naintindihan — Isara</button>
    </div>
  </div>
</div>

<!-- ══ MOBILE VIEW ══ -->
<div class="mobile-shell">
  <header class="topbar">
    <div class="topbar-logo"><img src="../img/mdrrmo.png" alt="MDRRMO" onerror="this.style.display='none'"><svg viewBox="0 0 24 24"><path d="M12 2L3 7v5c0 5.25 3.75 10.15 9 11.35C17.25 22.15 21 17.25 21 12V7L12 2z"/></svg></div>
    <div class="topbar-info">
      <div class="topbar-title">MDRRMO-San Ildefonso Bulacan</div>
      <div class="topbar-sub">Brgy. <?php echo htmlspecialchars($user['barangay_name'] ?? ''); ?> &nbsp;·&nbsp; <?php echo date('D, M j, Y'); ?></div>
    </div>
    <div class="topbar-avatar" onclick="openProfileAndCloseSidebar()" id="topbarAvatar">?</div>
    <button class="hamburger-btn" onclick="openSidebar()"><span></span><span></span><span></span></button>
  </header>

  <div class="page-scroll">
    <?php if ($activeDisaster): ?>
    <div class="alert-banner alert-typhoon anim-in" id="current-alerts" onclick="openDisasterModal()" role="button">
      
      <div class="alert-text">
        <div class="alert-title"><?php echo htmlspecialchars(ucfirst($activeDisaster['type'])); ?> Signal#<?php echo (int)$activeDisaster['level']; ?> — Aktibo</div>
        <div class="alert-sub"><?php echo ($lvlLabel[(string)(int)$activeDisaster['level']] ?? 'Moderate').' na antas ng panganib · Pindutin para sa detalye'; ?></div>
      </div>
      <div class="alert-pulse-wrap"><div class="alert-pulse-dot"></div></div>
    </div>
    <?php if ($advice): ?>
    <div class="readybag-card anim-in" onclick="openReadyBagModal()" role="button">
      <div class="readybag-tap-hint">Pindutin para makita ›</div>
    
      <div><div class="readybag-title">Payo sa Ready Bag</div><div class="readybag-text"><?php echo htmlspecialchars(mb_substr($advice['message'],0,100)); ?>…</div></div>
    </div>
    <?php endif; ?>
    <?php elseif ($weather && ($weather['level']==='high'||$weather['level']==='extreme')): ?>
    <div class="alert-banner alert-level-3 anim-in" id="current-alerts">
      <div class="alert-icon">🌡️</div>
      <div class="alert-text">
        <div class="alert-title">ALERTO SA INIT — Heat Index: <?php echo round($weather['heat_index']); ?>°C</div>
        <div class="alert-sub">Uminom ng maraming tubig at iwasang lumabas · Manatiling ligtas</div>
      </div>
      <div class="alert-pulse-wrap"><div class="alert-pulse-dot"></div></div>
    </div>
    <?php if ($advice): ?>
    <div class="readybag-card anim-in" onclick="openReadyBagModal()" role="button">
      <div class="readybag-tap-hint">Pindutin para makita ›</div>
      
      <div><div class="readybag-title">Payo sa Ready Bag</div><div class="readybag-text"><?php echo htmlspecialchars(mb_substr($advice['message'],0,100)); ?>…</div></div>
    </div>
    <?php endif; ?>
    <?php else: ?>
    <div class="alert-banner alert-none anim-in" id="current-alerts">
      <div class="alert-icon">✅</div>
      <div class="alert-text">
        <div class="alert-title">Walang aktibong sakuna sa ngayon</div>
        <div class="alert-sub">Manatiling handa at subaybayan ang mga update</div>
      </div>
    </div>
    <?php endif; ?>

    <div class="section-header anim-in"><h2>Weather Forecast</h2></div>
    <?php if ($weather): ?>
    <div class="weather-card anim-in">
      <div class="weather-banner" style="background:linear-gradient(150deg,<?php echo $wx_colors[0]; ?> 0%,<?php echo $wx_colors[1]; ?> 48%,<?php echo $wx_colors[2]; ?> 100%);">
        <div class="weather-top-row">
          <div class="weather-left">
            <div class="weather-temp-big"><?php echo round($weather['temp_c']); ?><sup>°C</sup></div>
            <div class="weather-place-name">San Ildefonso, Bulacan</div>
            <div class="weather-condition-label"><?php echo htmlspecialchars($weather['condition_text']); ?></div>
          </div>
          <div class="weather-risk-pill <?php echo $weather['level']; ?>"><?php echo $riskLabels[$weather['level']] ?? strtoupper($weather['level']); ?> RISK</div>
        </div>
        <div class="weather-mascot-wrap">
          <span class="mascot-note"><?php echo $wx_ptcls[0]; ?></span>
          <span class="mascot-note" style="animation-delay:.9s"><?php echo $wx_ptcls[1]; ?></span>
          <span class="mascot-note" style="animation-delay:1.6s"><?php echo $wx_ptcls[2]; ?></span>
          <?php echo wx_mascot_html($wx_cat, $isNightTime, 'm'); ?>
        </div>
      </div>
      <div class="weather-stats-strip" style="background:linear-gradient(190deg,<?php echo $wx_colors[1]; ?> 0%,<?php echo $wx_colors[2]; ?> 100%);">
        <div class="w-stat-pill">
          <div class="stat-icon-3d"><svg viewBox="0 0 40 40"><defs><radialGradient id="dropBody" cx="38%" cy="28%" r="65%"><stop offset="0%" stop-color="#b3e5fc"/><stop offset="35%" stop-color="#29b6f6"/><stop offset="75%" stop-color="#0277bd"/><stop offset="100%" stop-color="#01579b"/></radialGradient><radialGradient id="dropSpec" cx="30%" cy="22%" r="35%"><stop offset="0%" stop-color="rgba(255,255,255,.85)"/><stop offset="100%" stop-color="rgba(255,255,255,0)"/></radialGradient><filter id="dropBlur"><feGaussianBlur stdDeviation="1.2"/></filter></defs><ellipse cx="20" cy="36" rx="8" ry="3" fill="rgba(2,119,189,.22)" filter="url(#dropBlur)"/><path d="M20 4 Q27 14 28 22 A8 8 0 0 1 12 22 Q13 14 20 4Z" fill="url(#dropBody)"/><path d="M12.4 22 Q12 18 15 13" stroke="rgba(100,210,255,.5)" stroke-width="1.5" fill="none" stroke-linecap="round"/><path d="M20 4 Q27 14 28 22 A8 8 0 0 1 12 22 Q13 14 20 4Z" fill="url(#dropSpec)"/><ellipse cx="17" cy="16" rx="3.5" ry="4.5" fill="rgba(255,255,255,.30)" transform="rotate(-15 17 16)"/></svg></div>
          <div class="stat-info"><div class="stat-val"><?php echo $weather['humidity']; ?>%</div><div class="stat-label">Humidity</div></div>
        </div>
        <div class="w-stat-pill">
          <div class="stat-icon-3d"><svg viewBox="0 0 40 40"><defs><linearGradient id="thermTube" x1="0" y1="0" x2="1" y2="0"><stop offset="0%" stop-color="#b0bec5"/><stop offset="30%" stop-color="#eceff1"/><stop offset="60%" stop-color="#cfd8dc"/><stop offset="100%" stop-color="#90a4ae"/></linearGradient><linearGradient id="thermMercury" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#ff8a65"/><stop offset="60%" stop-color="#f4511e"/><stop offset="100%" stop-color="#bf360c"/></linearGradient><radialGradient id="thermBulb" cx="40%" cy="35%" r="60%"><stop offset="0%" stop-color="#ff8a65"/><stop offset="50%" stop-color="#f4511e"/><stop offset="100%" stop-color="#bf360c"/></radialGradient><radialGradient id="thermSpec" cx="35%" cy="28%" r="40%"><stop offset="0%" stop-color="rgba(255,255,255,.7)"/><stop offset="100%" stop-color="rgba(255,255,255,0)"/></radialGradient><filter id="thermBlur"><feGaussianBlur stdDeviation="1"/></filter></defs><ellipse cx="20" cy="37" rx="6" ry="2.2" fill="rgba(0,0,0,.18)" filter="url(#thermBlur)"/><rect x="16" y="5" width="8" height="22" rx="4" fill="url(#thermTube)"/><rect x="17.5" y="6" width="2" height="20" rx="1" fill="rgba(255,255,255,.55)"/><rect x="18" y="12" width="4" height="15" rx="2" fill="url(#thermMercury)"/><circle cx="20" cy="31" r="6" fill="url(#thermBulb)"/><circle cx="20" cy="31" r="6" fill="url(#thermSpec)"/><line x1="24.5" y1="10" x2="26.5" y2="10" stroke="rgba(255,255,255,.7)" stroke-width="1" stroke-linecap="round"/><line x1="24.5" y1="15" x2="26.5" y2="15" stroke="rgba(255,255,255,.7)" stroke-width="1" stroke-linecap="round"/><line x1="24.5" y1="20" x2="26.5" y2="20" stroke="rgba(255,255,255,.7)" stroke-width="1" stroke-linecap="round"/><line x1="24.5" y1="25" x2="26.5" y2="25" stroke="rgba(255,255,255,.7)" stroke-width="1" stroke-linecap="round"/></svg></div>
          <div class="stat-info"><div class="stat-val"><?php echo round($weather['heat_index'],1); ?>°C</div><div class="stat-label">Heat Index</div></div>
        </div>
      </div>
    </div>
    <?php else: ?>
    <div class="weather-card anim-in"><div class="weather-banner" style="padding-bottom:1rem;background:linear-gradient(135deg,#F97316,#FBBF24);"><p style="font-size:.82rem;color:rgba(255,255,255,.8);text-align:center;padding:.5rem 0;">Walang available na datos ng panahon.</p></div></div>
    <?php endif; ?>

   <div class="section-header anim-in"><h2>Evacuate</h2></div>
<div class="evac-card anim-in">
  <p>Kapag available, hanapin ang pinakamalapit na evacuation center at mag-navigate mula sa iyong lokasyon.</p>
  <a href="navigation.php" class="btn-nav" onclick="return requireProfileBeforeRoute(event)">Open Navigation</a>
</div>

    <div class="section-header anim-in" id="announcements"><h2>Announcements</h2></div>
    <?php if (!$announcements): ?>
    <div class="ann-list anim-in"><div class="ann-empty">Wala pang anunsyo.</div></div>
    <?php else: ?>
    <div class="ann-list anim-in">
      <?php foreach ($announcements as $idx => $a): ?>
      <div class="ann-item" onclick="openAnnModal(<?php echo $idx; ?>)" role="button">
        <div class="ann-dot <?php echo $a['is_pinned']?'pinned':''; ?>"></div>
        <div class="ann-body">
          <div class="ann-title">
            <?php if($a['is_pinned']): ?><span class="badge">NAKA-PIN</span><?php endif; ?>
            <?php if($a['disaster_title']): ?><span class="badge badge-disaster"><?php echo htmlspecialchars($a['disaster_title']); ?></span><?php endif; ?>
            <?php echo htmlspecialchars($a['title']); ?>
          </div>
          <div class="ann-preview"><?php echo htmlspecialchars(mb_substr($a['body'],0,200)); ?></div>
        </div>
        <svg viewBox="0 0 24 24" style="width:15px;height:15px;fill:var(--muted);flex-shrink:0;margin-top:3px;opacity:.5"><path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6z"/></svg>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <nav class="bottom-nav">
    <a href="citizen_dashboard.php" class="nav-item active">
      <svg viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg><span>Home</span>
    </a>
    <div class="nav-item nav-center" id="evacNavItem">
      <div class="nav-center-circle" id="evacFab">
        <div class="evac-fab-ring" id="evacRing"></div>
        <svg viewBox="0 0 24 24"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z M12 11.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z"/></svg>
      </div>
      <div class="evac-hint" id="evacHint">Pindutin para lumikas</div>
      <span>Lumikas</span>
    </div>
    <button class="nav-item" onclick="openSidebar()">
      <svg viewBox="0 0 24 24"><path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"/></svg><span>Menu</span>
    </button>
  </nav>
</div>

<div class="evac-ripple-primary" id="evacRipplePrimary"></div>
<div class="evac-ripple-shimmer" id="evacRippleShimmer"></div>
<div class="evac-ripple-icon" id="evacRippleIcon">
  <svg viewBox="0 0 24 24"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z M12 11.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z"/></svg>
  <span>LUMILIKAS NA</span>
</div>

<!-- ══ DESKTOP VIEW ══ -->
<div class="desktop-wrapper">
  <header class="desktop-topbar">
    <div class="drawer-logo" style="width:38px;height:38px;background:#eee;border-radius:50%;display:flex;align-items:center;justify-content:center;"><img src="../img/mdrrmo.png" alt="MDRRMO" style="width:100%"></div>
    <div class="desktop-topbar-center">
      <div class="desktop-topbar-title">Citizen Dashboard</div>
      <div class="desktop-topbar-sub">Welcome, <?php echo htmlspecialchars($user['full_name'] ?? 'Citizen'); ?></div>
    </div>
    <div class="desktop-topbar-right">
      <div class="desktop-date-chip"><?php echo date('l, F j, Y'); ?></div>
      <button class="hamburger-btn" onclick="openSidebar()"><span></span><span></span><span></span></button>
    </div>
  </header>
  <div class="desktop-content">
    <div class="desktop-grid">
      <div class="desktop-col-left">
        <div class="desktop-card">
          <div class="desktop-card-header"><h2>Current Status</h2></div>
          <div class="desktop-card-body desktop-alert-wrap">
            <?php if ($activeDisaster): ?>
            <div class="alert-banner alert-typhoon" onclick="openDisasterModal()" role="button">
             
              <div class="alert-text">
                <div class="alert-title"><?php echo htmlspecialchars(ucfirst($activeDisaster['type'])); ?> Signal#<?php echo (int)$activeDisaster['level']; ?> — Aktibo</div>
                <div class="alert-sub"><?php echo ($lvlLabel[(string)(int)$activeDisaster['level']] ?? 'Moderate').' na antas ng panganib'; ?></div>
              </div>
              <div class="alert-pulse-wrap"><div class="alert-pulse-dot"></div></div>
            </div>
            <?php if ($advice): ?>
            <div class="desktop-readybag" onclick="openReadyBagModal()">
        
              <div>
                <div class="desktop-readybag-title">Payo sa Ready Bag <small style="font-weight:400;color:#bcaaa4;font-size:.60rem">· Pindutin para makita</small></div>
                <div class="desktop-readybag-text"><?php echo htmlspecialchars(mb_substr($advice['message'],0,100)); ?>…</div>
              </div>
            </div>
            <?php endif; ?>
            <?php elseif ($weather && ($weather['level']==='high'||$weather['level']==='extreme')): ?>
            <div class="alert-banner alert-level-3">
              <div class="alert-icon">🌡️</div>
              <div class="alert-text">
                <div class="alert-title">ALERTO SA INIT — Heat Index: <?php echo round($weather['heat_index']); ?>°C</div>
                <div class="alert-sub">Uminom ng maraming tubig at iwasang lumabas</div>
              </div>
              <div class="alert-pulse-wrap"><div class="alert-pulse-dot"></div></div>
            </div>
            <?php if ($advice): ?>
            <div class="desktop-readybag" onclick="openReadyBagModal()">
             
              <div>
                <div class="desktop-readybag-title">Payo sa Ready Bag <small style="font-weight:400;color:#bcaaa4;font-size:.60rem">· Pindutin para makita</small></div>
                <div class="desktop-readybag-text"><?php echo htmlspecialchars(mb_substr($advice['message'],0,100)); ?>…</div>
              </div>
            </div>
            <?php endif; ?>
            <?php else: ?>
            <div class="alert-banner alert-none">
              <div class="alert-icon">✅</div>
              <div class="alert-text">
                <div class="alert-title">Walang aktibong sakuna sa ngayon</div>
                <div class="alert-sub">Manatiling handa at subaybayan ang mga update</div>
              </div>
            </div>
            <?php endif; ?>
          </div>
        </div>
        <div class="desktop-card">
          <div class="desktop-card-header"><h2>Weather Forecast</h2><a href="#">Live Data</a></div>
          <?php if ($weather): ?>
          <div class="weather-card" style="margin:0;border-radius:0;box-shadow:none">
            <div class="weather-banner" style="background:linear-gradient(150deg,<?php echo $wx_colors[0]; ?> 0%,<?php echo $wx_colors[1]; ?> 48%,<?php echo $wx_colors[2]; ?> 100%);">
              <div class="weather-top-row">
                <div class="weather-left">
                  <div class="weather-temp-big"><?php echo round($weather['temp_c']); ?><sup>°C</sup></div>
                  <div class="weather-place-name">San Ildefonso, Bulacan</div>
                  <div class="weather-condition-label"><?php echo htmlspecialchars($weather['condition_text']); ?></div>
                </div>
                <div class="weather-risk-pill <?php echo $weather['level']; ?>"><?php echo $riskLabels[$weather['level']] ?? strtoupper($weather['level']); ?> RISK</div>
              </div>
              <div class="weather-mascot-wrap" style="width:144px;height:144px;bottom:-10px;right:18px;">
                <span class="mascot-note"><?php echo $wx_ptcls[0]; ?></span>
                <span class="mascot-note" style="animation-delay:.9s"><?php echo $wx_ptcls[1]; ?></span>
                <span class="mascot-note" style="animation-delay:1.6s"><?php echo $wx_ptcls[2]; ?></span>
                <?php echo wx_mascot_html($wx_cat, $isNightTime, 'd'); ?>
              </div>
            </div>
            <div class="weather-stats-strip" style="background:linear-gradient(190deg,<?php echo $wx_colors[1]; ?> 0%,<?php echo $wx_colors[2]; ?> 100%);">
              <div class="w-stat-pill">
                <div class="stat-icon-3d"><svg viewBox="0 0 40 40"><defs><radialGradient id="dDropBody" cx="38%" cy="28%" r="65%"><stop offset="0%" stop-color="#b3e5fc"/><stop offset="35%" stop-color="#29b6f6"/><stop offset="75%" stop-color="#0277bd"/><stop offset="100%" stop-color="#01579b"/></radialGradient><radialGradient id="dDropSpec" cx="30%" cy="22%" r="35%"><stop offset="0%" stop-color="rgba(255,255,255,.85)"/><stop offset="100%" stop-color="rgba(255,255,255,0)"/></radialGradient><filter id="dDropBlur"><feGaussianBlur stdDeviation="1.2"/></filter></defs><ellipse cx="20" cy="36" rx="8" ry="3" fill="rgba(2,119,189,.22)" filter="url(#dDropBlur)"/><path d="M20 4 Q27 14 28 22 A8 8 0 0 1 12 22 Q13 14 20 4Z" fill="url(#dDropBody)"/><path d="M12.4 22 Q12 18 15 13" stroke="rgba(100,210,255,.5)" stroke-width="1.5" fill="none" stroke-linecap="round"/><path d="M20 4 Q27 14 28 22 A8 8 0 0 1 12 22 Q13 14 20 4Z" fill="url(#dDropSpec)"/><ellipse cx="17" cy="16" rx="3.5" ry="4.5" fill="rgba(255,255,255,.30)" transform="rotate(-15 17 16)"/></svg></div>
                <div class="stat-info"><div class="stat-val"><?php echo $weather['humidity']; ?>%</div><div class="stat-label">Humidity</div></div>
              </div>
              <div class="w-stat-pill">
                <div class="stat-icon-3d"><svg viewBox="0 0 40 40"><defs><linearGradient id="dThermTube" x1="0" y1="0" x2="1" y2="0"><stop offset="0%" stop-color="#b0bec5"/><stop offset="30%" stop-color="#eceff1"/><stop offset="60%" stop-color="#cfd8dc"/><stop offset="100%" stop-color="#90a4ae"/></linearGradient><linearGradient id="dThermMercury" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#ff8a65"/><stop offset="60%" stop-color="#f4511e"/><stop offset="100%" stop-color="#bf360c"/></linearGradient><radialGradient id="dThermBulb" cx="40%" cy="35%" r="60%"><stop offset="0%" stop-color="#ff8a65"/><stop offset="50%" stop-color="#f4511e"/><stop offset="100%" stop-color="#bf360c"/></radialGradient><radialGradient id="dThermSpec" cx="35%" cy="28%" r="40%"><stop offset="0%" stop-color="rgba(255,255,255,.7)"/><stop offset="100%" stop-color="rgba(255,255,255,0)"/></radialGradient><filter id="dThermBlur"><feGaussianBlur stdDeviation="1"/></filter></defs><ellipse cx="20" cy="37" rx="6" ry="2.2" fill="rgba(0,0,0,.18)" filter="url(#dThermBlur)"/><rect x="16" y="5" width="8" height="22" rx="4" fill="url(#dThermTube)"/><rect x="17.5" y="6" width="2" height="20" rx="1" fill="rgba(255,255,255,.55)"/><rect x="18" y="12" width="4" height="15" rx="2" fill="url(#dThermMercury)"/><circle cx="20" cy="31" r="6" fill="url(#dThermBulb)"/><circle cx="20" cy="31" r="6" fill="url(#dThermSpec)"/><line x1="24.5" y1="10" x2="26.5" y2="10" stroke="rgba(255,255,255,.7)" stroke-width="1" stroke-linecap="round"/><line x1="24.5" y1="15" x2="26.5" y2="15" stroke="rgba(255,255,255,.7)" stroke-width="1" stroke-linecap="round"/><line x1="24.5" y1="20" x2="26.5" y2="20" stroke="rgba(255,255,255,.7)" stroke-width="1" stroke-linecap="round"/><line x1="24.5" y1="25" x2="26.5" y2="25" stroke="rgba(255,255,255,.7)" stroke-width="1" stroke-linecap="round"/></svg></div>
                <div class="stat-info"><div class="stat-val"><?php echo round($weather['heat_index'],1); ?>°C</div><div class="stat-label">Heat Index</div></div>
              </div>
            </div>
          </div>
          <?php else: ?><p style="font-size:.82rem;color:#888;text-align:center;padding:1rem">Walang available na datos ng panahon.</p><?php endif; ?>
        </div>
        <div class="desktop-card">
          <div class="desktop-card-header"><h2>Evacuate</h2></div>
          <div class="desktop-evac-body">
            <p style="font-size:.82rem;color:var(--text-2);margin-bottom:.9rem;line-height:1.65">Kapag available, hanapin ang pinakamalapit na evacuation center at mag-navigate mula sa iyong lokasyon.</p>
            <a href="navigation.php" class="btn-nav" onclick="return requireProfileBeforeRoute(event)">Open Navigation</a>
          </div>
        </div>
      </div>
      <div class="desktop-col-right">
        <div class="desktop-card">
          <div class="desktop-card-header" id="announcements"><h2>Announcements</h2></div>
          <?php if (!$announcements): ?>
          <div class="ann-empty">Wala pang anunsyo.</div>
          <?php else: ?>
          <div class="desktop-ann-list ann-list">
            <?php foreach ($announcements as $idx => $a): ?>
            <div class="ann-item" onclick="openAnnModal(<?php echo $idx; ?>)" role="button">
              <div class="ann-dot <?php echo $a['is_pinned']?'pinned':''; ?>"></div>
              <div class="ann-body">
                <div class="ann-title">
                  <?php if($a['is_pinned']): ?><span class="badge">NAKA-PIN</span><?php endif; ?>
                  <?php if($a['disaster_title']): ?><span class="badge badge-disaster"><?php echo htmlspecialchars($a['disaster_title']); ?></span><?php endif; ?>
                  <?php echo htmlspecialchars($a['title']); ?>
                </div>
                <div class="ann-preview"><?php echo htmlspecialchars(mb_substr($a['body'],0,200)); ?></div>
              </div>
              <svg viewBox="0 0 24 24" style="width:15px;height:15px;fill:var(--muted);flex-shrink:0;margin-top:3px;opacity:.5"><path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6z"/></svg>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
'use strict';
const ANNOUNCEMENTS_DATA = <?php echo $announcementsJson; ?>;
const READY_BAG_DATA     = <?php echo $readyBagJson; ?>;
const DISASTER_DATA      = <?php echo $disasterModalJson ?? 'null'; ?>;

// ── helpers ──
function escHtml(s){const d=document.createElement('div');d.appendChild(document.createTextNode(s));return d.innerHTML}

// ── ANNOUNCEMENT MODAL ──
function openAnnModal(idx){
  const ann=ANNOUNCEMENTS_DATA[idx]; if(!ann) return;
  const SVG_MEGA=`<svg viewBox="0 0 24 24"><path d="M18 11v2h4v-2h-4zm-2 6.61c.96.71 2.21 1.65 3.2 2.39.4-.53.8-1.07 1.2-1.6-.99-.74-2.24-1.68-3.2-2.4-.4.54-.8 1.08-1.2 1.61zM20.4 5.6c-.4-.53-.8-1.07-1.2-1.6-.99.74-2.24 1.68-3.2 2.4.4.53.8 1.07 1.2 1.6.96-.72 2.21-1.65 3.2-2.4zM4 9c-1.1 0-2 .9-2 2v2c0 1.1.9 2 2 2h1v4h2v-4h1l5 3V6L8 9H4zm11.5 3c0-1.33-.58-2.53-1.5-3.35v6.69c.92-.81 1.5-2.01 1.5-3.34z"/></svg>`;
  const SVG_PIN=`<svg viewBox="0 0 24 24"><path d="M16 12V4h1V2H7v2h1v8l-2 2v2h5.2v6h1.6v-6H18v-2l-2-2z"/></svg>`;
  const SVG_WARN=`<svg viewBox="0 0 24 24"><path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/></svg>`;
  const SVG_CAL=`<svg viewBox="0 0 24 24"><path d="M20 3h-1V1h-2v2H7V1H5v2H4C2.9 3 2 3.9 2 5v16c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 18H4V8h16v13z"/></svg>`;
  document.getElementById('annModalIcon').innerHTML = ann.is_pinned ? SVG_PIN : (ann.disaster_title ? SVG_WARN : SVG_MEGA);
  document.getElementById('annModalTitle').textContent = ann.title || 'Anunsyo';
  const meta = document.getElementById('annModalMeta'); meta.innerHTML='';
  function chip(cls,svg,txt){const c=document.createElement('div');c.className='annmodal-meta-chip '+cls;c.innerHTML=svg+' '+escHtml(txt);meta.appendChild(c)}
  if(ann.is_pinned)       chip('pinned',SVG_PIN,'Naka-Pin');
  if(ann.disaster_title)  chip('disaster',SVG_WARN,ann.disaster_title);
  if(ann.published_at){
    let ds=ann.published_at;
    try{ds=new Date(ann.published_at).toLocaleDateString('fil-PH',{year:'numeric',month:'short',day:'numeric'})}catch(e){}
    chip('',SVG_CAL,ds);
  }
  document.getElementById('annModalText').textContent = ann.body || 'Walang nilalaman.';
  document.getElementById('annModalBackdrop').classList.add('open');
  document.body.style.overflow='hidden';
  const body=document.getElementById('annModalBody'); body.scrollTop=0;
  requestAnimationFrame(()=>requestAnimationFrame(()=>{
    const hint=document.getElementById('annModalScrollHint');
    hint.classList.toggle('visible', body.scrollHeight > body.clientHeight+12);
    body.addEventListener('scroll',()=>hint.classList.remove('visible'),{once:true,passive:true});
  }));
  if('vibrate' in navigator) navigator.vibrate(28);
}
function closeAnnModal(e){
  if(e&&e.target!==document.getElementById('annModalBackdrop')) return;
  document.getElementById('annModalBackdrop').classList.remove('open');
  document.body.style.overflow='';
}

// ── PROFILE ──
let profileCache=null;
const HH_FIELDS=['adults','children','seniors','pwds','pregnant_women','lactating_mothers','infants_toddlers'];
const HH_FIELD_IDS={adults:'hhAdults',children:'hhChildren',seniors:'hhSeniors',pwds:'hhPwds',pregnant_women:'hhPregnantWomen',lactating_mothers:'hhLactatingMothers',infants_toddlers:'hhInfantsToddlers'};
const hhState={adults:1,children:0,seniors:0,pwds:0,pregnant_women:0,lactating_mothers:0,infants_toddlers:0};
function showProfileToast(msg,type=''){
  const el=document.getElementById('profileToast'); if(!el) return;
  el.textContent=msg; el.className='profile-toast show '+type;
  setTimeout(()=>el.classList.remove('show'),2500);
}
function isProfileComplete(){
  if(!profileCache) return false;
  const required = ['first_name','last_name','contact_number','birthday','sex'];
  return required.every(f => profileCache[f] && String(profileCache[f]).trim() !== '');
}

function requireProfileBeforeRoute(e){
  if(isProfileComplete()) return true; // ok na, hayaan tumuloy
  if(e) e.preventDefault();
  showProfileToast('Kumpletuhin muna ang iyong personal details bago mag-navigate','error');
  openProfileAndCloseSidebar ? openProfileModal() : null;
  if('vibrate' in navigator) navigator.vibrate([40,30,40]);
  return false;
}
function updateHHTotal(){
  const total=HH_FIELDS.reduce((s,f)=>s+(hhState[f]||0),0);
  const el=document.getElementById('hhTotal'); if(el) el.textContent=total;
  let badge=document.getElementById('hhSizeBadge');
  if(!badge){
    const fab=document.getElementById('evacFab');
    if(fab){fab.style.position='relative';badge=document.createElement('div');badge.className='hh-size-badge';badge.id='hhSizeBadge';fab.appendChild(badge)}
  }
  if(badge) badge.textContent=total;
}
function hhChange(field,delta){
  hhState[field]=Math.max(field==='adults'?1:0,(hhState[field]||0)+delta);
  const el=document.getElementById(HH_FIELD_IDS[field]);
  if(el) el.textContent=hhState[field];
  updateHHTotal();
}
function renderProfileFromCache(){
  if(!profileCache) return;
  ['pfFirstName','pfMiddleName','pfLastName'].forEach(id=>document.getElementById(id).value=profileCache[id.replace('pf','').replace(/([A-Z])/g,'_$1').toLowerCase().slice(1)]||'');
  document.getElementById('pfFirstName').value  = profileCache.first_name   || '';
  document.getElementById('pfMiddleName').value = profileCache.middle_name  || '';
  document.getElementById('pfLastName').value   = profileCache.last_name    || '';
  document.getElementById('pfSuffix').value     = profileCache.suffix       || '';
  document.getElementById('pfContact').value    = profileCache.contact_number|| '';
  document.getElementById('pfBarangay').value   = profileCache.barangay_name|| '';
  document.getElementById('pfHouseNo').value    = profileCache.house_number || '';
  if(profileCache.birthday) document.getElementById('pfBirthday').value=profileCache.birthday;
  if(profileCache.sex) document.getElementById('pfSex').value=profileCache.sex;
  if(profileCache.age!=null) document.getElementById('ageDisplay').innerText=profileCache.age+' yrs';
  HH_FIELDS.forEach(f=>{ hhState[f]=profileCache.household?.[f]??(f==='adults'?1:0); });
  HH_FIELDS.forEach(f=>{ const el=document.getElementById(HH_FIELD_IDS[f]); if(el) el.textContent=hhState[f]; });
  updateHHTotal();
  const name=[profileCache.first_name,profileCache.last_name].filter(Boolean).join(' ');
  const initial=profileCache.first_name?profileCache.first_name[0].toUpperCase():'?';
  ['profileHeadAvatar','topbarAvatar','drawerAvatar'].forEach(id=>{const el=document.getElementById(id);if(el) el.textContent=initial});
  document.getElementById('profileHeadName').innerText=name||'My Profile';
  document.getElementById('drawerName').innerText=profileCache.full_name||'My Profile';
  document.getElementById('profileHeadBrgy').innerText=profileCache.barangay_name?'Brgy. '+profileCache.barangay_name:'';
}
function loadProfileData(){
  return fetch('citizen_profile_action.php?action=get&_='+Date.now(),{credentials:'same-origin',cache:'no-store'})
    .then(r=>r.json()).then(d=>{if(d.ok){profileCache=d;renderProfileFromCache()}}).catch(e=>console.warn(e));
}
function openProfileModal(){
  const b=document.getElementById('profileBackdrop'); if(!b) return;
  if(profileCache) renderProfileFromCache();
  b.classList.add('open'); document.body.style.overflow='hidden';
  if(!profileCache) loadProfileData();
}
function closeProfileModal(){
  const b=document.getElementById('profileBackdrop'); if(b) b.classList.remove('open');
  document.body.style.overflow='';
}
function handleProfileBackdropClick(e){if(e.target.id==='profileBackdrop') closeProfileModal()}
function openProfileAndCloseSidebar(){closeSidebar();setTimeout(openProfileModal,100)}
function saveProfile(){
  const btn=document.getElementById('profileSaveBtn'); btn.classList.add('saving');
  const p={first_name:document.getElementById('pfFirstName').value.trim(),middle_name:document.getElementById('pfMiddleName').value.trim(),last_name:document.getElementById('pfLastName').value.trim(),suffix:document.getElementById('pfSuffix').value,contact_number:document.getElementById('pfContact').value.trim(),birthday:document.getElementById('pfBirthday').value,sex:document.getElementById('pfSex').value,...hhState};
  fetch('citizen_profile_action.php?action=save',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(p)})
    .then(r=>r.json()).then(d=>{btn.classList.remove('saving');if(d.ok){showProfileToast('Saved successfully','success');loadProfileData();setTimeout(closeProfileModal,1000)}else showProfileToast(d.error||'Save failed','error')})
    .catch(()=>{btn.classList.remove('saving');showProfileToast('Network error','error')});
}

// ── SIDEBAR ──
function openSidebar(){document.getElementById('sidebarDrawer').classList.add('open');document.getElementById('sidebarOverlay').classList.add('open');document.body.style.overflow='hidden'}
function closeSidebar(){document.getElementById('sidebarDrawer').classList.remove('open');document.getElementById('sidebarOverlay').classList.remove('open');document.body.style.overflow=''}

// ── READY BAG ──
let checklistState={};
function parseRBItems(msg){
  if(!msg) return [];
  const d=msg.split(/\s*[-•·]\s+/).filter(s=>s.trim().length>1);
  if(d.length>1) return d.map(s=>s.replace(/\.?\s*$/,'').trim());
  const s=msg.split(/\.\s+/).filter(s=>s.trim().length>3);
  if(s.length>1) return s.map(s=>s.trim());
  return [msg.trim()];
}
function openReadyBagModal(){
  document.getElementById('rbModalTitle').textContent=READY_BAG_DATA.title||'Ready Bag';
  const items=parseRBItems(READY_BAG_DATA.message); checklistState={};
  items.forEach((_,i)=>{checklistState[i]=false});
  const wrap=document.getElementById('rbChecklistWrap');
  wrap.innerHTML=items.length>1?items.map((item,i)=>`<div class="rbmodal-item" onclick="toggleCheck(${i})"><div class="rbmodal-checkbox" id="rbchk-${i}"></div><div class="rbmodal-item-text" id="rbtxt-${i}">${escHtml(item)}</div></div>`).join(''):`<div class="rbmodal-message">${escHtml(READY_BAG_DATA.message)}</div>`;
  updateProgress(items.length);
  document.getElementById('rbModalBackdrop').classList.add('open');
  document.body.style.overflow='hidden';
  if('vibrate' in navigator) navigator.vibrate(50);
}
function toggleCheck(i){
  checklistState[i]=!checklistState[i];
  document.getElementById('rbchk-'+i)?.classList.toggle('checked',checklistState[i]);
  document.getElementById('rbtxt-'+i)?.classList.toggle('checked-text',checklistState[i]);
  updateProgress(Object.keys(checklistState).length);
}
function updateProgress(total){
  const checked=Object.values(checklistState).filter(Boolean).length;
  const pct=total>0?(checked/total)*100:0;
  const fill=document.getElementById('rbProgressFill'); if(fill) fill.style.width=pct+'%';
  const text=document.getElementById('rbProgressText'); if(text) text.textContent=checked+' / '+total+' nacheck';
}
function closeReadyBagModal(e){
  if(e&&e.target!==document.getElementById('rbModalBackdrop')) return;
  document.getElementById('rbModalBackdrop').classList.remove('open');
  document.body.style.overflow='';
}

// ── DISASTER MODAL ──
const LVL_LABEL={1:'Low',2:'Moderate',3:'High',4:'Severe'};
function openDisasterModal(){
  if(!DISASTER_DATA) return;
  const lvl=parseInt(DISASTER_DATA.level,10)||0;
  document.getElementById('dsModalTitle').textContent=DISASTER_DATA.title||'Alerto sa Sakuna';
  document.getElementById('dsModalType').textContent=DISASTER_DATA.type?(DISASTER_DATA.type.charAt(0).toUpperCase()+DISASTER_DATA.type.slice(1)):'Uri';
  document.getElementById('dsModalLevelText').textContent='Signal #'+lvl+' — '+(LVL_LABEL[lvl]||'Moderate');
  const chips=document.getElementById('dsModalChips'); chips.innerHTML='';
  function chip(txt){const c=document.createElement('div');c.className='dsmodal-chip';c.textContent=txt;chips.appendChild(c)}
  if(DISASTER_DATA.status) chip(DISASTER_DATA.status.charAt(0).toUpperCase()+DISASTER_DATA.status.slice(1));
  if(DISASTER_DATA.started_at){
    let ds=DISASTER_DATA.started_at;
    try{ds=new Date(DISASTER_DATA.started_at).toLocaleString('fil-PH',{year:'numeric',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'})}catch(e){}
    chip('Nagsimula: '+ds);
  }
  document.getElementById('dsModalDesc').textContent=DISASTER_DATA.description||'Walang karagdagang impormasyon na available.';
  document.getElementById('dsModalBackdrop').classList.add('open');
  document.body.style.overflow='hidden';
  if('vibrate' in navigator) navigator.vibrate(28);
}
function closeDisasterModal(e){
  if(e&&e.target!==document.getElementById('dsModalBackdrop')) return;
  document.getElementById('dsModalBackdrop').classList.remove('open');
  document.body.style.overflow='';
}

// ── EVACUATION FAB — optimized (ring throttled every 3rd frame) ──
(function(){
  const HOLD_MS=450, DEST='navigation.php';
  const fab=document.getElementById('evacFab');
  const hint=document.getElementById('evacHint');
  const navItem=document.getElementById('evacNavItem');
  const primary=document.getElementById('evacRipplePrimary');
  const shimmer=document.getElementById('evacRippleShimmer');
  const overlay=document.getElementById('evacRippleIcon');
  if(!fab) return;

  let isHolding=false,isCompleted=false,frame=null,startTime=0,raw=0,fc=0;
  let cx,cy,pDiam,sDiam;

  function measure(){
    const r=fab.getBoundingClientRect();
    cx=r.left+r.width/2; cy=r.top+r.height/2;
    const mr=Math.hypot(Math.max(cx,innerWidth-cx),Math.max(cy,innerHeight-cy))*1.18;
    pDiam=mr*2; sDiam=mr*2.5;
  }
  function posLayer(el,d){if(!el) return;el.style.cssText=`width:${d}px;height:${d}px;left:${cx-d/2}px;top:${cy-d/2}px;transition:none;transform:scale(0);opacity:0`}
  function ease(t){return 1-Math.pow(1-t,2.8)}
  function resetLayers(){
    [primary,shimmer].forEach(el=>{if(el){el.style.transition='transform .26s ease-out,opacity .26s ease-out';el.style.transform='scale(0)';el.style.opacity='0'}});
  }
  function setRing(pct){fc++;if(fc%3===0){const r=document.getElementById('evacRing');if(r) r.style.setProperty('--pct',Math.min(pct,100))}}

  function startHold(e){
    e.preventDefault(); if(isCompleted) return;

    if(!isProfileComplete()){
      showProfileToast('Kumpletuhin muna ang personal details bago lumikas','error');
      if('vibrate' in navigator) navigator.vibrate([40,30,40]);
      openProfileModal();
      return;
    }

    isHolding=true; isCompleted=false; raw=0; fc=0;
    fab.classList.remove('done','shake'); fab.classList.add('pressing');
    setRing(0); measure();
    posLayer(primary,pDiam); posLayer(shimmer,sDiam);
    startTime=Date.now();
    if('vibrate' in navigator) navigator.vibrate(20);
    function loop(){
      if(!isHolding) return;
      const t=Math.min((Date.now()-startTime)/HOLD_MS,1);
      raw+=(t-raw)*.15;
      setRing(raw*100);
      const e2=ease(raw);
      if(primary){primary.style.transform=`scale(${e2*1.02})`;primary.style.opacity=Math.min(raw/.15,1).toFixed(3)}
      if(shimmer){const lag=Math.max(0,raw-.05);shimmer.style.transform=`scale(${ease(lag)*.92})`;shimmer.style.opacity=Math.min(lag/.20,.72).toFixed(3)}
      fab.style.transform=`scale(${.91+raw*.12})`;
      if(raw>=.4&&hint.textContent!=='Lumilikas…'){hint.textContent='Lumilikas…';if('vibrate' in navigator) navigator.vibrate([30,20,30])}
      if(t>=1&&raw>.97) complete(); else frame=requestAnimationFrame(loop);
    }
    if(frame) cancelAnimationFrame(frame);
    frame=requestAnimationFrame(loop);
  }
  function cancelHold(e){
    if(!isHolding||isCompleted) return;
    const elapsed=Date.now()-startTime;
    isHolding=false;
    if(frame){cancelAnimationFrame(frame);frame=null}
    fab.classList.remove('pressing'); setRing(0); fab.style.transform='';
    hint.textContent='Pindutin para lumikas';
    resetLayers();
    if(elapsed>200){fab.classList.add('shake');setTimeout(()=>fab.classList.remove('shake'),400);if('vibrate' in navigator) navigator.vibrate([50,30,50])}
  }
  function complete(){
    if(isCompleted) return;
    isHolding=false; isCompleted=true;
    if(frame){cancelAnimationFrame(frame);frame=null}
    if(primary){primary.style.transition='transform .38s ease,opacity .28s ease';primary.style.transform='scale(1.04)';primary.style.opacity='1'}
    if(shimmer){shimmer.style.transition='transform .48s ease,opacity .38s ease';shimmer.style.transform='scale(1.0)';shimmer.style.opacity='.68'}
    fab.classList.remove('pressing'); fab.classList.add('done');
    hint.textContent='Lumilikas…';
    if('vibrate' in navigator) navigator.vibrate([100,50,100,50,300]);
    if(overlay) overlay.classList.add('visible');
    setTimeout(()=>{window.location.href=DEST},350);
  }

  fab.addEventListener('touchstart',startHold,{passive:false});
  fab.addEventListener('touchend',cancelHold);
  fab.addEventListener('touchcancel',cancelHold);
  fab.addEventListener('mousedown',startHold);
  document.addEventListener('mouseup',cancelHold);
  fab.addEventListener('contextmenu',e=>e.preventDefault());
  setTimeout(()=>{navItem.classList.add('hint-show');setTimeout(()=>navItem.classList.remove('hint-show'),2000)},1200);
})();
// ── INIT ──
window.addEventListener('DOMContentLoaded',()=>{
  loadProfileData();

  // Swipe-down to close all bottom sheets
  [['profileSheet',()=>closeProfileModal()],['annModalSheet',()=>closeAnnModal()],
   ['rbModalSheet',()=>closeReadyBagModal()],['dsModalSheet',()=>closeDisasterModal()]
  ].forEach(([id,fn])=>{
    const el=document.getElementById(id); if(!el) return;
    let sy=0;
    el.addEventListener('touchstart',e=>{sy=e.touches[0].clientY},{passive:true});
    el.addEventListener('touchend',e=>{if(e.changedTouches[0].clientY-sy>80) fn()},{passive:true});
  });

  // Desktop-only 3D tilt on weather card
  if(window.innerWidth>=1024){
    const wx=document.querySelector('.weather-card');
    if(wx){
      wx.addEventListener('mousemove',e=>{const r=wx.getBoundingClientRect();const x=(e.clientX-r.left)/r.width-.5;const y=(e.clientY-r.top)/r.height-.5;wx.style.transform=`perspective(800px) rotateY(${x*3}deg) rotateX(${-y*3}deg)`});
      wx.addEventListener('mouseleave',()=>{wx.style.transform=''});
    }
  }
});
</script>
</body>
</html>