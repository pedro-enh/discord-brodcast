#!/usr/bin/env php
<?php
/**
 * Discord Broadcaster Pro - Broadcast Worker Starter
 * This script starts the broadcast processing worker
 */

// Check if running from command line
if (php_sapi_name() !== 'cli') {
    die('This script must be run from the command line.');
}

echo "🚀 Discord Broadcaster Pro - Broadcast Worker\n";
echo "============================================\n\n";

// Check if required files exist
$requiredFiles = [
    'broadcast-worker.php',
    'broadcast-queue.php',
    'database.php'
];

foreach ($requiredFiles as $file) {
    if (!file_exists($file)) {
        echo "❌ Required file missing: {$file}\n";
        exit(1);
    }
}

// Check if database directory is writable
$dbPath = 'broadcaster.db';
$dbDir = dirname($dbPath);

if (!is_writable($dbDir)) {
    echo "❌ Database directory is not writable: {$dbDir}\n";
    echo "Please ensure the directory has write permissions.\n";
    exit(1);
}

echo "📊 Configuration:\n";
echo "   - Database: {$dbPath}\n";
echo "   - Worker: broadcast-worker.php\n";
echo "   - Queue: broadcast-queue.php\n";
echo "\n";

// Check for existing worker process
$pidFile = 'broadcast-worker.pid';
if (file_exists($pidFile)) {
    $pid = trim(file_get_contents($pidFile));
    if ($pid && posix_kill($pid, 0)) {
        echo "⚠️  Worker already running with PID: {$pid}\n";
        echo "To stop the existing worker, run: kill {$pid}\n";
        exit(1);
    } else {
        // Remove stale PID file
        unlink($pidFile);
    }
}

// Start the worker
echo "🔄 Starting broadcast worker...\n";
echo "Press Ctrl+C to stop the worker gracefully.\n";
echo "\n";

// Save PID
file_put_contents($pidFile, getmypid());

// Handle signals for graceful shutdown
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function() use ($pidFile) {
        echo "\n🛑 Received SIGTERM, shutting down gracefully...\n";
        if (file_exists($pidFile)) {
            unlink($pidFile);
        }
        exit(0);
    });
    
    pcntl_signal(SIGINT, function() use ($pidFile) {
        echo "\n🛑 Received SIGINT, shutting down gracefully...\n";
        if (file_exists($pidFile)) {
            unlink($pidFile);
        }
        exit(0);
    });
}

// Register shutdown function
register_shutdown_function(function() use ($pidFile) {
    if (file_exists($pidFile)) {
        unlink($pidFile);
    }
});

try {
    // Include and start the worker
    require_once 'broadcast-worker.php';
    
    $worker = new BroadcastWorker();
    $worker->processQueue();
    
} catch (Exception $e) {
    echo "❌ Worker error: " . $e->getMessage() . "\n";
    if (file_exists($pidFile)) {
        unlink($pidFile);
    }
    exit(1);
}

echo "✅ Broadcast worker stopped.\n";
if (file_exists($pidFile)) {
    unlink($pidFile);
}
?>
