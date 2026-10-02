<?php
/**
 * Evacuation registration member records (actual arrivals at a center).
 * Separate from household profile (family_members / users).
 */

require_once __DIR__ . '/demographic_helpers.php';
require_once __DIR__ . '/family_member_helpers.php';
require_once __DIR__ . '/center_helpers.php';
require_once __DIR__ . '/family_adjustment.php';

function rm_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $stmt->execute([$table]);
    return $cache[$table] = ((int)$stmt->fetchColumn() > 0);
}

function rm_member_row_to_person(array $row): array
{
    return [
        'primary_category' => $row['primary_category'],
        'is_pwd'           => (int)($row['is_pwd'] ?? 0),
        'is_pregnant'      => (int)($row['is_pregnant'] ?? 0),
        'is_lactating'     => (int)($row['is_lactating'] ?? 0),
    ];
}

function rm_derive_totals_from_member_rows(array $rows): array
{
    $persons = [];
    foreach ($rows as $row) {
        if (empty($row['is_present']) && array_key_exists('is_present', $row)) {
            continue;
        }
        $persons[] = rm_member_row_to_person($row);
    }
    return fm_derive_totals_from_persons($persons);
}

function rm_list_registration_members(PDO $pdo, int $registrationId, bool $presentOnly = true): array
{
    if (!rm_table_exists($pdo, 'evac_registration_members')) {
        return [];
    }

    $sql = 'SELECT * FROM evac_registration_members WHERE registration_id = ?';
    if ($presentOnly) {
        $sql .= ' AND is_present = 1';
    }
    $sql .= ' ORDER BY is_household_head DESC, id ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$registrationId]);
    return array_map('rm_format_registration_member', $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function rm_format_registration_member(array $row): array
{
    $age = null;
    if (!empty($row['birthday'])) {
        try {
            $age = (int)(new DateTime())->diff(new DateTime($row['birthday']))->y;
        } catch (Exception $e) {
            $age = null;
        }
    }

    return [
        'id'                    => (int)$row['id'],
        'registration_id'       => (int)$row['registration_id'],
        'is_household_head'     => (bool)(int)$row['is_household_head'],
        'source_family_member_id'=> $row['source_family_member_id'] ? (int)$row['source_family_member_id'] : null,
        'source_user_id'        => $row['source_user_id'] ? (int)$row['source_user_id'] : null,
        'full_name'             => $row['full_name'],
        'sex'                   => $row['sex'] ?? '',
        'birthday'              => $row['birthday'] ?? '',
        'age'                   => $age,
        'primary_category'      => $row['primary_category'],
        'primary_label'         => fm_category_label($row['primary_category']),
        'is_pwd'                => (bool)(int)$row['is_pwd'],
        'is_pregnant'           => (bool)(int)$row['is_pregnant'],
        'is_lactating'          => (bool)(int)$row['is_lactating'],
        'is_present'            => (bool)(int)$row['is_present'],
        'arrived_at'            => $row['arrived_at'] ?? '',
    ];
}

function rm_tracking_supports_partial(PDO $pdo): bool
{
    static $supports = null;
    if ($supports !== null) {
        return $supports;
    }
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM evac_navigation_tracking LIKE 'status'");
        $col = $stmt->fetch(PDO::FETCH_ASSOC);
        $supports = $col && str_contains((string)($col['Type'] ?? ''), 'partial_arrival');
    } catch (Exception $e) {
        $supports = false;
    }
    return $supports;
}

function rm_column_exists(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $stmt->execute([$table, $column]);
    return $cache[$key] = ((int)$stmt->fetchColumn() > 0);
}

function rm_find_registration_by_source_user(PDO $pdo, int $centerId, int $userId): ?array
{
    if (!rm_column_exists($pdo, 'evac_registrations', 'source_user_id')) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT * FROM evac_registrations WHERE center_id = ? AND source_user_id = ? LIMIT 1'
    );
    $stmt->execute([$centerId, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function rm_sync_registration_aggregates(PDO $pdo, int $registrationId): array
{
    $members = rm_list_registration_members($pdo, $registrationId, true);
    $totals = rm_derive_totals_from_member_rows($members);

    $cols = demo_field_keys();
    $sets = implode(', ', array_map(fn($c) => "$c = ?", $cols));
    $params = array_map(fn($c) => $totals[$c], $cols);
    $params[] = $totals['total_members'];
    $params[] = $registrationId;

    $pdo->prepare(
        "UPDATE evac_registrations SET $sets, total_members = ?, updated_at = NOW() WHERE id = ?"
    )->execute($params);

    return $totals;
}

function rm_insert_member_rows(PDO $pdo, int $registrationId, int $centerId, array $memberRows): void
{
    if (!rm_table_exists($pdo, 'evac_registration_members') || !$memberRows) {
        return;
    }

    $stmt = $pdo->prepare('
        INSERT INTO evac_registration_members
            (registration_id, center_id, is_household_head, source_family_member_id, source_user_id,
             full_name, sex, birthday, primary_category, is_pwd, is_pregnant, is_lactating, is_present)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
    ');

    foreach ($memberRows as $row) {
        $stmt->execute([
            $registrationId,
            $centerId,
            !empty($row['is_household_head']) ? 1 : 0,
            $row['source_family_member_id'] ?? null,
            $row['source_user_id'] ?? null,
            $row['full_name'],
            $row['sex'] ?: null,
            $row['birthday'] ?: null,
            $row['primary_category'],
            !empty($row['is_pwd']) ? 1 : 0,
            !empty($row['is_pregnant']) ? 1 : 0,
            !empty($row['is_lactating']) ? 1 : 0,
        ]);
    }
}

function rm_roster_keys_to_member_rows(PDO $pdo, int $userId, array $rosterKeys): array
{
    $roster = fm_build_household_roster($pdo, $userId);
    $byKey = [];
    foreach ($roster as $person) {
        $byKey[$person['roster_key']] = $person;
    }

    $rows = [];
    foreach ($rosterKeys as $key) {
        $key = trim((string)$key);
        if ($key === '' || !isset($byKey[$key])) {
            continue;
        }
        $p = $byKey[$key];
        $rows[] = [
            'is_household_head'       => !empty($p['is_head']) ? 1 : 0,
            'source_family_member_id' => $p['family_member_id'] ?? null,
            'source_user_id'          => !empty($p['is_head']) ? $userId : null,
            'full_name'               => $p['full_name'],
            'sex'                     => $p['sex'] ?? '',
            'birthday'                => $p['birthday'] ?? null,
            'primary_category'        => $p['primary_category'],
            'is_pwd'                  => !empty($p['is_pwd']) ? 1 : 0,
            'is_pregnant'             => !empty($p['is_pregnant']) ? 1 : 0,
            'is_lactating'            => !empty($p['is_lactating']) ? 1 : 0,
        ];
    }

    return $rows;
}

function rm_member_input_to_row(array $input): ?array
{
    $fullName = trim((string)($input['full_name'] ?? ''));
    if ($fullName === '') {
        return null;
    }

    $primary = trim((string)($input['primary_category'] ?? ''));
    if (!in_array($primary, FM_PRIMARY_CATEGORIES, true)) {
        return null;
    }

    $sex = trim((string)($input['sex'] ?? ''));
    $birthday = trim((string)($input['birthday'] ?? ''));
    if ($birthday !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthday)) {
        $birthday = '';
    }

    return [
        'is_household_head'       => !empty($input['is_household_head']) ? 1 : 0,
        'source_family_member_id' => null,
        'source_user_id'          => null,
        'full_name'               => $fullName,
        'sex'                     => in_array($sex, ['male', 'female', 'prefer_not_to_say'], true) ? $sex : null,
        'birthday'                => $birthday ?: null,
        'primary_category'        => $primary,
        'is_pwd'                  => !empty($input['is_pwd']) ? 1 : 0,
        'is_pregnant'             => !empty($input['is_pregnant']) ? 1 : 0,
        'is_lactating'            => !empty($input['is_lactating']) ? 1 : 0,
    ];
}

function rm_set_expected_from_totals(array $regData, array $expectedTotals): array
{
    foreach (demo_field_keys() as $key) {
        $regData['expected_' . $key] = (int)($expectedTotals[$key] ?? 0);
    }
    $regData['expected_total_members'] = (int)($expectedTotals['total_members'] ?? 0);
    return $regData;
}

/**
 * Record app arrival with individual member selection.
 *
 * @param array<string,mixed> $params
 * @return array{success:bool,errors?:string[],registration_id?:int,partial?:bool}
 */
function rm_record_app_arrival(PDO $pdo, int $centerId, int $createdBy, array $params): array
{
    $trackingId = (int)($params['tracking_id'] ?? 0);
    $userId     = (int)($params['nav_user_id'] ?? 0);
    $rosterKeys = $params['arrived_keys'] ?? [];
    if (!is_array($rosterKeys)) {
        $rosterKeys = [];
    }

    $chk = $pdo->prepare("
        SELECT nt.id, nt.user_id, nt.center_id, u.full_name, u.barangay_id,
               u.contact_number, u.birthday, u.sex
          FROM evac_navigation_tracking nt
          JOIN users u ON u.id = nt.user_id
         WHERE nt.id = ? AND nt.center_id = ? AND nt.status IN ('navigating','partial_arrival')
    ");
    $chk->execute([$trackingId, $centerId]);
    $trackRow = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$trackRow || (int)$trackRow['user_id'] !== $userId) {
        return ['success' => false, 'errors' => ['Could not record arrival — record may no longer be active.']];
    }

    $expectedTotals = fm_derive_household_totals($pdo, $userId);
    $expectedCount  = (int)$expectedTotals['total_members'];
    $memberRows     = rm_roster_keys_to_member_rows($pdo, $userId, $rosterKeys);

    if (!$memberRows) {
        return ['success' => false, 'errors' => ['Select at least one household member who arrived.']];
    }

    $actualTotals = rm_derive_totals_from_member_rows($memberRows);
    if ($actualTotals['total_members'] < 1) {
        return ['success' => false, 'errors' => ['Select at least one household member who arrived.']];
    }

    $existing = rm_find_registration_by_source_user($pdo, $centerId, $userId);
    if (!$existing && family_head_already_registered(
        $pdo,
        $centerId,
        $trackRow['full_name'],
        $trackRow['contact_number'] ?? null,
        $trackRow['birthday'] ?? null
    )) {
        $existing = null;
        $dup = $pdo->prepare("
            SELECT r.* FROM evac_registrations r
             WHERE r.center_id = ? AND r.family_head_name = ?
             LIMIT 1
        ");
        $dup->execute([$centerId, $trackRow['full_name']]);
        $existing = $dup->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    try {
        $pdo->beginTransaction();

        if ($existing) {
            $regId = (int)$existing['id'];
            if (rm_table_exists($pdo, 'evac_registration_members')) {
                $presentKeys = [];
                foreach (rm_list_registration_members($pdo, $regId, true) as $m) {
                    if ($m['is_household_head']) {
                        $presentKeys['head'] = true;
                    } elseif ($m['source_family_member_id']) {
                        $presentKeys['member:' . $m['source_family_member_id']] = true;
                    }
                }
                $newRows = [];
                foreach ($rosterKeys as $key) {
                    if (isset($presentKeys[$key])) {
                        continue;
                    }
                    $newRows = array_merge($newRows, rm_roster_keys_to_member_rows($pdo, $userId, [$key]));
                }
                if (!$newRows) {
                    $pdo->rollBack();
                    return ['success' => false, 'errors' => ['Select additional household members who arrived.']];
                }
                rm_insert_member_rows($pdo, $regId, $centerId, $newRows);
                rm_sync_registration_aggregates($pdo, $regId);
            } else {
                $demo = demo_from_request($actualTotals);
                $total = $actualTotals['total_members'];
                $cols = demo_field_keys();
                $sets = implode(', ', array_map(fn($c) => "$c = $c + ?", $cols));
                $upd = $pdo->prepare("UPDATE evac_registrations SET $sets, total_members = total_members + ? WHERE id = ?");
                $upd->execute([...array_values($demo), $total, $regId]);
            }
        } else {
            $actual = demo_from_request($actualTotals);
            $total  = $actualTotals['total_members'];
            $bind   = array_merge(
                [$centerId, $trackRow['full_name'], $trackRow['contact_number'], $trackRow['birthday'], $trackRow['barangay_id']],
                array_values($actual),
                [$total, $createdBy]
            );
            $insert = [
                'center_id'        => $centerId,
                'family_head_name' => $trackRow['full_name'],
                'contact_number'   => $trackRow['contact_number'],
                'birthday'         => $trackRow['birthday'],
                'barangay_id'      => $trackRow['barangay_id'],
            ];
            foreach (demo_field_keys() as $key) {
                $insert[$key] = $actual[$key];
            }
            $insert['total_members'] = $total;
            $insert['created_by']    = $createdBy;

            if (rm_table_exists($pdo, 'evac_registration_members')) {
                if (rm_column_exists($pdo, 'evac_registrations', 'source_user_id')) {
                    $insert['source_user_id'] = $userId;
                }
                if (rm_column_exists($pdo, 'evac_registrations', 'registration_mode')) {
                    $insert['registration_mode'] = 'members';
                }
                if (rm_column_exists($pdo, 'evac_registrations', 'expected_total_members')) {
                    $insert['expected_total_members'] = $expectedCount;
                    $insert['expected_adults'] = $expectedTotals['adults'];
                    $insert['expected_children'] = $expectedTotals['children'];
                    $insert['expected_seniors'] = $expectedTotals['seniors'];
                    $insert['expected_pwds'] = $expectedTotals['pwds'];
                    $insert['expected_pregnant_women'] = $expectedTotals['pregnant_women'];
                    $insert['expected_lactating_mothers'] = $expectedTotals['lactating_mothers'];
                    $insert['expected_infants_toddlers'] = $expectedTotals['infants_toddlers'];
                }
            }

            $cols = array_keys($insert);
            $ins = $pdo->prepare(
                'INSERT INTO evac_registrations (' . implode(', ', $cols) . ') VALUES (' .
                implode(', ', array_fill(0, count($cols), '?')) . ')'
            );
            $ins->execute(array_values($insert));
            $regId = (int)$pdo->lastInsertId();
            rm_insert_member_rows($pdo, $regId, $centerId, $memberRows);
        }

        $presentCount = rm_table_exists($pdo, 'evac_registration_members')
            ? count(rm_list_registration_members($pdo, $regId, true))
            : $actualTotals['total_members'];

        $newStatus = ($presentCount >= $expectedCount && $expectedCount > 0) ? 'arrived' : 'partial_arrival';
        if ($newStatus === 'partial_arrival' && !rm_tracking_supports_partial($pdo)) {
            $newStatus = 'navigating';
        }
        $updTrack = $pdo->prepare('UPDATE evac_navigation_tracking SET status = ?, updated_at = NOW() WHERE id = ?');
        $updTrack->execute([$newStatus, $trackingId]);

        refresh_center_status($centerId);
        $pdo->commit();

        return [
            'success'         => true,
            'registration_id' => $regId,
            'partial'         => $newStatus === 'partial_arrival',
        ];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('rm_record_app_arrival: ' . $e->getMessage());
        return ['success' => false, 'errors' => ['Database error while recording arrival.']];
    }
}

/**
 * Legacy aggregate-only app arrival (fallback when member table unavailable).
 */
function rm_record_app_arrival_aggregate(PDO $pdo, int $centerId, int $createdBy, array $params): array
{
    $trackingId = (int)($params['tracking_id'] ?? 0);
    $demo       = demo_from_request($params);
    $total      = demo_sum_row($demo);

    if ($total < 1) {
        return ['success' => false, 'errors' => ['Select at least one person.']];
    }

    $chk = $pdo->prepare("
        SELECT nt.id, u.full_name, u.barangay_id, u.contact_number, u.birthday
          FROM evac_navigation_tracking nt
          JOIN users u ON u.id = nt.user_id
         WHERE nt.id = ? AND nt.center_id = ? AND nt.status IN ('navigating','partial_arrival')
    ");
    $chk->execute([$trackingId, $centerId]);
    $trackRow = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$trackRow) {
        return ['success' => false, 'errors' => ['Could not record arrival.']];
    }

    if (family_head_already_registered($pdo, $centerId, $trackRow['full_name'], $trackRow['contact_number'], $trackRow['birthday'])) {
        $pdo->prepare("UPDATE evac_navigation_tracking SET status = 'arrived', updated_at = NOW() WHERE id = ?")
            ->execute([$trackingId]);
        return ['success' => true, 'duplicate' => true];
    }

    $demoCols = implode(', ', demo_field_keys());
    $demoPh   = implode(', ', array_fill(0, count(demo_field_keys()), '?'));
    $modeCol  = rm_column_exists($pdo, 'evac_registrations', 'registration_mode');
    $columns  = "center_id, family_head_name, contact_number, birthday, barangay_id, $demoCols, total_members, created_by";
    $values   = "?, ?, ?, ?, ?, $demoPh, ?, ?";
    $bind     = array_merge(
        [$centerId, $trackRow['full_name'], $trackRow['contact_number'], $trackRow['birthday'], $trackRow['barangay_id']],
        array_values($demo),
        [$total, $createdBy]
    );
    if ($modeCol) {
        $columns .= ', registration_mode';
        $values  .= ", 'aggregate'";
    }
    $ins = $pdo->prepare("INSERT INTO evac_registrations ($columns) VALUES ($values)");
    $ins->execute($bind);

    $pdo->prepare("UPDATE evac_navigation_tracking SET status = 'arrived', updated_at = NOW() WHERE id = ?")
        ->execute([$trackingId]);
    refresh_center_status($centerId);

    return ['success' => true, 'registration_id' => (int)$pdo->lastInsertId()];
}

function rm_add_walkin_members(PDO $pdo, int $registrationId, int $centerId, array $members): void
{
    $rows = [];
    foreach ($members as $input) {
        $row = rm_member_input_to_row($input);
        if ($row) {
            $rows[] = $row;
        }
    }
    if (!$rows) {
        return;
    }
    rm_insert_member_rows($pdo, $registrationId, $centerId, $rows);
    rm_sync_registration_aggregates($pdo, $registrationId);
}

function rm_add_registration_member(PDO $pdo, int $centerId, int $regId, array $input): array
{
    $reg = fetch_registration_for_center($pdo, $regId, $centerId);
    if (!$reg) {
        return ['success' => false, 'errors' => ['Registration not found.']];
    }
    if (!rm_table_exists($pdo, 'evac_registration_members')) {
        return apply_family_adjustment($pdo, $centerId, $regId, $input['primary_category'] ?? 'adults', 1, null);
    }

    $row = rm_member_input_to_row($input);
    if (!$row) {
        return ['success' => false, 'errors' => ['Invalid member information.']];
    }

    rm_insert_member_rows($pdo, $regId, $centerId, [$row]);
    rm_sync_registration_aggregates($pdo, $regId);
    refresh_center_status($centerId);

    return [
        'success' => true,
        'registration' => fetch_registration_for_center($pdo, $regId, $centerId),
        'members' => rm_list_registration_members($pdo, $regId, true),
    ];
}

function rm_remove_registration_member(PDO $pdo, int $centerId, int $regId, int $memberId): array
{
    $reg = fetch_registration_for_center($pdo, $regId, $centerId);
    if (!$reg) {
        return ['success' => false, 'errors' => ['Registration not found.']];
    }
    if (!rm_table_exists($pdo, 'evac_registration_members')) {
        return ['success' => false, 'errors' => ['Member-level adjustments require database migration.']];
    }

    $stmt = $pdo->prepare(
        'UPDATE evac_registration_members SET is_present = 0, checked_out_at = NOW(), updated_at = NOW()
          WHERE id = ? AND registration_id = ? AND center_id = ?'
    );
    $stmt->execute([$memberId, $regId, $centerId]);
    if ($stmt->rowCount() === 0) {
        return ['success' => false, 'errors' => ['Member not found.']];
    }

    rm_sync_registration_aggregates($pdo, $regId);
    refresh_center_status($centerId);

    return [
        'success' => true,
        'registration' => fetch_registration_for_center($pdo, $regId, $centerId),
        'members' => rm_list_registration_members($pdo, $regId, true),
    ];
}

function rm_registration_to_roster_item(array $row, PDO $pdo): array
{
    $item = registration_to_roster_item($row);
    $item['registration_mode'] = $row['registration_mode'] ?? 'aggregate';
    $item['expected_total_members'] = isset($row['expected_total_members']) ? (int)$row['expected_total_members'] : null;
    $item['source_user_id'] = isset($row['source_user_id']) ? (int)$row['source_user_id'] : null;
    if ((int)$row['id'] > 0) {
        $item['members'] = rm_list_registration_members($pdo, (int)$row['id'], true);
    } else {
        $item['members'] = [];
    }
    return $item;
}
