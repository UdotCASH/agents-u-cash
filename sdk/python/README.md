# agents-u-cash (Python)

Zero-dependency Python client for the [agents.u.cash](https://agents.u.cash) API — the 402 Online Protocol for non-custodial agent payments. Works for **both sides** of the network: an agent selling (manage resources, watch settlements) and a buyer (fetch a 402 door, pay, and settle — automatically via on-chain detection, or instantly by submitting the tx hash).

Uses only the standard library (`urllib`). No dependencies. Python 3.7+.

## Install

```bash
pip install agents-u-cash
```

Or copy `agents_u_cash.py` — it has no dependencies.

## Sell (as an agent)

```python
from agents_u_cash import AgentsUCash

# Get an API key once (wallet-first; then top up >= $1 to activate):
# key = AgentsUCash().signup(email="me@agent.dev", password="longpass")["api_key"]

agent = AgentsUCash(api_key="...")
agent.set_wallet("btc", "bc1q…")
agent.set_webhook("https://my.bot/webhook")

res = agent.create_resource(amount=0.05)               # priced resource
acc = agent.create_challenge(res["res_id"])             # what a buyer pays
# Share the payable link: https://agents.u.cash/r/{res_id}

settled = agent.get_settlements()                       # your earnings log
```

## Buy (as a buyer)

```python
from agents_u_cash import AgentsUCash

buyer = AgentsUCash()                                   # no key needed to buy
door = buyer.view_door(res_id)                          # the 402 door (JSON)
# 1. pick an entry, pay entry["payTo"] exactly entry["amount"] from your wallet (out of band)
# 2. the platform auto-detects the on-chain payment and settles it.
#    Optionally POST the tx hash to settle instantly instead:
result = buyer.verify(door["accepts"][0]["challengeId"], tx_hash)
# -> {"settled": True} | {"status": "pending", "confirmations": n, "required": m}
```

A human-friendly payable page is also available: `buyer.view_door(res_id, html=True)` returns the HTML.

## API

| Method | Auth | Description |
|---|---|---|
| `signup(email, password, primary_wallet=None)` | — | Register; returns `api_key` |
| `top_up(amount)` | key | Create a ≥$1 top-up checkout (activates the account) |
| `get_agent()` | key | Account snapshot (balance, wallets, webhook, earnings summary) |
| `set_webhook(url)` | key | Set the settlement webhook |
| `set_wallet(asset, address)` | key | Set your receive address for an asset |
| `set_stripe(secret_key, product_id, webhook_secret, publishable_key=None)` | key | Connect your Stripe account (card rail); verifies the key + product |
| `get_stripe()` | key | Masked Stripe config + the webhook endpoint to register |
| `clear_stripe()` | key | Disconnect your Stripe account |
| `set_custom_token(type, code, contract_address, decimals, name, rate=None, rate_url=None)` | key | Add a custom token (ERC-20/TRC-20/SPL); then `set_wallet(asset=code, address=...)` to set its receive address |
| `get_custom_tokens()` | key | List your custom tokens |
| `delete_custom_token(code)` | key | Remove a custom token |
| `get_settings()` | key | Read safe settings (confirmations, webhook url+secret, currency, payment prefs, notifications, branding) |
| `set_settings(partial)` | key | Partially update safe settings |
| `create_resource(amount, currency=None, accepted_assets=None, webhook_url=None)` | key | Create a priced resource |
| `get_resources(res_id=None)` | key | List resources, or fetch one |
| `create_challenge(res_id)` | key | Build the multi-coin `accepts[]` |
| `verify(challenge_id, hash)` | key optional | Verify + settle (buyer-push: no key needed) |
| `get_settlements()` | key | Earnings log |
| `view_door(res_id, html=False)` | — | The public 402 door (JSON, or HTML) |

All calls return the parsed `response` dict and raise `AgentsUCashError` on API errors (which carries `.code` and `.status`).

## Error handling

```python
from agents_u_cash import AgentsUCash, AgentsUCashError

try:
    agent.create_resource(amount=0.05)
except AgentsUCashError as e:
    print(e.code, e.status, e)   # e.g. 'uxc_agent_not_activated' 402 ...
```

Non-custodial: the platform never holds funds — every `payTo` is the seller's own wallet, and this client never sees your wallet keys.
