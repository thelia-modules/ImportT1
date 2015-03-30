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

use Thelia\Model\CategoryDocument;

class CategoryDocumentImport extends AbstractMediaImport
{

    protected function getMediaModelInstance($t2_object_id)
    {
        $obj = new CategoryDocument();

        return $obj->setCategoryId($t2_object_id);
    }

    protected function getMediaList($t1_object_id)
    {
        return $this->t1db->query_list("select * from document where rubrique = ? order by classement asc", array($t1_object_id));
    }

    protected function getMediaDesc($t1_object_id)
    {
        return $this->t1db->query_list("select * from documentdesc where document = ?", array($t1_object_id));
    }

    public function importMedia($id_rubrique, $id_category)
    {
        return parent::doImportMedia($id_rubrique, $id_category, "document", "documents" . DS . "category");
    }
}
