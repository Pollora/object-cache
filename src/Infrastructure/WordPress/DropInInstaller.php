<?php

declare(strict_types=1);

namespace Pollora\ObjectCache\Infrastructure\WordPress;

use Pollora\ObjectCache\Infrastructure\Exceptions\ForeignDropInException;
use RuntimeException;

/**
 * Puts the package's `object-cache.php` in WordPress's content directory, and
 * takes it out.
 *
 * The drop-in is copied, not linked, so it keeps working if `vendor` is moved,
 * and the copy says which package wrote it: a file without that marker is
 * somebody else's object cache and is never overwritten or deleted unless the
 * caller forces the install.
 */
final readonly class DropInInstaller
{
    /** The header line that marks a drop-in as this package's. */
    public const string MARKER = 'Pollora-Object-Cache-Drop-In:';

    /**
     * @param  string  $source  The drop-in the package ships.
     * @param  string  $target  Where WordPress looks for it: `object-cache.php` in the content directory.
     */
    public function __construct(private string $source, private string $target) {}

    /**
     * The place WordPress looks for the drop-in.
     */
    public function target(): string
    {
        return $this->target;
    }

    /**
     * Classify what is at the target.
     */
    public function status(): DropInStatus
    {
        if (! is_file($this->target)) {
            return DropInStatus::Missing;
        }

        $installed = (string) file_get_contents($this->target);

        if (! str_contains($installed, self::MARKER)) {
            return DropInStatus::Foreign;
        }

        return $installed === (string) file_get_contents($this->source) ? DropInStatus::Current : DropInStatus::Outdated;
    }

    /**
     * Copy the drop-in to the target; a drop-in that is already current is left alone.
     *
     * @param  bool  $force  Replace a drop-in that is not this package's.
     * @return DropInStatus What stood at the target before the call.
     *
     * @throws ForeignDropInException When another object cache is installed and `$force` is false.
     * @throws RuntimeException When the file cannot be written.
     */
    public function install(bool $force = false): DropInStatus
    {
        $before = $this->status();

        if ($before === DropInStatus::Foreign && ! $force) {
            throw new ForeignDropInException(sprintf('%s belongs to another object cache. Use --force to replace it.', $this->target));
        }

        if ($before !== DropInStatus::Current && ! $this->copyDropIn()) {
            throw new RuntimeException(sprintf('Cannot write %s.', $this->target));
        }

        return $before;
    }

    /**
     * Remove the drop-in, if it is this package's.
     *
     * @return DropInStatus What stood at the target before the call.
     *
     * @throws ForeignDropInException When the file is another object cache's.
     * @throws RuntimeException When the file cannot be removed.
     */
    public function uninstall(): DropInStatus
    {
        $before = $this->status();

        if ($before === DropInStatus::Foreign) {
            throw new ForeignDropInException(sprintf('%s belongs to another object cache: not removed.', $this->target));
        }

        if ($before !== DropInStatus::Missing && ! $this->removeDropIn()) {
            throw new RuntimeException(sprintf('Cannot remove %s.', $this->target));
        }

        return $before;
    }

    private function copyDropIn(): bool
    {
        $directory = dirname($this->target);

        return is_dir($directory) && is_writable($directory) && copy($this->source, $this->target);
    }

    private function removeDropIn(): bool
    {
        return is_writable(dirname($this->target)) && unlink($this->target);
    }
}
