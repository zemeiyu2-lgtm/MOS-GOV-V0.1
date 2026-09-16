<?php

/**
 * Governance identity create form (V0.2).
 *
 * Expected variables: $isEdit, $row, $personCandidates, $errors, $csrfField, $esc
 */

use ChurchCRM\dto\SystemURLs;

$mosGovRootPath = SystemURLs::getRootPath() . '/plugins/mos-gov';

$sPageTitle = 'New governance identity';
$sPageSubtitle = 'Link a ChurchCRM person to the governance layer';
$aBreadcrumbs = [
    ['label' => 'Plugins', 'url' => SystemURLs::getRootPath() . '/plugins/management'],
    ['label' => 'MOS-GOV', 'url' => $mosGovRootPath],
    ['label' => 'Identities', 'url' => $mosGovRootPath . '/identity'],
    ['label' => 'New', 'active' => true],
];

require __DIR__ . '/../../../../Include/Header.php';

$activeSlug = 'identity';
require __DIR__ . '/_tabs.php';

$errors = $errors ?? [];
$row = $row ?? [];
?>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger">Please correct the highlighted fields.</div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="post" action="<?= $esc($mosGovRootPath . '/identity') ?>">
            <?= $csrfField ?>
            <div class="mb-3">
                <label class="form-label" for="person_id">Person (ChurchCRM)</label>
                <select class="form-select" id="person_id" name="person_id" required>
                    <option value="">— select person —</option>
                    <?php foreach ($personCandidates as $pid => $label): ?>
                        <option value="<?= (int) $pid ?>" <?= (string) ($row['person_id'] ?? '') === (string) $pid ? 'selected' : '' ?>>
                            <?= $esc($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errors['person_id'])): ?><div class="text-danger small"><?= $esc($errors['person_id']) ?></div><?php endif; ?>
            </div>
            <div class="mb-3">
                <label class="form-label" for="identity_status">Identity status</label>
                <select class="form-select" id="identity_status" name="identity_status">
                    <?php foreach (['active', 'inactive', 'suspended', 'archived'] as $status): ?>
                        <option value="<?= $esc($status) ?>" <?= ($row['identity_status'] ?? 'active') === $status ? 'selected' : '' ?>>
                            <?= $esc($status) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label" for="member_since">Member since</label>
                <input class="form-control" type="date" id="member_since" name="member_since"
                       value="<?= $esc($row['member_since'] ?? '') ?>">
            </div>
            <div class="mb-3">
                <label class="form-label" for="display_name_override">Display name override</label>
                <input class="form-control" type="text" id="display_name_override" name="display_name_override"
                       maxlength="190" value="<?= $esc($row['display_name_override'] ?? '') ?>">
                <div class="form-hint">Optional governance display name. ChurchCRM person facts are never copied.</div>
            </div>
            <button class="btn btn-primary" type="submit">Create identity</button>
            <a class="btn btn-outline-secondary" href="<?= $esc($mosGovRootPath . '/identity') ?>">Cancel</a>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../../../../Include/Footer.php'; ?>
