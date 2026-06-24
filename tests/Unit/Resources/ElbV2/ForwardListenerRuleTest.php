<?php

declare(strict_types=1);

use Aws\Result;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;
use Codinglabs\Yolo\Resources\ElbV2\ForwardListenerRule;

function forwardRule(): ForwardListenerRule
{
    return new ForwardListenerRule('arn:listener');
}

beforeEach(function (): void {
    writeManifest(['account-id' => '111111111111', 'region' => 'ap-southeast-2']);
    bindHostedZones();
});

describe('hosts', function (): void {
    it('forwards only the apex when the domain is the apex', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'domain' => 'example.com',
        ]);

        expect(forwardRule()->hosts())->toBe(['example.com']);
    });

    it('forwards only www when the domain is www (www-canonical)', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'domain' => 'www.tenant.com',
        ]);

        expect(forwardRule()->hosts())->toBe(['www.tenant.com']);
    });

    it('forwards only the literal domain for a bare subdomain', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'domain' => 'app.example.com',
        ]);

        expect(forwardRule()->hosts())->toBe(['app.example.com']);
    });
});

describe('priority', function (): void {
    it('allocates a deterministic priority inside the ALB rule range', function (): void {
        $priority = ForwardListenerRule::nextAvailablePriority('my-app', []);

        expect($priority)->toBeGreaterThanOrEqual(1000)
            ->toBeLessThanOrEqual(49999)
            ->and(ForwardListenerRule::nextAvailablePriority('my-app', []))->toBe($priority);
    });

    it('skips a priority already taken by another rule', function (): void {
        $base = ForwardListenerRule::nextAvailablePriority('my-app', []);

        expect(ForwardListenerRule::nextAvailablePriority('my-app', [$base]))
            ->not->toBe($base)
            ->toBeGreaterThanOrEqual(1000)
            ->toBeLessThanOrEqual(49999);
    });

    it('wraps from the ceiling back to the floor on collision', function (): void {
        $priority = ForwardListenerRule::nextAvailablePriority('app-that-hashes-high', range(49000, 49999));

        expect($priority)->toBeLessThan(49000)->toBeGreaterThanOrEqual(1000);
    });

    it('throws when the priority space is fully exhausted', function (): void {
        ForwardListenerRule::nextAvailablePriority('my-app', range(1000, 49999));
    })->throws(IntegrityCheckException::class, 'priority space (1000-49999) exhausted');

    it('never returns a priority below the 1000 floor', function (): void {
        foreach (range(0, 50) as $i) {
            expect(ForwardListenerRule::nextAvailablePriority("app-$i", []))->toBeGreaterThanOrEqual(1000);
        }
    });
});

describe('synchroniseConfiguration', function (): void {
    it('reconciles the rule hosts in place and never touches a sibling host\'s rule', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'domain' => 'example.com',
        ]);

        $captured = [];
        bindRoutedElbV2Client([
            // The app's own rule still carries the legacy apex+www host-set, plus an
            // unrelated rule for a different host on the same shared listener.
            'DescribeRules' => new Result(['Rules' => [
                [
                    'RuleArn' => 'arn:rule:mine',
                    'Priority' => '1500',
                    'Conditions' => [['Field' => 'host-header', 'HostHeaderConfig' => ['Values' => ['example.com', 'www.example.com']]]],
                    'Actions' => [['Type' => 'forward', 'TargetGroupArn' => 'arn:tg:mine']],
                ],
                [
                    'RuleArn' => 'arn:rule:foreign',
                    'Priority' => '1600',
                    'Conditions' => [['Field' => 'host-header', 'HostHeaderConfig' => ['Values' => ['custom.domain.com']]]],
                    'Actions' => [['Type' => 'forward', 'TargetGroupArn' => 'arn:tg:foreign']],
                ],
            ]]),
            'DescribeTags' => new Result(['TagDescriptions' => [
                ['ResourceArn' => 'arn:rule:mine', 'Tags' => [['Key' => 'Name', 'Value' => 'yolo-testing-my-app']]],
                ['ResourceArn' => 'arn:rule:foreign', 'Tags' => [['Key' => 'Name', 'Value' => 'someone-elses-rule']]],
            ]]),
            'DescribeTargetGroups' => new Result(['TargetGroups' => [['TargetGroupArn' => 'arn:tg:mine']]]),
        ], $captured);

        $changes = forwardRule()->synchroniseConfiguration(apply: true);

        $modify = collect($captured)->where('name', 'ModifyRule');

        expect($changes)->toHaveCount(1)
            ->and($modify)->toHaveCount(1)
            // only the app's own rule is modified, narrowed to the canonical host
            ->and($modify->first()['args']['RuleArn'])->toBe('arn:rule:mine')
            ->and($modify->first()['args']['Conditions'][0]['HostHeaderConfig']['Values'])->toBe(['example.com'])
            // the foreign custom.domain.com rule is never modified or deleted
            ->and(collect($captured)->pluck('args.RuleArn')->filter()->all())->not->toContain('arn:rule:foreign')
            ->and(collect($captured)->pluck('name')->all())->not->toContain('DeleteRule');
    });

    it('records no change and issues no ModifyRule when the rule already matches', function (): void {
        writeManifest([
            'account-id' => '111111111111', 'region' => 'ap-southeast-2',
            'domain' => 'example.com',
        ]);

        $captured = [];
        bindRoutedElbV2Client([
            'DescribeRules' => new Result(['Rules' => [[
                'RuleArn' => 'arn:rule:mine',
                'Priority' => '1500',
                'Conditions' => [['Field' => 'host-header', 'HostHeaderConfig' => ['Values' => ['example.com']]]],
                'Actions' => [['Type' => 'forward', 'TargetGroupArn' => 'arn:tg:mine']],
            ]]]),
            'DescribeTags' => new Result(['TagDescriptions' => [
                ['ResourceArn' => 'arn:rule:mine', 'Tags' => [['Key' => 'Name', 'Value' => 'yolo-testing-my-app']]],
            ]]),
            'DescribeTargetGroups' => new Result(['TargetGroups' => [['TargetGroupArn' => 'arn:tg:mine']]]),
        ], $captured);

        expect(forwardRule()->synchroniseConfiguration(apply: true))->toBe([])
            ->and(collect($captured)->pluck('name')->all())->not->toContain('ModifyRule');
    });
});
