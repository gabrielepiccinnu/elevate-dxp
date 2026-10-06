<?php

declare(strict_types=1);

namespace ElevateDxp\Webhook\Webhook;

/**
 * Read-only view of the configured subscriptions (elevate_dxp_webhook.subscriptions).
 * Subscriptions are edited in YAML only, so they stay versioned with the application (GitOps-friendly).
 */
final class SubscriptionRegistry
{
    /**
     * @param array<string,array{active?:bool,url:string,secret?:string,events?:list<string>}> $subscriptions
     */
    public function __construct(
        private readonly array $subscriptions,
        private readonly bool $enabled = true,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map('strval', array_keys($this->subscriptions));
    }

    /** @return array{active?:bool,url:string,secret?:string,events?:list<string>}|null */
    public function get(string $name): ?array
    {
        return $this->subscriptions[$name] ?? null;
    }

    public function isActive(string $name): bool
    {
        $sub = $this->get($name);

        return $sub !== null && ($sub['active'] ?? true) === true;
    }

    /**
     * Subscription data safe to show in the admin UI: never the secret.
     *
     * @return array{name:string,active:bool,url:string,events:list<string>,signed:bool}|null
     */
    public function describe(string $name): ?array
    {
        $sub = $this->get($name);
        if ($sub === null) {
            return null;
        }

        return [
            'name' => $name,
            'active' => ($sub['active'] ?? true) === true,
            'url' => (string) ($sub['url'] ?? ''),
            'events' => array_values(array_map('strval', (array) ($sub['events'] ?? []))),
            'signed' => (string) ($sub['secret'] ?? '') !== '',
        ];
    }
}
