<?php

declare(strict_types=1);

namespace SmsTunnel\Exception;

/**
 * 403: the key is valid but may not do this. Either it lacks a permission (error code `PERMISSION_REQUIRED`,
 * see {@see getPermission()}), such as `sms:read` for statuses or `sms:inbox` for received texts, or the plan
 * does not include the feature.
 */
class PermissionException extends ApiException
{
    /** With `PERMISSION_REQUIRED`: the permission the key lacks, such as `sms:inbox`. */
    public function getPermission(): ?string
    {
        $body = $this->getBody();

        return \is_array($body) && \is_string($body['permission'] ?? null) ? $body['permission'] : null;
    }
}
