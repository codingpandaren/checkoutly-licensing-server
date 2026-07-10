<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Canonicalises a shop domain so the domain a merchant registers in the portal
 * and the domain the module reports on a heartbeat compare equal regardless of
 * scheme, www, port, path or casing.
 */
class DomainNormalizer
{
    public function normalize(string $domain): string
    {
        $value = strtolower(trim($domain));
        $value = (string) preg_replace('#^https?://#', '', $value); // scheme
        $value = (string) preg_replace('#/.*$#', '', $value);       // path
        $value = (string) preg_replace('#:\d+$#', '', $value);      // port
        $value = (string) preg_replace('#^www\.#', '', $value);     // leading www.

        return trim($value, '.');
    }
}
