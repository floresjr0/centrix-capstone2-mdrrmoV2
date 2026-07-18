<?php
ob_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/mail.php';

$pdo = db();

if (current_user()) {
    redirect_by_role();
}

$email         = trim($_GET['email'] ?? $_POST['email'] ?? '');
$errors        = [];
$notice        = '';
$resetUserId   = $_SESSION['password_reset_user_id'] ?? null;
$hasResetSession = $resetUserId && filter_var($email, FILTER_VALIDATE_EMAIL);

// OTP verified flag – used to show password fields
$otpVerified = isset($_SESSION['otp_verified']) && $_SESSION['otp_verified'] === true;

function issue_password_reset_otp(PDO $pdo, array $user): bool
{
    $otp       = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $otpHash   = password_hash($otp, PASSWORD_DEFAULT);
    $expiresAt = date('Y-m-d H:i:s', time() + 15 * 60);

    $upd = $pdo->prepare("UPDATE users SET
        otp_code_hash  = ?,
        otp_expires_at = ?,
        otp_purpose    = 'password_reset'
        WHERE id = ?");
    $upd->execute([$otpHash, $expiresAt, $user['id']]);

    $displayName = trim($user['full_name'] ?? '') ?: $user['email'];

    return send_otp_email(
        $user['email'],
        $displayName,
        $otp,
        'Your MDRRMO password reset code',
        'Your password reset code is'
    );
}

// ─── RESEND CODE ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend'])) {
    if (!$hasResetSession) {
        $errors[] = 'Password reset session expired. Please request a new code.';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? AND id = ? AND is_active = 1');
        $stmt->execute([$email, $resetUserId]);
        $user = $stmt->fetch();

        if (!$user) {
            $errors[] = 'Account not found.';
        } elseif (issue_password_reset_otp($pdo, $user)) {
            $notice = 'A new reset code was sent to your email.';
        } else {
            $errors[] = 'Could not send a new code right now. Please try again.';
        }
    }
}

// ─── VERIFY OTP (Step 1) ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_otp'])) {
    $otp = trim($_POST['otp'] ?? '');

    if (!$hasResetSession) {
        $errors[] = 'Password reset session expired. Please request a new code.';
    } elseif ($otp === '') {
        $errors[] = 'Please enter the reset code from your email.';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? AND id = ? AND is_active = 1');
        $stmt->execute([$email, $resetUserId]);
        $user = $stmt->fetch();

        if (!$user) {
            $errors[] = 'Account not found.';
        } elseif (($user['otp_purpose'] ?? '') !== 'password_reset') {
            $errors[] = 'No active reset code. Please request a new one.';
        } elseif (empty($user['otp_code_hash']) || empty($user['otp_expires_at'])) {
            $errors[] = 'No active reset code. Please request a new one.';
        } elseif (strtotime($user['otp_expires_at']) < time()) {
            $errors[] = 'Reset code has expired. Please request a new one.';
        } elseif (!password_verify($otp, $user['otp_code_hash'])) {
            $errors[] = 'Incorrect reset code.';
        } else {
            // OTP is correct – mark as verified and redirect to password step
            $_SESSION['otp_verified'] = true;
            header('Location: ' . app_url('pages/reset_password.php?email=' . urlencode($email) . '&step=password'));
            exit;
        }
    }
}

// ─── RESET PASSWORD (Step 2) ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (!$otpVerified) {
        $errors[] = 'OTP verification required. Please verify your code first.';
    } elseif (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? AND id = ? AND is_active = 1');
        $stmt->execute([$email, $resetUserId]);
        $user = $stmt->fetch();

        if (!$user) {
            $errors[] = 'Account not found.';
        } else {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            $upd = $pdo->prepare("UPDATE users SET
                password_hash   = ?,
                otp_code_hash   = NULL,
                otp_expires_at  = NULL,
                otp_purpose     = NULL
                WHERE id = ?");
            $upd->execute([$passwordHash, $user['id']]);

            unset($_SESSION['otp_verified']);
            unset($_SESSION['password_reset_user_id']);

            header('Location: ' . app_url('index.php?reset=1'));
            exit;
        }
    }
}

// If we're on the password step but OTP isn't verified, redirect back
if (isset($_GET['step']) && $_GET['step'] === 'password' && !$otpVerified) {
    header('Location: ' . app_url('pages/reset_password.php?email=' . urlencode($email)));
    exit;
}

// Initial load – if no session, show a generic notice
if (!$hasResetSession && $email !== '' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $notice = 'If an account exists for this email, check your inbox for the reset code.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reset Password — MDRRMO San Ildefonso</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ================================================
           RESET & BASE
           ================================================ */
        *,
        *::before,
        *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html,
        body {
            width: 100%;
            height: 100%;
            font-family: 'Poppins', sans-serif;
            background: #0d0806;
            overflow: hidden;
            -webkit-font-smoothing: antialiased;
        }

        ::-webkit-scrollbar {
            width: 0 !important;
            height: 0 !important;
            background: transparent !important;
        }
        * {
            scrollbar-width: none !important;
            -ms-overflow-style: none !important;
        }

        body {
            display: flex;
            align-items: center;
            justify-content: center;
            background:
                radial-gradient(ellipse 70% 60% at 65% 25%, rgba(160, 70, 5, 0.80) 0%, transparent 65%),
                linear-gradient(160deg, #5c1800 0%, #3a0e02 40%, #1c0600 100%);
            min-height: 100vh;
            padding: 24px;
        }

        /* ================================================
           CARD – MODAL STYLE
           ================================================ */
        .reset-card {
            width: 100%;
            max-width: 480px;
            background: #ffffff;
            border-radius: 28px;
            padding: 40px 36px 32px;
            box-shadow: 0 32px 80px rgba(0, 0, 0, 0.50);
            position: relative;
            animation: cardIn 0.6s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes cardIn {
            0% {
                opacity: 0;
                transform: translateY(24px) scale(0.96);
            }
            100% {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        /* ─── CLOSE BUTTON (X) ─── */
        .close-btn {
            position: absolute;
            top: 18px;
            right: 22px;
            background: none;
            border: none;
            font-size: 28px;
            line-height: 1;
            color: #b0a69e;
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 8px;
            transition: all 0.25s ease;
            font-weight: 300;
            font-family: 'Poppins', sans-serif;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .close-btn:hover {
            color: #c0391e;
            background: rgba(192, 57, 30, 0.06);
            transform: rotate(90deg);
        }

        /* ─── HEADER ─── */
        .reset-title {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 36px;
            letter-spacing: 3px;
            color: #1a0a06;
            text-align: center;
            margin-bottom: 6px;
        }

        .reset-sub {
            text-align: center;
            font-size: 13px;
            color: #888;
            line-height: 1.6;
            margin-bottom: 28px;
        }
        .reset-sub strong {
            color: #444;
            font-weight: 600;
        }

        /* ─── MESSAGES ─── */
        .msg-error,
        .msg-notice,
        .msg-success {
            border-radius: 10px;
            padding: 12px 16px;
            margin-bottom: 18px;
            font-size: 13px;
            font-weight: 500;
        }
        .msg-error {
            background: rgba(192, 57, 30, 0.08);
            border: 1px solid rgba(192, 57, 30, 0.35);
            color: #c0391e;
        }
        .msg-notice {
            background: rgba(234, 179, 8, 0.12);
            border: 1px solid rgba(234, 179, 8, 0.35);
            color: #a16207;
        }
        .msg-success {
            background: rgba(21, 128, 61, 0.08);
            border: 1px solid rgba(21, 128, 61, 0.35);
            color: #15803d;
        }

        /* ─── OTP GROUP ─── */
        .otp-group {
            display: flex;
            gap: 8px;
            justify-content: center;
            margin-bottom: 20px;
        }
        .otp-group input {
            width: 48px;
            height: 56px;
            text-align: center;
            font-size: 22px;
            font-weight: 700;
            font-family: 'Poppins', sans-serif;
            color: #1a0a06;
            background: #f7f7f7;
            border: 1.5px solid #e8e8e8;
            border-radius: 12px;
            outline: none;
            transition: border-color 0.25s, box-shadow 0.25s, background 0.25s;
        }
        .otp-group input:focus {
            border-color: #c0391e;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(192, 57, 30, 0.10);
        }

        /* ─── TIMER ─── */
        .timer {
            text-align: center;
            font-size: 12px;
            color: #aaa;
            margin-bottom: 22px;
        }
        .timer span {
            color: #c0391e;
            font-weight: 600;
        }

        /* ─── BUTTONS ─── */
        .btn-primary {
            width: 100%;
            padding: 14px;
            margin-top: 8px;
            background: linear-gradient(135deg, #c0391e 0%, #a02d15 100%);
            color: #fff;
            font-family: 'Poppins', sans-serif;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            box-shadow: 0 4px 20px rgba(192, 57, 30, 0.35);
            transition: transform 0.16s ease, box-shadow 0.16s ease;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 28px rgba(192, 57, 30, 0.45);
        }
        .btn-primary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none !important;
        }

        /* ─── FIELDS ─── */
        .field {
            margin-bottom: 16px;
        }
        .field label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #666;
            margin-bottom: 6px;
        }
        .field input {
            width: 100%;
            padding: 13px 14px;
            border: 1.5px solid #e8e8e8;
            border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            background: #f7f7f7;
            outline: none;
            transition: border-color 0.25s, box-shadow 0.25s, background 0.25s;
        }
        .field input:focus {
            border-color: #c0391e;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(192, 57, 30, 0.10);
        }

        /* ─── LINKS ROW ─── */
        .links-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
            font-size: 12.5px;
            gap: 12px;
        }
        .links-row a,
        .links-row button {
            color: #c0391e;
            font-weight: 600;
            text-decoration: none;
            background: none;
            border: none;
            cursor: pointer;
            font-family: 'Poppins', sans-serif;
            font-size: 12.5px;
            padding: 0;
        }
        .links-row a:hover,
        .links-row button:hover {
            text-decoration: underline;
        }

        /* ─── STEP TOGGLE ─── */
        .step-password {
            display: none;
        }
        .step-password.active {
            display: block;
        }
        .step-otp.hidden {
            display: none;
        }

        /* ================================================
           RESPONSIVE
           ================================================ */
        @media (max-width: 520px) {
            body {
                padding: 16px;
            }
            .reset-card {
                padding: 32px 20px 24px;
                border-radius: 20px;
            }
            .reset-title {
                font-size: 30px;
            }
            .otp-group input {
                width: 42px;
                height: 50px;
                font-size: 20px;
            }
            .btn-primary {
                font-size: 12px;
                padding: 12px;
            }
            .close-btn {
                top: 14px;
                right: 16px;
                font-size: 24px;
            }
        }

        @media (max-width: 400px) {
            .otp-group {
                gap: 6px;
            }
            .otp-group input {
                width: 36px;
                height: 44px;
                font-size: 18px;
            }
        }
    </style>
</head>
<body>

<div class="reset-card">

    <!-- ─── CLOSE BUTTON ─── -->
    <a href="../index.php" class="close-btn" aria-label="Close and return to login">×</a>

    <!-- ─── HEADER ─── -->
    <div class="reset-title">Reset Password</div>
    <div class="reset-sub">
        <?php if ($otpVerified): ?>
            <strong>OTP verified.</strong> Choose a new password.
        <?php else: ?>
            Enter the 6-digit code sent to<br>
            <?php if ($email): ?>
                <strong><?php echo htmlspecialchars($email); ?></strong>
            <?php else: ?>
                your email address.
            <?php endif; ?>
            <br>We'll verify your identity.
        <?php endif; ?>
    </div>

    <!-- ─── MESSAGES ─── -->
    <?php if ($errors): ?>
        <div class="msg-error">
            <?php foreach ($errors as $err): ?>
                ⚠ <?php echo htmlspecialchars($err); ?><br>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($notice): ?>
        <div class="msg-notice">ℹ <?php echo htmlspecialchars($notice); ?></div>
    <?php endif; ?>

    <!-- ════════════════════════════════════════════════════════ -->
    <!-- STEP 1 – OTP VERIFICATION                              -->
    <!-- ════════════════════════════════════════════════════════ -->
    <div class="step-otp <?php echo $otpVerified ? 'hidden' : ''; ?>" id="step-otp">
        <?php if ($hasResetSession): ?>
        <form method="post" id="otp-form">
            <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
            <input type="hidden" name="otp" id="otp-hidden">

            <!-- OTP Boxes -->
            <div class="otp-group" id="otp-boxes">
                <?php for ($i = 0; $i < 6; $i++): ?>
                <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="one-time-code">
                <?php endfor; ?>
            </div>

            <!-- Timer -->
            <div class="timer">Code expires in <span id="countdown">15:00</span></div>

            <!-- Verify Button -->
            <button type="submit" class="btn-primary" id="verify-btn" disabled name="verify_otp" value="1">
                Verify Code
            </button>
        </form>

        <!-- Links Row -->
        <div class="links-row">
            <form method="post" style="margin:0;">
                <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
                <button type="submit" name="resend" value="1">Resend code</button>
            </form>
            <a href="forgot_password.php">← Request new code</a>
        </div>
        <?php else: ?>
        <div class="links-row" style="justify-content:center;">
            <a href="forgot_password.php">← Back to forgot password</a>
        </div>
        <?php endif; ?>
    </div>

    <!-- ════════════════════════════════════════════════════════ -->
    <!-- STEP 2 – NEW PASSWORD (visible only after OTP verified) -->
    <!-- ════════════════════════════════════════════════════════ -->
    <div class="step-password <?php echo $otpVerified ? 'active' : ''; ?>" id="step-password">
        <?php if ($otpVerified && $hasResetSession): ?>
        <form method="post" id="reset-form">
            <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">

            <!-- New Password -->
            <div class="field">
                <label for="password">New Password</label>
                <input type="password" id="password" name="password" required minlength="8"
                       placeholder="At least 8 characters">
            </div>

            <!-- Confirm Password -->
            <div class="field">
                <label for="confirm_password">Confirm Password</label>
                <input type="password" id="confirm_password" name="confirm_password" required minlength="8"
                       placeholder="Repeat new password">
            </div>

            <!-- Reset Button -->
            <button type="submit" class="btn-primary" name="reset_password" value="1">
                Reset Password
            </button>
        </form>

        <div class="links-row" style="justify-content:center; margin-top:16px;">
            <a href="forgot_password.php">← Request new code</a>
        </div>
        <?php endif; ?>
    </div>

</div>

<script>
    (function () {
        var group  = document.getElementById('otp-boxes');
        var hidden = document.getElementById('otp-hidden');
        var btn    = document.getElementById('verify-btn');
        var form   = document.getElementById('otp-form');
        if (!group || !hidden || !btn || !form) return;

        var boxes = Array.from(group.querySelectorAll('input[type="text"]'));

        function collectValue() {
            return boxes.map(function (b) { return b.value; }).join('');
        }

        function updateState() {
            hidden.value = collectValue();
            btn.disabled = collectValue().length < 6;
        }

        boxes.forEach(function (box, i) {
            box.addEventListener('input', function (e) {
                var v = e.target.value.replace(/\D/g, '');
                if (v.length > 1) {
                    for (var j = 0; j < v.length && (i + j) < boxes.length; j++) {
                        boxes[i + j].value = v[j];
                    }
                    boxes[Math.min(i + v.length, boxes.length - 1)].focus();
                } else {
                    e.target.value = v;
                    if (v && i < boxes.length - 1) boxes[i + 1].focus();
                }
                updateState();
            });

            box.addEventListener('keydown', function (e) {
                if (e.key === 'Backspace' && !e.target.value && i > 0) {
                    boxes[i - 1].focus();
                    boxes[i - 1].value = '';
                    updateState();
                }
            });

            box.addEventListener('paste', function (e) {
                e.preventDefault();
                var text = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '');
                for (var j = 0; j < text.length && j < boxes.length; j++) {
                    boxes[j].value = text[j];
                }
                boxes[Math.min(text.length, boxes.length - 1)].focus();
                updateState();
            });
        });

        form.addEventListener('submit', function () {
            hidden.value = collectValue();
        });

        // Timer
        var seconds = 15 * 60;
        var el = document.getElementById('countdown');
        if (el) {
            setInterval(function () {
                seconds--;
                if (seconds <= 0) {
                    el.textContent = '0:00';
                    return;
                }
                var m = Math.floor(seconds / 60);
                var s = seconds % 60;
                el.textContent = m + ':' + (s < 10 ? '0' : '') + s;
            }, 1000);
        }
    })();
</script>

</body>
</html>