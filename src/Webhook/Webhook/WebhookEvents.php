<?php

declare(strict_types=1);

namespace ElevateDxp\Webhook\Webhook;

/** Event keys a subscription can listen to (the "event" field of every webhook body). */
final class WebhookEvents
{
    public const OBJECT_ADD = 'object.add';
    public const OBJECT_UPDATE = 'object.update';
    public const OBJECT_DELETE = 'object.delete';
    public const ASSET_ADD = 'asset.add';
    public const ASSET_UPDATE = 'asset.update';
    public const ASSET_DELETE = 'asset.delete';
    public const DOCUMENT_ADD = 'document.add';
    public const DOCUMENT_UPDATE = 'document.update';
    public const DOCUMENT_DELETE = 'document.delete';

    public const ALL = [
        self::OBJECT_ADD, self::OBJECT_UPDATE, self::OBJECT_DELETE,
        self::ASSET_ADD, self::ASSET_UPDATE, self::ASSET_DELETE,
        self::DOCUMENT_ADD, self::DOCUMENT_UPDATE, self::DOCUMENT_DELETE,
    ];

    /** @return array<string,string> value => label, for admin selects */
    public static function options(): array
    {
        return array_combine(self::ALL, self::ALL);
    }
}
