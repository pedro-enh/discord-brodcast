<?php
session_start();

// Clear all session data
session_unset();
session_destroy();

// Redirect to home page
header('Location: index.php?logout=success');
exit;
?>
