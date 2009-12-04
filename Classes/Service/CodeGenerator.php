<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2009 Ingmar Schlecht
*  All rights reserved
*
*  This script is part of the TYPO3 project. The TYPO3 project is
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 2 of the License, or
*  (at your option) any later version.
*
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/

/**
 * Creates a request an dispatches it to the controller which was specified
 * by TS Setup, Flexform and returns the content to the v4 framework.
 *
 * This class is the main entry point for extbase extensions in the frontend.
 *
 * @package ExtbaseKickstarter
 * @version $ID:$
 */
class Tx_ExtbaseKickstarter_Service_CodeGenerator {
	
	/**
	 *
	 * @var Tx_Fluid_Core_Parser_TemplateParser
	 */
	protected $templateParser;

	/**
	 *
	 * @var Tx_Fluid_Compatibility_ObjectFactory
	 */
	protected $objectFactory;

	/**
	 *
	 * @var Tx_ExtbaseKickstarter_Domain_Model_Extension
	 */
	protected $extension;

	public function __construct() {
		$this->templateParser = Tx_Fluid_Compatibility_TemplateParserBuilder::build();
		$this->objectFactory = new Tx_Fluid_Compatibility_ObjectFactory();
	}

	public function build(Tx_ExtbaseKickstarter_Domain_Model_Extension $extension) {
		$this->extension = $extension;

		// Base directory already exists at this point
		$extensionDirectory = PATH_typo3conf . 'ext/' . $this->extension->getExtensionKey().'/';
		//t3lib_div::mkdir($extensionDirectory);

		// Generate Domain Model
		t3lib_div::mkdir_deep($extensionDirectory, 'Classes/Domain/Model');
		$domainModelDirectory = $extensionDirectory . 'Classes/Domain/Model/';
		foreach ($this->extension->getDomainObjects() as $domainObject) {
			$fileContents = $this->generateDomainObjectCode($domainObject, $extension);
			t3lib_div::writeFile($domainModelDirectory . $domainObject->getName() . '.php', $fileContents);
		}

		// Generate Domain Repositories
		t3lib_div::mkdir_deep($extensionDirectory, 'Classes/Domain/Repository');
		$domainRepositoryDirectory = $extensionDirectory . 'Classes/Domain/Repository/';
		foreach ($this->extension->getDomainObjects() as $domainObject) {
			if (!$domainObject->isAggregateRoot()) continue;
		
			$fileContents = $this->generateDomainRepositoryCode($domainObject);
			t3lib_div::writeFile($domainRepositoryDirectory . $domainObject->getName() . 'Repository.php', $fileContents);
		}
		
		// Generate Action Controller
		t3lib_div::mkdir_deep($extensionDirectory, 'Classes/Controller');
		$controllerDirectory = $extensionDirectory . 'Classes/Controller/';
		foreach ($this->extension->getDomainObjects() as $domainObject) {
			// Every Domain Object with defined actions will get a corresponding Action Controller
			if(count($domainObject->getActions())){
				$fileContents = $this->generateActionControllerCode($domainObject, $extension);
				t3lib_div::writeFile($controllerDirectory . $domainObject->getName() . 'Controller.php', $fileContents);
			}
		}

		// Generate ext_emconf.php, ext_tables.* and TCA definition
		$fileContents = $this->generateExtEmconf($extension);
		t3lib_div::writeFile($extensionDirectory . 'ext_emconf.php', $fileContents);

		$fileContents = $this->generateExtTablesPhp($extension);
		t3lib_div::writeFile($extensionDirectory . 'ext_tables.php', $fileContents);
		
		$fileContents = $this->generateExtTablesSql($extension);
		t3lib_div::writeFile($extensionDirectory . 'ext_tables.sql', $fileContents);

		// Generate TCA
		t3lib_div::mkdir_deep($extensionDirectory, 'Configuration/TCA');
		$tcaDirectory = $extensionDirectory . 'Configuration/TCA/';
		$fileContents = $this->generateTCA($extension);
		t3lib_div::writeFile($tcaDirectory . 'tca.php', $fileContents);

		// Generate TypoScript setup
		t3lib_div::mkdir_deep($extensionDirectory, 'Configuration/TypoScript');
		$typoscriptDirectory = $extensionDirectory . 'Configuration/TypoScript/';
		$fileContents = $this->generateTyposcriptSetup($extension);
		t3lib_div::writeFile($typoscriptDirectory . 'setup.txt', $fileContents);

		// Generate Private Resources .htaccess
		t3lib_div::mkdir_deep($extensionDirectory, 'Resources/Private');
		$privateResourcesDirectory = $extensionDirectory . 'Resources/Private/';
		$fileContents = $this->generatePrivateResourcesHtaccess();
		t3lib_div::writeFile($privateResourcesDirectory . '.htaccess', $fileContents);
		
		// Generate Domain Templates
		foreach ($this->extension->getDomainObjects() as $domainObject) {
			if (!$domainObject->getEntity()) continue;
			
			t3lib_div::mkdir_deep($extensionDirectory, 'Resources/Private/Templates/' . $domainObject->getName());
			$domainTemplateDirectory = $extensionDirectory . 'Resources/Private/Templates/' . $domainObject->getName() . '/';
			foreach($domainObject->getActions() as $action) {
				$fileContents = $this->generateDomainTemplate($domainObject, $action);
				t3lib_div::writeFile($domainTemplateDirectory . $action->getName() . '.html', $fileContents);
			}
		}
		t3lib_div::mkdir_deep($extensionDirectory, 'Resources/Public');
	}

	/**
	 * Build the rendering context
	 * @author Sebastian Kurfürst <sebastian@typo3.org>
	 */
	protected function buildRenderingContext($templateVariables) {
		$variableContainer = $this->objectFactory->create('Tx_Fluid_Core_ViewHelper_TemplateVariableContainer', $templateVariables);

		$renderingConfiguration = $this->objectFactory->create('Tx_Fluid_Core_Rendering_RenderingConfiguration');

		$renderingContext = $this->objectFactory->create('Tx_Fluid_Core_Rendering_RenderingContext');
		$renderingContext->setTemplateVariableContainer($variableContainer);
		//$renderingContext->setControllerContext($this->controllerContext); 
		$renderingContext->setRenderingConfiguration($renderingConfiguration);

		$viewHelperVariableContainer = $this->objectFactory->create('Tx_Fluid_Core_ViewHelper_ViewHelperVariableContainer');
		$renderingContext->setViewHelperVariableContainer($viewHelperVariableContainer);

		return $renderingContext;
	}

	protected function renderTemplate($filePath, $variables) {
		$parsedTemplate = $this->templateParser->parse(file_get_contents(t3lib_extMgm::extPath('extbase_kickstarter').'Resources/Private/CodeTemplates/' . $filePath));
		return $parsedTemplate->render($this->buildRenderingContext($variables));
	}


	public function generateActionControllerCode(Tx_ExtbaseKickstarter_Domain_Model_DomainObject $domainObject, Tx_ExtbaseKickstarter_Domain_Model_Extension $extension) {
		return $this->renderTemplate('Classes/Controller/actionController.phpt', array('domainObject' => $domainObject, 'extension' => $extension));
	}
	
	public function generateDomainObjectCode(Tx_ExtbaseKickstarter_Domain_Model_DomainObject $domainObject, Tx_ExtbaseKickstarter_Domain_Model_Extension $extension) {
		return $this->renderTemplate('Domain/Model/domainObject.phpt', array('domainObject' => $domainObject, 'extension' => $extension));
	}

	public function generateDomainRepositoryCode(Tx_ExtbaseKickstarter_Domain_Model_DomainObject $domainObject) {
		return $this->renderTemplate('Domain/Repository/domainRepository.phpt', array('domainObject' => $domainObject));
	}
	
	/**
	 * Generates the content of an Action template
	 * For some Actions default templates are provided, other Action templates will just be created emtpy
	 *
	 * @param Tx_ExtbaseKickstarter_Domain_Model_DomainObject $domainObject
	 * @param Tx_ExtbaseKickstarter_Domain_Model_Action $action
	 * @return string The generated Template code (might be empty)
	 */
	public function generateDomainTemplate(Tx_ExtbaseKickstarter_Domain_Model_DomainObject $domainObject, Tx_ExtbaseKickstarter_Domain_Model_Action $action) {
		if(t3lib_div::inList('create,edit,list', $action->getName())){
			return $this->renderTemplate('Resources/Private/Templates/'. $action->getName() . '.htmlt', array('domainObject' => $domainObject));
		}
	}

	public function generateExtEmconf(Tx_ExtbaseKickstarter_Domain_Model_Extension $extension) {
		return $this->renderTemplate('extEmconf.phpt', array('extension' => $extension));
	}

	public function generateExtTablesPhp(Tx_ExtbaseKickstarter_Domain_Model_Extension $extension) {
		return $this->renderTemplate('extTables.phpt', array('extension' => $extension));
	}

	public function generateExtTablesSql(Tx_ExtbaseKickstarter_Domain_Model_Extension $extension) {
		return $this->renderTemplate('extTables.sqlt', array('extension' => $extension));
	}
	
	public function generatePrivateResourcesHtaccess() {
		return $this->renderTemplate('Resources/Private/htaccess.t', array());
	}

	public function generateTCA(Tx_ExtbaseKickstarter_Domain_Model_Extension $extension) {
		return $this->renderTemplate('Configuration/TCA/tca.phpt', array('extension' => $extension));
	}

	public function generateTyposcriptSetup(Tx_ExtbaseKickstarter_Domain_Model_Extension $extension) {
		return $this->renderTemplate('Configuration/TypoScript/setup.txt', array('extension' => $extension));
	}
}
?>