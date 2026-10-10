<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Domain\DTO\Integration\Entry;
use App\Domain\DTO\Integration\Listing;
use App\Domain\Enum\Integration\Capability;
use App\Domain\Exception\AvatarRefusedException;
use App\Domain\Exception\IntegrationException;
use App\Domain\Helper\AvatarStorage;
use App\Domain\Interface\OriginalFileDriverInterface;
use App\Domain\Interface\PictureRenditionDriverInterface;
use App\Entity\Integration\Integration;
use App\Entity\User\User;
use App\Repository\Integration\IntegrationRepository;
use App\Service\Integration\IntegrationDriverRegistry;
use Doctrine\ORM\EntityManagerInterface;
use finfo;

/**
 * Taking a profile picture from a service the user has already connected.
 *
 * The photos are usually already there — in Immich, in Google Photos — and
 * asking someone to download one and upload it again is asking them to do the
 * computer's job.
 *
 * Settings → Profile chooses through the compose file picker, in its avatar
 * mode (FilePickerController::browse), and this class only decides what may be
 * chosen and keeps the result. That replaced a smaller grid of its own, which
 * listed the first page of a service's root and had no albums, no search and no
 * paging — it showed whatever happened to be newest and nothing else. browse()
 * below is that grid's data and is now used only by the setup wizard, whose
 * profile step is itself inside the modal frame the picker opens in.
 */
final readonly class AvatarFromIntegration
{
    /** Enough to choose from without turning the step into a gallery. */
    private const int LIMIT = 24;

    public function __construct(
        private IntegrationRepository $integrations,
        private IntegrationDriverRegistry $drivers,
        private AvatarStorage $avatars,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Connections this user could pick a picture from, right now.
     *
     * Checked on every render rather than cached: connecting a service is a
     * step or two earlier in the wizard, so the answer changes while the user
     * is sitting in it.
     *
     * @return list<Integration>
     */
    public function availableFor(User $user): array
    {
        return array_values(array_filter(
            $this->integrations->findBy(['usr' => $user, 'isActive' => true]),
            // Thumbnail as well as Browse and Download: without previews the
            // grid is a list of filenames, which is not a way to choose a
            // photograph.
            static fn (Integration $integration): bool => $integration->supports(Capability::Browse)
                && $integration->supports(Capability::Download)
                && $integration->supports(Capability::Thumbnail),
        ));
    }

    /**
     * Images to choose from.
     *
     * Failure comes back as an empty list rather than an exception: a service
     * that is down should leave the rest of the step usable.
     *
     * @return list<Entry>
     */
    public function browse(Integration $integration): array
    {
        try {
            $driver = $this->drivers->forIntegration($integration);
            $listing = $driver->list($integration);
        } catch (IntegrationException) {
            return [];
        }

        $rendersPictures = $driver instanceof PictureRenditionDriverInterface;
        $images = array_values(array_filter(
            $listing->files(),
            static fn (Entry $entry): bool => self::looksLikeAnImage($entry, $rendersPictures),
        ));

        return array_slice($images, 0, self::LIMIT);
    }

    /**
     * Files on a picker page that cannot become a picture, so the picker can
     * show them without letting them be chosen.
     *
     * The compose picker lists whatever the service holds — a Paperless library
     * is mostly PDFs — and hiding those would leave an empty-looking page with a
     * working "show more" under it. Greyed out, it is plain why nothing there
     * can be picked.
     *
     * @return list<string> entry ids
     */
    public function refusedIds(Integration $integration, ?Listing $listing): array
    {
        if (null === $listing) {
            return [];
        }

        $rendersPictures = $this->rendersPictures($integration);

        return array_values(array_map(
            static fn (Entry $entry): string => $entry->id,
            array_filter($listing->files(), static fn (Entry $entry): bool => false === self::looksLikeAnImage($entry, $rendersPictures)),
        ));
    }

    /**
     * The size above which the picker greys a file out as too large.
     *
     * The avatar store's own cap, except where the service renders the picture:
     * what is kept is then the rendition, a few hundred kilobytes whatever the
     * original weighs, so a 9 MB HEIC from a good camera is as choosable as a
     * small one. The rendition is held to the cap all the same, in apply().
     */
    public function maxBytesFor(Integration $integration): int
    {
        return true === $this->rendersPictures($integration) ? PHP_INT_MAX : AvatarStorage::MAX_BYTES;
    }

    private function rendersPictures(Integration $integration): bool
    {
        return $this->drivers->forIntegration($integration) instanceof PictureRenditionDriverInterface;
    }

    /**
     * Only what the avatar store will take — PNG, JPEG, GIF, WebP — so nothing
     * is offered that is then refused. A HEIC photograph is an image, but not
     * one this can keep, and offering it was offering an error — unless the
     * service renders its photographs as pictures (PictureRenditionDriverInterface),
     * in which case any image it holds can be chosen, because apply() fetches
     * that rendition and not the original this entry describes.
     *
     * Not every driver reports a mime type — a photo library that only ever
     * returns photographs has little reason to — so the filename is the
     * fallback rather than the entry being dropped. Either is only a guess
     * about what the file will be; apply() decides on the bytes.
     */
    private static function looksLikeAnImage(Entry $entry, bool $rendersPictures): bool
    {
        if (null !== $entry->mime && '' !== $entry->mime) {
            $mime = strtolower($entry->mime);

            return in_array($mime, AvatarStorage::ALLOWED_MIME, true)
                || (true === $rendersPictures && true === str_starts_with($mime, 'image/'));
        }

        return in_array(
            strtolower(pathinfo($entry->name, PATHINFO_EXTENSION)),
            ['png', 'jpg', 'jpeg', 'gif', 'webp'],
            true,
        );
    }

    /**
     * Fetch the chosen file and make it the user's avatar.
     *
     * **What it is comes from its bytes, not from the service.** A download is
     * often labelled application/octet-stream whatever it holds — the listing
     * that offered it already had to go by its name — and trusting the label
     * refused real photographs with "not an image", while trusting it the other
     * way would store whatever a service chose to call a picture. The upload
     * path asks the same question of an UploadedFile, which also sniffs.
     *
     * The stored name takes its extension from what was found, for the same
     * reason: it is what the avatar is served as.
     *
     * @throws AvatarRefusedException when the file cannot be fetched or kept
     */
    public function apply(User $user, Integration $integration, string $fileId): void
    {
        try {
            $driver = $this->drivers->forIntegration($integration);

            // The service's own picture where it makes one: the listing offered
            // the original, which may be a HEIC nothing here can keep. Else the
            // original where the service keeps one apart from what it serves:
            // the picker listed the file by the original's type, and the
            // rendition — Paperless's archived PDF — is never a picture.
            $file = match (true) {
                $driver instanceof PictureRenditionDriverInterface => $driver->downloadPicture($integration, $fileId),
                $driver instanceof OriginalFileDriverInterface => $driver->downloadOriginal($integration, $fileId),
                default => $driver->download($integration, $fileId),
            };
        } catch (IntegrationException $e) {
            throw new AvatarRefusedException(AvatarRefusedException::UNAVAILABLE, $e);
        }

        if ($file->size() > AvatarStorage::MAX_BYTES) {
            throw new AvatarRefusedException(AvatarRefusedException::TOO_LARGE);
        }

        $mime = (string) new finfo(FILEINFO_MIME_TYPE)->buffer($file->contents);

        if (false === in_array($mime, AvatarStorage::ALLOWED_MIME, true)) {
            throw new AvatarRefusedException(AvatarRefusedException::NOT_AN_IMAGE);
        }

        $extension = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'][$mime];

        $userId = (string) $user->id;
        $user->avatar = $this->avatars->storeContents($userId, 'avatar.'.$extension, $file->contents);
        $this->entityManager->flush();
    }
}
