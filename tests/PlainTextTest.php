<?php

use Bredala\Template\PlainText;
use PHPUnit\Framework\TestCase;

class PlainTextTest extends TestCase
{
    /**
     * PlainText prints straight to stdout, so every call has to be buffered.
     */
    private function capture(callable $callback): string
    {
        ob_start();

        try {
            $callback();
        } finally {
            return ob_get_clean();
        }
    }

    public function testGetInstanceIsASingleton()
    {
        self::assertSame(PlainText::getInstance(), PlainText::getInstance());
    }

    public function testAddPrintsTheTextAndReturnsTheSingleton()
    {
        $returned = null;

        $output = $this->capture(function () use (&$returned) {
            $returned = PlainText::add('hello');
        });

        self::assertSame('hello', $output);
        self::assertSame(PlainText::getInstance(), $returned);
    }

    public function testAddWithArgumentsFormatsLikeVprintf()
    {
        self::assertSame(
            'Tom is 30',
            $this->capture(fn() => PlainText::add('%s is %d', 'Tom', 30))
        );
    }

    public function testAddWithoutArgumentsDoesNotInterpretPlaceholders()
    {
        // The branch is '$items ? vprintf(...) : print(...)', so a lone '%' is safe
        // as long as no arguments are passed.
        self::assertSame('100% done', $this->capture(fn() => PlainText::add('100% done')));
    }

    public function testAddWithArgumentsInterpretsPercentSigns()
    {
        self::assertSame('50%', $this->capture(fn() => PlainText::add('%d%%', 50)));
    }

    public function testAddAnEmptyString()
    {
        self::assertSame('', $this->capture(fn() => PlainText::add('')));
    }

    /**
     * @dataProvider repeaterProvider
     */
    public function testRepeaters(string $method, int $times, string $expected)
    {
        self::assertSame($expected, $this->capture(fn() => PlainText::{$method}($times)));
    }

    public static function repeaterProvider(): array
    {
        return [
            'one eol' => ['eol', 1, "\n"],
            'three eol' => ['eol', 3, "\n\n\n"],
            'one tab' => ['tab', 1, "\t"],
            'two tabs' => ['tab', 2, "\t\t"],
            'one space' => ['space', 1, ' '],
            'four spaces' => ['space', 4, '    '],
        ];
    }

    /**
     * @dataProvider nonPositiveProvider
     */
    public function testANonPositiveCountPrintsNothing(int $times)
    {
        self::assertSame('', $this->capture(fn() => PlainText::eol($times)));
    }

    public static function nonPositiveProvider(): array
    {
        return ['zero' => [0], 'negative' => [-3]];
    }

    public function testRepeat()
    {
        self::assertSame('-----', $this->capture(fn() => PlainText::repeat('-', 5)));
    }

    public function testRepeatOnceReturnsTheStringItself()
    {
        self::assertSame('ab', $this->capture(fn() => PlainText::repeat('ab')));
    }

    public function testCallsCanBeChained()
    {
        self::assertSame(
            "Title\n\t- item\n",
            $this->capture(function () {
                PlainText::add('Title')->eol()->tab()->add('- item')->eol();
            })
        );
    }

    public function testChainingWorksOnTheInstanceToo()
    {
        // The methods are static but return the singleton, so '->' calls resolve to
        // the same static methods.
        self::assertSame(
            'ab',
            $this->capture(fn() => PlainText::getInstance()->add('a')->add('b'))
        );
    }

    public function testASinglePercentIsMangledOnceArgumentsArePassed()
    {
        // vprintf() reads '% d' as a space-flagged integer conversion: 'one' casts
        // to 0, so '100% done' becomes '100' . '0' . 'one'. An unescaped literal '%'
        // is destroyed as soon as add() receives arguments.
        self::assertSame(
            '1000one',
            $this->capture(fn() => PlainText::add('100% done', 'one'))
        );
    }
}
