<?php
declare(strict_types=1);

/**
 * Reports tab composers. Public close / status / tx / anchor JSON only.
 * Never hosted. Never invent locked vs spendable.
 */

function poa_reward_positive($amount): bool
{
    if ($amount === null || $amount === '') {
        return false;
    }
    if (is_numeric($amount)) {
        return (float) $amount > 0;
    }
    return false;
}

function poa_index_closes(?array $epochsBody): array
{
    $by = [];
    if (!is_array($epochsBody)) {
        return $by;
    }
    foreach ($epochsBody as $close) {
        if (!is_array($close) || !array_key_exists('epoch', $close)) {
            continue;
        }
        $by[(int) $close['epoch']] = $close;
    }
    ksort($by);
    return $by;
}

function poa_pending_birth_epoch(int $closeEpoch, $since): ?int
{
    if ($since === null || $since === '') {
        return null;
    }
    if (!is_numeric($since)) {
        return null;
    }
    return $closeEpoch - (int) $since;
}

function poa_settled_birth_epoch(string $answer, int $closeEpoch, array $closesByEpoch): ?int
{
    $prevN = $closeEpoch - 1;
    if (!isset($closesByEpoch[$prevN])) {
        return null;
    }
    $pending = $closesByEpoch[$prevN]['basis']['pending'] ?? [];
    if (!is_array($pending) || !isset($pending[$answer]) || !is_array($pending[$answer])) {
        return null;
    }
    return poa_pending_birth_epoch($prevN, $pending[$answer]['since'] ?? null);
}

function poa_cohort_key(?int $birth): string
{
    return $birth === null ? 'unknown' : (string) $birth;
}

function poa_empty_cohort(): array
{
    return [
        'passed' => 0,
        'failed' => 0,
        'settled' => 0,
        'still_pending' => 0,
    ];
}

function poa_close_panel_health(array $close): array
{
    $pending = is_array($close['basis']['pending'] ?? null) ? $close['basis']['pending'] : [];
    $with = 0;
    $without = 0;
    $withSeals = 0;
    $zeroSeals = 0;
    $missingGrades = 0;
    $sizeHist = [];
    $sinceHist = [];
    $nearExpiry = 0;
    $expiry = POA_EXPLORER_EPOCH_EXPIRY;
    foreach ($pending as $p) {
        if (!is_array($p)) {
            continue;
        }
        $panel = $p['panel'] ?? [];
        $size = is_array($panel) ? count($panel) : 0;
        $sizeHist[(string) $size] = ($sizeHist[(string) $size] ?? 0) + 1;
        if ($size === 0) {
            $without++;
        } else {
            $with++;
        }
        $grades = poa_pending_grades_field($p);
        if ($grades['grades'] === null) {
            $missingGrades++;
        } elseif ((int) $grades['grades_count'] === 0) {
            $zeroSeals++;
        } else {
            $withSeals++;
        }
        $since = $p['since'] ?? null;
        $sk = $since === null || $since === '' ? 'unknown' : (string) (int) $since;
        $sinceHist[$sk] = ($sinceHist[$sk] ?? 0) + 1;
        if (is_numeric($since) && ($expiry - (int) $since) <= 2) {
            $nearExpiry++;
        }
    }
    ksort($sizeHist, SORT_NUMERIC);
    return [
        'pending' => count($pending),
        'with_panel' => $with,
        'without_panel' => $without,
        'with_seals' => $withSeals,
        'zero_seals' => $zeroSeals,
        'missing_grades_field' => $missingGrades,
        'panel_size_histogram' => $sizeHist,
        'since_histogram' => $sinceHist,
        'expiry_epochs' => $expiry,
        'within_two_epochs_of_expiry' => $nearExpiry,
        'quorum_note' => 'quorum is not on public close pending rows',
    ];
}

function poa_close_settlement_row(array $close, array $closesByEpoch): array
{
    $n = (int) ($close['epoch'] ?? -1);
    $pending = is_array($close['basis']['pending'] ?? null) ? $close['basis']['pending'] : [];
    $settled = is_array($close['basis']['settled'] ?? null) ? $close['basis']['settled'] : [];
    $rewards = is_array($close['rewards'] ?? null) ? $close['rewards'] : [];
    $established = is_array($close['basis']['established_after'] ?? null) ? $close['basis']['established_after'] : [];
    $fp = is_array($close['basis']['final_payout']['capped'] ?? null) ? $close['basis']['final_payout']['capped'] : [];
    $attest = $close['basis']['attestations'] ?? null;

    $cohorts = [];
    $passed = 0;
    $failed = 0;
    $unknownSettled = 0;
    foreach ($settled as $s) {
        if (!is_array($s)) {
            continue;
        }
        $ok = !empty($s['passed']);
        if ($ok) {
            $passed++;
        } else {
            $failed++;
        }
        $aid = (string) ($s['answer'] ?? '');
        $birth = $aid !== '' ? poa_settled_birth_epoch($aid, $n, $closesByEpoch) : null;
        if ($birth === null) {
            $unknownSettled++;
        }
        $key = poa_cohort_key($birth);
        if (!isset($cohorts[$key])) {
            $cohorts[$key] = poa_empty_cohort() + ['birth_epoch' => $birth];
        }
        $cohorts[$key]['settled']++;
        if ($ok) {
            $cohorts[$key]['passed']++;
        } else {
            $cohorts[$key]['failed']++;
        }
    }

    foreach ($pending as $p) {
        if (!is_array($p)) {
            continue;
        }
        $birth = poa_pending_birth_epoch($n, $p['since'] ?? null);
        $key = poa_cohort_key($birth);
        if (!isset($cohorts[$key])) {
            $cohorts[$key] = poa_empty_cohort() + ['birth_epoch' => $birth];
        }
        $cohorts[$key]['still_pending']++;
    }

    uksort($cohorts, static function ($a, $b) {
        if ($a === 'unknown') {
            return 1;
        }
        if ($b === 'unknown') {
            return -1;
        }
        return ((int) $a) <=> ((int) $b);
    });

    $paid = 0;
    foreach ($rewards as $amt) {
        if (poa_reward_positive($amt)) {
            $paid++;
        }
    }

    $panel = poa_close_panel_health($close);
    $missingKeys = poa_close_keys_present($close);

    return [
        'epoch' => $n,
        'ts' => $close['ts'] ?? null,
        'source' => 'minepoa GET /api/epochs close epoch=' . $n . ' (same object as explorer /api/close.php?epoch=' . $n . ')',
        'birth_rule' => 'Still-pending: this close epoch minus pending.since. Settled: prior close pending.since for that answer id (prior_epoch − since). Unknown if the answer was not pending at the prior close. Not live challenge-index epoch — that index only holds current answers.',
        'sealed' => $panel['with_seals'],
        'settled' => count($settled),
        'passed' => $passed,
        'failed' => $failed,
        'paid' => $paid,
        'paid_note' => 'Addresses with rewards amount > 0 on this close. Not tx.amount (those fields are empty on the public tx feed).',
        'final_payout_capped_addresses' => is_array($fp) ? count($fp) : 0,
        'still_pending' => count($pending),
        'settled_birth_unknown' => $unknownSettled,
        'established_after' => count($established),
        'attestations' => is_array($attest) ? count($attest) : null,
        'cohorts' => array_values($cohorts),
        'panel' => $panel,
        'close_keys_missing' => $missingKeys,
    ];
}

function poa_reports_settlement(array $closesByEpoch): array
{
    $rows = [];
    foreach ($closesByEpoch as $close) {
        $rows[] = poa_close_settlement_row($close, $closesByEpoch);
    }
    return [
        'source' => 'minepoa GET /api/epochs basis.pending / basis.settled / rewards',
        'test_case' => 'Epoch 8 close should show settled=345 and birth-epoch 3 settled=0 (LabLeak thread 1489 / Copperwick).',
        'rows' => $rows,
    ];
}

function poa_reports_network(array $status, array $txs, array $anchors, array $closesByEpoch): array
{
    $heights = [];
    $prevTs = null;
    $gaps = [];
    foreach ($closesByEpoch as $close) {
        $ts = isset($close['ts']) ? (int) $close['ts'] : 0;
        if ($prevTs !== null && $ts > $prevTs) {
            $gaps[] = $ts - $prevTs;
        }
        if ($ts > 0) {
            $prevTs = $ts;
        }
        $heights[] = (int) ($close['epoch'] ?? 0);
    }
    $cadenceSec = null;
    if ($gaps) {
        $cadenceSec = (int) round(array_sum($gaps) / count($gaps));
    }

    $types = [];
    foreach ($txs as $tx) {
        if (!is_array($tx)) {
            continue;
        }
        $t = (string) ($tx['type'] ?? 'unknown');
        $types[$t] = ($types[$t] ?? 0) + 1;
    }

    $lastBase = null;
    $lastSol = null;
    foreach ($anchors as $a) {
        if (!is_array($a)) {
            continue;
        }
        $chain = strtolower((string) ($a['chain'] ?? ''));
        if ($lastBase === null && str_contains($chain, 'base')) {
            $lastBase = $a;
        }
        if ($lastSol === null && str_contains($chain, 'solana')) {
            $lastSol = $a;
        }
    }

    $latest = null;
    if ($closesByEpoch) {
        $latest = $closesByEpoch[array_key_last($closesByEpoch)];
    }
    $attest = is_array($latest) ? ($latest['basis']['attestations'] ?? null) : null;

    return [
        'source' => [
            'status' => 'minepoa GET /api/status',
            'epochs' => 'minepoa GET /api/epochs',
            'txs' => 'minepoa GET /api/explorer/txs (recent feed only, capped)',
            'anchors' => 'minepoa GET /api/anchors',
        ],
        'checkpoint_height' => $status['checkpoint']['height'] ?? $status['checkpoint_height'] ?? null,
        'chain_id' => $status['chain_id'] ?? $status['network'] ?? null,
        'epochs_closed' => $status['epochs_closed'] ?? count($closesByEpoch),
        'status_tx_count' => $status['txs'] ?? null,
        'holders' => $status['holders'] ?? null,
        'stale' => $status['stale'] ?? null,
        'epoch_cadence_seconds_mean' => $cadenceSec,
        'epoch_cadence_hours_mean' => $cadenceSec !== null ? round($cadenceSec / 3600, 2) : null,
        'closes' => count($closesByEpoch),
        'recent_tx_types' => $types,
        'recent_tx_note' => 'Type counts are the recent explorer tx feed, not an all-time ledger tally.',
        'attestations_last_close' => is_array($attest) ? count($attest) : null,
        'last_base_anchor' => $lastBase,
        'last_solana_anchor' => $lastSol,
    ];
}

function poa_reports_agreement(array $closesByEpoch): array
{
    $rows = [];
    $drift = 0;
    foreach ($closesByEpoch as $close) {
        $missing = poa_close_keys_present($close);
        if ($missing) {
            $drift++;
        }
        $rows[] = [
            'epoch' => $close['epoch'] ?? null,
            'ts' => $close['ts'] ?? null,
            'keys_missing' => $missing,
            'ok' => $missing === [],
        ];
    }
    return [
        'source' => 'explorer close objects vs expected minepoa /api/epochs keys (' . implode(', ', POA_CLOSE_RECORD_KEYS) . ')',
        'note' => 'This is a schema/presence check on the public close records the explorer already mirrors (task 5389). It does not invent fields.',
        'closes_checked' => count($rows),
        'closes_with_missing_keys' => $drift,
        'rows' => $rows,
    ];
}

function poa_reports_from_public(array $epochsBody, array $status, array $txs, array $anchors): array
{
    $closes = poa_index_closes($epochsBody);
    $settlement = poa_reports_settlement($closes);
    $latestPanel = null;
    $latestN = $closes ? (int) array_key_last($closes) : null;
    if ($latestN !== null) {
        $latestPanel = poa_close_panel_health($closes[$latestN]);
        $latestPanel['as_of_epoch'] = $latestN;
        $latestPanel['as_of_ts'] = $closes[$latestN]['ts'] ?? null;
        $latestPanel['source'] = 'last-close basis.pending on GET /api/epochs — snapshot, not a live jury';
    }
    return [
        'bounds' => [
            'public_data_only' => true,
            'no_hosted' => true,
            'no_wallet' => true,
            'no_locked_spendable_split' => true,
            'no_forecasts' => true,
        ],
        'network' => poa_reports_network($status, $txs, $anchors, $closes),
        'settlement' => $settlement,
        'grading_backlog' => $latestPanel,
        'panel_by_close' => array_map(static function ($row) {
            return [
                'epoch' => $row['epoch'],
                'ts' => $row['ts'],
                'panel' => $row['panel'],
            ];
        }, $settlement['rows']),
        'payouts' => array_map(static function ($row) {
            return [
                'epoch' => $row['epoch'],
                'ts' => $row['ts'],
                'established_after' => $row['established_after'],
                'paid' => $row['paid'],
                'final_payout_capped_addresses' => $row['final_payout_capped_addresses'],
                'paid_note' => $row['paid_note'],
            ];
        }, $settlement['rows']),
        'close_agreement' => poa_reports_agreement($closes),
    ];
}

function poa_reports_row(): array
{
    $derived = poa_derived_row();
    $body = poa_derived_reports_body();
    return [
        '_error' => $derived['_error'] ?? null,
        '_cache' => $derived['_cache'] ?? 'miss',
        '_path' => '/api/epochs',
        '_status' => $derived['_status'] ?? 0,
        'body' => is_array($body) ? $body : [],
    ];
}
