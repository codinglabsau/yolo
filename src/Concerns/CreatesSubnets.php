<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;

trait CreatesSubnets
{
    public function createSubnet(string $name, int $index): void
    {
        $vpc = AwsResources::vpc();
        $availabilityZones = AwsResources::availabilityZones(Manifest::get('aws.region'));

        Aws::ec2()->createSubnet([
            'AvailabilityZone' => $availabilityZones[$index]['ZoneName'],
            'CidrBlock' => "10.1.$index.0/24",
            'VpcId' => $vpc['VpcId'],
            'TagSpecifications' => [
                [
                    'ResourceType' => 'subnet',
                    'Tags' => [
                        [
                            'Key' => 'Name',
                            'Value' => Helpers::keyedResourceName($name, exclusive: false),
                        ],
                    ],
                ],
            ],
        ]);
    }
}
