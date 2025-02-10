<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

trait UsesIam
{
    public static function ec2Role(): array
    {
        $name = Helpers::keyedResourceName(exclusive: false);
        $roles = Aws::iam()->listRoles();

        foreach ($roles['Roles'] as $role) {
            if ($role['RoleName'] === $name) {
                return $role;
            }
        }

        throw new ResourceDoesNotExistException("Could not find IAM role with name $name");
    }
}
