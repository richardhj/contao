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

use Contao\ArticleModel;
use Contao\CoreBundle\Event\PreviewUrlConvertEvent;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\Authentication\FrontendPreviewAuthenticator;
use Contao\PageModel;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * This controller handles the back end preview call and redirects to the requested front end page while ensuring the
 * /preview.php entry point is used. When requested, the front end user gets authenticated.
 *
 * @Route(defaults={"_scope" = "backend"})
 */
class BackendPreviewController
{
    private $contaoFramework;

    private $previewScript;

    private $frontendPreviewAuthenticator;

    private $dispatcher;

    private $router;

    private $authorizationChecker;

    public function __construct(
        ContaoFramework $contaoFramework,
        string $previewScript,
        FrontendPreviewAuthenticator $frontendPreviewAuthenticator,
        EventDispatcherInterface $dispatcher,
        RouterInterface $router,
        AuthorizationCheckerInterface $authorizationChecker
    ) {
        $this->contaoFramework = $contaoFramework;
        $this->previewScript = $previewScript;
        $this->frontendPreviewAuthenticator = $frontendPreviewAuthenticator;
        $this->dispatcher = $dispatcher;
        $this->router = $router;
        $this->authorizationChecker = $authorizationChecker;
    }

    /**
     * @Route("/contao/preview", name="contao_backend_preview")
     */
    public function __invoke(Request $request): Response
    {
        if ($request->getScriptName() !== $this->previewScript) {
            return new RedirectResponse($this->previewScript.$request->getRequestUri());
        }

        $this->contaoFramework->initialize();

        if (!$this->authorizationChecker->isGranted('ROLE_USER')) {
            return new Response('Access denied', Response::HTTP_FORBIDDEN);
        }

        // Switch to a particular member (see contao/core#6546)
        if (($frontendUser = $request->query->get('user'))
            && !$this->frontendPreviewAuthenticator->authenticateFrontendUser($frontendUser, false)) {
            $this->frontendPreviewAuthenticator->removeFrontendAuthentication();
        }

        if ($request->query->get('url')) {
            $targetUrl = $request->getBaseUrl().'/'.$request->query->get('url');

            return new RedirectResponse($targetUrl);
        }

        if ($request->query->get('page') && null !== $page = PageModel::findWithDetails($request->query->get('page'))) {
            $params = null;

            // Add the /article/ fragment (see contao/core-bundle#673)
            if (null !== ($article = ArticleModel::findByAlias($request->query->get('article')))) {
                $params = sprintf(
                    '/articles/%s%s',
                    ('main' !== $article->inColumn) ? $article->inColumn.':' : '',
                    $article->id
                );
            }

            return new RedirectResponse($page->getPreviewUrl($params));
        }

        $urlConvertEvent = new PreviewUrlConvertEvent();
        $this->dispatcher->dispatch($urlConvertEvent);

        if (null !== $targetUrl = $urlConvertEvent->getUrl()) {
            return new RedirectResponse($targetUrl);
        }

        return new RedirectResponse($this->router->generate('contao_root', [], UrlGeneratorInterface::ABSOLUTE_URL));
    }
}