<?php
require_once __DIR__ . '/config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/../vendor/autoload.php';

// -----------------------------------------------------------------
// Palette / branding config
// -----------------------------------------------------------------
define('MAIL_COLOR_DARK_RED',   '#7a0c0c');
define('MAIL_COLOR_DARKER_RED', '#5c0909');
define('MAIL_COLOR_TEXT',       '#333333');
define('MAIL_COLOR_MUTED',      '#777777');
define('MAIL_COLOR_BG',         '#f4f4f4');
define('MAIL_COLOR_FOOTER_BG',  '#f7f7f7');

/**
 * Turns a raw "name" into something presentable
 */
function mdrrmo_display_name(string $toName): string
{
    $toName = trim($toName);
    if ($toName === '') {
        return 'there';
    }
    if (strpos($toName, '@') !== false) {
        $localPart = strstr($toName, '@', true) ?: $toName;
        $localPart = str_replace(['.', '_', '-'], ' ', $localPart);
        return ucwords($localPart);
    }
    return $toName;
}

/**
 * Builds the HTML body for the OTP email
 */
function build_otp_email_html(string $toName, string $otp, string $intro): string
{
    $safeName  = htmlspecialchars(mdrrmo_display_name($toName), ENT_QUOTES, 'UTF-8');
    $safeOtp   = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');
    $safeIntro = htmlspecialchars($intro, ENT_QUOTES, 'UTF-8');
    $year      = date('Y');

    $darkRed   = MAIL_COLOR_DARK_RED;
    $darkerRed = MAIL_COLOR_DARKER_RED;
    $textColor = MAIL_COLOR_TEXT;
    $muted     = MAIL_COLOR_MUTED;
    $bg        = MAIL_COLOR_BG;
    $footerBg  = MAIL_COLOR_FOOTER_BG;

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MDRRMO Verification Code</title>
</head>
<body style="margin:0; padding:0; background-color:{$bg}; font-family:Arial, Helvetica, sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:{$bg};">
<tr>
<td align="center" style="padding:32px 16px;">

  <table role="presentation" width="100%" style="max-width:560px; background-color:#ffffff; border-radius:8px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,0.08);" cellpadding="0" cellspacing="0">

    <!-- Header -->
    <tr>
      <td align="center" style="position:relative; background-color:{$darkRed}; background-image:linear-gradient(135deg, {$darkRed} 0%, {$darkerRed} 100%); padding:26px 24px 28px 24px;">

        <div style="position:relative; font-family:Arial, Helvetica, sans-serif; font-size:10px; font-weight:bold; letter-spacing:3px; color:#e8b4b4; text-transform:uppercase; margin-bottom:16px;">
          Republic of the Philippines
        </div>

        <table role="presentation" cellpadding="0" cellspacing="0" style="position:relative; margin:0 auto;">
          <tr>
            <td style="padding-right:10px;"><table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="width:28px; height:1px; background-color:rgba(255,255,255,0.35); font-size:0; line-height:0;">&nbsp;</td></tr></table></td>
            <td>
              <div style="font-family:Arial, Helvetica, sans-serif; font-size:24px; font-weight:bold; letter-spacing:2px; color:#ffffff; line-height:1; white-space:nowrap;">
                MDRRMO
              </div>
            </td>
            <td style="padding-left:10px;"><table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="width:28px; height:1px; background-color:rgba(255,255,255,0.35); font-size:0; line-height:0;">&nbsp;</td></tr></table></td>
          </tr>
        </table>

        <div style="position:relative; font-family:Arial, Helvetica, sans-serif; font-size:11px; letter-spacing:1px; color:#e8b4b4; margin-top:8px;">
          Municipal Disaster Risk Reduction &amp; Management Office
        </div>
        <div style="position:relative; font-family:Arial, Helvetica, sans-serif; font-size:11px; letter-spacing:1px; color:rgba(255,255,255,0.55); margin-top:2px;">
          in partnership with BASC &middot; ICS
        </div>

      </td>
    </tr>
    <tr><td style="height:4px; background-color:{$darkerRed}; font-size:0; line-height:0;">&nbsp;</td></tr>

    <!-- Body -->
    <tr>
      <td style="padding:36px 32px 8px 32px; text-align:center;">
        <h1 style="margin:0 0 12px 0; font-size:22px; line-height:1.35; color:{$textColor};">
          Hi {$safeName}, here's your verification code
        </h1>
        <p style="margin:0 0 24px 0; font-size:15px; color:{$muted}; line-height:1.5;">
          {$safeIntro}
        </p>
      </td>
    </tr>

    <!-- OTP Code Box -->
    <tr>
      <td align="center" style="padding:0 32px 8px 32px;">
        <table role="presentation" cellpadding="0" cellspacing="0">
          <tr>
            <td style="background-color:{$darkRed}; border-radius:8px; padding:16px 40px;">
              <span style="font-family:'Courier New', Courier, monospace; font-size:32px; letter-spacing:10px; color:#ffffff; font-weight:bold;">{$safeOtp}</span>
            </td>
          </tr>
        </table>
        <p style="margin:18px 0 0 0; font-size:13px; color:{$muted};">
          This code will expire in 5 minutes. Do not share it with anyone.
        </p>
      </td>
    </tr>

    <tr><td style="padding:28px 32px 32px 32px;" align="center">
      <table role="presentation" cellpadding="0" cellspacing="0">
        <tr>
          <td style="border:1px solid #e2b8b8; border-radius:6px; padding:12px 20px; background-color:#fdf5f5;">
            <span style="font-size:13px; color:{$textColor};">Didn't request this? You can safely ignore this email.</span>
          </td>
        </tr>
      </table>
    </td></tr>

    <tr><td style="border-top:1px solid #eeeeee; font-size:0; line-height:0;">&nbsp;</td></tr>

    <!-- Footer -->
    <tr>
      <td style="background-color:{$footerBg}; padding:28px 24px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
          <tr>
            <td align="center" style="font-size:12px; color:{$muted}; padding-top:6px; line-height:1.6;">
              Need help? Contact your MDRRMO administrator.<br>
              San Ildefonso, Bulacan
            </td>
          </tr>
          <tr>
            <td align="center" style="font-size:11px; color:#aaaaaa; padding-top:14px;">
              &copy; {$year} MDRRMO x BASC &middot; ICS. All rights reserved.
            </td>
          </tr>
        </table>
      </td>
    </tr>

  </table>
</td>
</tr>
</table>
</body>
</html>
HTML;
}

/**
 * Sends an OTP email
 */
function send_otp_email(
    string $toEmail,
    string $toName,
    string $otp,
    string $subject = 'Your MDRRMO verification code',
    string $intro = 'Your one-time verification code is'
): bool
{
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_SMTP_USERNAME;
        $mail->Password   = MAIL_SMTP_PASSWORD;
        $mail->SMTPSecure = MAIL_SMTP_SECURE;
        $mail->Port       = MAIL_SMTP_PORT;

        $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $toName);

        // Clear previous attachments
        $mail->clearAttachments();

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = build_otp_email_html($toName, $otp, $intro);
        $mail->AltBody = $intro . ': ' . $otp;

        $mail->CharSet  = 'UTF-8';
        $mail->Encoding = 'base64';

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log('PHPMailer error: ' . $mail->ErrorInfo);

        $logLine = sprintf("[%s] OTP for %s (%s): %s | Error: %s\n",
            date('Y-m-d H:i:s'), $toName, $toEmail, $otp, $mail->ErrorInfo);
        file_put_contents(__DIR__ . '/otp_test.log', $logLine, FILE_APPEND);

        return false;
    }
}