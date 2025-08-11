<?php
session_start();

// Load configuration
try {
    $config = require_once 'config.php';
} catch (Exception $e) {
    die('Configuration file not found. Please check your environment variables.');
}

// Check if user is logged in
$user = isset($_SESSION['discord_user']) ? $_SESSION['discord_user'] : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Discord Broadcaster Pro</title>
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
                    <?php if (!$user): ?>
                    <div class="login-section">
                        <a href="login.php" class="btn btn-discord">
                            <i class="fab fa-discord"></i>
                            Login with Discord
                        </a>
                    </div>
                    <?php else: ?>
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
                        <a href="wallet.php" class="btn btn-info btn-small">
                            <i class="fas fa-wallet"></i>
                            Wallet
                        </a>
                        <a href="broadcast.php" class="btn btn-primary btn-small">
                            <i class="fas fa-broadcast-tower"></i>
                            Broadcast
                        </a>
                        <a href="logout.php" class="btn btn-secondary btn-small">
                            <i class="fas fa-sign-out-alt"></i>
                            Logout
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="main-content">
            <?php if (!$user): ?>
            <!-- Welcome Section -->
            <section class="card welcome-card">
                <div class="card-header">
                    <h2><i class="fab fa-discord"></i> Welcome to Discord Broadcaster Pro</h2>
                </div>
                <div class="card-body">
                    <div class="welcome-content">
                        <p>The most powerful Discord broadcasting tool for sending mass direct messages to your server members.</p>
                        <div class="features-list">
                            <div class="feature-item">
                                <i class="fas fa-shield-alt"></i>
                                <span>Secure Discord OAuth Authentication</span>
                            </div>
                            <div class="feature-item">
                                <i class="fas fa-broadcast-tower"></i>
                                <span>Mass Direct Message Broadcasting</span>
                            </div>
                            <div class="feature-item">
                                <i class="fas fa-users"></i>
                                <span>Target Online/Offline/All Members</span>
                            </div>
                            <div class="feature-item">
                                <i class="fas fa-at"></i>
                                <span>Mention Support & Anti-Ban Protection</span>
                            </div>
                            <div class="feature-item">
                                <i class="fas fa-robot"></i>
                                <span>Advanced Bot Integration</span>
                            </div>
                            <div class="feature-item">
                                <i class="fas fa-chart-bar"></i>
                                <span>Real-time Broadcasting Statistics</span>
                            </div>
                        </div>
                        <a href="login.php" class="btn btn-discord btn-large">
                            <i class="fab fa-discord"></i>
                            Get Started - Login with Discord
                        </a>
                    </div>
                </div>
            </section>
            <?php else: ?>
            <!-- Dashboard -->
            <section class="card">
                <div class="card-header">
                    <h2><i class="fas fa-tachometer-alt"></i> Broadcasting Dashboard</h2>
                </div>
                <div class="card-body">
                    <div class="dashboard-stats">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fas fa-paper-plane"></i>
                            </div>
                            <div class="stat-info">
                                <h3>Ready to Broadcast</h3>
                                <p>Start sending messages to your Discord server members</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="quick-actions">
                        <a href="broadcast.php" class="action-card">
                            <div class="action-icon">
                                <i class="fas fa-broadcast-tower"></i>
                            </div>
                            <h3>Start Broadcasting</h3>
                            <p>Send mass DMs to server members</p>
                            <div class="action-arrow">
                                <i class="fas fa-arrow-right"></i>
                            </div>
                        </a>
                    </div>
                </div>
            </section>

            <!-- Features Overview -->
            <section class="card">
                <div class="card-header">
                    <h2><i class="fas fa-star"></i> Broadcasting Features</h2>
                </div>
                <div class="card-body">
                    <div class="feature-grid">
                        <div class="feature-card">
                            <div class="feature-icon">
                                <i class="fas fa-users"></i>
                            </div>
                            <h4>Smart Targeting</h4>
                            <p>Choose between all members, online only, or offline only</p>
                        </div>
                        
                        <div class="feature-card">
                            <div class="feature-icon">
                                <i class="fas fa-at"></i>
                            </div>
                            <h4>Mention Support</h4>
                            <p>Include user mentions and role mentions in your messages</p>
                        </div>
                        
                        <div class="feature-card">
                            <div class="feature-icon">
                                <i class="fas fa-shield-alt"></i>
                            </div>
                            <h4>Anti-Ban Protection</h4>
                            <p>Built-in rate limiting and delay system to prevent Discord bans</p>
                        </div>
                        
                        <div class="feature-card">
                            <div class="feature-icon">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <h4>Real-time Stats</h4>
                            <p>Monitor delivery success rates and failed messages</p>
                        </div>
                    </div>
                </div>
            </section>
            <?php endif; ?>
        </main>

        <!-- Toast Notifications -->
        <div class="toast-container" id="toastContainer"></div>
    </div>

    <script>
        // Show login success message if redirected from auth
        <?php if (isset($_GET['login']) && $_GET['login'] === 'success'): ?>
        showToast('Successfully logged in with Discord!', 'success');
        <?php endif; ?>

        // Toast notification function
        function showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            
            const icon = getToastIcon(type);
            toast.innerHTML = `
                <i class="${icon}"></i>
                <span>${message}</span>
            `;
            
            const container = document.getElementById('toastContainer');
            container.appendChild(toast);
            
            // Auto remove after 5 seconds
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.style.animation = 'slideIn 0.3s ease reverse';
                    setTimeout(() => {
                        if (toast.parentNode) {
                            container.removeChild(toast);
                        }
                    }, 300);
                }
            }, 5000);
        }

        function getToastIcon(type) {
            switch (type) {
                case 'success':
                    return 'fas fa-check-circle';
                case 'error':
                    return 'fas fa-times-circle';
                case 'warning':
                    return 'fas fa-exclamation-triangle';
                default:
                    return 'fas fa-info-circle';
            }
        }
    </script>
</body>
</html>
