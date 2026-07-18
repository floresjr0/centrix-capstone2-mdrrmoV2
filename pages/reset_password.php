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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['resend'])) {
    $otp      = trim($_POST['otp'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (!$hasResetSession) {
        $errors[] = 'Password reset session expired. Please request a new code.';
    } elseif ($otp === '') {
        $errors[] = 'Please enter the reset code from your email.';
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
        } elseif (($user['otp_purpose'] ?? '') !== 'password_reset') {
            $errors[] = 'No active reset code. Please request a new one.';
        } elseif (empty($user['otp_code_hash']) || empty($user['otp_expires_at'])) {
            $errors[] = 'No active reset code. Please request a new one.';
        } elseif (strtotime($user['otp_expires_at']) < time()) {
            $errors[] = 'Reset code has expired. Please request a new one.';
        } elseif (!password_verify($otp, $user['otp_code_hash'])) {
            $errors[] = 'Incorrect reset code.';
        } else {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            $upd = $pdo->prepare("UPDATE users SET
                password_hash   = ?,
                otp_code_hash   = NULL,
                otp_expires_at  = NULL,
                otp_purpose     = NULL
                WHERE id = ?");
            $upd->execute([$passwordHash, $user['id']]);

            unset($_SESSION['password_reset_user_id']);

            header('Location: ' . app_url('index.php?reset=1'));
            exit;
        }
    }
}

if (!$hasResetSession && $email !== '' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $notice = 'If an account exists for this email, check your inbox for the reset code.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reset Password — MDRRMO San Ildefonso</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../asset/css/reset_password.css">
    <style>

    </style>
</head>
<body>
<div class="auth-card">
    <div class="auth-title">Reset Password</div>
    <div class="auth-sub">
        Enter the 6-digit code sent to<br>
        <?php if ($email): ?>
            <strong><?php echo htmlspecialchars($email); ?></strong>
        <?php else: ?>
            your email address.
        <?php endif; ?>
        <br>Then choose a new password.
    </div>

    <?php if ($errors): ?>
        <div class="auth-errors">
            <?php foreach ($errors as $err): ?>
                ⚠ <?php echo htmlspecialchars($err); ?><br>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($notice): ?>
        <div class="auth-notice">ℹ <?php echo htmlspecialchars($notice); ?></div>
    <?php endif; ?>

    <?php if ($hasResetSession): ?>
    <form method="post" id="reset-form">
        <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
        <input type="hidden" name="otp" id="otp-hidden">

        <div class="otp-group" id="otp-boxes">
            <?php for ($i = 0; $i < 6; $i++): ?>
            <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="one-time-code">
            <?php endfor; ?>
        </div>

        <div class="auth-timer">Code expires in <span id="countdown">15:00</span></div>

        <div class="auth-field">
            <label for="password">New Password</label>
            <input type="password" id="password" name="password" required minlength="8"
                   placeholder="At least 8 characters">
        </div>

        <div class="auth-field">
            <label for="confirm_password">Confirm Password</label>
            <input type="password" id="confirm_password" name="confirm_password" required minlength="8"
                   placeholder="Repeat new password">
        </div>

        <button type="submit" class="auth-btn" id="submit-btn" disabled>Reset Password</button>
    </form>

    <div class="auth-links">
        <form method="post" style="margin:0;">
            <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
            <button type="submit" name="resend" value="1">Resend code</button>
        </form>
        <a href="forgot_password.php">← Back to forgot password</a>
    </div>
    <?php else: ?>
    <div class="auth-links" style="justify-content:center;">
       
    </div>
    <?php endif; ?>

    <div class="auth-links" style="justify-content:center;margin-top:12px;">
        <a href="../index.php">Back to login</a>
    </div>
</div>

<script>
(function () {
    var group  = document.getElementById('otp-boxes');
    var hidden = document.getElementById('otp-hidden');
    var btn    = document.getElementById('submit-btn');
    var form   = document.getElementById('reset-form');
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