<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Webhooks;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RoundlyConsulting\Git\Dto\WebhookEvent;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Events\PullRequestEventReceived;
use RoundlyConsulting\Git\Events\PushReceived;
use RoundlyConsulting\Git\Events\WebhookReceived;

final class WebhookController
{
    public function __construct(
        private readonly SignatureVerifier $verifier,
    ) {}

    public function __invoke(Request $request, string $provider): JsonResponse
    {
        $name = ProviderName::tryFrom($provider);

        if ($name === null) {
            return new JsonResponse(['message' => 'Unknown provider.'], 404);
        }

        if (! $this->verifier->verify($name, $request)) {
            return new JsonResponse(['message' => 'Invalid signature.'], 403);
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();

        $event = new WebhookEvent(
            provider: $name,
            type: $this->eventType($name, $request),
            payload: $payload,
        );

        WebhookReceived::dispatch($event);

        if ($event->isPush()) {
            PushReceived::dispatch($event);
        }

        if ($event->isPullRequest()) {
            PullRequestEventReceived::dispatch($event);
        }

        return new JsonResponse(['message' => 'ok']);
    }

    private function eventType(ProviderName $provider, Request $request): string
    {
        return match ($provider) {
            ProviderName::Github => (string) $request->header('X-GitHub-Event', 'unknown'),
            ProviderName::Gitlab => (string) $request->header('X-Gitlab-Event', 'unknown'),
            ProviderName::Bitbucket => (string) $request->header('X-Event-Key', 'unknown'),
        };
    }
}
