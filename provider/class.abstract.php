<?php
abstract class tx_templavoilafiles_provider_abstract extends tx_t3build_provider_abstract
{
    /**
     * The extension to which the files should be written
     * @arg
     * @var string
     */
    protected $extKey = 'template';

    /**
     * Handle only records with this pid
     * @arg
     * @required
     * @var int
     */
    protected $pid = null;

    /**
     * The mask of the path within the extension the files will be
     * exported to. Following variables are available:
     * ${scope} fce or page
     * ${title} title of the ds record
     * ${path}  rootline between pid and record (empty when records
     *          are directly inside the folder with the specified pid)
     *
     * Add a feature request/patch at forge.typo3.org if you need more
     *
     * @arg
     * @var string
     */
    protected $path = '###TYPE###/${path}/${scope}/${title}.###EXTENSION###';

    /**
     * Whether to include deleted records
     * @arg
     * @var boolean
     */
    protected $includeDeletedRecords = false;

    /**
     * Renaming mode: 'camelCase', 'CamelCase' or 'underscore'
     * @arg
     * @var string
     */
    protected $renameMode = 'camelCase';

    /**
     * @var t3lib_DB
     */
    protected $db;

    protected $extPath;

    protected $extRelPath;

    protected $extension = 'xml';

    protected $rootlines = array();

    protected $recordFileMap = array();

    public function init($args)
    {
        if (!t3lib_extMgm::isLoaded('templavoila')) {
            $this->_die('templavoila is not loaded');
        }
        $this->db = $GLOBALS['TYPO3_DB'];

        preg_match('/_tvf([a-z]+)$/', get_class($this), $match);
        $this->path = str_replace('###TYPE###', $match[1], $this->path);
        $this->path = str_replace('###EXTENSION###', $this->extension, $this->path);

        parent::init($args);

        if ($this->dryRun) {
            $this->debug = true;
        }

        $this->extPath = PATH_typo3conf.'ext/'.$this->extKey.'/';
        $this->extRelPath = 'typo3conf/ext/'.$this->extKey.'/';
    }

    protected function getRows($table, $pid = null, array $rootline = array())
    {
        if (!$pid) {
            $pid = $this->pid;
        }

        $where = 'pid = '.$pid;
        $where .= ($this->includeDeletedRecords ? '' : ' AND deleted = 0');

        $rows = $this->db->exec_SELECTgetRows('*', $table, $where, '', '', '', 'uid');
        $pages = $this->db->exec_SELECTgetRows('uid, title', 'pages', $where.' AND doktype=254');

        foreach ($pages as $page) {
            $tempRootline = $rootline;
            $tempRootline[] = $page['title'];
            $this->rootlines[$page['uid']] = $tempRootline;
            $rows = array_merge($rows, $this->getRows($table, $page['uid'], $tempRootline));
        }

        return $rows;
    }

    protected function getRootline($pid)
    {
        if (!array_key_exists($pid, $this->rootlines)) {
            return array();
        }
        return $this->rootlines[$pid];
    }

    protected function recordsToFiles($rows, $source)
    {
        if (!file_exists($this->extPath)) {
            t3lib_div::mkdir($this->extPath);
        }

        $isCallback = is_callable($source);

        foreach ($rows as $row) {
            $file = $this->getPath(
                $this->path,
                array(
                	'scope' => $row['scope'] === '1' ? 'page' : 'fce',
                    'title' => str_replace(array('/', '\\'), '-', $row['title']),
                    'path' => implode('/', $this->getRootline($row['pid']))
                ),
                $this->renameMode
            );
            $path = $this->extPath.$file;
            if (!file_exists(dirname($path))) {
                if (t3lib_div::mkdir_deep($this->extPath, dirname($file))) {
                    $this->_die('Could not make directory '.$path);
                }
            }

            $exists = file_exists($path);
            if (!$exists || $this->doOverwrite($path, $row)) {
                $content = $isCallback ? call_user_func($source, $row) : $row[$source];
                if ($content !== false) {
                    file_put_contents($path, $content);
                    $this->_echo(($exists ? 'Updated' : 'Created').' '.$path.' for record '.$row['uid']);
                }
            }

            $this->recordFileMap[$row['uid']] = realpath($path);
        }
    }

    /**
     * Whether to override the file with $path for $row
     *
     * @param string $path
     * @param array $row
     */
    abstract protected function doOverwrite($path, $row);
}