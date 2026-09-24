<?php

use Bredala\Template\View;
use PHPUnit\Framework\TestCase;

class ViewTest extends TestCase
{
    private function fixture(string $name): string
    {
        return __DIR__ . '/fixtures/' . $name;
    }

    private function view(string $name, array $data = []): View
    {
        return View::create($this->fixture($name), $data);
    }

    // -------------------------------------------------------------------------
    // Construction
    // -------------------------------------------------------------------------

    public function testCreateIsEquivalentToNew()
    {
        self::assertInstanceOf(View::class, $this->view('static.phtml'));
        self::assertInstanceOf(Stringable::class, $this->view('static.phtml'));
    }

    public function testConstructorImportsData()
    {
        self::assertSame(['name' => 'Tom'], $this->view('static.phtml', ['name' => 'Tom'])->export());
    }

    public function testAMissingFileIsOnlyDetectedAtLoad()
    {
        $view = $this->view('nope.phtml');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('File not found');

        $view->load();
    }

    public function testAnEmptyFilenameIsRejected()
    {
        $this->expectException(InvalidArgumentException::class);

        View::create('')->load();
    }

    public function testADirectoryIsRejected()
    {
        $this->expectException(InvalidArgumentException::class);

        View::create(__DIR__ . '/fixtures')->load();
    }

    // -------------------------------------------------------------------------
    // load
    // -------------------------------------------------------------------------

    public function testLoadReturnsTheRenderedTemplate()
    {
        self::assertSame("static content\n", $this->view('static.phtml')->load());
    }

    public function testDataIsExtractedIntoTheTemplateScope()
    {
        self::assertSame("Hello Tom!\n", $this->view('hello.phtml', ['name' => 'Tom'])->load());
    }

    public function testSeveralVariablesAreExtracted()
    {
        self::assertSame('1|2', $this->view('vars.phtml', ['a' => 1, 'b' => 2])->load());
    }

    public function testToStringRendersTheTemplate()
    {
        self::assertSame("static content\n", (string) $this->view('static.phtml'));
    }

    public function testLoadCanBeCalledSeveralTimes()
    {
        $view = $this->view('hello.phtml', ['name' => 'Tom']);

        self::assertSame($view->load(), $view->load());
    }

    public function testThisIsNotAvailableInsideTheTemplate()
    {
        // load() includes the template from a static closure.
        self::assertSame('no this', $this->view('this.phtml')->load());
    }

    public function testHelpersAreReachableAsStaticInsideTheTemplate()
    {
        // A static closure keeps the class scope, so static:: is the View.
        self::assertSame("<p class=\"x\">body</p>\n", $this->view('helper.phtml')->load());
    }

    public function testOnlyTheDataKeysAreDefinedInsideTheTemplate()
    {
        self::assertSame('a,b', $this->view('bag.phtml', ['a' => 1, 'b' => 2])->load());
    }

    public function testAnEmptyTemplateRendersAnEmptyString()
    {
        self::assertSame('', $this->view('empty.phtml')->load());
    }

    public function testATemplateOutputtingZeroRendersZero()
    {
        self::assertSame('0', $this->view('zero.phtml')->load());
    }

    public function testAMissingVariableIsAWarningNotAnError()
    {
        $warnings = [];
        set_error_handler(function (int $severity, string $message) use (&$warnings) {
            $warnings[] = $message;
            return true;
        }, E_WARNING);

        try {
            $output = $this->view('hello.phtml')->load();
        } finally {
            restore_error_handler();
        }

        self::assertSame("Hello !\n", $output);
        self::assertNotEmpty($warnings);
    }

    public function testAnExceptionInsideATemplateClosesTheOutputBuffer()
    {
        $level = ob_get_level();

        try {
            $this->view('throws.phtml')->load();
            self::fail('Expected a RuntimeException');
        } catch (RuntimeException $ex) {
            self::assertSame('boom', $ex->getMessage());
        }

        self::assertSame($level, ob_get_level());
    }

    // -------------------------------------------------------------------------
    // current
    // -------------------------------------------------------------------------

    public function testCurrentReturnsTheViewBeingRendered()
    {
        self::assertSame('outer()outer', $this->view('current.phtml', ['name' => 'outer'])->load());
    }

    public function testCurrentFollowsNestedRenders()
    {
        $view = $this->view('current.phtml', ['name' => 'outer', 'inner' => true]);

        self::assertSame('outer(inner()inner)outer', $view->load());
    }

    public function testSetThroughCurrentDoesNotChangeTheRunningTemplate()
    {
        $view = $this->view('current-set.phtml', ['name' => 'Tom']);

        self::assertSame('Tom', $view->load());
        self::assertSame(['name' => 'changed'], $view->export());
    }

    public function testCurrentThrowsOutsideARender()
    {
        $this->expectException(LogicException::class);

        View::current();
    }

    public function testCurrentIsResetWhenATemplateThrows()
    {
        try {
            $this->view('throws.phtml')->load();
        } catch (RuntimeException) {
        }

        $this->expectException(LogicException::class);

        View::current();
    }

    // -------------------------------------------------------------------------
    // include
    // -------------------------------------------------------------------------

    public function testIncludeReturnsANewViewAndInheritsTheParentData()
    {
        $parent = $this->view('parent.phtml', ['title' => 'T']);
        $child = $parent->include($this->fixture('child.phtml'));

        self::assertInstanceOf(View::class, $child);
        self::assertNotSame($parent, $child);
        self::assertSame('child:T', $child->load());
    }

    public function testAPartialInheritsTheTemplateVariablesThroughGetDefinedVars()
    {
        self::assertSame("[T|child:T]\n", $this->view('parent.phtml', ['title' => 'T'])->load());
    }

    public function testIncludeDataWinsOverInheritedData()
    {
        // The merge is '$data + $this->export()', so the parent's values are only a
        // fallback for keys the child does not supply.
        $child = $this->view('child.phtml', ['title' => 'parent'])
            ->include($this->fixture('child.phtml'), ['title' => 'child']);

        self::assertSame('child:child', $child->load());
    }

    public function testIncludeDoesNotMutateTheParent()
    {
        $parent = $this->view('child.phtml', ['title' => 'parent']);
        $parent->include($this->fixture('child.phtml'), ['title' => 'child']);

        self::assertSame(['title' => 'parent'], $parent->export());
    }

    public function testADataKeyNamedThisIsIgnored()
    {
        // load() extracts with EXTR_SKIP, so the key "this" never becomes a variable.
        self::assertSame('no this', $this->view('this.phtml', ['this' => 'boom'])->load());
    }
}
