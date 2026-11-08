<?php

declare(strict_types=1);

namespace LemurAse\WebhookManagement\Domain;

/**
 * A provider event is authentic but must not be applied (wrong amount or currency,
 * missing order reference, order in a final state). Nothing is written.
 */
final class PaymentVerificationException extends \RuntimeException
{
}
