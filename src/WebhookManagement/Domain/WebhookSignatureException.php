<?php

declare(strict_types=1);

namespace LemurAse\WebhookManagement\Domain;

/**
 * The webhook is not authentic (missing or invalid signature, gateway not configured).
 * Hosts should answer 401 and never process it; providers retry only real failures.
 */
final class WebhookSignatureException extends \Exception
{
}
