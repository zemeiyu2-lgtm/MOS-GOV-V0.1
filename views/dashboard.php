<?php

/**
 * MOS-GOV governance dashboard.
 *
 * Every figure on this page comes from the governance data layer
 * (GovRepository) — the view contains no SQL and no data access.
 *
 * Expected variables (provided by the route):
 * - $stats                array<string,int> dashboard counters, or null on failure
 * - $statsError           safe error message when the counters could not be read
 * - $recentMeetings       latest governance meetings
 * - $recentDecisions      latest governance decisions
 * - $meetingDecorations   row id => ['ref' => ..., 'person' => ...] for meetings
 * - $decisionDecorations  row id => ['ref' => ..., 'person' => ...] for decisions
 * - $recentError          safe error message when the recent lists failed
 * - $canWrite             whether the current user may modify governance data
 * - $navigation           slug => plural label
 * - $esc                  HTML-escaping closure
 */

use ChurchCRM\dto\SystemURLs;

$mosGovRootPath = SystemURLs::getRootPath() . '/plugins/mos-gov';

$sPageTitle = 'MOS-GOV';
$sPageSubtitle = 'Church governance overview (V0.1)';
$aBreadcrumbs = [
    ['label' => 'Plugins', 'url' => SystemURLs::getRootPath() . '/plugins/management'],
    ['label' => 'MOS-GOV', 'active' => true],
];

require __DIR__ . '/../../../../Include/Header.php';

$activeSlug = null;
require __DIR__ . '/_tabs.php';

/** Render one counter tile. */
$mosGovTile = static function (string $label, $value, string $href = '') use ($esc): void {
    ?>
    <div class="col-sm-6 col-lg-3">
        <div class="card card-sm">
            <div class="card-body">
                <div class="text-secondary"><?= $esc($label) ?></div>
                <div class="h2 mb-0"><?= $value === null ? '&mdash;' : (int) $value ?></div>
                <?php if ($href !== ''): ?>
                    <a class="small" href="<?= $esc($href) ?>">Open list</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
};
?>

<?php if (!empty($statsError)): ?>
    <div class="alert alert-danger" role="alert">
        Governance data is unavailable: <?= $esc($statsError) ?>
    </div>
<?php endif; ?>

<?php if (empty($canWrite)): ?>
    <div class="alert alert-info" role="alert">
        You have read-only access to governance data.
        Modifying MOS-GOV records requires ChurchCRM administrator rights.
    </div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-header">
        <h3 class="card-title">Governance dashboard</h3>
    </div>
    <div class="card-body">
        <p class="text-secondary">
            MOS-GOV stores governance-specific data separately from ChurchCRM
            core data. ChurchCRM remains the source of people, families, groups
            and events; MOS-GOV records reference them by ID.
        </p>
        <div class="row g-3">
            <?php
            $mosGovTile('Structures', $stats['structures'] ?? null, $mosGovRootPath . '/structures');
            $mosGovTile('Bodies', $stats['bodies'] ?? null, $mosGovRootPath . '/bodies');
            $mosGovTile('Open issues', $stats['open_issues'] ?? null, $mosGovRootPath . '/issues');
            $mosGovTile('Open tasks', $stats['open_tasks'] ?? null, $mosGovRootPath . '/tasks');
            ?>
        </div>
        <div class="row g-3 mt-0">
            <?php
            $mosGovTile('Roles', $stats['roles'] ?? null, $mosGovRootPath . '/roles');
            $mosGovTile('Appointments', $stats['appointments'] ?? null, $mosGovRootPath . '/appointments');
            $mosGovTile('Responsibilities', $stats['responsibilities'] ?? null, $mosGovRootPath . '/responsibilities');
            $mosGovTile('Relationships', $stats['relationships'] ?? null, $mosGovRootPath . '/relationships');
            $mosGovTile('Meetings', $stats['meetings'] ?? null, $mosGovRootPath . '/meetings');
            $mosGovTile('Issues (all)', $stats['issues'] ?? null, $mosGovRootPath . '/issues');
            $mosGovTile('Decisions', $stats['decisions'] ?? null, $mosGovRootPath . '/decisions');
            $mosGovTile('Tasks (all)', $stats['tasks'] ?? null, $mosGovRootPath . '/tasks');
            ?>
        </div>
    </div>
</div>

<?php if (!empty($recentError)): ?>
    <div class="alert alert-warning" role="alert">
        Recent governance activity could not be loaded: <?= $esc($recentError) ?>
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header d-flex align-items-center">
                <h3 class="card-title">Recent governance meetings</h3>
                <div class="ms-auto">
                    <a class="btn btn-sm btn-outline-primary" href="<?= $esc($mosGovRootPath . '/meetings') ?>">All meetings</a>
                </div>
            </div>
            <div class="card-body">
                <?php if ($recentMeetings === []): ?>
                    <p class="text-secondary mb-0">No governance meetings recorded yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-vcenter card-table">
                            <thead>
                                <tr><th>Meeting</th><th>Body</th><th>Date</th><th>Status</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($recentMeetings as $row): ?>
                                <?php $dec = $meetingDecorations[(int) $row['id']] ?? ['ref' => [], 'person' => []]; ?>
                                <tr>
                                    <td>
                                        <a href="<?= $esc($mosGovRootPath . '/meetings/' . (int) $row['id']) ?>">
                                            <?= $esc($row['title'] ?? '') ?>
                                        </a>
                                    </td>
                                    <td><?= $esc($dec['ref']['body_id'] ?? '') ?></td>
                                    <td><?= $esc($row['meeting_date'] ?? '') ?></td>
                                    <td><?= $esc($row['status'] ?? '') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card">
            <div class="card-header d-flex align-items-center">
                <h3 class="card-title">Recent governance decisions</h3>
                <div class="ms-auto">
                    <a class="btn btn-sm btn-outline-primary" href="<?= $esc($mosGovRootPath . '/decisions') ?>">All decisions</a>
                </div>
            </div>
            <div class="card-body">
                <?php if ($recentDecisions === []): ?>
                    <p class="text-secondary mb-0">No governance decisions recorded yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-vcenter card-table">
                            <thead>
                                <tr><th>Decision</th><th>Related issue</th><th>Status</th><th>Decided</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($recentDecisions as $row): ?>
                                <?php $dec = $decisionDecorations[(int) $row['id']] ?? ['ref' => [], 'person' => []]; ?>
                                <tr>
                                    <td>
                                        <a href="<?= $esc($mosGovRootPath . '/decisions/' . (int) $row['id']) ?>">
                                            <?= $esc($row['title'] ?? '') ?>
                                        </a>
                                    </td>
                                    <td><?= $esc($dec['ref']['issue_id'] ?? '') ?></td>
                                    <td><?= $esc($row['decision_status'] ?? '') ?></td>
                                    <td><?= $esc($row['decided_at'] ?? '') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card mt-3">
    <div class="card-body">
        <p class="text-secondary mb-2">
            The governance working loop in V0.1:
        </p>
        <p class="mb-0">
            <code>Structure → Body → Role → Appointment → Responsibility</code><br>
            <code>Meeting → Issue → Decision → Task</code>
        </p>
    </div>
</div>

<?php require __DIR__ . '/../../../../Include/Footer.php'; ?>
