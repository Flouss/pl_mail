<?php

declare(strict_types=1);

namespace App\Tests\Service\Appearance;

use App\Service\Appearance\LogoRaster;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * The renderer behind the PNG icons: rsvg-convert, in the image since
 * librsvg2-bin was added to the Dockerfile.
 */
final class LogoRasterTest extends TestCase
{
    private const string RED_SQUARE = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10" width="10" height="10">'
        . '<rect width="10" height="10" fill="#ff0000"/></svg>';

    public function testItDrawsTheSvgAtTheSizeAsked(): void
    {
        $png = new LogoRaster(new ArrayAdapter())->png('k', static fn (): string => self::RED_SQUARE, 64);

        $info = getimagesizefromstring($png);

        self::assertIsArray($info);
        self::assertSame([64, 64, IMAGETYPE_PNG], [$info[0], $info[1], $info[2]]);

        // The drawing, not just a PNG of the right shape: a red square fills
        // every pixel with the red its fill names (#ff0000 = 255, 0, 0).
        $image = imagecreatefromstring($png);
        self::assertNotFalse($image);
        self::assertSame(0xFF0000, imagecolorat($image, 32, 32) & 0xFFFFFF);
    }

    /**
     * Rendering spawns a process and the route that asks is public, so a
     * picture that was already drawn must not be drawn again.
     */
    public function testAPictureAlreadyDrawnIsNotDrawnAgain(): void
    {
        $raster = new LogoRaster(new ArrayAdapter());
        $calls  = 0;
        $svg    = static function () use (&$calls): string {
            ++$calls;

            return self::RED_SQUARE;
        };

        $first = $raster->png('same', $svg, 32);

        // The presence companion: a different key IS drawn, so "never calls
        // the closure again" would not pass.
        $raster->png('other', $svg, 32);

        self::assertSame($first, $raster->png('same', $svg, 32));
        self::assertSame(2, $calls, 'one drawing per key');
    }

    public function testTheSizeIsPartOfWhatIsCached(): void
    {
        $raster = new LogoRaster(new ArrayAdapter());
        $svg    = static fn (): string => self::RED_SQUARE;

        $small = getimagesizefromstring($raster->png('same', $svg, 32));
        $large = getimagesizefromstring($raster->png('same', $svg, 64));

        self::assertIsArray($small);
        self::assertIsArray($large);
        self::assertSame(32, $small[0]);
        self::assertSame(64, $large[0]);
    }

    /**
     * A missing renderer must be a loud failure, not a blank or a default
     * picture: the icon would be wrong for everyone and nothing would say why.
     */
    public function testAMissingRendererFailsLoudly(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('librsvg2-bin');

        new LogoRaster(new ArrayAdapter(), 'plmail-no-such-renderer')->png('k', static fn (): string => self::RED_SQUARE, 64);
    }

    public function testAnUnreadableSvgFailsLoudly(): void
    {
        $this->expectException(\RuntimeException::class);

        new LogoRaster(new ArrayAdapter())->png('k', static fn (): string => 'not an svg', 64);
    }
}
