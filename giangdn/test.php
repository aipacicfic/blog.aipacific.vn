<?php
namespace Extension\Modules\ModTimesheet\Models;

use App\Mvc\Model\ModelGrid;
use Uri\Request;
use Extension\Modules\ModTimesheet\Models\Tables\TableTimesheet;
use Base\Log;
use Server\Server;
use Base\TimeHelper;
use Base\Factory;

class ModelTimesheet extends ModelGrid
{
    public function getStaffId() {
        $this->_db->select('StaffId')
            ->from('Staff')
            ->where('UserId', '=', Factory::getUser()->UserId)
            ->setQuery();
        return $this->_db->loadResult();
    }
    
    /**
     * @return array
     */
    public function getTimesheets() {
        $id = Request::getInt('id', 0, Request::$REQUEST);
        $day = Request::getString('day', '', Request::$REQUEST);

        if ($id && $day) {
            $this->_db->select_distinct('tk.TimeKeeperId, tk.StaffId, tk.WorkProfileId, tk.Day, tk.CheckInAt, tk.CheckOutAt,
                tk.FromIp, tk.RegistedMobileDeviceId, tk.MobileAppId, tk.WorkLocationId,
                s.StaffCode, wl.Name As WorkLocationName')
            ->from('TimeKeeper')->as('tk')
            ->join('Staff')->as('s')
            ->on('tk.StaffId = s.StaffId')
            ->join('WorkLocation')->as('wl')
            ->on('tk.WorkLocationId = wl.WorkLocationId')
            ->where('tk.StaffId', '=', $id)
            ->and('tk.Day', '=', $this->_db->escapeString($day))
            ->setQuery();
            
            return $this->_db->loadObjectList();
        }
    }
    
    /**
     * @return array
     */
    public function getTimesheetsDays() {
        $id = $this->getStaffId();
        $month = Request::getString('month', '', Request::$REQUEST);
        if ($id !== NULL && preg_match('/^[0-9]{4}(-)[0-1][0-9]$/', $month) && ($daysQuery = $this->getDaysInMonth($month))) {

            $subQuery = $this->_db->select('*')
                ->from('TimeKeeper')
                ->where('StaffId', '=', $id)
                ->setQuery()->getQuery();
            
            $this->_db->renew();
            
            // normal query
            $this->_db->select('DATE_FORMAT(d.Day, \'%W, %M %d, %Y\') AS Day, tk.CheckInAt, tk.CheckOutAt')
                ->from('(' . $daysQuery . ')')->as('d')
                ->left_join('(' . $subQuery . ')')->as('tk')
                ->on('tk.Day = d.Day')
                ->left_join('Staff')->as('s')
                ->on('tk.StaffId = s.StaffId')
                ->order_by('d.Day DESC, tk.TimeKeeperId')
                ->setQuery();
            
            $timesheets = $this->_db->loadObjectList();
            
            $output = [];
            $day = '';
            $len = count($timesheets);
            $index = -1;
            for ($i=0; $i<$len; $i++) {
                $timesheet = $timesheets[$i];
                if ($timesheet->Day != $day) {
                    $index++;
                    date_create($timesheet->Day);
                    $output[$index]['Day'] = $timesheet->Day;
                    $output[$index]['Checked'] = [];
                    $day = $timesheet->Day;
                }
                $output[$index]['Checked'][] = [
                    'CheckInAt' => ($timesheet->CheckInAt !== NULL) ? TimeHelper::time($timesheet->CheckInAt) : NULL, 
                    'CheckOutAt' => ($timesheet->CheckOutAt !== NULL) ? $timesheet->CheckOutAt ? TimeHelper::time($timesheet->CheckOutAt) : NULL : NULL
                ];
            }
            
            return $output;
        }
        return NULL;
    }
    
    private function getDaysInMonth($month) {
        $ex = explode('-', $month);
        $now = date('Y-m-d');
        $exNow = explode('-', $now);
        $monthsInYear = [
            '01' => 31,
            '02' => 28,
            '03' => 31,
            '04' => 30,
            '05' => 31,
            '06' => 30,
            '07' => 31,
            '08' => 31,
            '09' => 30,
            '10' => 31,
            '11' => 30,
            '12' => 31
        ];
        
        if (isset($ex[1])) {
            if ($ex[1] == '02' && intval($ex[0]) %  4 == 0) {
                $monthsInYear['02'] = 29;
            }
            if (intval($exNow[0]) < intval($ex[0])) {
                return 0;
            } else if (intval($exNow[1]) < intval($ex[1])) {
                return 0;
            } else if (intval($exNow[0]) == intval($ex[0]) && intval($exNow[1]) == intval($ex[1]) && intval($exNow[2]) < intval($monthsInYear[$ex[1]])) {
                $monthsInYear[$ex[1]] = intval($exNow[2]);
            }
            $days = [];
            $query = 'SELECT \'' . $month . '-01\' as Day';
            for ($i=2; $i<=$monthsInYear[$ex[1]]; $i++) {
                $query .= ' UNION SELECT \'' . $month . '-' . sprintf('%02d', $i) . '\'';
            }
            return $query;
        }
        return 0;
    }
    
    /**
     * @return boolean
     */
    public function save() {
        $tblTimesheet = new TableTimesheet();
        $data = Request::getArray('Timesheet', []);
        $data['Day'] = date('Y-m-d');
        if (($data['StaffId'] = $this->getStaffId()) !== NULL) {
            $this->_db->select('s.StaffId', 'tk.TimeKeeperId', 'tk.CheckInAt', 'tk.CheckOutAt')
                ->from('Staff')->as('s')
                ->left_join('TimeKeeper')->as('tk')
                ->on('s.StaffId = tk.StaffId')
                ->and('Day', '=', 'CAST(\'' . $data['Day']. '\' AS DATE)')
                ->where('s.StaffId', '=', $data['StaffId'])
                ->order_by('tk.TimeKeeperId DESC')
                ->limit(1)
                ->setQuery();
            $staff = $this->_db->loadObject();
            if ($staff !== NULL) {
                $data['FromIp'] = $this->getClientIp();
                $this->_db->select('WorkLocationId')
                ->from('NetworkConfig')
                ->where('WanIp', '=', $data['FromIp'])
                ->setQuery();
                if (($workLocationId = $this->_db->loadResult()) === NULL) {
                    Log::error('Location is not correct');
                    return false;
                }
                
                $this->_db->select('WorkProfileId')
                ->from('WorkProfile')
                ->where('FromDate', '<=', $data['Day'])
                ->and('ToDate', '>=', $data['Day'])
                ->and('IsCurrentProfile', '=', 1)
                ->and('StaffId', '=', $data['StaffId'])
                ->setQuery();
                $workProfileId = $this->_db->loadResult();
                if ($workProfileId === NULL) {
                    Log::error('Work profile is not correct');
                    return false;
                }
                if ($staff->TimeKeeperId !== NULL && $staff->TimeKeeperId && $staff->CheckOutAt == 0/*$staff->CheckInAt != NULL && $staff->CheckInAt != 0*/) {
                    $data['CheckOutAt'] = time();
                    $data['CheckOutLocationId'] = $workLocationId;
                    $data['TimeKeeperId'] = $staff->TimeKeeperId;
                } else {
                    $data['WorkProfileId'] = $workProfileId;
                    $data['CheckInAt'] = time();
                    $data['CheckOutAt'] = 0;
                    $data['CheckInLocationId'] = $workLocationId;
                    $data['CheckOutLocationId'] = 0;
                }
                // set Day, CheckedAt and Type
                // dd($data);
                return $tblTimesheet->bind($data) && $tblTimesheet->save();
            }
        }
        
        Log::error($this->_db->getQuery());
        return false;
    }
        
    /**
     * {@inheritDoc}
     * @see \App\Mvc\Model\ModelGrid::bindConf()
     */
    protected function bindConf()
    {
        if ($this->conf==null) {
            parent::bindConf();
            
            $this->conf->oder 	= Request::getUserState($this->getFriendlySelfName().'.oder', 'oder', 'Day');
        }
    }
    
    /**
     * Dùng để gọi các query chung như order_by, limit,...
     */
    private function setGeneralQuery() {
        if ($this->conf->oder) {
            $this->_db->order_by($this->conf->oder.' '.$this->conf->odir);
        }
        
        $this->_db->limit($this->conf->lmstart, $this->conf->limit);
    }
    
    /**
     * Lấy IP của client
     * @return string
     */
    private function getClientIp() {
        $ipAddress = '';
        if (Server::get('HTTP_CLIENT_IP', 0)) {
            $ipAddress = $_SERVER['HTTP_CLIENT_IP'] . '';
        } else if(Server::get('HTTP_X_FORWARDED_FOR', 0)) {
            $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] . '';
        } else if(Server::get('HTTP_X_FORWARDED', 0)) {
            $ipAddress = $_SERVER['HTTP_X_FORWARDED'] . '';
        } else if(Server::get('HTTP_FORWARDED_FOR', 0)) {
            $ipAddress = $_SERVER['HTTP_FORWARDED_FOR'] . '';
        } else if(Server::get('HTTP_FORWARDED', 0)) {
            $ipAddress = $_SERVER['HTTP_FORWARDED'] . '';
        } else if(Server::get('REMOTE_ADDR', 0)) {
            $ipAddress = $_SERVER['REMOTE_ADDR'] . '';
        } else {
            $ipAddress = 'unknown ip';
        }
        
        return $ipAddress;
    }
}