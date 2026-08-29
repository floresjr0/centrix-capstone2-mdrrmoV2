<?php
require_once __DIR__ . '/../pages/session.php';
require_login('coordinator');
require_once __DIR__ . '/../pages/center_helpers.php';
require_once __DIR__ . '/../pages/demographic_helpers.php';

$pdo  = db();
$user = current_user();

$centerId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Ensure this center belongs to this coordinator
$stmt = $pdo->prepare("SELECT c.*, b.name AS barangay_name
                       FROM evacuation_centers c
                       JOIN barangays b ON b.id = c.barangay_id
                       WHERE c.id = ? AND c.coordinator_user_id = ?");
$stmt->execute([$centerId, $user['id']]);
$center = $stmt->fetch();

if (!$center) {
    http_response_code(404);
    echo 'Center not found or not assigned to you.';
    exit;
}

$errors = [];

// ── Record arrival ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_app_arrival') {
    $trackingId = (int)($_POST['tracking_id'] ?? 0);
    $navUserId  = (int)($_POST['nav_user_id']  ?? 0);
    $demo  = demo_from_request($_POST);
    $total = demo_sum_row($demo);

    $chk = $pdo->prepare("SELECT nt.id, u.full_name, u.barangay_id,
                                  u.contact_number, u.birthday, u.sex
                           FROM evac_navigation_tracking nt
                           JOIN users u ON u.id = nt.user_id
                           WHERE nt.id = ? AND nt.center_id = ? AND nt.status = 'navigating'");
    $chk->execute([$trackingId, $centerId]);
    $trackRow = $chk->fetch();

    if ($trackRow && $total > 0) {
        $headName      = $trackRow['full_name'];
        $contactNumber = $trackRow['contact_number'] ?? null;
        $birthday      = $trackRow['birthday'] ?? null;

        if (family_head_already_registered($pdo, $centerId, $headName, $contactNumber, $birthday)) {
            $upd = $pdo->prepare("UPDATE evac_navigation_tracking
                                  SET status = 'arrived', updated_at = NOW()
                                  WHERE id = ?");
            $upd->execute([$trackingId]);
            header('Location: center_app_arrivals.php?id=' . $centerId . '&duplicate=1');
            exit;
        }

        $demoCols = implode(', ', demo_field_keys());
        $demoPh   = implode(', ', array_fill(0, count(demo_field_keys()), '?'));
        $ins = $pdo->prepare("INSERT INTO evac_registrations
            (center_id, family_head_name, contact_number, birthday, barangay_id,
             $demoCols, total_members, created_by)
            VALUES (?, ?, ?, ?, ?, $demoPh, ?, ?)");
        $ins->execute(array_merge([
            $centerId,
            $trackRow['full_name'],
            $trackRow['contact_number'] ?? null,
            $trackRow['birthday']       ?? null,
            $trackRow['barangay_id'],
        ], array_values($demo), [$total, $user['id']]));

        $upd = $pdo->prepare("UPDATE evac_navigation_tracking
                              SET status = 'arrived', updated_at = NOW()
                              WHERE id = ?");
        $upd->execute([$trackingId]);

        refresh_center_status($centerId);
        header('Location: center_app_arrivals.php?id=' . $centerId . '&checkin=1');
        exit;
    } else {
        $errors[] = 'Could not record arrival — record may no longer be active.';
    }
}

// ── Decline arrival ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'decline_app_arrival') {
    $trackingId = (int)($_POST['tracking_id'] ?? 0);

    $chk = $pdo->prepare("SELECT id FROM evac_navigation_tracking
                           WHERE id = ? AND center_id = ? AND status = 'navigating'");
    $chk->execute([$trackingId, $centerId]);

    if ($chk->fetch()) {
        $upd = $pdo->prepare("DELETE FROM evac_navigation_tracking
                              WHERE id = ? AND center_id = ? AND status = 'navigating'");
        $upd->execute([$trackingId, $centerId]);
    }

    header('Location: center_app_arrivals.php?id=' . $centerId);
    exit;
}

// ── Fetch en-route citizens ─────────────────────────────────────────────────
$appArrivalsStmt = $pdo->prepare("
    SELECT
        nt.id          AS tracking_id,
        nt.user_id,
        u.full_name,
        u.contact_number,
        u.birthday,
        b.name         AS barangay_name,
        u.barangay_id,
        u.house_number,
        COALESCE(ch.adults,        1) AS adults,
        COALESCE(ch.children,      0) AS children,
        COALESCE(ch.seniors,       0) AS seniors,
        COALESCE(ch.pwds,          0) AS pwds,
        COALESCE(ch.pregnant_women,    0) AS pregnant_women,
        COALESCE(ch.lactating_mothers, 0) AS lactating_mothers,
        COALESCE(ch.infants_toddlers,  0) AS infants_toddlers,
        COALESCE(ch.total_members, 1) AS total_members,
        nt.updated_at
    FROM evac_navigation_tracking nt
    JOIN users u        ON u.id  = nt.user_id
    JOIN barangays b    ON b.id  = u.barangay_id
    LEFT JOIN family_profiles ch ON ch.user_id = nt.user_id
    WHERE nt.center_id = ?
      AND nt.status    = 'navigating'
    ORDER BY nt.updated_at ASC
");
$appArrivalsStmt->execute([$centerId]);
$appArrivals = $appArrivalsStmt->fetchAll();
foreach ($appArrivals as $i => $a) {
    $appArrivals[$i]['already_registered'] = family_head_already_registered(
        $pdo,
        $centerId,
        $a['full_name'],
        $a['contact_number'] ?? null,
        $a['birthday'] ?? null
    );
}

$occ      = get_center_occupancy($centerId);
$pct      = round($occ['percent']);
$barColor = $pct >= 100 ? '#dc2626' : ($pct >= 75 ? '#d97706' : '#16a34a');
$justCheckedIn = isset($_GET['checkin']) && $_GET['checkin'] == '1';
$justDuplicate = isset($_GET['duplicate']) && $_GET['duplicate'] == '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>App Arrivals – <?php echo htmlspecialchars($center['name']); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Geist:wght@300;400;500;600;700;800;900&family=Geist+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../asset/css/center_app_arrivals.css">
</head>
<style>
</style>
<body>

<div class="bg-blobs" aria-hidden="true">
    <div class="bg-blob b1"></div>
    <div class="bg-blob b2"></div>
    <div class="bg-blob b3"></div>
    <div class="bg-blob b4"></div>
</div>

<div class="drawer-overlay" id="drawerOverlay" onclick="closeMenu()"></div>

<!-- Decline Confirmation Modal -->
<div class="modal-overlay" id="declineModal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="modal-box">
        <div class="modal-icon">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        </div>
        <div class="modal-title" id="modalTitle">Decline Arrival?</div>
        <p class="modal-desc">Remove <span class="modal-name" id="modalPersonName">—</span> from the en-route queue. This will cancel their navigation tracking.</p>
        <label class="modal-reason-label" for="declineReason">Reason (optional)</label>
        <select class="modal-reason-select" id="declineReason">
            <option value="">— Select a reason —</option>
            <option value="redirected">Redirected to another center</option>
            <option value="no_show">Did not arrive / no show</option>
            <option value="wrong_center">Wrong center selected</option>
            <option value="returned_home">Returned home safely</option>
            <option value="other">Other</option>
        </select>
        <div class="modal-btns">
            <button class="modal-btn-cancel" onclick="closeDeclineModal()">Cancel</button>
            <button class="modal-btn-confirm" onclick="submitDecline()">Decline Arrival</button>
        </div>
    </div>
</div>

<!-- Logout Confirmation Modal -->
<div class="modal-overlay" id="logoutModal" role="dialog" aria-modal="true" aria-labelledby="logoutModalTitle">
    <div class="modal-box">
        <div class="modal-icon" style="background:#fef3c7; border-color:#fcd34d;">
            <svg viewBox="0 0 24 24" style="stroke:#b45309;"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        </div>
        <div class="modal-title" id="logoutModalTitle">Log out?</div>
        <p class="modal-desc">You will be signed out of the system. Any unsaved changes will be lost.</p>
        <div class="modal-btns">
            <button class="modal-btn-cancel" onclick="closeLogoutModal()">Cancel</button>
            <a href="../pages/logout.php" class="modal-btn-confirm" style="text-decoration:none; display:flex; align-items:center; justify-content:center; background:#b45309;">Yes, Log Out</a>
        </div>
    </div>
</div>

<!-- Hidden decline form (submits to backend) -->
<form id="declineForm" method="post" style="display:none;">
    <input type="hidden" name="action"      value="decline_app_arrival">
    <input type="hidden" name="tracking_id" id="declineTrackingId" value="">
    <input type="hidden" name="reason"      id="declineReasonHidden" value="">
</form>

<div class="layout">

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-brand-row">
                <div class="brand-logo-sm"><img src="../img/mdrrmo.png" alt="MDRRMO Logo"></div>
                <div><div class="brand-name-sm">MDRRMO</div><div class="brand-tagline-sm">#BidaAngLagingHanda</div></div>
            </div>
            <button class="sidebar-close" onclick="closeMenu()">
                <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="sidebar-user">
            <div class="user-avatar"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($user['full_name'], 0, 1))); ?></div>
            <div class="user-info">
                <div class="user-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
                <div class="user-role">Coordinator</div>
            </div>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-label">Navigation</div>
            <a href="index.php" class="nav-item">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M3 9.5L12 3l9 6.5V20a1 1 0 01-1 1H5a1 1 0 01-1-1V9.5z"/><polyline points="9 21 9 12 15 12 15 21"/></svg></span>
                Dashboard
            </a>
            <a href="index.php" class="nav-item active">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 21V9h6v12"/><path d="M3 9h18"/></svg></span>
                Centers
            </a>
        </nav>
        <div class="sidebar-status"><span class="status-dot-green"></span>SYSTEM ONLINE</div>
        <div class="sidebar-footer">
            <button class="logout-btn" onclick="openLogoutModal()">
                <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Log Out
            </button>
        </div>
    </aside>

    <!-- Bottom navigation -->
    <nav class="bottom-nav">
        <div class="bottom-nav-inner">
            <a href="index.php" class="bottom-nav-item">
                <span class="bottom-nav-icon"><svg viewBox="0 0 24 24"><path d="M3 9.5L12 3l9 6.5V20a1 1 0 01-1 1H5a1 1 0 01-1-1V9.5z"/><polyline points="9 21 9 12 15 12 15 21"/></svg></span>
                Dashboard
                <span class="bottom-nav-dot"></span>
            </a>
            <a href="center_app_arrivals.php?id=<?php echo $centerId; ?>" class="bottom-nav-item active">
                <span class="bottom-nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg></span>
                App Arrivals
                <span class="bottom-nav-dot"></span>
            </a>
            <a href="center_walkin.php?id=<?php echo $centerId; ?>" class="bottom-nav-item">
                <span class="bottom-nav-icon"><svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg></span>
                Walk-in
                <span class="bottom-nav-dot"></span>
            </a>
            <a href="center_registrations.php?id=<?php echo $centerId; ?>" class="bottom-nav-item">
                <span class="bottom-nav-icon"><svg viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg></span>
                Registrations
                <span class="bottom-nav-dot"></span>
            </a>
            <button class="bottom-nav-item" onclick="openLogoutModal()">
                <span class="bottom-nav-icon"><svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg></span>
                Logout
                <span class="bottom-nav-dot"></span>
            </button>
        </div>
    </nav>

    <div class="main">
        <header class="topbar">
            <div class="topbar-brand">
                <div class="topbar-logo"><img src="../img/mdrrmo.png" alt="MDRRMO Logo"></div>
                <div class="topbar-brand-text">
                    <div class="topbar-title"><?php echo htmlspecialchars($center['name']); ?></div>
                    <div class="topbar-subtitle">San Ildefonso, Bulacan — MDRRMO</div>
                </div>
            </div>
            <div class="topbar-right">
                <button class="hamburger-btn" onclick="openMenu()">
                    <svg viewBox="0 0 24 24"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                </button>
            </div>
        </header>

        <main class="dashboard">
            <div>
                <h1 class="page-heading">App <span>Arrivals</span></h1>
                <div class="page-subnav">
                    <a href="center_app_arrivals.php?id=<?php echo $centerId; ?>" class="active">App Arrivals</a>
                    <a href="center_walkin.php?id=<?php echo $centerId; ?>">Walk-in Family</a>
                    <a href="center_registrations.php?id=<?php echo $centerId; ?>">Registered Families</a>
                </div>
            </div>

            <!-- Center Status Card -->
            <section class="card">
                <div class="card-header">
                    <div class="card-header-icon"><svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div>
                    <h2>Center Status</h2>
                </div>
                <div class="card-body">
                    <div class="info-row"><strong>Barangay</strong> <?php echo htmlspecialchars($center['barangay_name']); ?></div>
                    <div class="info-row"><strong>Status</strong>
                        <span class="status-pill status-<?php echo strtolower(preg_replace('/\s+/', '-', $center['status'])); ?>">
                            <?php echo htmlspecialchars($center['status']); ?>
                        </span>
                    </div>
                    <div class="occ-bar-wrap">
                        <div class="occ-bar-label">
                            <span>Occupancy</span>
                            <span><?php echo $occ['current']; ?> / <?php echo $occ['max']; ?> people (<?php echo $pct; ?>%)</span>
                        </div>
                        <div class="occ-bar-track">
                            <div class="occ-bar-fill" style="width:<?php echo min(100,$pct); ?>%; background:<?php echo $barColor; ?>;"></div>
                        </div>
                    </div>
                    <p class="occ-note">When capacity reaches 100%, status is set to <strong>full</strong> and new arrivals should be redirected.</p>
                </div>
            </section>

            <!-- App Arrivals Section -->
            <section class="card">
                <div class="card-header">
                    <svg class="card-header-icon-plain" viewBox="0 0 24 24" fill="none" stroke="var(--orange-dark)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
                    <h2>Citizens en Route</h2>
                    <?php if ($appArrivals): ?>
                    <span class="en-route-badge" style="margin-left:auto;">
                        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
                        <?php echo count($appArrivals); ?> en route
                    </span>
                    <?php endif; ?>
                </div>

                <?php if ($errors): ?>
                <ul class="error-box">
                    <?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?>
                </ul>
                <?php endif; ?>

                <div class="card-body">
                    <?php if ($justCheckedIn): ?>
                    <div class="checkin-toast">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        Evacuee recorded successfully!
                    </div>
                    <?php endif; ?>

                    <?php if ($justDuplicate): ?>
                    <div class="checkin-toast duplicate-toast">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        Already registered at this center — duplicate entry blocked. Navigation cleared.
                    </div>
                    <?php endif; ?>

                    <?php if (!$appArrivals): ?>
                    <div class="arrival-queue-empty">
                        <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.5"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
                        No citizens are currently navigating to this center via the app.
                    </div>
                    <?php else: ?>
                    <div class="app-arrivals-grid">
                        <?php foreach ($appArrivals as $a):
                            $initial      = mb_strtoupper(mb_substr($a['full_name'], 0, 1));
                            $profileTotal = (int)$a['total_members'];
                            $isRegistered = !empty($a['already_registered']);
                        ?>
                        <div class="app-arrival-card<?php echo $isRegistered ? ' is-registered' : ''; ?>" id="arrival-card-<?php echo (int)$a['tracking_id']; ?>">

                            <div class="app-arrival-card-header">
                                <div class="app-arrival-person">
                                    <div class="app-arrival-avatar"><?php echo htmlspecialchars($initial); ?></div>
                                    <div>
                                        <div class="app-arrival-name"><?php echo htmlspecialchars($a['full_name']); ?></div>
                                        <div class="app-arrival-meta">
                                            <svg viewBox="0 0 14 14" width="10" height="10" fill="#c2410c"><path d="M7 1C4.79 1 3 2.79 3 5c0 3.25 4 8 4 8s4-4.75 4-8c0-2.21-1.79-4-4-4Z"/></svg>
                                            <?php echo htmlspecialchars($a['barangay_name']); ?>
                                            <span>·</span> House #<?php echo htmlspecialchars($a['house_number']); ?>
                                            <span>·</span> Profile: <?php echo $profileTotal; ?> person<?php echo $profileTotal != 1 ? 's' : ''; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php if ($isRegistered): ?>
                                <span class="app-badge-registered">Already Registered</span>
                                <?php else: ?>
                                <span class="app-badge-nav">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
                                    En Route
                                </span>
                                <?php endif; ?>
                            </div>

                            <?php if ($isRegistered): ?>
                            <div class="already-registered-note">
                                This family head is already registered at this center. Use Decline to clear navigation tracking.
                            </div>
                            <?php endif; ?>

                            <form method="post"
                                  id="form-arrival-<?php echo (int)$a['tracking_id']; ?>"
                                  onsubmit="return <?php echo $isRegistered ? 'false' : 'confirmArrival(this)'; ?>">
                                <input type="hidden" name="action"      value="record_app_arrival">
                                <input type="hidden" name="tracking_id" value="<?php echo (int)$a['tracking_id']; ?>">
                                <input type="hidden" name="nav_user_id" value="<?php echo (int)$a['user_id']; ?>">

                                <div class="app-arrival-members">
                                    <?php foreach (DEMO_FIELDS as $field => $label):
                                        $val = (int)$a[$field]; ?>
                                    <div class="app-member-row">
                                        <span class="app-member-label"><?php echo $label; ?></span>
                                        <div class="app-member-controls">
                                            <button type="button" <?php echo $isRegistered ? 'disabled' : ''; ?> onclick="adjustVal(<?php echo (int)$a['tracking_id']; ?>, '<?php echo $field; ?>', -1)">−</button>
                                            <span class="app-member-val" id="val-<?php echo (int)$a['tracking_id']; ?>-<?php echo $field; ?>"><?php echo $val; ?></span>
                                            <button type="button" <?php echo $isRegistered ? 'disabled' : ''; ?> onclick="adjustVal(<?php echo (int)$a['tracking_id']; ?>, '<?php echo $field; ?>', 1)">+</button>
                                        </div>
                                        <input type="hidden"
                                               name="<?php echo $field; ?>"
                                               id="hid-<?php echo (int)$a['tracking_id']; ?>-<?php echo $field; ?>"
                                               value="<?php echo $val; ?>">
                                    </div>
                                    <?php endforeach; ?>
                                </div>

                                <!-- Footer: two-row layout -->
                                <div class="app-arrival-footer">
                                    <!-- Row 1: total + match badge -->
                                    <div class="app-footer-top">
                                        <div class="app-total-wrap">
                                            <div class="app-total-num" id="total-<?php echo (int)$a['tracking_id']; ?>"><?php echo $profileTotal; ?></div>
                                            <div class="app-total-label">&nbsp;total present</div>
                                        </div>
                                        <span class="profile-match match-ok"
                                              id="match-<?php echo (int)$a['tracking_id']; ?>">
                                            <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                            Matches profile
                                        </span>
                                    </div>

                                    <!-- Row 2: two equal buttons -->
                                    <div class="app-footer-actions">
                                        <button type="button"
                                                class="btn-decline"
                                                onclick="openDeclineModal(<?php echo (int)$a['tracking_id']; ?>, '<?php echo htmlspecialchars(addslashes($a['full_name'])); ?>')">
                                            <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                            Decline
                                        </button>
                                        <button type="submit" class="btn-record-arrival" <?php echo $isRegistered ? 'disabled' : ''; ?>>
                                            <svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="16 11 18 13 22 9"/></svg>
                                            Record Arrived
                                        </button>
                                    </div>
                                </div>

                                <!-- Hidden profile data for match check -->
                                <input type="hidden"
                                       id="profile-total-<?php echo (int)$a['tracking_id']; ?>"
                                       value="<?php echo $profileTotal; ?>"
                                       <?php foreach (demo_field_keys() as $dk): ?>
                                       data-<?php echo str_replace('_', '-', $dk); ?>="<?php echo (int)$a[$dk]; ?>"
                                       <?php endforeach; ?>>
                            </form>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>
</div>

<script>
const DEMO_FIELDS = <?php echo json_encode(demo_field_keys()); ?>;
/* ── Sidebar ── */
function openMenu()  { document.getElementById('sidebar').classList.add('open'); document.getElementById('drawerOverlay').classList.add('open'); document.body.style.overflow = 'hidden'; }
function closeMenu() { document.getElementById('sidebar').classList.remove('open'); document.getElementById('drawerOverlay').classList.remove('open'); document.body.style.overflow = ''; }
document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeMenu(); closeDeclineModal(); closeLogoutModal(); } });

/* ── Logout modal ── */
function openLogoutModal()  { closeMenu(); document.getElementById('logoutModal').classList.add('open'); document.body.style.overflow = 'hidden'; }
function closeLogoutModal() { document.getElementById('logoutModal').classList.remove('open'); document.body.style.overflow = ''; }
document.getElementById('logoutModal').addEventListener('click', function(e) { if (e.target === this) closeLogoutModal(); });

/* ── Counter adjust + match badge ── */
function adjustVal(trackingId, field, delta) {
    const valEl = document.getElementById('val-' + trackingId + '-' + field);
    const hidEl = document.getElementById('hid-' + trackingId + '-' + field);
    if (!valEl || !hidEl) return;
    let next = Math.max(0, parseInt(valEl.textContent, 10) + delta);
    valEl.textContent = next;
    hidEl.value = next;

    let newTotal = DEMO_FIELDS
        .reduce((s, f) => s + (parseInt(document.getElementById('hid-' + trackingId + '-' + f)?.value, 10) || 0), 0);
    document.getElementById('total-' + trackingId).textContent = newTotal;

    const profileEl = document.getElementById('profile-total-' + trackingId);
    const matchEl   = document.getElementById('match-' + trackingId);
    if (!profileEl || !matchEl) return;

    let allMatch = true;
    DEMO_FIELDS.forEach(f => {
        const attr = f.replace(/_/g, '-');
        const expected = parseInt(profileEl.dataset[attr], 10) || 0;
        const actual   = parseInt(document.getElementById('hid-' + trackingId + '-' + f)?.value, 10) || 0;
        if (expected !== actual) allMatch = false;
    });
    const profileTotal = parseInt(profileEl.value, 10) || 0;
    if (newTotal !== profileTotal) allMatch = false;

    if (allMatch) {
        matchEl.className = 'profile-match match-ok';
        matchEl.innerHTML = '<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> Matches profile';
    } else {
        matchEl.className = 'profile-match match-diff';
        matchEl.innerHTML = '<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg> Count adjusted';
    }
}

/* ── Confirm arrival ── */
function confirmArrival(form) {
    const card    = form.closest('.app-arrival-card');
    const nameEl  = card.querySelector('.app-arrival-name');
    const totalEl = card.querySelector('[id^="total-"]');
    const name    = nameEl  ? nameEl.textContent.trim()  : 'this evacuee';
    const total   = totalEl ? totalEl.textContent.trim() : '?';
    return confirm('Record arrival for ' + name + ' — ' + total + ' person(s)?\n\nThis will mark them as arrived and add them to the occupancy count.');
}

/* ── Decline modal ── */
let _declineTrackingId = null;

function openDeclineModal(trackingId, name) {
    _declineTrackingId = trackingId;
    document.getElementById('modalPersonName').textContent = name;
    document.getElementById('declineReason').value = '';
    document.getElementById('declineModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeDeclineModal() {
    document.getElementById('declineModal').classList.remove('open');
    document.body.style.overflow = '';
    _declineTrackingId = null;
}
document.getElementById('declineModal').addEventListener('click', function(e) {
    if (e.target === this) closeDeclineModal();
});

function submitDecline() {
    if (!_declineTrackingId) return;
    document.getElementById('declineTrackingId').value  = _declineTrackingId;
    document.getElementById('declineReasonHidden').value = document.getElementById('declineReason').value;
    document.getElementById('declineForm').submit();
}

/* ── Toast auto-hide ── */
const toast = document.querySelector('.checkin-toast');
if (toast) setTimeout(() => { toast.style.display = 'none'; }, 4200);
</script>
</body>
</html>