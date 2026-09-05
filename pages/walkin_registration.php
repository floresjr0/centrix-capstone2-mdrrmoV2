<?php
require_once __DIR__ . '/center_helpers.php';
require_once __DIR__ . '/demographic_helpers.php';

/**
 * Register a walk-in family at an evacuation center.
 *
 * @param array<string,mixed> $input
 * @return array{success:bool,id?:int,already_synced?:bool,errors?:string[]}
 */
function register_walkin_family(PDO $pdo, int $centerId, int $createdBy, array $input): array
{
    $headName      = trim((string)($input['family_head_name'] ?? ''));
    $contactNumber = trim((string)($input['contact_number'] ?? ''));
    $birthday      = (string)($input['birthday'] ?? '');
    $barangayId    = (int)($input['barangay_id'] ?? 0);
    $localUuid     = trim((string)($input['local_uuid'] ?? ''));
    $demo          = demo_from_request($input);
    $total         = demo_sum_row($demo);
    $errors        = [];

    if ($localUuid !== '' && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $localUuid)) {
        $errors[] = 'Invalid offline reference id.';
    }

    if ($headName === '') {
        $errors[] = 'Head of family name is required.';
    }
    if ($contactNumber === '') {
        $errors[] = 'Contact number is required.';
    }
    if ($birthday === '') {
        $errors[] = 'Birthday is required.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthday)) {
        $errors[] = 'Invalid birthday format (YYYY-MM-DD).';
    }
    if (!$barangayId) {
        $errors[] = 'Barangay is required.';
    }
    if ($total <= 0) {
        $errors[] = 'Please specify at least one member.';
    }

    if ($errors) {
        return ['success' => false, 'errors' => $errors];
    }

    if ($localUuid !== '') {
        $existing = $pdo->prepare('SELECT id FROM evac_registrations WHERE client_local_uuid = ? LIMIT 1');
        $existing->execute([$localUuid]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return [
                'success' => true,
                'id' => (int)$row['id'],
                'already_synced' => true,
            ];
        }
    }

    if (family_head_already_registered($pdo, $centerId, $headName, $contactNumber, $birthday)) {
        return [
            'success' => false,
            'errors' => ['This family head is already registered at this center.'],
        ];
    }

    $demoCols = implode(', ', demo_field_keys());
    $demoPh   = implode(', ', array_fill(0, count(demo_field_keys()), '?'));
    $columns  = 'center_id, family_head_name, contact_number, birthday, barangay_id, '
        . $demoCols . ', total_members, created_by';
    $values   = '?, ?, ?, ?, ?, ' . $demoPh . ', ?, ?';
    $params   = array_merge(
        [$centerId, $headName, $contactNumber, $birthday, $barangayId],
        array_values($demo),
        [$total, $createdBy]
    );

    if ($localUuid !== '') {
        $columns .= ', client_local_uuid';
        $values  .= ', ?';
        $params[] = $localUuid;
    }

    $ins = $pdo->prepare("INSERT INTO evac_registrations ($columns) VALUES ($values)");
    $ins->execute($params);

    refresh_center_status($centerId);

    return [
        'success' => true,
        'id' => (int)$pdo->lastInsertId(),
        'already_synced' => false,
    ];
}
