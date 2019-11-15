<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\EventListener;

use Contao\BackendUser;
use Contao\CoreBundle\Routing\ScopeMatcher;
use Contao\CoreBundle\Security\Authentication\Token\TokenChecker;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment as TwigEnvironment;
use Twig\Error\Error as TwigError;

/**
 * PreviewToolbarListener injects the back end preview switch toolbar.
 *
 * The onKernelResponse method must be connected to the kernel.response event.
 *
 * The toolbar is only injected on well-formed HTML (with a proper <body> tag).
 * This means that the WDT is never included in sub-requests or ESI requests.
 */
final class PreviewToolbarListener
{

    private $scopeMatcher;

    private $previewScript;

    private $twig;

    private $authorizationChecker;

    private $tokenStorage;

    private $tokenChecker;

    private $router;

    private $tokenManager;

    private $csrfTokenName;

    public function __construct(
        string $previewScript,
        ScopeMatcher $scopeMatcher,
        TwigEnvironment $twig,
        AuthorizationChecker $authorizationChecker,
        TokenStorageInterface $tokenStorage,
        TokenChecker $tokenChecker,
        RouterInterface $router,
        CsrfTokenManagerInterface $tokenManager,
        string $csrfTokenName
    ) {
        $this->previewScript        = $previewScript;
        $this->scopeMatcher         = $scopeMatcher;
        $this->twig                 = $twig;
        $this->authorizationChecker = $authorizationChecker;
        $this->tokenStorage         = $tokenStorage;
        $this->tokenChecker         = $tokenChecker;
        $this->router               = $router;
        $this->tokenManager         = $tokenManager;
        $this->csrfTokenName        = $csrfTokenName;
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$this->scopeMatcher->isContaoMasterRequest($event)) {
            return;
        }

        $request  = $event->getRequest();
        $response = $event->getResponse();

        if ($request->getScriptName() !== $this->previewScript) {
            return;
        }

        // Ignore redirects and errors
        if (200 !== $response->getStatusCode()) {
            return;
        }

        // Do not capture redirects or modify XML HTTP Requests
        if ($request->isXmlHttpRequest()) {
            return;
        }

        if (null === $user = BackendUser::getInstance()) {
            return;
        }

        // Only inject toolbar on html responses
        if ('html' !== $request->getRequestFormat()
            || false === strpos((string) $response->headers->get('Content-Type'), 'html')
            || false !== stripos((string) $response->headers->get('Content-Disposition'), 'attachment;')
        ) {
            return;
        }

        try {
            $this->injectToolbar($response, $request, $user);
        } catch (TwigError $e) {
        }
    }


    /**
     * @throws TwigError
     */
    private function injectToolbar(Response $response, Request $request, BackendUser $user): void
    {
        $content = $response->getContent();

        if (false === strpos($content, '<body')) {
            return;
        }

        $canSwitchUser    = ($user->isAdmin || (!empty($user->amg) && \is_array($user->amg)));
        $frontendUsername = $this->tokenChecker->getFrontendUsername();
        $showUnpublished  = $this->tokenChecker->isPreviewMode();

        $toolbar = str_replace(
            "\n",
            '',
            $this->twig->render(
                '@ContaoCore/Backend/preview_toolbar.html.twig',
                [
                    'uniqid'        => 'bpt' . substr(uniqid('', true), 0, 5),
                    'canSwitchUser' => $canSwitchUser,
                    'request_token' => $this->tokenManager->getToken($this->csrfTokenName)->getValue(),
                    'action'        => $this->router->generate('contao_backend_preview_switch'),
                    'user'          => $frontendUsername,
                    'show'          => $showUnpublished,
                    'request'       => $request,
                ]
            )
        );

        $content = preg_replace(
            "/<body[\s\S]*?>/",
            "\$0\n" . $toolbar . "\n",
            $content
        );

        $response->setContent($content);
    }

//    private function getDatalistOptions()
//    {
//        $strGroups = '';
//
//        if (!$this->User->isAdmin)
//        {
//            // No allowed member groups
//            if (empty($this->User->amg) || !\is_array($this->User->amg))
//            {
//                header('Content-type: application/json');
//                die(json_encode(array()));
//            }
//
//            $arrGroups = array();
//
//            foreach ($this->User->amg as $intGroup)
//            {
//                $arrGroups[] = '%"' . (int) $intGroup . '"%';
//            }
//
//            $strGroups = " AND (groups LIKE '" . implode("' OR GROUPS LIKE '", $arrGroups) . "')";
//        }
//
//        $arrUsers = array();
//        $time = Date::floorToMinute();
//
//        // Get the active front end users
//        $objUsers = $this->Database->prepare("SELECT username FROM tl_member WHERE username LIKE ?$strGroups AND login='1' AND disable!='1' AND (start='' OR start<='$time') AND (stop='' OR stop>'" . ($time + 60) . "') ORDER BY username")
//            ->limit(10)
//            ->execute(str_replace('%', '', Input::post('value')) . '%');
//
//        if ($objUsers->numRows)
//        {
//            $arrUsers = $objUsers->fetchEach('username');
//        }
//
//        header('Content-type: application/json');
//        die(json_encode($arrUsers));
//    }
}
