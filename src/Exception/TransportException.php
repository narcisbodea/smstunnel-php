<?php

declare(strict_types=1);

namespace SmsTunnel\Exception;

/**
 * SMSTunnel could not be reached at all: DNS, TLS, a refused connection or a timeout. No answer came back,
 * so there is no HTTP status.
 */
class TransportException extends SmsTunnelException
{
}
