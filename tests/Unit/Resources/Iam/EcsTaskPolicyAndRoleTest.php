<?php

use Codinglabs\Yolo\Resources\Iam\EcsTaskRole;
use Codinglabs\Yolo\Resources\Iam\EcsTaskPolicy;

beforeEach(function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
    ]);
});

it('describes the ECS task policy with the four ssmmessages exec permissions', function () {
    $document = (new EcsTaskPolicy())->document();

    expect($document['Version'])->toBe('2012-10-17');
    expect($document['Statement'])->toHaveCount(2);
    expect($document['Statement'][0])->toMatchArray([
        'Effect' => 'Allow',
        'Resource' => '*',
    ]);

    expect($document['Statement'][0]['Action'])->toEqualCanonicalizing([
        'ssmmessages:CreateControlChannel',
        'ssmmessages:CreateDataChannel',
        'ssmmessages:OpenControlChannel',
        'ssmmessages:OpenDataChannel',
    ]);
});

it('grants SQS access scoped to this environment\'s YOLO queues', function () {
    $statement = (new EcsTaskPolicy())->document()['Statement'][1];

    expect($statement['Effect'])->toBe('Allow');
    expect($statement['Resource'])->toBe('arn:aws:sqs:ap-southeast-2:111111111111:yolo-testing-*');
    expect($statement['Action'])->toContain('sqs:ReceiveMessage', 'sqs:DeleteMessage', 'sqs:SendMessage', 'sqs:ChangeMessageVisibility');
});

it('trusts the ecs-tasks service in the ECS task assume role policy', function () {
    expect((new EcsTaskRole())->assumeRolePolicyDocument())->toBe([
        'Version' => '2012-10-17',
        'Statement' => [
            [
                'Effect' => 'Allow',
                'Principal' => ['Service' => 'ecs-tasks.amazonaws.com'],
                'Action' => 'sts:AssumeRole',
            ],
        ],
    ]);
});
