<?php
/**
 * Webhook endpoint for ProBot payment monitoring
 * Can be called by external services or cron jobs
 */

header('Content-Type: application/json');

// Security check - optional webhook secret
$webhookSecret = $_ENV['WEBHOOK_SECRET'] ?? 'your_webhook_secret';
$providedSecret = $_GET['secret'] ?? $_POST['secret'] ?? '';

if ($providedSecret !== $webhookSecret) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once 'probot-monitor.php';

try {
    $monitor = new ProbotMonitor();
    $monitor->run();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
