<?php
foreach (array('tvfds', 'tvfto') as $provider) {
    $TYPO3_CONF_VARS['EXTCONF']['t3build']['providers'][$provider] =
    'EXT:templavoila_files/provider/class.'.$provider.'.php:tx_templavoilafiles_provider_'.$provider;
}