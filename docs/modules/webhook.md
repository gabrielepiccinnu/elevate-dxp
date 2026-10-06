# Elevate DXP Webhooks (`elevate-dxp/webhook-bundle`)

HMAC-signed JSON webhooks on OpenDXP element events (data objects, assets, documents), delivered
through Symfony Messenger with retry, a delivery log (`edxp_webhook_delivery`) and one-click re-delivery.
Ported from `OpenPimcore\WebhookBundle`. GPL-3.0-or-later.

## Install

```
bin/console opendxp:bundle:enable ElevateDxpWebhookBundle
bin/console opendxp:bundle:install ElevateDxpWebhookBundle   # table + permission "elevate_dxp_webhook"
```

Deliveries use the core `elevate_dxp` Messenger transport (`ELEVATE_DXP_MESSENGER_DSN`, default `sync://`,
which means inline delivery during the save). In production, use an async transport and run a worker:

```
ELEVATE_DXP_MESSENGER_DSN=doctrine://default?queue_name=elevate_dxp
bin/console messenger:consume elevate_dxp
```

Failed deliveries are retried according to the transport retry strategy (Symfony default: 3 retries,
exponential back-off). After that they go to the failure transport, if one is configured.

## Configuration

```yaml
elevate_dxp_webhook:
    enabled: true
    timeout: 5                # seconds (1-60)
    sink_enabled: false       # local debug receiver, dev/testing only
    sink_secret: ''           # when set, the sink verifies the signature (401 on mismatch)
    subscriptions:
        erp:
            active: true
            url: 'https://erp.example.com/hooks/dxp'
            secret: '%env(WEBHOOK_ERP_SECRET)%'
            events: [object.add, object.update, object.delete, asset.update, document.update]
```

Events: `object.add|update|delete`, `asset.add|update|delete`, `document.add|update|delete`.
Unknown event names are rejected at container build. Editor auto-saves do not fire webhooks.

## Wire format

`POST <url>` with `Content-Type: application/json`, `User-Agent: ElevateDxp-Webhook/1.0`,
`X-ElevateDxp-Event: <event>`, `X-ElevateDxp-Signature: sha256=<hex hmac of the raw body>` (only when a
secret is set). Body: `{"event": "...", "data": {"elementType", "id", "key", "fullPath", "type", ...}}`.
Redirects are not followed. Verify the signature with `ElevateDxp\Webhook\Webhook\WebhookSignature::verify()`
or any constant-time HMAC-SHA256 comparison.

## CLI

- `elevate-dxp:webhook:test <subscription> [--event=object.update] [--async]`: send a test payload.
- `elevate-dxp:webhook:install`: re-apply the idempotent schema and permission.

## Admin (Elevate DXP → Integration)

- **Webhook subscriptions** (`webhook_subscriptions`): a read-only view of the YAML subscriptions. Secrets are
  never shown, only an "HMAC signed" flag. Actions:
  - *Send test* (record): pick the event, then deliver now (shows the HTTP result) or queue it.
  - *Dispatch test event to all* (global).
- **Webhook deliveries** (`webhook_deliveries`): the read-only delivery log. *Re-deliver* (record) re-sends the stored
  event and payload with the current subscription config. You can filter by status in two ways:
  - type `failed`, `delivered`, `status:failed`, `subscription:<name>` or `event:<key>` in the search box;
  - or send the `filters` request parameter.

Permission: `elevate_dxp_webhook`.

## Public route

`POST /elevate-dxp/webhook-sink` (`elevate_dxp_webhook_sink`). This is a debug receiver that appends to
`var/elevate-dxp/webhook-sink.log`, with each logged body capped at 64 KiB. It answers 404 unless `sink_enabled: true`.
Never enable it in production.

## Porting notes

- Headers `X-OpenPimcore-*` were renamed to `X-ElevateDxp-*`. The n8n blueprint path is `elevate-dxp-<name>`.
- The Messenger message now carries only the subscription name. URL and secret are resolved at delivery
  time, so secrets are no longer serialised into the queue.
- The handler throws `WebhookDeliveryFailedException` instead of `RecoverableMessageHandlingException`.
  Messenger retries recoverable exceptions without limit, so a dead endpoint was retried forever.
- HTTP goes through the OpenDXP Guzzle client (`GuzzleHttp\Client`, honours the proxy settings), not
  Symfony HttpClient, which the app does not ship.
- Added: document events, auto-save skipping, an http(s)-only URL check, optional sink signature verification,
  and per-subscription failure isolation when the delivery runs inline.
