<?php

/**
 * MOS-GOV V0.1 — governance data layer test (all ten entities).
 *
 * Uses the full ChurchCRM bootstrap (same pattern as cli/timerjobs.php) and
 * exercises GovRepository plus the ChurchCRM person bridge against the real
 * database. Every row it creates is deleted again, so the suite is
 * idempotent and safe to run repeatedly.
 *
 * Coverage:
 *  1. registry / schema completeness (10 entities, 10 gov_* tables)
 *  2. ChurchCRM person lookup (read-only bridge)
 *  3. validation rules across every field type
 *  4. full CRUD lifecycle of the two governance loops
 *       Structure → Body → Role → Appointment → Responsibility
 *       Meeting → Issue → Decision → Task
 *     plus Relationship (Structure → Body)
 *  5. cross-entity relations and detail-page child collections
 *  6. dashboard counters, including open issue / open task totals
 *  7. cleanup back to the starting baseline
 *
 * Run: docker exec docker-webserver-1 php /var/www/html/plugins/community/mos-gov/tests/v01_data_test.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

require __DIR__ . '/../../../../Include/LoadConfigs.php';

use ChurchCRM\dto\SystemURLs;
use ChurchCRM\Plugins\MosGov\Data\GovDataException;
use ChurchCRM\Plugins\MosGov\Data\GovRepository;
use ChurchCRM\Plugins\MosGov\Integration\PersonLookup;
use ChurchCRM\Plugin\PluginManager;
use Propel\Runtime\Propel;

// Register the plugin autoloader (mos-gov is enabled, so loadPlugin() wires
// the ChurchCRM\Plugins\MosGov\ PSR-4 prefix used by the classes below).
PluginManager::init(SystemURLs::getDocumentRoot() . '/plugins');

$failures = 0;
$check = function (string $name, bool $ok, string $detail = '') use (&$failures): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . ($detail !== '' ? ' -- ' . $detail : '') . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
};
$section = static function (string $name): void {
    echo PHP_EOL . '=== ' . $name . ' ===' . PHP_EOL;
};

$repo = new GovRepository();
$conn = Propel::getConnection();

/** Merge a change set into a stored row so update() receives every field. */
$merged = static function (array $row, array $changes): array {
    return array_merge($row, $changes);
};

// ================================================================= 1. registry
$section('1. Registry and schema');

$expectedEntities = [
    'structure', 'body', 'role', 'appointment', 'responsibility',
    'relationship', 'meeting', 'issue', 'decision', 'task',
];
$check('registry lists all ten entities', GovRepository::entityKeys() === $expectedEntities, implode(',', GovRepository::entityKeys()));
$check('slug map covers all ten entities', count(GovRepository::SLUG_TO_ENTITY) === 10);
$check('navigation map labels plural', GovRepository::navigationMap()['responsibilities'] === 'Responsibilities');

$tableCheck = true;
$tableDetail = '';
foreach ($expectedEntities as $entity) {
    $cfg = GovRepository::ENTITIES[$entity];
    try {
        $conn->query('SELECT COUNT(*) FROM ' . $cfg['table'])->fetchColumn();
    } catch (\Throwable $e) {
        $tableCheck = false;
        $tableDetail .= $cfg['table'] . ' missing; ';
    }
}
$check('all ten gov_* tables readable', $tableCheck, $tableDetail);

$slugRoundTrip = true;
foreach ($expectedEntities as $entity) {
    if (GovRepository::entityForSlug(GovRepository::slugFor($entity)) !== $entity) {
        $slugRoundTrip = false;
    }
}
$check('slug/entity round trip', $slugRoundTrip);

// ==================================================== 2. ChurchCRM person bridge
$section('2. ChurchCRM person lookup (read-only bridge)');

$check('existing person resolves', PersonLookup::exists(1));
$check('unknown person does not resolve', !PersonLookup::exists(99999999));
$check('label of unknown person is explicit', str_contains(PersonLookup::label(99999999), 'not found'));
$label = PersonLookup::label(1);
$check('label of person #1 is non-empty and carries the id', $label !== '' && str_contains($label, '#1'), $label);
$candidates = PersonLookup::candidates();
$check('candidate list is populated from ChurchCRM', count($candidates) > 0, count($candidates) . ' candidates');
$check('candidate list excludes deactivated people', !array_key_exists(0, $candidates));

// ========================================================== 3. validation rules
$section('3. Validation');

$check('structure: name required', $repo->validate('structure', ['name' => '', 'status' => 'active']) !== []);
$check('structure: html rejected', $repo->validate('structure', ['name' => '<b>x</b>', 'status' => 'active']) !== []);
$check('structure: unknown status rejected', $repo->validate('structure', ['name' => 'X', 'status' => 'hacked']) !== []);
$check('structure: valid input accepted', $repo->validate('structure', ['name' => 'OK', 'status' => 'active']) === []);
$check('structure: non-existent parent rejected', $repo->validate('structure', ['name' => 'X', 'status' => 'active', 'parent_id' => 99999999]) !== []);

$check('body: structure_id required', $repo->validate('body', ['name' => 'X', 'status' => 'active']) !== []);
$check('role: body_id required', $repo->validate('role', ['name' => 'X', 'status' => 'active']) !== []);

$check('appointment: person required', $repo->validate('appointment', ['role_id' => 1, 'status' => 'active']) !== []);
$check('appointment: unknown person rejected', $repo->validate('appointment', ['role_id' => 1, 'person_id' => 99999999, 'status' => 'active']) !== []);
$check('appointment: bad date rejected', $repo->validate('appointment', ['role_id' => 1, 'person_id' => 1, 'status' => 'active', 'start_date' => '2026-13-45']) !== []);
$check('appointment: end before start rejected', $repo->validate('appointment', ['role_id' => 1, 'person_id' => 1, 'status' => 'active', 'start_date' => '2026-01-10', 'end_date' => '2026-01-01']) !== []);

$check('responsibility: priority whitelist enforced', $repo->validate('responsibility', ['title' => 'X', 'priority' => 'urgent', 'status' => 'active']) !== []);
$check('responsibility: valid priority accepted', $repo->validate('responsibility', ['title' => 'X', 'priority' => 'high', 'status' => 'active']) === []);

$check('relationship: bad from_type rejected', $repo->validate('relationship', ['from_type' => 'nope', 'from_id' => 1, 'relationship_type' => 'x', 'to_type' => 'body', 'to_id' => 1, 'status' => 'active']) !== []);
$check('relationship: valid endpoint types accepted', $repo->validate('relationship', ['from_type' => 'structure', 'from_id' => 1, 'relationship_type' => 'oversees', 'to_type' => 'body', 'to_id' => 1, 'status' => 'active']) === []);

// The body reference is intentionally bogus here, so the assertion checks the
// field under test rather than the (correctly reported) missing parent.
$meetingErrors = $repo->validate('meeting', ['body_id' => 1, 'title' => 'X', 'status' => 'held']);
$check('meeting: meeting status whitelist', !isset($meetingErrors['status']), json_encode($meetingErrors));
$check('meeting: unknown status rejected', isset($repo->validate('meeting', ['body_id' => 1, 'title' => 'X', 'status' => 'done'])['status']));
$check('meeting: malformed datetime rejected', isset($repo->validate('meeting', ['body_id' => 1, 'title' => 'X', 'status' => 'planned', 'meeting_date' => '2026-09-15 25:99'])['meeting_date']));
$meetingErrors = $repo->validate('meeting', ['body_id' => 1, 'title' => 'X', 'status' => 'planned', 'meeting_date' => '2026-09-15T14:30']);
$check('meeting: datetime-local format accepted', !isset($meetingErrors['meeting_date']), json_encode($meetingErrors));

$check('issue: issue status whitelist', $repo->validate('issue', ['title' => 'X', 'status' => 'in_progress', 'priority' => 'normal']) === []);
$check('issue: unknown status rejected', $repo->validate('issue', ['title' => 'X', 'status' => 'held', 'priority' => 'normal']) !== []);

$check('decision: decision_text required', $repo->validate('decision', ['title' => 'X', 'decision_status' => 'approved']) !== []);
$check('decision: unknown decision status rejected', $repo->validate('decision', ['title' => 'X', 'decision_text' => 'Y', 'decision_status' => 'maybe']) !== []);

$check('task: task status whitelist', $repo->validate('task', ['title' => 'X', 'status' => 'done', 'priority' => 'low']) === []);
$check('task: unknown status rejected', $repo->validate('task', ['title' => 'X', 'status' => 'finished', 'priority' => 'low']) !== []);

$check('normalize: datetime-local is canonicalised', $repo->normalize('meeting', ['meeting_date' => '2026-09-15T14:30'])['meeting_date'] === '2026-09-15 14:30:00');
$check('normalize: empty string becomes null for dates', $repo->normalize('appointment', ['start_date' => ''])['start_date'] === null);
$check('normalize: text is trimmed', $repo->normalize('structure', ['name' => '  padded  '])['name'] === 'padded');

// ============================================================= 4. CRUD lifecycle
$section('4. Governance loops (CRUD lifecycle)');

$before = $repo->getDashboardCounts();
echo 'baseline counts: ' . json_encode($before) . PHP_EOL;

$created = [
    'task' => [], 'decision' => [], 'issue' => [], 'meeting' => [],
    'relationship' => [], 'responsibility' => [], 'appointment' => [],
    'role' => [], 'body' => [], 'structure' => [],
];
$ids = [];
$error = null;

try {
    // ---- Structure → Body → Role → Appointment → Responsibility
    $ids['structure'] = $repo->insert('structure', [
        'name' => 'V01 Test Parish Council Structure', 'code' => 'V01S',
        'description' => 'data-layer test', 'status' => 'active', 'sort_order' => 5,
    ]);
    $created['structure'][] = $ids['structure'];
    $check('structure created', $ids['structure'] > 0, 'id=' . $ids['structure']);

    $row = $repo->find('structure', $ids['structure']);
    $check('structure read back', $row !== null && $row['code'] === 'V01S' && (int) $row['sort_order'] === 5);

    $ids['childStructure'] = $repo->insert('structure', [
        'name' => 'V01 Test Sub Structure', 'status' => 'active', 'parent_id' => $ids['structure'],
    ]);
    $created['structure'][] = $ids['childStructure'];
    $check('child structure linked to parent', $ids['childStructure'] > 0);

    $ids['body'] = $repo->insert('body', [
        'structure_id' => $ids['structure'], 'name' => 'V01 Test Standing Committee',
        'body_type' => 'committee', 'status' => 'active',
    ]);
    $created['body'][] = $ids['body'];
    $check('body created under structure', $ids['body'] > 0);

    $ids['role'] = $repo->insert('role', [
        'body_id' => $ids['body'], 'name' => 'V01 Test Chair', 'role_code' => 'V01R', 'status' => 'active',
    ]);
    $created['role'][] = $ids['role'];
    $check('role created under body', $ids['role'] > 0);

    $ids['appointment'] = $repo->insert('appointment', [
        'role_id' => $ids['role'], 'person_id' => 1, 'appointed_by_person_id' => 3,
        'start_date' => '2026-01-01', 'status' => 'active', 'notes' => 'test appointment',
    ]);
    $created['appointment'][] = $ids['appointment'];
    $check('appointment created for a ChurchCRM person', $ids['appointment'] > 0);

    $appointment = $repo->find('appointment', $ids['appointment']);
    $check('appointment keeps the ChurchCRM person id', (int) $appointment['person_id'] === 1);
    $check('appointment person resolves to a name', str_contains(PersonLookup::label(1), '#1'));

    $ids['responsibility'] = $repo->insert('responsibility', [
        'role_id' => $ids['role'], 'appointment_id' => $ids['appointment'],
        'title' => 'V01 Test Oversight of worship planning',
        'description' => 'test responsibility', 'priority' => 'high', 'status' => 'active',
    ]);
    $created['responsibility'][] = $ids['responsibility'];
    $check('responsibility created and linked to appointment', $ids['responsibility'] > 0);

    // ---- Relationship between two governance nodes
    $ids['relationship'] = $repo->insert('relationship', [
        'from_type' => 'structure', 'from_id' => $ids['structure'],
        'relationship_type' => 'oversees', 'to_type' => 'body', 'to_id' => $ids['body'],
        'description' => 'test relationship', 'status' => 'active',
    ]);
    $created['relationship'][] = $ids['relationship'];
    $check('relationship created', $ids['relationship'] > 0);

    // ---- Meeting → Issue → Decision → Task
    $ids['meeting'] = $repo->insert('meeting', [
        'body_id' => $ids['body'], 'title' => 'V01 Test Governance Meeting',
        'meeting_date' => '2026-09-10T19:00', 'location' => 'Parish Hall',
        'status' => 'planned', 'minutes' => 'test minutes',
    ]);
    $created['meeting'][] = $ids['meeting'];
    $check('meeting created (datetime-local normalised)', $ids['meeting'] > 0);

    $meeting = $repo->find('meeting', $ids['meeting']);
    $check('meeting date stored in canonical form', $meeting['meeting_date'] === '2026-09-10 19:00:00', (string) $meeting['meeting_date']);

    $ids['issue'] = $repo->insert('issue', [
        'body_id' => $ids['body'], 'meeting_id' => $ids['meeting'],
        'title' => 'V01 Test Issue: worship rota coverage',
        'description' => 'test issue', 'priority' => 'high', 'status' => 'open',
        'owner_person_id' => 3,
    ]);
    $created['issue'][] = $ids['issue'];
    $check('issue created against meeting + body', $ids['issue'] > 0);

    $issue = $repo->find('issue', $ids['issue']);
    $check('issue opened_at defaulted by the database', !empty($issue['opened_at']), (string) ($issue['opened_at'] ?? 'null'));

    $ids['decision'] = $repo->insert('decision', [
        'issue_id' => $ids['issue'], 'meeting_id' => $ids['meeting'],
        'title' => 'V01 Test Decision: extend rota',
        'decision_text' => 'Agreed to publish the rota four weeks in advance.',
        'decision_status' => 'approved', 'decided_at' => '2026-09-10T19:45',
        'decided_by_person_id' => 1, 'review_date' => '2027-03-01',
    ]);
    $created['decision'][] = $ids['decision'];
    $check('decision created against issue + meeting', $ids['decision'] > 0);

    $ids['task'] = $repo->insert('task', [
        'decision_id' => $ids['decision'], 'responsibility_id' => $ids['responsibility'],
        'title' => 'V01 Test Task: publish rota',
        'description' => 'test task', 'assignee_person_id' => 3,
        'due_date' => '2026-10-01', 'priority' => 'normal', 'status' => 'open',
    ]);
    $created['task'][] = $ids['task'];
    $check('task created against decision + responsibility', $ids['task'] > 0);

    // ---- invalid writes are rejected at the data layer
    try {
        $repo->insert('role', ['body_id' => 99999999, 'name' => 'Bad', 'status' => 'active']);
        $check('invalid insert rejected with per-field errors', false, 'no exception thrown');
    } catch (GovDataException $e) {
        $check('invalid insert rejected with per-field errors', isset($e->getErrors()['body_id']));
    }

    try {
        $repo->insert('meeting', ['body_id' => $ids['body'], 'title' => 'Bad', 'status' => 'nope']);
        $check('invalid enum rejected on insert', false, 'no exception thrown');
    } catch (GovDataException $e) {
        $check('invalid enum rejected on insert', isset($e->getErrors()['status']));
    }

    // ---- updates
    $repo->update('issue', $ids['issue'], $merged($repo->find('issue', $ids['issue']), [
        'status' => 'resolved', 'closed_at' => '2026-09-11T09:00',
    ]));
    $issue = $repo->find('issue', $ids['issue']);
    $check('issue resolved + closed_at stored', $issue['status'] === 'resolved' && $issue['closed_at'] === '2026-09-11 09:00:00');

    $repo->update('task', $ids['task'], $merged($repo->find('task', $ids['task']), [
        'status' => 'done', 'completed_at' => '2026-09-20T12:00',
    ]));
    $task = $repo->find('task', $ids['task']);
    $check('task completed', $task['status'] === 'done' && $task['completed_at'] === '2026-09-20 12:00:00');

    $repo->update('structure', $ids['structure'], $merged($repo->find('structure', $ids['structure']), [
        'name' => 'V01 Test Parish Council Structure (renamed)', 'status' => 'inactive',
    ]));
    $row = $repo->find('structure', $ids['structure']);
    $check('structure updated', $row['name'] === 'V01 Test Parish Council Structure (renamed)' && $row['status'] === 'inactive');

    try {
        $repo->update('structure', $ids['structure'], $merged($repo->find('structure', $ids['structure']), ['status' => 'bogus']));
        $check('invalid update rejected', false, 'no exception thrown');
    } catch (GovDataException $e) {
        $check('invalid update rejected', isset($e->getErrors()['status']));
    }

    // ---- closed loops verified from both ends
    $decisionsOfIssue = $repo->listWhere('decision', ['issue_id' => $ids['issue']], 50);
    $check('issue → decisions loop', count($decisionsOfIssue) === 1 && (int) $decisionsOfIssue[0]['id'] === $ids['decision']);

    $tasksOfDecision = $repo->listWhere('task', ['decision_id' => $ids['decision']], 50);
    $check('decision → tasks loop', count($tasksOfDecision) === 1 && (int) $tasksOfDecision[0]['id'] === $ids['task']);

    $bodiesOfStructure = $repo->listWhere('body', ['structure_id' => $ids['structure']], 50);
    $check('structure → bodies loop', count($bodiesOfStructure) === 1);

    $rolesOfBody = $repo->listWhere('role', ['body_id' => $ids['body']], 50);
    $check('body → roles loop', count($rolesOfBody) === 1);

    $appointmentsOfRole = $repo->listWhere('appointment', ['role_id' => $ids['role']], 50);
    $check('role → appointments loop', count($appointmentsOfRole) === 1);

    $responsibilitiesOfAppointment = $repo->listWhere('responsibility', ['appointment_id' => $ids['appointment']], 50);
    $check('appointment → responsibilities loop', count($responsibilitiesOfAppointment) === 1);

    $issuesOfMeeting = $repo->listWhere('issue', ['meeting_id' => $ids['meeting']], 50);
    $check('meeting → issues loop', count($issuesOfMeeting) === 1);

    $decisionsOfMeeting = $repo->listWhere('decision', ['meeting_id' => $ids['meeting']], 50);
    $check('meeting → decisions loop', count($decisionsOfMeeting) === 1);

    $tasksOfResponsibility = $repo->listWhere('task', ['responsibility_id' => $ids['responsibility']], 50);
    $check('responsibility → tasks loop', count($tasksOfResponsibility) === 1);

    $childStructures = $repo->listWhere('structure', ['parent_id' => $ids['structure']], 50);
    $check('structure → child structures loop', count($childStructures) === 1);

    $check('countWhere matches listWhere', $repo->countWhere('task', 'decision_id', $ids['decision']) === 1);

    // ---- registry 'related' configuration is valid for every entity
    $relatedOk = true;
    $relatedDetail = '';
    foreach ($expectedEntities as $entity) {
        foreach (GovRepository::ENTITIES[$entity]['related'] ?? [] as $rel) {
            if (!in_array($rel['entity'], $expectedEntities, true)
                || !isset(GovRepository::ENTITIES[$rel['entity']]['fields'][$rel['field']])) {
                $relatedOk = false;
                $relatedDetail .= $entity . '->' . $rel['entity'] . '.' . $rel['field'] . '; ';
            }
        }
    }
    $check('every configured relation points at a real entity field', $relatedOk, $relatedDetail);

    // ---- ordering helpers
    $recentMeetings = $repo->recent('meeting', 5);
    $check('recent meetings returns the created meeting', in_array($ids['meeting'], array_map(fn ($r) => (int) $r['id'], $recentMeetings), true));
    $recentDecisions = $repo->recent('decision', 5);
    $check('recent decisions returns the created decision', in_array($ids['decision'], array_map(fn ($r) => (int) $r['id'], $recentDecisions), true));

    // ---- counters
    $after = $repo->getDashboardCounts();
    $check(
        'counters increased for every created entity',
        $after['structures'] === $before['structures'] + 2
        && $after['bodies'] === $before['bodies'] + 1
        && $after['roles'] === $before['roles'] + 1
        && $after['appointments'] === $before['appointments'] + 1
        && $after['responsibilities'] === $before['responsibilities'] + 1
        && $after['relationships'] === $before['relationships'] + 1
        && $after['meetings'] === $before['meetings'] + 1
        && $after['issues'] === $before['issues'] + 1
        && $after['decisions'] === $before['decisions'] + 1
        && $after['tasks'] === $before['tasks'] + 1,
        'after=' . json_encode($after)
    );
    $check(
        'open counters track the open status only',
        $after['open_issues'] === $before['open_issues']
        && $after['open_tasks'] === $before['open_tasks'],
        'open_issues=' . $after['open_issues'] . ' open_tasks=' . $after['open_tasks']
    );

    // ---- guard rails on dynamic SQL fragments
    try {
        $repo->listWhere('task', ['not_a_column' => 1]);
        $check('unknown filter column rejected', false, 'no exception thrown');
    } catch (GovDataException $e) {
        $check('unknown filter column rejected', true);
    }
    try {
        $repo->list('structure', 10, ['name' => 'SIDEWAYS']);
        $check('unknown sort direction rejected', false, 'no exception thrown');
    } catch (GovDataException $e) {
        $check('unknown sort direction rejected', true);
    }
} catch (\Throwable $e) {
    $check('lifecycle completed without unexpected error', false, get_class($e) . ': ' . $e->getMessage());
    $error = $e;
}

// ================================================================== 5. cleanup
$section('5. Cleanup');

foreach ($created as $entity => $entityIds) {
    foreach ($entityIds as $id) {
        try {
            $repo->delete($entity, (int) $id);
        } catch (\Throwable $e) {
            $check('cleanup ' . $entity . '#' . $id, false, $e->getMessage());
        }
    }
}

$final = $repo->getDashboardCounts();
$check(
    'cleanup restored the baseline',
    $final === $before,
    'final=' . json_encode($final)
);

echo PHP_EOL . ($failures === 0 ? 'V0.1 DATA LAYER: ALL TESTS PASSED' : "V0.1 DATA LAYER: {$failures} FAILURES") . PHP_EOL;
exit($failures === 0 ? 0 : 1);
