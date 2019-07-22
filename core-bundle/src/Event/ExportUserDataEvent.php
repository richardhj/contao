<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Event;

use Contao\CoreBundle\DataExport\DataExportUnitInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class ExportUserDataEvent
{
    private $user;

    /**
     * @var DataExportUnitInterface[]
     */
    private $dataExportUnits;

    public function __construct(UserInterface $user)
    {
        $this->user = $user;
    }

    public function getUser(): UserInterface
    {
        return $this->user;
    }

    public function addDataExportUnit($data): void
    {
        $this->dataExportUnits[] = $data;
    }

    public function getDataExportUnit(): array
    {
        return $this->dataExportUnits;
    }
}
