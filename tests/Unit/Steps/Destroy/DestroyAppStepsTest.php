<?php

declare(strict_types=1);

use Aws\Result;
use Aws\Command;
use Aws\MockHandler;
use Aws\Acm\AcmClient;
use Aws\CommandInterface;
use Codinglabs\Yolo\Helpers;
use Aws\Route53\Route53Client;
use GuzzleHttp\Promise\Create;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Ec2\RdsSecurityGroup;
use Codinglabs\Yolo\Resources\Ec2\EcsTaskSecurityGroup;
use Codinglabs\Yolo\Steps\Destroy\App\RevokeRdsIngressStep;
use Aws\ElasticLoadBalancingV2\ElasticLoadBalancingV2Client;
use Codinglabs\Yolo\Steps\Destroy\App\TeardownForwardRuleStep;
use Codinglabs\Yolo\Steps\Destroy\App\TeardownTargetGroupStep;
use Codinglabs\Yolo\Steps\Destroy\App\DetachSslCertificateStep;
use Codinglabs\Yolo\Steps\Destroy\App\UnpublishAppManifestStep;
use Codinglabs\Yolo\Steps\Destroy\App\WithdrawAppDnsRecordsStep;
use Codinglabs\Yolo\Steps\Destroy\App\DeregisterWebAutoscalingStep;
use Codinglabs\Yolo\Steps\Destroy\App\DeregisterTaskDefinitionsStep;
use Codinglabs\Yolo\Steps\Destroy\App\TeardownCloudWatchDashboardStep;
use Aws\ElasticLoadBalancingV2\Exception\ElasticLoadBalancingV2Exception;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'domain' => 'example.com',
        'tasks' => ['web' => true],
    ]);
});

it('TeardownStep base deletes an existing resource and records the change', function (): void {
    $captured = [];
    bindRoutedElbV2Client([
        'DescribeTargetGroups' => new Result(['TargetGroups' => [['TargetGroupName' => 'yolo-testing-my-app', 'TargetGroupArn' => 'arn:tg']]]),
    ], $captured);

    $step = new TeardownTargetGroupStep();

    expect($step(['dry-run' => false]))->toBe(StepResult::DELETED)
        ->and(array_column($captured, 'name'))->toContain('DeleteTargetGroup')
        ->and($step->changes())->not->toBeEmpty();
});

it('TeardownStep base reports WOULD_DELETE without writing on the plan pass', function (): void {
    $captured = [];
    bindRoutedElbV2Client([
        'DescribeTargetGroups' => new Result(['TargetGroups' => [['TargetGroupName' => 'yolo-testing-my-app', 'TargetGroupArn' => 'arn:tg']]]),
    ], $captured);

    expect((new TeardownTargetGroupStep())(['dry-run' => true]))->toBe(StepResult::WOULD_DELETE)
        ->and(array_column($captured, 'name'))->not->toContain('DeleteTargetGroup');
});

it('revokes only this app\'s 3306 rule from the shared RDS security group', function (): void {
    $rdsName = (new RdsSecurityGroup())->name();
    $taskName = (new EcsTaskSecurityGroup())->name();

    $captured = [];
    bindMockEc2Client([
        'DescribeSecurityGroups' => new Result(['SecurityGroups' => [
            ['GroupName' => $rdsName, 'GroupId' => 'sg-rds'],
            ['GroupName' => $taskName, 'GroupId' => 'sg-task'],
        ]]),
        'DescribeSecurityGroupRules' => new Result(['SecurityGroupRules' => [
            ['SecurityGroupRuleId' => 'sgr-1', 'IsEgress' => false, 'IpProtocol' => 'tcp', 'FromPort' => 3306, 'ReferencedGroupInfo' => ['GroupId' => 'sg-task']],
        ]]),
    ], $captured);

    expect((new RevokeRdsIngressStep())(['dry-run' => false]))->toBe(StepResult::DELETED);

    $revoke = collect($captured)->firstWhere('name', 'RevokeSecurityGroupIngress');
    expect($revoke['args']['GroupId'])->toBe('sg-rds')
        ->and($revoke['args']['SecurityGroupRuleIds'])->toBe(['sgr-1']);
});

it('leaves an adopted RDS security group entirely alone', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2', 'domain' => 'example.com',
        'tasks' => ['web' => true], 'rds' => ['security-group' => 'my-existing-sg'],
    ]);

    expect((new RevokeRdsIngressStep())(['dry-run' => false]))->toBe(StepResult::SKIPPED);
});

it('deregisters every active task-definition revision in the app\'s families', function (): void {
    $captured = [];
    bindRoutedEcsClient([
        'ListTaskDefinitions' => new Result(['taskDefinitionArns' => ['arn:td:1', 'arn:td:2']]),
    ], $captured);

    expect((new DeregisterTaskDefinitionsStep())(['dry-run' => false]))->toBe(StepResult::DELETED);

    expect(collect($captured)->where('name', 'DeregisterTaskDefinition')->pluck('args.taskDefinition')->all())
        ->toBe(['arn:td:1', 'arn:td:2']);
});

it('deregisters the web scalable target, cascading its policies', function (): void {
    $captured = [];
    bindMockApplicationAutoScalingClient([
        'DescribeScalableTargets' => new Result(['ScalableTargets' => [['MinCapacity' => 1, 'MaxCapacity' => 4]]]),
    ], $captured);

    expect((new DeregisterWebAutoscalingStep())(['dry-run' => false]))->toBe(StepResult::DELETED)
        ->and(array_column($captured, 'name'))->toContain('DeregisterScalableTarget');
});

it('skips deregistering autoscaling when there is no scalable target', function (): void {
    $captured = [];
    bindMockApplicationAutoScalingClient([
        'DescribeScalableTargets' => new Result(['ScalableTargets' => []]),
    ], $captured);

    expect((new DeregisterWebAutoscalingStep())(['dry-run' => false]))->toBe(StepResult::SKIPPED)
        ->and(array_column($captured, 'name'))->not->toContain('DeregisterScalableTarget');
});

it('deletes the published claim file from the env config bucket', function (): void {
    $captured = [];
    bindRoutedS3Client([
        'HeadObject' => new Result([]),
        'DeleteObject' => new Result([]),
    ], $captured);

    expect((new UnpublishAppManifestStep())(['dry-run' => false]))->toBe(StepResult::DELETED)
        ->and(array_column($captured, 'name'))->toContain('DeleteObject');
});

it('deletes the CloudWatch dashboard when present', function (): void {
    $captured = [];
    bindMockCloudWatchClient([
        'GetDashboard' => new Result(['DashboardBody' => '{}']),
    ], $captured);

    expect((new TeardownCloudWatchDashboardStep())(['dry-run' => false]))->toBe(StepResult::DELETED)
        ->and(array_column($captured, 'name'))->toContain('DeleteDashboards');
});

it('skips the forward-rule teardown when the load balancer is already gone', function (): void {
    $captured = [];
    bindRoutedElbV2Client([
        'DescribeLoadBalancers' => new Result(['LoadBalancers' => []]),
    ], $captured);

    expect((new TeardownForwardRuleStep())(['dry-run' => false]))->toBe(StepResult::SKIPPED);
});

/**
 * Generic routed mock for clients without a Pest-wide binder (route53, acm).
 * Uniquely named to avoid colliding with the same helper in TeardownTest.php.
 *
 * @param  array<string, Result|Throwable|array<int, Result|Throwable>>  $byCommand
 * @param  array<int, array{name: string, args: array<string, mixed>}>  $captured
 */
function bindDestroyRoutedClient(string $binding, string $clientClass, array $byCommand, array &$captured): void
{
    $mock = new class($byCommand, $captured) extends MockHandler
    {
        /** @var array<string, int> */
        private array $cursors = [];

        public function __construct(protected array $byCommand, protected array &$captured) {}

        public function __invoke(CommandInterface $cmd, $request)
        {
            $name = $cmd->getName();
            $this->captured[] = ['name' => $name, 'args' => $cmd->toArray()];

            $entry = $this->byCommand[$name] ?? new Result();

            if (is_array($entry)) {
                $index = min($this->cursors[$name] ?? 0, count($entry) - 1);
                $this->cursors[$name] = $index + 1;
                $entry = $entry[$index];
            }

            return $entry instanceof Throwable ? Create::rejectionFor($entry) : Create::promiseFor($entry);
        }
    };

    Helpers::app()->instance($binding, new $clientClass([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}

// --- WithdrawAppDnsRecordsStep: withdraws this app's records, never deletes the zone ---

it('withdraws this app\'s records and never deletes the hosted zone', function (): void {
    $captured = [];
    bindDestroyRoutedClient('route53', Route53Client::class, [
        'ListHostedZones' => new Result(['HostedZones' => [['Id' => '/hostedzone/Z1', 'Name' => 'example.com.']]]),
        'ListResourceRecordSets' => new Result(['ResourceRecordSets' => [
            ['Name' => 'example.com.', 'Type' => 'A', 'ResourceRecords' => [['Value' => '1.1.1.1']]],
            ['Name' => 'example.com.', 'Type' => 'MX', 'ResourceRecords' => [['Value' => '10 mail']]],
        ]]),
    ], $captured);

    expect((new WithdrawAppDnsRecordsStep())(['dry-run' => false]))->toBe(StepResult::DELETED)
        ->and(array_column($captured, 'name'))
        ->toContain('ChangeResourceRecordSets')
        ->not->toContain('DeleteHostedZone');
});

it('skips when the hosted zone holds none of this app\'s records', function (): void {
    $captured = [];
    bindDestroyRoutedClient('route53', Route53Client::class, [
        'ListHostedZones' => new Result(['HostedZones' => [['Id' => '/hostedzone/Z1', 'Name' => 'example.com.']]]),
        'ListResourceRecordSets' => new Result(['ResourceRecordSets' => [
            ['Name' => 'example.com.', 'Type' => 'NS', 'ResourceRecords' => [['Value' => 'ns']]],
            ['Name' => 'example.com.', 'Type' => 'SOA', 'ResourceRecords' => [['Value' => 'soa']]],
            ['Name' => 'example.com.', 'Type' => 'MX', 'ResourceRecords' => [['Value' => '10 mail']]],
        ]]),
    ], $captured);

    expect((new WithdrawAppDnsRecordsStep())(['dry-run' => false]))->toBe(StepResult::SKIPPED)
        ->and(array_column($captured, 'name'))->not->toContain('ChangeResourceRecordSets');
});

it('skips when the hosted zone does not exist', function (): void {
    $captured = [];
    bindDestroyRoutedClient('route53', Route53Client::class, [
        'ListHostedZones' => new Result(['HostedZones' => []]),
    ], $captured);

    expect((new WithdrawAppDnsRecordsStep())(['dry-run' => false]))->toBe(StepResult::SKIPPED)
        ->and(array_column($captured, 'name'))->not->toContain('ChangeResourceRecordSets');
});

// --- DetachSslCertificateStep: withdraws the listener association, NEVER deletes the ACM cert ---

it('detaches the cert from this env\'s listener and keeps the ACM cert', function (): void {
    $acm = [];
    bindDestroyRoutedClient('acm', AcmClient::class, [
        'ListCertificates' => new Result(['CertificateSummaryList' => [['DomainName' => 'example.com', 'CertificateArn' => 'arn:aws:acm:ap-southeast-2:111111111111:certificate/x']]]),
    ], $acm);

    $elb = [];
    bindRoutedElbV2Client([
        'DescribeLoadBalancers' => new Result(['LoadBalancers' => [['LoadBalancerName' => 'yolo-testing', 'LoadBalancerArn' => 'arn:lb', 'DNSName' => 'd', 'CanonicalHostedZoneId' => 'Z']]]),
        'DescribeListeners' => new Result(['Listeners' => [['ListenerArn' => 'arn:listener', 'Port' => 443]]]),
    ], $elb);

    expect((new DetachSslCertificateStep())(['dry-run' => false]))->toBe(StepResult::DELETED);
    // The app's SNI association is withdrawn from the listener, but the ACM cert
    // itself is never deleted — it's domain-level and may be a sibling env's.
    expect(array_column($elb, 'name'))->toContain('RemoveListenerCertificates');
    expect(array_column($acm, 'name'))->not->toContain('DeleteCertificate');
});

it('tolerates a default cert that can\'t be detached and still never deletes the ACM cert', function (): void {
    $acm = [];
    bindDestroyRoutedClient('acm', AcmClient::class, [
        'ListCertificates' => new Result(['CertificateSummaryList' => [['DomainName' => 'example.com', 'CertificateArn' => 'arn:aws:acm:ap-southeast-2:111111111111:certificate/x']]]),
    ], $acm);

    // RemoveListenerCertificates rejects the listener's default cert — the step
    // swallows it (the cert is kept regardless) and still reports DELETED.
    $elb = [];
    bindDestroyRoutedClient('elasticLoadBalancingV2', ElasticLoadBalancingV2Client::class, [
        'DescribeLoadBalancers' => new Result(['LoadBalancers' => [['LoadBalancerName' => 'yolo-testing', 'LoadBalancerArn' => 'arn:lb', 'DNSName' => 'd', 'CanonicalHostedZoneId' => 'Z']]]),
        'DescribeListeners' => new Result(['Listeners' => [['ListenerArn' => 'arn:listener', 'Port' => 443]]]),
        'RemoveListenerCertificates' => new ElasticLoadBalancingV2Exception('default cert', new Command('RemoveListenerCertificates'), ['code' => 'ValidationError']),
    ], $elb);

    expect((new DetachSslCertificateStep())(['dry-run' => false]))->toBe(StepResult::DELETED)
        ->and(array_column($acm, 'name'))->not->toContain('DeleteCertificate');
});

it('skips when this env\'s HTTPS listener is already gone, keeping the ACM cert', function (): void {
    $acm = [];
    bindDestroyRoutedClient('acm', AcmClient::class, [
        'ListCertificates' => new Result(['CertificateSummaryList' => [['DomainName' => 'example.com', 'CertificateArn' => 'arn:aws:acm:ap-southeast-2:111111111111:certificate/x']]]),
    ], $acm);

    $elb = [];
    bindRoutedElbV2Client([
        'DescribeLoadBalancers' => new Result(['LoadBalancers' => [['LoadBalancerName' => 'yolo-testing', 'LoadBalancerArn' => 'arn:lb', 'DNSName' => 'd', 'CanonicalHostedZoneId' => 'Z']]]),
        'DescribeListeners' => new Result(['Listeners' => []]),
    ], $elb);

    expect((new DetachSslCertificateStep())(['dry-run' => false]))->toBe(StepResult::SKIPPED)
        ->and(array_column($elb, 'name'))->not->toContain('RemoveListenerCertificates')
        ->and(array_column($acm, 'name'))->not->toContain('DeleteCertificate');
});

it('skips the cert detach when the certificate is already gone', function (): void {
    $acm = [];
    bindDestroyRoutedClient('acm', AcmClient::class, [
        'ListCertificates' => new Result(['CertificateSummaryList' => []]),
    ], $acm);

    expect((new DetachSslCertificateStep())(['dry-run' => false]))->toBe(StepResult::SKIPPED)
        ->and(array_column($acm, 'name'))->not->toContain('DeleteCertificate');
});
