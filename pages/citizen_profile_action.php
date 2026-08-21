<?php
// pages/citizen_profile_action.php
// GET  ?action=get   → returns current user profile + household
// POST ?action=save  → saves name, contact, birthday, sex, household

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/demographic_helpers.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$user   = current_user();
$pdo    = db();
$action = $_GET['action'] ?? '';

// ── GET: return current profile + household ──────────────────────
if ($action === 'get') {

    // Fetch fresh user row (current_user() may be cached in session)
    $stmt = $pdo->prepare("
        SELECT u.*, b.name AS barangay_name
          FROM users u
          LEFT JOIN barangays b ON b.id = u.barangay_id
         WHERE u.id = ?
    ");
    $stmt->execute([$user['id']]);
    $freshUser = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch household row
    $hhStmt = $pdo->prepare("SELECT * FROM family_profiles WHERE user_id = ?");
    $hhStmt->execute([$user['id']]);
    $hh = $hhStmt->fetch(PDO::FETCH_ASSOC);

    // Compute age from birthday if available
    $age = null;
    if (!empty($freshUser['birthday'])) {
        $birthDate = new DateTime($freshUser['birthday']);
        $today     = new DateTime();
        $age       = (int)$birthDate->diff($today)->y;
    }

    echo json_encode([
        'ok'             => true,
        'full_name'      => $freshUser['full_name']      ?? '',
        'first_name'     => $freshUser['first_name']     ?? '',
        'last_name'      => $freshUser['last_name']      ?? '',
        'middle_name'    => $freshUser['middle_name']    ?? '',
        'suffix'         => $freshUser['suffix']         ?? '',
        'email'          => $freshUser['email']          ?? '',
        'contact_number' => $freshUser['contact_number'] ?? '',
        'house_number'   => $freshUser['house_number']   ?? '',
        'barangay_name'  => $freshUser['barangay_name']  ?? '',
        'birthday'       => $freshUser['birthday']       ?? '',
        'sex'            => $freshUser['sex']            ?? '',
        'age'            => $age,
        'household'      => $hh ? array_merge(
            array_map('intval', array_intersect_key($hh, array_flip(demo_field_keys()))),
            ['total_members' => (int)$hh['total_members']]
        ) : demo_defaults(),
    ]);
    exit;
}

// ── POST: save profile + household ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save') {

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        echo json_encode(['ok' => false, 'error' => 'Invalid input.']);
        exit;
    }

   // ── Personal fields ──────────────────────────────────────────
// The profile modal collects first/middle/last/suffix separately,
// so read those directly instead of a single full_name field.
$firstName     = trim($input['first_name']     ?? '');
$middleName    = trim($input['middle_name']    ?? '');
$lastName      = trim($input['last_name']      ?? '');
$suffix        = trim($input['suffix']         ?? '');
$contactNumber = trim($input['contact_number'] ?? '');
$birthdayRaw   = trim($input['birthday']       ?? '');
$sex           = trim($input['sex']            ?? '');

// Validate name
if (mb_strlen($firstName) < 1 || mb_strlen($lastName) < 1) {
    echo json_encode(['ok' => false, 'error' => 'Mangyaring ilagay ang iyong buong pangalan.']);
    exit;
}

// Build full_name from the individual parts, so it stays in
// sync with first/middle/last/suffix instead of drifting.
$fullName = trim(implode(' ', array_filter([$firstName, $middleName, $lastName, $suffix])));

    // Validate contact number — allow empty or Philippine formats
    if ($contactNumber !== '' && !preg_match('/^(\+63|0)[0-9]{9,10}$/', $contactNumber)) {
        echo json_encode(['ok' => false, 'error' => 'Ang contact number ay dapat nasa format na 09XXXXXXXXX o +639XXXXXXXXX.']);
        exit;
    }

    // Validate birthday — must be a real date and not in the future
    $birthdaySQL = null;
    if ($birthdayRaw !== '') {
        $parsed = DateTime::createFromFormat('Y-m-d', $birthdayRaw);
        if (!$parsed || $parsed->format('Y-m-d') !== $birthdayRaw) {
            echo json_encode(['ok' => false, 'error' => 'Hindi wastong format ng petsa ng kaarawan.']);
            exit;
        }
        if ($parsed > new DateTime()) {
            echo json_encode(['ok' => false, 'error' => 'Hindi maaaring hinaharap ang petsa ng kaarawan.']);
            exit;
        }
        // Must be at least 1 year old (basic sanity check)
        $age = (int)(new DateTime())->diff($parsed)->y;
        if ($age > 120) {
            echo json_encode(['ok' => false, 'error' => 'Ang naibigay na petsa ng kaarawan ay mukhang hindi tama.']);
            exit;
        }
        $birthdaySQL = $parsed->format('Y-m-d');
    }

    // Validate sex
    $allowedSex = ['male', 'female', 'prefer_not_to_say', ''];
    if (!in_array($sex, $allowedSex, true)) {
        echo json_encode(['ok' => false, 'error' => 'Hindi wastong halaga ng kasarian.']);
        exit;
    }

    $household = [];
    foreach (demo_field_keys() as $key) {
        $household[$key] = $key === 'adults'
            ? max(1, (int)($input[$key] ?? 1))
            : max(0, (int)($input[$key] ?? 0));
    }
    $total = demo_sum_row($household);

    if ($total < 1) {
        echo json_encode(['ok' => false, 'error' => 'Ang sambahayan ay dapat may hindi bababa sa 1 miyembro.']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Update users table — including first_name/last_name so
        // they stay in sync with full_name, plus birthday and sex
        $stmt = $pdo->prepare("
            UPDATE users
               SET full_name      = :name,
                   first_name     = :first_name,
                   last_name      = :last_name,
                   contact_number = :contact,
                   birthday       = :birthday,
                   sex            = :sex,
                   updated_at     = NOW()
             WHERE id = :uid
        ");
        $stmt->execute([
            ':name'        => $fullName,
            ':first_name'  => $firstName,
            ':last_name'   => $lastName,
            ':contact'     => $contactNumber ?: null,
            ':birthday'    => $birthdaySQL,
            ':sex'         => $sex ?: null,
            ':uid'         => $user['id'],
        ]);

        $cols = demo_field_keys();
        $colList = implode(', ', $cols);
        $placeholders = implode(', ', array_map(fn($c) => ':' . $c, $cols));
        $updates = implode(', ', array_map(fn($c) => "$c = VALUES($c)", $cols));

        $hhStmt = $pdo->prepare("
            INSERT INTO family_profiles
                (user_id, $colList, total_members)
            VALUES (:uid, $placeholders, :total)
            ON DUPLICATE KEY UPDATE
                $updates,
                total_members = VALUES(total_members),
                updated_at    = NOW()
        ");
        $params = [':uid' => $user['id'], ':total' => $total];
        foreach ($household as $key => $val) {
            $params[':' . $key] = $val;
        }
        $hhStmt->execute($params);

        // Also update any active evacuation_intention so coordinator
        // sees updated household count immediately
        $pdo->prepare("
            UPDATE evacuation_intentions
               SET household_size = ?,
                   updated_at     = NOW()
             WHERE user_id = ? AND status = 'going'
        ")->execute([$total, $user['id']]);

        $pdo->commit();

        // Compute age for response
        $ageResp = null;
        if ($birthdaySQL) {
            $ageResp = (int)(new DateTime())->diff(new DateTime($birthdaySQL))->y;
        }

        echo json_encode([
            'ok'            => true,
            'total_members' => $total,
            'age'           => $ageResp,
            'message'       => 'Na-save ang profile.',
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('citizen_profile_action error: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'May error sa database. Subukan ulit.']);
    }
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Hindi wastong aksyon.']);