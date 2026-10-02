<?php
/**
 * Citizen + family profile completion checks and navigation guards.
 */

require_once __DIR__ . '/family_member_helpers.php';

function pc_citizen_required_fields(): array
{
    return ['first_name', 'last_name', 'contact_number', 'birthday', 'sex'];
}

function pc_is_citizen_profile_complete(?array $user): bool
{
    if (!$user) {
        return false;
    }
    foreach (pc_citizen_required_fields() as $field) {
        if (empty($user[$field]) || trim((string)$user[$field]) === '') {
            return false;
        }
    }
    return true;
}

function pc_is_family_profile_complete(PDO $pdo, int $userId): bool
{
    $user = fm_fetch_user($pdo, $userId);
    if (!pc_is_citizen_profile_complete($user)) {
        return false;
    }

    if (fm_lives_alone_confirmed($pdo, $userId)) {
        return true;
    }

    return fm_member_count($pdo, $userId) >= 1;
}

function pc_navigation_allowed(PDO $pdo, int $userId): bool
{
    return pc_is_citizen_profile_complete(fm_fetch_user($pdo, $userId))
        && pc_is_family_profile_complete($pdo, $userId);
}

function pc_get_profile_completion_status(PDO $pdo, int $userId): array
{
    $user = fm_fetch_user($pdo, $userId);
    $citizenMissing = [];
    foreach (pc_citizen_required_fields() as $field) {
        if (!$user || empty($user[$field]) || trim((string)$user[$field]) === '') {
            $citizenMissing[] = $field;
        }
    }

    $citizenComplete = empty($citizenMissing);
    $livesAlone = fm_lives_alone_confirmed($pdo, $userId);
    $memberCount = fm_member_count($pdo, $userId);

    $familyMissing = [];
    if (!$citizenComplete) {
        $familyMissing[] = 'Kumpletuhin muna ang citizen profile.';
    } elseif (!$livesAlone && $memberCount < 1) {
        $familyMissing[] = 'Magrehistro ng mga miyembro ng sambahayan o kumpirmahin na kayo lang ang nakatira.';
    }

    $familyComplete = $citizenComplete && ($livesAlone || $memberCount >= 1);

    return [
        'citizen_profile' => [
            'complete' => $citizenComplete,
            'missing'  => $citizenMissing,
        ],
        'family_profile' => [
            'complete'              => $familyComplete,
            'missing'               => $familyMissing,
            'lives_alone_confirmed' => $livesAlone,
            'member_count'          => $memberCount,
        ],
        'navigation_allowed' => $citizenComplete && $familyComplete,
    ];
}

function pc_profile_block_message(PDO $pdo, int $userId): string
{
    $status = pc_get_profile_completion_status($pdo, $userId);
    if ($status['navigation_allowed']) {
        return '';
    }

    $parts = [];
    if (!$status['citizen_profile']['complete']) {
        $parts[] = 'Kumpletuhin ang iyong personal na profile (pangalan, contact, kaarawan, kasarian).';
    }
    if ($status['citizen_profile']['complete'] && !$status['family_profile']['complete']) {
        $parts[] = 'Magrehistro ng mga miyembro ng sambahayan o kumpirmahin na kayo lang ang nakatira bago lumikas.';
    }

    return implode(' ', $parts);
}

/**
 * @param 'redirect'|'json' $mode
 */
function pc_require_complete_profile_for_navigation(PDO $pdo, int $userId, string $mode = 'redirect'): void
{
    if (pc_navigation_allowed($pdo, $userId)) {
        return;
    }

    $message = pc_profile_block_message($pdo, $userId);

    if ($mode === 'json') {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'             => false,
            'error'          => $message,
            'profile_status' => pc_get_profile_completion_status($pdo, $userId),
        ]);
        exit;
    }

    header('Location: ' . app_url('pages/citizen_dashboard.php?profile_incomplete=1'));
    exit;
}
