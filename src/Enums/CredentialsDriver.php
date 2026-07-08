<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Enums;

/**
 * Where `yolo configure` points a profile's credential_process. 1Password is
 * the batteries-included driver (the bundled yolo-credentials helper); Process
 * accepts any command that emits credential JSON on stdout, so another
 * password manager or a bespoke script slots in without YOLO caring.
 */
enum CredentialsDriver: string
{
    case OnePassword = '1password';
    case Process = 'process';

    public function label(): string
    {
        return match ($this) {
            self::OnePassword => '1Password — the bundled yolo-credentials helper mints MFA-forwarding sessions from a 1Password item',
            self::Process => 'Custom credential_process — any command that emits AWS credential JSON on stdout',
        };
    }
}
