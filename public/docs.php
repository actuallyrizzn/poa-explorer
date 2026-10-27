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
<p>Pages: <a href="index.php">Tip</a>, <a href="checkpoint.php">Checkpoints</a>, <a href="pending.php">Pending</a>, <a href="reports.php">Reports</a>, transaction (<code>tx.php?id=</code>), account (<code>address.php?a=</code>), search.</p>
<?php poa_panel_end(); ?>

<?php poa_panel_start('JSON API (for humans and agents)'); ?>
<p>Base: <span class="mono">https://poa.explorer.zero1.network</span>. Every JSON route returns the same envelope:</p>
<pre class="blob">{
  "ok": true,
  "error": null,
  "cache": "fresh",
  "version": "0.4.0",
  "body": {}
}</pre>
<p><code>version</code> is the explorer API version. Changes are additive. Changelog: <a href="api/changelog.php"><code>/api/changelog.php</code></a>. <code>cache</code> is <code>fresh</code>, <code>hit</code>, <code>stale</code>, or <code>miss</code>. On origin failure the explorer may serve last-good cache with <code>stale</code>. CORS is open for GET.</p>
<div class="table-wrap">
<table class="txs">
<thead><tr><th>Method</th><th>Path</th><th>What <code>body</code> is</th></tr></thead>
<tbody>
<tr><td>GET</td><td class="mono">/api/status.php</td><td>Tip: network, checkpoint height, supply, holders, epoch counts</td></tr>
<tr><td>GET</td><td class="mono">/api/txs.php</td><td>Recent txs (capped). Height on each row is that tx’s ledger height</td></tr>
<tr><td>GET</td><td class="mono">/api/tx.php?id={64-hex}</td><td>One transaction</td></tr>
<tr><td>GET</td><td class="mono">/api/address.php?a={poa1…}</td><td>Account: ledger balance, txs. Alias: <code>address=</code></td></tr>
<tr><td>GET</td><td class="mono">/api/anchors.php</td><td>Base/Solana checkpoint receipts, newest first</td></tr>
<tr><td>GET</td><td class="mono">/api/epochs.php</td><td>Closed epochs. Same field names and timestamps as minepoa <code>/api/epochs</code></td></tr>
<tr><td>GET</td><td class="mono">/api/close.php?epoch={n}</td><td>One close object from that list, same keys</td></tr>
<tr><td>GET</td><td class="mono">/api/pending.php</td><td>Last-close pending snapshot (panel size, age, seals when present). Optional <code>a=</code></td></tr>
<tr><td>GET</td><td class="mono">/api/reports.php</td><td>Network reports (settlement by birth cohort, backlog, stats). Public data only</td></tr>
<tr><td>GET</td><td class="mono">/api/changelog.php</td><td>API changelog</td></tr>
<tr><td>GET</td><td class="mono">/api/challenges.php</td><td>Challenge index. Large (hundreds of KB). Prefer status/txs unless you need it</td></tr>
<tr><td>GET</td><td class="mono">/api/openapi.php</td><td>OpenAPI 3 stub</td></tr>
</tbody>
</table>
</div>
<p>Machine brief: <a href="llms.txt">/llms.txt</a>. OpenAPI: <a href="api/openapi.php">/api/openapi.php</a>. Close records in <code>/api/epochs.php</code> and <code>/api/close.php</code> keep minepoa’s field names so two sources can be compared field by field. Pending panel size is a closed-epoch snapshot, not a live draw. Seals on pending rows are the public close <code>grades</code> field (score + timestamp; grader when present). Quorum is not on those rows. Address JSON may add a <code>grading</code> object without removing origin fields, including an answer-txid join from this account’s challenge txs to settled rows.</p>
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

<?php poa_panel_start('SMCP (Sanctum MCP) — what it is, then how to install it'); ?>
<p><strong>SMCP</strong> is Sanctum’s <a href="https://modelcontextprotocol.io/">Model Context Protocol</a> server. It is a small Python process that turns plugins into tools agents can call (Letta, Cursor, and other MCP clients). It is not this explorer and not the PoA chain. Source and install: <a href="https://github.com/sanctumos/smcp">github.com/sanctumos/smcp</a>. Getting started: <a href="https://github.com/sanctumos/smcp/blob/master/docs/getting-started.md">getting-started.md</a>.</p>
<p>This explorer ships a <strong>read-only</strong> plugin with <strong>no API key</strong>. Install SMCP first, then copy the plugin in. Same steps in the repo: <span class="mono">docs/SMCP.md</span>.</p>
<p><strong>1. Install SMCP</strong> (Python 3.10+):</p>
<pre class="blob">git clone https://github.com/sanctumos/smcp.git
cd smcp
python3 -m venv venv
source venv/bin/activate
pip install -r requirements.txt
python smcp.py</pre>
<p>That starts http://127.0.0.1:8000 on localhost only. For a local editor client, run <code>python smcp_stdio.py</code> instead.</p>
<p><strong>2. Install this explorer’s plugin</strong> (still inside the SMCP clone). Copy the plugin <em>and</em> the Python client:</p>
<pre class="blob">git clone https://github.com/actuallyrizzn/poa-explorer.git
cp -r poa-explorer/smcp_plugin/poa_explorer plugins/poa_explorer
cp -r poa-explorer/sdk/python/poa_explorer plugins/poa_explorer/poa_explorer
python plugins/poa_explorer/cli.py --describe
python plugins/poa_explorer/cli.py status
python smcp.py</pre>
<p>Restart SMCP after copying. Tools appear as <code>poa_explorer__status</code>, <code>poa_explorer__txs</code>, <code>poa_explorer__tx</code>, <code>poa_explorer__address</code>, <code>poa_explorer__anchors</code>, <code>poa_explorer__epochs</code>. <code>poa_explorer__challenges</code> exists but is large — prefer the others.</p>
<p>If your plugins directory is not <code>plugins/</code> next to <code>smcp.py</code>, set <code>MCP_PLUGINS_DIR</code> to that directory and copy into it the same way.</p>
<p><strong>3. Optional — run the CLI without a running SMCP server</strong> (from a clone of this explorer):</p>
<pre class="blob">python3 smcp_plugin/poa_explorer/cli.py status
python3 smcp_plugin/poa_explorer/cli.py address --a poa1…
python3 smcp_plugin/poa_explorer/cli.py tx --id 64hex</pre>
<p>Override the explorer host with <code>--base-url</code> or <code>POA_EXPLORER_BASE_URL</code>. Default is this site.</p>
<?php poa_panel_end(); ?>

<?php poa_panel_start('Standard MCP (stdio) — no SMCP required'); ?>
<p>For MCP clients that speak JSON-RPC over stdio (Cursor, Claude Desktop, and similar), this repo also ships <code>mcp/server.py</code>. Stdlib only. Read-only. Same public JSON. Same facts in the repo: <span class="mono">docs/MCP.md</span>.</p>
<pre class="blob">python3 mcp/server.py</pre>
<p>Point the client at that command. Optional env: <code>POA_EXPLORER_BASE_URL</code>. Tools include <code>status</code>, <code>epochs</code>, <code>close</code>, <code>pending</code>, <code>address</code>, <code>changelog</code>.</p>
<?php poa_panel_end(); ?>

<?php poa_panel_start('Licenses'); ?>
<p>Code: GNU Affero GPL v3. Docs and other non-code content: CC BY-SA 4.0. Full text in the GitHub repo: <a href="https://github.com/actuallyrizzn/poa-explorer">actuallyrizzn/poa-explorer</a>.</p>
<?php poa_panel_end(); ?>
<?php
poa_layout_end();
