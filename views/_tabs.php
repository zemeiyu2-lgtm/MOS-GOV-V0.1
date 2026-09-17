<?php

/**
 * Shared MOS-GOV section navigation (V0.2 §41 — governance-first).
 *
 * 简体中文导航。一级入口固定为「治理首页」与「我的治理中心」，其余按治理含义分组：
 *
 *   教会治理  结构 · 治理主体 · 角色 · 任命 · 职责
 *   治理运行  会议 · 议题 · 决策 · 任务 · 关系
 *   治理身份  治理身份 · 权限注册表 · 治理搜索
 *   安全设置  安全设置
 *
 * Expected variables:
 * - $activeSlug  current URL slug, or null on the dashboard
 * - $esc         HTML-escaping closure
 */

use ChurchCRM\dto\SystemURLs;

$mosGovRootPath = SystemURLs::getRootPath() . '/plugins/mos-gov';
$activeSlug = $activeSlug ?? null;

$mosGovNavSections = [
    [
        'header' => null,
        'items' => [
            'dashboard' => ['label' => '治理首页', 'url' => $mosGovRootPath],
            'my-governance' => ['label' => '我的治理中心', 'url' => $mosGovRootPath . '/my-governance'],
        ],
    ],
    [
        'header' => '教会治理',
        'items' => [
            'structures' => ['label' => '结构', 'url' => $mosGovRootPath . '/structures'],
            'bodies' => ['label' => '治理主体', 'url' => $mosGovRootPath . '/bodies'],
            'roles' => ['label' => '角色', 'url' => $mosGovRootPath . '/roles'],
            'appointments' => ['label' => '任命', 'url' => $mosGovRootPath . '/appointments'],
            'responsibilities' => ['label' => '职责', 'url' => $mosGovRootPath . '/responsibilities'],
        ],
    ],
    [
        'header' => '治理运行',
        'items' => [
            'meetings' => ['label' => '会议', 'url' => $mosGovRootPath . '/meetings'],
            'issues' => ['label' => '议题', 'url' => $mosGovRootPath . '/issues'],
            'decisions' => ['label' => '决策', 'url' => $mosGovRootPath . '/decisions'],
            'tasks' => ['label' => '任务', 'url' => $mosGovRootPath . '/tasks'],
            'relationships' => ['label' => '关系', 'url' => $mosGovRootPath . '/relationships'],
        ],
    ],
    [
        'header' => '治理身份',
        'items' => [
            'identity' => ['label' => '治理身份', 'url' => $mosGovRootPath . '/identity'],
            'permissions' => ['label' => '权限注册表', 'url' => $mosGovRootPath . '/permissions'],
            'search' => ['label' => '治理搜索', 'url' => $mosGovRootPath . '/search'],
        ],
    ],
    [
        'header' => null,
        'items' => [
            'settings' => ['label' => '安全设置', 'url' => $mosGovRootPath . '/settings'],
        ],
    ],
];
?>
<div class="mb-3 d-print-none">
    <?php foreach ($mosGovNavSections as $section): ?>
        <?php if (!empty($section['header'])): ?>
            <div class="text-secondary small mt-2 mb-1"><?= $esc($section['header']) ?></div>
        <?php endif; ?>
        <ul class="nav nav-pills flex-wrap gap-1 mb-1">
            <?php foreach ($section['items'] as $slug => $item): ?>
                <li class="nav-item">
                    <a class="nav-link<?= $activeSlug === $slug || ($activeSlug === null && $slug === 'dashboard') ? ' active' : '' ?>"
                       href="<?= $esc($item['url']) ?>"><?= $esc($item['label']) ?></a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endforeach; ?>
</div>
