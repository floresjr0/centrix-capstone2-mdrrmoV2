<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/mail.php';

$pdo = db();

if (current_user()) {
    redirect_by_role();
}

$email = trim($_GET['email'] ?? $_POST['email'] ?? '');
$errors = [];
$notice = '';

$pendingUserId      = $_SESSION['device_verify_user_id'] ?? null;
$pendingFingerprint = $_SESSION['device_verify_fingerprint'] ?? '';
$emailSent          = true;

// Send OTP on first arrival after unrecognized-device login redirect.
if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    && $pendingUserId
    && filter_var($email, FILTER_VALIDATE_EMAIL)
    && !empty($_SESSION['device_verify_pending'])
) {
    unset($_SESSION['device_verify_pending']);

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND id = ? AND role = 'admin'");
    $stmt->execute([$email, $pendingUserId]);
    $user = $stmt->fetch();

    if ($user) {
        $result    = issue_device_otp($pdo, $user);
        $emailSent = $result['sent'];
        if (!$emailSent) {
            error_log("[MDRRMO] Device OTP email failed for {$user['email']}");
        }
    }
}

if (!$pendingUserId && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($email !== '') {
        $errors[] = 'Device verification session expired. Please log in again.';
    }
}

function issue_device_otp(PDO $pdo, array $user): array
{
    $otp       = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $otpHash   = password_hash($otp, PASSWORD_DEFAULT);
    $expiresAt = date('Y-m-d H:i:s', time() + 15 * 60);

    $upd = $pdo->prepare("UPDATE users SET
        otp_code_hash  = ?,
        otp_expires_at = ?,
        otp_purpose    = 'device_verify'
        WHERE id = ?");
    $upd->execute([$otpHash, $expiresAt, $user['id']]);

    $displayName = trim($user['full_name'] ?? '') ?: $user['email'];
    $sent        = send_otp_email($user['email'], $displayName, $otp);

    return ['sent' => $sent, 'otp' => $otp];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend'])) {
    if (!$pendingUserId || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Device verification session expired. Please log in again.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND id = ? AND role = 'admin'");
        $stmt->execute([$email, $pendingUserId]);
        $user = $stmt->fetch();

        if (!$user) {
            $errors[] = 'Account not found.';
        } else {
            $result = issue_device_otp($pdo, $user);
            if ($result['sent']) {
                $notice = 'A new verification code was sent to your email.';
            } else {
                error_log("[MDRRMO] Device OTP resend failed for {$user['email']}");
                $errors[] = 'Could not send a new code right now. Please try again in a moment.';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['resend'])) {
    $otp = trim($_POST['otp'] ?? '');

    if (!$pendingUserId) {
        $errors[] = 'Device verification session expired. Please log in again.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email.';
    } elseif ($otp === '') {
        $errors[] = 'Please enter the code sent to your email.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND id = ? AND role = 'admin'");
        $stmt->execute([$email, $pendingUserId]);
        $user = $stmt->fetch();

        if (!$user) {
            $errors[] = 'Account not found.';
        } elseif (($user['otp_purpose'] ?? '') !== 'device_verify') {
            $errors[] = 'No active device verification code. Please log in again.';
        } elseif (empty($user['otp_code_hash']) || empty($user['otp_expires_at'])) {
            $errors[] = 'No active verification code. Please log in again.';
        } elseif (strtotime($user['otp_expires_at']) < time()) {
            $errors[] = 'Verification code has expired. Please log in again to receive a new code.';
        } elseif (!password_verify($otp, $user['otp_code_hash'])) {
            $errors[] = 'Incorrect verification code.';
        } else {
            $newToken = bin2hex(random_bytes(32));

            $registerFingerprint = trim($_POST['device_fingerprint'] ?? '');
            if ($registerFingerprint === '') {
                $registerFingerprint = $pendingFingerprint;
            }

            $upd = $pdo->prepare("UPDATE users SET
                device_token         = ?,
                device_fingerprint   = ?,
                device_registered_at = NOW(),
                is_device_trusted    = 1,
                otp_code_hash        = NULL,
                otp_expires_at       = NULL,
                otp_purpose          = NULL
                WHERE id = ?");
            $upd->execute([$newToken, $registerFingerprint, $user['id']]);

            setcookie('mdrrmo_device_token', $newToken, [
                'expires'  => time() + (30 * 24 * 60 * 60),
                'path'     => '/',
                'secure'   => false,
                'httponly' => true,
                'samesite' => 'Strict'
            ]);

            unset($_SESSION['device_verify_user_id'], $_SESSION['device_verify_fingerprint']);

            $_SESSION['user_id'] = $user['id'];
            redirect_by_role();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Verify Device — MDRRMO San Ildefonso</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../asset/css/verify_device.css">

<style>

</style>
</head>
<body>

<div class="bg-orb bg-orb-1"></div>
<div class="bg-orb bg-orb-2"></div>

<div class="auth-card">

    <div class="auth-seal">
        <img src="../img/mdrrmo.png" alt="MDRRMO" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
        <svg viewBox="0 0 24 24" style="display:none" xmlns="http://www.w3.org/2000/svg">
            <rect x="4" y="10" width="16" height="11" rx="2"/>
            <path d="M7 10V7a5 5 0 1 1 10 0v3" fill="none" stroke="#fff" stroke-width="1.6"/>
        </svg>
    </div>

    <div class="auth-title">Verify Device</div>
    <div class="auth-sub">
        This device isn't recognized. Enter the 6-digit code sent to<br>
        <?php if ($email): ?>
            <strong><?php echo htmlspecialchars($email); ?></strong>
        <?php else: ?>
            your registered email.
        <?php endif; ?>
    </div>

    <?php if ($errors): ?>
        <div class="auth-errors">
            <?php foreach ($errors as $err): ?>
                ⚠ <?php echo htmlspecialchars($err); ?><br>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($notice): ?>
        <div class="auth-success">✓ <?php echo htmlspecialchars($notice); ?></div>
    <?php endif; ?>

    <?php if (!$emailSent && $pendingUserId && $email): ?>
        <div class="auth-notice">ℹ We couldn't confirm email delivery. Check your spam folder, or use Resend code below.</div>
    <?php endif; ?>

    <?php if ($pendingUserId && $email): ?>
    <form method="post" id="otp-form">
        <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
        <input type="hidden" name="otp" id="otp-hidden">
        <input type="hidden" name="device_fingerprint" id="device-fingerprint" value="">

        <div class="otp-group" id="otp-boxes">
            <?php for ($i = 0; $i < 6; $i++): ?>
            <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="one-time-code">
            <?php endfor; ?>
        </div>

        <div class="auth-timer">Code expires in <span class="countdown-timer">15:00</span></div>

        <button type="submit" class="auth-btn" id="submit-btn" disabled>Verify &amp; Continue</button>
    </form>

    <div class="auth-links">
        <form method="post" style="margin:0;">
            <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
            <button type="submit" name="resend" value="1">Resend code</button>
        </form>
        <a href="../index.php" class="back-login-btn">← Back to login</a>
    </div>
    <?php else: ?>
    <div class="auth-links" style="justify-content:center !important; margin-top: 8px !important;">
        <a href="../index.php" class="back-login-btn">← Back to login</a>
    </div>
    <?php endif; ?>

    <div class="auth-footer">&copy; <?php echo date('Y'); ?> MDRRMOxBASC_ICS. All rights reserved.</div>
</div>

<script>
(function () {
    function initOtpForm(groupId, hiddenId, btnId, formId) {
        var group  = document.getElementById(groupId);
        var hidden = document.getElementById(hiddenId);
        var btn    = document.getElementById(btnId);
        var form   = document.getElementById(formId);
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

        boxes[0].focus();

        form.addEventListener('submit', function () {
            hidden.value = collectValue();
        });
    }

    initOtpForm('otp-boxes', 'otp-hidden', 'submit-btn', 'otp-form');

    var seconds = 15 * 60;
    var timerEl = document.querySelector('.countdown-timer');
    if (timerEl) {
        setInterval(function () {
            seconds--;
            var text;
            if (seconds <= 0) {
                text = '0:00';
            } else {
                var m = Math.floor(seconds / 60);
                var s = seconds % 60;
                text = m + ':' + (s < 10 ? '0' : '') + s;
            }
            timerEl.textContent = text;
        }, 1000);
    }

    async function fillDeviceFingerprint() {
        try {
            var components = [
                navigator.userAgent || '',
                navigator.language || '',
                (screen.width || 0) + 'x' + (screen.height || 0),
                (screen.colorDepth || 0).toString(),
                new Date().getTimezoneOffset().toString(),
                (navigator.hardwareConcurrency || 0).toString(),
                navigator.platform || ''
            ];
            try {
                var canvas = document.createElement('canvas');
                var ctx = canvas.getContext('2d');
                ctx.textBaseline = 'top';
                ctx.font = '14px Arial';
                ctx.fillStyle = '#f35a00';
                ctx.fillRect(120, 1, 60, 20);
                ctx.fillStyle = '#002a5e';
                ctx.fillText('MDRRMO-device-fp', 2, 2);
                ctx.fillStyle = 'rgba(180,80,20,0.7)';
                ctx.fillText('SanIldefonso2026', 4, 17);
                components.push(canvas.toDataURL());
            } catch (e) {
                components.push('canvas-unavailable');
            }
            var raw = components.join('|||');
            var encoded = new TextEncoder().encode(raw);
            var hashBuffer = await crypto.subtle.digest('SHA-256', encoded);
            var hashArray = Array.from(new Uint8Array(hashBuffer));
            var fp = hashArray.map(function(b){ return b.toString(16).padStart(2,'0'); }).join('');
            document.querySelectorAll('input[name="device_fingerprint"]').forEach(function(el) {
                el.value = fp;
            });
        } catch (e) {}
    }

    fillDeviceFingerprint();
})();
</script>

</body>
</html>