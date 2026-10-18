<?php
declare(strict_types=1);

namespace WebRobot\CommerceMcp\Model;

/**
 * A tool call that cannot be honoured. Returned to the caller as `isError: true` with the message.
 */
class ToolError extends \RuntimeException
{
}
