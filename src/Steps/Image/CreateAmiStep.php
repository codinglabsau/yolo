<?php

namespace Codinglabs\Yolo\Steps\Image;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Concerns\UsesEc2;

class CreateAmiStep implements Step
{
    use UsesEc2;

    public function __invoke(): string
    {
        $ami = Aws::ec2()->createImage([
            'InstanceId' => Helpers::app('amiInstanceId'),
            'Name' => Helpers::keyedResourceName(date('y.W.N.Hi'), exclusive: false),
            'TagSpecifications' => [
                [
                    'ResourceType' => 'image',
                    ...Aws::tags(),
                ],
            ],
        ]);

        while (true) {
            // wait for AMI to be available
            $ami = Aws::ec2()->describeImages([
                'ImageIds' => [$ami['ImageId']],
            ])['Images'][0];

            if ($ami['State'] === 'available') {
                break;
            }

            sleep(3);
        }

        return $ami['ImageId'];
    }
}
