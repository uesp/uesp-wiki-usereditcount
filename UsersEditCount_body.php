<?php

/** UsersEditCountPage extends QueryPage.
 * This does the real work of generating the page contents
 * @package MediaWiki
 * @subpackage SpecialPage
 */
class UsersEditCountPage extends QueryPage
{
	private $requestDate = NULL;
	private $requestDateTitle = '';
	private $outputCSV = false;
	private $outputEmails = false;
	private $group = NULL;
	private $excludeGroup = false;

	function __construct()
	{
		parent::__construct('Userseditcount');

		$req = $this->getRequest();
		$inputdate = $req->getVal('date');
		$lcDate = strtolower($inputdate);
		switch ($lcDate) {
			case 'day':
			case 'week':
			case 'month':
			case 'year':
				$this->requestDate = $lcDate;
				$this->requestDateTitle = $lcDate;
				break;
			case '6month':
				$this->requestDate = $lcDate;
				$this->requestDateTitle = '6 months';
				break;
		}

		$group = $req->getVal('group');
		if (is_null($group)) {
			$this->group = 'bot';
			$this->excludeGroup = true;
		} else {
			$this->group = $group;
			$this->excludeGroup = $group === '' ? false : $req->getBool('excludegroup');
		}

		if ($req->getVal('csv')) {
			$this->outputCSV = true;
			// Note: the rights check below will always fail, since the right doesn't exist unless added. Showing
			// e-mails is a privacy breach, so should be restricted to those who already have database access anyway.
			$this->outputEmails = $this->getUser()->isAllowed('viewprivateuserinfo');
		}

		$this->setListoutput(false);
	}

	function formatResult($skin, $result)
	{
		$user = isset($result->title) ? User::newFromId($result->title) : null;

		if ($this->outputCSV) return $this->formatResultCSV($user, $result->value);

		if (is_null($user)) {
			return "Invalid User ID {$result->title} has {$result->value} edits.";
		}

		if ($user->isAnon()) {
			return "Anonymous users have {$result->value} edits.";
		}

		$link  = Linker::userLink($user->getId(), $user->getName());

		$titletalk = $user->getTalkPage();
		$linktalk  = Linker::link($titletalk, 'talk');

		$titlecontrib = Title::newFromText("Special:Contributions/{$user->getName()}");
		$linkcontrib  = Linker::link($titlecontrib, 'contribs');

		return "{$link} ( {$linktalk} | {$linkcontrib} ) has {$result->value} edits.";
	}

	function getPageHeader()
	{
		$header  = '<p>';
		$title = $this->getPageTitle();
		//$target, $html = null, $customAttribs = [], $query = [], $options = []
		$linkday = Linker::link($title, 'Day', [], ['date' => 'day']);
		$linkweek = Linker::link($title, 'Week', [], ['date' => 'week']);
		$linkmonth = Linker::link($title, 'Month', [], ['date' => 'month']);
		$link6month = Linker::link($title, '6 Months', [], ['date' => '6month']);
		$linkyear = Linker::link($title, 'Year', [], ['date' => 'year']);
		$linkall = Linker::link($title, 'All Time');

		# Form tag
		$header = Xml::openElement(
			'form',
			['method' => 'get', 'action' => wfScript(), 'id' => 'mw-listusers-form']
		) .
			Xml::fieldset($this->msg('userseditcount')->text()) .
			Html::hidden('title', $title);

		#Date Options
		$header .= "View Edit Counts for the Last: {$linkday} | {$linkweek} | {$linkmonth} | {$link6month} | {$linkyear} | {$linkall}<br>";

		# Group drop-down list
		$groupBox = new XmlSelect('group', 'group', $this->group);
		$groupBox->addOption($this->msg('group-all')->text(), '');
		foreach ($this->getAllGroups() as $group => $groupText) {
			$groupBox->addOption($groupText, $group);
		}

		$header .=
			Xml::label($this->msg('group')->text(), 'group') . ' ' .
			$groupBox->getHTML() . '&nbsp;' .
			Xml::checkLabel(
				$this->msg('userseditcount-excludegroup')->text(),
				'excludegroup',
				'excludegroup',
				$this->excludeGroup
			) .
			'&nbsp;';

		# Submit button and form bottom
		$header .=
			Xml::submitButton($this->msg('userseditcount-submit')->text()) .
			Xml::closeElement('fieldset') .
			Xml::closeElement('form');

		#Intro line
		$header .=
			wfMessage('userseditcount-headingtext') .
			' Showing counts for ' .
			($this->requestDate
				? 'edits in the last ' . $this->requestDateTitle
				: 'all time') .
			'.<br>';

		return $header;
	}

	function getQueryInfo()
	{
		if ($this->group) {
			$dbr = wfGetDB(DB_SLAVE);
			$sql = $dbr->selectSQLText('user_groups', 'ug_user', ['ug_group' => $this->group]);
			$sql = ($this->excludeGroup ? 'NOT ' : '') . "IN ($sql)";
		} else {
			$sql = false;
		}

		$queryinfo = [
			'tables' => ['user'],
			'fields' => [
				'2 as namespace',
				'user_id as title',
				'user_editcount as value'
			],
			'conds' => ['user_editcount >= 0']
		];

		if ($sql) {
			$queryinfo['conds'][] = "user_id $sql";
		}

		switch ($this->requestDate) {
			case 'day':
				$tsvalue = 1;
				break;
			case 'week':
				$tsvalue = 7;
				break;
			case 'month':
				$tsvalue = 31;
				break;
			case '6month':
				$tsvalue = 182.5;
				break;
			case 'year':
				$tsvalue = 365;
				break;
			default:
				$tsvalue = null;
				break;
		}

		if ($tsvalue == null) return $queryinfo;

		$tsvalue = time() - ($tsvalue * 86400);
		$queryinfo = [
			'tables' => ['revision'],
			'fields' => [
				'2 as namespace',
				'rev_user as title',
				'count(*) as value'
			],
			'conds' => ['rev_timestamp >= "' . wfTimestamp(TS_MW, $tsvalue) . '"'],
			'options' => ['GROUP BY' => 'rev_user']
		];

		if ($sql) {
			$queryinfo['conds'][] = "rev_user $sql";
		}

		return $queryinfo;
	}

	function isCacheable()
	{
		return false;
	}

	function isExpensive()
	{
		return true;
	}

	function isSyndicated()
	{
		return false;
	}

	function linkParameters()
	{
		return ['date' => $this->requestDate];
	}

	function sortDescending()
	{
		return true;
	}

	private function formatResultCSV(User $user, $value)
	{
		$realName = 'n/a';
		$email = 'n/a';
		if (is_null($user)) {
			$name = '';
		} elseif ($user->isAnon()) {
			$name = 'Anonymous';
		} else {
			$name = $user->getName();
			if ($this->outputEmails) {
				$realName = $user->getRealName();
				$email = $user->getEmail();
			}
		}

		return $this->outputEmails
			? "$name, $realName, $email, $value"
			: "$name, $value";
	}

	/**
	 * Get a list of all explicit groups
	 * @return array
	 */
	private function getAllGroups()
	{
		$result = [];
		foreach (User::getAllGroups() as $group) {
			$result[$group] = User::getGroupName($group);
		}
		asort($result);

		return $result;
	}
}
