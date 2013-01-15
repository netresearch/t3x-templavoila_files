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
    
    public function exportAction()
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
        ob_start();
        var_export($mapping);
        return ob_get_clean();
    }
}