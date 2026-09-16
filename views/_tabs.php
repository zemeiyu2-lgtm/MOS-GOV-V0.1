<?php

/**
 * Shared MOS-GOV section navigation (V0.2 §41 — governance-first).
 *
 * The ten V0.1 entity tabs are grouped into governance meaning instead of a
 * CRM-like flat list:
 *
 *   教会治理  Church governance  — structures, bodies, roles, appointments
 *   治理运行  Governance running — responsibilities, relationships, meetings,
 *                                  issues, decisions, tasks
 *   治理身份  Identity           — identities, permission registry
 *   安全设置  Security           — settings
 *
 * Expected variables:
 * - $activeSlug  current URL slug, or null on the dashboard
 * - $esc         HTML-escaping closure
 */

use ChurchCRM\dto\SystemURLs;
use ChurchCRM\Plugins\MosGov\Data\GovRepository;

$mosGovRootPath = SystemURLs::getRootPath() . '/plugins/mos-gov';
$activeSlug = $activeSlug ?? null;

$mosGovNavGroups = [
    '' => [
        'dashboard' => ['label' => 'Governance Home', 'url' => $mosGovRootPath],
        'my-governance' => ['label' => 'My Governance', 'url' => $mosGovRootPath . '/my-governance'],
    ],
    'Church governance' => [
        'structures' => ['label' => 'Structures', 'url' => $mosGovRootPath . '/structures'],
        'bodies' => ['label' => 'Bodies', 'url' => $mosGovRootPath . '/bodies'],
        'roles' => ['label' => 'Roles', 'url' => $mosGovRootPath . '/roles'],
        'appointments' => ['label' => 'Appointments', 'url' => $mosGovRootPath . '/appointments'],
        'responsibilities' => ['label' => 'Responsibilities', 'url' => $mosGovRootPath . '/responsibilities'],
    ],
    'Governance running' => [
        'meetings' => ['label' => 'Meetings', 'url' => $mosGovRootPath . '/meetings'],
        'issues' => ['label' => 'Issues', 'url' => $mosGovRootPath . '/issues'],
        'decisions' => ['label' => 'Decisions', 'url' => $mosGovRootPath . '/decisions'],
        'tasks' => ['label' => 'Tasks', 'url' => $mosGovRootPath . '/tasks'],
        'relationships' => ['label' => 'Relationships', 'url' => $mosGovRootPath . '/relationships'],
    ],
    'Governance identity' => [
        'identity' => ['label' => 'Identities', 'url' => $mosGovRootPath . '/identity'],
        'permissions' => ['label' => 'Permission Registry', 'url' => $mosGovRootPath . '/permissions'],
        'search' => ['label' => 'Governance Search', 'url' => $mosGovRootPath . '/search'],
    ],
    'Security' => [
        'settings' => ['label' => 'Settings', 'url' => $mosGovRootPath . '/settings'],
    ],
];
?>
<div class="mb-3 d-print-none">
    <?php foreach ($mosGovNavGroups as $groupName => $items): ?>
        <?php if ($groupName !== ''): ?>
            <div class="text-secondary small text-uppercase mt-2 mb-1"><?= $esc($groupName) ?></div>
        <?php endif; ?>
        <ul class="nav nav-pills flex-wrap gap-1 mb-1">
            <?php foreach ($items as $slug => $item): ?>
                <li class="nav-item">
                    <a class="nav-link<?= $activeSlug === $slug || ($activeSlug === null && $slug === 'dashboard') ? ' active' : '' ?>"
                       href="<?= $esc($item['url']) ?>"><?= $esc($item['label']) ?></a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endforeach; ?>
</div>
