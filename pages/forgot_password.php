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
    <link rel="stylesheet" href="../asset/css/forgot_password.css">
    <style>
 
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