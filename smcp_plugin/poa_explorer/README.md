# SMCP plugin — poa_explorer

Read-only tools for https://poa.explorer.zero1.network. **No API key.**

If you have never used SMCP: it is Sanctum’s Model Context Protocol server ([sanctumos/smcp](https://github.com/sanctumos/smcp)). **Install SMCP first, then drop this plugin in.** Full steps: [docs/SMCP.md](../../docs/SMCP.md) (same copy on https://poa.explorer.zero1.network/docs.php).

Short version (from an SMCP clone):

```bash
cp -r /path/to/poa-explorer/smcp_plugin/poa_explorer plugins/poa_explorer
cp -r /path/to/poa-explorer/sdk/python/poa_explorer plugins/poa_explorer/poa_explorer
python plugins/poa_explorer/cli.py --describe
python smcp.py
```

Direct CLI (no SMCP server):

```bash
python3 smcp_plugin/poa_explorer/cli.py status
python3 smcp_plugin/poa_explorer/cli.py address --a poa1…
python3 smcp_plugin/poa_explorer/cli.py tx --id <64-hex>
```
