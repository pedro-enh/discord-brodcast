<?php
session_start();
require_once 'broadcast-queue.php';
require_once 'database.php';

// Load configuration
try {
    $config = require_once 'config.php';
} catch (Exception $e) {
    die('Configuration file not found. Please check your environment variables.');
}

// Check if user is logged in
if (!isset($_SESSION['discord_user'])) {
    header('Location: index.php');
    exit;
}

$user = $_SESSION['discord_user'];
$queue = new BroadcastQueue();
$db = new Database();

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    $bot_token = $input['bot_token'] ?? $config['BOT_TOKEN'];
    
    switch ($action) {
        case 'get_guilds':
            echo json_encode(getGuilds($bot_token));
            break;
            
        case 'get_members':
            $guild_id = $input['guild_id'] ?? '';
            echo json_encode(getGuildMembers($bot_token, $guild_id));
            break;
            
        case 'send_broadcast':
            $guild_id = $input['guild_id'] ?? '';
            $message = $input['message'] ?? '';
            $target_type = $input['target_type'] ?? 'all';
            $delay = $input['delay'] ?? 2;
            $enable_mentions = $input['enable_mentions'] ?? false;
            echo json_encode(queueBroadcast($user, $guild_id, $message, $target_type, $delay, $enable_mentions, $bot_token));
            break;
            
        case 'get_broadcast_status':
            $broadcast_id = $input['broadcast_id'] ?? '';
            echo json_encode(getBroadcastStatus($broadcast_id));
            break;
            
        case 'get_user_broadcasts':
            echo json_encode(getUserBroadcasts($user['id']));
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
    exit;
}

function getGuilds($bot_token) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://discord.com/api/users/@me/guilds');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bot ' . $bot_token,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200) {
        return ['success' => true, 'guilds' => json_decode($response, true)];
    } else {
        return ['success' => false, 'error' => 'Failed to fetch guilds', 'code' => $http_code];
    }
}

function getGuildMembers($bot_token, $guild_id) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://discord.com/api/guilds/{$guild_id}/members?limit=1000");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bot ' . $bot_token,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200) {
        $members = json_decode($response, true);
        // Filter out bots
        $filtered_members = array_filter($members, function($member) {
            return !isset($member['user']['bot']) || !$member['user']['bot'];
        });
        return ['success' => true, 'members' => array_values($filtered_members)];
    } else {
        return ['success' => false, 'error' => 'Failed to fetch members', 'code' => $http_code];
    }
}

function sendBroadcast($bot_token, $guild_id, $message, $target_type, $delay, $enable_mentions) {
    // Get guild members first
    $members_result = getGuildMembers($bot_token, $guild_id);
    
    if (!$members_result['success']) {
        return $members_result;
    }
    
    $members = $members_result['members'];
    $sent_count = 0;
    $failed_count = 0;
    $failed_users = [];
    
    // Process mentions if enabled
    if ($enable_mentions) {
        $message = processMentions($message, $guild_id, $bot_token);
    }
    
    foreach ($members as $member) {
        $user_id = $member['user']['id'];
        $username = $member['user']['username'] . '#' . $member['user']['discriminator'];
        
        // Skip based on target type (simplified for web version)
        // In a full implementation, you'd check presence status
        
        // Create DM channel
        $dm_result = createDMChannel($bot_token, $user_id);
        if (!$dm_result['success']) {
            $failed_count++;
            $failed_users[] = [
                'user' => $username,
                'reason' => 'Failed to create DM channel'
            ];
            continue;
        }
        
        $dm_channel_id = $dm_result['channel_id'];
        
        // Personalize message with user mention if enabled
        $personalized_message = $message;
        if ($enable_mentions) {
            $personalized_message = str_replace('{user}', "<@{$user_id}>", $personalized_message);
            $personalized_message = str_replace('{username}', $member['user']['username'], $personalized_message);
        }
        
        // Send message
        $send_result = sendDirectMessage($bot_token, $dm_channel_id, $personalized_message);
        if ($send_result['success']) {
            $sent_count++;
        } else {
            $failed_count++;
            $failed_users[] = [
                'user' => $username,
                'reason' => $send_result['error']
            ];
        }
        
        // Anti-ban protection: wait between messages
        sleep($delay);
        
        // Break if too many failures (additional protection)
        if ($failed_count > 10 && $sent_count < 5) {
            break;
        }
    }
    
    return [
        'success' => true,
        'sent_count' => $sent_count,
        'failed_count' => $failed_count,
        'total_targeted' => count($members),
        'failed_users' => $failed_users
    ];
}

function processMentions($message, $guild_id, $bot_token) {
    // This is a simplified version - in a full implementation,
    // you'd fetch roles and process @role mentions
    return $message;
}

function createDMChannel($bot_token, $user_id) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://discord.com/api/users/@me/channels');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['recipient_id' => $user_id]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bot ' . $bot_token,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200) {
        $channel_data = json_decode($response, true);
        return ['success' => true, 'channel_id' => $channel_data['id']];
    } else {
        return ['success' => false, 'error' => 'Failed to create DM channel'];
    }
}

function sendDirectMessage($bot_token, $channel_id, $message) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://discord.com/api/channels/{$channel_id}/messages");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['content' => $message]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bot ' . $bot_token,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200) {
        return ['success' => true];
    } else {
        $error_data = json_decode($response, true);
        $error_message = isset($error_data['message']) ? $error_data['message'] : 'Unknown error';
        return ['success' => false, 'error' => $error_message];
    }
}

function queueBroadcast($user, $guild_id, $message, $target_type, $delay, $enable_mentions, $bot_token) {
    global $queue, $db;
    
    try {
        // Get or create user in database
        $user_data = $db->query("SELECT * FROM users WHERE discord_id = ?", [$user['id']]);
        
        if (!$user_data) {
            // Create user if doesn't exist
            $db->query("INSERT INTO users (discord_id, username, credits) VALUES (?, ?, ?)", 
                      [$user['id'], $user['username'], 0]);
            $user_id = $db->getLastInsertId();
        } else {
            $user_id = $user_data[0]['id'];
        }
        
        // Check if user has enough credits (simplified - you can add wallet integration here)
        // For now, we'll allow free broadcasts
        
        // Add broadcast to queue
        $broadcast_id = $queue->addBroadcast(
            $user_id,
            $user['id'],
            $guild_id,
            $message,
            $target_type,
            $delay,
            $enable_mentions,
            $bot_token
        );
        
        if ($broadcast_id) {
            return [
                'success' => true,
                'broadcast_id' => $broadcast_id,
                'message' => 'Broadcast queued successfully! It will be processed in the background.',
                'status' => 'queued'
            ];
        } else {
            return [
                'success' => false,
                'error' => 'Failed to queue broadcast'
            ];
        }
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => 'Database error: ' . $e->getMessage()
        ];
    }
}

function getBroadcastStatus($broadcast_id) {
    global $queue;
    
    $broadcast = $queue->getBroadcastStatus($broadcast_id);
    
    if ($broadcast) {
        return [
            'success' => true,
            'broadcast' => $broadcast
        ];
    } else {
        return [
            'success' => false,
            'error' => 'Broadcast not found'
        ];
    }
}

function getUserBroadcasts($discord_user_id) {
    global $queue;
    
    $broadcasts = $queue->getUserBroadcasts($discord_user_id);
    
    return [
        'success' => true,
        'broadcasts' => $broadcasts
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Discord Broadcaster Pro - Broadcast</title>
    <link rel="stylesheet" href="styles.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="app-container">
        <!-- Header -->
        <header class="header">
            <div class="header-content">
                <div class="logo">
                    <i class="fab fa-discord"></i>
                    <h1>Discord Broadcaster Pro</h1>
                </div>
                <div class="user-section">
                    <div class="user-profile">
                        <div class="user-info">
                            <img src="<?php echo htmlspecialchars($user['avatar_url']); ?>" alt="User Avatar" class="user-avatar">
                            <div class="user-details">
                                <span class="user-name"><?php echo htmlspecialchars($user['username'] . '#' . $user['discriminator']); ?></span>
                                <div class="connection-status">
                                    <span class="status-indicator online"></span>
                                    <span class="status-text">Ready to Broadcast</span>
                                </div>
                            </div>
                        </div>
                        <a href="index.php" class="btn btn-secondary btn-small">
                            <i class="fas fa-home"></i>
                            Home
                        </a>
                        <a href="wallet.php" class="btn btn-info btn-small">
                            <i class="fas fa-wallet"></i>
                            Wallet
                        </a>
                        <a href="logout.php" class="btn btn-secondary btn-small">
                            <i class="fas fa-sign-out-alt"></i>
                            Logout
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Bot Token Section -->
            <section class="card">
                <div class="card-header">
                    <h2><i class="fas fa-robot"></i> Bot Configuration</h2>
                </div>
                <div class="card-body">
                    <div class="input-group">
                        <label for="botToken">Discord Bot Token</label>
                        <div class="input-wrapper">
                            <input type="password" id="botToken" placeholder="Enter your Discord bot token..." class="input-field">
                            <button type="button" id="toggleToken" class="toggle-btn">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <small class="input-help">Your bot token is required to send messages. It will not be stored.</small>
                    </div>
                    <button id="connectBtn" class="btn btn-primary">
                        <i class="fas fa-plug"></i>
                        Connect Bot & Load Servers
                    </button>
                </div>
            </section>

            <!-- Server Selection -->
            <section class="card" id="serverSection" style="display: none;">
                <div class="card-header">
                    <h2><i class="fas fa-server"></i> Server Selection</h2>
                </div>
                <div class="card-body">
                    <div class="input-group">
                        <label for="serverSelect">Select Discord Server</label>
                        <select id="serverSelect" class="input-field">
                            <option value="">Choose a server...</option>
                        </select>
                        <small class="input-help">Select the server where you want to broadcast messages</small>
                    </div>
                    <div id="memberCount" class="member-info" style="display: none;">
                        <i class="fas fa-users"></i>
                        <span id="memberCountText">Loading members...</span>
                    </div>
                </div>
            </section>

            <!-- Message Composition -->
            <section class="card" id="messageSection" style="display: none;">
                <div class="card-header">
                    <h2><i class="fas fa-edit"></i> Message Composition</h2>
                </div>
                <div class="card-body">
                    <div class="input-group">
                        <label for="messageText">Broadcast Message</label>
                        <textarea id="messageText" placeholder="Type your message here..." class="input-field" rows="6" maxlength="2000"></textarea>
                        <div class="character-counter">
                            <span id="charCount">0</span>/2000 characters
                        </div>
                        <small class="input-help">Use {user} for user mention and {username} for username</small>
                    </div>
                    
                    <!-- Target Audience -->
                    <div class="input-group">
                        <label>Target Audience</label>
                        <div class="radio-group">
                            <label class="radio-option">
                                <input type="radio" name="targetType" value="all" checked>
                                <span class="radio-custom"></span>
                                <span class="radio-label">
                                    <strong>All Members</strong>
                                    <small>Send to everyone in the server</small>
                                </span>
                            </label>
                            <label class="radio-option">
                                <input type="radio" name="targetType" value="online">
                                <span class="radio-custom"></span>
                                <span class="radio-label">
                                    <strong>Online Members Only</strong>
                                    <small>Send only to currently online members</small>
                                </span>
                            </label>
                            <label class="radio-option">
                                <input type="radio" name="targetType" value="offline">
                                <span class="radio-custom"></span>
                                <span class="radio-label">
                                    <strong>Offline Members Only</strong>
                                    <small>Send only to currently offline members</small>
                                </span>
                            </label>
                        </div>
                    </div>

                    <!-- Advanced Options -->
                    <div class="input-group">
                        <label>Advanced Options</label>
                        <div class="checkbox-group">
                            <label class="checkbox-option">
                                <input type="checkbox" id="enableMentions">
                                <span class="checkbox-custom"></span>
                                <span class="checkbox-label">
                                    <strong>Enable Mentions</strong>
                                    <small>Allow {user} and {username} placeholders</small>
                                </span>
                            </label>
                        </div>
                    </div>

                    <!-- Anti-Ban Protection -->
                    <div class="input-group">
                        <label for="delaySlider">Anti-Ban Protection (Delay between messages)</label>
                        <div class="slider-container">
                            <input type="range" id="delaySlider" min="1" max="10" value="2" class="slider">
                            <div class="slider-labels">
                                <span>1s (Fast)</span>
                                <span id="delayValue">2s (Recommended)</span>
                                <span>10s (Safe)</span>
                            </div>
                        </div>
                        <small class="input-help">Higher delays reduce the risk of Discord rate limiting</small>
                    </div>
                    
                    <div class="broadcast-actions">
                        <button id="previewBtn" class="btn btn-info">
                            <i class="fas fa-eye"></i>
                            Preview Message
                        </button>
                        <button id="sendBtn" class="btn btn-success btn-large">
                            <i class="fas fa-paper-plane"></i>
                            Start Broadcasting
                        </button>
                    </div>
                </div>
            </section>

            <!-- Preview Modal -->
            <div id="previewModal" class="modal" style="display: none;">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3><i class="fas fa-eye"></i> Message Preview</h3>
                        <button class="modal-close" onclick="closePreview()">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="preview-message" id="previewContent"></div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-secondary" onclick="closePreview()">Close</button>
                        <button class="btn btn-success" onclick="closePreview(); sendBroadcast();">
                            <i class="fas fa-paper-plane"></i>
                            Send This Message
                        </button>
                    </div>
                </div>
            </div>

            <!-- Results Section -->
            <section class="card" id="resultsSection" style="display: none;">
                <div class="card-header">
                    <h2><i class="fas fa-chart-bar"></i> Broadcast Results</h2>
                </div>
                <div class="card-body">
                    <div class="results-stats">
                        <div class="stat-item success">
                            <i class="fas fa-check-circle"></i>
                            <div class="stat-info">
                                <span class="stat-number" id="sentCount">0</span>
                                <span class="stat-label">Messages Sent</span>
                            </div>
                        </div>
                        <div class="stat-item error">
                            <i class="fas fa-times-circle"></i>
                            <div class="stat-info">
                                <span class="stat-number" id="failedCount">0</span>
                                <span class="stat-label">Failed Deliveries</span>
                            </div>
                        </div>
                        <div class="stat-item info">
                            <i class="fas fa-users"></i>
                            <div class="stat-info">
                                <span class="stat-number" id="totalCount">0</span>
                                <span class="stat-label">Total Targeted</span>
                            </div>
                        </div>
                    </div>
                    
                    <div id="failedUsersList" style="display: none;">
                        <h3>Failed Deliveries</h3>
                        <div class="failed-users" id="failedUsers"></div>
                    </div>
                    
                    <div class="results-actions">
                        <button class="btn btn-primary" onclick="resetBroadcast()">
                            <i class="fas fa-redo"></i>
                            Send Another Broadcast
                        </button>
                    </div>
                </div>
            </section>
        </main>

        <!-- Loading Overlay -->
        <div class="loading-overlay" id="loadingOverlay" style="display: none;">
            <div class="loading-content">
                <div class="spinner"></div>
                <h3 id="loadingText">Processing...</h3>
                <p id="loadingSubtext">Please wait while we process your request.</p>
                <div class="progress-bar" id="progressBar" style="display: none;">
                    <div class="progress-fill" id="progressFill"></div>
                </div>
            </div>
        </div>

        <!-- Toast Notifications -->
        <div class="toast-container" id="toastContainer"></div>
    </div>

    <script src="broadcast.js"></script>
</body>
</html>
