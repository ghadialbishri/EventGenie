<?php
// Suppress error display to prevent HTML output before JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Start output buffering to catch any unexpected output
ob_start();

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];

/**
 * Send verification email with 6-digit code
 * @param string $email Recipient email address
 * @param string $code 6-digit verification code
 * @return bool True if email sent successfully, false otherwise
 */
function sendVerificationEmail($email, $code) {
    $subject = "EventGenie - Email Verification Code";
    
    // Email body with HTML formatting
    $message = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #059669 0%, #7C3AED 100%); color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0; }
            .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 5px 5px; }
            .code-box { background: white; border: 2px dashed #059669; padding: 20px; text-align: center; margin: 20px 0; border-radius: 5px; }
            .code { font-size: 32px; font-weight: bold; color: #059669; letter-spacing: 5px; }
            .footer { text-align: center; margin-top: 20px; color: #666; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>EventGenie</h1>
            </div>
            <div class='content'>
                <h2>Email Verification</h2>
                <p>Thank you for signing up for EventGenie! Please verify your email address by entering the code below:</p>
                <div class='code-box'>
                    <div class='code'>{$code}</div>
                </div>
                <p><strong>This code will expire in 30 minutes.</strong></p>
                <p>If you didn't create an account with EventGenie, please ignore this email.</p>
            </div>
            <div class='footer'>
                <p>&copy; 2025 EventGenie. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    // Check which email method to use
    $emailMethod = defined('EMAIL_METHOD') ? EMAIL_METHOD : 'mail';
    
    if ($emailMethod === 'smtp' && !empty(SMTP_USER) && !empty(SMTP_PASS)) {
        // Use SMTP with socket connections
        return sendEmailViaSMTP($email, $subject, $message);
    } else {
        // Use PHP mail() function (default)
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        $headers .= "From: " . SMTP_FROM_NAME . " <" . SMTP_FROM_EMAIL . ">" . "\r\n";
        $headers .= "Reply-To: " . SMTP_FROM_EMAIL . "\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();
        
        $result = mail($email, $subject, $message, $headers);
        
        if (!$result) {
            error_log("Failed to send verification email to: " . $email);
        }
        
        return $result;
    }
}

/**
 * Send email via SMTP using socket connections
 * @param string $to Recipient email
 * @param string $subject Email subject
 * @param string $message HTML email body
 * @return bool True if sent successfully
 */
function sendEmailViaSMTP($to, $subject, $message) {
    $error_detail = '';
    try {
        $host = SMTP_HOST;
        $port = SMTP_PORT;
        $user = SMTP_USER;
        $pass = SMTP_PASS;
        $from_email = SMTP_FROM_EMAIL;
        $from_name = SMTP_FROM_NAME;
        $secure = defined('SMTP_SECURE') ? SMTP_SECURE : 'tls';
        
        // Connect to SMTP server using stream_socket_client (better for TLS)
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ]);
        
        $smtp = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
        if (!$smtp) {
            $error_detail = "Connection failed: $errstr (Error #$errno). Check if port $port is open and $host is reachable.";
            error_log("SMTP Connection failed: $error_detail");
            return false;
        }
        
        // Set timeout for socket operations
        stream_set_timeout($smtp, 30);
        
        // Read server greeting
        $response = fgets($smtp, 515);
        if (!$response || substr(trim($response), 0, 3) != '220') {
            $error_detail = "Server greeting failed: " . trim($response);
            error_log("SMTP Error: $error_detail");
            fclose($smtp);
            return false;
        }
        
        // Send EHLO
        fputs($smtp, "EHLO $host\r\n");
        $response = '';
        $line = '';
        while ($line = fgets($smtp, 515)) {
            $response .= $line;
            if (substr(trim($line), 3, 1) == ' ') break; // Last line of multiline response
        }
        
        // Start TLS if required
        if ($secure === 'tls' && $port == 587) {
            fputs($smtp, "STARTTLS\r\n");
            $response = fgets($smtp, 515);
            if (!$response || substr(trim($response), 0, 3) != '220') {
                $error_detail = "STARTTLS failed: " . trim($response);
                error_log("SMTP STARTTLS failed: $error_detail");
                fclose($smtp);
                return false;
            }
            // Suppress warnings for TLS handshake
            $tls_result = @stream_socket_enable_crypto($smtp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if (!$tls_result) {
                $error_detail = "TLS handshake failed. Check SSL/TLS configuration.";
                error_log("SMTP TLS handshake failed: $error_detail");
                fclose($smtp);
                return false;
            }
            // Send EHLO again after TLS
            fputs($smtp, "EHLO $host\r\n");
            $response = '';
            $line = '';
            while ($line = fgets($smtp, 515)) {
                $response .= $line;
                if (substr(trim($line), 3, 1) == ' ') break;
            }
        }
        
        // Authenticate
        fputs($smtp, "AUTH LOGIN\r\n");
        $response = fgets($smtp, 515);
        if (!$response || substr(trim($response), 0, 3) != '334') {
            $error_detail = "AUTH LOGIN command failed: " . trim($response);
            error_log("SMTP AUTH LOGIN failed: $error_detail");
            fclose($smtp);
            return false;
        }
        
        fputs($smtp, base64_encode($user) . "\r\n");
        $response = fgets($smtp, 515);
        if (!$response || substr(trim($response), 0, 3) != '334') {
            $error_detail = "Username authentication failed: " . trim($response);
            error_log("SMTP Username auth failed: $error_detail");
            fclose($smtp);
            return false;
        }
        
        fputs($smtp, base64_encode($pass) . "\r\n");
        $response = fgets($smtp, 515);
        
        if (!$response || substr(trim($response), 0, 3) != '235') {
            $error_detail = "Password authentication failed: " . trim($response) . ". Verify your Gmail App Password is correct.";
            error_log("SMTP Authentication failed: $error_detail");
            fclose($smtp);
            return false;
        }
        
        // Send email
        fputs($smtp, "MAIL FROM: <$from_email>\r\n");
        $response = fgets($smtp, 515);
        if (!$response || substr(trim($response), 0, 3) != '250') {
            $error_detail = "MAIL FROM failed: " . trim($response);
            error_log("SMTP MAIL FROM failed: $error_detail");
            fclose($smtp);
            return false;
        }
        
        fputs($smtp, "RCPT TO: <$to>\r\n");
        $response = fgets($smtp, 515);
        if (!$response || substr(trim($response), 0, 3) != '250') {
            $error_detail = "RCPT TO failed: " . trim($response);
            error_log("SMTP RCPT TO failed: $error_detail");
            fclose($smtp);
            return false;
        }
        
        fputs($smtp, "DATA\r\n");
        $response = fgets($smtp, 515);
        if (!$response || substr(trim($response), 0, 3) != '354') {
            $error_detail = "DATA command failed: " . trim($response);
            error_log("SMTP DATA failed: $error_detail");
            fclose($smtp);
            return false;
        }
        
        // Email headers and body
        // Encode subject for proper handling (optional but good practice)
        $encoded_subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        
        $email_data = "From: $from_name <$from_email>\r\n";
        $email_data .= "To: <$to>\r\n";
        $email_data .= "Subject: $encoded_subject\r\n";
        $email_data .= "MIME-Version: 1.0\r\n";
        $email_data .= "Content-type:text/html;charset=UTF-8\r\n";
        $email_data .= "\r\n";
        $email_data .= $message . "\r\n";
        $email_data .= ".\r\n";
        
        fputs($smtp, $email_data);
        
        // Read response - might be multiline
        $response = '';
        $line = '';
        $timeout = 30;
        $start_time = time();
        while (($line = @fgets($smtp, 515)) !== false) {
            $response .= $line;
            if (substr(trim($line), 3, 1) == ' ') break; // Last line
            if ((time() - $start_time) > $timeout) break; // Timeout protection
        }
        
        if (!$response || substr(trim($response), 0, 3) != '250') {
            $error_detail = "Email send failed: " . trim($response);
            error_log("SMTP Send failed: $error_detail");
            if ($smtp) fclose($smtp);
            return false;
        }
        
        error_log("SMTP Email sent successfully to: $to");
        
        fputs($smtp, "QUIT\r\n");
        fclose($smtp);
        
        return true;
    } catch (Exception $e) {
        $error_detail = "Exception: " . $e->getMessage();
        error_log("SMTP Error: $error_detail");
        return false;
    }
}

$pathInfo = $_SERVER['PATH_INFO'] ?? '';
if ($pathInfo === '' || $pathInfo === null) {
    $requestUri = isset($_SERVER['REQUEST_URI']) ? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) : '';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    if ($requestUri && $scriptName && strpos($requestUri, $scriptName) === 0) {
        $pathInfo = substr($requestUri, strlen($scriptName));
    } elseif ($requestUri && $scriptName) {
        $scriptDir = rtrim(dirname($scriptName), '/\\');
        $basename = '/' . basename($scriptName);
        if ($scriptDir && strpos($requestUri, $scriptDir) === 0) {
            $remaining = substr($requestUri, strlen($scriptDir));
            if ($remaining && strpos($remaining, $basename) === 0) {
                $pathInfo = substr($remaining, strlen($basename));
            }
        }
    }
}

$pathInfo = trim($pathInfo ?? '', '/');
$request = $pathInfo === '' ? [] : explode('/', $pathInfo);
$resource = $request[0] ?? '';
$id = $request[1] ?? null;

try {
    // Handle authentication endpoints
    if ($resource === 'login' || $resource === 'signup') {
        handleAuth($method, $pdo);
    } elseif ($resource === 'verify' || $resource === 'resend_code') {
        handleVerification($method, $pdo, $resource);
    } else {
        switch ($resource) {
            case 'users':
                handleUsers($method, $pdo, $id);
                break;
            case 'events':
                handleEvents($method, $pdo, $id);
                break;
            case 'feedback':
                handleFeedback($method, $pdo, $id);
                break;
            case 'invitations':
                handleInvitations($method, $pdo, $id);
                break;
            case 'themes':
                handleThemes($method, $pdo);
                break;
            case 'venues':
                handleVenues($method, $pdo);
                break;
            case 'attendees':
                handleAttendees($method, $pdo, $id);
                break;
            case 'stats':
                handleStats($method, $pdo);
                break;
            case 'host_stats':
                handleHostStats($method, $pdo);
                break;
            case 'profile':
                handleProfile($method, $pdo);
                break;
            case 'change_password':
                handleChangePassword($method, $pdo);
                break;
            case 'recommendations':
                handleRecommendations($method, $pdo, $id);
                break;
            default:
                http_response_code(404);
                echo json_encode(['error' => 'Resource not found']);
        }
    }
} catch (Exception $e) {
    // Clean any buffered output before sending JSON
    cleanOutput();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * Helper function to clean output buffer and ensure clean JSON output
 * Call this before any JSON output if there might be buffered content
 */
function cleanOutput() {
    if (ob_get_level() > 0) {
        ob_clean();
    }
}

function handleUsers($method, $pdo, $id = null)
{
    switch ($method) {
        case 'GET':
            $stmt = $pdo->query("SELECT user_id, name, email, phone, address, role, status, created_at FROM users");
            $users = $stmt->fetchAll();
            echo json_encode($users, JSON_PRETTY_PRINT);
            break;
        case 'POST':
            $data = json_decode(file_get_contents('php://input'), true);
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password, phone, address) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $data['name'],
                $data['email'],
                password_hash($data['password'], PASSWORD_DEFAULT),
                $data['phone'],
                $data['address']
            ]);
            echo json_encode(['id' => $pdo->lastInsertId()]);
            break;
        case 'PUT':
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'User ID is required']);
                break;
            }
            $data = json_decode(file_get_contents('php://input'), true);
            // Validate status value
            $allowedStatuses = ['active', 'blocked', 'pending_approval'];
            if (!isset($data['status']) || !in_array($data['status'], $allowedStatuses)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid status. Must be one of: active, blocked, pending_approval']);
                break;
            }
            $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE user_id = ?");
            $stmt->execute([$data['status'], $id]);
            echo json_encode(['message' => 'User updated successfully']);
            break;
        case 'DELETE':
            // User deletion is disabled for data integrity
            http_response_code(403);
            echo json_encode(['error' => 'User deletion is not allowed. Use status update to block users instead.']);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
}

function handleEvents($method, $pdo, $id = null)
{
    switch ($method) {
        case 'GET':
            if ($id) {
                // Get single event
                $stmt = $pdo->prepare("SELECT e.*, v.name AS venue_name, v.location AS venue_location,v.capacity_max,v.attrs,v.avg_rating, e.food_pref AS food_details, et.name AS theme_name, et.category, et.visual_mood, et.decor_style, et.color_palette, u.name AS host_name
                FROM events e LEFT JOIN venues v ON e.chosen_venue_id = v.venue_id LEFT JOIN event_themes et ON e.theme_id = et.id LEFT JOIN users u ON e.user_id = u.user_id WHERE e.event_id = ?");
                $stmt->execute([$id]);
                $event = $stmt->fetch();
                if ($event) {
                    // Decode attrs if needed for future use
                    $attrs = json_decode($event['attrs'] ?? '{}', true);
                    $event['themes'] = $event['theme_id'] ? [[
                        'name' => $event['theme_name'],
                        'category' => $event['category'],
                        'visual_mood' => $event['visual_mood'],
                        'decor_style' => $event['decor_style'],
                        'color_palette' => $event['color_palette']
                    ]] : [];
                    // Clean up
                    unset($event['attrs'], $event['theme_name'], $event['category'], $event['visual_mood'], $event['decor_style'], $event['color_palette']);
                    echo json_encode($event);
                } else {
                    http_response_code(404);
                    echo json_encode(['error' => 'Event not found']);
                }
            } else {
                // Get events: if authenticated, check role
                $user_id = null;
                $role = null;
                $headers = getallheaders();
                if (isset($headers['Authorization'])) {
                    $auth = $headers['Authorization'];
                    if (preg_match('/Bearer\s+(.*)$/i', $auth, $matches)) {
                        $token = $matches[1];
                        $user_id = $token; // since token is user_id
                        // Get user role
                        $stmtRole = $pdo->prepare("SELECT role FROM users WHERE user_id = ?");
                        $stmtRole->execute([$user_id]);
                        $user = $stmtRole->fetch();
                        $role = $user['role'] ?? null;
                    }
                }
                if ($user_id && $role === 'host') {
                    // Host: events they created
                    $stmt = $pdo->prepare("SELECT e.*,v.location,v.name as venue_name, u.name as host_name FROM events e
                    LEFT JOIN venues v on e.chosen_venue_id =v.venue_id LEFT JOIN users u on e.user_id = u.user_id WHERE e.user_id = ? ORDER BY e.created_at DESC");
                    $stmt->execute([$user_id]);
                } elseif ($user_id && $role === 'guest') {
                    // Guest: events they are invited to
                    $stmt = $pdo->prepare("SELECT e.*, v.location, v.name as venue_name, u.name as host_name, i.status as invitation_status, i.invitation_id
                    FROM events e
                    LEFT JOIN venues v ON e.chosen_venue_id = v.venue_id
                    LEFT JOIN users u ON e.user_id = u.user_id
                    JOIN invitations i ON e.event_id = i.event_id
                    WHERE i.guest_user_id = ? AND i.status != 'declined'
                    ORDER BY e.created_at DESC");
                    $stmt->execute([$user_id]);
                } elseif ($user_id && $role === 'admin') {
                    // Admin: all events
                    $stmt = $pdo->query("SELECT e.*,v.location,v.name as venue_name, u.name as host_name FROM events e
                    LEFT JOIN venues v on e.chosen_venue_id =v.venue_id LEFT JOIN users u on e.user_id = u.user_id ORDER BY e.created_at DESC");
                } else {
                    // No auth or invalid, return empty
                    $events = [];
                    echo json_encode($events, JSON_PRETTY_PRINT);
                    return;
                }
                $events = $stmt->fetchAll();
                echo json_encode($events, JSON_PRETTY_PRINT);
            }
            break;
        case 'POST':
            $data = json_decode(file_get_contents('php://input'), true);
            
            // Validate required fields
            $required = ['user_id', 'event_name', 'event_type', 'event_date', 'guest_count', 'gender'];
            foreach ($required as $field) {
                if (!isset($data[$field]) || empty($data[$field])) {
                    http_response_code(400);
                    echo json_encode(['error' => "Missing required field: $field"]);
                    return;
                }
            }
            
            // Validate event date is in the future
            $event_date = $data['event_date'];
            $date_obj = new DateTime($event_date);
            $today = new DateTime();
            if ($date_obj < $today) {
                http_response_code(400);
                echo json_encode(['error' => 'Event date must be in the future']);
                return;
            }
            
            // Validate guest count is positive
            $guest_count = intval($data['guest_count']);
            if ($guest_count <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Guest count must be greater than 0']);
                return;
            }
            
            // Validate gender
            if (!in_array($data['gender'], ['Male', 'Female', 'Both'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid gender value']);
                return;
            }
            
            $user_id = intval($data['user_id']);
            // Handle budget - use 0.00 if not provided or <= 0 (database default, doesn't allow null)
            $budget = isset($data['budget']) && is_numeric($data['budget']) && floatval($data['budget']) > 0 
                ? floatval($data['budget']) 
                : 0.00;
            
            $stmt = $pdo->prepare("INSERT INTO events (user_id, event_name, event_type, description, event_date, guest_count, budget, gender, guest_age_min, guest_age_max) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $user_id,
                trim($data['event_name']),
                $data['event_type'],
                $data['description'] ?? null,
                $event_date,
                $guest_count,
                $budget, // Always send 0.00 if not provided (matches database default)
                $data['gender'],
                isset($data['guest_age_min']) && $data['guest_age_min'] > 0 ? intval($data['guest_age_min']) : null,
                isset($data['guest_age_max']) && $data['guest_age_max'] > 0 ? intval($data['guest_age_max']) : null
            ]);
            $event_id = $pdo->lastInsertId();
            
            // Call AI for recommendations
            // Use the same budget value we calculated (0.00 if not provided)
            $ai_data = [
                'user_id' => $user_id,
                'event_type' => $data['event_type'],
                'guest_count' => $guest_count,
                'budget' => $budget > 0 ? $budget : null, // Send null to AI if budget is 0.00 (no budget)
                'gender' => $data['gender'],
                'food_pref' => $data['food_pref'] ?? null,
                'event_date' => $event_date
            ];
            
            $recommendations = [];
            $rec_id = null;
            try {
                $ai_response = callAI($ai_data);
                // If callAI returns the full response structure, extract recommendations and rec_id
                if (isset($ai_response['recommendations'])) {
                    $recommendations = $ai_response['recommendations'];
                    $rec_id = $ai_response['rec_id'] ?? null;
                } else {
                    $recommendations = $ai_response;
                }
            } catch (Exception $e) {
                // Log error but don't fail event creation
                error_log("AI recommendation error: " . $e->getMessage());
                $recommendations = ['venues' => [], 'themes' => [], 'food' => []];
            }
            
            // Don't auto-select venue - let user choose from recommendations
            $response_data = [
                'id' => $event_id, 
                'recommendations' => $recommendations,
                'message' => 'Event created successfully'
            ];
            if ($rec_id) {
                $response_data['rec_id'] = $rec_id;
            }
            echo json_encode($response_data);
            break;
        case 'PUT':
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'Event ID is required']);
                break;
            }
            $data = json_decode(file_get_contents('php://input'), true);
            $updateFields = [];
            $params = [];
            
            // Allow updating all event fields
            $allowedFields = [
                'event_name', 'event_type', 'description', 'event_date',
                'guest_count', 'budget', 'gender', 'guest_age_min', 'guest_age_max',
                'chosen_venue_id', 'theme_id', 'food_pref', 'status'
            ];
            
            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    if ($field === 'event_date') {
                        // Validate event date if being updated
                        $event_date = $data[$field];
                        $date_obj = new DateTime($event_date);
                        $today = new DateTime();
                        if ($date_obj < $today) {
                            http_response_code(400);
                            echo json_encode(['error' => 'Event date must be today or in the future']);
                            return;
                        }
                    }
                    if ($field === 'guest_count' && intval($data[$field]) <= 0) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Guest count must be greater than 0']);
                        return;
                    }
                    if ($field === 'gender' && !in_array($data[$field], ['Male', 'Female', 'Both'])) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Invalid gender value']);
                        return;
                    }
                    
                    $updateFields[] = "$field = ?";
                    // Handle null values properly
                    // Special handling for budget: database column is NOT NULL DEFAULT 0.00
                    if ($field === 'budget') {
                        if ($data[$field] === null || $data[$field] === '' || !is_numeric($data[$field]) || floatval($data[$field]) <= 0) {
                            $params[] = 0.00; // Use default value instead of null
                        } else {
                            $params[] = floatval($data[$field]);
                        }
                    } elseif ($data[$field] === null || $data[$field] === '') {
                        $params[] = null;
                    } else {
                        $params[] = is_numeric($data[$field]) && strpos($field, 'id') === false && $field !== 'guest_count' 
                            ? (strpos($field, 'age') !== false || $field === 'guest_count' ? intval($data[$field]) : floatval($data[$field]))
                            : (strpos($field, 'id') !== false ? intval($data[$field]) : $data[$field]);
                    }
                }
            }
            
            if (!empty($updateFields)) {
                $sql = "UPDATE events SET " . implode(', ', $updateFields) . " WHERE event_id = ?";
                $params[] = $id;
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                echo json_encode(['message' => 'Event updated successfully']);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'No valid fields to update']);
            }
            break;
        case 'DELETE':
            // Allow deletion of draft events (no venue selected, no invitations sent)
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'Event ID is required']);
                break;
            }
            
            // Get current user from token
            $user_id = null;
            $headers = getallheaders();
            if (isset($headers['Authorization'])) {
                $auth = $headers['Authorization'];
                if (preg_match('/Bearer\s+(.*)$/i', $auth, $matches)) {
                    $token = $matches[1];
                    $user_id = $token;
                }
            }
            
            if (!$user_id) {
                http_response_code(401);
                echo json_encode(['error' => 'Unauthorized']);
                break;
            }
            
            // Check if event exists and belongs to user
            $stmt = $pdo->prepare("SELECT user_id, chosen_venue_id FROM events WHERE event_id = ?");
            $stmt->execute([$id]);
            $event = $stmt->fetch();
            
            if (!$event) {
                http_response_code(404);
                echo json_encode(['error' => 'Event not found']);
                break;
            }
            
            if ($event['user_id'] != $user_id) {
                http_response_code(403);
                echo json_encode(['error' => 'You can only delete your own events']);
                break;
            }
            
            // Check if event has invitations (if so, don't allow deletion)
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM invitations WHERE event_id = ?");
            $stmt->execute([$id]);
            $invitation_count = $stmt->fetch()['count'];
            
            if ($invitation_count > 0) {
                http_response_code(403);
                echo json_encode(['error' => 'Cannot delete event with invitations. Cancel the event instead.']);
                break;
            }
            
            // Allow deletion of draft events (no venue selected) or events without invitations
            // Delete the event
            $stmt = $pdo->prepare("DELETE FROM events WHERE event_id = ?");
            $stmt->execute([$id]);
            
            echo json_encode(['message' => 'Event deleted successfully']);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
}

function handleFeedback($method, $pdo, $id = null)
{
    switch ($method) {
        case 'GET':
            // Get current user from token to filter feedback
            $user_id = null;
            $headers = getallheaders();
            if (isset($headers['Authorization'])) {
                $auth = $headers['Authorization'];
                if (preg_match('/Bearer\s+(.*)$/i', $auth, $matches)) {
                    $token = $matches[1];
                    $user_id = $token; // since token is user_id
                }
            }
            
            if ($id) {
                // Get single feedback item
                $stmt = $pdo->prepare("SELECT * FROM feedback WHERE feedback_id = ?");
                $stmt->execute([$id]);
                $feedback = $stmt->fetch();
                if ($feedback) {
                    echo json_encode($feedback);
                } else {
                    http_response_code(404);
                    echo json_encode(['error' => 'Feedback not found']);
                }
            } else {
                // Get all feedback (optionally filtered by user)
                if ($user_id) {
                    $stmt = $pdo->prepare("SELECT * FROM feedback WHERE user_id = ? ORDER BY created_at DESC");
                    $stmt->execute([$user_id]);
                } else {
                    $stmt = $pdo->query("SELECT * FROM feedback ORDER BY created_at DESC");
                }
                $feedback = $stmt->fetchAll();
                echo json_encode($feedback, JSON_PRETTY_PRINT);
            }
            break;
        case 'POST':
            $data = json_decode(file_get_contents('php://input'), true);
            
            // Validate required fields
            if (!isset($data['attendees_id']) || !isset($data['event_id']) || !isset($data['rate'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing required fields: attendees_id, event_id, and rate are required']);
                return;
            }
            
            // Determine feedback type - default to 'Event' but allow all valid types
            $feedback_type = isset($data['type']) && in_array($data['type'], ['Event', 'Venue', 'Theme', 'Food']) 
                ? $data['type'] 
                : 'Event';
            
            // Validate rating is between 1-5
            $rating = intval($data['rate']);
            if ($rating < 1 || $rating > 5) {
                http_response_code(400);
                echo json_encode(['error' => 'Rating must be between 1 and 5']);
                return;
            }
            
            $stmt = $pdo->prepare("INSERT INTO feedback (user_id, item_id, type, rating) VALUES (?, ?, ?, ?)");
            $stmt->execute([
                intval($data['attendees_id']),
                intval($data['event_id']),
                $feedback_type,
                $rating
            ]);
            echo json_encode(['id' => $pdo->lastInsertId(), 'message' => 'Feedback submitted successfully']);
            break;
        case 'PUT':
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'Feedback ID is required']);
                return;
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            // Get current user from token
            $user_id = null;
            $headers = getallheaders();
            if (isset($headers['Authorization'])) {
                $auth = $headers['Authorization'];
                if (preg_match('/Bearer\s+(.*)$/i', $auth, $matches)) {
                    $token = $matches[1];
                    $user_id = $token;
                }
            }
            
            if (!$user_id) {
                http_response_code(401);
                echo json_encode(['error' => 'Unauthorized']);
                return;
            }
            
            // Verify feedback belongs to user
            $stmt = $pdo->prepare("SELECT user_id FROM feedback WHERE feedback_id = ?");
            $stmt->execute([$id]);
            $feedback = $stmt->fetch();
            
            if (!$feedback) {
                http_response_code(404);
                echo json_encode(['error' => 'Feedback not found']);
                return;
            }
            
            if ($feedback['user_id'] != $user_id) {
                http_response_code(403);
                echo json_encode(['error' => 'You can only edit your own feedback']);
                return;
            }
            
            // Update feedback
            $updateFields = [];
            $params = [];
            
            if (isset($data['rate'])) {
                $rating = intval($data['rate']);
                if ($rating < 1 || $rating > 5) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Rating must be between 1 and 5']);
                    return;
                }
                $updateFields[] = "rating = ?";
                $params[] = $rating;
            }
            
            if (empty($updateFields)) {
                http_response_code(400);
                echo json_encode(['error' => 'No valid fields to update']);
                return;
            }
            
            $params[] = $id;
            $sql = "UPDATE feedback SET " . implode(', ', $updateFields) . " WHERE feedback_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            echo json_encode(['message' => 'Feedback updated successfully']);
            break;
        case 'DELETE':
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'Feedback ID is required']);
                return;
            }
            
            // Get current user from token
            $user_id = null;
            $headers = getallheaders();
            if (isset($headers['Authorization'])) {
                $auth = $headers['Authorization'];
                if (preg_match('/Bearer\s+(.*)$/i', $auth, $matches)) {
                    $token = $matches[1];
                    $user_id = $token;
                }
            }
            
            if (!$user_id) {
                http_response_code(401);
                echo json_encode(['error' => 'Unauthorized']);
                return;
            }
            
            // Verify feedback belongs to user
            $stmt = $pdo->prepare("SELECT user_id FROM feedback WHERE feedback_id = ?");
            $stmt->execute([$id]);
            $feedback = $stmt->fetch();
            
            if (!$feedback) {
                http_response_code(404);
                echo json_encode(['error' => 'Feedback not found']);
                return;
            }
            
            if ($feedback['user_id'] != $user_id) {
                http_response_code(403);
                echo json_encode(['error' => 'You can only delete your own feedback']);
                return;
            }
            
            // Delete feedback
            $stmt = $pdo->prepare("DELETE FROM feedback WHERE feedback_id = ?");
            $stmt->execute([$id]);
            
            echo json_encode(['message' => 'Feedback deleted successfully']);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
}

function handleInvitations($method, $pdo, $id = null)
{
    switch ($method) {
        case 'GET':
            // Get current user from token
            $user_id = null;
            $headers = getallheaders();
            if (isset($headers['Authorization'])) {
                $auth = $headers['Authorization'];
                if (preg_match('/Bearer\s+(.*)$/i', $auth, $matches)) {
                    $token = $matches[1];
                    $user_id = $token; // since token is user_id
                }
            }
            if (!$user_id) {
                http_response_code(401);
                echo json_encode(['error' => 'Unauthorized']);
                return;
            }
            // Get invitations for current guest user
            $stmt = $pdo->prepare("SELECT i.invitation_id, i.status as invitation_status, i.sent_at, i.responded_at,
                i.invitation_id,
                i.status AS invitation_status,
                i.sent_at,
                i.responded_at,
                e.event_id, e.event_name, e.event_date, e.description, e.event_type,
                e.guest_count, e.gender, e.budget,
                v.location, v.name as venue_name,
                u.name as host_name
                FROM invitations i
                JOIN events e ON i.event_id = e.event_id
                LEFT JOIN venues v ON e.chosen_venue_id = v.venue_id
                JOIN users u ON e.user_id = u.user_id
                WHERE i.guest_user_id = ?
                ORDER BY i.sent_at DESC");
            $stmt->execute([$user_id]);
            $invitations = $stmt->fetchAll();
            echo json_encode($invitations, JSON_PRETTY_PRINT);
            break;
        case 'POST':
            $data = json_decode(file_get_contents('php://input'), true);
            // Find guest_user_id by email
            $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
            $stmt->execute([$data['guest_email']]);
            $guest = $stmt->fetch();
            if (!$guest) {
                http_response_code(400);
                echo json_encode(['error' => 'Guest not found']);
                return;
            }
            // Check if invitation already exists
            $stmtCheck = $pdo->prepare("SELECT invitation_id FROM invitations WHERE guest_user_id = ? AND event_id = ?");
            $stmtCheck->execute([$guest['user_id'], $data['event_id']]);
            if ($stmtCheck->fetch()) {
                http_response_code(400);
                echo json_encode(['error' => 'Invitation already sent to this guest for this event']);
                return;
            }
            $stmt = $pdo->prepare("INSERT INTO invitations (status, guest_user_id, event_id) VALUES (?, ?, ?)");
            $stmt->execute([
                $data['status'] ?? 'pending',
                $guest['user_id'],
                $data['event_id']
            ]);
            echo json_encode(['id' => $pdo->lastInsertId()]);
            break;
        case 'PUT':
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'Invitation ID is required']);
                break;
            }
            $data = json_decode(file_get_contents('php://input'), true);
            if (!isset($data['status'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Status is required']);
                break;
            }
            $stmt = $pdo->prepare("UPDATE invitations SET status = ?, responded_at = CURRENT_TIMESTAMP WHERE invitation_id = ?");
            $stmt->execute([$data['status'], $id]);
            echo json_encode(['message' => 'Invitation updated successfully']);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
}

function handleRecommendations($method, $pdo, $id = null)
{
    switch ($method) {
        case 'DELETE':
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'Recommendation ID is required']);
                return;
            }
            
            // Get current user from token
            $user_id = null;
            $headers = getallheaders();
            if (isset($headers['Authorization'])) {
                $auth = $headers['Authorization'];
                if (preg_match('/Bearer\s+(.*)$/i', $auth, $matches)) {
                    $token = $matches[1];
                    $user_id = $token;
                }
            }
            
            if (!$user_id) {
                http_response_code(401);
                echo json_encode(['error' => 'Unauthorized']);
                return;
            }
            
            // Verify recommendation belongs to user
            $stmt = $pdo->prepare("SELECT user_id FROM recommendations WHERE rec_id = ?");
            $stmt->execute([$id]);
            $recommendation = $stmt->fetch();
            
            if (!$recommendation) {
                http_response_code(404);
                echo json_encode(['error' => 'Recommendation not found']);
                return;
            }
            
            if ($recommendation['user_id'] != $user_id) {
                http_response_code(403);
                echo json_encode(['error' => 'You can only delete your own recommendations']);
                return;
            }
            
            // Delete recommendation
            $stmt = $pdo->prepare("DELETE FROM recommendations WHERE rec_id = ?");
            $stmt->execute([$id]);
            
            echo json_encode(['message' => 'Recommendation deleted successfully']);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
}

function handleThemes($method, $pdo)
{
    switch ($method) {
        case 'GET':
            $mode = $_GET['mode'] ?? null;
            if ($mode === 'full') {
                $stmt = $pdo->query("SELECT id AS theme_id, name FROM event_themes ORDER BY name");
                $themes = $stmt->fetchAll();
                echo json_encode(['themes' => $themes]);
                break;
            }
            $stmt = $pdo->query("SELECT DISTINCT name FROM event_themes");
            $themes = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $stmt2 = $pdo->query("SELECT DISTINCT color_palette FROM event_themes");
            $colors_raw = $stmt2->fetchAll(PDO::FETCH_COLUMN);
            $colors = [];
            foreach ($colors_raw as $c) {
                $parts = explode(',', $c);
                foreach ($parts as $p) {
                    $clean = trim(str_replace(['(', ')'], '', $p));
                    if (!in_array($clean, $colors) && !empty($clean)) $colors[] = $clean;
                }
            }
            echo json_encode(['themes' => $themes, 'colors' => $colors]);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
}

function handleVenues($method, $pdo)
{
    switch ($method) {
        case 'GET':
            $stmt = $pdo->query("SELECT venue_id, name, event_type, venue_category, location, capacity_min, capacity_max, price_min, price_max, gender_allowed, attrs, avg_rating, rating_count, image_url, venue_url FROM venues ORDER BY avg_rating DESC");
            $venues = $stmt->fetchAll();
            echo json_encode($venues);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
}

function handleAuth($method, $pdo)
{
    switch ($method) {
        case 'POST':
            $data = json_decode(file_get_contents('php://input'), true);
            if (isset($data['first_name']) && isset($data['last_name']) && isset($data['email']) && isset($data['password'])) {
                // Signup
                $name = $data['first_name'] . ' ' . $data['last_name'];
                // Validate role if provided, default to 'guest'
                $role = $data['role'] ?? 'guest';
                if (!in_array($role, ['host', 'guest', 'admin'])) {
                    $role = 'guest'; // Default to guest if invalid role provided
                }
                $stmt = $pdo->prepare("INSERT INTO users (name, phone, address, email, password, role, gender, age, preferred_styles, preferred_colors, indoor_outdoor, food, email_verified) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
                $stmt->execute([
                    $name,
                    $data['phone'] ?? '',
                    $data['address'] ?? '',
                    $data['email'],
                    $hashedPassword,
                    $role,
                    $data['gender'] ?? null,
                    $data['age'] ?? null,
                    $data['preferred_styles'] ?? null,
                    $data['preferred_colors'] ?? null,
                    $data['indoor_outdoor'] ?? 'Both',
                    $data['food'] ?? null,
                    false // email_verified = FALSE
                ]);
                $user_id = $pdo->lastInsertId();
                
                // Generate 6-digit verification code
                $verification_code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
                
                // Set expiration time (30 minutes from now)
                $expires_at = date('Y-m-d H:i:s', strtotime('+30 minutes'));
                
                // Store verification code in database
                $stmt_verify = $pdo->prepare("INSERT INTO email_verifications (user_id, verification_code, expires_at) VALUES (?, ?, ?)");
                $stmt_verify->execute([$user_id, $verification_code, $expires_at]);
                
                // Send verification email - REQUIRED for signup
                error_log("Attempting to send verification email to: {$data['email']} with code: $verification_code");
                $email_sent = sendVerificationEmail($data['email'], $verification_code);
                error_log("Email send result: " . ($email_sent ? 'SUCCESS' : 'FAILED'));
                
                if (!$email_sent) {
                    // Email sending failed - rollback user creation and return error
                    error_log("Email sending failed for user_id: {$user_id}, email: {$data['email']}. Check SMTP logs above for details.");
                    
                    // Delete the user that was just created
                    try {
                        $stmt_delete = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
                        $stmt_delete->execute([$user_id]);
                        
                        // Also delete the verification code that was created
                        $stmt_delete_verify = $pdo->prepare("DELETE FROM email_verifications WHERE user_id = ?");
                        $stmt_delete_verify->execute([$user_id]);
                    } catch (Exception $e) {
                        error_log("Error cleaning up failed signup: " . $e->getMessage());
                    }
                    
                    // Clean buffer and send error response
                    cleanOutput();
                    http_response_code(500);
                    echo json_encode([
                        'error' => 'Failed to send verification email. Please check your email address and try again. If the problem persists, contact support.',
                        'detail' => 'Email service is currently unavailable. Please ensure your email address is correct.'
                    ]);
                    return;
                }
                
                // Email sent successfully - return success
                cleanOutput();
                echo json_encode([
                    'message' => 'User created successfully. Please check your email for verification code.',
                    'id' => $user_id,
                    'email' => $data['email']
                ]);
            } elseif (isset($data['email']) && isset($data['password'])) {
                // Login
                $stmt = $pdo->prepare("SELECT user_id, name, email, password, role, status, email_verified FROM users WHERE email = ?");
                $stmt->execute([$data['email']]);
                $user = $stmt->fetch();

                if ($user && password_verify($data['password'], $user['password'])) {
                    // Check email verification status first
                    if (!$user['email_verified']) {
                        http_response_code(403);
                        echo json_encode(['error' => 'Please verify your email before logging in. Check your inbox for the verification code.']);
                        break;
                    }
                    
                    // Check user status - prevent login if blocked or pending_approval
                    if ($user['status'] === 'blocked') {
                        http_response_code(403);
                        echo json_encode(['error' => 'Your account has been blocked. Please contact support.']);
                        break;
                    }
                    if ($user['status'] === 'pending_approval') {
                        http_response_code(403);
                        echo json_encode(['error' => 'Your account is pending approval. Please wait for admin approval.']);
                        break;
                    }
                    // Only allow login if status is 'active'
                    if ($user['status'] !== 'active') {
                        http_response_code(403);
                        echo json_encode(['error' => 'Your account is not active. Please contact support.']);
                        break;
                    }
                    // Generate a simple token (user_id for simplicity)
                    $token = $user['user_id'];
                    echo json_encode([
                        'access_token' => $token,
                        'user' => [
                            'id' => $user['user_id'],
                            'name' => $user['name'],
                            'email' => $user['email'],
                            'role' => $user['role'],
                            'status' => $user['status']
                        ]
                    ]);
                } else {
                    http_response_code(401);
                    echo json_encode(['error' => 'Invalid credentials']);
                }
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Missing required fields']);
            }
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
}

function handleVerification($method, $pdo, $resource)
{
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if ($resource === 'verify') {
        // Verify email with code
        if (!isset($data['email']) || !isset($data['code'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Email and verification code are required']);
            return;
        }
        
        $email = $data['email'];
        $code = $data['code'];
        
        // Get user by email
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if (!$user) {
            http_response_code(404);
            echo json_encode(['error' => 'User not found']);
            return;
        }
        
        $user_id = $user['user_id'];
        
        // Trim and pad code to ensure it's 6 digits
        $code = trim($code);
        $code = str_pad($code, 6, '0', STR_PAD_LEFT);
        
        // Debug logging
        error_log("Verification attempt - User ID: $user_id, Code: $code");
        
        // Check for valid, unused, non-expired verification code
        // Use UTC_TIMESTAMP() for consistent timezone comparison
        $stmt = $pdo->prepare("SELECT verification_id, verification_code, expires_at, used, created_at FROM email_verifications WHERE user_id = ? AND used = 0 ORDER BY created_at DESC LIMIT 5");
        $stmt->execute([$user_id]);
        $all_codes = $stmt->fetchAll();
        
        error_log("Found " . count($all_codes) . " unused verification codes for user $user_id");
        foreach ($all_codes as $vc) {
            $db_code = trim($vc['verification_code']);
            $db_code = str_pad($db_code, 6, '0', STR_PAD_LEFT);
            $expires = $vc['expires_at'];
            $now = date('Y-m-d H:i:s');
            $is_expired = strtotime($expires) <= strtotime($now);
            error_log("  Code: $db_code, Expires: $expires, Now: $now, Expired: " . ($is_expired ? 'YES' : 'NO') . ", Matches: " . ($db_code === $code ? 'YES' : 'NO'));
        }
        
        // Check for valid, unused, non-expired verification code
        // Compare codes after padding and trimming, and check expiration using PHP time comparison
        $stmt = $pdo->prepare("SELECT verification_id, verification_code, expires_at FROM email_verifications WHERE user_id = ? AND used = 0 ORDER BY created_at DESC");
        $stmt->execute([$user_id]);
        $verifications = $stmt->fetchAll();
        
        $verification = null;
        $current_time = time();
        
        foreach ($verifications as $v) {
            $db_code = trim($v['verification_code']);
            $db_code = str_pad($db_code, 6, '0', STR_PAD_LEFT);
            $expires_at = strtotime($v['expires_at']);
            
            if ($db_code === $code && $expires_at > $current_time) {
                $verification = $v;
                break;
            }
        }
        
        if (!$verification) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid or expired verification code']);
            return;
        }
        
        // Mark verification code as used
        $stmt = $pdo->prepare("UPDATE email_verifications SET used = 1 WHERE verification_id = ?");
        $stmt->execute([$verification['verification_id']]);
        
        // Update user email_verified status
        $stmt = $pdo->prepare("UPDATE users SET email_verified = 1 WHERE user_id = ?");
        $stmt->execute([$user_id]);
        
        echo json_encode(['message' => 'Email verified successfully']);
        
    } elseif ($resource === 'resend_code') {
        // Resend verification code
        if (!isset($data['email'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Email is required']);
            return;
        }
        
        $email = $data['email'];
        
        // Get user by email
        $stmt = $pdo->prepare("SELECT user_id, email_verified FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if (!$user) {
            http_response_code(404);
            echo json_encode(['error' => 'User not found']);
            return;
        }
        
        // Check if already verified
        if ($user['email_verified']) {
            http_response_code(400);
            echo json_encode(['error' => 'Email is already verified']);
            return;
        }
        
        $user_id = $user['user_id'];
        
        // Check cooldown period (60 seconds)
        $stmt = $pdo->prepare("SELECT created_at FROM email_verifications WHERE user_id = ? AND used = 0 ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$user_id]);
        $last_code = $stmt->fetch();
        
        if ($last_code) {
            $last_created = strtotime($last_code['created_at']);
            $cooldown_seconds = 60;
            $time_since_last = time() - $last_created;
            
            if ($time_since_last < $cooldown_seconds) {
                $remaining = $cooldown_seconds - $time_since_last;
                http_response_code(429);
                echo json_encode(['error' => "Please wait {$remaining} seconds before requesting a new code"]);
                return;
            }
        }
        
        // Invalidate old unused codes
        $stmt = $pdo->prepare("UPDATE email_verifications SET used = 1 WHERE user_id = ? AND used = 0");
        $stmt->execute([$user_id]);
        
        // Generate new 6-digit verification code
        $verification_code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
        
        // Set expiration time (30 minutes from now)
        $expires_at = date('Y-m-d H:i:s', strtotime('+30 minutes'));
        
        // Store new verification code
        $stmt = $pdo->prepare("INSERT INTO email_verifications (user_id, verification_code, expires_at) VALUES (?, ?, ?)");
        $stmt->execute([$user_id, $verification_code, $expires_at]);
        
        // Send verification email
        $email_sent = sendVerificationEmail($email, $verification_code);
        
        if ($email_sent) {
            echo json_encode(['message' => 'Verification code sent successfully']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to send verification email. Please try again later.']);
        }
    }
}

function handleAttendees($method, $pdo, $id = null)
{
    switch ($method) {
        case 'GET':
            if ($id) {
                // Get single attendee details by invitation_id
                $stmt = $pdo->prepare("SELECT i.invitation_id, i.status, i.sent_at, i.responded_at,
                    u.user_id, u.name, u.email, u.phone,
                    e.event_id, e.event_name, e.event_date, e.event_type, e.description, e.guest_count,
                    v.name as venue_name, v.location
                    FROM invitations i 
                    JOIN users u ON i.guest_user_id = u.user_id 
                    JOIN events e ON i.event_id = e.event_id
                    LEFT JOIN venues v ON e.chosen_venue_id = v.venue_id
                    WHERE i.invitation_id = ?");
                $stmt->execute([$id]);
                $attendee = $stmt->fetch();
                if (!$attendee) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Attendee not found']);
                    return;
                }
                echo json_encode($attendee, JSON_PRETTY_PRINT);
            } else {
                // Get all attendees
                $stmt = $pdo->query("SELECT i.invitation_id, u.name, u.email, e.event_name, e.event_date, i.status, i.sent_at, i.responded_at FROM invitations i JOIN users u ON i.guest_user_id = u.user_id JOIN events e ON i.event_id = e.event_id ORDER BY i.sent_at DESC");
                $attendees = $stmt->fetchAll();
                echo json_encode($attendees, JSON_PRETTY_PRINT);
            }
            break;
        case 'DELETE':
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'Invitation ID is required']);
                return;
            }
            // Verify invitation exists
            $stmt = $pdo->prepare("SELECT invitation_id FROM invitations WHERE invitation_id = ?");
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'Invitation not found']);
                return;
            }
            // Delete the invitation (which removes the attendee)
            $stmt = $pdo->prepare("DELETE FROM invitations WHERE invitation_id = ?");
            $stmt->execute([$id]);
            echo json_encode(['message' => 'Attendee removed successfully']);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
}

function handleStats($method, $pdo)
{
    switch ($method) {
        case 'GET':
            // Calculate date ranges for this month and last month
            $now = new DateTime();
            $firstDayThisMonth = new DateTime($now->format('Y-m-01'));
            $firstDayLastMonth = (clone $firstDayThisMonth)->modify('-1 month');
            $lastDayLastMonth = (clone $firstDayThisMonth)->modify('-1 day');

            // Total Users
            $stmt = $pdo->query("SELECT COUNT(*) as total_users FROM users");
            $totalUsers = $stmt->fetch()['total_users'];
            
            // Users from last month
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE created_at < ?");
            $stmt->execute([$firstDayThisMonth->format('Y-m-d H:i:s')]);
            $usersLastMonth = $stmt->fetch()['count'];
            $usersChange = $usersLastMonth > 0 ? round((($totalUsers - $usersLastMonth) / $usersLastMonth) * 100, 1) : 0;

            // Total Events
            $stmt = $pdo->query("SELECT COUNT(*) as total_events FROM events");
            $totalEvents = $stmt->fetch()['total_events'];
            
            // Events from last month
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM events WHERE created_at < ?");
            $stmt->execute([$firstDayThisMonth->format('Y-m-d H:i:s')]);
            $eventsLastMonth = $stmt->fetch()['count'];
            $eventsChange = $eventsLastMonth > 0 ? round((($totalEvents - $eventsLastMonth) / $eventsLastMonth) * 100, 1) : 0;

            // Total Invitations (all invitations)
            $stmt = $pdo->query("SELECT COUNT(*) as total_invitations FROM invitations");
            $totalInvitations = $stmt->fetch()['total_invitations'];
            
            // Total invitations from last month (invitations created before this month)
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM invitations WHERE sent_at < ?");
            $stmt->execute([$firstDayThisMonth->format('Y-m-d H:i:s')]);
            $invitationsLastMonth = $stmt->fetch()['count'];
            $invitationsChange = $invitationsLastMonth > 0 ? round((($totalInvitations - $invitationsLastMonth) / $invitationsLastMonth) * 100, 1) : 0;

            // Average Event Rating (only ratings for events, not venues/themes/food)
            $stmt = $pdo->query("SELECT AVG(rating) as avg_rating FROM feedback WHERE type = 'Event'");
            $avgRatingResult = $stmt->fetch();
            $avgRating = $avgRatingResult && $avgRatingResult['avg_rating'] !== null 
                ? round($avgRatingResult['avg_rating'], 1) 
                : 0;
            
            // Average event rating from last month
            $stmt = $pdo->prepare("SELECT AVG(rating) as avg_rating, COUNT(*) as count FROM feedback WHERE type = 'Event' AND created_at < ?");
            $stmt->execute([$firstDayThisMonth->format('Y-m-d H:i:s')]);
            $lastMonthResult = $stmt->fetch();
            $ratingLastMonth = $lastMonthResult && $lastMonthResult['avg_rating'] !== null 
                ? round($lastMonthResult['avg_rating'], 1) 
                : null;
            $lastMonthCount = $lastMonthResult ? intval($lastMonthResult['count']) : 0;
            
            // Calculate change - if no data from last month, set a special value
            if ($ratingLastMonth === null || $lastMonthCount === 0) {
                // No feedback from last month - use a special indicator
                $ratingChange = $avgRating > 0 ? 999.9 : 0; // 999.9 indicates "new this month"
            } else {
                $ratingChange = round($avgRating - $ratingLastMonth, 1);
            }

            echo json_encode([
                'total_users' => $totalUsers,
                'total_events' => $totalEvents,
                'active_attendees' => $totalInvitations, // Keep key name for backward compatibility
                'total_invitations' => $totalInvitations,
                'avg_rating' => $avgRating,
                'users_change' => $usersChange,
                'events_change' => $eventsChange,
                'attendees_change' => $invitationsChange, // Keep key name for backward compatibility
                'invitations_change' => $invitationsChange,
                'rating_change' => $ratingChange
            ], JSON_PRETTY_PRINT);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
}

function handleProfile($method, $pdo)
{
    // Get current user from token
    $user_id = null;
    $headers = getallheaders();
    if (isset($headers['Authorization'])) {
        $auth = $headers['Authorization'];
        if (preg_match('/Bearer\s+(.*)$/i', $auth, $matches)) {
            $token = $matches[1];
            $user_id = $token; // since token is user_id
        }
    }
    if (!$user_id) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }

    switch ($method) {
        case 'GET':
            // Fetch user profile data
            $stmt = $pdo->prepare("SELECT name, email, phone, address FROM users WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
            if ($user) {
                // Split name into first and last
                $nameParts = explode(' ', $user['name'], 2);
                $user['firstName'] = $nameParts[0] ?? '';
                $user['lastName'] = $nameParts[1] ?? '';
                unset($user['name']);
                echo json_encode($user);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'User not found']);
            }
            break;
        case 'PUT':
            $data = json_decode(file_get_contents('php://input'), true);
            // Combine first and last name
            $fullName = trim(($data['firstName'] ?? '') . ' ' . ($data['lastName'] ?? ''));
            // Update user profile
            $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, phone = ?, address = ? WHERE user_id = ?");
            $stmt->execute([
                $fullName,
                $data['email'],
                $data['phone'],
                $data['address'],
                $user_id
            ]);
            echo json_encode(['message' => 'Profile updated successfully']);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
}

function handleChangePassword($method, $pdo)
{
    // Get current user from token
    $user_id = null;
    $headers = getallheaders();
    if (isset($headers['Authorization'])) {
        $auth = $headers['Authorization'];
        if (preg_match('/Bearer\s+(.*)$/i', $auth, $matches)) {
            $token = $matches[1];
            $user_id = $token; // since token is user_id
        }
    }
    if (!$user_id) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }

    switch ($method) {
        case 'PUT':
            $data = json_decode(file_get_contents('php://input'), true);
            // Verify current password
            $stmt = $pdo->prepare("SELECT password FROM users WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
            if (!$user || !password_verify($data['currentPassword'], $user['password'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Current password is incorrect']);
                return;
            }
            // Update password
            $newHashedPassword = password_hash($data['newPassword'], PASSWORD_DEFAULT);
            $stmtUpdate = $pdo->prepare("UPDATE users SET password = ? WHERE user_id = ?");
            $stmtUpdate->execute([$newHashedPassword, $user_id]);
            echo json_encode(['message' => 'Password updated successfully']);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
}

function handleHostStats($method, $pdo)
{
    switch ($method) {
        case 'GET':
            // Get current user from token
            $user_id = null;
            $headers = getallheaders();
            if (isset($headers['Authorization'])) {
                $auth = $headers['Authorization'];
                if (preg_match('/Bearer\s+(.*)$/i', $auth, $matches)) {
                    $token = $matches[1];
                    $user_id = $token; // since token is user_id
                }
            }
            if (!$user_id) {
                http_response_code(401);
                echo json_encode(['error' => 'Unauthorized']);
                return;
            }

            // Host-specific stats
            // Total Events created by host
            $stmt = $pdo->prepare("SELECT COUNT(*) as total_events FROM events WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $totalEvents = $stmt->fetch()['total_events'];

            // Upcoming Events (future dates)
            $stmt = $pdo->prepare("SELECT COUNT(*) as upcoming_events FROM events WHERE user_id = ? AND event_date > CURDATE()");
            $stmt->execute([$user_id]);
            $upcomingEvents = $stmt->fetch()['upcoming_events'];

            // Completed Events (past dates)
            $stmt = $pdo->prepare("SELECT COUNT(*) as completed_events FROM events WHERE user_id = ? AND event_date < CURDATE()");
            $stmt->execute([$user_id]);
            $completedEvents = $stmt->fetch()['completed_events'];

            // Total Attendees (accepted invitations across all host's events)
            // Recent events for activity table
            $stmt = $pdo->prepare("SELECT event_id, event_name, event_date, status FROM events WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
            $stmt->execute([$user_id]);
            $recentEvents = $stmt->fetchAll();

            // Next upcoming event
            $stmt = $pdo->prepare("SELECT e.event_name, e.event_date, e.event_type, e.guest_count, e.status, 
                COALESCE(v.name, '') AS venue_name, COALESCE(et.name, '') AS theme_name 
                FROM events e 
                LEFT JOIN venues v ON e.chosen_venue_id = v.venue_id 
                LEFT JOIN event_themes et ON e.theme_id = et.id 
                WHERE e.user_id = ? AND e.event_date >= CURDATE() 
                ORDER BY e.event_date ASC LIMIT 1");
            $stmt->execute([$user_id]);
            $nextEvent = $stmt->fetch() ?: null;

            // Pending invitations across host events
            $stmt = $pdo->prepare("SELECT COUNT(*) as pending_invitations 
                FROM invitations i 
                JOIN events e ON i.event_id = e.event_id 
                WHERE e.user_id = ? AND i.status = 'pending'");
            $stmt->execute([$user_id]);
            $pendingInvitations = (int)($stmt->fetch()['pending_invitations'] ?? 0);

            // Average guest count
            $stmt = $pdo->prepare("SELECT AVG(guest_count) as avg_guest_count 
                FROM events WHERE user_id = ? AND guest_count IS NOT NULL");
            $stmt->execute([$user_id]);
            $avgGuestCount = $stmt->fetch()['avg_guest_count'];
            $avgGuestCount = $avgGuestCount !== null ? round((float)$avgGuestCount, 1) : 0;

            // Monthly event trend (last 6 months)
            $stmt = $pdo->prepare("SELECT DATE_FORMAT(event_date, '%Y-%m') as month, COUNT(*) as count FROM events WHERE user_id = ? AND event_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY DATE_FORMAT(event_date, '%Y-%m') ORDER BY month");
            $stmt->execute([$user_id]);
            $monthlyTrend = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($monthlyTrend as $month => $count) {
                $monthlyTrend[$month] = (int)$count;
            }

            // Attendance distribution by event type
            $stmt = $pdo->prepare("SELECT e.event_type, COUNT(i.invitation_id) as count FROM events e LEFT JOIN invitations i ON e.event_id = i.event_id AND i.status = 'accepted' WHERE e.user_id = ? GROUP BY e.event_type");
            $stmt->execute([$user_id]);
            $attendanceByType = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($attendanceByType as $type => $count) {
                $attendanceByType[$type] = (int)$count;
            }

            if ($nextEvent) {
                $nextEvent['guest_count'] = $nextEvent['guest_count'] !== null ? (int)$nextEvent['guest_count'] : null;
            }

            echo json_encode([
                'total_events' => (int)$totalEvents,
                'upcoming_events' => (int)$upcomingEvents,
                'completed_events' => (int)$completedEvents,
                'recent_events' => $recentEvents,
                'next_event' => $nextEvent,
                'pending_invitations' => $pendingInvitations,
                'avg_guest_count' => $avgGuestCount,
                'monthly_trend' => $monthlyTrend,
                'attendance_by_type' => $attendanceByType
            ], JSON_PRETTY_PRINT);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
}

function callAI($data)
{
    $ch = curl_init('http://localhost:5000/recommend');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10); // 10 second timeout
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); // 5 second connection timeout
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error || $http_code !== 200) {
        error_log("AI service error: HTTP $http_code - $error");
        return ['venues' => [], 'themes' => [], 'food' => []];
    }
    
    $result = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("AI response JSON error: " . json_last_error_msg());
        return ['venues' => [], 'themes' => [], 'food' => []];
    }
    
    // Return full response (includes rec_id and recommendations)
    return $result;
}
