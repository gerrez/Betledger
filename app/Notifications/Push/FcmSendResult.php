<?php

namespace App\Notifications\Push;

/**
 * What happened to one message, with the reason when it didn't arrive.
 */
readonly class FcmSendResult
{
    public function __construct(public FcmResult $status, public ?string $error = null) {}
}
