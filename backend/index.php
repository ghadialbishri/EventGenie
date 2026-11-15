<?php
/**
 * EventGenie Backend Index
 */

header('Content-Type: text/html; charset=utf-8');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EventGenie Backend</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        .container { max-width: 800px; margin: 0 auto; }
        .endpoint { background: #f4f4f4; padding: 10px; margin: 10px 0; border-radius: 5px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>EventGenie Backend API</h1>
        <p>Smart Event Planning with Personalized Recommendations</p>

        <h2>Setup Instructions</h2>
        <ol>
            <li>Run <code>php setup_db.php run</code> to create database and tables</li>
            <li>Run <code>php init_data.php run</code> to populate with Saudi Arabia sample data</li>
            <li>API endpoints are available at <code>/api.php</code></li>
        </ol>

        <h2>API Endpoints</h2>
        <div class="endpoint">
            <strong>Authentication:</strong><br>
            POST /api.php/login - User login<br>
            POST /api.php/signup - User registration
        </div>

        <div class="endpoint">
            <strong>Resources:</strong><br>
            GET/POST /api.php/users - User management<br>
            GET/POST /api.php/events - Event management<br>
            GET/POST /api.php/feedbacks - Feedback management<br>
            GET/POST /api.php/bookings - Booking management
        </div>

        <h2>Sample Data</h2>
        <p>The system includes comprehensive sample data for Saudi Arabia:</p>
        <ul>
            <li>20 users with Saudi names and locations</li>
            <li>50 events across various types</li>
            <li>100 feedback entries</li>
            <li>30 booking records</li>
            <li>AI Recommendations for Venues,Themes,Food,Entertainment</li>
        </ul>

        <p><a href="README.md">View full documentation</a></p>
    </div>
</body>
</html>
