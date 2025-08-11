<?php
session_start();

// Simple auth handler that just stores the code and lets JavaScript handle the rest
if (isset($_GET['code'])) {
    // Store the authorization code temporarily
    $_SESSION['auth_code'] = $_GET['code'];
    
    // Redirect to a page that will handle the token exchange with JavaScript
    header('Location: complete-auth.php');
    exit;
} else {
    // No code received, redirect back with error
    header('Location: index.php?error=no_code');
    exit;
}
?>
