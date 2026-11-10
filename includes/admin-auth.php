<?php
/* ─────────────────────────────────────────────────────────────
   ADMIN AUTH GATE
   Include at the very top of every admin page (before any output):
       require_once __DIR__ . '/../includes/admin-auth.php';

   • Single admin password, stored only as a bcrypt hash in table `admin_auth`.
   • Unauthenticated GET  → login screen.   Unauthenticated POST → 401.
   • Helpers for pages: admin_csrf_token(), admin_csrf_ok(), admin_change_password().
───────────────────────────────────────────────────────────── */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

require_once __DIR__ . '/../config/database.php';

// bcrypt hash of the initial password (the plain text is never stored in code).
const ADMIN_DEFAULT_HASH = '$2y$10$h0bweO/0vymoeoizkIUQveynTt3qmed7qEabP.X1KfLtrmPEwYW8u';
const ADMIN_IDLE_SECONDS = 28800; // 8 hours

function admin_stored_hash(PDO $pdo): string
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS admin_auth (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            password_hash VARCHAR(255) NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $hash = $pdo->query('SELECT password_hash FROM admin_auth WHERE id = 1')->fetchColumn();
    if (!$hash) {
        $pdo->prepare('INSERT INTO admin_auth (id, password_hash) VALUES (1, :h)')->execute(['h' => ADMIN_DEFAULT_HASH]);
        return ADMIN_DEFAULT_HASH;
    }
    return (string) $hash;
}

function admin_csrf_token(): string
{
    if (empty($_SESSION['admin_csrf'])) $_SESSION['admin_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['admin_csrf'];
}

function admin_csrf_ok(?string $token): bool
{
    return is_string($token) && !empty($_SESSION['admin_csrf']) && hash_equals($_SESSION['admin_csrf'], $token);
}

/** @return string|null error message, or null on success */
function admin_change_password(PDO $pdo, string $current, string $new, string $confirm): ?string
{
    $hash = admin_stored_hash($pdo);
    if (!password_verify($current, $hash))      return 'Current password is incorrect.';
    if (strlen($new) < 8)                       return 'New password must be at least 8 characters.';
    if ($new !== $confirm)                      return 'New password and confirmation do not match.';
    if (hash_equals($current, $new))            return 'New password must be different from the current one.';
    $pdo->prepare('UPDATE admin_auth SET password_hash = :h WHERE id = 1')
        ->execute(['h' => password_hash($new, PASSWORD_DEFAULT)]);
    session_regenerate_id(true);
    $_SESSION['admin_auth'] = ['t' => time()];
    return null;
}

/* ── logout ─────────────────────────────────────────────── */
if (isset($_GET['admin_logout'])) {
    unset($_SESSION['admin_auth'], $_SESSION['admin_csrf']);
    session_regenerate_id(true);
    header('Location: dashboard.php');
    exit;
}

/* ── already signed in? ─────────────────────────────────── */
$adminAuthed = isset($_SESSION['admin_auth']['t'])
    && (time() - (int) $_SESSION['admin_auth']['t']) < ADMIN_IDLE_SECONDS;
if ($adminAuthed) {
    $_SESSION['admin_auth']['t'] = time();
    header('Cache-Control: no-store');
    return;
}
unset($_SESSION['admin_auth']);

/* ── sign-in attempt ────────────────────────────────────── */
$adminLoginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_login'])) {
    $locked = (int) ($_SESSION['admin_lock_until'] ?? 0);
    if ($locked > time()) {
        $adminLoginError = 'Too many attempts. Try again in ' . ($locked - time()) . ' seconds.';
    } elseif (admin_csrf_ok($_POST['csrf'] ?? null) && password_verify((string) ($_POST['password'] ?? ''), admin_stored_hash($pdo))) {
        session_regenerate_id(true);
        $_SESSION['admin_auth'] = ['t' => time()];
        unset($_SESSION['admin_fail'], $_SESSION['admin_lock_until']);
        header('Location: ' . ($_SERVER['REQUEST_URI'] ?? 'dashboard.php'));
        exit;
    } else {
        usleep(600000); // slow down guessing
        $_SESSION['admin_fail'] = (int) ($_SESSION['admin_fail'] ?? 0) + 1;
        if ($_SESSION['admin_fail'] >= 5) {
            $_SESSION['admin_lock_until'] = time() + 60;
            $_SESSION['admin_fail'] = 0;
            $adminLoginError = 'Too many attempts. Try again in 60 seconds.';
        } else {
            $adminLoginError = 'Incorrect password.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    // AJAX / form endpoints must never answer an anonymous request.
    http_response_code(401);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Unauthorized');
}

/* ── login screen ───────────────────────────────────────── */
header('Cache-Control: no-store');
http_response_code($adminLoginError !== '' ? 401 : 200);
$csrf = admin_csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex,nofollow">
  <title>Admin sign in — pastpresentfurther</title>
  <link rel="icon" type="image/png" href="../assets/images/brand/logo-admin.png">
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{min-height:100vh;min-height:100svh;display:grid;place-items:center;padding:24px;background:#f5f5f7;color:#1d1d1f;font-family:-apple-system,BlinkMacSystemFont,"SF Pro Text","Inter","Segoe UI",Roboto,Helvetica,Arial,sans-serif;font-size:14px;-webkit-font-smoothing:antialiased}
    .card{width:100%;max-width:360px;padding:32px 28px;background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:18px;box-shadow:0 20px 60px rgba(0,0,0,.08);animation:in .5s cubic-bezier(.32,.72,0,1)}
    .logo{display:block;width:44px;height:44px;margin:0 auto 18px;object-fit:contain;border-radius:10px}
    h1{margin-bottom:4px;font-size:20px;font-weight:650;letter-spacing:-.02em;text-align:center}
    p.sub{margin-bottom:22px;color:#6e6e73;text-align:center}
    label{display:block;margin-bottom:6px;font-size:12px;font-weight:600;color:#6e6e73}
    input[type=password]{width:100%;height:44px;padding:0 14px;border:1px solid #d2d2d7;border-radius:12px;font:inherit;font-size:15px;outline:none;transition:border-color .15s,box-shadow .15s}
    input[type=password]:focus{border-color:#0071e3;box-shadow:0 0 0 3px rgba(0,113,227,.18)}
    button{width:100%;height:44px;margin-top:14px;border:none;border-radius:12px;background:#1d1d1f;color:#fff;font:inherit;font-weight:600;cursor:pointer;transition:background .15s,transform .1s}
    button:hover{background:#000}
    button:active{transform:scale(.99)}
    .err{margin-bottom:14px;padding:10px 12px;border-radius:10px;background:#fdeaea;color:#9d1f1f;font-size:13px}
    @keyframes in{from{opacity:0;transform:translateY(8px) scale(.98)}to{opacity:1;transform:none}}
    @media(prefers-reduced-motion:reduce){.card{animation:none}}
  </style>
</head>
<body>
  <form class="card" method="post" autocomplete="off">
    <img class="logo" src="../assets/images/brand/logo-admin.png" alt="">
    <h1>Admin</h1>
    <p class="sub">Enter the admin password to continue.</p>
    <?php if ($adminLoginError !== ''): ?><div class="err" role="alert"><?= htmlspecialchars($adminLoginError) ?></div><?php endif; ?>
    <input type="hidden" name="admin_login" value="1">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
    <label for="pw">Password</label>
    <input type="password" id="pw" name="password" autofocus required autocomplete="current-password">
    <button type="submit">Sign in</button>
  </form>
</body>
</html>
<?php
exit;
