<?php

namespace Codinglabs\Yolo\Steps\Start;

use GuzzleHttp\Client;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\TenantStep;
use Codinglabs\Yolo\Contracts\RunsOnAwsWeb;

class WarmMultitenantedApplicationStep extends TenantStep implements RunsOnAwsWeb
{
    public function __invoke(array $options): StepResult
    {
        // make a request to each tenant index to warm the cacheable things
        (new Client(['timeout' => 10]))
            ->get('localhost', [
                'headers' => [
                    'Host' => $this->config()['domain'],
                    'X-Forwarded-Proto' => 'https',
                    'User-Agent' => 'YOLO-Warmer/1.0',
                ],
            ])
            ->getBody();

        return StepResult::SUCCESS;
    }
}
