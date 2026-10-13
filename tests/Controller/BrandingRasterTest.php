<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Domain\Enum\Theme\LogoMotif;
use App\Service\Appearance\LogoIcons;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The logo as a PNG: the home-screen and install icons.
 *
 * iOS takes only a PNG for its home-screen icon and the manifest's launcher
 * icons are PNGs, while the logo is an SVG with three hundred and thirty faces,
 * so the route draws the same tile the SVG route does and rasterises it. Two
 * forms: `tile` is the rounded square a manifest's `any` icon wears, `bleed` is
 * the ground out to every edge, for the places that cut the corners themselves
 * (iOS, an Android maskable icon).
 */
final class BrandingRasterTest extends WebTestCase
{
    public function testEverySizeAndFormIsAPngOfThatSize(): void
    {
        $client = static::createClient();

        foreach (['tile', 'bleed'] as $form) {
            foreach ([180, 192, 512] as $size) {
                $client->request('GET', sprintf('/branding/raster/%s/%d/wax-seal/original.png', $form, $size));

                self::assertResponseIsSuccessful();
                self::assertSame('image/png', $client->getResponse()->headers->get('Content-Type'));

                $info = getimagesizefromstring((string) $client->getResponse()->getContent());

                self::assertIsArray($info, "$form $size is an image");
                self::assertSame([$size, $size, IMAGETYPE_PNG], [$info[0], $info[1], $info[2]], "$form $size");
            }
        }
    }

    /**
     * What separates the two forms. The tile is clipped to a rounded square of
     * 72 units with a 16 radius, so its corner pixel is outside the drawing and
     * transparent; the bleed fills the square, so the same pixel is the ground.
     * The ground is read off the enum rather than spelled out, so this goes on
     * meaning "the choice's own ground" when its colour moves (it is #f3eadb
     * today: 243, 234, 219).
     */
    public function testATileHasTransparentCornersAndABleedIsTheGroundToTheEdge(): void
    {
        $client = static::createClient();

        $ground = LogoMotif::WaxSeal->paints(null)['background'];
        self::assertIsString($ground);

        $client->request('GET', '/branding/raster/tile/512/wax-seal/original.png');
        $tile = imagecreatefromstring((string) $client->getResponse()->getContent());

        $client->request('GET', '/branding/raster/bleed/512/wax-seal/original.png');
        $bleed = imagecreatefromstring((string) $client->getResponse()->getContent());

        self::assertNotFalse($tile);
        self::assertNotFalse($bleed);

        // gd's alpha runs 0 (opaque) to 127 (clear).
        self::assertSame(127, (imagecolorat($tile, 0, 0) >> 24) & 0x7F, 'the tile cuts its corners');
        self::assertSame(0, (imagecolorat($tile, 256, 256) >> 24) & 0x7F, 'and is solid inside them');

        self::assertSame(0, (imagecolorat($bleed, 0, 0) >> 24) & 0x7F, 'the bleed fills its corners');
        self::assertSame(hexdec(ltrim($ground, '#')), imagecolorat($bleed, 0, 0) & 0xFFFFFF, 'with the choice\'s ground');
    }

    /**
     * Why this is librsvg and not Imagick, whose SVG reader here paints a
     * gradient stroke black (the horn in ocean came out flat black on beige).
     *
     * The ocean ramp's stops are #1d4ed8, #0369a1, #0284c7, #0891b2, #0d9488,
     * #0f9f8f, #14b8a6, every one of them with blue at least 100 above red and
     * at least 136 itself; the ground, #f4ecdd, has red above blue. So a
     * rendering with the gradient has thousands of blue pixels and one without
     * it has none.
     */
    public function testAGradientColourwayKeepsItsGradient(): void
    {
        $client = static::createClient();

        $client->request('GET', '/branding/raster/tile/512/blue-horn/ocean.png');
        $image = imagecreatefromstring((string) $client->getResponse()->getContent());
        self::assertNotFalse($image);

        $blue = 0;
        $dark = 0;

        for ($y = 0; $y < 512; $y += 2) {
            for ($x = 0; $x < 512; $x += 2) {
                $c = imagecolorat($image, $x, $y);
                $r = ($c >> 16) & 0xFF;
                $g = ($c >> 8) & 0xFF;
                $b = $c & 0xFF;

                // Only the opaque pixels: a clear pixel is stored as black.
                if (0 !== (($c >> 24) & 0x7F)) {
                    continue;
                }

                if ($b >= 136 && $b - $r >= 100) {
                    ++$blue;
                }

                if ($r < 25 && $g < 25 && $b < 25) {
                    ++$dark;
                }
            }
        }

        self::assertGreaterThan(2_000, $blue, 'the horn wears its ocean gradient');
        self::assertSame(0, $dark, 'and no part of it fell back to black');
    }

    /** The choice is in the path: two choices, two pictures. */
    public function testTheChoiceIsInThePath(): void
    {
        $client = static::createClient();

        $client->request('GET', '/branding/raster/tile/192/blue-horn/ocean.png');
        $ocean = (string) $client->getResponse()->getContent();

        $client->request('GET', '/branding/raster/tile/192/blue-horn/original.png');
        $original = (string) $client->getResponse()->getContent();

        $client->request('GET', '/branding/raster/tile/192/blue-horn/ocean.png');

        self::assertNotSame($ocean, $original);
        self::assertSame($ocean, (string) $client->getResponse()->getContent(), 'and the same path is the same picture');
    }

    public function testAPngIsPublicAndImmutableAtTheCurrentVersion(): void
    {
        $client = static::createClient();

        $version = static::getContainer()->get(LogoIcons::class)->version();

        $client->request('GET', '/branding/raster/tile/192/wax-seal/original.png', ['v' => $version]);

        self::assertResponseIsSuccessful();

        $cache = (string) $client->getResponse()->headers->get('Cache-Control');

        self::assertStringContainsString('public', $cache);
        self::assertStringContainsString('immutable', $cache);
        self::assertStringNotContainsString('private', $cache);

        // Not the version: a short lifetime, because that URL will not change
        // when the drawing does.
        $client->request('GET', '/branding/raster/tile/192/wax-seal/original.png');

        self::assertStringNotContainsString('immutable', (string) $client->getResponse()->headers->get('Cache-Control'));

        $etag = $client->getResponse()->headers->get('ETag');
        self::assertNotNull($etag);

        $client->request('GET', '/branding/raster/tile/192/wax-seal/original.png', server: ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertResponseStatusCodeSame(304);
    }

    /**
     * The picture is kept on this side too (LogoRaster), and a deploy that
     * changes the drawing must not be answered from the drawing before it: the
     * version is in the cache key, so the old entries are simply never asked for.
     * Hand-spelled key: `<form>.<choice>.<version>` and the size, hashed.
     */
    public function testTheKeptPictureIsKeyedByTheDrawingsVersion(): void
    {
        $client = static::createClient();

        $client->request('GET', '/branding/raster/tile/192/wax-seal/original.png');
        self::assertResponseIsSuccessful();

        $version = static::getContainer()->get(LogoIcons::class)->version();
        $cache   = static::getContainer()->get('cache.app');

        self::assertTrue(
            $cache->getItem('logo_raster.' . hash('xxh128', 'tile.wax-seal.original.' . $version . '@192'))->isHit(),
            'kept under its version',
        );
        self::assertFalse(
            $cache->getItem('logo_raster.' . hash('xxh128', 'tile.wax-seal.original.0123456789ab@192'))->isHit(),
            'and not under another',
        );
    }

    public function testAnUnknownSizeFormOrChoiceIsNotFound(): void
    {
        $client = static::createClient();

        foreach ([
            '/branding/raster/tile/100/wax-seal/original.png',
            '/branding/raster/round/192/wax-seal/original.png',
            '/branding/raster/tile/192/chartreuse/original.png',
            '/branding/raster/tile/192/wax-seal/chartreuse-dreams.png',
        ] as $url) {
            $client->request('GET', $url);

            self::assertResponseStatusCodeSame(404, $url);
        }
    }
}
