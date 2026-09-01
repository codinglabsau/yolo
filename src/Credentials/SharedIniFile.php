<?php

namespace Codinglabs\Yolo\Credentials;

/**
 * Line-preserving reader/writer for the AWS shared config files. Only the
 * target section is ever touched — every other byte of the file (comments,
 * spacing, unrelated profiles) survives a write verbatim, because these files
 * are hand-maintained and shared with tools YOLO doesn't own.
 *
 * `~/.aws/config` names sections `[profile foo]` (except `[default]`);
 * `~/.aws/credentials` names them `[foo]` — the $prefixedSections flag picks
 * the dialect.
 */
class SharedIniFile
{
    public function __construct(
        protected string $path,
        protected bool $prefixedSections,
    ) {}

    public function exists(): bool
    {
        return file_exists($this->path);
    }

    public function hasSection(string $name): bool
    {
        return $this->sectionBounds($name) !== null;
    }

    /**
     * The section's body lines (between its header and the next header),
     * trimmed, blank lines dropped. Empty when the section doesn't exist.
     *
     * @return array<int, string>
     */
    public function sectionLines(string $name): array
    {
        $bounds = $this->sectionBounds($name);

        if ($bounds === null) {
            return [];
        }

        [$start, $end] = $bounds;

        return array_values(array_filter(array_map(
            trim(...),
            array_slice($this->lines(), $start + 1, $end - $start - 1),
        ), fn (string $line): bool => $line !== ''));
    }

    /**
     * The section's keys (lowercased) that start with the given prefix — how
     * the config writer detects SSO remnants (`sso_start_url`, `sso_session`,
     * …) that would steer credential resolution away from credential_process.
     *
     * @return array<int, string>
     */
    public function sectionKeysMatching(string $name, string $prefix): array
    {
        return array_values(array_filter(array_map(
            fn (string $line): string => strtolower(trim(explode('=', $line, 2)[0])),
            $this->sectionLines($name),
        ), fn (string $key): bool => str_starts_with($key, $prefix)));
    }

    /**
     * Write the section: replace it in place when it exists, append it
     * otherwise. Body lines are written as given, one per line.
     *
     * @param  array<int, string>  $lines
     */
    public function putSection(string $name, array $lines): void
    {
        $section = array_merge([$this->header($name)], $lines);
        $existing = $this->lines();
        $bounds = $this->sectionBounds($name);

        if ($bounds === null) {
            $prelude = $existing === [] ? [] : [...$existing, ''];
            $this->write([...$prelude, ...$section]);

            return;
        }

        [$start, $end] = $bounds;
        $tail = array_slice($existing, $end);

        // An in-place replace consumes the blank line the old section carried
        // before the next header — restore it so the new block never abuts the
        // following section.
        if ($tail !== [] && trim($tail[0]) !== '') {
            $section[] = '';
        }

        $this->write([
            ...array_slice($existing, 0, $start),
            ...$section,
            ...$tail,
        ]);
    }

    public function removeSection(string $name): void
    {
        $bounds = $this->sectionBounds($name);

        if ($bounds === null) {
            return;
        }

        [$start, $end] = $bounds;
        $existing = $this->lines();

        $result = [
            ...array_slice($existing, 0, $start),
            ...array_slice($existing, $end),
        ];

        // The removed section usually carried one separator blank line; when
        // it sat between two sections both separators survive — collapse the
        // doubled blank at the seam, and trim any blanks left dangling at EOF.
        if ($start > 0 && isset($result[$start]) && trim($result[$start]) === '' && trim($result[$start - 1]) === '') {
            array_splice($result, $start, 1);
        }

        while ($result !== [] && trim(end($result)) === '') {
            array_pop($result);
        }

        $this->write($result);
    }

    protected function header(string $name): string
    {
        return $this->prefixedSections && $name !== 'default'
            ? sprintf('[profile %s]', $name)
            : sprintf('[%s]', $name);
    }

    /**
     * The section's [startLine, endLine) bounds — endLine is the next section
     * header or EOF. Null when the section doesn't exist.
     *
     * @return array{0: int, 1: int}|null
     */
    protected function sectionBounds(string $name): ?array
    {
        $lines = $this->lines();
        $header = strtolower($this->header($name));
        $start = null;

        foreach ($lines as $index => $line) {
            $trimmed = strtolower(trim($line));

            if ($start === null) {
                if ($trimmed === $header) {
                    $start = $index;
                }

                continue;
            }

            if (str_starts_with($trimmed, '[')) {
                return [$start, $index];
            }
        }

        return $start === null ? null : [$start, count($lines)];
    }

    /**
     * @return array<int, string>
     */
    protected function lines(): array
    {
        if (! $this->exists()) {
            return [];
        }

        return explode("\n", rtrim(file_get_contents($this->path), "\n"));
    }

    /**
     * @param  array<int, string>  $lines
     */
    protected function write(array $lines): void
    {
        if (! is_dir(dirname($this->path))) {
            mkdir(dirname($this->path), 0700, true);
        }

        file_put_contents($this->path, implode("\n", $lines) . "\n");
        chmod($this->path, 0600);
    }
}
