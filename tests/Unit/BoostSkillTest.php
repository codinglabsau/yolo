<?php

use Symfony\Component\Yaml\Yaml;

/*
 * The Boost distribution artefacts ship inside the composer package (under
 * `resources/`, which `.gitattributes` does NOT export-ignore), so any app that
 * requires YOLO gets the `/yolo` skill and the auto-composed guideline. These
 * tests pin that they exist and that the skill carries a valid Boost/Claude
 * frontmatter contract (name + description) — the package is the single source
 * of truth, not a hand-copied dotfiles skill.
 */

function packageRoot(): string
{
    return dirname(__DIR__, 2);
}

it('ships the /yolo skill in the package', function (): void {
    expect(packageRoot() . '/resources/boost/skills/yolo/SKILL.md')->toBeFile();
});

it('gives the skill a valid frontmatter contract', function (): void {
    $contents = (string) file_get_contents(packageRoot() . '/resources/boost/skills/yolo/SKILL.md');

    expect($contents)->toStartWith('---');

    [, $frontmatter] = explode('---', $contents, 3);
    $meta = Yaml::parse($frontmatter);

    expect($meta['name'])->toBe('yolo')
        ->and($meta['description'])->toBeString()->not->toBeEmpty();
});

it('ships a Boost guideline Boost will auto-discover', function (): void {
    // Boost composes `resources/boost/guidelines/*.blade.php` from every installed
    // package, so the file living here is what wires YOLO into a consuming app.
    $guideline = packageRoot() . '/resources/boost/guidelines/yolo.blade.php';

    expect($guideline)->toBeFile()
        ->and(trim((string) file_get_contents($guideline)))->not->toBeEmpty();
});
