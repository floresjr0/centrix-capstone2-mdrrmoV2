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
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        @media (min-width: 900px) {
            #mobile-card { display: none !important; }
            #desktop-page { display: flex !important; }
        }

        #desktop-page {
            display: none;
            position: fixed;
            inset: 0;
            width: 100%;
            height: 100%;
            font-family: 'Poppins', sans-serif;
            background:
                radial-gradient(ellipse at 30% 60%, rgba(140,25,10,0.55) 0%, transparent 55%),
                radial-gradient(ellipse at 75% 30%, rgba(80,10,5,0.6) 0%, transparent 55%),
                #0d0806;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            z-index: 100;
        }

        .dt-card {
            position: relative;
            z-index: 1;
            width: calc(100% - 80px);
            max-width: 900px;
            margin: 0 auto;
            background: rgba(18, 12, 10, 0.75);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 24px;
            backdrop-filter: blur(24px);
            box-shadow: 0 32px 80px rgba(0,0,0,0.6);
            display: flex;
            overflow: hidden;
            height: calc(100vh - 80px);
            max-height: 640px;
        }

        .dt-card-left {
            width: 44%;
            flex-shrink: 0;
            background: linear-gradient(160deg, #1f0b06 0%, #3a1008 40%, #8b1a0a 80%, #c0391e 100%);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 52px 40px;
            text-align: center;
        }

        .dt-otp-icon {
            width: 96px;
            height: 96px;
            margin-bottom: 22px;
            background: rgba(0,0,0,0.25);
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .dt-otp-icon svg { width: 42px; height: 42px; }

        .dt-agency {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 48px;
            letter-spacing: 7px;
            color: #fff;
            line-height: 1;
            margin-bottom: 6px;
        }

        .dt-tagline {
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 3px;
            color: rgba(255,255,255,0.5);
            text-transform: uppercase;
            margin-bottom: 36px;
        }

        .dt-info-pills {
            display: flex;
            flex-direction: column;
            gap: 12px;
            width: 100%;
        }

        .dt-pill {
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(0,0,0,0.25);
            border: 1px solid rgba(255,255,255,0.09);
            border-radius: 12px;
            padding: 12px 16px;
            text-align: left;
        }

        .dt-pill-icon {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            background: rgba(192,57,30,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 16px;
        }

        .dt-pill-text strong {
            display: block;
            font-size: 12.5px;
            font-weight: 700;
            color: #fff;
        }

        .dt-pill-text span {
            font-size: 11px;
            color: rgba(255,255,255,0.5);
        }

        .dt-bottom-badge {
            margin-top: 32px;
            font-size: 10px;
            color: rgba(255,255,255,0.25);
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }

        .dt-card-right {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 52px 48px;
            background: #ffffff;
        }

        .dt-welcome {
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: #c0391e;
            margin-bottom: 8px;
        }

        .dt-form-title {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 42px;
            letter-spacing: 3px;
            color: #1a0a06;
            line-height: 1.05;
            margin-bottom: 6px;
        }

        .dt-form-subtitle {
            font-size: 13px;
            color: #888;
            line-height: 1.6;
            margin-bottom: 32px;
        }

        .dt-form-subtitle strong { color: #444; font-weight: 600; }

        .dt-errors {
            background: rgba(192,57,30,0.08);
            border: 1px solid rgba(192,57,30,0.35);
            border-radius: 10px;
            padding: 14px 18px;
            margin-bottom: 24px;
        }

        .dt-errors ul { list-style: none; margin: 0; padding: 0; }
        .dt-errors li { font-size: 13px; color: #c0391e; font-weight: 500; }
        .dt-errors li::before { content: '⚠ '; }

        .dt-otp-group {
            display: flex;
            gap: 10px;
            margin-bottom: 28px;
            justify-content: center;
        }

        .dt-otp-group input[type="text"] {
            width: 52px;
            height: 58px;
            text-align: center;
            font-size: 22px;
            font-weight: 700;
            font-family: 'Poppins', sans-serif;
            color: #1a0a06;
            background: #f7f7f7;
            border: 1.5px solid #e8e8e8;
            border-radius: 12px;
            outline: none;
            caret-color: #c0391e;
        }

        .dt-otp-group input[type="text"]:focus {
            border-color: #c0391e;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(192,57,30,0.10);
        }

        .dt-timer {
            text-align: center;
            font-size: 12.5px;
            color: #aaa;
            margin-bottom: 24px;
        }

        .dt-timer span { font-weight: 600; color: #c0391e; }

        .dt-btn-signin {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #c0391e 0%, #a02d15 100%);
            color: #fff;
            font-family: 'Poppins', sans-serif;
            font-size: 13.5px;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            box-shadow: 0 4px 20px rgba(192,57,30,0.35);
        }

        .dt-btn-signin:disabled { opacity: 0.5; cursor: not-allowed; }

        .dt-notice {
            background: rgba(234, 179, 8, 0.12);
            border: 1px solid rgba(234, 179, 8, 0.35);
            border-radius: 10px;
            padding: 14px 18px;
            margin-bottom: 24px;
            font-size: 13px;
            color: #a16207;
            font-weight: 500;
        }

        .dt-notice::before { content: 'ℹ  '; }

        .dt-success-notice {
            background: rgba(21, 128, 61, 0.08);
            border: 1px solid rgba(21, 128, 61, 0.35);
            border-radius: 10px;
            padding: 14px 18px;
            margin-bottom: 24px;
            font-size: 13px;
            color: #15803d;
            font-weight: 500;
        }

        .dt-success-notice::before { content: '✓  '; }

        .dt-links-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 22px;
            font-size: 12.5px;
            gap: 12px;
        }

        .dt-links-row a {
            color: #c0391e;
            text-decoration: none;
            font-weight: 600;
        }

        .dt-links-row button {
            color: #c0391e;
            background: none;
            border: none;
            cursor: pointer;
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
            font-size: 12.5px;
            padding: 0;
        }

        @media (max-width: 899px) {
            #desktop-page { display: none !important; }

            body {
                font-family: 'Poppins', sans-serif;
                margin: 0;
                min-height: 100vh;
                background:
                    radial-gradient(ellipse at 30% 60%, rgba(140,25,10,0.55) 0%, transparent 55%),
                    radial-gradient(ellipse at 75% 30%, rgba(80,10,5,0.6) 0%, transparent 55%),
                    #0d0806;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 24px;
                box-sizing: border-box;
            }

            .mobile-otp-card {
                width: 100%;
                max-width: 420px;
                background: #fff;
                border-radius: 20px;
                padding: 36px 28px 32px;
                box-shadow: 0 24px 60px rgba(0,0,0,0.45);
            }

            .mob-icon {
                width: 64px;
                height: 64px;
                background: rgba(192,57,30,0.08);
                border: 1.5px solid rgba(192,57,30,0.2);
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 20px;
            }

            .mob-icon svg { width: 28px; height: 28px; }

            .mob-title {
                font-family: 'Bebas Neue', sans-serif;
                font-size: 32px;
                letter-spacing: 3px;
                color: #1a0a06;
                text-align: center;
                margin-bottom: 6px;
            }

            .mob-sub {
                font-size: 13px;
                color: #888;
                text-align: center;
                margin-bottom: 28px;
                line-height: 1.55;
            }

            .mob-sub strong { color: #444; font-weight: 600; }

            .mob-otp-group {
                display: flex;
                gap: 8px;
                justify-content: center;
                margin-bottom: 20px;
            }

            .mob-otp-group input[type="text"] {
                width: 46px;
                height: 54px;
                text-align: center;
                font-size: 20px;
                font-weight: 700;
                font-family: 'Poppins', sans-serif;
                color: #1a0a06;
                background: #f7f7f7;
                border: 1.5px solid #e8e8e8;
                border-radius: 10px;
                outline: none;
            }

            .mob-errors {
                background: rgba(192,57,30,0.08);
                border: 1px solid rgba(192,57,30,0.35);
                border-radius: 10px;
                padding: 12px 16px;
                margin-bottom: 18px;
                font-size: 13px;
                color: #c0391e;
                font-weight: 500;
            }

            .mob-btn {
                width: 100%;
                padding: 14px;
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
                margin-bottom: 20px;
            }

            .mob-links {
                display: flex;
                justify-content: space-between;
                align-items: center;
                font-size: 12.5px;
                gap: 12px;
            }

            .mob-links button {
                color: #c0391e;
                background: none;
                border: none;
                cursor: pointer;
                font-weight: 600;
                font-family: 'Poppins', sans-serif;
                font-size: 12.5px;
                padding: 0;
            }
            .mob-timer { text-align: center; font-size: 12px; color: #aaa; margin-bottom: 16px; }
            .mob-timer span { color: #c0391e; font-weight: 600; }
        }
    </style>
</head>
<body>

<div id="desktop-page">
    <div class="dt-card">
        <div class="dt-card-left">
            <div class="dt-otp-icon">
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect x="5" y="11" width="14" height="10" rx="2" stroke="rgba(255,255,255,0.85)" stroke-width="1.5"/>
                    <path d="M8 11V7a4 4 0 1 1 8 0v4" stroke="rgba(255,255,255,0.85)" stroke-width="1.5" stroke-linecap="round"/>
                </svg>
            </div>
            <div class="dt-agency">MDRRMO</div>
            <div class="dt-tagline">Device Verification</div>
            <div class="dt-info-pills">
                <div class="dt-pill">
                    <div class="dt-pill-icon">💻</div>
                    <div class="dt-pill-text">
                        <strong>Unrecognized device</strong>
                        <span>Confirm it's you to re-register this computer</span>
                    </div>
                </div>
                <div class="dt-pill">
                    <div class="dt-pill-icon">📧</div>
                    <div class="dt-pill-text">
                        <strong>Check your email</strong>
                        <span>A 6-digit code was sent to your inbox</span>
                    </div>
                </div>
                <div class="dt-pill">
                    <div class="dt-pill-icon">⏱</div>
                    <div class="dt-pill-text">
                        <strong>Code expires in 15 mins</strong>
                        <span>Log in again if it expires</span>
                    </div>
                </div>
            </div>
            <div class="dt-bottom-badge">MDRRMO · Admin Access</div>
        </div>

        <div class="dt-card-right">
            <div class="dt-welcome">Security Check</div>
            <div class="dt-form-title">Verify Device</div>
            <div class="dt-form-subtitle">
                This device isn't recognized. Enter the 6-digit code sent to<br>
                <?php if ($email): ?>
                    <strong><?php echo htmlspecialchars($email); ?></strong>
                <?php else: ?>
                    your registered email.
                <?php endif; ?>
            </div>

            <?php if ($errors): ?>
                <div class="dt-errors">
                    <ul>
                        <?php foreach ($errors as $err): ?>
                            <li><?php echo htmlspecialchars($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($notice): ?>
                <div class="dt-success-notice"><?php echo htmlspecialchars($notice); ?></div>
            <?php endif; ?>

            <?php if (!$emailSent && $pendingUserId && $email): ?>
                <div class="dt-notice">
                    We couldn't confirm email delivery. Check your spam folder, or use Resend code below.
                </div>
            <?php endif; ?>

            <?php if ($pendingUserId && $email): ?>
            <form method="post" id="otp-form">
                <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
                <input type="hidden" name="otp" id="otp-hidden">
                <input type="hidden" name="device_fingerprint" id="dt-device-fingerprint" value="">

                <div class="dt-otp-group" id="dt-otp-boxes">
                    <?php for ($i = 0; $i < 6; $i++): ?>
                    <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="one-time-code">
                    <?php endfor; ?>
                </div>

                <div class="dt-timer">Code expires in <span id="dt-countdown">15:00</span></div>

                <button type="submit" class="dt-btn-signin" id="dt-submit-btn" disabled>Verify &amp; Continue</button>
            </form>
            <?php endif; ?>

            <div class="dt-links-row">
                <?php if ($pendingUserId && $email): ?>
                <form method="post" style="margin:0;">
                    <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
                    <button type="submit" name="resend" value="1">Resend code</button>
                </form>
                <?php else: ?><span></span><?php endif; ?>
                <a href="../index.php">← Back to login</a>
            </div>
        </div>
    </div>
</div>

<div class="mobile-otp-card" id="mobile-card">
    <div class="mob-icon">
        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect x="5" y="11" width="14" height="10" rx="2" stroke="#c0391e" stroke-width="1.5"/>
            <path d="M8 11V7a4 4 0 1 1 8 0v4" stroke="#c0391e" stroke-width="1.5" stroke-linecap="round"/>
        </svg>
    </div>
    <div class="mob-title">Verify Device</div>
    <div class="mob-sub">
        Enter the 6-digit code sent to<br>
        <?php if ($email): ?>
            <strong><?php echo htmlspecialchars($email); ?></strong>
        <?php else: ?>
            your registered email.
        <?php endif; ?>
    </div>

    <?php if ($errors): ?>
        <div class="mob-errors">
            <?php foreach ($errors as $err): ?>
                ⚠ <?php echo htmlspecialchars($err); ?><br>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($notice): ?>
        <div class="mob-errors" style="color:#15803d;border-color:rgba(21,128,61,0.35);background:rgba(21,128,61,0.08);">
            ✓ <?php echo htmlspecialchars($notice); ?>
        </div>
    <?php endif; ?>

    <?php if (!$emailSent && $pendingUserId && $email): ?>
        <div class="mob-errors" style="color:#a16207;border-color:rgba(234,179,8,0.35);background:rgba(234,179,8,0.12);">
            ℹ Check your spam folder or tap Resend code below.
        </div>
    <?php endif; ?>

    <?php if ($pendingUserId && $email): ?>
    <form method="post" id="mob-otp-form">
        <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
        <input type="hidden" name="otp" id="mob-otp-hidden">
        <input type="hidden" name="device_fingerprint" id="mob-device-fingerprint" value="">

        <div class="mob-otp-group" id="mob-otp-boxes">
            <?php for ($i = 0; $i < 6; $i++): ?>
            <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="one-time-code">
            <?php endfor; ?>
        </div>

        <div class="mob-timer">Code expires in <span id="mob-countdown">15:00</span></div>

        <button type="submit" class="mob-btn" id="mob-submit-btn" disabled>Verify &amp; Continue</button>
    </form>
    <?php endif; ?>

    <div class="mob-links">
        <?php if ($pendingUserId && $email): ?>
        <form method="post" style="margin:0;">
            <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
            <button type="submit" name="resend" value="1">Resend code</button>
        </form>
        <?php else: ?><span></span><?php endif; ?>
        <a href="../index.php">← Back to login</a>
    </div>
</div>

<script>
(function () {
    function initOtpBoxes(groupId, hiddenId, submitBtnId, formId) {
        var group = document.getElementById(groupId);
        if (!group) return;
        var boxes  = Array.from(group.querySelectorAll('input[type="text"]'));
        var hidden = document.getElementById(hiddenId);
        var btn    = document.getElementById(submitBtnId);
        var form   = document.getElementById(formId);
        if (!boxes.length || !hidden || !btn) return;

        function collectValue() {
            return boxes.map(function (b) { return b.value; }).join('');
        }

        function updateState() {
            var val = collectValue();
            hidden.value = val;
            btn.disabled = val.length < 6;
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
        if (form) {
            form.addEventListener('submit', function () {
                hidden.value = collectValue();
            });
        }
    }

    initOtpBoxes('dt-otp-boxes',  'otp-hidden',     'dt-submit-btn', 'otp-form');
    initOtpBoxes('mob-otp-boxes', 'mob-otp-hidden',  'mob-submit-btn', 'mob-otp-form');

    function startCountdown(elementId) {
        var el = document.getElementById(elementId);
        if (!el) return;
        var seconds = 15 * 60;
        var interval = setInterval(function () {
            seconds--;
            if (seconds <= 0) {
                clearInterval(interval);
                el.textContent = '0:00';
                return;
            }
            var m = Math.floor(seconds / 60);
            var s = seconds % 60;
            el.textContent = m + ':' + (s < 10 ? '0' : '') + s;
        }, 1000);
    }

    startCountdown('dt-countdown');
    startCountdown('mob-countdown');

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
