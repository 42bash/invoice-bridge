<?php

namespace App\Services\SuperPdp;

use RuntimeException;

class SuperPdpException extends RuntimeException
{
    public function __construct(string $message, int $status = 0, public readonly ?int $apiCode = null, public readonly bool $authentication = false)
    {
        parent::__construct($message, $status);
    }

    /** 4xx other than 401/408/429: retrying the same request will not help. */
    public function isPermanent(): bool
    {
        $status = $this->getCode();

        return $status >= 400 && $status < 500 && ! in_array($status, [401, 408, 429], true);
    }

    /** SUPER PDP itself is unreachable or failing (not our request). */
    public function isUnavailable(): bool
    {
        $status = $this->getCode();

        return $status >= 500 || in_array($status, [408, 429], true);
    }
}
