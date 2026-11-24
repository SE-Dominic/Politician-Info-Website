<?php
require __DIR__ . '/laceDatabase.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Invalid request.');
}

$username    = trim($_POST['createAccountUsername'] ?? '');
$email       = trim($_POST['createUserEmail'] ?? '');
$password    = $_POST['createAccountPassword'] ?? '';
$confirm     = $_POST['createUserConfirmPassword'] ?? '';
$state       = trim($_POST['createUserState'] ?? '');
$district    = trim($_POST['createUserDistrict'] ?? '');


if ($username === '' || $email === '' || $password === '' || $state === '' ||
    $district === ''){
    http_response_code(400);
    exit('All fields are required.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    exit('Please enter a valid email address.');
}
if (strlen($username) < 3 || strlen($username) > 50) {
    http_response_code(400);
    exit('Username must be between 3 and 50 characters.');
}
if (strlen($password) < 8) {
    http_response_code(400);
    exit('Password must be at least 8 characters.');
}
if ($password !== $confirm) {
    http_response_code(400);
    exit('Passwords do not match.');
}
if (!isset(LACE_STATES[$state])) {
   http_response_code(400);
   exit('Invalid state');
}

$districtNum = filter_var($district, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 0, 'max_range' => 60],
]);
if ($districtNum === false) {
    http_response_code(400);
    exit('Invalid district.');
}

$hash = password_hash($password, PASSWORD_DEFAULT); //hash the password for security

try {
    $db = connectLaceDatabase();
    inLaceTransaction($db, function () use ($db, $username, $email, $hash, $state, $districtNum) {
        executeLaceStatement(
            $db,
            'INSERT INTO users (username, email, user_password) VALUES (?, ?, ?)',
            [$username, $email, $hash]
        )->close();
        $newUserId = $db->insert_id;

        executeLaceStatement(
            $db,
            'INSERT INTO constituency (user_id, state, district) VALUES (?, ?, ?)',
            [$newUserId, $state, $districtNum]
        )->close();
    });
    $db->close();
} catch (mysqli_sql_exception $e) {
    if ($e->getCode() === 1062) {
        http_response_code(409);
        exit('That username or email is already in use.');
    }
    error_log('Signup error: ' . $e->getMessage());
    http_response_code(500);
    exit('Something went wrong. Please try again.');
} catch (Throwable $e) {
    error_log('Signup error: ' . $e->getMessage());
    http_response_code(500);
    exit('Something went wrong. Please try again.');
}

header('Location: access.html');
