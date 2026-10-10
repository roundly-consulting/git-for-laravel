<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto\Input;

use InvalidArgumentException;
use RoundlyConsulting\Git\Exceptions\OutOfScopeException;
use RoundlyConsulting\Git\Handles\PathGuard;

/**
 * Start a GitHub Actions workflow (`workflow_dispatch`).
 *
 * `workflow` is the workflow's file name (`deploy.yml`) or its numeric id. `ref` is sent as
 * given — `main` and `refs/heads/main` both work. `inputs` are the workflow's declared
 * inputs, each a scalar; how many a workflow may take is left to GitHub (a `422`), since it
 * has raised that limit before.
 */
final readonly class NewWorkflowDispatch
{
    /**
     * @param  array<string, string|int|float|bool>  $inputs
     *
     * @throws OutOfScopeException when the workflow or ref could address something else
     * @throws InvalidArgumentException when an input is not a named scalar
     */
    public function __construct(
        public string $workflow,
        public string $ref,
        public array $inputs = [],
    ) {
        PathGuard::workflow($workflow);
        PathGuard::ref('workflow ref', $ref);

        self::guardInputs($inputs);
    }

    /**
     * Typed loosely on purpose: a caller handing over decoded JSON is exactly who sends a
     * nested array, a null or a positional key here, whatever the docblock above promises.
     *
     * @param  array<mixed>  $inputs
     *
     * @throws InvalidArgumentException
     */
    private static function guardInputs(array $inputs): void
    {
        foreach ($inputs as $name => $value) {
            if (! is_string($name)) {
                throw new InvalidArgumentException("Workflow inputs are keyed by input name; got the positional key [{$name}].");
            }

            if (! is_scalar($value)) {
                throw new InvalidArgumentException("Workflow input [{$name}] must be a string, number or boolean.");
            }
        }
    }
}
