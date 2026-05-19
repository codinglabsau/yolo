<?php

namespace Codinglabs\Yolo\Concerns;

use Closure;
use Illuminate\Support\Str;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Exceptions\YoloException;

use function Laravel\Prompts\note;
use function Laravel\Prompts\alert;

trait EnsuresResourcesExist
{
    public function ensure(Closure $closure): void
    {
        try {
            $closure();
        } catch (YoloException $e) {
            alert(sprintf('%s: %s', Str::replaceLast('.php', '', basename($e->getFile())), $e->getMessage()));

            if ($e->getSuggestion()) {
                note(sprintf('Suggestion: try running "yolo %s %s"', $e->getSuggestion(), Helpers::environment()));
            }

            exit(1);
        }
    }
}
