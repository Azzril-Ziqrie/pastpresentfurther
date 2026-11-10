<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/config/database.php';

header('Content-Type: application/json; charset=utf-8');

function ppfRespond(int $status, bool $ok, string $message = '', array $extra = []): void
{
    http_response_code($status);
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ppfRespond(405, false, 'Method not allowed.');
}

$action = isset($_POST['action']) ? (string) $_POST['action'] : '';
if (!in_array($action, ['login', 'register'], true)) {
    error_log('[auth_api] Unknown action. POST keys: ' . implode(',', array_keys($_POST)) . ' | raw: ' . file_get_contents('php://input'));
    ppfRespond(400, false, 'Unknown action.');
}

try {
    if ($action === 'login') {
        $email    = trim((string)($_POST['email']    ?? ''));
        $password = (string)($_POST['password']      ?? '');

        if ($email === '' || $password === '') {
            ppfRespond(422, false, 'Please enter your email and password.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            ppfRespond(422, false, 'Please enter a valid email address.');
        }

        $stmt = $pdo->prepare(
            'SELECT id, full_name, email, password, role, is_active
             FROM users WHERE email = :email LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || (int)($user['is_active'] ?? 0) !== 1) {
            ppfRespond(401, false, 'Account not found or inactive.');
        }
        if (!password_verify($password, (string)$user['password'])) {
            ppfRespond(401, false, 'Invalid email or password.');
        }

        $_SESSION['user'] = [
            'id'        => (int)$user['id'],
            'full_name' => $user['full_name'],
            'email'     => $user['email'],
            'role'      => $user['role'],
        ];

        $redirect = ($user['role'] === 'admin') ? 'admin/dashboard.php' : 'index.php';
        ppfRespond(200, true, 'Signed in.', [
            'user'     => $_SESSION['user'],
            'redirect' => $redirect,
        ]);
    }

    // register
    $fullName        = trim((string)($_POST['full_name']        ?? ''));
    $email           = trim((string)($_POST['email']            ?? ''));
    $password        = (string)($_POST['password']              ?? '');
    $confirmPassword = (string)($_POST['confirm_password']      ?? '');

    if ($fullName === '' || $email === '' || $password === '' || $confirmPassword === '') {
        ppfRespond(422, false, 'Please complete all required fields.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        ppfRespond(422, false, 'Please enter a valid email address.');
    }
    if (strlen($password) < 6) {
        ppfRespond(422, false, 'Password must be at least 6 characters.');
    }
    if ($password !== $confirmPassword) {
        ppfRespond(422, false, 'Password confirmation does not match.');
    }

    $check = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $check->execute(['email' => $email]);
    if ($check->fetch()) {
        ppfRespond(409, false, 'This email is already registered.');
    }

    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $insert = $pdo->prepare(
        'INSERT INTO users (full_name, email, password, role, is_active)
         VALUES (:full_name, :email, :password, :role, :is_active)'
    );
    $insert->execute([
        'full_name' => $fullName,
        'email'     => $email,
        'password'  => $hashed,
        'role'      => 'customer',
        'is_active' => 1,
    ]);

    $_SESSION['user'] = [
        'id'        => (int)$pdo->lastInsertId(),
        'full_name' => $fullName,
        'email'     => $email,
        'role'      => 'customer',
    ];

    ppfRespond(200, true, 'Account created.', [
        'user'     => $_SESSION['user'],
        'redirect' => 'index.php',
    ]);
} catch (Throwable $e) {
    error_log('[auth_api] ' . $e->getMessage());
    ppfRespond(500, false, 'Server error. Please try again.');
}
