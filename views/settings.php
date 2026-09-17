<?php

/**
 * MOS-GOV 设置 / 状态页（安全设置）.
 *
 * 本页报告插件真实运行状态而非提供无效开关：治理表行数、当前用户权限、
 * 数据层健康状态与本地安全模式。
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

require __DIR__ . '/_i18n.php';

$sPageTitle = '安全设置';
$sPageSubtitle = '插件状态与当前权限';
$aBreadcrumbs = [
    ['label' => '插件', 'url' => SystemURLs::getRootPath() . '/plugins/management'],
    ['label' => 'MOS-GOV', 'url' => $mosGovRootPath],
    ['label' => '安全设置', 'active' => true],
];

require __DIR__ . '/../../../../Include/Header.php';

$activeSlug = 'settings';
require __DIR__ . '/_tabs.php';
?>

<?php if (!empty($statsError)): ?>
    <div class="alert alert-danger" role="alert">
        治理数据表不可读：<?= $esc($statsError) ?>
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">你的治理权限</h3>
            </div>
            <div class="card-body">
                <div class="datagrid">
                    <div class="datagrid-item">
                        <div class="datagrid-title">当前登录账号</div>
                        <div class="datagrid-content"><?= $esc($capabilities['userName'] ?? '（无）') ?></div>
                    </div>
                    <div class="datagrid-item">
                        <div class="datagrid-title">读取治理数据</div>
                        <div class="datagrid-content"><?= $capabilities['canRead'] ? '是' : '否' ?></div>
                    </div>
                    <div class="datagrid-item">
                        <div class="datagrid-title">修改治理数据</div>
                        <div class="datagrid-content"><?= $capabilities['canWrite'] ? '是' : '否' ?></div>
                    </div>
                    <div class="datagrid-item">
                        <div class="datagrid-title">ChurchCRM 管理员</div>
                        <div class="datagrid-content"><?= $capabilities['isAdmin'] ? '是' : '否' ?></div>
                    </div>
                </div>
                <p class="text-secondary small mb-0 mt-3">
                    V0.1 兼容路径下，遗留 CRUD 的写入仍限定于 ChurchCRM 管理员。
                    治理层权限由 MOS-GOV 授权引擎判定（治理身份、角色、任命、范围、信息分级）。
                </p>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">治理数据表</h3>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-vcenter">
                        <thead>
                            <tr><th>数据表</th><th>治理实体</th><th class="text-end">行数</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach (GovRepository::SLUG_TO_ENTITY as $slug => $entity): ?>
                            <?php $cfg = GovRepository::ENTITIES[$entity]; ?>
                            <tr>
                                <td><code><?= $esc($cfg['table']) ?></code></td>
                                <td>
                                    <a href="<?= $esc($mosGovRootPath . '/' . $slug) ?>">
                                        <?= $esc($mosGovEntityLabelPlural((string) $entity)) ?>
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
                    行数实时读取自 MOS-GOV 数据层。结构定义：<code>database/001_initial.sql</code>
                    （10 张表，幂等）。
                </p>
            </div>
        </div>
    </div>
</div>

<div class="card mt-3">
    <div class="card-body">
        <p class="text-secondary mb-0">
            插件本身没有可调配置项；启用/停用由
            <a href="<?= $esc(SystemURLs::getRootPath() . '/plugins/management') ?>">插件管理</a>负责。
        </p>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header">
        <h3 class="card-title">本地 / LAN 安全模式（V0.2）</h3>
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
                <div class="datagrid-title">策略说明</div>
                <div class="datagrid-content"><?= $esc($secureModeDescription ?? '') ?></div>
            </div>
            <div class="datagrid-item">
                <div class="datagrid-title">对外网络</div>
                <div class="datagrid-content">DENY —— MOS-GOV 不进行任何外部 API、云服务、统计、邮件或地图调用。</div>
            </div>
            <div class="datagrid-item">
                <div class="datagrid-title">公网绑定</div>
                <div class="datagrid-content">DENY —— 仅部署在本机或可信内网，绝不暴露到公网。</div>
            </div>
            <div class="datagrid-item">
                <div class="datagrid-title">数据库</div>
                <div class="datagrid-content">MariaDB 仅允许本机 / Docker 内部网络 / 可信内网访问；3306 端口不得对外暴露。</div>
            </div>
            <div class="datagrid-item">
                <div class="datagrid-title">备份</div>
                <div class="datagrid-content">保留一份本地备份 + 一份离线副本；治理数据不允许只存在一份。</div>
            </div>
        </div>
        <p class="text-secondary small mb-0 mt-3">
            通过环境变量 <code>MOS_GOV_SECURITY_MODE=LOCAL|LAN</code> 设置模式；
            Docker 部署下本机浏览器的来源地址（bridge 网关）已在 LOCAL 模式显式允许。
            CRM 收口指引见 <code>docs/V02-CRM-CUTDOWN.md</code>。
        </p>
    </div>
</div>

<?php require __DIR__ . '/../../../../Include/Footer.php'; ?>
