<?php

declare(strict_types=1);

namespace ElevateDxp\Experiments\Visitor;

/**
 * HMAC-signed opaque visitor id ("<id>.<mac>"). No PII, tamper-evident.
 */
final class CookieSigner
{
    public function __construct(private readonly string $secret)
    {
        if ($secret === '') {
            throw new \InvalidArgumentException('Visitor cookie secret must not be empty.');
        }
    }

    public function sign(string $id): string
    {
        return $id.'.'.$this->mac($id);
    }

    public function verify(string $value): ?string
    {
        $pos = strrpos($value, '.');
        if ($pos === false || $pos === 0) {
            return null;
        }
        $id = substr($value, 0, $pos);
        if (!hash_equals($this->mac($id), substr($value, $pos + 1))) {
            return null;
        }

        return preg_match('/^[a-f0-9]{8,64}$/', $id) === 1 ? $id : null;
    }

    public static function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function mac(string $id): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $id, $this->secret, true)), '+/', '-_'), '=');
    }
}
