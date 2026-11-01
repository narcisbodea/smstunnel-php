<?php

declare(strict_types=1);

namespace SmsTunnel\Exception;

/**
 * 401: the API key is wrong, was deleted or expired, or its account is suspended. Check the key in your
 * SMSTunnel dashboard.
 */
class AuthenticationException extends ApiException
{
}
