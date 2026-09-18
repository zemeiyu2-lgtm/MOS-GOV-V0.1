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
 * 呈现为一张安静的导航条：主入口加粗、分组以浅灰小标签引导、当前页高亮。
 * 仅表现层 — 不改变任何路由、权限或数据。
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
        'primary' => true,
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
<div class="mb-3 d-print-none mos-gov">
    <nav class="mg-nav" aria-label="MOS-GOV 导航">
        <?php $mosGovFirstRow = true; ?>
        <?php foreach ($mosGovNavSections as $mosGovSection): ?>
            <div class="mg-nav-row<?= !empty($mosGovSection['primary']) ? ' mg-nav-primary' : '' ?>">
                <?php if (!$mosGovFirstRow): ?>
                    <span class="mg-nav-divider" aria-hidden="true"></span>
                <?php endif; ?>
                <?php if (!empty($mosGovSection['header'])): ?>
                    <span class="mg-nav-label"><?= $esc($mosGovSection['header']) ?></span>
                <?php endif; ?>
                <?php foreach ($mosGovSection['items'] as $mosGovSlug => $mosGovItem): ?>
                    <a class="mg-nav-link<?= $activeSlug === $mosGovSlug || ($activeSlug === null && $mosGovSlug === 'dashboard') ? ' active' : '' ?>"
                       href="<?= $esc($mosGovItem['url']) ?>"><?= $esc($mosGovItem['label']) ?></a>
                <?php endforeach; ?>
            </div>
            <?php $mosGovFirstRow = false; ?>
        <?php endforeach; ?>
    </nav>
</div>
