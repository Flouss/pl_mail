<?php

declare(strict_types=1);

namespace App\Service\Appearance;

use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Process;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * The logo as a PNG: an SVG drawn by rsvg-convert (librsvg2-bin in the image).
 *
 * iOS takes nothing but a PNG for its home-screen icon and a launcher wants
 * raster icons from a manifest, while the logo is an SVG (BrandingController).
 *
 * WHY librsvg
 * ───────────
 * Imagick was tried first and is wrong for this: its SVG reader in the image is
 * ImageMagick's own, which paints a stroke whose paint is a gradient black, so
 * every colourway that is a gradient came out as a flat black glyph. librsvg
 * draws what a browser draws. BrandingRasterTest pins the gradient.
 *
 * WHY IT IS CACHED HERE
 * ─────────────────────
 * Drawing spawns a process, and the route that asks is public, so every request
 * for a picture that already exists must not spawn another. Callers pass the
 * key of the drawing (its choice, its form and the drawing's version, see
 * LogoIcons::version()), so a changed drawing is a new key and the old entries
 * age out of the pool unread.
 *
 * WHAT IT DOES NOT DO
 * ───────────────────
 * Nothing here limits how many distinct pictures can be asked for. There are a
 * few thousand (choices x forms x sizes), the route's requirements bound them,
 * and each costs one process once.
 */
final class LogoRaster
{
    private const int TIMEOUT_SECONDS = 15;

    private const string PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    public function __construct(
        private readonly CacheInterface $cache,
        /** Overridable so a missing renderer can be tested; the image has it as `rsvg-convert`. */
        private readonly string         $binary = 'rsvg-convert',
    ) {}

    /**
     * The SVG square drawn at $size by $size pixels.
     *
     * $svg is only called when the picture is not cached yet.
     *
     * @param \Closure(): string $svg
     *
     * @throws \RuntimeException when the renderer is missing or cannot read the SVG: loudly,
     *                           because the alternative is a wrong icon for everyone with nothing saying why
     */
    public function png(string $key, \Closure $svg, int $size): string
    {
        // Hashed: a cache key may not contain the characters a choice and a
        // version could in principle bring.
        return $this->cache->get(
            'logo_raster.' . hash('xxh128', $key . '@' . $size),
            fn (): string => $this->draw($svg(), $size),
        );
    }

    private function draw(string $svg, int $size): string
    {
        // An argument list, never a shell string: the SVG goes in on stdin.
        $process = new Process([$this->binary, '--width', (string) $size, '--height', (string) $size, '--format', 'png']);
        $process->setInput($svg);
        $process->setTimeout(self::TIMEOUT_SECONDS);

        try {
            $process->mustRun();
        } catch (ProcessException $e) {
            throw new \RuntimeException(
                sprintf('%s could not draw the logo (the image needs librsvg2-bin): %s', $this->binary, $e->getMessage()),
                previous: $e,
            );
        }

        $png = $process->getOutput();

        if (false === str_starts_with($png, self::PNG_SIGNATURE)) {
            throw new \RuntimeException(sprintf('%s answered with something that is not a PNG (the image needs librsvg2-bin)', $this->binary));
        }

        return $png;
    }
}
