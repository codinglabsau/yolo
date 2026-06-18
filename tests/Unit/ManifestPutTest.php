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

it('updates an existing scalar and preserves comments, blank lines, ordering and spacing', function () use ($richManifest): void {
    writeFormattedManifest($richManifest);

    Manifest::put('tasks.web.autoscaling.min', 3);

    // Only the `1` becomes `3`; every other byte — the inline comment, the blank
    // line, the standalone comment, key order — is untouched.
    $expected = str_replace('min: 1   # baseline floor', 'min: 3   # baseline floor', $richManifest);

    expect(file_get_contents(Paths::manifest()))->toBe($expected);
});

it('changes exactly one line', function () use ($richManifest): void {
    writeFormattedManifest($richManifest);

    Manifest::put('tasks.web.autoscaling.max', 10);

    $before = explode("\n", $richManifest);
    $after = explode("\n", file_get_contents(Paths::manifest()));

    $differing = collect($after)->diffAssoc(collect($before));

    expect($differing->all())->toBe([12 => '          max: 10']);
});

it('inserts a missing key as the first child of its existing parent block', function (): void {
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

it('creates a missing intermediate parent block and keeps the surrounding formatting', function (): void {
    writeFormattedManifest(<<<'YAML'
name: my-app  # the application

environments:
  production:
    # capacity tuning lives here
    tasks:
      web:
        cpu: 512
        memory: 1024
YAML);

    // autoscaling is on-by-default, so the block is absent — the first scale must
    // create `autoscaling:` AND its `min` child without re-dumping the whole file.
    Manifest::put('tasks.web.autoscaling.min', 3);

    $contents = file_get_contents(Paths::manifest());

    // The intermediate block + leaf are spliced in under web at the right indent...
    expect($contents)->toContain("      web:\n        autoscaling:\n          min: 3\n        cpu: 512");
    // ...and the comments + blank line a full re-dump would have stripped survive.
    expect($contents)->toContain('name: my-app  # the application');
    expect($contents)->toContain('    # capacity tuning lives here');
    expect(Manifest::get('tasks.web.autoscaling.min'))->toBe(3);
});

it('falls back to a full dump when the deepest ancestor carries an inline value', function (): void {
    // `web: true` can't take block children — splicing under `tasks` would duplicate
    // the key, so the surgical pass declines and put() re-dumps (losing comments) to
    // render web as a proper block. The value must still round-trip correctly.
    writeFormattedManifest(<<<'YAML'
name: my-app

environments:
  production:
    tasks:
      web: true
YAML);

    Manifest::put('tasks.web.autoscaling.min', 3);

    expect(Manifest::get('tasks.web.autoscaling.min'))->toBe(3);
    expect(substr_count(file_get_contents(Paths::manifest()), 'web:'))->toBe(1);
});

it('round-trips the inserted value through the manifest reader', function (): void {
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

it('quotes a string value only when it needs it', function (): void {
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
