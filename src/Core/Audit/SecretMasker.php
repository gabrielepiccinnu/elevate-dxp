<?php

declare(strict_types=1);

namespace ElevateDxp\Core\Audit;

final class SecretMasker
{
    private const SECRET_KEYS = ['api_key', 'apikey', 'password', 'secret', 'token', 'authorization', 'client_secret'];

    public static function mask(array $context): array
    {
        foreach ($context as $k => $v) {
            if (\in_array(strtolower((string) $k), self::SECRET_KEYS, true)) {
                $context[$k] = '***';
            } elseif (\is_array($v)) {
                $context[$k] = self::mask($v);
            }
        }

        return $context;
    }
}
