<?php
/*
 * agents.u.cash MCP server — exposes the agent SELLER surface as Model Context Protocol tools, so an
 * AI agent (Claude, etc., via an MCP client) can manage its own pay.u.cash account: configure receive
 * wallets, create priced resources, view the 402 challenge a buyer would see, and read its settlement
 * history. Non-custodial throughout — the platform never holds funds; this only reads/writes metadata.
 *
 * Transport: stdio, newline-delimited JSON-RPC 2.0 (the MCP standard for local tools).
 * Config (env): UXC_API_KEY (required — from POST https://agents.u.cash/v1/signup), UXC_BASE_URL
 *               (default https://agents.u.cash). The MCP client sets these (see README.md).
 *
 * This is a thin client over the REST API — it holds no state and never sees the agent's password or
 * keys beyond the API key the agent already chose to trust the client with. See agents/mcp/README.md.
 */

// Guard: this is a stdio server, run via CLI (`php server.php`). It must never execute over HTTP.
if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    die('Not found.');
}

// ---------- configuration ----------
$UXC_BASE_URL = getenv('UXC_BASE_URL') ?: 'https://agents.u.cash';
$UXC_API_KEY  = getenv('UXC_API_KEY') ?: '';

// ---------- REST client ----------
function uxc_call($base, $key, $method, $path, $body = null, $query = [], $auth = true) {
    $url = rtrim($base, '/') . '/' . ltrim($path, '/');
    if ($query) {
        $url .= '?' . http_build_query($query);
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $headers = ['Accept: application/json'];
    if ($auth) {
        $headers[] = 'X-Api-Key: ' . $key;
    }
    if ($body !== null && $method !== 'GET') {
        $headers[] = 'Content-Type: application/json';
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        } else {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    } elseif ($method !== 'GET' && $method !== 'POST') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($resp === false) {
        return ['success' => false, 'error_code' => 'uxc_transport', 'message' => 'Request failed: ' . $err];
    }
    $json = json_decode($resp, true);
    return is_array($json) ? $json : ['success' => false, 'error_code' => 'uxc_bad_response', 'message' => 'Unreadable response (HTTP ' . $code . ').', 'body' => $resp];
}

// ---------- tool catalog ----------
// Each tool: name, description, inputSchema (JSON Schema), and a handler returning the REST JSON.
function uxc_tools() {
    return [
        'uxc_get_agent' => [
            'description' => 'Show the agent account snapshot: activation state, non-withdrawable credit balance (USD), configured per-asset receive wallets, webhook URL, and an earnings summary (settlements count + total earned).',
            'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
        ],
        'uxc_set_webhook' => [
            'description' => 'Set the webhook URL that agents.u.cash notifies (POST, HMAC-signed) when a resource settles.',
            'inputSchema' => ['type' => 'object', 'required' => ['webhook_url'], 'properties' => ['webhook_url' => ['type' => 'string', 'format' => 'uri']]],
        ],
        'uxc_set_stripe' => [
            'description' => 'Connect the agent own Stripe account so it can accept card payments (the redirect rail). Pass the Stripe secret key, a one-time product id, and the webhook signing secret. The key + product are verified against Stripe before saving. Non-custodial — card payments settle direct to the agent own Stripe account.',
            'inputSchema' => ['type' => 'object', 'required' => ['secret_key', 'product_id', 'webhook_secret'], 'properties' => [
                'secret_key' => ['type' => 'string', 'description' => 'sk_live_... or sk_test_...'],
                'product_id' => ['type' => 'string', 'description' => 'prod_...'],
                'webhook_secret' => ['type' => 'string', 'description' => 'whsec_... (from registering the webhook endpoint)'],
                'publishable_key' => ['type' => 'string', 'description' => 'optional pk_live_... or pk_test_...'],
            ]],
        ],
        'uxc_get_stripe' => [
            'description' => 'Show the agent Stripe configuration (masked) and the webhook endpoint URL to register in the Stripe dashboard.',
            'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
        ],
        'uxc_clear_stripe' => [
            'description' => 'Disconnect the agent Stripe account (disables card payments).',
            'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
        ],
        'uxc_set_custom_token' => [
            'description' => 'Add a custom token (ERC-20/TRC-20/SPL) the agent accepts. type is one of erc-20, bep-20, base-20, polygon-20, arb-20, op-20, avax-20, trc-20, spl. After adding, set a receive address for it with uxc_set_wallet (asset = the code) so buyers can pay it. Custom tokens are auto-detected on-chain like the built-in coins.',
            'inputSchema' => ['type' => 'object', 'required' => ['type', 'code', 'contract_address', 'decimals', 'name'], 'properties' => [
                'type' => ['type' => 'string', 'description' => 'erc-20 | bep-20 | base-20 | polygon-20 | arb-20 | op-20 | avax-20 | trc-20 | spl'],
                'code' => ['type' => 'string', 'description' => '2-12 lowercase letters/digits; must not collide with a built-in coin'],
                'contract_address' => ['type' => 'string', 'description' => 'the token contract (ERC-20 0x..., TRC-20 T..., SPL mint)'],
                'decimals' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 36],
                'name' => ['type' => 'string'],
                'rate' => ['type' => 'string', 'description' => 'optional fixed USD rate'],
                'rate_url' => ['type' => 'string', 'format' => 'uri', 'description' => 'optional live-rate JSON URL'],
                'confirmations' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 200, 'description' => 'optional confirmations to settle (blank = the default)'],
            ]],
        ],
        'uxc_get_custom_tokens' => [
            'description' => 'List the custom tokens the agent has configured.',
            'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
        ],
        'uxc_delete_custom_token' => [
            'description' => 'Remove a custom token by its code.',
            'inputSchema' => ['type' => 'object', 'required' => ['code'], 'properties' => ['code' => ['type' => 'string']]],
        ],
        'uxc_get_settings' => [
            'description' => 'Read the agent safe settings: confirmations policy (default + per-coin + value-increase), webhook url + signing secret, default currency, payment preferences, notification email, and branding.',
            'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
        ],
        'uxc_set_settings' => [
            'description' => 'Partially update the agent safe settings. Pass any subset of {confirmations:{default?, coins:{<code>:n}?}, webhook:{url?, rotate_secret?}, currency?, payment:{accept_underpayments?, redirect?}, notifications:{email?}, branding:{brand_name?, logo_url?, logo_url_dark?, color_1?, color_2?, color_3?}}. Only sent fields change.',
            'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
        ],
        'uxc_set_wallet' => [
            'description' => 'Set the agent own receive address for one asset. This is where buyer funds land (non-custodial — the platform never holds them). asset is a code like btc, eth, sol, trx, usdt.',
            'inputSchema' => ['type' => 'object', 'required' => ['asset', 'address'], 'properties' => [
                'asset' => ['type' => 'string'], 'address' => ['type' => 'string'],
            ]],
        ],
        'uxc_create_resource' => [
            'description' => 'Create a priced resource the agent sells access to. amount is a fiat price (e.g. 0.01). accepted_assets defaults to all configured wallets; pass a list to restrict. Returns the res_id to share with buyers (https://agents.u.cash/r/{res_id}).',
            'inputSchema' => ['type' => 'object', 'required' => ['amount'], 'properties' => [
                'amount' => ['type' => 'number', 'minimum' => 0.01],
                'currency' => ['type' => 'string', 'default' => 'USD'],
                'accepted_assets' => ['type' => 'array', 'items' => ['type' => 'string']],
                'webhook_url' => ['type' => 'string', 'format' => 'uri'],
            ]],
        ],
        'uxc_list_resources' => [
            'description' => 'List the agent resources (newest first), each with its price, accepted assets, and whether it has settled.',
            'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
        ],
        'uxc_get_resource' => [
            'description' => 'Fetch one resource by res_id, with its settled state.',
            'inputSchema' => ['type' => 'object', 'required' => ['res_id'], 'properties' => ['res_id' => ['type' => 'string']]],
        ],
        'uxc_get_settlements' => [
            'description' => 'Show the agent earnings log: settled (and underpaid) on-chain payments for its resources (asset, amount, fiat value, tx hash, settled-at). Read-only record — funds already went to the agent own wallet.',
            'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
        ],
        'uxc_view_door' => [
            'description' => 'Fetch the public 402 payment door for a resource (what a buyer sees at https://agents.u.cash/r/{res_id}): the price + the multi-coin accepts[] (each asset, the agent receive address, and the exact amount to pay). No auth needed. Use this as a BUYER to see what to pay, then uxc_verify_payment after paying.',
            'inputSchema' => ['type' => 'object', 'required' => ['res_id'], 'properties' => ['res_id' => ['type' => 'string']]],
        ],
        'uxc_verify_payment' => [
            'description' => 'BUYER-SIDE. After paying a resource (from uxc_view_door) on-chain from your own wallet, submit the transaction hash to settle it. Pass the challengeId from the accepts[] entry you paid and the on-chain transaction hash. Returns settled (confirmed) or pending (awaiting confirmations). No seller key needed — the challengeId identifies the resource, and settlement is gated by on-chain confirmation.',
            'inputSchema' => ['type' => 'object', 'required' => ['challengeId', 'hash'], 'properties' => [
                'challengeId' => ['type' => 'string'], 'hash' => ['type' => 'string'],
            ]],
        ],
    ];
}

function uxc_dispatch($name, $args) {
    global $UXC_BASE_URL, $UXC_API_KEY;
    $k = $UXC_API_KEY;
    switch ($name) {
        case 'uxc_get_agent':      return uxc_call($UXC_BASE_URL, $k, 'GET',  '/v1/agent');
        case 'uxc_set_webhook':    return uxc_call($UXC_BASE_URL, $k, 'POST', '/v1/agent', ['webhook_url' => $args['webhook_url'] ?? '']);
        case 'uxc_set_stripe':     return uxc_call($UXC_BASE_URL, $k, 'POST', '/v1/stripe', ['secret_key' => $args['secret_key'] ?? '', 'product_id' => $args['product_id'] ?? '', 'webhook_secret' => $args['webhook_secret'] ?? '', 'publishable_key' => $args['publishable_key'] ?? '']);
        case 'uxc_get_stripe':     return uxc_call($UXC_BASE_URL, $k, 'GET',  '/v1/stripe');
        case 'uxc_clear_stripe':   return uxc_call($UXC_BASE_URL, $k, 'POST', '/v1/stripe', ['clear' => 1]);
        case 'uxc_set_custom_token':  return uxc_call($UXC_BASE_URL, $k, 'POST',   '/v1/custom-tokens', ['type' => $args['type'] ?? '', 'code' => $args['code'] ?? '', 'contract_address' => $args['contract_address'] ?? '', 'decimals' => $args['decimals'] ?? '', 'name' => $args['name'] ?? '', 'rate' => $args['rate'] ?? '', 'rate_url' => $args['rate_url'] ?? '', 'confirmations' => $args['confirmations'] ?? '']);
        case 'uxc_get_custom_tokens': return uxc_call($UXC_BASE_URL, $k, 'GET',    '/v1/custom-tokens');
        case 'uxc_delete_custom_token': return uxc_call($UXC_BASE_URL, $k, 'DELETE', '/v1/custom-tokens', null, ['code' => $args['code'] ?? '']);
        case 'uxc_get_settings': return uxc_call($UXC_BASE_URL, $k, 'GET', '/v1/settings');
        case 'uxc_set_settings': return uxc_call($UXC_BASE_URL, $k, 'PUT', '/v1/settings', $args);
        case 'uxc_set_wallet':     return uxc_call($UXC_BASE_URL, $k, 'POST', '/v1/wallets', ['asset' => $args['asset'] ?? '', 'address' => $args['address'] ?? '']);
        case 'uxc_create_resource':return uxc_call($UXC_BASE_URL, $k, 'POST', '/v1/resources', $args);
        case 'uxc_list_resources': return uxc_call($UXC_BASE_URL, $k, 'GET',  '/v1/resources');
        case 'uxc_get_resource':   return uxc_call($UXC_BASE_URL, $k, 'GET',  '/v1/resources', null, ['res_id' => $args['res_id'] ?? '']);
        case 'uxc_get_settlements':return uxc_call($UXC_BASE_URL, $k, 'GET',  '/v1/settlements');
        case 'uxc_view_door':      return uxc_call($UXC_BASE_URL, $k, 'GET',  '/r/' . ($args['res_id'] ?? ''), null, [], false);
        case 'uxc_verify_payment': return uxc_call($UXC_BASE_URL, $k, 'POST', '/v1/verify', ['challengeId' => $args['challengeId'] ?? '', 'hash' => $args['hash'] ?? ''], [], false);
        default: return ['success' => false, 'error_code' => 'uxc_unknown_tool', 'message' => 'Unknown tool: ' . $name];
    }
}

// ---------- MCP JSON-RPC ----------
function uxc_tool_catalog() {
    $out = [];
    foreach (uxc_tools() as $name => $t) {
        $out[] = ['name' => $name, 'description' => $t['description'], 'inputSchema' => $t['inputSchema']];
    }
    return $out;
}

function uxc_handle($msg) {
    $method = $msg['method'] ?? '';
    $id = array_key_exists('id', $msg) ? $msg['id'] : null;

    // Notifications (no id) get no response.
    if ($id === null) {
        return null;
    }

    if ($method === 'initialize') {
        $clientVersion = $msg['params']['protocolVersion'] ?? '2025-06-18';
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => [
            'protocolVersion' => $clientVersion,
            'capabilities'    => ['tools' => new stdClass()],
            'serverInfo'      => ['name' => 'agents-u-cash', 'version' => '0.1.0'],
        ]];
    }

    if ($method === 'tools/list') {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => uxc_tool_catalog()]];
    }

    if ($method === 'tools/call') {
        global $UXC_API_KEY;
        $name = $msg['params']['name'] ?? '';
        $args = $msg['params']['arguments'] ?? [];
        if (!isset(uxc_tools()[$name])) {
            return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32602, 'message' => 'Unknown tool: ' . $name]];
        }
        if ($UXC_API_KEY === '' && $name !== 'uxc_view_door' && $name !== 'uxc_verify_payment') {
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['content' => [['type' => 'text', 'text' => 'UXC_API_KEY is not set. Sign up at https://agents.u.cash/v1/signup and set UXC_API_KEY in this server env.']], 'isError' => true]];
        }
        $result = uxc_dispatch($name, is_array($args) ? $args : []);
        $isError = empty($result['success']);
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['content' => [['type' => 'text', 'text' => json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]], 'isError' => $isError]];
    }

    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'Method not found: ' . $method]];
}

// ---------- stdio loop ----------
while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    $msg = json_decode($line, true);
    if (!is_array($msg) || !isset($msg['method'])) {
        continue;   // not a valid JSON-RPC message — skip
    }
    $resp = uxc_handle($msg);
    if ($resp !== null) {
        fwrite(STDOUT, json_encode($resp) . "\n");
    }
}
