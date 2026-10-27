<?php
declare(strict_types=1);

/**
 * Reports HTML windowing. Numbers come from the cron snapshot.
 * Do not fetch origin. Do not invent missing cells.
 */

function poa_closes_chrono(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        if (is_array($row) && array_key_exists('epoch', $row)) {
            $out[] = $row;
        }
    }
    usort($out, static function ($a, $b) {
        return ((int) ($a['epoch'] ?? 0)) <=> ((int) ($b['epoch'] ?? 0));
    });
    return $out;
}

function poa_reports_window(array $chrono, int $limit = POA_REPORTS_WINDOW): array
{
    if ($limit < 1) {
        $limit = POA_REPORTS_WINDOW;
    }
    $n = count($chrono);
    if ($n <= $limit) {
        return $chrono;
    }
    return array_values(array_slice($chrono, $n - $limit));
}

function poa_reports_page(array $newestFirst, int $from, int $limit = POA_REPORTS_WINDOW): array
{
    $from = max(0, $from);
    return array_values(array_slice($newestFirst, $from, $limit));
}

function poa_int_or_null($v): ?int
{
    if ($v === null || $v === '') {
        return null;
    }
    if (!is_numeric($v)) {
        return null;
    }
    return (int) $v;
}

function poa_delta(?int $now, ?int $prev): ?int
{
    if ($now === null || $prev === null) {
        return null;
    }
    return $now - $prev;
}

function poa_delta_label(?int $d): string
{
    if ($d === null) {
        return '—';
    }
    if ($d > 0) {
        return '+' . $d;
    }
    return (string) $d;
}

function poa_panel_rate(?array $row): ?float
{
    if (!is_array($row)) {
        return null;
    }
    $panel = is_array($row['panel'] ?? null) ? $row['panel'] : [];
    $pending = poa_int_or_null($panel['pending'] ?? ($row['still_pending'] ?? null));
    $with = poa_int_or_null($panel['with_panel'] ?? null);
    if ($pending === null || $pending <= 0 || $with === null) {
        return null;
    }
    return $with / $pending;
}

function poa_rate_label(?float $rate): string
{
    if ($rate === null) {
        return '—';
    }
    return sprintf('%.1f%%', $rate * 100);
}

function poa_find_close(array $chrono, int $epoch): ?array
{
    foreach ($chrono as $row) {
        if ((int) ($row['epoch'] ?? -1) === $epoch) {
            return $row;
        }
    }
    return null;
}

function poa_latest_epoch(array $chrono): ?int
{
    if (!$chrono) {
        return null;
    }
    $last = $chrono[count($chrono) - 1];
    return poa_int_or_null($last['epoch'] ?? null);
}

function poa_headline_from_closes(array $chrono): array
{
    $n = count($chrono);
    $cur = $n ? $chrono[$n - 1] : null;
    $prev = $n >= 2 ? $chrono[$n - 2] : null;
    $settled = is_array($cur) ? poa_int_or_null($cur['settled'] ?? null) : null;
    $pending = is_array($cur) ? poa_int_or_null($cur['still_pending'] ?? null) : null;
    $est = is_array($cur) ? poa_int_or_null($cur['established_after'] ?? null) : null;
    $rate = poa_panel_rate(is_array($cur) ? $cur : null);
    $prevSettled = is_array($prev) ? poa_int_or_null($prev['settled'] ?? null) : null;
    $prevPending = is_array($prev) ? poa_int_or_null($prev['still_pending'] ?? null) : null;
    $prevEst = is_array($prev) ? poa_int_or_null($prev['established_after'] ?? null) : null;
    $prevRate = poa_panel_rate(is_array($prev) ? $prev : null);
    $rateDelta = ($rate !== null && $prevRate !== null) ? ($rate - $prevRate) : null;
    return [
        'epoch' => is_array($cur) ? poa_int_or_null($cur['epoch'] ?? null) : null,
        'settled' => $settled,
        'settled_delta' => poa_delta($settled, $prevSettled),
        'still_pending' => $pending,
        'still_pending_delta' => poa_delta($pending, $prevPending),
        'panel_rate' => $rate,
        'panel_rate_delta' => $rateDelta,
        'established' => $est,
        'established_delta' => poa_delta($est, $prevEst),
        'prior_epoch' => is_array($prev) ? poa_int_or_null($prev['epoch'] ?? null) : null,
    ];
}

function poa_svg_dual_line(array $labels, array $left, array $right, string $leftName, string $rightName): string
{
    $w = 640;
    $h = 220;
    $pl = 52;
    $pr = 52;
    $pt = 20;
    $pb = 36;
    $n = count($labels);
    if ($n < 1) {
        return '<p class="hint">No close points to chart.</p>';
    }
    $leftMax = 1;
    $rightMax = 1;
    foreach ($left as $v) {
        if (is_numeric($v) && (float) $v > $leftMax) {
            $leftMax = (float) $v;
        }
    }
    foreach ($right as $v) {
        if (is_numeric($v) && (float) $v > $rightMax) {
            $rightMax = (float) $v;
        }
    }
    $innerW = $w - $pl - $pr;
    $innerH = $h - $pt - $pb;
    $xAt = static function (int $i) use ($n, $pl, $innerW): float {
        if ($n <= 1) {
            return $pl + $innerW / 2;
        }
        return $pl + ($innerW * $i / ($n - 1));
    };
    $yLeft = static function ($v) use ($pt, $innerH, $leftMax): ?float {
        if (!is_numeric($v)) {
            return null;
        }
        return $pt + $innerH * (1 - ((float) $v / $leftMax));
    };
    $yRight = static function ($v) use ($pt, $innerH, $rightMax): ?float {
        if (!is_numeric($v)) {
            return null;
        }
        return $pt + $innerH * (1 - ((float) $v / $rightMax));
    };
    $poly = static function (array $vals, callable $yAt, int $n, callable $xAt): string {
        $pts = [];
        for ($i = 0; $i < $n; $i++) {
            $y = $yAt($vals[$i] ?? null);
            if ($y === null) {
                continue;
            }
            $pts[] = sprintf('%.1f,%.1f', $xAt($i), $y);
        }
        return implode(' ', $pts);
    };
    $leftPts = $poly($left, $yLeft, $n, $xAt);
    $rightPts = $poly($right, $yRight, $n, $xAt);
    $last = $labels[$n - 1] ?? '';
    $first = $labels[0] ?? '';
    $svg = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="'
        . poa_h($leftName . ' and ' . $rightName . ' over closes') . '">';
    $svg .= '<rect class="chart-bg" x="0" y="0" width="' . $w . '" height="' . $h . '"/>';
    if ($leftPts !== '') {
        $svg .= '<polyline class="chart-line chart-line-a" fill="none" points="' . poa_h($leftPts) . '"/>';
    }
    if ($rightName !== '' && $rightPts !== '') {
        $svg .= '<polyline class="chart-line chart-line-b" fill="none" points="' . poa_h($rightPts) . '"/>';
    }
    $svg .= '<text class="chart-axis" x="' . $pl . '" y="' . ($h - 10) . '">' . poa_h((string) $first) . '</text>';
    $svg .= '<text class="chart-axis" x="' . ($w - $pr) . '" y="' . ($h - 10) . '" text-anchor="end">' . poa_h((string) $last) . '</text>';
    $svg .= '<text class="chart-axis chart-axis-a" x="8" y="' . ($pt + 8) . '">' . poa_h($leftName) . '</text>';
    if ($rightName !== '') {
        $svg .= '<text class="chart-axis chart-axis-b" x="' . ($w - 8) . '" y="' . ($pt + 8) . '" text-anchor="end">' . poa_h($rightName) . '</text>';
    }
    $svg .= '</svg>';
    return $svg;
}

function poa_svg_single_line(array $labels, array $values, string $name): string
{
    $none = array_fill(0, count($labels), null);
    return poa_svg_dual_line($labels, $values, $none, $name, '');
}

function poa_heatmap_still_pending(array $window): array
{
    $closes = [];
    $births = [];
    $cells = [];
    $max = 0;
    foreach ($window as $row) {
        if (!is_array($row)) {
            continue;
        }
        $ep = poa_int_or_null($row['epoch'] ?? null);
        if ($ep === null) {
            continue;
        }
        $closes[] = $ep;
        foreach ((array) ($row['cohorts'] ?? []) as $co) {
            if (!is_array($co) || !array_key_exists('birth_epoch', $co)) {
                continue;
            }
            $b = $co['birth_epoch'];
            $key = $b === null ? 'unknown' : (string) (int) $b;
            if ($b !== null) {
                $births[(int) $b] = true;
            } else {
                $births['unknown'] = true;
            }
            if (!array_key_exists('still_pending', $co) || $co['still_pending'] === null || $co['still_pending'] === '') {
                continue;
            }
            if (!is_numeric($co['still_pending'])) {
                continue;
            }
            $v = (int) $co['still_pending'];
            $cells[$key][$ep] = $v;
            if ($v > $max) {
                $max = $v;
            }
        }
    }
    $birthList = [];
    foreach (array_keys($births) as $k) {
        if ($k !== 'unknown') {
            $birthList[] = (int) $k;
        }
    }
    sort($birthList, SORT_NUMERIC);
    if (isset($births['unknown'])) {
        $birthList[] = 'unknown';
    }
    return [
        'closes' => $closes,
        'births' => $birthList,
        'cells' => $cells,
        'max' => $max,
    ];
}

function poa_heat_fill(int $v, int $max): string
{
    if ($max <= 0 || $v <= 0) {
        return 'transparent';
    }
    $t = $v / $max;
    $a = 0.12 + (0.78 * $t);
    return 'rgba(61, 214, 198, ' . sprintf('%.3f', $a) . ')';
}
