<?php
declare(strict_types=1);

require_once __DIR__ . '/poa.php';

function poa_layout_start(string $title, string $nav = 'home'): void
{
    $pageTitle = $title === '' ? 'PoA Explorer' : $title . ' · PoA Explorer';
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo poa_h($pageTitle); ?></title>
  <meta name="description" content="Read-only explorer for the Proof of Agent ledger. Look up checkpoints, transactions, and addresses. No wallet.">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;600&family=Syne:wght@600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/app.css">
</head>
<body>
  <header>
    <div>
      <a class="brand-lockup" href="index.php" aria-label="PoA Explorer home">
        <div>
          <div class="brand">PoA <span>Explorer</span></div>
          <div class="sub">Read-only against minepoa.com public JSON · no wallet</div>
          <div class="sub" id="live-tip" style="color:var(--accent);margin-top:0.25rem">connecting…</div>
        </div>
      </a>
    </div>
    <nav>
      <a href="index.php"<?php echo $nav === 'home' ? ' aria-current="page"' : ''; ?>>Tip</a>
      <a href="checkpoint.php"<?php echo $nav === 'checkpoint' ? ' aria-current="page"' : ''; ?>>Checkpoints</a>
      <a href="pending.php"<?php echo $nav === 'pending' ? ' aria-current="page"' : ''; ?>>Pending</a>
      <a href="reports.php"<?php echo $nav === 'reports' ? ' aria-current="page"' : ''; ?>>Reports</a>
      <a href="docs.php"<?php echo $nav === 'docs' ? ' aria-current="page"' : ''; ?>>Docs</a>
    </nav>
  </header>
  <div class="lab-banner" role="status">
    <strong>Read-only — no wallet, no value promised.</strong>
    Public Proof of Agent ledger. Experimental accounting. Nothing here is for sale.
  </div>
  <form class="search" action="search.php" method="get" role="search">
    <label for="q">Search</label>
    <input id="q" name="q" type="search" placeholder="Address, tx id, or height" value="<?php echo poa_h((string) ($_GET['q'] ?? '')); ?>" autocomplete="off">
    <button type="submit">Look up</button>
  </form>
  <main>
    <?php
}

function poa_layout_end(?array $meta = null): void
{
    $cache = $meta['_cache'] ?? null;
    $asOf = $meta['as_of'] ?? null;
    $src = $meta['source'] ?? null;
    ?>
  </main>
  <footer>
    <p>poa-explorer · patterned after laser-explorer · public JSON only · never /api/hosted · <a href="docs.php">docs</a> · code AGPLv3 · docs CC BY-SA 4.0</p>
    <?php if ($asOf || $src || $cache): ?>
      <p><?php
        $bits = [];
        if ($asOf) {
            $bits[] = 'as of ' . poa_h((string) $asOf);
        }
        if ($src) {
            $bits[] = poa_h((string) $src);
        }
        if ($cache) {
            $bits[] = 'cache ' . poa_h((string) $cache);
        }
        echo implode(' · ', $bits);
      ?></p>
    <?php endif; ?>
  </footer>
  <script>
  (async function () {
    const el = document.getElementById('live-tip');
    async function tick() {
      try {
        const r = await fetch('api/status.php', { cache: 'no-store' });
        const t = await r.json();
        const b = t.body || {};
        const h = (b.checkpoint && b.checkpoint.height) || b.checkpoint_height;
        if (el) {
          el.textContent = 'live checkpoint ' + h + (b.chain_id ? ' · ' + b.chain_id : '');
        }
      } catch (e) { /* ignore */ }
    }
    tick();
    setInterval(tick, 45000);
  })();
  </script>
</body>
</html>
    <?php
}

function poa_error_box(string $msg): void
{
    echo '<div class="notice error" role="alert">' . poa_h($msg) . '</div>';
}

function poa_stats(array $items): void
{
    echo '<div class="grid">';
    foreach ($items as $k => $v) {
        echo '<div class="stat"><div class="k">' . poa_h((string) $k) . '</div><div class="v">' . $v . '</div></div>';
    }
    echo '</div>';
}

function poa_panel_start(string $title, ?string $hint = null): void
{
    echo '<div class="panel">';
    if ($title !== '') {
        echo '<h2>' . poa_h($title) . '</h2>';
    }
    if ($hint) {
        echo '<p class="hint">' . poa_h($hint) . '</p>';
    }
}

function poa_panel_end(): void
{
    echo '</div>';
}

function poa_kv(array $rows): void
{
    echo '<dl class="kv">';
    foreach ($rows as $k => $v) {
        echo '<dt>' . poa_h((string) $k) . '</dt><dd>' . $v . '</dd>';
    }
    echo '</dl>';
}

function poa_tx_link(string $id): string
{
    if (!poa_is_tx_id($id)) {
        return poa_h($id);
    }
    return '<a class="mono" href="tx.php?id=' . rawurlencode($id) . '" title="' . poa_h($id) . '">' . poa_h(poa_short_id($id, 10)) . '</a>';
}

function poa_grades_label($grades, $count, $note): string
{
    if ($grades === null) {
        return $note ? (string) $note : 'no grades field on this row';
    }
    if (!is_array($grades) || $grades === []) {
        return '0 seals';
    }
    $n = is_numeric($count) ? (int) $count : count($grades);
    $bits = [];
    foreach ($grades as $g) {
        if (!is_array($g)) {
            continue;
        }
        $sc = array_key_exists('score', $g) ? (string) $g['score'] : '?';
        $ts = isset($g['ts']) ? poa_format_ts($g['ts']) : '';
        $bits[] = $sc . ($ts !== '' && $ts !== '—' ? ' @ ' . $ts : '');
    }
    return (string) $n . ' · ' . implode(', ', $bits);
}

function poa_addr_link(string $addr): string
{
    $addr = trim($addr);
    if ($addr === '' || $addr === 'null') {
        return '—';
    }
    if (!poa_is_address($addr)) {
        return poa_h($addr);
    }
    return '<a class="mono" href="address.php?a=' . rawurlencode($addr) . '" title="' . poa_h($addr) . '">' . poa_h(poa_short_id($addr, 8)) . '</a>';
}

function poa_tx_table(array $txs): void
{
    echo '<div class="table-wrap"><table class="txs"><thead><tr>';
    echo '<th>Transaction</th><th>Type</th><th>From</th><th>To</th><th>Amount</th><th>Epoch</th><th>Height</th><th>Finality</th><th>Time</th>';
    echo '</tr></thead><tbody>';
    if (!$txs) {
        echo '<tr><td colspan="9">No transactions on this page.</td></tr>';
    }
    foreach ($txs as $tx) {
        if (!is_array($tx)) {
            continue;
        }
        $id = (string) ($tx['id'] ?? '');
        $from = (string) ($tx['from_addr'] ?? $tx['from'] ?? '');
        $to = (string) ($tx['to_addr'] ?? $tx['to'] ?? '');
        echo '<tr>';
        echo '<td>' . poa_tx_link($id) . '</td>';
        echo '<td>' . poa_h((string) ($tx['type'] ?? '—')) . '</td>';
        echo '<td>' . poa_addr_link($from) . '</td>';
        echo '<td>' . poa_addr_link($to) . '</td>';
        echo '<td>' . poa_h(poa_format_amount($tx['amount'] ?? null)) . '</td>';
        echo '<td>' . poa_h($tx['epoch'] === null || $tx['epoch'] === '' ? '—' : (string) $tx['epoch']) . '</td>';
        echo '<td>' . poa_h($tx['height'] === null || $tx['height'] === '' ? '—' : (string) $tx['height']) . '</td>';
        echo '<td>' . poa_h(poa_finality_label($tx)) . '</td>';
        echo '<td>' . poa_h(poa_format_ts($tx['ts'] ?? null)) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}
