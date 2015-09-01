<?php

use Application\StatisticHelper;
use Application\SoapHelper;

/**
 * Class IndexController
 * @method Zend_Controller_Request_Http getRequest()
 */
class IndexController extends Zend_Controller_Action
{
    const FILE_WITH_USERS               = '../application/configs/users.csv';
    const FILE_WITH_INEFFICIENT_APPS    = '../application/configs/inefficientApps.csv';

    const DEFAULT_FOCUS_FACTOR          = 50;
    const DEFAULT_EFFICIENCY            = 5;

    protected $_otherApps;

    public function indexAction()
    {
        $request = $this->getRequest();
        $step = $this->_getStep();
        switch ($step) {
            case 2:
                $form = $this->_getFormStep1();
                if ($form->isValid($request->getPost())) {
                    $form = $this->_getFormStep2();
                } else {
                    $step = 1;
                }
                break;
            case 3:
                $form = $this->_getFormStep2();
                if ($form->isValid($request->getPost())) {
                    $this->view->result = $this->_sendStatistics();
                    $step = 4;
                } else {
                    $step = 2;
                }
                break;
            default:
                $form = $this->_getFormStep1();
        }
        $this->view->step = $step;
        $this->view->form = $form;
    }

    protected function _sendStatistics()
    {
        $dirtyData = $this->getRequest()->getPost();
        $users = array();
        for ($i = 0; $i < $dirtyData['usersCount']; $i++) {
            $users[] = array(
                'name'          => $dirtyData['name' . $i],
                'efficiency'    => $dirtyData['efficiency' . $i],
                'focusFactor'   => $dirtyData['focusFactor' . $i],
                'applications'  => $dirtyData['applications' . $i],
            );
        }
        $applications = json_decode($dirtyData['applicationsList'], true);
        $statistics = array();
        $startDate = strtotime($this->getRequest()->getPost('startDate'));
        $endDate = strtotime($this->getRequest()->getPost('endDate'));
        $endDate += 86400;//1 Day
        do {
            $statistics[date('Y-m-d', $startDate)] = $this->_prepareStatistics($users, $applications);
            $startDate += 86400;//1 Day
        } while ($startDate != $endDate);

        return SoapHelper::sendStatistics($this->getRequest()->getPost('accountKey'), $statistics);
    }

    protected function _getOtherApps()
    {
        if (null === $this->_otherApps) {
            $this->_otherApps = array();
            $file = self::FILE_WITH_INEFFICIENT_APPS;
            if (file_exists($file)) {
                $list = new SplFileObject($file);
                $list->setFlags(SplFileObject::READ_CSV);
                foreach ($list as $row) {
                    list($name, $title, $path, $url) = $row;
                    $this->_otherApps[] = array(
                        'name'  => $name,
                        'title' => $title,
                        'path'  => $path,
                        'url'   => $url,
                    );
                }
            }
        }
        return $this->_otherApps;
    }

    protected function _prepareStatistics($users, $applications)
    {
        $result = array();
        foreach ($users as $user) {
            $mainApps = array();
            foreach ($applications as $index => $app) {
                if (in_array($index, $user['applications'])) {
                    $mainApps[] = $app;
                }
            }
            if ('' === $user['focusFactor']) {
                $user['focusFactor'] = self::DEFAULT_FOCUS_FACTOR;
            }
            if ('' === $user['efficiency']) {
                $user['efficiency'] = self::DEFAULT_EFFICIENCY;
            }
            $result[$user['name']] = StatisticHelper::makeStatistic(
                $user['focusFactor'],
                $user['efficiency'],
                $mainApps,
                $this->_getOtherApps()
            );
        }
        return $result;
    }

    protected function _getStep()
    {
        return $this->getRequest()->isPost()
            ? $this->getRequest()->getPost('step')
            : 1;
    }

    protected function _getApplications()
    {
        $request = $this->getRequest();
        if (3 == $this->_getStep()) {
            return json_decode($request->getPost('applicationsList'), true);
        }
        $applications = array_filter(array_map('trim', explode(',', $request->getPost('applications'))));
        foreach ($applications as &$app) {
            $app = array(
                'name'      => $app,
                'title'     => '',
                'path'      => '',
                'url'       => '',
            );
        }
        return array_merge(
            $applications,
            $this->_readApplicationsFromCsv($this->_getTmpFileName('applicationsFile'))
        );
    }

    protected function _getTmpFileName($alias)
    {
        $adapter = new Zend_File_Transfer_Adapter_Http();
        $info = $adapter->getFileInfo($alias);
        return $info[$alias]['tmp_name'];
    }

    protected function _getUserNames()
    {
        $request = $this->getRequest();
        $count = $request->getPost('usersCount');
        if (3 == $this->_getStep()) {
            $result = array();
            for ($i = 0; $i < $count; $i++) {
                $result[] = $request->getPost('name' . $i);
            }
            return $result;
        }
        $usersNames = array_filter(array_map('trim', explode(',', $request->getPost('usersNames'))));
        if (count($usersNames) > $count) {
            shuffle($usersNames);
            return array_slice($usersNames, 0, $count);
        }
        $result = $usersNames;
        $neededCount = $count - count($result);

        $usersFromForm = $this->_readUsersFromCsv($this->_getTmpFileName('usersNamesFile'));

        if (count($usersFromForm) > $neededCount) {
            shuffle($usersFromForm);
            return array_merge($result, array_slice($usersFromForm, 0, $neededCount));
        }
        $result = array_merge($result, $usersFromForm);
        $neededCount = $count - count($result);

        $usersFromConfig = $this->_readUsersFromCsv(self::FILE_WITH_USERS);
        if (count($usersFromConfig) > $neededCount) {
            shuffle($usersFromConfig);
            return array_merge($result, array_slice($usersFromConfig, 0, $neededCount));
        }
        return array_merge($result, $usersFromConfig);
    }

    protected function _readUsersFromCsv($file)
    {
        $result = array();
        if (file_exists($file)) {
            $list = new SplFileObject($file);
            $list->setFlags(SplFileObject::READ_CSV);
            foreach ($list as $row) {
                $result[] = $row[0] . ' ' . $row[1];
            }
        }
        return $result;
    }

    protected function _readApplicationsFromCsv($file)
    {
        $result = array();
        if (file_exists($file)) {
            $list = new SplFileObject($file);
            $list->setFlags(SplFileObject::READ_CSV);
            foreach ($list as $row) {
                list($name, $title, $path, $url) = $row;
                $result[] = compact('name', 'title', 'path', 'url');
            }
        }
        return $result;
    }

    protected function _getFormStep1()
    {
        $form = new Zend_Form();

        $form->addElement('text', 'accountKey', array(
            'label'         => 'Account Key',
            'required'      => true,
        ));
        $form->addElement('text', 'usersCount', array(
            'label'         => 'Users Count',
            'required'      => true,
            'validators'    => array('Int'),
        ));
        $form->addElement('text', 'startDate', array(
            'label'         => 'Start Date',
            'required'      => true,
        ));
        $form->addElement('text', 'endDate', array(
            'label'         => 'End Date',
            'required'      => true,
        ));
        $form->addElement('file', 'applicationsFile', array(
            'description'   => 'allowed formats: CSV. Structure of file: Application Name (Required), '
                             . 'Title, Path, URL Visited',
            'validators'    => array(new Zend_Validate_File_Extension('csv')),
        ));
        $transferAdapter = new Zend_File_Transfer_Adapter_Http();
        $form->addElement('textarea', 'applications', array(
            'attribs'       => array('rows' => 4),
            'description'   => 'Type only applications names separated by comma. They will be added '
                             . 'to applications form provided file.',
            'required'      => !$transferAdapter->isUploaded('applicationsFile'),
        ));
        $form->addDisplayGroup(
            array('applications', 'applicationsFile'),
            'applicationsGroup',
            array('legend' => 'Applications')
        );
        $form->addElement('file', 'usersNamesFile', array(
            'description'   => 'allowed format: CSV. Structure of file: First Name, Last Name',
            'validators'    => array(new Zend_Validate_File_Extension('csv')),
        ));
        $form->addElement('textarea', 'usersNames', array(
            'attribs'       => array('rows' => 4),
            'description'   => 'Type the first and the second names separated by comma. '
                             . 'If you load the file and type the names in textarea the '
                             . 'names will be taken from this one first.'
        ));
        $form->addDisplayGroup(
            array('usersNames', 'usersNamesFile'),
            'usersGroup',
            array('legend' => 'Users Names')
        );

        $form->addElement('hidden', 'step', array(
            'value'     => 2,
        ));
        $form->addElement('submit', 'submit', array(
            'label'         => 'Next Step'
        ));

        return $form;
    }

    protected function _getFormStep2()
    {
        $form = new Zend_Form();

        $users = $this->_getUserNames();
        $applications = $this->_getApplications();

        $usersCount = count($users);
        for ($i = 0; $i < $usersCount; $i++) {
            $form->addElement('hidden', 'name' . $i, array(
                'value'         => $users[$i],
            ));
            $form->addElement('text', 'efficiency' . $i, array(
                'label'         => 'Efficiency',
                'validators'    => array('Int', new Zend_Validate_Between(array('min' => 0, 'max' => 10))),
                'description'   => 'Default value is ' . self::DEFAULT_EFFICIENCY
            ));
            $form->addElement('text', 'focusFactor' . $i, array(
                'label'         => 'Focus Factor',
                'validators'    => array('Int', new Zend_Validate_Between(array('min' => 0, 'max' => 100))),
                'description'   => 'Default value is ' . self::DEFAULT_FOCUS_FACTOR
            ));
            $form->addElement('multiCheckbox', 'applications' . $i, array(
                'label'         => 'Main Applications',
                'required'      => true,
                'multiOptions'  => $this->_prepareApplicationsForCheckbox($applications),
            ));
            $form->addDisplayGroup(
                array('name'. $i, 'efficiency' . $i, 'focusFactor' . $i, 'applications' . $i),
                'userGroup' . $i,
                array('legend' => $users[$i])
            );
        }

        $form->addElement('hidden', 'applicationsList', array(
            'value'     => json_encode($applications),
        ));
        $form->addElement('hidden', 'usersCount', array(
            'value'     => $usersCount,
        ));
        $form->addElement('hidden', 'accountKey', array(
            'value'     => $this->getRequest()->getPost('accountKey'),
        ));
        $form->addElement('hidden', 'startDate', array(
            'value'     => $this->getRequest()->getPost('startDate'),
        ));
        $form->addElement('hidden', 'endDate', array(
            'value'     => $this->getRequest()->getPost('endDate'),
        ));
        $form->addElement('hidden', 'step', array(
            'value'     => 3,
        ));
        $form->addElement('submit', 'submit', array(
            'label'     => 'Submit',
        ));

        return $form;
    }

    protected function _prepareApplicationsForCheckbox($applications)
    {
        foreach ($applications as &$app) {
            $app = $app['name'];
        }
        return $applications;
    }
}

