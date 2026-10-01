<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

$db = db();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'GET only']);
    exit;
}

$stageOrder = $db->query(
    'SELECT `code`, `name`, `stage_order`, `date_role`, `anchors_from_code`, `default_responsible_party`
       FROM `process_stages`
      ORDER BY `stage_order`'
);
$stages = [];
while ($row = $stageOrder->fetch_assoc()) {
    $row['stage_order'] = (int) $row['stage_order'];
    $stages[] = $row;
}

// Holiday dates come from the database rather than a list in the JavaScript.
// They are stored as a recurring MM-DD, so a date that has already passed this
// year still blocks the same day next year.
$holidays = [];
if ($holidayRows = $db->query('SELECT `mmdd` FROM `public_holidays` ORDER BY `mmdd`')) {
    while ($row = $holidayRows->fetch_assoc()) {
        $holidays[] = $row['mmdd'];
    }
}

$types = [];
foreach ($db->query('SELECT `code`, `name` FROM `procurement_types` ORDER BY `sort_order`, `name`') as $row) {
    $types[] = $row;
}

$methods = [];
foreach ($db->query('SELECT `code`, `name` FROM `procurement_methods` ORDER BY `sort_order`, `name`') as $row) {
    $methods[] = $row;
}

// One row per period, with the variants for that period already nested, so the
// frontend gets the whole rule set in a single round trip.
$rows = $db->query(
    'SELECT r.`id`                        AS rule_id,
            t.`code`                      AS procurement_type_code,
            t.`name`                      AS procurement_type,
            m.`code`                      AS method_code,
            m.`name`                      AS method_name,
            s.`code`                      AS stage_code,
            s.`name`                      AS stage_name,
            s.`stage_order`               AS stage_order,
            p.`id`                        AS period_id,
            p.`period_mode`               AS period_mode,
            p.`days`                      AS days,
            p.`note`                      AS note
       FROM `statutory_rules` r
       JOIN `procurement_types`   t ON t.`id` = r.`procurement_type_id`
       JOIN `procurement_methods` m ON m.`id` = r.`procurement_method_id`
       LEFT JOIN `statutory_periods` p ON p.`rule_id` = r.`id`
       LEFT JOIN `process_stages`  s ON s.`id` = p.`stage_id`
      ORDER BY t.`sort_order`, t.`name`, m.`sort_order`, m.`name`, s.`stage_order`'
);

$variantsByPeriod = [];
if ($vr = $db->query(
    'SELECT `period_id`, `variant_code`, `variant_name`, `variant_kind`, `ordinal`, `days`
       FROM `statutory_period_variants`
      ORDER BY `period_id`, `ordinal`, `variant_code`'
)) {
    while ($v = $vr->fetch_assoc()) {
        $v['days']    = (int) $v['days'];
        $v['ordinal'] = (int) $v['ordinal'];
        $variantsByPeriod[$v['period_id']][] = $v;
    }
}

$rules = [];
$index  = [];
while ($row = $rows->fetch_assoc()) {
    $key = $row['procurement_type_code'] . '|' . $row['method_code'];

    if (!isset($index[$key])) {
        $index[$key] = count($rules);
        $rules[] = [
            'procurement_type_code' => $row['procurement_type_code'],
            'procurement_type'      => $row['procurement_type'],
            'method_code'           => $row['method_code'],
            'method'                => $row['method_name'],
            'periods'               => [],
        ];
    }

    // No period row at all means the stage is not part of this process.
    if ($row['period_id'] === null) {
        continue;
    }

    $variants = $variantsByPeriod[$row['period_id']] ?? [];
    $isAlt    = false;
    foreach ($variants as $v) {
        if ($v['variant_kind'] === 'ALTERNATIVE') {
            $isAlt = true;
        }
    }

    $days = $row['days'] === null ? null : (int) $row['days'];
    if ($days === null && $variants) {
        // Sequential variants run one after another and add up. Alternative
        // variants are a choice: only one applies, so the shortest is the
        // default and the longest is offered as the other option.
        $total = 0;
        foreach ($variants as $v) {
            $total += $v['days'];
        }
        $days = $isAlt ? min(array_column($variants, 'days')) : $total;
    }

    $period = [
        'stage_code'   => $row['stage_code'],
        'stage_name'   => $row['stage_name'],
        'stage_order'  => (int) $row['stage_order'],
        'period_mode'  => $row['period_mode'],
        'days'         => $days,
        'note'         => $row['note'],
        'variants'     => $variants,
    ];
    if ($isAlt && $variants) {
        $period['days_max'] = max(array_column($variants, 'days'));
    }
    $rules[$index[$key]]['periods'][] = $period;
}

// A flat lookup keyed the way the old wide table was, for any caller that still
// wants one row per type and method.
$flat = [];
foreach ($rules as $rule) {
    $row = [
        'procurement_type_code' => $rule['procurement_type_code'],
        'procurement_type'      => $rule['procurement_type'],
        'method_code'           => $rule['method_code'],
        'method'                => $rule['method'],
    ];
    foreach ($rule['periods'] as $period) {
        $row[$period['stage_code'] . '_days'] = $period['days'];
        if (isset($period['days_max'])) {
            $row[$period['stage_code'] . '_days_max'] = $period['days_max'];
        }
    }
    $flat[] = $row;
}

echo json_encode([
    'stages'          => $stages,
    'types'           => $types,
    'methods'         => $methods,
    'rules'           => $rules,
    'periods'         => $flat,
    'public_holidays' => $holidays,
]);
