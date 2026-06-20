<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

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
        /** @var array<string, mixed> $result */
        $result = $this->recursiveConvertToArray(
            value: get_object_vars($this)
        );

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
