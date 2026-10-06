# Automation (n8n)

Generates importable [n8n](https://n8n.io) workflow blueprints from the [webhook](webhook.md) subscriptions. Each blueprint has three nodes: a Webhook trigger (POST, path `elevate-dxp-<name>`), a NoOp node ("Do something") and a sticky note that documents the event, payload and signature contract. Blueprints are computed from the configuration, so nothing is stored.

Config key: `elevate_dxp.automation` · Permission: `elevate_dxp_automation` · Admin menu: Elevate DXP → Integration

Installed with the bundle; no tables (permission only).

## Configuration

```yaml
elevate_dxp:
    automation:
        enabled: true   # false: the admin list is empty, and generation is refused in admin and CLI
```

Subscriptions are read from `elevate_dxp.webhook.subscriptions`. A blueprint only contains the subscription name and its events. URLs and secrets are never included. All configured subscriptions are listed, including inactive ones, and regardless of `elevate_dxp.webhook.enabled`.

## Usage

1. Generate the blueprint for a subscription, then import it in n8n (*Import from clipboard* or *Import from file*).
2. Set the subscription `url` to `<n8n base URL>/webhook/elevate-dxp-<name>`. In the path, any character outside `[A-Za-z0-9_-]` in the name is replaced with `-`.
3. In the workflow, verify `X-ElevateDxp-Signature` (`sha256=<hex HMAC of the raw body>`) with the subscription secret. See [webhook.md](webhook.md#wire-format).
4. Replace the NoOp node with your automation.

## Admin

**Automation (n8n)** (`automation_n8n`) is a read-only grid of the subscriptions, their events and the n8n webhook path. The *Generate n8n blueprint* record action opens the JSON in a text window for copying.

## CLI

```
bin/console elevate-dxp:automation:n8n-export                       # list subscriptions
bin/console elevate-dxp:automation:n8n-export <subscription>        # print the blueprint JSON
bin/console elevate-dxp:automation:n8n-export <subscription> --save # write var/elevate-dxp/n8n/<name>.json
```
