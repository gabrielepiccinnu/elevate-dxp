<?php

declare(strict_types=1);

namespace ElevateDxp\Core\Messenger;

/**
 * Marker for Elevate DXP messages handled asynchronously on the "elevate_dxp" transport.
 * The transport DSN comes from ELEVATE_DXP_MESSENGER_DSN (default sync://, i.e. inline).
 */
interface AsyncMessageInterface
{
}
