<?php
/**
 * Pure synthetic cross-view partition inventory comparison.
 * Both views are caller-supplied; equality is not proof of kernel completeness.
 * No filesystem reads or authorization.
 *
 * View format: array of array('name' => string, 'dev' => 'major:minor',
 *                             'partition_number' => positive integer).
 */
function edm_synthetic_partition_views_agree($left, $right)
{
    $normalize = function ($view) {
        if (!is_array($view) || count($view) > 256
            || array_keys($view) !== range(0, count($view) - 1) && count($view) !== 0) return false;
        $byDev = array();
        $names = array();
        $numbers = array();
        foreach ($view as $entry) {
            if (!is_array($entry)
                || array_keys($entry) !== array('name', 'dev', 'partition_number'))
                return false;
            $name = $entry['name'];
            $dev = $entry['dev'];
            $number = $entry['partition_number'];
            if (!is_string($name) || !preg_match('/^[a-zA-Z0-9._+-]+$/D', $name)
                || !is_string($dev) || !preg_match('/^(0|[1-9][0-9]*):(0|[1-9][0-9]*)$/D', $dev)
                || !is_int($number) || $number < 1
                || isset($byDev[$dev]) || isset($names[$name]) || isset($numbers[$number]))
                return false;
            $byDev[$dev] = $name . ':' . $number;
            $names[$name] = true;
            $numbers[$number] = true;
        }
        ksort($byDev, SORT_STRING);
        return $byDev;
    };
    $a = $normalize($left);
    $b = $normalize($right);
    return $a !== false && $b !== false && $a === $b;
}
