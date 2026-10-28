<?php

use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Credentials\Totp;
use Codinglabs\Yolo\Credentials\AgentCredentials;

beforeEach(function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);
    Helpers::app()->instance('environment', 'testing');
});

it('titles the vault item by account and environment', function (): void {
    expect(AgentCredentials::itemTitle())->toBe('YOLO 111111111111 testing agent');
});

it('takes the source identity from an IAM user ARN, path and all', function (string $arn, ?string $expected): void {
    expect(AgentCredentials::sourceIdentityFrom($arn))->toBe($expected);
})->with([
    'plain user' => ['arn:aws:iam::111111111111:user/jane.doe', 'jane.doe'],
    'user with a path' => ['arn:aws:iam::111111111111:user/team/jane.doe', 'jane.doe'],
    'an assumed role is no developer' => ['arn:aws:sts::111111111111:assumed-role/yolo-testing-agent-role/agent-jane.doe', null],
    'nothing' => ['', null],
]);

it('names the session after the developer and the short host, within STS limits', function (): void {
    expect(AgentCredentials::sessionName('jane.doe', 'laptop-01.local'))->toBe('agent-jane.doe@laptop-01');
    expect(AgentCredentials::sessionName('jane.doe', 'Jane’s MacBook'))->toBe('agent-jane.doe@Jane---s-MacBook');

    $long = AgentCredentials::sessionName(str_repeat('a', 64), 'host');
    expect(strlen($long))->toBe(64)->and($long)->toStartWith('agent-');
});

it('matches the RFC 6238 SHA-1 test vectors', function (int $time, string $code): void {
    // "12345678901234567890" in base32; RFC codes are 8 digits, IAM takes the last 6.
    expect(Totp::code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $time))->toBe($code);
})->with([
    [59, '287082'],
    [1111111109, '081804'],
    [1234567890, '005924'],
    [2000000000, '279037'],
]);

it('reads the seed from a bare value or an otpauth URI', function (): void {
    $uri = Totp::uri('JBSWY3DPEHPK3PXP', 'yolo-testing-agent-user');

    expect($uri)->toBe('otpauth://totp/AWS:yolo-testing-agent-user?secret=JBSWY3DPEHPK3PXP&issuer=AWS')
        ->and(Totp::seedFrom($uri))->toBe('JBSWY3DPEHPK3PXP')
        ->and(Totp::seedFrom('jbsw y3dp ehpk 3pxp'))->toBe('JBSWY3DPEHPK3PXP');
});
