<?php
namespace HTML5\Serializer;

use \HTML5\Elements;

/**
 * Traverser for walking a DOM tree.
 *
 * This is a concrete traverser designed to convert a DOM tree into an 
 * HTML5 document. It is not intended to be a generic DOMTreeWalker 
 * implementation.
 *
 * @see http://www.w3.org/TR/2012/CR-html5-20121217/syntax.html#serializing-html-fragments
 */
class Traverser {

  /** Namespaces that should be treated as "local" to HTML5. */
  static $local_ns = array(
    'http://www.w3.org/1999/xhtml' => 'html',
    'http://www.w3.org/1998/Math/MathML' => 'mathml',
    'http://www.w3.org/2000/svg' => 'svg',
  );

  protected $dom;
  protected $out;
  protected $pretty = TRUE;

  const DOCTYPE = '<!DOCTYPE html>';

  /**
   * Create a traverser.
   *
   * @param DOMNode|DOMNodeList $dom
   *   The document or node to traverse.
   * @param resource $out
   *   A stream that allows writing. The traverser will output into this 
   *   stream.
   */
  public function __construct($dom, $out) {
    $this->dom = $dom;
    $this->out = $out;
  }

  /**
   * Determine whether output should be formatted.
   *
   * IMPORTANT: Neither option will GUARANTEE that the spacing of the output
   * will exactly match the spacing of an origin document. The HTML5 specification
   * does not require any such behavior.
   *
   * Semantically (according to the HTML5 spec's definition), either flag
   * will produce an identical document. (Insignificant 
   * whitespace does not impact semantics).
   *
   * @param boolean $useFormatting
   *   If TRUE (default) output will be formatted. If FALSE,
   *   the little or no formatting is done.
   */
  public function formatOutput($useFormatting = TRUE) {
    $this->pretty = $useFormatting;
  }

  /**
   * Tell the traverser to walk the DOM.
   *
   * @return resource $out
   *   Returns the output stream.
   */
  public function walk() {
    // If DOMDocument, start with the DOCTYPE and travers.
    if ($this->dom instanceof \DOMDocument) {
      $this->doctype();
      $this->document($this->dom);
    }
    // If NodeList, loop
    elseif ($this->dom instanceof \DOMNodeList) {
      // Loop through the list
    }
    // Else assume this is a DOMNode-like datastructure.
    else {
      $this->node($this->dom);
    }

    return $this->out;
  }

  protected function doctype() {
    $this->wr(self::DOCTYPE);
    $this->nl();
  }

  protected function document($node) {
    $this->node($node->documentElement);
    $this->nl();
  }

  /**
   * Process a node in the DOM.
   *
   * @param mixed $node
   *   A node implementing \DOMNode.
   */
  protected function node($node) {
    // A listing of types is at http://php.net/manual/en/dom.constants.php
    switch ($node->nodeType) {
      case XML_ELEMENT_NODE:
        $this->element($node);
        break;
      case XML_TEXT_NODE:
        $this->text($node);
        break;
      case XML_CDATA_SECTION_NODE:
        $this->cdata($node);
        break;
      // FIXME: It appears that the parser doesn't do PI's.
      case XML_PI_NODE:
        $this->processorInstruction($ele);
        break;
      case XML_COMMENT_NODE:
        $this->comment($node);
        break;
      // Currently we don't support embedding DTDs.
      default:
        print '<!-- Skipped -->';
        break;
    }
  }

  protected function element($ele) {
    $name = $ele->tagName;
    $block = $this->pretty && Elements::isA($name, Elements::BLOCK_TAG);

    // Per spec:
    // If the element has a declared namespace in the HTML, MathML or
    // SVG namespaces, we use the lname instead of the tagName.
    if ($this->isLocalElement($ele)) {
      $name = $ele->localName;
    }

    // TODO: Really need to fix the spacing.
    // Add a newline for a block element.
    if ($block) $this->nl();

    $this->openTag($ele);

    // Handle children.
    if ($ele->hasChildNodes()) {
      $this->children($ele->childNodes);
    }

    // If not unary, add a closing tag.
    if (Elements::isA($name, Elements::VOID_TAG)) {
      $this->closeTag($ele);
      if ($block) $this->nl();
    }
  }

  /**
   * Write a text node.
   *
   * @param \DOMText $ele 
   *   The text node to write.
   */
  protected function text($ele) {
    if (isset($ele->parentNode) && (Elements::isA($ele->parentNode->tagName, Elements::TEXT_RAW) || Elements::isA($ele->parentNode->tagName, Elements::TEXT_RCDATA))) {
      $this->wr($ele->wholeText);
      return;
    }

    // FIXME: This probably needs some flags set.
    $this->wr($this->enc($ele->wholeText));

  }

  protected function cdata($ele) {
    $this->wr('<![CDATA[')->wr($ele->wholeText)->wr(']]>');
  }

  protected function comment($ele) {
    $this->wr('<!--')->wr($ele->data)->wr('-->');
  }

  protected function processorInstruction($ele) {
    $this->wr('<?')->wr($ele->target)->wr(' ')->wr($ele->data)->wr(' ?>');
  }

  protected function children($nl) {
    foreach ($nl as $node) {
      $this->node($node);
    }
  }

  /**
   * Write the opening tag.
   *
   * Tags for HTML, MathML, and SVG are in the local name. Otherwise, use the
   * qualified name (8.3).
   * 
   * @param \DOMNode $ele
   *   The element being written.
   */
  protected function openTag($ele) {
    // FIXME: Needs support for SVG, MathML, and namespaced XML.
    $this->wr('<')->wr($ele->tagName);
    $this->attrs($ele);
    $this->wr('>');
  }

  protected function attrs($ele) {
    // FIXME: Needs support for xml, xmlns, xlink, and namespaced elements.
    if (!$ele->hasAttributes()) {
      return $this;
    }

    // TODO: Currently, this always writes name="value", and does not do
    // value-less attributes.
    $map = $ele->attributes;
    $len = $map->length;
    for ($i = 0; $i < $len; ++$i) {
      $node = $map->item($i);
      $val = $this->enc($node->value);

      // XXX: The spec says that we need to ensure that anything in
      // the XML, XMLNS, or XLink NS's should use the canonical
      // prefix. It seems that DOM does this for us already, but there
      // may be exceptions.
      $this->wr(' ')->wr($node->name)->wr('="')->wr($val)->wr('"');
    }
  }

  /**
   * Write the closing tag.
   * 
   * Tags for HTML, MathML, and SVG are in the local name. Otherwise, use the
   * qualified name (8.3).
   *
   * @param \DOMNode $ele
   *   The element being written.
   */
  protected function closeTag($ele) {
    // FIXME: Needs support for SVG, MathML, and namespaced XML.
    $this->wr('</')->wr($ele->tagName)->wr('>');
  }

  /**
   * Write to the output.
   *
   * @param string $text
   *   The string to put into the output.
   *
   * @return HTML5\Serializer\Traverser
   *   $this so it can be used in chaining.
   */
  protected function wr($text) {
    fwrite($this->out, $text);
    return $this;
  }

  /**
   * Write a new line character.
   *
   * @return HTML5\Serializer\Traverser
   *   $this so it can be used in chaining.
   */
  protected function nl() {
    fwrite($this->out, PHP_EOL);
    return $this;
  }

  /**
   * Encode text.
   *
   * Note, PHP 5.4+ has better html5 encoding.
   *
   * @todo Use the Entities class in php 5.3 to have html5 entities.
   *
   * @param string $text
   *   text to encode.
   *
   * @return string
   *   The encoded text.
   */
  protected function enc($text) {
    $flags = ENT_QUOTES;

    if (defined('ENT_HTML5')) {
      $flags = ENT_HTML5 | ENT_SUBSTITUTE | ENT_QUOTES;
    }
    $ret = htmlentities($text, $flags, 'UTF-8', FALSE);
    //if ($ret != $text) printf("Replaced [%s] with [%s]", $text, $ret);
    return $ret;
  }

  /**
   * Is an element local?
   *
   * @param mixed $ele
   *   An element that implement \DOMNode.
   *
   * @return bool
   *   True if local and false otherwise.
   */
  protected function isLocalElement($ele) {
    $uri = $ele->namespaceURI;
    if (empty($uri)) {
      return FALSE;
    }
    return isset(self::$local_ns[$uri]);
  }
}
