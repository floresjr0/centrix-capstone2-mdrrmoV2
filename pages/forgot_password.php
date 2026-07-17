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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../asset/css/userlogin.css">
    <style>
        body { min-height: 100vh; }
        #auth-page {
            position: fixed;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            z-index: 100;
        }
        .auth-card {
            width: 100%;
            max-width: 460px;
            background: #fff;
            border-radius: 20px;
            padding: 40px 36px 32px;
            box-shadow: 0 32px 80px rgba(0,0,0,0.45);
        }
        .auth-logo {
            width: 72px;
            height: 72px;
            margin: 0 auto 18px;
            display: block;
            border-radius: 50%;
        }
        .auth-title {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 36px;
            letter-spacing: 3px;
            text-align: center;
            color: #1a0a06;
            margin-bottom: 8px;
        }
        .auth-sub {
            text-align: center;
            font-size: 13px;
            color: #888;
            line-height: 1.6;
            margin-bottom: 28px;
        }
        .auth-field { margin-bottom: 18px; }
        .auth-field label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #666;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .auth-field input {
            width: 100%;
            padding: 13px 14px;
            border: 1.5px solid #e8e8e8;
            border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            outline: none;
            background: #f7f7f7;
        }
        .auth-field input:focus {
            border-color: #c0391e;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(192,57,30,0.10);
        }
        .auth-btn {
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
            box-shadow: 0 4px 20px rgba(192,57,30,0.35);
        }
        .auth-back {
            display: block;
            text-align: center;
            margin-top: 22px;
            font-size: 13px;
            color: #888;
            text-decoration: none;
        }
        .auth-back strong { color: #c0391e; }
        .auth-errors {
            background: rgba(192,57,30,0.08);
            border: 1px solid rgba(192,57,30,0.35);
            border-radius: 10px;
            padding: 12px 16px;
            margin-bottom: 18px;
            font-size: 13px;
            color: #c0391e;
        }
    </style>
</head>
<body>
<div id="auth-page">
    <div class="auth-card">
        <img class="auth-logo" src="../img/mdrrmo.png" alt="MDRRMO" onerror="this.style.display='none'">
        <div class="auth-title">Forgot Password</div>
        <div class="auth-sub">
            Enter the email address linked to your account.<br>
            We'll send you a 6-digit code to reset your password.
        </div>

        <?php if ($errors): ?>
            <div class="auth-errors">
                <?php foreach ($errors as $err): ?>
                    ⚠ <?php echo htmlspecialchars($err); ?><br>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post">
            <div class="auth-field">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" required
                       placeholder="yourname@example.com"
                       value="<?php echo htmlspecialchars($email); ?>">
            </div>
            <button type="submit" class="auth-btn">Send Reset Code</button>
        </form>

        <a class="auth-back" href="../index.php">← <strong>Back to login</strong></a>
    </div>
</div>
</body>
</html>
