<?php

/**
 * MOS-GOV governance dashboard.
 *
 * Expected variables (provided by the route):
 * - $stats        array{structures:int, bodies:int, open_issues:int, open_tasks:int} or null
 * - $statsError   optional safe error message when the data layer failed
 * - $esc          HTML-escaping closure
 */
require_once __DIR__ . '/../../../../Include/Header.php';
?>
<div class="container-xl">
    <div class="page-header d-print-none">
        <div class="row align-items-center">
            <div class="col">
                <h2 class="page-title">MOS-GOV</h2>
                <div class="text-secondary">Church governance layer &middot; V0.1</div>
            </div>
        </div>
    </div>

    <?php if (!empty($statsError)): ?>
        <div class="alert alert-danger" role="alert">
            <?= $esc($statsError) ?>
        </div>
    <?php endif; ?>

    <div class="row row-cards">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Governance dashboard</h3>
                </div>
                <div class="card-body">
                    <p>
                        MOS-GOV stores governance-specific data separately from
                        ChurchCRM core data.
                    </p>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <div class="card card-sm">
                                <div class="card-body">
                                    <div class="text-secondary">Structures</div>
                                    <div class="h2 mb-0"><?= isset($stats['structures']) ? (int) $stats['structures'] : '&mdash;' ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card card-sm">
                                <div class="card-body">
                                    <div class="text-secondary">Bodies</div>
                                    <div class="h2 mb-0"><?= isset($stats['bodies']) ? (int) $stats['bodies'] : '&mdash;' ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card card-sm">
                                <div class="card-body">
                                    <div class="text-secondary">Open issues</div>
                                    <div class="h2 mb-0"><?= isset($stats['open_issues']) ? (int) $stats['open_issues'] : '&mdash;' ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card card-sm">
                                <div class="card-body">
                                    <div class="text-secondary">Open tasks</div>
                                    <div class="h2 mb-0"><?= isset($stats['open_tasks']) ? (int) $stats['open_tasks'] : '&mdash;' ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 d-flex flex-wrap gap-2">
                        <a class="btn btn-outline-primary" href="<?= $esc($mosGovBase) ?>/structures">Structures</a>
                        <a class="btn btn-outline-primary" href="<?= $esc($mosGovBase) ?>/bodies">Bodies</a>
                        <a class="btn btn-outline-primary" href="<?= $esc($mosGovBase) ?>/roles">Roles</a>
                        <a class="btn btn-outline-primary" href="<?= $esc($mosGovBase) ?>/appointments">Appointments</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../../../Include/Footer.php'; ?>
