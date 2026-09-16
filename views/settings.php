<?php

/**
 * MOS-GOV settings / status page.
 *
 * V0.1 keeps configuration deliberately minimal (no tunables yet), so this
 * page reports the plugin's actual runtime state instead of offering
 * settings that do nothing: governance table row counts, the current user's
 * capabilities, and the data-layer health.
 *
 * Expected variables:
 * - $stats         array<string,int> row counts, or null on failure
 * - $statsError    safe error message when counts could not be read
 * - $capabilities  GovAuthorization::capabilitySummary()
 * - $canWrite      whether the current user may modify governance data
 * - $secureMode    effective MOS_GOV_SECURITY_MODE (LOCAL/LAN/OFF)
 * - $secureModeDescription  human description of the mode
 * - $esc           HTML-escaping closure
 */

use ChurchCRM\Plugins\MosGov\Data\GovRepository;
use ChurchCRM\dto\SystemURLs;

$mosGovRootPath = SystemURLs::getRootPath() . '/plugins/mos-gov';

$sPageTitle = 'MOS-GOV settings';
$sPageSubtitle = 'Plugin status and current permissions';
$aBreadcrumbs = [
    ['label' => 'Plugins', 'url' => SystemURLs::getRootPath() . '/plugins/management'],
    ['label' => 'MOS-GOV', 'url' => $mosGovRootPath],
    ['label' => 'Settings', 'active' => true],
];

require __DIR__ . '/../../../../Include/Header.php';

$activeSlug = null;
require __DIR__ . '/_tabs.php';
?>

<?php if (!empty($statsError)): ?>
    <div class="alert alert-danger" role="alert">
        Governance tables are not readable: <?= $esc($statsError) ?>
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Your governance permissions</h3>
            </div>
            <div class="card-body">
                <div class="datagrid">
                    <div class="datagrid-item">
                        <div class="datagrid-title">Signed in as</div>
                        <div class="datagrid-content"><?= $esc($capabilities['userName'] ?? '(none)') ?></div>
                    </div>
                    <div class="datagrid-item">
                        <div class="datagrid-title">Read governance data</div>
                        <div class="datagrid-content"><?= $capabilities['canRead'] ? 'Yes' : 'No' ?></div>
                    </div>
                    <div class="datagrid-item">
                        <div class="datagrid-title">Modify governance data</div>
                        <div class="datagrid-content"><?= $capabilities['canWrite'] ? 'Yes' : 'No' ?></div>
                    </div>
                    <div class="datagrid-item">
                        <div class="datagrid-title">ChurchCRM administrator</div>
                        <div class="datagrid-content"><?= $capabilities['isAdmin'] ? 'Yes' : 'No' ?></div>
                    </div>
                </div>
                <p class="text-secondary small mb-0 mt-3">
                    Governance writes are restricted to ChurchCRM administrators in V0.1.
                    This is enforced by the MOS-GOV authorization layer (R07), which
                    delegates identity and roles to ChurchCRM itself.
                </p>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Governance tables</h3>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-vcenter">
                        <thead>
                            <tr><th>Table</th><th>Entity</th><th class="text-end">Rows</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach (GovRepository::SLUG_TO_ENTITY as $slug => $entity): ?>
                            <?php $cfg = GovRepository::ENTITIES[$entity]; ?>
                            <tr>
                                <td><code><?= $esc($cfg['table']) ?></code></td>
                                <td>
                                    <a href="<?= $esc($mosGovRootPath . '/' . $slug) ?>">
                                        <?= $esc($cfg['labelPlural']) ?>
                                    </a>
                                </td>
                                <td class="text-end">
                                    <?= $stats === null ? '&mdash;' : (int) ($stats[$slug] ?? 0) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="text-secondary small mb-0">
                    Counts are read live through the MOS-GOV data layer.
                    Schema: <code>database/001_initial.sql</code> (10 tables, idempotent).
                </p>
            </div>
        </div>
    </div>
</div>

<div class="card mt-3">
    <div class="card-body">
        <p class="text-secondary mb-0">
            V0.1 has no configurable options. Plugin activation is managed from
            <a href="<?= $esc(SystemURLs::getRootPath() . '/plugins/management') ?>">Plugin Management</a>.
        </p>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header">
        <h3 class="card-title">Local / LAN secure mode (V0.2)</h3>
    </div>
    <div class="card-body">
        <div class="datagrid">
            <div class="datagrid-item">
                <div class="datagrid-title">MOS_GOV_SECURITY_MODE</div>
                <div class="datagrid-content">
                    <span class="badge bg-primary-lt"><?= $esc($secureMode ?? 'LOCAL') ?></span>
                </div>
            </div>
            <div class="datagrid-item">
                <div class="datagrid-title">Policy</div>
                <div class="datagrid-content"><?= $esc($secureModeDescription ?? '') ?></div>
            </div>
            <div class="datagrid-item">
                <div class="datagrid-title">Outbound network</div>
                <div class="datagrid-content">DENY — MOS-GOV performs no external API, cloud, analytics, mail or map calls.</div>
            </div>
            <div class="datagrid-item">
                <div class="datagrid-title">Public binding</div>
                <div class="datagrid-content">DENY — deploy on localhost or a trusted LAN only; never expose to the public internet.</div>
            </div>
            <div class="datagrid-item">
                <div class="datagrid-title">Database</div>
                <div class="datagrid-content">MariaDB must stay on localhost / Docker internal / trusted LAN. Port 3306 must not be exposed.</div>
            </div>
            <div class="datagrid-item">
                <div class="datagrid-title">Backup</div>
                <div class="datagrid-content">Keep one local backup plus one offline copy. Governance data must never exist only once.</div>
            </div>
        </div>
        <p class="text-secondary small mb-0 mt-3">
            Set the mode with the environment variable <code>MOS_GOV_SECURITY_MODE=LOCAL|LAN</code>.
            CRM surface reduction guidance: see <code>docs/V02-CRM-CUTDOWN.md</code>.
        </p>
    </div>
</div>

<?php require __DIR__ . '/../../../../Include/Footer.php'; ?>
