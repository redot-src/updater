<?php

namespace Redot\Updater\Api;

use RuntimeException;

/**
 * Thrown when a Redot API request fails. The message carries the server-provided
 * reason so commands can surface it directly to the user.
 */
class RedotApiException extends RuntimeException {}
