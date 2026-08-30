<?php
/**
 * Dievon – The product card. One definition, used everywhere.
 *
 * This markup previously existed three times over — shop.php, home.php and the
 * "Suggested Pieces" grid on product.php — and they had drifted apart. The
 * suggested grid had lost its badge, wishlist button, compare toggle, Quick
 * View, strikethrough price and Details button, so the same product looked like
 * a different component depending on which page you were standing on.
 *
 * A partial rather than a copy means restyling the card once restyles it
 * everywhere, and a card can never silently lose a feature again.
 *
 * Usage:
 *   $card = $p;                       // the product row
 *   include __DIR__ . '/product_card.php';
 *
 * Optional, set before including:
 *   $cardCompare       — false to hide the compare toggle (default true)
 *   $cardQuickView     — false to hide Quick View (default true)
 *   $cardExtraClass    — extra class on the <article>, e.g. 'product-card-carousel'
 *   $cardFallbackBadge — badge to show when the product has none and is not on sale
 *   $cardVariant       — 'default' (the shop, category pages, search, the wishlist
 *                        and the related strip) or 'home'. See below.
 *
 * TWO DESIGNS, ONE COMPONENT.
 * ─────────────────────────────────────────────────────────────────────────────
 * The home page wants a different card from the shop: no category line, "View
 * Product" rather than "Details", and a caption that lies over the photograph
 * instead of sitting under it in the flow. That is a presentation difference,
 * not a different product — every rule about what a card may SAY is identical.
 *
 * So it is one file with a variant, not two files. Country pricing, the "From"
 * spread across sizes, the strike-through MRP and the percentage it is measured
 * against, the New badge that expires by itself, sold out, "not sold in your
 * country", the wishlist heart, the compare toggle and the "in your bag" note
 * are written once here. A second component would have meant every one of those
 * rules existing twice, and the first time one was fixed in one copy and not the
 * other, the same product would quote two different prices on two pages.
 *
 * The 'home' variant changes three things in this file — a class, the category
 * line, and the button's wording. Everything else about it is CSS.
 */

$cardProduct = $card ?? null;
if (!is_array($cardProduct) || empty($cardProduct['id'])) { return; }

$cardCompare       = $cardCompare   ?? true;
$cardQuickView     = $cardQuickView ?? true;
$cardExtraClass    = $cardExtraClass ?? '';
$cardFallbackBadge = $cardFallbackBadge ?? '';
$cardVariant       = ($cardVariant ?? '') === 'home' ? 'home' : 'default';
/* Extra attributes for the host section to hang its own state on — the New
   Arrivals gallery stamps data-na-page here so its pager can key on it. Already
   escaped by the caller; it is markup, not user input. */
$cardDataAttrs     = $cardDataAttrs ?? '';
/* The first photograph of a hero section is worth fetching early; everything
   else stays lazy. */
$cardEager         = $cardEager ?? false;
$cardIsHome        = $cardVariant === 'home';

/* Country pricing and the buyable price, both resolved before anything is
   drawn — see includes/product_price_resolve.php for why, and for the bug that
   comes of resolving them later. Shared with the New Arrivals gallery. */
require __DIR__ . '/product_price_resolve.php';

// A "discount" is only real when the compare-at price is genuinely higher.
// Equal values would render as "0% OFF", which reads as a bug.
$cardHasDiscount = $cardMrp > $cardPrice && $cardPrice > 0;

/* On a "From" card the saving is measured against the DEAREST size, not the
   cheapest.
   ────────────────────────────────────────────────────────────────────────────
   A product carries one MRP but its sizes can be priced apart. Struck beside
   the lowest of them, "₹2,200 · From ₹1,200" advertises a ₹1,000 saving that
   only exists on the smallest size — buy the ₹2,000 one and you saved ₹200.
   The strike used to be dropped entirely for that reason, which meant a
   genuinely discounted product looked full price.

   Comparing against the top of the range fixes both halves: the strike appears
   whenever every size really is below the MRP, and stays hidden when the claim
   would only hold for the cheapest. The percentage follows the same figure, so
   it states the saving the shopper is guaranteed on ANY size rather than the
   best case they might not be able to buy. */
if ($cardVaries) {
    $cardHasDiscount = $cardHasDiscount && $cardDearest > 0 && $cardMrp > $cardDearest;
}
$cardDiscountBasis   = $cardVaries ? $cardDearest : $cardPrice;
$cardDiscountPercent = $cardHasDiscount
    ? (int)round((($cardMrp - $cardDiscountBasis) / $cardMrp) * 100)
    : 0;

// ── Sold-out state ──────────────────────────────────────────
// The card had no stock awareness at all, so a sold-out garment still showed
// its price and a buyable "Details" button. Published + track_stock + no
// sellable stock left = sold out. The per-product stock is cached per request
// (one small query, shared across every rail and grid on the page).
$cardPublished = (int)($cardProduct['available'] ?? 1) === 1;
$cardSoldOut   = false;
if ($cardPublished && !empty($cardProduct['track_stock'])) {
    static $cardStockCache = [];
    $cardPid = (int)$cardProduct['id'];
    if (!array_key_exists($cardPid, $cardStockCache)) {
        $cardStockCache[$cardPid] = productSellableStock($GLOBALS['pdo'] ?? null, $cardProduct);
    }
    $cardSoldOut = $cardStockCache[$cardPid] <= 0;
}
// Not sold in the shopper's country reads the same as sold out on the card:
// no price to show, no way to buy it here.
$cardSoldOut = $cardSoldOut || $cardNotSoldHere;

$cardUrl = productUrl($cardProduct['id'], $cardProduct['name'], $cardProduct['seo_url'] ?? null);
?>
<?php /* data-product-id is what syncBagNotes() in includes/footer.php matches on.
         The "in your bag" line is filled in by that one function for BOTH card
         renderers — this partial and createProductCard() in pages/shop.php — so
         the two cannot drift apart again, and the count stays right the moment
         the bag changes without waiting for a page load. */ ?>
<article class="product-card reveal-on-scroll<?= $cardIsHome ? ' product-card--home' : '' ?><?= $cardSoldOut ? ' sold-out' : '' ?><?= $cardExtraClass !== '' ? ' ' . htmlspecialchars($cardExtraClass) : '' ?>"
         data-product-id="<?= (int)$cardProduct['id'] ?>"<?= $cardDataAttrs ?>>
    <?php if ($cardNotSoldHere): ?>
        <span class="badge-luxury badge-sold-out">Not Available Here</span>
    <?php elseif ($cardSoldOut): ?>
        <span class="badge-luxury badge-sold-out">Sold Out</span>
    <?php elseif ($cardHasDiscount): ?>
        <span class="badge-luxury badge-sale"><?= $cardDiscountPercent ?>% OFF</span>
    <?php /* productBadge(), not the raw column: "New" comes off by itself once the
             product is NEW_BADGE_DAYS old. See config/config.php. */ ?>
    <?php elseif (($cardBadge = productBadge($cardProduct)) !== ''): ?>
        <span class="badge-luxury"><?= htmlspecialchars($cardBadge) ?></span>
    <?php /* The fallback goes through productBadge() too.
             The New Arrivals carousel passes $cardFallbackBadge = 'New', which
             stamped NEW on every card it drew whatever the garment's age — so a
             product whose own New badge had just expired got the word back from
             the section around it, and the expiry looked broken. Any other
             fallback wording passes straight through; only 'New' is dated. */ ?>
    <?php elseif (($cardFallback = productBadge(['badge' => $cardFallbackBadge, 'created_at' => $cardProduct['created_at'] ?? ''])) !== ''): ?>
        <span class="badge-luxury"><?= htmlspecialchars($cardFallback) ?></span>
    <?php endif; ?>

    <div class="card-img-container">
        <a href="<?= $cardUrl ?>" class="card-img-link">
            <?php if (!empty($cardProduct['image'])):
                // Serve the WebP copy where one exists. Every uploaded image already has
                // one generated beside it (see dievonMakeWebp), and pages/product.php has
                // always used them — but this card, which is every tile on the shop, the
                // collections, the home page and the suggested grid, was still serving the
                // full-size JPEG/PNG. Across the catalogue that is 13.4 MB of originals
                // against 3.5 MB of WebP, on exactly the pages a shopper lands on first.
                $cardWebp = webpUrlIfExists('products', $cardProduct['image']);
                // productImageAlt() rather than a fifth hand-written copy of the same
                // fallback. This partial renders the card on the shop, the category
                // pages, the home rails and the related strip, so it is the single
                // biggest source of alt text on the site.
                $cardAlt  = productImageAlt($cardProduct);
            ?>
                <picture>
                    <?php if ($cardWebp): ?><source srcset="<?= htmlspecialchars($cardWebp) ?>" type="image/webp"><?php endif; ?>
                    <img src="<?= htmlspecialchars(pictureImgSrc(SITE_URL . '/uploads/products/' . $cardProduct['image'], $cardWebp)) ?>"
                         alt="<?= htmlspecialchars($cardAlt) ?>" decoding="async" class="card-img"
                         loading="<?= $cardEager ? 'eager' : 'lazy' ?>"<?= $cardEager ? ' fetchpriority="high"' : '' ?>>
                </picture>
                <?php
                /* The second shot, revealed on hover.
                   ────────────────────────────────────────────────────────────
                   Only built when the product actually has a different second
                   photograph, so a single-picture product is untouched rather
                   than cross-fading to itself.

                   src is deliberately empty and the real path sits in data-src:
                   a shop grid of 24 tiles would otherwise pull a second full
                   image per tile on first paint, doubling the page weight for
                   something most shoppers never hover. The delegated listener
                   in includes/footer.php fills it in on the first pointer that
                   arrives, and aria-hidden keeps the duplicate garment out of
                   the accessibility tree — it is the same product, already
                   named by the image above. */
                $cardHoverImg = productHoverImage($cardProduct);
                if ($cardHoverImg):
                    $cardHoverWebp = webpUrlIfExists('products', $cardHoverImg);
                ?>
                    <img class="card-img card-img-hover" alt="" aria-hidden="true" decoding="async"
                         data-src="<?= SITE_URL ?>/uploads/products/<?= htmlspecialchars($cardHoverImg) ?>"
                         <?php if ($cardHoverWebp): ?>data-webp="<?= htmlspecialchars($cardHoverWebp) ?>"<?php endif; ?>>
                <?php endif; ?>
            <?php elseif (!empty($cardProduct['video_url'])):
                // Shared resolver — see dievonVideoSrc(). Same rule everywhere a
                // product video is rendered, including the https upgrade that
                // stops a plain-http URL being blocked on a secure page.
                $cardVidSrc = dievonVideoSrc($cardProduct['video_url'] ?? '');
            ?>
                <video src="<?= htmlspecialchars($cardVidSrc) ?>" autoplay loop muted playsinline class="card-img"></video>
            <?php else: ?>
                <div class="card-img-fallback"><?= htmlspecialchars($cardProduct['emoji'] ?? '👗') ?></div>
            <?php endif; ?>
        </a>

        <?php /* Wishlist and Compare together in a row of round buttons at
                 the top right, and INSIDE the image container rather than beside
                 it: they belong to the photograph, and the container is what
                 gives them something to be positioned against.

                 Compare is still a checkbox. The control looks like a button
                 now, but toggleCompare() reads input.value and input.dataset.name
                 and dievon-compare.js clears selections by walking
                 .compare-check — so the input stays exactly as it was and only
                 its wrapper is restyled. A <button> here would have needed all
                 of that rewritten to gain nothing. */ ?>
        <div class="product-card-tools">
            <button type="button" class="product-card-wishlist-btn"
                    onclick="event.preventDefault(); handleWishlistClick(<?= (int)$cardProduct['id'] ?>, this)"
                    aria-label="Add <?= htmlspecialchars($cardProduct['name'], ENT_QUOTES) ?> to wishlist">
                <i class="fa-regular fa-heart" aria-hidden="true"></i>
            </button>

            <?php if ($cardCompare): ?>
            <label class="compare-toggle" title="Compare (up to 3)" onclick="event.stopPropagation()">
                <input type="checkbox" class="compare-check" value="<?= (int)$cardProduct['id'] ?>"
                       data-name="<?= htmlspecialchars($cardProduct['name'], ENT_QUOTES) ?>"
                       onchange="toggleCompare(this)">
                <?php /* The word is still here for a screen reader; the circle
                         shows the glyph. */ ?>
                <span class="compare-toggle-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" focusable="false"><path d="M4 8h13m0 0-3.2-3.2M17 8l-3.2 3.2M20 16H7m0 0 3.2-3.2M7 16l3.2 3.2" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>
                <span class="compare-toggle-word">Compare</span>
            </label>
            <?php endif; ?>
        </div>

        <?php if ($cardQuickView): ?>
        <button type="button" onclick="event.preventDefault(); openQuickView(<?= (int)$cardProduct['id'] ?>)" class="quick-view-btn">
            <svg class="quick-view-eye" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M2 12s3.8-6.4 10-6.4S22 12 22 12s-3.8 6.4-10 6.4S2 12 2 12Z" fill="none" stroke="currentColor" stroke-width="1.7"/><circle cx="12" cy="12" r="2.6" fill="none" stroke="currentColor" stroke-width="1.7"/></svg>
            Quick View
        </button>
        <?php endif; ?>
    </div>

    <div class="product-card-details">
        <p class="product-card-bag-note" data-bag-note hidden></p>
        <?php /* Dropped on the home page. There the photograph is doing the
                 categorising, and the line is one more thing to read on top of a
                 picture. */ ?>
        <?php if (!$cardIsHome): ?>
        <span class="product-card-category"><?= htmlspecialchars($cardProduct['category'] ?? '') ?></span>
        <?php endif; ?>
        <h3 class="product-card-title">
            <a href="<?= $cardUrl ?>"><?= htmlspecialchars($cardProduct['name']) ?></a>
        </h3>
        <?php /* $cardPrice, $cardVaries, $cardHasDiscount and
                 $cardDiscountPercent were all settled at the top of this file,
                 before the badge was drawn — see the comment there. */ ?>
        <div class="product-card-price">
            <?php if ($cardNotSoldHere): ?>
                Not available in your country
            <?php else: ?>
                <?php if ($cardVaries): ?><span class="price-from-label">From</span> <?php endif; ?>
                <span class="price-now"><?= formatPrice($cardPrice) ?></span>
                <?php /* Struck price and saving AFTER the price being charged, not
                         before it. The number a shopper is deciding on is the one
                         they will pay; leading with the old price makes them read
                         two figures to find it. */ ?>
                <?php if ($cardHasDiscount): ?>
                    <span class="price-mrp-strike"><?= formatPrice($cardMrp) ?></span>
                    <span class="price-off"><?= $cardDiscountPercent ?>% OFF</span>
                <?php endif; ?>
            <?php endif; ?>
        </div>
<?php /* One word, everywhere. It used to read "View Product" on the home page
         and "Details" on the shop, which is two names for the same click.
         Sold Out still wins over it — that is a state, not a label. */
      $cardCtaWord = 'Details'; ?>
        <?php if ($cardNotSoldHere): ?>
        <a href="<?= $cardUrl ?>" class="btn-luxury product-card-cta"><?= $cardCtaWord ?></a>
        <?php elseif ($cardSoldOut): ?>
        <span class="btn-luxury product-card-cta sold-out-cta" aria-disabled="true">Sold Out</span>
        <?php else: ?>
        <a href="<?= $cardUrl ?>" class="btn-luxury product-card-cta"><?= $cardCtaWord ?></a>
        <?php endif; ?>
    </div>
</article>
<?php
// Clear the per-card options. Without this a caller that sets $cardExtraClass once
// leaks it into every later include in the same request — the carousel class would
// end up on the grid below it.
unset($cardCompare, $cardQuickView, $cardExtraClass, $cardFallbackBadge, $cardVariant, $cardDataAttrs, $cardEager, $card);
?>
