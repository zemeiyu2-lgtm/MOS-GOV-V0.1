<?php

namespace ChurchCRM\Plugins\MosGov\Governance;

use ChurchCRM\Plugins\MosGov\Data\GovRepository;
use ChurchCRM\Plugins\MosGov\Security\GovernanceContext;
use ChurchCRM\Plugins\MosGov\Security\GovernancePolicy;
use ChurchCRM\Plugins\MosGov\Security\PermissionResolver;
use ChurchCRM\Plugins\MosGov\Security\ScopeResolver;
use Propel\Runtime\Propel;

/**
 * Governance search (V0.2 design §23).
 *
 * The CRM global search is NOT the governance search. Every query flows
 * through: Query → Authorization → Scope → Visibility → Results.
 *
 * - A member without an active governance role can only reach P1/P2 rows.
 * - P5 content is masked regardless of matches.
 * - People are only searchable for identities holding identity.view; even
 *   then the result list never exposes more than the governance label.
 */
final class GovSearchService
{
    private GovRepository $repo;

    public function __construct(?GovRepository $repo = null)
    {
        $this->repo = $repo ?? new GovRepository();
    }

    /**
     * Scoped search across the governance entities.
     *
     * @return array<string, array<int, array<string, mixed>>> entity => rows
     */
    public function search(GovernanceContext $ctx, string $query, int $limit = 10): array
    {
        $query = trim($query);
        if ($query === '' || mb_strlen($query) > 100) {
            return [];
        }

        $results = [];
        foreach (['meeting', 'issue', 'decision', 'task'] as $entity) {
            if (!PermissionResolver::has($ctx, $entity . '.view') && !PermissionResolver::has($ctx, 'governance.view')) {
                continue;
            }
            $rows = $this->searchEntity($entity, $query, $limit);
            $visible = [];
            foreach ($rows as $row) {
                if (!GovernancePolicy::decide($this->user(), 'view', $entity, $row)->allowed) {
                    continue;
                }
                $visible[] = GovernancePolicy::filterFields($ctx, $entity, [$row])[0];
            }
            if ($visible !== []) {
                $results[$entity] = $visible;
            }
        }

        return $results;
    }

    /** The current user (injected seam kept simple for service use). */
    private function user(): ?\ChurchCRM\model\ChurchCRM\User
    {
        return \ChurchCRM\Plugins\MosGov\Security\GovAuthorization::currentUser();
    }

    /**
     * Whitelisted LIKE search on the entity's searchable columns. Columns
     * come from the registry, values are bound — no user input reaches SQL
     * structure.
     *
     * @return array<int, array<string, mixed>>
     */
    private function searchEntity(string $entity, string $query, int $limit): array
    {
        $searchable = [
            'meeting' => ['title', 'location'],
            'issue' => ['title', 'description'],
            'decision' => ['title', 'decision_text'],
            'task' => ['title', 'description'],
        ][$entity] ?? [];

        if ($searchable === []) {
            return [];
        }

        $cfg = $this->repo->getEntity($entity);
        $clauses = [];
        foreach ($searchable as $i => $column) {
            $clauses[] = $column . ' LIKE :q' . $i;
        }

        $conn = Propel::getConnection();
        $sql = 'SELECT * FROM ' . $cfg['table'] . ' WHERE (' . implode(' OR ', $clauses) . ') ORDER BY id DESC LIMIT :lim';
        $stmt = $conn->prepare($sql);
        foreach ($searchable as $i => $column) {
            $stmt->bindValue(':q' . $i, '%' . $query . '%');
        }
        $stmt->bindValue(':lim', max(1, min(50, $limit)), \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
