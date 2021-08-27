<?php

namespace Bredala\Template;

use InvalidArgumentException;

class View
{
    private string $file;
    private array $data;

    /**
     * @param string $file
     */
    public function __construct(string $file, array $data = [])
    {
        $this->file = $file;
        $this->data = $data;
    }

    /**
     * @param string $file
     * @param array $bag
     * @return self
     */
    public static function create(string $file, array $data = []): self
    {
        return new static($file, $data);
    }

    /**
     * @param string $file
     * @param array $data
     * @return self
     */
    public function include(string $file, array $data = []): self
    {
        return new static($file, $data + $this->data);
    }

    // -------------------------------------------------------------------------

    /**
     * @param string $name
     * @param mixed $value
     * @return self
     */
    public function set(string $name, $value): self
    {
        $this->data[$name] = $value;

        return $this;
    }

    /**
     * @param string $name
     * @param mixed $value
     * @return self
     */
    public function add(string $name, $value): self
    {
        if (!isset($this->data[$name])) {
            $this->data[$name] = [];
        } elseif (!is_array($this->data[$name])) {
            return $this;
        }

        if (!is_array($value)) {
            $value = [$value];
        }

        foreach ($value as $v) {
            $this->data[$name][] = $v;
        }

        return $this;
    }

    /**
     * @return string
     */
    public function load(): string
    {
        if (!$this->file || !is_file($this->file)) {
            throw new InvalidArgumentException("File not found {$this->file}");
        }

        if ($this->data) {
            extract($this->data);
        }

        ob_start();
        include $this->file;
        return ob_get_clean() ?: '';
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->load();
    }
}
