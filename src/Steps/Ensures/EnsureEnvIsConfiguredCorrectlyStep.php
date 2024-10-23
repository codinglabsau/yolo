<?php

namespace Codinglabs\Yolo\Steps\Ensures;

use Dotenv\Dotenv;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Illuminate\Filesystem\Filesystem;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class EnsureEnvIsConfiguredCorrectlyStep implements Step
{
    public function __construct(protected string $environment, protected $filesystem = new Filesystem())
    {

    }

    public function __invoke(): StepResult
    {
        if (Manifest::get('aws.transcoder')) {
            $this->checkTranscoderConfiguration();
        }

        return StepResult::SYNCED;
    }

    /**
     * @throws IntegrityCheckException
     * @throws ResourceDoesNotExistException
     * @throws FileNotFoundException
     */
    protected function checkTranscoderConfiguration(): void
    {
        $elasticTranscoderPipeline = AwsResources::elasticTranscoderPipeline();
        $elasticTranscoderPreset = AwsResources::elasticTranscoderPreset();

        $dotenv = Dotenv::parse($this->filesystem->get(Paths::build('.env')));

        if ($dotenv['AWS_TRANSCODER_PIPELINE'] !== $elasticTranscoderPipeline['Id']) {
            throw new IntegrityCheckException("Transcoder piepeline ID {$dotenv['AWS_TRANSCODER_PIPELINE']} does not match {$elasticTranscoderPipeline['Id']}");
        }

        if ($dotenv['AWS_TRANSCODER_PRESET'] != $elasticTranscoderPreset['Id']) {
            throw new IntegrityCheckException("Transcoder preset ID {$dotenv['AWS_TRANSCODER_PRESET']} does not match {$elasticTranscoderPreset['Id']}");
        }
    }
}
