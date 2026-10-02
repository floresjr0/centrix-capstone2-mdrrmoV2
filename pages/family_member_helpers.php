<?php
/**
 * Individual household members + derived aggregate totals for family_profiles.
 *
 * Family head lives in users (not family_members). Totals = head + members (headcount).
 */

require_once __DIR__ . '/demographic_helpers.php';

const FM_PRIMARY_CATEGORIES = ['adults', 'children', 'seniors', 'infants_toddlers'];

function fm_primary_category_from_birthday(?string $birthday): ?string
{
    if (!$birthday) {
        return null;
    }
    try {
        $birth = new DateTime($birthday);
    } catch (Exception $e) {
        return null;
    }
    $age = (int)(new DateTime())->diff($birth)->y;
    if ($age <= 2) {
        return 'infants_toddlers';
    }
    if ($age <= 17) {
        return 'children';
    }
    if ($age >= 60) {
        return 'seniors';
    }
    return 'adults';
}

function fm_category_label(string $key): string
{
    $map = [
        'adults'           => 'Adult',
        'children'         => 'Child',
        'seniors'          => 'Senior',
        'infants_toddlers' => 'Infant / Toddler',
    ];
    return $map[$key] ?? $key;
}

function fm_fetch_user(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function fm_fetch_family_profile(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM family_profiles WHERE user_id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function fm_list_members(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('
        SELECT id, user_id, full_name, sex, birthday, primary_category,
               is_pwd, is_pregnant, is_lactating, created_at, updated_at
          FROM family_members
         WHERE user_id = ?
         ORDER BY id ASC
    ');
    $stmt->execute([$userId]);
    return array_map('fm_format_member', $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function fm_format_member(array $row): array
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
        'id'               => (int)$row['id'],
        'full_name'        => $row['full_name'],
        'sex'              => $row['sex'] ?? '',
        'birthday'         => $row['birthday'] ?? '',
        'age'              => $age,
        'primary_category' => $row['primary_category'],
        'primary_label'    => fm_category_label($row['primary_category']),
        'is_pwd'           => (bool)(int)$row['is_pwd'],
        'is_pregnant'      => (bool)(int)$row['is_pregnant'],
        'is_lactating'     => (bool)(int)$row['is_lactating'],
    ];
}

function fm_head_as_person(array $user): ?array
{
    $category = fm_primary_category_from_birthday($user['birthday'] ?? null);
    if (!$category) {
        return null;
    }

    return [
        'is_head'          => true,
        'full_name'        => $user['full_name'] ?? '',
        'sex'              => $user['sex'] ?? '',
        'birthday'         => $user['birthday'] ?? '',
        'primary_category' => $category,
        'is_pwd'           => 0,
        'is_pregnant'      => 0,
        'is_lactating'     => 0,
    ];
}

/**
 * Full household roster for coordinator display (head + family_members).
 *
 * @return list<array<string,mixed>>
 */
function fm_build_household_roster(PDO $pdo, int $userId): array
{
    $user = fm_fetch_user($pdo, $userId);
    if (!$user) {
        return [];
    }

    $roster = [];
    $head = fm_head_as_person($user);
    if ($head) {
        $age = null;
        if (!empty($user['birthday'])) {
            try {
                $age = (int)(new DateTime())->diff(new DateTime($user['birthday']))->y;
            } catch (Exception $e) {
                $age = null;
            }
        }
        $roster[] = array_merge($head, [
            'roster_key'    => 'head',
            'family_member_id' => null,
            'age'           => $age,
            'primary_label' => fm_category_label($head['primary_category']),
        ]);
    }

    foreach (fm_list_members($pdo, $userId) as $member) {
        $roster[] = array_merge($member, [
            'roster_key'       => 'member:' . $member['id'],
            'family_member_id' => $member['id'],
            'is_head'          => false,
        ]);
    }

    return $roster;
}

function fm_collect_persons(PDO $pdo, int $userId): array
{
    $user = fm_fetch_user($pdo, $userId);
    if (!$user) {
        return [];
    }

    $persons = [];
    $head = fm_head_as_person($user);
    if ($head) {
        $persons[] = $head;
    }

    $stmt = $pdo->prepare('
        SELECT primary_category, is_pwd, is_pregnant, is_lactating
          FROM family_members
         WHERE user_id = ?
    ');
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $persons[] = [
            'is_head'          => false,
            'primary_category' => $row['primary_category'],
            'is_pwd'           => (int)$row['is_pwd'],
            'is_pregnant'      => (int)$row['is_pregnant'],
            'is_lactating'     => (int)$row['is_lactating'],
        ];
    }

    return $persons;
}

function fm_derive_totals_from_persons(array $persons): array
{
    $totals = demo_defaults(0);
    foreach (demo_field_keys() as $key) {
        $totals[$key] = 0;
    }

    $totals['total_members'] = count($persons);

    foreach ($persons as $person) {
        $cat = $person['primary_category'] ?? '';
        if (isset($totals[$cat])) {
            $totals[$cat]++;
        }
        if (!empty($person['is_pwd'])) {
            $totals['pwds']++;
        }
        if (!empty($person['is_pregnant'])) {
            $totals['pregnant_women']++;
        }
        if (!empty($person['is_lactating'])) {
            $totals['lactating_mothers']++;
        }
    }

    return $totals;
}

function fm_derive_household_totals(PDO $pdo, int $userId): array
{
    return fm_derive_totals_from_persons(fm_collect_persons($pdo, $userId));
}

function fm_sync_family_profiles(PDO $pdo, int $userId, bool $markMembersSource = true): array
{
    $totals = fm_derive_household_totals($pdo, $userId);
    $existing = fm_fetch_family_profile($pdo, $userId);

    $cols = demo_field_keys();
    $colList = implode(', ', $cols);
    $placeholders = implode(', ', array_map(fn($c) => ':' . $c, $cols));
    $updates = implode(', ', array_map(fn($c) => "$c = VALUES($c)", $cols));

    $params = [
        ':uid'   => $userId,
        ':total' => $totals['total_members'],
    ];
    foreach ($totals as $key => $val) {
        if ($key === 'total_members') {
            continue;
        }
        $params[':' . $key] = $val;
    }

    if ($markMembersSource) {
        $stmt = $pdo->prepare("
            INSERT INTO family_profiles
                (user_id, $colList, total_members, profile_source)
            VALUES (:uid, $placeholders, :total, 'members')
            ON DUPLICATE KEY UPDATE
                $updates,
                total_members = VALUES(total_members),
                profile_source = 'members',
                updated_at = NOW()
        ");
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO family_profiles
                (user_id, $colList, total_members)
            VALUES (:uid, $placeholders, :total)
            ON DUPLICATE KEY UPDATE
                $updates,
                total_members = VALUES(total_members),
                updated_at = NOW()
        ");
    }
    $stmt->execute($params);

    $pdo->prepare("
        UPDATE evacuation_intentions
           SET household_size = ?,
               updated_at     = NOW()
         WHERE user_id = ? AND status = 'going'
    ")->execute([$totals['total_members'], $userId]);

    return $totals;
}

function fm_validate_member_input(array $input, ?int $memberId = null): array
{
    $fullName = trim($input['full_name'] ?? '');
    if (mb_strlen($fullName) < 2) {
        return ['ok' => false, 'error' => 'Ilagay ang buong pangalan ng miyembro ng sambahayan.'];
    }

    $sex = trim($input['sex'] ?? '');
    $allowedSex = ['male', 'female', 'prefer_not_to_say', ''];
    if (!in_array($sex, $allowedSex, true)) {
        return ['ok' => false, 'error' => 'Hindi wastong halaga ng kasarian.'];
    }

    $birthdaySQL = null;
    $birthdayRaw = trim($input['birthday'] ?? '');
    if ($birthdayRaw !== '') {
        $parsed = DateTime::createFromFormat('Y-m-d', $birthdayRaw);
        if (!$parsed || $parsed->format('Y-m-d') !== $birthdayRaw) {
            return ['ok' => false, 'error' => 'Hindi wastong format ng petsa ng kaarawan.'];
        }
        if ($parsed > new DateTime()) {
            return ['ok' => false, 'error' => 'Hindi maaaring hinaharap ang petsa ng kaarawan.'];
        }
        $birthdaySQL = $parsed->format('Y-m-d');
    }

    $primary = trim($input['primary_category'] ?? '');
    if (!in_array($primary, FM_PRIMARY_CATEGORIES, true)) {
        return ['ok' => false, 'error' => 'Pumili ng wastong kategorya ng miyembro.'];
    }

    $isPwd = !empty($input['is_pwd']) ? 1 : 0;
    $isPregnant = !empty($input['is_pregnant']) ? 1 : 0;
    $isLactating = !empty($input['is_lactating']) ? 1 : 0;

    if ($sex !== 'female') {
        $isPregnant = 0;
        $isLactating = 0;
    }

    if ($birthdaySQL) {
        $suggested = fm_primary_category_from_birthday($birthdaySQL);
        if ($suggested && $suggested !== $primary && empty($input['primary_category_override'])) {
            // Allow explicit category selection; only auto-suggest in UI.
        }
    }

    return [
        'ok'   => true,
        'data' => [
            'id'               => $memberId,
            'full_name'        => $fullName,
            'sex'              => $sex ?: null,
            'birthday'         => $birthdaySQL,
            'primary_category' => $primary,
            'is_pwd'           => $isPwd,
            'is_pregnant'      => $isPregnant,
            'is_lactating'     => $isLactating,
        ],
    ];
}

function fm_save_member(PDO $pdo, int $userId, array $input): array
{
    $memberId = isset($input['id']) ? (int)$input['id'] : 0;
    $validated = fm_validate_member_input($input, $memberId ?: null);
    if (!$validated['ok']) {
        return $validated;
    }
    $data = $validated['data'];

    try {
        $pdo->beginTransaction();

        $pdo->prepare('
            UPDATE family_profiles
               SET lives_alone_confirmed = 0,
                   updated_at = NOW()
             WHERE user_id = ?
        ')->execute([$userId]);

        if ($memberId > 0) {
            $own = $pdo->prepare('SELECT id FROM family_members WHERE id = ? AND user_id = ?');
            $own->execute([$memberId, $userId]);
            if (!$own->fetch()) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'Hindi mahanap ang miyembro ng sambahayan.'];
            }

            $stmt = $pdo->prepare('
                UPDATE family_members
                   SET full_name = :name,
                       sex = :sex,
                       birthday = :birthday,
                       primary_category = :cat,
                       is_pwd = :pwd,
                       is_pregnant = :preg,
                       is_lactating = :lac,
                       updated_at = NOW()
                 WHERE id = :id AND user_id = :uid
            ');
            $stmt->execute([
                ':name'     => $data['full_name'],
                ':sex'      => $data['sex'],
                ':birthday' => $data['birthday'],
                ':cat'      => $data['primary_category'],
                ':pwd'      => $data['is_pwd'],
                ':preg'     => $data['is_pregnant'],
                ':lac'      => $data['is_lactating'],
                ':id'       => $memberId,
                ':uid'      => $userId,
            ]);
        } else {
            $stmt = $pdo->prepare('
                INSERT INTO family_members
                    (user_id, full_name, sex, birthday, primary_category, is_pwd, is_pregnant, is_lactating)
                VALUES
                    (:uid, :name, :sex, :birthday, :cat, :pwd, :preg, :lac)
            ');
            $stmt->execute([
                ':uid'      => $userId,
                ':name'     => $data['full_name'],
                ':sex'      => $data['sex'],
                ':birthday' => $data['birthday'],
                ':cat'      => $data['primary_category'],
                ':pwd'      => $data['is_pwd'],
                ':preg'     => $data['is_pregnant'],
                ':lac'      => $data['is_lactating'],
            ]);
            $memberId = (int)$pdo->lastInsertId();
        }

        fm_mark_household_setup_if_complete($pdo, $userId);
        $totals = fm_sync_family_profiles($pdo, $userId);
        $pdo->commit();

        $member = null;
        foreach (fm_list_members($pdo, $userId) as $m) {
            if ($m['id'] === $memberId) {
                $member = $m;
                break;
            }
        }

        return [
            'ok'       => true,
            'member'   => $member,
            'household'=> $totals,
            'message'  => 'Na-save ang miyembro ng sambahayan.',
        ];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('fm_save_member error: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'May error sa database. Subukan ulit.'];
    }
}

function fm_delete_member(PDO $pdo, int $userId, int $memberId): array
{
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('DELETE FROM family_members WHERE id = ? AND user_id = ?');
        $stmt->execute([$memberId, $userId]);
        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Hindi mahanap ang miyembro ng sambahayan.'];
        }

        $totals = fm_sync_family_profiles($pdo, $userId);
        $pdo->commit();

        return [
            'ok'        => true,
            'household' => $totals,
            'message'   => 'Naalis ang miyembro ng sambahayan.',
        ];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('fm_delete_member error: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'May error sa database. Subukan ulit.'];
    }
}

function fm_confirm_lives_alone(PDO $pdo, int $userId): array
{
    try {
        $pdo->beginTransaction();

        $pdo->prepare('DELETE FROM family_members WHERE user_id = ?')->execute([$userId]);

        $cols = demo_field_keys();
        $colList = implode(', ', $cols);
        $placeholders = implode(', ', array_map(fn($c) => ':' . $c, $cols));
        $updates = implode(', ', array_map(fn($c) => "$c = VALUES($c)", $cols));

        $totals = fm_derive_household_totals($pdo, $userId);
        $params = [
            ':uid'   => $userId,
            ':total' => $totals['total_members'],
            ':alone' => 1,
        ];
        foreach ($totals as $key => $val) {
            if ($key === 'total_members') {
                continue;
            }
            $params[':' . $key] = $val;
        }

        $stmt = $pdo->prepare("
            INSERT INTO family_profiles
                (user_id, $colList, total_members, lives_alone_confirmed, profile_source, household_setup_at)
            VALUES (:uid, $placeholders, :total, :alone, 'members', NOW())
            ON DUPLICATE KEY UPDATE
                $updates,
                total_members = VALUES(total_members),
                lives_alone_confirmed = 1,
                profile_source = 'members',
                household_setup_at = COALESCE(household_setup_at, NOW()),
                updated_at = NOW()
        ");
        $stmt->execute($params);

        $pdo->prepare("
            UPDATE evacuation_intentions
               SET household_size = ?,
                   updated_at     = NOW()
             WHERE user_id = ? AND status = 'going'
        ")->execute([$totals['total_members'], $userId]);

        $pdo->commit();

        return [
            'ok'        => true,
            'household' => $totals,
            'message'   => 'Nakumpirma na kayo lang ang nakatira sa inyong tahanan.',
        ];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('fm_confirm_lives_alone error: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'May error sa database. Subukan ulit.'];
    }
}

function fm_member_count(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM family_members WHERE user_id = ?');
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

function fm_lives_alone_confirmed(PDO $pdo, int $userId): bool
{
    $fp = fm_fetch_family_profile($pdo, $userId);
    return $fp && (int)($fp['lives_alone_confirmed'] ?? 0) === 1;
}

function fm_mark_household_setup_if_complete(PDO $pdo, int $userId): void
{
    require_once __DIR__ . '/profile_completion_helpers.php';
    if (!pc_is_family_profile_complete($pdo, $userId)) {
        return;
    }

    $pdo->prepare('
        UPDATE family_profiles
           SET household_setup_at = COALESCE(household_setup_at, NOW()),
               updated_at = NOW()
         WHERE user_id = ?
    ')->execute([$userId]);
}

function fm_build_profile_payload(PDO $pdo, int $userId): array
{
    require_once __DIR__ . '/profile_completion_helpers.php';

    $user = fm_fetch_user($pdo, $userId);
    if (!$user) {
        return ['ok' => false, 'error' => 'User not found.'];
    }

    $barangayStmt = $pdo->prepare('SELECT name FROM barangays WHERE id = ?');
    $barangayStmt->execute([$user['barangay_id'] ?? 0]);
    $barangayName = $barangayStmt->fetchColumn() ?: '';

    $age = null;
    if (!empty($user['birthday'])) {
        try {
            $age = (int)(new DateTime())->diff(new DateTime($user['birthday']))->y;
        } catch (Exception $e) {
            $age = null;
        }
    }

    $household = fm_derive_household_totals($pdo, $userId);
    $headCategory = fm_primary_category_from_birthday($user['birthday'] ?? null);

    return [
        'ok'                   => true,
        'full_name'            => $user['full_name'] ?? '',
        'first_name'           => $user['first_name'] ?? '',
        'last_name'            => $user['last_name'] ?? '',
        'middle_name'          => $user['middle_name'] ?? '',
        'suffix'               => $user['suffix'] ?? '',
        'email'                => $user['email'] ?? '',
        'contact_number'       => $user['contact_number'] ?? '',
        'house_number'         => $user['house_number'] ?? '',
        'barangay_name'        => $barangayName,
        'birthday'             => $user['birthday'] ?? '',
        'sex'                  => $user['sex'] ?? '',
        'age'                  => $age,
        'head_primary_category'=> $headCategory,
        'head_primary_label'   => $headCategory ? fm_category_label($headCategory) : '',
        'members'              => fm_list_members($pdo, $userId),
        'lives_alone_confirmed'=> fm_lives_alone_confirmed($pdo, $userId),
        'household'            => $household,
        'profile_status'       => pc_get_profile_completion_status($pdo, $userId),
    ];
}
