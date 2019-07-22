<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Controller\FrontendModule;

use Contao\CoreBundle\DataExport\BinaryAttachmentUnit;
use Contao\CoreBundle\DataExport\HtmlEncoder;
use Contao\CoreBundle\DataExport\SerializableAttachmentUnit;
use Contao\CoreBundle\Event\ContaoCoreEvents;
use Contao\CoreBundle\Event\ExportUserDataEvent;
use Contao\CoreBundle\ServiceAnnotation\FrontendModule;
use Contao\FormSelectMenu;
use Contao\FrontendUser;
use Contao\Input;
use Contao\ModuleModel;
use Contao\Template;
use Contao\ZipWriter;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Serializer\Encoder\JsonEncode;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Encoder\XmlEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * @FrontendModule(category="user")
 */
final class DataExport extends AbstractFrontendModuleController
{

    private $dispatcher;

    private $tokenStorage;

    private $projectDir;

    public function __construct(
        TokenStorageInterface $tokenStorage,
        EventDispatcherInterface $dispatcher,
        string $projectDir
    ) {
        $this->dispatcher   = $dispatcher;
        $this->tokenStorage = $tokenStorage;
        $this->projectDir   = $projectDir;
    }

    protected function getResponse(Template $template, ModuleModel $model, Request $request): Response
    {
        if (!FE_USER_LOGGED_IN) {
            return Response::create('');
        }

        $user = $this->getFrontendUser();
        $form = '';


        $widget = new FormSelectMenu();

        $widget->id        = 'format';
        $widget->name      = 'format';
        $widget->mandatory = true;
        $widget->options   = [
            ['value' => 'xml', 'label' => 'XML'],
            ['value' => 'json', 'label' => 'JSON'],
            ['value' => 'html', 'label' => 'HTML']
        ];

        $form .= $widget->generateWithError();

        if ('mod_export_user' === Input::post('FORM_SUBMIT')) {
            $widget->validate();

            if (!$widget->hasErrors()) {
                $this->download($user, $widget->value);

//            return $this->file($this->projectDir.'/test.zip');
                return Response::create('');
            }
        }

        $template->form = $form;

        return Response::create($template->parse());
    }

    private function getFrontendUser(): FrontendUser
    {
        $token = $this->tokenStorage->getToken();
        if (null === $token) {
            throw new \RuntimeException('No token provided');
        }

        $user = $token->getUser();

        if (!$user instanceof FrontendUser) {
            throw new \RuntimeException('The token does not contain a front end user object');
        }

        return $user;
    }

    private function download(FrontendUser $user, string $format): void
    {
        $event = new ExportUserDataEvent($user);
        $this->dispatcher->dispatch($event, ContaoCoreEvents::EXPORT_USER_DATA);

        $zip = new ZipWriter('test.zip');

        $encoders    = [
            new XmlEncoder(),
            new JsonEncoder(new JsonEncode([JsonEncode::OPTIONS => JSON_PRETTY_PRINT])),
            new HtmlEncoder()
        ];
        $normalizers = [new ObjectNormalizer()];
        $serializer  = new Serializer($normalizers, $encoders);

        foreach ($event->getDataExportUnit() as $dataExportUnit) {
            if ($dataExportUnit instanceof BinaryAttachmentUnit) {
                $zip->addFile(
                    str_replace($this->projectDir, '', $dataExportUnit->getFile()->getRealPath()),
                    $dataExportUnit->getLocalFileName()
                );
                continue;
            }

            if ($dataExportUnit instanceof SerializableAttachmentUnit) {
                $jsonContent = $serializer->serialize($dataExportUnit->getObject(), $format);
                $zip->addString($jsonContent, $dataExportUnit->getLocalFileName() . '.' . $format);
                continue;
            }

            throw new \RuntimeException('Unexpected data export unit');
        }

        $zip->close();
    }
}
