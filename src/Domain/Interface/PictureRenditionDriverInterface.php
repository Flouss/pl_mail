<?php

declare(strict_types=1);

namespace App\Domain\Interface;

use App\Domain\DTO\Integration\RemoteFile;
use App\Domain\Exception\IntegrationException;
use App\Entity\Integration\Integration;

/**
 * A driver whose service keeps a web-safe picture of every photograph it holds.
 *
 * Immich is the one: its originals are whatever the camera wrote — HEIC from an
 * iPhone, RAW from a better camera, none of which a browser can show or the
 * avatar store can keep — and it renders a JPEG or WebP of each for its own web
 * UI. For a profile picture that rendition is the file to fetch, and every
 * photograph is eligible, where the original would be refused as "not an image".
 *
 * Separate from IntegrationDriverInterface for the same reason
 * OriginalFileDriverInterface is: every other driver has one file per id, and a
 * method that repeats download() would be dead weight on all of them.
 */
interface PictureRenditionDriverInterface
{
    /**
     * The photograph as a picture, in a format any browser shows — never the
     * original, and sized for looking at rather than for printing.
     *
     * @throws IntegrationException
     */
    public function downloadPicture(Integration $integration, string $fileId): RemoteFile;
}
