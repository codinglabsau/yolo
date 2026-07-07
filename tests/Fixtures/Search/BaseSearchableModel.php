<?php

declare(strict_types=1);

namespace Tests\Fixtures\Search;

use Laravel\Scout\Searchable;
use Illuminate\Database\Eloquent\Model;

abstract class BaseSearchableModel extends Model
{
    use Searchable;
}
