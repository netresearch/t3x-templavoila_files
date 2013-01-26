<?php
require_once t3lib_extMgm::extPath('templavoila_files').'provider/class.abstract.php';
require_once t3lib_extMgm::extPath('templavoila').'classes/class.tx_templavoila_datastructureRepository.php';

/**
 * Provider to sync templavoila template object records with files.
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

    /**
     * Import files to database only
     * @arg
     * @var boolean
     */
    protected $importOnly = false;

    /**
     * Export records to files only
     * @arg
     * @var boolean
     */
    protected $exportOnly = false;

    /**
     * Record to file syncronization - see inline comments
     * to find out how it works
     */
    public function syncAction()
    {
        // First collect all records within pid recursively
        $rows = $this->getRows('tx_templavoila_tmplobj');

        // Scope is being detected
        /* @var $dsRepo tx_templavoila_datastructureRepository */
        $dsRepo = t3lib_div::makeInstance('tx_templavoila_datastructureRepository');
        foreach ($rows as $i => $row) {
            try {
                $ds = $dsRepo->getDatastructureByUidOrFilename($row['datastructure']);
                $rows[$i]['scope'] = (string) $ds->getScope();
            } catch (Exception $e) {
                $this->_echo(
                	'Warning: Could not find datastructure - skipping record '.$row['uid']."\n".
                    '('.(is_numeric($row['datastructure']) ? 'Record' : 'File').
                    ' '.$row['datastructure'].' missing)'
                );
                unset($rows[$i]);
            }
        }

        // Write records to files - the file contents are created in renderMapping
        // When files already exist doOverwrite() is invoked for each file
        // to decide if it should be overriden:
        // When nothing changed the file stays untouched
        // When the record is newer than the file, the file will be updated
        // When the file is newer than the record, the record will be updated
        // When they both have the same tstamp, a conflict will be reported
        $this->recordsToFiles($rows, array($this, 'renderMapping'));

        // This determines the directory where the files are and
        // creates records for those files which are not already
        // in the DB (updates of existing ones happen above)
        $this->filesToRecords($rows);
    }

    /**
     * Render the contents of the file from the row
     *
     * @param array $row
     * @return string|boolean
     */
    protected function renderMapping($row)
    {
        if ($this->importOnly) {
            // Don't write to the file
            return false;
        }

        $mapping = unserialize($row['templatemapping']);
        $scope = $row['scope'] === '1' ? 'page' : 'fce';
        $checksum = md5($row['templatemapping']);
        $user = (array) $this->db->exec_SELECTgetSingleRow('username', 'be_users', 'uid='.$row['cruser_id']);

        $removeColumns = array('uid', 'pid', 'templatemapping', 't3_origuid', 'scope', 'cruser_id');
        foreach ($row as $column => $value) {
            if (substr($column, 0, 6) == 't3ver_' || in_array($column, $removeColumns)) {
                unset($row[$column]);
            }
        }

        $templateInfo = array(
            'version' => '1.0.0',
            'meta' => array(
                'exportTime' => time(),
                'mappingChecksum' => $checksum,
                'cruserName' => $user['username'],
                'scope' => $scope,
                'host' => $_SERVER['COMPUTERNAME'],
        		'user' => $_SERVER['USERNAME'],
                'path' => $this->getRootline($row['pid'])
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

    /**
     * Limited replacement for native var_export because it creates
     * bad coding style
     *
     * @param array $array
     * @param string $indention
     * @param int $level
     * @return string
     */
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

        // First find out if something changed
        $dbProperties = array();
        $fileProperties = array();
        foreach ($templateInfo['record'] as $key => $value) {
            if ($row[$key] != $value) {
                $fileProperties[$key] = $value;
                $dbProperties[$key] = $row[$key];
            }
        }
        $mapping = $templateInfo['meta']['mappingChecksum'] != md5($row['templatemapping']);

        if (!count($dbProperties) && !$mapping) {
            // Nothing changed - leave file untouched
            return false;
        }

        // Decide which one to override - file or record:
        $exportTime = $templateInfo['record']['tstamp'];
        $recordTime = (int) $row['tstamp'];

        $forceExport = $this->exportOnly;
        $forceImport = $this->importOnly;

        if ($recordTime == $exportTime) {
            // We have a conflict:
            $this->_echo('Detected conflict on '.$path);
            if (count($dbProperties)) {
                $this->_echo('=> Conflicting properties:');
                $this->_echo('File properties:');
                var_dump($fileProperties);
                $this->_echo('Record properties:');
                var_dump($dbProperties);
            }
            if ($mapping) {
                $this->_echo('=> Conflicting mapping');
            }
            // Decide what to do:
            $res = $this->_input(
            	'Import file (f), export record (r) or skip (s)?',
                array('f', 'r', 's'),
                's' // Skip by default
            );
            if ($res == 's') {
                $this->_echo('Skipping');
                return false;
            }
            $forceImport = $res == 'f';
            $forceExport = $res == 'r';
        }

        if ($forceExport || $recordTime > $exportTime) {
            // Record is newer - update the file
            return true;
        } elseif ($forceImport || $recordTime < $exportTime) {
            // File is newer - update the record
            $this->updateRecord($row['uid'], $templateInfo);
            $this->_echo('Updated record '.$row['uid'].' from '.$path);
            return false;
        }
    }

    /**
     * Finds the files within $this->path and imports them when
     * they are not already in the database
     *
     * @param array $rows
     */
    protected function filesToRecords($rows)
    {
        if ($this->exportOnly) {
            return;
        }

        foreach (array('page', 'fce', '') as $scope) {
            // Create a path for a fake file to search there
            // for other files
            $file = $this->extPath.$this->getPath(
                $this->path,
                array(
                	'scope' => $scope,
                    'title' => 'noop',
                    'path' => ''
                ),
                $this->renameMode
            );
            $pathinfo = pathinfo($file);
            $found = true;
            try {
                $directory = new RecursiveDirectoryIterator($pathinfo['dirname']);
            } catch (Exception $e) {
                $found = false;
            }
            if ($found) {
                $found = false;
                $iterator = new RecursiveIteratorIterator($directory);
                $regex = new RegexIterator($iterator, '/^.+\.'.$pathinfo['extension'].'$/');
                foreach ($regex as $file) {
                    $found = true;
                    $path = realpath((string) $file);
                    if (!in_array($path, $this->recordFileMap)) {
                        $uid = $this->insertRecord($path);
                        $this->_echo('Created record '.$uid.' from '.$path);
                    }
                }
            }
            if (!$found) {
                $this->_echo('No files found for '.$scope.' scope in '.$pathinfo['dirname']);
            }
        }
    }

    /**
     * Update a record with the columns from $templateInfo
     *
     * @param int $uid
     * @param array $templateInfo
     */
    protected function updateRecord($uid, $templateInfo)
    {
        $record = $templateInfo['record'];
        $record['templatemapping'] = serialize($templateInfo['mapping']);
        $res = $this->db->exec_UPDATEquery('tx_templavoila_tmplobj', 'uid='.$uid, $record);
        if (!$res) {
            $this->_die('Could not update record');
        }
    }

    /**
     * Insert a new record from a info file - also creates the
     * parent sys folders that where between the record and the
     * pid provided when the file was exported
     *
     * @param string $path
     * @return int The uid of the new record
     */
    protected function insertRecord($path)
    {
        /* @var $tce t3lib_TCEmain */
        $tce = t3lib_div::makeInstance('t3lib_TCEmain');
        $templateInfo = $this->readTemplateInfo($path);

        // Find the pid of the folder inside $pid if any
        // and create any intermediate folders if missing
        $pid = $this->pid;
        $rootline = $templateInfo['meta']['path'];
        foreach ($rootline as $title) {
            $page = $this->db->exec_SELECTgetSingleRow('uid', 'pages', "pid=$pid AND title='$title'");
            if ($page) {
                $pid = $page['uid'];
                continue;
            }
            $data = array(
            	'pages' => array(
                    'NEW' => array(
                        'pid' => $pid,
                        'title' => $title
                    )
                )
            );
            $tce->start($data, array());
            $tce->process_datamap();
            $pid = $tce->substNEWwithIDs['NEW'];
            $this->_echo("Created missing page '$title' ($pid)");
        }

        // Insert the new record
        $row = $templateInfo['record'];
        unset($row['tstamp'], $row['crdate']);
        $row['pid'] = $pid;
        $row['templatemapping'] = serialize($templateInfo['mapping']);
        $data = array(
            'tx_templavoila_tmplobj' => array(
                'NEW' => $row
            )
        );

        $this->recordFileMap[$tce->substNEWwithIDs['NEW']] = $path;

        return $tce->substNEWwithIDs['NEW'];
    }

    /**
     * Read the template info from a file
     *
     * @param string $path
     * @return array
     */
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