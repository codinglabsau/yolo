<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Helpers;

trait FormatsSshCommands
{
    public static function formatSshCommand(string $ipAddress, string $sshKey = null, string $command = null): string
    {
        $sshKey = match (true) {
            ! is_null($sshKey) => $sshKey,
            ! is_null(Helpers::keyedEnv('SSH_KEY')) => Helpers::keyedEnv('SSH_KEY'),
            default => 'id_rsa',
        };

        $base = "ssh -tt -o StrictHostKeyChecking=no -i ~/.ssh/$sshKey ubuntu@{$ipAddress}";

        return $command
            ? "$base \"$command\""
            : $base;
    }
}
