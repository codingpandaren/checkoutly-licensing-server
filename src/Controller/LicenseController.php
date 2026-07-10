<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\License;
use App\Entity\User;
use App\Repository\LicenseRepository;
use App\Service\DomainNormalizer;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class LicenseController extends AbstractController
{
    #[Route('/license/{id}', name: 'app_license_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(#[MapEntity] License $license): Response
    {
        $this->assertOwner($license);

        return $this->render('license/show.html.twig', ['license' => $license]);
    }

    #[Route('/license/{id}/domain', name: 'app_license_domain', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function updateDomain(
        Request $request,
        #[MapEntity] License $license,
        LicenseRepository $licenses,
        DomainNormalizer $normalizer,
    ): Response {
        $this->assertOwner($license);

        if (!$this->isCsrfTokenValid('license_domain_' . $license->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $domain = $normalizer->normalize((string) $request->request->get('domain'));

        if (!$this->isValidDomain($domain)) {
            $this->addFlash('error', 'Please enter a valid store domain, e.g. yourstore.com.');

            return $this->redirectToRoute('app_license_show', ['id' => $license->getId()]);
        }

        $existing = $licenses->findOneByRegisteredDomain($domain);
        if ($existing !== null && $existing->getId() !== $license->getId()) {
            $this->addFlash('error', 'That domain is already registered to another license.');

            return $this->redirectToRoute('app_license_show', ['id' => $license->getId()]);
        }

        $license->setRegisteredDomain($domain);
        $licenses->save($license);

        $this->addFlash('success', 'Store domain set to ' . $domain . '. Changes can take up to 24 hours to apply in your shop - or re-save your license key in the module to apply immediately.');

        return $this->redirectToRoute('app_license_show', ['id' => $license->getId()]);
    }

    private function assertOwner(License $license): void
    {
        /** @var User $user */
        $user = $this->getUser();
        if ($license->getUser()->getId() !== $user->getId()) {
            throw $this->createNotFoundException();
        }
    }

    private function isValidDomain(string $domain): bool
    {
        return $domain !== ''
            && strlen($domain) <= 191
            && preg_match('/^(?=.{1,253}$)([a-z0-9](-?[a-z0-9])*\.)+[a-z]{2,}$/', $domain) === 1;
    }
}
