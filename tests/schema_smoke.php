<?php

declare(strict_types=1);

$sql = file_get_contents(__DIR__ . '/../database/001_initial.sql');

$tables = [
    'gov_structure',
    'gov_body',
    'gov_role',
    'gov_appointment',
    'gov_responsibility',
    'gov_relationship',
    'gov_meeting',
    'gov_issue',
    'gov_decision',
    'gov_task',
];

foreach ($tables as $table) {
    if (strpos($sql, 'CREATE TABLE IF NOT EXISTS ' . $table) === false) {
        fwrite(STDERR, "Missing table definition: {$table}\n");
        exit(1);
    }
}

echo "Schema smoke test passed: " . count($tables) . " governance tables found.\n";
