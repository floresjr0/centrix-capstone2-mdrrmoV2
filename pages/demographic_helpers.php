<?php

const DEMO_FIELDS = [
    'adults'            => 'Adults',
    'children'          => 'Children',
    'seniors'           => 'Senior Citizens',
    'pwds'              => 'PWD',
    'pregnant_women'    => 'Pregnant Women',
    'lactating_mothers' => 'Lactating / Breastfeeding Mothers',
    'infants_toddlers'  => 'Infants / Toddlers',
];

const DEMO_SHORT = [
    'adults'            => 'A',
    'children'          => 'C',
    'seniors'           => 'S',
    'pwds'              => 'P',
    'pregnant_women'    => 'PW',
    'lactating_mothers' => 'LM',
    'infants_toddlers'  => 'IT',
];

function demo_field_keys(): array
{
    return array_keys(DEMO_FIELDS);
}

function demo_sum_row(array $row): int
{
    $sum = 0;
    foreach (demo_field_keys() as $key) {
        $sum += (int)($row[$key] ?? 0);
    }
    return $sum;
}

function demo_sql_coalesce_sum(string $columnPrefix): string
{
    $parts = [];
    foreach (demo_field_keys() as $key) {
        $parts[] = "COALESCE(SUM({$columnPrefix}.{$key}), 0) AS total_{$key}";
    }
    return implode(",\n        ", $parts);
}

function demo_defaults(int $adults = 1): array
{
    $row = ['adults' => $adults];
    foreach (demo_field_keys() as $key) {
        if ($key !== 'adults') {
            $row[$key] = 0;
        }
    }
    $row['total_members'] = demo_sum_row($row);
    return $row;
}

function demo_from_request(array $input): array
{
    $row = [];
    foreach (demo_field_keys() as $key) {
        $row[$key] = max(0, (int)($input[$key] ?? 0));
    }
    return $row;
}
