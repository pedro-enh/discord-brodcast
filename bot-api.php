<?php
header('Content-Type: application/json');

// Get JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || !isset($data['action']) || !isset($data['token'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

$action = $data['action'];
$botToken = $data['token'];

// Function to make Discord API requests
function discordApiRequest($endpoint, $method = 'GET', $data = null, $token = null) {
    $url = 'https://discord.com/api/v10' . $endpoint;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $headers = [
        'Authorization: Bot ' . $token,
        'Content-Type: application/json'
    ];
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($response === false) {
        return ['success' => false, 'error' => 'Request failed'];
    }
    
    $decoded = json_decode($response, true);
    
    if ($httpCode >= 400) {
        $error = isset($decoded['message']) ? $decoded['message'] : 'API Error';
        return ['success' => false, 'error' => $error];
    }
    
    return ['success' => true, 'data' => $decoded];
}

// Handle different actions
switch ($action) {
    case 'test_connection':
        $result = discordApiRequest('/users/@me', 'GET', null, $botToken);
        if ($result['success']) {
            echo json_encode([
                'success' => true,
                'message' => 'Bot connection successful',
                'bot_info' => $result['data']
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => $result['error']
            ]);
        }
        break;
        
    case 'get_bot_info':
        $result = discordApiRequest('/users/@me', 'GET', null, $botToken);
        if ($result['success']) {
            $botInfo = $result['data'];
            $formattedInfo = [
                'Bot Name' => $botInfo['username'] . '#' . $botInfo['discriminator'],
                'Bot ID' => $botInfo['id'],
                'Avatar' => $botInfo['avatar'] ? 'https://cdn.discordapp.com/avatars/' . $botInfo['id'] . '/' . $botInfo['avatar'] . '.png' : 'Default',
                'Verified' => isset($botInfo['verified']) ? ($botInfo['verified'] ? 'Yes' : 'No') : 'Unknown',
                'Public Bot' => isset($botInfo['public_bot']) ? ($botInfo['public_bot'] ? 'Yes' : 'No') : 'Unknown'
            ];
            
            echo json_encode([
                'success' => true,
                'data' => $formattedInfo
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => $result['error']
            ]);
        }
        break;
        
    case 'get_servers':
        $result = discordApiRequest('/users/@me/guilds', 'GET', null, $botToken);
        if ($result['success']) {
            $servers = [];
            foreach ($result['data'] as $guild) {
                $servers[] = [
                    'name' => $guild['name'],
                    'id' => $guild['id'],
                    'member_count' => isset($guild['approximate_member_count']) ? $guild['approximate_member_count'] : 'Unknown',
                    'icon' => $guild['icon'] ? 'https://cdn.discordapp.com/icons/' . $guild['id'] . '/' . $guild['icon'] . '.png' : 'No icon'
                ];
            }
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'server_count' => count($servers),
                    'servers' => $servers
                ]
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => $result['error']
            ]);
        }
        break;
        
    case 'send_message':
        if (!isset($data['channel_id']) || !isset($data['message'])) {
            echo json_encode(['success' => false, 'error' => 'Missing channel_id or message']);
            break;
        }
        
        $channelId = $data['channel_id'];
        $message = $data['message'];
        
        $messageData = [
            'content' => $message
        ];
        
        $result = discordApiRequest('/channels/' . $channelId . '/messages', 'POST', $messageData, $botToken);
        
        if ($result['success']) {
            echo json_encode([
                'success' => true,
                'message' => 'Message sent successfully',
                'message_id' => $result['data']['id']
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => $result['error']
            ]);
        }
        break;
        
    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
        break;
}
?>
