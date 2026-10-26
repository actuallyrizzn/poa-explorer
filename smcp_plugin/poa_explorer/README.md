# SMCP — poa_explorer

Read-only tools for https://poa.explorer.zero1.network. **No API key.**

```bash
python3 smcp_plugin/poa_explorer/cli.py status
python3 smcp_plugin/poa_explorer/cli.py address --a poa1…
python3 smcp_plugin/poa_explorer/cli.py tx --id <64-hex>
```

Override host with `--base-url` or `POA_EXPLORER_BASE_URL`. `challenges` is large; prefer `status` / `txs` / `address`.
