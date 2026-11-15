<?php

namespace App\Notifications\Push;

/**
 * The outcome of sending one notification to all of a user's devices.
 */
readonly class PushReport
{
    /**
     * @param  int  $sent  devices Firebase accepted the message for
     * @param  int  $removed  devices Firebase no longer knows, now deleted
     * @param  list<string>  $errors  the distinct reasons other devices failed
     */
    public function __construct(public int $sent = 0, public int $removed = 0, public int $failed = 0, public array $errors = []) {}
}
