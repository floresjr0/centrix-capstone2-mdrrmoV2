<?php
require_once __DIR__ . '/center_helpers.php';
require_once __DIR__ . '/demographic_helpers.php';
require_once __DIR__ . '/family_member_helpers.php';
require_once __DIR__ . '/registration_member_helpers.php';

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
    $membersRaw    = $input['members'] ?? ($input['members_json'] ?? null);
    if (is_string($membersRaw)) {
        $decoded = json_decode($membersRaw, true);
        $membersRaw = is_array($decoded) ? $decoded : [];
    }
    $memberInputs  = is_array($membersRaw) ? $membersRaw : [];
    $hasMemberTable = rm_table_exists($pdo, 'evac_registration_members');

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
    $memberRows = [];
    $useMembers = false;

    // Count-only legacy path: explicit demographic totals with no named members payload.
    $hasNamedMembersPayload = !empty($memberInputs);
    $legacyAggregate = !$hasNamedMembersPayload && demo_sum_row($demo) > 0;

    if ($hasMemberTable && !$legacyAggregate) {
        $headCategory = trim((string)($input['head_primary_category'] ?? ''));
        if ($headCategory === '' || !in_array($headCategory, FM_PRIMARY_CATEGORIES, true)) {
            $headCategory = fm_primary_category_from_birthday($birthday) ?? 'adults';
        }
        $headInput = [
            'full_name'         => $headName,
            'sex'               => $input['head_sex'] ?? '',
            'birthday'          => $birthday,
            'primary_category'  => $headCategory,
            'is_household_head' => 1,
            'is_pwd'            => !empty($input['head_is_pwd']) ? 1 : 0,
            'is_pregnant'       => !empty($input['head_is_pregnant']) ? 1 : 0,
            'is_lactating'      => !empty($input['head_is_lactating']) ? 1 : 0,
        ];
        $headRow = rm_member_input_to_row($headInput);
        if (!$headRow) {
            $errors[] = 'Could not register family head — check birthday and category.';
        } else {
            $memberRows[] = $headRow;
        }

        foreach ($memberInputs as $memberInput) {
            if (!is_array($memberInput)) {
                continue;
            }
            if (!empty($memberInput['is_household_head'])) {
                continue;
            }
            $row = rm_member_input_to_row($memberInput);
            if ($row) {
                $memberRows[] = $row;
            }
        }

        if ($memberRows) {
            $useMembers = true;
            $derived = rm_derive_totals_from_member_rows($memberRows);
            $demo    = demo_from_request($derived);
            $total   = $derived['total_members'];
        }
    } elseif ($legacyAggregate) {
        $total = demo_sum_row($demo);
        $useMembers = false;
    } elseif ($total <= 0) {
        $cat = fm_primary_category_from_birthday($birthday) ?? 'adults';
        $demo = demo_defaults(0);
        $demo[$cat] = 1;
        $total = 1;

        // Head-only walk-in: still persist the family head as an individual member row.
        if ($hasMemberTable) {
            $headInput = [
                'full_name'         => $headName,
                'sex'               => $input['head_sex'] ?? '',
                'birthday'          => $birthday,
                'primary_category'  => $cat,
                'is_household_head' => 1,
                'is_pwd'            => !empty($input['head_is_pwd']) ? 1 : 0,
                'is_pregnant'       => !empty($input['head_is_pregnant']) ? 1 : 0,
                'is_lactating'      => !empty($input['head_is_lactating']) ? 1 : 0,
            ];
            $headRow = rm_member_input_to_row($headInput);
            if ($headRow) {
                $memberRows = [$headRow];
                $useMembers = true;
            }
        }
    }

    if ($errors) {
        return ['success' => false, 'errors' => $errors];
    }

    $hasLocalUuidCol = rm_column_exists($pdo, 'evac_registrations', 'client_local_uuid');
    if ($localUuid !== '' && $hasLocalUuidCol) {
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

    if ($useMembers && rm_column_exists($pdo, 'evac_registrations', 'registration_mode')) {
        $columns .= ', registration_mode';
        $values  .= ", 'members'";
    } elseif (rm_column_exists($pdo, 'evac_registrations', 'registration_mode')) {
        $columns .= ', registration_mode';
        $values  .= ", 'aggregate'";
    }

    if ($localUuid !== '' && $hasLocalUuidCol) {
        $columns .= ', client_local_uuid';
        $values  .= ', ?';
        $params[] = $localUuid;
    }

    try {
        $pdo->beginTransaction();

        $ins = $pdo->prepare("INSERT INTO evac_registrations ($columns) VALUES ($values)");
        $ins->execute($params);
        $regId = (int)$pdo->lastInsertId();

        if ($useMembers && $memberRows) {
            rm_insert_member_rows($pdo, $regId, $centerId, $memberRows);
            rm_sync_registration_aggregates($pdo, $regId);
        }

        refresh_center_status($centerId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('register_walkin_family: ' . $e->getMessage());
        return [
            'success' => false,
            'errors'  => ['Could not save registration. Please verify the form and try again.'],
        ];
    }

    return [
        'success' => true,
        'id' => $regId,
        'already_synced' => false,
        'registration_mode' => $useMembers ? 'members' : 'aggregate',
    ];
}
