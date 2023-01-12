<?php
namespace Usility\MarkdownPlus;

use Usility\PageFactory\PageFactory as PageFactory;
use Exception;
use Kirby\Exception\InvalidArgumentException;

/*
 * MarkdownPlus extends \cebe\markdown\MarkdownExtra
 * Why not Kirby's native ParsedownExtra?
 *  -> no access to array of lines surrounding current line -> not possible to inject lines
 *  -> required by DivBlock pattern
 */

 // HTML tags that must not have a closing tag:
const MDPMD_SINGLETON_TAGS =   'img,input,br,hr,meta,embed,link,source,track,wbr,col,area';



class MarkdownPlus extends \cebe\markdown\MarkdownExtra
{
    private static int $asciiTableInx   = 1;
    private static int $imageInx        = 0;
    private static int $tabulatorInx    = 1;

    private string $divblockChars;
    private bool $paragraphContext;
    private $inlineTags = ',a,abbr,acronym,b,bdo,big,br,button,cite,code,dfn,em,i,img,input,kbd,label,'.
            'map,object,output,q,samp,script,select,small,span,strong,sub,sup,textarea,time,tt,var,skip,';
    // 'skip' is a pseudo tag used by MarkdownPlus.

    /**
     * @param $kirby
     */
    public function __construct()
    {
        $this->divblockChars = PageFactory::$config['divblock-chars'] ?? '@';
        MdPlusHelper::findAvailableIcons();
    }


    /**
     * Compiles a markdown string to HTML:
     * @param string $str
     * @param bool $omitPWrapperTag
     * @param int $sectionInx       -> handleFrontmatter -> css/scss-> replace #this/.this with section identifier
     * @return string
     * @throws Exception
     */
    public function compile(string $str, bool $omitPWrapperTag = false, string $sectionIdentifier = ''):string
    {
        if (!$str) {
            return '';
        }
        $this->sectionIdentifier = $sectionIdentifier;
        $this->paragraphContext = false;
        $str = $this->preprocess($str);
        $html = parent::parse($str);
        return $this->postprocess($html, $omitPWrapperTag);
    } // compile


    /**
     * Compiles string on non-block level, i.e. like inside a paragraph
     * @param string $str
     * @return string
     * @throws Exception
     */
    public function compileParagraph(string $str, bool $omitPWrapperTag = false):string
    {
        if (!$str) {
            return '';
        }
        $this->paragraphContext = true;
        $str = $this->preprocess($str);
        $html = parent::parseParagraph($str);
        $html = $this->postprocess($html, $omitPWrapperTag);

        return $html;
    } // compile


    /**
     * Compiles a markdown string to HTML without pre- and postprocessing
     * @param string $str
     * @return string
     */
    public function compileStr(string $str): string
    {
        $html = parent::parse($str);
        return $html;
    } // compileStr



    // === AsciiTable ==================
    /**
     * @param string $line
     * @return bool
     */
    protected function identifyAsciiTable(string $line): bool
    {
        // asciiTable starts with '|==='
        if (strncmp($line, '|===', 4) === 0) {
            return true;
        }
        return false;
    }

    /**
     * @param array $lines
     * @param int $current
     * @return array
     */
    protected function consumeAsciiTable(array $lines, int $current): array
    {
        $block = [
            'asciiTable',
            'content' => [],
            'args' => false
        ];
        $firstLine = $lines[$current];
        if (preg_match('/^\|===*\s+(.*)$/', $firstLine, $m)) {
            $block['args'] = $m[1];
        }
        for($i = $current + 1, $count = count($lines); $i < $count; $i++) {
            $line = $lines[$i];
            if (strncmp($line, '|===', 4) !== 0) {
                $block['content'][] = $line;
            } else {
                // stop consuming when second '|===' found
                break;
            }
        }
        return [$block, $i];
    }

    /**
     * @param array $block
     * @return string
     */
    protected function renderAsciiTable(array $block): string
    {
        $table = [];
        $nCols = 0;
        $row = 0;
        $col = -1;

        $inx = self::$asciiTableInx++;

        for ($i = 0; $i < sizeof($block['content']); $i++) {
            $line = $block['content'][$i];

            if (strncmp($line, '|---', 4) === 0) {  // new row
                $row++;
                $col = -1;
                continue;
            }

            if (isset($line[0]) && ($line[0] === '|')) {  // next cell starts
                $line = substr($line,1);
                $cells = preg_split('/\s(?<!\\\)\|/', $line); // pattern is ' |'
                foreach ($cells as $cell) {
                    if ($cell && ($cell[0] === '>')) {
                        $cells2 = explode('|', $cell);
                        foreach ($cells2 as $j => $c) {
                            $col++;
                            $table[$row][$col] = $c;
                        }
                        unset($cells2);
                        unset($c);
                    } else {
                        $col++;
                        $table[$row][$col] = str_replace('\|', '|', $cell);
                    }
                }

            } else {
                if ($col < 0) {
                    throw new Exception("Error in AsciiTable: cell definition needs leading '|'");
                }
                $table[$row][$col] .= "\n$line";
            }
            $nCols = max($nCols, $col);
        }
        $nCols++;
        $nRows = $row+1;
        unset($cells);

        // prepare table attributes:
        $caption = $block['args'];
        if (strpbrk($caption, '#.:=!~^_+') !== false) {
            $attrs = MdPlusHelper::parseInlineBlockArguments($caption);
            if (($attrs['tag'] === 'skip') || ($attrs['lang'] && ($attrs['lang'] !== kirby()->language()->code()))) {
                return '';
            }
            $caption = $attrs['text'];
            $attrsStr = $attrs['htmlAttrs'];
            $attrsStr = preg_replace('/class=["\'].*?["\']/', '', $attrsStr);
            $class = "mdp-table mdp-table-$inx";
            if ($attrs['class']) {
                $class .= ' '.$attrs['class'];
            }
            if (!$attrs['id']) {
                $attrsStr = "id='mdp-table-$inx' ".$attrsStr;
            }
            $attrsStr .= " class='$class'";
        } else {
            $attrsStr = "id='mdp-table-$inx' class='mdp-table mdp-table-$inx'";
        }

        // now render the table:
        $out = "\t<table $attrsStr>\n";
        if ($caption) {
            $caption = trim($caption,'"\'');
            $out .= "\t  <caption>$caption</caption>\n";
        }

        // render header as defined in first row, e.g. |# H1|H2
        $row = 0;
        if (isset($table[0][0]) && ($table[0][0][0] === '#')) {
            $row = 1;
            $table[0][0] = substr($table[0][0],1);
            $out .= "\t  <thead>\n\t    <tr>\n";
            for ($col = 0; $col < $nCols; $col++) {
                $cell = isset($table[0][$col]) ? $table[0][$col] : '';
                $cell = self::compileParagraph($cell, true);
                $out .= "\t\t\t<th class='mdp-col-".($col+1)."'>$cell</th>\n";
            }
            $out .= "\t    </tr>\n\t  </thead>\n";
        }

        $out .= "\t  <tbody>\n";
        for (; $row < $nRows; $row++) {
            $out .= "\t\t<tr>\n";
            $colspan = 1;
            for ($col = 0; $col < $nCols; $col++) {
                $cell = isset($table[$row][$col]) ? $table[$row][$col] : '';
                if ($cell === '>') {    // colspan?  e.g. |>|
                    $colspan++;
                    continue;
                } elseif ($cell) {
                    $cell = self::compile($cell, true);
                }
                $colspanAttr = '';
                if ($colspan > 1) {
                    $colspanAttr = " colspan='$colspan'";
                }
                $out .= "\t\t\t<td class='mdp-row-".($row+1)." mdp-col-".($col+1)."'$colspanAttr>\n\t\t\t\t$cell\t\t\t</td>\n";
                $colspan = 1;
            }
            $out .= "\t\t</tr>\n";
        }

        $out .= "\t  </tbody>\n";
        $out .= "\t</table><!-- /asciiTable -->\n";

        return $out;
    } // AsciiTable




    // === DivBlock ==================
    /**
     * @param string $line
     * @return bool
     */
    protected function identifyDivBlock(string $line): bool
    {
        // if a line starts with at least 3 colons it is identified as a div-block
        // fence chars e.g. ':$@' -> defined in PageFactory::$config['divblock-chars']
        if (preg_match("/^[$this->divblockChars]{3,10}\s+\S/", $line)) {
            return true;
        }
        return false;
    } // identifyDivBlock

    /**
     * @param array $lines
     * @param int $current
     * @return array
     * @throws Exception
     */
    protected function consumeDivBlock(array $lines, int $current): array
    {
        $line = rtrim($lines[$current]);
        // create block array
        $block = [
            'divBlock',
            'content' => [],
            'marker' => $line[0],
            'attributes' => '',
            'literal' => false,
            'mdpBlockType' => true
        ];

        // detect class or id and fence length (can be more than 3 backticks)
        $depth = 0;
        $marker = $block['marker'];
        if (preg_match("/($marker{3,10})(.*)/",$line, $m)) {
            $fence = $m[1];
            $rest = trim($m[2]);
            if ($rest && ($rest[0] === '{')) {      // non-mdp block: e.g. "::: {#id}
                $block['mdpBlockType'] = false;
                $depth = 1;
                $rest = trim(str_replace(['{','}'], '', $rest));
            }
        } else {
            throw new Exception("Error in Markdown source line $current: $line");
        }
        $attrs = MdPlusHelper::parseInlineBlockArguments($rest);

        $tag = $attrs['tag'];
        if (stripos($this->inlineTags, ",$tag,") !== false) {
            if ($attrs['literal'] === null) {
                $attrs['literal'] = true;
            }
        }

        $block['tag'] = $tag ?: (($marker === '%') ? 'span' : '');
        $block['attributes'] = $attrs['htmlAttrs'];
        $block['lang'] = $attrs['lang'];
        $block['literal'] = $attrs['literal'];
        $block['meta'] = '';

        // consume all lines until end-tag, e.g. @@@
        for($i = $current + 1, $count = count($lines); $i < $count; $i++) {
            $line = $lines[$i];
            if (preg_match("/^($marker{3,10})\s*(.*)/", $line, $m)) { // it's a potential fence line
                $fenceEndCandidate = $m[1];
                $rest = $m[2];
                if ($fence === $fenceEndCandidate) {    // end tag we have to consider:
                    if ($rest !== '') {    // case nested or consequitive block
                        if ($block['mdpBlockType']) {   // mdp-style -> consecutive block starts:
                            $i--;
                            break;
                        }
                        $depth++;

                    } else {                    // end of block
                        $depth--;
                        if ($depth < 1) {       // only in case of non-mdpBlocks we may have to skip nested end-tags:
                            break;
                        }
                    }
                }
            }
            $block['content'][] = $line;
        }

        $content = implode("\n", $block['content']);
        unset($block['content']);
        if ($block['literal']) {
            $block['content'][0] = MdPlusHelper::shieldStr($content);

        } elseif ($attrs['inline']){
            $content = $this->compileEmbeddedDivBlock($content);
            $block['content'][0] = self::compileParagraph($content, true);

        } else {
            $block['content'][0] = MdPlusHelper::shieldStr($content, true);
        }
        return [$block, $i];
    } // consumeDivBlock

    /**
     * @param string $str
     * @return string
     */
    private function compileEmbeddedDivBlock(string $str): string
    {
        $lines = explode("\n", $str);
        $block = false;
        $str = '';
        foreach ($lines as $line) {
            if ($block === false) {
                if (preg_match("/([$this->divblockChars]{3,10})(.*)/",$line, $m)) {
                    $fence = $m[1];
                    $l = strlen($fence);
                    $block = '';
                    $attrs = MdPlusHelper::parseInlineBlockArguments($line);
                } else {
                    $str .= "$line\n";
                }
            } else {
                if (substr($line, 0, $l) === $fence) {
                    if ($attrs['inline']??false) {
                        $html = self::compileParagraph($block, true);
                    } else {
                        $html = self::compile($block, true);
                    }
                    $tag = $attrs['tag'] ?: 'div';
                    $_tag = str_contains(MDPMD_SINGLETON_TAGS, $tag)? '': "</$tag>";
                    $str .= "<$tag{$attrs['htmlAttrs']}>\n$html\n$_tag\n";
                    $block = false;
                } else {
                    $block .= $line;
                }
            }
        }
        return $str;
    } // compileEmbeddedDivBlock

    /**
     * @param array $block
     * @return string
     */
    protected function renderDivBlock(array $block): string
    {
        $tag = $block['tag'];
        $attrs = $block['attributes'];

        // exclude blocks with lang option set but is not current language:
        if ($block['lang'] && ($block['lang'] !== kirby()->language()->code())) {
            return '';
        }

        $out = $block['content'][0];

        if (str_contains($block['meta'], 'html')) {
            return $out;
        }

        if (($tag === '') && !$attrs) {
            return $out;
        } else {
            $tag = $tag?: 'div';
            $_tag = str_contains(MDPMD_SINGLETON_TAGS, $tag)? '': "</$tag>";
            return "\n\n<$tag$attrs>\n$out$_tag<!-- $tag$attrs -->\n\n\n";
        }
    } // renderDivBlock




    // === Tabulator ==================
    /**
     * @param string $line
     * @return bool
     */
    protected function identifyTabulator(string $line): bool
    {
        if (preg_match('/(\s\s|\t) ([.\d]{1,3}\w{1,2})? >> [\s\t]/x', $line)) { // identify patterns like '{{ tab( 7em ) }}'
            return true;
        }
        return false;
    } // identifyTabulator

    /**
     * @param array $lines
     * @param int $current
     * @return array
     */
    protected function consumeTabulator(array $lines, int $current): array
    {
        $block = [
            'tabulator',
            'content' => [],
            'widths' => [],
        ];

        $last = $current;
        $nEmptyLines = 0;
        // consume following lines containing >>
        for($i = $current, $count = count($lines); $i <= $count-1; $i++) {
            if (!preg_match('/\S/', $lines[$i])) {  // empty line
                if ($nEmptyLines++ > 0) {
                    break;
                }
            } else {
                $nEmptyLines = 0;
            }
            $line = $lines[$i];
            if (preg_match('/([.\d]{1,3}\w{1,2})? >> [\s\t]/x', $line)) {
                $block['content'][] = $line;

                preg_match_all('/([.\d]{1,3}\w{1,2})? >> [\s\t]/x', $line, $m);
                foreach ($m[1] as $j => $width) {
                    if ($width) {
                        $block['widths'][$j] = $width;
                    } elseif (isset($block['widths'][$j])) {
                        $block['widths'][$j] = '6em';
                    }
                }
                $last = $i;
            } elseif (empty($line)) {
                continue;
            } else {
                break;
            }
        }
        return [$block, $last];
    } // consumeTabulator

    /**
     * @param array $block
     * @return string
     */
    protected function renderTabulator(array $block): string
    {
        $inx = self::$tabulatorInx++;
        $out = '';
        foreach ($block['content'] as $l) {
            $parts = preg_split('/[\s\t]* ([.\d]{1,3}\w{1,2})? >> [\s\t]/x', $l);
            $line = '';
            $addedWidths = 0; // px
            $addedEmsWidths = 0; // em
            foreach ($parts as $n => $elem) {
                if ($w = $block['widths'][$n]??false) {
                    $style = " style='width:$w;'";
                    $addedWidths += MdPlusHelper::convertToPx($w);
                    $addedWidths += MdPlusHelper::convertToPx('1.2em');
                    if (preg_match('/^([\d.]+)em$/', $w, $m)) {
                        $addedEmsWidths += intval($m[1]);
                    } else {
                        // non-'em'-width detected -> can't use em-based width:
                        $addedEmsWidths = -999999;
                    }

                } elseif ($n === 0) {
                    $style = " style='width:6em;'";
                    $addedWidths += 16;
                    $addedEmsWidths += 1;

                } else {
                    if ($addedEmsWidths > 0) {
                        // all widths defined as 'em', so we can use that value:
                        $style = " style='max-width:calc(100% - {$addedEmsWidths}em);'";
                    } else {
                        // some widths defined as non-'em' -> use px-based estimate:
                        $style = " style='max-width:calc(100% - {$addedWidths}px);'";
                    }
                }
                $elem = self::compileParagraph($elem);
                $line .= "<span class='c".($n+1)."'$style>$elem</span>";
            }
            $out .= "<div class='mdp-tabulator-wrapper mdp-tabulator-wrapper-$inx'>$line</div>\n";
        }
        return $out;
    } // renderTabulator



    // === DefinitionList ==================
    /**
     * @param string $line
     * @param array $lines
     * @param int $current
     * @return bool
     */
    protected function identifyDefinitionList(string $line, array $lines, int $current): bool
    {
        // if next line starts with ': ', it's a dl:
        if (isset($lines[$current+1]) && strncmp($lines[$current+1]??'', ': ', 2) === 0) {
            return true;
        } elseif (preg_match('/^\{:.*?}$/', $line) && strncmp($lines[$current+2]??'', ': ', 2) === 0) {
            return true;
        }
        return false;
    } // identifyDefinitionList

    /**
     * @param array $lines
     * @param int $current
     * @return array
     */
    protected function consumeDefinitionList(array $lines, int $current): array
    {
        // create block array
        $block = [
            'definitionList',
            'content' => [],
            'attrs' => '',
        ];

        if (preg_match('/^\{:(.*?)}$/', $lines[$current], $m)) {
            $block['attrs'] = $m[1];
            $current++;
        }

        // consume all lines until 2 empty line
        $nEmptyLines = 0;
        $dt = -1;
        for($i = $current, $count = count($lines); $i < $count-1; $i++) {
            if (!$lines[$i]) {
                if ($nEmptyLines++ < 1) {
                    continue;
                }
                break;
            }
            if (($lines[$i][0] !== ':') && (($lines[$i+1][0]??' ') === ':')) {
                $dt++;
                $block['content'][$dt]['dt'] = $lines[$i++];
                $block['content'][$dt]['dd'] = ltrim(substr($lines[$i],1));
                while (($lines[$i+1][0]??' ') === ':') {
                    $i++;
                    $block['content'][$dt]['dd'] .= "\n".ltrim(substr($lines[$i],1));
                }
                $nEmptyLines = 0;
            } else {
                break;
            }
        }
        return [$block, $i];
    } // consumeDefinitionList

    /**
     * @param array $block
     * @return string
     */
    protected function renderDefinitionList(array $block): string
    {
        $attrsStr = '';
        if ($block['attrs']) {
            $attrs = MdPlusHelper::parseInlineBlockArguments($block['attrs']);
            $attrsStr = $attrs['htmlAttrs'];
        }
        $out = '';
        foreach ($block['content'] as $item) {
            $dt = self::compileParagraph($item['dt'], true);
            $out .= "\t<dt>$dt</dt>\n";

            $dd = self::compile($item['dd'], true);
            $out .= "\t<dd>$dd\t</dd>\n\n";
        }
        $out = $this->catchAndInjectTagAttributes($out);
        $out = <<<EOT

<dl$attrsStr>
$out</dl>


EOT;
        return $out;
    } // renderDefinitionList



    // === OrderedList ==================
    /**
     * @param string $line
     * @return bool
     */
    protected function identifyOrderedList(string $line): bool
    {
        if (preg_match('/^\d+ !? \. /x', $line)) {
            return true;
        }
        return false;
    } // identifyOrderedList

    /**
     * @param array $lines
     * @param int $current
     * @return array
     */
    protected function consumeOrderedList(array $lines, int $current): array
    {
        // create block array
        $block = [
            'orderedList',
            'content' => [],
            'start' => false,
        ];

        // consume all lines until 2 empty line
        for($i = $current, $count = count($lines); $i < $count; $i++) {
            $line = $lines[$i];
            if (!preg_match('/^\d+!?\./', $line)) {  // empty line
                    break;
            } elseif (preg_match('/^(\d+)!\.\s*(.*)/', $line, $m)) {
                $block['start'] = $m[1];
                $line = $m[2];
            } elseif (preg_match('/^(\d+)\.\s*(.*)/', $line, $m)) {
                $line = $m[2];
            }
            $block['content'][] = $line;
        }
        return [$block, $i];
    } // consumeOrderedList

    /**
     * @param array $block
     * @return string
     * @throws Exception
     */
    protected function renderOrderedList(array $block): string
    {
        $out = '';
        $start = '';
        if ($block['start'] !== false) {
            $start = " start='{$block['start']}'";
        }
        foreach ($block['content'] as $line) {
                $line = self::compile($line, true);
                $line = trim($line);
                $out .= "\t\t<li>$line</li>\n";
        }
        $out = "\t<ol$start>\n$out\t</ol>\n";
        return $out;
    } // renderOrderedList



    /**
     * @marker ~~
     */

    protected function parseStrike(string $markdown): array
    {
        // check whether the marker really represents a strikethrough (i.e. there is a closing ~~)
        if (preg_match('/^~~(.+?)~~/', $markdown, $matches)) {
            return [
                ['strike', $this->parseInline($matches[1])],
                strlen($matches[0])
            ];
        }
        return [['text', '~~'], 2];
    }

    /**
     * @param array $element
     * @return string
     */
    protected function renderStrike(array $element): string
    {
        return '<del>' . $this->renderAbsy($element[1]) . '</del>';
    }




    /**
     * @marker ~
     */

    protected function parseSubscript(string $markdown): array
    {
        if (preg_match('/^~(.{1,9}?)~/', $markdown, $matches)) {
            return [
                ['subscript', $this->parseInline($matches[1])],
                strlen($matches[0])
            ];
        }
        return [['text', '~'], 1];
    }

    /**
     * @param array $element
     * @return string
     */
    protected function renderSubscript(array $element): string
    {
        return '<sub>' . $this->renderAbsy($element[1]) . '</sub>';
    }



    /**
     * @marker ^^
     */
    protected function parseKbd(string $markdown): array
    {
        if (preg_match('/^\^\^(.{1,5}?)\^\^/', $markdown, $matches)) {
            return [
                ['kbd', $this->parseInline($matches[1])],
                strlen($matches[0])
            ];
        }
        return [['text', '^^'], 2];
    }

    /**
     * @param array $element
     * @return string
     */
    protected function renderKbd(array $element): string
    {
        return '<kbd>' . $this->renderAbsy($element[1]) . '</kbd>';
    }



    /**
     * @marker ^
     */
    protected function parseSuperscript(string $markdown): array
    {
        if (preg_match('/^\^(.{1,20}?)\^/', $markdown, $matches)) {
            return [
                ['superscript', $this->parseInline($matches[1])],
                strlen($matches[0])
            ];
        }
        return [['text', '^'], 1];
    }

    /**
     * @param array $element
     * @return string
     */
    protected function renderSuperscript(array $element): string
    {
        return '<sup>' . $this->renderAbsy($element[1]) . '</sup>';
    }



    /**
     * @marker ==
     */
    protected function parseMarked(string $markdown): array
    {
        // check whether the marker really represents a strikethrough (i.e. there is a closing ==)
        if (preg_match('/^==(.+?)==/', $markdown, $matches)) {
            return [
                ['marked', $this->parseInline($matches[1])],
                strlen($matches[0])
            ];
        }
        return [['text', '=='], 2];
    }

    /**
     * @param array $element
     * @return string
     */
    protected function renderMarked(array $element): string
    {
        return '<mark>' . $this->renderAbsy($element[1]) . '</mark>';
    }



    /**
     * @marker ++
     */
    protected function parseInserted(string $markdown): array
    {
        if (preg_match('/^\+\+(.+?)\+\+/', $markdown, $matches)) {
            return [
                ['inserted', $this->parseInline($matches[1])],
                strlen($matches[0])
            ];
        }
        return [['text', '++'], 2];
    }

    /**
     * @param array $element
     * @return string
     */
    protected function renderInserted(array $element): string
    {
        return '<ins>' . $this->renderAbsy($element[1]) . '</ins>';
    }



    /**
     * @marker __
     */
    protected function parseUnderlined(string $markdown): array
    {
        if (preg_match('/^__(.+?)__/', $markdown, $matches)) {
            return [
                ['underlined', $this->parseInline($matches[1])],
                strlen($matches[0])
            ];
        }
        return [['text', '__'], 2];
    }

    /**
     * @param array $element
     * @return string
     */
    protected function renderUnderlined(array $element): string
    {
        return '<u>' . $this->renderAbsy($element[1]) . '</u>';
    }



    /**
     * @marker ``
     */
    protected function parseDoubleBacktick(string $markdown): array
    {
        // check whether the marker really represents a strikethrough (i.e. there is a closing `)
        if (preg_match('/^``(?!`)(.+?)``/', $markdown, $matches)) {
            return [
                ['doublebacktick', $this->parseInline($matches[1])],
                strlen($matches[0])
            ];
        }
        return [['text', '``'], 2];
    }

    /**
     * @param array $element
     * @return string
     */
    protected function renderDoubleBacktick(array $element): string
    {
        return "<samp>" . $this->renderAbsy($element[1]) .  "</samp>";
    }




    /**
     * @marker ![
     *
     * ![alt text](img.jpg "Caption...")
     */
    protected function parseImage($markdown)
    {
        if (preg_match('/^!\[ (.+?) ]\( ( (.+?) (["\']) (.+?) \4) \s* \)/x', $markdown, $matches)) {
            return [
                ['image', $matches[1].']('.$matches[2]],
                strlen($matches[0])
            ];
        } elseif (preg_match('/^!\[ (.+?) ]\( (.+?) \)/x', $markdown, $matches)) {
            return [
                ['image', $matches[1].']('.$matches[2]],
                strlen($matches[0])
            ];
        }
        return [['text', '!['], 2];
    }

    /**
     * @param $element
     * @return mixed|string
     * @throws InvalidArgumentException
     */
    protected function renderImage($element)
    {
        self::$imageInx++;
        $str = $element[1];
        list($alt, $src) = explode('](', $str);
        if (preg_match('/^ (["\']) (.+) \1 \s* /x', $alt, $m)) {
            $alt = $m[2];
        }
        if (preg_match('/^ (["\']) (.+) \1 \s* /x', $src, $m)) {
            $src = $m[2];
        }
        $alt = str_replace(['"', "'"], ['&quot;','&apos;'], $alt);

        $caption = '';
        if (preg_match('/^ (.*?) \s+ (.*) /x', $src, $m)) {
            $src = $m[1];
            $caption = $m[2];
            if (preg_match('/^ (["\']) (.+) \1 \s* /x', $src, $mm)) {
                $src = $mm[2];
            }
            if (preg_match('/^ (["\']) (.+) \1 \s* /x', $caption, $mm)) {
                $caption = $mm[2];
            }
            $caption = str_replace(['"', "'"], ['&quot;','&apos;'], $caption);
        }

        if (!str_contains($src, '/')) {
            $src = page()->file($src)->url();
        }

        $attr = "src:'$src', alt:'$alt', caption:'$caption'";
        if (function_exists('Usility\\MarkdownPlus\\img')) {
            $str = $this->processByMacro('img', $attr);
        } else {
            $str = "<img src='$src' alt='$alt'>";
        }
        return $str;
    } // renderImage



    /**
     * @marker [
     *
     * [link text](https://www.google.com)
     */
    protected function parseLink($markdown)
    {
        if (preg_match('/^\[ ([^]]+) ]\(([^)]+) \)/x', $markdown, $matches)) {
            $linkText = $matches[1];
            $link = $matches[2];
            $title = '';
            // extract optional title:
            if (preg_match('/(.*?)\s+(.*)/', $link, $m)) {
                $link = $m[1];
                $title = trim($m[2], '"\'');
            }
            return [
                ['link', $link, $linkText, $title], strlen($matches[0])
            ];
        }
        return [['text', '['], 1];
    }

    /**
     * @param $element
     * @return mixed|string
     * @throws InvalidArgumentException
     */
    protected function renderLink($element)
    {
        $link = trim($element[1], '"\'');
        $linkText = $element[2];
        $title = preg_replace('/^ ([\'"]) (.*) \1 $/x', "$2", $element[3]);

        if (function_exists('Usility\\MarkdownPlus\\link')) {
            $attr = "url:'$link', ";
            $q = (!str_contains($linkText, "'")) ? "'" : '"';
            $attr .= "text:$q$linkText$q, ";
            $q = (!str_contains($title, "'")) ? "'" : '"';
            $attr .= "title:$q$title$q";
            $str = $this->processByMacro('link', $attr);
        } else {
            $title = $title ? " title='$title'" : '';
            $str = "<a href='$link'$title>$linkText</a>";
        }
        return $str;
    } // renderLink





    /**
     * @marker :
     */
    protected function parseIcon(string $markdown): array
    {
        if (preg_match('/^:(\w+):/', $markdown, $matches)) {
            if (MdPlusHelper::iconExists($matches[1])) {
                return [
                    ['icon', $matches[1]],
                    strlen($matches[0])
                ];
            }
        }
        return [['text', ':'], 1];
    }

    /**
     * @param array $element
     * @return string
     * @throws Exception
     */
    protected function renderIcon(array $element): string
    {
        $iconName = $element[1];
        return MdPlusHelper::renderIcon($iconName);
    } // renderIcon




    //=== Pre- and Post-Processing ===================================
    /**
     * Applies Pre-Processing: shielding chars, includes, handling variables, fixing HTML
     * @param string $str
     * @return string
     * @throws Exception
     */
    private function preprocess(string $str): string
    {
        $str = trim($str);
        $str = MdPlusHelper::removeCStyleComments($str);
        $str = MdPlusHelper::zapFileEND($str);

        $str = $this->handleFrontmatter($str);

        $str = $this->handleShieldedCharacters($str);

        $str = $this->handleIncludes($str);

        $str = $this->fixCebeBugs($str);

        $str = $this->handleLineBreaks($str);

        // {{...}} alone on line -> add NLs around it:
        return preg_replace('/(\n{{.*}}\n)/U', "\n$1\n", $str);
    } // preprocess


    /**
     * Applies Post-Processing: kirbyTags, SmartyPants, fixing HTML, attribute injection
     * @param string $str
     * @return string
     */
    private function postprocess(string $str, bool $omitPWrapperTag = false): string
    {
        // lines that contain but a variable or macro (e.g. "<p>{{ lorem( help ) }}</p>") -> remove enclosing P-tags:
        $str = preg_replace('|<p> ({{ .*? }}) </p>|xms', "$1", $str);

        // check for kirbytags, get them compiled:
        $str = $this->handleKirbyTags($str);


        // handle smartypants:
        if (kirby()->option('smartypants')) {
            $str = $this->smartypants($str);
        }

        // remove outer <p> tags if requested:
        if ($omitPWrapperTag) {
            $str = preg_replace('|^ (\s*) <p> ( .* ) </p> (\s*) |xms', "$1$2$3", $str);
        }

        $str = $this->catchAndInjectTagAttributes($str); // ... {: .cls}

        $str = MdPlusHelper::unshieldStr($str, true);

        // clean up shielded characters, e.g. '@#123;''@#123;' to '&#123;' :
        $str = preg_replace('/@#(\d+);/m', "&#$1;", $str);

        return $str;
    } // postprocess


    /**
     * Applies (PageFactory-)SmartyPants translation:
     * @param string $str
     * @return string
     */
    private function smartypants(string $str): string
    {
        $smartypants =    [
                '/(?<!-)-&gt;/ms'  => '&rarr;',
                '/(?<!=)=&gt;/ms'  => '&rArr;',
                '/(?<!!)&lt;-/ms'  => '&larr;',
                '/(?<!=)&lt;=/ms'  => '&lArr;',
                '/(?<!\.)\.\.\.(?!\.)/ms'  => '&hellip;',
                '/(?<!-|!)--(?!-|>)/ms'  => '&ndash;', // in particular: <!-- -->
                '/(?<!-)---(?!-)/ms'  => '&mdash;',
                '/(?<!&lt;)&lt;<(?!&lt;)/ms'  => '&#171;',      // <<
                '/(?<!&lt;)&lt;&lt;(?!&lt;)/ms'  => '&#171;',   // <<
                '/(?<!&gt;)>&gt;(?!&gt;)/ms'  => '&#187;',      // >>
                '/(?<!&gt;)&gt;&gt;(?!&gt;)/ms'  => '&#187;',   // >>
                '/\bEURO\b/ms'  => '&euro;',
                '/sS/ms'  => 'ß',
                '|1/4|ms'  => '&frac14;',
                '|1/2|ms'  => '&frac12;',
                '|3/4|ms'  => '&frac34;',
                '|0/00|ms'  => '&permil;',
                '/(?<!,),,(?!,)/ms'  => '„',
                "/(?<!')''(?!')/ms"  => '”',
                "/(?<!`)``(?!`)/ms"  => '“',
                "/(?<!~)~~(?!~)/ms"  => '≈',
                '/\bINFINITY\b/ms'  => '∞',
        ];
        return preg_replace(array_keys($smartypants), array_values($smartypants), $str);
    } // smartypants


    /**
     * Converts shielded characters (\c) to their unicode equivalent
     * @param string $str
     * @return string
     */
    private function handleShieldedCharacters(string $str): string
    {
        $p = 0;
        while ($p=strpos($str, '\\', $p)) {
            $ch = $str[$p+1];
            if ($ch === "\n") {
                $unicode = '<br>';
            } else {
                $o = ord($str[$p + 1]);
                $unicode = "@#$o;";
            }
            $str = substr($str, 0, $p) . $unicode . substr($str, $p+2);
            $p += 2;
        }
        return $str;
    } // handleShieldedCharacters


    /**
     * Executes embedded include instructions, e.g. (include: xy)
     * @param string $str
     * @return string
     * @throws InvalidArgumentException
     */
    private function handleIncludes(string $str): string
    {
        // pattern: "(include: -incl.md)"
        if (preg_match_all('/\(include: (.*?) \)/x', $str, $m)) {
            foreach ($m[1] as $i => $value) {
                $val = $this->handleInclude($value, ' ');
                $str = str_replace($m[0][$i], $val, $str);
            }
        }
        return $str;
    } // handleIncludes


    /**
     * Fixes irregular behavior of cebe/markdown compiler:
     * -> ul and ol not recognized if no empty line before pattern
     * @param string $str
     * @return string
     */
    private function fixCebeBugs(string $str): string
    {
        $lines = explode("\n", $str);
        foreach ($lines as $i => $line) {
            if (str_starts_with($line, '- ')) {
                if (($lines[$i-1]??false) && preg_match('/^[^\-\s].*/',$lines[$i-1])) {
                    $lines[$i-1] .= "\n";
                }
            } elseif (preg_match('/^\d+!?\./', $line, $m)) {
                if (($lines[$i-1]??false) && !preg_match('/^\d+!?\./', $lines[$i-1], $m)) {
                    $lines[$i-1] .= "\n";
                }
            }
        }
        return implode("\n", $lines);
    } // fixCebeBugs


    /**
     * Intercepts and applies attribute injection instructions of type "{: }"
     * @param string $str
     * @return string
     */
    private function catchAndInjectTagAttributes(string $str): string
    {
        if (!str_contains($str, '{:') || !preg_match('/(?<!\\\)<\w+/', $str)) {
            return $str;
        }

        // special case: string starts with attrib-def, i.e. no surrounding tags:
        if (preg_match('|^\s*{:(.*?)}\s*(.*)|', $str, $m)) {
            $attrs = MdPlusHelper::parseInlineBlockArguments($m[1]);
            $attrsStr = $attrs['htmlAttrs'];
            if ($this->paragraphContext) {
                $str = "<span$attrsStr>$m[2]</span>";
            } else {
                $str = "<div$attrsStr>$m[2]</div>";
            }
            return $str;
        }

        // run through HTML line by line:
        $lines = explode("\n", $str);
        $attrDescr = false;
        $nLines = sizeof($lines);
        for ($i=0; $i<$nLines; $i++) {
            $line = &$lines[$i];
            
            // case attribs found and not consumed yet -> apply to following tag:
            if ($attrDescr) {
                if (preg_match('|(<\w+)|', $line, $m)) {
                    $line = $this->applyAttributes($line, $attrDescr, $m[1]);
                }
                $attrDescr = false;
                continue;
            }

            // line with nothing but attr descriptor:
            if (preg_match('|^<p> \s* {:(.*?)} \s* </p> $|x', $line, $m)) {
                $attrDescr = $m[1];
                unset($lines[$i]);
                continue;
            }

            if (!preg_match('|(.*) {:(.*?)} (.*)|x', $line, $m)) {
                continue;
            }
            $attrDescr = $m[2];
            $line = $m[1].$m[3];
            if (preg_match('/(\s*)(<.*?>)/', $line, $mm)) {
                $line = $mm[1].$this->applyAttributes($line, $attrDescr, $mm[2]);
                $attrDescr = false;
            }

        }
        return implode("\n", $lines);
    } // catchAndInjectTagAttributes


    /**
     * Helper for catchAndInjectTagAttributes()
     * @param string $line
     * @param string $attribs
     * @param string $pattern
     * @return string
     */
    private function applyAttributes(string $line, string $attribs, string $tag): string
    {
        $attrs = MdPlusHelper::parseInlineBlockArguments($attribs);
        $line = substr(trim($line), strlen($tag));
        $line = ltrim($line, '>');
        $tag = rtrim($tag, '>');

        // handle id:
        if ($value = $attrs['id']??false) {
            if (preg_match("/\sid= ['\"] .*? ['\"]/x", $tag, $mm)) {
                $tag = str_replace($mm[0], '', $tag);
            }
            $tag .= " id='$value'";
        }

        // handle class:
        if ($value = $attrs['class']??false) {
            if (str_contains($tag, 'class=')) {
                $tag = preg_replace("/(class=['\"])/", "$1$value ", $tag);
            } else {
                $tag .= " class='$value'";
            }
        }

        // handle style:
        if ($value = $attrs['style']??false) {
            $value = rtrim($value, '; ').'; ';
            if (str_contains($tag, 'style=')) {
                $tag = preg_replace("/(style=['\"])/", "$1$value ", $tag);
            } else {
                $tag .= " style='$value'";
            }
        }

        // misc attrs:
        if ($attrs['attr']??false) {
            foreach ($attrs['attr'] as $k => $v) {
                if (!str_contains($tag,"$k='$v'")) {
                    $tag .= " $k='$v'";
                }
            }
        }

        return "$tag>$line";
    } // applyAttributes


    /**
     * Translates line-break code ' BR ' to HTML <br>
     * @param string $str
     * @return string
     */
    private function handleLineBreaks(string $str): string
    {
        return preg_replace("/(\\\\\n|\s(?<!\\\)BR\s)/m", "<br>\n", $str);
    } // handleLineBreaks


    /**
     * Executes include instructions
     * @param string $argsStr
     * @param string $delim
     * @return string
     * @throws InvalidArgumentException
     */
    private function handleInclude(string $argsStr, string $delim = ','): string
    {
        $args = MdPlusHelper::parseArgumentStr($argsStr, $delim);
        $file = $args[0]??false;
        if (!$file) {
            return '';
        }

        if (is_dir($file)) {
            $file = rtrim($file,'/').'/*';
        }

        $out = $this->includeFile($file, $args);

        if ($args['literal']??false) {
            $out = MdPlusHelper::shieldStr($out);
            $out = "<pre>$out</pre>";
        }
        if (!($args['mdCompile']??true)) {
            $out = MdPlusHelper::shieldStr($out);
        }
        return $out;
    } // handleInclude


    /**
     * Helper for handleInclude(): includes files of type md, txt and html
     * @param string $files
     * @param array $args
     * @return string
     * @throws InvalidArgumentException
     * @throws Exception
     */
    private function includeFile(string $files, array $args = []): string
    {
        $out = '';
        $mdCompileOverride = $args['mdCompile']??null;
        $files = trim($files);
        if (!str_contains($files, '/')) {
            $files = page()->root().'/'.$files;
        }
        if (is_file($files)) {
            $dir = [$files];
        } else {
            $exclude = $args['exclude']??null;
            $dir = MdPlusHelper::getDir($files, $exclude);
        }
        foreach ($dir as $i => $file) {
            if (!str_contains(',txt,md,html,', MdPlusHelper::fileExt($file))) {
                throw new Exception("Error: unsupported file type '$file'");
            }
            $str = MdPlusHelper::loadFile($file, 'cstyle');
            if (!file_exists($file)) {
                throw new Exception("Error: file '$file' not found for including.");
            }
            if ((($mdCompileOverride === null) && (MdPlusHelper::fileExt($file) === 'md')) || $mdCompileOverride) {
                $str = $this->compile($str);
                $str = MdPlusHelper::shieldStr($str);
            }
            if (!($args['literal']??false) && (sizeof($dir) > 1)) {
                $tag = $args['wrapperTag'] ?? 'section';
                $customClass = $args['class'] ?? '';
                $class = ' class="mdp-section-' . ($i + 1) . " $customClass\"";
                $str = <<<EOT


<$tag$class>
$str</$tag>


EOT;
            } else {
                if ($args['literal']??false) {
                    $str = htmlentities($str);
                } else {
                    $str = "\n\n$str\n\n";
                }
            }
            $out .= $str;
        }
        return $out;
    } // includeFile


    /**
     * Intercepts kirbytag patterns and sends them to Kirby.
     * Exceptions are 'link' and 'image', they are executed by corresponding macros
     * @param string $str
     * @return string
     */
    private function handleKirbyTags(string $str): string
    {
        if (preg_match_all('/( \( (date|email|file|gist|image|link|tel|twitter|video) : .*? \) )/xms', $str, $m)) {
            foreach ($m[1] as $i => $value) {
                // check whether it's part of a macro call, skip if so:
                $pat = '\{\{\s*[\w-]+'.str_replace('|', '\\|', preg_quote($value));
                if (preg_match("|$pat|", $str)) {
                    continue;
                }

                $value = strip_tags(str_replace("\n", ' ', $value));
                $res = false;

                // intercept '(link:' and process by link() macro:
                if (preg_match('/^ \(link: \s* ["\']? ([^\s"\']+) ["\']? (.*) \) /x', $value, $mm)) {
                    $args = "url:'$mm[1]' $mm[2]";
                    $res = $this->processByMacro('link', $args);

                // intercept '(image:' and process by img() macro:
                } elseif (preg_match('/^ \(image: \s* ["\']? ([^\s"\']+) ["\']? (.*) \) /x', $value, $mm)) {
                    $args = "src:'$mm[1]' $mm[2]";
                    $res = $this->processByMacro('img', $args);

                }
                if ($res === false) {
                    // (file: ~/assets/test.pdf text: Download File)
                    $res = kirby()->kirbytags($value);
                }
                $str = str_replace($m[0][$i], $res, $str);
            }
        }
        return $str;
    } // handleKirbyTags


    /**
     * Helper for handleKirbyTags(): calls "macro-" functions
     * @param string $macroName
     * @param string $argStr
     * @return mixed
     */
    private function processByMacro(string $macroName, string $argStr): mixed
    {
        // insert commas between arguments:
        if (!str_contains($argStr, ',')) {
            $argStr = preg_replace('/(\s\w+:)/', ",$1", $argStr);
        }
        $macroName = "Usility\\PageFactory\\$macroName";
        if (function_exists($macroName)) {
            $str = $macroName($argStr);
        } else {
            $str = false;
        }
        return $str;
    } // processByMacro


    /**
     * Extracts fields (or frontmatter elements): css, js, assets etc assigns them to page variables.
     * @param string $str
     * @return string
     */
    private function handleFrontmatter(string $str): string
    {
        while (preg_match('/^ \s* (\w+) : (.*?) \s* \n----\n (.*)/xms', $str, $m)) {
            $key = $m[1];
            $value = $m[2];
            if (str_contains($key, 'css')) {
                $value = $this->handleSectionRefs($value);
            }
            page()->$key()->value .= $value;
            $str = $m[3];
        }
        return $str;
    } // handleFrontmatter


    /**
     * Helper for handleFrontmatter(): looks out for '#this' and '.this' and replaces this with 'pfy-section-N'
     * @param string $value
     * @return string
     */
    private function handleSectionRefs(string $value): string
    {
        if ($this->sectionIdentifier) {
            $value = str_replace(['#this','.this'],
                ["#$this->sectionIdentifier", ".$this->sectionIdentifier"], $value);
        }
        return $value;
    } // handleSectionRefs
} // MarkdownPlus
