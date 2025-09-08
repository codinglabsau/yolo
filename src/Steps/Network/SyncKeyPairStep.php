<?php

namespace Codinglabs\Yolo\Steps\Network;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Commands\Command;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

use function Laravel\Prompts\note;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\warning;

class SyncKeyPairStep implements Step
{
    public function __invoke(array $options, Command $command): StepResult
    {
        try {
            AwsResources::keyPair();

            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException $e) {
            $name = Manifest::get('aws.ec2.key-pair', Helpers::keyedResourceName(exclusive: false));

            $key = Aws::ec2()->createKeyPair([
                'KeyName' => $name,
                'TagSpecifications' => [
                    [
                        'ResourceType' => 'key-pair',
                        ...Aws::tags([
                            'Name' => $name,
                        ]),
                    ],
                ],
            ]);

            $envFilename = '.env';
            $suggestedPath = sprintf('~/.ssh/%s', $name);
            $suggestedEnv = sprintf('%s=%s', Helpers::keyedEnvName('SSH_KEY'), $suggestedPath);

            $command->after(function () use ($suggestedPath, $key) {
                intro(
                    sprintf(
                        'A key pair has been created to access EC2 instances. Save the below private key to somewhere like %s',
                        $suggestedPath
                    )
                );

                note($key['KeyMaterial']);
            });

            if (file_exists(Paths::base($envFilename))) {
                file_put_contents(
                    Paths::base($envFilename),
                    PHP_EOL . $suggestedEnv . PHP_EOL,
                    FILE_APPEND
                );

                $command->after(fn () => warning("$suggestedEnv has been added to $envFilename. Update as required to match the location where you saved the private key."));
            } else {
                $command->after(fn () => warning(sprintf("Could not find $envFilename in the current directory. You will need to add an entry like %s to allow YOLO to authenticate.", $suggestedEnv)));
            }

            $command->after(fn () => warning(sprintf("Re-run 'yolo network:sync %s' after saving the private key to complete setup.", Helpers::environment())));

            return StepResult::CREATED;
        }
    }
}
