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
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please refresh the page and send it again.';
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
                    <?php if ($shopPhone !== ''): ?>Concierge Line: <strong><?= htmlspecialchars($shopPhone) ?></strong><?php endif; ?>
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
