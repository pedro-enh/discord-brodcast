<?php
require_once 'broadcast-queue.php';

class BroadcastWorker {
    private $queue;
    private $isRunning = false;
    
    public function __construct() {
        $this->queue = new BroadcastQueue();
    }
    
    public function processQueue() {
        $this->isRunning = true;
        
        while ($this->isRunning) {
            $broadcast = $this->queue->getNextPendingBroadcast();
            
            if ($broadcast) {
                $this->processBroadcast($broadcast);
            } else {
                // No pending broadcasts, wait a bit
                sleep(5);
            }
        }
    }
    
    private function processBroadcast($broadcast) {
        try {
            // Mark as processing
            $this->queue->updateBroadcastStatus($broadcast['id'], 'processing');
            
            // Get guild members
            $members_result = $this->getGuildMembers($broadcast['bot_token'], $broadcast['guild_id']);
            
            if (!$members_result['success']) {
                $this->queue->updateBroadcastStatus($broadcast['id'], 'failed', [
                    'error_message' => $members_result['error']
                ]);
                return;
            }
            
            $members = $members_result['members'];
            $total_members = count($members);
            
            // Update total members count
            $this->queue->updateBroadcastStatus($broadcast['id'], 'processing', [
                'total_members' => $total_members
            ]);
            
            $sent_count = 0;
            $failed_count = 0;
            $processed = 0;
            
            foreach ($members as $member) {
                $user_id = $member['user']['id'];
                $username = $member['user']['username'] . '#' . $member['user']['discriminator'];
                
                // Create DM channel
                $dm_result = $this->createDMChannel($broadcast['bot_token'], $user_id);
                if (!$dm_result['success']) {
                    $failed_count++;
                } else {
                    $dm_channel_id = $dm_result['channel_id'];
                    
                    // Personalize message
                    $personalized_message = $broadcast['message'];
                    if ($broadcast['enable_mentions']) {
                        $personalized_message = str_replace('{user}', "<@{$user_id}>", $personalized_message);
                        $personalized_message = str_replace('{username}', $member['user']['username'], $personalized_message);
                    }
                    
                    // Send message
                    $send_result = $this->sendDirectMessage($broadcast['bot_token'], $dm_channel_id, $personalized_message);
                    if ($send_result['success']) {
                        $sent_count++;
                    } else {
                        $failed_count++;
                    }
                }
                
                $processed++;
                $progress = round(($processed / $total_members) * 100);
                
                // Update progress every 10 messages or at the end
                if ($processed % 10 === 0 || $processed === $total_members) {
                    $this->queue->updateBroadcastStatus($broadcast['id'], 'processing', [
                        'progress' => $progress,
                        'sent_count' => $sent_count,
                        'failed_count' => $failed_count
                    ]);
                }
                
                // Anti-ban protection: wait between messages
                sleep($broadcast['delay_seconds']);
                
                // Break if too many failures
                if ($failed_count > 10 && $sent_count < 5) {
                    break;
                }
            }
            
            // Mark as completed
            $this->queue->updateBroadcastStatus($broadcast['id'], 'completed', [
                'progress' => 100,
                'sent_count' => $sent_count,
                'failed_count' => $failed_count
            ]);
            
        } catch (Exception $e) {
            // Mark as failed
            $this->queue->updateBroadcastStatus($broadcast['id'], 'failed', [
                'error_message' => $e->getMessage()
            ]);
        }
    }
    
    private function getGuildMembers($bot_token, $guild_id) {
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
    
    private function createDMChannel($bot_token, $user_id) {
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
    
    private function sendDirectMessage($bot_token, $channel_id, $message) {
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
    
    public function stop() {
        $this->isRunning = false;
    }
}

// If this file is run directly, start the worker
if (php_sapi_name() === 'cli') {
    $worker = new BroadcastWorker();
    
    // Handle signals for graceful shutdown
    if (function_exists('pcntl_signal')) {
        pcntl_signal(SIGTERM, function() use ($worker) {
            echo "Received SIGTERM, shutting down gracefully...\n";
            $worker->stop();
        });
        
        pcntl_signal(SIGINT, function() use ($worker) {
            echo "Received SIGINT, shutting down gracefully...\n";
            $worker->stop();
        });
    }
    
    echo "Starting broadcast worker...\n";
    $worker->processQueue();
    echo "Broadcast worker stopped.\n";
}
?>
