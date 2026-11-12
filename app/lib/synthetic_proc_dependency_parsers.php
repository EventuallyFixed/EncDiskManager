<?php
/**
 * Strict parser for caller-supplied synthetic /proc/self/mountinfo text.
 * No live /proc reads, completeness claims, or operation authorization.
 * Returns records or false on malformed input.
 */
function edm_synthetic_mountinfo_parse($text)
{
    if (!is_string($text) || strlen($text) > 1048576) return false;
    if ($text === '') return array();
    if (substr($text, -1) !== "\n") return false;
    $lines = explode("\n", substr($text, 0, -1));
    if (count($lines) > 4096) return false;
    $records = array();
    $ids = array();
    foreach ($lines as $line) {
        if ($line === '' || substr_count($line, ' - ') !== 1) return false;
        $halves = explode(' - ', $line);
        $pre = preg_split('/ +/', $halves[0]);
        $post = preg_split('/ +/', $halves[1]);
        if (count($pre) < 6 || count($post) !== 3) return false;
        if (!preg_match('/^[1-9][0-9]*$/D', $pre[0])
            || !preg_match('/^(0|[1-9][0-9]*)$/D', $pre[1])
            || !preg_match('/^(0|[1-9][0-9]*):(0|[1-9][0-9]*)$/D', $pre[2])
            || isset($ids[$pre[0]])) return false;
        foreach (array_merge($pre, $post) as $field) {
            if ($field === '' || preg_match('/[\x00-\x20\x7f]/', $field)) return false;
            // Kernel mountinfo uses octal escapes for whitespace and backslash.
            if (preg_match('/\\\\(?!0(?:40|11|12|134))/', $field)) return false;
        }
        $ids[$pre[0]] = true;
        $records[] = array('mount_id' => $pre[0], 'parent_id' => $pre[1],
            'dev' => $pre[2], 'root' => $pre[3], 'mountpoint' => $pre[4],
            'filesystem' => $post[0], 'source' => $post[1]);
    }
    return $records;
}

/**
 * Strict parser for caller-supplied synthetic /proc/swaps text.
 * Returns records or false. Paths are diagnostic, not device identity.
 */
function edm_synthetic_swaps_parse($text)
{
    if (!is_string($text) || strlen($text) > 1048576
        || $text === '' || substr($text, -1) !== "\n") return false;
    $lines = explode("\n", substr($text, 0, -1));
    if (count($lines) > 4097
        || !preg_match('/^Filename\s+Type\s+Size\s+Used\s+Priority$/D', $lines[0]))
        return false;
    $records = array();
    $seen = array();
    foreach (array_slice($lines, 1) as $line) {
        $fields = preg_split('/\s+/', trim($line));
        if (count($fields) !== 5 || $fields[0] === ''
            || $fields[0][0] !== '/'
            || !in_array($fields[1], array('partition', 'file'), true)
            || !preg_match('/^(0|[1-9][0-9]*)$/D', $fields[2])
            || !preg_match('/^(0|[1-9][0-9]*)$/D', $fields[3])
            || !preg_match('/^-?(0|[1-9][0-9]*)$/D', $fields[4])
            || isset($seen[$fields[0]])) return false;
        $seen[$fields[0]] = true;
        $records[] = array('path' => $fields[0], 'type' => $fields[1],
            'size_kib' => $fields[2], 'used_kib' => $fields[3],
            'priority' => $fields[4]);
    }
    return $records;
}
