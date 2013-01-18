<?php

require_once t3lib_extMgm::extPath('templavoila_files').'provider/class.abstract.php';

/**
 * Provider to copy templavoila datastructure to files and link
 * them with the template objects
 *
 * @package t3build
 * @author Christian Opitz <co@netzelf.de>
 */
class tx_templavoilafiles_provider_tvfds extends tx_templavoilafiles_provider_abstract
{
    /**
     * If the tmplobj records should be updated after export
     * (set datastructure to file)
     * @arg
     * @var boolean
     */
    protected $update = true;

    /**
     * If the datastructure records should be deleted after export
     * @arg
     * @var boolean
     */
    protected $delete = true;

    protected $updateColumns = array(
        'tx_templavoila_tmplobj' => 'datastructure',
        'pages' => array('tx_templavoila_ds', 'tx_templavoila_next_ds'),
        'tt_content' => 'tx_templavoila_ds'
    );

    public function tvfdsAction()
    {
        $rows = $this->getRows('tx_templavoila_datastructure');
        
        if (!count($rows)) {
            $this->_die('No records found for pid '.$this->pid);
        }
        
        $map = $this->export($rows, 'dataprot');
        
        foreach ($map as $uid => $file) {
            if ($this->update) {
                foreach ($this->updateColumns as $table => $columns) {
                    foreach ((array) $columns as $column) {
                        $this->_debug('Updating column '.$column.' on table '.$table);
                        $res = $this->db->exec_UPDATEquery(
                        	$table,
                        	$column.'='.$uid,
                            array($column => $this->extRelPath.$file)
                        );
                        if (!$res) {
                            $this->_die('Could not update '.$table.'.'.$column.' for ds '.$uid);
                        }
                    }
                }
            }
            if ($this->delete) {
                $this->db->exec_UPDATEquery(
                	'tx_templavoila_datastructure',
                	'uid='.$uid,
                    array('deleted' => 1)
                );
            }
        }


        $conf = array('enable' => 1);
        foreach (array('page', 'fce') as $scope) {
            $file = $this->getPath($this->path, array('scope' => $scope, 'title' => 'foo'), $this->renameMode);
            $conf['path_'.$scope] = $this->extRelPath.dirname($file).'/';
        }

        $this->writeExtConf('templavoila', array('staticDS.' => $conf));
    }
}