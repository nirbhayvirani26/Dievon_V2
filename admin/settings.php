<?php
/*
 * The layout include has moved to the bottom of this block, and that ordering is
 * load-bearing.
 *
 * includes/header.php prints the page chrome as soon as it is required. Saving
 * settings ends in header('Location: ...'), and PHP cannot send a redirect once
 * any output has gone out — so every save produced
 * "Cannot modify header information - headers already sent".
 *
 * The bootstrap header.php performs (session, config, db, admin auth) is done
 * here instead, without printing anything, so the POST handler below can still
 * redirect. header.php is required further down, once all the work is finished;
 * its own session_start() and require_once calls are guarded, so including it
 * later is safe.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
// Role check. The nav hides links this account cannot use, but the URL is
// still typeable and a handler still accepts a POST — so permission is
// decided here, on the server, every time.
require_once __DIR__ . '/../config/config.php';
requireAdminCapability('settings.manage');


if (empty($_SESSION['admin_logged_in'])) {
    header('Location: ' . SITE_URL . '/admin/login.php');
    exit;
}

$activeTab = (isset($_GET['tab']) && $_GET['tab'] === 'settings') ? 'settings' : 'stock';

// ── Store General Settings: persistence (simple key-value table) ──────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `store_settings` (
        `setting_key` VARCHAR(100) NOT NULL PRIMARY KEY,
        `setting_value` TEXT DEFAULT NULL,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (PDOException $e) {}

$storeSettingDefaults = [
    'site_name'             => 'Dievon',
    'site_logo'             => '',   // relative path; blank = use the bundled default
    'contact_email'         => 'hello@dievon.com',
    'contact_phone'         => SHOP_PHONE,
    // ── Social profiles ─────────────────────────────────────────────────────
    // The footer draws an icon only for a profile that actually exists — an
    // Instagram icon pointing at /contact is a lie a shopper spots immediately.
    // These lived only in .env, which meant editing a file over FTP to add a
    // link; they are settings, so they belong in Settings.
    // .env (SHOP_SOCIAL_*) still works and is used when a field here is blank,
    // so nothing breaks on an install that already set them there.
    'social_instagram'      => '',
    'social_facebook'       => '',
    'social_youtube'        => '',
    'social_pinterest'      => '',
    'social_whatsapp'       => '',   // digits only, with country code: 919876543210
    // Invoice / GST identity. includes/invoice_template.php reads these and will
    // only print "TAX INVOICE" once a GSTIN exists AND the line items carry HSN
    // codes and rates — otherwise it prints "ORDER SUMMARY", because issuing an
    // incorrect tax invoice is a worse problem than not issuing one.
    'legal_entity_name'     => '',
    'seller_gstin'          => '',
    'invoice_prefix'        => 'DI',
    'seller_address'        => '',
    'seller_state'          => '',
    // Whether displayed prices already include GST (standard Indian retail).
    // Store-wide on purpose: every product mirrors this choice, and the product
    // form shows it read-only so no product can disagree with the store.
    'price_includes_gst'    => '1',
    // ── Identity published in the policy pages ──────────────────────────────
    // The privacy notice and the terms have to say who the customer is dealing
    // with and where to complain. Those pages read these values rather than
    // carrying invented ones, and show a visible notice where a value is blank —
    // publishing a made-up registration number would be worse than publishing none.
    'company_cin'           => '',   // blank if the business is not incorporated
    'jurisdiction_city'     => '',   // whose courts govern disputes
    'courier_partners'      => '',   // e.g. "Blue Dart, Delhivery, India Post"
    // ── What the refund email promises the customer ─────────────────────────
    // A promise about someone's money should be changeable without editing PHP.
    // Two of them, because the two refund routes are genuinely different: an
    // online refund is handed to the bank and waits on THEM, while a COD refund
    // is a person in this shop sending money, and only the shop knows how long
    // that realistically takes.
    // The most of one item a shopper may put in the bag. Stock already caps a
    // tracked product; this is the ceiling for everything else, including a
    // product with tracking switched off. 0 disables it.
    'max_qty_per_item'      => '10',
    'refund_timing_online'  => '3–5 business days',
    'refund_timing_manual'  => '3–5 working days',
    // The Consumer Protection (E-Commerce) Rules, 2020 require an e-commerce
    // business to publish a named grievance officer with contact details, to
    // acknowledge a complaint within 48 hours and resolve it within one month.
    'grievance_officer_name'  => '',
    'grievance_officer_email' => '',
    'grievance_officer_phone' => '',
    // Selling within India for now; home_country switches the whole shipping-zone
    // rule (see detectShippingZone() in config/config.php) when that changes.
    'default_currency'      => 'INR',
    // Off until the shop sells outside India. Turning this on shows shoppers the
    // currency picker; it is read by currencySwitcherEnabled() in config/config.php,
    // so expanding abroad is this tick-box rather than a code change + deploy.
    // 'enable_currency_switcher' removed — nothing ever read it. The header picker
    // is driven by countrySelectorEnabled(), which is true when more than one
    // country is enabled. See the note where its field used to be, further down.
    'home_country'          => 'IN',
    'min_order_value'       => '0',      // 0 = no minimum
    'free_shipping_min'     => '2000',
    'standard_shipping_fee' => '99',
    'international_shipping_fee' => '2500',
    /* '' = follow the Countries screen, which is how this behaved before the
       setting had a field. '0' refuses every foreign address whatever Countries
       says; '1' allows them. See the note on the field itself for why the lock
       is worth having. */
    'ships_internationally'      => '',
    /* ── Shiprocket ──────────────────────────────────────────────────────────
       pickup_location is blank by design. Shiprocket matches it against the
       pickup addresses registered in the account, and an invented default is
       refused at booking time with a message that does not explain itself.
       A blank field the owner must fill is better than a plausible one that
       fails on a real customer's order.

       The parcel figures ARE seeded, because a booking cannot be made without
       them and every product here has an empty weight. 0.4kg / 30x25x5cm suits
       womenswear packed flat in a poly mailer — a starting point, not a
       measurement. Shiprocket bills on actual or volumetric weight, whichever
       is greater, and re-weighs at the hub. */
    'shiprocket_pickup_location'   => '',
    'shiprocket_default_city'      => '',
    'shiprocket_default_state'     => '',
    'default_parcel_weight_kg'     => '0.4',
    'default_parcel_dimensions_cm' => '30x25x5',
    // ── COD guardrails ──────────────────────────────────────────────────────
    // Every order on this shop is Cash on Delivery, and none of these rules
    // existed before: no cap (an order of any size could go out for cash
    // collection), no handling fee, and a phone of "123456" passed the
    // 6-character length check. checkout_action.php enforces all three.
    'cod_max_order_value'   => (string)COD_MAX_ORDER_DEFAULT,   // 0 = no cap
    'cod_fee'               => (string)COD_FEE_DEFAULT,          // 0 = no fee
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = 'Security validation failed (Invalid CSRF token).';
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO store_settings (setting_key, setting_value) VALUES (:k, :v)
                ON DUPLICATE KEY UPDATE setting_value = :v2");
            foreach (array_keys($storeSettingDefaults) as $key) {
                // site_logo is set by the upload below, never by a text field —
                // skip it here so an absent POST value cannot blank the saved logo.
                if ($key === 'site_logo') { continue; }

                // A field that was not submitted is left ALONE, not blanked.
                //
                // `trim($_POST[$key] ?? '')` wrote an empty string for every key
                // the POST happened not to carry, so a partial submission — a
                // form served before a new setting existed, a request that lost
                // fields, a tab left open across an update — silently erased the
                // entire shop configuration: shop name, delivery charges, GST
                // details, contact address, the lot. The site_logo guard below
                // already recognised this exact hazard for one key; it applies to
                // all of them.
                if (!array_key_exists($key, $_POST)) { continue; }

                $val = trim($_POST[$key] ?? '');

                /* Money and quantity settings cannot be negative.
                   ────────────────────────────────────────────────────────────
                   Every value was stored exactly as typed, with no check of any
                   kind. The number inputs carry min="0", but that is a browser
                   hint — a crafted POST ignores it, and so does any request that
                   does not come from this form.

                   A negative cod_fee is the one that matters: it is added to the
                   order total at actions/checkout_action.php:423, so -500 would
                   take 500 OFF every cash-on-delivery order. The two shipping
                   fees behave the same way. A shop can be given away through a
                   settings field that was never meant to hold a minus sign.

                   Clamped rather than rejected: a negative here is a slip or an
                   attack, and zero is the safe reading of both. */
                $nonNegative = [
                    'free_shipping_min', 'standard_shipping_fee', 'international_shipping_fee',
                    'cod_max_order_value', 'cod_fee', 'min_order_value', 'max_qty_per_item',
                ];
                if (in_array($key, $nonNegative, true) && $val !== '') {
                    $val = (string)max(0, (float)$val);
                }

                $stmt->execute(['k' => $key, 'v' => $val, 'v2' => $val]);
            }

            // ── Brand image upload (header logo) ────────────────────────────
            // Stored as a path in store_settings and read everywhere via
            // siteLogoUrl(), so one upload updates the header, footer and invoice
            // at once.
            //
            // The tab icons used to be uploaded here too. They are a deployed
            // asset now — assets/images/logo/favicon.png and favicondark.png — and
            // siteFaviconUrl() reads nothing else, so offering an upload that the
            // page would then ignore would be worse than not offering one.
            $brandUploads = [
                'site_logo' => [
                    'prefix' => 'logo',
                    'exts'   => ['png', 'jpg', 'jpeg', 'webp', 'svg'],
                    'mimes'  => ['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'],
                    'maxMb'  => 3,
                ],
            ];

            $logoNotice = '';
            foreach ($brandUploads as $key => $rules) {
                $short = $rules['prefix'];   // 'logo'

                if (!empty($_POST['remove_' . $short])) {
                    // Take the file with it. This used to blank the setting only,
                    // leaving the logo in assets/images/logo/ forever —
                    // a folder the Media Library does not even scan, so those
                    // orphans were invisible as well as unreachable.
                    $prev = $pdo->prepare("SELECT setting_value FROM store_settings WHERE setting_key = :k");
                    $prev->execute(['k' => $key]);
                    $prevPath = (string)$prev->fetchColumn();

                    $stmt->execute(['k' => $key, 'v' => '', 'v2' => '']);

                    if ($prevPath !== '') {
                        deleteUploadedFileIfUnused($pdo, $prevPath, __DIR__ . '/../assets/images/logo/');
                    }
                    $logoNotice = $short . 'removed';
                    continue;
                }
                if (empty($_FILES[$key]['name']) || $_FILES[$key]['error'] !== UPLOAD_ERR_OK) {
                    continue;
                }

                $file = $_FILES[$key];
                $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);

                if (!in_array($ext, $rules['exts'], true) || !in_array($mime, $rules['mimes'], true)) {
                    $logoNotice = $short . 'badtype';
                    continue;
                }
                if ($file['size'] > $rules['maxMb'] * 1024 * 1024) {
                    $logoNotice = $short . 'toobig';
                    continue;
                }

                $dir = __DIR__ . '/../assets/images/logo/';
                if (!is_dir($dir)) { mkdir($dir, 0755, true); }
                // Timestamped filename so browsers and CDNs pick the new file up
                // immediately instead of serving a cached one.
                // Random suffix as well as the timestamp: two uploads of the same
                // type within one second produced an identical name and silently
                // overwrote each other.
                $filename = $short . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                if (move_uploaded_file($file['tmp_name'], $dir . $filename)) {
                    // Shrink and recompress, exactly as every other upload screen in
                    // this admin does. This one did not, and the logo is the single
                    // most-requested image on the site — it loads on every page, for
                    // every visitor. A 1.8MB PNG straight off a designer's export
                    // would have been served untouched on all of them; the copies in
                    // uploads/_originals/logo are that size today.
                    //
                    // 1200px rather than the 2000 default: a header logo renders around
                    // 200px wide, so 1200 is already generous for a retina screen and
                    // there is nothing to gain from carrying more. Transparency
                    // survives — optimizeUploadedImage() preserves the alpha channel
                    // for PNG and WebP rather than filling it black, which matters
                    // here more than anywhere else on the site.
                    if (function_exists('optimizeUploadedImage')) {
                        optimizeUploadedImage($dir . $filename, 1200);
                    }
                    if (function_exists('generateWebpCopy')) {
                        generateWebpCopy($dir . $filename);
                    }

                    // What is this replacing?
                    $prev = $pdo->prepare("SELECT setting_value FROM store_settings WHERE setting_key = :k");
                    $prev->execute(['k' => $key]);
                    $prevPath = (string)$prev->fetchColumn();

                    $rel = 'assets/images/logo/' . $filename;
                    $stmt->execute(['k' => $key, 'v' => $rel, 'v2' => $rel]);

                    // Only after the setting points at the new file. Uploading a new
                    // logo previously left every earlier one behind — up to 3MB each.
                    if ($prevPath !== '' && basename($prevPath) !== $filename) {
                        deleteUploadedFileIfUnused($pdo, $prevPath, __DIR__ . '/../assets/images/logo/');
                    }
                    logAdminAction($_SESSION['admin_id'] ?? 1, 'update_' . $key, ucfirst($short) . " set to $filename");
                    $logoNotice = $short . 'saved';
                } else {
                    $logoNotice = $short . 'failed';
                }
            }

            header('Location: settings.php?tab=settings&saved=1' . ($logoNotice ? '&logo=' . $logoNotice : ''));
            exit;
        } catch (PDOException $e) {
            $errorMsg = 'Error saving settings: ' . $e->getMessage();
        }
    }
}

$storeSettings = $storeSettingDefaults;
try {
    $rows = $pdo->query("SELECT setting_key, setting_value FROM store_settings")->fetchAll();
    foreach ($rows as $r) {
        if (array_key_exists($r['setting_key'], $storeSettings)) {
            $storeSettings[$r['setting_key']] = $r['setting_value'];
        }
    }
} catch (PDOException $e) {}

// Labels follow the configured currency — they were hardcoded to £ while the store
// was set to sell in ₹, which made every shipping figure read as the wrong money.
$currencySymbol = $GLOBALS['CURRENCY_RATES'][$storeSettings['default_currency']]['symbol']
                  ?? ($storeSettings['default_currency'] ?? '');

// Safe to emit the page now — nothing below this line redirects.
require_once __DIR__ . '/includes/header.php';
?>
        
        <div class="glass-panel" style="padding:24px; overflow:hidden;">

            <?php if ($activeTab === 'settings'): ?>
            <!-- ══ STORE CONFIGURATION TAB ══ -->
            <div style="max-width: 800px; margin: 0 auto; padding: 20px 0;">
                <h3 style="font-size:20px; font-weight:700; margin-bottom:20px; color:var(--text-primary);"><i class="fa-solid fa-gears" style="color:var(--color-primary); margin-right:8px;"></i> Store General Settings</h3>
                <?php if (isset($_GET['saved'])): ?>
                    <div class="alert alert-success" style="margin-bottom:20px">✅ Store settings updated successfully!</div>
                <?php endif; ?>
                <form method="POST" action="settings.php?tab=settings" class="admin-form" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                    <input type="hidden" name="save_settings" value="1">

                    <?php
                    // Show the result of a logo upload. Without this a rejected file type
                    // or an oversized image would look identical to "nothing happened".
                    $logoMsgs = [
                        'logosaved'      => ['ok',  'Logo updated. It now appears in the header, footer and on invoices.'],
                        'logoremoved'    => ['ok',  'Custom logo removed — the default logo is back in use.'],
                        'logobadtype'    => ['err', 'That file type is not allowed. Use a PNG, JPG, WebP or SVG.'],
                        'logotoobig'     => ['err', 'That logo is larger than 3MB. Please compress it and try again.'],
                        'logofailed'     => ['err', 'The logo could not be written to assets/images/logo/. Check the folder is writable.'],
                    ];
                    $logoStatus = $_GET['logo'] ?? '';
                    $currentLogo = siteLogoUrl($pdo);
                    $usingCustom = trim((string)($storeSettings['site_logo'] ?? '')) !== '';
                    ?>
                    <?php if (isset($logoMsgs[$logoStatus])): ?>
                    <div class="settings-logo-status settings-logo-status--<?= $logoMsgs[$logoStatus][0] ?>">
                        <i class="fa-solid fa-<?= $logoMsgs[$logoStatus][0] === 'ok' ? 'circle-check' : 'triangle-exclamation' ?>"></i>
                        <?= htmlspecialchars($logoMsgs[$logoStatus][1]) ?>
                    </div>
                    <?php endif; ?>

                    <div class="settings-logo-row">
                        <div class="settings-logo-preview">
                            <img src="<?= htmlspecialchars($currentLogo) ?>?v=<?= time() ?>" alt="Current site logo" id="logoPreviewImg">
                        </div>
                        <div class="settings-logo-fields">
                            <label class="form-label">Header Logo</label>
                            <p class="settings-logo-hint">
                                Used in the site header, the footer and on invoices — upload once and it changes everywhere.
                                PNG, JPG, WebP or SVG, up to 3MB. A wide logo around 400&times;120px works best.
                                <?= $usingCustom ? '' : ' Currently using the bundled default.' ?>
                            </p>
                            <input type="file" name="site_logo" class="form-control settings-logo-input"
                                   accept="image/png,image/jpeg,image/webp,image/svg+xml"
                                   onchange="previewNewLogo(this,'logoPreviewImg',3)">
                            <?php if ($usingCustom): ?>
                            <label class="settings-logo-remove">
                                <input type="checkbox" name="remove_logo" value="1">
                                Remove custom logo and go back to the default
                            </label>
                            <?php endif; ?>
                        </div>
                    </div>

                    <script>
                    // Show the chosen file straight away, and enforce the same limits the
                    // server does so a bad file is rejected before the upload happens.
                    function previewNewLogo(input, targetId, maxMb){
                        const f = input.files && input.files[0];
                        if (!f) return;
                        // .ico often reports an empty or non-standard MIME, so accept it by
                        // extension rather than rejecting a perfectly valid logo.
                        const isIco = /\.ico$/i.test(f.name);
                        const ok = ['image/png','image/jpeg','image/webp','image/svg+xml',
                                    'image/x-icon','image/vnd.microsoft.icon'];
                        if (!isIco && !ok.includes(f.type)) {
                            (window.dievonAlert || alert)('Please choose a PNG, JPG, WebP, SVG or ICO image.');
                            input.value = ''; return;
                        }
                        if (f.size > maxMb * 1024 * 1024) {
                            (window.dievonAlert || alert)('That file is larger than ' + maxMb + 'MB. Please compress it first.');
                            input.value = ''; return;
                        }
                        const el = document.getElementById(targetId);
                        if (el) el.src = URL.createObjectURL(f);
                    }
                    </script>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px;">
                        <div class="form-group">
                            <label class="form-label">Store Brand Name</label>
                            <input type="text" name="site_name" class="form-control" value="<?= htmlspecialchars($storeSettings['site_name']) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Customer Support Email</label>
                            <input type="email" name="contact_email" class="form-control" value="<?= htmlspecialchars($storeSettings['contact_email']) ?>">
                        </div>
                        <?php // Full profile URLs. The footer renders an icon only for a
                              // field that is filled in, so a blank one simply does not
                              // appear rather than pointing somewhere wrong. ?>
                        <div class="form-group">
                            <label class="form-label">Instagram URL</label>
                            <input type="url" name="social_instagram" class="form-control" placeholder="https://instagram.com/yourbrand" value="<?= htmlspecialchars($storeSettings['social_instagram']) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Facebook URL</label>
                            <input type="url" name="social_facebook" class="form-control" placeholder="https://facebook.com/yourbrand" value="<?= htmlspecialchars($storeSettings['social_facebook']) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">YouTube URL</label>
                            <input type="url" name="social_youtube" class="form-control" placeholder="https://youtube.com/@yourbrand" value="<?= htmlspecialchars($storeSettings['social_youtube']) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Pinterest URL</label>
                            <input type="url" name="social_pinterest" class="form-control" placeholder="https://pinterest.com/yourbrand" value="<?= htmlspecialchars($storeSettings['social_pinterest']) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">WhatsApp number</label>
                            <input type="text" name="social_whatsapp" class="form-control" placeholder="919876543210" value="<?= htmlspecialchars($storeSettings['social_whatsapp']) ?>">
                            <small style="color:var(--text-muted); font-size:11.5px;">Country code and number, digits only — no +, no spaces. Shows the WhatsApp button across the site.</small>
                        </div>
                        <div class="form-group" style="grid-column:1 / -1;">
                            <?php // These four drive includes/invoice_template.php. Until a GSTIN is
                                  // present AND products carry HSN codes and GST rates, every printed
                                  // document says "ORDER SUMMARY" rather than "TAX INVOICE" — an
                                  // invoice that claims tax it cannot substantiate is worse than none. ?>
                            <div class="gst-note">
                                <strong>Invoice &amp; GST details.</strong>
                                Fill these in to turn printed receipts into proper <strong>tax invoices</strong>.
                                Each product also needs its HSN code and GST rate (Products &rarr; edit &rarr; Tax).
                                <?php if (trim((string)($storeSettings['seller_gstin'] ?? '')) === ''): ?>
                                    <br><span class="gst-note-warn">Currently printing as “Order Summary” — no GSTIN recorded.</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Legal Entity Name <span class="form-hint">as registered</span></label>
                            <input type="text" name="legal_entity_name" class="form-control"
                                   placeholder="e.g. Dievon Fashions Pvt Ltd"
                                   value="<?= htmlspecialchars($storeSettings['legal_entity_name'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">GSTIN</label>
                            <input type="text" name="seller_gstin" class="form-control" maxlength="15"
                                   placeholder="15 characters, e.g. 24ABCDE1234F1Z5"
                                   value="<?= htmlspecialchars($storeSettings['seller_gstin'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Invoice Number Prefix</label>
                            <?php /* maxlength was 10, which allowed a 24-character invoice
                                      number. A tax invoice number may not exceed 16 characters
                                      (CGST Rule 46(b)) and "/2026-27/00014" already uses 14 of
                                      them, so the prefix has exactly two to spend. Letters and
                                      digits only — the rule permits no other characters here. */ ?>
                            <input type="text" name="invoice_prefix" class="form-control"
                                   maxlength="<?= (int)INVOICE_PREFIX_MAX_LEN ?>"
                                   pattern="[A-Za-z0-9]{1,<?= (int)INVOICE_PREFIX_MAX_LEN ?>}"
                                   title="<?= (int)INVOICE_PREFIX_MAX_LEN ?> letters or digits, e.g. DV"
                                   placeholder="e.g. DV"
                                   value="<?= htmlspecialchars(invoicePrefix($pdo)) ?>">
                            <p class="form-hint">Prepended to the financial year and sequence — e.g. <code><?= htmlspecialchars(invoicePrefix($pdo)) ?>/2026-27/00014</code>.
                               <?= (int)INVOICE_PREFIX_MAX_LEN ?> letters or digits: a tax invoice number cannot exceed <?= (int)GST_INVOICE_NUMBER_MAX_LEN ?> characters.</p>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Price Includes GST</label>
                            <select name="price_includes_gst" class="form-control">
                                <?php // An empty/missing stored value means the default: inclusive. Only an
                                      // explicit '0' selects the exclusive option, so the dropdown always agrees
                                      // with what checkout_action.php will do. ?>
                                <?php $gstInclusiveStored = ((string)($storeSettings['price_includes_gst'] ?? '1')) !== '0'; ?>
                                <option value="1" <?= $gstInclusiveStored ? 'selected' : '' ?>>Yes — selling prices already include GST</option>
                                <option value="0" <?= !$gstInclusiveStored ? 'selected' : '' ?>>No — GST is added on top at checkout</option>
                            </select>
                            <span class="form-hint">Store-wide: every product form shows this read-only, so no single product can disagree.</span>
                        </div>
                        <div class="form-group" style="grid-column:1 / -1;">
                            <label class="form-label">Registered Address <span class="form-hint">printed on every invoice, and used as the return address</span></label>
                            <textarea name="seller_address" class="form-control" rows="2"
                                      placeholder="Street, area, city, state, PIN"><?= htmlspecialchars($storeSettings['seller_address'] ?? '') ?></textarea>
                        </div>
                        <div class="form-group">
                            <label class="form-label">State <span class="form-hint">decides CGST+SGST vs IGST</span></label>
                            <input type="text" name="seller_state" class="form-control"
                                   placeholder="e.g. Gujarat"
                                   value="<?= htmlspecialchars($storeSettings['seller_state'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">CIN <span class="form-hint">leave blank if not incorporated</span></label>
                            <input type="text" name="company_cin" class="form-control" maxlength="21"
                                   placeholder="21 characters, e.g. U18101GJ2024PTC123456"
                                   value="<?= htmlspecialchars($storeSettings['company_cin'] ?? '') ?>">
                        </div>

                        <?php // ── Published in the privacy notice and the terms ────────────
                              // Those pages state who a customer is dealing with and where to
                              // complain. They read these values and print a visible notice
                              // where one is blank, rather than inventing a number. ?>
                        <div class="form-group" style="grid-column:1 / -1;">
                            <h4 class="settings-subhead">Published in your policy pages</h4>
                            <p class="settings-hint">
                                Your privacy notice and terms have to name the business, say where disputes are
                                heard, and give a grievance officer. The Consumer Protection (E-Commerce) Rules,
                                2020 require a named officer with contact details, a reply within 48 hours and a
                                resolution within a month. Anything left blank shows as a gap on the live pages.
                            </p>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Courts / jurisdiction city</label>
                            <input type="text" name="jurisdiction_city" class="form-control"
                                   placeholder="e.g. Surat, Gujarat"
                                   value="<?= htmlspecialchars($storeSettings['jurisdiction_city'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Courier partners <span class="form-hint">named in the privacy notice</span></label>
                            <input type="text" name="courier_partners" class="form-control"
                                   placeholder="e.g. Blue Dart, Delhivery, India Post"
                                   value="<?= htmlspecialchars($storeSettings['courier_partners'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Max quantity per item <span class="form-hint">how many of one piece a shopper may bag</span></label>
                            <input type="number" name="max_qty_per_item" class="form-control" min="0" step="1"
                                   placeholder="10"
                                   value="<?= htmlspecialchars($storeSettings['max_qty_per_item'] ?? '') ?>">
                            <small class="settings-hint">Stock still caps anything you count. This is the limit for the rest &mdash; 0 removes it.</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Refund timing — paid online <span class="form-hint">printed in the refund email</span></label>
                            <input type="text" name="refund_timing_online" class="form-control"
                                   placeholder="3–5 business days"
                                   value="<?= htmlspecialchars($storeSettings['refund_timing_online'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Refund timing — cash / COD <span class="form-hint">how long YOU take to send it back</span></label>
                            <input type="text" name="refund_timing_manual" class="form-control"
                                   placeholder="3–5 working days"
                                   value="<?= htmlspecialchars($storeSettings['refund_timing_manual'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Grievance officer — name</label>
                            <input type="text" name="grievance_officer_name" class="form-control"
                                   placeholder="A real person's name"
                                   value="<?= htmlspecialchars($storeSettings['grievance_officer_name'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Grievance officer — email</label>
                            <input type="email" name="grievance_officer_email" class="form-control"
                                   placeholder="e.g. grievance@dievon.com"
                                   value="<?= htmlspecialchars($storeSettings['grievance_officer_email'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Grievance officer — phone</label>
                            <input type="text" name="grievance_officer_phone" class="form-control"
                                   placeholder="e.g. +91 999 000 9992"
                                   value="<?= htmlspecialchars($storeSettings['grievance_officer_phone'] ?? '') ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Contact Phone / Concierge</label>
                            <input type="text" name="contact_phone" class="form-control" value="<?= htmlspecialchars($storeSettings['contact_phone']) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Default Store Currency</label>
                            <select name="default_currency" class="form-control">
                                <?php foreach ($GLOBALS['CURRENCY_RATES'] as $code => $cdata): ?>
                                <option value="<?= htmlspecialchars($code) ?>" <?= $storeSettings['default_currency'] === $code ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cdata['name'] ?? $code) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="settings-hint">The one currency the shop prices and charges in.</small>
                        </div>
                        <?php /* The "Let Shoppers Choose Their Currency" switch was here.
                                 ────────────────────────────────────────────────────────────────
                                 Removed because nothing read it. `enable_currency_switcher` was
                                 written by this form and by the installer, and no page, template
                                 or script ever consulted it — the box could be ticked or cleared
                                 and the site behaved identically. A control that does nothing is
                                 worse than a missing one: it invites someone to flip it, see no
                                 change, and start looking for a fault that is not there.

                                 Its warning had also gone stale. It cautioned that exchange rates
                                 in config/config.php would be out of date, but every rate in that
                                 table is 1.00 and deliberately unused — prices are STORED per
                                 country now rather than converted, so nothing is multiplied.

                                 What genuinely governs the picker is countrySelectorEnabled()
                                 (config/config.php:1433): it returns true when more than one
                                 country is enabled. So the picker turns itself on and off as a
                                 consequence of where you sell, and cannot contradict it. */ ?>
                        <div class="form-group">
                            <label class="form-label">Shopper currency</label>
                            <small class="settings-hint">
                                There is no switch for this. The country and currency picker appears in the
                                header automatically once <strong>more than one country is enabled</strong>,
                                and disappears when only one is left &mdash; manage that under
                                <strong>Countries</strong>. Each country carries its own prices, so nothing
                                is converted at an exchange rate.
                            </small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Minimum Order Value (<?= htmlspecialchars($currencySymbol) ?>)</label>
                            <input type="number" name="min_order_value" class="form-control" min="0" value="<?= htmlspecialchars($storeSettings['min_order_value']) ?>">
                            <small class="settings-hint">Orders below this are refused at checkout. Set to 0 for no minimum.</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Free Shipping Over (<?= htmlspecialchars($currencySymbol) ?>)</label>
                            <input type="number" name="free_shipping_min" class="form-control" value="<?= htmlspecialchars($storeSettings['free_shipping_min']) ?>" disabled>
                            <?php /* Same as the shipping fee above: the country row wins. */ ?>
                            <small class="settings-hint">Set this under <a href="countries.php">Countries We Sell To</a>. Shown here for reference only.</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Ship From (Home Country)</label>
                            <select name="home_country" class="form-control">
                                <?php foreach (['IN' => 'India — domestic = 6-digit PIN', 'GB' => 'United Kingdom — domestic = UK postcode'] as $cc => $lbl): ?>
                                <option value="<?= $cc ?>" <?= ($storeSettings['home_country'] ?? 'IN') === $cc ? 'selected' : '' ?>><?= $lbl ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="settings-hint">Decides which postcodes count as domestic. Everything else is treated as international, and Cash on Delivery is hidden for those orders.</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Domestic Shipping Fee (<?= htmlspecialchars($currencySymbol) ?>)</label>
                            <input type="number" name="standard_shipping_fee" class="form-control" value="<?= htmlspecialchars($storeSettings['standard_shipping_fee']) ?>" disabled>
                            <?php /* Disabled, not deleted. shippingCostForZone() now reads the home
                                     country's row first, so this box stopped deciding anything the
                                     moment Countries became the single source — and a field that
                                     silently does nothing is worse than one that is not there.
                                     Kept visible so the number is still findable, and because it is
                                     the fallback if the countries table is ever missing. */ ?>
                            <small class="settings-hint">Set this under <a href="countries.php">Countries We Sell To</a> &mdash; the country row decides what is charged. Shown here for reference only.</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">International Shipping Fee (<?= htmlspecialchars($currencySymbol) ?>)</label>
                            <input type="number" name="international_shipping_fee" class="form-control" value="<?= htmlspecialchars($storeSettings['international_shipping_fee'] ?? '2500') ?>">
                            <small class="settings-hint">Flat rate for addresses outside the home country. The free-shipping threshold never applies to these.</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Deliver Outside <?= htmlspecialchars(homeCountryRow()['name'] ?? 'India') ?>?</label>
                            <?php
                            /* A lock, because the automatic answer has a trap in it.
                               ────────────────────────────────────────────────────
                               shipsInternationally() is true as soon as ANY second
                               country is enabled under Countries — and that screen
                               exists mainly to set PRICES for other countries. So
                               enabling one to quote in pounds also, silently, starts
                               accepting delivery addresses in Britain: the checkout
                               takes the order, GST is added to what is legally an
                               export, and Razorpay still settles it in rupees.

                               "Never" makes that impossible whatever Countries says.
                               Leave it on Automatic to keep the behaviour this shop
                               has always had. */
                            $shipIntl = (string)($storeSettings['ships_internationally'] ?? '');
                            ?>
                            <select name="ships_internationally" class="form-control">
                                <option value=""  <?= $shipIntl === ''  ? 'selected' : '' ?>>Automatic &mdash; follow Countries We Sell To</option>
                                <option value="0" <?= $shipIntl === '0' ? 'selected' : '' ?>>Never &mdash; refuse addresses outside the home country</option>
                                <option value="1" <?= $shipIntl === '1' ? 'selected' : '' ?>>Yes &mdash; accept overseas addresses</option>
                            </select>
                            <small class="settings-hint">
                                On <strong>Automatic</strong> this turns itself on the moment a second country is
                                enabled under <a href="countries.php">Countries We Sell To</a> &mdash; even if you
                                enabled it only to show prices. Choose <strong>Never</strong> while you are not
                                ready to ship abroad.
                            </small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">COD Maximum Order Value (<?= htmlspecialchars($currencySymbol) ?>)</label>
                            <input type="number" name="cod_max_order_value" class="form-control" min="0"
                                   value="<?= htmlspecialchars($storeSettings['cod_max_order_value'] ?? '') ?>">
                            <small class="settings-hint">Orders above this are refused Cash on Delivery. Set to 0 for no cap.</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">COD Handling Fee (<?= htmlspecialchars($currencySymbol) ?>)</label>
                            <input type="number" name="cod_fee" class="form-control" min="0" step="0.01"
                                   value="<?= htmlspecialchars($storeSettings['cod_fee'] ?? '') ?>">
                            <small class="settings-hint">Added to the order total on Cash on Delivery orders. Set to 0 for none.</small>
                        </div>
                        <?php /* Shiprocket. Sits with the other shipping settings rather than on a
                                 screen of its own — a booking fails without these, and the person
                                 setting shipping fees is the person who knows them. */ ?>
                        <div class="form-group" style="grid-column:1 / -1;">
                            <label class="form-label">Shiprocket pickup location</label>
                            <input type="text" name="shiprocket_pickup_location" class="form-control"
                                   value="<?= htmlspecialchars($storeSettings['shiprocket_pickup_location'] ?? '') ?>"
                                   placeholder="e.g. Primary">
                            <small class="settings-hint">
                                Must match a pickup address in your Shiprocket account <strong>exactly</strong> —
                                Shiprocket &rsaquo; Settings &rsaquo; Company &rsaquo; Pickup Addresses, the
                                <em>nickname</em> column. Leave it wrong and every booking is refused.
                                Bookings will not run at all while this is empty.
                            </small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Warehouse city</label>
                            <input type="text" name="shiprocket_default_city" class="form-control"
                                   value="<?= htmlspecialchars($storeSettings['shiprocket_default_city'] ?? '') ?>" placeholder="e.g. Surat">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Warehouse state</label>
                            <input type="text" name="shiprocket_default_state" class="form-control"
                                   value="<?= htmlspecialchars($storeSettings['shiprocket_default_state'] ?? '') ?>" placeholder="e.g. Gujarat">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Default parcel weight (kg)</label>
                            <input type="text" name="default_parcel_weight_kg" class="form-control"
                                   value="<?= htmlspecialchars($storeSettings['default_parcel_weight_kg'] ?? '0.4') ?>" placeholder="0.4">
                            <small class="settings-hint">
                                Used for any product with no weight of its own — currently that is every product.
                                Shiprocket bills on actual or volumetric weight, whichever is greater, and re-weighs
                                at the hub, so an under-declared parcel is charged back to you.
                            </small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Default parcel size (cm)</label>
                            <input type="text" name="default_parcel_dimensions_cm" class="form-control"
                                   value="<?= htmlspecialchars($storeSettings['default_parcel_dimensions_cm'] ?? '30x25x5') ?>" placeholder="30x25x5">
                            <small class="settings-hint">Length x breadth x height. Accepts 30x25x5 or 30 x 25 x 5 cm.</small>
                        </div>
                    </div>
                    <?php // This used to warn that these values were "saved, but not yet wired
                          // into checkout". Both halves are now false: shippingCostForZone()
                          // reads them, and the contact email is used across the site. A stale
                          // warning is worse than none — it tells the owner their settings do
                          // nothing when they do. ?>
                    <p style="font-size:12px; color:var(--text-muted); margin-bottom:14px;">
                        <i class="fa-solid fa-circle-check"></i> These values are live. They set the shipping figure shown at
                        checkout and the amount actually charged, and the contact email is used across the site.
                    </p>
                    <button type="submit" class="btn-primary" style="padding:12px 28px;"><i class="fa-solid fa-floppy-disk"></i> Save Settings</button>
                </form>
            </div>
            <?php else: ?>
            <!-- ══ STOCK MANAGEMENT TAB ══ -->
            <?php
            $totalInStock = 0; $totalDamage = 0; $totalOffline = 0; $totalOnline = 0; $grandTotal = 0;
            foreach ($stockProducts as $sp) {
                $ts  = (int)($sp['total_stock']  ?? $sp['stock_qty'] ?? 0);
                $dmg = (int)($sp['damage_stock'] ?? 0);
                $off = (int)($sp['sold_offline'] ?? 0);
                $sol = (int)($sp['sold_online']  ?? 0);

                // Ask the same function the shop and the checkout ask.
                //
                // This counted the product-level total_stock column and ignored
                // size variants entirely, so it disagreed with the Products
                // screen on 7 of 11 items — one showed 25 here and 40 there.
                // Whichever the owner happened to be looking at, the other was
                // telling them something different about the same garment.
                // productSellableStock() is what decides whether a size can be
                // bought, so it is the figure that means anything.
                $sellable = function_exists('productSellableStock')
                    ? (int)productSellableStock($pdo, $sp)
                    : max(0, $ts - $dmg - $off - $sol);
                // PHP_INT_MAX is the "not tracked" answer and must never be
                // summed. Nor should it count as zero, which is what a first
                // attempt at this did — two products the owner simply had not
                // put counts against dropped out of the total and the Stock tab
                // understated the shop by 65 units. Untracked falls back to the
                // product-level figure, which is the only number there is.
                $totalInStock += ($sellable === PHP_INT_MAX)
                    ? max(0, $ts - $dmg - $off - $sol)
                    : max(0, $sellable);
                $totalDamage  += $dmg; $totalOffline += $off;
                $totalOnline  += $sol; $grandTotal   += $ts;
            }
            ?>
            <?php
            // Colour is carried by a 3px spine on each card (--stock-accent) rather
            // than by a tinted panel and an emoji. Same at-a-glance grouping, about
            // half the height, and it stops competing with the table underneath.
            $stockStats = [
                ['Grand Total',  $grandTotal,   'var(--color-primary)'],
                ['In Stock',     $totalInStock, 'var(--color-success)'],
                ['Damage',       $totalDamage,  'var(--color-danger)'],
                ['Sold Offline', $totalOffline, 'var(--color-secondary)'],
                ['Sold Online',  $totalOnline,  'var(--color-accent)'],
            ];
            ?>
            <div class="stock-summary">
                <?php foreach ($stockStats as [$label, $value, $accent]): ?>
                <div class="stock-stat" style="--stock-accent: <?= $accent ?>;">
                    <div class="stock-stat-value"><?= (int)$value ?></div>
                    <div class="stock-stat-label"><?= htmlspecialchars($label) ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if (empty($stockProducts)): ?>
            <div style="text-align:center; padding:60px 20px; color:var(--text-muted);">
                <div style="font-size:64px; margin-bottom:16px; opacity:0.3;">📦</div>
                <p style="font-size:16px;">No products yet. <a href="product_form.php" class="btn-primary" style="display:inline-flex; margin-left:10px;"><i class="fa-solid fa-plus"></i> Add Product</a></p>
            </div>
            <?php else: ?>

            <!-- Setup warning -->
            <?php if (!$stockMigrationDone || !$stockV2Done): ?>
            <div style="background:rgba(245,158,11,0.12); border:1px solid rgba(245,158,11,0.4); padding:14px 18px; margin-bottom:20px; font-size:13px; color:var(--text-secondary); display:flex; align-items:flex-start; justify-content:space-between; gap:16px; flex-wrap:wrap;">
                <div style="display:flex; align-items:flex-start; gap:12px;">
                    <i class="fa-solid fa-triangle-exclamation" style="color:#f59e0b; margin-top:2px; flex-shrink:0; font-size:16px;"></i>
                    <div><strong style="color:#f59e0b; font-size:14px;">Database setup required</strong><br>Some stock columns are missing. <a href="setup_stock_v3.php">Run Setup Now →</a></div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Info note -->
            <div style="background:rgba(139,92,246,0.08); border:1px solid rgba(139,92,246,0.2); padding:12px 16px; margin-bottom:20px; font-size:13px; color:var(--text-secondary); display:flex; align-items:flex-start; gap:10px;">
                <i class="fa-solid fa-circle-info" style="color:#8b5cf6; margin-top:2px; flex-shrink:0;"></i>
                <span><strong>Click ‘Edit Stock’ to add new stock, damage, or offline sales.</strong> Grand Total, In Stock, Damage and Sold columns are all read-only — updated when you save via the Edit button. Sold Online auto-counts when an order is marked Delivered.</span>
            </div>

            <?php
            /* Categories offered are the ones these products actually use, not a
               list of every category in the shop — an option that filters to
               nothing is a dead end the owner has to discover by clicking it. */
            $stockCats = array_values(array_unique(array_filter(
                array_map(static fn($r) => trim((string)($r['category'] ?? '')), $stockProducts)
            )));
            sort($stockCats);
            ?>
            <div class="stock-toolbar">
                <div class="stock-toolbar-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="search" id="stockSearch" class="form-control"
                           placeholder="Search by product name or SKU&hellip;" autocomplete="off">
                </div>

                <select id="stockStateFilter" class="form-control stock-toolbar-select">
                    <option value="all">All stock levels</option>
                    <option value="out">Out of stock</option>
                    <option value="low">Low stock (5 or fewer)</option>
                    <option value="in">In stock</option>
                    <option value="untracked">Not tracked</option>
                </select>

                <select id="stockCatFilter" class="form-control stock-toolbar-select">
                    <option value="all">All categories</option>
                    <?php foreach ($stockCats as $sc): ?>
                    <option value="<?= htmlspecialchars($sc) ?>"><?= htmlspecialchars($sc) ?></option>
                    <?php endforeach; ?>
                </select>

                <select id="stockSort" class="form-control stock-toolbar-select">
                    <?php /* Lowest first is the default on purpose: this screen exists to
                             answer "what am I about to run out of", and the answer should
                             be at the top without anyone choosing it. */ ?>
                    <option value="stock_asc">Stock: lowest first</option>
                    <option value="stock_desc">Stock: highest first</option>
                    <option value="name_asc">Name (A&ndash;Z)</option>
                    <option value="name_desc">Name (Z&ndash;A)</option>
                    <option value="total_desc">Goods received: most first</option>
                </select>

                <span class="stock-toolbar-count" id="stockCount"></span>
            </div>

            <div class="table-wrapper">
                <table class="data-table" id="stockTable">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th class="stock-th stock-hide-xs">Tracked</th>
                            <th class="stock-th stock-hide-xs">Grand Total<span class="stock-th-note">auto-cumulative</span></th>
                            <th class="stock-th">In Stock<span class="stock-th-note">auto-calculated</span></th>
                            <th class="stock-th stock-hide-sm">Damage<span class="stock-th-note">cumulative</span></th>
                            <th class="stock-th stock-hide-sm">Sold Offline<span class="stock-th-note">cumulative</span></th>
                            <th class="stock-th stock-hide-sm">Sold Online<span class="stock-th-note">auto on Delivered</span></th>
                            <th class="stock-th">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stockProducts as $sp):
                            $ts  = (int)($sp['total_stock']  ?? $sp['stock_qty'] ?? 0);
                            $dmg = (int)($sp['damage_stock'] ?? 0);
                            $off = (int)($sp['sold_offline'] ?? 0);
                            $sol = (int)($sp['sold_online']  ?? 0);

                            // The same figure the shop, the cart and the Products
                            // screen use — not this row's own arithmetic.
                            //
                            // This computed total_stock − damage − offline − online
                            // and ignored size variants entirely, so a product whose
                            // stock lives on its sizes read one number here and a
                            // different one on the Products screen: 9 of 21 tracked
                            // products disagreed. The summary card above this table
                            // was already fixed to ask productSellableStock(); the
                            // rows underneath it were still doing their own sum, so
                            // the card and its own rows could not be added up.
                            $rowSellable = function_exists('productSellableStock')
                                ? (int)productSellableStock($pdo, $sp)
                                : max(0, $ts - $dmg - $off - $sol);
                            // PHP_INT_MAX means "not tracked" — fall back to the
                            // product-level figure, the only number there is.
                            $ins = ($rowSellable === PHP_INT_MAX)
                                ? max(0, $ts - $dmg - $off - $sol)
                                : max(0, $rowSellable);
                        ?>
                        <?php
                        /* Everything the filter bar reads, on the row itself.
                           Computed here in PHP rather than scraped out of the
                           cells by JavaScript: the cells are formatted for a
                           person — thousands separators, badges, an em dash for
                           "not tracked" — and parsing them back into numbers is
                           how a filter starts disagreeing with the table it is
                           filtering. */
                        $rowTracked = !empty($sp['track_stock']);
                        $rowState   = !$rowTracked ? 'untracked'
                                    : ($ins <= 0 ? 'out' : ($ins <= 5 ? 'low' : 'in'));
                        ?>
                        <tr id="stock-row-<?= $sp['id'] ?>" class="stock-row"
                            data-name="<?= htmlspecialchars(mb_strtolower($sp['name'] ?? '')) ?>"
                            data-sku="<?= htmlspecialchars(mb_strtolower(productDisplayCode($sp))) ?>"
                            data-category="<?= htmlspecialchars($sp['category'] ?? '') ?>"
                            data-state="<?= $rowState ?>"
                            data-instock="<?= (int)$ins ?>"
                            data-total="<?= (int)$ts ?>">
                            <!-- Product -->
                            <td>
                                <div class="stock-product">
                                    <?php // The frame renders either way, so a product with no photo keeps
                                          // the column aligned instead of shrinking to a bare emoji. ?>
                                    <div class="stock-thumb">
                                        <?php if (!empty($sp['image'])): ?>
                                        <img src="<?= SITE_URL ?>/uploads/products/<?= htmlspecialchars($sp['image']) ?>"
                                             alt="" loading="lazy">
                                        <?php else: ?>
                                        <span class="stock-thumb-fallback"><?= htmlspecialchars($sp['emoji'] ?? '✨') ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="stock-product-text">
                                        <div class="stock-product-name" title="<?= htmlspecialchars($sp['name']) ?>"><?= htmlspecialchars($sp['name']) ?></div>
                                        <div class="stock-product-cat"><?= htmlspecialchars($sp['category'] ?: '—') ?></div>
                                    </div>
                                </div>
                            </td>
                            <!-- Tracked -->
                            <td class="stock-cell stock-hide-xs">
                                <?php if ($sp['track_stock']): ?>
                                <span class="stock-badge stock-badge-on">Tracked</span>
                                <?php else: ?>
                                <span class="stock-badge stock-badge-off">&#8734; Unlimited</span>
                                <?php endif; ?>
                            </td>
                            <!-- Grand Total (read-only) -->
                            <td class="stock-cell stock-hide-xs">
                                <span id="val-total_stock-<?= $sp['id'] ?>" class="stock-num<?= $ts === 0 ? ' stock-num-zero' : '' ?>"><?= $ts ?></span>
                            </td>
                            <!-- In Stock (auto, read-only) — the one figure worth emphasising -->
                            <td class="stock-cell">
                                <?php if ($sp['track_stock']): ?>
                                <span id="val-in_stock-<?= $sp['id'] ?>" class="stock-num stock-num-lead <?= $ins > 0 ? 'stock-num-good' : 'stock-num-bad' ?>"><?= $ins ?></span>
                                <?php else: ?>
                                <span class="stock-num stock-num-zero">—</span>
                                <?php endif; ?>
                            </td>
                            <!-- Damage (read-only) -->
                            <td class="stock-cell stock-hide-sm">
                                <span id="val-damage_stock-<?= $sp['id'] ?>" class="stock-num<?= $dmg === 0 ? ' stock-num-zero' : ' stock-num-bad' ?>"><?= $dmg ?></span>
                            </td>
                            <!-- Sold Offline (read-only) -->
                            <td class="stock-cell stock-hide-sm">
                                <span id="val-sold_offline-<?= $sp['id'] ?>" class="stock-num<?= $off === 0 ? ' stock-num-zero' : '' ?>"><?= $off ?></span>
                            </td>
                            <!-- Sold Online (auto, read-only) -->
                            <td class="stock-cell stock-hide-sm">
                                <span id="val-sold_online-<?= $sp['id'] ?>" class="stock-num<?= $sol === 0 ? ' stock-num-zero' : '' ?>"><?= $sol ?></span>
                            </td>
                            <!-- Actions -->
                            <td class="stock-cell">
                                <?php // Same 32px icon squares as every other admin row (products.php,
                                      // orders.php, blog.php …), rather than this screen's own mix of a
                                      // labelled btn-primary and an outline button at two different paddings. ?>
                                <div class="admin-actions" style="justify-content:center;">
                                    <?php if ($sp['track_stock']): ?>
                                    <button class="admin-action-btn is-primary" <?php /* The product's sizes travel with the click, so the dialog knows whether to
         ask which size and what each one currently holds. htmlspecialchars over the
         JSON because it lands inside a double-quoted HTML attribute — a size named
         with a quote would otherwise close the attribute, the same flaw that was
         printing stray markup on the products list. */ ?>
onclick="openStockEdit(<?= $sp['id'] ?>, '<?= htmlspecialchars(addslashes($sp['name'] ?? ''), ENT_QUOTES) ?>', <?= $ts ?>, <?= $dmg ?>, <?= $off ?>, <?= htmlspecialchars(json_encode(array_map(static function (array $v): array {
    // "S · Ivory Gold", not "S" — a product can hold the same size in several
    // colours, and the picker has to say which one you are about to change.
    $vSize   = trim((string)($v['size_code'] ?: $v['name']));
    $vColour = trim((string)($v['color_name'] ?? ''));
    $label   = $vColour !== '' ? $vSize . ' · ' . $vColour : $vSize;
    // A row the shop can no longer offer says so here, or its stock reads as
    // ordinary saleable stock while no shopper can ever reach it.
    if (isset($v['sellable']) && !$v['sellable']) { $label .= ' — NOT ON SALE'; }
    return [
        'id'    => (int)$v['id'],
        'label' => $label,
        'qty'   => (int)$v['stock_qty'],
    ];
}, productTrackedVariants($pdo, (int)$sp['id'])), JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)" title="Edit stock" aria-label="Edit stock for <?= htmlspecialchars($sp['name']) ?>">
                                        <i class="fa-solid fa-pen"></i>
                                    </button>
                                    <?php endif; ?>
                                    <a href="product_form.php?id=<?= $sp['id'] ?>" class="admin-action-btn" title="Edit product" aria-label="Edit product <?= htmlspecialchars($sp['name']) ?>">
                                        <?php /* Not a gear. A gear means "settings for this row", so it was
                                                 being clicked by someone expecting stock options — while this
                                                 link leaves the page entirely for the product editor. An arrow
                                                 says "this takes you somewhere else", which is what it does. */ ?>
                                        <i class="fa-solid fa-arrow-right"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="stock-empty-note" id="stockNoMatch" hidden>
                Nothing matches that. Clear the search or choose <strong>All stock levels</strong>.
            </p>

            <script>
            /* Search, filter and sort for the stock table.
               ─────────────────────────────────────────────────────────────────
               Done in the browser on rows already on the page, so a search does
               not cost a page load and does not lose the tab you are on. The
               table is the whole catalogue and the shop is small; when it is not,
               this becomes a query rather than a loop, and the row attributes it
               reads are already the right shape for that.

               Every value comes off data- attributes written by PHP. Reading the
               cells instead would mean parsing "1,240" and "—" back into numbers,
               which is how a filter starts disagreeing with its own table. */
            (function () {
                const table  = document.getElementById('stockTable');
                if (!table) { return; }
                const body   = table.querySelector('tbody');
                const rows   = Array.from(body.querySelectorAll('tr.stock-row'));
                const search = document.getElementById('stockSearch');
                const state  = document.getElementById('stockStateFilter');
                const cat    = document.getElementById('stockCatFilter');
                const sort   = document.getElementById('stockSort');
                const count  = document.getElementById('stockCount');
                const empty  = document.getElementById('stockNoMatch');

                const num = (r, a) => parseInt(r.getAttribute(a), 10) || 0;
                const txt = (r, a) => (r.getAttribute(a) || '');

                const ORDER = {
                    stock_asc:  (a, b) => num(a,'data-instock') - num(b,'data-instock'),
                    stock_desc: (a, b) => num(b,'data-instock') - num(a,'data-instock'),
                    name_asc:   (a, b) => txt(a,'data-name').localeCompare(txt(b,'data-name')),
                    name_desc:  (a, b) => txt(b,'data-name').localeCompare(txt(a,'data-name')),
                    total_desc: (a, b) => num(b,'data-total')  - num(a,'data-total')
                };

                function apply() {
                    const q  = (search.value || '').trim().toLowerCase();
                    const st = state.value;
                    const ct = cat.value;

                    let shown = 0;
                    rows.forEach(r => {
                        /* An untracked product has no stock level, so it can only
                           ever match its own option — never "out of stock", which
                           would otherwise sweep in every product that simply is
                           not counted. */
                        const okState = st === 'all' || txt(r,'data-state') === st;
                        const okCat   = ct === 'all' || txt(r,'data-category') === ct;
                        const okText  = !q || txt(r,'data-name').includes(q) || txt(r,'data-sku').includes(q);
                        const visible = okState && okCat && okText;
                        r.style.display = visible ? '' : 'none';
                        if (visible) { shown++; }
                    });

                    // Sorting the whole set, not only what is on screen, so the
                    // order is the same when a filter is cleared again.
                    const cmp = ORDER[sort.value] || ORDER.stock_asc;
                    rows.slice().sort(cmp).forEach(r => body.appendChild(r));

                    if (count) {
                        count.textContent = shown === rows.length
                            ? rows.length + ' product' + (rows.length === 1 ? '' : 's')
                            : shown + ' of ' + rows.length;
                    }
                    if (empty) { empty.hidden = shown !== 0; }
                }

                [search, state, cat, sort].forEach(el => {
                    if (!el) { return; }
                    el.addEventListener('input',  apply);
                    el.addEventListener('change', apply);
                });
                apply();
            })();
            </script>


            <?php
            /* Stock history.
               ────────────────────────────────────────────────────────────────
               recordStockMovement() has been writing every add, removal, damage
               and offline sale since the handler was fixed — and nothing could
               read it, which made it half a feature: a log with no reader is
               indistinguishable from no log at all.

               Read-only on purpose. This is the record you consult when a count
               is wrong and the only question that matters is what happened; a
               screen that can edit its own audit trail cannot answer that.

               Its own try/catch and a table-existence guard, because the table is
               created lazily on the first movement — a shop that has not moved any
               stock yet must not meet an error where a history should be. */
            $stockLog = [];
            try {
                $hasLog = (int)$pdo->query(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_movements'"
                )->fetchColumn();
                if ($hasLog) {
                    $stockLog = $pdo->query(
                        "SELECT m.created_at, m.delta, m.reason, m.note,
                                p.name AS product_name, v.size_code,
                                a.username AS admin_name
                           FROM stock_movements m
                      LEFT JOIN products         p ON p.id = m.product_id
                      LEFT JOIN product_variants v ON v.id = m.variant_id
                      LEFT JOIN admin_users      a ON a.id = m.created_by
                       ORDER BY m.id DESC LIMIT 40"
                    )->fetchAll(PDO::FETCH_ASSOC);
                }
            } catch (Throwable $e) { $stockLog = []; }

            $stockReasonLabels = [
                'received'          => 'Stock received',
                'damaged'           => 'Damaged',
                'sold_offline'      => 'Sold offline',
                'returned_supplier' => 'Returned to supplier',
                'lost'              => 'Lost or stolen',
                'correction'        => 'Miscount correction',
            ];
            ?>
            <div class="stock-log-panel">
                <h3 class="stock-log-title">Stock history</h3>
                <?php if (empty($stockLog)): ?>
                    <p class="stock-log-empty">
                        Nothing recorded yet. Every change made through <strong>Edit Stock</strong> is logged here
                        &mdash; what moved, which size, how many, why, and who did it.
                    </p>
                <?php else: ?>
                    <div class="table-wrapper">
                        <table class="data-table stock-log-table">
                            <thead>
                                <tr>
                                    <th>When</th><th>Product</th><th>Size</th>
                                    <th>Change</th><th>Reason</th><th>By</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stockLog as $m): ?>
                                    <?php $d = (int)$m['delta']; ?>
                                    <tr>
                                        <td><?= htmlspecialchars(date('d M y, H:i', strtotime((string)$m['created_at']))) ?></td>
                                        <td><?= htmlspecialchars((string)($m['product_name'] ?? 'deleted product')) ?></td>
                                        <td><?= htmlspecialchars((string)($m['size_code'] ?: '—')) ?></td>
                                        <td class="<?= $d > 0 ? 'stock-log-in' : 'stock-log-out' ?>">
                                            <?= $d > 0 ? '+' . $d : $d ?>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($stockReasonLabels[$m['reason']] ?? (string)$m['reason']) ?>
                                            <?php if (!empty($m['note'])): ?>
                                                <span class="stock-log-note"><?= htmlspecialchars((string)$m['note']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars((string)($m['admin_name'] ?? '—')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="stock-log-foot">Showing the 40 most recent movements.</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php endif; // End tab conditional ?>
        </div>

        <!-- Stock Edit Modal (incremental) -->
        <div id="stockEditModal" style="display:none; position:fixed; inset:0; z-index:9000; background:rgba(0,0,0,0.6); align-items:center; justify-content:center;">
            <?php /* Sized to fit the screen, not to fill it.
                     Five full-width blocks stacked vertically — each a large centred
                     number box with two lines of caption under it — ran past the bottom
                     of a laptop screen and pushed Save out of reach, on a dialog whose
                     whole job is four small numbers.

                     Now: a fixed header, a scrolling middle, and Save pinned to the
                     bottom where it can always be reached however tall the content gets.
                     The four quantities sit in a 2x2 grid because they are peers — the
                     stacked layout implied an order of importance that does not exist. */ ?>
            <div class="stk-dialog" style="background:var(--bg-surface); min-width:320px; max-width:460px; width:92%; max-height:88vh; display:flex; flex-direction:column; box-shadow:0 20px 60px rgba(0,0,0,0.4); position:relative; overflow:hidden;">
                <button onclick="closeStockEdit()" style="position:absolute; top:12px; right:14px; background:none; border:none; font-size:20px; color:var(--text-muted); cursor:pointer; line-height:1; z-index:2;">&#x2715;</button>

                <div style="padding:20px 24px 12px; border-bottom:1px solid var(--border-light); flex:0 0 auto;">
                    <h3 id="stockEditTitle" style="margin:0 0 3px; font-size:16px; padding-right:24px;">Edit Stock</h3>
                    <p id="stockEditSubtitle" style="font-size:11.5px; color:var(--text-muted); margin:0;">Leave a box at 0 to skip it.</p>
                </div>

                <div style="padding:16px 24px; overflow-y:auto; flex:1 1 auto;">

                <?php /* Size picker — hidden by the JS for products that have no sizes.
                         It has to exist because the shop sells from product_variants.stock_qty
                         whenever size rows are present, so "add 50" must say WHICH size the 50
                         belong to. Without it the handler cannot put stock anywhere a customer
                         can buy from, which is precisely how this screen used to behave: the
                         Grand Total rose and nothing became buyable. */ ?>
                <style>
                    /* Matches the lightbox already in admin/assets/css/style.css:1104 —
                       the overlay fades on opacity, the panel scales in on the same
                       cubic-bezier, and the @keyframes lightboxIn defined there is reused
                       rather than a second near-identical one being declared here.
                       This dialog was the only popup in the panel that simply snapped
                       from display:none to visible. */
                    #stockEditModal { opacity:0; pointer-events:none; transition:opacity .22s ease; }
                    #stockEditModal.is-open { opacity:1; pointer-events:auto; }
                    #stockEditModal.is-open .stk-dialog {
                        animation: lightboxIn .32s cubic-bezier(0.34,1.56,0.64,1);
                    }
                    /* Someone who has asked their system to stop animations gets the
                       dialog immediately, with no fade and no scale. */
                    @media (prefers-reduced-motion: reduce) {
                        #stockEditModal { transition:none; }
                        #stockEditModal.is-open .stk-dialog { animation:none; }
                    }

                    .stk-grid  { display:grid; grid-template-columns:1fr 1fr; gap:10px 12px; }
                    .stk-f     { margin:0; }
                    .stk-f label { display:block; font-size:11px; font-weight:700; letter-spacing:0.03em;
                                   text-transform:uppercase; margin-bottom:4px; }
                    .stk-f input, .stk-f select {
                        width:100%; padding:8px 10px; font-size:15px; font-weight:700; text-align:center;
                        border:1px solid var(--border-strong); background:var(--bg-surface);
                        color:var(--text-primary);
                    }
                    .stk-f select { font-size:12.5px; font-weight:600; text-align:left; }
                    .stk-hint  { font-size:10.5px; color:var(--text-muted); margin-top:3px; display:block; line-height:1.35; }
                    .stk-full  { grid-column:1 / -1; }
                    @media (max-width:420px) { .stk-grid { grid-template-columns:1fr; } }
                </style>

                <?php /* Size picker — the JS hides it for products with no sizes.
                         It must exist because the shop sells from product_variants.stock_qty
                         whenever size rows are present, so "add 50" has to say WHICH size.
                         Without it the handler cannot put stock anywhere a customer can buy
                         from — which is exactly how this screen used to behave: the Grand
                         Total rose and nothing became buyable. */ ?>
                <div class="stk-f stk-full" id="stockVariantRow" style="display:none; margin-bottom:14px;">
                    <label style="color:var(--color-primary);">📏 Which size</label>
                    <select id="stockVariantId"></select>
                </div>

                <div class="stk-grid">
                    <div class="stk-f">
                        <label style="color:#8b5cf6;">📦 Add</label>
                        <input type="number" id="stockAddQty" min="0" value="0" placeholder="0">
                        <span class="stk-hint">Goods received</span>
                    </div>

                    <?php /* Stock that leaves WITHOUT being sold or damaged. There was no way
                             to record this at all, so returning goods to a supplier left two bad
                             choices: call it damage, which makes the damage figure fiction, or an
                             offline sale, which invents revenue that gets filed with a tax
                             return. Most people would record neither, and the count drifts. */ ?>
                    <div class="stk-f">
                        <label style="color:#0f766e;">📤 Remove</label>
                        <input type="number" id="stockRemoveQty" min="0" value="0" placeholder="0">
                        <span class="stk-hint">Left unsold</span>
                    </div>

                    <div class="stk-f stk-full" id="stockRemoveReasonRow" style="display:none;">
                        <select id="stockRemoveReason">
                            <option value="">Why is it leaving?…</option>
                            <?php foreach (stockRemovalReasons() as $rk => $rl): ?>
                                <option value="<?= htmlspecialchars($rk) ?>"><?= htmlspecialchars($rl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="stk-f">
                        <label style="color:#ef4444;">⚠️ Damage</label>
                        <input type="number" id="stockDamageQty" min="0" value="0" placeholder="0">
                        <span class="stk-hint">Reduces In Stock</span>
                    </div>

                    <div class="stk-f">
                        <label style="color:#f59e0b;">🏪 Sold offline</label>
                        <input type="number" id="stockOfflineQty" min="0" value="0" placeholder="0">
                        <span class="stk-hint">Reduces In Stock</span>
                    </div>
                </div>

                </div><!-- /scrolling middle -->

                <?php /* Pinned footer. Save used to sit at the end of the content, so on a
                         laptop it fell below the fold and the dialog looked like it had no
                         way to confirm. Now the middle scrolls and this stays put. */ ?>
                <div style="padding:12px 24px 16px; border-top:1px solid var(--border-light); flex:0 0 auto; background:var(--bg-surface);">
                    <div id="stockEditMsg" style="font-size:12px; margin-bottom:8px; min-height:16px;"></div>
                    <div style="display:flex; gap:10px;">
                        <button class="btn-primary" onclick="saveStockEdit()" style="flex:1;"><i class="fa-solid fa-check"></i> Save</button>
                        <button class="btn-secondary" onclick="closeStockEdit()" style="flex:1;">Cancel</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════════════════ REVENUE TAB ═══════════════════ -->

<?php require_once 'includes/footer.php'; ?>
