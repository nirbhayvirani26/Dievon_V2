<?php
// Google Merchant Center product feed.
//
// This file previously would have got the account suspended on its first crawl:
//
//   1. Every price was labelled GBP while the store sells in INR, so a ₹350 kurti
//      was submitted as £350 — a ~100× mismatch against the landing page. Price
//      mismatch is one of the fastest routes to a disapproved feed.
//   2. g:link pointed at /product.php?id=N, a path this site does not serve.
//      Every item linked to a 404.
//   3. Soft-deleted products were included.
//   4. Apparel needs g:size, g:color, g:item_group_id, g:gender, g:age_group and
//      an identifier declaration. None were sent, which disqualifies clothing
//      items outright in most target countries.
//
// Money and URLs now come from the same helpers the product page and its
// structured data use, so the feed cannot drift from what a shopper sees.
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';

header('Content-Type: application/xml; charset=utf-8');

$currency = getCurrentCurrency();
$country  = function_exists('currentCountryCode') ? currentCountryCode() : 'IN';
/* The two shipping numbers, resolved the way checkout resolves them.
   ────────────────────────────────────────────────────────────────────────────
   These read the GLOBAL store_settings figures, while shippingFeeFor() in
   config.php charges from the HOME COUNTRY row — the same split that comment
   already warns about for the product page. The Countries screen carries a
   shipping_fee and a free_shipping_min per country (IN: 120 and 2000), so
   editing the India row moved what a shopper is charged while the feed kept
   quoting the old global number. A feed that disagrees with the landing page
   about postage is a price mismatch, and Merchant Center disapproves items for
   exactly that.

   The free-shipping default was also 0 here against 2000 everywhere else, and
   0 does not merely differ — it disables the threshold outright, because the
   test below is `$freeOver > 0`. With the setting absent the feed would have
   charged postage on every item while the shop gave it away free.

   Nothing changes in today's output: every garment is priced ₹898–₹1,799, all
   under the ₹2,000 threshold, so all 115 rows carry the flat fee either way.
   This is about the next time someone edits a rate in admin. */
$homeCountry = function_exists('homeCountryRow') ? homeCountryRow() : null;

$shipFee = ($homeCountry !== null && isset($homeCountry['shipping_fee']) && $homeCountry['shipping_fee'] !== null)
    ? (float)$homeCountry['shipping_fee']
    : (float)storeSetting($pdo, 'standard_shipping_fee', 99);

$freeOver = (function_exists('freeShippingMinForCountry') && $homeCountry !== null)
    ? freeShippingMinForCountry($pdo, $homeCountry['country_code'] ?? null)
    : (float)storeSetting($pdo, 'free_shipping_min', 2000);

$products = [];
try {
    $products = $pdo->query(
        "SELECT * FROM products
          WHERE available = 1 AND (is_deleted = 0 OR is_deleted IS NULL)
          ORDER BY id DESC"
    )->fetchAll();
} catch (PDOException $e) {}

/** Size variants of a product, for one feed item per size.
 *
 * Each row carries its colour with it (_colorRow / _colorName), because a size
 * cannot be priced or identified without one:
 *
 *  - PRICE. A colour's price_override beats the size's own price, and only
 *    effectiveVariantPrice() knows that. Without the colour this file could
 *    only re-derive a price, which is exactly the bug it used to have.
 *  - IDENTITY. g:id was code + size with nothing for the colour, so a garment
 *    in three colourways emitted the SAME g:id three times with three different
 *    prices. Merchant Center keeps one arbitrarily, so two colourways silently
 *    vanished and the survivor could be advertised at another colour's price.
 *
 * A colour switched off in admin is excluded here too. product_colors.is_active
 * is what the product page filters on (pages/product.php:217); the feed ignored
 * it entirely and went on advertising a colourway the shop had withdrawn.
 */
function feedVariants(PDO $pdo, int $productId): array {
    try {
        $st = $pdo->prepare(
            "SELECT v.*, c.color_name AS _colorName, c.id AS _colorId,
                    c.price_override AS _colorOverride, c.sku AS _colorSku
               FROM product_variants v
               LEFT JOIN product_colors c ON c.id = v.color_id
              WHERE v.product_id = :id
                AND v.available = 1
                AND (v.color_id IS NULL OR c.is_active = 1)
              ORDER BY v.sort_order ASC, v.id ASC"
        );
        $st->execute(['id' => $productId]);
        $rows = $st->fetchAll();
        foreach ($rows as &$r) {
            $r['_colorRow'] = $r['_colorId'] === null
                ? null
                : ['id' => $r['_colorId'], 'color_name' => $r['_colorName'], 'price_override' => $r['_colorOverride']];
        }
        unset($r);
        return $rows;
    } catch (PDOException $e) { return []; }
}

function feedTag(string $name, $value, bool $cdata = false): void {
    $value = trim((string)$value);
    if ($value === '') { return; }
    echo "      <$name>";
    echo $cdata ? '<![CDATA[' . $value . ']]>' : htmlspecialchars($value, ENT_XML1);
    echo "</$name>\n";
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">
  <channel>
    <title><?= htmlspecialchars(SHOP_NAME, ENT_XML1) ?> Product Feed</title>
    <link><?= htmlspecialchars(SITE_URL, ENT_XML1) ?></link>
    <description>Google Merchant Center product feed for <?= htmlspecialchars(SHOP_NAME, ENT_XML1) ?> garments.</description>
<?php foreach ($products as $p):
    $link  = productUrl($p['id'], $p['name'], $p['seo_url'] ?? null);
    $image = !empty($p['image'])
        ? SITE_URL . '/uploads/products/' . $p['image']
        : siteLogoUrl($pdo);
    $baseCode = productDisplayCode($p);
    $variants = feedVariants($pdo, (int)$p['id']);

    /* The product's live colourways, used only as the last resort for g:color
       further down. Read once per product rather than once per size. */
    $soleColourway = [];
    try {
        $cwSt = $pdo->prepare("SELECT color_name FROM product_colors
                                WHERE product_id = :id AND is_active = 1
                                  AND TRIM(COALESCE(color_name,'')) <> ''");
        $cwSt->execute(['id' => (int)$p['id']]);
        $soleColourway = array_values(array_unique(array_map('trim', $cwSt->fetchAll(PDO::FETCH_COLUMN))));
    } catch (PDOException $e) { $soleColourway = []; }

    // Sale pricing: g:price is the regular price and g:sale_price the current one,
    // so Merchant Center can show the strike-through the site already shows.
    $mrp        = (float)($p['mrp_price'] ?? 0);
    $sellPrice  = (float)$p['price'];
    $hasSale    = $mrp > $sellPrice && $sellPrice > 0;
    $regular    = $hasSale ? $mrp : $sellPrice;

    // Free delivery above the threshold is what this item would really cost to ship.
    $shipRate = ($freeOver > 0 && $sellPrice >= $freeOver) ? 0.0 : $shipFee;

    $description = trim(preg_replace('/\s+/', ' ', strip_tags((string)$p['description'])));
    /* Merchant Center's own shape for these two, now that both columns can hold
       a list.
       ────────────────────────────────────────────────────────────────────────
       g:material takes the PRIMARY material first and up to two more separated
       by a SLASH ("leather/cotton/polyester") — not the comma the column stores.
       g:pattern takes ONE value; there is no multi-pattern form in the spec.
       Sent as stored, a garment saved "Cotton, Linen, Silk" arrived as a single
       unrecognised material string, which is how an item starts collecting
       attribute warnings without anything on the shop looking wrong.
       Composition is free text and is left exactly as typed. */
    $materialRaw = trim((string)($p['composition'] ?: ($p['fabric'] ?? '')));
    $material    = $materialRaw;
    if ($p['composition'] === null || trim((string)$p['composition']) === '') {
        $matParts = dievonSplitAttrList('fabric', $materialRaw);
        if ($matParts) { $material = implode('/', array_slice($matParts, 0, 3)); }
    }
    $patternParts = dievonSplitAttrList('pattern', (string)($p['pattern'] ?? ''));
    $feedPattern  = $patternParts[0] ?? '';

    // A garment with no photograph or no description is not advertisable, so it
    // is left OUT of the feed rather than padded to look complete.
    //
    // g:description is a required Merchant Center attribute — an item missing it
    // is disapproved on submission. Worse, the $image line above quietly
    // substituted the shop LOGO whenever a product had no picture, and a logo
    // used as a product image is separately grounds for disapproval and, at
    // volume, for account-level warnings against the whole feed.
    //
    // This file already refuses to invent data elsewhere: it declares
    // g:identifier_exists=no rather than fabricating a GTIN. Same judgement here.
    if (empty($p['image']) || $description === '') {
        continue;
    }

    // One item per size when sizes exist, sharing an item_group_id. Google matches
    // a shopper's size to a specific item; a single sizeless item cannot be shown
    // for a size query at all.
    $rows = [];
    foreach ($variants as $v) {
        $size = trim(str_replace('Size:', '', (string)($v['size_code'] ?: $v['name'])));
        if ($size === '') { continue; }
        $colourSlug = $v['_colorName'] !== null
            ? '-' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$v['_colorName']))
            : '';
        $rows[] = [
            // Colour included, or three colourways collide on one g:id. See feedVariants().
            'id'    => $baseCode . $colourSlug . '-' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $size)),
            'size'  => $size,
            // Resolved by the SAME function the page and the bag use, rather than
            // re-derived here. This line was "the size's price if it has one, else
            // the product's" — blind to a colour's price_override and to the sale
            // clamp, so the feed advertised ₹2,400 for a size the shop sells at
            // ₹1,650, and ₹2,400 for one clamped to ₹1,899. A feed price that
            // disagrees with the landing page is a Merchant Center policy breach,
            // which is precisely what this file's header says it was rewritten to
            // prevent — the ladder simply had a second copy living here.
            'price' => effectiveVariantPrice($p, $v, $v['_colorRow'] ?? null),
            'colour'=> (string)($v['_colorName'] ?? ''),
            'stock' => array_key_exists('stock_qty', $v) && $v['stock_qty'] !== null
                ? ((int)$v['stock_qty'] > 0) : productIsInStock($p),
        ];
    }
    if (!$rows) {
        $rows[] = ['id' => $baseCode, 'size' => '', 'price' => effectiveVariantPrice($p), 'colour' => '', 'stock' => productIsInStock($p)];
    }

    foreach ($rows as $row):
        /* g:sale_price must be strictly BELOW g:price or Google rejects the item.
           This paired the product's MRP with a raw variant price, so a size dearer
           than the MRP emitted price 2500 / sale_price 2800 — a "sale" costing more
           than the list price, which is an outright disapproval. Resolving the row
           price through effectiveVariantPrice() above fixes the common case; this
           guard covers the rest, and drops the sale pair rather than publishing a
           contradiction. */
        $rowOnSale  = $hasSale && $mrp > $row['price'];
        $rowRegular = $rowOnSale ? $mrp : $row['price'];
?>
    <item>
<?php
        feedTag('g:id', $row['id']);
        feedTag('g:item_group_id', count($rows) > 1 ? $baseCode : '');
        feedTag('g:title', $p['name'] . ($row['size'] !== '' ? ' — Size ' . $row['size'] : ''), true);
        feedTag('g:description', $description, true);
        feedTag('g:link', $link);
        feedTag('g:image_link', $image);
        feedTag('g:availability', $row['stock'] ? 'in_stock' : 'out_of_stock');
        feedTag('g:condition', 'new');

        // Currency comes from the same source as the page and the JSON-LD.
        feedTag('g:price', priceForMachines($rowRegular) . ' ' . $currency);
        if ($rowOnSale) {
            feedTag('g:sale_price', priceForMachines($row['price']) . ' ' . $currency);
        }

        feedTag('g:brand', trim((string)($p['brand'] ?? '')) ?: SHOP_NAME, true);
        feedTag('g:mpn', $p['atelier_code'] ?? '');
        feedTag('g:gtin', $p['barcode'] ?? '');
        // Required when a product genuinely has no GTIN or MPN, which is normal
        // for garments a brand makes itself. Without it the item is disapproved.
        if (empty($p['barcode']) && empty($p['atelier_code'])) {
            feedTag('g:identifier_exists', 'no');
        }

        feedTag('g:product_type', $p['category'] ?? '', true);
        // Google's own taxonomy id for Apparel & Accessories > Clothing.
        feedTag('g:google_product_category', '1604');
        // Read from the product's category, not hardcoded. This was the literal
        // string 'female' for every item — fine while the shop sold only
        // womenswear, but the day a men's category goes live it would submit
        // menswear to Google Shopping as women's, which gets items disapproved
        // or served to the wrong audience. Products in a women's category still
        // send exactly 'female', so today's feed is unchanged.
        $genderMap = ['women' => 'female', 'men' => 'male', 'unisex' => 'unisex'];
        feedTag('g:gender', $genderMap[productGender($p)] ?? 'female');
        feedTag('g:age_group', 'adult');
        feedTag('g:size', $row['size']);
        /* The colourway this item actually is, not the product's blanket one.
           Every item of a three-colour garment advertised the same g:color, so
           Shopping could not tell the colourways apart — and with the colour now
           in g:id they are finally distinct items that deserve distinct colours.
           Falls back to the product-level fields for a garment sold without
           colourways. */
        /* Third fallback: the product's own single colourway.
           ────────────────────────────────────────────────────────────────────
           A garment can have one active colourway and size variants that were
           never linked to it — color_id NULL on every row — and then neither of
           the two sources above has anything. Measured: Iris Straight Crepe
           Pants has a live "Bone Ivory" colourway and emitted four items with
           no g:color at all, which Google disapproves for apparel. The owner
           would have had to notice that and retype a colour the shop already
           knew, in a second place.

           ONLY when there is exactly one. With two or more and nothing linking
           the sizes to either, which colour this item is cannot be known, and
           inventing one is worse than omitting it — a wrong colour on a live
           listing is a returned parcel. */
        $feedColour = $row['colour'] !== '' ? $row['colour'] : dievonProductColour($p);
        if (trim((string)$feedColour) === '' && count($soleColourway) === 1) {
            $feedColour = $soleColourway[0];
        }
        /* g:color takes the same shape as g:material: primary colour first, up to
           two more, separated by slashes. dievonProductColour() may hand back the
           comma-separated list the Colour Way picker stores — "Ivory, Gold" — and
           Google reads that whole string as ONE colour name it does not recognise,
           which is how an item starts collecting attribute warnings. Split on the
           list's own separator and rejoin Google's way. A single colour passes
           through untouched. */
        $colourParts = dievonSplitAttrList('colour', (string)$feedColour);
        if (count($colourParts) > 1) {
            $feedColour = implode('/', array_slice($colourParts, 0, 3));
        }
        feedTag('g:color', $feedColour, true);
        feedTag('g:material', $material, true);
        feedTag('g:pattern', $feedPattern, true);
?>
      <g:shipping>
        <g:country><?= htmlspecialchars($country, ENT_XML1) ?></g:country>
        <g:service>Standard</g:service>
        <g:price><?= priceForMachines($shipRate) ?> <?= htmlspecialchars($currency, ENT_XML1) ?></g:price>
      </g:shipping>
      <g:shipping_label><?= htmlspecialchars($shipRate > 0 ? 'standard' : 'free', ENT_XML1) ?></g:shipping_label>
    </item>
<?php endforeach; endforeach; ?>
  </channel>
</rss>
