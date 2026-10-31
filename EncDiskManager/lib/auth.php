<?php
/*
 * EncDiskManager authentication primitives.
 * One local administrator; credentials are kept in the root-only data folder.
 */

function edm_auth_app_path()
{
    $path = realpath(__DIR__ . '/..');
    if ($path === false || !is_dir($path)) {
        throw new RuntimeException('Application path is unavailable.');
    }

    // Verify every path component without following symlinks. WD OS5's
    // application parent is the one known vendor-managed writable exception.
    $components = explode('/', trim($path, '/'));
    $current = '';
    foreach ($components as $component) {
        if ($component === '') {
            continue;
        }
        $current .= '/' . $component;
        $stat = @lstat($current);
        if ($stat === false || (($stat['mode'] & 0170000) !== 0040000)
            || $stat['uid'] !== 0) {
            throw new RuntimeException('Application path components must be real root-owned directories.');
        }
        $permissions = $stat['mode'] & 07777;
        $stickyDirectory = (($permissions & 01000) !== 0);
        $trustedWdParent = ($current === '/mnt/HD/HD_a2/Nas_Prog');
        if (($permissions & 0022) !== 0 && !$stickyDirectory && !$trustedWdParent) {
            throw new RuntimeException('Application path contains a directory writable by group or others.');
        }
    }
    return $path;
}

function edm_auth_data_path()
{
    $appPath = edm_auth_app_path();
    // WD's builder defines APP_PERSISTENT_DATA_PATH as a sibling of APP_PATH:
    // <application parent>/_EncDiskManager. Keeping this outside APP_PATH
    // allows configuration to survive normal app removal and upgrades.
    $dataPath = dirname($appPath) . '/_EncDiskManager';
    $stat = @lstat($dataPath);
    if ($stat === false || (($stat['mode'] & 0170000) !== 0040000)
        || is_link($dataPath) || $stat['uid'] !== 0
        || (($stat['mode'] & 0777) !== 0700)
        || realpath($dataPath) !== $dataPath) {
        throw new RuntimeException('Persistent application data directory is not secure.');
    }
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
        throw new RuntimeException('The web PHP process must run as root to access private application data.');
    }
    return $dataPath;
}

function edm_auth_file_path()
{
    return edm_auth_data_path() . '/admin.json';
}

/*
 * A separate random epoch invalidates every previously issued PHP session
 * after an administrator restore or configuration deletion.
 */
function edm_auth_read_session_epoch()
{
    $dataPath = edm_auth_data_path();
    $path = $dataPath . '/auth-epoch';
    if (is_link($path)) {
        throw new RuntimeException('Authentication session epoch must not be a symbolic link.');
    }
    if (!file_exists($path)) {
        edm_auth_rotate_session_epoch();
    }
    $stat = @lstat($path);
    if ($stat === false || (($stat['mode'] & 0170000) !== 0100000)
        || $stat['uid'] !== 0 || (($stat['mode'] & 0777) !== 0600)) {
        throw new RuntimeException('Authentication session epoch permissions are invalid.');
    }
    $epoch = trim((string)file_get_contents($path));
    if (!preg_match('/^[a-f0-9]{64}$/', $epoch)) {
        throw new RuntimeException('Authentication session epoch is invalid.');
    }
    return $epoch;
}

function edm_auth_rotate_session_epoch()
{
    $dataPath = edm_auth_data_path();
    $epochPath = $dataPath . '/auth-epoch';
    if (is_link($epochPath)) {
        throw new RuntimeException('Authentication session epoch must not be a symbolic link.');
    }
    if (file_exists($epochPath)) {
        $epochStat = @lstat($epochPath);
        if ($epochStat === false || (($epochStat['mode'] & 0170000) !== 0100000)
            || $epochStat['uid'] !== 0 || (($epochStat['mode'] & 0777) !== 0600)) {
            throw new RuntimeException('Existing authentication session epoch is not secure.');
        }
    }
    $lockPath = $dataPath . '/auth-epoch.lock';
    if (is_link($lockPath)) {
        throw new RuntimeException('Authentication epoch lock must not be a symbolic link.');
    }
    $lock = fopen($lockPath, 'c');
    if ($lock === false) {
        throw new RuntimeException('Could not open authentication epoch lock.');
    }

    try {
        if (!chown($lockPath, 0) || !chgrp($lockPath, 0)
            || !chmod($lockPath, 0600) || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Could not secure authentication epoch lock.');
        }
        $temporary = tempnam($dataPath, '.auth-epoch-');
        if ($temporary === false) {
            throw new RuntimeException('Could not create a temporary authentication epoch.');
        }
        try {
            $epoch = bin2hex(random_bytes(32)) . "\n";
            $written = file_put_contents($temporary, $epoch, LOCK_EX);
            if ($written === false || $written !== strlen($epoch)
                || !chown($temporary, 0) || !chgrp($temporary, 0)
                || !chmod($temporary, 0600)
                || !rename($temporary, $dataPath . '/auth-epoch')) {
                throw new RuntimeException('Could not safely rotate the authentication session epoch.');
            }
        } finally {
            if (file_exists($temporary)) {
                @unlink($temporary);
            }
        }
    } finally {
        @flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function edm_auth_read_admin()
{
    $path = edm_auth_file_path();
    if (is_link($path)) {
        throw new RuntimeException('Administrator credential file must not be a symbolic link.');
    }
    if (!file_exists($path)) {
        return null;
    }
    $stat = @lstat($path);
    if ($stat === false || (($stat['mode'] & 0170000) !== 0100000)
        || $stat['uid'] !== 0 || (($stat['mode'] & 0777) !== 0600)) {
        throw new RuntimeException('Administrator credential file permissions are invalid.');
    }
    $raw = file_get_contents($path);
    $data = ($raw === false) ? null : json_decode($raw, true);
    if (!is_array($data) || !isset($data['password_hash'])
        || !is_string($data['password_hash'])
        || $data['password_hash'] === ''
        || !isset($data['created_at']) || !is_int($data['created_at'])) {
        throw new RuntimeException('Administrator credential file is invalid.');
    }
    return $data;
}

function edm_auth_read_setup_token()
{
    $path = edm_auth_data_path() . '/setup-token';
    if (is_link($path)) {
        throw new RuntimeException('Initial setup token must not be a symbolic link.');
    }
    if (!file_exists($path)) {
        return null;
    }
    $stat = @lstat($path);
    if ($stat === false || (($stat['mode'] & 0170000) !== 0100000)
        || $stat['uid'] !== 0 || (($stat['mode'] & 0777) !== 0600)) {
        throw new RuntimeException('Initial setup token permissions are invalid.');
    }
    $token = trim((string)file_get_contents($path));
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        throw new RuntimeException('Initial setup token is invalid.');
    }
    return $token;
}

function edm_auth_consume_setup_token($providedToken)
{
    $expected = edm_auth_read_setup_token();
    if ($expected === null || !is_string($providedToken)
        || !hash_equals($expected, $providedToken)) {
        return false;
    }
    $path = edm_auth_data_path() . '/setup-token';
    // The administrator file is written first and permanently disables
    // setup mode. If unlink fails, the leftover token cannot create another
    // account; init.sh will remove it on the next start.
    if (!@unlink($path)) {
        error_log('EncDiskManager: administrator created but setup-token cleanup failed.');
    }
    return true;
}

function edm_auth_write_admin($password)
{
    $dataPath = edm_auth_data_path();
    $path = $dataPath . '/admin.json';
    $lockPath = $dataPath . '/admin.lock';
    if (is_link($path) || is_link($lockPath)) {
        throw new RuntimeException('Authentication files must not be symbolic links.');
    }

    $lock = fopen($lockPath, 'c');
    if ($lock === false) {
        throw new RuntimeException('Could not open authentication lock.');
    }
    try {
        if (!chown($lockPath, 0) || !chgrp($lockPath, 0) || !chmod($lockPath, 0600)
            || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Could not secure authentication lock.');
        }
        if (file_exists($path)) {
            throw new RuntimeException('Administrator account has already been configured.');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('Password hashing failed.');
        }
        $json = json_encode(array(
            'password_hash' => $hash,
            'created_at' => time()
        ), JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new RuntimeException('Administrator configuration could not be encoded.');
        }

        $temporary = tempnam($dataPath, '.admin-');
        if ($temporary === false) {
            throw new RuntimeException('Could not create temporary credential file.');
        }
        try {
            $written = file_put_contents($temporary, $json . "\n", LOCK_EX);
            if ($written === false || $written !== strlen($json . "\n")
                || !chown($temporary, 0) || !chgrp($temporary, 0)
                || !chmod($temporary, 0600)
                || !rename($temporary, $path)) {
                throw new RuntimeException('Could not safely save administrator credentials.');
            }
        } finally {
            if (file_exists($temporary)) {
                @unlink($temporary);
            }
        }
    } finally {
        @flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function edm_auth_is_https()
{
    return isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== ''
        && strtolower((string)$_SERVER['HTTPS']) !== 'off';
}

function edm_auth_start_session()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.gc_maxlifetime', '28800');

    $https = edm_auth_is_https();
    $script = isset($_SERVER['SCRIPT_NAME']) ? (string)$_SERVER['SCRIPT_NAME'] : '/';
    $cookiePath = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if ($cookiePath === '') {
        $cookiePath = '/';
    }

    session_name('EDMSESSID');
    session_set_cookie_params(array(
        'lifetime' => 0,
        'path' => $cookiePath,
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax'
    ));
    if (!session_start()) {
        throw new RuntimeException('Could not start a secure administrator session.');
    }

    $now = time();
    $idleLimit = 1800;
    $absoluteLimit = 28800;
    if (isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true) {
        $last = isset($_SESSION['last_activity']) ? (int)$_SESSION['last_activity'] : 0;
        $created = isset($_SESSION['created_at']) ? (int)$_SESSION['created_at'] : 0;
        if ($last <= 0 || $created <= 0 || ($now - $last) > $idleLimit
            || ($now - $created) > $absoluteLimit) {
            $_SESSION = array();
            session_regenerate_id(true);
        }
    }
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

function edm_auth_is_authenticated()
{
    if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
        return false;
    }
    $currentEpoch = edm_auth_read_session_epoch();
    $sessionEpoch = isset($_SESSION['auth_epoch']) ? (string)$_SESSION['auth_epoch'] : '';
    if ($sessionEpoch === '' || !hash_equals($currentEpoch, $sessionEpoch)) {
        $_SESSION = array();
        session_regenerate_id(true);
        return false;
    }
    return true;
}

function edm_auth_login($password)
{
    $dataPath = edm_auth_data_path();
    $lockPath = $dataPath . '/admin.lock';
    $attemptsPath = $dataPath . '/login-attempts.json';
    if (is_link($lockPath) || is_link($attemptsPath)) {
        throw new RuntimeException('Login rate-limit files must not be symbolic links.');
    }

    $lock = fopen($lockPath, 'c');
    if ($lock === false) {
        throw new RuntimeException('Could not open login rate-limit lock.');
    }

    $valid = false;
    try {
        if (!chown($lockPath, 0) || !chgrp($lockPath, 0) || !chmod($lockPath, 0600)
            || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Could not secure login rate-limit lock.');
        }

        $attemptsByAddress = array();
        if (file_exists($attemptsPath)) {
            $stat = @lstat($attemptsPath);
            if ($stat === false || (($stat['mode'] & 0170000) !== 0100000)
                || $stat['uid'] !== 0 || (($stat['mode'] & 0777) !== 0600)) {
                throw new RuntimeException('Login rate-limit file permissions are invalid.');
            }
            $raw = file_get_contents($attemptsPath);
            $decoded = ($raw === false) ? null : json_decode($raw, true);
            if (!is_array($decoded)) {
                throw new RuntimeException('Login rate-limit data is invalid.');
            }
            $attemptsByAddress = $decoded;
        }

        $now = time();
        $cutoff = $now - 900;
        foreach ($attemptsByAddress as $addressKey => $timestamps) {
            if (!preg_match('/^[a-f0-9]{64}$/', (string)$addressKey) || !is_array($timestamps)) {
                unset($attemptsByAddress[$addressKey]);
                continue;
            }
            $recent = array();
            foreach ($timestamps as $timestamp) {
                if (is_int($timestamp) && $timestamp > $cutoff && $timestamp <= $now) {
                    $recent[] = $timestamp;
                }
            }
            if ($recent) {
                $attemptsByAddress[$addressKey] = $recent;
            } else {
                unset($attemptsByAddress[$addressKey]);
            }
        }

        $remoteAddress = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : 'unknown';
        $addressKey = hash('sha256', $remoteAddress);
        $recentAttempts = isset($attemptsByAddress[$addressKey])
            ? $attemptsByAddress[$addressKey] : array();
        if (count($recentAttempts) >= 5) {
            return false;
        }

        $admin = edm_auth_read_admin();
        $valid = ($admin !== null && password_verify($password, $admin['password_hash']));
        if ($valid) {
            unset($attemptsByAddress[$addressKey]);
        } else {
            $recentAttempts[] = $now;
            $attemptsByAddress[$addressKey] = $recentAttempts;
        }

        $json = json_encode($attemptsByAddress, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Login rate-limit data could not be encoded.');
        }
        $temporary = tempnam($dataPath, '.login-attempts-');
        if ($temporary === false) {
            throw new RuntimeException('Could not create temporary login rate-limit file.');
        }
        try {
            $written = file_put_contents($temporary, $json . "\n", LOCK_EX);
            if ($written === false || $written !== strlen($json . "\n")
                || !chown($temporary, 0) || !chgrp($temporary, 0)
                || !chmod($temporary, 0600)
                || !rename($temporary, $attemptsPath)) {
                throw new RuntimeException('Could not safely save login rate-limit data.');
            }
        } finally {
            if (file_exists($temporary)) {
                @unlink($temporary);
            }
        }
    } finally {
        @flock($lock, LOCK_UN);
        fclose($lock);
    }

    if (!$valid) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION = array();
    $_SESSION['authenticated'] = true;
    $_SESSION['created_at'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['auth_epoch'] = edm_auth_read_session_epoch();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return true;
}

function edm_auth_logout()
{
    $_SESSION = array();
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', array(
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => isset($params['domain']) ? $params['domain'] : '',
            'secure' => !empty($params['secure']),
            'httponly' => !empty($params['httponly']),
            'samesite' => 'Lax'
        ));
    }
    session_destroy();
}

function edm_auth_require_login()
{
    if (!edm_auth_is_authenticated()) {
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo "Authentication required.\n";
        exit;
    }
    $now = time();
    $_SESSION['last_activity'] = $now;
}
