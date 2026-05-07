<?php

declare(strict_types=1);

namespace LemurAse\FormManagement\Application;

use LemurAse\FormManagement\Domain\Events\GatewayFormConfiguredEvent;
use LemurAse\FormManagement\UI\GatewayFormManager;
use LemurAse\FormManagement\UI\GatewayFormException;
use LemurAse\Shared\Domain\Events\EventDispatcherInterface;

/**
 * Application Use Case: Process a submitted gateway configuration form.
 *
 * Responsibilities:
 *  1. Delegates CSRF + field validation to GatewayFormManager.
 *  2. On success, dispatches a GatewayFormConfiguredEvent so listeners
 *     can persist credentials, update cache, etc.
 *  3. Does NOT write to the database directly — that is the listener's job.
 *
 * Usage (from a controller):
 *
 *   $useCase = new ProcessGatewayFormSubmission($dispatcher);
 *   $event   = $useCase->execute(
 *       provider:    'stripe',
 *       postData:    $_POST,
 *       csrfToken:   $_POST['_token'],
 *       userId:      (string) Auth::id(),
 *       existing:    $existingCredentials,
 *   );
 *   // The event has already been dispatched.
 *   // Listeners handle persistence asynchronously (or synchronously in tests).
 */
final class ProcessGatewayFormSubmission
{
    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
    ) {}

    /**
     * @throws GatewayFormException  On CSRF or validation failure.
     */
    public function execute(
        string $provider,
        array  $postData,
        string $csrfToken,
        string $userId,
        array  $existing = [],
    ): GatewayFormConfiguredEvent {
        // 1. Validate submission (CSRF + field rules)
        $result = GatewayFormManager::processSubmission(
            provider:   $provider,
            postData:   $postData,
            csrfToken:  $csrfToken,
            userId:     $userId,
            existing:   $existing,
        );

        // 2. Build the domain event
        $event = new GatewayFormConfiguredEvent(
            provider:      $provider,
            credentials:   $result['credentials'],
            isActive:      $result['is_active'],
            configuredBy:  $userId,
        );

        // 3. Dispatch (sync or async depending on the driver registered)
        $this->dispatcher->dispatch($event);

        return $event;
    }
}
