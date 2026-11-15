<?php

/**
 * Phase 3 isolated lab bootstrap.
 *
 * Provides the MINIMUM ChurchCRM/Propel surface needed to load the real MOS-GOV
 * plugin classes against a REAL MariaDB 11.8.9 empty database. No production
 * code, no ChurchCRM UI, no existing local datadir and no real church data are
 * touched. Stubs are deliberately tiny and are listed in the delivery report
 * so results are never mistaken for a full ChurchCRM end-to-end run.
 */

namespace Propel\Runtime\Connection {
    interface ConnectionInterface {}
    interface StatementInterface {}
}

namespace Propel\Runtime {
    class Propel
    {
        private static mixed $connection = null;

        public static function setConnection(mixed $c): void
        {
            self::$connection = $c;
        }

        public static function getConnection(): mixed
        {
            return self::$connection;
        }
    }
}

namespace ChurchCRM\Utils {
    class LabLogger
    {
        public function info(string|\Stringable $m, array $c = []): void {}
        public function debug(string|\Stringable $m, array $c = []): void {}
        public function warning(string|\Stringable $m, array $c = []): void {}
        public function error(string|\Stringable $m, array $c = []): void {}
    }

    class LoggerUtils
    {
        public static function getAppLogger(): LabLogger { return new LabLogger(); }
        public static function getAuthLogger(): LabLogger { return new LabLogger(); }
    }
}

namespace ChurchCRM\dto {
    class SystemURLs
    {
        public static function getRootPath(): string { return ''; }
    }
}

namespace ChurchCRM\model\ChurchCRM {
    class User
    {
        public function __construct(
            private readonly string $name = 'lab-admin',
            private readonly bool $admin = true,
            private readonly int $personId = 1,
        ) {}
        public function getUserName(): string { return $this->name; }
        public function isAdmin(): bool { return $this->admin; }
        public function getPersonId(): int { return $this->personId; }
        public function getId(): int { return $this->personId; }
    }
}

namespace MosGov\Lab {
    use PDO;
    use PDOStatement;
    use Propel\Runtime\Connection\ConnectionInterface;
    use Propel\Runtime\Connection\StatementInterface;

    final class LabStatement implements StatementInterface
    {
        public function __construct(private readonly PDOStatement $inner) {}

        public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
        {
            if ($value === null) {
                $type = PDO::PARAM_NULL;
            } elseif (is_int($value) && $type === PDO::PARAM_STR) {
                $type = PDO::PARAM_INT;
            }

            return $this->inner->bindValue($param, $value, $type);
        }

        public function execute(?array $params = null): bool
        {
            return $this->inner->execute($params);
        }

        public function fetchAll(int $mode = PDO::FETCH_BOTH, mixed ...$args): array
        {
            return $this->inner->fetchAll($mode, ...$args);
        }

        public function fetch(int $mode = PDO::FETCH_BOTH, ...$args): mixed
        {
            return $this->inner->fetch($mode, ...$args);
        }

        public function fetchColumn(int $column = 0): mixed
        {
            return $this->inner->fetchColumn($column);
        }

        public function rowCount(): int
        {
            return $this->inner->rowCount();
        }

        public function errorCode(): ?string { return $this->inner->errorCode(); }
        public function errorInfo(): array { return $this->inner->errorInfo(); }
    }

    final class LabConnection implements ConnectionInterface
    {
        public function __construct(public readonly PDO $pdo) {}

        public static function connect(string $user, string $password, string $database): self
        {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                getenv('MOSGOV_LAB_HOST') ?: '127.0.0.1',
                getenv('MOSGOV_LAB_PORT') ?: '3406',
                $database
            );
            $pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            return new self($pdo);
        }

        public function prepare(string $sql, array $options = []): LabStatement
        {
            return new LabStatement($this->pdo->prepare($sql, $options));
        }

        public function query(string $sql, ...$args): LabStatement|false
        {
            $stmt = $this->pdo->query($sql, ...$args);

            return $stmt === false ? false : new LabStatement($stmt);
        }

        public function exec(string $sql): int|false { return $this->pdo->exec($sql); }
        public function lastInsertId(?string $name = null): string|false { return $this->pdo->lastInsertId($name); }
        public function beginTransaction(): bool { return $this->pdo->beginTransaction(); }
        public function commit(): bool { return $this->pdo->commit(); }
        public function rollBack(): bool { return $this->pdo->rollBack(); }
    }

    final class Assert
    {
        public static int $pass = 0;
        public static int $fail = 0;
        /** @var list<string> */
        public static array $failures = [];

        public static function check(string $label, bool $ok, string $detail = ''): void
        {
            $line = ($ok ? 'PASS' : 'FAIL') . ' | ' . $label;
            if ($detail !== '') {
                $line .= ' | ' . $detail;
            }
            echo $line . PHP_EOL;
            if ($ok) {
                self::$pass++;
            } else {
                self::$fail++;
                self::$failures[] = $label;
            }
        }

        public static function summary(string $section): void
        {
            echo sprintf('--- %s: %d PASS / %d FAIL ---', $section, self::$pass, self::$fail) . PHP_EOL;
        }
    }
}

namespace {
    $root = dirname(__DIR__, 2);
    define('MOSGOV_LAB_ROOT', $root);
    define('MOSGOV_LAB_EVIDENCE', getenv('MOSGOV_LAB_EVIDENCE') ?: sys_get_temp_dir());

    spl_autoload_register(static function (string $class) use ($root): void {
        $prefix = 'ChurchCRM\\Plugins\\MosGov\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $path = $root . '/src/' . $relative . '.php';
        if (is_file($path)) {
            require $path;
        }
    });

    date_default_timezone_set('Asia/Shanghai');
}
