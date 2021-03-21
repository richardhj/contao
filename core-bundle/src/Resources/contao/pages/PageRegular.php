<?php

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao;

use Contao\CoreBundle\Exception\NoLayoutSpecifiedException;
use Contao\CoreBundle\Page\PageBuilder;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provide methods to handle a regular front end page.
 *
 * @author Leo Feyer <https://github.com/leofeyer>
 */
class PageRegular extends Frontend
{
	private PageBuilder $pageBuilder;

	public function __construct()
	{
		$this->pageBuilder = System::getContainer()->get(PageBuilder::class);

		parent::__construct();
	}

	/**
	 * Generate a regular page
	 *
	 * @param PageModel $objPage
	 * @param boolean   $blnCheckRequest
	 */
	public function generate($objPage, $blnCheckRequest=false)
	{
		trigger_deprecation('contao/core-bundle', '4.0', 'Using "Contao\PageRegular::output()" has been deprecated and will no longer work in Contao 5.0. Use "Contao\PageRegular::getResponse()" instead.');

		$this->prepare($objPage);

		$this->pageBuilder->getResponse()->send();
	}

	/**
	 * Return a response object
	 *
	 * @param PageModel $objPage
	 * @param boolean   $blnCheckRequest
	 *
	 * @return Response
	 */
	public function getResponse($objPage, $blnCheckRequest=false)
	{
		$this->prepare($objPage);

		return $this->pageBuilder->getResponse();
	}

	/**
	 * Generate a regular page
	 *
	 * @param PageModel $objPage
	 *
	 * @internal Do not call this method in your code. It will be made private in Contao 5.0.
	 */
	protected function prepare($objPage)
	{
		$GLOBALS['TL_KEYWORDS'] = '';
		$GLOBALS['TL_LANGUAGE'] = $objPage->language;

		$locale = str_replace('-', '_', $objPage->language);

		$container = System::getContainer();
		$container->get('request_stack')->getCurrentRequest()->setLocale($locale);
		$container->get('translator')->setLocale($locale);

		System::loadLanguageFile('default');

		// Static URLs
		$this->setStaticUrls();

		// Get the page layout
		$objLayout = $this->getPageLayout($objPage);

		/** @var ThemeModel $objTheme */
		$objTheme = $objLayout->getRelated('pid');

		// Set the default image densities
		$container->get('contao.image.picture_factory')->setDefaultDensities($objLayout->defaultImageDensities);

		// Store the layout ID
		$objPage->layoutId = $objLayout->id;

		// Set the layout template and template group
		$objPage->template = $objLayout->template ?: 'fe_page';
		$objPage->templateGroup = $objTheme->templates ?? null;

		// Minify the markup
		$objPage->minifyMarkup = $objLayout->minifyMarkup;

		// Initialize the template
		$this->pageBuilder = $this->pageBuilder->withTemplate($objPage->template)->withLayout($objLayout);
		$this->createTemplate($objPage, $objLayout);

		// Mark RTL languages (see #7171)
		if (($GLOBALS['TL_LANG']['MSC']['textDirection'] ?? null) == 'rtl')
		{
			$this->Template->isRTL = true;
		}

		// HOOK: modify the page or layout object
		if (isset($GLOBALS['TL_HOOKS']['generatePage']) && \is_array($GLOBALS['TL_HOOKS']['generatePage']))
		{
			foreach ($GLOBALS['TL_HOOKS']['generatePage'] as $callback)
			{
				$this->import($callback[0]);
				$this->{$callback[0]}->{$callback[1]}($objPage, $objLayout, $this);
			}
		}

		// Execute AFTER the modules have been generated and create footer scripts first
		$this->createFooterScripts($objLayout, $objPage);
		$this->createHeaderScripts($objPage, $objLayout);
	}

	/**
	 * Get a page layout and return it as database result object
	 *
	 * @param PageModel $objPage
	 *
	 * @return LayoutModel
	 */
	protected function getPageLayout($objPage)
	{
		$objLayout = LayoutModel::findByPk($objPage->layout);

		// Die if there is no layout
		if (null === $objLayout)
		{
			$this->log('Could not find layout ID "' . $objPage->layout . '"', __METHOD__, TL_ERROR);

			throw new NoLayoutSpecifiedException('No layout specified');
		}

		$objPage->hasJQuery = $objLayout->addJQuery;
		$objPage->hasMooTools = $objLayout->addMooTools;

		// HOOK: modify the page or layout object (see #4736)
		if (isset($GLOBALS['TL_HOOKS']['getPageLayout']) && \is_array($GLOBALS['TL_HOOKS']['getPageLayout']))
		{
			foreach ($GLOBALS['TL_HOOKS']['getPageLayout'] as $callback)
			{
				$this->import($callback[0]);
				$this->{$callback[0]}->{$callback[1]}($objPage, $objLayout, $this);
			}
		}

		return $objLayout;
	}

	/**
	 * Modify the page template
	 *
	 * @param PageModel   $objPage
	 * @param LayoutModel $objLayout
	 */
	protected function createTemplate($objPage, $objLayout)
	{
		$this->Template = $this->pageBuilder->getTemplate();

		$this->Template->viewport = '';
		$this->Template->framework = '';

		$arrFramework = StringUtil::deserialize($objLayout->framework);

		// Generate the CSS framework
		if (\is_array($arrFramework) && \in_array('layout.css', $arrFramework))
		{
			$strFramework = '';

			if (\in_array('responsive.css', $arrFramework))
			{
				$this->Template->viewport = '<meta name="viewport" content="width=device-width,initial-scale=1.0">' . "\n";
			}

			// Wrapper
			if ($objLayout->static)
			{
				$arrSize = StringUtil::deserialize($objLayout->width);

				if (isset($arrSize['value']) && $arrSize['value'] && $arrSize['value'] >= 0)
				{
					$arrMargin = array('left'=>'0 auto 0 0', 'center'=>'0 auto', 'right'=>'0 0 0 auto');
					$strFramework .= sprintf('#wrapper{width:%s;margin:%s}', $arrSize['value'] . $arrSize['unit'], $arrMargin[$objLayout->align]);
				}
			}

			// Header
			if ($objLayout->rows == '2rwh' || $objLayout->rows == '3rw')
			{
				$arrSize = StringUtil::deserialize($objLayout->headerHeight);

				if (isset($arrSize['value']) && $arrSize['value'] && $arrSize['value'] >= 0)
				{
					$strFramework .= sprintf('#header{height:%s}', $arrSize['value'] . $arrSize['unit']);
				}
			}

			$strContainer = '';

			// Left column
			if ($objLayout->cols == '2cll' || $objLayout->cols == '3cl')
			{
				$arrSize = StringUtil::deserialize($objLayout->widthLeft);

				if (isset($arrSize['value']) && $arrSize['value'] && $arrSize['value'] >= 0)
				{
					$strFramework .= sprintf('#left{width:%s;right:%s}', $arrSize['value'] . $arrSize['unit'], $arrSize['value'] . $arrSize['unit']);
					$strContainer .= sprintf('padding-left:%s;', $arrSize['value'] . $arrSize['unit']);
				}
			}

			// Right column
			if ($objLayout->cols == '2clr' || $objLayout->cols == '3cl')
			{
				$arrSize = StringUtil::deserialize($objLayout->widthRight);

				if (isset($arrSize['value']) && $arrSize['value'] && $arrSize['value'] >= 0)
				{
					$strFramework .= sprintf('#right{width:%s}', $arrSize['value'] . $arrSize['unit']);
					$strContainer .= sprintf('padding-right:%s;', $arrSize['value'] . $arrSize['unit']);
				}
			}

			// Main column
			if ($strContainer)
			{
				$strFramework .= sprintf('#container{%s}', substr($strContainer, 0, -1));
			}

			// Footer
			if ($objLayout->rows == '2rwf' || $objLayout->rows == '3rw')
			{
				$arrSize = StringUtil::deserialize($objLayout->footerHeight);

				if (isset($arrSize['value']) && $arrSize['value'] && $arrSize['value'] >= 0)
				{
					$strFramework .= sprintf('#footer{height:%s}', $arrSize['value'] . $arrSize['unit']);
				}
			}

			// Add the layout specific CSS
			if ($strFramework)
			{
				$this->Template->framework = Template::generateInlineStyle($strFramework) . "\n";
			}
		}

		// Overwrite the viewport tag (see #6251)
		if ($objLayout->viewport)
		{
			$this->Template->viewport = '<meta name="viewport" content="' . $objLayout->viewport . '">' . "\n";
		}

		$this->Template->mooScripts = '';

		// Make sure TL_JAVASCRIPT exists (see #4890)
		if (isset($GLOBALS['TL_JAVASCRIPT']) && \is_array($GLOBALS['TL_JAVASCRIPT']))
		{
			$arrAppendJs = $GLOBALS['TL_JAVASCRIPT'];
			$GLOBALS['TL_JAVASCRIPT'] = array();
		}
		else
		{
			$arrAppendJs = array();
			$GLOBALS['TL_JAVASCRIPT'] = array();
		}

		$container = System::getContainer();
		$projectDir = $container->getParameter('kernel.project_dir');

		// jQuery scripts
		if ($objLayout->addJQuery)
		{
			$GLOBALS['TL_JAVASCRIPT'][] = 'assets/jquery/js/jquery.min.js|static';
		}

		// MooTools scripts
		if ($objLayout->addMooTools)
		{
			$GLOBALS['TL_JAVASCRIPT'][] = 'assets/mootools/js/mootools.min.js|static';
		}

		// Check whether TL_APPEND_JS exists (see #4890)
		if (!empty($arrAppendJs))
		{
			$GLOBALS['TL_JAVASCRIPT'] = array_merge($GLOBALS['TL_JAVASCRIPT'], $arrAppendJs);
		}

		// Add the check_cookies image and the request token script if needed
		if ($objPage->alwaysLoadFromCache)
		{
			$GLOBALS['TL_BODY'][] = sprintf('<img src="%s" width="1" height="1" class="invisible" alt aria-hidden="true" onload="this.parentNode.removeChild(this)">', System::getContainer()->get('router')->generate('contao_frontend_check_cookies'));
			$GLOBALS['TL_BODY'][] = sprintf('<script src="%s" async></script>', System::getContainer()->get('router')->generate('contao_frontend_request_token_script'));
		}
	}

	/**
	 * Create all header scripts
	 *
	 * @param PageModel   $objPage
	 * @param LayoutModel $objLayout
	 */
	protected function createHeaderScripts($objPage, $objLayout)
	{
		$strStyleSheets = '';
		$strCcStyleSheets = '';
		$arrStyleSheets = StringUtil::deserialize($objLayout->stylesheet);
		$arrFramework = StringUtil::deserialize($objLayout->framework);

		// Add the Contao CSS framework style sheets
		if (\is_array($arrFramework))
		{
			foreach ($arrFramework as $strFile)
			{
				if ($strFile != 'tinymce.css')
				{
					$GLOBALS['TL_FRAMEWORK_CSS'][] = 'assets/contao/css/' . basename($strFile, '.css') . '.min.css';
				}
			}
		}

		// Make sure TL_USER_CSS is set
		if (!isset($GLOBALS['TL_USER_CSS']) || !\is_array($GLOBALS['TL_USER_CSS']))
		{
			$GLOBALS['TL_USER_CSS'] = array();
		}

		// User style sheets
		if (\is_array($arrStyleSheets) && isset($arrStyleSheets[0]))
		{
			$objStylesheets = StyleSheetModel::findByIds($arrStyleSheets);

			if ($objStylesheets !== null)
			{
				while ($objStylesheets->next())
				{
					$media = implode(',', StringUtil::deserialize($objStylesheets->media));

					// Overwrite the media type with a custom media query
					if ($objStylesheets->mediaQuery)
					{
						$media = $objStylesheets->mediaQuery;
					}

					// Style sheets with a CC or a combination of font-face and media-type != all cannot be aggregated (see #5216)
					if ($objStylesheets->cc || ($objStylesheets->hasFontFace && $media != 'all'))
					{
						$strStyleSheet = '';

						// External style sheet
						if ($objStylesheets->type == 'external')
						{
							$objFile = FilesModel::findByPk($objStylesheets->singleSRC);

							if ($objFile !== null)
							{
								$strStyleSheet = Template::generateStyleTag(Controller::addFilesUrlTo($objFile->path), $media, null);
							}
						}
						else
						{
							$strStyleSheet = Template::generateStyleTag(Controller::addAssetsUrlTo('assets/css/' . $objStylesheets->name . '.css'), $media, max($objStylesheets->tstamp, $objStylesheets->tstamp2, $objStylesheets->tstamp3));
						}

						if ($objStylesheets->cc)
						{
							$strStyleSheet = '<!--[' . $objStylesheets->cc . ']>' . $strStyleSheet . '<![endif]-->';
						}

						$strCcStyleSheets .= $strStyleSheet . "\n";
					}
					elseif ($objStylesheets->type == 'external')
					{
						$objFile = FilesModel::findByPk($objStylesheets->singleSRC);

						if ($objFile !== null)
						{
							$GLOBALS['TL_USER_CSS'][] = $objFile->path . '|' . $media . '|static';
						}
					}
					else
					{
						$GLOBALS['TL_USER_CSS'][] = 'assets/css/' . $objStylesheets->name . '.css|' . $media . '|static|' . max($objStylesheets->tstamp, $objStylesheets->tstamp2, $objStylesheets->tstamp3);
					}
				}
			}
		}

		$arrExternal = StringUtil::deserialize($objLayout->external);

		// External style sheets
		if (!empty($arrExternal) && \is_array($arrExternal))
		{
			// Get the file entries from the database
			$objFiles = FilesModel::findMultipleByUuids($arrExternal);
			$projectDir = System::getContainer()->getParameter('kernel.project_dir');

			if ($objFiles !== null)
			{
				$arrFiles = array();

				while ($objFiles->next())
				{
					if (file_exists($projectDir . '/' . $objFiles->path))
					{
						$arrFiles[] = $objFiles->path . '|static';
					}
				}

				// Inject the external style sheets before or after the internal ones (see #6937)
				if ($objLayout->loadingOrder == 'external_first')
				{
					array_splice($GLOBALS['TL_USER_CSS'], 0, 0, $arrFiles);
				}
				else
				{
					array_splice($GLOBALS['TL_USER_CSS'], \count($GLOBALS['TL_USER_CSS']), 0, $arrFiles);
				}
			}
		}

		// Add a placeholder for dynamic style sheets (see #4203)
		$strStyleSheets .= '[[TL_CSS]]';

		// Always add conditional style sheets at the end
		$strStyleSheets .= $strCcStyleSheets;

		// Add a placeholder for dynamic <head> tags (see #4203)
		$strHeadTags = '[[TL_HEAD]]';

		// Add the analytics scripts
		if ($objLayout->analytics)
		{
			$arrAnalytics = StringUtil::deserialize($objLayout->analytics, true);

			foreach ($arrAnalytics as $strTemplate)
			{
				if ($strTemplate)
				{
					$objTemplate = new FrontendTemplate($strTemplate);
					$strHeadTags .= $objTemplate->parse();
				}
			}
		}

		// Add the user <head> tags
		if ($strHead = trim($objLayout->head))
		{
			$strHeadTags .= $strHead . "\n";
		}

		$this->Template->stylesheets = $strStyleSheets;
		$this->Template->head = $strHeadTags;
	}

	/**
	 * Create all footer scripts
	 *
	 * @param LayoutModel $objLayout
	 * @param PageModel   $objPage
	 *
	 * @todo Change the method signature to ($objPage, $objLayout) in Contao 5.0
	 */
	protected function createFooterScripts($objLayout, $objPage = null)
	{
		$strScripts = '';

		// jQuery
		if ($objLayout->addJQuery)
		{
			$arrJquery = StringUtil::deserialize($objLayout->jquery, true);

			foreach ($arrJquery as $strTemplate)
			{
				if ($strTemplate)
				{
					$objTemplate = new FrontendTemplate($strTemplate);
					$strScripts .= $objTemplate->parse();
				}
			}

			// Add a placeholder for dynamic scripts (see #4203)
			$strScripts .= '[[TL_JQUERY]]';
		}

		// MooTools
		if ($objLayout->addMooTools)
		{
			$arrMootools = StringUtil::deserialize($objLayout->mootools, true);

			foreach ($arrMootools as $strTemplate)
			{
				if ($strTemplate)
				{
					$objTemplate = new FrontendTemplate($strTemplate);
					$strScripts .= $objTemplate->parse();
				}
			}

			// Add a placeholder for dynamic scripts (see #4203)
			$strScripts .= '[[TL_MOOTOOLS]]';
		}

		// Add the framework agnostic JavaScripts
		if ($objLayout->scripts)
		{
			$arrScripts = StringUtil::deserialize($objLayout->scripts, true);

			foreach ($arrScripts as $strTemplate)
			{
				if ($strTemplate)
				{
					$objTemplate = new FrontendTemplate($strTemplate);
					$strScripts .= $objTemplate->parse();
				}
			}
		}

		// Add a placeholder for dynamic scripts (see #4203, #5583)
		$strScripts .= '[[TL_BODY]]';

		// Add the external JavaScripts
		$arrExternalJs = StringUtil::deserialize($objLayout->externalJs);

		// Get the file entries from the database
		$objFiles = FilesModel::findMultipleByUuids($arrExternalJs);
		$projectDir = System::getContainer()->getParameter('kernel.project_dir');

		if ($objFiles !== null)
		{
			while ($objFiles->next())
			{
				if (file_exists($projectDir . '/' . $objFiles->path))
				{
					$strScripts .= Template::generateScriptTag($objFiles->path, false, null);
				}
			}
		}

		// Add search index metadata
		if ($objPage !== null)
		{
			$noSearch = (bool) $objPage->noSearch;

			// Do not search the page if the query has a key that is in TL_NOINDEX_KEYS
			if (preg_grep('/^(' . implode('|', $GLOBALS['TL_NOINDEX_KEYS']) . ')$/', array_keys($_GET)))
			{
				$noSearch = true;
			}

			$meta = array
			(
				'@context' => array('contao' => 'https://schema.contao.org/'),
				'@type' => 'contao:Page',
				'contao:title' => $objPage->pageTitle ?: $objPage->title,
				'contao:pageId' => (int) $objPage->id,
				'contao:noSearch' => $noSearch,
				'contao:protected' => (bool) $objPage->protected,
				'contao:groups' => array_map('intval', array_filter((array) $objPage->groups)),
				'contao:fePreview' => System::getContainer()->get('contao.security.token_checker')->isPreviewMode()
			);

			$strScripts .= '<script type="application/ld+json">' . json_encode($meta) . '</script>';
		}

		// Add the custom JavaScript
		if ($objLayout->script)
		{
			$strScripts .= "\n" . trim($objLayout->script) . "\n";
		}

		$this->Template->mootools = $strScripts;
	}
}

class_alias(PageRegular::class, 'PageRegular');
