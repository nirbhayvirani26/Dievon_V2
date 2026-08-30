<?php
// Fragment, not a page — same guard as the admin footer. Fetched directly this
// fatalled on an undefined SITE_URL and printed the install's absolute path.
if (!defined('SITE_URL')) { http_response_code(404); exit; }
?>
<!-- ══ Cart Sidebar ════════════════════════════════════════ -->
<div class="cart-overlay" id="cartOverlay" onclick="closeCart()"></div>
<aside class="cart-sidebar" id="cartSidebar">
    <div class="cart-header">
        <h2><i class="fa-solid fa-bag-shopping"></i> Shopping Bag</h2>
        <button class="btn-close-cart" onclick="closeCart()" aria-label="Close cart">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>

    <div class="cart-items" id="cartItems">
        <div class="cart-empty">
            <div class="cart-empty-icon">👜</div>
            <p>Your shopping bag is empty.</p>
        </div>
    </div>

    <div class="cart-footer" id="cartFooter" style="display:none;">
        <div class="cart-total-row">
            <span class="cart-total-label">Subtotal</span>
            <span class="cart-total-amount" id="cartTotal">₹0.00</span>
        </div>
        <a href="<?= SITE_URL ?>/cart" class="btn-luxury-outline" style="display: flex; justify-content: center; width: 100%; text-align: center; padding: 12px; margin-bottom: 10px; font-weight: 600; font-size: 12px; border-color: var(--color-primary); color: var(--color-primary);">
            View Shopping Cart Page
        </a>
        <a href="<?= SITE_URL ?>/checkout" class="btn-luxury">
            Proceed to Checkout
        </a>
        <button class="btn-clear-cart" onclick="clearCart()">
            <i class="fa-solid fa-trash"></i> Clear Bag
        </button>
    </div>

</aside>

<!-- ══ Compare Bar + Modal ══════════════════════════════════
     Sticky bar appears once a product is ticked; opens a side-by-side table.
     Capped at 3 products in the browser AND in actions/compare_action.php. -->
<div class="cmp-bar" id="compareBar">
  <div class="cmp-bar-inner">
    <div class="cmp-bar-items" id="compareBarItems"></div>
    <div class="cmp-bar-actions">
      <span class="cmp-count" id="compareCount">0 of 3 selected</span>
      <button type="button" class="btn-luxury cmp-open" id="compareOpenBtn" onclick="openCompare()" disabled>Compare</button>
      <button type="button" class="cmp-clear" onclick="clearCompare()">Clear</button>
    </div>
  </div>
</div>

<div class="cmp-overlay" id="compareOverlay" role="dialog" aria-modal="true" aria-labelledby="cmpTitle"
     onclick="if (event.target === this) closeCompare()">
  <div class="cmp-modal">
    <div class="cmp-head">
      <h2 id="cmpTitle">Compare Products</h2>
      <button type="button" class="cmp-close" onclick="closeCompare()" aria-label="Close comparison">&times;</button>
    </div>
    <div class="cmp-body" id="compareBody"><div class="cmp-loading">Loading…</div></div>
  </div>
</div>

<!-- ══ Quick View Modal ══════════════════════════════════════
     Lives in the shared footer so every listing page (shop, home, search)
     gets it without duplicating markup. Filled by openQuickView() from
     actions/quick_view_action.php — the button used to do
     window.location.href, which defeated the whole point of a "quick" view. -->
<div class="qv-overlay" id="quickViewOverlay" role="dialog" aria-modal="true" aria-labelledby="qvTitle"
     onclick="if (event.target === this) closeQuickView()">
  <div class="qv-modal">
    <button type="button" class="qv-close" onclick="closeQuickView()" aria-label="Close quick view">&times;</button>
    <div class="qv-body" id="qvBody">
      <div class="qv-loading">Loading…</div>
    </div>
  </div>
</div>

<!-- ══ Welcome / Join Popup ═════════════════════════════════ -->
<?php
/*
 * Shown once to a first-time visitor, a few seconds in.
 *
 * Deliberately NOT shown to someone who is already signed in, already on the
 * login/register/checkout pages, or who has dismissed it before — a popup that
 * reappears every visit, or interrupts someone mid-purchase, costs more sales
 * than it wins. The "seen" flag lives in localStorage so it survives across
 * sessions without needing a cookie banner.
 */
/* 'product' added to the list above. The rule this list encodes is "do not
   interrupt someone mid-purchase", and the product page is where the purchase is
   actually decided — a shopper reading a garment's fabric and fit is further into
   buying than one still on the cart page, which was already excluded.
   Observed: the modal covered the photograph and the size picker a few seconds
   after arrival, twice in a row, on the page the shopper came for.
   It still shows on the homepage, the shop grid, the journal and the rest, so the
   email capture keeps working where it costs nothing. */
$suppressWelcome = isset($_SESSION['customer_id'])
    || in_array(($path ?? ''), ['login', 'register', 'checkout', 'cart', 'product'], true);
?>
<?php if (!$suppressWelcome): ?>
<div class="welcome-overlay" id="welcomeOverlay" role="dialog" aria-modal="true"
     aria-labelledby="welcomeTitle" onclick="if (event.target === this) closeWelcome()">
    <div class="welcome-modal">
        <button type="button" class="welcome-close" onclick="closeWelcome()" aria-label="Close">&times;</button>

        <span class="welcome-eyebrow">Welcome to Dievon</span>
        <h2 class="welcome-title" id="welcomeTitle">Create Your Account</h2>
        <p class="welcome-text">
            Create an account for faster checkout, order tracking and first access to new collections.
        </p>

        <div class="welcome-actions">
            <a href="<?= SITE_URL ?>/register" class="btn-luxury welcome-btn">Create Account</a>
            <a href="<?= SITE_URL ?>/login" class="btn-luxury-outline welcome-btn">Sign In</a>
        </div>

        <div class="welcome-divider"><span>or just get our updates</span></div>

        <form class="welcome-form" onsubmit="return submitWelcomeEmail(event, this);">
            <input type="email" name="email" placeholder="Your email address" required class="welcome-input">
            <button type="submit" class="btn-luxury welcome-submit">Join</button>
        </form>
        <div class="welcome-msg" id="welcomeMsg"></div>

        <button type="button" class="welcome-skip" onclick="closeWelcome()">No thanks, keep browsing</button>
    </div>
</div>
<?php endif; ?>

<!-- ══ Toast ════════════════════════════════════════════════ -->
<?php /* The bump on the bag badge is the sighted confirmation; this is the
         same confirmation for anyone using a screen reader, who until now got
         nothing at all when an item went into the bag.

         The toast carries it rather than the badge because it holds the
         meaningful sentence ("Added to Bag" plus the garment's name), while a
         badge would announce a bare number. It is deliberately the only live
         region for this event — two of them would talk over each other. */ ?>
<div class="toast" id="toast" role="status" aria-live="polite" aria-atomic="true">
    <span class="toast-icon" aria-hidden="true">✨</span>
    <div>
        <div class="toast-text" id="toastText">Added to Bag!</div>
        <div class="toast-sub" id="toastSub"></div>
    </div>
</div>

<!-- ══ Footer ══════════════════════════════════════════════ -->
<?php
/*
 * $minimalFooter — set to true by a page BEFORE requiring this file to drop the
 * marketing columns and keep only the copyright and payment badges.
 *
 * Checkout uses it because the full footer measured 1332px on a 375px-wide
 * phone: 43% of the entire page, nearly as tall as the checkout itself. Those
 * columns are also links away from the purchase at the exact moment the
 * customer should be finishing it. The trust row below is kept deliberately.
 */
$minimalFooter = !empty($minimalFooter);
?>
<?php /* Closes the <main> opened in includes/header.php, immediately before the
         footer so the landmark wraps exactly the page's own content. */ ?>
</main>

<?php /* ── Dievon Invitations ──────────────────────────────────────────────
         Was section 10 of the home page, and only ever appeared to someone who
         had scrolled to the bottom of it. It lives here now, directly above the
         footer, so it is on every page — the shop, a product, the journal, the
         lookbook.

         Gated on $minimalFooter for the same reason every other footer block
         is: checkout deliberately drops everything that leads away from the
         purchase, and an invitation to join a mailing list mid-payment is
         exactly that.

         The band is above <footer> rather than inside it on purpose. It is not
         footer content — it is a call to action with its own ground colour and
         its own section spacing, and putting it inside the landmark would
         announce it to a screen reader as part of the site's small print. */ ?>
<?php if (!$minimalFooter): ?>
<section class="newsletter-section section-space">
    <div class="container newsletter-container">
        <span class="editorial-label">Private Membership</span>
        <h2 class="section-title">Dievon Invitations</h2>
        <p class="newsletter-subtitle">Subscribe for private lookbook access, digital invitations, and seasonal collection previews.</p>

        <?php /* Posts to the same endpoint the footer column always did, so a
                 signup here is recorded in newsletter_subscribers exactly as one
                 from anywhere else. */ ?>
        <form method="POST" onsubmit="return submitHomeNewsletter(event, this);">
            <?php /* submitHomeNewsletter() posts a FormData built from this form,
                     so the token travels with it without touching the script. */ ?>
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <div class="newsletter-input-wrap">
                <input type="email" id="newsletterEmailInput" name="email" class="animated-input" required autocomplete="email" placeholder=" ">
                <label for="newsletterEmailInput" class="animated-label">Enter your email address</label>
            </div>
            <button type="submit" class="btn-luxury btn-newsletter">Request Membership</button>
            <div class="newsletter-home-msg" id="newsletterHomeMsg" style="display:none; margin-top:12px; font-size:13px;"></div>
        </form>
    </div>
</section>
<?php endif; ?>

<footer class="footer-enhanced<?= $minimalFooter ? ' footer-minimal' : '' ?>">
    <div class="container">

        <?php if (!$minimalFooter): ?>
        <div class="footer-top">
            <div class="footer-brand">
                <a href="<?= SITE_URL ?>/">
                    <img src="<?= htmlspecialchars(siteLogoUrl($pdo ?? null)) ?>" alt="Dievon">
                </a>
                <p>
                    <?php // Written out here rather than pulled from SHOP_TAGLINE, and that is
                          // the point: this is a sentence, the tagline is a label. The tagline
                          // suits the places it stands alone — the About hero, the sign-off on
                          // an email — while the footer has room for prose, and two brand
                          // statements one after the other read like a throat being cleared.
                          //
                          // Audience-neutral either way: "Indian luxury", not "women's
                          // fashion", so it still holds once menswear is on sale. ?>
                    Timeless Indian luxury designed for the modern connoisseur. Handcrafted with the finest fabrics and tailored for elegance.
                </p>
                <?php
                // Store Settings first, .env second.
                //
                // These lived only in .env, so adding an Instagram link meant editing
                // a file over FTP on the live server. They are settings, so they are
                // in Settings now — the .env constants still work and are used when a
                // field is left blank, so an install already configured that way is
                // unaffected.
                //
                // An icon is drawn ONLY for a profile that exists. Pointing an
                // Instagram icon at /contact, or WhatsApp at a dummy number, is a lie
                // a shopper spots immediately.
                $socialPick = function (string $settingKey, string $constName) use ($pdo): string {
                    $v = trim((string)storeSetting($pdo ?? null, $settingKey, ''));
                    if ($v !== '') { return $v; }
                    return defined($constName) ? trim((string)constant($constName)) : '';
                };

                $footerSocial = array_filter([
                    'Instagram' => $socialPick('social_instagram', 'SHOP_SOCIAL_INSTAGRAM'),
                    'Facebook'  => $socialPick('social_facebook',  'SHOP_SOCIAL_FACEBOOK'),
                    'YouTube'   => $socialPick('social_youtube',   'SHOP_SOCIAL_YOUTUBE'),
                    'Pinterest' => $socialPick('social_pinterest', 'SHOP_SOCIAL_PINTEREST'),
                ], fn($u) => $u !== '');

                // Digits only — a number typed as "+91 98765 43210" would otherwise
                // build a wa.me link that silently fails.
                $footerWhatsApp = preg_replace('/\D/', '', $socialPick('social_whatsapp', 'SHOP_WHATSAPP'));
                ?>
                <?php // The wrapper is skipped entirely when there is nothing to put in
                      // it — an empty .footer-social still carried its 16px top margin,
                      // leaving a gap under the brand text for no reason. ?>
                <?php if ($footerSocial || $footerWhatsApp !== ''): ?>
                <div class="footer-social">
                    <?php foreach ($footerSocial as $label => $url): ?>
                        <a href="<?= htmlspecialchars($url) ?>" target="_blank" rel="noopener" aria-label="<?= htmlspecialchars($label) ?>"><i class="fa-brands fa-<?= strtolower($label) ?>"></i></a>
                    <?php endforeach; ?>
                    <?php if ($footerWhatsApp !== ''): ?>
                        <a href="https://wa.me/<?= htmlspecialchars($footerWhatsApp) ?>?text=Hello%20Dievon%20Concierge" target="_blank" rel="noopener" aria-label="WhatsApp"><i class="fa-brands fa-whatsapp"></i></a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="footer-col">
                <h4>Collections</h4>
                <ul>
                    <?php
                    // Was a hardcoded list of Dresses, Handbags, Shoes, Outerwear and
                    // Accessories — none of which exist as categories. Every one of
                    // those five links landed the shopper on an empty shop page.
                    //
                    // $menuCategories is built in includes/header.php and already
                    // excludes anything with nothing to sell, so these links can
                    // never point at an empty grid again.
                    $footerCats = array_slice($menuCategories ?? [], 0, 5);
                    ?>
                    <?php if ($footerCats): ?>
                        <?php foreach ($footerCats as $fc): ?>
                        <li><a href="<?= htmlspecialchars(categoryUrlByName($pdo, $fc['name'])) ?>"><?= htmlspecialchars($fc['name']) ?></a></li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li><a href="<?= SITE_URL ?>/shop">Shop All</a></li>
                    <?php endif; ?>
                </ul>
            </div>
            <div class="footer-col">
                <h4>Customer Service</h4>
                <ul>
                    <li><a href="<?= SITE_URL ?>/about">Our Story</a></li>
                    <li><a href="<?= SITE_URL ?>/blog">Journal</a></li>
                    <li><a href="<?= SITE_URL ?>/contact">Contact Us</a></li>
                    <li><a href="<?= SITE_URL ?>/shipping">Shipping &amp; Delivery</a></li>
                    <li><a href="<?= SITE_URL ?>/returns">Returns &amp; Refunds</a></li>
                    <li><a href="<?= SITE_URL ?>/privacy">Privacy Policy</a></li>
                    <li><a href="<?= SITE_URL ?>/terms">Terms &amp; Conditions</a></li>
                </ul>
            </div>
            <?php /* The Newsletter column that stood here is gone. Dievon
                     Invitations now sits directly above this footer on every
                     page, and the two were the same signup to the same endpoint
                     about two hundred pixels apart — asking twice reads as a
                     page that did not notice you had already answered.

                     submitNewsletterSignup() is kept below: the same handler
                     still serves any other footer form, and deleting a working
                     endpoint helper to save nine lines is how the next form
                     silently stops recording. */ ?>
            <div class="footer-col">
                <h4>Client Care</h4>
                <p>
                    Our boutique team is here for sizing, styling and order
                    questions.
                </p>
                <a class="footer-care-link" href="<?= SITE_URL ?>/contact">Contact the Atelier</a>
            </div>
        </div>
        <?php endif; ?>
        <div class="footer-bottom-bar">
            <div class="footer-bottom">
                <p>&copy; <?= date('Y') ?> Dievon. ALL RIGHTS RESERVED.</p>
            </div>
            <?php /* Where the country is actually READABLE.
                     ──────────────────────────────────────────────────────────
                     The header's picker is a globe, chosen because two letters
                     among five drawn glyphs never sat right — but a globe cannot
                     tell a shopper that they are seeing rupee prices. This can,
                     in full, and the footer is where most shops keep the setting
                     anyway.

                     It writes the same cookie through the same changeCountry()
                     the header calls, so there is one code path and the two
                     controls cannot disagree. Its own id, because the header's
                     is already on the page. Rendered only when a second country
                     is enabled, matching the header exactly. */ ?>
            <?php if (function_exists('countrySelectorEnabled') && countrySelectorEnabled()):
                $fCode = currentCountryCode();
                $fList = enabledCountries(); ?>
            <div class="footer-country">
                <label for="footerCountrySelector">Shipping to</label>
                <?php /* The value is drawn as text with the <select> invisible over it,
                         the same arrangement as the header's globe. A native select is
                         as wide as its LONGEST option, so left to itself this one is
                         sized for "United Kingdom — GBP" whichever country is chosen —
                         which strands the chevron and runs the underline past the end
                         of the words. A span is exactly as wide as what it says. */ ?>
                <span class="footer-country-value" aria-hidden="true">
                    <?= htmlspecialchars(($fList[$fCode]['country_name'] ?? $fCode) . ' — ' . ($fList[$fCode]['currency_code'] ?? '')) ?>
                    <svg viewBox="0 0 24 24" focusable="false"><path d="m6 9.5 6 6 6-6"/></svg>
                </span>
                <select id="footerCountrySelector" onchange="changeCountry(this.value)"
                        aria-label="Select country and currency">
                    <?php foreach ($fList as $fc => $frow): ?>
                    <option value="<?= htmlspecialchars($fc) ?>" <?= $fCode === $fc ? 'selected' : '' ?>>
                        <?= htmlspecialchars($frow['country_name'] . ' — ' . $frow['currency_code']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <!-- Secure Payments -->
            <div class="footer-secure-payments">
                <div class="secure-payments-label"><i class="fa-solid fa-lock"></i> 100% Secure Payments</div>
                <div class="secure-payments-logos">
                    <i class="fa-brands fa-cc-visa" title="Visa"></i>
                    <i class="fa-brands fa-cc-mastercard" title="Mastercard"></i>
                    <img src="<?= SITE_URL ?>/assets/images/payment/rupay.svg" alt="RuPay" title="RuPay">
                    <img src="<?= SITE_URL ?>/assets/images/payment/upi.svg" alt="UPI" title="UPI">
                </div>
            </div>
        </div>
    </div>
</footer>

<!-- ══ Mobile Bottom Navigation Dock ═════════════════════════ -->
<nav class="mobile-bottom-dock">
    <a href="<?= SITE_URL ?>/" class="mobile-dock-item <?php echo (!isset($path) || $path == 'home') ? 'active' : ''; ?>">
        <i class="fa-solid fa-house"></i>
        <span>Home</span>
    </a>
    <button type="button" class="mobile-dock-item" onclick="openSearchOverlay()">
        <i class="fa-solid fa-magnifying-glass"></i>
        <span>Search</span>
    </button>
    <?php if (isset($_SESSION['customer_id'])): ?>
        <a href="<?= SITE_URL ?>/account" class="mobile-dock-item <?php echo (isset($path) && $path == 'account') ? 'active' : ''; ?>">
            <i class="fa-solid fa-user"></i>
            <span>Account</span>
        </a>
    <?php else: ?>
        <a href="<?= SITE_URL ?>/login" class="mobile-dock-item <?php echo (isset($path) && $path == 'login') ? 'active' : ''; ?>">
            <i class="fa-regular fa-user"></i>
            <span>Login</span>
        </a>
    <?php endif; ?>
    <?php if (defined('SHOP_WHATSAPP') && SHOP_WHATSAPP !== ''): ?>
    <a href="https://wa.me/<?= SHOP_WHATSAPP ?>?text=Hello%20Dievon%20Concierge" target="_blank" rel="noopener" class="mobile-dock-item mobile-dock-whatsapp">
        <i class="fa-brands fa-whatsapp"></i>
        <span>Contact Us</span>
    </a>
    <?php endif; ?>
</nav>

<!-- Floating WhatsApp Concierge & Back To Top Widgets -->
<?php if (defined('SHOP_WHATSAPP') && SHOP_WHATSAPP !== ''): ?>
<a href="https://wa.me/<?= SHOP_WHATSAPP ?>?text=Hello%20Dievon%20Concierge" target="_blank" rel="noopener" class="whatsapp-float-btn" title="Chat on WhatsApp" style="position:fixed; bottom:25px; right:25px; width:50px; height:50px; background:#25d366; color:#fff; display:flex; align-items:center; justify-content:center; font-size:26px; box-shadow:0 4px 12px rgba(0,0,0,0.25); z-index:998; text-decoration:none;">
    <i class="fa-brands fa-whatsapp"></i>
</a>
<?php endif; ?>
<!-- Back To Top Widget -->
<?php /* Styled from the stylesheet, not from a style attribute. The inline
         version is why responsive.css had to say `bottom: 70px !important` to
         move it on a phone — an inline declaration outranks any rule a
         stylesheet can write, so every adjustment needed a bigger hammer.

         aria-label because the only content is an icon font glyph. Without it a
         screen reader announced "button" and nothing else. */ ?>
<button type="button" id="backToTopBtn" aria-label="Back to top">
    <i class="fa-solid fa-chevron-up" aria-hidden="true"></i>
</button>
<script>
(function () {
    /* Shown once there is somewhere to go back TO.
       ─────────────────────────────────────────────────────────────────────
       This button was pinned to the viewport with no condition at all, so it
       sat over the bottom-right corner of every page including the very top —
       offering to return you to where you already were, and competing with
       whatever the design puts in that corner. On the homepage that is now the
       hero's pager, which had to be moved 96px inland to keep clear of it.

       One viewport of scrolling is the threshold: below that the top is still
       a flick away and the button is clutter. The 120px band between showing
       and hiding stops it blinking when a reader hovers around the boundary. */
    var btn = document.getElementById('backToTopBtn');
    if (!btn) { return; }

    var SHOW_AFTER = function () { return Math.max(400, window.innerHeight * 0.9); };
    var ticking = false, shown = false;

    function update() {
        ticking = false;
        var y = window.scrollY || window.pageYOffset || 0;
        var on = shown ? y > SHOW_AFTER() - 120 : y > SHOW_AFTER();
        if (on === shown) { return; }
        shown = on;
        btn.classList.toggle('is-visible', on);
    }
    window.addEventListener('scroll', function () {
        if (ticking) { return; }
        ticking = true;
        window.requestAnimationFrame(update);
    }, { passive: true });
    window.addEventListener('resize', update, { passive: true });
    update();

    btn.addEventListener('click', function () {
        /* Instant for anyone who asked for less movement — smooth-scrolling a
           whole page is exactly the kind of motion that setting is about. */
        var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        window.scrollTo({ top: 0, behavior: reduce ? 'auto' : 'smooth' });
    });
})();
</script>

<!-- Luxury Glassmorphic Ultra-Slim Header Effect on Scroll -->
<script>
document.addEventListener('DOMContentLoaded', () => {
    const nav = document.querySelector('.navbar-luxury');
    if (!nav) return;

    let isCompact = false;
    let lastY     = Math.max(0, window.scrollY || window.pageYOffset);
    let ticking   = false;

    // Which way the reader is going, published as a class on the header.
    //
    // This script only reports the direction — it does not move anything. What
    // (if anything) happens visually is decided in the stylesheet by writing a
    // rule for .header-scroll-down, so the behaviour can be changed or dropped
    // without touching any JavaScript.
    const DOWN = 'header-scroll-down';
    const UP   = 'header-scroll-up';

    // Stay neutral until the page has scrolled past roughly the header's own
    // height. Reacting earlier makes the class flip on the tiny scroll that a
    // tap or an on-screen keyboard produces.
    const START_AFTER = 140;

    // Ignore movement smaller than this. Trackpads, momentum scrolling and iOS
    // rubber-banding all emit a stream of 1-2px events in alternating
    // directions, which without a threshold makes the class flicker.
    const DELTA = 6;

    function setDirection(dir) {
        nav.classList.toggle(DOWN, dir === 'down');
        nav.classList.toggle(UP,   dir === 'up');
    }

    function update() {
        ticking = false;
        const y = Math.max(0, window.scrollY || window.pageYOffset);

        // The mobile drawer and the search overlay lock the page by setting body
        // overflow. Whatever the stylesheet does with these classes, it must not
        // happen underneath an open panel.
        if (document.body.style.overflow === 'hidden') { setDirection(null); lastY = y; return; }

        // Someone tabbing through the menu must be able to see where they are.
        if (nav.contains(document.activeElement)) { setDirection(null); lastY = y; return; }

        // Pointer inside the header, so a mega menu may be open beneath it.
        // Tested here rather than with a CSS :hover rule, because :hover latches
        // after a tap on a touchscreen and would stick until something else is
        // tapped.
        if (typeof nav.matches === 'function' && nav.matches(':hover')) { setDirection(null); lastY = y; return; }

        // Near the top: no direction at all, so the header always reads as its
        // normal self there.
        if (y <= START_AFTER) { setDirection(null); lastY = y; return; }
        if (Math.abs(y - lastY) < DELTA) { return; }

        setDirection(y > lastY ? 'down' : 'up');
        lastY = y;
    }

    window.addEventListener('scroll', () => {
        const scrollPos = window.scrollY || window.pageYOffset;
        if (!isCompact && scrollPos > 80) {
            nav.classList.add('header-compact');
            isCompact = true;
        } else if (isCompact && scrollPos < 30) {
            nav.classList.remove('header-compact');
            isCompact = false;
        }

        // Reading scrollY is cheap, but writing a class on every scroll event is
        // not — rAF collapses a burst of events into one change per frame.
        if (!ticking) { ticking = true; window.requestAnimationFrame(update); }
    }, { passive: true });

    // Bring the header back the moment a panel opens, without waiting for the
    // next scroll — with the page locked, no scroll event is coming.
    if (window.MutationObserver) {
        new MutationObserver(() => {
            if (document.body.style.overflow === 'hidden') { setDirection(null); }
        }).observe(document.body, { attributes: true, attributeFilter: ['style'] });
    }

    document.addEventListener('focusin', () => {
        if (nav.contains(document.activeElement)) { setDirection(null); }
    });
});
</script>


<!-- ══ Scripts ══════════════════════════════════════════════ -->
<!-- Branded dialog. Loaded WITHOUT defer and before the inline handlers below so that
     window.alert is already overridden by the time any of them can fire. -->
<script src="<?= SITE_URL ?>/assets/js/dievon-dialog.js?v=<?= filemtime(__DIR__ . '/../assets/js/dievon-dialog.js') ?>"></script>
<script src="<?= SITE_URL ?>/assets/js/dievon-welcome.js?v=<?= filemtime(__DIR__ . '/../assets/js/dievon-welcome.js') ?>" defer></script>
<script src="<?= SITE_URL ?>/assets/js/dievon-quickview.js?v=<?= filemtime(__DIR__ . '/../assets/js/dievon-quickview.js') ?>"></script>
<script src="<?= SITE_URL ?>/assets/js/dievon-compare.js?v=<?= filemtime(__DIR__ . '/../assets/js/dievon-compare.js') ?>"></script>
<script src="<?= SITE_URL ?>/assets/js/search.js" defer></script>
<script src="<?= SITE_URL ?>/assets/js/dievon-password.js?v=<?= filemtime(__DIR__ . '/../assets/js/dievon-password.js') ?>" defer></script>
<script>
/* Dievon Invitations' handler, moved here with the band it serves.
   ────────────────────────────────────────────────────────────────────────────
   It lived in pages/home.php, which is where the form used to be. Leaving it
   there would have shipped the form to every page and the code to one of them —
   a submit button that silently did nothing everywhere except the home page. */
function submitHomeNewsletter(e, form) {
    e.preventDefault();
    const msg = document.getElementById('newsletterHomeMsg');
    const btn = form.querySelector('button[type="submit"]');
    const originalLabel = btn.textContent;
    btn.disabled = true;
    const formData = new FormData(form);
    fetch(window.SITE_URL + '/actions/newsletter_action.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            msg.style.display = 'block';
            msg.style.color = data.success ? '#10b981' : '#ef4444';
            msg.textContent = data.message;
            if (data.success) form.reset();
            btn.disabled = false;
            btn.textContent = originalLabel;
        })
        .catch(() => {
            msg.style.display = 'block';
            msg.style.color = '#ef4444';
            msg.textContent = 'Something went wrong. Please try again.';
            btn.disabled = false;
            btn.textContent = originalLabel;
        });
    return false;
}

function submitNewsletterSignup(e, form) {
    e.preventDefault();
    const msg = form.parentElement.querySelector('.footer-newsletter-msg');
    const btn = form.querySelector('button[type="submit"]');
    const originalLabel = btn.textContent;
    btn.disabled = true;

    const formData = new FormData(form);
    fetch(window.SITE_URL + '/actions/newsletter_action.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            msg.style.display = 'block';
            msg.style.color = data.success ? '#10b981' : '#ef4444';
            msg.textContent = data.message;
            if (data.success) form.reset();
            btn.disabled = false;
            btn.textContent = originalLabel;
        })
        .catch(() => {
            msg.style.display = 'block';
            msg.style.color = '#ef4444';
            msg.textContent = 'Something went wrong. Please try again.';
            btn.disabled = false;
            btn.textContent = originalLabel;
        });
    return false;
}

// ── Cart state ──────────────────────────────────────────────
let cartState = { items: [], cart_count: 0, cart_total: '0.00' };

// ── Open / Close cart ────────────────────────────────────────
function openCart() { 
    document.getElementById('cartSidebar').classList.add('open'); 
    document.getElementById('cartOverlay').classList.add('open'); 
    dievonScrollLock.lock('cart'); 
}
function closeCart() { 
    document.getElementById('cartSidebar').classList.remove('open'); 
    document.getElementById('cartOverlay').classList.remove('open'); 
    dievonScrollLock.unlock('cart'); 
}

// ── Add to Cart AJAX helper ──────────────────────────────────
function getCartApiUrl(param) {
    const base = (typeof window.SITE_URL !== 'undefined' && window.SITE_URL) ? window.SITE_URL + '/actions/cart_action.php' : 'actions/cart_action.php';
    return param ? base + param : base;
}

function addToCart(productId, variantId, name, emoji, variantName, variantPrice, size, quantity, colorId) {
    variantId = variantId || 0;
    size = size || (typeof variantName === 'string' ? variantName : '');
    let body = 'action=add&product_id=' + productId;
    if (variantId > 0) body += '&variant_id=' + variantId;
    if (size) body += '&size=' + encodeURIComponent(size);
    if (quantity && quantity > 1) body += '&quantity=' + quantity;
    // Optional last argument, so every existing caller keeps working untouched.
    // Only a colour-only product needs it: where sizes exist the colour travels
    // on the variant row, and the server reads it from there rather than trusting
    // anything sent from here.
    if (colorId && colorId > 0) body += '&color_id=' + colorId;

    fetch(getCartApiUrl(), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body,
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            cartState = data;
            renderCart();
            updateBadge();
            bumpBadge();
            // Strip a leading "Size:" before adding one.
            //
            // 42 of the 49 variant rows store their name as "Size: M" rather than
            // "M", and callers pass that string through as the size — so this built
            // "(Size: SIZE: M)". Normalising here rather than at each call site,
            // because the stored format is the thing that keeps causing it.
            const cleanSize = String(size || '').replace(/^\s*size\s*:\s*/i, '').trim();
            const cleanVariant = String(variantName || '').replace(/^\s*size\s*:\s*/i, '').trim();
            const label = cleanSize
                ? name + ' (Size: ' + cleanSize.toUpperCase() + ')'
                : (cleanVariant ? name + ' (Size: ' + cleanVariant.toUpperCase() + ')' : name);
            showToast('Added to Bag!', label + ' is now in your shopping bag.');

            if (typeof onCartUpdated === 'function') {
                onCartUpdated(cartState);
            }
        } else if (data.requires_size) {
            // Absolute, and without the dead select_size flag.
            //
            // This was relative, and the browser resolves a relative path against
            // the CURRENT one — so from /product/<slug>-<id>, where this branch
            // actually fires, it asked for /product/product.php?id=18 and landed
            // on the 404 page. select_size=1 was never read anywhere in the
            // codebase (set in three files, read in none), so it is dropped
            // rather than carried along looking meaningful.
            window.location.href = `${window.SITE_URL}/product.php?id=${productId}`;
        } else {
            showToast('⚠️ Notice', data.message || 'Could not add item.');
        }
    })
    .catch(() => showToast('⚠️ Error', 'Could not add item, please try again.'));
}

// ── Remove item ──────────────────────────────────────────────
function removeItem(cartKey) {
    fetch(getCartApiUrl(), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=remove&cart_key=' + encodeURIComponent(cartKey),
    })
    .then(r => r.json()).then(data => { 
        cartState = data; 
        renderCart(); 
        updateBadge(); 
        if (typeof onCartUpdated === 'function') onCartUpdated(cartState);
    });
}

// ── Update quantity ───────────────────────────────────────────
/* The quantity stepper, written once.
 * ─────────────────────────────────────────────────────────────────────────────
 * The same control is built in two places — the cart drawer just below, and the
 * full cart page's own renderer in pages/cart.php — and the two copies had
 * already parted company. The drawer's buttons carried no aria-label while the
 * page's did, so the same control was described to a screen reader on one
 * screen and not the other; and any future change (a stock cap, a different
 * disabled reason, a busy state) had to be made twice or it would part company
 * again.
 *
 * Both LOOKS are kept. The page's stepper is flat buttons inside one bordered
 * pill; the drawer's is a pair of circles. Those are two designs of the same
 * control, not a defect, and choosing between them is not this function's job —
 * so `variant` selects the class set and the markup is identical either way.
 *
 * Defined on window because pages/cart.php builds its rows from its own script;
 * both call sites fall back to their previous inline markup if this has somehow
 * not loaded, so a stepper can never fail to render.
 */
window.dievonQtyStepper = function (cartKey, qty, opts) {
    opts = opts || {};
    // One class set now. The variant switch that used to be here existed only
    // because the cart page and this drawer had two different LOOKS for the same
    // control; they have one look now, so there is nothing left to switch on.
    // opts.variant is still accepted and ignored, so an older call site cannot
    // break during a deploy.
    const cls    = 'qty-controls';
    const btn    = 'qty-btn';
    const val    = 'qty-value';
    const key    = String(cartKey).replace(/'/g, "\\'");
    const atLimit = !!opts.atLimit;
    return `
            <div class="${cls}">
                <button type="button" class="${btn}" aria-label="Decrease quantity"
                        onclick="updateQty('${key}', ${qty - 1})">\u2212</button>
                <span class="${val}">${qty}</span>
                <button type="button" class="${btn}" aria-label="Increase quantity"
                        onclick="updateQty('${key}', ${qty + 1})"
                        ${atLimit ? 'disabled title="That is all we have left"' : ''}>+</button>
            </div>`;
};

function updateQty(cartKey, qty) {
    fetch(getCartApiUrl(), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=update&cart_key=' + encodeURIComponent(cartKey) + '&quantity=' + qty,
    })
    .then(r => r.json()).then(data => { 
        cartState = data; 
        renderCart(); 
        updateBadge(); 
        if (typeof onCartUpdated === 'function') onCartUpdated(cartState);
    });
}

// ── Clear cart ───────────────────────────────────────────────
function clearCart() {
    fetch(getCartApiUrl(), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=clear',
    })
    .then(r => r.json()).then(data => { 
        cartState = data; 
        renderCart(); 
        updateBadge(); 
        if (typeof onCartUpdated === 'function') onCartUpdated(cartState);
    });
}

/* Tell the shopper when their bag was cut back.
   ────────────────────────────────────────────────────────────────────────────
   The server now re-checks every line against live stock on each cart reply, so
   a bag holding 10 of something the owner has since reduced to 5 comes back
   holding 5. Silently changing a number someone chose is worse than not changing
   it, so each adjustment is announced. Every path that refreshes the cart calls
   renderCart(), which is why the hook lives here rather than in five places.
   Notices are remembered for the page's lifetime so re-rendering the drawer
   cannot repeat the same message. */
const seenStockNotices = new Set();
function announceStockNotices() {
    (cartState.stock_notices || []).forEach(msg => {
        if (seenStockNotices.has(msg)) { return; }
        seenStockNotices.add(msg);
        showToast('Bag updated', msg);
    });
}

/* "2 in your bag" on the product card.
   ────────────────────────────────────────────────────────────────────────────
   A shopper had no way of telling, from a grid of tiles, which pieces were
   already in their bag — so the same garment got added twice, and the only clue
   was the badge quietly counting up.

   Driven off cartState rather than rendered server-side, for two reasons: the
   count stays correct the instant something is added, without a page load; and
   BOTH card renderers (includes/product_card.php and createProductCard() in
   pages/shop.php) are served by this one function, so they cannot drift apart.

   The wording earns its keep when the bag holds the last of something. Every
   cart line carries the stock ceiling the server worked out for it, so when
   each line of a product is sitting at that ceiling the note says so outright —
   which is the honest answer to "can I have more of this?". No stock figure the
   shopper does not already hold is exposed. */
function syncBagNotes() {
    const cards = document.querySelectorAll('.product-card[data-product-id]');
    if (!cards.length) { return; }

    // product id → { units, allMaxed }
    const bag = {};
    (cartState.items || []).forEach(item => {
        const pid = String(item.product_id);
        if (!bag[pid]) { bag[pid] = { units: 0, allMaxed: true }; }
        bag[pid].units += parseInt(item.quantity, 10) || 0;
        const limit = (item.stock_limit === null || item.stock_limit === undefined)
            ? null : parseInt(item.stock_limit, 10);
        // An uncounted line has no ceiling, so nothing about it is "all we have".
        if (limit === null || item.quantity < limit) { bag[pid].allMaxed = false; }
    });

    cards.forEach(card => {
        const note = card.querySelector('[data-bag-note]');
        if (!note) { return; }
        const held = bag[String(card.dataset.productId)];

        if (!held || held.units <= 0) {
            note.hidden = true;
            note.textContent = '';
            card.classList.remove('is-in-bag');
            return;
        }

        const unit = held.units === 1 ? 'item' : 'items';
        note.textContent = held.allMaxed
            ? `${held.units} in your bag — that's all we have`
            : `${held.units} ${unit} in your bag`;
        note.hidden = false;
        card.classList.add('is-in-bag');
    });
}

// ── Render cart sidebar ──────────────────────────────────────
function renderCart() {
    announceStockNotices();
    syncBagNotes();

    const itemsEl  = document.getElementById('cartItems');
    const footerEl = document.getElementById('cartFooter');
    const totalEl  = document.getElementById('cartTotal');

    if (!cartState.items || cartState.items.length === 0) {
        itemsEl.innerHTML = `<div class="cart-empty"><div class="cart-empty-icon">👜</div><p class="cart-empty-msg">Your shopping bag is empty.</p></div>`;
        footerEl.style.display = 'none';
        // Zero the drawer total too. Returning without touching it left the last
        // non-empty subtotal sitting under an empty bag.
        if (totalEl) { totalEl.textContent = formatPriceJS(0); }
        return;
    }

    let html = '';
    cartState.items.forEach(item => {
        const subtotal  = (item.price * item.quantity).toFixed(2);
        const cartKey   = item.cart_key || item.product_id;
        const imgHtml   = item.image
            ? `<img class="cart-item-img" src="${window.SITE_URL}/uploads/products/${escHtml(item.image)}" alt="${escHtml(item.name)}">`
            : `<div class="cart-item-img-placeholder">${escHtml(item.emoji)}</div>`;
        const colorLabel = item.color_name ? escHtml(item.color_name) + (item.variant_name ? ' · ' : '') : '';
        const vSize = String(item.variant_name || '').replace(/^\s*size\s*:\s*/i, '').trim();
        const variantLabel = vSize ? `<span class="cart-variant-label">${colorLabel}Size: ${escHtml(vSize)}</span>` : (colorLabel ? `<span class="cart-variant-label">${colorLabel}</span>` : '');
        // stock_limit is null when the line is not counted, so only a real
        // number stops the button — and it stops it rather than letting the
        // shopper press a + that the server will refuse.
        const atLimit = (item.stock_limit !== null && item.stock_limit !== undefined
                         && item.quantity >= item.stock_limit);
        html += `
        <div class="cart-item">
            ${imgHtml}
            <div class="cart-item-info">
                <div class="cart-item-name">${escHtml(item.name)}${variantLabel ? '<br>' + variantLabel : ''}</div>
                <div class="cart-item-price">${formatPriceJS(item.subtotal || (item.price * item.quantity))}</div>
            </div>
${window.dievonQtyStepper ? window.dievonQtyStepper(cartKey, item.quantity, { atLimit }) : `
            <div class="qty-controls">
                <button class="qty-btn" aria-label="Decrease quantity" onclick="updateQty('${cartKey}', ${item.quantity - 1})">−</button>
                <span class="qty-value">${item.quantity}</span>
                <button class="qty-btn" aria-label="Increase quantity" onclick="updateQty('${cartKey}', ${item.quantity + 1})"
                        ${atLimit ? 'disabled title="That is all we have left"' : ''}>+</button>
            </div>`}
            <button class="btn-remove-item" onclick="removeItem('${cartKey}')" title="Remove">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>`;
    });

    itemsEl.innerHTML = html;
    totalEl.textContent = formatPriceJS(cartState.cart_total_raw !== undefined ? cartState.cart_total_raw : cartState.cart_total);
    footerEl.style.display = 'block';
}

// ── Badge helpers ────────────────────────────────────────────
function updateBadge() {
    const count = (cartState && typeof cartState.cart_count !== 'undefined') ? parseInt(cartState.cart_count, 10) : 0;
    /* Two counts, two different things — they used to share one loop.
     * ─────────────────────────────────────────────────────────────────────
     * #cartBadge is the round disc on the bag icon. Hidden at zero, exactly as
     * updateWishlistBadge() already does: it forced display:flex
     * unconditionally, so an empty bag wore a filled maroon "0" permanently.
     * Beside a wishlist heart that correctly shows nothing at zero, that read
     * as a bug rather than a count — a badge is a notification, and "you have
     * zero things" is not something to notify anybody about.
     *
     * #mobileCartCount is a number inside a sentence, in the drawer's Shopping
     * Bag button. Sharing the rule above gave it both of that rule's
     * assumptions and neither of them holds: display:flex turned an inline
     * count into a flex container in the middle of the button's text, and
     * hiding it at zero left the brackets around it stranded — "Shopping Bag ()".
     * It takes the hidden attribute instead, and the brackets are on the span
     * in CSS so they go with it. */
    const badge = document.getElementById('cartBadge');
    if (badge) {
        badge.textContent = count;
        badge.style.display = count > 0 ? 'flex' : 'none';
    }
    const inlineCount = document.getElementById('mobileCartCount');
    if (inlineCount) {
        inlineCount.textContent = count;
        inlineCount.hidden = count === 0;
    }

    // Update sticky floating view cart bar
    const stickyBar = document.getElementById('stickyViewCartBar');
    const stickyCount = document.getElementById('stickyCartCount');
    const stickyTotal = document.getElementById('stickyCartTotal');
    const stickyItemText = document.getElementById('stickyCartItemText');

    if (stickyBar) {
        if (count > 0) {
            stickyBar.classList.add('show');
            if (stickyCount) stickyCount.textContent = count;
            if (stickyItemText) stickyItemText.textContent = count === 1 ? 'item' : 'items';
            if (stickyTotal) stickyTotal.textContent = formatPriceJS(cartState.cart_total_raw !== undefined ? cartState.cart_total_raw : cartState.cart_total);
        } else {
            stickyBar.classList.remove('show');
        }
    }

    if (typeof updatePageCartIndicators === 'function') {
        updatePageCartIndicators(cartState);
    }
}

function bumpBadge() {
    const count = (cartState && typeof cartState.cart_count !== 'undefined') ? parseInt(cartState.cart_count, 10) : 0;
    // Both badges, the same as updateBadge() already does. This only ever
    // bumped the desktop one, so on a phone — where most orders are placed —
    // the count changed with no movement at all.
    document.querySelectorAll('#cartBadge, #mobileCartCount').forEach(badge => {
        if (!badge) return;
        badge.textContent = count;
        // The drawer's inline count carries its own visibility; the bump must not
        // reveal a zero that updateBadge() has just hidden.
        if (badge.id === 'mobileCartCount') { badge.hidden = count === 0; }
        // Removing first and forcing a reflow lets the pop replay when items
        // are added one after another; re-adding a class the element already
        // carries does not restart a CSS animation on its own.
        badge.classList.remove('bump');
        void badge.offsetWidth;
        badge.classList.add('bump');
        setTimeout(() => badge.classList.remove('bump'), 400);
    });
}

// ── Toast ────────────────────────────────────────────────────
let toastTimeout;
function showToast(title, sub) {
    const toast = document.getElementById('toast');
    document.getElementById('toastText').textContent = title;
    document.getElementById('toastSub').textContent  = sub || '';
    toast.classList.add('show');
    clearTimeout(toastTimeout);
    toastTimeout = setTimeout(() => toast.classList.remove('show'), 3000);
}

// ── Escape HTML helper ───────────────────────────────────────
function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Mobile nav drawer toggle ─────────────────────────────────
const ham = document.getElementById('navHamburger');
const drawer = document.getElementById('mobileDrawer');
const drawerClose = document.getElementById('mobileDrawerClose');
function openMobileMenu()  { ham.classList.add('open'); drawer.classList.add('open'); dievonScrollLock.lock('menu'); }
function closeMobileMenu() { ham.classList.remove('open'); drawer.classList.remove('open'); dievonScrollLock.unlock('menu'); }
if (ham) ham.addEventListener('click', openMobileMenu);
if (drawerClose) drawerClose.addEventListener('click', closeMobileMenu);
if (drawer) drawer.addEventListener('click', e => { if (e.target === drawer) closeMobileMenu(); });

// Close on Escape key
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { 
        closeCart(); 
        closeMobileMenu(); 
        if (typeof closeVariantPicker === 'function') closeVariantPicker();
    }
});

// ── Scroll-reveal fade-in (site-wide, respects prefers-reduced-motion) ──
(function() {
    const prefersReduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let observer = null;
    if (!prefersReduced && 'IntersectionObserver' in window) {
        observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('revealed');
                    // Release the entrance stagger so later hovers/transitions
                    // on the same element are never delayed.
                    entry.target.style.removeProperty('transition-delay');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });
    }

    // Call after adding class="reveal-on-scroll" to any element added dynamically
    // (e.g. a product card created via JS after the initial page load).
    window.dievonObserveReveal = function(el) {
        if (!el) return;
        if (!observer) {
            el.classList.add('revealed');
            // No-observer path (reduced motion / old browsers) also needs the
            // stagger delay released, or every card keeps a laggy hover.
            el.style.removeProperty('transition-delay');
            return;
        }
        observer.observe(el);
    };

    document.addEventListener('DOMContentLoaded', function() {
        // Staggered cascade for collection grids: each child enters with a
        // small incremental delay. Only runs when the observer exists (the
        // no-observer path adds .revealed instantly, so nothing can get stuck
        // hidden). The delay is inline-important so it wins over the product
        // card's own `transition: all ... !important`, and the observer
        // removes it again once the entrance has played.
        /* '.new-arrivals-carousel' is gone from this list with the rail it named.
           The editorial gallery that replaced it must NOT be staggered in: its
           four panels are one composition, and revealing them one at a time
           would animate the layout apart before it settles. */
        ['.home-categories-grid', '.home-best-sellers-grid',
         '.home-blog-grid', '.editorial-grid', '.products-grid',
         '.newsletter-container', '.home-benefits-grid', '.footer-top', '.footer-bottom-bar'].forEach(sel => {
            document.querySelectorAll(sel + ' > *').forEach((el, i) => {
                if (el.classList.contains('reveal-on-scroll')) return;
                el.classList.add('reveal-on-scroll');
                el.style.setProperty('transition-delay', (Math.min(i % 4, 3) * 0.07).toFixed(2) + 's', 'important');
                window.dievonObserveReveal(el);
            });
        });
        document.querySelectorAll('.reveal-on-scroll').forEach(el => window.dievonObserveReveal(el));
    });
})();

// ── The card's second photograph, fetched on the first hover ─────────────
(function() {
    // Nothing is downloaded on a touch screen. The CSS never reveals the layer
    // there (see the @media (hover: hover) block), so pulling a second image
    // per tile would be pure waste on exactly the connections that can least
    // afford it.
    if (!window.matchMedia || !window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;

    // pointerover, not pointerenter — only the former bubbles, and one
    // delegated listener has to keep working for cards that arrive later from
    // Load More, the shop filters and the wishlist, none of which exist yet
    // when this runs.
    document.addEventListener('pointerover', function(e) {
        if (!e.target || !e.target.closest) return;
        const card = e.target.closest('.product-card');
        if (!card) return;
        const img = card.querySelector('.card-img-hover[data-src]');
        if (!img) return;

        const src  = img.dataset.src;
        const webp = img.dataset.webp || '';
        // Claimed before the request starts, so moving on and off the same tile
        // quickly cannot begin the download twice.
        delete img.dataset.src;

        // Loaded through a probe so the cross-fade only ever begins on a
        // complete picture, never on a half-drawn one over a slow connection.
        const probe = new Image();
        probe.onload = function() {
            img.src = probe.src;
            img.classList.add('is-ready');
        };
        probe.onerror = function() {
            // Falls back to the original when the WebP twin is missing or the
            // browser will not take it; only a genuinely absent file removes
            // the layer, so a broken image is never left hanging over the card.
            if (webp && probe.src !== src) { probe.src = src; return; }
            img.remove();
        };
        probe.src = webp || src;
    }, { passive: true });
})();

// Wishlist local-storage syncing helper (site-wide)
function getWishlist() {
    try {
        return JSON.parse(localStorage.getItem('dievon_wishlist')) || [];
    } catch(e) {
        return [];
    }
}
function toggleWishlist(productId) {
    let list = getWishlist();
    const idx = list.indexOf(productId);
    let added = false;
    if (idx > -1) {
        list.splice(idx, 1);
    } else {
        list.push(productId);
        added = true;
    }
    localStorage.setItem('dievon_wishlist', JSON.stringify(list));
    updateWishlistBadge();
    showToast(added ? 'Added to Wishlist' : 'Removed from Wishlist', added ? 'Item added to your private wishlist' : 'Item removed from your wishlist');
    return added;
}
function updateWishlistBadge() {
    const list = getWishlist();
    const badge = document.getElementById('wishlistBadge');
    if (badge) {
        badge.textContent = list.length;
        badge.style.display = list.length > 0 ? 'inline-flex' : 'none';
    }
}

function handleWishlistClick(productId, btn) {
    const icon = btn.querySelector('i');
    const added = toggleWishlist(productId.toString());
    if (added) {
        icon.className = 'fa-solid fa-heart wishlist-btn-active';
        // Only on the deliberate click, never in syncWishlistHearts() below —
        // that runs on every page load and would pop every saved heart on the
        // grid at once, which reads as a glitch rather than as feedback.
        void icon.offsetWidth;
        icon.classList.add('wishlist-pop');
    } else {
        icon.className = 'fa-regular fa-heart';
    }

    /* So a page showing a list OF the wishlist can react to it changing.
       The wishlist page needs this: its cards come from the shared partial,
       whose heart toggles rather than removes, so un-hearting a piece there
       has to take the card off the list too. */
    document.dispatchEvent(new CustomEvent('dievon:wishlist-changed', {
        detail: { productId: productId, added: added }
    }));
}

function syncWishlistHearts() {
    const list = getWishlist();
    document.querySelectorAll('button[onclick*="handleWishlistClick"]').forEach(btn => {
        const match = btn.getAttribute('onclick').match(/handleWishlistClick\((\d+)/);
        if (match && match[1]) {
            const pid = match[1];
            const icon = btn.querySelector('i');
            if (icon) {
                if (list.indexOf(pid.toString()) > -1) {
                    icon.className = 'fa-solid fa-heart wishlist-btn-active';
                } else {
                    icon.className = 'fa-regular fa-heart';
                }
            }
        }
    });
}

// formatPriceJS() is defined by includes/header.php, which every real page
// loads before this file. Fallback only, in case something ever includes
// footer.php on its own — see includes/header.php for the real definition.
if (typeof formatPriceJS !== 'function') {
    function formatPriceJS(amount) {
        const sym = window.DIEVON_CURRENT_SYMBOL || '₹';
        const gap = /\p{L}$/u.test(sym) ? ' ' : '';
        const fixed = (parseFloat(amount) || 0).toFixed(2);
        const neg   = fixed.charAt(0) === '-';
        const body  = neg ? fixed.slice(1) : fixed;
        const dot   = body.indexOf('.');
        const grouped = body.slice(0, dot).replace(/\B(?=(\d{3})+(?!\d))/g, ',') + body.slice(dot);
        return sym + gap + (neg ? '-' : '') + grouped;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    updateWishlistBadge();
    syncWishlistHearts();
});

// Load cart on page startup
fetch(getCartApiUrl('?action=get'))
    .then(r => r.json())
    .then(data => { 
        cartState = data; 
        renderCart(); 
        updateBadge(); 
        if (typeof onCartUpdated === 'function') onCartUpdated(cartState);
    });
</script>

<script>
/* ── Product rails, on OwlCarousel ─────────────────────────────────────────
   ONE initialiser for every horizontal rail on the site — new arrivals, the
   occasion strip, suggested pieces, recently viewed. Written once, here, rather
   than a copy per page: this codebase has been bitten repeatedly by two copies
   of one idea drifting apart, and four hand-maintained carousels would be four
   more chances of it.

   Opt in from the markup with class="js-owl-rail" and, where the design wants a
   fixed card width, data-owl-autowidth="1".

   The rails were native CSS scroll-snap before this, which needed no JS at all.
   They are on Owl now because the shop owner asked for one slider engine across
   the site after repeated trouble, and consistency across browsers is worth
   more to them than the few kilobytes.

   Everything degrades safely: if jQuery or Owl fails to load, the markup is
   still a plain scrollable flex row and every product remains reachable. */
document.addEventListener('DOMContentLoaded', function () {
    if (!window.jQuery || typeof jQuery.fn.owlCarousel !== 'function') { return; }

    jQuery('.js-owl-rail').each(function () {
        var $rail = jQuery(this);
        if ($rail.hasClass('owl-loaded') || !$rail.children().length) { return; }

        /* Owl's OWN stylesheet is scoped to .owl-carousel — and Owl never adds
           that class. It adds owl-loaded and owl-drag, and expects .owl-carousel
           to already be in the markup. Without it, not one rule in
           owl.carousel.min.css applies. The one that matters most is:

               .owl-carousel.owl-drag .owl-item { touch-action: pan-y; }

           touch-action: pan-y is what tells a phone "vertical panning is yours,
           anything horizontal belongs to this element". Missing it, the browser
           claims every gesture — so a sideways swipe on the slider scrolled the
           PAGE instead of moving the slider. It also carries the overflow, float
           and user-select rules that were being hand-written in style.css.

           Added from JS rather than in the markup on purpose: Owl's CSS also
           says .owl-carousel { display: none }, undone only by .owl-loaded. In
           the HTML that would hide the rail until JS ran, and for ever if the
           library failed to load. One line before init, it can only ever be
           present alongside a working Owl. */
        $rail.addClass('owl-carousel');

        /* Firefox ignores the CSS user-drag property, so the attribute says it
           too: a tile is a link around a photograph, and both are draggable
           objects by default. Pulling one started a native drag-and-drop that
           ate the mouse events Owl needs, and the rail simply would not slide.
           Clicking is untouched — only dragging the object itself is off. */
        $rail.find('a, img').attr('draggable', 'false');

        var autoWidth = $rail.data('owl-autowidth') === 1 || $rail.data('owl-autowidth') === '1';
        var gap       = parseFloat(getComputedStyle(this).columnGap) || 24;

        /* stagePadding, not CSS padding, for a full-bleed rail.
           ─────────────────────────────────────────────────────────────────────
           A rail marked .dv-rail-bleed runs to the edge of the screen, and the
           first card should not sit against the glass. CSS padding on the
           element cannot do it: Owl clips the strip at the element's box, so
           padding insets the CLIP too and the last card is cut 16px early
           instead of at the edge. stagePadding insets the cards inside a stage
           that still clips at the element's edge, which is the whole point.

           Read from the class rather than passed per call site, so any rail that
           opts into the bleed gets the inset automatically. */
        $rail.owlCarousel({
            stagePadding: $rail.hasClass('dv-rail-bleed') ? 16 : 0,
            // autoWidth keeps each card at the width the CSS already gives it,
            // which is what preserves the existing look (and the peek of the
            // next card) instead of Owl stretching items to fill the row.
            autoWidth: autoWidth,
            items: autoWidth ? undefined : 1,
            margin: gap,
            nav: false,          // the pages draw their own arrows
            dots: false,
            loop: false,         // a product rail should stop at the end, not wrap
            mouseDrag: true,
            touchDrag: true,
            freeDrag: false,
            slideBy: 1,
            /* 500ms, the same as every other rail on the homepage. Left unset
               this fell back to Owl's own 250, which is why this strip felt
               abrupt beside New Arrivals (500), the fan (620) and the hero
               (800) — it was moving at twice their speed. */
            smartSpeed: 500,
            /* Whole cards, never a sliver.
               A rail without autoWidth divides the row into a WHOLE number of
               cards at each width, so the strip always ends flush instead of
               cutting the last card in half. At 1440px that is four cards of
               ~313px — near enough the 280px the fixed-width version used that
               the section looks unchanged, but without the 0.43 of a card that
               used to hang off the right edge. */
            responsive: autoWidth ? undefined : {
                0:    { items: 1 },
                576:  { items: 2 },
                768:  { items: 3 },
                992:  { items: 4 }
            }
        });

        /* Re-measure once the browser has settled.
           ────────────────────────────────────────────────────────────────────
           Owl measures every item DURING init, and adds .owl-loaded after. Any
           width that only applies once that class exists is therefore invisible
           to the measurement — which is exactly how the occasion strip ended up
           with an 888px row squeezed into a 560px stage, its last tiles
           unreachable. The widths no longer depend on that class, and this
           refresh is the belt to that braces: it also catches a late-loading
           web font or image changing an item's size after init. */
        requestAnimationFrame(function () { $rail.trigger('refresh.owl.carousel'); });

        /* Trackpad: two fingers sideways should slide the rail.
           ────────────────────────────────────────────────────────────────────
           Before Owl these rails were native overflow-x containers, so a
           two-finger swipe scrolled them for free. Owl replaced that with a
           transform inside overflow:hidden — there is no scrollable box left,
           and Owl itself only listens for drags. So on a laptop the most
           natural gesture there is did nothing at all, and the rail looked
           broken to anyone who never thought to click and pull.

           Only a HORIZONTAL gesture is taken: deltaX has to beat deltaY, so an
           ordinary vertical scroll still belongs to the page and reading the
           homepage never gets caught on a slider. preventDefault is called only
           on that horizontal case — which also stops macOS reading the same
           swipe as "go back a page" while you are browsing a row of kurtis.

           The deltas are accumulated to a threshold and then locked for the
           length of Owl's own animation: a trackpad fires wheel events in the
           dozens, and one slide each would send the rail flying to the end. */
        (function (railEl, $c) {
            /* The rail follows the fingers, it does not hop.
               ─────────────────────────────────────────────────────────────────
               The first version of this counted the deltas up and fired
               next.owl.carousel once they passed a threshold — a whole slide per
               push, with a lock while it animated. It worked, and it felt awful:
               a trackpad sends a continuous stream and got back a series of
               jerks, nothing like the scrolling every other strip on a Mac does.

               So during the gesture the stage is moved directly, pixel for pixel,
               with the transition switched off — that is what makes it glide.
               When the fingers stop, the nearest slide is handed back to Owl,
               which animates the last few pixels and, more importantly, leaves
               its own idea of the current index correct so the arrows and any
               later drag still behave. */
            var outer = railEl.querySelector('.owl-stage-outer');
            var stage = railEl.querySelector('.owl-stage');
            if (!outer || !stage) { return; }

            var at = null, settle = null;

            function currentX() {
                var m = window.getComputedStyle(stage).transform;
                if (!m || m === 'none') { return 0; }
                try { return new DOMMatrix(m).m41; } catch (err) { return 0; }
            }

            function limit() {                       // furthest left the rail may go
                return Math.min(0, outer.clientWidth - stage.scrollWidth);
            }

            function snap() {
                var items = Array.prototype.slice.call(railEl.querySelectorAll('.owl-item'));
                if (!items.length) { at = null; stage.style.transition = ''; return; }

                // whichever item sits closest to the left edge right now
                var want = -at, best = 0, bestGap = Infinity;
                items.forEach(function (el, i) {
                    var gap = Math.abs(el.offsetLeft - want);
                    if (gap < bestGap) { bestGap = gap; best = i; }
                });

                /* Animate the last few pixels ourselves, then tell Owl where we
                   ended up at zero speed.
                   ──────────────────────────────────────────────────────────────
                   Handing the settle to Owl directly did not work: when the
                   nearest slide is the one it already thinks it is on — a short
                   swipe that does not reach the next card — it has nothing to do
                   and does nothing, leaving the rail parked wherever the fingers
                   stopped while its index said otherwise. The next arrow press
                   then jumped from a position it did not know about.

                   Doing the movement here covers both cases with one path, and
                   the zero-speed handover afterwards writes the same transform
                   we just animated to, so nothing moves twice — it only brings
                   Owl's own bookkeeping back in step. */
                var target = -items[best].offsetLeft;
                stage.style.transition = 'transform 220ms cubic-bezier(.25,.46,.45,.94)';
                stage.style.transform  = 'translate3d(' + target + 'px, 0px, 0px)';
                at = null;

                window.setTimeout(function () {
                    stage.style.transition = '';
                    $c.trigger('to.owl.carousel', [best, 0, true]);
                }, 240);
            }

            railEl.addEventListener('wheel', function (e) {
                if (Math.abs(e.deltaX) <= Math.abs(e.deltaY)) { return; }   // vertical → the page's
                e.preventDefault();

                if (at === null) { at = currentX(); }
                at = Math.max(limit(), Math.min(0, at - e.deltaX));

                stage.style.transition = 'none';
                stage.style.transform  = 'translate3d(' + at + 'px, 0px, 0px)';

                window.clearTimeout(settle);
                settle = window.setTimeout(snap, 110);   // the fingers have stopped
            }, { passive: false });
        })(this, $rail);

        /* Centre the row when there is not enough to scroll.
           ────────────────────────────────────────────────────────────────────
           A rail holds whatever the shop happens to have. With four occasions on
           a wide screen the tiles came to about 950px inside a 1300px strip, and
           Owl lays its stage out from the left — so they sat hard against the
           left edge with a gap of empty space beside them, looking like
           something had failed to load rather than like a deliberate row.

           Only when the content genuinely fits: the moment there is more than
           the strip can show, left alignment is correct again, because the row
           has to start at the edge for the scroll to make sense.

           Re-checked on resize, since whether it fits depends entirely on the
           window width. */
        var fitTimer = null;
        var fitWidth = window.innerWidth;
        function syncRailFit() {
            var outer = $rail.find('.owl-stage-outer')[0];
            var stage = $rail.find('.owl-stage')[0];
            if (!outer || !stage) { return; }
            // 2px of tolerance: sub-pixel rounding should not flip the state.
            var fits = stage.getBoundingClientRect().width <= outer.getBoundingClientRect().width + 2;
            $rail.toggleClass('owl-content-fits', fits);

            /* Owl writes an inline width on the stage that INCLUDES the margin
               after the final item. Centring that box therefore centres a row
               with one item-gap of dead space inside its right edge, leaving
               the tiles a gap-width off true centre — 155px of space on the
               left against 169px on the right.

               So while centred, the stage is measured to its real content and
               told that width instead. Restored to Owl's own value the moment
               the rail scrolls again, because Owl's positioning maths depends
               on that trailing margin being counted. */
            if (fits) {
                if (stage.dataset.owlWidth === undefined) {
                    stage.dataset.owlWidth = stage.style.width || '';
                }
                var content = 0;
                Array.prototype.forEach.call(stage.children, function (item, i, all) {
                    var r = item.getBoundingClientRect();
                    content += r.width;
                    if (i < all.length - 1) {
                        content += parseFloat(getComputedStyle(item).marginRight) || 0;
                    }
                });
                if (content > 0) { stage.style.width = Math.ceil(content) + 'px'; }
            } else if (stage.dataset.owlWidth !== undefined) {
                stage.style.width = stage.dataset.owlWidth;
                delete stage.dataset.owlWidth;
            }
        }
        requestAnimationFrame(syncRailFit);
        $rail.on('refreshed.owl.carousel resized.owl.carousel', syncRailFit);
        window.addEventListener('resize', function () {
            /* Width changes only. Whether the row fits depends on how WIDE the
               window is and on nothing else, but a phone fires resize when the
               address bar slides away — which happens while you are scrolling
               the page, and often while a finger is still on this strip. That
               ran a full measure of every tile and could rewrite the stage's
               inline width mid-gesture, moving the ground under Owl. Height-only
               resizes are now ignored. */
            if (window.innerWidth === fitWidth) { return; }
            fitWidth = window.innerWidth;
            clearTimeout(fitTimer);
            fitTimer = setTimeout(syncRailFit, 150);
        });
    });

    /* One helper the pages call instead of scrollBy(). Takes the rail's id (or
       the element) so the existing arrow buttons keep working unchanged. */
    window.owlRailStep = function (railOrId, direction) {
        var el = (typeof railOrId === 'string') ? document.getElementById(railOrId) : railOrId;
        if (!el) { return false; }
        var $r = jQuery(el);
        if (!$r.hasClass('owl-loaded')) { return false; }   // caller falls back to scrollBy
        $r.trigger(direction > 0 ? 'next.owl.carousel' : 'prev.owl.carousel');
        return true;
    };

    /* Stop the last slide short of empty space.
       ────────────────────────────────────────────────────────────────────────
       Under autoWidth Owl picks its last page by walking items from the right
       until they fill the viewport, then scrolls to that item's own coordinate.
       When the final item is narrower than the viewport that coordinate lies
       PAST the end of the content, so the row parks with a band of background
       showing on one side. Measured on a 456px phone, product gallery: Owl went
       to -356 where the content ends at -280, leaving 12px of gap on the left
       against 96px on the right.

       It is not a rail-by-rail bug — every autoWidth rail on the site does it:
       Shop by Occasion overshot by 92px, The Dievon Edit by 128, Collections by
       56. So the correction lives here, once, and every rail gets it.

       How it corrects: Owl fires `translate` and then, in the same synchronous
       task, writes the target transform inline on the stage and lets CSS animate
       to it. A zero-delay timer scheduled from that event therefore runs right
       after the write and before the browser paints, reads the INLINE value —
       the target, not the interpolated position getComputedStyle would give —
       and rewrites it if it is past the end. The transition is already running,
       so the row travels to the corrected place in one movement; there is no
       second hop, and Owl's own index is untouched, so the counter still reads
       "2 / 2" on the last page.

       A timer rather than requestAnimationFrame on purpose: rAF does not fire
       in a background tab, so a rail moved by a script while the tab was hidden
       would have kept the overshoot and shown it the moment the tab came back.

       Loops are skipped: the hero wraps around on purpose and has no end to
       stop at. */
    function dvClampOwlTail(railEl) {
        var $r = jQuery(railEl);
        var inst = $r.data('owl.carousel');
        if (!inst || (inst.settings && inst.settings.loop)) { return; }

        var outer = railEl.querySelector('.owl-stage-outer');
        var stage = railEl.querySelector('.owl-stage');
        if (!outer || !stage) { return; }

        /* The CONTENT width, not the stage box — Owl's inline stage width is not
           reliable to clamp against. `tail` says whether the margin after the
           final item counts. */
        function contentWidth(tail) {
            var total = 0, kids = stage.children;
            for (var i = 0; i < kids.length; i++) {
                total += kids[i].getBoundingClientRect().width;
                if (tail || i < kids.length - 1) {
                    total += parseFloat(getComputedStyle(kids[i]).marginRight) || 0;
                }
            }
            return total;
        }

        /* The last slide keeps its gutter.
           ────────────────────────────────────────────────────────────────────
           Owl puts the same margin-right on every item, the final one included,
           so the CSS gutter was already there — but stopping at the last slide's
           right EDGE scrolled straight past it, and the row ended hard against
           the glass while every other gap in it was 15px. Counting that trailing
           margin makes the strip stop 15px later, so the space after the last
           slide matches the space between them.

           Only when the row actually scrolls. If everything already fits there
           is nothing to scroll and adding the tail would hand a static row 15px
           of pointless travel. */
        function limit() {
            var view = outer.clientWidth;
            if (!view) { return null; }          // a hidden panel measures zero
            if (contentWidth(false) <= view) { return 0; }
            return Math.min(0, view - contentWidth(true));
        }

        function targetX() {
            var m = /translate3d\(\s*(-?[\d.]+)px/.exec(stage.style.transform || '');
            return m ? parseFloat(m[1]) : null;
        }

        function clamp() {
            var end = limit(), at = targetX();
            if (end === null || at === null) { return; }
            if (at < end - 0.5) {
                stage.style.transform = 'translate3d(' + end + 'px, 0px, 0px)';
            }
        }

        $r.on('translate.owl.carousel changed.owl.carousel', function () {
            window.setTimeout(clamp, 0);
        });
        $r.on('translated.owl.carousel refreshed.owl.carousel resized.owl.carousel', clamp);
        clamp();
    }
    window.dvClampOwlTail = dvClampOwlTail;

    /* Every rail on the page, whoever built it — the four on the homepage, the
       Occasion strip above, and the product gallery, which is initialised by
       pages/product.php and would otherwise need the same fix written a second
       time. Deferred to load because each page initialises its own rails at
       DOMContentLoaded and they do not all exist yet at this point. */
    window.addEventListener('load', function () {
        jQuery('.owl-carousel.owl-loaded').each(function () { dvClampOwlTail(this); });
    });
});
</script>

<?php
// Renders nothing while no analytics or advertising tracker is configured, which
// is the case today. See includes/consent_notice.php.
require_once __DIR__ . '/consent_notice.php';
?>
</body>
</html>

