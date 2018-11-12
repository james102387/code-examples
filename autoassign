<?php

namespace App\Listeners;

use App\Events\UpdateMember;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Task;
use Event;
use App\Events\DashboardChange;
use App\Providers\Core\Common\DBFunctions;


class AutoAssign
{
    private $_fac_uid;
    private $_allDepartments = array(2,3,4,5,6,7,8);
    private $_user;
    private $_facility;
    private $_maxPerMonth;
    // holds the count of indicators per category
    private $_countPerCategory = array('2'=>0, '3'=>0, '4'=>0, '5'=>0, '6'=>0, '7'=>0, '8'=>0);
    // holds the Indicator UIDs for each indicator assigned to a given month(s)
    private $_indicatorUidPerCategory = array('2' => array(), '3' => array(), '4' => array(), '5' => array(), '6' => array(), '7' => array(), '8' => array());
    private $_departments = array();
    private $_isMultiFacility = false;
    private $_oldUserData = null;

//----------------------------------------------------------------------------------------------------------------------

    public function __construct()
    {
	   $this->_fac_uid = session('fac_uid');
       $this->_facility = \Facility::find($this->_fac_uid);
       $autoAssignNum = $this->_facility->auto_assign_number;
       $remainder = $autoAssignNum % 3;
       $nearestMultiple = $autoAssignNum - $remainder;
       $this->_maxPerMonth = array('2' => array('0' => $nearestMultiple/3.0, '1' => $nearestMultiple/3.0, '2' => ($nearestMultiple/3.0) + $remainder), '3' => array('0' => $nearestMultiple/3.0, '1' => $nearestMultiple/3.0, '2' => ($nearestMultiple/3.0) + $remainder), '4' => array('0' => $nearestMultiple/3.0, '1' => $nearestMultiple/3.0, '2' => ($nearestMultiple/3.0) + $remainder), '5' => array('0' => $nearestMultiple/3.0, '1' => $nearestMultiple/3.0, '2' => ($nearestMultiple/3.0) + $remainder), '6' => array('0' => $nearestMultiple/3.0, '1' => $nearestMultiple/3.0, '2' => ($nearestMultiple/3.0) + $remainder),  '7' => array('0' => $nearestMultiple/3.0, '1' => $nearestMultiple/3.0, '2' => ($nearestMultiple/3.0) + $remainder),  '8' => array('0' => $nearestMultiple/3.0, '1' => $nearestMultiple/3.0, '2' => ($nearestMultiple/3.0) + $remainder));
    }


//----------------------------------------------------------------------------------------------------------------------

    public function handle(UpdateMember $event)
    {
    	if(!$this->_facility->auto_assign) //If facility doesn't have auto-assign turned on, return.
    	{
    	    return true;
    	}

        $deps = json_decode($event->user->acl, true)['fac'.session('fac_uid')]['departments'];
        $oldDeps = json_decode($event->oldUserData->acl, true)['fac'.session('fac_uid')]['departments'];
        if(($event->oldUserData === null && $event->modelChange != 'created') || $deps == $oldDeps || $event->user->is_surveyor == 1 || $event->user->is_site_admin == 1) return true;

        // We now know there has been a change in departments.
        $this->_user = $event->user;
        $this->_user->departments = json_encode($deps);
        $this->_user->save();
        $this->_departments = json_decode($this->_user->acl, true)['fac'.$this->_fac_uid]['departments']; //$this->_user->departments; // If is multi-facility, grab from ACL
        $this->_isMultiFacility = $event->isMultiFacility;
        $this->_oldUserData = $event->oldUserData;
        // Begin
        $this->go();

        $data = array();
        $data['old'] = $oldDeps;
        $data['new'] = $deps;
        userLog('Auto Assign', 'User '.$this->_user->first_name.' '.$this->_user->last_name.' was autoassigned tasks', 'fac-'.$this->_fac_uid,$data);
    }

//----------------------------------------------------------------------------------------------------------------------


    public function go()
    {
        foreach ($this->_oldUserData->departments as $dep){
            $this->_getUserCurrentForCategory($dep);
        }

        foreach ($this->_departments as $dep) {
            if($this->_hasTasksAssigned($dep)) continue;
            $this->_getTasksForReassign($dep);
            $this->_getFiveIndicatorsForCategory($dep);
            $this->_autoAssignCategory($dep);
        }
        foreach (array_diff($this->_allDepartments, $this->_departments) as $dep) {
            $this->_removeUserFromOldCategory($dep);
        }
    }

//----------------------------------------------------------------------------------------------------------------------


    private function _getUserCurrentForCategory($dep)
    {
        $tasks = \Task::where('dep_uid', $dep)->where('asned_usr_uid', $this->_user->uid)->get();
        foreach ($tasks as $task) {
            $this->_gatherTaskMonthsForDepartment($task);
            if ($task->needs_assign == 1) {
                \Task::where('uid', $task->uid)->update(['needs_assign' => 0]);
            }
        }
    }

//----------------------------------------------------------------------------------------------------------------------


    private function _hasTasksAssigned($dep){
        \Task::where('dep_uid', $dep)->where('asned_usr_uid', $this->_user->uid)->update(['needs_assign' => 0]);
        $tasks = \Task::where('dep_uid', $dep)->where('asned_usr_uid', $this->_user->uid)->get();
        if($tasks->count() != 0) return true;
        else return false;
    }

//----------------------------------------------------------------------------------------------------------------------


    private function _getTasksForReassign($dep)
    {
        $reassignTasks = \Task::where('needs_assign', 1)->where('dep_uid', $dep)->get(['uid', 'months', 'alert_months']);
        if (is_null($reassignTasks)) {
            return;
        }
        foreach ($reassignTasks as $task) {
            $this->_gatherTaskMonthsForDepartment($task);
        }
    }

//----------------------------------------------------------------------------------------------------------------------


    private function _removeUserFromOldCategory($dep)
    {
        $oldTasks = \Task::where('dep_uid', $dep)->where('asned_usr_uid', $this->_user->uid)->groupBy('uid')->get();
        if (is_null($oldTasks)) {
            return;
        }
        foreach ($oldTasks as $task) {
            \Task::where('uid', $task->uid)->update(['needs_assign' => 1]);
            Event::fire(new DashboardChange($task, false));
        }
    }

//----------------------------------------------------------------------------------------------------------------------


    private function _getFiveIndicatorsForCategory($dep)
    {
        $db = DBFunctions::facDB();
        $indicators = $db->select("(
                            SELECT DISTINCT i.uid as ind_uid, i.dep_uid, i.reg_uid, i.month, t.uid
                                FROM snfqapi.indicators AS i
                                LEFT JOIN tasks as t ON i.uid = t.ind_uid
                                WHERE i.month LIKE '[\"0\"%' AND i.dep_uid = ".$dep." AND (i.cus_facility = 0 OR i.cus_facility = ".$this->_fac_uid.")
                                AND ((t.needs_assign = 1 OR t.needs_assign IS NULL) OR (t.asned_usr_uid = 0 OR t.asned_usr_uid IS NULL))
                                ORDER BY RAND() LIMIT ".$this->_maxPerMonth[$dep]['0']."
                        )
                        UNION
                        (
                            SELECT DISTINCT i.uid as ind_uid, i.dep_uid, i.reg_uid, i.month, t.uid
                                FROM snfqapi.indicators AS i
                                LEFT JOIN tasks as t ON i.uid = t.ind_uid
                                WHERE i.month LIKE '[\"1\"%' AND i.dep_uid = ".$dep." AND (i.cus_facility = 0 OR i.cus_facility = ".$this->_fac_uid.")
                                AND ((t.needs_assign = 1 OR t.needs_assign IS NULL) OR (t.asned_usr_uid = 0 OR t.asned_usr_uid IS NULL))
                                ORDER BY RAND() LIMIT ".$this->_maxPerMonth[$dep]['1']."
                        )
                        UNION
                        (
                            SELECT DISTINCT i.uid as ind_uid, i.dep_uid, i.reg_uid, i.month, t.uid
                                FROM snfqapi.indicators AS i
                                LEFT JOIN tasks as t ON i.uid = t.ind_uid
                                WHERE i.month LIKE '[\"2\"%' AND i.dep_uid = ".$dep." AND (i.cus_facility = 0 OR i.cus_facility = ".$this->_fac_uid.")
                                AND ((t.needs_assign = 1 OR t.needs_assign IS NULL) OR (t.asned_usr_uid = 0 OR t.asned_usr_uid IS NULL))
                                ORDER BY RAND() LIMIT ".$this->_maxPerMonth[$dep]['2']."
                        )
                        ORDER BY RAND()");

        if (!is_null($indicators)) {
            $this->_setIndicatorsForCategory($indicators, $dep);
        }
    }
//----------------------------------------------------------------------------------------------------------------------

    /**
     * Sets the indicators for a given category
     * @param array $indicators -The indicators
     * @param int $dep        -The category
     */
    private function _setIndicatorsForCategory($indicators, $dep)
    {
        foreach ($indicators as $row) {
            $this->_countPerCategory[$dep]++;
            $thisIndicator = array(
                                        'uid'     => $row->ind_uid,
                                        'month'   => $row->month,
                                        'reg_uid' => $row->reg_uid,
                                        'dep_uid' => $row->dep_uid,
                                        'task_uid'    => $row->uid
                                );
            $this->_indicatorUidPerCategory[$dep][] = $thisIndicator;
        }
    }

//----------------------------------------------------------------------------------------------------------------------

    /**
     * Does the autoassigning
     * @param  int $dep the category
     */
    private function _autoAssignCategory($dep)
    {
        foreach ($this->_indicatorUidPerCategory[$dep] as $row) {
            $data = array();
            if ($row['task_uid'] == '') {
                $data['months'] = $row['month'];
                $data['asned_usr_uid'] = $this->_user->uid;
                $data['ind_uid'] = $row['uid'];
                $data['reg_uid'] = $row['reg_uid'];
                $data['dep_uid'] = $row['dep_uid'];
                $data['needs_assign'] = 0;
                $task = \Task::create($data);
                Event::fire(new DashboardChange($task, false));
            } else {
                $task = \Task::where('uid', $row['task_uid']);
                $task->update(['needs_assign' => 0, 'asned_usr_uid' => $this->_user->uid]);
                Event::fire(new DashboardChange($task, false));
            }
        }
    }

//----------------------------------------------------------------------------------------------------------------------


    public function emptyUsers()
    {
        \Task::update(['asned_usr_uid' => 0,'lock' => 0, 'needs_assign' => 0]);
    }

//----------------------------------------------------------------------------------------------------------------------


    public function swapAdministratorTasks($newUser, $oldUser)
    {
        $tasks = \Task::where('asned_usr_uid', $oldUser)->where('lock', 0)->get();

        if (is_null($tasks)) {
            return;
        }
        foreach ($tasks as $task) {
            \Task::where('uid', $task->uid)->update(['asned_usr_uid' => $newUser]);
        }
    }

//----------------------------------------------------------------------------------------------------------------------


    private function _gatherTaskMonthsForDepartment($task){
        $months = $task->months;
        if ($task->alert_months != '[]' || !empty($task->alert_months)) {
            $alertMonthsMerge = $task->alert_months;
            if (!$alertMonthsMerge) {
                $months = array_merge($months, $alertMonthsMerge);
            }
        }
        if (!$months) {
            return;
        }
        $this->_maxPerMonth[$dep][$months[0]] = ($this->_maxPerMonth[$dep][$months[0]] > 0 ? $this->_maxPerMonth[$dep][$months[0]] - 1 : 0);
    }
}
