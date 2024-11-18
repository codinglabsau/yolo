<?php

namespace Codinglabs\Yolo\Concerns;

use BackedEnum;
use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Aws\Ssm\Exception\SsmException;

trait UsesSsm
{
    public static function ubuntuAmiId(): string
    {
        // Ubuntu 22.04 LTS
        return Aws::ssm()->getParameter([
            'Name' => '/aws/service/canonical/ubuntu/server/22.04/stable/current/amd64/hvm/ebs-gp2/ami-id',
        ])['Parameter']['Value'];
    }

    public static function getParameter(string|BackedEnum $key): ?string
    {
        if ($key instanceof BackedEnum) {
            $key = $key->value;
        }

        $key = '/' . Helpers::keyedResourceName($key, exclusive: false, seperator: '/');

        try {
            return Aws::ssm()->getParameter([
                'Name' => $key,
            ])['Parameter']['Value'];
        } catch (SsmException $e) {
            return null;
        }
    }

    public static function putParameter(string $key, string $value, string $description): void
    {
        $key = '/' . Helpers::keyedResourceName($key, exclusive: false, seperator: '/');

        if (static::getParameter($key) === null) {
            Aws::ssm()->putParameter([
                'Name' => $key,
                'Value' => $value,
                'Type' => 'String',
                'Description' => $description,
                ...Aws::tags(),
            ]);

            return;
        }

        Aws::ssm()->putParameter([
            'Name' => $key,
            'Value' => $value,
            'Type' => 'String',
            'Description' => $description,
            'Overwrite' => true,
        ]);
    }
}
