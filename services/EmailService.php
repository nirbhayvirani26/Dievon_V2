<?php
// ============================================================
//  Dievon – Central Reusable EmailService
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/EnvLoader.php';

// Safe PHPMailer inclusion
$_phpmailerReady = false;
if (file_exists(__DIR__ . '/../PHPMailer/src/PHPMailer.php')) {
    require_once __DIR__ . '/../PHPMailer/src/PHPMailer.php';
    require_once __DIR__ . '/../PHPMailer/src/SMTP.php';
    require_once __DIR__ . '/../PHPMailer/src/Exception.php';
    $_phpmailerReady = true;
} elseif (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    $_phpmailerReady = class_exists('PHPMailer\PHPMailer\PHPMailer');
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class EmailService {
    private $pdo;
    private $testMode;
    private $testRecipient;
    private $fromAddress;
    private $fromName;
    private $adminAddress;

    /**
     * The base URL to use for anything an email points at.
     *
     * SITE_URL is derived per request, and config.php treats CLI as "local" —
     * so an email sent from a cron job ON THE LIVE SERVER resolves SITE_URL to
     * http://localhost:8888/DievonOrders. Every image and link in that email
     * then points at the recipient's own machine, which is exactly why a logo
     * "does not show": the address is real, it just is not the shop's.
     *
     * Set SITE_PUBLIC_URL in .env (e.g. https://dievon.com) and every email uses
     * it regardless of how the send was triggered. Without it this falls back to
     * SITE_URL, which is correct for anything sent during a normal page request.
     */
    public static function publicBaseUrl(): string {
        $configured = trim((string)EnvLoader::get('SITE_PUBLIC_URL', ''));
        if ($configured !== '') { return rtrim($configured, '/'); }
        return rtrim((string)SITE_URL, '/');
    }

    /** Rewrite a site URL onto the public base, so cron-sent mail is not left pointing at localhost. */
    public static function toPublicUrl(string $url): string {
        $url = trim($url);
        if ($url === '') { return ''; }
        $base = self::publicBaseUrl();
        $site = rtrim((string)SITE_URL, '/');
        if ($site !== '' && $base !== $site && str_starts_with($url, $site)) {
            return $base . substr($url, strlen($site));
        }
        return $url;
    }

    public function __construct(?PDO $pdo = null) {
        global $pdo;
        $this->pdo = $pdo;

        $this->testMode = (bool)EnvLoader::get('MAIL_TEST_MODE', true);
        $this->testRecipient = trim((string)EnvLoader::get('MAIL_TEST_RECIPIENT', EnvLoader::get('MAIL_ADMIN_ADDRESS', ADMIN_EMAIL)));
        $this->fromAddress = trim((string)EnvLoader::get('MAIL_FROM_ADDRESS', ADMIN_EMAIL));
        $this->fromName = trim((string)EnvLoader::get('MAIL_FROM_NAME', 'Dievon'));
        $this->adminAddress = trim((string)EnvLoader::get('MAIL_ADMIN_ADDRESS', ADMIN_EMAIL));
    }

    /**
     * Core PHPMailer Factory & Dispatch Engine
     */
    private function sendMail(string $toEmail, string $toName, string $subject, string $htmlContent, string $emailType): bool {
        /* Ask the class loader, not a global set at the top of this file.
           ────────────────────────────────────────────────────────────────────
           $_phpmailerReady is assigned at file scope, and require_once runs in
           the SCOPE OF THE CALLER — so a file included from inside a function
           makes that assignment function-local and leaves the global unset.
           This read then saw null and refused to send with "PHPMailer library
           not found" while PHPMailer sat loaded and working.

           require_once compounds it: only the first include of the request runs
           those lines at all, so whether mail worked depended on which call
           site happened to come first.

           All twelve current includes are at top level, so this cannot fire
           today — I checked each with the tokeniser rather than by eye, because
           indentation does not decide it; PHP scope is function-level, and a
           require nested ten blocks deep is still global if no function
           encloses it. The point of asking class_exists() is that it stays
           true whoever includes this file and from where. */
        if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            $this->logEmail($emailType, $toEmail, $subject, 'failed', 'PHPMailer library not found');
            return false;
        }

        // The SMTP server follows CONTACT_EMAIL, so flipping that one constant
        // also points us at the right mail host. A Gmail address has to
        // authenticate against Gmail; an @dievon.com address against Hostinger.
        // Mismatching that pair is the usual reason email "just stops working"
        // with no visible error. .env still overrides all of it per environment.
        $isGmail = str_ends_with(strtolower(CONTACT_EMAIL), '@gmail.com');

        $host = (string)EnvLoader::get('MAIL_HOST', $isGmail ? 'smtp.gmail.com' : 'smtp.hostinger.com');
        $port = (int)EnvLoader::get('MAIL_PORT', 465);
        $user = (string)EnvLoader::get('MAIL_USERNAME', CONTACT_EMAIL);
        $pass = (string)EnvLoader::get('MAIL_PASSWORD', '');
        $enc  = (string)EnvLoader::get('MAIL_ENCRYPTION', 'ssl');

        // Test mode redirect
        $targetEmail = $toEmail;
        $targetName  = $toName;
        $testHeaderNotice = '';

        if ($this->testMode && !empty($this->testRecipient)) {
            $targetEmail = $this->testRecipient;
            $targetName = "TEST MODE (" . $toName . ")";
            $testHeaderNotice = "
            <div style='background:#fff3cd; color:#856404; padding:10px 16px; font-size:12px; border-bottom:1px solid #ffeeba; text-align:center;'>
                🧪 <strong>TEST MODE ENABLED</strong> — Original Recipient: <code>" . htmlspecialchars($toEmail) . "</code>
            </div>";
        }

        $mail = new PHPMailer(true);

        try {
            $mail->CharSet = 'UTF-8';
            $mail->SMTPDebug = 0;

            // PHPMailer defaults to a 300 SECOND timeout, and this was never set.
            // A slow or unreachable mail server therefore held the PHP request —
            // and its Apache worker — for five minutes. Measured locally: four
            // password-reset requests exhausted the worker pool and took the whole
            // site down until they expired.
            //
            // 15s is generous for a working SMTP handshake and turns an outage
            // into one slow page. logEmail() still records the failure, so a
            // genuinely broken mail server is visible rather than silent.
            $mail->Timeout = 15;
            $mail->SMTPKeepAlive = false;

            $mail->isSMTP();
            $mail->Host       = $host;
            $mail->SMTPAuth   = true;
            $mail->Username   = $user;
            $mail->Password   = $pass;

            // The PORT decides the handshake, not the label in .env.
            //
            // Port 465 is implicit TLS: the server expects a TLS handshake the
            // moment the socket opens. Port 587 is STARTTLS: plaintext first,
            // upgraded by a STARTTLS command.
            //
            // A .env pairing 465 with ENCRYPTION=tls made PHPMailer open plaintext
            // on an implicit-TLS port and wait for a greeting that never came —
            // the connection just hung. Measured: every send blocked until timeout,
            // and with PHPMailer's 300s default that exhausted the worker pool and
            // took the whole site down.
            //
            // Trusting the port makes that pairing harmless instead of fatal.
            $encLower = strtolower(trim($enc));
            if ($port === 465) {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($port === 587) {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($encLower === 'ssl' || $encLower === 'smtps') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }

            $mail->Port = $port;

            $mail->setFrom($this->fromAddress ?: $user, $this->fromName);
            $mail->addAddress($targetEmail, $targetName);
            $mail->isHTML(true);

            $mail->Subject = $this->testMode ? "[TEST] " . $subject : $subject;
            $mail->Body    = $testHeaderNotice . $htmlContent;

            $mail->send();
            $this->logEmail($emailType, $toEmail, $subject, 'sent', null);
            return true;

        } catch (Exception $e) {
            $errMsg = $mail->ErrorInfo ?: $e->getMessage();
            error_log("EmailService Error [{$emailType} to {$toEmail}]: " . $errMsg);
            $this->logEmail($emailType, $toEmail, $subject, 'failed', $errMsg);
            return false;
        } catch (\Throwable $t) {
            error_log("EmailService Throwable [{$emailType} to {$toEmail}]: " . $t->getMessage());
            $this->logEmail($emailType, $toEmail, $subject, 'failed', $t->getMessage());
            return false;
        }
    }

    /**
     * Log email dispatch attempt to email_logs table
     *
     * The subject is REDACTED of one-time codes before it is stored. The sign-in
     * and password-reset emails put the live 6-digit code in the subject line
     * itself ("Your Dievon admin password reset code: 839066"), and this row is
     * kept for the life of the shop. That turned the audit log into a live
     * credential store: anyone able to read email_logs — through admin/email_logs.php
     * (settings.manage, which a non-owner role can hold) or through a database
     * backup — could read a reset code straight off the screen. Chained with the
     * unauthenticated forgot-password page, which will issue a code for any
     * account including the owner, that was a complete account takeover, and it
     * defeated emailed 2FA the same way, because sign-in codes were logged too.
     *
     * The email that goes to the customer is untouched — it still carries the real
     * code, because that is the whole point of it. Only the stored copy is masked.
     * The pattern keys on the literal word "code"/"OTP" before the digits, so order
     * numbers, amounts and ticket references (which are not preceded by that word)
     * are left readable.
     */
    private function logEmail(string $type, string $recipient, string $subject, string $status, ?string $error): void {
        try {
            if (!$this->pdo) return;

            /* Keyed on the email TYPE first, and only then on the wording.
               ────────────────────────────────────────────────────────────────
               Matching the word "code" immediately before the digits works for
               today's two subjects ("…reset code: 839066") but breaks the moment
               anyone rephrases: "Your sign-in code is 123456" slips straight
               through the pattern, because "is" sits between the word and the
               number. A credential that survives a copy-edit is not contained.

               These four types exist ONLY to carry a one-time secret, so for them
               every 4-8 digit run in the subject goes, whatever the sentence looks
               like. The word-based pass still runs for everything else, so order
               numbers, amounts and ticket references stay readable. */
            $secretTypes = ['admin_login_code', 'admin_password_reset', 'password_reset', 'email_verification'];
            $safeSubject = in_array($type, $secretTypes, true)
                ? preg_replace('/\d{4,8}/', '••••••', $subject)
                : preg_replace('/\b(code|otp)\b([:\s#]*)\d{4,8}/i', '$1$2••••••', $subject);
            $stmt = $this->pdo->prepare("INSERT INTO email_logs (email_type, recipient, subject, status, error_message, test_mode, created_at) VALUES (:type, :rec, :subj, :status, :err, :test, NOW())");
            $stmt->execute([
                'type'   => $type,
                'rec'    => $recipient,
                'subj'   => $safeSubject,
                'status' => $status,
                'err'    => $error,
                'test'   => $this->testMode ? 1 : 0
            ]);
        } catch (\PDOException $e) {
            error_log("Failed to insert email_log: " . $e->getMessage());
        }
    }

    /**
     * Dievon Luxury HTML Master Template Wrapper
     */
    private function wrapLuxuryTemplate(string $title, string $subtitle, string $bodyContent, string $footerText = ''): string {
        $siteUrl = defined('SITE_URL') ? SITE_URL : 'http://localhost:8888/DievonOrders';
        $year = date('Y');
        $brandName = defined('SHOP_NAME') ? SHOP_NAME : 'Dievon';

        // Brand mark in the email header: the uploaded logo, with the shop name as
        // the alt text.
        //
        // The alt text is not decoration. Gmail, Outlook and Apple Mail block
        // remote images by default until the reader trusts the sender, so for a
        // large share of recipients the <img> never loads — and alt text is the
        // only thing left in the header. Styled to look deliberate rather than
        // like a broken image: same gold, same letter-spacing as the old text logo.
        //
        // Falls back to plain text when no logo has been uploaded, so the header
        // is never an empty box.
        $logoUrl = '';
        try {
            if (function_exists('siteLogoUrl')) {
                // Put through toPublicUrl() so a cron-triggered send does not embed
                // a localhost address the recipient's mail client cannot reach.
                $logoUrl = self::toPublicUrl((string)siteLogoUrl($this->pdo instanceof PDO ? $this->pdo : null));
            }

            /* A copy of the mark with white behind it, for the header only.
               ────────────────────────────────────────────────────────────────
               logo.PNG is RGBA and 84% of it is see-through — the burgundy
               strokes and nothing else. On a white header that is exactly right.
               In Gmail's dark mode, which repaints backgrounds regardless of the
               color-scheme meta, the bgcolor and the media query below, the dark
               grey comes through those transparent pixels and the mark is dark
               burgundy on dark grey. Nearly invisible, and the first thing in
               the message.
               A flattened copy carries its own white and cannot be seen through,
               so the mark stays legible whatever the client paints around it.
               Only swapped in when the file is actually there; any shop that has
               not generated one keeps the logo it had. */
            if ($logoUrl !== '') {
                $emailLogo = dirname(__DIR__) . '/assets/images/logo/logo-email.png';
                if (is_file($emailLogo)) {
                    $logoUrl = self::toPublicUrl(
                        (defined('SITE_URL') ? SITE_URL : '') . '/assets/images/logo/logo-email.png?v=' . filemtime($emailLogo)
                    );
                }
            }
        } catch (\Throwable $e) { /* fall through to the text mark */ }

        // The alt-text colour is burgundy, not gold, because the header behind it is
        // now light. See the note on .brand-header below for why it changed.
        //
        // The header styles are written BOTH as a class and inline on the <td>.
        // Gmail strips the <style> block wholesale, so anything that must survive
        // has to be an attribute — which is why the old burgundy band appeared in
        // Apple Mail (hiding the burgundy logo) but not in Gmail. Stating it inline
        // makes every client agree on the same header.
        if ($logoUrl !== '') {
            $logoBlock = "<img src='" . htmlspecialchars($logoUrl, ENT_QUOTES) . "'"
                       . " alt='" . htmlspecialchars($brandName, ENT_QUOTES) . "'"
                       . " width='170' class='brand-logo-img'"
                       . " style='display:inline-block; max-width:170px; height:auto; border:0;"
                       . " font-family:Georgia,serif; font-size:26px; font-weight:300;"
                       . " letter-spacing:4px; text-transform:uppercase; color:#511126;'>";
        } else {
            $logoBlock = "<h1 class='brand-logo' style='font-size:26px; font-weight:300; letter-spacing:4px;"
                       . " text-transform:uppercase; color:#511126; margin:0; font-family:Georgia,serif;'>"
                       . htmlspecialchars($brandName) . "</h1>";
        }

        return "
<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    " . "<!-- Dark mode: this email declares its own colours and asks not to be repainted.
         ─────────────────────────────────────────────────────────────────────
         The header was already white — twice, as a class and as a bgcolor — and
         it still looked wrong, because a phone in dark mode does not read the
         white; it INVERTS it. The band came back near-black and the logo, which
         is transparent artwork in dark burgundy, disappeared into it. Painting
         it white a third time could never have helped.

         These two meta tags plus color-scheme below tell Apple Mail and
         Outlook.com that light is deliberate, and they leave it alone. Gmail
         ignores them, which is what the dark-mode media query and the [data-ogsc]
         rules further down are for. --> " . "
    <meta name='color-scheme' content='light'>
    <meta name='supported-color-schemes' content='light'>
    <title>" . htmlspecialchars($title) . "</title>
    <style>
        :root { color-scheme: light; supported-color-schemes: light; }
        body { margin: 0; padding: 0; background-color: #FAF8F5; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #2B2627; -webkit-font-smoothing: antialiased; }
        .wrapper { width: 100%; table-layout: fixed; background-color: #FAF8F5; padding: 36px 0; }
        .main-card { max-width: 600px; margin: 0 auto; background: #FFFFFF; overflow: hidden; border: 1px solid #EAE4DC; box-shadow: 0 8px 30px rgba(81,17,38,0.06); }
        /* A LIGHT header, not the burgundy band this used to be.
           The uploaded logo is dark burgundy artwork — the same #511126 the band
           was filled with — so the mark was burgundy on burgundy and effectively
           invisible in any client that applies this stylesheet. The site's own
           header is light for exactly this reason, so the email now matches it.
           The gold hairline keeps the band from reading as an empty white strip. */
        .brand-header { background: #FFFFFF; padding: 32px; text-align: center; color: #511126; border-bottom: 2px solid #D4AF37; }
        .brand-logo { font-size: 26px; font-weight: 300; letter-spacing: 4px; text-transform: uppercase; color: #511126; margin: 0; font-family: Georgia, serif; }
        /* Also declared inline on the <img> itself — Gmail strips <style> blocks,
           so anything that must survive has to be an inline attribute. */
        .brand-logo-img { display: inline-block; max-width: 170px; height: auto; border: 0; }
        .brand-subtitle { font-size: 11px; text-transform: uppercase; letter-spacing: 2px; color: #7B7275; margin-top: 8px; }
        .content-area { padding: 32px; font-size: 14px; line-height: 1.7; color: #3A3537; }
        .btn-luxury { display: inline-block; background: #511126; color: #FFFFFF !important; font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 1.5px; padding: 14px 28px; text-decoration: none; margin: 18px 0; }
        .footer { background: #FAF8F5; padding: 24px 32px; border-top: 1px solid #EAE4DC; text-align: center; font-size: 11px; color: #7B7275; line-height: 1.6; }
        .footer a { color: #511126; text-decoration: none; font-weight: 600; }
        table.item-table { width: 100%; border-collapse: collapse; margin: 18px 0; }
        table.item-table th { background: #FAF8F5; color: #511126; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; padding: 10px 12px; text-align: left; border-bottom: 1px solid #EAE4DC; }
        table.item-table td { padding: 12px; border-bottom: 1px solid #F3EEE7; font-size: 13px; }

        /* Hold the light palette when the phone is in dark mode.
           ────────────────────────────────────────────────────────────────────
           The meta tags above are enough for Apple Mail and Outlook.com. Gmail
           ignores them and repaints anyway, so the values it would change are
           stated again here — !important, because Gmail's own injected styles
           are what we are overriding.

           Only the surfaces the logo and the text sit on. The point is not to
           fight dark mode everywhere; it is that a transparent burgundy logo
           needs the band behind it to stay white, or it vanishes. */
        @media (prefers-color-scheme: dark) {
            body, .wrapper           { background-color: #FAF8F5 !important; }
            .main-card               { background: #FFFFFF !important; }
            .brand-header            { background: #FFFFFF !important; }
            .brand-logo, .brand-header, .brand-header * { color: #511126 !important; }
            .footer, .footer a       { color: #511126 !important; }
            table.item-table th      { background: #FAF8F5 !important; color: #511126 !important; }
            table.item-table td      { color: #2B2627 !important; }
        }

        /* Outlook.com rewrites the message and prefixes everything with these
           attributes rather than honouring the media query. Same values again —
           there is no shared syntax that covers both. */
        [data-ogsc] .brand-header,
        [data-ogsb] .brand-header    { background: #FFFFFF !important; }
        [data-ogsc] .brand-logo,
        [data-ogsc] .brand-header *  { color: #511126 !important; }
        [data-ogsc] .main-card       { background: #FFFFFF !important; }
    </style>
</head>
<body>
<div class='wrapper' style='background-color:#FAF8F5;'>
    <table class='main-card' width='100%' cellpadding='0' cellspacing='0' bgcolor='#FFFFFF' style='background-color:#FFFFFF;'>
        <tr>
            <td class='brand-header' align='center' bgcolor='#FFFFFF'
                style='background:#FFFFFF; padding:32px; text-align:center; color:#511126; border-bottom:2px solid #D4AF37;'>
                {$logoBlock}
                <div class='brand-subtitle' style='font-size:11px; text-transform:uppercase; letter-spacing:2px; color:#7B7275; margin-top:8px;'>"
                . htmlspecialchars($subtitle) . "</div>
            </td>
        </tr>
        <tr>
            <td class='content-area' bgcolor='#FFFFFF' style='background-color:#FFFFFF;'>
                {$bodyContent}
            </td>
        </tr>
        <tr>
            <td class='footer' bgcolor='#FAF8F5' style='background-color:#FAF8F5;'>
                " . ($footerText ? htmlspecialchars($footerText) . "<br>" : "") . "
                &copy; {$year} Dievon &bull; " . htmlspecialchars(SHOP_TAGLINE) . "<br>
                Need assistance? Contact our concierge at <a href='mailto:" . CONTACT_EMAIL . "'>" . CONTACT_EMAIL . "</a>
            </td>
        </tr>
    </table>
</div>
</body>
</html>";
    }

    // ============================================================
    //  1. CUSTOMER REGISTRATION WELCOME EMAIL
    // ============================================================
    public function sendWelcomeEmail(string $email, string $name): bool {
        $safeName = htmlspecialchars($name);
        $safeEmail = htmlspecialchars($email);

        $body = "
            <h2 style='font-family: Georgia, serif; font-size: 20px; font-weight: 400; color: #511126; margin-top: 0;'>Welcome to Dievon</h2>
            <p>Dear <strong>{$safeName}</strong>,</p>
            <p>Thank you for creating an account with Dievon. We are delighted to welcome you to our private luxury circle.</p>
            <p>As a registered member, you will enjoy express checkout, personal order tracking, and exclusive early access to new seasonal collections and bespoke fittings.</p>
            <div style='text-align: center; margin: 24px 0;'>
                <a href='" . SITE_URL . "/pages/login.php' class='btn-luxury'>Sign In to Your Profile &rarr;</a>
            </div>
            <p>If you have any questions regarding your account or need styling guidance, our concierge team is always at your service.</p>
        ";

        $html = $this->wrapLuxuryTemplate("Welcome to Dievon", "Private Member Account", $body);
        return $this->sendMail($email, $name, "Welcome to Dievon", $html, 'welcome');
    }

    // ============================================================
    //  1b. EMAIL VERIFICATION (guest-order linking gate)
    // ============================================================
    // Sent at registration. Until this link is clicked the account is
    // unverified and NO guest order placed with the address is linked to
    // it — registering with somebody else's email used to hand over that
    // person's entire order history with no proof the address was theirs.
    public function sendEmailVerificationEmail(string $email, string $name, string $verifyUrl): bool
    {
        $safeName = htmlspecialchars($name);
        $safeUrl  = htmlspecialchars($verifyUrl);

        $body = "
            <h2 style='font-family: Georgia, serif; font-size: 20px; font-weight: 400; color: #511126; margin-top: 0;'>Verify Your Email Address</h2>
            <p>Dear <strong>{$safeName}</strong>,</p>
            <p>Thank you for creating a Dievon account. Please confirm this email address so we can link any guest orders placed with it to your new account.</p>
            <p>This secure link is single-use and will expire in <strong>24 hours</strong>.</p>
            <div style='text-align: center; margin: 26px 0;'>
                <a href='{$safeUrl}' class='btn-luxury'>Verify My Email &rarr;</a>
            </div>
            <p style='font-size: 12px; color: #7A7275;'>If the button above does not work, copy and paste the following link into your browser:<br>
            <a href='{$safeUrl}' style='color: #511126; word-break: break-all;'>{$safeUrl}</a></p>
            <div style='background: #FFF8EE; border: 1px solid #F5E5D0; padding: 12px 16px; font-size: 12px; color: #8A6D3B; margin-top: 20px;'>
                🔒 <strong>Security Note:</strong> If you did not create this account, please ignore this email. Your address cannot be linked to any orders unless you click this link.
            </div>
        ";

        $html = $this->wrapLuxuryTemplate("Verify Your Email", "Account Security", $body);
        return $this->sendMail($email, $name, "Verify your Dievon email address", $html, 'email_verification');
    }

    // ============================================================
    //  2. FORGOT PASSWORD RESET LINK EMAIL
    // ============================================================
    public function sendPasswordResetEmail(string $email, string $name, string $resetUrl): bool {
        $safeName = htmlspecialchars($name);
        $safeUrl = htmlspecialchars($resetUrl);

        $body = "
            <h2 style='font-family: Georgia, serif; font-size: 20px; font-weight: 400; color: #511126; margin-top: 0;'>Password Reset Request</h2>
            <p>Dear <strong>{$safeName}</strong>,</p>
            <p>We received a request to reset the password for your Dievon account (<code>" . htmlspecialchars($email) . "</code>).</p>
            <p>Click the button below to set a new password. This secure link is single-use and will expire in <strong>1 hour</strong>.</p>
            <div style='text-align: center; margin: 26px 0;'>
                <a href='{$safeUrl}' class='btn-luxury'>Reset Your Password &rarr;</a>
            </div>
            <p style='font-size: 12px; color: #7A7275;'>If the button above does not work, copy and paste the following link into your browser:<br>
            <a href='{$safeUrl}' style='color: #511126; word-break: break-all;'>{$safeUrl}</a></p>
            <div style='background: #FFF8EE; border: 1px solid #F5E5D0; padding: 12px 16px; font-size: 12px; color: #8A6D3B; margin-top: 20px;'>
                🔒 <strong>Security Note:</strong> If you did not request a password reset, please ignore this email. Your current password remains completely safe and unchanged.
            </div>
        ";

        $html = $this->wrapLuxuryTemplate("Reset Password", "Security & Protection", $body);
        return $this->sendMail($email, $name, "Reset Your Dievon Account Password", $html, 'password_reset');
    }

    // ============================================================
    //  2b. ADMIN SIGN-IN CODE (two-step by email)
    // ============================================================
    public function sendAdminLoginCodeEmail(string $email, string $name, string $code, int $minutes = 10): bool {
        $safeName = htmlspecialchars($name);
        $safeCode = htmlspecialchars($code);

        // The code is spaced out and set large because it is read off a screen and
        // typed into another one, often from a phone. No link anywhere in this
        // email on purpose: a sign-in code that arrives next to a clickable button
        // teaches staff that codes and links belong together, which is the exact
        // habit a phishing mail relies on.
        $body = "
            <h2 style='font-family: Georgia, serif; font-size: 20px; font-weight: 400; color: #511126; margin-top: 0;'>Your sign-in code</h2>
            <p>Hello <strong>{$safeName}</strong>,</p>
            <p>Use this code to finish signing in to the Dievon admin panel:</p>
            <div style='text-align: center; margin: 26px 0;'>
                <div style='display:inline-block; font-family: Menlo, Consolas, monospace; font-size: 34px; font-weight: 700;
                            letter-spacing: 10px; color: #511126; background: #FAF8F5; border: 1px solid #EAE4DC;
                             padding: 16px 26px 16px 36px;'>{$safeCode}</div>
            </div>
            <p style='font-size: 13px; color: #7A7275;'>It expires in <strong>{$minutes} minutes</strong> and can be used once.</p>
            <div style='background: #FFF8EE; border: 1px solid #F5E5D0; padding: 12px 16px; font-size: 12px; color: #8A6D3B; margin-top: 20px;'>
                🔒 <strong>Did not try to sign in?</strong> Someone may know this account's password.
                Ignore this email — the code alone is useless without it — then change that password as soon as you can.
            </div>
        ";

        $html = $this->wrapLuxuryTemplate('Sign-in code', 'Admin Security', $body);
        return $this->sendMail($email, $name, "Your Dievon sign-in code: {$code}", $html, 'admin_login_code');
    }

    public function sendAdminPasswordResetCodeEmail(string $email, string $name, string $code, int $minutes = 10): bool {
        $safeName = htmlspecialchars($name);
        $safeCode = htmlspecialchars($code);

        // Same shape as the sign-in code: large, spaced, no link. A reset code
        // arriving next to a clickable button teaches the exact habit a phishing
        // mail relies on — the reset page is typed, not clicked to.
        $body = "
            <h2 style='font-family: Georgia, serif; font-size: 20px; font-weight: 400; color: #511126; margin-top: 0;'>Your password reset code</h2>
            <p>Hello <strong>{$safeName}</strong>,</p>
            <p>Someone asked to reset the password for your Dievon admin account. If that was you, use this code on the reset page:</p>
            <div style='text-align: center; margin: 26px 0;'>
                <div style='display:inline-block; font-family: Menlo, Consolas, monospace; font-size: 34px; font-weight: 700;
                            letter-spacing: 10px; color: #511126; background: #FAF8F5; border: 1px solid #EAE4DC;
                             padding: 16px 26px 16px 36px;'>{$safeCode}</div>
            </div>
            <p style='font-size: 13px; color: #7A7275;'>It expires in <strong>{$minutes} minutes</strong> and can be used once.</p>
            <div style='background: #FFF8EE; border: 1px solid #F5E5D0; padding: 12px 16px; font-size: 12px; color: #8A6D3B; margin-top: 20px;'>
                🔒 <strong>Did not ask to reset your password?</strong> Someone may be trying to get into your account.
                Ignore this email — the code alone is not enough — and sign in to change your password if you can.
            </div>
        ";

        $html = $this->wrapLuxuryTemplate('Password reset code', 'Admin Security', $body);
        return $this->sendMail($email, $name, "Your Dievon admin password reset code: {$code}", $html, 'admin_password_reset');
    }

    // ============================================================
    //  3. PASSWORD CHANGED CONFIRMATION EMAIL
    // ============================================================
    public function sendPasswordChangedEmail(string $email, string $name): bool {
        $safeName = htmlspecialchars($name);

        $body = "
            <h2 style='font-family: Georgia, serif; font-size: 20px; font-weight: 400; color: #511126; margin-top: 0;'>Password Updated</h2>
            <p>Dear <strong>{$safeName}</strong>,</p>
            <p>This is a security confirmation to let you know that your password for your Dievon account has been successfully updated.</p>
            <div style='background: #F4FAF6; border: 1px solid #D1EAD8; padding: 14px 18px; font-size: 13px; color: #1E6B37; margin: 18px 0;'>
                ✅ <strong>Security Alert:</strong> Your new password is now active.
            </div>
            <p>If you made this change, no further action is needed.</p>
            <p style='color: #A94442; font-weight: 600;'>⚠️ If you did NOT change your password, please contact our security team immediately at <a href='mailto:" . CONTACT_EMAIL . "' style='color: #A94442;'>" . CONTACT_EMAIL . "</a>.</p>
        ";

        $html = $this->wrapLuxuryTemplate("Password Changed", "Account Security Confirmation", $body);
        return $this->sendMail($email, $name, "Security Alert: Your Dievon Password Was Updated", $html, 'password_changed');
    }

    /**
     * Re-reach a customer whose earlier message failed to send.
     *
     * Used by admin/email_logs.php. It exists as its own narrow method rather
     * than by making sendMail() public: sendMail takes an arbitrary HTML body,
     * and a public method that mails anything to anyone is a spam relay one
     * loose endpoint away. This can only ever send this one wording.
     *
     * The original body is NOT stored — email_logs keeps the envelope only, not
     * the message — so this cannot reproduce the first email. It says plainly
     * that something failed and invites a reply, which is honest and useful;
     * inventing a replacement order confirmation would not be.
     */
    public function sendFailedDeliveryFollowUp(string $email, string $originalSubject): bool {
        $safeSubject = htmlspecialchars($originalSubject);

        $body = "
            <h2 style='font-family: Georgia, serif; font-size: 20px; font-weight: 400; color: #511126; margin-top: 0;'>We could not reach you earlier</h2>
            <p>Hello,</p>
            <p>We tried to send you a message and it did not arrive. It was about:</p>
            <div style='background: #FAF7F2; border: 1px solid #E8DCC8; padding: 14px 18px; font-size: 14px; color: #511126; margin: 18px 0;'>
                <strong>{$safeSubject}</strong>
            </div>
            <p>If this concerned an order, simply reply to this email and we will help you straight away.</p>
            <p>With apologies for the delay,<br>" . htmlspecialchars(SHOP_NAME) . "</p>
        ";

        $html = $this->wrapLuxuryTemplate('A message did not reach you', 'Resending from ' . SHOP_NAME, $body);
        return $this->sendMail($email, '', 'Following up: ' . $originalSubject, $html, 'resend');
    }

    // ============================================================
    //  4A. ORDER PLACED — CUSTOMER CONFIRMATION EMAIL
    // ============================================================
    public function sendOrderPlacedCustomerEmail(array $order): bool {
        $email = trim($order['customer_email'] ?? '');
        if (empty($email)) return false;

        $safeName = htmlspecialchars($order['customer_name'] ?? 'Valued Customer');
        $code = htmlspecialchars($order['order_code'] ?? '');
        $date = date('d M Y, H:i', strtotime($order['created_at'] ?? 'now'));
        $totalFmt = number_format((float)($order['total_price'] ?? 0), 2);
        $sym = htmlspecialchars($order['currency_symbol'] ?? '₹');
        $status = htmlspecialchars($order['status'] ?? 'Pending');
        $payMethod = htmlspecialchars(ucfirst($order['payment_method'] ?? 'Later'));
        $payStatus = htmlspecialchars(ucfirst($order['payment_status'] ?? 'Unpaid'));

        $items = json_decode($order['items_json'] ?? '[]', true) ?? [];
        $itemRows = '';
        foreach ($items as $it) {
            $name = htmlspecialchars($it['name'] ?? 'Product');
            $variant = !empty($it['variant_name']) ? "<br><span style='font-size:11px;color:#7A7275;'>Size: " . htmlspecialchars($it['variant_name']) . "</span>" : '';
            $qty = (int)($it['quantity'] ?? 1);
            $priceFmt = number_format((float)($it['price'] ?? 0), 2);
            $lineTotal = number_format($qty * (float)($it['price'] ?? 0), 2);

            $itemRows .= "
            <tr>
                <td><strong>{$name}</strong>{$variant}</td>
                <td style='text-align:center;'>{$qty}</td>
                <td style='text-align:right;'>{$sym}{$priceFmt}</td>
                <td style='text-align:right;'><strong>{$sym}{$lineTotal}</strong></td>
            </tr>";
        }

        $address = nl2br(htmlspecialchars($order['address'] ?? ''));

        $body = "
            <h2 style='font-family: Georgia, serif; font-size: 20px; font-weight: 400; color: #511126; margin-top: 0;'>Order Confirmation</h2>
            <p>Dear <strong>{$safeName}</strong>,</p>
            <p>Thank you for your order with Dievon. We have received your purchase and our atelier team is currently preparing your order with care.</p>

            <div style='background: #FAF8F5; border: 1px solid #EAE4DC; padding: 16px; margin: 18px 0;'>
                <table width='100%' cellpadding='0' cellspacing='0'>
                    <tr>
                        <td><strong>Order Reference:</strong> <span style='color:#511126; font-weight:700;'>{$code}</span></td>
                        <td style='text-align:right;'><strong>Date:</strong> {$date}</td>
                    </tr>
                    <tr>
                        <td style='padding-top:8px;'><strong>Status:</strong> {$status}</td>
                        <td style='padding-top:8px; text-align:right;'><strong>Payment:</strong> {$payMethod} ({$payStatus})</td>
                    </tr>
                </table>
            </div>

            <table class='item-table'>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th style='text-align:center;'>Qty</th>
                        <th style='text-align:right;'>Price</th>
                        <th style='text-align:right;'>Total</th>
                    </tr>
                </thead>
                <tbody>
                    {$itemRows}
                </tbody>
            </table>

            <div style='text-align: right; font-size: 16px; font-weight: 700; color: #511126; margin-top: 12px;'>
                Order Total: {$sym}{$totalFmt}
            </div>

            <div style='margin-top: 24px; padding-top: 16px; border-top: 1px solid #EAE4DC;'>
                <strong style='color: #511126;'>📍 Delivery Address:</strong><br>
                <div style='margin-top: 4px; color: #555;'>{$address}</div>
            </div>

            <div style='margin-top: 26px; text-align: center;'>
                <a href='" . SITE_URL . "/print_invoice.php?code=" . urlencode((string)($order['order_code'] ?? '')) . "' class='btn-luxury'>View &amp; Print Your Invoice &rarr;</a>
            </div>
            <p style='font-size: 12px; color: #757575; text-align: center; margin-top: 10px;'>
                Opens your invoice on the site, where it can be saved as a PDF. You will be asked to sign in first, so
                the document is only ever shown to you.
            </p>
        ";

        // Linked rather than attached, deliberately.
        //
        // Building a PDF here would need a rendering library this project does
        // not have, and a generated attachment is a snapshot: the moment an
        // address is corrected or a refund is issued, the customer is holding a
        // document that disagrees with the order. The link always renders the
        // current state — and it is behind the same sign-in check as the account
        // page, so an invoice cannot leak by being forwarded.
        $html = $this->wrapLuxuryTemplate("Order Confirmation #{$code}", "Order Confirmation", $body);
        return $this->sendMail($email, $safeName, "Your Dievon Order Confirmation [{$code}]", $html, 'order_confirmation');
    }

    // ============================================================
    //  4B. ORDER PLACED — ADMIN NOTIFICATION EMAIL
    // ============================================================
    public function sendOrderPlacedAdminEmail(array $order): bool {
        $adminEmail = $this->adminAddress;
        if (empty($adminEmail)) return false;

        $safeName = htmlspecialchars($order['customer_name'] ?? 'Customer');
        $custEmail = htmlspecialchars($order['customer_email'] ?? 'N/A');
        $phone = htmlspecialchars($order['phone'] ?? 'N/A');
        $code = htmlspecialchars($order['order_code'] ?? '');
        $date = date('d M Y, H:i', strtotime($order['created_at'] ?? 'now'));
        $totalFmt = number_format((float)($order['total_price'] ?? 0), 2);
        $sym = htmlspecialchars($order['currency_symbol'] ?? '₹');
        $status = htmlspecialchars($order['status'] ?? 'Pending');
        $payMethod = htmlspecialchars(ucfirst($order['payment_method'] ?? 'Later'));
        $payStatus = htmlspecialchars(ucfirst($order['payment_status'] ?? 'Unpaid'));

        $items = json_decode($order['items_json'] ?? '[]', true) ?? [];
        $itemRows = '';
        foreach ($items as $it) {
            $name = htmlspecialchars($it['name'] ?? 'Product');
            $variant = !empty($it['variant_name']) ? "<br><span style='font-size:11px;color:#7A7275;'>Size: " . htmlspecialchars($it['variant_name']) . "</span>" : '';
            $qty = (int)($it['quantity'] ?? 1);
            $priceFmt = number_format((float)($it['price'] ?? 0), 2);
            $lineTotal = number_format($qty * (float)($it['price'] ?? 0), 2);

            /* Fulfilment reference, on the ADMIN copy only.
               ────────────────────────────────────────────────────────────────
               This email is usually the first sight of a new order, often on a
               phone — and it named the garment and its size and nothing more,
               so it could not be acted on without opening the admin panel and
               looking the product up.

               sendOrderPlacedCustomerEmail() carries the identical row markup
               and is deliberately NOT changed: a shopper must never be told who
               supplies the shop. Read from the order's own snapshot, so it names
               the supplier who actually filled this order rather than whoever
               supplies the product today. Older orders carry none of these keys
               and simply show nothing. */
            $refBits = [];
            if (!empty($it['sku']))          { $refBits[] = htmlspecialchars((string)$it['sku']); }
            if (!empty($it['supplier']))     { $refBits[] = htmlspecialchars((string)$it['supplier']); }
            if (!empty($it['supplier_ref'])) { $refBits[] = htmlspecialchars((string)$it['supplier_ref']); }
            $ref = $refBits
                ? "<br><span style='font-size:11px;color:#8D7146;font-family:monospace;'>" . implode(' &middot; ', $refBits) . "</span>"
                : '';

            $itemRows .= "
            <tr>
                <td><strong>{$name}</strong>{$variant}{$ref}</td>
                <td style='text-align:center;'>{$qty}</td>
                <td style='text-align:right;'>{$sym}{$priceFmt}</td>
                <td style='text-align:right;'><strong>{$sym}{$lineTotal}</strong></td>
            </tr>";
        }

        $address = nl2br(htmlspecialchars($order['address'] ?? ''));

        $body = "
            <h2 style='font-family: Georgia, serif; font-size: 20px; font-weight: 400; color: #511126; margin-top: 0;'>New Order Received!</h2>
            <p>A new order has been placed on Dievon.</p>

            <div style='background: #FAF8F5; border: 1px solid #EAE4DC; padding: 16px; margin: 18px 0;'>
                <table width='100%' cellpadding='0' cellspacing='0'>
                    <tr>
                        <td><strong>Order Ref:</strong> <span style='color:#511126; font-weight:700;'>{$code}</span></td>
                        <td style='text-align:right;'><strong>Date:</strong> {$date}</td>
                    </tr>
                    <tr>
                        <td style='padding-top:6px;'><strong>Customer:</strong> {$safeName} ({$custEmail})</td>
                        <td style='padding-top:6px; text-align:right;'><strong>Phone:</strong> {$phone}</td>
                    </tr>
                    <tr>
                        <td style='padding-top:6px;'><strong>Status:</strong> {$status}</td>
                        <td style='padding-top:6px; text-align:right;'><strong>Payment:</strong> {$payMethod} ({$payStatus})</td>
                    </tr>
                </table>
            </div>

            <table class='item-table'>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th style='text-align:center;'>Qty</th>
                        <th style='text-align:right;'>Price</th>
                        <th style='text-align:right;'>Total</th>
                    </tr>
                </thead>
                <tbody>
                    {$itemRows}
                </tbody>
            </table>

            <div style='text-align: right; font-size: 16px; font-weight: 700; color: #511126; margin-top: 12px;'>
                Total: {$sym}{$totalFmt}
            </div>

            <div style='margin-top: 20px;'>
                <strong>📍 Delivery Address:</strong><br>
                <div style='margin-top: 4px; color: #555;'>{$address}</div>
            </div>

            <div style='text-align: center; margin-top: 24px;'>
                <a href='" . SITE_URL . "/admin/orders.php' class='btn-luxury'>View Order in Admin Dashboard &rarr;</a>
            </div>
        ";

        $html = $this->wrapLuxuryTemplate("New Order #{$code}", "Admin Order Notification", $body);
        return $this->sendMail($adminEmail, "Dievon Admin", "🚨 NEW ORDER {$code} – {$sym}{$totalFmt} [{$status}]", $html, 'admin_order_notification');
    }

    // ============================================================
    //  5. ORDER STATUS UPDATES EMAIL (Duplicate Prevention Enforced)
    // ============================================================
    /**
     * @param bool $force Send even when the status is unchanged.
     *
     *   Only for the case where something the customer NEEDS changed while the
     *   status did not — in practice, a tracking number added after the order
     *   was already marked Shipped. Marking Shipped with the boxes empty and
     *   filling them in afterwards is the natural way to work, and it used to
     *   mean the customer was told "Shipped" with nothing to track and then
     *   never told again: the second save matched last_notified_status and was
     *   silently swallowed. The parcel had a tracking number the buyer could
     *   not see.
     *
     *   The caller decides. This is not "ignore the guard" — the guard still
     *   stops the ordinary repeat, which is someone pressing Save twice.
     */
    public function sendOrderStatusEmail(array $order, string $newStatus, string $trackingNum = '', string $carrier = '', bool $force = false): bool {
        $email = trim($order['customer_email'] ?? '');
        if (empty($email)) return false;

        $lastNotified = trim($order['last_notified_status'] ?? '');
        if (!$force && strtolower($lastNotified) === strtolower($newStatus)) {
            // Duplicate prevention: status has not changed
            return false;
        }

        $safeName = htmlspecialchars($order['customer_name'] ?? 'Valued Customer');
        $code = htmlspecialchars($order['order_code'] ?? '');
        $safeStatus = htmlspecialchars($newStatus);
        $sym = htmlspecialchars($order['currency_symbol'] ?? '₹');
        $totalFmt = number_format((float)($order['total_price'] ?? 0), 2);

        $statusColor = '#511126';
        $statusIcon = '📦';
        $statusMessage = 'Your order status has been updated.';

        switch (strtolower($newStatus)) {
            case 'processing':
                $statusIcon = '⚙️';
                $statusMessage = 'Your order is currently being prepared and quality-checked by our master craftsmen.';
                break;
            case 'shipped':
            case 'dispatched':
                $statusIcon = '🚚';
                $statusMessage = 'Great news! Your package has been dispatched and is on its way to you.';
                break;
            case 'delivered':
            case 'completed':
                $statusIcon = '✨';
                $statusColor = '#1E6B37';
                $statusMessage = 'Your order has been successfully delivered. We hope you adore your new Dievon piece!';
                break;
            case 'cancelled':
                $statusIcon = '❌';
                $statusColor = '#A94442';
                $statusMessage = 'Your order has been cancelled as requested or due to inventory adjustment.';
                break;
            case 'refunded':
                $statusIcon = '💳';
                $statusColor = '#8A6D3B';
                // Was "A refund has been processed for your order." — but nothing in
                // this codebase moves money: there is no call to Razorpay's refund
                // API anywhere, and no refund_amount / refunded_at / refund id column.
                // The refund is done by hand in the Razorpay dashboard, so the email
                // must not state as fact that it has already happened. If the manual
                // step were ever missed, that sentence is written evidence against the
                // shop in a chargeback or consumer complaint. Reworded to approval,
                // which is what selecting this status actually represents.
                // The 3–5 day figure matches the public policy at pages/returns.php:29.
                $statusMessage = 'Your refund for this order has been approved. '
                    . 'It will be returned to your original payment method, and normally '
                    . 'takes 3–5 business days to appear once processed. '
                    . 'We will confirm separately with the refund reference.';
                break;
            /* The statuses below had no wording of their own, so they fell to the
               generic "Your order status has been updated." That is thin for a
               customer owed money or waiting on a courier — the line arrives, says
               nothing, and has to be chased. Each now says what actually happened
               and what the customer should expect next.

               Pending and Pending Payment are deliberately absent: the order-placed
               email already covers "we have your order", and a second message
               saying the same thing the moment it is filed is noise. */
            case 'confirmed':
                $statusIcon = '📋';
                $statusColor = '#1E6B37';
                $statusMessage = 'Your order is confirmed and has entered our atelier queue. '
                    . 'We will write again the moment it is on its way.';
                break;
            case 'packed':
                $statusIcon = '🎀';
                $statusMessage = 'Your order has been packed and is waiting for the courier. '
                    . 'A tracking link follows as soon as it is collected.';
                break;
            case 'in progress':
                $statusIcon = '🧵';
                $statusMessage = 'Your order is being worked on now.';
                break;
            case 'out for delivery':
                $statusIcon = '🛵';
                $statusColor = '#1E6B37';
                $statusMessage = 'Your order is out for delivery and should reach you today. '
                    . 'Please keep your phone nearby — the courier may call.';
                break;
            case 'rto':
                /* Returned to origin: the courier could not deliver and has sent the
                   parcel back. The customer needs to know it is not lost and that
                   they do not have to do anything yet, because the alternative is
                   a tracking page that simply stops. */
                $statusIcon = '↩️';
                $statusColor = '#8A6D3B';
                $statusMessage = 'Your parcel could not be delivered and is on its way back to us. '
                    . 'Nothing is lost — we will contact you to arrange redelivery or a refund.';
                break;
            case 'cancellation requested':
                $statusIcon = '🕓';
                $statusColor = '#8A6D3B';
                $statusMessage = 'We have received your cancellation request and are checking whether '
                    . 'the order can still be stopped. You will hear from us shortly either way.';
                break;
            case 'exchange requested':
                $statusIcon = '🔁';
                $statusColor = '#8A6D3B';
                $statusMessage = 'We have received your exchange request and are reviewing it. '
                    . 'We will confirm the replacement and how to send the original back.';
                break;
            case 'refund pending':
                /* Says nothing about timing on purpose. Nothing in this codebase
                   moves money — refunds are issued by hand — so a promised date
                   would be a guess, and a missed guess on money is worse than no
                   date at all. The same reasoning as the 'refunded' case above. */
                $statusIcon = '⏳';
                $statusColor = '#8A6D3B';
                $statusMessage = 'Your refund has been approved and is being processed. '
                    . 'We will confirm here with the reference once it has been sent to your bank.';
                break;
            case 'return requested':
                $statusIcon = '📮';
                $statusColor = '#8A6D3B';
                $statusMessage = 'We have received your return request and are reviewing it. '
                    . 'You will hear from us shortly with the next steps.';
                break;
            case 'returned':
                $statusIcon = '📦';
                $statusColor = '#8A6D3B';
                $statusMessage = 'We have received your returned item. '
                    . 'Any refund due will be confirmed to you separately.';
                break;
        }

        $trackingHtml = '';
        if (!empty($trackingNum)) {
            $safeTracking = htmlspecialchars($trackingNum);
            $safeCarrier = htmlspecialchars($carrier ?: 'Standard Courier');
            $trackingHtml = "
            <div style='background: #FFF9F0; border: 1px dashed #D4AF37; padding: 14px 18px; margin: 18px 0;'>
                <strong>🚚 Tracking Information:</strong><br>
                Carrier: <strong>{$safeCarrier}</strong><br>
                Tracking Code: <strong style='color:#511126;'>{$safeTracking}</strong>
            </div>";
        }

        $body = "
            <h2 style='font-family: Georgia, serif; font-size: 20px; font-weight: 400; color: {$statusColor}; margin-top: 0;'>{$statusIcon} Order Status Update</h2>
            <p>Dear <strong>{$safeName}</strong>,</p>
            <p>The status of your Dievon order <strong>#{$code}</strong> has been updated to: <span style='font-size:16px; font-weight:700; color:{$statusColor};'>{$safeStatus}</span>.</p>

            <div style='background: #FAF8F5; border-left: 4px solid {$statusColor}; padding: 14px 18px; margin: 18px 0;'>
                {$statusMessage}
            </div>

            {$trackingHtml}

            <p style='margin-top: 20px;'><strong>Order Summary:</strong><br>
            Reference: <strong>#{$code}</strong><br>
            Total: <strong>{$sym}{$totalFmt}</strong></p>

            <div style='text-align: center; margin-top: 26px;'>
                <a href='" . SITE_URL . "/pages/account.php' class='btn-luxury'>Track Order in Account &rarr;</a>
            </div>
        ";

        $html = $this->wrapLuxuryTemplate("Order Status Update #{$code}", "Order Status Update", $body);
        $sent = $this->sendMail($email, $safeName, "Order Status Update: #{$code} is now {$safeStatus}", $html, 'order_status_update');

        if ($sent && $this->pdo && !empty($order['id'])) {
            try {
                $upd = $this->pdo->prepare("UPDATE orders SET last_notified_status = :st WHERE id = :id");
                $upd->execute(['st' => $newStatus, 'id' => $order['id']]);
            } catch (\PDOException $ex) {}
        }

        return $sent;
    }

    /**
     * Asks a customer to review what they bought, sent once on first delivery.
     *
     * The review system (write, moderate, display with rich snippets) is fully
     * built, but nothing ever prompted a customer to use it — orders kept
     * arriving with zero reviews. This is the missing last step: one email per
     * order, sent when it is first marked Delivered/Completed, linking back to
     * the bought products' pages where the review form lives. Deduplication is
     * the caller's job (orders.review_requested_at), so a failed send is retried
     * on the next status save rather than lost.
     */
    public function sendReviewRequestEmail(array $order): bool
    {
        $email = trim($order['customer_email'] ?? '');
        if (empty($email)) return false;

        $safeName = htmlspecialchars($order['customer_name'] ?? 'Valued Customer');
        $code     = htmlspecialchars($order['order_code'] ?? '');

        // One row per purchased item, linking back to its product page (where the
        // review form is). Limited to the first four so the email stays tidy on
        // a phone.
        $itemsHtml = '';
        $items = json_decode((string)($order['items_json'] ?? '[]'), true) ?: [];
        foreach (array_slice($items, 0, 4) as $it) {
            $pid  = (int)($it['product_id'] ?? 0);
            $name = trim((string)($it['name'] ?? $it['product_name'] ?? ''));
            if ($pid <= 0 || $name === '') { continue; }
            $url = function_exists('productUrl')
                ? productUrl($pid, $name)
                : SITE_URL . '/product.php?id=' . $pid;
            $itemsHtml .= "<li style='margin:6px 0;'><a href='{$url}' style='color:#511126; font-weight:600;'>"
                . htmlspecialchars($name) . "</a></li>";
        }
        if ($itemsHtml === '') { return false; }

        $body = "
            <h2 style='font-family: Georgia, serif; font-size: 20px; font-weight: 400; color: #511126; margin-top: 0;'>We would love your feedback ✨</h2>
            <p>Dear <strong>{$safeName}</strong>,</p>
            <p>Your Dievon order <strong>#{$code}</strong> has been delivered. We hope your new pieces are everything you wished for.</p>
            <p>If you have a moment, a short review helps other shoppers choose with confidence — and tells our ateliers what to make more of.</p>
            <div style='background: #FAF8F5; border-left: 4px solid #D4AF37; padding: 14px 18px; margin: 18px 0;'>
                <strong style='color:#511126;'>Leave a review for:</strong>
                <ul style='margin:8px 0 0; padding-left:20px;'>{$itemsHtml}</ul>
            </div>
            <p style='font-size:12px; color:#6b7280;'>Reviews appear on the product page after a quick moderation check.</p>
        ";

        $html = $this->wrapLuxuryTemplate('Review Request', 'We would love your feedback', $body);
        return $this->sendMail($email, $safeName, 'How was your Dievon order? Leave a review', $html, 'review_request');
    }

    // ============================================================
    //  5b. PAYMENT RECEIVED
    // ============================================================
    /**
     * Confirms money was received. Nothing to do with delivery.
     *
     * Marking an order Paid or Cash used to call sendOrderStatusEmail($order,
     * 'Delivered'), which told the customer "Your order has been successfully
     * delivered" for an order still sitting in the shop — and, because that
     * method writes last_notified_status, it also stamped 'Delivered' on the
     * row. The duplicate guard at the top of sendOrderStatusEmail() then
     * silently swallowed the REAL delivery email when the parcel actually
     * arrived. COD orders took the worst of it, since "Cash Received" is the
     * routine click for them.
     *
     * Deliberately does NOT touch last_notified_status: that column tracks
     * fulfilment progress, and a payment is not a fulfilment step.
     */
    public function sendPaymentReceivedEmail(array $order): bool {
        $email = trim($order['customer_email'] ?? '');
        if ($email === '') { return false; }

        $safeName = htmlspecialchars($order['customer_name'] ?? 'Valued Customer');
        $code     = htmlspecialchars($order['order_code'] ?? '');
        $sym      = htmlspecialchars($order['currency_symbol'] ?? '₹');
        $totalFmt = number_format((float)($order['total_price'] ?? 0), 2);
        $method   = strtolower(trim($order['payment_method'] ?? ''));
        $howPaid  = ($method === 'cod' || $method === 'cash')
            ? 'Cash on Delivery'
            : 'your online payment';

        $body = "
            <h2 style='font-family: Georgia, serif; font-size: 20px; font-weight: 400; color: #1E6B37; margin-top: 0;'>💰 Payment Received</h2>
            <p>Dear <strong>{$safeName}</strong>,</p>
            <p>We have received {$howPaid} for order <strong>#{$code}</strong>. Thank you.</p>

            <div style='background: #FAF8F5; border-left: 4px solid #1E6B37; padding: 14px 18px; margin: 18px 0;'>
                Amount received: <strong>{$sym}{$totalFmt}</strong><br>
                Reference: <strong>#{$code}</strong>
            </div>

            <p>This confirms payment only. We will email you again as soon as your order is dispatched.</p>

            <div style='text-align: center; margin-top: 26px;'>
                <a href='" . SITE_URL . "/pages/account.php' class='btn-luxury'>View Order in Account &rarr;</a>
            </div>
        ";

        $html = $this->wrapLuxuryTemplate("Payment Received #{$code}", "Payment Received", $body);
        return $this->sendMail($email, $safeName, "Payment Received for Order #{$code}", $html, 'payment_received');
    }

    // ============================================================
    //  5e. ORDER CANCELLED BY THE CUSTOMER — tell the shop
    // ============================================================
    /**
     * Fires when an UNPAID order is self-cancelled. No money is involved, so this
     * is informational rather than an action request — but it still matters,
     * because without it the shop can pick, pack and ship an order the customer
     * already cancelled, then pay for the return leg as well.
     */
    public function sendOrderCancelledAdminEmail(array $order): bool {
        $code     = htmlspecialchars($order['order_code'] ?? '');
        $safeName = htmlspecialchars($order['customer_name'] ?? 'Customer');
        $sym      = htmlspecialchars($order['currency_symbol'] ?? '₹');
        $totalFmt = number_format((float)($order['total_price'] ?? 0), 2);

        $body = "
            <h2 style='font-family: Georgia, serif; font-size: 20px; font-weight: 400; color: #A94442; margin-top: 0;'>❌ Order cancelled by the customer</h2>
            <p><strong>{$safeName}</strong> cancelled order <strong>#{$code}</strong>.</p>

            <div style='background: #FAF8F5; border-left: 4px solid #A94442; padding: 14px 18px; margin: 18px 0;'>
                Order value: <strong>{$sym}{$totalFmt}</strong><br>
                Payment: <strong>" . htmlspecialchars($order['payment_status'] ?? 'Unpaid') . "</strong><br>
                Status now: <strong>Cancelled</strong>
            </div>

            <p>No payment was taken, so there is nothing to refund. <strong>Do not ship this order.</strong>
            If it is already picked or packed, put the stock back.</p>

            <div style='text-align: center; margin-top: 26px;'>
                <a href='" . SITE_URL . "/admin/orders.php' class='btn-luxury'>Open Orders &rarr;</a>
            </div>
        ";
        $html = $this->wrapLuxuryTemplate("Order cancelled #{$code}", "Order Cancelled", $body);
        return $this->sendMail($this->adminAddress, 'Dievon',
            "Order #{$code} cancelled by customer — do not ship", $html, 'order_cancelled_admin');
    }

    /**
     * A customer has asked to return something — told to the shop.
     *
     * The customer's side of this already existed: sendOrderStatusEmail() has a
     * 'return requested' case, written and never reached, because the handler
     * that creates the RMA moved the order on with a raw UPDATE instead of going
     * through the status path. The shop's side did not exist at all — a return
     * surfaced only when somebody happened to open the returns page, so a request
     * could sit unseen for days while the customer waited.
     *
     * Modelled on sendOrderCancelledAdminEmail() above, which was written for the
     * same gap on cancellations. Carries the reason and the RMA code, because
     * both decide what happens next and neither is guessable from the order.
     */
    /**
     * A deletion receipt, sent to the address being erased.
     *
     * Deleting an account changed thirteen tables and told nobody. The customer
     * was left with an on-screen line and no proof the request was honoured —
     * which is precisely the thing a person asking to be forgotten wants in
     * writing, and the one message that can never be re-sent afterwards because
     * the address is gone.
     *
     * Sent BEFORE the rows are erased, for that reason: after the delete there is
     * no address left to write to.
     *
     * Says what was kept as well as what went. Orders survive because tax law
     * requires them, anonymised — claiming "everything has been deleted" when
     * order rows remain would be a plain untruth in a receipt whose whole value
     * is being trustworthy.
     */
    public function sendAccountDeletedEmail(string $email, string $name, int $ordersKept = 0): bool {
        $email = trim($email);
        if ($email === '') { return false; }
        $safeName = htmlspecialchars($name !== '' ? $name : 'there');

        $keptLine = $ordersKept > 0
            ? "<p>Your <strong>" . (int)$ordersKept . " past order" . ($ordersKept === 1 ? '' : 's')
              . "</strong> had to be kept — tax law requires a shop to hold its sales records — but they no "
              . "longer carry your name, email, phone or address, and nothing links them back to you.</p>"
            : '';

        $body = "
            <h2 style='font-family: Georgia, serif; font-size: 20px; font-weight: 400; color: #511126; margin-top: 0;'>Your account has been deleted</h2>
            <p>Hello {$safeName},</p>
            <p>Your Dievon account has been closed and your personal details erased, as you asked.
               This is your confirmation — no reply is needed.</p>

            <div style='background: #FAF8F5; border-left: 4px solid #511126; padding: 14px 18px; margin: 18px 0;'>
                <strong>Erased:</strong> your account, saved addresses, wishlist,
                support tickets, enquiries and newsletter subscription.
            </div>

            {$keptLine}

            <p>Any reviews you left stay on the products, under <em>Dievon Customer</em> rather than your name,
               so other shoppers keep the rating.</p>

            <p style='color:#6b6b6b; font-size:13px;'>If you did not ask for this, reply to this message
               straight away — this address will stop working once the deletion completes.</p>
        ";
        $html = $this->wrapLuxuryTemplate('Your Dievon account has been deleted', 'Account Deleted', $body);
        return $this->sendMail($email, $name !== '' ? $name : 'Customer',
            'Your Dievon account has been deleted', $html, 'account_deleted');
    }

    /**
     * A review is waiting to be published — told to the shop.
     *
     * Reviews are stored 'Pending' and a human publishes them, and the customer
     * is told as much: "It will appear on the product page once approved." But
     * nothing told the shop one had arrived, so approval waited on somebody
     * happening to open the reviews screen. A customer who was promised their
     * words would appear could wait indefinitely for a queue nobody was watching.
     *
     * Same shape as the return-request gap: something needs attention and the
     * only person who can act on it is not told.
     */
    public function sendReviewPendingAdminEmail(array $review): bool {
        $product = htmlspecialchars($review['product_name'] ?? ('#' . (int)($review['product_id'] ?? 0)));
        $author  = htmlspecialchars($review['author_name'] ?? 'A customer');
        $rating  = max(0, min(5, (int)($review['rating'] ?? 0)));
        $stars   = str_repeat('★', $rating) . str_repeat('☆', 5 - $rating);
        $title   = trim((string)($review['title'] ?? ''));
        $titleTx = $title !== '' ? '<strong>' . htmlspecialchars($title) . '</strong><br>' : '';
        $text    = trim((string)($review['review_text'] ?? ''));
        $textTx  = $text !== '' ? nl2br(htmlspecialchars($text)) : '<em>No words, rating only</em>';

        $body = "
            <h2 style='font-family: Georgia, serif; font-size: 20px; font-weight: 400; color: #8A6D3B; margin-top: 0;'>⭐ A review is waiting for approval</h2>
            <p><strong>{$author}</strong> reviewed <strong>{$product}</strong>.</p>

            <div style='background: #FAF8F5; border-left: 4px solid #8A6D3B; padding: 14px 18px; margin: 18px 0;'>
                <span style='font-size:18px; letter-spacing:2px; color:#C9A227;'>{$stars}</span>
                &nbsp;<span style='color:#6b6b6b;'>{$rating} of 5</span><br><br>
                {$titleTx}{$textTx}
            </div>

            <p>It is <strong>not visible on the site</strong> until you publish it, and the customer has been
            told it will appear once approved.</p>

            <div style='text-align: center; margin-top: 26px;'>
                <a href='" . SITE_URL . "/admin/reviews.php' class='btn-luxury'>Open Reviews &rarr;</a>
            </div>
        ";
        $html = $this->wrapLuxuryTemplate('Review awaiting approval', 'Review Awaiting Approval', $body);
        return $this->sendMail($this->adminAddress, 'Dievon',
            "Review awaiting approval — {$product}", $html, 'review_pending_admin');
    }

    /**
     * Somebody reported a published review — told to the shop, urgently.
     *
     * This one is worse than the pending-review gap it sits beside: a reported
     * review is already ON the product page. Whatever a shopper found offensive
     * enough to report stays visible to every other shopper until somebody
     * notices the report, and nothing was raising it.
     */
    public function sendReviewReportedAdminEmail(array $report): bool {
        $product = htmlspecialchars($report['product_name'] ?? '');
        $author  = htmlspecialchars($report['author_name'] ?? 'Unknown');
        $reason  = htmlspecialchars((string)($report['reason'] ?? '—'));
        $details = trim((string)($report['details'] ?? ''));
        $detailT = $details !== '' ? nl2br(htmlspecialchars($details)) : '<em>None given</em>';
        $count   = max(1, (int)($report['reported_count'] ?? 1));
        $excerpt = trim((string)($report['review_text'] ?? ''));
        $excerptT= $excerpt !== '' ? nl2br(htmlspecialchars(mb_substr($excerpt, 0, 400))) : '<em>(no text)</em>';

        $body = "
            <h2 style='font-family: Georgia, serif; font-size: 20px; font-weight: 400; color: #A94442; margin-top: 0;'>🚩 A review has been reported</h2>
            <p>A shopper reported a review of <strong>{$product}</strong>, written by <strong>{$author}</strong>.
               It has now been reported <strong>{$count}</strong> time" . ($count === 1 ? '' : 's') . ".</p>

            <div style='background: #FAF8F5; border-left: 4px solid #A94442; padding: 14px 18px; margin: 18px 0;'>
                Reason given: <strong>{$reason}</strong><br><br>{$detailT}
            </div>

            <p style='margin-bottom:6px;'><strong>The review itself</strong></p>
            <p style='background:#fff; border:1px solid #EDE7DF; padding:12px 14px; margin-top:0;'>{$excerptT}</p>

            <p><strong>It is still visible on the product page.</strong> Reporting does not hide it —
            only you can, from the reviews screen.</p>

            <div style='text-align: center; margin-top: 26px;'>
                <a href='" . SITE_URL . "/admin/reviews.php' class='btn-luxury'>Open Reviews &rarr;</a>
            </div>
        ";
        $html = $this->wrapLuxuryTemplate('Review reported', 'Review Reported', $body);
        return $this->sendMail($this->adminAddress, 'Dievon',
            "Review reported on {$product} — still visible", $html, 'review_reported_admin');
    }

    public function sendReturnRequestedAdminEmail(array $rma): bool {
        $code     = htmlspecialchars($rma['return_code'] ?? '');
        $orderTxt = htmlspecialchars($rma['order_code'] ?? '');
        $safeName = htmlspecialchars($rma['customer_name'] ?? 'Customer');
        $type     = htmlspecialchars(ucfirst((string)($rma['request_type'] ?? 'Return')));
        $reason   = htmlspecialchars((string)($rma['reason'] ?? '—'));
        $details  = trim((string)($rma['details'] ?? ''));
        $detailTx = $details !== '' ? nl2br(htmlspecialchars($details)) : '<em>None given</em>';
        $sizeTxt  = trim((string)($rma['exchange_size'] ?? ''));
        $hasPhoto = !empty($rma['photo_path']);

        $body = "
            <h2 style='font-family: Georgia, serif; font-size: 20px; font-weight: 400; color: #8A6D3B; margin-top: 0;'>📮 Return requested by the customer</h2>
            <p><strong>{$safeName}</strong> has requested a <strong>{$type}</strong> on order <strong>#{$orderTxt}</strong>.</p>

            <div style='background: #FAF8F5; border-left: 4px solid #8A6D3B; padding: 14px 18px; margin: 18px 0;'>
                RMA: <strong>{$code}</strong><br>
                Reason: <strong>{$reason}</strong>"
                . ($sizeTxt !== '' ? "<br>Exchange size wanted: <strong>" . htmlspecialchars($sizeTxt) . "</strong>" : '')
                . "<br>Photo attached: <strong>" . ($hasPhoto ? 'Yes' : 'No') . "</strong>
            </div>

            <p style='margin-bottom:6px;'><strong>What they said</strong></p>
            <p style='background:#fff; border:1px solid #EDE7DF; padding:12px 14px; margin-top:0;'>{$detailTx}</p>

            <p>The order is now <strong>Return Requested</strong>. Nothing has been refunded — approving a
            return does not move any money, and the refund has its own email once it is actually issued.</p>

            <div style='text-align: center; margin-top: 26px;'>
                <a href='" . SITE_URL . "/admin/returns.php' class='btn-luxury'>Open Returns &rarr;</a>
            </div>
        ";
        $html = $this->wrapLuxuryTemplate("Return requested {$code}", "Return Requested", $body);
        return $this->sendMail($this->adminAddress, 'Dievon',
            "Return {$code} requested on order #{$orderTxt}", $html, 'return_requested_admin');
    }

    // ============================================================
    //  5f. RETURN / RMA STATUS CHANGED
    // ============================================================
    /**
     * Sent when the shop moves an RMA along. Nothing was sent before, so a
     * customer who posted a garment back heard nothing until (and unless) a
     * refund appeared.
     *
     * Deliberately says nothing about money: approving a return does not move
     * any, and the refund has its own email once it is actually issued.
     */
    public function sendReturnStatusEmail(array $rma, string $newStatus): bool {
        $email = trim($rma['customer_email'] ?? '');
        if ($email === '') { return false; }

        $safeName = htmlspecialchars($rma['customer_name'] ?? 'Valued Customer');
        $code     = htmlspecialchars($rma['order_code'] ?? '');
        $safeStat = htmlspecialchars($newStatus);

        $icon = '📦'; $colour = '#8A6D3B';
        $message = 'The status of your return has been updated.';
        switch (strtolower(trim($newStatus))) {
            case 'approved':
                $icon = '✅'; $colour = '#1E6B37';
                $message = 'Your return has been approved. Please send the item back to us using the '
                         . 'instructions we provide, keeping the original packaging where possible.';
                break;
            case 'received':
            case 'in transit':
                $message = 'We have your return on its way to us and will inspect it as soon as it arrives.';
                break;
            case 'completed':
                $icon = '✨'; $colour = '#1E6B37';
                $message = 'Your return is complete. If a refund is due, it will be confirmed to you '
                         . 'in a separate email with its reference.';
                break;
            case 'rejected':
            case 'declined':
                $icon = '⚠️'; $colour = '#A94442';
                $message = 'Unfortunately we could not accept this return. Reply to this email and '
                         . 'our team will explain and help you further.';
                break;
        }

        /* The courier and tracking number for the return leg.
           ────────────────────────────────────────────────────────────────────
           admin/returns.php records both against the RMA, and this email is the
           moment the customer is told their return moved — but it never carried
           either, so they learned a courier had been booked and still had no
           name to expect or number to chase it with. Included only once the shop
           has actually filled them in. */
        $rmaCarrier = htmlspecialchars(trim((string)($rma['return_carrier'] ?? '')));
        $rmaAwb     = htmlspecialchars(trim((string)($rma['return_awb'] ?? '')));
        $trackingBlock = '';
        if ($rmaCarrier !== '' || $rmaAwb !== '') {
            $bits = [];
            if ($rmaCarrier !== '') { $bits[] = "<strong>Courier:</strong> {$rmaCarrier}"; }
            if ($rmaAwb !== '')     { $bits[] = "<strong>Tracking number:</strong> {$rmaAwb}"; }
            $trackingBlock = "
            <div style='background:#FFFFFF; border:1px solid #E8E0D8; padding:14px 18px; margin:18px 0;'>
                <div style='font-size:12px; letter-spacing:0.08em; text-transform:uppercase; color:#8A7B6B; margin-bottom:6px;'>Return shipment</div>
                " . implode(' &nbsp;&middot;&nbsp; ', $bits) . "
            </div>";
        }

        $body = "
            <h2 style='font-family: Georgia, serif; font-size: 20px; font-weight: 400; color: {$colour}; margin-top: 0;'>{$icon} Return Update</h2>
            <p>Dear <strong>{$safeName}</strong>,</p>
            <p>Your return for order <strong>#{$code}</strong> is now:
               <span style='font-weight:700; color:{$colour};'>{$safeStat}</span>.</p>

            <div style='background: #FAF8F5; border-left: 4px solid {$colour}; padding: 14px 18px; margin: 18px 0;'>
                {$message}
            </div>
            {$trackingBlock}

            <div style='text-align: center; margin-top: 26px;'>
                <a href='" . SITE_URL . "/pages/account.php' class='btn-luxury'>View in Account &rarr;</a>
            </div>
        ";
        $html = $this->wrapLuxuryTemplate("Return update #{$code}", "Return Update", $body);
        return $this->sendMail($email, $safeName,
            "Return Update for Order #{$code} — {$safeStat}", $html, 'return_status');
    }

    // ============================================================
    //  5d. CANCELLATION REQUESTED on a PAID order
    // ============================================================
    /**
     * Two emails: one telling the shop money is owed back, one telling the
     * customer their request landed.
     *
     * The shop copy carries the Razorpay payment id, because without it the
     * owner has to go digging in the database to find what to refund — and
     * nothing else in the system flags that a refund is outstanding.
     */
    public function sendCancellationRequestEmails(array $order): bool {
        $code      = htmlspecialchars($order['order_code'] ?? '');
        $safeName  = htmlspecialchars($order['customer_name'] ?? 'Customer');
        $custEmail = trim($order['customer_email'] ?? '');
        $sym       = htmlspecialchars($order['currency_symbol'] ?? '₹');
        $totalFmt  = number_format((float)($order['total_price'] ?? 0), 2);
        $payMethod = htmlspecialchars($order['payment_method'] ?? '');
        $payId     = trim((string)($order['razorpay_payment_id'] ?? ''));
        $payLine   = $payId !== ''
            ? "Razorpay payment id: <strong>" . htmlspecialchars($payId) . "</strong><br>"
            : "Paid by <strong>{$payMethod}</strong> — no online payment reference.<br>";

        // ── to the shop ──
        $adminBody = "
            <h2 style='font-family: Georgia, serif; font-size: 20px; font-weight: 400; color: #A94442; margin-top: 0;'>⚠️ Cancellation requested on a PAID order</h2>
            <p><strong>{$safeName}</strong> has asked to cancel order <strong>#{$code}</strong>, which is already paid.</p>

            <div style='background: #FDF3F3; border-left: 4px solid #A94442; padding: 14px 18px; margin: 18px 0;'>
                Amount paid: <strong>{$sym}{$totalFmt}</strong><br>
                {$payLine}
                Customer: " . htmlspecialchars($custEmail) . "
            </div>

            <p><strong>The order has NOT been cancelled.</strong> It is now marked
            &ldquo;Cancellation Requested&rdquo; and is waiting for you.</p>

            <p>To action it: open Orders in admin, use the <strong>Refund</strong> panel on that
            order to send the money back, then set the status to <strong>Cancelled</strong>.
            Do not set it to Cancelled first — the customer would be told it was cancelled
            while still holding no refund.</p>

            <div style='text-align: center; margin-top: 26px;'>
                <a href='" . SITE_URL . "/admin/orders.php' class='btn-luxury'>Open Orders &rarr;</a>
            </div>
        ";
        $adminHtml = $this->wrapLuxuryTemplate("Cancellation requested #{$code}", "Action needed", $adminBody);
        $adminSent = $this->sendMail(
            $this->adminAddress, 'Dievon',
            "ACTION NEEDED: cancellation requested on paid order #{$code} ({$sym}{$totalFmt})",
            $adminHtml, 'cancellation_request_admin'
        );

        // ── to the customer ──
        $custSent = false;
        if ($custEmail !== '') {
            $custBody = "
                <h2 style='font-family: Georgia, serif; font-size: 20px; font-weight: 400; color: #8A6D3B; margin-top: 0;'>📩 Cancellation request received</h2>
                <p>Dear <strong>{$safeName}</strong>,</p>
                <p>We have received your request to cancel order <strong>#{$code}</strong>.</p>

                <div style='background: #FAF8F5; border-left: 4px solid #8A6D3B; padding: 14px 18px; margin: 18px 0;'>
                    Order total: <strong>{$sym}{$totalFmt}</strong><br>
                    Status: <strong>Cancellation requested</strong>
                </div>

                <p>Because this order is already paid, our team will confirm the cancellation and
                arrange your refund. You will get a separate email with the refund reference once
                it has been sent.</p>

                <div style='text-align: center; margin-top: 26px;'>
                    <a href='" . SITE_URL . "/pages/account.php' class='btn-luxury'>View Order in Account &rarr;</a>
                </div>
            ";
            $custHtml = $this->wrapLuxuryTemplate("Cancellation request #{$code}", "Request Received", $custBody);
            $custSent = $this->sendMail($custEmail, $safeName,
                "We received your cancellation request for order #{$code}",
                $custHtml, 'cancellation_request_customer');
        }

        return $adminSent || $custSent;
    }

    // ============================================================
    //  5c. REFUND ISSUED — sent only after money has actually moved
    // ============================================================
    /**
     * Unlike the 'Refunded' status email, this one is sent by RefundService
     * *after* the refund has gone through, so it can state the real amount and
     * quote the Razorpay reference. That reference is what a customer's bank
     * asks for when the money has not shown up yet.
     *
     * $mode is 'razorpay' for a card/UPI reversal or 'manual' when the shop
     * hands cash back for a COD order — the wording has to differ, because
     * there is no reference number and no bank delay in the second case.
     */
    public function sendRefundIssuedEmail(array $order, float $amount, string $reference = '', string $mode = 'manual'): bool {
        $email = trim($order['customer_email'] ?? '');
        if ($email === '') { return false; }

        $safeName  = htmlspecialchars($order['customer_name'] ?? 'Valued Customer');
        $code      = htmlspecialchars($order['order_code'] ?? '');
        $sym       = htmlspecialchars($order['currency_symbol'] ?? '₹');
        $amountFmt = number_format($amount, 2);
        $total     = (float)($order['total_price'] ?? 0);
        $refunded  = (float)($order['refunded_amount'] ?? 0);
        $isPartial = ($refunded + 0.005) < $total;

        /* Both periods come from Settings, so the promise made to a customer about
           their own money can be corrected without editing PHP. The old online
           wording was hard-coded, and the COD wording gave no period at all —
           "our team will be in touch" tells someone waiting on a refund nothing,
           and silence after that reads as the shop keeping the money. */
        $timingOnline = trim((string)storeSetting($this->pdo, 'refund_timing_online', '3–5 business days'));
        $timingManual = trim((string)storeSetting($this->pdo, 'refund_timing_manual', '3–5 working days'));
        if ($timingOnline === '') { $timingOnline = '3–5 business days'; }
        if ($timingManual === '') { $timingManual = '3–5 working days'; }

        $refLine = '';
        if ($mode === 'razorpay') {
            $timing = "The money goes back to the card or account you paid from. "
                    . "Banks usually take <strong>" . htmlspecialchars($timingOnline) . "</strong> to show it.";
            if ($reference !== '') {
                $safeRef = htmlspecialchars($reference);
                $refLine = "Refund reference: <strong>{$safeRef}</strong><br>";
            }
        } else {
            /* Says who acts, how, and by when. The shop is sending this money by
               hand, so the customer has to be told what to expect and what is
               needed from them — a COD refund cannot go anywhere until they say
               where to send it. */
            $timing = "As this order was paid on delivery, we will send the amount to you directly, "
                    . "normally within <strong>" . htmlspecialchars($timingManual) . "</strong>. "
                    . "We will contact you on your registered phone or email to confirm where to send it.";
        }

        $partialLine = $isPartial
            ? "<p style='font-size:13px; color:#6b5b52;'>This is a partial refund. "
            . "Refunded so far on this order: <strong>{$sym}" . number_format($refunded, 2) . "</strong> "
            . "of <strong>{$sym}" . number_format($total, 2) . "</strong>.</p>"
            : '';

        $body = "
            <h2 style='font-family: Georgia, serif; font-size: 20px; font-weight: 400; color: #1E6B37; margin-top: 0;'>💳 Refund Issued</h2>
            <p>Dear <strong>{$safeName}</strong>,</p>
            <p>We have refunded <strong>{$sym}{$amountFmt}</strong> for your order <strong>#{$code}</strong>.</p>

            <div style='background: #FAF8F5; border-left: 4px solid #1E6B37; padding: 14px 18px; margin: 18px 0;'>
                Amount refunded: <strong>{$sym}{$amountFmt}</strong><br>
                Order: <strong>#{$code}</strong><br>
                {$refLine}
                {$timing}
            </div>

            {$partialLine}

            <p style='margin-top: 18px;'>If it has not reached you after 5 business days, reply to this email
            with the reference above and we will chase it for you.</p>

            <div style='text-align: center; margin-top: 26px;'>
                <a href='" . SITE_URL . "/pages/account.php' class='btn-luxury'>View Order in Account &rarr;</a>
            </div>
        ";

        $html = $this->wrapLuxuryTemplate("Refund Issued #{$code}", "Refund Issued", $body);
        return $this->sendMail($email, $safeName, "Refund Issued for Order #{$code} — {$sym}{$amountFmt}", $html, 'refund_issued');
    }

    // ============================================================
    //  6. CONTACT FORM EMAILS (Admin Notification + Customer Acknowledgement)
    // ============================================================
    /* $sendAck exists because the acknowledgement is the half of this that
       cannot be trusted. It is addressed to whatever the form was given, and a
       flood of enquiries carries fake addresses — so every one of those sends
       leaves the building, counts against the host's quota and may bounce,
       which teaches mail providers that this domain sends to addresses that do
       not exist. The admin alert at least goes somewhere real. The caller
       decides; nothing about the two messages changes. */
    public function sendContactFormEmails(string $name, string $email, string $phone, string $subject, string $message, bool $sendAck = true): bool {
        $safeName = htmlspecialchars($name);
        $safeEmail = htmlspecialchars($email);
        $safePhone = htmlspecialchars($phone ?: 'N/A');
        $safeSubj = htmlspecialchars($subject ?: 'General Enquiry');
        $safeMsg = nl2br(htmlspecialchars($message));

        // 1. Admin Notification Email
        $adminBody = "
            <h2 style='font-family: Georgia, serif; font-size: 20px; font-weight: 400; color: #511126; margin-top: 0;'>New Contact Form Submission</h2>
            <table width='100%' cellpadding='8' cellspacing='0' style='border-collapse: collapse; margin-bottom: 20px;'>
                <tr><td style='width:120px; font-weight:700;'>Name:</td><td>{$safeName}</td></tr>
                <tr><td style='font-weight:700;'>Email:</td><td><a href='mailto:{$safeEmail}' style='color:#511126;'>{$safeEmail}</a></td></tr>
                <tr><td style='font-weight:700;'>Phone:</td><td>{$safePhone}</td></tr>
                <tr><td style='font-weight:700;'>Subject:</td><td>{$safeSubj}</td></tr>
            </table>
            <div style='background: #FAF8F5; border: 1px solid #EAE4DC; padding: 16px;'>
                <strong>Message:</strong><br>
                {$safeMsg}
            </div>
        ";
        $adminHtml = $this->wrapLuxuryTemplate("Contact Form Enquiry", "Admin Enquiry Alert", $adminBody);
        $adminSent = $this->sendMail($this->adminAddress, "Dievon Concierge", "📬 Contact Form: {$safeSubj} from {$safeName}", $adminHtml, 'contact_admin_notice');

        if (!$sendAck) { return $adminSent; }

        // 2. Customer Acknowledgement Email
        $custBody = "
            <h2 style='font-family: Georgia, serif; font-size: 20px; font-weight: 400; color: #511126; margin-top: 0;'>Thank You for Contacting Dievon</h2>
            <p>Dear <strong>{$safeName}</strong>,</p>
            <p>Thank you for reaching out to Dievon. We have received your inquiry regarding <strong>{$safeSubj}</strong>.</p>
            <p>Our concierge styling team is reviewing your message and will respond within 24 business hours.</p>
            <div style='background: #FAF8F5; border-left: 3px solid #511126; padding: 14px 18px; margin: 18px 0; font-size: 13px;'>
                <strong>Summary of your message:</strong><br>
                {$safeMsg}
            </div>
        ";
        $custHtml = $this->wrapLuxuryTemplate("Inquiry Received", "Customer Service Acknowledgement", $custBody);
        return $this->sendMail($email, $name, "We Have Received Your Inquiry — Dievon", $custHtml, 'contact_customer_ack');
    }

    // ============================================================
    //  7. PRODUCT ENQUIRY EMAILS (Admin Notification + Customer Ack)
    // ============================================================
    public function sendProductEnquiryEmails(string $name, string $email, string $phone, string $productName, string $productUrl, string $message): bool {
        $safeName = htmlspecialchars($name);
        $safeEmail = htmlspecialchars($email);
        $safePhone = htmlspecialchars($phone ?: 'N/A');
        $safeProd = htmlspecialchars($productName);
        $safeUrl = htmlspecialchars($productUrl);
        $safeMsg = nl2br(htmlspecialchars($message));

        // 1. Admin Notification
        $adminBody = "
            <h2 style='font-family: Georgia, serif; font-size: 20px; font-weight: 400; color: #511126; margin-top: 0;'>New Product Enquiry</h2>
            <p>A customer has requested details for product: <strong>{$safeProd}</strong></p>
            <table width='100%' cellpadding='8' cellspacing='0' style='border-collapse: collapse; margin-bottom: 20px;'>
                <tr><td style='width:120px; font-weight:700;'>Product:</td><td><a href='{$safeUrl}' style='color:#511126; font-weight:700;'>{$safeProd}</a></td></tr>
                <tr><td style='font-weight:700;'>Customer:</td><td>{$safeName}</td></tr>
                <tr><td style='font-weight:700;'>Email:</td><td><a href='mailto:{$safeEmail}' style='color:#511126;'>{$safeEmail}</a></td></tr>
                <tr><td style='font-weight:700;'>Phone:</td><td>{$safePhone}</td></tr>
            </table>
            <div style='background: #FAF8F5; border: 1px solid #EAE4DC; padding: 16px;'>
                <strong>Enquiry / Fitting Request:</strong><br>
                {$safeMsg}
            </div>
            <div style='text-align: center; margin-top: 20px;'>
                <a href='{$safeUrl}' class='btn-luxury'>View Product Details &rarr;</a>
            </div>
        ";
        $adminHtml = $this->wrapLuxuryTemplate("Product Enquiry: {$safeProd}", "Admin Product Alert", $adminBody);
        $this->sendMail($this->adminAddress, "Dievon Concierge", "👗 Product Enquiry: {$safeProd} from {$safeName}", $adminHtml, 'product_enquiry_admin');

        // 2. Customer Acknowledgement
        $custBody = "
            <h2 style='font-family: Georgia, serif; font-size: 20px; font-weight: 400; color: #511126; margin-top: 0;'>Product Inquiry Received</h2>
            <p>Dear <strong>{$safeName}</strong>,</p>
            <p>Thank you for inquiring about <strong>{$safeProd}</strong>.</p>
            <p>Our personal styling team will review your inquiry and get back to you with custom fitting and availability details shortly.</p>
            <div style='background: #FAF8F5; border-left: 3px solid #D4AF37; padding: 14px 18px; margin: 18px 0; font-size: 13px;'>
                <strong>Enquiry Reference:</strong> {$safeProd}<br>
                <strong>Message:</strong><br>
                {$safeMsg}
            </div>
            <div style='text-align: center; margin-top: 20px;'>
                <a href='{$safeUrl}' class='btn-luxury'>View {$safeProd} Again &rarr;</a>
            </div>
        ";
        $custHtml = $this->wrapLuxuryTemplate("Inquiry Received: {$safeProd}", "Styling Concierge Acknowledgement", $custBody);
        return $this->sendMail($email, $name, "Product Enquiry Confirmation: {$safeProd} — Dievon", $custHtml, 'product_enquiry_customer_ack');
    }

    // ============================================================
    //  8. SUPPORT TICKET EMAILS
    //     Ticket created (customer ack + admin alert), status changed,
    //     and advisor reply. Before these existed a customer had no way of
    //     knowing anyone was working on their ticket — they had to log in
    //     and watch the status badge.
    // ============================================================

    /** Customer acknowledgement + admin alert when a new ticket is opened. */
    public function sendTicketCreatedEmails(array $ticket, string $customerName, string $customerEmail): bool {
        $code    = htmlspecialchars($ticket['ticket_code'] ?? '');
        $subject = htmlspecialchars($ticket['subject'] ?? '');
        $message = nl2br(htmlspecialchars($ticket['message'] ?? ''));
        $name    = htmlspecialchars($customerName);
        $orderTxt = !empty($ticket['order_code']) ? htmlspecialchars($ticket['order_code']) : 'Not linked to an order';
        $hasPhoto = !empty($ticket['attachment']);

        // 1. Admin alert — nobody was being told a ticket had arrived.
        $adminBody = "
            <h2 style='font-family: Georgia, serif; font-size: 20px; font-weight: 400; color: #511126; margin-top: 0;'>New Support Ticket</h2>
            <table width='100%' cellpadding='8' cellspacing='0' style='border-collapse: collapse; margin-bottom: 20px;'>
                <tr><td style='width:130px; font-weight:700;'>Ticket:</td><td>{$code}</td></tr>
                <tr><td style='font-weight:700;'>Customer:</td><td>{$name} &lt;" . htmlspecialchars($customerEmail) . "&gt;</td></tr>
                <tr><td style='font-weight:700;'>Order:</td><td>{$orderTxt}</td></tr>
                <tr><td style='font-weight:700;'>Subject:</td><td>{$subject}</td></tr>
                <tr><td style='font-weight:700;'>Photo:</td><td>" . ($hasPhoto ? 'Yes — attached in admin panel' : 'None') . "</td></tr>
            </table>
            <div style='background: #FAF8F5; border: 1px solid #EAE4DC; padding: 16px;'>
                <strong>Message:</strong><br>{$message}
            </div>";
        $this->sendMail(
            $this->adminAddress, 'Dievon Concierge',
            "🎫 New Support Ticket {$code}: {$subject}",
            $this->wrapLuxuryTemplate("New Support Ticket", "Concierge Alert", $adminBody),
            'ticket_admin_notice'
        );

        // 2. Customer acknowledgement — gives them the ticket code to quote.
        $photoLine = $hasPhoto
            ? "<p style='font-size:13px; color:#6b6b6b;'>We have received the photo you attached and our team will review it.</p>"
            : '';
        $custBody = "
            <h2 style='font-family: Georgia, serif; font-size: 20px; font-weight: 400; color: #511126; margin-top: 0;'>We Have Received Your Ticket</h2>
            <p>Dear <strong>{$name}</strong>,</p>
            <p>Thank you for contacting the Dievon Concierge. Your ticket has been logged and an advisor will respond within 24 business hours.</p>
            <div style='background: #FAF8F5; border-left: 3px solid #511126; padding: 14px 18px; margin: 18px 0; font-size: 13px;'>
                <strong>Ticket Reference:</strong> {$code}<br>
                <strong>Subject:</strong> {$subject}<br><br>
                <strong>Your message:</strong><br>{$message}
            </div>
            {$photoLine}
            <p style='font-size:13px; color:#6b6b6b;'>Please quote <strong>{$code}</strong> in any further correspondence. You will be emailed whenever the status changes or an advisor replies.</p>";
        return $this->sendMail(
            $customerEmail, $customerName,
            "Support Ticket {$code} Received — Dievon",
            $this->wrapLuxuryTemplate("Ticket {$code} Received", "Atelier Concierge", $custBody),
            'ticket_customer_ack'
        );
    }

    /** Tell the customer their ticket moved to a new status. */
    public function sendTicketStatusEmail(array $ticket, string $customerName, string $customerEmail, string $newStatus): bool {
        $code    = htmlspecialchars($ticket['ticket_code'] ?? '');
        $subject = htmlspecialchars($ticket['subject'] ?? '');
        $name    = htmlspecialchars($customerName);
        $status  = htmlspecialchars($newStatus);

        $blurb = match ($newStatus) {
            'In Progress' => 'An advisor is now actively working on your ticket.',
            'Resolved'    => 'We believe your ticket has been resolved. If anything is still outstanding, simply reply to this email.',
            'Closed'      => 'This ticket has been closed. You are welcome to open a new ticket at any time.',
            default       => 'Your ticket has been reopened and is awaiting review.',
        };

        $body = "
            <h2 style='font-family: Georgia, serif; font-size: 20px; font-weight: 400; color: #511126; margin-top: 0;'>Ticket Update</h2>
            <p>Dear <strong>{$name}</strong>,</p>
            <p>The status of your support ticket has changed.</p>
            <div style='background: #FAF8F5; border-left: 3px solid #511126; padding: 14px 18px; margin: 18px 0; font-size: 13px;'>
                <strong>Ticket Reference:</strong> {$code}<br>
                <strong>Subject:</strong> {$subject}<br>
                <strong>New Status:</strong> {$status}
            </div>
            <p>{$blurb}</p>";
        return $this->sendMail(
            $customerEmail, $customerName,
            "Ticket {$code} is now {$status} — Dievon",
            $this->wrapLuxuryTemplate("Ticket {$code}: {$status}", "Atelier Concierge", $body),
            'ticket_status_update'
        );
    }

    /** Send the advisor's written reply to the customer. */
    public function sendTicketReplyEmail(array $ticket, string $customerName, string $customerEmail, string $reply): bool {
        $code    = htmlspecialchars($ticket['ticket_code'] ?? '');
        $subject = htmlspecialchars($ticket['subject'] ?? '');
        $name    = htmlspecialchars($customerName);
        $safeReply = nl2br(htmlspecialchars($reply));
        $original  = nl2br(htmlspecialchars($ticket['message'] ?? ''));

        $body = "
            <h2 style='font-family: Georgia, serif; font-size: 20px; font-weight: 400; color: #511126; margin-top: 0;'>A Reply From Your Advisor</h2>
            <p>Dear <strong>{$name}</strong>,</p>
            <p>An advisor has responded to your support ticket <strong>{$code}</strong>.</p>
            <div style='background: #FAF8F5; border-left: 3px solid #511126; padding: 14px 18px; margin: 18px 0; font-size: 14px;'>
                {$safeReply}
            </div>
            <div style='border-top: 1px solid #EAE4DC; padding-top: 14px; margin-top: 22px; font-size: 12px; color: #757575;'>
                <strong>Your original message ({$subject}):</strong><br>{$original}
            </div>";
        return $this->sendMail(
            $customerEmail, $customerName,
            "Re: Ticket {$code} — {$subject} — Dievon",
            $this->wrapLuxuryTemplate("Reply to Ticket {$code}", "Atelier Concierge", $body),
            'ticket_reply'
        );
    }
}
