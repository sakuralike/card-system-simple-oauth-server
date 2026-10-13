<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$dsn = getenv('CARD_TEST_DSN');
if (!$dsn) {
    fwrite(STDERR, "Set CARD_TEST_DSN to a dedicated MySQL card_claim_test database.\n");
    exit(1);
}
$pdo = new PDO($dsn, getenv('CARD_TEST_USER') ?: 'root', getenv('CARD_TEST_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'card_claim_test') {
    throw new RuntimeException('Refusing to use a non-test database');
}
$worker = ($argv[1] ?? '') === '--worker';
$prefix = $worker ? getenv('CARD_TEST_PREFIX') : 'claimtest_' . bin2hex(random_bytes(6)) . '_';
if (!preg_match('/^claimtest_[a-f0-9]{12}_$/D', $prefix)) {
    throw new RuntimeException('Invalid test prefix');
}
putenv('CARD_TEST_PREFIX=' . $prefix);
define('DB_PREFIX', $prefix);
define('DB_NAME', 'card_claim_test');
define('DEFAULT_DAILY_LIMIT', 3);
define('DEFAULT_CARDS_PER_DRAW', 1);
date_default_timezone_set('Asia/Shanghai');
require dirname(__DIR__) . '/includes/Database.php';
require dirname(__DIR__) . '/includes/CardManager.php';
$reflection = new ReflectionClass(Database::class);
$db = $reflection->newInstanceWithoutConstructor();
$reflection->getProperty('pdo')->setValue($db, $pdo);
$reflection->getProperty('instance')->setValue(null, $db);

if ($worker) {
    $manager = new CardManager();
    while (microtime(true) < (float) $argv[5]) {
        usleep(1000);
    }
    echo json_encode($manager->drawCard((int) $argv[2], (int) $argv[3], $argv[4]), JSON_THROW_ON_ERROR);
    exit;
}

$checks = 0;
function check($condition, $label) {
    global $checks;
    if (!$condition) {
        throw new RuntimeException($label);
    }
    $checks++;
    echo "PASS $label\n";
}
function user($limit = 3, $perDraw = 1) {
    global $db;
    static $wpId = 100;
    return (int) $db->insert('users', [
        'wp_user_id' => ++$wpId, 'username' => 'test-' . $wpId,
        'daily_limit' => $limit, 'cards_per_draw' => $perDraw,
    ]);
}
function category($stock, $active = 1) {
    global $manager;
    $id = (int) $manager->addCategory(['name' => 'test-category', 'is_active' => $active]);
    for ($i = 0; $i < $stock; $i++) {
        $manager->addCard(['category_id' => $id, 'card_code' => "test-$id-$i", 'content' => 'original-content']);
    }
    return $id;
}
function parallelDraws($requests) {
    $processes = [];
    $start = microtime(true) + 2;
    foreach ($requests as [$userId, $categoryId, $requestId]) {
        $command = [PHP_BINARY, '-d', 'extension_dir=' . ini_get('extension_dir'), '-d', 'extension=pdo_mysql',
            __FILE__, '--worker', (string) $userId, (string) $categoryId, $requestId, (string) $start];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start concurrency worker');
        }
        fclose($pipes[0]);
        $processes[] = [$process, $pipes];
    }
    $results = [];
    foreach ($processes as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException('Concurrency worker failed: ' . $error);
        }
        $results[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }
    return $results;
}

try {
    Database::createTables();
    $manager = new CardManager();
    $legacyUser = user();
    $legacyCard = $manager->addCard(['category_id' => 1, 'card_code' => 'legacy-code', 'content' => 'legacy-content']);
    $db->update('cards', ['status' => 'used', 'used_by' => $legacyUser], 'id = ?', [$legacyCard]);
    $legacyLog = $db->insert('draw_logs', [
        'user_id' => $legacyUser, 'card_id' => $legacyCard, 'category_id' => 1, 'created_at' => date('Y-m-d H:i:s'),
    ]);
    $before = $db->fetch('SELECT * FROM ' . DB_PREFIX . 'cards WHERE id = ?', [$legacyCard]);
    $pdo->exec('ALTER TABLE ' . DB_PREFIX . 'draw_logs DROP INDEX user_draw_day, DROP COLUMN request_id, DROP COLUMN card_code, DROP COLUMN content, DROP COLUMN category_name');
    $pdo->exec('DROP TABLE ' . DB_PREFIX . 'draw_requests');
    $db->delete('settings', 'setting_key = ?', ['draw_snapshots_initialized']);
    $reflection->getProperty('drawSchemaReady')->setValue($db, false);
    $manager = new CardManager();
    check($before === $db->fetch('SELECT * FROM ' . DB_PREFIX . 'cards WHERE id = ?', [$legacyCard]), 'legacy inventory remains unchanged');
    check($manager->countUserDraws($legacyUser) === 1, 'legacy logs keep their previous count');
    $manager->deleteCard($legacyCard);
    check($manager->queryCard($legacyUser, 'legacy-code')['content'] === 'legacy-content', 'legacy snapshots survive inventory deletion');
    check((int) $manager->getUserDrawLogs($legacyUser)['logs'][0]['id'] === (int) $legacyLog, 'legacy log identity is preserved');
    $reflection->getProperty('drawSchemaReady')->setValue($db, false);
    $manager = new CardManager();
    check($db->count('draw_logs') === 1, 'schema initialization is repeatable');

    $uid = user(3, 2);
    $cat = category(8);
    $key = bin2hex(random_bytes(16));
    $first = $manager->drawCard($uid, $cat, $key);
    check($first['success'] && count($first['cards']) === 2 && $first['remaining'] === 2, 'two cards consume one of three draws');
    $replay = $manager->drawCard($uid, $cat, $key);
    check($replay['cards'] === $first['cards'] && $replay['replayed'] && $replay['remaining'] === 2, 'retry returns the original cards');
    check($manager->countUserDraws($uid) === 1, 'retry does not consume quota');
    $second = $manager->drawCard($uid, $cat, bin2hex(random_bytes(16)));
    $third = $manager->drawCard($uid, $cat, bin2hex(random_bytes(16)));
    check($second['remaining'] === 1 && $third['remaining'] === 0 && count($third['cards']) === 2, 'three draws deliver six cards');
    check(!$manager->drawCard($uid, $cat, bin2hex(random_bytes(16)))['success'], 'fourth draw is denied');
    check($manager->countUserDraws($uid) === 3 && $manager->getUserRemainingDraws($uid) === 0, 'quota counters agree');
    check($db->count('draw_logs', 'user_id = ?', [$uid]) === 6, 'per-card history is retained');

    $db->update('users', ['is_banned' => 1], 'id = ?', [$uid]);
    $db->update('categories', ['is_active' => 0], 'id = ?', [$cat]);
    $manager->deleteCard($first['cards'][0]['id']);
    check($manager->drawCard($uid, $cat, $key)['cards'] === $first['cards'], 'retry survives ban, category shutdown and inventory deletion');
    check($manager->queryCard($uid, $first['cards'][0]['card_code'])['content'] === 'original-content', 'query reads the issued snapshot');
    check(!$manager->queryCard($legacyUser, $first['cards'][0]['card_code']), 'another user cannot query the snapshot');
    check(!$manager->drawCard($uid, 1, $key)['success'], 'request key cannot change category');

    $freshUser = user(3, 2);
    check(!$manager->drawCard($freshUser, $cat, bin2hex(random_bytes(16)))['success'], 'disabled category cannot issue cards');
    check(!$manager->drawCard($freshUser, 999999, bin2hex(random_bytes(16)))['success'], 'missing category cannot issue cards');
    check(!$manager->drawCard($freshUser, 0, bin2hex(random_bytes(16)))['success'], 'zero category cannot bypass selection');
    check(!$manager->drawCard($freshUser, 1, 'invalid')['success'], 'invalid request key is rejected');
    check($manager->countUserDraws($freshUser) === 0, 'rejected requests do not consume quota');

    $short = category(1);
    $shortKey = bin2hex(random_bytes(16));
    check(!$manager->drawCard($freshUser, $short, $shortKey)['success'], 'insufficient stock denies the entire batch');
    check($db->count('cards', 'category_id = ? AND status = ?', [$short, 'available']) === 1, 'insufficient stock remains untouched');
    $manager->addCard(['category_id' => $short, 'card_code' => 'short-replenished']);
    check(!$manager->drawCard($freshUser, $short, $shortKey)['success'], 'a definitive failure stays failed on replay');
    check($manager->drawCard($freshUser, $short, bin2hex(random_bytes(16)))['success'], 'a new request can use replenished stock');
    check(!$manager->drawCard(user(0), 1, bin2hex(random_bytes(16)))['success'], 'zero quota is not replaced with a default');

    $rollbackUser = user(3, 2);
    $rollbackCat = category(2);
    $rollbackKey = bin2hex(random_bytes(16));
    $pdo->exec('CREATE TRIGGER ' . DB_PREFIX . 'fail_log BEFORE INSERT ON ' . DB_PREFIX . "draw_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'test rollback'");
    $failed = $manager->drawCard($rollbackUser, $rollbackCat, $rollbackKey);
    check(!$failed['success'] && $failed['retryable'], 'database failure requests a safe retry');
    check($db->count('cards', 'category_id = ? AND status = ?', [$rollbackCat, 'available']) === 2, 'database failure rolls back inventory');
    check($db->count('draw_requests', 'user_id = ?', [$rollbackUser]) === 0, 'database failure rolls back the request record');
    check($manager->countUserDraws($rollbackUser) === 0, 'database failure rolls back quota');
    $pdo->exec('DROP TRIGGER ' . DB_PREFIX . 'fail_log');
    check($manager->drawCard($rollbackUser, $rollbackCat, $rollbackKey)['success'], 'the same request succeeds after rollback');

    $concurrentUser = user(3, 2);
    $concurrentCat = category(20);
    $requests = [];
    for ($i = 0; $i < 8; $i++) {
        $requests[] = [$concurrentUser, $concurrentCat, bin2hex(random_bytes(16))];
    }
    $results = parallelDraws($requests);
    check(count(array_filter($results, fn($r) => $r['success'])) === 3, 'eight concurrent sessions respect three daily draws');
    check($db->count('draw_logs', 'user_id = ?', [$concurrentUser]) === 6, 'concurrent sessions issue exactly six cards');

    $replayUser = user(3, 2);
    $replayCat = category(10);
    $replayKey = bin2hex(random_bytes(16));
    $results = parallelDraws(array_fill(0, 8, [$replayUser, $replayCat, $replayKey]));
    check(count(array_filter($results, fn($r) => $r['success'])) === 8, 'all concurrent duplicate requests receive a result');
    check(count(array_unique(array_map(fn($r) => json_encode($r['cards']), $results))) === 1, 'concurrent duplicates receive identical cards');
    check($manager->countUserDraws($replayUser) === 1 && $db->count('draw_requests', 'user_id = ?', [$replayUser]) === 1, 'concurrent duplicates commit once');

    $scarceCat = category(3);
    $requests = [];
    for ($i = 0; $i < 6; $i++) {
        $requests[] = [user(), $scarceCat, bin2hex(random_bytes(16))];
    }
    $results = parallelDraws($requests);
    $successes = array_values(array_filter($results, fn($r) => $r['success']));
    check(count($successes) === 3, 'competing users cannot overdraw inventory');
    check(count(array_unique(array_map(fn($r) => $r['cards'][0]['id'], $successes))) === 3, 'competing users never receive the same card');

    $db->update('draw_logs', ['created_at' => date('Y-m-d', strtotime('yesterday')) . ' 23:59:59'], 'user_id = ?', [$replayUser]);
    check($manager->getUserRemainingDraws($replayUser) === 3, 'new day resets the count using application time');
    $nextDay = $manager->drawCard($replayUser, $replayCat, $replayKey);
    check($nextDay['remaining'] === 3 && $nextDay['replayed'], 'old request replay does not consume a new-day draw');
    check($manager->getStats()['total_draws'] === 13, 'admin totals count batches plus legacy records');

    $duplicateCat = category(0);
    $code = 'case-sensitive-code';
    check((bool) $manager->addCard(['category_id' => $duplicateCat, 'card_code' => $code]), 'first inventory entry is accepted');
    check($manager->addCard(['category_id' => $duplicateCat, 'card_code' => $code]) === false, 'same-category duplicate is rejected');
    check((bool) $manager->addCard(['category_id' => 1, 'card_code' => $code]), 'different categories do not share a key namespace');
    check((bool) $manager->addCard(['category_id' => $duplicateCat, 'card_code' => strtoupper($code)]), 'case-sensitive keys remain distinct');
    check($manager->importCards($duplicateCat, [$code, 'batch-key', 'batch-key', 'other-key']) === 2, 'batch imports count only new unique inventory');
    $duplicateUser = user(3, 2);
    $issued = $manager->drawCard($duplicateUser, $duplicateCat, bin2hex(random_bytes(16)));
    check($manager->queryCard($duplicateUser, $code)['card_code'] === $code, 'card query preserves case-sensitive identity');
    $manager->deleteCard($issued['cards'][0]['id']);
    check($manager->addCard(['category_id' => $duplicateCat, 'card_code' => $issued['cards'][0]['card_code']]) === false, 'deleted issued keys cannot be reimported');
    $db->insert('cards', ['category_id' => $duplicateCat, 'card_code' => $issued['cards'][0]['card_code']]);
    $issuedAgain = $manager->drawCard($duplicateUser, $duplicateCat, bin2hex(random_bytes(16)));
    check($issuedAgain['success'] && !in_array($issued['cards'][0]['card_code'], array_column($issuedAgain['cards'], 'card_code'), true), 'legacy available duplicate of an issued key is skipped');

    $oldDuplicates = category(0);
    for ($i = 0; $i < 3; $i++) {
        $db->insert('cards', ['category_id' => $oldDuplicates, 'card_code' => 'same-legacy-key']);
    }
    check($manager->getAvailableCardCount($oldDuplicates) === 1, 'available inventory counts unique unissued keys');
    check(!$manager->drawCard(user(3, 2), $oldDuplicates, bin2hex(random_bytes(16)))['success'], 'duplicate rows do not satisfy a two-card batch');
    $results = parallelDraws([[user(), $oldDuplicates, bin2hex(random_bytes(16))], [user(), $oldDuplicates, bin2hex(random_bytes(16))]]);
    check(count(array_filter($results, fn($r) => $r['success'])) === 1, 'competing users receive a duplicated legacy key only once');
    check($db->count('cards', 'category_id = ?', [$oldDuplicates]) === 3, 'legacy duplicate inventory is preserved');
    check($manager->getAvailableCardCount($oldDuplicates) === 0, 'available inventory excludes issued duplicates');
    check($manager->getAvailableCardCount($cat) === 0, 'disabled category has no claimable inventory');
    echo "PASS $checks checks\n";
} finally {
    foreach (['draw_logs', 'draw_requests', 'cards', 'categories', 'users', 'settings'] as $table) {
        $pdo->exec('DROP TABLE IF EXISTS ' . DB_PREFIX . $table);
    }
}
