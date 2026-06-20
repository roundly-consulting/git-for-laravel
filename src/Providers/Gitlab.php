<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Providers;

use Illuminate\Http\Client\Response;
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

class Gitlab extends BaseProvider
{
    protected function key(): string
    {
        return 'gitlab';
    }

    public function user(): Owner
    {
        $user = $this->get('/api/v4/user')->json();

        return new Owner(
            id: (string) $user['id'],
            name: $user['username'],
            avatar: $user['avatar_url'] ?? null,
        );
    }

    /** @return Page<Repository> */
    public function repositories(int $perPage = 30): Page
    {
        return $this->paginate(
            url: '/api/v4/projects',
            query: ['membership' => 'true'],
            page: 1,
            perPage: $perPage,
            map: fn (array $repository): Repository => $this->createRepositoryDto($repository),
        );
    }

    /** @return LazyCollection<int, Repository> */
    public function allRepositories(int $perPage = 30): LazyCollection
    {
        return $this->lazyPages(fn (int $page): Page => $this->paginate(
            url: '/api/v4/projects',
            query: ['membership' => 'true'],
            page: $page,
            perPage: $perPage,
            map: fn (array $repository): Repository => $this->createRepositoryDto($repository),
        ));
    }

    public function repository(string $path): Repository
    {
        return $this->createRepositoryDto(
            $this->get('/api/v4/projects/'.$this->encode($path))->json()
        );
    }

    /** @return Page<string> */
    public function branches(string $path, int $perPage = 30): Page
    {
        return $this->paginate(
            url: '/api/v4/projects/'.$this->encode($path).'/repository/branches',
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
                $query['ref_name'] = $filters['branch'];
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
                url: '/api/v4/projects/'.$this->encode($path).'/repository/commits',
                query: $query,
                page: $page,
                perPage: $perPage,
                map: fn (array $commit): Commit => $this->createCommitDto($commit),
            );
        });
    }

    public function commit(string $path, string $commit): Commit
    {
        return $this->createCommitDto(
            $this->get('/api/v4/projects/'.$this->encode($path).'/repository/commits/'.rawurlencode($commit))->json()
        );
    }

    /** @return Page<PullRequest> */
    public function pullRequests(string $path, string $state = 'open', int $perPage = 30): Page
    {
        $this->guardSupported(Feature::ListPullRequests);

        return $this->paginate(
            url: '/api/v4/projects/'.$this->encode($path).'/merge_requests',
            query: ['state' => $this->mapState($state)],
            page: 1,
            perPage: $perPage,
            map: fn (array $mr): PullRequest => $this->createPullRequestDto($mr),
        );
    }

    public function pullRequest(string $path, int $number): PullRequest
    {
        $this->guardSupported(Feature::FindPullRequest);

        return $this->createPullRequestDto(
            $this->get('/api/v4/projects/'.$this->encode($path)."/merge_requests/{$number}")->json()
        );
    }

    /** @return Page<Issue> */
    public function issues(string $path, string $state = 'open', int $perPage = 30): Page
    {
        $this->guardSupported(Feature::ListIssues);

        return $this->paginate(
            url: '/api/v4/projects/'.$this->encode($path).'/issues',
            query: ['state' => $this->mapState($state)],
            page: 1,
            perPage: $perPage,
            map: fn (array $issue): Issue => $this->createIssueDto($issue),
        );
    }

    public function issue(string $path, int $number): Issue
    {
        $this->guardSupported(Feature::FindIssue);

        return $this->createIssueDto(
            $this->get('/api/v4/projects/'.$this->encode($path)."/issues/{$number}")->json()
        );
    }

    /** @return Page<Tag> */
    public function tags(string $path, int $perPage = 30): Page
    {
        $this->guardSupported(Feature::ListTags);

        return $this->paginate(
            url: '/api/v4/projects/'.$this->encode($path).'/repository/tags',
            query: [],
            page: 1,
            perPage: $perPage,
            map: fn (array $tag): Tag => new Tag(
                name: $tag['name'],
                sha: $tag['commit']['id'] ?? null,
                url: null,
            ),
        );
    }

    /** @return Page<Release> */
    public function releases(string $path, int $perPage = 30): Page
    {
        $this->guardSupported(Feature::ListReleases);

        return $this->paginate(
            url: '/api/v4/projects/'.$this->encode($path).'/releases',
            query: [],
            page: 1,
            perPage: $perPage,
            map: fn (array $release): Release => $this->createReleaseDto($release),
        );
    }

    public function release(string $path, string $tagOrId): Release
    {
        $this->guardSupported(Feature::FindRelease);

        return $this->createReleaseDto(
            $this->get('/api/v4/projects/'.$this->encode($path).'/releases/'.rawurlencode($tagOrId))->json()
        );
    }

    public function contents(string $path, string $filePath, ?string $ref = null): FileContent
    {
        $this->guardSupported(Feature::FileContents);

        $file = $this->get(
            '/api/v4/projects/'.$this->encode($path).'/repository/files/'.rawurlencode($filePath),
            ['ref' => $ref ?? 'main'],
        )->json();

        return new FileContent(
            path: $file['file_path'],
            content: (string) base64_decode((string) ($file['content'] ?? ''), true),
            sha: $file['blob_id'] ?? null,
            size: (int) ($file['size'] ?? 0),
            url: null,
        );
    }

    public function compare(string $path, string $base, string $head): Comparison
    {
        $this->guardSupported(Feature::Compare);

        $data = $this->get(
            '/api/v4/projects/'.$this->encode($path).'/repository/compare',
            ['from' => $base, 'to' => $head],
        )->json();

        /** @var list<ComparisonFile> $files */
        $files = array_map(fn (array $diff): ComparisonFile => new ComparisonFile(
            filename: $diff['new_path'],
            status: ($diff['new_file'] ?? false) ? 'added' : (($diff['deleted_file'] ?? false) ? 'removed' : 'modified'),
            additions: 0,
            deletions: 0,
        ), $data['diffs'] ?? []);

        return new Comparison(
            base: $base,
            head: $head,
            aheadBy: count($data['commits'] ?? []),
            behindBy: 0,
            files: $files,
        );
    }

    /** @return Page<Contributor> */
    public function contributors(string $path, int $perPage = 30): Page
    {
        $this->guardSupported(Feature::ListContributors);

        return $this->paginate(
            url: '/api/v4/projects/'.$this->encode($path).'/repository/contributors',
            query: [],
            page: 1,
            perPage: $perPage,
            map: fn (array $c): Contributor => new Contributor(
                id: $c['email'] ?? $c['name'],
                name: $c['name'],
                avatar: null,
                contributions: (int) ($c['commits'] ?? 0),
            ),
        );
    }

    /** @return array<string, int> */
    public function languages(string $path): array
    {
        $this->guardSupported(Feature::Languages);

        /** @var array<string, float> $languages */
        $languages = $this->get('/api/v4/projects/'.$this->encode($path).'/languages')->json();

        return array_map(fn (float $percent): int => (int) round($percent), $languages);
    }

    /** @return Page<Repository> */
    public function searchRepositories(string $query, int $perPage = 30): Page
    {
        $this->guardSupported(Feature::SearchRepositories);

        return $this->paginate(
            url: '/api/v4/projects',
            query: ['search' => $query],
            page: 1,
            perPage: $perPage,
            map: fn (array $repository): Repository => $this->createRepositoryDto($repository),
        );
    }

    public function createRepository(NewRepository $data): Repository
    {
        $this->guardSupported(Feature::CreateRepository);
        $this->guardAuthenticated();

        $response = $this->send('POST', '/api/v4/projects', [
            'name' => $data->name,
            'visibility' => $data->private ? 'private' : 'public',
            'description' => $data->description,
        ]);

        return $this->createRepositoryDto($response->json());
    }

    public function createBranch(string $path, NewBranch $data): string
    {
        $this->guardSupported(Feature::CreateBranch);
        $this->guardAuthenticated();

        $response = $this->send('POST', '/api/v4/projects/'.$this->encode($path).'/repository/branches', [
            'branch' => $data->name,
            'ref' => $data->fromRef,
        ]);

        return $response->json('name');
    }

    public function createFile(string $path, NewFile $data): Commit
    {
        $this->guardSupported(Feature::CreateFile);
        $this->guardAuthenticated();

        $response = $this->send(
            'POST',
            '/api/v4/projects/'.$this->encode($path).'/repository/files/'.rawurlencode($data->path),
            [
                'branch' => $data->branch,
                'content' => $data->content,
                'commit_message' => $data->message,
            ],
        );

        return $this->createCommitFromFileResponse($response, $data->message);
    }

    public function updateFile(string $path, UpdatedFile $data): Commit
    {
        $this->guardSupported(Feature::UpdateFile);
        $this->guardAuthenticated();

        $response = $this->send(
            'PUT',
            '/api/v4/projects/'.$this->encode($path).'/repository/files/'.rawurlencode($data->path),
            [
                'branch' => $data->branch,
                'content' => $data->content,
                'commit_message' => $data->message,
            ],
        );

        return $this->createCommitFromFileResponse($response, $data->message);
    }

    public function createPullRequest(string $path, NewPullRequest $data): PullRequest
    {
        $this->guardSupported(Feature::CreatePullRequest);
        $this->guardAuthenticated();

        $response = $this->send('POST', '/api/v4/projects/'.$this->encode($path).'/merge_requests', [
            'title' => $data->title,
            'source_branch' => $data->head,
            'target_branch' => $data->base,
            'description' => $data->body,
        ]);

        return $this->createPullRequestDto($response->json());
    }

    public function comment(string $path, NewComment $data): Comment
    {
        $this->guardSupported(Feature::CreateComment);
        $this->guardAuthenticated();

        $response = $this->send(
            'POST',
            '/api/v4/projects/'.$this->encode($path)."/merge_requests/{$data->number}/notes",
            ['body' => $data->body],
        );

        $note = $response->json();

        return new Comment(
            id: (string) $note['id'],
            body: $note['body'],
            author: isset($note['author']) ? new Author(
                name: $note['author']['username'],
                email: '',
                avatar: $note['author']['avatar_url'] ?? null,
            ) : null,
            url: null,
            createdAt: Carbon::parse($note['created_at']),
        );
    }

    public function createRelease(string $path, NewRelease $data): Release
    {
        $this->guardSupported(Feature::CreateRelease);
        $this->guardAuthenticated();

        $response = $this->send('POST', '/api/v4/projects/'.$this->encode($path).'/releases', [
            'tag_name' => $data->tagName,
            'name' => $data->name,
            'description' => $data->body,
        ]);

        return $this->createReleaseDto($response->json());
    }

    public function createTag(string $path, NewTag $data): Tag
    {
        $this->guardSupported(Feature::CreateTag);
        $this->guardAuthenticated();

        $response = $this->send('POST', '/api/v4/projects/'.$this->encode($path).'/repository/tags', [
            'tag_name' => $data->name,
            'ref' => $data->ref,
        ]);

        $tag = $response->json();

        return new Tag(
            name: $tag['name'],
            sha: $tag['commit']['id'] ?? $data->ref,
            url: null,
        );
    }

    public function createWebhook(string $path, NewWebhook $data): Webhook
    {
        $this->guardSupported(Feature::CreateWebhook);
        $this->guardAuthenticated();

        $response = $this->send('POST', '/api/v4/projects/'.$this->encode($path).'/hooks', [
            'url' => $data->url,
            'push_events' => in_array('push', $data->events, true),
            'token' => $data->secret,
            'enable_ssl_verification' => true,
        ]);

        $hook = $response->json();

        return new Webhook(
            id: (string) $hook['id'],
            url: $hook['url'] ?? $data->url,
            events: $data->events,
            active: (bool) ($hook['enable_ssl_verification'] ?? $data->active),
        );
    }

    public function deleteWebhook(string $path, string $id): void
    {
        $this->guardSupported(Feature::DeleteWebhook);
        $this->guardAuthenticated();

        $this->send('DELETE', '/api/v4/projects/'.$this->encode($path)."/hooks/{$id}");
    }

    public function cloneUrlForRepository(string $path, string $username, Credentials $credentials): string
    {
        return $this->buildCloneUrl(
            baseUrl: $this->cloneBaseUrl(),
            user: 'oauth2',
            secret: (string) $credentials->credentials?->getValue(),
            path: $path,
        );
    }

    protected function cloneBaseUrl(): string
    {
        return (string) config('git.providers.gitlab.url');
    }

    protected function hasMorePages(Response $response, int $count, int $perPage): bool
    {
        $next = $response->header('X-Next-Page');

        if ($next !== '') {
            return true;
        }

        // No paging headers (e.g. on faked responses): fall back to a full page.
        if ($response->header('X-Page') === '') {
            return $count >= $perPage;
        }

        return false;
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

    protected function encode(string $path): string
    {
        return rawurlencode($path);
    }

    protected function mapState(string $state): string
    {
        return match ($state) {
            'open' => 'opened',
            'closed' => 'closed',
            default => $state,
        };
    }

    /** @param array<string, mixed> $repository */
    protected function createRepositoryDto(array $repository): Repository
    {
        return new Repository(
            id: (string) $repository['id'],
            path: $repository['path_with_namespace'],
            name: $repository['path'],
            description: $repository['description'] ?? null,
            defaultBranch: $repository['default_branch'],
            owner: new Owner(
                id: (string) $repository['namespace']['id'],
                name: $repository['namespace']['path'],
                avatar: $repository['namespace']['avatar_url'] ?? null,
            ),
            createdAt: $createdAt = Carbon::parse($repository['created_at']),
            lastActivityAt: $repository['last_activity_at'] ? Carbon::parse($repository['last_activity_at']) : $createdAt,
        );
    }

    /** @param array<string, mixed> $commit */
    protected function createCommitDto(array $commit): Commit
    {
        return new Commit(
            sha: $commit['id'],
            message: $commit['message'],
            author: new Author(
                name: $commit['author_name'],
                email: $commit['author_email'],
                avatar: null,
            ),
            url: $commit['web_url'] ?? null,
            commitAt: Carbon::parse($commit['authored_date']),
        );
    }

    protected function createCommitFromFileResponse(Response $response, string $message): Commit
    {
        $data = $response->json();

        return new Commit(
            sha: $data['commit_id'] ?? ($data['file_path'] ?? ''),
            message: $message,
            author: new Author(name: '', email: '', avatar: null),
            url: null,
            commitAt: Carbon::now(),
        );
    }

    /** @param array<string, mixed> $mr */
    protected function createPullRequestDto(array $mr): PullRequest
    {
        return new PullRequest(
            id: (string) $mr['id'],
            number: (int) $mr['iid'],
            title: $mr['title'],
            body: $mr['description'] ?? null,
            state: $mr['state'],
            sourceBranch: $mr['source_branch'] ?? '',
            targetBranch: $mr['target_branch'] ?? '',
            author: isset($mr['author']) ? new Author(
                name: $mr['author']['username'],
                email: '',
                avatar: $mr['author']['avatar_url'] ?? null,
            ) : null,
            url: $mr['web_url'] ?? null,
            createdAt: Carbon::parse($mr['created_at']),
        );
    }

    /** @param array<string, mixed> $issue */
    protected function createIssueDto(array $issue): Issue
    {
        return new Issue(
            id: (string) $issue['id'],
            number: (int) $issue['iid'],
            title: $issue['title'],
            body: $issue['description'] ?? null,
            state: $issue['state'],
            author: isset($issue['author']) ? new Author(
                name: $issue['author']['username'],
                email: '',
                avatar: $issue['author']['avatar_url'] ?? null,
            ) : null,
            url: $issue['web_url'] ?? null,
            createdAt: Carbon::parse($issue['created_at']),
        );
    }

    /** @param array<string, mixed> $release */
    protected function createReleaseDto(array $release): Release
    {
        return new Release(
            id: (string) ($release['tag_name'] ?? ''),
            tagName: $release['tag_name'],
            name: $release['name'] ?? null,
            body: $release['description'] ?? null,
            draft: (bool) ($release['upcoming_release'] ?? false),
            prerelease: false,
            url: $release['_links']['self'] ?? null,
            createdAt: isset($release['created_at']) ? Carbon::parse($release['created_at']) : null,
        );
    }
}
