<?php
session_start();

// Check if we have an auth code
if (!isset($_SESSION['auth_code'])) {
    header('Location: index.php?error=no_auth_code');
    exit;
}

$auth_code = $_SESSION['auth_code'];
// Clear the code from session for security
unset($_SESSION['auth_code']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Completing Login - Discord Broadcaster Pro</title>
    <link rel="stylesheet" href="styles.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <div class="app-container">
        <div class="loading-container">
            <div class="loading-card">
                <div class="loading-spinner">
                    <i class="fab fa-discord"></i>
                </div>
                <h2>Completing Discord Login...</h2>
                <p>Please wait while we finish setting up your account.</p>
                <div class="progress-bar">
                    <div class="progress-fill" id="progressFill"></div>
                </div>
                <div id="statusText">Exchanging authorization code...</div>
            </div>
        </div>
    </div>

    <script>
        // Discord configuration
        const DISCORD_CLIENT_ID = '1404415002269712394';
        const DISCORD_CLIENT_SECRET = 'xxEFeapSbG0SOhPNxsoQxMFZCCpj2ZgX';
        const REDIRECT_URI = 'https://brodcast-ds-production.up.railway.app/auth-simple.php';
        const AUTH_CODE = '<?php echo htmlspecialchars($auth_code); ?>';

        let progress = 0;
        const progressFill = document.getElementById('progressFill');
        const statusText = document.getElementById('statusText');

        function updateProgress(percent, text) {
            progress = percent;
            progressFill.style.width = percent + '%';
            statusText.textContent = text;
        }

        async function completeAuth() {
            try {
                updateProgress(25, 'Exchanging authorization code...');

                // Exchange code for access token
                const tokenResponse = await fetch('https://discord.com/api/oauth2/token', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        client_id: DISCORD_CLIENT_ID,
                        client_secret: DISCORD_CLIENT_SECRET,
                        grant_type: 'authorization_code',
                        code: AUTH_CODE,
                        redirect_uri: REDIRECT_URI
                    })
                });

                if (!tokenResponse.ok) {
                    throw new Error('Failed to get access token');
                }

                const tokenData = await tokenResponse.json();
                updateProgress(50, 'Getting user information...');

                // Get user information
                const userResponse = await fetch('https://discord.com/api/users/@me', {
                    headers: {
                        'Authorization': `Bearer ${tokenData.access_token}`
                    }
                });

                if (!userResponse.ok) {
                    throw new Error('Failed to get user information');
                }

                const userData = await userResponse.json();
                updateProgress(75, 'Setting up your session...');

                // Create avatar URL
                const avatarUrl = userData.avatar 
                    ? `https://cdn.discordapp.com/avatars/${userData.id}/${userData.avatar}.png`
                    : `https://cdn.discordapp.com/embed/avatars/${parseInt(userData.discriminator) % 5}.png`;

                // Send user data to PHP to store in session
                const sessionResponse = await fetch('store-session.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        id: userData.id,
                        username: userData.username,
                        discriminator: userData.discriminator,
                        avatar: userData.avatar,
                        avatar_url: avatarUrl,
                        access_token: tokenData.access_token
                    })
                });

                if (!sessionResponse.ok) {
                    throw new Error('Failed to store session');
                }

                updateProgress(100, 'Login successful! Redirecting...');

                // Redirect to main page
                setTimeout(() => {
                    window.location.href = 'index.php?login=success';
                }, 1000);

            } catch (error) {
                console.error('Auth error:', error);
                statusText.textContent = 'Login failed: ' + error.message;
                setTimeout(() => {
                    window.location.href = 'index.php?error=auth_failed';
                }, 3000);
            }
        }

        // Start the authentication process
        completeAuth();
    </script>

    <style>
        .loading-container {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .loading-card {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            text-align: center;
            max-width: 400px;
            width: 90%;
        }

        .loading-spinner {
            font-size: 3rem;
            color: #5865f2;
            margin-bottom: 1rem;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        .progress-bar {
            width: 100%;
            height: 8px;
            background: #e0e0e0;
            border-radius: 4px;
            margin: 1rem 0;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #5865f2, #7289da);
            border-radius: 4px;
            transition: width 0.3s ease;
            width: 0%;
        }

        #statusText {
            color: #666;
            font-size: 0.9rem;
            margin-top: 1rem;
        }
    </style>
</body>
</html>
