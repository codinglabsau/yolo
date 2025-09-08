<?php

namespace Codinglabs\Yolo\Exceptions;

use Exception;

class YoloException extends Exception
{
    protected ?string $suggestion = null;

    public static function make(string $message): self
    {
        return new (get_called_class())($message);
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
}
