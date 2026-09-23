<?php

use Bredala\Template\BagTrait;
use PHPUnit\Framework\TestCase;

class BagTestSubject
{
    use BagTrait;
}

class BagTest extends TestCase
{
    private function bag(): BagTestSubject
    {
        return new BagTestSubject();
    }

    public function testStartsEmpty()
    {
        self::assertSame([], $this->bag()->export());
    }

    public function testEveryMutatorIsFluent()
    {
        $bag = $this->bag();

        self::assertSame($bag, $bag->import([]));
        self::assertSame($bag, $bag->set('a', 1));
        self::assertSame($bag, $bag->add('b', 1));
    }

    public function testSetThenExport()
    {
        self::assertSame(['a' => 1], $this->bag()->set('a', 1)->export());
    }

    public function testSetOverwrites()
    {
        self::assertSame(['a' => 2], $this->bag()->set('a', 1)->set('a', 2)->export());
    }

    public function testSetAcceptsAnyValue()
    {
        self::assertSame(['a' => null], $this->bag()->set('a', null)->export());
    }

    public function testImportReplacesTheWholeBag()
    {
        // Not a merge: everything set before is discarded.
        $bag = $this->bag()->set('a', 1)->import(['b' => 2]);

        self::assertSame(['b' => 2], $bag->export());
    }

    public function testImportWithAnEmptyArrayClearsTheBag()
    {
        self::assertSame([], $this->bag()->set('a', 1)->import([])->export());
    }

    public function testAddCreatesAListOnAnUnsetKey()
    {
        self::assertSame(['a' => [1]], $this->bag()->add('a', 1)->export());
    }

    public function testAddAppendsToAnExistingList()
    {
        self::assertSame(['a' => [1, 2]], $this->bag()->add('a', 1)->add('a', 2)->export());
    }

    public function testAddAppendsToAnArraySetBySet()
    {
        self::assertSame(['a' => [1, 2]], $this->bag()->set('a', [1])->add('a', 2)->export());
    }

    public function testAddPreservesTheKeysOfAnAssociativeArray()
    {
        self::assertSame(
            ['a' => ['k' => 1, 0 => 2]],
            $this->bag()->set('a', ['k' => 1])->add('a', 2)->export()
        );
    }

    /**
     * @dataProvider scalarProvider
     */
    public function testAddOnANonArrayKeyIsASilentNoop(mixed $value)
    {
        // No exception, no conversion, no append: the value is simply left alone.
        $bag = $this->bag()->set('a', $value)->add('a', 'ignored');

        self::assertSame(['a' => $value], $bag->export());
    }

    public static function scalarProvider(): array
    {
        return [
            'string' => ['x'],
            'int' => [1],
            'zero' => [0],
            'false' => [false],
        ];
    }

    public function testAddOnANullKeyTreatsItAsUnset()
    {
        // The guard is '$this->data[$name] ?? []', and ?? treats a stored null as
        // absent -- so add() replaces the null with a list instead of skipping.
        $bag = $this->bag()->set('a', null)->add('a', 'appended');

        self::assertSame(['a' => ['appended']], $bag->export());
    }

    public function testExportReturnsACopy()
    {
        $bag = $this->bag()->set('a', 1);
        $export = $bag->export();
        $export['b'] = 2;

        self::assertSame(['a' => 1], $bag->export());
    }
}
