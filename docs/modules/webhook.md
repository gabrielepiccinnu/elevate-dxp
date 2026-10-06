# Webhooks

Sends HMAC-signed JSON webhooks when OpenDXP data objects, assets or documents are added, updated or deleted. Subscriptions are declared in YAML. Delivery goes through Symfony Messenger (shared `elevate_dxp` transport), every attempt is written to a delivery log, and failed deliveries can be re-sent from the admin.

Config key: `elevate_dxp.webhook` · Permission: `elevate_dxp_webhook` · Admin menu: Elevate DXP → Integration

Installed with the bundle; table: `edxp_webhook_delivery`. `bin/console elevate-dxp:webhook:install` re-applies only this table and the permission (idempotent).

## Configuration

```yaml
elevate_dxp:
    webhook:
        enabled: true            # false: no event is dispatched
        timeout: 5               # seconds, 1-60; used as both request and connect timeout
        sink_enabled: false      # debug receiver, see "Debug sink"; never in production
        sink_secret: ''          # when set, the sink verifies the signature
        subscriptions:
            erp:                              # subscription name
                active: true                  # default true
                url: 'https://erp.example.com/hooks/dxp'   # required; must be http(s) at delivery time
                secret: '%env(WEBHOOK_ERP_SECRET)%'         # optional HMAC secret; empty = unsigned
                events: [object.add, object.update, object.delete, asset.update, document.update]
```

Events: `object.add|update|delete`, `asset.add|update|delete`, `document.add|update|delete`. Unknown event names fail config validation at container build. A subscription with an empty `events` list never receives element events (tests still work).

Subscriptions are read-only in the admin; change them in YAML. Keep secrets in environment variables. See [../configuration.md](../configuration.md) for where the `elevate_dxp:` file lives.

## Triggers

The subscriber listens to `POST_ADD`, `POST_UPDATE` and `POST_DELETE` of data objects, assets and documents. Editor auto-saves (`isAutoSave` argument) are skipped. Dispatch is fail-soft: an error never breaks the save.

## Delivery

Each matching active subscription gets one `SendWebhookMessage`. The message implements `ElevateDxp\Core\Messenger\AsyncMessageInterface`, which the core routes to the `elevate_dxp` transport. Its DSN is `ELEVATE_DXP_MESSENGER_DSN`, default `sync://`.

- **`sync://` (default):** delivery runs inline during the save, so a slow endpoint delays the save by up to `timeout`. A failure is logged and does not stop the other subscriptions, but it is **not retried**. Use *Re-deliver* in the admin.
- **Async transport (recommended for production):**

  ```
  ELEVATE_DXP_MESSENGER_DSN=doctrine://default?queue_name=elevate_dxp
  bin/console messenger:consume elevate_dxp
  ```

  A failed delivery throws `WebhookDeliveryFailedException`. Messenger then applies the transport retry strategy (Symfony default: 3 retries with exponential back-off), then the failure transport if one is configured.

The message carries only the subscription name, the event and the payload. URL and secret are resolved from the current configuration when the message is handled, so secrets are never serialised into the queue and a rotated secret applies to pending retries. When the message is handled, a subscription that was removed from config is dropped (unrecoverable, no retry) and an inactive one is skipped.

HTTP goes through the OpenDXP Guzzle client (service `GuzzleHttp\Client`, built by `OpenDxp\Http\ClientFactory`, so the system proxy settings apply). Redirects are not followed. Any 2xx response counts as success.

Each attempt is also written to the audit log as `webhook.send`. The delivery log has no automatic pruning.

## Wire format

```
POST <url>
Content-Type: application/json
User-Agent: ElevateDxp-Webhook/1.0
X-ElevateDxp-Event: object.update
X-ElevateDxp-Signature: sha256=<hex HMAC-SHA256 of the raw body>   (only when a secret is set)

{"event": "object.update", "data": {...}}
```

`data` by element type:

| Type | Fields |
|---|---|
| object | `elementType`, `id`, `key`, `fullPath`, `type`, `className` |
| asset | `elementType`, `id`, `key` (file name), `fullPath`, `type`, `mimeType` |
| document | `elementType`, `id`, `key`, `fullPath`, `type` |

Test deliveries send `{"test": true, "elementType", "id": 0, "key": "webhook-test", "fullPath": "/webhook-test", "source": "cli"|"admin", "note"}`.

To verify on the receiver, recompute the HMAC over the raw body with the shared secret and compare in constant time. In PHP: `ElevateDxp\Webhook\Webhook\WebhookSignature::verify($body, $secret, $header)`.

## Admin

Both resources are read-only grids.

- **Webhook subscriptions** (`webhook_subscriptions`): name, active flag, URL, events and an "HMAC signed" flag. The secret is never shown.
  - *Send test* (record): choose the event and the mode. *Now* delivers synchronously and shows the HTTP result. *Queue* dispatches through Messenger and is refused for inactive subscriptions.
  - *Dispatch test event to all* (global): sends a test event to every active subscription that listens to it. Refused when `enabled: false`.
- **Webhook deliveries** (`webhook_deliveries`): the delivery log (time, subscription, event, status, HTTP status, URL, error, payload).
  - Filters: status, subscription, event. The search box also accepts `failed`, `delivered`, `status:<value>`, `subscription:<name>` and `event:<key>`.
  - *Re-deliver* (record): re-sends the stored event and payload to the current URL and secret of the subscription. Refused if the subscription no longer exists or is inactive.

## CLI

```
bin/console elevate-dxp:webhook:test <subscription> [--event=object.update] [--async]
bin/console elevate-dxp:webhook:install
```

`webhook:test` delivers synchronously and exits non-zero on failure. With `--async` it only queues the message. Unlike the admin, it does not check whether the subscription is active, so the handler skips a queued test for an inactive subscription.

## Debug sink

`POST /elevate-dxp/webhook-sink` (route `elevate_dxp_webhook_sink`) is a public receiver for local testing. It answers `404` unless `sink_enabled: true`. Each request is appended to `var/elevate-dxp/webhook-sink.log`, with the body capped at 64 KiB. When `sink_secret` is set, the signature is checked and a mismatch answers `401`. A request that passes answers `204`. Do not enable it in production. See [../security-and-privacy.md](../security-and-privacy.md).
