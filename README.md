# agents-u-cash

**Non-custodial HTTP-402 payments for AI agents.**

Agents sell priced resources over a single HTTP 402 challenge — buyers pay direct to the seller's own wallet (on-chain, any coin) or via card (the seller's own Stripe). The platform never holds funds. Multi-coin: BTC (+Lightning), ETH + all ERC-20s, USDT, USDC, Solana, Tron, XRP, Monero, UCASH, custom tokens.

**Live:** [agents.u.cash](https://agents.u.cash) · [OpenAPI spec](https://agents.u.cash/openapi.json) · [llms.txt](https://agents.u.cash/llms.txt) · [MCP page](https://agents.u.cash/mcp)

## What's in this repo

| Path | What |
|---|---|
| `sdk/javascript/` | Zero-dependency JS/Node SDK ([npm: agents-u-cash](https://npmjs.com/package/agents-u-cash)) |
| `sdk/python/` | Zero-dependency Python SDK ([PyPI: agents-u-cash](https://pypi.org/project/agents-u-cash/)) |
| `mcp/` | stdio MCP server (Claude Desktop / any MCP client) + README |
| `api/openapi.json` | OpenAPI 3.1 spec (also served live at agents.u.cash/openapi.json) |

## Quickstart (60 seconds)

```bash
# 1. Sign up (wallet-first; no email loop)
curl -X POST https://agents.u.cash/v1/signup \
  -H 'Content-Type: application/json' \
  -d '{"email":"me@agent.dev","password":"longpass"}'
# -> {"response":{"api_key":"…","activated":false}}

# 2. Top up >= $1 to activate (pays + activates on confirmation)
curl -X POST https://agents.u.cash/v1/top-up \
  -H 'X-Api-Key: $KEY' -d '{"amount":1}'

# 3. Set your receive address (where buyers pay you)
curl -X POST https://agents.u.cash/v1/wallets \
  -H 'X-Api-Key: $KEY' -d '{"asset":"btc","address":"bc1q…"}'

# 4. Create a priced resource
curl -X POST https://agents.u.cash/v1/resources \
  -H 'X-Api-Key: $KEY' -d '{"amount":0.05}'
# -> {"response":{"res_id":"res_…"}}
# Share:  https://agents.u.cash/r/{res_id}
```

## SDKs

### JavaScript
```bash
npm install agents-u-cash
```
```javascript
import { AgentsUCash } from 'agents-u-cash';
const agent = new AgentsUCash({ apiKey: 'your_key' });
await agent.setWallet('btc', 'bc1q…');
const res = await agent.createResource({ amount: 0.05 });
```

### Python
```bash
pip install agents-u-cash
```
```python
from agents_u_cash import AgentsUCash
agent = AgentsUCash(api_key='your_key')
agent.set_wallet('btc', 'bc1q…')
res = agent.create_resource(amount=0.05)
```

## MCP server (Claude Desktop)

See [`mcp/README.md`](mcp/README.md) for setup. Tools: `uxc_get_agent`, `uxc_set_wallet`, `uxc_create_resource`, `uxc_list_resources`, `uxc_create_challenge`, `uxc_get_settlements`, `uxc_view_door`, `uxc_verify_payment`.

## UCP (Universal Commerce Protocol)

agents.u.cash implements the UCP discovery layer:
- `/.well-known/ucp` — business profile (capabilities, payment handlers, EC P-256 signing key).
- `/catalog.json` — schema.org/Product JSON-LD catalog (the merchant's Shop products).
- `/catalog/search` | `/catalog/lookup` | `/catalog/product` — structured catalog REST.
- `/checkout-sessions` — UCP checkout sessions (create → complete → payment handlers).
- `/orders/{id}` — order status.

UCP is an open standard ([ucp.dev](https://ucp.dev), Apache-licensed; co-developed by Google/Shopify/Amazon/Walmart/Microsoft/Meta/Stripe/Visa/Mastercard).

## Protocol

[402.onl](https://402.onl) — the 402 Online Protocol.

## License

MIT
