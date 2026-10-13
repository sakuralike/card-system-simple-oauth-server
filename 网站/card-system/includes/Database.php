<?php
/**
 * 数据库操作类
 */

class Database {
    private static $instance = null;
    private $pdo;
    private $drawSchemaReady = false;
    
    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            // M2：PDO 异常消息含主机名、库名、用户名，绝不能回显给访客。
            error_log('DB connect failed: ' . $e->getMessage());
            if (defined('APP_DEBUG') && APP_DEBUG) {
                die('数据库连接失败: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
            }
            http_response_code(500);
            die('数据库连接失败，请联系管理员。');
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getPdo() {
        return $this->pdo;
    }
    
    public function query($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    
    public function fetch($sql, $params = []) {
        return $this->query($sql, $params)->fetch();
    }
    
    public function fetchAll($sql, $params = []) {
        return $this->query($sql, $params)->fetchAll();
    }
    
    public function insert($table, $data) {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        
        $sql = "INSERT INTO " . DB_PREFIX . "$table ($columns) VALUES ($placeholders)";
        $this->query($sql, array_values($data));
        
        return $this->pdo->lastInsertId();
    }
    
    public function update($table, $data, $where, $whereParams = []) {
        $set = implode(' = ?, ', array_keys($data)) . ' = ?';
        $sql = "UPDATE " . DB_PREFIX . "$table SET $set WHERE $where";
        
        return $this->query($sql, array_merge(array_values($data), $whereParams));
    }
    
    public function delete($table, $where, $params = []) {
        $sql = "DELETE FROM " . DB_PREFIX . "$table WHERE $where";
        return $this->query($sql, $params);
    }
    
    public function count($table, $where = '1', $params = []) {
        $sql = "SELECT COUNT(*) as cnt FROM " . DB_PREFIX . "$table WHERE $where";
        $result = $this->fetch($sql, $params);
        return (int) $result['cnt'];
    }

    public function ensureDrawSchema() {
        if ($this->drawSchemaReady) {
            return;
        }
        if (!preg_match('/^[a-zA-Z0-9_]*$/D', DB_PREFIX) || $this->pdo->inTransaction()) {
            throw new RuntimeException('无法初始化领取数据结构');
        }

        $columns = [
            'request_id' => 'BIGINT UNSIGNED DEFAULT NULL',
            'card_code' => 'TEXT DEFAULT NULL',
            'content' => 'TEXT DEFAULT NULL',
            'category_name' => 'VARCHAR(100) DEFAULT NULL',
        ];
        $existing = array_column($this->fetchAll('SHOW COLUMNS FROM ' . DB_PREFIX . 'draw_logs'), 'Field');
        $requestsExist = $this->fetch(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [DB_PREFIX . 'draw_requests']
        );
        $snapshotsReady = $this->fetch(
            'SELECT setting_value FROM ' . DB_PREFIX . 'settings WHERE setting_key = ?',
            ['draw_snapshots_initialized']
        );
        if ($requestsExist && !array_diff(array_keys($columns), $existing) && ($snapshotsReady['setting_value'] ?? '') === '1') {
            $this->drawSchemaReady = true;
            return;
        }

        $lock = 'card_draw_schema_' . substr(hash('sha256', DB_NAME . ':' . DB_PREFIX), 0, 40);
        if ((int) $this->fetch('SELECT GET_LOCK(?, 15) AS acquired', [$lock])['acquired'] !== 1) {
            throw new RuntimeException('领取数据结构正在初始化，请稍后重试');
        }
        try {
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS ' . DB_PREFIX . "draw_requests (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                request_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                category_id INT UNSIGNED NOT NULL,
                response MEDIUMTEXT NOT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY user_request (user_id, request_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $existing = array_column($this->fetchAll('SHOW COLUMNS FROM ' . DB_PREFIX . 'draw_logs'), 'Field');
            $changes = [];
            foreach ($columns as $name => $definition) {
                if (!in_array($name, $existing, true)) {
                    $changes[] = "ADD COLUMN $name $definition";
                }
            }
            if (!in_array('request_id', $existing, true)) {
                $changes[] = 'ADD KEY user_draw_day (user_id, created_at, request_id)';
            }
            if ($changes) {
                $this->pdo->exec('ALTER TABLE ' . DB_PREFIX . 'draw_logs ' . implode(', ', $changes));
            }
            $this->pdo->exec('UPDATE ' . DB_PREFIX . 'draw_logs l
                LEFT JOIN ' . DB_PREFIX . 'cards c ON l.card_id = c.id
                LEFT JOIN ' . DB_PREFIX . "categories cat ON l.category_id = cat.id
                SET l.card_code = COALESCE(l.card_code, c.card_code, ''),
                    l.content = COALESCE(l.content, c.content, ''),
                    l.category_name = COALESCE(l.category_name, cat.name, '')
                WHERE l.card_code IS NULL OR l.content IS NULL OR l.category_name IS NULL");
            $this->query(
                'INSERT INTO ' . DB_PREFIX . "settings (setting_key, setting_value) VALUES (?, '1') ON DUPLICATE KEY UPDATE setting_value = '1'",
                ['draw_snapshots_initialized']
            );
            $this->drawSchemaReady = true;
        } finally {
            $this->query('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }
    
    /**
     * 创建数据表
     */
    public static function createTables() {
        $pdo = self::getInstance()->getPdo();
        // 前缀来自安装程序的白名单校验，此处再兜底一次
        $prefix = preg_replace('/[^a-zA-Z0-9_]/', '', (string) DB_PREFIX);
        $defaultLimit  = max(0, (int) DEFAULT_DAILY_LIMIT);
        $defaultPerDraw = max(1, (int) DEFAULT_CARDS_PER_DRAW);
        
        // 用户表
        $pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            wp_user_id INT UNSIGNED NOT NULL,
            username VARCHAR(100) NOT NULL,
            email VARCHAR(200) DEFAULT '',
            avatar VARCHAR(500) DEFAULT '',
            role ENUM('user', 'admin') DEFAULT 'user',
            daily_limit INT DEFAULT {$defaultLimit},
            cards_per_draw INT DEFAULT {$defaultPerDraw},
            is_banned TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_login DATETIME DEFAULT NULL,
            UNIQUE KEY wp_user_id (wp_user_id),
            KEY username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        
        // 卡密分类表
        $pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}categories (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            icon VARCHAR(50) DEFAULT '🎁',
            is_active TINYINT(1) DEFAULT 1,
            sort_order INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        
        // 卡密表
        $pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}cards (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            category_id INT UNSIGNED DEFAULT NULL,
            card_code VARCHAR(500) NOT NULL,
            content TEXT,
            status ENUM('available', 'used', 'disabled') DEFAULT 'available',
            used_by INT UNSIGNED DEFAULT NULL,
            used_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY category_id (category_id),
            KEY status (status),
            KEY used_by (used_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        
        // 提取记录表
        $pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}draw_logs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            card_id INT UNSIGNED NOT NULL,
            category_id INT UNSIGNED DEFAULT NULL,
            ip_address VARCHAR(50) DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY user_id (user_id),
            KEY card_id (card_id),
            KEY created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        
        // 系统设置表
        $pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}settings (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL,
            setting_value TEXT,
            UNIQUE KEY setting_key (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        
        // 插入默认设置
        $pdo->exec("INSERT IGNORE INTO {$prefix}settings (setting_key, setting_value) VALUES
            ('site_notice', '欢迎使用卡密提取系统！'),
            ('draw_rules', '1. 每日提取次数有限\\n2. 提取后请及时保存\\n3. 卡密仅限本人使用')
        ");
        
        // 插入默认分类
        $pdo->exec("INSERT IGNORE INTO {$prefix}categories (id, name, description, icon) VALUES
            (1, '默认分类', '默认卡密分类', '🎁')
        ");

        self::getInstance()->ensureDrawSchema();
        
        return true;
    }
}
