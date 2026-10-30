<?php
/**
 * Compare two pricing snapshots from dev/pricing-snapshot.php. Exits 1 on any difference.
 *
 *   php dev/pricing-compare.php /tmp/pricing-1.0.6.json /tmp/pricing-1.1.0.json
 *
 * Checks: stored amounts of every registration; every price quote; and that a registration
 * submitted with six named students stores exactly the amounts of one without students.
 * Payment option/method/status of submissions are not compared across 1.0.6, which stored
 * them as 0.000000 (fixed in 1.0.7).
 */

if ($argc < 3) {
    fwrite(STDERR, "usage: php pricing-compare.php before.json after.json\n");
    exit(2);
}
$a = json_decode(file_get_contents($argv[1]), true);
$b = json_decode(file_get_contents($argv[2]), true);
$fail = 0;
$check = function ($label, $x, $y) use (&$fail) {
    if ($x !== $y) {
        $fail++;
        echo "DIFF  $label\n  before: " . json_encode($x) . "\n  after:  " . json_encode($y) . "\n";
    }
};

echo "Before: plugin {$a['plugin_version']}   After: plugin {$b['plugin_version']}\n";
$check('registration ids', array_keys($a['registrations']), array_keys($b['registrations']));
foreach ($a['registrations'] as $id => $row) {
    $check("registration $id", $row, isset($b['registrations'][$id]) ? $b['registrations'][$id] : null);
}
$check('quote keys', array_keys($a['quotes']), array_keys($b['quotes']));
foreach ($a['quotes'] as $k => $q) {
    $check("quote $k", $q, isset($b['quotes'][$k]) ? $b['quotes'][$k] : null);
}
$amounts = array('total_amount', 'deposit_amount', 'amount_paid', 'balance_due', 'competing_students', 'additional_students', 'additional_adults', 'coaches');
$pick = function ($s) use ($amounts) {
    return array_intersect_key($s['stored'], array_flip($amounts));
};
$check('submission (no students) accepted before', true, $a['submissions']['no_students']['response_success']);
$check('submission (no students) accepted after', true, $b['submissions']['no_students']['response_success']);
$check('submission without students: amounts before vs after', $pick($a['submissions']['no_students']), $pick($b['submissions']['no_students']));
if (isset($b['submissions']['six_students'])) {
    $check('submission with six students accepted', true, $b['submissions']['six_students']['response_success']);
    $check('submission with six students vs without (after): all stored payment fields', $b['submissions']['no_students']['stored'], $b['submissions']['six_students']['stored']);
}
echo $fail ? "FAILED: $fail difference(s)\n" : 'PASS: ' . count($a['registrations']) . ' registrations, ' . count($a['quotes']) . " quotes and the submitted registrations price identically.\n";
exit($fail ? 1 : 0);
