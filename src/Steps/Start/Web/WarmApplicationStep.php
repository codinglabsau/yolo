<?php

namespace Codinglabs\Yolo\Steps\Start\Web;

use GuzzleHttp\Client;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\RunsOnAwsWeb;

class WarmApplicationStep implements RunsOnAwsWeb
{
    public function __invoke(array $options): StepResult
    {
        // make a request to each tenant index to warm the cacheable things
        (new Client(['timeout' => 10]))
            ->get('localhost', [
                'headers' => [
                    'Host' => Manifest::get('domain'),
                    'X-Forwarded-Proto' => 'https',
                    'User-Agent' => 'YOLO-Warmer/1.0',
                ],
            ])
            ->getBody();

        return StepResult::SUCCESS;
    }
}
