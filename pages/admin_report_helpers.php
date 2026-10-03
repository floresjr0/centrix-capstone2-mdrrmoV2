<?php
/**
 * Admin evacuee reports — load/format household member names for print & UI.
 */

require_once __DIR__ . '/demographic_helpers.php';
require_once __DIR__ . '/family_member_helpers.php';

function ar_column_exists(PDO $pdo, string $table, string $column): bool
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

function ar_table_exists(PDO $pdo, string $table): bool
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

function ar_calculate_age(?string $birthday): ?int
{
    if (empty($birthday)) {
        return null;
    }
    try {
        return (int)(new DateTime())->diff(new DateTime($birthday))->y;
    } catch (Exception $e) {
        return null;
    }
}

function ar_format_sex(?string $sex): string
{
    return match ($sex) {
        'male'              => 'Male',
        'female'            => 'Female',
        'prefer_not_to_say' => 'Prefer not to say',
        default             => '—',
    };
}

function ar_format_member_row(array $row): array
{
    return [
        'full_name'         => $row['full_name'] ?? '',
        'is_household_head' => !empty($row['is_household_head']),
        'sex'               => $row['sex'] ?? '',
        'sex_label'         => ar_format_sex($row['sex'] ?? null),
        'birthday'          => $row['birthday'] ?? '',
        'age'               => ar_calculate_age($row['birthday'] ?? null),
        'primary_category'  => $row['primary_category'] ?? 'adults',
        'primary_label'     => fm_category_label($row['primary_category'] ?? 'adults'),
        'is_pwd'            => !empty($row['is_pwd']),
        'is_pregnant'       => !empty($row['is_pregnant']),
        'is_lactating'      => !empty($row['is_lactating']),
    ];
}

function ar_member_flag_text(array $m): string
{
    $flags = [];
    if (!empty($m['is_household_head'])) {
        $flags[] = 'Head';
    }
    if (!empty($m['is_pwd'])) {
        $flags[] = 'PWD';
    }
    if (!empty($m['is_pregnant'])) {
        $flags[] = 'Pregnant';
    }
    if (!empty($m['is_lactating'])) {
        $flags[] = 'Lactating';
    }
    return implode(', ', $flags);
}

function ar_bulk_live_members(PDO $pdo, array $registrationIds): array
{
    if (!$registrationIds || !ar_table_exists($pdo, 'evac_registration_members')) {
        return [];
    }

    $ids = array_values(array_unique(array_map('intval', $registrationIds)));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $pdo->prepare(
        "SELECT * FROM evac_registration_members
          WHERE registration_id IN ($placeholders) AND is_present = 1
          ORDER BY is_household_head DESC, full_name ASC"
    );
    $stmt->execute($ids);

    $grouped = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rid = (int)$row['registration_id'];
        $grouped[$rid][] = ar_format_member_row($row);
    }
    return $grouped;
}

/**
 * @return array<int, array> keyed by evac_registrations_archive.id
 */
function ar_bulk_archive_members(PDO $pdo, string $archiveLabel, array $archiveRegistrationIds): array
{
    if (!$archiveRegistrationIds || !ar_table_exists($pdo, 'evac_registration_members_archive')) {
        return [];
    }

    $ids = array_values(array_unique(array_map('intval', $archiveRegistrationIds)));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $pdo->prepare(
        "SELECT m.* FROM evac_registration_members_archive m
          INNER JOIN evac_registrations_archive era ON era.id = m.archive_registration_id
          WHERE era.archive_label = ? AND m.archive_registration_id IN ($placeholders)
          ORDER BY m.is_household_head DESC, m.full_name ASC"
    );
    $stmt->execute(array_merge([$archiveLabel], $ids));

    $grouped = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $aid = (int)$row['archive_registration_id'];
        $grouped[$aid][] = ar_format_member_row($row);
    }
    return $grouped;
}

function ar_registration_context(array $reg): array
{
    return [
        'family_head_name'      => $reg['family_head_name'] ?? '',
        'barangay_name'         => $reg['barangay_name'] ?? '',
        'contact_number'        => $reg['contact_number'] ?? '',
        'center_name'           => $reg['center_name'] ?? '',
        'registration_source'   => ar_registration_source($reg),
        'registration_source_label' => ar_registration_source_label(ar_registration_source($reg)),
    ];
}

/** App/citizen registration vs coordinator walk-in. */
function ar_registration_source(array $reg): string
{
    if (!empty($reg['source_user_id'])) {
        return 'app';
    }
    return 'walkin';
}

function ar_registration_source_label(string $source): string
{
    return $source === 'app' ? 'App / Citizen' : 'Walk-in';
}

function ar_registration_source_badge(string $source): string
{
    if ($source === 'app') {
        return '<span class="reg-source-badge reg-source-app">App</span>';
    }
    return '<span class="reg-source-badge reg-source-walkin">Walk-in</span>';
}

/**
 * SQL fragment: registration_source column alias.
 */
function ar_sql_registration_source(PDO $pdo, string $regAlias = 'er'): string
{
    if (ar_column_exists($pdo, 'evac_registrations', 'source_user_id')) {
        return "CASE WHEN {$regAlias}.source_user_id IS NOT NULL THEN 'app' ELSE 'walkin' END AS registration_source";
    }
    return "'walkin' AS registration_source";
}

/**
 * Members for display when only aggregate data exists (head name only).
 */
function ar_fallback_head_member(string $headName, ?string $birthday = null, ?string $sex = null): array
{
    return [[
        'full_name'         => $headName,
        'is_household_head' => true,
        'sex'               => $sex ?? '',
        'sex_label'         => ar_format_sex($sex),
        'birthday'          => $birthday ?? '',
        'age'               => ar_calculate_age($birthday),
        'primary_category'  => 'adults',
        'primary_label'     => 'Adult',
        'is_pwd'            => false,
        'is_pregnant'       => false,
        'is_lactating'      => false,
        '_aggregate_only'   => true,
    ]];
}

function ar_profile_member_from_roster_person(array $person): array
{
    $row = ar_format_member_row([
        'full_name'         => $person['full_name'] ?? '',
        'is_household_head' => !empty($person['is_head']),
        'sex'               => $person['sex'] ?? '',
        'birthday'          => $person['birthday'] ?? '',
        'primary_category'  => $person['primary_category'] ?? 'adults',
        'is_pwd'            => !empty($person['is_pwd']),
        'is_pregnant'       => !empty($person['is_pregnant']),
        'is_lactating'      => !empty($person['is_lactating']),
    ]);
    $row['_from_profile'] = true;
    return $row;
}

/**
 * Load household profile (users head + family_members) for one registration.
 *
 * @return list<array<string,mixed>>
 */
function ar_profile_members_for_registration(PDO $pdo, array $reg): array
{
    if (!ar_table_exists($pdo, 'family_members')) {
        return [];
    }

    $userId = ar_resolve_user_id_for_registration($pdo, $reg);
    if (!$userId) {
        return [];
    }

    $roster = fm_build_household_roster($pdo, $userId);
    if (!$roster) {
        return [];
    }

    $rows = [];
    foreach ($roster as $person) {
        $rows[] = ar_profile_member_from_roster_person($person);
    }

    return ar_dedupe_member_rows($rows);
}

/**
 * Resolve citizen user id from registration (app link or name/contact match).
 */
function ar_resolve_user_id_for_registration(PDO $pdo, array $reg): ?int
{
    if (!empty($reg['source_user_id'])) {
        return (int)$reg['source_user_id'];
    }

    $name = trim((string)($reg['family_head_name'] ?? ''));
    if ($name === '') {
        return null;
    }

    $contact = trim((string)($reg['contact_number'] ?? ''));
    $birthday = !empty($reg['birthday']) ? (string)$reg['birthday'] : null;

    $attempts = [];
    if ($contact !== '' && $birthday) {
        $attempts[] = ['sql' => 'role = \'citizen\' AND TRIM(full_name) = ? AND contact_number = ? AND birthday = ?', 'params' => [$name, $contact, $birthday]];
        $attempts[] = ['sql' => 'role = \'citizen\' AND LOWER(TRIM(full_name)) = LOWER(?) AND contact_number = ? AND birthday = ?', 'params' => [$name, $contact, $birthday]];
    }
    if ($contact !== '') {
        $attempts[] = ['sql' => 'role = \'citizen\' AND TRIM(full_name) = ? AND contact_number = ?', 'params' => [$name, $contact]];
        $attempts[] = ['sql' => 'role = \'citizen\' AND LOWER(TRIM(full_name)) = LOWER(?) AND contact_number = ?', 'params' => [$name, $contact]];
    }
    if ($birthday) {
        $attempts[] = ['sql' => 'role = \'citizen\' AND TRIM(full_name) = ? AND birthday = ?', 'params' => [$name, $birthday]];
    }
    $attempts[] = ['sql' => 'role = \'citizen\' AND TRIM(full_name) = ?', 'params' => [$name]];
    $attempts[] = ['sql' => 'role = \'citizen\' AND LOWER(TRIM(full_name)) = LOWER(?)', 'params' => [$name]];

    foreach ($attempts as $attempt) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE ' . $attempt['sql'] . ' ORDER BY id ASC LIMIT 2');
        $stmt->execute($attempt['params']);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) === 1) {
            return (int)$ids[0];
        }
    }

    return null;
}

/**
 * Load present arrival member rows directly for a live registration.
 *
 * @return list<array<string,mixed>>
 */
function ar_fetch_live_arrival_members(PDO $pdo, int $registrationId): array
{
    if ($registrationId <= 0 || !ar_table_exists($pdo, 'evac_registration_members')) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT * FROM evac_registration_members
          WHERE registration_id = ? AND is_present = 1
          ORDER BY is_household_head DESC, full_name ASC'
    );
    $stmt->execute([$registrationId]);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[] = ar_format_member_row($row);
    }
    return $rows;
}

/**
 * Load archived individual member rows for one archive registration.
 *
 * @return list<array<string,mixed>>
 */
function ar_fetch_archive_arrival_members(PDO $pdo, int $archiveRegistrationId): array
{
    if ($archiveRegistrationId <= 0 || !ar_table_exists($pdo, 'evac_registration_members_archive')) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT * FROM evac_registration_members_archive
          WHERE archive_registration_id = ?
          ORDER BY is_household_head DESC, full_name ASC'
    );
    $stmt->execute([$archiveRegistrationId]);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[] = ar_format_member_row($row);
    }
    return $rows;
}

/**
 * Resolve registration id fields from a report row.
 *
 * @return array{live:int,archive:int}
 */
function ar_registration_lookup_ids(array $reg): array
{
    return [
        'live'    => (int)($reg['registration_id'] ?? $reg['id'] ?? 0),
        'archive' => (int)($reg['archive_registration_id'] ?? 0),
    ];
}

/**
 * Household profile members (family head + family_members) keyed by registration id.
 *
 * @param callable(array): int $keyFn
 */
function ar_bulk_profile_members(PDO $pdo, array $registrations, callable $keyFn): array
{
    if (!$registrations) {
        return [];
    }

    $grouped = [];
    foreach ($registrations as $reg) {
        $key = (int)$keyFn($reg);
        if (isset($grouped[$key])) {
            continue;
        }

        $rows = ar_profile_members_for_registration($pdo, $reg);
        if ($rows) {
            $grouped[$key] = $rows;
        }
    }

    return $grouped;
}

/**
 * Remove duplicate rows (same person listed twice by name + head flag).
 *
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function ar_dedupe_member_rows(array $rows): array
{
    $seen = [];
    $deduped = [];

    foreach ($rows as $row) {
        $nameKey = mb_strtolower(trim((string)($row['full_name'] ?? '')));
        if ($nameKey === '') {
            continue;
        }
        $dedupeKey = $nameKey . '|' . (!empty($row['is_household_head']) ? '1' : '0');
        if (isset($seen[$dedupeKey])) {
            continue;
        }
        $seen[$dedupeKey] = true;
        $deduped[] = $row;
    }

    return $deduped;
}

/**
 * Resolve individual evacuee rows for one registration.
 *
 * Priority:
 * 1. evac_registration_members (is_present = 1) — actual arrivals only
 * 2. citizen household profile (users + family_members)
 * 3. legacy aggregate fallback (head only + optional note)
 *
 * @return list<array<string,mixed>>
 */
function ar_resolve_registration_members(
    PDO $pdo,
    array $reg,
    array $arrivalMembers,
    array $profileMembers = []
): array {
    $ids = ar_registration_lookup_ids($reg);

    if (!$arrivalMembers && $ids['live'] > 0) {
        $arrivalMembers = ar_fetch_live_arrival_members($pdo, $ids['live']);
    }
    if (!$arrivalMembers && $ids['archive'] > 0) {
        $arrivalMembers = ar_fetch_archive_arrival_members($pdo, $ids['archive']);
    }

    $arrivalMembers = ar_dedupe_member_rows($arrivalMembers);
    if ($arrivalMembers) {
        return $arrivalMembers;
    }

    // Profile fallback only for app/citizen registrations without stored arrival rows.
    if (ar_registration_source($reg) === 'app' || ar_resolve_user_id_for_registration($pdo, $reg)) {
        if (!$profileMembers) {
            $profileMembers = ar_profile_members_for_registration($pdo, $reg);
        } else {
            $profileMembers = ar_dedupe_member_rows($profileMembers);
        }

        if ($profileMembers) {
            return $profileMembers;
        }
    }

    return ar_legacy_aggregate_members($reg);
}

/**
 * Legacy aggregate-only registrations with no individual member records.
 *
 * @return list<array<string,mixed>>
 */
function ar_legacy_aggregate_members(array $reg): array
{
    $rows = ar_fallback_head_member(
        $reg['family_head_name'] ?? '',
        $reg['birthday'] ?? null,
        $reg['head_sex'] ?? null
    );

    $extra = max(0, (int)($reg['total_members'] ?? 1) - 1);
    if ($extra > 0) {
        $rows[] = [
            'full_name'         => 'Additional household member(s)',
            'is_household_head' => false,
            'sex'               => '',
            'sex_label'         => '—',
            'birthday'          => '',
            'age'               => null,
            'primary_category'  => '',
            'primary_label'     => 'See family counts',
            'is_pwd'            => false,
            'is_pregnant'       => false,
            'is_lactating'      => false,
            '_is_note'          => true,
            '_note_text'        => $extra . ' additional person(s) recorded by count only — no individual names on file',
        ];
    }

    return $rows;
}

function ar_members_for_registration(
    array $reg,
    array $membersByKey,
    int $lookupKey,
    array $profileByKey = [],
    ?PDO $pdo = null
): array {
    $arrivalMembers = $membersByKey[$lookupKey] ?? [];
    $profileMembers = $profileByKey[$lookupKey] ?? [];

    if ($pdo) {
        return ar_resolve_registration_members($pdo, $reg, $arrivalMembers, $profileMembers);
    }

    $arrivalMembers = ar_dedupe_member_rows($arrivalMembers);
    if ($arrivalMembers) {
        return $arrivalMembers;
    }

    $profileMembers = ar_dedupe_member_rows($profileMembers);
    if ($profileMembers && (ar_registration_source($reg) === 'app' || !empty($reg['source_user_id']))) {
        return $profileMembers;
    }

    return ar_legacy_aggregate_members($reg);
}

/**
 * Expand registrations into one row per evacuee (with family context).
 *
 * Priority: actual arrivals → household profile → legacy aggregate note.
 *
 * @param callable(array): int $keyFn
 */
function ar_collect_centre_evacuees(
    array $centreRecs,
    array $membersByKey,
    callable $keyFn,
    array $profileByKey = [],
    ?PDO $pdo = null
): array {
    $evacuees = [];

    foreach ($centreRecs as $reg) {
        $key = (int)$keyFn($reg);
        $ctx = ar_registration_context($reg);
        $members = ar_members_for_registration(
            $reg,
            $membersByKey,
            $key,
            $profileByKey,
            $pdo
        );

        foreach ($members as $member) {
            $evacuees[] = array_merge($member, $ctx);
        }
    }

    return $evacuees;
}

function ar_render_person_meta_line(array $m): string
{
    $flags = ar_member_flag_text($m);
    $meta = [];
    $sexLabel = $m['sex_label'] ?? ar_format_sex($m['sex'] ?? null);
    if ($sexLabel !== '—') {
        $meta[] = htmlspecialchars($sexLabel);
    }
    if (!empty($m['primary_label']) && ($m['primary_label'] ?? '') !== 'See family counts') {
        $meta[] = htmlspecialchars($m['primary_label']);
    }
    if (($m['age'] ?? null) !== null) {
        $meta[] = $m['age'] . ' yrs';
    }
    if ($flags !== '') {
        $meta[] = htmlspecialchars($flags);
    }
    return $meta ? ' <span class="member-meta">' . implode(' · ', $meta) . '</span>' : '';
}

function ar_render_members_html(array $members, bool $compact = false): string
{
    if (empty($members)) {
        return '<span class="member-empty">—</span>';
    }

    $html = '<ul class="member-name-list' . ($compact ? ' member-name-list-compact' : '') . '">';
    foreach ($members as $m) {
        $metaStr = ar_render_person_meta_line($m);
        $agg = !empty($m['_aggregate_only']) ? ' <span class="member-agg-note">(head only)</span>' : '';
        $prof = !empty($m['_from_profile']) ? ' <span class="member-profile-note">(household profile)</span>' : '';
        $html .= '<li><span class="member-name">' . htmlspecialchars($m['full_name']) . '</span>' . $metaStr . $agg . $prof . '</li>';
    }
    $html .= '</ul>';
    return $html;
}

function ar_render_evacuee_list_html(array $evacuees, bool $compact = false): string
{
    if (empty($evacuees)) {
        return '<span class="member-empty">—</span>';
    }

    $html = '<ul class="member-name-list' . ($compact ? ' member-name-list-compact' : '') . '">';
    foreach ($evacuees as $p) {
        if (!empty($p['_is_note'])) {
            $html .= '<li class="member-note">' . htmlspecialchars($p['_note_text'] ?? $p['full_name']) . '</li>';
            continue;
        }
        $metaStr = ar_render_person_meta_line($p);
        $agg = !empty($p['_aggregate_only']) ? ' <span class="member-agg-note">(aggregate)</span>' : '';
        $prof = !empty($p['_from_profile']) ? ' <span class="member-profile-note">(household profile)</span>' : '';
        $html .= '<li><span class="member-name">' . htmlspecialchars($p['full_name']) . '</span>' . $metaStr . $agg . $prof . '</li>';
    }
    $html .= '</ul>';
    return $html;
}

function ar_render_individual_roster_table(array $evacuees): string
{
    if (empty($evacuees)) {
        return '<p class="roster-empty">No evacuee records for this section.</p>';
    }

    ob_start();
    ?>
    <table class="individual-roster-table">
        <thead>
            <tr>
                <th style="width:28px">#</th>
                <th>Full Name</th>
                <th>Sex</th>
                <th>Age</th>
                <th>Birthday</th>
                <th>Category</th>
                <th>Conditions</th>
                <th>Family Head</th>
                <th>Barangay</th>
                <th>Source</th>
                <th>Contact</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $rowNum = 0;
        foreach ($evacuees as $p):
            $isNote = !empty($p['_is_note']);
            if (!$isNote) {
                $rowNum++;
            }
            $bday = !empty($p['birthday']) ? date('M d, Y', strtotime($p['birthday'])) : '—';
            $age = $p['age'] !== null ? (string)$p['age'] : '—';
            $flags = ar_member_flag_text($p);
        ?>
            <tr class="<?php echo $isNote ? 'evacuee-note-row' : ''; ?>">
                <td style="color:var(--muted,#7F8C8D);font-size:10px"><?php echo $isNote ? '' : $rowNum; ?></td>
                <td style="font-weight:<?php echo $isNote ? '500' : '600'; ?>">
                    <?php echo htmlspecialchars($p['full_name']); ?>
                    <?php if (!empty($p['_aggregate_only'])): ?>
                    <span class="member-agg-tag">aggregate</span>
                    <?php endif; ?>
                    <?php if (!empty($p['_from_profile'])): ?>
                    <span class="member-agg-tag" style="background:#E8F5E9;color:#2E7D32">profile</span>
                    <?php endif; ?>
                    <?php if ($isNote && !empty($p['_note_text'])): ?>
                    <br><span style="font-size:10px;color:var(--muted,#7F8C8D);font-weight:400"><?php echo htmlspecialchars($p['_note_text']); ?></span>
                    <?php endif; ?>
                </td>
                <td><?php echo htmlspecialchars($p['sex_label'] ?? ar_format_sex($p['sex'] ?? null)); ?></td>
                <td class="num"><?php echo $age; ?></td>
                <td style="white-space:nowrap;font-size:10.5px"><?php echo $bday; ?></td>
                <td><?php echo htmlspecialchars($p['primary_label'] ?? '—'); ?></td>
                <td style="font-size:10.5px"><?php echo $flags !== '' ? htmlspecialchars($flags) : '—'; ?></td>
                <td><?php echo htmlspecialchars($p['family_head_name'] ?? '—'); ?></td>
                <td><?php echo htmlspecialchars($p['barangay_name'] ?? '—'); ?></td>
                <td style="font-size:10.5px"><?php echo htmlspecialchars($p['registration_source_label'] ?? ar_registration_source_label('walkin')); ?></td>
                <td style="font-size:10.5px"><?php echo htmlspecialchars($p['contact_number'] ?? '—'); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="11" style="font-size:10.5px;text-align:right">
                    <strong><?php echo count(array_filter($evacuees, fn($p) => empty($p['_is_note']))); ?></strong>
                    named evacuee record(s)
                    <?php
                    $notes = array_filter($evacuees, fn($p) => !empty($p['_is_note']));
                    if ($notes) {
                        echo ' · ' . count($notes) . ' count-only note(s)';
                    }
                    ?>
                </td>
            </tr>
        </tfoot>
    </table>
    <?php
    return (string)ob_get_clean();
}
