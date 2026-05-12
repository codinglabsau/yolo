<?php

namespace Codinglabs\Yolo\Steps\Recording;

use ZipArchive;
use GuzzleHttp\Client;
use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;

use function Laravel\Prompts\note;

class SyncIvsRemuxFfmpegLayerStep implements Step
{
    // John Van Der Loo static builds — widely used for Lambda, amd64 matches x86_64 Lambda runtime
    private const FFMPEG_URL = 'https://johnvansickle.com/ffmpeg/releases/ffmpeg-release-amd64-static.tar.xz';

    public function __invoke(array $options): StepResult
    {
        if (! Manifest::ivsRecordingEnabled()) {
            return StepResult::SKIPPED;
        }

        if (! Manifest::ivsRealtimeRemuxWebhookUrl()) {
            return StepResult::SKIPPED;
        }

        if (! Manifest::ivsWebhookSecret()) {
            return StepResult::SKIPPED;
        }

        if (! Manifest::ivsRealtimeMainBucket()) {
            return StepResult::SKIPPED;
        }

        if (Manifest::ivsRemuxFfmpegLayerArn()) {
            return StepResult::SYNCED;
        }

        if (Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_CREATE;
        }

        $tarPath = sys_get_temp_dir() . '/yolo-ffmpeg.tar.xz';
        $extractDir = sys_get_temp_dir() . '/yolo-ffmpeg-extract';

        try {
            note('Downloading FFmpeg static binary (~80 MB)...');

            (new Client(['timeout' => 300]))->get(self::FFMPEG_URL, ['sink' => $tarPath]);

            @mkdir($extractDir, 0755, true);
            exec('tar xf ' . escapeshellarg($tarPath) . ' -C ' . escapeshellarg($extractDir));

            exec('find ' . escapeshellarg($extractDir) . ' -maxdepth 2 -name ffmpeg -type f', $found);
            $ffmpegBin = $found[0] ?? null;

            if (! $ffmpegBin) {
                throw new \RuntimeException('Could not find ffmpeg binary in extracted archive');
            }

            chmod($ffmpegBin, 0755);

            $zipContent = $this->buildLayerZip($ffmpegBin);
        } finally {
            @unlink($tarPath);
            exec('rm -rf ' . escapeshellarg($extractDir));
        }

        // Lambda direct zip upload limit is 50 MB — route via S3 (the binary zip is ~70 MB)
        $bucket = SyncIvsRealtimeRecordingBucketStep::bucketName();
        $s3Key = 'lambda-layers/ffmpeg.zip';

        Aws::s3()->putObject(['Bucket' => $bucket, 'Key' => $s3Key, 'Body' => $zipContent]);

        try {
            $layerName = Helpers::keyedResourceName('ffmpeg-layer');

            $result = Aws::lambda()->publishLayerVersion([
                'LayerName' => $layerName,
                'Description' => 'YOLO managed FFmpeg layer for IVS Real-Time remux',
                'Content' => ['S3Bucket' => $bucket, 'S3Key' => $s3Key],
                'CompatibleRuntimes' => ['python3.12'],
                'CompatibleArchitectures' => ['x86_64'],
            ]);
        } finally {
            Aws::s3()->deleteObject(['Bucket' => $bucket, 'Key' => $s3Key]);
        }

        $layerVersionArn = $result['LayerVersionArn'];

        Manifest::put('aws.ivs.recording.ffmpeg_layer_arn', $layerVersionArn);

        note(sprintf('FFmpeg layer ARN saved to yolo.yml: %s', $layerVersionArn));

        return StepResult::CREATED;
    }

    private function buildLayerZip(string $ffmpegBin): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'yolo-ffmpeg-layer') . '.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFile($ffmpegBin, 'bin/ffmpeg');
        $zip->setCompressionName('bin/ffmpeg', ZipArchive::CM_STORE);
        $zip->close();

        $content = file_get_contents($zipPath);
        unlink($zipPath);

        return $content;
    }
}
