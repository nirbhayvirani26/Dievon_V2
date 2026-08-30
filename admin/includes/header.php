<?php
// ============================================================
//  Dievon – Admin Dashboard
//  Tabs: Orders | Products | Gallery | Categories
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: ' . SITE_URL . '/admin/login.php');
    exit;
}

/* ── What this signed-in admin is actually allowed to open ────────────────
   Four roles existed — Owner, Catalogue manager, Order staff, Editor — with
   capabilities stored against each, and adminCan()/requireAdminCapability()
   fully written. But only TWO of fifty-eight admin pages ever called them, so
   the roles were a description rather than a rule: an Editor with no
   capabilities at all could open Orders, Refunds, Customers or Settings simply
   by typing the address. Harmless while the shop has one owner; a real hole the
   day a colleague gets an account.

   Enforced HERE because every one of these pages already passes through this
   header to be drawn at all — one gate instead of fifty-eight edits, and a page
   added later cannot forget to guard itself.

   Pages are named explicitly and anything unlisted falls back to
   settings.manage, so a NEW admin page is closed to staff until somebody
   decides otherwise. Failing closed is the only safe default for a list that
   will grow. The Owner role holds '*' and is unaffected by any of this. */
$dvAdminPageCaps = [
    // Catalogue
    'products.php'            => 'catalogue.view',
    'product_form.php'        => 'catalogue.manage',
    'categories.php'          => 'catalogue.manage',
    'attributes.php'          => 'catalogue.manage',
    'brands.php'              => 'catalogue.manage',
    'suppliers.php'           => 'suppliers.manage',
    'reviews.php'             => 'catalogue.manage',
    'promo.php'               => 'catalogue.manage',
    'backfill_size_codes.php' => 'catalogue.manage',
    'repair_category_tree.php'=> 'catalogue.manage',
    // Orders and customer service
    'orders.php'              => 'orders.view',
    'returns.php'             => 'returns.manage',
    'support_tickets.php'     => 'tickets.manage',
    'customers.php'           => 'customers.view',
    'newsletter.php'          => 'customers.view',
    // Money — deliberately the tightest
    'gst_report.php'          => 'refunds.manage',
    // Media
    'media.php'               => 'media.manage',
    'media_cleanup.php'       => 'media.manage',
    'media_missing.php'       => 'media.manage',
    'repair_orphan_media.php' => 'media.manage',
    'lookbook.php'            => 'media.manage',
    // Content
    'banners.php'             => 'content.manage',
    'blog.php'                => 'content.manage',
    'seo.php'                 => 'seo.manage',
    'size_guide.php'          => 'sizeguide.manage',
    'size_guide_cleanup.php'  => 'sizeguide.manage',
    // Owner-level: staff, settings, infrastructure, logs
    'dashboard.php'           => 'orders.view',
    'settings.php'            => 'settings.manage',
    'admin_users.php'         => 'settings.manage',
    'roles.php'               => 'settings.manage',
    'countries.php'           => 'settings.manage',
    'backup.php'              => 'settings.manage',
    'logs.php'                => 'settings.manage',
    'email_logs.php'          => 'settings.manage',
    'health.php'              => 'settings.manage',
];
$dvPage = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
requireAdminCapability($dvAdminPageCaps[$dvPage] ?? 'settings.manage');

// ?? rather than a plain assignment.
//
// These two lines used to blank both messages unconditionally — and every page
// sets its own BEFORE including this header, so the assignment ran afterwards
// and wiped it. On twelve screens (Categories, Banners, Blog, Products, SEO,
// Attributes, Customers, Homepage Builder, Media Cleanup, Size Guide Cleanup,
// Repair Category Tree, Backfill Size Codes) neither a green "saved" nor a red
// error ever appeared: a failed save, an expired security token and a successful
// save all looked identical, which is nothing happening.
//
// The declaration still has to stay, so that a page which sets neither leaves
// the header's own markup below reading a defined variable.
$successMsg = $successMsg ?? '';
$errorMsg   = $errorMsg   ?? '';

// ── Handle product delete (POST only with CSRF) ─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_product' && isset($_POST['id'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errorMsg = 'Security validation failed (Invalid CSRF token).';
    } else {
        $delId = (int)$_POST['id'];
        try {
            $pdo->prepare("UPDATE products SET is_deleted = 1, available = 0 WHERE id = :id")->execute(['id' => $delId]);
            logAdminAction($_SESSION['admin_id'] ?? 1, 'soft_delete_product', "Soft-deleted product ID $delId");
            $successMsg = 'Product archived (soft-deleted) successfully.';
        } catch (PDOException $e) {
            $errorMsg = 'Could not delete product: ' . $e->getMessage();
        }
    }
}

// ── URL success messages ────────────────────────────────
if (isset($_GET['order_deleted'])) $successMsg = '✅ Order deleted successfully.';

// ── URL success messages ──────────────────────────────────
if (isset($_GET['product_added']))   $successMsg = '✅ New product added successfully!';
if (isset($_GET['product_updated'])) $successMsg = '✅ Product updated successfully!';

// ── Load stats ────────────────────────────────────────────
$totalOrders   = $pdo->query("SELECT COUNT(*) FROM orders WHERE COALESCE(is_deleted,0) = 0")->fetchColumn();
$pendingOrders = $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'Pending' AND COALESCE(is_deleted,0) = 0")->fetchColumn();
// Money actually kept: what was charged, less anything given back.
//
// This summed total_price alone, so a fully refunded order still counted as
// revenue for ever. An owner reconciling the dashboard against the bank would
// have found a figure that could only ever go up.
/* Revenue, grouped by the currency each order was actually taken in.
   ────────────────────────────────────────────────────────────────────────────
   SUM(total_price) across every order has no currency in it. While the shop
   sells only in India that is harmless — every row is rupees — but the first
   foreign order turns it into arithmetic on unlike things: a ₹4,000 sale and a
   £50 sale add up to "4,050" of nothing, and the tile shows it with whichever
   symbol happens to be current.

   Each order already records its own currency, so the fix is to keep them apart
   rather than convert. Converting would be worse: it rewrites what was earned
   every time a rate moves, and the figure that matters is the one banked.

   $totalRevenue stays as the HOME-currency figure so every existing caller and
   the tile below keep working unchanged; $revenueByCurrency carries the rest. */
$revenueByCurrency = [];
try {
    $revRows = $pdo->query(
        "SELECT COALESCE(NULLIF(currency, ''), 'INR') AS cur,
                COALESCE(SUM(total_price - COALESCE(refunded_amount, 0)), 0) AS total
           FROM orders
          WHERE payment_status IN ('Paid', 'Cash') AND COALESCE(is_deleted,0) = 0
       GROUP BY cur
       ORDER BY total DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($revRows as $rr) { $revenueByCurrency[strtoupper($rr['cur'])] = (float)$rr['total']; }
} catch (Throwable $e) { $revenueByCurrency = []; }

$homeCurrency  = strtoupper((string)(homeCountryRow()['currency_code'] ?? 'INR'));
$totalRevenue  = $revenueByCurrency[$homeCurrency] ?? 0.0;
// Anything taken in another currency, listed separately beneath the tile.
$revenueOther  = array_diff_key($revenueByCurrency, [$homeCurrency => true]);
// Archived products are excluded, to match orders directly above. Counting them
// meant the dashboard still reported the full catalogue after every product had
// been archived — the tile read "14 Products" with nothing live on the shop.
// A draft is not on the shop either, so counting it here repeats the very fault
// the note above describes fixing: on a shop whose only product is a draft the
// tile read "1 Product" while a shopper saw an empty catalogue. The number is
// now what is actually for sale, and any drafts are named beneath it rather than
// hidden inside it — invisible drafts are how a product sits unpublished for a
// week without anyone noticing.
$totalProducts = (int)$pdo->query(
    "SELECT COUNT(*) FROM products WHERE (is_deleted = 0 OR is_deleted IS NULL) AND available = 1"
)->fetchColumn();
$draftProducts = (int)$pdo->query(
    "SELECT COUNT(*) FROM products WHERE (is_deleted = 0 OR is_deleted IS NULL) AND available = 0"
)->fetchColumn();

// Repeat customer detection
$repeatPhones = [];
try {
    // Archived orders excluded here too, or a customer whose earlier orders were
    // all deleted still wears a "repeat customer" badge on their only live one.
    $repeatPhones = $pdo->query(
        "SELECT phone FROM orders WHERE COALESCE(is_deleted, 0) = 0
          GROUP BY phone HAVING COUNT(*) > 1"
    )->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {}
$repeatPhoneSet = array_flip($repeatPhones);
$repeatCustomerCount = count($repeatPhones);

/* ── The five page lists this file used to load, and no longer does ──────────
   $orders, $products, $catList, $promoCodes and $inquiries were all SELECT *
   with no LIMIT, run here on EVERY admin page load. Measured before removing
   them: 18 SELECTs and 185 rows fetched per page load, on all 35 admin screens.

   Five of those 35 screens referenced any of the lists, and only ONE needed this
   file to supply it:

     admin/orders.php        $orders      — now loads it itself, just below its
                                            own role check
     admin/customers.php     $inquiries   — already loaded its own, before this
                                            include
     admin/size_guide.php    $products    — already loaded its own (ORDER BY
                                            name, which this file's id DESC list
                                            was overwriting, so its "Product
                                            Override" picker was never
                                            alphabetical)
     admin/gst_report.php    $orders      — already loads its own, AFTER this
                                            include, so this copy was fetched and
                                            then thrown away
     admin/promo.php         $promoCodes  — same: loads its own afterwards

   $catList and $unreadInquiries were read by NOTHING, anywhere in the codebase.
   Two queries per admin page load whose results were never looked at.

   The remaining 30 screens used none of it. So this was 185 rows fetched to serve
   one page, and it grows with the shop: at 5,000 orders every admin screen would
   have pulled 5,000 full rows before drawing anything.

   A page that needs a list now runs its own query, which also lets it choose its
   own columns, filter and order. If you came here looking for where $orders is
   set, that is why it is not here. */

// ── Load stock data (for Stock tab) ───────────────────────
// Every one of the three queries below is filtered to non-archived products.

// ── Load stock data (for Stock tab) ───────────────────────
// Every one of the three queries below is filtered to non-archived products.
// They were not, so Inventory & Stock listed archived products exactly like live
// ones: archiving the entire catalogue removed it from the shop and from the
// Products screen, while Stock Management still showed all of it with editable
// stock boxes. Archiving is a soft delete (products.php sets is_deleted = 1), so
// the rows are still there by design — the stock screen just has no business
// showing them. Matches $notArchived in admin/products.php.
//
// NOT applied to $revProductMap below or to revenue_report.php: those are id→row
// lookups for historical orders, which must still resolve the name of a product
// that has since been archived, or old invoices lose their line-item labels.
$stockNotArchived   = "WHERE (is_deleted = 0 OR is_deleted IS NULL)";
$stockProducts      = [];
$stockMigrationDone = false;
$stockV2Done        = false; // true when total_stock + sold_online also exist

/* Only the screen that shows stock pays for reading it.
   ────────────────────────────────────────────────────────────────────────────
   This sits in the shared header, so it ran on EVERY admin page: every product,
   eleven columns, sorted — and then up to two more full-table fallbacks if the
   v2 columns are missing. The only file that ever reads $stockProducts is
   admin/settings.php. Saving a category, editing a coupon, opening the orders
   list: each one loaded the entire stock table and threw it away.

   $stockProducts is already [] above, and $stockMigrationDone/$stockV2Done are
   already false, so every other page gets exactly the empty state it was going
   to ignore anyway. Same fix as the five page lists removed from this header
   earlier — this one was missed because it is used, just not here. */
if (($dvPage ?? '') === 'settings.php') {
try {
    // Try full v2 schema
    $stockProducts = $pdo->query(
        "SELECT id, name, emoji, image, category, sku, atelier_code,
                IFNULL(track_stock,  0) AS track_stock,
                IFNULL(total_stock,  0) AS total_stock,
                IFNULL(stock_qty,    0) AS stock_qty,
                IFNULL(damage_stock, 0) AS damage_stock,
                IFNULL(sold_offline, 0) AS sold_offline,
                IFNULL(sold_online,  0) AS sold_online
         FROM products $stockNotArchived ORDER BY name ASC"
    )->fetchAll();
    $stockMigrationDone = true;
    $stockV2Done        = true;
} catch (PDOException $e) {
    // v2 columns missing — try v1 (damage + offline only)
    try {
        $stockProducts = $pdo->query(
            "SELECT id, name, emoji, image, category, sku, atelier_code,
                    IFNULL(track_stock,  0) AS track_stock,
                    IFNULL(stock_qty,    0) AS total_stock,
                    IFNULL(stock_qty,    0) AS stock_qty,
                    IFNULL(damage_stock, 0) AS damage_stock,
                    IFNULL(sold_offline, 0) AS sold_offline,
                    0 AS sold_online
             FROM products $stockNotArchived ORDER BY name ASC"
        )->fetchAll();
        $stockMigrationDone = true;
    } catch (PDOException $e2) {
        // No stock columns at all — basic fallback
        try {
            $stockProducts = $pdo->query(
                "SELECT id, name, emoji, image, category, sku, atelier_code,
                        0 AS track_stock, 0 AS total_stock, 0 AS stock_qty,
                        0 AS damage_stock, 0 AS sold_offline, 0 AS sold_online
                 FROM products $stockNotArchived ORDER BY name ASC"
            )->fetchAll();
        } catch (PDOException $e3) { $stockProducts = []; }
    }
}
}   // end of the settings.php-only guard


// ── Load gallery (removed) ──────────────────────────────────

// $products, $catList and $promoCodes were loaded here. See the note above the
// stock block for where they went and why.

// ── Active tab ────────────────────────────────────────────
if (!isset($activeTab)) $activeTab = $_GET['tab'] ?? 'orders';
$validTabs = [
    'orders', 'products', 'categories', 'subcategories', 'brands', 'colors', 'sizes',
    'stock', 'customers', 'promos', 'reviews', 'invoices', 'banners',
    'homepage', 'blog', 'seo', 'newsletter', 'media', 'revenue_report', 'revenue',
    'settings', 'backup', 'admin_users', 'roles', 'logs', 'inquiries', 'dashboard',
    'returns', 'support_tickets', 'size_guide', 'countries', 'lookbook', 'email_logs', 'health',
    /* A tab missing from this list is silently rewritten to 'orders' by the line
       below — so suppliers.php set $activeTab = 'suppliers', it failed the
       whitelist, and the sidebar highlighted Orders & Invoices while you stood on
       Suppliers. Any new admin screen needs its tab name added here as well as a
       menu row, or it will look like it belongs to another page. */
    'suppliers',
    /* attributes.php sets $activeTab from its own ?type= tab, so every tab that
       screen can open has to be here. Only 'colors' and 'sizes' were, so standing
       on Sleeves, Necklines, Patterns, Fabrics or Occasions failed the whitelist
       and lit up Orders & Invoices — the same fault the note above records for
       suppliers, repeated for five more tabs. */
    'sleeves', 'necks', 'patterns', 'fabrics', 'occasions'
];
if (!in_array($activeTab, $validTabs)) $activeTab = 'orders';

// ── Inquiries: the table, and marking them read ─────────────
// The SELECT * that used to be here is gone — admin/customers.php loads its own,
// and it was the only reader. The $unreadInquiries COUNT is gone too: nothing in
// the codebase ever read it.
//
// What stays are the two things with real effects. The table creation is the
// house pattern (config/db.php does the same for its tables), and the
// mark-as-read has to run when the tab is opened whatever any page has fetched
// for itself.
try {
    // Auto-create table if it doesn't exist
    $pdo->exec("CREATE TABLE IF NOT EXISTS `inquiries` (
        `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `name`       VARCHAR(120) NOT NULL,
        `email`      VARCHAR(180) NOT NULL,
        `phone`      VARCHAR(30)  NOT NULL DEFAULT '',
        `message`    TEXT         NOT NULL,
        `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `is_read`    TINYINT(1)   NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Mark as read if viewing the tab
    if ($activeTab === 'inquiries') {
        $pdo->exec("UPDATE inquiries SET is_read = 1 WHERE is_read = 0");
    }
} catch (PDOException $e) {}

// ── Revenue tab data ──────────────────────────────────
$revData = [];
if ($activeTab === 'revenue') {
    $revFrom = $_GET['rev_from'] ?? date('Y-m-01');
    $revTo   = $_GET['rev_to']   ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $revFrom)) $revFrom = date('Y-m-01');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $revTo))   $revTo   = date('Y-m-d');

    // Summary by payment status
    try {
        // Same basis as the dashboard figure above: refunds deducted, archived
        // orders excluded.
        //
        // This one did neither, so the Revenue report and the dashboard tile
        // showed two different "Total Revenue" numbers on the same screen —
        // the report counted orders the owner had already archived, and both
        // ignored money that had been paid back.
        $rstmt = $pdo->prepare("SELECT payment_status, SUM(total_price - COALESCE(refunded_amount, 0)) AS total, COUNT(*) AS cnt FROM orders WHERE DATE(created_at) BETWEEN :f AND :t AND COALESCE(is_deleted,0) = 0 GROUP BY payment_status");
        $rstmt->execute(['f' => $revFrom, 't' => $revTo]);
        $byStatus = [];
        while ($r = $rstmt->fetch()) $byStatus[$r['payment_status']] = $r;
        $revData['online']       = (float)($byStatus['Paid']['total']  ?? 0);
        $revData['cash']         = (float)($byStatus['Cash']['total']  ?? 0);
        $revData['total']        = $revData['online'] + $revData['cash'];
        $revData['unpaid_total'] = (float)($byStatus['Unpaid']['total'] ?? 0);
        $revData['unpaid_count'] = (int)($byStatus['Unpaid']['cnt']    ?? 0);
    } catch (PDOException $e) { $revData['total'] = 0; $revData['online'] = 0; $revData['cash'] = 0; $revData['unpaid_total'] = 0; $revData['unpaid_count'] = 0; }

    // Product map for categories
    $revProductMap = [];
    try {
        $rows = $pdo->query("SELECT id, name, category FROM products")->fetchAll();
        foreach ($rows as $row) $revProductMap[$row['id']] = $row;
    } catch (PDOException $e) {}

    // Category breakdown + product sold qty (all-time & this month)
    $catRevenue      = [];
    $productAllTime  = [];
    $productThisMonth = [];
    $thisYear  = (int)date('Y');
    $thisMonth = (int)date('n');

    try {
        // For category table: filter by date range (paid only)
        /* Archived orders excluded, like every other order query in this file.
           ────────────────────────────────────────────────────────────────────
           Deleting an order from the admin ARCHIVES it — the row is the GST tax
           invoice and cannot legally be destroyed — so "I deleted all my orders"
           leaves them all present with is_deleted = 1. This query and the chart
           one below were the only two that never checked, so Revenue by Category
           went on reporting sales from orders the owner had deleted, on a shop
           with no products left. Every neighbouring query (lines 56, 57, 82, 121,
           248, 338) already filters; these two were simply missed. */
        $oStmt = $pdo->prepare(
            "SELECT items_json, payment_status, created_at FROM orders
              WHERE DATE(created_at) BETWEEN :f AND :t
                AND COALESCE(is_deleted, 0) = 0"
        );
        $oStmt->execute(['f' => $revFrom, 't' => $revTo]);
        $revOrders = $oStmt->fetchAll();
    } catch (PDOException $e) { $revOrders = []; }

    foreach ($revOrders as $o) {
        $isPaid = in_array($o['payment_status'], ['Paid', 'Cash']);
        // Normalised — see orderItems() in config/config.php. Three QA orders with
            // items_json missing "quantity" put an Undefined-array-key warning at the
            // top of EVERY admin page, because this header builds its charts from
            // every order in the shop.
            $items  = orderItems($o['items_json']);
        foreach ($items as $it) {
            $pid  = $it['product_id'] ?? 0;
            $cat  = $revProductMap[$pid]['category'] ?? ($it['category'] ?? 'Other');
            $qty  = (int)$it['quantity'];
            $line = (float)$it['price'] * $qty;
            if ($isPaid) {
                if (!isset($catRevenue[$cat])) $catRevenue[$cat] = ['revenue' => 0, 'qty' => 0];
                $catRevenue[$cat]['revenue'] += $line;
                $catRevenue[$cat]['qty']     += $qty;
            }
        }
    }
    arsort($catRevenue);

    // Product charts: all-time paid orders
    try {
        // Same omission as above: the best-sellers charts counted archived orders.
        $allStmt = $pdo->query(
            "SELECT items_json, created_at FROM orders
              WHERE payment_status IN ('Paid','Cash')
                AND COALESCE(is_deleted, 0) = 0"
        );
        while ($o = $allStmt->fetch()) {
            $dt    = new DateTime($o['created_at']);
            $items = orderItems($o['items_json']);
            $isThisMonth = ((int)$dt->format('Y') === $thisYear && (int)$dt->format('n') === $thisMonth);
            foreach ($items as $it) {
                $nm  = $it['name'];
                $qty = (int)$it['quantity'];
                $productAllTime[$nm]  = ($productAllTime[$nm]  ?? 0) + $qty;
                if ($isThisMonth) $productThisMonth[$nm] = ($productThisMonth[$nm] ?? 0) + $qty;
            }
        }
    } catch (PDOException $e) {}
    arsort($productAllTime);
    arsort($productThisMonth);
    $revData['chart_alltime']   = array_slice($productAllTime,   0, 10, true);
    $revData['chart_thismonth'] = array_slice($productThisMonth, 0, 10, true);
    $revData['cat_revenue']     = $catRevenue;
    $revData['from']            = $revFrom;
    $revData['to']              = $revTo;

    // ── COD orders & handling-fee breakdown ─────────────────────────
    // Every COD order in the range, with the fee collected on it. Rolled up
    // per month so the shop can see what the handling fee contributes over
    // time — the reason the fee column exists. All COD orders count here,
    // not just Paid/Cash ones: the fee is owed at delivery regardless of
    // whether the admin has yet clicked "Cash Received".
    $codOrders  = [];
    $codMonthly = [];
    try {
        $codStmt = $pdo->prepare(
            "SELECT order_code, customer_name, customer_email, postcode,
                    total_price, cod_fee, payment_status, created_at,
                    remitted_at, remitted_amount,
                    DATE_FORMAT(created_at, '%Y-%m') AS month_key
               FROM orders
              WHERE payment_method = 'cod'
                AND COALESCE(is_deleted, 0) = 0
                AND DATE(created_at) BETWEEN :f AND :t
              ORDER BY created_at DESC"
        );
        $codStmt->execute(['f' => $revFrom, 't' => $revTo]);
        $codOrders = $codStmt->fetchAll(PDO::FETCH_ASSOC);

        // remitted_value = courier cash actually banked; pending_value = cash
        // handed over at delivery but not yet paid by the courier.
        $codTotals = ['orders' => 0, 'order_value' => 0.0, 'fees' => 0.0,
                      'remitted_value' => 0.0, 'pending_value' => 0.0, 'remitted_count' => 0];
        foreach ($codOrders as $codO) {
            // Bucketed by MySQL's own clock (DATE_FORMAT in the SELECT), not by
            // PHP's date(): this shop documented PHP and MySQL being an hour
            // apart, and a 23:30 order must land in the month its DATE() range
            // check counted it in.
            $monthKey = (string)($codO['month_key'] ?? date('Y-m', strtotime($codO['created_at'])));
            $fee = (float)($codO['cod_fee'] ?? 0);
            if (!isset($codMonthly[$monthKey])) {
                $codMonthly[$monthKey] = ['orders' => 0, 'order_value' => 0.0, 'fees' => 0.0];
            }
            $codMonthly[$monthKey]['orders']++;
            $codMonthly[$monthKey]['order_value'] += (float)$codO['total_price'];
            $codMonthly[$monthKey]['fees']        += $fee;

            $codTotals['orders']++;
            $codTotals['order_value'] += (float)$codO['total_price'];
            $codTotals['fees']        += $fee;
            if (!empty($codO['remitted_amount'])) {
                $codTotals['remitted_count']++;
                $codTotals['remitted_value'] += (float)$codO['remitted_amount'];
            } elseif (strcasecmp(trim((string)$codO['payment_status']), 'Cash') === 0) {
                // Only cash the courier HAS collected but not yet paid the shop counts
                // as awaiting remittance — undelivered/unpaid COD orders are not owed
                // to us by the courier yet.
                $codTotals['pending_value'] += (float)$codO['total_price'];
            }
        }
        // Newest month first; fees matter more than the orders count.
        uksort($codMonthly, fn($a, $b) => strcmp($b, $a));
    } catch (PDOException $e) {
        // Pre-migration table without cod_fee — the report just reads zero
        // fees rather than failing the whole revenue page.
        $codTotals = ['orders' => 0, 'order_value' => 0.0, 'fees' => 0.0,
                      'remitted_value' => 0.0, 'pending_value' => 0.0, 'remitted_count' => 0];
        $codMonthly = [];
    }
    $revData['cod_orders']  = $codOrders;
    $revData['cod_monthly'] = $codMonthly;
    $revData['cod_totals']  = $codTotals;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin Dashboard – <?= SHOP_NAME ?></title>
    <?php
    // Favicon. When a dark-mode variant exists we emit BOTH links with a
    // prefers-color-scheme media query, so a dark logo does not disappear against
    // a dark browser tab. Browsers that ignore the media attribute fall back to
    // the last matching link, which is why the light one is listed first.
    $faviconLight = siteFaviconUrl($pdo ?? null);
    $faviconDark  = siteFaviconDarkUrl($pdo ?? null);
    ?>
    <?php if ($faviconDark): ?>
    <link rel="icon" type="<?= siteFaviconMime($pdo ?? null, $faviconLight) ?>" href="<?= htmlspecialchars($faviconLight) ?>" media="(prefers-color-scheme: light)">
    <link rel="icon" type="<?= siteFaviconMime($pdo ?? null, $faviconDark) ?>" href="<?= htmlspecialchars($faviconDark) ?>" media="(prefers-color-scheme: dark)">
    <?php else: ?>
    <link rel="icon" type="<?= siteFaviconMime($pdo ?? null, $faviconLight) ?>" href="<?= htmlspecialchars($faviconLight) ?>">
    <?php endif; ?>
    <!-- Versioned with filemtime, not time(): a cache-buster that changes on every
         request defeats browser caching and re-downloads the CSS on each page view. -->
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/style.css?v=<?= filemtime(__DIR__ . '/../../assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= SITE_URL ?>/admin/assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/responsive.css?v=<?= filemtime(__DIR__ . '/../../assets/css/responsive.css') ?>">
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/fontawesome/css/all.min.css">
    <script>
        window.ADMIN_CSRF_TOKEN = '<?= generateCsrfToken() ?>';
        /* Base for any picture a script builds a URL for. Scripts were writing
           "../uploads/products/…", which the browser resolves against the URL
           it is currently on rather than against the file on disk — so on live
           it pointed at a folder that does not exist and every thumbnail 404'd,
           while the page around it looked completely normal. */
        window.SITE_URL = '<?= rtrim(SITE_URL, '/') ?>';
        window.ADMIN_UPLOADS_URL = window.SITE_URL + '/uploads';
    </script>
</head>

<body class="admin-wrapper">

<!-- ══ Global Top Header Bar (0,0 Fixed) ════════════════════════════════════ -->
<header class="admin-global-topbar">
    <div style="display: flex; align-items: center; gap: 12px;">
        <button type="button" class="admin-mobile-toggle" onclick="toggleAdminSidebar()" style="display: none; background: none; border: none; color: #ffffff; font-size: 18px; cursor: pointer; padding: 4px;">
            <i class="fa-solid fa-bars"></i>
        </button>
        <?php /* SITE_URL, not ../home.php. The relative form resolved to a
                 /home.php at the web root — a file that does not exist as a page
                 in its own right, and an address the shop never uses anywhere
                 else, so pressing the admin logo landed on dievon.com/home.php
                 instead of dievon.com. Same rule as everywhere else in this
                 codebase: links are built from SITE_URL, never guessed by
                 counting ../ from wherever the file happens to sit. */ ?>
        <a href="<?= SITE_URL ?>/" class="admin-brand-logo">
            <span class="logo-emoji">✨</span> <?= SHOP_NAME ?> <small>ATELIER ADMIN</small>
        </a>
    </div>

    <div class="admin-topbar-actions">
        <a href="<?= SITE_URL ?>/" target="_blank" rel="noopener" class="topbar-btn-shop">
            <i class="fa-solid fa-globe"></i> View Live Shop ↗
        </a>
        <div class="admin-user-pill">
            <i class="fa-solid fa-user-shield"></i> <?= htmlspecialchars((string)($_SESSION['admin_username'] ?? ADMIN_USERNAME)) ?>
        </div>
        <a href="logout.php" class="topbar-btn-logout">
            Sign Out
        </a>
    </div>
</header>

<!-- Mobile Sidebar Backdrop Overlay -->
<div class="mobile-drawer-backdrop" id="adminSidebarBackdrop" onclick="toggleAdminSidebar(false)"></div>

<script>
function toggleAdminSidebar(force) {
    const sidebar = document.querySelector('.admin-sidebar');
    const backdrop = document.getElementById('adminSidebarBackdrop');
    if (!sidebar) return;
    
    if (typeof force === 'boolean') {
        if (force) {
            sidebar.classList.add('open');
            if (backdrop) backdrop.classList.add('active');
        } else {
            sidebar.classList.remove('open');
            if (backdrop) backdrop.classList.remove('active');
        }
    } else {
        sidebar.classList.toggle('open');
        if (backdrop) backdrop.classList.toggle('active');
    }
}

// Auto scroll-into-view & preserve sidebar scroll position across page reloads
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.querySelector('.admin-sidebar');
    if (!sidebar) return;

    const savedScroll = localStorage.getItem('adminSidebarScroll');
    if (savedScroll !== null) {
        sidebar.scrollTop = parseInt(savedScroll, 10);
    }

    const activeItem = sidebar.querySelector('a.active');
    if (activeItem) {
        setTimeout(function() {
            activeItem.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }, 100);
    }

    sidebar.addEventListener('scroll', function() {
        localStorage.setItem('adminSidebarScroll', sidebar.scrollTop);
    });
});
</script>

<div class="admin-body-layout">

    <!-- ══ Left Fixed Sidebar (Streamlined & Clean) ════════════════════════ -->
    <?php
    /* Each entry is [capability, href, active-tabs, icon, label]. A null
       capability means everyone signed in may see it.

       Built as data rather than as a run of hand-written <a> tags so that a
       group heading can be suppressed when the person cannot reach anything
       inside it — the previous markup would have printed "Sales & Clients"
       above an empty gap for an account that only manages the catalogue.

       Still only tidiness: every page repeats the check with
       requireAdminCapability(), because the URL remains typeable and the
       handlers still accept a POST from anyone who has one. */
    $sidebarGroups = [
        'Overview' => [
            [null, 'dashboard.php', ['dashboard'], 'fa-chart-line', 'Dashboard Analytics'],
        ],
        'Catalog &amp; Inventory' => [
            ['catalogue.view',   'products.php',            ['products'],                          'fa-shirt',          'Products &amp; Garments'],
            ['catalogue.manage', 'categories.php',          ['categories', 'subcategories'],       'fa-folder-tree',    'Categories &amp; Subcategories'],
            /* One page, one row. Colours, Sleeves, Necklines and Patterns are all
               managed on attributes.php — they are the lists that feed the shop's
               filters, and keeping them on one screen means you can see the whole
               set at once instead of hunting a tab or a menu row per list. */
            ['catalogue.manage', 'attributes.php', ['colors', 'sleeves', 'necks', 'patterns', 'fabrics', 'occasions', 'sizes', 'attributes'], 'fa-filter', 'Filters &amp; Attributes'],
            // brands.php existed, enforced catalogue.manage and worked — but nothing
            // linked to it except setup_database.php, which is itself unlinked. The
            // only route in was typing the URL. This row even claimed 'brands' as one
            // of its own active tabs, so the menu highlighted a page it could not open.
            ['catalogue.manage', 'brands.php',                ['brands'],                         'fa-tag',            'Brands'],
            /* In the menu, not only in the permissions list. The comment above
               describes exactly this mistake on brands.php — a working page whose
               only route in was typing the URL — and the new Suppliers screen had
               just repeated it. */
            ['suppliers.manage', 'suppliers.php',             ['suppliers'],                      'fa-truck-field',    'Suppliers'],
            ['catalogue.manage', 'settings.php?tab=stock',  ['stock'],                             'fa-boxes-stacked',  'Inventory &amp; Stock'],
            ['sizeguide.manage', 'size_guide.php',          ['size_guide'],                        'fa-ruler',          'Size Guide'],
        ],
        'Sales &amp; Clients' => [
            ['orders.view',    'orders.php',          ['orders', 'invoices'], 'fa-clipboard-list', 'Orders &amp; Invoices'],
            ['customers.view', 'customers.php',       ['customers'],          'fa-users',          'Customers &amp; Inquiries'],
            ['pricing.manage', 'promo.php',           ['promos'],             'fa-ticket',         'Discount Coupons'],
            ['catalogue.manage', 'reviews.php',       ['reviews'],            'fa-star',           'Customer Reviews'],
            ['returns.manage', 'returns.php',         ['returns'],            'fa-rotate-left',    'RMA Returns'],
            ['tickets.manage', 'support_tickets.php', ['support_tickets'],    'fa-headset',        'Support Tickets'],
        ],
        'Content &amp; Settings' => [
            ['content.manage',  'banners.php',            ['banners'],                    'fa-sliders',           'Banner Slider Manager'],
            ['content.manage',  'blog.php',               ['blog'],                       'fa-newspaper',         'Blog Journal Manager'],
            ['seo.manage',      'seo.php',                ['seo'],                        'fa-magnifying-glass',  'SEO &amp; Social Previews'],
            ['content.manage',  'newsletter.php',         ['newsletter'],                 'fa-envelope',          'Newsletter Subscribers'],
            ['media.manage',    'media.php',              ['media'],                      'fa-images',            'Media Library'],
            ['media.manage',    'lookbook.php',           ['lookbook'],                   'fa-book-open',         'Lookbook Images'],
            ['settings.manage', 'settings.php?tab=settings', ['settings'],                'fa-gear',              'Store Settings'],
            ['staff.manage',    'admin_users.php',        ['admin_users'],                'fa-users-gear',        'Staff Accounts'],
            ['staff.manage',    'roles.php',              ['roles'],                      'fa-user-shield',       'Roles &amp; Permissions'],
            ['settings.manage', 'countries.php',          ['countries'],                  'fa-earth-asia',        'Countries We Sell To'],
            // Also unreachable. Every logAdminAction() call in the panel writes here —
            // who changed a price, who archived a product, who altered a permission —
            // and none of it could be read without typing the URL.
            ['settings.manage', 'email_logs.php',         ['email_logs'],                 'fa-envelope-open-text','Email Log'],
            ['settings.manage', 'health.php',             ['health'],                     'fa-stethoscope',       'System Health Check'],
            ['settings.manage', 'logs.php',               ['logs'],                       'fa-clipboard-list',    'Audit Logs'],
            ['settings.manage', 'backup.php',             ['backup'],                     'fa-database',          'Database Backup'],
        ],
    ];
    ?>
    <aside class="admin-sidebar">
        <nav class="admin-sidebar-nav">
            <?php foreach ($sidebarGroups as $groupTitle => $items):
                $visible = array_values(array_filter(
                    $items,
                    fn(array $i): bool => $i[0] === null || adminCan($i[0])
                ));
                if (!$visible) { continue; }
            ?>
            <div class="sidebar-group-title"><?= $groupTitle ?></div>
            <?php foreach ($visible as [$cap, $href, $tabs, $icon, $label]): ?>
            <a href="<?= $href ?>" class="<?= in_array($activeTab, $tabs, true) ? 'active' : '' ?>"><i class="fa-solid <?= $icon ?>"></i> <?= $label ?></a>
            <?php endforeach; ?>
            <?php endforeach; ?>
        </nav>
    </aside>

    <!-- ══ Main Content Area ════════════════════════════════════════ -->
    <div class="admin-main">
        <main class="admin-content">

        <!-- Alerts -->
        <?php // Drawn here only for pages that do NOT draw their own.
              //
              // $hideHeaderTitle already means "this page renders its own header
              // furniture", and every page that sets it also renders its own alert
              // block further down. Once the ?? above stopped blanking the message,
              // both blocks fired and all twelve of those screens showed each
              // message TWICE — two stacked boxes, mismatched styling, one with a
              // Font Awesome icon and one with an emoji.
              //
              // Guarded the same way as the page-title block immediately below, so
              // there is exactly one message everywhere: the page's own where it has
              // one, this one where it does not. ?>
        <?php if (empty($hideHeaderTitle)): ?>
            <?php if ($successMsg): ?>
            <div class="alert alert-success" style="margin-bottom:24px; background: #ecfdf5; border: 1px solid #10b981; color: #065f46; padding: 12px 16px;">
                <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($successMsg) ?>
            </div>
            <?php endif; ?>
            <?php if ($errorMsg): ?>
            <div class="alert alert-danger" style="margin-bottom:24px; background: #fef2f2; border: 1px solid #ef4444; color: #991b1b; padding: 12px 16px;">
                <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($errorMsg) ?>
            </div>
            <?php endif; ?>

            <?php
            /* Is outbound mail working RIGHT NOW?
               ────────────────────────────────────────────────────────────────────
               Email failures were recorded and never surfaced. Nothing told anyone,
               so the shop could stop sending order confirmations — a full mailbox, a
               daily sending cap, a changed password — and the first sign would be a
               customer asking why they never got anything.

               Measured as failures SINCE THE LAST SUCCESSFUL SEND, not failures in
               the last N days. This shop has 311 historical failures from before SMTP
               was configured; counting a time window would light this banner up
               permanently over a problem already fixed, and a warning that is always
               on is a warning nobody reads. A send that succeeded after them proves
               mail works, and the count resets on its own.

               Wrapped in its own try/catch: a broken email_logs table must never take
               out every admin page. */
            $mailBroken = 0;
            try {
                $lastOk = $pdo->query("SELECT MAX(created_at) FROM email_logs WHERE status = 'sent'")->fetchColumn();
                $q = $lastOk
                    ? $pdo->prepare("SELECT COUNT(*) FROM email_logs WHERE status = 'failed' AND created_at > :t")
                    : $pdo->prepare("SELECT COUNT(*) FROM email_logs WHERE status = 'failed'");
                if ($lastOk) { $q->execute(['t' => $lastOk]); } else { $q->execute(); }
                $mailBroken = (int)$q->fetchColumn();
            } catch (Throwable $e) { $mailBroken = 0; }
            ?>
            <?php if ($mailBroken > 0): ?>
            <div class="alert alert-danger admin-notice">
                <i class="fa-solid fa-envelope-circle-check"></i>
                <div class="admin-notice-body">
                    <strong>Email is failing.</strong>
                    <?= (int)$mailBroken ?> message<?= $mailBroken === 1 ? '' : 's' ?>
                    could not be sent since the last one that worked &mdash; customers may not be
                    receiving order confirmations.
                    <a href="<?= defined('SITE_URL') ? SITE_URL : '' ?>/admin/email_logs.php">See Email Logs</a>
                </div>
            </div>
            <?php endif; ?>

            <?php
            /* A country switched on with nothing priced for it.
               ────────────────────────────────────────────────────────────────────
               A product is sold abroad only when it carries an explicit price for
               that country — productCountryPricing() returns null otherwise, and
               every caller correctly hides it rather than converting at a rate
               nobody maintains. Right rule. The problem was that it said nothing:
               enable a country with no prices and the shop simply looks broken to
               anyone browsing from there — an empty catalogue, "free shipping over
               £0.00", and a cart that cannot reach checkout. Three symptoms, one
               cause, and no warning anywhere.

               Counts DISTINCT products so a country is judged by how many pieces it
               can actually sell, not by how many price rows happen to exist.
               Home country is excluded: it prices from products.price and can never
               be in this state.

               Its own try/catch — a missing store_countries table must not take out
               every admin page. */
            $countriesUnpriced = [];
            try {
                $liveProducts = (int)$pdo->query(
                    "SELECT COUNT(*) FROM products WHERE available = 1 AND COALESCE(is_deleted,0) = 0"
                )->fetchColumn();
                $cq = $pdo->query(
                    "SELECT c.country_code, c.country_name,
                            COUNT(DISTINCT cp.product_id) AS priced
                       FROM store_countries c
                  LEFT JOIN product_country_prices cp ON cp.country_code = c.country_code
                      WHERE c.is_enabled = 1 AND COALESCE(c.is_home,0) = 0
                   GROUP BY c.country_code, c.country_name"
                );
                /* Flagged on COVERAGE, not on zero.
                   A threshold of "exactly nothing priced" goes quiet the moment one
                   product gets a price — while shoppers there still see 1 item out of
                   12 and a shop that looks abandoned. The state worth warning about is
                   "enabled but mostly unsellable", so anything under full coverage is
                   reported, with the actual figures rather than an adjective. */
                foreach ($cq->fetchAll(PDO::FETCH_ASSOC) as $cr) {
                    if ((int)$cr['priced'] < $liveProducts) {
                        $countriesUnpriced[] = $cr;
                    }
                }
            } catch (Throwable $e) { $countriesUnpriced = []; }
            ?>
            <?php if (!empty($countriesUnpriced)): ?>
            <div class="alert alert-danger admin-notice">
                <i class="fa-solid fa-earth-americas"></i>
                <div class="admin-notice-body">
                    <div><strong><?= count($countriesUnpriced) === 1 ? 'A country is' : 'Countries are' ?> switched on without full pricing.</strong></div>
                    <?php foreach ($countriesUnpriced as $cr): ?>
                        <?php $cPriced = (int)$cr['priced']; ?>
                        <div>
                            <strong><?= htmlspecialchars($cr['country_name'] ?: $cr['country_code']) ?></strong>
                            &mdash; shoppers there can see
                            <strong><?= $cPriced ?> of <?= (int)$liveProducts ?></strong>
                            <?= $liveProducts === 1 ? 'product' : 'products' ?>.
                            <?= $cPriced === 0
                                ? 'The shop looks empty to them and they cannot check out.'
                                : 'The rest are hidden because they have no ' . htmlspecialchars($cr['country_code']) . ' price.' ?>
                        </div>
                    <?php endforeach; ?>
                    <div>
                        Set prices under <strong>International Pricing</strong> on each product, or switch the country off in
                        <a href="<?= defined('SITE_URL') ? SITE_URL : '' ?>/admin/countries.php">Countries We Sell To</a>.
                    </div>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (empty($hideHeaderTitle)): ?>
        <!-- Page Header -->
        <div class="admin-page-header admin-page-header-flex">
            <div>
                <h1 class="admin-page-title">
                    <?php
                    echo match($activeTab) {
                        'orders'     => 'Orders',
                        'products'   => 'Products',
                        'stock'      => 'Stock Management',
                        'categories' => 'Categories',
                        'revenue'    => 'Revenue & Reports',
                        'promos'     => 'Promos',
                        'inquiries'  => 'Inquiries',
                        'reviews'    => 'Customer Reviews',
                        'newsletter' => 'Newsletter Subscribers',
                        'media'      => 'Media Library & Assets',
                        'returns'    => 'RMA Returns & Exchanges',
                        'support_tickets' => 'Atelier Concierge Support Tickets',
                        'backup'     => 'Database Backup & Restore',
                        'size_guide' => 'Size Guide',
                        'lookbook'   => 'Lookbook Images',
                        default      => 'Admin Panel',
                    };
                    ?>
                </h1>
                <p class="admin-page-subtitle">
                    <?php
                    echo match($activeTab) {
                        'orders'     => 'View and manage all customer orders',
                        'products'   => 'Add, edit, or remove products',
                        'stock'      => 'Track in-stock, damage, and offline sold quantities per product',
                        'categories' => 'Add or remove product categories',
                        'revenue'    => 'Sales reports, payment breakdowns and product charts',
                        'reviews'    => 'Approve, reject, or delete customer product reviews',
                        'newsletter' => 'Everyone who subscribed from the site footer',
                        'media'      => 'Every image uploaded for products, banners, and blog posts',
                        'returns'    => 'Review and process customer return &amp; exchange requests',
                        'support_tickets' => 'Respond to customer concierge and support requests',
                        'backup'     => 'Download a full SQL export of every table',
                        'size_guide' => 'Manage body & garment measurements shown in the storefront size guide popup',
                        'lookbook'   => 'Replace the three lookbook photographs shown across the storefront',
                        default      => '',
                    };
                    ?>
                </p>
            </div>
            <?php if ($activeTab === 'products'): ?>
            <a href="product_form.php" class="btn-primary admin-header-btn-secondary">
                <i class="fa-solid fa-plus"></i> Add Product
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($activeTab === 'revenue' || $activeTab === 'orders' || $activeTab === 'dashboard'): ?>
        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Orders</div>
                <div class="stat-value"><?= $totalOrders ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Pending Orders</div>
                <div class="stat-value text-warning"><?= $pendingOrders ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Revenue</div>
                <?php // currencySymbol(), not a typed ₹ — every other money figure on this
                      // screen uses it, and Settings can change the currency. ?>
                <div class="stat-value text-success"><?= htmlspecialchars(function_exists('currencySymbol') ? currencySymbol() : '₹') ?><?= number_format($totalRevenue, 2) ?></div>
                <?php /* Foreign takings listed, never added in. Two currencies have no
                         common total that is not an exchange-rate guess, and a guess in
                         the revenue tile is the one number nobody should have to check. */ ?>
                <?php if (!empty($revenueOther)): ?>
                    <div class="stat-subline">
                        <?php foreach ($revenueOther as $cur => $amt): ?>
                            <span><?= htmlspecialchars(currencySymbol($cur)) ?><?= number_format($amt, 2) ?> <?= htmlspecialchars($cur) ?></span>
                        <?php endforeach; ?>
                        <em>not added &mdash; different currencies</em>
                    </div>
                <?php endif; ?>
            </div>
            <div class="stat-card">
                <div class="stat-label">Products On Sale</div>
                <div class="stat-value"><?= $totalProducts ?></div>
                <?php if ($draftProducts > 0): ?>
                <?php /* view=draft is what admin/products.php actually reads — it
                         validates against active|published|draft|archived|all and
                         silently falls back to 'active' for anything else, so a
                         wrong parameter here would look like a working link that
                         quietly shows the wrong list. */ ?>
                <div class="stat-subline">
                    <a href="<?= SITE_URL ?>/admin/products.php?view=draft">
                        + <?= $draftProducts ?> draft<?= $draftProducts === 1 ? '' : 's' ?> not published
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ═══════════════════ ORDERS TAB ═══════════════════ -->
