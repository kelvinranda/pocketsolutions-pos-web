<?php
/**
 * Phase 9.5 — Historical orphan branch data policy & soft integrity.
 *
 * Classification (stored strings / linked records):
 *   1 VALID_CURRENT         — exact trim match in branchies; consistent with modern rules
 *   2 HISTORICAL            — non-blank, not in today's registry; may be historically valid
 *   3 MISSING               — NULL / blank; no reliable ownership
 *   4 PROVABLE_INCONSISTENCY — linked evidence establishes intended registered branch
 *   5 UNRESOLVED_CONFLICT   — linked records disagree; intended branch not established
 *
 * Soft integrity (application-layer):
 *   - NEW writes must use registered branchies names (trim + exact match).
 *   - Historical orphans / MISSING / conflicts are preserved; never auto-rewritten.
 *   - Do not infer branch from product name or from the caller's current branch.
 *   - Do not treat historical names as invalid merely because absent from today's registry.
 *   - No FK / UNIQUE on branchies.branch_name until separately approved after clean data.
 *   - Optional non-unique INDEX on branchies.branch_name for registry lookups only.
 *
 * Remediation: only when classification is PROVABLE_INCONSISTENCY with reliable evidence,
 * authorised business decision, and reversible impact — Phase 9.5 performs none by default.
 */

require_once __DIR__ . '/branch_access.php';

/** Classification class constants (string codes for reports / APIs). */
const PS_BRANCH_CLASS_VALID = 'VALID_CURRENT';
const PS_BRANCH_CLASS_HISTORICAL = 'HISTORICAL';
const PS_BRANCH_CLASS_MISSING = 'MISSING';
const PS_BRANCH_CLASS_PROVABLE = 'PROVABLE_INCONSISTENCY';
const PS_BRANCH_CLASS_UNRESOLVED = 'UNRESOLVED_CONFLICT';

/**
 * Ensure soft integrity support objects (non-unique lookup index only).
 * Does not add UNIQUE/FK. Does not rewrite historical rows.
 */
function psBranchIntegrityEnsureSchema(mysqli $conn): bool
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $idx = mysqli_query($conn, "SHOW INDEX FROM branchies WHERE Key_name = 'idx_branchies_branch_name'");
    if ($idx && mysqli_num_rows($idx) > 0) {
        return true;
    }
    $ok = mysqli_query(
        $conn,
        'ALTER TABLE branchies ADD INDEX idx_branchies_branch_name (branch_name)'
    );
    if ($ok || mysqli_errno($conn) === 1061) {
        return true;
    }
    error_log('branch_integrity: registry lookup index was not added');
    return false;
}

/**
 * Classify a single branch name against the live registry.
 * Does not invent ownership for MISSING; historical ≠ invalid.
 */
function psBranchClassifyName(mysqli $conn, $raw): string
{
    $name = psBranchNormalizeName($raw);
    if ($name === '') {
        return PS_BRANCH_CLASS_MISSING;
    }
    if (psBranchIsRegistered($conn, $name)) {
        return PS_BRANCH_CLASS_VALID;
    }
    return PS_BRANCH_CLASS_HISTORICAL;
}

/**
 * Remediation policy text for a classification group (documentation / UI).
 */
function psBranchIntegrityPolicyForClass(string $class): string
{
    switch ($class) {
        case PS_BRANCH_CLASS_VALID:
            return 'Preserve. Use as working/write branch when authorised. No remediation.';
        case PS_BRANCH_CLASS_HISTORICAL:
            return 'Preserve as historical. Do not treat as invalid solely because absent from today\'s branchies. '
                . 'Do not auto-assign to a current branch. Remap only with proven ownership + approved decision (not Phase 9.5 auto).';
        case PS_BRANCH_CLASS_MISSING:
            return 'Preserve. No reliable ownership — do not infer from product name or caller\'s current branch. '
                . 'Operational paths must reject NEW use of blank branch. Closure/assignment requires business decision.';
        case PS_BRANCH_CLASS_PROVABLE:
            return 'Eligible for controlled remediation only when linked evidence is unambiguous, impact is safe/reversible, '
                . 'and an authorised business decision is recorded. Otherwise leave unchanged.';
        case PS_BRANCH_CLASS_UNRESOLVED:
            return 'Leave unchanged. Document conflict. Do not rewrite headers/lines/payments/stock/invoice refs. Requires authorised business decision (not auto-remediated in Phase 9.6).';
        default:
            return 'Leave unchanged pending review.';
    }
}

/**
 * Phase 9.6 — assess whether UNIQUE(branch_name) / FKs are safe to add.
 * Does not mutate schema. Returns blockers with counts.
 *
 * @return array{unique_safe:bool,fk_safe:bool,blockers:array<int,string>,notes:array<int,string>}
 */
function psBranchIntegrityConstraintAssessment(mysqli $conn): array
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $blockers = [];
    $notes = [];

    $dup = mysqli_query(
        $conn,
        'SELECT TRIM(branch_name) AS n, COUNT(*) AS c FROM branchies GROUP BY TRIM(branch_name) HAVING c > 1'
    );
    $dupCount = 0;
    if ($dup) {
        while ($row = mysqli_fetch_assoc($dup)) {
            $dupCount += (int)$row['c'];
            $blockers[] = 'Duplicate branchies name after trim: ' . (string)$row['n'] . ' (rows=' . (int)$row['c'] . ')';
        }
    }

    // Orphan / historical strings in key tables prevent FK to branchies without cleanup.
    $tables = [
        ['online_sales_ref', 'branch'],
        ['online_sales', 'branch'],
        ['users', 'branch'],
        ['sessions', 'branch'],
        ['goods', 'branch'],
        ['movements_receipts', 'branch'],
        ['goods_receipts_ref', 'branch'],
        ['goods_receipts_ref', 'sending_branch'],
    ];
    foreach ($tables as [$table, $col]) {
        $table = preg_replace('/[^A-Za-z0-9_]/', '', $table);
        $col = preg_replace('/[^A-Za-z0-9_]/', '', $col);
        $exists = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $table) . "'");
        if (!$exists || mysqli_num_rows($exists) < 1) {
            continue;
        }
        $cols = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$col'");
        if (!$cols || mysqli_num_rows($cols) < 1) {
            continue;
        }
        $sql = "SELECT COUNT(*) AS c FROM `$table` t
                WHERE TRIM(IFNULL(t.`$col`, '')) <> ''
                  AND NOT EXISTS (
                      SELECT 1 FROM branchies b WHERE TRIM(b.branch_name) = TRIM(t.`$col`)
                  )";
        $r = mysqli_query($conn, $sql);
        if ($r && ($row = mysqli_fetch_assoc($r)) && (int)$row['c'] > 0) {
            $blockers[] = "$table.$col has " . (int)$row['c'] . ' non-blank values not in branchies (FK unsafe)';
        }
        $blank = mysqli_query(
            $conn,
            "SELECT COUNT(*) AS c FROM `$table` WHERE `$col` IS NULL OR TRIM(`$col`) = ''"
        );
        if ($blank && ($brow = mysqli_fetch_assoc($blank)) && (int)$brow['c'] > 0) {
            $notes[] = "$table.$col has " . (int)$brow['c'] . ' NULL/blank rows (FK would need nullable or cleanup)';
        }
    }

    $idx = mysqli_query($conn, "SHOW INDEX FROM branchies WHERE Key_name = 'idx_branchies_branch_name'");
    if ($idx && mysqli_num_rows($idx) > 0) {
        $notes[] = 'idx_branchies_branch_name present (non-unique)';
    } else {
        $blockers[] = 'idx_branchies_branch_name missing';
    }

    $uniqueSafe = $dupCount === 0 && $blockers === [];
    // UNIQUE alone could be safe if no duplicate names even when FKs are not.
    $uniqueOnlySafe = $dupCount === 0;
    if ($uniqueOnlySafe && !$uniqueSafe) {
        $notes[] = 'UNIQUE(branch_name) has no duplicate-name blocker, but deferred while orphan strings remain (avoid implying FK readiness)';
    }

    return [
        'unique_safe' => false, // deferred: orphans remain; do not add UNIQUE until business cleanup
        'unique_name_duplicates' => $dupCount,
        'fk_safe' => false,
        'blockers' => $blockers,
        'notes' => $notes,
    ];
}

/**
 * Phase 9.6 — classify all current sale line↔header mismatches (read-only).
 *
 * @return array{total_distinct:int,provable:int,unresolved:int,samples:array<int,array>}
 */
function psBranchIntegrityMismatchReview(mysqli $conn): array
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $out = [
        'total_distinct' => 0,
        'provable' => 0,
        'unresolved' => 0,
        'samples' => [],
    ];
    $sql = "SELECT l.id AS line_id, l.invoice_number, l.branch AS line_branch, r.branch AS header_branch
            FROM online_sales l
            INNER JOIN online_sales_ref r ON r.invoice_number = l.invoice_number
            WHERE IFNULL(TRIM(l.branch), '') <> IFNULL(TRIM(r.branch), '')
            GROUP BY l.id, l.invoice_number, l.branch, r.branch
            ORDER BY l.id ASC
            LIMIT 50";
    $res = mysqli_query($conn, $sql);
    if (!$res) {
        return $out;
    }
    $seen = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $lineId = (int)$row['line_id'];
        if (isset($seen[$lineId])) {
            continue;
        }
        $seen[$lineId] = true;
        $out['total_distinct']++;
        $class = psBranchClassifySaleLineHeaderPair(
            $conn,
            (string)($row['line_branch'] ?? ''),
            (string)($row['header_branch'] ?? ''),
            (string)($row['invoice_number'] ?? '')
        );
        if ($class['class'] === PS_BRANCH_CLASS_PROVABLE) {
            $out['provable']++;
        } else {
            $out['unresolved']++;
        }
        if (count($out['samples']) < 15) {
            $out['samples'][] = [
                'line_id' => $lineId,
                'invoice' => (string)$row['invoice_number'],
                'line_branch' => (string)$row['line_branch'],
                'header_branch' => (string)$row['header_branch'],
                'class' => $class['class'],
                'reason' => $class['reason'],
            ];
        }
    }
    return $out;
}

/**
 * Soft gate for NEW write paths: branch must be non-blank and registered (trim + exact).
 *
 * @return array{ok:bool,branch?:string,error?:string}
 */
function psBranchRequireRegisteredWrite(mysqli $conn, $raw): array
{
    $branch = psBranchNormalizeName($raw);
    if ($branch === '') {
        return ['ok' => false, 'error' => 'A registered branch is required.'];
    }
    if (!psBranchIsRegistered($conn, $branch)) {
        return ['ok' => false, 'error' => 'That branch is not available.'];
    }
    return ['ok' => true, 'branch' => $branch];
}

/**
 * Count helpers — read-only diagnostics (never mutate).
 *
 * @return array<string,mixed>
 */
function psBranchIntegritySnapshot(mysqli $conn): array
{
    mysqli_report(MYSQLI_REPORT_OFF);
    psBranchIntegrityEnsureSchema($conn);

    $registered = psBranchRegisteredNames($conn);
    $regSet = array_fill_keys($registered, true);

    $classifyKey = static function ($raw) use ($regSet): string {
        $n = trim((string)$raw);
        if ($n === '') {
            return PS_BRANCH_CLASS_MISSING;
        }
        return isset($regSet[$n]) ? PS_BRANCH_CLASS_VALID : PS_BRANCH_CLASS_HISTORICAL;
    };

    $groupTable = static function (mysqli $conn, string $table, string $col) use ($classifyKey): array {
        $table = preg_replace('/[^A-Za-z0-9_]/', '', $table);
        $col = preg_replace('/[^A-Za-z0-9_]/', '', $col);
        $exists = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $table) . "'");
        if (!$exists || mysqli_num_rows($exists) < 1) {
            return ['absent' => true];
        }
        $cols = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$col'");
        if (!$cols || mysqli_num_rows($cols) < 1) {
            return ['absent_column' => true];
        }
        $res = mysqli_query(
            $conn,
            "SELECT
                CASE WHEN `$col` IS NULL THEN '' ELSE TRIM(`$col`) END AS bkey,
                COUNT(*) AS c
             FROM `$table`
             GROUP BY bkey
             ORDER BY c DESC"
        );
        $out = [
            'total' => 0,
            'by_name' => [],
            'by_class' => [
                PS_BRANCH_CLASS_VALID => 0,
                PS_BRANCH_CLASS_HISTORICAL => 0,
                PS_BRANCH_CLASS_MISSING => 0,
            ],
        ];
        if (!$res) {
            error_log('branch_integrity: group failed for ' . $table);
            return $out;
        }
        while ($row = mysqli_fetch_assoc($res)) {
            $key = (string)($row['bkey'] ?? '');
            $c = (int)$row['c'];
            $class = $classifyKey($key);
            $label = $key === '' ? '(blank/NULL)' : $key;
            $out['by_name'][] = ['branch' => $label, 'count' => $c, 'class' => $class];
            $out['by_class'][$class] = ($out['by_class'][$class] ?? 0) + $c;
            $out['total'] += $c;
        }
        return $out;
    };

    $seq = null;
    $seqRes = mysqli_query($conn, 'SELECT next_number FROM sale_invoice_seq WHERE id = 1 LIMIT 1');
    if ($seqRes && ($sr = mysqli_fetch_assoc($seqRes))) {
        $seq = (int)$sr['next_number'];
    }

    $maxInv = 0;
    $maxRes = mysqli_query(
        $conn,
        "SELECT IFNULL(MAX(CAST(invoice_number AS UNSIGNED)), 0) AS mx
         FROM online_sales_ref
         WHERE invoice_number REGEXP '^[0-9]{1,9}$'"
    );
    if ($maxRes && ($mr = mysqli_fetch_assoc($maxRes))) {
        $maxInv = (int)$mr['mx'];
    }

    $gaps = [];
    if ($seq !== null && $seq > $maxInv + 1) {
        $gaps[] = [
            'from' => $maxInv + 1,
            'to' => $seq - 1,
            'count' => ($seq - 1) - ($maxInv + 1) + 1,
            'note' => 'Allocated next_number ahead of max committed invoice; numbers in range have no online_sales_ref row',
        ];
    }

    // Line↔header mismatches: distinct line ids (duplicate invoice headers inflate joins).
    $mismatchDistinct = 0;
    $mismatchJoinRows = 0;
    $mm = mysqli_query(
        $conn,
        "SELECT COUNT(DISTINCT l.id) AS distinct_lines, COUNT(*) AS join_rows
         FROM online_sales l
         INNER JOIN online_sales_ref r ON r.invoice_number = l.invoice_number
         WHERE IFNULL(TRIM(l.branch), '') <> IFNULL(TRIM(r.branch), '')"
    );
    if ($mm && ($mrow = mysqli_fetch_assoc($mm))) {
        $mismatchDistinct = (int)$mrow['distinct_lines'];
        $mismatchJoinRows = (int)$mrow['join_rows'];
    }

    $dupHeaders = 0;
    $dupRes = mysqli_query(
        $conn,
        'SELECT COUNT(*) AS c FROM (
            SELECT invoice_number FROM online_sales_ref GROUP BY invoice_number HAVING COUNT(*) > 1
         ) t'
    );
    if ($dupRes && ($drow = mysqli_fetch_assoc($dupRes))) {
        $dupHeaders = (int)$drow['c'];
    }

    $nullOpen = [];
    $so = mysqli_query(
        $conn,
        "SELECT id, cashier_id, cashier_email, status, branch, opened_at, register_id, opening_float
         FROM sessions
         WHERE status = 'OPEN' AND (branch IS NULL OR TRIM(branch) = '')
         ORDER BY id"
    );
    if ($so) {
        while ($row = mysqli_fetch_assoc($so)) {
            $nullOpen[] = $row;
        }
    }

    $usersOutside = 0;
    $uRes = mysqli_query($conn, 'SELECT branch, COUNT(*) AS c FROM users GROUP BY branch');
    $userBranchGroups = [];
    if ($uRes) {
        while ($row = mysqli_fetch_assoc($uRes)) {
            $class = $classifyKey($row['branch'] ?? '');
            $c = (int)$row['c'];
            $label = trim((string)($row['branch'] ?? ''));
            if ($label === '') {
                $label = '(blank/NULL)';
            }
            $userBranchGroups[] = ['branch' => $label, 'count' => $c, 'class' => $class];
            if ($class !== PS_BRANCH_CLASS_VALID) {
                $usersOutside += $c;
            }
        }
    }

    $goodsCnt = 0;
    $gRes = mysqli_query($conn, 'SELECT COUNT(*) AS c, IFNULL(SUM(quantity),0) AS qty, IFNULL(SUM(re_order_level),0) AS rol FROM goods');
    $goodsQty = '0';
    $goodsRol = '0';
    if ($gRes && ($grow = mysqli_fetch_assoc($gRes))) {
        $goodsCnt = (int)$grow['c'];
        $goodsQty = (string)$grow['qty'];
        $goodsRol = (string)$grow['rol'];
    }

    $uniq = false;
    $uIdx = mysqli_query($conn, "SHOW INDEX FROM goods WHERE Key_name = 'uniq_goods_product_branch'");
    if ($uIdx && mysqli_num_rows($uIdx) > 0) {
        $uniq = true;
    }

    $idxBranchies = false;
    $bIdx = mysqli_query($conn, "SHOW INDEX FROM branchies WHERE Key_name = 'idx_branchies_branch_name'");
    if ($bIdx && mysqli_num_rows($bIdx) > 0) {
        $idxBranchies = true;
    }

    $countSimple = static function (mysqli $conn, string $table): ?int {
        $table = preg_replace('/[^A-Za-z0-9_]/', '', $table);
        $exists = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $table) . "'");
        if (!$exists || mysqli_num_rows($exists) < 1) {
            return null;
        }
        $r = mysqli_query($conn, "SELECT COUNT(*) AS c FROM `$table`");
        if (!$r || !($row = mysqli_fetch_assoc($r))) {
            return null;
        }
        return (int)$row['c'];
    };

    return [
        'registered' => $registered,
        'sale_invoice_seq' => $seq,
        'max_committed_invoice' => $maxInv,
        'invoice_gaps' => $gaps,
        'online_sales_ref' => $groupTable($conn, 'online_sales_ref', 'branch'),
        'online_sales' => $groupTable($conn, 'online_sales', 'branch'),
        'goods' => $groupTable($conn, 'goods', 'branch'),
        'sessions' => $groupTable($conn, 'sessions', 'branch'),
        'users' => [
            'total' => $countSimple($conn, 'users'),
            'outside_registry' => $usersOutside,
            'by_name' => $userBranchGroups,
        ],
        'mismatch_distinct_lines' => $mismatchDistinct,
        'mismatch_join_rows' => $mismatchJoinRows,
        'duplicate_header_invoice_numbers' => $dupHeaders,
        'null_open_sessions' => $nullOpen,
        'null_open_count' => count($nullOpen),
        'movements_receipts' => $groupTable($conn, 'movements_receipts', 'branch'),
        'goods_receipts_ref' => $groupTable($conn, 'goods_receipts_ref', 'branch'),
        'goods_receipts_ref_sending' => $groupTable($conn, 'goods_receipts_ref', 'sending_branch'),
        'stock_take_adj' => [
            'count' => $countSimple($conn, 'stock_take_adj'),
            'note' => 'No branch column — incomplete association (legacy product/record_name only)',
        ],
        'fingerprints' => [
            'goods' => $goodsCnt,
            'goods_qty_sum' => $goodsQty,
            'goods_rol_sum' => $goodsRol,
            'uniq_goods_product_branch' => $uniq,
            'idx_branchies_branch_name' => $idxBranchies,
            'stock_movements' => $countSimple($conn, 'stock_movements'),
            'online_sales' => $countSimple($conn, 'online_sales'),
            'online_sales_ref' => $countSimple($conn, 'online_sales_ref'),
            'sessions' => $countSimple($conn, 'sessions'),
            'users' => $countSimple($conn, 'users'),
            'cash_movements' => $countSimple($conn, 'cash_movements'),
            'sale_payments' => $countSimple($conn, 'sale_payments'),
            'sale_invoice_seq' => $seq,
            'branchies' => count($registered),
        ],
        'policy' => [
            PS_BRANCH_CLASS_VALID => psBranchIntegrityPolicyForClass(PS_BRANCH_CLASS_VALID),
            PS_BRANCH_CLASS_HISTORICAL => psBranchIntegrityPolicyForClass(PS_BRANCH_CLASS_HISTORICAL),
            PS_BRANCH_CLASS_MISSING => psBranchIntegrityPolicyForClass(PS_BRANCH_CLASS_MISSING),
            PS_BRANCH_CLASS_PROVABLE => psBranchIntegrityPolicyForClass(PS_BRANCH_CLASS_PROVABLE),
            PS_BRANCH_CLASS_UNRESOLVED => psBranchIntegrityPolicyForClass(PS_BRANCH_CLASS_UNRESOLVED),
        ],
    ];
}

/**
 * Classify sale line↔header pairs for an invoice into PROVABLE vs UNRESOLVED.
 * Does not rewrite. Product-name inference is never used.
 *
 * @return array{class:string,reason:string,invoice:string}
 */
function psBranchClassifySaleLineHeaderPair(mysqli $conn, string $lineBranch, string $headerBranch, string $invoice): array
{
    $lineBranch = psBranchNormalizeName($lineBranch);
    $headerBranch = psBranchNormalizeName($headerBranch);
    $invoice = trim($invoice);

    if ($lineBranch === $headerBranch) {
        return [
            'class' => psBranchClassifyName($conn, $lineBranch),
            'reason' => 'Line and header agree',
            'invoice' => $invoice,
        ];
    }

    // Duplicate invoice_number headers mean a join alone cannot prove one intended branch.
    $dup = false;
    $stmt = mysqli_prepare($conn, 'SELECT COUNT(*) AS c FROM online_sales_ref WHERE invoice_number = ?');
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 's', $invoice);
        if (mysqli_stmt_execute($stmt)) {
            $res = mysqli_stmt_get_result($stmt);
            $row = $res ? mysqli_fetch_assoc($res) : null;
            $dup = $row && (int)$row['c'] > 1;
        }
        mysqli_stmt_close($stmt);
    }

    if ($dup) {
        return [
            'class' => PS_BRANCH_CLASS_UNRESOLVED,
            'reason' => 'Duplicate invoice_number headers; line↔header join is not unique ownership evidence',
            'invoice' => $invoice,
        ];
    }

    $lineClass = psBranchClassifyName($conn, $lineBranch);
    $headerClass = psBranchClassifyName($conn, $headerBranch);

    // One side VALID and the other MISSING — still not automatic proof (could be partial write).
    if ($lineClass === PS_BRANCH_CLASS_VALID && $headerClass === PS_BRANCH_CLASS_MISSING) {
        return [
            'class' => PS_BRANCH_CLASS_UNRESOLVED,
            'reason' => 'Header missing branch while line is registered — intended header branch unproven without business decision',
            'invoice' => $invoice,
        ];
    }
    if ($headerClass === PS_BRANCH_CLASS_VALID && $lineClass === PS_BRANCH_CLASS_MISSING) {
        return [
            'class' => PS_BRANCH_CLASS_UNRESOLVED,
            'reason' => 'Line missing branch while header is registered — intended line branch unproven without business decision',
            'invoice' => $invoice,
        ];
    }

    return [
        'class' => PS_BRANCH_CLASS_UNRESOLVED,
        'reason' => 'Line and header disagree; no unambiguous linked proof of a single intended branch',
        'invoice' => $invoice,
    ];
}
