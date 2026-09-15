<?php

/**
 * MOS-GOV standalone error page.
 *
 * Used for CSRF rejections, missing records and data-layer failures. It is
 * deliberately standalone (no ChurchCRM header/footer) so it also renders
 * when the failure happened while bootstrapping a plugin page.
 *
 * Expected variables:
 * - $message safe, pre-validated error text
 * - $status  optional HTTP status shown to the user (defaults to rejected)
 */

use ChurchCRM\dto\SystemURLs;

$mosGovRootPath = SystemURLs::getRootPath() . '/plugins/mos-gov';
$status = $status ?? 400;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MOS-GOV &mdash; Request rejected</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(SystemURLs::getRootPath() . '/skin/v2/churchcrm.min.css', ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="antialiased">
<div class="page page-center">
    <div class="container-tight py-4">
        <div class="card">
            <div class="card-body text-center">
                <div class="display-6 mb-2">MOS-GOV</div>
                <div class="text-secondary mb-3">Request rejected (HTTP <?= (int) $status ?>)</div>
                <p><?= htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') ?></p>
                <div class="btn-list justify-content-center mt-4">
                    <a class="btn btn-outline-secondary" href="javascript:history.back()">Go back</a>
                    <a class="btn btn-primary"
                       href="<?= htmlspecialchars($mosGovRootPath, ENT_QUOTES, 'UTF-8') ?>">MOS-GOV dashboard</a>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
