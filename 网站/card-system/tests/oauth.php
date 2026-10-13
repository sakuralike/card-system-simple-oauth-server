<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$wpRoot = rtrim(getenv('CARD_TEST_WP_ROOT') ?: '/var/www/html', '/\\');
$host = getenv('CARD_TEST_DB_HOST');
$user = getenv('CARD_TEST_USER');
$password = getenv('CARD_TEST_PASSWORD');
if (!$host || !$user || $password === false || !is_file($wpRoot . '/wp-load.php')) {
    fwrite(STDERR, "Set CARD_TEST_WP_ROOT, CARD_TEST_DB_HOST, CARD_TEST_USER and CARD_TEST_PASSWORD for card_claim_test.\n");
    exit(1);
}

$worker = ($argv[1] ?? '') === '--token';
$prefix = $worker ? getenv('CARD_TEST_OAUTH_PREFIX') : 'oauthtest_' . bin2hex(random_bytes(6)) . '_';
if (!is_string($prefix) || !preg_match('/^oauthtest_[a-f0-9]{12}_$/D', $prefix)) {
    throw new RuntimeException('Invalid OAuth test prefix');
}
putenv('CARD_TEST_OAUTH_PREFIX=' . $prefix);
define('SHORTINIT', true);
define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
require $wpRoot . '/wp-load.php';
require_once ABSPATH . WPINC . '/l10n.php';
require_once ABSPATH . WPINC . '/kses.php';
require_once ABSPATH . WPINC . '/pluggable.php';
wp_plugin_directory_constants();
$GLOBALS['wp_plugin_paths'] = [];
$GLOBALS['l10n'] = [];
add_filter('pre_option_blog_charset', static fn() => 'UTF-8');

$originalWpdb = $wpdb;
$testDb = new wpdb($user, $password, 'card_claim_test', $host);
$testDb->suppress_errors(true);
$testDb->hide_errors();
if ($testDb->get_var('SELECT DATABASE()') !== 'card_claim_test') {
    throw new RuntimeException('Refusing to use a non-test database');
}
$testDb->set_prefix($prefix);
$wpdb = $testDb;
$createdTables = [];
$checks = 0;

function oauthCheck($condition, $label) {
    global $checks;
    if (!$condition) {
        throw new RuntimeException($label);
    }
    $checks++;
    echo "PASS $label\n";
}

function oauthSql($sql) {
    global $testDb;
    $result = $testDb->query($sql);
    if ($result === false) {
        throw new RuntimeException('OAuth test database query failed: ' . $testDb->last_error);
    }
    return $result;
}

function oauthTokenRequest($parameters) {
    $process = proc_open([PHP_BINARY, __FILE__, '--token'], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start OAuth token worker');
    }
    fwrite($pipes[0], json_encode($parameters, JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0 || $errors !== '') {
        throw new RuntimeException('OAuth token worker failed: ' . $errors);
    }
    return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
}

try {
    require dirname(__DIR__) . '/wordpress-plugin/simple-oauth-server.php';
    $plugin = SimpleOAuthServer\Plugin::instance();
    $reflection = new ReflectionClass($plugin);
    $clientsTable = $prefix . 'oauth_clients';
    $tokensTable = $prefix . 'oauth_tokens';
    $reflection->getProperty('table_name')->setValue($plugin, $clientsTable);
    $reflection->getProperty('tokens_table')->setValue($plugin, $tokensTable);

    if ($worker) {
        $_POST = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
        $status = 200;
        add_filter('status_header', static function ($header, $code) use (&$status) {
            $status = (int) $code;
            return $header;
        }, 10, 2);
        ob_start(static function ($body) use (&$status) {
            return json_encode([
                'status' => $status,
                'body' => json_decode($body, true, 512, JSON_THROW_ON_ERROR),
            ], JSON_THROW_ON_ERROR);
        });
        $reflection->getMethod('handle_token')->invoke($plugin);
        throw new RuntimeException('Token handler returned without a response');
    }

    oauthSql("CREATE TABLE $clientsTable (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        client_id VARCHAR(100) NOT NULL,
        client_secret VARCHAR(100) NOT NULL,
        name VARCHAR(200) NOT NULL,
        redirect_uri TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY client_id (client_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $createdTables[] = $clientsTable;
    oauthSql("CREATE TABLE $tokensTable (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        client_id VARCHAR(100) NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        access_token VARCHAR(200) NOT NULL,
        refresh_token VARCHAR(200),
        code VARCHAR(200),
        code_expires DATETIME,
        token_expires DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY client_id (client_id), KEY access_token (access_token), KEY code (code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $createdTables[] = $tokensTable;

    $clientId = 'oauth-test-client';
    $clientSecret = bin2hex(random_bytes(24));
    $redirectA = 'https://client.example.test/callback/A?source=one';
    $redirectB = 'https://client.example.test/callback/B?source=two';
    oauthCheck($testDb->insert($clientsTable, [
        'client_id' => $clientId, 'client_secret' => $clientSecret,
        'name' => 'OAuth regression client', 'redirect_uri' => $redirectA . "\n" . $redirectB,
    ]) === 1, 'legacy client fixture is created');
    oauthCheck($testDb->insert($tokensTable, [
        'client_id' => $clientId, 'user_id' => 123, 'access_token' => 'legacy-access-token',
        'refresh_token' => 'legacy-refresh-token', 'token_expires' => gmdate('Y-m-d H:i:s', time() + 3600),
    ]) === 1, 'legacy access token fixture is created');
    oauthCheck($testDb->insert($tokensTable, [
        'client_id' => $clientId, 'user_id' => 123, 'access_token' => '',
        'code' => 'legacy-unbound-code', 'code_expires' => gmdate('Y-m-d H:i:s', time() + 600),
    ]) === 1, 'legacy unbound code fixture is created');
    $legacyClient = $testDb->get_row("SELECT * FROM $clientsTable", ARRAY_A);
    $legacyToken = $testDb->get_row("SELECT * FROM $tokensTable WHERE access_token = 'legacy-access-token'", ARRAY_A);
    $saveCode = $reflection->getMethod('save_auth_code');
    $verifyCode = $reflection->getMethod('verify_auth_code');
    $verifyToken = $reflection->getMethod('verify_access_token');

    oauthCheck($testDb->get_var("SHOW COLUMNS FROM $tokensTable LIKE 'code_redirect_uri'") === null, 'legacy schema starts without redirect binding');
    oauthCheck($saveCode->invoke($plugin, $clientId, 123, 'bound-code', $redirectA) === 1, 'first authorization upgrades the legacy schema without reactivation');
    oauthCheck($testDb->get_var("SHOW COLUMNS FROM $tokensTable LIKE 'code_redirect_uri'") === 'code_redirect_uri', 'redirect binding column is added');
    oauthCheck($testDb->get_row("SELECT * FROM $clientsTable", ARRAY_A) === $legacyClient, 'schema upgrade preserves the existing client');
    $upgradedToken = $testDb->get_row("SELECT * FROM $tokensTable WHERE access_token = 'legacy-access-token'", ARRAY_A);
    unset($upgradedToken['code_redirect_uri']);
    oauthCheck($upgradedToken === $legacyToken, 'schema upgrade preserves the existing access token');
    oauthCheck($verifyCode->invoke($plugin, $clientId, 'bound-code')['code_redirect_uri'] === $redirectA, 'authorization code stores the exact selected callback');
    oauthCheck($saveCode->invoke($plugin, $clientId, 123, 'second-bound-code', $redirectA) === 1, 'authorization remains available after schema upgrade');

    $request = [
        'grant_type' => 'authorization_code', 'client_id' => $clientId,
        'client_secret' => $clientSecret, 'code' => 'bound-code',
    ];
    foreach (['other registered callback' => $redirectB, 'omitted callback' => null, 'case-changed callback' => strtolower($redirectA)] as $label => $redirect) {
        $parameters = $request;
        if ($redirect !== null) {
            $parameters['redirect_uri'] = $redirect;
        }
        $response = oauthTokenRequest($parameters);
        oauthCheck($response['status'] === 400 && ($response['body']['error'] ?? '') === 'invalid_grant', "$label is rejected");
        oauthCheck($verifyCode->invoke($plugin, $clientId, 'bound-code') !== null, "$label does not consume the authorization code");
    }
    $response = oauthTokenRequest($request + ['redirect_uri' => $redirectA]);
    oauthCheck($response['status'] === 200 && !empty($response['body']['access_token']) && ($response['body']['token_type'] ?? '') === 'Bearer', 'exact callback exchanges the authorization code');
    oauthCheck($verifyCode->invoke($plugin, $clientId, 'bound-code') === null, 'successful exchange consumes the authorization code');
    oauthCheck($verifyToken->invoke($plugin, $response['body']['access_token']) !== null, 'exchanged access token is usable');
    $response = oauthTokenRequest($request + ['redirect_uri' => $redirectA]);
    oauthCheck($response['status'] === 400 && ($response['body']['error'] ?? '') === 'invalid_grant', 'authorization code cannot be exchanged twice');

    $request['code'] = 'legacy-unbound-code';
    $response = oauthTokenRequest($request + ['redirect_uri' => $redirectA]);
    oauthCheck($response['status'] === 400 && ($response['body']['error'] ?? '') === 'invalid_grant', 'legacy unbound authorization code is rejected');
    oauthCheck($verifyCode->invoke($plugin, $clientId, 'legacy-unbound-code') !== null, 'rejected legacy authorization code is not consumed');
    oauthCheck($verifyToken->invoke($plugin, 'legacy-access-token') !== null, 'legacy access token remains usable');

    foreach (['+08:00', '-08:00'] as $timezone) {
        oauthSql($testDb->prepare('SET SESSION time_zone = %s', $timezone));
        foreach (['future' => 3600, 'past' => -3600] as $state => $offset) {
            foreach (['code', 'token'] as $kind) {
                $identity = "$timezone-$state-$kind";
                $row = [
                    'client_id' => $clientId, 'user_id' => 123,
                    'access_token' => $kind === 'token' ? $identity : '',
                    $kind . '_expires' => gmdate('Y-m-d H:i:s', time() + $offset),
                ];
                if ($kind === 'code') {
                    $row['code'] = $identity;
                    $row['code_redirect_uri'] = $redirectA;
                }
                oauthCheck($testDb->insert($tokensTable, $row) === 1, "$timezone $state $kind fixture is created");
            }
        }
        SimpleOAuthServer\Plugin::cleanup_expired();
        foreach (['code', 'token'] as $kind) {
            $column = $kind === 'code' ? 'code' : 'access_token';
            foreach (['future' => 1, 'past' => 0] as $state => $expected) {
                $count = (int) $testDb->get_var($testDb->prepare("SELECT COUNT(*) FROM $tokensTable WHERE $column = %s", "$timezone-$state-$kind"));
                oauthCheck($count === $expected, "$timezone cleanup handles $state $kind in UTC");
            }
        }
    }
    oauthCheck($verifyToken->invoke($plugin, 'legacy-access-token') !== null, 'timezone cleanup preserves the legacy live access token');
    echo "PASS $checks checks\n";
} finally {
    $cleanupFailed = false;
    foreach (array_reverse($createdTables) as $table) {
        if ($testDb->query("DROP TABLE IF EXISTS $table") === false) {
            $cleanupFailed = true;
        }
    }
    $wpdb = $originalWpdb;
    $testDb->close();
    if ($cleanupFailed) {
        throw new RuntimeException('OAuth test table cleanup failed');
    }
}
