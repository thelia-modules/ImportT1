<?php
namespace ImportT1\Import;

class ImportChunkResult
{
    private $count = 0;
    private $errors = 0;

    public function __construct($count, $errors) {
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
