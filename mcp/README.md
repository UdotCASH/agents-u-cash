# agents.u.cash MCP server

Exposes the **agent seller surface** at [agents.u.cash](https://agents.u.cash) as [Model Context Protocol](https://modelcontextprotocol.io) tools, so an AI agent (Claude, etc., via an MCP client) can manage its own pay.u.cash account: configure receive wallets, create priced resources, see the 402 challenge a buyer would get, and read its settlement history. Non-custodial — the platform never holds funds; this only reads/writes metadata.

It is a **thin client over the REST API** (`/v1/*` + the public `/r/{id}` door). It holds no state and never sees the agent password — only the API key the agent already chose to trust the MCP client with.

## Configure

1. Create an agent account and get an API key:
   ```bash
   curl -X POST https://agents.u.cash/v1/signup \
     -H 'Content-Type: application/json' \
     -d '{"email":"me@agent.dev","password":"longpass"}'
   # -> {"response":{"api_key":"…","activated":false,…}}
   ```
   Then top up ≥ $1 at the returned `top-up` URL to activate (required for most tools).
2. Set the key as an env var for the server: `UXC_API_KEY`. Optionally `UXC_BASE_URL` (default `https://agents.u.cash`).

## Claude Desktop

Add to `claude_desktop_config.json` (Settings → Developer → Edit Config):

```json
{
  "mcpServers": {
    "agents-u-cash": {
      "command": "php",
      "args": ["/absolute/path/to/agents/mcp/server.php"],
      "env": {
        "UXC_API_KEY": "your_api_key_here"
      }
    }
  }
}
```

Restart Claude Desktop. The tools (`uxc_get_agent`, `uxc_set_wallet`, `uxc_create_resource`, `uxc_view_door`, `uxc_get_settlements`, …) appear, and you can ask Claude things like *"create a $0.05 resource payable in BTC and show me the payment link."*

## Other MCP clients

Any client that speaks MCP over stdio works. Run the server with the env vars set and point the client at:

```
command: php
args:    <repo>/agents/mcp/server.php
env:     UXC_API_KEY=<key>   # UXC_BASE_URL optional
```

## Tools

| Tool | What it does |
|---|---|
| `uxc_get_agent` | Account snapshot: activation, balance, wallets, webhook, earnings summary |
| `uxc_set_webhook` | Set the settlement webhook URL |
| `uxc_set_wallet` | Set the agent's own receive address for an asset |
| `uxc_create_resource` | Create a priced resource → `res_id` (share `https://agents.u.cash/r/{res_id}`) |
| `uxc_list_resources` | List the agent's resources with settled state |
| `uxc_get_resource` | Fetch one resource |
| `uxc_get_settlements` | Earnings log (settled/underpaid payments) |
| `uxc_view_door` | The public 402 door: what a buyer sees (price + multi-coin `accepts[]`) — no auth |
| `uxc_verify_payment` | **Buyer-side, optional.** The platform auto-detects the payment on-chain; submit the tx hash here to settle instantly instead (challengeId + hash) — no auth. |

## Buyer flow (paying for a resource)

The same server also works for an agent that wants to **pay** for someone else's resource (the `uxc_view_door` + `uxc_verify_payment` tools need no API key):

1. `uxc_view_door {res_id}` → see the `accepts[]` (asset, address, exact amount).
2. Pay that address the exact amount from your own wallet (out of band — the server never touches your keys).
3. `uxc_verify_payment {challengeId, hash}` → settled (confirmed) or pending (awaiting confirmations). This settles instantly; the platform also auto-detects the on-chain payment on its own. Settlement is gated by on-chain confirmation, so this is safe to call with just the public challengeId.

## Protocol

- Transport: **stdio**, newline-delimited JSON-RPC 2.0.
- Handles `initialize`, `notifications/initialized`, `tools/list`, `tools/call`.
- Tool results are returned as text content (the REST JSON); failed calls set `isError: true`.

## Try it by hand

```bash
printf '%s\n' \
  '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{}}}' \
  '{"jsonrpc":"2.0","method":"notifications/initialized"}' \
  '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' \
  | UXC_API_KEY=your_key php agents/mcp/server.php
```
