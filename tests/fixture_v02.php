<?php

/**
 * MOS-GOV V0.2 — STANDARD ACCEPTANCE FIXTURE  (T01..T04)
 * ------------------------------------------------------
 * CLI only. NOT part of the 14-suite regression run.
 *
 * Purpose
 *   Replace the ad-hoc "MOS-GOV V0.2 Acceptance Test" dataset with one
 *   named, repeatable acceptance environment that exercises the whole
 *   governance model end to end.
 *
 * Modes
 *   status   inventory the governance tables (what exists, what is protected)
 *   reset    delete EVERY fixture row + every legacy acceptance row
 *   setup    create the standard fixture from scratch (refuses a dirty DB)
 *   clean    delete only the current fixture, keep legacy rows untouched
 *   verify   integrity + authorization matrix + HTTP probes (needs setup)
 *   all      reset -> setup -> verify   (the one-click reset)
 *
 * Identification
 *   Every fixture row is discoverable by the marker in a text column
 *   (MARKER below) or through the FK graph that starts at the fixture
 *   structure / fixture persons. NO fixed IDs are required anywhere:
 *   persons are resolved by ChurchCRM username, everything else by marker.
 *
 * Hard boundaries (enforced by guards in this file)
 *   - Never touches ChurchCRM core tables (read-only lookups only).
 *   - Never touches MOS-GOV schema (no DDL at all).
 *   - Never deletes seeded system roles A01..E01, the seeded body /
 *     structure / scope / role-scope / visibility rows, or any governance
 *     identity that is not fixture-marked (identity 91 is protected).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

require __DIR__ . '/../../../../Include/LoadConfigs.php';
\ChurchCRM\Plugin\PluginManager::init(\ChurchCRM\dto\SystemURLs::getDocumentRoot() . '/plugins');

use ChurchCRM\model\ChurchCRM\UserQuery;
use ChurchCRM\Plugins\MosGov\Data\GovRepository;
use ChurchCRM\Plugins\MosGov\Governance\IdentityService;
use ChurchCRM\Plugins\MosGov\Security\GovAuthorization;
use ChurchCRM\Plugins\MosGov\Security\GovernanceContext;
use ChurchCRM\Plugins\MosGov\Security\GovernancePolicy;
use ChurchCRM\Plugins\MosGov\Security\VisibilityResolver;

/** Marker written into every fixture name / title / description. */
const MARKER = 'MOS-GOV Fixture';

/** Marker of the superseded acceptance dataset (deleted by `reset`). */
const LEGACY_MARKER = 'MOS-GOV V0.2 Acceptance Test';

/** Both markers are treated as "acceptance test data" by reset/clean. */
const ALL_MARKERS = [LEGACY_MARKER, MARKER];

/**
 * The four standard acceptance identities.
 * Persons are resolved by ChurchCRM username at runtime — never by id.
 * role_code refers to the SEEDED system role the fixture role mirrors.
 */
const FIXTURE_IDENTITIES = [
    'T01' => [
        'username' => 'judith.matthews@example.com',
        'label' => 'T01 牧者',
        'role_code' => 'D03',
        'role_name' => 'Fixture Pastor',
        'fixture_role_code' => 'FIX-T01',
    ],
    'T02' => [
        'username' => 'finance.nofundraiser',
        'label' => 'T02 执事',
        'role_code' => 'D01',
        'role_name' => 'Fixture Deacon',
        'fixture_role_code' => 'FIX-T02',
    ],
    'T03' => [
        'username' => 'john.plainauth@example.com',
        'label' => 'T03 小组负责人',
        'role_code' => 'B01',
        'role_name' => 'Fixture Small Group Leader',
        'fixture_role_code' => 'FIX-T03',
    ],
    'T04' => [
        'username' => 'grace.financeonly@example.com',
        'label' => 'T04 普通会员',
        'role_code' => 'A01',
        'role_name' => 'Fixture General Member',
        'fixture_role_code' => 'FIX-T04',
    ],
];

/** Seed rows this script must never delete, whatever the marker says. */
const PROTECTED_ROWS = [
    'gov_identity' => [91],
    'gov_role' => [17, 18, 19, 20, 21, 22, 23, 24, 25, 26],
    'gov_body' => [24],
    'gov_structure' => [43],
    'gov_scope' => [1, 2],
    'gov_role_scope' => [3, 4, 5, 6, 7, 8, 9, 10],
    'gov_visibility_rule' => [1, 2, 3, 4, 5, 6],
];

/** Secret tokens used by the P5 probes (never allowed to reach a page). */
const P5_SECRET_MEETING = 'MOSGOVFIXTURE-P5-SECRET-MINUTES-4f1a';
const P5_SECRET_DECISION = 'MOSGOVFIXTURE-P5-SECRET-DECISION-8b27';
const P5_SECRET_APPOINTMENT = 'MOSGOVFIXTURE-P5-SECRET-APPOINTMENT-2c93';

/**
 * ChurchCRM group the T03 small-group scope binds to. The seeded B01 role
 * scope row carries a NULL group id (raw-SQL seed); the data layer refuses
 * that, so the fixture role is bound to a concrete group instead.
 */
const FIXTURE_GROUP_ID = 6;

$repo = new GovRepository();
$service = new IdentityService($repo);
$conn = \Propel\Runtime\Propel::getConnection();

$mode = $argv[1] ?? 'status';

// `all` is the one-click reset: run the three phases in fresh processes so
// no request-scoped cache of the policy engine can survive between them.
if ($mode === 'all') {
    foreach (['reset', 'setup', 'verify'] as $step) {
        echo PHP_EOL . str_repeat('=', 72) . PHP_EOL
            . 'STEP  ' . $step . PHP_EOL . str_repeat('=', 72) . PHP_EOL;
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . $step;
        passthru($cmd, $rc);
        if ($rc !== 0) {
            fwrite(STDERR, "ABORT: step '{$step}' failed with exit code {$rc}.\n");
            exit($rc);
        }
    }
    echo PHP_EOL . 'one-click reset complete: reset -> setup -> verify.' . PHP_EOL;
    exit(0);
}

$die = static function (string $why): void {
    fwrite(STDERR, "ABORT: {$why}\n");
    exit(1);
};
$say = static function (string $line = ''): void {
    echo $line . PHP_EOL;
};

// ---------------------------------------------------------------------------
// 1. small query helpers
// ---------------------------------------------------------------------------

$scalar = static function (string $sql, array $params = []) use ($conn) {
    $st = $conn->prepare($sql);
    $st->execute($params);

    return $st->fetchColumn();
};

$pluck = static function (string $sql, array $params = []) use ($conn): array {
    $st = $conn->prepare($sql);
    $st->execute($params);

    return array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
};

$rows = static function (string $sql, array $params = []) use ($conn): array {
    $st = $conn->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(\PDO::FETCH_ASSOC);
};

$inList = static function (array $ids): string {
    return implode(',', array_map('intval', $ids));
};

/** Build "col LIKE :m0 OR col LIKE :m1" over every marker into &$params. */
$markerClause = static function (string $col, array &$params, string $prefix): string {
    $parts = [];
    foreach (ALL_MARKERS as $i => $m) {
        $ph = ':' . $prefix . $i;
        $parts[] = "{$col} LIKE {$ph}";
        $params[$ph] = '%' . $m . '%';
    }

    return '(' . implode(' OR ', $parts) . ')';
};

/** Resolve the four fixture persons through ChurchCRM usernames (read-only). */
$resolvePersons = static function () use ($die): array {
    $out = [];
    foreach (FIXTURE_IDENTITIES as $code => $spec) {
        $u = UserQuery::create()->filterByUserName($spec['username'])->findOne();
        if ($u === null) {
            $die("ChurchCRM user '{$spec['username']}' ({$code}) not found — "
                . 'the fixture reuses existing ChurchCRM users and never creates them.');
        }
        $pid = (int) $u->getPersonId();
        $out[$code] = [
            'person_id' => $pid,
            'user' => $u,
            'api_key' => (string) $u->getApiKey(),
            'admin' => (bool) $u->isAdmin(),
        ];
    }

    return $out;
};

/** Permission key → id (from the seeded registry). */
$permIds = static function () use ($rows): array {
    $out = [];
    foreach ($rows('SELECT id, permission_key FROM gov_permission') as $r) {
        $out[$r['permission_key']] = (int) $r['id'];
    }

    return $out;
};

// ---------------------------------------------------------------------------
// 2. discovery — every acceptance row, by marker and by FK graph
// ---------------------------------------------------------------------------

/**
 * Resolve the complete id set of all acceptance test rows (legacy + current).
 * Everything is derived from the markers plus the FK graph; no fixed ids.
 */
$discover = static function () use ($conn, $pluck, $markerClause): array {
    $ids = [];

    // every marker query below covers BOTH the legacy dataset and the
    // current fixture, so `reset` removes the old acceptance rows too.
    $p = [];
    $w = 'name LIKE :fixname OR code LIKE :fixname OR description LIKE :fixname'
        . ' OR ' . $markerClause('name', $p, 'st')
        . ' OR ' . $markerClause('description', $p, 'std');
    $p[':fixname'] = '%' . MARKER . '%';
    $ids['gov_structure'] = $pluck("SELECT id FROM gov_structure WHERE {$w}", $p);

    $bodyWhere = 'name LIKE :a OR description LIKE :a';
    foreach (ALL_MARKERS as $i => $m) {
        $bodyWhere .= " OR name LIKE :b{$i}";
    }
    $bodyP = [':a' => '%' . MARKER . '%'];
    foreach (ALL_MARKERS as $i => $m) {
        $bodyP[":b{$i}"] = '%' . $m . '%';
    }
    if ($ids['gov_structure'] !== []) {
        $bodyWhere = '(' . $bodyWhere . ') OR structure_id IN ('
            . implode(',', array_map('intval', $ids['gov_structure'])) . ')';
    }
    $ids['gov_body'] = $pluck("SELECT id FROM gov_body WHERE {$bodyWhere}", $bodyP);

    $roleWhere = 'name LIKE :a OR description LIKE :a OR role_code LIKE :a';
    foreach (ALL_MARKERS as $i => $m) {
        $roleWhere .= " OR name LIKE :c{$i}";
    }
    $roleP = [':a' => '%' . MARKER . '%'];
    foreach (ALL_MARKERS as $i => $m) {
        $roleP[":c{$i}"] = '%' . $m . '%';
    }
    if ($ids['gov_body'] !== []) {
        $roleWhere = '(' . $roleWhere . ') OR body_id IN ('
            . implode(',', array_map('intval', $ids['gov_body'])) . ')';
    }
    $ids['gov_role'] = $pluck("SELECT id FROM gov_role WHERE {$roleWhere}", $roleP);

    // ---- identities: marker OR a person reserved for the fixture ---------
    $persons = [];
    foreach (FIXTURE_IDENTITIES as $spec) {
        $u = UserQuery::create()->filterByUserName($spec['username'])->findOne();
        if ($u !== null) {
            $persons[] = (int) $u->getPersonId();
        }
    }
    $identWhere = 'display_name_override LIKE :a OR notes LIKE :a';
    foreach (ALL_MARKERS as $i => $m) {
        $identWhere .= " OR display_name_override LIKE :d{$i} OR notes LIKE :d{$i}";
    }
    $identP = [':a' => '%' . MARKER . '%'];
    foreach (ALL_MARKERS as $i => $m) {
        $identP[":d{$i}"] = '%' . $m . '%';
    }
    if ($persons !== []) {
        $identWhere = '(' . $identWhere . ') OR person_id IN ('
            . implode(',', array_map('intval', $persons)) . ')';
    }
    $ids['gov_identity'] = $pluck("SELECT id FROM gov_identity WHERE {$identWhere}", $identP);

    // ---- appointment / responsibility chains ----------------------------
    $apWhere = 'notes LIKE :a';
    foreach (ALL_MARKERS as $i => $m) {
        $apWhere .= " OR notes LIKE :e{$i}";
    }
    $apP = [':a' => '%' . MARKER . '%'];
    foreach (ALL_MARKERS as $i => $m) {
        $apP[":e{$i}"] = '%' . $m . '%';
    }
    if ($ids['gov_role'] !== []) {
        $apWhere = '(' . $apWhere . ') OR role_id IN (' . implode(',', array_map('intval', $ids['gov_role'])) . ')';
    }
    if ($persons !== []) {
        $apWhere = '(' . $apWhere . ') OR person_id IN (' . implode(',', array_map('intval', $persons)) . ')';
    }
    $ids['gov_appointment'] = $pluck("SELECT id FROM gov_appointment WHERE {$apWhere}", $apP);

    $rsWhere = 'title LIKE :a OR description LIKE :a';
    foreach (ALL_MARKERS as $i => $m) {
        $rsWhere .= " OR title LIKE :f{$i}";
    }
    $rsP = [':a' => '%' . MARKER . '%'];
    foreach (ALL_MARKERS as $i => $m) {
        $rsP[":f{$i}"] = '%' . $m . '%';
    }
    if ($ids['gov_appointment'] !== []) {
        $rsWhere = '(' . $rsWhere . ') OR appointment_id IN ('
            . implode(',', array_map('intval', $ids['gov_appointment'])) . ')';
    }
    if ($ids['gov_role'] !== []) {
        $rsWhere = '(' . $rsWhere . ') OR role_id IN (' . implode(',', array_map('intval', $ids['gov_role'])) . ')';
    }
    $ids['gov_responsibility'] = $pluck("SELECT id FROM gov_responsibility WHERE {$rsWhere}", $rsP);

    // ---- running chain: meeting -> issue -> decision -> task -------------
    $mtWhere = 'title LIKE :a OR location LIKE :a OR minutes LIKE :a';
    foreach (ALL_MARKERS as $i => $m) {
        $mtWhere .= " OR title LIKE :g{$i}";
    }
    $mtP = [':a' => '%' . MARKER . '%'];
    foreach (ALL_MARKERS as $i => $m) {
        $mtP[":g{$i}"] = '%' . $m . '%';
    }
    if ($ids['gov_body'] !== []) {
        $mtWhere = '(' . $mtWhere . ') OR body_id IN (' . implode(',', array_map('intval', $ids['gov_body'])) . ')';
    }
    $ids['gov_meeting'] = $pluck("SELECT id FROM gov_meeting WHERE {$mtWhere}", $mtP);

    $isWhere = 'title LIKE :a OR description LIKE :a';
    foreach (ALL_MARKERS as $i => $m) {
        $isWhere .= " OR title LIKE :h{$i}";
    }
    $isP = [':a' => '%' . MARKER . '%'];
    foreach (ALL_MARKERS as $i => $m) {
        $isP[":h{$i}"] = '%' . $m . '%';
    }
    if ($ids['gov_meeting'] !== []) {
        $isWhere = '(' . $isWhere . ') OR meeting_id IN ('
            . implode(',', array_map('intval', $ids['gov_meeting'])) . ')';
    }
    if ($ids['gov_body'] !== []) {
        $isWhere = '(' . $isWhere . ') OR body_id IN (' . implode(',', array_map('intval', $ids['gov_body'])) . ')';
    }
    $ids['gov_issue'] = $pluck("SELECT id FROM gov_issue WHERE {$isWhere}", $isP);

    $dcWhere = 'title LIKE :a OR decision_text LIKE :a';
    foreach (ALL_MARKERS as $i => $m) {
        $dcWhere .= " OR title LIKE :i{$i}";
    }
    $dcP = [':a' => '%' . MARKER . '%'];
    foreach (ALL_MARKERS as $i => $m) {
        $dcP[":i{$i}"] = '%' . $m . '%';
    }
    if ($ids['gov_issue'] !== []) {
        $dcWhere = '(' . $dcWhere . ') OR issue_id IN (' . implode(',', array_map('intval', $ids['gov_issue'])) . ')';
    }
    if ($ids['gov_meeting'] !== []) {
        $dcWhere = '(' . $dcWhere . ') OR meeting_id IN ('
            . implode(',', array_map('intval', $ids['gov_meeting'])) . ')';
    }
    $ids['gov_decision'] = $pluck("SELECT id FROM gov_decision WHERE {$dcWhere}", $dcP);

    $tkWhere = 'title LIKE :a OR description LIKE :a';
    foreach (ALL_MARKERS as $i => $m) {
        $tkWhere .= " OR title LIKE :j{$i}";
    }
    $tkP = [':a' => '%' . MARKER . '%'];
    foreach (ALL_MARKERS as $i => $m) {
        $tkP[":j{$i}"] = '%' . $m . '%';
    }
    if ($ids['gov_decision'] !== []) {
        $tkWhere = '(' . $tkWhere . ') OR decision_id IN ('
            . implode(',', array_map('intval', $ids['gov_decision'])) . ')';
    }
    if ($ids['gov_responsibility'] !== []) {
        $tkWhere = '(' . $tkWhere . ') OR responsibility_id IN ('
            . implode(',', array_map('intval', $ids['gov_responsibility'])) . ')';
    }
    if ($persons !== []) {
        $tkWhere = '(' . $tkWhere . ') OR assignee_person_id IN ('
            . implode(',', array_map('intval', $persons)) . ')';
    }
    $ids['gov_task'] = $pluck("SELECT id FROM gov_task WHERE {$tkWhere}", $tkP);

    // ---- child tables keyed off the sets above --------------------------
    $ids['gov_identity_role'] = $ids['gov_identity'] === [] ? [] : $pluck(
        'SELECT id FROM gov_identity_role WHERE identity_id IN ('
        . implode(',', array_map('intval', $ids['gov_identity'])) . ')'
    );
    $ids['gov_identity_scope'] = $ids['gov_identity'] === [] ? [] : $pluck(
        'SELECT id FROM gov_identity_scope WHERE identity_id IN ('
        . implode(',', array_map('intval', $ids['gov_identity'])) . ')'
    );
    $ids['gov_identity_permission'] = $ids['gov_identity'] === [] ? [] : $pluck(
        'SELECT id FROM gov_identity_permission WHERE identity_id IN ('
        . implode(',', array_map('intval', $ids['gov_identity'])) . ')'
    );
    $ids['gov_role_scope'] = $ids['gov_role'] === [] ? [] : $pluck(
        'SELECT id FROM gov_role_scope WHERE role_id IN ('
        . implode(',', array_map('intval', $ids['gov_role'])) . ')'
    );
    $ids['gov_role_permission'] = $ids['gov_role'] === [] ? [] : $pluck(
        'SELECT id FROM gov_role_permission WHERE role_id IN ('
        . implode(',', array_map('intval', $ids['gov_role'])) . ')'
    );

    $scWhere = 'name LIKE :a OR description LIKE :a';
    foreach (ALL_MARKERS as $i => $m) {
        $scWhere .= " OR name LIKE :k{$i}";
    }
    $scP = [':a' => '%' . MARKER . '%'];
    foreach (ALL_MARKERS as $i => $m) {
        $scP[":k{$i}"] = '%' . $m . '%';
    }
    $ids['gov_scope'] = $pluck("SELECT id FROM gov_scope WHERE {$scWhere}", $scP);

    $rlWhere = 'description LIKE :a';
    foreach (ALL_MARKERS as $i => $m) {
        $rlWhere .= " OR description LIKE :l{$i}";
    }
    $rlP = [':a' => '%' . MARKER . '%'];
    foreach (ALL_MARKERS as $i => $m) {
        $rlP[":l{$i}"] = '%' . $m . '%';
    }
    $ids['gov_relationship'] = $pluck("SELECT id FROM gov_relationship WHERE {$rlWhere}", $rlP);

    $ids['gov_visibility_rule'] = [];
    foreach (ALL_MARKERS as $m) {
        // a visibility rule never carries a name column; guard instead
        $ids['gov_visibility_rule'] = array_merge($ids['gov_visibility_rule'], $pluck(
            'SELECT id FROM gov_visibility_rule WHERE 1=0'
        ));
    }

    return ['ids' => $ids, 'persons' => $persons];
};

// ---------------------------------------------------------------------------
// 3. guards
// ---------------------------------------------------------------------------

$assertProtected = static function (array $ids) use ($die): void {
    foreach (PROTECTED_ROWS as $table => $keep) {
        $hit = array_intersect($keep, $ids[$table] ?? []);
        if ($hit !== []) {
            $die("refusing to delete protected seed rows in {$table}: " . implode(',', $hit));
        }
    }
};

// ---------------------------------------------------------------------------
// 4. mode: status / reset / clean
// ---------------------------------------------------------------------------

$tableList = [
    'gov_structure', 'gov_body', 'gov_role', 'gov_appointment', 'gov_responsibility',
    'gov_relationship', 'gov_meeting', 'gov_issue', 'gov_decision', 'gov_task',
    'gov_identity', 'gov_identity_role', 'gov_identity_scope', 'gov_identity_permission',
    'gov_scope', 'gov_role_scope', 'gov_role_permission', 'gov_permission', 'gov_visibility_rule',
];

$counts = static function () use ($tableList, $conn): array {
    $out = [];
    foreach ($tableList as $t) {
        $out[$t] = (int) $conn->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
    }

    return $out;
};

/** Delete acceptance rows in strict child -> parent order. */
$purge = static function (array $d) use ($conn, $say, $inList): array {
    $ids = $d['ids'];
    $order = [
        'gov_task', 'gov_decision', 'gov_issue', 'gov_meeting',
        'gov_relationship',
        'gov_responsibility', 'gov_appointment',
        'gov_identity_permission', 'gov_identity_scope', 'gov_identity_role',
        'gov_identity',
        'gov_role_permission', 'gov_role_scope', 'gov_role',
        'gov_body', 'gov_structure',
        'gov_scope',
    ];
    $deleted = [];
    foreach ($order as $t) {
        $list = $ids[$t] ?? [];
        if ($list === []) {
            $deleted[$t] = 0;
            continue;
        }
        $sql = "DELETE FROM {$t} WHERE id IN (" . $inList($list) . ')';
        $n = $conn->exec($sql);
        $deleted[$t] = (int) $n;
        $say(sprintf('  delete %-24s %3d row(s)', $t, $n));
    }

    return $deleted;
};

if ($mode === 'status') {
    $d = $discover();
    $c = $counts();
    $say('=== MOS-GOV governance tables ===');
    foreach ($c as $t => $n) {
        $fixture = count($d['ids'][$t] ?? []);
        $say(sprintf('%-26s total=%-5d acceptance=%-4d kept=%d', $t, $n, $fixture, $n - $fixture));
    }
    $say();
    $say('fixture persons: ' . ($d['persons'] === [] ? '(none)' : implode(',', $d['persons'])));
    $say('protected identity: 91 (real governance administrator)');
    exit(0);
}

if ($mode === 'reset' || $mode === 'clean') {
    // `reset` and `clean` both purge every acceptance row — the superseded
    // dataset ('MOS-GOV V0.2 Acceptance Test') and the current fixture
    // ('MOS-GOV Fixture'). Seeded and real rows are protected by guards.
    $before = $counts();
    $d = $discover();
    $assertProtected($d['ids']);

    $say('=== purging all acceptance data (legacy + fixture) ===');

    $deleted = $purge($d);
    $after = $counts();

    $say();
    $say('=== before -> after ===');
    foreach ($tableList as $t) {
        if ($before[$t] !== $after[$t]) {
            $say(sprintf('%-26s %5d -> %-5d  (-%d)', $t, $before[$t], $after[$t], $before[$t] - $after[$t]));
        }
    }
    $say();
    GovAuthorization::reset();
    $say('purge complete.');
    exit(0);
}

// ---------------------------------------------------------------------------
// 5. mode: setup
// ---------------------------------------------------------------------------

if ($mode === 'setup') {
    $d = $discover();
    $existing = array_sum(array_map('count', $d['ids']));
    if ($existing > 0) {
        $die("the database still holds {$existing} acceptance row(s) — run `reset` first.");
    }

    $persons = $resolvePersons();
    foreach ($persons as $code => $info) {
        if ($info['admin']) {
            $die("{$code} maps to a ChurchCRM administrator ({$info['user']->getUserName()}); "
                . 'administrators must stay identity-less so the my-governance suite can test the empty state.');
        }
    }

    $say('=== building the standard acceptance fixture ===');

    // seeded roles we mirror (never modified)
    $seedRole = [];
    foreach ($repo->list('role', 1000) as $r) {
        $seedRole[(string) $r['role_code']] = $r;
    }
    foreach (FIXTURE_IDENTITIES as $code => $spec) {
        if (!isset($seedRole[$spec['role_code']])) {
            $die("seeded role {$spec['role_code']} is missing — cannot mirror the permission model.");
        }
    }
    $permId = $permIds();

    $seedBody = $repo->find('body', 24);
    if ($seedBody === null) {
        $die('seeded body 24 (Governance Role Registry) is missing.');
    }

    // ---------------------------------------------------------- structure
    $structureId = $repo->insert('structure', [
        'name' => MARKER . ' 治理结构',
        'code' => 'FIX-STR',
        'description' => MARKER . ' — 标准验收治理结构（可完整重建）',
        'status' => 'active',
        'sort_order' => 50,
    ]);
    $say("  structure  #{$structureId}  " . MARKER . ' 治理结构');

    // -------------------------------------------------------------- bodies
    $bodyMain = $repo->insert('body', [
        'structure_id' => $structureId,
        'name' => MARKER . ' 教会治理委员会',
        'body_type' => 'committee',
        'description' => MARKER . ' — T01/T02 的治理主体（教会级）',
        'status' => 'active',
    ]);
    $bodyGroup = $repo->insert('body', [
        'structure_id' => $structureId,
        'name' => MARKER . ' 恩典小组',
        'body_type' => 'group',
        'description' => MARKER . ' — T03 的治理主体（小组级）',
        'status' => 'active',
    ]);
    $bodyOutside = $repo->insert('body', [
        'structure_id' => $structureId,
        'name' => MARKER . ' 范围外事工部',
        'body_type' => 'ministry',
        'description' => MARKER . ' — 越权探针：故意位于 T03 范围之外',
        'status' => 'active',
    ]);
    $say("  bodies     #{$bodyMain} (committee) #{$bodyGroup} (group) #{$bodyOutside} (ministry/out-of-scope)");

    // ---------------------------------------- seats + roles per identity
    // Every fixture role is a fresh row placed in the fixture structure, and
    // mirrors the permission set of its seeded counterpart role_code.
    $fixture = [];
    foreach (FIXTURE_IDENTITIES as $code => $spec) {
        $target = ($code === 'T03') ? $bodyGroup : $bodyMain;
        $roleId = $repo->insert('role', [
            'body_id' => $target,
            'name' => MARKER . ' ' . $spec['role_name'],
            'role_code' => $spec['fixture_role_code'],
            'description' => MARKER . " — mirrors seeded {$spec['role_code']}",
            'status' => 'active',
        ]);

        // copy the seeded permission grants of the mirrored role
        $copied = 0;
        foreach ($rows(
            'SELECT p.permission_key FROM gov_role_permission rp
             JOIN gov_permission p ON p.id = rp.permission_id
             WHERE rp.role_id = :r AND rp.grant_mode = :g',
            [':r' => (int) $seedRole[$spec['role_code']]['id'], ':g' => 'allow']
        ) as $pr) {
            $repo->insert('role_permission', [
                'role_id' => $roleId,
                'permission_id' => $permId[$pr['permission_key']],
                'grant_mode' => 'allow',
            ]);
            $copied++;
        }

        // mirror the seeded role scope
        $seedScope = $rows('SELECT scope_type, scope_id, scope_mode FROM gov_role_scope WHERE role_id = :r',
            [':r' => (int) $seedRole[$spec['role_code']]['id']]);
        foreach ($seedScope as $s) {
            $scopeId = $s['scope_id'] !== null ? (int) $s['scope_id'] : null;
            if ($scopeId === null && !in_array($s['scope_type'], GovRepository::GLOBAL_SCOPE_TYPES, true)) {
                // the seed carries a NULL id for a numeric scope type; the data
                // layer rejects that, so bind the fixture role to a real id.
                $scopeId = $s['scope_type'] === 'group' ? FIXTURE_GROUP_ID : null;
            }
            if ($scopeId === null && !in_array($s['scope_type'], GovRepository::GLOBAL_SCOPE_TYPES, true)) {
                continue; // nothing sensible to bind; skip instead of guessing
            }
            $repo->insert('role_scope', [
                'role_id' => $roleId,
                'scope_type' => $s['scope_type'],
                'scope_id' => $scopeId,
                'scope_mode' => $s['scope_mode'],
            ]);
        }

        // appointment + responsibility (Structure -> Body -> Role -> Appointment -> Responsibility)
        $appointmentId = $repo->insert('appointment', [
            'role_id' => $roleId,
            'person_id' => $persons[$code]['person_id'],
            'appointed_by_person_id' => $persons['T01']['person_id'],
            'start_date' => date('Y-m-d', strtotime('-60 days')),
            'status' => 'active',
            'notes' => MARKER . " {$code} 任命 — " . P5_SECRET_APPOINTMENT,
        ]);

        $respTitle = match ($code) {
            'T01' => MARKER . ' 职责：牧养与教导监督',
            'T02' => MARKER . ' 职责：执事会财务监督',
            'T03' => MARKER . ' 职责：小组牧养与关怀',
            default => MARKER . ' 职责：会友参与与反馈',
        };
        $responsibilityId = $repo->insert('responsibility', [
            'role_id' => $roleId,
            'appointment_id' => $appointmentId,
            'title' => $respTitle,
            'description' => MARKER . " {$code} 职责说明",
            'priority' => $code === 'T01' ? 'high' : 'normal',
            'status' => 'active',
        ]);

        $identityId = $service->provisionIdentity($persons[$code]['person_id'], [
            'display_name_override' => MARKER . " {$code} " . $spec['label'],
            'notes' => MARKER . " {$code} 标准验收身份（person "
                . $persons[$code]['person_id'] . '）',
        ]);
        $identityRoleId = $service->attachRole($identityId, $roleId, $appointmentId, date('Y-m-d', strtotime('-60 days')));

        $fixture[$code] = [
            'person_id' => $persons[$code]['person_id'],
            'username' => $spec['username'],
            'api_key' => $persons[$code]['api_key'],
            'identity' => $identityId,
            'identity_role' => $identityRoleId,
            'role' => $roleId,
            'appointment' => $appointmentId,
            'responsibility' => $responsibilityId,
            'scopes' => [],
            'permissions' => [],
            'permissions_copied' => $copied,
        ];
        $say("  {$code}  identity #{$identityId}  role #{$roleId} ({$spec['fixture_role_code']})  "
            . "appointment #{$appointmentId}  responsibility #{$responsibilityId}  perms={$copied}");
    }

    // --------------------------------------------------------------- scopes
    // T01: church inherited from its role (pure role inheritance, no override).
    // T02: church inherited + explicit body scope bound to the appointment.
    // T03: group registered scope + explicit body scope of the small group.
    // T04: no scope at all -> nothing but its own person-scoped records.
    $fixture['T02']['scopes'][] = $service->assignScope(
        $fixture['T02']['identity'], 'body', $bodyMain, 'appointment', $fixture['T02']['appointment']
    );

    // T04: the member-level congregational read scope. A01 holds no role
    // scope by seed design, so the fixture grants it explicitly to model the
    // "member may read, may change nothing" case.
    $fixture['T04']['scopes'][] = $service->assignScope(
        $fixture['T04']['identity'], 'church', null, 'manual_assignment'
    );

    $groupScopeId = $repo->insert('scope', [
        'scope_type' => 'group',
        'scope_id' => FIXTURE_GROUP_ID,
        'name' => MARKER . ' 小组范围（group ' . FIXTURE_GROUP_ID . '）',
        'description' => MARKER . ' — T03 小组级治理范围',
        'status' => 'active',
    ]);
    $fixture['T03']['scopes'][] = $service->assignScope(
        $fixture['T03']['identity'], 'group', FIXTURE_GROUP_ID, 'manual_assignment'
    );
    $fixture['T03']['scopes'][] = $service->assignScope(
        $fixture['T03']['identity'], 'body', $bodyGroup, 'appointment', $fixture['T03']['appointment']
    );
    $say("  scope      T03 group#6 -> gov_scope #{$groupScopeId}, T03 body #{$bodyGroup}, T02 body #{$bodyMain}");

    // ------------------------------------------- explicit identity grants
    // Asymmetry on purpose: the matrix must show view != export,
    // explicit-deny > role grant, and that scope never widens permission.
    $grants = [
        ['T01', 'meeting.export', 'grant', MARKER . ' 导出权限仅授予牧者（view != export）'],
        ['T01', 'governance.export', 'grant', MARKER . ' 模块级导出仅授予牧者'],
        ['T02', 'meeting.create', 'deny', MARKER . ' 显式拒绝优先于角色授权（explicit deny wins）'],
        ['T03', 'task.close', 'deny', MARKER . ' 小组负责人不得关闭任务'],
    ];
    foreach ($grants as [$code, $key, $mode2, $reason]) {
        if (!isset($permId[$key])) {
            $die("permission {$key} is not in the seeded registry.");
        }
        $fixture[$code]['permissions'][] = $service->overridePermission(
            $fixture[$code]['identity'], $permId[$key], $mode2, $reason
        );
        $say("  grant      {$code} {$mode2} {$key}");
    }

    // -------------------------------------------------- running chain 1
    $meetingMain = $repo->insert('meeting', [
        'body_id' => $bodyMain,
        'title' => MARKER . ' 治理委员会例会',
        'meeting_date' => date('Y-m-d', strtotime('-14 days')) . 'T19:00',
        'location' => '主堂会议室',
        'status' => 'held',
        'minutes' => MARKER . ' 例会纪要（P5 探针）— ' . P5_SECRET_MEETING,
    ]);
    $issueMain = $repo->insert('issue', [
        'body_id' => $bodyMain,
        'meeting_id' => $meetingMain,
        'title' => MARKER . ' 议题：年度预算审批',
        'description' => MARKER . ' 议题说明：审议下一年度宣教与牧养预算。',
        'priority' => 'high',
        'status' => 'open',
        'owner_person_id' => $fixture['T01']['person_id'],
        'opened_at' => date('Y-m-d', strtotime('-14 days')) . 'T19:15',
    ]);
    $decisionMain = $repo->insert('decision', [
        'issue_id' => $issueMain,
        'meeting_id' => $meetingMain,
        'title' => MARKER . ' 决策：通过年度预算',
        'decision_text' => MARKER . ' 决议内容（P5 探针）— ' . P5_SECRET_DECISION,
        'decision_status' => 'approved',
        'decided_at' => date('Y-m-d', strtotime('-14 days')) . 'T19:45',
        'decided_by_person_id' => $fixture['T01']['person_id'],
    ]);
    $taskMain = $repo->insert('task', [
        'decision_id' => $decisionMain,
        'responsibility_id' => $fixture['T01']['responsibility'],
        'title' => MARKER . ' 任务：执行年度预算',
        'description' => MARKER . ' 由牧者负责推动执行。',
        'assignee_person_id' => $fixture['T01']['person_id'],
        'due_date' => date('Y-m-d', strtotime('+30 days')),
        'priority' => 'high',
        'status' => 'open',
    ]);
    $say("  chain      meeting #{$meetingMain} -> issue #{$issueMain} -> decision #{$decisionMain} -> task #{$taskMain}");

    // -------------------------------------------------- running chain 2..n
    $taskT02 = $repo->insert('task', [
        'decision_id' => $decisionMain,
        'responsibility_id' => $fixture['T02']['responsibility'],
        'title' => MARKER . ' 任务：执事会核对预算明细',
        'description' => MARKER . ' 执事负责核对预算明细。',
        'assignee_person_id' => $fixture['T02']['person_id'],
        'due_date' => date('Y-m-d', strtotime('+7 days')),
        'priority' => 'normal',
        'status' => 'in_progress',
    ]);
    $taskT03 = $repo->insert('task', [
        'responsibility_id' => $fixture['T03']['responsibility'],
        'title' => MARKER . ' 任务：小组预算说明会',
        'description' => MARKER . ' 小组负责人向组员说明预算。',
        'assignee_person_id' => $fixture['T03']['person_id'],
        'due_date' => date('Y-m-d', strtotime('+10 days')),
        'priority' => 'normal',
        'status' => 'open',
    ]);
    $taskT04 = $repo->insert('task', [
        'title' => MARKER . ' 任务：会员预算意见反馈',
        'description' => MARKER . ' 普通会员收集并反馈意见。',
        'assignee_person_id' => $fixture['T04']['person_id'],
        'due_date' => date('Y-m-d', strtotime('+14 days')),
        'priority' => 'low',
        'status' => 'open',
    ]);

    $meetingGroup = $repo->insert('meeting', [
        'body_id' => $bodyGroup,
        'title' => MARKER . ' 小组例会',
        'meeting_date' => date('Y-m-d', strtotime('-3 days')) . 'T20:00',
        'location' => '小组家庭',
        'status' => 'held',
        'minutes' => MARKER . ' 小组例会纪要',
    ]);
    $meetingOutside = $repo->insert('meeting', [
        'body_id' => $bodyOutside,
        'title' => MARKER . ' 范围外会议（越权探针）',
        'meeting_date' => date('Y-m-d', strtotime('+3 days')) . 'T20:00',
        'location' => '范围外事工部',
        'status' => 'planned',
    ]);
    $say("  probes     out-of-scope meeting #{$meetingOutside} (body #{$bodyOutside}), group meeting #{$meetingGroup}");

    // ------------------------------------------------------ relationships
    $rels = [
        ['structure', $structureId, 'contains', 'body', $bodyMain],
        ['structure', $structureId, 'contains', 'body', $bodyGroup],
        ['structure', $structureId, 'contains', 'body', $bodyOutside],
    ];
    foreach (['T01', 'T02'] as $c) {
        $rels[] = ['body', $bodyMain, 'governs', 'role', $fixture[$c]['role']];
    }
    $rels[] = ['body', $bodyGroup, 'governs', 'role', $fixture['T03']['role']];
    $rels[] = ['body', $bodyMain, 'governs', 'role', $fixture['T04']['role']];
    $rels[] = ['body', $bodyGroup, 'accountable-to', 'body', $bodyMain];
    $rels[] = ['body', $bodyOutside, 'accountable-to', 'body', $bodyMain];
    $relIds = [];
    foreach ($rels as [$ft, $fid, $type, $tt, $tid]) {
        $relIds[] = $repo->insert('relationship', [
            'from_type' => $ft, 'from_id' => $fid, 'relationship_type' => $type,
            'to_type' => $tt, 'to_id' => $tid,
            'description' => MARKER . " 关系：{$ft}#{$fid} {$type} {$tt}#{$tid}",
            'status' => 'active',
        ]);
    }
    $say('  relations  ' . count($relIds) . ' rows');

    GovAuthorization::reset();

    $out = [
        'marker' => MARKER,
        'ids' => [
            'structure' => $structureId,
            'body_main' => $bodyMain,
            'body_group' => $bodyGroup,
            'body_outside' => $bodyOutside,
            'gov_scope_group' => $groupScopeId,
            'meeting_main' => $meetingMain,
            'meeting_group' => $meetingGroup,
            'meeting_outside' => $meetingOutside,
            'issue_main' => $issueMain,
            'decision_main' => $decisionMain,
            'task_T01' => $taskMain,
            'task_T02' => $taskT02,
            'task_T03' => $taskT03,
            'task_T04' => $taskT04,
            'relationships' => $relIds,
        ],
        'identities' => $fixture,
        'secrets' => [
            'p5_meeting' => P5_SECRET_MEETING,
            'p5_decision' => P5_SECRET_DECISION,
            'p5_appointment' => P5_SECRET_APPOINTMENT,
        ],
    ];
    @file_put_contents(__DIR__ . '/fixture_v02.state.json',
        json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    $say();
    $say('fixture built. state written to tests/fixture_v02.state.json');
    exit(0);
}

// ---------------------------------------------------------------------------
// 6. mode: verify
// ---------------------------------------------------------------------------

if ($mode === 'verify' || $mode === 'all') {
    $results = [];
    $check = static function (string $name, bool $ok, string $detail = '') use (&$results): void {
        $results[] = ($ok ? '[PASS] ' : '[FAIL] ') . $name . ($detail !== '' ? '  -- ' . $detail : '');
    };

    $persons = $resolvePersons();
    $permId = $permIds();

    // locate the fixture rows through the FK graph
    $ident = [];
    foreach (FIXTURE_IDENTITIES as $code => $spec) {
        $row = $service->findByPerson($persons[$code]['person_id']);
        $ident[$code] = $row;
    }

    $say('=== 1. fixture integrity ===');
    foreach (FIXTURE_IDENTITIES as $code => $spec) {
        $row = $ident[$code];
        $check("{$code} holds exactly one governance identity", $row !== null);
        if ($row === null) {
            continue;
        }
        $check("{$code} identity is active", $row['identity_status'] === 'active', (string) $row['identity_status']);
        $check("{$code} display name carries the marker", str_contains((string) $row['display_name_override'], MARKER));
        $roles = $rows('SELECT * FROM gov_identity_role WHERE identity_id = :i AND status = :s',
            [':i' => (int) $row['id'], ':s' => 'active']);
        $check("{$code} holds exactly one active role", count($roles) === 1, 'n=' . count($roles));
    }
    $check('no fixture identity collides with the protected identity 91',
        (int) ($scalar('SELECT COUNT(*) FROM gov_identity WHERE id = 91') ?? 0) === 1);

    $structureRow = $rows('SELECT * FROM gov_structure WHERE code = :c', [':c' => 'FIX-STR']);
    $check('fixture structure FIX-STR exists', count($structureRow) === 1);
    $fixtureStructureId = $structureRow === [] ? 0 : (int) $structureRow[0]['id'];

    $fixtureBodies = $fixtureStructureId === 0 ? [] : $rows(
        'SELECT * FROM gov_body WHERE structure_id = :s', [':s' => $fixtureStructureId]
    );
    $check('fixture has 3 bodies', count($fixtureBodies) === 3, 'n=' . count($fixtureBodies));

    $bodyIds = array_map(static fn ($b) => (int) $b['id'], $fixtureBodies);
    $fixtureRoles = $bodyIds === [] ? [] : $rows(
        'SELECT * FROM gov_role WHERE body_id IN (' . $inList($bodyIds) . ')'
    );
    $check('fixture has 4 roles', count($fixtureRoles) === 4, 'n=' . count($fixtureRoles));

    $roleIds = array_map(static fn ($r) => (int) $r['id'], $fixtureRoles);
    $fixtureAppointments = $roleIds === [] ? [] : $rows(
        'SELECT * FROM gov_appointment WHERE role_id IN (' . $inList($roleIds) . ')'
    );
    $check('fixture has 4 appointments', count($fixtureAppointments) === 4, 'n=' . count($fixtureAppointments));

    $appIds = array_map(static fn ($a) => (int) $a['id'], $fixtureAppointments);
    $fixtureResp = $roleIds === [] ? [] : $rows(
        'SELECT * FROM gov_responsibility WHERE role_id IN (' . $inList($roleIds) . ')'
    );
    $check('fixture has 4 responsibilities', count($fixtureResp) === 4, 'n=' . count($fixtureResp));

    // integrity: no orphans
    $orphans = [];
    $orphans['body->structure'] = (int) $scalar(
        'SELECT COUNT(*) FROM gov_body b LEFT JOIN gov_structure s ON s.id = b.structure_id
         WHERE b.structure_id IS NOT NULL AND s.id IS NULL AND b.id IN (' . $inList($bodyIds) . ')'
    );
    $orphans['role->body'] = (int) $scalar(
        'SELECT COUNT(*) FROM gov_role r LEFT JOIN gov_body b ON b.id = r.body_id
         WHERE b.id IS NULL AND r.id IN (' . $inList($roleIds) . ')'
    );
    $orphans['appointment->role'] = (int) $scalar(
        'SELECT COUNT(*) FROM gov_appointment a LEFT JOIN gov_role r ON r.id = a.role_id
         WHERE r.id IS NULL AND a.id IN (' . $inList($appIds) . ')'
    );
    $check('no orphan rows in the fixture chain', array_sum($orphans) === 0, json_encode($orphans));

    // running chain integrity
    $fixtureMeetings = $bodyIds === [] ? [] : $rows(
        'SELECT * FROM gov_meeting WHERE body_id IN (' . $inList($bodyIds) . ') ORDER BY id'
    );
    $meetingIds = array_map(static fn ($m) => (int) $m['id'], $fixtureMeetings);
    $check('fixture has 3 meetings (main / group / out-of-scope)', count($fixtureMeetings) === 3, 'n=' . count($fixtureMeetings));
    $fixtureIssues = $meetingIds === [] ? [] : $rows(
        'SELECT * FROM gov_issue WHERE meeting_id IN (' . $inList($meetingIds) . ')'
    );
    $check('fixture has 1 issue on the main meeting', count($fixtureIssues) === 1, 'n=' . count($fixtureIssues));
    $issueIds = array_map(static fn ($i) => (int) $i['id'], $fixtureIssues);
    $fixtureDecisions = $issueIds === [] ? [] : $rows(
        'SELECT * FROM gov_decision WHERE issue_id IN (' . $inList($issueIds) . ')'
    );
    $check('fixture has 1 decision on that issue', count($fixtureDecisions) === 1, 'n=' . count($fixtureDecisions));
    $decisionIds = array_map(static fn ($d) => (int) $d['id'], $fixtureDecisions);
    $fixtureTasks = $decisionIds === [] ? [] : $rows(
        'SELECT * FROM gov_task WHERE decision_id = :d', [':d' => $decisionIds[0]]
    );
    $check('fixture main decision carries a task (chain closes)', count($fixtureTasks) >= 1, 'n=' . count($fixtureTasks));

    $t04Person = $persons['T04']['person_id'];
    $t04Tasks = $rows('SELECT * FROM gov_task WHERE assignee_person_id = :p', [':p' => $t04Person]);
    $check('T04 has at least one person-scoped task', count($t04Tasks) >= 1, 'n=' . count($t04Tasks));

    // protected seed intact
    $seedRoles = (int) $scalar("SELECT COUNT(*) FROM gov_role WHERE role_code IN ('A01','A02','B01','B02','B03','C01','D01','D02','D03','E01')");
    $seedPerms = (int) $scalar('SELECT COUNT(*) FROM gov_permission');
    $seedBody = (int) $scalar('SELECT COUNT(*) FROM gov_body WHERE id = 24');
    $seedStruct = (int) $scalar('SELECT COUNT(*) FROM gov_structure WHERE id = 43');
    $seedIdent = (int) $scalar('SELECT COUNT(*) FROM gov_identity WHERE id = 91');
    $p5Allow = (int) $scalar(
        "SELECT COUNT(*) FROM gov_visibility_rule WHERE information_level='P5' AND rule_type='allow'
         AND status='active' AND resource_type='*' AND role_id IS NULL"
    );
    $check('seeded system roles A01..E01 intact (10)', $seedRoles === 10, "n={$seedRoles}");
    $check('permission registry intact (>=35)', $seedPerms >= 35, "n={$seedPerms}");
    $check('seeded registry body 24 intact', $seedBody === 1);
    $check('seeded structure 43 intact', $seedStruct === 1);
    $check('real governance identity 91 intact', $seedIdent === 1);
    $check('no global P5 allow rule was introduced', $p5Allow === 0, "n={$p5Allow}");

    // ------------------------------------------------- authorization matrix
    $say();
    $say('=== 2a. permission-registry separation (role grants per identity) ===');

    $ctxOf = static function (string $code) use ($persons): ?GovernanceContext {
        GovAuthorization::reset();
        VisibilityResolver::resetCache();

        return GovernanceContext::forUser($persons[$code]['user']);
    };
    $dec = static function (string $code, string $action, string $resource, ?array $row = null) use ($persons) {
        GovAuthorization::reset();
        VisibilityResolver::resetCache();

        return GovernancePolicy::decide($persons[$code]['user'], $action, $resource, $row);
    };
    $keysOf = static function (string $code) use ($ctxOf): array {
        $ctx = $ctxOf($code);

        return $ctx === null ? [] : array_keys($ctx->permissions());
    };
    $keyCache = [];
    $holds = static function (string $code, string $key) use (&$keyCache, $keysOf): bool {
        $keyCache[$code] ??= $keysOf($code);

        return in_array($key, $keyCache[$code], true);
    };

    // registry truth: who holds which key
    $check('T04 (member) holds meeting.view', $holds('T04', 'meeting.view'));
    $check('T04 (member) holds NO meeting.edit  ->  view != edit', !$holds('T04', 'meeting.edit'));
    $check('T02 (deacon) holds decision.edit', $holds('T02', 'decision.edit'));
    $check('T02 (deacon) holds NO decision.approve  ->  edit != approve',
        !$holds('T02', 'decision.approve'));
    $check('T01 (pastor) holds decision.approve (only the pastor may approve)',
        $holds('T01', 'decision.approve'));
    $check('T03 (group leader) holds issue.create but not decision.create',
        $holds('T03', 'issue.create') && !$holds('T03', 'decision.create'));
    $check('T01 holds meeting.export ONLY via the explicit grant',
        $holds('T01', 'meeting.export') && !$holds('T02', 'meeting.export')
        && !$holds('T03', 'meeting.export') && !$holds('T04', 'meeting.export'));
    $check('no fixture identity holds any manage key  ->  普通角色 != 管理角色',
        !$holds('T01', 'permission.manage') && !$holds('T01', 'role.manage')
        && !$holds('T02', 'role.manage') && !$holds('T03', 'role.manage') && !$holds('T04', 'role.manage'));
    $check('T04 holds write keys for nothing (最小权限)',
        $holds('T04', 'governance.view') && count(array_filter(
            $keysOf('T04'),
            static fn (string $k): bool => (bool) preg_match(
                '/\.(create|edit|submit|approve|publish|close|export|feedback|manage|end)$/',
                $k
            )
        )) === 0,
        'keys=' . count($keysOf('T04')));

    $say();
    $say('=== 2b. policy engine decisions (real users, real scoped rows) ===');

    $mainMeeting = $fixtureMeetings === [] ? null : $fixtureMeetings[0];
    $groupMeeting = null;
    $outsideMeeting = null;
    foreach ($fixtureMeetings as $m) {
        $b = $rows('SELECT name FROM gov_body WHERE id = :i', [':i' => (int) $m['body_id']]);
        $nm = $b === [] ? '' : (string) $b[0]['name'];
        if (str_contains($nm, '恩典小组')) {
            $groupMeeting = $m;
        } elseif (str_contains($nm, '范围外')) {
            $outsideMeeting = $m;
        }
    }
    $mainIssue = $fixtureIssues === [] ? null : $fixtureIssues[0];
    $mainDecision = $fixtureDecisions === [] ? null : $fixtureDecisions[0];

    // A. view vs edit
    $check('T04 (member) may VIEW a church meeting',
        $dec('T04', 'view', 'meeting', $mainMeeting)->allowed);
    $check('T04 (member) may NOT EDIT a church meeting',
        !$dec('T04', 'edit', 'meeting', $mainMeeting)->allowed);
    $check('T04 rationale is the missing permission',
        $dec('T04', 'edit', 'meeting', $mainMeeting)->source === 'permission');

    // B. view vs export  (registry proof is in 2a; this is defence in depth)
    $check('T02 (deacon) may VIEW a church meeting',
        $dec('T02', 'view', 'meeting', $mainMeeting)->allowed);
    $check('T02 (deacon) may NOT EXPORT (view != export)',
        !$dec('T02', 'export', 'meeting')->allowed);
    $r = $dec('T01', 'export', 'meeting');
    $check('T01 (pastor) export is still refused by the visibility layer',
        !$r->allowed, 'source=' . (string) $r->source);
    $check('...because the seeded model has no allow rule for the export action',
        $r->source === 'visibility', (string) $r->source);

    // C. edit vs approve  (registry proof is in 2a)
    $r = $dec('T02', 'edit', 'decision', $mainDecision);
    $check('T02 (deacon) edit is refused by the visibility layer',
        !$r->allowed, 'source=' . (string) $r->source);
    $check('T02 (deacon) may NOT APPROVE a decision (edit != approve)',
        !$dec('T02', 'approve', 'decision', $mainDecision)->allowed);
    $r = $dec('T01', 'approve', 'decision', $mainDecision);
    $check('T01 (pastor) approve is refused by the visibility layer too',
        !$r->allowed, 'source=' . (string) $r->source);

    // D. explicit deny outranks role grant
    $r = $dec('T02', 'create', 'meeting', null);
    $check('T02 explicit DENY beats the role grant on meeting.create', !$r->allowed);
    $check('T02 denial source is the explicit deny, not the registry',
        $r->source === 'explicit-deny', (string) $r->source);
    $r = $dec('T03', 'close', 'task', $t04Tasks === [] ? null : $t04Tasks[0]);
    $check('T03 explicit DENY beats the role grant on task.close', !$r->allowed);
    $check('T03 denial source is the explicit deny', $r->source === 'explicit-deny', (string) $r->source);

    // E. ordinary role != management role
    foreach (['T01', 'T02', 'T03', 'T04'] as $code) {
        $check("{$code} can NOT perform manage actions",
            !$dec($code, 'manage', 'role', $fixtureRoles === [] ? null : $fixtureRoles[0])->allowed);
    }

    // F. scope != global
    $check('T03 (group leader) is OUT of scope on a church-body meeting',
        !$dec('T03', 'view', 'meeting', $mainMeeting)->allowed);
    $check('T03 denial reason is scope, not permission',
        in_array($dec('T03', 'view', 'meeting', $mainMeeting)->source, ['scope'], true),
        (string) $dec('T03', 'view', 'meeting', $mainMeeting)->source);
    $check('T03 IS in scope on its own small-group meeting',
        $dec('T03', 'view', 'meeting', $groupMeeting)->allowed);
    $check('T03 is OUT of scope on the out-of-scope body meeting (ID guessing)',
        !$dec('T03', 'view', 'meeting', $outsideMeeting)->allowed);
    $check('T01 (church scope) IS in scope on the out-of-scope body meeting',
        $dec('T01', 'view', 'meeting', $outsideMeeting)->allowed);
    $check('T04 (member) is inside the congregational scope (Member scope)',
        $dec('T04', 'view', 'meeting', $mainMeeting)->allowed);
    $permProbe = $rows('SELECT * FROM gov_permission ORDER BY id LIMIT 1');
    $r = $dec('T04', 'view', 'permission', $permProbe === [] ? null : $permProbe[0]);
    $check('T04 is OUT of scope on the global-scope authorization registry', !$r->allowed);
    $check('...and the reason is scope, not permission', $r->source === 'scope', (string) $r->source);
    $check('T04 MAY see its own task (own record shortcut)',
        $dec('T04', 'view', 'task', $t04Tasks === [] ? null : $t04Tasks[0])->allowed);

    // G. P5 is never default-visible
    $p5Meeting = null;
    foreach ($fixtureMeetings as $m) {
        if (str_contains((string) $m['minutes'], 'P5-SECRET')) {
            $p5Meeting = $m;
        }
    }
    $check('a P5 probe meeting exists (secret minutes)', $p5Meeting !== null);
    foreach (['T01', 'T02', 'T03', 'T04'] as $code) {
        GovAuthorization::reset();
        \ChurchCRM\Plugins\MosGov\Security\VisibilityResolver::resetCache();
        $ctx = $ctxOf($code);
        $check("{$code} can NOT see information level P5 by default",
            $ctx === null || !\ChurchCRM\Plugins\MosGov\Security\VisibilityResolver::canSeeLevel($ctx, 'P5'));
    }
    if ($p5Meeting !== null) {
        GovAuthorization::reset();
        $masked = GovernancePolicy::filterFields($ctxOf('T01'), 'meeting', [$p5Meeting]);
        $check('P5 minutes are masked for a pastor without an explicit grant',
            $masked[0]['minutes'] === '__P5_PROTECTED__', (string) $masked[0]['minutes']);
    }

    // ------------------------------------------------------- HTTP probes
    $say();
    $say('=== 3. HTTP probes (real requests, real sessions) ===');

    $baseUrl = rtrim(getenv('MOSGOV_BASE_URL') ?: 'http://localhost', '/');
    $basePath = \ChurchCRM\dto\SystemURLs::getRootPath() . '/plugins/mos-gov';
    $tmpDir = sys_get_temp_dir() . '/mosgov-fixture-' . getmypid();
    @mkdir($tmpDir, 0777, true);

    $http = static function (string $url, string $jar, string $apiKey = '') use ($tmpDir): array {
        $ch = curl_init($url);
        $headers = ['Accept: text/html'];
        if ($apiKey !== '') {
            $headers[] = 'x-api-key: ' . $apiKey;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $jar,
            CURLOPT_COOKIEFILE => $jar,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => (string) $body];
    };

    foreach (FIXTURE_IDENTITIES as $code => $spec) {
        $key = $persons[$code]['api_key'];
        if ($key === '') {
            $check("{$code} has an API key for HTTP probes", false);
            continue;
        }
        $jar = $tmpDir . '/' . $code . '.cookies';
        $page = $http($baseUrl . $basePath . '/my-governance', $jar, $key);
        $check("{$code} /my-governance renders 200", $page['status'] === 200, (string) $page['status']);
        $check("{$code} /my-governance shows the answer chain (我是谁)",
            str_contains($page['body'], '我是谁'));
        $check("{$code} /my-governance shows its own display name",
            str_contains($page['body'], $spec['label']));
        $check("{$code} /my-governance has no raw English enum leak",
            !preg_match('/>(global|self|system|committee|inherited|manual_assignment)<\s*</', $page['body']));
    }

    // scope isolation over real HTTP (T03 out-of-scope meeting)
    if ($outsideMeeting !== null) {
        $r = $http($baseUrl . $basePath . '/meetings/' . (int) $outsideMeeting['id'],
            $tmpDir . '/T03.cookies', $persons['T03']['api_key']);
        $check('T03 HTTP request to an out-of-scope meeting is refused',
            in_array($r['status'], [403, 404], true), (string) $r['status']);
        $check('T03 out-of-scope response does not leak the title',
            !str_contains($r['body'], '范围外会议'));
    }
    if ($mainMeeting !== null) {
        $r = $http($baseUrl . $basePath . '/meetings/' . (int) $mainMeeting['id'],
            $tmpDir . '/T04.cookies', $persons['T04']['api_key']);
        $check('T04 (member scope) CAN read a church-scope meeting', $r['status'] === 200, (string) $r['status']);
        // the same page must not hand the member any protected content
        $check('T04 read does not leak the P5 minutes',
            !str_contains($r['body'], P5_SECRET_MEETING));
    }
    // T04 must not reach the global-scope authorization registry
    $r = $http($baseUrl . $basePath . '/permissions', $tmpDir . '/T04.cookies', $persons['T04']['api_key']);
    $check('T04 is refused on the global-scope permission registry',
        in_array($r['status'], [403, 404], true), (string) $r['status']);

    // P5 must never reach the page
    if ($p5Meeting !== null) {
        $r = $http($baseUrl . $basePath . '/meetings/' . (int) $p5Meeting['id'],
            $tmpDir . '/T01.cookies', $persons['T01']['api_key']);
        $check('P5 probe meeting page loads for the pastor (in scope)', $r['status'] === 200, (string) $r['status']);
        $check('P5 secret minutes never appear in the HTML',
            !str_contains($r['body'], P5_SECRET_MEETING));
        $check('P5 page shows the protection placeholder or notice',
            str_contains($r['body'], '__P5_PROTECTED__') || str_contains($r['body'], '受权限保护')
                || str_contains($r['body'], '受保护'));
    }
    if ($mainDecision !== null) {
        $r = $http($baseUrl . $basePath . '/decisions/' . (int) $mainDecision['id'],
            $tmpDir . '/T01.cookies', $persons['T01']['api_key']);
        $check('P5 secret decision text never appears in the HTML',
            !str_contains($r['body'], P5_SECRET_DECISION), 'status=' . $r['status']);
    }

    // search must not bypass scope, export must not bypass view.
    // The query string is echoed in the search box, so match the record URL
    // instead of the search term.
    $outsideId = $outsideMeeting === null ? 0 : (int) $outsideMeeting['id'];
    $r = $http($baseUrl . $basePath . '/search?q=' . urlencode('范围外会议'),
        $tmpDir . '/T03.cookies', $persons['T03']['api_key']);
    $check('T03 scoped search renders 200', $r['status'] === 200, (string) $r['status']);
    $check('T03 search does NOT return the out-of-scope record',
        $outsideId === 0 || !str_contains($r['body'], '/meetings/' . $outsideId),
        'record url /meetings/' . $outsideId);
    $r2 = $http($baseUrl . $basePath . '/search?q=' . urlencode('范围外会议'),
        $tmpDir . '/T01.cookies', $persons['T01']['api_key']);
    $check('T01 (church scope) DOES see the same record -> the filter is scope-driven, not blanket',
        $outsideId !== 0 && str_contains($r2['body'], '/meetings/' . $outsideId));

    $r = $http($baseUrl . $basePath . '/tasks/export', $tmpDir . '/T03.cookies', $persons['T03']['api_key']);
    $check('T03 export is refused (view != export)', $r['status'] === 403, (string) $r['status']);
    $r = $http($baseUrl . $basePath . '/tasks/export', $tmpDir . '/T04.cookies', $persons['T04']['api_key']);
    $check('T04 export is refused', $r['status'] === 403, (string) $r['status']);

    // anonymous probe
    $r = $http($baseUrl . $basePath . '/my-governance', $tmpDir . '/anon.cookies');
    $check('anonymous /my-governance is redirected to login', $r['status'] === 302, (string) $r['status']);

    // ------------------------------------------------------------- report
    $say();
    $pass = 0;
    $fail = 0;
    foreach ($results as $line) {
        $say($line);
        if (str_starts_with($line, '[PASS]')) {
            $pass++;
        } else {
            $fail++;
        }
    }
    $say();
    $say("result: {$pass} PASS, {$fail} FAIL");

    @file_put_contents(__DIR__ . '/fixture_v02.verify.txt', implode(PHP_EOL, $results) . PHP_EOL);
    GovAuthorization::reset();
    exit($fail === 0 ? 0 : 1);
}

fwrite(STDERR, "unknown mode '{$mode}'. use: status | reset | setup | clean | verify | all\n");
exit(2);
