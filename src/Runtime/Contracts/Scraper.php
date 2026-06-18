<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime\Contracts;

use Codinglabs\Yolo\Runtime\ScrapeResult;
use Codinglabs\Yolo\Runtime\WorkerSaturationReporter;

/**
 * Reads FrankenPHP's worker saturation once and classifies the result. The seam
 * lets {@see WorkerSaturationReporter} be tested without a
 * live metrics endpoint.
 */
interface Scraper
{
    public function scrape(): ScrapeResult;
}
