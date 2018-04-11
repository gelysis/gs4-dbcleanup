<?php
/**
 * @package Gs4Dbcleanup
 * @author gelysis <andreas@gelysis.net>
 * @copyright ©2018, Andreas Gerhards - All rights reserved
 * @license http://opensource.org/licenses/BSD-3-Clause BSD-3-Clause - Please check LICENSE.md for more information
 */

require_once 'src/DbCleanUp.php';
use Gs4Dbcleanup\DbCleanUp;

$cleanUp = new DbCleanUp();
$cleanUp->removeVersionDuplicates();
