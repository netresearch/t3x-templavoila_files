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
     * @var int
     */
    protected $pid = null;

    /**
     * Handle only the record with this uid
     * @arg
     * @var int
     */
    protected $uid = null;

    /**
     * The mask of the path within the extension the files will be
     * exported to. Following variables are available:
     * ${scope} fce or page
     * ${title} title of the ds record
     *
     * Add a feature request/patch at forge.typo3.org if you need more
     *
     * @arg
     * @var string
     */
    protected $path = '###TYPE###/${scope}/${title}.###EXTENSION###';

    /**
     * Whether to include deleted ds
     * @arg
     * @var boolean
     */
    protected $includeDeletedRecords = false;

    /**
     * If existing files should be overwritten
     * @arg
     * @var boolean
     */
    protected $overwrite = false;

    /**
     * Renaming mode: 'camelCase', 'CamelCase' or 'under_scored'
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
        
        $this->extPath = PATH_typo3conf.'ext/'.$this->extKey.'/';
        $this->extRelPath = 'typo3conf/ext/'.$this->extKey.'/';
    }
    
    protected function getRows($table)
    {
        if (!$this->pid && !$this->uid) {
            $this->_die('You need to provide either a pid or an uid');
        }
        
        $parts = array();
        $criteria = array();
        foreach (array('pid', 'uid') as $type) {
            if ($this->$type) {
                $parts[] = $table.'.'.$type.'='.$this->$type;
                $criteria[] = $type.' '.$this->$type;
            }
        }
        
        $where = implode(' AND ', $parts);
        $where .= ($this->includeDeletedRecords ? '' : ' AND '.$table.'.deleted = 0');
        
        $rows = $this->db->exec_SELECTgetRows('*', $table, $where);

        if (!count($rows)) {
            $this->_die('No records found for '.implode(' and ', $criteria));
        }
        
        return $rows;
    }
    
    protected function export($rows, $source)
    {
        if (!file_exists($this->extPath)) {
            t3lib_div::mkdir($this->extPath);
        }
        
        $map = array();
        $isCallback = is_callable($source);
        
        foreach ($rows as $row) {
            $file = $this->getPath($this->path, array(
            	'scope' => $row['scope'] === '1' ? 'page' : 'fce',
                'title' => str_replace(array('/', '\\'), '-', $row['title'])
            ), $this->renameMode);
            $path = $this->extPath.$file;
            if (!file_exists(dirname($path))) {
                if (t3lib_div::mkdir_deep($this->extPath, dirname($file))) {
                    $this->_die('Could not make directory '.$path);
                }
            }
            
            if (!file_exists($path) || $this->overwrite) {
                $content = $isCallback ? call_user_func($source, $row) : $row[$source];
                file_put_contents($path, $content);
                $map[$row['uid']] = $file;
            }            
        }
        
        return $map;
    }
}