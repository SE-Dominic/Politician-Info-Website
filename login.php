<?php
session_start();
require __DIR__ . '/laceDatabase.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Invalid request.');
}

$login    = trim($_POST['accountSignIn'] ?? '');
$password = $_POST['accountPassword'] ?? '';

if ($login === '' || $password === '') {
    http_response_code(400);
    exit('Please enter your username/email and password.');
}

try {
    $db = connectLaceDatabase();
    $rows = laceRows_SELECT(
        $db,
        'SELECT user_id, username, user_password, role, status
         FROM users WHERE username = ? OR email = ? LIMIT 1',
        [$login, $login]
    );
    $db->close();
} catch (Throwable $e) {
    error_log('Login error: ' . $e->getMessage());
    http_response_code(500);
    exit('Something went wrong. Please try again.');
}

$user = $rows[0] ?? null;

if (!$user || !password_verify($password, $user['user_password'])) {
    http_response_code(401);
    exit('Invalid username/email or password.');
}
if ($user['status'] !== 'active') {
    http_response_code(403);
    exit('This account is not active.');
}

session_regenerate_id(true);
$_SESSION['user_id']  = $user['user_id'];
$_SESSION['username'] = $user['username'];
$_SESSION['role']     = $user['role'];

header('Location: home.html');
