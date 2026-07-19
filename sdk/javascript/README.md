# agents-u-cash

Zero-dependency JavaScript client for the [agents.u.cash](https://agents.u.cash) API — the 402 Online Protocol for non-custodial agent payments. Works for **both sides** of the network: an agent selling (manage resources, watch settlements) and a buyer (fetch a 402 door, pay, and settle — automatically via on-chain detection, or instantly by submitting the tx hash).

Uses the global `fetch` (Node 18+ and modern browsers). No dependencies.

## Install

```bash
npm install agents-u-cash
```

Or copy `index.js` — it has no dependencies.

## Sell (as an agent)

```js
import { AgentsUCash } from 'agents-u-cash';

// Get an API key once (wallet-first; then top up >= $1 to activate):
// const { api_key } = await new AgentsUCash().signup({ email: 'me@agent.dev', password: 'longpass' });

const agent = new AgentsUCash({ apiKey: process.env.UXC_API_KEY });
await agent.setWallet('btc', 'bc1q…');
await agent.setWebhook('https://my.bot/webhook');

const { res_id } = await agent.createResource({ amount: 0.05 });        // priced resource
const { accepts } = await agent.createChallenge(res_id);                // what a buyer pays
// Share the payable link: https://agents.u.cash/r/{res_id}

const settled = await agent.getSettlements();                          // your earnings log
```

## Buy (as a buyer)

```js
import { AgentsUCash } from 'agents-u-cash';

const buyer = new AgentsUCash();                       // no key needed to buy
const { accepts } = await buyer.viewDoor(resId);       // the 402 door (JSON)
// 1. pick an entry, pay entry.payTo exactly entry.amount from your wallet (out of band)
// 2. the platform auto-detects the on-chain payment and settles it.
//    Optionally POST the tx hash to settle instantly instead:
const result = await buyer.verify(accepts[0].challengeId, txHash);
// -> { settled: true } | { status: 'pending', confirmations, required }
```

A human-friendly payable page is also available: `await buyer.viewDoor(resId, { html: true })` returns the HTML.

## API

| Method | Auth | Description |
|---|---|---|
| `signup({ email, password, primaryWallet? })` | — | Register; returns `api_key` |
| `topUp(amount)` | key | Create a ≥$1 top-up checkout (activates the account) |
| `getAgent()` | key | Account snapshot (balance, wallets, webhook, earnings summary) |
| `setWebhook(url)` | key | Set the settlement webhook |
| `setWallet(asset, address)` | key | Set your receive address for an asset |
| `setStripe({ secretKey, productId, webhookSecret, publishableKey? })` | key | Connect your Stripe account (card rail); verifies the key + product |
| `getStripe()` | key | Masked Stripe config + the webhook endpoint to register |
| `clearStripe()` | key | Disconnect your Stripe account |
| `setCustomToken({ type, code, contractAddress, decimals, name, rate?, rateUrl? })` | key | Add a custom token (ERC-20/TRC-20/SPL); then `setWallet({ asset: code, address })` to set its receive address |
| `getCustomTokens()` | key | List your custom tokens |
| `deleteCustomToken(code)` | key | Remove a custom token |
| `getSettings()` | key | Read safe settings (confirmations, webhook url+secret, currency, payment prefs, notifications, branding) |
| `setSettings(partial)` | key | Partially update safe settings |
| `createResource({ amount, currency?, acceptedAssets?, webhookUrl? })` | key | Create a priced resource |
| `getResources(resId?)` | key | List resources, or fetch one |
| `createChallenge(resId)` | key | Build the multi-coin `accepts[]` |
| `verify(challengeId, hash)` | key optional | Verify + settle (buyer-push: no key needed) |
| `getSettlements()` | key | Earnings log |
| `viewDoor(resId, { html? })` | — | The public 402 door (JSON, or HTML) |

All calls return the parsed `response` object and throw on API errors (the error has `.code` and `.status`).

## Error handling

```js
try {
  await agent.createResource({ amount: 0.05 });
} catch (e) {
  console.error(e.code, e.status, e.message);   // e.g. 'uxc_agent_not_activated' 402
}
```

Non-custodial: the platform never holds funds — every `payTo` is the seller's own wallet, and this client never sees your wallet keys.
