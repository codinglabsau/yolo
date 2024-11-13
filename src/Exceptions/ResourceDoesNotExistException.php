<?php

namespace Codinglabs\Yolo\Exceptions;

use Exception;

final class ResourceDoesNotExistException extends Exception
{
    protected ?string $suggestion = null;

    public static function make(string $message): self
    {
        return new ResourceDoesNotExistException($message);
    }

    public function suggest(string $suggestion): self
    {
        $this->suggestion = $suggestion;

        return $this;
    }

    public function getSuggestion(): string
    {
        return $this->suggestion;
    }

    public function throw(): void
    {
        throw $this;
    }
}
