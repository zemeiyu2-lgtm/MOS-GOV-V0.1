<?php

namespace ChurchCRM\Plugins\MosGov\Integration;

use ChurchCRM\model\ChurchCRM\Person;
use ChurchCRM\model\ChurchCRM\PersonQuery;

/**
 * Read-only bridge from MOS-GOV governance records to ChurchCRM people (R08).
 *
 * Boundary rule (docs/ARCHITECTURE.md):
 *  - ChurchCRM remains the single authoritative source of person facts.
 *  - MOS-GOV stores only the integer `person_id` reference.
 *  - This class therefore READS ChurchCRM through its own ORM
 *    (ChurchCRM\model\ChurchCRM\PersonQuery) and never writes ChurchCRM
 *    tables, never caches person data into gov_* columns, and never creates
 *    a parallel people table.
 *
 * If ChurchCRM is unavailable the lookups degrade to an explicit
 * "unavailable" marker rather than throwing inside a view render.
 */
final class PersonLookup
{
    /** Max candidates offered to a person picker (keeps pages bounded). */
    public const CANDIDATE_LIMIT = 500;

    /** @var array<int, array<string, mixed>|null> request-scoped memo */
    private static array $memo = [];

    /** @var array<int, string>|null request-scoped candidate cache */
    private static ?array $candidates = null;

    /**
     * Look up one person.
     *
     * @return array{id:int, fullName:string, email:?string, active:bool}|null
     *         null when the person does not exist
     */
    public static function find(int $personId): ?array
    {
        if ($personId <= 0) {
            return null;
        }
        if (array_key_exists($personId, self::$memo)) {
            return self::$memo[$personId];
        }

        try {
            /** @var Person|null $person */
            $person = PersonQuery::create()->findPk($personId);
        } catch (\Throwable $e) {
            // ChurchCRM unavailable — surface null rather than breaking a page.
            return self::$memo[$personId] = null;
        }

        if ($person === null) {
            return self::$memo[$personId] = null;
        }

        return self::$memo[$personId] = [
            'id' => $personId,
            'fullName' => (string) $person->getFullName(),
            'email' => $person->getEmail(),
            'active' => $person->isActive(),
        ];
    }

    /** True when the ChurchCRM person exists (used by validation). */
    public static function exists(int $personId): bool
    {
        return self::find($personId) !== null;
    }

    /**
     * Display label for a person reference, e.g. "Ada Lovelace (#12)".
     */
    public static function label(int $personId): string
    {
        if ($personId <= 0) {
            return '';
        }
        $person = self::find($personId);
        if ($person === null) {
            return 'Person #' . $personId . ' (not found in ChurchCRM)';
        }

        return $person['fullName'] . ' (#' . $personId . ')';
    }

    /**
     * Resolve several person IDs at once.
     *
     * @param array<int, int> $personIds
     *
     * @return array<int, string> person_id => label
     */
    public static function labels(array $personIds): array
    {
        $out = [];
        foreach ($personIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $out[$id] = self::label($id);
            }
        }

        return $out;
    }

    /**
     * Candidate list for a person picker.
     *
     * @return array<int, string> person_id => "Full name (#id)"
     */
    public static function candidates(int $limit = self::CANDIDATE_LIMIT): array
    {
        if (self::$candidates !== null) {
            return array_slice(self::$candidates, 0, $limit, true);
        }

        try {
            $people = PersonQuery::create()
                ->filterByLiving()
                ->orderByLastName()
                ->orderByFirstName()
                ->limit(self::CANDIDATE_LIMIT)
                ->find();
        } catch (\Throwable $e) {
            return self::$candidates = [];
        }

        $out = [];
        foreach ($people as $person) {
            $out[(int) $person->getId()] = $person->getFullName() . ' (#' . $person->getId() . ')';
        }

        return self::$candidates = $out;
    }

    /**
     * Free-text search over ChurchCRM people (name / email fragments).
     *
     * @return array<int, string> person_id => label
     */
    public static function search(string $query, int $limit = 25): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        try {
            $people = PersonQuery::create()
                ->filterByFirstName('%' . $query . '%', \Propel\Runtime\ActiveQuery\Criteria::LIKE)
                ->_or()
                ->filterByLastName('%' . $query . '%', \Propel\Runtime\ActiveQuery\Criteria::LIKE)
                ->orderByLastName()
                ->orderByFirstName()
                ->limit(max(1, min(100, $limit)))
                ->find();
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($people as $person) {
            $out[(int) $person->getId()] = $person->getFullName() . ' (#' . $person->getId() . ')';
        }

        return $out;
    }

    /** Reset the request-scoped caches (used by tests). */
    public static function resetCache(): void
    {
        self::$memo = [];
        self::$candidates = null;
    }
}
