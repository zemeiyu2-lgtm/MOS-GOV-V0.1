<?php

/**
 * MOS-GOV 通用实体详情视图.
 *
 * 显示记录自身字段（含引用与 ChurchCRM 人员标签）以及注册表中配置的子集合，
 * 使治理链路从任一端都可追溯：
 *   结构 → 治理主体 → 角色 → 任命 → 职责
 *   会议 → 议题 → 决策 → 任务
 *
 * Expected variables (provided by the route):
 * - $cfg          entity config from GovRepository::ENTITIES
 * - $slug         URL slug (e.g. structures)
 * - $row          the record (assoc array) or null when not found
 * - $decorations  ['ref' => field => label, 'person' => field => label]
 * - $related      child collections: label, slug, fields, rows, decorations
 * - $error        optional safe error message
 * - $canWrite     whether the current user may edit
 * - $esc          HTML-escaping closure
 */

use ChurchCRM\dto\SystemURLs;
use ChurchCRM\Plugins\MosGov\Data\GovRepository;

$mosGovRootPath = SystemURLs::getRootPath() . '/plugins/mos-gov';
$listUrl = $mosGovRootPath . '/' . $slug;

require __DIR__ . '/_i18n.php';

$entityLabel = $mosGovEntityLabel((string) (GovRepository::entityForSlug($slug) ?? $slug));
$entityLabelPlural = $mosGovEntityLabelPlural((string) (GovRepository::entityForSlug($slug) ?? $slug));

$sPageTitle = $entityLabel . ($row !== null ? ' #' . (int) $row['id'] : '');
$sPageSubtitle = 'MOS-GOV 治理数据';
$aBreadcrumbs = [
    ['label' => '插件', 'url' => SystemURLs::getRootPath() . '/plugins/management'],
    ['label' => 'MOS-GOV', 'url' => $mosGovRootPath],
    ['label' => $entityLabelPlural, 'url' => $listUrl],
    ['label' => $row !== null ? '#' . (int) $row['id'] : '未找到', 'active' => true],
];
if ($row !== null && !empty($canWrite)) {
    $sPageHeaderButtons = '<a class="btn btn-primary" href="'
        . $esc($listUrl . '/' . (int) $row['id'] . '/edit') . '">编辑</a>';
}

require __DIR__ . '/../../../../Include/Header.php';

$activeSlug = $slug;
require __DIR__ . '/_tabs.php';
require __DIR__ . '/_style.php';

/** Format one value of the record for display. */
$mosGovValue2 = static function (array $cfg, string $field, array $row, array $dec) use ($esc, $mosGovValue, $mosGovT, $mosGovDecorLabel): string {
    $value = $row[$field] ?? null;
    $type = $cfg['fields'][$field]['type'] ?? 'text';

    if ($type === 'ref') {
        $label = $dec['ref'][$field] ?? null;

        return $label !== null ? $esc($mosGovDecorLabel($label)) : '<span class="text-secondary">&mdash;</span>';
    }
    if ($type === 'person') {
        $label = $dec['person'][$field] ?? null;

        return $label !== null ? $esc($label) : '<span class="text-secondary">&mdash;</span>';
    }
    if ($value === null || $value === '') {
        return '<span class="text-secondary">&mdash;</span>';
    }
    if ($type === 'textlong') {
        return nl2br($esc($value));
    }
    if ($type === 'status' || $type === 'select') {
        return $esc($mosGovValue($value));
    }

    // Plain text cells: enum-like values (committee / system / active / …)
    // are shown in Chinese; seed names go through the phrase map; free text
    // passes through unchanged.
    return $esc($mosGovT($mosGovValue($value)));
};

/** Format one cell of a related-collection row. */
$mosGovRelatedCell = static function (array $fields, array $row, array $dec) use ($esc, $mosGovDecorLabel): string {
    foreach ($fields as $field) {
        $value = $row[$field] ?? null;
        if ($value !== null && $value !== '') {
            return $esc($mosGovDecorLabel($dec['ref'][$field] ?? $dec['person'][$field] ?? $value));
        }
    }

    return '<span class="text-secondary">&mdash;</span>';
};
?>

<div class="mos-gov">
<?php if (!empty($error)): ?>
    <div class="alert alert-danger" role="alert"><?= $esc($mosGovMsg($error)) ?></div>
<?php elseif ($row === null): ?>
    <div class="alert alert-warning" role="alert">
        该<?= $esc($entityLabel) ?>不存在（可能已被删除）。
    </div>
    <p><a href="<?= $esc($listUrl) ?>">&larr; 返回<?= $esc($entityLabelPlural) ?>列表</a></p>
<?php else: ?>
    <div class="card">
        <div class="card-header d-flex align-items-center">
            <h3 class="card-title"><?= $esc($entityLabel) ?></h3>
            <div class="ms-auto d-flex gap-2">
                <?php if (!empty($canWrite)): ?>
                    <a class="btn btn-sm btn-primary" href="<?= $esc($listUrl . '/' . (int) $row['id'] . '/edit') ?>">编辑</a>
                <?php endif; ?>
                <a class="btn btn-sm btn-outline-secondary" href="<?= $esc($listUrl) ?>">返回列表</a>
            </div>
        </div>
        <div class="card-body">
            <div class="datagrid">
                <?php foreach ($cfg['fields'] as $field => $spec): ?>
                    <div class="datagrid-item">
                        <div class="datagrid-title"><?= $esc($mosGovT($spec['label'])) ?></div>
                        <div class="datagrid-content"><?= $mosGovValue2($cfg, $field, $row, $decorations) ?></div>
                    </div>
                <?php endforeach; ?>
                <div class="datagrid-item">
                    <div class="datagrid-title">创建时间</div>
                    <div class="datagrid-content"><?= $esc($row['created_at'] ?? '') ?></div>
                </div>
                <div class="datagrid-item">
                    <div class="datagrid-title">最后更新</div>
                    <div class="datagrid-content"><?= $esc($row['updated_at'] ?? '') ?></div>
                </div>
            </div>
        </div>
    </div>

    <?php
    // V1.0 整改闭环（DEV-07）：oversight 详情页的受控操作面板。
    // 权限规则在 RemediationService（责任人提交证据、复核人复核、禁自批）；
    // 面板只做呈现，服务端拒绝时返回明确错误页。
    if ($slug === 'oversight' && !empty($canWrite) && isset($remediationActor)):
        $hbActor = $remediationActor();
        $hbOpen = in_array($row['oversight_status'] ?? '', ['open', 'in_progress'], true);
        $hbAction = $mosGovRootPath . '/oversight/' . (int) $row['id'];
        ?>
        <div class="card mt-3 mg-card">
            <div class="card-header"><h3 class="card-title">整改闭环（V1.0）</h3></div>
            <div class="card-body">
                <?php if (!$hbOpen): ?>
                    <p class="text-secondary mb-0">
                        该监督记录已归档（<?= $esc($mosGovValue((string) ($row['outcome'] ?? 'pending'))) ?>）。
                        复核结论为终态记录；如需重新整改，请针对同一问题新建下一轮整改任务与监督记录。
                    </p>
                <?php else: ?>
                    <div class="row g-3">
                        <div class="col-lg-6">
                            <h4 class="mb-2">提交整改证据</h4>
                            <p class="text-secondary small">
                                证据引用为外部凭证的文本引用（本平台不存储文件）；提交证据不等于整改通过，通过与否由复核结论决定。
                            </p>
                            <form method="post" action="<?= $esc($hbAction . '/evidence') ?>">
                                <?= $csrfField ?? '' ?>
                                <div class="mb-2">
                                    <label class="form-label">证据引用</label>
                                    <input class="form-control" name="evidence_ref" maxlength="190"
                                           value="<?= $esc((string) ($row['evidence_ref'] ?? '')) ?>" placeholder="例如：会议纪要 2026-10-21 §3 或文件编号">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label">证据说明</label>
                                    <textarea class="form-control" name="evidence_notes" rows="3"><?= $esc((string) ($row['evidence_notes'] ?? '')) ?></textarea>
                                </div>
                                <button class="btn btn-primary btn-sm" type="submit">提交证据</button>
                            </form>
                        </div>
                        <div class="col-lg-6">
                            <h4 class="mb-2">复核结论</h4>
                            <p class="text-secondary small">
                                仅监督人（或治理权限管理员）可复核；整改责任人不得复核自己的整改。
                                通过/部分通过要求已有证据；退回后原记录保留，可新建下一轮整改。
                            </p>
                            <form method="post" action="<?= $esc($hbAction . '/review') ?>">
                                <?= $csrfField ?? '' ?>
                                <div class="mb-2">
                                    <label class="form-label">复核结论</label>
                                    <select class="form-select" name="outcome">
                                        <option value="pass">通过（pass）</option>
                                        <option value="partial">部分通过（partial）</option>
                                        <option value="fail">退回整改（fail）</option>
                                    </select>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label">复核意见</label>
                                    <textarea class="form-control" name="outcome_notes" rows="3"><?= $esc((string) ($row['outcome_notes'] ?? '')) ?></textarea>
                                </div>
                                <button class="btn btn-outline-primary btn-sm" type="submit">提交复核结论</button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php foreach ($related as $group): ?>
        <div class="card mt-3 mg-card">
            <div class="card-header d-flex align-items-center">
                <h3 class="card-title"><?= $esc($mosGovT($group['label'])) ?></h3>
                <div class="ms-auto">
                    <a class="btn btn-sm btn-outline-primary"
                       href="<?= $esc($mosGovRootPath . '/' . $group['slug'] . '/new?' . $group['field'] . '=' . (int) $row['id']) ?>">
                        新建<?= $esc($mosGovEntityLabel((string) ($group['entity'] ?? ''))) ?>
                    </a>
                </div>
            </div>
            <div class="card-body">
                <?php if ($group['rows'] === []): ?>
                    <p class="text-secondary mb-0">暂无记录。</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-vcenter card-table">
                            <thead>
                                <tr><th class="w-1">ID</th><th>摘要</th><th class="w-1"></th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($group['rows'] as $childRow): ?>
                                    <?php $childDec = $group['decorations'][(int) $childRow['id']] ?? ['ref' => [], 'person' => []]; ?>
                                    <tr>
                                        <td class="text-secondary"><?= (int) $childRow['id'] ?></td>
                                        <td><?= $mosGovRelatedCell($group['fields'], $childRow, $childDec) ?></td>
                                        <td>
                                            <a class="btn btn-sm btn-outline-secondary"
                                               href="<?= $esc($mosGovRootPath . '/' . $group['slug'] . '/' . (int) $childRow['id']) ?>">查看</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

</div>

<?php require __DIR__ . '/../../../../Include/Footer.php'; ?>
