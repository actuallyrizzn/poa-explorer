# Standard MCP server (stdio)

This repo ships a **stdlib** JSON-RPC MCP server next to the SMCP plugin.

- SMCP plugin: `smcp_plugin/poa_explorer` — for Sanctum MCP (`sanctumos/smcp`)
- This file: `mcp/server.py` — for any MCP client that speaks stdio (Cursor, Claude Desktop, and similar)

Both are **read-only**. No API key. No wallet.

## Run

```bash
python3 mcp/server.py
```

Override the explorer host with `POA_EXPLORER_BASE_URL` (default `https://poa.explorer.zero1.network`).

Example MCP client config:

```json
{
  "command": "python3",
  "args": ["/path/to/poa-explorer/mcp/server.py"],
  "env": {
    "POA_EXPLORER_BASE_URL": "https://poa.explorer.zero1.network"
  }
}
```

Tools: `status`, `txs`, `tx`, `address`, `anchors`, `epochs`, `close`, `challenges`, `pending`, `changelog`, `reports`, `health`, `market`.

`epochs` / `close` return the same close-record field names and timestamps as minepoa `GET /api/epochs` (`epoch`, `ts`, `pool`, `note`, `basis`, `weights`, `challenges`, `propagation`, `rewards`), wrapped in the explorer envelope.

`pending` is the last **closed** epoch’s `basis.pending` snapshot (panel size + `since`). It is not a live panel draw.
