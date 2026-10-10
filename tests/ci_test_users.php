<?php

/**
 * Disposable CI-only ChurchCRM accounts for the MOS-GOV regression runner.
 *
 * This script refuses to run unless MOSGOV_CI_TEST_MODE=1 and the active
 * database is named churchcrm. It creates only two marked test users and
 * cleanup removes only those exact accounts.
 *
 * Usage:
 *   MOSGOV_CI_TEST_MODE=1 php tests/ci_test_users.php prepare
 *   MOSGOV_CI_TEST_MODE=1 php tests/ci_test_users.php cleanup
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}
if (getenv('MOSGOV_CI_TEST_MODE') !== '1') {
    fwrite(STDERR, "BLOCKED: set MOSGOV_CI_TEST_MODE=1 to run this CI-only helper.\n");
    exit(2);
}

require __DIR__ . '/../../../../Include/LoadConfigs.php';

$conn = \Propel\Runtime\Propel::getConnection();
$dbName = (string) $conn->query('SELECT DATABASE()')->fetchColumn();
if ($dbName !== 'churchcrm') {
    fwrite(STDERR, "BLOCKED: expected database churchcrm, got " . ($dbName ?: '(none)') . ".\n");
    exit(2);
}

$mode = $argv[1] ?? '';
$statePath = getenv('MOSGOV_CI_STATE_FILE')
    ?: sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mosgov-ci-test-users-state.json';

$accounts = [
    [
        'username' => 'mosgov_ci_readonly',
        'first' => 'MOS-GOV CI',
        'last' => 'ReadOnly',
        'email' => 'mosgov-ci-readonly@example.invalid',
        'edit_records' => 0,
    ],
    [
        'username' => 'mosgov_ci_editor',
        'first' => 'MOS-GOV CI',
        'last' => 'Editor',
        'email' => 'mosgov-ci-editor@example.invalid',
        'edit_records' => 1,
    ],
];

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

try {
    if ($mode === 'prepare') {
        if (is_file($statePath)) {
            throw new \RuntimeException('CI state file already exists; run cleanup before preparing another test run.');
        }

        $conn->beginTransaction();

        // Refuse collisions rather than ever repurposing a pre-existing account.
        $findUser = $conn->prepare('SELECT usr_per_ID FROM user_usr WHERE usr_UserName = :username LIMIT 1');
        foreach ($accounts as $account) {
            $findUser->bindValue(':username', $account['username'], \PDO::PARAM_STR);
            $findUser->execute();
            if ($findUser->fetchColumn() !== false) {
                throw new \RuntimeException('Refusing to reuse existing user ' . $account['username'] . '.');
            }
        }

        $admin = $conn->query(
            'SELECT usr_per_ID, usr_apiKey FROM user_usr WHERE usr_Admin = 1 ORDER BY usr_per_ID ASC LIMIT 1'
        )->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($admin)) {
            throw new \RuntimeException('No ChurchCRM administrator exists for the HTTP regression tests.');
        }

        // Store exact original/temporary values before modifying the database.
        // If a later insert fails, the transaction rolls back and cleanup can
        // safely recognize that the original key is already in place.
        $originalAdminKey = !empty($admin['usr_apiKey']) ? (string) $admin['usr_apiKey'] : null;
        $temporaryAdminKey = $originalAdminKey === null
            ? 'mosgov-ci-admin-' . bin2hex(random_bytes(24))
            : null;
        $state = [
            'admin_person_id' => (int) $admin['usr_per_ID'],
            'admin_original_api_key' => $originalAdminKey,
            'admin_temporary_api_key' => $temporaryAdminKey,
        ];
        if (file_put_contents($statePath, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            throw new \RuntimeException('Could not write CI state file.');
        }

        if ($temporaryAdminKey !== null) {
            $stmt = $conn->prepare("UPDATE user_usr SET usr_apiKey = :key WHERE usr_per_ID = :id AND (usr_apiKey IS NULL OR usr_apiKey = '')");
            $stmt->bindValue(':key', $temporaryAdminKey, \PDO::PARAM_STR);
            $stmt->bindValue(':id', (int) $admin['usr_per_ID'], \PDO::PARAM_INT);
            $stmt->execute();
            if ($stmt->rowCount() !== 1) {
                throw new \RuntimeException('Could not provision a temporary administrator API key.');
            }
        }

        foreach ($accounts as $account) {
            $stmt = $conn->prepare(
                'INSERT INTO person_per
                    (per_FirstName, per_LastName, per_Email, per_DateEntered, per_EnteredBy, per_EditedBy, per_fam_ID)
                 VALUES (:first, :last, :email, NOW(), :entered_by, :edited_by, 0)'
            );
            $stmt->bindValue(':first', $account['first'], \PDO::PARAM_STR);
            $stmt->bindValue(':last', $account['last'], \PDO::PARAM_STR);
            $stmt->bindValue(':email', $account['email'], \PDO::PARAM_STR);
            $stmt->bindValue(':entered_by', (int) $admin['usr_per_ID'], \PDO::PARAM_INT);
            $stmt->bindValue(':edited_by', (int) $admin['usr_per_ID'], \PDO::PARAM_INT);
            $stmt->execute();
            $personId = (int) $conn->query('SELECT LAST_INSERT_ID()')->fetchColumn();
            if ($personId < 1) {
                throw new \RuntimeException('Could not create CI person for ' . $account['username'] . '.');
            }

            $apiKey = 'mosgov-ci-user-' . bin2hex(random_bytes(24));
            $stmt = $conn->prepare(
                'INSERT INTO user_usr
                    (usr_per_ID, usr_Password, usr_NeedPasswordChange, usr_AddRecords,
                     usr_EditRecords, usr_DeleteRecords, usr_MenuOptions, usr_ManageGroups,
                     usr_Finance, usr_Notes, usr_Admin, usr_UserName, usr_apiKey, usr_EditSelf)
                 VALUES
                    (:person_id, :password, 0, 0, :edit_records, 0, 0, 0, 0, 0, 0,
                     :username, :api_key, 0)'
            );
            $stmt->bindValue(':person_id', $personId, \PDO::PARAM_INT);
            $stmt->bindValue(':password', password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), \PDO::PARAM_STR);
            $stmt->bindValue(':edit_records', (int) $account['edit_records'], \PDO::PARAM_INT);
            $stmt->bindValue(':username', $account['username'], \PDO::PARAM_STR);
            $stmt->bindValue(':api_key', $apiKey, \PDO::PARAM_STR);
            $stmt->execute();
        }

        $conn->commit();
        echo "[PASS] Created two isolated MOS-GOV CI users and ensured an admin API key exists.\n";
        exit(0);
    }

    if ($mode === 'cleanup') {
        if (!is_file($statePath)) {
            throw new \RuntimeException('CI state file is missing; refusing to guess which administrator key to restore.');
        }
        $state = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($state) || empty($state['admin_person_id'])
            || !array_key_exists('admin_original_api_key', $state)
            || !array_key_exists('admin_temporary_api_key', $state)) {
            throw new \RuntimeException('CI state file is invalid.');
        }

        $conn->beginTransaction();

        foreach ($accounts as $account) {
            $stmt = $conn->prepare(
                "SELECT u.usr_per_ID, p.per_Email, p.per_FirstName, p.per_LastName
                 FROM user_usr u
                 INNER JOIN person_per p ON p.per_ID = u.usr_per_ID
                 WHERE u.usr_UserName = :username AND u.usr_apiKey LIKE 'mosgov-ci-user-%'
                 LIMIT 1"
            );
            $stmt->bindValue(':username', $account['username'], \PDO::PARAM_STR);
            $stmt->execute();
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                continue;
            }
            if ($row['per_Email'] !== $account['email']
                || $row['per_FirstName'] !== $account['first']
                || $row['per_LastName'] !== $account['last']) {
                throw new \RuntimeException('CI account marker mismatch; refusing to delete ' . $account['username'] . '.');
            }

            $personId = (int) $row['usr_per_ID'];
            $identityCheck = $conn->prepare('SELECT COUNT(*) FROM gov_identity WHERE person_id = :pid');
            $identityCheck->bindValue(':pid', $personId, \PDO::PARAM_INT);
            $identityCheck->execute();
            if ((int) $identityCheck->fetchColumn() > 0) {
                throw new \RuntimeException('A governance identity remains for ' . $account['username'] . '; refusing to delete its ChurchCRM person.');
            }
            $stmt = $conn->prepare("DELETE FROM user_usr WHERE usr_per_ID = :id AND usr_UserName = :username AND usr_apiKey LIKE 'mosgov-ci-user-%'");
            $stmt->bindValue(':id', $personId, \PDO::PARAM_INT);
            $stmt->bindValue(':username', $account['username'], \PDO::PARAM_STR);
            $stmt->execute();

            $stmt = $conn->prepare(
                'DELETE FROM person_per
                 WHERE per_ID = :id AND per_Email = :email
                   AND per_FirstName = :first AND per_LastName = :last
                   AND NOT EXISTS (SELECT 1 FROM user_usr WHERE usr_per_ID = :id2)'
            );
            $stmt->bindValue(':id', $personId, \PDO::PARAM_INT);
            $stmt->bindValue(':id2', $personId, \PDO::PARAM_INT);
            $stmt->bindValue(':email', $account['email'], \PDO::PARAM_STR);
            $stmt->bindValue(':first', $account['first'], \PDO::PARAM_STR);
            $stmt->bindValue(':last', $account['last'], \PDO::PARAM_STR);
            $stmt->execute();
            if ($stmt->rowCount() !== 1) {
                throw new \RuntimeException('Could not safely remove CI person ' . $account['username'] . '.');
            }
        }

        $temporaryAdminKey = $state['admin_temporary_api_key'];
        if ($temporaryAdminKey !== null) {
            $stmt = $conn->prepare('SELECT usr_apiKey FROM user_usr WHERE usr_per_ID = :id');
            $stmt->bindValue(':id', (int) $state['admin_person_id'], \PDO::PARAM_INT);
            $stmt->execute();
            $currentAdminKey = $stmt->fetchColumn();
            if ($currentAdminKey === $temporaryAdminKey) {
                $stmt = $conn->prepare('UPDATE user_usr SET usr_apiKey = :original WHERE usr_per_ID = :id AND usr_apiKey = :temporary');
                $stmt->bindValue(':original', $state['admin_original_api_key'], $state['admin_original_api_key'] === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
                $stmt->bindValue(':id', (int) $state['admin_person_id'], \PDO::PARAM_INT);
                $stmt->bindValue(':temporary', $temporaryAdminKey, \PDO::PARAM_STR);
                $stmt->execute();
                if ($stmt->rowCount() !== 1) {
                    throw new \RuntimeException('Could not restore the original administrator API key.');
                }
            } elseif ($currentAdminKey !== $state['admin_original_api_key']) {
                throw new \RuntimeException('Administrator API key changed unexpectedly; refusing to overwrite it.');
            }
        }

        $conn->commit();
        if (is_file($statePath) && !unlink($statePath)) {
            echo "[WARN] Cleanup succeeded, but could not remove the CI state file.\n";
        }
        echo "[PASS] Removed only marked MOS-GOV CI accounts and restored the exact original admin API key.\n";
        exit(0);
    }

    throw new \InvalidArgumentException('Usage: ci_test_users.php prepare|cleanup');
} catch (\Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    $fail('CI test-user helper failed: ' . $e->getMessage());
}
