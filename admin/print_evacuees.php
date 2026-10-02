<?php
/**
 * print_evacuees.php — Printable current evacuee report (live registrations + member names).
 */

require_once __DIR__ . '/../pages/session.php';
require_once __DIR__ . '/../pages/demographic_helpers.php';
require_once __DIR__ . '/../pages/admin_report_helpers.php';
require_login('admin');

$pdo = db();

$hasSourceUser = ar_column_exists($pdo, 'evac_registrations', 'source_user_id');
$headSexSql = $hasSourceUser ? 'head_u.sex AS head_sex' : 'NULL AS head_sex';
$sourceUserSql = $hasSourceUser ? 'er.source_user_id' : 'NULL AS source_user_id';
$regSourceSql = ar_sql_registration_source($pdo, 'er');
$headJoinSql = $hasSourceUser ? 'LEFT JOIN users head_u ON head_u.id = er.source_user_id' : '';
$records = $pdo->query("
    SELECT
        er.id AS registration_id,
        er.family_head_name,
        er.contact_number,
        er.birthday,
        er.adults, er.children, er.seniors, er.pwds,
        er.pregnant_women, er.lactating_mothers, er.infants_toddlers,
        er.total_members,
        er.created_at,
        ec.name     AS center_name,
        ec.address  AS center_address,
        b.name      AS barangay_name,
        u.full_name AS registered_by,
        {$headSexSql},
        {$sourceUserSql},
        {$regSourceSql}
    FROM evac_registrations er
    LEFT JOIN evacuation_centers ec ON ec.id = er.center_id
    LEFT JOIN barangays b           ON b.id  = er.barangay_id
    LEFT JOIN users u               ON u.id  = er.created_by
    {$headJoinSql}
    ORDER BY ec.name ASC, er.family_head_name ASC
")->fetchAll();

$regIds = array_column($records, 'registration_id');
$membersByRegId = ar_bulk_live_members($pdo, $regIds);
$profileByRegId = ar_bulk_profile_members(
    $pdo,
    $records,
    fn($r) => (int)($r['registration_id'] ?? 0)
);

$byCentre = [];
foreach ($records as $rec) {
    $byCentre[$rec['center_name'] ?? 'Unknown Center'][] = $rec;
}

$byBrgy = $pdo->query("
    SELECT
        b.name AS barangay_name,
        SUM(er.adults) AS adults, SUM(er.children) AS children, SUM(er.seniors) AS seniors,
        SUM(er.pwds) AS pwds, SUM(er.pregnant_women) AS pregnant_women,
        SUM(er.lactating_mothers) AS lactating_mothers, SUM(er.infants_toddlers) AS infants_toddlers,
        SUM(er.total_members) AS total_members, COUNT(*) AS families
    FROM evac_registrations er
    JOIN barangays b ON b.id = er.barangay_id
    GROUP BY b.id
    ORDER BY total_members DESC
")->fetchAll();

$grandAdults    = array_sum(array_column($records, 'adults'));
$grandChildren  = array_sum(array_column($records, 'children'));
$grandSeniors   = array_sum(array_column($records, 'seniors'));
$grandPwds      = array_sum(array_column($records, 'pwds'));
$grandPregnant  = array_sum(array_column($records, 'pregnant_women'));
$grandLactating = array_sum(array_column($records, 'lactating_mothers'));
$grandInfants   = array_sum(array_column($records, 'infants_toddlers'));
$grandTotal     = array_sum(array_column($records, 'total_members'));
$grandFamilies  = count($records);

$printedAt = date('F j, Y \a\t g:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Print — Current Evacuee Report | MDRRMO</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root { --red:#C0392B; --red-dk:#922B21; --blue:#1A5276; --gray:#566573; --light:#F8F9FA; --border:#D5D8DC; --text:#1C2833; --muted:#7F8C8D; }
body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 12px; color: var(--text); background: #fff; }
@media screen { body { background: #EAECEE; padding: 20px; } .print-page { background: #fff; max-width: 900px; margin: 0 auto 40px; padding: 36px 40px; box-shadow: 0 2px 20px rgba(0,0,0,.12); border-radius: 4px; } .print-controls { max-width: 900px; margin: 0 auto 16px; display: flex; gap: 10px; } .btn { padding: 9px 20px; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; } .btn-print { background: var(--red); color: #fff; } .btn-back { background: #fff; color: var(--text); border: 1.5px solid var(--border); } }
@media print { body { padding: 0; font-size: 11px; } .print-controls { display: none !important; } .print-page { padding: 18mm 20mm; page-break-after: always; } .print-page:last-child { page-break-after: avoid; } .no-break { page-break-inside: avoid; } thead { display: table-header-group; } @page { margin: 10mm; size: A4 portrait; } }
.report-header { display: flex; align-items: flex-start; gap: 16px; padding-bottom: 16px; border-bottom: 3px solid var(--red); margin-bottom: 20px; }
.header-logo-box { width: 54px; height: 54px; border: 1px solid var(--border); border-radius: 8px; overflow: hidden; }
.header-logo-box img { width: 100%; height: 100%; object-fit: contain; }
.header-org h1 { font-size: 17px; font-weight: 700; color: var(--red); }
.header-org p { font-size: 11px; color: var(--gray); margin-top: 2px; }
.header-right { margin-left: auto; text-align: right; }
.header-right .report-title { font-size: 14px; font-weight: 700; color: var(--blue); }
.header-right .report-meta { font-size: 10.5px; color: var(--muted); margin-top: 3px; }
.section-title { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .7px; color: var(--blue); margin-bottom: 8px; padding-bottom: 4px; border-bottom: 1px solid var(--border); }
.summary-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 20px; }
.summary-card { background: var(--light); border: 1px solid var(--border); border-radius: 6px; padding: 10px 8px; text-align: center; }
.summary-card .val { font-size: 20px; font-weight: 700; color: var(--red); }
.summary-card .lbl { font-size: 10px; color: var(--muted); margin-top: 4px; text-transform: uppercase; }
.chip { display: inline-block; padding: 1px 7px; border-radius: 20px; font-size: 10.5px; font-weight: 600; }
.chip-c { background: #D6EAF8; color: #1A5276; } .chip-a { background: #D5F5E3; color: #1E8449; } .chip-s { background: #EDE7F6; color: #6A1B9A; } .chip-p { background: #FEF9E7; color: #B7950B; } .chip-t { background: #FDEDEC; color: #C0392B; }
table { width: 100%; border-collapse: collapse; font-size: 11.5px; }
table th { background: var(--blue); color: #fff; padding: 7px 9px; text-align: left; font-size: 10.5px; }
table th.center { text-align: center; }
table td { padding: 6px 9px; border-bottom: 1px solid var(--border); vertical-align: top; }
table tr:nth-child(even) td { background: #F8F9FA; }
table tfoot td { background: #EBF5FB; font-weight: 700; color: var(--blue); border-top: 2px solid var(--blue); }
td.num { text-align: right; }
.centre-heading { background: #EBF5FB; border-left: 3px solid var(--blue); padding: 7px 12px; font-size: 12px; font-weight: 700; color: var(--blue); margin-bottom: 8px; }
.centre-heading small { font-weight: 400; color: var(--gray); margin-left: 8px; }
.member-subrow td { background: #FDFEFE !important; border-bottom: 1px dashed var(--border); font-size: 10.5px; color: var(--gray); padding: 4px 9px 6px 24px; }
.member-bullet { color: var(--blue); font-weight: 700; margin-right: 4px; }
.member-count-pill { display: inline-block; margin-left: 6px; padding: 1px 7px; border-radius: 99px; background: #EBF5FB; color: var(--blue); font-size: 10px; font-weight: 600; }
.section-title { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .7px; color: var(--blue); margin: 16px 0 8px; padding-bottom: 4px; border-bottom: 1px solid var(--border); }
.individual-roster-section { margin-top: 20px; }
.individual-roster-table th { background: #154360; }
.evacuee-note-row td { background: #FFFBEB !important; color: #92400E; font-style: italic; }
.member-agg-tag { display: inline-block; margin-left: 4px; padding: 1px 6px; border-radius: 99px; background: #FEF9E7; color: #B7950B; font-size: 9px; font-weight: 700; text-transform: uppercase; }
.report-footer { margin-top: 28px; padding-top: 12px; border-top: 1.5px solid var(--border); display: flex; justify-content: space-between; font-size: 10.5px; color: var(--muted); }
</style>
</head>
<body>

<div class="print-controls">
    <a href="evacuees.php" class="btn btn-back">← Back to Evacuees</a>
    <button onclick="window.print()" class="btn btn-print">🖨 Print / Save as PDF</button>
</div>

<?php if (empty($records)): ?>
<div class="print-page"><p style="color:#7F8C8D;text-align:center;padding:40px 0">No active evacuee registrations to print.</p></div>
<?php else: ?>

<div class="print-page">
    <div class="report-header no-break">
        <div class="header-logo-box"><img src="../img/mdrrmo.png" alt="MDRRMO"></div>
        <div class="header-org">
            <h1>MDRRMO — Municipality of San Ildefonso</h1>
            <p>Municipal Disaster Risk Reduction and Management Office · Bulacan</p>
        </div>
        <div class="header-right">
            <div class="report-title">Current Evacuee Report</div>
            <div class="report-meta">Live snapshot · Printed <?php echo $printedAt; ?></div>
        </div>
    </div>

    <div class="section-title">Summary Totals</div>
    <div class="summary-grid no-break">
        <div class="summary-card"><div class="val"><?php echo number_format($grandTotal); ?></div><div class="lbl">Total Evacuees</div></div>
        <div class="summary-card"><div class="val"><?php echo number_format($grandFamilies); ?></div><div class="lbl">Families</div></div>
        <div class="summary-card"><div class="val"><?php echo number_format($grandAdults); ?></div><div class="lbl">Adults</div></div>
        <div class="summary-card"><div class="val"><?php echo number_format($grandChildren); ?></div><div class="lbl">Children</div></div>
        <div class="summary-card"><div class="val"><?php echo number_format($grandSeniors); ?></div><div class="lbl">Seniors</div></div>
        <div class="summary-card"><div class="val"><?php echo number_format($grandPwds); ?></div><div class="lbl">PWD</div></div>
        <div class="summary-card"><div class="val"><?php echo number_format($grandPregnant); ?></div><div class="lbl">Pregnant</div></div>
        <div class="summary-card"><div class="val"><?php echo number_format($grandLactating); ?></div><div class="lbl">Lactating</div></div>
        <div class="summary-card"><div class="val"><?php echo number_format($grandInfants); ?></div><div class="lbl">Infants</div></div>
    </div>

    <?php
    $walkinCount = count(array_filter($records, fn($r) => ($r['registration_source'] ?? 'walkin') === 'walkin'));
    $appCount = count($records) - $walkinCount;
    ?>
    <div class="section-title">Registration Source</div>
    <div class="summary-grid no-break" style="grid-template-columns:repeat(2,1fr);margin-bottom:16px">
        <div class="summary-card"><div class="val"><?php echo $walkinCount; ?></div><div class="lbl">Walk-in Families</div></div>
        <div class="summary-card"><div class="val"><?php echo $appCount; ?></div><div class="lbl">App / Citizen Families</div></div>
    </div>

    <div class="section-title">By Evacuation Center</div>
    <table class="no-break">
        <thead><tr><th>Center</th><th class="center">Families</th><th class="center">Total</th><th class="center">Adults</th><th class="center">Children</th><th class="center">Seniors</th><th class="center">PWD</th></tr></thead>
        <tbody>
        <?php
        $centreStats = [];
        foreach ($records as $rec) {
            $cn = $rec['center_name'] ?? 'Unknown';
            if (!isset($centreStats[$cn])) {
                $centreStats[$cn] = ['families' => 0, 'total' => 0, 'adults' => 0, 'children' => 0, 'seniors' => 0, 'pwds' => 0];
            }
            $centreStats[$cn]['families']++;
            $centreStats[$cn]['total'] += (int)$rec['total_members'];
            foreach (['adults','children','seniors','pwds'] as $k) {
                $centreStats[$cn][$k] += (int)$rec[$k];
            }
        }
        foreach ($centreStats as $cname => $cs): ?>
        <tr>
            <td><?php echo htmlspecialchars($cname); ?></td>
            <td class="num"><?php echo $cs['families']; ?></td>
            <td class="num"><span class="chip chip-t"><?php echo $cs['total']; ?></span></td>
            <td class="num"><?php echo $cs['adults']; ?></td>
            <td class="num"><?php echo $cs['children']; ?></td>
            <td class="num"><?php echo $cs['seniors']; ?></td>
            <td class="num"><?php echo $cs['pwds']; ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($byBrgy): ?>
    <div class="section-title" style="margin-top:20px">By Barangay of Origin</div>
    <table class="no-break">
        <thead><tr><th>Barangay</th><th class="center">Families</th><th class="center">Total</th></tr></thead>
        <tbody>
        <?php foreach ($byBrgy as $br): ?>
        <tr>
            <td><?php echo htmlspecialchars($br['barangay_name']); ?></td>
            <td class="num"><?php echo $br['families']; ?></td>
            <td class="num"><?php echo $br['total_members']; ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <div class="report-footer">
        <div><strong>MDRRMO San Ildefonso</strong> — Live evacuation records</div>
        <div style="text-align:right">Printed <?php echo $printedAt; ?></div>
    </div>
</div>

<?php foreach ($byCentre as $centreName => $centreRecs): ?>
<div class="print-page">
    <div class="centre-heading no-break">
        <?php echo htmlspecialchars($centreName); ?>
        <small><?php echo count($centreRecs); ?> families · <?php echo array_sum(array_column($centreRecs, 'total_members')); ?> evacuees</small>
    </div>
    <div class="section-title">Family Summary (counts per household)</div>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Source</th>
                <th>Family Head</th>
                <th>Contact</th>
                <th>Birthday</th>
                <th>Age</th>
                <th>Barangay</th>
                <?php foreach (DEMO_SHORT as $short): ?><th class="center"><?php echo $short; ?></th><?php endforeach; ?>
                <th class="center">Total</th>
                <th>Registered By</th>
                <th>Date</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($centreRecs as $ri => $rec):
            $regId = (int)$rec['registration_id'];
            $members = ar_members_for_registration($rec, $membersByRegId, $regId);
            $age = ar_calculate_age($rec['birthday'] ?? null);
        ?>
        <tr class="family-row">
            <td><?php echo $ri + 1; ?></td>
            <td style="font-size:10.5px"><?php echo ar_registration_source_label($rec['registration_source'] ?? 'walkin'); ?></td>
            <td style="font-weight:600">
                <?php echo htmlspecialchars($rec['family_head_name']); ?>
                <span class="member-count-pill"><?php echo count($members); ?> name<?php echo count($members) === 1 ? '' : 's'; ?></span>
            </td>
            <td><?php echo htmlspecialchars($rec['contact_number'] ?? '—'); ?></td>
            <td><?php echo $rec['birthday'] ? date('M d, Y', strtotime($rec['birthday'])) : '–'; ?></td>
            <td><?php echo $age !== null ? $age : '–'; ?></td>
            <td><?php echo htmlspecialchars($rec['barangay_name']); ?></td>
            <?php foreach (demo_field_keys() as $dk): ?>
            <td class="num"><?php echo (int)$rec[$dk]; ?></td>
            <?php endforeach; ?>
            <td class="num"><span class="chip chip-t"><?php echo (int)$rec['total_members']; ?></span></td>
            <td style="font-size:10.5px"><?php echo htmlspecialchars($rec['registered_by'] ?? '—'); ?></td>
            <td style="font-size:10.5px;white-space:nowrap"><?php echo date('M j, Y g:i A', strtotime($rec['created_at'])); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php
    $centreEvacuees = ar_collect_centre_evacuees(
        $centreRecs,
        $membersByRegId,
        fn($rec) => (int)($rec['registration_id'] ?? 0),
        $profileByRegId
    );
    ?>
    <div class="individual-roster-section no-break">
        <div class="section-title">Individual Evacuee Roster — <?php echo count($centreEvacuees); ?> record(s)</div>
        <?php echo ar_render_individual_roster_table($centreEvacuees); ?>
    </div>

    <div class="report-footer">
        <div>Each evacuee listed individually with sex, age, and category where recorded.</div>
        <div style="text-align:right"><?php echo htmlspecialchars($centreName); ?></div>
    </div>
</div>
<?php endforeach; ?>

<?php endif; ?>
</body>
</html>
