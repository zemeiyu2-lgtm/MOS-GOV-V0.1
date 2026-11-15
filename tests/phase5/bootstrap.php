<?php
/**
 * MOS-GOV Phase 5 — real-execution harness bootstrap.
 *
 * HONESTY CONTRACT
 * ----------------
 * This file loads the REAL ChurchCRM 7.8.0 GroupService.php (patched copy) and
 * lets PHP execute its three target methods. Every SQL statement produced by
 * those methods is executed against the REAL isolated MariaDB 11.8.9 instance
 * on 127.0.0.1:3407 through a real mysqli connection ($cnInfoCentral).
 *
 * The only substitutes are ChurchCRM's *framework* dependencies that are not
 * part of the code under test:
 *   - AuthService::requireUserGroupMembership()  -> allow/deny switch
 *   - Propel ORM query classes                    -> empty collections
 *   - LoggerUtils / InputUtils                    -> no-op helpers
 * These are explicitly NOT counted as "full-stack integration". See the
 * Phase 5 patch verification report for the exact boundary of this evidence.
 *
 * No credentials live in this file; connect_db() reads them from the protected
 * lab env file outside Git.
 */

declare(strict_types=1);

namespace ChurchCRM\Utils {
    /** Records every SQL and executes it for real against $cnInfoCentral. */
    final class FunctionsUtils
    {
        /** @var string[] */
        public static array $sqlLog = [];

        public static function runQuery(string $sSQL, bool $bStopOnError = true)
        {
            global $cnInfoCentral;
            self::$sqlLog[] = $sSQL;
            // Mirror upstream behaviour (it relaxes ONLY_FULL_GROUP_BY before each query).
            mysqli_query($cnInfoCentral, "SET sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))");
            $result = mysqli_query($cnInfoCentral, $sSQL);
            if ($result === false) {
                throw new \RuntimeException(
                    'SQL_ERROR: ' . mysqli_error($cnInfoCentral) . ' | SQL=' . preg_replace('/\s+/', ' ', $sSQL)
                );
            }
            return $result;
        }
    }

    final class LoggerUtils
    {
        public static function getAppLogger(): object
        {
            return new class {
                public function error($m): void {}
                public function warning($m): void {}
                public function info($m): void {}
            };
        }
    }

    final class InputUtils
    {
        public static function sanitizeText($s): string
        {
            return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        }
        public static function legacyFilterInput($s, $type)
        {
            return $type === 'int' ? (int)$s : (string)$s;
        }
    }
}

namespace ChurchCRM\Service {
    /** Auth boundary switch — the guard under test runs AFTER this call. */
    final class AuthService
    {
        public static bool $deny = false;
        public static int $calls = 0;
        public static function requireUserGroupMembership($groupName): void
        {
            self::$calls++;
            if (self::$deny) {
                throw new \Exception('permission denied (lab switch)');
            }
        }
    }
}

namespace ChurchCRM\model\ChurchCRM\Map {
    class GroupTableMap { public const DATABASE_NAME = 'ChurchCRM'; }
}

namespace ChurchCRM\model\ChurchCRM {
    /** Minimal Propel stand-ins: enableGroupSpecificProperties() calls getGroupMembers(). */
    class Person2group2roleP2g2rQuery
    {
        public static function create(): self { return new self(); }
        public function filterByGroupId($v): self { return $this; }
        public function filterByPersonId($v): self { return $this; }
        public function joinWithPerson(): self { return $this; }
        public function find(): array { return []; }
    }
    class GroupQuery
    {
        public static function create(): self { return new self(); }
        public function findOneById($v): ?object { return null; }
        public function filterById($v): self { return $this; }
        public function lockForUpdate(): self { return $this; }
        // Official 7.8.0 deleteGroupRole() locks the group row inside a write
        // transaction; without a Propel runtime the row is simply absent.
        public function findOne($con = null): ?object { return null; }
    }
    class ListOptionQuery
    {
        public static function create(): self { return new self(); }
        public function filterById($v): self { return $this; }
        public function filterByOptionId($v): self { return $this; }
        public function filterByOptionName($v): self { return $this; }
        public function find(): array { return []; }
        public function findOne(): ?object { return null; }
    }
    class ListOption { public function save(): void {} }
    class Person {}
    class Person2group2roleP2g2r {}
}

namespace Propel\Runtime {
    /** Transaction-aware proxy so official 7.8.0 code (which calls
     *  getWriteConnection()->beginTransaction()) runs against real mysqli. */
    class P5WriteConnection
    {
        private \mysqli $c;
        public function __construct(\mysqli $c) { $this->c = $c; }
        public function beginTransaction(): void { $this->c->begin_transaction(); }
        public function commit(): void { $this->c->commit(); }
        public function rollBack(): void { $this->c->rollback(); }
        public function prepare(string $sql) { return $this->c->prepare($sql); }
        public function query(string $sql) { return $this->c->query($sql); }
    }

    class Propel
    {
        public static function getConnection()
        {
            global $cnInfoCentral;
            return $cnInfoCentral;
        }
        public static function getWriteConnection($dbName = null): P5WriteConnection
        {
            global $cnInfoCentral;
            return new P5WriteConnection($cnInfoCentral);
        }
    }
}

namespace {
    /** Real mysqli connection to the isolated Phase 5 MariaDB instance. */
    function p5_connect(string $host, int $port, string $user, string $pass, string $db): \mysqli
    {
        $cn = @new \mysqli($host, $user, $pass, $db, $port);
        if ($cn->connect_errno) {
            throw new \RuntimeException('DB_CONNECT_FAILED: ' . $cn->connect_error);
        }
        $cn->set_charset('utf8mb4');
        $GLOBALS['cnInfoCentral'] = $cn;
        return $cn;
    }

    function p5_reset_sql_log(): void
    {
        \ChurchCRM\Utils\FunctionsUtils::$sqlLog = [];
        \ChurchCRM\Service\AuthService::$calls = 0;
    }

    /** @return string[] */
    function p5_sql_log(): array
    {
        return \ChurchCRM\Utils\FunctionsUtils::$sqlLog;
    }

    /** Load a GroupService.php copy into an isolated class alias is impossible,
     *  so each verification run is a separate PHP process (see run-all.sh). */
    function p5_load_group_service(string $file): \ChurchCRM\Service\GroupService
    {
        if (!is_file($file)) {
            throw new \RuntimeException('GroupService file missing: ' . $file);
        }
        require_once $file;
        return new \ChurchCRM\Service\GroupService();
    }

    /** Simple assertion recorder shared by all Phase 5 suites. */
    final class P5Assert
    {
        public int $pass = 0;
        public int $fail = 0;
        /** @var array<int,array{0:string,1:string,2:string}> */
        public array $rows = [];

        public function ok(string $id, bool $cond, string $detail = ''): void
        {
            if ($cond) { $this->pass++; $this->rows[] = ['PASS', $id, $detail]; }
            else       { $this->fail++; $this->rows[] = ['FAIL', $id, $detail]; }
            printf("%s | %s | %s\n", $cond ? 'PASS' : 'FAIL', $id, $detail);
        }

        public function summary(string $suite): int
        {
            printf("--- %s: %d PASS / %d FAIL ---\n", $suite, $this->pass, $this->fail);
            return $this->fail === 0 ? 0 : 1;
        }
    }
}
