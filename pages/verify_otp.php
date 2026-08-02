<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';

$pdo = db();

// If already verified and logged in, send to dashboard
if (current_user()) {
    redirect_by_role();
}

$email = trim($_GET['email'] ?? $_POST['email'] ?? '');
$errors = [];
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $otp = trim($_POST['otp'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email.';
    }
    if ($otp === '') {
        $errors[] = 'Please enter the code sent to your email.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            $errors[] = 'Account not found.';
        } elseif ((int)$user['is_email_verified'] === 1) {
            $message = 'Email already verified. You can log in.';
        } elseif (empty($user['otp_code_hash']) || empty($user['otp_expires_at'])) {
            $errors[] = 'No active verification code. Please sign up again.';
        } elseif (strtotime($user['otp_expires_at']) < time()) {
            $errors[] = 'Verification code has expired. Please sign up again.';
        } elseif (!password_verify($otp, $user['otp_code_hash'])) {
            $errors[] = 'Incorrect verification code.';
        } else {
            $upd = $pdo->prepare("UPDATE users SET is_email_verified = 1, otp_code_hash = NULL, otp_expires_at = NULL WHERE id = ?");
            $upd->execute([$user['id']]);
            $message = 'Email verified successfully. You can now log in.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Verify Email — MDRRMO San Ildefonso</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../asset/css/verify_otp.css">
<style>

</style>
</head>
<body>

<div class="mobile-view">
<div class="bg-orb bg-orb-1"></div>
<div class="bg-orb bg-orb-2"></div>

<div class="auth-card">

    <div class="auth-seal">
        <img src="../img/mdrrmo.png" alt="MDRRMO" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
        <svg viewBox="0 0 24 24" style="display:none" xmlns="http://www.w3.org/2000/svg">
            <rect x="2" y="4" width="20" height="16" rx="2" fill="none" stroke="#c0391e" stroke-width="1.6"/>
            <path d="M2 8l10 6 10-6" fill="none" stroke="#c0391e" stroke-width="1.6" stroke-linejoin="round"/>
        </svg>
    </div>

    <div class="auth-title">Verify Email</div>
    <div class="auth-sub">
        Enter the 6-digit code we sent to<br>
        <?php if ($email): ?>
            <strong><?php echo htmlspecialchars($email); ?></strong>
        <?php else: ?>
            your email address.
        <?php endif; ?>
    </div>

    <?php if ($errors): ?>
        <div class="auth-errors">
            <?php foreach ($errors as $err): ?>
                ⚠ <?php echo htmlspecialchars($err); ?><br>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($message): ?>
        <div class="auth-success">
            ✓ <?php echo htmlspecialchars($message); ?>
            <?php if (strpos($message, 'verified successfully') !== false): ?>
                <a href="../index.php">Log in now →</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!$message): ?>
    <form method="post" id="otp-form">
        <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
        <input type="hidden" name="otp" id="otp-hidden">

        <div class="otp-group" id="otp-boxes">
            <?php for ($i = 0; $i < 6; $i++): ?>
            <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="one-time-code">
            <?php endfor; ?>
        </div>

        <div class="auth-timer">Code expires in <span class="countdown-timer">10:00</span></div>

        <button type="submit" class="auth-btn" id="submit-btn" disabled>Verify Code</button>
    </form>

    <div class="auth-divider"><span>or</span></div>

    <div class="auth-links">
        <span>Didn't receive it?
            <button type="button" onclick="alert('Resend functionality — wire to your resend OTP endpoint.')">Resend code</button>
        </span>
        <a href="../index.php">← Back to login</a>
    </div>
    <?php else: ?>
    <div class="auth-links" style="justify-content:center;">
        <a href="../index.php">← Back to login</a>
    </div>
    <?php endif; ?>

    <div class="auth-footer">&copy; <?php echo date('Y'); ?> MDRRMOxBASC_ICS. All rights reserved.</div>
</div>
</div><!-- /.mobile-view -->

<!-- ══════════════════════════════════════════════
     DESKTOP: split-panel view (≥900px), same layout
     language as the login / forgot-password / reset-password /
     verify-device pages
     ══════════════════════════════════════════════ -->
<div id="desktop-page">
  <div class="dt-bg"></div>
  <div class="dt-spotlight"></div>
  <div class="dt-grain"></div>
  <div class="dt-orb dt-orb-1"></div>
  <div class="dt-orb dt-orb-2"></div>
  <div class="dt-orb dt-orb-3"></div>

  <div class="dt-card">

    <div class="dt-card-left">
      <div class="dt-otp-icon">
        <img src="../img/mdrrmo.png" alt="MDRRMO" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
        <svg viewBox="0 0 24 24" style="display:none" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect x="2" y="4" width="20" height="16" rx="2" stroke="rgba(255,255,255,0.85)" stroke-width="1.5"/>
            <path d="M2 8l10 6 10-6" stroke="rgba(255,255,255,0.85)" stroke-width="1.5" stroke-linejoin="round"/>
        </svg>
      </div>
      <div class="dt-agency">MDRRMO</div>
      <div class="dt-tagline">San Ildefonso</div>

      <div class="dt-info-pills">
          <div class="dt-pill">
              
              <div class="dt-pill-text">
                  <strong>Check your inbox</strong>
                  <span>A 6-digit code was sent to your email</span>
              </div>
          </div>
          <div class="dt-pill">
              
              <div class="dt-pill-text">
                  <strong>Code expires in 10 mins</strong>
                  <span>Request a new code if it expires</span>
              </div>
          </div>
          <div class="dt-pill">
             
              <div class="dt-pill-text">
                  <strong>One-time use only</strong>
                  <span>Code becomes invalid after use</span>
              </div>
          </div>
      </div>

      <div class="dt-bottom-badge">MDRRMO · Emergency Management System</div>
    </div>

    <div class="dt-card-right">
      <div class="dt-form-scroll">

        <div class="dt-welcome">Step 2 of 2</div>
        <div class="dt-form-title">Verify Email</div>
        <div class="dt-form-subtitle">
          Enter the 6-digit code we sent to<br>
          <?php if ($email): ?>
              <strong><?php echo htmlspecialchars($email); ?></strong>
          <?php else: ?>
              your email address.
          <?php endif; ?>
        </div>

        <?php if ($errors): ?>
            <div class="auth-errors">
                <?php foreach ($errors as $err): ?>
                    ⚠ <?php echo htmlspecialchars($err); ?><br>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($message): ?>
            <div class="auth-success">
                ✓ <?php echo htmlspecialchars($message); ?>
                <?php if (strpos($message, 'verified successfully') !== false): ?>
                    <a href="../index.php">Log in now →</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (!$message): ?>
        <form method="post" id="dt-otp-form">
            <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
            <input type="hidden" name="otp" id="dt-otp-hidden">

            <div class="otp-group" id="dt-otp-boxes">
                <?php for ($i = 0; $i < 6; $i++): ?>
                <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="one-time-code">
                <?php endfor; ?>
            </div>

            <div class="auth-timer">Code expires in <span class="countdown-timer">10:00</span></div>

            <button type="submit" class="auth-btn" id="dt-submit-btn" disabled>Verify Code</button>
        </form>

        <div class="auth-divider"><span>or</span></div>

        <div class="auth-links">
            <span>Didn't receive it?
                <button type="button" onclick="alert('Resend functionality — wire to your resend OTP endpoint.')">Resend code</button>
            </span>
            <a href="../index.php">← Back to login</a>
        </div>
        <?php else: ?>
        <div class="auth-links" style="justify-content:center;">
            <a href="../index.php">← Back to login</a>
        </div>
        <?php endif; ?>

        <div class="auth-footer">&copy; <?php echo date('Y'); ?> MDRRMOxBASC_ICS. All rights reserved.</div>

      </div>
    </div>

  </div>

  <div class="dt-status-bar">
    <span><span class="dt-status-dot"></span>System Online</span>
    <span>·</span>
    <span>MDRRMO San Ildefonso &copy; <?php echo date('Y'); ?></span>
  </div>
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

        form.addEventListener('submit', function () {
            hidden.value = collectValue();
        });
    }

    initOtpForm('otp-boxes',    'otp-hidden',    'submit-btn',    'otp-form');
    initOtpForm('dt-otp-boxes', 'dt-otp-hidden', 'dt-submit-btn', 'dt-otp-form');

    // Focus whichever OTP box set is actually visible
    function focusVisibleFirstBox() {
        var groups = [document.getElementById('otp-boxes'), document.getElementById('dt-otp-boxes')];
        groups.forEach(function (g) {
            if (g && g.offsetParent !== null) {
                var first = g.querySelector('input[type="text"]');
                if (first) first.focus();
            }
        });
    }
    focusVisibleFirstBox();

    var seconds = 10 * 60;
    var timerEls = document.querySelectorAll('.countdown-timer');
    if (timerEls.length) {
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
            timerEls.forEach(function (el) {
                el.textContent = text;
                if (seconds <= 60) { el.style.color = '#c0391e'; }
            });
        }, 1000);
    }
})();
</script>

</body>
</html>