<?php
global $IP;

require_once "$IP/includes/specialpage/QueryPage.php";

/** UsersEditCountPage extends QueryPage.
 * This does the real work of generating the page contents
 * @package MediaWiki
 * @subpackage SpecialPage
 */
class UsersEditCountPage extends QueryPage
{
	var $requestDate = NULL;
	var $requestDateTitle = '';
	var $outputCSV = false;
	var $outputEmails = false;
	var $group = NULL;
	var $excludeGroup = false;

	function __construct($name = 'UsersEditCount')
	{
		global $wgUser;

		parent::__construct($name);

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

		$this->group = $req->getVal('group', 'bot');
		$this->excludeGroup = $req->getVal('excludegroup', true) !== null;

		if ($req->getVal('csv') == 1) {
			$this->outputCSV = true;
			if (in_array('sysop', $wgUser->getEffectiveGroups())) $this->outputEmails = true;
		}

		$this->setListoutput(false);
	}

	function getName()
	{
		return 'UsersEditCount';
	}

	function isCacheable()
	{
		return false;
	}

	function isExpensive()
	{
		return false;
	}

	function isSyndicated()
	{
		return false;
	}

	function getPageHeader()
	{
		$header  = '<p>';
		$title = $this->getTitle();
		$skin = $this->getSkin();
		//$target, $html = null, $customAttribs = [], $query = [], $options = []
		$linkday = Linker::link($title, 'Day', [], array('date' => 'day'));
		$linkweek = Linker::link($title, 'Week', [], array('date' => 'week'));
		$linkmonth = Linker::link($title, 'Month', [], array('date' => 'month'));
		$link6month = Linker::link($title, '6 Months', [], array('date' => '6month'));
		$linkyear = Linker::link($title, 'Year', [], array('date' => 'year'));
		$linkall = Linker::link($title, 'All Time');

		$header .= "<small style='position:absolute; top:12px;'>View Edit Counts for the Last: {$linkday} | {$linkweek} | {$linkmonth} | {$link6month} | {$linkyear} | {$linkall} </small>";
		$header .= '<br>';
		$header .= wfMessage('userseditcounttext') . ' ';

		if ($this->requestDate)
			$header .= 'Showing counts for edits in the last ' . $this->requestDateTitle . '. ';
		else
			$header .= 'Showing counts for all time.';

		$header .= '</p>';
		return $header;
	}

	function getQueryInfo()
	{
		if ($this->group) {
			$dbr = wfGetDB(DB_SLAVE);
			$sql = $dbr->selectSQLText('user_groups', 'ug_user', array('ug_group' => $this->group));
			$exclude = $this->excludeGroup ? 'NOT' : '';
		}

		$queryinfo = array(
			'tables' => array('user'),
			'fields' => array(
				'2 as namespace',
				'user_id as title',
				'user_editcount as value'
			),
			'conds' => array('user_editcount >= 0')
		);

		if ($sql) {
			$queryinfo['conds'][] = "user_id $exclude IN ($sql)";
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
		$queryinfo = array(
			'tables' => array('revision'),
			'fields' => array(
				'2 as namespace',
				'rev_user as title',
				'count(*) as value'
			),
			'conds' => array('rev_timestamp >= "' . wfTimestamp(TS_MW, $tsvalue) . '"'),
			'options' => array('GROUP BY' => 'rev_user')
		);

		if ($sql) {
			$queryinfo['conds'][] = "rev_user $exclude IN ($sql)";
		}

		return $queryinfo;
	}

	function linkParameters()
	{
		return array('date' => $this->requestDate);
	}

	function sortDescending()
	{
		return true;
	}

	function formatResult($skin, $result)
	{
		global $wgLang, $wgContLang;

		if ($this->outputCSV) return $this->formatResultCSV($skin, $result);

		$user = null;
		$user = User::newFromId($result->title);

		if (is_null($user)) {
			return "User ID {$result->title} has {$result->value} edits.";
		} else if ($user->isAnon()) {
			return "Anonymous users have {$result->value} edits.";
		} else {
			$title = $user->getUserPage();
			$link  = Linker::link($title, $wgContLang->convert($user->getName()));

			$titletalk = $user->getTalkPage();
			$linktalk  = Linker::link($titletalk, 'talk');

			$titlecontrib = Title::newFromText("Special:Contributions/{$user->getName()}");
			$linkcontrib  = Linker::link($titlecontrib, 'contribs');

			return "{$link} ( {$linktalk} | {$linkcontrib} ) has {$result->value} edits.";
		}
	}

	function formatResultCSV($skin, $result)
	{
		$user = null;
		$user = User::newFromId($result->title);

		if (is_null($user)) {
			if ($this->outputEmails) return "{$result->title}, n/a, n/a, {$result->value}";
			return "{$result->title}, {$result->value}";
		} else if (isset($result->rev_user) && $result->rev_user == 0) {
			if ($this->outputEmails) return "Anonymous, Anonymous, n/a, {$result->value}";
			return "Anonymous, {$result->value}";
		} else {
			if ($this->outputEmails) return "{$user->getName()}, {$user->getRealName()}, {$user->getEmail()}, {$result->value}";
			return "{$user->getName()}, {$result->value}";
		}
	}
}
