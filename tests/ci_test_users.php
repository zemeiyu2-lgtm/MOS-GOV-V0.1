<?php

/**
 * Disposable CI-only ChurchCRM accounts for the MOS-GOV regression runner.
 *
 * This script deliberately refuses to run unless MOSGOV_CI_TEST_MODE=1 and
 * the active database is named "churchcrm". It creates only two clearly
 * marked users, and cleanup removes only those exact test accounts.
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

        // Fresh ChurchCRM installs have no admin API key. Add one only when
        // absent; cleanup recognizes our unique prefix and restores NULL.
        if (empty($admin['usr_apiKey'])) {
            $adminKey = 'mosgov-ci-admin-' . bin2hex(random_bytes(24));
            $stmt = $conn->prepare('UPDATE user_usr SET usr_apiKey = :key WHERE usr_per_ID = :id AND (usr_apiKey IS NULL OR usr_apiKey = "")');
            $stmt->bindValue(':key', $adminKey, \PDO::PARAM_STR);
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
            $personId = (int) $conn->lastInsertId();
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
        $conn->beginTransaction();

        foreach ($accounts as $account) {
            $stmt = $conn->prepare(
                'SELECT u.usr_per_ID, p.per_Email, p.per_FirstName, p.per_LastName
                 FROM user_usr u
                 INNER JOIN person_per p ON p.per_ID = u.usr_per_ID
                 WHERE u.usr_UserName = :username AND u.usr_apiKey LIKE "mosgov-ci-user-%"
                 LIMIT 1'
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
            $stmt = $conn->prepare('DELETE FROM user_usr WHERE usr_per_ID = :id AND usr_UserName = :username AND usr_apiKey LIKE "mosgov-ci-user-%"');
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

        $conn->exec(
            'UPDATE user_usr SET usr_apiKey = NULL
             WHERE usr_Admin = 1 AND usr_apiKey LIKE "mosgov-ci-admin-%"'
        );

        $conn->commit();
        echo "[PASS] Removed only marked MOS-GOV CI accounts and restored any temporary admin API key.\n";
        exit(0);
    }

    throw new \InvalidArgumentException('Usage: ci_test_users.php prepare|cleanup');
} catch (\Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    $fail('CI test-user helper failed: ' . $e->getMessage());
}
