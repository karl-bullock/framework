<?php

/*
 * This file is part of Flarum.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Flarum\ExtensionManager\Command;

use Flarum\ExtensionManager\Composer\ComposerAdapter;
use Flarum\ExtensionManager\Composer\ComposerJson;
use Flarum\ExtensionManager\Exception\NoNewMajorVersionException;
use Flarum\ExtensionManager\Settings\LastUpdateCheck;
use Flarum\Foundation\Paths;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\StringInput;

/**
 * Answers "would a major update work?" without changing anything.
 *
 * A major update is the one operation here that cannot be undone: it rewrites
 * every version constraint in composer.json and then replaces vendor/. If it
 * fails partway the site is down, and the people most likely to be using this
 * tool are the ones with no shell to repair it with. Finding out first is
 * cheap; finding out afterwards may not be recoverable.
 *
 * The dry run itself already exists on MajorUpdate. What is missing is running
 * it on its own, ahead of the real thing, and turning "composer exited 2" into
 * something an administrator can act on: which of their extensions are holding
 * the upgrade back, and whether this server can complete it at all.
 */
class PreflightHandler
{
    /**
     * Resolution is the memory-hungry part, and 512M is where it starts to be
     * comfortable on a real forum. Below this an update can die mid-write for
     * no reason the administrator would understand.
     */
    public const RECOMMENDED_MEMORY_BYTES = 512 * 1024 * 1024;

    public function __construct(
        protected ComposerAdapter $composer,
        protected LastUpdateCheck $lastUpdateCheck,
        protected ComposerJson $composerJson,
        protected Paths $paths
    ) {
    }

    /**
     * @return array{version: string, resolves: bool, blocking: string[], output: string, environment: array<string, mixed>}
     *
     * @throws \Flarum\User\Exception\PermissionDeniedException
     * @throws NoNewMajorVersionException
     */
    public function handle(Preflight $command): array
    {
        $command->actor->assertAdmin();

        $majorVersion = $this->lastUpdateCheck->getNewMajorVersion();

        if (! $majorVersion) {
            throw new NoNewMajorVersionException();
        }

        $resolves = $this->wouldResolve($majorVersion, $output);

        return [
            'version' => $majorVersion,
            'resolves' => $resolves,
            // Only meaningful when it does not resolve; the packages standing
            // in the way are what an administrator has to deal with.
            'blocking' => $resolves ? [] : $this->blockingPackages($majorVersion),
            'output' => $output,
            'environment' => $this->environment(),
        ];
    }

    /**
     * Runs the same update the real thing would, with --dry-run, so nothing is
     * written. composer.json IS edited to ask the question, and is put back
     * whatever the answer, including when composer throws.
     */
    protected function wouldResolve(string $majorVersion, ?string &$output): bool
    {
        $this->composerJson->require('*', '*');
        $this->composerJson->require('flarum/core', '^'.str_replace('v', '', $majorVersion));

        try {
            $result = $this->composer->run(new ArrayInput([
                'command' => 'update',
                '--prefer-dist' => true,
                '--no-plugins' => true,
                '--no-dev' => true,
                '-a' => true,
                '--with-all-dependencies' => true,
                '--dry-run' => true,
            ]), null, true);

            $output = $result->getContents();

            return $result->getExitCode() === 0;
        } finally {
            $this->composerJson->revert();
        }
    }

    /**
     * Asks composer why the new core cannot be installed. The answer names the
     * packages that conflict, which is the list an administrator needs: usually
     * extensions with no release for the new major version.
     *
     * @return string[]
     */
    protected function blockingPackages(string $majorVersion): array
    {
        $result = $this->composer->run(
            new StringInput('why-not flarum/core '.str_replace('v', '', $majorVersion))
        );

        $packages = [];

        foreach (explode("\n", $result->getContents()) as $line) {
            // Lines look like: "acme/extension  1.0.0  requires  flarum/core (^1.0)".
            if (preg_match('/^\s*(\S+\/\S+)\s+\S+\s+requires\s+flarum\/core/i', $line, $matches)) {
                $packages[] = $matches[1];
            }
        }

        return array_values(array_unique($packages));
    }

    /**
     * Whether this server can finish what it starts. Neither of these stops an
     * update being attempted; they are the two reasons a healthy set of
     * packages still fails halfway.
     *
     * @return array<string, mixed>
     */
    protected function environment(): array
    {
        $memoryLimit = $this->memoryLimitBytes();
        $vendorSize = $this->directorySize($this->paths->vendor);
        $freeDisk = @disk_free_space($this->paths->base);

        return [
            'memoryLimit' => $memoryLimit,
            'memoryLimitSufficient' => $memoryLimit === -1 || $memoryLimit >= self::RECOMMENDED_MEMORY_BYTES,
            'recommendedMemory' => self::RECOMMENDED_MEMORY_BYTES,
            'vendorSize' => $vendorSize,
            'freeDisk' => $freeDisk === false ? null : (int) $freeDisk,
            // A replacement vendor tree has to fit beside the current one
            // before the old one goes.
            'diskSufficient' => $freeDisk === false || $vendorSize === null || $freeDisk > $vendorSize,
        ];
    }

    /** -1 means no limit, which is fine rather than suspicious. */
    protected function memoryLimitBytes(): int
    {
        $limit = trim((string) ini_get('memory_limit'));

        if ($limit === '' || $limit === '-1') {
            return -1;
        }

        $unit = strtolower(substr($limit, -1));
        $value = (int) $limit;

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    /** Null when the directory cannot be read, rather than a misleading zero. */
    protected function directorySize(string $path): ?int
    {
        if (! is_dir($path)) {
            return null;
        }

        $bytes = 0;

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if ($file->isFile()) {
                $bytes += $file->getSize();
            }
        }

        return $bytes;
    }
}
