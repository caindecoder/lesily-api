<?php

namespace App\Foundation\UseCases\Casts;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Casts\Cast;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Casts\Uncastable;
use Spatie\LaravelData\Support\DataProperty;
use Spatie\LaravelData\Support\Creation\CreationContext;

class ModelCast implements Cast
{
    /**
     * @param  list<string>  $columns
     */
    public function __construct(protected string $model, protected array $columns = ['*']) {}

    /**
     * @param  array<string, mixed>  $properties
     * @param  CreationContext<Data>  $context
     */
    public function cast(DataProperty $property, mixed $value, array $properties, CreationContext $context): mixed
    {
        if (! is_subclass_of($this->model, Model::class)) {
            return Uncastable::create();
        }

        if ($value instanceof $this->model) {
            return $value;
        }

        return $this->model::query()->whereKey($value)->firstOrFail($this->columns);
    }
}
