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
    /** @var int */
    private $count = 0;

    /** @var int  */
    private $errors = 0;

    /**
     * ImportChunkResult constructor.
     * @param int $count
     * @param int $errors
     */
    public function __construct($count, $errors)
    {
        $this->count = $count;
        $this->errors = $errors;
    }

    /**
     * @return int
     */
    public function getCount()
    {
        return $this->count;
    }

    /**
     * @param int $count
     * @return $this
     */
    public function setCount($count)
    {
        $this->count = $count;
        return $this;
    }

    /**
     * @return int
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * @param int $errors
     * @return $this
     */
    public function setErrors($errors)
    {
        $this->errors = $errors;
        return $this;
    }


}
