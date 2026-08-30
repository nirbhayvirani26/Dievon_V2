<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/blog_content.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$article = null;
try {
    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM blog_posts WHERE id = :id AND status = 'Published'");
        $stmt->execute(['id' => $id]);
        $article = $stmt->fetch();
    }
} catch (PDOException $e) {}

// An unknown, missing or unpublished id used to fall back to the FIRST published
// post and answer 200 — so /blog-single?id=99999 and /blog-single (no id at all)
// each served a real article under an address that is not its own. That is an
// unbounded set of duplicate URLs for one post, all indexable.
if (!$article) {
    http_response_code(404);
    $noindex = true;
    require __DIR__ . '/404.php';
    exit;
}

$pageTitle = !empty($article['meta_title']) ? $article['meta_title'] : $article['title'];
$metaDescription = !empty($article['meta_description']) ? $article['meta_description'] : (substr(strip_tags($article['content']), 0, 155) . '...');

$imgFile = $article['image'] ?? '';

// One resolver, shared with the blog list, the homepage and the admin screen —
// so the four cannot drift apart again. A lookbook filename counts as "this
// article has no picture of its own" and falls to the blog's OWN default, which
// the Lookbook screen never touches. See blogImageUrl().
$imgUrl        = blogImageUrl($imgFile);
$imgIsFallback = ($imgUrl === '' || strpos($imgUrl, 'blog_default') !== false);

// The share preview stays PNG — a few social scrapers still prefer it — so the
// query string and any .webp are stripped back to the original file.
$ogImage = $imgUrl !== ''
    ? preg_replace('/\.webp(\?.*)?$/', '.png', explode('?', $imgUrl)[0])
    : siteLogoUrl($pdo ?? null);

$canonicalUrl = SITE_URL . '/blog-single?id=' . $article['id'];

require_once __DIR__ . '/../includes/header.php';
?>

<!-- ══ Article structured data ══════════════════════════════════════════════
     The journal published no structured data at all, so Google had to infer
     what these pages were. -->
<script type="application/ld+json">
<?= json_encode(array_filter([
    '@context' => 'https://schema.org',
    '@type'    => 'Article',
    'headline' => mb_substr((string)$article['title'], 0, 110),
    'description' => $metaDescription,
    'image'    => cacheBustedUploadUrl($imgUrl),
    'datePublished' => !empty($article['published_date']) ? date('c', strtotime($article['published_date'])) : null,
    'dateModified'  => !empty($article['published_date']) ? date('c', strtotime($article['published_date'])) : null,
    'author'    => ['@type' => 'Organization', 'name' => SHOP_NAME, 'url' => SITE_URL . '/'],
    'publisher' => ['@type' => 'Organization', 'name' => SHOP_NAME, 'url' => SITE_URL . '/'],
    'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $canonicalUrl],
    'articleSection' => $article['category'] ?? null,
], fn($v) => $v !== null && $v !== ''), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
</script>

<script type="application/ld+json">
<?= json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home',    'item' => SITE_URL . '/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Journal', 'item' => SITE_URL . '/blog'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => $article['title'], 'item' => $canonicalUrl],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
</script>

<!-- ══ Article Detailed Reading Section ════════════════════ -->
<article style="padding: 120px 0 100px; background: var(--bg-main); font-family: var(--font-body);">
    <div class="container reveal-on-scroll" style="max-width: 900px; margin: 0 auto; padding: 0 20px;">
        
        <!-- Back to journal -->
        <div style="margin-bottom: 30px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.1em;">
            <a href="<?= SITE_URL ?>/blog" style="color: var(--text-muted);"><i class="fa-solid fa-arrow-left" style="margin-right: 5px;"></i> Back to Journal</a>
        </div>

        <span class="editorial-label" style="margin-bottom: 12px;">
            <?= htmlspecialchars($article['category'] ?? 'Style Guide') ?> &bull; <?= date('F d, Y', strtotime($article['published_date'])) ?>
        </span>
        <h1 style="font-family: var(--font-heading); font-size: 42px; font-weight: 300; text-transform: uppercase; line-height: 1.2; margin-bottom: 40px; color: var(--text-primary);">
            <?= htmlspecialchars($article['title']) ?>
        </h1>

        <!-- Feature Image -->
        <div style="border: 1px solid var(--border-light); padding: 10px; background: var(--bg-surface); margin-bottom: 50px;">
            <?php $artWebp = preg_replace('/\.[^.]+$/', '.webp', $imgUrl);
                  $artWebpFile = str_replace(SITE_URL . '/', __DIR__ . '/../', $artWebp);
                  // Resolved once: the <source> and the <img> fallback must agree, or
                  // the browser fetches the original alongside the WebP it displays.
                  $artUseWebp = webpSourceIsFresh($imgUrl, $artWebpFile); ?>
            <picture>
                <?php if ($artUseWebp): ?><source srcset="<?= htmlspecialchars(cacheBustedUploadUrl($artWebp)) ?>" type="image/webp"><?php endif; ?>
                <img src="<?= htmlspecialchars(cacheBustedUploadUrl(pictureImgSrc($imgUrl, $artUseWebp ? $artWebp : null))) ?>" alt="<?= htmlspecialchars($article['title']) ?>" class="article-hero-img">
            </picture>
        </div>

        <!-- Article Content -->
        <div class="article-body" style="font-size: 16px; line-height: 1.9; color: var(--text-secondary); max-width: 750px; margin: 0 auto;">
            <?php
                // Two storage formats live side by side here. Articles written in the
                // visual editor are HTML; everything written before it is plain text
                // with "## " headings. blogRenderContent() picks the right path per
                // article, so old posts keep rendering exactly as they always did.
                //
                // The HTML path is re-sanitised on the way out, not merely trusted
                // because it was cleaned on the way in — a row edited directly in
                // phpMyAdmin never passed through the save handler at all.
                echo blogRenderContent($article['content'] ?? '', $article['content_format'] ?? null);
            ?>
        </div>

        <!-- Social Share & Return Buttons -->
        <div class="article-share-footer">
            <div class="article-share">
                <span class="article-share-label">Share:</span>
                <button type="button" class="article-share-btn" onclick="navigator.clipboard.writeText(window.location.href); alert('Article link copied to clipboard!');" aria-label="Copy link to this article"><i class="fa-solid fa-link" aria-hidden="true"></i></button>
                <button type="button" class="article-share-btn" onclick="window.open('https://pinterest.com/pin/create/button/?url='+encodeURIComponent(window.location.href), '_blank');" aria-label="Share this article on Pinterest"><i class="fa-brands fa-pinterest" aria-hidden="true"></i></button>
                <button type="button" class="article-share-btn" onclick="window.open('https://twitter.com/intent/tweet?url='+encodeURIComponent(window.location.href), '_blank');" aria-label="Share this article on X"><i class="fa-brands fa-x-twitter" aria-hidden="true"></i></button>
            </div>

            <a href="<?= SITE_URL ?>/shop" class="btn-luxury article-share-cta">Shop Collections</a>
        </div>

    </div>
</article>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
