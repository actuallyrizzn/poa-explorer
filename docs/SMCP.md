# Using this explorer through SMCP (Sanctum MCP)

SMCP is **Sanctum MCP**: a small server that turns command-line plugins into [Model Context Protocol](https://modelcontextprotocol.io/) tools so agents (Letta, Cursor, and other MCP clients) can call them. It is **not** this explorer and **not** the chain. Upstream: [sanctumos/smcp](https://github.com/sanctumos/smcp). Getting-started: https://github.com/sanctumos/smcp/blob/master/docs/getting-started.md

This repo ships one plugin: `smcp_plugin/poa_explorer`. It is **read-only** and needs **no API key**. It talks to https://poa.explorer.zero1.network.

Same facts on the site: https://poa.explorer.zero1.network/docs.php (SMCP section).

## 1. Install SMCP

You need Python 3.10+ and pip.

```bash
git clone https://github.com/sanctumos/smcp.git
cd smcp
python3 -m venv venv
source venv/bin/activate   # Windows: venv\Scripts\activate
pip install -r requirements.txt
```

Default: `python smcp.py` listens on http://127.0.0.1:8000 (localhost only). For a local editor client, use `python smcp_stdio.py` instead.

Full install notes: https://github.com/sanctumos/smcp#installation

## 2. Install this explorer plugin

SMCP loads any folder under `plugins/` (or `MCP_PLUGINS_DIR`) that contains a `cli.py`. Copy **both** the plugin and the Python client (stdlib; no extra pip packages):

```bash
git clone https://github.com/actuallyrizzn/poa-explorer.git
cp -r poa-explorer/smcp_plugin/poa_explorer plugins/poa_explorer
cp -r poa-explorer/sdk/python/poa_explorer plugins/poa_explorer/poa_explorer
```

Paths assume you are still inside the SMCP clone, where `plugins/` already exists. If your plugins live elsewhere:

```bash
export MCP_PLUGINS_DIR=/path/to/your/plugins
cp -r poa-explorer/smcp_plugin/poa_explorer "$MCP_PLUGINS_DIR/poa_explorer"
cp -r poa-explorer/sdk/python/poa_explorer "$MCP_PLUGINS_DIR/poa_explorer/poa_explorer"
```

## 3. Check the plugin, then start SMCP

```bash
python plugins/poa_explorer/cli.py --describe
python plugins/poa_explorer/cli.py status
python smcp.py
```

`--describe` is the SMCP discovery contract. After a restart, tools show up as `poa_explorer__status`, `poa_explorer__txs`, `poa_explorer__tx`, `poa_explorer__address`, `poa_explorer__anchors`, `poa_explorer__epochs`, `poa_explorer__close`, `poa_explorer__pending`, `poa_explorer__changelog`, and `poa_explorer__challenges`.

For MCP clients that do not use SMCP, there is also a stdio server: [docs/MCP.md](MCP.md) (`mcp/server.py`).

`challenges` is a large payload. Prefer `status`, `txs`, `address`, or `tx` unless you need the index.

Override the explorer host with `--base-url` or `POA_EXPLORER_BASE_URL`. Default is https://poa.explorer.zero1.network.

## 4. Optional: run the CLI without SMCP

From a clone of this explorer repo:

```bash
python3 smcp_plugin/poa_explorer/cli.py status
python3 smcp_plugin/poa_explorer/cli.py address --a poa1…
python3 smcp_plugin/poa_explorer/cli.py tx --id <64-hex>
```

No wallet. No keys. Never `/api/hosted`.
