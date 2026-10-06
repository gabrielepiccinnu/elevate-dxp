<?php

declare(strict_types=1);

namespace ElevateDxp\Sso\Login;

/** Thrown when an SSO identity must not be logged in. Map it to an AuthenticationException. */
final class SsoDeniedException extends \RuntimeException
{
}
