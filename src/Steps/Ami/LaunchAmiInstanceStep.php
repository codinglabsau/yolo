<?php

namespace Codinglabs\Yolo\Steps\Ami;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Concerns\UsesEc2;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\ResourceExistsException;

class LaunchAmiInstanceStep implements Step
{
    use UsesEc2;

    public function __invoke(): StepResult
    {
        if ($instance = static::ec2ByName(
            'AMI',
            states: ['pending', 'running', 'stopping', 'stopped'],
            throws: false
        )) {
            throw new ResourceExistsException("AMI instance already exists in state '{$instance['State']['Name']}'. It must be manually terminated before creating a new AMI.");
        }

        Aws::ec2()->runInstances([
            // Ubuntu 22.04 LTS
            'ImageId' => Manifest::get('aws.ec2.os-image-id'),

            // Set the AMI name
            'TagSpecifications' => [
                [
                    'ResourceType' => 'instance',
                    'Tags' => [
                        [
                            'Key' => 'Name',
                            'Value' => 'AMI',
                        ],
                    ],
                ],
            ],

            // 8GB storage on root volume
            'BlockDeviceMappings' => [
                [
                    'DeviceName' => '/dev/sda1',
                    'Ebs' => [
                        'VolumeSize' => 8,
                        'VolumeType' => 'gp2',
                    ],
                ],
            ],

            // something with some grunt to execute steps quickly
            'InstanceType' => 't3.xlarge',

            // use the existing key pair
            'KeyName' => Manifest::name(),

            // 1 server only per favor (min+max are both required)
            'MaxCount' => 1,
            'MinCount' => 1,

            // use the existing security group and subnet
            'SecurityGroupIds' => [Manifest::get('aws.security-group-id')],
            'SubnetId' => static::subnets()[0]['SubnetId'],

            // execute UserData scripts on launch
            'UserData' => base64_encode(file_get_contents(Paths::stubs('ami.sh'))),

            // require IMDSv2 metadata service on 169.254.169.254
            'MetadataOptions' => [
                'HttpTokens' => 'required',
                'HttpPutResponseHopLimit' => 1,
                'HttpEndpoint' => 'enabled',
            ],
        ]);

        while (true) {
            // wait for instance to be running with an assigned public IP address
            if ($instance = static::ec2ByName('AMI', throws: false)) {
                Helpers::app()->singleton('amiInstanceId', fn () => $instance['InstanceId']);
                Helpers::app()->singleton('amiIp', fn () => $instance['PublicIpAddress']);
                break;
            }

            sleep(3);
        }

        return StepResult::SUCCESS;
    }
}
