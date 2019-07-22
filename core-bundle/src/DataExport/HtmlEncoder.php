<?php


namespace Contao\CoreBundle\DataExport;


use Symfony\Component\Serializer\Encoder\EncoderInterface;

class HtmlEncoder implements EncoderInterface
{
    const OPTIONS = 'html_encode_options';

    private $defaultContext = [
        self::OPTIONS => 0,
        // Add option for dca table. Then lookup dca for rgxp aware formatting.
    ];

    /**
     * @param array $defaultContext
     */
    public function __construct($defaultContext = [])
    {
            $this->defaultContext = array_merge($this->defaultContext, $defaultContext);
    }

    /**
     * {@inheritdoc}
     */
    public function encode($data, $format, array $context = [])
    {
        $encodedJson = '';
        $encodedJson .= '<table>';
        foreach ($data as $k => $datum) {
            $encodedJson .= '<tr>';
            $encodedJson .= '<td>';
            $encodedJson .= $k;
            $encodedJson .= '</td>';
            $encodedJson .= '<td>';
            $encodedJson .= $datum;
            $encodedJson .= '</td>';
            $encodedJson .= '</tr>';
        }
        $encodedJson .= '</table>';

        $encodedJson = sprintf('<html><body>%s</body></html>', $encodedJson);

        return $encodedJson;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsEncoding($format): bool
    {
        return 'html' === $format;
    }
}
