<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

// The homepage answers at both / and /home. The sitemap submits /, so that is
// the canonical, every internal link now points there, and /home is redirected
// to it — otherwise the two addresses each declared themselves canonical and
// competed as duplicates of one another.
//
// Done here rather than in .htaccess because a root-relative RewriteRule target
// drops the subfolder prefix when the site is not at the document root: under
// MAMP it sent /DievonOrders/home to /. SITE_URL is correct in both places.
$canonicalUrl = SITE_URL . '/';

// pages/men.php renders this same template in menswear context. Without its own
// canonical, /men would declare itself a duplicate of / and Google would drop
// the entire menswear half from the index — the exact failure a real URL exists
// to avoid.
// Only the canonical here. The title and description are set further down,
// AFTER the seo_settings lookup — assigning them at this point achieved nothing,
// because line ~30 and then the database row both overwrite them.
if (!empty($GLOBALS['DIEVON_FORCE_SHOP_GENDER']) && $GLOBALS['DIEVON_FORCE_SHOP_GENDER'] === 'men') {
    $canonicalUrl = SITE_URL . '/men';
}

$homeReqPath = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && preg_match('#/home/?$#', $homeReqPath)) {
    header('Location: ' . SITE_URL . '/', true, 301);
    exit;
}
// Both lines are composed from live stock rather than written down, so the
// homepage describes the shop it actually is. Previously they were fixed
// sentences naming womenswear and, worse, advertising "designer dresses" and
// "organza dupattas" that the catalogue has never carried — a claim to Google
// that nobody would think to revisit. Adding menswear now updates this by
// itself; an explicit value in Admin › SEO still overrides both (below).
$pageTitle = dievonHomeTitle($pdo);
$metaDescription = dievonHomeDescription($pdo);
try {
    $seoStmt = $pdo->prepare("SELECT meta_title, meta_description, og_image FROM seo_settings WHERE page_slug = 'home'");
    $seoStmt->execute();
    if ($seoRow = $seoStmt->fetch()) {
        if (!empty($seoRow['meta_title'])) { $pageTitle = $seoRow['meta_title']; }
        if (!empty($seoRow['meta_description'])) { $metaDescription = $seoRow['meta_description']; }
        if (!empty($seoRow['og_image'])) { $ogImage = cacheBustedUploadUrl(SITE_URL . '/uploads/gallery/' . $seoRow['og_image']); }
    }
} catch (PDOException $e) {}

// Menswear title, applied LAST so nothing above can undo it.
//
// The seo_settings row read just above belongs to the homepage, which is the
// womenswear one — its title is literally "Luxury Women's Ethnic & Contemporary
// Fashion". Left alone, /men published that as its browser tab and its search
// result headline, so the menswear address described itself as womenswear.
if (!empty($GLOBALS['DIEVON_FORCE_SHOP_GENDER']) && $GLOBALS['DIEVON_FORCE_SHOP_GENDER'] === 'men') {
    // Named, not just "Menswear". A 17-character title wastes most of the ~60
    // characters a search result gives you, and says nothing about what is
    // actually sold — the words alongside it are what someone scanning results
    // matches against.
    //
    // The categories are read from live men's stock rather than typed. The
    // hard-coded version said "shirts, trousers and tailoring" — and nothing in
    // this catalogue has ever been called tailoring, so /men advertised a
    // department that does not exist. It also matched pages/shop.php word for
    // word, which put one identical sentence on two separate indexable URLs.
    $menCats  = liveCategoryNamesForGender($pdo, 'men');
    $menList  = $menCats ? humanList($menCats) : 'shirts and trousers';

    $pageTitle       = 'Menswear — ' . trimToLength(compactTitleList($menCats ?: ['Shirts', 'Trousers']), 42);
    $metaDescription = trimToLength(
        'Shop menswear at ' . SHOP_NAME . ' — ' . $menList
        . ', with detailed size guides and easy returns across India.', 155);

    // The share image was the womenswear lookbook, because seo_settings holds one
    // og_image for the homepage and /men renders that same template. A menswear
    // link posted to WhatsApp or Instagram previewed a woman in a kurti.
    // Uses the first men's banner, and only overrides when one genuinely exists.
    try {
        $ogSt = $pdo->prepare(
            "SELECT image FROM banners
              WHERE status = 'Active' AND image <> '' AND (gender = 'men' OR gender = 'both')
           ORDER BY sort_order ASC, id ASC LIMIT 1"
        );
        $ogSt->execute();
        $ogFile = (string)$ogSt->fetchColumn();
        if ($ogFile !== '' && file_exists(__DIR__ . '/../uploads/products/' . $ogFile)) {
            $ogImage = SITE_URL . '/uploads/products/' . $ogFile;
        }
    } catch (PDOException $e) {
        // gender column not added yet — keep the existing image
    }
}

require_once __DIR__ . '/../includes/header.php';

// Every product row on this page is limited to the side of the shop being
// browsed. Without it the womenswear homepage listed men's shirts under New
// Arrivals — the switch changed the menu while the products underneath stayed
// mixed, which is worse than no switch because it looks deliberate.
//
// shopGenderSqlFilter() returns an empty string while only one audience trades,
// so all six queries below are byte-identical to before until menswear is live.
$homeGenderSql = function_exists('shopGenderSqlFilter') ? shopGenderSqlFilter() : '';

// Archived stock must not be merchandised.
//
// All six rails below tested `available = 1` and nothing else, while the shop
// grid, the search, the sitemap and the Merchant feed all pair that with an
// is_deleted guard. A row archived while still flagged available therefore
// appeared ONLY here — on the shop's most-visited page — with a price, a badge,
// a wishlist heart, Quick View and a Details link, and nowhere else on the site.
// Measured: product 249 (available = 1, is_deleted = 1) rendered in New Arrivals
// under a "New" badge while /shop, /search and every collection correctly hid it.
//
// One constant so the six queries cannot drift apart again.
$homeLiveSql = " AND (is_deleted = 0 OR is_deleted IS NULL)";

/* The homepage's product picks — one garment, one section.
   ────────────────────────────────────────────────────────────────────────────
   Each of the three sections used to fall back to "any live product" when its
   badge found nothing: newest 8 for New Arrivals, dearest 4 for Best Sellers,
   cheapest 4 for Trending. Across a full catalogue those three sets barely
   overlap. Across the handful of products a shop actually has on opening day
   they returned THE SAME garments, so scrolling the homepage meant meeting the
   same few pieces three times under three different headings — which reads as a
   shop with nothing in it, the exact opposite of what the sections are for.

   So the badge picks are claimed first, in the order the sections appear, since
   those are choices somebody made deliberately. Then each section tops up from
   whatever no other section has taken. A section left with nothing is not drawn
   at all — see the guards further down. One fewer band beats a titled empty one.

   As the catalogue grows the sections fill up on their own and this stops having
   anything to do. */
$homeSeen = [];

/** Take up to $limit rows nobody has used yet, and mark them used. */
$homeClaim = function (array $rows, int $limit) use (&$homeSeen): array {
    $kept = [];
    if ($limit <= 0) { return $kept; }
    foreach ($rows as $row) {
        if (count($kept) >= $limit) { break; }
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0 || isset($homeSeen[$id])) { continue; }
        $homeSeen[$id] = true;
        $kept[] = $row;
    }
    return $kept;
};

// Everything this page may draw on, fetched once instead of three times.
$homePool = [];
try {
    $homePool = $pdo->query("SELECT * FROM products WHERE available = 1{$homeLiveSql}{$homeGenderSql} ORDER BY id DESC LIMIT 40")->fetchAll();
} catch (PDOException $e) {}

$newArrivals = [];
try {
    $newArrivals = $pdo->query("SELECT * FROM products WHERE available = 1{$homeLiveSql} AND badge = 'New'{$homeGenderSql} ORDER BY id DESC LIMIT 8")->fetchAll();
} catch (PDOException $e) {}

// Latest Chronicles — the three newest published articles.
//
// These three cards used to be hand-typed HTML pointing at /blog-single?id=1,
// 2 and 3, with titles typed beside the links rather than read from the posts.
// The two never agreed: the card headed "Hand-Embroidered Zari: Preserving
// Heritage Craft" opened an article called "Calfskin Sourcing: Preserving the
// Tuscan Heritage". Once those three off-brand posts were unpublished, all
// three cards became hard 404s. Reading the posts is the only arrangement in
// which a card cannot advertise an article it does not open.
$latestPosts = [];
try {
    $latestPosts = $pdo->query(
        "SELECT id, title, category, image, excerpt, published_date
           FROM blog_posts
          WHERE status = 'Published'" . blogGenderSqlFilter($pdo) . "
       ORDER BY published_date DESC, id DESC
          LIMIT 3"
    )->fetchAll();
} catch (PDOException $e) {}

$bestSellers = [];
$trending    = [];
try {
    /* Twelve, not four. These two lists are a slider now rather than a fixed
       four-up grid — four is what is ON SCREEN at desktop, and a slider whose
       total equals its window has nothing to slide and two arrows that are both
       dead on arrival. The mockup's own counter reads 01-04 / 12. The section
       still renders correctly at any count from one upward: the arrows, the
       counter and the progress bar all read the real total. */
    $bestSellers = $pdo->query("SELECT * FROM products WHERE available = 1{$homeLiveSql} AND badge = 'Best Seller'{$homeGenderSql} ORDER BY id ASC LIMIT 12")->fetchAll();
    $trending    = $pdo->query("SELECT * FROM products WHERE available = 1{$homeLiveSql} AND badge = 'Hot'{$homeGenderSql} ORDER BY id ASC LIMIT 12")->fetchAll();
} catch (PDOException $e) {}

/* Claim the deliberate picks in page order, then top each section up from what
   is left — newest for arrivals, dearest for best sellers, keenest for trending,
   which is the ordering each section had before. Nothing is drawn twice. */
$newArrivals = $homeClaim($newArrivals, 8);
$bestSellers = $homeClaim($bestSellers, 12);
$trending    = $homeClaim($trending,    12);

$byNewest = $homePool;                                          // already id DESC
$byDearest = $homePool;
usort($byDearest, fn($a, $b) => (float)($b['price'] ?? 0) <=> (float)($a['price'] ?? 0));
$byKeenest = $homePool;
usort($byKeenest, fn($a, $b) => (float)($a['price'] ?? 0) <=> (float)($b['price'] ?? 0));

$newArrivals = array_merge($newArrivals, $homeClaim($byNewest,  8 - count($newArrivals)));
$bestSellers = array_merge($bestSellers, $homeClaim($byDearest, 12 - count($bestSellers)));
$trending    = array_merge($trending,    $homeClaim($byKeenest, 12 - count($trending)));

// Fetch dynamic hero slider banners
$heroBanners = [];
try {
    $heroBanners = $pdo->query("SELECT * FROM banners WHERE status = 'Active' ORDER BY sort_order ASC, id DESC")->fetchAll();

    // Show only the banners meant for the side being browsed. Filtered in PHP
    // rather than SQL because the gender column may not exist yet on an install
    // that has not run update_new_database.php — naming it in the query there
    // would fail the whole thing and leave the homepage with no hero at all.
    //
    // A banner with no gender recorded counts as womenswear, which is what every
    // banner on this shop was when the column was introduced.
    // Occasion Edit banners are a different placement on this same page, so they
    // must not also appear in the slider at the top. Filtered in PHP, not SQL,
    // for the same reason as gender: the column may not exist yet.
    $heroBanners = array_values(array_filter($heroBanners, function ($b) {
        return (string)($b['placement'] ?? 'hero') !== 'occasion';
    }));

    if (function_exists('shopGenderSelectorEnabled') && shopGenderSelectorEnabled()) {
        $wantGender = currentShopGender();
        $heroBanners = array_values(array_filter($heroBanners, function ($b) use ($wantGender) {
            $g = strtolower(trim((string)($b['gender'] ?? 'women')));
            if ($g === '' ) { $g = 'women'; }
            return $g === 'both' || $g === $wantGender;
        }));
    }
} catch (PDOException $e) {}

/* How many slides this page will actually render.
 *
 * The three branches below decide it: uploaded banners if there are any, one
 * honest menswear slide when menswear is trading without banners of its own,
 * otherwise the three default womenswear slides. The controls have to be
 * counted from the SAME decision, and they were not — the dots hard-coded a
 * fallback of 3 and the arrows were printed unconditionally.
 *
 * Measured on /men: one slide, three dots and two arrows. Two of those dots
 * pointed at nothing and both arrows cycled a single slide back onto itself, so
 * the shopper clicked a control and the page appeared frozen.
 */
$heroSlideCount = !empty($heroBanners)
    ? count($heroBanners)
    : ((function_exists('currentShopGender') && currentShopGender() === 'men') ? 1 : 3);
?>


<!-- ==================== 1. LUXURY HERO SLIDER ==================== -->

<section class="hero-slider-section">
    <div class="slider-container" id="heroSlider">
        <?php if (!empty($heroBanners)): ?>
            <?php foreach ($heroBanners as $idx => $banner): 
                // One resolver, shared with the admin screen — see
                // bannerImageLocation(). This block and admin/banners.php each
                // had their own copy searching products then gallery, and a
                // third was needed the moment banners got their own folder.
                $imgFile = $banner['image'] ?? '';
                $imgLoc  = bannerImageLocation($imgFile);
                if ($imgLoc) {
                    $imgDir = $imgLoc['dir'];
                    $imgUrl = SITE_URL . '/uploads/' . $imgDir . '/' . $imgLoc['file'];
                } else {
                    $imgDir = 'gallery';
                    $imgUrl = SITE_URL . '/uploads/gallery/lookbook_' . (($idx % 3) + 1) . '.png';
                }
                $imgBase = basename(parse_url($imgUrl, PHP_URL_PATH) ?: $imgFile);
                $imgWebp = webpUrlIfExists($imgDir, $imgBase);
            ?>
                <?php
                // A real <img>, not a CSS background.
                //
                // Two things were wrong with the background. It served the ORIGINAL
                // file and never looked for the .webp twin the uploader had already
                // made — the three live banners are 2,317KB, 848KB and 1,748KB as PNG
                // against 273KB and 259KB as WebP, so the homepage was pulling roughly
                // 4.9MB of hero image on a phone. And a background cannot begin
                // downloading until the stylesheet has parsed, while this is the
                // largest thing on the page and the one Google times (LCP).
                //
                // <picture> serves WebP where the browser takes it and the original
                // everywhere else. The first slide carries fetchpriority=high because
                // it is on screen immediately.
                //
                // Every slide is loading="eager", NOT lazy. The slider is a filmstrip:
                // slides 2+ sit outside the viewport horizontally and are brought in by
                // a CSS transform, which is not a scroll — so a browser applying lazy
                // loading strictly never registers them as approaching the viewport and
                // never fetches them. The result is a first slide that works and blank
                // panels behind it, and it differs by browser because each implements
                // the lazy heuristic differently: it showed in Chrome while Safari,
                // holding an older cached stylesheet, looked fine.
                //
                // Lazy loading is for images DOWN the page. Nothing in the hero is
                // below the fold — it is the first thing on screen.
                //
                // z-index 0 sits under .slide-panel (2) and .slide-content, so
                // the scrim and the text still layer exactly as before.
                ?>
                <div class="slide slide-<?= ($idx % 3) + 1 ?> slide-has-img">
                    <?php /* The words lie ON the photograph, in its own negative space.
                             ────────────────────────────────────────────────────────
                             This was briefly a split — an ivory panel beside the
                             picture — because legibility here is measurable rather
                             than a matter of taste: of the two banners live on the
                             shop today, the text zone of one averages luminance 78
                             and the other 196, so no single ink colour reads on both.
                             A panel made legibility independent of the upload.

                             The overlay is what was asked for, and it is the better
                             of the two when the scrim is dark rather than light. A
                             light wash only lifts dark type off a banner that is
                             already darker than the ink; a dark wash carries white
                             type over anything, however bright the upload. That is
                             the same bet dievon.com makes, and .slide-panel now uses
                             live's own gradient stops — see style.css. */ ?>
                    <div class="slide-media">
                        <picture>
                            <?php if ($imgWebp): ?><source srcset="<?= htmlspecialchars(cacheBustedUploadUrl($imgWebp)) ?>" type="image/webp"><?php endif; ?>
                            <img src="<?= htmlspecialchars(cacheBustedUploadUrl(pictureImgSrc($imgUrl, $imgWebp))) ?>"
                                 alt="<?= htmlspecialchars($banner['title'] ?? 'Dievon collection') ?>"
                                 class="slide-img"
                                 <?= $idx === 0 ? 'fetchpriority="high" decoding="async"' : 'decoding="async"' ?>
                                 loading="eager">
                        </picture>
                    </div>
                    <div class="slide-panel">
                    <div class="slide-content">
                        <span class="slide-eyebrow">New Season</span>
                        <?php
                        // One H1 per page, on the first slide only. This loop used to
                        // print <h1> for every banner, so a shop with three banners
                        // published three competing "main headings" — and which one a
                        // crawler saw first depended on sort_order, not on meaning.
                        // The hand-written fallback slides below already do it this way;
                        // the database-driven path had simply drifted from them.
                        //
                        // Purely a markup change: .slide-content h1 and .slide-content h2
                        // are declared together in style.css with identical !important
                        // rules, so every slide still looks exactly as it did.
                        ?>
                        <?php if ($idx === 0): ?>
                            <h1><?= htmlspecialchars($banner['title']) ?></h1>
                        <?php else: ?>
                            <h2><?= htmlspecialchars($banner['title']) ?></h2>
                        <?php endif; ?>
                        <p><?= htmlspecialchars($banner['subtitle'] ?? 'Curated luxury fashion ensembles crafted for elegant moods.') ?></p>
                        <?php
                        // Banner links are authored in the admin panel and are usually stored
                        // relative (e.g. "shop?category=Kurtis"). A relative href resolves
                        // against the current directory, so it 404s on any multi-segment route
                        // such as /product/<slug>-<id>. Make it absolute unless it is already
                        // an absolute URL or root-relative path.
                        $bannerLink = trim($banner['link'] ?? '') ?: 'shop';
                        /* A category NAME typed here is only as good as the category
                           keeping that name. "shop?category=kurta" was authored when a
                           category was called that; it has since been renamed, and the
                           banner's Shop Now button had been answering 404 ever since --
                           on the hero, on the landing page. Names in this field are now
                           resolved through the category table, which maps them to the
                           clean /collections/<slug> address and falls back to the full
                           shop when the name no longer exists. Every other kind of link
                           the field accepts is passed through untouched. */
                        if (preg_match('~^/?shop\?category=([^&]+)$~i', $bannerLink, $bm)) {
                            $bannerLink = categoryUrlByName($pdo, urldecode($bm[1]));
                        }
                        // `~` delimiter, not `#` — the pattern itself contains a literal `#`
                        // (anchor links), which would terminate a `#`-delimited regex early.
                        if (!preg_match('~^(https?://|//|/|#|mailto:|tel:)~i', $bannerLink)) {
                            $bannerLink = SITE_URL . '/' . ltrim($bannerLink, '/');
                        }
                        ?>
                        <?php /* Two ways in, as the brief asks. The primary keeps the
                                 address the owner typed into the banner in admin; the
                                 secondary is always the full shop, so a visitor who does
                                 not want that particular edition still has somewhere to
                                 go. Neither is new behaviour for the admin — the link
                                 field means exactly what it meant before. */ ?>
                        <div class="slide-actions">
                            <a href="<?= htmlspecialchars($bannerLink) ?>" class="btn-hero-primary">Shop Now</a>
                            <a href="<?= SITE_URL ?>/shop" class="btn-hero-ghost">Explore Collection</a>
                        </div>
                    </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php elseif (function_exists('currentShopGender') && currentShopGender() === 'men'): ?>
            <?php /* Menswear with no banners of its own yet.
                     The womenswear default slides below cannot stand in for it: their
                     first slide carries the page's <h1>, so /men was publishing "The
                     Silk Kurtis" as its primary heading — the one line Google reads as
                     what the page is about, on the menswear address. One honest slide
                     naming the section beats three advertising the wrong half of the
                     shop, and it disappears the moment a men's banner is uploaded. */ ?>
            <div class="slide slide-1">
                <div class="slide-media" style="background-image: url('<?= lookbookUrl(2) ?>');"></div>
                <div class="slide-panel">
                <div class="slide-content">
                    <span class="slide-eyebrow">New Season</span>
                    <h1>Dievon Men</h1>
                    <p>Shirts, trousers and tailoring, cut clean and made to be worn often.</p>
                    <div class="slide-actions">
                        <a href="<?= SITE_URL ?>/shop" class="btn-hero-primary">Shop Now</a>
                        <a href="<?= SITE_URL ?>/shop" class="btn-hero-ghost">Explore Collection</a>
                    </div>
                </div>
                </div>
            </div>
        <?php else: ?>
            <!-- Default Curated Slides -->
            <?php /* The brief's own copy, on the slide that carries the page's h1.
                     This only renders when the shop has no banners of its own — on a
                     stocked shop the admin slides above replace it entirely — so it is
                     the empty state, not the usual one. Worth knowing that "Timeless
                     Elegance" is a weaker h1 for search than the line it replaces
                     ("Luxury Indian Womenswear — Hand-Finished Fashion"); it is the
                     copy that was asked for, and it is only ever seen by a shop with
                     nothing uploaded. */ ?>
            <div class="slide slide-1">
                <div class="slide-media"></div>
                <div class="slide-panel">
                <div class="slide-content">
                    <span class="slide-eyebrow">New Season</span>
                    <h1>Timeless Elegance</h1>
                    <p>Graceful silhouettes. Exquisite details. Crafted for every moment.</p>
                    <div class="slide-actions">
                        <a href="<?= SITE_URL ?>/shop" class="btn-hero-primary">Shop Now</a>
                        <a href="<?= htmlspecialchars(categoryUrlByName($pdo, 'Kurtis')) ?>" class="btn-hero-ghost">Explore Collection</a>
                    </div>
                </div>
                </div>
            </div>
            <div class="slide slide-2">
                <div class="slide-media"></div>
                <div class="slide-panel">
                <div class="slide-content">
                    <span class="slide-eyebrow">New Season</span>
                    <h2>3-Piece Suits</h2>
                    <p>Elegant tailored brocades and soft raw silks. Perfect for premium celebrations.</p>
                    <div class="slide-actions">
                        <a href="<?= SITE_URL ?>/shop" class="btn-hero-primary">Shop Now</a>
                        <a href="<?= htmlspecialchars(categoryUrlByName($pdo, '3 Piece Suits')) ?>" class="btn-hero-ghost">Explore Collection</a>
                    </div>
                </div>
                </div>
            </div>
            <div class="slide slide-3">
                <div class="slide-media"></div>
                <div class="slide-panel">
                <div class="slide-content">
                    <span class="slide-eyebrow">New Season</span>
                    <h2>Coord Sets</h2>
                    <p>Luxurious satin wide-legs and tailored organic linens. Designed for refined modern loungewear.</p>
                    <div class="slide-actions">
                        <a href="<?= SITE_URL ?>/shop" class="btn-hero-primary">Shop Now</a>
                        <a href="<?= htmlspecialchars(categoryUrlByName($pdo, 'Coord Sets')) ?>" class="btn-hero-ghost">Explore Collection</a>
                    </div>
                </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <?php /* Controls only exist when there is somewhere to go. A single slide
             needs none, and zero slides needs neither markup nor a control
             floating over an empty band.

             The shared rail pager — see .dv-pager in style.css — so the hero is
             navigated the same way as Collections, New Arrivals, Trending and
             Shop by Occasion. It replaces two separate controls this band used
             to carry: a pair of arrows pinned left and right over the
             photograph, which were deleted outright below 480px, and a centred
             row of dots.

             --onmedia because the placement rule cannot be the same here. Every
             other rail puts the pill in normal flow underneath; the hero is a
             full-bleed photograph with the next section hard against it, so
             underneath means on top of Collections. It sits over the image
             instead, in the same bottom-right corner, with a shadow to hold its
             edge against whatever banner is uploaded. */ ?>
    <?php if ($heroSlideCount > 1): ?>
    <div class="dv-pager dv-pager--onmedia" data-pager-mode="count" data-pager="hero">
      <div class="dv-pager-pill">
        <button type="button" class="dv-pager-btn" data-pager-step="-1" aria-label="Previous slide">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M15 5 8 12l7 7" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
        <span class="dv-pager-count" data-pager-count aria-hidden="true">1&#8202;/&#8202;<?= (int)$heroSlideCount ?></span>
        <button type="button" class="dv-pager-btn" data-pager-step="1" aria-label="Next slide">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m9 5 7 7-7 7" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
      </div>
      <span class="dv-sr-only" role="status" aria-live="polite" aria-atomic="true" data-pager-status></span>
    </div>
    <?php endif; ?>
</section>

<!-- ==================== 2. FEATURED CATEGORIES ==================== -->
<section class="featured-categories section-space">
    <div class="container">
        <div class="section-title-wrapper reveal-on-scroll">
            <span class="editorial-label">Curated Ensembles</span>
            <h2 class="section-title">Dievon Collections</h2>
        </div>
        <?php
            // Fetch ONLY main parent categories for the collections section
            $stmtCat = $pdo->query("SELECT * FROM categories WHERE parent_id = 0 OR parent_id IS NULL ORDER BY sort_order ASC");
            $allCategories = $stmtCat->fetchAll(PDO::FETCH_ASSOC);

            // Two filters this list was missing, both of which the header, footer
            // and sitemap already applied — so this grid was the one place on the
            // site still advertising collections that lead nowhere.
            //
            //  1. Empty collections. $categoryHasStock is built in includes/header.php,
            //     which has already run by this point, and counts a category's whole
            //     subtree so a parent whose stock all sits in its children still shows.
            //  2. The other side of the shop. Without it a menswear tile sat between
            //     Kurtis and 3 Piece Suits, which reads as a mistake because it is one:
            //     an audience is not a garment type.
            $allCategories = array_values(array_filter($allCategories, function ($c) use ($categoryHasStock) {
                $id = (int)$c['id'];
                if (!empty($categoryHasStock) && empty($categoryHasStock[$id])) { return false; }
                if (function_exists('shopGenderSelectorEnabled') && shopGenderSelectorEnabled()) {
                    return categoryMatchesShopGender($id);
                }
                return true;
            }));

            /* Six tiles, and a button for the rest.
               ────────────────────────────────────────────────────────────────
               Six is the mosaic — one large tile, three beside it, two beneath.
               It was briefly uncapped, continuing into aligned rows of three,
               and that works, but it makes the section as tall as the catalogue
               is wide: twelve collections turned this into four rows and pushed
               everything below it off the first two screens.

               So the composition IS the limit. Six fill it exactly, and a
               seventh collection is reached through the button underneath
               rather than by making the section taller.

               Counted BEFORE the slice, because the button has to say how many
               there are in total; asking after the cut would always say six. */
            $catTotalCount = count($allCategories);
            $allCategories = array_slice($allCategories, 0, 6);
            $catShownCount = count($allCategories);

?>
        <?php /* data-count is what makes this work with the two collections the
                 shop has today as well as the six the design was drawn for.
                 A mosaic is an arrangement of a KNOWN number of tiles; hand it
                 two and the reference layout leaves a tall feature beside one
                 small tile and two thirds of the row empty. The CSS keyed to
                 this attribute picks a composition that is whole at every count
                 from one upward — see assets/css/style.css.

                 The list has to be built BEFORE this div, not inside it: the
                 attribute is written as the tag is emitted, so a count taken
                 from a variable the loop below fills is a count of nothing. */ ?>
        <div class="home-categories-grid" data-count="<?= (int)$catShownCount ?>">
            <?php
            foreach($allCategories as $catIndex => $cat):
                // The collection's OWN image first, then a product photo, then the
                // placeholder.
                //
                // This used to go straight to "SELECT image FROM products WHERE
                // category = :cat LIMIT 1", which meant three things:
                //
                //  1. categories.image was ignored entirely. An owner could set a
                //     proper collection photograph in the Categories screen and the
                //     home page would keep showing a product shot instead — there
                //     was no way to control how a collection looked here.
                //  2. LIMIT 1 with no ORDER BY lets MySQL return whichever row it
                //     likes, so adding an unrelated product could silently swap the
                //     tile's photograph with nothing having been changed on purpose.
                //  3. It matched on the legacy `category` name string only, so a
                //     product filed by category_id with a blank name contributed
                //     nothing and a well-stocked collection fell back to the
                //     placeholder.
                $catImgSrc = SITE_URL . '/assets/images/placeholder.png';
                /* The WebP twin generateWebpCopy() already wrote beside the upload.
                   These tiles are the heaviest images on the page -- a collection
                   photograph is 2.2MB as PNG and 163KB as WebP, the same pixels
                   either way -- and this <img> asked for the PNG every time, so the
                   twin sat on disk unused. Null for the placeholder, which has no
                   twin and does not need one. */
                $catImgWebp = null;
                $ownImage  = trim((string)($cat['image'] ?? ''));

                if ($ownImage !== '') {
                    // Same folder the category editor and the OG tags use.
                    $catImgSrc = SITE_URL . '/uploads/gallery/' . rawurlencode($ownImage);
                    $catImgWebp = webpUrlIfExists('gallery', $ownImage);
                } else {
                    // Newest product with a photo, matched by id OR name so both the
                    // current and the legacy filing work. Stable ordering, so the
                    // tile only changes when the catalogue genuinely changes.
                    $stmtProdImg = $pdo->prepare(
                        "SELECT image FROM products
                          WHERE (category_id = :cid OR category = :cat)
                            AND image IS NOT NULL AND image <> ''
                            AND available = 1 AND (is_deleted = 0 OR is_deleted IS NULL)
                          ORDER BY id DESC LIMIT 1"
                    );
                    $stmtProdImg->execute(['cid' => (int)$cat['id'], 'cat' => $cat['name']]);
                    $prodImg = $stmtProdImg->fetchColumn();
                    if ($prodImg) {
                        $catImgSrc = SITE_URL . '/uploads/products/' . rawurlencode((string)$prodImg);
                        $catImgWebp = webpUrlIfExists('products', (string)$prodImg);
                    }
                }
            ?>
            <?php /* The first collection takes the large tile. It is first because
                     sort_order says so — the same order the header menu and the
                     footer use — so the shop decides what leads here by ordering
                     collections in admin, not by anything written in this file.

                     width/height are the intrinsic ratio, not a size: the tile
                     controls the box and object-fit crops into it. They are here
                     so the browser reserves the space before the photograph
                     arrives, which is what stops the section jumping as the lazy
                     images land. */ ?>
            <a href="<?= htmlspecialchars(categoryUrlByName($pdo, $cat['name'])) ?>"
               class="category-card zoom-box<?= $catIndex === 0 ? ' is-feature' : '' ?>">
                <picture>
                    <?php if ($catImgWebp): ?><source srcset="<?= htmlspecialchars($catImgWebp) ?>" type="image/webp"><?php endif; ?>
                    <img class="zoom-img" src="<?= htmlspecialchars(pictureImgSrc($catImgSrc, $catImgWebp)) ?>"
                         alt="<?= htmlspecialchars($cat['name']) ?> collection"
                         width="800" height="1000" loading="lazy" decoding="async">
                </picture>
                <div class="category-card-overlay">
                    <div>
                        <h3><?= htmlspecialchars($cat['name']) ?></h3>
                        <span class="category-card-link">View Collection <i class="fa-solid fa-arrow-right-long"></i></span>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>

        <?php /* Only when collections were genuinely held back. A permanent
                 "View All" under a row that already shows everything promises
                 more than the shop has, which is the same fault as a Read more
                 that reveals nothing. */ ?>
        <?php /* Navigation for the collections rail. Below 768px this is a rail
                 with its scrollbar hidden, so without a control it is reachable
                 by touch and by nothing else — no trackpad, no keyboard, no
                 narrow desktop window. Above 768px the same element is a mosaic
                 grid that shows everything, and the engine retires the control
                 because the rail measures as unable to move. Measured, not
                 counted: six collections at 42% each may fit inside a wide
                 phone, and a control that does nothing is the same fault as a
                 Read more that reveals nothing. */ ?>
        <?php /* The shared rail pager — see .dv-pager in style.css. No counter:
                 this rail slides, it does not page through sets, and a page
                 number under a row of collections reframes browsing as a chore. */ ?>
        <div class="dv-pager" data-pager-mode="count" data-pager="cat" hidden>
          <div class="dv-pager-pill">
            <button type="button" class="dv-pager-btn" data-pager-step="-1" aria-label="Previous collections">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M15 5 8 12l7 7" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <span class="dv-pager-count" data-pager-count aria-hidden="true">1&#8202;/&#8202;1</span>
            <button type="button" class="dv-pager-btn" data-pager-step="1" aria-label="Next collections">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m9 5 7 7-7 7" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
          </div>
          <span class="dv-sr-only" role="status" aria-live="polite" aria-atomic="true" data-pager-status></span>
        </div>

        <?php if ($catTotalCount > count($allCategories)): ?>
        <div class="home-categories-more">
            <a href="<?= SITE_URL ?>/shop" class="btn-luxury-outline">
                View All <?= (int)$catTotalCount ?> Collections
            </a>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- ==================== 3. NEW ARRIVALS ==================== -->
<?php /* Nothing to show, nothing drawn. The heading, the carousel arrows and the
         section's own vertical space used to print whatever happened — so a shop
         with no products served a tall empty band titled "New Arrivals", which is
         the same mistake Shop by Occasion below already had fixed. */ ?>
<?php /* The hover images and price ranges for EVERY grid on this page, primed in
         one pair of queries rather than a pair per tile — see
         productHoverImage(). Hoisted above the New Arrivals guard: it used to
         sit inside that section, so a shop with no new arrivals skipped the
         priming and Best Sellers below fell back to a query per card. */
      productHoverImagePrime($homeGrids = array_merge($newArrivals ?? [], $bestSellers ?? [], $trending ?? []));
      productPriceRangePrime($homeGrids); ?>

<?php if (!empty($newArrivals)): ?>
<?php
/* ── New Arrivals: an editorial gallery, not a rail ──────────────────────────
   Four garments side by side, the first shown large and the other three as
   narrow panels. Hovering or focusing a panel expands it and collapses the one
   that was open. The expanding itself is flex-basis and a transition — no
   library, nothing to initialise, and it survives a script that fails to load.

   Four ACROSS, because that is the composition: a fifth panel would make every
   narrow one 12% of the screen, which is a stripe rather than a garment. More
   than four arrivals are not dropped, they are paged — the desktop shows one
   set of four at a time and the arrows top right swap in the next set. Twelve
   is the ceiling; past that this stops being an editorial opener and starts
   being the shop page, which already exists.

   On a phone none of that applies. There is no hover to expand with and no
   room for four across, so the same panels become one full-bleed swipe rail
   holding every arrival — see the touch block in style.css. That is why the
   off-page panels are marked with a class rather than the hidden attribute:
   .na-off is only honoured above 1024px, so the rail keeps all of them. */
$naItems    = array_slice($newArrivals, 0, 12);
$naPerPage  = 4;
/* Stops, not pages. The four on screen are a window that moves by ONE garment,
   so the number of positions is one per garment that can start the row — eight
   products showing four gives five stops, not two pages. Computed here as well
   as in the script so the counter is right on the first paint instead of saying
   "1 / 2" until JavaScript corrects it. */
$naPages    = max(1, count($naItems) - $naPerPage + 1);
/* data-count sizes the panels for the set actually on screen, so it is the
   size of the FIRST page, not the total. With JavaScript off that is the only
   page there is, and the four still add to exactly 100%. */
$naFirstPageCount = min(count($naItems), $naPerPage);
?>
<section class="new-arrivals-section section-space">
    <?php /* The heading is the same .section-title-wrapper every other section
             on this page uses, inside .container, above the photographs. It was
             laid over them at first, in the top left of the open panel. Two
             things were wrong with that. It read as part of the picture rather
             than as the page's own voice, and it needed a wash under it to stay
             legible — white type over garment photography shot on pale studio
             walls measured 2.17:1. Off the picture, the type is burgundy on the
             page ground, the wash is gone, and the open panel is no longer
             dimmed in the corner to rescue two words. */ ?>
    <div class="container">
        <div class="section-title-wrapper reveal-on-scroll">
            <span class="editorial-label">Latest Creations</span>
            <h2 class="section-title">New Arrivals</h2>
        </div>
    </div>

    <?php /* The gallery itself stays outside .container — it runs edge to edge,
             and .container is max-width 1440 centred.

             The arrows are a sibling of the gallery rather than a child, held
             beside it by .na-stage. That is not tidiness: .na-gallery:hover
             collapses the open panel, and an arrow inside the gallery would
             fire that rule while being the thing hovered — so no panel would be
             expanded and the row would come up a third short. Outside the
             gallery, hovering an arrow is not hovering the gallery. */ ?>
    <div class="na-stage">

    <div class="na-gallery" data-count="<?= $naFirstPageCount ?>" data-na-pages="<?= $naPages ?>" data-na-per-page="<?= $naPerPage ?>">

        <?php foreach ($naItems as $naIndex => $naProduct):
            /* One panel per page is the open one, so it is the first of each set
               of four, not only the very first product. */
            /* Owl owns which panels are on screen now, so nothing is hidden
               here and no panel is the permanently-open one: every item must be
               in the DOM and measurable when the carousel initialises. */
            $naClasses = 'na-panel';
            /* Stamped on the card by the partial's $cardDataAttrs, because the
               paging script reads it to decide which set a panel belongs to. */
            $cardDataAttrs = ' data-na-page="' . intdiv($naIndex, $naPerPage) . '"';
        ?>
        <?php
        /* The shared product card in its 'home' variant — the same component the
           photo fan and the shop grid use. It was bespoke markup here, which
           meant the price rules, the badge rule and the sold-out state existed
           twice on one page; now the panel is the card and this section supplies
           only the shell: how wide a panel is, when it expands, and where the
           caption sits.

           The panel IS the card rather than a wrapper around it. The paging
           script keys on .na-panel and the CSS sizes it, and adding a div
           between them would have meant every one of those selectors growing a
           level for no gain. */
        $card           = $naProduct;
        $cardVariant    = 'home';
        $cardExtraClass = $naClasses;
        $cardEager      = ($naIndex === 0);
        include __DIR__ . '/../includes/product_card.php';
        ?>
        <?php endforeach; ?>
    </div><!-- /.na-gallery -->

        <?php if ($naPages > 1): ?>
        <?php /* Rendered hidden and revealed by the script below. With no
                 JavaScript the arrows would be two buttons that do nothing, so
                 they must not appear until the thing that makes them work has
                 run.

                 Below the photographs, not on them. Inside the gallery the only
                 free corner is the top right, and these are portrait
                 photographs — the pill landed squarely on the model's face, and
                 would on any garment shot the same way. Bottom right inside is
                 worse still: that is where the caption of whichever panel is
                 open appears. */ ?>
        <?php /* The shared rail pager — see .dv-pager in style.css. Same markup,
                 same classes and same behaviour as the one under Collections,
                 Trending and Shop by Occasion; only the labels and the mode
                 differ. This section pages in sets of four, so it opts into the
                 counter. */ ?>
        <div class="dv-pager" data-pager-mode="count" data-pager="na" hidden>
          <div class="dv-pager-pill">
            <button type="button" class="dv-pager-btn" data-pager-step="-1" aria-label="Previous new arrivals">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M15 5 8 12l7 7" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <?php /* aria-hidden on the visible digits and a separate sr-only status
                     region below: the visible text is repainted on resize too, and a
                     live region that fires on every resize tick machine-guns a screen
                     reader. Only a button press writes the announcement. */ ?>
            <span class="dv-pager-count" data-pager-count aria-hidden="true">1&#8202;/&#8202;<?= $naPages ?></span>
            <button type="button" class="dv-pager-btn" data-pager-step="1" aria-label="Next new arrivals">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m9 5 7 7-7 7" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
          </div>
          <span class="dv-sr-only" role="status" aria-live="polite" aria-atomic="true" data-pager-status></span>
        </div>
        <?php endif; ?>
    </div><!-- /.na-stage -->
</section>

<?php if ($naPages > 1): ?>
<script>
/* New Arrivals paging. The gallery itself is CSS; this only decides which four
   panels are in the flow, and it is additive — with the script absent the first
   four are already the only ones rendered visible and the arrows never appear.

   --na-rest is the number of NARROW panels on the page being shown, one less
   than that page's count, and --na-open is how wide the open one is. A final
   page of two would otherwise size its one narrow panel at a sixth of the row,
   and a final page of one would sit at half width with the rest of the gallery
   bare. Both go on the gallery, not the panels, so the inline values beat the
   [data-count] rules by being on the element those panels inherit from. */
/* Inside DOMContentLoaded because jQuery and Owl are deferred in the head:
   deferred scripts finish before that event, so both are guaranteed ready here
   and neither is ready at parse time. The shared pager retries its wiring on
   load, which is what lets this driver register a tick late. */
document.addEventListener('DOMContentLoaded', function () {
    var stage = document.querySelector('.na-stage');
    if (!stage) { return; }
    var gallery = stage.querySelector('.na-gallery');
    if (!gallery) { return; }

    /* Owl drives this row, like the hero and the Occasion strip.
       ────────────────────────────────────────────────────────────────────────
       It was a hand-rolled flex row: four panels of unequal width, one open at
       half the gallery and three narrow ones sharing the rest, swapping on
       hover. That is the one thing a carousel library cannot do — Owl measures
       every item once and writes a pixel width onto each .owl-item, so a panel
       that grows on hover would overflow its slot instead of pushing its
       neighbours along. Equal panels are the price of the library.

       What survives is everything else the section was: the same shared product
       card, the photograph filling the slot at the shape it was shot in, the
       caption and zoom on hover, the badge, the price and the sold-out state.
       What is gained is Owl's own drag, touch and momentum, and one garment per
       press for free — slideBy: 1 is what the four-at-a-time paging never was.

       No library, no carousel: the panels are already in the markup as a plain
       row, so a failed script costs the sliding, never the garments. */
    if (!window.jQuery || typeof jQuery.fn.owlCarousel !== 'function') { return; }
    var $g = jQuery(gallery);

    /* Owl's stylesheet is scoped to .owl-carousel, which Owl never adds itself,
       and it hides .owl-carousel until .owl-loaded exists. Added here rather
       than in the HTML for that second reason: in the markup it would hide the
       row until the script ran, and for ever if the library failed. */
    $g.addClass('owl-carousel');

    /* A panel is a link around a photograph, and both are draggable objects by
       default — the browser's own drag-and-drop eats the mouse events Owl needs
       and the row simply will not slide. Firefox ignores the CSS property, so
       the attribute has to say it too. */
    $g.find('a, img').attr('draggable', 'false');

    $g.owlCarousel({
        /* autoWidth below the breakpoint, decided HERE rather than in the
           responsive block — Owl applies responsive settings after it has
           already measured, so autoWidth arriving late left width:auto on every
           item and the row collapsed to nothing (measured: an empty band where
           the garments were).

           It is what lets the slide be two thirds of the screen AND start at the
           edge inset. With items:1 the only lever is stagePadding, which insets
           BOTH sides — 46px of it to get the width, and so 62px of gutter down
           the left of the first slide where the other rails start at 12. */
        autoWidth: window.matchMedia('(max-width: 1024px)').matches,
        items: 4,
        /* No gutter. The reference is one continuous band of photographs meeting
           edge to edge, and a margin would cut it into four separate cards —
           which is the thing this section was built to replace. */
        margin: 0,
        nav: false,          // the page draws its own arrows
        dots: false,         // and its own counter
        /* Looped only where the stage padding would otherwise show a blank
           band: on a phone the row is inset 46px each side so the neighbouring
           garment shows in it, and at the first slide there IS no neighbour on
           the left — a sixth of the screen, empty, before the photograph.
           Looping fills it with the last garment. Above the breakpoint four
           panels fill the row edge to edge, there is no padding to fill, and a
           product row should stop at the end rather than wrap. */
        loop: false,         // a product row should stop at the end, not wrap
        mouseDrag: true,
        touchDrag: true,
        freeDrag: false,
        /* The whole point of the exercise: one garment per press. Paging by four
           swapped out the three you were still reading to reach the fourth. */
        slideBy: 1,
        smartSpeed: 500,     // the flex row's own 500ms, kept
        autoHeight: false,
        responsive: {
            /* A phone shows one garment with the next one peeking, which is what
               says the row continues without an arrow having to.

               And a gutter between them, the same 12px every other rail on the
               site uses. Edge to edge is the DESKTOP idea — four panels reading
               as one continuous band of photography — and it does not survive
               the trip down: at one garment per screen there is no band to
               interrupt, just two unrelated pictures touching. */
            /* One garment with the next peeking, and the same 22px gutter
               every other rail uses.

               The width comes from the panel (autoWidth, set at init above),
               not from stagePadding. stagePadding insets BOTH sides, so making
               the slide two thirds of the screen that way cost 62px of empty
               gutter down the left of the FIRST slide, where there is no
               previous slide to show in it. The 14 that remains is only the
               page inset.

               Edge to edge is the DESKTOP idea — four panels reading as one
               continuous band of photography — and it does not survive the trip
               down: at one garment per screen there is no band to interrupt,
               just two unrelated pictures touching. */
            /* No stagePadding: it insets both sides and Owl reports it unset
               under autoWidth anyway. The rail's left inset is CSS now, shared
               with the Occasion strip so the two cannot drift. */
            /* 15px between slides on a phone. The desktop row has no gutter at
               all (it is one continuous band), so this number only ever
               describes the phone and the small-tablet step. */
            0:    { stagePadding: 0, margin: 15 },
            600:  { stagePadding: 0, margin: 15 },
            1025: { items: 4, stagePadding: 0,  margin: 0  }
        }
    });

    /* The expanding row, on top of Owl.
       ────────────────────────────────────────────────────────────────────────
       One panel open at half the row, three narrow ones sharing the rest,
       swapping as the pointer moves — the design the section was built around,
       kept while Owl does the sliding.

       What makes it safe on a carousel is ONE INVARIANT: the four panels on
       screen always add to exactly one viewport width, and every panel off
       screen keeps the quarter width Owl gave it.

       Owl shows item k by translating the stage to -(k x quarter). With the
       four visible summing to a viewport, item k still begins k quarters from
       the start and item k+4 still begins one viewport after item k — so Owl's
       arithmetic stays right to the pixel while the row rearranges itself.
       Break the sum and the arrows drift by exactly however much it is out.

       Set in PIXELS from here rather than in CSS. The first attempt sized these
       with calc(var(--na-view) / 4) and the variable resolved to its 100%
       fallback — which inside a flex row means 100% of the STAGE, all eight
       items, not the viewport. Every panel came out double width and the height
       chain collapsed with it. Pixels have no such ambiguity, and the script
       already knows the only number involved. */
    var railQuery = window.matchMedia('(min-width: 1025px)');
    var OPEN_SHARE = 0.5;          // the open panel's share of the row

    function owlOf()    { return $g.data('owl.carousel'); }
    function naItems()  { return $g.find('.owl-item').toArray(); }
    function naActive() { return naItems().filter(function (i) { return i.classList.contains('active'); }); }

    /* Whether our pixel widths are currently sitting on the items.
       ────────────────────────────────────────────────────────────────────────
       The widths are an OVERRIDE of Owl's own inline widths, so taking them off
       cannot mean blanking style.width — that deletes Owl's layout too. Below
       the breakpoint that collapsed the whole rail to a single pixel: items of
       zero width, a panel whose aspect-ratio then resolved to zero height, and
       a gallery 1px tall with the garments inside it.

       So the widths come off only if we put them there, and Owl is asked to
       re-measure afterwards — it is the only thing that knows what an item
       should be at this breakpoint, with the stage padding the phone adds. The
       flag is also what stops the refresh it triggers coming straight back
       through the refreshed handler as a loop. */
    var naOverridden = false;

    function naRelease() {
        if (!naOverridden) { return; }
        naItems().forEach(function (i) { i.style.width = ''; i.classList.remove('is-open'); });
        naOverridden = false;
        $g.trigger('refresh.owl.carousel');
    }

    /* hovered is the item the pointer is over, or null for the resting state,
       in which the open one is the first on screen — the same rule the flex
       version used for the first panel of each page. */
    /* startAt is the index the row is moving TO. Passed in when the layout has
       to run before Owl has updated its own .active classes — which is the case
       for every arrow press and every trackpad step, because the widths have to
       start moving at the same moment the stage does. Left out, the window is
       read from the classes, which is right for hover and for resize. */
    function naLayout(hovered, startAt) {
        var items = naItems();

        /* Below the breakpoint Owl shows one garment at a time and there is
           nothing to redistribute. Every override comes off, so the phone rail
           is Owl's own layout untouched. */
        if (!railQuery.matches) { naRelease(); return; }

        /* The CONTENT width, not clientWidth.
           ────────────────────────────────────────────────────────────────────
           clientWidth includes padding, and this rail has a left inset so its
           first panel starts on the page gutter. Sizing the four visible panels
           to clientWidth therefore made them sum to 72px MORE than the space
           they actually had, and the fourth ran that far past the right edge —
           the invariant still "held" against the wrong number, which is exactly
           why it went unnoticed until it was looked at on a desktop. */
        var padL = parseFloat(getComputedStyle(gallery).paddingLeft) || 0;
        var padR = parseFloat(getComputedStyle(gallery).paddingRight) || 0;
        var view    = gallery.clientWidth - padL - padR;
        var per     = Math.max(1, Math.round((owlOf() || {}).settings ? owlOf().settings.items : 4));
        var actives = (typeof startAt === 'number')
            ? items.slice(startAt, startAt + per)
            : naActive();
        if (!actives.length) { actives = naActive(); }
        var quarter = view / (per || actives.length);

        /* Fewer on screen than a full window — a short catalogue, or a moment
           mid-refresh. Equal widths still satisfy the invariant, and there is no
           sensible open panel when there is only one. */
        if (actives.length < 2) { naRelease(); return; }

        var open   = (hovered && actives.indexOf(hovered) !== -1) ? hovered : actives[0];
        var narrow = (view * OPEN_SHARE) / (actives.length - 1);

        items.forEach(function (item) {
            var isActive = actives.indexOf(item) !== -1;
            item.classList.toggle('is-open', isActive && item === open);
            /* Off screen: exactly the width Owl assigned, which is what keeps
               every offset outside the window where Owl expects it. */
            item.style.width = (!isActive ? quarter
                                          : (item === open ? view * OPEN_SHARE : narrow)) + 'px';
        });
        naOverridden = true;
    }

    /* Hover is read on the gallery, not bound per item: Owl rebuilds .owl-item
       elements on refresh and per-item listeners would be lost with them. */
    /* Every one of these is gated on the breakpoint, and that gate is the fix
       for a bug reported many times: the slide CHANGED WIDTH on hover on a
       phone.
       ────────────────────────────────────────────────────────────────────────
       I kept looking for it in the CSS transforms, and it was never there. It
       is this: naLayout() ran on every mouseover at every width. Below the
       breakpoint it takes the release path, which clears the inline widths and
       triggers a refresh — so Owl re-measured mid-hover and the slide visibly
       resized. A phone fires mouseover on tap, so it happened on touch too.

       Nothing hover-driven runs below 1025px now. The row only rearranges where
       rearranging is the design. */
    function naHoverActive() { return railQuery.matches; }

    gallery.addEventListener('mouseover', function (e) {
        if (!naHoverActive()) { return; }
        var item = e.target.closest ? e.target.closest('.owl-item') : null;
        if (item) { naLayout(item); }
    });
    gallery.addEventListener('mouseleave', function () {
        if (!naHoverActive()) { return; }
        naLayout(null);
    });
    gallery.addEventListener('focusin', function (e) {
        if (!naHoverActive()) { return; }
        var item = e.target.closest ? e.target.closest('.owl-item') : null;
        if (item) { naLayout(item); }
    });

    /* The widths move WITH the slide, not after it.
       ────────────────────────────────────────────────────────────────────────
       They used to be frozen for the length of the translate and then applied
       in one go — which read as a blink: the row was still gliding when the
       incoming panel snapped from a third of its width to full, and the
       outgoing one collapsed in the same frame.

       Both animations are 500ms, so they simply run together. That means
       starting the widths at the moment the stage starts, which is BEFORE Owl
       has moved its .active classes — hence the explicit index. The end state
       is identical either way (item k begins k quarters in, because everything
       before it is an inactive quarter), so the two arrive together and nothing
       jumps at the finish. */
    $g.on('changed.owl.carousel', function (e) {
        if (!e.property || e.property.name !== 'position') { return; }
        var owl = owlOf();
        if (!owl) { return; }
        naLayout(null, Math.max(0, owl.relative(e.property.value)));
    });

    /* And once more when the move has landed, reading the classes this time —
       cheap, and it is the resync if anything above was a frame out. */
    $g.on('translated.owl.carousel', function () { naLayout(null); });
    $g.on('resized.owl.carousel refreshed.owl.carousel', function () { naLayout(null); });

    var naResizeTimer;
    window.addEventListener('resize', function () {
        window.clearTimeout(naResizeTimer);
        naResizeTimer = window.setTimeout(function () { naLayout(null); }, 120);
    });

    naLayout(null);

    /* No trackpad handler here, deliberately.
       ────────────────────────────────────────────────────────────────────────
       The other rails take a two-finger swipe; this row does not. It is the one
       slider on the page whose panels resize as it moves, and a sideways
       gesture over it kept catching while the homepage was being read — a row
       that rearranges itself under a scroll you did not mean to give it.

       The arrows move it, a mouse drag moves it, and a finger moves it on a
       phone. A wheel is left entirely to the page, horizontal and vertical
       alike: nothing here calls preventDefault, so the browser's own gestures
       stay the browser's. */

    /* Registered for the shared pager, like every other rail on this page. The
       section keeps what is its own — Owl, the responsive item counts — and
       hands over the arrows and the counter, which are the same everywhere.

       Read from Owl rather than remembered, so a drag and a button press cannot
       drift apart. Stops, not pages: eight garments showing four can start the
       row at eight different places, which is 5 stops, and that is what the
       counter should say. */
    function naItemCount() { return $g.find('.owl-item').not('.cloned').length || 1; }
    function naPerView() {
        /* Counted off the row, not read from settings.items.
           ────────────────────────────────────────────────────────────────────
           Below the breakpoint this carousel runs on autoWidth, where items is
           not what decides how many fit — the panel's own width is. The counter
           went on saying "1 / 5" on a phone showing one garment at a time, five
           being the desktop arithmetic for four across. .owl-item.active is what
           Owl actually put on screen, at either width. */
        var live = $g.find('.owl-item.active').length;
        if (live) { return Math.max(1, Math.min(naItemCount(), live)); }
        var owl = $g.data('owl.carousel');
        var n = owl && owl.settings ? owl.settings.items : 4;
        return Math.max(1, Math.min(naItemCount(), Math.round(n)));
    }

    window.dvRailDrivers = window.dvRailDrivers || {};
    window.dvRailDrivers.na = {
        label: 'New Arrivals',
        rail:  gallery,
        pages: function () { return Math.max(1, naItemCount() - naPerView() + 1); },
        page:  function () {
            var owl = $g.data('owl.carousel');
            if (!owl) { return 0; }
            var i = owl.relative(owl.current());
            return Math.max(0, Math.min(this.pages() - 1, i));
        },
        go: function (delta) {
            $g.trigger(delta > 0 ? 'next.owl.carousel' : 'prev.owl.carousel');
        },
        /* Owl moves on its own whenever it is dragged or swiped; the counter
           follows from its own event rather than from a scroll that no longer
           happens. The tick lets Owl settle its index before it is read. */
        onMove: function (cb) {
            $g.on('changed.owl.carousel dragged.owl.carousel', function () {
                window.setTimeout(cb, 0);
            });
        }
    };
});
</script>
<?php endif; ?>

<?php endif; ?>

<!-- ==================== 4. TRENDING / BEST SELLERS ==================== -->
<?php
/* ── The photo fan ───────────────────────────────────────────────────────────
   Four garments laid out like physical prints dropped on a table: alternating
   rotations, overlapping slightly, straightening and lifting under the pointer.
   A fifth is half in frame to say the strip continues.

   Built on the shared product card, not on a copy of it. Every tile here is
   includes/product_card.php with one extra class — so the badge, the sale
   percentage, the sold-out state, country pricing, the "From" spread, the
   wishlist button and the product link are all the same code that draws the
   shop grid, and none of them can drift. Only the arrangement is new, and that
   is entirely CSS.

   No carousel library. Owl drives the hero and Shop by Occasion, but it wraps
   every item and clips the stage — a card that rotates, lifts 14px and scales
   to 1.03 would be cut off at the moment it is being looked at. This is a flex
   track moved by transform, which is about sixty lines and cannot clip.

   Two tabs are only worth having when both lead somewhere. With one list empty
   the pair became a button that opened a blank grid, so the toggle collapses to
   a plain heading naming whichever half has stock — and when neither has any,
   the section does not appear. */
$pfPanels = [];
if (!empty($bestSellers)) {
    $pfPanels['best']  = ['label' => 'Best Sellers', 'items' => $bestSellers, 'badge' => 'Best Seller'];
}
if (!empty($trending)) {
    $pfPanels['trend'] = ['label' => 'Trending Now', 'items' => $trending,    'badge' => 'Hot'];
}
$pfHasBoth = count($pfPanels) > 1;
?>
<?php if ($pfPanels): ?>
<section class="trending-section section-space photo-fan-section">
    <div class="container">

        <?php /* The section had no heading at all — it opened straight onto two
                 tabs, while every other band on the page announces itself with an
                 editorial label and an h2 above the content. Read down the page
                 it was the one section that started mid-sentence.

                 The shared block, not a bespoke one, so the mobile heading
                 spacing already tuned for .section-title-wrapper applies here for
                 free — the same reason Shop by Occasion uses it.

                 The h2 names what the two tabs have in common; the tabs
                 themselves stay the sub-navigation they always were. It is worth
                 saying they are now an h2 followed by a tablist rather than two
                 headings competing, which is also what a screen reader needs to
                 hear to make sense of the pair. */ ?>
        <div class="section-title-wrapper reveal-on-scroll">
            <span class="editorial-label">Most Coveted</span>
            <h2 class="section-title">The Dievon Edit</h2>
        </div>

        <div class="pf-head">
            <?php if ($pfHasBoth): ?>
            <?php /* Real tabs, so a screen reader announces "tab 1 of 2, selected"
                     rather than two unrelated buttons. Arrow keys move between
                     them; only the selected tab is in the tab order, which is the
                     expected pattern for a tablist. */ ?>
            <div class="pf-tabs" role="tablist" aria-label="Product collections">
                <?php $pfFirst = true; foreach ($pfPanels as $pfKey => $pfPanel): ?>
                <button type="button" role="tab"
                        id="pfTab-<?= $pfKey ?>"
                        class="pf-tab<?= $pfFirst ? ' is-active' : '' ?>"
                        aria-selected="<?= $pfFirst ? 'true' : 'false' ?>"
                        aria-controls="pfPanel-<?= $pfKey ?>"
                        tabindex="<?= $pfFirst ? '0' : '-1' ?>"><?= htmlspecialchars($pfPanel['label']) ?></button>
                <?php $pfFirst = false; endforeach; ?>
            </div>
            <?php else: ?>
            <h2 class="section-title pf-single-title"><?= htmlspecialchars(reset($pfPanels)['label']) ?></h2>
            <?php endif; ?>

            <?php /* The "View All" link that sat here is gone, on every screen
                     size. It was one link per panel, swapped with the tab. The
                     row already leads to each garment, the tabs already say what
                     is in it, and on a phone it was a third heading competing
                     with the two tabs beside it. Its swap handler went with it —
                     see the tab switcher below. */ ?>
        </div>
    </div>

    <?php /* .container closes ABOVE the panels, so the strip runs edge to edge
             while the heading and the tabs stay aligned with the rest of the
             page — the same construction Shop by Occasion uses, and the reason
             that strip reaches the glass and this one did not. Measured before
             the change: at 1440 the Occasion rail ran 0 to 1434 while this
             viewport sat at 72 with 78 to spare, and at 375 every other rail
             started at 0 while this one started at 12.

             The pager goes back INTO a container of its own below, because the
             arrows belong on the page's gutter with the heading, not out at the
             edge with the prints. Occasion does that too. */ ?>

        <?php $pfFirst = true; foreach ($pfPanels as $pfKey => $pfPanel): ?>
        <div class="pf-panel<?= $pfFirst ? ' is-active' : '' ?>"
             id="pfPanel-<?= $pfKey ?>"
             data-pf-panel="<?= $pfKey ?>"
             <?php /* Named for the pager's screen-reader announcement, which reads
                      "Best Sellers, page 2 of 3" rather than a bare "2 / 3" — and
                      re-reads correctly after a tab switch because the name comes
                      from the panel, not from the pager. */ ?>
             data-pf-label="<?= htmlspecialchars($pfPanel['label'] ?? ucfirst((string)$pfKey)) ?>"
             <?= $pfHasBoth ? 'role="tabpanel" aria-labelledby="pfTab-' . $pfKey . '"' : '' ?>
             <?= $pfFirst ? '' : 'hidden' ?>>

            <?php /* The viewport clips horizontally. Its vertical padding is not
                     decoration: a card that lifts 14px and scales 1.03 grows past
                     its own box, and overflow:hidden would shave the top off the
                     one being hovered. */ ?>
            <div class="pf-viewport" tabindex="-1">
                <div class="pf-track" data-pf-track>
                    <?php foreach ($pfPanel['items'] as $pfIndex => $pfProduct): ?>
                    <?php /* --pf-i drives the stacking order below: a fan of
                             prints falls with the LEFT one on top, so an earlier
                             print has to out-rank a later one. Source order gives
                             the opposite. */ ?>
                    <div class="pf-item" data-pf-index="<?= $pfIndex ?>" style="--pf-i: <?= $pfIndex ?>">
                        <?php
                        /* The shared product card in its 'home' variant — see
                           includes/product_card.php. One component, two designs:
                           the badge, the sale percentage, sold out, "not sold in
                           your country", country pricing, the "From" spread, the
                           wishlist heart, the compare toggle and the "in your bag"
                           note are all the same code that draws the shop grid, so
                           a price or a state can never disagree between the two.

                           This section supplies only the shell: what a print looks
                           like, how far it is rotated, and when the caption
                           appears. All of that is CSS on .pf-card. */
                        $card           = $pfProduct;
                        $cardVariant    = 'home';
                        $cardExtraClass = 'pf-card';
                        // Quick View stays on: it is what the hover reveals here.
                        include __DIR__ . '/../includes/product_card.php';
                        ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php /* The shared rail pager — see .dv-pager in style.css. It replaces
                     three separate controls this section used to carry: a pair of
                     48px circles hung outside the viewport into the page gutter
                     (and deleted below 767px, leaving the strip swipe-only), a
                     range counter, and a progress bar. The counter and the bar
                     said the same thing two pixels apart; the pill says it once,
                     in the same place and the same words as the other three
                     rails. Products page in fours, so this one takes the counter. */ ?>
            <div class="container">
            <div class="dv-pager" data-pager-mode="count" data-pager="pf-<?= htmlspecialchars($pfKey) ?>" hidden>
              <div class="dv-pager-pill">
                <button type="button" class="dv-pager-btn" data-pager-step="-1" aria-label="Previous products">
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M15 5 8 12l7 7" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
                <span class="dv-pager-count" data-pager-count aria-hidden="true"></span>
                <button type="button" class="dv-pager-btn" data-pager-step="1" aria-label="More products">
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m9 5 7 7-7 7" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
              </div>
              <span class="dv-sr-only" role="status" aria-live="polite" aria-atomic="true" data-pager-status></span>
            </div>
            </div>
        </div>
        <?php $pfFirst = false; endforeach; ?>
</section>
<?php endif; ?>

<?php
// ==================== 5. SHOP BY OCCASION ====================
// These four pills pointed at Dresses, Outerwear and "Jewelry & Accessories" —
// none of which exist as categories. Every pill landed the shopper on an empty
// grid. The `occasion` column already holds exactly this idea, so the pills are
// built from occasions that genuinely have stock.
//
// Worked out BEFORE the <section> opens, not inside it. Previously the section
// wrapper and the "Shop by Occasion" heading were printed unconditionally and
// only the pills were guarded — which was invisible while womenswear always had
// occasions, and became a titled, completely empty band on /men the moment a
// side had no stock to count.
$occasions = [];
try {
    // Scoped to the side being browsed like every other product query on this
    // page. Without it the menswear homepage offered "Wedding" and "Diwali"
    // pills counted from womenswear, and clicking one returned a grid the
    // shopper had just been told was menswear.
    $occasions = $pdo->query(
        "SELECT occasion FROM products
          WHERE occasion IS NOT NULL AND occasion <> ''
            AND available = 1 AND (is_deleted = 0 OR is_deleted IS NULL)
            {$homeGenderSql}"
    )->fetchAll(PDO::FETCH_COLUMN);

    /* Counted per OCCASION, not per stored string.
       ────────────────────────────────────────────────────────────────────────
       This was GROUP BY occasion, which groups by the whole field — and the
       field is a list. On the live shop that produced nine tiles for nine
       products: "Casual / Everyday / Day Out / Travel" beside "Casual /
       Workwear / Everyday / Day Out", each one a sentence, each leading to a
       single garment. A section meant to offer four or five ways in was
       offering the catalogue back one product at a time.

       Splitting first gives the handful of real occasions the shop actually
       sells for — Casual, Everyday, Workwear, Festive — each covering every
       garment that names it. The commonest come first, which is what the
       LIMIT was always trying to do. */
    $occCounts = [];
    foreach ($occasions as $occRaw) {
        foreach (dievonSplitOccasions($occRaw) as $occOne) {
            $occCounts[$occOne] = ($occCounts[$occOne] ?? 0) + 1;
        }
    }
    arsort($occCounts);
    $occasions = array_slice(array_keys($occCounts), 0, 12);
} catch (PDOException $e) {}

// Fall back to this side's real categories rather than printing nothing at all.
// $menuCategories is already gender-filtered in includes/header.php, so a men's
// visitor is offered Shirts and Trousers, never Kurtis.
if (!$occasions) {
    foreach (array_slice($menuCategories ?? [], 0, 12) as $mc) { $occasions[] = $mc['name']; }
}

/* One representative photograph per tile.
   ────────────────────────────────────────────────────────────────────────────
   Nothing new to upload and nothing to maintain: each tile borrows the cover
   shot of the NEWEST live product in that occasion, so the section restyles
   itself as the catalogue changes and can never point at a photo of something
   that has been taken down.

   Matched on occasion OR category in one pass, because $occasions above may
   hold either — it falls back to category names when no occasion has stock,
   and the link below already switches between ?occasions= and ?category= for
   exactly that reason. One query for all four rather than four queries.

   Reuses $homeGenderSql verbatim: shopGenderSqlFilter() was called with no
   alias, so it emits bare column names and this single-table query is the
   shape it expects. A men's visitor therefore gets men's photographs. */
$occasionCovers = [];
if ($occasions) {
    try {
        $occPh = implode(',', array_fill(0, count($occasions), '?'));
        /* occasion is a LIST, so IN() cannot find "Casual" inside
           "Casual / Everyday / Day Out". One LIKE per tile against the
           normalised, slash-wrapped field does — and without dragging in
           "Smart Casual" the way a bare %Casual% would. */
        $occLike = implode(' OR ', array_fill(0, count($occasions),
                    OCCASION_MATCH_SQL . " LIKE ?"));
        $occCovSt = $pdo->prepare(
            "SELECT occasion, category, image FROM products
              WHERE image IS NOT NULL AND image <> ''
                AND available = 1 AND (is_deleted = 0 OR is_deleted IS NULL)
                AND (({$occLike}) OR category IN ({$occPh}))
                {$homeGenderSql}
              ORDER BY id DESC"
        );
        $occCovSt->execute(array_merge(
            array_map(fn($o) => '%/' . $o . '/%', $occasions),
            $occasions
        ));
        /* One garment, one tile — as far as the catalogue allows.
           ────────────────────────────────────────────────────────────────────
           This gave a product's photograph to EVERY occasion it named, and an
           occasion field is a list: one kurti tagged "Casual / Everyday / Day
           Out / Travel" filled four tiles with the same picture. On the live
           shop five tiles showed two photographs between them, so a row meant to
           show the range of the collection showed the same dress over and over.

           Two passes fix it. The first lets each product furnish ONE tile and
           each photograph appear once, which spreads the row across as many
           different garments as there are. The second fills whatever is still
           empty, reusing pictures only where there is genuinely nothing else —
           a tile with a repeated photograph still beats a blank one.

           Rows arrive newest first, so the newest stock claims tiles first. */
        $occRows     = $occCovSt->fetchAll(PDO::FETCH_ASSOC);
        $occUsedImgs = [];

        $occClaimsOf = static function (array $row) use ($occasions): array {
            // A product's occasion field is a list, so it can supply the
            // photograph for any occasion named inside it. Category matches
            // whole, because a category name is a single value.
            $claims = array_merge(
                dievonSplitOccasions((string)($row['occasion'] ?? '')),
                [(string)($row['category'] ?? '')]
            );
            return array_values(array_filter($claims, static fn($c) =>
                $c !== '' && in_array($c, $occasions, true)));
        };

        foreach ($occRows as $occRow) {                 // pass 1 — spread them out
            $img = (string)($occRow['image'] ?? '');
            if ($img === '' || isset($occUsedImgs[$img])) { continue; }
            foreach ($occClaimsOf($occRow) as $occName) {
                if (!isset($occasionCovers[$occName])) {
                    $occasionCovers[$occName] = $img;
                    $occUsedImgs[$img] = true;
                    break;                              // this garment fills ONE tile
                }
            }
        }

        /* An occasion that cannot have a photograph of its own is not shown.
           ────────────────────────────────────────────────────────────────────
           Spreading the pictures was not enough on its own. Only one garment in
           the shop is tagged Festive, Traditional, Wedding Guest and
           Celebration — so those four tiles could only ever show that same
           dress, four times in a row, whatever order they were assigned in.

           So the row is built from the occasions the catalogue can genuinely
           illustrate: twelve tiles showing seven photographs becomes seven
           tiles, each a different garment. A visitor reads it as a range; the
           old row read as a shop with two dresses in it.

           Nothing is lost permanently — the dropped occasions still work as
           filters, they just do not get a tile until a second garment is tagged
           with them. Add stock and they return by themselves. */
        $occasions = array_values(array_filter(
            $occasions,
            static fn($occName) => isset($occasionCovers[$occName])
        ));
    } catch (PDOException $e) {}
}
?>
<?php if ($occasions): ?>
<section class="occasion-bar-section section-space">
    <div class="container">
        <?php /* The house heading block, same as every other section on this page.
                 This was the only section using a lone 10px label as its title, with
                 no h2 and no gold rule, so it read as an unfinished strip sitting
                 between two properly titled sections rather than as a section of its
                 own. Using the shared block also means the mobile heading spacing
                 already tuned for .section-title-wrapper applies here for free. */ ?>
        <div class="section-title-wrapper reveal-on-scroll">
            <span class="editorial-label">Curated Occasions</span>
            <h2 class="section-title">Shop by Occasion</h2>
        </div>
    </div>

    <?php /* The strip sits OUTSIDE .container on purpose — that is what lets it run
             edge to edge while the heading above stays aligned with the rest of the
             page. Scrolled by finger or trackpad only: no arrows, no dots. The
             affordance is the tile clipped by the right edge of the screen, which
             says "there is more" without adding any furniture to look at. */ ?>
    <div class="occasion-slider js-owl-rail" data-owl-autowidth="1">
            <?php foreach ($occasions as $occ):
                $occCover = $occasionCovers[$occ] ?? '';
                $occWebp  = ($occCover !== '' && function_exists('webpUrlIfExists'))
                          ? webpUrlIfExists('products', $occCover) : '';
            ?>
                <a href="<?= SITE_URL ?>/shop?<?= in_array($occ, array_column($menuCategories ?? [], 'name'), true) ? 'category' : 'occasions' ?>=<?= urlencode($occ) ?>"
                   class="occasion-tile reveal-on-scroll">
                    <?php /* The photograph is its own box now, because the caption sits
                             UNDER it rather than on it. The 3:4 frame moves here with
                             the picture — the tile itself is picture plus caption and
                             has no ratio of its own. */ ?>
                    <span class="occasion-tile-frame">
                    <?php if ($occCover !== ''): ?>
                        <?php /* alt="" on purpose. The tile's accessible name is the label
                                 underneath it, so describing the photograph as well would
                                 make a screen reader announce every tile twice. */ ?>
                        <picture>
                            <?php if ($occWebp): ?><source srcset="<?= htmlspecialchars($occWebp) ?>" type="image/webp"><?php endif; ?>
                            <img src="<?= htmlspecialchars(pictureImgSrc(SITE_URL . '/uploads/products/' . $occCover, $occWebp)) ?>"
                                 alt="" loading="lazy" decoding="async" class="occasion-tile-img">
                        </picture>
                    <?php else: ?>
                        <?php /* An occasion with no photographed product still gets a tile —
                                 a gap in the row would look broken, and the link works. */ ?>
                        <span class="occasion-tile-img occasion-tile-img--blank"></span>
                    <?php endif; ?>
                    </span>
                    <?php /* Caption under the print, like the reference: the occasion in
                             serif caps, and one small underlined prompt beneath it. The
                             prompt is a span, not a second link — the whole tile is
                             already the link, and nesting one inside it would give a
                             keyboard two stops to reach the same page. */ ?>
                    <span class="occasion-tile-caption">
                        <span class="occasion-tile-label"><?= htmlspecialchars($occ) ?></span>
                        <span class="occasion-tile-cta">Explore</span>
                    </span>
                </a>
            <?php endforeach; ?>
    </div>

    <?php /* The shared rail pager — see .dv-pager in style.css. This strip shipped
             with no control at all and a comment explaining that the clipped tile
             at the right edge was affordance enough. It is not: a clipped tile is
             an invitation to drag, and drag is the one gesture a keyboard, a
             trackpad without horizontal scroll, or a switch cannot perform.

             It is also the section that dictated where the shared pager lives.
             Owl clips its stage at this element's box and ignores stagePadding
             under autoWidth, so nothing can hang outside the strip's edges the
             way Trending's arrows used to, and nothing can sit inside without
             scrolling away with the tiles. Under it, in normal flow, is the only
             placement that works here — so it is the placement everywhere.

             No counter: the strip slides, it does not page through sets, and a
             page number under a curated row of occasions reframes browsing as a
             chore. */ ?>
    <div class="container">
        <div class="dv-pager" data-pager-mode="count" data-pager="occ" hidden>
          <div class="dv-pager-pill">
            <button type="button" class="dv-pager-btn" data-pager-step="-1" aria-label="Previous occasions">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M15 5 8 12l7 7" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <span class="dv-pager-count" data-pager-count aria-hidden="true">1&#8202;/&#8202;1</span>
            <button type="button" class="dv-pager-btn" data-pager-step="1" aria-label="Next occasions">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m9 5 7 7-7 7" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
          </div>
          <span class="dv-sr-only" role="status" aria-live="polite" aria-atomic="true" data-pager-status></span>
        </div>
    </div>

    <script>
    (function () {
        var rail = document.querySelector('.occasion-slider');
        if (!rail) { return; }

        /* Owl or not, without knowing which at registration time.
           ─────────────────────────────────────────────────────────────────
           This file's scripts run before includes/footer.php initialises the
           rail, so the instance does not exist yet — and may never, if jQuery
           or Owl fails to load. Every method therefore asks at call time and
           falls back to driving the element as a plain overflow rail, which is
           exactly what it is until Owl takes it over. */
        function owl() {
            if (!window.jQuery) { return null; }
            var inst = window.jQuery(rail).data('owl.carousel');
            return (inst && typeof inst.maximum === 'function') ? inst : null;
        }

        /* The prints have to stack left over right.
           ────────────────────────────────────────────────────────────────────
           A fan of photographs dropped on a table falls that way, and source
           order paints the opposite — which put each print's right-hand edge
           UNDER its neighbour and buried the caption of the one beside it.
           Owl's .owl-item is the stacking element, and the count is not
           knowable in CSS, so it is written from here.

           Deferred to load because includes/footer.php initialises this rail
           after this file's scripts run: there are no .owl-item elements to
           number yet at parse time. */
        function occStack() {
            var items = rail.querySelectorAll('.owl-item');
            for (var i = 0; i < items.length; i++) { items[i].style.zIndex = String(40 - i); }
        }
        window.addEventListener('load', function () {
            occStack();
            if (window.jQuery) {
                /* refreshed ONLY, not changed and translated as well.
                   ────────────────────────────────────────────────────────────
                   The number written here is 40 minus the item's index, so it
                   can only change when Owl rebuilds its items — which is what
                   refreshed means. Sliding does not renumber anything, so the
                   other two events re-wrote all four items with the values they
                   already had, twice per slide.

                   Not merely wasted: changed fires as the settle animation
                   BEGINS, so the inline write landed on the first frames of the
                   incoming tile. z-index is what puts an item in its own
                   stacking context, so rewriting it mid-animation makes the
                   compositor re-order layers exactly when the next tile is
                   coming in — the catch in this strip that the others do not
                   have. The photo fan sets its stacking once at init and never
                   touches it again; this now matches. */
                window.jQuery(rail).on('refreshed.owl.carousel',
                    function () { window.setTimeout(occStack, 0); });
            }
        });

        window.dvRailDrivers = window.dvRailDrivers || {};
        window.dvRailDrivers.occ = {
            label: 'Shop by Occasion',
            rail:  rail,
            pages: function () {
                var o = owl();
                /* maximum() is the last index Owl will scroll to, so it is 0
                   exactly when everything already fits — which is the same
                   "can this move" test the other rails make with scrollWidth,
                   and the reason a short list of occasions gets no control. */
                return o ? Math.max(1, o.maximum() + 1) : window.dvRail.pages(rail);
            },
            page: function () {
                var o = owl();
                return o ? Math.max(0, Math.min(o.maximum(), o.current())) : window.dvRail.page(rail);
            },
            go: function (delta) {
                var o = owl();
                if (!o) { window.dvRail.go(rail, delta); return; }
                var total = o.maximum() + 1;
                var next  = ((o.current() + delta) % total + total) % total;
                /* The rail's OWN speed, not a number written here.
                   ────────────────────────────────────────────────────────────
                   This passed 300, which overrode the 500 the rail is built
                   with and made the arrow move this strip at roughly twice the
                   speed of New Arrivals (500) and the photo fan (620) — the
                   two rails it sits between. smartSpeed was raised to 500 for
                   exactly that reason, but only the drag-release path reads it;
                   an explicit third argument here beat it on every arrow press,
                   so the strip still jumped where the others glided.

                   Reading it off the instance means the two can no longer drift
                   apart the way they just did. */
                window.jQuery(rail).trigger('to.owl.carousel', [next, o.settings.smartSpeed]);
            },
            onMove: function (cb) {
                /* Owl announces every settle, including the ones a drag causes,
                   so the counter cannot go stale. Bound late for the same reason
                   the instance is looked up late. */
                window.addEventListener('load', function () {
                    if (window.jQuery) { window.jQuery(rail).on('translated.owl.carousel initialized.owl.carousel', cb); }
                    else { rail.addEventListener('scroll', cb, { passive: true }); }
                    cb();
                });
            }
        };
    })();
    </script>
</section>
<?php endif; ?>

<!-- ==================== 6. FESTIVAL / WEDDING / OFFICE / PARTY COLLECTIONS ==================== -->
<?php
// ── Occasion edits ──────────────────────────────────────────────────────────
// Two wide panels, always — the pair this page has always had.
//
// Their background photographs used to be fixed in the stylesheet
// (.banner-wedding and .banner-office pointed at lookbook_1.png and
// lookbook_2.png), so changing either one meant editing CSS, and showing
// menswear something different was impossible.
//
// They now take the first two banners of whichever side is being browsed. That
// means uploading men's banners changes the men's panels too, with nothing extra
// to configure — the same two images that lead the slider carry these panels.
// The original lookbook photographs stay as the fallback for a side that has no
// banners of its own yet, so the section is never blank.
$occasionImages = [];
try {
    $oiSt = $pdo->prepare(
        "SELECT image FROM banners
          WHERE status = 'Active' AND image <> ''
            AND (gender = :g OR gender = 'both')
       ORDER BY sort_order ASC, id ASC LIMIT 2"
    );
    $oiSt->execute([':g' => function_exists('currentShopGender') ? currentShopGender() : 'women']);
    $occasionImages = $oiSt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    // gender column not added yet — fall back to the lookbook images below
}

/**
 * Background for occasion panel $slot (0 or 1).
 *
 * Resolves the filename exactly as the hero slider above does — uploads/products
 * first, then uploads/gallery, then the lookbook fallback. That duplication is
 * deliberate and necessary: the slider checks whether the file actually exists,
 * so a banner row pointing at a missing image quietly falls back there. Building
 * the URL here without the same check meant the two could show different
 * pictures for the same banner — the slider a lookbook photo, this panel a
 * broken image — which is the opposite of "panel 1 matches slide 1".
 */
$occasionBg = function (int $slot) use ($occasionImages) {
    // The WebP twin wherever one exists.
    //
    // These panels reuse the SAME two photographs as the hero slider. Once the
    // slider began serving WebP and this did not, the browser fetched BOTH
    // encodings of one picture — 273KB of WebP for the slide and 2,317KB of PNG
    // for the panel below it. Matching the format means the file is already in
    // cache by the time the panel needs it, so these two panels cost nothing at
    // all to display.
    $pick = function (string $dir, string $file) {
        $webp = webpUrlIfExists($dir, $file);
        return cacheBustedUploadUrl($webp ?: SITE_URL . '/uploads/' . $dir . '/' . $file);
    };

    // Ask the SHARED resolver where the file is — do not search by hand.
    //
    // This looked in uploads/products/ and uploads/gallery/ only. Banners moved
    // to their own uploads/banners/ folder (admin/banners.php:120) so that
    // clearing out product photography could no longer take the homepage hero
    // with it — and this copy was never told. Every banner uploaded since then
    // is invisible to it, so the panel silently drops through to the lookbook
    // photograph below.
    //
    // That is exactly the symptom: the hero slider shows the real banner,
    // because it asks bannerImageLocation(), while the Occasion Edit panel
    // underneath shows a lookbook shot. Two resolvers, one of them out of date.
    // Now there is one, and it checks banners, products and gallery in turn.
    $file = (string)($occasionImages[$slot] ?? '');
    $loc  = function_exists('bannerImageLocation') ? bannerImageLocation($file) : null;
    if ($loc !== null) {
        return $pick($loc['dir'], $loc['file']);
    }

    // Same lookbook numbering the slider uses for slot N.
    return $pick('gallery', 'lookbook_' . (($slot % 3) + 1) . '.png');
};

/* These two panels' PHOTOGRAPHS were already chosen per audience — the query
   feeding $occasionImages scopes by currentShopGender() — but the words and the
   buttons underneath them were hard-coded womenswear. So /men showed a men's
   picture above copy about silk slip gowns, gold heels and organza dupattas,
   and its most prominent button walked a male shopper into 3 Piece Suits, a
   women's collection. That is exactly the failure pages/men.php exists to
   prevent, and /men is in the sitemap.

   The CTA on menswear points at /shop, matching the men's hero slide above:
   the shop reads the gender context, so /shop is already "shop menswear" here,
   and it cannot go stale the way a named category link can. */
$occIsMen = function_exists('currentShopGender') && currentShopGender() === 'men';
?>
<section class="occasion-banners-section">
    <!-- Wedding Collection Banner -->
    <div class="occasion-banner" style="--occ-bg: url('<?= htmlspecialchars($occasionBg(0)) ?>');">
        <div class="occasion-banner-overlay"></div>
        <div class="container occasion-banner-content">
            <span class="occasion-banner-eyebrow">Occasion Edit</span>
            <?php if ($occIsMen): ?>
                <h2>The Wedding Guest &amp; Celebration</h2>
                <p>
                    Festive kurtas, clean tailoring and quiet detail, cut for the season's invitations.
                </p>
            <?php else: ?>
                <h2>The Wedding Guest &amp; Party</h2>
                <p>
                    Graceful silk slip gowns and metallic gold heels curated for your celebratory invitations.
                </p>
            <?php endif; ?>
            <a href="<?= SITE_URL ?>/shop" class="btn-luxury btn-banner">Shop the Edit</a>
        </div>
    </div>

    <!-- Office & Festival Collection Banner -->
    <div class="occasion-banner" style="--occ-bg: url('<?= htmlspecialchars($occasionBg(1)) ?>'); border-top: 1px solid var(--border-light);">
        <div class="occasion-banner-overlay"></div>
        <div class="container occasion-banner-content">
            <span class="occasion-banner-eyebrow">Occasion Edit</span>
            <?php if ($occIsMen): ?>
                <h2>Workwear &amp; Festival</h2>
                <p>
                    Crisp cotton shirts, straight-cut trousers and kurtas that carry from the desk to the evening.
                </p>
                <a href="<?= SITE_URL ?>/shop" class="btn-luxury btn-banner">Shop Menswear</a>
            <?php else: ?>
                <h2>Office Chic &amp; Festival</h2>
                <p>
                    Fluid silk 3-piece suits, hand-embroidered organza dupattas, and tailored linen co-ord sets.
                </p>
                <a href="<?= htmlspecialchars(categoryUrlByName($pdo, '3 Piece Suits')) ?>" class="btn-luxury btn-banner">Shop Ensembles</a>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php // Section 7, the Atelier Film banner, was removed at the owner's request.
      //
      // It played a stock clip hosted on Vimeo's CDN, and that link had expired:
      // the address answered 403 Forbidden, so every visitor — on the live site
      // as well as locally — saw an empty grey band where a film should have
      // been. The "Play Full Film" lightbox pointed at the same dead address.
      //
      // Removed rather than repointed because the footage was never Dievon's.
      // If a real atelier film is shot later this is the place for it, but a
      // borrowed clip that no longer loads is worth less than the space. ?>

<!-- ==================== 8. CUSTOMER REVIEWS ==================== -->
<section class="reviews-section section-space">
    <div class="container reviews-container">
        <span class="editorial-label">Dievon Testimonials</span>
        
        <div class="reviews-slider-wrap">
            <div id="reviewsSlider" class="reviews-slider">
                <div class="review-slide">
                    <div class="review-stars">
                        <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i>
                    </div>
                    <blockquote class="review-quote">
                        "Dievon has completely elevated my wardrobe. The drape of the Mulberry Silk Kurta Set is unmatched. The fabric feels impossibly soft and regal."
                    </blockquote>
                    <cite class="review-author">— Eleanor R.</cite>
                </div>
                
                <div class="review-slide">
                    <div class="review-stars">
                        <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i>
                    </div>
                    <blockquote class="review-quote">
                        "The 3-Piece Silk Suit is a work of art. The hand-embroidered detailing is exquisite, and the Organza Dupatta finishes the outfit with timeless grace."
                    </blockquote>
                    <cite class="review-author">— Sophia K.</cite>
                </div>

                <div class="review-slide">
                    <div class="review-stars">
                        <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i>
                    </div>
                    <blockquote class="review-quote">
                        "The Linen Co-Ord Set is lightweight yet deeply structured, with an incredibly comfortable luxury fit. Express delivery got it to me so fast!"
                    </blockquote>
                    <cite class="review-author">— Charlotte D.</cite>
                </div>
            </div>
        </div>

        <div class="review-dots-container">
            <span class="review-dot active" onclick="goToReview(0)"></span>
            <span class="review-dot" onclick="goToReview(1)"></span>
            <span class="review-dot" onclick="goToReview(2)"></span>
        </div>
    </div>
</section>


<?php /* Dievon Invitations moved to includes/footer.php.
         ─────────────────────────────────────────────────────────────────────
         It sits above the footer on EVERY page now, not only this one. A
         private-membership invitation that only ever appeared to someone who
         reached the bottom of the home page was the one page they were least
         likely to be on when they decided they wanted it. */ ?>

<!-- ==================== 11. FASHION BLOG ==================== -->
<?php if (!empty($latestPosts)): ?>
<section class="maison-journal section-space">
    <div class="container">
        <div class="section-title-wrapper reveal-on-scroll">
            <span class="editorial-label">Dievon Journal</span>
            <h2 class="section-title">Latest Chronicles</h2>
        </div>
        <div class="home-blog-grid">
            <?php /* Shared partial — see includes/blog_card.php. The rail and the
                     /blog listing had drifted into two components with the same
                     name and two sets of CSS; this is the one card. h3 because
                     these sit under this section's own h2, and a trimmed excerpt
                     because the rail is three across rather than a full-width
                     list. */ ?>
            <?php foreach ($latestPosts as $post): ?>
                <?php
                $blogPost           = $post;
                $blogCardTitleTag   = 'h3';
                $blogCardExcerpt    = 120;
                $blogCardCta        = 'Read Journal';
                $blogCardDateFormat = 'F j';
                $blogCardReveal     = false;   // the rail has no scroll-entrance today
                include __DIR__ . '/../includes/blog_card.php';
                ?>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ==================== 12. BRAND STORY ==================== -->
<section class="editorial-section section-space">
    <div class="container">
        <div class="editorial-grid">
            <div class="editorial-img">
                <img src="<?= lookbookUrl(2) ?>" alt="Dievon couture fashion editorial photograph" loading="lazy">
            </div>
            <div class="editorial-content">
                <span class="editorial-label">Artisanal Curation</span>
                <h2 class="editorial-title">Tailored by Hand,<br>Inspired by Legacy</h2>
                <p class="editorial-text">
                    Our fabrics are sourced from organic silk collectives, handloom Chanderi weavers, and artisanal embroidery masters across India. Every thread is woven with utmost care, and every silhouette is tailored to maximize comfort while exuding regal poise. Dievon is a celebration of timeless grace.
                </p>
                <a href="<?= SITE_URL ?>/about" class="btn-luxury-outline">
                    Our Full Story
                </a>
            </div>
        </div>
    </div>
</section>

<!-- ==================== 13. STORE BENEFITS ==================== -->
<section class="benefits-section section-space">
    <div class="container">
        <div class="home-benefits-grid">
            <div class="benefit-item">
                <div class="benefit-icon"><i class="fa-solid fa-truck-fast"></i></div>
                <h3 class="benefit-title">Express Delivery</h3>
                <?php // Said "Complimentary worldwide express shipping" — untrue in both
                      // halves: delivery is free only above the threshold, and international
                      // is a flat fee that never qualifies for it. Reads the same Store
                      // Setting the cart, the policy page and checkout all use. ?>
                <?php // Threshold AND symbol from the same country row — see
                      // freeShippingMinForCountry(). This printed the India figure under
                      // whatever symbol the shopper was browsing in. ?>
                <p class="benefit-text">Free delivery over <?= formatPrice(freeShippingMinForCountry($pdo)) ?></p>
            </div>
            <div class="benefit-item">
                <div class="benefit-icon"><i class="fa-solid fa-rotate-left"></i></div>
                <h3 class="benefit-title">Atelier Returns</h3>
                <p class="benefit-text">Complimentary 14-day return pathway</p>
            </div>
            <div class="benefit-item">
                <div class="benefit-icon"><i class="fa-solid fa-lock"></i></div>
                <h3 class="benefit-title">SSL Security</h3>
                <p class="benefit-text">256-bit encrypted checkout billing</p>
            </div>
            <div class="benefit-item">
                <div class="benefit-icon"><i class="fa-solid fa-leaf"></i></div>
                <h3 class="benefit-title">Sustainable Craft</h3>
                <p class="benefit-text">Traceable materials &amp; fair trade ateliers</p>
            </div>
        </div>
    </div>
</section>



<!-- Slider & Tab scripts -->
<script>
    /* ── Hero slider — driven by OwlCarousel ──────────────────────────────
       The design is unchanged: same markup, same .slide blocks, same dots, same
       entrance animation on .is-active. Only the engine underneath is different.

       It used to be hand-written — a translateX on a flex row, plus mousedown /
       mousemove / mouseup handlers doing swipe detection by hand. That worked
       until it did not: a press on a slide's <img> started the browser's own
       image drag, which swallowed the mousemove events and left the slider dead
       until you clicked elsewhere. It looked random, and it showed up mostly on
       Windows where press-and-drag is how people swipe a carousel.

       Owl handles pointer, touch and mouse dragging itself, on every browser it
       supports, and it is not our code to get wrong again.

       Inside DOMContentLoaded because jQuery and Owl are deferred in the head —
       deferred scripts finish before this event fires, so both are guaranteed
       ready here. */
    document.addEventListener('DOMContentLoaded', function () {
        var $hero = window.jQuery ? jQuery('#heroSlider') : null;

        // No banners, or the library failed to load: leave the markup alone.
        // The first slide is already visible and readable without any JS, so a
        // missing library costs the rotation, never the content.
        if (!$hero || !$hero.length || typeof jQuery.fn.owlCarousel !== 'function') { return; }

        /* Owl's stylesheet is scoped to .owl-carousel, which Owl itself never
           adds — so without this line none of it applies, including
           `.owl-carousel.owl-drag .owl-item { touch-action: pan-y }`. That rule
           is what hands vertical panning to the phone and horizontal swiping to
           the slider; missing, a sideways swipe scrolled the page instead.

           Added here rather than in the markup because Owl's CSS also hides
           .owl-carousel until .owl-loaded exists — in the HTML that would hide
           the hero until JS ran, and permanently if the library failed. */
        $hero.addClass('owl-carousel');

        function markActive() {
            // The existing CSS animates .slide.is-active .slide-content children.
            // Owl clones slides for looping, so mark by index within the ORIGINAL
            // items — .owl-item.active carries the currently shown one.
            jQuery('#heroSlider .slide').removeClass('is-active');
            jQuery('#heroSlider .owl-item.active .slide').addClass('is-active');
            // The dot row this also kept in step is gone; the shared pager's
            // counter says which slide is showing, and it repaints itself from
            // Owl's own translated event.
        }

        // Same reason as the rails in includes/footer.php: a slide is a link
        // around a photograph, and the browser's own drag-and-drop eats the
        // mouse events Owl needs. Firefox ignores the CSS user-drag property,
        // so the attribute has to say it as well.
        $hero.find('a, img').attr('draggable', 'false');

        $hero.owlCarousel({
            items: 1,
            loop: jQuery('#heroSlider .slide').length > 1,
            nav: false,               // the site draws its own arrows
            dots: false,              // and its own dots
            /* Off outright for anyone who has asked the operating system for
               less movement — a band that changes itself every 5.5 seconds is
               the motion, not the transition into it. */
            autoplay: !window.matchMedia('(prefers-reduced-motion: reduce)').matches,
            autoplayTimeout: 5500,
            autoplayHoverPause: true,
            smartSpeed: 800,          // matches the old 0.8s transition
            mouseDrag: true,
            touchDrag: true,
            pullDrag: true,
            autoHeight: false,
            animateOut: false
        });

        /* Owl clones the first and last slides to make the loop seamless, and a
           clone of slide one is a clone of its <h1> — so the rendered page carried
           TWO identical <h1>s even though the HTML has one. The markup already
           takes care to print the heading on the first slide only (see the note
           in the slide loop above); the duplicate arrives after Owl runs, which is
           why it could not be fixed in PHP.

           Demoted rather than removed: the clone is what a shopper sees for the
           moment the loop wraps, so its text has to stay. .slide-content h1 and
           .slide-content h2 are declared together in style.css with identical
           rules, so this is invisible on screen — it only changes what a crawler
           and a screen reader's heading list are handed.

           Runs on initialized AND on refresh, because Owl rebuilds its clones
           whenever the carousel is refreshed or the viewport crosses a
           breakpoint, and a rebuilt clone is a fresh <h1> again. */
        function demoteClonedHeadings() {
            jQuery('#heroSlider .owl-item.cloned').each(function () {
                var clone = this;

                /* The <h1> is only on slide one, so this used to fix slide one and
                   leave slides two, three and four duplicated: their headings are
                   <h2>, which the h1-only selector never saw. Measured on the live
                   homepage — "Grace, Woven in Every Detail" was correctly hidden
                   while "Floral Kurta Sets", "Timeless Lehengas" and "The Art of
                   Effortless Style" each appeared twice in the heading list. */
                jQuery(clone).find('h1').each(function () {
                    var h1 = this;
                    var h2 = document.createElement('h2');
                    h2.className = h1.className;
                    h2.innerHTML = h1.innerHTML;
                    h1.parentNode.replaceChild(h2, h1);
                });

                /* Hide the WHOLE clone, not just its heading. A clone duplicates
                   the slide's link and button too, so a keyboard user tabbing
                   through the hero met the same "Shop Now" two or three times with
                   no way to tell them apart. aria-hidden takes the subtree out of
                   the accessibility tree; tabindex -1 takes it out of the tab
                   order, which aria-hidden alone does not do.

                   Purely additive to what a shopper sees: the clone is what shows
                   for the moment the loop wraps, so it stays on screen. */
                clone.setAttribute('aria-hidden', 'true');
                jQuery(clone).find('a, button, input, [tabindex]').attr('tabindex', '-1');
            });
        }
        $hero.on('initialized.owl.carousel refreshed.owl.carousel', demoteClonedHeadings);
        demoteClonedHeadings();   // initialized has usually already fired by here

        markActive();
        $hero.on('changed.owl.carousel', function () {
            // Wait a tick so .owl-item.active reflects the new position. The
            // clone-index arithmetic that used to live here fed the dot row;
            // markActive() now reads .owl-item.active directly, and the pager's
            // own driver does the same normalisation for the counter.
            setTimeout(markActive, 0);
        });

        /* Registered for the shared pager, like every other rail on this page.
           The band keeps what is genuinely its own — Owl, the loop, the
           autoplay, marking the active slide — and hands over the part that is
           the same everywhere.

           current() is read from Owl rather than remembered, so a drag and a
           button press cannot drift apart. relative() maps Owl's cloned-slide
           index back to the real one; without it a looping carousel reports
           "4 / 3". */
        function heroCount() { return $hero.find('.owl-item').not('.cloned').length || 1; }
        function heroIndex() {
            var owl = $hero.data('owl.carousel');
            if (!owl) { return 0; }
            var i = owl.relative(owl.current());
            return Math.max(0, Math.min(heroCount() - 1, i));
        }

        /* Once the shopper steers, the band stops steering itself. Owl's
           autoplayHoverPause is mouse-only, so a keyboard or touch user had no
           way to stop a slide changing under them mid-read; taking the wheel is
           the clearest signal that they want it still. */
        var heroAuto = true;
        function heroStopAuto() {
            if (!heroAuto) { return; }
            heroAuto = false;
            $hero.trigger('stop.owl.autoplay');
        }

        window.dvRailDrivers = window.dvRailDrivers || {};
        window.dvRailDrivers.hero = {
            /* What a screen reader says. "Hero" is our word for this band, not a
               shopper's — they see featured pieces. */
            label: 'Featured',
            rail:  $hero[0],
            pages: heroCount,
            page:  heroIndex,
            go: function (delta) {
                heroStopAuto();
                $hero.trigger(delta < 0 ? 'prev.owl.carousel' : 'next.owl.carousel');
            },
            /* Owl announces every settle, autoplay's included, so the counter
               follows the band whether the shopper moved it or the timer did. */
            onMove: function (cb) { $hero.on('translated.owl.carousel', cb); }
        };

        /* A drag is steering too. */
        $hero.on('drag.owl.carousel', heroStopAuto);

        /* Kept: nothing in this file calls them any more, but they were the
           documented way to drive the hero from the console and from any
           banner CTA a future template might add. */
        window.nextSlide = function () { heroStopAuto(); $hero.trigger('next.owl.carousel'); };
        window.prevSlide = function () { heroStopAuto(); $hero.trigger('prev.owl.carousel'); };
        window.goToSlide = function (i) { heroStopAuto(); $hero.trigger('to.owl.carousel', [i, 800]); };
    });

    // The Atelier Film lightbox handlers were removed with the section they
    // served (openFilmModal / filmModalEsc / closeFilmModal). Nothing calls them
    // any more, and #filmModal no longer exists, so every one of them would have
    // returned on its first line forever.

    /* ── Collections rail ───────────────────────────────────────────────────
       A driver for the shared pager, nothing more. This used to be a pair of
       globals reached from inline onclick attributes — which is why they could
       not be wrapped in a scope, and why an earlier attempt to tidy them threw
       "scrollCatRail is not defined" on every press. The buttons are wired by
       the engine now, so the scope problem is gone with them. */
    (function () {
        const rail = document.querySelector(".home-categories-grid");
        if (!rail) { return; }

        /* Owl drives this rail on a phone — and ONLY on a phone.
           ────────────────────────────────────────────────────────────────────
           Above 768px this element is not a rail at all: it is a mosaic grid,
           one large tile with three beside it and two beneath, and there is
           nothing to slide. Below it the same markup becomes a horizontal
           strip, and that strip was the last thing on the homepage still
           running on native overflow scrolling while every other slider ran on
           Owl.

           The init is gated at DOMContentLoaded because jQuery and Owl are
           deferred in the head, and gated on the breakpoint because turning the
           mosaic into a carousel would be a different section entirely. */
        const RAIL_ONLY = window.matchMedia("(max-width: 768px)");

        function catOwl() {
            if (!window.jQuery) { return null; }
            const inst = window.jQuery(rail).data("owl.carousel");
            return (inst && typeof inst.maximum === "function") ? inst : null;
        }

        function catInit() {
            if (!RAIL_ONLY.matches || catOwl()) { return; }
            if (!window.jQuery || typeof jQuery.fn.owlCarousel !== "function") { return; }
            const $r = jQuery(rail);

            /* Owl's stylesheet is scoped to .owl-carousel, which Owl never adds
               itself, and it hides .owl-carousel until .owl-loaded exists — so
               this goes on one line before init, never in the markup, or a
               failed library would hide the collections for good. */
            $r.addClass("owl-carousel");
            $r.find("a, img").attr("draggable", "false");

            $r.owlCarousel({
                /* autoWidth, because the tiles carry their own width. It cannot
                   be items:N here: the mosaic's own rules size these in
                   PERCENTAGES of the flex container, and once Owl wraps each
                   tile in an .owl-item those percentages resolve against the
                   stage — the whole track — instead of the screen. The width is
                   restated in viewport units in the stylesheet for exactly that
                   reason. */
                autoWidth: true,
                margin: 15,          // the phone gutter every rail uses
                nav: false,          // the page draws its own arrows
                dots: false,         // and its own counter
                loop: false,
                mouseDrag: true,
                touchDrag: true,
                freeDrag: false,
                slideBy: 1,
                smartSpeed: 500
            });

            catSyncFit();
            $r.on('refreshed.owl.carousel resized.owl.carousel changed.owl.carousel',
                  function () { window.setTimeout(catSyncFit, 0); });
        }

        /* A row that fits sits centred rather than hard left.
           ────────────────────────────────────────────────────────────────────
           With one or two collections the tiles do not fill the rail, and Owl
           lays its stage out from the left — so they sat against the left edge
           with a band of empty rail beside them, which reads as something
           having failed to load rather than as a short row.

           The stage is measured to its real content first. Owl's own inline
           width INCLUDES the margin after the final item, so centring that box
           would leave the tiles one gap-width off true centre. Owl's value is
           kept and restored the moment the row can scroll again, because its
           positioning maths depends on that trailing margin being counted.

           Same approach the Occasion strip gets from includes/footer.php. */
        function catSyncFit() {
            const outer = rail.querySelector('.owl-stage-outer');
            const stage = rail.querySelector('.owl-stage');
            if (!outer || !stage) { return; }
            /* Measure the CONTENT, not the stage box. Under autoWidth Owl gives
               the stage a width of its own that runs well past the last tile,
               so asking whether the STAGE fits answers no even when the tiles
               plainly do — two tiles reported "does not fit" while leaving 72px
               of empty rail beside them. The sum of the items and the gaps
               between them is the only honest measure of how wide the row is. */
            let content = 0;
            Array.prototype.forEach.call(stage.children, function (item, i, all) {
                content += item.getBoundingClientRect().width;
                if (i < all.length - 1) {
                    content += parseFloat(getComputedStyle(item).marginRight) || 0;
                }
            });
            const fits = content > 0
                      && content <= outer.getBoundingClientRect().width + 2;
            rail.classList.toggle('owl-content-fits', fits);

            if (fits) {
                if (stage.dataset.owlWidth === undefined) {
                    stage.dataset.owlWidth = stage.style.width || '';
                }
                stage.style.width = Math.ceil(content) + 'px';
            } else if (stage.dataset.owlWidth !== undefined) {
                stage.style.width = stage.dataset.owlWidth;
                delete stage.dataset.owlWidth;
            }
        }

        function catDestroy() {
            const inst = catOwl();
            if (!inst) { return; }
            jQuery(rail).trigger("destroy.owl.carousel").removeClass("owl-carousel");
        }

        /* Crossing the breakpoint has to hand the element back. Owl leaves its
           wrappers and inline widths behind otherwise, and the mosaic above
           768px would be laid out inside a stage that is no longer being
           driven. */
        function catSync() { RAIL_ONLY.matches ? catInit() : catDestroy(); }

        /* This block runs at parse time — the surrounding IIFE is not deferred —
           and jQuery and Owl are deferred in the head, so neither exists yet.
           Deferred scripts finish before DOMContentLoaded, which is the first
           moment the library can be there. Calling catSync() directly here did
           nothing at all: it found no jQuery, returned, and the rail stayed on
           native scrolling while reporting no error. */
        if (document.readyState === "loading") {
            document.addEventListener("DOMContentLoaded", catSync);
        } else {
            catSync();
        }
        if (RAIL_ONLY.addEventListener) { RAIL_ONLY.addEventListener("change", catSync); }
        else if (RAIL_ONLY.addListener)  { RAIL_ONLY.addListener(catSync); }

        /* And a debounced resize as well, because the matchMedia change event
           cannot be relied on alone: measured here crossing 768px in both
           directions without it firing once, leaving Owl driving a mosaic at
           1440px — five pages of arrows over a grid that does not move.
           catSync is idempotent (catInit returns if Owl is already up,
           catDestroy if it is not), so the two paths cannot fight. */
        var catResizeTimer;
        window.addEventListener("resize", function () {
            window.clearTimeout(catResizeTimer);
            catResizeTimer = window.setTimeout(function () { catSync(); catSyncFit(); }, 150);
        });

        window.dvRailDrivers = window.dvRailDrivers || {};
        window.dvRailDrivers.cat = {
            label: "Dievon Collections",
            rail:  rail,
            /* Above 768 this element is a mosaic GRID, not a rail — scrollWidth
               equals clientWidth, dvRail reports one page, and the engine
               retires the control. That is the same measured test this section
               already used, kept: whether a rail scrolls depends on tile width,
               gap and viewport together, so counting tiles would be a guess. */
            /* Owl when it is driving, the native helpers when it is not —
               above 768px this is a grid that cannot move, and dvRail's
               scrollWidth test is what correctly reports one page and retires
               the control. */
            pages: function () {
                const o = catOwl();
                return o ? Math.max(1, o.maximum() + 1) : window.dvRail.pages(rail);
            },
            page: function () {
                const o = catOwl();
                return o ? Math.max(0, Math.min(o.maximum(), o.current())) : window.dvRail.page(rail);
            },
            go: function (delta) {
                const o = catOwl();
                if (!o) { window.dvRail.go(rail, delta); return; }
                const total = o.maximum() + 1;
                const next  = ((o.current() + delta) % total + total) % total;
                // Same 300-over-500 override Shop by Occasion had; see the note
                // on that rail's go(). The rail's own smartSpeed, not a literal.
                jQuery(rail).trigger("to.owl.carousel", [next, o.settings.smartSpeed]);
            },
            onMove: function (cb) {
                rail.addEventListener("scroll", cb, { passive: true });
                if (window.jQuery) {
                    jQuery(rail).on("changed.owl.carousel translated.owl.carousel",
                        function () { window.setTimeout(cb, 0); });
                }
            }
        };
    })();

    /* scrollCarousel() drove the New Arrivals rail's arrows. That section is an
       editorial gallery now — four panels side by side, expanded on hover, with
       nothing to scroll — so the arrows and this function went with it. */

    /* ── Best Sellers / Trending Now: the photo fan ───────────────────────
       A slider in about a hundred lines, because the thing being slid cannot go
       in a carousel library: Owl wraps each item and clips its stage, and these
       cards rotate, lift 14px and scale to 1.03 — they would be cut off at the
       exact moment they are being looked at.

       Nothing here measures in CSS units. The step between cards is read off the
       rendered DOM (the gap between two items' offsetLeft), so the four
       breakpoints in style.css are the only place the visible count is written
       down, and changing one of them needs no matching change here.

       Never autoplays. There is no timer in this file. */
    /* Inside DOMContentLoaded because jQuery and Owl are deferred in the head:
       deferred scripts finish before that event and neither exists at parse
       time. This block ran as a plain IIFE while it had its own engine and
       needed no library; the moment Owl drives the fan it does. */
    document.addEventListener('DOMContentLoaded', function () {
        var section = document.querySelector('.photo-fan-section');
        if (!section) { return; }

        var panels = Array.prototype.slice.call(section.querySelectorAll('[data-pf-panel]'));
        var tabs   = Array.prototype.slice.call(section.querySelectorAll('.pf-tab'));
        var sliders = [];

        /* Owl drives the fan now, like every other slider on the page.
           ────────────────────────────────────────────────────────────────────
           The design is untouched: the prints keep their tilt, their overlap,
           their stacking order and their lift. What goes is a hand-rolled
           engine — measure, paint, a pointer drag with its own threshold, its
           own inert bookkeeping — replaced by the library that was already
           loaded for the hero and the Occasion strip.

           autoWidth, because the fan shows a FRACTION of a card on purpose:
           4.2 across, so the strip is visibly cut by the edge of the screen and
           says there is more without any furniture saying it. Owl's `items`
           counts whole cards and cannot express that, so the width is written
           onto each print in pixels and Owl is told to measure rather than
           divide. --pf-visible stays the single place the number lives, read
           back out of the stylesheet so the breakpoints keep owning it. */
        function makeSlider(panel) {
            var track = panel.querySelector('[data-pf-track]');
            var view  = panel.querySelector('.pf-viewport');
            var items = Array.prototype.slice.call(panel.querySelectorAll('.pf-item'));
            if (!track || !view || !items.length) { return null; }
            if (!window.jQuery || typeof jQuery.fn.owlCarousel !== 'function') { return null; }

            var $t = jQuery(track);

            /* The prints used to overlap by a negative margin — a stack of
               photographs dropped on a table. They stand apart now, so this is
               a real gutter, and Owl owns it rather than CSS. */
            /* 22px, and the SAME 22 at every width. It was 20 above the
               breakpoint and 12 below — two numbers doing one job, which made
               the row read tighter on a phone than the rails beside it. */
            var PF_GAP = 22;

            /* Owl's stylesheet is scoped to .owl-carousel, which Owl never adds
               itself, and it hides .owl-carousel until .owl-loaded exists —
               so this goes on one line before init, never in the markup, or a
               failed library would hide the prints for good. */
            $t.addClass('owl-carousel');

            /* A print is a link round a photograph and both are draggable
               objects by default; the native drag eats the pointer stream Owl
               needs. Firefox ignores the CSS property, so the attribute says it
               too. */
            $t.find('a, img').attr('draggable', 'false');

            function visibleCount() {
                var v = parseFloat(getComputedStyle(panel.closest('.photo-fan-section') || panel)
                                    .getPropertyValue('--pf-visible'));
                return (v && v > 0) ? v : 4;
            }

            /* The print's width, in pixels, so a fractional count survives. */
            /* The gutter comes off the print's width, not out of the row.
               ────────────────────────────────────────────────────────────────
               --pf-visible says how many prints are on screen and Owl puts the
               margin BETWEEN them, so sizing each at view/visible and then
               adding a margin would push the count below what the stylesheet
               asked for and cut the last print short. The gap is subtracted
               from the print instead: 4.2 across still means 4.2 across. */
            function sizeItems() {
                var per = visibleCount();
                var w = (view.clientWidth / per) - PF_GAP;
                if (w < 60) { w = view.clientWidth / per; }   // absurdly narrow screen
                items.forEach(function (el) { el.style.width = w + 'px'; });
                return w;
            }

            sizeItems();

            $t.owlCarousel({
                autoWidth: true,        // widths come from the prints themselves
                /* A real gutter now. The prints used to overlap by a negative
                   margin — a stack of photographs dropped on a table — and the
                   row is meant to read as separate prints standing apart. */
                margin: PF_GAP,
                /* Owl picks the HIGHEST breakpoint at or below the viewport, so
                   a lone 0 entry wins at every width and the top-level margin
                   never applies. Both steps have to be spelled out: 12 on a
                   phone, where one print fills the screen and the rails all
                   agreed on 12 long ago, and the full gutter above it. */
                responsive: {
                    0:   { margin: 15 },        // phone
                    768: { margin: PF_GAP }     // desktop keeps 22
                },
                nav: false,             // the page draws its own arrows
                dots: false,            // and its own counter
                loop: false,            // a product strip stops at the end
                mouseDrag: true,
                touchDrag: true,
                freeDrag: false,
                slideBy: 1,
                smartSpeed: 620,        // the strip's own 620ms, kept
                autoHeight: false
            });

            /* A fan of prints falls LEFT over right, so an earlier print has to
               out-rank a later one — and source order paints the opposite way,
               which put every print's right corner under its neighbour and
               swallowed the wishlist heart. The rule used to live on .pf-item;
               Owl's wrapper is the stacking element now, so it moves here. */
            function stack() {
                $t.find('.owl-item').each(function (i, el) { el.style.zIndex = String(40 - i); });
            }
            stack();

            /* A print scrolled out of frame must not be reachable by Tab, or the
               focus ring walks off the side of the screen and the page appears
               to jump for no reason. Owl marks what is on screen; this turns
               that into the tab order. */
            function gateTabbing() {
                $t.find('.owl-item').each(function (i, el) {
                    var off = !el.classList.contains('active');
                    /* preventScroll, or this JUMPS THE PAGE. focus() on a tabindex="-1"
                       element scrolls it into view by default, so moving focus off a print
                       that had paged out of frame dragged the whole page to the strip. The
                       point here is only to get focus out of an element about to go inert;
                       where the reader is looking is none of its business. */
                    if (off && el.contains(document.activeElement)) {
                        try { view.focus({ preventScroll: true }); }
                        catch (err) { view.focus(); }
                    }
                    if (off) { el.setAttribute('inert', ''); }
                    else     { el.removeAttribute('inert'); }
                });
            }
            gateTabbing();

            function owl() { return $t.data('owl.carousel'); }
            function shown() {
                var n = $t.find('.owl-item.active').length;
                return Math.max(1, n || Math.floor(visibleCount()));
            }
            function pages() {
                /* An inactive tab is display:none, so Owl measures a zero-width
                   viewport and marks one print visible — which reported three
                   pages for a strip of three that cannot move, and put arrows
                   over it the moment the tab was opened. Nothing can page while
                   it has no width. */
                if (!view.clientWidth) { return 1; }
                return Math.max(1, items.length - shown() + 1);
            }
            function page()  {
                var o = owl();
                if (!o) { return 0; }
                return Math.max(0, Math.min(pages() - 1, o.relative(o.current())));
            }

            var onPaint = null;
            $t.on('changed.owl.carousel translated.owl.carousel', function () {
                window.setTimeout(function () {
                    stack(); gateTabbing();
                    if (onPaint) { onPaint(); }
                }, 0);
            });

            var api = {
                panel: panel,
                /* Both of these repaint the pager by hand. Owl only fires
                   changed/translated when it actually moves, so a tab switched
                   back to a strip already at zero moved nothing, said nothing,
                   and left the arrows showing over a strip with one page. */
                /* Called when this tab is opened, and it has to RE-MEASURE, not
                   just rewind.
                   ────────────────────────────────────────────────────────────
                   The inactive panel is display:none, so Owl initialises against
                   a viewport of zero width and writes 0px onto every print.
                   Opening the tab showed an empty strip: the panel was there,
                   the prints were there, and every one of them was nothing
                   wide. Nothing re-measures on its own — Owl has no idea the
                   element became visible — so the width is recomputed here and
                   handed back before the strip is rewound. */
                reset: function () {
                    sizeItems();
                    $t.trigger('refresh.owl.carousel');
                    $t.trigger('to.owl.carousel', [0, 0, true]);
                    stack(); gateTabbing();
                    if (onPaint) { onPaint(); }
                },
                refresh: function () {
                    sizeItems();
                    $t.trigger('refresh.owl.carousel');
                    stack(); gateTabbing();
                    if (onPaint) { onPaint(); }
                },
                driver: {
                    label: panel.getAttribute('data-pf-label') || 'Products',
                    rail:  view,
                    pages: pages,
                    page:  page,
                    go: function (delta) {
                        $t.trigger(delta > 0 ? 'next.owl.carousel' : 'prev.owl.carousel');
                    },
                    onMove: function (cb) { onPaint = cb; }
                }
            };
            return api;
        }

        window.dvRailDrivers = window.dvRailDrivers || {};
        panels.forEach(function (panel) {
            var s = makeSlider(panel);
            if (!s) { return; }
            sliders.push(s);
            /* One pager per tab panel, keyed the same way the panel is. */
            window.dvRailDrivers['pf-' + panel.getAttribute('data-pf-panel')] = s.driver;
        });

        /* Measure once the photographs have settled. The cards are 3:4 by CSS so
           nothing moves as they arrive, but a late web font can still change the
           caption's height, and the step is read from the layout. */
        function refreshAll() { sliders.forEach(function (s) { s.refresh(); }); }
        refreshAll();
        window.addEventListener('load', refreshAll);
        var rt;
        window.addEventListener('resize', function () {
            clearTimeout(rt);
            rt = setTimeout(refreshAll, 120);
        });

        /* ── Tabs ──────────────────────────────────────────────────────────
           The inactive panel is hidden outright rather than moved off screen, so
           it costs no layout, cannot be tabbed into and is invisible to a screen
           reader. It also cannot be measured while hidden — offsetLeft is 0 for
           everything — so the incoming panel is measured AFTER it is shown, and
           its slider is reset to the first product as the brief asks. */
        function selectTab(key) {
            tabs.forEach(function (t) {
                var on = t.getAttribute('aria-controls') === 'pfPanel-' + key;
                t.classList.toggle('is-active', on);
                t.setAttribute('aria-selected', on ? 'true' : 'false');
                t.tabIndex = on ? 0 : -1;
            });
            panels.forEach(function (p) {
                var on = p.getAttribute('data-pf-panel') === key;
                p.hidden = !on;
                p.classList.toggle('is-active', on);
            });
            sliders.forEach(function (s) {
                if (s.panel.getAttribute('data-pf-panel') === key) { s.reset(); }
            });
        }

        tabs.forEach(function (tab, i) {
            tab.addEventListener('click', function () {
                selectTab(tab.getAttribute('aria-controls').replace('pfPanel-', ''));
            });
            /* Roving tabindex: one stop for the whole tablist, arrows to move
               within it. Anything else and a keyboard shopper tabs through every
               tab before reaching the products. */
            tab.addEventListener('keydown', function (e) {
                var d = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
                if (!d) { return; }
                e.preventDefault();
                var nextTab = tabs[(i + d + tabs.length) % tabs.length];
                nextTab.focus();
                nextTab.click();
            });
        });
    });

    // ── Reviews Testimonials Carousel Slider ───────────────
    let reviewIndex = 0;
    const reviewsSlider = document.getElementById('reviewsSlider');
    const reviewDots = document.querySelectorAll('.review-dot');

    function updateReviewPosition() {
        reviewsSlider.style.transform = `translateX(-${reviewIndex * 33.333}%)`;
        reviewDots.forEach((dot, idx) => {
            if(idx === reviewIndex) {
                dot.style.background = 'var(--color-primary)';
                dot.style.opacity = '1';
                dot.classList.add('active');
            } else {
                dot.style.background = 'var(--text-muted)';
                dot.style.opacity = '0.5';
                dot.classList.remove('active');
            }
        });
    }

    function goToReview(idx) {
        reviewIndex = idx;
        updateReviewPosition();
    }

    setInterval(() => {
        reviewIndex = (reviewIndex + 1) % 3;
        updateReviewPosition();
    }, 6000);
</script>

<?php /* ══ The shared rail pager engine ═══════════════════════════════════════
         One prev button, one next button and one counter, driving four rails
         that move in four completely different ways: New Arrivals swaps which
         panels are in the flow, Collections scrolls a native overflow rail,
         Trending translates a flex track, and Shop by Occasion asks Owl.

         The sections do not share any of that. What they share is the control,
         so each one registers a tiny driver — pages(), page(), go(delta) — and
         this engine owns everything else: painting the counter, announcing the
         move, and retiring a pager whose rail has nowhere to go.

         Placed last on purpose. Sections assign into window.dvRailDrivers as
         plain data, so no section has to care whether the engine has loaded
         yet, and the engine runs once when every section has had its say. */ ?>
<script>
(function () {
    /* Native-scroll helpers, published before the drivers are read.
       ─────────────────────────────────────────────────────────────────────
       Three of the four rails are, at some breakpoint, an ordinary
       overflow-x element — Collections on a phone, New Arrivals below 1025,
       and the Occasion strip if Owl never initialises. Paging one is the same
       eight lines every time, so they live here once and the drivers borrow
       them. Drivers reference window.dvRail lazily, from inside their own
       methods, which the engine only ever calls after this assignment. */
    window.dvRail = {
        /* Laid out in ITEMS, not in pixels, because these rails carry
           scroll-snap-align on their cards. Scrolling to a raw multiple of
           clientWidth lands between two snap points and the browser drags the
           rail back to the nearest card — measured on the New Arrivals rail at
           390px: a press meant for 780 settled at 442, and the counter then
           disagreed with the rail for the rest of the session. Snapping to the
           first card of the page is a position the browser already agrees with,
           so the two never argue. */
        items: function (el) {
            return Array.prototype.filter.call(el.children, function (c) {
                return c.offsetWidth > 0 && getComputedStyle(c).position !== 'absolute';
            });
        },
        /* How many cards are on screen at once. The +(step - firstWidth) term is
           the gap: without it a rail showing 2.0 cards reports 1. */
        perPage: function (el) {
            var it = this.items(el);
            if (it.length < 2) { return 1; }
            var step = it[1].offsetLeft - it[0].offsetLeft;
            if (step < 1) { return 1; }
            return Math.max(1, Math.floor((el.clientWidth + (step - it[0].offsetWidth)) / step));
        },
        pages: function (el) {
            if (!el || el.clientWidth < 1) { return 1; }
            /* Whether the thing can move at all is a separate question from how
               many items it holds, and it has to be asked first. Above 768px the
               collections element is a mosaic GRID: six tiles that wrap into
               rows and do not scroll, but whose first two still sit side by side,
               so an item-and-step count cheerfully reported three pages and put
               a live control under a grid that cannot move. scrollWidth against
               clientWidth is the only honest test — the same one this section
               used before it moved to the shared pager. */
            if (el.scrollWidth <= el.clientWidth + 2) { return 1; }
            var it = this.items(el);
            if (!it.length) { return 1; }
            return Math.max(1, Math.ceil(it.length / this.perPage(el)));
        },
        page: function (el) {
            if (!el || el.clientWidth < 1) { return 0; }
            var it = this.items(el), per = this.perPage(el);
            if (!it.length) { return 0; }
            /* Read from the rail's real position rather than a remembered index,
               so a finger swipe and a button press cannot drift apart. */
            var left = el.scrollLeft;
            var best = 0, bestD = Infinity;
            for (var i = 0; i < it.length; i += per) {
                var d = Math.abs(this.offsetOf(el, it[i]) - left);
                if (d < bestD) { bestD = d; best = i / per; }
            }
            return Math.round(best);
        },
        /* Relative to the scroller, whatever the offsetParent happens to be. */
        offsetOf: function (el, item) {
            return el.scrollLeft
                 + (item.getBoundingClientRect().left - el.getBoundingClientRect().left);
        },
        go: function (el, delta) {
            if (!el) { return; }
            /* Wrap, like every other rail here. A shopper at the end who presses
               next and gets nothing has been handed a dead control; the pages
               that disable instead need a disabled style and an explanation. */
            var it = this.items(el), per = this.perPage(el), total = this.pages(el);
            if (!it.length) { return; }
            var next = ((this.page(el) + delta) % total + total) % total;
            var item = it[Math.min(it.length - 1, next * per)];
            var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            el.scrollTo({ left: this.offsetOf(el, item), behavior: reduce ? 'auto' : 'smooth' });
        }
    };

    var drivers = window.dvRailDrivers || {};

    function wire(pager) {
        var driver = drivers[pager.getAttribute('data-pager')];
        /* A driver can register LATE. Most sections register inline, before this
           runs, but the hero's lives inside jQuery(document).ready() because it
           needs Owl — so on the first pass it is not there yet. Hide the control
           for now and say we could not wire it; the retry on load picks it up.
           Bailing permanently here is what left the hero with no navigation at
           all the first time this was built. */
        if (!driver) { pager.hidden = true; return false; }

        var count  = pager.querySelector('[data-pager-count]');
        var status = pager.querySelector('[data-pager-status]');

        /* paint() is safe to call as often as you like — it is wired to resize.
           announce() is NOT: it writes a live region, so it is called only from
           a real button press. A status region fed by a resize listener fires on
           every address-bar collapse on a phone. */
        function paint() {
            var total = driver.pages();
            /* Measured, not guessed from viewport width. This is what replaces
               the four unrelated display:none rules that used to strand a rail
               with no control at all on a phone: the pager goes away only when
               the rail genuinely cannot move. */
            pager.hidden = !(total > 1);
            if (!(total > 1) || !count) { return; }
            count.textContent = (driver.page() + 1) + '\u200a/\u200a' + total;
        }

        function announce() {
            if (!status) { return; }
            status.textContent = driver.label + ', page ' + (driver.page() + 1)
                               + ' of ' + driver.pages();
        }

        pager.addEventListener('click', function (event) {
            var button = event.target.closest('[data-pager-step]');
            if (!button) { return; }
            driver.go(parseInt(button.getAttribute('data-pager-step'), 10) || 1);
            paint();
            announce();
        });

        /* A rail that moves by itself — a swipe, an Owl drag — tells the engine
           so the counter does not go stale. Still never announces: the shopper
           who swiped already knows the rail moved. */
        if (typeof driver.onMove === 'function') { driver.onMove(paint); }

        var resizeTimer;
        window.addEventListener('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(paint, 150);
        });

        /* Again after load, because two things settle late: Owl initialises the
           Occasion strip from includes/footer.php, which runs after this, and a
           web font arriving can change a card's width and so the page count.
           Both would otherwise leave a control showing for a rail that cannot
           move, or hidden for one that can. */
        window.addEventListener('load', function () { setTimeout(paint, 60); });

        paint();
        return true;
    }

    var pagers  = Array.prototype.slice.call(document.querySelectorAll('.dv-pager[data-pager]'));
    var pending = pagers.filter(function (pager) { return !wire(pager); });

    /* One retry, once everything has had a chance to register. Owl is the only
       thing that needs it today, and it is always up by load. */
    if (pending.length) {
        window.addEventListener('load', function () {
            setTimeout(function () {
                drivers = window.dvRailDrivers || drivers;
                pending.forEach(wire);
            }, 80);
        });
    }
})();
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
