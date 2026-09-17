<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * A navigation item as a template renders it: where it goes, what it is called,
 * which icon it takes, and whether it is the page you are on.
 *
 * `active` is decided on the server from the request path, so the highlight is
 * right in the HTML that arrives rather than being corrected by script after
 * the page has already painted the wrong item.
 */
final class NavLink
{
    /**
     * @param non-empty-string $labelKey
     * @param non-empty-string $href
     * @param non-empty-string $icon
     */
    public function __construct(
        public readonly string $labelKey,
        public readonly string $href,
        public readonly string $icon,
        public readonly bool $active,
    ) {
    }
}
