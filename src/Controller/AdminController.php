<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\LicenseRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Internal operator console. Guarded by ROLE_ADMIN (granted via app:user:promote)
 * on top of the ^/admin firewall rule. Read-only overview plus a manual kill
 * switch for abuse cases Stripe can't catch (shared keys, ToS violations).
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin')]
class AdminController extends AbstractController
{
    #[Route('/licenses', name: 'app_admin_licenses', methods: ['GET'])]
    public function licenses(LicenseRepository $licenses): Response
    {
        $all = $licenses->findAllForAdmin();

        $stats = [
            'total' => count($all),
            'entitled' => 0,
            'revoked' => 0,
            'registered' => 0,
        ];
        foreach ($all as $license) {
            if ($license->isRevoked()) {
                ++$stats['revoked'];
            }
            if ($license->grantsAccessTo((string) $license->getRegisteredDomain())) {
                ++$stats['entitled'];
            }
            if ($license->getRegisteredDomain()) {
                ++$stats['registered'];
            }
        }

        return $this->render('admin/licenses.html.twig', [
            'licenses' => $all,
            'stats' => $stats,
        ]);
    }

    #[Route('/licenses/{id}/revoke', name: 'app_admin_license_revoke', methods: ['POST'])]
    public function toggleRevoke(int $id, Request $request, LicenseRepository $licenses): Response
    {
        $license = $licenses->find($id);
        if ($license === null) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('admin_revoke_' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');

            return $this->redirectToRoute('app_admin_licenses');
        }

        $license->setRevoked(!$license->isRevoked());
        $licenses->save($license);

        $this->addFlash('success', sprintf(
            'License %s %s.',
            $license->getLicenseId(),
            $license->isRevoked() ? 'revoked' : 'reinstated'
        ));

        return $this->redirectToRoute('app_admin_licenses');
    }
}
