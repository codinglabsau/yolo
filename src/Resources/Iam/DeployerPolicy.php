<?php

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\EnvManifest;
use Codinglabs\Yolo\Resources\Resource;
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
 * YOLO-managed customer-managed IAM policy granting exactly the AWS permissions
 * `yolo deploy` exercises — co-located with the deploy steps so that when a new
 * deploy step calls a new AWS API, the deployer's permission is bumped in the
 * same place and CI never drifts into AccessDenied. Attached to the deployer
 * role by AttachDeployerRolePoliciesStep.
 *
 * All resource ARNs are constructed deterministically from the manifest
 * (region, account id, app name), so the document is pure — no live AWS calls,
 * and no coupling to resources later sync phases provision.
 *
 * Document drift is reconciled as a plan-visible Change via the shared
 * SynchronisesPolicyDocument trait (createPolicyVersion + 5-version pruning).
 */
class DeployerPolicy implements Resource, SynchronisesConfiguration
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

    /**
     * IAM Description fields enforce a restricted character set
     * (tab/LF/CR + printable ASCII + Latin-1 Supplement) — no em dashes,
     * smart quotes, or U+007F - U+00A0 control range. Validated by
     * IamDescriptionsAreSafeTest.
     */
    public function description(): string
    {
        return 'YOLO managed deploy-time permissions for the GitHub Actions deployer role';
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseIamPolicyTags($this->arn(), $this->tags(), $apply);
    }

    public function document(): array
    {
        $region = Manifest::get('region');
        $accountId = Aws::accountId();

        $ecrRepositoryArn = sprintf('arn:aws:ecr:%s:%s:repository/%s', $region, $accountId, (new EcrRepository())->name());

        $cluster = (new EcsCluster())->name();

        // Each service group (web + any standalone queue/scheduler) gets its own
        // service + task-definition family (the family is the service name), so the
        // deployer needs UpdateService/RegisterTaskDefinition scoped to all of them.
        $serviceArns = [];
        $taskDefinitionArns = [];

        foreach (Manifest::serverGroups() as $group) {
            $name = (new EcsService($group))->name();
            $serviceArns[] = sprintf('arn:aws:ecs:%s:%s:service/%s/%s', $region, $accountId, $cluster, $name);
            $taskDefinitionArns[] = sprintf('arn:aws:ecs:%s:%s:task-definition/%s:*', $region, $accountId, $name);
        }

        $assetBucketArn = (new AssetBucket())->arn();
        $configBucketArn = (new S3ConfigBucket())->arn();
        $envConfigBucketArn = (new EnvConfigBucket())->arn();
        $appManifestArn = $envConfigBucketArn . '/' . Paths::s3AppManifestKey();

        $statements = [
            [
                // Operations AWS does not support resource-level permissions for,
                // so they must be granted on "*". Read-only, except
                // RegisterTaskDefinition which only mints an immutable revision
                // that the scoped UpdateService below then adopts.
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
                    // Build resolves the asset distribution by scanning the
                    // account list (CloudFront has no name-based lookup) to bake
                    // ASSET_URL before `npm run build`.
                    'cloudfront:ListDistributions',
                    // The task-definition payload resolves the task + execution
                    // role ARNs by scanning the account role list.
                    'iam:ListRoles',
                    'sts:GetCallerIdentity',
                ],
            ],
            [
                // Push the application image to this app's ECR repository.
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
                // Roll the new revision onto this app's services and run the one-off
                // deploy task (migrations) on its cluster.
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
                ],
            ],
            [
                // ECS runs a separate ecs:TagResource authorization check when
                // RegisterTaskDefinition is called with tags, and the task-def
                // payload always stamps yolo:environment + Name. RegisterTaskDefinition
                // itself is unscopeable (granted on * above), so the create-action
                // condition — not a resource ARN — is the fence: the deployer can
                // tag only as part of registering a task definition, never retag
                // arbitrary ECS resources.
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
                // Hand the task + execution roles to ECS when registering the task
                // definition — scoped to exactly the two roles YOLO manages, and
                // only to the ECS tasks service.
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
                // Pull the environment file (build) and push the public asset tree
                // (deploy) — object operations on the config + asset buckets.
                'Effect' => 'Allow',
                'Resource' => [
                    sprintf('%s/*', $assetBucketArn),
                    sprintf('%s/*', $configBucketArn),
                ],
                'Action' => [
                    's3:GetObject',
                    's3:PutObject',
                    's3:AbortMultipartUpload',
                    's3:ListMultipartUploadParts',
                ],
            ],
            [
                // Bucket-level operations the asset transfer + env pull need.
                'Effect' => 'Allow',
                'Resource' => [
                    $assetBucketArn,
                    $configBucketArn,
                ],
                'Action' => [
                    's3:ListBucket',
                    's3:ListBucketMultipartUploads',
                    's3:GetBucketLocation',
                ],
            ],
            [
                // Publish this app's claim file into the env config bucket on
                // every deploy (PublishAppManifestStep reads then writes it).
                // Scoped to exactly this app's `apps/{app}.yml` object — never
                // the bucket root, so the deployer can't reach the env-shared
                // `.env` or env manifest that share the bucket. Read of those is
                // the permission that gates env-secret control; app deploys must
                // not hold it.
                'Effect' => 'Allow',
                'Resource' => $appManifestArn,
                'Action' => [
                    's3:GetObject',
                    's3:PutObject',
                ],
            ],
            [
                // The deployer role also carries AWS-managed ReadOnlyAccess (attached
                // by AttachDeployerRolePoliciesStep) so the pre-deploy `sync --check`
                // gate can read every service's live state without an AccessDenied
                // aborting it. ReadOnlyAccess grants s3:GetObject on *every* bucket —
                // which would let a deploy read the env-shared `.env` and other apps'
                // secrets, the one thing this role must never reach. Claw object reads
                // back to the app's own objects plus the env-shared *config* the gate
                // legitimately needs: the env manifest and the app claim files
                // (`apps/*.yml`) — declarations of what each env/app provisions, not
                // secrets — which the env-tier plan and the claim gate read. The
                // env-shared `.env` is NOT carved out, so it stays unreadable. Bucket-
                // level Describe/Get* reads ReadOnlyAccess grants are metadata only and
                // stay — the plan needs them to diff bucket configuration.
                'Effect' => 'Deny',
                'Action' => [
                    's3:GetObject',
                    's3:GetObjectVersion',
                ],
                'NotResource' => [
                    sprintf('%s/*', $assetBucketArn),
                    sprintf('%s/*', $configBucketArn),
                    sprintf('%s/apps/*', $envConfigBucketArn),
                    sprintf('%s/%s', $envConfigBucketArn, EnvManifest::filename()),
                ],
            ],
        ];

        // The apex/www DNS cutover only runs for apps with a public domain. Scope
        // the record change to the app's hosted zone; the change-status poll
        // can't be scoped (change ids aren't known ahead of time).
        if (Manifest::has('apex') || Manifest::has('domain')) {
            $statements = [...$statements, ...$this->route53Statements()];
        }

        // When the app uses the shared Valkey cache (`cache.store: redis`, the
        // web-app default), the build bakes REDIS_HOST by reading the cluster's
        // primary endpoint (ConfigureEnvAndVersionStep -> CacheCluster::endpoint()).
        // DescribeReplicationGroups has no resource-level scoping, so it's granted
        // on "*". Apps that opt out (file/database/array) never read the cluster
        // and so get no elasticache permission.
        if (Manifest::cacheStore() === 'redis') {
            $statements[] = [
                'Effect' => 'Allow',
                'Resource' => '*',
                'Action' => ['elasticache:DescribeReplicationGroups'],
            ];
        }

        // Autoscaling deliberately gets no statement here: `yolo deploy` never
        // touches the scalable target or its policies (a deploy rolls a task-def
        // revision and UpdateService without desiredCount — App Auto Scaling keeps
        // owning capacity straight through). The scaling APIs are exercised only by
        // `yolo sync` / `yolo scale`, which run with admin creds, not this role.

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
                // Scoped to the hosted-zone resource type rather than one resolved
                // zone id. The id isn't derivable from the domain, and resolving it
                // live here would couple the IAM sync phase to the hosted zone that
                // the later Solo phase creates — wedging the first `yolo sync` on a
                // green-field account. The OIDC repo/branch trust boundary is the
                // real fence; a single deploy role changing records in its own
                // account is an acceptable scope.
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
