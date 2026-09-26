<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/mailer.php';

$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['contact_submit'])) {
    /* Same token every other form on the site carries. Without it any page
       anywhere could post an enquiry in a visitor's name, and the row lands in
       admin > Enquiries looking exactly like a real one. */
    /* Bot protection, which this form alone was missing.
       ────────────────────────────────────────────────────────────────────────
       The checkout form carries a honeypot pair and login, orders, verify_email
       and forgot_password all throttle. This one had the CSRF token and nothing
       else, so it was the single public form a script could post to freely —
       and one did, at four enquiries an hour, each one sending TWO emails (the
       admin alert and the visitor acknowledgement). That is what exhausted the
       host's sending quota, and a spent quota does not only lose enquiries: the
       order confirmations and password resets queued behind it do not go out
       either. The inbox was the symptom; silent transactional mail was the cost.

       The honeypot answers with the ordinary thank-you rather than an error. A
       hidden field a human never sees has no false positives to apologise for,
       and a script told it succeeded goes away, while one told it failed comes
       back having learned something. Nothing is written and nothing is sent. */
    $cntHoneypot = !empty($_POST['website']);
    $cntLoadedAt = (int)($_POST['form_loaded_at'] ?? 0);
    /* Three seconds, against checkout's 2.5: this form asks for a written
       message, and nobody types one in under three seconds. */
    $cntTooFast  = $cntLoadedAt > 0 && ((time() * 1000) - $cntLoadedAt) < 3000;

    /* The throttle is per email address over a quarter of an hour, the same
       shape forgot_password uses. It is the backstop for a script that learns
       to leave the honeypot alone: the honeypot is what stops the flood, this
       is what caps what gets through if it ever does. Three is above any
       genuine use — nobody sends a fourth enquiry within fifteen minutes. */
    /* No links in an enquiry, because the link IS the spam.
       ────────────────────────────────────────────────────────────────────────
       The honeypot went up and the flood continued, so whatever is posting is
       reading the form rather than blindly filling every field. But every one
       of these messages exists to deliver a shortened URL — that is the entire
       payload, and the enquiry text around it is set dressing. Refuse the
       payload and there is nothing left worth the sender's trouble.

       A customer with a genuine link to share is rare on a boutique enquiry
       form, and is not turned away: the message names the shop's email address
       so they have somewhere to send it. That is a better trade than the
       alternatives — a CAPTCHA taxes every real visitor to stop this one
       sender, and guessing at gibberish names would reject real people with
       unfamiliar ones.

       Matched loosely on purpose. A bare "example.com" and an obfuscated
       "example (dot) com" both count, because a link that a human can follow
       is a link worth refusing. */
    $cntMessageRaw = (string)($_POST['message'] ?? '');
    $cntHasLink = (bool)preg_match(
          '~(?:https?://)'                                    // any explicit scheme
        . '|(?:\\bwww\\.[a-z0-9-]+\\.[a-z]{2,})'                 // www.something.tld
        . '|(?<![\\w@.])[a-z0-9][a-z0-9-]{0,61}\\.[a-z]{2,12}/'    // domain.tld/path — an email has no slash
        . '|\\(\\s*dot\\s*\\)'                                    // "example (dot) com"
        . '~i',
        $cntMessageRaw
    );

    $cntOverLimit = false;
    $cntEmailRaw  = trim((string)($_POST['email'] ?? ''));
    if (!$cntHoneypot && !$cntTooFast && $cntEmailRaw !== '') {
        try {
            $cntRate = $pdo->prepare(
                "SELECT COUNT(*) FROM inquiries
                  WHERE LOWER(email) = :email
                    AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
            );
            $cntRate->execute(['email' => mb_strtolower($cntEmailRaw)]);
            $cntOverLimit = ((int)$cntRate->fetchColumn() >= 3);
        } catch (PDOException $e) {
            /* A throttle that cannot read the table must not block a real
               enquiry — the honeypot above is still doing the heavy lifting. */
            error_log('Contact form rate-limit check failed: ' . $e->getMessage());
        }
    }

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please refresh the page and send it again.';
    } elseif ($cntHoneypot || $cntTooFast) {
        $success = true;
    } elseif ($cntHasLink) {
        $error = 'For security we cannot accept enquiries containing web links. '
               . 'Please remove the link and send again, or email us directly at '
               . htmlspecialchars(shopContactEmail($pdo ?? null)) . '.';
    } elseif ($cntOverLimit) {
        $error = 'We already have your message — our team will be in touch shortly. '
               . 'Please wait a few minutes before sending another.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $service = trim($_POST['service'] ?? 'General Enquiry');

        if ($name && $email && $message) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = "Please enter a valid email address.";
            } else {
                // Save to database.
                //
                // The catch here was empty — a rejected INSERT vanished without a
                // trace and the visitor was still thanked. The usual cause is dull:
                // the service label is appended to the name, so a long name overflows
                // the column and MySQL refuses the row. Values are trimmed to fit,
                // and a genuine failure is remembered rather than discarded.
                $messageSaved = false;
                try {
                    $ins = $pdo->prepare("INSERT INTO inquiries (name, email, phone, message) VALUES (:name, :email, :phone, :message)");
                    $messageSaved = $ins->execute([
                        'name' => mb_substr($name . " [Service: $service]", 0, 120),
                        'email' => mb_substr($email, 0, 180),
                        'phone' => mb_substr($phone, 0, 30),
                        'message' => $message
                    ]);
                } catch (PDOException $e) {
                    error_log("Contact form DB save error: " . $e->getMessage());
                    $messageSaved = false;
                }

                // Send notification to Admin & Acknowledgement to Customer via EmailService
                $mailSent = false;
                try {
                    require_once __DIR__ . '/../services/EmailService.php';
                    $emailService = new EmailService($pdo);
                    $emailService->sendContactFormEmails($name, $email, $phone, $service, $message);
                    $mailSent = true;
                } catch (\Throwable $exEmail) {
                    error_log("Contact email dispatch error: " . $exEmail->getMessage());
                }

                // Confirm only if the message survived somewhere — the database or an
                // inbox. If neither took it, tell them, rather than thanking them for
                // something nobody will ever read.
                if ($messageSaved || $mailSent) {
                    $success = true;
                } else {
                    $error = "Sorry — we could not send your message just now. Please try again, or email us directly at "
                           . htmlspecialchars(storeSetting($pdo, 'contact_email', 'info@dievon.com')) . ".";
                }
            }
        } else {
            $error = "Please fill in all required fields (Name, Email, and Message).";
        }
    }
}

$pageTitle = "Contact Us";
try {
    $seoStmt = $pdo->prepare("SELECT meta_title, meta_description, og_image FROM seo_settings WHERE page_slug = 'contact'");
    $seoStmt->execute();
    if ($seoRow = $seoStmt->fetch()) {
        if (!empty($seoRow['meta_title'])) { $pageTitle = $seoRow['meta_title']; }
        if (!empty($seoRow['meta_description'])) { $metaDescription = $seoRow['meta_description']; }
        if (!empty($seoRow['og_image'])) { $ogImage = cacheBustedUploadUrl(SITE_URL . '/uploads/gallery/' . $seoRow['og_image']); }
    }
} catch (PDOException $e) {}
require_once __DIR__ . '/../includes/header.php';
?>

<!-- ══ Lookbook Hero ═══════════════════════════════════════ -->
<section class="luxury-hero has-bg-image section-mb-sm" style="--hero-bg-image: url('<?= lookbookUrl(1) ?>')">
    <div class="container">
        <span class="luxury-hero-eyebrow">Private Invitation</span>
        <h1>Atelier Consultations</h1>
        <p>Book private fittings or request customized size consultation at Dievon.</p>
    </div>
</section>

<!-- ══ Contact Form & Details Section ═════════════════════ -->
<section class="section-space">
    <div class="container contact-container">
        
        <div class="contact-layout-grid">
            
            <!-- Left Column: Form -->
            <div class="reveal-on-scroll contact-card contact-card--form">
                <h2 class="contact-card-title">Private Inquiry Form</h2>
                
                <?php if ($success): ?>
                    <div class="contact-alert contact-alert--ok">
                        <i class="fa-solid fa-circle-check contact-alert-icon"></i>
                        <strong>Request Received</strong><br>
                        An atelier concierge representative will email or call you within 24 business hours to confirm details.
                    </div>
                <?php else: ?>
                    
                    <?php if ($error !== ''): ?>
                        <div class="contact-alert contact-alert--error">
                            <i class="fa-solid fa-triangle-exclamation contact-alert-error-icon"></i> <?= htmlspecialchars($error) ?>
                        </div>
                    <?php endif; ?>

                    <form action="contact.php" method="POST">
                        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">

                        <?php /* Bot Honeypot — the same pair the checkout form carries, and
                                 the same .hp-input class that hides it. A visitor never sees
                                 or tabs into it; a script fills every field it finds. */ ?>
                        <input type="text" name="website" id="cntHpWebsite" tabindex="-1" autocomplete="off" aria-hidden="true" class="hp-input">
                        <input type="hidden" name="form_loaded_at" id="cntHpLoadedAt" value="0">
                        <script>document.getElementById('cntHpLoadedAt').value = Date.now();</script>
                        <div class="contact-form-row">
                            <div class="form-luxury-group">
                                <label for="cntName">Full Name *</label>
                                <input type="text" id="cntName" name="name" class="form-luxury-input" required placeholder="Eleanor Vance">
                            </div>
                            <div class="form-luxury-group">
                                <label for="cntEmail">Email Address *</label>
                                <input type="email" id="cntEmail" name="email" class="form-luxury-input" required placeholder="eleanor@example.com">
                            </div>
                        </div>

                        <div class="contact-form-row">
                            <div class="form-luxury-group">
                                <label for="cntPhone">Phone Number (optional)</label>
                                <input type="tel" id="cntPhone" name="phone" class="form-luxury-input" placeholder="<?= htmlspecialchars(shopPhone()) ?>">
                            </div>
                            <div class="form-luxury-group">
                                <label for="cntService">Requested Consultation *</label>
                                <select id="cntService" name="service" class="form-luxury-input contact-select">
                                    <option value="General Inquiry">General Product Inquiry</option>
                                    <option value="Private Fitting">Bespoke Size / Private Fitting</option>
                                    <option value="Bridal Customization">Wedding / Bridal Customization</option>
                                    <option value="Atelier Tour">Atelier Consultation & Tour</option>
                                </select>
                            </div>
                        </div>


                        <div class="form-luxury-group">
                            <label for="cntMessage">Inquiry details *</label>
                            <textarea id="cntMessage" name="message" class="form-luxury-input" rows="5" required placeholder="Describe your styling preferences, size requests, or questions here..." class="contact-textarea"></textarea>
                        </div>

                        <button type="submit" name="contact_submit" class="btn-luxury contact-submit">
                            Submit Request
                        </button>
                    </form>

                <?php endif; ?>
            </div>

            <!-- Right Column: Info & Details -->
            <div class="reveal-on-scroll contact-card contact-card--info">
                <h2 class="contact-card-title">Dievon Info</h2>
                
                <?php
                // This block previously printed "Dievon London, 12 Bond Street,
                // Mayfair, London W1S 1AA" and a +44 concierge line — a real,
                // prestigious London address that is not yours, on the contact
                // page of an India-based GST-registered business. India's
                // e-commerce rules require the seller's genuine address and
                // contact details, and a customer returning an item had nowhere
                // real to send it.
                //
                // Now read from Store Settings. The whole block is skipped when
                // no address is set: showing nothing is honest, showing someone
                // else's address is not.
                $shopAddress = trim((string)storeSetting($pdo, 'store_address', ''));
                $shopPhone   = trim((string)storeSetting($pdo, 'contact_phone', SHOP_PHONE));
                ?>
                <?php if ($shopAddress !== ''): ?>
                <h3 class="contact-sub-title">Our Studio</h3>
                <p class="contact-block">
                    <?= nl2br(htmlspecialchars($shopAddress)) ?><br>
                    <small class="contact-muted">By appointment only.</small>
                </p>
                <?php endif; ?>

                <h3 class="contact-sub-title">Support Hours</h3>
                <p class="contact-block">
                    Monday to Saturday: 10:00 AM – 7:00 PM<br>
                    Sunday: 12:00 PM – 6:00 PM<br>
                    <?php if ($shopPhone !== ''):
                        /* The concierge line, as something a phone can actually dial.
                           ──────────────────────────────────────────────────────────
                           This was the one contact method on the page printed as
                           plain text: the email addresses below are mailto: links and
                           WhatsApp is a link in the footer, dock and floating button,
                           but the number a customer is most likely to want on a phone
                           could only be copied out by hand. tel: is the whole fix.

                           The visible string keeps its spacing — it is easier to read
                           and to repeat aloud — while the href carries the digits on
                           their own, which is all a dialler accepts. A bare ten-digit
                           number typed into the admin panel gets +91, since the shop
                           ships within India; anything already carrying a country code
                           is passed through untouched rather than guessed at. */
                        $shopPhoneHref = preg_replace('/[^0-9+]/', '', $shopPhone);
                        if ($shopPhoneHref !== '' && $shopPhoneHref[0] !== '+' && strlen($shopPhoneHref) === 10) {
                            $shopPhoneHref = '+91' . $shopPhoneHref;
                        }
                    ?>Concierge Line: <a href="tel:<?= htmlspecialchars($shopPhoneHref) ?>" class="contact-link"><strong><?= htmlspecialchars($shopPhone) ?></strong></a><?php endif; ?>
                </p>

                <h3 class="contact-sub-title">Digital Desk</h3>
                <p class="contact-block contact-block--last">
                    Client Support: <a href="mailto:<?= htmlspecialchars(shopContactEmail($pdo ?? null)) ?>" class="contact-link"><?= htmlspecialchars(shopContactEmail($pdo ?? null)) ?></a><br>
                    Private Fittings: <a href="mailto:<?= htmlspecialchars(shopContactEmail($pdo ?? null)) ?>" class="contact-link"><?= htmlspecialchars(shopContactEmail($pdo ?? null)) ?></a>
                </p>
            </div>

        </div>

    </div>
</section>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
