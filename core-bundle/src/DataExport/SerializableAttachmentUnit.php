<?php


namespace Contao\CoreBundle\DataExport;


class SerializableAttachmentUnit implements DataExportUnitInterface
{

    private $localFileName;
    private $object;

    public function __construct(string $localFileName, $object)
    {
        $this->object        = $object;
        $this->localFileName = $localFileName;
    }

    public function getLocalFileName(): string
    {
        return $this->localFileName;
    }

    public function getObject()
    {
        return $this->object;
    }
}
