<?php

declare(strict_types=1);

namespace Tests\Fixtures\Search;

use Laravel\Scout\Searchable;
use Illuminate\Database\Eloquent\Model;

/**
 * A string-keyed searchable model — the shape scout:queue-import refuses.
 */
class Voucher extends Model
{
    use Searchable;

    public $incrementing = false;

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    protected $guarded = [];

    public function toSearchableArray(): array
    {
        return ['id' => (string) $this->code];
    }
}
