<?php

use Codinglabs\Yolo\Credentials\SharedIniFile;

beforeEach(function (): void {
    $this->path = BASE_PATH . '/shared-ini-test/config';

    if (file_exists($this->path)) {
        unlink($this->path);
    }
});

it('appends a section to a missing file and locks it down', function (): void {
    $file = new SharedIniFile($this->path, prefixedSections: true);

    $file->putSection('my-app-production', [
        'credential_process = /usr/local/bin/helper "AWS My App"',
        'region = ap-southeast-2',
    ]);

    expect(file_get_contents($this->path))->toBe(
        "[profile my-app-production]\n"
        . "credential_process = /usr/local/bin/helper \"AWS My App\"\n"
        . "region = ap-southeast-2\n"
    );
    expect(substr(sprintf('%o', fileperms($this->path)), -3))->toBe('600');
});

it('appends after existing content with a separating blank line', function (): void {
    file_put_contents($this->path, "[profile other]\nregion = us-west-2\n");

    (new SharedIniFile($this->path, prefixedSections: true))->putSection('my-app-production', ['region = ap-southeast-2']);

    expect(file_get_contents($this->path))->toBe(
        "[profile other]\nregion = us-west-2\n\n[profile my-app-production]\nregion = ap-southeast-2\n"
    );
});

it('replaces a section in place leaving neighbours untouched', function (): void {
    file_put_contents($this->path, implode("\n", [
        '# hand-written comment',
        '[profile before]',
        'region = us-west-2',
        '',
        '[profile my-app-production]',
        'sso_start_url = https://example.awsapps.com/start',
        'sso_region = us-east-1',
        '',
        '[profile after]',
        'region = eu-west-1',
    ]) . "\n");

    (new SharedIniFile($this->path, prefixedSections: true))->putSection('my-app-production', [
        'credential_process = /usr/local/bin/helper "AWS My App"',
        'region = ap-southeast-2',
    ]);

    $contents = file_get_contents($this->path);

    expect($contents)
        ->toContain("# hand-written comment\n[profile before]\nregion = us-west-2")
        ->toContain("[profile my-app-production]\ncredential_process = /usr/local/bin/helper \"AWS My App\"\nregion = ap-southeast-2")
        ->toContain("[profile after]\nregion = eu-west-1")
        ->not->toContain('sso_start_url');
});

it('uses bare section headers for the credentials dialect', function (): void {
    $file = new SharedIniFile($this->path, prefixedSections: false);

    $file->putSection('my-app-production', ['aws_access_key_id = AKIAEXAMPLE']);

    expect(file_get_contents($this->path))->toContain('[my-app-production]');
});

it('never prefixes the default profile', function (): void {
    (new SharedIniFile($this->path, prefixedSections: true))->putSection('default', ['region = ap-southeast-2']);

    expect(file_get_contents($this->path))->toContain("[default]\n");
});

it('reports section keys matching a prefix', function (): void {
    file_put_contents($this->path, implode("\n", [
        '[profile stale]',
        'sso_session = corp',
        'SSO_ACCOUNT_ID = 111111111111',
        'region = ap-southeast-2',
    ]) . "\n");

    $file = new SharedIniFile($this->path, prefixedSections: true);

    expect($file->sectionKeysMatching('stale', 'sso'))->toBe(['sso_session', 'sso_account_id'])
        ->and($file->sectionKeysMatching('stale', 'credential_process'))->toBe([])
        ->and($file->sectionKeysMatching('missing', 'sso'))->toBe([]);
});

it('finds sections case-insensitively', function (): void {
    file_put_contents($this->path, "[Profile My-App]\nregion = ap-southeast-2\n");

    expect((new SharedIniFile($this->path, prefixedSections: true))->hasSection('my-app'))->toBeTrue();
});

it('removes a section and the blank line that separated it', function (): void {
    file_put_contents($this->path, implode("\n", [
        '[keep]',
        'aws_access_key_id = AKIAEXAMPLE',
        '',
        '[remove]',
        'aws_access_key_id = AKIAEXAMPLE2',
        '',
        '[also-keep]',
        'aws_access_key_id = AKIAEXAMPLE3',
    ]) . "\n");

    (new SharedIniFile($this->path, prefixedSections: false))->removeSection('remove');

    expect(file_get_contents($this->path))->toBe(
        "[keep]\naws_access_key_id = AKIAEXAMPLE\n\n[also-keep]\naws_access_key_id = AKIAEXAMPLE3\n"
    );
});

it('removing a missing section is a no-op', function (): void {
    file_put_contents($this->path, "[keep]\nregion = ap-southeast-2\n");

    (new SharedIniFile($this->path, prefixedSections: false))->removeSection('missing');

    expect(file_get_contents($this->path))->toBe("[keep]\nregion = ap-southeast-2\n");
});
