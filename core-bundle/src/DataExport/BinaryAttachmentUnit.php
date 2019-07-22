<?php


namespace Contao\CoreBundle\DataExport;


class BinaryAttachmentUnit implements DataExportUnitInterface
{

    private $localFileName;
    private $file;

    public function __construct(string $localFileName, \SplFileInfo $file)
    {
        $this->localFileName = $localFileName;
        $this->file          = $file;
    }

    public function getLocalFileName(): string
    {
        return $this->localFileName;
    }

    public function getFile(): \SplFileInfo
    {
        return $this->file;
    }
}
