<?php
/**
 * archive_evacuees.php
 * POST-only action. Copies all current evac_registrations into
 * evac_registrations_archive, then deletes the live records.
 * Resets all evacuation center statuses to 'available'.
 */

ob_start();

require_once __DIR__ . '/../pages/session.php';
require_once __DIR__ . '/../pages/admin_report_helpers.php';
require_login('admin');

$pdo  = db();
$user = current_user();

function redirect(string $url): void {
    ob_end_clean();
    if (!headers_sent()) {
        header('Location: ' . $url);
    } else {
        echo '<script>window.location.href=' . json_encode($url) . ';</script>';
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('evacuees.php');
}

$label      = trim($_POST['archive_label'] ?? '');
$disasterId = !empty($_POST['disaster_id']) ? (int)$_POST['disaster_id'] : null;
$archivedBy = (int)$user['id'];

if ($label === '') {
    redirect('evacuees.php?error=label_required');
}

$count = (int)$pdo->query("SELECT COUNT(*) FROM evac_registrations")->fetchColumn();
if ($count === 0) {
    redirect('evacuees.php?error=nothing_to_archive');
}

try {
    $pdo->beginTransaction();

    $archiveCols = 'original_id, center_id, family_head_name, contact_number, birthday, barangay_id,
             adults, children, seniors, pwds, pregnant_women, lactating_mothers, infants_toddlers, total_members,
             created_by, created_at,
             archive_label, disaster_id, archived_by, archived_at';
    $archiveSelect = 'id, center_id, family_head_name, contact_number, birthday, barangay_id,
            adults, children, seniors, pwds, pregnant_women, lactating_mothers, infants_toddlers, total_members,
            created_by, created_at,
            :label, :disaster_id, :archived_by, NOW()';

    if (ar_column_exists($pdo, 'evac_registrations', 'source_user_id')
        && ar_column_exists($pdo, 'evac_registrations_archive', 'source_user_id')) {
        $archiveCols = 'original_id, center_id, source_user_id, family_head_name, contact_number, birthday, barangay_id,
             adults, children, seniors, pwds, pregnant_women, lactating_mothers, infants_toddlers, total_members,
             created_by, created_at,
             archive_label, disaster_id, archived_by, archived_at';
        $archiveSelect = 'id, center_id, source_user_id, family_head_name, contact_number, birthday, barangay_id,
            adults, children, seniors, pwds, pregnant_women, lactating_mothers, infants_toddlers, total_members,
            created_by, created_at,
            :label, :disaster_id, :archived_by, NOW()';
    }

    $stmt = $pdo->prepare("
        INSERT INTO evac_registrations_archive
            ({$archiveCols})
        SELECT
            {$archiveSelect}
        FROM evac_registrations
    ");
    $stmt->execute([
        ':label'       => $label,
        ':disaster_id' => $disasterId,
        ':archived_by' => $archivedBy,
    ]);

    $archivedCount = $stmt->rowCount();

    if (ar_table_exists($pdo, 'evac_registration_members')
        && ar_table_exists($pdo, 'evac_registration_members_archive')) {
        $memberArchive = $pdo->prepare("
            INSERT INTO evac_registration_members_archive
                (archive_registration_id, original_member_id, is_household_head, full_name, sex, birthday,
                 primary_category, is_pwd, is_pregnant, is_lactating, arrived_at)
            SELECT
                era.id, erm.id, erm.is_household_head, erm.full_name, erm.sex, erm.birthday,
                erm.primary_category, erm.is_pwd, erm.is_pregnant, erm.is_lactating, erm.arrived_at
            FROM evac_registration_members erm
            INNER JOIN evac_registrations_archive era
                ON era.original_id = erm.registration_id AND era.archive_label = :label
            WHERE erm.is_present = 1
        ");
        $memberArchive->execute([':label' => $label]);
    }

    $pdo->exec("DELETE FROM evac_registrations");
    $pdo->exec("UPDATE evacuation_centers SET status = 'available'");

    $pdo->commit();

    redirect('evacuees.php?archived=' . $archivedCount . '&label=' . urlencode($label));

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Archive error: ' . $e->getMessage());
    redirect('evacuees.php?error=archive_failed');
}