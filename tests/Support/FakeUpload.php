<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Slim\Psr7\UploadedFile;

/**
 * Builds a PSR-7 uploaded file out of bytes, for the upload tests.
 *
 * The bytes matter more than usual here: attachment validation reads the file's
 * magic number rather than trusting its name or its declared type, so a test
 * that faked the type would be testing nothing. These are real minimal PDFs and
 * PNGs.
 */
final class FakeUpload
{
    /** @var list<string> */
    private static array $paths = [];

    public static function pdf(string $filename = 'invoice.pdf'): UploadedFile
    {
        return self::of("%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< >>\n%%EOF\n", $filename);
    }

    public static function png(string $filename = 'logo.png'): UploadedFile
    {
        return self::of(self::pngBytes(), $filename);
    }

    /**
     * A file that claims to be a PDF and is not. The whole point of validating
     * by content is that this is rejected.
     */
    public static function disguisedScript(string $filename = 'invoice.pdf'): UploadedFile
    {
        return self::of("<?php echo 'not a pdf'; ?>\n", $filename);
    }

    public static function of(string $contents, string $filename, ?string $claimedType = null): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'renovo-test-upload');
        file_put_contents($path, $contents);
        self::$paths[] = $path;

        return new UploadedFile(
            $path,
            $filename,
            $claimedType ?? self::guessClaimedType($filename),
            strlen($contents),
            UPLOAD_ERR_OK,
        );
    }

    public static function pngBytes(): string
    {
        // A 1x1 transparent PNG.
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk'
            . 'YPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
            true,
        );
    }

    public static function cleanUp(): void
    {
        foreach (self::$paths as $path) {
            @unlink($path);
        }

        self::$paths = [];
    }

    private static function guessClaimedType(string $filename): string
    {
        return str_ends_with($filename, '.pdf') ? 'application/pdf' : 'image/png';
    }
}
