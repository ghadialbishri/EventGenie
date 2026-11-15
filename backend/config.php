<?php
// Database configuration for EventGenie (Saudi Arabia)
define('DB_HOST', 'localhost');
define('DB_NAME', 'eventgenie_mvp');
define('DB_USER', 'root');
define('DB_PASS', '');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// SMTP Configuration for Email Verification
// Option 1: Use PHP mail() function (works on most servers, but may go to spam)
// Option 2: Use SMTP (recommended for production)

// Set to 'mail' for PHP mail() function or 'smtp' for SMTP
// For Gmail, you MUST use 'smtp' - mail() function won't work with Gmail
define('EMAIL_METHOD', 'smtp'); // Use 'smtp' for Gmail

// SMTP Settings (only used if EMAIL_METHOD is 'smtp')
define('SMTP_HOST', 'smtp.gmail.com'); // Gmail: smtp.gmail.com
define('SMTP_PORT', 587); // Gmail: 587 (TLS) or 465 (SSL)
define('SMTP_USER', ''); // Your Gmail address (leave empty to skip email verification)
define('SMTP_PASS', ''); // Your Gmail App Password. Never commit a real one
define('SMTP_SECURE', 'tls'); // 'tls' for port 587, 'ssl' for port 465

// Email sender information
define('SMTP_FROM_EMAIL', ''); // Must match SMTP_USER for Gmail
define('SMTP_FROM_NAME', 'EventGenie'); // Sender name displayed in emails

// For Gmail: You need to generate an "App Password" instead of your regular password
// Go to: Google Account > Security > 2-Step Verification > App passwords
?>
