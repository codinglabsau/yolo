<?php

use Codinglabs\Yolo\Resources\Iam\EcsTaskRole;
use Codinglabs\Yolo\Resources\Iam\EcsTaskPolicy;

it('describes the ECS task policy with the four ssmmessages exec permissions', function () {
    $document = (new EcsTaskPolicy())->document();

    expect($document['Version'])->toBe('2012-10-17');
    expect($document['Statement'])->toHaveCount(1);
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
