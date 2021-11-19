<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\DataContainer;

use Contao\ArrayUtil;
use Contao\Backend;
use Contao\BackendTemplate;
use Contao\Config;
use Contao\Controller;
use Contao\CoreBundle\Exception\AccessDeniedException;
use Contao\CoreBundle\Exception\ResponseException;
use Contao\CoreBundle\Picker\DcaPickerProviderInterface;
use Contao\CoreBundle\Picker\PickerInterface;
use Contao\Database;
use Contao\Date;
use Contao\File;
use Contao\FilesModel;
use Contao\Image;
use Contao\Image\ResizeConfiguration;
use Contao\Input;
use Contao\Message;
use Contao\Model;
use Contao\Pagination;
use Contao\RequestToken;
use Contao\StringUtil;
use Contao\System;
use Contao\Versions;
use Contao\Widget;
use Doctrine\DBAL\Connection;
use FOS\HttpCacheBundle\CacheManager;
use Imagine\Gd\Imagine;
use Patchwork\Utf8;
use Symfony\Component\HttpFoundation\Session\Attribute\AttributeBagInterface;

/**
 * The controller for all backend modules that use a Data Container.
 */
class DataContainer extends \Contao\DataContainer implements \listable, \editable
{
    private Connection $connection;

    /**
     * Name attribute of the current input field.
     *
     * @var string
     */
    protected $strInputName;

    /**
     * Value of the current field.
     *
     * @var mixed
     */
    protected $varValue;

    /**
     * Name of the current palette.
     *
     * @var string
     */
    protected $strPalette;

    /**
     * IDs of all root records.
     *
     * @var array
     */
    protected $root;

    /**
     * WHERE clause of the database query.
     *
     * @var array
     */
    protected $procedure = [];

    /**
     * Values for the WHERE clause of the database query.
     *
     * @var array
     */
    protected $values = [];

    /**
     * Form attribute "onsubmit".
     *
     * @var array
     */
    protected $onsubmit = [];

    /**
     * Reload the page after the form has been submitted.
     *
     * @var bool
     */
    protected $noReload = false;

    /**
     * Active record.
     *
     * @var Model|FilesModel
     */
    protected $objActiveRecord;

    /**
     * True if one of the form fields is uploadable.
     *
     * @var bool
     */
    protected $blnUploadable = false;

    /**
     * DCA Picker instance.
     *
     * @var PickerInterface
     */
    protected $objPicker;

    /**
     * Callback to convert DCA value to picker value.
     *
     * @var callable
     */
    protected $objPickerCallback;

    /**
     * The picker value.
     *
     * @var array
     */
    protected $arrPickerValue = [];

    /**
     * The picker field type.
     *
     * @var string
     */
    protected $strPickerFieldType;

    /**
     * True if a new version has to be created.
     *
     * @var bool
     */
    protected $blnCreateNewVersion = false;

    /**
     * Name of the parent table.
     *
     * @var string
     */
    protected $ptable;

    /**
     * Names of the child tables.
     *
     * @var array
     */
    protected $ctable;

    /**
     * Limit (database query).
     *
     * @var string
     */
    protected $limit = '';

    /**
     * Total (database query).
     *
     * @var string
     */
    protected $total;

    /**
     * First sorting field.
     *
     * @var string
     */
    protected $firstOrderBy;

    /**
     * Order by (database query).
     *
     * @var array
     */
    protected $orderBy = [];

    /**
     * Fields of a new or duplicated record.
     *
     * @var array
     */
    protected $set = [];

    /**
     * IDs of all records that are currently displayed.
     *
     * @var array
     */
    protected $current = [];

    /**
     * Show the current table as tree.
     *
     * @var bool
     */
    protected $treeView = false;

    /**
     * The current back end module.
     *
     * @var array
     */
    protected $arrModule = [];

    /**
     * Preserve this record when revising tables.
     *
     * @var int
     */
    protected $intPreserveRecord;

    /**
     * Initialize the object.
     *
     * @param string $strTable
     * @param array  $arrModule
     */
    public function __construct($strTable, $arrModule = [])
    {
        $this->connection = System::getContainer()->get('database_connection');

        /** @var Session $objSession */
        $objSession = System::getContainer()->get('session');

        // Check the request token (see #4007)
        if (isset($_GET['act'])) {
            if (!isset($_GET['rt']) || !RequestToken::validate(Input::get('rt'))) {
                $objSession->set('INVALID_TOKEN_URL', Environment::get('request'));
                $this->redirect('contao/confirm.php');
            }
        }

        $this->intId = Input::get('id');

        // Clear the clipboard
        if (isset($_GET['clipboard'])) {
            $objSession->set('CLIPBOARD', []);
            $this->redirect(Backend::getReferer());
        }

        // Check whether the table is defined
        if (!$strTable || !isset($GLOBALS['TL_DCA'][$strTable])) {
            System::log('Could not load the data container configuration for "'.$strTable.'"', __METHOD__, TL_ERROR);
            trigger_error('Could not load the data container configuration', E_USER_ERROR);
        }

        // Set IDs and redirect
        if ('tl_select' === Input::post('FORM_SUBMIT')) {
            $ids = Input::post('IDS');

            if (empty($ids) || !\is_array($ids)) {
                $this->reload();
            }

            $session = $objSession->all();
            $session['CURRENT']['IDS'] = $ids;
            $objSession->replace($session);

            if (isset($_POST['edit'])) {
                $this->redirect(str_replace('act=select', 'act=editAll', Environment::get('request')));
            } elseif (isset($_POST['delete'])) {
                $this->redirect(str_replace('act=select', 'act=deleteAll', Environment::get('request')));
            } elseif (isset($_POST['override'])) {
                $this->redirect(str_replace('act=select', 'act=overrideAll', Environment::get('request')));
            } elseif (isset($_POST['cut']) || isset($_POST['copy'])) {
                $arrClipboard = $objSession->get('CLIPBOARD');

                $arrClipboard[$strTable] = [
                    'id' => $ids,
                    'mode' => (isset($_POST['cut']) ? 'cutAll' : 'copyAll'),
                ];

                $objSession->set('CLIPBOARD', $arrClipboard);

                // Support copyAll in the list view (see #7499)
                if (isset($_POST['copy']) && ($GLOBALS['TL_DCA'][$strTable]['list']['sorting']['mode'] ?? 0) < 4) {
                    $this->redirect(str_replace('act=select', 'act=copyAll', Environment::get('request')));
                }

                $this->redirect(Backend::getReferer());
            }
        }

        $this->strTable = $strTable;
        $this->ptable = $GLOBALS['TL_DCA'][$this->strTable]['config']['ptable'] ?? null;
        $this->ctable = $GLOBALS['TL_DCA'][$this->strTable]['config']['ctable'] ?? null;
        $this->treeView = \in_array($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null, [5, 6], true);
        $this->root = null;
        $this->arrModule = $arrModule;

        // Call onload_callback (e.g. to check permissions)
        if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['config']['onload_callback'] ?? null)) {
            foreach ($GLOBALS['TL_DCA'][$this->strTable]['config']['onload_callback'] as $callback) {
                if (\is_array($callback)) {
                    System::importStatic($callback[0])->{$callback[1]}($this);
                } elseif (\is_callable($callback)) {
                    $callback($this);
                }
            }
        }

        // Get the IDs of all root records (tree view)
        if ($this->treeView) {
            $table = ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) === 6 ? $this->ptable : $this->strTable;

            // Unless there are any root records specified, use all records with parent ID 0
            if (!isset($GLOBALS['TL_DCA'][$table]['list']['sorting']['root']) || false === $GLOBALS['TL_DCA'][$table]['list']['sorting']['root']) {
                $objIds = Database::getInstance()->prepare('SELECT id FROM '.$table.' WHERE pid=?'.(Database::getInstance()->fieldExists('sorting', $table) ? ' ORDER BY sorting' : ''))
                    ->execute(0)
                ;

                if ($objIds->numRows > 0) {
                    $this->root = $objIds->fetchEach('id');
                }
            }

            // Get root records from global configuration file
            elseif (\is_array($GLOBALS['TL_DCA'][$table]['list']['sorting']['root'] ?? null)) {
                if ($GLOBALS['TL_DCA'][$table]['list']['sorting']['root'] === [0]) {
                    $this->root = [0];
                } else {
                    $this->root = $this->eliminateNestedPages($GLOBALS['TL_DCA'][$table]['list']['sorting']['root'], $table, Database::getInstance()->fieldExists('sorting', $table));
                }
            }
        }

        // Get the IDs of all root records (list view or parent view)
        elseif (\is_array($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['root'] ?? null)) {
            $this->root = array_unique($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['root']);
        }

        $request = System::getContainer()->get('request_stack')->getCurrentRequest();
        $route = $request->attributes->get('_route');

        // Store the current referer
        if (!empty($this->ctable) && 'contao_backend' === $route && !Input::get('act') && !Input::get('key') && !Input::get('token') && !Environment::get('isAjaxRequest')) {
            $strKey = Input::get('popup') ? 'popupReferer' : 'referer';
            $strRefererId = System::getContainer()->get('request_stack')->getCurrentRequest()->attributes->get('_contao_referer_id');

            $session = $objSession->get($strKey);
            $session[$strRefererId][$this->strTable] = substr(Environment::get('requestUri'), \strlen(Environment::get('path')) + 1);
            $objSession->set($strKey, $session);
        }
    }

    /**
     * Return an object property.
     *
     * @param string $strKey
     *
     * @return mixed
     */
    public function __get($strKey)
    {
        switch ($strKey) {
            case 'parentTable':
                return $this->ptable;

            case 'childTable':
                return $this->ctable;

            case 'rootIds':
                return $this->root;
        }

        switch ($strKey) {
            case 'id':
                return $this->intId;

            case 'table':
                return $this->strTable;

            case 'value':
                return $this->varValue;

            case 'field':
                return $this->strField;

            case 'inputName':
                return $this->strInputName;

            case 'palette':
                return $this->strPalette;

            case 'activeRecord':
                return $this->objActiveRecord;

            case 'createNewVersion':
                return $this->blnCreateNewVersion;
        }
    }

    /**
     * Set an object property.
     *
     * @param string $strKey
     * @param mixed  $varValue
     */
    public function __set($strKey, $varValue): void
    {
        switch ($strKey) {
            case 'activeRecord':
                $this->objActiveRecord = $varValue;
                break;

            case 'createNewVersion':
                $this->blnCreateNewVersion = (bool) $varValue;
                break;

            case 'id':
                $this->intId = $varValue;
                break;

            default:
                $this->$strKey = $varValue; // backwards compatibility
                break;
        }
    }

    /**
     * List all records of a particular table.
     *
     * @return string
     */
    public function showAll()
    {
        $return = '';
        $this->limit = '';

        /** @var Session $objSession */
        $objSession = System::getContainer()->get('session');

        $this->reviseTable();

        // Add to clipboard
        if ('paste' === Input::get('act')) {
            $arrClipboard = $objSession->get('CLIPBOARD');

            $arrClipboard[$this->strTable] = [
                'id' => Input::get('id'),
                'childs' => Input::get('childs'),
                'mode' => Input::get('mode'),
            ];

            $objSession->set('CLIPBOARD', $arrClipboard);
        }

        // Custom filter
        if (!empty($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['filter']) && \is_array($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['filter'])) {
            foreach ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['filter'] as $filter) {
                if (\is_string($filter)) {
                    $this->procedure[] = $filter;
                } else {
                    $this->procedure[] = $filter[0];
                    $this->values[] = $filter[1];
                }
            }
        }

        // Render view
        if ($this->treeView) {
            $return .= $this->panel();
            $return .= $this->treeView();
        } else {
            if ($this->ptable && Input::get('table') && Database::getInstance()->fieldExists('pid', $this->strTable)) {
                $this->procedure[] = 'pid=?';
                $this->values[] = CURRENT_ID;
            }

            $return .= $this->panel();
            $return .= ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) === 4 ? $this->parentView() : $this->listView();
        }

        return $return;
    }

    /**
     * Return all non-excluded fields of a record as HTML table.
     *
     * @return string
     */
    public function show()
    {
        if (!$this->intId) {
            return '';
        }

        $objRow = Database::getInstance()->prepare('SELECT * FROM '.$this->strTable.' WHERE id=?')
            ->limit(1)
            ->execute($this->intId)
        ;

        if ($objRow->numRows < 1) {
            return '';
        }

        $data = [];
        $row = $objRow->row();

        // Get the order fields
        $objDcaExtractor = DcaExtractor::getInstance($this->strTable);
        $arrOrder = $objDcaExtractor->getOrderFields();

        // Get all fields
        $fields = array_keys($row);
        $allowedFields = ['id', 'pid', 'sorting', 'tstamp'];

        if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'] ?? null)) {
            $allowedFields = array_unique(array_merge($allowedFields, array_keys($GLOBALS['TL_DCA'][$this->strTable]['fields'])));
        }

        // Use the field order of the DCA file
        $fields = array_intersect($allowedFields, $fields);

        // Show all allowed fields
        foreach ($fields as $i) {
            if (($GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['inputType'] ?? null) === 'password' || ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['eval']['doNotShow'] ?? null) || ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['eval']['hideInput'] ?? null) || !\in_array($i, $allowedFields, true)) {
                continue;
            }

            // Special treatment for table tl_undo
            if ('tl_undo' === $this->strTable && 'data' === $i) {
                continue;
            }

            $value = StringUtil::deserialize($row[$i]);

            // Decrypt the value
            if ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['eval']['encrypt'] ?? null) {
                $value = Encryption::decrypt($value);
            }

            // Get the field value
            if (isset($GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['foreignKey'])) {
                $temp = [];
                $chunks = explode('.', $GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['foreignKey'], 2);

                foreach ((array) $value as $v) {
                    $objKey = Database::getInstance()->prepare('SELECT '.Database::quoteIdentifier($chunks[1]).' AS value FROM '.$chunks[0].' WHERE id=?')
                        ->limit(1)
                        ->execute($v)
                    ;

                    if ($objKey->numRows) {
                        $temp[] = $objKey->value;
                    }
                }

                $row[$i] = implode(', ', $temp);
            } elseif (($GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['inputType'] ?? null) === 'fileTree' || \in_array($i, $arrOrder, true)) {
                if (\is_array($value)) {
                    foreach ($value as $kk => $vv) {
                        if (($objFile = FilesModel::findByUuid($vv)) instanceof FilesModel) {
                            $value[$kk] = $objFile->path.' ('.StringUtil::binToUuid($vv).')';
                        } else {
                            $value[$kk] = '';
                        }
                    }

                    $row[$i] = implode(', ', $value);
                } elseif (($objFile = FilesModel::findByUuid($value)) instanceof FilesModel) {
                    $row[$i] = $objFile->path.' ('.StringUtil::binToUuid($value).')';
                } else {
                    $row[$i] = '';
                }
            } elseif (\is_array($value)) {
                if (isset($value['value'], $value['unit']) && 2 === \count($value)) {
                    $row[$i] = trim($value['value'].', '.$value['unit']);
                } else {
                    foreach ($value as $kk => $vv) {
                        if (\is_array($vv)) {
                            $vals = array_values($vv);
                            $value[$kk] = array_shift($vals).' ('.implode(', ', array_filter($vals)).')';
                        }
                    }

                    if (ArrayUtil::isAssoc($value)) {
                        foreach ($value as $kk => $vv) {
                            $value[$kk] = $kk.': '.$vv;
                        }
                    }

                    $row[$i] = implode(', ', $value);
                }
            } elseif (($GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['eval']['rgxp'] ?? null) === 'date') {
                $row[$i] = $value ? Date::parse(Config::get('dateFormat'), $value) : '-';
            } elseif (($GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['eval']['rgxp'] ?? null) === 'time') {
                $row[$i] = $value ? Date::parse(Config::get('timeFormat'), $value) : '-';
            } elseif ('tstamp' === $i || ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['eval']['rgxp'] ?? null) === 'datim' || \in_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['flag'] ?? null, [5, 6, 7, 8, 9, 10], true)) {
                $row[$i] = $value ? Date::parse(Config::get('datimFormat'), $value) : '-';
            } elseif (($GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['eval']['isBoolean'] ?? null) || (($GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['inputType'] ?? null) === 'checkbox' && !($GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['eval']['multiple'] ?? null))) {
                $row[$i] = $value ? $GLOBALS['TL_LANG']['MSC']['yes'] : $GLOBALS['TL_LANG']['MSC']['no'];
            } elseif (($GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['eval']['rgxp'] ?? null) === 'email') {
                $row[$i] = Idna::decodeEmail($value);
            } elseif (($GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['inputType'] ?? null) === 'textarea' && (($GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['eval']['allowHtml'] ?? null) || ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['eval']['preserveTags'] ?? null))) {
                $row[$i] = StringUtil::specialchars($value);
            } elseif (\is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['reference'] ?? null)) {
                $row[$i] = isset($GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['reference'][$row[$i]]) ? (\is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['reference'][$row[$i]]) ? $GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['reference'][$row[$i]][0] : $GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['reference'][$row[$i]]) : $row[$i];
            } elseif (($GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['eval']['isAssociative'] ?? null) || ArrayUtil::isAssoc($GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['options'] ?? null)) {
                $row[$i] = $GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['options'][$row[$i]] ?? null;
            } else {
                $row[$i] = $value;
            }

            $label = null;

            // Label
            if (isset($GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['label'])) {
                $label = \is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['label']) ? $GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['label'][0] : $GLOBALS['TL_DCA'][$this->strTable]['fields'][$i]['label'];
            } elseif (isset($GLOBALS['TL_LANG']['MSC'][$i])) {
                $label = \is_array($GLOBALS['TL_LANG']['MSC'][$i]) ? $GLOBALS['TL_LANG']['MSC'][$i][0] : $GLOBALS['TL_LANG']['MSC'][$i];
            }

            if (!$label) {
                $label = '-';
            }

            $label .= ' <small>'.$i.'</small>';

            $data[$this->strTable][0][$label] = $row[$i];
        }

        // Call onshow_callback
        if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['config']['onshow_callback'] ?? null)) {
            foreach ($GLOBALS['TL_DCA'][$this->strTable]['config']['onshow_callback'] as $callback) {
                if (\is_array($callback)) {
                    $this->import($callback[0]);
                    $data = $this->{$callback[0]}->{$callback[1]}($data, $objRow->row(), $this);
                } elseif (\is_callable($callback)) {
                    $data = $callback($data, $objRow->row(), $this);
                }
            }
        }

        $separate = false;
        $return = '<table class="tl_show">';

        // Generate table
        foreach ($data as $table => $rows) {
            foreach ($rows as $entries) {
                // Separate multiple rows
                if ($separate) {
                    $return .= '
  <tr>
    <td colspan="2" style="height:1em"></td>
   </tr>';
                }

                $separate = true;

                // Add the table name
                $return .= '
  <tr>
    <td class="tl_folder_top tl_label">'.$GLOBALS['TL_LANG']['MSC']['table'].'</td>
    <td class="tl_folder_top">'.$table.'</td>
  </tr>
';

                foreach ($entries as $lbl => $val) {
                    // Always encode special characters (thanks to Oliver Klee)
                    $return .= '
	  <tr>
		<td class="tl_label">'.$lbl.'</td>
		<td>'.StringUtil::specialchars($val).'</td>
	  </tr>';
                }
            }
        }

        // Return table
        return $return.'</table>';
    }

    /**
     * Insert a new row into a database table.
     *
     * @param array $set
     *
     * @throws InternalServerErrorException
     */
    public function create($set = []): void
    {
        if ($GLOBALS['TL_DCA'][$this->strTable]['config']['notCreatable'] ?? null) {
            throw new InternalServerErrorException('Table "'.$this->strTable.'" is not creatable.');
        }

        // Get all default values for the new entry
        foreach ($GLOBALS['TL_DCA'][$this->strTable]['fields'] as $k => $v) {
            // Use array_key_exists here (see #5252)
            if (\array_key_exists('default', $v)) {
                $this->set[$k] = \is_array($v['default']) ? serialize($v['default']) : $v['default'];

                // Encrypt the default value (see #3740)
                if ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$k]['eval']['encrypt'] ?? null) {
                    $this->set[$k] = Encryption::encrypt($this->set[$k]);
                }
            }
        }

        // Set passed values
        if (!empty($set) && \is_array($set)) {
            $this->set = array_merge($this->set, $set);
        }

        // Get the new position
        $this->getNewPosition('new', Input::get('pid'), '2' === Input::get('mode'));

        // Dynamically set the parent table
        if ($GLOBALS['TL_DCA'][$this->strTable]['config']['dynamicPtable'] ?? null) {
            $this->set['ptable'] = $this->ptable;
        }

        /** @var Session $objSession */
        $objSession = System::getContainer()->get('session');

        // Empty the clipboard
        $arrClipboard = $objSession->get('CLIPBOARD');
        $arrClipboard[$this->strTable] = [];
        $objSession->set('CLIPBOARD', $arrClipboard);

        // Insert the record if the table is not closed and switch to edit mode
        if (!($GLOBALS['TL_DCA'][$this->strTable]['config']['closed'] ?? null)) {
            $this->set['tstamp'] = 0;

            $objInsertStmt = Database::getInstance()->prepare('INSERT INTO '.$this->strTable.' %s')
                ->set($this->set)
                ->execute()
            ;

            if ($objInsertStmt->affectedRows) {
                $s2e = $GLOBALS['TL_DCA'][$this->strTable]['config']['switchToEdit'] ?? null ? '&s2e=1' : '';
                $insertID = $objInsertStmt->insertId;

                /** @var AttributeBagInterface $objSessionBag */
                $objSessionBag = $objSession->getBag('contao_backend');

                // Save new record in the session
                $new_records = $objSessionBag->get('new_records');
                $new_records[$this->strTable][] = $insertID;
                $objSessionBag->set('new_records', $new_records);

                // Call the oncreate_callback
                if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['config']['oncreate_callback'] ?? null)) {
                    foreach ($GLOBALS['TL_DCA'][$this->strTable]['config']['oncreate_callback'] as $callback) {
                        if (\is_array($callback)) {
                            System::importStatic($callback[0])->{$callback[0]}->{$callback[1]}($this->strTable, $insertID, $this->set, $this);
                        } elseif (\is_callable($callback)) {
                            $callback($this->strTable, $insertID, $this->set, $this);
                        }
                    }
                }

                // Add a log entry
                System::log('A new entry "'.$this->strTable.'.id='.$insertID.'" has been created'.$this->getParentEntries($this->strTable, $insertID), __METHOD__, TL_GENERAL);
                $this->redirect($this->switchToEdit($insertID).$s2e);
            }
        }

        $this->redirect(Backend::getReferer());
    }

    /**
     * Assign a new position to an existing record.
     *
     * @param bool $blnDoNotRedirect
     *
     * @throws InternalServerErrorException
     */
    public function cut($blnDoNotRedirect = false): void
    {
        if ($GLOBALS['TL_DCA'][$this->strTable]['config']['notSortable'] ?? null) {
            throw new InternalServerErrorException('Table "'.$this->strTable.'" is not sortable.');
        }

        $cr = [];

        // ID and PID are mandatory (PID can be 0!)
        if (!$this->intId || !isset($_GET['pid'])) {
            $this->redirect(Backend::getReferer());
        }

        // Get the new position
        $this->getNewPosition('cut', Input::get('pid'), '2' === Input::get('mode'));

        // Avoid circular references when there is no parent table
        if (!$this->ptable && Database::getInstance()->fieldExists('pid', $this->strTable)) {
            $cr = Database::getInstance()->getChildRecords($this->intId, $this->strTable);
            $cr[] = $this->intId;
        }

        /** @var Session $objSession */
        $objSession = System::getContainer()->get('session');

        // Empty clipboard
        $arrClipboard = $objSession->get('CLIPBOARD');
        $arrClipboard[$this->strTable] = [];
        $objSession->set('CLIPBOARD', $arrClipboard);

        // Check for circular references
        if (\in_array($this->set['pid'], $cr, true)) {
            throw new InternalServerErrorException('Attempt to relate record '.$this->intId.' of table "'.$this->strTable.'" to its child record '.Input::get('pid').' (circular reference).');
        }

        $this->set['tstamp'] = time();

        // HOOK: style sheet category
        if ('tl_style' === $this->strTable) {
            /** @var AttributeBagInterface $objSessionBag */
            $objSessionBag = $objSession->getBag('contao_backend');

            $filter = $objSessionBag->get('filter');
            $category = $filter['tl_style_'.CURRENT_ID]['category'];

            if ($category) {
                $this->set['category'] = $category;
            }
        }

        // Dynamically set the parent table of tl_content
        if ($GLOBALS['TL_DCA'][$this->strTable]['config']['dynamicPtable'] ?? null) {
            $this->set['ptable'] = $this->ptable;
        }

        Database::getInstance()->prepare('UPDATE '.$this->strTable.' %s WHERE id=?')
            ->set($this->set)
            ->execute($this->intId)
        ;

        // Call the oncut_callback
        if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['config']['oncut_callback'] ?? null)) {
            foreach ($GLOBALS['TL_DCA'][$this->strTable]['config']['oncut_callback'] as $callback) {
                if (\is_array($callback)) {
                    $this->import($callback[0]);
                    $this->{$callback[0]}->{$callback[1]}($this);
                } elseif (\is_callable($callback)) {
                    $callback($this);
                }
            }
        }

        if (!$blnDoNotRedirect) {
            $this->redirect(Backend::getReferer());
        }
    }

    /**
     * Move all selected records.
     *
     * @throws InternalServerErrorException
     */
    public function cutAll(): void
    {
        if ($GLOBALS['TL_DCA'][$this->strTable]['config']['notSortable'] ?? null) {
            throw new InternalServerErrorException('Table "'.$this->strTable.'" is not sortable.');
        }

        /** @var Session $objSession */
        $objSession = System::getContainer()->get('session');

        $arrClipboard = $objSession->get('CLIPBOARD');

        if (isset($arrClipboard[$this->strTable]) && \is_array($arrClipboard[$this->strTable]['id'])) {
            foreach ($arrClipboard[$this->strTable]['id'] as $id) {
                $this->intId = $id;
                $this->cut(true);
                Input::setGet('pid', $id);
                Input::setGet('mode', 1);
            }
        }

        $this->redirect(Backend::getReferer());
    }

    /**
     * Duplicate a particular record of the current table.
     *
     * @param bool $blnDoNotRedirect
     *
     * @throws InternalServerErrorException
     *
     * @return int|bool
     */
    public function copy($blnDoNotRedirect = false)
    {
        if ($GLOBALS['TL_DCA'][$this->strTable]['config']['notCopyable'] ?? null) {
            throw new InternalServerErrorException('Table "'.$this->strTable.'" is not copyable.');
        }

        if (!$this->intId) {
            $this->redirect(Backend::getReferer());
        }

        /** @var Session $objSession */
        $objSession = System::getContainer()->get('session');

        /** @var AttributeBagInterface $objSessionBag */
        $objSessionBag = $objSession->getBag('contao_backend');

        $objRow = Database::getInstance()->prepare('SELECT * FROM '.$this->strTable.' WHERE id=?')
            ->limit(1)
            ->execute($this->intId)
        ;

        // Copy the values if the record contains data
        if ($objRow->numRows) {
            foreach ($objRow->row() as $k => $v) {
                if (\array_key_exists($k, $GLOBALS['TL_DCA'][$this->strTable]['fields'] ?? [])) {
                    // Never copy passwords
                    if (($GLOBALS['TL_DCA'][$this->strTable]['fields'][$k]['inputType'] ?? null) === 'password') {
                        $v = Widget::getEmptyValueByFieldType($GLOBALS['TL_DCA'][$this->strTable]['fields'][$k]['sql'] ?? []);
                    }

                    // Empty unique fields or add a unique identifier in copyAll mode
                    elseif ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$k]['eval']['unique'] ?? null) {
                        $v = 'copyAll' === Input::get('act') && !($GLOBALS['TL_DCA'][$this->strTable]['fields'][$k]['eval']['doNotCopy'] ?? null) ? $v.'-'.substr(md5(uniqid(mt_rand(), true)), 0, 8) : Widget::getEmptyValueByFieldType($GLOBALS['TL_DCA'][$this->strTable]['fields'][$k]['sql'] ?? []);
                    }

                    // Reset doNotCopy and fallback fields to their default value
                    elseif (($GLOBALS['TL_DCA'][$this->strTable]['fields'][$k]['eval']['doNotCopy'] ?? null) || ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$k]['eval']['fallback'] ?? null)) {
                        $v = Widget::getEmptyValueByFieldType($GLOBALS['TL_DCA'][$this->strTable]['fields'][$k]['sql'] ?? []);

                        // Use array_key_exists to allow NULL (see #5252)
                        if (\array_key_exists('default', $GLOBALS['TL_DCA'][$this->strTable]['fields'][$k] ?? [])) {
                            $v = \is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$k]['default']) ? serialize($GLOBALS['TL_DCA'][$this->strTable]['fields'][$k]['default']) : $GLOBALS['TL_DCA'][$this->strTable]['fields'][$k]['default'];
                        }

                        // Encrypt the default value (see #3740)
                        if ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$k]['eval']['encrypt'] ?? null) {
                            $v = Encryption::encrypt($v);
                        }
                    }

                    $this->set[$k] = $v;
                }
            }

            // HOOK: style sheet category
            if ('tl_style' === $this->strTable) {
                $filter = $objSessionBag->get('filter');
                $category = $filter['tl_style_'.CURRENT_ID]['category'];

                if ($category) {
                    $this->set['category'] = $category;
                }
            }
        }

        // Get the new position
        $this->getNewPosition('copy', Input::get('pid'), '2' === Input::get('mode'));

        // Dynamically set the parent table of tl_content
        if ($GLOBALS['TL_DCA'][$this->strTable]['config']['dynamicPtable'] ?? null) {
            $this->set['ptable'] = $this->ptable;
        }

        // Empty clipboard
        $arrClipboard = $objSession->get('CLIPBOARD');
        $arrClipboard[$this->strTable] = [];
        $objSession->set('CLIPBOARD', $arrClipboard);

        // Insert the record if the table is not closed and switch to edit mode
        if (!($GLOBALS['TL_DCA'][$this->strTable]['config']['closed'] ?? null)) {
            $this->set['tstamp'] = ($blnDoNotRedirect ? time() : 0);

            // Mark the new record with "copy of" (see #586)
            if (isset($GLOBALS['TL_DCA'][$this->strTable]['config']['markAsCopy'])) {
                $strKey = $GLOBALS['TL_DCA'][$this->strTable]['config']['markAsCopy'];

                if (($GLOBALS['TL_DCA'][$this->strTable]['fields'][$strKey]['inputType'] ?? null) === 'inputUnit') {
                    $value = StringUtil::deserialize($this->set[$strKey]);

                    if (!empty($value['value'])) {
                        $value['value'] = sprintf($GLOBALS['TL_LANG']['MSC']['copyOf'], $value['value']);
                        $this->set[$strKey] = serialize($value);
                    }
                } elseif (!empty($this->set[$strKey])) {
                    $this->set[$strKey] = sprintf($GLOBALS['TL_LANG']['MSC']['copyOf'], $this->set[$strKey]);
                }
            }

            // Remove the ID field from the data array
            unset($this->set['id']);

            $objInsertStmt = Database::getInstance()->prepare('INSERT INTO '.$this->strTable.' %s')
                ->set($this->set)
                ->execute()
            ;

            if ($objInsertStmt->affectedRows) {
                $insertID = $objInsertStmt->insertId;

                // Save the new record in the session
                $new_records = $objSessionBag->get('new_records');
                $new_records[$this->strTable][] = $insertID;
                $objSessionBag->set('new_records', $new_records);

                // Duplicate the records of the child table
                $this->copyChilds($this->strTable, $insertID, $this->intId, $insertID);

                // Call the oncopy_callback after all new records have been created
                if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['config']['oncopy_callback'] ?? null)) {
                    foreach ($GLOBALS['TL_DCA'][$this->strTable]['config']['oncopy_callback'] as $callback) {
                        if (\is_array($callback)) {
                            $this->import($callback[0]);
                            $this->{$callback[0]}->{$callback[1]}($insertID, $this);
                        } elseif (\is_callable($callback)) {
                            $callback($insertID, $this);
                        }
                    }
                }

                // Add a log entry
                System::log('A new entry "'.$this->strTable.'.id='.$insertID.'" has been created by duplicating record "'.$this->strTable.'.id='.$this->intId.'"'.$this->getParentEntries($this->strTable, $insertID), __METHOD__, TL_GENERAL);

                // Switch to edit mode
                if (!$blnDoNotRedirect) {
                    $this->redirect($this->switchToEdit($insertID));
                }

                return $insertID;
            }
        }

        if (!$blnDoNotRedirect) {
            $this->redirect(Backend::getReferer());
        }

        return false;
    }

    /**
     * Move all selected records.
     *
     * @throws InternalServerErrorException
     */
    public function copyAll(): void
    {
        if ($GLOBALS['TL_DCA'][$this->strTable]['config']['notCopyable'] ?? null) {
            throw new InternalServerErrorException('Table "'.$this->strTable.'" is not copyable.');
        }

        /** @var Session $objSession */
        $objSession = System::getContainer()->get('session');

        $arrClipboard = $objSession->get('CLIPBOARD');

        if (isset($arrClipboard[$this->strTable]) && \is_array($arrClipboard[$this->strTable]['id'])) {
            foreach ($arrClipboard[$this->strTable]['id'] as $id) {
                $this->intId = $id;
                $id = $this->copy(true);
                Input::setGet('pid', $id);
                Input::setGet('mode', 1);
            }
        }

        $this->redirect(Backend::getReferer());
    }

    /**
     * Delete a record of the current table table and save it to tl_undo.
     *
     * @param bool $blnDoNotRedirect
     *
     * @throws InternalServerErrorException
     */
    public function delete($blnDoNotRedirect = false): void
    {
        if ($GLOBALS['TL_DCA'][$this->strTable]['config']['notDeletable'] ?? null) {
            throw new InternalServerErrorException('Table "'.$this->strTable.'" is not deletable.');
        }

        if (!$this->intId) {
            $this->redirect(Backend::getReferer());
        }

        $delete = [];

        // Do not save records from tl_undo itself
        if ('tl_undo' === $this->strTable) {
            Database::getInstance()->prepare('DELETE FROM '.$this->strTable.' WHERE id=?')
                ->limit(1)
                ->execute($this->intId)
            ;

            $this->redirect(Backend::getReferer());
        }

        // If there is a PID field but no parent table
        if (!$this->ptable && Database::getInstance()->fieldExists('pid', $this->strTable)) {
            $delete[$this->strTable] = Database::getInstance()->getChildRecords($this->intId, $this->strTable);
            array_unshift($delete[$this->strTable], $this->intId);
        } else {
            $delete[$this->strTable] = [$this->intId];
        }

        // Delete all child records if there is a child table
        if (!empty($this->ctable)) {
            foreach ($delete[$this->strTable] as $id) {
                $this->deleteChilds($this->strTable, $id, $delete);
            }
        }

        $affected = 0;
        $data = [];

        // Save each record of each table
        foreach ($delete as $table => $fields) {
            foreach ($fields as $k => $v) {
                $objSave = Database::getInstance()->prepare('SELECT * FROM '.$table.' WHERE id=?')
                    ->limit(1)
                    ->execute($v)
                ;

                if ($objSave->numRows) {
                    $data[$table][$k] = $objSave->row();

                    // Store the active record
                    if ($table === $this->strTable && $v === $this->intId) {
                        $this->objActiveRecord = $objSave;
                    }
                }

                ++$affected;
            }
        }

        $this->import(BackendUser::class, 'User');

        $objUndoStmt = Database::getInstance()->prepare('INSERT INTO tl_undo (pid, tstamp, fromTable, query, affectedRows, data) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute($this->User->id, time(), $this->strTable, 'DELETE FROM '.$this->strTable.' WHERE id='.$this->intId, $affected, serialize($data))
        ;

        // Delete the records
        if ($objUndoStmt->affectedRows) {
            $undoId = $objUndoStmt->insertId;

            // Call ondelete_callback
            if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['config']['ondelete_callback'] ?? null)) {
                foreach ($GLOBALS['TL_DCA'][$this->strTable]['config']['ondelete_callback'] as $callback) {
                    if (\is_array($callback)) {
                        $this->import($callback[0]);
                        $this->{$callback[0]}->{$callback[1]}($this, $undoId);
                    } elseif (\is_callable($callback)) {
                        $callback($this, $undoId);
                    }
                }
            }

            // Invalidate cache tags (no need to invalidate the parent)
            $this->invalidateCacheTags();

            // Delete the records
            foreach ($delete as $table => $fields) {
                foreach ($fields as $v) {
                    Database::getInstance()->prepare('DELETE FROM '.$table.' WHERE id=?')
                        ->limit(1)
                        ->execute($v)
                    ;
                }
            }

            // Add a log entry unless we are deleting from tl_log itself
            if ('tl_log' !== $this->strTable) {
                System::log('DELETE FROM '.$this->strTable.' WHERE id='.$data[$this->strTable][0]['id'], __METHOD__, TL_GENERAL);
            }
        }

        if (!$blnDoNotRedirect) {
            $this->redirect(Backend::getReferer());
        }
    }

    /**
     * Delete all selected records.
     *
     * @throws InternalServerErrorException
     */
    public function deleteAll(): void
    {
        if ($GLOBALS['TL_DCA'][$this->strTable]['config']['notDeletable'] ?? null) {
            throw new InternalServerErrorException('Table "'.$this->strTable.'" is not deletable.');
        }

        /** @var Session $objSession */
        $objSession = System::getContainer()->get('session');

        $session = $objSession->all();
        $ids = $session['CURRENT']['IDS'] ?? [];

        if (\is_array($ids) && \strlen($ids[0])) {
            foreach ($ids as $id) {
                $this->intId = $id;
                $this->delete(true);
            }
        }

        $this->redirect(Backend::getReferer());
    }

    /**
     * Recursively get all related table names and records.
     *
     * @param string $table
     * @param int    $id
     * @param array  $delete
     */
    public function deleteChilds($table, $id, &$delete): void
    {
        $cctable = [];
        $ctable = $GLOBALS['TL_DCA'][$table]['config']['ctable'] ?? [];

        if (empty($ctable) || !\is_array($ctable)) {
            return;
        }

        // Walk through each child table
        foreach ($ctable as $v) {
            $this->loadDataContainer($v);
            $cctable[$v] = $GLOBALS['TL_DCA'][$v]['config']['ctable'] ?? null;

            // Consider the dynamic parent table (see #4867)
            if ($GLOBALS['TL_DCA'][$v]['config']['dynamicPtable'] ?? null) {
                $ptable = $GLOBALS['TL_DCA'][$v]['config']['ptable'];
                $cond = 'tl_article' === $ptable ? "(ptable=? OR ptable='')" : 'ptable=?';

                $objDelete = Database::getInstance()->prepare("SELECT id FROM $v WHERE pid=? AND $cond")
                    ->execute($id, $ptable)
                ;
            } else {
                $objDelete = Database::getInstance()->prepare("SELECT id FROM $v WHERE pid=?")
                    ->execute($id)
                ;
            }

            if ($objDelete->numRows && !($GLOBALS['TL_DCA'][$v]['config']['doNotDeleteRecords'] ?? null) && \strlen($v)) {
                foreach ($objDelete->fetchAllAssoc() as $row) {
                    $delete[$v][] = $row['id'];

                    if (!empty($cctable[$v])) {
                        $this->deleteChilds($v, $row['id'], $delete);
                    }
                }
            }
        }
    }

    /**
     * Restore one or more deleted records.
     */
    public function undo(): void
    {
        $objRecords = Database::getInstance()->prepare('SELECT * FROM '.$this->strTable.' WHERE id=?')
            ->limit(1)
            ->execute($this->intId)
        ;

        // Check whether there is a record
        if ($objRecords->numRows < 1) {
            $this->redirect(Backend::getReferer());
        }

        $error = false;
        $query = $objRecords->query;
        $data = StringUtil::deserialize($objRecords->data);

        if (!\is_array($data)) {
            $this->redirect(Backend::getReferer());
        }

        $arrFields = [];

        // Restore the data
        foreach ($data as $table => $fields) {
            $this->loadDataContainer($table);

            // Get the currently available fields
            if (!isset($arrFields[$table])) {
                $arrFields[$table] = array_flip(Database::getInstance()->getFieldNames($table));
            }

            foreach ($fields as $row) {
                // Unset fields that no longer exist in the database
                $row = array_intersect_key($row, $arrFields[$table]);

                // Re-insert the data
                $objInsertStmt = Database::getInstance()->prepare('INSERT INTO '.$table.' %s')
                    ->set($row)
                    ->execute()
                ;

                // Do not delete record from tl_undo if there is an error
                if ($objInsertStmt->affectedRows < 1) {
                    $error = true;
                }

                // Trigger the undo_callback
                if (\is_array($GLOBALS['TL_DCA'][$table]['config']['onundo_callback'] ?? null)) {
                    foreach ($GLOBALS['TL_DCA'][$table]['config']['onundo_callback'] as $callback) {
                        if (\is_array($callback)) {
                            $this->import($callback[0]);
                            $this->{$callback[0]}->{$callback[1]}($table, $row, $this);
                        } elseif (\is_callable($callback)) {
                            $callback($table, $row, $this);
                        }
                    }
                }
            }
        }

        // Add log entry and delete record from tl_undo if there was no error
        if (!$error) {
            System::log('Undone '.$query, __METHOD__, TL_GENERAL);

            Database::getInstance()->prepare('DELETE FROM '.$this->strTable.' WHERE id=?')
                ->limit(1)
                ->execute($this->intId)
            ;
        }

        $this->invalidateCacheTags();

        $this->redirect(Backend::getReferer());
    }

    /**
     * Change the order of two neighbour database records.
     */
    public function move(): void
    {
        // Proceed only if all mandatory variables are set
        if ($this->intId && Input::get('sid') && (!($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['root'] ?? null) || !\in_array($this->intId, $this->root, true))) {
            $objRow = Database::getInstance()->prepare('SELECT * FROM '.$this->strTable.' WHERE id=? OR id=?')
                ->limit(2)
                ->execute($this->intId, Input::get('sid'))
            ;

            $row = $objRow->fetchAllAssoc();

            if ($row[0]['pid'] === $row[1]['pid']) {
                Database::getInstance()->prepare('UPDATE '.$this->strTable.' SET sorting=? WHERE id=?')
                    ->execute($row[0]['sorting'], $row[1]['id'])
                ;

                Database::getInstance()->prepare('UPDATE '.$this->strTable.' SET sorting=? WHERE id=?')
                    ->execute($row[1]['sorting'], $row[0]['id'])
                ;

                $this->invalidateCacheTags();
            }
        }

        $this->redirect(Backend::getReferer());
    }

    /**
     * Auto-generate a form to edit the current database record.
     *
     * @param int $intId
     * @param int $ajaxId
     *
     * @throws AccessDeniedException
     * @throws InternalServerErrorException
     *
     * @return string
     */
    public function edit($intId = null, $ajaxId = null)
    {
        if ($GLOBALS['TL_DCA'][$this->strTable]['config']['notEditable'] ?? null) {
            throw new InternalServerErrorException('Table "'.$this->strTable.'" is not editable.');
        }

        if ($intId) {
            $this->intId = $intId;
        }

        // Get the current record
        $objRow = Database::getInstance()->prepare('SELECT * FROM '.$this->strTable.' WHERE id=?')
            ->limit(1)
            ->execute($this->intId)
        ;

        // Redirect if there is no record with the given ID
        if ($objRow->numRows < 1) {
            throw new AccessDeniedException('Cannot load record "'.$this->strTable.'.id='.$this->intId.'".');
        }

        $this->objActiveRecord = $objRow;

        $return = '';
        $this->values[] = $this->intId;
        $this->procedure[] = 'id=?';

        $this->blnCreateNewVersion = false;
        $objVersions = new Versions($this->strTable, $this->intId);

        if (!($GLOBALS['TL_DCA'][$this->strTable]['config']['hideVersionMenu'] ?? null)) {
            // Compare versions
            if (Input::get('versions')) {
                $objVersions->compare();
            }

            // Restore a version
            if ('tl_version' === Input::post('FORM_SUBMIT') && Input::post('version')) {
                $objVersions->restore(Input::post('version'));

                $this->invalidateCacheTags();

                $this->reload();
            }
        }

        $objVersions->initialize();

        // Build an array from boxes and rows
        $this->strPalette = $this->getPalette();
        $boxes = StringUtil::trimsplit(';', $this->strPalette);
        $legends = [];

        if (!empty($boxes)) {
            foreach ($boxes as $k => $v) {
                $eCount = 1;
                $boxes[$k] = StringUtil::trimsplit(',', $v);

                foreach ($boxes[$k] as $kk => $vv) {
                    if (preg_match('/^\[.*]$/', $vv)) {
                        ++$eCount;
                        continue;
                    }

                    if (preg_match('/^{.*}$/', $vv)) {
                        $legends[$k] = substr($vv, 1, -1);
                        unset($boxes[$k][$kk]);
                    } elseif (!\is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$vv] ?? null) || ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$vv]['exclude'] ?? null)) {
                        unset($boxes[$k][$kk]);
                    }
                }

                // Unset a box if it does not contain any fields
                if (\count($boxes[$k]) < $eCount) {
                    unset($boxes[$k]);
                }
            }

            /** @var Session $objSessionBag */
            $objSessionBag = System::getContainer()->get('session')->getBag('contao_backend');

            $class = 'tl_tbox';
            $fs = $objSessionBag->get('fieldset_states');

            // Render boxes
            foreach ($boxes as $k => $v) {
                $arrAjax = [];
                $blnAjax = false;
                $key = '';
                $cls = '';
                $legend = '';

                if (isset($legends[$k])) {
                    [$key, $cls] = explode(':', $legends[$k]) + [null, null];

                    $legend = "\n".'<legend onclick="AjaxRequest.toggleFieldset(this,\''.$key.'\',\''.$this->strTable.'\')">'.($GLOBALS['TL_LANG'][$this->strTable][$key] ?? $key).'</legend>';
                }

                if (isset($fs[$this->strTable][$key])) {
                    $class .= ($fs[$this->strTable][$key] ? '' : ' collapsed');
                } else {
                    $class .= ($cls && $legend ? ' '.$cls : '');
                }

                $return .= "\n\n".'<fieldset'.($key ? ' id="pal_'.$key.'"' : '').' class="'.$class.($legend ? '' : ' nolegend').'">'.$legend;
                $thisId = '';

                // Build rows of the current box
                foreach ($v as $vv) {
                    if ('[EOF]' === $vv) {
                        if ($blnAjax && Environment::get('isAjaxRequest')) {
                            if ($ajaxId === $thisId) {
                                return $arrAjax[$thisId].'<input type="hidden" name="FORM_FIELDS[]" value="'.StringUtil::specialchars($this->strPalette).'">';
                            }

                            if (\count($arrAjax) > 1) {
                                $current = "\n".'<div id="'.$thisId.'" class="subpal cf">'.$arrAjax[$thisId].'</div>';
                                unset($arrAjax[$thisId]);
                                end($arrAjax);
                                $thisId = key($arrAjax);
                                $arrAjax[$thisId] .= $current;
                            }
                        }

                        $return .= "\n".'</div>';

                        continue;
                    }

                    if (preg_match('/^\[.*]$/', $vv)) {
                        $thisId = 'sub_'.substr($vv, 1, -1);
                        $arrAjax[$thisId] = '';
                        $blnAjax = $ajaxId === $thisId && Environment::get('isAjaxRequest') ? true : $blnAjax;
                        $return .= "\n".'<div id="'.$thisId.'" class="subpal cf">';

                        continue;
                    }

                    $this->strField = $vv;
                    $this->strInputName = $vv;
                    $this->varValue = $objRow->$vv;

                    // Convert CSV fields (see #2890)
                    if (($GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField]['eval']['multiple'] ?? null) && isset($GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField]['eval']['csv'])) {
                        $this->varValue = StringUtil::trimsplit($GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField]['eval']['csv'], $this->varValue);
                    }

                    // Call load_callback
                    if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField]['load_callback'] ?? null)) {
                        foreach ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField]['load_callback'] as $callback) {
                            if (\is_array($callback)) {
                                $this->import($callback[0]);
                                $this->varValue = $this->{$callback[0]}->{$callback[1]}($this->varValue, $this);
                            } elseif (\is_callable($callback)) {
                                $this->varValue = $callback($this->varValue, $this);
                            }
                        }
                    }

                    // Re-set the current value
                    $this->objActiveRecord->{$this->strField} = $this->varValue;

                    // Build the row and pass the current palette string (thanks to Tristan Lins)
                    $blnAjax ? $arrAjax[$thisId] .= $this->row($this->strPalette) : $return .= $this->row($this->strPalette);
                }

                $class = 'tl_box';
                $return .= "\n".'</fieldset>';
            }
        }

        // Versions overview
        if (($GLOBALS['TL_DCA'][$this->strTable]['config']['enableVersioning'] ?? null) && !($GLOBALS['TL_DCA'][$this->strTable]['config']['hideVersionMenu'] ?? null)) {
            $version = $objVersions->renderDropdown();
        } else {
            $version = '';
        }

        // Submit buttons
        $arrButtons = [];
        $arrButtons['save'] = '<button type="submit" name="save" id="save" class="tl_submit" accesskey="s">'.$GLOBALS['TL_LANG']['MSC']['save'].'</button>';

        if (!Input::get('nb')) {
            $arrButtons['saveNclose'] = '<button type="submit" name="saveNclose" id="saveNclose" class="tl_submit" accesskey="c">'.$GLOBALS['TL_LANG']['MSC']['saveNclose'].'</button>';

            if (!Input::get('nc')) {
                if (!($GLOBALS['TL_DCA'][$this->strTable]['config']['closed'] ?? null) && !($GLOBALS['TL_DCA'][$this->strTable]['config']['notCreatable'] ?? null)) {
                    $arrButtons['saveNcreate'] = '<button type="submit" name="saveNcreate" id="saveNcreate" class="tl_submit" accesskey="n">'.$GLOBALS['TL_LANG']['MSC']['saveNcreate'].'</button>';

                    if (!($GLOBALS['TL_DCA'][$this->strTable]['config']['notCopyable'] ?? null)) {
                        $arrButtons['saveNduplicate'] = '<button type="submit" name="saveNduplicate" id="saveNduplicate" class="tl_submit" accesskey="d">'.$GLOBALS['TL_LANG']['MSC']['saveNduplicate'].'</button>';
                    }
                }

                if ($GLOBALS['TL_DCA'][$this->strTable]['config']['switchToEdit'] ?? null) {
                    $arrButtons['saveNedit'] = '<button type="submit" name="saveNedit" id="saveNedit" class="tl_submit" accesskey="e">'.$GLOBALS['TL_LANG']['MSC']['saveNedit'].'</button>';
                }

                if ($this->ptable || ($GLOBALS['TL_DCA'][$this->strTable]['config']['switchToEdit'] ?? null) || ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) === 4) {
                    $arrButtons['saveNback'] = '<button type="submit" name="saveNback" id="saveNback" class="tl_submit" accesskey="g">'.$GLOBALS['TL_LANG']['MSC']['saveNback'].'</button>';
                }
            }
        }

        // Call the buttons_callback (see #4691)
        if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['edit']['buttons_callback'] ?? null)) {
            foreach ($GLOBALS['TL_DCA'][$this->strTable]['edit']['buttons_callback'] as $callback) {
                if (\is_array($callback)) {
                    $this->import($callback[0]);
                    $arrButtons = $this->{$callback[0]}->{$callback[1]}($arrButtons, $this);
                } elseif (\is_callable($callback)) {
                    $arrButtons = $callback($arrButtons, $this);
                }
            }
        }

        if (\count($arrButtons) < 3) {
            $strButtons = implode(' ', $arrButtons);
        } else {
            $strButtons = array_shift($arrButtons).' ';
            $strButtons .= '<div class="split-button">';
            $strButtons .= array_shift($arrButtons).'<button type="button" id="sbtog">'.Image::getHtml('navcol.svg').'</button> <ul class="invisible">';

            foreach ($arrButtons as $strButton) {
                $strButtons .= '<li>'.$strButton.'</li>';
            }

            $strButtons .= '</ul></div>';
        }

        // Add the buttons and end the form
        $return .= '
</div>
<div class="tl_formbody_submit">
<div class="tl_submit_container">
  '.$strButtons.'
</div>
</div>
</form>';

        // Always create a new version if something has changed, even if the form has errors (see #237)
        if ($this->noReload && $this->blnCreateNewVersion && Input::post('FORM_SUBMIT') === $this->strTable) {
            $objVersions->create();
        }

        $strVersionField = '';

        // Store the current version number (see #8412)
        if (($intLatestVersion = $objVersions->getLatestVersion()) !== null) {
            $strVersionField = '
<input type="hidden" name="VERSION_NUMBER" value="'.$intLatestVersion.'">';
        }

        // Begin the form (-> DO NOT CHANGE THIS ORDER -> this way the onsubmit attribute of the form can be changed by a field)
        $return = $version.Message::generate().($this->noReload ? '
<p class="tl_error">'.$GLOBALS['TL_LANG']['ERR']['general'].'</p>' : '').'
<div id="tl_buttons">'.(Input::get('nb') ? '&nbsp;' : '
<a href="'.Backend::getReferer(true).'" class="header_back" title="'.StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['backBTTitle']).'" accesskey="b" onclick="Backend.getScrollOffset()">'.$GLOBALS['TL_LANG']['MSC']['backBT'].'</a>').'
</div>
<form id="'.$this->strTable.'" class="tl_form tl_edit_form" method="post" enctype="'.($this->blnUploadable ? 'multipart/form-data' : 'application/x-www-form-urlencoded').'"'.(!empty($this->onsubmit) ? ' onsubmit="'.implode(' ', $this->onsubmit).'"' : '').'>
<div class="tl_formbody_edit">
<input type="hidden" name="FORM_SUBMIT" value="'.$this->strTable.'">
<input type="hidden" name="REQUEST_TOKEN" value="'.REQUEST_TOKEN.'">'.$strVersionField.'
<input type="hidden" name="FORM_FIELDS[]" value="'.StringUtil::specialchars($this->strPalette).'">'.$return;

        // Reload the page to prevent _POST variables from being sent twice
        if (!$this->noReload && Input::post('FORM_SUBMIT') === $this->strTable) {
            $arrValues = $this->values;
            array_unshift($arrValues, time());

            // Trigger the onsubmit_callback
            if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['config']['onsubmit_callback'] ?? null)) {
                foreach ($GLOBALS['TL_DCA'][$this->strTable]['config']['onsubmit_callback'] as $callback) {
                    if (\is_array($callback)) {
                        $this->import($callback[0]);
                        $this->{$callback[0]}->{$callback[1]}($this);
                    } elseif (\is_callable($callback)) {
                        $callback($this);
                    }
                }
            }

            // Set the current timestamp before adding a new version
            if ($GLOBALS['TL_DCA'][$this->strTable]['config']['dynamicPtable'] ?? null) {
                Database::getInstance()->prepare('UPDATE '.$this->strTable.' SET ptable=?, tstamp=? WHERE id=?')
                    ->execute($this->ptable, time(), $this->intId)
                ;
            } else {
                Database::getInstance()->prepare('UPDATE '.$this->strTable.' SET tstamp=? WHERE id=?')
                    ->execute(time(), $this->intId)
                ;
            }

            // Save the current version
            if ($this->blnCreateNewVersion) {
                $objVersions->create();

                // Call the onversion_callback
                if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['config']['onversion_callback'] ?? null)) {
                    trigger_deprecation('contao/core-bundle', '4.0', 'Using the "onversion_callback" has been deprecated and will no longer work in Contao 5.0. Use the "oncreate_version_callback" instead.');

                    foreach ($GLOBALS['TL_DCA'][$this->strTable]['config']['onversion_callback'] as $callback) {
                        if (\is_array($callback)) {
                            $this->import($callback[0]);
                            $this->{$callback[0]}->{$callback[1]}($this->strTable, $this->intId, $this);
                        } elseif (\is_callable($callback)) {
                            $callback($this->strTable, $this->intId, $this);
                        }
                    }
                }
            }

            // Show a warning if the record has been saved by another user (see #8412)
            if (null !== $intLatestVersion && isset($_POST['VERSION_NUMBER']) && $intLatestVersion > Input::post('VERSION_NUMBER')) {
                $objTemplate = new BackendTemplate('be_conflict');
                $objTemplate->language = $GLOBALS['TL_LANGUAGE'];
                $objTemplate->title = StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['versionConflict']);
                $objTemplate->theme = Backend::getTheme();
                $objTemplate->charset = System::getContainer()->getParameter('kernel.charset');
                $objTemplate->base = Environment::get('base');
                $objTemplate->h1 = $GLOBALS['TL_LANG']['MSC']['versionConflict'];
                $objTemplate->explain1 = sprintf($GLOBALS['TL_LANG']['MSC']['versionConflict1'], $intLatestVersion, Input::post('VERSION_NUMBER'));
                $objTemplate->explain2 = sprintf($GLOBALS['TL_LANG']['MSC']['versionConflict2'], $intLatestVersion + 1, $intLatestVersion);
                $objTemplate->diff = $objVersions->compare(true);
                $objTemplate->href = Environment::get('request');
                $objTemplate->button = $GLOBALS['TL_LANG']['MSC']['continue'];

                throw new ResponseException($objTemplate->getResponse());
            }

            $this->invalidateCacheTags();

            // Redirect
            if (isset($_POST['saveNclose'])) {
                Message::reset();

                $this->redirect(Backend::getReferer());
            } elseif (isset($_POST['saveNedit'])) {
                Message::reset();

                $this->redirect(Backend::addToUrl($GLOBALS['TL_DCA'][$this->strTable]['list']['operations']['edit']['href'] ?? '', false, ['s2e', 'act', 'mode', 'pid']));
            } elseif (isset($_POST['saveNback'])) {
                Message::reset();

                if (!$this->ptable) {
                    $this->redirect(TL_SCRIPT.'?do='.Input::get('do'));
                }
                // TODO: try to abstract this
                elseif (('tl_theme' === $this->ptable && 'tl_style_sheet' === $this->strTable) || ('tl_page' === $this->ptable && 'tl_article' === $this->strTable)) {
                    $this->redirect(Backend::getReferer(false, $this->strTable));
                } else {
                    $this->redirect(Backend::getReferer(false, $this->ptable));
                }
            } elseif (isset($_POST['saveNcreate'])) {
                Message::reset();

                $strUrl = TL_SCRIPT.'?do='.Input::get('do');

                if (isset($_GET['table'])) {
                    $strUrl .= '&amp;table='.Input::get('table');
                }

                // Tree view
                if ($this->treeView) {
                    $strUrl .= '&amp;act=create&amp;mode=1&amp;pid='.$this->intId;
                }

                // Parent view
                elseif (($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) === 4) {
                    $strUrl .= Database::getInstance()->fieldExists('sorting', $this->strTable) ? '&amp;act=create&amp;mode=1&amp;pid='.$this->intId : '&amp;act=create&amp;mode=2&amp;pid='.$this->activeRecord->pid;
                }

                // List view
                else {
                    $strUrl .= $this->ptable ? '&amp;act=create&amp;mode=2&amp;pid='.CURRENT_ID : '&amp;act=create';
                }

                $this->redirect($strUrl.'&amp;rt='.REQUEST_TOKEN);
            } elseif (isset($_POST['saveNduplicate'])) {
                Message::reset();

                $strUrl = TL_SCRIPT.'?do='.Input::get('do');

                if (isset($_GET['table'])) {
                    $strUrl .= '&amp;table='.Input::get('table');
                }

                // Tree view
                if ($this->treeView) {
                    $strUrl .= '&amp;act=copy&amp;mode=1&amp;id='.$this->intId.'&amp;pid='.$this->intId;
                }

                // Parent view
                elseif (($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) === 4) {
                    $strUrl .= Database::getInstance()->fieldExists('sorting', $this->strTable) ? '&amp;act=copy&amp;mode=1&amp;pid='.$this->intId.'&amp;id='.$this->intId : '&amp;act=copy&amp;mode=2&amp;pid='.CURRENT_ID.'&amp;id='.$this->intId;
                }

                // List view
                else {
                    $strUrl .= $this->ptable ? '&amp;act=copy&amp;mode=2&amp;pid='.CURRENT_ID.'&amp;id='.CURRENT_ID : '&amp;act=copy&amp;id='.CURRENT_ID;
                }

                $this->redirect($strUrl.'&amp;rt='.REQUEST_TOKEN);
            }

            $this->reload();
        }

        // Set the focus if there is an error
        if ($this->noReload) {
            $return .= '
<script>
  window.addEvent(\'domready\', function() {
    Backend.vScrollTo(($(\''.$this->strTable.'\').getElement(\'label.error\').getPosition().y - 20));
  });
</script>';
        }

        return $return;
    }

    /**
     * Auto-generate a form to edit all records that are currently shown.
     *
     * @param int $intId
     * @param int $ajaxId
     *
     * @throws InternalServerErrorException
     *
     * @return string
     */
    public function editAll($intId = null, $ajaxId = null)
    {
        if ($GLOBALS['TL_DCA'][$this->strTable]['config']['notEditable'] ?? null) {
            throw new InternalServerErrorException('Table "'.$this->strTable.'" is not editable.');
        }

        $return = '';
        $this->import(BackendUser::class, 'User');

        /** @var Session $objSession */
        $objSession = System::getContainer()->get('session');

        // Get current IDs from session
        $session = $objSession->all();
        $ids = $session['CURRENT']['IDS'] ?? [];

        if ($intId && Environment::get('isAjaxRequest')) {
            $ids = [$intId];
        }

        // Save field selection in session
        if (Input::post('FORM_SUBMIT') === $this->strTable.'_all' && Input::get('fields')) {
            $session['CURRENT'][$this->strTable] = Input::post('all_fields');
            $objSession->replace($session);
        }

        // Add fields
        $fields = $session['CURRENT'][$this->strTable] ?? [];

        if (!empty($fields) && \is_array($fields) && Input::get('fields')) {
            $class = 'tl_tbox';

            // Walk through each record
            foreach ($ids as $id) {
                $this->intId = $id;
                $this->procedure = ['id=?'];
                $this->values = [$this->intId];
                $this->blnCreateNewVersion = false;
                $this->strPalette = StringUtil::trimsplit('[;,]', $this->getPalette());

                $objVersions = new Versions($this->strTable, $this->intId);
                $objVersions->initialize();

                // Add meta fields if the current user is an administrator
                if ($this->User->isAdmin) {
                    if (Database::getInstance()->fieldExists('sorting', $this->strTable)) {
                        array_unshift($this->strPalette, 'sorting');
                    }

                    if (Database::getInstance()->fieldExists('pid', $this->strTable)) {
                        array_unshift($this->strPalette, 'pid');
                    }

                    // Ensure a minimum configuration
                    foreach (['pid', 'sorting'] as $f) {
                        if (!isset($GLOBALS['TL_DCA'][$this->strTable]['fields'][$f]['label'])) {
                            $GLOBALS['TL_DCA'][$this->strTable]['fields'][$f]['label'] = &$GLOBALS['TL_LANG']['MSC'][$f];
                        }

                        if (!isset($GLOBALS['TL_DCA'][$this->strTable]['fields'][$f]['inputType'])) {
                            $GLOBALS['TL_DCA'][$this->strTable]['fields'][$f]['inputType'] = 'text';
                        }

                        if (!isset($GLOBALS['TL_DCA'][$this->strTable]['fields'][$f]['eval']['tl_class'])) {
                            $GLOBALS['TL_DCA'][$this->strTable]['fields'][$f]['eval']['tl_class'] = 'w50';
                        }

                        if (!isset($GLOBALS['TL_DCA'][$this->strTable]['fields'][$f]['eval']['rgxp'])) {
                            $GLOBALS['TL_DCA'][$this->strTable]['fields'][$f]['eval']['rgxp'] = 'natural';
                        }
                    }
                }

                // Begin current row
                $strAjax = '';
                $blnAjax = false;
                $return .= '
<div class="'.$class.' cf">';

                $class = 'tl_box';
                $formFields = [];

                // Get the field values
                $objRow = Database::getInstance()->prepare('SELECT * FROM '.$this->strTable.' WHERE id=?')
                    ->limit(1)
                    ->execute($this->intId)
                ;

                // Store the active record
                $this->objActiveRecord = $objRow;

                foreach ($this->strPalette as $v) {
                    // Check whether field is excluded
                    if ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$v]['exclude'] ?? null) {
                        continue;
                    }

                    if ('[EOF]' === $v) {
                        if ($blnAjax && Environment::get('isAjaxRequest')) {
                            return $strAjax.'<input type="hidden" name="FORM_FIELDS_'.$id.'[]" value="'.StringUtil::specialchars(implode(',', $formFields)).'">';
                        }

                        $blnAjax = false;
                        $return .= "\n  ".'</div>';

                        continue;
                    }

                    if (preg_match('/^\[.*]$/', $v)) {
                        $thisId = 'sub_'.substr($v, 1, -1).'_'.$id;
                        $blnAjax = ($ajaxId === $thisId && Environment::get('isAjaxRequest'));
                        $return .= "\n  ".'<div id="'.$thisId.'" class="subpal cf">';

                        continue;
                    }

                    if (!\in_array($v, $fields, true)) {
                        continue;
                    }

                    $this->strField = $v;
                    $this->strInputName = $v.'_'.$this->intId;
                    $formFields[] = $v.'_'.$this->intId;

                    // Set the default value and try to load the current value from DB (see #5252)
                    if (\array_key_exists('default', $GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField] ?? [])) {
                        $this->varValue = \is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField]['default']) ? serialize($GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField]['default']) : $GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField]['default'];
                    }

                    if ($objRow->$v !== false) {
                        $this->varValue = $objRow->$v;
                    }

                    // Convert CSV fields (see #2890)
                    if (($GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField]['eval']['multiple'] ?? null) && isset($GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField]['eval']['csv'])) {
                        $this->varValue = StringUtil::trimsplit($GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField]['eval']['csv'], $this->varValue);
                    }

                    // Call load_callback
                    if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField]['load_callback'] ?? null)) {
                        foreach ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField]['load_callback'] as $callback) {
                            if (\is_array($callback)) {
                                $this->import($callback[0]);
                                $this->varValue = $this->{$callback[0]}->{$callback[1]}($this->varValue, $this);
                            } elseif (\is_callable($callback)) {
                                $this->varValue = $callback($this->varValue, $this);
                            }
                        }
                    }

                    // Re-set the current value
                    $this->objActiveRecord->{$this->strField} = $this->varValue;

                    // Build the row and pass the current palette string (thanks to Tristan Lins)
                    $blnAjax ? $strAjax .= $this->row($this->strPalette) : $return .= $this->row($this->strPalette);
                }

                // Close box
                $return .= '
  <input type="hidden" name="FORM_FIELDS_'.$this->intId.'[]" value="'.StringUtil::specialchars(implode(',', $formFields)).'">
</div>';

                // Always create a new version if something has changed, even if the form has errors (see #237)
                if ($this->noReload && $this->blnCreateNewVersion && Input::post('FORM_SUBMIT') === $this->strTable) {
                    $objVersions->create();
                }

                // Save record
                if (!$this->noReload && Input::post('FORM_SUBMIT') === $this->strTable) {
                    // Call the onsubmit_callback
                    if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['config']['onsubmit_callback'] ?? null)) {
                        foreach ($GLOBALS['TL_DCA'][$this->strTable]['config']['onsubmit_callback'] as $callback) {
                            if (\is_array($callback)) {
                                $this->import($callback[0]);
                                $this->{$callback[0]}->{$callback[1]}($this);
                            } elseif (\is_callable($callback)) {
                                $callback($this);
                            }
                        }
                    }

                    // Set the current timestamp before adding a new version
                    if ($GLOBALS['TL_DCA'][$this->strTable]['config']['dynamicPtable'] ?? null) {
                        Database::getInstance()->prepare('UPDATE '.$this->strTable.' SET ptable=?, tstamp=? WHERE id=?')
                            ->execute($this->ptable, time(), $this->intId)
                        ;
                    } else {
                        Database::getInstance()->prepare('UPDATE '.$this->strTable.' SET tstamp=? WHERE id=?')
                            ->execute(time(), $this->intId)
                        ;
                    }

                    // Create a new version
                    if ($this->blnCreateNewVersion) {
                        $objVersions->create();

                        // Call the onversion_callback
                        if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['config']['onversion_callback'] ?? null)) {
                            trigger_deprecation('contao/core-bundle', '4.0', 'Using the "onversion_callback" has been deprecated and will no longer work in Contao 5.0. Use the "oncreate_version_callback" instead.');

                            foreach ($GLOBALS['TL_DCA'][$this->strTable]['config']['onversion_callback'] as $callback) {
                                if (\is_array($callback)) {
                                    $this->import($callback[0]);
                                    $this->{$callback[0]}->{$callback[1]}($this->strTable, $this->intId, $this);
                                } elseif (\is_callable($callback)) {
                                    $callback($this->strTable, $this->intId, $this);
                                }
                            }
                        }
                    }

                    $this->invalidateCacheTags();
                }
            }

            // Submit buttons
            $arrButtons = [];
            $arrButtons['save'] = '<button type="submit" name="save" id="save" class="tl_submit" accesskey="s">'.$GLOBALS['TL_LANG']['MSC']['save'].'</button>';
            $arrButtons['saveNclose'] = '<button type="submit" name="saveNclose" id="saveNclose" class="tl_submit" accesskey="c">'.$GLOBALS['TL_LANG']['MSC']['saveNclose'].'</button>';

            // Call the buttons_callback (see #4691)
            if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['edit']['buttons_callback'] ?? null)) {
                foreach ($GLOBALS['TL_DCA'][$this->strTable]['edit']['buttons_callback'] as $callback) {
                    if (\is_array($callback)) {
                        $this->import($callback[0]);
                        $arrButtons = $this->{$callback[0]}->{$callback[1]}($arrButtons, $this);
                    } elseif (\is_callable($callback)) {
                        $arrButtons = $callback($arrButtons, $this);
                    }
                }
            }

            if (\count($arrButtons) < 3) {
                $strButtons = implode(' ', $arrButtons);
            } else {
                $strButtons = array_shift($arrButtons).' ';
                $strButtons .= '<div class="split-button">';
                $strButtons .= array_shift($arrButtons).'<button type="button" id="sbtog">'.Image::getHtml('navcol.svg').'</button> <ul class="invisible">';

                foreach ($arrButtons as $strButton) {
                    $strButtons .= '<li>'.$strButton.'</li>';
                }

                $strButtons .= '</ul></div>';
            }

            // Add the form
            $return = '

<form id="'.$this->strTable.'" class="tl_form tl_edit_form" method="post" enctype="'.($this->blnUploadable ? 'multipart/form-data' : 'application/x-www-form-urlencoded').'">
<div class="tl_formbody_edit nogrid">
<input type="hidden" name="FORM_SUBMIT" value="'.$this->strTable.'">
<input type="hidden" name="REQUEST_TOKEN" value="'.REQUEST_TOKEN.'">'.($this->noReload ? '
<p class="tl_error">'.$GLOBALS['TL_LANG']['ERR']['general'].'</p>' : '').$return.'
</div>
<div class="tl_formbody_submit">
<div class="tl_submit_container">
  '.$strButtons.'
</div>
</div>
</form>';

            // Set the focus if there is an error
            if ($this->noReload) {
                $return .= '
<script>
  window.addEvent(\'domready\', function() {
    Backend.vScrollTo(($(\''.$this->strTable.'\').getElement(\'label.error\').getPosition().y - 20));
  });
</script>';
            }

            // Reload the page to prevent _POST variables from being sent twice
            if (!$this->noReload && Input::post('FORM_SUBMIT') === $this->strTable) {
                if (isset($_POST['saveNclose'])) {
                    $this->redirect(Backend::getReferer());
                }

                $this->reload();
            }
        }

        // Else show a form to select the fields
        else {
            $options = '';
            $fields = [];

            // Add fields of the current table
            $fields = array_merge($fields, array_keys($GLOBALS['TL_DCA'][$this->strTable]['fields'] ?? []));

            // Add meta fields if the current user is an administrator
            if ($this->User->isAdmin) {
                if (Database::getInstance()->fieldExists('sorting', $this->strTable) && !\in_array('sorting', $fields, true)) {
                    array_unshift($fields, 'sorting');
                }

                if (Database::getInstance()->fieldExists('pid', $this->strTable) && !\in_array('pid', $fields, true)) {
                    array_unshift($fields, 'pid');
                }
            }

            // Show all non-excluded fields
            foreach ($fields as $field) {
                if ('pid' === $field || 'sorting' === $field || (!($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['exclude'] ?? null) && !($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['eval']['doNotShow'] ?? null) && (isset($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['inputType']) || \is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['input_field_callback'] ?? null) || \is_callable($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['input_field_callback'] ?? null)))) {
                    $options .= '
  <input type="checkbox" name="all_fields[]" id="all_'.$field.'" class="tl_checkbox" value="'.StringUtil::specialchars($field).'"> <label for="all_'.$field.'" class="tl_checkbox_label">'.(($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['label'][0] ?? (\is_array($GLOBALS['TL_LANG']['MSC'][$field] ?? null) ? $GLOBALS['TL_LANG']['MSC'][$field][0] : ($GLOBALS['TL_LANG']['MSC'][$field] ?? null)) ?? $field).' <span style="color:#999;padding-left:3px">['.$field.']</span>').'</label><br>';
                }
            }

            $blnIsError = ($_POST && empty($_POST['all_fields']));

            // Return the select menu
            $return .= '

<form action="'.StringUtil::ampersand(Environment::get('request')).'&amp;fields=1" id="'.$this->strTable.'_all" class="tl_form tl_edit_form" method="post">
<div class="tl_formbody_edit">
<input type="hidden" name="FORM_SUBMIT" value="'.$this->strTable.'_all">
<input type="hidden" name="REQUEST_TOKEN" value="'.REQUEST_TOKEN.'">'.($blnIsError ? '
<p class="tl_error">'.$GLOBALS['TL_LANG']['ERR']['general'].'</p>' : '').'
<div class="tl_tbox">
<div class="widget">
<fieldset class="tl_checkbox_container">
  <legend'.($blnIsError ? ' class="error"' : '').'>'.$GLOBALS['TL_LANG']['MSC']['all_fields'][0].'<span class="mandatory">*</span></legend>
  <input type="checkbox" id="check_all" class="tl_checkbox" onclick="Backend.toggleCheckboxes(this)"> <label for="check_all" style="color:#a6a6a6"><em>'.$GLOBALS['TL_LANG']['MSC']['selectAll'].'</em></label><br>'.$options.'
</fieldset>'.($blnIsError ? '
<p class="tl_error">'.$GLOBALS['TL_LANG']['ERR']['all_fields'].'</p>' : (Config::get('showHelp') && isset($GLOBALS['TL_LANG']['MSC']['all_fields'][1]) ? '
<p class="tl_help tl_tip">'.$GLOBALS['TL_LANG']['MSC']['all_fields'][1].'</p>' : '')).'
</div>
</div>
</div>
<div class="tl_formbody_submit">
<div class="tl_submit_container">
  <button type="submit" name="save" id="save" class="tl_submit" accesskey="s">'.$GLOBALS['TL_LANG']['MSC']['continue'].'</button>
</div>
</div>
</form>';
        }

        // Return
        return '
<div id="tl_buttons">
<a href="'.Backend::getReferer(true).'" class="header_back" title="'.StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['backBTTitle']).'" accesskey="b" onclick="Backend.getScrollOffset()">'.$GLOBALS['TL_LANG']['MSC']['backBT'].'</a>
</div>'.$return;
    }

    /**
     * Auto-generate a form to override all records that are currently shown.
     *
     * @throws InternalServerErrorException
     *
     * @return string
     */
    public function overrideAll()
    {
        if ($GLOBALS['TL_DCA'][$this->strTable]['config']['notEditable'] ?? null) {
            throw new InternalServerErrorException('Table "'.$this->strTable.'" is not editable.');
        }

        $return = '';
        $this->import(BackendUser::class, 'User');

        /** @var Session $objSession */
        $objSession = System::getContainer()->get('session');

        // Get current IDs from session
        $session = $objSession->all();
        $ids = $session['CURRENT']['IDS'] ?? [];

        // Save field selection in session
        if (Input::post('FORM_SUBMIT') === $this->strTable.'_all' && Input::get('fields')) {
            $session['CURRENT'][$this->strTable] = Input::post('all_fields');
            $objSession->replace($session);
        }

        // Add fields
        $fields = $session['CURRENT'][$this->strTable] ?? [];

        if (!empty($fields) && \is_array($fields) && Input::get('fields')) {
            $class = 'tl_tbox';
            $formFields = [];

            // Save record
            if (Input::post('FORM_SUBMIT') === $this->strTable) {
                foreach ($ids as $id) {
                    $this->intId = $id;
                    $this->procedure = ['id=?'];
                    $this->values = [$this->intId];
                    $this->blnCreateNewVersion = false;

                    // Get the field values
                    $objRow = Database::getInstance()->prepare('SELECT * FROM '.$this->strTable.' WHERE id=?')
                        ->limit(1)
                        ->execute($this->intId)
                    ;

                    // Store the active record
                    $this->objActiveRecord = $objRow;

                    $objVersions = new Versions($this->strTable, $this->intId);
                    $objVersions->initialize();

                    // Store all fields
                    foreach ($fields as $v) {
                        // Check whether field is excluded
                        if ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$v]['exclude'] ?? null) {
                            continue;
                        }

                        $this->strField = $v;
                        $this->strInputName = $v;
                        $this->varValue = '';

                        // Make sure the new value is applied
                        $GLOBALS['TL_DCA'][$this->strTable]['fields'][$v]['eval']['alwaysSave'] = true;

                        // Store value
                        $this->row();
                    }

                    // Always create a new version if something has changed, even if the form has errors (see #237)
                    if ($this->noReload && $this->blnCreateNewVersion) {
                        $objVersions->create();
                    }

                    // Post processing
                    if (!$this->noReload) {
                        // Call the onsubmit_callback
                        if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['config']['onsubmit_callback'] ?? null)) {
                            foreach ($GLOBALS['TL_DCA'][$this->strTable]['config']['onsubmit_callback'] as $callback) {
                                if (\is_array($callback)) {
                                    $this->import($callback[0]);
                                    $this->{$callback[0]}->{$callback[1]}($this);
                                } elseif (\is_callable($callback)) {
                                    $callback($this);
                                }
                            }
                        }

                        $this->invalidateCacheTags();

                        // Set the current timestamp before adding a new version
                        if ($GLOBALS['TL_DCA'][$this->strTable]['config']['dynamicPtable'] ?? null) {
                            Database::getInstance()->prepare('UPDATE '.$this->strTable.' SET ptable=?, tstamp=? WHERE id=?')
                                ->execute($this->ptable, time(), $this->intId)
                            ;
                        } else {
                            Database::getInstance()->prepare('UPDATE '.$this->strTable.' SET tstamp=? WHERE id=?')
                                ->execute(time(), $this->intId)
                            ;
                        }

                        // Create a new version
                        if ($this->blnCreateNewVersion) {
                            $objVersions->create();

                            // Call the onversion_callback
                            if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['config']['onversion_callback'] ?? null)) {
                                trigger_deprecation('contao/core-bundle', '4.0', 'Using the "onversion_callback" has been deprecated and will no longer work in Contao 5.0. Use the "oncreate_version_callback" instead.');

                                foreach ($GLOBALS['TL_DCA'][$this->strTable]['config']['onversion_callback'] as $callback) {
                                    if (\is_array($callback)) {
                                        $this->import($callback[0]);
                                        $this->{$callback[0]}->{$callback[1]}($this->strTable, $this->intId, $this);
                                    } elseif (\is_callable($callback)) {
                                        $callback($this->strTable, $this->intId, $this);
                                    }
                                }
                            }
                        }
                    }
                }
            }

            // Begin current row
            $return .= '
<div class="'.$class.'">';

            foreach ($fields as $v) {
                // Check whether field is excluded
                if ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$v]['exclude'] ?? null) {
                    continue;
                }

                $formFields[] = $v;

                $this->intId = 0;
                $this->procedure = ['id=?'];
                $this->values = [$this->intId];
                $this->strField = $v;
                $this->strInputName = $v;
                $this->varValue = '';

                // Disable auto-submit
                $GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField]['eval']['submitOnChange'] = false;
                $return .= $this->row();
            }

            // Close box
            $return .= '
<input type="hidden" name="FORM_FIELDS[]" value="'.StringUtil::specialchars(implode(',', $formFields)).'">
</div>';

            // Submit buttons
            $arrButtons = [];
            $arrButtons['save'] = '<button type="submit" name="save" id="save" class="tl_submit" accesskey="s">'.$GLOBALS['TL_LANG']['MSC']['save'].'</button>';
            $arrButtons['saveNclose'] = '<button type="submit" name="saveNclose" id="saveNclose" class="tl_submit" accesskey="c">'.$GLOBALS['TL_LANG']['MSC']['saveNclose'].'</button>';

            // Call the buttons_callback (see #4691)
            if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['edit']['buttons_callback'] ?? null)) {
                foreach ($GLOBALS['TL_DCA'][$this->strTable]['edit']['buttons_callback'] as $callback) {
                    if (\is_array($callback)) {
                        $this->import($callback[0]);
                        $arrButtons = $this->{$callback[0]}->{$callback[1]}($arrButtons, $this);
                    } elseif (\is_callable($callback)) {
                        $arrButtons = $callback($arrButtons, $this);
                    }
                }
            }

            if (\count($arrButtons) < 3) {
                $strButtons = implode(' ', $arrButtons);
            } else {
                $strButtons = array_shift($arrButtons).' ';
                $strButtons .= '<div class="split-button">';
                $strButtons .= array_shift($arrButtons).'<button type="button" id="sbtog">'.Image::getHtml('navcol.svg').'</button> <ul class="invisible">';

                foreach ($arrButtons as $strButton) {
                    $strButtons .= '<li>'.$strButton.'</li>';
                }

                $strButtons .= '</ul></div>';
            }

            // Add the form
            $return = '
<form id="'.$this->strTable.'" class="tl_form tl_edit_form" method="post" enctype="'.($this->blnUploadable ? 'multipart/form-data' : 'application/x-www-form-urlencoded').'">
<div class="tl_formbody_edit nogrid">
<input type="hidden" name="FORM_SUBMIT" value="'.$this->strTable.'">
<input type="hidden" name="REQUEST_TOKEN" value="'.REQUEST_TOKEN.'">'.($this->noReload ? '
<p class="tl_error">'.$GLOBALS['TL_LANG']['ERR']['general'].'</p>' : '').$return.'
</div>
<div class="tl_formbody_submit">
<div class="tl_submit_container">
  '.$strButtons.'
</div>
</div>
</form>';

            // Set the focus if there is an error
            if ($this->noReload) {
                $return .= '
<script>
  window.addEvent(\'domready\', function() {
    Backend.vScrollTo(($(\''.$this->strTable.'\').getElement(\'label.error\').getPosition().y - 20));
  });
</script>';
            }

            // Reload the page to prevent _POST variables from being sent twice
            if (!$this->noReload && Input::post('FORM_SUBMIT') === $this->strTable) {
                if (isset($_POST['saveNclose'])) {
                    $this->redirect(Backend::getReferer());
                }

                $this->reload();
            }
        }

        // Else show a form to select the fields
        else {
            $options = '';
            $fields = [];

            // Add fields of the current table
            $fields = array_merge($fields, array_keys($GLOBALS['TL_DCA'][$this->strTable]['fields'] ?? []));

            // Add meta fields if the current user is an administrator
            if ($this->User->isAdmin) {
                if (Database::getInstance()->fieldExists('sorting', $this->strTable) && !\in_array('sorting', $fields, true)) {
                    array_unshift($fields, 'sorting');
                }

                if (Database::getInstance()->fieldExists('pid', $this->strTable) && !\in_array('pid', $fields, true)) {
                    array_unshift($fields, 'pid');
                }
            }

            // Show all non-excluded fields
            foreach ($fields as $field) {
                if ('pid' === $field || 'sorting' === $field || (!($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['exclude'] ?? null) && !($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['eval']['doNotShow'] ?? null) && (isset($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['inputType']) || \is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['input_field_callback'] ?? null) || \is_callable($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['input_field_callback'] ?? null)))) {
                    $options .= '
  <input type="checkbox" name="all_fields[]" id="all_'.$field.'" class="tl_checkbox" value="'.StringUtil::specialchars($field).'"> <label for="all_'.$field.'" class="tl_checkbox_label">'.(($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['label'][0] ?? (\is_array($GLOBALS['TL_LANG']['MSC'][$field] ?? null) ? $GLOBALS['TL_LANG']['MSC'][$field][0] : ($GLOBALS['TL_LANG']['MSC'][$field] ?? null)) ?? $field).' <span style="color:#999;padding-left:3px">['.$field.']</span>').'</label><br>';
                }
            }

            $blnIsError = ($_POST && empty($_POST['all_fields']));

            // Return the select menu
            $return .= '
<form action="'.StringUtil::ampersand(Environment::get('request')).'&amp;fields=1" id="'.$this->strTable.'_all" class="tl_form tl_edit_form" method="post">
<div class="tl_formbody_edit">
<input type="hidden" name="FORM_SUBMIT" value="'.$this->strTable.'_all">
<input type="hidden" name="REQUEST_TOKEN" value="'.REQUEST_TOKEN.'">'.($blnIsError ? '
<p class="tl_error">'.$GLOBALS['TL_LANG']['ERR']['general'].'</p>' : '').'
<div class="tl_tbox">
<div class="widget">
<fieldset class="tl_checkbox_container">
  <legend'.($blnIsError ? ' class="error"' : '').'>'.$GLOBALS['TL_LANG']['MSC']['all_fields'][0].'<span class="mandatory">*</span></legend>
  <input type="checkbox" id="check_all" class="tl_checkbox" onclick="Backend.toggleCheckboxes(this)"> <label for="check_all" style="color:#a6a6a6"><em>'.$GLOBALS['TL_LANG']['MSC']['selectAll'].'</em></label><br>'.$options.'
</fieldset>'.($blnIsError ? '
<p class="tl_error">'.$GLOBALS['TL_LANG']['ERR']['all_fields'].'</p>' : (Config::get('showHelp') && isset($GLOBALS['TL_LANG']['MSC']['all_fields'][1]) ? '
<p class="tl_help tl_tip">'.$GLOBALS['TL_LANG']['MSC']['all_fields'][1].'</p>' : '')).'
</div>
</div>
</div>
<div class="tl_formbody_submit">
<div class="tl_submit_container">
  <button type="submit" name="save" id="save" class="tl_submit" accesskey="s">'.$GLOBALS['TL_LANG']['MSC']['continue'].'</button>
</div>
</div>
</form>';
        }

        // Return
        return '
<div id="tl_buttons">
<a href="'.Backend::getReferer(true).'" class="header_back" title="'.StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['backBTTitle']).'" accesskey="b" onclick="Backend.getScrollOffset()">'.$GLOBALS['TL_LANG']['MSC']['backBT'].'</a>
</div>'.$return;
    }

    /**
     * Return the name of the current palette.
     *
     * @return string
     */
    public function getPalette()
    {
        $palette = 'default';
        $strPalette = $GLOBALS['TL_DCA'][$this->strTable]['palettes'][$palette];

        // Check whether there are selector fields
        if (!empty($GLOBALS['TL_DCA'][$this->strTable]['palettes']['__selector__'])) {
            $sValues = [];
            $subpalettes = [];

            $objFields = Database::getInstance()->prepare('SELECT * FROM '.$this->strTable.' WHERE id=?')
                ->limit(1)
                ->execute($this->intId)
            ;

            // Get selector values from DB
            if ($objFields->numRows > 0) {
                foreach ($GLOBALS['TL_DCA'][$this->strTable]['palettes']['__selector__'] as $name) {
                    $trigger = $objFields->$name;

                    // Overwrite the trigger
                    if (Input::post('FORM_SUBMIT') === $this->strTable) {
                        $key = 'editAll' === Input::get('act') ? $name.'_'.$this->intId : $name;

                        if (isset($_POST[$key])) {
                            $trigger = Input::post($key);
                        }
                    }

                    if ($trigger) {
                        if (($GLOBALS['TL_DCA'][$this->strTable]['fields'][$name]['inputType'] ?? null) === 'checkbox' && !($GLOBALS['TL_DCA'][$this->strTable]['fields'][$name]['eval']['multiple'] ?? null)) {
                            $sValues[] = $name;

                            // Look for a subpalette
                            if (isset($GLOBALS['TL_DCA'][$this->strTable]['subpalettes'][$name])) {
                                $subpalettes[$name] = $GLOBALS['TL_DCA'][$this->strTable]['subpalettes'][$name];
                            }
                        } else {
                            $sValues[] = $trigger;
                            $key = $name.'_'.$trigger;

                            // Look for a subpalette
                            if (isset($GLOBALS['TL_DCA'][$this->strTable]['subpalettes'][$key])) {
                                $subpalettes[$name] = $GLOBALS['TL_DCA'][$this->strTable]['subpalettes'][$key];
                            }
                        }
                    }
                }
            }

            // Build possible palette names from the selector values
            if (empty($sValues)) {
                $names = ['default'];
            } elseif (\count($sValues) > 1) {
                foreach ($sValues as $k => $v) {
                    // Unset selectors that just trigger subpalettes (see #3738)
                    if (isset($GLOBALS['TL_DCA'][$this->strTable]['subpalettes'][$v])) {
                        unset($sValues[$k]);
                    }
                }

                $names = $this->combiner($sValues);
            } else {
                $names = [$sValues[0]];
            }

            // Get an existing palette
            foreach ($names as $paletteName) {
                if (isset($GLOBALS['TL_DCA'][$this->strTable]['palettes'][$paletteName])) {
                    $strPalette = $GLOBALS['TL_DCA'][$this->strTable]['palettes'][$paletteName];
                    break;
                }
            }

            // Include subpalettes
            foreach ($subpalettes as $k => $v) {
                $strPalette = preg_replace('/\b'.preg_quote($k, '/').'\b/i', $k.',['.$k.'],'.$v.',[EOF]', $strPalette);
            }
        }

        return $strPalette;
    }

    /**
     * Generate a particular subpart of the tree and return it as HTML string.
     *
     * @param int $id
     * @param int $level
     *
     * @return string
     */
    public function ajaxTreeView($id, $level)
    {
        if (!Environment::get('isAjaxRequest')) {
            return '';
        }

        $return = '';
        $table = $this->strTable;
        $blnPtable = false;

        // Load parent table
        if (($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) === 6) {
            $table = $this->ptable;

            System::loadLanguageFile($table);
            $this->loadDataContainer($table);

            $blnPtable = true;
        }

        $blnProtected = false;

        // Check protected pages
        if ('tl_page' === $table) {
            $objParent = PageModel::findWithDetails($id);
            $blnProtected = $objParent->protected ? true : false;
        }

        $margin = $level * 20;
        $hasSorting = Database::getInstance()->fieldExists('sorting', $table);
        $arrIds = [];

        // Get records
        $objRows = Database::getInstance()->prepare('SELECT id FROM '.$table.' WHERE pid=?'.($hasSorting ? ' ORDER BY sorting' : ''))
            ->execute($id)
        ;

        while ($objRows->next()) {
            $arrIds[] = $objRows->id;
        }

        /** @var Session $objSession */
        $objSession = System::getContainer()->get('session');

        $blnClipboard = false;
        $arrClipboard = $objSession->get('CLIPBOARD');

        // Check clipboard
        if (!empty($arrClipboard[$this->strTable])) {
            $blnClipboard = true;
            $arrClipboard = $arrClipboard[$this->strTable];
        } else {
            $arrClipboard = null;
        }

        for ($i = 0, $c = \count($arrIds); $i < $c; ++$i) {
            $return .= ' '.trim($this->generateTree($table, $arrIds[$i], ['p' => ($arrIds[$i - 1] ?? null), 'n' => ($arrIds[$i + 1] ?? null)], $hasSorting, $margin, ($blnClipboard ? $arrClipboard : false), null !== $arrClipboard && ($id === $arrClipboard['id'] || (\is_array($arrClipboard['id']) && \in_array($id, $arrClipboard['id'], true)) || (!$blnPtable && !\is_array($arrClipboard['id']) && \in_array($id, Database::getInstance()->getChildRecords($arrClipboard['id'], $table), true))), $blnProtected));
        }

        return $return;
    }

    /**
     * {@inheritdoc}
     */
    public function initPicker(PickerInterface $picker)
    {
        $provider = $picker->getCurrentProvider();

        if (!$provider instanceof DcaPickerProviderInterface || $provider->getDcaTable($picker->getConfig()) !== $this->strTable) {
            return null;
        }

        $attributes = $provider->getDcaAttributes($picker->getConfig());

        $this->objPicker = $picker;
        $this->strPickerFieldType = $attributes['fieldType'];

        $this->objPickerCallback = static fn ($value) => $provider->convertDcaValue($picker->getConfig(), $value);

        if (isset($attributes['value'])) {
            $this->arrPickerValue = (array) $attributes['value'];
        }

        if (null === $attributes) {
            return null;
        }

        // Predefined node set (see #3563)
        if (isset($attributes['rootNodes'])) {
            $blnHasSorting = Database::getInstance()->fieldExists('sorting', $this->strTable);
            $arrRoot = $this->eliminateNestedPages((array) $attributes['rootNodes'], $this->strTable, $blnHasSorting);

            // Calculate the intersection of the root nodes with the mounted nodes (see #1001)
            if (!empty($this->root) && $arrRoot !== $this->root) {
                $arrRoot = $this->eliminateNestedPages(
                    array_intersect(
                        array_merge($arrRoot, Database::getInstance()->getChildRecords($arrRoot, $this->strTable)),
                        array_merge($this->root, Database::getInstance()->getChildRecords($this->root, $this->strTable))
                    ),
                    $this->strTable,
                    $blnHasSorting
                );
            }

            $this->root = $arrRoot;
        }

        if (isset($attributes['preserveRecord'])) {
            [$table, $id] = explode('.', $attributes['preserveRecord']);

            if ($table === $this->strTable) {
                $this->intPreserveRecord = $id;
            }
        }

        return $attributes;
    }

    /**
     * Return the field explanation as HTML string.
     *
     * @param string $strClass
     *
     * @return string
     */
    public function help($strClass = '')
    {
        $return = $GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField]['label'][1] ?? null;

        if (!$return || ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField]['inputType'] ?? null) === 'password' || !Config::get('showHelp')) {
            return '';
        }

        return '
  <p class="tl_help tl_tip'.$strClass.'">'.$return.'</p>';
    }

    /**
     * Invalidate the cache tags associated with a given DC.
     *
     * Call this whenever an entry is modified (added, updated, deleted).
     */
    public function invalidateCacheTags(): void
    {
        if (!System::getContainer()->has('fos_http_cache.cache_manager')) {
            return;
        }

        $tags = ['contao.db.'.$this->table.'.'.$this->id];

        $this->addPtableTags($this->table, $this->id, $tags);
        $this->addCtableTags($this->table, $this->id, $tags);

        // Trigger the oninvalidate_cache_tags_callback
        if (\is_array($GLOBALS['TL_DCA'][$this->table]['config']['oninvalidate_cache_tags_callback'] ?? null)) {
            foreach ($GLOBALS['TL_DCA'][$this->table]['config']['oninvalidate_cache_tags_callback'] as $callback) {
                if (\is_array($callback)) {
                    $this->import($callback[0]);
                    $tags = $this->{$callback[0]}->{$callback[1]}($this, $tags);
                } elseif (\is_callable($callback)) {
                    $tags = $callback($this, $tags);
                }
            }
        }

        // Make sure tags are unique and empty ones are removed
        $tags = array_filter(array_unique($tags));

        /** @var CacheManager $cacheManager */
        $cacheManager = System::getContainer()->get('fos_http_cache.cache_manager');
        $cacheManager->invalidateTags($tags);
    }

    public function addPtableTags($strTable, $intId, &$tags): void
    {
        if (empty($GLOBALS['TL_DCA'][$strTable]['config']['ptable'])) {
            $tags[] = 'contao.db.'.$strTable;

            return;
        }

        $ptable = $GLOBALS['TL_DCA'][$strTable]['config']['ptable'];

        Controller::loadDataContainer($ptable);

        $objPid = Database::getInstance()->prepare('SELECT pid FROM '.Database::quoteIdentifier($strTable).' WHERE id=?')
            ->execute($intId)
        ;

        if (!$objPid->numRows) {
            return;
        }

        $tags[] = 'contao.db.'.$ptable.'.'.$objPid->pid;

        $this->addPtableTags($ptable, $objPid->pid, $tags);
    }

    public function addCtableTags($strTable, $intId, &$tags): void
    {
        if (empty($GLOBALS['TL_DCA'][$strTable]['config']['ctable'])) {
            return;
        }

        foreach ($GLOBALS['TL_DCA'][$strTable]['config']['ctable'] as $ctable) {
            Controller::loadDataContainer($ctable);

            if ($GLOBALS['TL_DCA'][$ctable]['config']['dynamicPtable'] ?? null) {
                $objIds = Database::getInstance()->prepare('SELECT id FROM '.Database::quoteIdentifier($ctable).' WHERE pid=? AND ptable=?')
                    ->execute($intId, $strTable)
                ;
            } else {
                $objIds = Database::getInstance()->prepare('SELECT id FROM '.Database::quoteIdentifier($ctable).' WHERE pid=?')
                    ->execute($intId)
                ;
            }

            if (!$objIds->numRows) {
                continue;
            }

            while ($objIds->next()) {
                $tags[] = 'contao.db.'.$ctable.'.'.$objIds->id;

                $this->addCtableTags($ctable, $objIds->id, $tags);
            }
        }
    }

    /**
     * Duplicate all child records of a duplicated record.
     *
     * @param string $table
     * @param int    $insertID
     * @param int    $id
     * @param int    $parentId
     */
    protected function copyChilds($table, $insertID, $id, $parentId): void
    {
        $time = time();
        $copy = [];
        $cctable = [];
        $ctable = $GLOBALS['TL_DCA'][$table]['config']['ctable'] ?? [];

        if (!($GLOBALS['TL_DCA'][$table]['config']['ptable'] ?? null) && Input::get('childs') && Database::getInstance()->fieldExists('pid', $table) && Database::getInstance()->fieldExists('sorting', $table)) {
            $ctable[] = $table;
        }

        if (empty($ctable) || !\is_array($ctable)) {
            return;
        }

        // Walk through each child table
        foreach ($ctable as $v) {
            $this->loadDataContainer($v);
            $cctable[$v] = $GLOBALS['TL_DCA'][$v]['config']['ctable'] ?? null;

            if (!($GLOBALS['TL_DCA'][$v]['config']['doNotCopyRecords'] ?? null) && \strlen($v)) {
                // Consider the dynamic parent table (see #4867)
                if ($GLOBALS['TL_DCA'][$v]['config']['dynamicPtable'] ?? null) {
                    $ptable = $GLOBALS['TL_DCA'][$v]['config']['ptable'];
                    $cond = 'tl_article' === $ptable ? "(ptable=? OR ptable='')" : 'ptable=?';

                    $objCTable = Database::getInstance()->prepare("SELECT * FROM $v WHERE pid=? AND $cond".(Database::getInstance()->fieldExists('sorting', $v) ? ' ORDER BY sorting' : ''))
                        ->execute($id, $ptable)
                    ;
                } else {
                    $objCTable = Database::getInstance()->prepare("SELECT * FROM $v WHERE pid=?".(Database::getInstance()->fieldExists('sorting', $v) ? ' ORDER BY sorting' : ''))
                        ->execute($id)
                    ;
                }

                while ($objCTable->next()) {
                    // Exclude the duplicated record itself
                    if ($v === $table && $objCTable->id === $parentId) {
                        continue;
                    }

                    foreach ($objCTable->row() as $kk => $vv) {
                        if ('id' === $kk) {
                            continue;
                        }

                        // Never copy passwords
                        if (($GLOBALS['TL_DCA'][$v]['fields'][$kk]['inputType'] ?? null) === 'password') {
                            $vv = Widget::getEmptyValueByFieldType($GLOBALS['TL_DCA'][$v]['fields'][$kk]['sql'] ?? []);
                        }

                        // Empty unique fields or add a unique identifier in copyAll mode
                        elseif ($GLOBALS['TL_DCA'][$v]['fields'][$kk]['eval']['unique'] ?? null) {
                            $vv = 'copyAll' === Input::get('act') ? $vv.'-'.substr(md5(uniqid(mt_rand(), true)), 0, 8) : Widget::getEmptyValueByFieldType($GLOBALS['TL_DCA'][$v]['fields'][$kk]['sql'] ?? []);
                        }

                        // Reset doNotCopy and fallback fields to their default value
                        elseif (($GLOBALS['TL_DCA'][$v]['fields'][$kk]['eval']['doNotCopy'] ?? null) || ($GLOBALS['TL_DCA'][$v]['fields'][$kk]['eval']['fallback'] ?? null)) {
                            $vv = Widget::getEmptyValueByFieldType($GLOBALS['TL_DCA'][$v]['fields'][$kk]['sql'] ?? []);

                            // Use array_key_exists to allow NULL (see #5252)
                            if (\array_key_exists('default', $GLOBALS['TL_DCA'][$v]['fields'][$kk] ?? [])) {
                                $vv = \is_array($GLOBALS['TL_DCA'][$v]['fields'][$kk]['default']) ? serialize($GLOBALS['TL_DCA'][$v]['fields'][$kk]['default']) : $GLOBALS['TL_DCA'][$v]['fields'][$kk]['default'];
                            }

                            // Encrypt the default value (see #3740)
                            if ($GLOBALS['TL_DCA'][$v]['fields'][$kk]['eval']['encrypt'] ?? null) {
                                $vv = Encryption::encrypt($vv);
                            }
                        }

                        $copy[$v][$objCTable->id][$kk] = $vv;
                    }

                    $copy[$v][$objCTable->id]['pid'] = $insertID;
                    $copy[$v][$objCTable->id]['tstamp'] = $time;
                }
            }
        }

        // Duplicate the child records
        foreach ($copy as $k => $v) {
            if (!empty($v)) {
                foreach ($v as $kk => $vv) {
                    $objInsertStmt = Database::getInstance()->prepare('INSERT INTO '.$k.' %s')
                        ->set($vv)
                        ->execute()
                    ;

                    if ($objInsertStmt->affectedRows) {
                        $insertID = $objInsertStmt->insertId;

                        if ($kk !== $parentId && (!empty($cctable[$k]) || ($GLOBALS['TL_DCA'][$k]['list']['sorting']['mode'] ?? null) === 5)) {
                            $this->copyChilds($k, $insertID, $kk, $parentId);
                        }
                    }
                }
            }
        }
    }

    /**
     * Calculate the new position of a moved or inserted record.
     *
     * @param string $mode
     * @param int    $pid
     * @param bool   $insertInto
     */
    protected function getNewPosition($mode, $pid = null, $insertInto = false): void
    {
        // If there is pid and sorting
        if (Database::getInstance()->fieldExists('pid', $this->strTable) && Database::getInstance()->fieldExists('sorting', $this->strTable)) {
            // PID is not set - only valid for duplicated records, as they get the same parent ID as the original record!
            if (null === $pid && $this->intId && 'copy' === $mode) {
                $pid = $this->intId;
            }

            // PID is set (insert after or into the parent record)
            if (is_numeric($pid)) {
                $newPID = null;
                $newSorting = null;
                $filter = ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) === 4 ? $this->strTable.'_'.CURRENT_ID : $this->strTable;

                /** @var Session $objSession */
                $objSession = System::getContainer()->get('session');
                $session = $objSession->all();

                // Consider the pagination menu when inserting at the top (see #7895)
                if ($insertInto && isset($session['filter'][$filter]['limit'])) {
                    $limit = substr($session['filter'][$filter]['limit'], 0, strpos($session['filter'][$filter]['limit'], ','));

                    if ($limit > 0) {
                        $objInsertAfter = Database::getInstance()->prepare('SELECT id FROM '.$this->strTable.' WHERE pid=? ORDER BY sorting')
                            ->limit(1, $limit - 1)
                            ->execute($pid)
                        ;

                        if ($objInsertAfter->numRows) {
                            $insertInto = false;
                            $pid = $objInsertAfter->id;
                        }
                    }
                }

                // Insert the current record at the beginning when inserting into the parent record
                if ($insertInto) {
                    $newPID = $pid;

                    $objSorting = Database::getInstance()->prepare('SELECT MIN(sorting) AS sorting FROM '.$this->strTable.' WHERE pid=?')
                        ->execute($pid)
                    ;

                    // Select sorting value of the first record
                    if ($objSorting->numRows) {
                        $curSorting = $objSorting->sorting;

                        // Resort if the new sorting value is not an integer or smaller than 1
                        if (0 !== $curSorting % 2 || $curSorting < 1) {
                            $objNewSorting = Database::getInstance()->prepare('SELECT id FROM '.$this->strTable.' WHERE pid=? ORDER BY sorting')
                                ->execute($pid)
                            ;

                            $count = 2;
                            $newSorting = 128;

                            while ($objNewSorting->next()) {
                                Database::getInstance()->prepare('UPDATE '.$this->strTable.' SET sorting=? WHERE id=?')
                                    ->limit(1)
                                    ->execute($count++ * 128, $objNewSorting->id)
                                ;
                            }
                        }

                        // Else new sorting = (current sorting / 2)
                        else {
                            $newSorting = $curSorting / 2;
                        }
                    }

                    // Else new sorting = 128
                    else {
                        $newSorting = 128;
                    }
                }

                // Else insert the current record after the parent record
                elseif ($pid > 0) {
                    $objSorting = Database::getInstance()->prepare('SELECT pid, sorting FROM '.$this->strTable.' WHERE id=?')
                        ->limit(1)
                        ->execute($pid)
                    ;

                    // Set parent ID of the current record as new parent ID
                    if ($objSorting->numRows) {
                        $newPID = $objSorting->pid;
                        $curSorting = $objSorting->sorting;

                        // Do not proceed without a parent ID
                        if (is_numeric($newPID)) {
                            $objNextSorting = Database::getInstance()->prepare('SELECT MIN(sorting) AS sorting FROM '.$this->strTable.' WHERE pid=? AND sorting>?')
                                ->execute($newPID, $curSorting)
                            ;

                            // Select sorting value of the next record
                            if (null !== $objNextSorting->sorting) {
                                $nxtSorting = $objNextSorting->sorting;

                                // Resort if the new sorting value is no integer or bigger than a MySQL integer
                                if (($curSorting + $nxtSorting) % 2 !== 0 || $nxtSorting >= 4294967295) {
                                    $count = 1;

                                    $objNewSorting = Database::getInstance()->prepare('SELECT id, sorting FROM '.$this->strTable.' WHERE pid=? ORDER BY sorting')
                                        ->execute($newPID)
                                    ;

                                    while ($objNewSorting->next()) {
                                        Database::getInstance()->prepare('UPDATE '.$this->strTable.' SET sorting=? WHERE id=?')
                                            ->execute($count++ * 128, $objNewSorting->id)
                                        ;

                                        if ($objNewSorting->sorting === $curSorting) {
                                            $newSorting = $count++ * 128;
                                        }
                                    }
                                }

                                // Else new sorting = (current sorting + next sorting) / 2
                                else {
                                    $newSorting = ($curSorting + $nxtSorting) / 2;
                                }
                            }

                            // Else new sorting = (current sorting + 128)
                            else {
                                $newSorting = $curSorting + 128;
                            }
                        }
                    }

                    // Use the given parent ID as parent ID
                    else {
                        $newPID = $pid;
                        $newSorting = 128;
                    }
                }

                // Set new sorting and new parent ID
                $this->set['pid'] = (int) $newPID;
                $this->set['sorting'] = (int) $newSorting;
            }
        }

        // If there is only pid
        elseif (Database::getInstance()->fieldExists('pid', $this->strTable)) {
            // PID is not set - only valid for duplicated records, as they get the same parent ID as the original record!
            if (null === $pid && $this->intId && 'copy' === $mode) {
                $pid = $this->intId;
            }

            // PID is set (insert after or into the parent record)
            if (is_numeric($pid)) {
                // Insert the current record into the parent record
                if ($insertInto) {
                    $this->set['pid'] = $pid;
                }

                // Else insert the current record after the parent record
                elseif ($pid > 0) {
                    $objParentRecord = Database::getInstance()->prepare('SELECT pid FROM '.$this->strTable.' WHERE id=?')
                        ->limit(1)
                        ->execute($pid)
                    ;

                    if ($objParentRecord->numRows) {
                        $this->set['pid'] = $objParentRecord->pid;
                    }
                }
            }
        }

        // If there is only sorting
        elseif (Database::getInstance()->fieldExists('sorting', $this->strTable)) {
            // ID is set (insert after the current record)
            if ($this->intId) {
                $objCurrentRecord = Database::getInstance()->prepare('SELECT * FROM '.$this->strTable.' WHERE id=?')
                    ->limit(1)
                    ->execute($this->intId)
                ;

                // Select current record
                if ($objCurrentRecord->numRows) {
                    $newSorting = null;
                    $curSorting = $objCurrentRecord->sorting;

                    $objNextSorting = Database::getInstance()->prepare('SELECT MIN(sorting) AS sorting FROM '.$this->strTable.' WHERE sorting>?')
                        ->execute($curSorting)
                    ;

                    // Select sorting value of the next record
                    if ($objNextSorting->numRows) {
                        $nxtSorting = $objNextSorting->sorting;

                        // Resort if the new sorting value is no integer or bigger than a MySQL integer field
                        if (($curSorting + $nxtSorting) % 2 !== 0 || $nxtSorting >= 4294967295) {
                            $count = 1;

                            $objNewSorting = Database::getInstance()->execute('SELECT id, sorting FROM '.$this->strTable.' ORDER BY sorting');

                            while ($objNewSorting->next()) {
                                Database::getInstance()->prepare('UPDATE '.$this->strTable.' SET sorting=? WHERE id=?')
                                    ->execute($count++ * 128, $objNewSorting->id)
                                ;

                                if ($objNewSorting->sorting === $curSorting) {
                                    $newSorting = $count++ * 128;
                                }
                            }
                        }

                        // Else new sorting = (current sorting + next sorting) / 2
                        else {
                            $newSorting = ($curSorting + $nxtSorting) / 2;
                        }
                    }

                    // Else new sorting = (current sorting + 128)
                    else {
                        $newSorting = $curSorting + 128;
                    }

                    // Set new sorting
                    $this->set['sorting'] = (int) $newSorting;

                    return;
                }
            }

            // ID is not set or not found (insert at the end)
            $objNextSorting = Database::getInstance()->execute('SELECT MAX(sorting) AS sorting FROM '.$this->strTable);
            $this->set['sorting'] = (int) $objNextSorting->sorting + 128;
        }
    }

    /**
     * Save the current value.
     *
     * @param mixed $varValue
     *
     * @throws \Exception
     */
    protected function save($varValue): void
    {
        if (Input::post('FORM_SUBMIT') !== $this->strTable) {
            return;
        }

        $arrData = $GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField] ?? [];

        // Convert date formats into timestamps
        if (null !== $varValue && '' !== $varValue && \in_array($arrData['eval']['rgxp'] ?? null, ['date', 'time', 'datim'], true)) {
            $objDate = new Date($varValue, Date::getFormatFromRgxp($arrData['eval']['rgxp']));
            $varValue = $objDate->tstamp;
        }

        // Make sure unique fields are unique
        if ('' !== (string) $varValue && ($arrData['eval']['unique'] ?? null) && !Database::getInstance()->isUniqueValue($this->strTable, $this->strField, $varValue, $this->objActiveRecord->id)) {
            throw new \Exception(sprintf($GLOBALS['TL_LANG']['ERR']['unique'], $arrData['label'][0] ?: $this->strField));
        }

        // Handle multi-select fields in "override all" mode
        if (null !== $this->objActiveRecord && (($arrData['inputType'] ?? null) === 'checkbox' || ($arrData['inputType'] ?? null) === 'checkboxWizard') && ($arrData['eval']['multiple'] ?? null) && 'overrideAll' === Input::get('act')) {
            $new = StringUtil::deserialize($varValue, true);
            $old = StringUtil::deserialize($this->objActiveRecord->{$this->strField}, true);

            // Call load_callback
            if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField]['load_callback'] ?? null)) {
                foreach ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField]['load_callback'] as $callback) {
                    if (\is_array($callback)) {
                        $this->import($callback[0]);
                        $old = $this->{$callback[0]}->{$callback[1]}($old, $this);
                    } elseif (\is_callable($callback)) {
                        $old = $callback($old, $this);
                    }
                }
            }

            switch (Input::post($this->strInputName.'_update')) {
                case 'add':
                    $varValue = array_values(array_unique(array_merge($old, $new)));
                    break;

                case 'remove':
                    $varValue = array_values(array_diff($old, $new));
                    break;

                case 'replace':
                    $varValue = $new;
                    break;
            }

            if (empty($varValue) || !\is_array($varValue)) {
                $varValue = Widget::getEmptyStringOrNullByFieldType($arrData['sql'] ?? []);
            } elseif (isset($arrData['eval']['csv'])) {
                $varValue = implode($arrData['eval']['csv'], $varValue); // see #2890
            } else {
                $varValue = serialize($varValue);
            }
        }

        // Convert arrays (see #2890)
        if (($arrData['eval']['multiple'] ?? null) && isset($arrData['eval']['csv'])) {
            $varValue = implode($arrData['eval']['csv'], StringUtil::deserialize($varValue, true));
        }

        // Trigger the save_callback
        if (\is_array($arrData['save_callback'] ?? null)) {
            foreach ($arrData['save_callback'] as $callback) {
                if (\is_array($callback)) {
                    $this->import($callback[0]);
                    $varValue = $this->{$callback[0]}->{$callback[1]}($varValue, $this);
                } elseif (\is_callable($callback)) {
                    $varValue = $callback($varValue, $this);
                }
            }
        }

        // Save the value if there was no error
        if ('' !== (string) $varValue || !($arrData['eval']['doNotSaveEmpty'] ?? null) && ($this->varValue !== $varValue || ($arrData['eval']['alwaysSave'] ?? null))) {
            // If the field is a fallback field, empty all other columns (see #6498)
            if ($varValue && ($arrData['eval']['fallback'] ?? null)) {
                if (($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) === 4) {
                    Database::getInstance()->prepare('UPDATE '.$this->strTable.' SET '.Database::quoteIdentifier($this->strField)."='' WHERE pid=?")
                        ->execute($this->activeRecord->pid)
                    ;
                } else {
                    Database::getInstance()->execute('UPDATE '.$this->strTable.' SET '.Database::quoteIdentifier($this->strField)."=''");
                }
            }

            // Set the correct empty value (see #6284, #6373)
            if ('' === (string) $varValue) {
                $varValue = Widget::getEmptyValueByFieldType($GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField]['sql'] ?? []);
            }

            $arrValues = $this->values;
            array_unshift($arrValues, $varValue);

            $objUpdateStmt = Database::getInstance()->prepare('UPDATE '.$this->strTable.' SET '.Database::quoteIdentifier($this->strField).'=? WHERE '.implode(' AND ', $this->procedure))
                ->execute($arrValues)
            ;

            if ($objUpdateStmt->affectedRows) {
                if (!isset($arrData['eval']['versionize']) || false !== $arrData['eval']['versionize']) {
                    $this->blnCreateNewVersion = true;
                }

                $this->varValue = StringUtil::deserialize($varValue);

                if (\is_object($this->objActiveRecord)) {
                    $this->objActiveRecord->{$this->strField} = $this->varValue;
                }
            }
        }
    }

    /**
     * Delete all incomplete and unrelated records.
     */
    protected function reviseTable(): void
    {
        $reload = false;
        $ptable = $GLOBALS['TL_DCA'][$this->strTable]['config']['ptable'] ?? null;
        $ctable = $GLOBALS['TL_DCA'][$this->strTable]['config']['ctable'] ?? null;

        if (null === $ptable && ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) === 5) {
            $ptable = $this->strTable;
        }

        /** @var AttributeBagInterface $objSessionBag */
        $objSessionBag = System::getContainer()->get('session')->getBag('contao_backend');

        $new_records = $objSessionBag->get('new_records');

        // HOOK: add custom logic
        if (isset($GLOBALS['TL_HOOKS']['reviseTable']) && \is_array($GLOBALS['TL_HOOKS']['reviseTable'])) {
            foreach ($GLOBALS['TL_HOOKS']['reviseTable'] as $callback) {
                $status = null;

                if (\is_array($callback)) {
                    System::importStatic($callback[0])->{$callback[1]}($this->strTable, $new_records[$this->strTable] ?? null, $ptable, $ctable);
                } elseif (\is_callable($callback)) {
                    $status = $callback($this->strTable, $new_records[$this->strTable] ?? null, $ptable, $ctable);
                }

                if (true === $status) {
                    $reload = true;
                }
            }
        }

        // Delete all new but incomplete records (tstamp=0)
        if (!empty($new_records[$this->strTable]) && \is_array($new_records[$this->strTable])) {
            $intPreserved = null;

            // Unset the preserved record (see #1129)
            if ($this->intPreserveRecord && ($index = array_search($this->intPreserveRecord, $new_records[$this->strTable], true)) !== false) {
                $intPreserved = $new_records[$this->strTable][$index];
                unset($new_records[$this->strTable][$index]);
            }

            // Remove the entries from the database
            if (!empty($new_records[$this->strTable])) {
                $origId = $this->id;
                $origActiveRecord = $this->activeRecord;
                $ids = array_map('\intval', $new_records[$this->strTable]);

                foreach ($ids as $id) {
                    // Get the current record
                    $objRow = Database::getInstance()->prepare('SELECT * FROM '.$this->strTable.' WHERE id=?')
                        ->limit(1)
                        ->execute($id)
                    ;

                    $this->id = $id;
                    $this->activeRecord = $objRow;

                    // Invalidate cache tags (no need to invalidate the parent)
                    $this->invalidateCacheTags();
                }

                $this->id = $origId;
                $this->activeRecord = $origActiveRecord;

                $objStmt = Database::getInstance()->execute('DELETE FROM '.$this->strTable.' WHERE id IN('.implode(',', $ids).') AND tstamp=0');

                if ($objStmt->affectedRows > 0) {
                    $reload = true;
                }
            }

            // Remove the entries from the session
            if (null !== $intPreserved) {
                $new_records[$this->strTable] = [$intPreserved];
            } else {
                unset($new_records[$this->strTable]);
            }

            $objSessionBag->set('new_records', $new_records);
        }

        // Delete all records of the current table that are not related to the parent table
        if ($ptable) {
            if ($GLOBALS['TL_DCA'][$this->strTable]['config']['dynamicPtable'] ?? null) {
                $objIds = Database::getInstance()->execute('SELECT c.id FROM '.$this->strTable.' c LEFT JOIN '.$ptable." p ON c.pid=p.id WHERE c.ptable='".$ptable."' AND p.id IS NULL");
            } elseif ($ptable === $this->strTable) {
                $objIds = Database::getInstance()->execute('SELECT c.id FROM '.$this->strTable.' c LEFT JOIN '.$ptable.' p ON c.pid=p.id WHERE p.id IS NULL AND c.pid > 0');
            } else {
                $objIds = Database::getInstance()->execute('SELECT c.id FROM '.$this->strTable.' c LEFT JOIN '.$ptable.' p ON c.pid=p.id WHERE p.id IS NULL');
            }

            if ($objIds->numRows) {
                $objStmt = Database::getInstance()->execute('DELETE FROM '.$this->strTable.' WHERE id IN('.implode(',', array_map('\intval', $objIds->fetchEach('id'))).')');

                if ($objStmt->affectedRows > 0) {
                    $reload = true;
                }
            }
        }

        // Delete all records of the child table that are not related to the current table
        if (!empty($ctable) && \is_array($ctable)) {
            foreach ($ctable as $v) {
                if ($v) {
                    // Load the DCA configuration so we can check for "dynamicPtable"
                    $this->loadDataContainer($v);

                    if ($GLOBALS['TL_DCA'][$v]['config']['dynamicPtable'] ?? null) {
                        $objIds = Database::getInstance()->execute('SELECT c.id FROM '.$v.' c LEFT JOIN '.$this->strTable." p ON c.pid=p.id WHERE c.ptable='".$this->strTable."' AND p.id IS NULL");
                    } else {
                        $objIds = Database::getInstance()->execute('SELECT c.id FROM '.$v.' c LEFT JOIN '.$this->strTable.' p ON c.pid=p.id WHERE p.id IS NULL');
                    }

                    if ($objIds->numRows) {
                        $objStmt = Database::getInstance()->execute('DELETE FROM '.$v.' WHERE id IN('.implode(',', array_map('\intval', $objIds->fetchEach('id'))).')');

                        if ($objStmt->affectedRows > 0) {
                            $reload = true;
                        }
                    }
                }
            }
        }

        // Reload the page
        if ($reload) {
            $this->reload();
        }
    }

    /**
     * List all records of the current table as tree and return them as HTML string.
     *
     * @return string
     */
    protected function treeView()
    {
        $table = $this->strTable;
        $treeClass = 'tl_tree';

        if (($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) === 6) {
            $table = $this->ptable;
            $treeClass = 'tl_tree_xtnd';

            System::loadLanguageFile($table);
            $this->loadDataContainer($table);
        }

        /** @var Session $objSession */
        $objSession = System::getContainer()->get('session');

        /** @var AttributeBagInterface $objSessionBag */
        $objSessionBag = $objSession->getBag('contao_backend');

        $session = $objSessionBag->all();

        // Toggle the nodes
        if ('all' === Input::get('ptg')) {
            $node = ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) === 6 ? $this->strTable.'_'.$table.'_tree' : $this->strTable.'_tree';

            // Expand tree
            if (empty($session[$node]) || !\is_array($session[$node]) || 1 !== current($session[$node])) {
                $session[$node] = [];
                $objNodes = Database::getInstance()->execute('SELECT DISTINCT pid FROM '.$table.' WHERE pid>0');

                while ($objNodes->next()) {
                    $session[$node][$objNodes->pid] = 1;
                }
            }

            // Collapse tree
            else {
                $session[$node] = [];
            }

            $objSessionBag->replace($session);
            $this->redirect(preg_replace('/(&(amp;)?|\?)ptg=[^& ]*/i', '', Environment::get('request')));
        }

        // Return if a mandatory field (id, pid, sorting) is missing
        if (($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) === 5 && (!Database::getInstance()->fieldExists('id', $table) || !Database::getInstance()->fieldExists('pid', $table) || !Database::getInstance()->fieldExists('sorting', $table))) {
            return '
<p class="tl_empty">Table "'.$table.'" can not be shown as tree, because the "id", "pid" or "sorting" field is missing!</p>';
        }

        // Return if there is no parent table
        if (!$this->ptable && ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) === 6) {
            return '
<p class="tl_empty">Table "'.$table.'" can not be shown as extended tree, because there is no parent table!</p>';
        }

        $blnClipboard = false;
        $arrClipboard = $objSession->get('CLIPBOARD');

        // Check the clipboard
        if (!empty($arrClipboard[$this->strTable])) {
            $blnClipboard = true;
            $arrClipboard = $arrClipboard[$this->strTable];
        } else {
            $arrClipboard = null;
        }

        if (isset($GLOBALS['TL_DCA'][$table]['config']['label'])) {
            $label = $GLOBALS['TL_DCA'][$table]['config']['label'];
        } elseif (($do = Input::get('do')) && isset($GLOBALS['TL_LANG']['MOD'][$do])) {
            $label = $GLOBALS['TL_LANG']['MOD'][$do][0];
        } else {
            $label = $GLOBALS['TL_LANG']['MOD']['page'][0];
        }

        $icon = !empty($GLOBALS['TL_DCA'][$table]['list']['sorting']['icon']) ? $GLOBALS['TL_DCA'][$table]['list']['sorting']['icon'] : 'pagemounts.svg';
        $label = Image::getHtml($icon).' <label>'.$label.'</label>';

        // Check the default labels (see #509)
        $labelNew = $GLOBALS['TL_LANG'][$this->strTable]['new'] ?? $GLOBALS['TL_LANG']['DCA']['new'];

        // Begin buttons container
        $return = Message::generate().'
<div id="tl_buttons">'.('select' === Input::get('act') ? '
<a href="'.Backend::getReferer(true).'" class="header_back" title="'.StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['backBTTitle']).'" accesskey="b" onclick="Backend.getScrollOffset()">'.$GLOBALS['TL_LANG']['MSC']['backBT'].'</a> ' : (isset($GLOBALS['TL_DCA'][$this->strTable]['config']['backlink']) ? '
<a href="contao/main.php?'.$GLOBALS['TL_DCA'][$this->strTable]['config']['backlink'].'" class="header_back" title="'.StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['backBTTitle']).'" accesskey="b" onclick="Backend.getScrollOffset()">'.$GLOBALS['TL_LANG']['MSC']['backBT'].'</a> ' : '')).('select' !== Input::get('act') && !$blnClipboard && !($GLOBALS['TL_DCA'][$this->strTable]['config']['closed'] ?? null) && !($GLOBALS['TL_DCA'][$this->strTable]['config']['notCreatable'] ?? null) ? '
<a href="'.Backend::addToUrl('act=paste&amp;mode=create').'" class="header_new" title="'.StringUtil::specialchars($labelNew[1]).'" accesskey="n" onclick="Backend.getScrollOffset()">'.$labelNew[0].'</a> ' : '').($blnClipboard ? '
<a href="'.Backend::addToUrl('clipboard=1').'" class="header_clipboard" title="'.StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['clearClipboard']).'" accesskey="x">'.$GLOBALS['TL_LANG']['MSC']['clearClipboard'].'</a> ' : $this->generateGlobalButtons()).'
</div>';

        $tree = '';
        $blnHasSorting = Database::getInstance()->fieldExists('sorting', $table);
        $arrFound = [];

        if (!empty($this->procedure)) {
            $fld = ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) === 6 ? 'pid' : 'id';

            if ('id' === $fld) {
                $objRoot = Database::getInstance()->prepare('SELECT id FROM '.$this->strTable.' WHERE '.implode(' AND ', $this->procedure).($blnHasSorting ? ' ORDER BY sorting' : ''))
                    ->execute($this->values)
                ;
            } elseif ($blnHasSorting) {
                $objRoot = Database::getInstance()->prepare('SELECT pid, (SELECT sorting FROM '.$table.' WHERE '.$this->strTable.'.pid='.$table.'.id) AS psort FROM '.$this->strTable.' WHERE '.implode(' AND ', $this->procedure).' GROUP BY pid ORDER BY psort')
                    ->execute($this->values)
                ;
            } else {
                $objRoot = Database::getInstance()->prepare('SELECT pid FROM '.$this->strTable.' WHERE '.implode(' AND ', $this->procedure).' GROUP BY pid')
                    ->execute($this->values)
                ;
            }

            if ($objRoot->numRows < 1) {
                $this->root = [];
            }
            // Respect existing limitations (root IDs)
            elseif (!empty($this->root)) {
                $arrRoot = [];

                while ($objRoot->next()) {
                    if (\count(array_intersect($this->root, Database::getInstance()->getParentRecords($objRoot->$fld, $table))) > 0) {
                        $arrRoot[] = $objRoot->$fld;
                    }
                }

                $arrFound = $arrRoot;
                $this->root = $this->eliminateNestedPages($arrFound, $table, $blnHasSorting);
            } else {
                $arrFound = $objRoot->fetchEach($fld);
                $this->root = $this->eliminateNestedPages($arrFound, $table, $blnHasSorting);
            }
        }

        // Call a recursive function that builds the tree
        if (\is_array($this->root)) {
            for ($i = 0, $c = \count($this->root); $i < $c; ++$i) {
                $tree .= $this->generateTree($table, $this->root[$i], ['p' => ($this->root[$i - 1] ?? null), 'n' => ($this->root[$i + 1] ?? null)], $blnHasSorting, -20, ($blnClipboard ? $arrClipboard : false), (($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) === 5 && $blnClipboard && $this->root[$i] === $arrClipboard['id']), false, false, $arrFound);
            }
        }

        $breadcrumb = $GLOBALS['TL_DCA'][$table]['list']['sorting']['breadcrumb'] ?? '';

        // Return if there are no records
        if (!$tree && 'paste' !== Input::get('act')) {
            if ($breadcrumb) {
                $return .= '<div class="tl_listing_container">'.$breadcrumb.'</div>';
            }

            return $return.'
<p class="tl_empty">'.$GLOBALS['TL_LANG']['MSC']['noResult'].'</p>';
        }

        $return .= ('select' === Input::get('act') ? '
<form id="tl_select" class="tl_form'.('select' === Input::get('act') ? ' unselectable' : '').'" method="post" novalidate>
<div class="tl_formbody_edit">
<input type="hidden" name="FORM_SUBMIT" value="tl_select">
<input type="hidden" name="REQUEST_TOKEN" value="'.REQUEST_TOKEN.'">' : '').($blnClipboard ? '
<div id="paste_hint" data-add-to-scroll-offset="20">
  <p>'.$GLOBALS['TL_LANG']['MSC']['selectNewPosition'].'</p>
</div>' : '').'
<div class="tl_listing_container tree_view" id="tl_listing"'.$this->getPickerValueAttribute().'>'.$breadcrumb.('select' === Input::get('act') || ('checkbox' === $this->strPickerFieldType) ? '
<div class="tl_select_trigger">
<label for="tl_select_trigger" class="tl_select_label">'.$GLOBALS['TL_LANG']['MSC']['selectAll'].'</label> <input type="checkbox" id="tl_select_trigger" onclick="Backend.toggleCheckboxes(this)" class="tl_tree_checkbox">
</div>' : '').'
<ul class="tl_listing '.$treeClass.($this->strPickerFieldType ? ' picker unselectable' : '').'">
  <li class="tl_folder_top cf"><div class="tl_left">'.$label.'</div> <div class="tl_right">';

        $_buttons = '&nbsp;';

        // Show paste button only if there are no root records specified
        if ($blnClipboard && ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) === 5 && ((empty($GLOBALS['TL_DCA'][$table]['list']['sorting']['root']) && ($GLOBALS['TL_DCA'][$table]['list']['sorting']['root'] ?? null) !== false) || ($GLOBALS['TL_DCA'][$table]['list']['sorting']['rootPaste'] ?? null)) && 'select' !== Input::get('act')) {
            // Call paste_button_callback (&$dc, $row, $table, $cr, $childs, $previous, $next)
            if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['paste_button_callback'] ?? null)) {
                $strClass = $GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['paste_button_callback'][0];
                $strMethod = $GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['paste_button_callback'][1];

                $this->import($strClass);
                $_buttons = $this->$strClass->$strMethod($this, ['id' => 0], $table, false, $arrClipboard);
            } elseif (\is_callable($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['paste_button_callback'] ?? null)) {
                $_buttons = $GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['paste_button_callback']($this, ['id' => 0], $table, false, $arrClipboard);
            } else {
                $labelPasteInto = $GLOBALS['TL_LANG'][$this->strTable]['pasteinto'] ?? $GLOBALS['TL_LANG']['DCA']['pasteinto'];
                $imagePasteInto = Image::getHtml('pasteinto.svg', $labelPasteInto[0]);
                $_buttons = '<a href="'.Backend::addToUrl('act='.$arrClipboard['mode'].'&amp;mode=2&amp;pid=0'.(!\is_array($arrClipboard['id']) ? '&amp;id='.$arrClipboard['id'] : '')).'" title="'.StringUtil::specialchars($labelPasteInto[0]).'" onclick="Backend.getScrollOffset()">'.$imagePasteInto.'</a> ';
            }
        }

        // End table
        $return .= $_buttons.'</div></li>'.$tree.'
</ul>'.('radio' === $this->strPickerFieldType ? '
<div class="tl_radio_reset">
<label for="tl_radio_reset" class="tl_radio_label">'.$GLOBALS['TL_LANG']['MSC']['resetSelected'].'</label> <input type="radio" name="picker" id="tl_radio_reset" value="" class="tl_tree_radio">
</div>' : '').'
</div>';

        // Close the form
        if ('select' === Input::get('act')) {
            // Submit buttons
            $arrButtons = [];

            if (!($GLOBALS['TL_DCA'][$this->strTable]['config']['notEditable'] ?? null)) {
                $arrButtons['edit'] = '<button type="submit" name="edit" id="edit" class="tl_submit" accesskey="s">'.$GLOBALS['TL_LANG']['MSC']['editSelected'].'</button>';
            }

            if (!($GLOBALS['TL_DCA'][$this->strTable]['config']['notDeletable'] ?? null)) {
                $arrButtons['delete'] = '<button type="submit" name="delete" id="delete" class="tl_submit" accesskey="d" onclick="return confirm(\''.$GLOBALS['TL_LANG']['MSC']['delAllConfirm'].'\')">'.$GLOBALS['TL_LANG']['MSC']['deleteSelected'].'</button>';
            }

            if (!($GLOBALS['TL_DCA'][$this->strTable]['config']['notCopyable'] ?? null)) {
                $arrButtons['copy'] = '<button type="submit" name="copy" id="copy" class="tl_submit" accesskey="c">'.$GLOBALS['TL_LANG']['MSC']['copySelected'].'</button>';
            }

            if (!($GLOBALS['TL_DCA'][$this->strTable]['config']['notSortable'] ?? null)) {
                $arrButtons['cut'] = '<button type="submit" name="cut" id="cut" class="tl_submit" accesskey="x">'.$GLOBALS['TL_LANG']['MSC']['moveSelected'].'</button>';
            }

            if (!($GLOBALS['TL_DCA'][$this->strTable]['config']['notEditable'] ?? null)) {
                $arrButtons['override'] = '<button type="submit" name="override" id="override" class="tl_submit" accesskey="v">'.$GLOBALS['TL_LANG']['MSC']['overrideSelected'].'</button>';
            }

            // Call the buttons_callback (see #4691)
            if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['select']['buttons_callback'] ?? null)) {
                foreach ($GLOBALS['TL_DCA'][$this->strTable]['select']['buttons_callback'] as $callback) {
                    if (\is_array($callback)) {
                        $this->import($callback[0]);
                        $arrButtons = $this->{$callback[0]}->{$callback[1]}($arrButtons, $this);
                    } elseif (\is_callable($callback)) {
                        $arrButtons = $callback($arrButtons, $this);
                    }
                }
            }

            if (\count($arrButtons) < 3) {
                $strButtons = implode(' ', $arrButtons);
            } else {
                $strButtons = array_shift($arrButtons).' ';
                $strButtons .= '<div class="split-button">';
                $strButtons .= array_shift($arrButtons).'<button type="button" id="sbtog">'.Image::getHtml('navcol.svg').'</button> <ul class="invisible">';

                foreach ($arrButtons as $strButton) {
                    $strButtons .= '<li>'.$strButton.'</li>';
                }

                $strButtons .= '</ul></div>';
            }

            $return .= '
</div>
<div class="tl_formbody_submit" style="text-align:right">
<div class="tl_submit_container">
  '.$strButtons.'
</div>
</div>
</form>';
        }

        return $return;
    }

    /**
     * Recursively generate the tree and return it as HTML string.
     *
     * @param string $table
     * @param int    $id
     * @param array  $arrPrevNext
     * @param bool   $blnHasSorting
     * @param int    $intMargin
     * @param array  $arrClipboard
     * @param bool   $blnCircularReference
     * @param bool   $protectedPage
     * @param bool   $blnNoRecursion
     * @param array  $arrFound
     *
     * @return string
     */
    protected function generateTree($table, $id, $arrPrevNext, $blnHasSorting, $intMargin = 0, $arrClipboard = null, $blnCircularReference = false, $protectedPage = false, $blnNoRecursion = false, $arrFound = [])
    {
        /** @var AttributeBagInterface $objSessionBag */
        $objSessionBag = System::getContainer()->get('session')->getBag('contao_backend');

        $session = $objSessionBag->all();
        $node = ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) === 6 ? $this->strTable.'_'.$table.'_tree' : $this->strTable.'_tree';

        // Toggle nodes
        if (Input::get('ptg')) {
            $session[$node][Input::get('ptg')] = isset($session[$node][Input::get('ptg')]) && 1 === $session[$node][Input::get('ptg')] ? 0 : 1;
            $objSessionBag->replace($session);
            $this->redirect(preg_replace('/(&(amp;)?|\?)ptg=[^& ]*/i', '', Environment::get('request')));
        }

        $objRow = Database::getInstance()->prepare('SELECT * FROM '.$table.' WHERE id=?')
            ->limit(1)
            ->execute($id)
        ;

        // Return if there is no result
        if ($objRow->numRows < 1) {
            $objSessionBag->replace($session);

            return '';
        }

        $return = '';
        $intSpacing = 20;
        $childs = [];

        // Add the ID to the list of current IDs
        if ($this->strTable === $table) {
            $this->current[] = $objRow->id;
        }

        // Check whether there are child records
        if (!$blnNoRecursion) {
            if ($this->strTable !== $table || ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) === 5) {
                $objChilds = Database::getInstance()->prepare('SELECT id FROM '.$table.' WHERE pid=?'.(!empty($arrFound) ? ' AND id IN('.implode(',', array_map('\intval', $arrFound)).')' : '').($blnHasSorting ? ' ORDER BY sorting' : ''))
                    ->execute($id)
                ;

                if ($objChilds->numRows) {
                    $childs = $objChilds->fetchEach('id');
                }
            }
        }

        $blnProtected = false;

        // Check whether the page is protected
        if ('tl_page' === $table) {
            $blnProtected = ($objRow->protected || $protectedPage);
        }

        $session[$node][$id] = \is_int($session[$node][$id] ?? null) ? $session[$node][$id] : 0;
        $mouseover = ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) === 5 || $table === $this->strTable ? ' toggle_select hover-div' : '';

        $return .= "\n  ".'<li class="'.((($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) === 5 && 'root' === $objRow->type) || $table !== $this->strTable ? 'tl_folder' : 'tl_file').' click2edit'.$mouseover.' cf"><div class="tl_left" style="padding-left:'.($intMargin + $intSpacing + (empty($childs) ? 20 : 0)).'px">';

        // Calculate label and add a toggle button
        $args = [];
        $showFields = $GLOBALS['TL_DCA'][$table]['list']['label']['fields'];
        $level = $intMargin / $intSpacing + 1;
        $blnIsOpen = (!empty($arrFound) || 1 === $session[$node][$id]);

        // Always show selected nodes
        if (!$blnIsOpen && !empty($this->arrPickerValue) && (($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) === 5 || $table !== $this->strTable)) {
            $selected = $this->arrPickerValue;

            if (($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) === 6) {
                $selected = Database::getInstance()->execute("SELECT pid FROM {$this->strTable} WHERE id IN (".implode(',', array_map('\intval', $this->arrPickerValue)).')')
                    ->fetchEach('pid')
                ;
            }

            if (!empty(array_intersect(Database::getInstance()->getChildRecords([$id], $table), $selected))) {
                $blnIsOpen = true;
            }
        }

        if (!empty($childs)) {
            $img = $blnIsOpen ? 'folMinus.svg' : 'folPlus.svg';
            $alt = $blnIsOpen ? $GLOBALS['TL_LANG']['MSC']['collapseNode'] : $GLOBALS['TL_LANG']['MSC']['expandNode'];
            $return .= '<a href="'.Backend::addToUrl('ptg='.$id).'" title="'.StringUtil::specialchars($alt).'" onclick="Backend.getScrollOffset();return AjaxRequest.toggleStructure(this,\''.$node.'_'.$id.'\','.$level.','.($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? '').')">'.Image::getHtml($img, '', 'style="margin-right:2px"').'</a>';
        }

        foreach ($showFields as $k => $v) {
            // Decrypt the value
            if ($GLOBALS['TL_DCA'][$table]['fields'][$v]['eval']['encrypt'] ?? null) {
                $objRow->$v = Encryption::decrypt(StringUtil::deserialize($objRow->$v));
            }

            if (false !== strpos($v, ':')) {
                [$strKey, $strTable] = explode(':', $v, 2);
                [$strTable, $strField] = explode('.', $strTable, 2);

                $objRef = Database::getInstance()->prepare('SELECT '.Database::quoteIdentifier($strField).' FROM '.$strTable.' WHERE id=?')
                    ->limit(1)
                    ->execute($objRow->$strKey)
                ;

                $args[$k] = $objRef->numRows ? $objRef->$strField : '';
            } elseif (\in_array($GLOBALS['TL_DCA'][$table]['fields'][$v]['flag'] ?? null, [5, 6, 7, 8, 9, 10], true)) {
                $args[$k] = Date::parse(Config::get('datimFormat'), $objRow->$v);
            } elseif (($GLOBALS['TL_DCA'][$table]['fields'][$v]['inputType'] ?? null) === 'checkbox' && !($GLOBALS['TL_DCA'][$table]['fields'][$v]['eval']['multiple'] ?? null)) {
                $args[$k] = $objRow->$v ? ($GLOBALS['TL_DCA'][$table]['fields'][$v]['label'][0] ?? $v) : '';
            } else {
                $args[$k] = !empty($GLOBALS['TL_DCA'][$table]['fields'][$v]['reference'][$objRow->$v]) ? $GLOBALS['TL_DCA'][$table]['fields'][$v]['reference'][$objRow->$v] : $objRow->$v;
            }
        }

        $label = vsprintf($GLOBALS['TL_DCA'][$table]['list']['label']['format'] ?? '%s', $args);

        // Shorten the label if it is too long
        if (($GLOBALS['TL_DCA'][$table]['list']['label']['maxCharacters'] ?? null) > 0 && $GLOBALS['TL_DCA'][$table]['list']['label']['maxCharacters'] < Utf8::strlen(strip_tags($label))) {
            $label = trim(StringUtil::substrHtml($label, $GLOBALS['TL_DCA'][$table]['list']['label']['maxCharacters'])).' …';
        }

        $label = preg_replace('/\(\) ?|\[] ?|{} ?|<> ?/', '', $label);

        // Call the label_callback ($row, $label, $this)
        if (\is_array($GLOBALS['TL_DCA'][$table]['list']['label']['label_callback'] ?? null)) {
            $strClass = $GLOBALS['TL_DCA'][$table]['list']['label']['label_callback'][0];
            $strMethod = $GLOBALS['TL_DCA'][$table]['list']['label']['label_callback'][1];

            $this->import($strClass);
            $return .= $this->$strClass->$strMethod($objRow->row(), $label, $this, '', false, $blnProtected);
        } elseif (\is_callable($GLOBALS['TL_DCA'][$table]['list']['label']['label_callback'] ?? null)) {
            $return .= $GLOBALS['TL_DCA'][$table]['list']['label']['label_callback']($objRow->row(), $label, $this, '', false, $blnProtected);
        } else {
            $return .= Image::getHtml('iconPLAIN.svg').' '.$label;
        }

        $return .= '</div> <div class="tl_right">';
        $previous = ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) === 6 ? ($arrPrevNext['pp'] ?? null) : ($arrPrevNext['p'] ?? null);
        $next = ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) === 6 ? ($arrPrevNext['nn'] ?? null) : ($arrPrevNext['n'] ?? null);
        $_buttons = '';

        // Regular buttons ($row, $table, $root, $blnCircularReference, $childs, $previous, $next)
        if ($this->strTable === $table) {
            $_buttons .= 'select' === Input::get('act') ? '<input type="checkbox" name="IDS[]" id="ids_'.$id.'" class="tl_tree_checkbox" value="'.$id.'">' : $this->generateButtons($objRow->row(), $table, $this->root, $blnCircularReference, $childs, $previous, $next);

            if ($this->strPickerFieldType) {
                $_buttons .= $this->getPickerInputField($id);
            }
        }

        // Paste buttons
        if (false !== $arrClipboard && 'select' !== Input::get('act')) {
            $_buttons .= ' ';

            // Call paste_button_callback(&$dc, $row, $table, $blnCircularReference, $arrClipboard, $childs, $previous, $next)
            if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['paste_button_callback'] ?? null)) {
                $strClass = $GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['paste_button_callback'][0];
                $strMethod = $GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['paste_button_callback'][1];

                $this->import($strClass);
                $_buttons .= $this->$strClass->$strMethod($this, $objRow->row(), $table, $blnCircularReference, $arrClipboard, $childs, $previous, $next);
            } elseif (\is_callable($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['paste_button_callback'] ?? null)) {
                $_buttons .= $GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['paste_button_callback']($this, $objRow->row(), $table, $blnCircularReference, $arrClipboard, $childs, $previous, $next);
            } else {
                $labelPasteAfter = $GLOBALS['TL_LANG'][$this->strTable]['pasteafter'] ?? $GLOBALS['TL_LANG']['DCA']['pasteafter'];
                $imagePasteAfter = Image::getHtml('pasteafter.svg', sprintf($labelPasteAfter[1], $id));

                $labelPasteInto = $GLOBALS['TL_LANG'][$this->strTable]['pasteinto'] ?? $GLOBALS['TL_LANG']['DCA']['pasteinto'];
                $imagePasteInto = Image::getHtml('pasteinto.svg', sprintf($labelPasteInto[1], $id));

                // Regular tree (on cut: disable buttons of the page and all its childs to avoid circular references)
                if (($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) === 5) {
                    $_buttons .= ('cut' === $arrClipboard['mode'] && ($blnCircularReference || $arrClipboard['id'] === $id)) || ('cutAll' === $arrClipboard['mode'] && ($blnCircularReference || \in_array($id, $arrClipboard['id'], true))) || (!empty($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['root']) && !$GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['rootPaste'] && \in_array($id, $this->root, true)) ? Image::getHtml('pasteafter_.svg').' ' : '<a href="'.Backend::addToUrl('act='.$arrClipboard['mode'].'&amp;mode=1&amp;pid='.$id.(!\is_array($arrClipboard['id']) ? '&amp;id='.$arrClipboard['id'] : '')).'" title="'.StringUtil::specialchars(sprintf($labelPasteAfter[1], $id)).'" onclick="Backend.getScrollOffset()">'.$imagePasteAfter.'</a> ';
                    $_buttons .= ('cut' === $arrClipboard['mode'] && ($blnCircularReference || $arrClipboard['id'] === $id)) || ('cutAll' === $arrClipboard['mode'] && ($blnCircularReference || \in_array($id, $arrClipboard['id'], true))) ? Image::getHtml('pasteinto_.svg').' ' : '<a href="'.Backend::addToUrl('act='.$arrClipboard['mode'].'&amp;mode=2&amp;pid='.$id.(!\is_array($arrClipboard['id']) ? '&amp;id='.$arrClipboard['id'] : '')).'" title="'.StringUtil::specialchars(sprintf($labelPasteInto[1], $id)).'" onclick="Backend.getScrollOffset()">'.$imagePasteInto.'</a> ';
                }

                // Extended tree
                else {
                    $_buttons .= $this->strTable === $table ? (('cut' === $arrClipboard['mode'] && ($blnCircularReference || $arrClipboard['id'] === $id)) || ('cutAll' === $arrClipboard['mode'] && ($blnCircularReference || \in_array($id, $arrClipboard['id'], true))) ? Image::getHtml('pasteafter_.svg') : '<a href="'.Backend::addToUrl('act='.$arrClipboard['mode'].'&amp;mode=1&amp;pid='.$id.(!\is_array($arrClipboard['id']) ? '&amp;id='.$arrClipboard['id'] : '')).'" title="'.StringUtil::specialchars(sprintf($labelPasteAfter[1], $id)).'" onclick="Backend.getScrollOffset()">'.$imagePasteAfter.'</a> ') : '';
                    $_buttons .= $this->strTable !== $table ? '<a href="'.Backend::addToUrl('act='.$arrClipboard['mode'].'&amp;mode=2&amp;pid='.$id.(!\is_array($arrClipboard['id']) ? '&amp;id='.$arrClipboard['id'] : '')).'" title="'.StringUtil::specialchars(sprintf($labelPasteInto[1], $id)).'" onclick="Backend.getScrollOffset()">'.$imagePasteInto.'</a> ' : '';
                }
            }
        }

        $return .= ($_buttons ?: '&nbsp;').'</div></li>';

        // Add the records of the table itself
        if ($table !== $this->strTable) {
            // Also apply the filter settings to the child table (see #716)
            if (!empty($this->procedure) && ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) === 6) {
                $arrValues = $this->values;
                array_unshift($arrValues, $id);

                $objChilds = Database::getInstance()->prepare('SELECT id FROM '.$this->strTable.' WHERE pid=? AND '.implode(' AND ', $this->procedure).($blnHasSorting ? ' ORDER BY sorting' : ''))
                    ->execute($arrValues)
                ;
            } else {
                $objChilds = Database::getInstance()->prepare('SELECT id FROM '.$this->strTable.' WHERE pid=?'.($blnHasSorting ? ' ORDER BY sorting' : ''))
                    ->execute($id)
                ;
            }

            if ($objChilds->numRows) {
                $ids = $objChilds->fetchEach('id');

                for ($j = 0, $c = \count($ids); $j < $c; ++$j) {
                    $return .= $this->generateTree($this->strTable, $ids[$j], ['pp' => ($ids[$j - 1] ?? null), 'nn' => ($ids[$j + 1] ?? null)], $blnHasSorting, $intMargin + $intSpacing, $arrClipboard, false, ($j < (\count($ids) - 1) || !empty($childs)), $blnNoRecursion, $arrFound);
                }
            }
        }

        // Begin a new submenu
        if (!$blnNoRecursion) {
            $blnAddParent = ($blnIsOpen || !empty($arrFound) || (!empty($childs) && 1 === $session[$node][$id]));

            if ($blnAddParent) {
                $return .= '<li class="parent" id="'.$node.'_'.$id.'"><ul class="level_'.$level.'">';
            }

            // Add the records of the parent table
            if ($blnIsOpen && \is_array($childs)) {
                for ($k = 0, $c = \count($childs); $k < $c; ++$k) {
                    $return .= $this->generateTree($table, $childs[$k], ['p' => ($childs[$k - 1] ?? null), 'n' => ($childs[$k + 1] ?? null)], $blnHasSorting, $intMargin + $intSpacing, $arrClipboard, ((($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) === 5 && \is_array($arrClipboard) && $childs[$k] === $arrClipboard['id']) || $blnCircularReference), ($blnProtected || $protectedPage), $blnNoRecursion, $arrFound);
                }
            }

            // Close the submenu
            if ($blnAddParent) {
                $return .= '</ul></li>';
            }
        }

        $objSessionBag->replace($session);

        return $return;
    }

    /**
     * Show header of the parent table and list all records of the current table.
     *
     * @return string
     */
    protected function parentView()
    {
        /** @var Session $objSession */
        $objSession = System::getContainer()->get('session');

        $blnClipboard = false;
        $arrClipboard = $objSession->get('CLIPBOARD');
        $table = ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) === 6 ? $this->ptable : $this->strTable;
        $blnHasSorting = ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['fields'][0] ?? null) === 'sorting';
        $blnMultiboard = false;

        // Check clipboard
        if (!empty($arrClipboard[$table])) {
            $blnClipboard = true;
            $arrClipboard = $arrClipboard[$table];

            if (\is_array($arrClipboard['id'] ?? null)) {
                $blnMultiboard = true;
            }
        } else {
            $arrClipboard = null;
        }

        // Load the language file and data container array of the parent table
        System::loadLanguageFile($this->ptable);
        $this->loadDataContainer($this->ptable);

        // Check the default labels (see #509)
        $labelNew = $GLOBALS['TL_LANG'][$this->strTable]['new'] ?? $GLOBALS['TL_LANG']['DCA']['new'];
        $labelCut = $GLOBALS['TL_LANG'][$this->strTable]['cut'] ?? $GLOBALS['TL_LANG']['DCA']['cut'];
        $labelPasteNew = $GLOBALS['TL_LANG'][$this->strTable]['pastenew'] ?? $GLOBALS['TL_LANG']['DCA']['pastenew'];
        $labelPasteAfter = $GLOBALS['TL_LANG'][$this->strTable]['pasteafter'] ?? $GLOBALS['TL_LANG']['DCA']['pasteafter'];
        $labelEditHeader = $GLOBALS['TL_LANG'][$this->ptable]['editmeta'] ?? $GLOBALS['TL_LANG'][$this->strTable]['editheader'] ?? $GLOBALS['TL_LANG']['DCA']['editheader'];

        $return = Message::generate().'
<div id="tl_buttons">'.(Input::get('nb') ? '&nbsp;' : ($this->ptable ? '
<a href="'.Backend::getReferer(true, $this->ptable).'" class="header_back" title="'.StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['backBTTitle']).'" accesskey="b" onclick="Backend.getScrollOffset()">'.$GLOBALS['TL_LANG']['MSC']['backBT'].'</a>' : (isset($GLOBALS['TL_DCA'][$this->strTable]['config']['backlink']) ? '
<a href="contao/main.php?'.$GLOBALS['TL_DCA'][$this->strTable]['config']['backlink'].'" class="header_back" title="'.StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['backBTTitle']).'" accesskey="b" onclick="Backend.getScrollOffset()">'.$GLOBALS['TL_LANG']['MSC']['backBT'].'</a>' : ''))).' '.('select' !== Input::get('act') && !$blnClipboard && !($GLOBALS['TL_DCA'][$this->strTable]['config']['closed'] ?? null) && !($GLOBALS['TL_DCA'][$this->strTable]['config']['notCreatable'] ?? null) ? '
<a href="'.Backend::addToUrl(($blnHasSorting ? 'act=paste&amp;mode=create' : 'act=create&amp;mode=2&amp;pid='.$this->intId)).'" class="header_new" title="'.StringUtil::specialchars($labelNew[1]).'" accesskey="n" onclick="Backend.getScrollOffset()">'.$labelNew[0].'</a> ' : '').($blnClipboard ? '
<a href="'.Backend::addToUrl('clipboard=1').'" class="header_clipboard" title="'.StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['clearClipboard']).'" accesskey="x">'.$GLOBALS['TL_LANG']['MSC']['clearClipboard'].'</a> ' : $this->generateGlobalButtons()).'
</div>';

        // Get all details of the parent record
        $objParent = Database::getInstance()->prepare('SELECT * FROM '.$this->ptable.' WHERE id=?')
            ->limit(1)
            ->execute(CURRENT_ID)
        ;

        if ($objParent->numRows < 1) {
            return $return;
        }

        $return .= ('select' === Input::get('act') ? '

<form id="tl_select" class="tl_form'.('select' === Input::get('act') ? ' unselectable' : '').'" method="post" novalidate>
<div class="tl_formbody_edit">
<input type="hidden" name="FORM_SUBMIT" value="tl_select">
<input type="hidden" name="REQUEST_TOKEN" value="'.REQUEST_TOKEN.'">' : '').($blnClipboard ? '
<div id="paste_hint" data-add-to-scroll-offset="20">
  <p>'.$GLOBALS['TL_LANG']['MSC']['selectNewPosition'].'</p>
</div>' : '').'
<div class="tl_listing_container parent_view'.($this->strPickerFieldType ? ' picker unselectable' : '').'" id="tl_listing"'.$this->getPickerValueAttribute().'>
<div class="tl_header click2edit toggle_select hover-div">';

        // List all records of the child table
        if (!Input::get('act') || \in_array(Input::get('act'), ['paste', 'select'], true)) {
            $this->import(BackendUser::class, 'User');

            // Header
            $imagePasteNew = Image::getHtml('new.svg', $labelPasteNew[0]);
            $imagePasteAfter = Image::getHtml('pasteafter.svg', $labelPasteAfter[0]);
            $imageEditHeader = Image::getHtml('header.svg', sprintf(\is_array($labelEditHeader) ? $labelEditHeader[0] : $labelEditHeader, $objParent->id));

            $return .= '
<div class="tl_content_right">'.('select' === Input::get('act') || 'checkbox' === $this->strPickerFieldType ? '
<label for="tl_select_trigger" class="tl_select_label">'.$GLOBALS['TL_LANG']['MSC']['selectAll'].'</label> <input type="checkbox" id="tl_select_trigger" onclick="Backend.toggleCheckboxes(this)" class="tl_tree_checkbox">' : ($blnClipboard ? '
<a href="'.Backend::addToUrl('act='.$arrClipboard['mode'].'&amp;mode=2&amp;pid='.$objParent->id.(!$blnMultiboard ? '&amp;id='.$arrClipboard['id'] : '')).'" title="'.StringUtil::specialchars($labelPasteAfter[0]).'" onclick="Backend.getScrollOffset()">'.$imagePasteAfter.'</a>' : (!($GLOBALS['TL_DCA'][$this->ptable]['config']['notEditable'] ?? null) && $this->User->canEditFieldsOf($this->ptable) ? '
<a href="'.preg_replace('/&(amp;)?table=[^& ]*/i', ($this->ptable ? '&amp;table='.$this->ptable : ''), Backend::addToUrl('act=edit'.(Input::get('nb') ? '&amp;nc=1' : ''))).'" class="edit" title="'.StringUtil::specialchars(sprintf(\is_array($labelEditHeader) ? $labelEditHeader[1] : $labelEditHeader, $objParent->id)).'">'.$imageEditHeader.'</a> '.$this->generateHeaderButtons($objParent->row(), $this->ptable) : '').($blnHasSorting && !($GLOBALS['TL_DCA'][$this->strTable]['config']['closed'] ?? null) && !($GLOBALS['TL_DCA'][$this->strTable]['config']['notCreatable'] ?? null) ? '
<a href="'.Backend::addToUrl('act=create&amp;mode=2&amp;pid='.$objParent->id.'&amp;id='.$this->intId).'" title="'.StringUtil::specialchars($labelPasteNew[0]).'">'.$imagePasteNew.'</a>' : ''))).'
</div>';

            // Format header fields
            $add = [];
            $headerFields = $GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['headerFields'];

            foreach ($headerFields as $v) {
                $_v = StringUtil::deserialize($objParent->$v);

                // Translate UUIDs to paths
                if (($GLOBALS['TL_DCA'][$this->ptable]['fields'][$v]['inputType'] ?? null) === 'fileTree') {
                    $objFiles = FilesModel::findMultipleByUuids((array) $_v);

                    if (null !== $objFiles) {
                        $_v = $objFiles->fetchEach('path');
                    }
                }

                if (\is_array($_v)) {
                    $_v = implode(', ', $_v);
                } elseif (($GLOBALS['TL_DCA'][$this->ptable]['fields'][$v]['eval']['isBoolean'] ?? null) || (($GLOBALS['TL_DCA'][$this->ptable]['fields'][$v]['inputType'] ?? null) === 'checkbox' && !($GLOBALS['TL_DCA'][$this->ptable]['fields'][$v]['eval']['multiple'] ?? null))) {
                    $_v = $_v ? $GLOBALS['TL_LANG']['MSC']['yes'] : $GLOBALS['TL_LANG']['MSC']['no'];
                } elseif (($GLOBALS['TL_DCA'][$this->ptable]['fields'][$v]['eval']['rgxp'] ?? null) === 'date') {
                    $_v = $_v ? Date::parse(Config::get('dateFormat'), $_v) : '-';
                } elseif (($GLOBALS['TL_DCA'][$this->ptable]['fields'][$v]['eval']['rgxp'] ?? null) === 'time') {
                    $_v = $_v ? Date::parse(Config::get('timeFormat'), $_v) : '-';
                } elseif (($GLOBALS['TL_DCA'][$this->ptable]['fields'][$v]['eval']['rgxp'] ?? null) === 'datim') {
                    $_v = $_v ? Date::parse(Config::get('datimFormat'), $_v) : '-';
                } elseif ('tstamp' === $v) {
                    $_v = Date::parse(Config::get('datimFormat'), $objParent->tstamp);
                } elseif (isset($GLOBALS['TL_DCA'][$this->ptable]['fields'][$v]['foreignKey'])) {
                    $arrForeignKey = explode('.', $GLOBALS['TL_DCA'][$this->ptable]['fields'][$v]['foreignKey'], 2);

                    $objLabel = Database::getInstance()->prepare('SELECT '.Database::quoteIdentifier($arrForeignKey[1]).' AS value FROM '.$arrForeignKey[0].' WHERE id=?')
                        ->limit(1)
                        ->execute($_v)
                    ;

                    $_v = $objLabel->numRows ? $objLabel->value : '-';
                } elseif (\is_array($GLOBALS['TL_DCA'][$this->ptable]['fields'][$v]['reference'][$_v] ?? null)) {
                    $_v = $GLOBALS['TL_DCA'][$this->ptable]['fields'][$v]['reference'][$_v][0];
                } elseif (isset($GLOBALS['TL_DCA'][$this->ptable]['fields'][$v]['reference'][$_v])) {
                    $_v = $GLOBALS['TL_DCA'][$this->ptable]['fields'][$v]['reference'][$_v];
                } elseif (($GLOBALS['TL_DCA'][$this->ptable]['fields'][$v]['eval']['isAssociative'] ?? null) || ArrayUtil::isAssoc($GLOBALS['TL_DCA'][$this->ptable]['fields'][$v]['options'] ?? null)) {
                    $_v = $GLOBALS['TL_DCA'][$this->ptable]['fields'][$v]['options'][$_v] ?? null;
                } elseif (\is_array($GLOBALS['TL_DCA'][$this->ptable]['fields'][$v]['options_callback'] ?? null)) {
                    $strClass = $GLOBALS['TL_DCA'][$this->ptable]['fields'][$v]['options_callback'][0];
                    $strMethod = $GLOBALS['TL_DCA'][$this->ptable]['fields'][$v]['options_callback'][1];

                    $this->import($strClass);
                    $options_callback = $this->$strClass->$strMethod($this);

                    $_v = $options_callback[$_v];
                } elseif (\is_callable($GLOBALS['TL_DCA'][$this->ptable]['fields'][$v]['options_callback'] ?? null)) {
                    $options_callback = $GLOBALS['TL_DCA'][$this->ptable]['fields'][$v]['options_callback']($this);

                    $_v = $options_callback[$_v];
                }

                // Add the sorting field
                if ($_v) {
                    if (isset($GLOBALS['TL_DCA'][$this->ptable]['fields'][$v]['label'])) {
                        $key = \is_array($GLOBALS['TL_DCA'][$this->ptable]['fields'][$v]['label']) ? $GLOBALS['TL_DCA'][$this->ptable]['fields'][$v]['label'][0] : $GLOBALS['TL_DCA'][$this->ptable]['fields'][$v]['label'];
                    } else {
                        $key = $GLOBALS['TL_LANG'][$this->ptable][$v][0] ?? $v;
                    }

                    $add[$key] = $_v;
                }
            }

            // Trigger the header_callback (see #3417)
            if (\is_array($GLOBALS['TL_DCA'][$table]['list']['sorting']['header_callback'] ?? null)) {
                $strClass = $GLOBALS['TL_DCA'][$table]['list']['sorting']['header_callback'][0];
                $strMethod = $GLOBALS['TL_DCA'][$table]['list']['sorting']['header_callback'][1];

                $this->import($strClass);
                $add = $this->$strClass->$strMethod($add, $this);
            } elseif (\is_callable($GLOBALS['TL_DCA'][$table]['list']['sorting']['header_callback'] ?? null)) {
                $add = $GLOBALS['TL_DCA'][$table]['list']['sorting']['header_callback']($add, $this);
            }

            // Output the header data
            $return .= '

<table class="tl_header_table">';

            foreach ($add as $k => $v) {
                if (\is_array($v)) {
                    $v = $v[0];
                }

                $return .= '
  <tr>
    <td><span class="tl_label">'.$k.':</span> </td>
    <td>'.$v.'</td>
  </tr>';
            }

            $return .= '
</table>
</div>';

            $orderBy = [];
            $firstOrderBy = [];

            // Add all records of the current table
            $query = 'SELECT * FROM '.$this->strTable;

            if (\is_array($this->orderBy) && isset($this->orderBy[0])) {
                $orderBy = $this->orderBy;
                $firstOrderBy = preg_replace('/\s+.*$/', '', $orderBy[0]);

                // Order by the foreign key
                if (isset($GLOBALS['TL_DCA'][$this->strTable]['fields'][$firstOrderBy]['foreignKey'])) {
                    $key = explode('.', $GLOBALS['TL_DCA'][$this->strTable]['fields'][$firstOrderBy]['foreignKey'], 2);
                    $query = 'SELECT *, (SELECT '.Database::quoteIdentifier($key[1]).' FROM '.$key[0].' WHERE '.$this->strTable.'.'.Database::quoteIdentifier($firstOrderBy).'='.$key[0].'.id) AS foreignKey FROM '.$this->strTable;
                    $orderBy[0] = 'foreignKey';
                }
            } elseif (\is_array($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['fields'] ?? null)) {
                $orderBy = $GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['fields'];
                $firstOrderBy = preg_replace('/\s+.*$/', '', $orderBy[0]);
            }

            $arrProcedure = $this->procedure;
            $arrValues = $this->values;

            // Support empty ptable fields
            if ($GLOBALS['TL_DCA'][$this->strTable]['config']['dynamicPtable'] ?? null) {
                $arrProcedure[] = 'tl_article' === $this->ptable ? "(ptable=? OR ptable='')" : 'ptable=?';
                $arrValues[] = $this->ptable;
            }

            // WHERE
            if (!empty($arrProcedure)) {
                $query .= ' WHERE '.implode(' AND ', $arrProcedure);
            }

            if (!empty($this->root) && \is_array($this->root)) {
                $query .= (!empty($arrProcedure) ? ' AND ' : ' WHERE ').'id IN('.implode(',', array_map('\intval', $this->root)).')';
            }

            // ORDER BY
            if (!empty($orderBy) && \is_array($orderBy)) {
                foreach ($orderBy as $k => $v) {
                    if (isset($GLOBALS['TL_DCA'][$this->strTable]['fields'][$v]['flag']) && ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$v]['flag'] % 2) === 0) {
                        $orderBy[$k] .= ' DESC';
                    }
                }

                $query .= ' ORDER BY '.implode(', ', $orderBy);
            }

            $objOrderByStmt = Database::getInstance()->prepare($query);

            // LIMIT
            if ($this->limit) {
                $arrLimit = explode(',', $this->limit) + [null, null];
                $objOrderByStmt->limit($arrLimit[1], $arrLimit[0]);
            }

            $objOrderBy = $objOrderByStmt->execute($arrValues);

            if ($objOrderBy->numRows < 1) {
                return $return.'
<p class="tl_empty_parent_view">'.$GLOBALS['TL_LANG']['MSC']['noResult'].'</p>
</div>';
            }

            // Call the child_record_callback
            if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['child_record_callback'] ?? null) || \is_callable($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['child_record_callback'] ?? null)) {
                $strGroup = '';
                $blnIndent = false;
                $intWrapLevel = 0;
                $row = $objOrderBy->fetchAllAssoc();

                // Make items sortable
                if ($blnHasSorting) {
                    $return .= '

<ul id="ul_'.CURRENT_ID.'">';
                }

                for ($i = 0, $c = \count($row); $i < $c; ++$i) {
                    $this->current[] = $row[$i]['id'];
                    $imagePasteAfter = Image::getHtml('pasteafter.svg', sprintf($labelPasteAfter[1] ?? $labelPasteAfter[0], $row[$i]['id']));
                    $imagePasteNew = Image::getHtml('new.svg', sprintf($labelPasteNew[1] ?? $labelPasteNew[0], $row[$i]['id']));

                    // Decrypt encrypted value
                    foreach ($row[$i] as $k => $v) {
                        if ($GLOBALS['TL_DCA'][$table]['fields'][$k]['eval']['encrypt'] ?? null) {
                            $row[$i][$k] = Encryption::decrypt(StringUtil::deserialize($v));
                        }
                    }

                    // Make items sortable
                    if ($blnHasSorting) {
                        $return .= '
<li id="li_'.$row[$i]['id'].'">';
                    }

                    // Add the group header
                    if ('sorting' !== $firstOrderBy && !($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['disableGrouping'] ?? null)) {
                        $sortingMode = 1 === \count($orderBy) && $firstOrderBy === $orderBy[0] && ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['flag'] ?? null) && !($GLOBALS['TL_DCA'][$this->strTable]['fields'][$firstOrderBy]['flag'] ?? null) ? $GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['flag'] : $GLOBALS['TL_DCA'][$this->strTable]['fields'][$firstOrderBy]['flag'];
                        $remoteNew = $this->formatCurrentValue($firstOrderBy, $row[$i][$firstOrderBy], $sortingMode);
                        $group = $this->formatGroupHeader($firstOrderBy, $remoteNew, $sortingMode, $row[$i]);

                        if ($group !== $strGroup) {
                            $return .= "\n\n".'<div class="tl_content_header">'.$group.'</div>';
                            $strGroup = $group;
                        }
                    }

                    $blnWrapperStart = isset($row[$i]['type']) && \in_array($row[$i]['type'], $GLOBALS['TL_WRAPPERS']['start'], true);
                    $blnWrapperSeparator = isset($row[$i]['type']) && \in_array($row[$i]['type'], $GLOBALS['TL_WRAPPERS']['separator'], true);
                    $blnWrapperStop = isset($row[$i]['type']) && \in_array($row[$i]['type'], $GLOBALS['TL_WRAPPERS']['stop'], true);
                    $blnIndentFirst = isset($row[$i - 1]['type']) && \in_array($row[$i - 1]['type'], $GLOBALS['TL_WRAPPERS']['start'], true);
                    $blnIndentLast = isset($row[$i + 1]['type']) && \in_array($row[$i + 1]['type'], $GLOBALS['TL_WRAPPERS']['stop'], true);

                    // Closing wrappers
                    if ($blnWrapperStop && --$intWrapLevel < 1) {
                        $blnIndent = false;
                    }

                    $return .= '
<div class="tl_content'.($blnWrapperStart ? ' wrapper_start' : '').($blnWrapperSeparator ? ' wrapper_separator' : '').($blnWrapperStop ? ' wrapper_stop' : '').($blnIndent ? ' indent indent_'.$intWrapLevel : '').($blnIndentFirst ? ' indent_first' : '').($blnIndentLast ? ' indent_last' : '').(!empty($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['child_record_class']) ? ' '.$GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['child_record_class'] : '').(0 === $i % 2 ? ' even' : ' odd').' click2edit toggle_select hover-div">
<div class="tl_content_right">';

                    // Opening wrappers
                    if ($blnWrapperStart && ++$intWrapLevel > 0) {
                        $blnIndent = true;
                    }

                    // Edit multiple
                    if ('select' === Input::get('act')) {
                        $return .= '<input type="checkbox" name="IDS[]" id="ids_'.$row[$i]['id'].'" class="tl_tree_checkbox" value="'.$row[$i]['id'].'">';
                    }

                    // Regular buttons
                    else {
                        $return .= $this->generateButtons($row[$i], $this->strTable, $this->root, false, null, ($row[$i - 1]['id'] ?? null), ($row[$i + 1]['id'] ?? null));

                        // Sortable table
                        if ($blnHasSorting) {
                            // Create new button
                            if (!($GLOBALS['TL_DCA'][$this->strTable]['config']['closed'] ?? null) && !($GLOBALS['TL_DCA'][$this->strTable]['config']['notCreatable'] ?? null)) {
                                $return .= ' <a href="'.Backend::addToUrl('act=create&amp;mode=1&amp;pid='.$row[$i]['id'].'&amp;id='.$objParent->id.(Input::get('nb') ? '&amp;nc=1' : '')).'" title="'.StringUtil::specialchars(sprintf($labelPasteNew[1], $row[$i]['id'])).'">'.$imagePasteNew.'</a>';
                            }

                            // Prevent circular references
                            if (($blnClipboard && 'cut' === $arrClipboard['mode'] && $row[$i]['id'] === $arrClipboard['id']) || ($blnMultiboard && 'cutAll' === $arrClipboard['mode'] && \in_array($row[$i]['id'], $arrClipboard['id'], true))) {
                                $return .= ' '.Image::getHtml('pasteafter_.svg');
                            }

                            // Copy/move multiple
                            elseif ($blnMultiboard) {
                                $return .= ' <a href="'.Backend::addToUrl('act='.$arrClipboard['mode'].'&amp;mode=1&amp;pid='.$row[$i]['id']).'" title="'.StringUtil::specialchars(sprintf($labelPasteAfter[1], $row[$i]['id'])).'" onclick="Backend.getScrollOffset()">'.$imagePasteAfter.'</a>';
                            }

                            // Paste buttons
                            elseif ($blnClipboard) {
                                $return .= ' <a href="'.Backend::addToUrl('act='.$arrClipboard['mode'].'&amp;mode=1&amp;pid='.$row[$i]['id'].'&amp;id='.$arrClipboard['id']).'" title="'.StringUtil::specialchars(sprintf($labelPasteAfter[1], $row[$i]['id'])).'" onclick="Backend.getScrollOffset()">'.$imagePasteAfter.'</a>';
                            }

                            // Drag handle
                            if (!($GLOBALS['TL_DCA'][$this->strTable]['config']['notSortable'] ?? null)) {
                                $return .= ' <button type="button" class="drag-handle" title="'.StringUtil::specialchars(sprintf(\is_array($labelCut) ? $labelCut[1] : $labelCut, $row[$i]['id'])).'" aria-hidden="true">'.Image::getHtml('drag.svg').'</button>';
                            }
                        }

                        // Picker
                        if ($this->strPickerFieldType) {
                            $return .= $this->getPickerInputField($row[$i]['id']);
                        }
                    }

                    if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['child_record_callback'] ?? null)) {
                        $strClass = $GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['child_record_callback'][0];
                        $strMethod = $GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['child_record_callback'][1];

                        $this->import($strClass);
                        $return .= '</div>'.$this->$strClass->$strMethod($row[$i]).'</div>';
                    } elseif (\is_callable($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['child_record_callback'] ?? null)) {
                        $return .= '</div>'.$GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['child_record_callback']($row[$i]).'</div>';
                    }

                    // Make items sortable
                    if ($blnHasSorting) {
                        $return .= '
</li>';
                    }
                }
            }
        }

        // Make items sortable
        if ($blnHasSorting && !($GLOBALS['TL_DCA'][$this->strTable]['config']['notSortable'] ?? null) && 'select' !== Input::get('act')) {
            $return .= '
</ul>
<script>
  Backend.makeParentViewSortable("ul_'.CURRENT_ID.'");
</script>';
        }

        $return .= ('radio' === $this->strPickerFieldType ? '
<div class="tl_radio_reset">
<label for="tl_radio_reset" class="tl_radio_label">'.$GLOBALS['TL_LANG']['MSC']['resetSelected'].'</label> <input type="radio" name="picker" id="tl_radio_reset" value="" class="tl_tree_radio">
</div>' : '').'
</div>';

        // Add another panel at the end of the page
        if (false !== strpos($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['panelLayout'], 'limit')) {
            $return .= $this->paginationMenu();
        }

        // Close the form
        if ('select' === Input::get('act')) {
            // Submit buttons
            $arrButtons = [];

            if (!($GLOBALS['TL_DCA'][$this->strTable]['config']['notEditable'] ?? null)) {
                $arrButtons['edit'] = '<button type="submit" name="edit" id="edit" class="tl_submit" accesskey="s">'.$GLOBALS['TL_LANG']['MSC']['editSelected'].'</button>';
            }

            if (!($GLOBALS['TL_DCA'][$this->strTable]['config']['notDeletable'] ?? null)) {
                $arrButtons['delete'] = '<button type="submit" name="delete" id="delete" class="tl_submit" accesskey="d" onclick="return confirm(\''.$GLOBALS['TL_LANG']['MSC']['delAllConfirm'].'\')">'.$GLOBALS['TL_LANG']['MSC']['deleteSelected'].'</button>';
            }

            if (!($GLOBALS['TL_DCA'][$this->strTable]['config']['notCopyable'] ?? null)) {
                $arrButtons['copy'] = '<button type="submit" name="copy" id="copy" class="tl_submit" accesskey="c">'.$GLOBALS['TL_LANG']['MSC']['copySelected'].'</button>';
            }

            if (!($GLOBALS['TL_DCA'][$this->strTable]['config']['notSortable'] ?? null)) {
                $arrButtons['cut'] = '<button type="submit" name="cut" id="cut" class="tl_submit" accesskey="x">'.$GLOBALS['TL_LANG']['MSC']['moveSelected'].'</button>';
            }

            if (!($GLOBALS['TL_DCA'][$this->strTable]['config']['notEditable'] ?? null)) {
                $arrButtons['override'] = '<button type="submit" name="override" id="override" class="tl_submit" accesskey="v">'.$GLOBALS['TL_LANG']['MSC']['overrideSelected'].'</button>';
            }

            // Call the buttons_callback (see #4691)
            if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['select']['buttons_callback'] ?? null)) {
                foreach ($GLOBALS['TL_DCA'][$this->strTable]['select']['buttons_callback'] as $callback) {
                    if (\is_array($callback)) {
                        $this->import($callback[0]);
                        $arrButtons = $this->{$callback[0]}->{$callback[1]}($arrButtons, $this);
                    } elseif (\is_callable($callback)) {
                        $arrButtons = $callback($arrButtons, $this);
                    }
                }
            }

            if (\count($arrButtons) < 3) {
                $strButtons = implode(' ', $arrButtons);
            } else {
                $strButtons = array_shift($arrButtons).' ';
                $strButtons .= '<div class="split-button">';
                $strButtons .= array_shift($arrButtons).'<button type="button" id="sbtog">'.Image::getHtml('navcol.svg').'</button> <ul class="invisible">';

                foreach ($arrButtons as $strButton) {
                    $strButtons .= '<li>'.$strButton.'</li>';
                }

                $strButtons .= '</ul></div>';
            }

            $return .= '
</div>
<div class="tl_formbody_submit" style="text-align:right">
<div class="tl_submit_container">
  '.$strButtons.'
</div>
</div>
</form>';
        }

        return $return;
    }

    private function render(string $name, array $context = []): string
    {
        return System::getContainer()->get('twig')->render($name, $context);
    }

    /**
     * List all records of the current table and return them as HTML string.
     *
     * @return string
     */
    protected function listView()
    {
        $table = ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) === 6 ? $this->ptable : $this->strTable;
        $orderBy = $GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['fields'] ?? [];
        $firstOrderBy = preg_replace('/\s+.*$/', '', $orderBy[0]);

        if (\is_array($this->orderBy) && !empty($this->orderBy[0])) {
            $orderBy = $this->orderBy;
            $firstOrderBy = $this->firstOrderBy;
        }

        // Check the default labels (see #509)
        $labelNew = $GLOBALS['TL_LANG'][$this->strTable]['new'] ?? $GLOBALS['TL_LANG']['DCA']['new'];

        $qb = $this->connection->createQueryBuilder();
        $qb->select('*')->from($this->strTable);

        if (!empty($this->procedure)) {
            $qb->where(...$this->procedure);
        }

        if (!empty($this->root) && \is_array($this->root)) {
            $qb->andWhere('id IN('.implode(',', array_map('\intval', $this->root)).')');
        }

        if (\is_array($orderBy) && $orderBy[0]) {
            foreach ($orderBy as $k => $v) {
                [$key, $direction] = explode(' ', $v, 2) + [null, null];

                // If there is no direction, check the global flag in sorting mode 1 or the field flag in all other sorting modes
                if (!$direction) {
                    if (($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) === 1 && isset($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['flag']) && ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['flag'] % 2) === 0) {
                        $direction = 'DESC';
                    } elseif (isset($GLOBALS['TL_DCA'][$this->strTable]['fields'][$key]['flag']) && ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$key]['flag'] % 2) === 0) {
                        $direction = 'DESC';
                    }
                }

                if ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$key]['eval']['findInSet'] ?? null) {
                    $direction = null;

                    if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$key]['options_callback'] ?? null)) {
                        $strClass = $GLOBALS['TL_DCA'][$this->strTable]['fields'][$key]['options_callback'][0];
                        $strMethod = $GLOBALS['TL_DCA'][$this->strTable]['fields'][$key]['options_callback'][1];

                        System::importStatic($strClass)->$strMethod($this);
                    } elseif (\is_callable($GLOBALS['TL_DCA'][$this->strTable]['fields'][$key]['options_callback'] ?? null)) {
                        $keys = $GLOBALS['TL_DCA'][$this->strTable]['fields'][$key]['options_callback']($this);
                    } else {
                        $keys = $GLOBALS['TL_DCA'][$this->strTable]['fields'][$key]['options'] ?? [];
                    }

                    if (($GLOBALS['TL_DCA'][$this->strTable]['fields'][$key]['eval']['isAssociative'] ?? null) || ArrayUtil::isAssoc($keys)) {
                        $keys = array_keys($keys);
                    }

                    $orderBy[$k] = Database::getInstance()->findInSet($v, $keys);
                } elseif (\in_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$key]['flag'] ?? null, [5, 6, 7, 8, 9, 10], true)) {
                    $orderBy[$k] = "CAST($key AS SIGNED)"; // see #5503
                }

                if ($direction) {
                    $orderBy[$k] = $key.' '.$direction;
                }
            }

            if (($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) === 3) {
                $firstOrderBy = 'pid';
                $showFields = $GLOBALS['TL_DCA'][$table]['list']['label']['fields'];

                    $qb->orderBy(sprintf('(SELECT %s FROM %s WHERE %s.id=%s.pid)', Database::quoteIdentifier($showFields[0]), $this->ptable, $this->ptable, $this->strTable));
                foreach ($orderBy as $order) {
                    $qb->orderBy(...explode(' ', $order));
                }

                // Set the foreignKey so that the label is translated
                if (!($GLOBALS['TL_DCA'][$table]['fields']['pid']['foreignKey'] ?? null)) {
                    $GLOBALS['TL_DCA'][$table]['fields']['pid']['foreignKey'] = $this->ptable.'.'.$showFields[0];
                }

                // Remove the parent field from label fields
                array_shift($showFields);
                $GLOBALS['TL_DCA'][$table]['list']['label']['fields'] = $showFields;
            } else {
                foreach ($orderBy as $order) {
                    $qb->orderBy(...explode(' ', $order));
                }
            }
        }

        if ($this->limit) {
            $arrLimit = explode(',', $this->limit) + [null, null];
            $qb->setMaxResults($arrLimit[1]);
            $qb->setFirstResult($arrLimit[0]);
        }

        $qb->setParameters($this->values);
        $rows = $qb->execute()->fetchAllAssociative();

        $ddd = [];

            // Process result and add label and buttons
            $remoteCur = false;
            $groupclass = 'tl_folder_tlist';
            $eoCount = -1;

            foreach ($rows as $i=>$row) {
                $args = [];
                $this->current[] = $row['id'];
                $showFields = $GLOBALS['TL_DCA'][$table]['list']['label']['fields'];

                // Label
                foreach ($showFields as $k => $v) {
                    if (false !== strpos($v, ':')) {
                        [$strKey, $strTable] = explode(':', $v, 2);
                        [$strTable, $strField] = explode('.', $strTable, 2);

                        $objRef = Database::getInstance()->prepare('SELECT '.Database::quoteIdentifier($strField).' FROM '.$strTable.' WHERE id=?')
                            ->limit(1)
                            ->execute($row[$strKey])
                        ;

                        $args[$k] = $objRef->numRows ? $objRef->$strField : '';
                    } elseif (\in_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$v]['flag'] ?? null, [5, 6, 7, 8, 9, 10], true)) {
                        if (($GLOBALS['TL_DCA'][$this->strTable]['fields'][$v]['eval']['rgxp'] ?? null) === 'date') {
                            $args[$k] = $row[$v] ? Date::parse(Config::get('dateFormat'), $row[$v]) : '-';
                        } elseif (($GLOBALS['TL_DCA'][$this->strTable]['fields'][$v]['eval']['rgxp'] ?? null) === 'time') {
                            $args[$k] = $row[$v] ? Date::parse(Config::get('timeFormat'), $row[$v]) : '-';
                        } else {
                            $args[$k] = $row[$v] ? Date::parse(Config::get('datimFormat'), $row[$v]) : '-';
                        }
                    } elseif (($GLOBALS['TL_DCA'][$this->strTable]['fields'][$v]['eval']['isBoolean'] ?? null) || (($GLOBALS['TL_DCA'][$this->strTable]['fields'][$v]['inputType'] ?? null) === 'checkbox' && !($GLOBALS['TL_DCA'][$this->strTable]['fields'][$v]['eval']['multiple'] ?? null))) {
                        $args[$k] = $row[$v] ? $GLOBALS['TL_LANG']['MSC']['yes'] : $GLOBALS['TL_LANG']['MSC']['no'];
                    } elseif (isset($row[$v])) {
                        $row_v = StringUtil::deserialize($row[$v]);

                        if (\is_array($row_v)) {
                            $args_k = [];

                            foreach ($row_v as $option) {
                                $args_k[] = $GLOBALS['TL_DCA'][$table]['fields'][$v]['reference'][$option] ?: $option;
                            }

                            $args[$k] = implode(', ', $args_k);
                        } elseif (isset($GLOBALS['TL_DCA'][$table]['fields'][$v]['reference'][$row[$v]])) {
                            $args[$k] = \is_array($GLOBALS['TL_DCA'][$table]['fields'][$v]['reference'][$row[$v]]) ? $GLOBALS['TL_DCA'][$table]['fields'][$v]['reference'][$row[$v]][0] : $GLOBALS['TL_DCA'][$table]['fields'][$v]['reference'][$row[$v]];
                        } elseif (($GLOBALS['TL_DCA'][$table]['fields'][$v]['eval']['isAssociative'] ?? null) || ArrayUtil::isAssoc($GLOBALS['TL_DCA'][$table]['fields'][$v]['options'] ?? null) && isset($GLOBALS['TL_DCA'][$table]['fields'][$v]['options'][$row[$v]])) {
                            $args[$k] = $GLOBALS['TL_DCA'][$table]['fields'][$v]['options'][$row[$v]] ?? null;
                        } else {
                            $args[$k] = $row[$v];
                        }
                    } else {
                        $args[$k] = null;
                    }
                }

                // Shorten the label it if it is too long
                $label = vsprintf(!empty($GLOBALS['TL_DCA'][$this->strTable]['list']['label']['format']) ? $GLOBALS['TL_DCA'][$this->strTable]['list']['label']['format'] : '%s', $args);

                if (($GLOBALS['TL_DCA'][$this->strTable]['list']['label']['maxCharacters'] ?? null) > 0 && $GLOBALS['TL_DCA'][$this->strTable]['list']['label']['maxCharacters'] < \strlen(strip_tags($label))) {
                    $label = trim(StringUtil::substrHtml($label, $GLOBALS['TL_DCA'][$this->strTable]['list']['label']['maxCharacters'])).' …';
                }

                // Remove empty brackets (), [], {}, <> and empty tags from the label
                $label = preg_replace('/\( *\) ?|\[ *] ?|{ *} ?|< *> ?/', '', $label);
                $label = preg_replace('/<[^>]+>\s*<\/[^>]+>/', '', $label);

                // Build the sorting groups
                if (($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) > 0) {
                    $current = $row[$firstOrderBy];
                    $orderBy = $GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['fields'] ?? [];
                    $sortingMode = 1 === \count($orderBy) && $firstOrderBy === $orderBy[0] && ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['flag'] ?? null) && !($GLOBALS['TL_DCA'][$this->strTable]['fields'][$firstOrderBy]['flag'] ?? null) ? $GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['flag'] : ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$firstOrderBy]['flag'] ?? null);
                    $remoteNew = $this->formatCurrentValue($firstOrderBy, $current, $sortingMode);

                    // Add the group header
                    if (($remoteNew !== $remoteCur || false === $remoteCur) && !($GLOBALS['TL_DCA'][$this->strTable]['list']['label']['showColumns'] ?? null) && !($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['disableGrouping'] ?? null)) {
                        $eoCount = -1;
                        $group = $this->formatGroupHeader($firstOrderBy, $remoteNew, $sortingMode, $row);
                        $remoteCur = $remoteNew;

                        $return .= '
  <tr>
    <td colspan="2" class="'.$groupclass.'">'.$group.'</td>
  </tr>';
                        $groupclass = 'tl_folder_list';
                    }
                }

                $colspan = 1;

                // Call the label_callback ($row, $label, $this)
                if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['list']['label']['label_callback'] ?? null) || \is_callable($GLOBALS['TL_DCA'][$this->strTable]['list']['label']['label_callback'] ?? null)) {
                    if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['list']['label']['label_callback'] ?? null)) {
                        $strClass = $GLOBALS['TL_DCA'][$this->strTable]['list']['label']['label_callback'][0];
                        $strMethod = $GLOBALS['TL_DCA'][$this->strTable]['list']['label']['label_callback'][1];

                        $args = System::importStatic($strClass)->$strMethod($row, $label, $this, $args);
                    } elseif (\is_callable($GLOBALS['TL_DCA'][$this->strTable]['list']['label']['label_callback'] ?? null)) {
                        $args = $GLOBALS['TL_DCA'][$this->strTable]['list']['label']['label_callback']($row, $label, $this, $args);
                    }

                    // Handle strings and arrays
                    if (!($GLOBALS['TL_DCA'][$this->strTable]['list']['label']['showColumns'] ?? null)) {
                        $label = \is_array($args) ? implode(' ', $args) : $args;
                    } elseif (!\is_array($args)) {
                        $args = [$args];
                        $colspan = \count($GLOBALS['TL_DCA'][$this->strTable]['list']['label']['fields'] ?? []);
                    }
                }

                // Show columns
                if ($GLOBALS['TL_DCA'][$this->strTable]['list']['label']['showColumns'] ?? null) {
                    foreach ($args as $j => $arg) {
                        $field = $GLOBALS['TL_DCA'][$this->strTable]['list']['label']['fields'][$j] ?? null;

                        if (isset($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['foreignKey'])) {
                            $value = $arg ?: '-';
                        } else {
                            $value = '' !== (string) $arg ? $arg : '-';
                        }

                        $return .= '<td colspan="'.$colspan.'" class="tl_file_list col_'.explode(':', $field, 2)[0].($field === $firstOrderBy ? ' ordered_by' : '').'">'.$value.'</td>';
                    }
                        $ddd[$i]['label'] = $row;
                } else {
                        $ddd[$i]['label'] = $label;
                    $return .= '<td class="tl_file_list">'.$label.'</td>';
                }

                        $ddd[$i]['buttons'] = $this->generateButtons($row, $this->strTable, $this->root).($this->strPickerFieldType ? $this->getPickerInputField($row['id']) : '');
                // Buttons ($row, $table, $root, $blnCircularReference, $childs, $previous, $next)
                $return .= ('select' === Input::get('act') ? '
    <td class="tl_file_list tl_right_nowrap"><input type="checkbox" name="IDS[]" id="ids_'.$row['id'].'" class="tl_tree_checkbox" value="'.$row['id'].'"></td>' : '
    <td class="tl_file_list tl_right_nowrap">'.$this->generateButtons($row, $this->strTable, $this->root).($this->strPickerFieldType ? $this->getPickerInputField($row['id']) : '').'</td>').'
  </tr>';
            }

            // Add another panel at the end of the page
            if (false !== strpos($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['panelLayout'], 'limit')) {
                $return .= $this->paginationMenu();
            }

            // Close the form
            if ('select' === Input::get('act')) {
                // Submit buttons
                $arrButtons = [];

                if (!($GLOBALS['TL_DCA'][$this->strTable]['config']['notEditable'] ?? null)) {
                    $arrButtons['edit'] = '<button type="submit" name="edit" id="edit" class="tl_submit" accesskey="s">' . $GLOBALS['TL_LANG']['MSC']['editSelected'] . '</button>';
                }

                if (!($GLOBALS['TL_DCA'][$this->strTable]['config']['notDeletable'] ?? null)) {
                    $arrButtons['delete'] = '<button type="submit" name="delete" id="delete" class="tl_submit" accesskey="d" onclick="return confirm(\'' . $GLOBALS['TL_LANG']['MSC']['delAllConfirm'] . '\')">' . $GLOBALS['TL_LANG']['MSC']['deleteSelected'] . '</button>';
                }

                if (!($GLOBALS['TL_DCA'][$this->strTable]['config']['notCopyable'] ?? null)) {
                    $arrButtons['copy'] = '<button type="submit" name="copy" id="copy" class="tl_submit" accesskey="c">' . $GLOBALS['TL_LANG']['MSC']['copySelected'] . '</button>';
                }

                if (!($GLOBALS['TL_DCA'][$this->strTable]['config']['notEditable'] ?? null)) {
                    $arrButtons['override'] = '<button type="submit" name="override" id="override" class="tl_submit" accesskey="v">' . $GLOBALS['TL_LANG']['MSC']['overrideSelected'] . '</button>';
                }

                // Call the buttons_callback (see #4691)
                if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['select']['buttons_callback'] ?? null)) {
                    foreach ($GLOBALS['TL_DCA'][$this->strTable]['select']['buttons_callback'] as $callback) {
                        if (\is_array($callback)) {
                            System::importStatic($callback[0]);
                            $arrButtons = $this->{$callback[0]}->{$callback[1]}($arrButtons, $this);
                        } elseif (\is_callable($callback)) {
                            $arrButtons = $callback($arrButtons, $this);
                        }
                    }
                }

                if (\count($arrButtons) < 3) {
                    $strButtons = implode(' ', $arrButtons);
                } else {
                    $strButtons = array_shift($arrButtons) . ' ';
                    $strButtons .= '<div class="split-button">';
                    $strButtons .= array_shift($arrButtons) . '<button type="button" id="sbtog">' . Image::getHtml('navcol.svg') . '</button> <ul class="invisible">';

                    foreach ($arrButtons as $strButton) {
                        $strButtons .= '<li>' . $strButton . '</li>';
                    }

                    $strButtons .= '</ul></div>';
                }
        }

        return $this->render('@ContaoCore/Backend/DataContainer/list_view.html.twig', [
            'rows' => $ddd,
            'config' => $GLOBALS['TL_DCA'][$this->strTable]['list'],
            'table' => $this->strTable
        ]);

    }

    /**
     * Return a search form that allows to search results using regular expressions.
     *
     * @return string
     */
    protected function searchMenu()
    {
        $searchFields = [];

        /** @var AttributeBagInterface $objSessionBag */
        $objSessionBag = System::getContainer()->get('session')->getBag('contao_backend');

        $session = $objSessionBag->all();

        // Get search fields
        foreach ($GLOBALS['TL_DCA'][$this->strTable]['fields'] as $k => $v) {
            if ($v['search'] ?? null) {
                $searchFields[] = $k;
            }
        }

        // Return if there are no search fields
        if (empty($searchFields)) {
            return '';
        }

        // Store search value in the current session
        if ('tl_filters' === Input::post('FORM_SUBMIT')) {
            $strField = Input::post('tl_field', true);
            $strKeyword = ltrim(Input::postRaw('tl_value'), '*');

            if ($strField && !\in_array($strField, $searchFields, true)) {
                $strField = '';
                $strKeyword = '';
            }

            $session['search'][$this->strTable]['field'] = $strField;
            $session['search'][$this->strTable]['value'] = $strKeyword;

            $objSessionBag->replace($session);
        }

        // Set the search value from the session
        elseif (isset($session['search'][$this->strTable]['value']) && '' !== (string) $session['search'][$this->strTable]['value']) {
            $searchValue = $session['search'][$this->strTable]['value'];
            $fld = $session['search'][$this->strTable]['field'] ?? null;

            try {
                Database::getInstance()->prepare("SELECT '' REGEXP ?")->execute($searchValue);
            } catch (DriverException $exception) {
                // Quote search string if it is not a valid regular expression
                $searchValue = preg_quote($searchValue);
            }

            $strReplacePrefix = '';
            $strReplaceSuffix = '';

            // Decode HTML entities to make them searchable
            if (empty($GLOBALS['TL_DCA'][$this->strTable]['fields'][$fld]['eval']['decodeEntities'])) {
                $arrReplace = [
                    '&#35;' => '#',
                    '&#60;' => '<',
                    '&#62;' => '>',
                    '&lt;' => '<',
                    '&gt;' => '>',
                    '&#40;' => '(',
                    '&#41;' => ')',
                    '&#92;' => '\\\\',
                    '&#61;' => '=',
                    '&amp;' => '&',
                ];

                $strReplacePrefix = str_repeat('REPLACE(', \count($arrReplace));

                foreach ($arrReplace as $strSource => $strTarget) {
                    $strReplaceSuffix .= ", '$strSource', '$strTarget')";
                }
            }

            $strPattern = "$strReplacePrefix CAST(%s AS CHAR) $strReplaceSuffix REGEXP ?";

            if ('_ci' === substr(Config::get('dbCollation'), -3)) {
                $strPattern = "$strReplacePrefix LOWER(CAST(%s AS CHAR)) $strReplaceSuffix REGEXP LOWER(?)";
            }

            if (isset($GLOBALS['TL_DCA'][$this->strTable]['fields'][$fld]['foreignKey'])) {
                [$t, $f] = explode('.', $GLOBALS['TL_DCA'][$this->strTable]['fields'][$fld]['foreignKey'], 2);
                $this->procedure[] = '('.sprintf($strPattern, Database::quoteIdentifier($fld)).' OR '.sprintf($strPattern, '(SELECT '.Database::quoteIdentifier($f)." FROM $t WHERE $t.id=".$this->strTable.'.'.Database::quoteIdentifier($fld).')').')';
                $this->values[] = $searchValue;
            } else {
                $this->procedure[] = sprintf($strPattern, Database::quoteIdentifier($fld));
            }

            $this->values[] = $searchValue;
        }

        $options_sorter = [];

        foreach ($searchFields as $field) {
            $option_label = $field;

            if (isset($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['label'])) {
                $option_label = \is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['label']) ? $GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['label'][0] : $GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['label'];
            } elseif (isset($GLOBALS['TL_LANG']['MSC'][$field])) {
                $option_label = \is_array($GLOBALS['TL_LANG']['MSC'][$field]) ? $GLOBALS['TL_LANG']['MSC'][$field][0] : $GLOBALS['TL_LANG']['MSC'][$field];
            }

            $options_sorter[Utf8::toAscii($option_label).'_'.$field] = '  <option value="'.StringUtil::specialchars($field).'"'.(isset($session['search'][$this->strTable]['field']) && $session['search'][$this->strTable]['field'] === $field ? ' selected="selected"' : '').'>'.$option_label.'</option>';
        }

        // Sort by option values
        uksort($options_sorter, 'strnatcasecmp');
        $active = isset($session['search'][$this->strTable]['value']) && '' !== (string) $session['search'][$this->strTable]['value'];

        return '
<div class="tl_search tl_subpanel">
<strong>'.$GLOBALS['TL_LANG']['MSC']['search'].':</strong>
<select name="tl_field" class="tl_select'.($active ? ' active' : '').'">
'.implode("\n", $options_sorter).'
</select>
<span>=</span>
<input type="search" name="tl_value" class="tl_text'.($active ? ' active' : '').'" value="'.StringUtil::specialchars($session['search'][$this->strTable]['value'] ?? '').'">
</div>';
    }

    /**
     * Return a select menu that allows to sort results by a particular field.
     *
     * @return string
     */
    protected function sortMenu()
    {
        if (($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) !== 2 && ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) !== 4) {
            return '';
        }

        $sortingFields = [];

        // Get sorting fields
        foreach ($GLOBALS['TL_DCA'][$this->strTable]['fields'] as $k => $v) {
            if ($v['sorting'] ?? null) {
                $sortingFields[] = $k;
            }
        }

        // Return if there are no sorting fields
        if (empty($sortingFields)) {
            return '';
        }

        /** @var AttributeBagInterface $objSessionBag */
        $objSessionBag = System::getContainer()->get('session')->getBag('contao_backend');

        $session = $objSessionBag->all();
        $orderBy = $GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['fields'] ?? [];
        $firstOrderBy = preg_replace('/\s+.*$/', '', $orderBy[0]);

        // Add PID to order fields
        if (($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) === 3 && Database::getInstance()->fieldExists('pid', $this->strTable)) {
            array_unshift($orderBy, 'pid');
        }

        // Set sorting from user input
        if ('tl_filters' === Input::post('FORM_SUBMIT')) {
            $strSort = Input::post('tl_sort');

            // Validate the user input (thanks to aulmn) (see #4971)
            if (\in_array($strSort, $sortingFields, true)) {
                $session['sorting'][$this->strTable] = \in_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$strSort]['flag'] ?? null, [2, 4, 6, 8, 10, 12], true) ? "$strSort DESC" : $strSort;
                $objSessionBag->replace($session);
            }
        }

        // Overwrite the "orderBy" value with the session value
        elseif (isset($session['sorting'][$this->strTable])) {
            $overwrite = preg_quote(preg_replace('/\s+.*$/', '', $session['sorting'][$this->strTable]), '/');
            $orderBy = array_diff($orderBy, preg_grep('/^'.$overwrite.'/i', $orderBy));

            array_unshift($orderBy, $session['sorting'][$this->strTable]);

            $this->firstOrderBy = $overwrite;
            $this->orderBy = $orderBy;
        }

        $options_sorter = [];

        // Sorting fields
        foreach ($sortingFields as $field) {
            $options_label = ($lbl = \is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['label'] ?? null) ? $GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['label'][0] : ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['label'] ?? null)) ? $lbl : $GLOBALS['TL_LANG']['MSC'][$field];

            if (\is_array($options_label)) {
                $options_label = $options_label[0];
            }

            $options_sorter[$options_label] = '  <option value="'.StringUtil::specialchars($field).'"'.((!isset($session['sorting'][$this->strTable]) && $field === $firstOrderBy) || $field === str_replace(' DESC', '', $session['sorting'][$this->strTable] ?? '') ? ' selected="selected"' : '').'>'.$options_label.'</option>';
        }

        // Sort by option values
        uksort($options_sorter, 'strcasecmp');

        return '
<div class="tl_sorting tl_subpanel">
<strong>'.$GLOBALS['TL_LANG']['MSC']['sortBy'].':</strong>
<select name="tl_sort" id="tl_sort" class="tl_select">
'.implode("\n", $options_sorter).'
</select>
</div>';
    }

    /**
     * Return a select menu to limit results.
     *
     * @param bool $blnOptional
     *
     * @return string
     */
    protected function limitMenu($blnOptional = false)
    {
        /** @var AttributeBagInterface $objSessionBag */
        $objSessionBag = System::getContainer()->get('session')->getBag('contao_backend');

        $session = $objSessionBag->all();
        $filter = ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) === 4 ? $this->strTable.'_'.CURRENT_ID : $this->strTable;
        $fields = '';

        // Set limit from user input
        if (\in_array(Input::post('FORM_SUBMIT'), ['tl_filters', 'tl_filters_limit'], true)) {
            $strLimit = Input::post('tl_limit');

            if ('tl_limit' === $strLimit) {
                unset($session['filter'][$filter]['limit']);
            }
            // Validate the user input (thanks to aulmn) (see #4971)
            elseif ('all' === $strLimit || preg_match('/^[0-9]+,[0-9]+$/', $strLimit)) {
                $session['filter'][$filter]['limit'] = $strLimit;
            }

            $objSessionBag->replace($session);

            if ('tl_filters_limit' === Input::post('FORM_SUBMIT')) {
                $this->reload();
            }
        }

        // Set limit from table configuration
        else {
            $this->limit = isset($session['filter'][$filter]['limit']) ? ('all' === $session['filter'][$filter]['limit'] ? null : $session['filter'][$filter]['limit']) : '0,'.Config::get('resultsPerPage');

            $arrProcedure = $this->procedure;
            $arrValues = $this->values;
            $query = 'SELECT COUNT(*) AS count FROM '.$this->strTable;

            if (!empty($this->root) && \is_array($this->root)) {
                $arrProcedure[] = 'id IN('.implode(',', $this->root).')';
            }

            // Support empty ptable fields
            if ($GLOBALS['TL_DCA'][$this->strTable]['config']['dynamicPtable'] ?? null) {
                $arrProcedure[] = 'tl_article' === $this->ptable ? "(ptable=? OR ptable='')" : 'ptable=?';
                $arrValues[] = $this->ptable;
            }

            if (!empty($arrProcedure)) {
                $query .= ' WHERE '.implode(' AND ', $arrProcedure);
            }

            $objTotal = Database::getInstance()->prepare($query)->execute($arrValues);
            $this->total = $objTotal->count;
            $options_total = 0;
            $maxResultsPerPage = Config::get('maxResultsPerPage');
            $blnIsMaxResultsPerPage = false;

            // Overall limit
            if ($maxResultsPerPage > 0 && $this->total > $maxResultsPerPage && (null === $this->limit || preg_replace('/^.*,/', '', $this->limit) === $maxResultsPerPage)) {
                if (null === $this->limit) {
                    $this->limit = '0,'.Config::get('maxResultsPerPage');
                }

                $blnIsMaxResultsPerPage = true;
                Config::set('resultsPerPage', Config::get('maxResultsPerPage'));
                $session['filter'][$filter]['limit'] = Config::get('maxResultsPerPage');
            }

            $options = '';

            // Build options
            if ($this->total > 0) {
                $options = '';
                $options_total = ceil($this->total / Config::get('resultsPerPage'));

                // Reset limit if other parameters have decreased the number of results
                if (null !== $this->limit && (!$this->limit || preg_replace('/,.*$/', '', $this->limit) > $this->total)) {
                    $this->limit = '0,'.Config::get('resultsPerPage');
                }

                // Build options
                for ($i = 0; $i < $options_total; ++$i) {
                    $this_limit = ($i * Config::get('resultsPerPage')).','.Config::get('resultsPerPage');
                    $upper_limit = $i * Config::get('resultsPerPage') + Config::get('resultsPerPage');

                    if ($upper_limit > $this->total) {
                        $upper_limit = $this->total;
                    }

                    $options .= '
  <option value="'.$this_limit.'"'.Widget::optionSelected($this->limit, $this_limit).'>'.($i * Config::get('resultsPerPage') + 1).' - '.$upper_limit.'</option>';
                }

                if (!$blnIsMaxResultsPerPage) {
                    $options .= '
  <option value="all"'.Widget::optionSelected($this->limit, null).'>'.$GLOBALS['TL_LANG']['MSC']['filterAll'].'</option>';
                }
            }

            // Return if there is only one page
            if ($blnOptional && ($this->total < 1 || $options_total < 2)) {
                return '';
            }

            $fields = '
<select name="tl_limit" class="tl_select'.(($session['filter'][$filter]['limit'] ?? null) !== 'all' && $this->total > Config::get('resultsPerPage') ? ' active' : '').'" onchange="this.form.submit()">
  <option value="tl_limit">'.$GLOBALS['TL_LANG']['MSC']['filterRecords'].'</option>'.$options.'
</select> ';
        }

        return '
<div class="tl_limit tl_subpanel">
<strong>'.$GLOBALS['TL_LANG']['MSC']['showOnly'].':</strong> '.$fields.'
</div>';
    }

    /**
     * Generate the filter panel and return it as HTML string.
     *
     * @param int $intFilterPanel
     *
     * @return string
     */
    protected function filterMenu($intFilterPanel)
    {
        /** @var AttributeBagInterface $objSessionBag */
        $objSessionBag = System::getContainer()->get('session')->getBag('contao_backend');

        $fields = '';
        $sortingFields = [];
        $session = $objSessionBag->all();
        $filter = ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) === 4 ? $this->strTable.'_'.CURRENT_ID : $this->strTable;

        // Get the sorting fields
        foreach ($GLOBALS['TL_DCA'][$this->strTable]['fields'] as $k => $v) {
            if (($v['filter'] ?? null) === $intFilterPanel) {
                $sortingFields[] = $k;
            }
        }

        // Return if there are no sorting fields
        if (empty($sortingFields)) {
            return '';
        }

        // Set filter from user input
        if ('tl_filters' === Input::post('FORM_SUBMIT')) {
            foreach ($sortingFields as $field) {
                if (Input::post($field, true) !== 'tl_'.$field) {
                    $session['filter'][$filter][$field] = Input::post($field, true);
                } else {
                    unset($session['filter'][$filter][$field]);
                }
            }

            $objSessionBag->replace($session);
        }

        // Set filter from table configuration
        else {
            foreach ($sortingFields as $field) {
                $what = Database::quoteIdentifier($field);

                if (isset($session['filter'][$filter][$field])) {
                    // Sort by day
                    if (\in_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['flag'] ?? null, [5, 6], true)) {
                        if (!$session['filter'][$filter][$field]) {
                            $this->procedure[] = $what."=''";
                        } else {
                            $objDate = new Date($session['filter'][$filter][$field]);
                            $this->procedure[] = $what.' BETWEEN ? AND ?';
                            $this->values[] = $objDate->dayBegin;
                            $this->values[] = $objDate->dayEnd;
                        }
                    }

                    // Sort by month
                    elseif (\in_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['flag'] ?? null, [7, 8], true)) {
                        if (!$session['filter'][$filter][$field]) {
                            $this->procedure[] = $what."=''";
                        } else {
                            $objDate = new Date($session['filter'][$filter][$field]);
                            $this->procedure[] = $what.' BETWEEN ? AND ?';
                            $this->values[] = $objDate->monthBegin;
                            $this->values[] = $objDate->monthEnd;
                        }
                    }

                    // Sort by year
                    elseif (\in_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['flag'] ?? null, [9, 10], true)) {
                        if (!$session['filter'][$filter][$field]) {
                            $this->procedure[] = $what."=''";
                        } else {
                            $objDate = new Date($session['filter'][$filter][$field]);
                            $this->procedure[] = $what.' BETWEEN ? AND ?';
                            $this->values[] = $objDate->yearBegin;
                            $this->values[] = $objDate->yearEnd;
                        }
                    }

                    // Manual filter
                    elseif ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['eval']['multiple'] ?? null) {
                        // CSV lists (see #2890)
                        if (isset($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['eval']['csv'])) {
                            $this->procedure[] = Database::getInstance()->findInSet('?', $field, true);
                            $this->values[] = $session['filter'][$filter][$field] ?? null;
                        } else {
                            $this->procedure[] = $what.' LIKE ?';
                            $this->values[] = '%"'.$session['filter'][$filter][$field].'"%';
                        }
                    }

                    // Other sort algorithm
                    else {
                        $this->procedure[] = $what.'=?';
                        $this->values[] = $session['filter'][$filter][$field] ?? null;
                    }
                }
            }
        }

        // Add sorting options
        foreach ($sortingFields as $cnt => $field) {
            $arrValues = [];
            $arrProcedure = [];

            if (($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) === 4) {
                $arrProcedure[] = 'pid=?';
                $arrValues[] = CURRENT_ID;
            }

            if (!$this->treeView && !empty($this->root) && \is_array($this->root)) {
                $arrProcedure[] = 'id IN('.implode(',', array_map('\intval', $this->root)).')';
            }

            // Check for a static filter (see #4719)
            if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['filter'] ?? null)) {
                foreach ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['filter'] as $fltr) {
                    if (\is_string($fltr)) {
                        $arrProcedure[] = $fltr;
                    } else {
                        $arrProcedure[] = $fltr[0];
                        $arrValues[] = $fltr[1];
                    }
                }
            }

            // Support empty ptable fields
            if ($GLOBALS['TL_DCA'][$this->strTable]['config']['dynamicPtable'] ?? null) {
                $arrProcedure[] = 'tl_article' === $this->ptable ? "(ptable=? OR ptable='')" : 'ptable=?';
                $arrValues[] = $this->ptable;
            }

            $what = Database::quoteIdentifier($field);

            // Optimize the SQL query (see #8485)
            if (isset($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['flag'])) {
                // Sort by day
                if (\in_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['flag'], [5, 6], true)) {
                    $what = "IF($what!='', FLOOR(UNIX_TIMESTAMP(FROM_UNIXTIME($what , '%%Y-%%m-%%d'))), '') AS $what";
                }

                // Sort by month
                elseif (\in_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['flag'], [7, 8], true)) {
                    $what = "IF($what!='', FLOOR(UNIX_TIMESTAMP(FROM_UNIXTIME($what , '%%Y-%%m-01'))), '') AS $what";
                }

                // Sort by year
                elseif (\in_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['flag'], [9, 10], true)) {
                    $what = "IF($what!='', FLOOR(UNIX_TIMESTAMP(FROM_UNIXTIME($what , '%%Y-01-01'))), '') AS $what";
                }
            }

            $table = ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) === 6 ? $this->ptable : $this->strTable;

            // Limit the options if there are root records
            if (isset($GLOBALS['TL_DCA'][$table]['list']['sorting']['root']) && false !== $GLOBALS['TL_DCA'][$table]['list']['sorting']['root']) {
                $rootIds = array_map('\intval', $GLOBALS['TL_DCA'][$table]['list']['sorting']['root']);

                // Also add the child records of the table (see #1811)
                if (($GLOBALS['TL_DCA'][$table]['list']['sorting']['mode'] ?? null) === 5) {
                    $rootIds = array_merge($rootIds, Database::getInstance()->getChildRecords($rootIds, $table));
                }

                if (($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) === 6) {
                    $arrProcedure[] = 'pid IN('.implode(',', $rootIds).')';
                } else {
                    $arrProcedure[] = 'id IN('.implode(',', $rootIds).')';
                }
            }

            $objFields = Database::getInstance()->prepare('SELECT DISTINCT '.$what.' FROM '.$this->strTable.(\is_array($arrProcedure) && isset($arrProcedure[0]) ? ' WHERE '.implode(' AND ', $arrProcedure) : ''))
                ->execute($arrValues)
            ;

            // Begin select menu
            $fields .= '
<select name="'.$field.'" id="'.$field.'" class="tl_select'.(isset($session['filter'][$filter][$field]) ? ' active' : '').'">
  <option value="tl_'.$field.'">'.(\is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['label'] ?? null) ? $GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['label'][0] : ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['label'] ?? null)).'</option>
  <option value="tl_'.$field.'">---</option>';

            if ($objFields->numRows) {
                $options = $objFields->fetchEach($field);

                // Sort by day
                if (\in_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['flag'] ?? null, [5, 6], true)) {
                    ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['flag'] ?? null) === 6 ? rsort($options) : sort($options);

                    foreach ($options as $k => $v) {
                        if ('' === $v) {
                            $options[$v] = '-';
                        } else {
                            $options[$v] = Date::parse(Config::get('dateFormat'), $v);
                        }

                        unset($options[$k]);
                    }
                }

                // Sort by month
                elseif (\in_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['flag'] ?? null, [7, 8], true)) {
                    ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['flag'] ?? null) === 8 ? rsort($options) : sort($options);

                    foreach ($options as $k => $v) {
                        if ('' === $v) {
                            $options[$v] = '-';
                        } else {
                            $options[$v] = date('Y-m', $v);
                            $intMonth = date('m', $v) - 1;

                            if (isset($GLOBALS['TL_LANG']['MONTHS'][$intMonth])) {
                                $options[$v] = $GLOBALS['TL_LANG']['MONTHS'][$intMonth].' '.date('Y', $v);
                            }
                        }

                        unset($options[$k]);
                    }
                }

                // Sort by year
                elseif (\in_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['flag'] ?? null, [9, 10], true)) {
                    ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['flag'] ?? null) === 10 ? rsort($options) : sort($options);

                    foreach ($options as $k => $v) {
                        if ('' === $v) {
                            $options[$v] = '-';
                        } else {
                            $options[$v] = date('Y', $v);
                        }

                        unset($options[$k]);
                    }
                }

                // Manual filter
                if ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['eval']['multiple'] ?? null) {
                    $moptions = [];

                    // TODO: find a more effective solution
                    foreach ($options as $option) {
                        // CSV lists (see #2890)
                        if (isset($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['eval']['csv'])) {
                            $doptions = StringUtil::trimsplit($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['eval']['csv'], $option);
                        } else {
                            $doptions = StringUtil::deserialize($option);
                        }

                        if (\is_array($doptions)) {
                            $moptions = array_merge($moptions, $doptions);
                        }
                    }

                    $options = $moptions;
                }

                $options = array_unique($options);
                $options_callback = [];

                // Call the options_callback
                if (!($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['reference'] ?? null) && (\is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['options_callback'] ?? null) || \is_callable($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['options_callback'] ?? null))) {
                    if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['options_callback'] ?? null)) {
                        $strClass = $GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['options_callback'][0];
                        $strMethod = $GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['options_callback'][1];

                        $options_callback = System::importStatic($strClass)->$strMethod($this);
                    } elseif (\is_callable($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['options_callback'] ?? null)) {
                        $options_callback = $GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['options_callback']($this);
                    }

                    // Sort options according to the keys of the callback array
                    $options = array_intersect(array_keys($options_callback), $options);
                }

                $options_sorter = [];
                $blnDate = \in_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['flag'] ?? null, [5, 6, 7, 8, 9, 10], true);

                // Options
                foreach ($options as $kk => $vv) {
                    $value = $blnDate ? $kk : $vv;

                    // Options callback
                    if (!empty($options_callback) && \is_array($options_callback)) {
                        $vv = $options_callback[$vv];
                    }

                    // Replace the ID with the foreign key
                    elseif (isset($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['foreignKey'])) {
                        $key = explode('.', $GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['foreignKey'], 2);

                        $objParent = Database::getInstance()->prepare('SELECT '.Database::quoteIdentifier($key[1]).' AS value FROM '.$key[0].' WHERE id=?')
                            ->limit(1)
                            ->execute($vv)
                        ;

                        if ($objParent->numRows) {
                            $vv = $objParent->value;
                        }
                    }

                    // Replace boolean checkbox value with "yes" and "no"
                    elseif (($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['eval']['isBoolean'] ?? null) || (($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['inputType'] ?? null) === 'checkbox' && !($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['eval']['multiple'] ?? null))) {
                        $vv = $vv ? $GLOBALS['TL_LANG']['MSC']['yes'] : $GLOBALS['TL_LANG']['MSC']['no'];
                    }

                    // Get the name of the parent record (see #2703)
                    elseif ('pid' === $field) {
                        $this->loadDataContainer($this->ptable);
                        $showFields = $GLOBALS['TL_DCA'][$this->ptable]['list']['label']['fields'] ?? [];

                        if (!($showFields[0] ?? null)) {
                            $showFields[0] = 'id';
                        }

                        $objShowFields = Database::getInstance()->prepare('SELECT '.Database::quoteIdentifier($showFields[0]).' FROM '.$this->ptable.' WHERE id=?')
                            ->limit(1)
                            ->execute($vv)
                        ;

                        if ($objShowFields->numRows) {
                            $vv = $objShowFields->{$showFields[0]};
                        }
                    }

                    $option_label = '';

                    // Use reference array
                    if (isset($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['reference'])) {
                        $option_label = \is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['reference'][$vv] ?? null) ? $GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['reference'][$vv][0] : ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['reference'][$vv] ?? null);
                    }

                    // Associative array
                    elseif (($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['eval']['isAssociative'] ?? null) || ArrayUtil::isAssoc($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['options'] ?? null)) {
                        $option_label = $GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['options'][$vv] ?? null;
                    }

                    // No empty options allowed
                    if (!$option_label) {
                        if (isset($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['foreignKey'])) {
                            $option_label = $vv ?: '-';
                        } else {
                            $option_label = '' !== (string) $vv ? $vv : '-';
                        }
                    }

                    $options_sorter['  <option value="'.StringUtil::specialchars($value).'"'.(isset($session['filter'][$filter][$field]) && $session['filter'][$filter][$field] === $value ? ' selected="selected"' : '').'>'.StringUtil::specialchars($option_label).'</option>'] = Utf8::toAscii($option_label);
                }

                // Sort by option values
                if (!$blnDate) {
                    natcasesort($options_sorter);

                    if (\in_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['flag'] ?? null, [2, 4, 12], true)) {
                        $options_sorter = array_reverse($options_sorter, true);
                    }
                }

                $fields .= "\n".implode("\n", array_keys($options_sorter));
            }

            // End select menu
            $fields .= '
</select> ';

            // Force a line-break after six elements (see #3777)
            if (($cnt + 1) % 6 === 0) {
                $fields .= '<br>';
            }
        }

        return '
<div class="tl_filter tl_subpanel">
<strong>'.$GLOBALS['TL_LANG']['MSC']['filter'].':</strong> '.$fields.'
</div>';
    }

    /**
     * Return a pagination menu to browse results.
     *
     * @return string
     */
    protected function paginationMenu()
    {
        /** @var AttributeBagInterface $objSessionBag */
        $objSessionBag = System::getContainer()->get('session')->getBag('contao_backend');

        $session = $objSessionBag->all();
        $filter = ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) === 4 ? $this->strTable.'_'.CURRENT_ID : $this->strTable;

        [$offset, $limit] = explode(',', $this->limit ?? '') + [null, null];

        // Set the limit filter based on the page number
        if (isset($_GET['lp'])) {
            $lp = (int) Input::get('lp') - 1;

            if ($lp >= 0 && $lp < ceil($this->total / $limit)) {
                $session['filter'][$filter]['limit'] = ($lp * $limit).','.$limit;
                $objSessionBag->replace($session);
            }

            $this->redirect(preg_replace('/&(amp;)?lp=[^&]+/i', '', Environment::get('request')));
        }

        if ($limit) {
            Input::setGet('lp', $offset / $limit + 1); // see #6923
        }

        $objPagination = new Pagination($this->total, $limit, 7, 'lp', new BackendTemplate('be_pagination'), true);

        return $objPagination->generate();
    }

    /**
     * Return the formatted group header as string.
     *
     * @param string $field
     * @param mixed  $value
     * @param int    $mode
     *
     * @return string
     */
    protected function formatCurrentValue($field, $value, $mode)
    {
        $remoteNew = $value; // see #3861

        if (($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['eval']['isBoolean'] ?? null) || (($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['inputType'] ?? null) === 'checkbox' && !($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['eval']['multiple'] ?? null))) {
            $remoteNew = $value ? ucfirst($GLOBALS['TL_LANG']['MSC']['yes']) : ucfirst($GLOBALS['TL_LANG']['MSC']['no']);
        } elseif (isset($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['foreignKey'])) {
            $key = explode('.', $GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['foreignKey'], 2);

            $objParent = Database::getInstance()->prepare('SELECT '.Database::quoteIdentifier($key[1]).' AS value FROM '.$key[0].' WHERE id=?')
                ->limit(1)
                ->execute($value)
            ;

            if ($objParent->numRows) {
                $remoteNew = $objParent->value;
            }
        } elseif (\in_array($mode, [1, 2], true)) {
            $remoteNew = $value ? ucfirst(Utf8::substr($value, 0, 1)) : '-';
        } elseif (\in_array($mode, [3, 4], true)) {
            if (!isset($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['length'])) {
                $GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['length'] = 2;
            }

            $remoteNew = $value ? ucfirst(Utf8::substr($value, 0, $GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['length'])) : '-';
        } elseif (\in_array($mode, [5, 6], true)) {
            $remoteNew = $value ? Date::parse(Config::get('dateFormat'), $value) : '-';
        } elseif (\in_array($mode, [7, 8], true)) {
            $remoteNew = $value ? date('Y-m', $value) : '-';
            $intMonth = $value ? date('m', $value) - 1 : '-';

            if (isset($GLOBALS['TL_LANG']['MONTHS'][$intMonth])) {
                $remoteNew = $value ? $GLOBALS['TL_LANG']['MONTHS'][$intMonth].' '.date('Y', $value) : '-';
            }
        } elseif (\in_array($mode, [9, 10], true)) {
            $remoteNew = $value ? date('Y', $value) : '-';
        } else {
            if (($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['inputType'] ?? null) === 'checkbox' && !($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['eval']['multiple'] ?? null)) {
                $remoteNew = $value ? $field : '';
            } elseif (\is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['reference'] ?? null)) {
                $remoteNew = $GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['reference'][$value] ?? null;
            } elseif (($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['eval']['isAssociative'] ?? null) || ArrayUtil::isAssoc($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['options'] ?? null)) {
                $remoteNew = $GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['options'][$value] ?? null;
            } else {
                $remoteNew = $value;
            }

            if (\is_array($remoteNew)) {
                $remoteNew = $remoteNew[0];
            }

            if (empty($remoteNew)) {
                $remoteNew = '-';
            }
        }

        return $remoteNew;
    }

    /**
     * Return the formatted group header as string.
     *
     * @param string $field
     * @param mixed  $value
     * @param int    $mode
     * @param array  $row
     *
     * @return string
     */
    protected function formatGroupHeader($field, $value, $mode, $row)
    {
        static $lookup = [];

        if (($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['eval']['isAssociative'] ?? null) || ArrayUtil::isAssoc($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['options'] ?? null)) {
            $group = $GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['options'][$value] ?? null;
        } elseif (\is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['options_callback'] ?? null)) {
            if (!isset($lookup[$field])) {
                $strClass = $GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['options_callback'][0];
                $strMethod = $GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['options_callback'][1];

                $lookup[$field] = System::importStatic($strClass)->$strMethod($this);
            }

            $group = $lookup[$field][$value] ?? null;
        } else {
            $group = \is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['reference'][$value] ?? null) ? $GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['reference'][$value][0] : ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['reference'][$value] ?? null);
        }

        if (empty($group)) {
            $group = \is_array($GLOBALS['TL_LANG'][$this->strTable][$value] ?? null) ? $GLOBALS['TL_LANG'][$this->strTable][$value][0] : ($GLOBALS['TL_LANG'][$this->strTable][$value] ?? null);
        }

        if (empty($group)) {
            $group = $value;

            if ('-' !== $value && ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['eval']['isBoolean'] ?? null)) {
                $group = \is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['label'] ?? null) ? $GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['label'][0] : ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['label'] ?? null);
            }
        }

        // Call the group callback ($group, $sortingMode, $firstOrderBy, $row, $this)
        if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['list']['label']['group_callback'] ?? null)) {
            $strClass = $GLOBALS['TL_DCA'][$this->strTable]['list']['label']['group_callback'][0];
            $strMethod = $GLOBALS['TL_DCA'][$this->strTable]['list']['label']['group_callback'][1];

            $this->import($strClass);
            $group = $this->$strClass->$strMethod($group, $mode, $field, $row, $this);
        } elseif (\is_callable($GLOBALS['TL_DCA'][$this->strTable]['list']['label']['group_callback'] ?? null)) {
            $group = $GLOBALS['TL_DCA'][$this->strTable]['list']['label']['group_callback']($group, $mode, $field, $row, $this);
        }

        return $group;
    }

    /**
     * Render a row of a box and return it as HTML string.
     *
     * @param string|array|null $strPalette
     *
     * @throws AccessDeniedException
     * @throws \Exception
     *
     * @return string
     */
    protected function row($strPalette = null)
    {
        $arrData = $GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField] ?? [];

        // Check if the field is excluded
        if ($arrData['exclude'] ?? null) {
            throw new AccessDeniedException('Field "'.$this->strTable.'.'.$this->strField.'" is excluded from being edited.');
        }

        $xlabel = '';

        // Toggle line wrap (textarea)
        if (($arrData['inputType'] ?? null) === 'textarea' && !isset($arrData['eval']['rte'])) {
            $xlabel .= ' '.Image::getHtml('wrap.svg', $GLOBALS['TL_LANG']['MSC']['wordWrap'], 'title="'.StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['wordWrap']).'" class="toggleWrap" onclick="Backend.toggleWrap(\'ctrl_'.$this->strInputName.'\')"');
        }

        // Add the help wizard
        if ($arrData['eval']['helpwizard'] ?? null) {
            $xlabel .= ' <a href="contao/help.php?table='.$this->strTable.'&amp;field='.$this->strField.'" title="'.StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['helpWizard']).'" onclick="Backend.openModalIframe({\'title\':\''.StringUtil::specialchars(str_replace("'", "\\'", $arrData['label'][0] ?? '')).'\',\'url\':this.href});return false">'.Image::getHtml('about.svg', $GLOBALS['TL_LANG']['MSC']['helpWizard']).'</a>';
        }

        // Add a custom xlabel
        if (\is_array($arrData['xlabel'] ?? null)) {
            foreach ($arrData['xlabel'] as $callback) {
                if (\is_array($callback)) {
                    $this->import($callback[0]);
                    $xlabel .= $this->{$callback[0]}->{$callback[1]}($this);
                } elseif (\is_callable($callback)) {
                    $xlabel .= $callback($this);
                }
            }
        }

        // Input field callback
        if (\is_array($arrData['input_field_callback'] ?? null)) {
            $this->import($arrData['input_field_callback'][0]);

            return $this->{$arrData['input_field_callback'][0]}->{$arrData['input_field_callback'][1]}($this, $xlabel);
        }

        if (\is_callable($arrData['input_field_callback'] ?? null)) {
            return $arrData['input_field_callback']($this, $xlabel);
        }

        $strClass = $GLOBALS['BE_FFL'][($arrData['inputType'] ?? null)] ?? null;

        // Return if the widget class does not exists
        if (!class_exists($strClass)) {
            return '';
        }

        $arrData['eval']['required'] = false;

        if ($arrData['eval']['mandatory'] ?? null) {
            if (\is_array($this->varValue)) {
                if (empty($this->varValue)) {
                    $arrData['eval']['required'] = true;
                }
            }
            // Use strlen() here (see #3277)
            elseif (!\strlen($this->varValue)) {
                $arrData['eval']['required'] = true;
            }
        }

        // Convert insert tags in src attributes (see #5965)
        if (isset($arrData['eval']['rte']) && 0 === strncmp($arrData['eval']['rte'], 'tiny', 4)) {
            $this->varValue = StringUtil::insertTagToSrc($this->varValue);
        }

        // Use raw request if set globally but allow opting out setting useRawRequestData to false explicitly
        $useRawGlobally = isset($GLOBALS['TL_DCA'][$this->strTable]['config']['useRawRequestData']) && true === $GLOBALS['TL_DCA'][$this->strTable]['config']['useRawRequestData'];
        $notRawForField = isset($arrData['eval']['useRawRequestData']) && false === $arrData['eval']['useRawRequestData'];

        if ($useRawGlobally && !$notRawForField) {
            $arrData['eval']['useRawRequestData'] = true;
        }

        /** @var Widget $objWidget */
        $objWidget = new $strClass($strClass::getAttributesFromDca($arrData, $this->strInputName, $this->varValue, $this->strField, $this->strTable, $this));

        $objWidget->xlabel = $xlabel;
        $objWidget->currentRecord = $this->intId;

        // Validate the field
        if (Input::post('FORM_SUBMIT') === $this->strTable) {
            $suffix = $this->getFormFieldSuffix();
            $key = 'editAll' === Input::get('act') ? 'FORM_FIELDS_'.$suffix : 'FORM_FIELDS';

            // Calculate the current palette
            $postPaletteFields = implode(',', Input::post($key));
            $postPaletteFields = array_unique(StringUtil::trimsplit('[,;]', $postPaletteFields));

            // Compile the palette if there is none
            if (null === $strPalette) {
                $newPaletteFields = StringUtil::trimsplit('[,;]', $this->getPalette());
            } else {
                // Use the given palette ($strPalette is an array in editAll mode)
                $newPaletteFields = \is_array($strPalette) ? $strPalette : StringUtil::trimsplit('[,;]', $strPalette);

                // Recompile the palette if the current field is a selector field and the value has changed
                if (isset($GLOBALS['TL_DCA'][$this->strTable]['palettes']['__selector__']) && $this->varValue !== Input::post($this->strInputName) && \in_array($this->strField, $GLOBALS['TL_DCA'][$this->strTable]['palettes']['__selector__'], true)) {
                    $newPaletteFields = StringUtil::trimsplit('[,;]', $this->getPalette());
                }
            }

            // Adjust the names in editAll mode
            if ('editAll' === Input::get('act')) {
                foreach ($newPaletteFields as $k => $v) {
                    $newPaletteFields[$k] = $v.'_'.$suffix;
                }

                if ($this->User->isAdmin) {
                    $newPaletteFields['pid'] = 'pid_'.$suffix;
                    $newPaletteFields['sorting'] = 'sorting_'.$suffix;
                }
            }

            $paletteFields = array_intersect($postPaletteFields, $newPaletteFields);

            // Deprecated since Contao 4.2, to be removed in Contao 5.0
            if (!isset($_POST[$this->strInputName]) && \in_array($this->strInputName, $paletteFields, true)) {
                trigger_deprecation('contao/core-bundle', '4.2', 'Using $_POST[\'FORM_FIELDS\'] has been deprecated and will no longer work in Contao 5.0. Make sure to always submit at least an empty string in your widget.');
            }

            // Validate and save the field
            if ($objWidget->submitInput() && (\in_array($this->strInputName, $paletteFields, true) || 'overrideAll' === Input::get('act'))) {
                $objWidget->validate();

                if ($objWidget->hasErrors()) {
                    // Skip mandatory fields on auto-submit (see #4077)
                    if (!$objWidget->mandatory || $objWidget->value || 'auto' !== Input::post('SUBMIT_TYPE')) {
                        $this->noReload = true;
                    }
                }
                // The return value of submitInput() might have changed, therefore check it again here (see #2383)
                elseif ($objWidget->submitInput()) {
                    $varValue = $objWidget->value;

                    // Sort array by key (fix for JavaScript wizards)
                    if (\is_array($varValue)) {
                        ksort($varValue);
                        $varValue = serialize($varValue);
                    }

                    // Convert file paths in src attributes (see #5965)
                    if ($varValue && isset($arrData['eval']['rte']) && 0 === strncmp($arrData['eval']['rte'], 'tiny', 4)) {
                        $varValue = StringUtil::srcToInsertTag($varValue);
                    }

                    // Save the current value
                    try {
                        $this->save($varValue);
                    } catch (ResponseException $e) {
                        throw $e;
                    } catch (\Exception $e) {
                        $this->noReload = true;
                        $objWidget->addError($e->getMessage());
                    }
                }
            }
        }

        $wizard = '';
        $strHelpClass = '';

        // Date picker
        if ($arrData['eval']['datepicker'] ?? null) {
            $rgxp = $arrData['eval']['rgxp'] ?? 'date';
            $format = Date::formatToJs(Config::get($rgxp.'Format'));

            switch ($rgxp) {
                case 'datim':
                    $time = ",\n        timePicker: true";
                    break;

                case 'time':
                    $time = ",\n        pickOnly: \"time\"";
                    break;

                default:
                    $time = '';
                    break;
            }

            $strOnSelect = '';

            // Trigger the auto-submit function (see #8603)
            if ($arrData['eval']['submitOnChange'] ?? null) {
                $strOnSelect = ",\n        onSelect: function() { Backend.autoSubmit(\"".$this->strTable.'"); }';
            }

            $wizard .= ' '.Image::getHtml('assets/datepicker/images/icon.svg', '', 'title="'.StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['datepicker']).'" id="toggle_'.$objWidget->id.'" style="cursor:pointer"').'
  <script>
    window.addEvent("domready", function() {
      new Picker.Date($("ctrl_'.$objWidget->id.'"), {
        draggable: false,
        toggle: $("toggle_'.$objWidget->id.'"),
        format: "'.$format.'",
        positionOffset: {x:-211,y:-209}'.$time.',
        pickerClass: "datepicker_bootstrap",
        useFadeInOut: !Browser.ie'.$strOnSelect.',
        startDay: '.$GLOBALS['TL_LANG']['MSC']['weekOffset'].',
        titleFormat: "'.$GLOBALS['TL_LANG']['MSC']['titleFormat'].'"
      });
    });
  </script>';
        }

        // Color picker
        if ($arrData['eval']['colorpicker'] ?? null) {
            // Support single fields as well (see #5240)
            $strKey = $arrData['eval']['multiple'] ?? null ? $this->strField.'_0' : $this->strField;

            $wizard .= ' '.Image::getHtml('pickcolor.svg', $GLOBALS['TL_LANG']['MSC']['colorpicker'], 'title="'.StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['colorpicker']).'" id="moo_'.$this->strField.'" style="cursor:pointer"').'
  <script>
    window.addEvent("domready", function() {
      var cl = $("ctrl_'.$strKey.'").value.hexToRgb(true) || [255, 0, 0];
      new MooRainbow("moo_'.$this->strField.'", {
        id: "ctrl_'.$strKey.'",
        startColor: cl,
        imgPath: "assets/colorpicker/images/",
        onComplete: function(color) {
          $("ctrl_'.$strKey.'").value = color.hex.replace("#", "");
        }
      });
    });
  </script>';
        }

        $arrClasses = StringUtil::trimsplit(' ', $arrData['eval']['tl_class'] ?? '');

        // DCA picker
        if (isset($arrData['eval']['dcaPicker']) && (\is_array($arrData['eval']['dcaPicker']) || true === $arrData['eval']['dcaPicker'])) {
            $arrClasses[] = 'dcapicker';
            $wizard .= Backend::getDcaPickerWizard($arrData['eval']['dcaPicker'], $this->strTable, $this->strField, $this->strInputName);
        }

        if (($arrData['inputType'] ?? null) === 'password') {
            $wizard .= Backend::getTogglePasswordWizard($this->strInputName);
        }

        // Add a custom wizard
        if (\is_array($arrData['wizard'] ?? null)) {
            foreach ($arrData['wizard'] as $callback) {
                if (\is_array($callback)) {
                    $this->import($callback[0]);
                    $wizard .= $this->{$callback[0]}->{$callback[1]}($this);
                } elseif (\is_callable($callback)) {
                    $wizard .= $callback($this);
                }
            }
        }

        $hasWizardClass = \in_array('wizard', $arrClasses, true);

        if ($wizard) {
            $objWidget->wizard = $wizard;

            if (!$hasWizardClass) {
                $arrClasses[] = 'wizard';
            }
        } elseif ($hasWizardClass) {
            unset($arrClasses[array_search('wizard', $arrClasses, true)]);
        }

        // Set correct form enctype
        if ($objWidget instanceof \uploadable) {
            $this->blnUploadable = true;
        }

        $arrClasses[] = 'widget';

        // Mark floated single checkboxes
        if (($arrData['inputType'] ?? null) === 'checkbox' && !($arrData['eval']['multiple'] ?? null) && \in_array('w50', $arrClasses, true)) {
            $arrClasses[] = 'cbx';
        } elseif (($arrData['inputType'] ?? null) === 'text' && ($arrData['eval']['multiple'] ?? null) && \in_array('wizard', $arrClasses, true)) {
            $arrClasses[] = 'inline';
        }

        if (!empty($arrClasses)) {
            $arrData['eval']['tl_class'] = implode(' ', array_unique($arrClasses));
        }

        $updateMode = '';

        // Replace the textarea with an RTE instance
        if (!empty($arrData['eval']['rte'])) {
            [$file, $type] = explode('|', $arrData['eval']['rte'], 2) + [null, null];

            $fileBrowserTypes = [];
            $pickerBuilder = System::getContainer()->get('contao.picker.builder');

            foreach (['file' => 'image', 'link' => 'file'] as $context => $fileBrowserType) {
                if ($pickerBuilder->supportsContext($context)) {
                    $fileBrowserTypes[] = $fileBrowserType;
                }
            }

            $objTemplate = new BackendTemplate('be_'.$file);
            $objTemplate->selector = 'ctrl_'.$this->strInputName;
            $objTemplate->type = $type;
            $objTemplate->fileBrowserTypes = $fileBrowserTypes;
            $objTemplate->source = $this->strTable.'.'.$this->intId;

            // Deprecated since Contao 4.0, to be removed in Contao 5.0
            $objTemplate->language = Backend::getTinyMceLanguage();

            $updateMode = $objTemplate->parse();

            unset($file, $type, $pickerBuilder, $fileBrowserTypes, $fileBrowserType);
        }

        // Handle multi-select fields in "override all" mode
        elseif ((($arrData['inputType'] ?? null) === 'checkbox' || ($arrData['inputType'] ?? null) === 'checkboxWizard') && ($arrData['eval']['multiple'] ?? null) && 'overrideAll' === Input::get('act')) {
            $updateMode = '
</div>
<div class="widget">
  <fieldset class="tl_radio_container">
  <legend>'.$GLOBALS['TL_LANG']['MSC']['updateMode'].'</legend>
    <input type="radio" name="'.$this->strInputName.'_update" id="opt_'.$this->strInputName.'_update_1" class="tl_radio" value="add" onfocus="Backend.getScrollOffset()"> <label for="opt_'.$this->strInputName.'_update_1">'.$GLOBALS['TL_LANG']['MSC']['updateAdd'].'</label><br>
    <input type="radio" name="'.$this->strInputName.'_update" id="opt_'.$this->strInputName.'_update_2" class="tl_radio" value="remove" onfocus="Backend.getScrollOffset()"> <label for="opt_'.$this->strInputName.'_update_2">'.$GLOBALS['TL_LANG']['MSC']['updateRemove'].'</label><br>
    <input type="radio" name="'.$this->strInputName.'_update" id="opt_'.$this->strInputName.'_update_0" class="tl_radio" value="replace" checked="checked" onfocus="Backend.getScrollOffset()"> <label for="opt_'.$this->strInputName.'_update_0">'.$GLOBALS['TL_LANG']['MSC']['updateReplace'].'</label>
  </fieldset>';
        }

        $strPreview = '';

        // Show a preview image (see #4948)
        if ('tl_files' === $this->strTable && 'name' === $this->strField && null !== $this->objActiveRecord && 'file' === $this->objActiveRecord->type) {
            $objFile = new File($this->objActiveRecord->path);

            if ($objFile->isImage) {
                $blnCanResize = true;

                if ($objFile->isSvgImage) {
                    // SVG images with undefined sizes cannot be resized
                    if (!$objFile->viewWidth || !$objFile->viewHeight) {
                        $blnCanResize = false;
                    }
                } elseif (System::getContainer()->get('contao.image.imagine') instanceof Imagine) {
                    // Check the maximum width and height if the GDlib is used to resize images
                    if ($objFile->height > Config::get('gdMaxImgHeight') || $objFile->width > Config::get('gdMaxImgWidth')) {
                        $blnCanResize = false;
                    }
                }

                if ($blnCanResize) {
                    $container = System::getContainer();
                    $projectDir = $container->getParameter('kernel.project_dir');
                    $image = rawurldecode($container->get('contao.image.image_factory')->create($projectDir.'/'.$objFile->path, [699, 524, ResizeConfiguration::MODE_BOX])->getUrl($projectDir));
                } else {
                    $image = Image::getPath('placeholder.svg');
                }

                $objImage = new File($image);
                $ctrl = 'ctrl_preview_'.substr(md5($image), 0, 8);

                $strPreview = '
<div id="'.$ctrl.'" class="tl_edit_preview">
  <img src="'.$objImage->dataUri.'" width="'.$objImage->width.'" height="'.$objImage->height.'" alt="">
</div>';

                // Add the script to mark the important part
                if ('placeholder.svg' !== basename($image)) {
                    $strPreview .= '<script>Backend.editPreviewWizard($(\''.$ctrl.'\'));</script>';

                    if (Config::get('showHelp')) {
                        $strPreview .= '<p class="tl_help tl_tip">'.$GLOBALS['TL_LANG'][$this->strTable]['edit_preview_help'].'</p>';
                    }

                    $strPreview = '<div class="widget">'.$strPreview.'</div>';
                }
            }
        }

        return $strPreview.'
<div'.(!empty($arrData['eval']['tl_class']) ? ' class="'.trim($arrData['eval']['tl_class']).'"' : '').'>'.$objWidget->parse().$updateMode.(!$objWidget->hasErrors() ? $this->help($strHelpClass) : '').'
</div>';
    }

    /**
     * Generate possible palette names from an array by taking the first value and either adding or not adding the following values.
     *
     * @param array $names
     *
     * @return array
     */
    protected function combiner($names)
    {
        $return = [''];
        $names = array_values($names);

        for ($i = 0, $c = \count($names); $i < $c; ++$i) {
            $buffer = [];

            foreach ($return as $k => $v) {
                $buffer[] = 0 === $k % 2 ? $v : $v.$names[$i];
                $buffer[] = 0 === $k % 2 ? $v.$names[$i] : $v;
            }

            $return = $buffer;
        }

        return array_filter($return);
    }

    /**
     * Return a query string that switches into edit mode.
     *
     * @param int $id
     *
     * @return string
     */
    protected function switchToEdit($id)
    {
        $arrKeys = [];
        $arrUnset = ['act', 'id', 'table', 'mode', 'pid'];

        foreach (array_keys($_GET) as $strKey) {
            if (!\in_array($strKey, $arrUnset, true)) {
                $arrKeys[$strKey] = $strKey.'='.Input::get($strKey);
            }
        }

        $strUrl = TL_SCRIPT.'?'.implode('&', $arrKeys);

        return $strUrl.(!empty($arrKeys) ? '&' : '').(Input::get('table') ? 'table='.Input::get('table').'&amp;' : '').'act=edit&amp;id='.rawurlencode($id);
    }

    /**
     * Compile buttons from the table configuration array and return them as HTML.
     *
     * @param array  $arrRow
     * @param string $strTable
     * @param array  $arrRootIds
     * @param bool   $blnCircularReference
     * @param array  $arrChildRecordIds
     * @param string $strPrevious
     * @param string $strNext
     *
     * @return string
     */
    protected function generateButtons($arrRow, $strTable, $arrRootIds = [], $blnCircularReference = false, $arrChildRecordIds = null, $strPrevious = null, $strNext = null)
    {
        if (!\is_array($GLOBALS['TL_DCA'][$strTable]['list']['operations'] ?? null)) {
            return '';
        }

        $return = '';

        foreach ($GLOBALS['TL_DCA'][$strTable]['list']['operations'] as $k => $v) {
            $v = \is_array($v) ? $v : [$v];
            $id = StringUtil::specialchars(rawurldecode($arrRow['id']));
            $label = $title = $k;

            if (isset($v['label'])) {
                if (\is_array($v['label'])) {
                    $label = $v['label'][0] ?? null;
                    $title = sprintf($v['label'][1] ?? '', $id);
                } else {
                    $label = $title = sprintf($v['label'], $id);
                }
            }

            $attributes = !empty($v['attributes']) ? ' '.ltrim(sprintf($v['attributes'], $id, $id)) : '';

            // Add the key as CSS class
            if (false !== strpos($attributes, 'class="')) {
                $attributes = str_replace('class="', 'class="'.$k.' ', $attributes);
            } else {
                $attributes = ' class="'.$k.'"'.$attributes;
            }

            // Call a custom function instead of using the default button
            if (\is_array($v['button_callback'] ?? null)) {
                $this->import($v['button_callback'][0]);
                $return .= $this->{$v['button_callback'][0]}->{$v['button_callback'][1]}($arrRow, $v['href'] ?? null, $label, $title, $v['icon'] ?? null, $attributes, $strTable, $arrRootIds, $arrChildRecordIds, $blnCircularReference, $strPrevious, $strNext, $this);
                continue;
            }

            if (\is_callable($v['button_callback'] ?? null)) {
                $return .= $v['button_callback']($arrRow, $v['href'] ?? null, $label, $title, $v['icon'] ?? null, $attributes, $strTable, $arrRootIds, $arrChildRecordIds, $blnCircularReference, $strPrevious, $strNext, $this);
                continue;
            }

            // Generate all buttons except "move up" and "move down" buttons
            if ('move' !== $k && 'move' !== $v) {
                if ('show' === $k) {
                    if (!empty($v['route'])) {
                        $href = System::getContainer()->get('router')->generate($v['route'], ['id' => $arrRow['id'], 'popup' => '1']);
                    } else {
                        $href = Backend::addToUrl($v['href'].'&amp;id='.$arrRow['id'].'&amp;popup=1');
                    }

                    $return .= '<a href="'.$href.'" title="'.StringUtil::specialchars($title).'" onclick="Backend.openModalIframe({\'title\':\''.StringUtil::specialchars(str_replace("'", "\\'", $label)).'\',\'url\':this.href});return false"'.$attributes.'>'.Image::getHtml($v['icon'], $label).'</a> ';
                } else {
                    if (!empty($v['route'])) {
                        $href = System::getContainer()->get('router')->generate($v['route'], ['id' => $arrRow['id']]);
                    } else {
                        $href = Backend::addToUrl($v['href'].'&amp;id='.$arrRow['id'].(Input::get('nb') ? '&amp;nc=1' : ''));
                    }

                    $return .= '<a href="'.$href.'" title="'.StringUtil::specialchars($title).'"'.$attributes.'>'.Image::getHtml($v['icon'], $label).'</a> ';
                }

                continue;
            }

            $arrDirections = ['up', 'down'];
            $arrRootIds = \is_array($arrRootIds) ? $arrRootIds : [$arrRootIds];

            foreach ($arrDirections as $dir) {
                $label = !empty($GLOBALS['TL_LANG'][$strTable][$dir][0]) ? $GLOBALS['TL_LANG'][$strTable][$dir][0] : $dir;
                $title = !empty($GLOBALS['TL_LANG'][$strTable][$dir][1]) ? $GLOBALS['TL_LANG'][$strTable][$dir][1] : $dir;

                $label = Image::getHtml($dir.'.svg', $label);
                $href = !empty($v['href']) ? $v['href'] : '&amp;act=move';

                if ('up' === $dir) {
                    $return .= (is_numeric($strPrevious) && (empty($GLOBALS['TL_DCA'][$strTable]['list']['sorting']['root']) || !\in_array($arrRow['id'], $arrRootIds, true)) ? '<a href="'.Backend::addToUrl($href.'&amp;id='.$arrRow['id']).'&amp;sid='.(int) $strPrevious.'" title="'.StringUtil::specialchars($title).'"'.$attributes.'>'.$label.'</a> ' : Image::getHtml('up_.svg')).' ';
                } else {
                    $return .= (is_numeric($strNext) && (empty($GLOBALS['TL_DCA'][$strTable]['list']['sorting']['root']) || !\in_array($arrRow['id'], $arrRootIds, true)) ? '<a href="'.Backend::addToUrl($href.'&amp;id='.$arrRow['id']).'&amp;sid='.(int) $strNext.'" title="'.StringUtil::specialchars($title).'"'.$attributes.'>'.$label.'</a> ' : Image::getHtml('down_.svg')).' ';
                }
            }
        }

        return trim($return);
    }

    /**
     * Compile global buttons from the table configuration array and return them as HTML.
     *
     * @return string
     */
    protected function generateGlobalButtons()
    {
        if (!\is_array($GLOBALS['TL_DCA'][$this->strTable]['list']['global_operations'] ?? null)) {
            return '';
        }

        $return = '';

        foreach ($GLOBALS['TL_DCA'][$this->strTable]['list']['global_operations'] as $k => $v) {
            if (!($v['showOnSelect'] ?? null) && 'select' === Input::get('act')) {
                continue;
            }

            $v = \is_array($v) ? $v : [$v];
            $label = \is_array($v['label']) ? $v['label'][0] : $v['label'];
            $title = \is_array($v['label']) ? ($v['label'][1] ?? null) : $v['label'];
            $attributes = !empty($v['attributes']) ? ' '.ltrim($v['attributes']) : '';

            // Custom icon (see #5541)
            if ($v['icon'] ?? null) {
                $v['class'] = trim(($v['class'] ?? '').' header_icon');

                // Add the theme path if only the file name is given
                if (false === strpos($v['icon'], '/')) {
                    $v['icon'] = Image::getPath($v['icon']);
                }

                $attributes = sprintf(' style="background-image:url(\'%s\')"', Controller::addAssetsUrlTo($v['icon'])).$attributes;
            }

            if (!$label) {
                $label = $k;
            }

            if (!$title) {
                $title = $label;
            }

            // Call a custom function instead of using the default button
            if (\is_array($v['button_callback'] ?? null)) {
                $this->import($v['button_callback'][0]);
                $return .= $this->{$v['button_callback'][0]}->{$v['button_callback'][1]}($v['href'], $label, $title, $v['class'], $attributes, $this->strTable, $this->root);
                continue;
            }

            if (\is_callable($v['button_callback'] ?? null)) {
                $return .= $v['button_callback']($v['href'], $label, $title, $v['class'], $attributes, $this->strTable, $this->root);
                continue;
            }

            if (!empty($v['route'])) {
                $href = System::getContainer()->get('router')->generate($v['route']);
            } else {
                $href = Backend::addToUrl($v['href']);
            }

            $return .= '<a href="'.$href.'" class="'.$v['class'].'" title="'.StringUtil::specialchars($title).'"'.$attributes.'>'.$label.'</a> ';
        }

        return $return;
    }

    /**
     * Compile header buttons from the table configuration array and return them as HTML.
     *
     * @param array  $arrRow
     * @param string $strPtable
     *
     * @return string
     */
    protected function generateHeaderButtons($arrRow, $strPtable)
    {
        if (!\is_array($GLOBALS['TL_DCA'][$strPtable]['list']['operations'] ?? null)) {
            return '';
        }

        $return = '';

        foreach ($GLOBALS['TL_DCA'][$strPtable]['list']['operations'] as $k => $v) {
            if (empty($v['showInHeader']) || ('select' === Input::get('act') && !($v['showOnSelect'] ?? null))) {
                continue;
            }

            $v = \is_array($v) ? $v : [$v];
            $id = StringUtil::specialchars(rawurldecode($arrRow['id']));
            $label = $title = $k;

            if (isset($v['label'])) {
                if (\is_array($v['label'])) {
                    $label = $v['label'][0];
                    $title = sprintf($v['label'][1], $id);
                } else {
                    $label = $title = sprintf($v['label'], $id);
                }
            }

            $attributes = !empty($v['attributes']) ? ' '.ltrim(sprintf($v['attributes'], $id, $id)) : '';

            // Add the key as CSS class
            if (false !== strpos($attributes, 'class="')) {
                $attributes = str_replace('class="', 'class="'.$k.' ', $attributes);
            } else {
                $attributes = ' class="'.$k.'"'.$attributes;
            }

            // Add the parent table to the href
            if (isset($v['href'])) {
                $v['href'] .= '&amp;table='.$strPtable;
            } else {
                $v['href'] = 'table='.$strPtable;
            }

            // Call a custom function instead of using the default button
            if (\is_array($v['button_callback'] ?? null)) {
                $this->import($v['button_callback'][0]);
                $return .= $this->{$v['button_callback'][0]}->{$v['button_callback'][1]}($arrRow, $v['href'], $label, $title, $v['icon'], $attributes, $strPtable, [], null, false, null, null, $this);
                continue;
            }

            if (\is_callable($v['button_callback'] ?? null)) {
                $return .= $v['button_callback']($arrRow, $v['href'], $label, $title, $v['icon'], $attributes, $strPtable, [], null, false, null, null, $this);
                continue;
            }

            if ('show' === $k) {
                if (!empty($v['route'])) {
                    $href = System::getContainer()->get('router')->generate($v['route'], ['id' => $arrRow['id'], 'popup' => '1']);
                } else {
                    $href = Backend::addToUrl($v['href'].'&amp;id='.$arrRow['id'].'&amp;popup=1');
                }

                $return .= '<a href="'.$href.'" title="'.StringUtil::specialchars($title).'" onclick="Backend.openModalIframe({\'title\':\''.StringUtil::specialchars(str_replace("'", "\\'", sprintf(\is_array($GLOBALS['TL_LANG'][$strPtable]['show'] ?? null) ? $GLOBALS['TL_LANG'][$strPtable]['show'][1] : ($GLOBALS['TL_LANG'][$strPtable]['show'] ?? ''), $arrRow['id']))).'\',\'url\':this.href});return false"'.$attributes.'>'.Image::getHtml($v['icon'], $label).'</a> ';
            } else {
                if (!empty($v['route'])) {
                    $href = System::getContainer()->get('router')->generate($v['route'], ['id' => $arrRow['id']]);
                } else {
                    $href = Backend::addToUrl($v['href'].'&amp;id='.$arrRow['id'].(Input::get('nb') ? '&amp;nc=1' : ''));
                }

                $return .= '<a href="'.$href.'" title="'.StringUtil::specialchars($title).'"'.$attributes.'>'.Image::getHtml($v['icon'], $label).'</a> ';
            }
        }

        return $return;
    }

    /**
     * Return the picker input field markup.
     *
     * @param string $value
     * @param string $attributes
     *
     * @return string
     */
    protected function getPickerInputField($value, $attributes = '')
    {
        $id = is_numeric($value) ? $value : md5($value);

        switch ($this->strPickerFieldType) {
            case 'checkbox':
                return ' <input type="checkbox" name="picker[]" id="picker_'.$id.'" class="tl_tree_checkbox" value="'.StringUtil::specialchars(($this->objPickerCallback)($value)).'" onfocus="Backend.getScrollOffset()"'.Widget::optionChecked($value, $this->arrPickerValue).$attributes.'>';

            case 'radio':
                return ' <input type="radio" name="picker" id="picker_'.$id.'" class="tl_tree_radio" value="'.StringUtil::specialchars(($this->objPickerCallback)($value)).'" onfocus="Backend.getScrollOffset()"'.Widget::optionChecked($value, $this->arrPickerValue).$attributes.'>';
        }

        return '';
    }

    /**
     * Return the data-picker-value attribute with the currently selected picker values (see #1816).
     *
     * @return string
     */
    protected function getPickerValueAttribute()
    {
        // Only load the previously selected values for the checkbox field type (see #2346)
        if ('checkbox' !== $this->strPickerFieldType) {
            return '';
        }

        $values = array_map($this->objPickerCallback, $this->arrPickerValue);
        $values = array_map('strval', $values);
        $values = json_encode($values);
        $values = htmlspecialchars($values);

        return ' data-picker-value="'.$values.'"';
    }

    /**
     * Build the sort panel and return it as string.
     *
     * @return string
     */
    protected function panel()
    {
        if (!($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['panelLayout'] ?? null)) {
            return '';
        }

        // Reset all filters
        if (isset($_POST['filter_reset']) && 'tl_filters' === Input::post('FORM_SUBMIT')) {
            /** @var AttributeBagInterface $objSessionBag */
            $objSessionBag = System::getContainer()->get('session')->getBag('contao_backend');

            $data = $objSessionBag->all();

            unset(
                $data['filter'][$this->strTable],
                $data['filter'][$this->strTable.'_'.CURRENT_ID],
                $data['sorting'][$this->strTable],
                $data['search'][$this->strTable]
            );

            $objSessionBag->replace($data);

            $this->reload();
        }

        $intFilterPanel = 0;
        $arrPanels = [];
        $arrPanes = StringUtil::trimsplit(';', $GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['panelLayout'] ?? '');

        foreach ($arrPanes as $strPanel) {
            $panels = '';
            $arrSubPanels = StringUtil::trimsplit(',', $strPanel);

            foreach ($arrSubPanels as $strSubPanel) {
                $panel = '';

                switch ($strSubPanel) {
                    case 'limit':
                        // The limit menu depends on other panels that may set a filter query, e.g. search and filter.
                        // In order to correctly calculate the total row count, the limit menu must be compiled last.
                        // We insert a placeholder here and compile the limit menu after all other panels.
                        $panel = '###limit_menu###';
                        break;

                    case 'search':
                        $panel = $this->searchMenu();
                        break;

                    case 'sort':
                        $panel = $this->sortMenu();
                        break;

                    case 'filter':
                        // Multiple filter subpanels can be defined to split the fields across panels
                        $panel = $this->filterMenu(++$intFilterPanel);
                        break;

                    default:
                        // Call the panel_callback
                        $arrCallback = $GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['panel_callback'][$strSubPanel] ?? null;

                        if (\is_array($arrCallback)) {
                            $this->import($arrCallback[0]);
                            $panel = $this->{$arrCallback[0]}->{$arrCallback[1]}($this);
                        } elseif (\is_callable($arrCallback)) {
                            $panel = $arrCallback($this);
                        }
                }

                // Add the panel if it is not empty
                if ($panel) {
                    $panels = $panel.$panels;
                }
            }

            // Add the group if it is not empty
            if ($panels) {
                $arrPanels[] = $panels;
            }
        }

        if (empty($arrPanels)) {
            return '';
        }

        // Compile limit menu if placeholder is present
        foreach ($arrPanels as $key => $strPanel) {
            if (false === strpos($strPanel, '###limit_menu###')) {
                continue;
            }

            $arrPanels[$key] = str_replace('###limit_menu###', $this->limitMenu(), $strPanel);
        }

        if ('tl_filters' === Input::post('FORM_SUBMIT')) {
            $this->reload();
        }

        $return = '';
        $intTotal = \count($arrPanels);
        $intLast = $intTotal - 1;

        for ($i = 0; $i < $intTotal; ++$i) {
            $submit = '';

            if ($i === $intLast) {
                $submit = '
<div class="tl_submit_panel tl_subpanel">
  <button name="filter" id="filter" class="tl_img_submit filter_apply" title="'.StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['applyTitle']).'">'.$GLOBALS['TL_LANG']['MSC']['apply'].'</button>
  <button name="filter_reset" id="filter_reset" value="1" class="tl_img_submit filter_reset" title="'.StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['resetTitle']).'">'.$GLOBALS['TL_LANG']['MSC']['reset'].'</button>
</div>';
            }

            $return .= '
<div class="tl_panel cf">
  '.$submit.$arrPanels[$i].'
</div>';
        }

        $return = '
<form class="tl_form" method="post" aria-label="'.StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['searchAndFilter']).'">
<div class="tl_formbody">
  <input type="hidden" name="FORM_SUBMIT" value="tl_filters">
  <input type="hidden" name="REQUEST_TOKEN" value="'.REQUEST_TOKEN.'">
  '.$return.'
</div>
</form>';

        return $return;
    }

    /**
     * Return the form field suffix.
     *
     * @return int|string
     */
    protected function getFormFieldSuffix()
    {
        return $this->intId;
    }
}
