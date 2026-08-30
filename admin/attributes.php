<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

// Moved ABOVE the POST handlers below.
// It used to sit after them, and after the page had already rendered — so a
// staff account without this permission ran the INSERT/DELETE first and was
// only shown the 403 afterwards. The record was already gone by then.
requireAdminCapability('catalogue.manage');

// Colours only. ?type=sizes is accepted and quietly treated as colours.
//
// This page offered a sizes tab that no link ever reached — $activeTab below was
// computed and never used, so the switcher was never built — and the size rows it
// managed drove exactly one thing: the order of the shop's size filter chips.
// That order now comes from the size ladder in config/size_ladder.php, the same
// list the product form, the colour Sizes & Stock grid, the size guide and the
// product page all use.
//
// Leaving the tab reachable would mean editing a second size list that changes
// nothing on the website, which is how the two drifted apart in the first place
// (the master list stopped at XL while the ladder runs to 5XL). Sizes are edited
// per product on the product form, and shop-wide in the ladder file.
//
// Existing attr_type='size' rows are left in the table — unreferenced, but a
// harmless record, and deleting a shopkeeper's data to tidy a page is not a
// trade worth making.
/* Four managed lists now, not one.
   ────────────────────────────────────────────────────────────────────────────
   Sleeve, Neck and Pattern were free-text boxes whose suggestions were built
   from whatever had already been typed, so a typo became a suggestion and the
   shop filter filled with near-duplicates — "3/4 Sleeves" beside
   "Three-quarter Sleeve" beside "Three-quarter Sleeves", a fabric composition
   filed under Neck, a neck filed under Pattern. Colour was the only clean
   filter and the only managed one, so the other three join it here.

   Fabric and Occasion are deliberately NOT here: both are currently clean, and
   a list to maintain is a cost that should be paid where there is a problem.
   Adding them later is one line in DIEVON_ATTR_TYPES. */
$tabToType = [];
foreach (DIEVON_ATTR_TYPES as $attrKey => $meta) { $tabToType[$meta['tab']] = $attrKey; }

/* Every list on ONE page, rather than a tab or a menu row each.
   ────────────────────────────────────────────────────────────────────────────
   These four are the same idea four times — a list of allowed values feeding a
   shop filter — and splitting them across tabs meant three of them existed only
   for someone who already knew the URL. Stacked on one screen you can see the
   whole set, and which of them still has values to tidy, without navigating.

   Which list an action belongs to therefore comes from the FORM, not the URL:
   every add, delete and reconcile form carries its own attr_type. The old
   ?type= links still work and simply scroll to that section. */
$attrTypeFromPost = static function (array $src) use ($tabToType): ?string {
    $t = (string)($src['attr_type'] ?? '');
    if (isset(DIEVON_ATTR_TYPES[$t])) { return $t; }
    if (isset($tabToType[$t]))        { return $tabToType[$t]; }
    return null;
};

// Only used to highlight the sidebar and to honour an old ?type= link.
$type      = (string)($_GET['type'] ?? 'colors');
if (!isset($tabToType[$type])) { $type = 'colors'; }
$activeTab = $type;

/* Which list is open when the page draws.
   ────────────────────────────────────────────────────────────────────────────
   After an add, a delete or a rename the page reloads, and landing back on
   Colours when you were half way through tidying Patterns is the kind of small
   rudeness that makes a screen tiring. $openType is set to whichever list was
   acted on, further down, so you come back where you were. */
$openType = $tabToType[$type];
$successMsg = '';
$errorMsg   = '';

// Auto create attributes table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `product_attributes` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `attr_type` VARCHAR(20) NOT NULL DEFAULT 'color',   -- not an ENUM: see config/db.php
        `name` VARCHAR(100) NOT NULL,
        `code` VARCHAR(50) DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (PDOException $e) {}

// Handle Add/Delete (POST only with CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_attr']) && !verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    $errorMsg = "Security validation failed (Invalid CSRF token).";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_attr'])) {
    // Which list this add belongs to comes from the form, since every list has
    // its own add form on the one page now.
    $attrType = $attrTypeFromPost($_POST) ?? 'color';
    $attrMeta = DIEVON_ATTR_TYPES[$attrType];
    $openType = $attrType;   // come back to the list that was just changed
    $name = trim($_POST['name'] ?? '');
    $code = trim($_POST['code'] ?? '');
    if ($name) {
        try {
            /* No duplicates, compared without case.
               ────────────────────────────────────────────────────────────────
               There is no unique index on this table, and the list ended up
               holding 'red' twice. A duplicate is not cosmetic here: this list
               is now the only source of colours for every product, it is what
               the shop's filter names, and two rows spelling one colour is
               exactly the drift the whole change exists to remove. */
            /* A comma is the separator Color Way uses to hold more than one
               colour on a product, so a colour NAMED with a comma would split
               into two that do not exist and match nothing. */
            if ($attrType === 'color' && str_contains($name, ',')) {
                throw new RuntimeException('A colour name cannot contain a comma — the comma is what separates two colours on a product.');
            }
            $clash = $pdo->prepare("SELECT name FROM product_attributes
                                     WHERE attr_type = :type AND LOWER(TRIM(name)) = LOWER(TRIM(:name)) LIMIT 1");
            $clash->execute(['type' => $attrType, 'name' => $name]);
            if ($existing = $clash->fetchColumn()) {
                throw new RuntimeException("'{$existing}' is already on the list.");
            }
            $stmt = $pdo->prepare("INSERT INTO product_attributes (attr_type, name, code) VALUES (:type, :name, :code)");
            $stmt->execute(['type' => $attrType, 'name' => $name, 'code' => $code]);
            $successMsg = ucfirst($attrType) . " '{$name}' created successfully.";
        } catch (RuntimeException $e) {
            $errorMsg = $e->getMessage();
        } catch (PDOException $e) {
            $errorMsg = "Error adding attribute: " . $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = 'Security token expired. Please refresh and try again.';
    } else {
        $delId = (int)$_POST['delete'];
        $attrType = $attrTypeFromPost($_POST) ?? 'color';
        $openType = $attrType;
        try {
            /* Read the name and its users BEFORE the row goes, so the message can
               say what actually happened.
               ────────────────────────────────────────────────────────────────
               "Item deleted successfully." was true and still looked like a lie.
               Deleting a value that products are tagged with removes it from the
               list and nothing else — the products keep the tag — so the value
               reappears seconds later in the "in use but not on this list" box
               further down the SAME page. Nothing said it would, so the only
               reading available was that Delete had not worked, and the natural
               response was to press it again.

               Now the sentence names the garments that kept the value and points
               at the box it moved to, which is the difference between a screen
               that looks broken and one that is merely honest about what a list
               entry is. */
            $delName = '';
            $st = $pdo->prepare("SELECT name FROM product_attributes WHERE id = :id");
            $st->execute(['id' => $delId]);
            $delName = trim((string)($st->fetchColumn() ?: ''));

            $stillUsing = [];
            if ($delName !== '') {
                $usage = dievonAttributeUsage($pdo, $attrType);
                $stillUsing = $usage[strtolower($delName)]['products'] ?? [];
            }

            $pdo->prepare("DELETE FROM product_attributes WHERE id = :id")->execute(['id' => $delId]);

            if ($stillUsing) {
                $names = array_values($stillUsing);
                $shown = implode(', ', array_slice($names, 0, 3));
                if (count($names) > 3) { $shown .= ' and ' . (count($names) - 3) . ' more'; }
                $successMsg = "'{$delName}' removed from the list, but "
                            . count($names) . ' product' . (count($names) === 1 ? '' : 's')
                            . " still carr" . (count($names) === 1 ? 'ies' : 'y') . " it: {$shown}. "
                            . "It now appears below under \u{201C}in use but not on this list\u{201D} — "
                            . "retag those products to clear it completely.";
            } else {
                $successMsg = $delName !== ''
                    ? "'{$delName}' deleted. No product was using it."
                    : "Item deleted successfully.";
            }
        } catch (PDOException $e) {
            $errorMsg = "Error deleting attribute: " . $e->getMessage();
        }
    }
}

/* ── Reconciliation: colours products use that this list does not have ───────
 *
 * The product form only offers this list now, but products saved BEFORE that
 * rule hold whatever was typed at the time. Those values still feed the shop's
 * colour filter, so until they are dealt with the filter keeps showing them.
 *
 * Deliberately not a migration script with names baked into it. This machine's
 * database and the live shop's database have different strays — the QA colours
 * here will not exist there, and live will have its own — so the panel reads
 * whichever database it is running in and the shopkeeper decides, per row, on
 * each site. Nothing is created or rewritten without a press.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reconcile'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = 'Security token expired. Please refresh and try again.';
    } else {
        $mode  = (string)($_POST['reconcile'] ?? '');
        $stray = trim((string)($_POST['stray'] ?? ''));
        $attrType = $attrTypeFromPost($_POST) ?? 'color';
        $attrMeta = DIEVON_ATTR_TYPES[$attrType];
        $openType = $attrType;

        if ($mode === 'add_all') {
            /* The five managed lists start empty on a shop whose products were
               built before them, so every picker in the product form offers
               exactly one tick box -- the garment's own value -- and a second
               fabric or occasion cannot be chosen at all until the list is
               filled. Add-one-at-a-time meant a round trip per value here, then
               the whole set again on the live shop, which has its own lists. */
            try {
                $clash = $pdo->prepare("SELECT name FROM product_attributes
                                         WHERE attr_type = :t AND LOWER(TRIM(name)) = LOWER(TRIM(:name)) LIMIT 1");
                $ins   = $pdo->prepare("INSERT INTO product_attributes (attr_type, name, code) VALUES (:t, :name, '')");
                $added = [];
                $already = [];
                foreach (dievonStrayAttributes($pdo, $attrType) as $sc) {
                    $val = trim((string)($sc['value'] ?? ''));
                    if ($val === '') { continue; }
                    $clash->execute(['t' => $attrType, 'name' => $val]);
                    if ($clash->fetchColumn()) { $already[] = $val; continue; }
                    $ins->execute(['t' => $attrType, 'name' => $val]);
                    $added[] = $val;
                }
                if (!$added && !$already) {
                    $errorMsg = 'Nothing left to add.';
                } else {
                    /* Named, never counted -- a bare number here reads as a bug
                       when one value silently does not appear on the list. */
                    $successMsg = $added
                        ? 'Added to the ' . strtolower($attrMeta['label']) . ' list: ' . implode(', ', $added) . '.'
                        : '';
                    if ($already) {
                        $successMsg .= ($successMsg ? ' ' : '')
                            . 'Already on the list, left alone: ' . implode(', ', $already) . '.';
                    }
                }
            } catch (PDOException $e) {
                $errorMsg = 'Could not add those ' . strtolower($attrMeta['label']) . ' values: ' . $e->getMessage();
            }
        } elseif ($stray === '') {
            $errorMsg = 'Nothing selected.';
        } elseif ($mode === 'add') {
            try {
                $clash = $pdo->prepare("SELECT name FROM product_attributes
                                         WHERE attr_type = :t AND LOWER(TRIM(name)) = LOWER(TRIM(:name)) LIMIT 1");
                $clash->execute(['t' => $attrType, 'name' => $stray]);
                if ($clash->fetchColumn()) {
                    throw new RuntimeException("'{$stray}' is already on the list.");
                }
                $ins = $pdo->prepare("INSERT INTO product_attributes (attr_type, name, code) VALUES (:t, :name, '')");
                $ins->execute(['t' => $attrType, 'name' => $stray]);
                $successMsg = "'{$stray}' added to the " . strtolower($attrMeta['label'])
                               . " list. The products already using it now match the filter.";
            } catch (RuntimeException $e) {
                $errorMsg = $e->getMessage();
            } catch (PDOException $e) {
                $errorMsg = 'Could not add that ' . strtolower($attrMeta['label']) . ': ' . $e->getMessage();
            }
        } elseif ($mode === 'rename') {
            $to = trim((string)($_POST['rename_to'] ?? ''));
            if ($to === '') {
                $errorMsg = 'Choose the colour to rename it to.';
            } else {
                /* One transaction across all three columns. A rename that
                   updated the variants and then failed on products would leave
                   the same garment under two colours, which is worse than the
                   stray it was fixing.

                   Only the NAME moves. product_colors.id is untouched, so every
                   size, stock number, image and price override stays attached
                   to exactly the colour it was on, and order_items keeps its own
                   copy of what was actually bought. */
                try {
                    $pdo->beginTransaction();
                    $moved = 0;

                    if ($attrType === 'color') {
                        /* Colour spans three columns, and Colour Way can hold a
                           LIST, so only the matching PART moves: "Black,Green"
                           must come back "Black,Emerald Green". An exact-match
                           UPDATE would never touch it, and a blind string
                           replace would corrupt "Emerald Green" while renaming
                           "Green". */
                        $rows = $pdo->query("SELECT id, color_way FROM products
                                              WHERE color_way IS NOT NULL AND color_way <> ''");
                        $setWay = $pdo->prepare("UPDATE products SET color_way = :cw WHERE id = :id");
                        foreach ($rows as $row) {
                            $parts = dievonColorWayList((string)$row['color_way']);
                            $hit = false;
                            foreach ($parts as $i2 => $part) {
                                if (strcasecmp(trim($part), $stray) === 0) { $parts[$i2] = $to; $hit = true; }
                            }
                            if (!$hit) { continue; }
                            // unique(), or renaming one half onto the other leaves "Green,Green".
                            $setWay->execute(['cw' => implode(',', array_values(array_unique($parts))), 'id' => (int)$row['id']]);
                            $moved++;
                        }
                        $a = $pdo->prepare("UPDATE products SET color = :to WHERE LOWER(TRIM(color)) = LOWER(TRIM(:from))");
                        $a->execute(['to' => mb_substr($to, 0, 50), 'from' => $stray]);
                        $c = $pdo->prepare("UPDATE product_colors SET color_name = :to WHERE LOWER(TRIM(color_name)) = LOWER(TRIM(:from))");
                        $c->execute(['to' => $to, 'from' => $stray]);
                        $moved += $a->rowCount() + $c->rowCount();
                    } else {
                        /* Sleeve, Neck and Pattern are one plain column each and
                           never a list — one garment, one sleeve. The column name
                           comes from DIEVON_ATTR_TYPES and is checked against
                           [a-z_] before it goes near the SQL, because a column
                           name cannot be a bound parameter. */
                        $col = $attrMeta['columns'][0] ?? '';
                        $field = str_starts_with($col, 'products.') ? substr($col, strlen('products.')) : '';
                        if ($field === '' || !preg_match('/^[a-z_]+$/', $field)) {
                            throw new RuntimeException('That list cannot be renamed automatically.');
                        }
                        /* Rename the VALUE inside the column, not the column.
                           ────────────────────────────────────────────────────
                           This matched the whole field, so it only ever worked
                           while a product held exactly one value. A garment
                           tagged "Cotton, Velvet" did not match "Velvet" at
                           all: the rename reported 0 rows, changed nothing, and
                           left the stray exactly where it was — the one screen
                           whose entire job is clearing strays, quietly unable
                           to clear them. Occasion has held lists all along, so
                           it never worked there; the other four joined it when
                           the product form gained multi-select.
                           Colour has always done it this way (see the color_way
                           branch above); this is the same walk for the rest. */
                        $sel = $pdo->prepare("SELECT id, `$field` AS v FROM products
                                               WHERE `$field` IS NOT NULL AND `$field` <> ''");
                        $sel->execute();
                        $set = $pdo->prepare("UPDATE products SET `$field` = :v WHERE id = :id");
                        $sep = dievonAttrListSeparator($attrType);
                        foreach ($sel as $row) {
                            $parts = dievonSplitAttrList($attrType, (string)$row['v']);
                            $hit = false;
                            foreach ($parts as $i2 => $part) {
                                if (strcasecmp(trim($part), $stray) === 0) { $parts[$i2] = $to; $hit = true; }
                            }
                            if (!$hit) { continue; }
                            /* unique(), or renaming one value onto another the
                               product already carries leaves "Cotton, Cotton". */
                            $seen = []; $out = [];
                            foreach ($parts as $part) {
                                $k = mb_strtolower(trim($part));
                                if ($k === '' || isset($seen[$k])) { continue; }
                                $seen[$k] = true; $out[] = trim($part);
                            }
                            $set->execute(['v' => implode($sep, $out), 'id' => (int)$row['id']]);
                            $moved++;
                        }
                    }
                    $pdo->commit();
                    /* The reassurance has to be true for the list being renamed.
                       "Sizes, stock, images and price overrides" is the COLOUR
                       story — those hang off product_colors.id — and printing it
                       after a pattern rename claims something that was never at
                       risk, which is worse than saying nothing. */
                    $successMsg = "'{$stray}' renamed to '{$to}' on {$moved} row(s). "
                                . ($attrType === 'color'
                                    ? 'Sizes, stock, images and price overrides are untouched — they belong to the colour, not its name.'
                                    : 'Only the label changed; nothing else about those products moved.');
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) { $pdo->rollBack(); }
                    $errorMsg = 'Rename failed, nothing was changed: ' . $e->getMessage();
                }
            }
        }
    }
}

/* Loaded per list by the render loop below, not once for a single type. */

/* One loader, called per list by the render loop.
   Alphabetical: this was id DESC, so a list read in the order things happened
   to be typed, backwards — and a list of allowed values exists to be looked up
   by name. No sort_order column on this table, so nothing manual is overridden. */
$loadList = static function (PDO $pdo, string $t): array {
    try {
        $stmt = $pdo->prepare("SELECT * FROM product_attributes WHERE attr_type = :type ORDER BY name ASC, id ASC");
        $stmt->execute(['type' => $t]);
        return $stmt->fetchAll();
    } catch (PDOException $e) { return []; }
};

$hideHeaderTitle = true;
require_once __DIR__ . '/includes/header.php';
// Role check. The nav hides links this account cannot use, but the URL is
// still typeable and a handler still accepts a POST — so permission is
// decided here, on the server, every time.
require_once __DIR__ . '/../config/config.php';

?>

<div class="admin-page-header" style="margin-bottom: 18px;">
    <div>
        <h1 class="admin-page-title">🎛️ Filters &amp; Attributes</h1>
        <p class="admin-page-subtitle">
            The lists a product can choose from, and the filters a shopper sees on the shop page.
            Products pick from these lists; they cannot type their own. Every list on one screen,
            because they are the same idea four times and are usually tidied together.
        </p>
    </div>
</div>

<?php if ($successMsg): ?>
    <?= dvNotice(htmlspecialchars($successMsg), 'success') ?>
<?php endif; ?>
<?php if ($errorMsg): ?>
    <?= dvNotice(htmlspecialchars($errorMsg), 'danger') ?>
<?php endif; ?>

<?php /* Real tabs, and real links underneath them.
         ────────────────────────────────────────────────────────────────────
         Each is an <a href="?type=…">, so with no JavaScript at all pressing
         one loads that list the ordinary way. The script below intercepts and
         swaps instantly instead, which is the whole point: four lists on one
         screen without scrolling past three to reach the fourth.

         The number is how many values products use that the list does not
         have — the only thing on this page asking to be dealt with, so it is
         visible from every tab rather than only from the one it belongs to. */ ?>
<div role="tablist" style="display:flex; gap:4px; margin-bottom:22px;
     border-bottom:1px solid var(--border-light); flex-wrap:wrap;">
    <?php foreach (DIEVON_ATTR_TYPES as $tKey => $tMeta):
        $pending = 0;
        try { $pending = count(dievonStrayAttributes($pdo, $tKey)); } catch (Throwable $e) {}
        $isOn = ($tKey === $openType);
    ?>
        <a href="attributes.php?type=<?= htmlspecialchars($tMeta['tab']) ?>"
           role="tab" aria-selected="<?= $isOn ? 'true' : 'false' ?>"
           data-attr-tab="<?= htmlspecialchars($tKey) ?>"
           style="padding:10px 18px; font-size:13.5px; text-decoration:none; white-space:nowrap;
                  font-weight:<?= $isOn ? '700' : '500' ?>;
                  color:<?= $isOn ? 'var(--color-primary)' : 'var(--text-muted)' ?>;
                  border-bottom:2px solid <?= $isOn ? 'var(--color-primary)' : 'transparent' ?>;
                  margin-bottom:-1px;">
            <?= htmlspecialchars($tMeta['plural']) ?>
            <?php if ($pending): ?>
                <span style="display:inline-block; min-width:18px; padding:1px 6px; margin-left:5px; border-radius:9px;
                             background:#c9a227; color:#fff; font-size:11px; font-weight:700;"><?= (int)$pending ?></span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</div>

<?php
/* One section per list. Identical markup for each — the only differences are
   the wording, and the colour picker, which only colours have. */
foreach (DIEVON_ATTR_TYPES as $attrType => $attrMeta):
    $attributes  = $loadList($pdo, $attrType);
    $strayColors = [];
    try { $strayColors = dievonStrayAttributes($pdo, $attrType); } catch (Throwable $e) {}
    /* Who is using each listed value. Without this the Delete button is a
       guess: the list alone cannot say whether removing a value is harmless
       or leaves garments tagged with something no longer on it. */
    $attrUsage = [];
    try { $attrUsage = dievonAttributeUsage($pdo, $attrType); } catch (Throwable $e) {}
    /* product_attributes is unique on id ONLY — nothing stops the same name
       being on a list twice, and older rows predate the clash check the add
       form does now. Two identical rows are indistinguishable in the table, so
       deleting one leaves what looks like the same row still sitting there and
       Delete appears to do nothing. Counted here so the row can say it. */
    $dupeCount = [];
    foreach ($attributes as $dRow) {
        $dKey = mb_strtolower(trim((string)$dRow['name']));
        $dupeCount[$dKey] = ($dupeCount[$dKey] ?? 0) + 1;
    }
    $addHint = [
        'color'   => 'Colour name (e.g. Emerald Green, Rose Gold)',
        'sleeve'  => 'Sleeve (e.g. Three-quarter Sleeves, Long Sleeve)',
        'neck'    => 'Neckline (e.g. Round Neck, V-neck)',
        'pattern' => 'Pattern (e.g. Floral Embroidered, Ikat-inspired Print)',
    ][$attrType] ?? 'Name';
?>

<section id="list-<?= htmlspecialchars($attrType) ?>" data-attr-section="<?= htmlspecialchars($attrType) ?>"
         style="margin-bottom:38px;<?= $attrType === $openType ? '' : ' display:none;' ?>">

    <h2 style="font-size:18px; font-weight:700; color:var(--text-primary); margin:0 0 4px;">
        <?= htmlspecialchars($attrMeta['plural']) ?>
        <span style="font-size:13px; font-weight:500; color:var(--text-muted);">
            — <?= count($attributes) ?> on the list
        </span>
    </h2>
    <p style="font-size:12.5px; color:var(--text-muted); margin:0 0 14px; max-width:74ch;">
        The <?= htmlspecialchars(strtolower($attrMeta['label'])) ?> options a product can be given,
        and the <?= htmlspecialchars(strtolower($attrMeta['label'])) ?> filter a shopper sees.
    </p>

    <div class="glass-panel form-section" style="margin-bottom: 16px;">
        <form action="attributes.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <?php /* Which list this form belongs to. Every list has its own add
                     form on this page, so the type cannot come from the URL. */ ?>
            <input type="hidden" name="attr_type" value="<?= htmlspecialchars($attrType) ?>">
            <div class="form-row" style="display: grid; grid-template-columns: 1fr auto auto; gap: 12px; align-items: center;">
                <div class="form-group" style="margin:0;">
                    <input type="text" name="name" class="form-control" placeholder="<?= htmlspecialchars($addHint) ?>" required>
                </div>
                <?php if ($attrType === 'color'): ?>
                    <div class="form-group" style="margin:0;">
                        <input type="color" name="code" value="#991b1b" style="width: 50px; height: 42px; padding: 2px; border: 1px solid var(--border-strong); cursor: pointer;">
                    </div>
                <?php endif; ?>
                <button type="submit" name="add_attr" class="btn-primary" style="padding: 10px 20px; font-size: 14px;">
                    Add <?= htmlspecialchars(strtolower($attrMeta['label'])) ?>
                </button>
            </div>
        </form>
    </div>

    <?php if ($strayColors): ?>
    <div class="glass-panel" style="padding:0; overflow:hidden; margin-bottom:16px; border:1px solid #c9a227;">
        <div style="padding:16px 24px; border-bottom:1px solid var(--border-light); background:rgba(201,162,39,0.08);">
            <div style="font-weight:700; font-size:15px; color:var(--text-primary);">
                ⚠️ <?= count($strayColors) ?> <?= htmlspecialchars(strtolower($attrMeta['label'])) ?>
                value<?= count($strayColors) === 1 ? '' : 's' ?> in use but not on this list
            </div>
            <div style="font-size:12.5px; color:var(--text-muted); margin-top:6px; line-height:1.6; max-width:74ch;">
                These were typed into products before this list existed. Each still appears in the shop's
                filter as its own entry, matching only the products spelling it exactly this way.
                <strong>Add</strong> keeps the value and puts it on the list.
                <strong>Rename</strong> moves those products onto a value you already have.
                Renaming changes the label only — nothing else about a product moves.
            </div>
            <?php /* The whole backlog in one click. Keeps every value exactly as
                     typed -- this only puts them on the list, no product changes. */ ?>
            <form method="POST" action="attributes.php" style="margin-top:12px;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                <input type="hidden" name="attr_type" value="<?= htmlspecialchars($attrType) ?>">
                <button type="submit" name="reconcile" value="add_all" class="btn-primary"
                        style="padding:7px 14px; font-size:12.5px;"
                        data-confirm-rename="Add all <?= count($strayColors) ?> <?= htmlspecialchars(strtolower($attrMeta['label'])) ?> value<?= count($strayColors) === 1 ? '' : 's' ?> to the list? Products are not changed.">
                    Add all <?= count($strayColors) ?> to the list
                </button>
            </form>
        </div>
        <div class="table-wrapper">
            <table class="data-table" style="width:100%;">
                <thead>
                    <tr><th>Value found</th><th>Used by</th><th style="width:330px; text-align:right;">Fix</th></tr>
                </thead>
                <tbody>
                <?php foreach ($strayColors as $sc): ?>
                    <tr>
                        <td style="font-weight:600;"><?= htmlspecialchars($sc['value']) ?></td>
                        <td style="font-size:12px; color:var(--text-muted);">
                            <?php /* Named, not counted. A bare "3 products" cannot be acted on. */ ?>
                            <?= htmlspecialchars(implode(', ', array_slice(array_values($sc['products']), 0, 3))) ?>
                            <?php if (count($sc['products']) > 3): ?>
                                <em>and <?= count($sc['products']) - 3 ?> more</em>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;">
                            <form method="POST" action="attributes.php" style="display:inline-flex; gap:6px; align-items:center; flex-wrap:wrap; justify-content:flex-end;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                                <input type="hidden" name="attr_type" value="<?= htmlspecialchars($attrType) ?>">
                                <input type="hidden" name="stray" value="<?= htmlspecialchars($sc['value']) ?>">
                                <button type="submit" name="reconcile" value="add" class="btn-secondary" style="padding:5px 10px; font-size:12px;">
                                    Add to list
                                </button>
                                <?php if ($attributes): ?>
                                    <select name="rename_to" class="form-control" style="width:150px; padding:5px 8px; font-size:12px;">
                                        <option value="">Rename to&hellip;</option>
                                        <?php foreach ($attributes as $a): ?>
                                            <option value="<?= htmlspecialchars($a['name']) ?>"><?= htmlspecialchars($a['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" name="reconcile" value="rename" class="btn-secondary" style="padding:5px 10px; font-size:12px;"
                                            data-confirm-rename="Rename <?= htmlspecialchars($sc['value'], ENT_QUOTES) ?> on <?= count($sc['products']) ?> product(s)?&#10;&#10;Only the label changes. Nothing else about a product moves.">
                                        Rename
                                    </button>
                                <?php endif; ?>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <div class="glass-panel" style="padding:0; overflow:hidden;">
        <div class="table-wrapper">
            <table class="data-table" style="width: 100%;">
                <thead>
                    <tr>
                        <th style="width: 60px;">ID</th>
                        <th><?= htmlspecialchars($attrMeta['label']) ?></th>
                        <?php if ($attrType === 'color'): ?><th>Colour Code</th><?php endif; ?>
                        <th>Used by</th>
                        <th style="width: 100px; text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($attributes)): ?>
                        <tr><td colspan="<?= $attrType === 'color' ? 5 : 4 ?>" style="padding: 20px; text-align: center; color: var(--text-muted);">
                            Nothing on this list yet. Until one value is added, products keep whatever they already
                            hold and nothing is refused — so there is no rush and no way to be locked out.
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($attributes as $a): ?>
                            <tr>
                                <td><?= (int)$a['id'] ?></td>
                                <td style="font-weight:600;">
                                    <?= htmlspecialchars($a['name']) ?>
                                    <?php $dN = $dupeCount[mb_strtolower(trim((string)$a['name']))] ?? 1; ?>
                                    <?php if ($dN > 1): ?>
                                        <span style="display:block; font-weight:400; font-size:11px; color:#b45309;">
                                            This name is on the list <?= (int)$dN ?> times (ids differ) &mdash;
                                            deleting one leaves the others, which look identical. Delete each.
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <?php if ($attrType === 'color'): ?>
                                    <td>
                                        <?php $hex = trim((string)($a['code'] ?? '')); ?>
                                        <?php if (preg_match('/^#[0-9a-fA-F]{6}$/', $hex)): ?>
                                            <span style="display:inline-block; width:16px; height:16px; vertical-align:middle;
                                                         border:1px solid var(--border-light); background:<?= htmlspecialchars($hex) ?>;"></span>
                                            <span style="font-size:12px; color:var(--text-muted);"><?= htmlspecialchars($hex) ?></span>
                                        <?php else: ?>
                                            <span style="font-size:12px; color:var(--text-muted);"><?= $hex !== '' ? htmlspecialchars($hex) : '—' ?></span>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                <?php
                                   /* Named, not counted — the same rule the stray table
                                      below follows. "3 products" cannot be acted on; three
                                      garment names can. */
                                   $uses = $attrUsage[strtolower(trim((string)$a['name']))]['products'] ?? [];
                                   $useNames = array_values($uses);
                                   /* Nothing carries this exact spelling — but is the same value
                                      sitting on the products under a different one? Saying "safe to
                                      delete" without checking is how the wrong row gets deleted while
                                      the chip it was blamed for stays in the shop. */
                                   $nearMiss = [];
                                   if (!$useNames) {
                                       $loose = dievonAttributeLooseKey((string)$a['name']);
                                       if ($loose !== '') {
                                           foreach ($attrUsage as $uKey => $uRow) {
                                               if ($uKey === strtolower(trim((string)$a['name']))) { continue; }
                                               if (dievonAttributeLooseKey($uRow['value']) === $loose) {
                                                   $nearMiss[] = ['value' => $uRow['value'], 'n' => count($uRow['products'])];
                                               }
                                           }
                                       }
                                   }
                                ?>
                                <td style="font-size:12px; color:var(--text-muted);">
                                    <?php if (!$useNames && $nearMiss): ?>
                                        <span style="color:#b45309;">
                                            No product uses this exact spelling, but
                                            <?php foreach ($nearMiss as $i => $nm): ?><?= $i ? ' and ' : ' ' ?><strong><?= htmlspecialchars($nm['value']) ?></strong>
                                                is on <?= (int)$nm['n'] ?> product<?= $nm['n'] === 1 ? '' : 's' ?><?php endforeach; ?>.
                                            Deleting this row only removes the spelling below from the
                                            product form; it will not touch those garments or the shop
                                            filter. Open them from the row above and change the value there.
                                        </span>
                                    <?php elseif (!$useNames): ?>
                                        <span style="color:var(--text-muted);">Not used &mdash; safe to delete</span>
                                    <?php else: ?>
                                        <?php /* Every one, linked, and never "and 4 more".
                                                 Clearing a value means opening each garment that
                                                 carries it, so a name that is only counted is a
                                                 product you cannot go and fix. */ ?>
                                        <?php $i = 0; foreach ($uses as $pid => $pname): ?><?= $i++ ? ', ' : '' ?><a href="product_form.php?id=<?= (int)$pid ?>" style="color:inherit; text-decoration:underline;"><?= htmlspecialchars($pname) ?></a><?php endforeach; ?>
                                        <span style="white-space:nowrap;">(<?= count($useNames) ?>)</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right;">
                                    <form method="POST" action="attributes.php" style="display:inline;"
                                          onsubmit="return dvConfirmForm(this,'<?= $useNames ? htmlspecialchars(count($useNames) . ' product' . (count($useNames)===1?'':'s') . ' still use' . (count($useNames)===1?'s':'') . ' this. Deleting removes it from the list only — those products keep the value and it will reappear below under “in use but not on this list”. Continue?', ENT_QUOTES) : 'Delete this entry? No product is using it.' ?>');">
                                        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                        <input type="hidden" name="attr_type" value="<?= htmlspecialchars($attrType) ?>">
                                        <button type="submit" name="delete" value="<?= (int)$a['id'] ?>" class="btn-danger" style="padding:5px 10px; font-size:12px;">
                                            Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<?php endforeach; ?>

<script>
/* Swap tabs without a reload. Every list is already in the page, so this only
   shows one and hides the rest — nothing is fetched and nothing can half-load.

   The tabs are ordinary links, so this is an enhancement rather than the
   mechanism: with the script gone they still work, just with a page load. The
   URL is kept in step with replaceState so a refresh, or a link copied out of
   the address bar, opens the same list. */
(function () {
    var tabs = [].slice.call(document.querySelectorAll('[data-attr-tab]'));
    if (!tabs.length) { return; }

    function show(key) {
        [].forEach.call(document.querySelectorAll('[data-attr-section]'), function (sec) {
            sec.style.display = (sec.getAttribute('data-attr-section') === key) ? '' : 'none';
        });
        tabs.forEach(function (t) {
            var on = t.getAttribute('data-attr-tab') === key;
            t.style.fontWeight = on ? '700' : '500';
            t.style.color = on ? 'var(--color-primary)' : 'var(--text-muted)';
            t.style.borderBottomColor = on ? 'var(--color-primary)' : 'transparent';
            t.setAttribute('aria-selected', on ? 'true' : 'false');
        });
    }

    tabs.forEach(function (t) {
        t.addEventListener('click', function (e) {
            e.preventDefault();
            var key = t.getAttribute('data-attr-tab');
            show(key);
            try { history.replaceState(null, '', t.getAttribute('href')); } catch (err) {}
            window.scrollTo(0, 0);
        });
    });
})();
</script>

<script>
/* Uses the shop's dialog when it is there and the browser's box only if it is
   not, the same fallback countries.php and the product form use.

   requestSubmit(button) rather than form.submit(): the pressed button carries
   name="reconcile" value="rename", and a plain submit() drops the submitter, so
   the handler would receive no action and silently do nothing. */
document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-confirm-rename]');
    if (!btn || btn.dataset.confirmed === '1') { return; }
    e.preventDefault();
    var ask = (typeof window.dievonConfirm === 'function')
        ? window.dievonConfirm
        : function (m) { return Promise.resolve(window.confirm(m)); };
    Promise.resolve(ask(btn.getAttribute('data-confirm-rename'))).then(function (ok) {
        if (!ok) { return; }
        btn.dataset.confirmed = '1';
        var form = btn.form;
        if (form && typeof form.requestSubmit === 'function') { form.requestSubmit(btn); }
        else { btn.click(); }
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
