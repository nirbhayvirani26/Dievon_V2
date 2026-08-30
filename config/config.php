<?php
// ============================================================
//  Dievon – Site Configuration
// ============================================================

// ── Session cookie hardening ────────────────────────────────
// MUST run before any session_start(). This file is required by index.php
// (the front controller) on line 12, ahead of every route, so setting the
// params here covers the whole public site. Direct hits to actions/*.php and
// admin/*.php start their own session before loading config, so .user.ini
// carries httponly/samesite for those entry points as a floor.
//
// samesite is deliberately 'Lax', NOT 'Strict': Razorpay redirects the
// customer back to us after payment, and a Strict cookie is withheld on that
// cross-site navigation — the session (and with it the cart and pending order)
// would be lost at the worst possible moment. Lax still blocks CSRF on POST
// while allowing that top-level return navigation.
//
// secure is conditional, not hardcoded: MAMP serves plain HTTP, and a secure
// cookie there is never sent back, which would silently break local login.
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? null) == 443)
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,   // blocks JS from reading PHPSESSID (XSS -> account takeover)
        'samesite' => 'Lax',
    ]);
}

/**
 * Host names that mean "this is the MAMP development machine".
 *
 * Deliberately the PORTED loopback forms only. A bare "localhost" or
 * "127.0.0.1" is left off the list: on the live server an attacker can send
 * any Host header they like, and a header of "localhost" must not be able to
 * flip the shop into its local-MAMP configuration, or make any link point at
 * "http://localhost". MAMP always serves on :8888, so that port is the test.
 */
const TRUSTED_LOCAL_HOSTS = ['localhost:8888', '127.0.0.1:8888'];

/** The one hostname this shop is meant to be indexed under. */
const CANONICAL_SITE_HOST = 'dievon.com';

/**
 * Is this request being served under the host the shop should be indexed as?
 *
 * trustedSiteHost() accepts every *.dievon.com, which is right for deciding
 * whether a Host header is FORGED — but wrong for deciding whether a crawler
 * should be invited in. www.dievon.com and any staging subdomain were serving
 * the whole catalogue with self-referencing canonicals and a robots.txt reading
 * "Allow: /" plus their own Sitemap: line — a complete, self-endorsing duplicate
 * of the shop, which is how a staging site ends up outranking production.
 *
 * Local development counts as canonical so robots.php behaves normally there.
 */
function isCanonicalSiteHost(?string $host = null): bool
{
    $host = strtolower(trim((string)($host ?? ($_SERVER['HTTP_HOST'] ?? ''))));
    return $host === CANONICAL_SITE_HOST || in_array($host, TRUSTED_LOCAL_HOSTS, true);
}

/** Is this host the local MAMP machine? */
function isLocalDevHost(string $host): bool
{
    return in_array($host, TRUSTED_LOCAL_HOSTS, true);
}

/**
 * The ONLY host names this site may call itself.
 *
 * HTTP_HOST is the visitor's claim, not a fact: anyone can send
 * "Host: evil.example" to the server, and the site used to trust it
 * verbatim on the live branch — SITE_URL, and with it every link in every
 * email (password resets included) and every redirect, was built on the
 * attacker's host. A genuine Dievon password-reset email could point at an
 * attacker's site, which would hand over the account the moment the victim
 * clicked.
 *
 * Anything not on the allowlist below is refused and the request is treated
 * as coming from the canonical domain, so a poisoned header can never
 * change where links lead.
 */
function trustedSiteHost(?string $claimed): string
{
    $claimed = strtolower(trim((string)$claimed));

    // Local development: MAMP serves the shop on http://localhost:8888/DievonOrders.
    if (in_array($claimed, TRUSTED_LOCAL_HOSTS, true)) {
        return $claimed;
    }

    // Production: dievon.com and every subdomain (stage., testing., www., …).
    // Accepting subdomains here is about rejecting a FORGED host, not about
    // which host should be indexed — see isCanonicalSiteHost() for that.
    if ($claimed === CANONICAL_SITE_HOST || str_ends_with($claimed, '.' . CANONICAL_SITE_HOST)) {
        return $claimed;
    }

    // A poisoned Host header. Fall back to the canonical production host.
    return CANONICAL_SITE_HOST;
}

// ── Error display ───────────────────────────────────────────
// Nothing in this codebase set display_errors, so PHP's own default decided
// whether a visitor saw a warning — and on the live host it was showing them.
// A single deprecation on the profile page printed
// "/home/<account>/public_html/pages/account.php on line 597" to the customer:
// the server's directory layout, handed to anyone who triggers a notice.
//
// Errors are still RECORDED, just not rendered. $isLocal is defined further
// down, so the host is re-derived here rather than depending on load order.
if (PHP_SAPI !== 'cli') {
    // Trusted, never the raw header — a poisoned Host must not be able to
    // flip the error-display switch (or anything else keyed on the host).
    $errHost = trustedSiteHost($_SERVER['HTTP_HOST'] ?? '');
    $errIsLocal = isLocalDevHost($errHost);

    error_reporting(E_ALL);
    ini_set('log_errors', '1');
    // On a developer machine, show them — that is the point of a dev machine.
    // Anywhere else, never.
    ini_set('display_errors', $errIsLocal ? '1' : '0');
    ini_set('display_startup_errors', $errIsLocal ? '1' : '0');
}

// ── Security response headers ───────────────────────────────
// These are also set in .htaccess, which is the right place for them — but
// under MAMP's mod_fastcgi the Apache header filter reaches static files and
// NOT PHP responses, so every actual page was served without a single one.
// Setting them here as well means they arrive whatever the host does with
// mod_headers, on MAMP and on LiteSpeed alike.
//
// headers_list() is consulted first so a server that DOES apply its own copy
// does not end up sending each header twice.
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    $alreadySent = [];
    foreach (headers_list() as $h) {
        $alreadySent[strtolower(trim(explode(':', $h, 2)[0]))] = true;
    }

    $securityHeaders = [
        // Stops a browser second-guessing Content-Type. Without it an uploaded
        // file sniffed as HTML can execute inside this site's own origin.
        'X-Content-Type-Options'      => 'nosniff',
        // Stops another site framing checkout and stealing clicks.
        'X-Frame-Options'             => 'SAMEORIGIN',
        'Referrer-Policy'             => 'strict-origin-when-cross-origin',
        'Permissions-Policy'          => 'camera=(), microphone=(), geolocation=(), interest-cohort=()',
        'Cross-Origin-Opener-Policy'  => 'same-origin',
    ];

    $onHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? null) == 443)
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    if ($onHttps) {
        // Only over a working HTTPS connection. Sending this on a domain whose
        // certificate is broken locks every visitor out for a year.
        $securityHeaders['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
    }

    foreach ($securityHeaders as $name => $value) {
        if (!isset($alreadySent[strtolower($name)])) { header("$name: $value"); }
    }

    // Announces the exact PHP version to anyone scanning for version-specific
    // exploits. expose_php=Off in php.ini is the other half of this.
    header_remove('X-Powered-By');
}

/**
 * Is an advertising tracker actually configured?
 *
 * Single source of truth for three places that must never disagree:
 *   - includes/header.php  decides whether to publish the pixel ID
 *   - includes/footer.php  decides whether to render the consent bar
 *   - pages/privacy.php    decides whether to describe advertising cookies
 *
 * If these ever drifted apart the site could load a tracker with no way to
 * refuse it, or show a consent bar that consents to nothing.
 */
function dievonPixelEnabled(): bool
{
    return defined('META_PIXEL_ID')
        && META_PIXEL_ID !== ''
        && META_PIXEL_ID !== 'YOUR_FACEBOOK_PIXEL_ID';
}

/**
 * Is any non-essential tracker configured on this site?
 *
 * Right now: no. Nothing is set, so nothing loads and no consent question is
 * asked — a consent banner in front of zero trackers is a click the shopper does
 * not owe anyone. The point of this function is what happens the day an ID IS
 * set: everything below keys off it, so the tracker cannot start firing quietly.
 */
function dievonTrackersConfigured(): bool
{
    return dievonPixelEnabled();
}

/**
 * Has this visitor agreed to non-essential cookies?
 *
 * Three states, and the difference matters:
 *   'granted' — they said yes. Trackers may load.
 *   'denied'  — they said no. Trackers must not load, and we must not ask again.
 *   null      — they have not been asked, or have cleared their cookies.
 *
 * A tracker may only load on 'granted'. Not asking yet is not permission.
 */
function dievonConsentState(): ?string
{
    $v = $_COOKIE['dievon_consent'] ?? null;
    return in_array($v, ['granted', 'denied'], true) ? $v : null;
}

/**
 * The single gate every analytics or advertising tag must pass through.
 *
 * The Meta Pixel block in includes/header.php used to test only whether an ID
 * existed, with a comment noting that a consent step "needs to go in front of it"
 * before the ID was set. That is exactly the kind of note that gets forgotten on
 * the day someone pastes an ID in. This makes it structural instead.
 */
function dievonMayLoadTrackers(): bool
{
    return dievonTrackersConfigured() && dievonConsentState() === 'granted';
}

/** Should the consent notice be shown on this request? */
function dievonNeedsConsentNotice(): bool
{
    // Only when there is genuinely something to consent to, and only until the
    // visitor has answered one way or the other.
    return dievonTrackersConfigured() && dievonConsentState() === null;
}

// Loaded here rather than further down because CONTACT_EMAIL (below) and the
// Razorpay keys both read from .env, and the constants are defined in file order.
require_once __DIR__ . '/EnvLoader.php';

define('SHOP_NAME',    'Dievon');
// The brand line, written once and used wherever the brand introduces itself:
// the footer, the About page and the footer of every transactional email.
//
// Deliberately NOT used as the homepage <title> — at 61 characters with the
// audience appended it overflowed the ~60 a search result shows, and it would
// have spent the most valuable line on the site on words nobody searches for.
// A title has to be found; a tagline only has to be read once you are already
// looking. dievonHomeTitle() handles the first job, this handles the second.
//
// Audience-neutral on purpose, so it never needs revisiting when menswear
// arrives — unlike the "Women's Fashion" version this replaced, which sat in
// the footer of all 21 emails including any sent to a man buying a shirt.
define('SHOP_TAGLINE', 'The New Expression of Indian Fashion');
define('SHOP_PHONE',   (string)EnvLoader::get('SHOP_PHONE', '+91 999 000 9992'));

// Real contact details live in .env. SHOP_WHATSAPP is the WhatsApp number in
// international format, digits only (no '+'). The old fallback '919876543210' was
// a dummy number — shoppers were chatting a stranger, so the WhatsApp buttons now
// render ONLY when SHOP_WHATSAPP is set. Same for social: icons render only when
// the matching SHOP_SOCIAL_* URL is set, instead of lying that /contact is the
// brand's Instagram/Facebook page. The number is stripped to digits here so a
// typo like '+91 98765 43210' can never produce a broken wa.me link.
define('SHOP_WHATSAPP', (string)preg_replace('/\D/', '', (string)EnvLoader::get('SHOP_WHATSAPP', '')));
define('SHOP_SOCIAL_INSTAGRAM', (string)EnvLoader::get('SHOP_SOCIAL_INSTAGRAM', ''));
define('SHOP_SOCIAL_FACEBOOK',  (string)EnvLoader::get('SHOP_SOCIAL_FACEBOOK', ''));
define('SHOP_SOCIAL_YOUTUBE',   (string)EnvLoader::get('SHOP_SOCIAL_YOUTUBE', ''));
define('SHOP_SOCIAL_PINTEREST', (string)EnvLoader::get('SHOP_SOCIAL_PINTEREST', ''));
define('SHOP_SOCIAL_TUMBLR',    (string)EnvLoader::get('SHOP_SOCIAL_TUMBLR', ''));
define('META_PIXEL_ID', (string)EnvLoader::get('META_PIXEL_ID', '')); // e.g. '1234567890'

// ── THE EMAIL SWITCH ────────────────────────────────────────
//  ONE line controls every address on the site and in every email we send:
//  the mailto: links on Contact / Privacy / Returns, the "reply to us at"
//  line inside order emails, and who admin notifications go to.
//
//  To switch, change the value below to whichever you want:
//      'hello@dievon.com'         <- the real business address (use once live)
//      'princevir2610@gmail.com'  <- your Gmail (use while testing)
//
//  Nothing else needs editing. Every page and email reads this constant.
//
//  .env overrides it without touching code, which is how local and live can
//  differ at the same time: .env is never uploaded, so your machine can keep
//  using Gmail while the live site uses hello@dievon.com. To do that, add
//  CONTACT_EMAIL=princevir2610@gmail.com to your local .env.
define('CONTACT_EMAIL', (string)EnvLoader::get('CONTACT_EMAIL', 'hello@dievon.com'));

// Where admin notifications land. Same address unless you deliberately split them.
define('ADMIN_EMAIL', CONTACT_EMAIL);

// ── Admin sign-in ───────────────────────────────────────────
// There is no admin password here any more, and there must never be one again.
//
// This file is uploaded to the server and lives inside the web root. A password
// written into it is a password on the server in plain text, readable by anyone
// who gets at the file, present in every backup and in every copy of the code.
// admin/login.php now authenticates only against the admin_users table, where
// passwords are stored as password_hash() digests.
//
// ADMIN_USERNAME stays because parts of the admin UI print it as a label, and
// update_new_database.php uses it to name the FIRST account when seeding an
// empty admin_users table. It is an identifier, not a credential — knowing it
// does not sign anybody in.
define('ADMIN_USERNAME', 'dievonadmin');

// ── Environment Auto-Detection (Local MAMP vs Live Hostinger) ──
// The host is the visitor's CLAIM, not a fact. It used to be trusted as-is:
// a request carrying "Host: evil.example" made SITE_URL — and with it every
// link in every email, including password resets — point at the attacker's
// site. trustedSiteHost() refuses anything outside the allowlist, so a
// poisoned header can only ever produce the canonical host.
$hostHeader = trustedSiteHost($_SERVER['HTTP_HOST'] ?? '');
$isLocal = isLocalDevHost($hostHeader) || php_sapi_name() === 'cli';

if ($isLocal) {
    // LOCAL / MAMP Credentials
    define('SITE_URL', 'http://localhost:8888/DievonOrders');
} else {
    /* LIVE. The protocol test must match the one used everywhere else in this file.
       ────────────────────────────────────────────────────────────────────────────
       This read $_SERVER['HTTPS'] alone. Behind a TLS-terminating proxy — which is
       the standard Hostinger and Cloudflare arrangement — PHP never sees HTTPS
       set, because TLS ends at the proxy and the request reaches PHP over plain
       HTTP with the real protocol in X-Forwarded-Proto. So SITE_URL was built as
       http:// on a site served entirely over https://, and SITE_URL is what every
       canonical tag, every <loc> in sitemap.php, the Sitemap: line in robots.php
       and every emailed link is made from.

       The .htaccess rule below then 301s each of those http:// URLs to https://,
       so the site declared a protocol it does not serve and pointed Google at a
       redirect from every canonical it published. Reproduced here: with
       X-Forwarded-Proto: https the canonical came back http://dievon.com/shop.

       Three other places in this same file already get this right —  the session
       cookie block at line 21, the secure-cookie test at 135, and
       includes/remember_me.php:49 — all testing HTTPS, SERVER_PORT 443 and
       X-Forwarded-Proto together. This is that same test, nothing new. */
    $isHttpsRequest = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? null) == 443)
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $protocol = $isHttpsRequest ? 'https://' : 'http://';
    define('SITE_URL', $protocol . $hostHeader);
}

// Database credentials live in .env (DB_HOST / DB_PORT / DB_NAME / DB_USER / DB_PASS),
// the same file as the Razorpay keys — so the file that ships with the source no
// longer carries the live password. The values below are env-specific fallbacks used
// only when .env is absent: local keeps its trivial MAMP defaults, live falls back to
// an empty password (connection fails loudly until DB_PASS is set in .env).
define('DB_HOST', (string)EnvLoader::get('DB_HOST', 'localhost'));
define('DB_PORT', (string)EnvLoader::get('DB_PORT', $isLocal ? '8889' : '3306'));
define('DB_NAME', (string)EnvLoader::get('DB_NAME', $isLocal ? 'dievonfashion' : 'u167013900_dievon'));
define('DB_USER', (string)EnvLoader::get('DB_USER', $isLocal ? 'root' : 'u167013900_dievonuser'));
define('DB_PASS', (string)EnvLoader::get('DB_PASS', $isLocal ? 'root' : ''));



// ── Razorpay Payment Keys ─────────────────────────────────────
// Get these from: https://dashboard.razorpay.com/app/keys
// Real values live in .env (never committed/backed up with the rest of the source) —
// these string literals are fallbacks only, used if .env has no override.
// Use TEST keys (rzp_test_... / test secret) while testing, LIVE keys when ready to launch.
// "Pay Online" at checkout stays disabled (falls back to Bank Wire/COD) until both are filled in.
require_once __DIR__ . '/EnvLoader.php';
define('RAZORPAY_KEY_ID',     (string)EnvLoader::get('RAZORPAY_KEY_ID',     'rzp_test_TJ7K15lBViA0Hw'));
define('RAZORPAY_KEY_SECRET', (string)EnvLoader::get('RAZORPAY_KEY_SECRET', '9JXgHibcZS9VgaPH5sAQl0kJ'));
// Separate secret for the webhook (Dashboard -> Settings -> Webhooks -> create one pointed
// at SITE_URL/actions/razorpay_webhook.php, subscribed to "payment.captured"). Empty by
// default — the webhook endpoint safely no-ops until this is set.
define('RAZORPAY_WEBHOOK_SECRET', (string)EnvLoader::get('RAZORPAY_WEBHOOK_SECRET', ''));

// ── Multi-Currency & Default Currency Configuration ──────────────────
define('DEFAULT_CURRENCY', 'INR');          // Default store currency (INR ₹)

$GLOBALS['CURRENCY_RATES'] = [
    // rate is 1.00 for every currency: prices are STORED per country now, not
    // converted, so multiplying here would corrupt them. Kept in the array shape
    // because formatPrice(), currencySymbol() and the "Default Store Currency"
    // dropdown in admin/settings.php all read it.
    'INR' => ['symbol' => '₹', 'rate' => 1.00, 'name' => 'INR (₹)'],
    'GBP' => ['symbol' => '£', 'rate' => 1.00, 'name' => 'GBP (£)'],
    'USD' => ['symbol' => '$', 'rate' => 1.00, 'name' => 'USD ($)'],
    'AED' => ['symbol' => 'AED ', 'rate' => 1.00, 'name' => 'AED']
];

/**
 * The one currency this shop trades in when the switcher is off.
 *
 * Reads Store Settings first so the shop currency is admin-editable, and only
 * falls back to the DEFAULT_CURRENCY constant when the database is unreachable
 * (or the settings table does not exist yet, e.g. mid-migration).
 */
function storeDefaultCurrency(): string {
    $pdo = $GLOBALS['pdo'] ?? null;
    $fallback = defined('DEFAULT_CURRENCY') ? DEFAULT_CURRENCY : 'INR';
    $c = strtoupper(trim((string)storeSetting($pdo instanceof PDO ? $pdo : null, 'default_currency', $fallback)));
    return isset($GLOBALS['CURRENCY_RATES'][$c]) ? $c : 'INR';
}

/**
 * The currency this shopper is being charged in right now.
 *
 * Currency follows the chosen COUNTRY once international selling is on — a
 * shopper picks "United Kingdom", not "GBP", via Countries We Sell To
 * (admin/countries.php); there is no separate currency-only switcher any
 * more. A shop with only its home country enabled always trades in
 * storeDefaultCurrency() — the "Default Store Currency" set in admin/settings.php.
 *
 * Declared before the country helpers in this file, so guard on the
 * function existing rather than assuming load order.
 */
function getCurrentCurrency() {
    if (function_exists('countrySelectorEnabled') && countrySelectorEnabled()) {
        $c = currentCountry();
        if ($c && !empty($c['currency_code'])) { return strtoupper($c['currency_code']); }
    }
    return storeDefaultCurrency();
}

/**
 * The Indian state a 6-digit PIN belongs to.
 *
 * Place of supply decides whether an invoice carries CGST+SGST (same state as
 * the seller) or IGST (different state). The orders table has no state column,
 * so without this every invoice defaulted to IGST — wrong for every local sale,
 * and wrong in a way that matters when the figures are filed.
 *
 * Derived from the PIN rather than asked for at checkout: the first two digits
 * already identify the circle, so adding a state dropdown would be one more
 * field to abandon a purchase over, for information the shopper has already
 * given.
 *
 * Returns '' when the PIN is not Indian or not recognised — the caller must
 * treat that as "unknown" and fall back to IGST rather than guessing.
 */
function indianStateForPin(?string $pin): string {
    $pin = preg_replace('/\D/', '', (string)$pin);
    if (strlen($pin) !== 6) { return ''; }
    $p3 = (int)substr($pin, 0, 3);
    $p  = (int)substr($pin, 0, 2);

    // Three-digit overrides for circles split across states: the 2-digit circle
    // is not enough for Goa (403 inside Maharashtra's 40-44), Sikkim (737 inside
    // West Bengal's 70-74), Uttarakhand (inside UP's 20-28), Puducherry (605
    // inside Tamil Nadu's 60-66), Chandigarh (160-161 inside Punjab's 14-16) and
    // the six North-Eastern states (all under the 79 circle).
    $overrides = [
        403 => 'Goa',
        737 => 'Sikkim',
        605 => 'Puducherry',
        160 => 'Chandigarh', 161 => 'Chandigarh',
        246 => 'Uttarakhand', 248 => 'Uttarakhand', 249 => 'Uttarakhand',
        262 => 'Uttarakhand', 263 => 'Uttarakhand',
        793 => 'Meghalaya', 794 => 'Meghalaya', 795 => 'Manipur', 796 => 'Mizoram',
        797 => 'Nagaland', 798 => 'Nagaland', 799 => 'Tripura',
        // Inside Telangana's 51 circle, Anantapur/Cuddapah/Chittoor/Kurnool are Andhra.
        515 => 'Andhra Pradesh', 516 => 'Andhra Pradesh', 517 => 'Andhra Pradesh',
        518 => 'Andhra Pradesh', 519 => 'Andhra Pradesh',
        // Inside Jharkhand's 82-83 circles, the Gaya division is still Bihar.
        821 => 'Bihar', 823 => 'Bihar', 824 => 'Bihar',
    ];
    if (isset($overrides[$p3])) { return $overrides[$p3]; }

    // Ranges follow India Post's PIN circles.
    $map = [
        [11, 11, 'Delhi'],
        [12, 13, 'Haryana'],
        [14, 16, 'Punjab'],
        [17, 17, 'Himachal Pradesh'],
        [18, 19, 'Jammu and Kashmir'],
        [20, 28, 'Uttar Pradesh'],
        [30, 34, 'Rajasthan'],
        [36, 39, 'Gujarat'],
        [40, 44, 'Maharashtra'],
        [45, 49, 'Madhya Pradesh'],
        [50, 51, 'Telangana'],
        [52, 55, 'Andhra Pradesh'],
        [56, 59, 'Karnataka'],
        [60, 66, 'Tamil Nadu'],
        [67, 69, 'Kerala'],
        [70, 74, 'West Bengal'],
        [75, 77, 'Odisha'],
        [78, 78, 'Assam'],
        [79, 79, 'Arunachal Pradesh'],
        [80, 81, 'Bihar'],
        [82, 83, 'Jharkhand'],
        [84, 85, 'Bihar'],
        [90, 99, 'Army Post Office'],
    ];
    foreach ($map as [$lo, $hi, $state]) {
        if ($p >= $lo && $p <= $hi) { return $state; }
    }
    return '';
}

/* ═══════════════════════════════════════════════════════════
   ADMIN PERMISSIONS
   ═══════════════════════════════════════════════════════════
   Three roles, defined by what each genuinely needs:

     owner     — everything. Money (refunds, pricing), configuration, backups
                 and staff accounts belong here and nowhere else.
     catalogue — the shop's contents: products, categories, media, SEO.
                 Cannot see revenue, issue refunds, or change settings.
     orders    — the day-to-day: orders, returns, support tickets.
                 Cannot change prices, refund, or edit the catalogue.

   Capabilities rather than page names, so a page can move or split without
   permissions silently going missing.

   Every page must call requireAdminCapability(). Hiding a nav link is a
   tidiness measure — the URL is still typeable, and the handler still accepts
   a POST from anyone who has it.
   ═══════════════════════════════════════════════════════════ */
const ADMIN_CAPABILITIES = [
    'owner' => ['*'],
    'catalogue' => [
        'catalogue.manage',   // categories, brands, reviews
        'suppliers.manage',   // kept in this preset so the built-in catalogue role
                              // behaves exactly as it did before suppliers became
                              // a separate tick; withhold it per-person instead
        'media.manage',
        'seo.manage',
        'content.manage',     // blog, banners, homepage
        'sizeguide.manage',
        'orders.view',        // needs to see what sold, not to change it
    ],
    'orders' => [
        'orders.view',
        'orders.manage',      // status, dispatch, notes
        'returns.manage',
        'tickets.manage',
        'customers.view',
        'catalogue.view',     // look up a product on an order
    ],
];

/* ── The catalogue of grantable capabilities ────────────────────────────────
   The single source of truth for what can be ticked in Roles & Permissions and
   on an individual staff account. Grouped to match the admin sidebar, because
   "which part of the menu does this person get?" is the question an owner is
   actually asking when they hand out access.

   Anything NOT listed here cannot be granted through the UI. Adding a new admin
   screen means adding its capability here as well, otherwise the screen is
   owner-only forever — deliberately the safe direction to fail in. */
const ADMIN_CAPABILITY_CATALOGUE = [
    /* Each line must name every screen the tick actually opens. These read as a
       promise to whoever is handing out access, and they were describing less
       than they granted: "Products, categories, brands, colours and sizes" also
       opened Suppliers and Customer Reviews, so a catalogue manager silently
       received the contact details of everyone the shop buys from. Verified
       against the sidebar rows in admin/includes/header.php. */
    'Catalogue & Inventory' => [
        'catalogue.manage'  => 'Categories, subcategories, brands and customer reviews',
        'catalogue.view'    => 'The product list, and editing products',
        'suppliers.manage'  => 'Suppliers — who you buy from, with owner name and phone',
        'sizeguide.manage'  => 'Size guides and measurement charts',
        'media.manage'      => 'Media library, uploads and lookbook images',
    ],
    'Orders & Customers' => [
        'orders.view'       => 'See orders and invoices',
        'orders.manage'     => 'Change order status, dispatch, notes',
        'returns.manage'    => 'Returns and RMAs',
        'tickets.manage'    => 'Support tickets and enquiries',
        'customers.view'    => 'Customer list and details',
        // Deliberately NOT in the 'orders' preset above.
        //
        // Deleting a customer account, or wiping the enquiries table, used to
        // need only customers.view — the permission an order clerk is given so
        // they can look someone up. Reading a customer's details and destroying
        // them are not the same job, and the destructive one is unrecoverable:
        // customers has no is_deleted column, and orders.customer_id has no
        // foreign key, so a deleted customer leaves their orders pointing at a
        // row that no longer exists.
        'customers.manage'  => 'Delete customer accounts and enquiries',
    ],
    'Content & Marketing' => [
        // Newsletter subscribers were not mentioned. That list is customer email
        // addresses, which is a different thing to hand over than a blog post.
        'content.manage'    => 'Blog, banners, homepage sections and newsletter subscribers',
        'seo.manage'        => 'SEO meta and OpenGraph',
    ],
    'Money' => [
        'pricing.manage'    => 'Prices, discounts and promo codes',
        'revenue.view'      => 'Revenue reports and takings',
        // Its own capability, not part of orders.manage. Issuing a refund sends
        // real money out of the business, and it was reachable by anyone who
        // could change a dispatch status — while the note on ADMIN_CAPABILITIES
        // said money "belongs to the owner and nowhere else".
        'refunds.manage'    => 'Send money back to a customer',
    ],
    'Administration' => [
        // The email log holds every message the shop has sent, recipient addresses
        // included, and the audit log is the record of what staff have done — both
        // were reachable from a tick that only mentioned settings and backups.
        'settings.manage'   => 'Shop settings, countries, database backups, email log and audit logs',
        // staff.manage is deliberately NOT offered here any more.
        //
        // Ticking it granted nothing on the Staff Accounts screen — that handler
        // has always required the literal owner role — but it DID open Roles &
        // Permissions, where capabilities are written. Anyone with it could add
        // refunds.manage or settings.manage to their own role, so the one tick
        // labelled "Staff accounts, roles and permissions" was in practice a tick
        // labelled "everything". Both screens now require the owner role, which is
        // what config's own rule at the top of this file already said.
        //
        // Any role that still carries staff.manage in its stored JSON is harmless:
        // the pages no longer consult it.
    ],
];

/**
 * Alt text for a product photograph.
 *
 * A hand-written image_alt always wins. What follows is only the floor for the
 * ten products out of eleven that have none.
 *
 * This existed already, but written out longhand in four places —
 * pages/product.php, pages/shop.php and two JS payloads — each falling back to
 * the bare product name, and each subtly different. One helper means the shop
 * page, the product page and the gallery cannot drift apart, and improving the
 * wording improves it everywhere at once.
 *
 * Deliberately a short description, not a keyword list. Alt text is read aloud
 * by screen readers and Google penalises stuffing, so this adds the category
 * and the brand once each and stops — "Iris Straight Crepe Pants — Bottoms by
 * Dievon", never "kurti dress ethnic wear buy online india".
 *
 * $context labels a specific shot ("Back view", "Detail") for galleries where
 * several photographs of one garment would otherwise share identical alt text.
 */
/* The care wording used when a product has none of its own. Defined as a
   constant so the product page, the details sheet and the admin form's
   placeholder all quote the same sentence. */
define('DEFAULT_WASH_CARE', 'Gentle machine wash or hand wash in cold water. Wash with similar colours. Do not bleach. Do not tumble dry. Dry in shade. Iron on low to medium heat.');

/**
 * The care instructions shown for a garment, and the wording used when the
 * owner has not written any.
 *
 * One function rather than the text repeated at each place that shows it. It
 * appears on the product page, inside the Details sheet and as the placeholder
 * in the admin form — and before this the page carried its own paragraph while
 * the form suggested something else, so what an owner was shown while typing
 * was not what a shopper would read if they left it blank.
 *
 * The default suits the cottons, georgettes and blended silks this shop
 * actually sells. It deliberately does NOT say "dry clean only", which the
 * previous fallback did: that was printed under every garment including
 * machine-washable cotton, and telling a customer to dry clean a cotton kurti
 * costs them money for nothing.
 *
 * A garment with genuinely different needs — pure silk, hand embroidery, zari —
 * still needs its own text typed in. This is the sensible floor, not an excuse
 * to leave the field empty on a piece that cannot take water.
 */
function productWashCare(array $product): string
{
    $own = trim((string)($product['wash_care'] ?? ''));
    return $own !== '' ? $own : DEFAULT_WASH_CARE;
}

/**
 * How many days a product wears its "New" badge.
 *
 * Thirty is long enough that a piece added at the start of a month is still
 * flagged at the end of it, and short enough that "New" never sits on something
 * a shopper already saw last season. Change it here and every screen follows,
 * because they all ask productBadge() instead of reading the column.
 */
const NEW_BADGE_DAYS = 30;

/**
 * The individual occasions inside a product's occasion field.
 *
 * The field holds a LIST, not one value: the shop types "Casual / Everyday /
 * Day Out / Travel" because a kurti genuinely suits all four. Everything read
 * it as a single opaque string, so the homepage grouped by it and produced one
 * tile per product — nine tiles on a nine-product shop, each a sentence long,
 * each leading to exactly one garment. The data was never wrong; the reading
 * of it was.
 *
 * Slashes and commas both separate, spacing is forgiving, and each value comes
 * back in one consistent capitalisation so "casual" and "Casual" cannot become
 * two different filters.
 */
function dievonSplitOccasions(?string $raw): array
{
    $parts = preg_split('~\s*[/,]\s*~u', (string)$raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $out = [];
    foreach ($parts as $part) {
        $clean = ucwords(mb_strtolower(trim($part)));
        if ($clean !== '') { $out[$clean] = true; }
    }
    return array_keys($out);
}

/**
 * SQL that matches ONE occasion inside that list, and not a longer name that
 * happens to contain it.
 *
 * The separators are normalised to a single slash and the whole value is
 * wrapped in slashes, so the pattern "%/Casual/%" matches "Casual / Everyday"
 * but NOT "Smart Casual" — which a plain LIKE '%Casual%' would have swept in.
 */
const OCCASION_MATCH_SQL =
    "CONCAT('/', REPLACE(REPLACE(REPLACE(occasion, ' / ', '/'), ', ', '/'), ',', '/'), '/')";

/**
 * The badge a product should actually be shown wearing.
 *
 * "New" is the only badge with a natural expiry, and nothing ever took it off.
 * A badge that never comes off stops meaning anything: once every other card
 * says New, the word tells a shopper nothing and the genuinely new pieces are
 * the ones that lose out. Worse, it quietly makes the shop look untended — a
 * garment first listed last year still announcing itself as new is the sort of
 * detail a customer notices and a shopkeeper never does.
 *
 * So "New" is now a fact about the product's age rather than a flag somebody has
 * to remember to clear. The stored value is left alone: this only decides what
 * is DISPLAYED, so turning the window up or off brings every badge straight
 * back, and nothing the owner chose has been thrown away.
 *
 * "Hot" and "Best Seller" are editorial judgements with no natural expiry date,
 * so they are returned untouched.
 */
function productBadge(array $product): string
{
    $badge = trim((string)($product['badge'] ?? ''));
    if ($badge === '' || strcasecmp($badge, 'New') !== 0) { return $badge; }

    // No date to judge age by — leave the owner's choice exactly as it is. A
    // query that simply did not select created_at must never silently strip a
    // badge off every product it returns.
    $created = trim((string)($product['created_at'] ?? ''));
    if ($created === '') { return $badge; }

    $ts = strtotime($created);
    if ($ts === false) { return $badge; }

    return (time() - $ts) > (NEW_BADGE_DAYS * 86400) ? '' : $badge;
}

function productImageAlt(array $product, string $context = ''): string {
    $stored = trim((string)($product['image_alt'] ?? ''));
    if ($stored !== '') {
        return $context !== '' ? $stored . ' — ' . $context : $stored;
    }

    $name = trim((string)($product['name'] ?? ''));
    if ($name === '') { return 'Dievon product photograph'; }

    $parts = [$name];

    // Skipped when the name already says it: "Women's Tops" under a product
    // called "Elena Cowl-Neck Satin Top" would just repeat the word Top.
    $category = trim((string)($product['category'] ?? ''));
    if ($category !== '') {
        $lastWord = strtolower(trim((string)preg_replace('/^.*\s/', '', rtrim($category, 's'))));
        if ($lastWord === '' || stripos($name, $lastWord) === false) {
            $parts[] = $category;
        }
    }

    $alt = implode(' — ', $parts);

    // Brand before the shot label, or a gallery image reads "… — Back view by
    // Dievon", which attributes the view to the brand rather than the garment.
    $brand = trim((string)(defined('SHOP_NAME') ? SHOP_NAME : 'Dievon'));
    if ($brand !== '' && stripos($alt, $brand) === false) { $alt .= ' by ' . $brand; }
    if ($context !== '') { $alt .= ' — ' . $context; }

    return $alt;
}

/**
 * The second photograph a card cross-fades to while the pointer is over it.
 *
 * Returns a filename in uploads/products/, or null when the product has only
 * the one picture — in which case the card builds no hover layer at all and
 * behaves exactly as it did before. That per-product opt-in is deliberate: the
 * catalogue is uneven, and a product with a single photograph must not flash
 * its own cover image at itself.
 *
 * Precedence follows the product page: the first gallery picture, and failing
 * that the first photograph of the first active colour. Anything already equal
 * to the cover image is skipped, so the hover is always a genuinely different
 * shot rather than a cross-fade between two copies of the same one.
 *
 * Results are memoised per request and loaded for the whole grid in two
 * queries, not two per tile — the card partial is included once per product,
 * so an uncached lookup here would mean a round trip for every card on the
 * shop.
 */
function productHoverImagePrime(array $products, ?PDO $pdo = null): void
{
    static $done = [];

    $pdo = $pdo ?? ($GLOBALS['pdo'] ?? null);
    if (!$pdo instanceof PDO) { return; }

    // Only products not already resolved, so a second grid on the same page
    // (say "Suggested Pieces" under the product) costs nothing for repeats.
    $wanted = [];
    foreach ($products as $p) {
        $id = (int)($p['id'] ?? 0);
        if ($id > 0 && !array_key_exists($id, $done)) { $wanted[$id] = $p['image'] ?? ''; }
    }
    if (!$wanted) { return; }

    $ids = array_keys($wanted);
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    foreach ($ids as $id) { $done[$id] = null; }   // resolved-as-nothing until proven otherwise

    // Gallery first.
    $st = $pdo->prepare(
        "SELECT product_id, image FROM product_images
          WHERE product_id IN ($ph) ORDER BY product_id, sort_order ASC, id ASC"
    );
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pid = (int)$row['product_id'];
        if ($done[$pid] !== null) { continue; }                       // first row per product wins
        if (trim((string)$row['image']) === trim((string)$wanted[$pid])) { continue; }  // same as the cover
        $done[$pid] = (string)$row['image'];
    }

    // Colour photographs for whatever the gallery did not cover.
    $rest = array_values(array_filter($ids, static fn($id) => $done[$id] === null));
    if ($rest) {
        $ph2 = implode(',', array_fill(0, count($rest), '?'));
        $st2 = $pdo->prepare(
            "SELECT pc.product_id, ci.image
               FROM product_color_images ci
               JOIN product_colors pc ON pc.id = ci.color_id
              WHERE pc.product_id IN ($ph2) AND pc.is_active = 1
              ORDER BY pc.product_id, pc.sort_order ASC, pc.id ASC, ci.sort_order ASC, ci.id ASC"
        );
        $st2->execute($rest);
        foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pid = (int)$row['product_id'];
            if ($done[$pid] !== null) { continue; }
            if (trim((string)$row['image']) === trim((string)$wanted[$pid])) { continue; }
            $done[$pid] = (string)$row['image'];
        }
    }

    $GLOBALS['__dievonHoverImages'] = ($GLOBALS['__dievonHoverImages'] ?? []) + $done;
}

/**
 * The hover photograph for one product, or null.
 *
 * Primes itself from the product row when a grid has not already been primed
 * in bulk, so the card partial works on any page without that page having to
 * know this feature exists. Pages that render a grid should still call
 * productHoverImagePrime() with the whole list first — that turns N pairs of
 * queries into one pair.
 */
function productHoverImage(array $product): ?string
{
    $id = (int)($product['id'] ?? 0);
    if ($id <= 0) { return null; }

    if (!array_key_exists($id, $GLOBALS['__dievonHoverImages'] ?? [])) {
        productHoverImagePrime([$product]);
    }

    $img = $GLOBALS['__dievonHoverImages'][$id] ?? null;
    return ($img === null || $img === '') ? null : $img;
}

/**
 * Keeps a colour product's cover image equal to the first photograph a shopper
 * actually sees when the product page opens.
 *
 * Biba, Aza and Myntra never upload a separate "main" picture for a garment sold
 * in several colourways — the listing thumbnail IS the first shot of the default
 * colour. Dievon's admin allows both, so the two were free to drift apart: the
 * shop grid showed one photograph and the tap-through showed two entirely
 * different ones, which reads as the wrong product having loaded.
 *
 * The default colour is picked by exactly the rule pages/product.php uses for
 * its opening gallery — first active colour, in sort order, holding at least one
 * available size — because any other rule starts the drift again.
 *
 * Returns the filename now on the product, or null when nothing changed. Never
 * blanks products.image: a colour with no photographs leaves the existing cover
 * alone rather than replacing it with nothing.
 */
function syncProductCoverImage(PDO $pdo, int $productId): ?string {
    if ($productId <= 0) { return null; }

    try {
        $stmt = $pdo->prepare(
            "SELECT ci.image
               FROM product_colors c
               JOIN product_color_images ci ON ci.color_id = c.id
              WHERE c.product_id = :pid
                AND c.is_active = 1
                AND EXISTS (SELECT 1 FROM product_variants v
                             WHERE v.color_id = c.id AND v.available = 1)
              ORDER BY c.sort_order ASC, c.id ASC, ci.sort_order ASC, ci.id ASC
              LIMIT 1"
        );
        $stmt->execute(['pid' => $productId]);
        $cover = (string)$stmt->fetchColumn();

        // No colourway can supply one. Either the product never had colours —
        // in which case its own cover is already correct and this does nothing —
        // or the last colour has just been deleted, and deleting a colour also
        // unlinks its photographs. The cover would then name a file that is no
        // longer on disk, so the shop card, the share preview and every order
        // thumbnail render broken while the product's own images sit unused.
        if ($cover === '') { return productCoverFallback($pdo, $productId); }

        $cur = $pdo->prepare("SELECT image FROM products WHERE id = :pid");
        $cur->execute(['pid' => $productId]);
        $current = (string)$cur->fetchColumn();
        if ($current === $cover) { return $cover; }

        // The cover being replaced is filed away, not dropped. It is still the
        // only record of a photograph the shopkeeper chose, and if every colour
        // is later removed the product would otherwise be left with no image.
        if ($current !== '') {
            $dupe = $pdo->prepare("SELECT id FROM product_images WHERE product_id = :pid AND image = :img");
            $dupe->execute(['pid' => $productId, 'img' => $current]);
            if (!$dupe->fetch()) {
                // Filed at the FRONT of the gallery, not the back, so that if the
                // colours are later removed productCoverFallback() hands this
                // exact picture back rather than some other gallery shot — the
                // product returns to the cover it had before colours existed.
                $ord = $pdo->prepare("SELECT COALESCE(MIN(sort_order),0)-1 FROM product_images WHERE product_id = :pid");
                $ord->execute(['pid' => $productId]);
                $pdo->prepare("INSERT INTO product_images (product_id, image, sort_order) VALUES (:pid, :img, :sort)")
                    ->execute(['pid' => $productId, 'img' => $current, 'sort' => (int)$ord->fetchColumn()]);
            }
        }

        $pdo->prepare("UPDATE products SET image = :img WHERE id = :pid")
            ->execute(['img' => $cover, 'pid' => $productId]);
        return $cover;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Promotes one of the product's own photographs to cover when the current one
 * is missing or gone from disk.
 *
 * Only ever acts on a genuinely broken cover — a cover whose file is present is
 * the shopkeeper's choice and is left exactly as it is. The promoted row is
 * removed from the additional gallery so the picture does not then appear twice
 * on the product page, which restores precisely the layout the product had
 * before its colours were introduced.
 *
 * Returns the filename now on the product, or null if nothing usable was found.
 */
function productCoverFallback(PDO $pdo, int $productId): ?string {
    if ($productId <= 0) { return null; }
    $dir = __DIR__ . '/../uploads/products/';

    try {
        /* A gallery row whose file is not on disk is SKIPPED, never deleted.
           ────────────────────────────────────────────────────────────────────
           This used to run DELETE FROM product_images for every row whose file
           was missing, on the reasoning that a colour deletion leaves rows that
           nothing can reference again. That reasoning does not hold, because a
           missing file is very often temporary:

             • Media Cleanup MOVES files into uploads/_quarantine and is meant
               to be undoable — but the moment the next save ran, this deleted
               the rows, so restoring the batch brought the files back with
               nothing left pointing at them.
             • An interrupted or add-only FTP upload, a permissions blip, or a
               folder not yet synced all read as "missing" for a few seconds.

           A missing file is a display problem and it is recoverable. A deleted
           row is a data problem and it is not — the order the owner arranged is
           gone for good. So the row stays and only the cover choice skips it;
           pages/product.php skips missing files when rendering, so the broken
           tile this was written to prevent still never reaches a shopper. */
        $rows = $pdo->prepare("SELECT id, image FROM product_images WHERE product_id = :pid ORDER BY sort_order ASC, id ASC");
        $rows->execute(['pid' => $productId]);
        $live = [];
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_file($dir . $row['image'])) { $live[] = $row; }
        }

        $cur = $pdo->prepare("SELECT image FROM products WHERE id = :pid");
        $cur->execute(['pid' => $productId]);
        $current = (string)$cur->fetchColumn();
        if ($current !== '' && is_file($dir . $current)) { return $current; }

        foreach ($live as $row) {
            $pdo->prepare("UPDATE products SET image = :img WHERE id = :pid")
                ->execute(['img' => $row['image'], 'pid' => $productId]);
            $pdo->prepare("DELETE FROM product_images WHERE id = :id")->execute(['id' => $row['id']]);
            return $row['image'];
        }
        return null;
    } catch (PDOException $e) {
        return null;
    }
}

/** Whether this product is sold by colourway — photographs live on the colours. */
function productHasColourways(PDO $pdo, int $productId): bool {
    if ($productId <= 0) { return false; }
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM product_colors WHERE product_id = :pid LIMIT 1");
        $stmt->execute(['pid' => $productId]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * How many of a product can actually be sold right now.
 *
 * - track_stock off → PHP_INT_MAX (stock is not tracked, so it is always
 *   sellable while published).
 * - variant-managed → the sum of stock_qty across available variant rows. A
 *   variant whose stock_qty is NULL is not tracked either, so one of those
 *   makes the product sellable (PHP_INT_MAX).
 * - product-level   → total_stock − damage_stock − sold_offline − sold_online,
 *   the exact formula checkout_action.php enforces.
 *
 * Used by the product card ("is this tile buyable?") so a sold-out garment
 * stops looking like it can be bought.
 */
/**
 * The size rows that actually hold this product's stock, or [] when it has none.
 *
 * productSellableStock() prefers variants over the product-level columns whenever
 * any exist, so this is the test for "where does adding stock have to land?".
 * Adding to products.total_stock on a product with sizes changes a number nobody
 * sells from.
 */
function productTrackedVariants(?PDO $pdo, int $productId): array
{
    $pdo = $pdo ?: ($GLOBALS['pdo'] ?? null);
    if (!$pdo || $productId <= 0) { return []; }
    try {
        /* The colour comes along too.
           ────────────────────────────────────────────────────────────────────
           A variant is a size AND a colour, not a size. One product here holds
           XS three times — plain, Ivory Gold and pink — so a picker labelled by
           size alone offered "XS" three times over with no way to tell which was
           which, and no way to know which one an entered quantity would land in.
           Nothing is wrong with the data; the label was missing half of it. */
        $st = $pdo->prepare(
            "SELECT v.id, v.name, v.size_code, v.stock_qty, v.available,
                    v.color_id, c.color_name, c.sort_order AS color_sort, c.is_active AS color_active
               FROM product_variants v
          LEFT JOIN product_colors c ON c.id = v.color_id
              WHERE v.product_id = :id AND v.stock_qty IS NOT NULL
           ORDER BY c.sort_order IS NULL DESC, c.sort_order, v.sort_order, v.id"
        );
        $st->execute(['id' => $productId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows) { return []; }

        /* Sizes in LADDER order, not in the order they were ticked.
           ────────────────────────────────────────────────────────────────────
           v.sort_order is assigned when a size is ticked, so it records the
           order of clicks. Tick L on a later visit than M, XL and 2XL — which
           is what happened on this shop — and the picker reads "M, XL, 2XL, L",
           with L in a different position under every colour. Choosing a size
           from a list where the sizes are not in size order is how the wrong
           one ends up with the stock. */
        $ladderPos = [];
        foreach (sizeLadderForCategory(0) as $i => $rung) { $ladderPos[$rung['code']] = $i; }
        usort($rows, static function (array $a, array $b) use ($ladderPos): int {
            // Uncoloured rows first, as before, then colours in the owner's order.
            $aNull = $a['color_id'] === null ? 0 : 1;
            $bNull = $b['color_id'] === null ? 0 : 1;
            if ($aNull !== $bNull) { return $aNull <=> $bNull; }
            if ((int)$a['color_sort'] !== (int)$b['color_sort']) {
                return (int)$a['color_sort'] <=> (int)$b['color_sort'];
            }
            $ai = $ladderPos[$a['size_code']] ?? PHP_INT_MAX;   // off-ladder sizes last
            $bi = $ladderPos[$b['size_code']] ?? PHP_INT_MAX;
            return $ai <=> $bi;
        });

        /* Flag the rows that hold stock the shop cannot sell.
           ────────────────────────────────────────────────────────────────────
           A product sells EITHER by plain size OR by colourway. Once a colour is
           active the plain rows stop being offered — but they keep whatever
           stock they were given, and this picker still lists them because the
           stock is real. On the shop in question that was four rows holding 40
           units each: 160 garments counted nowhere and sellable to nobody.
           Deleting them is the owner's call, not this function's. Saying so is
           not. */
        $hasActiveColour = false;
        foreach ($rows as $r) {
            if ($r['color_id'] !== null && (int)$r['color_active'] === 1) { $hasActiveColour = true; break; }
        }
        foreach ($rows as &$r) {
            $r['sellable'] = !($hasActiveColour && $r['color_id'] === null);
        }
        unset($r);

        return $rows;
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Write one line into the stock history.
 *
 * This shop had NO record of stock movement at all — a quantity would change and
 * nothing anywhere said who changed it, when, or why. That is survivable while
 * one person runs the shop from memory, and stops being survivable the first
 * time a count is wrong and the only question that matters is "what happened?".
 *
 * $delta is signed: +12 received, -50 sent back to a supplier.
 *
 * Deliberately never throws. Losing the history of a movement is bad; refusing
 * to perform the movement because the history could not be written is worse, and
 * the caller has already changed the stock by the time this runs.
 */
function recordStockMovement(
    ?PDO $pdo, int $productId, ?int $variantId, int $delta,
    string $reason, string $note = '', ?int $adminId = null
): void {
    $pdo = $pdo ?: ($GLOBALS['pdo'] ?? null);
    if (!$pdo || $productId <= 0 || $delta === 0) { return; }
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS stock_movements (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                product_id  INT NOT NULL,
                variant_id  INT NULL,
                delta       INT NOT NULL,
                reason      VARCHAR(32) NOT NULL,
                note        VARCHAR(255) NULL,
                created_by  INT NULL,
                created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (product_id), INDEX (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $st = $pdo->prepare(
            "INSERT INTO stock_movements (product_id, variant_id, delta, reason, note, created_by)
             VALUES (:p, :v, :d, :r, :n, :a)"
        );
        $st->execute([
            'p' => $productId,
            'v' => $variantId ?: null,
            'd' => $delta,
            'r' => mb_substr($reason, 0, 32),
            'n' => $note !== '' ? mb_substr($note, 0, 255) : null,
            'a' => $adminId ?: null,
        ]);
    } catch (Throwable $e) {
        error_log('recordStockMovement failed for product ' . $productId . ': ' . $e->getMessage());
    }
}

/**
 * The most of one item a shopper may put in the bag.
 *
 * Stock already caps a TRACKED product — the cart refuses more than the size
 * holds. Nothing capped an untracked one, so a piece with track_stock off could
 * be added thirty times, or three hundred, by a stuck finger or a bot. An
 * untracked product means "I am not counting these", not "I will make any number
 * of them".
 *
 * A ceiling, not a stock rule: it applies to every product, and the tighter of
 * the two always wins. 0 or less in Settings disables it, for a shop that really
 * does take bulk orders through the same basket as everyone else.
 */
function cartMaxQtyPerItem(?PDO $pdo = null): int
{
    $pdo = $pdo ?: ($GLOBALS['pdo'] ?? null);
    $v = (int)storeSetting($pdo, 'max_qty_per_item', 10);
    return $v > 0 ? $v : PHP_INT_MAX;
}

/** Reasons stock may leave without being sold or damaged. */
function stockRemovalReasons(): array
{
    return [
        'returned_supplier' => 'Returned to supplier',
        'lost'              => 'Lost or stolen',
        'correction'        => 'Miscount correction',
    ];
}

function productSellableStock(?PDO $pdo, array $product): int
{
    if (empty($product['track_stock'])) {
        return PHP_INT_MAX;
    }
    $pid = (int)($product['id'] ?? 0);
    if ($pid <= 0) { return 0; }

    $productLevel = function () use ($product): int {
        $avail = (int)($product['total_stock'] ?? 0)
               - (int)($product['damage_stock'] ?? 0)
               - (int)($product['sold_offline'] ?? 0)
               - (int)($product['sold_online'] ?? 0);
        return max(0, $avail);
    };

    try {
        $stmt = $pdo->prepare(
            "SELECT
                COUNT(*) AS variant_count,
                COALESCE(SUM(CASE WHEN available = 1 AND stock_qty IS NOT NULL THEN stock_qty ELSE 0 END), 0) AS variant_stock,
                SUM(CASE WHEN available = 1 AND stock_qty IS NULL THEN 1 ELSE 0 END) AS untracked_variants
             FROM product_variants WHERE product_id = :id"
        );
        $stmt->execute(['id' => $pid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ((int)($row['variant_count'] ?? 0) > 0) {
            // Variant-managed. An available variant with NULL stock is untracked,
            // so the product cannot honestly be called sold out.
            if ((int)($row['untracked_variants'] ?? 0) > 0) {
                return PHP_INT_MAX;
            }

            // Damage and offline sales come off the variant sum too.
            //
            // This returned the bare sum, so anything recorded through the Stock
            // tab — breakages, units sold in person — moved products.damage_stock
            // and products.sold_offline and changed NOTHING the shopper sees. One
            // stock edit produced three different answers across four surfaces:
            // measured on product 20, availableStock() said 0 (Stock tab printed
            // "In Stock 0"), this function said 12 (Products list, shop tile and
            // Merchant feed), the product page rendered "in stock" with four Add
            // to Bag buttons, and the cart then refused with "currently out of
            // stock". Subtracting here makes the tile, the list, the feed, the
            // page and the cart agree, and makes the Stock tab's own numbers
            // mean something on a product that sells by size.
            //
            // sold_online is deliberately NOT subtracted here. Checkout already
            // decrements the variant's own stock_qty AND increments
            // products.sold_online for the same sale, so the variant sum has
            // taken online orders into account; deducting it again would count
            // every web sale twice and walk a stocked product down to Sold Out.
            // damage_stock and sold_offline have no such counterpart — the Stock
            // tab writes them at product level only, never against a size.
            $variantStock = (int)($row['variant_stock'] ?? 0);
            $deductions   = (int)($product['damage_stock'] ?? 0)
                          + (int)($product['sold_offline'] ?? 0);

            return max(0, $variantStock - $deductions);
        }

        return $productLevel();
    } catch (PDOException $e) {
        // No variant query available — fall back to the product-level formula.
        return $productLevel();
    }
}

/** Every capability string in the catalogue, flattened. */
function adminAllCapabilities(): array {
    $out = [];
    foreach (ADMIN_CAPABILITY_CATALOGUE as $caps) {
        foreach ($caps as $key => $_label) { $out[] = $key; }
    }
    return $out;
}

/* ═══════════════════════════════════════════════════════════
   TWO-STEP SIGN-IN BY EMAIL
   ═══════════════════════════════════════════════════════════
   An alternative second factor for staff without an authenticator app. Opt-in
   per account, exactly like the app option — an account with totp_enabled = 0
   is unaffected by everything here.

   Codes are six digits, valid ten minutes, single use, and stored hashed. Six
   digits is only a million possibilities, so the short life, the single use and
   the sign-in throttle in admin/login.php are what make that safe rather than
   the length of the code.
   ═══════════════════════════════════════════════════════════ */

const ADMIN_LOGIN_CODE_TTL_MINUTES = 10;
const ADMIN_LOGIN_CODE_MAX_PER_15M = 3;

/**
 * Issue a one-time sign-in code, or null when the rate limit has been reached.
 *
 * The limit is per account and counts codes SENT, not codes tried: without it,
 * anyone who knows a username could have the shop mail somebody a code every
 * few seconds — a nuisance for the recipient and a good way to get the sending
 * domain flagged as spam.
 */
function issueAdminLoginCode(PDO $pdo, int $adminId, string $purpose = 'login'): ?string {
    try {
        $rate = $pdo->prepare(
            "SELECT COUNT(*) FROM admin_login_codes
              WHERE admin_id = :id AND created_at > (NOW() - INTERVAL 15 MINUTE)"
        );
        $rate->execute([':id' => $adminId]);
        if ((int)$rate->fetchColumn() >= ADMIN_LOGIN_CODE_MAX_PER_15M) { return null; }

        // Any code still outstanding is retired first, so a person who pressed
        // Resend cannot be confused about which of two live codes to type.
        $pdo->prepare("UPDATE admin_login_codes SET used_at = NOW()
                        WHERE admin_id = :id AND used_at IS NULL")->execute([':id' => $adminId]);

        // random_int, not rand/mt_rand — this is a credential, and the others are
        // predictable from a couple of observed outputs.
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $pdo->prepare(
            "INSERT INTO admin_login_codes (admin_id, code_hash, purpose, expires_at)
             VALUES (:id, :h, :p, NOW() + INTERVAL " . ADMIN_LOGIN_CODE_TTL_MINUTES . " MINUTE)"
        )->execute([':id' => $adminId, ':h' => password_hash($code, PASSWORD_DEFAULT), ':p' => $purpose]);

        return $code;
    } catch (PDOException $e) {
        error_log('issueAdminLoginCode: ' . $e->getMessage());
        return null;
    }
}

/**
 * Check a typed code and burn it. True only for a code that is unused, unexpired
 * and belongs to this account.
 *
 * Marked used BEFORE returning true, so the same code replayed on a second
 * request cannot be accepted twice.
 */
function verifyAdminLoginCode(PDO $pdo, int $adminId, string $typed, string $purpose = 'login'): bool {
    $typed = preg_replace('/\D+/', '', $typed);
    if ($typed === '' || strlen($typed) !== 6) { return false; }

    try {
        $st = $pdo->prepare(
            "SELECT id, code_hash FROM admin_login_codes
              WHERE admin_id = :id AND purpose = :p AND used_at IS NULL AND expires_at > NOW()
              ORDER BY id DESC LIMIT 5"
        );
        $st->execute([':id' => $adminId, ':p' => $purpose]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (password_verify($typed, $row['code_hash'])) {
                $pdo->prepare("UPDATE admin_login_codes SET used_at = NOW() WHERE id = :id")
                    ->execute([':id' => $row['id']]);
                return true;
            }
        }
    } catch (PDOException $e) {
        error_log('verifyAdminLoginCode: ' . $e->getMessage());
    }
    return false;
}

/** "p•••@gmail.com" — enough to recognise your own address, not enough to learn someone else's. */
function maskEmailAddress(string $email): string {
    $at = strpos($email, '@');
    if ($at === false || $at < 1) { return '•••'; }
    $name = substr($email, 0, $at);
    return substr($name, 0, 1) . str_repeat('•', max(2, min(6, strlen($name) - 1))) . substr($email, $at);
}

/** The signed-in admin's role, defaulting to owner for pre-role sessions. */
function currentAdminRole(): string {
    $r = $_SESSION['admin_role'] ?? 'owner';
    return isset(ADMIN_CAPABILITIES[$r]) ? $r : 'orders';
}

/**
 * The capabilities the signed-in admin actually holds.
 *
 * Resolution order, each step layered on the one before:
 *   1. the built-in preset for their legacy role  (owner / catalogue / orders)
 *   2. their assigned custom role, if they have one            (admin_roles)
 *   3. their own per-account grants and denies    (admin_user_capabilities)
 *
 * Step 1 alone is exactly the old behaviour, which is what makes this safe to
 * deploy before the migration has been run: if the new tables are missing, the
 * queries throw, the catch keeps the preset, and every existing account carries
 * on with precisely the access it had yesterday.
 *
 * Resolved per request rather than cached into the session, so revoking access
 * takes effect on the staff member's very next page load rather than whenever
 * they next happen to sign in.
 */
function adminCapabilitySet(): array {
    static $cache = null;
    if ($cache !== null) { return $cache; }

    $legacyRole = $_SESSION['admin_role'] ?? 'owner';
    $caps = ADMIN_CAPABILITIES[$legacyRole] ?? ADMIN_CAPABILITIES['orders'];

    $adminId = (int)($_SESSION['admin_id'] ?? 0);
    $pdo     = $GLOBALS['pdo'] ?? null;
    if (!($pdo instanceof PDO) || $adminId <= 0) { return $cache = $caps; }

    try {
        $st = $pdo->prepare(
            "SELECT r.capabilities FROM admin_users u
               JOIN admin_roles r ON r.id = u.role_id
              WHERE u.id = :id"
        );
        $st->execute([':id' => $adminId]);
        $json = $st->fetchColumn();
        if (is_string($json) && $json !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) { $caps = $decoded; }
        }

        $ov = $pdo->prepare("SELECT capability, mode FROM admin_user_capabilities WHERE admin_id = :id");
        $ov->execute([':id' => $adminId]);
        $rows = $ov->fetchAll(PDO::FETCH_ASSOC);

        if ($rows) {
            // A deny has to be able to bite a wildcard, or "everything except
            // refunds" would silently grant refunds. Expanding '*' to the real
            // list first makes the subtraction mean something.
            $hasDeny = false;
            foreach ($rows as $r) { if ($r['mode'] === 'deny') { $hasDeny = true; break; } }
            if ($hasDeny && in_array('*', $caps, true)) {
                $caps = adminAllCapabilities();
            }
            foreach ($rows as $r) {
                if ($r['mode'] === 'grant') {
                    $caps[] = $r['capability'];
                } else {
                    $caps = array_values(array_diff($caps, [$r['capability']]));
                }
            }
        }
    } catch (PDOException $e) {
        // Migration not run yet — keep the built-in preset.
        return $cache = $caps;
    }

    return $cache = array_values(array_unique($caps));
}

/** Does the signed-in admin hold this capability? */
function adminCan(string $capability): bool {
    $caps = adminCapabilitySet();
    if (in_array('*', $caps, true)) { return true; }
    if (in_array($capability, $caps, true)) { return true; }

    // A ".manage" capability implies the matching ".view".
    if (str_ends_with($capability, '.view')) {
        $manage = substr($capability, 0, -5) . '.manage';
        return in_array($manage, $caps, true);
    }
    return false;
}

/**
 * Stop the request unless the admin holds this capability.
 *
 * $asJson for handlers, which must answer with JSON rather than an HTML page
 * the caller cannot parse.
 */
function requireAdminCapability(string $capability, bool $asJson = false): void {
    if (empty($_SESSION['admin_logged_in'])) {
        if ($asJson) {
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Please sign in again.']);
        } else {
            header('Location: login.php');
        }
        exit;
    }

    // ── Forced password change ──────────────────────────────────────────────
    // must_change_password was set by the installer on the seeded owner account
    // and by admin_users_handler.php when an admin creates a colleague — but
    // nothing ever ENFORCED it. It rendered a banner on one page and was ignored
    // everywhere else, so the account seeded from a password written in
    // config/config.php stayed on that password indefinitely.
    //
    // Every admin page passes through here, so this is the chokepoint. The page
    // that performs the change and the sign-out route are exempt, or the gate
    // would trap the person inside it.
    if (!empty($_SESSION['admin_id']) && empty($_SESSION['admin_pw_change_done'])) {
        static $mustChange = null;
        if ($mustChange === null) {
            $mustChange = false;
            try {
                global $pdo;
                if ($pdo instanceof PDO) {
                    $st = $pdo->prepare("SELECT must_change_password FROM admin_users WHERE id = :id");
                    $st->execute(['id' => (int)$_SESSION['admin_id']]);
                    $mustChange = ((int)$st->fetchColumn() === 1);
                }
            } catch (PDOException $e) { $mustChange = false; }
        }
        if ($mustChange) {
            $here = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
            $exempt = ['admin_users.php', 'admin_users_handler.php', 'logout.php', 'login.php'];
            if (!in_array($here, $exempt, true)) {
                if ($asJson) {
                    header('Content-Type: application/json');
                    http_response_code(403);
                    echo json_encode(['success' => false, 'message' => 'Please set a new password before continuing.']);
                } else {
                    $_SESSION['admin_notice'] = 'Please set a new password before you continue. '
                        . 'Your account is still on the password it was created with.';
                    header('Location: admin_users.php?force_password_change=1');
                }
                exit;
            }
        } else {
            // Cleared — do not query again for the rest of this session.
            $_SESSION['admin_pw_change_done'] = 1;
        }
    }

    if (adminCan($capability)) { return; }

    if ($asJson) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Your account does not have permission to do that.']);
        exit;
    }

    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><meta name="robots" content="noindex, nofollow">'
       . '<title>Not permitted</title>'
       . '<div style="font-family:system-ui,sans-serif;max-width:520px;margin:80px auto;padding:28px;'
       . 'border:1px solid #e5e7eb;line-height:1.7;">'
       . '<h1 style="font-size:19px;margin:0 0 10px;">You do not have permission to open this page</h1>'
       . '<p style="color:#555;margin:0 0 18px;">Your account is set up as <strong>'
       . htmlspecialchars(currentAdminRole()) . '</strong>. Ask the shop owner if you need access.</p>'
       . '<a href="orders.php" style="color:#511126;font-weight:600;">Back to Orders</a></div>';
    exit;
}

/* ═══════════════════════════════════════════════════════════
   PASSWORD POLICY
   ═══════════════════════════════════════════════════════════
   Registration and the change-password form previously enforced NOTHING on the
   server — "Min 6 characters" was placeholder text in the input, and a one
   character password was accepted. Only the reset page checked, at 6.

   The rule here is length, not composition. Forced upper/lower/digit/symbol
   rules reliably produce "Pa$$w0rd1" — short, predictable, and hostile to
   password managers — whereas length is what actually resists guessing. So:
   a high minimum, a maximum only high enough to stop abuse, and no character
   classes. A four-word passphrase passes; "Passw0rd!" does not.
   ═══════════════════════════════════════════════════════════ */
const PASSWORD_MIN_LENGTH = 12;
const PASSWORD_MAX_LENGTH = 200;

/**
 * Returns an error message, or null when the password is acceptable.
 *
 * Callers must treat null as "ok" and anything else as a message to show the
 * customer verbatim.
 */
function validatePasswordStrength(string $password, string $email = '', string $name = ''): ?string {
    $len = mb_strlen($password);

    if ($len < PASSWORD_MIN_LENGTH) {
        return 'Please choose a password of at least ' . PASSWORD_MIN_LENGTH
             . ' characters. A few words together — like "silk garden morning" — is easy to remember and hard to guess.';
    }

    // bcrypt silently truncates at 72 BYTES, so anything beyond that adds no
    // security while giving a false sense of it. The cap is well above any
    // reasonable passphrase and exists to stop megabyte payloads being hashed.
    if ($len > PASSWORD_MAX_LENGTH) {
        return 'Please keep your password under ' . PASSWORD_MAX_LENGTH . ' characters.';
    }

    // A password that is only whitespace passes a naive length check.
    if (trim($password) === '') {
        return 'Please enter a real password.';
    }

    // The handful that get tried first in every credential-stuffing run. Not a
    // dictionary — length already covers most of it — just the obvious ones
    // that happen to be long enough to slip through.
    $lower = mb_strtolower($password);
    foreach (['password', 'qwerty', '123456', 'iloveyou', 'letmein', 'admin', 'welcome', 'dievon'] as $bad) {
        if (str_contains($lower, $bad)) {
            return 'That password contains a very common word. Please choose something less guessable.';
        }
    }

    // Their own email or name is the first thing an attacker who knows the
    // account will try.
    foreach ([$email, $name] as $personal) {
        $personal = mb_strtolower(trim($personal));
        if ($personal !== '' && mb_strlen($personal) >= 4 && str_contains($lower, $personal)) {
            return 'Please choose a password that does not contain your own name or email address.';
        }
    }

    // A single repeated character reaches any length ("aaaaaaaaaaaa").
    if (preg_match('/^(.)\1+$/u', $password)) {
        return 'Please choose a password with more variety than one repeated character.';
    }

    return null;
}

/* ═══════════════════════════════════════════════════════════
   LOGIN THROTTLING
   ═══════════════════════════════════════════════════════════
   Nothing previously limited password guessing: an attacker could try the whole
   of rockyou.txt against one account as fast as the server would answer.

   Two counters, because either alone leaks:
     - per IDENTIFIER (the email or phone typed) stops one account being ground
       down, but not one attacker spraying one common password at many accounts;
     - per IP stops the spray, but not a botnet with an address each.

   The response is a temporary lock, not a permanent one. Permanent lockout on
   failed attempts hands anyone who knows a customer's email a way to lock them
   out of their own account at will.
   ═══════════════════════════════════════════════════════════ */
// 10 rather than 5, per identifier.
//
// Five was tight enough to lock the shop owner out of his own admin panel twice
// in two days: one mistyped password followed by three or four quick retries
// reached the limit, and from then on the correct password was refused too — the
// page says "too many attempts", but it reads as "my password stopped working".
// Both lockouts were the owner, on his own account, from his own address.
//
// Ten does not meaningfully help an attacker. The passwords are bcrypt at cost
// 10, so each guess costs real CPU time; the meaningful defence is the per-IP
// ceiling and the lock itself, not the difference between five tries and ten.
// A limit that fires on ordinary human mistyping is a limit people work around,
// and the usual workaround is a shorter password.
const LOGIN_MAX_PER_IDENTIFIER = 10;     // within the window, per email/phone
const LOGIN_MAX_PER_IP         = 20;     // within the window, per source address
const LOGIN_WINDOW_MINUTES     = 15;
const LOGIN_LOCK_MINUTES       = 15;

/** Record one failed sign-in. Never called on success. */
function recordFailedLogin(?PDO $pdo, string $identifier): void {
    if (!($pdo instanceof PDO)) { return; }
    try {
        $pdo->prepare("INSERT INTO login_attempts (identifier, ip_address) VALUES (:id, :ip)")
            ->execute([
                ':id' => mb_strtolower(trim($identifier)),
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);
    } catch (PDOException $e) {
        // Table missing (pre-migration) must not block signing in.
        error_log('recordFailedLogin: ' . $e->getMessage());
    }
}

/**
 * Seconds the caller must wait, or 0 when they may try now.
 *
 * Returning a duration rather than a boolean lets the page tell the customer
 * how long to wait, which is the difference between "try later" and a form
 * that appears broken.
 */
function loginLockRemaining(?PDO $pdo, string $identifier): int {
    if (!($pdo instanceof PDO)) { return 0; }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    try {
        // The seconds remaining are computed BY MYSQL, comparing its own NOW()
        // against its own stored timestamps. Reading MAX(attempted_at) into PHP
        // and subtracting time() looked correct and was not: PHP and MySQL were
        // an hour apart here, so a 15 minute lock was announced as 75 minutes.
        // Never mix a timestamp from one clock with "now" from another.
        //
        // INTERVAL will not accept a bound parameter, so both windows are
        // interpolated from integer constants — never from user input.
        $window = (int)LOGIN_WINDOW_MINUTES;
        $lock   = (int)LOGIN_LOCK_MINUTES;

        $sql = "SELECT COUNT(*) AS c,
                       GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), MAX(attempted_at) + INTERVAL {$lock} MINUTE)) AS secs_left
                  FROM login_attempts
                 WHERE %s AND attempted_at > (NOW() - INTERVAL {$window} MINUTE)";

        $q = $pdo->prepare(sprintf($sql, 'identifier = :id'));
        $q->execute([':id' => mb_strtolower(trim($identifier))]);
        $byId = $q->fetch(PDO::FETCH_ASSOC) ?: ['c' => 0, 'secs_left' => 0];

        $q2 = $pdo->prepare(sprintf($sql, 'ip_address = :ip'));
        $q2->execute([':ip' => $ip]);
        $byIp = $q2->fetch(PDO::FETCH_ASSOC) ?: ['c' => 0, 'secs_left' => 0];

        $remaining = 0;
        if ((int)$byId['c'] >= LOGIN_MAX_PER_IDENTIFIER) { $remaining = (int)$byId['secs_left']; }
        if ((int)$byIp['c'] >= LOGIN_MAX_PER_IP)         { $remaining = max($remaining, (int)$byIp['secs_left']); }

        return $remaining > 0 ? $remaining : 0;

    } catch (PDOException $e) {
        // Fail OPEN: a broken throttle table must not lock every customer out
        // of the shop. The risk of an unthrottled login is smaller than the
        // certainty of nobody being able to sign in.
        error_log('loginLockRemaining: ' . $e->getMessage());
        return 0;
    }
}

/** Wipe the failure history for an identifier. Called after a successful login. */
function clearFailedLogins(?PDO $pdo, string $identifier): void {
    if (!($pdo instanceof PDO)) { return; }
    try {
        $pdo->prepare("DELETE FROM login_attempts WHERE identifier = :id")
            ->execute([':id' => mb_strtolower(trim($identifier))]);
    } catch (PDOException $e) {
        error_log('clearFailedLogins: ' . $e->getMessage());
    }
}

/** "3 minutes" / "45 seconds" — for telling the customer how long is left. */
function humaniseSeconds(int $seconds): string {
    if ($seconds >= 60) {
        $m = (int)ceil($seconds / 60);
        return $m . ' minute' . ($m === 1 ? '' : 's');
    }
    return max(1, $seconds) . ' second' . ($seconds === 1 ? '' : 's');
}

/* ═══════════════════════════════════════════════════════════
   EMAIL VERIFICATION
   ═══════════════════════════════════════════════════════════
   Registration used to claim every guest order placed with the typed email
   — and the phone, if one was given — the moment the account was created.
   Nothing proved the address was theirs: an attacker who knew a guest's
   email (or phone) registered with it and instantly owned that guest's
   order history, name, address and invoices. Six of every seven orders on
   this shop are guest orders, so that was the rule, not the exception.

   Now the account starts unverified. Guest orders are linked ONLY after the
   customer proves they own the address by clicking a link emailed to it,
   and phone numbers are never a factor at all (a phone is not proof of
   identity — the registration form asks for it optionally and nothing
   verifies it).
   ═══════════════════════════════════════════════════════════ */
const EMAIL_VERIFY_TTL_HOURS  = 24;    // the link expires after this long
// Cooldown between verification emails — roughly the cadence the password-reset
// flow enforces (3 per 15 minutes ≈ one every 5), so an unverified address can
// not be used as a mail cannon.
const EMAIL_VERIFY_RESEND_MIN  = 5;

/** Has this customer proved they own their email address? */
function isCustomerEmailVerified(array $customer): bool
{
    return !empty($customer['email_verified_at']);
}

/**
 * Attach this customer's unclaimed guest orders (email match only).
 *
 * The single safe moment to do this is after the customer has proved they
 * own the address — from the verification link, or from a sign-in where the
 * address is already verified. Never by phone, and never on a bare
 * registration.
 */
function linkGuestOrdersByEmail(PDO $pdo, int $customerId, string $email): void
{
    $email = strtolower(trim($email));
    if ($email === '' || $customerId <= 0) { return; }
    try {
        $pdo->prepare(
            "UPDATE orders SET customer_id = :cid
              WHERE (customer_id IS NULL OR customer_id = 0)
                AND LOWER(customer_email) COLLATE utf8mb4_unicode_ci = :email"
        )->execute(['cid' => $customerId, 'email' => $email]);
    } catch (PDOException $e) {
        error_log('linkGuestOrdersByEmail: ' . $e->getMessage());
    }
}

/**
 * Issue a fresh verification token for an unverified account.
 *
 * Returns the RAW token to build the emailed link with (the database only
 * ever holds its SHA-256 hash), or null when nothing can be issued — the
 * account is already verified, or a token was sent too recently to resend
 * (the mail cannon guard). All expiry/rate comparisons run in MySQL against
 * its own NOW(), matching how the reset-flow timestamps are handled.
 */
function issueEmailVerification(PDO $pdo, int $customerId): ?string
{
    if ($customerId <= 0) { return null; }
    try {
        $st = $pdo->prepare("SELECT email_verified_at FROM customers WHERE id = :id");
        $st->execute(['id' => $customerId]);
        $c = $st->fetch();
        if (!$c || !empty($c['email_verified_at'])) { return null; }

        $rate = $pdo->prepare(
            "SELECT COUNT(*) FROM customers
              WHERE id = :id
                AND (email_verify_sent_at IS NULL
                     OR email_verify_sent_at <= (NOW() - INTERVAL " . (int)EMAIL_VERIFY_RESEND_MIN . " MINUTE))"
        );
        $rate->execute(['id' => $customerId]);
        if ((int)$rate->fetchColumn() === 0) { return null; }

        $raw = bin2hex(random_bytes(32));
        $upd = $pdo->prepare(
            "UPDATE customers
                SET email_verify_token = :t,
                    email_verify_token_expires = NOW() + INTERVAL " . (int)EMAIL_VERIFY_TTL_HOURS . " HOUR,
                    email_verify_sent_at = NOW()
              WHERE id = :id"
        );
        $upd->execute(['t' => hash('sha256', $raw), 'id' => $customerId]);
        return $raw;
    } catch (PDOException $e) {
        error_log('issueEmailVerification: ' . $e->getMessage());
        return null;
    }
}

/* ═══════════════════════════════════════════════════════════
   SELLING IN OTHER COUNTRIES
   ═══════════════════════════════════════════════════════════
   A country is only offered to shoppers when its store_countries row is
   enabled. Until then the shop behaves exactly as an India-only shop: the
   selector never renders, getCurrentCurrency() returns the home currency, and
   every price comes from products.price as before.

   Prices are STORED per country, never converted. £59 is a price chosen for
   that market — covering duty, freight and positioning — not ₹4,999 through a
   rate. A live rate would print £47.49 and would quietly eat margin every time
   the rupee moved.
   ═══════════════════════════════════════════════════════════ */

/** Every country row, enabled or not. Read once per request. */
function allStoreCountries(): array {
    static $cache = null;
    if ($cache !== null) { return $cache; }

    $pdo = $GLOBALS['pdo'] ?? null;
    if (!($pdo instanceof PDO)) { return $cache = []; }
    try {
        $rows = $pdo->query("SELECT * FROM store_countries ORDER BY sort_order ASC, country_name ASC")
                    ->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Table missing (pre-migration) — behave as a single-country shop.
        return $cache = [];
    }
    $out = [];
    foreach ($rows as $r) { $out[strtoupper($r['country_code'])] = $r; }
    return $cache = $out;
}

/** Only the countries actually switched on in admin. */
function enabledCountries(): array {
    return array_filter(allStoreCountries(), fn($c) => (int)$c['is_enabled'] === 1);
}

/** The shop's own country. Falls back to India if nothing is flagged home. */
function homeCountryRow(): ?array {
    foreach (allStoreCountries() as $c) {
        if ((int)$c['is_home'] === 1) { return $c; }
    }
    return allStoreCountries()['IN'] ?? null;
}

/**
 * Show the country chooser only when there is a genuine choice.
 *
 * One enabled country means no decision to make, so the modal never appears —
 * which is why turning on international selling is a single switch in admin.
 */
function countrySelectorEnabled(): bool {
    return count(enabledCountries()) > 1;
}

/* ══════════════════════════════════════════════════════════════════════════
   EXCHANGE RATES — for SUGGESTING an international price, nothing else
   ──────────────────────────────────────────────────────────────────────────
   Read this before using any of it: no rate here is ever applied to a price a
   shopper sees. productCountryPricing() still reads product_country_prices and
   only that. These functions exist so the product form can PRE-FILL the
   international boxes when a price is typed in rupees; the admin can overwrite
   the suggestion, and whatever is in the box is what gets stored.

   That separation is deliberate. A live rate moving overnight must never
   silently change what a garment costs — a shopper who saw £68 yesterday sees
   £68 today. Converting at display time would also mean a rate outage changes
   prices, which is the worst possible failure for a shop.

   The rate is expressed as UNITS OF THE FOREIGN CURRENCY PER 1 INR, because
   INR is the home currency and the product form types rupees first.
   ══════════════════════════════════════════════════════════════════════════ */

/** Rates older than this are refetched; anything newer is served from the DB. */
define('DIEVON_FX_MAX_AGE_HOURS', 12);

/**
 * Ask an exchange-rate service for the current rates against INR.
 *
 * Returns [CURRENCY => rate] for whatever it could get, or [] on any failure.
 * Never throws and never blocks for long: this runs inside an admin page load,
 * so a slow or dead service must cost a couple of seconds, not the page.
 *
 * open.er-api.com is used because it needs no API key and covers the
 * currencies an Indian shop actually exports to — including AED, which the
 * ECB-backed alternatives do not publish.
 */
function fxFetchLiveRates(array $currencies): array {
    $currencies = array_values(array_unique(array_filter(array_map(
        static fn($c) => strtoupper(trim((string)$c)), $currencies
    ))));
    if (!$currencies) { return []; }

    $url  = 'https://open.er-api.com/v6/latest/INR';
    $body = null;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_USERAGENT      => 'Dievon/1.0 (+admin exchange rates)',
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code < 200 || $code >= 300) {
            error_log('fxFetchLiveRates: HTTP ' . $code . ' ' . $err);
            $body = null;
        }
    }

    // Shared hosting sometimes has cURL off but allow_url_fopen on.
    if ($body === null && ini_get('allow_url_fopen')) {
        $ctx  = stream_context_create(['http' => ['timeout' => 8, 'header' => "User-Agent: Dievon/1.0\r\n"]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) { $body = null; }
    }

    if ($body === null) { return []; }

    $json = json_decode($body, true);
    if (!is_array($json) || ($json['result'] ?? '') !== 'success' || !is_array($json['rates'] ?? null)) {
        error_log('fxFetchLiveRates: unexpected payload');
        return [];
    }

    $out = [];
    foreach ($currencies as $cur) {
        $rate = $json['rates'][$cur] ?? null;
        if (is_numeric($rate) && (float)$rate > 0) { $out[$cur] = (float)$rate; }
    }
    return $out;
}

/**
 * Refresh the stored rate for every enabled country, and hand back what is
 * now on record.
 *
 * $force ignores the age check — that is the "Refresh rates now" button. Left
 * alone it only calls out when the oldest rate is over DIEVON_FX_MAX_AGE_HOURS,
 * so opening the product form twenty times in an afternoon makes one request.
 *
 * A failed fetch is NOT an error state. The previously stored rate stays in
 * place and is returned marked stale, so the form still suggests something and
 * the admin screens can say how old it is. That is the whole reason the rate
 * is a column rather than a transient.
 *
 * Returns [CODE => ['rate'=>float|null, 'updated_at'=>?string, 'stale'=>bool]].
 */
/**
 * Why there is no usable exchange rate — '' when there is one.
 *
 * 'schema'    the fx_rate columns do not exist, so update_new_database.php has
 *             not been run on this server. Nothing will ever fetch a rate until
 *             it is, and no amount of retrying helps.
 * 'unfetched' the columns are there but every enabled country is still NULL, so
 *             the request to the rate service never succeeded. Usually a host
 *             that blocks outbound HTTPS; the fix is to type a rate by hand.
 *
 * Called with an argument it records; called without one it reports. The two
 * causes look identical from outside — both end with no price suggestion — and
 * telling an admin to "type them by hand" when the real answer is "run the
 * database update" is how a five-minute fix becomes an afternoon.
 */
function fxRatesProblem(?string $set = null): string {
    static $reason = '';
    if ($set !== null) { $reason = $set; }
    return $reason;
}

function fxRatesForCountries(?PDO $pdo = null, bool $force = false): array {
    $pdo = $pdo ?: ($GLOBALS['pdo'] ?? null);
    if (!($pdo instanceof PDO)) { return []; }

    static $cache = null;
    if ($cache !== null && !$force) { return $cache; }

    try {
        $rows = $pdo->query(
            "SELECT country_code, currency_code, is_home, fx_rate, fx_rate_updated_at
               FROM store_countries WHERE is_enabled = 1"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Column missing means update_new_database.php has not been run yet.
        // Recorded rather than merely returned empty: "no rates" and "no rate
        // COLUMN" need completely different things done about them, and the
        // admin screens could not tell them apart from an empty array.
        fxRatesProblem('schema');
        return $cache = [];
    }

    $maxAge  = DIEVON_FX_MAX_AGE_HOURS * 3600;
    $now     = time();
    $wanted  = [];
    foreach ($rows as $r) {
        if ((int)$r['is_home'] === 1) { continue; }
        $age = $r['fx_rate_updated_at'] ? ($now - strtotime((string)$r['fx_rate_updated_at'])) : PHP_INT_MAX;
        if ($force || $r['fx_rate'] === null || $age > $maxAge) {
            $wanted[] = strtoupper((string)$r['currency_code']);
        }
    }

    if ($wanted) {
        $live = fxFetchLiveRates($wanted);
        if ($live) {
            $upd = $pdo->prepare(
                "UPDATE store_countries SET fx_rate = :r, fx_rate_updated_at = NOW() WHERE country_code = :c"
            );
            foreach ($rows as &$r) {
                $cur = strtoupper((string)$r['currency_code']);
                if ((int)$r['is_home'] === 1 || !isset($live[$cur])) { continue; }
                try {
                    $upd->execute([':r' => $live[$cur], ':c' => strtoupper((string)$r['country_code'])]);
                    $r['fx_rate']            = $live[$cur];
                    $r['fx_rate_updated_at'] = date('Y-m-d H:i:s');
                } catch (PDOException $e) { /* keep the old rate */ }
            }
            unset($r);
        }
    }

    $out = [];
    foreach ($rows as $r) {
        $code = strtoupper((string)$r['country_code']);
        $rate = $r['fx_rate'] !== null ? (float)$r['fx_rate'] : null;
        if ((int)$r['is_home'] === 1) { $rate = 1.0; }
        $age  = $r['fx_rate_updated_at'] ? ($now - strtotime((string)$r['fx_rate_updated_at'])) : null;
        $out[$code] = [
            'rate'       => $rate,
            'currency'   => strtoupper((string)$r['currency_code']),
            'updated_at' => $r['fx_rate_updated_at'],
            'stale'      => (int)$r['is_home'] !== 1 && ($age === null || $age > $maxAge),
        ];
    }

    /* One pass to say whether anything usable came back. Two things it must not
       get wrong: a home country always has a rate of 1, so it is no evidence
       that the fetch worked; and a shop selling only at home has no rate to
       fetch and no problem to report. */
    $foreign = 0; $usable = 0;
    foreach ($rows as $r) {
        if ((int)$r['is_home'] === 1) { continue; }
        $foreign++;
        if ($r['fx_rate'] !== null) { $usable++; }
    }
    fxRatesProblem(($foreign === 0 || $usable > 0) ? '' : 'unfetched');

    if (!$force) { $cache = $out; }
    return $out;
}

/**
 * Convert a rupee amount into a country's currency, rounded the way a price
 * tag is rounded.
 *
 * ceil to a whole unit, not round(): 7230 INR at 0.0094 is 67.96, and rounding
 * down to 67 gives away margin on every single sale of that garment. Rounding
 * up costs the shopper 4p and keeps the intended markup. It is a suggestion in
 * a form either way — the admin sees the number before it is saved.
 *
 * Returns null when there is no usable rate, which the caller must treat as
 * "leave the box empty" rather than as zero.
 */
function fxConvertFromInr(float $inr, ?float $rate): ?float {
    if ($rate === null || $rate <= 0 || $inr <= 0) { return null; }
    return (float)ceil($inr * $rate);
}


/**
 * The country this visitor is shopping in.
 *
 * Cookie-driven, but always validated against the ENABLED list — a stale cookie
 * naming a country that was later switched off must not keep showing its prices.
 */
function currentCountryCode(): string {
    static $cache = null;
    if ($cache !== null) { return $cache; }

    $enabled = enabledCountries();
    $home    = homeCountryRow();
    $fallback = $home['country_code'] ?? 'IN';

    if (!$enabled) { return $cache = $fallback; }

    /* The admin panel is not a shopper and has no country.
       ────────────────────────────────────────────────────────────────────────
       This cookie is set by the storefront's country picker, and every admin
       page loads this same config — so switching the shop to the US to check a
       price silently relabelled the whole back office in dollars. Total Revenue
       read "$19,992.00" for 126 orders that are, every one of them, in rupees.
       Not a conversion: the same number wearing the wrong sign, which is worse,
       because it looks like a figure someone could act on.

       The back office reports in the shop's OWN currency, always. What a
       customer chose to browse in is none of its business. Keyed on the request
       path rather than a session flag so it holds for every admin entry point,
       including the handlers that never include the admin header. */
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '');
    if (strpos($script, '/admin/') !== false) {
        return $cache = $fallback;
    }

    $picked = strtoupper(trim((string)($_COOKIE['dievon_country'] ?? '')));
    if ($picked !== '' && isset($enabled[$picked])) { return $cache = $picked; }

    return $cache = (isset($enabled[$fallback]) ? $fallback : array_key_first($enabled));
}

/** The full row for the current country. */
/**
 * The currency an order should actually be CHARGED in, and how to express it in
 * the smallest unit a payment gateway wants.
 *
 * Razorpay was handed the string 'INR' in three places with a comment saying
 * standard accounts only settle in rupees. True of the account, not of the code:
 * it meant a shopper shown £45 was charged 45 in RUPEES — off by a factor of a
 * hundred in the shop's favour, which is worse than refusing the payment.
 *
 * The country row already carries currency_code, so the answer was always in the
 * database; nothing asked it. Reading it here means enabling a country under
 * Countries We Sell To is the only step — no code change waiting on it.
 *
 * `minor` is the multiplier to the smallest unit. It is 100 for every currency
 * this shop can currently sell in (paise, pence, cents, fils), but it is NOT
 * universal — yen has no minor unit and dinars have three digits — so the
 * exceptions are named rather than assumed. Getting this wrong is a hundredfold
 * over- or under-charge, which is the one arithmetic error worth spelling out.
 */
function checkoutCurrency(?string $countryCode = null): array
{
    $row  = null;
    $code = strtoupper(trim((string)($countryCode ?: currentCountryCode())));

    foreach (enabledCountries() as $cc => $c) {
        if (strtoupper((string)$cc) === $code) { $row = $c; break; }
    }
    if ($row === null) { $row = homeCountryRow(); }

    $currency = strtoupper(trim((string)($row['currency_code'] ?? 'INR'))) ?: 'INR';

    // Currencies with no minor unit, and those with three digits rather than two.
    $noMinor    = ['JPY', 'KRW', 'VND', 'CLP', 'ISK'];
    $threeMinor = ['KWD', 'BHD', 'OMR', 'JOD', 'TND'];
    $minor = in_array($currency, $noMinor, true) ? 1
           : (in_array($currency, $threeMinor, true) ? 1000 : 100);

    return [
        'code'   => $currency,
        'symbol' => (string)($row['currency_symbol'] ?? '₹'),
        'minor'  => $minor,
        'is_home'=> strtoupper((string)($row['country_code'] ?? '')) === strtoupper((string)(homeCountryRow()['country_code'] ?? 'IN')),
    ];
}

function currentCountry(): ?array {
    return allStoreCountries()[currentCountryCode()] ?? homeCountryRow();
}

/**
 * The free-delivery threshold for the country being browsed.
 *
 * store_countries already carries free_shipping_min per country — IN 2000,
 * US 300, AE 900 — and nothing read it. Every "free delivery over ..." line on
 * the shop used the global store_settings figure and passed it through
 * formatPrice(), which stamps the CURRENT country's symbol on it. So a shopper
 * in the States was promised free delivery over "$2,000" when their real
 * threshold is 300, and one in the UAE saw "AED 2,000" against a real 900.
 *
 * The number and the symbol have to come from the same place or the promise is
 * simply false. Falls back to the global setting for a country with no row, and
 * to the same default the callers already used.
 */
function freeShippingMinForCountry(?PDO $pdo = null, ?string $countryCode = null): float {
    $pdo = $pdo ?: ($GLOBALS['pdo'] ?? null);

    $code = strtoupper($countryCode ?: currentCountryCode());
    $row  = allStoreCountries()[$code] ?? null;

    if ($row !== null && array_key_exists('free_shipping_min', $row) && $row['free_shipping_min'] !== null) {
        return (float)$row['free_shipping_min'];
    }

    return (float)storeSetting($pdo, 'free_shipping_min', 2000);
}

/**
 * Price + strikethrough (MRP) to show/charge for this product in the
 * shopper's current country, in one row fetch.
 *
 * Country rows have no per-size or per-colour granularity — a non-home
 * country always prices the whole product the same regardless of which
 * variant is chosen, unlike effectiveVariantPrice() for the home country,
 * because product_country_prices carries one row per product, not one per
 * variant.
 *
 * product_country_prices.sale_price is only ever a saving on top of `price`
 * (mirroring products.price/mrp_price, just named the other way round): a
 * stored sale_price that is 0, null, or not below price prints as no
 * discount rather than a negative or nonsensical one.
 *
 * Returns null when the product is not sold in this country at all — the
 * caller's cue to hide it rather than guess a converted price.
 */
function productCountryPricing(array $product, ?string $countryCode = null): ?array {
    $code = strtoupper($countryCode ?: currentCountryCode());
    $home = strtoupper(homeCountryRow()['country_code'] ?? 'IN');

    // Home country always uses the product's own price/mrp columns.
    if ($code === $home) {
        if (!isset($product['price'])) { return null; }
        return ['price' => (float)$product['price'], 'mrp' => (float)($product['mrp_price'] ?? 0)];
    }

    // A product page calls this once per size/colour via effectiveVariantPrice()
    // — cached per product+country so that does not become one query per
    // variant on the same request.
    static $cache = [];
    $pid = (int)($product['id'] ?? 0);
    $key = $pid . ':' . $code;
    if (array_key_exists($key, $cache)) { return $cache[$key]; }

    $pdo = $GLOBALS['pdo'] ?? null;
    if (!($pdo instanceof PDO) || $pid <= 0) { return $cache[$key] = null; }
    try {
        $st = $pdo->prepare("SELECT price, sale_price FROM product_country_prices WHERE product_id = :pid AND country_code = :cc");
        $st->execute([':pid' => $pid, ':cc' => $code]);
        $row = $st->fetch();
    } catch (PDOException $e) {
        return $cache[$key] = null;
    }
    if ($row === false) { return $cache[$key] = null; }

    $list = (float)$row['price'];
    $sale = $row['sale_price'];
    $sale = ($sale !== null && (float)$sale > 0 && (float)$sale < $list) ? (float)$sale : null;

    return $cache[$key] = ['price' => $sale ?? $list, 'mrp' => $sale !== null ? $list : 0.0];
}

/**
 * The price to charge for a product in the current country.
 *
 * Thin wrapper around productCountryPricing() for callers that only need the
 * charged amount, not the strikethrough — kept so nothing that already reads
 * a single float has to change.
 *
 * Returns null when the product has no price for a non-home country, which is
 * the signal that it is not sold there — used to hide it rather than to show a
 * converted guess.
 */
function productPriceForCountry(array $product, ?string $countryCode = null): ?float {
    $p = productCountryPricing($product, $countryCode);
    return $p['price'] ?? null;
}

/** Whether Cash on Delivery may be offered in the current country. */
function codAllowedInCurrentCountry(): bool {
    $c = currentCountry();
    return $c ? (int)$c['cod_allowed'] === 1 : true;
}

/**
 * The symbol for the currency this shop is trading in right now.
 *
 * Exists because '£' was hardcoded as the fallback in a dozen templates — the
 * price filter on /shop, the invoice, the account pages, coupon messages and the
 * admin dashboard — while the shop actually sells in INR. Anything that needs a
 * bare symbol (rather than a full formatted price) must call this, so there is
 * one place to change when the shop starts selling abroad.
 *
 * Use formatPrice() instead wherever a complete amount is being printed; this is
 * only for labels like "Min Price (₹)" and for legacy fallbacks.
 */
function currencySymbol(?string $code = null): string {
    $c = strtoupper(trim((string)($code ?: getCurrentCurrency())));

    // Prefer the symbol recorded against the country, so a currency the shop
    // trades in (AED, for instance) does not need an entry in CURRENCY_RATES
    // just to render its symbol.
    foreach (allStoreCountries() as $row) {
        if (strtoupper($row['currency_code']) === $c && trim((string)$row['currency_symbol']) !== '') {
            return $row['currency_symbol'];
        }
    }
    return $GLOBALS['CURRENCY_RATES'][$c]['symbol'] ?? '₹';
}

/**
 * Symbol to print for a stored order.
 *
 * Orders record the currency they were placed in, so a historic order keeps its
 * own symbol even if the shop later changes currency. Falls back to the order's
 * currency code, then to the shop's current symbol — never to a hardcoded '£'.
 */
function orderCurrencySymbol(?array $order): string {
    if (!empty($order['currency_symbol'])) { return $order['currency_symbol']; }
    if (!empty($order['currency']))        { return currencySymbol($order['currency']); }
    return currencySymbol();
}

/**
 * Format an amount that is ALREADY in the current country's currency.
 *
 * The parameter name is historical. Since prices became per-country stored
 * values rather than conversions, nothing is multiplied by a rate here: a UK
 * shopper is shown the GBP price that was entered for the UK, not an INR price
 * run through a stale multiplier.
 *
 * For the home country this is identical to the previous behaviour, because
 * INR's rate was 1.00 — the arithmetic was always a no-op there.
 */
/**
 * One notice box, for the whole site.
 * ============================================================================
 * There were SEVEN of these — .alert, .admin-note-superseded, .admin-notice,
 * .settings-hint, .admin-section-desc, .cart-tool-msg, .checkout-alert — across
 * 85 places, each with its own markup and its own CSS. Every one of them was
 * written by hand, so every one of them could get it wrong independently, and
 * several did: the same `display:flex` mistake was found and fixed four
 * separate times in different files, because fixing one taught the others
 * nothing.
 *
 * The specific trap, worth stating because it is not obvious: a flex container
 * makes every CHILD a column. The children of a notice are its icon and the
 * inline pieces of its own sentence — <strong>, <code>, links. So a flexed
 * notice slices its text into narrow columns mid-sentence:
 *
 *     This product is sold by | colour's | photographs and not these.
 *     colourway, so the       |          | Upload the shots under
 *
 * Prose is laid out with normal flow. The icon gets a hanging indent by being
 * positioned, which is the only thing the flex row was ever really for.
 *
 * Usage — the text is trusted HTML so a notice can contain <strong> and links,
 * which is the entire point; escape anything that came from a user first:
 *
 *     echo dvNotice('Stock is managed per <strong>colour</strong> now.');
 *     echo dvNotice('Could not save.', 'danger');
 *     echo dvNotice('Saved.', 'success', 'fa-circle-check');
 *
 * @param string $html  Notice body. Trusted HTML — escape user input yourself.
 * @param string $tone  info | success | warning | danger | muted
 * @param string $icon  Font Awesome class; a sensible one per tone by default.
 */
function dvNotice(string $html, string $tone = 'info', string $icon = ''): string {
    $tones = ['info', 'success', 'warning', 'danger', 'muted'];
    $tone  = in_array($tone, $tones, true) ? $tone : 'info';

    if ($icon === '') {
        $icon = [
            'info'    => 'fa-circle-info',
            'success' => 'fa-circle-check',
            'warning' => 'fa-triangle-exclamation',
            'danger'  => 'fa-triangle-exclamation',
            'muted'   => 'fa-circle-info',
        ][$tone];
    }

    return '<div class="dv-notice dv-notice-' . $tone . '">'
         . '<i class="fa-solid ' . htmlspecialchars($icon, ENT_QUOTES) . '" aria-hidden="true"></i>'
         . '<div class="dv-notice-body">' . $html . '</div>'
         . '</div>';
}

function formatPrice($amountInINR, $includeSymbol = true) {
    $formatted = number_format((float)$amountInINR, 2);
    if (!$includeSymbol) { return $formatted; }

    /* A word needs a space; a sign does not.
       ────────────────────────────────────────────────────────────────────────
       ₹, £ and $ sit tight against the number, which is how everyone writes
       them. The UAE's symbol in store_countries is the literal text "AED", and
       the same concatenation produced "AED80.00" — a price tag that reads as one
       run-on token and looks like a bug to anyone in that market. Every shop
       that sells there writes "AED 80.00".

       The test is the symbol itself rather than a list of countries, so a symbol
       the owner types in later — "CHF", "kr", "R$" — is spaced correctly without
       anyone having to remember to come back here. */
    $symbol = currencySymbol();
    $needsSpace = (bool)preg_match('/[\p{L}]$/u', $symbol);   // ends in a letter

    return $symbol . ($needsSpace ? ' ' : '') . $formatted;
}

// Machine-readable price in the active currency: converted like formatPrice(), but with
// NO currency symbol and NO thousands separator. Required for schema.org / OpenGraph
// price values — number_format()'s default "1,299.00" is invalid to a parser and would
// silently invalidate the Product rich snippet.
function priceForMachines($amountInINR) {
    // Same no-conversion rule as formatPrice(): the amount handed in is already
    // in the currency named by priceCurrency in the markup, so multiplying here
    // would publish a price that disagrees with the one on the page.
    return number_format((float)$amountInINR, 2, '.', '');
}

// Turns "Aurelia Silk Kurti" into "aurelia-silk-kurti" — used to give uploaded
// image files SEO-friendly names instead of random/generic ones.
/**
 * The extension an uploaded image may be SAVED with, decided by its content.
 *
 * The admin upload paths built the saved filename from
 * pathinfo($_FILES[...]['name'], PATHINFO_EXTENSION) — the uploader's own
 * string — and validated only the MIME type. finfo reads the magic bytes, and a
 * file that begins "GIF89a" and continues with PHP is a valid GIF to finfo and
 * a valid script to the interpreter. GIF also skips the re-encode that would
 * otherwise destroy the payload. Proven in review: a .php uploaded through the
 * product form landed in uploads/products/ and executed on request — full
 * server compromise, reachable by any catalogue-staff account, which is exactly
 * what the role system exists to prevent.
 *
 * Returning the extension for the DETECTED type closes it at the source: an
 * uploaded name can no longer influence what the file is called on disk. An
 * unrecognised type returns null and the caller must refuse the upload.
 *
 * actions/customer_action.php solved the same problem with an extension
 * allowlist and was never vulnerable; this is the stricter form of that rule,
 * put somewhere every handler can reach.
 */
function safeImageExtension(?string $detectedMime): ?string
{
    $map = [
        'image/jpeg' => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];
    return $map[strtolower(trim((string)$detectedMime))] ?? null;
}

function slugify($text, $maxLength = 60) {
    $text = strtolower(trim((string)$text));
    // Drop apostrophes rather than letting them become separators, so
    // "Women's Tops" slugs as "womens-tops" and not "women-s-tops".
    $text = str_replace(["'", "\u{2019}", "\u{2018}", '`'], '', $text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    $text = trim($text, '-');
    if ($text === '') { $text = 'image'; }
    if (strlen($text) > $maxLength) { $text = rtrim(substr($text, 0, $maxLength), '-'); }
    return $text;
}

// Canonical SEO-friendly product URL, e.g. /product/aurelia-silk-kurti-1 — the trailing
/**
 * A category slug that no other category is already using.
 *
 * Lives here rather than in admin/categories.php, where it was defined, because
 * the quick-add handler needs it too and could not reach it — including that
 * page would have run the whole admin screen. That gap is why a category added
 * through the quick-add had NO slug at all: `INSERT INTO categories (name,
 * sort_order)` never set one, so it could never get a /collections/<slug>
 * address and fell back to the older ?category= form for the rest of its life.
 *
 * Falls back to "collection" for a name that slugifies to nothing (an emoji, or
 * CJK text), and gives up on a random suffix after 200 collisions rather than
 * looping forever.
 */
function uniqueCategorySlug(PDO $pdo, string $base, int $ignoreId = 0): string {
    $base = slugify($base, 100);
    if ($base === '' || $base === 'image') { $base = 'collection'; }
    $check = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE slug = :s AND id <> :id");
    $slug = $base;
    $i = 2;
    while (true) {
        $check->execute(['s' => $slug, 'id' => $ignoreId]);
        if ((int)$check->fetchColumn() === 0) { return $slug; }
        $slug = $base . '-' . $i;
        if (++$i > 200) { return $base . '-' . bin2hex(random_bytes(3)); }
    }
}

// id is the real lookup key, so it stays a valid link even if the product is renamed later.
function productUrl($id, $name, ?string $seoUrl = null) {
    // The owner's own slug wins, when they set one.
    //
    // admin/product_form.php has an "SEO URL Slug" field that saves to
    // products.seo_url, and nothing read it — every URL was slugify(name), so
    // the field did nothing at all and a product could not be given a shorter,
    // keyword-led address. Optional third argument rather than a signature
    // change, so existing two-argument callers keep working unchanged.
    //
    // Safe to change an existing product's slug: the rewrite is
    // ^product/[a-z0-9-]+-([0-9]+)$ and resolves on the ID, so the slug is
    // decorative and any link already in the wild still lands on the right page.
    $slug = slugify((string)$seoUrl !== '' ? (string)$seoUrl : (string)$name);
    if ($slug === '') { $slug = slugify((string)$name); }
    return SITE_URL . '/product/' . $slug . '-' . (int)$id;
}

/**
 * The absolute URL of the page being viewed, built on the TRUSTED host only.
 *
 * og:url and the canonical link used to be assembled from $_SERVER['HTTP_HOST']
 * directly — the same poisoned-header hole that SITE_URL had. SITE_URL itself
 * is now allowlisted, so its scheme+host is the one safe base to build on;
 * REQUEST_URI is appended untouched. Works on MAMP too, where the request URI
 * carries the /DievonOrders path prefix that SITE_URL does not.
 */
function currentPageUrl(?string $requestUri = null): string
{
    $scheme = (string)parse_url(SITE_URL, PHP_URL_SCHEME);
    $host   = (string)parse_url(SITE_URL, PHP_URL_HOST);
    // parse_url(PHP_URL_HOST) strips the port, which silently dropped :8888 on
    // MAMP — og:url then claimed http://localhost/DievonOrders/ while the
    // canonical said http://localhost:8888/DievonOrders/. Two different URLs
    // for the same page. Re-add the port when SITE_URL carries one.
    $port   = parse_url(SITE_URL, PHP_URL_PORT);
    $uri    = $requestUri ?? ($_SERVER['REQUEST_URI'] ?? '/');
    return $scheme . '://' . $host . ($port ? ':' . $port : '') . $uri;
}

// ── Published business identity ─────────────────────────────────────────────
// The privacy notice and the terms have to say who a customer is dealing with,
// where their data goes and where to complain. Those are facts about the
// business, not text a developer can write — so the pages read them from store
// settings and mark the gap where one is missing. Printing an invented
// registration number or officer name would be worse than printing none.

/**
 * One published identity field, or null when the owner has not supplied it.
 */
function legalDetail(?PDO $pdo, string $key): ?string {
    $v = trim((string)storeSetting($pdo, $key, ''));
    return $v === '' ? null : $v;
}

/**
 * Everything the policy pages need about the business, in one array.
 */
function legalIdentity(?PDO $pdo): array {
    return [
        'trading_name'    => (string)storeSetting($pdo, 'site_name', SHOP_NAME),
        'legal_name'      => legalDetail($pdo, 'legal_entity_name'),
        'address'         => legalDetail($pdo, 'seller_address'),
        'state'           => legalDetail($pdo, 'seller_state'),
        'gstin'           => legalDetail($pdo, 'seller_gstin'),
        'cin'             => legalDetail($pdo, 'company_cin'),
        'email'           => (string)storeSetting($pdo, 'contact_email', CONTACT_EMAIL),
        'phone'           => legalDetail($pdo, 'contact_phone'),
        'jurisdiction'    => legalDetail($pdo, 'jurisdiction_city'),
        'couriers'        => legalDetail($pdo, 'courier_partners'),
        'grievance_name'  => legalDetail($pdo, 'grievance_officer_name'),
        'grievance_email' => legalDetail($pdo, 'grievance_officer_email'),
        'grievance_phone' => legalDetail($pdo, 'grievance_officer_phone'),
    ];
}

/**
 * Render one identity value for a policy page.
 *
 * A supplied value prints normally. A missing one prints a marked placeholder,
 * so the gap is obvious on the page itself and gets filled in — rather than the
 * page quietly reading as though the business had answered the question.
 */
function legalValue(?string $value, string $whatIsMissing): string {
    if ($value !== null && $value !== '') {
        return '<span class="legal-value">' . nl2br(htmlspecialchars($value)) . '</span>';
    }
    return '<span class="legal-missing" title="Set this in Admin → Store Settings">['
         . htmlspecialchars($whatIsMissing) . ' — to be added]</span>';
}

/** Identity fields that are required before the policy pages are complete. */
function legalIdentityGaps(?PDO $pdo): array {
    $id = legalIdentity($pdo);
    $required = [
        'legal_name'      => 'Legal entity name',
        'address'         => 'Registered business address',
        'jurisdiction'    => 'Courts / jurisdiction city',
        'grievance_name'  => 'Grievance officer name',
        'grievance_email' => 'Grievance officer email',
    ];
    $gaps = [];
    foreach ($required as $k => $label) {
        if (empty($id[$k])) { $gaps[] = $label; }
    }
    return $gaps;
}

/**
 * Catalogue-data problems that make the site publish something untrue.
 *
 * These are data faults, not code faults, so nothing in the codebase can fix
 * them — but every one of them reaches Google and shoppers through the product
 * page, the structured data and the Merchant Center feed, so they need to be
 * visible somewhere the owner will look.
 *
 * Returns a list of ['label', 'detail'] plus EITHER 'products' (rows that can be
 * opened and corrected: id, name, note) or 'ids' (plain strings, for a fault that
 * is not about one product — a size chart belongs to a category, not a garment).
 *
 * 'products' exists so the SEO page can link straight to the edit form. It listed
 * "#70 Aarini Ivory…" as text and left the owner to go and find it, which on a
 * screen that says "fix these before submitting to Google" is the one moment you
 * do not want to send somebody hunting.
 */
function catalogueDataProblems(PDO $pdo): array {
    $problems = [];
    try {
        $rows = $pdo->query(
            "SELECT id, name, brand, fabric, composition, image, image_alt, sleeve, neck, category
               FROM products
              WHERE available = 1 AND (is_deleted = 0 OR is_deleted IS NULL)
              ORDER BY id"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { return $problems; }

    $ownBrands = [mb_strtolower(SHOP_NAME)];
    try {
        foreach ($pdo->query("SELECT name FROM brands") as $b) {
            $ownBrands[] = mb_strtolower(trim($b['name']));
        }
    } catch (PDOException $e) {
        /* A health check that cannot run must not report health.
           This is the list of the shop's OWN brand names, used below to decide
           whether a product is mislabelled. Swallowed, the list is short, the
           check quietly finds fewer problems than exist, and the screen says
           all is well because it failed — the worst answer a warning system can
           give. It still degrades rather than blocking the page; it just says so. */
        error_log('catalogueDataProblems: own-brand list failed: ' . $e->getMessage());
    }

    // Fabric words that appear in garment names, so a name and a fabric field
    // that disagree can be spotted without a dictionary.
    $fabricWords = ['silk', 'cotton', 'linen', 'georgette', 'crepe', 'organza', 'satin',
                    'brocade', 'chiffon', 'velvet', 'rayon', 'chanderi', 'muslin'];

    // Words that name an ACCESSORY. A garment whose photo is called
    // "dievon_leather_handbag" is showing the wrong product — the file exists and
    // opens fine, so nothing else can detect it. Three seed products shipped this
    // way, and the wrong photo reaches the product page, the suggested strip, the
    // category tile on the home page, the share preview and the Merchant feed.
    $accessoryWords = ['handbag', 'bag', 'purse', 'clutch', 'heels', 'shoe', 'sandal',
                       'necklace', 'earring', 'bangle', 'jewel', 'watch', 'belt', 'scarf'];

    $foreignBrand = $fabricClash = $missingImage = $noAlt = $wrongPhoto = [];
    foreach ($rows as $r) {
        $brand = mb_strtolower(trim((string)$r['brand']));
        if ($brand !== '' && !in_array($brand, $ownBrands, true)) {
            $foreignBrand[] = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'note' => 'brand is "' . $r['brand'] . '"'];
        }

        $nameLower = mb_strtolower((string)$r['name']);
        $fabric    = mb_strtolower(trim((string)$r['fabric']));
        /* The fabric only has to CONTAIN the word, not equal it.
           ────────────────────────────────────────────────────────────────────
           This demanded $fabric === $w, so every compound fabric name was
           reported as contradicting itself: "Ivory Embroidered Khadi Cotton
           Short Kurti" recorded as "Khadi Cotton" was listed as a clash, because
           "khadi cotton" is not the literal string "cotton". Six products were
           flagged with a message that named the same fabric twice and told the
           owner to fix a disagreement that was not there — before submitting to
           Google, which makes it read as urgent.

           Pure Silk, Cotton Blend and Chanderi Silk all failed the same way. The
           identical bug was in admin/product_form.php's publish check and was
           fixed there; this copy was missed, which is what a second copy of a
           rule is for.

           A real contradiction is the fabric not mentioning the word at all — a
           "Georgette Kurti" recorded as Silk. */
        foreach ($fabricWords as $w) {
            if (str_contains($nameLower, $w) && $fabric !== '' && !str_contains($fabric, $w)) {
                $fabricClash[] = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'note' => 'recorded as ' . $r['fabric']];
                break;
            }
        }

        $img = trim((string)$r['image']);
        if ($img === '' || !is_file(__DIR__ . '/../uploads/products/' . $img)) {
            $missingImage[] = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'note' => 'no photograph on disk'];
        } else {
            // Only flag when the NAME does not also mention the accessory — a real
            // handbag product called "… Handbag" is correctly named handbag.jpg.
            $imgLower  = mb_strtolower($img);
            $nameLower2 = mb_strtolower((string)$r['name'] . ' ' . (string)$r['category']);
            foreach ($accessoryWords as $w) {
                if (str_contains($imgLower, $w) && !str_contains($nameLower2, $w)) {
                    $wrongPhoto[] = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'note' => 'photo file is ' . $img];
                    break;
                }
            }
        }
        if (trim((string)$r['image_alt']) === '') {
            $noAlt[] = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'note' => 'no image description'];
        }
    }

    if ($foreignBrand) {
        $problems[] = [
            'label'  => 'Products branded as another label',
            'detail' => 'These are published as the brand in the product schema and in the Merchant Center feed. '
                      . 'Listing another company\'s trademark as your brand gets a Merchant Center account suspended.',
            'products' => $foreignBrand,
        ];
    }
    if ($fabricClash) {
        $problems[] = [
            'label'  => 'Fabric contradicts the product name',
            'detail' => 'The name says one fabric and the fabric field says another. Both appear on the page, '
                      . 'and the fabric field also drives the shop filter and the schema material.',
            'products' => $fabricClash,
        ];
    }
    // ── Size-guide gaps ──
    // A garment chart with no sleeve figure is not broken, but it is incomplete:
    // the column is now hidden on the storefront rather than printing a row of
    // dashes, so the omission is invisible to the owner unless it is said here.
    try {
        $sgGaps = [];
        $sgRows = $pdo->query(
            // A chart attached to a PRODUCT is named after that product, and the
            // product is joined so the name can be shown instead of a bare id —
            // and, more importantly, so a chart belonging to an archived or
            // deleted product is dropped entirely.
            //
            // Without that join this reported "product #14 — no sleeve length"
            // for products that had been archived months ago: a deleted product
            // named on the SEO readiness panel, telling the owner to go and fix
            // something that is no longer in the shop. Charts attached to a
            // CATEGORY are unaffected — a category is not deleted with its stock.
            "SELECT COALESCE(cat.name, p.name, '(not linked to anything)') AS owner,
                    c.id AS chart_id,
                    SUM(sc.sleeve IS NULL OR sc.sleeve = 0) AS no_sleeve,
                    SUM(sc.length IS NULL OR sc.length = 0) AS no_length,
                    COUNT(*) AS total
               FROM size_guide_charts c
               JOIN size_guide_content sc ON sc.chart_id = c.id AND sc.measurement_type = 'garment'
               LEFT JOIN categories cat ON cat.id = c.category_id
               LEFT JOIN products p ON p.id = c.product_id
                    AND p.available = 1 AND (p.is_deleted = 0 OR p.is_deleted IS NULL)
              WHERE c.product_id IS NULL OR p.id IS NOT NULL
              GROUP BY c.id, owner"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($sgRows as $g) {
            $missing = [];
            // Bottoms genuinely have no sleeve, so only flag a chart that is missing
            // BOTH, or one whose category is not a bottoms-type garment.
            $isBottoms = stripos((string)$g['owner'], 'bottom') !== false
                      || stripos((string)$g['owner'], 'trouser') !== false
                      || stripos((string)$g['owner'], 'pant') !== false;
            if ((int)$g['no_sleeve'] === (int)$g['total'] && !$isBottoms) { $missing[] = 'sleeve length'; }
            if ((int)$g['no_length'] === (int)$g['total']) { $missing[] = 'garment length'; }
            if ($missing) {
                $sgGaps[] = $g['owner'] . ' — no ' . implode(' and no ', $missing);
            }
        }
        if ($sgGaps) {
            $problems[] = [
                'label'  => 'Size charts missing a measurement',
                'detail' => 'The storefront hides a column that is empty on every row, so these gaps do not show '
                          . 'as broken — but a shopper deciding on a size is not being given the figure.',
                'ids'    => $sgGaps,
            ];
        }
    } catch (PDOException $e) {}

    if ($wrongPhoto) {
        $problems[] = [
            'label'  => 'Photo appears to show a different product',
            'detail' => 'The image filename names an accessory but the product is a garment. The file is valid, '
                      . 'so nothing else catches this — the wrong photo appears on the product page, in the '
                      . 'suggested strip, on the home page category tile, in the share preview and in the '
                      . 'Google Merchant feed.',
            'products' => $wrongPhoto,
        ];
    }
    if ($missingImage) {
        $problems[] = [
            'label'  => 'Main image file is missing',
            'detail' => 'The file named on the product is not in uploads/products, so the page, the share preview '
                      . 'and the feed all point at an image that does not exist.',
            'products' => $missingImage,
        ];
    }
    if ($noAlt) {
        $problems[] = [
            'label'  => 'No image description',
            'detail' => 'What a shopper using a screen reader hears in place of the photograph, and what Google '
                      . 'Images indexes. Without it the product name is used, which describes the product, not the picture.',
            'products' => $noAlt,
        ];
    }
    return $problems;
}

/**
 * The fallback meta description, written from what is actually in stock.
 *
 * Only reached by pages that do not set their own $metaDescription. Those pages
 * should still be given real copy of their own — this exists so the fallback is
 * at least true, and so it changes when the catalogue does.
 */
function dievonDefaultMetaDescription(?PDO $pdo): string {
    static $cached = null;
    if ($cached !== null) { return $cached; }

    $names   = [];
    $genders = [];
    if ($pdo !== null) {
        try {
            foreach ($pdo->query(
                "SELECT c.id, c.name,
                        (SELECT COUNT(*) FROM products p
                          WHERE (p.category_id = c.id OR p.category = c.name)
                            AND p.available = 1 AND (p.is_deleted = 0 OR p.is_deleted IS NULL)) AS live
                   FROM categories c
                  WHERE c.parent_id = 0 OR c.parent_id IS NULL
                  ORDER BY c.sort_order ASC, c.name ASC"
            ) as $r) {
                if ((int)$r['live'] > 0) {
                    $names[] = mb_strtolower($r['name']);
                    // Asked via categoryGender() rather than selected as c.gender
                    // in the query above, deliberately: before the migration runs
                    // that column does not exist, and naming it here would fail
                    // the whole query and blank the description. The helper
                    // swallows that case and answers 'women'.
                    $genders[categoryGender((int)$r['id'])] = true;
                }
            }
        } catch (PDOException $e) {}
    }

    // "womenswear" is a claim about the whole shop, so it has to follow what is
    // actually on sale. While every live category is womenswear this produces
    // the exact sentence the site has always used; it only changes once a men's
    // category genuinely has stock.
    $label = 'womenswear';
    if (isset($genders['men'])) {
        $label = isset($genders['women']) ? 'fashion' : 'menswear';
    }

    $text = $names
        ? SHOP_NAME . " is an Indian $label label. Shop " . humanList($names)
            . ' — hand-finished, with detailed size guides and easy returns across India.'
        : SHOP_NAME . " is an Indian $label label offering hand-finished kurtis, three-piece suits and co-ord sets, with size guides and easy returns across India.";
    // Google shows roughly 155 characters; anything past that is cut mid-sentence.
    $cached = trimToLength($text, 155);
    return $cached;
}

/**
 * Live top-level category names for one side of the shop, lowercased.
 *
 * Written because three different pages had the phrase "shirts, trousers and
 * tailoring" typed into them by hand. Nothing in the catalogue has ever been
 * called tailoring, and two of those three sentences were byte-identical on two
 * separate indexable URLs. Asking the database instead means the wording cannot
 * drift from the stock, and cannot advertise a category that does not exist.
 *
 * Returns [] when that side has nothing on sale, which callers should treat as
 * "say nothing specific" rather than as an error.
 */
function liveCategoryNamesForGender(?PDO $pdo, string $gender): array {
    static $cache = [];
    if (isset($cache[$gender])) { return $cache[$gender]; }
    if ($pdo === null) { return []; }

    $out = [];
    try {
        foreach ($pdo->query(
            "SELECT c.id, c.name,
                    (SELECT COUNT(*) FROM products p
                      WHERE (p.category_id = c.id OR p.category = c.name)
                        AND p.available = 1 AND (p.is_deleted = 0 OR p.is_deleted IS NULL)) AS live
               FROM categories c
              WHERE c.parent_id = 0 OR c.parent_id IS NULL
              ORDER BY c.sort_order ASC, c.name ASC"
        ) as $r) {
            if ((int)$r['live'] > 0 && categoryGender((int)$r['id']) === $gender) {
                // The name exactly as the owner typed it.
                //
                // This lowercased so the list could sit mid-sentence in a meta
                // description, and compactTitleList() then put capitals back with
                // ucwords() — a round trip that DESTROYS any casing that is not
                // plain Title Case. An acronym, an all-caps promotional name or an
                // internal capital came out mangled: "McQueen Edit" printed as
                // "Mcqueen Edit" on the one line Google shows.
                $out[] = (string)$r['name'];
            }
        }
    } catch (PDOException $e) {
        /* Say something, and do NOT cache the failure.
           ────────────────────────────────────────────────────────────────────
           This swallowed the error and fell through to the cache write below,
           so one failed query left $out empty, stored that empty answer, and
           every later call in the request got it back as fact — the shop
           quietly deciding it has no categories for this side of the store.
           That is the same shape as the bug that switched off international
           selling for good: a query that cannot succeed, an empty catch, and a
           wrong answer nobody can see. Returning without caching means the next
           call tries again rather than inheriting the silence. */
        error_log('liveCategoryNamesForGender(' . $gender . ') failed: ' . $e->getMessage());
        return $out;
    }

    $cache[$gender] = $out;
    return $out;
}

/**
 * Category names formatted for a page TITLE: "Shirts & Trousers".
 *
 * Joining with an ampersand rather than "and" avoids the joining word entirely,
 * and is shorter, which matters against a 60-character title budget — ucwords()
 * over "shirts and trousers" would produce "Shirts And Trousers", with a capital
 * A that reads as a mistake in a search result.
 *
 * The names arrive already cased the way the owner typed them
 * (liveCategoryNamesForGender no longer lowercases), so nothing re-capitalises
 * them here: doing so was what turned "McQueen Edit" into "Mcqueen Edit".
 */
function compactTitleList(array $names): string {
    $names = array_map(static fn($n) => (string)$n, $names);
    if (count($names) <= 1) { return $names[0] ?? ''; }
    $last = array_pop($names);
    return implode(', ', $names) . ' & ' . $last;
}

/**
 * The homepage meta description, in the owner's own wording, sized to fit.
 *
 * Separate from dievonDefaultMetaDescription() on purpose: that one is the
 * site-wide fallback used by every page without a description of its own, and
 * rewording it here would change all of them. This is the homepage's alone.
 *
 * The version this replaced was 174 characters, so Google cut it mid-word at
 * "crafted for modern styl…", and it named "ethnic wear, western wear,
 * occasion wear" — shelf labels rather than the words a shopper types. The
 * categories below come from live stock, so the sentence can only ever
 * advertise things that are genuinely for sale.
 */
function dievonHomeDescription(?PDO $pdo): string {
    static $cached = null;
    if ($cached !== null) { return $cached; }

    $names   = [];
    $genders = [];
    if ($pdo !== null) {
        try {
            foreach ($pdo->query(
                "SELECT c.id, c.name,
                        (SELECT COUNT(*) FROM products p
                          WHERE (p.category_id = c.id OR p.category = c.name)
                            AND p.available = 1 AND (p.is_deleted = 0 OR p.is_deleted IS NULL)) AS live
                   FROM categories c
                  WHERE c.parent_id = 0 OR c.parent_id IS NULL
                  ORDER BY c.sort_order ASC, c.name ASC"
            ) as $r) {
                if ((int)$r['live'] > 0) {
                    $names[] = mb_strtolower($r['name']);
                    $genders[categoryGender((int)$r['id'])] = true;
                }
            }
        } catch (PDOException $e) {}
    }

    // The audience is named only once menswear exists.
    //
    // While the shop is womenswear alone, "kurtis" and "coord sets" already say
    // so and the words would be spent twice. Once both sides trade, that is the
    // fact a reader cannot infer from the category names, so it is stated. It
    // is also the one clause that must never appear early: a description
    // promising menswear to someone who lands on a page of kurtis has bought a
    // click and wasted it.
    $aud = '';
    if (isset($genders['men'])) {
        $aud = isset($genders['women']) ? ' for men and women' : ' for men';
    }

    $open = 'A new expression of Indian fashion at ' . SHOP_NAME . ' — contemporary ';
    $tail = ', with distinctive details and modern silhouettes.';

    // Google stops showing the sentence at roughly 155 characters. Fitting the
    // category names to the space that is actually left means the closing
    // phrase always survives — the owner's first draft ran to 184 and was cut
    // at "modern silhouettes and an elevate", losing the best part of the line.
    $room = 155 - mb_strlen($open) - mb_strlen($aud) - mb_strlen($tail);

    $picked = [];
    foreach ($names as $n) {
        $candidate = humanList(array_merge($picked, [$n]));
        if (mb_strlen($candidate) > $room) { break; }
        $picked[] = $n;
    }

    // With nothing in stock there are no category names to offer, so the
    // sentence still has to stand on its own.
    $cached = $picked
        ? $open . humanList($picked) . $aud . $tail
        : 'A new expression of Indian fashion at ' . SHOP_NAME
          . ' — contemporary pieces' . $aud . $tail;
    return $cached;
}

/**
 * The homepage <title>, derived from what is actually on sale.
 *
 * The counterpart to dievonDefaultMetaDescription(), and written for the same
 * reason: the homepage title is the one line Google shows as the whole brand,
 * and it was a fixed sentence saying "Women's". That sentence is correct while
 * the shop sells only womenswear and wrong the day it does not — and nothing
 * about adding a men's product would have changed it. Somebody would have had
 * to remember, months later, that this string exists.
 *
 * So it is composed instead of stored: the product nouns come from the
 * categories that genuinely have stock, and the audience words from
 * categoryGender(). Put a men's shirt on sale and the title says so by itself;
 * sell out of menswear and it goes back. Nothing to remember, nothing to edit.
 *
 * An explicit title typed into Admin › SEO still wins — see pages/home.php.
 * Leave that field empty to get this.
 */
function dievonHomeTitle(?PDO $pdo): string {
    static $cached = null;
    if ($cached !== null) { return $cached; }

    $names   = [];
    $genders = [];
    if ($pdo !== null) {
        try {
            // Same shape as dievonDefaultMetaDescription's query, deliberately:
            // the two lines appear together in a search result and reading the
            // catalogue two different ways is how they would start to disagree.
            foreach ($pdo->query(
                "SELECT c.id, c.name,
                        (SELECT COUNT(*) FROM products p
                          WHERE (p.category_id = c.id OR p.category = c.name)
                            AND p.available = 1 AND (p.is_deleted = 0 OR p.is_deleted IS NULL)) AS live
                   FROM categories c
                  WHERE c.parent_id = 0 OR c.parent_id IS NULL
                  ORDER BY c.sort_order ASC, c.name ASC"
            ) as $r) {
                if ((int)$r['live'] > 0) {
                    $names[] = $r['name'];
                    $genders[categoryGender((int)$r['id'])] = true;
                }
            }
        } catch (PDOException $e) {}
    }

    // Shape: "Dievon | <what you sell> for <who>".
    //
    // The brand goes first because that is how the owner writes it, and because
    // header.php:149 skips its usual " – Dievon" suffix when the title already
    // names the shop — so this form gets the whole 60 characters instead of 51.
    //
    // The audience words are the point of the exercise. "for Men & Women"
    // appears only while BOTH sides genuinely have stock: a title promising
    // menswear to someone who then finds only kurtis is a broken promise, and a
    // visitor leaving straight away is worse than never having ranked.
    $audience = 'Women';
    if (isset($genders['men'])) {
        $audience = isset($genders['women']) ? 'Men & Women' : 'Men';
    }

    // The audience is the only part that moves.
    //
    // An earlier version listed the live category names here instead, which is
    // better for search — people type "coord sets", nobody types "Premium
    // Indian Fashion". It was rejected on how it read, and rightly: once both
    // sides of the shop have stock the list mixes them, and "Kurtis, Shirts &
    // 3 Piece Suits" is a jumble rather than a sentence. A homepage title is
    // the brand's own voice, so the owner's wording wins over the marginally
    // better keyword.
    //
    // The search terms are not lost, only moved: they still appear in the meta
    // description below, and the category and product pages carry them properly
    // — those, not the homepage, are what rank for "silk kurti online".
    //
    // "for Men & Women" appears only while BOTH sides genuinely have stock. A
    // title promising menswear to someone who finds only kurtis is a broken
    // promise, and a visitor leaving at once costs more than never ranking.
    //
    // header.php:149 skips its usual " – Dievon" suffix because this title
    // already names the shop, so what is returned here is the whole title.
    $cached = SHOP_NAME . ' | Premium Indian Fashion for ' . $audience;
    return $cached;
}

/**
 * Is the shop actually able to take an order from outside its home country?
 *
 * The shipping policy promised "Global courier partners" and named DHL Express
 * and FedEx, while the country selector was off, the currency switcher was off,
 * no country was enabled for selling, and Cash on Delivery is domestic-only. A
 * policy page is a promise; promising delivery the shop cannot perform is worse
 * than saying plainly that it ships within India.
 *
 * Rather than deleting the international copy, the page now asks this — so the
 * moment international selling is switched on, the policy says so by itself.
 */
function shipsInternationally(?PDO $pdo = null): bool {
    // An explicit override, for a shop that ships abroad without using the
    // country selector at all.
    $flag = trim((string)storeSetting($pdo, 'ships_internationally', ''));
    if ($flag !== '') { return $flag === '1'; }

    /* Otherwise: at least one enabled country that is not the home country.
       ────────────────────────────────────────────────────────────────────────
       This asked `SELECT COUNT(*) FROM countries WHERE is_enabled = 1 AND
       UPPER(code) <> …` — but there is no table called `countries` and no column
       called `code`. The real table is `store_countries`, keyed on
       `country_code`. Every call threw, the empty catch below swallowed it, and
       the function returned false.

       That one wrong name switched off selling abroad, completely and silently.
       isDeliverablePostcode() refuses any foreign postcode when this is false,
       so a shopper in London typing SW1A 1AA was told "Please enter a valid
       delivery postcode (for example 380001)" — while the Countries screen said
       the United Kingdom was enabled, the shop was quoting them prices in
       pounds, and their bag was full. No international order could be placed at
       all, and nothing anywhere reported a problem.

       Reads through enabledCountries(), the same accessor the rest of the
       country system uses, so there is no second copy of the table name to get
       wrong again. */
    $home = strtoupper(trim((string)(homeCountryRow()['country_code']
            ?? storeSetting($pdo, 'home_country', 'IN'))));

    foreach (array_keys(enabledCountries()) as $code) {
        if (strtoupper((string)$code) !== $home) { return true; }
    }
    return false;
}

// ── Order codes ─────────────────────────────────────────────────────────────
// The prefix lives here because two places generate a code
// (actions/checkout_action.php and actions/razorpay_webhook.php) and the Returns
// page describes it to customers. That page said to quote "INV-XXXX" while every
// real order is "CB-" + eight digits, so a shopper following the instructions gave
// support a reference that does not exist.
/* DV for Dievon. It was "CB-", which is not this shop's initials and meant every
   confirmation email, invoice and support enquiry carried a reference that says
   nothing about the brand sending it.
   ────────────────────────────────────────────────────────────────────────────
   Safe to change: an order code is stored on the row when the order is placed
   and is only ever looked up by exact match, so the CB- orders already in the
   database keep their codes and keep working — tracking, returns and support
   all still find them. Only new orders are affected. */
if (!defined('ORDER_CODE_PREFIX')) { define('ORDER_CODE_PREFIX', 'DV-'); }

/**
 * A fresh, unused order code.
 *
 * Codes used to be 6 random digits generated with no uniqueness check at all —
 * the 900,000-code space means a ~43% chance of at least one collision by the
 * 1,000th order, and a collision failed the whole checkout, because the INSERT
 * hit the UNIQUE constraint and the order was lost.
 *
 * Now they are 8 random digits (a 90-million-code space: the collision chance
 * by order 1,000 drops to ~0.005%), and the code is checked against the orders
 * table before it is returned. That check is best-effort — two checkouts can
 * still pick the same code in the same instant — so the callers also retry the
 * INSERT on a duplicate-key error (see checkout_action.php and the webhook).
 */
function generateOrderCode(?PDO $pdo = null): string {
    for ($i = 0; $i < 5; $i++) {
        $code = ORDER_CODE_PREFIX . random_int(10000000, 99999999);
        if ($pdo instanceof PDO) {
            try {
                $st = $pdo->prepare("SELECT 1 FROM orders WHERE order_code = :c LIMIT 1");
                $st->execute(['c' => $code]);
                if ($st->fetchColumn()) { continue; }
            } catch (PDOException $e) {
                // Table missing or unreachable — fall through and hand the code
                // back; the INSERT's unique constraint is still the real guard.
            }
        }
        return $code;
    }
    // Five fresh codes all colliding is practically impossible; if it happens,
    // the caller's duplicate-key retry will regenerate anyway.
    return $code;
}

/** The format, written out for a customer, e.g. "CB-12345678". */
function orderCodeExample(): string {
    return ORDER_CODE_PREFIX . '12345678';
}

/**
 * A variant name fit to show a customer.
 *
 * 42 of the 49 variant rows store their name as "Size: M" rather than "M". Every
 * screen that wanted to label it added its own "Size: " in front, producing
 * "Size: Size: M" — in the add-to-cart confirmation, the cart line, the checkout
 * summary and the order confirmation. This strips the stored prefix so the
 * caller can add exactly one.
 */
function variantSizeLabel(?string $variantName): string {
    $v = trim((string)$variantName);
    if ($v === '') { return ''; }
    return trim(preg_replace('/^\s*size\s*:\s*/i', '', $v));
}

/**
 * The customer-facing contact email.
 *
 * CONTACT_EMAIL is a constant read from .env, so editing "Customer Support Email"
 * in the admin changed nothing on the pages that printed the constant directly.
 * The saved setting wins; the constant is the fallback for a site that has never
 * saved its settings.
 */
function shopContactEmail(?PDO $pdo = null): string {
    $v = trim((string)storeSetting($pdo, 'contact_email', ''));
    return $v !== '' ? $v : CONTACT_EMAIL;
}

/**
 * THE product code. One value, one definition, used everywhere.
 *
 * The product page used to show two invented codes for the same garment, in two
 * different accordions: "Atelier Code: MD-0002" (products.atelier_code, falling
 * back to MD- + a zero-padded id) and "SKU: DV-2" (products.sku, falling back to
 * DV- + the raw id). Neither existed in the database — both were generated at
 * render time — so a shopper quoting one to support and support searching the
 * other were looking at different strings for the same piece. The schema
 * published the second of them as the sku.
 *
 * Resolution order: the real SKU, then the atelier code, then a stable fallback.
 */
/**
 * The lowest price a shopper can actually pay for a product, and whether it
 * varies by size.
 *
 * A card showed products.price and nothing else, while every other surface —
 * the product page, Quick View, the cart, checkout — resolves the price
 * through effectiveVariantPrice(), which prefers a size's own price. On a
 * product whose sizes carry their own prices those two answers are different,
 * and the card was advertising a figure the shopper could not get: a product
 * priced 50 with its only size priced 2000 was listed at ₹50 across the shop,
 * the home rails and the wishlist, and charged ₹2,000 at the bag.
 *
 * Returns ['min' => float, 'varies' => bool]. `varies` is what earns the
 * "From" prefix — with one price, or none stored per size, the card reads
 * exactly as it always did.
 *
 * Primed for a whole grid in one query; see productPriceRangePrime().
 */
function productPriceRange(array $product): array
{
    $id   = (int)($product['id'] ?? 0);
    $base = (float)($product['price'] ?? 0);

    if ($id <= 0) { return ['min' => $base, 'max' => $base, 'varies' => false]; }

    if (!array_key_exists($id, $GLOBALS['__dievonPriceRanges'] ?? [])) {
        productPriceRangePrime([$product]);
    }
    $row = $GLOBALS['__dievonPriceRanges'][$id] ?? null;
    if ($row === null) { return ['min' => $base, 'max' => $base, 'varies' => false]; }

    return $row;
}

/**
 * Load the buyable-price spread for a whole grid at once.
 *
 * Every candidate is resolved by CALLING effectiveVariantPrice() rather than by
 * re-expressing its rules here. That matters: the precedence is not obvious —
 * a colour's price_override beats a size's price, a stored size price of 0
 * means "follow the product", and a size dearer than a product that is on sale
 * is clamped back down to the product's price. A second implementation of that
 * ladder would drift from the cart's, which is the very fault this whole
 * function exists to close.
 *
 * Two queries for the grid: one for sizes, one for colours.
 */
function productPriceRangePrime(array $products, ?PDO $pdo = null): void
{
    $pdo = $pdo ?? ($GLOBALS['pdo'] ?? null);
    if (!$pdo instanceof PDO) { return; }

    $want = [];
    foreach ($products as $p) {
        $id = (int)($p['id'] ?? 0);
        if ($id > 0 && !array_key_exists($id, $GLOBALS['__dievonPriceRanges'] ?? [])) {
            $want[$id] = $p;
        }
    }
    if (!$want) { return; }

    $ids = array_keys($want);
    foreach ($ids as $id) {
        $GLOBALS['__dievonPriceRanges'][$id] = ['min' => (float)($want[$id]['price'] ?? 0), 'max' => (float)($want[$id]['price'] ?? 0), 'varies' => false];
    }

    try {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        /* A size belonging to a DEACTIVATED colour is not on sale, so it cannot
           set the price a card advertises.
           ────────────────────────────────────────────────────────────────────
           This selected every available variant by product_id alone, while
           pages/product.php:217 loads colours with `AND is_active = 1`. Switch a
           colourway off in admin and the two disagreed: the product page
           correctly showed no colours, no size pills and "sold out", while the
           card beside it in the grid went on advertising "From ₹1,500.00" —
           the price of a size nobody can buy. Measured on a one-colour product:
           card said From ₹1,500, the page offered nothing and quoted ₹1,900.

           pages/product.php's own comment says deactivating a colour has to hide
           its sizes with it. That reached the page and the merchant feed; the
           shared range helper — which every card, the wishlist, the collection
           meta description and the schema all read — was missed.

           A colour-less variant (color_id IS NULL) is unaffected: it belongs to
           the product, not to a colourway, and stays in the range. */
        /* A SOLD OUT size cannot set the price a card advertises either.
           ────────────────────────────────────────────────────────────────────
           The same argument as the colour rule above, one door further along.
           A size with no stock is drawn on the product page greyed out and
           labelled "Out of stock" — nobody can buy it — yet it went on setting
           the cheapest figure in the grid. Measured on a saree whose smallest
           size was sold out: the card advertised "From ₹300.00" while the page
           quoted ₹3,000 and offered nothing at ₹300 in any colour or size.

           That is worse than untidy. The card is a price advertisement for
           something the shop will not sell at that price.

           The test matches pages/product.php exactly (both the server-rendered
           ladder at :1087 and the colour-swap one at :2437): stock_qty NULL
           means this size is NOT COUNTED, which is not the same as none left,
           so a NULL row stays in the range. Only a real number at or below zero
           is out. Getting that backwards would drop every untracked product to
           its bare product price. */
        $vSt = $pdo->prepare(
            "SELECT v.* FROM product_variants v
               LEFT JOIN product_colors c ON c.id = v.color_id
              WHERE v.product_id IN ($ph)
                AND v.available = 1
                AND (v.color_id IS NULL OR c.is_active = 1)
                AND (v.stock_qty IS NULL OR v.stock_qty > 0)"
        );
        $vSt->execute($ids);
        $variants = $vSt->fetchAll(PDO::FETCH_ASSOC);

        $cSt = $pdo->prepare("SELECT * FROM product_colors WHERE product_id IN ($ph) AND is_active = 1");
        $cSt->execute($ids);
        $colors = $cSt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return;   // leave every product on its own price
    }

    $colorById = [];
    $colorsByProduct = [];
    foreach ($colors as $c) {
        $colorById[(int)$c['id']] = $c;
        $colorsByProduct[(int)$c['product_id']][] = $c;
    }

    $candidates = [];
    foreach ($variants as $v) {
        $pid = (int)$v['product_id'];
        if (!isset($want[$pid])) { continue; }
        // A size belonging to a colourway is priced as that pairing, which is
        // how the cart prices it too.
        $col = (!empty($v['color_id']) && isset($colorById[(int)$v['color_id']]))
            ? $colorById[(int)$v['color_id']] : null;
        $candidates[$pid][] = effectiveVariantPrice($want[$pid], $v, $col);
    }
    // Colourways with no sizes of their own are still separately buyable.
    foreach ($colorsByProduct as $pid => $list) {
        if (!isset($want[$pid]) || !empty($candidates[$pid])) { continue; }
        foreach ($list as $c) {
            $candidates[$pid][] = effectiveVariantPrice($want[$pid], null, $c);
        }
    }

    foreach ($candidates as $pid => $prices) {
        $prices = array_filter($prices, static fn($p) => $p > 0);
        if (!$prices) { continue; }
        $min = min($prices);
        $max = max($prices);
        $GLOBALS['__dievonPriceRanges'][$pid] = [
            'min'    => $min,
            // Carried so the product page can publish an AggregateOffer band —
            // schema.org needs lowPrice AND highPrice, and recomputing the
            // dearest size elsewhere would be a second copy of this ladder.
            'max'    => $max,
            'varies' => ($max - $min) > 0.005,
        ];
    }
}

/**
 * A fresh, unique product SKU in Dievon's existing shape.
 *
 * "DV-" plus the first eight alphanumerics of the name, uppercased, with a
 * numeric suffix when that base is already taken — which is exactly the rule
 * admin/product_form.php has always applied when publishing a product with an
 * empty SKU box. It lived only inside that one save handler, so the duplicate
 * feature could not reach it and invented its own scheme instead, appending
 * "-COPY" and then "-COPY-COPY" on a copy of a copy.
 *
 * One implementation, so a SKU minted by cloning is indistinguishable from one
 * minted by publishing.
 *
 * @param int $excludeId Product whose own SKU should not count as a collision
 *                       (a product being edited is allowed to keep its code).
 */
function generateProductSku(PDO $pdo, string $name, int $excludeId = 0, ?string $category = null): string
{
    /* DV-KUR-0001, not DV-WOMENSLE-2.
       ────────────────────────────────────────────────────────────────────────
       The old rule took the first eight alphanumerics of the product NAME. It
       worked, but what it produced was unreadable and collided constantly:
       "Women's Leaf Print Co-ord Set" and "Women's Leather Belt" both flatten
       to WOMENSLE, so the second one became DV-WOMENSLE-2 — a code that tells
       nobody anything and whose suffix records an accident of naming.

       This is not a private key. It is printed on the product page under
       Product Code & Dimensions, and published as the `sku` in the Product
       schema and the Google Merchant feed. It should read like an inventory
       code, because that is what it is.

       Three letters of the category, then a number that counts within that
       category. Sequence is per-category rather than global so the numbers stay
       small and mean something: DV-KUR-0007 is the seventh kurti.

       EXISTING SKUS ARE NOT TOUCHED. This only runs for a new product whose SKU
       box was left blank — a stored code is always kept (admin/product_form.php
       keeps it on edit, and refuses to change it at all once the product has
       sold), and anything typed by hand always wins. */
    $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$category) ?? '');
    $prefix = $prefix === '' ? 'GEN' : substr($prefix, 0, 3);
    $stem   = 'DV-' . $prefix . '-';

    try {
        /* The next number is read from the SKUs already issued under this
           prefix, not from a counter column — there is no counter to drift, and
           a product deleted from the middle does not make the next one reuse
           its code. CAST is needed because the suffix is text: without it
           '10' sorts below '9' and the sequence would stall at 10. */
        $seqSt = $pdo->prepare(
            "SELECT COALESCE(MAX(CAST(SUBSTRING(sku, :len) AS UNSIGNED)), 0)
               FROM products
              WHERE sku LIKE :like AND SUBSTRING(sku, :len2) REGEXP '^[0-9]+$'"
        );
        $seqSt->execute([
            ':len'  => strlen($stem) + 1,
            ':len2' => strlen($stem) + 1,
            ':like' => $stem . '%',
        ]);
        $next = (int)$seqSt->fetchColumn() + 1;

        /* Still checked for a clash, and still bounded.
           Sequential numbering should make a collision impossible, but two
           admins saving at the same moment would both read the same MAX. The
           loop walks forward rather than failing. */
        $chk = $pdo->prepare("SELECT COUNT(*) FROM products WHERE sku = :s AND id <> :id");
        for ($i = 0; $i < 50; $i++) {
            $try = $stem . str_pad((string)($next + $i), 4, '0', STR_PAD_LEFT);
            $chk->execute([':s' => $try, ':id' => $excludeId]);
            if ((int)$chk->fetchColumn() === 0) { return $try; }
        }
    } catch (PDOException $e) {
        // Fall through rather than block the save. A product without a code is
        // worse than one with an ugly code.
    }
    // The check itself failed, or fifty in a row were taken: this cannot clash.
    return $stem . strtoupper(substr(uniqid(), -5));
}

/**
 * A unique seo_url for a product, derived from its name.
 *
 * The duplicate feature copied seo_url verbatim along with every other column,
 * so cloning a product that had a slug produced two rows claiming the same
 * URL — and nothing in the schema forbids it, so it happened silently and the
 * router then had two candidates for one address.
 */
function generateProductSlug(PDO $pdo, string $name, int $excludeId = 0): string
{
    $base = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? '', '-'));
    if ($base === '') { $base = 'product'; }
    $base = substr($base, 0, 80);

    try {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM products WHERE seo_url = :s AND id <> :id");
        for ($i = 0; $i < 50; $i++) {
            $try = $i === 0 ? $base : $base . '-' . $i;
            $chk->execute([':s' => $try, ':id' => $excludeId]);
            if ((int)$chk->fetchColumn() === 0) { return $try; }
        }
    } catch (PDOException $e) {
        // ignore and fall through
    }
    return $base . '-' . substr(uniqid(), -5);
}

function productDisplayCode(array $product): string {
    $sku = trim((string)($product['sku'] ?? ''));
    if ($sku !== '') { return $sku; }
    $atelier = trim((string)($product['atelier_code'] ?? ''));
    if ($atelier !== '') { return $atelier; }
    return 'DV-' . str_pad((string)(int)($product['id'] ?? 0), 4, '0', STR_PAD_LEFT);
}

/**
 * Sellable units of a product right now.
 *
 * Lived only in actions/cart_action.php, so the product page could not use it —
 * which is why the page and its structured data both said "in stock"
 * unconditionally while the cart refused to add the item.
 */
function availableStock(array $product): int {
    if (array_key_exists('total_stock', $product)) {
        $ts  = (int)($product['total_stock']  ?? 0);
        $dmg = (int)($product['damage_stock'] ?? 0);
        $off = (int)($product['sold_offline'] ?? 0);
        $sol = (int)($product['sold_online']  ?? 0);
        return max(0, $ts - $dmg - $off - $sol);
    }
    return (int)($product['stock_qty'] ?? 0);
}

/**
 * How many units of ONE cart line may still be bought, right now.
 *
 * Returns null when the line is not stock-tracked (untracked variant, or a
 * product with track_stock off) — those have no ceiling. Otherwise an integer,
 * where 0 means the product or variant has gone away or has nothing left.
 *
 * Mirrors the gate in actions/checkout_action.php exactly, deliberately: the
 * cart must not promise a quantity that the order handler will then refuse.
 */
function cartLineStockLimit(PDO $pdo, array $line): ?int {
    $pid = (int)($line['product_id'] ?? 0);
    $vid = (int)($line['variant_id'] ?? 0);
    if ($pid <= 0) { return 0; }

    try {
        $pStmt = $pdo->prepare("SELECT * FROM products WHERE id = :pid AND available = 1 AND COALESCE(is_deleted, 0) = 0");
        $pStmt->execute(['pid' => $pid]);
        $product = $pStmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) { return 0; }   // withdrawn from sale while it sat in the bag

        if ($vid > 0) {
            $vStmt = $pdo->prepare("SELECT * FROM product_variants WHERE id = :vid AND product_id = :pid AND available = 1");
            $vStmt->execute(['vid' => $vid, 'pid' => $pid]);
            $variant = $vStmt->fetch(PDO::FETCH_ASSOC);
            if (!$variant) { return 0; }
            // NULL stock_qty means "this size is not counted", not "none left".
            return $variant['stock_qty'] === null ? null : max(0, (int)$variant['stock_qty']);
        }

        if (!empty($product['track_stock'])) { return max(0, availableStock($product)); }
    } catch (PDOException $e) {
        // A database hiccup must not empty someone's bag. Leave the line alone;
        // checkout re-checks against the database before any order is written.
        error_log('cartLineStockLimit: ' . $e->getMessage());
        return null;
    }

    return null;
}

/**
 * Bring the whole session cart back in line with live stock.
 *
 * The cart is a session array written at add-to-cart time and never looked at
 * again, so anything that reduced stock AFTERWARDS left it stale: the owner
 * removing 5 units in the Stock tab, another shopper buying the last one, or a
 * size being switched off. The bag went on showing 10, the + button went on
 * working, and the shopper only discovered the truth when checkout refused the
 * order — after they had entered an address and chosen how to pay.
 *
 * Called from cartSummary(), so every response the cart endpoint returns — view,
 * add, update, remove — is measured against stock as it stands at that moment.
 *
 * Quantities are only ever reduced, never raised, and every reduction is
 * reported back so the shopper is told what changed. Returns a list of
 * human-readable notices, empty when nothing needed adjusting.
 */
/**
 * Re-price every line in the bag against what the shop charges RIGHT NOW.
 *
 * The line price was frozen at add-to-bag and never revisited, while
 * actions/checkout_action.php re-resolves it through effectiveVariantPrice() at
 * order time. Nothing reconciled the two, so the bag, the checkout summary, the
 * header badge, the promo evaluation and the Razorpay amount all quoted the
 * stale figure and the ORDER was written at the live one. Reproduced end to end:
 * a bag showing ₹2,650 with "Total Due ₹2,650" produced order DV-72280001 at
 * ₹2,900. The customer approved one number and was billed another.
 *
 * That is the same defect effectiveVariantPrice() exists to close — one price,
 * every surface — except across TIME rather than across files. Per-size editing
 * makes it far easier to hit, because a shopkeeper correcting one size's price
 * now moves a figure that bags in flight are still holding.
 *
 * Re-pricing rather than honouring the stale figure is the deliberate choice:
 * the shop must never charge more than the screen last showed, and a bag left
 * open for a fortnight must not buy at a fortnight-old price. The shopper is
 * told either way — every change returns a notice, in the same shape
 * cartRevalidateStock() uses for quantity, so both kinds of adjustment surface
 * through one channel.
 *
 * Historical orders are untouched: order_items.price and orders.items_json are
 * written once at checkout and no screen re-derives them.
 *
 * Returns human-readable notices, empty when nothing moved.
 */
function cartRepriceLive(PDO $pdo): array {
    if (empty($_SESSION['cart']) || !is_array($_SESSION['cart'])) { return []; }

    $notices = [];
    foreach ($_SESSION['cart'] as $key => $line) {
        $pid = (int)($line['product_id'] ?? 0);
        if ($pid <= 0) { continue; }

        try {
            $pSt = $pdo->prepare("SELECT * FROM products WHERE id = :id");
            $pSt->execute(['id' => $pid]);
            $pRow = $pSt->fetch(PDO::FETCH_ASSOC);
            if (!$pRow) { continue; }

            $vRow = null;
            if (!empty($line['variant_id'])) {
                $vSt = $pdo->prepare("SELECT * FROM product_variants WHERE id = :vid AND product_id = :pid");
                $vSt->execute(['vid' => (int)$line['variant_id'], 'pid' => $pid]);
                $vRow = $vSt->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            $cRow = null;
            if (!empty($line['color_id'])) {
                $cSt = $pdo->prepare("SELECT * FROM product_colors WHERE id = :cid AND product_id = :pid");
                $cSt->execute(['cid' => (int)$line['color_id'], 'pid' => $pid]);
                $cRow = $cSt->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            $live = effectiveVariantPrice($pRow, $vRow, $cRow);
            $was  = (float)($line['price'] ?? 0);

            // Half a paisa of tolerance: DECIMAL round-tripping must not be
            // reported to the shopper as a price change.
            if (abs($live - $was) < 0.005) { continue; }

            $_SESSION['cart'][$key]['price'] = $live;

            $label = trim((string)($line['name'] ?? 'An item'));
            $size  = trim(preg_replace('/^\s*size\s*:\s*/i', '', (string)($line['variant_name'] ?? '')));
            if ($size !== '') { $label .= ' (' . $size . ')'; }

            $notices[] = sprintf(
                '%s is now %s (was %s). The new price is what will be charged.',
                $label,
                formatPrice($live),
                formatPrice($was)
            );
        } catch (PDOException $e) {
            // A line we cannot re-price keeps the figure it had rather than
            // vanishing from the bag; checkout re-resolves it regardless.
            continue;
        }
    }
    return $notices;
}

function cartRevalidateStock(PDO $pdo): array {
    if (empty($_SESSION['cart']) || !is_array($_SESSION['cart'])) { return []; }

    $notices = [];
    foreach ($_SESSION['cart'] as $key => $line) {
        $qty = (int)($line['quantity'] ?? 0);
        if ($qty <= 0) { continue; }

        $limit = cartLineStockLimit($pdo, $line);
        if ($limit === null || $limit >= $qty) { continue; }   // untracked, or still fits

        $label = trim((string)($line['name'] ?? 'This item'));
        $size  = trim(preg_replace('/^\s*size\s*:\s*/i', '', (string)($line['variant_name'] ?? '')));
        if ($size !== '') { $label .= ' (Size: ' . $size . ')'; }

        if ($limit <= 0) {
            unset($_SESSION['cart'][$key]);
            $notices[] = $label . ' has sold out and has been removed from your bag.';
        } else {
            $_SESSION['cart'][$key]['quantity'] = $limit;
            $notices[] = 'Only ' . $limit . ' left of ' . $label
                       . ' — your bag has been updated from ' . $qty . ' to ' . $limit . '.';
        }
    }

    return $notices;
}

/**
 * Is this product buyable? Mirrors what actions/cart_action.php will allow.
 *
 * A product that does not track stock is always buyable; one that does is
 * buyable when its own count is positive, or when any size variant has units.
 */
function productIsInStock(array $product, array $variants = [], array $colors = []): bool {
    if (empty($product['track_stock'])) { return true; }

    // Flatten colour-scoped sizes in with the plain ones — both are variant rows.
    $rows = $variants;
    foreach ($colors as $c) {
        foreach (($c['sizes'] ?? []) as $v) { $rows[] = $v; }
    }

    if ($rows) {
        // Variant-managed: mirror productSellableStock() exactly, or the tile and
        // the cart give different answers about the same garment. An untracked
        // size (NULL stock) means the product cannot honestly be called sold out.
        $sum = 0;
        foreach ($rows as $v) {
            if (!array_key_exists('stock_qty', $v) || $v['stock_qty'] === null) { return true; }
            $sum += (int)$v['stock_qty'];
        }
        // Damage and offline sales are recorded at product level only, so they
        // come off the variant sum here for the same reason they do there.
        $sum -= (int)($product['damage_stock'] ?? 0) + (int)($product['sold_offline'] ?? 0);
        return $sum > 0;
    }

    return availableStock($product) > 0;
}

/**
 * An order's line items, decoded and guaranteed to have the keys callers read.
 *
 * items_json is free-form JSON written at checkout, and ~36 places across the
 * shop index straight into it — $it['quantity'], $it['price'], $it['product_id'] —
 * with no guard. One row whose JSON is missing a key therefore sprayed
 * "Undefined array key" warnings across every admin page (the admin header
 * builds its revenue chart from every order), the invoice, the customer's order
 * page and the confirmation page. Three malformed rows were enough to make the
 * whole admin look broken.
 *
 * Normalising once here means a bad row renders as a zero-value line instead of
 * breaking the page it appears on. It cannot repair the data — that is the
 * owner's to look at — but it stops one bad order taking a screen down with it.
 */
function orderItems($itemsJson): array {
    $items = is_array($itemsJson) ? $itemsJson : json_decode((string)$itemsJson, true);
    if (!is_array($items)) { return []; }

    $out = [];
    foreach ($items as $it) {
        if (!is_array($it)) { continue; }
        $out[] = $it + [
            'product_id'   => 0,
            'variant_id'   => null,
            'variant_name' => '',
            'color_id'     => null,
            'color_name'   => '',
            'name'         => 'Item',
            'emoji'        => '',
            'image'        => '',
            'price'        => 0,
            'quantity'     => 0,
        ];
    }
    return $out;
}

/**
 * What one unit of this variant actually costs — the single source of truth.
 *
 * The product page, the cart, the checkout summary and the order handler each
 * worked this out for themselves, and they disagreed. Measured: product 18 was
 * on sale at ₹1,899 (mrp ₹2,799) with size 38/XL priced ₹2,099. The product page
 * quoted ₹2,099, actions/cart_action.php capped it to ₹1,899, the checkout
 * screen showed a ₹1,998 grand total — and actions/checkout_action.php, which
 * had no cap at all, wrote an order for ₹2,099. The customer was charged ₹101
 * more than the last screen they saw, and the ₹99 delivery line vanished on the
 * way because the uncapped subtotal crossed the free-shipping threshold.
 *
 * The cap itself is deliberate and stays: variant prices are edited separately
 * from the product price, so lowering the price in Basic Info to run a sale used
 * to leave the sizes at their old, higher figures — the page advertised the sale
 * and the cart charged the pre-sale amount. Capping only bites while the product
 * is genuinely reduced, so a size that is legitimately dearer still sells at its
 * own price the rest of the time.
 *
 * A colourway's price_override wins outright: it is a deliberate per-colour
 * price, not a stale leftover.
 *
 * Non-home country: product_country_prices carries one flat price per
 * product, not one per variant/colour, so every size and colourway of a
 * product sold abroad charges that same figure — $variant and $color are
 * ignored in that case rather than applying an INR override on top of a
 * different currency's amount. Falls through to the ordinary logic below
 * when the product has no price for that country yet; callers that must
 * refuse an unpriced item (cart, checkout) check productCountryPricing()
 * for null themselves before ever reaching this function.
 */
function effectiveVariantPrice(array $product, ?array $variant = null, ?array $color = null): float {
    if (function_exists('countrySelectorEnabled') && countrySelectorEnabled()) {
        $cp = productCountryPricing($product);
        if ($cp !== null) { return $cp['price']; }
    }

    $productPrice = (float)($product['price'] ?? 0);

    if ($color !== null && isset($color['price_override']) && $color['price_override'] !== null) {
        return (float)$color['price_override'];
    }

    $price = ($variant !== null && isset($variant['price']) && (float)$variant['price'] > 0)
        ? (float)$variant['price']
        : $productPrice;

    /* A size may never be sold above the printed MRP — but the MRP is the
       ceiling, not the base price.
       ────────────────────────────────────────────────────────────────────────
       This clamped to $productPrice, so the moment a product carried an MRP,
       EVERY size priced above the base was reset to the base. Measured on a
       product with a base of ₹10 and a size of ₹2,000: setting any MRP at all
       dropped that size to ₹10, and this function is what the cart, checkout,
       product page and Quick View all charge from — so the shop would have sold
       it for ₹10. On a real garment it silently wipes out a larger size's
       upcharge the instant that product goes on sale.

       Selling above the printed MRP is what the rule exists to prevent, and
       $mrp is that figure. Clamping there keeps the protection and leaves a
       legitimate size upcharge alone: a ₹2,000 size under a ₹2,500 MRP stays
       ₹2,000, and under a ₹1,500 MRP becomes ₹1,500 rather than collapsing.

       Guarded on $productPrice > 0 as before, so a product with no price set
       cannot make every variant free. */
    $mrp = (float)($product['mrp_price'] ?? 0);
    if ($mrp > 0 && $productPrice > 0 && $price > $mrp) {
        $price = $mrp;
    }

    return $price;
}

/**
 * Give an order's stock back — once, and only once.
 *
 * This loop existed in TWO copies (the Orders screen and the customer's own
 * cancel button) and was missing from a third place that needed it (the Returns
 * screen). The copies drifted, and three separate defects came out of it:
 *
 *   - Completing an RMA credited nothing at all. A returned garment stayed
 *     counted as sold for ever, so the shop under-sold its real stock.
 *   - Both copies restored a variant only when the line carried a color_id,
 *     while checkout deducts on variant_id alone — so a SIZE-ONLY variant was
 *     taken at checkout and never given back. Measured: a 2-unit order against
 *     a size holding 5 left it on 3 permanently, with no order behind the gap.
 *   - The customer's cancel answered "stock restored" when it had not been.
 *
 * Idempotent by design: it does nothing unless orders.stock_deducted is set,
 * and clears that flag when it finishes — so re-saving an order, or an owner
 * moving a status twice, cannot credit the same garments back twice.
 *
 * Returns true when it actually restored something.
 */
function restoreOrderStock(PDO $pdo, array $orderRow): bool {
    if (empty($orderRow['stock_deducted'])) { return false; }
    $orderId = (int)($orderRow['id'] ?? 0);
    if ($orderId <= 0) { return false; }

    $items = orderItems($orderRow['items_json']);
    foreach ($items as $item) {
        $pid = (int)($item['product_id'] ?? 0);
        $qty = (int)($item['quantity'] ?? 0);
        if ($pid <= 0 || $qty <= 0) { continue; }

        try {
            $pdo->prepare("UPDATE products SET sold_online = GREATEST(0, sold_online - :qty) WHERE id = :pid AND track_stock = 1")
                ->execute(['qty' => $qty, 'pid' => $pid]);
        } catch (PDOException $e) {
            // Older installs without sold_online keep their count in stock_qty.
            try {
                $pdo->prepare("UPDATE products SET stock_qty = stock_qty + :qty WHERE id = :pid AND track_stock = 1")
                    ->execute(['qty' => $qty, 'pid' => $pid]);
            } catch (PDOException $e2) {}
        }

        // Colour-scoped OR NOT — checkout deducts on variant_id alone
        // (actions/checkout_action.php, "colour-scoped or not"). The WHERE
        // already skips variants that record no per-variant stock.
        if (!empty($item['variant_id'])) {
            try {
                $pdo->prepare("UPDATE product_variants SET stock_qty = stock_qty + :qty WHERE id = :vid AND stock_qty IS NOT NULL")
                    ->execute(['qty' => $qty, 'vid' => (int)$item['variant_id']]);
            } catch (PDOException $e3) {}
        }

        /* The return leg of the stock ledger.
           ────────────────────────────────────────────────────────────────────
           Checkout now writes a 'sold_online' movement when stock goes out, but
           stock coming BACK — a cancellation, a refund, a return — was as
           invisible as the sale used to be. The units reappeared on the Stock
           screen with nothing anywhere saying why, which reads exactly like a
           miscount.

           Logged here rather than at the three call sites (this screen, the
           customer's own cancel button, and the Returns screen) because they all
           come through this one function, and doing it per caller is how the
           three drifted apart before. The reason is the order's new status, so
           the ledger distinguishes a cancellation from a refund from a return.
           Its own try, so a logging failure never blocks the restock. */
        if (function_exists('recordStockMovement')) {
            try {
                $restockReason = strtolower(trim((string)($orderRow['_restock_reason'] ?? 'returned')));
                if (!in_array($restockReason, ['cancelled', 'refunded', 'returned'], true)) {
                    $restockReason = 'returned';
                }
                recordStockMovement(
                    $pdo, $pid,
                    !empty($item['variant_id']) ? (int)$item['variant_id'] : null,
                    $qty,                                   // positive: coming back in
                    $restockReason,
                    'Order ' . (string)($orderRow['order_code'] ?? $orderId),
                    null
                );
            } catch (Throwable $eLog) {
                error_log('Restock movement log failed (order ' . $orderId . '): ' . $eLog->getMessage());
            }
        }
    }

    try {
        $pdo->prepare("UPDATE orders SET stock_deducted = 0 WHERE id = :id")->execute(['id' => $orderId]);
    } catch (PDOException $e) {}

    return true;
}

// ── Category pages: URLs, breadcrumbs and per-category SEO ──────────────────
//
// Every collection page used to share one title ("Collections"), one H1
// ("Dievon Curated Catalog") and one meta description, so to a search engine
// they read as the same page repeated N times and none of them could rank for
// its own term. Nor did a category have a URL of its own — only
// ?category=<name>, which breaks the moment the category is renamed.
//
// The generators below produce a genuinely different title, description, H1 and
// intro for each category from the products actually in it, so a new category
// is correctly optimised the moment it has stock, with no one writing copy.
// Anything the owner types in the admin overrides the generated version.

/** Canonical URL of a category page: /collections/womens-kurtis */
function categoryUrl(array $cat): string {
    $slug = trim((string)($cat['slug'] ?? ''));
    if ($slug !== '') { return SITE_URL . '/collections/' . rawurlencode($slug); }
    // Pre-migration rows have no slug yet — the old query URL still resolves.
    return SITE_URL . '/shop?category=' . urlencode((string)($cat['name'] ?? ''));
}

/**
 * Clean collection URL for a category NAME — what nearly every internal link has.
 *
 * Links across the nav, footer, home page and product pages all pointed at
 * ?category=<name>. Those still work, but they are not the canonical URL, so
 * every one of them was passing its link value to a page that then told Google
 * "the real address is somewhere else". This resolves them to the canonical
 * form. Cached, so a mega-menu of forty links costs one query.
 */
function categoryUrlByName(PDO $pdo, string $name): string {
    static $slugs = null;
    if ($slugs === null) {
        $slugs = [];
        try {
            foreach ($pdo->query("SELECT name, slug FROM categories") as $r) {
                if (!empty($r['slug'])) { $slugs[mb_strtolower(trim($r['name']))] = $r['slug']; }
            }
        } catch (PDOException $e) {}
    }
    $key = mb_strtolower(trim($name));
    if (isset($slugs[$key])) {
        return SITE_URL . '/collections/' . rawurlencode($slugs[$key]);
    }
    /* An unknown name used to be handed back as ?category=<name>, which shop.php
       answers with a hard 404 -- correct for a shopper's typo, wrong for a link
       this site prints itself. Three buttons on the live homepage pointed at
       "3 Piece Suits" and "Coord Sets" while the shop's own categories had been
       renamed to "3 Pis Kurti Set" and "Coord Set", so the primary call to
       action on the landing page went to Page Not Found. A caller asking for a
       category by name has no way to know it has been renamed; degrading to the
       full shop keeps the shopper moving, and a rename can no longer turn a
       button into a dead end. */
    return SITE_URL . '/shop';
}

/** Look a category up by its URL slug. Returns null when there is no match. */
function categoryBySlug(PDO $pdo, string $slug): ?array {
    $slug = trim($slug);
    if ($slug === '') { return null; }
    try {
        $st = $pdo->prepare("SELECT * FROM categories WHERE slug = :s LIMIT 1");
        $st->execute(['s' => $slug]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (PDOException $e) { return null; }
}

/** Root-to-self ancestor chain, for breadcrumbs. */
function categoryTrail(PDO $pdo, array $cat): array {
    $trail = [$cat];
    try {
        $st = $pdo->prepare("SELECT * FROM categories WHERE id = :id LIMIT 1");
        $guard = 0; // categories are user-editable; a bad parent_id must not hang the page
        $parentId = (int)($cat['parent_id'] ?? 0);
        while ($parentId > 0 && $guard++ < 10) {
            $st->execute(['id' => $parentId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) { break; }
            array_unshift($trail, $row);
            $parentId = (int)($row['parent_id'] ?? 0);
        }
    } catch (PDOException $e) {}
    return $trail;
}

/** Join a list into readable English: "silk, crepe and organza". */
function humanList(array $items, string $conjunction = 'and'): string {
    $items = array_values(array_filter(array_map('trim', $items)));
    $n = count($items);
    if ($n === 0) { return ''; }
    if ($n === 1) { return $items[0]; }
    $last = array_pop($items);
    return implode(', ', $items) . ' ' . $conjunction . ' ' . $last;
}

/** Cut to a length without slicing a word in half. */
function trimToLength(string $text, int $max): string {
    $text = trim(preg_replace('/\s+/', ' ', $text));
    if (mb_strlen($text) <= $max) { return $text; }
    $cut = mb_substr($text, 0, $max);
    $space = mb_strrpos($cut, ' ');
    if ($space !== false && $space > $max * 0.6) { $cut = mb_substr($cut, 0, $space); }
    return rtrim($cut, " ,.–—-") . '…';
}

/* ═══════════════════════════════════════════════════════════
   WHO A CATEGORY IS FOR
   ═══════════════════════════════════════════════════════════
   This shop was womenswear-only for its whole life, and the SEO fallbacks say
   so in hardcoded English: a category title appends "for Women" and the Google
   feed stamps every item g:gender=female. Adding a menswear category without a
   flag to read would publish "Shop Men's Shirts for Women".

   Everything below defaults to 'women' whenever the answer is not knowable —
   column missing (pre-migration), row deleted, broken parent chain. That is the
   one default that cannot make an existing page wrong, because every category
   that exists today IS womenswear.
   ═══════════════════════════════════════════════════════════ */

/**
 * The audience a category serves: 'women', 'men' or 'unisex'.
 *
 * Answered from the TOP of the tree, never the row itself. "Shirts" filed under
 * "Men" is menswear without anyone setting the flag twice, and a subcategory can
 * never silently contradict its parent — which is the failure that would put one
 * men's page in the feed as female while its siblings were correct.
 */
function categoryGender(?int $catId): string {
    static $cache = [];
    $catId = (int)$catId;
    if ($catId <= 0) { return 'women'; }
    if (isset($cache[$catId])) { return $cache[$catId]; }

    $pdo = $GLOBALS['pdo'] ?? null;
    if (!($pdo instanceof PDO)) { return 'women'; }

    $answer = 'women';
    try {
        $st = $pdo->prepare("SELECT parent_id, gender FROM categories WHERE id = :id");
        // $seen guards against a category cycle, the same way the size-guide
        // lookup in pages/product.php does — a self-parenting row would
        // otherwise spin here forever and hang every page on the site.
        $seen = [];
        $id   = $catId;
        while ($id > 0 && !isset($seen[$id])) {
            $seen[$id] = true;
            $st->execute([':id' => $id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) { break; }

            $g = strtolower(trim((string)($row['gender'] ?? '')));
            if (in_array($g, ['women', 'men', 'unisex'], true)) { $answer = $g; }

            $parent = (int)($row['parent_id'] ?? 0);
            if ($parent <= 0) { break; }   // top of the tree: this row decides
            $id = $parent;
        }
    } catch (PDOException $e) {
        // Column not added yet. Womenswear is the truth until it is.
        return $cache[$catId] = 'women';
    }
    return $cache[$catId] = $answer;
}

/** The audience a single product serves, via the category it sits in. */
function productGender(array $product): string {
    $catId = (int)($product['category_id'] ?? 0);
    if ($catId > 0) { return categoryGender($catId); }

    // Older rows record the category by NAME with no id — the same legacy
    // fallback includes/header.php uses when it counts live stock.
    $name = trim((string)($product['category'] ?? ''));
    if ($name === '') { return 'women'; }

    $pdo = $GLOBALS['pdo'] ?? null;
    if (!($pdo instanceof PDO)) { return 'women'; }
    try {
        $st = $pdo->prepare("SELECT id FROM categories WHERE name = :n LIMIT 1");
        $st->execute([':n' => $name]);
        $id = (int)$st->fetchColumn();
        return $id > 0 ? categoryGender($id) : 'women';
    } catch (PDOException $e) {
        return 'women';
    }
}

/**
 * The size ladder for an audience.
 *
 * Two files, not one merged list, because the CODES are identical (XS … 5XL)
 * while the numbers are not: on womenswear the numeric is the BUST (30 … 46),
 * on menswear the CHEST (36 … 52). Identical codes mean
 * product_variants.size_code carries the same meaning for every product and
 * needed no migration when menswear arrived.
 *
 * Anything that only wants the codes — the variant validator, the size-guide
 * row validator — can keep requiring size_ladder.php directly, because the code
 * list is the same either way.
 *
 * Falls back to the women's ladder for unknown or missing input, matching every
 * other gender helper here: this shop was womenswear-only for its whole life.
 */
function sizeLadderFor(?string $gender = null): array {
    static $cache = [];
    $g = strtolower(trim((string)$gender));
    if ($g !== 'men') { $g = 'women'; }
    if (isset($cache[$g])) { return $cache[$g]; }
    return $cache[$g] = require __DIR__ . ($g === 'men' ? '/size_ladder_men.php' : '/size_ladder.php');
}

/** The size ladder appropriate to the category a product sits in. */
function sizeLadderForCategory(?int $catId): array {
    return sizeLadderFor(categoryGender($catId));
}

/* ═══════════════════════════════════════════════════════════
   WHICH SIDE OF THE SHOP THE VISITOR IS ON
   ═══════════════════════════════════════════════════════════ */

/**
 * The audiences that actually have something on sale right now.
 *
 * Everything below is gated on this. A Men tab leading to an empty grid is
 * worse than no Men tab, so the shop refuses to advertise a side it cannot
 * fill — the same rule countrySelectorEnabled() applies to countries.
 */
function gendersWithLiveStock(): array {
    static $cache = null;
    if ($cache !== null) { return $cache; }

    $pdo = $GLOBALS['pdo'] ?? null;
    if (!($pdo instanceof PDO)) { return $cache = ['women']; }

    $found = [];
    try {
        $rows = $pdo->query(
            "SELECT c.id,
                    (SELECT COUNT(*) FROM products p
                      WHERE (p.category_id = c.id OR p.category = c.name)
                        AND p.available = 1 AND (p.is_deleted = 0 OR p.is_deleted IS NULL)) AS live
               FROM categories c"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            if ((int)$r['live'] > 0) { $found[categoryGender((int)$r['id'])] = true; }
        }
    } catch (PDOException $e) {
        return $cache = ['women'];
    }
    return $cache = ($found ? array_keys($found) : ['women']);
}

/**
 * Show the Women/Men switch only when both sides are real.
 *
 * Unisex alone is not a choice, so it does not count towards the two.
 */
function shopGenderSelectorEnabled(): bool {
    return count(array_intersect(['women', 'men'], gendersWithLiveStock())) > 1;
}

/**
 * Persist the chosen side so it survives the next click.
 *
 * Silent when headers have already gone out — this is a convenience, and a
 * "headers already sent" warning printed over the page would be a worse bug
 * than a forgotten preference. Not HttpOnly on purpose: it is a display
 * preference, carries nothing private, and front-end code may want to read it.
 */
function rememberShopGender(string $gender): void {
    if (!in_array($gender, ['women', 'men'], true)) { return; }
    if (($_COOKIE['dievon_shop_for'] ?? null) === $gender) { return; }
    if (headers_sent()) { return; }

    $_COOKIE['dievon_shop_for'] = $gender;
    setcookie('dievon_shop_for', $gender, [
        'expires'  => time() + 60 * 60 * 24 * 180,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
}

/**
 * The side of the shop this visitor is browsing: 'women' or 'men'.
 *
 * Order matters. The URL wins over the cookie, because /men has to render
 * menswear for a crawler that carries no cookies at all — otherwise the entire
 * men's half of the shop would be invisible to search.
 *
 * The cookie is then validated against what is actually in stock, exactly as
 * currentCountryCode() validates against enabled countries: a stale cookie
 * saying 'men' must not strand a returning visitor in an empty shop after
 * menswear is switched off.
 */
function currentShopGender(): string {
    static $cache = null;
    if ($cache !== null) { return $cache; }

    // Set by pages/men.php before the header renders.
    $forced = strtolower(trim((string)($GLOBALS['DIEVON_FORCE_SHOP_GENDER'] ?? '')));
    if (in_array($forced, ['women', 'men'], true)) {
        rememberShopGender($forced);
        return $cache = $forced;
    }

    // ?for=women is how the Women tab gets back. Without it, a visitor who had
    // clicked Men would carry that cookie onto the homepage and still be shown
    // menswear after asking for womenswear — the switch would look one-way.
    $asked = strtolower(trim((string)($_GET['for'] ?? '')));
    if (in_array($asked, ['women', 'men'], true)) {
        rememberShopGender($asked);
        return $cache = $asked;
    }

    $picked = strtolower(trim((string)($_COOKIE['dievon_shop_for'] ?? '')));
    if (in_array($picked, ['women', 'men'], true)
        && in_array($picked, gendersWithLiveStock(), true)) {
        return $cache = $picked;
    }
    return $cache = 'women';
}

/**
 * Does a category belong on the side of the shop currently being browsed?
 *
 * Unisex passes both ways on purpose — a scarf does not belong to one half.
 */
function categoryMatchesShopGender(?int $catId, ?string $shopGender = null): bool {
    $g = categoryGender($catId);
    if ($g === 'unisex') { return true; }
    return $g === ($shopGender ?: currentShopGender());
}

/**
 * Every category on one side of the shop, as ids and as names.
 *
 * Names as well as ids because older product rows record their category by name
 * with no category_id — the same dual match includes/header.php uses when it
 * counts stock. Dropping the name half would make legacy products vanish from
 * the grid rather than merely be filed oddly.
 *
 * Returns ['ids' => int[], 'names' => string[]].
 */
function categoryScopeForShopGender(?string $shopGender = null): array {
    static $cache = [];
    $want = $shopGender ?: currentShopGender();
    if (isset($cache[$want])) { return $cache[$want]; }

    $pdo = $GLOBALS['pdo'] ?? null;
    if (!($pdo instanceof PDO)) { return $cache[$want] = ['ids' => [], 'names' => []]; }

    $ids = [];
    $names = [];
    try {
        foreach ($pdo->query("SELECT id, name FROM categories")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            if (categoryMatchesShopGender((int)$c['id'], $want)) {
                $ids[]   = (int)$c['id'];
                $names[] = (string)$c['name'];
            }
        }
    } catch (PDOException $e) {
        return $cache[$want] = ['ids' => [], 'names' => []];
    }
    return $cache[$want] = ['ids' => $ids, 'names' => $names];
}

/**
 * A SQL fragment limiting a products query to one side of the shop.
 *
 * Returns '' when there is nothing to limit — no categories on that side, or
 * only one side is trading — so callers can concatenate it unconditionally and
 * an un-launched menswear shop behaves exactly as it always did.
 */
function shopGenderSqlFilter(string $productsAlias = '', ?string $shopGender = null): string {
    // Nothing to separate while a single audience is trading.
    if (!shopGenderSelectorEnabled()) { return ''; }

    $scope = categoryScopeForShopGender($shopGender);
    if (!$scope['ids'] && !$scope['names']) { return ''; }

    $p = $productsAlias !== '' ? $productsAlias . '.' : '';
    $parts = [];
    if ($scope['ids']) {
        $parts[] = "{$p}category_id IN (" . implode(',', array_map('intval', $scope['ids'])) . ")";
    }
    if ($scope['names']) {
        $pdo = $GLOBALS['pdo'] ?? null;
        $quoted = [];
        foreach ($scope['names'] as $n) {
            $quoted[] = $pdo instanceof PDO ? $pdo->quote($n) : "'" . str_replace("'", "''", $n) . "'";
        }
        $parts[] = "{$p}category IN (" . implode(',', $quoted) . ")";
    }

    // A product with NO category still belongs somewhere.
    //
    // This built a pure whitelist, so an uncategorised row fell outside every
    // audience and vanished from all of them: published, answering 200 on its
    // own URL, listed in sitemap.xml, submitted to the Merchant feed and offered
    // by the header autocomplete — yet absent from every browsable grid, with
    // nothing anywhere saying why. The filter is dormant while one audience
    // trades, so this armed itself the instant menswear went live.
    //
    // Showing it on both sides is the safe direction: the product form already
    // refuses to save without a category, so only a legacy or directly-inserted
    // row can land here, and being seen twice beats being lost.
    $parts[] = "({$p}category_id IS NULL AND ({$p}category IS NULL OR {$p}category = ''))";

    return ' AND (' . implode(' OR ', $parts) . ')';
}

/**
 * The audience word for a page title — "Women", "Men", or '' for unisex.
 *
 * Empty for unisex on purpose: "Shop Scarves for Everyone" is filler, and a
 * title reads better simply not claiming an audience it does not have.
 */
function genderAudienceWord(string $gender): string {
    return ['women' => 'Women', 'men' => 'Men'][$gender] ?? '';
}

/**
 * Does this category name already say who it is for?
 *
 * Exists because of one nasty overlap: the word "women" CONTAINS "men", so the
 * obvious stripos($name, 'men') matches "Women's Tops" and would decide it is
 * already menswear. Women is stripped out before menswear is looked for.
 */
function nameStatesAudience(string $name, string $gender): bool {
    if ($gender === 'women') { return stripos($name, 'women') !== false; }
    if ($gender === 'men')   { return stripos(str_ireplace('women', '', $name), 'men') !== false; }
    return true;   // unisex claims no audience, so nothing to repeat
}

/**
 * Title, description, H1, intro and image for one category page.
 *
 * Owner-entered values in the categories table always win; anything left blank
 * is generated from the products in the category so the page is never generic.
 */
function categorySeo(PDO $pdo, array $cat): array {
    $name = trim((string)($cat['name'] ?? ''));
    $out = [
        'name'  => $name,
        'url'   => categoryUrl($cat),
        'image' => trim((string)($cat['image'] ?? '')),
        'image_alt' => trim((string)($cat['image_alt'] ?? '')),
    ];

    // ── Facts about what is actually in this category ──
    $count = 0; $fabrics = []; $minPrice = null; $occasions = [];
    try {
        $ids = categoryDescendantIds($pdo, (int)($cat['id'] ?? 0));
        $in  = implode(',', array_map('intval', $ids));
        /* The cheapest price a shopper can ACTUALLY pay in this collection.
           ────────────────────────────────────────────────────────────────────
           This was MIN(price) — the products.price column, raw. That column is
           only the price of a product with no per-size or per-colour pricing;
           where a size carries its own price the cart charges that instead. So
           a product listed at 50 whose only size is priced 2,000 made the whole
           collection advertise "from ₹50", and this string is the META
           DESCRIPTION: it goes to Google, gets indexed, and brings people to a
           page where nothing is available at the price that attracted them.

           Exactly the fault the product card had, pointed at search results
           rather than at the grid. Resolved through productPriceRange(), the
           same helper the card uses, so the two can never quote different
           floors. Rows are fetched rather than aggregated in SQL because the
           precedence — colour override, then size price, then product price,
           with the sale clamp — lives in effectiveVariantPrice() and must not
           be re-expressed here. */
        $st = $pdo->prepare(
            "SELECT id, price, mrp_price FROM products
              WHERE (category_id IN ($in) OR category = :name)
                AND available = 1 AND (is_deleted = 0 OR is_deleted IS NULL)"
        );
        $st->execute(['name' => $name]);
        $catRows = $st->fetchAll(PDO::FETCH_ASSOC);
        $count   = count($catRows);

        if ($catRows && function_exists('productPriceRangePrime')) {
            productPriceRangePrime($catRows, $pdo);
            foreach ($catRows as $cr) {
                $buyable = productPriceRange($cr)['min'];
                if ($buyable > 0 && ($minPrice === null || $buyable < $minPrice)) { $minPrice = $buyable; }
            }
        } else {
            foreach ($catRows as $cr) {
                $p = (float)$cr['price'];
                if ($p > 0 && ($minPrice === null || $p < $minPrice)) { $minPrice = $p; }
            }
        }
        foreach (['fabric' => 'fabrics', 'occasion' => 'occasions'] as $col => $key) {
            $st = $pdo->prepare(
                "SELECT DISTINCT $col FROM products
                  WHERE (category_id IN ($in) OR category = :name)
                    AND available = 1 AND (is_deleted = 0 OR is_deleted IS NULL)
                    AND $col IS NOT NULL AND $col <> ''
                  ORDER BY $col LIMIT 3"
            );
            $st->execute(['name' => $name]);
            $$key = array_map(fn($v) => ucwords(strtolower(trim((string)$v))), $st->fetchAll(PDO::FETCH_COLUMN));
        }
    } catch (PDOException $e) {}

    $out['product_count'] = $count;
    $out['fabrics'] = $fabrics;

    // ── H1: what the shopper reads at the top of the page ──
    $out['h1'] = $name;

    // ── Title tag ──
    // Aim under ~60 characters, which is roughly where Google truncates.
    if (!empty($cat['meta_title'])) {
        $out['title'] = trim((string)$cat['meta_title']);
    } else {
        // The audience is read from the category rather than assumed. This used
        // to be the literal word "Women" in both branches below, which meant a
        // menswear category would have published "Shop Men's Shirts for Women".
        // For a women's category the output is byte-identical to before.
        $gender   = categoryGender((int)($cat['id'] ?? 0));
        $audience = genderAudienceWord($gender);
        // "Women's Tops for Women" reads as a typo — only add the audience when
        // the category name does not already state it.
        $needsAudience = $audience !== '' && !nameStatesAudience($name, $gender);

        if (count($fabrics) >= 2) {
            $phrase = $fabrics[0] . ' & ' . $fabrics[1] . ' ' . $name;
        } elseif (count($fabrics) === 1) {
            $phrase = $fabrics[0] . ' ' . $name . ($needsAudience ? " for $audience" : '');
        } else {
            $phrase = $needsAudience ? "Shop $name for $audience" : "Shop $name at " . SHOP_NAME;
        }
        $candidate = "$name Online | $phrase | " . SHOP_NAME;
        // A title Google cuts in half is worse than a shorter honest one.
        $out['title'] = mb_strlen($candidate) <= 65 ? $candidate : "$name Online | " . SHOP_NAME;
    }

    // ── Meta description ──
    if (!empty($cat['meta_description'])) {
        $out['description'] = trim((string)$cat['meta_description']);
    } else {
        $lower = mb_strtolower($name);
        $d = "Shop " . SHOP_NAME . " $lower";
        if ($count > 0) { $d .= " — $count " . ($count === 1 ? 'piece' : 'pieces'); }
        if ($fabrics) { $d .= " in " . mb_strtolower(humanList($fabrics)); }
        // Whole rupees: "from ₹350" is what a shopper scans for; the ".00" is noise
        // and costs two of the ~158 characters Google will show.
        if ($minPrice !== null && $minPrice > 0) {
            $d .= ", from " . currencySymbol() . number_format($minPrice, 0);
        }
        $d .= ". Detailed size guides, secure checkout and easy returns across India.";
        $out['description'] = trimToLength($d, 158);
    }

    // ── Introductory paragraph ──
    // Deliberately different wording from the meta description: this one is read
    // by a shopper on the page, that one is read in a results list.
    if (!empty($cat['intro'])) {
        $out['intro'] = trim((string)$cat['intro']);
    } elseif ($count > 0) {
        $i = "Our $name edit brings together $count " . ($count === 1 ? 'piece' : 'pieces');
        if ($fabrics) { $i .= " in " . mb_strtolower(humanList($fabrics)); }
        if ($occasions) { $i .= ", cut for " . mb_strtolower(humanList($occasions, 'and')); }
        $i .= ". Every piece carries a full measurement chart, so you can order your size with confidence.";
        $out['intro'] = $i;
    } else {
        $out['intro'] = "New $name are being added to this collection. In the meantime, explore the rest of the "
                      . SHOP_NAME . " catalogue.";
    }

    return $out;
}

// ── Global body measurements ────────────────────────────────────────────────
/**
 * The default shop-wide body size table, used to seed size_guide_body_global on a
 * fresh install so every category has working body measurements from day one
 * without anyone typing 54 numbers.
 *
 * Follows config/size_ladder.php: the numeric size IS the bust, with the waist 4"
 * under and the hips 4" over — the proportion most Indian ready-to-wear ladders
 * are cut to. These are a sane starting point, not gospel: they are editable in
 * Admin → Size Guide → Body tab, and that edit applies to the whole shop.
 */
function defaultGlobalBodyRows(): array {
    $ladder = require __DIR__ . '/size_ladder.php';
    $rows = [];
    foreach ($ladder as $i => $sz) {
        $bust = (float)$sz['numeric'];
        $rows[] = [
            'size_label'   => $sz['code'],
            'numeric_size' => (string)$sz['numeric'],
            'bust'         => $bust,
            'waist'        => $bust - 6,
            'hips'         => $bust + 3,
            'shoulder'     => 13.5 + ($i * 0.5),
            'sort_order'   => $i,
        ];
    }
    return $rows;
}

/**
 * The men's default shop-wide body size table.
 *
 * Same idea as defaultGlobalBodyRows() but for menswear: the numeric size IS
 * the chest, with the waist 8\" under and the hips 8\" over — the proportions
 * of the men's Shirts/Trousers garment charts this shop already uses, so the
 * "Your measurements" panel and the garment tables never contradict each
 * other. Chest, not bust: the storefront labels the column per gender.
 */
function defaultMenGlobalBodyRows(): array {
    $rows = [];
    foreach (['XS','S','M','L','XL','2XL','3XL','4XL','5XL'] as $i => $code) {
        $chest = 36 + ($i * 2);   // 36 … 52
        $rows[] = [
            'size_label'   => $code,
            'numeric_size' => (string)$chest,
            'bust'         => $chest,      // stored in the shared column; the UI calls it Chest for men
            'waist'        => $chest - 8,  // 28 … 44
            'hips'         => $chest,       // 36 … 52 (trouser hip = waist + 8)
            'shoulder'     => 16.5 + ($i * 0.5),   // 16.5 … 20.5
            'sort_order'   => $i,
        ];
    }
    return $rows;
}

/**
 * Fill size_guide_body_global with the defaults, but only when it is completely
 * empty — never overwrites numbers an admin has already set.
 * Returns the number of rows inserted.
 */
function seedGlobalBodyRows(PDO $pdo): int {
    try {
        // Counts WOMEN only when the gender column exists. The men's ladder can be
        // seeded before or after this without ever hiding it — otherwise a fresh
        // install whose men's rows arrived first would see "rows exist" and skip
        // the women's ladder entirely.
        $hasGender = (bool)$pdo->query("SHOW COLUMNS FROM size_guide_body_global LIKE 'gender'")->fetchColumn();
        $where = $hasGender ? " WHERE gender = 'women'" : '';
        if ((int)$pdo->query("SELECT COUNT(*) FROM size_guide_body_global" . $where)->fetchColumn() > 0) {
            return 0;
        }
    } catch (PDOException $e) {
        return 0;   // table not created yet
    }
    $ins = $pdo->prepare("INSERT INTO size_guide_body_global (size_label, numeric_size, bust, waist, hips, shoulder, sort_order)
                          VALUES (:l,:n,:b,:w,:h,:s,:o)
                          ON DUPLICATE KEY UPDATE size_label = size_label");
    $n = 0;
    foreach (defaultGlobalBodyRows() as $r) {
        $ins->execute([
            'l' => $r['size_label'], 'n' => $r['numeric_size'], 'b' => $r['bust'],
            'w' => $r['waist'], 'h' => $r['hips'], 's' => $r['shoulder'], 'o' => $r['sort_order'],
        ]);
        $n++;
    }
    return $n;
}

/**
 * Fill size_guide_body_global with the MEN'S defaults, but only when no men's
 * rows exist yet — never overwrites numbers an admin has already set.
 * Returns the number of rows inserted.
 */
function seedMenGlobalBodyRows(PDO $pdo): int {
    try {
        // Pre-migration table has no gender column — nothing to seed into.
        if (!$pdo->query("SHOW COLUMNS FROM size_guide_body_global LIKE 'gender'")->fetchColumn()) {
            return 0;
        }
        $hasMen = (int)$pdo->query("SELECT COUNT(*) FROM size_guide_body_global WHERE gender = 'men'")->fetchColumn() > 0;
    } catch (PDOException $e) {
        return 0;   // table not created yet
    }
    if ($hasMen) { return 0; }

    $ins = $pdo->prepare("INSERT INTO size_guide_body_global (gender, size_label, numeric_size, bust, waist, hips, shoulder, sort_order)
                          VALUES ('men', :l,:n,:b,:w,:h,:s,:o)
                          ON DUPLICATE KEY UPDATE size_label = size_label");
    $n = 0;
    foreach (defaultMenGlobalBodyRows() as $r) {
        $ins->execute([
            'l' => $r['size_label'], 'n' => $r['numeric_size'], 'b' => $r['bust'],
            'w' => $r['waist'], 'h' => $r['hips'], 's' => $r['shoulder'], 'o' => $r['sort_order'],
        ]);
        $n++;
    }
    return $n;
}

/**
 * The BODY measurement table, shared by every category within a gender.
 *
 * Body measurements describe the wearer, not the garment — a 34\" bust is a size
 * M whatever she is buying, and a 40\" chest is a size M whatever he is buying —
 * so there is exactly one table per gender. GARMENT measurements stay per
 * category, because a kurti hem and a top hem are genuinely different lengths.
 *
 * $gender selects the ladder: 'women' (bust 30-46) or 'men' (chest 36-52).
 *
 * Falls back to the per-chart body rows if the global table is empty, so an
 * un-migrated database (or a rollback) keeps working unchanged. A men's request
 * on a database that has not been migrated yet falls back to the women's rows
 * (the pre-migration behaviour) rather than an empty panel.
 */
function globalBodySizeRows(PDO $pdo, array $fallbackRows = [], string $gender = 'women'): array {
    $gender = $gender === 'men' ? 'men' : 'women';
    static $cache = [];
    if (!isset($cache[$gender])) {
        try {
            // A pre-migration table has no gender column: the qualified query
            // throws, the catch falls back to the plain read (which returns the
            // women's ladder — exactly what the shop showed before genders).
            $st = $pdo->prepare("SELECT size_label, numeric_size, bust, waist, hips, shoulder, sort_order
                                  FROM size_guide_body_global WHERE gender = :g ORDER BY sort_order ASC, id ASC");
            $st->execute(['g' => $gender]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            // Migration gap: the column exists but this gender's rows have not been
            // seeded yet (fresh column, seed step not run). Show the women's ladder
            // rather than nothing — a wrong number is easier to spot than a blank.
            // Women only: pulling the whole table here would hand back BOTH ladders,
            // and every size label would appear twice.
            if (!$rows) {
                $rows = $pdo->query("SELECT size_label, numeric_size, bust, waist, hips, shoulder, sort_order
                                     FROM size_guide_body_global WHERE gender = 'women' ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
            }
            $cache[$gender] = $rows;
        } catch (PDOException $e) {
            // No gender column yet (pre-migration) or no table at all — the caller
            // falls back to the per-chart body rows, which carry the product's own
            // gender's numbers and are exactly what the shop showed before genders.
            $cache[$gender] = [];
        }
    }
    if (!$cache[$gender]) { return $fallbackRows; }

    // Shape it like size_guide_content rows so every existing template that reads
    // $r['bust'] etc. keeps working with no changes.
    return array_map(function ($r) {
        return [
            'measurement_type' => 'body',
            'size_label'       => $r['size_label'],
            'numeric_size'     => $r['numeric_size'],
            'bust'             => $r['bust'],
            'waist'            => $r['waist'],
            'hips'             => $r['hips'],
            'shoulder'         => $r['shoulder'],
            'length'           => null,          // body rows never carry a length
            'sort_order'       => $r['sort_order'],
        ];
    }, $cache[$gender]);
}

/**
 * Auto-create a size chart for a brand-new TOP-LEVEL category.
 *
 * The owner does not build a chart every time they add a collection — this does
 * it for them, cloning the right gender's default measurements. Template chosen
 * by the category's gender and, when the name says so, its garment type:
 *
 *   - men    -> the men's default (shirt-style chest ladder), or the Trousers
 *               chart when the name is clearly a bottoms garment
 *   - women  -> the shop-wide default (kurti-style), or the Bottoms chart when
 *               the name is clearly a bottoms garment
 *
 * Templates are located by NAME, never by id, so this works on the live server
 * where category ids differ from local. The cloned chart is a real row the
 * owner can refine in Admin → Size Guides. All failures are swallowed: a chart
 * is a convenience, never a reason to block category creation.
 */
function autoCreateSizeGuideChartForCategory(PDO $pdo, int $categoryId, string $gender): void {
    if ($categoryId <= 0) { return; }
    try {
        $exists = $pdo->prepare("SELECT 1 FROM size_guide_charts WHERE category_id = :id LIMIT 1");
        $exists->execute(['id' => $categoryId]);
        if ($exists->fetch()) { return; }   // already has one — never touch it

        $nm = $pdo->prepare("SELECT name FROM categories WHERE id = :id");
        $nm->execute(['id' => $categoryId]);
        $name      = (string)$nm->fetchColumn();
        // 'short' is deliberately NOT here: "Short Kurtis" is a top and needs the
        // bust/chest column — matching 'short' would have routed it to Bottoms.
        $isBottoms = preg_match('/bottom|trouser|pant|shorts\b|chino|skirt|jean/', strtolower($name)) === 1;

        $trouserId = (int)$pdo->query("SELECT c.id FROM size_guide_charts c JOIN categories cat ON cat.id = c.category_id WHERE cat.name = 'Trousers' AND c.product_id IS NULL ORDER BY c.id ASC LIMIT 1")->fetchColumn();
        $bottomsId = (int)$pdo->query("SELECT c.id FROM size_guide_charts c JOIN categories cat ON cat.id = c.category_id WHERE cat.name = 'Bottoms' AND c.product_id IS NULL ORDER BY c.id ASC LIMIT 1")->fetchColumn();

        if ($gender === 'men') {
            $templateId = $isBottoms
                ? $trouserId
                : (int)$pdo->query("SELECT id FROM size_guide_charts WHERE category_id IS NULL AND product_id IS NULL AND gender = 'men' ORDER BY id ASC LIMIT 1")->fetchColumn();
            // Men's default not built yet (migration not run) — fall back to the
            // Shirts chart by name so the category still gets a usable chart.
            if ($templateId <= 0) {
                $templateId = (int)$pdo->query("SELECT c.id FROM size_guide_charts c JOIN categories cat ON cat.id = c.category_id WHERE cat.name = 'Shirts' AND c.product_id IS NULL ORDER BY c.id ASC LIMIT 1")->fetchColumn();
            }
        } else {
            $templateId = $isBottoms
                ? $bottomsId
                : (int)$pdo->query("SELECT id FROM size_guide_charts WHERE category_id IS NULL AND product_id IS NULL AND (gender IS NULL OR gender = 'women') ORDER BY (gender = 'women') DESC, id ASC LIMIT 1")->fetchColumn();
        }
        if ($templateId <= 0) { return; }

        $tmpl = $pdo->prepare("SELECT * FROM size_guide_charts WHERE id = :id");
        $tmpl->execute(['id' => $templateId]);
        $t = $tmpl->fetch(PDO::FETCH_ASSOC);
        if (!$t) { return; }

        $ins = $pdo->prepare(
            "INSERT INTO size_guide_charts (category_id, product_id, gender, unit, tolerance,
                instructions_shoulder, instructions_bust, instructions_waist, instructions_hips,
                instructions_length, instructions_sleeve)
             VALUES (:cat, NULL, :gender, :unit, :tol, :is1, :is2, :is3, :is4, :is5, :is6)"
        );
        $ins->execute([
            'cat' => $categoryId, 'gender' => $gender === 'men' ? 'men' : 'women',
            'unit' => $t['unit'], 'tol' => $t['tolerance'],
            'is1' => $t['instructions_shoulder'], 'is2' => $t['instructions_bust'],
            'is3' => $t['instructions_waist'], 'is4' => $t['instructions_hips'],
            'is5' => $t['instructions_length'], 'is6' => $t['instructions_sleeve'],
        ]);
        $newId = (int)$pdo->lastInsertId();

        $rows = $pdo->prepare("SELECT * FROM size_guide_content WHERE chart_id = :id");
        $rows->execute(['id' => $templateId]);
        $insRow = $pdo->prepare(
            "INSERT INTO size_guide_content (chart_id, measurement_type, size_label, numeric_size,
                bust, waist, hips, shoulder, sleeve, length, sort_order)
             VALUES (:cid, :type, :label, :num, :b, :w, :h, :sh, :sl, :len, :sort)"
        );
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $insRow->execute([
                'cid' => $newId, 'type' => $r['measurement_type'], 'label' => $r['size_label'],
                'num' => $r['numeric_size'], 'b' => $r['bust'], 'w' => $r['waist'], 'h' => $r['hips'],
                'sh' => $r['shoulder'], 'sl' => $r['sleeve'], 'len' => $r['length'], 'sort' => $r['sort_order'],
            ]);
        }
    } catch (PDOException $e) {
        error_log('autoCreateSizeGuideChartForCategory: ' . $e->getMessage());
    }
}

// ── Size guide "how to measure" text ────────────────────────────────────────
/**
 * The shop-wide default measuring instructions.
 *
 * How you measure a bust does not change between a kurti and a pair of trousers,
 * so these are shared. A category chart can still override any of them (a Bottoms
 * chart, for instance, overrides Length with "from the waistband down the leg"),
 * but a category with no chart of its own still gets proper guidance instead of a
 * blank panel.
 */
function defaultSizeGuideInstructions(): array {
    return [
        'shoulder' => 'Stand relaxed with arms down. Measure across the back from the bony tip of one shoulder to the bony tip of the other, keeping the tape flat along the shoulder line.',
        'bust'     => 'Wear a non-padded bra. Measure around the fullest part of the bust, keeping the tape level and parallel to the floor. Keep it snug but not tight — you should be able to slide a finger underneath.',
        'waist'    => 'Measure around the narrowest part of your natural waistline, usually just above the navel. Do not hold your breath in; keep the tape comfortably loose.',
        'hips'     => 'Stand with your feet together. Measure around the fullest part of your hips and seat, roughly 8 inches below the natural waist, keeping the tape parallel to the floor.',
        'length'   => 'Measure from the highest point of the shoulder, next to the neck, straight down the front to the hem.',
    ];
}

/**
 * Final instruction text for a chart: the category's own wording when it has any,
 * otherwise the shop-wide default. Returns [Label => text] with blanks removed.
 */
// $chart is intentionally untyped: PDO::fetch() returns FALSE (not null) when a
// category has no chart, and a `?array` hint turns that into a fatal TypeError.
function resolveSizeGuideInstructions(PDO $pdo, $chart): array {
    if (!is_array($chart)) { $chart = []; }
    $defaults = defaultSizeGuideInstructions();

    // A stored override lives in store_settings under size_guide_instr_<field>, so the
    // wording can be edited shop-wide without touching every category.
    foreach (array_keys($defaults) as $field) {
        $stored = storeSetting($pdo, 'size_guide_instr_' . $field);
        if ($stored !== null && trim((string)$stored) !== '') {
            $defaults[$field] = $stored;
        }
    }

    $labels = [
        'shoulder' => 'Shoulder',
        'bust'     => 'Bust',
        'waist'    => 'Waist',
        'hips'     => 'Hips',
        'length'   => 'Garment Length',
    ];

    $out = [];
    foreach ($labels as $field => $label) {
        $own = trim((string)($chart['instructions_' . $field] ?? ''));
        $text = $own !== '' ? $own : $defaults[$field];
        if (trim($text) !== '') { $out[$label] = $text; }
    }
    return $out;
}

// ── Number to words (Indian numbering) ──────────────────────────────────────
/**
 * "Three Hundred Ninety Rupees and Fifty Paise Only" — required on a GST invoice.
 * Uses the Indian system (thousand / lakh / crore), not the Western short scale,
 * so 150000 reads "One Lakh Fifty Thousand", not "One Hundred Fifty Thousand".
 */
function numberToWords($amount): string {
    $amount = (float)$amount;
    $units = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten',
              'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen',
              'eighteen', 'nineteen'];
    $tens  = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];

    $below100 = function ($n) use ($units, $tens) {
        if ($n < 20) { return $units[$n]; }
        return trim($tens[intdiv($n, 10)] . ' ' . $units[$n % 10]);
    };
    $below1000 = function ($n) use ($units, $below100) {
        $out = '';
        if ($n >= 100) { $out .= $units[intdiv($n, 100)] . ' hundred '; $n %= 100; }
        return trim($out . $below100($n));
    };

    $rupees = (int)floor($amount);
    $paise  = (int)round(($amount - $rupees) * 100);
    if ($rupees === 0 && $paise === 0) { return 'zero only'; }

    $parts = [];
    foreach ([10000000 => 'crore', 100000 => 'lakh', 1000 => 'thousand'] as $div => $label) {
        if ($rupees >= $div) {
            $parts[] = $below1000(intdiv($rupees, $div)) . ' ' . $label;
            $rupees %= $div;
        }
    }
    if ($rupees > 0) { $parts[] = $below1000($rupees); }

    $out = trim(implode(' ', $parts));
    if ($paise > 0) { $out .= ' and ' . $below100($paise) . ' paise'; }
    return trim($out) . ' only';
}

// ── Shipping zones ──────────────────────────────────────────────────────────
// The shop ships from India, so a 6-digit PIN code is domestic and anything else
// is international. Kept as one function because three things depend on the same
// answer: which shipping rate applies, whether COD may be offered, and whether the
// order is an export for tax purposes. If those ever disagree, orders get charged
// the wrong postage or COD is offered where it cannot be collected.
//
// The home country is a setting rather than a constant so this does not need a code
// change when international operations start.

/** 'domestic' | 'international' for a given postcode. Empty postcode = unknown → international. */
function detectShippingZone(?string $postcode, ?PDO $pdo = null): string {
    $home = strtoupper(trim((string)(storeSetting($pdo, 'home_country', 'IN') ?: 'IN')));
    $pc   = strtoupper(preg_replace('/\s+/', '', (string)$postcode));
    if ($pc === '') { return 'international'; }

    switch ($home) {
        case 'IN':
            // Indian PIN: exactly 6 digits, first digit 1-9 (0 is not a valid region).
            return preg_match('/^[1-9][0-9]{5}$/', $pc) ? 'domestic' : 'international';
        case 'GB':
        case 'UK':
            // UK: 5-7 alphanumeric, always ending in digit + 2 letters.
            return preg_match('/^[A-Z]{1,2}[0-9][A-Z0-9]?[0-9][A-Z]{2}$/', $pc) ? 'domestic' : 'international';
        default:
            return 'international';
    }
}

/** True when COD may be offered. Cash on delivery is not collectable abroad. */
/**
 * May this delivery be paid in cash?
 *
 * Two conditions, and both matter:
 *
 *   1. The parcel is going somewhere a courier can collect cash — decided from
 *      the POSTCODE, never from the form, because payment_method arrives from the
 *      browser and can be forged.
 *   2. Cash on delivery is switched on for the home country in Countries.
 *
 * The second check is new. store_countries.cod_allowed existed, and a function
 * codAllowedInCurrentCountry() existed to read it, but NOTHING called it — so
 * the tick box in the Countries screen did nothing at all. Turning COD off there
 * left it fully available at checkout, with no way to tell why.
 *
 * Deliberately reads the HOME country rather than the shopper's selected one:
 * cash is only collectable where this shop's own couriers run, and the postcode
 * check above has already established the parcel is going there. Reading the
 * selected country would let a stale cookie decide whether cash can be taken.
 */
function codAvailableForPostcode(?string $postcode, ?PDO $pdo = null): bool {
    if (detectShippingZone($postcode, $pdo) !== 'domestic') {
        return false;
    }
    $home = homeCountryRow();
    // No row at all: fall back to allowing it, which is how this behaved before
    // the flag was honoured. A missing countries table must not stop the shop
    // taking the orders it takes today.
    if ($home === null || !array_key_exists('cod_allowed', $home)) {
        return true;
    }
    return (int)$home['cod_allowed'] === 1;
}

/* ═══════════════════════════════════════════════════════════
   CASH ON DELIVERY RULES
   ═══════════════════════════════════════════════════════════
   COD used to be completely unguarded: any order size could go out for cash
   collection, no handling fee, and a phone number of "123456" was accepted
   because the only rule was a 6-character length check. These are the
   defaults; both live in Store Settings and are editable there.
   ═══════════════════════════════════════════════════════════ */
const COD_MAX_ORDER_DEFAULT = 5000;   // 0 disables the cap
const COD_FEE_DEFAULT       = 49;     // 0 disables the fee

/**
 * Validate a checkout phone number.
 *
 * Returns an error message, or null when the number is acceptable. The old
 * check was `strlen($phone) < 6`, so "123456" sailed through — a courier has
 * no way to call a parcel whose number is six sequential digits.
 *
 * When $codOnly is set (Cash on Delivery, which only happens domestically),
 * the number must be a real 10-digit Indian mobile starting 6-9 — the format
 * every Indian courier can dial. Otherwise any plausible international number
 * of 7-13 digits is accepted, minus the obvious fake sequences.
 */
function validatePhoneNumber(?string $phone, bool $codOnly = false): ?string
{
    $digits = preg_replace('/\D+/', '', (string)$phone);
    if ($digits === '') {
        return 'Please enter your phone number.';
    }

    if ($codOnly) {
        // Indian mobile numbers come pre-filled with a +91 country code more
        // often than not (browser autofill, Google address book), and a 12-digit
        // "919876543210" is a real, diallable number — refusing it because the
        // regex wants exactly 10 digits turned away a chunk of genuine COD
        // orders with a message that reads as "the machine is broken". Strip a
        // leading country code (or the local trunk 0) before the 10-digit test,
        // and only accept the bare 10-digit form that Indian couriers can dial.
        $national = $digits;
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $national = substr($digits, 2);
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $national = substr($digits, 1);
        }
        if (!preg_match('/^[6-9][0-9]{9}$/', $national)) {
            return 'Cash on Delivery needs a valid 10-digit Indian mobile number (for example 98765 43210).';
        }
        // A number that passes the format but is obviously not real still fails:
        // 9999999999 (all same digit) and 9876543210 (a famous fake run) are the
        // two most common test/fake numbers a courier cannot actually call.
        if (preg_match('/^(\d)\1+$/', $national)) {
            return 'That phone number does not look real. Please check it.';
        }
        if (preg_match('/^(012345|123456|234567|345678|456789|567890|098765|987654|876543|765432|654321|543210)/', $national)) {
            return 'That phone number does not look real. Please check it.';
        }
        return null;
    }

    $len = strlen($digits);
    if ($len < 7 || $len > 13) {
        return 'Please enter a valid phone number.';
    }
    // All-same-digit, or an obvious run like 1234567 / 9876543.
    if (preg_match('/^(\d)\1+$/', $digits)) {
        return 'That phone number does not look real. Please check it.';
    }
    if (preg_match('/^(012345|123456|234567|345678|456789|567890|098765|987654|876543|765432|654321|543210)/', $digits)) {
        return 'That phone number does not look real. Please check it.';
    }
    return null;
}

/**
 * Shipping cost for a zone, honouring the free-shipping threshold.
 * Rates live in store_settings so they can be changed without a deploy.
 */
function shippingCostForZone(string $zone, float $orderTotal, ?PDO $pdo = null, ?string $countryCode = null): float {
    if ($zone === 'domestic') {
        /* Countries first, Settings as the fallback.
           ────────────────────────────────────────────────────────────────────
           This read the GLOBAL store_settings figures while the product page
           quoted freeShippingMinForCountry(), which reads the country row. Two
           sources for one promise: change the threshold under Countries and the
           product page updated while the checkout carried on charging by the old
           global number. They agree today only because both happen to say 2000.

           'domestic' means the shop's own country — that is what detectShippingZone()
           decides — so the HOME row is the one that governs, not whichever country
           the visitor is browsing in. Cash and couriers are local facts.

           Rupee defaults. These were 150 and 15 — the GBP-era figures — so a site
           whose settings row was missing quoted ₹15 postage free over ₹150. */
        $home = homeCountryRow();

        $freeOver = (function_exists('freeShippingMinForCountry') && $home !== null)
            ? freeShippingMinForCountry($pdo, $home['country_code'] ?? null)
            : (float)storeSetting($pdo, 'free_shipping_min', 2000);

        $fee = ($home !== null && isset($home['shipping_fee']) && $home['shipping_fee'] !== null)
            ? (float)$home['shipping_fee']
            : (float)storeSetting($pdo, 'standard_shipping_fee', 99);

        return ($freeOver > 0 && $orderTotal >= $freeOver) ? 0.0 : $fee;
    }
    /* International: the DESTINATION country's own rate, not one global number.
       ────────────────────────────────────────────────────────────────────────
       The Countries screen carries a shipping fee and a free-shipping threshold
       per country, and the domestic branch above already honours them. This
       branch ignored both and returned the single global international_shipping_fee
       — 2500, a rupee figure — for every destination on earth.

       That number was then stamped with whatever currency the shopper was
       browsing in. A UK shopper with £84 of clothes in their bag was quoted
       £2,500 postage and a total of £2,584: thirty times the value of the order,
       in the wrong currency, on the payment screen. Nobody completes that
       checkout, and the Countries screen said £15.00 the whole time.

       Read the destination row, and only fall back to the global setting when
       the country has no row of its own — which is also the only case the old
       behaviour was ever right for.

       The free-shipping threshold applies here too. It did not before, so a
       country could offer free delivery over £400 on its own settings screen
       while checkout charged full postage on a £900 order. */
    $destCode = strtoupper(trim((string)($countryCode ?? currentCountryCode())));
    $dest     = allStoreCountries()[$destCode] ?? null;

    if ($dest !== null && isset($dest['shipping_fee']) && $dest['shipping_fee'] !== null) {
        $fee = (float)$dest['shipping_fee'];

        $freeOver = isset($dest['free_shipping_min']) && $dest['free_shipping_min'] !== null
            ? (float)$dest['free_shipping_min']
            : 0.0;

        return ($freeOver > 0 && $orderTotal >= $freeOver) ? 0.0 : $fee;
    }

    return (float)storeSetting($pdo, 'international_shipping_fee', 2500);
}

// ── Brand identity ──────────────────────────────────────────────────────────
// One place for the brand name and logo so they are never hardcoded again.
// The logo path is stored in store_settings under `site_logo`, so changing the
// logo in admin changes it everywhere — header, invoice, emails — at once.

/** Path (relative to the site root) of the current site logo. */
function siteLogoPath(?PDO $pdo = null): string {
    // logo.PNG is the CURRENT Dievon logo, shipped with the code rather than
    // uploaded. That matters because one database is shared by two domains: the
    // site_logo setting is a relative path, so it resolves inside whichever
    // domain is serving the page, and a logo uploaded on one domain does not
    // exist in the other's folder. Shipping the real logo as the default means
    // both sites are correctly branded even when the setting points at a file
    // only one of them has. The previous placeholder is kept beside it as
    // logo-bundled-original.PNG.
    $default = 'assets/images/logo/logo.PNG';
    if (!$pdo) { return $default; }
    $stored = storeSetting($pdo, 'site_logo');
    if (!$stored) { return $default; }
    // Only trust a stored value that actually exists on disk, so a bad or deleted
    // setting silently falls back instead of rendering a broken image.
    return is_file(__DIR__ . '/../' . ltrim($stored, '/')) ? ltrim($stored, '/') : $default;
}

/**
 * Absolute URL for a site-relative asset, stamped with the file's own timestamp.
 *
 * .htaccess tells browsers to cache images for a YEAR:
 *     ExpiresByType image/png "access plus 1 year"
 * which is right for performance and wrong for anything that can be replaced in
 * place. Uploading a new logo over the old one changes the file but not the URL,
 * so every browser that has already seen it keeps showing the old picture for up
 * to a year, with no way for the shop owner to force the issue.
 *
 * Appending ?v=<mtime> makes the URL change whenever the file does. New picture,
 * new address, fetched immediately — and the year-long cache still applies, which
 * is exactly what you want for a file that is not going to change again.
 *
 * The stylesheet already does this (?v=... in includes/header.php); images did not.
 */
function assetUrlWithVersion(string $relativePath): string {
    $rel  = ltrim($relativePath, '/');
    $abs  = __DIR__ . '/../' . $rel;
    $stamp = is_file($abs) ? filemtime($abs) : 0;
    return SITE_URL . '/' . $rel . ($stamp ? '?v=' . $stamp : '');
}

/** Absolute URL of the current site logo. */
function siteLogoUrl(?PDO $pdo = null): string {
    return assetUrlWithVersion(siteLogoPath($pdo));
}

/**
 * Absolute URL of the browser tab icon.
 *
 * Falls back to the logo when no favicon is set, so a tab icon always exists —
 * but they are separate settings on purpose: a favicon is a small square shown
 * at 16-32px, while a header logo is usually wide. Squashing a wide logo into a
 * tab makes it unreadable, so this lets a proper square icon be uploaded.
 */
function siteFaviconUrl(?PDO $pdo = null): string {
    /* The tab icon is a deployed asset, not a setting.
       ────────────────────────────────────────────────────────────────────
       It used to prefer an uploaded site_favicon over the bundled file. The
       owner's decision is that the icon ships with the code and is not
       editable from admin, so the stored value is no longer consulted at all
       — replacing the file in assets/images/logo/ is the only way to change
       it, and there is one obvious answer to what the icon currently is.
       Any row left in the settings table from before is simply unread. */
    // Fall back to the bundled square icon before falling back to the logo. The
    // logo is a wide banner; squashed into a 16px tab it is unreadable. This also
    // means the tab icon is correct on a site that has never had one uploaded —
    // which matters when one database is shared by two domains, because the
    // setting names a file that exists in only one of their folders.
    $bundled = 'assets/images/logo/favicon.png';
    if (is_file(__DIR__ . '/../' . $bundled)) {
        return assetUrlWithVersion($bundled);
    }
    return siteLogoUrl($pdo);
}

/**
 * Absolute URL of the DARK-MODE tab icon, or null when none is set.
 *
 * A dark logo vanishes against a dark browser tab, so browsers let a second icon
 * be offered via <link rel="icon" media="(prefers-color-scheme: dark)">. Returns
 * null rather than falling back, because the caller must only emit the paired
 * media links when a genuine dark variant exists — otherwise the same icon would
 * be declared twice for no reason.
 */
function siteFaviconDarkUrl(?PDO $pdo = null): ?string {
    // Bundled only, for the same reason as the light icon above.
    // Same bundled fallback as the light icon. Still returns null when there is
    // no dark icon at all, so the caller does not emit half a pair.
    $bundled = 'assets/images/logo/favicondark.png';
    return is_file(__DIR__ . '/../' . $bundled) ? assetUrlWithVersion($bundled) : null;
}

/** MIME type for a favicon <link>, derived from the file extension. */
function siteFaviconMime(?PDO $pdo = null, ?string $url = null): string {
    $url = $url ?? siteFaviconUrl($pdo);
    $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
    return [
        'ico'  => 'image/x-icon',
        'svg'  => 'image/svg+xml',
        'webp' => 'image/webp',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
    ][$ext] ?? 'image/png';
}

/**
 * The brand name, as it should appear to customers.
 * Reads store_settings.site_name, falling back to the SHOP_NAME constant —
 * "Dievon" was never the brand, it was invented copy that spread.
 */
function brandName(?PDO $pdo = null): string {
    $stored = $pdo ? storeSetting($pdo, 'site_name') : null;
    $name = trim((string)($stored ?? ''));
    return $name !== '' ? $name : (defined('SHOP_NAME') ? SHOP_NAME : 'Dievon');
}

/** The registered legal entity for invoices — falls back to the brand name. */
function legalEntityName(?PDO $pdo = null): string {
    $stored = $pdo ? storeSetting($pdo, 'legal_entity_name') : null;
    $name = trim((string)($stored ?? ''));
    return $name !== '' ? $name : brandName($pdo);
}

// ── Store settings ──────────────────────────────────────────────────────────
/** Read a single key from store_settings, with a default. Cached per request. */
/**
 * The shop's public phone number, as shown to customers.
 *
 * One source for every form placeholder, the contact page and the shipping
 * label. Editing it in admin -> Store Settings changes all of them at once;
 * previously the same number was hardcoded in six files and drifted.
 *
 * Falls back to the SHOP_PHONE constant when the setting is blank or the
 * database is unreachable, so a template never renders an empty placeholder.
 */
function shopPhone(): string {
    $pdo = $GLOBALS['pdo'] ?? null;
    $v = trim((string)storeSetting($pdo instanceof PDO ? $pdo : null, 'contact_phone', ''));
    return $v !== '' ? $v : (defined('SHOP_PHONE') ? SHOP_PHONE : '');
}

/**
 * The shop's postal address, as shown to customers.
 *
 * Returns '' when unset — callers must handle that by hiding the block rather
 * than printing a placeholder. An invented address is worse than none: a
 * customer posting a return needs somewhere real to send it.
 */
function shopAddress(): string {
    $pdo = $GLOBALS['pdo'] ?? null;
    return trim((string)storeSetting($pdo instanceof PDO ? $pdo : null, 'store_address', ''));
}

/**
 * First line of the shop address, for use as an *example* in a customer's
 * address field. Falls back to a neutral Indian example when unset, because a
 * form placeholder is only ever a format hint — it is never submitted.
 */
function shopAddressExample(): string {
    $addr = shopAddress();
    if ($addr === '') { return '12 Linking Road, Bandra West'; }
    $firstLine = trim((string)strtok($addr, "\n"));
    return $firstLine !== '' ? $firstLine : '12 Linking Road, Bandra West';
}

/**
 * What a promo code is worth against a given subtotal, decided once.
 *
 * The checkout page and the order handler each worked this out for themselves,
 * and they disagreed: the page printed the amount frozen into the session when
 * the code was first typed, while the handler re-read the promo row and
 * recomputed against the live basket. Change the cart after applying a coupon
 * and the customer was shown "Discount -₹35, Total Due ₹2,065" and charged
 * ₹1,989 — four wrong figures on the last screen before paying.
 *
 * Every rule is re-checked here, not just the arithmetic: a code can be
 * deactivated, exhausted or expired between being typed and being used, and the
 * minimum-spend rule has to be judged against what is in the basket NOW.
 *
 * Returns ['amount' => float, 'code' => ?string]; amount is 0.0 and code null
 * when the promo no longer qualifies.
 */
/**
 * A promo code's money values for the country being shopped in.
 *
 * promo_codes stores ONE discount_value and ONE min_order, entered in the shop's
 * own currency. There is no exchange rate anywhere in this shop — each country
 * carries its own prices — so those two figures are meaningless abroad: a ₹50
 * coupon offered to a shopper paying in pounds is either £50 (a hundred times
 * too generous) or refused against a ₹300 minimum their £84 basket can never
 * reach. Both happened.
 *
 * promo_country_values holds the equivalent figures per country, exactly as
 * product_country_prices does for products. Anything the owner has not set falls
 * back to the base row, so a shop selling only at home behaves as it always has.
 *
 * Percentages are currency-free and are never overridden — 10% is 10% anywhere.
 *
 * @return array{discount_value: float, min_order: float, is_country_specific: bool}
 */
function promoValuesForCountry(?PDO $pdo, array $promoRow, ?string $countryCode = null): array {
    $base = [
        'discount_value'      => (float)($promoRow['discount_value'] ?? 0),
        'min_order'           => (float)($promoRow['min_order'] ?? 0),
        'is_country_specific' => false,
    ];

    // A percentage means the same thing in every currency.
    if (($promoRow['discount_type'] ?? '') === 'percentage') { return $base; }
    if (!$pdo instanceof PDO || empty($promoRow['id'])) { return $base; }

    $code = strtoupper(trim((string)($countryCode ?? (function_exists('currentCountryCode') ? currentCountryCode() : ''))));
    if ($code === '') { return $base; }

    // Home country uses the base row — that is the currency it was typed in.
    $home = strtoupper(trim((string)(homeCountryRow()['country_code'] ?? 'IN')));
    if ($code === $home) { return $base; }

    try {
        $st = $pdo->prepare("SELECT discount_value, min_order FROM promo_country_values
                              WHERE promo_id = :pid AND country_code = :cc");
        $st->execute(['pid' => (int)$promoRow['id'], 'cc' => $code]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Table not created yet on an older database — behave as before.
        return $base;
    }

    if (!$row) { return $base; }

    return [
        'discount_value'      => $row['discount_value'] !== null ? (float)$row['discount_value'] : $base['discount_value'],
        'min_order'           => $row['min_order']      !== null ? (float)$row['min_order']      : $base['min_order'],
        'is_country_specific' => true,
    ];
}

/**
 * True when a fixed-amount promo has no figure for the country being shopped in.
 *
 * A fixed ₹500-off code with nothing set for the United Kingdom cannot be
 * honoured there in any honest way — offering £500 gives the shop away, and
 * offering ₹500 charges in a currency the shopper is not paying in. Callers use
 * this to withhold the code abroad rather than guess.
 */
function promoIsUnpricedForCountry(?PDO $pdo, array $promoRow, ?string $countryCode = null): bool {
    if (($promoRow['discount_type'] ?? '') === 'percentage') { return false; }
    $home = strtoupper(trim((string)(homeCountryRow()['country_code'] ?? 'IN')));
    $code = strtoupper(trim((string)($countryCode ?? (function_exists('currentCountryCode') ? currentCountryCode() : ''))));
    if ($code === '' || $code === $home) { return false; }

    return !promoValuesForCountry($pdo, $promoRow, $code)['is_country_specific'];
}

function resolveCartDiscount(?PDO $pdo, ?array $promo, float $subtotal): array {
    $none = ['amount' => 0.0, 'code' => null];
    if (!$pdo instanceof PDO || !$promo || empty($promo['id']) || empty($promo['code'])) { return $none; }

    try {
        $st = $pdo->prepare("SELECT * FROM promo_codes WHERE id = :id AND code = :code AND active = 1");
        $st->execute(['id' => $promo['id'], 'code' => $promo['code']]);
        $row = $st->fetch();
    } catch (PDOException $e) { return $none; }

    if (!$row) { return $none; }
    if (!is_null($row['max_uses']) && $row['uses_count'] >= $row['max_uses']) { return $none; }
    if (!empty($row['expires_at']) && strtotime($row['expires_at']) < strtotime('today')) { return $none; }

    /* The figures for the country being paid in.
       ────────────────────────────────────────────────────────────────────────
       This read discount_value and min_order straight off the row — figures the
       owner typed in rupees — and applied them to a basket priced in pounds. A
       ₹50-off code became £50, and a ₹300 minimum could not be met by an £84
       basket however large it really was. See promoValuesForCountry().

       A fixed-amount code with nothing set for this country is refused rather
       than guessed at: there is no exchange rate in this shop to guess with. */
    if (promoIsUnpricedForCountry($pdo, $row)) { return $none; }
    $vals = promoValuesForCountry($pdo, $row);

    if ($subtotal < $vals['min_order']) { return $none; }

    $amount = ($row['discount_type'] === 'percentage')
        ? round($subtotal * ($vals['discount_value'] / 100), 2)
        : min($vals['discount_value'], $subtotal);

    return ['amount' => (float)$amount, 'code' => $row['code']];
}

function storeSetting(?PDO $pdo, string $key, $default = null) {
    // Callers in shared templates pass `$pdo ?? null`, so a null connection must
    // return the default rather than fataling.
    if ($pdo === null) { return $default; }
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach ($pdo->query("SELECT setting_key, setting_value FROM store_settings")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $cache[$r['setting_key']] = $r['setting_value'];
            }
        } catch (PDOException $e) { $cache = []; }
    }
    return (isset($cache[$key]) && $cache[$key] !== '') ? $cache[$key] : $default;
}

/**
 * The measurement illustration to show in the size guide popup.
 *
 * Resolution order: this chart's own image, then the ONE site-wide default.
 * The site-wide default exists because body measuring (bust/waist/hips) is
 * identical whatever the garment — Biba, for instance, serves a single shared
 * diagram across their whole catalogue. Uploading one image therefore covers
 * every category, instead of having to repeat it per chart.
 */
// Untyped $chart for the same reason as resolveSizeGuideInstructions(): a missing
// chart arrives as boolean FALSE from PDO::fetch(), which a ?array hint rejects.
function sizeGuideIllustrationUrl(PDO $pdo, $chart): ?string {
    if (!is_array($chart)) { $chart = []; }
    if (!empty($chart['illustration_image'])) {
        return SITE_URL . '/uploads/products/' . $chart['illustration_image'];
    }
    $global = storeSetting($pdo, 'size_guide_illustration');
    return $global ? SITE_URL . '/uploads/gallery/' . $global : null;
}

// ── Category tree helpers ───────────────────────────────────────────────────
// Browsing a category must include everything beneath it: opening "Kurtis" should
// show Long Kurtis, Short Kurtis, Anarkali Sets and so on. This used to be faked
// in shop.php with hardcoded `category LIKE '%Kurti%'` rules, which both returned
// the wrong products (Short Kurtis showed Long Kurtis items) and silently returned
// nothing for any category family without a hand-written rule. These walk the real
// parent_id tree instead, so any depth and any future category works untouched.

/** All categories as id => ['id','name','parent_id'], read once and reused. */
function allCategoriesById(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $cache = [];
    try {
        foreach ($pdo->query("SELECT id, name, parent_id FROM categories")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $cache[(int)$c['id']] = [
                'id'        => (int)$c['id'],
                'name'      => $c['name'],
                'parent_id' => (int)($c['parent_id'] ?? 0),
            ];
        }
    } catch (PDOException $e) { $cache = []; }
    return $cache;
}

/**
 * A category id plus every descendant id, to any depth.
 * Guards against a cycle (a category made its own ancestor) so a bad row in the
 * table can never spin this into an infinite loop.
 */
function categoryDescendantIds(PDO $pdo, int $categoryId): array {
    $all = allCategoriesById($pdo);
    if (!isset($all[$categoryId])) { return [$categoryId]; }

    $childrenOf = [];
    foreach ($all as $c) { $childrenOf[$c['parent_id']][] = $c['id']; }

    $out   = [];
    $stack = [$categoryId];
    $seen  = [];
    while ($stack) {
        $id = array_pop($stack);
        if (isset($seen[$id])) { continue; }
        $seen[$id] = true;
        $out[] = $id;
        foreach ($childrenOf[$id] ?? [] as $child) { $stack[] = $child; }
    }
    return $out;
}

/** Resolve category names (as used in URLs) to ids, including their descendants. */
function categoryIdsForNames(PDO $pdo, array $names): array {
    $all = allCategoriesById($pdo);
    $byName = [];
    foreach ($all as $c) { $byName[mb_strtolower(trim($c['name']))] = $c['id']; }

    $ids = [];
    foreach ($names as $n) {
        $key = mb_strtolower(trim((string)$n));
        if ($key === '' || !isset($byName[$key])) { continue; }
        foreach (categoryDescendantIds($pdo, $byName[$key]) as $id) { $ids[$id] = true; }
    }
    return array_keys($ids);
}

// ── Automatic SEO / OpenGraph / Schema derivation ───────────────────────────
// Every product needs a meta title, description, slug and keyword set. Filling
// those in by hand for each garment is tedious and usually gets skipped, which
// leaves Google and social previews showing a bare product name. These helpers
// derive sensible values from data the admin has already entered.
//
// The rule everywhere: an explicit value the admin typed ALWAYS wins. These are
// only consulted when the corresponding field is blank.

/**
 * Is this attribute value fit to show a human in a meta tag?
 *
 * products.color carries a column default of '#ff6b9d,#c44dff' — a leftover hex
 * swatch — so every new product inherits it. Without this filter those hex codes
 * ended up in the keywords meta tag and, on short descriptions, in the meta
 * description itself. Anything that is a hex code, a list of hex codes, or has no
 * letters in it is not a description of a garment.
 */
function isHumanReadableAttribute($value): bool {
    $v = trim((string)$value);
    if ($v === '') { return false; }
    if (preg_match('/^[#0-9a-f\s,;]+$/i', $v)) { return false; }  // hex codes / numeric lists
    if (!preg_match('/[a-z]{2,}/i', $v)) { return false; }        // no real word in it
    return true;
}

/**
 * The colour a customer should be shown for a product.
 *
 * Two columns hold this. color_way is the real one — the full list a garment IS,
 * "Ivory, Gold" — written by the Colour Way picker in the admin. products.color
 * is a leftover: admin/product_form.php stores only the FIRST entry there,
 * truncated to 50 characters, for the older code that still reads it.
 *
 * Reading `color` therefore shows one colour where the garment has three, and
 * five places had each picked their own order. The Merchant Centre feed had it
 * right and the compare drawer had it wrong, so the same garment was one colour
 * to Google and another in the table. color_way first, everywhere; `color` stays
 * as the fallback for any product not re-saved since the picker arrived, and an
 * empty string when neither is set so callers can test it plainly.
 */
function dievonProductColour($product): string {
    $way = trim((string)(is_array($product) ? ($product['color_way'] ?? '') : ''));
    if ($way !== '') { return $way; }
    return trim((string)(is_array($product) ? ($product['color'] ?? '') : ''));
}

/** Truncate on a word boundary, never mid-word, and never exceed $limit including the ellipsis. */
function seoTruncate($text, $limit) {
    $text = trim(preg_replace('/\s+/', ' ', strip_tags((string)$text)));
    if ($text === '' || mb_strlen($text) <= $limit) { return $text; }
    $slice = mb_substr($text, 0, $limit - 1);
    $lastSpace = mb_strrpos($slice, ' ');
    if ($lastSpace !== false && $lastSpace > $limit * 0.5) {
        $slice = mb_substr($slice, 0, $lastSpace);
    }
    return rtrim($slice, " ,.;:-") . '…';
}

/**
 * Meta title: "<Name> — <Category> | Dievon", trimmed to ~60 chars, which is
 * roughly where Google truncates. The brand suffix is dropped before the product name
 * is sacrificed, since the name is the part that actually earns the click.
 */
function autoMetaTitle(array $p) {
    $name = trim((string)($p['name'] ?? ''));
    if ($name === '') { return ''; }
    $category = trim((string)($p['category'] ?? ''));
    $brand    = 'Dievon';

    $withAll = $category !== '' ? "{$name} — {$category} | {$brand}" : "{$name} | {$brand}";
    if (mb_strlen($withAll) <= 60) { return $withAll; }

    $withBrand = "{$name} | {$brand}";
    if (mb_strlen($withBrand) <= 60) { return $withBrand; }

    return seoTruncate($name, 60);
}

/**
 * Meta description: the product's own description, cleanly truncated to ~155 chars.
 * If the description is too thin to be useful on its own, enrich it with the
 * attributes that shoppers actually search for (fabric, colour, occasion).
 */
function autoMetaDescription(array $p) {
    $desc = trim(preg_replace('/\s+/', ' ', strip_tags((string)($p['description'] ?? ''))));

    if (mb_strlen($desc) < 60) {
        $bits = [];
        foreach (['fabric', 'color', 'pattern', 'occasion'] as $key) {
            $v = trim((string)($p[$key] ?? ''));
            // Skip hex swatches and other non-words — see isHumanReadableAttribute().
            if (isHumanReadableAttribute($v)) { $bits[] = $v; }
        }
        $name     = trim((string)($p['name'] ?? ''));
        $category = trim((string)($p['category'] ?? ''));
        $lead     = $desc !== '' ? $desc : trim($name . ($category !== '' ? " — luxury {$category} by Dievon." : '.'));
        if ($bits) { $lead .= ' ' . implode(' · ', $bits) . '.'; }
        $desc = trim($lead);
    }
    return seoTruncate($desc, 155);
}

/** URL slug derived from the product name. */
function autoSeoSlug(array $p) {
    $name = trim((string)($p['name'] ?? ''));
    return $name === '' ? '' : slugify($name);
}

/** Keyword set built from the attributes already recorded against the product. */
function autoSeoTags(array $p) {
    $tags = [];
    foreach (['category', 'fabric', 'color', 'pattern', 'occasion', 'sleeve', 'neck', 'brand'] as $key) {
        $v = trim((string)($p[$key] ?? ''));
        if (isHumanReadableAttribute($v)) { $tags[] = mb_strtolower($v); }
    }
    $name = trim((string)($p['name'] ?? ''));
    if ($name !== '') { $tags[] = mb_strtolower($name); }
    $tags[] = 'dievon';

    // Case-insensitive de-dupe that preserves the order above.
    $seen = [];
    $out  = [];
    foreach ($tags as $t) {
        $k = mb_strtolower($t);
        if (!isset($seen[$k])) { $seen[$k] = true; $out[] = $t; }
    }
    return implode(', ', $out);
}

/**
 * Resolve the final SEO values for a product: the admin's explicit entry when
 * present, otherwise the derived one. Single source of truth for the storefront.
 */
function resolveProductSeo(array $p) {
    return [
        'meta_title'       => trim((string)($p['meta_title'] ?? ''))       ?: autoMetaTitle($p),
        'meta_description' => trim((string)($p['meta_description'] ?? '')) ?: autoMetaDescription($p),
        'seo_url'          => trim((string)($p['seo_url'] ?? ''))          ?: autoSeoSlug($p),
        'tags'             => trim((string)($p['tags'] ?? ''))             ?: autoSeoTags($p),
    ];
}

// Creates an upload directory (if missing) and drops in an .htaccess that stops any
// uploaded file being executed as a script.
//
// IMPORTANT: `php_flag` MUST stay wrapped in <IfModule>. It is only a valid directive
// when PHP runs as an Apache module; under CGI/FastCGI/PHP-FPM — which is how MAMP and
// most modern hosts run PHP — an unguarded `php_flag` is an "Invalid command" fatal and
// Apache returns HTTP 500 for *every* request in that directory, including the images.
// That is exactly what previously made all uploaded RMA return photos unviewable.
function hardenUploadDir($absoluteDir) {
    if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
        return false;
    }
    $htaccess = rtrim($absoluteDir, '/') . '/.htaccess';
    if (is_file($htaccess)) {
        return true;
    }
    $rules = <<<'HTACCESS'
# Prevent any uploaded file in this directory from being executed as a script.
# `php_flag` is guarded: unguarded it is fatal under CGI/FPM and 500s every request.
<IfModule mod_php.c>
    php_flag engine off
</IfModule>
<IfModule mod_php7.c>
    php_flag engine off
</IfModule>
<IfModule mod_php5.c>
    php_flag engine off
</IfModule>

<FilesMatch "\.(?i:php|phtml|phar|php3|php4|php5|php7|phps|cgi|pl|py|sh|exe|htaccess)$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order allow,deny
        Deny from all
    </IfModule>
</FilesMatch>

<IfModule mod_mime.c>
    RemoveHandler .php .phtml .phar .php3 .php4 .php5 .php7 .phps .cgi .pl .py
    RemoveType .php .phtml .phar .php3 .php4 .php5 .php7 .phps
</IfModule>

Options -ExecCGI -Indexes
HTACCESS;
    file_put_contents($htaccess, $rules . "\n");
    return true;
}

// Generates a same-name .webp copy alongside an uploaded JPEG/PNG/GIF, for pages to
// optionally serve via <picture> (smaller file, faster load — a real Core Web Vitals
// factor). The original file is never modified or removed. Silently no-ops if GD is
// unavailable or the source type is unsupported, since WebP is a bonus, not a requirement.
function generateWebpCopy($absoluteSourcePath) {
    if (!extension_loaded('gd') || !function_exists('imagewebp') || !is_file($absoluteSourcePath)) {
        return false;
    }
    $destPath = preg_replace('/\.[^.]+$/', '.webp', $absoluteSourcePath);

    // Detect the real format from file content, not the extension — an upload can be
    // saved with a mismatched extension (e.g. a .png that is actually JPEG data).
    $imageInfo = @getimagesize($absoluteSourcePath);
    $type = $imageInfo[2] ?? null;

    try {
        switch ($type) {
            case IMAGETYPE_JPEG:
                $img = @imagecreatefromjpeg($absoluteSourcePath);
                break;
            case IMAGETYPE_PNG:
                $img = @imagecreatefrompng($absoluteSourcePath);
                if ($img) { imagepalettetotruecolor($img); imagealphablending($img, true); imagesavealpha($img, true); }
                break;
            case IMAGETYPE_GIF:
                $img = @imagecreatefromgif($absoluteSourcePath);
                break;
            default:
                return false;
        }
        if (!$img) { return false; }
        $ok = imagewebp($img, $destPath, 85);   // delivery copy — matched to the 90-quality original
        imagedestroy($img);
        return $ok;
    } catch (\Throwable $e) {
        return false;
    }
}

// Returns the .webp sibling path (URL) for an uploaded image if it actually exists on
// disk, else null — lets templates opt into <picture> only when a real WebP file is there.
function webpUrlIfExists($uploadsSubdir, $filename) {
    if (empty($filename)) { return null; }
    $webpFilename = preg_replace('/\.[^.]+$/', '.webp', $filename);
    $srcPath = __DIR__ . '/../uploads/' . $uploadsSubdir . '/' . $filename;
    $webpPath = __DIR__ . '/../uploads/' . $uploadsSubdir . '/' . $webpFilename;
    if ($webpFilename === $filename || !webpTwinIsFresh($srcPath, $webpPath)) {
        return null;
    }
    return SITE_URL . '/uploads/' . $uploadsSubdir . '/' . $webpFilename;
}

/**
 * The URL the <img> inside a WebP <picture> should carry.
 *
 * A <picture> is supposed to fetch ONE file: the browser takes the WebP
 * <source> and the <img> src is only the fallback for a browser that cannot
 * read it. On the home page that stopped being true — every product photograph
 * was fetched TWICE, once as the WebP that was painted and once as the JPEG
 * fallback that nothing ever displayed. Measured on a cold load: 6.06 MB of
 * JPEG across 11 photographs, against 0.85 MB of WebP that was actually shown,
 * so the page pulled roughly eight times the image bytes it put on screen.
 *
 * Pointing the fallback at the SAME WebP file makes the second request
 * impossible rather than merely unlikely: whichever candidate the browser
 * resolves to, it is one URL and therefore one fetch. That is deliberately a
 * blunter instrument than chasing whichever DOM operation provoked the reload —
 * it cannot regress the day some other script touches those images.
 *
 * The <picture>/<source> wrapper is kept so every CSS rule and script that
 * expects `picture > img` keeps matching. Returns the original whenever there
 * is no fresh WebP twin, so a product whose WebP is missing or stale still
 * renders its real photograph.
 */
function pictureImgSrc(string $originalUrl, ?string $webpUrl): string
{
    return ($webpUrl !== null && $webpUrl !== '') ? $webpUrl : $originalUrl;
}

/**
 * May this WebP twin actually be served?
 *
 * False when the twin is missing, or when it is STALE — older than the file it
 * was generated from. The lookbook admin rewrites the PNG first and regenerates
 * the WebP after; if that regeneration silently fails on a live host (file
 * permissions, GD limits), an old WebP file keeps existing and would serve the
 * PREVIOUS photograph wherever a <picture>/<source> prefers WebP. A WebP older
 * than its own source cannot be anything but stale, so the PNG is served
 * instead. Product photos never change in place, so this never triggers for
 * them.
 */
function webpTwinIsFresh(string $srcPath, string $webpPath): bool
{
    if (!is_file($webpPath)) { return false; }
    if (is_file($srcPath) && filemtime($webpPath) < filemtime($srcPath)) { return false; }
    return true;
}

/**
 * May the WebP <source> derived from an <img> URL be emitted?
 *
 * The <picture> blocks in blog.php / blog-single.php / home.php derive the
 * twin's path from the <img> URL with preg_replace(). is_file() alone is not
 * enough — a stale twin would still exist and browsers prefer the <source>,
 * so the OLD photo would win even when the <img> is a fresh PNG. This applies
 * the same freshness rule webpTwinIsFresh() gives the direct references.
 */
function webpSourceIsFresh(string $imgUrl, string $webpFile): bool
{
    if (!is_file($webpFile)) { return false; }
    $imgPath = explode('?', $imgUrl, 2)[0];
    if (!preg_match('/\.png$/i', $imgPath)) { return true; }
    $imgFile = str_replace(SITE_URL . '/', __DIR__ . '/../', $imgPath);
    return !is_file($imgFile) || filemtime($webpFile) >= filemtime($imgFile);
}

/**
 * How many lookbook slots the site has.
 *
 * Named rather than repeated as a literal, because the number appears in three
 * places that must agree: this helper's clamp, the admin screen's slot list,
 * and its upload validation. They had drifted before — the helper clamped to 3
 * while the pages referenced more.
 */
const LOOKBOOK_SLOTS = 4;

/**
 * The picture for a blog article — resolved with NO reference to the lookbook.
 *
 * Blog and lookbook used to share images, and it caused two visible problems:
 * every seeded article stored a `lookbook_N.png` filename, so replacing a
 * lookbook photograph silently changed the blog cards with it; and the admin
 * list resolved those names against uploads/products/ while the storefront
 * resolved them against uploads/gallery/, so the same article showed one
 * photograph in the panel and a different one on the site.
 *
 * They are separate now. A `lookbook_*` value counts as "this article has no
 * picture of its own" and falls to the blog's OWN default, which lives beside
 * the lookbook files but is never touched by the Lookbook screen.
 *
 * Returns '' when there is no usable image at all, so a caller can render no
 * media rather than a broken one.
 */
function blogImageUrl(?string $file): string
{
    $file = trim((string)$file);
    // uploads/blog first — that is where new article pictures are written.
    // products and gallery stay in the list so articles uploaded before the
    // blog had its own folder still resolve.
    $dirs = ['blog', 'products', 'gallery'];

    // A real uploaded picture wins — but never a lookbook filename.
    if ($file !== '' && strpos($file, 'lookbook_') !== 0) {
        foreach ($dirs as $d) {
            if (is_file(__DIR__ . '/../uploads/' . $d . '/' . $file)) {
                $webp = webpUrlIfExists($d, $file);
                return cacheBustedUploadUrl($webp ?: SITE_URL . '/uploads/' . $d . '/' . $file);
            }
        }
    }

    // The blog's own default. Replaceable on its own, without touching a lookbook.
    $defPng = __DIR__ . '/../uploads/gallery/blog_default.png';
    if (is_file($defPng)) {
        $webp = webpUrlIfExists('gallery', 'blog_default.png');
        return cacheBustedUploadUrl($webp ?: SITE_URL . '/uploads/gallery/blog_default.png');
    }

    return '';
}



/**
 * Cache-busted URL for one of the three fixed lookbook photographs.
 *
 * The storefront hardcodes lookbook_1/2/3 in ~20 files, and .htaccess tells
 * browsers to cache images for a YEAR. When the owner replaces a lookbook
 * photo in admin (admin/lookbook.php) the file is rewritten in place — the URL
 * never changes, so every visitor's browser kept serving the OLD photograph
 * from cache for up to a year, and the replacement appeared nowhere on the
 * storefront (the admin preview already appended ?v=filemtime, which is why it
 * looked fine there). This appends the file's mtime so the URL changes exactly
 * when the file changes: the new photo is fetched on the next page load.
 *
 * Falls back to the PNG when the requested WebP twin is missing, so a URL from
 * this helper can never point at a file that does not exist.
 */
function lookbookUrl(int $slot, string $ext = 'webp'): string
{
    // Four slots. Slot 4 is the policy pages — Privacy, Terms, Shipping,
    // Returns — which previously borrowed 1, 2 and 3, so changing the About
    // page photograph silently changed the Privacy page too.
    $slot = max(1, min(LOOKBOOK_SLOTS, $slot));

    // A slot whose file has not been uploaded yet falls back to slot 1 rather
    // than emitting a src that 404s. Slot 4 ships with no image of its own, so
    // without this every policy page would show a broken picture from the
    // moment this code lands until the shopkeeper uploads one.
    if (!is_file(__DIR__ . '/../uploads/gallery/lookbook_' . $slot . '.png')
        && !is_file(__DIR__ . '/../uploads/gallery/lookbook_' . $slot . '.webp')) {
        $slot = 1;
    }

    // A men's version of this slot, when one has been uploaded.
    //
    // These photographs are the brand's own imagery — the About page, the shop
    // hero, the homepage editorial — so on the menswear side they were showing
    // women in kurtis. lookbook_<n>_men.png sits beside the existing file and
    // is used only while the men's side is being browsed.
    //
    // Entirely optional: with no men's file uploaded this changes nothing, and
    // the women's photograph is served exactly as before. That matters because
    // most slots appear on pages with no audience at all — cart, checkout,
    // order confirmation — where one picture for everyone is correct.
    $slotName = 'lookbook_' . $slot;
    if (function_exists('currentShopGender') && currentShopGender() === 'men') {
        $menBase = 'lookbook_' . $slot . '_men';
        if (is_file(__DIR__ . '/../uploads/gallery/' . $menBase . '.png')
            || is_file(__DIR__ . '/../uploads/gallery/' . $menBase . '.webp')) {
            $slotName = $menBase;
        }
    }

    $ext  = ($ext === 'png') ? 'png' : 'webp';
    $url  = SITE_URL . '/uploads/gallery/' . $slotName . '.' . $ext;
    if ($ext === 'webp') {
        $png  = __DIR__ . '/../uploads/gallery/' . $slotName . '.png';
        $webp = __DIR__ . '/../uploads/gallery/' . $slotName . '.webp';
        // Serve the PNG instead of the WebP whenever the WebP is missing OR
        // stale. The admin rewrites the PNG first and then regenerates the WebP
        // twin; if that regeneration silently fails on a live host (file
        // permissions, GD limits), the old WebP file would keep serving the
        // PREVIOUS photograph under a cache-busted URL — which is exactly the
        // "some lookbook images never change" symptom. A WebP older than its
        // PNG cannot be anything but stale.
        if (!is_file($webp) || (is_file($png) && filemtime($webp) < filemtime($png))) {
            $url = SITE_URL . '/uploads/gallery/' . $slotName . '.png';
        }
    }
    return cacheBustedUploadUrl($url);
}

/**
 * Append a ?v=<mtime> cache-buster to any /uploads/ URL.
 *
 * Used at the render points where the URL is built through webpUrlIfExists() /
 * preg_replace() (hero slider fallback, occasion panels, blog cards, article
 * hero) — the same stale-cache problem lookbookUrl() solves for the direct
 * references. Harmless for product photos, which never change in place: their
 * version is simply constant. Any existing query string is stripped first.
 */
function cacheBustedUploadUrl(string $url): string
{
    $path = explode('?', $url, 2)[0];
    $abs  = str_replace(SITE_URL . '/', __DIR__ . '/../', $path);
    $v    = is_file($abs) ? (int)@filemtime($abs) : 0;
    return $path . '?v=' . $v;
}

/* ═══════════════════════════════════════════════════════════
   GST INVOICE SERIAL NUMBERS
   ═══════════════════════════════════════════════════════════
   Every tax invoice needs a serial that never repeats and never gets re-used.
   Numbers run per Indian financial year (April–March), so the serial carries
   the year it was issued in: DI/2026-27/00014. Skipped numbers (a cancelled
   order, a failed attempt) are fine — GST auditors expect gaps, what they do
   not accept is a duplicate.

   nextInvoiceNumber() is the ONLY writer. The counter lives in its own
   invoice_sequences table and the bump is a single atomic UPDATE
   (INSERT ... ON DUPLICATE KEY UPDATE with LAST_INSERT_ID), so two checkouts
   happening at the same instant can never read the same number.
   ═══════════════════════════════════════════════════════════ */

/**
 * Indian financial year a date falls in, as "2026-27" (Apr–Mar, so an April
 * 2026 order is FY 2026-27 while a March 2026 order is FY 2025-26).
 */
function financialYearFor(?string $date = null): string
{
    $ts = ($date !== null && $date !== '') ? strtotime($date) : time();
    $y  = (int)date('Y', $ts);
    $m  = (int)date('n', $ts);
    $start = ($m >= 4) ? $y : $y - 1;
    return $start . '-' . substr((string)($start + 1), -2);
}

/* 16 characters is the legal ceiling for a tax invoice number, and the rest of
   the format — "/2026-27/" plus a five-digit serial — already spends 14 of them. */
if (!defined('GST_INVOICE_NUMBER_MAX_LEN')) { define('GST_INVOICE_NUMBER_MAX_LEN', 16); }
if (!defined('INVOICE_PREFIX_MAX_LEN')) { define('INVOICE_PREFIX_MAX_LEN', GST_INVOICE_NUMBER_MAX_LEN - 14); }

/**
 * The two-letter business prefix on every invoice serial, e.g. "DI" in
 * DI/2026-27/00014. Admin-editable via Store Settings (invoice_prefix) so the
 * owner can switch it without a deploy; never empty.
 */
function invoicePrefix(?PDO $pdo = null): string
{
    $p = trim((string)storeSetting($pdo, 'invoice_prefix', 'DI'));

    /* Sanitised here, not just in the form.
       ────────────────────────────────────────────────────────────────────
       CGST Rule 46(b) allows a tax invoice number of at most SIXTEEN
       characters, and only letters, digits, "-" and "/". The number this
       builds is:

           PREFIX / 2026-27 / 00014
             ?   +1+   7   +1+  5     = 14 + strlen(PREFIX)

       so anything past two characters produces an invoice number that is
       too long to be valid. The settings field accepted ten — "DIEVONSHOP"
       would have printed a 24-character number on every tax invoice, and
       nothing would have complained until an assessment.

       A prefix is typed once and then appears on every invoice for years,
       so it is cleaned where it is USED rather than only where it is
       entered: a value already saved by the old field, or edited straight
       in the database, still cannot produce an invalid number. */
    $p = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $p) ?? '');
    $p = substr($p, 0, INVOICE_PREFIX_MAX_LEN);

    return $p === '' ? 'DI' : $p;
}

/**
 * The next invoice serial for the financial year of $date (default: now).
 *
 * Assigned exactly once per order and frozen on the row. New orders get it at
 * checkout; orders placed before numbering existed get it lazily on first print
 * (see admin/print_invoice.php / pages/print_invoice.php). Both call THIS
 * function, so there is a single sequence and no chance of two orders ending up
 * with the same serial no matter which path issued it.
 */
function nextInvoiceNumber(?PDO $pdo, string $date = ''): string
{
    $fy = financialYearFor($date !== '' ? $date : null);

    // One atomic statement, and the number comes straight back through
    // LAST_INSERT_ID() on the SAME connection: the fresh-INSERT branch seeds it
    // to 1 (VALUES (:fy, LAST_INSERT_ID(1))), the duplicate branch bumps it by 1
    // under a row lock and returns the new value. This is race-free — reading the
    // row back with a separate SELECT could return a number a concurrent checkout
    // had already bumped past, handing two orders the same serial. The table has
    // no AUTO_INCREMENT column, so the explicit LAST_INSERT_ID(1) on a fresh row
    // is what makes the fresh path return 1 rather than 0.
    $pdo->prepare(
        "INSERT INTO invoice_sequences (financial_year, last_number)
         VALUES (:fy, LAST_INSERT_ID(1))
         ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1)"
    )->execute(['fy' => $fy]);

    $n = (int)$pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
    if ($n < 1) { $n = 1; }

    return invoicePrefix($pdo) . '/' . $fy . '/' . str_pad((string)$n, 5, '0', STR_PAD_LEFT);
}

// ── CSRF Token & Security Helpers ───────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// This response depends on a cookie. Say so, to every cache in between.
//
// "/" and "/shop" choose which products to show from the dievon_shop_for
// cookie (home.php:88 and shop.php:509, via shopGenderSqlFilter) while both
// declare one fixed canonical URL — so a single address is two different
// documents. Nothing was telling a shared cache what that difference depended
// on, and .htaccess gives everything an ExpiresDefault of two days. On a host
// with edge caching in front of the origin, which is the normal Hostinger
// arrangement, one visitor pressing "Men" could have that response stored and
// replayed to everyone who requested the same URL afterwards — Googlebot
// included. That is how a cookie bug turns into an indexing bug.
//
// session_start() above already emits Cache-Control: no-store through PHP's
// default cache limiter, and that is the only thing preventing this today. It
// is a side effect rather than a decision: one session_cache_limiter('public'),
// or one entry point that never opens a session, and the protection vanishes
// with nothing to show for it.
//
// Sent from PHP rather than .htaccess deliberately. Three .htaccess forms were
// tried — an expr= on %{CONTENT_TYPE}, a <FilesMatch "\.php$"> wrapper, and
// SetEnvIf with `Header always set ... env=` — and every one emitted nothing
// while the security headers beside them kept working. The live host runs
// LiteSpeed, not Apache, so a directive that cannot be verified here could fail
// there just as quietly, or 500 the site. This runs everywhere and can be read
// back off the response. The `false` argument appends rather than replaces, so
// mod_deflate's own Vary: Accept-Encoding survives.
if (!headers_sent()) {
    header('Vary: Cookie', false);
}

function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(?string $token): bool {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function logAdminAction($adminId, $action, $details = '') {
    global $pdo;
    if (!$pdo) return;
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $stmt = $pdo->prepare("INSERT INTO admin_audit_logs (admin_id, action, details, ip_address) VALUES (:aid, :act, :det, :ip)");
        $stmt->execute(['aid' => (int)$adminId, 'act' => $action, 'det' => $details, 'ip' => $ip]);
    } catch (Exception $e) {}
}


/* ═══════════════════════════════════════════════════════════
   ORDER STATUSES — one definition, used everywhere
   ═══════════════════════════════════════════════════════════
   These lists were previously written out by hand in two places:
   the <select> in admin/orders.php and the whitelist in
   admin/update_order.php. They had drifted apart — 'Pending' was
   missing from BOTH, even though it is the status every order is
   created with (actions/checkout_action.php, razorpay_webhook.php
   and the column default all use it).

   The effect: with no matching <option>, the browser fell back to
   showing the first entry, "Pending Payment". Pressing Save on an
   untouched order silently rewrote its status, dropped it out of
   the "Pending Orders" counter, and told a customer who had already
   paid that payment was outstanding. The whitelist then rejected
   'Pending', so it could never be set back.

   Anything that offers or validates a status must read these
   functions, so the two can never disagree again.
*/

/** Every status an order may hold, in workflow order. */
function orderStatusList(): array {
    return [
        'Pending',            // freshly placed — the default for every new order
        'Pending Payment',
        'Confirmed',
        'Processing',
        'Packed',
        'Shipped',
        'Delivered',
        // A customer asked to cancel an order they had already PAID for. It is not
        // cancelled yet — money is involved, so the shop approves it and issues the
        // refund from the admin Refund panel. Unpaid orders skip this and cancel
        // outright, because there is nothing to give back.
        'Cancellation Requested',
        'Cancelled',
        'Return Requested',
        'Returned',
        'Refunded',
        'Exchange Requested',
        'RTO',
    ];
}

/** True when $status is one this shop recognises. Case-sensitive by design. */
function isValidOrderStatus(string $status): bool {
    return in_array($status, orderStatusList(), true);
}

/**
 * The fourteen statuses collapsed into the four buckets a CUSTOMER thinks in.
 *
 * A shopper does not distinguish Packed from Processing — both mean "you have my
 * money and I am waiting". They care about four questions: is it coming, did it
 * arrive, did I call it off, am I getting money back. Anything sorting orders
 * for a customer should sort them by these, not by the raw status.
 *
 * Defined here rather than inside the account page because this shop has been
 * bitten repeatedly by the same rule living in two files and drifting apart —
 * two shop query builders, two price resolvers, two banner image lookups. Any
 * screen that wants these buckets calls this, and there is one place to change
 * when a status is added.
 *
 * @return array<string, array{label: string, statuses: string[]}>
 */
function orderStatusGroups(): array {
    return [
        'open'      => ['label' => 'In Progress', 'statuses' => [
            'Pending', 'Pending Payment', 'Confirmed', 'Processing', 'Packed', 'Shipped',
        ]],
        'delivered' => ['label' => 'Delivered', 'statuses' => [
            'Delivered',
        ]],
        'cancelled' => ['label' => 'Cancelled', 'statuses' => [
            // RTO — "return to origin" — is a parcel the courier could not deliver
            // and sent back. From the customer's side that is a cancelled order,
            // not a return: they never had it in their hands.
            'Cancellation Requested', 'Cancelled', 'RTO',
        ]],
        'returns'   => ['label' => 'Returns & Refunds', 'statuses' => [
            'Return Requested', 'Returned', 'Refunded', 'Exchange Requested',
        ]],
    ];
}

/**
 * Which bucket $status belongs to, or '' when the status is unrecognised.
 *
 * Returns '' rather than guessing a bucket. An unknown status is a bug to be
 * seen, not one to be filed quietly under "In Progress" where nobody notices.
 */
function orderStatusGroup(string $status): string {
    foreach (orderStatusGroups() as $key => $group) {
        if (in_array($status, $group['statuses'], true)) {
            return $key;
        }
    }
    return '';
}

/**
 * Badge colours for an order status: [background, text].
 *
 * The account page painted EVERY badge the same green — a cancelled order and a
 * refunded one both announced themselves in success green, which tells the
 * customer the opposite of what happened.
 */
function orderStatusBadgeColours(string $status): array {
    switch (orderStatusGroup($status)) {
        case 'delivered': return ['#e6f7ec', '#2e7d32']; // green — it arrived
        case 'cancelled': return ['#fdecea', '#a94442']; // red — it is off
        case 'returns':   return ['#fff4e5', '#8a5a00']; // amber — money in motion
        case 'open':      return ['#eef2fb', '#2c4a7c']; // blue — in flight
        default:          return ['#f2f2f2', '#555555']; // grey — unrecognised
    }
}

/**
 * Statuses a person may set BY HAND from the Orders screen.
 *
 * orderStatusList() is every state an order can be in. That is not the same as
 * every state someone may declare, and treating the two as one opened a hole:
 * the plain status dropdown is gated on orders.manage — the permission given to
 * whoever marks parcels dispatched — while the Refund panel is gated on
 * refunds.manage. But 'Refunded' was selectable from the dropdown, so an order
 * clerk could set it in one click and the shop would:
 *
 *   - put the garment back on sale, because Refunded triggers the stock restore
 *   - drop the sale out of the GST report, which excludes Refunded orders
 *   - send NO money to anyone and write NO refund record
 *
 * Money kept, stock back on the shelf, sale gone from the tax return — with no
 * warning and nothing to review afterwards. 'Returned' had the same shape.
 *
 * These states are OUTCOMES, not declarations: they become true because a refund
 * was issued (services/RefundService.php) or an RMA completed (admin/returns.php),
 * and those paths set them directly. The customer's own requests likewise.
 *
 * What remains here is the parcel's journey, which is genuinely the clerk's job.
 */
function manuallySettableOrderStatuses(): array {
    return array_values(array_diff(orderStatusList(), [
        'Refunded',                // services/RefundService.php, after money moves
        'Returned',                // admin/returns.php, when an RMA completes
        'Cancellation Requested',  // the customer asks
        'Return Requested',        // the customer asks
        'Exchange Requested',      // the customer asks
    ]));
}

/** True when a person is allowed to select this status by hand. */
function isManuallySettableOrderStatus(string $status): bool {
    return in_array($status, manuallySettableOrderStatuses(), true);
}

/** Every payment state an order may hold. */
function paymentStatusList(): array {
    return ['Unpaid', 'Paid', 'Cash'];
}

function isValidPaymentStatus(string $status): bool {
    return in_array($status, paymentStatusList(), true);
}

/**
 * Payment states a PERSON may set by hand. 'Paid' is deliberately absent.
 *
 * The three states are not three flavours of the same thing — they differ in
 * who witnessed the money:
 *
 *   Paid    a machine verified it. actions/razorpay_webhook.php:92 is the only
 *           writer, and it is a server-to-server confirmation from Razorpay.
 *   Cash    a person verified it — notes handed over, or a transfer someone
 *           checked. The shop's own word, nothing else behind it.
 *   Unpaid  neither.
 *
 * While 'Paid' stayed hand-selectable those two meanings collapsed into one and
 * the distinction was worthless. It also let real damage through:
 *
 *   - GST reporting counts payment_status IN ('Paid','Cash'), so a hand-set
 *     'Paid' books revenue with no Razorpay settlement behind it. It cannot be
 *     reconciled, and it is a tax filing.
 *   - services/RefundService.php refuses to refund unless the order is paid or
 *     cash, so a wrong click the other way strands money the shop is holding
 *     with no way to give it back through the panel.
 *
 * Mirrors manuallySettableOrderStatuses() above — same problem, same shape.
 */
function manuallySettablePaymentStatuses(): array {
    return ['Unpaid', 'Cash'];
}

function isManuallySettablePaymentStatus(string $status): bool {
    return in_array($status, manuallySettablePaymentStatuses(), true);
}

/**
 * True when a payment gateway owns this order's payment status.
 *
 * Keyed on the presence of a gateway REFERENCE, not on payment_method. That
 * distinction is the escape hatch: if a card payment succeeds but the webhook
 * never arrives — misconfigured endpoint, dropped connection — the order has no
 * reference, stays editable, and the shop can still correct it by hand. Locking
 * on payment_method = 'online' would trap exactly the order most needing help.
 */
function orderIsGatewayPaid(array $order): bool {
    return trim((string)($order['razorpay_payment_id'] ?? '')) !== '';
}

/**
 * True when a refund on this order can travel back through Razorpay.
 *
 * All three conditions matter. The money must have come in through the gateway
 * (payment_method), the gateway must have given us something to refund AGAINST
 * (razorpay_payment_id), and the order must still be in the paid state the
 * gateway left it in (payment_status) — Razorpay will not refund a payment this
 * shop has since declared unpaid.
 *
 * Defined here because it was written twice and the copies had drifted. The
 * admin refund panel tested only the first two, so an online order whose payment
 * status had been changed by hand was shown as "goes back through Razorpay
 * automatically" and had its Cash/UPI/Bank dropdown hidden — while RefundService,
 * testing all three, recorded it as a MANUAL refund. The shop would have been
 * told the money was sent when nothing had been sent, and the payout channel it
 * was never offered would have been left blank in the books.
 *
 * One definition, so the screen and the service cannot disagree again.
 */
function orderIsOnlineRefundable(array $order): bool {
    return strtolower(trim((string)($order['payment_method'] ?? ''))) === 'online'
        && trim((string)($order['razorpay_payment_id'] ?? '')) !== ''
        && strtolower(trim((string)($order['payment_status'] ?? ''))) === 'paid';
}

/*
 * How long a customer has to open a return, counted from DELIVERY.
 * Defined once here so the code, the policy page and the printed invoice
 * cannot drift apart — they previously stated 14 days while the code measured
 * from the order date, giving roughly 9–11 real days.
 */
if (!defined('RETURN_WINDOW_DAYS')) { define('RETURN_WINDOW_DAYS', 14); }

/* ═══════════════════════════════════════════════════════════
   WHICH UPLOADED FILES ARE STILL IN USE
   ═══════════════════════════════════════════════════════════
   One definition, so the Media Library and any cleanup script
   can never disagree about what is safe to delete.

   This exists because admin/media.php previously checked four
   columns only and matched on the exact filename. Two ways that
   was dangerous, both of which DELETE LIVE FILES rather than
   merely leaving junk behind:

     1. The database always stores the original name (photo.jpg)
        and never the .webp copy generateWebpCopy() writes beside
        it — so every .webp on disk read as "unused", including
        the 20 that are twins of live product images.
     2. store_settings was not consulted at all, so the site
        logo, both favicons and the two live How-to-Measure
        diagrams also read as "unused".
*/

/** Every table.column anywhere in the schema that can name a file. */
function mediaFileColumns(): array {
    return [
        ['products', 'image'], ['products', 'video_url'],
        ['product_images', 'image'],
        ['product_colors', 'thumbnail'], ['product_color_images', 'image'],
        ['product_variants', 'image'],
        ['categories', 'image'],
        ['blog_posts', 'image'], ['banners', 'image'],
        ['gallery', 'filename'], ['gallery', 'image'],
        ['seo_settings', 'og_image'],
        ['size_guide_charts', 'illustration_image'],
        // Free-text / JSON columns: filenames are pulled out by pattern below.
        ['size_guide_snapshots', 'payload'],
        ['store_settings', 'setting_value'],
        ['customer_tickets', 'attachment'],
        ['customer_returns', 'photo'], ['customer_returns', 'image'],
    ];
}

/**
 * @return array{names: array<string,true>, stems: array<string,true>}
 *         'names' = exact basenames the database points at.
 *         'stems' = the same minus extension, used only to keep a .webp alive
 *                   while its original is alive.
 */
function referencedMediaFilenames(PDO $pdo): array {
    $names = [];
    $stems = [];

    $note = function (string $raw) use (&$names, &$stems) {
        $raw = trim($raw);
        if ($raw === '') { return; }
        // A value may be a bare filename, a path, or JSON/text containing several.
        if (preg_match_all('/[\w\-. ]+\.(?:jpe?g|png|webp|gif|svg|ico|mp4|webm|mov|pdf)/i', $raw, $m)) {
            foreach ($m[0] as $hit) {
                $b = basename(trim($hit));
                $names[$b] = true;
                $stems[strtolower(pathinfo($b, PATHINFO_FILENAME))] = true;
            }
        }
    };

    foreach (mediaFileColumns() as [$table, $col]) {
        try {
            // Tables and columns vary between installs and migrations; a missing
            // one must not take the whole listing down.
            foreach ($pdo->query("SELECT `$col` FROM `$table`")->fetchAll(PDO::FETCH_COLUMN) as $v) {
                $note((string)$v);
            }
        } catch (PDOException $e) {
            // table or column absent on this database — skip it
        }
    }

    return ['names' => $names, 'stems' => $stems];
}

/**
 * Is this file still referenced?
 *
 * The stem rule is deliberately limited to .webp. Those are generated copies
 * named after their source, so photo.webp lives exactly as long as photo.jpg.
 * Applying the same rule to other extensions would wrongly keep, say, an old
 * photo.png alive merely because an unrelated photo.jpg is referenced.
 */
/**
 * Files the CODE depends on, which no database row names.
 *
 * The media screens decide "unused" by scanning every filename column in the
 * database. That misses anything the PHP asks for by a fixed name or builds
 * from a pattern — and those files are exactly the ones whose loss is hardest
 * to explain, because nothing was edited and nothing errored.
 *
 * Concretely, before this list existed:
 *   blog_default.png   the fallback picture on every article without its own
 *   lookbook_1..4.png  the header photograph on About, Privacy, Terms,
 *                      Shipping and Returns — lookbookUrl() builds the name
 *                      from a slot number, so it appears nowhere as a literal
 *   logo / favicons    siteLogoPath() and the favicon helpers fall back to them
 *
 * Purge any of those and a page loses its picture with no error anywhere. This
 * is checked in isMediaFileInUse(), so every screen that asks the question gets
 * the right answer — rather than each one keeping its own list, which is how
 * media_cleanup.php came to protect three files and media.php none.
 */
function isCodeReferencedMedia(string $filename): bool {
    $base = mb_strtolower(basename($filename));
    $stem = mb_strtolower(pathinfo($base, PATHINFO_FILENAME));

    // Exact names.
    static $exact = [
        'logo.png', 'favicon.png', 'favicondark.png',
        'blog_default.png', 'blog_default.webp',
    ];
    if (in_array($base, $exact, true)) { return true; }
    if (in_array($stem . '.png', $exact, true)) { return true; }   // the .webp twin

    // Built from a pattern: lookbook_<slot>. LOOKBOOK_SLOTS decides how many.
    if (preg_match('/^lookbook_(\d+)$/', $stem, $m)) {
        $max = defined('LOOKBOOK_SLOTS') ? (int)LOOKBOOK_SLOTS : 4;
        return (int)$m[1] >= 1 && (int)$m[1] <= $max;
    }

    return false;
}

/**
 * SQL fragment limiting articles to the side of the shop being browsed.
 *
 * The mirror of shopGenderSqlFilter(), which every product query already uses.
 * Articles had no such filter, so a man on /men was shown womenswear pieces
 * illustrated with photographs of women — the one thing the audience switch
 * exists to prevent.
 *
 * Returns '' while only one audience trades, so the query is byte-identical to
 * before until menswear genuinely goes live. 'both' articles always show.
 */
function blogGenderSqlFilter(?PDO $pdo = null): string {
    if (!function_exists('shopGenderSelectorEnabled') || !shopGenderSelectorEnabled()) { return ''; }

    $g = function_exists('currentShopGender') ? currentShopGender() : 'women';
    $g = ($g === 'men') ? 'men' : 'women';

    // The column may not exist yet on a database that has not opened the blog
    // admin — in that case say nothing rather than failing the whole query and
    // leaving the Journal empty.
    $pdo = $pdo ?? ($GLOBALS['pdo'] ?? null);
    if ($pdo instanceof PDO) {
        try {
            if ($pdo->query("SHOW COLUMNS FROM blog_posts LIKE 'gender'")->rowCount() === 0) { return ''; }
        } catch (PDOException $e) { return ''; }
    }

    return " AND (gender = '" . $g . "' OR gender = 'both')";
}

/**
 * The folder a banner's picture actually lives in, resolved once.
 *
 * Banner uploads used to be written into uploads/products/, mixed in with
 * product photography — so clearing out product images destroyed the homepage
 * hero along with them, exactly as it destroyed the blog pictures. New uploads
 * go to uploads/banners/; the older folders stay in the search order so every
 * banner already on the site keeps working.
 *
 * Resolved in one place because pages/home.php and admin/banners.php each had
 * their own copy of this logic, and a third was about to be written. Returns
 * ['dir' => 'banners'|'products'|'gallery', 'file' => name] or null when the
 * file cannot be found anywhere.
 */
function bannerImageLocation(?string $file): ?array {
    $file = basename(trim((string)$file));
    if ($file === '') { return null; }
    foreach (['banners', 'products', 'gallery'] as $dir) {
        if (is_file(__DIR__ . '/../uploads/' . $dir . '/' . $file)) {
            return ['dir' => $dir, 'file' => $file];
        }
    }
    return null;
}

/** Full URL for a banner picture, or '' when the file is missing. */
function bannerImageUrl(?string $file): string {
    $loc = bannerImageLocation($file);
    return $loc ? SITE_URL . '/uploads/' . $loc['dir'] . '/' . rawurlencode($loc['file']) : '';
}

/**
 * Where a media file is actually referenced, in words the owner can act on.
 *
 * "That file is still being used by a product, post, banner or setting" is true
 * and useless: it names four possibilities and points at none of them. Worse,
 * the blocking row is often one that cannot be seen from any screen — an
 * ARCHIVED product, a DRAFT article, a settings key — so the owner hunts
 * through the catalogue for something that was never there.
 *
 * Returns a list like ["Archived product #16 (Oxford Structured Shirt)"].
 */
function mediaFileUsedBy(PDO $pdo, string $filename): array {
    $base = basename(trim($filename));
    if ($base === '') { return []; }

    // The .jpg twin counts: deleting a .webp is deleting the optimised copy of
    // whatever references the original.
    $stem  = pathinfo($base, PATHINFO_FILENAME);
    $where = [];

    // Named lookups first, so the answer can include the record's own title.
    $named = [
        ['products',    'image',   'id', 'name',  'Product',        'is_deleted'],
        ['blog_posts',  'image',   'id', 'title', 'Article',        null],
        ['banners',     'image',   'id', 'title', 'Banner',         null],
        ['categories',  'image',   'id', 'name',  'Category',       null],
    ];
    foreach ($named as [$table, $col, $idCol, $labelCol, $noun, $deletedCol]) {
        try {
            $sql = "SELECT `$idCol` AS id, `$labelCol` AS label"
                 . ($deletedCol ? ", `$deletedCol` AS archived" : ", 0 AS archived")
                 . " FROM `$table` WHERE `$col` = :a OR `$col` = :b LIMIT 5";
            $st = $pdo->prepare($sql);
            $st->execute(['a' => $base, 'b' => $stem . '.jpg']);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $prefix = !empty($r['archived']) ? 'Archived ' . strtolower($noun) : $noun;
                $where[] = $prefix . ' #' . $r['id'] . ' (' . mb_substr((string)$r['label'], 0, 40) . ')';
            }
        } catch (PDOException $e) {}
    }

    // Settings and the remaining free-text columns: no title to show, so name
    // the setting key itself where we can.
    try {
        $st = $pdo->prepare("SELECT setting_key FROM store_settings WHERE setting_value LIKE :x LIMIT 5");
        $st->execute(['x' => '%' . $base . '%']);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $k) { $where[] = 'Setting: ' . $k; }
    } catch (PDOException $e) {}

    /* Colour rows: name the product, and say so when the product is gone.
       ────────────────────────────────────────────────────────────────────
       These reported a bare noun — "Colour photo" — while everything above
       names itself ("Category #7 (Bridal)"). So the one reference that sends
       you hunting for a record gave the least to go on: no product, no
       colour, no id, and no way to tell it apart from a collection image or
       a banner you had already checked.

       Worse when the owning product has been purged: the row survives (see
       dievonPurgeProduct), so "change the picture on that record first"
       pointed at a record that no screen can open. Now it says which. */
    $colourSources = [
        'Colour photo' =>
            "SELECT p.id AS pid, p.name AS pname, p.is_deleted AS archived, c.color_name AS cname
               FROM product_color_images ci
               LEFT JOIN product_colors c ON c.id = ci.color_id
               LEFT JOIN products p       ON p.id = c.product_id
              WHERE ci.image = :a OR ci.image = :b LIMIT 5",
        'Colour thumbnail' =>
            "SELECT p.id AS pid, p.name AS pname, p.is_deleted AS archived, c.color_name AS cname
               FROM product_colors c
               LEFT JOIN products p ON p.id = c.product_id
              WHERE c.thumbnail = :a OR c.thumbnail = :b LIMIT 5",
    ];
    foreach ($colourSources as $noun => $sql) {
        try {
            $st = $pdo->prepare($sql);
            $st->execute(['a' => $base, 'b' => $stem . '.jpg']);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $colour = trim((string)($r['cname'] ?? ''));
                if ($r['pid'] === null) {
                    $where[] = $noun . ($colour !== '' ? ' “' . $colour . '”' : '')
                             . ' left behind by a deleted product — no screen can open it; '
                             . 'clear it on admin/repair_orphan_media.php';
                } else {
                    $where[] = ((int)$r['archived'] ? 'Archived product #' : 'Product #')
                             . (int)$r['pid'] . ' (' . mb_substr((string)$r['pname'], 0, 40) . ')'
                             . ' — ' . mb_strtolower($noun)
                             . ($colour !== '' ? ': ' . $colour : '');
                }
            }
        } catch (PDOException $e) {}
    }

    foreach ([['size_guide_charts', 'illustration_image', 'Size-guide diagram'],
              ['seo_settings', 'og_image', 'Share image']] as [$t, $c, $noun]) {
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE `$c` = :a OR `$c` = :b");
            $st->execute(['a' => $base, 'b' => $stem . '.jpg']);
            if ((int)$st->fetchColumn() > 0) { $where[] = $noun; }
        } catch (PDOException $e) {}
    }

    return array_values(array_unique($where));
}

function isMediaFileInUse(string $filename, array $referenced): bool {
    $base = basename($filename);

    // Asked first: a code-referenced file is in use however little the database
    // knows about it.
    if (isCodeReferencedMedia($base)) { return true; }

    if (isset($referenced['names'][$base])) { return true; }

    if (strtolower(pathinfo($base, PATHINFO_EXTENSION)) === 'webp') {
        return isset($referenced['stems'][strtolower(pathinfo($base, PATHINFO_FILENAME))]);
    }
    return false;
}

/**
 * Give a duplicated record its own physical copy of an uploaded file.
 *
 * Duplicating a product used to copy the FILENAME, leaving two rows pointing at
 * one file on disk. That is not just untidy: admin/product_form.php unlinks the
 * old file whenever an image is replaced, so editing either product destroyed
 * the other product's image with no warning.
 *
 * Copies the same-name .webp twin too, so the duplicate keeps its WebP.
 *
 * @return string the new filename, or the original when it could not be copied
 *                (better to share than to leave the duplicate with no image)
 */
function duplicateUploadedFile(string $filename, string $absoluteDir): string {
    $filename = basename(trim($filename));
    if ($filename === '') { return ''; }

    $absoluteDir = rtrim($absoluteDir, '/') . '/';
    $source = $absoluteDir . $filename;
    if (!is_file($source)) { return $filename; }

    $ext  = pathinfo($filename, PATHINFO_EXTENSION);
    $stem = pathinfo($filename, PATHINFO_FILENAME);

    // Random suffix, not a timestamp: two duplicates in the same second would
    // otherwise collide and re-create the shared-file problem this fixes.
    $newStem = $stem . '_copy_' . bin2hex(random_bytes(4));
    $newName = $newStem . ($ext !== '' ? '.' . $ext : '');

    if (!@copy($source, $absoluteDir . $newName)) {
        error_log("duplicateUploadedFile: could not copy $source");
        return $filename;
    }

    $sourceWebp = $absoluteDir . $stem . '.webp';
    if (is_file($sourceWebp)) {
        @copy($sourceWebp, $absoluteDir . $newStem . '.webp');
    }

    return $newName;
}

/**
 * Delete an uploaded file, and its generated .webp twin, safely.
 *
 * Every unlink in the admin should go through here. Two reasons:
 *
 *  1. Containment. admin/product_form.php built its unlink target straight from
 *     $_POST['existing_image'], so a posted value of "../gallery/lookbook_1.png"
 *     deleted a file outside uploads/products entirely. basename() alone is not
 *     enough to say a path is inside a directory — realpath() comparison is.
 *  2. The .webp twin. generateWebpCopy() writes photo.webp beside photo.jpg, and
 *     four of the five unlink sites in the admin removed only the original,
 *     stranding the twin forever with nothing in the database naming it.
 *
 * @return bool true when nothing is left behind (including "it was already gone")
 */
function deleteUploadedFile(string $filename, string $absoluteDir, bool $alsoWebp = true): bool {
    $filename = basename(trim($filename));
    if ($filename === '' || $filename === '.' || $filename === '..') { return false; }

    $realDir = realpath($absoluteDir);
    if ($realDir === false) { return false; }
    $realDir = rtrim($realDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    $ok = true;

    $target = $realDir . $filename;
    if (is_file($target)) {
        // Confirm the resolved path really sits inside the directory — this also
        // catches a symlink pointing somewhere else.
        $realTarget = realpath($target);
        if ($realTarget === false || strpos($realTarget, $realDir) !== 0) {
            error_log("deleteUploadedFile: refused to delete outside $realDir: $filename");
            return false;
        }
        if (!@unlink($realTarget)) {
            error_log("deleteUploadedFile: unlink failed for $realTarget");
            $ok = false;
        }
    }

    if ($alsoWebp) {
        $twin = $realDir . pathinfo($filename, PATHINFO_FILENAME) . '.webp';
        if (is_file($twin)) {
            $realTwin = realpath($twin);
            if ($realTwin !== false && strpos($realTwin, $realDir) === 0) {
                @unlink($realTwin);
            }
        }
    }

    return $ok;
}

/**
 * Delete an uploaded file only if no other record still names it.
 *
 * Necessary because uploads/products/ is a shared namespace: products, blog
 * posts, banners and size guide illustrations all write into it (see
 * admin/blog.php and admin/banners.php, which both set $destDir to
 * ../uploads/products/). A plain unlink($dir . $row['image']) bolted onto those
 * handlers would happily delete a file a live product is still using.
 *
 * Pass $ignore to discount the record being deleted or replaced right now —
 * otherwise its own row keeps the file alive and nothing is ever reclaimed.
 *
 * @param array $ignore [['table','column','id'], ...] rows to disregard
 * @return bool true if the file was removed, false if it is still in use
 *              (or could not be removed)
 */
function deleteUploadedFileIfUnused(PDO $pdo, string $filename, string $absoluteDir, array $ignore = []): bool {
    $base = basename(trim($filename));
    if ($base === '') { return false; }

    // Never delete a file the code depends on, whatever the database says.
    //
    // This function only asks the database, so blog_default.png and the
    // lookbook slots looked deletable to it — the Media screen's Delete button
    // routes through here, and would have removed the fallback picture behind
    // every article and the header photograph on five policy pages.
    if (isCodeReferencedMedia($base)) { return false; }

    foreach (mediaFileColumns() as [$table, $col]) {
        try {
            $sql = "SELECT COUNT(*) FROM `$table` WHERE `$col` LIKE :needle";
            $params = ['needle' => '%' . $base . '%'];

            // Discount the row(s) this delete is about.
            $n = 0;
            foreach ($ignore as [$iTable, $iCol, $iId]) {
                if ($iTable !== $table) { continue; }
                $sql .= " AND `$iCol` <> :ig$n";
                $params["ig$n"] = $iId;
                $n++;
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            if ((int)$stmt->fetchColumn() > 0) {
                return false;   // someone still needs it
            }
        } catch (PDOException $e) {
            // Table or column missing on this database. Err on the side of
            // keeping the file: a stranded file costs disk, a wrongly deleted
            // one costs the shop a picture it cannot get back.
            continue;
        }
    }

    return deleteUploadedFile($base, $absoluteDir);
}

/**
 * Shrink an uploaded image before it is stored.
 *
 * Uploads were saved exactly as they arrived — no resizing, no re-compression.
 * That left photos far larger than a web page ever needs: 896x1200 product
 * shots weighing 750-820KB (a quality-95 JPEG), and several 1825x1217 files
 * that no page displays above ~700px wide.
 *
 * Two things happen here:
 *   1. Anything wider or taller than $maxDimension is scaled down, keeping the
 *      aspect ratio. Nothing on the site renders larger than this.
 *   2. JPEGs are re-encoded at a sane quality. PNGs stay PNG so transparency
 *      (logos, swatches) survives; GIFs are left alone so animation survives.
 *
 * If the result is not actually smaller, the original is kept — re-encoding an
 * already-optimised file can make it grow.
 *
 * Why 2000px and quality 90, and not lower:
 *   - The product lightbox is capped at max-width 1400px
 *     (.product-lightbox-container-wrapper), so 2000px still leaves headroom on
 *     a high-DPI screen. Every current product photo is 896x1200 or smaller and
 *     is therefore NOT resized at all — only re-compressed.
 *   - Measured on a real product photo, quality 90 gives an average colour
 *     difference of 1.9 per channel out of 255 while cutting the file by ~69%.
 *     Dropping to 82 saves only another ~6% of the original and measurably
 *     degrades fabric and embroidery detail, which is the whole point of the
 *     photography on a fashion site.
 *
 * @return bool true if the file on disk was replaced with a smaller version
 */
function optimizeUploadedImage(string $absolutePath, int $maxDimension = 2000, int $jpegQuality = 90): bool {
    if (!extension_loaded('gd') || !is_file($absolutePath)) { return false; }

    $info = @getimagesize($absolutePath);
    if (!$info) { return false; }
    [$width, $height, $type] = $info;

    // Animated GIFs would lose their animation if re-encoded by GD.
    if ($type === IMAGETYPE_GIF) { return false; }

    /*
     * Refuse to open an image that will not fit in memory.
     *
     * File size is a poor guide here: a 3MB JPEG can be 6000x4000, and GD holds
     * every pixel uncompressed at 4 bytes, so opening it needs ~92MB regardless
     * of how small the compressed file is. A 48MP photo needs ~183MB. Without
     * this check, a customer or admin uploading a modern camera shot on a host
     * with a 128M memory_limit gets a fatal error and a white screen.
     *
     * ~2.6x the raw pixel budget covers the source bitmap, the resized copy and
     * PHP's own overhead. If it will not fit, the upload is kept exactly as it
     * arrived — a large file on disk beats a broken upload.
     */
    $limitBytes = _phpMemoryLimitBytes();
    if ($limitBytes > 0) {
        $needed = (int)($width * $height * 4 * 2.6);
        $headroom = $limitBytes - memory_get_usage(true);
        if ($needed > $headroom) {
            error_log(sprintf(
                'optimizeUploadedImage: skipped %s (%dx%d needs ~%dMB, only ~%dMB free)',
                basename($absolutePath), $width, $height,
                (int)round($needed / 1048576), (int)round($headroom / 1048576)
            ));
            return false;
        }
    }

    $needsResize = ($width > $maxDimension || $height > $maxDimension);

    /* A PNG that already fits is left exactly as it is.
       ────────────────────────────────────────────────────────────────────────
       I briefly made this re-encode large PNGs instead of skipping them, on the
       reasoning that a 2.4MB garment shot is worth compressing. Then I timed it,
       on a real 1024x1536 photograph from this shop:

           re-encode as PNG   3937 ms   1.37MB -> 1.14MB   (17% smaller)
           WebP twin          1053 ms   1.37MB -> 0.14MB   (90% smaller)

       Four seconds of CPU for 17%, while the twin beside it does 90% in one.
       PNG is lossless: re-encoding a photograph in it cannot win, whatever the
       compression level. And that cost lands in the worst possible place — the
       owner sits on a spinning Save button, once per photograph, six or seven
       times for one product, on shared hosting slower than the machine measured
       above. It made adding a product feel broken.

       The .webp copy is what browsers are actually served through srcset, so the
       PNG's own size costs disk and backup space, not page speed. That is not
       worth four seconds of anybody's morning.

       Oversized PNGs still go through: a resize is real work with a real result,
       and it is the one case where the pixels genuinely have to be rewritten. */
    if (!$needsResize && $type === IMAGETYPE_PNG) {
        return false;
    }

    switch ($type) {
        case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($absolutePath); break;
        case IMAGETYPE_PNG:  $src = @imagecreatefrompng($absolutePath);  break;
        case IMAGETYPE_WEBP: $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($absolutePath) : null; break;
        default: return false;
    }
    if (!$src) { return false; }

    if ($needsResize) {
        $scale     = $maxDimension / max($width, $height);
        $newWidth  = max(1, (int)round($width * $scale));
        $newHeight = max(1, (int)round($height * $scale));

        $dst = imagecreatetruecolor($newWidth, $newHeight);
        if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
            // Keep transparency rather than filling it black.
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($src);
        $src = $dst;

        /*
         * Gentle sharpening, applied ONLY when the image was actually scaled down.
         *
         * Any resampling softens fine detail — measured on a 3000x4000 photo,
         * downscaling to 2000px cost 1.3% of edge sharpness at the 1400px size the
         * lightbox displays. A light unsharp mask more than recovers that (+4.3%),
         * for about 110KB more on a 570KB file. Deliberately gentle: stronger
         * settings score higher on the metric but start to look artificial on
         * fabric, which is the opposite of what a fashion photo needs.
         *
         * Not applied when the image is merely re-compressed — there is nothing to
         * recover there, and sharpening an unscaled photo only adds artefacts.
         */
        $centre = 8 / 0.6;
        @imageconvolution($src, [[-1, -1, -1], [-1, $centre + 8, -1], [-1, -1, -1]], $centre, 0);
    }

    // Write to a temp file first, so a failure never damages the upload.
    $tmp = $absolutePath . '.opt';
    $ok  = false;
    switch ($type) {
        case IMAGETYPE_JPEG: $ok = imagejpeg($src, $tmp, $jpegQuality); break;
        case IMAGETYPE_PNG:  $ok = imagepng($src, $tmp, 8); break;
        case IMAGETYPE_WEBP: $ok = function_exists('imagewebp') && imagewebp($src, $tmp, $jpegQuality); break;
    }
    imagedestroy($src);

    if (!$ok || !is_file($tmp)) { @unlink($tmp); return false; }

    // Only accept the new file if it is genuinely smaller.
    if (filesize($tmp) >= filesize($absolutePath)) { @unlink($tmp); return false; }

    return @rename($tmp, $absolutePath);
}

/**
 * Is this a postcode the shop can actually deliver to?
 *
 * Replaces a hardcoded UK-only regex in actions/checkout_action.php that
 * rejected every Indian PIN with "Please enter a valid UK delivery postcode" —
 * blocking every domestic order outright. A leftover from the site's UK origins,
 * the same one that broke the shipping calculation.
 *
 * Deliberately permissive for anywhere abroad. The shop ships worldwide and
 * postal formats vary enormously (Ireland's Eircode, Canada's K1A 0B1, Japan's
 * 100-0001), so anything outside the home country only has to look like a postal
 * code rather than match a specific pattern. Guessing at foreign formats is how
 * a checkout ends up refusing real customers.
 */
function isDeliverablePostcode(?string $postcode, ?PDO $pdo = null): bool {
    $pc = strtoupper(trim((string)$postcode));
    if ($pc === '') { return false; }

    // Domestic? Then it must match the home country's real format.
    if (detectShippingZone($pc, $pdo) === 'domestic') { return true; }

    // Not domestic, and the shop does not ship abroad? Then it is not a foreign
    // address, it is a mistyped Indian one.
    //
    // Without this, "38001" — a six-digit PIN with a digit missed — failed the
    // domestic pattern, fell through to the international branch below, was
    // accepted, and priced at the ₹2,500 international rate. The customer never
    // chose international delivery and could not have: checkout hides that
    // option entirely when shipsInternationally() is false. One slipped
    // keystroke turned a ₹99 delivery into ₹2,500, on a shop that would then
    // have had to refuse the order anyway.
    if ($pdo instanceof PDO && !shipsInternationally($pdo)) { return false; }

    // Otherwise accept any plausible international postal code: 3–10 characters
    // of letters/digits, optionally broken by one space or hyphen.
    $compact = preg_replace('/[\s\-]+/', '', $pc);
    return (bool)preg_match('/^[A-Z0-9]{3,10}$/', $compact);
}

/** memory_limit in bytes; 0 when unlimited. Handles the K/M/G shorthand. */
function _phpMemoryLimitBytes(): int {
    $raw = trim((string)ini_get('memory_limit'));
    if ($raw === '' || $raw === '-1') { return 0; }
    $unit = strtolower(substr($raw, -1));
    $n = (int)$raw;
    switch ($unit) {
        case 'g': return $n * 1024 * 1024 * 1024;
        case 'm': return $n * 1024 * 1024;
        case 'k': return $n * 1024;
    }
    return $n;
}

/* ═══════════════════════════════════════════════════════════════════════
   Category default GST — including the price-dependent apparel slab
   ───────────────────────────────────────────────────────────────────────
   Indian apparel is not one rate. Below the per-piece threshold it is 5%,
   above it 18%, so a collection holding both a ₹1,800 kurti and a ₹4,200
   suit cannot be described by a single number typed into a box.

   "Apparel – Auto by Price" is stored as a sentinel in the existing
   DECIMAL(5,2) column rather than in a new one, so nothing needs migrating
   on live before the setting can be used. -1 is safe as a marker: it is a
   legal value for the column and can never collide with a real slab, all
   of which are zero or positive.
   ═══════════════════════════════════════════════════════════════════════ */
if (!defined('CATEGORY_GST_AUTO')) { define('CATEGORY_GST_AUTO', -1.0); }
/* A deliberate 0% needs its own marker.
   0.00 is the column default, so it already means "nothing chosen here, ask my
   parent". It therefore cannot ALSO mean "this collection is zero-rated" — the
   two are opposite instructions written the same way. -2 says zero on purpose. */
if (!defined('CATEGORY_GST_ZERO')) { define('CATEGORY_GST_ZERO', -2.0); }
if (!defined('APPAREL_GST_THRESHOLD')) { define('APPAREL_GST_THRESHOLD', 2500.0); }

/**
 * The slabs offered on the Collection form. Value => label.
 *
 * The apparel option stores 0 — the column's own default — not the -1 marker.
 * That matters: opening a sub-collection and pressing Save would otherwise
 * write -1 into a row that had nothing set, and -1 counts as "decided here",
 * which stops the walk that lets a sub-collection inherit its parent's rate.
 * The owner would have changed nothing on the form and silently detached the
 * collection from its parent.
 *
 * Nothing is lost by using 0: an unset rate already resolves to the apparel
 * slabs (productGstRateFor), so "leave it alone" and "choose apparel" are the
 * same instruction and are now written the same way. CATEGORY_GST_AUTO stays
 * recognised for any row that already holds it.
 */
function categoryGstOptions(): array {
    return [
        '0' => 'Apparel – Auto by Price',
        (string)CATEGORY_GST_ZERO => '0%',
        '0.25' => '0.25%',
        '3'    => '3%',
        '5'    => '5%',
        '12'   => '12%',
        '18'   => '18%',
        '28'   => '28%',
    ];
}

/** True when this stored value is the auto-by-price marker. */
function categoryGstIsAuto($stored): bool {
    if ($stored === null || $stored === '') { return false; }
    return abs((float)$stored - CATEGORY_GST_AUTO) < 0.005;
}

/**
 * Has a rate actually been chosen for this collection?
 *
 * 0.00 is the column default and has always meant "nothing set here, look at
 * the parent", so it cannot also mean a deliberate 0%. The auto marker DOES
 * count as set — without this the inheritance walk would step straight past a
 * collection configured for apparel and inherit from its parent instead.
 */
function categoryGstIsSet($stored): bool {
    if ($stored === null || $stored === '') { return false; }
    $v = (float)$stored;
    return $v > 0 || categoryGstIsAuto($stored) || abs($v - CATEGORY_GST_ZERO) < 0.005;
}

/** Apparel slab for one garment at this selling price, per piece. */
function apparelGstRateForPrice(float $sellingPrice): float {
    return $sellingPrice > APPAREL_GST_THRESHOLD ? 18.0 : 5.0;
}

/**
 * The rate a product should carry, given its collection's setting and its own
 * selling price. Null means the collection sets no rate, so keep what the
 * product already has.
 */
function resolveCategoryGstRate($stored, float $sellingPrice): ?float {
    if (!categoryGstIsSet($stored)) { return null; }
    if (categoryGstIsAuto($stored)) { return apparelGstRateForPrice($sellingPrice); }
    if (abs((float)$stored - CATEGORY_GST_ZERO) < 0.005) { return 0.0; }
    return (float)$stored;
}

/**
 * The rate for a product once the whole collection tree has been searched.
 *
 * $found is the first collection walking up from the product that had anything
 * set, or null if none of them did — which is the state every shop starts in,
 * because the column defaults to 0.00 and nobody has opened the Collection form
 * yet. Falling back to the apparel slabs there means the field does not have to
 * be filled in at all for the common case: a shop selling clothes gets the
 * correct clothing rate by default, and the dropdown exists to say otherwise.
 */
function productGstRateFor($found, float $sellingPrice): float {
    $resolved = resolveCategoryGstRate($found, $sellingPrice);
    return $resolved ?? apparelGstRateForPrice($sellingPrice);
}

/**
 * Climb the collection tree until a collection states a tax treatment.
 *
 * Lifted out of admin/product_form.php so the checkout can walk the same tree
 * the product form does. Bounded at 20 hops, so a mis-linked tree cannot loop.
 *
 * categoryGstIsSet(), not "> 0": the Apparel – Auto by Price marker is stored
 * as 0, so a greater-than test would walk straight past a collection that HAS
 * been given a treatment and inherit from its parent instead.
 */
function categoryTaxInherited(?PDO $pdo, ?int $categoryId): ?array
{
    if (!$pdo instanceof PDO || !$categoryId) { return null; }
    try {
        $st = $pdo->prepare("SELECT parent_id, default_hsn_code, default_gst_rate FROM categories WHERE id = :id");
        $cid = (int)$categoryId;
        for ($hop = 0; $cid > 0 && $hop < 20; $hop++) {
            $st->execute([':id' => $cid]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) { return null; }
            if (trim((string)($row['default_hsn_code'] ?? '')) !== ''
                || categoryGstIsSet($row['default_gst_rate'] ?? null)) {
                return $row;
            }
            $cid = (int)($row['parent_id'] ?? 0);
        }
    } catch (PDOException $e) {
        return null;   // tax columns absent on this install
    }
    return null;
}

/**
 * The tax treatment to freeze onto an order line.
 *
 * WHY THIS EXISTS: products.gst_rate is materialised only when a product is
 * SAVED through the admin form. Anything created before the tax feature
 * existed — or imported, or seeded — still carries the column's default of 0,
 * and the checkout was reading that column raw. Every collection in the shop
 * is set to "Apparel – Auto by Price", which resolves to 5% below ₹2,500, yet
 * every order was being recorded at 0% and every invoice printed no tax at
 * all. Nothing on any screen said so, because the admin form shows the rate it
 * would resolve rather than the rate stored.
 *
 * Resolving here means the rate an order freezes is correct whether or not the
 * product has ever been re-saved. It is still frozen at order time, so a later
 * change to a collection's rate cannot rewrite an invoice already issued.
 *
 * An owner who has ticked OFF "use collection tax" has stated a rate by hand,
 * and that is honoured exactly as typed.
 *
 * @return array{gst_rate: float, hsn_code: string}
 */
function productTaxSnapshot(?PDO $pdo, array $product, ?float $sellingPrice = null): array
{
    $price = $sellingPrice ?? (float)($product['price'] ?? 0);
    $ownHsn = trim((string)($product['hsn_code'] ?? ''));

    // Explicitly overridden on the product: take it as typed.
    if (array_key_exists('use_category_tax', $product) && (int)$product['use_category_tax'] === 0) {
        return ['gst_rate' => (float)($product['gst_rate'] ?? 0), 'hsn_code' => $ownHsn];
    }

    $found = categoryTaxInherited($pdo, (int)($product['category_id'] ?? 0));
    $rate  = productGstRateFor($found['default_gst_rate'] ?? null, $price);
    $hsn   = trim((string)($found['default_hsn_code'] ?? ''));

    return [
        'gst_rate' => $rate,
        // The collection's code where it has one, else whatever the product
        // carries — an HSN typed on the product is still better than none.
        'hsn_code' => $hsn !== '' ? $hsn : $ownHsn,
    ];
}

/* ═══════════════════════════════════════════════════════════════════════
   Product photography must be 3:4
   ───────────────────────────────────────────────────────────────────────
   Every frame a product picture appears in is aspect-ratio 3/4 with
   object-fit: cover — the shop card, the product gallery, "similar
   products", the compact card and the mega-menu promo. cover crops
   whatever does not match, from the centre, silently.

   A real example from the live shop: a photograph uploaded at 1023x1537
   (2:3) lost 173px off the top and bottom — eleven per cent of its height,
   which on a full-length shot is the model's head and her shoes. Nothing
   warned anyone; it simply appeared cropped on the website afterwards.

   So the check happens at upload, where it can still be answered, rather
   than being discovered later on a live product page.
   ═══════════════════════════════════════════════════════════════════════ */
if (!defined('PRODUCT_IMAGE_RATIO')) { define('PRODUCT_IMAGE_RATIO', 3 / 4); }
/* A little tolerance, because export tools round. 0.005 accepts 1499x2000
   and 1500x1999 while still refusing 2:3 (0.667) and square (1.000). */
if (!defined('PRODUCT_IMAGE_RATIO_TOLERANCE')) { define('PRODUCT_IMAGE_RATIO_TOLERANCE', 0.005); }

/**
 * Why this file cannot be used as a product photograph — or null if it can.
 *
 * $tmpPath is the uploaded temp file; $shownName is what the owner called it,
 * used so a rejection names the picture they chose rather than a temp path.
 */
function productImageRatioError(string $tmpPath, string $shownName = ''): ?string
{
    $d = @getimagesize($tmpPath);
    if (!$d || $d[0] < 1 || $d[1] < 1) {
        return 'That file could not be read as an image.';
    }
    [$w, $h] = $d;
    $ratio = $w / $h;
    if (abs($ratio - PRODUCT_IMAGE_RATIO) <= PRODUCT_IMAGE_RATIO_TOLERANCE) {
        return null;
    }

    // Say what it IS, and what would be lost — a bare "wrong ratio" leaves the
    // owner to work out what to change.
    if ($ratio > PRODUCT_IMAGE_RATIO) {
        $keep = (int)round($h * PRODUCT_IMAGE_RATIO);
        $lost = $w - $keep;
        $where = number_format($lost) . 'px would be cut off the sides';
    } else {
        $keep = (int)round($w / PRODUCT_IMAGE_RATIO);
        $lost = $h - $keep;
        $where = number_format($lost) . 'px would be cut off the top and bottom';
    }

    $label = $shownName !== '' ? '"' . htmlspecialchars($shownName) . '" is ' : 'That image is ';
    return $label . $w . '×' . $h . 'px, which is not 3:4 — ' . $where
         . '. Product photographs must be 3:4 portrait (1500×2000px). Re-export it and try again.';
}

/* ── The colour master list ───────────────────────────────────────────────────
 * Colours are added in the Color tab (admin/attributes.php, attr_type='color')
 * and only chosen in the product form. Before this, the product form offered
 * the master list as a <datalist> — a suggestion, not a constraint — so any
 * string could be typed and saved into products.color, products.color_way or
 * product_colors.color_name.
 *
 * The shop's colour filter is built by unioning those three columns
 * (pages/shop.php), so every typo became its own checkbox matching one product.
 * The local database had 16 colours in use against 11 in the master, including
 * 'ff' and 'pink'.
 *
 * These three helpers are the single place that answers "is this a real
 * colour?", so the form, the colour-variant handler and the reconciliation
 * screen cannot drift apart on the answer.
 *
 * Note what is NOT changed: product_colors.color_name stays a VARCHAR. Sizes,
 * stock, images and price overrides all hang off product_colors.id, and an
 * order records its own copy in order_items.variant_name — so constraining the
 * label touches none of them, and a colour can still be renamed later without
 * disturbing a single variant or a single past order.
 */
function dievonMasterColors(PDO $pdo): array {
    // One implementation, in dievonMasterList(). Kept as a name because the
    // colour call sites read better for it, not as a second copy of the rule.
    return dievonMasterList($pdo, 'color');
}

/**
 * The master's own spelling of a colour, or null if it is not on the list.
 *
 * Matched case- and space-insensitively so 'navy' and ' Navy ' both resolve to
 * the master's 'Navy' and save that way. Without this the list would fill with
 * the same colour in three casings, which is the drift this is meant to stop.
 */
function dievonCanonicalColor(PDO $pdo, string $name): ?string {
    return dievonCanonicalAttribute($pdo, 'color', $name);
}

/**
 * Whether a colour may be saved.
 *
 * An EMPTY master list means the Color tab has never been filled in — on a
 * fresh install, or on a database where the table is missing. Refusing every
 * colour there would lock the shopkeeper out of their own product form with no
 * way to recover, so an empty list is treated as "not configured yet" and lets
 * the value through. The moment one colour exists, the list is enforced.
 */
function dievonIsMasterColor(PDO $pdo, string $name): bool {
    return dievonIsOnMasterList($pdo, 'color', $name);
}

/**
 * Every colour a product actually carries that is NOT on the master list, with
 * the products using it. Powers the reconciliation panel in the Color tab.
 *
 * Reads whatever database it is running in and hardcodes no colour names —
 * local's strays ('ff', 'pink', the QA colours) are testing junk that will not
 * exist on the live shop, and live will have strays of its own. Each site is
 * reconciled against its own data.
 */
function dievonStrayColors(PDO $pdo): array {
    $master = array_map(
        static fn($n) => strtolower(trim((string)$n)),
        array_keys(dievonMasterColors($pdo))
    );
    $found = [];

    $sources = [
        ['sql' => "SELECT id, name, color      AS c FROM products WHERE color      IS NOT NULL AND color      <> ''", 'where' => 'Colour'],
        ['sql' => "SELECT id, name, color_way  AS c FROM products WHERE color_way  IS NOT NULL AND color_way  <> ''", 'where' => 'Color Way'],
        ['sql' => "SELECT p.id, p.name, pc.color_name AS c
                     FROM product_colors pc JOIN products p ON p.id = pc.product_id
                    WHERE pc.color_name IS NOT NULL AND pc.color_name <> ''", 'where' => 'Colour variant'],
    ];

    foreach ($sources as $src) {
        try { $rows = $pdo->query($src['sql']); } catch (PDOException $e) { continue; }
        foreach ($rows as $r) {
            /* SPLIT first. Color Way can hold a list — "Black,Green" is one
               garment that IS black and green — and comparing the whole string
               against the colour list reported that valid pair as a single
               unknown colour called "Black,Green". Every multi-colour garment
               was being listed as a problem to fix, which is the opposite of
               true: both halves are on the list, which is the only way they
               could have been ticked in the first place.

               A colour variant is always one colour, so splitting is a no-op
               there and this stays one loop for all three sources. */
            foreach (dievonColorWayList((string)$r['c']) as $value) {
                if ($value === '' || in_array(strtolower($value), $master, true)) { continue; }
                $key = strtolower($value);
                if (!isset($found[$key])) {
                    $found[$key] = ['value' => $value, 'fields' => [], 'products' => []];
                }
                $found[$key]['fields'][$src['where']] = true;
                $found[$key]['products'][(int)$r['id']] = (string)$r['name'];
            }
        }
    }

    ksort($found);
    return array_values(array_map(static function (array $f) {
        $f['fields'] = array_keys($f['fields']);
        return $f;
    }, $found));
}

/**
 * The <option> list for a colour picker, with the current value preserved.
 *
 * The point of the second argument: a product saved before the Color tab was
 * enforced may hold a colour that is no longer on the list. Rendering only the
 * master list would make the browser select the FIRST option instead, so simply
 * opening that product and pressing Save would silently repaint it a different
 * colour. Instead its own value is kept, selected, and labelled — the shopkeeper
 * sees what it is and chooses deliberately.
 */
function dievonColorOptions(PDO $pdo, string $current = '', string $blankLabel = '— Select a colour —'): string {
    return dievonAttributeOptions($pdo, 'color', $current, $blankLabel);
}

/* ── A product that IS two colours ────────────────────────────────────────────
 * Two different ideas share the word "colour" in this shop, and they are not
 * interchangeable:
 *
 *   product_colors  — the garment COMES IN purple or gold. The shopper picks
 *                     one. Each has its own photographs, SKU, price and
 *                     per-size stock. Untouched by anything below.
 *
 *   products.color_way — the garment IS purple and gold. One item, one stock
 *                     number, woven in both.
 *
 * The second could not be expressed. Typing "Purple, Gold" into Color Way stored
 * one string, and the shop's filter compares whole strings — so it became its own
 * filter entry reading "Purple, Gold", and a shopper filtering Purple never found
 * the garment at all.
 *
 * Color Way now holds a comma-separated list of master colours. No new table, so
 * nothing has to be run on the live database — and the filter splits the value
 * when building its list and matches with FIND_IN_SET, so Purple and Gold each
 * appear once and either one finds the product.
 *
 * Stored WITHOUT a space after the comma, because FIND_IN_SET splits on the comma
 * exactly and does not trim: 'Purple,Bone Ivory' matches 'Bone Ivory', while
 * 'Purple, Bone Ivory' would look for ' Bone Ivory' and miss. Display adds the
 * space back; the column never carries it. Colour names may not contain a comma
 * (enforced in the Color tab) or the delimiter would be ambiguous.
 */
function dievonColorWayList(?string $stored): array {
    $out = [];
    foreach (explode(',', (string)$stored) as $one) {
        $one = trim($one);
        if ($one !== '') { $out[] = $one; }
    }
    return $out;
}

/**
 * Turn what the form posted into the value for the column.
 *
 * Every entry is checked against the master list and stored in the master's own
 * spelling, so the same rule that governs the single-colour fields governs this
 * one — anything not on the list is dropped rather than saved. Order is the
 * order chosen, so "Purple, Gold" and "Gold, Purple" stay distinguishable to a
 * reader while matching identically in the filter.
 */
function dievonNormaliseColorWay(PDO $pdo, $posted): string {
    $values = is_array($posted) ? $posted : dievonColorWayList((string)$posted);
    $clean  = [];
    foreach ($values as $v) {
        $canonical = dievonCanonicalColor($pdo, (string)$v);
        if ($canonical === null) {
            /* Not on the master list. Kept only if it is what the product
               already had — a value saved before the list was enforced must not
               be silently dropped by an edit that never touched it. The
               reconciliation panel in the Color tab is where those get fixed. */
            $canonical = trim((string)$v);
            if ($canonical === '' || !dievonMasterColors($pdo)) { continue; }
        }
        if (!in_array($canonical, $clean, true)) { $clean[] = $canonical; }
    }

    /* Never hand the column more than it can hold.
       ────────────────────────────────────────────────────────────────────────
       MySQL truncates a too-long value silently, and it would cut mid-name —
       leaving a final "colour" that is half a word and matches nothing in the
       filter. Dropping whole colours off the end instead keeps every value that
       survives a real, findable one. config/db.php widens the column to 255 on
       first run; this is the belt to that pair of braces, and it is what stops a
       shop with very long colour names losing the end of its list quietly. */
    $joined = implode(',', $clean);
    while (strlen($joined) > 255 && count($clean) > 1) {
        array_pop($clean);
        $joined = implode(',', $clean);
    }
    return $joined;
}

/** "Purple,Gold" as a person reads it. */
function dievonColorWayLabel(?string $stored): string {
    return implode(', ', dievonColorWayList($stored));
}

/**
 * The Color Way picker: one checkbox per master colour.
 *
 * Checkboxes rather than a <select multiple>, which on every platform hides how
 * many are chosen behind a scroll and needs ctrl-click to add a second — the
 * whole point here is that picking two is ordinary, not an expert gesture.
 */
function dievonColorWayChecklist(PDO $pdo, ?string $current): string {
    $chosen = array_map(
        static fn($c) => strtolower(trim($c)),
        dievonColorWayList($current)
    );
    $master = array_keys(dievonMasterColors($pdo));

    /* A value the product already holds that is no longer on the list is shown
       and stays ticked, exactly as the single-colour pickers do it — otherwise
       opening the product and saving would quietly drop it. */
    foreach (dievonColorWayList($current) as $held) {
        $known = false;
        foreach ($master as $m) { if (strcasecmp($m, $held) === 0) { $known = true; break; } }
        if (!$known) { array_unshift($master, $held . "\0stray"); }
    }

    if (!$master) {
        return '<p style="margin:0; font-size:12px; color:var(--text-muted);">'
             . 'No colours yet — add them in the Color tab first.</p>';
    }

    /* A search box above the ticks, because this list only grows. Twelve colours
       are fine to scan; fifty are not, and the shop is heading that way. It
       filters what is shown and never touches what is TICKED — so typing to find
       one colour cannot silently drop another you already chose.

       Progressive enhancement on purpose: without JavaScript this input simply
       does nothing and every checkbox is still there to tick. The product form
       is where the catalogue gets built, so it must not depend on a script
       loading to be usable. */
    /* Classes, not inline styles — .dv-colour-picker in admin/assets/css/style.css,
       which matches the accent and size the rest of the panel's checkboxes use. */
    /* The same dropdown the other five attributes wear, for the same reason and
       by the same rules: rendered OPEN with the toggle hidden, collapsed only
       once dvMultiSelect() marks it, so a failed script leaves a usable list
       rather than an unreachable one. Colour was left out when the others were
       converted, which made it the one field on the screen behaving differently
       from its four neighbours. */
    $summary = $chosen ? implode(', ', dievonColorWayList($current)) : 'Select colours';
    $html  = '<div class="dv-multiselect" data-multiselect="colours">';
    $html .= '<button type="button" class="dv-multiselect-toggle" aria-expanded="false">'
           . '<span class="dv-multiselect-value">' . htmlspecialchars($summary) . '</span>'
           . '<span class="dv-multiselect-caret" aria-hidden="true"></span></button>';
    $html .= '<div class="dv-multiselect-panel">';
    $html .= '<input type="search" class="form-control dv-colour-search" data-colour-search="way"'
           . ' placeholder="Search colours&hellip;" autocomplete="off">';
    $html .= '<div class="dv-colour-picker" data-colour-search-target="way">';
    foreach ($master as $name) {
        $stray = str_contains($name, "\0stray");
        $name  = str_replace("\0stray", '', $name);
        $on    = in_array(strtolower(trim($name)), $chosen, true);
        $html .= '<label>'
               . '<input type="checkbox" name="color_way[]" value="' . htmlspecialchars($name) . '"'
               . ($on ? ' checked' : '') . '>'
               . '<span>' . htmlspecialchars($name)
               . ($stray ? ' <em>(not in Color tab)</em>' : '') . '</span>'
               . '</label>';
    }
    return $html . '</div></div></div>';   // picker, panel, wrapper
}

/* ── A product video that the browser will actually load ──────────────────────
 * video_url holds either an uploaded filename or an absolute URL, and the three
 * places that render it each rebuilt that decision by hand. One of them dropped
 * a plain http:// address straight into an https:// page, where the browser
 * blocks it as mixed content and the player reports "No video with supported
 * format and MIME type found" — which reads as a broken file rather than a
 * blocked request, so it is easy to chase the wrong thing. Seen on this shop's
 * own products, which point at an http:// sample video.
 *
 * The upgrade to https is applied ONLY when the page itself is served over
 * https. On a plain-http page nothing is blocked, and rewriting there could
 * break a host that genuinely has no certificate — so the rule can only ever
 * help, never take away a video that was working.
 *
 * YouTube links are not this function's business: those are turned into an
 * embed by the caller and never reach a <video> element.
 */
function dievonVideoSrc(?string $raw): string {
    $url = trim((string)$raw);
    if ($url === '') { return ''; }

    $isAbsolute = str_starts_with($url, 'http://') || str_starts_with($url, 'https://');
    if (!$isAbsolute) {
        return SITE_URL . '/uploads/products/' . $url;
    }

    $pageIsHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');

    if ($pageIsHttps && str_starts_with($url, 'http://')) {
        return 'https://' . substr($url, 7);
    }
    return $url;
}

/* ── Managed attribute lists, beyond colour ───────────────────────────────────
 * Sleeve, Neck and Pattern were free-text boxes with a datalist of suggestions,
 * and the suggestions were built FROM the values already saved — so the first
 * typo became a suggestion for the next product and the mess compounded. Live
 * showed exactly that: "3/4 Sleeves", "Three-quarter Sleeve" and "Three-quarter
 * Sleeves" as three separate filters for one kind of sleeve, a fabric
 * composition sitting in the Neck filter, and a neck sitting in Pattern.
 *
 * Colour is the only one of the six that is clean, and the only one with a
 * managed list. So these three join it, using the same product_attributes table
 * (which has always taken any attr_type) and the same picker, guard and
 * reconciliation screen.
 *
 * Everything below is the colour logic with the type lifted out of it. The
 * dievon*Color* functions further up are now thin wrappers on these, so every
 * existing colour call site keeps working untouched.
 */
const DIEVON_ATTR_TYPES = [
    'color'   => ['label' => 'Colour',  'plural' => 'Colours',  'tab' => 'colors',
                  'columns' => ['products.color', 'products.color_way', 'product_colors.color_name']],
    'sleeve'  => ['label' => 'Sleeve',  'plural' => 'Sleeves',  'tab' => 'sleeves',
                  'columns' => ['products.sleeve']],
    'neck'    => ['label' => 'Neckline', 'plural' => 'Necklines', 'tab' => 'necks',
                  'columns' => ['products.neck']],
    'pattern' => ['label' => 'Pattern', 'plural' => 'Patterns', 'tab' => 'patterns',
                  'columns' => ['products.pattern']],
    /* Fabric and Occasion were left free text at first because both looked
       clean — 3 fabrics, 12 occasions, no duplicates. Two things changed that.

       Occasion does not merely feed a filter: every distinct value becomes a
       TILE in Shop by Occasion on the homepage (pages/home.php, "SELECT occasion
       FROM products"). A typo there is not a stray checkbox in a sidebar, it is a
       wrongly-named tile on the front page of the shop. That is a stronger
       reason to manage it than any of the three managed before it, and it was
       missed when the filter alone was being looked at.

       Fabric is the duller case: clean only because the catalogue is young, and
       exactly the field that attracts "Cotton", "cotton", "100% Cotton" and
       "Pure Cotton" as products are added on different days. That is not a
       prediction — it is what already happened to Sleeve, which arrived at three
       names for one thing.

       And with these two, every value a shopper can filter by is managed. Three
       pickers beside two free-text boxes is its own small confusion. */
    'fabric'   => ['label' => 'Fabric',   'plural' => 'Fabrics',   'tab' => 'fabrics',
                   'columns' => ['products.fabric']],
    'occasion' => ['label' => 'Occasion', 'plural' => 'Occasions', 'tab' => 'occasions',
                   'columns' => ['products.occasion']],
];

/** Every value on a managed list, in its own spelling. */
function dievonMasterList(PDO $pdo, string $type): array {
    static $cache = [];
    if (isset($cache[$type])) { return $cache[$type]; }
    $cache[$type] = [];
    try {
        $st = $pdo->prepare("SELECT name, code FROM product_attributes
                              WHERE attr_type = :t AND name IS NOT NULL AND name <> ''
                              ORDER BY name ASC, id ASC");
        $st->execute(['t' => $type]);
        foreach ($st as $r) {
            $name = trim((string)$r['name']);
            if ($name !== '') { $cache[$type][$name] = trim((string)($r['code'] ?? '')); }
        }
    } catch (PDOException $e) {
        /* No table yet. Callers treat an empty list as "not configured" and let
           values through, so a fresh install is never locked out of its own
           product form. */
    }
    return $cache[$type];
}

/** The list's own spelling of a value, or null when it is not on the list. */
function dievonCanonicalAttribute(PDO $pdo, string $type, string $name): ?string {
    $needle = strtolower(trim($name));
    if ($needle === '') { return null; }
    foreach (array_keys(dievonMasterList($pdo, $type)) as $known) {
        if (strtolower(trim($known)) === $needle) { return $known; }
    }
    return null;
}

/** Whether a value may be saved. An empty list means "not set up yet". */
function dievonIsOnMasterList(PDO $pdo, string $type, string $name): bool {
    if (trim($name) === '') { return true; }
    if (!dievonMasterList($pdo, $type)) { return true; }
    return dievonCanonicalAttribute($pdo, $type, $name) !== null;
}

/** <option> list for a picker, keeping a value the product already holds. */
function dievonAttributeOptions(PDO $pdo, string $type, string $current = '', string $blankLabel = ''): string {
    $label   = $blankLabel !== '' ? $blankLabel
             : '— Select a ' . strtolower(DIEVON_ATTR_TYPES[$type]['label'] ?? $type) . ' —';
    $current = trim($current);
    $html    = '<option value="">' . htmlspecialchars($label) . '</option>';
    $matched = false;

    foreach (array_keys(dievonMasterList($pdo, $type)) as $name) {
        $on = ($current !== '' && strcasecmp(trim($name), $current) === 0);
        if ($on) { $matched = true; }
        $html .= '<option value="' . htmlspecialchars($name) . '"' . ($on ? ' selected' : '') . '>'
               . htmlspecialchars($name) . '</option>';
    }
    /* A value saved before the list existed stays, selected and labelled, so
       opening a product and saving cannot silently retag it. */
    if ($current !== '' && !$matched) {
        /* Names the list, so the note says where to go and not merely that
           something is wrong: "not on the Colour list", "not on the Sleeve
           list". The colour picker used to say "not in Color tab"; now that
           there are four lists, each says its own. */
        $listName = DIEVON_ATTR_TYPES[$type]['label'] ?? ucfirst($type);
        $html = '<option value="' . htmlspecialchars($current) . '" selected>'
              . htmlspecialchars($current) . ' — not on the ' . htmlspecialchars($listName) . ' list</option>'
              . $html;
    }
    return $html;
}

/**
 * The separator a multi-value attribute column is stored with.
 *
 * Two conventions, and the difference is not cosmetic. "3/4 Sleeve" contains a
 * SLASH, so a slash-separated sleeve column would split into "3" and "4 Sleeve"
 * the first time anything read it — silently, and on data already saved. Every
 * field but occasion therefore follows the comma convention colour_way has used
 * all along, and is matched with FIND_IN_SET the same way.
 *
 * Occasion keeps " / " because that is what is already in the column and what
 * OCCASION_MATCH_SQL and dievonSplitOccasions() already parse; its values are
 * plain words with no slash in them.
 */
function dievonAttrListSeparator(string $type): string {
    return $type === 'occasion' ? ' / ' : ', ';
}

/** The individual values inside a multi-value attribute column. */
function dievonSplitAttrList(string $type, ?string $raw): array {
    if ($type === 'occasion') { return dievonSplitOccasions($raw); }
    // Comma only — never slash. See dievonAttrListSeparator().
    $out = [];
    foreach (explode(',', (string)$raw) as $one) {
        $one = trim($one);
        if ($one !== '') { $out[] = $one; }
    }
    return $out;
}

/**
 * SQL that matches ONE value inside such a column, for any column.
 *
 * The comma-separated fields reuse colour's FIND_IN_SET approach, which cannot
 * mistake "Cotton" for part of "Cotton Blend" the way a bare LIKE '%Cotton%'
 * would. Occasion has its own constant because its separator differs.
 */
function dievonAttrMatchSql(string $type, string $column, PDO $pdo, string $value): string {
    if ($type === 'occasion') {
        return OCCASION_MATCH_SQL . ' LIKE ' . $pdo->quote('%/' . $value . '/%');
    }
    return 'FIND_IN_SET(' . $pdo->quote($value) . ", REPLACE($column, ', ', ',')) > 0";
}

/**
 * What the form posted, turned into the value for the column.
 *
 * Each entry goes through the same master-list check the single-value fields
 * use, so an off-list value is dropped on exactly the same terms — this simply
 * runs once per ticked box and rejoins them. Order is the order the list is in,
 * and duplicates cannot survive.
 *
 * A plain string still works: a save posted from an older cached copy of the
 * form is read as one value rather than lost.
 */
function dievonNormaliseAttrList(PDO $pdo, string $type, $posted, ?array $product = null, string $field = ''): string {
    if (!is_array($posted)) { $posted = ($posted === '' || $posted === null) ? [] : [$posted]; }
    $kept = [];
    foreach ($posted as $one) {
        $one = trim((string)$one);
        if ($one === '') { continue; }
        $canonical = dievonCanonicalAttribute($pdo, $type, $one);
        if ($canonical === null) {
            // List not configured yet — a fresh install must not be locked out.
            if (!dievonMasterList($pdo, $type)) { $canonical = $one; }
            else {
                /* Not on the list. Kept only if the product already held it, so
                   opening a product and saving cannot quietly retag it — the
                   same promise the single-value fields make. */
                $held = dievonSplitAttrList($type, (string)(($product ?? [])[$field] ?? ''));
                foreach ($held as $h) { if (strcasecmp($h, $one) === 0) { $canonical = $h; break; } }
                if ($canonical === null) { continue; }
            }
        }
        if (!in_array($canonical, $kept, true)) { $kept[] = $canonical; }
    }
    return implode(dievonAttrListSeparator($type), $kept);
}

/**
 * The picker itself: a search box over a scrollable list of ticks.
 *
 * This is dievonColorWayChecklist() generalised. Colour has had this control
 * all along and it is the right one — a native <select multiple> asks a shopkeeper
 * to Ctrl-click, which is a way to lose four selections by clicking a fifth.
 * Ticks cannot do that, they show every choice at once, and they need no
 * JavaScript to work.
 *
 * The search filters what is SHOWN and never what is TICKED, so typing to find
 * one value cannot drop another already chosen.
 */
function dievonAttributeChecklist(PDO $pdo, string $type, ?string $current, string $field): string {
    $chosen = array_map('mb_strtolower', array_map('trim', dievonSplitAttrList($type, $current)));
    $master = array_keys(dievonMasterList($pdo, $type));
    $label  = DIEVON_ATTR_TYPES[$type]['label'] ?? ucfirst($type);

    /* A value the product holds that is no longer on the list stays, and stays
       ticked — otherwise opening the product and saving would drop it. */
    foreach (dievonSplitAttrList($type, $current) as $held) {
        $known = false;
        foreach ($master as $m) { if (strcasecmp($m, $held) === 0) { $known = true; break; } }
        if (!$known) { array_unshift($master, $held . "\0stray"); }
    }

    if (!$master) {
        return '<p style="margin:0; font-size:12px; color:var(--text-muted);">'
             . 'No ' . htmlspecialchars(mb_strtolower($label)) . ' values yet — add them under Filters &amp; Attributes first.</p>';
    }

    /* A dropdown, but only once JavaScript says so.
       ────────────────────────────────────────────────────────────────────────
       The panel is rendered OPEN and the toggle is hidden until dvMultiSelect()
       marks the wrapper. With no script the shopkeeper still sees every tick and
       can still save — the product form is where the catalogue gets built, so it
       must not need a script to be usable. The collapsing is a convenience laid
       on top, not the thing holding it up. */
    $summary = $chosen ? implode(', ', dievonSplitAttrList($type, $current))
                       : 'Select ' . mb_strtolower($label);
    $html  = '<div class="dv-multiselect" data-multiselect="' . htmlspecialchars($field) . '">';
    $html .= '<button type="button" class="dv-multiselect-toggle" aria-expanded="false">'
           . '<span class="dv-multiselect-value">' . htmlspecialchars($summary) . '</span>'
           . '<span class="dv-multiselect-caret" aria-hidden="true"></span></button>';
    $html .= '<div class="dv-multiselect-panel">';
    $html .= '<input type="search" class="form-control dv-colour-search" data-colour-search="' . htmlspecialchars($field) . '"'
           . ' placeholder="Search ' . htmlspecialchars(mb_strtolower($label)) . '&hellip;" autocomplete="off">';
    $html .= '<div class="dv-colour-picker" data-colour-search-target="' . htmlspecialchars($field) . '">';
    foreach ($master as $name) {
        $stray = str_contains($name, "\0stray");
        $name  = str_replace("\0stray", '', $name);
        $on    = in_array(mb_strtolower(trim($name)), $chosen, true);
        $html .= '<label>'
               . '<input type="checkbox" name="' . htmlspecialchars($field) . '[]" value="' . htmlspecialchars($name) . '"'
               . ($on ? ' checked' : '') . '>'
               . '<span>' . htmlspecialchars($name)
               . ($stray ? ' <em>(not on the ' . htmlspecialchars($label) . ' list)</em>' : '') . '</span>'
               . '</label>';
    }
    return $html . '</div></div></div>';   // picker, panel, wrapper
}


/**
 * Values products carry that the list does not have, with the products using
 * them. Reads whichever database it runs in and hardcodes nothing, so a shop is
 * always reconciled against its own data.
 */
function dievonStrayAttributes(PDO $pdo, string $type): array {
    if ($type === 'color') { return dievonStrayColors($pdo); }   // colour reads three columns

    $column = DIEVON_ATTR_TYPES[$type]['columns'][0] ?? '';
    if (!str_starts_with($column, 'products.')) { return []; }
    $field = substr($column, strlen('products.'));
    if (!preg_match('/^[a-z_]+$/', $field)) { return []; }        // never interpolate anything else

    $master = array_map(
        static fn($n) => strtolower(trim((string)$n)),
        array_keys(dievonMasterList($pdo, $type))
    );

    $found = [];
    try {
        $rows = $pdo->query("SELECT id, name, `$field` AS v FROM products
                              WHERE `$field` IS NOT NULL AND `$field` <> ''");
    } catch (PDOException $e) { return []; }

    /* Judge each VALUE, not the whole column.
       ────────────────────────────────────────────────────────────────────────
       These columns hold lists. Compared whole, a garment correctly tagged
       "Cotton, Linen, Silk" was reported as a stray value in its own right —
       three perfectly listed fabrics accused of being an unknown one, and the
       owner invited to reconcile something that was never wrong. Occasion has
       held lists all along ("Casual / Everyday / Day Out"), so it has been
       reporting that false stray for as long as the panel has existed; the
       other four inherited it when the form gained multi-select.
       Split, only the parts that really are off-list are named. */
    foreach ($rows as $r) {
        foreach (dievonSplitAttrList($type, (string)$r['v']) as $value) {
            $value = trim($value);
            if ($value === '' || in_array(strtolower($value), $master, true)) { continue; }
            $key = strtolower($value);
            if (!isset($found[$key])) {
                $found[$key] = ['value' => $value, 'fields' => [DIEVON_ATTR_TYPES[$type]['label']], 'products' => []];
            }
            $found[$key]['products'][(int)$r['id']] = (string)$r['name'];
        }
    }
    ksort($found);
    return array_values($found);
}

/**
 * Every value products actually carry for one attribute, and which products
 * carry it. Keyed by the lowercased value.
 *
 * dievonStrayAttributes() answers the same question but only for values the
 * master list does NOT have, because its job is reconciliation. The attributes
 * screen needs the other half: for a value that IS on the list, who is using it?
 * Without that the Delete button is a guess. Deleting a listed value does work —
 * the row goes — but any product still tagged with it keeps the tag, so the
 * value reappears further down the same page under "in use but not on this
 * list". Nothing on screen said it would, so the delete looked broken when it
 * had in fact done exactly what it says.
 *
 * Values are split before counting for the same reason the stray reader splits
 * them: these columns hold lists, so a garment tagged "Cotton, Linen" is using
 * two listed fabrics, not one unknown one.
 */
/**
 * A loose key for one attribute value, used only to spot the SAME value spelled
 * two ways — never to decide what a product is.
 *
 * "Used by" matches the list's spelling against the product's, exactly. That is
 * the honest test, but on its own it is a trap: a list holding "Three-Quarter
 * Sleeve" while the garments say "Three-quarter Sleeves" gets told nothing uses
 * it, which is true of that string and false of the shopper's experience — the
 * chip is right there in the shop. Acting on it deletes the wrong row.
 *
 * Case, hyphens, slashes and a trailing plural are dropped, so those two collapse
 * together and the screen can say "nothing carries this exact spelling, but N
 * products carry <the other one>". It deliberately does NOT try to understand
 * that "3/4 Sleeves" and "Three-quarter Sleeves" mean the same thing; no string
 * rule can, and guessing there would merge values that are genuinely different.
 */
function dievonAttributeLooseKey(string $value): string {
    $v = mb_strtolower(trim($value));
    $v = str_replace(['/', '-', '_'], ' ', $v);
    $v = preg_replace('/[^a-z0-9 ]+/u', '', $v);
    $v = preg_replace('/\s+/', ' ', trim((string)$v));
    if ($v === '') { return ''; }
    $words = array_map(
        static fn($w) => (mb_strlen($w) > 3 && str_ends_with($w, 's')) ? substr($w, 0, -1) : $w,
        explode(' ', $v)
    );
    return implode(' ', $words);
}

function dievonAttributeUsage(PDO $pdo, string $type): array {
    $found = [];

    if ($type === 'color') {
        // Colour lives in three places; a colour is "in use" if any of them names it.
        $sources = [
            "SELECT id, name, color     AS c FROM products WHERE color     IS NOT NULL AND color     <> ''",
            "SELECT id, name, color_way AS c FROM products WHERE color_way IS NOT NULL AND color_way <> ''",
            "SELECT p.id, p.name, pc.color_name AS c
               FROM product_colors pc JOIN products p ON p.id = pc.product_id
              WHERE pc.color_name IS NOT NULL AND pc.color_name <> ''",
        ];
    } else {
        $column = DIEVON_ATTR_TYPES[$type]['columns'][0] ?? '';
        if (!str_starts_with($column, 'products.')) { return []; }
        $field = substr($column, strlen('products.'));
        if (!preg_match('/^[a-z_]+$/', $field)) { return []; }   // never interpolate anything else
        $sources = ["SELECT id, name, `$field` AS c FROM products
                      WHERE `$field` IS NOT NULL AND `$field` <> ''"];
    }

    foreach ($sources as $sql) {
        try { $rows = $pdo->query($sql); } catch (PDOException $e) { continue; }
        foreach ($rows as $r) {
            $parts = ($type === 'color')
                ? dievonColorWayList((string)$r['c'])
                : dievonSplitAttrList($type, (string)$r['c']);
            foreach ($parts as $value) {
                $value = trim($value);
                if ($value === '') { continue; }
                $key = strtolower($value);
                if (!isset($found[$key])) { $found[$key] = ['value' => $value, 'products' => []]; }
                $found[$key]['products'][(int)$r['id']] = (string)$r['name'];
            }
        }
    }
    return $found;
}
