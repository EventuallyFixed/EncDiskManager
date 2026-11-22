<?php
/**
 * Parse kernel device-mapper UUIDs, not filesystem UUIDs.
 *
 * cryptsetup LUKS mappings normally use CRYPT-LUKS1-<32 hex>-<name>
 * or CRYPT-LUKS2-<32 hex>-<name>. A filesystem UUID obtained from
 * blkid on /dev/dm-N is NOT the underlying LUKS container UUID.
 * Unknown or non-crypt mapper identities must not be treated as proof
 * that a registered LUKS device is inactive.
 */
function edm_mapper_luks_uuid($dmUuid)
{
    if (!is_string($dmUuid)) {
        return null;
    }
    $dmUuid = trim($dmUuid);
    if (!preg_match('/^CRYPT-LUKS[12]-([a-f0-9]{32})-.+$/iD', $dmUuid, $matches)) {
        return null;
    }
    $hex = strtolower($matches[1]);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-'
        . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-'
        . substr($hex, 20, 12);
}

/**
 * Assess the identity of all observed device-mapper volumes. This helper
 * does not enumerate the kernel; the caller must independently prove that
 * its enumeration was complete before using the result for authorisation.
 */
function edm_mapper_identities_complete($dmUuids)
{
    if (!is_array($dmUuids)) {
        return false;
    }
    foreach ($dmUuids as $dmUuid) {
        if (edm_mapper_luks_uuid($dmUuid) === null) {
            return false;
        }
    }
    return true;
}
