<?php

/**
 * Shared MOS-GOV section navigation, rendered inside the page body.
 *
 * The tab list is derived from the entity registry, so a new governance
 * entity automatically appears in the navigation.
 *
 * Expected variables:
 * - $activeSlug  current URL slug, or null on the dashboard
 * - $esc         HTML-escaping closure
 */

use ChurchCRM\Plugins\MosGov\Data\GovRepository;

$mosGovRootPath = \ChurchCRM\dto\SystemURLs::getRootPath() . '/plugins/mos-gov';
$activeSlug = $activeSlug ?? null;
?>
<ul class="nav nav-pills flex-wrap gap-1 mb-3 d-print-none">
    <li class="nav-item">
        <a class="nav-link<?= $activeSlug === null ? ' active' : '' ?>"
           href="<?= $esc($mosGovRootPath) ?>">Dashboard</a>
    </li>
    <?php foreach (GovRepository::navigationMap() as $slug => $label): ?>
        <li class="nav-item">
            <a class="nav-link<?= $activeSlug === $slug ? ' active' : '' ?>"
               href="<?= $esc($mosGovRootPath . '/' . $slug) ?>"><?= $esc($label) ?></a>
        </li>
    <?php endforeach; ?>
</ul>
