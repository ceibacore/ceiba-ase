<?php

declare(strict_types=1);

namespace LemurAse\Tests\Unit\WebhookManagement;

use LemurAse\WebhookManagement\Domain\WebhookEvent;
use PHPUnit\Framework\TestCase;

final class WebhookEventTest extends TestCase
{
    public function testWithDataReturnsAnEnrichedCopy(): void
    {
        $event = new WebhookEvent('INITIAL_PAYMENT', ['amount' => 10.0], 'evt-1', 'stripe', ['raw' => true]);

        $enriched = $event->withData(['gateway_id' => 'gw-1']);

        $this->assertSame(['amount' => 10.0, 'gateway_id' => 'gw-1'], $enriched->data);
        $this->assertSame(['amount' => 10.0], $event->data, 'The original event is unchanged');
        $this->assertSame(['INITIAL_PAYMENT', 'evt-1', 'stripe', ['raw' => true]],
            [$enriched->action, $enriched->externalId, $enriched->provider, $enriched->rawPayload]);
    }

    public function testWebhookManagerDoesNotWriteIntoTheReadonlyEvent(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/WebhookManagement/UI/WebhookManager.php');
        $this->assertDoesNotMatchRegularExpression('/\$event->data\[[^\]]*\]\s*=(?!=)/', $source, 'Assignment into the readonly event data');
    }
}
