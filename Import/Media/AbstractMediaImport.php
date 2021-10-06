<?php
/*************************************************************************************/
/*                                                                                   */
/*      Thelia 1 Database Importation Tool                                           */
/*                                                                                   */
/*      Copyright (c) CQFDev                                                         */
/*      email : contact@cqfdev.fr                                                    */
/*      web : http://www.cqfdev.fr                                                   */
/*                                                                                   */
/*      This program is free software; you can redistribute it and/or modify         */
/*      it under the terms of the GNU General Public License as published by         */
/*      the Free Software Foundation; either version 3 of the License                */
/*                                                                                   */
/*      This program is distributed in the hope that it will be useful,              */
/*      but WITHOUT ANY WARRANTY; without even the implied warranty of               */
/*      MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the                */
/*      GNU General Public License for more details.                                 */
/*                                                                                   */
/*      You should have received a copy of the GNU General Public License            */
/*	    along with this program. If not, see <http://www.gnu.org/licenses/>.         */
/*                                                                                   */
/*************************************************************************************/

namespace ImportT1\Import\Media;

use ImportT1\Import\BaseImport;
use Symfony\Component\Filesystem\Filesystem;
use Thelia\Log\Tlog;

abstract class AbstractMediaImport extends BaseImport
{

    abstract protected function getMediaModelInstance($t2_object_id);

    abstract protected function getMediaList($t1_object_id);

    abstract protected function getMediaDesc($t1_object_id);

    public function getTotalCount()
    {
        // Not used.
        return false;
    }
    public function import($startRecord = 0)
    {
        // Not used.
        return false;
    }

    public function doImportMedia($t1_object_id, $t2_object_id, $client_path, $local_path)
    {
        if ($this->t1db->hasClientPath($this->session)) {
            $fs = new Filesystem();

            $list = $this->getMediaList($t1_object_id);

            foreach ($list as $item) {

                $src_path = $this->t1db->getClientPath($this->session) . DS . $client_path . DS . $item->fichier;

                if ($fs->exists($src_path)) {

                    $dst_path = THELIA_LOCAL_DIR . "media" . DS . $local_path . DS . $item->fichier;

                    $fs->copy($src_path, $dst_path, true);

                    $descs = $this->getMediaDesc($item->id);

                    $obj = $this->getMediaModelInstance($t2_object_id)
                        ->setFile($item->fichier)
                        ->setPosition($item->classement);

                    foreach ($descs as $desc) {
                        $lang = $this->getT2Lang($desc->lang);

                        $obj
                            ->setLocale($lang->getLocale())
                            ->setTitle($desc->titre)
                            ->setChapo($desc->chapo)
                            ->setDescription($desc->description)
                            ->setPostscriptum('') // Missing in T1
                        ;
                    }

                    $obj->save();
                } else {
                    Tlog::getInstance()->addWarning("Failed to find media file $src_path");
                }
            }
        }
    }
}
