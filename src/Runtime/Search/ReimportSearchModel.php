<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime\Search;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * One model's zero-downtime reimport, as a queued job — how the heal
 * command hands the heavy lifting to the queue workers instead of doing a
 * full rebuild inside a scheduler tick. Unique per model class, so a heal
 * pass that fires from several tasks at once (or twice in a row) queues
 * each rebuild exactly once.
 */
class ReimportSearchModel implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** A full rebuild of a large collection is slow by design. */
    public int $timeout = 3600;

    public int $tries = 1;

    /**
     * @param  class-string<Model&SearchableModel>  $modelClass
     */
    public function __construct(public string $modelClass) {}

    public function uniqueId(): string
    {
        return $this->modelClass;
    }

    public function handle(TypesenseClient $typesense): void
    {
        $result = (new ZeroDowntimeReimport($typesense))->reimport($this->modelClass);

        Log::info('yolo:search — rebuilt search collection', $result);
    }
}
