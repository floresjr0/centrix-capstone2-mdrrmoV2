<?php
require_once __DIR__ . '/../pages/session.php';
require_login('coordinator');
require_once __DIR__ . '/../pages/center_helpers.php';
require_once __DIR__ . '/../pages/demographic_helpers.php';
require_once __DIR__ . '/../pages/family_adjustment.php';
require_once __DIR__ . '/../pages/registration_member_helpers.php';

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

// Handle adjustments (legacy form POST — online fallback)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'adjust') {
        $regId = (int)($_POST['reg_id'] ?? 0);
        $field = $_POST['field'] ?? '';
        $delta = (int)($_POST['delta'] ?? 0);
        apply_family_adjustment($pdo, $centerId, $regId, $field, $delta, null);
        header('Location: center_registrations.php?id=' . $centerId);
        exit;
    }
    if ($action === 'add_member') {
        rm_add_registration_member($pdo, $centerId, (int)($_POST['reg_id'] ?? 0), $_POST);
        header('Location: center_registrations.php?id=' . $centerId . '&updated=1');
        exit;
    }
    if ($action === 'remove_member') {
        rm_remove_registration_member($pdo, $centerId, (int)($_POST['reg_id'] ?? 0), (int)($_POST['member_id'] ?? 0));
        header('Location: center_registrations.php?id=' . $centerId . '&updated=1');
        exit;
    }
}

// Fetch registrations
$regsStmt = $pdo->prepare("SELECT r.*, b.name AS barangay_name
                           FROM evac_registrations r
                           JOIN barangays b ON b.id = r.barangay_id
                           WHERE r.center_id = ?
                           ORDER BY r.created_at DESC");
$regsStmt->execute([$centerId]);
$registrations = $regsStmt->fetchAll();

$useMemberRegs = rm_table_exists($pdo, 'evac_registration_members');
$registrationMembers = [];
foreach ($registrations as $r) {
    $rid = (int)$r['id'];
    $registrationMembers[$rid] = $useMemberRegs ? rm_list_registration_members($pdo, $rid, true) : [];
}
$rosterJson = array_map(fn($row) => rm_registration_to_roster_item($row, $pdo), $registrations);

// Unique barangays present in this list, for the filter dropdown
$usedBarangays = [];
foreach ($registrations as $r) {
    $usedBarangays[$r['barangay_name']] = true;
}
$usedBarangays = array_keys($usedBarangays);
sort($usedBarangays);

$occ      = get_center_occupancy($centerId);
$pct      = round($occ['percent']);
$barColor = $pct >= 100 ? '#dc2626' : ($pct >= 75 ? '#d97706' : '#16a34a');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Registered Families – <?php echo htmlspecialchars($center['name']); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Geist:wght@300;400;500;600;700;800;900&family=Geist+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../asset/css/center_registrations.css">
    <link rel="stylesheet" href="../asset/css/coordinator_components.css">
</head>
<body>

<div class="bg-blobs" aria-hidden="true">
    <div class="bg-blob b1"></div>
    <div class="bg-blob b2"></div>
    <div class="bg-blob b3"></div>
    <div class="bg-blob b4"></div>
</div>

<div class="drawer-overlay" id="drawerOverlay" onclick="closeMenu()"></div>

<!-- Logout Confirmation Modal -->
<div class="logout-modal-overlay" id="logoutModal" role="dialog" aria-modal="true" aria-labelledby="logoutModalTitle">
    <div class="logout-modal-box">
        <div class="logout-modal-icon">
            <svg viewBox="0 0 24 24">
                <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/>
                <polyline points="16 17 21 12 16 7"/>
                <line x1="21" y1="12" x2="9" y2="12"/>
            </svg>
        </div>
        <div class="logout-modal-title" id="logoutModalTitle">Log out?</div>
        <p class="logout-modal-desc">You will be signed out of the system. Any unsaved changes will be lost.</p>
        <div class="logout-modal-btns">
            <button class="logout-modal-btn-cancel" onclick="closeLogoutModal()">Cancel</button>
            <a href="../pages/logout.php" class="logout-modal-btn-confirm">Yes, Log Out</a>
        </div>
    </div>
</div>

<div class="layout">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-brand-row">
                <div class="brand-logo-sm"><img src="../img/mdrrmo.png" alt="MDRRMO Logo"></div>
                <div><div class="brand-name-sm">MDRRMO</div><div class="brand-tagline-sm">#BidaAngLagingHanda</div></div>
            </div>
            <button class="sidebar-close" onclick="closeMenu()"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
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
            <a href="index.php" class="nav-item"><span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M3 9.5L12 3l9 6.5V20a1 1 0 01-1 1H5a1 1 0 01-1-1V9.5z"/><polyline points="9 21 9 12 15 12 15 21"/></svg></span>Dashboard</a>
            <a href="index.php" class="nav-item active"><span class="nav-icon"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 21V9h6v12"/><path d="M3 9h18"/></svg></span>Centers</a>
        </nav>
        <div class="sidebar-status"><span class="status-dot-green"></span>SYSTEM ONLINE</div>
        <div class="sidebar-footer">
            <!-- Logout triggers modal instead of direct link -->
            <button class="logout-btn" onclick="openLogoutModal()">
                <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Log Out
            </button>
        </div>
    </aside>

    <!-- Bottom navigation ("Registrations" active) -->
    <nav class="bottom-nav">
        <div class="bottom-nav-inner">
            <a href="index.php" class="bottom-nav-item">
                <span class="bottom-nav-icon"><svg viewBox="0 0 24 24"><path d="M3 9.5L12 3l9 6.5V20a1 1 0 01-1 1H5a1 1 0 01-1-1V9.5z"/><polyline points="9 21 9 12 15 12 15 21"/></svg></span>
                Dashboard
                <span class="bottom-nav-dot"></span>
            </a>
            <a href="center_app_arrivals.php?id=<?php echo $centerId; ?>" class="bottom-nav-item">
                <span class="bottom-nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg></span>
                App Arrivals
                <span class="bottom-nav-dot"></span>
            </a>
            <a href="center_walkin.php?id=<?php echo $centerId; ?>" class="bottom-nav-item">
                <span class="bottom-nav-icon"><svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg></span>
                Walk-in
                <span class="bottom-nav-dot"></span>
            </a>
            <a href="center_registrations.php?id=<?php echo $centerId; ?>" class="bottom-nav-item active">
                <span class="bottom-nav-icon"><svg viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg></span>
                Registrations
                <span class="bottom-nav-dot"></span>
            </a>
            <!-- Logout triggers modal instead of direct link -->
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
            <?php $coordOfflinePage = 'registrations'; include __DIR__ . '/_offline_bootstrap.php'; ?>
            <script>window.MDRRMO_REGISTRATIONS_ROSTER = <?php echo json_encode($rosterJson, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
            <div>
                <h1 class="page-heading">Registered <span>Families</span></h1>
                <div class="page-subnav">
                    <a href="center_app_arrivals.php?id=<?php echo $centerId; ?>">App Arrivals</a>
                    <a href="center_walkin.php?id=<?php echo $centerId; ?>">Walk-in Family</a>
                    <a href="center_registrations.php?id=<?php echo $centerId; ?>" class="active">Registered Families</a>
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

            <!-- Registered Families Table + Mobile Cards -->
            <section class="card">
                <div class="card-header">
                    <div class="card-header-icon"><svg viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg></div>
                    <h2>Occupant List</h2>
                </div>

                <?php if (!$registrations): ?>
                    <div class="no-data">
                        <div class="no-data-icon"><svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg></div>
                        No families have been registered yet.
                    </div>
                <?php else: ?>
                    <!-- Search + filter toolbar -->
                    <div class="reg-toolbar">
                        <div class="reg-search-wrap">
                            <svg class="reg-search-icon" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            <input type="text" id="regSearchInput" class="reg-search-input" placeholder="Search by name or contact number…" autocomplete="off">
                        </div>
                        <select id="regBarangayFilter" class="reg-filter-select">
                            <option value="">All Barangays</option>
                            <?php foreach ($usedBarangays as $bname): ?>
                            <option value="<?php echo htmlspecialchars(mb_strtolower($bname)); ?>"><?php echo htmlspecialchars($bname); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="reg-count-badge" id="regCountBadge"><?php echo count($registrations); ?> families</span>
                    </div>

                    <!-- Desktop table -->
                    <div class="table-wrap">
                        <table class="table" id="regTable">
                            <thead>
                                <tr><th>Head</th><th>Contact</th><th>Birthday</th><th>Barangay</th><?php if ($useMemberRegs): ?><th>Members</th><?php endif; ?><?php foreach (DEMO_FIELDS as $label): ?><th><?php echo htmlspecialchars($label); ?></th><?php endforeach; ?><th>Total</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($registrations as $r):
                                $rid = (int)$r['id'];
                                $members = $registrationMembers[$rid] ?? [];
                                $isMemberMode = ($r['registration_mode'] ?? 'aggregate') === 'members' || count($members) > 0;
                                $expectedTotal = isset($r['expected_total_members']) ? (int)$r['expected_total_members'] : null;
                            ?>
                                <tr class="reg-row"
                                    data-reg-id="<?php echo $rid; ?>"
                                    data-name="<?php echo htmlspecialchars(mb_strtolower($r['family_head_name'] . ' ' . ($r['contact_number'] ?? ''))); ?>"
                                    data-barangay="<?php echo htmlspecialchars(mb_strtolower($r['barangay_name'])); ?>">
                                    <td class="cell-head"><?php echo htmlspecialchars($r['family_head_name']); ?></td>
                                    <td><?php echo htmlspecialchars($r['contact_number'] ?? ''); ?></td>
                                    <td><?php echo !empty($r['birthday']) ? date('M d, Y', strtotime($r['birthday'])) : ''; ?></td>
                                    <td><?php echo htmlspecialchars($r['barangay_name']); ?></td>
                                    <?php if ($useMemberRegs): ?>
                                    <td class="cell-members">
                                        <button type="button" class="btn-toggle-members" onclick="toggleMemberPanel(<?php echo $rid; ?>)">
                                            <?php echo count($members); ?> present
                                            <?php if ($expectedTotal !== null && $expectedTotal !== (int)$r['total_members']): ?>
                                            <span class="expected-tag">(exp. <?php echo $expectedTotal; ?>)</span>
                                            <?php endif; ?>
                                        </button>
                                    </td>
                                    <?php endif; ?>
                                    <?php foreach (demo_field_keys() as $field): ?>
                                    <td>
                                        <?php if ($isMemberMode && $useMemberRegs): ?>
                                        <span class="adjust-val"><?php echo (int)$r[$field]; ?></span>
                                        <?php else: ?>
                                        <div class="adjust-cell">
                                            <form method="post" class="inline-adjust">
                                                <input type="hidden" name="action"  value="adjust">
                                                <input type="hidden" name="reg_id"  value="<?php echo $rid; ?>">
                                                <input type="hidden" name="field"   value="<?php echo $field; ?>">
                                                <input type="hidden" name="delta"   value="-1">
                                                <button type="submit">−</button>
                                            </form>
                                            <span class="adjust-val"><?php echo (int)$r[$field]; ?></span>
                                            <form method="post" class="inline-adjust">
                                                <input type="hidden" name="action"  value="adjust">
                                                <input type="hidden" name="reg_id"  value="<?php echo $rid; ?>">
                                                <input type="hidden" name="field"   value="<?php echo $field; ?>">
                                                <input type="hidden" name="delta"   value="1">
                                                <button type="submit">+</button>
                                            </form>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <?php endforeach; ?>
                                    <td class="cell-total"><?php echo (int)$r['total_members']; ?></td>
                                </tr>
                                <?php if ($useMemberRegs): ?>
                                <tr class="member-panel-row" id="member-panel-<?php echo $rid; ?>" hidden>
                                    <td colspan="<?php echo 5 + count(DEMO_FIELDS) + 1; ?>">
                                        <?php if ($members): ?>
                                        <div class="member-table-scroll">
                                        <table class="reg-member-table">
                                            <thead><tr><th>Name</th><th>Sex</th><th>Birthday</th><th>Category</th><th>PWD</th><th>Pregnant</th><th>Lactating</th><th></th></tr></thead>
                                            <tbody>
                                            <?php foreach ($members as $m): ?>
                                            <tr>
                                                <td data-label="Name"><?php echo htmlspecialchars($m['full_name']); ?><?php if ($m['is_household_head']): ?> <span class="head-tag">Head</span><?php endif; ?></td>
                                                <td data-label="Sex"><?php echo htmlspecialchars(ucfirst($m['sex'] ?? '')); ?></td>
                                                <td data-label="Birthday"><?php echo !empty($m['birthday']) ? htmlspecialchars($m['birthday']) : '—'; ?></td>
                                                <td data-label="Category"><?php echo htmlspecialchars($m['primary_label']); ?></td>
                                                <td data-label="PWD"><?php echo $m['is_pwd'] ? 'Yes' : '—'; ?></td>
                                                <td data-label="Pregnant"><?php echo $m['is_pregnant'] ? 'Yes' : '—'; ?></td>
                                                <td data-label="Lactating"><?php echo $m['is_lactating'] ? 'Yes' : '—'; ?></td>
                                                <td data-label="">
                                                    <form method="post" class="inline-adjust" onsubmit="return confirm('Mark this person as left / not present?');">
                                                        <input type="hidden" name="action" value="remove_member">
                                                        <input type="hidden" name="reg_id" value="<?php echo $rid; ?>">
                                                        <input type="hidden" name="member_id" value="<?php echo (int)$m['id']; ?>">
                                                        <button type="submit" class="btn-member-out">Remove</button>
                                                    </form>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                        </div>
                                        <?php else: ?>
                                        <p class="member-panel-empty">No individual member records — aggregate counts only.</p>
                                        <?php endif; ?>
                                        <form method="post" class="add-late-member-form">
                                            <input type="hidden" name="action" value="add_member">
                                            <input type="hidden" name="reg_id" value="<?php echo $rid; ?>">
                                            <strong>Add late arrival</strong>
                                            <div class="add-late-member-grid">
                                                <input type="text" name="full_name" placeholder="Full name" required>
                                                <select name="sex"><option value="">Sex</option><option value="male">Male</option><option value="female">Female</option></select>
                                                <input type="date" name="birthday" placeholder="Birthday">
                                                <select name="primary_category" required>
                                                    <option value="adults">Adult</option>
                                                    <option value="children">Child</option>
                                                    <option value="seniors">Senior</option>
                                                    <option value="infants_toddlers">Infant/Toddler</option>
                                                </select>
                                            </div>
                                            <div class="late-arrival-flags">
                                                <span class="late-arrival-flags-label">Special conditions</span>
                                                <div class="late-arrival-flags-row">
                                                    <label class="late-flag-chip"><input type="checkbox" name="is_pwd" value="1"><span>PWD</span></label>
                                                    <label class="late-flag-chip"><input type="checkbox" name="is_pregnant" value="1"><span>Pregnant</span></label>
                                                    <label class="late-flag-chip"><input type="checkbox" name="is_lactating" value="1"><span>Lactating</span></label>
                                                </div>
                                            </div>
                                            <div class="add-late-member-actions">
                                                <button type="submit">Add late arrival</button>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="reg-no-results" id="regNoResultsTable">No families match your search.</div>
                    </div>

                    <!-- Mobile cards -->
                    <div class="reg-cards" id="regCards">
                        <?php foreach ($registrations as $r):
                            $rid = (int)$r['id'];
                            $members = $registrationMembers[$rid] ?? [];
                            $isMemberMode = ($r['registration_mode'] ?? 'aggregate') === 'members' || count($members) > 0;
                            $expectedTotal = isset($r['expected_total_members']) ? (int)$r['expected_total_members'] : null;
                        ?>
                        <div class="reg-card reg-row"
                             data-reg-id="<?php echo $rid; ?>"
                             data-name="<?php echo htmlspecialchars(mb_strtolower($r['family_head_name'] . ' ' . ($r['contact_number'] ?? ''))); ?>"
                             data-barangay="<?php echo htmlspecialchars(mb_strtolower($r['barangay_name'])); ?>">
                            <div class="reg-card-head">
                                <div>
                                    <div class="reg-card-name"><?php echo htmlspecialchars($r['family_head_name']); ?></div>
                                    <div class="reg-card-barangay"><?php echo htmlspecialchars($r['barangay_name']); ?></div>
                                    <div class="reg-card-contact"><?php echo htmlspecialchars($r['contact_number'] ?? ''); ?></div>
                                    <div class="reg-card-bday"><?php echo !empty($r['birthday']) ? date('M d, Y', strtotime($r['birthday'])) : ''; ?></div>
                                </div>
                                <div class="reg-card-total">
                                    <div class="reg-card-total-num"><?php echo (int)$r['total_members']; ?></div>
                                    <div class="reg-card-total-label">Total</div>
                                </div>
                            </div>
                            <div class="reg-card-members">
                                <?php foreach (DEMO_FIELDS as $field => $label): ?>
                                <?php if ($isMemberMode && $useMemberRegs): ?>
                                <div class="reg-card-readonly-count">
                                    <span class="member-row-label"><?php echo $label; ?></span>
                                    <span class="adjust-val"><?php echo (int)$r[$field]; ?></span>
                                </div>
                                <?php else: ?>
                                <div class="member-row">
                                    <span class="member-row-label"><?php echo $label; ?></span>
                                    <div class="member-row-controls">
                                        <form method="post" class="inline-adjust">
                                            <input type="hidden" name="action" value="adjust">
                                            <input type="hidden" name="reg_id" value="<?php echo $rid; ?>">
                                            <input type="hidden" name="field"  value="<?php echo $field; ?>">
                                            <input type="hidden" name="delta"  value="-1">
                                            <button type="submit">−</button>
                                        </form>
                                        <span class="adjust-val"><?php echo (int)$r[$field]; ?></span>
                                        <form method="post" class="inline-adjust">
                                            <input type="hidden" name="action" value="adjust">
                                            <input type="hidden" name="reg_id" value="<?php echo $rid; ?>">
                                            <input type="hidden" name="field"  value="<?php echo $field; ?>">
                                            <input type="hidden" name="delta"  value="1">
                                            <button type="submit">+</button>
                                        </form>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                            <?php if ($useMemberRegs): ?>
                            <div class="reg-card-members-toolbar">
                                <button type="button" class="btn-toggle-members" onclick="toggleMemberPanel(<?php echo $rid; ?>)">
                                    <?php echo count($members); ?> present
                                    <?php if ($expectedTotal !== null && $expectedTotal !== (int)$r['total_members']): ?>
                                    <span class="expected-tag">(exp. <?php echo $expectedTotal; ?>)</span>
                                    <?php endif; ?>
                                </button>
                            </div>
                            <div class="member-panel-card" id="member-panel-card-<?php echo $rid; ?>">
                                <?php if ($members): ?>
                                <div class="member-table-scroll">
                                <table class="reg-member-table">
                                    <thead><tr><th>Name</th><th>Sex</th><th>Birthday</th><th>Category</th><th>PWD</th><th>Pregnant</th><th>Lactating</th><th></th></tr></thead>
                                    <tbody>
                                    <?php foreach ($members as $m): ?>
                                    <tr>
                                        <td data-label="Name"><?php echo htmlspecialchars($m['full_name']); ?><?php if ($m['is_household_head']): ?> <span class="head-tag">Head</span><?php endif; ?></td>
                                        <td data-label="Sex"><?php echo htmlspecialchars(ucfirst($m['sex'] ?? '')); ?></td>
                                        <td data-label="Birthday"><?php echo !empty($m['birthday']) ? htmlspecialchars($m['birthday']) : '—'; ?></td>
                                        <td data-label="Category"><?php echo htmlspecialchars($m['primary_label']); ?></td>
                                        <td data-label="PWD"><?php echo $m['is_pwd'] ? 'Yes' : '—'; ?></td>
                                        <td data-label="Pregnant"><?php echo $m['is_pregnant'] ? 'Yes' : '—'; ?></td>
                                        <td data-label="Lactating"><?php echo $m['is_lactating'] ? 'Yes' : '—'; ?></td>
                                        <td data-label="">
                                            <form method="post" class="inline-adjust" onsubmit="return confirm('Mark this person as left / not present?');">
                                                <input type="hidden" name="action" value="remove_member">
                                                <input type="hidden" name="reg_id" value="<?php echo $rid; ?>">
                                                <input type="hidden" name="member_id" value="<?php echo (int)$m['id']; ?>">
                                                <button type="submit" class="btn-member-out">Remove</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                                </div>
                                <?php else: ?>
                                <p class="member-panel-empty">No individual member records — aggregate counts only.</p>
                                <?php endif; ?>
                                <form method="post" class="add-late-member-form">
                                    <input type="hidden" name="action" value="add_member">
                                    <input type="hidden" name="reg_id" value="<?php echo $rid; ?>">
                                    <strong>Add late arrival</strong>
                                    <div class="add-late-member-grid">
                                        <input type="text" name="full_name" placeholder="Full name" required>
                                        <select name="sex"><option value="">Sex</option><option value="male">Male</option><option value="female">Female</option></select>
                                        <input type="date" name="birthday" placeholder="Birthday">
                                        <select name="primary_category" required>
                                            <option value="adults">Adult</option>
                                            <option value="children">Child</option>
                                            <option value="seniors">Senior</option>
                                            <option value="infants_toddlers">Infant/Toddler</option>
                                        </select>
                                    </div>
                                    <div class="late-arrival-flags">
                                        <span class="late-arrival-flags-label">Special conditions</span>
                                        <div class="late-arrival-flags-row">
                                            <label class="late-flag-chip"><input type="checkbox" name="is_pwd" value="1"><span>PWD</span></label>
                                            <label class="late-flag-chip"><input type="checkbox" name="is_pregnant" value="1"><span>Pregnant</span></label>
                                            <label class="late-flag-chip"><input type="checkbox" name="is_lactating" value="1"><span>Lactating</span></label>
                                        </div>
                                    </div>
                                    <div class="add-late-member-actions">
                                        <button type="submit">Add late arrival</button>
                                    </div>
                                </form>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        <div class="reg-no-results" id="regNoResultsCards">No families match your search.</div>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
</div>

<script>
/* ── Sidebar ── */
function openMenu() {
    document.getElementById('sidebar').classList.add('open');
    document.getElementById('drawerOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeMenu() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('drawerOverlay').classList.remove('open');
    document.body.style.overflow = '';
}

/* ── Logout modal ── */
function openLogoutModal() {
    closeMenu(); // close sidebar first if open
    document.getElementById('logoutModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeLogoutModal() {
    document.getElementById('logoutModal').classList.remove('open');
    document.body.style.overflow = '';
}
document.getElementById('logoutModal').addEventListener('click', function(e) {
    if (e.target === this) closeLogoutModal();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') { closeMenu(); closeLogoutModal(); }
});

/* ── Search + barangay filter for the occupant list (table + mobile cards) ── */
(function () {
    const searchInput    = document.getElementById('regSearchInput');
    const barangaySelect = document.getElementById('regBarangayFilter');
    const countBadge     = document.getElementById('regCountBadge');
    const noResultsTable = document.getElementById('regNoResultsTable');
    const noResultsCards = document.getElementById('regNoResultsCards');
    if (!searchInput || !barangaySelect) return;

    const rows = Array.from(document.querySelectorAll('.reg-row'));

    function applyFilters() {
        const q    = searchInput.value.trim().toLowerCase();
        const brgy = barangaySelect.value;
        let visibleCount = 0;

        rows.forEach(row => {
            const nameMatch = !q    || row.dataset.name.includes(q);
            const brgyMatch = !brgy || row.dataset.barangay === brgy;
            const show = nameMatch && brgyMatch;
            row.style.display = show ? '' : 'none';
            if (show) visibleCount++;
        });

        if (countBadge) countBadge.textContent = visibleCount + (visibleCount === 1 ? ' family' : ' families');
        const noneVisible = visibleCount === 0;
        if (noResultsTable) noResultsTable.style.display = noneVisible ? 'block' : 'none';
        if (noResultsCards) noResultsCards.style.display = noneVisible ? 'block' : 'none';
    }

    searchInput.addEventListener('input', applyFilters);
    barangaySelect.addEventListener('change', applyFilters);
})();

function toggleMemberPanel(regId) {
    const panel = document.getElementById('member-panel-' + regId);
    if (panel) panel.hidden = !panel.hidden;
    const card = document.getElementById('member-panel-card-' + regId);
    if (card) card.classList.toggle('open');
}
</script>
</body>
</html>