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
use RoundlyConsulting\Git\GitManager;

final class WebhookController
{
    public function __construct(
        private readonly GitManager $git,
    ) {}

    public function __invoke(Request $request, string $provider): JsonResponse
    {
        $name = ProviderName::tryFrom($provider);

        if ($name === null) {
            return new JsonResponse(['message' => 'Unknown provider.'], 404);
        }

        if (! $this->git->verifyWebhook($name, $request)) {
            return new JsonResponse(['message' => 'Invalid signature.'], 403);
        }

        $event = new WebhookEvent(
            provider: $name,
            type: $this->eventType($name, $request),
            payload: $this->payload($request),
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

    /**
     * The delivery's JSON payload, whichever content type carried it.
     *
     * GitHub offers two: `application/json`, and `application/x-www-form-urlencoded` — the
     * default when a hook is added in its UI — which sends the JSON as a `payload` field.
     * The field is read from the raw body, the bytes the signature covers, never from the
     * request's merged input, where an unsigned query string could supply it.
     *
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        if ($request->getContentTypeFormat() !== 'form') {
            return $request->json()->all();
        }

        parse_str($request->getContent(), $form);

        $payload = is_string($form['payload'] ?? null) ? json_decode($form['payload'], true) : null;

        return is_array($payload) ? $payload : [];
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
