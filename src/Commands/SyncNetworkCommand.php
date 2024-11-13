<?php

namespace Codinglabs\Yolo\Commands;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Concerns\RunsSteppedCommands;
use Symfony\Component\Console\Input\InputArgument;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\warning;

class SyncNetworkCommand extends Command
{
    use RunsSteppedCommands;

    protected array $steps = [
        // vpc
        Steps\Network\SyncVpcStep::class,

        // internet gateway
        Steps\Network\SyncInternetGatewayStep::class,
        Steps\Network\SyncInternetGatewayAttachmentStep::class,

        // subnets
        Steps\Network\SyncPublicSubnetAStep::class,
        Steps\Network\SyncPublicSubnetBStep::class,
        Steps\Network\SyncPublicSubnetCStep::class,
        Steps\Network\SyncRdsSubnetStep::class,

        // route table
        Steps\Network\SyncRouteTableStep::class,
        Steps\Network\SyncDefaultRouteStep::class,
        Steps\Network\SyncPublicSubnetsAssociationToRouteTableStep::class,

        // security groups
        Steps\Network\SyncLoadBalancerSecurityGroupStep::class,
        Steps\Network\SyncEc2SecurityGroupStep::class,
        Steps\Network\SyncRdsSecurityGroupStep::class,

        // sns
        Steps\Network\SyncSnsTopicStep::class,
    ];

    protected function configure(): void
    {
        $this
            ->setName('sync:network')
            ->addArgument('environment', InputArgument::REQUIRED, 'The environment name')
            ->addOption('dry-run', null, null, 'Run the command without making changes')
            ->setDescription('Sync the network resources for the given environment');
    }

    public function handle(): void
    {
        $environment = $this->argument('environment');

        // because the user will need to save the private key, we'll bail if a key was created
        $this->syncKeyPair($environment);

        intro(sprintf("Executing sync:network steps in %s", $environment));

        $totalTime = $this->handleSteps($environment);

        info(sprintf('Completed successfully in %ss.', $totalTime));
    }

    protected function syncKeyPair(string $environment): void
    {
        try {
            AwsResources::keyPair();
        } catch (ResourceDoesNotExistException $e) {
            if (! confirm('A key pair is required to access EC2 instances. Would you like to create one now?')) {
                exit(1);
            }

            $key = Aws::ec2()->createKeyPair([
                'KeyName' => Helpers::keyedResourceName(exclusive: false),
                'TagSpecifications' => [
                    [
                        'ResourceType' => 'key-pair',
                        ...Aws::tags([
                            'Name' => Helpers::keyedResourceName(exclusive: false),
                        ]),
                    ],
                ],
            ]);

            $envFilename = ".env.$environment";
            $suggestedPath = sprintf("~/.ssh/%s", Helpers::keyedResourceName(exclusive: false));
            $suggestedEnv = sprintf('%s=%s', Helpers::keyedEnvName('SSH_KEY'), $suggestedPath);

            intro(
                sprintf(
                    "A key pair has been created to access EC2 instances. Save the below private key to somewhere like %s",
                    $suggestedPath
                )
            );

            note($key['KeyMaterial']);

            if (file_exists(Paths::base($envFilename))) {
                file_put_contents(
                    Paths::base($envFilename),
                    PHP_EOL . $suggestedEnv . PHP_EOL,
                    FILE_APPEND
                );

                warning("$suggestedEnv has been added to $envFilename. Update as required to match the location where you saved the private key.");

                $exitCode = 0;
            } else {
                warning(sprintf("Could not find $envFilename in the current directory. You will need to add an entry like %s to allow YOLO to authenticate.", $suggestedEnv));
                $exitCode = 1;
            }

            warning("Re-run 'yolo network:sync $environment' after saving the private key to complete setup.");

            exit($exitCode);
        }
    }
}
