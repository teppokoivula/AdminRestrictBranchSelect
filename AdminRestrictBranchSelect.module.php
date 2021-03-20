<?php namespace ProcessWire;

/**
 * Admin Restrict Branch Select
 *
 * ProcessWire module for adding branch select support for Admin Restrict Branch. With this module enabled, you can
 * manually select more than one branch parent per user, and they'll be able to switch between those while editing
 * site content.
 *
 * Note that users are still limited to one branch at a time: this module will not make it possible to view multiple
 * branches at the same time.
 *
 * @copyright 2021 Teppo Koivula
 * @license https://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License, version 2
 */
class AdminRestrictBranchSelect extends WireData implements Module {

	/**
	 * Basic information about module
	 *
	 * @return array
	 */
	public static function getModuleInfo() {
		return [
			'title' => 'Admin Restrict Branch Select',
			'summary' => 'Adds support for switching between multiple branches when using Admin Restrict Branch.',
			'author' => 'Teppo Koivula',
			'version' => '0.2.0',
			'autoload' => 2,
			'singular' => true,
			'icon' => 'key',
			'requires' => [
				'PHP>=7.1.0',
				'ProcessWire>=3.0.123',
			],
		];
	}

	/**
	 * Init method
	 */
	public function init() {
		$this->addHookBefore('AdminRestrictBranch::getBranchRootParentId', $this, 'setBranchRootParentId', [
			'priority' => 1,
		]);
		$this->addHookAfter('ProcessPageList::execute', $this, 'selectBranchParent');
	}

	/**
	 * Set branch root parent ID
	 *
	 * @param HookEvent $event
	 */
	protected function ___setBranchRootParentId(HookEvent $event) {

		// bail out early if AdminRestrictBranch is not configured to use user or role branch_parent field
		if ($event->object->matchType !== 'specified_parent' && $event->object->matchType !== 'specified_parent_role') {
			return;
		}

		// get branch parents
		$branch_parents = $this->getBranchParents($event);

		// bail out early if there are no branch parents available
		if ($branch_parents->count() === 0) {
			return;
		}

		// if only one branch parent is available, return its ID
		if ($branch_parents->count() === 1) {
			$event->return = $branch_parents->first()->id;
			$event->replace = true;
			return;
		}

		// change active branch parent if an ID was provided via GET param or found from session data
		$branch_parent_id = $event->input->get('branch_parent', 'int') ?: ($event->session->get('BranchParentID') ?: null);
		if ($branch_parent_id !== null) {
			$branch_parent_page = $event->pages->get($branch_parent_id);
			if (!$branch_parent_page->id || !$branch_parents->has($branch_parent_page)) {
				$branch_parent_id = null;
			}
		}

		// if we don't have a branch parent at this point, find first usable value from branch parents
		if ($branch_parent_id === null) {
			foreach ($branch_parents as $branch_parent) {
				$branch_parent_id = $event->pages->get($branch_parent->id)->id ?? null;
				if ($branch_parent_id !== null) {
					break;
				}
			}
		}

		// bail out early if a branch parent couldn't be selected
		if ($branch_parent_id === null) {
			return;
		}

		// store active branch parent ID in session
		$event->session->set('BranchParentID', $branch_parent_id);

		$event->return = $branch_parent_id;
		$event->replace = true;
	}

	/**
	 * Select active branch parent
	 *
	 * @param HookEvent $event
	 */
	protected function ___selectBranchParent(HookEvent $event) {

		// bail out early if this request is not for page tree markup
		if (strpos($event->return, 'PageListContainer') === false) {
			return;
		}

		// get user and bail out early if they don't have more than one branch selected
		$branch_parents = $this->getBranchParents($event);
		if ($branch_parents->count() < 2) {
			return;
		}

		// inline styles for visually hidden label text
		$label_text_style = '
			position: absolute;
			width: 1px;
			height: 1px;
			padding: 0;
			margin: -1px;
			overflow: hidden;
			clip: rect(0, 0, 0, 0);
			white-space: nowrap;
			border-width: 0;
		';

		// render branch parent select form
		$out = '<form method="get" class="InputfieldForm">'
			. '<label>'
			. '<span style="' . $label_text_style . '">' . $this->_('Active branch') . '</span> '
			. '<select name="branch_parent" onchange="this.form.submit()">';
		foreach ($branch_parents as $branch_parent) {
			$selected = $branch_parent->id == $event->session->get('BranchParentID') ? ' selected="selected"' : '';
			$out .= '<option value="' . $branch_parent->id . '"' . $selected . '>' . $branch_parent->title . '</option>';
		}
		$out .= '</select>'
			. '</label>'
			. '</form>';
		$event->return = $out . $event->return;
	}

	/**
	 * Get branch parents
	 *
	 * @param HookEvent $event
	 * @return PageArray
	 */
	protected function getBranchParents(HookEvent $event): PageArray {
		$user = $event->user;
		$branch_parents = $event->object->matchType === 'specified_parent' ? $user->branch_parent : $this->wire(new PageArray);
		$admin_restrict_branch = $event->object->className === 'AdminRestrictBranch' ? $event->object : $event->modules->get('AdminRestrictBranch');
		if ($admin_restrict_branch->matchType === 'specified_parent_role') {
			foreach ($user->roles as $role) {
				if ($role->branch_parent && $role->branch_parent->count()) {
					$branch_parents->add($role->branch_parent);
				}
			}
		}
		return $branch_parents;
	}

	/**
	 * When the module is installed, make necessary changes to the branch_parent field
	 *
	 * @throws WireException if AdminRestrictBranch is not installed
	 * @throws WireException if branch_parent field is not found
	 */
	public function ___install() {

		// make sure that AdminRestrictBranch is installed
		// note: using a custom check instead of module info (requires) due to load order: this module needs to be
		// loaded before AdminRestrictBranch so that hooking into AdminRestrictBranch::getBranchRootParentId works.
		if (!$this->modules->isInstalled('AdminRestrictBranch')) {
			throw new WireException(
				$this->_('Please install AdminRestrictBranch before this module')
			);
		}

		// make sure that AdminRestrictBranch version is recent enough
		// note: 1.0.3 is the version in which getBranchRootParentId was made hookable
		$arb_info = $this->modules->getModuleInfo('AdminRestrictBranch');
		if (version_compare($arb_info['version'], '1.0.3') < 0) {
			throw new WireException(
				$this->_('Please update AdminRestrictBranch to 1.0.3 or later version before installing this module')
			);
		}

		// get and validate the branch parent field
		$branch_parent = $this->fields->get('branch_parent');
		if ($branch_parent === null) {
			throw new WireException(
				$this->_('Branch parent field not found, please add it before installing this module')
			);
		}

		// modify the branch parent field (unless it already allows multiple pages)
		if ($branch_parent->derefAsPage !== FieldtypePage::derefAsPageArray) {
			$branch_parent->inputfield = 'InputfieldPageListSelectMultiple';
			$branch_parent->derefAsPage = FieldtypePage::derefAsPageArray;
			if ($branch_parent->save()) {
				$this->message($this->_('Branch parent field updated to allow selecting multiple options'));
				return;
			}
			$this->error($this->_('Branch parent field could not be updated to allow selecting multiple options, please update field setting manually'));
		}
	}

}
