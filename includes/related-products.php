<?php
/* Rekomendasi produk ("You may also like") untuk halaman produk.
   Butuh: $relatedProducts (array), $currentCurrency (string),
          dan helper formatCurrency() + getFinalPriceProd() dari product.php. */
if (empty($relatedProducts)) return;
?>
<section class="pd-related" aria-labelledby="relatedTitle">
  <div class="pd-related__head">
    <h2 id="relatedTitle">You may also like</h2>
    <a class="head-link" href="shop.php">View all</a>
  </div>

  <div class="pd-related__track">
    <?php foreach ($relatedProducts as $related): ?>
      <?php
        $relName    = strtoupper((string) $related['name']);
        $relInStock = (int) $related['is_in_stock'] === 1;
        $relFinal   = getFinalPriceProd($related, $currentCurrency);
        $relOrig    = getBasePriceProd($related, $currentCurrency);
        $relOnSale  = $relInStock && $relFinal < $relOrig;
      ?>
      <a class="rel-card" href="product.php?slug=<?= urlencode((string) $related['slug']) ?>">
        <div class="rel-card__img">
          <?php if ($relOnSale): ?><span class="rel-card__sale">Sale</span><?php endif; ?>
          <img src="<?= htmlspecialchars((string) $related['thumbnail']) ?>"
               alt="<?= htmlspecialchars($relName) ?>" loading="lazy">
        </div>
        <div class="rel-card__info">
          <h3><?= htmlspecialchars($relName) ?></h3>
          <?php if (!$relInStock): ?>
            <span class="rel-card__sold">SOLD OUT</span>
          <?php else: ?>
            <p class="rel-card__price">
              <?php if ($relOnSale): ?>
                <span class="was"><?= htmlspecialchars(formatCurrency($relOrig, $currentCurrency)) ?></span>
              <?php endif; ?>
              <span><?= htmlspecialchars(formatCurrency($relFinal, $currentCurrency)) ?></span>
            </p>
          <?php endif; ?>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
</section>
