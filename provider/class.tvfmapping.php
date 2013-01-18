<?php
require_once t3lib_extMgm::extPath('templavoila_files').'provider/class.abstract.php';
require_once t3lib_extMgm::extPath('templavoila').'classes/class.tx_templavoila_datastructureRepository.php';

/**
 * Provider to export, import or sync templavoila mapping to,
 * from or with files and link them with the template objects.
 *
 * @package t3build
 * @author Christian Opitz <co@netzelf.de>
 */
class tx_templavoilafiles_provider_tvfmapping extends tx_templavoilafiles_provider_abstract
{
    protected $extension = 'php';

    /**
     * Number of spaces to use for indention
     * (use 0 to indent with tabs)
     *
     * @arg
     * @var int
     */
    protected $csSpaces = 4;

    /**
     * The variable name in the files which contain the
     * records
     *
     * @arg
     * @var string
     */
    protected $varName = 'templateInfo';

    public function syncAction()
    {
        /* @var $dsRepo tx_templavoila_datastructureRepository */
        $dsRepo = t3lib_div::makeInstance('tx_templavoila_datastructureRepository');
        $rows = $this->getRows('tx_templavoila_tmplobj');

        foreach ($rows as $row) {
            $ds = $dsRepo->getDatastructureByUidOrFilename($row['datastructure']);
            $row['scope'] = $ds->getScope();
        }

        $this->export($rows, array($this, 'renderMapping'));
    }

    protected function renderMapping($row)
    {
        $mapping = unserialize($row['templatemapping']);
        $scope = $row['scope'];
        foreach ($row as $column => $value) {
            if (substr($column, 0, 6) == 't3ver_') {
                unset($row[$column]);
            }
        }
        unset($row['uid'], $row['templatemapping'], $row['t3_origuid'], $row['scope']);

        $user = (array) $this->db->exec_SELECTgetSingleRow('username', 'be_users', 'uid='.$row['cruser_id']);

        $templateInfo = array(
            'version' => '1.0.0',
            'meta' => array(
                'exportTime' => time(),
                'cruserName' => $user['username'],
                'scope' => $scope,
                'host' => $_SERVER['COMPUTERNAME'],
        		'user' => $_SERVER['USERNAME'],
                'path' => $this->getRootline($row['pid']),
            ),
            'record' => $row,
            'mapping' => $mapping
        );

        $indention = $this->csSpaces ? str_repeat(' ', $this->csSpaces) : "\t";
        $file = '<?'."php\n";
        $file .= '$'.$this->varName.' = ';
        $file .= $this->varExport($templateInfo, $indention);
        $file .= ';';
        return $file;
    }

    protected function varExport($array, $indention, $level = 0)
    {
        $lines = array();
        $pre = str_repeat($indention, $level + 1);
        $nl = "\n";
        $preLength = strlen($pre);
        foreach ($array as $key => $value) {
            $line = $pre;
            $line .= is_numeric($key) ? $key : "'".str_replace("'", "\\'", $key)."'";
            $line .= ' => ';
            switch (true) {
                case is_null($value):
                    $line .= 'null';
                    break;
                case is_bool($value):
                    $line .= $value ? 'true' : 'false';
                    break;
                case is_numeric($value):
                    $line .= $value;
                    break;
                case is_string($value):
                    $line .= "'".str_replace("'", "\\'", $value)."'";
                    break;
                case is_array($value):
                    if (count($value)) {
                        $line .= $this->varExport($value, $indention, $level + 1);
                    } else {
                        $line .= 'array()';
                    }
                    break;
                default:
                    $this->_die('Unsupported type: '.gettype($value));
            }
            $lines[] = $line;
        }
        $res = "array(\n";
        $res .= implode(",\n", $lines);
        $res .= "\n".str_repeat($indention, $level).')';
        return $res;
    }

	/* (non-PHPdoc)
     * @see tx_templavoilafiles_provider_abstract::doOverwrite()
     */
    protected function doOverwrite($path, $row)
    {
        $templateInfo = $this->readTemplateInfo($path);
        $exportTime = $templateInfo['record']['tstamp'];
        $recordTime = (int) $row['tstamp'];
        if ($recordTime > $exportTime) {
            return true;
        } elseif ($recordTime < $exportTime) {
            $this->updateRecord($row['uid'], $templateInfo);
            return false;
        } else {
            $dbProperties = array();
            $fileProperties = array();
            foreach ($templateInfo['record'] as $key => $value) {
                if ($row[$key] != $value) {
                    $fileProperties[$key] = $value;
                    $dbProperties[$key] = $row[$key];
                }
            }
            $mapping = serialize($templateInfo['mapping']) != $row['templatemapping'];
            if (count($dbProperties) || $mapping) {
                $this->_echo('Detected conflict:');
                if (count($dbProperties)) {
                    $this->_echo('=> Conflicting properties:');
                    $this->_echo('File properties:');
                    var_dump($fileProperties);
                    $this->_echo('Database properties:');
                    var_dump($dbProperties);
                }
                if ($mapping) {
                    $this->_echo('=> Conflicting mapping');
                }
                // Todo: Introduce interactive mode and allow
                // to fix the conflict from CLI
                $this->_die('Aborting');
            }
            return false;
        }
    }

    protected function updateRecord($uid, $templateInfo)
    {
        $record = $templateInfo['record'];
        $record['templatemapping'] = serialize($templateInfo['mapping']);
        $res = $this->db->exec_UPDATEquery('tx_templavoila_tmplobj', 'uid='.$uid, $record);
        if (!$res) {
            $this->_die('Could not update record');
        }
    }

    protected function readTemplateInfo($path)
    {
        @include $path;
        if (!isset(${$this->varName})) {
            $this->_die(
            	'Could not read file '.$path.' - either it doesn\'t '.
                'exist or doesn\'t contain the correct variable'
            );
        }
        return ${$this->varName};
    }

}