We've replaced the "Back to login" link with a clean close (×) icon positioned at the upper-right corner of the card. The styling matches the elegant white card aesthetic, and it links back to the login page.
```html
<?php
ob_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/mail.php';

$pdo = db();

if (current_user()) {
    redirect_by_role();
}

$errors  = [];
$message = '';
$email   = trim($_POST['email'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email is required.';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1 AND is_email_verified = 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
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
            send_otp_email(
                $user['email'],
                $displayName,
                $otp,
                'Your MDRRMO password reset code',
                'Your password reset code is'
            );

            $_SESSION['password_reset_user_id'] = $user['id'];
        }

        header('Location: ' . app_url('pages/reset_password.php?email=' . urlencode($email)));
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Forgot Password — MDRRMO San Ildefonso</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Poppins:wght@300;400;500;600;700;800&family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        /* ================================================
           RESET & BASE
           ================================================ */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html,
        body {
            height: 100%;
            font-family: 'Poppins', sans-serif;
            background: #0d0a08;
            color: #1a0a06;
            overflow: hidden;
        }

        /* ================================================
           BACKGROUND LAYERS (same as login)
           ================================================ */
        .bg-layer {
            position: fixed;
            inset: 0;
            z-index: 0;
            pointer-events: none;
        }

        .bg-base {
            background: radial-gradient(ellipse at 30% 20%, #2a1410 0%, #0d0a08 65%, #060403 100%);
            width: 100%;
            height: 100%;
        }

        .bg-grain {
            position: absolute;
            inset: 0;
            opacity: 0.06;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)'/%3E%3C/svg%3E");
            background-size: 256px 256px;
        }

        .bg-vignette {
            position: absolute;
            inset: 0;
            background: radial-gradient(ellipse at center, transparent 50%, rgba(0, 0, 0, 0.6) 100%);
        }

        .orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.25;
            pointer-events: none;
        }
        .orb-1 {
            width: 500px;
            height: 500px;
            top: -120px;
            right: -120px;
            background: radial-gradient(circle, rgba(200, 80, 20, 0.35), transparent 70%);
            animation: orbFloat 18s ease-in-out infinite alternate;
        }
        .orb-2 {
            width: 400px;
            height: 400px;
            bottom: -100px;
            left: -100px;
            background: radial-gradient(circle, rgba(212, 150, 10, 0.20), transparent 70%);
            animation: orbFloat 22s ease-in-out infinite alternate-reverse;
        }
        .orb-3 {
            width: 300px;
            height: 300px;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: radial-gradient(circle, rgba(180, 50, 10, 0.12), transparent 70%);
            animation: orbFloat 14s ease-in-out infinite alternate;
        }

        @keyframes orbFloat {
            0% {
                transform: translate(0, 0) scale(1);
            }
            100% {
                transform: translate(40px, -30px) scale(1.1);
            }
        }

        .particle {
            position: absolute;
            border-radius: 50%;
            pointer-events: none;
            animation: particleDrift linear infinite;
        }

        @keyframes particleDrift {
            0% {
                transform: translateY(0) translateX(0) scale(1);
                opacity: 0;
            }
            10% {
                opacity: 1;
            }
            90% {
                opacity: 1;
            }
            100% {
                transform: translateY(-120vh) translateX(40px) scale(0.4);
                opacity: 0;
            }
        }

        /* ================================================
           CARD CONTAINER
           ================================================ */
        #auth-page {
            position: fixed;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            z-index: 10;
        }

        /* ================================================
           MAIN CARD — WHITE BACKGROUND
           ================================================ */
        .auth-card {
            position: relative;
            width: 100%;
            max-width: 480px;
            background: #ffffff;
            border-radius: 28px;
            padding: 40px 36px 34px;
            box-shadow:
                0 40px 100px rgba(0, 0, 0, 0.6),
                0 0 0 1px rgba(255, 255, 255, 0.08) inset;
            border: 1px solid rgba(255, 255, 255, 0.12);
            transition: transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.4s ease;
            animation: cardEnter 0.7s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
            opacity: 0;
            transform: translateY(30px) scale(0.97);
        }

        @keyframes cardEnter {
            0% {
                opacity: 0;
                transform: translateY(30px) scale(0.97);
            }
            100% {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .auth-card:hover {
            box-shadow:
                0 50px 120px rgba(0, 0, 0, 0.7),
                0 0 0 1px rgba(255, 255, 255, 0.12) inset;
        }

        /* ================================================
           CLOSE BUTTON (X) — top right
           ================================================ */
        .auth-close {
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

        .auth-close:hover {
            color: #c0391e;
            background: rgba(192, 57, 30, 0.06);
            transform: rotate(90deg);
        }

        .auth-close:focus-visible {
            outline: 2px solid #c0391e;
            outline-offset: 2px;
        }

        /* ================================================
           HEADER — Dark text on white
           ================================================ */
        .auth-header {
            text-align: center;
            margin-bottom: 28px;
        }

        .auth-logo-wrap {
            position: relative;
            display: inline-block;
            margin-bottom: 14px;
        }

        .auth-logo-ring {
            position: absolute;
            inset: -10px;
            border-radius: 50%;
            border: 1.5px solid rgba(192, 57, 30, 0.15);
            animation: ringPulse 4s ease-in-out infinite;
        }

        @keyframes ringPulse {
            0%,
            100% {
                transform: scale(1);
                opacity: 0.4;
            }
            50% {
                transform: scale(1.08);
                opacity: 0.9;
            }
        }

        .auth-logo-ring-2 {
            position: absolute;
            inset: -18px;
            border-radius: 50%;
            border: 1px dashed rgba(192, 57, 30, 0.08);
            animation: ringSpin 20s linear infinite;
        }

        @keyframes ringSpin {
            0% {
                transform: rotate(0deg);
            }
            100% {
                transform: rotate(360deg);
            }
        }

        .auth-logo {
            position: relative;
            width: 76px;
            height: 76px;
            display: block;
            margin: 0 auto;
            border-radius: 50%;
            object-fit: cover;
            background: #f8f5f2;
            border: 2px solid rgba(192, 57, 30, 0.15);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08);
            padding: 4px;
            transition: transform 0.3s ease;
        }

        .auth-logo:hover {
            transform: scale(1.04);
        }

        .auth-title {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 34px;
            letter-spacing: 4px;
            color: #1a0a06;
            margin-bottom: 4px;
        }

        .auth-title span {
            color: #c0391e;
        }

        .auth-sub {
            font-size: 13px;
            color: #6b5f5a;
            line-height: 1.7;
            font-weight: 400;
            max-width: 320px;
            margin: 0 auto;
        }

        .auth-sub strong {
            color: #1a0a06;
            font-weight: 600;
        }

        /* ================================================
           DIVIDER
           ================================================ */
        .auth-divider {
            display: flex;
            align-items: center;
            gap: 14px;
            margin: 20px 0 18px;
        }

        .auth-divider::before,
        .auth-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: linear-gradient(to right, transparent, rgba(0, 0, 0, 0.08), transparent);
        }

        .auth-divider span {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: #a09088;
            font-weight: 600;
        }

        /* ================================================
           ERROR MESSAGES (adapted for white bg)
           ================================================ */
        .auth-errors {
            background: rgba(192, 57, 30, 0.06);
            border: 1px solid rgba(192, 57, 30, 0.20);
            border-radius: 12px;
            padding: 12px 16px;
            margin-bottom: 20px;
            font-size: 13px;
            color: #c0391e;
        }

        .auth-errors ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .auth-errors ul li {
            padding: 2px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .auth-errors ul li::before {
            content: '⚠';
            font-size: 14px;
            opacity: 0.7;
        }

        /* ================================================
           FORM FIELDS — light inputs on white
           ================================================ */
        .auth-form {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .field-label {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            color: #6b5f5a;
            transition: color 0.3s ease;
        }

        .field:focus-within .field-label {
            color: #c0391e;
        }

        .field input {
            width: 100%;
            padding: 14px 16px;
            background: #f5f2ef;
            border: 1.5px solid #e2dbd6;
            border-radius: 12px;
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            color: #1a0a06;
            outline: none;
            transition: all 0.3s ease;
        }

        .field input::placeholder {
            color: #b0a69e;
            font-weight: 300;
        }

        .field input:focus {
            border-color: #c0391e;
            background: #ffffff;
            box-shadow: 0 0 0 4px rgba(192, 57, 30, 0.08);
        }

        .field input:hover {
            border-color: #c8bdb6;
        }

        /* ================================================
           SUBMIT BUTTON (same as login)
           ================================================ */
        .auth-btn {
            position: relative;
            width: 100%;
            padding: 16px;
            margin-top: 8px;
            background: linear-gradient(135deg, #c0391e 0%, #a02d15 100%);
            color: #fff;
            font-family: 'Poppins', sans-serif;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 2.5px;
            text-transform: uppercase;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            box-shadow: 0 8px 32px rgba(192, 57, 30, 0.30);
            transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
            overflow: hidden;
        }

        .auth-btn::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.12), transparent 60%);
            pointer-events: none;
        }

        .auth-btn:hover {
            transform: translateY(-2px) scale(1.01);
            box-shadow: 0 12px 44px rgba(192, 57, 30, 0.40);
            background: linear-gradient(135deg, #d44528 0%, #b0351a 100%);
        }

        .auth-btn:active {
            transform: translateY(0) scale(0.98);
            box-shadow: 0 6px 20px rgba(192, 57, 30, 0.30);
        }

        .auth-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }

        .auth-btn .btn-spinner {
            display: none;
            width: 20px;
            height: 20px;
            margin: 0 auto;
            border: 2.5px solid rgba(255, 255, 255, 0.15);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.7s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .auth-btn.loading .btn-text {
            display: none;
        }
        .auth-btn.loading .btn-spinner {
            display: block;
        }

        /* ================================================
           FOOTER — Partner logos (adjusted for white)
           ================================================ */
        .auth-footer {
            margin-top: 28px;
            padding-top: 20px;
            border-top: 1px solid rgba(0, 0, 0, 0.06);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 14px;
        }

        .partner-logos {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .partner-logo {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #f5f2ef;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #e2dbd6;
            transition: all 0.3s ease;
            overflow: hidden;
        }

        .partner-logo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }

        .partner-logo svg {
            width: 18px;
            height: 18px;
            fill: #b0a69e;
            display: none;
        }

        .partner-logo:hover {
            border-color: #c0391e;
            background: #ffffff;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(192, 57, 30, 0.10);
        }

        .auth-copyright {
            font-size: 10px;
            color: #b0a69e;
            letter-spacing: 0.8px;
            font-weight: 300;
        }

        /* ================================================
           RESPONSIVE
           ================================================ */
        @media (max-width: 520px) {
            .auth-card {
                padding: 32px 20px 26px;
                border-radius: 20px;
                max-width: 100%;
            }

            .auth-title {
                font-size: 28px;
                letter-spacing: 2px;
            }

            .auth-logo {
                width: 64px;
                height: 64px;
            }

            .field input {
                padding: 13px 14px;
                font-size: 13px;
            }

            .auth-btn {
                padding: 14px;
                font-size: 12px;
            }

            .auth-sub {
                font-size: 12px;
            }

            .auth-close {
                top: 14px;
                right: 16px;
                font-size: 24px;
            }
        }

        @media (max-width: 400px) {
            .auth-card {
                padding: 24px 16px 20px;
            }

            .auth-title {
                font-size: 24px;
            }

            .auth-logo {
                width: 56px;
                height: 56px;
            }

            .partner-logos {
                gap: 12px;
            }

            .partner-logo {
                width: 30px;
                height: 30px;
            }

            .auth-close {
                top: 10px;
                right: 12px;
                font-size: 22px;
            }
        }

        @media (min-width: 900px) {
            .auth-card {
                padding: 48px 44px 38px;
                max-width: 500px;
            }

            .auth-card:hover {
                transform: translateY(-4px);
            }
        }

        /* ================================================
           UTILITY
           ================================================ */
        ::-webkit-scrollbar {
            width: 4px;
        }
        ::-webkit-scrollbar-track {
            background: transparent;
        }
        ::-webkit-scrollbar-thumb {
            background: rgba(192, 57, 30, 0.20);
            border-radius: 4px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: rgba(192, 57, 30, 0.35);
        }
    </style>
</head>
<body>

    <!-- ================================================
    BACKGROUND LAYERS
    ================================================ -->
    <div class="bg-layer">
        <div class="bg-base"></div>
        <div class="bg-grain"></div>
        <div class="bg-vignette"></div>
        <div class="orb orb-1"></div>
        <div class="orb orb-2"></div>
        <div class="orb orb-3"></div>

        <!-- Particles -->
        <div class="particle" style="width:5px;height:5px;left:8%;background:rgba(200,80,20,0.20);animation-duration:14s;animation-delay:0s;"></div>
        <div class="particle" style="width:3px;height:3px;left:22%;background:rgba(212,150,10,0.30);animation-duration:18s;animation-delay:2s;"></div>
        <div class="particle" style="width:6px;height:6px;left:38%;background:rgba(200,80,20,0.15);animation-duration:12s;animation-delay:0.8s;"></div>
        <div class="particle" style="width:4px;height:4px;left:54%;background:rgba(255,200,80,0.22);animation-duration:16s;animation-delay:3.5s;"></div>
        <div class="particle" style="width:7px;height:7px;left:68%;background:rgba(180,50,10,0.18);animation-duration:10s;animation-delay:1.2s;"></div>
        <div class="particle" style="width:3px;height:3px;left:80%;background:rgba(212,150,10,0.35);animation-duration:15s;animation-delay:0.4s;"></div>
        <div class="particle" style="width:5px;height:5px;left:90%;background:rgba(200,80,20,0.14);animation-duration:13s;animation-delay:5s;"></div>
        <div class="particle" style="width:4px;height:4px;left:45%;background:rgba(255,150,50,0.25);animation-duration:19s;animation-delay:6s;"></div>
    </div>

    <!-- ================================================
    AUTH PAGE
    ================================================ -->
    <div id="auth-page">
        <div class="auth-card">

            <!-- CLOSE BUTTON (X) -->
            <a href="../index.php" class="auth-close" aria-label="Close and return to login">×</a>

            <!-- HEADER -->
            <div class="auth-header">
                <div class="auth-logo-wrap">
                    <div class="auth-logo-ring"></div>
                    <div class="auth-logo-ring-2"></div>
                    <img class="auth-logo" src="../img/mdrrmo.png" alt="MDRRMO" onerror="this.style.display='none'">
                </div>
                <div class="auth-title">Forgot <span>Password</span></div>
                <div class="auth-sub">
                    Enter the email address linked to your account.<br>
                    We'll send you a <strong>6-digit code</strong> to reset your password.
                </div>
            </div>

            <!-- DIVIDER -->
            <div class="auth-divider"><span>Reset access</span></div>

            <!-- ERRORS -->
            <?php if ($errors): ?>
                <div class="auth-errors">
                    <ul>
                        <?php foreach ($errors as $err): ?>
                            <li><?php echo htmlspecialchars($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- FORM -->
            <form method="post" class="auth-form" id="forgot-form">
                <div class="field">
                    <label class="field-label" for="email">Email Address</label>
                    <input type="email" id="email" name="email" required
                           placeholder="yourname@example.com"
                           value="<?php echo htmlspecialchars($email); ?>"
                           autocomplete="email">
                </div>

                <button type="submit" class="auth-btn" id="submit-btn">
                    <span class="btn-text">Send Reset Code</span>
                    <div class="btn-spinner"></div>
                </button>
            </form>

            <!-- FOOTER -->
            <div class="auth-footer">
                <div class="partner-logos">
                    <div class="partner-logo">
                        <img src="../img/mdrrmo.png" alt="MDRRMO" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                        <svg viewBox="0 0 24 24"><path d="M12 2L3 7v5c0 5.25 3.75 10.15 9 11.35C17.25 22.15 21 17.25 21 12V7L12 2z"/></svg>
                    </div>
                    <div class="partner-logo">
                        <img src="../img/basc.png" alt="BASC" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                        <svg viewBox="0 0 24 24"><path d="M12 3L1 9l11 6 9-4.91V17h2V9L12 3zM5 13.18v4L12 21l7-3.82v-4L12 17l-7-3.82z"/></svg>
                    </div>
                    <div class="partner-logo">
                        <img src="../img/ics.jpg" alt="ICS" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                        <svg viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z"/></svg>
                    </div>
                </div>
                <div class="auth-copyright">&copy; 2026 MDRRMOxBASC_ICS &bull; San Ildefonso, Bulacan</div>
            </div>

        </div>
    </div>

    <!-- ================================================
    JAVASCRIPT — Button loading state + ripple
    ================================================ -->
    <script>
        (function() {
            const form = document.getElementById('forgot-form');
            const btn  = document.getElementById('submit-btn');

            if (form && btn) {
                form.addEventListener('submit', function() {
                    btn.classList.add('loading');
                    btn.disabled = true;

                    const emailInput = document.getElementById('email');
                    if (emailInput) emailInput.readOnly = true;
                });

                // Ripple effect on click
                btn.addEventListener('click', function(e) {
                    const rect = btn.getBoundingClientRect();
                    const size = Math.max(rect.width, rect.height);
                    const ripple = document.createElement('span');
                    ripple.style.cssText =
                        'position:absolute;border-radius:50%;pointer-events:none;' +
                        'width:' + size + 'px;height:' + size + 'px;' +
                        'left:' + (e.clientX - rect.left - size / 2) + 'px;' +
                        'top:' + (e.clientY - rect.top - size / 2) + 'px;' +
                        'background:rgba(255,255,255,0.15);' +
                        'transform:scale(0);opacity:1;' +
                        'transition:transform 0.5s ease,opacity 0.5s ease;';
                    btn.appendChild(ripple);
                    requestAnimationFrame(function() {
                        ripple.style.transform = 'scale(2.6)';
                        ripple.style.opacity = '0';
                    });
                    setTimeout(function() {
                        ripple.remove();
                    }, 600);
                });
            }
        })();
    </script>

</body>
</html>
```