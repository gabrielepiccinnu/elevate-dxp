<?php

declare(strict_types=1);

namespace ElevateDxp\Translation\Provider;

use Psr\Container\ContainerInterface;
use Symfony\Contracts\Service\ServiceProviderInterface;

/** Resolves translation providers (by key) from a tagged locator. */
final class ProviderRegistry
{
    public function __construct(
        private readonly ContainerInterface $providers,
        private readonly string $defaultProvider,
    ) {
    }

    /** Legacy behaviour: unknown or empty name falls back to pseudo, never fails. */
    public function get(?string $name = null): TranslationProviderInterface
    {
        $name = $name === null || $name === '' ? $this->defaultProvider : $name;
        if ($this->providers->has($name)) {
            return $this->providers->get($name);
        }

        // Fallback: pseudo if available, else a fresh pseudo provider.
        if ($this->providers->has('pseudo')) {
            return $this->providers->get('pseudo');
        }

        return new PseudoProvider();
    }

    /** Strict lookup for user input: an explicitly requested provider must exist. */
    public function getOrFail(string $name): TranslationProviderInterface
    {
        if (!$this->providers->has($name)) {
            throw new \InvalidArgumentException(\sprintf('Unknown translation provider "%s".', $name));
        }

        return $this->providers->get($name);
    }

    /** @return list<string> */
    public function names(): array
    {
        if ($this->providers instanceof ServiceProviderInterface) {
            return array_map('strval', array_keys($this->providers->getProvidedServices()));
        }

        return array_values(array_filter(['pseudo', 'libretranslate'], fn (string $n): bool => $this->providers->has($n)));
    }

    public function defaultName(): string
    {
        return $this->get()->name();
    }
}
