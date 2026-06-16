<?php

declare(strict_types=1);

use Codinglabs\Yolo\Arn;
use Codinglabs\Yolo\ConsoleUrl;

function consoleUrl(string $arn): ?string
{
    return ConsoleUrl::for(Arn::parse($arn));
}

it('builds a deep link for each supported service', function (string $arn, string $expected): void {
    expect(consoleUrl($arn))->toBe($expected);
})->with([
    'ecs service' => [
        'arn:aws:ecs:ap-southeast-2:111:service/yolo-production-codinglabs/web',
        'https://ap-southeast-2.console.aws.amazon.com/ecs/v2/clusters/yolo-production-codinglabs/services/web/health?region=ap-southeast-2',
    ],
    'ecs cluster' => [
        'arn:aws:ecs:ap-southeast-2:111:cluster/yolo-production-codinglabs',
        'https://ap-southeast-2.console.aws.amazon.com/ecs/v2/clusters/yolo-production-codinglabs/services?region=ap-southeast-2',
    ],
    'ecr repository' => [
        'arn:aws:ecr:ap-southeast-2:111:repository/yolo-production-ghost',
        'https://ap-southeast-2.console.aws.amazon.com/ecr/repositories/private/111/yolo-production-ghost?region=ap-southeast-2',
    ],
    'elbv2 target group' => [
        'arn:aws:elasticloadbalancing:ap-southeast-2:111:targetgroup/yolo-x/abc123',
        'https://ap-southeast-2.console.aws.amazon.com/ec2/home?region=ap-southeast-2#TargetGroup:targetGroupArn=arn:aws:elasticloadbalancing:ap-southeast-2:111:targetgroup/yolo-x/abc123',
    ],
    'ec2 security group' => [
        'arn:aws:ec2:ap-southeast-2:111:security-group/sg-0123',
        'https://ap-southeast-2.console.aws.amazon.com/ec2/home?region=ap-southeast-2#SecurityGroup:groupId=sg-0123',
    ],
    'ec2 vpc (vpc console)' => [
        'arn:aws:ec2:ap-southeast-2:111:vpc/vpc-0abc',
        'https://ap-southeast-2.console.aws.amazon.com/vpcconsole/home?region=ap-southeast-2#VpcDetails:VpcId=vpc-0abc',
    ],
    's3 bucket (region-less)' => [
        'arn:aws:s3:::yolo-production-codinglabs-assets',
        'https://s3.console.aws.amazon.com/s3/buckets/yolo-production-codinglabs-assets',
    ],
    'iam role (region-less, basename)' => [
        'arn:aws:iam::111:role/yolo-production-codinglabs-ecs-task-role',
        'https://console.aws.amazon.com/iam/home#/roles/details/yolo-production-codinglabs-ecs-task-role',
    ],
    'route53 hosted zone' => [
        'arn:aws:route53:::hostedzone/Z123ABC',
        'https://console.aws.amazon.com/route53/v2/hostedzones#ListRecordSets/Z123ABC',
    ],
    'sqs queue (built from queue url)' => [
        'arn:aws:sqs:ap-southeast-2:111:yolo-production-codinglabs-default',
        'https://ap-southeast-2.console.aws.amazon.com/sqs/v3/home?region=ap-southeast-2#/queues/https%3A%2F%2Fsqs.ap-southeast-2.amazonaws.com%2F111%2Fyolo-production-codinglabs-default',
    ],
    'rds instance' => [
        'arn:aws:rds:ap-southeast-2:111:db:yolo-production',
        'https://ap-southeast-2.console.aws.amazon.com/rds/home?region=ap-southeast-2#database:id=yolo-production;is-cluster=false',
    ],
    'rds aurora cluster' => [
        'arn:aws:rds:ap-southeast-2:111:cluster:yolo-production-aurora',
        'https://ap-southeast-2.console.aws.amazon.com/rds/home?region=ap-southeast-2#database:id=yolo-production-aurora;is-cluster=true',
    ],
    'elasticache replication group' => [
        'arn:aws:elasticache:ap-southeast-2:111:replicationgroup:yolo-production-cache',
        'https://ap-southeast-2.console.aws.amazon.com/elasticache/home?region=ap-southeast-2#/redis/yolo-production-cache',
    ],
]);

it('builds a region-wide CloudWatch alarms link', function (): void {
    expect(ConsoleUrl::cloudWatchAlarms('ap-southeast-2'))
        ->toBe('https://ap-southeast-2.console.aws.amazon.com/cloudwatch/home?region=ap-southeast-2#alarmsV2:');
});

it('applies the CloudWatch console encoding to a log-group name and strips the describe-ARN suffix', function (): void {
    expect(consoleUrl('arn:aws:logs:ap-southeast-2:111:log-group:/aws/ivs/yolo-x:*'))
        ->toBe('https://ap-southeast-2.console.aws.amazon.com/cloudwatch/home?region=ap-southeast-2#logsV2:log-groups/log-group/$252Faws$252Fivs$252Fyolo-x');
});

it('returns null for an unsupported service', function (): void {
    expect(consoleUrl('arn:aws:kms:ap-southeast-2:111:key/abc-123'))->toBeNull();
});

it('returns null for an unparseable or missing ARN', function (): void {
    expect(ConsoleUrl::for(null))->toBeNull()
        ->and(consoleUrl('not-an-arn'))->toBeNull();
});
