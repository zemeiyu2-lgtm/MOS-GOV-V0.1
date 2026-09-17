<?php

/**
 * MOS-GOV 独立错误页.
 *
 * 用于 CSRF 拒绝、记录不存在与数据层失败。刻意独立（不套 ChurchCRM 头尾），
 * 以便在引导插件页面的过程中失败时仍可渲染。
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
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MOS-GOV &mdash; 请求被拒绝</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(SystemURLs::getRootPath() . '/skin/v2/churchcrm.min.css', ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="antialiased">
<div class="page page-center">
    <div class="container-tight py-4">
        <div class="card">
            <div class="card-body text-center">
                <div class="display-6 mb-2">MOS-GOV</div>
                <div class="text-secondary mb-3">教会治理平台 · 请求被拒绝（HTTP <?= (int) $status ?>）</div>
                <p><?= htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') ?></p>
                <div class="btn-list justify-content-center mt-4">
                    <a class="btn btn-outline-secondary" href="javascript:history.back()">返回上一页</a>
                    <a class="btn btn-primary"
                       href="<?= htmlspecialchars($mosGovRootPath, ENT_QUOTES, 'UTF-8') ?>">治理首页</a>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
