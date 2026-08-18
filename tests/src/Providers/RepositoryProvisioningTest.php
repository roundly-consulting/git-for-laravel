<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Git\Dto\Input\NewRepository;
use RoundlyConsulting\Git\Dto\Repository;
use RoundlyConsulting\Git\Enums\Feature;
use RoundlyConsulting\Git\Exceptions\FeatureNotSupportedException;
use RoundlyConsulting\Git\Exceptions\InvalidCredentialsException;

/**
 * The body IS the assertion here.
 *
 * Every field this feature added is one a provider silently drops when it is spelled
 * wrong or sent to the wrong route — an ignored `owner` builds the repository in the
 * wrong account, an ignored `auto_init` leaves it with no commit to clone. A test that
 * only checked the returned DTO would pass through all of it.
 */
function githubRepoResponse(string $fullName = 'acme/widget', string $defaultBranch = 'main'): array
{
    return [
        'id' => 42,
        'full_name' => $fullName,
        'name' => str($fullName)->after('/')->toString(),
        'description' => 'd',
        'default_branch' => $defaultBranch,
        'owner' => ['id' => 7, 'login' => str($fullName)->before('/')->toString(), 'avatar_url' => 'a'],
        'created_at' => '2020-01-01T00:00:00Z',
        'updated_at' => '2020-01-02T00:00:00Z',
    ];
}

it('generates from a template repository', function () {
    Http::fake(['*/repos/roundly-consulting/package-template/generate' => Http::response(githubRepoResponse())]);

    $repo = github()->createRepository(new NewRepository(
        name: 'widget',
        private: true,
        description: 'd',
        owner: 'acme',
        template: 'roundly-consulting/package-template',
    ));

    expect($repo)->toBeInstanceOf(Repository::class)->path->toBe('acme/widget');

    Http::assertSent(function (Request $request): bool {
        expect($request->url())->toContain('/repos/roundly-consulting/package-template/generate');

        return $request->method() === 'POST'
            && $request['owner'] === 'acme'
            && $request['name'] === 'widget'
            && $request['private'] === true
            && $request['description'] === 'd'
            && $request['include_all_branches'] === false;
    });
});

it('omits the owner on the template route when none was given', function () {
    Http::fake(['*/generate' => Http::response(githubRepoResponse('octocat/widget'))]);

    github()->createRepository(new NewRepository(name: 'widget', template: 'roundly-consulting/package-template'));

    // Not `'owner' => null`: GitHub answers 422 for an explicit null here, so the field
    // has to be absent rather than empty.
    Http::assertSent(fn (Request $r): bool => ! array_key_exists('owner', (array) $r->data()));
});

it('creates in an organization', function () {
    Http::fake(['*/orgs/acme/repos' => Http::response(githubRepoResponse())]);

    $repo = github()->createRepository(new NewRepository(
        name: 'widget',
        private: true,
        description: 'd',
        owner: 'acme',
        autoInit: true,
    ));

    expect($repo->path)->toBe('acme/widget');

    Http::assertSent(function (Request $request): bool {
        expect($request->url())->toContain('/orgs/acme/repos');

        return $request->method() === 'POST'
            && $request['name'] === 'widget'
            && $request['private'] === true
            && $request['auto_init'] === true;
    });
});

it('creates under the authenticated user when no owner is given', function () {
    Http::fake(['*/user/repos' => Http::response(githubRepoResponse('octocat/widget'))]);

    github()->createRepository(new NewRepository(name: 'widget', autoInit: true));

    Http::assertSent(function (Request $request): bool {
        expect($request->url())->toContain('/user/repos');

        return $request->method() === 'POST' && $request['auto_init'] === true;
    });
});

it('keeps the pre-existing user route untouched', function () {
    Http::fake(['*/user/repos' => Http::response(githubRepoResponse('octocat/acme'))]);

    // The three-argument positional form every existing caller uses.
    github()->createRepository(new NewRepository('acme', true, 'd'));

    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/user/repos')
        && $r['name'] === 'acme'
        && $r['private'] === true
        && $r['auto_init'] === false);
});

it('sends auto_init and default_branch on neither field of the generate route', function () {
    Http::fake(['*/generate' => Http::response(githubRepoResponse())]);

    github()->createRepository(new NewRepository(
        name: 'widget',
        owner: 'acme',
        template: 'roundly-consulting/package-template',
        autoInit: true,
        defaultBranch: 'main',
    ));

    // `/generate` accepts neither, and GitHub drops unknown members without complaint —
    // so sending them would look like it worked.
    Http::assertSent(function (Request $request): bool {
        $body = (array) $request->data();

        return ! array_key_exists('auto_init', $body) && ! array_key_exists('default_branch', $body);
    });
});

it('never sends default_branch on the creation body of any route', function () {
    Http::fake([
        '*/orgs/acme/repos' => Http::response(githubRepoResponse('acme/widget', 'main')),
        '*/repos/acme/widget/branches/main/rename' => Http::response(['name' => 'develop']),
    ]);

    github()->createRepository(new NewRepository(
        name: 'widget',
        owner: 'acme',
        autoInit: true,
        defaultBranch: 'develop',
    ));

    Http::assertSent(fn (Request $r): bool => ! str_contains($r->url(), 'rename')
        || ! array_key_exists('default_branch', (array) $r->data()));
});

it('renames the initial branch to the requested default branch', function () {
    Http::fake([
        '*/orgs/acme/repos' => Http::response(githubRepoResponse('acme/widget', 'main')),
        '*/repos/acme/widget/branches/main/rename' => Http::response(['name' => 'develop']),
    ]);

    $repo = github()->createRepository(new NewRepository(
        name: 'widget',
        owner: 'acme',
        autoInit: true,
        defaultBranch: 'develop',
    ));

    // The returned DTO must describe the repository that now exists, not the creation
    // response that is already stale by the time the rename returns.
    expect($repo->defaultBranch)->toBe('develop');

    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/repos/acme/widget/branches/main/rename')
        && $r->method() === 'POST'
        && $r['new_name'] === 'develop');
});

it('does not rename when the created branch already has the requested name', function () {
    Http::fake(['*/orgs/acme/repos' => Http::response(githubRepoResponse('acme/widget', 'develop'))]);

    github()->createRepository(new NewRepository(
        name: 'widget',
        owner: 'acme',
        autoInit: true,
        defaultBranch: 'develop',
    ));

    // Renaming a branch to its own name is a 422.
    Http::assertSentCount(1);
});

it('refuses a template selector on gitlab and bitbucket', function () {
    Http::preventStrayRequests();

    $input = new NewRepository(name: 'widget', template: 'roundly-consulting/package-template');

    expect(fn () => gitlab()->createRepository($input))->toThrow(FeatureNotSupportedException::class)
        ->and(fn () => bitbucket()->createRepository($input))->toThrow(FeatureNotSupportedException::class);
});

it('declares template generation on github only', function () {
    expect(github()->supports(Feature::GenerateFromTemplate))->toBeTrue()
        ->and(gitlab()->supports(Feature::GenerateFromTemplate))->toBeFalse()
        ->and(bitbucket()->supports(Feature::GenerateFromTemplate))->toBeFalse();
});

it('maps the owner to a gitlab namespace id', function () {
    Http::fake(['*/api/v4/projects' => Http::response([
        'id' => 5, 'path_with_namespace' => 'acme/widget', 'path' => 'widget',
        'description' => null, 'default_branch' => 'develop',
        'namespace' => ['id' => 42, 'path' => 'acme'],
        'created_at' => '2020-01-01T00:00:00Z', 'last_activity_at' => '2020-01-02T00:00:00Z',
    ])]);

    gitlab()->createRepository(new NewRepository(
        name: 'widget',
        owner: '42',
        autoInit: true,
        defaultBranch: 'develop',
    ));

    Http::assertSent(fn (Request $r): bool => $r['namespace_id'] === 42
        && $r['initialize_with_readme'] === true
        && $r['default_branch'] === 'develop');
});

it('refuses a non-numeric gitlab owner rather than creating in the wrong namespace', function () {
    Http::preventStrayRequests();

    gitlab()->createRepository(new NewRepository(name: 'widget', owner: 'acme'));
})->throws(InvalidArgumentException::class, 'numeric namespace id');

it('creates a bitbucket repository in a workspace', function () {
    Http::fake(['*/2.0/repositories/acme/widget' => Http::response([
        'uuid' => '{u}', 'full_name' => 'acme/widget', 'description' => 'd',
        'mainbranch' => ['name' => 'main'],
        'owner' => ['uuid' => '{o}', 'username' => 'acme'],
        'created_on' => '2020-01-01T00:00:00Z', 'updated_on' => '2020-01-02T00:00:00Z',
    ])]);

    bitbucket()->createRepository(new NewRepository(name: 'widget', private: true, description: 'd', owner: 'acme'));

    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/2.0/repositories/acme/widget')
        && $r['is_private'] === true);
});

it('refuses an initial commit on bitbucket rather than dropping it', function () {
    Http::preventStrayRequests();

    bitbucket()->createRepository(new NewRepository(name: 'widget', owner: 'acme', autoInit: true));
})->throws(FeatureNotSupportedException::class);

it('reports a missing permission as a credential failure, not github\'s message', function () {
    Http::fake(['*/orgs/acme/repos' => Http::response(
        ['message' => 'Resource not accessible by integration', 'documentation_url' => 'https://docs.github.com/x'],
        403,
    )]);

    $create = fn () => github()->createRepository(new NewRepository(name: 'widget', owner: 'acme'));

    expect($create)->toThrow(InvalidCredentialsException::class);

    try {
        $create();
    } catch (InvalidCredentialsException $exception) {
        // The consumer's requirement: the app's own words, not the provider's.
        expect($exception->getMessage())
            ->toContain('administration: write')
            ->toContain('acme')
            ->not->toContain('Resource not accessible by integration')
            ->not->toContain('documentation_url');
    }
});

it('leaves a throttled 403 retryable instead of condemning the credential', function () {
    Http::fake(['*/orgs/acme/repos' => Http::response(
        ['message' => 'You have exceeded a secondary rate limit'],
        403,
        ['Retry-After' => '60'],
    )]);

    // A consumer treats InvalidCredentialsException as "a human must reconnect". Reporting
    // one throttled minute that way would tear down a credential that was never broken.
    github()->createRepository(new NewRepository(name: 'widget', owner: 'acme'));
})->throws(RequestException::class);

it('leaves other upstream failures exactly as they were', function () {
    Http::fake(['*/orgs/acme/repos' => Http::response(['message' => 'Validation Failed'], 422)]);

    github()->createRepository(new NewRepository(name: 'widget', owner: 'acme'));
})->throws(RequestException::class);
