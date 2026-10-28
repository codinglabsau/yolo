<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Credentials;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * The few `op` calls `agent:rotate` needs. Secrets travel on stdin as an item JSON
 * template, never as arguments — arguments land in shell history and are visible
 * to every process on the machine.
 */
class OnePasswordVault
{
    /**
     * @return array<string, mixed>|null null when the vault holds no such item
     */
    public function item(string $title, string $vault): ?array
    {
        $process = $this->run(['op', 'item', 'get', $title, '--vault', $vault, '--format', 'json']);

        if (! $process->isSuccessful()) {
            if (str_contains($process->getErrorOutput(), 'isn\'t an item')) {
                return null;
            }

            throw new RuntimeException(sprintf("Couldn't read '%s' from the %s vault: %s", $title, $vault, trim($process->getErrorOutput())));
        }

        return json_decode($process->getOutput(), true);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return string the new item's id
     */
    public function create(string $vault, array $item): string
    {
        $process = $this->run(['op', 'item', 'create', '--vault', $vault, '--format', 'json', '-'], json_encode($item));

        if (! $process->isSuccessful()) {
            throw new RuntimeException(sprintf("Couldn't write '%s' to the %s vault: %s", $item['title'] ?? 'item', $vault, trim($process->getErrorOutput())));
        }

        return (string) (json_decode($process->getOutput(), true)['id'] ?? '');
    }

    /** Archived, not deleted — the previous key stays recoverable from 1Password until someone empties it. */
    public function archive(string $id, string $vault): void
    {
        $process = $this->run(['op', 'item', 'delete', $id, '--vault', $vault, '--archive']);

        if (! $process->isSuccessful()) {
            throw new RuntimeException(sprintf("Couldn't archive the previous item %s: %s", $id, trim($process->getErrorOutput())));
        }
    }

    /**
     * @param  array<int, string>  $command
     */
    protected function run(array $command, ?string $input = null): Process
    {
        $process = new Process($command);
        $process->setTimeout(60);

        if ($input !== null) {
            $process->setInput($input);
        }

        $process->run();

        return $process;
    }
}
