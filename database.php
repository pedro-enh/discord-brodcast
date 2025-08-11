<?php
/**
 * Simple SQLite Database Handler for Discord Broadcaster Pro
 * Handles wallet system and credit transactions
 */

class Database {
    private $pdo;
    private $dbPath;
    
    public function __construct($dbPath = 'broadcaster.db') {
        $this->dbPath = $dbPath;
        $this->connect();
        $this->createTables();
    }
    
    public function getPdo() {
        return $this->pdo;
    }
    
    private function connect() {
        try {
            $this->pdo = new PDO("sqlite:" . $this->dbPath);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }
    
    private function createTables() {
        // Users table for wallet system
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                discord_id TEXT UNIQUE NOT NULL,
                username TEXT NOT NULL,
                discriminator TEXT NOT NULL,
                avatar TEXT,
                email TEXT,
                credits INTEGER DEFAULT 0,
                total_spent INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // Transactions table for credit history
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                discord_id TEXT NOT NULL,
                type TEXT NOT NULL, -- 'purchase', 'spend', 'refund'
                amount INTEGER NOT NULL,
                description TEXT,
                probot_transaction_id TEXT,
                status TEXT DEFAULT 'pending', -- 'pending', 'completed', 'failed'
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users (id)
            )
        ");
        
        // Broadcast history
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS broadcasts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                discord_id TEXT NOT NULL,
                guild_id TEXT NOT NULL,
                guild_name TEXT,
                message TEXT NOT NULL,
                target_type TEXT NOT NULL,
                messages_sent INTEGER DEFAULT 0,
                messages_failed INTEGER DEFAULT 0,
                credits_used INTEGER NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users (id)
            )
        ");
        
        // ProBot payment monitoring
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS payment_monitoring (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                discord_id TEXT NOT NULL,
                expected_amount INTEGER NOT NULL,
                status TEXT DEFAULT 'waiting', -- 'waiting', 'received', 'expired'
                expires_at DATETIME NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }
    
    // User management
    public function createOrUpdateUser($discordData) {
        $stmt = $this->pdo->prepare("
            INSERT OR REPLACE INTO users (discord_id, username, discriminator, avatar, email, updated_at)
            VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ");
        
        $stmt->execute([
            $discordData['id'],
            $discordData['username'],
            $discordData['discriminator'],
            $discordData['avatar'] ?? null,
            $discordData['email'] ?? null
        ]);
        
        return $this->getUserByDiscordId($discordData['id']);
    }
    
    public function getUserByDiscordId($discordId) {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE discord_id = ?");
        $stmt->execute([$discordId]);
        return $stmt->fetch();
    }
    
    // Wallet management
    public function getUserCredits($discordId) {
        $user = $this->getUserByDiscordId($discordId);
        return $user ? $user['credits'] : 0;
    }
    
    public function addCredits($discordId, $amount, $description = 'Credit purchase', $probotTransactionId = null) {
        $user = $this->getUserByDiscordId($discordId);
        if (!$user) {
            throw new Exception("User not found");
        }
        
        $this->pdo->beginTransaction();
        
        try {
            // Update user credits
            $stmt = $this->pdo->prepare("
                UPDATE users 
                SET credits = credits + ?, updated_at = CURRENT_TIMESTAMP 
                WHERE discord_id = ?
            ");
            $stmt->execute([$amount, $discordId]);
            
            // Record transaction
            $stmt = $this->pdo->prepare("
                INSERT INTO transactions (user_id, discord_id, type, amount, description, probot_transaction_id, status)
                VALUES (?, ?, 'purchase', ?, ?, ?, 'completed')
            ");
            $stmt->execute([$user['id'], $discordId, $amount, $description, $probotTransactionId]);
            
            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
    
    public function spendCredits($discordId, $amount, $description = 'Broadcast messages') {
        $user = $this->getUserByDiscordId($discordId);
        if (!$user) {
            throw new Exception("User not found");
        }
        
        if ($user['credits'] < $amount) {
            throw new Exception("Insufficient credits");
        }
        
        $this->pdo->beginTransaction();
        
        try {
            // Update user credits
            $stmt = $this->pdo->prepare("
                UPDATE users 
                SET credits = credits - ?, total_spent = total_spent + ?, updated_at = CURRENT_TIMESTAMP 
                WHERE discord_id = ?
            ");
            $stmt->execute([$amount, $amount, $discordId]);
            
            // Record transaction
            $stmt = $this->pdo->prepare("
                INSERT INTO transactions (user_id, discord_id, type, amount, description, status)
                VALUES (?, ?, 'spend', ?, ?, 'completed')
            ");
            $stmt->execute([$user['id'], $discordId, $amount, $description]);
            
            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
    
    // Transaction history
    public function getUserTransactions($discordId, $limit = 50) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM transactions 
            WHERE discord_id = ? 
            ORDER BY created_at DESC 
            LIMIT ?
        ");
        $stmt->execute([$discordId, $limit]);
        return $stmt->fetchAll();
    }
    
    // Broadcast history
    public function recordBroadcast($discordId, $guildId, $guildName, $message, $targetType, $messagesSent, $messagesFailed, $creditsUsed) {
        $user = $this->getUserByDiscordId($discordId);
        if (!$user) {
            throw new Exception("User not found");
        }
        
        $stmt = $this->pdo->prepare("
            INSERT INTO broadcasts (user_id, discord_id, guild_id, guild_name, message, target_type, messages_sent, messages_failed, credits_used)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        return $stmt->execute([
            $user['id'], $discordId, $guildId, $guildName, $message, $targetType, $messagesSent, $messagesFailed, $creditsUsed
        ]);
    }
    
    public function getUserBroadcasts($discordId, $limit = 20) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM broadcasts 
            WHERE discord_id = ? 
            ORDER BY created_at DESC 
            LIMIT ?
        ");
        $stmt->execute([$discordId, $limit]);
        return $stmt->fetchAll();
    }
    
    // Payment monitoring
    public function createPaymentMonitoring($discordId, $expectedAmount, $expiresInMinutes = 30) {
        $expiresAt = date('Y-m-d H:i:s', time() + ($expiresInMinutes * 60));
        
        $stmt = $this->pdo->prepare("
            INSERT INTO payment_monitoring (discord_id, expected_amount, expires_at)
            VALUES (?, ?, ?)
        ");
        
        $stmt->execute([$discordId, $expectedAmount, $expiresAt]);
        return $this->pdo->lastInsertId();
    }
    
    public function getPaymentMonitoring($discordId, $amount) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM payment_monitoring 
            WHERE discord_id = ? AND expected_amount = ? AND status = 'waiting' AND expires_at > CURRENT_TIMESTAMP
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$discordId, $amount]);
        return $stmt->fetch();
    }
    
    public function markPaymentReceived($id) {
        $stmt = $this->pdo->prepare("
            UPDATE payment_monitoring 
            SET status = 'received' 
            WHERE id = ?
        ");
        return $stmt->execute([$id]);
    }
    
    // Statistics
    public function getUserStats($discordId) {
        $user = $this->getUserByDiscordId($discordId);
        if (!$user) {
            return null;
        }
        
        $stats = [
            'credits' => $user['credits'],
            'total_spent' => $user['total_spent'],
            'total_broadcasts' => 0,
            'total_messages_sent' => 0
        ];
        
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as total_broadcasts, SUM(messages_sent) as total_messages_sent
            FROM broadcasts 
            WHERE discord_id = ?
        ");
        $stmt->execute([$discordId]);
        $broadcastStats = $stmt->fetch();
        
        $stats['total_broadcasts'] = $broadcastStats['total_broadcasts'] ?? 0;
        $stats['total_messages_sent'] = $broadcastStats['total_messages_sent'] ?? 0;
        
        return $stats;
    }
}
?>
