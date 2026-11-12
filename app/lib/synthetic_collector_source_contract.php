<?php
/**
 * Synthetic-only source provenance contract. No kernel access or authorization.
 * Both independently named source views must be complete, readable and agree
 * on a nonempty canonical kernel object token and collection epoch.
 * Caller-supplied tokens do not prove live provenance or absence of races.
 */
function edm_synthetic_collector_sources_agree($sources)
{
    if (!is_array($sources) || array_keys($sources) !== array('class_block', 'disk_tree'))
        return false;
    $identity = null;
    $epoch = null;
    foreach (array('class_block', 'disk_tree') as $name) {
        $s = $sources[$name];
        if (!is_array($s) || array_keys($s) !== array(
            'readable', 'complete', 'object_token', 'epoch'
        ) || $s['readable'] !== true || $s['complete'] !== true
            || !is_string($s['object_token'])
            || !preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $s['object_token'])
            || !is_string($s['epoch'])
            || !preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $s['epoch']))
            return false;
        if ($identity !== null && $identity !== $s['object_token']) return false;
        if ($epoch !== null && $epoch !== $s['epoch']) return false;
        $identity = $s['object_token'];
        $epoch = $s['epoch'];
    }
    return true;
}
