<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/common.php';
require_once __DIR__ . '/../libs/local.php';

class NUTClient extends IPSModule
{
    use NUTClient\StubsCommonLib;
    use NUTClientLocalLib;

    public function __construct(string $InstanceID)
    {
        parent::__construct($InstanceID);

        $this->CommonConstruct(__DIR__);
    }

    public function __destruct()
    {
        $this->CommonDestruct();
    }

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('module_disable', false);

        $this->RegisterPropertyString('hostname', '');
        $this->RegisterPropertyInteger('port', 3493);

        $this->RegisterPropertyString('user', '');
        $this->RegisterPropertyString('password', '');

        $this->RegisterPropertyString('upsname', 'ups');

        $this->RegisterPropertyInteger('update_interval', '30');

        $this->RegisterPropertyString('use_fields', '[]');

        $this->RegisterPropertyString('add_fields', '[]');
        $this->RegisterPropertyInteger('convert_script', 0);

        $this->RegisterAttributeString('UpdateInfo', json_encode([]));
        $this->RegisterAttributeString('ModuleStats', json_encode([]));

        $this->InstallVarProfiles(false);

        $this->RegisterTimer('UpdateData', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "UpdateData", "");');

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    private function CheckModuleConfiguration()
    {
        $r = [];

        $hostname = $this->ReadPropertyString('hostname');
        if ($hostname == '') {
            $this->SendDebug(__FUNCTION__, '"hostname" is needed', 0);
            $r[] = $this->Translate('Hostname must be specified');
        }

        $port = $this->ReadPropertyInteger('port');
        if ($port == 0) {
            $this->SendDebug(__FUNCTION__, '"port" is needed', 0);
            $r[] = $this->Translate('Port must be specified');
        }

        $upsname = $this->ReadPropertyString('upsname');
        if ($upsname == '') {
            $this->SendDebug(__FUNCTION__, '"upsname" is needed', 0);
            $r[] = $this->Translate('UPS-Identification must be specified');
        }

        return $r;
    }

    public function MessageSink($tstamp, $senderID, $message, $data)
    {
        parent::MessageSink($tstamp, $senderID, $message, $data);

        if ($message == IPS_KERNELMESSAGE && $data[0] == KR_READY) {
            $this->SetUpdateInterval();
        }
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $propertyNames = ['convert_script'];
        $this->MaintainReferences($propertyNames);

        if ($this->CheckPrerequisites() != false) {
            $this->MaintainTimer('UpdateData', 0);
            $this->MaintainStatus(self::$IS_INVALIDPREREQUISITES);
            return;
        }

        if ($this->CheckUpdate() != false) {
            $this->MaintainTimer('UpdateData', 0);
            $this->MaintainStatus(self::$IS_UPDATEUNCOMPLETED);
            return;
        }

        if ($this->CheckConfiguration() != false) {
            $this->MaintainTimer('UpdateData', 0);
            $this->MaintainStatus(self::$IS_INVALIDCONFIG);
            return;
        }

        // MaintainVariable

        $vpos = 1;
        $varList = [];

        $identList = [];
        $use_fields = json_decode($this->ReadPropertyString('use_fields'), true);
        $fieldMap = $this->getFieldMap();
        foreach ($fieldMap as $map) {
            $ident = $this->GetArrayElem($map, 'ident', '');
            $use = false;
            foreach ($use_fields as $field) {
                if ($ident == $this->GetArrayElem($field, 'ident', '')) {
                    $use = (bool) $this->GetArrayElem($field, 'use', false);
                    break;
                }
            }
            if ($use) {
                $identList[] = $ident;
            }
            $ident = 'DP_' . str_replace('.', '_', $ident);
            $desc = $this->GetArrayElem($map, 'desc', '');
            $vartype = $this->GetArrayElem($map, 'type', '');
            $varprof = $this->GetArrayElem($map, 'prof', '');
            $this->SendDebug(__FUNCTION__, 'register variable: ident=' . $ident . ', vartype=' . $vartype . ', varprof=' . $varprof . ', use=' . $this->bool2str($use), 0);
            $r = $this->MaintainVariable($ident, $this->Translate($desc), $vartype, $varprof, $vpos++, $use);
            if ($r == false) {
                $this->SendDebug(__FUNCTION__, 'failed to register variable', 0);
            }
            $varList[] = $ident;

            if ($ident == 'DP_ups_status') {
                $ident .= '_info';
                $this->SendDebug(__FUNCTION__, 'additional register variable: ident=' . $ident, 0);
                $r = $this->MaintainVariable($ident, $this->Translate('Additional status'), VARIABLETYPE_STRING, '', $vpos++, $use);
                if ($r == false) {
                    $this->SendDebug(__FUNCTION__, 'failed to register variable', 0);
                }
                $varList[] = $ident;
            }
        }

        $vpos = 50;

        $add_fields = json_decode($this->ReadPropertyString('add_fields'), true);
        foreach ($add_fields as $field) {
            $this->SendDebug(__FUNCTION__, 'field=' . print_r($field, true), 0);
            $ident = $this->GetArrayElem($field, 'ident', '');
            $vartype = $this->GetArrayElem($field, 'vartype', -1);
            if ($ident == '' || $vartype == -1) {
                continue;
            }
            $desc = $ident;
            $ident = 'DP_' . str_replace('.', '_', $ident);
            $this->SendDebug(__FUNCTION__, 'register variable: ident=' . $ident . ', vartype=' . $vartype, 0);
            $r = $this->MaintainVariable($ident, $desc, $vartype, '', $vpos++, true);
            if ($r == false) {
                $this->SendDebug(__FUNCTION__, 'failed to register variable', 0);
            }
            $varList[] = $ident;
        }

        $vpos = 100;

        $this->MaintainVariable('LastUpdate', $this->Translate('Last update'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, true);

        $objList = [];
        $this->findVariables($this->InstanceID, $objList);
        foreach ($objList as $obj) {
            $ident = $obj['ObjectIdent'];
            if (!in_array($ident, $varList)) {
                $this->SendDebug(__FUNCTION__, 'unregister variable: ident=' . $ident, 0);
                $this->UnregisterVariable($ident);
            }
        }

        $module_disable = $this->ReadPropertyBoolean('module_disable');
        if ($module_disable) {
            $this->MaintainTimer('UpdateData', 0);
            $this->MaintainStatus(IS_INACTIVE);
            return;
        }

        $upsname = $this->ReadPropertyString('upsname');
        $ups_list = $this->ExecuteList('UPS', '');
        if ($ups_list == false) {
            $this->MaintainStatus(self::$IS_NOSERVICE);
            return false;
        }
        $ups_found = false;
        foreach ($ups_list as $ups) {
            if ($ups['id'] == $upsname) {
                $ups_found = true;
            }
        }
        if ($ups_found == false) {
            $this->MaintainTimer('UpdateData', 0);
            $this->MaintainStatus(self::$IS_UPSIDUNKNOWN);
            return false;
        }

        $this->MaintainStatus(IS_ACTIVE);

        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->SetUpdateInterval();
        }
    }

    private function SetUpdateInterval()
    {
        $sec = $this->ReadPropertyInteger('update_interval');
        $msec = $sec > 0 ? $sec * 1000 : 0;
        $this->MaintainTimer('UpdateData', $msec);
    }

    private function findVariables($objID, &$objList)
    {
        $chldIDs = IPS_GetChildrenIDs($objID);
        foreach ($chldIDs as $chldID) {
            $obj = IPS_GetObject($chldID);
            switch ($obj['ObjectType']) {
                case OBJECTTYPE_VARIABLE:
                    if (preg_match('#^DP_#', $obj['ObjectIdent'], $r)) {
                        $objList[] = $obj;
                    }
                    break;
                case OBJECTTYPE_CATEGORY:
                    $this->findVariables($chldID, $objList);
                    break;
                default:
                    break;
            }
        }
    }

    private function GetFormElements()
    {
        $formElements = $this->GetCommonFormElements('NUT Client (Network UPS Tools)');

        if ($this->GetStatus() == self::$IS_UPDATEUNCOMPLETED) {
            return $formElements;
        }

        $formElements[] = [
            'type'    => 'CheckBox',
            'name'    => 'module_disable',
            'caption' => 'Disable instance'
        ];

        $formElements[] = [
            'type'      => 'ExpansionPanel',
            'items'     => [
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'hostname',
                    'caption' => 'Hostname'
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'port',
                    'caption' => 'Port'
                ],
                [
                    'type'    => 'Label',
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'upsname',
                    'caption' => 'UPS-Identification'
                ],
                [
                    'type'    => 'Label',
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'update_interval',
                    'minimum' => 0,
                    'suffix'  => 'Seconds',
                    'caption' => 'Update interval',
                ],
            ],
            'caption'   => 'Basic configuration',
        ];

        $formElements[] = [
            'type'    => 'ExpansionPanel',
            'items'   => [
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'user',
                    'caption' => 'Username'
                ],
                [
                    'type'    => 'PasswordTextBox',
                    'name'    => 'password',
                    'caption' => 'Password'
                ],
            ],
            'caption' => 'Authentification (optional)',
        ];

        $values = [];
        $fieldMap = $this->getFieldMap();
        $use_fields = json_decode($this->ReadPropertyString('use_fields'), true);
        foreach ($fieldMap as $map) {
            $ident = $this->GetArrayElem($map, 'ident', '');
            $desc = $this->GetArrayElem($map, 'desc', '');
            $use = false;
            foreach ($use_fields as $field) {
                if ($ident == $this->GetArrayElem($field, 'ident', '')) {
                    $use = (bool) $this->GetArrayElem($field, 'use', false);
                    break;
                }
            }
            $values[] = ['ident' => $ident, 'desc' => $this->Translate($desc), 'use' => $use];
        }

        $formElements[] = [
            'type'    => 'ExpansionPanel',
            'items'   => [
                [
                    'type'     => 'List',
                    'name'     => 'use_fields',
                    'rowCount' => count($values),
                    'add'      => false,
                    'delete'   => false,
                    'columns'  => [
                        [
                            'caption' => 'Datapoint',
                            'name'    => 'ident',
                            'width'   => '200px',
                            'save'    => true
                        ],
                        [
                            'caption' => 'Description',
                            'name'    => 'desc',
                            'width'   => 'auto'
                        ],
                        [
                            'caption' => 'Use',
                            'name'    => 'use',
                            'width'   => '100px',
                            'edit'    => [
                                'type' => 'CheckBox'
                            ]
                        ],
                    ],
                    'values'   => $values,
                    'caption'  => 'Predefined datapoints',
                ],
                [
                    'type'     => 'List',
                    'name'     => 'add_fields',
                    'rowCount' => 10,
                    'add'      => true,
                    'delete'   => true,
                    'columns'  => [
                        [
                            'caption' => 'Datapoint',
                            'name'    => 'ident',
                            'add'     => '',
                            'width'   => 'auto',
                            'edit'    => [
                                'type' => 'ValidationTextBox'
                            ]
                        ],
                        [
                            'caption' => 'Variable type',
                            'name'    => 'vartype',
                            'add'     => VARIABLETYPE_STRING,
                            'width'   => '150px',
                            'edit'    => [
                                'type'    => 'Select',
                                'options' => [
                                    [
                                        'caption' => 'Boolean',
                                        'value'   => VARIABLETYPE_BOOLEAN
                                    ],
                                    [
                                        'caption' => 'Integer',
                                        'value'   => VARIABLETYPE_INTEGER
                                    ],
                                    [
                                        'caption' => 'Float',
                                        'value'   => VARIABLETYPE_FLOAT
                                    ],
                                    [
                                        'caption' => 'String',
                                        'value'   => VARIABLETYPE_STRING
                                    ],
                                ]
                            ]
                        ],
                    ],
                    'caption'  => 'Additional datapoints',
                ],
                [
                    'type'    => 'SelectScript',
                    'name'    => 'convert_script',
                    'caption' => 'convert values',
                ],
            ],
            'caption' => 'Variables',
        ];

        return $formElements;
    }

    private function GetFormActions()
    {
        $formActions = [];

        if ($this->GetStatus() == self::$IS_UPDATEUNCOMPLETED) {
            $formActions[] = $this->GetCompleteUpdateFormAction();

            $formActions[] = $this->GetInformationFormAction();
            $formActions[] = $this->GetReferencesFormAction();

            return $formActions;
        }

        $formActions[] = [
            'type'  => 'RowLayout',
            'items' => [
                [
                    'type'    => 'Button',
                    'caption' => 'Test access',
                    'onClick' => 'IPS_RequestAction(' . $this->InstanceID . ', "TestAccess", "");',
                ],
                [
                    'type'    => 'Button',
                    'caption' => 'Show variables',
                    'onClick' => 'IPS_RequestAction(' . $this->InstanceID . ', "ShowVars", "");',
                ],
                [
                    'type'    => 'Button',
                    'label'   => 'Description of the variables',
                    'onClick' => 'echo \'https://networkupstools.org/docs/user-manual.chunked/apcs01.html#_examples\';'
                ],
                [
                    'type'    => 'Button',
                    'caption' => 'Update data',
                    'onClick' => 'IPS_RequestAction(' . $this->InstanceID . ', "UpdateData", "");',
                ],
            ],
        ];

        $formActions[] = [
            'type'      => 'ExpansionPanel',
            'caption'   => 'Expert area',
            'expanded'  => false,
            'items'     => [
                $this->GetInstallVarProfilesFormItem(),
            ]
        ];

        $formActions[] = $this->GetInformationFormAction();
        $formActions[] = $this->GetReferencesFormAction();

        return $formActions;
    }

    public function RequestAction($ident, $value)
    {
        if ($this->CommonRequestAction($ident, $value)) {
            return;
        }

        switch ($ident) {
            case 'TestAccess':
                $this->TestAccess();
                break;
            case 'ShowVars':
                $this->ShowVars();
                break;
            case 'UpdateData':
                $this->UpdateData();
                break;
            default:
                $this->SendDebug(__FUNCTION__, 'invalid ident ' . $ident, 0);
                break;
        }
    }

    private function TestAccess()
    {
        if ($this->GetStatus() == IS_INACTIVE) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            $msg = $this->GetStatusText();
            $this->PopupMessage($msg);
            return;
        }

        $line = $this->ExecuteVersion();
        if ($line == false) {
            $txt = $this->Translate('access failed') . PHP_EOL;
            $txt .= PHP_EOL;
        } else {
            $txt = $this->Translate('access succeeded') . PHP_EOL;
            $txt .= PHP_EOL;
            $txt .= $line . PHP_EOL;
            $txt .= PHP_EOL;

            $upsname = $this->ReadPropertyString('upsname');
            $ups_list = $this->ExecuteList('UPS', '');
            $n_ups = is_array($ups_list) ? count($ups_list) : 0;
            $ups_found = false;
            if ($n_ups > 0) {
                $txt .= $n_ups . ' ' . $this->Translate('UPS found') . PHP_EOL;
                foreach ($ups_list as $ups) {
                    $this->SendDebug(__FUNCTION__, 'ups=' . print_r($ups, true), 0);
                    $txt .= ' - ' . $ups['id'] . PHP_EOL;
                    if ($ups['id'] == $upsname) {
                        $ups_found = true;
                    }
                }
            }
            if ($ups_found == false) {
                $txt .= PHP_EOL . $this->Translate('Warning: the specified UPS ID is unknown') . PHP_EOL;
                $this->MaintainStatus(self::$IS_UPSIDUNKNOWN);
            }

            $b = false;
            $vars = $this->ExecuteList('VAR', '');
            if (is_array($vars)) {
                $use_fields = json_decode($this->ReadPropertyString('use_fields'), true);
                foreach ($use_fields as $field) {
                    $use = (bool) $this->GetArrayElem($field, 'use', false);
                    if (!$use) {
                        continue;
                    }
                    $ident = $this->GetArrayElem($field, 'ident', '');
                    $found = false;
                    foreach ($vars as $var) {
                        if ($ident == $var['varname']) {
                            $found = true;
                            break;
                        }
                    }
                    if ($found == false) {
                        if ($b == false) {
                            $txt .= PHP_EOL . $this->Translate('datapoints not found in data') . PHP_EOL;
                            $b = true;
                        }
                        $txt .= ' - ' . $ident . PHP_EOL;
                    }
                }

                $add_fields = json_decode($this->ReadPropertyString('add_fields'), true);
                foreach ($add_fields as $field) {
                    $ident = $field['ident'];
                    $found = false;
                    foreach ($vars as $var) {
                        if ($ident == $var['varname']) {
                            $found = true;
                            break;
                        }
                    }
                    if ($found == false) {
                        if ($b == false) {
                            $txt .= PHP_EOL . $this->Translate('datapoints not found in data') . PHP_EOL;
                            $b = true;
                        }
                        $txt .= ' - ' . $ident . PHP_EOL;
                    }
                }
            }
            $this->MaintainStatus(IS_ACTIVE);
        }

        $this->PopupMessage($txt);
    }

    private function ShowVars()
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            $msg = $this->GetStatusText();
            $this->PopupMessage($msg);
            return;
        }

        $fieldMap = $this->getFieldMap();
        $vars = $this->ExecuteList('VAR', '');

        if ($vars != false) {
            $txt = $this->Translate('Predefined datapoints') . PHP_EOL;
            foreach ($fieldMap as $map) {
                $ident = $this->GetArrayElem($map, 'ident', '');
                foreach ($vars as $var) {
                    if ($ident == $var['varname']) {
                        $txt .= ' - ' . $var['varname'] . ' = "' . $var['val'] . '"' . PHP_EOL;
                        break;
                    }
                }
            }
            $txt .= PHP_EOL;

            $txt .= $this->Translate('Additional datapoints') . PHP_EOL;
            foreach ($vars as $var) {
                $predef = false;
                foreach ($fieldMap as $map) {
                    $ident = $this->GetArrayElem($map, 'ident', '');
                    if ($ident == $var['varname']) {
                        $predef = true;
                        break;
                    }
                }
                if ($predef) {
                    continue;
                }
                $txt .= ' - ' . $var['varname'] . ' = "' . $var['val'] . '"' . PHP_EOL;
            }
        } else {
            $txt = $this->Translate('Got no datapoints') . PHP_EOL;
        }

        $this->PopupMessage($txt);
    }

    private function UpdateData()
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        $convert_script = $this->ReadPropertyInteger('convert_script');

        $vars = $this->ExecuteList('VAR', '');
        $this->SendDebug(__FUNCTION__, 'data=' . print_r($vars, true), 0);
        if ($vars == false) {
            $this->SetValue('DP_ups_status', self::$NUTC_STATUS_OFF);
            $this->SetValue('DP_ups_status_info', $this->Translate('NUT-service not reachable'));
            $this->MaintainStatus(self::$IS_NOSERVICE);
            return;
        }

        $fieldMap = $this->getFieldMap();
        $this->SendDebug(__FUNCTION__, 'fieldMap="' . print_r($fieldMap, true) . '"', 0);
        $identV = [];
        foreach ($fieldMap as $map) {
            $identV[] = $this->GetArrayElem($map, 'ident', '');
        }
        $identS = implode(',', $identV);
        $this->SendDebug(__FUNCTION__, 'known idents=' . $identS, 0);

        $use_fields = json_decode($this->ReadPropertyString('use_fields'), true);
        $use_fieldsV = [];
        foreach ($use_fields as $field) {
            if ((bool) $this->GetArrayElem($field, 'use', false)) {
                $use_fieldsV[] = $this->GetArrayElem($field, 'ident', '');
            }
        }
        $use_fieldsS = implode(',', $use_fieldsV);
        $this->SendDebug(__FUNCTION__, 'use fields=' . $use_fieldsS, 0);

        foreach ($vars as $var) {
            $ident = $var['varname'];
            $value = $var['val'];

            $vartype = VARIABLETYPE_STRING;
            $varprof = '';
            foreach ($fieldMap as $map) {
                if ($ident == $this->GetArrayElem($map, 'ident', '')) {
                    $vartype = $this->GetArrayElem($map, 'type', '');
                    $varprof = $this->GetArrayElem($map, 'prof', '');
                    break;
                }
            }

            foreach ($use_fields as $field) {
                if ($ident == $this->GetArrayElem($field, 'ident', '')) {
                    $use = (bool) $this->GetArrayElem($field, 'use', false);
                    if (!$use) {
                        $this->SendDebug(__FUNCTION__, 'ignore ident "' . $ident . '", value=' . $value, 0);
                        continue;
                    }

                    if (IPS_ScriptExists($convert_script)) {
                        $vartype = $this->GetArrayElem($field, 'vartype', -1);
                        $info = [
                            'InstanceID'    => $this->InstanceID,
                            'ident'         => $ident,
                            'vartype'       => $vartype,
                            'value'         => $value,
                        ];
                        $r = IPS_RunScriptWaitEx($convert_script, $info);
                        $this->SendDebug(__FUNCTION__, 'convert: ident=' . $ident . ', orgval=' . $value . ', value=' . ($r == false ? '<nop>' : $r), 0);
                        if ($r != false) {
                            $value = $r;
                        }
                    }

                    $ident = 'DP_' . str_replace('.', '_', $ident);
                    if ($ident == 'DP_ups_status') {
                        $this->decodeStatus($value, $code, $info);
                        $this->SendDebug(__FUNCTION__, 'use ident "' . $ident . '", value=' . $value . ' => ' . $code, 0);
                        $this->SetValue($ident, $code);
                        $ident .= '_info';
                        $this->SendDebug(__FUNCTION__, 'use ident "' . $ident . '", value=' . $value . ' => ' . $info, 0);
                        $this->SetValue($ident, $info);
                    } else {
                        $this->SendDebug(__FUNCTION__, 'use ident "' . $ident . '", value=' . $value, 0);
                        switch ($vartype) {
                            case VARIABLETYPE_INTEGER:
                                $this->SetValue($ident, intval($value));
                                break;
                            case VARIABLETYPE_FLOAT:
                                $this->SetValue($ident, floatval($value));
                                break;
                            default:
                                $this->SetValue($ident, $value);
                                break;
                        }
                    }
                    break;
                }
            }
        }

        foreach ($use_fields as $field) {
            $use = (bool) $this->GetArrayElem($field, 'use', false);
            if (!$use) {
                continue;
            }
            $ident = $this->GetArrayElem($field, 'ident', '');
            $found = false;
            foreach ($vars as $var) {
                if ($ident == $var['varname']) {
                    $found = true;
                    break;
                }
            }
            if ($found == false) {
                $this->SendDebug(__FUNCTION__, 'configured ident "' . $ident . '" not found in receviced data', 0);
            }
        }

        $add_fields = json_decode($this->ReadPropertyString('add_fields'), true);
        foreach ($vars as $var) {
            $ident = $var['varname'];
            $value = $var['val'];
            foreach ($add_fields as $field) {
                if ($field['ident'] != $ident) {
                    continue;
                }

                if ($convert_script > 0) {
                    $vartype = $this->GetArrayElem($field, 'vartype', -1);
                    $info = [
                        'InstanceID'    => $this->InstanceID,
                        'ident'         => $ident,
                        'vartype'       => $vartype,
                        'value'         => $value,
                    ];
                    $r = IPS_RunScriptWaitEx($convert_script, $info);
                    $this->SendDebug(__FUNCTION__, 'convert: ident=' . $ident . ', orgval=' . $value . ', value=' . ($r == false ? '<nop>' : $r), 0);
                    if ($r != false) {
                        $value = $r;
                    }
                }

                switch ($vartype) {
                    case VARIABLETYPE_BOOLEAN:
                        $this->SetValue($ident, boolval($value));
                        break;
                    case VARIABLETYPE_INTEGER:
                        $this->SetValue($ident, intval($value));
                        break;
                    case VARIABLETYPE_FLOAT:
                        $this->SetValue($ident, floatval($value));
                        break;
                    default:
                        $this->SetValue($ident, $value);
                        break;
                }

                $ident = 'DP_' . str_replace('.', '_', $ident);
                $this->SendDebug(__FUNCTION__, 'use ident "' . $ident . '", value=' . $value, 0);
                $this->SetValue($ident, $value);
            }
        }

        $this->SetValue('LastUpdate', time());

        $model = '';
        $serial = '';
        foreach ($vars as $var) {
            if ($var['varname'] == 'ups.model') {
                $model = $var['val'];
            }
            if ($var['varname'] == 'ups.serial') {
                $serial = $var['val'];
            }
        }

        $info = $model . ' (#' . $serial . ')';
        $this->SetSummary($info);
        $this->MaintainStatus(IS_ACTIVE);
    }

    private function doCommunication($fp, $cmd, $args, &$lines)
    {
        $query = $cmd;
        if ($args != '') {
            $query .= ' ' . $args;
        }
        $query .= "\n";
        $this->SendDebug(__FUNCTION__, 'query=' . $query, 0);
        if (fwrite($fp, $query) == false) {
            $this->SendDebug(__FUNCTION__, 'fwrite() failed', 0);
            fclose($fp);
            return false;
        }

        $finished = false;
        $timed_out = false;
        $lines = [];
        while (!feof($fp)) {
            $line = fgets($fp, 1024);
            $info = stream_get_meta_data($fp);
            if ($info['timed_out']) {
                $timed_out = true;
                break;
            }
            $line = str_replace("\n", '', $line);

            switch ($cmd) {
            case 'LIST':
                $lines[] = $line;
                if (preg_match('/^END LIST/', $line)) {
                    $finished = true;
                }
                break;
            case 'GET':
            case 'SET':
            case 'INSTCMD':
            case 'LOGIN':
            case 'LOGOUT':
            case 'USERNAME':
            case 'PASSWORD':
            case 'HELP':
            case 'VER':
                $lines[] = $line;
                $finished = true;
                break;
            default:
                $lines[] = $line;
            }
            if ($finished) {
                break;
            }
        }
        $info = stream_get_meta_data($fp);
        if ($info['timed_out']) {
            $timed_out = true;
        }

        return $timed_out;
    }

    private function performQuery(string $cmd, string $args)
    {
        $hostname = $this->ReadPropertyString('hostname');
        $port = $this->ReadPropertyInteger('port');

        $fp = false;
        for ($i = 0; $i <= 5 && !$fp; $i++) {
            $fp = @fsockopen($hostname, $port, $errno, $errstr, 5);
            if ($fp) {
                break;
            }
            $this->SendDebug(__FUNCTION__, 'fsockopen(' . $hostname . ',' . $port . ') failed: error=' . $errstr . '(' . $errno . ') #' . $i, 0);
            IPS_Sleep(250);
        }
        if (!$fp) {
            $this->SendDebug(__FUNCTION__, 'fsockopen(' . $hostname . ',' . $port . ') failed: error=' . $errstr . '(' . $errno . ')', 0);
            $use_fields = json_decode($this->ReadPropertyString('use_fields'), true);
            foreach ($use_fields as $field) {
                if ($this->GetArrayElem($field, 'ident', '') == 'DP_ups_status') {
                    $this->SetValue('DP_ups_status', self::$NUTC_STATUS_UNKNOWN);
                    $this->SetValue('DP_ups_status_info', $this->Translate('unable to connect NUT-server'));
                }
            }
            $this->SetValue('LastUpdate', time());
            return false;
        }
        stream_set_timeout($fp, 2);

        $user = $this->ReadPropertyString('user');
        $password = $this->ReadPropertyString('password');
        if ($user != '' && $password != '') {
            $this->SendDebug(__FUNCTION__, 'user=' . $user . ', password=' . $password, 0);

            $timed_out = $this->doCommunication($fp, 'USERNAME', $user, $lines);
            if ($timed_out) {
                $this->SendDebug(__FUNCTION__, 'socket: timeout', 0);
                fclose($fp);
                return false;
            }
            $err = $this->extractError($lines);
            if ($err != false) {
                $this->SendDebug(__FUNCTION__, 'got error ' . $err, 0);
                fclose($fp);
                return false;
            }
            $timed_out = $this->doCommunication($fp, 'PASSWORD', $password, $lines);
            if ($timed_out) {
                $this->SendDebug(__FUNCTION__, 'socket: timeout', 0);
                fclose($fp);
                return false;
            }
            $err = $this->extractError($lines);
            if ($err != false) {
                $this->SendDebug(__FUNCTION__, 'got error ' . $err, 0);
                fclose($fp);
                return false;
            }
        }

        $lines = [];
        $timed_out = $this->doCommunication($fp, $cmd, $args, $lines);

        fclose($fp);
        if ($timed_out) {
            $this->SendDebug(__FUNCTION__, 'socket: timeout', 0);
            return false;
        }
        if ($lines == []) {
            $this->SendDebug(__FUNCTION__, 'got no lines', 0);
            return false;
        }
        $this->SendDebug(__FUNCTION__, 'received ' . count($lines) . ' lines => ' . print_r($lines, true), 0);

        return $lines;
    }

    private function extractError($lines)
    {
        if (count($lines) > 0 && preg_match('/^ERR (.*)$/', $lines[0], $r)) {
            return $r[1];
        }
        return false;
    }

    private function checkOK($lines)
    {
        if (count($lines) > 0 && $lines[0] == 'OK') {
            return true;
        }
        return false;
    }

    public function ExecuteList(string $subcmd, string $varname)
    {
        $this->SendDebug(__FUNCTION__, 'subcmd=' . $subcmd . ', varname=' . $varname, 0);
        switch ($subcmd) {
            case 'VAR':
            case 'CMD':
            case 'RW':
            case 'CLIENT':
                $upsname = $this->ReadPropertyString('upsname');
                if ($upsname == '') {
                    $this->SendDebug(__FUNCTION__, 'missing name for subcmd ' . $subcmd, 0);
                    return false;
                }
                $query = $subcmd . ' ' . $upsname;
                break;
            case 'ENUM':
            case 'RANGE':
                $upsname = $this->ReadPropertyString('upsname');
                if ($upsname == '') {
                    $this->SendDebug(__FUNCTION__, 'missing name for subcmd ' . $subcmd, 0);
                    return false;
                }
                $query = $subcmd . ' ' . $upsname . ' ' . $varname;
                break;
            default:
                $query = $subcmd;
                break;
        }

        $lines = $this->performQuery('LIST', $query);
        if ($lines == false) {
            return false;
        }
        $arr = [];
        foreach ($lines as $line) {
            if (preg_match('/^BEGIN /', $line)) {
                continue;
            }
            if (preg_match('/^END /', $line)) {
                continue;
            }
            switch ($subcmd) {
                case 'UPS':
                    if (preg_match('/^[^ ]* ([^ ]*) (.*)$/', $line, $r)) {
                        $arr[] = ['id' => $r[1], 'desc' => $r[2]];
                    }
                    break;
                case 'VAR':
                case 'RW':
                    if (preg_match('/^[^ ]* ([^ ]*) ([^ ]*) "([^"]*)"$/', $line, $r)) {
                        $arr[] = ['varname' => $r[2], 'val' => rtrim($r[3])];
                    }
                    break;
                case 'CMD':
                    if (preg_match('/^[^ ]* ([^ ]*) (.*)$/', $line, $r)) {
                        $arr[] = ['cmd' => $r[2]];
                    }
                    break;
                case 'CLIENT':
                    if (preg_match('/^[^ ]* ([^ ]*) (.*)$/', $line, $r)) {
                        $arr[] = ['id' => $r[1], 'ip' => $r[2]];
                    }
                    break;
                case 'RANGE':
                    if (preg_match('/^[^ ]* ([^ ]*) ([^ ]*) "([^"]*)" "([^"]*)"$/', $line, $r)) {
                        $arr[] = ['varname' => $r[2], 'min' => rtrim($r[3]), 'max' => rtrim($r[4])];
                    }
                    break;
                case 'ENUM':
                    if (preg_match('/^[^ ]* ([^ ]*) ([^ ]*) "([^"]*)"$/', $line, $r)) {
                        $arr[] = ['varname' => $r[2], 'val' => rtrim($r[3])];
                    }
                    break;
                default:
                    break;
            }
        }
        return $arr;
    }

    public function ExecuteGet(string $subcmd, string $varname)
    {
        $this->SendDebug(__FUNCTION__, 'subcmd=' . $subcmd . ', varname=' . $varname, 0);
        switch ($subcmd) {
            case 'UPSDESC':
            case 'NUMLOGINS':
                $upsname = $this->ReadPropertyString('upsname');
                if ($upsname == '') {
                    $this->SendDebug(__FUNCTION__, 'missing name for subcmd ' . $subcmd, 0);
                    return false;
                }
                $query = $subcmd . ' ' . $upsname;
                break;
            case 'VAR':
            case 'TYPE':
            case 'DESC':
            case 'CMDDESC':
                $upsname = $this->ReadPropertyString('upsname');
                if ($upsname == '') {
                    $this->SendDebug(__FUNCTION__, 'missing name for subcmd ' . $subcmd, 0);
                    return false;
                }
                $query = $subcmd . ' ' . $upsname . ' ' . $varname;
                break;
            default:
                $query = $subcmd . ' ' . $varname;
                break;
        }

        $lines = $this->performQuery('GET', $query);
        if ($lines == false || count($lines) == 0) {
            return false;
        }
        $line = $lines[0];
        $elem = [];
        switch ($subcmd) {
            case 'VAR':
                if (preg_match('/^[^ ]* ([^ ]*) ([^ ]*) "([^"]*)"$/', $line, $r)) {
                    $elem = ['varname' => $r[2], 'val' => rtrim($r[3])];
                }
                break;
            case 'TYPE':
                if (preg_match('/^[^ ]* ([^ ]*) ([^ ]*) (.*)$/', $line, $r)) {
                    $elem = ['varname' => $r[2], 'type' => $r[3]];
                }
                break;
            case 'DESC':
                if (preg_match('/^[^ ]* ([^ ]*) ([^ ]*) "([^"]*)"$/', $line, $r)) {
                    $elem = ['varname' => $r[2], 'desc' => rtrim($r[3])];
                }
                break;
            case 'UPSDESC':
                if (preg_match('/^[^ ]* ([^ ]*) "([^"]*)"$/', $line, $r)) {
                    $elem = ['id' => $r[1], 'desc' => rtrim($r[2])];
                }
                break;
            case 'CMDDESC':
                if (preg_match('/^[^ ]* ([^ ]*) ([^ ]*) "([^"]*)"$/', $line, $r)) {
                    $elem = ['cmd' => $r[2], 'desc' => rtrim($r[3])];
                }
                break;
            case 'NUMLOGINS':
                if (preg_match('/^[^ ]* ([^ ]*) (.*)$/', $line, $r)) {
                    $elem = ['id' => $r[1], 'num' => $r[2]];
                }
                break;
            default:
                break;
        }
        return $elem;
    }

    public function ExecuteSet(string $varname, string $value)
    {
        $this->SendDebug(__FUNCTION__, 'varname=' . $varname . ', value=' . $value, 0);

        $upsname = $this->ReadPropertyString('upsname');
        if ($upsname == '') {
            $this->SendDebug(__FUNCTION__, 'missing name', 0);
            return false;
        }
        $lines = $this->performQuery('SET', 'VAR ' . $upsname . ' ' . $varname . ' "' . $value . '"');
        $err = $this->extractError($lines);
        if ($err != false) {
            $this->SendDebug(__FUNCTION__, 'got error ' . $err, 0);
            return false;
        }
        return true;
    }

    public function ExecuteCmd(string $cmdname)
    {
        $this->SendDebug(__FUNCTION__, 'cmdname=' . $cmdname, 0);

        $upsname = $this->ReadPropertyString('upsname');
        if ($upsname == '') {
            $this->SendDebug(__FUNCTION__, 'missing name', 0);
            return false;
        }
        $lines = $this->performQuery('INSTCMD', $upsname . ' ' . $cmdname);
        $err = $this->extractError($lines);
        if ($err != false) {
            $this->SendDebug(__FUNCTION__, 'got error ' . $err, 0);
            return false;
        }
        return true;
    }

    public function ExecuteHelp()
    {
        $this->SendDebug(__FUNCTION__, '', 0);
        $lines = $this->performQuery('HELP', '');
        if ($lines == false || count($lines) == 0) {
            return false;
        }
        return $lines[0];
    }

    public function ExecuteVersion()
    {
        $this->SendDebug(__FUNCTION__, '', 0);
        $lines = $this->performQuery('VER', '');
        if ($lines == false || count($lines) == 0) {
            return false;
        }
        return $lines[0];
    }

    public function ExecuteLogin()
    {
        $this->SendDebug(__FUNCTION__, '', 0);

        $upsname = $this->ReadPropertyString('upsname');
        if ($upsname == '') {
            $this->SendDebug(__FUNCTION__, 'missing name', 0);
            return false;
        }
        $lines = $this->performQuery('LOGIN', $upsname);
        $err = $this->extractError($lines);
        if ($err != false) {
            $this->SendDebug(__FUNCTION__, 'got error ' . $err, 0);
            return false;
        }
        return true;
    }

    public function ExecuteLogout()
    {
        $this->SendDebug(__FUNCTION__, '', 0);

        $lines = $this->performQuery('LOGOUT', '');
        $err = $this->extractError($lines);
        if ($err != false) {
            $this->SendDebug(__FUNCTION__, 'got error ' . $err, 0);
            return false;
        }
        return true;
    }

    private function decodeStatus($tags, &$code, &$info)
    {
        $maps = [
            [
                'tag'   => 'RB',
                'code'  => self::$NUTC_STATUS_RB,
                'info'  => $this->Translate('battery needs replacement')
            ],
            [
                'tag'   => 'OVER',
                'code'  => self::$NUTC_STATUS_OVER,
                'info'  => $this->Translate('overloaded')
            ],

            [
                'tag'   => 'FSD',
                'code'  => self::$NUTC_STATUS_FSD,
                'info'  => $this->Translate('forced shutdown')
            ],

            [
                'tag'   => 'LB',
                'code'  => self::$NUTC_STATUS_LB,
                'info'  => $this->Translate('low battery')
            ],
            [
                'tag'   => 'HB',
                'code'  => self::$NUTC_STATUS_HB,
                'info'  => $this->Translate('high battery')
            ],
            [
                'tag'   => 'OFF',
                'code'  => self::$NUTC_STATUS_OFF,
                'info'  => $this->Translate('offline')
            ],
            [
                'tag'   => 'OL',
                'code'  => self::$NUTC_STATUS_OL,
                'info'  => $this->Translate('on line')
            ],
            [
                'tag'   => 'OB',
                'code'  => self::$NUTC_STATUS_OB,
                'info'  => $this->Translate('on battery')
            ],

            [
                'tag'   => 'CHRG',
                'code'  => self::$NUTC_STATUS_CHRG,
                'info'  => $this->Translate('battery is charging')
            ],
            [
                'tag'   => 'DISCHRG',
                'code'  => self::$NUTC_STATUS_DISCHRG,
                'info'  => $this->Translate('battery is discharging')
            ],
            [
                'tag'   => 'BYPASS',
                'code'  => self::$NUTC_STATUS_BYPASS,
                'info'  => $this->Translate('bypass circuit activated')
            ],
            [
                'tag'   => 'CAL',
                'code'  => self::$NUTC_STATUS_CAL,
                'info'  => $this->Translate('is calibrating')
            ],
            [
                'tag'   => 'TRIM',
                'code'  => self::$NUTC_STATUS_TRIM,
                'info'  => $this->Translate('trimming incoming voltage')
            ],
            [
                'tag'   => 'BOOST',
                'code'  => self::$NUTC_STATUS_BOOST,
                'info'  => $this->Translate('boosting incoming voltage')
            ],
        ];

        $code = self::$NUTC_STATUS_OFF;
        $info = '';

        $tagV = explode(' ', $tags);
        $infoV = [];
        foreach ($maps as $map) {
            if (in_array($map['tag'], $tagV)) {
                $code = $map['code'];
                break;
            }
        }
        foreach ($maps as $map) {
            if ($map['code'] == $code) {
                continue;
            }
            if (in_array($map['tag'], $tagV)) {
                $infoV[] = $map['info'];
            }
        }
        foreach ($tagV as $tag) {
            $found = false;
            foreach ($maps as $map) {
                if ($map['tag'] == $tag) {
                    $found = true;
                    break;
                }
            }
            if ($found == false) {
                $infoV[] = $tag;
            }
        }
        $info = implode(', ', $infoV);

        $this->SendDebug(__FUNCTION__, 'tags=' . $tags . ' => code=' . $code . ', info=' . $info, 0);
    }

    private function getFieldMap()
    {
        $map = [
            [
                'ident'  => 'ups.status',
                'desc'   => 'Status',
                'type'   => VARIABLETYPE_INTEGER,
                'prof'   => 'NUTC.Status',
            ],
            [
                'ident'  => 'ups.alarm',
                'desc'   => 'Alarm',
                'type'   => VARIABLETYPE_STRING,
            ],
            [
                'ident'  => 'ups.mfr',
                'desc'   => 'Manufacturer',
                'type'   => VARIABLETYPE_STRING,
            ],
            [
                'ident'  => 'ups.model',
                'desc'   => 'Model',
                'type'   => VARIABLETYPE_STRING,
            ],
            [
                'ident'  => 'ups.serial',
                'desc'   => 'Serialnumber',
                'type'   => VARIABLETYPE_STRING,
            ],
            [
                'ident'  => 'ups.load',
                'desc'   => 'Load',
                'type'   => VARIABLETYPE_FLOAT,
                'prof'   => 'NUTC.Percent',
            ],
            [
                'ident'  => 'ups.realpower.nominal',
                'desc'   => 'Nominal value of real power',
                'type'   => VARIABLETYPE_FLOAT,
                'prof'   => 'NUTC.Power',
            ],

            [
                'ident'  => 'battery.charge',
                'desc'   => 'Battery charge',
                'type'   => VARIABLETYPE_FLOAT,
                'prof'   => 'NUTC.Percent',
            ],
            [
                'ident'  => 'battery.voltage',
                'desc'   => 'Battery voltage',
                'type'   => VARIABLETYPE_FLOAT,
                'prof'   => 'NUTC.Voltage',
            ],
            [
                'ident'  => 'battery.current',
                'desc'   => 'Battery current',
                'type'   => VARIABLETYPE_FLOAT,
                'prof'   => 'NUTC.Current',
            ],
            [
                'ident'  => 'battery.capacity',
                'desc'   => 'Battery capacity',
                'type'   => VARIABLETYPE_FLOAT,
                'prof'   => 'NUTC.Capacity',
            ],
            [
                'ident'  => 'battery.temperature',
                'desc'   => 'Battery temperature',
                'type'   => VARIABLETYPE_FLOAT,
                'prof'   => 'NUTC.Temperature',
            ],
            [
                'ident'  => 'battery.runtime',
                'desc'   => 'Battery runtime',
                'type'   => VARIABLETYPE_INTEGER,
                'prof'   => 'NUTC.sec',
            ],

            [
                'ident'  => 'input.voltage',
                'desc'   => 'Input voltage',
                'type'   => VARIABLETYPE_FLOAT,
                'prof'   => 'NUTC.Voltage',
            ],
            [
                'ident'  => 'input.current',
                'desc'   => 'Input current',
                'type'   => VARIABLETYPE_FLOAT,
                'prof'   => 'NUTC.Current',
            ],
            [
                'ident'  => 'input.realpower',
                'desc'   => 'Input real power',
                'type'   => VARIABLETYPE_FLOAT,
                'prof'   => 'NUTC.Power',
            ],
            [
                'ident'  => 'input.frequency',
                'desc'   => 'Input frequency',
                'type'   => VARIABLETYPE_INTEGER,
                'prof'   => 'NUTC.Frequency',
            ],

            [
                'ident'  => 'output.voltage',
                'desc'   => 'Output voltage',
                'type'   => VARIABLETYPE_FLOAT,
                'prof'   => 'NUTC.Voltage',
            ],
            [
                'ident'  => 'output.current',
                'desc'   => 'Output current',
                'type'   => VARIABLETYPE_FLOAT,
                'prof'   => 'NUTC.Current',
            ],
            [
                'ident'  => 'output.frequency',
                'desc'   => 'Output frequency',
                'type'   => VARIABLETYPE_INTEGER,
                'prof'   => 'NUTC.Frequency',
            ],
        ];

        return $map;
    }
}
