<?php
/**
 * Dievon – the journal card. One definition, used everywhere.
 *
 * This markup existed twice — the "Latest Chronicles" rail on the homepage and
 * the listing on /blog — and the two had drifted into different components
 * wearing the same name. The homepage wrapped its text in .blog-card-content
 * and the listing in .blog-card-body; the homepage printed the category and
 * date as one .blog-card-tag and the listing as .blog-card-meta holding a
 * separate .blog-card-category and .blog-card-date; one used an <h3> and the
 * other an <h2>; the excerpt was .blog-card-text on one and .blog-card-excerpt
 * on the other. Both class sets carry their own CSS, so restyling the journal
 * card meant finding and editing both — and forgetting one was the normal
 * outcome.
 *
 * The listing's structure is the one kept, because it is the more capable of
 * the two: .blog-card-body is a flex column with flex:1, which is what lets a
 * row of cards share a height when the titles wrap to different depths.
 *
 * Usage:
 *   $blogPost = $row;                  // needs id, title, category, excerpt,
 *   include __DIR__ . '/blog_card.php';//       image, published_date
 *
 * Optional, set before including:
 *   $blogCardTitleTag    — 'h2' (default) or 'h3'. A listing card sits under the
 *                          page's own <h1>; a homepage card sits under the
 *                          section's <h2>, so the level genuinely differs.
 *   $blogCardExcerpt     — characters to trim the excerpt to; 0 for the full text
 *   $blogCardCta         — the link label, default 'Read Article'
 *   $blogCardDateFormat  — date() format, default 'F d, Y'
 *   $blogCardReveal      — false to leave off the scroll-entrance class
 */

$bcPost = $blogPost ?? null;
if (!is_array($bcPost) || empty($bcPost['id'])) { return; }

$bcTitleTag = in_array($blogCardTitleTag ?? 'h2', ['h2', 'h3'], true) ? ($blogCardTitleTag ?? 'h2') : 'h2';
$bcTrim     = (int)($blogCardExcerpt ?? 0);
$bcCta      = (string)($blogCardCta ?? 'Read Article');
$bcDateFmt  = (string)($blogCardDateFormat ?? 'F d, Y');
$bcReveal   = ($blogCardReveal ?? true) ? ' reveal-on-scroll' : '';

$bcUrl = SITE_URL . '/blog-single?id=' . (int)$bcPost['id'];

/* One resolver, shared with the article page and the admin list — lookbook
   filenames are deliberately NOT honoured here; see blogImageUrl(). Both callers
   already did this identically, and both cache-bust; it is gathered here so a
   third caller cannot get it wrong. */
$bcImg      = blogImageUrl(trim((string)($bcPost['image'] ?? '')));
$bcWebpUrl  = preg_replace('/\.[^.]+$/', '.webp', $bcImg);
$bcWebpFile = str_replace(SITE_URL . '/', __DIR__ . '/../', $bcWebpUrl);
$bcHasWebp  = webpSourceIsFresh($bcImg, $bcWebpFile);
$bcWebpUrl  = cacheBustedUploadUrl($bcWebpUrl);
$bcImg      = cacheBustedUploadUrl($bcImg);

$bcDate = !empty($bcPost['published_date'])
    ? date($bcDateFmt, strtotime((string)$bcPost['published_date']))
    : '';

$bcExcerpt = (string)($bcPost['excerpt'] ?? '');
if ($bcTrim > 0 && function_exists('trimToLength')) { $bcExcerpt = trimToLength($bcExcerpt, $bcTrim); }
?>
<article class="blog-card<?= $bcReveal ?>">
    <div class="blog-card-media">
        <a href="<?= htmlspecialchars($bcUrl) ?>">
            <picture>
                <?php if ($bcHasWebp): ?><source srcset="<?= htmlspecialchars($bcWebpUrl) ?>" type="image/webp"><?php endif; ?>
                <img src="<?= htmlspecialchars(pictureImgSrc($bcImg, $bcHasWebp ? $bcWebpUrl : null)) ?>" alt="<?= htmlspecialchars((string)$bcPost['title']) ?>"
                     class="blog-card-img" loading="lazy" decoding="async">
            </picture>
        </a>
    </div>
    <div class="blog-card-body">
        <div class="blog-card-meta">
            <span class="blog-card-category"><?= htmlspecialchars((string)($bcPost['category'] ?: 'Style Guide')) ?></span><?php
            if ($bcDate !== ''): ?> &bull; <span class="blog-card-date"><?= htmlspecialchars($bcDate) ?></span><?php endif; ?>
        </div>
        <<?= $bcTitleTag ?> class="blog-card-title">
            <a href="<?= htmlspecialchars($bcUrl) ?>"><?= htmlspecialchars((string)$bcPost['title']) ?></a>
        </<?= $bcTitleTag ?>>
        <?php if ($bcExcerpt !== ''): ?>
        <p class="blog-card-excerpt"><?= htmlspecialchars($bcExcerpt) ?></p>
        <?php endif; ?>
        <div>
            <a href="<?= htmlspecialchars($bcUrl) ?>" class="blog-card-link">
                <?= htmlspecialchars($bcCta) ?> <i class="fa-solid fa-arrow-right-long"></i>
            </a>
        </div>
    </div>
</article>
