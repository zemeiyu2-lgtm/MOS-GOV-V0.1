<?php

/**
 * MOS-GOV shared entity create/edit form view.
 *
 * Expected variables (provided by the route):
 * - $cfg               entity config from GovRepository::ENTITIES
 * - $slug              URL slug (e.g. structures)
 * - $isEdit            true when editing an existing record
 * - $actionUrl         form action URL (POST target)
 * - $data              current form values (field => value)
 * - $errors            validation errors (field => message)
 * - $refOptions        field => array(id => display label) for ref fields
 * - $personCandidates  person_id => display label (ChurchCRM, read-only)
 * - $personLabels      field => resolved label for the current value
 * - $csrfField         CSRF hidden input markup
 * - $canWrite          whether the current user may save
 * - $error             optional top-level error message
 * - $esc               HTML-escaping closure
 */

use ChurchCRM\dto\SystemURLs;

$mosGovRootPath = SystemURLs::getRootPath() . '/plugins/mos-gov';
$listUrl = $mosGovRootPath . '/' . $slug;

$sPageTitle = ($isEdit ? 'Edit ' : 'New ') . $cfg['label'];
$sPageSubtitle = 'MOS-GOV governance data';
$aBreadcrumbs = [
    ['label' => 'Plugins', 'url' => SystemURLs::getRootPath() . '/plugins/management'],
    ['label' => 'MOS-GOV', 'url' => $mosGovRootPath],
    ['label' => $cfg['labelPlural'], 'url' => $listUrl],
    ['label' => $isEdit ? 'Edit #' . (int) ($data['id'] ?? 0) : 'New', 'active' => true],
];

require __DIR__ . '/../../../../Include/Header.php';

$activeSlug = $slug;
require __DIR__ . '/_tabs.php';

/** Normalise a stored datetime for an <input type="datetime-local">. */
$mosGovDateTimeInput = static function ($value): string {
    $value = (string) $value;
    if ($value === '') {
        return '';
    }

    return strlen($value) >= 16 ? str_replace(' ', 'T', substr($value, 0, 16)) : $value;
};
?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger" role="alert"><?= $esc($error) ?></div>
<?php endif; ?>

<?php if ($errors !== []): ?>
    <div class="alert alert-warning" role="alert">
        Please correct the highlighted fields.
    </div>
<?php endif; ?>

<?php if (empty($canWrite)): ?>
    <div class="alert alert-info" role="alert">
        You do not have permission to modify MOS-GOV governance data.
    </div>
<?php else: ?>
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><?= $esc($cfg['label']) ?> details</h3>
        </div>
        <div class="card-body">
            <form method="post" action="<?= $esc($actionUrl) ?>" novalidate>
                <?= $csrfField ?>

                <?php foreach ($cfg['fields'] as $field => $spec): ?>
                    <?php
                    // Prefill from the submitted/stored value; fall back to the
                    // registry default only when the field was not part of the
                    // submission at all (e.g. a brand-new record).
                    $value = $data[$field] ?? ($spec['default'] ?? '');
                    $fieldError = $errors[$field] ?? null;
                    $fieldId = 'gov-field-' . $field;
                    ?>
                    <div class="mb-3">
                        <label class="form-label" for="<?= $esc($fieldId) ?>">
                            <?= $esc($spec['label']) ?><?= !empty($spec['required']) ? ' <span class="text-danger">*</span>' : '' ?>
                        </label>

                        <?php if ($spec['type'] === 'text'): ?>
                            <input type="text" class="form-control" id="<?= $esc($fieldId) ?>"
                                   name="<?= $esc($field) ?>" maxlength="<?= (int) ($spec['max'] ?? 190) ?>"
                                   value="<?= $esc($value) ?>">

                        <?php elseif ($spec['type'] === 'textlong'): ?>
                            <textarea class="form-control" id="<?= $esc($fieldId) ?>"
                                      name="<?= $esc($field) ?>" rows="4"><?= $esc($value) ?></textarea>

                        <?php elseif ($spec['type'] === 'int'): ?>
                            <input type="number" step="1" min="<?= (int) ($spec['min'] ?? 1) ?>"
                                   class="form-control" id="<?= $esc($fieldId) ?>"
                                   name="<?= $esc($field) ?>" value="<?= $esc($value) ?>">

                        <?php elseif ($spec['type'] === 'date'): ?>
                            <input type="date" class="form-control" id="<?= $esc($fieldId) ?>"
                                   name="<?= $esc($field) ?>" value="<?= $esc($value) ?>">

                        <?php elseif ($spec['type'] === 'datetime'): ?>
                            <input type="datetime-local" class="form-control" id="<?= $esc($fieldId) ?>"
                                   name="<?= $esc($field) ?>" value="<?= $esc($mosGovDateTimeInput($value)) ?>">

                        <?php elseif ($spec['type'] === 'status' || $spec['type'] === 'select'): ?>
                            <select class="form-select" id="<?= $esc($fieldId) ?>" name="<?= $esc($field) ?>">
                                <?php if (empty($spec['required'])): ?>
                                    <option value="">-- none --</option>
                                <?php endif; ?>
                                <?php foreach ($spec['options'] ?? [] as $option): ?>
                                    <option value="<?= $esc($option) ?>"
                                        <?= (string) $value === (string) $option ? 'selected' : '' ?>>
                                        <?= $esc($option) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                        <?php elseif ($spec['type'] === 'ref'): ?>
                            <select class="form-select" id="<?= $esc($fieldId) ?>" name="<?= $esc($field) ?>">
                                <option value="">-- none --</option>
                                <?php foreach ($refOptions[$field] ?? [] as $optId => $optLabel): ?>
                                    <option value="<?= (int) $optId ?>"
                                        <?= (string) $value === (string) $optId ? 'selected' : '' ?>>
                                        <?= $esc($optLabel) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (($refOptions[$field] ?? []) === [] && !empty($spec['required'])): ?>
                                <div class="form-hint text-warning">
                                    No <?= $esc(strtolower(\ChurchCRM\Plugins\MosGov\Data\GovRepository::labelFor($spec['ref']))) ?>
                                    exists yet — create one first.
                                </div>
                            <?php endif; ?>

                        <?php elseif ($spec['type'] === 'person'): ?>
                            <input type="text" inputmode="numeric" pattern="[0-9]*" class="form-control"
                                   id="<?= $esc($fieldId) ?>" name="<?= $esc($field) ?>"
                                   list="gov-person-candidates" value="<?= $esc($value) ?>"
                                   autocomplete="off">
                            <?php if (!empty($personLabels[$field])): ?>
                                <div class="form-hint">Currently: <?= $esc($personLabels[$field]) ?></div>
                            <?php else: ?>
                                <div class="form-hint">ChurchCRM person ID (read-only reference).</div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if ($fieldError !== null): ?>
                            <div class="text-danger small mt-1"><?= $esc($fieldError) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <datalist id="gov-person-candidates">
                    <?php foreach ($personCandidates as $candidateId => $candidateLabel): ?>
                        <option value="<?= (int) $candidateId ?>"><?= $esc($candidateLabel) ?></option>
                    <?php endforeach; ?>
                </datalist>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Save</button>
                    <?php if ($isEdit): ?>
                        <a class="btn btn-outline-secondary"
                           href="<?= $esc($listUrl . '/' . (int) ($data['id'] ?? 0)) ?>">Cancel</a>
                    <?php else: ?>
                        <a class="btn btn-outline-secondary" href="<?= $esc($listUrl) ?>">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <?php if ($isEdit): ?>
        <p class="mt-3">
            <a href="<?= $esc($listUrl . '/' . (int) ($data['id'] ?? 0)) ?>">&larr; Back to this <?= $esc(strtolower($cfg['label'])) ?></a>
        </p>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../../../../Include/Footer.php'; ?>
