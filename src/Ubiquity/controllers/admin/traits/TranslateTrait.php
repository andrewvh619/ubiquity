<?php
namespace Ubiquity\controllers\admin\traits;

use Ajax\semantic\components\validation\Rule;
use Ajax\semantic\html\base\constants\Direction;
use Ajax\semantic\html\elements\HtmlLabel;
use Ubiquity\translation\MessagesCatalog;
use Ubiquity\translation\MessagesDomain;
use Ubiquity\translation\TranslatorManager;
use Ubiquity\utils\http\URequest;

/**
 *
 * @property \Ajax\php\ubiquity\JsUtils $jquery
 * @author jcheron <myaddressmail@gmail.com>
 *        
 */
trait TranslateTrait {

	protected function _translate($loc, $baseRoute) {
		TranslatorManager::start();
		$locales = TranslatorManager::getLocales();
		if (sizeof($locales) == 0) {
			$locales = TranslatorManager::initialize();
		}
		$tabs = $this->jquery->semantic()->htmlTab("locales");
		foreach ($locales as $locale) {
			$tabs->addTab($locale, $this->loadLocale($locale));
		}
		$tabs->activate(array_search($loc, $locales));

		$frm = $this->jquery->semantic()->htmlForm("frmLocale");
		$frm->setValidationParams([
			"on" => "blur",
			"inline" => true
		]);
		$fields = $frm->addFields();
		$input = $fields->addInput("localeName", null, "text", "", "Locale name")
			->addRules([
			[
				"empty",
				"Locale name must have a value"
			],
			"regExp[/^[A-Za-z]\w*$/]",
			[
				"checkLocale",
				"Locale {value} is not a valid name!"
			]
		])
			->setWidth(8);
		$input->addAction("Add locale", true, "plus", true)
			->addClass("teal")
			->asSubmit();
		$frm->setSubmitParams($baseRoute . '/createLocale', '#translations-refresh', [
			'jqueryDone' => 'replaceWith',
			'hasLoader' => 'internal'
		]);

		$this->jquery->exec(Rule::ajax($this->jquery, "checkLocale", $this->_getFiles()
			->getAdminBaseRoute() . "/_checkLocale", "{}", "result=data.result;", "postForm", [
			"form" => "frmLocale"
		]), true);
		$this->jquery->renderView($this->_getFiles()
			->getViewTranslateIndex());
	}

	public function loadLocale($locale) {
		$baseRoute = $this->_getFiles()->getAdminBaseRoute();

		$messagesCatalog = new MessagesCatalog($locale, TranslatorManager::getLoader());
		$messagesCatalog->load();
		$msgDomains = $messagesCatalog->getMessagesDomains();

		$frm = $this->jquery->semantic()->htmlForm("frmDomain-" . $locale);
		$frm->setValidationParams([
			"on" => "blur",
			"inline" => true
		]);
		$fields = $frm->addFields();
		$input = $fields->addInput("name-" . $locale, null, "text", "", "Domain name")
			->addRules([
			[
				"empty",
				"Domain name must have a value"
			],
			"regExp[/^[A-Za-z]\w*$/]"
		])
			->setWidth(8);
		$input->setName('domainName');
		$ck = $input->labeledCheckbox(Direction::LEFT, "Add in all locales", "all-locales");
		$ck->getField()->setProperty('name', 'ck-all-locales');
		$input->addAction("Add domain", true, "plus", true)
			->addClass("teal")
			->asSubmit();
		$frm->setSubmitParams($baseRoute . "/addDomain/" . $locale, "#translations-refresh");

		$dt = $this->jquery->semantic()->dataTable('dt-' . $locale, MessagesDomain::class, $msgDomains);
		$dt->setFields([
			'domain',
			'messages'
		]);
		$dt->setValueFunction('messages', function ($value) {
			$nb = 0;
			if (is_array($value)) {
				$nb = count($value);
			}
			return new HtmlLabel('', $nb, 'mail');
		});
		$dt->setIdentifierFunction('getDomain');
		$dt->addEditDeleteButtons(true, [], function ($bt) use ($locale) {
			$bt->addClass($locale);
		});
		$dt->setActiveRowSelector();

		$this->jquery->getOnClick('._edit.' . $locale, "/Admin/loadDomain/" . $locale . "/", '#domain-' . $locale, [
			'attr' => 'data-ajax'
		]);
		return $this->loadView('@framework/Admin/translate/locale.html', [
			'locale' => $locale,
			'dt' => $dt,
			'frm' => $frm
		], true);
	}

	public function loadDomain($locale, $domain) {
		TranslatorManager::start();
		$msgDomain = new MessagesDomain($locale, TranslatorManager::getLoader(), $domain);
		$msgDomain->load();
		$messages = $msgDomain->getMessages();
		$this->loadView('@framework/Admin/translate/domain.html', [
			'messages' => $messages
		]);
	}

	public function createLocale() {
		if (URequest::isPost()) {
			$baseRoute = $this->_getFiles()->getAdminBaseRoute();
			if (isset($_POST["localeName"]) && $_POST["localeName"] != null) {
				$loc = $_POST["localeName"];
				TranslatorManager::createLocale($loc);
			} else {
				$loc = URequest::getDefaultLanguage();
			}
			$this->_translate($loc, $baseRoute);
		}
	}

	public function _checkLocale() {
		if (URequest::isPost()) {
			TranslatorManager::start();
			$result = [];
			header('Content-type: application/json');
			if (isset($_POST["localeName"]) && $_POST["localeName"] != null) {
				$localeName = $_POST["localeName"];
				$locales = TranslatorManager::getLocales();
				$result = TranslatorManager::isValidLocale($localeName) && (array_search($localeName, $locales) === false);
			} else {
				$result = true;
			}
			echo json_encode([
				'result' => $result
			]);
		}
	}

	public function addDomain($locale) {
		if (URequest::isPost()) {
			TranslatorManager::start();
			if (isset($_POST["domainName"]) && $_POST["domainName"] != null) {
				$domainName = $_POST["domainName"];
				if (isset($_POST["ck-all-locales"])) {
					$locales = TranslatorManager::getLocales();
					foreach ($locales as $loc) {
						TranslatorManager::createDomain($loc, $domainName, [
							'newKey' => 'New key for translations'
						]);
					}
				} else {
					TranslatorManager::createDomain($locale, $domainName, [
						'newKey' => 'New key for translations'
					]);
				}
			}
			$this->_translate($locale, $this->_getFiles()
				->getAdminBaseRoute());
		}
	}
}

