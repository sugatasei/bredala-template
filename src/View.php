<?php

namespace Bredala\Template;

use InvalidArgumentException;
use LogicException;
use Stringable;

class View implements Stringable
{
    use BagTrait;
    use HelperTrait;

    /** @var View[] views being rendered, innermost last */
    private static array $stack = [];

    private string $file;

    public function __construct(string $file, array $data = [])
    {
        $this->file = $file;
        $this->import($data);
    }

    public static function create(string $file, array $data = []): static
    {
        return new static($file, $data);
    }

    public function include(string $file, array $data = []): static
    {
        return new static($file, $data + $this->export());
    }

    public function load(): string
    {
        if (!$this->file || !is_file($this->file)) {
            throw new InvalidArgumentException("File not found {$this->file}");
        }

        self::$stack[] = $this;
        try {
            return $this->render();
        } finally {
            array_pop(self::$stack);
        }
    }

    /**
     * The view whose template is currently executing.
     *
     * The stack is process-wide, like the output buffer load() relies on: a
     * template must never suspend (Fiber, await) while it renders, or another
     * render would interleave with both. Fetch the data before load().
     *
     * @throws LogicException outside a render
     */
    public static function current(): static
    {
        return end(self::$stack) ?: throw new LogicException('No view is being rendered');
    }

    private function render(): string
    {
        // Static closure: the template gets the data but no $this.
        // func_get_arg() keeps $file and $data out of the template scope.
        $render = static function (): string {
            extract(func_get_arg(1), EXTR_SKIP);
            ob_start();
            try {
                include func_get_arg(0);
                return (string) ob_get_contents();
            } finally {
                ob_end_clean();
            }
        };

        /** @disregard P1119 arguments are read with func_get_arg() */
        return $render($this->file, $this->data);
    }

    public function __toString(): string
    {
        return $this->load();
    }

    // -------------------------------------------------------------------------
}
