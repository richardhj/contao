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
use Contao\CoreBundle\DataExport\BinaryAttachmentUnit;
use Contao\CoreBundle\DataExport\SerializableAttachmentUnit;
use Contao\CoreBundle\Event\ExportUserDataEvent;
use Contao\FilesModel;
use Contao\FrontendUser;
use Symfony\Component\Finder\Finder;

class UserDataExportListener
{

    private $projectDir;

    public function __construct(string $projectDir)
    {
        $this->projectDir = $projectDir;
    }

    public function addFrontendUserData(ExportUserDataEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof FrontendUser) {
            return;
        }

        $data = new SerializableAttachmentUnit('personal_data', $this->cleanData($user->getData()));
        $event->addDataExportUnit($data);

        $this->addHomeDir($event, $user);
    }

    public function addBackendUserData(ExportUserDataEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof BackendUser) {
            return;
        }

        $data = new SerializableAttachmentUnit('personal_data', $this->cleanData($user->getData()));
        $event->addDataExportUnit($data);
    }

    private function cleanData(array $data): array
    {
        return array_diff_key($data, array_flip(['password', 'homeDir', 'assignDir']));
    }

    private function addHomeDir(ExportUserDataEvent $event, $user): void
    {
        if (!$user->assignDir || !$user->homeDir) {
            return;
        }

        $folder = FilesModel::findByPk($user->homeDir);
        if (null === $folder) {
            return;
        }

        $finder = new Finder();
        $finder->files()->in($this->projectDir . '/' . $folder->path);

        if (!$finder->hasResults()) {
            return;
        }

        foreach ($finder as $file) {
            $attachmentUnit = new BinaryAttachmentUnit('home/' . $file->getRelativePathname(), $file);
            $event->addDataExportUnit($attachmentUnit);
        }
    }
}
