<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';

$pageTitle = "Returns & Refunds Policy | Dievon";
// Its own description. These pages all fell back to the shop-wide default,
// so ten indexable URLs described themselves with one identical sentence.
$metaDescription = "How to return or exchange a Dievon garment: the window, the condition we need it in, who pays return postage, and how refunds are issued.";
require_once __DIR__ . '/../includes/header.php';
?>

<?php // Slot 4 — the shared policy-page photograph. ?>
<section class="luxury-hero has-bg-image" style="--hero-bg-image: url('<?= lookbookUrl(4) ?>')">
    <div class="container">
        <span class="luxury-hero-eyebrow">Assurance &amp; Guarantees</span>
        <h1>Returns &amp; Refunds Policy</h1>
        <?php /* Reads RETURN_WINDOW_DAYS like every other page that states this
                 number — pages/terms.php, pages/product.php and pages/account.php
                 all already did. This page was the only one with the figure typed
                 in by hand, in three places, which made it the one page that would
                 keep quoting the old window after the policy changed. It is also
                 the page a customer opens to argue with, and the account screen
                 enforces the real constant when deciding if a return is still
                 allowed — so the drift would have shown up as the shop refusing a
                 return its own policy page appeared to promise. */ ?>
        <p><?= (int)RETURN_WINDOW_DAYS ?>-day luxury return guarantee, exchanges, and full refund processing.</p>
    </div>
</section>

<section class="section-space">
    <?php /* Uses the .legal-* classes that pages/privacy.php and pages/terms.php
             already use. Every declaration here was previously an inline style
             repeating those same values by hand — so the four legal pages could
             drift apart, and none of them could be restyled or made responsive
             without editing markup. The rendered result is identical. */ ?>
    <?php /* .legal-page, the same wrapper privacy.php and terms.php use, rather
             than the boxed .legal-panel this page had to itself.
             ────────────────────────────────────────────────────────────────
             Four pages sit behind one "Customer Service" list in the footer and
             a reader moves between them, so meeting a bordered card on two of
             them and plain text on the other two reads as two different sites.
             The box also cost 40px of padding on every side, which on a phone
             is most of the reading width spent on a border. */ ?>
    <div class="container">
        <div class="reveal-on-scroll legal-page">
            <?php /* Named for what this section actually covers, not for the page.
                     It read "<?= (int)RETURN_WINDOW_DAYS ?>-Day Return Policy" directly beneath the
                     page title "Returns & Refunds Policy", so the first thing a reader
                     met was the same words twice and it looked like the page had two
                     titles. Every other legal page already avoids this: Shipping opens
                     with "Shipping Timelines & Dispatch", Terms and Privacy with "The
                     short version" — the heading tells you what is in the section. */ ?>
            <h2 class="legal-heading">1. Your <?= (int)RETURN_WINDOW_DAYS ?>-Day Return Window</h2>
            <p class="legal-text">We want you to be completely delighted with your purchase. You may return unworn, unaltered garments in original luxury packaging with all tags attached within <?= (int)RETURN_WINDOW_DAYS ?> days of delivery.</p>

            <h2 class="legal-heading">2. How to Request a Return</h2>
            <p class="legal-text">To initiate a return, visit your customer account dashboard or email <a href="mailto:<?= htmlspecialchars(shopContactEmail($pdo ?? null)) ?>" class="legal-link"><?= htmlspecialchars(shopContactEmail($pdo ?? null)) ?></a> with your order number &mdash; it looks like <strong><?= htmlspecialchars(orderCodeExample()) ?></strong> and is on your confirmation email and your invoice. We will reply with the return address and confirm how the item should be sent back.</p>

            <?php /* Who pays to send the garment back.
                     ───────────────────────────────────────────────────────────────────────────
                     The page said how to start a return and how the refund is
                     issued, but never who bears the postage in between — the
                     question a customer weighs before deciding whether to return
                     at all, and one Google Merchant Center checks when it
                     validates the returns policy behind a product listing. */ ?>
            <h2 class="legal-heading">3. Return Shipping Costs</h2>
            <p class="legal-text">If the garment reached you faulty or damaged, or is not the item you ordered, Dievon covers the cost of sending it back &mdash; tell us when you request the return and we will confirm how to send it at our expense. For any other reason, including a change of mind or a different size, the cost of returning the garment to us is the customer’s.</p>

            <?php /* Exchanges, said out loud.
                     ───────────────────────────────────────────────────────────────────────────
                     The page described returns and refunds and never mentioned an
                     exchange, while the meta description and the subtitle both
                     promised them — a claim visible to a crawler and to nobody
                     reading the page. Merchant Center asks whether exchanges are
                     accepted and then checks the answer against this page, so the
                     declaration could not honestly be made either way.

                     Size is the reason this matters for a garment shop: 36 to 44 on
                     one kurta set, bought without trying it on. "Send it back and
                     order again" is two postages and a wait; asking for the next size
                     up is the thing customers actually want.

                     Conditions are deliberately the same as a return rather than a
                     second set to remember — same window, same state, same route in.
                     Postage follows section 3 for the same reason. */ ?>
            <h2 class="legal-heading">4. Exchanges</h2>
            <p class="legal-text">If a piece does not fit, you may exchange it for another size or colour of the same garment on the same terms as a return &mdash; unworn, unaltered, in its original packaging with tags attached, within <?= (int)RETURN_WINDOW_DAYS ?> days of delivery. Request it the way you would a return and tell us the size or colour you would like instead. Exchanges depend on that piece being in stock; if it is not, we will refund you in full rather than keep you waiting. Return postage follows the rule above &mdash; ours if the garment was faulty or wrongly sent, yours if the size or the choice has changed &mdash; and we cover the cost of sending the replacement out to you.</p>

            <h2 class="legal-heading">5. Refund Processing</h2>
            <p class="legal-text-last">Once your returned garment passes boutique inspection, your refund will be processed back to your original payment method within 3–5 business days.</p>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
