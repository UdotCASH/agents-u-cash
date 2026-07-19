"""agents.u.cash Python client — the 402 Online Protocol API (non-custodial agent payments).

Zero dependencies (stdlib ``urllib`` only; Python 3.7+). Works for both sides of the network: an
agent SELLING (manage resources, watch settlements) and a BUYER (fetch a 402 door, push-verify a
payment). See https://agents.u.cash.
"""

import json
import urllib.request
import urllib.error
from urllib.parse import urlencode, quote

DEFAULT_BASE_URL = "https://agents.u.cash"
USER_AGENT = "agents-u-cash-python/0.1 (+https://agents.u.cash)"


class AgentsUCashError(Exception):
    """Raised on any non-success API response. Carries ``code`` and ``status``."""

    def __init__(self, message, code=None, status=None):
        super().__init__(message)
        self.code = code
        self.status = status


class AgentsUCash:
    """Client for the agents.u.cash API.

    Most calls require an agent API key (from :meth:`signup`). The buyer-side calls
    (:meth:`view_door` and the buyer form of :meth:`verify`) are public; a client constructed
    without an api_key can call them.

    Args:
        api_key: Agent API key (required for seller/authenticated calls).
        base_url: Override the API base URL.
    """

    def __init__(self, api_key="", base_url=DEFAULT_BASE_URL):
        self.api_key = api_key
        self.base_url = base_url.rstrip("/")
        if not self.base_url.startswith("https://") and not self.base_url.startswith("http://localhost"):
            raise ValueError("base_url must use HTTPS (or http://localhost for development)")

    def _request(self, method, path, body=None, query=None, auth=True):
        url = self.base_url + path
        if query:
            url += "?" + urlencode(query)
        headers = {"Accept": "application/json", "User-Agent": USER_AGENT}
        data = None
        if auth and self.api_key:
            headers["X-Api-Key"] = self.api_key
        if body is not None:
            headers["Content-Type"] = "application/json"
            data = json.dumps(body).encode("utf-8")
        req = urllib.request.Request(url, data=data, headers=headers, method=method)
        try:
            with urllib.request.urlopen(req) as resp:
                text = resp.read().decode("utf-8")
                status = resp.status
        except urllib.error.HTTPError as e:
            text = e.read().decode("utf-8")
            status = e.code
        try:
            payload = json.loads(text) if text else {}
        except ValueError:
            payload = {"success": False, "message": text}
        if not payload or payload.get("success") is not True:
            raise AgentsUCashError(
                payload.get("message", "HTTP %s" % status),
                code=payload.get("error_code", "http_error"),
                status=status,
            )
        return payload["response"]

    # ----- account -----
    def signup(self, email, password, primary_wallet=None):
        """Register an agent account. Wallet-first: returns the api_key immediately."""
        body = {"email": email, "password": password}
        if primary_wallet is not None:
            body["primary_wallet"] = primary_wallet
        return self._request("POST", "/v1/signup", body=body, auth=False)

    def top_up(self, amount):
        """Create a credit top-up checkout ($1 min; activates the account on confirmation)."""
        return self._request("POST", "/v1/top-up", body={"amount": amount})

    def get_agent(self):
        """Account snapshot: activation, balance, wallets, webhook URL, earnings summary."""
        return self._request("GET", "/v1/agent")

    def set_webhook(self, webhook_url):
        """Set the webhook URL notified on settlement."""
        return self._request("POST", "/v1/agent", body={"webhook_url": webhook_url})

    def set_wallet(self, asset, address):
        """Set the agent's own receive address for an asset (where buyer funds land)."""
        return self._request("POST", "/v1/wallets", body={"asset": asset, "address": address})

    def set_stripe(self, secret_key, product_id, webhook_secret, publishable_key=None):
        """Connect the agent's own Stripe account to enable card payments (the redirect rail)."""
        body = {"secret_key": secret_key, "product_id": product_id, "webhook_secret": webhook_secret}
        if publishable_key is not None:
            body["publishable_key"] = publishable_key
        return self._request("POST", "/v1/stripe", body=body)

    def get_stripe(self):
        """The masked Stripe config + the webhook endpoint URL to register in the Stripe dashboard."""
        return self._request("GET", "/v1/stripe")

    def clear_stripe(self):
        """Disconnect the agent's Stripe account (disables card payments)."""
        return self._request("POST", "/v1/stripe", body={"clear": 1})

    # ----- custom tokens (seller) -----
    def set_custom_token(self, type, code, contract_address, decimals, name, rate=None, rate_url=None, confirmations=None):
        """Add a custom token (ERC-20/TRC-20/SPL) the agent accepts. Follow with set_wallet(asset=code,
        address=...) so buyers have a receive address to pay (the token's own address field stays empty)."""
        body = {"type": type, "code": code, "contract_address": contract_address, "decimals": decimals, "name": name}
        if rate is not None:
            body["rate"] = rate
        if rate_url is not None:
            body["rate_url"] = rate_url
        if confirmations is not None:
            body["confirmations"] = confirmations
        return self._request("POST", "/v1/custom-tokens", body=body)

    def get_custom_tokens(self):
        """The custom tokens the agent has configured."""
        return self._request("GET", "/v1/custom-tokens")

    def delete_custom_token(self, code):
        """Remove a custom token by its code."""
        return self._request("DELETE", "/v1/custom-tokens", query={"code": code})

    # ----- settings (seller) -----
    def get_settings(self):
        """Read the agent safe settings: confirmations policy, webhook url + signing secret, currency,
        payment preferences, notification email, and branding."""
        return self._request("GET", "/v1/settings")

    def set_settings(self, partial=None):
        """Partially update the agent safe settings. Pass any subset of the shape; only sent fields change."""
        return self._request("PUT", "/v1/settings", body=partial or {})

    # ----- resources (seller) -----
    def create_resource(self, amount, currency=None, accepted_assets=None, webhook_url=None):
        """Create a priced resource. Returns {res_id, amount, currency, accepted_assets}."""
        body = {"amount": amount}
        if currency is not None:
            body["currency"] = currency
        if accepted_assets is not None:
            body["accepted_assets"] = accepted_assets
        if webhook_url is not None:
            body["webhook_url"] = webhook_url
        return self._request("POST", "/v1/resources", body=body)

    def get_resources(self, res_id=None):
        """List the agent's resources, or fetch one by res_id."""
        return self._request("GET", "/v1/resources", query={"res_id": res_id} if res_id else None)

    # ----- 402 payment flow -----
    def create_challenge(self, res_id):
        """Build the multi-coin accepts[] for a resource (the payable options)."""
        return self._request("POST", "/v1/challenge", body={"res_id": res_id})

    def verify(self, challenge_id, hash):
        """Verify + settle a payment by on-chain tx hash.

        Buyer-push: with no api_key on the client, the public challengeId authorizes the call
        (settlement is still gated by on-chain confirmation).
        """
        return self._request(
            "POST", "/v1/verify", body={"challengeId": challenge_id, "hash": hash}, auth=bool(self.api_key)
        )

    def get_settlements(self):
        """The agent's earnings log (settled/underpaid resource payments)."""
        return self._request("GET", "/v1/settlements")

    # ----- buyer / public -----
    def view_door(self, res_id, html=False):
        """The public 402 door: price + multi-coin accepts[]. Pass html=True for the payable page."""
        if html:
            url = self.base_url + "/r/" + quote(res_id)
            req = urllib.request.Request(url, headers={"Accept": "text/html", "User-Agent": USER_AGENT})
            with urllib.request.urlopen(req) as resp:
                return resp.read().decode("utf-8")
        return self._request("GET", "/r/" + quote(res_id), auth=False)
