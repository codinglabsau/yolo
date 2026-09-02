<?php

namespace Codinglabs\Yolo;

/**
 * Best-effort console deep link from an ARN. No AWS API maps an ARN to a console URL and every
 * service has its own scheme, so this is a template table; an unknown service returns null
 * and the audit renders plain text — a missing link beats a dead one.
 */
class ConsoleUrl
{
    public static function for(?Arn $arn): ?string
    {
        if (! $arn instanceof Arn) {
            return null;
        }

        return match ($arn->service) {
            'ecs' => static::ecs($arn),
            'ecr' => static::regional($arn, sprintf('ecr/repositories/private/%s/%s', $arn->accountId, $arn->resourceId)),
            'elasticloadbalancing' => static::elb($arn),
            'ec2' => static::ec2($arn),
            'logs' => static::logs($arn),
            'sqs' => static::sqs($arn),
            'sns' => static::regional($arn, sprintf('sns/v3/home#/topic/%s', rawurlencode($arn->value))),
            'events' => static::regional($arn, sprintf('events/home#/eventbus/default/rules/%s', $arn->resourceId)),
            'acm' => static::regional($arn, sprintf('acm/home#/certificates/%s', $arn->resourceId)),
            's3' => sprintf('https://s3.console.aws.amazon.com/s3/buckets/%s', $arn->resourceId),
            'iam' => static::iam($arn),
            'route53' => sprintf('https://console.aws.amazon.com/route53/v2/hostedzones#ListRecordSets/%s', $arn->resourceId),
            'cloudfront' => $arn->resourceType === 'distribution'
                ? sprintf('https://console.aws.amazon.com/cloudfront/v4/home#/distributions/%s', $arn->resourceId)
                : null,
            'rds' => static::rds($arn),
            'elasticache' => static::elastiCache($arn),
            default => null,
        };
    }

    /** Null for a region-less ARN — bail rather than emit a broken host. */
    protected static function regional(Arn $arn, string $path): ?string
    {
        if ($arn->region === '') {
            return null;
        }

        $region = "region={$arn->region}";
        $path = str_contains($path, '#')
            ? str_replace('#', "?{$region}#", $path)
            : "{$path}?{$region}";

        return sprintf('https://%s.console.aws.amazon.com/%s', $arn->region, $path);
    }

    protected static function ecs(Arn $arn): ?string
    {
        // cluster ARN resourceId is the cluster name; service ARN is "cluster/service".
        return match ($arn->resourceType) {
            'cluster' => static::regional($arn, sprintf('ecs/v2/clusters/%s/services', $arn->resourceId)),
            'service' => static::regional($arn, sprintf(
                'ecs/v2/clusters/%s/services/%s/health',
                ...array_pad(explode('/', $arn->resourceId, 2), 2, ''),
            )),
            default => null,
        };
    }

    protected static function elb(Arn $arn): ?string
    {
        // The EC2 console selects an ELBv2 resource by its full ARN in the fragment.
        return match ($arn->resourceType) {
            'loadbalancer' => static::regional($arn, sprintf('ec2/home#LoadBalancer:loadBalancerArn=%s', $arn->value)),
            'targetgroup' => static::regional($arn, sprintf('ec2/home#TargetGroup:targetGroupArn=%s', $arn->value)),
            default => null,
        };
    }

    protected static function ec2(Arn $arn): ?string
    {
        // VPC-family resources live in the VPC console; security groups in EC2.
        return match ($arn->resourceType) {
            'vpc' => static::regional($arn, sprintf('vpcconsole/home#VpcDetails:VpcId=%s', $arn->resourceId)),
            'subnet' => static::regional($arn, sprintf('vpcconsole/home#SubnetDetails:subnetId=%s', $arn->resourceId)),
            'route-table' => static::regional($arn, sprintf('vpcconsole/home#RouteTableDetails:RouteTableId=%s', $arn->resourceId)),
            'internet-gateway' => static::regional($arn, sprintf('vpcconsole/home#InternetGateway:internetGatewayId=%s', $arn->resourceId)),
            'security-group' => static::regional($arn, sprintf('ec2/home#SecurityGroup:groupId=%s', $arn->resourceId)),
            default => null,
        };
    }

    protected static function logs(Arn $arn): ?string
    {
        if ($arn->resourceType !== 'log-group') {
            return null;
        }

        // Strip the describe-ARN's trailing ':*', then apply the CloudWatch console's
        // own path encoding: every '/' in the log-group name becomes '$252F'.
        $name = preg_replace('/:\*$/', '', $arn->resourceId);

        return static::regional($arn, sprintf(
            'cloudwatch/home#logsV2:log-groups/log-group/%s',
            str_replace('/', '$252F', $name),
        ));
    }

    protected static function sqs(Arn $arn): ?string
    {
        if ($arn->region === '') {
            return null;
        }

        // The SQS console keys off the queue URL, not the ARN.
        $queueUrl = sprintf('https://sqs.%s.amazonaws.com/%s/%s', $arn->region, $arn->accountId, $arn->resourceId);

        return static::regional($arn, sprintf('sqs/v3/home#/queues/%s', rawurlencode($queueUrl)));
    }

    protected static function iam(Arn $arn): ?string
    {
        return match ($arn->resourceType) {
            'role' => sprintf('https://console.aws.amazon.com/iam/home#/roles/details/%s', basename($arn->resourceId)),
            'policy' => sprintf('https://console.aws.amazon.com/iam/home#/policies/details/%s', rawurlencode($arn->value)),
            'oidc-provider' => 'https://console.aws.amazon.com/iam/home#/identity_providers',
            default => null,
        };
    }

    protected static function rds(Arn $arn): ?string
    {
        // The RDS console keys off the identifier plus a cluster flag.
        return match ($arn->resourceType) {
            'db' => static::regional($arn, sprintf('rds/home#database:id=%s;is-cluster=false', $arn->resourceId)),
            'cluster' => static::regional($arn, sprintf('rds/home#database:id=%s;is-cluster=true', $arn->resourceId)),
            default => null,
        };
    }

    protected static function elastiCache(Arn $arn): ?string
    {
        // The console lists Valkey under the Redis OSS view.
        return $arn->resourceType === 'replicationgroup'
            ? static::regional($arn, sprintf('elasticache/home#/redis/%s', $arn->resourceId))
            : null;
    }

    /** No per-alarm ARN is threaded through the dashboard, so the region-wide list is the closest stable target. */
    public static function cloudWatchAlarms(string $region): string
    {
        return sprintf('https://%s.console.aws.amazon.com/cloudwatch/home?region=%s#alarmsV2:', $region, $region);
    }
}
