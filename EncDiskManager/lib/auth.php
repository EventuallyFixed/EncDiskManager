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
    return $path;
}

function edm_auth_data_path()
{
    $appPath = edm_auth_app_path();
    $dataPath = $appPath . '/data';
    $stat = @lstat($dataPath);
    if ($stat === false || (($stat['mode'] & 0170000) !== 0040000)
        || is_link($dataPath) || $stat['uid'] !== 0
        || (($stat['mode'] & 0777) !== 0700)
        || realpath($dataPath) !== $dataPath) {
        throw new RuntimeException('Private application data directory is not secure.');
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

function edm_auth_start_session()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');

    $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== ''
        && strtolower((string)$_SERVER['HTTPS']) !== 'off';
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
    return isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
}

function edm_auth_login($password)
{
    $admin = edm_auth_read_admin();
    if ($admin === null || !password_verify($password, $admin['password_hash'])) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION = array();
    $_SESSION['authenticated'] = true;
    $_SESSION['created_at'] = time();
    $_SESSION['last_activity'] = time();
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
