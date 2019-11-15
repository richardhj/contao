<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Controller;

use Contao\BackendUser;
use Contao\CoreBundle\Exception\PageNotFoundException;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\Authentication\FrontendPreviewAuthenticator;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route(defaults={"_scope" = "backend"})
 */
final class BackendPreviewSwitchController
{

    private $contaoFramework;

    private $frontendPreviewAuthenticator;

    private $tokenChecker;

    public function __construct(
        ContaoFramework $contaoFramework,
        FrontendPreviewAuthenticator $frontendPreviewAuthenticator,
        TokenChecker $tokenChecker
    ) {
        $this->contaoFramework              = $contaoFramework;
        $this->frontendPreviewAuthenticator = $frontendPreviewAuthenticator;
        $this->tokenChecker                 = $tokenChecker;
    }

    /**
     * @Route("/_contao/preview_switch", name="contao_backend_preview_switch")
     */
    public function __invoke(Request $request): Response
    {
        $this->contaoFramework->initialize(false);

        $user = BackendUser::getInstance();
        if (null === $user || !$request->isXmlHttpRequest()) {
            throw new PageNotFoundException();
        }

        if ('tl_switch' !== $request->request->get('FORM_SUBMIT')) {
            return Response::create('', 404);
        }

        $canSwitchUser    = ($user->isAdmin || (!empty($user->amg) && \is_array($user->amg)));
        $frontendUsername = $this->tokenChecker->getFrontendUsername();
        $showUnpublished  = 'hide' !== $request->request->get('unpublished');

        if ($canSwitchUser) {
            $frontendUsername = $request->request->get('user') ?: null;
        }

        if (null !== $frontendUsername) {
            $this->frontendPreviewAuthenticator->authenticateFrontendUser($frontendUsername, $showUnpublished);
        } else {
            $this->frontendPreviewAuthenticator->authenticateFrontendGuest($showUnpublished);
        }

        return Response::create();
    }
}
