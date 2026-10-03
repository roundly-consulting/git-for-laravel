<!-- roundly-hero:start -->
<p align="center">
  <a href="https://roundly-consulting.com/open-source/docs/git-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=git-for-laravel">
    <img src="art/hero.png" alt="Git for Laravel — Roundly open source" width="100%">
  </a>
</p>
<!-- roundly-hero:end -->

<!-- roundly-badges:start -->
<p align="center">
  <a href="https://packagist.org/packages/roundly-consulting/git-for-laravel"><img src="https://img.shields.io/packagist/v/roundly-consulting/git-for-laravel?style=flat-square&label=release" alt="Latest release"></a>
  <a href="https://github.com/roundly-consulting/git-for-laravel/actions/workflows/run-tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/git-for-laravel/run-tests.yml?branch=main&style=flat-square&label=tests" alt="Tests"></a>
  <a href="https://github.com/roundly-consulting/git-for-laravel/actions/workflows/fix-php-code-style-issues.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/git-for-laravel/fix-php-code-style-issues.yml?branch=main&style=flat-square&label=code%20style" alt="Code style"></a>
  <a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/donate-support%20our%20open%20source-F24E29?style=flat-square&logo=stripe&logoColor=white" alt="Donate"></a>
  <a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/patreon-become%20a%20patron-F96854?style=flat-square&logo=patreon&logoColor=white" alt="Patreon"></a>
  <a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=git-for-laravel#crypto"><img src="https://img.shields.io/badge/crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=flat-square&logo=bitcoin&logoColor=white" alt="Crypto"></a>
</p>
<!-- roundly-badges:end -->

# Git for Laravel

One Laravel-native API for GitHub, GitLab and Bitbucket: page through repositories and commits,
read pull requests, issues, releases and files, open and merge pull requests, receive signed
webhooks as typed events, and authenticate with tokens, GitHub Apps or OAuth — every answer a
typed, provider-agnostic data object.

## Installation

Requires PHP 8.4, Laravel 12 or 13.

```bash
composer require roundly-consulting/git-for-laravel
```

Put an access token in `.env` (`GITHUB_TOKEN`, `GITLAB_TOKEN` or `BITBUCKET_TOKEN`); without
one, only public repositories can be read.

## Usage

Pick a forge and scope it to one repository:

```php
use RoundlyConsulting\Git\Facades\Git;

$repo = Git::github()->repo('acme/app');       // or Git::gitlab(), Git::bitbucket()

$repo->get()->defaultBranch;                   // "main"
$repo->pullRequests('open');                   // Page<PullRequest>
$repo->contents('composer.json')->content;     // decoded file contents

foreach ($repo->commits()->branch('main')->since(now()->subWeek())->lazy() as $commit) {
    $commit->sha;                              // streams across every page
}
```

Writes take typed input objects:

```php
use RoundlyConsulting\Git\Dto\Input\{NewBranch, NewFile, NewPullRequest};
use RoundlyConsulting\Git\Enums\MergeMethod;

$repo->createBranch(new NewBranch('feature/ci', 'main'));
$repo->createFile(new NewFile('ci.yml', $yaml, 'Add CI', 'feature/ci'));

$pr = $repo->createPullRequest(new NewPullRequest('Add CI', 'feature/ci', 'main'));

$repo->pullRequest($pr->number)->comment('Ready to ship');
$repo->pullRequest($pr->number)->merge(MergeMethod::Squash); // the merge commit's sha
```

<!-- roundly-docs:start -->
## Documentation

The full documentation — configuration, every feature and its API, and testing — lives on our
website: **[roundly-consulting.com/open-source/docs/git-for-laravel](https://roundly-consulting.com/open-source/docs/git-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=git-for-laravel)**

Release notes are in [CHANGELOG.md](CHANGELOG.md). To contribute, see the
[contributing guide](https://github.com/roundly-consulting/.github/blob/main/CONTRIBUTING.md).
<!-- roundly-docs:end -->

<!-- roundly-support:start -->
## Support our work

This package is free and open source, built and maintained by
[Roundly Consulting](https://roundly-consulting.com/open-source?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=git-for-laravel).
If it saves you time, please consider supporting our open-source work — a one-time donation, a
monthly pledge on Patreon or a crypto donation helps fund maintenance, new features and new
packages.

<a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/Donate-Support%20Roundly%20open%20source-F24E29?style=for-the-badge&logo=stripe&logoColor=white" alt="Donate to Roundly open source"></a>
<a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/Patreon-Become%20a%20patron-F96854?style=for-the-badge&logo=patreon&logoColor=white" alt="Become a patron on Patreon"></a>
<a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=git-for-laravel#crypto"><img src="https://img.shields.io/badge/Crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=for-the-badge&logo=bitcoin&logoColor=white" alt="Donate crypto: BTC, ETH, BNB or SOL"></a>
<!-- roundly-support:end -->

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
