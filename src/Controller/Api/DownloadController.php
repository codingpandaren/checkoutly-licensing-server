<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Download;
use App\Repository\DownloadRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * First-party download counter. The site's download button points here instead
 * of straight at the zip; we record the hit, then 302 to the real archive on
 * the marketing host. Counting is accurate because the apex /api/* is served by
 * this app directly (not from Cloudflare's cache).
 */
class DownloadController extends AbstractController
{
    public function __construct(
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    ) {
    }

    #[Route('/api/download', name: 'api_download', methods: ['GET'])]
    public function download(Request $request, DownloadRepository $downloads): RedirectResponse
    {
        $requested = (string) $request->query->get('v', '');
        // Accept only a dotted-numeric version; anything else falls back to the
        // stable archive so a crafted query string can't build an odd path.
        $version = preg_match('/^\d+(\.\d+){0,3}$/', $requested) === 1 ? $requested : '';
        $target = $version !== ''
            ? '/downloads/checkoutly-'.$version.'.zip'
            : '/downloads/checkoutly.zip';

        $ip = (string) $request->getClientIp();

        $downloads->record(new Download(
            $version !== '' ? $version : 'latest',
            $ip !== '' ? hash('sha256', $ip.$this->secret) : null,
            $this->clip($request->headers->get('cf-ipcountry'), 2),
            $this->clip($request->headers->get('referer'), 255),
            $this->clip($request->headers->get('user-agent'), 255),
        ));

        return new RedirectResponse($target);
    }

    private function clip(?string $value, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr($value, 0, $max);
    }
}
