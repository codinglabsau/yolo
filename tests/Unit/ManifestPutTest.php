<?php

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;

/**
 * Write a hand-formatted manifest (comments, blank lines, ordering) straight to
 * disk — bypassing writeManifest()'s Yaml::dump, which would strip all of that —
 * so the surgical Manifest::put can be asserted to preserve everything but the
 * value it changes.
 */
function writeFormattedManifest(string $yaml, string $environment = 'production'): void
{
    file_put_contents(Paths::manifest(), $yaml);
    Helpers::app()->instance('environment', $environment);
}

// These tests set a 'production' env + a custom manifest file. Restore the
// bootstrap default (env 'testing', stub manifest) after each so we don't
// pollute global state for tests that rely on it under `pest --parallel` —
// e.g. PathsTest, which has no beforeEach and asserts yolo-testing-my-app.
afterEach(fn () => writeManifest([]));

$richManifest = <<<'YAML'
name: my-app  # the application

environments:
  production:
    # capacity tuning lives here
    tasks:
      web:
        queue: true
        scheduler: true

        autoscaling:
          min: 1   # baseline floor
          max: 6
YAML;

it('updates an existing scalar and preserves comments, blank lines, ordering and spacing', function () use ($richManifest) {
    writeFormattedManifest($richManifest);

    Manifest::put('tasks.web.autoscaling.min', 3);

    // Only the `1` becomes `3`; every other byte — the inline comment, the blank
    // line, the standalone comment, key order — is untouched.
    $expected = str_replace('min: 1   # baseline floor', 'min: 3   # baseline floor', $richManifest);

    expect(file_get_contents(Paths::manifest()))->toBe($expected);
});

it('changes exactly one line', function () use ($richManifest) {
    writeFormattedManifest($richManifest);

    Manifest::put('tasks.web.autoscaling.max', 10);

    $before = explode("\n", $richManifest);
    $after = explode("\n", file_get_contents(Paths::manifest()));

    $differing = collect($after)->diffAssoc(collect($before));

    expect($differing->all())->toBe([12 => '          max: 10']);
});

it('inserts a missing key as the first child of its existing parent block', function () {
    writeFormattedManifest(<<<'YAML'
name: my-app

environments:
  production:
    tasks:
      web:
        autoscaling:
          max: 6
YAML);

    Manifest::put('tasks.web.autoscaling.min', 3);

    expect(file_get_contents(Paths::manifest()))->toContain("        autoscaling:\n          min: 3\n          max: 6");
});

it('round-trips the inserted value through the manifest reader', function () {
    writeFormattedManifest(<<<'YAML'
name: my-app

environments:
  production:
    tasks:
      web:
        autoscaling:
          max: 6
YAML);

    Manifest::put('tasks.web.autoscaling.min', 3);

    expect(Manifest::get('tasks.web.autoscaling.min'))->toBe(3);
    expect(Manifest::get('tasks.web.autoscaling.max'))->toBe(6);
});

it('quotes a string value only when it needs it', function () {
    writeFormattedManifest(<<<'YAML'
name: my-app

environments:
  production:
    domain: old.example.com
YAML);

    Manifest::put('domain', 'new.example.com');

    expect(file_get_contents(Paths::manifest()))->toContain('domain: new.example.com');
    expect(Manifest::get('domain'))->toBe('new.example.com');
});
