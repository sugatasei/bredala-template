<?php

use Bredala\Template\HelperTrait;
use PHPUnit\Framework\TestCase;

class HelperTestSubject
{
    use HelperTrait;
}

class HelperTest extends TestCase
{
    // -------------------------------------------------------------------------
    // tag / openTag / closeTag
    // -------------------------------------------------------------------------

    public function testTagWrapsItsContentAndEndsWithANewline()
    {
        self::assertSame("<p>body</p>\n", HelperTestSubject::tag('p', null, 'body'));
    }

    public function testTagWithoutContentIsStillAPair()
    {
        self::assertSame("<p></p>\n", HelperTestSubject::tag('p'));
    }

    public function testTagRendersAttributes()
    {
        self::assertSame(
            "<a href=\"/x\" class=\"btn\">go</a>\n",
            HelperTestSubject::tag('a', ['href' => '/x', 'class' => 'btn'], 'go')
        );
    }

    /**
     * @dataProvider autocloseProvider
     */
    public function testAutocloseTagsAreSelfClosingAndHaveNoNewline(string $tag)
    {
        self::assertSame("<{$tag} />", HelperTestSubject::tag($tag));
    }

    public static function autocloseProvider(): array
    {
        return array_map(
            fn(string $tag) => [$tag],
            array_combine(
                ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'keygen', 'link', 'meta', 'param', 'source', 'track', 'wbr'],
                ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'keygen', 'link', 'meta', 'param', 'source', 'track', 'wbr']
            )
        );
    }

    public function testAutocloseTagIgnoresItsContent()
    {
        self::assertSame('<br />', HelperTestSubject::tag('br', null, 'dropped'));
    }

    public function testAnUnknownTagIsTreatedAsAPair()
    {
        self::assertSame("<my-widget>x</my-widget>\n", HelperTestSubject::tag('my-widget', null, 'x'));
    }

    public function testTagDoesNotEscapeItsContent()
    {
        // Only attributes go through xss(); content is inserted verbatim, so escape
        // it yourself with xss() when it comes from user input.
        self::assertSame("<p><b>raw</b></p>\n", HelperTestSubject::tag('p', null, '<b>raw</b>'));
    }

    public function testOpenTag()
    {
        self::assertSame('<div class="x">', HelperTestSubject::openTag('div', ['class' => 'x']));
    }

    public function testOpenTagOfAnAutocloseTagIsSelfClosing()
    {
        self::assertSame('<br />', HelperTestSubject::openTag('br'));
    }

    public function testCloseTag()
    {
        self::assertSame("</div>\n", HelperTestSubject::closeTag('div'));
    }

    public function testCloseTagOfAnAutocloseTagIsEmpty()
    {
        self::assertSame('', HelperTestSubject::closeTag('br'));
    }

    // -------------------------------------------------------------------------
    // attrToString
    // -------------------------------------------------------------------------

    public function testAttrToStringPrefixesEachAttributeWithASpace()
    {
        self::assertSame(' a="1" b="2"', HelperTestSubject::attrToString(['a' => 1, 'b' => 2]));
    }

    public function testAttrToStringOnAnEmptyArray()
    {
        self::assertSame('', HelperTestSubject::attrToString([]));
    }

    public function testTrueRendersABareAttribute()
    {
        self::assertSame(' disabled', HelperTestSubject::attrToString(['disabled' => true]));
    }

    public function testFalseOmitsTheAttributeEntirely()
    {
        self::assertSame('', HelperTestSubject::attrToString(['disabled' => false]));
    }

    public function testAttributeValuesAreEscaped()
    {
        self::assertSame(
            ' title="a &quot;b&quot; &amp; c"',
            HelperTestSubject::attrToString(['title' => 'a "b" & c'])
        );
    }

    public function testNullAttributeValueRendersAnEmptyString()
    {
        self::assertSame(' data-x=""', HelperTestSubject::attrToString(['data-x' => null]));
    }

    public function testAttributeNamesAreNotEscaped()
    {
        // Never build attribute names from user input.
        self::assertSame(' on"x="1"', HelperTestSubject::attrToString(['on"x' => 1]));
    }

    // -------------------------------------------------------------------------
    // xss
    // -------------------------------------------------------------------------

    /**
     * @dataProvider xssProvider
     */
    public function testXss(mixed $input, string $expected)
    {
        self::assertSame($expected, HelperTestSubject::xss($input));
    }

    public static function xssProvider(): array
    {
        return [
            'null' => [null, ''],
            'empty string' => ['', ''],
            'plain' => ['abc', 'abc'],
            'tags' => ['<b>x</b>', '&lt;b&gt;x&lt;/b&gt;'],
            'double quote' => ['"', '&quot;'],
            'single quote' => ["'", '&apos;'],
            'ampersand' => ['&', '&amp;'],
            'int' => [42, '42'],
            'zero' => [0, '0'],
            'float' => [1.5, '1.5'],
            'numeric string' => ['42', '42'],
            'true' => [true, '1'],
            'false' => [false, '0'],
            'array' => [['a' => 1], '{&quot;a&quot;:1}'],
        ];
    }

    public function testXssLeavesNumericStringsUntouched()
    {
        // is_numeric() short-circuits before escaping, so a numeric string is cast
        // rather than encoded -- harmless, but it means the branch order matters.
        self::assertSame('1e3', HelperTestSubject::xss('1e3'));
    }

    public function testXssEncodesAnObjectAsJson()
    {
        $object = new stdClass();
        $object->a = '<b>';

        self::assertSame('{&quot;a&quot;:&quot;&lt;b&gt;&quot;}', HelperTestSubject::xss($object));
    }

    // -------------------------------------------------------------------------
    // meta
    // -------------------------------------------------------------------------

    public function testMetaBuildsMetaTags()
    {
        self::assertSame(
            '<meta name="description" content="x" />',
            HelperTestSubject::meta([['name' => 'description', 'content' => 'x']])
        );
    }

    public function testMetaBuildsALinkTagWhenTheEntryHasARel()
    {
        self::assertSame(
            '<link rel="canonical" href="/x" />',
            HelperTestSubject::meta([['rel' => 'canonical', 'href' => '/x']])
        );
    }

    public function testMetaConcatenatesEveryEntry()
    {
        self::assertSame(
            '<meta name="a" /><link rel="b" />',
            HelperTestSubject::meta([['name' => 'a'], ['rel' => 'b']])
        );
    }

    public function testMetaOnAnEmptyListReturnsAnEmptyString()
    {
        self::assertSame('', HelperTestSubject::meta([]));
    }

    // -------------------------------------------------------------------------
    // scripts / styles / jsVars
    // -------------------------------------------------------------------------

    public function testScripts()
    {
        self::assertSame(
            "<script src=\"/a.js\"></script>\n<script src=\"/b.js\"></script>\n",
            HelperTestSubject::scripts(['/a.js', '/b.js'])
        );
    }

    public function testScriptsAsModules()
    {
        self::assertSame(
            "<script type=\"module\" src=\"/a.js\"></script>\n",
            HelperTestSubject::scripts(['/a.js'], true)
        );
    }

    public function testScriptsOnAnEmptyListReturnsAnEmptyString()
    {
        self::assertSame('', HelperTestSubject::scripts([]));
    }

    public function testStyles()
    {
        self::assertSame(
            '<link type="text/css" rel="stylesheet" href="/a.css" />',
            HelperTestSubject::styles(['/a.css'])
        );
    }

    public function testStylesOnAnEmptyListReturnsAnEmptyString()
    {
        self::assertSame('', HelperTestSubject::styles([]));
    }

    public function testJsVars()
    {
        self::assertSame(
            "<script>const APP = {\"a\":1};</script>\n",
            HelperTestSubject::jsVars('APP', ['a' => 1])
        );
    }

    public function testJsVarsEscapesSingleQuotes()
    {
        // JSON_HEX_APOS keeps the value safe inside a single-quoted HTML attribute.
        self::assertSame(
            "<script>const A = \"it\\u0027s\";</script>\n",
            HelperTestSubject::jsVars('A', "it's")
        );
    }

    public function testJsVarsIsSafeAgainstAnEarlyScriptClose()
    {
        // json_encode() escapes '/' as '\/' by default, so a '</script>' inside a
        // value cannot close the element early.
        $html = HelperTestSubject::jsVars('A', '</script>');

        self::assertSame("<script>const A = \"<\\/script>\";</script>\n", $html);
        self::assertStringNotContainsString('</script>";', $html);
    }

    public function testJsVarsWithAScalar()
    {
        self::assertSame("<script>const N = 42;</script>\n", HelperTestSubject::jsVars('N', 42));
    }

    public function testJsVarsWithNull()
    {
        self::assertSame("<script>const N = null;</script>\n", HelperTestSubject::jsVars('N', null));
    }

    public function testAStringFalseIsRenderedAsAValue()
    {
        // Only real booleans take the bare/omitted path. For disabled/checked this
        // means the string 'false' actually ENABLES the attribute in HTML.
        self::assertSame(' disabled="false"', HelperTestSubject::attrToString(['disabled' => 'false']));
    }

    public function testVoidElementListDoesNotCoverEveryHtml5VoidTag()
    {
        // 'wbr' is in the list, but a custom element is not and comes out as a pair.
        self::assertSame('<wbr />', HelperTestSubject::tag('wbr'));
        self::assertSame("<my-tag></my-tag>\n", HelperTestSubject::tag('my-tag'));
    }

    public function testJsVarsEmitsAConstDeclaration()
    {
        self::assertStringStartsWith('<script>const ', HelperTestSubject::jsVars('A', 1));
    }
}
