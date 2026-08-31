<?php
require_once __DIR__ . '/../pages/session.php';
require_login('admin');

require_once __DIR__ . '/../pages/center_helpers.php';
require_once __DIR__ . '/../pages/demographic_helpers.php';

$user = current_user();
$pdo  = db();

// Summary metrics
$summary = [
    'total_centers'     => 0,
    'total_evacuees'    => 0,
    'status_available'  => 0,
    'status_near'       => 0,
    'status_full'       => 0,
    'status_temp'       => 0,
    'status_closed'     => 0,
];

$row = $pdo->query("SELECT COUNT(*) AS c FROM evacuation_centers")->fetch();
if ($row) {
    $summary['total_centers'] = (int)$row['c'];
}

$row = $pdo->query("SELECT COALESCE(SUM(total_members),0) AS total FROM evac_registrations")->fetch();
if ($row) {
    $summary['total_evacuees'] = (int)$row['total'];
}

$st = $pdo->query("SELECT status, COUNT(*) AS c FROM evacuation_centers GROUP BY status");
foreach ($st as $s) {
    switch ($s['status']) {
        case 'available':
            $summary['status_available'] = (int)$s['c'];
            break;
        case 'near_capacity':
            $summary['status_near'] = (int)$s['c'];
            break;
        case 'full':
            $summary['status_full'] = (int)$s['c'];
            break;
        case 'temp_shelter':
            $summary['status_temp'] = (int)$s['c'];
            break;
        case 'closed':
            $summary['status_closed'] = (int)$s['c'];
            break;
    }
}

$centers = get_centers_with_occupancy();

// Evacuation Summary per Center with coordinator info and demographics
$evacSummaryStmt = $pdo->query("
    SELECT
        ec.id,
        ec.name AS center_name,
        b.name  AS barangay_name,
        ec.status,
        ec.max_capacity_people,

        -- Coordinator info
        u.full_name    AS coordinator_name,
        u.contact_number AS coordinator_contact,

        -- Demographics aggregated
        COALESCE(SUM(er.adults),   0) AS total_adults,
        COALESCE(SUM(er.children), 0) AS total_children,
        COALESCE(SUM(er.seniors),  0) AS total_seniors,
        COALESCE(SUM(er.pwds),     0) AS total_pwds,
        COALESCE(SUM(er.pregnant_women),    0) AS total_pregnant_women,
        COALESCE(SUM(er.lactating_mothers), 0) AS total_lactating_mothers,
        COALESCE(SUM(er.infants_toddlers),  0) AS total_infants_toddlers,
        COALESCE(SUM(er.total_members), 0) AS total_evacuees,
        COUNT(DISTINCT er.id) AS total_families

    FROM evacuation_centers ec
    LEFT JOIN barangays b        ON b.id = ec.barangay_id
    LEFT JOIN users u            ON u.id = ec.coordinator_user_id
    LEFT JOIN evac_registrations er ON er.center_id = ec.id
    GROUP BY ec.id
    ORDER BY total_evacuees DESC
");
$evacSummary = $evacSummaryStmt->fetchAll();

// Expected evacuees en route (same algorithm as coordinator dashboard)
$expectedCentersStmt = $pdo->query("
    SELECT
        ec.id,
        ec.name,
        ec.status,
        ec.max_capacity_people,
        b.name AS barangay_name,
        u.full_name AS coordinator_name,
        COALESCE(t.expected_count, 0) AS expected_count
    FROM evacuation_centers ec
    LEFT JOIN barangays b ON b.id = ec.barangay_id
    LEFT JOIN users u ON u.id = ec.coordinator_user_id
    LEFT JOIN (
        SELECT
            nt.center_id,
            SUM(COALESCE(ch.total_members, 1)) AS expected_count
        FROM evac_navigation_tracking nt
        LEFT JOIN family_profiles ch ON ch.user_id = nt.user_id
        WHERE nt.status = 'navigating'
        GROUP BY nt.center_id
    ) t ON t.center_id = ec.id
    WHERE ec.status != 'closed'
    ORDER BY expected_count DESC, ec.name ASC
");
$expectedCenters = $expectedCentersStmt->fetchAll();

$expectedByCenterId = [];
$totalExpectedEnRoute = 0;
foreach ($expectedCenters as $ec) {
    $expectedByCenterId[(int)$ec['id']] = (int)$ec['expected_count'];
    $totalExpectedEnRoute += (int)$ec['expected_count'];
}

$breakdownStmt = $pdo->query("
    SELECT
        nt.center_id,
        b.name AS barangay_name,
        SUM(COALESCE(ch.total_members, 1)) AS citizen_count
    FROM evac_navigation_tracking nt
    JOIN users u ON u.id = nt.user_id
    JOIN barangays b ON b.id = u.barangay_id
    LEFT JOIN family_profiles ch ON ch.user_id = nt.user_id
    WHERE nt.status = 'navigating'
      AND nt.center_id IN (SELECT id FROM evacuation_centers WHERE status != 'closed')
    GROUP BY nt.center_id, u.barangay_id
    ORDER BY citizen_count DESC
");
$expectedBreakdown = [];
foreach ($breakdownStmt->fetchAll() as $row) {
    $expectedBreakdown[(int)$row['center_id']][] = $row;
}

$arrivalsStmt = $pdo->query("
    SELECT
        nt.center_id,
        nt.id AS tracking_id,
        u.full_name,
        ub.name AS origin_barangay,
        COALESCE(ch.total_members, 1) AS total_members,
        nt.updated_at
    FROM evac_navigation_tracking nt
    JOIN users u ON u.id = nt.user_id
    JOIN barangays ub ON ub.id = u.barangay_id
    LEFT JOIN family_profiles ch ON ch.user_id = nt.user_id
    WHERE nt.status = 'navigating'
      AND nt.center_id IN (SELECT id FROM evacuation_centers WHERE status != 'closed')
    ORDER BY nt.center_id, nt.updated_at ASC
");
$arrivalsByCenter = [];
foreach ($arrivalsStmt->fetchAll() as $row) {
    $arrivalsByCenter[(int)$row['center_id']][] = $row;
}

// Latest weather + active disaster for quick admin view
// Live weather for San Ildefonso (no cron)
$lat = 15.0828;
$lon = 120.9417;

$weather = null;

if (defined('WEATHER_API_KEY') && WEATHER_API_KEY !== '') {
    $url = "https://api.openweathermap.org/data/2.5/weather?lat=$lat&lon=$lon&appid=" . WEATHER_API_KEY . "&units=metric";
    $json = @file_get_contents($url);

    if ($json !== false) {
        $data = json_decode($json, true);
        if (isset($data['main'])) {
            $tempC = (float)$data['main']['temp'];
            $humidity = (float)$data['main']['humidity'];

            // Heat index calculation
            $t = $tempC;
            $rh = $humidity;
            $heatIndex = $t;
            if ($t >= 27 && $rh >= 40) {
                $heatIndex = -8.784695 + 1.61139411*$t + 2.338549*$rh
                    - 0.14611605*$t*$rh - 0.012308094*($t*$t)
                    - 0.016424828*($rh*$rh) + 0.002211732*($t*$t*$rh)
                    + 0.00072546*($t*$rh*$rh) - 0.000003582*($t*$t*$rh*$rh);
            }

            // Comfort level
            $level = 'low';
            if ($heatIndex >= 41) {
                $level = 'extreme';
            } elseif ($heatIndex >= 38) {
                $level = 'high';
            } elseif ($heatIndex >= 32) {
                $level = 'medium';
            }

            $condition = $data['weather'][0]['description'] ?? 'N/A';

            $weather = [
                'temp_c' => $tempC,
                'humidity' => $humidity,
                'heat_index' => round($heatIndex),
                'level' => $level,
                'condition_text' => $condition
            ];
        }
    }
}
$disasterStmt = $pdo->query("SELECT * FROM disasters WHERE status = 'ongoing' ORDER BY level DESC, started_at DESC LIMIT 1");
$activeDisaster = $disasterStmt->fetch();
// Sidebar badges
$_badgeCenters       = (int)$pdo->query("SELECT COUNT(*) FROM evacuation_centers")->fetchColumn();
$_badgeOngoing       = (int)$pdo->query("SELECT COUNT(*) FROM disasters WHERE status = 'ongoing'")->fetchColumn();
$_badgeAnnouncements = (int)$pdo->query("SELECT COUNT(*) FROM announcements")->fetchColumn();
$_badgeEvacuees      = (int)$pdo->query("SELECT COALESCE(SUM(total_members),0) FROM evac_registrations")->fetchColumn();
// $_badgeUsers        = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

// User summary stats
$uSummary = $pdo->query("
    SELECT
        COUNT(*) AS total_all,
        SUM(role = 'admin') AS total_admin,
        SUM(role = 'coordinator') AS total_coordinator,
        SUM(role = 'citizen') AS total_citizen,
        SUM(is_active = 1) AS total_active,
        SUM(is_active = 0) AS total_inactive
    FROM users
")->fetch();

// Chart datasets for dashboard analytics
$chartDemographics = [
    'Adults'    => (int)array_sum(array_column($evacSummary, 'total_adults')),
    'Children'  => (int)array_sum(array_column($evacSummary, 'total_children')),
    'Seniors'   => (int)array_sum(array_column($evacSummary, 'total_seniors')),
    'PWD'       => (int)array_sum(array_column($evacSummary, 'total_pwds')),
    'Pregnant'  => (int)array_sum(array_column($evacSummary, 'total_pregnant_women')),
    'Lactating' => (int)array_sum(array_column($evacSummary, 'total_lactating_mothers')),
    'Infants'   => (int)array_sum(array_column($evacSummary, 'total_infants_toddlers')),
];

$chartCenterStatus = [
    'Available'     => $summary['status_available'],
    'Near Capacity' => $summary['status_near'],
    'Full'          => $summary['status_full'],
    'Temp Shelter'  => $summary['status_temp'],
    'Closed'        => $summary['status_closed'],
];

$chartUsers = [
    'Admins'       => (int)$uSummary['total_admin'],
    'Coordinators' => (int)$uSummary['total_coordinator'],
    'Citizens'     => (int)$uSummary['total_citizen'],
];

$chartCapacityCenters = [];
foreach (array_slice($evacSummary, 0, 6) as $row) {
    $cid   = (int)$row['id'];
    $label = $row['center_name'];
    if (mb_strlen($label) > 22) {
        $label = mb_substr($label, 0, 20) . '…';
    }
    $chartCapacityCenters[] = [
        'id'         => $cid,
        'label'      => $label,
        'registered' => (int)$row['total_evacuees'],
        'enRoute'    => $expectedByCenterId[$cid] ?? 0,
        'max'        => (int)$row['max_capacity_people'],
    ];
}

$trendDays = [];
for ($i = 6; $i >= 0; $i--) {
    $trendDays[] = date('Y-m-d', strtotime("-{$i} days"));
}
$trendMap = [];
$trendStmt = $pdo->query("
    SELECT DATE(created_at) AS day, COALESCE(SUM(total_members), 0) AS total
    FROM evac_registrations
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(created_at)
");
foreach ($trendStmt->fetchAll() as $tr) {
    $trendMap[$tr['day']] = (int)$tr['total'];
}
$chartTrend = [
    'labels' => array_map(fn($d) => date('M j', strtotime($d)), $trendDays),
    'values' => array_map(fn($d) => $trendMap[$d] ?? 0, $trendDays),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>MDRRMO Dashboard | San Ildefonso, Bulacan</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../asset/css/admin_index.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link href="https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
    <style>
        #adminMap .maplibregl-map,
        #adminMap .maplibregl-canvas-container { z-index: 0 !important; }
    </style>
</head>
<body>
    <div class="app-wrapper">
        <!-- Sidebar Toggle Button - Outside Sidebar -->
        <div class="sidebar-toggle-btn" id="sidebarToggleBtn">
            <i class="fas fa-chevron-left"></i>
        </div>

        <!-- Sidebar - No Scrollbar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <div class="logo-image">
                        <img src="https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcRqukasrXgrajWG753eZaSE0F17M3XFWroASQ&s" alt="MDRRMO Logo" onerror="this.style.display='none'; this.parentElement.innerHTML='<span class=logo-icon-fallback></span>';">
                    </div>
                    <div class="logo-text">
                        <h3>MDRRMO</h3>
                        <p>San Ildefonso</p>
                    </div>
                </div>
            </div>

            <div class="sidebar-content">
                <div class="sidebar-section">
                    <div class="sidebar-section-title">Main</div>
                    <ul class="sidebar-menu">
                        <li><a href="#" class="sidebar-link active"><i class="fas fa-home"></i> <span>Dashboard</span></a></li>
                        <li><a href="centers.php" class="sidebar-link"><i class="fas fa-map-marker-alt"></i> <span>Evacuation Centers</span>
                    <?php if($_badgeCenters > 0): ?><span class="sidebar-badge"><?php echo $_badgeCenters; ?></span><?php endif; ?></a></li>
                        <li><a href="users.php" class="sidebar-link"><i class="fas fa-users"></i> <span>User Management</span></a></li>
                        <li><a href="disasters.php" class="sidebar-link"><i class="fas fa-exclamation-triangle"></i> <span>Disasters</span>
                        <?php if($_badgeOngoing > 0): ?><span class="sidebar-badge"><?php echo $_badgeOngoing; ?></span><?php endif; ?></a></li>
                    </ul>
                </div>

                <div class="sidebar-section">
                    <div class="sidebar-section-title">Operations</div>
                    <ul class="sidebar-menu">
                        <li><a href="announcements.php" class="sidebar-link "><i class="fas fa-bullhorn"></i> <span>Announcements</span> <?php if($_badgeAnnouncements > 0): ?><span class="sidebar-badge"><?php echo $_badgeAnnouncements; ?></span><?php endif; ?></a></li>
                    </ul>
                </div>

                <div class="sidebar-section">
                    <div class="sidebar-section-title">Monitoring</div>
                    <ul class="sidebar-menu">
                        <li><a href="maps.php" class="sidebar-link"><i class="fas fa-map"></i> <span>Maps</span></a></li>
                        <li><a href="evacuees.php" class="sidebar-link"><i class="fas fa-people-arrows"></i> <span>Evacuees</span><?php if($_badgeEvacuees > 0): ?><span class="sidebar-badge"><?php echo number_format($_badgeEvacuees); ?></span><?php endif; ?></a></li>
                    </ul>
                </div>

                <div class="sidebar-section">
                    <div class="sidebar-section-title">Settings</div>
                    <ul class="sidebar-menu">
                        <li><a href="../pages/logout.php" class="sidebar-link"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a></li>
                    </ul>
                </div>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main-content" id="mainContent">
            <!-- Top Navigation -->
            <div class="top-nav">
                <div class="page-title">
                    <button class="mobile-toggle" id="mobileToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    <h1>Dashboard</h1>
                </div>

                <div class="user-menu">
                    <div class="user-profile">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($user['full_name'] ?? 'A', 0, 1)); ?>
                        </div>
                        <div class="user-info">
                            <span class="user-name"><?php echo htmlspecialchars($user['full_name'] ?? 'Admin'); ?></span>
                            <span class="user-role">MDRRMO Administrator</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Dashboard Content -->
            <div class="dashboard">
                <!-- Welcome Bar -->
                <div class="welcome-bar">
                    <div class="welcome-text">
                        <h2>Welcome back, <?php echo htmlspecialchars(explode(' ', $user['full_name'] ?? 'Admin')[0]); ?>!</h2>
                        <p>San Ildefonso, Bulacan</p>
                    </div>
                    <div class="date-badge">
                        <?php echo date('F j, Y'); ?>
                    </div>
                </div>

                <!-- Minimized Stat Cards -->
                <div class="stats-row">
                    <div class="stat-card">
                        <div class="stat-icon-small"><i class="fas fa-building"></i></div>
                        <div class="stat-content">
                            <div class="stat-value-small"><?php echo $summary['total_centers']; ?></div>
                            <div class="stat-label-small">Centers</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon-small"><i class="fas fa-users"></i></div>
                        <div class="stat-content">
                            <div class="stat-value-small"><?php echo number_format($summary['total_evacuees']); ?></div>
                            <div class="stat-label-small">Evacuees</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon-small"><i class="fas fa-check-circle" style="color: var(--map-green);"></i></div>
                        <div class="stat-content">
                            <div class="stat-value-small"><?php echo $summary['status_available']; ?></div>
                            <div class="stat-label-small">Available</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon-small"><i class="fas fa-exclamation-triangle" style="color: var(--map-yellow);"></i></div>
                        <div class="stat-content">
                            <div class="stat-value-small"><?php echo $summary['status_near']; ?></div>
                            <div class="stat-label-small">Near Cap</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon-small"><i class="fas fa-times-circle" style="color: var(--map-red);"></i></div>
                        <div class="stat-content">
                            <div class="stat-value-small"><?php echo $summary['status_full']; ?></div>
                            <div class="stat-label-small">Full</div>
                        </div>
                    </div>
                    <div class="stat-card stat-card-enroute">
                        <div class="stat-icon-small"><i class="fas fa-route"></i></div>
                        <div class="stat-content">
                            <div class="stat-value-small" id="total-expected"><?php echo number_format($totalExpectedEnRoute); ?></div>
                            <div class="stat-label-small">En Route</div>
                        </div>
                    </div>
                </div>

                <!-- ==========================================================
                     REDESIGNED ANALYTICS CHARTS SECTION
                     ========================================================== -->
                <section class="charts-section">
                    <div class="charts-section-header">
                        <h3></i> Analytics Overview</h3>
                        <p>Visual summary of evacuation operations and system activity</p>
                    </div>

                    <div class="charts-grid">
                        <!-- 1. Center Status (doughnut) -->
                        <div class="chart-card">
                            <div class="chart-card-header">
                                <h4>Center Status</h4>
                                <span>Availability distribution</span>
                            </div>
                            <div class="chart-wrap chart-wrap-donut">
                                <canvas id="chartCenterStatus"></canvas>
                            </div>
                        </div>

                        <!-- 2. Platform Users (doughnut) -->
                        <div class="chart-card">
                            <div class="chart-card-header">
                                <h4>Platform Users</h4>
                                <span>Accounts by role</span>
                            </div>
                            <div class="chart-wrap chart-wrap-donut">
                                <canvas id="chartUsers"></canvas>
                            </div>
                        </div>

                        <!-- 3. Evacuee Demographics (horizontal bar) -->
                        <div class="chart-card">
                            <div class="chart-card-header">
                                <h4>Evacuee Demographics</h4>
                                <span>Registered population breakdown</span>
                            </div>
                            <div class="chart-wrap chart-wrap-bar">
                                <canvas id="chartDemographics"></canvas>
                            </div>
                        </div>

                        <!-- 4. Center Occupancy (stacked horizontal bar) -->
                        <div class="chart-card">
                            <div class="chart-card-header">
                                <h4>Center Occupancy</h4>
                                <span>Registered + en route vs capacity (top centers)</span>
                            </div>
                            <div class="chart-wrap chart-wrap-bar">
                                <canvas id="chartCapacity"></canvas>
                            </div>
                        </div>

                        <!-- 5. Evacuee Registrations (line, full width) -->
                        <div class="chart-card chart-card-full">
                            <div class="chart-card-header">
                                <h4>Evacuee Registrations</h4>
                                <span>People registered over the last 7 days</span>
                            </div>
                            <div class="chart-wrap chart-wrap-line">
                                <canvas id="chartTrend"></canvas>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Main Two Column Layout (unchanged) -->
                <div class="main-grid">
                    <!-- Quick Stats Mini Cards — User Summary -->
                    <div class="stats-mini-grid">
                        <div class="stat-mini-card">
                            <div class="stat-mini-label">All Users</div>
                            <div class="stat-mini-value"><?php echo number_format((int)$uSummary['total_all']); ?></div>
                        </div>
                        <div class="stat-mini-card">
                            <div class="stat-mini-label">Admins</div>
                            <div class="stat-mini-value" style="color: #3498DB;"><?php echo number_format((int)$uSummary['total_admin']); ?></div>
                        </div>
                        <div class="stat-mini-card">
                            <div class="stat-mini-label">Coordinators</div>
                            <div class="stat-mini-value" style="color: #FFC107;"><?php echo number_format((int)$uSummary['total_coordinator']); ?></div>
                        </div>
                        <div class="stat-mini-card">
                            <div class="stat-mini-label">Citizens</div>
                            <div class="stat-mini-value" style="color: var(--map-green);"><?php echo number_format((int)$uSummary['total_citizen']); ?></div>
                        </div>
                        <div class="stat-mini-card">
                            <div class="stat-mini-label">Active</div>
                            <div class="stat-mini-value" style="color: #2E7D32;"><?php echo number_format((int)$uSummary['total_active']); ?></div>
                        </div>
                        <div class="stat-mini-card">
                            <div class="stat-mini-label">Inactive</div>
                            <div class="stat-mini-value" style="color: var(--map-red);"><?php echo number_format((int)$uSummary['total_inactive']); ?></div>
                        </div>
                    </div>

                    <!-- Evacuation Centers -->
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-map-pin"></i> Evacuation Centers</h3>
                            <span class="badge"><?php echo count($centers); ?> Active</span>
                        </div>

                        <div class="centers-list">
                            <?php 
                            $displayCenters = array_slice($centers, 0, 4);
                            foreach ($displayCenters as $center): 
                                $dotClass = 'dot-gray';
                                $fillClass = '';
                                $capacityPercent = ($center['max_capacity_people'] > 0) 
                                    ? ($center['current_occupancy'] / $center['max_capacity_people']) * 100 
                                    : 0;

                                if ($center['status'] === 'available') {
                                    $dotClass = 'dot-green';
                                    $fillClass = 'green';
                                } else if ($center['status'] === 'near_capacity') {
                                    $dotClass = 'dot-yellow';
                                    $fillClass = 'yellow';
                                } else if ($center['status'] === 'full') {
                                    $dotClass = 'dot-red';
                                } else if ($center['status'] === 'temp_shelter') {
                                    $dotClass = 'dot-blue';
                                }
                            ?>
                            <div class="center-item">
                                <div class="center-info">
                                    <h4><?php echo htmlspecialchars($center['name']); ?></h4>
                                    <p><?php echo htmlspecialchars($center['barangay_name']); ?></p>
                                </div>
                                <div class="capacity-indicator">
                                    <div class="capacity-bar">
                                        <div class="capacity-fill <?php echo $fillClass; ?>" 
                                             style="width: <?php echo min($capacityPercent, 100); ?>%;">
                                        </div>
                                    </div>
                                    <span class="capacity-dot <?php echo $dotClass; ?>"></span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <div style="margin-top: 15px; text-align: center;">
                            <a href="centers.php" style="color: var(--primary-red); text-decoration: none; font-size: 13px; font-weight: 500;">
                                View All Centers <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- App Arrivals — Expected Evacuees (with pagination for citizen lists) -->
                <div class="card app-arrivals-card">
                    <div class="card-header app-arrivals-header">
                        <h3><i class="fas fa-location-arrow"></i> App Arrivals — Expected Evacuees</h3>
                        <div class="app-arrivals-meta">
                            <span class="badge badge-enroute" id="arrivals-badge"><?php echo number_format($totalExpectedEnRoute); ?> en route</span>
                            <button type="button" class="btn-refresh-expected" id="refreshExpectedBtn" title="Refresh counts">
                                <i class="fas fa-sync-alt" id="spinIcon"></i>
                            </button>
                            <span class="last-expected-updated" id="last-expected-updated">Auto-refreshes every 30s</span>
                        </div>
                    </div>

                    <?php if (empty($expectedCenters)): ?>
                        <div class="app-arrivals-empty">No active evacuation centers.</div>
                    <?php elseif ($totalExpectedEnRoute === 0): ?>
                        <div class="app-arrivals-empty">
                            <i class="fas fa-check-circle"></i>
                            No citizens are currently navigating to an evacuation center via the app.
                        </div>
                        <p class="app-arrivals-note">Counts update when citizens start navigation from the citizen app.</p>
                    <?php else: ?>
                        <ul class="admin-arrivals-list" id="adminArrivalsList">
                            <?php foreach ($expectedCenters as $ec):
                                $centerId    = (int)$ec['id'];
                                $expected    = (int)$ec['expected_count'];
                                if ($expected <= 0) continue;
                                $maxCap      = (int)$ec['max_capacity_people'];
                                $capPct      = $maxCap > 0 ? min(100, round($expected / $maxCap * 100)) : 0;
                                $capClass    = $capPct >= 85 ? 'danger' : ($capPct >= 60 ? 'warning' : 'safe');
                                $bdown       = $expectedBreakdown[$centerId] ?? [];
                                $maxBdown    = !empty($bdown) ? max(array_column($bdown, 'citizen_count')) : 1;
                                $arrivals    = $arrivalsByCenter[$centerId] ?? [];
                                $statusClass = 'es-' . str_replace('_', '-', $ec['status']);
                                $totalArrivals = count($arrivals);
                            ?>
                            <li class="admin-arrival-center" data-center-id="<?php echo $centerId; ?>">
                                <div class="admin-arrival-center-header">
                                    <div>
                                        <div class="admin-arrival-name"><?php echo htmlspecialchars($ec['name']); ?></div>
                                        <div class="admin-arrival-sub">
                                            <?php echo htmlspecialchars($ec['barangay_name'] ?? '—'); ?>
                                            <?php if ($ec['coordinator_name']): ?>
                                                &bull; Coord: <?php echo htmlspecialchars($ec['coordinator_name']); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <span class="es-status-badge <?php echo $statusClass; ?>"><?php echo ucfirst(str_replace('_', ' ', $ec['status'])); ?></span>
                                    <span class="expected-pill has-evacuees" id="pill-<?php echo $centerId; ?>">
                                        <i class="fas fa-walking"></i>
                                        <span class="pill-val"><?php echo $expected; ?></span> expected
                                    </span>
                                </div>

                                <?php if ($maxCap > 0): ?>
                                <div class="admin-arrival-capacity">
                                    <span class="capacity-label">Incoming vs capacity</span>
                                    <div class="cap-bar-track"><div class="cap-bar <?php echo $capClass; ?>" id="capbar-<?php echo $centerId; ?>" style="width:<?php echo $capPct; ?>%"></div></div>
                                    <span class="capacity-pct" id="cappct-<?php echo $centerId; ?>"><?php echo $expected; ?> / <?php echo $maxCap; ?> (<?php echo $capPct; ?>%)</span>
                                </div>
                                <?php endif; ?>

                                <?php if (!empty($bdown)): ?>
                                <div class="admin-arrival-breakdown" id="breakdown-<?php echo $centerId; ?>">
                                    <div class="breakdown-label">By barangay of origin</div>
                                    <table class="breakdown-table">
                                        <thead><tr><th>Barangay</th><th style="text-align: right; margin-left: 10px;">People</th><th></th></tr></thead>
                                        <tbody>
                                            <?php foreach ($bdown as $brow):
                                                $bpct = $maxBdown > 0 ? round((int)$brow['citizen_count'] / $maxBdown * 100) : 0;
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($brow['barangay_name']); ?></td>
                                                <td class="count-cell"><?php echo (int)$brow['citizen_count']; ?></td>
                                                <td><div class="bar-wrap"><div class="bar-fill" style="width:<?php echo $bpct; ?>%"></div></div></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php endif; ?>

                                <?php if ($arrivals): ?>
                                <div class="admin-arrival-people">
                                    <div class="breakdown-label">Citizens navigating here</div>
                                    <div class="arrival-people-wrapper" id="arrival-wrapper-<?php echo $centerId; ?>">
                                        <ul class="arrival-people-list" id="arrival-list-<?php echo $centerId; ?>">
                                            <?php foreach ($arrivals as $a): ?>
                                            <li>
                                                <span class="arrival-person-name"><?php echo htmlspecialchars($a['full_name']); ?></span>
                                                <span class="arrival-person-meta">
                                                    <?php echo htmlspecialchars($a['origin_barangay']); ?>
                                                    &bull; <?php echo (int)$a['total_members']; ?> person<?php echo (int)$a['total_members'] !== 1 ? 's' : ''; ?>
                                                </span>
                                            </li>
                                            <?php endforeach; ?>
                                        </ul>
                                        <?php if ($totalArrivals > 10): ?>
                                        <div class="pagination-controls" id="pagination-controls-<?php echo $centerId; ?>">
                                            <button class="pagination-btn prev-btn" data-center="<?php echo $centerId; ?>">Previous</button>
                                            <span class="page-indicator" id="page-indicator-<?php echo $centerId; ?>">Page 1 of <span class="total-pages"></span></span>
                                            <button class="pagination-btn next-btn" data-center="<?php echo $centerId; ?>">Next</button>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <!-- Evacuation Centers Summary (unchanged) -->
                <?php if (!empty($evacSummary)): ?>
                <div class="evac-summary-card card">
                    <div class="card-header">
                        <h3><i class="fas fa-people-arrows"></i> Evacuation Centers Summary</h3>
                    </div>

                    <div style="overflow-x: auto;">
                        <table class="evac-table">
                            <thead>
                                <tr>
                                    <th>Center</th>
                                    <th>Coordinator</th>
                                    <th>Adults</th>
                                    <th>Children</th>
                                    <th>Seniors</th>
                                    <th>PWD</th>
                                    <th>Pregnant</th>
                                    <th>Lactating</th>
                                    <th>Infants</th>
                                    <th>Families</th>
                                    <th>Total</th>
                                    <th>En Route</th>
                                    <th>Capacity</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($evacSummary as $row):
                                    $enRoute = $expectedByCenterId[(int)$row['id']] ?? 0;
                                    $pct = $row['max_capacity_people'] > 0
                                        ? min(round(($row['total_evacuees'] / $row['max_capacity_people']) * 100), 100)
                                        : 0;

                                    $barColor = '#2E7D32';
                                    $statusLabel = 'Available';
                                    $statusClass = 'es-available';
                                    if ($row['status'] === 'near_capacity') {
                                        $barColor = '#FFC107'; $statusLabel = 'Near Cap'; $statusClass = 'es-near';
                                    } elseif ($row['status'] === 'full') {
                                        $barColor = '#D32F2F'; $statusLabel = 'Full'; $statusClass = 'es-full';
                                    } elseif ($row['status'] === 'temp_shelter') {
                                        $barColor = '#3498DB'; $statusLabel = 'Temp'; $statusClass = 'es-temp';
                                    } elseif ($row['status'] === 'closed') {
                                        $barColor = '#95A5A6'; $statusLabel = 'Closed'; $statusClass = 'es-closed';
                                    }
                                ?>
                                <tr>
                                    <td>
                                        <div class="es-center-name"><?php echo htmlspecialchars($row['center_name']); ?></div>
                                        <div class="es-center-brgy">
                                            <?php echo htmlspecialchars($row['barangay_name']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($row['coordinator_name']): ?>
                                            <div class="es-coord-name"><?php echo htmlspecialchars($row['coordinator_name']); ?></div>
                                            <div class="es-coord-contact">
                                                <?php echo htmlspecialchars($row['coordinator_contact'] ?? '—'); ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="es-no-coord">Unassigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="es-demo es-adults"><?php echo number_format($row['total_adults']); ?></span></td>
                                    <td><span class="es-demo es-children"><?php echo number_format($row['total_children']); ?></span></td>
                                    <td><span class="es-demo es-seniors"><?php echo number_format($row['total_seniors']); ?></span></td>
                                    <td><span class="es-demo es-pwd"><?php echo number_format($row['total_pwds']); ?></span></td>
                                    <td><span class="es-demo"><?php echo number_format($row['total_pregnant_women']); ?></span></td>
                                    <td><span class="es-demo"><?php echo number_format($row['total_lactating_mothers']); ?></span></td>
                                    <td><span class="es-demo"><?php echo number_format($row['total_infants_toddlers']); ?></span></td>
                                    <td><span class="es-families"><?php echo number_format($row['total_families']); ?></span></td>
                                    <td><span class="es-total"><?php echo number_format($row['total_evacuees']); ?></span></td>
                                    <td>
                                        <span class="es-enroute <?php echo $enRoute > 0 ? 'has-enroute' : ''; ?>" id="enroute-<?php echo (int)$row['id']; ?>">
                                            <?php echo number_format($enRoute); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="es-cap-wrap">
                                            <div class="es-cap-bar">
                                                <div class="es-cap-fill" style="width:<?php echo $pct; ?>%; background:<?php echo $barColor; ?>;"></div>
                                            </div>
                                            <div class="es-cap-text">
                                                <?php echo number_format($row['total_evacuees']); ?> / <?php echo number_format($row['max_capacity_people']); ?>
                                                (<?php echo $pct; ?>%)
                                            </div>
                                        </div>
                                    </td>
                                    <td><span class="es-status-badge <?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>

                            <?php
                                $grandAdults    = array_sum(array_column($evacSummary, 'total_adults'));
                                $grandChildren  = array_sum(array_column($evacSummary, 'total_children'));
                                $grandSeniors   = array_sum(array_column($evacSummary, 'total_seniors'));
                                $grandPwds      = array_sum(array_column($evacSummary, 'total_pwds'));
                                $grandPregnant  = array_sum(array_column($evacSummary, 'total_pregnant_women'));
                                $grandLactating = array_sum(array_column($evacSummary, 'total_lactating_mothers'));
                                $grandInfants   = array_sum(array_column($evacSummary, 'total_infants_toddlers'));
                                $grandFamilies  = array_sum(array_column($evacSummary, 'total_families'));
                                $grandTotal     = array_sum(array_column($evacSummary, 'total_evacuees'));
                                $grandCap       = array_sum(array_column($evacSummary, 'max_capacity_people'));
                                $grandEnRoute   = $totalExpectedEnRoute;
                            ?>
                            <tfoot>
                                <tr>
                                    <td colspan="2"><strong>TOTAL</strong></td>
                                    <td><strong><?php echo number_format($grandAdults); ?></strong></td>
                                    <td><strong><?php echo number_format($grandChildren); ?></strong></td>
                                    <td><strong><?php echo number_format($grandSeniors); ?></strong></td>
                                    <td><strong><?php echo number_format($grandPwds); ?></strong></td>
                                    <td><strong><?php echo number_format($grandPregnant); ?></strong></td>
                                    <td><strong><?php echo number_format($grandLactating); ?></strong></td>
                                    <td><strong><?php echo number_format($grandInfants); ?></strong></td>
                                    <td><strong><?php echo number_format($grandFamilies); ?></strong></td>
                                    <td><strong><?php echo number_format($grandTotal); ?></strong></td>
                                    <td><strong id="enroute-total"><?php echo number_format($grandEnRoute); ?></strong></td>
                                    <td colspan="2">
                                        <strong><?php echo number_format($grandTotal); ?> / <?php echo number_format($grandCap); ?></strong>
                                        (<?php echo $grandCap > 0 ? round(($grandTotal/$grandCap)*100) : 0; ?>% overall)
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Map Card -->
                <div class="map-container">
                    <div class="map-view-toggle" role="tablist" aria-label="Map view">
                        <button type="button" class="map-view-btn active" data-map-view="evacuation" role="tab" aria-selected="true" aria-controls="adminMap">
                            <i class="fas fa-map-marked-alt" aria-hidden="true"></i>
                            <span>Evacuation Centers</span>
                        </button>
                        <button type="button" class="map-view-btn" data-map-view="weather" role="tab" aria-selected="false" aria-controls="windyMap">
                            <i class="fas fa-wind" aria-hidden="true"></i>
                            <span>Weather</span>
                        </button>
                    </div>
                    <div class="map-view-body">
                        <div id="adminMap"></div>
                        <div id="windyMap" hidden>
                            <iframe
                                id="windyIframe"
                                title="Windy Weather Map — San Ildefonso, Bulacan"
                                width="1000"
                                height="350"
                                loading="lazy"
                                data-src="https://embed.windy.com/embed2.html?lat=15.0828&amp;lon=120.9417&amp;detailLat=15.0828&amp;detailLon=120.9417&amp;zoom=11&amp;level=surface&amp;overlay=wind&amp;product=ecmwf&amp;menu=&amp;message=true&amp;marker=true&amp;calendar=now&amp;pressure=true&amp;type=map&amp;location=coordinates&amp;detail=true&amp;metricWind=default&amp;metricTemp=%C2%B0C&amp;radarRange=-1"
                                src=""
                                referrerpolicy="no-referrer-when-downgrade"
                                allowfullscreen></iframe>
                        </div>
                        <div class="map-legend">
                            <div class="legend-item">
                                <span class="legend-color green"></span>
                                <span>A</span>
                            </div>
                            <div class="legend-item">
                                <span class="legend-color yellow"></span>
                                <span>N</span>
                            </div>
                            <div class="legend-item">
                                <span class="legend-color red"></span>
                                <span>F</span>
                            </div>
                            <div class="legend-item">
                                <span class="legend-color blue"></span>
                                <span>T</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Scripts -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.js"></script>
    <script src="https://unpkg.com/@maplibre/maplibre-gl-leaflet@0.0.20/leaflet-maplibre-gl.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
        const DASHBOARD_CHARTS = <?php echo json_encode([
            'centerStatus'   => $chartCenterStatus,
            'demographics'   => $chartDemographics,
            'users'          => $chartUsers,
            'capacityCenters'=> $chartCapacityCenters,
            'trend'          => $chartTrend,
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        // Sidebar Toggle with external button
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const toggleBtn = document.getElementById('sidebarToggleBtn');
        const mobileToggle = document.getElementById('mobileToggle');
        let adminMap = null;

        toggleBtn.addEventListener('click', () => {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
            toggleBtn.classList.toggle('collapsed');
            const icon = toggleBtn.querySelector('i');
            icon.className = sidebar.classList.contains('collapsed') ? 'fas fa-chevron-right' : 'fas fa-chevron-left';
            if (adminMap && document.getElementById('adminMap') && !document.getElementById('windyMap')?.hidden) {
                setTimeout(() => adminMap.invalidateSize(true), 320);
            }
        });

        mobileToggle.addEventListener('click', () => {
            sidebar.classList.toggle('show');
        });

        // Map with Custom Location Pin + Shelter Icon Markers
        const centers = <?php echo json_encode(array_map(function ($c) {
            return [
                'id' => (int)$c['id'],
                'name' => $c['name'],
                'lat' => (float)$c['lat'],
                'lng' => (float)$c['lng'],
                'barangay' => $c['barangay_name'],
                'status' => $c['status'],
                'max_capacity_people' => (int)$c['max_capacity_people'],
                'current_occupancy' => (int)$c['current_occupancy'],
            ];
        }, $centers)); ?>;

        function initAdminEvacuationMap() {
            if (centers.length === 0) {
                document.getElementById('adminMap').innerHTML = '<div style="display: flex; align-items: center; justify-content: center; height: 100%; color: #95A5A6;">No evacuation centers defined yet.</div>';
                return;
            }

            adminMap = L.map('adminMap', { zoomControl: true, maxZoom: 20 });
            adminMap.setView([centers[0].lat, centers[0].lng], 13);

            L.maplibreGL({
                style: 'https://tiles.openfreemap.org/styles/liberty',
                attribution: '&copy; <a href="https://openfreemap.org">OpenFreeMap</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
            }).addTo(adminMap);

            const markerGroup = L.featureGroup();

            centers.forEach((c) => {
                let pinColor = '#2E7D32';
                if (c.status === 'near_capacity') pinColor = '#FFC107';
                else if (c.status === 'full') pinColor = '#D32F2F';
                else if (c.status === 'temp_shelter') pinColor = '#3498DB';

                const customIcon = L.divIcon({
                    className: 'custom-marker',
                    html: `
                        <div class="marker-pin ${c.status}">
                            <i class="fas fa-home marker-icon"></i>
                        </div>
                    `,
                    iconSize: [30, 42],
                    iconAnchor: [15, 42],
                    popupAnchor: [0, -42]
                });

                const marker = L.marker([c.lat, c.lng], { icon: customIcon }).addTo(adminMap);
                markerGroup.addLayer(marker);

                const capacityPercent = Math.min((c.current_occupancy / c.max_capacity_people) * 100, 100);
                const statusClass = c.status.replace('_', '-');

                const popupContent = `
                    <div class="mini-modal">
                        <div class="mini-header">
                            <h3 class="mini-title">${c.name}</h3>
                            <span class="mini-status ${statusClass}">${c.status === 'available' ? 'A' : c.status === 'near_capacity' ? 'N' : c.status === 'full' ? 'F' : 'T'}</span>
                        </div>
                        <div class="mini-location">${c.barangay}</div>
                        <div class="mini-stats">
                            <div class="mini-stat">
                                <div class="mini-stat-value">${c.max_capacity_people}</div>
                                <div class="mini-stat-label">CAP</div>
                            </div>
                            <div class="mini-stat">
                                <div class="mini-stat-value">${c.current_occupancy}</div>
                                <div class="mini-stat-label">EVA</div>
                            </div>
                            <div class="mini-stat">
                                <div class="mini-stat-value">${c.max_capacity_people - c.current_occupancy}</div>
                                <div class="mini-stat-label">AVL</div>
                            </div>
                        </div>
                        <div class="mini-capacity">
                            <div class="mini-capacity-header">
                                <span>Fill</span>
                                <span>${Math.round(capacityPercent)}%</span>
                            </div>
                            <div class="mini-capacity-bar">
                                <div class="mini-capacity-fill" style="width: ${capacityPercent}%; background: ${pinColor};"></div>
                            </div>
                        </div>
                        <div class="mini-footer">
                            <a href="centers.php?id=${c.id}" class="mini-btn mini-btn-primary">View</a>
                            <button class="mini-btn mini-btn-secondary" onclick="alert('Directions coming soon!')">Directions</button>
                        </div>
                    </div>
                `;

                marker.bindPopup(popupContent, {
                    className: 'custom-popup',
                    minWidth: 200,
                    maxWidth: 200
                });
            });

            if (markerGroup.getLayers().length === 1) {
                adminMap.setView([centers[0].lat, centers[0].lng], 14);
            } else {
                adminMap.fitBounds(markerGroup.getBounds().pad(0.12), { maxZoom: 15 });
            }

            adminMap.whenReady(() => {
                setTimeout(() => adminMap.invalidateSize(true), 100);
            });
        }

        requestAnimationFrame(() => {
            initAdminEvacuationMap();
            window.addEventListener('load', () => {
                if (adminMap) adminMap.invalidateSize(true);
            });
        });

        (function initMapViewToggle() {
            const mapViewButtons = document.querySelectorAll('.map-view-btn');
            const windyMapEl = document.getElementById('windyMap');
            const windyIframe = document.getElementById('windyIframe');
            const legend = document.querySelector('.map-legend');
            if (!windyMapEl) return;

            function refreshEvacuationMap() {
                if (!adminMap) return;
                requestAnimationFrame(() => {
                    adminMap.invalidateSize(true);
                    setTimeout(() => adminMap.invalidateSize(true), 250);
                });
            }

            function setToggleActive(view) {
                mapViewButtons.forEach(btn => {
                    const active = btn.dataset.mapView === view;
                    btn.classList.toggle('active', active);
                    btn.setAttribute('aria-selected', active ? 'true' : 'false');
                });
            }

            function showEvacuation() {
                windyMapEl.hidden = true;
                if (legend) legend.style.display = '';
                setToggleActive('evacuation');
                refreshEvacuationMap();
            }

            function showWeather() {
                windyMapEl.hidden = false;
                if (legend) legend.style.display = 'none';
                setToggleActive('weather');
                if (windyIframe && windyIframe.dataset.src && !windyIframe.dataset.loaded) {
                    windyIframe.src = windyIframe.dataset.src;
                    windyIframe.dataset.loaded = '1';
                }
            }

            mapViewButtons.forEach(button => {
                button.addEventListener('click', () => {
                    const selectedView = button.dataset.mapView;
                    if (selectedView === 'evacuation') {
                        showEvacuation();
                    } else if (selectedView === 'weather') {
                        showWeather();
                    }
                });
            });
        })();

        // Auto-refresh expected evacuee counts
        const AUTO_REFRESH_INTERVAL = 30000;
        let expectedRefreshTimer = null;

        function refreshExpectedCounts() {
            const btn = document.getElementById('refreshExpectedBtn');
            if (!btn) return;
            btn.disabled = true;
            btn.classList.add('spinning');

            fetch('expected_counts.php', { cache: 'no-store', credentials: 'same-origin' })
                .then(r => r.json())
                .then(data => {
                    if (!data.ok) return;

                    data.centers.forEach(c => {
                        const pill = document.getElementById('pill-' + c.id);
                        if (pill) {
                            const val = pill.querySelector('.pill-val');
                            if (val) val.textContent = c.expected_count;
                            pill.className = 'expected-pill ' + (c.expected_count > 0 ? 'has-evacuees' : 'no-evacuees');
                        }

                        const capBar = document.getElementById('capbar-' + c.id);
                        const capPct = document.getElementById('cappct-' + c.id);
                        if (capBar && c.max_capacity_people > 0) {
                            const pct = Math.min(100, Math.round(c.expected_count / c.max_capacity_people * 100));
                            capBar.style.width = pct + '%';
                            capBar.className = 'cap-bar ' + (pct >= 85 ? 'danger' : (pct >= 60 ? 'warning' : 'safe'));
                            if (capPct) capPct.textContent = c.expected_count + ' / ' + c.max_capacity_people + ' (' + pct + '%)';
                        }

                        const enRouteCell = document.getElementById('enroute-' + c.id);
                        if (enRouteCell) {
                            enRouteCell.textContent = c.expected_count.toLocaleString();
                            enRouteCell.classList.toggle('has-enroute', c.expected_count > 0);
                        }
                    });

                    const total = data.total_expected ?? data.centers.reduce((s, c) => s + c.expected_count, 0);
                    const totalEl = document.getElementById('total-expected');
                    const badgeEl = document.getElementById('arrivals-badge');
                    const footEl  = document.getElementById('enroute-total');
                    if (totalEl) totalEl.textContent = total.toLocaleString();
                    if (badgeEl) badgeEl.textContent = total.toLocaleString() + ' en route';
                    if (footEl)  footEl.textContent  = total.toLocaleString();

                    const ts = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
                    const updatedEl = document.getElementById('last-expected-updated');
                    if (updatedEl) updatedEl.textContent = 'Last updated: ' + ts;

                    if (capacityChart && window.chartCapacityMeta) {
                        const enRouteMap = {};
                        data.centers.forEach(c => { enRouteMap[c.id] = c.expected_count; });
                        window.chartCapacityMeta.forEach((id, idx) => {
                            capacityChart.data.datasets[1].data[idx] = enRouteMap[id] || 0;
                        });
                        capacityChart.update('none');
                    }
                })
                .catch(() => {
                    const updatedEl = document.getElementById('last-expected-updated');
                    if (updatedEl) updatedEl.textContent = 'Refresh failed — retrying…';
                })
                .finally(() => {
                    btn.disabled = false;
                    btn.classList.remove('spinning');
                });
        }

        const refreshExpectedBtn = document.getElementById('refreshExpectedBtn');
        if (refreshExpectedBtn) {
            refreshExpectedBtn.addEventListener('click', refreshExpectedCounts);
            expectedRefreshTimer = setInterval(refreshExpectedCounts, AUTO_REFRESH_INTERVAL);
        }

        // ── Dashboard Charts (Chart.js) ─────────────────────────────────────
        let capacityChart = null;

        (function initDashboardCharts() {
            if (typeof Chart === 'undefined') return;

            Chart.defaults.font.family = "'Segoe UI', system-ui, -apple-system, sans-serif";
            Chart.defaults.color = '#5D6D7E';
            Chart.defaults.plugins.legend.labels.usePointStyle = true;
            Chart.defaults.plugins.legend.labels.boxWidth = 8;

            const tooltipDefaults = {
                backgroundColor: 'rgba(44, 62, 80, 0.92)',
                titleFont: { size: 13, weight: '600' },
                bodyFont: { size: 12 },
                padding: 12,
                cornerRadius: 10,
                displayColors: true,
            };

            function filterNonZero(labels, values) {
                const outL = [], outV = [];
                labels.forEach((l, i) => {
                    if (values[i] > 0) { outL.push(l); outV.push(values[i]); }
                });
                return { labels: outL.length ? outL : labels, values: outV.length ? outV : values };
            }

            const data = DASHBOARD_CHARTS;

            // 1. Center Status — doughnut
            const statusLabels = Object.keys(data.centerStatus);
            const statusValues = Object.values(data.centerStatus);
            const statusFiltered = filterNonZero(statusLabels, statusValues);
            new Chart(document.getElementById('chartCenterStatus'), {
                type: 'doughnut',
                data: {
                    labels: statusFiltered.labels,
                    datasets: [{
                        data: statusFiltered.values,
                        backgroundColor: ['#2E7D32', '#FFC107', '#D32F2F', '#3498DB', '#95A5A6'],
                        borderWidth: 3,
                        borderColor: '#fff',
                        hoverOffset: 8,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '68%',
                    plugins: {
                        legend: { position: 'bottom', labels: { padding: 14 } },
                        tooltip: tooltipDefaults,
                    },
                },
            });

            // 2. Platform Users — doughnut
            const userLabels = Object.keys(data.users);
            const userValues = Object.values(data.users);
            new Chart(document.getElementById('chartUsers'), {
                type: 'doughnut',
                data: {
                    labels: userLabels,
                    datasets: [{
                        data: userValues,
                        backgroundColor: ['#3498DB', '#FFC107', '#2E7D32'],
                        borderWidth: 3,
                        borderColor: '#fff',
                        hoverOffset: 8,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '68%',
                    plugins: {
                        legend: { position: 'bottom', labels: { padding: 14 } },
                        tooltip: tooltipDefaults,
                    },
                },
            });

            // 3. Evacuee Demographics — horizontal bar
            const demoLabels = Object.keys(data.demographics);
            const demoValues = Object.values(data.demographics);
            new Chart(document.getElementById('chartDemographics'), {
                type: 'bar',
                data: {
                    labels: demoLabels,
                    datasets: [{
                        label: 'People',
                        data: demoValues,
                        backgroundColor: [
                            '#D32F2F', '#FF7043', '#FFC107', '#7E57C2',
                            '#EC407A', '#26A69A', '#42A5F5',
                        ],
                        borderRadius: 8,
                        borderSkipped: false,
                    }],
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: tooltipDefaults,
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            grid: { color: 'rgba(0,0,0,0.05)' },
                            ticks: { precision: 0 },
                        },
                        y: { grid: { display: false } },
                    },
                },
            });

            // 4. Center Occupancy — stacked horizontal bar
            const capLabels = data.capacityCenters.map(c => c.label);
            const capRegistered = data.capacityCenters.map(c => c.registered);
            const capEnRoute = data.capacityCenters.map(c => c.enRoute);
            window.chartCapacityMeta = data.capacityCenters.map(c => c.id);

            capacityChart = new Chart(document.getElementById('chartCapacity'), {
                type: 'bar',
                data: {
                    labels: capLabels,
                    datasets: [
                        {
                            label: 'Registered',
                            data: capRegistered,
                            backgroundColor: 'rgba(46, 125, 50, 0.85)',
                            borderRadius: 6,
                            borderSkipped: false,
                        },
                        {
                            label: 'En Route',
                            data: capEnRoute,
                            backgroundColor: 'rgba(230, 81, 0, 0.85)',
                            borderRadius: 6,
                            borderSkipped: false,
                        },
                    ],
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom', labels: { padding: 14 } },
                        tooltip: {
                            ...tooltipDefaults,
                            callbacks: {
                                footer(items) {
                                    const idx = items[0]?.dataIndex;
                                    if (idx == null) return '';
                                    const max = data.capacityCenters[idx]?.max || 0;
                                    const reg = capRegistered[idx] || 0;
                                    const en  = capEnRoute[idx] || 0;
                                    return 'Total incoming: ' + (reg + en) + ' / ' + max + ' capacity';
                                },
                            },
                        },
                    },
                    scales: {
                        x: {
                            stacked: true,
                            beginAtZero: true,
                            grid: { color: 'rgba(0,0,0,0.05)' },
                            ticks: { precision: 0 },
                        },
                        y: { stacked: true, grid: { display: false } },
                    },
                },
            });

            // 5. Evacuee Registrations — line with area fill
            new Chart(document.getElementById('chartTrend'), {
                type: 'line',
                data: {
                    labels: data.trend.labels,
                    datasets: [{
                        label: 'People registered',
                        data: data.trend.values,
                        borderColor: '#D32F2F',
                        backgroundColor: 'rgba(211, 47, 47, 0.12)',
                        borderWidth: 2.5,
                        pointRadius: 5,
                        pointHoverRadius: 7,
                        pointBackgroundColor: '#fff',
                        pointBorderColor: '#D32F2F',
                        pointBorderWidth: 2,
                        fill: true,
                        tension: 0.35,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: tooltipDefaults,
                    },
                    scales: {
                        x: { grid: { display: false } },
                        y: {
                            beginAtZero: true,
                            grid: { color: 'rgba(0,0,0,0.05)' },
                            ticks: { precision: 0 },
                        },
                    },
                },
            });
        })();

        // ── Pagination for citizen lists ─────────────────────────────────────
        document.addEventListener('DOMContentLoaded', function() {
            const PAGE_SIZE = 10;

            // Find all arrival lists
            document.querySelectorAll('.arrival-people-wrapper').forEach(function(wrapper) {
                const list = wrapper.querySelector('.arrival-people-list');
                if (!list) return;
                const items = list.querySelectorAll('li');
                const total = items.length;
                if (total <= PAGE_SIZE) return; // no pagination needed

                const controls = wrapper.querySelector('.pagination-controls');
                if (!controls) return;
                const prevBtn = controls.querySelector('.prev-btn');
                const nextBtn = controls.querySelector('.next-btn');
                const indicator = controls.querySelector('.page-indicator');
                const totalSpan = indicator.querySelector('.total-pages');

                let currentPage = 1;
                const totalPages = Math.ceil(total / PAGE_SIZE);
                totalSpan.textContent = totalPages;

                function showPage(page) {
                    currentPage = page;
                    const start = (page - 1) * PAGE_SIZE;
                    const end = start + PAGE_SIZE;
                    items.forEach((li, index) => {
                        li.style.display = (index >= start && index < end) ? 'flex' : 'none';
                    });
                    indicator.textContent = 'Page ' + page + ' of ' + totalPages;
                    prevBtn.disabled = (page === 1);
                    nextBtn.disabled = (page === totalPages);
                }

                prevBtn.addEventListener('click', function() {
                    if (currentPage > 1) showPage(currentPage - 1);
                });
                nextBtn.addEventListener('click', function() {
                    if (currentPage < totalPages) showPage(currentPage + 1);
                });

                showPage(1); // initial display
            });
        });
    </script>
</body>
</html>