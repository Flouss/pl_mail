<?php

declare(strict_types=1);

namespace App\Tests\Service\User;

use App\Domain\DTO\Integration\Entry;
use App\Domain\DTO\Integration\Listing;
use App\Domain\DTO\Integration\RemoteFile;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\AvatarRefusedException;
use App\Domain\Exception\IntegrationException;
use App\Domain\Helper\AvatarStorage;
use App\Domain\Interface\IntegrationDriverInterface;
use App\Domain\Interface\OriginalFileDriverInterface;
use App\Domain\Interface\PictureRenditionDriverInterface;
use App\Entity\Integration\Integration;
use App\Entity\User\User;
use App\Repository\Integration\IntegrationRepository;
use App\Service\Integration\IntegrationDriverRegistry;
use App\Service\User\AvatarFromIntegration;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * A picture picked from a connected service is judged by its bytes.
 *
 * Production answered a picked photo with a 500: the service labelled the
 * download application/octet-stream, the label was trusted, and the refusal
 * was a RuntimeException nothing caught. Both halves are pinned — a real image
 * under a wrong label is kept, and a non-image under an image label is refused
 * with a reason the form can show, as is a download that never arrives.
 */
final class AvatarFromIntegrationTest extends TestCase
{
    /** The smallest PNG there is: one transparent pixel. */
    private const string PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89"
        . "\0\0\0\rIDATx\x9cc\xf8\x0f\x00\x00\x01\x01\x00\x05\x18\xd8N\0\0\0\0IEND\xaeB`\x82";

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/avatar-test-' . bin2hex(random_bytes(4));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $entry) {
            $entry->isDir() ? rmdir((string) $entry) : unlink((string) $entry);
        }
        rmdir($this->root);
    }

    public function testARealImageUnderAWrongLabelIsKeptAsWhatItIs(): void
    {
        $user = new User();

        $this->picker(new RemoteFile('photo', 'application/octet-stream', self::PNG))
            ->apply($user, $this->integration(), 'f1');

        self::assertNotNull($user->avatar);
        self::assertStringEndsWith('.png', $user->avatar);
    }

    public function testSomethingElseUnderAnImageLabelIsRefusedWithAReason(): void
    {
        $user = new User();

        try {
            $this->picker(new RemoteFile('notes.jpg', 'image/jpeg', "just some text\n"))
                ->apply($user, $this->integration(), 'f1');
            self::fail('a text file became an avatar');
        } catch (AvatarRefusedException $refused) {
            self::assertSame(AvatarRefusedException::NOT_AN_IMAGE, $refused->reason);
        }

        self::assertNull($user->avatar);
    }

    /**
     * Paperless lists a scanned photograph as image/jpeg and serves the archived
     * PDF it made from it. A picture taken from it has to be the photograph, so
     * a driver that keeps the original apart is asked for that one — the
     * rendition would be refused as "not an image" every time.
     */
    public function testADriverThatKeepsAnOriginalIsAskedForIt(): void
    {
        $user = new User();
        $driver = new OriginalKeepingDriver(
            rendition: new RemoteFile('scan.pdf', 'application/pdf', "%PDF-1.7
"),
            original: new RemoteFile('scan.png', 'image/png', self::PNG),
        );

        $this->pickerFor($driver)->apply($user, $this->integration(), 'f1');

        self::assertNotNull($user->avatar);
        self::assertStringEndsWith('.png', $user->avatar);
        self::assertSame(['downloadOriginal'], $driver->calls);
    }

    /**
     * An iPhone photograph is HEIC, which the avatar store cannot keep and
     * browsers cannot show. Immich renders every photograph as a JPEG or WebP
     * of its own, so for a driver that can do that, that is the file to fetch:
     * the original would be refused as "not an image" every time.
     */
    public function testADriverThatRendersPicturesIsAskedForThePictureNotTheOriginal(): void
    {
        $user = new User();
        $driver = new PictureRenderingDriver(
            picture: new RemoteFile('IMG_1.jpg', 'image/jpeg', self::jpeg()),
            original: new RemoteFile('IMG_1.HEIC', 'image/heic', 'not a jpeg'),
        );

        $this->pickerFor($driver)->apply($user, $this->integration(), 'f1');

        self::assertNotNull($user->avatar);
        self::assertStringEndsWith('.jpg', $user->avatar);
        self::assertSame(['downloadPicture'], $driver->calls);
    }

    public function testAHeicPhotoIsOfferedWhereTheServiceCanRenderIt(): void
    {
        // Presence: the HEIC ('heic') and the JPEG ('jpeg') are both choosable.
        // Absence: the video and the PDF are not, whatever the driver can render.
        self::assertSame(
            ['video', 'pdf'],
            $this->pickerFor($this->renderingDriver())->refusedIds($this->integration(), $this->mixedListing()),
        );
    }

    public function testAHeicPhotoIsStillRefusedWhereNothingCanRenderIt(): void
    {
        // The same listing from a driver with no rendition: HEIC is refused as
        // before, and the JPEG is still not.
        self::assertSame(
            ['heic', 'video', 'pdf'],
            $this->picker(null)->refusedIds($this->integration(), $this->mixedListing()),
        );
    }

    public function testNoSizeCapAppliesToWhatTheServiceRenders(): void
    {
        // The original's size says nothing about the file that will be kept; the
        // kept file is checked against the cap in apply(), whatever this says.
        self::assertGreaterThan(
            AvatarStorage::MAX_BYTES,
            $this->pickerFor($this->renderingDriver())->maxBytesFor($this->integration()),
        );
        self::assertSame(
            AvatarStorage::MAX_BYTES,
            $this->picker(null)->maxBytesFor($this->integration()),
        );
    }

    private function mixedListing(): Listing
    {
        return new Listing([
            new Entry('heic', 'IMG_1.HEIC', false, 9_000_000, 'image/heic'),
            new Entry('jpeg', 'IMG_2.JPG', false, 2_000_000, 'image/jpeg'),
            new Entry('video', 'IMG_1.MOV', false, 5_000_000, 'video/quicktime'),
            new Entry('pdf', 'scan.pdf', false, 100_000, 'application/pdf'),
        ]);
    }

    private function renderingDriver(): PictureRenderingDriver
    {
        return new PictureRenderingDriver(
            picture: new RemoteFile('IMG_1.jpg', 'image/jpeg', self::jpeg()),
            original: new RemoteFile('IMG_1.HEIC', 'image/heic', 'unused'),
        );
    }

    private static function jpeg(): string
    {
        ob_start();
        imagejpeg(imagecreatetruecolor(2, 2));

        return (string) ob_get_clean();
    }

    public function testADownloadThatFailsIsRefusedRatherThanThrown(): void
    {
        $this->expectExceptionObject(new AvatarRefusedException(AvatarRefusedException::UNAVAILABLE));

        $this->picker(null)->apply(new User(), $this->integration(), 'f1');
    }

    private function picker(?RemoteFile $file): AvatarFromIntegration
    {
        $driver = new class ($file) implements IntegrationDriverInterface {
            public function __construct(private readonly ?RemoteFile $file)
            {
            }

            public function supports(Provider $provider): bool
            {
                return true;
            }

            public function verify(Integration $integration): void
            {
            }

            public function list(Integration $integration, ?string $folderId = null, ?string $cursor = null): Listing
            {
                return new Listing([]);
            }

            public function download(Integration $integration, string $fileId): RemoteFile
            {
                return $this->file ?? throw new IntegrationException('the service is down');
            }

            public function upload(Integration $integration, string $absolutePath, string $filename, string $mime, ?string $folderId = null): string
            {
                return 'unused';
            }

            public function shareLink(Integration $integration, string $fileId): ?string
            {
                return null;
            }

            public function thumbnail(Integration $integration, string $fileId): ?RemoteFile
            {
                return null;
            }
        };

        return $this->pickerFor($driver);
    }

    private function pickerFor(IntegrationDriverInterface $driver): AvatarFromIntegration
    {
        return new AvatarFromIntegration(
            $this->createStub(IntegrationRepository::class),
            new IntegrationDriverRegistry([$driver]),
            new AvatarStorage($this->root, 'var'),
            $this->createStub(EntityManagerInterface::class),
        );
    }

    private function integration(): Integration
    {
        return new Integration(new User(), Provider::Nextcloud, 'Nextcloud');
    }
}

/**
 * A service whose download() is a rendition and whose original is a different
 * file, recording which one it was asked for.
 */
final class OriginalKeepingDriver implements IntegrationDriverInterface, OriginalFileDriverInterface
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(
        private readonly RemoteFile $rendition,
        private readonly RemoteFile $original,
    ) {
    }

    public function supports(Provider $provider): bool
    {
        return true;
    }

    public function verify(Integration $integration): void
    {
    }

    public function list(Integration $integration, ?string $folderId = null, ?string $cursor = null): Listing
    {
        return new Listing([]);
    }

    public function download(Integration $integration, string $fileId): RemoteFile
    {
        $this->calls[] = 'download';

        return $this->rendition;
    }

    public function downloadOriginal(Integration $integration, string $fileId): RemoteFile
    {
        $this->calls[] = 'downloadOriginal';

        return $this->original;
    }

    public function upload(Integration $integration, string $absolutePath, string $filename, string $mime, ?string $folderId = null): string
    {
        return 'unused';
    }

    public function shareLink(Integration $integration, string $fileId): ?string
    {
        return null;
    }

    public function thumbnail(Integration $integration, string $fileId): ?RemoteFile
    {
        return null;
    }
}

/**
 * Immich in miniature: the listing names the original, and a picture is a
 * separate rendition the service made, recording which one it was asked for.
 */
final class PictureRenderingDriver implements IntegrationDriverInterface, PictureRenditionDriverInterface
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(
        private readonly RemoteFile $picture,
        private readonly RemoteFile $original,
    ) {
    }

    public function supports(Provider $provider): bool
    {
        return true;
    }

    public function verify(Integration $integration): void
    {
    }

    public function list(Integration $integration, ?string $folderId = null, ?string $cursor = null): Listing
    {
        return new Listing([]);
    }

    public function download(Integration $integration, string $fileId): RemoteFile
    {
        $this->calls[] = 'download';

        return $this->original;
    }

    public function downloadPicture(Integration $integration, string $fileId): RemoteFile
    {
        $this->calls[] = 'downloadPicture';

        return $this->picture;
    }

    public function upload(Integration $integration, string $absolutePath, string $filename, string $mime, ?string $folderId = null): string
    {
        return 'unused';
    }

    public function shareLink(Integration $integration, string $fileId): ?string
    {
        return null;
    }

    public function thumbnail(Integration $integration, string $fileId): ?RemoteFile
    {
        return null;
    }
}
