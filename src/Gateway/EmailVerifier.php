<?php

declare(strict_types=1);

namespace App\Gateway;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Email quality check for the checkout email field - the single highest-leverage
 * field, since a mistyped address loses the order AND the abandoned-cart recovery
 * email. Near-zero upstream cost (like VIES): syntax, a "did you mean gmail.com?"
 * typo suggestion (mailcheck-style), a disposable-domain blocklist, and an
 * MX/A DNS lookup for deliverability. No paid API - the value is that the merchant
 * gets it bundled and set up for them.
 */
final class EmailVerifier
{
    private const MX_TTL = 86400;

    /**
     * Popular mailbox providers used for typo suggestions. A domain within a
     * couple of edits of one of these is almost certainly a typo of it.
     */
    private const POPULAR_DOMAINS = [
        'gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.co.uk', 'hotmail.com',
        'hotmail.co.uk', 'outlook.com', 'live.com', 'msn.com', 'aol.com',
        'icloud.com', 'me.com', 'mac.com', 'protonmail.com', 'proton.me',
        'gmx.com', 'gmx.net', 'gmx.de', 'mail.com', 'yandex.com', 'zoho.com',
        'comcast.net', 'verizon.net', 'web.de', 't-online.de',
    ];

    private const POPULAR_TLDS = [
        'com', 'net', 'org', 'info', 'biz', 'io', 'co', 'de', 'fr', 'es', 'it',
        'nl', 'us', 'ca', 'eu',
    ];

    /**
     * Common disposable / temp-mail domains. Not exhaustive, but covers the ones
     * that actually show up at checkout.
     */
    private const DISPOSABLE = [
        'mailinator.com', 'guerrillamail.com', 'guerrillamail.info', 'grr.la',
        '10minutemail.com', '10minutemail.net', 'tempmail.com', 'temp-mail.org',
        'yopmail.com', 'yopmail.fr', 'throwawaymail.com', 'getnada.com',
        'trashmail.com', 'trashmail.net', 'sharklasers.com', 'guerrillamailblock.com',
        'maildrop.cc', 'dispostable.com', 'mailnesia.com', 'mintemail.com',
        'fakeinbox.com', 'tempinbox.com', 'spamgourmet.com', 'mailcatch.com',
        'mohmal.com', 'emailondeck.com', 'moakt.com', 'tempr.email',
        'discard.email', 'spam4.me', 'burnermail.io', 'temp-mail.io',
        'mailtemp.info', 'tmpmail.org', 'inboxbear.com', 'jetable.org',
    ];

    public function __construct(
        private readonly CacheInterface $emailCache,
    ) {
    }

    public function verify(string $email): GatewayResult
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            throw GatewayException::invalidInput('empty email');
        }

        $validSyntax = filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        $at = strrpos($email, '@');
        $domain = $at !== false ? substr($email, $at + 1) : '';

        if (!$validSyntax || $domain === '') {
            return new GatewayResult([
                'valid' => false,
                'deliverable' => false,
                'disposable' => false,
                'suggestion' => '',
            ], cached: false, billable: true);
        }

        $local = substr($email, 0, $at);

        return new GatewayResult([
            'valid' => true,
            'deliverable' => $this->hasMailRecords($domain),
            'disposable' => in_array($domain, self::DISPOSABLE, true),
            'suggestion' => $this->suggest($local, $domain),
        ], cached: false, billable: true);
    }

    /**
     * True if the domain can plausibly receive mail (has an MX or, as a fallback,
     * an A record - an "implicit MX"). Cached per domain; DNS is the only slow bit.
     */
    private function hasMailRecords(string $domain): bool
    {
        return (bool) $this->emailCache->get('mx.' . $domain, function (ItemInterface $item) use ($domain): bool {
            $item->expiresAfter(self::MX_TTL);

            return checkdnsrr($domain, 'MX') || checkdnsrr($domain, 'A');
        });
    }

    /**
     * A "did you mean …" suggestion, or '' when the domain looks fine. Matches the
     * whole domain against popular providers first, then falls back to a TLD-only
     * typo check for custom domains (e.g. mystore.con -> mystore.com).
     */
    private function suggest(string $local, string $domain): string
    {
        if (in_array($domain, self::POPULAR_DOMAINS, true)) {
            return '';
        }

        $closestDomain = $this->closest($domain, self::POPULAR_DOMAINS, 2);
        if ($closestDomain !== '') {
            return $local . '@' . $closestDomain;
        }

        $dot = strrpos($domain, '.');
        if ($dot !== false) {
            $tld = substr($domain, $dot + 1);
            if (!in_array($tld, self::POPULAR_TLDS, true)) {
                $closestTld = $this->closest($tld, self::POPULAR_TLDS, 1);
                if ($closestTld !== '') {
                    return $local . '@' . substr($domain, 0, $dot + 1) . $closestTld;
                }
            }
        }

        return '';
    }

    /**
     * Closest candidate within maxDistance edits (and strictly different), or ''.
     *
     * @param list<string> $candidates
     */
    private function closest(string $value, array $candidates, int $maxDistance): string
    {
        $best = '';
        $bestDistance = $maxDistance + 1;
        foreach ($candidates as $candidate) {
            $distance = levenshtein($value, $candidate);
            if ($distance > 0 && $distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $candidate;
            }
        }

        return $bestDistance <= $maxDistance ? $best : '';
    }
}
