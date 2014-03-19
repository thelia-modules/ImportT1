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

namespace ImportT1\Import;

class ImportChunkResult
{
    private $count = 0;
    private $errors = 0;

    public function __construct($count, $errors)
    {
        $this->count = $count;
        $this->errors = $errors;
    }

    /**
     * @return the unknown_type
     */
    public function getCount()
    {
        return $this->count;
    }

    /**
     * @param unknown_type $count
     */
    public function setCount($count)
    {
        $this->count = $count;

        return $this;
    }

    /**
     * @return the unknown_type
     */
    public function getErrors()
    {
        return $this->errors;
        return $this;
    }

    /**
     * @param unknown_type $errors
     */
    public function setErrors($errors)
    {
        $this->errors = $errors;

        return $this;
    }
}
