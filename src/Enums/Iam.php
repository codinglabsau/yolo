<?php

namespace Codinglabs\Yolo\Enums;

enum Iam: string
{
    case INSTANCE_PROFILE = 'instance-profile';
    case MEDIA_CONVERT_ROLE = 'mediaconvert-role';
    case LAMBDA_IVS_REMUX_ROLE = 'lambda-ivs-remux-role';
}
