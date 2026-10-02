<?php

// pages/citizen_profile_action.php

// GET  ?action=get                  → profile + members + derived household + status

// POST ?action=save                → save citizen personal fields

// POST ?action=save_member         → add/update one family_members row

// POST ?action=delete_member       → remove family_members row

// POST ?action=confirm_lives_alone → confirm solo household



require_once __DIR__ . '/session.php';

require_once __DIR__ . '/db.php';

require_once __DIR__ . '/family_member_helpers.php';
require_once __DIR__ . '/profile_completion_helpers.php';

require_login();



header('Content-Type: application/json; charset=utf-8');

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

header('Pragma: no-cache');



$user   = current_user();

$pdo    = db();

$action = $_GET['action'] ?? '';



if ($action === 'get') {

    echo json_encode(fm_build_profile_payload($pdo, (int)$user['id']));

    exit;

}



if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    echo json_encode(['ok' => false, 'error' => 'Hindi wastong aksyon.']);

    exit;

}



$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {

    echo json_encode(['ok' => false, 'error' => 'Invalid input.']);

    exit;

}



$userId = (int)$user['id'];



if ($action === 'save_member') {

    $result = fm_save_member($pdo, $userId, $input);

    if ($result['ok']) {

        $result['profile_status'] = pc_get_profile_completion_status($pdo, $userId);

    }

    echo json_encode($result);

    exit;

}



if ($action === 'delete_member') {

    $memberId = (int)($input['id'] ?? 0);

    if ($memberId <= 0) {

        echo json_encode(['ok' => false, 'error' => 'Invalid member id.']);

        exit;

    }

    $result = fm_delete_member($pdo, $userId, $memberId);

    if ($result['ok']) {

        $result['profile_status'] = pc_get_profile_completion_status($pdo, $userId);

        $result['members'] = fm_list_members($pdo, $userId);

        $result['lives_alone_confirmed'] = fm_lives_alone_confirmed($pdo, $userId);

    }

    echo json_encode($result);

    exit;

}



if ($action === 'confirm_lives_alone') {

    require_once __DIR__ . '/profile_completion_helpers.php';

    if (!pc_is_citizen_profile_complete(fm_fetch_user($pdo, $userId))) {

        echo json_encode(['ok' => false, 'error' => 'Kumpletuhin muna ang iyong personal na profile.']);

        exit;

    }

    $result = fm_confirm_lives_alone($pdo, $userId);

    if ($result['ok']) {

        $result['profile_status'] = pc_get_profile_completion_status($pdo, $userId);

        $result['members'] = [];

        $result['lives_alone_confirmed'] = true;

    }

    echo json_encode($result);

    exit;

}



if ($action === 'save') {

    $firstName     = trim($input['first_name']     ?? '');

    $middleName    = trim($input['middle_name']    ?? '');

    $lastName      = trim($input['last_name']      ?? '');

    $suffix        = trim($input['suffix']         ?? '');

    $contactNumber = trim($input['contact_number'] ?? '');

    $birthdayRaw   = trim($input['birthday']       ?? '');

    $sex           = trim($input['sex']            ?? '');



    if (mb_strlen($firstName) < 1 || mb_strlen($lastName) < 1) {

        echo json_encode(['ok' => false, 'error' => 'Mangyaring ilagay ang iyong buong pangalan.']);

        exit;

    }



    $fullName = trim(implode(' ', array_filter([$firstName, $middleName, $lastName, $suffix])));



    if ($contactNumber !== '' && !preg_match('/^(\+63|0)[0-9]{9,10}$/', $contactNumber)) {

        echo json_encode(['ok' => false, 'error' => 'Ang contact number ay dapat nasa format na 09XXXXXXXXX o +639XXXXXXXXX.']);

        exit;

    }



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

        $age = (int)(new DateTime())->diff($parsed)->y;

        if ($age > 120) {

            echo json_encode(['ok' => false, 'error' => 'Ang naibigay na petsa ng kaarawan ay mukhang hindi tama.']);

            exit;

        }

        $birthdaySQL = $parsed->format('Y-m-d');

    }



    $allowedSex = ['male', 'female', 'prefer_not_to_say', ''];

    if (!in_array($sex, $allowedSex, true)) {

        echo json_encode(['ok' => false, 'error' => 'Hindi wastong halaga ng kasarian.']);

        exit;

    }



    try {

        $pdo->beginTransaction();



        $stmt = $pdo->prepare("

            UPDATE users

               SET full_name      = :name,

                   first_name     = :first_name,

                   last_name      = :last_name,

                   middle_name    = :middle_name,

                   suffix         = :suffix,

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

            ':middle_name' => $middleName ?: null,

            ':suffix'      => $suffix ?: null,

            ':contact'     => $contactNumber ?: null,

            ':birthday'    => $birthdaySQL,

            ':sex'         => $sex ?: null,

            ':uid'         => $userId,

        ]);



        $totals = fm_sync_family_profiles($pdo, $userId);

        fm_mark_household_setup_if_complete($pdo, $userId);



        $pdo->commit();



        require_once __DIR__ . '/profile_completion_helpers.php';



        $ageResp = null;

        if ($birthdaySQL) {

            $ageResp = (int)(new DateTime())->diff(new DateTime($birthdaySQL))->y;

        }



        $headCategory = fm_primary_category_from_birthday($birthdaySQL);



        echo json_encode([

            'ok'                    => true,

            'message'               => 'Na-save ang profile.',

            'age'                   => $ageResp,

            'head_primary_category' => $headCategory,

            'head_primary_label'    => $headCategory ? fm_category_label($headCategory) : '',

            'household'             => $totals,

            'profile_status'        => pc_get_profile_completion_status($pdo, $userId),

        ]);

    } catch (Exception $e) {

        if ($pdo->inTransaction()) {

            $pdo->rollBack();

        }

        error_log('citizen_profile_action save error: ' . $e->getMessage());

        echo json_encode(['ok' => false, 'error' => 'May error sa database. Subukan ulit.']);

    }

    exit;

}



echo json_encode(['ok' => false, 'error' => 'Hindi wastong aksyon.']);

