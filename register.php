<?php
session_start();
require_once __DIR__ . '/config/database.php';

if (isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$brandName = 'ALBRYCIA';
$brandLogo = 'assets/images/brand/logo.png';

$error   = '';
$message = '';

function old(string $key): string
{
    return htmlspecialchars($_POST[$key] ?? '', ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName        = trim($_POST['full_name']        ?? '');
    $email           = trim($_POST['email']            ?? '');
    $password        = $_POST['password']              ?? '';
    $confirmPassword = $_POST['confirm_password']      ?? '';

    if ($fullName === '' || $email === '' || $password === '' || $confirmPassword === '') {
        $error = 'Please complete all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Password confirmation does not match.';
    } else {
        try {
            $check = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $check->execute(['email' => $email]);

            if ($check->fetch()) {
                $error = 'This email is already registered.';
            } else {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                $insert = $pdo->prepare(
                    'INSERT INTO users (full_name, email, password, role, is_active)
                     VALUES (:full_name, :email, :password, :role, :is_active)'
                );
                $insert->execute([
                    'full_name' => $fullName,
                    'email'     => $email,
                    'password'  => $hashedPassword,
                    'role'      => 'customer',
                    'is_active' => 1,
                ]);

                $userId = (int)$pdo->lastInsertId();

                $_SESSION['user'] = [
                    'id'        => $userId,
                    'full_name' => $fullName,
                    'email'     => $email,
                    'role'      => 'customer',
                ];

                header('Location: index.php');
                exit;
            }
        } catch (Throwable $e) {
            $error = 'Failed to register account: ' . $e->getMessage();
        }
    }
}

/* pre-fill dari redirect index.php account modal */
$prefillName  = htmlspecialchars(trim($_GET['name']  ?? ''), ENT_QUOTES, 'UTF-8');
$prefillEmail = htmlspecialchars(trim($_GET['email'] ?? ''), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Register — <?= htmlspecialchars($brandName) ?></title>
  <style>
/* ── reset ───────────────────────────────────────────── */
*{margin:0;padding:0;box-sizing:border-box}
html{scroll-behavior:smooth}
body{background:#f3f3f0;color:#111;font-family:"Arial Narrow",Arial,Helvetica,sans-serif;text-transform:uppercase;letter-spacing:.35px;min-height:100vh;display:flex;flex-direction:column;animation:pageFadeIn .6s ease forwards}
a{color:inherit;text-decoration:none}
button,input{font:inherit}
img{display:block}

/* ── NAVBAR — identik index.php ──────────────────────── */
.navbar{width:100%;min-height:72px;background:#fff;color:#000;display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:24px;padding:0 28px;font-size:14px;letter-spacing:.5px;border-bottom:1px solid rgba(0,0,0,.08);flex-shrink:0}
.nav-left,.nav-right{display:flex;align-items:center}
.nav-left{gap:12px;justify-content:flex-start}
.nav-right{justify-content:flex-end;gap:24px}
.nav-center{display:flex;justify-content:center}
.brand{display:inline-flex;align-items:center;gap:10px;font-size:15px;letter-spacing:1.8px}
.brand-logo{width:28px;height:28px;object-fit:contain;flex:0 0 auto}
.nav-link{font-size:14px;letter-spacing:.5px;transition:opacity .2s}
.nav-link:hover{opacity:.65}

/* ── MAIN CONTENT ────────────────────────────────────── */
.page-body{flex:1;display:flex;align-items:center;justify-content:center;padding:40px 20px 60px}

/* ── SPLIT LAYOUT ────────────────────────────────────── */
.auth-shell{width:100%;max-width:940px;display:grid;grid-template-columns:1fr 1fr;background:#fff;box-shadow:0 2px 40px rgba(0,0,0,.07)}

/* ── LEFT — brand panel ──────────────────────────────── */
.auth-brand{background:#111;color:#fff;padding:52px 44px;display:flex;flex-direction:column;justify-content:space-between;position:relative;overflow:hidden}
.auth-brand::before{content:'';position:absolute;top:-60px;right:-60px;width:280px;height:280px;border:1px solid rgba(255,255,255,.07);border-radius:50%;pointer-events:none}
.auth-brand::after{content:'';position:absolute;bottom:-40px;left:-40px;width:200px;height:200px;border:1px solid rgba(255,255,255,.05);border-radius:50%;pointer-events:none}
.brand-top{display:flex;align-items:center;gap:12px}
.brand-top img{width:30px;height:30px;object-fit:contain;filter:invert(1)}
.brand-top-name{font-size:15px;letter-spacing:2px}
.brand-tagline{font-size:clamp(24px,2.8vw,36px);font-weight:400;line-height:1.1;letter-spacing:.5px;max-width:260px;color:#fff}
.brand-tagline em{font-style:normal;opacity:.45;display:block;font-size:.55em;letter-spacing:1.5px;margin-bottom:10px}
.brand-perks{display:flex;flex-direction:column;gap:14px;margin-top:32px}
.perk{display:flex;align-items:flex-start;gap:12px;font-size:12px;letter-spacing:.5px;opacity:.7;line-height:1.5}
.perk-dot{width:5px;height:5px;background:#fff;border-radius:50%;flex-shrink:0;margin-top:6px;opacity:.5}
.brand-foot{font-size:11px;letter-spacing:.8px;opacity:.35}

/* ── RIGHT — form panel ──────────────────────────────── */
.auth-form-panel{padding:48px 44px;display:flex;flex-direction:column;justify-content:center;overflow-y:auto}
.auth-eyebrow{font-size:11px;letter-spacing:1.8px;color:#999;margin-bottom:16px}
.auth-title{font-size:clamp(26px,2.8vw,36px);font-weight:400;letter-spacing:.6px;line-height:1.05;margin-bottom:6px}
.auth-subtitle{font-size:12px;color:#888;letter-spacing:.4px;text-transform:none;line-height:1.6;margin-bottom:28px}

/* ── PROGRESS STEPS ──────────────────────────────────── */
.form-steps{display:flex;gap:4px;margin-bottom:28px}
.step{height:2px;flex:1;background:#e8e8e8;transition:background .3s}
.step.done{background:#111}
.step.active{background:#666}

/* ── NOTICE ──────────────────────────────────────────── */
.notice{margin-bottom:18px;padding:13px 16px;border:1px solid rgba(0,0,0,.12);background:#fafafa;font-size:12px;letter-spacing:.3px;color:#c0392b;text-transform:none;line-height:1.5}

/* ── FORM FIELDS ─────────────────────────────────────── */
.fields-grid{display:grid;gap:0}
.field{display:flex;flex-direction:column;gap:7px;margin-bottom:13px}
.field label{font-size:11px;letter-spacing:1.2px;color:#555}
.field input{width:100%;min-height:46px;border:1px solid #d0d0d0;background:#fafafa;padding:0 14px;outline:none;color:#111;font-size:13px;letter-spacing:.3px;text-transform:none;transition:border-color .2s,background .2s}
.field input:focus{border-color:#111;background:#fff}
.field input::placeholder{color:#bbb;text-transform:uppercase;font-size:11px;letter-spacing:.6px}
.field input.input-error{border-color:#c0392b}

/* password strength ──────────────────────────────────── */
.pw-strength{margin-top:6px;display:flex;gap:3px}
.pw-bar{flex:1;height:3px;background:#e8e8e8;border-radius:2px;transition:background .25s}
.pw-hint{font-size:10px;letter-spacing:.5px;color:#aaa;margin-top:5px;text-transform:none;height:14px;transition:color .2s}

/* ── SUBMIT ──────────────────────────────────────────── */
.submit-row{margin-top:8px}
.btn-submit{width:100%;min-height:50px;background:#111;color:#fff;border:1px solid #111;font-size:12px;letter-spacing:1.2px;cursor:pointer;transition:background .2s;display:flex;align-items:center;justify-content:center;gap:10px;font-family:inherit;text-transform:uppercase}
.btn-submit:hover{background:#333}
.btn-submit:disabled{background:#aaa;border-color:#aaa;cursor:not-allowed}
.btn-arrow{font-size:16px;transition:transform .2s}
.btn-submit:hover .btn-arrow{transform:translateX(4px)}

/* ── DIVIDER ─────────────────────────────────────────── */
.auth-divider{display:flex;align-items:center;gap:12px;margin:18px 0;color:#ccc;font-size:11px;letter-spacing:.8px}
.auth-divider::before,.auth-divider::after{content:'';flex:1;height:1px;background:#e8e8e8}

/* ── FOOTER LINK ─────────────────────────────────────── */
.auth-foot{font-size:12px;color:#777;letter-spacing:.3px;text-transform:none;line-height:1.6}
.auth-foot a{color:#111;text-decoration:underline;text-underline-offset:3px;transition:opacity .2s}
.auth-foot a:hover{opacity:.65}

/* ── SITE FOOTER ─────────────────────────────────────── */
.site-footer{padding:22px 28px;border-top:1px solid rgba(0,0,0,.07);background:#fff;display:flex;justify-content:space-between;align-items:center;font-size:11px;color:#aaa;letter-spacing:.5px;flex-shrink:0}
.site-footer a{color:#aaa;transition:color .2s}
.site-footer a:hover{color:#111}

/* ── RESPONSIVE ──────────────────────────────────────── */
@media(max-width:900px){
  .navbar{grid-template-columns:auto 1fr;padding:0 20px;gap:16px}
  .nav-center{justify-content:flex-end}
  .auth-shell{grid-template-columns:1fr;max-width:480px}
  .auth-brand{display:none}
  .auth-form-panel{padding:36px 28px}
}
@media(max-width:560px){
  .navbar{padding:0 14px}
  .page-body{padding:20px 14px 36px}
  .auth-form-panel{padding:28px 18px}
  .site-footer{flex-direction:column;gap:8px;text-align:center}
}

@keyframes pageFadeIn{from{opacity:0}to{opacity:1}}
@keyframes slideUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
.auth-shell{animation:slideUp .55s ease both;animation-delay:.1s;opacity:0}
  </style>
</head>
<body>

  <!-- ── NAVBAR ──────────────────────────────────────── -->
  <header class="navbar">
    <div class="nav-left">
      <a href="index.php" class="brand">
        <img src="<?= htmlspecialchars($brandLogo) ?>" alt="<?= htmlspecialchars($brandName) ?> logo" class="brand-logo">
        <span><?= htmlspecialchars($brandName) ?></span>
      </a>
    </div>
    <div class="nav-center"></div>
    <div class="nav-right">
      <a href="index.php" class="nav-link">Shop</a>
      <a href="login.php" class="nav-link">Login</a>
    </div>
  </header>

  <!-- ── PAGE BODY ────────────────────────────────────── -->
  <div class="page-body">
    <div class="auth-shell">

      <!-- Brand panel kiri -->
      <div class="auth-brand">
        <div class="brand-top">
          <img src="<?= htmlspecialchars($brandLogo) ?>" alt="<?= htmlspecialchars($brandName) ?>">
          <span class="brand-top-name"><?= htmlspecialchars($brandName) ?></span>
        </div>
        <div>
          <div class="brand-tagline">
            <em>Join Us</em>
            Create your account
          </div>
          <div class="brand-perks">
            <div class="perk"><span class="perk-dot"></span><span>Track orders and view history</span></div>
            <div class="perk"><span class="perk-dot"></span><span>Faster checkout with saved details</span></div>
            <div class="perk"><span class="perk-dot"></span><span>Early access to new arrivals</span></div>
          </div>
        </div>
        <div class="brand-foot">&copy; <?= date('Y') ?> <?= htmlspecialchars($brandName) ?></div>
      </div>

      <!-- Form panel kanan -->
      <div class="auth-form-panel">
        <div class="auth-eyebrow"><?= htmlspecialchars($brandName) ?></div>
        <h1 class="auth-title">Create Account</h1>
        <p class="auth-subtitle">Fill in your details below to register. It only takes a moment.</p>

        <!-- Progress bar step indicator -->
        <div class="form-steps" aria-hidden="true">
          <div class="step active" id="step1"></div>
          <div class="step" id="step2"></div>
          <div class="step" id="step3"></div>
          <div class="step" id="step4"></div>
        </div>

        <?php if ($error !== ''): ?>
          <div class="notice" role="alert"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="" id="registerForm" novalidate>

          <div class="field">
            <label for="full_name">Full Name</label>
            <input
              type="text"
              id="full_name"
              name="full_name"
              value="<?= $prefillName ?: old('full_name') ?>"
              placeholder="Your Full Name"
              autocomplete="name"
              required
            >
          </div>

          <div class="field">
            <label for="email">Email Address</label>
            <input
              type="email"
              id="email"
              name="email"
              value="<?= $prefillEmail ?: old('email') ?>"
              placeholder="your@email.com"
              autocomplete="email"
              required
            >
          </div>

          <div class="field">
            <label for="password">Password</label>
            <input
              type="password"
              id="password"
              name="password"
              placeholder="Min. 6 characters"
              autocomplete="new-password"
              required
            >
            <div class="pw-strength" aria-hidden="true">
              <div class="pw-bar" id="bar1"></div>
              <div class="pw-bar" id="bar2"></div>
              <div class="pw-bar" id="bar3"></div>
            </div>
            <div class="pw-hint" id="pwHint"></div>
          </div>

          <div class="field">
            <label for="confirm_password">Confirm Password</label>
            <input
              type="password"
              id="confirm_password"
              name="confirm_password"
              placeholder="Repeat your password"
              autocomplete="new-password"
              required
            >
          </div>

          <div class="submit-row">
            <button type="submit" class="btn-submit" id="submitBtn">
              <span>Create Account</span>
              <span class="btn-arrow">→</span>
            </button>
          </div>
        </form>

        <div class="auth-divider">or</div>

        <div class="auth-foot">Already have an account? <a href="login.php">Login here</a></div>
        <div class="auth-foot" style="margin-top:8px"><a href="index.php">← Back to store</a></div>
      </div>

    </div>
  </div>

  <!-- ── SITE FOOTER ──────────────────────────────────── -->
  <footer class="site-footer">
    <span>&copy; <?= date('Y') ?> <?= htmlspecialchars($brandName) ?>. All rights reserved.</span>
    <span><a href="index.php">Shop</a> &nbsp;·&nbsp; <a href="login.php">Login</a></span>
  </footer>

  <script>
  (function () {
    'use strict';

    const pwInput   = document.getElementById('password');
    const confInput = document.getElementById('confirm_password');
    const bar1      = document.getElementById('bar1');
    const bar2      = document.getElementById('bar2');
    const bar3      = document.getElementById('bar3');
    const pwHint    = document.getElementById('pwHint');
    const steps     = [
      document.getElementById('step1'),
      document.getElementById('step2'),
      document.getElementById('step3'),
      document.getElementById('step4'),
    ];
    const inputs = [
      document.getElementById('full_name'),
      document.getElementById('email'),
      pwInput,
      confInput,
    ];

    /* password strength */
    function getPwStrength(pw) {
      let score = 0;
      if (pw.length >= 6)  score++;
      if (pw.length >= 10) score++;
      if (/[A-Z]/.test(pw) || /[0-9]/.test(pw)) score++;
      return score;
    }

    const strengthColors = ['#e74c3c', '#e67e22', '#27ae60'];
    const strengthLabels = ['Too short', 'Could be stronger', 'Strong password'];

    pwInput.addEventListener('input', function () {
      const pw = this.value;
      const score = getPwStrength(pw);
      const bars = [bar1, bar2, bar3];
      bars.forEach((bar, i) => {
        bar.style.background = i < score ? strengthColors[score - 1] : '#e8e8e8';
      });
      pwHint.textContent = pw.length === 0 ? '' : strengthLabels[Math.min(score - 1, 2)] || 'Too short';
      pwHint.style.color = pw.length === 0 ? '#aaa' : strengthColors[Math.min(score - 1, 2)] || '#e74c3c';
    });

    /* step progress — fills as user fills each field */
    inputs.forEach((input, idx) => {
      input.addEventListener('input', updateSteps);
      input.addEventListener('blur',  updateSteps);
    });

    function updateSteps() {
      inputs.forEach((input, idx) => {
        const filled = input.value.trim() !== '';
        steps[idx].className = 'step' + (filled ? ' done' : (idx === getFirstEmpty() ? ' active' : ''));
      });
    }

    function getFirstEmpty() {
      return inputs.findIndex(i => i.value.trim() === '');
    }

    /* confirm password live validation */
    confInput.addEventListener('input', function () {
      if (pwInput.value && this.value && this.value !== pwInput.value) {
        this.classList.add('input-error');
      } else {
        this.classList.remove('input-error');
      }
    });

    /* submit */
    document.getElementById('registerForm').addEventListener('submit', function (e) {
      const pw   = pwInput.value;
      const conf = confInput.value;

      if (pw !== conf) {
        e.preventDefault();
        confInput.classList.add('input-error');
        confInput.focus();
        return;
      }

      const btn = document.getElementById('submitBtn');
      btn.disabled = true;
      btn.querySelector('span').textContent = 'Creating Account...';
    });
  })();
  </script>
</body>
</html>
