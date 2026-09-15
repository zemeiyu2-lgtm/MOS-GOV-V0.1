<?php

/**
 * MOS-GOV shared entity create/edit form view.
 *
 * Expected variables (provided by the route):
 * - $cfg          entity config from GovRepository::ENTITIES
 * - $slug         URL slug (structures|bodies|roles|appointments)
 * - $isEdit       true when editing an existing record
 * - $actionUrl    form action URL (POST target)
 * - $data         current form values (field => value)
 * - $errors       validation errors (field => message)
 * - $refOptions   field => array(id => display label) for ref fields
 * - $error        optional top-level error message
 * - $esc          HTML-escaping closure
 */
require_once __DIR__ . '/../../../../Include/Header.php';
$mosGovBase = \ChurchCRM\dto\SystemURLs::getRootPath() . '/plugins/mos-gov';
?>
<div class="container-xl">
    <div class="page-header d-print-none">
        <div class="row align-items-center">
            <div class="col">
                <h2 class="page-title">
                    <?= $esc(($isEdit ? 'Edit ' : 'New ') . $cfg['label']) ?>
                </h2>
                <div class="text-secondary">MOS-GOV governance data</div>
            </div>
        </div>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger" role="alert"><?= $esc($error) ?></div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="alert alert-warning" role="alert">
            Please correct the highlighted fields.
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <form method="post" action="<?= $esc($actionUrl) ?>">
                <?= $csrfField ?>

                <?php foreach ($cfg['fields'] as $field => $spec): ?>
                    <?php $value = $data[$field] ?? ($spec['type'] === 'status' ? 'active' : ''); ?>
                    <?php $fieldError = $errors[$field] ?? null; ?>
                    <div class="mb-3">
                        <label class="form-label" for="gov-field-<?= $esc($field) ?>">
                            <?= $esc($spec['label']) ?><?= $spec['required'] ? ' *' : '' ?>
                        </label>

                        <?php if ($spec['type'] === 'text'): ?>
                            <input type="text" class="form-control" id="gov-field-<?= $esc($field) ?>"
                                   name="<?= $esc($field) ?>" maxlength="<?= (int) ($spec['max'] ?? 190) ?>"
                                   value="<?= $esc($value) ?>">
                        <?php elseif ($spec['type'] === 'textlong'): ?>
                            <textarea class="form-control" id="gov-field-<?= $esc($field) ?>"
                                      name="<?= $esc($field) ?>" rows="3"><?= $esc($value) ?></textarea>
                        <?php elseif ($spec['type'] === 'int'): ?>
                            <input type="number" min="1" step="1" class="form-control"
                                   id="gov-field-<?= $esc($field) ?>" name="<?= $esc($field) ?>"
                                   value="<?= $esc($value) ?>">
                            <?php if ($field === 'person_id'): ?>
                                <div class="form-hint">ChurchCRM person ID reference (number only).</div>
                            <?php endif; ?>
                        <?php elseif ($spec['type'] === 'date'): ?>
                            <input type="date" class="form-control" id="gov-field-<?= $esc($field) ?>"
                                   name="<?= $esc($field) ?>" value="<?= $esc($value) ?>">
                        <?php elseif ($spec['type'] === 'status'): ?>
                            <select class="form-select" id="gov-field-<?= $esc($field) ?>"
                                    name="<?= $esc($field) ?>">
                                <?php foreach ($statuses as $statusValue): ?>
                                    <option value="<?= $esc($statusValue) ?>"
                                        <?= $value === $statusValue ? 'selected' : '' ?>>
                                        <?= $esc($statusValue) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php elseif ($spec['type'] === 'ref'): ?>
                            <select class="form-select" id="gov-field-<?= $esc($field) ?>"
                                    name="<?= $esc($field) ?>">
                                <option value="">-- none --</option>
                                <?php foreach ($refOptions[$field] ?? [] as $optId => $optLabel): ?>
                                    <option value="<?= (int) $optId ?>"
                                        <?= ((string) $value) === (string) $optId ? 'selected' : '' ?>>
                                        <?= $esc($optLabel) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>

                        <?php if ($fieldError !== null): ?>
                            <div class="text-danger small mt-1"><?= $esc($fieldError) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Save</button>
                    <a class="btn btn-outline-secondary"
                       href="<?= $esc('/plugins/mos-gov/' . $slug) ?>">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../../../Include/Footer.php'; ?>
