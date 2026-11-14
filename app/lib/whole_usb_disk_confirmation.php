<?php
/**
 * Validate the two explicit acknowledgements required before a whole-disk
 * erase. This is only one prerequisite: it does not authorize or execute
 * encryption, and the server must still verify the selected disk afresh.
 */
function edm_whole_disk_erase_confirmed($warningAccepted, $finalConfirmation)
{
    return $warningAccepted === true
        && $finalConfirmation === 'ERASE ALL DATA';
}
