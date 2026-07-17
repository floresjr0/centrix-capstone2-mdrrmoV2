<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/session.php';

$pdo = db();

// If already logged in, send to appropriate dashboard
if (current_user()) {
    redirect_by_role();
}

$errors = [];

// Load active barangays (San Ildefonso only, enforced at DB level)
$barangays = $pdo->query("SELECT id, name FROM barangays WHERE is_active = 1 ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName  = trim($_POST['first_name']  ?? '');
    $lastName   = trim($_POST['last_name']   ?? '');
    $middleName = trim($_POST['middle_name'] ?? '');
    $suffix     = trim($_POST['suffix']      ?? '');
    $email      = trim($_POST['email']       ?? '');
    $password   = $_POST['password']         ?? '';
    $confirm    = $_POST['confirm_password'] ?? '';
    $barangayId = (int)($_POST['barangay_id'] ?? 0);
    $houseNo    = trim($_POST['house_number'] ?? '');
    $terms      = isset($_POST['terms']);

    if ($firstName === '') {
        $errors[] = 'First name is required.';
    }
    if ($lastName === '') {
        $errors[] = 'Last name is required.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email is required.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }
    if (!$barangayId) {
        $errors[] = 'Please select your barangay in San Ildefonso.';
    }
    if ($houseNo === '') {
        $errors[] = 'House number is required.';
    }
    if (!$terms) {
        $errors[] = 'You must agree to the terms.';
    }

    if (!$errors) {
        // Ensure barangay exists and is active (extra safety)
        $stmt = $pdo->prepare("SELECT id FROM barangays WHERE id = ? AND is_active = 1");
        $stmt->execute([$barangayId]);
        if (!$stmt->fetch()) {
            $errors[] = 'The selected barangay is not valid for San Ildefonso.';
        }
    }

    if (!$errors) {
        // Check email uniqueness
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $errors[] = 'An account with this email already exists.';
        }
    }

    if (!$errors) {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $otp          = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $otpHash      = password_hash($otp, PASSWORD_DEFAULT);
        $expiresAt    = date('Y-m-d H:i:s', time() + 15 * 60); // 15 minutes

        $displayName = trim($firstName . ' ' . $lastName);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                INSERT INTO users
                    (first_name, last_name, middle_name, suffix,
                     email, password_hash, role,
                     barangay_id, house_number,
                     is_email_verified, otp_code_hash, otp_expires_at)
                VALUES (?, ?, ?, ?, ?, ?, 'citizen', ?, ?, 0, ?, ?)
            ");
            $stmt->execute([
                $firstName,
                $lastName,
                $middleName !== '' ? $middleName : null,
                $suffix     !== '' ? $suffix     : null,
                $email,
                $passwordHash,
                $barangayId,
                $houseNo,
                $otpHash,
                $expiresAt,
            ]);

            // Send OTP (currently logged to otp_test.log)
            send_otp_email($email, $displayName, $otp);

            $pdo->commit();

            header('Location: verify_otp.php?email=' . urlencode($email));
            exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Account creation failed. Please try again.';
        }
    }
}

function old(string $key, string $default = ''): string {
    return htmlspecialchars($_POST[$key] ?? $default);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Sign Up - MDRRMO San Ildefonso</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<link rel="stylesheet" href="../asset/css/usersignup.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<!-- All styles are inline below — no external CSS needed -->
</head>
<style>


</style>
<body>

<!-- ================================================
     MOBILE: Signup Shell
     ================================================ -->
<div class="signup-shell">

  <!-- ── HERO ── -->
  <div class="hero">
    <div class="logo-row" id="logoRow">
      <div class="logo-circle">
        <img src="../img/mdrrmo.png" alt="MDRRMO"
             onerror="this.style.display='none'">
        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
          <path d="M12 2L3 7v5c0 5.25 3.75 10.15 9 11.35C17.25 22.15 21 17.25 21 12V7L12 2z"/>
        </svg>
      </div>
      <div class="logo-text">
        <strong>Office of the Municipal Disaster Risk Reduction<br>and Management Office</strong>
        <span>San Ildefonso, Bulacan</span>
      </div>
    </div>

    <div class="hero-headline" id="heroHeadline">
      Create an<br>Account
    </div>
  </div>

  <!-- ── WHITE CARD ── -->
  <div class="card" id="card">

    <div class="card-scroll">

      <?php if ($errors): ?>
      <div class="auth-errors">
        <ul>
          <?php foreach ($errors as $err): ?>
            <li><?= htmlspecialchars($err) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>

      <form method="post" class="auth-form" id="signupForm">

        <div class="section-divider"><span>Personal Information</span></div>

        <div class="field">
          <label class="field-label" for="first_name">First Name <span class="req">*</span></label>
          <input type="text" id="first_name" name="first_name" required
                 placeholder="Juan"
                 value="<?= old('first_name') ?>">
        </div>

        <div class="field">
          <label class="field-label" for="middle_name">
            Middle Name <span class="optional">(optional)</span>
          </label>
          <input type="text" id="middle_name" name="middle_name"
                 placeholder="Santos"
                 value="<?= old('middle_name') ?>">
        </div>

        <div class="field">
          <label class="field-label" for="last_name">Last Name <span class="req">*</span></label>
          <input type="text" id="last_name" name="last_name" required
                 placeholder="Dela Cruz"
                 value="<?= old('last_name') ?>">
        </div>

        <div class="field">
          <label class="field-label" for="suffix">
            Suffix <span class="optional">(optional)</span>
          </label>
          <div class="select-wrap">
            <select id="suffix" name="suffix">
              <option value="">— None —</option>
              <?php foreach (['Jr.','Sr.','II','III','IV','V'] as $sfx): ?>
                <option value="<?= $sfx ?>" <?= old('suffix') === $sfx ? 'selected' : '' ?>>
                  <?= $sfx ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="field">
          <label class="field-label" for="barangay_id">Barangay <span class="req">*</span></label>
          <div class="select-wrap">
            <select id="barangay_id" name="barangay_id" required>
              <option value="">Select Barangay</option>
              <?php foreach ($barangays as $b): ?>
                <option value="<?= (int)$b['id'] ?>"
                  <?= (isset($_POST['barangay_id']) && (int)$_POST['barangay_id'] === (int)$b['id']) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($b['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="field">
          <label class="field-label" for="house_number">House Number <span class="req">*</span></label>
          <input type="text" id="house_number" name="house_number" required
                 placeholder="e.g. 123"
                 value="<?= old('house_number') ?>">
        </div>

        <div class="field">
          <label class="field-label" for="address">Detected Address</label>
          <input type="text" id="address" name="detected_address" readonly
                 placeholder="Getting location...">
          <small id="locationWarning" class="location-warning"></small>
        </div>

        <input type="hidden" id="lat">
        <input type="hidden" id="lng">

        <div class="section-divider" style="margin-top:0.5rem;"><span>Account Information</span></div>

        <div class="field">
          <label class="field-label" for="email">Email <span class="req">*</span></label>
          <input type="email" id="email" name="email" required
                 placeholder="juandelacruz@gmail.com"
                 value="<?= old('email') ?>">
        </div>

        <!-- Password field — right-side eye icon only -->
        <div class="field">
          <label class="field-label" for="password">Password <span class="req">*</span></label>
          <div class="pw-wrap">
            <input type="password" id="password" name="password" required minlength="8"
                   placeholder="At least 8 characters">
            <button type="button" class="pw-toggle" data-target="password" aria-label="Toggle password visibility">
              <svg class="icon-eye" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                <circle cx="12" cy="12" r="3"/>
              </svg>
              <svg class="icon-eye-off" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                   style="display:none;">
                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/>
                <line x1="1" y1="1" x2="23" y2="23"/>
              </svg>
            </button>
          </div>
        </div>

        <!-- Confirm Password field — right-side eye icon only -->
        <div class="field">
          <label class="field-label" for="confirm_password">Confirm Password <span class="req">*</span></label>
          <div class="pw-wrap">
            <input type="password" id="confirm_password" name="confirm_password" required minlength="8"
                   placeholder="Repeat password">
            <button type="button" class="pw-toggle" data-target="confirm_password" aria-label="Toggle confirm password visibility">
              <svg class="icon-eye" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                <circle cx="12" cy="12" r="3"/>
              </svg>
              <svg class="icon-eye-off" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                   style="display:none;">
                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/>
                <line x1="1" y1="1" x2="23" y2="23"/>
              </svg>
            </button>
          </div>
        </div>

        <!-- Terms — opens modal, checkbox only checks after acceptance -->
        <div class="checkbox-field">
          <input type="checkbox" name="terms" id="terms" value="1"
                 <?= isset($_POST['terms']) ? 'checked' : '' ?>
                 readonly>
          <label for="terms">
            I confirm that I am a resident of San Ildefonso, Bulacan and agree to MDRRMO's
            <button type="button" class="terms-trigger-link" id="termsOpenBtn">
              Data Policy &amp; Terms of Use
            </button>.
          </label>
        </div>

      </form>
    </div>

    <div class="card-footer">
      <button type="submit" form="signupForm" class="btn-signup">Sign Up</button>
      <p class="login-link">Already have an account? <a href="../index.php">Login</a></p>
    </div>

  </div><!-- /card -->

</div><!-- /.signup-shell -->


<!-- ================================================
     DESKTOP: Centered Card Layout
     ================================================ -->
<div id="desktop-page">

  <!-- CENTERED CARD -->
  <div class="dt-card">

    <!-- LEFT: Branding -->
    <div class="dt-card-left">

      <div class="dt-seal-wrap">
        <img src="../img/mdrrmo.png" alt="MDRRMO Seal"
             onerror="this.style.display='none'">
      </div>

      <div class="dt-agency">MDRRMO</div>
      <div class="dt-tagline">#BidaAngLagingHanda</div>
      <div class="dt-bottom-badge">Municipal Government of San Ildefonso</div>

    </div><!-- /.dt-card-left -->

    <!-- RIGHT: Signup Form -->
    <div class="dt-card-right">

      <div class="dt-form-scroll">

        <div class="dt-form-header">
          <div class="dt-welcome">Join the Community</div>
          <div class="dt-form-title">Create an<br>Account</div>
          <div class="dt-form-subtitle">Register as a resident of San Ildefonso, Bulacan<br>to stay prepared and informed.</div>
        </div>

        <?php if ($errors): ?>
        <div class="dt-errors">
          <ul>
            <?php foreach ($errors as $err): ?>
              <li><?= htmlspecialchars($err) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>

        <form method="post" id="dtSignupForm">

          <div class="dt-section-divider"><span>Personal Information</span></div>

          <div class="dt-fields-grid">

            <div class="dt-field">
              <label for="dt-first_name">First Name *</label>
              <input type="text" id="dt-first_name" name="first_name" required
                     placeholder="Juan"
                     value="<?= old('first_name') ?>">
            </div>

            <div class="dt-field">
              <label for="dt-last_name">Last Name *</label>
              <input type="text" id="dt-last_name" name="last_name" required
                     placeholder="Dela Cruz"
                     value="<?= old('last_name') ?>">
            </div>

            <div class="dt-field">
              <label for="dt-middle_name">
                Middle Name <span class="dt-optional">(optional)</span>
              </label>
              <input type="text" id="dt-middle_name" name="middle_name"
                     placeholder="Santos"
                     value="<?= old('middle_name') ?>">
            </div>

            <div class="dt-field">
              <label for="dt-suffix">
                Suffix <span class="dt-optional">(optional)</span>
              </label>
              <div class="dt-select-wrap">
                <select id="dt-suffix" name="suffix">
                  <option value="">— None —</option>
                  <?php foreach (['Jr.','Sr.','II','III','IV','V'] as $sfx): ?>
                    <option value="<?= $sfx ?>" <?= old('suffix') === $sfx ? 'selected' : '' ?>>
                      <?= $sfx ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="dt-field">
              <label for="dt-barangay_id">Barangay *</label>
              <div class="dt-select-wrap">
                <select id="dt-barangay_id" name="barangay_id" required>
                  <option value="">Select Barangay</option>
                  <?php foreach ($barangays as $b): ?>
                    <option value="<?= (int)$b['id'] ?>"
                      <?= (isset($_POST['barangay_id']) && (int)$_POST['barangay_id'] === (int)$b['id']) ? 'selected' : '' ?>>
                      <?= htmlspecialchars($b['name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="dt-field">
              <label for="dt-house_number">House Number *</label>
              <input type="text" id="dt-house_number" name="house_number" required
                     placeholder="e.g. 123"
                     value="<?= old('house_number') ?>">
            </div>

            <div class="dt-field dt-field-full">
              <label for="dt-address">Detected Address</label>
              <input type="text" id="dt-address" name="detected_address" readonly
                     placeholder="Getting location...">
              <small id="dt-locationWarning" class="location-warning"></small>
            </div>

          </div><!-- /.dt-fields-grid -->

          <input type="hidden" id="dt-lat">
          <input type="hidden" id="dt-lng">

          <div class="dt-section-divider" style="margin-top:8px;"><span>Account Information</span></div>

          <div class="dt-fields-grid">

            <div class="dt-field dt-field-full">
              <label for="dt-email">Email *</label>
              <input type="email" id="dt-email" name="email" required
                     placeholder="juandelacruz@gmail.com"
                     value="<?= old('email') ?>">
            </div>

            <!-- Desktop: Password field — right-side eye icon only -->
            <div class="dt-field">
              <label for="dt-password">Password *</label>
              <div class="dt-pw-wrap">
                <input type="password" id="dt-password" name="password" required minlength="8"
                       placeholder="At least 8 characters">
                <button type="button" class="dt-pw-toggle" data-target="dt-password" aria-label="Toggle password visibility">
                  <svg class="icon-eye" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                       stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                    <circle cx="12" cy="12" r="3"/>
                  </svg>
                  <svg class="icon-eye-off" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                       stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                       style="display:none;">
                    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/>
                    <line x1="1" y1="1" x2="23" y2="23"/>
                  </svg>
                </button>
              </div>
            </div>

            <!-- Desktop: Confirm Password field — right-side eye icon only -->
            <div class="dt-field">
              <label for="dt-confirm_password">Confirm Password *</label>
              <div class="dt-pw-wrap">
                <input type="password" id="dt-confirm_password" name="confirm_password" required minlength="8"
                       placeholder="Repeat password">
                <button type="button" class="dt-pw-toggle" data-target="dt-confirm_password" aria-label="Toggle confirm password visibility">
                  <svg class="icon-eye" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                       stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                    <circle cx="12" cy="12" r="3"/>
                  </svg>
                  <svg class="icon-eye-off" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                       stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                       style="display:none;">
                    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/>
                    <line x1="1" y1="1" x2="23" y2="23"/>
                  </svg>
                </button>
              </div>
            </div>

          </div><!-- /.dt-fields-grid -->

          <!-- Terms — opens modal, checkbox only checks after acceptance -->
          <div class="dt-checkbox-field">
            <input type="checkbox" name="terms" id="dt-terms" value="1"
                   <?= isset($_POST['terms']) ? 'checked' : '' ?>
                   readonly>
            <label for="dt-terms">
              I confirm that I am a resident of San Ildefonso, Bulacan and agree to MDRRMO's
              <button type="button" class="terms-trigger-link" id="dtTermsOpenBtn">
                Data Policy &amp; Terms of Use
              </button>.
            </label>
          </div>

        </form>
      </div><!-- /.dt-form-scroll -->

      <!-- Fixed footer -->
      <div class="dt-card-footer">
        <button type="submit" form="dtSignupForm" class="dt-btn-signup">Create Account</button>
        <p class="dt-login-link">Already have an account? <a href="../index.php">Login</a></p>
      </div>

    </div><!-- /.dt-card-right -->

  </div><!-- /.dt-card -->

  <!-- Status bar -->
  <div class="dt-status-bar">
    <span><span class="dt-status-dot"></span>System Online</span>
    <span>·</span>
    <span>MDRRMO · San Ildefonso, Bulacan</span>
  </div>

</div><!-- /#desktop-page -->


<!-- ================================================================
     TERMS & CONDITIONS MODAL (shared — mobile + desktop)
     ================================================================ -->
<div class="terms-backdrop" id="termsBackdrop" role="dialog" aria-modal="true" aria-label="Terms and Conditions">

  <div class="terms-modal" id="termsModal">

    <!-- Mobile drag handle -->
    <div class="terms-drag-handle"></div>

    <!-- ── HEADER ── -->
    <div class="terms-header">
      <div class="terms-header-inner">
        <!-- MDRRMO logo -->
        <div class="terms-icon-wrap" aria-hidden="true">
          <img src="../img/mdrrmo.png" alt="MDRRMO Logo">
        </div>

        <div class="terms-header-text">
          <div class="terms-eyebrow">MDRRMO San Ildefonso</div>
          <div class="terms-title">Data Policy &amp; Terms</div>
          <div class="terms-subtitle">Please read all sections before accepting.</div>
        </div>

        <!-- Close -->
        <button class="terms-close-btn" id="termsCloseBtn" aria-label="Close terms modal">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
               stroke-linecap="round" stroke-linejoin="round">
            <line x1="18" y1="6" x2="6" y2="18"/>
            <line x1="6" y1="6" x2="18" y2="18"/>
          </svg>
        </button>
      </div>

      <div class="terms-header-divider"></div>
    </div>

    <!-- ── READING PROGRESS ── -->
    <div class="terms-progress-wrap">
      <div class="terms-progress-label">
        <span>Reading Progress</span>
        <span class="terms-pct" id="termsPct">0%</span>
      </div>
      <div class="terms-progress-track">
        <div class="terms-progress-fill" id="termsProgressFill"></div>
      </div>
    </div>

    <!-- ── SCROLLABLE TERMS BODY ── -->
    <div class="terms-body" id="termsBody">

      <!-- Section 1 -->
      <div class="terms-section" id="tsec-1">
        <div class="terms-section-header">
          <div class="terms-section-num">1</div>
          <div class="terms-section-title">Purpose &amp; Scope</div>
        </div>
        <div class="terms-section-body">
          <p>This registration system is operated by the <strong>Municipal Disaster Risk Reduction and Management Office (MDRRMO)</strong> of San Ildefonso, Bulacan. The system is designed to facilitate early warning notifications, disaster response coordination, relief assistance profiling, and community preparedness programs.</p>
          <p>By creating an account, you voluntarily participate in MDRRMO's disaster risk reduction initiatives and agree to be bound by these terms.</p>
        </div>
      </div>

      <!-- Section 2 -->
      <div class="terms-section" id="tsec-2">
        <div class="terms-section-header">
          <div class="terms-section-num">2</div>
          <div class="terms-section-title">Personal Data We Collect</div>
        </div>
        <div class="terms-section-body">
          <div class="terms-info-chip">
            <svg viewBox="0 0 24 24"><path d="M12 2a10 10 0 1 0 0 20A10 10 0 0 0 12 2zm1 14H11v-6h2v6zm0-8H11V6h2v2z"/></svg>
            Collected at Registration
          </div>
          <p>We collect the following personal information from you:</p>
          <ul>
            <li><strong>Full Name</strong> — first, middle, last name, and suffix (if applicable)</li>
            <li><strong>Home Address</strong> — barangay, house number, and optional GPS coordinates</li>
            <li><strong>Email Address</strong> — for account verification and emergency alerts</li>
            <li><strong>Password</strong> — stored securely using industry-standard hashing (never stored in plaintext)</li>
          </ul>
        </div>
      </div>

      <!-- Section 3 -->
      <div class="terms-section" id="tsec-3">
        <div class="terms-section-header">
          <div class="terms-section-num">3</div>
          <div class="terms-section-title">How We Use Your Data</div>
        </div>
        <div class="terms-section-body">
          <p>Your personal data is used exclusively for the following purposes:</p>
          <ul>
            <li>Sending <strong>disaster early warnings</strong> and emergency alerts relevant to your barangay</li>
            <li>Identifying households in need of <strong>evacuation assistance</strong> during calamities</li>
            <li>Profiling residents for <strong>relief goods distribution</strong> and post-disaster assessment</li>
            <li>Generating aggregated, non-identifiable community risk maps and statistics</li>
            <li>Communicating <strong>preparedness advisories</strong> and community drills</li>
          </ul>
          <div class="terms-highlight">
            Your information will <strong>NOT</strong> be used for commercial purposes, sold to third parties, or used for any activity unrelated to disaster risk reduction and public safety.
          </div>
        </div>
      </div>

      <!-- Section 4 -->
      <div class="terms-section" id="tsec-4">
        <div class="terms-section-header">
          <div class="terms-section-num">4</div>
          <div class="terms-section-title">Data Sharing &amp; Disclosure</div>
        </div>
        <div class="terms-section-body">
          <p>MDRRMO may share your information <strong>only</strong> with:</p>
          <ul>
            <li>The <strong>Municipal Government of San Ildefonso</strong> for official disaster response operations</li>
            <li>The <strong>National Disaster Risk Reduction and Management Council (NDRRMC)</strong> and Provincial DRRMO for coordinated response</li>
            <li>Authorized <strong>barangay officials</strong> within your registered barangay for local coordination</li>
            <li>Law enforcement or emergency services when required to <strong>protect life or safety</strong></li>
          </ul>
          <p>We will never disclose your personal data to commercial entities or unauthorized parties.</p>
        </div>
      </div>

      <!-- Section 5 -->
      <div class="terms-section" id="tsec-5">
        <div class="terms-section-header">
          <div class="terms-section-num">5</div>
          <div class="terms-section-title">Residency Requirement</div>
        </div>
        <div class="terms-section-body">
          <div class="terms-highlight">
            Registration is <strong>strictly limited to actual residents of San Ildefonso, Bulacan</strong>. By checking the agreement box, you declare under good faith that you reside within the municipality. Providing false residency information may result in account suspension and disqualification from relief services.
          </div>
          <p>MDRRMO reserves the right to verify residency through barangay certifications or cross-referencing with existing resident records. Location detection is used only as an advisory check and does not block registration.</p>
        </div>
      </div>

      <!-- Section 6 -->
      <div class="terms-section" id="tsec-6">
        <div class="terms-section-header">
          <div class="terms-section-num">6</div>
          <div class="terms-section-title">User Responsibilities</div>
        </div>
        <div class="terms-section-body">
          <p>As a registered resident, you agree to:</p>
          <ul>
            <li>Provide <strong>accurate and truthful information</strong> during registration and any subsequent updates</li>
            <li><strong>Update your profile</strong> if your address or contact details change</li>
            <li>Keep your login credentials <strong>confidential</strong> and not share your account</li>
            <li>Use the system only for its <strong>intended lawful purposes</strong></li>
            <li>Promptly report any <strong>suspicious activity</strong> on your account to MDRRMO</li>
          </ul>
        </div>
      </div>

      <!-- Section 7 -->
      <div class="terms-section" id="tsec-7">
        <div class="terms-section-header">
          <div class="terms-section-num">7</div>
          <div class="terms-section-title">Emergency Notifications Consent</div>
        </div>
        <div class="terms-section-body">
          <p>By registering, you explicitly consent to receiving <strong>emergency-related communications</strong> from MDRRMO through your registered email address. These may include:</p>
          <ul>
            <li>Typhoon, flooding, and earthquake warnings</li>
            <li>Mandatory evacuation orders</li>
            <li>Relief distribution schedules and venues</li>
            <li>Community preparedness drills and announcements</li>
          </ul>
          <p>You may update your notification preferences within your account settings at any time. However, opting out of critical safety alerts is <strong>not recommended</strong> as these may be life-saving.</p>
        </div>
      </div>

      <!-- Section 8 -->
      <div class="terms-section" id="tsec-8">
        <div class="terms-section-header">
          <div class="terms-section-num">8</div>
          <div class="terms-section-title">Data Security</div>
        </div>
        <div class="terms-section-body">
          <p>MDRRMO employs the following security measures to protect your personal data:</p>
          <ul>
            <li>Passwords are encrypted using <strong>bcrypt hashing</strong> — we cannot retrieve your password</li>
            <li>All data transmissions are protected through <strong>secure HTTPS connections</strong></li>
            <li>Database access is restricted to authorized MDRRMO personnel only</li>
            <li>Email verification via <strong>One-Time Password (OTP)</strong> is required upon registration</li>
            <li>System activity logs are maintained for <strong>security auditing</strong></li>
          </ul>
          <p>While we implement robust security measures, no online system is completely immune to risks. You are encouraged to use a strong, unique password.</p>
        </div>
      </div>

      <!-- Section 9 -->
      <div class="terms-section" id="tsec-9">
        <div class="terms-section-header">
          <div class="terms-section-num">9</div>
          <div class="terms-section-title">Your Rights Under the Data Privacy Act</div>
        </div>
        <div class="terms-section-body">
          <p>In accordance with the <strong>Republic Act 10173 (Data Privacy Act of 2012)</strong>, you have the following rights:</p>
          <ul>
            <li><strong>Right to Access</strong> — request a copy of your personal data held by MDRRMO</li>
            <li><strong>Right to Rectification</strong> — correct inaccurate or incomplete personal information</li>
            <li><strong>Right to Erasure</strong> — request deletion of your account and associated data</li>
            <li><strong>Right to Object</strong> — object to processing of your data for certain purposes</li>
            <li><strong>Right to Data Portability</strong> — receive your data in a structured, readable format</li>
          </ul>
          <p>To exercise any of these rights, contact the MDRRMO Data Privacy Officer at the address below.</p>
        </div>
      </div>

      <!-- Section 10 -->
      <div class="terms-section" id="tsec-10">
        <div class="terms-section-header">
          <div class="terms-section-num">10</div>
          <div class="terms-section-title">Limitation of Liability</div>
        </div>
        <div class="terms-section-body">
          <p>MDRRMO strives to maintain accurate and timely disaster information; however, the office is <strong>not liable</strong> for:</p>
          <ul>
            <li>Delays in emergency notifications caused by technical failures or network outages</li>
            <li>Decisions made based on information provided through this system</li>
            <li>Unauthorized access resulting from the user's failure to secure their credentials</li>
          </ul>
          <p>This system is a supplementary tool and does not replace direct communication with emergency services, barangay officials, or local government units.</p>
        </div>
      </div>

      <!-- Section 11 -->
      <div class="terms-section" id="tsec-11">
        <div class="terms-section-header">
          <div class="terms-section-num">11</div>
          <div class="terms-section-title">Contact &amp; Concerns</div>
        </div>
        <div class="terms-section-body">
          <p>For questions, concerns, or to exercise your data privacy rights, please contact:</p>
          <div class="terms-highlight">
            <strong>MDRRMO San Ildefonso, Bulacan</strong><br>
            Municipal Hall, San Ildefonso, Bulacan<br>
            Email: mdrrmo@sanildefonso.gov.ph<br>
            Office Hours: Monday–Friday, 8:00 AM – 5:00 PM
          </div>
          <p style="font-size:11px; color:#999; margin-top:8px;">
            These terms were last updated on <strong>June 2026</strong> and are subject to revision. Continued use of the system after any amendments constitutes acceptance of the updated terms.
          </p>
        </div>
      </div>

      <!-- End of terms marker -->
      <div class="terms-end-marker">
        <span>End of Terms &amp; Conditions</span>
      </div>

    </div><!-- /.terms-body -->

    <!-- Scroll prompt — hides once user reaches bottom -->
    <div class="terms-scroll-prompt" id="termsScrollPrompt">
      <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
        <polyline points="6 9 12 15 18 9"/>
      </svg>
      Scroll to read all terms
    </div>

    <!-- ── MODAL FOOTER: Decline + Accept ── -->
    <div class="terms-footer">
      <button type="button" class="terms-btn-decline" id="termsBtnDecline">
        Decline
      </button>
      <button type="button" class="terms-btn-accept locked" id="termsBtnAccept">
        <!-- Lock icon shown while locked -->
        <svg class="lock-icon" id="termsLockIcon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
          <path d="M19 11H5a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7a2 2 0 0 0-2-2zM7 11V7a5 5 0 0 1 10 0v4"/>
        </svg>
        <!-- Check icon shown when unlocked -->
        <svg id="termsCheckIcon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" style="display:none;">
          <polyline points="20 6 9 17 4 12"/>
        </svg>
        <span id="termsAcceptLabel">Read All Terms First</span>
      </button>
    </div>

  </div><!-- /.terms-modal -->
</div><!-- /.terms-backdrop -->


<!-- ── SUCCESS TOAST ── -->
<div class="terms-toast" id="termsToast" aria-live="polite">
  <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
    <polyline points="20 6 9 17 4 12"/>
  </svg>
  Terms accepted — you're all set!
</div>

<!-- ── GEOLOCATION WARNING TOAST (non-blocking) ── -->
<div class="geo-toast" id="geoToast" aria-live="polite"></div>


<script>

  /* ================================================
     MOBILE: Entrance animations
     ================================================ */
  window.addEventListener('DOMContentLoaded', function () {
    requestAnimationFrame(function () {
      setTimeout(function () {
        document.getElementById('logoRow').classList.add('visible');
        document.getElementById('heroHeadline').classList.add('visible');
      }, 60);
      setTimeout(function () {
        document.getElementById('card').classList.add('visible');
      }, 180);
    });
  });

  /* Mobile: underline focus highlight */
  document.querySelectorAll('.field input, .field select').forEach(function (inp) {
    inp.addEventListener('focus', function () {
      this.style.setProperty('border-bottom', '1.5px solid #c0391e', 'important');
    });
    inp.addEventListener('blur', function () {
      this.style.setProperty('border-bottom', '1.5px solid #c8c8c8', 'important');
    });
  });

  /* Mobile: button ripple */
  var btn = document.querySelector('.btn-signup');
  if (btn) {
    btn.addEventListener('click', function (e) {
      var r   = btn.getBoundingClientRect();
      var sz  = Math.max(r.width, r.height);
      var rpl = document.createElement('span');
      rpl.style.cssText =
        'position:absolute;border-radius:50%;pointer-events:none;' +
        'width:'  + sz + 'px;height:' + sz + 'px;' +
        'left:'   + (e.clientX - r.left - sz / 2) + 'px;' +
        'top:'    + (e.clientY - r.top  - sz / 2) + 'px;' +
        'background:rgba(255,255,255,0.20);' +
        'transform:scale(0);opacity:1;' +
        'transition:transform 0.55s ease,opacity 0.55s ease;';
      btn.appendChild(rpl);
      requestAnimationFrame(function () {
        rpl.style.transform = 'scale(2.6)';
        rpl.style.opacity   = '0';
      });
      setTimeout(function () { rpl.remove(); }, 600);
    });
  }

  /* Desktop: button ripple */
  var dtBtn = document.querySelector('.dt-btn-signup');
  if (dtBtn) {
    dtBtn.addEventListener('click', function (e) {
      var r   = dtBtn.getBoundingClientRect();
      var sz  = Math.max(r.width, r.height);
      var rpl = document.createElement('span');
      rpl.style.cssText =
        'position:absolute;border-radius:50%;pointer-events:none;' +
        'width:'  + sz + 'px;height:' + sz + 'px;' +
        'left:'   + (e.clientX - r.left - sz / 2) + 'px;' +
        'top:'    + (e.clientY - r.top  - sz / 2) + 'px;' +
        'background:rgba(255,255,255,0.18);' +
        'transform:scale(0);opacity:1;' +
        'transition:transform 0.55s ease,opacity 0.55s ease;';
      dtBtn.appendChild(rpl);
      requestAnimationFrame(function () {
        rpl.style.transform = 'scale(2.8)';
        rpl.style.opacity   = '0';
      });
      setTimeout(function () { rpl.remove(); }, 600);
    });
  }

  /* ================================================
     PASSWORD TOGGLE — shared handler for mobile & desktop
     ================================================ */
  document.querySelectorAll('.pw-toggle, .dt-pw-toggle').forEach(function (toggleBtn) {
    toggleBtn.addEventListener('click', function () {
      var targetId   = this.getAttribute('data-target');
      var inputEl    = document.getElementById(targetId);
      var iconEye    = this.querySelector('.icon-eye');
      var iconEyeOff = this.querySelector('.icon-eye-off');

      if (!inputEl) return;

      if (inputEl.type === 'password') {
        inputEl.type             = 'text';
        iconEye.style.display    = 'none';
        iconEyeOff.style.display = 'block';
        this.setAttribute('aria-label', 'Hide password');
      } else {
        inputEl.type             = 'password';
        iconEye.style.display    = 'block';
        iconEyeOff.style.display = 'none';
        this.setAttribute('aria-label', 'Show password');
      }

      inputEl.focus();
    });
  });

  /* ================================================
     TERMS & CONDITIONS MODAL LOGIC
     ================================================ */
  (function () {

    var backdrop      = document.getElementById('termsBackdrop');
    var modal         = document.getElementById('termsModal');
    var body          = document.getElementById('termsBody');
    var closeBtn      = document.getElementById('termsCloseBtn');
    var acceptBtn     = document.getElementById('termsBtnAccept');
    var declineBtn    = document.getElementById('termsBtnDecline');
    var progressFill  = document.getElementById('termsProgressFill');
    var pctLabel      = document.getElementById('termsPct');
    var scrollPrompt  = document.getElementById('termsScrollPrompt');
    var toast         = document.getElementById('termsToast');
    var lockIcon      = document.getElementById('termsLockIcon');
    var checkIcon     = document.getElementById('termsCheckIcon');
    var acceptLabel   = document.getElementById('termsAcceptLabel');

    var termsCheckbox   = document.getElementById('terms');
    var termsOpenBtn    = document.getElementById('termsOpenBtn');

    var dtTermsCheckbox = document.getElementById('dt-terms');
    var dtTermsOpenBtn  = document.getElementById('dtTermsOpenBtn');

    var hasReadAll    = false;
    var hasAccepted   = false;
    var toastTimer    = null;

    /* Prevent checkbox direct toggle — must go through modal */
    [termsCheckbox, dtTermsCheckbox].forEach(function (cb) {
      if (!cb) return;
      cb.addEventListener('click', function (e) {
        e.preventDefault();
        if (!hasAccepted) {
          openModal();
        } else {
          hasAccepted = false;
          hasReadAll  = false;
          cb.checked  = false;
          if (termsCheckbox)   termsCheckbox.checked   = false;
          if (dtTermsCheckbox) dtTermsCheckbox.checked = false;
          resetModal();
        }
      });
    });

    /* Open modal via "Data Policy & Terms" link buttons */
    [termsOpenBtn, dtTermsOpenBtn].forEach(function (triggerBtn) {
      if (!triggerBtn) return;
      triggerBtn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        openModal();
      });
    });

    function openModal() {
      if (!hasAccepted) {
        body.scrollTop = 0;
        updateProgress(0);
        unlockState(false);
      }
      backdrop.classList.add('open');
      document.body.style.overflow = 'hidden';
      setTimeout(revealSections, 120);
    }

    function closeModal() {
      backdrop.classList.remove('open');
      document.body.style.overflow = '';
    }

    backdrop.addEventListener('click', function (e) {
      if (e.target === backdrop) closeModal();
    });

    closeBtn.addEventListener('click', closeModal);

    declineBtn.addEventListener('click', function () {
      hasAccepted = false;
      if (termsCheckbox)   termsCheckbox.checked   = false;
      if (dtTermsCheckbox) dtTermsCheckbox.checked = false;
      closeModal();
    });

    acceptBtn.addEventListener('click', function () {
      if (!hasReadAll) return;

      hasAccepted = true;
      if (termsCheckbox)   termsCheckbox.checked   = true;
      if (dtTermsCheckbox) dtTermsCheckbox.checked = true;

      closeModal();
      showToast();
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && backdrop.classList.contains('open')) {
        closeModal();
      }
    });

    body.addEventListener('scroll', function () {
      var scrollTop    = body.scrollTop;
      var scrollHeight = body.scrollHeight - body.clientHeight;
      var pct          = scrollHeight > 0 ? Math.min(100, Math.round((scrollTop / scrollHeight) * 100)) : 100;

      updateProgress(pct);

      if (pct >= 95 && !hasReadAll) {
        hasReadAll = true;
        unlockState(true);
      }

      revealSections();
    });

    function updateProgress(pct) {
      progressFill.style.width = pct + '%';
      pctLabel.textContent     = pct + '%';

      if (pct >= 95) {
        pctLabel.style.color = '#166534';
      } else {
        pctLabel.style.color = '#c0391e';
      }
    }

    function unlockState(unlocked) {
      if (unlocked) {
        acceptBtn.classList.remove('locked');
        lockIcon.style.display  = 'none';
        checkIcon.style.display = 'block';
        acceptLabel.textContent  = 'I Accept the Terms';
        scrollPrompt.classList.add('hidden');
      } else {
        acceptBtn.classList.add('locked');
        lockIcon.style.display  = 'block';
        checkIcon.style.display = 'none';
        acceptLabel.textContent  = 'Read All Terms First';
        scrollPrompt.classList.remove('hidden');
      }
    }

    function resetModal() {
      hasReadAll = false;
      body.scrollTop = 0;
      updateProgress(0);
      unlockState(false);
      document.querySelectorAll('.terms-section').forEach(function (s) {
        s.classList.remove('revealed');
      });
    }

    function revealSections() {
      var bodyRect = body.getBoundingClientRect();
      document.querySelectorAll('.terms-section').forEach(function (section, idx) {
        var rect = section.getBoundingClientRect();
        if (rect.top < bodyRect.bottom + 60) {
          setTimeout(function () {
            section.classList.add('revealed');
          }, idx * 45);
        }
      });
    }

    function showToast() {
      if (toastTimer) clearTimeout(toastTimer);
      toast.classList.add('show');
      toastTimer = setTimeout(function () {
        toast.classList.remove('show');
      }, 3000);
    }

    /* If PHP already set terms (page re-render after error), mark accepted */
    if ((termsCheckbox && termsCheckbox.checked) || (dtTermsCheckbox && dtTermsCheckbox.checked)) {
      hasAccepted = true;
      hasReadAll  = true;
    }

  })();

  /* ================================================
     GEOLOCATION — functionality from doc 1:
     active, non-blocking. Detects address and shows
     a soft warning (inline + toast) if outside
     San Ildefonso, Bulacan, but never disables submit.
     ================================================ */
  const allowedMunicipality = "San Ildefonso";
  const allowedProvince = "Bulacan";

  function showGeoToast(message) {
    var geoToast = document.getElementById('geoToast');
    if (!geoToast || !message) return;
    geoToast.textContent = message;
    geoToast.classList.add('show');
    setTimeout(function () {
      geoToast.classList.remove('show');
    }, 5000);
  }

  function detectLocation() {

    if (!navigator.geolocation) {
      return;
    }

    navigator.geolocation.getCurrentPosition(async function (position) {

      const lat = position.coords.latitude;
      const lon = position.coords.longitude;

      document.getElementById("lat").value = lat;
      document.getElementById("lng").value = lon;
      document.getElementById("dt-lat").value = lat;
      document.getElementById("dt-lng").value = lon;

      const url = `https://nominatim.openstreetmap.org/reverse?lat=${lat}&lon=${lon}&format=json`;

      const res = await fetch(url);
      const data = await res.json();

      const addr = data.address;

      let municipality = addr.town || addr.city || addr.municipality || "";
      let province = addr.state || "";

      const fullAddress = data.display_name;

      document.getElementById("address").value = fullAddress;
      document.getElementById("dt-address").value = fullAddress;

      const isOutside =
        !municipality.toLowerCase().includes("san ildefonso") ||
        !province.toLowerCase().includes("bulacan");

      const warningText = isOutside
        ? "Note: Your detected location appears to be outside San Ildefonso, Bulacan."
        : "";
      <p style={{ color: "red" }}>{warningText}</p>

      document.getElementById("locationWarning").textContent = warningText;
      document.getElementById("dt-locationWarning").textContent = warningText;

      if (isOutside) {
        showGeoToast(warningText);
      }

    }, function () {
      // silently ignore — location is optional
    });
  }

  detectLocation();

</script>
</body>
</html>