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

use Contao\BackendCustom;
use Contao\BackendUser;
use Contao\Controller;
use Contao\CoreBundle\Exception\AccessDeniedException;
use Contao\CoreBundle\Picker\PickerInterface;
use Contao\DataContainer;
use Contao\Input;
use Contao\System;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;

/**
 * @internal
 */
class BackendDcController extends AbstractController
{
    public function listAction(string $module, string $act = 'showAll'): Response
    {
        return $this->run($this->getBackendModule($module, $act));
    }

    public function itemAction(string $module, string $id, string $act = 'edit'): Response
    {
        Input::setGet('id', $id);

        return $this->run($this->getBackendModule($module, $act));
    }

    public function childTableListAction(string $module, string $table, string $id, string $action): Response
    {
        Input::setGet('id', $id);

        return $this->run($this->getBackendModule($module, $action, $table));
    }

    public function childTableItemAction(string $module, string $table, string $id, string $action): Response
    {
        Input::setGet('id', $id);

        return $this->run($this->getBackendModule($module, $action, $table));
    }

    public function run(?string $main): Response
    {
        $backend = new BackendCustom();
        $backend->getTemplateObject()->main = $main;

        return $backend->run();
    }

    private function getBackendModule(string $module, string $act = 'showAll', string $table = null, PickerInterface $picker = null)
    {
        $arrModule = [];

        foreach ($GLOBALS['BE_MOD'] as &$arrGroup) {
            if (isset($arrGroup[$module])) {
                $arrModule = &$arrGroup[$module];
                break;
            }
        }

        unset($arrGroup);

        $user = BackendUser::getInstance();

        // Check whether the current user has access to the current module
        $blnAccess = (isset($arrModule['disablePermissionChecks']) && true === $arrModule['disablePermissionChecks']) || $user->hasAccess($module, 'modules');

        if (!$blnAccess) {
            throw new AccessDeniedException(sprintf('Back end module "%s" is not allowed for user "%s".', $module, $user->username));
        }

        // The module does not exist
        if (empty($arrModule)) {
            return null;
        }

        /** @var Session $objSession */
        $objSession = System::getContainer()->get('session');

        $arrTables = (array) ($arrModule['tables'] ?? []);
        $strTable = $table ?? ($arrTables[0] ?? null);

        $id = !Input::get('act') && Input::get('id') ? Input::get('id') : $objSession->get('CURRENT_ID');
        \define('CURRENT_ID', (Input::get('table') ? $id : Input::get('id')));

//        if (isset($GLOBALS['TL_LANG']['MOD'][$module][0]))
//        {
//           $template->headline = $GLOBALS['TL_LANG']['MOD'][$module][0];
//        }

        // Add the module style sheet
        if (isset($arrModule['stylesheet'])) {
            foreach ((array) $arrModule['stylesheet'] as $stylesheet) {
                $GLOBALS['TL_CSS'][] = $stylesheet;
            }
        }

        // Add module javascript
        if (isset($arrModule['javascript'])) {
            foreach ((array) $arrModule['javascript'] as $javascript) {
                $GLOBALS['TL_JAVASCRIPT'][] = $javascript;
            }
        }

        $dc = null;

        // Create the data container object
        if ($strTable) {
            if (!\in_array($strTable, $arrTables, true)) {
                throw new AccessDeniedException(sprintf('Table "%s" is not allowed in module "%s".', $strTable, $module));
            }

            // Load the language and DCA file
            System::loadLanguageFile($strTable);
            Controller::loadDataContainer($strTable);

            // Include all excluded fields which are allowed for the current user
            if (\is_array($GLOBALS['TL_DCA'][$strTable]['fields'] ?? null)) {
                foreach ($GLOBALS['TL_DCA'][$strTable]['fields'] as $k => $v) {
                    if (($v['exclude'] ?? null) && $user->hasAccess($strTable.'::'.$k, 'alexf')) {
                        if ('tl_user_group' === $strTable) {
                            $GLOBALS['TL_DCA'][$strTable]['fields'][$k]['orig_exclude'] = $GLOBALS['TL_DCA'][$strTable]['fields'][$k]['exclude'];
                        }

                        $GLOBALS['TL_DCA'][$strTable]['fields'][$k]['exclude'] = false;
                    }
                }
            }

            // Fabricate a new data container object
            if (!isset($GLOBALS['TL_DCA'][$strTable]['config']['dataContainer'])) {
                System::log('Missing data container for table "'.$strTable.'"', __METHOD__, TL_ERROR);
                trigger_error('Could not create a data container object', E_USER_ERROR);
            }

            $dataContainer = DataContainer::getDriverForTable($strTable);

            /** @var DataContainer $dc */
            $dc = new $dataContainer($strTable, $arrModule);

            if (null !== $picker && $dc instanceof DataContainer) {
                $dc->initPicker($picker);
            }
        }

        // Wrap the existing headline
        //$this->Template->headline = '<span>' .$template->headline . '</span>';

        // AJAX request
//        if ($_POST && Environment::get('isAjaxRequest'))
//        {
//            $this->objAjax->executePostActions($dc);
//        }

        // Trigger the module callback
//        elseif (class_exists($arrModule['callback'] ?? null))
//        {
//            /** @var Module $objCallback */
//            $objCallback = new $arrModule['callback']($dc);
//
//           $template->main .= $objCallback->generate();
//        }

        // Custom action (if key is not defined in config.php the default action will be called)
//        elseif (Input::get('key') && isset($arrModule[Input::get('key')]))
//        {
//            $objCallback = System::importStatic($arrModule[Input::get('key')][0]);
//            $response = $objCallback->{$arrModule[Input::get('key')][1]}($dc);
//
//            if ($response instanceof RedirectResponse)
//            {
//                throw new ResponseException($response);
//            }
//
//            if ($response instanceof Response)
//            {
//                $response = $response->getContent();
//            }
//
//           $template->main .= $response;
//
//            // Add the name of the parent element
//            if (isset($_GET['table']) && !empty($GLOBALS['TL_DCA'][$strTable]['config']['ptable']) && \in_array(Input::get('table'), $arrTables) && Input::get('table') != $arrTables[0])
//            {
//                $objRow = $this->Database->prepare("SELECT * FROM " . $GLOBALS['TL_DCA'][$strTable]['config']['ptable'] . " WHERE id=(SELECT pid FROM $strTable WHERE id=?)")
//                    ->limit(1)
//                    ->execute(Input::get('id'));
//
//                if ($objRow->title)
//                {
//                   $template->headline .= ' <span>' . $objRow->title . '</span>';
//                }
//                elseif ($objRow->name)
//                {
//                   $template->headline .= ' <span>' . $objRow->name . '</span>';
//                }
//            }
//
//            // Add the name of the submodule
//           $template->headline .= ' <span>' . sprintf($GLOBALS['TL_LANG'][$strTable][Input::get('key')][1], Input::get('id')) . '</span>';
//        }

        if (!$act || 'paste' === $act || 'select' === $act) {
            $act = $dc instanceof \listable ? 'showAll' : 'edit';
        }

        // Add the name of the parent elements
//            if ($strTable && \in_array($strTable, $arrTables) && $strTable != $arrTables[0])
//            {
//                $trail = array();
//
//                $pid = $dc->id;
//                $table = $strTable;
//                $ptable = $act != 'edit' ? ($GLOBALS['TL_DCA'][$strTable]['config']['ptable'] ?? null) : $strTable;
//
//                while ($ptable && !\in_array($GLOBALS['TL_DCA'][$table]['list']['sorting']['mode'] ?? null, array(5, 6)) && ($GLOBALS['TL_DCA'][$ptable]['config']['dataContainer'] ?? null) === 'Table')
//                {
//                    $objRow = $this->Database->prepare("SELECT * FROM " . $ptable . " WHERE id=?")
//                        ->limit(1)
//                        ->execute($pid);
//
//                    // Add only parent tables to the trail
//                    if ($table != $ptable)
//                    {
//                        // Add table name
//                        if (isset($GLOBALS['TL_LANG']['MOD'][$table]))
//                        {
//                            $trail[] = ' <span>' . $GLOBALS['TL_LANG']['MOD'][$table] . '</span>';
//                        }
//
//                        // Add object title or name
//                        if ($objRow->title)
//                        {
//                            $trail[] = ' <span>' . $objRow->title . '</span>';
//                        }
//                        elseif ($objRow->name)
//                        {
//                            $trail[] = ' <span>' . $objRow->name . '</span>';
//                        }
//                        elseif ($objRow->headline)
//                        {
//                            $trail[] = ' <span>' . $objRow->headline . '</span>';
//                        }
//                    }
//
//                    System::loadLanguageFile($ptable);
//                    $this->loadDataContainer($ptable);
//
//                    // Next parent table
//                    $pid = $objRow->pid;
//                    $table = $ptable;
//                    $ptable = ($GLOBALS['TL_DCA'][$ptable]['config']['dynamicPtable'] ?? null) ? $objRow->ptable : ($GLOBALS['TL_DCA'][$ptable]['config']['ptable'] ?? null);
//                }
//
//                // Add the last parent table
//                if (isset($GLOBALS['TL_LANG']['MOD'][$table]))
//                {
//                    $trail[] = ' <span>' . $GLOBALS['TL_LANG']['MOD'][$table] . '</span>';
//                }
//
//                // Add the breadcrumb trail in reverse order
//                foreach (array_reverse($trail) as $breadcrumb)
//                {
//                   $template->headline .= $breadcrumb;
//                }
//            }

//            // Add the current action
//            if ($act == 'editAll')
//            {
//                if (isset($GLOBALS['TL_LANG']['MSC']['all'][0]))
//                {
//                   $template->headline .= ' <span>' . $GLOBALS['TL_LANG']['MSC']['all'][0] . '</span>';
//                }
//            }
//            elseif ($act == 'overrideAll')
//            {
//                if (isset($GLOBALS['TL_LANG']['MSC']['all_override'][0]))
//                {
//                   $template->headline .= ' <span>' . $GLOBALS['TL_LANG']['MSC']['all_override'][0] . '</span>';
//                }
//            }
//            elseif (Input::get('id'))
//            {
//                if ($do == 'files' || $do == 'tpl_editor')
//                {
//                    // Handle new folders (see #7980)
//                    if (strpos(Input::get('id'), '__new__') !== false)
//                    {
//                       $template->headline .= ' <span>' . \dirname(Input::get('id')) . '</span> <span>' . $GLOBALS['TL_LANG'][$strTable]['new'][1] . '</span>';
//                    }
//                    else
//                    {
//                       $template->headline .= ' <span>' . Input::get('id') . '</span>';
//                    }
//                }
//                elseif (isset($GLOBALS['TL_LANG'][$strTable][$act]))
//                {
//                    if (\is_array($GLOBALS['TL_LANG'][$strTable][$act]))
//                    {
//                       $template->headline .= ' <span>' . sprintf($GLOBALS['TL_LANG'][$strTable][$act][1], Input::get('id')) . '</span>';
//                    }
//                    else
//                    {
//                       $template->headline .= ' <span>' . sprintf($GLOBALS['TL_LANG'][$strTable][$act], Input::get('id')) . '</span>';
//                    }
//                }
//            }
//            elseif (Input::get('pid'))
//            {
//                if ($do == 'files' || $do == 'tpl_editor')
//                {
//                    if ($act == 'move')
//                    {
//                       $template->headline .= ' <span>' . Input::get('pid') . '</span> <span>' . $GLOBALS['TL_LANG'][$strTable]['move'][1] . '</span>';
//                    }
//                    else
//                    {
//                       $template->headline .= ' <span>' . Input::get('pid') . '</span>';
//                    }
//                }
//                elseif (isset($GLOBALS['TL_LANG'][$strTable][$act]))
//                {
//                    if (\is_array($GLOBALS['TL_LANG'][$strTable][$act]))
//                    {
//                       $template->headline .= ' <span>' . sprintf($GLOBALS['TL_LANG'][$strTable][$act][1], Input::get('pid')) . '</span>';
//                    }
//                    else
//                    {
//                       $template->headline .= ' <span>' . sprintf($GLOBALS['TL_LANG'][$strTable][$act], Input::get('pid')) . '</span>';
//                    }
//                }
//            }

        return $dc->$act();
    }
}
