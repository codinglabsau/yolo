<?php

namespace Codinglabs\Yolo\Concerns;

use Closure;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;
use function Laravel\Prompts\note;
use function Laravel\Prompts\alert;

trait EnsuresResourcesExist
{
    public function ensure(Closure $closure): void
    {
        try {
            $closure();
        } catch (ResourceDoesNotExistException $e) {
            alert('ResourceDoesNotExistException: ' . $e->getMessage());

            if ($e->getSuggestion()) {
                note('Suggestion: try running "yolo ' . $e->getSuggestion() . '"');
            }

            exit(1);
        }
    }
}
