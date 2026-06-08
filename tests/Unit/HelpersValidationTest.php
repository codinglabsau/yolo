<?php

declare(strict_types=1);

use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

describe('validatePositiveInt', function (): void {
    it('accepts a positive int', function (): void {
        expect(Helpers::validatePositiveInt(30, 'k'))->toBe(30);
    });

    it('accepts a numeric string', function (): void {
        expect(Helpers::validatePositiveInt('30', 'k'))->toBe(30);
    });

    it('rejects zero', function (): void {
        Helpers::validatePositiveInt(0, 'tasks.web.autoscaling.min');
    })->throws(IntegrityCheckException::class, 'tasks.web.autoscaling.min must be a positive integer');

    it('rejects negative', function (): void {
        Helpers::validatePositiveInt(-5, 'k');
    })->throws(IntegrityCheckException::class);

    it('rejects garbage strings', function (): void {
        Helpers::validatePositiveInt('thirty', 'k');
    })->throws(IntegrityCheckException::class);

    it('rejects floats', function (): void {
        Helpers::validatePositiveInt(1.7, 'k');
    })->throws(IntegrityCheckException::class);

    it('rejects null', function (): void {
        Helpers::validatePositiveInt(null, 'k');
    })->throws(IntegrityCheckException::class);
});

describe('validateCloudWatchLogRetention', function (): void {
    it('accepts valid retention values', function (): void {
        foreach ([1, 3, 5, 7, 14, 30, 60, 90, 120, 150, 180, 365, 400, 545, 731, 1827, 2192, 2557, 2922, 3288, 3653] as $valid) {
            expect(Helpers::validateCloudWatchLogRetention($valid, 'k'))->toBe($valid);
        }
    });

    it('accepts numeric strings of valid values', function (): void {
        expect(Helpers::validateCloudWatchLogRetention('30', 'k'))->toBe(30);
    });

    it('rejects values not in the enum', function (): void {
        Helpers::validateCloudWatchLogRetention(45, 'tasks.web.log-retention');
    })->throws(IntegrityCheckException::class, 'tasks.web.log-retention must be one of CloudWatch Logs retention values');

    it('rejects zero', function (): void {
        Helpers::validateCloudWatchLogRetention(0, 'k');
    })->throws(IntegrityCheckException::class);

    it('rejects garbage', function (): void {
        Helpers::validateCloudWatchLogRetention('forever', 'k');
    })->throws(IntegrityCheckException::class);
});

describe('validateStrictBool', function (): void {
    it('accepts true', function (): void {
        expect(Helpers::validateStrictBool(true, 'k'))->toBeTrue();
    });

    it('accepts false', function (): void {
        expect(Helpers::validateStrictBool(false, 'k'))->toBeFalse();
    });

    it('accepts the string "true"', function (): void {
        expect(Helpers::validateStrictBool('true', 'k'))->toBeTrue();
    });

    it('accepts the string "false" as false', function (): void {
        // This is the whole point — PHP's (bool) cast on "false" returns true; filter_var doesn't.
        expect(Helpers::validateStrictBool('false', 'k'))->toBeFalse();
    });

    it('accepts the strings "yes" and "no"', function (): void {
        expect(Helpers::validateStrictBool('yes', 'k'))->toBeTrue();
        expect(Helpers::validateStrictBool('no', 'k'))->toBeFalse();
    });

    it('rejects garbage', function (): void {
        Helpers::validateStrictBool('maybe', 'tasks.web.enable-execute-command');
    })->throws(IntegrityCheckException::class, 'tasks.web.enable-execute-command must be a boolean');
});
