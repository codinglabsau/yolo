<?php

declare(strict_types=1);

namespace Tests\Fixtures\Search;

use Laravel\Scout\Searchable;

/**
 * An app's own wrapper trait — discovery must resolve Searchable through it
 * (class_uses_recursive walks traits-of-traits).
 */
trait AppSearchable
{
    use Searchable;
}
