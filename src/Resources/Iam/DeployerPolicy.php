<?php

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Aws\Iam as IamClient;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\Ecs\EcsCluster;
use Codinglabs\Yolo\Resources\Ecs\EcsService;
use Codinglabs\Yolo\Resources\S3\AssetBucket;
use Codinglabs\Yolo\Resources\Ecr\EcrRepository;
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
 * Document drift is reconciled via createPolicyVersion (see EcsTaskPolicy).
 */
class DeployerPolicy implements Resource
{
    use ResolvesTags;

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

    /**
     * Policy-document drift is reconciled by creating a new version and setting
     * it as default — the mechanism that lets a YOLO upgrade that adds a deploy
     * step also widen the deployer's permissions. AWS keeps up to 5 versions.
     */
    public function synchroniseDocument(): void
    {
        $policy = IamClient::policy($this->name());
        $document = json_encode($this->document());

        $currentVersion = IamClient::policyVersion($policy['Arn'], $policy['DefaultVersionId']);

        if (urldecode($currentVersion['Document']) === $document) {
            return;
        }

        Aws::iam()->createPolicyVersion([
            'PolicyArn' => $policy['Arn'],
            'PolicyDocument' => $document,
            'SetAsDefault' => true,
        ]);
    }

    public function document(): array
    {
        $region = Manifest::get('region');
        $accountId = Aws::accountId();

        $ecrRepositoryArn = sprintf('arn:aws:ecr:%s:%s:repository/%s', $region, $accountId, (new EcrRepository())->name());

        $cluster = (new EcsCluster())->name();
        $service = (new EcsService())->name(); // also the task-definition family

        $assetBucketArn = sprintf('arn:aws:s3:::%s', (new AssetBucket())->name());
        $artefactsBucketArn = sprintf('arn:aws:s3:::%s', Paths::s3ArtefactsBucket());

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
                // Roll the new revision onto this app's service and run the one-off
                // deploy task (migrations) on its cluster.
                'Effect' => 'Allow',
                'Resource' => [
                    sprintf('arn:aws:ecs:%s:%s:cluster/%s', $region, $accountId, $cluster),
                    sprintf('arn:aws:ecs:%s:%s:service/%s/%s', $region, $accountId, $cluster, $service),
                    sprintf('arn:aws:ecs:%s:%s:task-definition/%s:*', $region, $accountId, $service),
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
                // (deploy) — object operations on the artefacts + asset buckets.
                'Effect' => 'Allow',
                'Resource' => [
                    sprintf('%s/*', $assetBucketArn),
                    sprintf('%s/*', $artefactsBucketArn),
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
                    $artefactsBucketArn,
                ],
                'Action' => [
                    's3:ListBucket',
                    's3:ListBucketMultipartUploads',
                    's3:GetBucketLocation',
                ],
            ],
        ];

        // The apex/www DNS cutover only runs for apps with a public domain. Scope
        // the record change to the app's hosted zone; the change-status poll
        // can't be scoped (change ids aren't known ahead of time).
        if (Manifest::has('apex') || Manifest::has('domain')) {
            $statements = [...$statements, ...$this->route53Statements()];
        }

        // Only apps that opt into autoscaling need the deployer role to provision
        // and reconcile the scalable target + policies (yolo sync / yolo scale).
        if (Manifest::has('tasks.web.autoscaling')) {
            $statements = [...$statements, ...$this->autoscalingStatements()];
        }

        return [
            'Version' => '2012-10-17',
            'Statement' => $statements,
        ];
    }

    /**
     * Provision and reconcile the ECS service's Application Auto Scaling target +
     * target-tracking policies. Application Auto Scaling, and the CloudWatch alarms
     * target tracking creates on its behalf, don't support resource-level
     * permissions, so these are granted on "*". CreateServiceLinkedRole is fenced
     * to exactly the ECS scaling service principal.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function autoscalingStatements(): array
    {
        return [
            [
                'Effect' => 'Allow',
                'Resource' => '*',
                'Action' => [
                    'application-autoscaling:RegisterScalableTarget',
                    'application-autoscaling:DeregisterScalableTarget',
                    'application-autoscaling:DescribeScalableTargets',
                    'application-autoscaling:PutScalingPolicy',
                    'application-autoscaling:DeleteScalingPolicy',
                    'application-autoscaling:DescribeScalingPolicies',
                    'cloudwatch:PutMetricAlarm',
                    'cloudwatch:DescribeAlarms',
                    'cloudwatch:DeleteAlarms',
                ],
            ],
            [
                'Effect' => 'Allow',
                'Resource' => 'arn:aws:iam::*:role/aws-service-role/ecs.application-autoscaling.amazonaws.com/*',
                'Action' => ['iam:CreateServiceLinkedRole'],
                'Condition' => [
                    'StringEquals' => [
                        'iam:AWSServiceName' => 'ecs.application-autoscaling.amazonaws.com',
                    ],
                ],
            ],
        ];
    }

    protected function taskRoleArn(): string
    {
        return Manifest::has('tasks.web.task-role')
            ? Manifest::get('tasks.web.task-role')
            : sprintf('arn:aws:iam::%s:role/%s', Aws::accountId(), (new EcsTaskRole())->name());
    }

    protected function executionRoleArn(): string
    {
        return Manifest::has('tasks.web.execution-role')
            ? Manifest::get('tasks.web.execution-role')
            : sprintf('arn:aws:iam::%s:role/%s', Aws::accountId(), (new EcsExecutionRole())->name());
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
