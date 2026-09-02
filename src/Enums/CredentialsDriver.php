<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Enums;

enum CredentialsDriver: string
{
    case OnePassword = '1password';
    case Process = 'process';

    public function label(): string
    {
        return match ($this) {
            self::OnePassword => '1Password — the bundled yolo-credentials-1password helper mints MFA-forwarding sessions from a 1Password item',
            self::Process => 'Custom credential_process — any command that emits AWS credential JSON on stdout',
        };
    }
}
