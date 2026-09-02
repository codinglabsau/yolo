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
