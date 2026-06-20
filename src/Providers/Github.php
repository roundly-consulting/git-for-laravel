<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Providers;

use Illuminate\Support\Carbon;
use Illuminate\Support\LazyCollection;
use RoundlyConsulting\Git\Dto\Author;
use RoundlyConsulting\Git\Dto\Comment;
use RoundlyConsulting\Git\Dto\Commit;
use RoundlyConsulting\Git\Dto\Comparison;
use RoundlyConsulting\Git\Dto\ComparisonFile;
use RoundlyConsulting\Git\Dto\Contributor;
use RoundlyConsulting\Git\Dto\Credentials\Credentials;
use RoundlyConsulting\Git\Dto\Credentials\Token;
use RoundlyConsulting\Git\Dto\FileContent;
use RoundlyConsulting\Git\Dto\Input\NewBranch;
use RoundlyConsulting\Git\Dto\Input\NewComment;
use RoundlyConsulting\Git\Dto\Input\NewFile;
use RoundlyConsulting\Git\Dto\Input\NewPullRequest;
use RoundlyConsulting\Git\Dto\Input\NewRelease;
use RoundlyConsulting\Git\Dto\Input\NewRepository;
use RoundlyConsulting\Git\Dto\Input\NewTag;
use RoundlyConsulting\Git\Dto\Input\NewWebhook;
use RoundlyConsulting\Git\Dto\Input\UpdatedFile;
use RoundlyConsulting\Git\Dto\Issue;
use RoundlyConsulting\Git\Dto\Owner;
use RoundlyConsulting\Git\Dto\Page;
use RoundlyConsulting\Git\Dto\PullRequest;
use RoundlyConsulting\Git\Dto\Release;
use RoundlyConsulting\Git\Dto\Repository;
use RoundlyConsulting\Git\Dto\Tag;
use RoundlyConsulting\Git\Dto\Webhook;
use RoundlyConsulting\Git\Enums\Feature;
use RoundlyConsulting\Git\Query\CommitQuery;

class Github extends BaseProvider
{
    protected function key(): string
    {
        return 'github';
    }

    public function user(): Owner
    {
        $user = $this->get('/user')->json();

        return new Owner(
            id: (string) $user['id'],
            name: $user['login'],
            avatar: $user['avatar_url'] ?? null,
        );
    }

    /** @return Page<Repository> */
    public function repositories(int $perPage = 30): Page
    {
        return $this->paginate(
            url: '/user/repos',
            query: [],
            page: 1,
            perPage: $perPage,
            map: fn (array $repository): Repository => $this->createRepositoryDto($repository),
        );
    }

    /** @return LazyCollection<int, Repository> */
    public function allRepositories(int $perPage = 30): LazyCollection
    {
        return $this->lazyPages(fn (int $page): Page => $this->paginate(
            url: '/user/repos',
            query: [],
            page: $page,
            perPage: $perPage,
            map: fn (array $repository): Repository => $this->createRepositoryDto($repository),
        ));
    }

    public function repository(string $path): Repository
    {
        return $this->createRepositoryDto($this->get("/repos/{$path}")->json());
    }

    /** @return Page<string> */
    public function branches(string $path, int $perPage = 30): Page
    {
        return $this->paginate(
            url: "/repos/{$path}/branches",
            query: [],
            page: 1,
            perPage: $perPage,
            map: fn (array $item): string => $item['name'],
        );
    }

    public function commits(string $path): CommitQuery
    {
        return new CommitQuery(function (array $filters, int $page, int $perPage) use ($path): Page {
            $query = [];

            if (isset($filters['branch'])) {
                $query['sha'] = $filters['branch'];
            }

            if (isset($filters['author'])) {
                $query['author'] = $filters['author'];
            }

            if (isset($filters['path'])) {
                $query['path'] = $filters['path'];
            }

            if (isset($filters['since'])) {
                $query['since'] = $filters['since'];
            }

            if (isset($filters['until'])) {
                $query['until'] = $filters['until'];
            }

            return $this->paginate(
                url: "/repos/{$path}/commits",
                query: $query,
                page: $page,
                perPage: $perPage,
                map: fn (array $commit): Commit => $this->createCommitDto($commit),
            );
        });
    }

    public function commit(string $path, string $commit): Commit
    {
        return $this->createCommitDto($this->get("/repos/{$path}/commits/{$commit}")->json());
    }

    /** @return Page<PullRequest> */
    public function pullRequests(string $path, string $state = 'open', int $perPage = 30): Page
    {
        $this->guardSupported(Feature::ListPullRequests);

        return $this->paginate(
            url: "/repos/{$path}/pulls",
            query: ['state' => $state],
            page: 1,
            perPage: $perPage,
            map: fn (array $pr): PullRequest => $this->createPullRequestDto($pr),
        );
    }

    public function pullRequest(string $path, int $number): PullRequest
    {
        $this->guardSupported(Feature::FindPullRequest);

        return $this->createPullRequestDto($this->get("/repos/{$path}/pulls/{$number}")->json());
    }

    /** @return Page<Issue> */
    public function issues(string $path, string $state = 'open', int $perPage = 30): Page
    {
        $this->guardSupported(Feature::ListIssues);

        return $this->paginate(
            url: "/repos/{$path}/issues",
            query: ['state' => $state],
            page: 1,
            perPage: $perPage,
            map: fn (array $issue): Issue => $this->createIssueDto($issue),
        );
    }

    public function issue(string $path, int $number): Issue
    {
        $this->guardSupported(Feature::FindIssue);

        return $this->createIssueDto($this->get("/repos/{$path}/issues/{$number}")->json());
    }

    /** @return Page<Tag> */
    public function tags(string $path, int $perPage = 30): Page
    {
        $this->guardSupported(Feature::ListTags);

        return $this->paginate(
            url: "/repos/{$path}/tags",
            query: [],
            page: 1,
            perPage: $perPage,
            map: fn (array $tag): Tag => new Tag(
                name: $tag['name'],
                sha: $tag['commit']['sha'] ?? null,
                url: $tag['commit']['url'] ?? null,
            ),
        );
    }

    /** @return Page<Release> */
    public function releases(string $path, int $perPage = 30): Page
    {
        $this->guardSupported(Feature::ListReleases);

        return $this->paginate(
            url: "/repos/{$path}/releases",
            query: [],
            page: 1,
            perPage: $perPage,
            map: fn (array $release): Release => $this->createReleaseDto($release),
        );
    }

    public function release(string $path, string $tagOrId): Release
    {
        $this->guardSupported(Feature::FindRelease);

        return $this->createReleaseDto($this->get("/repos/{$path}/releases/tags/{$tagOrId}")->json());
    }

    public function contents(string $path, string $filePath, ?string $ref = null): FileContent
    {
        $this->guardSupported(Feature::FileContents);

        $response = $this->get("/repos/{$path}/contents/{$filePath}", $ref !== null ? ['ref' => $ref] : []);
        $file = $response->json();

        return new FileContent(
            path: $file['path'],
            content: (string) base64_decode((string) ($file['content'] ?? ''), true),
            sha: $file['sha'] ?? null,
            size: (int) ($file['size'] ?? 0),
            url: $file['html_url'] ?? null,
        );
    }

    public function compare(string $path, string $base, string $head): Comparison
    {
        $this->guardSupported(Feature::Compare);

        $data = $this->get("/repos/{$path}/compare/{$base}...{$head}")->json();

        /** @var list<ComparisonFile> $files */
        $files = array_map(fn (array $file): ComparisonFile => new ComparisonFile(
            filename: $file['filename'],
            status: $file['status'],
            additions: (int) ($file['additions'] ?? 0),
            deletions: (int) ($file['deletions'] ?? 0),
        ), $data['files'] ?? []);

        return new Comparison(
            base: $base,
            head: $head,
            aheadBy: (int) ($data['ahead_by'] ?? 0),
            behindBy: (int) ($data['behind_by'] ?? 0),
            files: $files,
        );
    }

    /** @return Page<Contributor> */
    public function contributors(string $path, int $perPage = 30): Page
    {
        $this->guardSupported(Feature::ListContributors);

        return $this->paginate(
            url: "/repos/{$path}/contributors",
            query: [],
            page: 1,
            perPage: $perPage,
            map: fn (array $c): Contributor => new Contributor(
                id: (string) $c['id'],
                name: $c['login'],
                avatar: $c['avatar_url'] ?? null,
                contributions: (int) ($c['contributions'] ?? 0),
            ),
        );
    }

    /** @return array<string, int> */
    public function languages(string $path): array
    {
        $this->guardSupported(Feature::Languages);

        /** @var array<string, int> $languages */
        $languages = $this->get("/repos/{$path}/languages")->json();

        return $languages;
    }

    /** @return Page<Repository> */
    public function searchRepositories(string $query, int $perPage = 30): Page
    {
        $this->guardSupported(Feature::SearchRepositories);

        return $this->paginate(
            url: '/search/repositories',
            query: ['q' => $query],
            page: 1,
            perPage: $perPage,
            map: fn (array $repository): Repository => $this->createRepositoryDto($repository),
            itemsKey: 'items',
        );
    }

    public function createRepository(NewRepository $data): Repository
    {
        $this->guardSupported(Feature::CreateRepository);
        $this->guardAuthenticated();

        $response = $this->send('POST', '/user/repos', [
            'name' => $data->name,
            'private' => $data->private,
            'description' => $data->description,
        ]);

        return $this->createRepositoryDto($response->json());
    }

    public function createBranch(string $path, NewBranch $data): string
    {
        $this->guardSupported(Feature::CreateBranch);
        $this->guardAuthenticated();

        $base = $this->get("/repos/{$path}/git/refs/heads/{$data->fromRef}")->json();

        $response = $this->send('POST', "/repos/{$path}/git/refs", [
            'ref' => "refs/heads/{$data->name}",
            'sha' => $base['object']['sha'],
        ]);

        return $response->json('ref');
    }

    public function createFile(string $path, NewFile $data): Commit
    {
        $this->guardSupported(Feature::CreateFile);
        $this->guardAuthenticated();

        $response = $this->send('PUT', "/repos/{$path}/contents/{$data->path}", [
            'message' => $data->message,
            'content' => base64_encode($data->content),
            'branch' => $data->branch,
        ]);

        return $this->createCommitFromContentResponse($response->json('commit'));
    }

    public function updateFile(string $path, UpdatedFile $data): Commit
    {
        $this->guardSupported(Feature::UpdateFile);
        $this->guardAuthenticated();

        $response = $this->send('PUT', "/repos/{$path}/contents/{$data->path}", [
            'message' => $data->message,
            'content' => base64_encode($data->content),
            'branch' => $data->branch,
            'sha' => $data->sha,
        ]);

        return $this->createCommitFromContentResponse($response->json('commit'));
    }

    public function createPullRequest(string $path, NewPullRequest $data): PullRequest
    {
        $this->guardSupported(Feature::CreatePullRequest);
        $this->guardAuthenticated();

        $response = $this->send('POST', "/repos/{$path}/pulls", [
            'title' => $data->title,
            'head' => $data->head,
            'base' => $data->base,
            'body' => $data->body,
        ]);

        return $this->createPullRequestDto($response->json());
    }

    public function comment(string $path, NewComment $data): Comment
    {
        $this->guardSupported(Feature::CreateComment);
        $this->guardAuthenticated();

        $response = $this->send('POST', "/repos/{$path}/issues/{$data->number}/comments", [
            'body' => $data->body,
        ]);

        $comment = $response->json();

        return new Comment(
            id: (string) $comment['id'],
            body: $comment['body'],
            author: isset($comment['user']) ? new Author(
                name: $comment['user']['login'],
                email: '',
                avatar: $comment['user']['avatar_url'] ?? null,
            ) : null,
            url: $comment['html_url'] ?? null,
            createdAt: Carbon::parse($comment['created_at']),
        );
    }

    public function createRelease(string $path, NewRelease $data): Release
    {
        $this->guardSupported(Feature::CreateRelease);
        $this->guardAuthenticated();

        $response = $this->send('POST', "/repos/{$path}/releases", [
            'tag_name' => $data->tagName,
            'name' => $data->name,
            'body' => $data->body,
            'draft' => $data->draft,
            'prerelease' => $data->prerelease,
        ]);

        return $this->createReleaseDto($response->json());
    }

    public function createTag(string $path, NewTag $data): Tag
    {
        $this->guardSupported(Feature::CreateTag);
        $this->guardAuthenticated();

        $response = $this->send('POST', "/repos/{$path}/git/refs", [
            'ref' => "refs/tags/{$data->name}",
            'sha' => $data->ref,
        ]);

        $ref = $response->json();

        return new Tag(
            name: $data->name,
            sha: $ref['object']['sha'] ?? $data->ref,
            url: $ref['url'] ?? null,
        );
    }

    public function createWebhook(string $path, NewWebhook $data): Webhook
    {
        $this->guardSupported(Feature::CreateWebhook);
        $this->guardAuthenticated();

        $response = $this->send('POST', "/repos/{$path}/hooks", [
            'config' => array_filter([
                'url' => $data->url,
                'content_type' => 'json',
                'secret' => $data->secret,
            ], fn ($value): bool => $value !== null),
            'events' => $data->events,
            'active' => $data->active,
        ]);

        $hook = $response->json();

        return new Webhook(
            id: (string) $hook['id'],
            url: $hook['config']['url'] ?? $data->url,
            events: $hook['events'] ?? $data->events,
            active: (bool) ($hook['active'] ?? $data->active),
        );
    }

    public function deleteWebhook(string $path, string $id): void
    {
        $this->guardSupported(Feature::DeleteWebhook);
        $this->guardAuthenticated();

        $this->send('DELETE', "/repos/{$path}/hooks/{$id}");
    }

    public function cloneUrlForRepository(string $path, string $username, Credentials $credentials): string
    {
        return $this->buildCloneUrl(
            baseUrl: $this->cloneBaseUrl(),
            user: 'token',
            secret: (string) $credentials->credentials?->getValue(),
            path: $path,
        );
    }

    protected function cloneBaseUrl(): string
    {
        return str((string) config('git.providers.github.url'))->replace('api.', '')->toString();
    }

    /** @return list<Feature> */
    public function features(): array
    {
        return [
            Feature::ListRepositories,
            Feature::FindRepository,
            Feature::ListCommits,
            Feature::FindCommit,
            Feature::ListRepositoryBranches,
            Feature::ListPullRequests,
            Feature::FindPullRequest,
            Feature::ListIssues,
            Feature::FindIssue,
            Feature::ListTags,
            Feature::ListReleases,
            Feature::FindRelease,
            Feature::FileContents,
            Feature::Compare,
            Feature::ListContributors,
            Feature::Languages,
            Feature::SearchRepositories,
            Feature::CreateRepository,
            Feature::CreateBranch,
            Feature::CreateFile,
            Feature::UpdateFile,
            Feature::CreatePullRequest,
            Feature::CreateComment,
            Feature::CreateRelease,
            Feature::CreateTag,
            Feature::CreateWebhook,
            Feature::DeleteWebhook,
        ];
    }

    /** @return list<class-string<Credentials>> */
    public function authenticationMethods(): array
    {
        return [
            Token::class,
        ];
    }

    /** @param array<string, mixed> $repository */
    protected function createRepositoryDto(array $repository): Repository
    {
        return new Repository(
            id: (string) $repository['id'],
            path: $repository['full_name'],
            name: $repository['name'],
            description: $repository['description'] ?? null,
            defaultBranch: $repository['default_branch'],
            owner: new Owner(
                id: (string) $repository['owner']['id'],
                name: $repository['owner']['login'],
                avatar: $repository['owner']['avatar_url'] ?? null,
            ),
            createdAt: $createdAt = Carbon::parse($repository['created_at']),
            lastActivityAt: $repository['pushed_at'] ? Carbon::parse($repository['pushed_at']) : $createdAt,
        );
    }

    /** @param array<string, mixed> $commit */
    protected function createCommitDto(array $commit): Commit
    {
        return new Commit(
            sha: $commit['sha'],
            message: $commit['commit']['message'],
            author: new Author(
                name: $commit['commit']['author']['name'],
                email: $commit['commit']['author']['email'],
                avatar: $commit['author']['avatar_url'] ?? null,
            ),
            url: $commit['html_url'] ?? null,
            commitAt: Carbon::parse($commit['commit']['author']['date']),
        );
    }

    /** @param array<string, mixed> $commit */
    protected function createCommitFromContentResponse(array $commit): Commit
    {
        return new Commit(
            sha: $commit['sha'],
            message: $commit['message'],
            author: new Author(
                name: $commit['author']['name'] ?? '',
                email: $commit['author']['email'] ?? '',
                avatar: null,
            ),
            url: $commit['html_url'] ?? null,
            commitAt: Carbon::parse($commit['author']['date'] ?? 'now'),
        );
    }

    /** @param array<string, mixed> $pr */
    protected function createPullRequestDto(array $pr): PullRequest
    {
        return new PullRequest(
            id: (string) $pr['id'],
            number: (int) $pr['number'],
            title: $pr['title'],
            body: $pr['body'] ?? null,
            state: $pr['state'],
            sourceBranch: $pr['head']['ref'] ?? '',
            targetBranch: $pr['base']['ref'] ?? '',
            author: isset($pr['user']) ? new Author(
                name: $pr['user']['login'],
                email: '',
                avatar: $pr['user']['avatar_url'] ?? null,
            ) : null,
            url: $pr['html_url'] ?? null,
            createdAt: Carbon::parse($pr['created_at']),
        );
    }

    /** @param array<string, mixed> $issue */
    protected function createIssueDto(array $issue): Issue
    {
        return new Issue(
            id: (string) $issue['id'],
            number: (int) $issue['number'],
            title: $issue['title'],
            body: $issue['body'] ?? null,
            state: $issue['state'],
            author: isset($issue['user']) ? new Author(
                name: $issue['user']['login'],
                email: '',
                avatar: $issue['user']['avatar_url'] ?? null,
            ) : null,
            url: $issue['html_url'] ?? null,
            createdAt: Carbon::parse($issue['created_at']),
        );
    }

    /** @param array<string, mixed> $release */
    protected function createReleaseDto(array $release): Release
    {
        return new Release(
            id: (string) $release['id'],
            tagName: $release['tag_name'],
            name: $release['name'] ?? null,
            body: $release['body'] ?? null,
            draft: (bool) ($release['draft'] ?? false),
            prerelease: (bool) ($release['prerelease'] ?? false),
            url: $release['html_url'] ?? null,
            createdAt: isset($release['created_at']) ? Carbon::parse($release['created_at']) : null,
        );
    }
}
