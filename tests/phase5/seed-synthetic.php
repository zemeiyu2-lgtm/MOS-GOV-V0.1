<?php
/**
 * MOS-GOV Phase 5 — SYNTHETIC data generator for Test Church A / Test Church B.
 *
 * Writes REAL rows into the two REAL isolated MariaDB databases and REAL files
 * into two separate storage roots. No real church, member, donation or
 * governance data is read or imported anywhere in this file.
 *
 * Usage: php -c <ini> seed-synthetic.php
 */
declare(strict_types=1);
require_once __DIR__ . '/lib-chain.php';

$host = getenv('MOSGOV_P5_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('MOSGOV_P5_DB_PORT') ?: 3407);
$root = getenv('MOSGOV_P5_DB_USER') ?: 'root';
$rp   = getenv('MOSGOV_P5_DB_PASS') ?: '';

$churches = [
    'A' => [
        'db'      => getenv('MOSGOV_P5_DB_A'),
        'tenant'  => 'church-a',
        'key'     => getenv('MOSGOV_P5_HMAC_A'),
        'storage' => getenv('MOSGOV_P5_STORAGE_A'),
        'user'    => getenv('MOSGOV_P5_USER_A'),
        'pass'    => getenv('MOSGOV_P5_PASS_A'),
        'login'   => 'synthetic.admin.a',
        'secret'  => 'SYNTHETIC-LOGIN-A-2026',
    ],
    'B' => [
        'db'      => getenv('MOSGOV_P5_DB_B'),
        'tenant'  => 'church-b',
        'key'     => getenv('MOSGOV_P5_HMAC_B'),
        'storage' => getenv('MOSGOV_P5_STORAGE_B'),
        'user'    => getenv('MOSGOV_P5_USER_B'),
        'pass'    => getenv('MOSGOV_P5_PASS_B'),
        'login'   => 'synthetic.admin.b',
        'secret'  => 'SYNTHETIC-LOGIN-B-2026',
    ],
];

function conn(string $host, int $port, string $u, string $p, string $db): \mysqli
{
    $c = @new \mysqli($host, $u, $p, $db, $port);
    if ($c->connect_errno) { throw new \RuntimeException('CONNECT_FAILED: ' . $c->connect_error); }
    $c->set_charset('utf8mb4');
    return $c;
}
function ex(\mysqli $c, string $sql): void
{
    if (!mysqli_query($c, $sql)) {
        throw new \RuntimeException('SQL_FAILED: ' . mysqli_error($c) . ' | ' . preg_replace('/\s+/', ' ', $sql));
    }
}

foreach ($churches as $tag => $cfg) {
    echo "=== seeding SYNTHETIC Test Church {$tag} ({$cfg['db']}) ===\n";
    $c = conn($host, $port, $root, $rp, $cfg['db']);
    $t = $cfg['tenant'];

    // Clear only synthetic rows (fresh lab DBs anyway).
    foreach (['gov_evidence_log','gov_audit_log','gov_tenant_file','gov_decision',
              'gov_identity','gov_role','gov_body','gov_structure'] as $tbl) {
        ex($c, "DELETE FROM `$tbl`");
    }

    // Church structure / small groups / roles
    ex($c, "INSERT INTO gov_structure (id,name,code,description,status,sort_order)
            VALUES (1,'SYNTHETIC {$tag} 治理架构','STRUCT-{$t}','纯合成数据','active',1)");
    ex($c, "INSERT INTO gov_body (id,structure_id,name,body_type,description,status)
            VALUES (1,1,'SYNTHETIC {$tag} 长执会','board','纯合成数据','active'),
                   (2,1,'SYNTHETIC {$tag} 财务小组','team','纯合成数据','active')");
    ex($c, "INSERT INTO gov_role (id,body_id,name,role_code,description,status)
            VALUES (1,1,'SYNTHETIC {$tag} 主席','CHAIR-{$t}','纯合成','active'),
                   (2,1,'SYNTHETIC {$tag} 文书','CLERK-{$t}','纯合成','active')");

    // Members (person_id ranges are deliberately disjoint per church)
    $base = $tag === 'A' ? 1001 : 2001;
    for ($i = 0; $i < 3; $i++) {
        ex($c, "INSERT INTO gov_identity (person_id,identity_status,member_since,display_name_override,notes)
                VALUES (" . ($base + $i) . ",'active','2026-01-01',
                        'SYNTHETIC Member {$tag}-" . ($i + 1) . "','纯合成测试成员')");
    }

    // Governance records
    ex($c, "INSERT INTO gov_decision (id,title,decision_text,decision_status,decided_at)
            VALUES (1,'SYNTHETIC {$tag} 决议一','仅用于隔离测试的合成决议','approved','2026-02-01 10:00:00'),
                   (2,'SYNTHETIC {$tag} 决议二','仅用于隔离测试的合成决议','approved','2026-03-01 10:00:00')");

    // Audit evidence chain with THIS church's own HMAC key
    $events = [
        ['actor' => $cfg['login'], 'action' => 'create', 'object_type' => 'structure', 'object_id' => 1],
        ['actor' => $cfg['login'], 'action' => 'update', 'object_type' => 'decision',  'object_id' => 1],
        ['actor' => $cfg['login'], 'action' => 'export', 'object_type' => 'decision',  'object_id' => 2],
    ];
    $prev = str_repeat('0', 64);
    foreach ($events as $i => $e) {
        $row = [
            'tenant_id' => $t, 'actor' => $e['actor'],
            'occurred_at' => sprintf('2026-04-%02d 12:00:00', $i + 1),
            'object_type' => $e['object_type'], 'object_id' => $e['object_id'],
            'action' => $e['action'], 'result' => 'success',
            'authorization_basis' => $t . '.' . $e['object_type'] . '.' . $e['action'],
            'prev_hash' => $prev,
        ];
        $hash = p5_chain_hash($row, $cfg['key']);
        ex($c, "INSERT INTO gov_evidence_log
            (tenant_id,actor,occurred_at,object_type,object_id,action,authorization_basis,result,request_id,prev_hash,row_hash)
            VALUES ('{$t}','{$row['actor']}','{$row['occurred_at']}','{$row['object_type']}',{$row['object_id']},
                    '{$row['action']}','{$row['authorization_basis']}','success','p5-synthetic-{$i}',
                    '{$prev}','{$hash}')");
        $prev = $hash;
    }
    echo "  evidence rows: 3, chain head: " . substr($prev, 0, 16) . "...\n";

    // Real files in a church-private storage root (outside any web root)
    @mkdir($cfg['storage'], 0777, true);
    $files = [
        "minutes-{$tag}-2026-04.txt" => "SYNTHETIC {$t} meeting minutes — no real data.\n",
        "report-{$tag}-2026-04.txt"  => "SYNTHETIC {$t} governance report — no real data.\n",
    ];
    foreach ($files as $name => $body) {
        $path = rtrim($cfg['storage'], '/') . '/' . $name;
        file_put_contents($path, $body);
        $sha = hash_file('sha256', $path);
        ex($c, "INSERT INTO gov_tenant_file
            (tenant_id,object_key,original_name,mime_type,byte_size,sha256,uploaded_by,status)
            VALUES ('{$t}','{$name}','{$name}','text/plain'," . strlen($body) . ",'{$sha}','{$cfg['login']}','active')");
        echo "  file {$name} bytes=" . strlen($body) . " sha256=" . substr($sha, 0, 16) . "...\n";
    }

    // Synthetic login identity for the HTTP boundary test (lab-only, not a real account)
    @file_put_contents(rtrim($cfg['storage'], '/') . '/.p5-login.json', json_encode([
        'login' => $cfg['login'], 'secret' => $cfg['secret'], 'tenant' => $t,
    ]));

    mysqli_close($c);
}
echo "SEED_DONE\n";
