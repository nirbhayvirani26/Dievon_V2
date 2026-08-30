<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/config.php';

$productId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Fetch product details
$product = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = :id AND available = 1");
    $stmt->execute(['id' => $productId]);
    $product = $stmt->fetch();
} catch (PDOException $e) {}

if (!$product) {
    // This used to be `header('Location: shop.php')` — a 302 to a RELATIVE path,
    // which under the /product/<slug>-<id> route resolves to /product/shop.php and
    // 404s. So every discontinued or mistyped product URL answered "temporarily
    // moved" and then led nowhere, which keeps the dead URL in the index and
    // hands the shopper an error page.
    //
    // A product that never existed is a 404. One that existed and was withdrawn is
    // 410 Gone, which Google drops from the index faster than a 404 — and we send
    // the shopper on to its collection rather than a dead end.
    $withdrawn = null;
    try {
        $st = $pdo->prepare("SELECT * FROM products WHERE id = :id");
        $st->execute(['id' => $productId]);
        $withdrawn = $st->fetch() ?: null;
    } catch (PDOException $e) {}

    if ($withdrawn && !empty($withdrawn['category'])) {
        http_response_code(410);
        $goneCategoryUrl = categoryUrlByName($pdo, (string)$withdrawn['category']);
        $goneProductName = (string)$withdrawn['name'];
    } else {
        http_response_code(404);
    }
    $noindex = true;
    require __DIR__ . '/404.php';
    exit;
}

// ── Country pricing ──────────────────────────────────────────────
// A non-home country prices the whole product flat — no per-size or
// per-colour granularity exists in product_country_prices — so the override
// happens once, here, before mrpPrice/discount and the colour/size blocks
// below ever read $product['price']. Everything downstream (this page,
// effectiveVariantPrice() for the size switcher) then just works with
// whatever is in $product, home or abroad, without knowing which.
$dvCountryCode      = function_exists('currentCountryCode') ? currentCountryCode() : 'IN';
$dvHomeCountryCode  = strtoupper((function_exists('homeCountryRow') ? (homeCountryRow()['country_code'] ?? 'IN') : 'IN'));
$dvIsHomeCountry    = strtoupper($dvCountryCode) === $dvHomeCountryCode;
$dvSoldInCountry    = true;
if (!$dvIsHomeCountry) {
    $dvCountryPricing = function_exists('productCountryPricing') ? productCountryPricing($product, $dvCountryCode) : null;
    $dvSoldInCountry  = $dvCountryPricing !== null;
    if ($dvSoldInCountry) {
        $product['price']     = $dvCountryPricing['price'];
        $product['mrp_price'] = $dvCountryPricing['mrp'];
    }
}
// Colour/size price overrides are INR-only figures with no country
// equivalent, so abroad they are ignored in favour of the flat price above
// rather than applied on top of the wrong currency.
$dvAllowVariantOverrides = $dvIsHomeCountry;

// Canonical SEO-friendly URL (/product/aurelia-silk-kurti-1). The id at the end is the
// real lookup key, so old links (?id=1) still resolve — they just 301 to this form.
//
// Built through productUrl() so the owner's own "SEO URL Slug" is honoured here
// too. This rebuilt the slug from the product NAME, which meant a custom slug was
// 301-redirected straight back to the name-based one — the admin field could be
// filled in, every link on the site would use it, and this line would undo it on
// arrival. One function decides the canonical address, everywhere.
$canonicalUrl    = productUrl($product['id'], $product['name'], $product['seo_url'] ?? null);
$productSlugPath = (string)parse_url($canonicalUrl, PHP_URL_PATH);
// SITE_URL may carry a subdirectory (/DievonOrders on MAMP); the suffix compare
// below needs the path as it appears in REQUEST_URI, which it does.
$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (substr($requestPath, -strlen($productSlugPath)) !== $productSlugPath) {
    header('Location: ' . $canonicalUrl, true, 301);
    exit;
}

$hasVideo = !empty(trim($product['video_url'] ?? ''));

// Fetch genuine approved reviews with exception safety (used by both the Product schema and the Reviews section below)
$dbReviews = [];
try {
    $revStmt = $pdo->prepare("SELECT pr.*, c.name as customer_name FROM product_reviews pr LEFT JOIN customers c ON pr.customer_id = c.id WHERE pr.product_id = :pid AND pr.status = 'Approved' ORDER BY pr.created_at DESC");
    $revStmt->execute(['pid' => $productId]);
    $dbReviews = $revStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $exRev) {
    $dbReviews = [];
}

$revCount = count($dbReviews);
// Not 5.0. This default was rendered as five solid gold stars on every product
// with no reviews at all, directly above the words "No customer reviews yet." —
// a rating the shop invented for itself. Nothing reads it when $revCount is 0.
$avgRating = 0.0;
if ($revCount > 0) {
    $sum = array_sum(array_column($dbReviews, 'rating'));
    $avgRating = round($sum / $revCount, 1);
}


// Fetch variants
$variants = [];
try {
    // color_id IS NULL — the PLAIN sizes only, matching what the admin's Product
    // Sizes panel edits.
    //
    // Without it this pulled in every colour's sizes too, and the two disagreed:
    // switch a colour off and $productColors goes empty, so the page falls to the
    // plain-size branch — which was still holding that hidden colour's rows. #13
    // with Blue deactivated offered 30/XS and 32/S, Blue's own stock, sold under
    // no colour at all, while the admin panel that supposedly edits those sizes
    // showed nothing. Deactivating a colour has to hide its sizes with it.
    $stmt = $pdo->prepare("SELECT * FROM product_variants WHERE product_id = :id AND color_id IS NULL AND available = 1 ORDER BY sort_order ASC, id ASC");
    $stmt->execute(['id' => $productId]);
    $variants = $stmt->fetchAll();
} catch (PDOException $e) {}

// Fetch additional images
//
// A row whose file is not on disk is left out of the page rather than rendered
// as a broken tile. It stays in the database: the file may be sitting in the
// media quarantine waiting to be restored, or half-way through an upload, and
// the row carries the order the owner arranged. Dropping the row to avoid a
// broken tile threw that away permanently — see productCoverFallback().
$additionalImages = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM product_images WHERE product_id = :id ORDER BY sort_order ASC, id ASC");
    $stmt->execute(['id' => $productId]);
    $productImageDir = __DIR__ . '/../uploads/products/';
    $additionalImages = array_values(array_filter(
        $stmt->fetchAll(),
        fn($r) => trim((string)$r['image']) !== '' && is_file($productImageDir . $r['image'])
    ));
} catch (PDOException $e) {}

// ── Related products ────────────────────────────────────────────────────────
// Matched on the legacy free-text category column, which meant a piece in
// "Short Kurtis" could only ever be shown alongside other Short Kurtis — one
// other product in this catalogue — and anything whose category_id was set but
// whose name string differed found nothing at all.
//
// Order of preference: what the owner explicitly chose, then the category's own
// subtree, then its parent's, then anything else live. Four slots, always full
// while the catalogue has four products.
$related = [];
try {
    $seen = [$productId => true];

    $addRows = function (array $rows) use (&$related, &$seen) {
        foreach ($rows as $r) {
            if (count($related) >= 4) { return; }
            if (isset($seen[(int)$r['id']])) { continue; }
            $seen[(int)$r['id']] = true;
            $related[] = $r;
        }
    };

    $liveWhere = "available = 1 AND (is_deleted = 0 OR is_deleted IS NULL)";

    // 1. Explicit picks.
    $explicitIds = array_values(array_filter(array_map('intval',
        preg_split('/[^0-9]+/', (string)($product['related_ids'] ?? '')) ?: [])));
    if ($explicitIds) {
        $in = implode(',', $explicitIds);
        $addRows($pdo->query("SELECT * FROM products WHERE id IN ($in) AND $liveWhere")->fetchAll());
    }

    // 2. The category subtree, then the parent's subtree.
    $catId = (int)($product['category_id'] ?? 0);
    $tryCategories = [];
    if ($catId > 0) {
        $tryCategories[] = categoryDescendantIds($pdo, $catId);
        $all = allCategoriesById($pdo);
        $parentId = (int)($all[$catId]['parent_id'] ?? 0);
        if ($parentId > 0) { $tryCategories[] = categoryDescendantIds($pdo, $parentId); }
    }
    foreach ($tryCategories as $ids) {
        if (count($related) >= 4) { break; }
        $in = implode(',', array_map('intval', $ids));
        $addRows($pdo->query(
            "SELECT * FROM products WHERE category_id IN ($in) AND id <> " . (int)$productId . " AND $liveWhere ORDER BY id DESC LIMIT 8"
        )->fetchAll());
    }

    // 3. Legacy name match, for rows whose category_id was never backfilled.
    if (count($related) < 4 && !empty($product['category'])) {
        $st = $pdo->prepare("SELECT * FROM products WHERE category = :c AND id <> :id AND $liveWhere LIMIT 8");
        $st->execute(['c' => $product['category'], 'id' => $productId]);
        $addRows($st->fetchAll());
    }

    // 4. Anything else live, so the section is never a lonely single card.
    //
    // Scoped to THIS product's own audience, not the visitor's current side: a
    // men's shirt reached from a search or a shared link must not fill its
    // "you may also like" row with kurtis. Steps 2 and 3 above are already safe
    // because they match on the product's own category — this catch-all was the
    // one that could cross over.
    $relatedGenderSql = function_exists('shopGenderSqlFilter')
        ? shopGenderSqlFilter('', categoryGender($catId))
        : '';
    if (count($related) < 4) {
        $addRows($pdo->query(
            "SELECT * FROM products WHERE id <> " . (int)$productId . " AND $liveWhere{$relatedGenderSql} ORDER BY id DESC LIMIT 12"
        )->fetchAll());
    }
} catch (PDOException $e) {}

// ── Discount pricing (MRP vs selling price) ──────────────────────
$mrpPrice = (float)($product['mrp_price'] ?? 0);
$hasDiscount = $mrpPrice > (float)$product['price'];
$discountPercent = $hasDiscount ? (int)round((($mrpPrice - (float)$product['price']) / $mrpPrice) * 100) : 0;

// ── Colour variants (Biba-style image colour selector) ──────────
// Purely additive: products with no rows here fall straight through
// to the existing plain size selector below, unchanged.
$productColors = [];
try {
    $cStmt = $pdo->prepare("SELECT * FROM product_colors WHERE product_id = :id AND is_active = 1 ORDER BY sort_order ASC, id ASC");
    $cStmt->execute(['id' => $productId]);
    $productColors = $cStmt->fetchAll();
    foreach ($productColors as &$col) {
        $imgStmt = $pdo->prepare("SELECT * FROM product_color_images WHERE color_id = :cid ORDER BY sort_order ASC, id ASC");
        $imgStmt->execute(['cid' => $col['id']]);
        $col['images'] = $imgStmt->fetchAll();

        $szStmt = $pdo->prepare("SELECT * FROM product_variants WHERE color_id = :cid AND available = 1 ORDER BY sort_order ASC, id ASC");
        $szStmt->execute(['cid' => $col['id']]);
        $col['sizes'] = $szStmt->fetchAll();

        $col['effective_price'] = ($dvAllowVariantOverrides && $col['price_override'] !== null) ? (float)$col['price_override'] : (float)$product['price'];
        $col['effective_mrp'] = ($dvAllowVariantOverrides && $col['mrp_price_override'] !== null) ? (float)$col['mrp_price_override'] : $mrpPrice;
    }
    unset($col);
    // Every active colour is shown. No filtering.
    //
    // This filtered on "has photographs OR sizes", which silently deleted a
    // colourway the shopkeeper had deliberately created. The failure was almost
    // impossible to diagnose from the outside:
    //
    //   Add a product with 4 photos and one colour, Red, with no photos of its
    //   own. Red is dropped — but the page falls back to the product's 4 photos
    //   and the plain size ladder, so it looks completely normal. Then add Blue
    //   WITH its own photos, and Blue draws a swatch. Now there is exactly one
    //   circle on the page and the first colour appears to have vanished.
    //   Reproduced: two colours in the database, one swatch rendered.
    //
    // Nothing needs the filter any more. A colour with no photographs falls back
    // to the product's own — server-side in $initialGallery and client-side via
    // DIEVON_DEFAULT_IMAGES — its swatch falls back through thumbnail, first
    // photo, then the product cover, and a colour with no sizes says so in the
    // ladder rather than pretending. An empty state that tells the truth beats a
    // colour that quietly does not exist.
    $productColors = array_values($productColors);
} catch (PDOException $e) {}

// Ladder chosen by the product's own category. Menswear measures the CHEST and
// womenswear the BUST, so the fixed women's ladder this used to require told a
// 40" chest he was a 5XL.
$sizeLadder = sizeLadderForCategory((int)($product['category_id'] ?? 0));

// ── Size guide: product-specific override wins over its category default ──
// Men's garments measure the CHEST and women's the BUST. The database stores both
// in the same column, so the label follows the product's audience.
$sgGender = (function_exists('productGender') ? productGender($product) : 'women');
$sgBustLabel = $sgGender === 'men' ? 'Chest' : 'Bust';
$sizeGuideChart   = null;
$sizeGuideBody    = [];
$sizeGuideGarment = [];
try {
    $sgStmt = $pdo->prepare("SELECT * FROM size_guide_charts WHERE product_id = :pid");
    $sgStmt->execute(['pid' => $productId]);
    $sizeGuideChart = $sgStmt->fetch();

    if (!$sizeGuideChart) {
        $catStmt = $pdo->prepare("SELECT id, parent_id FROM categories WHERE name = :name LIMIT 1");
        $catStmt->execute(['name' => $product['category']]);
        $cat = $catStmt->fetch();
        if ($cat) {
            $sgStmt2 = $pdo->prepare("SELECT * FROM size_guide_charts WHERE category_id = :cid AND product_id IS NULL");
            $sgStmt2->execute(['cid' => $cat['id']]);
            $sizeGuideChart = $sgStmt2->fetch();

            // Fall back to the parent category. Subcategories (e.g. "Long Kurtis" under
            // "Kurtis") usually have no chart of their own, and without this the modal
            // opens completely empty even though the parent has a perfectly good chart.
            // Walks up the tree so deeper nesting keeps working; the seen-guard stops a
            // self-referential or circular parent_id from looping forever.
            $seen = [(int)$cat['id']];
            $parentId = (int)($cat['parent_id'] ?? 0);
            while (!$sizeGuideChart && $parentId > 0 && !in_array($parentId, $seen, true)) {
                $seen[] = $parentId;
                $sgStmt3 = $pdo->prepare("SELECT * FROM size_guide_charts WHERE category_id = :cid AND product_id IS NULL");
                $sgStmt3->execute(['cid' => $parentId]);
                $sizeGuideChart = $sgStmt3->fetch();
                if ($sizeGuideChart) { break; }
                $pStmt = $pdo->prepare("SELECT parent_id FROM categories WHERE id = :id LIMIT 1");
                $pStmt->execute(['id' => $parentId]);
                $parentId = (int)$pStmt->fetchColumn();
            }
        }
    }

    // Last resort: the shop-wide default chart (category_id and product_id both
    // NULL). Without this, a brand-new TOP-LEVEL category — which has no parent
    // to inherit from — showed the body table and the diagram but no garment
    // column at all, until someone remembered to build a chart for it.
    //
    // With it, every product on the site has garment measurements from the moment
    // it is created, and a per-category chart becomes an optional refinement
    // rather than a required setup step.
    if (!$sizeGuideChart) {
        // Last resort is now GENDER-AWARE. The shop-wide default is women's
        // measurements, so a men's product falling back to it would have been
        // told it had a 34\" bust. Prefer the default for the product's own
        // audience; anything untagged still works as the catch-all.
        $g = (function_exists('productGender') ? productGender($product) : 'women');
        $st = $pdo->prepare(
            "SELECT * FROM size_guide_charts
              WHERE category_id IS NULL AND product_id IS NULL AND (gender = :g OR gender IS NULL)
              ORDER BY (gender = :g2) DESC, id ASC LIMIT 1"
        );
        $st->execute(['g' => $g, 'g2' => $g]);
        $sizeGuideChart = $st->fetch();
    }

    if ($sizeGuideChart) {
        $contentStmt = $pdo->prepare("SELECT * FROM size_guide_content WHERE chart_id = :id ORDER BY sort_order ASC, id ASC");
        $contentStmt->execute(['id' => $sizeGuideChart['id']]);
        foreach ($contentStmt->fetchAll() as $r) {
            if ($r['measurement_type'] === 'garment') { $sizeGuideGarment[] = $r; }
            else { $sizeGuideBody[] = $r; }
        }
    }
    // Body measurements come from the ONE global table per gender — a 34" bust is
    // a size M whatever the garment, and a 40" chest is a size M too. The per-chart
    // body rows above are only a fallback for a database where the global table has
    // not been created or filled in yet. The product's own audience picks the ladder:
    // men's products see chest 36-52, women's see bust 30-46.
    $sizeGuideBody = globalBodySizeRows($pdo, $sizeGuideBody, $sgGender ?? 'women');
} catch (PDOException $e) {}

// Whether there is anything to actually show. The "SIZE GUIDE" trigger buttons used to
// render unconditionally, so a product whose category has no chart (a newly added
// top-level category, for example) showed the button and then opened a completely empty
// popup. This must stay identical to the condition guarding the modal markup itself.
// Deliberately does NOT require a category chart. Body measurements and the
// "how to measure" picture are both shop-wide now, so a category with no chart of
// its own can still show a genuinely useful size guide — the body table and the
// diagram — and simply omits the garment column and the per-category instructions.
// Requiring a chart meant a shop that had not filled in every category showed no
// Size Guide button at all, and hid the uploaded how-to-measure image with it.
$hasSizeGuide = !empty($sizeGuideBody) || !empty($sizeGuideGarment);

// A chart with no shoulder/bust instructions is a lower-body-only garment (e.g. Bottoms) —
// swap in the leg/waist/hip diagram instead of the full-figure one.
$sgIsLowerBodyOnly = $sizeGuideChart
    && trim($sizeGuideChart['instructions_shoulder'] ?? '') === ''
    && trim($sizeGuideChart['instructions_bust'] ?? '') === '';

// Which measurement overlay lines are relevant for this chart (drives both the
// fallback SVG variant above and the real-photo overlay below).
$sgShowShoulder = $sizeGuideChart && trim($sizeGuideChart['instructions_shoulder'] ?? '') !== '';
$sgShowBust     = $sizeGuideChart && trim($sizeGuideChart['instructions_bust'] ?? '') !== '';
$sgShowWaist    = $sizeGuideChart && trim($sizeGuideChart['instructions_waist'] ?? '') !== '';
$sgShowHips     = $sizeGuideChart && trim($sizeGuideChart['instructions_hips'] ?? '') !== '';
$sgShowLength   = $sizeGuideChart && trim($sizeGuideChart['instructions_length'] ?? '') !== '';

// ── Active promotional coupons (pulled from the real promo_codes table) ──
$activeCoupons = [];
try {
    $pcStmt = $pdo->prepare("SELECT * FROM promo_codes WHERE active = 1 AND (expires_at IS NULL OR expires_at >= CURDATE()) ORDER BY discount_value DESC LIMIT 3");
    $pcStmt->execute();
    $activeCoupons = $pcStmt->fetchAll();
} catch (PDOException $e) {}

// ── Estimated delivery date (next N business days, skipping weekends) ──
function dievonAddBusinessDays($days, $fromTs = null) {
    $ts = $fromTs ?: time();
    $added = 0;
    while ($added < $days) {
        $ts = strtotime('+1 day', $ts);
        if ((int)date('N', $ts) < 6) { $added++; }
    }
    return $ts;
}
$estimatedDeliveryDate = date('l, jS F', dievonAddBusinessDays(5));

// ── Dynamic product specifications (general + per-component) ──────
// Purely additive: products with none of these rows simply show none of
// these sections — every existing product keeps working unchanged.
$generalSpecs = [];
try {
    $gsStmt = $pdo->prepare("SELECT * FROM product_specifications WHERE product_id = :pid ORDER BY sort_order ASC, id ASC");
    $gsStmt->execute(['pid' => $productId]);
    $generalSpecs = $gsStmt->fetchAll();
} catch (PDOException $e) {}

$productComponents = [];
try {
    $compStmt = $pdo->prepare("SELECT * FROM product_components WHERE product_id = :pid ORDER BY sort_order ASC, id ASC");
    $compStmt->execute(['pid' => $productId]);
    $productComponents = $compStmt->fetchAll();
    foreach ($productComponents as &$comp) {
        $csStmt = $pdo->prepare("SELECT * FROM product_component_specifications WHERE component_id = :cid AND product_id = :pid ORDER BY sort_order ASC, id ASC");
        $csStmt->execute(['cid' => $comp['id'], 'pid' => $productId]);
        $comp['specs'] = $csStmt->fetchAll();
    }
    unset($comp);
    // Hide components that ended up with no specifications
    $productComponents = array_values(array_filter($productComponents, fn($c) => !empty($c['specs'])));
} catch (PDOException $e) {}

// SEO values are derived from the product's own data (name, category, description,
// fabric/colour/occasion) whenever the admin has not typed an explicit override.
// See resolveProductSeo() in config/config.php — the admin form shows exactly the
// same generated values as placeholders, so what is previewed is what ships.
$productSeo = resolveProductSeo($product);
$pageTitle = $productSeo['meta_title'];
$metaDescription = $productSeo['meta_description'];
$metaKeywords = $productSeo['tags'];
if (!empty($product['image'])) {
    $ogImage = SITE_URL . "/uploads/products/" . $product['image'];
}
// Renders the share preview as a product card (price + availability) instead of a plain link.
$ogType = 'product';
// priceForMachines()/getCurrentCurrency() are the single source of truth for money in
// machine-readable output, so the OG tags and the JSON-LD below always agree — and both
// stay correct once a shopper's country (Countries We Sell To) changes the currency.
/* Is this piece actually buyable? Worked out ONCE, here, because three separate
   things on this page answer that question and they must not disagree: the
   social/merchant meta tags below, the JSON-LD further down, and the Add to Bag
   button. $variants and $productColors are both loaded well above this point. */
$dvInStock = function_exists('productIsInStock')
    ? productIsInStock($product, $variants ?? [], $productColors ?? [])
    : true;

/* The same resolved figure the JSON-LD publishes, not products.price.
   These tags are read by Facebook, Pinterest and merchant crawlers, so quoting
   a price no size actually sells at is the same falsehood in a second format. */
$ogPriceRange = function_exists('productPriceRange')
    ? productPriceRange($product)
    : ['min' => (float)$product['price'], 'varies' => false];

$ogProduct = [
    'price'        => priceForMachines($ogPriceRange['min'] > 0 ? $ogPriceRange['min'] : (float)$product['price']),
    'currency'     => getCurrentCurrency(),
    /* Published is not the same as in stock.
       ────────────────────────────────────────────────────────────────────────
       This read the `available` column alone — whether the owner has PUBLISHED
       the product — and never looked at how many were left. So a garment with
       every size on zero told Facebook, WhatsApp and any merchant feed reading
       these tags "in stock", on the very same page whose JSON-LD said
       schema.org/OutOfStock and whose Add to Bag button was disabled and
       labelled Sold Out. Three answers to one question, in one document.

       $dvInStock is the figure the page itself is drawn from (productIsInStock(),
       variants and colours included), so the tag now says what the shopper is
       being shown. */
    'availability' => (((int)($product['available'] ?? 0) === 1) && $dvInStock)
        ? 'in stock' : 'out of stock',
    'brand'        => trim((string)($product['brand'] ?? '')) ?: 'Dievon',
];
require_once __DIR__ . '/../includes/header.php';
?>

<!-- ══ Product structured data ══════════════════════════════════════════════
     Built in PHP and json_encode()d in one piece rather than assembled by hand
     inside the markup. The hand-written version relied on conditional commas to
     stay valid JSON, emitted `"gtin13": null` on every product without a barcode,
     described the garment with the truncated meta description, published a single
     image, claimed InStock unconditionally, and carried no shipping details or
     variants — so it qualified for a product snippet but not a merchant listing.
-->
<?php
    // ── Images: every photograph of this garment, best first ──
    $schemaImages = [];
    if (!empty($product['image'])) {
        $schemaImages[] = SITE_URL . '/uploads/products/' . $product['image'];
    }
    foreach ($additionalImages as $ai) {
        if (!empty($ai['image'])) { $schemaImages[] = SITE_URL . '/uploads/products/' . $ai['image']; }
    }
    foreach ($productColors as $pc) {
        foreach (($pc['images'] ?? []) as $ci) {
            if (!empty($ci['image'])) { $schemaImages[] = SITE_URL . '/uploads/products/' . $ci['image']; }
        }
    }
    $schemaImages = array_values(array_unique($schemaImages));

    // ── Availability: the same formula the cart enforces ──
    $schemaInStock = productIsInStock($product, $variants, $productColors);

    // ── Shipping, from the figures the checkout actually charges ──
    $schemaCurrency  = getCurrentCurrency();
    $schemaShipFee   = (float)storeSetting($pdo, 'standard_shipping_fee', 99);
    $schemaFreeOver  = freeShippingMinForCountry($pdo);
    $schemaCountry   = function_exists('currentCountryCode') ? currentCountryCode() : 'IN';
    // Free delivery above the threshold is what a shopper buying this piece would
    // actually pay, so quote that rate when this product alone clears it.
    $schemaShipRate  = ($schemaFreeOver > 0 && (float)$product['price'] >= $schemaFreeOver) ? 0.0 : $schemaShipFee;

    /* The group offer must quote a price somebody can actually pay.
       ────────────────────────────────────────────────────────────────────────
       This published products.price raw. On a product whose sizes are priced
       individually that is not an offer at all: a piece listed at 50 whose only
       size sells at 2,000 told Google `offers.price = 50.00` while the variant
       beside it said 2,000.00 — two contradictory prices for one product, in the
       format Google trusts most. A structured-data price that disagrees with the
       landing page is a Merchant policy problem, not just a bad look, and can
       cost the rich result entirely.

       Where the sizes genuinely differ, schema.org's answer is AggregateOffer
       with lowPrice/highPrice — that is what "from ₹X" means in machine terms.
       Where they agree, a plain Offer at the buyable figure is still correct.
       Resolved through productPriceRange(), the same helper the card and the
       collection meta description use. */
    $schemaRange = function_exists('productPriceRange')
        ? productPriceRange($product)
        : ['min' => (float)$product['price'], 'max' => (float)$product['price'], 'varies' => false];
    $schemaLow   = $schemaRange['min'] > 0 ? $schemaRange['min'] : (float)$product['price'];

    $schemaOffer = [
        '@type'         => $schemaRange['varies'] ? 'AggregateOffer' : 'Offer',
        'url'           => $canonicalUrl,
        'priceCurrency' => $schemaCurrency,
        'price'         => priceForMachines($schemaLow),
        // Google warns on offers with no validity date; a year out is the
        // conventional answer for a catalogue with no scheduled price changes.
        'priceValidUntil' => date('Y-m-d', strtotime('+1 year')),
        'availability'  => $schemaInStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
        'itemCondition' => 'https://schema.org/NewCondition',
        'seller'        => ['@type' => 'Organization', 'name' => SHOP_NAME],
        'shippingDetails' => [
            '@type' => 'OfferShippingDetails',
            'shippingRate' => [
                '@type'    => 'MonetaryAmount',
                'value'    => priceForMachines($schemaShipRate),
                'currency' => $schemaCurrency,
            ],
            'shippingDestination' => [
                '@type'          => 'DefinedRegion',
                'addressCountry' => $schemaCountry,
            ],
            'deliveryTime' => [
                '@type' => 'ShippingDeliveryTime',
                // Same promise as pages/shipping.php: dispatched same day before
                // 2pm IST, 3-5 business days within India.
                'handlingTime' => ['@type' => 'QuantitativeValue', 'minValue' => 0, 'maxValue' => 1, 'unitCode' => 'DAY'],
                'transitTime'  => ['@type' => 'QuantitativeValue', 'minValue' => 3, 'maxValue' => 5, 'unitCode' => 'DAY'],
            ],
        ],
        'hasMerchantReturnPolicy' => [
            '@type'                => 'MerchantReturnPolicy',
            'applicableCountry'    => $schemaCountry,
            'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
            // The constant, not a literal — so the schema and pages/returns.php
            // can never quote different windows.
            'merchantReturnDays'   => RETURN_WINDOW_DAYS,
            'returnMethod'         => 'https://schema.org/ReturnByMail',
            'returnFees'           => 'https://schema.org/FreeReturn',
        ],
    ];

    /* AggregateOffer requires the band, not a single figure. lowPrice is what
       "from" means; highPrice is the dearest size. Added after the array so the
       plain-Offer case is untouched. */
    if ($schemaOffer['@type'] === 'AggregateOffer') {
        $schemaOffer['lowPrice']  = priceForMachines($schemaLow);
        $schemaOffer['highPrice'] = priceForMachines(max($schemaLow, (float)($schemaRange['max'] ?? $schemaLow)));
        unset($schemaOffer['price']);
    }

    $schemaProduct = [
        '@context' => 'https://schema.org/',
        '@type'    => 'Product',
        'name'     => $product['name'],
        // The product's own description, not the 155-character meta description,
        // which was being published complete with its trailing ellipsis.
        'description' => trim(preg_replace('/\s+/', ' ', strip_tags((string)$product['description']))),
        'sku'      => productDisplayCode($product),
        'brand'    => ['@type' => 'Brand', 'name' => trim((string)($product['brand'] ?? '')) ?: SHOP_NAME],
        'category' => (string)($product['category'] ?? ''),
        'url'      => $canonicalUrl,
        'offers'   => $schemaOffer,
    ];
    if ($schemaImages) { $schemaProduct['image'] = $schemaImages; }

    // Identifiers: emitted only when real. `"gtin13": null` is not a missing
    // value to a parser, it is an invalid one.
    if (!empty($product['barcode']))      { $schemaProduct['gtin13'] = $product['barcode']; }
    if (!empty($product['atelier_code'])) { $schemaProduct['mpn'] = $product['atelier_code']; }
    // No filler when neither exists. Apparel a merchant makes itself has no GTIN,
    // and schema.org has no way to say so — that declaration belongs in the
    // Merchant Center feed as g:identifier_exists, which the feed now sends.

    // Attributes Google uses for apparel merchant listings, where we hold them.
    $schemaMaterial = trim((string)($product['composition'] ?: ($product['fabric'] ?? '')));
    if ($schemaMaterial !== '') { $schemaProduct['material'] = $schemaMaterial; }
    if (!empty($product['pattern'])) { $schemaProduct['pattern'] = $product['pattern']; }
    // Taken from the product, not hard-coded.
    //
    // Every product page told Google this item was for women — including the
    // men's ones — while google_merchant_feed.php:143 sent g:gender male for
    // the same SKU. One item, two contradictory claims about who it is for.
    // $sgGender is already resolved further up (line 231) from the same
    // productGender() the feed uses, so the two now cannot disagree. For a
    // women's product this emits exactly what it always did.
    $schemaGenderMap = ['women' => 'female', 'men' => 'male', 'unisex' => 'unisex'];
    $schemaProduct['audience'] = [
        '@type'          => 'PeopleAudience',
        'suggestedGender' => $schemaGenderMap[$sgGender] ?? 'female',
    ];

    // ── Colour and size ──
    $schemaSizes = [];
    foreach ($variants as $v) {
        $sz = trim(str_replace('Size:', '', (string)($v['size_code'] ?: $v['name'])));
        if ($sz !== '' && !in_array($sz, $schemaSizes, true)) { $schemaSizes[] = $sz; }
    }
    if ($productColors) {
        $schemaProduct['color'] = array_values(array_map(fn($c) => $c['color_name'], $productColors));
    } elseif (dievonProductColour($product) !== '') {
        $schemaProduct['color'] = dievonProductColour($product);
    }
    if ($schemaSizes) { $schemaProduct['size'] = $schemaSizes; }

    // ── Variants ──
    // A garment sold in four sizes is four purchasable things. Declaring them as
    // a ProductGroup lets Google show the right one rather than guessing, and is
    // what "variant information for sizes and colours" means in the merchant
    // listing guidance.
    $schemaVariants = [];
    $variantRows = $productColors
        ? array_merge(...array_map(fn($c) => array_map(fn($v) => $v + ['_color' => $c['color_name'], '_csku' => $c['sku'] ?? '', '_colorRow' => $c], $c['sizes'] ?? []), $productColors) ?: [[]])
        : $variants;
    foreach ($variantRows as $v) {
        $sz = trim(str_replace('Size:', '', (string)($v['size_code'] ?? $v['name'] ?? '')));
        if ($sz === '') { continue; }
        /* Resolved through effectiveVariantPrice(), like every other price on
           this page.
           ────────────────────────────────────────────────────────────────────
           This was a second, hand-written copy of the ladder — "the size's price
           if it has one, else the product's" — which knows nothing about a
           colour's price_override or the sale clamp. So this block published
           figures the shop refuses to charge, INSIDE the same document as a
           correct one: the ProductGroup offer above resolves through
           productPriceRange() and said ₹1,650 while this loop said ₹2,450 for
           the same size, because Rose carries an override. On a sale product it
           published the pre-clamp ₹2,450 against a page and bag charging ₹1,899.
           Two contradictory prices for one size, in the format Google trusts
           most, which is a Merchant policy problem and not merely untidy.
           The colour row is carried through above so its override still wins. */
        $vPrice = effectiveVariantPrice($product, $v, $v['_colorRow'] ?? null);
        $vStock = array_key_exists('stock_qty', $v) && $v['stock_qty'] !== null
            ? (int)$v['stock_qty'] > 0 : $schemaInStock;
        $entry = [
            '@type' => 'Product',
            'name'  => $product['name'] . ' — ' . (isset($v['_color']) ? $v['_color'] . ', ' : '') . $sz,
            'sku'   => productDisplayCode($product) . '-' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $sz)),
            'size'  => $sz,
            'offers' => [
                '@type'         => 'Offer',
                'url'           => $canonicalUrl,
                'priceCurrency' => $schemaCurrency,
                'price'         => priceForMachines($vPrice),
                'availability'  => $vStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                'itemCondition' => 'https://schema.org/NewCondition',
            ],
        ];
        if (isset($v['_color'])) { $entry['color'] = $v['_color']; }
        $schemaVariants[] = $entry;
    }
    if ($schemaVariants) {
        $schemaProduct['@type'] = 'ProductGroup';
        $schemaProduct['productGroupID'] = productDisplayCode($product);
        $schemaProduct['variesBy'] = $productColors
            ? ['https://schema.org/color', 'https://schema.org/size']
            : ['https://schema.org/size'];
        $schemaProduct['hasVariant'] = $schemaVariants;
    }

    // ── Ratings and reviews: only ever real ones ──
    // Google treats an invented or empty aggregateRating as spam, so both of
    // these appear only when an approved review actually exists.
    if ($revCount > 0) {
        $schemaProduct['aggregateRating'] = [
            '@type'       => 'AggregateRating',
            'ratingValue' => (string)$avgRating,
            'reviewCount' => (string)$revCount,
            'bestRating'  => '5',
            'worstRating' => '1',
        ];
        $schemaProduct['review'] = array_values(array_map(function ($r) {
            return [
                '@type'  => 'Review',
                'author' => ['@type' => 'Person', 'name' => trim((string)($r['customer_name'] ?? '')) ?: 'Verified Buyer'],
                'datePublished' => !empty($r['created_at']) ? date('Y-m-d', strtotime($r['created_at'])) : null,
                'reviewBody'    => trim((string)($r['review'] ?? $r['comment'] ?? '')),
                'reviewRating'  => [
                    '@type'       => 'Rating',
                    'ratingValue' => (string)(int)($r['rating'] ?? 0),
                    'bestRating'  => '5',
                    'worstRating' => '1',
                ],
            ];
        }, array_slice($dbReviews, 0, 10)));
    }

    // Drop nulls so no property is published with an invalid value type.
    $stripNulls = function ($v) use (&$stripNulls) {
        if (!is_array($v)) { return $v; }
        $out = [];
        foreach ($v as $k => $x) {
            if ($x === null || $x === '') { continue; }
            $out[$k] = $stripNulls($x);
        }
        return $out;
    };
?>
<script type="application/ld+json">
<?= json_encode($stripNulls($schemaProduct), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
</script>

<!-- ══ Breadcrumb Structured Data ══════════════════════════
     Home › Shop › Category › Product. The Shop step was missing, so the product
     breadcrumb disagreed with the one the collection pages publish. -->
<?php
    $pbCrumbs = [
        ['name' => 'Home', 'url' => SITE_URL . '/'],
        ['name' => 'Shop', 'url' => SITE_URL . '/shop'],
    ];
    if (!empty($product['category'])) {
        $pbCrumbs[] = ['name' => $product['category'], 'url' => categoryUrlByName($pdo, (string)$product['category'])];
    }
    $pbCrumbs[] = ['name' => $product['name'], 'url' => $canonicalUrl];
?>
<script type="application/ld+json">
<?= json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => array_map(fn($c, $i) => [
        '@type' => 'ListItem', 'position' => $i + 1, 'name' => $c['name'], 'item' => $c['url'],
    ], $pbCrumbs, array_keys($pbCrumbs)),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
</script>

<!-- ══ Product Hero ════════════════════════════ -->
<?php /* Unlike every other page that uses .luxury-hero, this one is not the page's
         only heading — the garment's real name is an <h1> a hundred pixels below,
         inside the buy box. So this band is a <p>, not a second <h1>: two <h1>s on
         one page give a crawler two candidate titles and make a screen reader's
         heading list read the same product name twice in a row.

         It is also hidden below 992px (.product-hero, style.css). On a phone it was
         207px of the first 844 — a quarter of the opening screen — spent showing a
         blurred copy of the photograph that appears sharp immediately underneath,
         above a breadcrumb that repeats the category and a title that repeats the
         name. Three statements of the same thing before the shopper reaches a size.
         On a desktop it costs nothing and stays. */ ?>
<?php if (!empty($product['image'])): ?>
<section class="luxury-hero has-bg-image product-hero section-mb-sm" style="--hero-bg-image: url('<?= SITE_URL ?>/uploads/products/<?= htmlspecialchars($product['image']) ?>')">
    <div class="container">
        <span class="luxury-hero-eyebrow"><?= htmlspecialchars($product['category']) ?></span>
        <p class="product-hero-name"><?= htmlspecialchars($product['name']) ?></p>
    </div>
</section>
<?php endif; ?>

<!-- ══ Product Detail Showcase ════════════════════════════ -->
<section class="product-showcase-section section-space">
    <div class="container product-detail-container">

        
        <div class="product-layout">
            
            <!-- Left Column: Image Grid -->
            <div class="product-image-section" id="productImageSection">
                <div class="sticky-sentinel" aria-hidden="true"></div>
                <div class="product-gallery-slider-wrap" id="galleryWrap">
                <?php
                // What the gallery opens on. A colour product opens on the
                // FIRST COLOUR's photographs, because that is the colour whose
                // price and sizes the buy box opens on.
                //
                // This used to render the product's own images and let
                // initColorSizeSelector() swap them out on DOMContentLoaded —
                // so every load of a colour product briefly painted the wrong
                // photographs, and with JavaScript off it never corrected.
                // Deciding it here means the first paint is already right.
                $initialGallery = [];
                if (!empty($productColors)) {
                    foreach ($productColors[0]['images'] as $colorImg) {
                        $initialGallery[] = $colorImg['image'];
                    }
                }
                // Falls through for a product with no colours — and for a colour
                // that has no photographs of its own, which would otherwise show
                // an empty gallery.
                if (!$initialGallery) {
                    if (!empty($product['image'])) { $initialGallery[] = $product['image']; }
                    foreach ($additionalImages as $img) { $initialGallery[] = $img['image']; }
                }
                ?>
                <div class="product-gallery-grid" id="productGalleryGrid">
                    <?php foreach ($initialGallery as $gIndex => $gFile): ?>
                    <?php /* Operable by keyboard, not only by mouse.
                             ────────────────────────────────────────────────────
                             This was a bare <div onclick>: tabindex null, role
                             null, no key handler — so the enlarged view of the
                             garment was reachable by pointer alone. On a clothing
                             shop the photographs ARE the product, and a keyboard
                             or screen-reader user could not open a single one.
                             WCAG 2.1.1. role + tabindex + Enter/Space is the
                             standard retrofit when the element cannot become a
                             real <button> without restyling the grid. */ ?>
                    <div class="gallery-grid-item" role="button" tabindex="0"
                         aria-label="View larger image<?= $gIndex === 0 ? '' : ' ' . ($gIndex + 1) ?>"
                         onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}"
                         onclick="openProductLightbox('<?= SITE_URL ?>/uploads/products/<?= htmlspecialchars($gFile) ?>')">
                        <?php /* An expired "New" disappears here too — one rule for every
                                 screen, so the card and the page it opens cannot disagree. */ ?>
                        <?php if ($gIndex === 0 && ($pageBadge = productBadge($product)) !== ''): ?>
                            <span class="badge-luxury badge-product-page"><?= htmlspecialchars($pageBadge) ?></span>
                        <?php endif; ?>
                        <?php $gWebp = webpUrlIfExists('products', $gFile); ?>
                        <picture>
                            <?php if ($gWebp): ?><source srcset="<?= htmlspecialchars($gWebp) ?>" type="image/webp"><?php endif; ?>
                            <img src="<?= htmlspecialchars(pictureImgSrc(SITE_URL . '/uploads/products/' . $gFile, $gWebp)) ?>" alt="<?= htmlspecialchars(productImageAlt($product, $gIndex === 0 ? '' : 'View ' . ($gIndex + 1))) ?>" class="gallery-grid-img">
                        </picture>
                    </div>
                    <?php endforeach; ?>

                    <!-- Video (if any) -->
                    <?php if ($hasVideo): ?>
                    <div class="gallery-grid-item video-grid-item">
                        <?php 
                        $vUrl = trim($product['video_url']);
                        if (strpos($vUrl, 'youtube.com') !== false || strpos($vUrl, 'youtu.be') !== false):
                            preg_match('/(?:v=|\/)([a-zA-Z0-9_-]{11})/', $vUrl, $m);
                            $ytId = $m[1] ?? '';
                        ?>
                            <iframe class="gallery-grid-video" src="https://www.youtube-nocookie.com/embed/<?= $ytId ?>?autoplay=0&mute=1&loop=1&playlist=<?= $ytId ?>" frameborder="0" allow="encrypted-media" allowfullscreen></iframe>
                        <?php else: ?>
                            <?php /* Shared resolver: also upgrades a plain http:// address on an
                                 https page, which the browser would otherwise block as mixed
                                 content and report as an unsupported format. */ ?>
                        <?php $videoSrc = dievonVideoSrc($vUrl); ?>
                            <video class="gallery-grid-video" src="<?= htmlspecialchars($videoSrc) ?>" autoplay loop muted playsinline controls></video>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <button type="button" class="gallery-nav gallery-nav-prev" onclick="navGallerySlide(-1)" aria-label="Previous photo"><i class="fa-solid fa-chevron-left"></i></button>
                <button type="button" class="gallery-nav gallery-nav-next" onclick="navGallerySlide(1)" aria-label="Next photo"><i class="fa-solid fa-chevron-right"></i></button>
                <div class="product-gallery-slide-counter" id="gallerySlideCounter" aria-hidden="true"></div>
                <?php /* "3 / 7" over the photograph.
                         ──────────────────────────────────────────────────────────
                         The dots say WHERE you are; they do not say how much is
                         left once there are more than a handful, because five
                         identical dashes do not read as a number at a glance.
                         Written by JS and left empty here, so a product with a
                         single photograph renders nothing at all rather than a
                         permanent "1 / 1". aria-hidden because the count is
                         decorative duplication — the carousel's own controls are
                         what a screen reader is given. */ ?>
                <div class="product-gallery-frame-count" id="galleryFrameCount" aria-hidden="true"></div>
                <?php /* Every detail this page holds, one tap from the photograph.
                         ──────────────────────────────────────────────────────────
                         The details are all on the page already, but only reachable
                         by scrolling past the colour picker, the size ladder, the
                         quantity stepper and both buy buttons. Someone still
                         deciding whether they want the garment had to travel past
                         everything meant for someone who already had.

                         Labelled, not icon-only: a hanger glyph alone does not say
                         whether it opens sizing, care or the fabric. */ ?>
                <?php /* Icon only, so it takes as little of the photograph as it can.
                         The word is gone from the screen but NOT from the button: with
                         the label removed, aria-label is the only thing left naming it,
                         and without one a screen reader announces "button" and nothing
                         else. title gives the same word back on hover. */ ?>
                <button type="button" class="gallery-details-btn" onclick="openProductDetails()"
                        aria-haspopup="dialog" aria-controls="productDetailsSheet"
                        aria-label="Product details" title="Product details">
                    <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                </button>
                </div>
            </div>

            <!-- Right Column: Details & Actions -->
            <div class="product-details-col">
                <div class="product-buy-box">
                <!-- Breadcrumbs -->
                <nav class="product-breadcrumbs">
                    <a href="<?= SITE_URL ?>/">Dievon</a> &nbsp;&bull;&nbsp;
                    <a href="<?= htmlspecialchars(categoryUrlByName($pdo, $product['category'])) ?>"><?= htmlspecialchars($product['category']) ?></a> &nbsp;&bull;&nbsp;
                    <span><?= htmlspecialchars($product['name']) ?></span>
                </nav>

                <?php /* Always rendered. This used to be `if (empty($product['image']))`,
                         on the reasoning that a product WITH a photograph already had its
                         name in the hero band above — so the buy box showed a breadcrumb
                         and then jumped straight to a price, and the garment's name existed
                         on the page exactly once, 200px away in a decorative band.

                         That made the hero load-bearing for something it was never meant to
                         carry. The name belongs here, beside the price, where a shopper
                         reads it and where the page's single <h1> should be; the band above
                         is decoration and now says so. */ ?>
                <h1 class="product-title-heading">
                    <?= htmlspecialchars($product['name']) ?>
                </h1>

                <?php
                // The price the page OPENS on, decided here rather than by JavaScript.
                //
                // These three read the product's own figures, while the buy box opens
                // on colour #1 — so a colourway with a price override painted the
                // product price first and JavaScript corrected it a moment later. On
                // test-15 that was ₹675 with "44% OFF" flashing before it settled at
                // the colour's ₹77. The gallery had exactly this bug and was fixed the
                // same way; the price was missed.
                //
                // Same source as the swatch: $productColors[0], already carrying
                // effective_price and effective_mrp with the overrides applied.
                $openPrice = (float)$product['price'];
                $openMrp   = (float)$mrpPrice;
                if (!empty($productColors)) {
                    $openPrice = (float)$productColors[0]['effective_price'];
                    $openMrp   = (float)$productColors[0]['effective_mrp'];
                }
                // A "was" price below the asking price is not a discount, so no strike
                // -through and no badge — the same test the JavaScript applies.
                $openHasDiscount = $openMrp > $openPrice;
                $openOffPercent  = $openHasDiscount && $openMrp > 0
                    ? (int)round((($openMrp - $openPrice) / $openMrp) * 100)
                    : 0;
                ?>
                <?php if ($dvSoldInCountry): ?>
                <?php /* Announced, because choosing a size now MOVES this figure.
                         ──────────────────────────────────────────────────────────
                         Selecting a size is signalled to a sighted user by the
                         heading changing; a screen-reader user got aria-pressed on
                         the pill and silence about the price. That was tolerable
                         while the heading never moved for a size — it moves now, so
                         the one piece of information the interaction exists to
                         convey was the piece not being conveyed. polite, so it waits
                         for a pause rather than interrupting the pill's own label;
                         atomic, so the amount, the MRP and the discount badge are
                         read as one price rather than three loose numbers. */ ?>
                <div class="product-price-heading" id="productPriceDisplay" role="status" aria-live="polite" aria-atomic="true">
                    <span class="price-current-amount" id="priceCurrentAmount"><?= formatPrice($openPrice) ?></span>
                    <span class="price-mrp-amount" id="priceMrpAmount" style="<?= $openHasDiscount ? '' : 'display:none;' ?>"><?= formatPrice($openMrp) ?></span>
                    <span class="price-off-badge" id="priceOffBadge" style="<?= $openHasDiscount ? '' : 'display:none;' ?>"><?= $openOffPercent ?>% OFF</span>
                </div>
                <?php else: ?>
                <?php // No product_country_prices row for this country — the shop's own
                      // signal (see productCountryPricing()) that this piece is not sold
                      // there yet, rather than a converted guess at what it should cost. ?>
                <div class="product-price-heading" id="productPriceDisplay">
                    <span class="price-current-amount" style="font-size:16px; color:var(--text-muted);">Not available for delivery to your selected country</span>
                </div>
                <?php endif; ?>

                <?php /* The full description is always in the page — clamping is visual only,
                         so a crawler and a screen reader still get every word, and the copy
                         keeps its place directly under the price where it belongs for
                         scanning. On a phone it was costing 450px between the price and the
                         size picker, which pushed Add to Bag past two full screens. The
                         toggle is added by JS and only when the text actually overflows, so a
                         one-line description never grows a pointless "Read more". */ ?>
                <div class="product-description-text" id="productDescriptionText">
                    <?= nl2br(htmlspecialchars($product['description'])) ?>
                </div>
                <button type="button" class="desc-read-more" id="descReadMoreBtn"
                        aria-expanded="false" aria-controls="productDescriptionText" hidden>
                    <span class="desc-read-more-label">Read more</span>
                    <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                </button>


                <?php if (!empty($productColors)): ?>
                <?php // The SWATCHES need two colours to be a choice; the size ladder below
                      // is needed for one colour just as much as for five, and it lives in
                      // this same branch — so the condition wraps only the picker, not the
                      // whole block. Gating the branch itself dropped a single-colour
                      // product straight past its sizes and left it with no way to choose
                      // one at all.
                      //
                      // With one colourway the colour is still applied — its images, its
                      // sizes, its price — it simply is not presented as a decision. Same
                      // rule as the Men/Women switcher, hidden until both sides have stock. ?>
                <!-- ══ Colour Selector (Biba-style image swatches) ══════════ -->
                <div class="product-colors-wrap">
                    <?php // Every colourway gets a named swatch, including a product that has
                          // only one of them.
                          //
                          // This went through two wrong versions. Drawing nothing at all for a
                          // single colour left the buy box empty between the description and
                          // the size ladder, so the description read as the colour name — #13
                          // looked like it came in a shade called "Prince bhai". Naming it in
                          // text alone fixed that but still hid the photograph, and the swatch
                          // is what shows the shopper the actual cloth.
                          //
                          // So the count gates nothing here. One colour renders one swatch,
                          // already selected — which is what Biba, Aza and Myntra all show. ?>
                    <span class="variant-select-label color-select-label">
                        Colour: <strong id="selectedColorNameLabel"><?= htmlspecialchars($productColors[0]['color_name']) ?></strong>
                    </span>
                    <div class="color-swatch-row" id="colorSwatchRow" role="radiogroup" aria-label="Select colour">
                        <?php // No "all colours" swatch. It showed the product-level gallery,
                              // which on a garment with colourways is either empty (nothing was
                              // ever uploaded outside a colour) or a second, competing set of
                              // photographs of the same thing. Every colour is a real option;
                              // going back means clicking the first swatch, which is how every
                              // fashion site behaves. ?>
                        <?php
                        // What goes inside the ring, in order of preference, skipping
                        // anything whose file is not actually on this server:
                        //
                        //   1. the colour's chosen swatch thumbnail
                        //   2. its first photograph
                        //   3. the product's own cover
                        //   4. the emoji, only when the product has no picture at all
                        //
                        // The existence check is the important part. The deploy does not
                        // carry uploads/, by design — mirroring it would wipe the live
                        // folder — so a database moved from a development copy names
                        // thumbnails whose files were never uploaded. The old code trusted
                        // the column, emitting an <img> to a missing file: a broken-image
                        // icon in the swatch, which reads exactly like "no image" and was
                        // invisible locally where every file happens to exist.
                        $swatchDir = __DIR__ . '/../uploads/products/';
                        $swatchSrc = static function (array $c) use ($product, $swatchDir): string {
                            $candidates = [];
                            if (!empty($c['thumbnail']))       { $candidates[] = (string)$c['thumbnail']; }
                            if (!empty($c['images'][0]['image'])) { $candidates[] = (string)$c['images'][0]['image']; }
                            if (!empty($product['image']))     { $candidates[] = (string)$product['image']; }
                            foreach ($candidates as $file) {
                                if ($file !== '' && is_file($swatchDir . $file)) {
                                    return SITE_URL . '/uploads/products/' . rawurlencode($file);
                                }
                            }
                            return '';
                        };
                        ?>
                        <?php foreach ($productColors as $idx => $c):
                            $thumbSrc = $swatchSrc($c);
                              /* Sold out when the colour HAS sizes and none of them can be bought.
                                 ────────────────────────────────────────────────────────────────
                                 A shopper had to click each swatch to discover the colour was gone —
                                 the size pills below say OUT OF STOCK plainly while the swatch that
                                 leads to them looked identical to one with stock.
                              
                                 stock_qty === null means the size is not tracked, which is available,
                                 NOT zero. Treating null as empty would strike out every colour on a
                                 product that deliberately does not count stock.
                              
                                 A colour with no sizes at all is left alone: that is a product still
                                 being set up, not one that has sold out. */
                              $cSizes   = is_array($c['sizes'] ?? null) ? $c['sizes'] : [];
                              $cSoldOut = $cSizes && !array_filter($cSizes, function ($s) {
                                  return $s['stock_qty'] === null || (int)$s['stock_qty'] > 0;
                              });
                        ?>
                        <button type="button"
                                class="color-swatch-btn <?= $idx === 0 ? 'color-swatch-selected' : '' ?> <?= $cSoldOut ? 'color-swatch-soldout' : '' ?>"
                                data-color-id="<?= $c['id'] ?>"
                                onclick="selectProductColor(<?= $c['id'] ?>, this)"
                                role="radio"
                                aria-checked="<?= $idx === 0 ? 'true' : 'false' ?>"
                                aria-label="Colour: <?= htmlspecialchars($c['color_name'], ENT_QUOTES) ?><?= $cSoldOut ? " — out of stock" : "" ?>">
                            <span class="color-swatch-ring">
                                <?php if ($thumbSrc): ?>
                                <img src="<?= $thumbSrc ?>" alt="<?= htmlspecialchars(productImageAlt($product, $c['color_name'])) ?>">
                                <?php else: ?>
                                <span class="color-swatch-fallback-emoji"><?= htmlspecialchars($product['emoji'] ?? '👗') ?></span>
                                <?php endif; ?>
                            </span>
                            <span class="color-swatch-name"><?= htmlspecialchars($c['color_name']) ?></span>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- ══ Size Selector — Biba-style numeric/letter buttons, scoped to selected colour ══ -->
                <div class="product-variants-wrap" id="colorSizeSelectorWrap">
                    <div class="size-select-header-row">
                        <span class="variant-select-label">Select Size *</span>
                        <?php if ($hasSizeGuide): ?>
                        <button type="button" onclick="openSizeGuideModal()" class="size-guide-trigger-btn">
                            📏 SIZE GUIDE
                        </button>
                        <?php endif; ?>
                    </div>
                    <div class="variant-pills-grid size-ladder-grid" id="sizeLadderGrid"></div>
                    <p id="sizeSelectValidationMsg" class="size-select-validation-msg" style="display:none;"></p>
                </div>
                <?php elseif (!empty($variants)): ?>
                <?php // A product with sizes but no colourways. This used to be a visibly
                      // different control from the one above — headed "Select Size / Option",
                      // pills reading "Size: S" because the raw variant name was printed, and
                      // no stock warning at all. Same shop, same garment, two designs, and the
                      // plainer one appeared precisely when a product had no colours.
                      //
                      // It now renders the identical .size-pill-btn markup, so the only thing
                      // colourways change is whether swatches appear above. The click handler
                      // is still selectProductVariant(), so what reaches the cart is unchanged. ?>
                <div class="product-variants-wrap" id="colorSizeSelectorWrap">
                    <div class="size-select-header-row">
                        <span class="variant-select-label">Select Size *</span>
                        <?php if ($hasSizeGuide): ?>
                        <button type="button" onclick="openSizeGuideModal()" class="size-guide-trigger-btn">
                            📏 SIZE GUIDE
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php
                    // Ladder order, not the order they were typed in.
                    //
                    // Plain sizes are entered by ticking rungs now, so each carries a
                    // size_code and can be sorted properly — previously the page listed
                    // them by sort_order alone, so adding XL before S put XL first on
                    // the buy box. Anything off-ladder (Free Size, 500ml) has no rung
                    // and keeps its own order after the graded sizes.
                    $ladderPos       = array_flip(array_column($sizeLadder, 'code'));
                    $orderedVariants = $variants;
                    usort($orderedVariants, static function ($a, $b) use ($ladderPos) {
                        $pa = $ladderPos[$a['size_code'] ?? ''] ?? PHP_INT_MAX;
                        $pb = $ladderPos[$b['size_code'] ?? ''] ?? PHP_INT_MAX;
                        if ($pa !== $pb) { return $pa <=> $pb; }
                        return [(int)$a['sort_order'], (int)$a['id']] <=> [(int)$b['sort_order'], (int)$b['id']];
                    });
                    ?>
                    <div class="variant-pills-grid size-ladder-grid">
                        <?php foreach ($orderedVariants as $v): ?>
                            <?php
                            // Stock is optional on these rows: null means "not tracked", which
                            // must not read as sold out.
                            $vStock      = $v['stock_qty'] === null ? null : (int)$v['stock_qty'];
                            $vOutOfStock = $vStock !== null && $vStock <= 0;
                            $vLowStock   = $vStock !== null && $vStock > 0 && $vStock <= 5;
                            // "Size: S" printed as-is gave pills reading "Size: S" next to a
                            // heading already saying Select Size. The stored name still goes to
                            // the cart untouched, so older orders keep matching.
                            $vLabel = preg_replace('/^\s*size\s*:\s*/i', '', (string)$v['name']);
                              // A price on the pill only where this size differs from the product's own.
                              // Same rule as the colour ladder below, and the same reason: 1 of 42
                              // variants in this catalogue is priced differently, so printing a figure
                              // on every pill would bury the one that matters under forty-one that
                              // simply repeat the heading.
                              // effectiveVariantPrice() both sides, never $v['price'] — the raw column
                              // ignores the sale clamp, which is how this page once quoted a figure the
                              // cart then capped.
                              $vPrice     = (float)effectiveVariantPrice($product, $v);
                              $vHasOwn    = abs($vPrice - (float)$product['price']) > 0.009;
                            ?>
                            <button type="button"
                                    class="size-pill-btn <?= $vOutOfStock ? 'size-pill-disabled' : '' ?> <?= $vHasOwn ? 'size-pill-has-price' : '' ?>"
                                    <?= $vOutOfStock ? 'disabled aria-disabled="true"' : '' ?>
                                    aria-pressed="false"
                                    <?php // The price the cart will actually charge, not the raw
                                          // variant row. This emitted $v['price'] straight, so a
                                          // dearer size on a discounted product quoted a figure the
                                          // cart then capped — the page said ₹2,099 and the bag said
                                          // ₹1,899. Same function both sides now. ?>
                                    <?php /* htmlspecialchars, not addslashes — this is an HTML
                                             attribute, not a JS string literal.
                                             ────────────────────────────────────────────────
                                             addslashes escapes quotes for JavaScript but HTML
                                             attributes have no backslash escaping, so a double
                                             quote in a size name CLOSED this onclick and
                                             everything after it was parsed as further
                                             attributes. Proven: a variant named
                                             `M" onmouseover=...` produced a real, working
                                             onmouseover handler that ran for every shopper.
                                             Stored XSS, plantable by any catalogue-staff
                                             account, firing on the storefront and on the
                                             owner's own admin session.
                                             ENT_QUOTES encodes both quote characters, so the
                                             name can no longer leave the attribute; the JS
                                             string sees the decoded text exactly as before,
                                             and what reaches the cart is unchanged. */ ?>
                                    onclick="selectProductVariant(<?= (int)$v['id'] ?>, '<?= htmlspecialchars(addslashes($v['name']), ENT_QUOTES, 'UTF-8') ?>', <?= effectiveVariantPrice($product, $v) ?>, this)">
                                <?= htmlspecialchars($vLabel) ?>
                                  <?php if ($vHasOwn): ?><span class="size-pill-price"><?= formatPrice($vPrice) ?></span><?php endif; ?>
                                <?php if ($vOutOfStock): ?><span class="size-pill-low-stock">Out of stock</span>
                                <?php elseif ($vLowStock): ?><span class="size-pill-low-stock">Only <?= $vStock ?> left</span>
                                <?php endif; ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <p id="sizeSelectValidationMsg" class="size-select-validation-msg" style="display:none;"></p>
                </div>
                <?php endif; // no third branch: a product with no sizes offers none ?>
                <?php // A product with no configured sizes now shows NO size control.
                      //
                      // It used to fall back to a hard-coded S/M/L/XL/XXL list that had
                      // nothing to do with stock — the pills came from a PHP array, not from
                      // product_variants. Nothing was ever disabled, no "only N left" could
                      // appear, and selectProductSize() set the variant id to 0, so the size
                      // travelled to the order as loose text with no stock row behind it.
                      // A shopper could order XXL of a garment that was never made in XXL,
                      // and a product listed before its sizes were entered sold sizes that
                      // did not exist.
                      //
                      // Showing nothing is correct for both cases this covers: a genuine
                      // one-size piece, and a product still being set up. Add to Bag still
                      // works — validateSizeSelection() only demands a choice when a size
                      // grid is actually on the page. ?>

                <?php /* The "Dispatched in 24 Hours / 14-Day Return Eligible" strip used to
                         sit here, between the size ladder and the quantity stepper — 83px of
                         reassurance interrupting the one place on the page where a shopper is
                         mid-decision, on a phone where Add to Bag was already two screens
                         down. Both claims were also printed again, word for word, 150px below
                         the button in .product-benefits-row.

                         They now appear once, in that strip, where every other delivery and
                         returns promise already lives. Nothing was dropped; the return window
                         there reads RETURN_WINDOW_DAYS rather than a hardcoded 14, so it can
                         no longer disagree with the returns page. */ ?>

                <!-- In-Bag Status Banner (Visible if item is in cart) -->
                <div id="inBagStatusBanner" class="in-bag-status-banner" style="display: none;">
                    <!-- Populated dynamically via JS -->
                </div>

                <!-- Quantity Stepper + Wishlist -->
                <div class="product-quantity-row">
                    <div class="qty-controls product-qty-controls">
                        <button type="button" onclick="changeProductQuantity(-1)" class="qty-btn" aria-label="Decrease quantity">−</button>
                        <span id="selectedQtyDisplay" class="qty-value">1</span>
                        <button type="button" onclick="changeProductQuantity(1)" class="qty-btn" aria-label="Increase quantity">+</button>
                    </div>

                    <button onclick="handleWishlistToggleClick()" class="btn-wishlist-action" aria-label="Add to Wishlist">
                        <i class="fa-regular fa-heart" id="detailWishlistIcon"></i>
                    </button>
                </div>

                <!-- Action Buttons: Add to Bag & Buy Now / Checkout / Product Enquiry -->
                <?php
                /* Out of stock has to reach the button itself.
                   ────────────────────────────────────────────────────────────
                   This was gated only on $dvSoldInCountry, so a piece with every
                   size at zero still drew a live "Add to Bag". Nothing could
                   actually be oversold — the size pills disable themselves and
                   actions/cart_action.php re-checks on the server — but both of
                   those are guards at the edges. A product with NO size grid has
                   neither, and there the only thing between a sold-out garment and
                   an order was a check the shopper never sees.

                   Computed here rather than reusing $schemaInStock from line 462,
                   which is set inside a conditional. A button that decides whether
                   a sale can happen should not depend on a variable that may not
                   have been reached. */
                $dvInStock = function_exists('productIsInStock')
                    ? productIsInStock($product, $variants ?? [], $productColors ?? [])
                    : true;
                ?>
                <div class="product-action-buttons-group">
                    <?php if ($dvSoldInCountry && $dvInStock): ?>
                    <button onclick="handleAddToCartClick()" class="btn-luxury btn-add-bag">
                        <i class="fa-solid fa-bag-shopping"></i> Add to Bag
                    </button>

                    <button onclick="handleBuyNowClick()" class="btn-luxury-outline btn-buy-now">
                        Buy Now
                    </button>
                    <?php elseif ($dvSoldInCountry): ?>
                    <?php /* Sold here, but there is none left — say which of the two it
                             is. "Not Available Here" would send a shopper hunting for a
                             country switch that would not help. */ ?>
                    <button class="btn-luxury btn-add-bag is-unavailable" disabled>
                        <i class="fa-solid fa-bag-shopping"></i> Sold Out
                    </button>
                    <?php else: ?>
                    <button class="btn-luxury btn-add-bag is-unavailable" disabled>
                        <i class="fa-solid fa-bag-shopping"></i> Not Available Here
                    </button>
                    <?php endif; ?>

                </div>

                <?php /* A link, not a third button in the row above.
                         ────────────────────────────────────────────────────────────────
                         It used to sit beside Add to Bag and Buy Now in the same outlined
                         box, at the same weight — so the three read as equal choices when
                         they are not. Add to Bag and Buy Now are the two ways to purchase;
                         this is a question asked INSTEAD of purchasing, by someone unsure
                         of their size. Given equal prominence it takes a third of the
                         decision space from the two actions that complete a sale, and a
                         shopper who has decided has to read all three to find theirs.

                         Demoted, not removed: the person who needs it is the one who would
                         otherwise leave, so it stays directly under the buttons where that
                         doubt occurs, phrased as the question they are actually asking. */ ?>
                <p class="product-fit-help">
                    Not sure of your size?
                    <button type="button" class="product-fit-help-link"
                            onclick="document.getElementById('productEnquiryModal').style.display='flex'">
                        <i class="fa-regular fa-envelope" aria-hidden="true"></i>
                        Ask us about the fit
                    </button>
                </p>
                </div>

                <!-- Compact Purchase Benefits -->
                <?php
                /* These four tiles are promises, so each one has to be true for the
                   country the shopper is actually in. Two of them were not.

                   COD was printed unconditionally, so a shopper in the UK — where
                   store_countries.cod_allowed is 0 and actions/checkout_action.php
                   refuses cash outright — was told "COD Available" on every product,
                   then rejected at the last step of checkout. That is the worst place
                   to break a promise.

                   The free-shipping line read straight from the threshold, so a country
                   whose threshold is 0 advertised "On orders over £0.00", which reads as
                   an unfinished setting rather than an offer. With a 0 threshold every
                   order qualifies, so it is stated as that.

                   The return window was typed as "14-Day" while pages/returns.php,
                   terms, account and the structured data all read RETURN_WINDOW_DAYS —
                   the same drift that was fixed on the returns page. */
                $benefitFreeMin = freeShippingMinForCountry($pdo);
                $benefitCod     = function_exists('codAllowedInCurrentCountry')
                                ? codAllowedInCurrentCountry() : true;
                ?>
                <?php /* The page's one trust strip. It used to be one of three, carrying ten
                         claims between them of which four were the same claim twice: this row
                         said "14-Day Easy Return/Exchange" while the strip above the button
                         said "14-Day Return Eligible", and it said "Assured Quality" while
                         Shop with Confidence said "Quality-Checked Before Dispatch". Repeating
                         a promise does not make it more believable; it makes the page longer.

                         "Assured Quality" is the one line that is gone rather than moved. Of
                         the ten it was the only one that promises nothing checkable —
                         "Quality-Checked Before Dispatch", now in the accordion below, says
                         the same thing and says who does it and when. */ ?>
                <div class="product-benefits-row">
                    <div class="benefit-item">
                        <i class="fa-solid fa-truck-fast"></i>
                        <span>Free Shipping<br><small><?= $benefitFreeMin > 0
                            ? 'On orders over ' . htmlspecialchars(formatPrice($benefitFreeMin))
                            : 'On all orders' ?></small></span>
                    </div>
                    <div class="benefit-item">
                        <i class="fa-solid fa-box-open"></i>
                        <span>Dispatched<br>in 24 Hours</span>
                    </div>
                    <div class="benefit-item">
                        <i class="fa-solid fa-rotate-left"></i>
                        <span><?= (int)RETURN_WINDOW_DAYS ?>-Day Easy<br>Return/Exchange</span>
                    </div>
                    <?php if ($benefitCod): ?>
                    <div class="benefit-item">
                        <i class="fa-solid fa-money-bill-wave"></i>
                        <span>COD<br>Available</span>
                    </div>
                    <?php endif; ?>
                </div>

                <?php
                    /* What this code is worth HERE, in the currency being shown.
                       ────────────────────────────────────────────────────────────
                       promo_codes holds one discount_value and one min_order, typed
                       in the shop's own currency, so this line used to advertise a
                       ₹50-off-over-₹300 coupon to a UK shopper as "£50.00 off over
                       £300.00" — a hundred times the real discount — while the
                       validator refused them with "Minimum order of ₹300.00". Page
                       and code speaking different currencies about the same coupon.

                       promoValuesForCountry() returns the figures that will actually
                       be applied, per country, and promoIsUnpricedForCountry() says
                       when a fixed-amount code has no local figure at all. A code
                       that cannot be honoured here is not advertised here — better
                       than printing a number the checkout will refuse.

                       Percentages are currency-free and pass through untouched. */
                    $offerBits = [];
                    foreach (($activeCoupons ?? []) as $c) {
                        if (promoIsUnpricedForCountry($pdo, $c)) { continue; }
                        $cVals = promoValuesForCountry($pdo, $c);

                        $discountLabel = $c['discount_type'] === 'percentage'
                            ? (rtrim(rtrim(number_format($cVals['discount_value'], 1), '0'), '.') . '% off')
                            : (formatPrice($cVals['discount_value']) . ' off');
                        $minOrderNote = $cVals['min_order'] > 0 ? ' over ' . formatPrice($cVals['min_order']) : '';
                        // The code stays click-to-copy, just rendered as plain text
                        // rather than a chip with its own button.
                        $offerBits[] = '<button type="button" class="offer-code" onclick="copyCouponCode(\''
                            . addslashes($c['code']) . '\', this)" title="Click to copy">'
                            . htmlspecialchars($c['code']) . '</button> ' . htmlspecialchars($discountLabel . $minOrderNote);
                    }
                ?>

                <!-- Product Detail Accordions -->
                <div class="product-accordions">

                    <?php /* "Offers & Coupons" was moved out of this accordion and up next to
                             the price — see the .product-offers block above. Coupon codes only
                             work if a shopper actually sees them, and an accordion collapsed by
                             default meant almost nobody did. */ ?>

                    <?php if (!empty($generalSpecs) || !empty($productComponents)): ?>
                    <div class="product-accordion">
                        <?php // A real <button>, not a div with role="button". It is focusable and
                              // fires on Enter/Space for free, so the hand-rolled onkeydown that used
                              // to live here is gone — kept alongside a button it fired the toggle
                              // twice (once on keydown, once on the click the key press generates)
                              // and the panel snapped shut again. ?>
                        <button type="button" class="product-accordion-header" aria-expanded="false" onclick="toggleProductAccordion(this)">
                            Product Details <i class="fa-solid fa-plus toggle-icon" aria-hidden="true"></i>
                        </button>
                        <div class="product-accordion-content">
                            <?php if (!empty($productComponents)): ?>
                            <div class="product-details-tabs" role="tablist" aria-label="Product details by component">
                                <button type="button" class="product-details-tab-btn active" role="tab" aria-selected="true" onclick="switchProductDetailsTab(this, 'about')">About</button>
                                <?php foreach ($productComponents as $comp): ?>
                                <button type="button" class="product-details-tab-btn" role="tab" aria-selected="false" onclick="switchProductDetailsTab(this, 'comp-<?= $comp['id'] ?>')"><?= htmlspecialchars($comp['name']) ?></button>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>

                            <?php /* Each panel carries its own heading, hidden on desktop where the
                                     tab button already names it. On a phone the tabs are hidden and
                                     every panel is shown at once, so the heading is what tells the
                                     shopper which garment's measurements they are reading. A real
                                     heading, not CSS ::before text, so it is in the accessibility
                                     tree and can be navigated to. */ ?>
                            <div class="product-details-tab-panel" data-panel="about">
                                <h4 class="product-details-panel-title">About</h4>
                                <div class="specifications-grid">
                                    <?php if (!empty($productComponents)): ?>
                                    <div><strong>No. of Components:</strong> <?= count($productComponents) ?></div>
                                    <div><strong>Components:</strong> <?= htmlspecialchars(implode(', ', array_map(fn($c) => $c['name'], $productComponents))) ?></div>
                                    <?php endif; ?>
                                    <?php foreach ($generalSpecs as $spec): ?>
                                    <div><strong><?= htmlspecialchars($spec['label']) ?>:</strong> <?= htmlspecialchars($spec['value']) ?><?= !empty($spec['unit']) ? ' ' . htmlspecialchars($spec['unit']) : '' ?></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <?php foreach ($productComponents as $comp): ?>
                            <div class="product-details-tab-panel" data-panel="comp-<?= $comp['id'] ?>" style="display:none;">
                                <h4 class="product-details-panel-title"><?= htmlspecialchars($comp['name']) ?></h4>
                                <div class="specifications-grid">
                                    <?php foreach ($comp['specs'] as $spec): ?>
                                    <div><strong><?= htmlspecialchars($spec['label']) ?>:</strong> <?= htmlspecialchars($spec['value']) ?><?= !empty($spec['unit']) ? ' ' . htmlspecialchars($spec['unit']) : '' ?></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php /* Design & Fit is NOT an accordion. It is the one panel here that
                             describes the garment being sold — colour, fabric, sleeve, neck,
                             pattern, occasion, fit, and what size the model wears — which is
                             what a shopper is deciding on. Everything else in this stack
                             (shipping, returns, product code, the confidence strip) is the
                             same on every product and can stay behind a tap.

                             It began life collapsed, below the fold, in a stack that only
                             permits one open panel at a time. So the most useful content on
                             the page cost a scroll and a click to reach.

                             Made static rather than "open by default": toggleProductAccordion()
                             closes every other panel before opening one, so a default-open
                             accordion is closed again the moment a shopper taps any other
                             heading. A plain section cannot be closed by accident and does not
                             compete with the single-open rule the rest of the stack relies on.

                             Was named "Specifications", which the stack already used further
                             down for SKU and package dimensions — two panels with the same
                             name and no way to tell them apart while closed. */ ?>
                    <div class="product-detail-static">
                        <h3 class="product-detail-static-title">Design &amp; Fit</h3>
                        <div class="product-detail-static-content">
                            <div class="specifications-grid">
                                <?php // Only when the owner actually entered one. This used to invent
                                      // "MD-" . id whenever the field was blank — which is every product
                                      // in the catalogue — producing a second code that contradicted the
                                      // SKU shown further down the same page. ?>
                                <?php if (!empty($product['atelier_code'])): ?>
                                <div><strong>Atelier Code:</strong> <?= htmlspecialchars($product['atelier_code']) ?></div>
                                <?php endif; ?>
                                <div><strong>Color:</strong> <?= htmlspecialchars(dievonProductColour($product) ?: 'Editorial') ?></div>
                                <div><strong>Brand:</strong> <?= htmlspecialchars(!empty($product['brand']) ? $product['brand'] : 'Dievon In-House') ?></div>
                                <div><strong>Fabric:</strong> <?= htmlspecialchars(!empty($product['fabric']) ? $product['fabric'] : '100% Premium Material') ?></div>
                                <?php // Rendered only when the garment really has them. Trousers were
                                      // publishing "Sleeve: Standard" and "Neck: Standard" because these
                                      // rows invented a default rather than staying quiet — on the page
                                      // and in the meta keywords. ?>
                                <?php if (!empty($product['sleeve'])): ?>
                                <div><strong>Sleeve:</strong> <?= htmlspecialchars($product['sleeve']) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($product['neck'])): ?>
                                <div><strong>Neck:</strong> <?= htmlspecialchars($product['neck']) ?></div>
                                <?php endif; ?>
                                <div><strong>Pattern:</strong> <?= htmlspecialchars(!empty($product['pattern']) ? $product['pattern'] : 'Solid') ?></div>
                                <div><strong>Occasion:</strong> <?= htmlspecialchars(!empty($product['occasion']) ? $product['occasion'] : 'Versatile') ?></div>

                                <?php
                                // Fit and model reference. Unlike the rows above these have NO
                                // invented default: "Fit: Regular" on a garment nobody has
                                // assessed is a guess the shopper would act on. Shown only when
                                // genuinely filled in.
                                if (!empty($product['fit_description'])): ?>
                                <div><strong>Fit:</strong> <?= htmlspecialchars($product['fit_description']) ?></div>
                                <?php endif; ?>

                                <?php
                                // "Model is 5'8\" and wears size S" is the single most useful line
                                // for judging fit from a photograph, which is why it sits with the
                                // specs rather than buried in the size guide.
                                $mh = trim((string)($product['model_height'] ?? ''));
                                $ms = trim((string)($product['model_size_worn'] ?? ''));
                                if ($mh !== '' || $ms !== ''):
                                    $bits = [];
                                    if ($mh !== '') { $bits[] = 'is ' . htmlspecialchars($mh) . ' tall'; }
                                    if ($ms !== '') { $bits[] = 'wears size ' . htmlspecialchars($ms); }
                                ?>
                                <div><strong>Model:</strong> <?= implode(' and ', $bits) ?></div>
                                <?php endif; ?>
                            </div>

                        </div>
                    </div>

                    <div class="product-accordion">
                        <button type="button" class="product-accordion-header" aria-expanded="false" onclick="toggleProductAccordion(this)">
                            Fabric &amp; Care <i class="fa-solid fa-plus toggle-icon" aria-hidden="true"></i>
                        </button>
                        <div class="product-accordion-content">
                            <?php // products.wash_care is collected by the admin form and was never
                                  // read here, so every garment in the catalogue — silk, linen, cotton —
                                  // showed the same "dry clean only" paragraph. The generic text stays
                                  // as the fallback for products with nothing entered yet. ?>
                            <?php /* productWashCare() supplies the default, so the sentence a
                                     shopper reads when nothing was typed is the same one the admin
                                     form offered while typing. */ ?>
                            <p><?= nl2br(htmlspecialchars(productWashCare($product))) ?></p>
                            <?php if (!empty($product['composition'])): ?>
                                <p style="margin-top: 10px;"><strong>Composition:</strong> <?= htmlspecialchars($product['composition']) ?></p>
                            <?php endif; ?>
                            <?php if (!empty($product['sourcing'])): ?>
                                <p style="margin-top: 10px;"><strong>Sourcing:</strong> <?= htmlspecialchars($product['sourcing']) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="product-accordion">
                        <button type="button" class="product-accordion-header" aria-expanded="false" onclick="toggleProductAccordion(this)">
                            Shipping &amp; Returns <i class="fa-solid fa-plus toggle-icon" aria-hidden="true"></i>
                        </button>
                        <div class="product-accordion-content">
                            <?php // Per-product overrides, else the shop-wide promise. The return window
                                  // comes from RETURN_WINDOW_DAYS so this paragraph, pages/returns.php and
                                  // the Product schema can never quote three different numbers. ?>
                            <?php if (!empty($product['shipping_info'])): ?>
                                <p><?= nl2br(htmlspecialchars($product['shipping_info'])) ?></p>
                            <?php else: ?>
                                <p><?= htmlspecialchars(SHOP_NAME) ?> provides insured tracked courier delivery on all orders, dispatched within 24–48 business hours.</p>
                            <?php endif; ?>
                            <?php if (!empty($product['returns_info'])): ?>
                                <p class="product-returns-line"><?= nl2br(htmlspecialchars($product['returns_info'])) ?></p>
                            <?php else: ?>
                                <p class="product-returns-line">Returns and size exchanges are accepted within <?= (int)RETURN_WINDOW_DAYS ?> days of delivery, in original unworn condition with tags attached.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Product Specifications & Identifiers -->
                    <div class="product-accordion">
                        <button type="button" class="product-accordion-header" aria-expanded="false" onclick="toggleProductAccordion(this)">
                            Product Code &amp; Dimensions <i class="fa-solid fa-plus toggle-icon" aria-hidden="true"></i>
                        </button>
                        <div class="product-accordion-content">
                            <ul style="list-style: none; padding: 0; margin: 0; font-size: 13px; color: var(--text-secondary); line-height: 1.8;">
                                <?php // productDisplayCode() is the same helper the schema uses, so the
                                      // code a shopper reads and the code Google indexes are one string. ?>
                                <li><strong>SKU:</strong> <?= htmlspecialchars(productDisplayCode($product)) ?></li>
                                <?php if (!empty($product['barcode'])): ?>
                                    <li><strong>Barcode / EAN:</strong> <?= htmlspecialchars($product['barcode']) ?></li>
                                <?php endif; ?>
                                <?php if (!empty($product['weight'])): ?>
                                    <li><strong>Package Weight:</strong> <?= htmlspecialchars($product['weight']) ?></li>
                                <?php endif; ?>
                                <?php if (!empty($product['dimensions'])): ?>
                                    <li><strong>Dimensions (L×W×H):</strong> <?= htmlspecialchars($product['dimensions']) ?></li>
                                <?php endif; ?>
                                <?php if (!empty($product['tags'])): ?>
                                    <li><strong>Tags:</strong> <?= htmlspecialchars($product['tags']) ?></li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>

                    <?php if (!empty($product['video_url'])): ?>
                    <div class="product-accordion">
                        <button type="button" class="product-accordion-header" aria-expanded="false" onclick="toggleProductAccordion(this)">
                            Campaign Video <i class="fa-solid fa-plus toggle-icon" aria-hidden="true"></i>
                        </button>
                        <div class="product-accordion-content">
                            <video style="width: 100%; max-height: 400px; object-fit: cover; border: 1px solid var(--border-light);" controls>
                                <source src="<?= htmlspecialchars(dievonVideoSrc($product['video_url'] ?? '')) ?>" type="video/mp4">
                                Your browser does not support the video tag.
                            </video>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php /* Last in the stack on purpose. Every panel above it is about THIS
                             garment — its details, its fabric, its measurements. This one is
                             about the shop, and it is the same on every product page, so it
                             follows rather than leads. It was an always-open 134px box between
                             the delivery checker and these panels; collapsed it costs 54. */ ?>
                    <div class="product-accordion">
                        <button type="button" class="product-accordion-header" aria-expanded="false" onclick="toggleProductAccordion(this)">
                            Shop with Confidence <i class="fa-solid fa-plus toggle-icon" aria-hidden="true"></i>
                        </button>
                        <div class="product-accordion-content">
                            <ul class="shop-confidence-list">
                                <li><i class="fa-solid fa-lock" aria-hidden="true"></i> Secure Checkout &amp; Data Privacy</li>
                                <li><i class="fa-solid fa-user-tie" aria-hidden="true"></i> Styled by Our In-House Team</li>
                                <li><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> Quality-Checked Before Dispatch</li>
                                <li><i class="fa-solid fa-headset" aria-hidden="true"></i> Dedicated Post-Purchase Support</li>
                            </ul>
                        </div>
                    </div>

                </div>

                <?php /* Built BEFORE the wrapper, because a shop with coupons that
                         none of them can be honoured in this country would otherwise
                         render an empty line with a lonely tag icon in it. */ ?>

                <!-- COD Availability Checker -->
                <div class="delivery-checker-box">
                    <div class="delivery-estimate-row">
                        <i class="fa-solid fa-truck-fast"></i>
                        <span>Standard delivery by <strong><?= htmlspecialchars($estimatedDeliveryDate) ?></strong></span>
                    </div>
                    <span class="delivery-checker-label">Check Exact Delivery Date / COD Availability:</span>
                    <div class="delivery-checker-input-wrap">
                        <input type="text" id="zipCode" placeholder="Enter Zip / Postal Code">
                        <button onclick="checkDelivery()" class="btn-luxury-outline">Check</button>
                    </div>
                    <div id="deliveryStatus" class="delivery-status-msg"></div>
                </div>

                 <?php if (!empty($offerBits)): ?>
                <!-- Available offers sit just ABOVE the delivery box, as its own line —
                     grouped with the "what it costs / when it arrives" details, but not
                     boxed in with them. -->
                <p class="product-offers-line">
                    <i class="fa-solid fa-tag"></i>
                    <?= implode(' <span class="offer-sep">·</span> ', $offerBits) ?>
                </p>
                <?php endif; ?>

                <?php /* "Shop with Confidence" moved into the accordion stack below, all four
                         lines intact. It was 134px of always-open list standing between the
                         delivery checker and the product's own detail panels, and none of what
                         it says changes a purchase decision the way a delivery date or a return
                         window does — it is brand assurance, which is exactly what a collapsed
                         panel is for. Collapsed it costs 54px instead of 134. */ ?>

            </div>

        </div>

    </div>
</section>

<!-- ══ Ratings & Reviews ════════════════════════════ -->
<section class="product-reviews-section section-space">
    <div class="container product-reviews-container">

        <div class="product-section-header-row">
            <div>
                <h2>Customer Reviews</h2>
                <div class="product-rating-row" style="margin-top: 5px;">
                    <?php // Stars only when a real rating exists. $avgRating defaulted to 5.0,
                          // so a product with no reviews at all rendered five solid gold stars
                          // directly beside the words "No customer reviews yet." — a rating the
                          // shop had awarded itself. ?>
                    <?php if ($revCount > 0): ?>
                    <div class="stars">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="fa-<?= $i <= round($avgRating) ? 'solid' : 'regular' ?> fa-star"></i>
                        <?php endfor; ?>
                    </div>
                    <?php endif; ?>
                    <span><?= $revCount > 0 ? "{$avgRating} / 5 ({$revCount} " . ($revCount === 1 ? "Review" : "Reviews") . ")" : "No customer reviews yet." ?></span>
                </div>
            </div>
            <button class="btn-luxury-outline btn-write-review" onclick="openFadeModal('writeReviewModal')">Write a Review</button>
        </div>

        <div class="reviews-list-grid">
            <?php if ($revCount > 0): ?>
                <?php foreach ($dbReviews as $r): ?>
                <div class="review-card-item">
                    <div class="review-card-header">
                        <div>
                            <strong class="review-buyer-name"><?= htmlspecialchars($r['author_name'] ?? $r['customer_name'] ?? 'Guest Customer') ?></strong>
                            <?php // is_verified_purchase is set by the endpoint only after
                                  // finding a real order containing this product. The older
                                  // flags are kept so legacy rows still display correctly. ?>
                            <?php if (!empty($r['is_verified_purchase']) || !empty($r['verified_buyer']) || !empty($r['is_verified'])): ?>
                                <span class="review-verified-tag"><i class="fa-solid fa-circle-check"></i> Verified purchase</span>
                            <?php endif; ?>
                        </div>
                        <div class="review-date"><?= date('F j, Y', strtotime($r['created_at'] ?? 'now')) ?></div>
                    </div>
                    <div class="review-card-stars">
                        <?php for ($s = 1; $s <= 5; $s++): ?>
                            <i class="fa-<?= $s <= (int)$r['rating'] ? 'solid' : 'regular' ?> fa-star"></i>
                        <?php endfor; ?>
                    </div>
                    <?php // No invented title. It used to fall back to "Verified Customer
                          // Review", which asserted verification on the shop's own words. ?>
                    <?php if (trim((string)($r['title'] ?? '')) !== ''): ?>
                    <?php // h3: each review sits under the "Customer Reviews" h2, so h3 is
                          // the level directly beneath it. As an h4 it skipped a level. ?>
                    <h3 class="review-card-title"><?= htmlspecialchars($r['title']) ?></h3>
                    <?php endif; ?>
                    <p class="review-card-text"><?= nl2br(htmlspecialchars($r['review_text'] ?? '')) ?></p>

                    <?php // Reporting is deliberately available to everyone, signed in or
                          // not: the person best placed to spot a defamatory or fake review
                          // is often not a customer. The endpoint rate-limits by IP. ?>
                    <button type="button" class="review-report-btn"
                            onclick="reportReview(<?= (int)$r['id'] ?>)">
                        <i class="fa-regular fa-flag"></i> Report
                    </button>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="color:var(--text-muted); font-size:14px; text-align:center; padding:30px 0; grid-column: 1 / -1;">
                    <i class="fa-regular fa-comment-dots" style="font-size:32px; display:block; margin-bottom:12px; opacity:0.4;"></i>
                    There are no reviews for this garment yet. Be the first to submit a review!
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- ══ Q&A ════════════════════════════ -->


<script>window.DIEVON_CSRF = '<?= generateCsrfToken() ?>';</script>
<!-- Write Review Modal -->
<div id="writeReviewModal" class="quick-view-modal" style="display:none;" onclick="closeFadeModal('writeReviewModal')">
    <div class="quick-view-content product-modal-form-content" onclick="event.stopPropagation()">
        <button class="quick-view-close" onclick="closeFadeModal('writeReviewModal')">&times;</button>
        <h2 class="product-modal-form-title">Write a Review</h2>
        <div id="writeReviewMsg" class="product-modal-form-msg" style="display:none;"></div>

        <?php if (empty($_SESSION['customer_id'])): ?>
            <?php // The rules are stated here rather than discovered after typing a
                  // review and having it rejected. The endpoint enforces both. ?>
            <p class="review-gate-text">
                Reviews come from customers who have bought the piece, so you will need to sign in first.
            </p>
            <div class="review-gate-actions">
                <a href="<?= SITE_URL ?>/login" class="btn-luxury review-gate-btn">Sign In</a>
                <a href="<?= SITE_URL ?>/register" class="btn-luxury-outline review-gate-btn">Create Account</a>
            </div>
        <?php else: ?>
        <p class="review-gate-text">
            Reviews are published after a quick check, and are marked
            <strong>Verified purchase</strong> when they come from a real order.
        </p>
        <form id="writeReviewForm" onsubmit="submitProductReview(event, <?= (int)$productId ?>)">
            <?php // Was missing entirely — any site could post a review as a signed-in
                  // Dievon customer while they were browsing it. ?>
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <div class="form-luxury-group">
                <label>Rating</label>
                <select name="rating" class="form-luxury-input" required>
                    <option value="">Choose a rating…</option>
                    <option value="5">5 Stars - Excellent</option>
                    <option value="4">4 Stars - Good</option>
                    <option value="3">3 Stars - Average</option>
                    <option value="2">2 Stars - Poor</option>
                    <option value="1">1 Star - Terrible</option>
                </select>
            </div>
            <div class="form-luxury-group">
                <label>Review Title <span class="review-optional">(optional)</span></label>
                <input type="text" name="title" class="form-luxury-input" maxlength="150">
            </div>
            <div class="form-luxury-group">
                <label>Your Review</label>
                <textarea name="review_text" class="form-luxury-input form-luxury-textarea-tall" required minlength="10" maxlength="4000"></textarea>
            </div>
            <button type="submit" class="btn-luxury" style="width: 100%; justify-content: center; height: 50px;">Submit Review</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<!-- Ask Question Modal -->
</div>

<!-- ══ Product Lightbox Zoom Modal ════════════════════════════ -->
<?php /* Announced as a dialog, and named. It carried no role and no aria-modal,
         so a screen reader treated the enlarged image as ordinary page content
         appearing out of nowhere, with the rest of the page still readable
         behind it. */ ?>
<?php
/* Every fact this page knows about the garment, in one sheet.
   ────────────────────────────────────────────────────────────────────────────
   Built from $product, the same array the Design & Fit section below reads, so
   the two cannot drift into disagreeing about the same garment. Rendered
   server-side rather than cloned out of the DOM — cloning would duplicate every
   id inside it, and this page has plenty.

   Every row is conditional and none invents a default. A pair of trousers used
   to publish "Sleeve: Standard" because a row filled its own blank; a sheet
   whose purpose is answering questions must not answer one it has not been
   told. A row with nothing behind it simply does not appear. */
$dvSheetRows = [];
$dvAdd = function (string $label, $value) use (&$dvSheetRows) {
    $value = trim((string)$value);
    if ($value !== '') { $dvSheetRows[] = [$label, $value]; }
};
$dvAdd('Fabric',      $product['fabric']);
$dvAdd('Composition', $product['composition']);
$dvAdd('Colour',      dievonProductColour($product));
$dvAdd('Pattern',     $product['pattern']);
$dvAdd('Sleeve',      $product['sleeve']);
$dvAdd('Neck',        $product['neck']);
$dvAdd('Occasion',    $product['occasion']);
$dvAdd('Brand',       $product['brand']);
$dvAdd('Sourcing',    $product['sourcing'] ?? '');
$dvAdd('Fit',         $product['fit_description'] ?? '');
$dvAdd('Model wears', trim(trim((string)($product['model_height'] ?? '')) . ' · ' . trim((string)($product['model_size_worn'] ?? '')), ' ·'));
$dvAdd('Care',        productWashCare($product));
?>
<div id="productDetailsSheet" class="pd-sheet" role="dialog" aria-modal="true"
     aria-labelledby="pdSheetTitle" hidden onclick="closeProductDetails(event)">
    <div class="pd-sheet-panel" role="document" onclick="event.stopPropagation()">
        <div class="pd-sheet-head">
            <h2 class="pd-sheet-title" id="pdSheetTitle">Details</h2>
            <button type="button" class="pd-sheet-close" aria-label="Close details"
                    onclick="closeProductDetails(event)">&times;</button>
        </div>

        <?php if ($productComponents): ?>
        <?php /* The pieces lead, as a row of chips — on a set, "what do I actually
                 receive" outranks every specification under it.
                 Read from $productComponents, the same product_components rows the
                 Product Details accordion below already uses, so the sheet and the
                 page cannot end up naming different pieces for one garment. */ ?>
        <ul class="pd-sheet-chips">
            <?php foreach ($productComponents as $dvComp): ?>
            <li class="pd-sheet-chip"><?= htmlspecialchars($dvComp['name']) ?></li>
            <?php endforeach; ?>
        </ul>

        <?php /* Each piece keeps its own measurements under its own name. A suit's
                 top and its trousers both have a length, and merging them into one
                 list would produce two rows called "Length" with no way to tell
                 which garment either belongs to. */ ?>
        <?php foreach ($productComponents as $dvComp): ?>
            <?php if (!empty($dvComp['specs'])): ?>
            <div class="pd-sheet-group">
                <h3 class="pd-sheet-group-title"><?= htmlspecialchars($dvComp['name']) ?></h3>
                <dl class="pd-sheet-rows">
                    <?php foreach ($dvComp['specs'] as $dvSpec): ?>
                    <div class="pd-sheet-row">
                        <dt><?= htmlspecialchars($dvSpec['label']) ?></dt>
                        <dd>
                            <?= htmlspecialchars($dvSpec['value']) ?><?php
                            // "42 inch", not "42" — a measurement without its unit
                            // is not a measurement.
                            if (!empty($dvSpec['unit'])) { echo ' ' . htmlspecialchars($dvSpec['unit']); }
                            ?>
                        </dd>
                    </div>
                    <?php endforeach; ?>
                </dl>
            </div>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($dvSheetRows): ?>
        <div class="pd-sheet-group">
            <?php /* Headed only when per-piece groups sit above it — otherwise there
                     is nothing to tell it apart from and the heading is noise. */ ?>
            <?php if ($productComponents): ?>
            <h3 class="pd-sheet-group-title">The garment</h3>
            <?php endif; ?>
            <dl class="pd-sheet-rows">
                <?php foreach ($dvSheetRows as [$dvLabel, $dvValue]): ?>
                <div class="pd-sheet-row">
                    <dt><?= htmlspecialchars($dvLabel) ?></dt>
                    <dd><?= nl2br(htmlspecialchars($dvValue)) ?></dd>
                </div>
                <?php endforeach; ?>
            </dl>
        </div>
        <?php elseif (!$productComponents): ?>
        <?php /* Says which garment has nothing recorded, rather than a bare
                 "No details" that reads as a broken page. Only when the component
                 groups are empty too — otherwise the sheet has plenty to show. */ ?>
        <p class="pd-sheet-empty">No details have been recorded for <?= htmlspecialchars($product['name']) ?> yet.</p>
        <?php endif; ?>
    </div>
</div>

<div id="productLightboxModal" class="product-lightbox-modal" role="dialog" aria-modal="true"
     aria-label="Product image viewer" onclick="closeProductLightbox(event)">
    <button type="button" class="product-lightbox-close" aria-label="Close image viewer" onclick="closeProductLightbox(event)">&times;</button>
    
    <div class="product-lightbox-container-wrapper">
        <!-- Vertical Thumbnails List on Left -->
        <div class="product-lightbox-thumbnails" onclick="event.stopPropagation()">
            <!-- Populated dynamically via JS -->
        </div>
        
        <!-- Main Image Area in Center -->
        <div class="product-lightbox-main-area" onclick="event.stopPropagation()">
            <button type="button" class="product-lightbox-nav prev" onclick="navProductLightbox(-1, event)" aria-label="Previous image"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button>
            <div class="product-lightbox-content">
                <?php
                    // A 1x1 transparent GIF as a data URI — deliberately, and not a real
                    // image.
                    //
                    // This was src="", which a browser does NOT treat as "no image": it
                    // resolves the empty string against the current document, so the tag
                    // requested the PRODUCT PAGE as an image, received HTML, and drew a
                    // broken-image icon. It also downloaded the whole page a second time
                    // on every visit.
                    //
                    // Seeding it with the product's real photo fixes the broken icon but
                    // swaps one waste for another: the visible gallery serves the WebP
                    // (121 KB), so a JPEG seed is a SEPARATE ~800 KB download on every
                    // visit, for a modal most visitors never open — and it is never even
                    // displayed, because openProductLightbox() always assigns lbImg.src
                    // before showing the modal.
                    //
                    // A data URI is zero requests and ~70 bytes, and the lightbox loads
                    // the full-resolution image at the moment it is opened, which is the
                    // one moment full resolution is actually wanted.
                    $lightboxPlaceholder = 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==';
                ?>
                <img id="productLightboxImg" src="<?= $lightboxPlaceholder ?>" alt="<?= htmlspecialchars(productImageAlt($product, 'Full view')) ?>">
            </div>
            <button type="button" class="product-lightbox-nav next" onclick="navProductLightbox(1, event)" aria-label="Next image"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>
            <div id="productLightboxCounter" class="product-lightbox-counter">1 / 1</div>
        </div>
    </div>
</div>

<script>
let galleryImagesList = [
    <?php if (!empty($product['image'])): ?>
    '<?= SITE_URL ?>/uploads/products/<?= htmlspecialchars($product['image']) ?>',
    <?php endif; ?>
    <?php foreach ($additionalImages as $img): ?>
    '<?= SITE_URL ?>/uploads/products/<?= htmlspecialchars($img['image']) ?>',
    <?php endforeach; ?>
];
let currentGalleryIndex = 0;

function openProductLightbox(src) {
    if (!src) {
        if (galleryImagesList.length > 0) {
            src = galleryImagesList[0];
        } else {
            return;
        }
    }
    
    const modal = document.getElementById('productLightboxModal');
    const lbImg = document.getElementById('productLightboxImg');
    const counter = document.getElementById('productLightboxCounter');
    
    if (modal && lbImg) {
        lbImg.src = src;
        modal.style.display = 'flex';
        requestAnimationFrame(() => requestAnimationFrame(() => modal.classList.add('is-visible')));
        dievonScrollLock.lock('lightbox');

        /* Focus moves in, and is remembered so it can be handed back.
           Without this a keyboard user who opened the viewer was left with focus
           still on the thumbnail behind it — Tab walked the page underneath while
           the image covered the screen, and closing dropped them at the top of
           the document rather than back at the photograph they were looking at. */
        window.__lightboxReturnFocus = document.activeElement;
        const closeBtn = modal.querySelector('.product-lightbox-close');
        if (closeBtn) { setTimeout(() => closeBtn.focus(), 50); }

        const idx = galleryImagesList.indexOf(src);
        if (idx !== -1) currentGalleryIndex = idx;

        if (counter && galleryImagesList.length > 0) {
            counter.textContent = (currentGalleryIndex + 1) + ' / ' + galleryImagesList.length;
        }

        renderLightboxThumbnails();
        lbResetZoom();
    }
}

function renderLightboxThumbnails() {
    const thumbsContainer = document.querySelector('.product-lightbox-thumbnails');
    if (thumbsContainer) {
        thumbsContainer.innerHTML = galleryImagesList.map((imgSrc, idx) => `
            <div class="product-lightbox-thumb-item ${idx === currentGalleryIndex ? 'active' : ''}" onclick="selectLightboxImage(${idx})">
                <img src="${imgSrc}" alt="${escHtml(DIEVON_PRODUCT_NAME)} - Thumbnail ${idx + 1}">
            </div>
        `).join('');
    }
}

function selectLightboxImage(idx) {
    if (idx < 0 || idx >= galleryImagesList.length) return;
    currentGalleryIndex = idx;

    const lbImg = document.getElementById('productLightboxImg');
    const counter = document.getElementById('productLightboxCounter');

    if (lbImg) {
        lbImg.classList.add('is-swapping');
        setTimeout(() => {
            lbImg.src = galleryImagesList[idx];
            lbImg.classList.remove('is-swapping');
        }, 120);
    }
    if (counter) counter.textContent = (idx + 1) + ' / ' + galleryImagesList.length;

    document.querySelectorAll('.product-lightbox-thumb-item').forEach((item, i) => {
        if (i === idx) {
            item.classList.add('active');
            item.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        } else {
            item.classList.remove('active');
        }
    });

    lbResetZoom();
}

/* Details sheet — open, close, and give the page back.
   ────────────────────────────────────────────────────────────────────────────
   Follows closeProductLightbox below rather than inventing a second convention:
   a class drives the transition, the scroll lock is released on the way out,
   and focus returns to the control that opened it instead of the top of the
   document. `hidden` is dropped before the class goes on, because the browser
   will not transition an element it has not laid out yet. */
function openProductDetails() {
    const sheet = document.getElementById('productDetailsSheet');
    if (!sheet) { return; }
    window.__pdSheetReturnFocus = document.activeElement;
    sheet.hidden = false;
    /* Read offsetHeight to force layout between dropping `hidden` and adding the
       class. Without a layout in between the browser sees one style change and
       the sheet appears instantly instead of sliding.
       A requestAnimationFrame would also do it, but rAF is throttled in
       background tabs and does not fire at all in some embedded webviews — and
       when it does not, the class is never added and the sheet never appears.
       A forced reflow has no such dependency. */
    void sheet.offsetHeight;
    sheet.classList.add('is-open');
    dievonScrollLock.lock('details');
    const close = sheet.querySelector('.pd-sheet-close');
    if (close) { try { close.focus({ preventScroll: true }); } catch (err) {} }
}

function closeProductDetails(e) {
    if (e) { e.stopPropagation(); }
    const sheet = document.getElementById('productDetailsSheet');
    if (!sheet) { return; }
    sheet.classList.remove('is-open');
    dievonScrollLock.unlock('details');
    // Matches the 300ms in the stylesheet; hiding sooner cuts the slide short.
    setTimeout(() => { sheet.hidden = true; }, 300);
    if (window.__pdSheetReturnFocus && window.__pdSheetReturnFocus.focus) {
        try { window.__pdSheetReturnFocus.focus({ preventScroll: true }); } catch (err) {}
        window.__pdSheetReturnFocus = null;
    }
}

// Escape closes it, as it does every other overlay on this page.
document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') { return; }
    const sheet = document.getElementById('productDetailsSheet');
    if (sheet && !sheet.hidden) { closeProductDetails(); }
});

function closeProductLightbox(e) {
    if (e) e.stopPropagation();
    const modal = document.getElementById('productLightboxModal');
    if (modal) {
        modal.classList.remove('is-visible');
        setTimeout(() => { modal.style.display = 'none'; }, 300);
        dievonScrollLock.unlock('lightbox');
        // Back to the thumbnail that opened it, not the top of the page.
        if (window.__lightboxReturnFocus && window.__lightboxReturnFocus.focus) {
            try { window.__lightboxReturnFocus.focus({ preventScroll: true }); } catch (err) {}
            window.__lightboxReturnFocus = null;
        }
        lbResetZoom();
    }
}

function openFadeModal(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.style.display = 'flex';
    requestAnimationFrame(() => requestAnimationFrame(() => el.classList.add('is-visible')));
}
function closeFadeModal(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.remove('is-visible');
    setTimeout(() => { el.style.display = 'none'; }, 300);
}

function navProductLightbox(dir, e) {
    if (e) e.stopPropagation();
    if (!galleryImagesList.length) return;

    const nextIdx = (currentGalleryIndex + dir + galleryImagesList.length) % galleryImagesList.length;
    selectLightboxImage(nextIdx);
}

// Keyboard arrows navigation for Lightbox
document.addEventListener('keydown', e => {
    const modal = document.getElementById('productLightboxModal');
    if (modal && modal.style.display === 'flex') {
        if (e.key === 'ArrowLeft') {
            navProductLightbox(-1);
        } else if (e.key === 'ArrowRight') {
            navProductLightbox(1);
        } else if (e.key === 'Escape') {
            closeProductLightbox();
        }
    }
});

// ── Lightbox zoom, pan & swipe (vanilla JS — no external library) ──────
(function () {
    const MIN_SCALE = 1;
    const MAX_SCALE = 4;
    const DBLTAP_MS = 300;
    const DBLTAP_DIST = 30;
    const SWIPE_THRESHOLD = 50;

    let scale = 1, panX = 0, panY = 0;
    let isPanning = false, panStartX = 0, panStartY = 0, panOriginX = 0, panOriginY = 0;
    let pinchStartDist = 0, pinchStartScale = 1;
    let touchStartX = 0, touchStartY = 0, touchMoved = false;
    let lastTapTime = 0, lastTapX = 0, lastTapY = 0;

    function getImg() { return document.getElementById('productLightboxImg'); }

    window.lbResetZoom = function () {
        scale = 1; panX = 0; panY = 0;
        const img = getImg();
        if (img) {
            img.style.transform = '';
            img.classList.remove('lb-zoomed', 'lb-panning');
        }
    };

    // Keep the pan inside the bounds of the zoomed image so it can never be
    // dragged fully off-screen with no way back.
    function clampPan() {
        const img = getImg();
        if (!img) return;
        const container = img.closest('.product-lightbox-main-area');
        if (!container) return;
        const cw = container.clientWidth, ch = container.clientHeight;
        const iw = img.offsetWidth * scale, ih = img.offsetHeight * scale;
        const maxX = Math.max(0, (iw - cw) / 2);
        const maxY = Math.max(0, (ih - ch) / 2);
        panX = Math.min(maxX, Math.max(-maxX, panX));
        panY = Math.min(maxY, Math.max(-maxY, panY));
    }

    function applyTransform() {
        const img = getImg();
        if (!img) return;
        clampPan();
        img.style.transform = `translate(${panX}px, ${panY}px) scale(${scale})`;
        img.classList.toggle('lb-zoomed', scale > 1);
    }

    function setZoom(newScale, originXRatio, originYRatio) {
        newScale = Math.min(MAX_SCALE, Math.max(MIN_SCALE, newScale));
        if (newScale === 1) { panX = 0; panY = 0; }
        scale = newScale;
        applyTransform();
    }

    function toggleDblZoom(clientX, clientY) {
        const img = getImg();
        if (!img) return;
        if (scale > 1) {
            setZoom(1);
        } else {
            const rect = img.getBoundingClientRect();
            panX = 0; panY = 0;
            scale = 2.2;
            applyTransform();
            // Nudge the pan so the tapped point stays roughly under the cursor/finger
            const offsetX = (clientX - (rect.left + rect.width / 2));
            const offsetY = (clientY - (rect.top + rect.height / 2));
            panX = -offsetX * (scale - 1) / scale;
            panY = -offsetY * (scale - 1) / scale;
            applyTransform();
        }
    }

    function dist(t1, t2) {
        return Math.hypot(t1.clientX - t2.clientX, t1.clientY - t2.clientY);
    }

    document.addEventListener('DOMContentLoaded', function () {
        const img = getImg();
        if (!img) return;

        // Desktop: double-click to zoom
        img.addEventListener('dblclick', function (e) {
            toggleDblZoom(e.clientX, e.clientY);
        });

        // Desktop: scroll wheel to zoom
        img.addEventListener('wheel', function (e) {
            e.preventDefault();
            setZoom(scale + (e.deltaY < 0 ? 0.25 : -0.25));
        }, { passive: false });

        // Desktop: drag to pan when zoomed
        img.addEventListener('mousedown', function (e) {
            if (scale <= 1) return;
            isPanning = true;
            panStartX = e.clientX; panStartY = e.clientY;
            panOriginX = panX; panOriginY = panY;
            img.classList.add('lb-panning');
            e.preventDefault();
        });
        document.addEventListener('mousemove', function (e) {
            if (!isPanning) return;
            panX = panOriginX + (e.clientX - panStartX);
            panY = panOriginY + (e.clientY - panStartY);
            applyTransform();
        });
        document.addEventListener('mouseup', function () {
            if (isPanning) { isPanning = false; img.classList.remove('lb-panning'); }
        });

        // Touch: pinch-zoom, single-finger pan/swipe, double-tap zoom
        img.addEventListener('touchstart', function (e) {
            if (e.touches.length === 2) {
                pinchStartDist = dist(e.touches[0], e.touches[1]);
                pinchStartScale = scale;
            } else if (e.touches.length === 1) {
                touchMoved = false;
                touchStartX = e.touches[0].clientX;
                touchStartY = e.touches[0].clientY;
                if (scale > 1) {
                    isPanning = true;
                    panStartX = touchStartX; panStartY = touchStartY;
                    panOriginX = panX; panOriginY = panY;
                }
            }
        }, { passive: true });

        img.addEventListener('touchmove', function (e) {
            touchMoved = true;
            if (e.touches.length === 2 && pinchStartDist > 0) {
                e.preventDefault();
                const newDist = dist(e.touches[0], e.touches[1]);
                setZoom(pinchStartScale * (newDist / pinchStartDist));
            } else if (e.touches.length === 1 && isPanning && scale > 1) {
                e.preventDefault();
                panX = panOriginX + (e.touches[0].clientX - panStartX);
                panY = panOriginY + (e.touches[0].clientY - panStartY);
                applyTransform();
            }
        }, { passive: false });

        img.addEventListener('touchend', function (e) {
            isPanning = false;
            pinchStartDist = 0;

            if (e.changedTouches.length !== 1) return;
            const endX = e.changedTouches[0].clientX;
            const endY = e.changedTouches[0].clientY;

            // Double-tap to zoom
            const now = Date.now();
            const tapDist = Math.hypot(endX - lastTapX, endY - lastTapY);
            if (!touchMoved && now - lastTapTime < DBLTAP_MS && tapDist < DBLTAP_DIST) {
                toggleDblZoom(endX, endY);
                lastTapTime = 0;
                return;
            }
            lastTapTime = now; lastTapX = endX; lastTapY = endY;

            // Swipe to navigate (only when not zoomed in)
            if (scale <= 1 && touchMoved) {
                const dx = endX - touchStartX;
                const dy = endY - touchStartY;
                if (Math.abs(dx) > SWIPE_THRESHOLD && Math.abs(dx) > Math.abs(dy)) {
                    navProductLightbox(dx < 0 ? 1 : -1);
                }
            }
        });
    });
})();
</script>

<!-- ══ Related Suggested Pieces ═══════════════════════════ -->
<?php if (!empty($related)): ?>
<section class="compact-suggested-section section-space">
    <div class="container">
        <div class="section-title-wrapper reveal-on-scroll">
            <span class="editorial-label">Dievon Curation</span>
            <h2 class="section-title">Suggested Pieces</h2>
        </div>
        <div class="related-compact-grid">
            <?php /* Primed once for the strip — see productHoverImage(). */
                  productHoverImagePrime($related ?? []); productPriceRangePrime($related ?? []); ?>
            <?php foreach ($related as $p): ?>
            <?php // The SAME partial the shop and home grids use. Copying the markup
                  // here is what let this grid quietly lose its badge, wishlist,
                  // compare, Quick View and Details button while the real card kept
                  // them. ?>
            <?php $card = $p; include __DIR__ . '/../includes/product_card.php'; ?>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ══ Recently Viewed ═══════════════════════════ -->
<section class="compact-suggested-section section-space">
    <div class="container">
        <div class="section-title-wrapper reveal-on-scroll">
            <span class="editorial-label">Your Journey</span>
            <h2 class="section-title">Recently Viewed</h2>
        </div>
        <div id="recentlyViewedContainer" class="related-compact-grid">
            <!-- Seeded dynamically via Javascript -->
        </div>
        <div id="recentlyViewedEmpty" class="recently-viewed-empty">
            No recently viewed items.
        </div>
    </div>
</section>

<?php /* ══ Sticky buy bar (phones and tablets) ═══════════════════════════════
         Even after 400px of repetition came off the top, Add to Bag lands at
         1,420px on a 390px phone — the gallery and the size ladder are simply
         that tall, and shrinking a fashion photograph to raise a button is the
         wrong trade. So the button follows instead.

         It replaces the bottom dock rather than stacking on it: two fixed bars
         would take 121px of an 844px screen, and on a product page the primary
         action is to buy, not to navigate — Home, Search and Login are all still
         in the header. The swap is done in JS (see initPdpBuyBar), so if the
         script never runs the shopper keeps the dock and loses nothing.

         Deliberately OUTSIDE every .reveal-on-scroll section: those animate with
         a transform, and a transformed ancestor makes position:fixed resolve
         against the ancestor instead of the viewport, which would strand this at
         a fixed point in the page.

         The button calls the same handleAddToCartClick() as the real one, so
         size validation, stock checks, the toast and the drawer are identical —
         there is no second add-to-cart path to keep in step. */ ?>
<div class="pdp-buy-bar" id="pdpBuyBar" hidden>
    <div class="pdp-buy-bar-price">
        <span class="pdp-buy-bar-price-label">Total</span>
        <span class="pdp-buy-bar-price-amount" id="pdpBuyBarPrice"><?= formatPrice($openPrice ?? $product['price']) ?></span>
    </div>
    <button type="button" class="btn-luxury pdp-buy-bar-btn" onclick="handleAddToCartClick()">
        <i class="fa-solid fa-bag-shopping" aria-hidden="true"></i> Add to Bag
    </button>
</div>

<script>
    // Selected variant info
    let hasVariants = <?= !empty($variants) ? 'true' : 'false' ?>;
    let selectedVariantId = 0;
    let selectedVariantName = '';
    let selectedVariantPrice = <?= (float)$product['price'] ?>;
    let selectedVariantMrp = <?= (float)$mrpPrice ?>;

    function copyCouponCode(code, btnEl) {
        const done = () => {
            const icon = btnEl.querySelector('i');
            if (icon) {
                icon.className = 'fa-solid fa-check';
                setTimeout(() => { icon.className = 'fa-regular fa-copy'; }, 1500);
            }
            showToast('Copied!', 'Coupon code ' + code + ' copied to clipboard.');
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(code).then(done).catch(() => {
                showToast('Notice', 'Could not copy automatically — code: ' + code);
            });
        } else {
            showToast('Notice', 'Coupon code: ' + code);
        }
    }

    // ── Colour + colour-scoped size data (Biba-style selectors) ──────
    const DIEVON_DEFAULT_IMAGES = galleryImagesList.slice();
    <?php // A colourway with no sizes of its own falls back to the product's own
          // sizes, rather than leaving the shopper nothing to choose.
          //
          // Sizes can be attached either to a colourway or to the product as a
          // whole (color_id NULL). If a product has both a colourway and only
          // product-level sizes, this branch found an empty list and the page
          // rendered no size buttons at all — an item advertised as in stock in
          // the grid, in Quick View, in the JSON-LD and in the Google feed, which
          // simply could not be bought. One colourway added to an existing
          // product was enough to do it, with nothing on screen to explain why.
          //
          // $variants carries the same id/size_code/name/stock_qty keys, so the
          // shape below is unchanged. ?>
    const DIEVON_COLORS = <?= json_encode(array_map(function($c) use ($product, $variants, $dvAllowVariantOverrides) {
        $colourSizes = $c['sizes'] ?: array_values(array_filter(
            $variants,
            fn($v) => empty($v['color_id']) && trim((string)($v['size_code'] ?? '')) !== ''
        ));
        return [
            'id' => (int)$c['id'],
            'color_name' => $c['color_name'],
            'sku' => $c['sku'],
            'price' => $c['effective_price'],
            'mrp' => $c['effective_mrp'],
            /* Each colour photograph travels with its WebP twin.
               ────────────────────────────────────────────────────────────────
               The server-rendered gallery above wraps every picture in
               <picture> with a WebP <source> (webpUrlIfExists, line 747). The
               colour swap did not — it wrote a bare <img> pointing at the raw
               file. So the page loaded light, and then clicking a colour
               swapped every image for the FULL-SIZE original.
               On this shop's own files that is roughly 200KB against 2.3MB per
               photograph: choosing a colour made the page several times heavier
               than it was on arrival, which is the opposite of what a swatch
               should cost. */
            'images' => array_map(fn($img) => [
                'src'  => SITE_URL . '/uploads/products/' . $img['image'],
                'webp' => webpUrlIfExists('products', $img['image']) ?: null,
            ], $c['images']),
            /* stock stays NULL when the size is not counted.
               Casting it to int turned "not tracked" into 0, and the renderer
               below reads 0 as sold out — so an uncounted colour-size would be
               drawn disabled and labelled "Out of stock" while the plain-size
               list on the same page left the identical case buyable, and
               checkout would have accepted it. Two renderers, one product, two
               answers. NULL travels through and the renderer decides. */
            /* Each size carries the price the CART will charge for it.
               ────────────────────────────────────────────────────────────────
               This field did not exist, and renderSizeButtons() below therefore
               drew pills that could not tell selectProductSizeCode() a price —
               so choosing a size on a product sold by colour left the heading
               showing the COLOUR's price for ever, while actions/cart_action.php
               (line 192) priced the very same row through effectiveVariantPrice(),
               which prefers a size's own price. Page and bag disagreed with
               nothing on screen admitting it: the plain-size ladder a few
               hundred lines up has passed its price since it was written, and
               only the colour-scoped renderer was missed.

               Resolved by CALLING effectiveVariantPrice() with this size AND its
               colour, so the precedence that decides it — colour override, then
               size price, then product price, then the sale clamp — is read from
               the one function the cart, checkout and Quick View all read it
               from, rather than restated here in a form that could drift.

               Abroad the overrides do not apply at all: product_country_prices
               is a flat per-product figure with no size or colour granularity,
               so the guard mirrors $col['effective_price'] on line 229 exactly
               rather than inventing a second rule for the same question. */
            'sizes' => array_map(fn($s) => [
                'id'        => (int)$s['id'],
                'size_code' => $s['size_code'],
                'label'     => $s['name'],
                'stock'     => $s['stock_qty'] === null ? null : (int)$s['stock_qty'],
                'price'     => $dvAllowVariantOverrides
                    ? effectiveVariantPrice($product, $s, $c)
                    : (float)$c['effective_price'],
            ], $colourSizes),
        ];
    }, $productColors)) ?>;
    const DIEVON_SIZE_LADDER = <?= json_encode(array_map(fn($s) => ['code' => $s['code'], 'label' => $s['label']], $sizeLadder)) ?>;
    const DIEVON_PRODUCT_EMOJI = <?= json_encode($product['emoji'] ?? '👗') ?>;
    const DIEVON_PRODUCT_BADGE = <?= json_encode($product['badge'] ?? '') ?>;
    const DIEVON_PRODUCT_NAME = <?= json_encode($product['name']) ?>;
    // The first colour is the opening state, whether or not a selector is drawn.
    // A single-colour product hides the swatches but still uses that colour's
    // images, sizes and price — hiding the control must not mean ignoring it.
    let selectedColorId = DIEVON_COLORS.length ? DIEVON_COLORS[0].id : 0;
    let selectedColorName = DIEVON_COLORS.length ? DIEVON_COLORS[0].color_name : '';

    function initColorSizeSelector() {
        if (!DIEVON_COLORS.length) return;

        // The gallery opens on the SELECTED colour's photographs.
        //
        // The grid is rendered server-side from the product's own images, while
        // the price and the size ladder came from colour #1 — so the page opened
        // showing one colourway's photographs beside another's sizes and price.
        // Most visible on a single-colour product, where there is no swatch to
        // click and therefore nothing that would ever have corrected it: the
        // shopper saw the product-level shots for a garment sold only in red.
        //
        // selectProductColor() already does the whole job (gallery, price, sizes,
        // selected state) and tolerates a null button, so the opening state is
        // just that same call rather than a second copy of the logic.
        selectProductColor(DIEVON_COLORS[0].id, document.querySelector('.color-swatch-btn'));
    }

    function findColor(colorId) {
        return DIEVON_COLORS.find(c => c.id === colorId);
    }

    function selectProductColor(colorId, btnEl) {
        const color = findColor(colorId);
        if (!color) return;

        selectedColorId = colorId;
        selectedColorName = color.color_name;

        document.querySelectorAll('.color-swatch-btn').forEach(b => {
            b.classList.remove('color-swatch-selected');
            b.setAttribute('aria-checked', 'false');
        });
        if (btnEl) { btnEl.classList.add('color-swatch-selected'); btnEl.setAttribute('aria-checked', 'true'); }

        const nameLabel = document.getElementById('selectedColorNameLabel');
        if (nameLabel) nameLabel.textContent = color.color_name;

        /* Normalised to {src, webp} whichever source they came from.
           ────────────────────────────────────────────────────────────────────
           A colour now carries objects with its WebP twin; DIEVON_DEFAULT_IMAGES
           is still a list of plain URL strings, and the lightbox below indexes
           galleryImagesList by string. Both shapes are folded into one here so
           nothing downstream has to know which it got. */
        const rawImgs = (color.images && color.images.length) ? color.images : DIEVON_DEFAULT_IMAGES;
        const imgsToDisplay = rawImgs.map(x =>
            (typeof x === 'string') ? { src: x, webp: null } : { src: x.src, webp: x.webp || null }
        );
        if (imgsToDisplay.length) {
            let videoHtml = '';
            <?php if ($hasVideo): ?>
                videoHtml = `
                <div class="gallery-grid-item video-grid-item">
                    <?php 
                    $vUrl = trim($product['video_url']);
                    if (strpos($vUrl, 'youtube.com') !== false || strpos($vUrl, 'youtu.be') !== false):
                        preg_match('/(?:v=|\/)([a-zA-Z0-9_-]{11})/', $vUrl, $m);
                        $ytId = $m[1] ?? '';
                    ?>
                        <iframe class="gallery-grid-video" src="https://www.youtube-nocookie.com/embed/<?= $ytId ?>?autoplay=0&mute=1&loop=1&playlist=<?= $ytId ?>" frameborder="0" allow="encrypted-media" allowfullscreen></iframe>
                    <?php else: ?>
                        <?php /* Shared resolver: also upgrades a plain http:// address on an
                                 https page, which the browser would otherwise block as mixed
                                 content and report as an unsupported format. */ ?>
                        <?php $videoSrc = dievonVideoSrc($vUrl); ?>
                        <video class="gallery-grid-video" src="<?= htmlspecialchars($videoSrc) ?>" autoplay loop muted playsinline controls></video>
                    <?php endif; ?>
                </div>`;
            <?php endif; ?>

            const grid = document.getElementById('productGalleryGrid');
            if (grid) {
                const badgeHtml = DIEVON_PRODUCT_BADGE ? `<span class="badge-luxury badge-product-page">${escHtml(DIEVON_PRODUCT_BADGE)}</span>` : '';
                /* Fetch the pictures BEFORE putting them on the page.
                   ────────────────────────────────────────────────────────────
                   This waited a flat 200ms and only then wrote the <img> tags,
                   so the browser did not even begin downloading until the delay
                   was over — the wait and the download ran one after the other
                   instead of together. In the gap each <img> had a src it had
                   not loaded yet, and a not-yet-loaded image renders its ALT
                   text, which is why "Ivory Gold - Image 1" appears where the
                   photograph should be.

                   Preloading into the cache first means the tags are written
                   only once the files are actually there, so they paint at once
                   and no alt text is ever shown. The fade covers the fetch
                   rather than being served before it.

                   Capped at 1.2s: a slow or missing image must not leave the
                   gallery stuck on the previous colour. */
                grid.classList.add('is-swapping');

                // Preload what will actually be DISPLAYED — the WebP where there
                // is one. Preloading the original then showing the WebP would
                // fetch both and warm the wrong one.
                const preload = url => new Promise(resolve => {
                    const im = new Image();
                    im.onload = im.onerror = resolve;
                    im.src = url;
                });
                const ready = Promise.all(imgsToDisplay.map(x => preload(x.webp || x.src)));
                const capped = Promise.race([
                    ready,
                    new Promise(resolve => setTimeout(resolve, 1200))
                ]);

                capped.then(() => {
                    /* Give the DOM back before overwriting it. When Owl is
                       driving (phones), the gallery's children are its stage,
                       not the tiles — writing innerHTML straight over that
                       leaves Owl's instance data attached to a node whose
                       contents it no longer owns, and the carousel freezes on
                       the colour that was showing. No-op on desktop. */
                    if (window.dievonGalleryOwl) { window.dievonGalleryOwl.teardown(); }
                    // <picture> with the WebP source, matching how the same
                    // gallery is rendered server-side at line 748.
                    grid.innerHTML = imgsToDisplay.map((im, i) => `
                        <div class="gallery-grid-item" role="button" tabindex="0"
                             aria-label="View larger image"
                             onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}"
                             onclick="openProductLightbox('${im.src}')">
                            ${i === 0 ? badgeHtml : ''}
                            <picture>
                                ${im.webp ? `<source srcset="${im.webp}" type="image/webp">` : ''}
                                <img src="${im.webp || im.src}" alt="${escHtml(color.color_name)} - Image ${i + 1}" class="gallery-grid-img">
                            </picture>
                        </div>`).join('') + videoHtml;
                    /* Commit the hidden state before revealing it.
                       ────────────────────────────────────────────────────────
                       Writing innerHTML and dropping the class in the same tick
                       lets the browser collapse both into one style pass, and a
                       transition with no starting frame does not run — the new
                       pictures appear instantly instead of fading. Reading
                       offsetHeight forces the layout to settle first, so the
                       fade back in happens every time rather than sometimes. */
                    void grid.offsetHeight;
                    grid.classList.remove('is-swapping');
                    grid.scrollLeft = 0;
                    initGallerySlideCounter();
                    // Rebuild the carousel around the new colour's photographs.
                    if (window.dievonGalleryOwl) { window.dievonGalleryOwl.sync(); }
                    // The tiles are new elements, so the parallax has to let go
                    // of the ones it measured and pick these up instead.
                    document.dispatchEvent(new CustomEvent('dievon:gallery-swapped'));
                });
            }

            // Keep the lightbox in sync — full-size originals, not the WebP.
            // The lightbox is where someone looks closely, and it indexes this
            // list by string, which is the shape it has always been given.
            galleryImagesList = imgsToDisplay.map(im => im.src);
            currentGalleryIndex = 0;
        }

        // Reset size selection — sizes differ per colour
        selectedVariantId = 0;
        selectedVariantName = '';
        selectedVariantPrice = color.price;
        selectedVariantMrp = color.mrp;
        updatePriceDisplay();
        renderSizeButtons(colorId);

        const msg = document.getElementById('sizeSelectValidationMsg');
        if (msg) msg.style.display = 'none';
        const wrap = document.getElementById('colorSizeSelectorWrap');
        if (wrap) wrap.style.border = '';
    }

    function renderSizeButtons(colorId) {
        const grid = document.getElementById('sizeLadderGrid');
        if (!grid) return;
        const color = findColor(colorId);
        if (!color) { grid.innerHTML = ''; return; }

        let html = '';
        DIEVON_SIZE_LADDER.forEach(rung => {
            const size = color.sizes.find(s => s.size_code === rung.code);
            if (!size) return; // only show sizes actually configured for this colour
            // null = this size is not counted, which is not the same as none
            // left. Matches the plain-size list rendered server-side, and the
            // NULL rule checkout enforces.
            const tracked    = size.stock !== null && size.stock !== undefined;
            const outOfStock = tracked && size.stock <= 0;
            const lowStock   = tracked && size.stock > 0 && size.stock <= 5;
            // The figure the bag will charge for THIS size, resolved server-side
            // through effectiveVariantPrice(). A colour whose sizes are all priced
            // like the product sends its price back unchanged, so the heading does
            // not move — which is the correct outcome, not a missing one. The
            // fallback covers a payload cached from before this field existed.
            // null is checked explicitly: Number(null) is 0, and 0 is finite, so a
            // null would price the size at nothing rather than falling back.
            const sizePrice = (size.price !== null && size.price !== undefined && Number.isFinite(Number(size.price)))
                ? Number(size.price)
                : color.price;
              /* A price on the pill ONLY where this size costs something other than the
                 rest of its colour.
                 ────────────────────────────────────────────────────────────────────
                 Measured across this catalogue: 1 of 42 variants is priced differently.
                 Printing a figure on all 42 would add forty-one labels repeating what
                 the heading already says, and bury the one that matters — an exception
                 stops being visible when everything around it says the same thing.
                 Compared against the COLOUR price, not the product price: a colourway
                 override is what the other sizes here actually sell at, so measuring
                 against the product would light up every pill on an overridden colour. */
              const pillFmt = typeof formatPriceJS === 'function'
                  ? formatPriceJS
                  : (v => '<?= currencySymbol() ?>' + Number(v).toLocaleString('en-IN'));
              const priceDiffers = Number.isFinite(Number(color.price))
                  && Math.abs(Number(sizePrice) - Number(color.price)) > 0.009;
              const pillPrice = priceDiffers
                  ? '<span class="size-pill-price">' + pillFmt(sizePrice) + '</span>'
                  : '';
            html += `
                <button type="button" class="size-pill-btn ${outOfStock ? 'size-pill-disabled' : ''} ${priceDiffers ? 'size-pill-has-price' : ''}"
                        ${outOfStock ? 'disabled aria-disabled="true"' : ''}
                        aria-pressed="false"
                        onclick="selectProductSizeCode(${size.id}, '${escHtml(size.label)}', ${sizePrice}, this)">
                    ${escHtml(size.label)}
                      ${pillPrice}
                    ${outOfStock ? '<span class="size-pill-low-stock">Out of stock</span>' : (lowStock ? `<span class="size-pill-low-stock">Only ${size.stock} left</span>` : '')}
                </button>`;
        });
        grid.innerHTML = html || '<p class="size-guide-empty-msg">No sizes configured for this colour yet.</p>';
    }

    function selectProductSizeCode(variantId, label, price, element) {
        selectedVariantId = variantId;
        selectedVariantName = label;

        // The heading follows the chosen size, exactly as it does on the
        // plain-size ladder via selectProductVariant(). Without this the price
        // stayed on the colour's figure while the bag charged the size's own —
        // see the 'price' field in DIEVON_COLORS for the full account.
        //
        // selectedVariantMrp is deliberately NOT touched: "was" is a property of
        // the product (or the colourway that overrides it), not of a size, so a
        // dearer size correctly shrinks the discount badge against the same MRP
        // instead of inventing a new one to keep the percentage flattering.
        if (price !== null && price !== undefined && Number.isFinite(Number(price))) {
            selectedVariantPrice = Number(price);
            updatePriceDisplay();
        }

        // aria-pressed has to move with the class. Which size is chosen is shown
        // to a sighted user purely by .size-pill-selected restyling the pill —
        // there is no text change — so without this a screen reader user gets no
        // confirmation that their choice registered, on any pill.
        document.querySelectorAll('#sizeLadderGrid .size-pill-btn').forEach(b => {
            b.classList.remove('size-pill-selected');
            b.setAttribute('aria-pressed', 'false');
        });
        if (element) {
            element.classList.add('size-pill-selected');
            element.setAttribute('aria-pressed', 'true');
        }

        const msg = document.getElementById('sizeSelectValidationMsg');
        if (msg) msg.style.display = 'none';
        const wrap = document.getElementById('colorSizeSelectorWrap');
        if (wrap) wrap.style.border = '';
    }

    function escHtml(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ── Mobile gallery swipe-slide counter ────────────────────────────
    // Below 768px the gallery becomes a one-photo-at-a-time horizontal
    // scroll-snap slider (see responsive CSS); this just reflects which
    // slide is currently in view as a row of dots, and rebuilds itself
    // whenever the photo set changes (e.g. switching colour).
    function initGallerySlideCounter() {
        const grid = document.getElementById('productGalleryGrid');
        const counter = document.getElementById('gallerySlideCounter');
        const wrap = document.getElementById('galleryWrap');
        if (!grid || !counter) return;
        const slides = grid.querySelectorAll('.gallery-grid-item');
        if (slides.length <= 1) {
            counter.innerHTML = '';
            if (wrap) wrap.classList.remove('has-multiple');
            return;
        }
        if (wrap) wrap.classList.add('has-multiple');
        counter.innerHTML = Array.from(slides).map((_, i) => `<span class="dot${i === 0 ? ' active' : ''}"></span>`).join('');
    }

    /* How far it is from one slide to the next, in pixels.
       ────────────────────────────────────────────────────────────────────
       This used to be assumed to be grid.clientWidth, which was true only
       while a slide filled the viewport exactly. It no longer does: a slide
       stops 84px short so the next photograph peeks in (see the responsive
       CSS), so the pitch is ~290px where clientWidth is 366px.
       Dividing by the wrong one silently lit dot 3 on slide 4 and dot 4 on
       slide 5 — the error accumulates, so it looked fine for the first few
       photographs and only went wrong further along.
       Measured off two real slides rather than recomputed from the peek
       constant, so the CSS stays the only place the peek is tuned. */
    function gallerySlidePitch(grid) {
        const slides = grid.querySelectorAll('.gallery-grid-item');
        if (slides.length > 1) {
            const pitch = slides[1].offsetLeft - slides[0].offsetLeft;
            // Guard the desktop grid, where slide 2 sits on the next row and
            // the difference is 0 or negative.
            if (pitch > 0) return pitch;
        }
        return (slides[0] && slides[0].getBoundingClientRect().width) || grid.clientWidth;
    }

    function navGallerySlide(direction) {
        const grid = document.getElementById('productGalleryGrid');
        if (!grid || !grid.clientWidth) return;
        grid.scrollBy({ left: direction * gallerySlidePitch(grid), behavior: 'smooth' });
    }
    (function () {
        const grid = document.getElementById('productGalleryGrid');
        const counter = document.getElementById('gallerySlideCounter');
        if (!grid || !counter) return;
        initGallerySlideCounter();
        let scrollTimer = null;
        grid.addEventListener('scroll', function () {
            clearTimeout(scrollTimer);
            scrollTimer = setTimeout(() => {
                const dots = counter.querySelectorAll('.dot');
                if (!dots.length || !grid.clientWidth) return;
                const pitch = gallerySlidePitch(grid);
                if (!pitch) return;
                const idx = Math.max(0, Math.min(dots.length - 1, Math.round(grid.scrollLeft / pitch)));
                dots.forEach((d, i) => d.classList.toggle('active', i === idx));
            }, 60);
        }, { passive: true });
    })();

    /* ── Owl Carousel on phones ───────────────────────────────────────
       Owl is vendored and loaded site-wide (includes/header.php) and every
       other rail in the shop rides it. The product gallery was the last
       hand-rolled slider, so the one place a shopper looks hardest behaved
       differently under the finger from everywhere else.

       Owl takes over below 768px only. Above it the gallery is a
       two-column grid rather than a slider, so the carousel is destroyed
       outright — restyling it would leave Owl's stage wrapper in the DOM
       and the grid measuring against a transformed parent.

       The scroll-snap rail underneath is left fully intact and is not a
       dead branch: if jQuery or the plugin fails to arrive, .is-owl never
       lands, the CSS keeps the native slider in charge, and the gallery
       still swipes. This is an enhancement over a working gallery, not a
       replacement for one. */
    var dievonGalleryOwl = (function () {
        /* Must match the peek in the responsive CSS. It is repeated here
           because Owl's autoWidth needs a pixel width BEFORE it measures,
           and reading the computed flex-basis off a slide the CSS has
           already stopped applying (Owl reparents them) returns nothing
           useful. Change one, change the other. */
        var PEEK = 84;
        var mq = window.matchMedia('(max-width: 768px)');
        var active = false;

        function grid()  { return document.getElementById('productGalleryGrid'); }
        function wrap()  { return document.getElementById('galleryWrap'); }
        function ready() { return !!window.jQuery && typeof jQuery.fn.owlCarousel === 'function'; }

        /* "3 / 7". Blanked rather than shown as "1 / 1" for a single
           photograph — a counter that can only ever read one is furniture. */
        function setFrameCount(index, total) {
            var el = document.getElementById('galleryFrameCount');
            if (!el) { return; }
            el.textContent = (total > 1) ? ((index + 1) + ' / ' + total) : '';
        }

        /* Owl's autoWidth reads each slide's own width, so it has to be a
           real number before init. Measured off the wrap rather than the
           grid: the grid is the element Owl restructures, and once it holds
           a stage its clientWidth no longer describes the screen. */
        function sizeSlides(g) {
            var host = wrap() || g;
            var w = host.clientWidth;
            if (!w) { return 0; }
            var slide = Math.max(120, Math.round(w - PEEK));
            g.querySelectorAll('.gallery-grid-item').forEach(function (el) {
                el.style.width = slide + 'px';
            });
            return slide;
        }

        /* The arrows and dots are Owl's own, so the labels the hand-rolled
           controls carried have to be put back on them — Owl ships bare
           buttons. The gallery IS the product on a clothing shop; leaving
           its controls unnamed to a screen reader would undo the WCAG
           retrofit the markup above was written for. */
        function labelControls(g) {
            var nav = g.querySelectorAll('.owl-nav button');
            if (nav[0]) { nav[0].setAttribute('aria-label', 'Previous photo'); }
            if (nav[1]) { nav[1].setAttribute('aria-label', 'Next photo'); }
            g.querySelectorAll('.owl-dots button').forEach(function (d, i) {
                d.setAttribute('aria-label', 'Go to photo ' + (i + 1));
            });
        }

        function build() {
            var g = grid(), wp = wrap();
            if (!g || !wp || active || !ready() || !mq.matches) { return; }
            // One photograph is not a carousel — no stage, no dots, no arrows.
            if (g.querySelectorAll('.gallery-grid-item').length <= 1) { return; }
            if (!sizeSlides(g)) { return; }

            var $g = jQuery(g);
            /* Owl's stylesheet is scoped to .owl-carousel and Owl never adds
               that class itself. Added here rather than in the markup for the
               same reason home.php does it: that stylesheet also hides
               .owl-carousel until .owl-loaded exists, so in the HTML it would
               blank the gallery until the script ran — and permanently if the
               library never arrived. */
            $g.addClass('owl-carousel');
            /* A slide is a picture, and pictures are draggable objects by
               default — the browser's own drag-and-drop eats the pointer
               events Owl needs and the gallery simply will not move. Firefox
               ignores the CSS property, so the attribute has to say it too. */
            $g.find('img, a').attr('draggable', 'false');

            $g.owlCarousel({
                /* autoWidth, not items:1 + stagePadding. stagePadding insets
                   BOTH edges, which would gutter the first photograph on the
                   left by however much peek was wanted on the right. The
                   reference is a photograph starting at the page inset with
                   the next one bleeding off the right edge, and only a real
                   per-slide width gives that. */
                autoWidth: true,
                /* Not a sizing instruction — autoWidth already decides that
                   from each slide's measured width. This is here because Owl
                   decides whether the controls are needed at all with
                   `items().length <= settings.items`, and `items` defaults to
                   THREE. Left unset, every product whose colour has three or
                   fewer photographs got .disabled on the whole nav and dot
                   strip, and the arrows and dots simply were not there — on a
                   gallery that still had two or three photographs to show. It
                   only looked right on the five-photo product it was built
                   against. At 1 the controls stand down for a single
                   photograph and in no other case. */
                items: 1,
                margin: 8,
                nav: true,          // Owl's own .owl-nav, styled in style.css
                dots: true,         // Owl's own .owl-dots, below the photograph
                loop: false,        // a product's photographs end; they do not wrap
                slideBy: 1,
                mouseDrag: true,
                touchDrag: true,
                freeDrag: false,
                smartSpeed: 400,
                autoHeight: false,
                navText: [
                    '<i class="fa-solid fa-chevron-left" aria-hidden="true"></i>',
                    '<i class="fa-solid fa-chevron-right" aria-hidden="true"></i>'
                ]
            });

            labelControls(g);
            $g.on('refreshed.owl.carousel', function () { labelControls(g); });
            /* Owl reports the index and the total on every settle, which is
               the only place both are known for certain — autoWidth means the
               last page can hold more than one slide, so counting presses
               would drift. */
            $g.on('changed.owl.carousel', function (e) {
                if (e.item) { setFrameCount(e.item.index, e.item.count); }
            });
            setFrameCount(0, g.querySelectorAll('.gallery-grid-item').length);
            wp.classList.add('is-owl');
            active = true;
        }

        /* Hand the DOM back exactly as Owl found it. Called before the
           colour swap rewrites the gallery's innerHTML — writing over a live
           Owl leaves its stage wrapper orphaned and its instance data still
           attached, so the next init silently no-ops and the gallery freezes
           on whichever colour was showing. */
        function teardown() {
            var g = grid(), wp = wrap();
            if (!g) { return; }
            if (ready()) {
                var $g = jQuery(g);
                if ($g.data('owl.carousel')) { $g.trigger('destroy.owl.carousel'); }
                $g.removeClass('owl-carousel owl-loaded owl-drag owl-grab owl-rtl owl-hidden');
            }
            g.querySelectorAll('.gallery-grid-item').forEach(function (el) {
                el.style.width = '';
            });
            if (wp) { wp.classList.remove('is-owl'); }
            // Otherwise a stale "2 / 5" is left behind for the colour that
            // was showing, and reappears the moment a carousel is rebuilt.
            setFrameCount(0, 0);
            active = false;
        }

        // The single entry point: decides from the viewport whether the
        // gallery should be an Owl carousel right now, and makes it so.
        function sync() {
            if (mq.matches) {
                if (!active) { build(); }
                else { sizeSlides(grid()); jQuery(grid()).trigger('refresh.owl.carousel'); }
            } else if (active) {
                teardown();
            }
        }

        var resizeTimer = null;
        window.addEventListener('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(sync, 150);
        });
        if (mq.addEventListener) { mq.addEventListener('change', sync); }
        else if (mq.addListener) { mq.addListener(sync); }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', sync);
        } else {
            sync();
        }
        /* Owl and jQuery are deferred, and a deferred script that is still
           being fetched has not defined jQuery.fn.owlCarousel by
           DOMContentLoaded. One more attempt on load costs nothing and is
           the difference between a carousel and a plain rail on a slow
           connection. */
        window.addEventListener('load', function () { if (!active) { sync(); } });

        return { build: build, teardown: teardown, sync: sync,
                 isActive: function () { return active; } };
    })();
    window.dievonGalleryOwl = dievonGalleryOwl;

    // ── Sticky gallery "lift" effect (desktop only) ──────────────────
    // Detects when .product-image-section is actually in its pinned/stuck
    // state (vs. just scrolling normally) and toggles a class for a subtle
    // shadow — confirms the sticky behaviour visually instead of leaving it
    // as an invisible layout detail.
    // The previous implementation observed .sticky-sentinel with a negative rootMargin.
    // That could not work: the sentinel is position:absolute INSIDE the sticky element,
    // so it pins along with it and always sits exactly at the sticky offset — leaving a
    // sub-pixel intersection edge case. It also cached the rootMargin from the 90px
    // fallback, because --header-height is only set later (syncHeaderPadding() in
    // includes/header.php runs on DOMContentLoaded). Comparing the element's own top
    // against the offset is exact and has no ordering dependency.
    (function () {
        const section = document.getElementById('productImageSection');
        if (!section) return;

        // Must match .product-image-section's `top` in style.css. It read +16
        // while the CSS said +8, so the class flipped 8px away from where the
        // gallery actually pins.
        const STICK_GAP = 8;
        function stickyOffset() {
            const raw = getComputedStyle(document.documentElement).getPropertyValue('--header-height').trim();
            return (parseFloat(raw) || 90) + STICK_GAP;
        }

        /* The gentle drift.
           ────────────────────────────────────────────────────────────────
           Each photograph drifts slowly INSIDE its own frame while the page
           scrolls, the two columns at slightly different speeds. The frames
           themselves never move, so nothing in the layout shifts — only what
           is seen through them. It is the depth an editorial spread has, and
           it is the one stretch of this page where the gallery would otherwise
           be completely motionless.

           This replaces a 14px lift of the whole grid that was driven by
           `offset - top`. A sticky element's top is CLAMPED at its offset, so
           that value stayed 0 for the entire pinned stretch and only grew once
           the gallery had begun scrolling out of view — the effect played
           where nobody was looking at it, and never where the comment claimed.
           Sticky progress is measured off the parent block below instead,
           which is the thing that actually keeps moving. */
        const grid  = section.querySelector('.product-gallery-grid');
        const track = section.parentElement;   // the block the gallery is pinned within

        /* How far a tile may drift, in pixels.
           Kept well inside the grid's 12px gap so two tiles moving opposite
           ways can never appear to touch. Fixed pixels rather than a fraction
           of the tile, because the constraint is the gap, which does not grow
           with the photograph. */
        const PAR_TRAVEL = 5;        // px, each direction
        /* Equal and opposite.
           ────────────────────────────────────────────────────────────────
           These were 1 and -0.55, taking "vary the speed per layer" from the
           parallax guidance — but that rule is about a background layer
           trailing a foreground one, which is not what two tiles side by side
           in a grid are. At equal size and equal depth, one column travelling
           nearly twice as far as the other does not read as depth; it reads as
           the first column slipping. Matched amounts read as the pair opening
           and closing together, which is a decision rather than a fault. */
        const COL_SPEED  = [1, -1];

        const stillMotion = window.matchMedia
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        /* Re-read after a colour swap replaces the tiles.
           ────────────────────────────────────────────────────────────────
           isConnected is the test that matters, not the tile count. A colour
           swap rewrites the gallery's innerHTML, and the replacement usually
           has exactly as many photographs as the one before it — so a count
           check sees 2 and 2 and holds on to the old, now-detached elements,
           quietly writing the parallax to nodes that are no longer on the
           page. The count is still worth checking for the case where the new
           colour genuinely has a different number of shots. */
        let frames = [], framesFor = -1;
        function galleryFrames() {
            if (!grid) { return []; }
            const stale = grid.childElementCount !== framesFor
                       || (frames.length > 0 && !frames[0].isConnected);
            if (stale) {
                frames = Array.from(grid.querySelectorAll('.gallery-grid-item'));
                framesFor = grid.childElementCount;
            }
            return frames;
        }

        /* Take every trace of the effect back off.
           Used on narrow screens and for reduced motion — leaving a stale
           --dv-par behind would hold a tile a few pixels out of place with
           nothing left running to correct it. */
        function clearParallax() {
            grid.classList.remove('dv-parallax-on');
            galleryFrames().forEach(f => {
                f.style.removeProperty('--dv-par');
                f.style.willChange = '';
            });
        }

        let settleTimer = null;
        function releaseWillChange() {
            // The skill's own performance note: will-change costs GPU memory for
            // as long as it is set, so it is granted for the scroll and taken
            // back once the page is still again.
            galleryFrames().forEach(f => { f.style.willChange = ''; });
        }

        function update() {
            const narrow = window.innerWidth < 992;
            const offset = stickyOffset();
            const rect   = section.getBoundingClientRect();

            /* is-stuck now also requires the gallery to still be on screen.
               The old test was `top <= offset + 1` alone, which stays true
               forever once passed — so the pinned shadow was never taken off
               again, including while the gallery sat far above the viewport. */
            section.classList.toggle('is-stuck', !narrow && rect.top <= offset + 1 && rect.bottom > offset);

            if (!grid) { return; }

            /* Nothing at all below 992px, restoring the guard the original had.
               Down there the gallery is a horizontal slider rather than a
               two-column grid: there is no sticky progress to drive the drift,
               and a vertical transform would only fight the swipe. */
            if (narrow || stillMotion) { clearParallax(); return; }
            grid.classList.add('dv-parallax-on');

            /* Progress through the pinned stretch, 0 → 1.
               Measured from the parent block: it starts when the parent's top
               reaches the sticky offset and ends when its bottom can no longer
               hold the gallery there. Continuous, so there is no jump at the
               moment of pinning, and it still sweeps 0 → 1 on narrow screens
               where nothing is sticky at all. */
            const trackRect = track.getBoundingClientRect();
            const span = trackRect.height - rect.height;
            const progress = span > 0
                ? Math.min(1, Math.max(0, (offset - trackRect.top) / span))
                : 0;

            galleryFrames().forEach((frame, i) => {
                const speed = COL_SPEED[i % COL_SPEED.length];
                /* Starts at zero, not at the middle of the travel.
                   Centring it meant the columns already sat several pixels
                   apart before a single scroll — on a two-tile grid that reads
                   as a misaligned layout rather than as an effect. Aligned on
                   arrival, drifting apart as the page moves. */
                const shift = progress * PAR_TRAVEL * speed;
                frame.style.setProperty('--dv-par', shift.toFixed(2) + 'px');
                frame.style.willChange = 'transform';
            });

            clearTimeout(settleTimer);
            settleTimer = setTimeout(releaseWillChange, 200);
        }

        /* Measured straight in the listener rather than inside
           requestAnimationFrame: rAF is paused in a background or embedded
           webview, which left this doing nothing at all in those. One rect read
           and one style write per scroll event is cheap enough not to defer. */
        function onScroll() { update(); }

        update();
        window.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('resize', onScroll);
        document.addEventListener('DOMContentLoaded', update);
        window.addEventListener('load', update);
        // The colour swap rebuilds the tiles; re-measure once it has painted.
        document.addEventListener('dievon:gallery-swapped', update);
    })();

    /* Open the first accordion — Product Details — on arrival.
       ────────────────────────────────────────────────────────────────────────
       Done from JS rather than by shipping aria-expanded="true" in the markup,
       because "open" here is an inline max-height measured from scrollHeight.
       Hardcoding the attribute would leave max-height at 0: a panel that
       announces itself as expanded to a screen reader while showing nothing,
       which is worse than either state on its own. Reusing
       toggleProductAccordion() means the default-open panel is built by exactly
       the same path as a tapped one — same class, same aria, same icon — so
       there is no second definition of "open" to drift.

       Waits for `load`, not DOMContentLoaded: the measurement is a fixed pixel
       height, and taking it before the webfont swaps means the panel is sized
       for the fallback face and clips its last line once the real font lands.

       Deliberately querySelector on the class rather than naming the panel.
       Product Details only renders when the product HAS specs or components —
       for a piece with neither, this opens whichever panel is genuinely first
       instead of doing nothing. */
    (function openFirstAccordion() {
        /* One panel, not two — and that is a constraint, not a preference.
           toggleProductAccordion() closes every other open panel before opening
           the one it was given, so calling it twice on arrival opened the second
           and closed the first. Opening more than one here would mean changing
           that single-open rule, which changes what every tap does afterwards.
           Left alone deliberately. */
        function openFirst() {
            const first = document.querySelector('.product-accordion .product-accordion-header');
            // Respect a shopper who already opened something in the gap between
            // DOM ready and fonts settling.
            if (!first || document.querySelector('.product-accordion-header.open')) { return; }
            toggleProductAccordion(first);
        }
        if (document.readyState === 'complete') { openFirst(); }
        else { window.addEventListener('load', openFirst); }

        // max-height is a fixed pixel value, so a rotate or a font-size change
        // re-wraps the content and leaves the panel clipped or over-tall.
        let t;
        window.addEventListener('resize', () => {
            clearTimeout(t);
            t = setTimeout(() => {
                document.querySelectorAll('.product-accordion-header.open').forEach(h => {
                    const c = h.nextElementSibling;
                    if (c) { c.style.maxHeight = c.scrollHeight + 40 + 'px'; }
                });
            }, 150);
        });
    })();

    /* Sticky buy bar — visible only while the real button is not.
       ────────────────────────────────────────────────────────────────────────
       An IntersectionObserver on the in-page Add to Bag, rather than a scroll
       listener comparing offsets: the page has a colour swap and a description
       toggle that both change the button's position, and offsets cached on load
       would be wrong after either. */
    (function initPdpBuyBar() {
        const bar    = document.getElementById('pdpBuyBar');
        const anchor = document.querySelector('.product-action-buttons-group');
        if (!bar || !anchor || !('IntersectionObserver' in window)) { return; }

        const narrow = window.matchMedia('(max-width: 991px)');

        // Hiding the dock is this script's job, so a browser that never gets
        // here keeps its navigation instead of losing both bars.
        function claimDock() {
            document.body.classList.toggle('has-pdp-buy-bar', narrow.matches);
            if (!narrow.matches) { bar.hidden = true; bar.classList.remove('is-visible'); }
            else { bar.hidden = false; }
        }

        /* The bar follows the PRICE, not just the Add to Bag button.
           ────────────────────────────────────────────────────────────────────
           Anchored on the buttons alone, the bar appeared only after you had
           scrolled past Add to Bag — but the price heading leaves the top of a
           phone screen well before that. Measured on a 375×812 screen: at
           scrollY 780 the price was at −107 (gone), the size pills at 184 and
           Add to Bag at 313 (both on screen), and the bar was hidden. So the one
           moment the shopper is changing the price — picking a size or a colour —
           was the one moment no price was visible anywhere, and checking it meant
           scrolling up, back down to change, and up again.

           Two separate questions, because they have different answers:
             priceGone   → is the heading price off screen? Then the bar must
                           show, so a figure is always somewhere on screen.
             buttonsGone → is the real Add to Bag off screen? Only then may the
                           bar offer its own, or the page shows two Add to Bag
                           buttons at once. That duplication is why the bar was
                           anchored here in the first place; hiding just the
                           button keeps that right while freeing the price. */
        let priceGone = false, buttonsGone = false;
        const priceAnchor = document.getElementById('priceCurrentAmount') || anchor;

        function syncBar() {
            bar.classList.toggle('is-visible', narrow.matches && (priceGone || buttonsGone));
            // Price-only while the real button is still reachable.
            bar.classList.toggle('is-price-only', !buttonsGone);
        }

        new IntersectionObserver(([entry]) => {
            buttonsGone = !entry.isIntersecting;
            syncBar();
        }, { rootMargin: '0px 0px -12px 0px' }).observe(anchor);

        new IntersectionObserver(([entry]) => {
            priceGone = !entry.isIntersecting;
            syncBar();
        }, { rootMargin: '0px 0px -12px 0px' }).observe(priceAnchor);

        // The compare tray pins to the same edge and outranks this bar. Watch its
        // class rather than polling — it is toggled from a click, not a scroll.
        //
        // Deferred to DOMContentLoaded because #compareBar is printed by
        // includes/footer.php, which runs AFTER this script. Looking it up here
        // returned null, the observer was never attached, and the tray covered
        // the buy bar completely — 69px of overlap, measured.
        function watchCompareTray() {
            const cmp = document.getElementById('compareBar');
            if (!cmp) { return; }
            const sync = () => bar.classList.toggle('is-above-compare', cmp.classList.contains('is-visible'));
            new MutationObserver(sync).observe(cmp, { attributes: true, attributeFilter: ['class'] });
            sync();
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', watchCompareTray);
        } else {
            watchCompareTray();
        }

        claimDock();
        narrow.addEventListener('change', claimDock);
    })();

    /* Clamp a long description on a phone.
       ────────────────────────────────────────────────────────────────────────
       Applied here rather than in CSS because it must be conditional on the text
       ACTUALLY overflowing. A one-line description under a permanent "Read more"
       that reveals nothing is worse than no toggle at all, and CSS cannot ask
       whether four lines was enough.
       The clamp is never the default state: if this script does not run, the
       shopper reads the whole description. Failing open is the right way round
       for the copy that sells the garment. */
    (function initDescriptionClamp() {
        const text = document.getElementById('productDescriptionText');
        const btn  = document.getElementById('descReadMoreBtn');
        if (!text || !btn) { return; }

        const label = btn.querySelector('.desc-read-more-label');
        const wide  = window.matchMedia('(min-width: 992px)');   // the two-column layout

        function apply() {
            // Desktop has a pinned gallery beside a scrolling column, so the full
            // text costs nothing there. Undo any clamp on the way up.
            if (wide.matches) {
                text.classList.remove('is-clamped');
                btn.hidden = true;
                return;
            }
            // Measure against the UNCLAMPED element, then decide.
            text.classList.remove('is-clamped');
            const line = parseFloat(getComputedStyle(text).lineHeight) || 25;
            const overflows = text.scrollHeight > line * 4 + 4;   // 4 lines, 4px slack
            if (!overflows) { btn.hidden = true; return; }

            btn.hidden = false;
            if (btn.getAttribute('aria-expanded') !== 'true') {
                text.classList.add('is-clamped');
            }
        }

        btn.addEventListener('click', () => {
            const open = btn.getAttribute('aria-expanded') === 'true';
            btn.setAttribute('aria-expanded', open ? 'false' : 'true');
            text.classList.toggle('is-clamped', open);
            label.textContent = open ? 'Read more' : 'Read less';
            // Collapsing from the bottom of a long description would otherwise
            // leave the shopper looking at whatever the page scrolled to.
            if (open) { text.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
        });

        apply();
        // A rotate changes both the breakpoint and how many lines the text takes.
        wide.addEventListener('change', apply);
        window.addEventListener('resize', apply);
    })();

    document.addEventListener('DOMContentLoaded', () => {
        // Sync wishlist state
        const wishlist = getWishlist();
        const icon = document.getElementById('detailWishlistIcon');
        if (wishlist.indexOf('<?= $productId ?>') > -1) {
            icon.className = 'fa-solid fa-heart wishlist-btn-active';
            icon.closest('.btn-wishlist-action').classList.add('active');
        }

        // Update price display initially
        updatePriceDisplay();
        initColorSizeSelector();

        // Track & Load Recently Viewed
        trackRecentlyViewed();
        loadRecentlyViewed();
    });

    // Both size grids now render .size-pill-btn, so the highlight has to move on
    // that class. .variant-pill-btn is kept in the selector only so any markup
    // still using the old class keeps working.
    function markSizePillSelected(element) {
        document.querySelectorAll('.variant-pill-btn, .size-pill-btn').forEach(p => {
            p.classList.remove('selected-pill', 'size-pill-selected');
            p.setAttribute('aria-pressed', 'false');
        });
        if (element) {
            element.classList.add('size-pill-selected');
            element.setAttribute('aria-pressed', 'true');
        }
        const msg = document.getElementById('sizeSelectValidationMsg');
        if (msg) msg.style.display = 'none';
        const wrap = document.getElementById('colorSizeSelectorWrap');
        if (wrap) wrap.style.border = '';
    }

    function selectProductVariant(vid, vname, price, element) {
        selectedVariantId = vid;
        selectedVariantName = vname;
        selectedVariantPrice = price;
        markSizePillSelected(element);
        updatePriceDisplay();
    }

    function updatePriceDisplay() {
        const fmt = typeof formatPriceJS === 'function' ? formatPriceJS : (v => '<?= currencySymbol() ?>' + parseFloat(v).toFixed(2));
        const curEl = document.getElementById('priceCurrentAmount');
        const mrpEl = document.getElementById('priceMrpAmount');
        const offEl = document.getElementById('priceOffBadge');

        if (curEl) curEl.textContent = fmt(selectedVariantPrice);
        // The sticky bar quotes the same figure through the same resolver, so a
        // size change moves both at once. Written here rather than mirrored from
        // the heading afterwards — reading one element's text to fill another is
        // how the two drift apart the first time either is reformatted.
        const stickyEl = document.getElementById('pdpBuyBarPrice');
        if (stickyEl) stickyEl.textContent = fmt(selectedVariantPrice);

        const hasDiscount = selectedVariantMrp > selectedVariantPrice;
        if (mrpEl) {
            mrpEl.style.display = hasDiscount ? '' : 'none';
            mrpEl.textContent = fmt(selectedVariantMrp);
        }
        if (offEl) {
            if (hasDiscount) {
                const pct = Math.round(((selectedVariantMrp - selectedVariantPrice) / selectedVariantMrp) * 100);
                offEl.textContent = pct + '% OFF';
                offEl.style.display = '';
            } else {
                offEl.style.display = 'none';
            }
        }
    }

    function validateSizeSelection(actionLabel) {
        const hasColors = DIEVON_COLORS.length > 0;

        // A colour can exist WITHOUT sizes — a stole, a scarf, a bag.
        //
        // This demanded a size from every product that had colours, and a
        // colour-only product has no size button to click, so selectedVariantId
        // could never leave 0: the page rendered "No sizes configured for this
        // colour yet" and Add to Bag stayed blocked FOREVER. The product was
        // unbuyable, with nothing on screen explaining why. Requiring a size only
        // when the chosen colour actually has one is the real condition.
        const activeColor = hasColors ? findColor(selectedColorId) : null;
        const colorHasSizes = !!(activeColor && activeColor.sizes && activeColor.sizes.length);

        const needsSelection = hasColors
            ? (colorHasSizes && selectedVariantId === 0)
            : (document.querySelector('.variant-pills-grid') && selectedVariantId === 0 && !selectedVariantName);

        if (!needsSelection) return true;

        const message = hasColors
            ? `Please select a size before ${actionLabel}.`
            : `Please select your garment size before ${actionLabel}.`;
        showToast('⚠️ Select Size', message);

        const wrap = hasColors ? document.getElementById('colorSizeSelectorWrap') : document.querySelector('.product-variants-wrap');
        const inlineMsg = document.getElementById('sizeSelectValidationMsg');
        if (inlineMsg) { inlineMsg.textContent = message; inlineMsg.style.display = 'block'; }
        if (wrap) {
            wrap.scrollIntoView({ behavior: 'smooth', block: 'center' });
            wrap.style.border = '1px dashed var(--color-danger)';
            setTimeout(() => wrap.style.border = '', 2500);
        }
        return false;
    }

    let selectedQuantity = 1;
    const MAX_PRODUCT_QTY = 10;

    function changeProductQuantity(delta) {
        selectedQuantity = Math.max(1, Math.min(MAX_PRODUCT_QTY, selectedQuantity + delta));
        document.getElementById('selectedQtyDisplay').textContent = selectedQuantity;
    }

    function handleAddToCartClick() {
        if (!validateSizeSelection('adding to bag')) return;

        // Add to cart helper in footer
        addToCart(
            <?= $productId ?>,
            selectedVariantId,
            '<?= addslashes($product['name']) ?>',
            '<?= addslashes($product['emoji'] ?? '✨') ?>',
            selectedVariantName,
            selectedVariantPrice,
            // The SIZE argument, not the variant name. Passing selectedVariantName
            // here — which is stored as "Size: M" — is what produced
            // "Size: SIZE: M" in the confirmation. addToCart() now also guards
            // against this, but sending the right value is the actual fix.
            String(selectedVariantName || '').replace(/^\s*size\s*:\s*/i, '').trim(),
            selectedQuantity,
            // The chosen colourway. A colour-scoped SIZE already carries its
            // colour on the variant row, so the server ignores this there; it is
            // the only way a colour-only product can say what was picked.
            selectedColorId
        );
        changeProductQuantity(-MAX_PRODUCT_QTY); // reset stepper back to 1 for the next add
    }

    function handleBuyNowClick() {
        if (!validateSizeSelection('buying')) return;

        let body = 'action=add&product_id=<?= $productId ?>';
        if (selectedVariantId > 0) body += '&variant_id=' + selectedVariantId;
        if (selectedVariantName) body += '&size=' + encodeURIComponent(selectedVariantName);
        if (selectedQuantity > 1) body += '&quantity=' + selectedQuantity;
        // Buy Now bypasses addToCart() entirely, so it needs the colour of its
        // own — without it this path charged the base price for a colourway with
        // its own price and recorded no colour on the order.
        if (selectedColorId > 0) body += '&color_id=' + selectedColorId;

        fetch(getCartApiUrl(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                window.location.href = '<?= SITE_URL ?>/pages/checkout.php';
            } else {
                showToast('⚠️ Notice', data.message || 'Could not process request.');
            }
        })
        .catch(() => {
            window.location.href = '<?= SITE_URL ?>/pages/checkout.php';
        });
    }

    function updatePageCartIndicators(cart) {
        if (!cart || !cart.items) return;
        const currentProductId = <?= (int)$productId ?>;
        const existingItems = cart.items.filter(i => parseInt(i.product_id) === currentProductId);
        const totalQtyInCart = existingItems.reduce((acc, i) => acc + i.quantity, 0);

        /* :not([disabled]) — never relabel a button that is out of action.
           This selected .btn-add-bag and rewrote its innerHTML, which also matched
           the disabled "Sold Out" and "Not Available Here" buttons: the server
           rendered the true state and this painted "Add to Bag" straight over it.
           The button stayed disabled so nothing could be bought, but it read as
           available on a garment that is not. */
        const btn = document.querySelector('.btn-add-bag:not([disabled])');
        const banner = document.getElementById('inBagStatusBanner');

        if (totalQtyInCart > 0) {
            if (btn) {
                btn.innerHTML = `<i class="fa-solid fa-check"></i> In Bag (${totalQtyInCart} in Cart)`;
                btn.classList.add('in-bag-active');
            }
            if (banner) {
                banner.style.display = 'flex';
                banner.innerHTML = `
                    <span><i class="fa-solid fa-circle-check"></i> This piece is already in your shopping bag (${totalQtyInCart} selected).</span>
                    <a href="#" onclick="openCart(); return false;" class="in-bag-view-link">View Cart →</a>
                `;
            }
        } else {
            if (btn) {
                btn.innerHTML = `<i class="fa-solid fa-bag-shopping"></i> Add to Bag`;
                btn.classList.remove('in-bag-active');
            }
            if (banner) {
                banner.style.display = 'none';
            }
        }
    }



    function handleWishlistToggleClick() {
        const icon = document.getElementById('detailWishlistIcon');
        const btn = icon.closest('.btn-wishlist-action');
        const added = toggleWishlist('<?= $productId ?>');
        if (added) {
            icon.className = 'fa-solid fa-heart wishlist-btn-active';
            btn.classList.add('active');
        } else {
            icon.className = 'fa-regular fa-heart';
            btn.classList.remove('active');
        }
    }

    function toggleAccordion(id) {
        const acc = document.getElementById(id);
        const icon = document.getElementById(id + 'Icon');
        const show = acc.style.display === 'none';

        acc.style.display = show ? 'block' : 'none';
        icon.className = show ? 'fa-solid fa-minus' : 'fa-solid fa-plus';
    }

    function closeProductAccordion(headerEl) {
        const content = headerEl.nextElementSibling;
        const icon = headerEl.querySelector('.toggle-icon');
        headerEl.classList.remove('open');
        headerEl.setAttribute('aria-expanded', 'false');
        content.style.maxHeight = '0px';
        content.style.paddingTop = '0px';
        content.style.paddingBottom = '0px';
        icon.classList.remove('fa-minus');
        icon.classList.add('fa-plus');
    }

    function toggleProductAccordion(headerEl) {
        const wasOpen = headerEl.classList.contains('open');

        // Only one accordion may be open at a time — close every other open one first
        document.querySelectorAll('.product-accordion-header.open').forEach(otherHeader => {
            if (otherHeader !== headerEl) closeProductAccordion(otherHeader);
        });

        if (wasOpen) {
            closeProductAccordion(headerEl);
            return;
        }

        const content = headerEl.nextElementSibling;
        const icon = headerEl.querySelector('.toggle-icon');
        headerEl.classList.add('open');
        headerEl.setAttribute('aria-expanded', 'true');
        content.style.maxHeight = content.scrollHeight + 40 + "px"; // Add some buffer for padding
        content.style.paddingTop = '15px';
        content.style.paddingBottom = '15px';
        icon.classList.remove('fa-plus');
        icon.classList.add('fa-minus');
    }

    function switchProductDetailsTab(btnEl, tabKey) {
        const content = btnEl.closest('.product-accordion-content');
        if (!content) return;

        content.querySelectorAll('.product-details-tab-btn').forEach(b => {
            b.classList.remove('active');
            b.setAttribute('aria-selected', 'false');
        });
        btnEl.classList.add('active');
        btnEl.setAttribute('aria-selected', 'true');

        content.querySelectorAll('.product-details-tab-panel').forEach(p => {
            p.style.display = (p.dataset.panel === tabKey) ? '' : 'none';
        });

        // Re-measure the accordion's open height so a taller/shorter panel
        // never gets clipped or leaves a stale gap behind.
        const header = content.previousElementSibling;
        if (header && header.classList.contains('open')) {
            content.style.maxHeight = content.scrollHeight + 40 + 'px';
        }
    }

    function checkDelivery() {
        // Used to approve ANY postcode longer than three characters after a fake
        // 800ms delay — it never asked the server, so it could not know where we
        // actually deliver or accept COD. Now it asks ajax_delivery_check.php,
        // which runs the same rules checkout_action.php enforces.
        const zip = document.getElementById('zipCode').value.trim();
        const status = document.getElementById('deliveryStatus');
        if (!zip) {
            status.style.display = 'block';
            status.style.color = 'var(--color-danger)';
            status.textContent = 'Please enter a valid zip/postal code.';
            return;
        }

        status.style.display = 'block';
        status.style.color = 'var(--color-secondary)';
        status.textContent = 'Checking availability...';

        fetch(window.SITE_URL + '/ajax_delivery_check.php?pin=' + encodeURIComponent(zip))
            .then(r => r.json())
            .then(d => {
                if (!d.ok) {
                    status.style.color = 'var(--color-danger)';
                    status.innerHTML = '<i class="fa-solid fa-xmark-circle"></i> Please enter a valid postcode.';
                    return;
                }
                if (d.zone === 'domestic') {
                    status.style.color = 'var(--color-primary)';
                    status.innerHTML = `<i class="fa-solid fa-check-circle"></i> Delivery available to ${escHtml(zip)}${d.state ? ' &middot; ' + escHtml(d.state) : ''}${d.cod ? ' &middot; COD available' : ''}`;
                } else if (d.deliverable) {
                    status.style.color = 'var(--color-secondary)';
                    status.innerHTML = `<i class="fa-solid fa-truck"></i> International delivery available to ${escHtml(zip)} &middot; COD is not available for international orders.`;
                } else {
                    status.style.color = 'var(--color-danger)';
                    status.innerHTML = '<i class="fa-solid fa-xmark-circle"></i> We could not recognise that postcode. Please check and try again.';
                }
            })
            .catch(() => {
                status.style.color = 'var(--color-danger)';
                status.textContent = 'Could not check availability right now — please try again.';
            });
    }

    function trackRecentlyViewed() {
        <?php /* Not saved at all when this piece is not sold in the shopper's country.
                 $product['price'] above is only replaced with the country price when the
                 product HAS one (line 59). A piece with no price for this country keeps
                 its rupee figure — and this card renders through formatPriceJS(), which
                 stamps on whatever symbol is current. So a ₹180 kurti reappeared in the
                 US as "$180.00": an Indian amount wearing a dollar sign, with no "Not
                 Available Here" badge and a working Wishlist and Quick View on something
                 that cannot be bought. Nothing to remember, so nothing is remembered. */ ?>
        <?php if (!$dvSoldInCountry) { return; } ?>

        // json_encode, not addslashes: addslashes escapes quotes but not a newline,
        // and not a closing script tag typed into a product name — either of which
        // ends the script block early and takes the rest of the page's JavaScript
        // with it. JSON_HEX_TAG below encodes < and > so that cannot happen.
        // The extra fields are what the card below needs to look like every other
        // card on the site — without them it could only ever render a stub.
        const product = <?= json_encode([
            'id'         => (int)$product['id'],
            'name'       => (string)$product['name'],
            'price'      => (float)$product['price'],
            'mrp_price'  => (float)($product['mrp_price'] ?? 0),
            'badge'      => (string)($product['badge'] ?? ''),
            'image'      => (string)($product['image'] ?? ''),
            'image_webp' => !empty($product['image']) ? webpUrlIfExists('products', $product['image']) : null,
            'image_alt'  => productImageAlt($product),
            'category'   => (string)($product['category'] ?? ''),
            'emoji'      => (string)($product['emoji'] ?? '👗'),
            /* The country this price belongs to. A figure saved while browsing India
               means nothing in the US — different price, possibly not sold there at
               all — and localStorage outlives a country switch. */
            'country'    => strtoupper((string)$dvCountryCode),
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        
        let viewed = [];
        try {
            viewed = JSON.parse(localStorage.getItem('dievon_recently_viewed')) || [];
        } catch (e) {}

        // Remove if exists
        viewed = viewed.filter(p => p.id !== product.id);
        // Add to front
        viewed.unshift(product);
        // Keep only last 4
        if (viewed.length > 4) viewed = viewed.slice(0, 4);

        localStorage.setItem('dievon_recently_viewed', JSON.stringify(viewed));
    }

    /* Recently viewed, rendered by the server through the shared card partial.
       ────────────────────────────────────────────────────────────────────────
       This used to build its own card in JavaScript from the snapshot saved in
       localStorage. That copy had drifted from includes/product_card.php: no
       hover photograph, no bag note, and — the one that matters — no sold-out
       state, so a piece that had gone out of stock still appeared here fully
       buyable while the same piece on the shop grid beside it was greyed out.
       It could not have shown otherwise: the snapshot holds a name, a price and
       an image, and availability is not in it.

       Asking the server for the cards fixes that by removing the copy rather
       than by patching it. Three further things fall out of the change:

         * The prune no longer needs the catalogue. It used to embed the id of
           EVERY live product in the page — the whole table, on every product
           view, to decide whether four saved entries still existed. The
           endpoint answers for the four.

         * The saved price is no longer displayed, so the country filter that
           used to hide entries saved elsewhere is gone with it. Those entries
           were being dropped to avoid showing an Indian figure under a dollar
           sign; the server prices every card for the country being browsed now,
           so they can simply be shown.

         * Sold out, discount badges, hover images, WebP and the bag note arrive
           for free, because they are the card's, not this function's. */
    function loadRecentlyViewed() {
        let viewed = [];
        try {
            viewed = JSON.parse(localStorage.getItem('dievon_recently_viewed')) || [];
        } catch (e) {}

        // The piece being looked at is not "recently viewed" from this page.
        const ids = viewed
            .map(p => parseInt(p && p.id, 10))
            .filter(id => id > 0 && id !== <?= (int)$productId ?>);

        const container = document.getElementById('recentlyViewedContainer');
        const empty     = document.getElementById('recentlyViewedEmpty');
        if (!container) { return; }

        if (!ids.length) {
            if (empty) { empty.style.display = 'block'; }
            return;
        }

        fetch(`${window.SITE_URL || ''}/actions/product_cards_action.php?ids=${ids.join(',')}`)
            .then(res => res.json())
            .then(data => {
                if (!data || !data.success) { return; }

                /* Forget a product that no longer exists — but only that.
                   `alive` answers "does this still exist", ignoring
                   availability, which is the distinction this prune has always
                   made: a piece the owner has merely hidden must not be wiped
                   from the visitor's history, or it would never come back when
                   the piece returns. */
                try {
                    /* Only judge the ids that were actually asked about.
                       `alive` answers for the ids in the request, and the
                       request deliberately leaves out the product being viewed
                       — so filtering the whole stored list against it deleted
                       the current piece from the visitor's history on every
                       single page view. Measured: browse four products, and the
                       list held one. */
                    const asked = new Set(ids);
                    const alive = new Set((data.alive || []).map(Number));
                    const kept  = viewed.filter(p => {
                        const id = parseInt(p && p.id, 10);
                        return !asked.has(id) || alive.has(id);
                    });
                    if (kept.length !== viewed.length) {
                        localStorage.setItem('dievon_recently_viewed', JSON.stringify(kept));
                    }
                } catch (e) {}

                const html = (data.html || '').trim();
                if (!html) {
                    if (empty) { empty.style.display = 'block'; }
                    return;
                }
                if (empty) { empty.style.display = 'none'; }
                container.insertAdjacentHTML('beforeend', html);

                // Same scroll-entrance the static grids use, and the same bag
                // note fill the shop does after Load More — these cards were
                // built after the cart was read, so without it "2 in your bag"
                // would be missing from them alone.
                if (typeof dievonObserveReveal === 'function') {
                    container.querySelectorAll('.reveal-on-scroll').forEach(el => dievonObserveReveal(el));
                }
                if (typeof syncBagNotes === 'function') { syncBagNotes(); }
            })
            .catch(() => { /* the strip simply stays empty */ });
    }

    // ── Dievon Size Guide Modal ────────────────────────────────────
    const DIEVON_SG_UNIT = <?= json_encode($sizeGuideChart['unit'] ?? 'in') ?>;
    let dievonSgLastFocused = null;
    let dievonSgCurrentUnit = DIEVON_SG_UNIT;

    function openSizeGuideModal() {
        const modal = document.getElementById('dievonSizeGuideModal');
        if (!modal) return;
        dievonSgLastFocused = document.activeElement;
        modal.style.display = 'flex';
        requestAnimationFrame(() => requestAnimationFrame(() => modal.classList.add('is-visible')));
        document.body.classList.add('dievon-sg-open');
        const closeBtn = document.getElementById('dievonSgCloseBtn');
        if (closeBtn) closeBtn.focus();
        document.addEventListener('keydown', dievonSgEscHandler);
    }

    function closeSizeGuideModal() {
        const modal = document.getElementById('dievonSizeGuideModal');
        if (!modal) return;
        modal.classList.remove('is-visible');
        setTimeout(() => { modal.style.display = 'none'; }, 300);
        document.body.classList.remove('dievon-sg-open');
        document.removeEventListener('keydown', dievonSgEscHandler);
        if (dievonSgLastFocused && typeof dievonSgLastFocused.focus === 'function') dievonSgLastFocused.focus();
    }

    function dievonSgEscHandler(e) {
        if (e.key === 'Escape') closeSizeGuideModal();
    }

    function dievonSgSwitchTab(type) {
        ['body', 'garment'].forEach(t => {
            const table = document.getElementById('sgTable-' + t);
            const btn = document.getElementById('sgTabBtn-' + t);
            if (table) table.style.display = (t === type) ? '' : 'none';
            if (btn) { btn.classList.toggle('active', t === type); btn.setAttribute('aria-selected', t === type ? 'true' : 'false'); }
        });
    }

    function dievonSgSwitchUnit(unit) {
        if (unit === dievonSgCurrentUnit) return;
        dievonSgCurrentUnit = unit;
        document.getElementById('sgUnitBtn-in')?.classList.toggle('active', unit === 'in');
        document.getElementById('sgUnitBtn-cm')?.classList.toggle('active', unit === 'cm');

        document.querySelectorAll('.dievon-sg-measure-cell').forEach(cell => {
            const raw = cell.getAttribute('data-raw');
            if (raw === '' || raw === null) { cell.textContent = '—'; return; }
            let val = parseFloat(raw);
            if (DIEVON_SG_UNIT !== unit) {
                val = (DIEVON_SG_UNIT === 'in') ? (val * 2.54) : (val / 2.54);
            }
            cell.textContent = (Math.round(val * 10) / 10).toString();
        });
    }
</script>

<!-- ══ Dievon Size Guide Modal ════════════════════════════════════ -->
<div id="dievonSizeGuideModal" class="dievon-sg-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="dievonSgTitle" onclick="if(event.target===this) closeSizeGuideModal()">
    <div class="dievon-sg-modal">
        <div class="dievon-sg-header">
            <div class="dievon-sg-header-text">
                <span class="dievon-sg-eyebrow">Measure With Confidence</span>
                <h3 id="dievonSgTitle" class="dievon-sg-title">Dievon SIZE GUIDE</h3>
            </div>
            <button type="button" id="dievonSgCloseBtn" class="dievon-sg-close" onclick="closeSizeGuideModal()" aria-label="Close size guide">&times;</button>
        </div>

        <?php if ($hasSizeGuide): ?>
        <p class="dievon-sg-intro">Compare your body measurements against the chart below to find your perfect Dievon fit. All measurements are shown in <?= ($sizeGuideChart['unit'] ?? 'in') === 'in' ? 'inches' : 'centimetres' ?> by default — toggle units at any time.</p>
        <div class="dievon-sg-controls">
            <div class="dievon-sg-tabs" role="tablist">
                <button type="button" id="sgTabBtn-body" class="dievon-sg-tab active" role="tab" aria-selected="true" onclick="dievonSgSwitchTab('body')">BODY MEASUREMENTS</button>
                <button type="button" id="sgTabBtn-garment" class="dievon-sg-tab" role="tab" aria-selected="false" onclick="dievonSgSwitchTab('garment')">GARMENT MEASUREMENTS</button>
            </div>
            <div class="dievon-sg-unit-toggle" role="group" aria-label="Measurement units">
                <button type="button" id="sgUnitBtn-in" class="dievon-sg-unit-btn <?= ($sizeGuideChart['unit'] ?? 'in') === 'in' ? 'active' : '' ?>" onclick="dievonSgSwitchUnit('in')">INCHES</button>
                <button type="button" id="sgUnitBtn-cm" class="dievon-sg-unit-btn <?= ($sizeGuideChart['unit'] ?? 'in') === 'cm' ? 'active' : '' ?>" onclick="dievonSgSwitchUnit('cm')">CM</button>
            </div>
        </div>

        <div class="dievon-sg-body">
            <div class="dievon-sg-table-wrap">
                <table class="dievon-sg-table" id="sgTable-body">
                    <thead><tr><th>Size</th><th>Numeric</th><th><?= $sgBustLabel ?></th><th>Waist</th><th>Hips</th><th>Shoulder</th></tr></thead>
                    <tbody>
                        <?php if (empty($sizeGuideBody)): ?>
                        <tr><td colspan="6" class="dievon-sg-empty-row">No body measurements entered yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($sizeGuideBody as $r): ?>
                        <tr>
                            <td class="dievon-sg-size-cell"><?= htmlspecialchars($r['size_label']) ?></td>
                            <td><?= htmlspecialchars($r['numeric_size'] ?? '—') ?></td>
                            <td class="dievon-sg-measure-cell" data-raw="<?= $r['bust'] !== null ? $r['bust'] : '' ?>"><?= $r['bust'] !== null ? rtrim(rtrim(number_format($r['bust'], 1), '0'), '.') : '—' ?></td>
                            <td class="dievon-sg-measure-cell" data-raw="<?= $r['waist'] !== null ? $r['waist'] : '' ?>"><?= $r['waist'] !== null ? rtrim(rtrim(number_format($r['waist'], 1), '0'), '.') : '—' ?></td>
                            <td class="dievon-sg-measure-cell" data-raw="<?= $r['hips'] !== null ? $r['hips'] : '' ?>"><?= $r['hips'] !== null ? rtrim(rtrim(number_format($r['hips'], 1), '0'), '.') : '—' ?></td>
                            <td class="dievon-sg-measure-cell" data-raw="<?= $r['shoulder'] !== null ? $r['shoulder'] : '' ?>"><?= $r['shoulder'] !== null ? rtrim(rtrim(number_format($r['shoulder'], 1), '0'), '.') : '—' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php
                    // Drop any column that is empty on EVERY row.
                    //
                    // Sleeve is unset across all nine rows of every garment chart, so the
                    // table printed a Sleeve heading above nine em dashes. That reads as a
                    // broken table, not as "not applicable" — and on Bottoms, sleeve and
                    // bust genuinely never apply, so the blanks were correct there and
                    // still looked like a fault.
                    //
                    // A column reappears by itself the moment a single value is entered,
                    // so this hides nothing the shop actually knows.
                    $sgGarmentCols = [
                        'bust' => $sgBustLabel, 'waist' => 'Waist', 'hips' => 'Hips',
                        'shoulder' => 'Shoulder', 'sleeve' => 'Sleeve', 'length' => 'Length',
                    ];
                    $sgGarmentCols = array_filter($sgGarmentCols, function ($label, $key) use ($sizeGuideGarment) {
                        foreach ($sizeGuideGarment as $row) {
                            $v = $row[$key] ?? null;
                            if ($v !== null && $v !== '' && (float)$v > 0) { return true; }
                        }
                        return false;
                    }, ARRAY_FILTER_USE_BOTH);
                    $sgGarmentSpan = count($sgGarmentCols) + 2;   // + Size and Numeric
                ?>
                <table class="dievon-sg-table" id="sgTable-garment" style="display:none;">
                    <thead><tr><th>Size</th><th>Numeric</th><?php foreach ($sgGarmentCols as $sgLabel): ?><th><?= htmlspecialchars($sgLabel) ?></th><?php endforeach; ?></tr></thead>
                    <tbody>
                        <?php if (empty($sizeGuideGarment)): ?>
                        <tr><td colspan="<?= (int)$sgGarmentSpan ?>" class="dievon-sg-empty-row">No garment measurements entered yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($sizeGuideGarment as $r):
                            // One formatter for every cell: trims 34.50 to 34.5 and 34.00 to 34,
                            // and prints an em dash rather than "0" when a measurement genuinely
                            // does not apply (a sleeveless top has no sleeve length).
                            $cell = static function ($v) {
                                if ($v === null || $v === '') { return '—'; }
                                return rtrim(rtrim(number_format((float)$v, 1), '0'), '.');
                            };
                        ?>
                        <tr>
                            <td class="dievon-sg-size-cell"><?= htmlspecialchars($r['size_label']) ?></td>
                            <td><?= htmlspecialchars($r['numeric_size'] ?? '—') ?></td>
                            <?php foreach ($sgGarmentCols as $sgKey => $sgLabel): ?>
                            <td class="dievon-sg-measure-cell" data-raw="<?= $r[$sgKey] ?? '' ?>"><?= $cell($r[$sgKey] ?? null) ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php
            // Measurement tolerance. Hand-finished garments genuinely vary, and
            // saying so up front turns "it's half an inch out" from a return into
            // an expectation that was already set.
            //
            // Sits OUTSIDE .dievon-sg-table-wrap, which is overflow-x:auto. Inside
            // it, this sentence was part of the scrolling area: on a phone the
            // table is wider than the popup (480px of table in 289px of space), so
            // scrolling across to read the hip measurement dragged the tolerance
            // note off with it, and at rest it sat flush against the wrapper's
            // edges with none of the body padding the rest of the popup has.
            // A caption for the table is not part of the table.
            $sgTolerance = trim((string)($sizeGuideChart['tolerance'] ?? ''));
            if ($sgTolerance !== ''):
            ?>
            <p class="dievon-sg-tolerance">
                Garment measurements are taken flat and may vary by <strong><?= htmlspecialchars($sgTolerance) ?></strong>,
                as each piece is finished by hand.
            </p>
            <?php endif; ?>

            <?php
            // Site-wide "How to Measure" picture: uploaded once in admin and shown on
            // every category. It sits between the size table and the diagrams below, and
            // is purely additive — the built-in SVG illustrations still render as normal.
            // Fixed artwork. There is no uploaded alternative any more.
            //
            // The uploaded copies lived in uploads/gallery, which the FTP deploy
            // deliberately does not carry — syncing it would wipe live uploads — so
            // every install needed the picture re-uploaded by hand or the size guide
            // opened with no diagram at all. Body measuring is identical for every
            // garment in every category, so the choice was never worth making: one
            // drawing, shipped with the code, correct everywhere.
            $sgHowToDesktop = '';
            $sgHowToMobile  = '';

            // Bundled default artwork, so a fresh install shows the diagram with
            // nothing uploaded. The uploaded copies live in uploads/, which the FTP
            // deploy deliberately does not carry (it would wipe live uploads), so on
            // live this block simply never rendered — the size guide opened with no
            // measurement picture at all until someone re-uploaded it per install.
            // These two files sit in assets/ instead, which ships with the code.
            //
            // An upload still wins: this is only consulted when the setting is empty.
            // file_exists() guards the fallback because a half-deployed assets/ folder
            // should degrade to "no picture" — the same as before — rather than to a
            // broken-image icon in the middle of the popup.
            $sgDefaultDir = __DIR__ . '/../assets/images/sizeguide/';
            $sgDefaults   = [
                'desktop' => 'howtomeasure_desktop.webp',
                'mobile'  => 'howtomeasure_mobile.webp',
            ];

            // Resolve each width to a full URL, because the uploaded copy and the
            // bundled copy live under different directories.
            $sgUploadUrl = fn(string $f): string => SITE_URL . '/uploads/gallery/' . rawurlencode($f);
            $sgAssetUrl  = fn(string $f): string => SITE_URL . '/assets/images/sizeguide/' . rawurlencode($f);

            $sgHowToDesktopUrl = $sgHowToDesktop
                ? $sgUploadUrl($sgHowToDesktop)
                : (is_file($sgDefaultDir . $sgDefaults['desktop']) ? $sgAssetUrl($sgDefaults['desktop']) : '');
            $sgHowToMobileUrl = $sgHowToMobile
                ? $sgUploadUrl($sgHowToMobile)
                : (is_file($sgDefaultDir . $sgDefaults['mobile']) ? $sgAssetUrl($sgDefaults['mobile']) : '');

            // If only one width resolved, use it at both — matches the old behaviour.
            $sgHowToDesktopUrl = $sgHowToDesktopUrl ?: $sgHowToMobileUrl;
            $sgHowToMobileUrl  = $sgHowToMobileUrl  ?: $sgHowToDesktopUrl;
            ?>
            <?php if ($sgHowToDesktopUrl): ?>
            <?php /* No caption on this figure on purpose: the written instructions further
                     down already carry a "How to measure" heading, and captioning the image
                     the same way showed the title twice in one popup.
                     <picture> is used rather than CSS show/hide so a phone only ever
                     downloads the mobile file — display:none still costs the download.
                     (PHP comment, not an HTML one, so none of this reaches the browser.) */ ?>
            <figure class="dievon-sg-howto">
                <picture>
                    <?php if ($sgHowToMobileUrl !== $sgHowToDesktopUrl): ?>
                    <source media="(max-width: 768px)" srcset="<?= htmlspecialchars($sgHowToMobileUrl) ?>">
                    <?php endif; ?>
                    <img src="<?= htmlspecialchars($sgHowToDesktopUrl) ?>"
                         alt="Diagram showing how to measure bust, waist, hips and length"
                         class="dievon-sg-howto-img" loading="lazy">
                </picture>
            </figure>
            <?php endif; ?>

            <div class="dievon-sg-illustration-row">
                <div class="dievon-sg-illustration">
                    <?php
                    // ONLY this chart's own overlay photo here. The site-wide "how to
                    // measure" image is a separate thing and renders above this row, so
                    // it must not also fall through to here — otherwise it would appear
                    // twice and replace the built-in SVG diagram.
                    $sgIllustration = !empty($sizeGuideChart['illustration_image'])
                        ? SITE_URL . '/uploads/products/' . $sizeGuideChart['illustration_image']
                        : null;
                    ?>
                    <?php if ($sgIllustration): ?>
                    <?php
                        $sgLenTop    = $sizeGuideChart['pos_length_top'] ?? 15;
                        $sgLenBottom = $sizeGuideChart['pos_length_bottom'] ?? 95;
                    ?>
                    <div class="dievon-sg-photo-stage">
                        <img src="<?= htmlspecialchars($sgIllustration) ?>" alt="How to measure — body measurement diagram" class="dievon-sg-photo-img">
                        <?php if ($sgShowShoulder): ?>
                        <div class="dievon-sg-photo-line" style="top:<?= $sizeGuideChart['pos_shoulder_top'] ?? 18 ?>%; width:<?= $sizeGuideChart['pos_shoulder_width'] ?? 45 ?>%;"><span>Shoulder</span></div>
                        <?php endif; ?>
                        <?php if ($sgShowBust): ?>
                        <div class="dievon-sg-photo-line" style="top:<?= $sizeGuideChart['pos_bust_top'] ?? 32 ?>%; width:<?= $sizeGuideChart['pos_bust_width'] ?? 45 ?>%;"><span><?= $sgBustLabel ?></span></div>
                        <?php endif; ?>
                        <?php if ($sgShowWaist): ?>
                        <div class="dievon-sg-photo-line" style="top:<?= $sizeGuideChart['pos_waist_top'] ?? 50 ?>%; width:<?= $sizeGuideChart['pos_waist_width'] ?? 35 ?>%;"><span>Waist</span></div>
                        <?php endif; ?>
                        <?php if ($sgShowHips): ?>
                        <div class="dievon-sg-photo-line" style="top:<?= $sizeGuideChart['pos_hips_top'] ?? 64 ?>%; width:<?= $sizeGuideChart['pos_hips_width'] ?? 50 ?>%;"><span>Hips</span></div>
                        <?php endif; ?>
                        <?php if ($sgShowLength): ?>
                        <div class="dievon-sg-photo-length" style="top:<?= min($sgLenTop, $sgLenBottom) ?>%; height:<?= abs($sgLenBottom - $sgLenTop) ?>%;"><span>Length</span></div>
                        <?php endif; ?>
                    </div>
                    <?php elseif ($sgIsLowerBodyOnly): ?>
                    <svg viewBox="0 0 210 300" class="dievon-sg-illustration-svg" role="img" aria-label="Diagram showing where to measure waist, hips and length on a lower-body silhouette">
                        <path d="M125,40 C138,55 145,72 145,90 C148,140 152,220 155,280 L45,280 C48,220 52,140 55,90 C55,72 62,55 75,40 Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                        <path d="M100,90 L98,280 M102,90 L102,280" fill="none" stroke="currentColor" stroke-width="1" stroke-dasharray="2,3" opacity="0.5"/>

                        <line x1="72" y1="38" x2="128" y2="38" stroke="currentColor" stroke-width="1" stroke-dasharray="3,3"/>
                        <line x1="72" y1="34" x2="72" y2="42" stroke="currentColor" stroke-width="1.5"/>
                        <line x1="128" y1="34" x2="128" y2="42" stroke="currentColor" stroke-width="1.5"/>
                        <text x="133" y="41" font-size="9" fill="currentColor">Waist</text>

                        <line x1="50" y1="95" x2="150" y2="95" stroke="currentColor" stroke-width="1" stroke-dasharray="3,3"/>
                        <line x1="50" y1="91" x2="50" y2="99" stroke="currentColor" stroke-width="1.5"/>
                        <line x1="150" y1="91" x2="150" y2="99" stroke="currentColor" stroke-width="1.5"/>
                        <text x="155" y="98" font-size="9" fill="currentColor">Hips</text>

                        <line x1="20" y1="40" x2="20" y2="280" stroke="currentColor" stroke-width="1" stroke-dasharray="3,3"/>
                        <line x1="16" y1="40" x2="24" y2="40" stroke="currentColor" stroke-width="1.5"/>
                        <line x1="16" y1="280" x2="24" y2="280" stroke="currentColor" stroke-width="1.5"/>
                        <text x="9" y="168" font-size="9" fill="currentColor" transform="rotate(-90 9 168)">Length</text>
                    </svg>
                    <?php else: ?>
                    <svg viewBox="0 0 210 300" class="dievon-sg-illustration-svg" role="img" aria-label="Diagram showing where to measure shoulder, <?= strtolower($sgBustLabel) ?>, waist, hips and garment length on a front-facing silhouette">
                        <circle cx="100" cy="24" r="15" fill="none" stroke="currentColor" stroke-width="2"/>
                        <path d="M90,38 L92,50 M110,38 L108,50" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <path d="M138,50 C144,65 142,80 142,95 C142,115 136,135 130,150 C126,165 138,178 146,195 C152,220 155,250 155,280 L45,280 C45,250 48,220 54,195 C62,178 74,165 70,150 C64,135 58,115 58,95 C58,80 56,65 62,50 Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>

                        <line x1="55" y1="50" x2="145" y2="50" stroke="currentColor" stroke-width="1" stroke-dasharray="3,3"/>
                        <line x1="55" y1="46" x2="55" y2="54" stroke="currentColor" stroke-width="1.5"/>
                        <line x1="145" y1="46" x2="145" y2="54" stroke="currentColor" stroke-width="1.5"/>
                        <text x="150" y="53" font-size="9" fill="currentColor">Shoulder</text>

                        <line x1="50" y1="95" x2="150" y2="95" stroke="currentColor" stroke-width="1" stroke-dasharray="3,3"/>
                        <line x1="50" y1="91" x2="50" y2="99" stroke="currentColor" stroke-width="1.5"/>
                        <line x1="150" y1="91" x2="150" y2="99" stroke="currentColor" stroke-width="1.5"/>
                        <text x="155" y="98" font-size="9" fill="currentColor"><?= $sgBustLabel ?></text>

                        <line x1="62" y1="150" x2="138" y2="150" stroke="currentColor" stroke-width="1" stroke-dasharray="3,3"/>
                        <line x1="62" y1="146" x2="62" y2="154" stroke="currentColor" stroke-width="1.5"/>
                        <line x1="138" y1="146" x2="138" y2="154" stroke="currentColor" stroke-width="1.5"/>
                        <text x="143" y="153" font-size="9" fill="currentColor">Waist</text>

                        <line x1="46" y1="195" x2="154" y2="195" stroke="currentColor" stroke-width="1" stroke-dasharray="3,3"/>
                        <line x1="46" y1="191" x2="46" y2="199" stroke="currentColor" stroke-width="1.5"/>
                        <line x1="154" y1="191" x2="154" y2="199" stroke="currentColor" stroke-width="1.5"/>
                        <text x="159" y="198" font-size="9" fill="currentColor">Hips</text>

                        <line x1="20" y1="50" x2="20" y2="280" stroke="currentColor" stroke-width="1" stroke-dasharray="3,3"/>
                        <line x1="16" y1="50" x2="24" y2="50" stroke="currentColor" stroke-width="1.5"/>
                        <line x1="16" y1="280" x2="24" y2="280" stroke="currentColor" stroke-width="1.5"/>
                        <text x="9" y="168" font-size="9" fill="currentColor" transform="rotate(-90 9 168)">Length</text>
                    </svg>
                    <?php endif; ?>
                </div>
                <?php
                    // This category's own wording where it has any, otherwise the shop-wide
                    // default. Previously it read the chart only, so a category with no chart
                    // showed a completely blank "How to measure" panel — measuring a bust is
                    // the same whatever the garment, so there is no reason to hide it.
                    $instructionPairs = resolveSizeGuideInstructions($pdo, $sizeGuideChart);
                ?>
                <?php if (!empty($instructionPairs)): ?>
                <div class="dievon-sg-instructions">
                    <h4>How to measure</h4>
                    <?php foreach ($instructionPairs as $label => $text): ?>
                    <p><strong><?= htmlspecialchars($label) ?>:</strong> <?= htmlspecialchars($text) ?></p>
                    <?php endforeach; ?>
                    <div class="dievon-sg-tip"><strong>Tip:</strong> Measure over well-fitting undergarments, keep the tape parallel to the floor, and ask someone to help for the most accurate result.</div>
                </div>
                <?php endif; ?>
            </div>

            <div class="dievon-sg-cta">
                <p>Still unsure of your fit?</p>
                <button type="button" class="btn-luxury-outline" style="padding: 10px 22px; font-size: 11px;" onclick="closeSizeGuideModal(); setTimeout(() => openFadeModal('productEnquiryModal'), 320);">Ask Our Concierge</button>
            </div>
        </div>
        <?php else: ?>
        <div class="dievon-sg-body">
            <p class="size-guide-empty-msg">The size guide for this product hasn't been set up yet. Please contact us and we'll help you find the right fit.</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ══ Product Enquiry / Concierge Modal ════════════════════════ -->
<div id="productEnquiryModal" class="product-modal-overlay">
    <div class="product-modal-card">
        <div class="product-modal-card-header">
            <h3>👗 Product & Fitting Enquiry</h3>
            <button onclick="document.getElementById('productEnquiryModal').style.display='none'" class="product-modal-close-btn">&times;</button>
        </div>
        <div class="product-modal-card-body">
            <p class="product-modal-hint">
                Have questions regarding <strong><?= htmlspecialchars($product['name']) ?></strong>, custom tailoring, or size fitting?
            </p>
            <form id="productEnquiryForm" onsubmit="submitProductEnquiry(event)">
                <input type="hidden" name="product_id" value="<?= $productId ?>">
                <input type="hidden" name="product_name" value="<?= htmlspecialchars($product['name']) ?>">
                <div class="form-luxury-group">
                    <label>Your Name *</label>
                    <input type="text" name="name" class="form-luxury-input" required placeholder="Eleanor Vance" value="<?= htmlspecialchars($_SESSION['customer_name'] ?? '') ?>">
                </div>
                <div class="form-luxury-group">
                    <label>Email Address *</label>
                    <input type="email" name="email" class="form-luxury-input" required placeholder="you@example.com" value="<?= htmlspecialchars($_SESSION['customer_email'] ?? '') ?>">
                </div>
                <div class="form-luxury-group">
                    <label>Phone Number</label>
                    <input type="text" name="phone" class="form-luxury-input" placeholder="<?= htmlspecialchars(shopPhone()) ?>">
                </div>
                <div class="form-luxury-group">
                    <label>Your Message / Fitting Request *</label>
                    <textarea name="message" class="form-luxury-input" rows="3" required placeholder="Ask about size advice, fabric details, or custom order requests..."></textarea>
                </div>
                <div id="enquiryStatusMsg" class="product-modal-form-msg" style="display: none;"></div>
                <button type="submit" id="btnSubmitEnquiry" class="btn-luxury" style="width: 100%;">
                    Submit Product Enquiry
                </button>
            </form>
        </div>
    </div>
</div>

<script>
function submitProductEnquiry(e) {
    e.preventDefault();
    const form = document.getElementById('productEnquiryForm');
    const statusMsg = document.getElementById('enquiryStatusMsg');
    const btn = document.getElementById('btnSubmitEnquiry');

    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting...';
    statusMsg.style.display = 'none';

    const formData = new FormData(form);
    fetch(window.SITE_URL + '/actions/product_enquiry_action.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        statusMsg.style.display = 'block';
        if (data.success) {
            statusMsg.style.color = '#10b981';
            statusMsg.innerHTML = '✅ ' + data.message;
            form.reset();
            setTimeout(() => {
                document.getElementById('productEnquiryModal').style.display = 'none';
                btn.disabled = false;
                btn.innerHTML = 'Submit Product Enquiry';
            }, 2500);
        } else {
            statusMsg.style.color = '#ef4444';
            statusMsg.innerHTML = '❌ ' + data.message;
            btn.disabled = false;
            btn.innerHTML = 'Submit Product Enquiry';
        }
    })
    .catch(err => {
        statusMsg.style.display = 'block';
        statusMsg.style.color = '#ef4444';
        statusMsg.innerHTML = '❌ Error sending enquiry. Please try again.';
        btn.disabled = false;
        btn.innerHTML = 'Submit Product Enquiry';
    });
}

// Report a review. Uses dievonPrompt/confirm where available so the shop keeps
// its own dialog styling, and falls back to the native prompt otherwise.
const REVIEW_REPORT_REASONS = ['Offensive or abusive', 'Not about this product', 'Spam or advertising', 'Fake or misleading', 'Other'];

function reportReview(reviewId) {
    const list = REVIEW_REPORT_REASONS.map((r, i) => (i + 1) + '. ' + r).join('\n');
    const pick = window.prompt('Why are you reporting this review?\n\n' + list + '\n\nEnter a number (1-' + REVIEW_REPORT_REASONS.length + '):');
    if (pick === null) { return; }

    const idx = parseInt(pick, 10) - 1;
    const reason = REVIEW_REPORT_REASONS[idx] || 'Other';

    const fd = new FormData();
    fd.append('review_id', reviewId);
    fd.append('reason', reason);
    fd.append('csrf_token', window.DIEVON_CSRF || '');

    fetch(window.SITE_URL + '/actions/review_report_action.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (typeof showToast === 'function') { showToast(d.success ? 'Reported' : 'Could not report', d.message); }
            else { window.alert(d.message); }
        })
        .catch(() => window.alert('Could not reach the server. Please try again.'));
}

function submitProductReview(e, productId) {
    e.preventDefault();
    const form = document.getElementById('writeReviewForm');
    const msg = document.getElementById('writeReviewMsg');
    const btn = form.querySelector('button[type="submit"]');

    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting...';
    msg.style.display = 'none';

    const formData = new FormData(form);
    formData.append('product_id', productId);

    fetch(window.SITE_URL + '/actions/review_action.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        msg.style.display = 'block';
        if (data.success) {
            msg.style.background = '#ecfdf5';
            msg.style.color = '#065f46';
            msg.innerHTML = data.message;
            form.reset();
            setTimeout(() => { closeFadeModal('writeReviewModal'); }, 2500);
        } else {
            msg.style.background = '#fdf2f2';
            msg.style.color = '#a94442';
            msg.innerHTML = data.message;
        }
        btn.disabled = false;
        btn.innerHTML = 'Submit Review';
    })
    .catch(() => {
        msg.style.display = 'block';
        msg.style.background = '#fdf2f2';
        msg.style.color = '#a94442';
        msg.innerHTML = 'Error submitting review. Please try again.';
        btn.disabled = false;
        btn.innerHTML = 'Submit Review';
    });
}

</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
