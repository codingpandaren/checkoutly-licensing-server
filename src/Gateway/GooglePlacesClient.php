<?php

declare(strict_types=1);

namespace App\Gateway;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Server-side client for Google Places API (New). OUR key lives here, never in
 * the shipped module - that is the whole point of the gateway: the merchant does
 * no Google Cloud setup, and a cracked module can't reach Places without a valid
 * license answered by us.
 *
 * Session tokens (§7) are the key cost lever: the module mints one token per
 * address-entry session and sends it with every autocomplete keystroke AND the
 * final details call; we forward it so Google bills one session, not per
 * keystroke. Quota is metered on the details call to align with that billing -
 * autocomplete is deliberately NOT billable. Predictions are never cached (TOS).
 *
 * The response is normalized to PrestaShop-agnostic address fields; resolving
 * those to country/state ids stays in the module, which owns the shop's DB.
 */
final class GooglePlacesClient
{
    private const AUTOCOMPLETE_URL = 'https://places.googleapis.com/v1/places:autocomplete';
    private const DETAILS_URL = 'https://places.googleapis.com/v1/places/';
    private const TIMEOUT = 5;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(GOOGLE_PLACES_API_KEY)%')]
        private readonly string $apiKey = '',
    ) {
    }

    public function isConfigured(): bool
    {
        return trim($this->apiKey) !== '';
    }

    /**
     * Address predictions for a query. Not a billable unit.
     */
    public function autocomplete(string $query, string $session, string $lang): GatewayResult
    {
        $query = trim($query);
        if ($query === '') {
            throw GatewayException::invalidInput('empty query');
        }
        $this->assertConfigured();

        $body = [
            'input' => $query,
            'includedPrimaryTypes' => ['street_address', 'premise', 'subpremise', 'route'],
        ];
        if ($session !== '') {
            $body['sessionToken'] = $session;
        }
        if ($lang !== '') {
            $body['languageCode'] = $lang;
        }

        $response = $this->request(
            'POST',
            self::AUTOCOMPLETE_URL,
            $body,
            'suggestions.placePrediction.placeId,suggestions.placePrediction.text.text'
        );

        $predictions = [];
        foreach (($response['suggestions'] ?? []) as $suggestion) {
            $prediction = $suggestion['placePrediction'] ?? null;
            if (!is_array($prediction) || empty($prediction['placeId'])) {
                continue;
            }
            $predictions[] = [
                'place_id' => (string) $prediction['placeId'],
                'description' => (string) ($prediction['text']['text'] ?? ''),
            ];
        }

        return new GatewayResult(['predictions' => $predictions], cached: false, billable: false);
    }

    /**
     * Full details for a chosen prediction, normalized to address fields. This is
     * the billable unit - it completes the session Google bills for.
     */
    public function details(string $placeId, string $session): GatewayResult
    {
        $placeId = trim($placeId);
        if ($placeId === '') {
            throw GatewayException::invalidInput('empty place_id');
        }
        $this->assertConfigured();

        $url = self::DETAILS_URL . rawurlencode($placeId);
        if ($session !== '') {
            $url .= '?sessionToken=' . rawurlencode($session);
        }

        $response = $this->request('GET', $url, null, 'addressComponents');
        if (!isset($response['addressComponents']) || !is_array($response['addressComponents'])) {
            throw GatewayException::upstreamUnavailable('places details missing components');
        }

        return new GatewayResult($this->normalize($response['addressComponents']), cached: false, billable: true);
    }

    /**
     * @param array<int, array<string, mixed>> $components
     *
     * @return array{address1: string, address2: string, city: string, postcode: string, countryCode: string, stateName: string, stateCode: string}
     */
    private function normalize(array $components): array
    {
        $get = static function (string $type, bool $short = false) use ($components): string {
            foreach ($components as $component) {
                if (in_array($type, $component['types'] ?? [], true)) {
                    return (string) ($short ? ($component['shortText'] ?? '') : ($component['longText'] ?? ''));
                }
            }

            return '';
        };

        return [
            'address1' => trim($get('street_number') . ' ' . $get('route')),
            'address2' => $get('subpremise'),
            'city' => $get('locality') ?: $get('postal_town'),
            'postcode' => $get('postal_code'),
            'countryCode' => strtoupper($get('country', true)),
            'stateName' => $get('administrative_area_level_1'),
            'stateCode' => $get('administrative_area_level_1', true),
        ];
    }

    private function assertConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw GatewayException::upstreamUnavailable('places api key not configured');
        }
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $url, ?array $body, string $fieldMask): array
    {
        try {
            $options = [
                'timeout' => self::TIMEOUT,
                'headers' => [
                    'X-Goog-Api-Key' => trim($this->apiKey),
                    'X-Goog-FieldMask' => $fieldMask,
                ],
            ];
            if ($method === 'POST') {
                $options['json'] = $body ?? [];
            }

            $response = $this->httpClient->request($method, $url, $options);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                throw GatewayException::upstreamUnavailable('places http ' . $status);
            }

            return $response->toArray(false);
        } catch (TransportException | HttpExceptionInterface $e) {
            throw GatewayException::upstreamUnavailable('places transport: ' . $e->getMessage());
        }
    }
}
