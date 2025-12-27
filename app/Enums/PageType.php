<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Canonical page types stored in pages.page_type.
 */
enum PageType: string
{
    case PRODUCT = 'PRODUCT';
    case CATEGORY = 'CATEGORY';
    case MAIN = 'MAIN';
    case CONTACT = 'CONTACT';
    case SITEMAP = 'SITEMAP';
}


