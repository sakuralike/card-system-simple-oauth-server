<?php
/**
 * 卡密管理类
 */

class CardManager {
    
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->db->ensureDrawSchema();
    }
    
    /**
     * 获取所有分类
     */
    public function getCategories($activeOnly = true) {
        $where = $activeOnly ? "WHERE is_active = 1" : "";
        return $this->db->fetchAll(
            "SELECT * FROM " . DB_PREFIX . "categories $where ORDER BY sort_order ASC, id ASC"
        );
    }
    
    /**
     * 获取单个分类
     */
    public function getCategory($id) {
        return $this->db->fetch(
            "SELECT * FROM " . DB_PREFIX . "categories WHERE id = ?",
            [$id]
        );
    }
    
    /**
     * 添加分类
     */
    public function addCategory($data) {
        return $this->db->insert('categories', [
            'name' => $data['name'],
            'description' => $data['description'] ?? '',
            'icon' => $data['icon'] ?? '🎁',
            'is_active' => $data['is_active'] ?? 1,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);
    }
    
    /**
     * 更新分类
     */
    public function updateCategory($id, $data) {
        return $this->db->update('categories', $data, 'id = ?', [$id]);
    }
    
    /**
     * 删除分类
     */
    public function deleteCategory($id) {
        // 将该分类下的卡密移到默认分类
        $this->db->query(
            "UPDATE " . DB_PREFIX . "cards SET category_id = 1 WHERE category_id = ?",
            [$id]
        );
        return $this->db->delete('categories', 'id = ? AND id != 1', [$id]);
    }
    
    /**
     * 获取卡密列表
     */
    public function getCards($filters = [], $page = 1, $perPage = 20) {
        $where = "1=1";
        $params = [];
        
        if (!empty($filters['category_id'])) {
            $where .= " AND c.category_id = ?";
            $params[] = $filters['category_id'];
        }
        
        if (!empty($filters['status'])) {
            $where .= " AND c.status = ?";
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['keyword'])) {
            $where .= " AND (c.card_code LIKE ? OR c.content LIKE ?)";
            $params[] = '%' . $filters['keyword'] . '%';
            $params[] = '%' . $filters['keyword'] . '%';
        }
        
        $offset = ($page - 1) * $perPage;
        
        $total = $this->db->fetch(
            "SELECT COUNT(*) as cnt FROM " . DB_PREFIX . "cards c WHERE $where",
            $params
        )['cnt'];
        
        $cards = $this->db->fetchAll(
            "SELECT c.*, cat.name as category_name, u.username as used_by_name
             FROM " . DB_PREFIX . "cards c
             LEFT JOIN " . DB_PREFIX . "categories cat ON c.category_id = cat.id
             LEFT JOIN " . DB_PREFIX . "users u ON c.used_by = u.id
             WHERE $where
             ORDER BY c.id DESC
             LIMIT $offset, $perPage",
            $params
        );
        
        return [
            'cards' => $cards,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => ceil($total / $perPage),
        ];
    }
    
    /**
     * 获取单个卡密
     */
    public function getCard($id) {
        return $this->db->fetch(
            "SELECT * FROM " . DB_PREFIX . "cards WHERE id = ?",
            [$id]
        );
    }
    
    /**
     * 添加单个卡密
     */
    public function addCard($data) {
        $categoryId = $data['category_id'] ?? 1;
        $pdo = $this->db->getPdo();
        try {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->beginTransaction();
            if (!$this->db->fetch('SELECT id FROM ' . DB_PREFIX . 'categories WHERE id = ? FOR UPDATE', [$categoryId])) {
                throw new InvalidArgumentException('卡密分类不存在，请刷新页面');
            }
            $duplicate = $this->db->fetch(
                'SELECT id FROM ' . DB_PREFIX . 'cards WHERE category_id = ? AND BINARY card_code = BINARY ? LIMIT 1',
                [$categoryId, $data['card_code']]
            ) || $this->db->fetch(
                'SELECT id FROM ' . DB_PREFIX . 'draw_logs WHERE category_id = ? AND BINARY card_code = BINARY ? LIMIT 1',
                [$categoryId, $data['card_code']]
            );
            if ($duplicate) {
                $pdo->rollBack();
                return false;
            }
            $id = $this->db->insert('cards', [
                'category_id' => $categoryId,
                'card_code' => $data['card_code'],
                'content' => $data['content'] ?? '',
                'status' => 'available',
            ]);
            $pdo->commit();
            return $id;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
    
    /**
     * 批量导入卡密
     */
    public function importCards($categoryId, $cards, $content = '') {
        $count = 0;
        $pdo = $this->db->getPdo();
        
        try {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->beginTransaction();
            if (!$this->db->fetch('SELECT id FROM ' . DB_PREFIX . 'categories WHERE id = ? FOR UPDATE', [$categoryId])) {
                throw new InvalidArgumentException('卡密分类不存在，请刷新页面');
            }
            $stmt = $pdo->prepare(
                "INSERT INTO " . DB_PREFIX . "cards (category_id, card_code, content, status) VALUES (?, ?, ?, 'available')"
            );
            
            foreach ($cards as $cardCode) {
                $cardCode = trim($cardCode);
                if (!empty($cardCode)) {
                    $duplicate = $this->db->fetch(
                        'SELECT id FROM ' . DB_PREFIX . 'cards WHERE category_id = ? AND BINARY card_code = BINARY ? LIMIT 1',
                        [$categoryId, $cardCode]
                    ) || $this->db->fetch(
                        'SELECT id FROM ' . DB_PREFIX . 'draw_logs WHERE category_id = ? AND BINARY card_code = BINARY ? LIMIT 1',
                        [$categoryId, $cardCode]
                    );
                    if ($duplicate) {
                        continue;
                    }
                    $stmt->execute([$categoryId, $cardCode, $content]);
                    $count++;
                }
            }
            
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        
        return $count;
    }
    
    /**
     * 更新卡密
     */
    public function updateCard($id, $data) {
        return $this->db->update('cards', $data, 'id = ?', [$id]);
    }
    
    /**
     * 批量更新卡密内容
     */
    public function batchUpdateContent($ids, $content) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return $this->db->query(
            "UPDATE " . DB_PREFIX . "cards SET content = ? WHERE id IN ($placeholders)",
            array_merge([$content], $ids)
        );
    }
    
    /**
     * 批量更新卡密分类
     */
    public function batchUpdateCategory($ids, $categoryId) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return $this->db->query(
            "UPDATE " . DB_PREFIX . "cards SET category_id = ? WHERE id IN ($placeholders)",
            array_merge([$categoryId], $ids)
        );
    }
    
    /**
     * 删除卡密
     */
    public function deleteCard($id) {
        return $this->db->delete('cards', 'id = ?', [$id]);
    }
    
    /**
     * 批量删除卡密
     */
    public function batchDeleteCards($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return $this->db->query(
            "DELETE FROM " . DB_PREFIX . "cards WHERE id IN ($placeholders)",
            $ids
        );
    }
    
    private function availableCardCondition() {
        return "c.status = 'available'
                AND NOT EXISTS (
                    SELECT 1 FROM " . DB_PREFIX . "cards duplicate
                    WHERE duplicate.category_id = c.category_id
                      AND BINARY duplicate.card_code = BINARY c.card_code
                      AND (duplicate.status = 'used' OR (duplicate.status = 'available' AND duplicate.id < c.id))
                )
                AND NOT EXISTS (
                    SELECT 1 FROM " . DB_PREFIX . 'draw_logs l
                    WHERE l.category_id = c.category_id AND BINARY l.card_code = BINARY c.card_code
                )';
    }

    public function getAvailableCardCount($categoryId = null) {
        $where = 'cat.is_active = 1 AND ' . $this->availableCardCondition();
        $params = [];
        if ($categoryId !== null) {
            $where .= ' AND c.category_id = ?';
            $params[] = $categoryId;
        }
        return (int) $this->db->fetch(
            'SELECT COUNT(*) AS cnt FROM ' . DB_PREFIX . 'cards c
             INNER JOIN ' . DB_PREFIX . "categories cat ON cat.id = c.category_id
             WHERE $where",
            $params
        )['cnt'];
    }

    /**
     * 提取卡密
     */
    public function drawCard($userId, $categoryId, $requestId) {
        if (!is_string($requestId) || !preg_match('/^[a-f0-9]{32}$/D', $requestId) || $categoryId <= 0) {
            return ['success' => false, 'message' => '领取参数无效，请刷新页面'];
        }

        $pdo = $this->db->getPdo();
        try {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->beginTransaction();
            $user = $this->db->fetch(
                'SELECT * FROM ' . DB_PREFIX . 'users WHERE id = ? FOR UPDATE',
                [$userId]
            );
            if (!$user) {
                $pdo->rollBack();
                return ['success' => false, 'message' => '用户不存在，请重新登录'];
            }

            $previous = $this->db->fetch(
                'SELECT * FROM ' . DB_PREFIX . 'draw_requests WHERE user_id = ? AND request_key = ?',
                [$userId, $requestId]
            );
            $now = date('Y-m-d H:i:s');
            $today = substr($now, 0, 10);
            $dailyLimit = self::intOrDefault($user['daily_limit'] ?? null, DEFAULT_DAILY_LIMIT, 0);
            $cardsPerDraw = self::intOrDefault($user['cards_per_draw'] ?? null, DEFAULT_CARDS_PER_DRAW, 1);
            $remaining = max(0, $dailyLimit - $this->countUserDraws($userId, $today));

            if ($previous) {
                if ((int) $previous['category_id'] !== (int) $categoryId) {
                    $pdo->rollBack();
                    return ['success' => false, 'message' => '领取请求与原分类不一致，请查看提取记录'];
                }
                $result = json_decode($previous['response'], true, 512, JSON_THROW_ON_ERROR);
                $result['remaining'] = $remaining;
                $result['replayed'] = true;
                $pdo->commit();
                return $result;
            }

            $message = '';
            $cards = [];
            if ($user['is_banned']) {
                $message = '您的账号已被禁止提取';
            } elseif ($remaining <= 0) {
                $message = "今日提取次数已用完（{$dailyLimit}次/天）";
            } else {
                $category = $this->db->fetch(
                    'SELECT * FROM ' . DB_PREFIX . 'categories WHERE id = ? FOR UPDATE',
                    [$categoryId]
                );
                if (!$category || !$category['is_active']) {
                    $message = '该分类不存在或已停用，请刷新页面';
                } else {
                    $cards = $this->db->fetchAll(
                        'SELECT c.* FROM ' . DB_PREFIX . 'cards c
                         WHERE c.category_id = ? AND ' . $this->availableCardCondition() . '
                         ORDER BY c.id ASC LIMIT ' . $cardsPerDraw . ' FOR UPDATE',
                        [$categoryId]
                    );
                    if (count($cards) < $cardsPerDraw) {
                        $message = "库存不足，本次需要 {$cardsPerDraw} 张，未发放卡密且未扣次数";
                    }
                }
            }

            $result = [
                'success' => $message === '',
                'message' => $message ?: '提取成功',
                'request_id' => $requestId,
                'remaining' => $message === '' ? $remaining - 1 : $remaining,
            ];
            if ($message === '') {
                $result['cards'] = [];
                foreach ($cards as $card) {
                    $result['cards'][] = [
                        'id' => $card['id'],
                        'category_id' => $categoryId,
                        'category_name' => $category['name'],
                        'card_code' => $card['card_code'],
                        'content' => $card['content'],
                        'status' => 'used',
                        'used_by' => $userId,
                        'used_at' => $now,
                    ];
                }
            }

            $drawId = $this->db->insert('draw_requests', [
                'user_id' => $userId,
                'request_key' => $requestId,
                'category_id' => $categoryId,
                'response' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'created_at' => $now,
            ]);
            if ($result['success']) {
                foreach ($result['cards'] as $card) {
                    $claimed = $this->db->query(
                        'UPDATE ' . DB_PREFIX . "cards SET status = 'used', used_by = ?, used_at = ? WHERE id = ? AND category_id = ? AND status = 'available'",
                        [$userId, $now, $card['id'], $categoryId]
                    );
                    if ($claimed->rowCount() !== 1) {
                        throw new RuntimeException('卡密状态已变化');
                    }
                    $this->db->insert('draw_logs', [
                        'user_id' => $userId,
                        'card_id' => $card['id'],
                        'category_id' => $categoryId,
                        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                        'created_at' => $now,
                        'request_id' => $drawId,
                        'card_code' => $card['card_code'],
                        'content' => $card['content'],
                        'category_name' => $card['category_name'],
                    ]);
                }
            }
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('drawCard failed: ' . $e->getMessage());
            return ['success' => false, 'message' => '领取结果暂未确认，请重试本次领取', 'retryable' => true];
        }
    }

    public function countUserDraws($userId, $day = null) {
        $where = 'user_id = ?';
        $params = [$userId];
        if ($day !== null) {
            $where .= ' AND created_at >= ? AND created_at < ?';
            $params[] = $day . ' 00:00:00';
            $params[] = date('Y-m-d', strtotime($day . ' +1 day')) . ' 00:00:00';
        }
        return (int) $this->db->fetch(
            'SELECT COUNT(DISTINCT COALESCE(request_id, CONCAT(\'legacy-\', id))) AS cnt FROM ' . DB_PREFIX . "draw_logs WHERE $where",
            $params
        )['cnt'];
    }

    /**
     * 获取用户提取记录
     */
    public function getUserDrawLogs($userId, $page = 1, $perPage = 20) {
        $offset = ($page - 1) * $perPage;
        
        $total = $this->db->count('draw_logs', 'user_id = ?', [$userId]);
        
        $logs = $this->db->fetchAll(
            "SELECT l.*, COALESCE(l.card_code, c.card_code, '') AS card_code,
                    COALESCE(l.content, c.content, '') AS content,
                    COALESCE(l.category_name, cat.name, '') AS category_name
             FROM " . DB_PREFIX . "draw_logs l
             LEFT JOIN " . DB_PREFIX . "cards c ON l.card_id = c.id
             LEFT JOIN " . DB_PREFIX . "categories cat ON l.category_id = cat.id
             WHERE l.user_id = ?
             ORDER BY l.created_at DESC
             LIMIT $offset, $perPage",
            [$userId]
        );
        
        return [
            'logs' => $logs,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => ceil($total / $perPage),
        ];
    }
    
    /**
     * 查询卡密（用户端）
     */
    public function queryCard($userId, $cardCode) {
        $card = $this->db->fetch(
            "SELECT l.card_id AS id, l.category_id, l.user_id AS used_by,
                    l.created_at AS used_at, 'used' AS status,
                    COALESCE(l.card_code, c.card_code, '') AS card_code,
                    COALESCE(l.content, c.content, '') AS content,
                    COALESCE(l.category_name, cat.name, '') AS category_name
             FROM " . DB_PREFIX . "draw_logs l
             LEFT JOIN " . DB_PREFIX . "cards c ON l.card_id = c.id
             LEFT JOIN " . DB_PREFIX . "categories cat ON l.category_id = cat.id
             WHERE l.user_id = ? AND (BINARY l.card_code = BINARY ? OR (l.card_code IS NULL AND BINARY c.card_code = BINARY ?))
             ORDER BY l.id DESC LIMIT 1",
            [$userId, $cardCode, $cardCode]
        );
        
        return $card;
    }
    
    /**
     * 获取统计数据
     */
    public function getStats() {
        $stats = [];
        
        $stats['total_cards'] = $this->db->count('cards');
        $stats['available_cards'] = $this->getAvailableCardCount();
        $stats['used_cards'] = $this->db->count('cards', 'status = ?', ['used']);
        $stats['total_users'] = $this->db->count('users');
        $drawCount = "COUNT(DISTINCT COALESCE(request_id, CONCAT('legacy-', id)))";
        $today = date('Y-m-d');
        $stats['total_draws'] = (int) $this->db->fetch("SELECT $drawCount AS cnt FROM " . DB_PREFIX . 'draw_logs')['cnt'];
        $stats['today_draws'] = (int) $this->db->fetch(
            "SELECT $drawCount AS cnt FROM " . DB_PREFIX . 'draw_logs WHERE created_at >= ? AND created_at < ?',
            [$today . ' 00:00:00', date('Y-m-d', strtotime($today . ' +1 day')) . ' 00:00:00']
        )['cnt'];
        
        return $stats;
    }
    
    /**
     * 取整数配置：值为 null/'' 时才回退默认值，0 会被保留（L5）
     *
     * @param mixed $value
     * @param int   $default
     * @param int   $min
     * @return int
     */
    private static function intOrDefault($value, $default, $min = 0) {
        if ($value === null || $value === '') {
            return max($min, (int) $default);
        }
        return max($min, (int) $value);
    }

    /**
     * 获取用户今日剩余提取次数
     */
    public function getUserRemainingDraws($userId) {
        $user = $this->db->fetch(
            "SELECT daily_limit FROM " . DB_PREFIX . "users WHERE id = ?",
            [$userId]
        );
        
        if (!$user) {
            return 0;
        }
        $dailyLimit = self::intOrDefault($user['daily_limit'] ?? null, DEFAULT_DAILY_LIMIT, 0);
        return max(0, $dailyLimit - $this->countUserDraws($userId, date('Y-m-d')));
    }
}
