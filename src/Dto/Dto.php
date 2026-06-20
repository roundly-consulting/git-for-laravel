<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use JsonSerializable;
use SensitiveParameterValue;

/**
 * @implements Arrayable<string, mixed>
 */
abstract readonly class Dto implements Arrayable, Jsonable, JsonSerializable
{
    public function toJson($options = 0): string
    {
        return (string) json_encode($this->jsonSerialize(), $options);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $vars = get_object_vars($this);

        // The raw provider payload is an escape hatch, not part of the canonical
        // serialized shape — excluding it keeps output lean and avoids leaking
        // unmodelled provider internals into JSON.
        unset($vars['raw']);

        /** @var array<string, mixed> $result */
        $result = $this->recursiveConvertToArray(value: $vars);

        return $result;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    protected function recursiveConvertToArray(mixed $value): mixed
    {
        if ($value instanceof Arrayable) {
            $value = $value->toArray();
        }

        if ($value instanceof SensitiveParameterValue) {
            $value = $value->getValue();
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        if (is_array($value)) {
            $result = [];

            foreach ($value as $key => $item) {
                $result[$key] = $this->recursiveConvertToArray($item);
            }
        } else {
            $result = $value;
        }

        return $result;
    }
}
