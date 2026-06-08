<?php

namespace Codinglabs\Yolo\Steps\Build\Fargate;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Symfony\Component\Process\Process;

class LoginToEcrStep implements Step
{
    public function __construct(protected string $environment) {}

    public function __invoke(array $options = []): StepResult
    {
        $token = Aws::ecr()->getAuthorizationToken()['authorizationData'][0];
        [, $password] = explode(':', base64_decode((string) $token['authorizationToken']), 2);

        $process = new Process(
            command: [
                'docker', 'login',
                '--username', 'AWS',
                '--password-stdin',
                $this->registry(),
            ],
            timeout: 60,
        );

        $process->setInput($password);
        $process->mustRun();

        return StepResult::SUCCESS;
    }

    protected function registry(): string
    {
        return sprintf(
            '%s.dkr.ecr.%s.amazonaws.com',
            Aws::accountId(),
            Manifest::get('region'),
        );
    }
}
