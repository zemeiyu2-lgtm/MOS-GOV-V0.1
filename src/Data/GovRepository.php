<?php

namespace ChurchCRM\Plugins\MosGov\Data;

use Propel\Runtime\Propel;

/**
 * Data access layer for the MOS-GOV governance tables (gov_*).
 *
 * Design decisions (Phase 3 audit, 2026-09-15):
 *
 * - Propel generated models are NOT available for plugin-owned tables: the
 *   Propel schema belongs to ChurchCRM core and must not be modified. The
 *   minimal safe data-access path is therefore PDO through the standard
 *   ChurchCRM connection obtained via Propel::getConnection().
 * - Every statement is prepared; table and column names come exclusively
 *   from the whitelisted ENTITIES registry below, never from user input.
 * - Only gov_* tables are touched. ChurchCRM core tables are neither read
 *   nor written here (ChurchCRM references are opaque integer IDs per the
 *   architecture boundary rule / R08).
 * - Routes and views must not embed SQL; they call this repository.
 */
final class GovRepository
{
    /** Whitelisted status values shared by the four core entities. */
    public const STATUSES = ['active', 'inactive', 'archived'];

    /** Entity registry: single source of truth for tables, fields, labels
     * and validation rules. Field types: text, textlong, int, date, status,
     * ref ('ref' names the parent entity). */
    public const ENTITIES = [
        'structure' => [
            'table' => 'gov_structure',
            'label' => 'Structure',
            'listFields' => ['name', 'code', 'status', 'sort_order'],
            'fields' => [
                'name' => ['type' => 'text', 'required' => true, 'max' => 190, 'label' => 'Name'],
                'code' => ['type' => 'text', 'required' => false, 'max' => 80, 'label' => 'Code'],
                'description' => ['type' => 'textlong', 'required' => false, 'label' => 'Description'],
                'status' => ['type' => 'status', 'required' => true, 'label' => 'Status'],
                'parent_id' => ['type' => 'ref', 'ref' => 'structure', 'required' => false, 'label' => 'Parent structure'],
                'sort_order' => ['type' => 'int', 'required' => false, 'min' => 0, 'default' => 0, 'label' => 'Sort order'],
            ],
        ],
        'body' => [
            'table' => 'gov_body',
            'label' => 'Body',
            'listFields' => ['name', 'body_type', 'status'],
            'fields' => [
                'structure_id' => ['type' => 'ref', 'ref' => 'structure', 'required' => true, 'label' => 'Structure'],
                'name' => ['type' => 'text', 'required' => true, 'max' => 190, 'label' => 'Name'],
                'body_type' => ['type' => 'text', 'required' => false, 'max' => 50, 'label' => 'Type'],
                'description' => ['type' => 'textlong', 'required' => false, 'label' => 'Description'],
                'status' => ['type' => 'status', 'required' => true, 'label' => 'Status'],
            ],
        ],
        'role' => [
            'table' => 'gov_role',
            'label' => 'Role',
            'listFields' => ['name', 'role_code', 'status'],
            'fields' => [
                'body_id' => ['type' => 'ref', 'ref' => 'body', 'required' => true, 'label' => 'Body'],
                'name' => ['type' => 'text', 'required' => true, 'max' => 190, 'label' => 'Name'],
                'role_code' => ['type' => 'text', 'required' => false, 'max' => 80, 'label' => 'Code'],
                'description' => ['type' => 'textlong', 'required' => false, 'label' => 'Description'],
                'status' => ['type' => 'status', 'required' => true, 'label' => 'Status'],
            ],
        ],
        'appointment' => [
            'table' => 'gov_appointment',
            'label' => 'Appointment',
            'listFields' => ['person_id', 'role_id', 'start_date', 'end_date', 'status'],
            'fields' => [
                'role_id' => ['type' => 'ref', 'ref' => 'role', 'required' => true, 'label' => 'Role'],
                'person_id' => ['type' => 'int', 'required' => true, 'label' => 'Person ID (ChurchCRM reference)'],
                'appointed_by_person_id' => ['type' => 'int', 'required' => false, 'label' => 'Appointed by (Person ID)'],
                'start_date' => ['type' => 'date', 'required' => false, 'label' => 'Start date'],
                'end_date' => ['type' => 'date', 'required' => false, 'label' => 'End date'],
                'status' => ['type' => 'status', 'required' => true, 'label' => 'Status'],
                'notes' => ['type' => 'textlong', 'required' => false, 'label' => 'Notes'],
            ],
        ],
    ];

    private \Propel\Runtime\Connection\ConnectionInterface $conn;

    public function __construct(?\Propel\Runtime\Connection\ConnectionInterface $conn = null)
    {
        $this->conn = $conn ?? Propel::getConnection();
    }

    /**
     * @param string $entity one of the ENTITIES keys
     */
    public function getEntity(string $entity): array
    {
        if (!isset(self::ENTITIES[$entity])) {
            throw new GovDataException('Unknown governance entity.');
        }

        return self::ENTITIES[$entity];
    }

    /**
     * Dashboard counters. Empty tables yield 0; failures raise
     * GovDataException so callers can show an explicit error state.
     *
     * @return array{structures:int, bodies:int, open_issues:int, open_tasks:int}
     */
    public function getDashboardCounts(): array
    {
        try {
            return [
                'structures' => $this->countRows('gov_structure'),
                'bodies' => $this->countRows('gov_body'),
                'open_issues' => $this->countRows('gov_issue', 'open'),
                'open_tasks' => $this->countRows('gov_task', 'open'),
            ];
        } catch (\PDOException $e) {
            throw new GovDataException('Governance data is currently unavailable.', [], $e);
        }
    }

    /**
     * List rows of an entity, newest first by default.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(string $entity, int $limit = 500): array
    {
        $cfg = $this->getEntity($entity);
        $limit = max(1, min(1000, $limit));

        try {
            $stmt = $this->conn->prepare(
                'SELECT * FROM ' . $cfg['table'] . ' ORDER BY id DESC LIMIT :lim'
            );
            $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            throw new GovDataException('Unable to load ' . $cfg['label'] . ' records.', [], $e);
        }
    }

    /**
     * Find one row by primary key, or null when it does not exist.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $entity, int $id): ?array
    {
        $cfg = $this->getEntity($entity);

        try {
            $stmt = $this->conn->prepare(
                'SELECT * FROM ' . $cfg['table'] . ' WHERE id = :id'
            );
            $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            return $row === false ? null : $row;
        } catch (\PDOException $e) {
            throw new GovDataException('Unable to load ' . $cfg['label'] . ' record.', [], $e);
        }
    }

    /**
     * Insert one row. Runs validation first; throws GovDataException with
     * per-field errors on invalid input.
     */
    public function insert(string $entity, array $data): int
    {
        $cfg = $this->getEntity($entity);
        $errors = $this->validate($entity, $data);
        if ($errors !== []) {
            throw new GovDataException('Please correct the highlighted fields.', $errors);
        }

        $fields = array_keys($cfg['fields']);
        $columns = implode(', ', $fields);
        $placeholders = implode(', ', array_map(fn ($f) => ':' . $f, $fields));
        $data = $this->applyDefaults($cfg, $data);

        try {
            $stmt = $this->conn->prepare(
                'INSERT INTO ' . $cfg['table'] . ' (' . $columns . ') VALUES (' . $placeholders . ')'
            );
            foreach ($fields as $field) {
                $this->bindValue($stmt, $field, $data[$field] ?? null, $cfg['fields'][$field]['type']);
            }
            $stmt->execute();

            return (int) $this->conn->lastInsertId();
        } catch (\PDOException $e) {
            throw new GovDataException('Unable to save the ' . $cfg['label'] . ' record.', [], $e);
        }
    }

    /**
     * Update one row by primary key. Runs validation first.
     */
    public function update(string $entity, int $id, array $data): void
    {
        $cfg = $this->getEntity($entity);
        $errors = $this->validate($entity, $data);
        if ($errors !== []) {
            throw new GovDataException('Please correct the highlighted fields.', $errors);
        }

        $fields = array_keys($cfg['fields']);
        $assignments = implode(', ', array_map(fn ($f) => $f . ' = :' . $f, $fields));
        $data = $this->applyDefaults($cfg, $data);

        try {
            $stmt = $this->conn->prepare(
                'UPDATE ' . $cfg['table'] . ' SET ' . $assignments . ' WHERE id = :id'
            );
            foreach ($fields as $field) {
                $this->bindValue($stmt, $field, $data[$field] ?? null, $cfg['fields'][$field]['type']);
            }
            $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
            $stmt->execute();
        } catch (\PDOException $e) {
            throw new GovDataException('Unable to save the ' . $cfg['label'] . ' record.', [], $e);
        }
    }

    /**
     * Delete one row by primary key. Returns false when the row does not
     * exist. Not exposed via routes in Phase 3; kept for tests and future
     * explicit admin actions.
     */
    public function delete(string $entity, int $id): bool
    {
        $cfg = $this->getEntity($entity);

        try {
            $stmt = $this->conn->prepare('DELETE FROM ' . $cfg['table'] . ' WHERE id = :id');
            $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            throw new GovDataException('Unable to delete the ' . $cfg['label'] . ' record.', [], $e);
        }
    }

    /**
     * Validate raw (untrusted) input against the entity field spec.
     *
     * @return array<string, string> field => error message; empty when valid
     */
    public function validate(string $entity, array $data): array
    {
        $cfg = $this->getEntity($entity);
        $errors = [];

        foreach ($cfg['fields'] as $field => $spec) {
            $raw = $data[$field] ?? null;
            $value = is_string($raw) ? trim($raw) : $raw;
            $isEmpty = $value === null || $value === '';

            switch ($spec['type']) {
                case 'text':
                    if ($isEmpty) {
                        if ($spec['required']) {
                            $errors[$field] = $spec['label'] . ' is required.';
                        }
                        break;
                    }
                    if (!is_string($value)) {
                        $errors[$field] = $spec['label'] . ' is invalid.';
                        break;
                    }
                    if (isset($spec['max']) && mb_strlen($value) > $spec['max']) {
                        $errors[$field] = $spec['label'] . ' must be at most ' . $spec['max'] . ' characters.';
                        break;
                    }
                    if (preg_match('/<[^>]*>/', $value)) {
                        $errors[$field] = $spec['label'] . ' cannot contain HTML tags.';
                        break;
                    }
                    if (str_contains($value, "\0")) {
                        $errors[$field] = $spec['label'] . ' contains invalid characters.';
                    }
                    break;

                case 'textlong':
                    if ($isEmpty) {
                        break; // never required in V0.1
                    }
                    if (!is_string($value)) {
                        $errors[$field] = $spec['label'] . ' is invalid.';
                        break;
                    }
                    if (mb_strlen($value) > 65535) {
                        $errors[$field] = $spec['label'] . ' is too long.';
                    }
                    break;

                case 'int':
                    if ($isEmpty) {
                        if ($spec['required']) {
                            $errors[$field] = $spec['label'] . ' is required.';
                        }
                        break;
                    }
                    $min = (int) ($spec['min'] ?? 1);
                    if (!is_numeric($value) || (int) $value != $value || (int) $value < $min) {
                        $errors[$field] = $spec['label'] . ' must be a whole number of ' . $min . ' or greater.';
                    }
                    break;

                case 'date':
                    if ($isEmpty) {
                        if ($spec['required']) {
                            $errors[$field] = $spec['label'] . ' is required.';
                        }
                        break;
                    }
                    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                        $errors[$field] = $spec['label'] . ' must have the format YYYY-MM-DD.';
                        break;
                    }
                    [$y, $m, $d] = array_map('intval', explode('-', $value));
                    if (!checkdate($m, $d, $y)) {
                        $errors[$field] = $spec['label'] . ' is not a valid calendar date.';
                    }
                    break;

                case 'status':
                    if ($isEmpty) {
                        $errors[$field] = $spec['label'] . ' is required.';
                        break;
                    }
                    if (!is_string($value) || !in_array($value, self::STATUSES, true)) {
                        $errors[$field] = $spec['label'] . ' must be one of: ' . implode(', ', self::STATUSES) . '.';
                    }
                    break;

                case 'ref':
                    if ($isEmpty) {
                        if ($spec['required']) {
                            $errors[$field] = $spec['label'] . ' is required.';
                        }
                        break;
                    }
                    if (!is_numeric($value) || (int) $value <= 0) {
                        $errors[$field] = $spec['label'] . ' is invalid.';
                        break;
                    }
                    $refCfg = $this->getEntity($spec['ref']);
                    if ($this->find($spec['ref'], (int) $value) === null) {
                        $errors[$field] = 'Selected ' . strtolower($refCfg['label']) . ' does not exist.';
                    }
                    break;
            }
        }

        // Cross-field rule: appointment end date must not precede start date.
        if ($entity === 'appointment') {
            $start = $data['start_date'] ?? null;
            $end = $data['end_date'] ?? null;
            if (is_string($start) && is_string($end) && $start !== '' && $end !== ''
                && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)
                && $end < $start) {
                $errors['end_date'] = 'End date cannot be before the start date.';
            }
        }

        return $errors;
    }

    /**
     * Fill configured default values for fields that are missing or empty.
     * Needed for NOT NULL columns without an application-level value
     * (e.g. gov_structure.sort_order).
     */
    private function applyDefaults(array $cfg, array $data): array
    {
        foreach ($cfg['fields'] as $field => $spec) {
            if (array_key_exists('default', $spec)) {
                $v = $data[$field] ?? null;
                if ($v === null || $v === '') {
                    $data[$field] = $spec['default'];
                }
            }
        }

        return $data;
    }

    private function countRows(string $table, ?string $status = null): int
    {
        if ($status === null) {
            $stmt = $this->conn->query('SELECT COUNT(*) FROM ' . $table);

            return (int) $stmt->fetchColumn();
        }

        $stmt = $this->conn->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE status = :status');
        $stmt->bindValue(':status', $status);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    private function bindValue(\Propel\Runtime\Connection\StatementInterface $stmt, string $field, mixed $value, string $type): void
    {
        // Normalize "empty" to NULL for optional fields; database columns are
        // nullable and '' is not a meaningful value for dates/ints/refs.
        if ($value === null || $value === '') {
            $stmt->bindValue(':' . $field, null, \PDO::PARAM_NULL);

            return;
        }

        switch ($type) {
            case 'int':
            case 'ref':
                $stmt->bindValue(':' . $field, (int) $value, \PDO::PARAM_INT);
                break;
            case 'date':
            case 'text':
            case 'textlong':
            case 'status':
                $stmt->bindValue(':' . $field, is_string($value) ? trim($value) : (string) $value, \PDO::PARAM_STR);
                break;
            default:
                $stmt->bindValue(':' . $field, $value);
        }
    }
}
