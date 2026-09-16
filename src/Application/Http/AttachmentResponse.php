<?php

declare(strict_types=1);

namespace App\Application\Http;

use App\Domain\Entity\Attachment;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Streams a stored attachment back to an authorised caller.
 *
 * Shared by the web route and the API route so the headers cannot diverge, and
 * every one of them is load-bearing:
 *
 *  - The Content-Type is the type this application *detected* when the file was
 *    stored, never what the uploader claimed. Combined with `nosniff`, that is
 *    what stops a file that talked its way past validation from being executed
 *    or rendered as HTML in the instance's own origin.
 *  - `Content-Disposition: attachment` means even an image is downloaded rather
 *    than rendered in the page.
 *  - The filename is sent twice: a stripped ASCII form for old clients and an
 *    RFC 5987 `filename*` for everything else, because an invoice called
 *    "facture-août.pdf" should arrive with its name intact.
 *  - `private, no-store` keeps a receipt out of shared caches.
 */
final class AttachmentResponse
{
    public static function stream(
        ResponseInterface $response,
        StreamFactoryInterface $streams,
        Attachment $attachment,
        string $absolutePath,
    ): ResponseInterface {
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $attachment->originalFilename) ?? 'attachment';
        $ascii = str_replace(['"', '\\'], '_', $ascii);

        return $response
            ->withBody($streams->createStreamFromFile($absolutePath, 'rb'))
            ->withHeader('Content-Type', $attachment->mimeType)
            ->withHeader('Content-Length', (string) $attachment->sizeBytes)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Content-Disposition', sprintf(
                'attachment; filename="%s"; filename*=UTF-8\'\'%s',
                $ascii,
                rawurlencode($attachment->originalFilename),
            ));
    }
}
