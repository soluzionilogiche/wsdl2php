<?php
// +------------------------------------------------------------------------+
// | wsdl2php                                                               |
// +------------------------------------------------------------------------+
// | Copyright (C) 2005 Knut Urdalen <knut.urdalen@gmail.com>               |
// +------------------------------------------------------------------------+
// | THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS    |
// | "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT      |
// | LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR  |
// | A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT   |
// | OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,  |
// | SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT       | 
// | LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,  |
// | DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY  |
// | THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT    |
// | (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE  |
// | OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.   |
// +------------------------------------------------------------------------+
// | This software is licensed under the LGPL license. For more information |
// | see http://wsdl2php.sf.net                                             |
// +------------------------------------------------------------------------+

ini_set('soap.wsdl_cache_enabled', 0); // disable WSDL cache

if( $_SERVER['argc'] < 2 ) {
    die("usage: wsdl2php <wsdl-file> <namespace (optional)>\n");
}

$wsdl = $_SERVER['argv'][1];
if(isset($_SERVER['argv'][2])) {
    $namespace = $_SERVER['argv'][2];
}
else $namespace = '';

print "Analyzing WSDL";

try {
    $client = new SoapClient($wsdl);
} catch(SoapFault $e) {
    die($e);
}
print ".";
$dom = DOMDocument::load($wsdl);
print ".";

// get documentation
$nodes = $dom->getElementsByTagName('documentation');
$doc = array('service' => '',
    'operations' => array());
foreach($nodes as $node) {
    if( $node->parentNode->localName == 'service' ) {
        $doc['service'] = trim($node->parentNode->nodeValue);
    } else if( $node->parentNode->localName == 'operation' ) {
        $operation = $node->parentNode->getAttribute('name');
        //$parameterOrder = $node->parentNode->getAttribute('parameterOrder');
        $doc['operations'][$operation] = trim($node->nodeValue);
    }
}
print ".";

// get targetNamespace
$targetNamespace = '';
$nodes = $dom->getElementsByTagName('definitions');
foreach($nodes as $node) {
    $targetNamespace = $node->getAttribute('targetNamespace');
}
print ".";

// declare service
$service = array('class' => $dom->getElementsByTagNameNS('*', 'service')->item(0)->getAttribute('name'),
    'wsdl' => $wsdl,
    'doc' => $doc['service'],
    'functions' => array());
print ".";

// PHP keywords - can not be used as constants, class names or function names!
$reserved_keywords = array('and', 'or', 'xor', 'as', 'break', 'case', 'cfunction', 'class', 'continue', 'declare', 'const', 'default', 'do', 'else', 'elseif', 'enddeclare', 'endfor', 'endforeach', 'endif', 'endswitch', 'endwhile', 'eval', 'extends', 'for', 'foreach', 'function', 'global', 'if', 'new', 'old_function', 'static', 'switch', 'use', 'var', 'while', 'array', 'die', 'echo', 'empty', 'exit', 'include', 'include_once', 'isset', 'list', 'print', 'require', 'require_once', 'return', 'unset', '__file__', '__line__', '__function__', '__class__', 'abstract', 'private', 'public', 'protected', 'throw', 'try');

// ensure legal class name (I don't think using . and whitespaces is allowed in terms of the SOAP standard, should check this out and may throw and exception instead...)
$service['class'] = str_replace(' ', '_', $service['class']);
$service['class'] = str_replace('.', '_', $service['class']);
$service['class'] = str_replace('-', '_', $service['class']);

if(in_array(strtolower($service['class']), $reserved_keywords)) {
    $service['class'] .= 'Service';
}

// verify that the name of the service is named as a defined class
if(class_exists($service['class'])) {
    throw new Exception("Class '".$service['class']."' already exists");
}

/*if(function_exists($service['class'])) {
  throw new Exception("Class '".$service['class']."' can't be used, a function with that name already exists");
}*/

// get operations
$operations = $client->__getFunctions();
foreach($operations as $operation) {

  /*
   This is broken, need to handle
   GetAllByBGName_Response_t GetAllByBGName(string $Name)
   list(int $pcode, string $city, string $area, string $adm_center) GetByBGName(string $Name)

   finding the last '(' should be ok
   */
    //list($call, $params) = explode('(', $operation); // broken

    //if($call == 'list') { // a list is returned
    //}

  /*$call = array();
  preg_match('/^(list\(.*\)) (.*)\((.*)\)$/', $operation, $call);
  if(sizeof($call) == 3) { // found list()

  } else {
    preg_match('/^(.*) (.*)\((.*)\)$/', $operation, $call);
    if(sizeof($call) == 3) {

    }
  }*/

    $matches = array();
    if(preg_match('/^(\w[\w\d_]*) (\w[\w\d_]*)\(([\w\$\d,_ ]*)\)$/', $operation, $matches)) {
        $returns = $matches[1];
        $call = $matches[2];
        $params = $matches[3];
    } else if(preg_match('/^(list\([\w\$\d,_ ]*\)) (\w[\w\d_]*)\(([\w\$\d,_ ]*)\)$/', $operation, $matches)) {
        $returns = $matches[1];
        $call = $matches[2];
        $params = $matches[3];
    } else { // invalid function call
        throw new Exception('Invalid function call: '.$function);
    }

    $params = explode(', ', $params);

    $paramsArr = array();
    foreach($params as $param) {
        $paramsArr[] = explode(' ', $param);
    }
    //  $call = explode(' ', $call);
    $function = array('name' => $call,
        'method' => $call,
        'return' => $returns,
        'doc' => isset($doc['operations'][$call])?$doc['operations'][$call]:'',
        'params' => $paramsArr);

    // ensure legal function name
    if(in_array(strtolower($function['method']), $reserved_keywords)) {
        $function['name'] = '_'.$function['method'];
    }

    // ensure that the method we are adding has not the same name as the constructor
    if(strtolower($service['class']) == strtolower($function['method'])) {
        $function['name'] = '_'.$function['method'];
    }

    // ensure that there's no method that already exists with this name
    // this is most likely a Soap vs HttpGet vs HttpPost problem in WSDL
    // I assume for now that Soap is the one listed first and just skip the rest
    // this should be improved by actually verifying that it's a Soap operation that's in the WSDL file
    // QUICK FIX: just skip function if it already exists
    $add = true;
    foreach($service['functions'] as $func) {
        if($func['name'] == $function['name']) {
            $add = false;
        }
    }
    if($add) {
        $service['functions'][] = $function;
    }
    print ".";
}

$types = $client->__getTypes();

$primitive_types = array('string', 'int', 'long', 'float', 'boolean', 'date', 'dateTime', 'double', 'short', 'UNKNOWN', 'base64Binary', 'decimal', 'ArrayOfInt', 'ArrayOfFloat', 'ArrayOfString', 'decimal', 'hexBinary'); // TODO: dateTime is special, maybe use PEAR::Date or similar
$service['types'] = array();
foreach($types as $type) {
    $parts = explode("\n", $type);
    $class = explode(" ", $parts[0]);
    $class = $class[1];

    if( substr($class, -2, 2) == '[]' ) { // array skipping
        continue;
    }

    if( substr($class, 0, 7) == 'ArrayOf' ) { // skip 'ArrayOf*' types (from MS.NET, Axis etc.)
        continue;
    }


    $members = array();
    for($i=1; $i<count($parts)-1; $i++) {
        $parts[$i] = trim($parts[$i]);
        list($type, $member) = explode(" ", substr($parts[$i], 0, strlen($parts[$i])-1) );

        // check syntax
        if(preg_match('/^$\w[\w\d_]*$/', $member)) {
            throw new Exception('illegal syntax for member variable: '.$member);
            continue;
        }

        // IMPORTANT: Need to filter out namespace on member if presented
        if(strpos($member, ':')) { // keep the last part
            list($tmp, $member) = explode(':', $member);
        }

        // OBS: Skip member if already presented (this shouldn't happen, but I've actually seen it in a WSDL-file)
        // "It's better to be safe than sorry" (ref Morten Harket) 
        $add = true;
        foreach($members as $mem) {
            if($mem['member'] == $member) {
                $add = false;
            }
        }
        if($add) {
            $members[] = array('member' => $member, 'type' => $type);
        }
    }

    // gather enumeration values
    $values = array();
    if(count($members) == 0) {
        $values = checkForEnum($dom, $class);
    }

    $service['types'][] = array('baseClass'=> $class, 'class' => $namespace.$class, 'members' => $members, 'values' => $values);
    print ".";
}
print "done\n";

print "Generating code...";
$code = "";

// add types
$file = null;
foreach($service['types'] as $type) {

    if($namespace) {
        $dirname = str_replace('_', '/', $namespace);
        if(!is_dir($dirname))
            mkdir($dirname, 0777, true);
        $file = fopen($dirname . $type['baseClass']. '.php', 'w');
    }

    // add enumeration values
    $code .= "class ".$type['class']." {\n";
    foreach($type['values'] as $value) {
        $code .= "  const ".generatePHPSymbol($value)." = '$value';\n";
    }

    // add member variables
    foreach($type['members'] as $member) {
        $code .= "    /**\n";
        if(!in_array($member['type'], $primitive_types))
            $code .= "     * @var " . $namespace . $member['type'] . "\n";
        else
            $code .= "     * @var " . $member['type'] . "\n";
        $code .= "     */\n";
        $code .= "    public \$".$member['member'] . ";\n";
    }
    $code .= "}\n\n";
    if($file) 
    {
        print "Writing " . $type['baseClass']. ".php...";
        fwrite($file, "<?php\n\n".$code."\n");
        fclose($file);
        $code = "";
        print "ok\n";
    }

}

$code .= "\n";

// class level docblock
$code .= "/**\n";
$code .= " * ".$service['class']." class\n";
$code .= " * \n";
$code .= parse_doc(" * ", $service['doc']);
$code .= " * \n";
$code .= " * @author    {author}\n";
$code .= " * @copyright {copyright}\n";
$code .= " * @package   {package}\n";
$code .= " */\n";
$code .= "class ".$service['class']." extends SoapClient {\n\n";

// add classmap
$code .= "    private static \$classmap = array(\n";
foreach($service['types'] as $type) {
    $code .= "        '".$type['baseClass']."' => '".$type['class']."',\n";
}
$code .= "    );\n\n";
$code .= "    public function __construct(\$wsdl = \"".$service['wsdl']."\", \$options = array()) {\n";

// initialize classmap (merge)
$code .= "        foreach(self::\$classmap as \$key => \$value) {\n";
$code .= "            if(!isset(\$options['classmap'][\$key])) {\n";
$code .= "                \$options['classmap'][\$key] = \$value;\n";
$code .= "            }\n";
$code .= "        }\n";
$code .= "        parent::__construct(\$wsdl, \$options);\n";
$code .= "    }\n\n";

foreach($service['functions'] as $function) {
    $code .= "    /**\n";
    if ($function['doc']) {
        $code .= parse_doc("     * ", $function['doc']);
        $code .= "     *\n";
    }

    $signature = array(); // used for function signature
    $para = array(); // just variable names
    if(count($function['params']) > 0 && $function['params'][0][0]) {
        echo $function['name'];
        var_export($function['params']);
        foreach($function['params'] as $param) {
            if (count($param) > 1) {
                $type = ' ' . $param[0];
                $name = $param[1];
            } else {
                $type = '';
                $name = $param[0];
            }
            $code .= "     * @param $name$type\n";
            $signature[] = $name;
            $para[] = $name;
        }
    }
    if(isTypeHint($function['return'], $primitive_types)) {
        $code .= "     * @return ".$namespace . $function['return']."\n";
    }
    else
        $code .= "     * @return ".$function['return']."\n";
    $code .= "     */\n";
    $code .= "    public function ".$function['name']."(".implode(', ', $signature).") {\n";
    $code .= "        return \$this->__soapCall(\n";
    $code .= "            '".$function['method']."',\n";
    $code .= "            array(";
    $params = array();
    if(count($signature) > 0) { // add arguments
        foreach($signature as $param) {
            if(strpos($param, ' ')) { // slice 
                $param = array_pop(explode(' ', $param));
            }
            $params[] = $param;
        }
        $code .= implode(", ", $params);
    }
    $code .= "),\n";
    $code .= "            array(\n";
    $code .= "                'uri' => '".$targetNamespace."',\n";
    $code .= "                'soapaction' => ''\n";
    $code .= "            )\n";
    $code .= "        );\n";
    $code .= "    }\n\n";
}
$code .= "}\n\n";
print "done\n";

print "Writing ".$service['class'].".php...";
$fp = fopen($service['class'].".php", 'w');
fwrite($fp, "<?php\n".$code."?>\n");
fclose($fp);
print "done\n";

function parse_doc($prefix, $doc) {
    $code = "";
    $words = split(' ', $doc);
    $line = $prefix;
    foreach($words as $word) {
        $line .= $word.' ';
        if( strlen($line) > 90 ) { // new line
            $code .= $line."\n";
            $line = $prefix;
        }
    }
    $code .= $line."\n";
    return $code;
}

/**
 * Look for enumeration
 * 
 * @param DOM $dom
 * @param string $class
 * @return array
 */
function checkForEnum(&$dom, $class) {
    $values = array();

    $node = findType($dom, $class);
    if(!$node) {
        return $values;
    }

    $value_list = $node->getElementsByTagName('enumeration');
    if($value_list->length == 0) {
        return $values;
    }

    for($i=0; $i<$value_list->length; $i++) {
        $values[] = $value_list->item($i)->attributes->getNamedItem('value')->nodeValue;
    }
    return $values;
}

/**
 * Look for a type
 * 
 * @param DOM $dom
 * @param string $class
 * @return DOMNode
 */
function findType(&$dom, $class) {
    $types_node  = $dom->getElementsByTagName('types')->item(0);
    $schema_list = $types_node->getElementsByTagName('schema');

    for ($i=0; $i<$schema_list->length; $i++) {
        $children = $schema_list->item($i)->childNodes;
        for ($j=0; $j<$children->length; $j++) {
            $node = $children->item($j);
            if ($node instanceof DOMElement &&
                $node->hasAttributes() &&
                $node->attributes->getNamedItem('name')->nodeValue == $class) {
                    return $node;
                }
        }
    }
    return null;
}

function generatePHPSymbol($s) {
    global $reserved_keywords;

    if(!preg_match('/^[A-Za-z_]/', $s)) {
        $s = 'value_'.$s;
    }
    if(in_array(strtolower($s), $reserved_keywords)) {
        $s = '_'.$s;
    }
    return preg_replace('/[-.\s]/', '_', $s);
}

function isTypeHint($typeHint, array $primitive_types) {
    return !in_array($typeHint, $primitive_types) && !(substr($typeHint, 0, 7) == 'ArrayOf');
}

?>
