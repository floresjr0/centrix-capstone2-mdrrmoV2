<?php
require_once __DIR__ . '/center_helpers.php';
require_once __DIR__ . '/demographic_helpers.php';

/**
 * Apply a +1/-1 household count adjustment to an existing registration.
 *
 * @return array{success:bool,already_applied?:bool,registration?:array,errors?:string[]}
 */
function apply_family_adjustment(
    PDO $pdo,
    int $centerId,
    int $regId,
    string $field,
    int $delta,
    ?string $clientAdjustmentUuid = null
): array {
    if (!in_array($field, demo_field_keys(), true)) {
        return ['success' => false, 'errors' => ['Invalid demographic field.']];
    }
    if (!in_array($delta, [-1, 1], true)) {
        return ['success' => false, 'errors' => ['Invalid adjustment delta.']];
    }

    if ($clientAdjustmentUuid !== null && $clientAdjustmentUuid !== '') {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $clientAdjustmentUuid)) {
            return ['success' => false, 'errors' => ['Invalid adjustment reference id.']];
        }

        $logCheck = $pdo->prepare(
            'SELECT id FROM evac_registration_adjustment_log WHERE client_adjustment_uuid = ? LIMIT 1'
        );
        $logCheck->execute([$clientAdjustmentUuid]);
        if ($logCheck->fetch()) {
            $reg = fetch_registration_for_center($pdo, $regId, $centerId);
            if (!$reg) {
                return ['success' => false, 'errors' => ['Registration not found.']];
            }
            return [
                'success' => true,
                'already_applied' => true,
                'registration' => $reg,
            ];
        }
    }

    $check = $pdo->prepare(
        'SELECT r.*, b.name AS barangay_name
         FROM evac_registrations r
         JOIN barangays b ON b.id = r.barangay_id
         WHERE r.id = ? AND r.center_id = ?'
    );
    $check->execute([$regId, $centerId]);
    $reg = $check->fetch(PDO::FETCH_ASSOC);
    if (!$reg) {
        return ['success' => false, 'errors' => ['Registration not found for this center.']];
    }

    $demo = demo_from_request($reg);
    $demo[$field] = max(0, (int)$reg[$field] + $delta);
    $total = demo_sum_row($demo);

    $sets = implode(', ', array_map(fn($k) => "$k=?", demo_field_keys()));
    $upd = $pdo->prepare("UPDATE evac_registrations SET $sets, total_members=? WHERE id=? AND center_id=?");
    $upd->execute([...array_values($demo), $total, $regId, $centerId]);

    if ($clientAdjustmentUuid !== null && $clientAdjustmentUuid !== '') {
        $logIns = $pdo->prepare(
            'INSERT INTO evac_registration_adjustment_log
             (client_adjustment_uuid, registration_id, center_id, field_name, delta)
             VALUES (?, ?, ?, ?, ?)'
        );
        $logIns->execute([$clientAdjustmentUuid, $regId, $centerId, $field, $delta]);
    }

    refresh_center_status($centerId);

    $updated = fetch_registration_for_center($pdo, $regId, $centerId);
    return [
        'success' => true,
        'already_applied' => false,
        'registration' => $updated,
    ];
}

function fetch_registration_for_center(PDO $pdo, int $regId, int $centerId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT r.*, b.name AS barangay_name
         FROM evac_registrations r
         JOIN barangays b ON b.id = r.barangay_id
         WHERE r.id = ? AND r.center_id = ?'
    );
    $stmt->execute([$regId, $centerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function registration_to_roster_item(array $row): array
{
    $item = [
        'id' => (int)$row['id'],
        'center_id' => (int)$row['center_id'],
        'family_head_name' => (string)$row['family_head_name'],
        'contact_number' => (string)($row['contact_number'] ?? ''),
        'birthday' => (string)($row['birthday'] ?? ''),
        'barangay_id' => (int)$row['barangay_id'],
        'barangay_name' => (string)($row['barangay_name'] ?? ''),
        'total_members' => (int)$row['total_members'],
    ];
    foreach (demo_field_keys() as $key) {
        $item[$key] = (int)($row[$key] ?? 0);
    }
    return $item;
}
