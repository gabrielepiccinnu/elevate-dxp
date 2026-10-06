# Elevate DXP Automation (`elevate-dxp/automation-bundle`)

Generates importable [n8n](https://n8n.io) workflow blueprints from Elevate DXP webhook subscriptions.
Each blueprint contains a Webhook trigger, a NoOp node and a sticky note that documents the event,
payload and signature contract. Ported from `OpenPimcore\AutomationBundle`. GPL-3.0-or-later.

Requires `elevate-dxp/webhook-bundle`, which is registered automatically as a dependent bundle, and reads
`elevate_dxp_webhook.subscriptions`. A blueprint only contains the subscription name and its events. URLs and
secrets are never included.

## Configuration

```yaml
elevate_dxp_automation:
    enabled: true   # false: admin list is empty, generation refused
```

## Usage

1. Generate the blueprint for a subscription, then import it in n8n (*Import from clipboard / file*).
2. Set the subscription `url` to `<n8n>/webhook/elevate-dxp-<name>`.
3. Verify `X-ElevateDxp-Signature` in the workflow.

## CLI

- `elevate-dxp:automation:n8n-export`: lists the subscriptions.
- `elevate-dxp:automation:n8n-export <subscription>`: prints the blueprint JSON.
- `elevate-dxp:automation:n8n-export <subscription> --save`: writes it to `var/elevate-dxp/n8n/<name>.json`.

## Admin (Elevate DXP → Integration)

**Automation (n8n)** (`automation_n8n`, permission `elevate_dxp_automation`) lists the subscriptions and their events,
along with the n8n webhook path for each. The *Generate n8n blueprint* record action shows the JSON, ready to copy.

## Porting notes

- The Studio panel's Copy and Download buttons are replaced by the action's text window, where you copy the JSON.
  Use the CLI `--save` to get a file.
- The legacy `open-pimcore:n8n:export` command is now `elevate-dxp:automation:n8n-export`.
