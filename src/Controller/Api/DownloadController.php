<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Download;
use App\Repository\DownloadRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * First-party gated download. The site's download button points here, and this
 * endpoint is the ONLY public way to reach the zip: the archive is served by
 * the internal "marketing" nginx and Caddy 404s /downloads/*.zip on the apex,
 * so a bot cannot skip the gate by fetching the file directly. Every request is
 * bot-filtered and per-IP rate limited, then the bytes are streamed back from
 * the internal host over the private docker network. Counting happens here too,
 * so the download table reflects real (gated) downloads rather than raw clicks.
 */
class DownloadController extends AbstractController
{
    // Non-browser clients that have no business pulling the zip: scripting/HTTP
    // tools and known SEO/AI crawlers. Real browsers never carry these tokens,
    // and we deliberately avoid a bare "bot" match to spare odd device UAs.
    private const BOT_UA = '/(curl|wget|python-requests|python-urllib|libwww|go-http-client|okhttp|java\/|apache-httpclient|node-fetch|axios|scrapy|httrack|headlesschrome|phantomjs|googlebot|bingbot|yandexbot|baiduspider|duckduckbot|semrushbot|ahrefsbot|mj12bot|dotbot|bytespider|gptbot|ccbot|claudebot|anthropic|perplexitybot)/i';

    public function __construct(
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
        #[Autowire(service: 'limiter.download')]
        private readonly RateLimiterFactory $downloadLimiter,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/api/download', name: 'api_download', methods: ['GET'])]
    public function download(Request $request, DownloadRepository $downloads): Response
    {
        $ua = (string) $request->headers->get('user-agent', '');
        $ip = (string) $request->getClientIp();

        // 1. Reject obvious non-browser clients. Cheap, and it clears out the
        //    bulk of the scripted-curl noise before anything heavier runs. A 404
        //    (not 403) keeps the endpoint from advertising that it filters.
        if ($ua === '' || preg_match(self::BOT_UA, $ua) === 1) {
            $this->logger->info('download blocked (ua)', ['ua' => $ua, 'ip' => $ip]);

            return new Response('', Response::HTTP_NOT_FOUND);
        }

        // 2. Per-IP rate limit. Only bites a client hammering the link.
        $limit = $this->downloadLimiter->create($ip !== '' ? $ip : 'unknown')->consume();
        if (!$limit->isAccepted()) {
            $this->logger->info('download blocked (rate limit)', ['ip' => $ip]);
            $response = new Response('', Response::HTTP_TOO_MANY_REQUESTS);
            $response->headers->set('Retry-After', (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()));

            return $response;
        }

        // Accept only a dotted-numeric version; anything else falls back to the
        // stable archive so a crafted query string can't build an odd path.
        $requested = (string) $request->query->get('v', '');
        $version = preg_match('/^\d+(\.\d+){0,3}$/', $requested) === 1 ? $requested : '';
        $file = $version !== '' ? 'checkoutly-'.$version.'.zip' : 'checkoutly.zip';

        // 3. Fetch the archive from the internal marketing host. This travels
        //    the private docker network, not the public apex, so the Caddy
        //    /downloads/*.zip block does not apply to it.
        $upstream = $this->httpClient->request('GET', 'http://marketing/downloads/'.$file, [
            'timeout' => 30,
        ]);

        if ($upstream->getStatusCode() !== 200) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        // 4. Record the (gated) download for accurate stats.
        $downloads->record(new Download(
            $version !== '' ? $version : 'latest',
            $ip !== '' ? hash('sha256', $ip.$this->secret) : null,
            $this->clip($request->headers->get('cf-ipcountry'), 2),
            $this->clip($request->headers->get('referer'), 255),
            $this->clip($ua, 255),
        ));

        $length = $upstream->getHeaders(false)['content-length'][0] ?? null;

        $response = new StreamedResponse(function () use ($upstream): void {
            foreach ($this->httpClient->stream($upstream) as $chunk) {
                echo $chunk->getContent();
                flush();
            }
        });
        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $file,
        ));
        if ($length !== null) {
            $response->headers->set('Content-Length', $length);
        }
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }

    private function clip(?string $value, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr($value, 0, $max);
    }
}
