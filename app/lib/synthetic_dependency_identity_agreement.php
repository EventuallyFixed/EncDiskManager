<?php
/**
 * Pure synthetic dependency identity agreement.
 * No kernel reads, no pathname resolution, no authorization.
 *
 * Each caller-supplied source is a list of canonical major:minor strings.
 * The two sources must agree exactly, with no duplicates or malformed entries.
 * Empty agreement is not evidence that live discovery was complete.
 */
function edm_synthetic_dependency_identity_agrees($first, $second)
{
    $normalize = function ($items) {
        if (!is_array($items) || array_keys($items) !== (count($items) === 0 ? array() : range(0, count($items) - 1))
            || count($items) > 256) return false;
        $seen = array();
        foreach ($items as $item) {
            if (!is_string($item)
                || !preg_match('/^(0|[1-9][0-9]*):(0|[1-9][0-9]*)$/D', $item)
                || isset($seen[$item])) return false;
            $seen[$item] = true;
        }
        ksort($seen, SORT_STRING);
        return array_keys($seen);
    };
    $a = $normalize($first);
    $b = $normalize($second);
    return $a !== false && $b !== false && $a === $b;
}
