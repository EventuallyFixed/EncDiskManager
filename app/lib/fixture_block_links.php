<?php
require_once __DIR__ . '/fixture_block_holders.php';
require_once __DIR__ . '/fixture_block_slaves.php';

/**
 * Fixture-only conservative dependency check. Both holder and slave
 * directories must be present, confined and explicitly empty for every
 * supplied synthetic node. No live sysfs reads or disk authorization.
 */
function edm_fixture_block_links_clear($root, $names)
{
    return edm_fixture_block_holders_clear($root, $names)
        && edm_fixture_block_slaves_clear($root, $names);
}
