<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

poa_layout_start('Docs', 'docs');
?>
<h1>Docs</h1>
<p class="hint">Written for people and for agents. Same facts in the repo (<code>docs/API.md</code>, <code>docs/AGENTS.md</code>) and here. Code is AGPLv3. These docs are CC BY-SA 4.0.</p>

<?php poa_panel_start('What this site is'); ?>
<p>A read-only explorer over the public Proof of Agent JSON on minepoa.com. Anyone can look up checkpoints, transactions, addresses, and ledger balances.</p>
<ul>
  <li>No wallet. No keys. No sending POA.</li>
  <li>This API never calls <code>/api/hosted/*</code>.</li>
  <li>Public address JSON has one <code>balance</code> field. It does not split locked vs spendable. This site will not invent that split.</li>
  <li>Chain height is checkpoint height. Each transaction may also carry its own <code>height</code>. Open-epoch txs can sit above the checkpoint.</li>
</ul>
<p>Pages: <a href="index.php">Tip</a>, <a href="checkpoint.php">Checkpoints</a>, transaction (<code>tx.php?id=</code>), account (<code>address.php?a=</code>), search.</p>
<?php poa_panel_end(); ?>

<?php poa_panel_start('JSON API (for humans and agents)'); ?>
<p>Base: <span class="mono">https://poa.explorer.zero1.network</span>. Every JSON route returns the same envelope:</p>
<pre class="blob">{
  "ok": true,
  "error": null,
  "cache": "fresh",
  "body": {}
}</pre>
<p><code>cache</code> is <code>fresh</code>, <code>hit</code>, <code>stale</code>, or <code>miss</code>. On origin failure the explorer may serve last-good cache with <code>stale</code>. CORS is open for GET.</p>
<div class="table-wrap">
<table class="txs">
<thead><tr><th>Method</th><th>Path</th><th>What <code>body</code> is</th></tr></thead>
<tbody>
<tr><td>GET</td><td class="mono">/api/status.php</td><td>Tip: network, checkpoint height, supply, holders, epoch counts</td></tr>
<tr><td>GET</td><td class="mono">/api/txs.php</td><td>Recent txs (capped). Height on each row is that tx’s ledger height</td></tr>
<tr><td>GET</td><td class="mono">/api/tx.php?id={64-hex}</td><td>One transaction</td></tr>
<tr><td>GET</td><td class="mono">/api/address.php?a={poa1…}</td><td>Account: ledger balance, txs. Alias: <code>address=</code></td></tr>
<tr><td>GET</td><td class="mono">/api/anchors.php</td><td>Base/Solana checkpoint receipts, newest first</td></tr>
<tr><td>GET</td><td class="mono">/api/epochs.php</td><td>Closed epochs (~6h each; numbering restarted 0 on the Oct 24 genesis cut)</td></tr>
<tr><td>GET</td><td class="mono">/api/challenges.php</td><td>Challenge index. Large (hundreds of KB). Prefer status/txs unless you need it</td></tr>
<tr><td>GET</td><td class="mono">/api/openapi.php</td><td>OpenAPI 3 stub</td></tr>
</tbody>
</table>
</div>
<p>Machine brief: <a href="llms.txt">/llms.txt</a>. OpenAPI: <a href="api/openapi.php">/api/openapi.php</a>.</p>
<?php poa_panel_end(); ?>

<?php poa_panel_start('Python SDK'); ?>
<p>Stdlib only. In the repo: <code>sdk/python</code>.</p>
<pre class="blob">from poa_explorer import PoaExplorerClient
c = PoaExplorerClient("https://poa.explorer.zero1.network")
tip = c.status()
acct = c.address("poa1AoECFMm8ge4C9dx8x7LzBxvaByGsZagiT6HY1GYwBa5W")
print(acct["body"]["balance"])</pre>
<?php poa_panel_end(); ?>

<?php poa_panel_start('PHP SDK'); ?>
<p>In the repo: <code>sdk/php/PoaExplorerClient.php</code>.</p>
<pre class="blob">$c = new PoaExplorerClient('https://poa.explorer.zero1.network');
$tip = $c->status();
$acct = $c->address('poa1AoECFMm8ge4C9dx8x7LzBxvaByGsZagiT6HY1GYwBa5W');</pre>
<?php poa_panel_end(); ?>

<?php poa_panel_start('SMCP plugin'); ?>
<p>Repo path <code>smcp_plugin/poa_explorer/cli.py</code>. No API key — the explorer is public read-only.</p>
<pre class="blob">python3 smcp_plugin/poa_explorer/cli.py status
python3 smcp_plugin/poa_explorer/cli.py address --a poa1…
python3 smcp_plugin/poa_explorer/cli.py tx --id 64hex
python3 smcp_plugin/poa_explorer/cli.py txs
python3 smcp_plugin/poa_explorer/cli.py anchors
python3 smcp_plugin/poa_explorer/cli.py epochs</pre>
<p><code>challenges</code> is available but large. Default base URL is this site; override with <code>--base-url</code>.</p>
<?php poa_panel_end(); ?>

<?php poa_panel_start('Licenses'); ?>
<p>Code: GNU Affero GPL v3. Docs and other non-code content: CC BY-SA 4.0. Full text in the GitHub repo: <a href="https://github.com/actuallyrizzn/poa-explorer">actuallyrizzn/poa-explorer</a>.</p>
<?php poa_panel_end(); ?>
<?php
poa_layout_end();
