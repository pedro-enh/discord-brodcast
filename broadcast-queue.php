<?php
require_once 'database.php';

class BroadcastQueue {
    private $db;
    
    public function __construct() {
        $this->db = new Database();
        $this->createQueueTable();
    }
    
    private function createQueueTable() {
        $sql = "CREATE TABLE IF NOT EXISTS broadcast_queue (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            discord_user_id TEXT NOT NULL,
            guild_id TEXT NOT NULL,
            message TEXT NOT NULL,
            target_type TEXT DEFAULT 'all',
            delay_seconds INTEGER DEFAULT 2,
            enable_mentions BOOLEAN DEFAULT 0,
            bot_token TEXT NOT NULL,
            status TEXT DEFAULT 'pending',
            progress INTEGER DEFAULT 0,
            total_members INTEGER DEFAULT 0,
            sent_count INTEGER DEFAULT 0,
            failed_count INTEGER DEFAULT 0,
            error_message TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            started_at DATETIME,
            completed_at DATETIME
        )";
        
        $this->db->query($sql);
    }
    
    public function addBroadcast($user_id, $discord_user_id, $guild_id, $message, $target_type, $delay, $enable_mentions, $bot_token) {
        $sql = "INSERT INTO broadcast_queue 
                (user_id, discord_user_id, guild_id, message, target_type, delay_seconds, enable_mentions, bot_token) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $params = [$user_id, $discord_user_id, $guild_id, $message, $target_type, $delay, $enable_mentions, $bot_token];
        
        return $this->db->query($sql, $params);
    }
    
    public function getNextPendingBroadcast() {
        $sql = "SELECT * FROM broadcast_queue WHERE status = 'pending' ORDER BY created_at ASC LIMIT 1";
        $result = $this->db->query($sql);
        return $result ? $result[0] : null;
    }
    
    public function updateBroadcastStatus($id, $status, $data = []) {
        $updates = ['status = ?'];
        $params = [$status, $id];
        
        if (isset($data['progress'])) {
            $updates[] = 'progress = ?';
            array_unshift($params, $data['progress']);
        }
        
        if (isset($data['total_members'])) {
            $updates[] = 'total_members = ?';
            array_unshift($params, $data['total_members']);
        }
        
        if (isset($data['sent_count'])) {
            $updates[] = 'sent_count = ?';
            array_unshift($params, $data['sent_count']);
        }
        
        if (isset($data['failed_count'])) {
            $updates[] = 'failed_count = ?';
            array_unshift($params, $data['failed_count']);
        }
        
        if (isset($data['error_message'])) {
            $updates[] = 'error_message = ?';
            array_unshift($params, $data['error_message']);
        }
        
        if ($status === 'processing') {
            $updates[] = 'started_at = CURRENT_TIMESTAMP';
        } elseif ($status === 'completed' || $status === 'failed') {
            $updates[] = 'completed_at = CURRENT_TIMESTAMP';
        }
        
        $sql = "UPDATE broadcast_queue SET " . implode(', ', $updates) . " WHERE id = ?";
        
        return $this->db->query($sql, $params);
    }
    
    public function getBroadcastStatus($id) {
        $sql = "SELECT * FROM broadcast_queue WHERE id = ?";
        $result = $this->db->query($sql, [$id]);
        return $result ? $result[0] : null;
    }
    
    public function getUserBroadcasts($discord_user_id, $limit = 10) {
        $sql = "SELECT * FROM broadcast_queue WHERE discord_user_id = ? ORDER BY created_at DESC LIMIT ?";
        return $this->db->query($sql, [$discord_user_id, $limit]);
    }
    
    public function getActiveBroadcasts() {
        $sql = "SELECT * FROM broadcast_queue WHERE status IN ('pending', 'processing') ORDER BY created_at ASC";
        return $this->db->query($sql);
    }
    
    public function deleteBroadcast($id) {
        $sql = "DELETE FROM broadcast_queue WHERE id = ?";
        return $this->db->query($sql, [$id]);
    }
}
?>
