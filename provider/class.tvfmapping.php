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
    protected $varName = 'templateObject';

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
        foreach ($row as $column => $value) {
            if (substr($column, 0, 6) == 't3ver_') {
                unset($row[$column]);
            }
        }
        unset($row['templatemapping'], $row['t3_origuid']);
        $row['templatemapping'] = $mapping;

        $indention = $this->csSpaces ? str_repeat(' ', $this->csSpaces) : "\t";
        $file = '<?'."php\n";
        $file .= '$'.$this->varName.' = ';
        $file .= $this->varExport($row, $indention);
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
                    $line .= $this->varExport($value, $indention, $level + 1);
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
}