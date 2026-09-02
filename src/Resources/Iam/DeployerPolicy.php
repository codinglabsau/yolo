<?php

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\Enums\Scope;
use Aws\Iam\Exception\IamException;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Aws\Iam as IamClient;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\Ecs\EcsCluster;
use Codinglabs\Yolo\Resources\Ecs\EcsService;
use Codinglabs\Yolo\Resources\S3\AssetBucket;
use Codinglabs\Yolo\Resources\Ecr\EcrRepository;
use Codinglabs\Yolo\Resources\S3\S3ConfigBucket;
use Codinglabs\Yolo\Resources\S3\EnvConfigBucket;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Exactly the permissions `yolo deploy` exercises, co-located so a new deploy
 * step bumps the deployer's grant in the same place and CI never drifts into
 * AccessDenied. Every ARN is manifest-derived — no live AWS calls, no coupling
 * to resources later sync phases provision.
 */
class DeployerPolicy implements Deletable, Resource, SynchronisesConfiguration
{
    use ResolvesTags;
    use SynchronisesPolicyDocument;

    public function name(): string
    {
        return $this->keyedName(Iam::DEPLOYER_POLICY);
    }

    public function scope(): Scope
    {
        return Scope::App;
    }

    public function exists(): bool
    {
        try {
            IamClient::policy($this->name());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return IamClient::policy($this->name())['Arn'];
    }

    public function create(): void
    {
        Aws::iam()->createPolicy([
            'PolicyName' => $this->name(),
            'Description' => $this->description(),
            'PolicyDocument' => json_encode($this->document()),
            ...Aws::tags($this->tags()),
        ]);
    }

    /** IAM Description allows only printable ASCII + Latin-1 (no em dashes or smart quotes) — pinned by IamDescriptionsAreSafeTest. */
    public function description(): string
    {
        return 'YOLO managed deploy-time permissions for the GitHub Actions deployer role';
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseIamPolicyTags($this->arn(), $this->tags(), $apply);
    }

    /**
     * IAM refuses to delete a policy that is still attached anywhere or carries
     * non-default versions, so detach and prune before deletePolicy.
     */
    public function delete(): void
    {
        try {
            $policyArn = $this->arn();

            $entities = Aws::iam()->listEntitiesForPolicy([
                'PolicyArn' => $policyArn,
            ]);

            foreach ($entities['PolicyRoles'] ?? [] as $role) {
                Aws::iam()->detachRolePolicy([
                    'RoleName' => $role['RoleName'],
                    'PolicyArn' => $policyArn,
                ]);
            }

            foreach ($entities['PolicyGroups'] ?? [] as $group) {
                Aws::iam()->detachGroupPolicy([
                    'GroupName' => $group['GroupName'],
                    'PolicyArn' => $policyArn,
                ]);
            }

            foreach ($entities['PolicyUsers'] ?? [] as $user) {
                Aws::iam()->detachUserPolicy([
                    'UserName' => $user['UserName'],
                    'PolicyArn' => $policyArn,
                ]);
            }

            foreach (IamClient::policyVersions($policyArn) as $version) {
                if (! ($version['IsDefaultVersion'] ?? false)) {
                    Aws::iam()->deletePolicyVersion([
                        'PolicyArn' => $policyArn,
                        'VersionId' => $version['VersionId'],
                    ]);
                }
            }

            Aws::iam()->deletePolicy([
                'PolicyArn' => $policyArn,
            ]);
        } catch (IamException $e) {
            if ($e->getAwsErrorCode() !== 'NoSuchEntity') {
                throw $e;
            }
        } catch (ResourceDoesNotExistException) {
            // Removed between exists() and here — nothing left to do.
        }
    }

    public function document(): array
    {
        $region = Manifest::get('region');
        $accountId = Aws::accountId();

        $ecrRepositoryArn = sprintf('arn:aws:ecr:%s:%s:repository/%s', $region, $accountId, (new EcrRepository())->name());

        $cluster = (new EcsCluster())->name();

        // The task-definition family is the service name.
        $serviceArns = [];
        $taskDefinitionArns = [];

        foreach (Manifest::serverGroups() as $group) {
            $name = (new EcsService($group))->name();
            $serviceArns[] = sprintf('arn:aws:ecs:%s:%s:service/%s/%s', $region, $accountId, $cluster, $name);
            $taskDefinitionArns[] = sprintf('arn:aws:ecs:%s:%s:task-definition/%s:*', $region, $accountId, $name);
        }

        $assetBucketArn = (new AssetBucket())->arn();
        $configBucketArn = (new S3ConfigBucket())->arn();
        $appManifestArn = (new EnvConfigBucket())->arn() . '/' . Paths::s3AppManifestKey();

        $statements = [
            [
                // Unscopeable ops. Read-only except RegisterTaskDefinition, which only
                // mints an immutable revision the scoped UpdateService below adopts.
                'Effect' => 'Allow',
                'Resource' => '*',
                'Action' => [
                    'ecr:GetAuthorizationToken',
                    'ecs:RegisterTaskDefinition',
                    'ecs:DescribeTaskDefinition',
                    'ecs:ListTasks',
                    'elasticloadbalancing:DescribeLoadBalancers',
                    'elasticloadbalancing:DescribeTargetGroups',
                    'elasticloadbalancing:DescribeTargetHealth',
                    'ec2:DescribeVpcs',
                    'ec2:DescribeSubnets',
                    'ec2:DescribeSecurityGroups',
                    // CloudFront has no name-based lookup; build scans the account list
                    // to bake ASSET_URL.
                    'cloudfront:ListDistributions',
                    // The task-def payload resolves role ARNs by scanning the role list.
                    'iam:ListRoles',
                    'sts:GetCallerIdentity',
                ],
            ],
            [
                'Effect' => 'Allow',
                'Resource' => $ecrRepositoryArn,
                'Action' => [
                    'ecr:BatchCheckLayerAvailability',
                    'ecr:GetDownloadUrlForLayer',
                    'ecr:BatchGetImage',
                    'ecr:InitiateLayerUpload',
                    'ecr:UploadLayerPart',
                    'ecr:CompleteLayerUpload',
                    'ecr:PutImage',
                    'ecr:DescribeImages',
                    'ecr:DescribeRepositories',
                ],
            ],
            [
                // ExecuteCommand backs the `yolo run` ECS Exec session — the same
                // app-plane execution RunTask already grants, on the same tasks.
                'Effect' => 'Allow',
                'Resource' => [
                    sprintf('arn:aws:ecs:%s:%s:cluster/%s', $region, $accountId, $cluster),
                    ...$serviceArns,
                    ...$taskDefinitionArns,
                    sprintf('arn:aws:ecs:%s:%s:task/%s/*', $region, $accountId, $cluster),
                ],
                'Action' => [
                    'ecs:DescribeClusters',
                    'ecs:DescribeServices',
                    'ecs:UpdateService',
                    'ecs:RunTask',
                    'ecs:DescribeTasks',
                    'ecs:ExecuteCommand',
                ],
            ],
            [
                // RegisterTaskDefinition with tags triggers a separate ecs:TagResource
                // check. The action is unscopeable, so the create-action condition is
                // the fence: tag only while registering a task definition.
                'Effect' => 'Allow',
                'Resource' => '*',
                'Action' => ['ecs:TagResource'],
                'Condition' => [
                    'StringEquals' => [
                        'ecs:CreateAction' => 'RegisterTaskDefinition',
                    ],
                ],
            ],
            [
                'Effect' => 'Allow',
                'Resource' => [
                    $this->taskRoleArn(),
                    $this->executionRoleArn(),
                ],
                'Action' => ['iam:PassRole'],
                'Condition' => [
                    'StringEquals' => [
                        'iam:PassedToService' => 'ecs-tasks.amazonaws.com',
                    ],
                ],
            ],
            [
                // Write-only on the per-deploy prefix. s3:PutObject covers the multipart
                // chain; abort + list-parts cover a Transfer-manager retry. No read or
                // ListBucket: PushAssetsToS3Step never reads or lists the destination.
                'Effect' => 'Allow',
                'Resource' => sprintf('%s/builds/*', $assetBucketArn),
                'Action' => [
                    's3:PutObject',
                    's3:AbortMultipartUpload',
                    's3:ListMultipartUploadParts',
                ],
            ],
            [
                // The app's env file: read on the build pull, write for `env:push`.
                // Without the write, the only path to author an app `.env` would be
                // rewriting the bucket policy via the admin tier — an escalation lever.
                'Effect' => 'Allow',
                'Resource' => sprintf('%s/%s', $configBucketArn, Paths::s3AppEnvKey()),
                'Action' => ['s3:GetObject', 's3:PutObject'],
            ],
            [
                // The claim file only — never the bucket root, so the deployer can't
                // reach the env-shared `.env` or env manifest in the same bucket
                // (reading those is what gates env-secret control).
                'Effect' => 'Allow',
                'Resource' => $appManifestArn,
                'Action' => [
                    's3:GetObject',
                    's3:PutObject',
                ],
            ],
            [
                // This app's env-side `.env` (its YOLO-minted Typesense key) — never the
                // env-shared `.env` (cluster admin key) or another app's.
                'Effect' => 'Allow',
                'Resource' => sprintf('%s/%s', (new EnvConfigBucket())->arn(), Paths::s3EnvAppEnvKey()),
                'Action' => ['s3:GetObject'],
            ],
            [
                // The pre-deploy `sync --check` gate verifies a BYO data bucket still
                // exists on this account; a probe of the bucket answers 403 for both
                // "someone else's" and "yours, unreadable", so only listing works.
                // Returns names only.
                'Effect' => 'Allow',
                'Resource' => '*',
                'Action' => ['s3:ListAllMyBuckets'],
            ],
        ];

        if (Manifest::hasDomain()) {
            $statements = [...$statements, ...$this->route53Statements()];
        }

        // Build bakes REDIS_HOST from the cluster's primary endpoint;
        // DescribeReplicationGroups is unscopeable. Apps that opt out never read
        // the cluster.
        if (Manifest::cacheStore() === 'redis') {
            $statements[] = [
                'Effect' => 'Allow',
                'Resource' => '*',
                'Action' => ['elasticache:DescribeReplicationGroups'],
            ];
        }

        // No autoscaling statement on purpose: deploy rolls a revision via
        // UpdateService without desiredCount, so App Auto Scaling keeps owning
        // capacity; scaling APIs belong to sync/scale under admin creds.

        return [
            'Version' => '2012-10-17',
            'Statement' => $statements,
        ];
    }

    protected function taskRoleArn(): string
    {
        return sprintf('arn:aws:iam::%s:role/%s', Aws::accountId(), (new EcsTaskRole())->name());
    }

    protected function executionRoleArn(): string
    {
        return sprintf('arn:aws:iam::%s:role/%s', Aws::accountId(), (new EcsExecutionRole())->name());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function route53Statements(): array
    {
        return [
            [
                // ListHostedZones is a collection operation — no resource-level scoping.
                'Effect' => 'Allow',
                'Resource' => '*',
                'Action' => ['route53:ListHostedZones'],
            ],
            [
                // Hosted-zone type, not one zone id: the id isn't derivable from the
                // domain, and resolving it live would couple the IAM phase to the zone
                // the later Solo phase creates — wedging a green-field first sync. The
                // OIDC repo/branch trust is the real fence.
                'Effect' => 'Allow',
                'Resource' => 'arn:aws:route53:::hostedzone/*',
                'Action' => ['route53:ChangeResourceRecordSets'],
            ],
            [
                'Effect' => 'Allow',
                'Resource' => 'arn:aws:route53:::change/*',
                'Action' => ['route53:GetChange'],
            ],
        ];
    }
}
