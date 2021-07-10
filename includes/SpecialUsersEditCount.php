<?php

/** UsersEditCountPage extends QueryPage.
 * This does the real work of generating the page contents
 * @package MediaWiki
 * @subpackage SpecialPage
 */
class SpecialUsersEditCount extends QueryPage
{
	private static $requestDates = [
		'day' => 1,
		'week' => 7,
		'month' => 31,
		'6month' => 182.5,
		'year' => 365,
	];

	private static $inputDateReverse = [
		1 => 'day',
		7 => 'week',
		31 => 'month',
		182.5 => '6months',
		365 => 'year',
	];

	private $requestDate = NULL;
	private $requestDateTitle = '';
	private $outputCSV = false;
	private $outputEmails = false;
	private $group = NULL;
	private $excludeGroup = false;

	public function __construct($name = 'Userseditcount')
	{
		parent::__construct($name);

		$req = $this->getRequest();
		$inputDate = $req->getVal('date');
		if ($inputDate) {
			$inputDate = strtolower($inputDate);
		}

		if (isset(self::$inputDateReverse[$inputDate])) {
			$inputDate = self::$inputDateReverse[$inputDate];
		}

		switch ($inputDate) {
			case 'day':
			case 'week':
			case 'month':
			case 'year':
				$this->requestDateTitle = $inputDate;
				$this->requestDate = self::$requestDates[$inputDate];
				break;
			case '6month':
				$this->requestDateTitle = '6 months';
				$this->requestDate = self::$requestDates[$inputDate];
				break;
			default:
				if (is_numeric($inputDate)) {
					$this->requestDateTitle = $inputDate . ' days';
				} else {
					$inputDate = null;
					$this->requestDateTitle = null;
				}

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

	public function formatResult($skin, $result)
	{
		if ($this->outputCSV) {
			return $this->formatResultCSV($result);
		}

		if (isset($result->title)) {
			$msg = 'normal';
			$name = $result->title;
			$user = User::newFromName($result->title);
			$name = $user === false ? wfMessage('userseditcount-invaliduser') : Linker::userLink($user->getId(), $name) . Linker::userToolLinks($user->getId(), $result->title, false, Linker::TOOL_LINKS_NOBLOCK, $result->value);
		} else {
			$msg = 'anon';
			$name = null;
			$user = User::newFromId(0);
		}

		return wfMessage('userseditcount-result-' . $msg)
			->params($name)
			->numParams($result->value)
			->text();
	}

	public function getGroupName()
	{
		return 'users';
	}

	public function getPageHeader()
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

	public function getQueryInfo()
	{
		$queryInfo = [
			'tables' => ['revision', 'user'],
			'fields' => [
				'2 as namespace',
				'user_name as title',
				'COUNT(*) as value'
			],
			'conds' => [],
			'join_conds' => [
				'user' => [
					'LEFT JOIN',
					['rev_user=user_id']
				]
			],
			'options' => ['GROUP BY' => 'rev_user']
		];

		if ($this->group) {
			$dbr = wfGetDB(DB_SLAVE);
			$groupFilter = $dbr->selectSQLText('user_groups', 'ug_user', ['ug_group' => $this->group]);
			$not = $this->excludeGroup ? ' NOT' : '';
			$queryInfo['conds'][] = "rev_user$not IN ($groupFilter)";
		}

		if (!is_null($this->requestDate)) {
			$queryInfo['conds'][] = 'rev_timestamp >= "' . wfTimestamp(TS_MW, time() - ($this->requestDate * 86400)) . '"';
		}

		return $queryInfo;
	}

	public function isCacheable()
	{
		return false;
	}

	public function isExpensive()
	{
		return true;
	}

	public function isSyndicated()
	{
		return false;
	}

	public function linkParameters()
	{
		return ['date' => $this->requestDate];
	}

	public function sortDescending()
	{
		return true;
	}

	private function formatResultCSV($result)
	{
		$user = isset($result->title)
			? User::newFromName($result->title)
			: User::newFromId(0);
		$value = $result->value;
		$realName = 'n/a';
		$email = 'n/a';
		if ($user === false) {
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
