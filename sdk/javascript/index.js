// agents.u.cash JavaScript client — the 402 Online Protocol API (non-custodial agent payments).
// Zero dependencies: uses the global fetch available in Node 18+ and modern browsers. Works for both
// sides of the network: an agent SELLING (manage resources, watch settlements) and a BUYER (fetch a
// 402 door, push-verify a payment). See https://agents.u.cash.

const DEFAULT_BASE_URL = 'https://agents.u.cash';
const USER_AGENT = 'agents-u-cash-js/0.1 (+https://agents.u.cash)';

/**
 * Client for the agents.u.cash API.
 *
 * Most calls require an agent API key (from signup). The buyer-side calls — {@link viewDoor} and the
 * buyer form of {@link verify} — are public (no key); you can call them on a client constructed
 * without an apiKey, or use the static helpers.
 */
export class AgentsUCash {
  /**
   * @param {object} opts
   * @param {string} [opts.apiKey]  Agent API key (required for seller/authenticated calls).
   * @param {string} [opts.baseUrl] Override the API base URL.
   */
  constructor({ apiKey = '', baseUrl = DEFAULT_BASE_URL } = {}) {
    this.apiKey = apiKey;
    this.baseUrl = baseUrl.replace(/\/+$/, '');
    if (!this.baseUrl.startsWith('https://') && !this.baseUrl.startsWith('http://localhost')) {
      throw new Error('baseUrl must use HTTPS (or http://localhost for development)');
    }
  }

  /** Internal: perform a JSON request and return the parsed `response` (throws on API error). */
  async #request(method, path, { body = null, query = null, auth = true } = {}) {
    let url = this.baseUrl + path;
    if (query) {
      url += '?' + new URLSearchParams(query).toString();
    }
    const headers = { Accept: 'application/json', 'User-Agent': USER_AGENT };
    if (auth && this.apiKey) {
      headers['X-Api-Key'] = this.apiKey;
    }
    if (body !== null) {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(body);
    }
    const res = await fetch(url, { method, headers, body });
    let data = null;
    const text = await res.text();
    if (text) {
      try { data = JSON.parse(text); } catch { data = { success: false, message: text }; }
    }
    if (data && data.success === false) {
      const err = new Error((data && data.message) || ('HTTP ' + res.status));
      err.code = data && data.error_code ? data.error_code : 'http_error';
      err.status = res.status;
      throw err;
    }
    if (data && data.success === true) {
      return data.response; // v1 API envelope {success, response}
    }
    // Raw envelope (UCP checkout/order responses have no success wrapper): HTTP status gates success.
    if (!res.ok) {
      const err = new Error((data && (data.error || data.message)) || ('HTTP ' + res.status));
      err.code = data && data.error_code ? data.error_code : 'http_error';
      err.status = res.status;
      throw err;
    }
    return data;
  }

  // ---------- account ----------
  /** Register an agent account. Wallet-first: returns the api_key immediately (no email loop). */
  signup({ email, password, primaryWallet } = {}) {
    const body = { email, password };
    if (primaryWallet !== undefined) body.primary_wallet = primaryWallet;
    return this.#request('POST', '/v1/signup', { body, auth: false });
  }

  /** Create a credit top-up checkout ($1 min; activates the account on confirmation). */
  topUp(amount) {
    return this.#request('POST', '/v1/top-up', { body: { amount } });
  }

  /** Account snapshot: activation, balance, wallets, webhook URL, earnings summary. */
  getAgent() {
    return this.#request('GET', '/v1/agent');
  }

  /** Set the webhook URL notified on settlement. */
  setWebhook(webhookUrl) {
    return this.#request('POST', '/v1/agent', { body: { webhook_url: webhookUrl } });
  }

  /** Set the agent's own receive address for an asset (where buyer funds land). */
  setWallet(asset, address) {
    return this.#request('POST', '/v1/wallets', { body: { asset, address } });
  }

  /** Connect the agent's own Stripe account to enable card payments (the redirect rail). */
  setStripe({ secretKey, productId, webhookSecret, publishableKey } = {}) {
    const body = { secret_key: secretKey, product_id: productId, webhook_secret: webhookSecret };
    if (publishableKey !== undefined) body.publishable_key = publishableKey;
    return this.#request('POST', '/v1/stripe', { body });
  }

  /** The masked Stripe config + the webhook endpoint URL to register in the Stripe dashboard. */
  getStripe() {
    return this.#request('GET', '/v1/stripe');
  }

  /** Disconnect the agent's Stripe account (disables card payments). */
  clearStripe() {
    return this.#request('POST', '/v1/stripe', { body: { clear: 1 } });
  }

  // ---------- custom tokens (seller) ----------
  /** Add a custom token (ERC-20/TRC-20/SPL) the agent accepts. Follow with setWallet({ asset: code,
   *  address }) so buyers have a receive address to pay (the token's own address field stays empty). */
  setCustomToken({ type, code, contractAddress, decimals, name, rate, rateUrl, confirmations } = {}) {
    const body = { type, code, contract_address: contractAddress, decimals, name };
    if (rate !== undefined) body.rate = rate;
    if (rateUrl !== undefined) body.rate_url = rateUrl;
    if (confirmations !== undefined) body.confirmations = confirmations;
    return this.#request('POST', '/v1/custom-tokens', { body });
  }

  /** The custom tokens the agent has configured. */
  getCustomTokens() {
    return this.#request('GET', '/v1/custom-tokens');
  }

  /** Remove a custom token by its code. */
  deleteCustomToken(code) {
    return this.#request('DELETE', '/v1/custom-tokens', { query: { code } });
  }

  // ---------- settings (seller) ----------
  /** Read the agent safe settings: confirmations policy, webhook url + signing secret, currency,
   *  payment preferences, notification email, and branding. */
  getSettings() {
    return this.#request('GET', '/v1/settings');
  }

  /** Partially update the agent safe settings. Pass any subset of the shape; only sent fields change. */
  setSettings(partial = {}) {
    return this.#request('PUT', '/v1/settings', { body: partial });
  }

  // ---------- resources (seller) ----------
  /** Create a priced resource. Returns { res_id, amount, currency, accepted_assets }. */
  createResource({ amount, currency, acceptedAssets, webhookUrl } = {}) {
    const body = { amount };
    if (currency !== undefined) body.currency = currency;
    if (acceptedAssets !== undefined) body.accepted_assets = acceptedAssets;
    if (webhookUrl !== undefined) body.webhook_url = webhookUrl;
    return this.#request('POST', '/v1/resources', { body });
  }

  /** List the agent's resources with settled state, or fetch one by res_id. */
  getResources(resId = null) {
    return resId
      ? this.#request('GET', '/v1/resources', { query: { res_id: resId } })
      : this.#request('GET', '/v1/resources');
  }

  // ---------- 402 payment flow ----------
  /** Build the multi-coin accepts[] for a resource (the payable options). */
  createChallenge(resId) {
    return this.#request('POST', '/v1/challenge', { body: { res_id: resId } });
  }

  /**
   * Verify + settle a payment by on-chain transaction hash. Buyer-push: with no apiKey on the client,
   * the public challengeId authorizes the call (settlement is still gated by on-chain confirmation).
   * @param {string} challengeId  From an accepts[] entry (or the door).
   * @param {string} hash         The on-chain transaction hash.
   */
  verify(challengeId, hash) {
    return this.#request('POST', '/v1/verify', { body: { challengeId, hash }, auth: !!this.apiKey });
  }

  /** The agent's earnings log (settled/underpaid resource payments). */
  getSettlements() {
    return this.#request('GET', '/v1/settlements');
  }

  // ---------- buyer / public ----------
  /**
   * The public 402 door: the price + the multi-coin accepts[] for a resource. No key needed. Pass
   * `{ html: true }` to get the human-payable HTML page instead of the JSON challenge.
   */
  viewDoor(resId, { html = false } = {}) {
    if (html) {
      return fetch(this.baseUrl + '/r/' + encodeURIComponent(resId), { headers: { Accept: 'text/html', 'User-Agent': USER_AGENT } })
        .then((r) => r.text());
    }
    return this.#request('GET', '/r/' + encodeURIComponent(resId), { auth: false });
  }

  // ---------- UCP checkout sessions (buyer-side; merchant from host or ?cloud=) ----------
  /**
   * Create a UCP checkout session (multi-item, mixed-currency cart). The merchant is resolved from the
   * custom-domain baseUrl, or from `cloud` (a merchant token) on the shared platform host. No API key.
   * Returns {id, status, currency, line_items, totals, ap2:{merchant_authorization, nonce}}.
   */
  createCheckout({ lineItems, currency, buyer, context, cloud } = {}) {
    const body = { line_items: lineItems };
    if (currency !== undefined) body.currency = currency;
    if (buyer !== undefined) body.buyer = buyer;
    if (context !== undefined) body.context = context;
    return this.#request('POST', '/checkout-sessions', { body, auth: false, query: cloud ? { cloud } : null });
  }

  /** Fetch a checkout session by id (status: incomplete -> ready_for_complete -> completed). */
  getCheckout(id, { cloud } = {}) {
    return this.#request('GET', '/checkout-sessions/' + encodeURIComponent(id), { auth: false, query: cloud ? { cloud } : null });
  }

  /**
   * Mint payment challenges -> ready_for_complete. Optional `ap2: { checkout_mandate }` for AP2
   * (dev.ucp.shopping.ap2_mandate) holder-proof buyer authorization (SD-JWT-VC); verified, else throws 401.
   */
  completeCheckout(id, { ap2, cloud } = {}) {
    const body = ap2 ? { ap2 } : {};
    return this.#request('POST', '/checkout-sessions/' + encodeURIComponent(id) + '/complete', { body, auth: false, query: cloud ? { cloud } : null });
  }

  /** Cancel a checkout session. */
  cancelCheckout(id, { cloud } = {}) {
    return this.#request('POST', '/checkout-sessions/' + encodeURIComponent(id) + '/cancel', { auth: false, query: cloud ? { cloud } : null });
  }

  /** A checkout session viewed as a UCP order (per-item fulfillment status). */
  getOrder(id, { cloud } = {}) {
    return this.#request('GET', '/orders/' + encodeURIComponent(id), { auth: false, query: cloud ? { cloud } : null });
  }

  /** Search the merchant catalog (text + price filter + pagination). */
  searchCatalog({ query, filters, pagination, cloud } = {}) {
    const body = {};
    if (query !== undefined) body.query = query;
    if (filters !== undefined) body.filters = filters;
    if (pagination !== undefined) body.pagination = pagination;
    return this.#request('POST', '/catalog/search', { body, auth: false, query: cloud ? { cloud } : null });
  }
}

export default AgentsUCash;
