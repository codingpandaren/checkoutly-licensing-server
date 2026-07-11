<?php

declare(strict_types=1);

namespace App\Gateway;

use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin client over the EU VIES REST API for EU VAT number validation. VIES is
 * free and returns public company data, so results are cached (§6): the same
 * (country, number) rarely changes and re-querying VIES per keystroke would be
 * wasteful and rude to their service.
 *
 * A definitive answer - valid true OR false - is cached. Service faults
 * (VIES/member-state unavailable, transport error) are NOT cached and surface as
 * upstream_unavailable so the module degrades to the plain field.
 */
final class ViesClient
{
    private const BASE = 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms';
    private const TTL = 86400;

    /**
     * userError values VIES returns for a transient fault rather than a genuine
     * "invalid number". These are never cached and surface as upstream_unavailable.
     */
    private const SERVICE_FAULTS = [
        'MS_UNAVAILABLE',
        'MS_MAX_CONCURRENT_REQ',
        'SERVICE_UNAVAILABLE',
        'TIMEOUT',
        'GLOBAL_MAX_CONCURRENT_REQ',
        'IP_BLOCKED',
        'INVALID_REQUESTER_INFO',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $viesCache,
    ) {
    }

    public function validate(string $country, string $vat): GatewayResult
    {
        $country = strtoupper(preg_replace('/[^A-Za-z]/', '', $country) ?? '');
        $vat = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $vat) ?? '');

        if (strlen($country) !== 2) {
            throw GatewayException::invalidInput('country must be a 2-letter code');
        }
        // Merchants often paste the number with the country prefix ("LT1000...").
        if (str_starts_with($vat, $country)) {
            $vat = substr($vat, 2);
        }
        if ($vat === '') {
            throw GatewayException::invalidInput('vat number is empty');
        }

        $cacheHit = true;
        $data = $this->viesCache->get(
            'vies.' . $country . '.' . $vat,
            function (ItemInterface $item) use ($country, $vat, &$cacheHit): array {
                $cacheHit = false;
                $item->expiresAfter(self::TTL);

                return $this->query($country, $vat);
            }
        );

        return new GatewayResult($data, cached: $cacheHit, billable: true);
    }

    /**
     * @return array{valid: bool, name: string, address: string, countryCode: string, vatNumber: string}
     */
    private function query(string $country, string $vat): array
    {
        try {
            $response = $this->httpClient->request('GET', sprintf('%s/%s/vat/%s', self::BASE, $country, $vat), [
                'timeout' => 3,
                'headers' => ['Accept' => 'application/json'],
            ]);

            if ($response->getStatusCode() !== 200) {
                throw GatewayException::upstreamUnavailable('vies http ' . $response->getStatusCode());
            }

            $body = $response->toArray(false);
        } catch (TransportException | HttpExceptionInterface $e) {
            throw GatewayException::upstreamUnavailable('vies transport: ' . $e->getMessage());
        }

        if (!array_key_exists('isValid', $body)) {
            throw GatewayException::upstreamUnavailable('vies malformed response');
        }

        // isValid=false can mean either "this VAT is genuinely invalid" (a
        // definitive, cacheable fact) or a transient service fault dressed up as
        // invalid. userError distinguishes them; fault codes must NOT be cached.
        $userError = strtoupper((string) ($body['userError'] ?? ''));
        if (in_array($userError, self::SERVICE_FAULTS, true)) {
            throw GatewayException::upstreamUnavailable('vies: ' . $userError);
        }

        $valid = (bool) $body['isValid'];

        return [
            'valid' => $valid,
            'name' => $valid ? trim((string) ($body['name'] ?? '')) : '',
            'address' => $valid ? trim((string) ($body['address'] ?? '')) : '',
            'countryCode' => $country,
            'vatNumber' => $vat,
        ];
    }
}
