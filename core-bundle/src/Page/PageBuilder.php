<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Page;

use Contao\Config;
use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Environment;
use Contao\FrontendTemplate;
use Contao\LayoutModel;
use Contao\ModuleModel;
use Contao\StringUtil;
use Contao\Template;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Controller\ControllerReference;
use Symfony\Component\HttpKernel\Fragment\FragmentHandler;

class PageBuilder
{
    private ContaoFramework $framework;
    private FragmentHandler $fragmentHandler;

    private ?string $templateName = null;
    private ?Template $template = null;
    private ?LayoutModel $layout = null;

    /**
     * @var array<string, array<ControllerReference>>
     */
    private $fragments = [];

    public function __construct(ContaoFramework $framework, FragmentHandler $fragmentHandler)
    {
        $this->framework = $framework;
        $this->fragmentHandler = $fragmentHandler;
    }

    public function withTemplate(string $templateName): self
    {
        $new = clone $this;
        $new->templateName = $templateName;

        $new->createTemplate();

        return $new;
    }

    public function withLayout(LayoutModel $layout): self
    {
        $new = clone $this;
        $new->layout = $layout;

        return $new;
    }

    public function addFragment(string $section, ControllerReference $fragment): self
    {
        $this->fragments[$section][] = $fragment;

        return $this;
    }

    public function getResponse(): Response
    {
        if (null === $this->templateName) {
            throw new \LogicException('Call PageBuilder#withTemplate() first.');
        }

        $this->generateModules();
        $this->generateFragments();

        // Set the page title and description AFTER the modules have been generated
        $this->template->mainTitle = $objPage->rootPageTitle;
        $this->template->pageTitle = $objPage->pageTitle ?: $objPage->title;

        // Meta robots tag
        $this->template->robots = $objPage->robots ?: 'index,follow';

        // Remove shy-entities (see #2709)
        $this->template->mainTitle = str_replace('[-]', '', $this->template->mainTitle);
        $this->template->pageTitle = str_replace('[-]', '', $this->template->pageTitle);

        // Fall back to the default title tag
        if (null !== $this->layout && !$this->layout->titleTag) {
            $this->layout->titleTag = '{{page::pageTitle}} - {{page::rootPageTitle}}';
        }

        // Assign the title and description
        $this->template->title = strip_tags(Controller::replaceInsertTags($objLayout->titleTag));
        $this->template->description = str_replace(["\n", "\r", '"'], [' ', '', ''], $objPage->description);

        // Body onload and body classes
        $this->template->onload = trim($this->layout->onload);
        $this->template->class = trim($this->layout->cssClass.' '.$objPage->cssClass);

        return $this->template->getResponse(true);
    }

    public function getTemplate(): Template
    {
        if (null === $this->template) {
            throw new \LogicException('Call PageBuilder#withTemplate() first.');
        }

        return $this->template;
    }

    private function generateModules(): void
    {
        if (null === $this->layout) {
            return;
        }

        $includes = StringUtil::deserialize($this->layout->modules, true);

        $moduleIds = [];

        foreach ($includes as $include) {
            if ($include['enable'] ?? null) {
                $moduleIds[] = (int) $include['mod'];
            }
        }

        $objModules = ModuleModel::findMultipleByIds($moduleIds);

        if (null === $objModules && !\in_array(0, $moduleIds, true)) {
            return;
        }

        if (null !== $objModules) {
            while ($objModules->next()) {
                $modules[$objModules->id] = $objModules->current();
            }
        }

        foreach ($includes as $include) {
            $section = $include['col'];
            $module = $include['mod'];
            $enable = (bool) ($include['enable'] ?? null);

            // Disabled module
            if (!BE_USER_LOGGED_IN && false === $enable) {
                continue;
            }

            // Replace the module ID with the module model
            if ($module > 0 && isset($modules[$module])) {
                $module = $modules[$module];
            }

            $customSections = [];

            if (\in_array($section, ['header', 'left', 'right', 'main', 'footer'], true)) {
                // Filter active sections (see #3273)
                if ('2rwh' !== $this->layout->rows && '3rw' !== $this->layout->rows && 'header' === $section) {
                    continue;
                }

                if ('2cll' !== $this->layout->cols && '3cl' !== $this->layout->cols && 'left' === $section) {
                    continue;
                }

                if ('2clr' !== $this->layout->cols && '3cl' !== $this->layout->cols && 'right' === $section) {
                    continue;
                }

                if ('2rwf' !== $this->layout->rows && '3rw' !== $this->layout->rows && 'footer' === $section) {
                    continue;
                }

                $this->template->{$section} .= Controller::getFrontendModule($module, $section);
            } else {
                if (!isset($customSections[$section])) {
                    $customSections[$section] = '';
                }

                $customSections[$section] .= Controller::getFrontendModule($module, $section);
            }
        }

        $this->template->sections = $customSections;
    }

    private function generateFragments(): void
    {
        $customSections = $this->template->sections ?? [];

        foreach ($this->fragments as $section => $fragments) {
            foreach ($fragments as $fragment) {
                if (\in_array($section, ['header', 'left', 'right', 'main', 'footer'], true)) {
                    $this->template->{$section} .= $this->fragmentHandler->render($fragment);
                } else {
                    if (!isset($customSections[$section])) {
                        $customSections[$section] = '';
                    }

                    $customSections[$section] .= $this->fragmentHandler->render($fragment);
                }
            }
        }

        $this->template->sections = $customSections;
    }

    private function createTemplate(): void
    {
        $this->template = $this->framework->createInstance(FrontendTemplate::class, [$this->templateName]);

        $this->template->header = '';
        $this->template->left = '';
        $this->template->main = '';
        $this->template->right = '';
        $this->template->footer = '';

        $this->template->sections = [];
        $this->template->positions = [];

        if (null !== $this->layout && $this->layout->sections) {
            $positions = [];
            $sections = StringUtil::deserialize($this->layout->sections, true);

            foreach ($sections as $v) {
                $positions[$v['position']][$v['id']] = $v;
            }

            $this->template->positions = $positions;
        }

        // Default settings
        $this->template->layout = $objLayout;
        $this->template->language = $GLOBALS['TL_LANGUAGE'];
        $this->template->charset = Config::get('characterSet');
        $this->template->base = Environment::get('base');
        $this->template->isRTL = false;
    }
}
